<?php
// Auth action handlers

function handle_auth_login(PDO $pdo, ?array $authContext, array $input): void {
    $email = strtolower(trim($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');

    if ($email === '' || $password === '') {
        echo json_encode(["status" => "error", "message" => "Email y contraseña son requeridos"]);
        return;
    }

    $rl = checkRateLimit('auth:' . $email);
    if ($rl['locked']) {
        http_response_code(429);
        echo json_encode(["status" => "error", "message" => "Demasiados intentos. Intente de nuevo en " . $rl['retry_after'] . " segundos."]);
        return;
    }

    $stmt = $pdo->prepare("
        SELECT
            u.id AS user_id,
            u.tenant_id,
            u.branch_id,
            u.password_hash,
            u.active AS user_active,
            t.active AS tenant_active,
            b.active AS branch_active
        FROM `users` u
        INNER JOIN `tenants` t ON t.id = u.tenant_id
        INNER JOIN `branches` b ON b.id = u.branch_id
        WHERE LOWER(u.email) = :email
        LIMIT 1
    ");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        recordFailedAttempt('auth:' . $email);
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Credenciales inválidas"]);
        return;
    }

    if (!(int)$user['user_active'] || !(int)$user['tenant_active'] || !(int)$user['branch_active']) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Usuario o sucursal desactivados"]);
        return;
    }

    $subStmt = $pdo->prepare("SELECT `status` FROM `tenant_subscriptions` WHERE `tenant_id` = :tid LIMIT 1");
    $subStmt->execute(['tid' => $user['tenant_id']]);
    $sub = $subStmt->fetch(PDO::FETCH_ASSOC);
    if ($sub && $sub['status'] === 'suspended') {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Suscripción suspendida. Contacte al administrador."]);
        return;
    }

    clearRateLimit('auth:' . $email);
    $authContext = createUserSession($pdo, $user);
    writeAuditLog($pdo, $authContext, 'auth.login', 'user', $authContext['user_id']);

    echo json_encode(array_merge(["status" => "success"], buildAuthPayload($authContext)));
}

function handle_admin_login(PDO $pdo, ?array $authContext, array $input): void {
    $email = strtolower(trim($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');

    if ($email === '' || $password === '') {
        echo json_encode(["status" => "error", "message" => "Email y contraseña son requeridos"]);
        return;
    }

    $rl = checkRateLimit('admin:' . $email);
    if ($rl['locked']) {
        http_response_code(429);
        echo json_encode(["status" => "error", "message" => "Demasiados intentos. Intente de nuevo en " . $rl['retry_after'] . " segundos."]);
        return;
    }

    try {
        $authContext = createAdminSession($pdo, $email, $password);
        clearRateLimit('admin:' . $email);
        writeAuditLog($pdo, $authContext, 'admin.login', 'user', $authContext['user_id']);
        echo json_encode(array_merge(["status" => "success"], buildAuthPayload($authContext)));
    } catch (RuntimeException $e) {
        recordFailedAttempt('admin:' . $email);
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Credenciales inválidas"]);
    }
}

function handle_auth_me(PDO $pdo, ?array $authContext, array $input): void {
    if (!$authContext) {
        echo json_encode(["status" => "success", "authenticated" => false]);
        return;
    }
    echo json_encode(array_merge(["status" => "success"], buildAuthPayload($authContext)));
}

function handle_auth_logout(PDO $pdo, ?array $authContext, array $input): void {
    writeAuditLog($pdo, $authContext, 'auth.logout', 'user', $authContext['user_id']);
    closeUserSession($pdo);
    echo json_encode(["status" => "success"]);
}

function handle_switch_branch(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    $branchId = trim((string)($input['branch_id'] ?? ''));
    if ($branchId === '') {
        echo json_encode(['status' => 'error', 'message' => 'Sucursal requerida']);
        return;
    }

    $access = $pdo->prepare("\n        SELECT b.id\n        FROM `user_branch_access` uba\n        INNER JOIN `branches` b ON b.id = uba.branch_id AND b.tenant_id = uba.tenant_id\n        WHERE uba.user_id = :uid AND uba.tenant_id = :tid AND uba.branch_id = :bid AND b.active = 1\n        LIMIT 1\n    ");
    $access->execute([
        'uid' => $authContext['user_id'],
        'tid' => $authContext['tenant_id'],
        'bid' => $branchId
    ]);
    if (!$access->fetch()) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'No tienes acceso a esta sucursal']);
        return;
    }

    $stmt = $pdo->prepare("UPDATE `user_sessions` SET `branch_id` = :bid, `last_seen_at` = NOW() WHERE `id` = :sid AND `user_id` = :uid AND `tenant_id` = :tid AND `ended_at` IS NULL");
    $stmt->execute(['bid' => $branchId, 'sid' => $authContext['session_id'], 'uid' => $authContext['user_id'], 'tid' => $authContext['tenant_id']]);
    $nextContext = resolveAuthContext($pdo);
    if (!$nextContext) {
        throw new RuntimeException('No se pudo cambiar el contexto de sucursal.');
    }
    writeAuditLog($pdo, $nextContext, 'auth.branch.switch', 'branch', $branchId);
    echo json_encode(array_merge(['status' => 'success'], buildAuthPayload($nextContext)));
}
