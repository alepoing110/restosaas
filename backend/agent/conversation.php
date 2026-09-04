<?php

function chatbot_find_or_create_conversation(PDO $pdo, array $context, string $waPhone, ?string $customerName = null): array
{
    $stmt = $pdo->prepare("SELECT * FROM `chatbot_conversations` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `wa_phone` = :wa_phone AND `status` = 'active' ORDER BY `updated_at` DESC LIMIT 1");
    $stmt->execute([
        'tenant_id' => $context['tenant_id'],
        'branch_id' => $context['branch_id'],
        'wa_phone' => $waPhone,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $row['context'] = !empty($row['context']) ? (json_decode($row['context'], true) ?: []) : [];
        return $row;
    }

    $id = 'conv_' . bin2hex(random_bytes(8));
    $insert = $pdo->prepare("INSERT INTO `chatbot_conversations` (`id`, `tenant_id`, `branch_id`, `wa_phone`, `customer_name`, `context`) VALUES (:id, :tenant_id, :branch_id, :wa_phone, :customer_name, :context)");
    $insert->execute([
        'id' => $id,
        'tenant_id' => $context['tenant_id'],
        'branch_id' => $context['branch_id'],
        'wa_phone' => $waPhone,
        'customer_name' => $customerName,
        'context' => json_encode([], JSON_UNESCAPED_UNICODE),
    ]);

    return [
        'id' => $id,
        'tenant_id' => $context['tenant_id'],
        'branch_id' => $context['branch_id'],
        'wa_phone' => $waPhone,
        'customer_name' => $customerName,
        'status' => 'active',
        'context' => [],
    ];
}

function chatbot_update_conversation_context(PDO $pdo, string $conversationId, array $contextPatch): void
{
    $current = chatbot_get_conversation($pdo, $conversationId);
    $merged = array_merge($current['context'] ?? [], $contextPatch);
    $stmt = $pdo->prepare("UPDATE `chatbot_conversations` SET `context` = :context, `updated_at` = NOW() WHERE `id` = :id");
    $stmt->execute([
        'id' => $conversationId,
        'context' => json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function chatbot_get_conversation(PDO $pdo, string $conversationId): array
{
    $stmt = $pdo->prepare("SELECT * FROM `chatbot_conversations` WHERE `id` = :id LIMIT 1");
    $stmt->execute(['id' => $conversationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Conversación no encontrada.');
    }
    $row['context'] = !empty($row['context']) ? (json_decode($row['context'], true) ?: []) : [];
    return $row;
}

function chatbot_append_message(PDO $pdo, string $conversationId, string $role, string $content, ?array $toolCalls = null, ?array $toolResult = null, int $tokensIn = 0, int $tokensOut = 0): void
{
    $stmt = $pdo->prepare("INSERT INTO `chatbot_messages` (`id`, `conversation_id`, `role`, `content`, `tool_calls`, `tool_result`, `tokens_in`, `tokens_out`) VALUES (:id, :conversation_id, :role, :content, :tool_calls, :tool_result, :tokens_in, :tokens_out)");
    $stmt->execute([
        'id' => 'msg_' . bin2hex(random_bytes(8)),
        'conversation_id' => $conversationId,
        'role' => $role,
        'content' => $content,
        'tool_calls' => $toolCalls ? json_encode($toolCalls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        'tool_result' => $toolResult ? json_encode($toolResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        'tokens_in' => $tokensIn,
        'tokens_out' => $tokensOut,
    ]);
}

function chatbot_delete_conversation(PDO $pdo, string $conversationId, string $tenantId, string $branchId): void
{
    $stmt = $pdo->prepare("DELETE FROM `chatbot_messages` WHERE `conversation_id` = :id");
    $stmt->execute(['id' => $conversationId]);

    $stmt = $pdo->prepare("DELETE FROM `chatbot_conversations` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    $stmt->execute(['id' => $conversationId, 'tenant_id' => $tenantId, 'branch_id' => $branchId]);
}

function chatbot_load_recent_messages(PDO $pdo, string $conversationId, int $limit = 12): array
{
    $stmt = $pdo->prepare("SELECT `role`, `content`, `tool_calls`, `tool_result` FROM `chatbot_messages` WHERE `conversation_id` = :conversation_id ORDER BY `created_at` DESC LIMIT :limit");
    $stmt->bindValue(':conversation_id', $conversationId);
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

    $messages = [];
    foreach ($rows as $row) {
        $role = $row['role'];
        $content = $row['content'] ?? '';
        $toolCalls = !empty($row['tool_calls']) ? json_decode($row['tool_calls'], true) : null;
        $toolResult = !empty($row['tool_result']) ? json_decode($row['tool_result'], true) : null;

        if ($role === 'assistant' && $toolCalls && is_array($toolCalls)) {
            $messages[] = [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => $toolCalls,
            ];
            foreach ($toolCalls as $tc) {
                $toolCallId = $tc['id'] ?? 'tool_1';
                $resultForCall = $toolResult;
                if (is_array($toolResult) && array_key_exists($toolCallId, $toolResult)) {
                    $resultForCall = $toolResult[$toolCallId];
                }
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCallId,
                    'content' => json_encode($resultForCall ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
        } elseif ($role === 'assistant' && str_starts_with($content, 'Tool call: ')) {
            continue;
        } else {
            $messages[] = [
                'role' => $role,
                'content' => $content,
            ];
        }
    }

    return $messages;
}
