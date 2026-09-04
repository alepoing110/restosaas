#!/usr/bin/env php
<?php
/**
 * Process WhatsApp events from the database queue.
 * Run from cron, for example every minute: php whatsapp-worker.php --limit=10
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/backend/api/helpers.php';
require_once __DIR__ . '/backend/llm.php';
require_once __DIR__ . '/backend/agent/conversation.php';
require_once __DIR__ . '/backend/agent/recommendations.php';
require_once __DIR__ . '/backend/agent/tools.php';
require_once __DIR__ . '/backend/agent/chatbot.php';
require_once __DIR__ . '/backend/agent/whatsapp.php';

$limit = 10;
foreach (($argv ?? []) as $argument) {
    if (str_starts_with($argument, '--limit=')) {
        $limit = max(1, min(100, (int)substr($argument, 8)));
    }
}

$stmt = $pdo->prepare("SELECT * FROM `whatsapp_events` WHERE `status` IN ('received', 'failed', 'ready') AND `next_attempt_at` <= NOW() ORDER BY `created_at` ASC LIMIT :limit");
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$events = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$processed = 0;

foreach ($events as $event) {
    $claim = $pdo->prepare("UPDATE `whatsapp_events` SET `status` = 'processing', `attempts` = `attempts` + 1, `updated_at` = NOW() WHERE `message_id` = :message_id AND `status` IN ('received', 'failed', 'ready')");
    $claim->execute(['message_id' => $event['message_id']]);
    if ($claim->rowCount() !== 1) {
        continue;
    }

    try {
        $attention = $pdo->prepare("SELECT `attention_mode` FROM `chatbot_conversations` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `wa_phone` = :phone AND `status` = 'active' ORDER BY `updated_at` DESC LIMIT 1");
        $attention->execute(['tenant_id' => $event['tenant_id'], 'branch_id' => $event['branch_id'], 'phone' => $event['sender_phone']]);
        if ($attention->fetchColumn() === 'humano') {
            $pdo->prepare("UPDATE `whatsapp_events` SET `status` = 'held', `last_error` = NULL, `updated_at` = NOW() WHERE `message_id` = :message_id")->execute(['message_id' => $event['message_id']]);
            continue;
        }
        $reply = trim((string)($event['reply'] ?? ''));
        if ($reply === '') {
            $context = ['tenant_id' => $event['tenant_id'], 'branch_id' => $event['branch_id']];
            $businessConfig = loadCatalogState($pdo, $context)['business'] ?? [];
            $result = chatbot_handle_message(
                $pdo,
                $context,
                $businessConfig,
                (string)$event['sender_phone'],
                (string)($event['customer_name'] ?: 'Cliente'),
                (string)$event['message_text']
            );
            $reply = (string)$result['reply'];
            $saveReply = $pdo->prepare("UPDATE `whatsapp_events` SET `status` = 'ready', `reply` = :reply, `last_error` = NULL, `updated_at` = NOW() WHERE `message_id` = :message_id AND `status` = 'processing'");
            $saveReply->execute(['message_id' => $event['message_id'], 'reply' => $reply]);
        }

        whatsapp_send_text((string)$event['sender_phone'], $reply);
        $complete = $pdo->prepare("UPDATE `whatsapp_events` SET `status` = 'sent', `last_error` = NULL, `updated_at` = NOW() WHERE `message_id` = :message_id");
        $complete->execute(['message_id' => $event['message_id']]);
        $processed++;
    } catch (Throwable $e) {
        $delay = min(3600, 60 * (2 ** min(6, (int)$event['attempts'])));
        $fail = $pdo->prepare("UPDATE `whatsapp_events` SET `status` = 'failed', `next_attempt_at` = DATE_ADD(NOW(), INTERVAL {$delay} SECOND), `last_error` = :error, `updated_at` = NOW() WHERE `message_id` = :message_id");
        $fail->execute([
            'message_id' => $event['message_id'],
            'error' => mb_substr($e->getMessage(), 0, 1000),
        ]);
        error_log('[RestoCloud][whatsapp-worker] ' . $e->getMessage());
    }
}

echo "Processed: {$processed}; queued: " . count($events) . "\n";
