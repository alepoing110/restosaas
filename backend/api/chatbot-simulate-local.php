<?php

require_once __DIR__ . '/../agent/conversation.php';
require_once __DIR__ . '/../agent/recommendations.php';
require_once __DIR__ . '/../agent/tools.php';
require_once __DIR__ . '/../agent/chatbot.php';

function handle_chatbot_simulate_local(PDO $pdo, ?array $authContext, array $input): void
{
    requireChatbotSimulatorEnabled();
    requirePermission($authContext, 'settings');

    $messageText = trim((string)($input['message'] ?? ''));
    if ($messageText === '') {
        echo json_encode(['status' => 'error', 'message' => 'message requerido']);
        return;
    }

    $waPhone = trim((string)($input['phone'] ?? 'sim_' . substr($authContext['tenant_id'], 0, 8)));
    $customerName = trim((string)($input['customer_name'] ?? 'Simulador'));

    $businessContext = [
        'tenant_id' => $authContext['tenant_id'],
        'branch_id' => $authContext['branch_id'],
    ];

    $catalogState = loadCatalogState($pdo, $businessContext);
    $businessConfig = $catalogState['business'] ?? [];

    $conversation = chatbot_find_or_create_conversation($pdo, $businessContext, $waPhone, $customerName);
    chatbot_append_message($pdo, $conversation['id'], 'user', $messageText);

    $history = chatbot_load_recent_messages($pdo, $conversation['id']);
    $messages = [
        ['role' => 'system', 'content' => chatbot_system_prompt($businessConfig, $conversation['context'] ?? [])],
    ];
    foreach ($history as $message) {
        $msg = ['role' => $message['role']];
        if (array_key_exists('content', $message)) {
            $msg['content'] = $message['content'];
        }
        if (!empty($message['tool_calls'])) {
            $msg['tool_calls'] = $message['tool_calls'];
        }
        if (!empty($message['tool_call_id'])) {
            $msg['tool_call_id'] = $message['tool_call_id'];
        }
        $messages[] = $msg;
    }

    echo json_encode([
        'status' => 'success',
        'conversation_id' => $conversation['id'],
        'messages' => $messages,
        'tools' => chatbot_tool_definitions(),
    ]);
}

function handle_chatbot_save_reply_local(PDO $pdo, ?array $authContext, array $input): void
{
    requireChatbotSimulatorEnabled();
    requirePermission($authContext, 'settings');

    $conversationId = trim((string)($input['conversation_id'] ?? ''));
    $reply = trim((string)($input['reply'] ?? ''));
    $toolCalls = $input['tool_calls'] ?? null;
    $toolResult = $input['tool_result'] ?? null;
    $tokensIn = (int)($input['tokens_in'] ?? 0);
    $tokensOut = (int)($input['tokens_out'] ?? 0);

    if ($conversationId === '' || $reply === '') {
        echo json_encode(['status' => 'error', 'message' => 'conversation_id y reply requeridos']);
        return;
    }

    chatbot_append_message(
        $pdo,
        $conversationId,
        'assistant',
        $reply,
        $toolCalls,
        $toolResult,
        $tokensIn,
        $tokensOut
    );

    if ($toolResult && is_array($toolResult)) {
        $toolName = $toolCalls[0]['function']['name'] ?? '';
        if ($toolName === 'create_reservation' && !empty($toolResult['reservation'])) {
            $r = $toolResult['reservation'];
            chatbot_update_conversation_context($pdo, $conversationId, [
                'reservation_id' => $r['id'] ?? '',
                'customer_name' => $r['customer_name'] ?? '',
                'reservation_date' => $r['reservation_date'] ?? '',
                'reservation_time' => $r['reservation_time'] ?? '',
            ]);
            $update = $pdo->prepare("UPDATE `chatbot_conversations` SET `reservation_id` = :rid, `customer_name` = :cn, `updated_at` = NOW() WHERE `id` = :id");
            $update->execute([
                'id' => $conversationId,
                'rid' => $r['id'] ?? null,
                'cn' => $r['customer_name'] ?? null,
            ]);
        }
    }

    echo json_encode(['status' => 'success']);
}
