<?php

function whatsapp_parse_webhook_payload(array $payload): array
{
    return whatsapp_parse_webhook_messages($payload)[0] ?? [];
}

function whatsapp_parse_webhook_messages(array $payload): array
{
    $messages = [];
    foreach (($payload['entry'] ?? []) as $entry) {
        foreach (($entry['changes'] ?? []) as $change) {
            $value = $change['value'] ?? [];
            $contacts = [];
            foreach (($value['contacts'] ?? []) as $contact) {
                $waId = (string)($contact['wa_id'] ?? '');
                if ($waId !== '') {
                    $contacts[$waId] = $contact;
                }
            }
            foreach (($value['messages'] ?? []) as $message) {
                $contact = $contacts[(string)($message['from'] ?? '')] ?? ($value['contacts'][0] ?? []);
                $messages[] = [
                    'phone_number_id' => $value['metadata']['phone_number_id'] ?? '',
                    'display_phone_number' => $value['metadata']['display_phone_number'] ?? '',
                    'from' => $message['from'] ?? '',
                    'message_id' => $message['id'] ?? '',
                    'type' => $message['type'] ?? 'text',
                    'text' => trim((string)($message['text']['body'] ?? '')),
                    'customer_name' => trim((string)($contact['profile']['name'] ?? 'Cliente')),
                ];
            }
        }
    }

    return $messages;
}

function whatsapp_send_text(string $to, string $body): array
{
    $token = rc_env('WHATSAPP_TOKEN', '');
    $phoneId = rc_env('WHATSAPP_PHONE_ID', '');
    if ($token === '' || $phoneId === '') {
        throw new RuntimeException('WhatsApp Cloud API no está configurado.');
    }

    $url = 'https://graph.facebook.com/v25.0/' . rawurlencode($phoneId) . '/messages';
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'text',
        'text' => ['body' => $body],
    ];

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!function_exists('curl_init')) {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('La extensión cURL de PHP no está habilitada y allow_url_fopen está desactivado.');
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",
                'content' => $jsonPayload,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $response = @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        preg_match('/\s(\d{3})\s/', $statusLine, $statusMatch);
        $httpCode = (int)($statusMatch[1] ?? 0);
        $data = json_decode((string)$response, true);
        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('No se pudo enviar el mensaje por WhatsApp.');
        }
        return is_array($data) ? $data : [];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string)$response, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('No se pudo enviar el mensaje por WhatsApp.');
    }
    return is_array($data) ? $data : [];
}

function whatsapp_validate_signature(string $rawBody): bool
{
    $appSecret = rc_env('WHATSAPP_APP_SECRET', '');
    $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    if ($appSecret === '' || $signature === '' || strpos($signature, 'sha256=') !== 0) {
        return false;
    }
    $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);
    return hash_equals($expected, $signature);
}

function whatsapp_claim_message(PDO $pdo, string $messageId, array $businessContext): bool
{
    if ($messageId === '') {
        return false;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO `whatsapp_processed_messages` (`message_id`, `tenant_id`, `branch_id`, `status`) VALUES (:message_id, :tenant_id, :branch_id, 'processing')");
        $stmt->execute([
            'message_id' => $messageId,
            'tenant_id' => $businessContext['tenant_id'],
            'branch_id' => $businessContext['branch_id'],
        ]);
        return true;
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            $existing = $pdo->prepare("SELECT `status`, `created_at` FROM `whatsapp_processed_messages` WHERE `message_id` = :message_id LIMIT 1");
            $existing->execute(['message_id' => $messageId]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['status'] === 'processing' && strtotime((string)$row['created_at']) < time() - 600) {
                $retry = $pdo->prepare("UPDATE `whatsapp_processed_messages` SET `created_at` = NOW(), `reply` = NULL WHERE `message_id` = :message_id AND `status` = 'processing'");
                $retry->execute(['message_id' => $messageId]);
                return $retry->rowCount() === 1;
            }
            return false;
        }
        throw $e;
    }
}

function whatsapp_complete_message(PDO $pdo, string $messageId, string $reply): void
{
    $stmt = $pdo->prepare("UPDATE `whatsapp_processed_messages` SET `status` = 'completed', `reply` = :reply, `processed_at` = NOW() WHERE `message_id` = :message_id");
    $stmt->execute(['message_id' => $messageId, 'reply' => $reply]);
}

function whatsapp_get_processed_message(PDO $pdo, string $messageId): ?array
{
    $stmt = $pdo->prepare("SELECT `status`, `reply` FROM `whatsapp_processed_messages` WHERE `message_id` = :message_id LIMIT 1");
    $stmt->execute(['message_id' => $messageId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function whatsapp_enqueue_event(PDO $pdo, array $message, array $businessContext): bool
{
    if (trim((string)($message['message_id'] ?? '')) === '' || trim((string)($message['from'] ?? '')) === '') {
        return false;
    }

    $stmt = $pdo->prepare("INSERT IGNORE INTO `whatsapp_events` (`message_id`, `tenant_id`, `branch_id`, `phone_number_id`, `display_phone_number`, `sender_phone`, `customer_name`, `message_text`) VALUES (:message_id, :tenant_id, :branch_id, :phone_number_id, :display_phone_number, :sender_phone, :customer_name, :message_text)");
    $stmt->execute([
        'message_id' => $message['message_id'],
        'tenant_id' => $businessContext['tenant_id'],
        'branch_id' => $businessContext['branch_id'],
        'phone_number_id' => $message['phone_number_id'],
        'display_phone_number' => $message['display_phone_number'] ?: null,
        'sender_phone' => $message['from'],
        'customer_name' => $message['customer_name'] ?: 'Cliente',
        'message_text' => $message['text'],
    ]);
    return $stmt->rowCount() === 1;
}

function whatsapp_normalize_phone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?: '';
}

function whatsapp_resolve_business_context(PDO $pdo, string $phoneNumberId, string $displayPhoneNumber = ''): ?array
{
    if ($phoneNumberId !== '') {
        $stmt = $pdo->prepare("SELECT `tenant_id`, `branch_id` FROM `config_general` WHERE `id` = 'whatsapp_phone_id' AND `value` = :value LIMIT 1");
        $stmt->execute(['value' => $phoneNumberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }

    $normalized = whatsapp_normalize_phone($displayPhoneNumber);
    if ($normalized !== '') {
        $stmt = $pdo->prepare("SELECT `tenant_id`, `branch_id`, `value` FROM `config_general` WHERE `id` = 'whatsapp_number'");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (whatsapp_normalize_phone((string)($row['value'] ?? '')) === $normalized) {
                return [
                    'tenant_id' => $row['tenant_id'],
                    'branch_id' => $row['branch_id'],
                ];
            }
        }
    }

    return null;
}
