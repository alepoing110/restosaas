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

$port = (int)($argv[1] ?? 8080);

echo "RestoCloud WebSocket Server starting on port $port...\n";

$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if (!$socket) {
    die("Failed to create socket: " . socket_strerror(socket_last_error()) . "\n");
}

socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);

if (!@socket_bind($socket, '0.0.0.0', $port)) {
    die("Failed to bind to port $port: " . socket_strerror(socket_last_error($socket)) . "\n");
}

socket_listen($socket);
socket_set_nonblock($socket);

$clients = [];
$clientInfo = [];
$tenantClients = [];

echo "Server listening on 0.0.0.0:$port\n";
echo "Waiting for connections...\n";

$lastActivityFile = sys_get_temp_dir() . '/restocloud_ws_activity.log';
$eventFile = sys_get_temp_dir() . '/restocloud_ws_events.jsonl';
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
                'connected_at' => time()
            ];
            echo "New connection from $addr:$portNum\n";

            $welcome = json_encode([
                'type' => 'connected',
                'message' => 'Connected to RestoCloud WebSocket',
                'timestamp' => date('c')
            ]);
            @socket_write($newClient, $welcome . "\n");
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
                unset($clientInfo[(int)$client]);
                socket_close($client);
                unset($clients[$index]);
            }
            continue;
        }

        $lines = explode("\n", $data);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $message = json_decode($line, true);
            if (!$message) continue;

            $type = $message['type'] ?? '';

            if ($type === 'auth') {
                $tenantId = $message['tenant_id'] ?? '';
                $branchId = $message['branch_id'] ?? '';
                $token = $message['token'] ?? '';

                if (!$tenantId || !$branchId) {
                    $errMsg = json_encode(['type' => 'auth_error', 'message' => 'tenant_id y branch_id requeridos']);
                    @socket_write($client, $errMsg . "\n");
                    continue;
                }

                try {
                    $stmt = $pdo->prepare("SELECT u.id FROM `users` u JOIN `tenants` t ON t.id = u.tenant_id JOIN `branches` b ON b.id = u.branch_id WHERE u.tenant_id = :tid AND u.branch_id = :bid AND u.active = 1 AND t.active = 1 AND b.active = 1 LIMIT 1");
                    $stmt->execute(['tid' => $tenantId, 'bid' => $branchId]);
                    if (!$stmt->fetch()) {
                        $errMsg = json_encode(['type' => 'auth_error', 'message' => 'Tenant o branch inválido']);
                        @socket_write($client, $errMsg . "\n");
                        continue;
                    }
                } catch (Throwable $e) {
                    $errMsg = json_encode(['type' => 'auth_error', 'message' => 'Error de autenticación']);
                    @socket_write($client, $errMsg . "\n");
                    continue;
                }

                $clientInfo[(int)$client]['tenant_id'] = $tenantId;
                $clientInfo[(int)$client]['branch_id'] = $branchId;

                if (!isset($tenantClients[$tenantId])) {
                    $tenantClients[$tenantId] = [];
                }
                $tenantClients[$tenantId][(int)$client] = $client;

                $authOk = json_encode(['type' => 'auth_ok', 'tenant_id' => $tenantId]);
                @socket_write($client, $authOk . "\n");
                echo "Client authenticated: tenant=$tenantId branch=$branchId\n";

            } elseif ($type === 'ping') {
                $pong = json_encode(['type' => 'pong', 'timestamp' => date('c')]);
                @socket_write($client, $pong . "\n");

            } elseif (in_array($type, ['order.created', 'order.updated', 'order.completed', 'order.cancelled', 'order.appended', 'stock.changed', 'reservation.changed'])) {
                $tenantId = $clientInfo[(int)$client]['tenant_id'] ?? '';
                $branchId = $clientInfo[(int)$client]['branch_id'] ?? '';

                $broadcast = json_encode([
                    'type' => $type,
                    'data' => $message['data'] ?? [],
                    'from_branch' => $branchId,
                    'timestamp' => date('c')
                ]);

                if (isset($tenantClients[$tenantId])) {
                    foreach ($tenantClients[$tenantId] as $cId => $cClient) {
                        @socket_write($cClient, $broadcast . "\n");
                    }
                }

                file_put_contents($lastActivityFile, json_encode([
                    'type' => $type,
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                    'timestamp' => date('c')
                ]) . "\n", FILE_APPEND | LOCK_EX);
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
                    $broadcast = json_encode([
                        'type' => $event['type'],
                        'data' => $event['data'] ?? [],
                        'from_branch' => $event['branch_id'] ?? '',
                        'timestamp' => $event['timestamp'] ?? date('c')
                    ]);

                    if (isset($tenantClients[$tenantId])) {
                        foreach ($tenantClients[$tenantId] as $cId => $cClient) {
                            @socket_write($cClient, $broadcast . "\n");
                        }
                    }
                }
            }
        }
    }
}

socket_close($socket);
