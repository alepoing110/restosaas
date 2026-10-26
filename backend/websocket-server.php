<?php
/**
 * RestoCloud WebSocket Server
 * Run: php backend/websocket-server.php
 * Default port: 8080
 * 
 * Events broadcast:
 *   - order.created, order.updated, order.completed, order.cancelled
 *   - stock.changed
 *   - reservation.changed
 */

require_once __DIR__ . '/../db.php';

if (!function_exists('socket_create')) {
    fwrite(STDERR, "La extensión PHP 'sockets' es obligatoria para el servidor WebSocket. Habilítala en php.ini.\n");
    exit(1);
}

function ws_send_frame($client, string $payload, int $opcode = 0x1): void
{
    $length = strlen($payload);
    $header = chr(0x80 | ($opcode & 0x0f));
    if ($length < 126) {
        $header .= chr($length);
    } elseif ($length <= 0xffff) {
        $header .= chr(126) . pack('n', $length);
    } else {
        $header .= chr(127) . pack('NN', 0, $length);
    }
    @socket_write($client, $header . $payload);
}

function ws_send_json($client, array $payload): void
{
    ws_send_frame($client, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function ws_parse_frames(string &$buffer): array
{
    $messages = [];
    while (strlen($buffer) >= 2) {
        $first = ord($buffer[0]);
        $second = ord($buffer[1]);
        $opcode = $first & 0x0f;
        $masked = ($second & 0x80) !== 0;
        $length = $second & 0x7f;
        $offset = 2;

        if ($length === 126) {
            if (strlen($buffer) < 4) break;
            $length = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($buffer) < 10) break;
            $parts = unpack('Nhigh/Nlow', substr($buffer, 2, 8));
            if ($parts['high'] > 0 || $parts['low'] > 1048576) {
                throw new RuntimeException('WebSocket frame too large');
            }
            $length = $parts['low'];
            $offset = 10;
        }

        $mask = '';
        if ($masked) {
            if (strlen($buffer) < $offset + 4) break;
            $mask = substr($buffer, $offset, 4);
            $offset += 4;
        }
        if (strlen($buffer) < $offset + $length) break;

        $payload = substr($buffer, $offset, $length);
        $buffer = substr($buffer, $offset + $length);
        if ($masked) {
            for ($i = 0; $i < $length; $i++) {
                $payload[$i] = $payload[$i] ^ $mask[$i % 4];
            }
        }

        $messages[] = ['opcode' => $opcode, 'payload' => $payload];
    }
    return $messages;
}

$port = (int)($argv[1] ?? 8080);
$bindHost = rc_env('WS_BIND_HOST', '127.0.0.1');

echo "RestoCloud WebSocket Server starting on port $port...\n";

$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if (!$socket) {
    die("Failed to create socket: " . socket_strerror(socket_last_error()) . "\n");
}

socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);

if (!@socket_bind($socket, $bindHost, $port)) {
    die("Failed to bind to port $port: " . socket_strerror(socket_last_error($socket)) . "\n");
}

socket_listen($socket);
socket_set_nonblock($socket);

$clients = [];
$clientInfo = [];
$tenantClients = [];
$branchClients = [];

echo "Server listening on $bindHost:$port\n";
echo "Waiting for connections...\n";

$wsStorageDir = __DIR__ . '/../storage/ws';
if (!is_dir($wsStorageDir)) @mkdir($wsStorageDir, 0750, true);
$lastActivityFile = $wsStorageDir . '/restocloud_ws_activity.log';
$eventFile = $wsStorageDir . '/restocloud_ws_events.jsonl';
$lastEventCheck = 0;

while (true) {
    $read = [$socket];
    $write = null;
    $except = null;

    foreach ($clients as $client) {
        $read[] = $client;
    }

    $changed = $read;
    $numChanged = socket_select($changed, $write, $except, 0, 100000);

    if ($numChanged === false) {
        break;
    }

    if (in_array($socket, $changed)) {
        $newClient = @socket_accept($socket);
        if ($newClient) {
            socket_getpeername($newClient, $addr, $portNum);
            $clients[] = $newClient;
            $clientInfo[(int)$newClient] = [
                'addr' => $addr,
                'port' => $portNum,
                'tenant_id' => null,
                'branch_id' => null,
                'handshaked' => false,
                'buffer' => '',
                'connected_at' => time()
            ];
            echo "New connection from $addr:$portNum\n";
        }
        unset($changed[array_search($socket, $changed)]);
    }

    foreach ($changed as $client) {
        $data = @socket_read($client, 4096, PHP_BINARY_READ);
        if ($data === false || $data === '') {
            $index = array_search($client, $clients);
            if ($index !== false) {
                $info = $clientInfo[(int)$client] ?? [];
                echo "Disconnected: {$info['addr']}:{$info['port']}\n";
                unset($tenantClients[$info['tenant_id']][(int)$client]);
                if (isset($info['tenant_id'], $info['branch_id'], $branchClients[$info['tenant_id']][$info['branch_id']])) {
                    unset($branchClients[$info['tenant_id']][$info['branch_id']][(int)$client]);
                }
                unset($clientInfo[(int)$client]);
                socket_close($client);
                unset($clients[$index]);
            }
            continue;
        }

        $infoKey = (int)$client;
        $clientInfo[$infoKey]['buffer'] .= $data;
        if (!$clientInfo[$infoKey]['handshaked']) {
            $headerEnd = strpos($clientInfo[$infoKey]['buffer'], "\r\n\r\n");
            if ($headerEnd === false) continue;
            $httpHeader = substr($clientInfo[$infoKey]['buffer'], 0, $headerEnd);
            $clientInfo[$infoKey]['buffer'] = substr($clientInfo[$infoKey]['buffer'], $headerEnd + 4);
            if (!preg_match('/Sec-WebSocket-Key:\s*(.+)\r?$/mi', $httpHeader, $keyMatch)) {
                socket_close($client);
                continue;
            }
            $accept = base64_encode(pack('H*', sha1(trim($keyMatch[1]) . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
            $response = "HTTP/1.1 101 Switching Protocols\r\n"
                . "Upgrade: websocket\r\nConnection: Upgrade\r\n"
                . "Sec-WebSocket-Accept: $accept\r\n\r\n";
            @socket_write($client, $response);
            $clientInfo[$infoKey]['handshaked'] = true;
            ws_send_json($client, [
                'type' => 'connected',
                'message' => 'Connected to RestoCloud WebSocket',
                'timestamp' => date('c')
            ]);
        }

        foreach (ws_parse_frames($clientInfo[$infoKey]['buffer']) as $frame) {
            if ($frame['opcode'] === 0x8) {
                ws_send_frame($client, '', 0x8);
                continue 2;
            }
            if ($frame['opcode'] === 0x9) {
                ws_send_frame($client, $frame['payload'], 0xA);
                continue;
            }
            if ($frame['opcode'] !== 0x1) continue;

            $message = json_decode($frame['payload'], true);
            if (!$message) continue;

            $type = $message['type'] ?? '';

            if ($type === 'auth') {
                $tenantId = $message['tenant_id'] ?? '';
                $branchId = $message['branch_id'] ?? '';
                $sessionToken = $message['session_token'] ?? '';

                if (!$tenantId || !$branchId || !$sessionToken) {
                    $errMsg = json_encode(['type' => 'auth_error', 'message' => 'tenant_id, branch_id y session_token requeridos']);
                    ws_send_json($client, ['type' => 'auth_error', 'message' => 'tenant_id, branch_id y session_token requeridos']);
                    continue;
                }

                try {
                    $stmt = $pdo->prepare("SELECT u.id FROM `users` u JOIN `tenants` t ON t.id = u.tenant_id JOIN `branches` b ON b.id = u.branch_id JOIN `user_sessions` s ON s.user_id = u.id WHERE u.tenant_id = :tid AND u.branch_id = :bid AND s.id = :session_token AND s.ended_at IS NULL AND u.active = 1 AND t.active = 1 AND b.active = 1 LIMIT 1");
                    $stmt->execute(['tid' => $tenantId, 'bid' => $branchId, 'session_token' => $sessionToken]);
                    if (!$stmt->fetch()) {
                        $errMsg = json_encode(['type' => 'auth_error', 'message' => 'Sesión inválida o expirada']);
                        ws_send_json($client, ['type' => 'auth_error', 'message' => 'Sesión inválida o expirada']);
                        continue;
                    }
                } catch (Throwable $e) {
                    $errMsg = json_encode(['type' => 'auth_error', 'message' => 'Error de autenticación']);
                    ws_send_json($client, ['type' => 'auth_error', 'message' => 'Error de autenticación']);
                    continue;
                }

                $clientInfo[(int)$client]['tenant_id'] = $tenantId;
                $clientInfo[(int)$client]['branch_id'] = $branchId;

                if (!isset($tenantClients[$tenantId])) {
                    $tenantClients[$tenantId] = [];
                }
                $tenantClients[$tenantId][(int)$client] = $client;
                if (!isset($branchClients[$tenantId])) {
                    $branchClients[$tenantId] = [];
                }
                if (!isset($branchClients[$tenantId][$branchId])) {
                    $branchClients[$tenantId][$branchId] = [];
                }
                $branchClients[$tenantId][$branchId][(int)$client] = $client;

                ws_send_json($client, ['type' => 'auth_ok', 'tenant_id' => $tenantId]);
                echo "Client authenticated: tenant=$tenantId branch=$branchId\n";

            } elseif ($type === 'ping') {
                ws_send_json($client, ['type' => 'pong', 'timestamp' => date('c')]);

            }
        }
    }

    $now = time();
    if ($now - $lastEventCheck >= 1) {
        $lastEventCheck = $now;
        if (file_exists($eventFile)) {
            $events = file($eventFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($events) {
                @unlink($eventFile);
                foreach ($events as $eventLine) {
                    $event = json_decode($eventLine, true);
                    if (!$event || !isset($event['type'])) continue;

                    $tenantId = $event['tenant_id'] ?? '';
                    $branchId = $event['branch_id'] ?? '';
                    $broadcast = json_encode([
                        'type' => $event['type'],
                        'data' => $event['data'] ?? [],
                        'from_branch' => $branchId,
                        'timestamp' => $event['timestamp'] ?? date('c')
                    ]);

                    if ($branchId && isset($branchClients[$tenantId][$branchId])) {
                        foreach ($branchClients[$tenantId][$branchId] as $cId => $cClient) {
                            ws_send_frame($cClient, $broadcast);
                        }
                    } elseif (isset($tenantClients[$tenantId])) {
                        foreach ($tenantClients[$tenantId] as $cId => $cClient) {
                            ws_send_frame($cClient, $broadcast);
                        }
                    }
                }
            }
        }
    }
}

socket_close($socket);
