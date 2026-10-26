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
            u.role,
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
    if ($sub && $sub['status'] === 'suspended' && $user['role'] !== 'super_admin') {
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
        echo json_encode([
            "status" => "success",
            "authenticated" => false,
            "message" => "RestoCloud está disponible. Inicie sesión para continuar."
        ]);
        return;
    }
    echo json_encode(array_merge([
        "status" => "success",
        "message" => "Sesión activa."
    ], buildAuthPayload($authContext)));
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

    if (($authContext['role'] ?? '') === 'super_admin') {
        $access = $pdo->prepare("SELECT b.id FROM `branches` b WHERE b.id = :bid AND b.tenant_id = :tid AND b.active = 1 LIMIT 1");
        $access->execute(['tid' => $authContext['tenant_id'], 'bid' => $branchId]);
    } else {
        $access = $pdo->prepare("\n            SELECT b.id\n            FROM `user_branch_access` uba\n            INNER JOIN `branches` b ON b.id = uba.branch_id AND b.tenant_id = uba.tenant_id\n            WHERE uba.user_id = :uid AND uba.tenant_id = :tid AND uba.branch_id = :bid AND b.active = 1\n            LIMIT 1\n        ");
        $access->execute([
            'uid' => $authContext['user_id'],
            'tid' => $authContext['tenant_id'],
            'bid' => $branchId
        ]);
    }
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

function handle_switch_tenant(PDO $pdo, ?array $authContext, array $input): void {
    if (($authContext['role'] ?? '') !== 'super_admin') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Solo el superadministrador puede cambiar de tenant']);
        return;
    }

    $tenantId = trim((string)($input['tenant_id'] ?? ''));
    if ($tenantId === '') {
        echo json_encode(['status' => 'error', 'message' => 'Tenant requerido']);
        return;
    }

    $tenantStmt = $pdo->prepare("\n        SELECT t.id, t.name\n        FROM `tenants` t\n        WHERE t.id = :tid AND t.active = 1\n        LIMIT 1\n    ");
    $tenantStmt->execute(['tid' => $tenantId]);
    if (!$tenantStmt->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'El tenant no existe o está desactivado']);
        return;
    }

    $branchStmt = $pdo->prepare("\n        SELECT b.id\n        FROM `branches` b\n        WHERE b.tenant_id = :tid AND b.active = 1\n        ORDER BY b.name ASC\n        LIMIT 1\n    ");
    $branchStmt->execute(['tid' => $tenantId]);
    $branch = $branchStmt->fetch(PDO::FETCH_ASSOC);
    if (!$branch) {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => 'El tenant no tiene sucursales activas']);
        return;
    }

    $stmt = $pdo->prepare("\n        UPDATE `user_sessions`\n        SET `tenant_id` = :tid, `branch_id` = :bid, `last_seen_at` = NOW()\n        WHERE `id` = :sid AND `user_id` = :uid AND `ended_at` IS NULL\n    ");
    $stmt->execute([
        'tid' => $tenantId,
        'bid' => $branch['id'],
        'sid' => $authContext['session_id'],
        'uid' => $authContext['user_id']
    ]);

    $nextContext = resolveAuthContext($pdo);
    if (!$nextContext) {
        throw new RuntimeException('No se pudo cambiar el contexto de tenant.');
    }

    writeAuditLog($pdo, $nextContext, 'auth.tenant.switch', 'tenant', $tenantId, [
        'previous_tenant_id' => $authContext['tenant_id']
    ]);
    echo json_encode(array_merge(['status' => 'success'], buildAuthPayload($nextContext)));
}
