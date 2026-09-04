<?php

require_once __DIR__ . '/../agent/conversation.php';
require_once __DIR__ . '/../agent/recommendations.php';
require_once __DIR__ . '/../agent/tools.php';

function handle_chatbot_execute_tool(PDO $pdo, ?array $authContext, array $input): void
{
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    try {
        requirePermission($authContext, 'settings');

        $toolName = trim((string)($input['tool_name'] ?? ''));
        $toolArgs = $input['arguments'] ?? [];
        $conversationId = trim((string)($input['conversation_id'] ?? ''));

        if ($toolName === '') {
            echo json_encode(['status' => 'error', 'message' => 'tool_name requerido'], $jsonFlags);
            return;
        }

        $businessContext = [
            'tenant_id' => $authContext['tenant_id'],
            'branch_id' => $authContext['branch_id'],
        ];

        $result = chatbot_execute_tool($pdo, $businessContext, $conversationId, $toolName, $toolArgs);
        echo json_encode(['status' => 'success', 'result' => $result], $jsonFlags);
    } catch (Throwable $e) {
        error_log('[RestoCloud][chatbot_tool] ' . $e->getMessage());
        $safeMsg = mb_convert_encoding($e->getMessage(), 'UTF-8', 'UTF-8');
        echo json_encode(['status' => 'error', 'message' => 'Error ejecutando tool: ' . $safeMsg], $jsonFlags);
    }
}
