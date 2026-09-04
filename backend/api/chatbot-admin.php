<?php

function chatbot_resolve_impersonated_context(PDO $pdo, array $authContext, array $input): array
{
    $context = [
        'tenant_id' => $authContext['tenant_id'],
        'branch_id' => $authContext['branch_id'],
    ];
    if (hasPermission($authContext, 'saas_admin') && !empty($input['tenant_id'])) {
        $branchStmt = $pdo->prepare("SELECT `id` FROM `branches` WHERE `tenant_id` = :tid LIMIT 1");
        $branchStmt->execute(['tid' => $input['tenant_id']]);
        $branchRow = $branchStmt->fetch(PDO::FETCH_ASSOC);
        if ($branchRow) {
            $context['tenant_id'] = $input['tenant_id'];
            $context['branch_id'] = $branchRow['id'];
        }
    }
    return $context;
}

function handle_get_chatbot_conversations(PDO $pdo, ?array $authContext, array $input): void
{
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    try {
        requirePermission($authContext, 'settings');

        $ctx = chatbot_resolve_impersonated_context($pdo, $authContext, $input);
        $limit = max(1, min(100, (int)($input['limit'] ?? 30)));
        $stmt = $pdo->prepare("SELECT * FROM `chatbot_conversations` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id ORDER BY `updated_at` DESC LIMIT :limit");
        $stmt->bindValue(':tenant_id', $ctx['tenant_id']);
        $stmt->bindValue(':branch_id', $ctx['branch_id']);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $row['context'] = !empty($row['context']) ? json_decode($row['context'], true) ?? [] : [];
        }
        unset($row);

        echo json_encode([
            'status' => 'success',
            'conversations' => $rows,
        ], $jsonFlags);
    } catch (Throwable $e) {
        error_log('[RestoCloud][chatbot_conversations] ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener conversaciones'], $jsonFlags);
    }
}

function handle_get_chatbot_messages(PDO $pdo, ?array $authContext, array $input): void
{
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    try {
        requirePermission($authContext, 'settings');

        $conversationId = trim((string)($input['conversation_id'] ?? ''));
        if ($conversationId === '') {
            echo json_encode(['status' => 'error', 'message' => 'conversation_id requerido'], $jsonFlags);
            return;
        }

        $ctx = chatbot_resolve_impersonated_context($pdo, $authContext, $input);
        $stmtConversation = $pdo->prepare("SELECT `id` FROM `chatbot_conversations` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id LIMIT 1");
        $stmtConversation->execute([
            'id' => $conversationId,
            'tenant_id' => $ctx['tenant_id'],
            'branch_id' => $ctx['branch_id'],
        ]);
        if (!$stmtConversation->fetch(PDO::FETCH_ASSOC)) {
            echo json_encode(['status' => 'error', 'message' => 'Conversación no encontrada'], $jsonFlags);
            return;
        }

        $stmt = $pdo->prepare("SELECT * FROM `chatbot_messages` WHERE `conversation_id` = :conversation_id ORDER BY `created_at` ASC");
        $stmt->execute(['conversation_id' => $conversationId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($messages as &$message) {
            $message['tool_calls'] = !empty($message['tool_calls']) ? json_decode($message['tool_calls'], true) ?? [] : [];
            $message['tool_result'] = !empty($message['tool_result']) ? json_decode($message['tool_result'], true) ?? [] : [];
            $message['tokens_in'] = (int)($message['tokens_in'] ?? 0);
            $message['tokens_out'] = (int)($message['tokens_out'] ?? 0);
        }
        unset($message);

        echo json_encode([
            'status' => 'success',
            'messages' => $messages,
        ], $jsonFlags);
    } catch (Throwable $e) {
        error_log('[RestoCloud][chatbot_messages] ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Error al obtener mensajes'], $jsonFlags);
    }
}

function handle_update_chatbot_attention(PDO $pdo, ?array $authContext, array $input): void
{
    requirePermission($authContext, 'settings');
    $conversationId = trim((string)($input['conversation_id'] ?? ''));
    $mode = trim((string)($input['attention_mode'] ?? ''));
    $assignedUserId = trim((string)($input['assigned_user_id'] ?? ''));
    if ($conversationId === '' || !in_array($mode, ['bot', 'humano'], true)) throw new InvalidArgumentException('Datos de atención inválidos.');
    if ($assignedUserId === '') $assignedUserId = $mode === 'humano' ? $authContext['user_id'] : '';
    if ($mode === 'humano' && $assignedUserId !== $authContext['user_id'] && !in_array($authContext['role'], ['owner', 'super_admin'], true)) {
        throw new InvalidArgumentException('Solo el propietario puede asignar conversaciones a otro usuario.');
    }
    if ($assignedUserId !== '') {
        $user = $pdo->prepare("SELECT id FROM `users` WHERE id = :id AND tenant_id = :tenant_id AND active = 1 LIMIT 1");
        $user->execute(['id' => $assignedUserId, 'tenant_id' => $authContext['tenant_id']]);
        if (!$user->fetchColumn()) throw new InvalidArgumentException('Usuario asignado no válido.');
    }
    $stmt = $pdo->prepare("UPDATE `chatbot_conversations` SET `attention_mode` = :mode, `assigned_user_id` = :assigned_user_id, `assigned_at` = CASE WHEN :mode = 'humano' THEN NOW() ELSE NULL END, `human_takeover_at` = CASE WHEN :mode = 'humano' THEN NOW() ELSE NULL END, `updated_at` = NOW() WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    $stmt->execute(['mode' => $mode, 'assigned_user_id' => $assignedUserId ?: null, 'id' => $conversationId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Conversación no encontrada.');
    writeAuditLog($pdo, $authContext, 'chatbot.attention.update', 'chatbot_conversation', $conversationId, ['mode' => $mode, 'assigned_user_id' => $assignedUserId ?: null]);
    echo json_encode(['status' => 'success']);
}

function handle_send_human_whatsapp_reply(PDO $pdo, ?array $authContext, array $input): void
{
    requirePermission($authContext, 'settings');
    $conversationId = trim((string)($input['conversation_id'] ?? ''));
    $content = trim((string)($input['content'] ?? ''));
    if ($conversationId === '' || $content === '' || mb_strlen($content) > 4096) throw new InvalidArgumentException('Mensaje inválido.');
    $stmt = $pdo->prepare("SELECT wa_phone, assigned_user_id, attention_mode FROM `chatbot_conversations` WHERE id = :id AND tenant_id = :tenant_id AND branch_id = :branch_id LIMIT 1");
    $stmt->execute(['id' => $conversationId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    $conversation = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$conversation || $conversation['attention_mode'] !== 'humano') throw new InvalidArgumentException('La conversación no está en atención humana.');
    if ($conversation['assigned_user_id'] && $conversation['assigned_user_id'] !== $authContext['user_id'] && !in_array($authContext['role'], ['owner', 'super_admin'], true)) throw new InvalidArgumentException('La conversación está asignada a otro usuario.');
    whatsapp_send_text((string)$conversation['wa_phone'], $content);
    chatbot_append_message($pdo, $conversationId, 'assistant', $content, null, ['human' => true]);
    $pdo->prepare("UPDATE `chatbot_conversations` SET `updated_at` = NOW() WHERE id = :id")->execute(['id' => $conversationId]);
    writeAuditLog($pdo, $authContext, 'chatbot.human_reply.send', 'chatbot_conversation', $conversationId);
    echo json_encode(['status' => 'success']);
}
