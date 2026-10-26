<?php

require_once __DIR__ . '/../agent/conversation.php';
require_once __DIR__ . '/../agent/recommendations.php';
require_once __DIR__ . '/../agent/tools.php';
require_once __DIR__ . '/../agent/chatbot.php';
require_once __DIR__ . '/../llm.php';

function requireChatbotSimulatorEnabled(): void
{
    $enabled = rc_env('CHATBOT_SIMULATOR_ENABLED', rc_env('APP_ENV', 'production') === 'development' ? '1' : '0');
    if ($enabled !== '1') {
        http_response_code(404);
        throw new RuntimeException('El simulador está deshabilitado en este entorno.');
    }
}

function handle_chatbot_simulate(PDO $pdo, ?array $authContext, array $input): void
{
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    try {
        requireChatbotSimulatorEnabled();
        requirePermission($authContext, 'settings');

        $messageText = trim((string)($input['message'] ?? ''));
        if ($messageText === '') {
            echo json_encode(['status' => 'error', 'message' => 'message requerido'], $jsonFlags);
            return;
        }

        $waPhone = trim((string)($input['phone'] ?? 'sim_' . substr($authContext['tenant_id'], 0, 8)));
        $customerName = trim((string)($input['customer_name'] ?? 'Simulador'));

        $businessContext = [
            'tenant_id' => $authContext['tenant_id'],
            'branch_id' => $authContext['branch_id'],
        ];

        if (hasPermission($authContext, 'saas_admin') && !empty($input['tenant_id'])) {
            $tenantId = $input['tenant_id'];
            $branchStmt = $pdo->prepare("SELECT `id` FROM `branches` WHERE `tenant_id` = :tid LIMIT 1");
            $branchStmt->execute(['tid' => $tenantId]);
            $branchRow = $branchStmt->fetch(PDO::FETCH_ASSOC);
            if ($branchRow) {
                $businessContext = [
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchRow['id'],
                ];
            }
        }

        $catalogState = loadCatalogState($pdo, $businessContext);
        $businessConfig = $catalogState['business'] ?? [];

        $result = chatbot_handle_message(
            $pdo,
            $businessContext,
            $businessConfig,
            $waPhone,
            $customerName,
            $messageText
        );

        echo json_encode([
            'status' => 'success',
            'reply' => $result['reply'] ?? '',
            'tool_result' => $result['tool_result'] ?? null,
            'conversation_id' => $result['conversation']['id'] ?? null,
        ], $jsonFlags);
    } catch (Throwable $e) {
        error_log('[RestoCloud][chatbot_simulate] ' . $e->getMessage());
        $safeMsg = mb_convert_encoding($e->getMessage(), 'UTF-8', 'UTF-8');
        echo json_encode(['status' => 'error', 'message' => 'Error al procesar mensaje simulado: ' . $safeMsg], $jsonFlags);
    }
}

function handle_chatbot_close_conversation(PDO $pdo, ?array $authContext, array $input): void
{
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    try {
        requireChatbotSimulatorEnabled();
        requirePermission($authContext, 'settings');

        $conversationId = trim((string)($input['conversation_id'] ?? ''));
        if ($conversationId === '') {
            echo json_encode(['status' => 'error', 'message' => 'conversation_id requerido'], $jsonFlags);
            return;
        }

        $stmt = $pdo->prepare("UPDATE `chatbot_conversations` SET `status` = 'closed', `updated_at` = NOW() WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
        $stmt->execute([
            'id' => $conversationId,
            'tenant_id' => $authContext['tenant_id'],
            'branch_id' => $authContext['branch_id'],
        ]);

        echo json_encode(['status' => 'success', 'message' => 'Conversación cerrada'], $jsonFlags);
    } catch (Throwable $e) {
        error_log('[RestoCloud][chatbot_close] ' . $e->getMessage());
        $safeMsg = mb_convert_encoding($e->getMessage(), 'UTF-8', 'UTF-8');
        echo json_encode(['status' => 'error', 'message' => 'Error al cerrar conversación: ' . $safeMsg], $jsonFlags);
    }
}

function handle_delete_chatbot_conversation(PDO $pdo, ?array $authContext, array $input): void
{
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    try {
        requireChatbotSimulatorEnabled();
        requirePermission($authContext, 'settings');

        $conversationId = trim((string)($input['conversation_id'] ?? ''));
        if ($conversationId === '') {
            echo json_encode(['status' => 'error', 'message' => 'conversation_id requerido'], $jsonFlags);
            return;
        }

        $tenantId = $authContext['tenant_id'];
        $branchId = $authContext['branch_id'];

        if (hasPermission($authContext, 'saas_admin') && !empty($input['tenant_id'])) {
            $branchStmt = $pdo->prepare("SELECT `id` FROM `branches` WHERE `tenant_id` = :tid LIMIT 1");
            $branchStmt->execute(['tid' => $input['tenant_id']]);
            $branchRow = $branchStmt->fetch(PDO::FETCH_ASSOC);
            if ($branchRow) {
                $tenantId = $input['tenant_id'];
                $branchId = $branchRow['id'];
            }
        }

        chatbot_delete_conversation($pdo, $conversationId, $tenantId, $branchId);

        echo json_encode(['status' => 'success', 'message' => 'Conversación eliminada'], $jsonFlags);
    } catch (Throwable $e) {
        error_log('[RestoCloud][chatbot_delete] ' . $e->getMessage());
        $safeMsg = mb_convert_encoding($e->getMessage(), 'UTF-8', 'UTF-8');
        echo json_encode(['status' => 'error', 'message' => 'Error al eliminar conversación: ' . $safeMsg], $jsonFlags);
    }
}
