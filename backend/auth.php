<?php

function ensureSessionStarted(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $sessionPath = __DIR__ . '/../storage/sessions';
        if (!is_dir($sessionPath)) {
            @mkdir($sessionPath, 0700, true);
        }

        if (is_dir($sessionPath) && is_writable($sessionPath)) {
            session_save_path($sessionPath);
        } else {
            error_log("[RestoCloud] ensureSessionStarted: session path $sessionPath not writable, using default");
        }

        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }
}

function getPublicActions(): array
{
    return ['auth_login', 'admin_login', 'auth_me', 'public_register'];
}

function getRolePermissions(string $role): array
{
    $map = [
        'super_admin' => ['pos', 'orders', 'inventory', 'reports', 'dashboard', 'settings', 'saas_admin'],
        'owner' => ['pos', 'orders', 'inventory', 'reports', 'dashboard', 'settings'],
        'admin' => ['pos', 'orders', 'inventory', 'reports', 'dashboard', 'settings'],
        'cajero' => ['pos', 'orders', 'reports', 'dashboard'],
    ];

    return $map[$role] ?? ['pos'];
}

function hasPermission(?array $context, string $permission): bool
{
    if (!$context) {
        return false;
    }

    return in_array($permission, getRolePermissions($context['role']), true);
}

function requirePermission(?array $context, string $permission): void
{
    if (hasPermission($context, $permission)) {
        return;
    }

    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "No tienes permisos para esta acción"]);
    exit;
}

function buildAuthPayload(?array $context): array
{
    if (!$context) {
        return ['authenticated' => false];
    }

    return [
        'authenticated' => true,
        'csrf_token' => generateCsrfToken(),
        'session' => [
            'id' => $context['session_id'],
            'started_at' => $context['session_started_at'],
            'tenant_id' => $context['tenant_id'],
            'branch_id' => $context['branch_id']
        ],
        'authUser' => [
            'id' => $context['user_id'],
            'name' => $context['user_name'],
            'email' => $context['user_email'],
            'role' => $context['role']
        ],
        'tenant' => [
            'id' => $context['tenant_id'],
            'slug' => $context['tenant_slug'],
            'name' => $context['tenant_name']
        ],
        'branch' => [
            'id' => $context['branch_id'],
            'name' => $context['branch_name']
        ],
        'permissions' => getRolePermissions($context['role']),
        'subscription' => [
            'status' => $context['subscription_status'] ?: 'trial',
            'plan_code' => $context['plan_code'] ?: 'legacy',
            'ends_at' => $context['subscription_ends_at'] ?? null
        ]
    ];
}

function resolveAuthContext(PDO $pdo): ?array
{
    ensureSessionStarted();

    $sessionId = $_SESSION['restocloud_session_id'] ?? null;
    if (!$sessionId) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            us.id AS session_id,
            us.started_at AS session_started_at,
            u.id AS user_id,
            u.name AS user_name,
            u.email AS user_email,
            u.role,
            u.active AS user_active,
            t.id AS tenant_id,
            t.slug AS tenant_slug,
            t.name AS tenant_name,
            t.active AS tenant_active,
            b.id AS branch_id,
            b.name AS branch_name,
            b.active AS branch_active,
            ts.status AS subscription_status,
            ts.plan_code,
            ts.ends_at AS subscription_ends_at
        FROM `user_sessions` us
        INNER JOIN `users` u ON u.id = us.user_id
        INNER JOIN `tenants` t ON t.id = u.tenant_id
        INNER JOIN `branches` b ON b.id = us.branch_id AND b.tenant_id = t.id
        LEFT JOIN `tenant_subscriptions` ts ON ts.tenant_id = t.id
        WHERE us.id = :id AND us.ended_at IS NULL
        LIMIT 1
    ");
    $stmt->execute(['id' => $sessionId]);
    $context = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$context) {
        error_log("[RestoCloud] resolveAuthContext: session $sessionId not found in DB or ended");
        unset($_SESSION['restocloud_session_id']);
        return null;
    }

    if (!(int)$context['user_active'] || !(int)$context['tenant_active'] || !(int)$context['branch_active']) {
        error_log("[RestoCloud] resolveAuthContext: session $sessionId — inactive user/tenant/branch");
        unset($_SESSION['restocloud_session_id']);
        return null;
    }

    if (($context['subscription_status'] ?? 'trial') === 'suspended') {
        unset($_SESSION['restocloud_session_id']);
        return null;
    }

    if (($context['subscription_status'] ?? '') === 'trial' && !empty($context['subscription_ends_at'])) {
        $endsAt = new DateTime($context['subscription_ends_at']);
        $now = new DateTime('now');
        if ($now > $endsAt) {
            $stmt = $pdo->prepare("UPDATE `tenant_subscriptions` SET `status` = 'suspended' WHERE `tenant_id` = :tid AND `status` = 'trial'");
            $stmt->execute(['tid' => $context['tenant_id']]);
            unset($_SESSION['restocloud_session_id']);
            return null;
        }
    }

    return $context;
}

function createUserSession(PDO $pdo, array $user): array
{
    ensureSessionStarted();

    $sessionId = 'ses_' . bin2hex(random_bytes(12));
    $stmt = $pdo->prepare("
        INSERT INTO `user_sessions` (`id`, `user_id`, `tenant_id`, `branch_id`, `started_at`, `last_seen_at`, `ip_address`, `user_agent`)
        VALUES (:id, :user_id, :tenant_id, :branch_id, NOW(), NOW(), :ip_address, :user_agent)
    ");
    $stmt->execute([
        'id' => $sessionId,
        'user_id' => $user['user_id'],
        'tenant_id' => $user['tenant_id'],
        'branch_id' => $user['branch_id'],
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
    ]);

    $_SESSION['restocloud_session_id'] = $sessionId;

    $context = resolveAuthContext($pdo);
    if (!$context) {
        throw new RuntimeException('No se pudo inicializar la sesión del usuario.');
    }

    return $context;
}

function closeUserSession(PDO $pdo): void
{
    ensureSessionStarted();
    $sessionId = $_SESSION['restocloud_session_id'] ?? null;
    if ($sessionId) {
        $stmt = $pdo->prepare("UPDATE `user_sessions` SET `ended_at` = NOW(), `last_seen_at` = NOW() WHERE `id` = :id AND `ended_at` IS NULL");
        $stmt->execute(['id' => $sessionId]);
    }
    unset($_SESSION['restocloud_session_id']);
}

function touchUserSession(PDO $pdo, ?array $context): bool
{
    if (!$context) {
        return true;
    }

    $maxInactive = 8 * 3600;
    $sessionId = $context['session_id'] ?? 'unknown';

    // Try to expire inactive session in one query
    $expireStmt = $pdo->prepare("UPDATE `user_sessions` SET `ended_at` = NOW() WHERE `id` = :id AND `ended_at` IS NULL AND TIMESTAMPDIFF(SECOND, `last_seen_at`, NOW()) > :maxInactive");
    $expireStmt->execute(['id' => $sessionId, 'maxInactive' => $maxInactive]);
    if ($expireStmt->rowCount() > 0) {
        error_log("[RestoCloud] Session expired by inactivity: $sessionId");
        return false;
    }

    // Active session — touch it in one query
    $touchStmt = $pdo->prepare("UPDATE `user_sessions` SET `last_seen_at` = NOW() WHERE `id` = :id AND `ended_at` IS NULL");
    $touchStmt->execute(['id' => $sessionId]);
    if ($touchStmt->rowCount() > 0) {
        return true;
    }

    // Touch returned 0 rows but expire also returned 0 — anomalous.
    // The session was found by resolveAuthContext but UPDATE didn't match.
    // Log and allow the request (trust the earlier validation).
    error_log("[RestoCloud] touchUserSession: no rows touched for $sessionId (resolveAuthContext found it). Session may have been deleted or ended between queries.");
    return true;
}

function writeAuditLog(PDO $pdo, ?array $context, string $action, string $entityType, ?string $entityId = null, array $payload = []): void
{
    if (!$context) {
        return;
    }

    // Sanitize sensitive fields from payload
    $sensitiveKeys = ['password', 'password_hash', 'token', 'secret', 'api_key'];
    foreach ($payload as $key => $value) {
        if (in_array(strtolower($key), $sensitiveKeys, true)) {
            $payload[$key] = '***REDACTED***';
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO `audit_logs` (`id`, `tenant_id`, `branch_id`, `user_id`, `action`, `entity_type`, `entity_id`, `payload`, `created_at`)
        VALUES (:id, :tenant_id, :branch_id, :user_id, :action, :entity_type, :entity_id, :payload, NOW())
    ");
    $stmt->execute([
        'id' => 'aud_' . bin2hex(random_bytes(12)),
        'tenant_id' => $context['tenant_id'],
        'branch_id' => $context['branch_id'],
        'user_id' => $context['user_id'],
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
}

// ==========================================================================
// ADMIN SESSION (separate from POS — uses same session key but validates saas_admin)
// ==========================================================================

function createAdminSession(PDO $pdo, string $email, string $password): array
{
    $stmt = $pdo->prepare("
        SELECT u.id AS user_id, u.name, u.email, u.password_hash, u.role, u.tenant_id, u.branch_id, u.active
        FROM `users` u
        WHERE u.email = :email AND u.active = 1
        LIMIT 1
    ");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        throw new RuntimeException('Credenciales inválidas');
    }

    if (!in_array('saas_admin', getRolePermissions($user['role']), true)) {
        throw new RuntimeException('No tienes permisos de administrador SaaS');
    }

    return createUserSession($pdo, $user);
}

// ==========================================================================
// RATE LIMITING (file-based)
// ==========================================================================

function _rateLimitDir(): string {
    $dir = __DIR__ . '/../storage/rate_limits';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    return $dir;
}

function _rateLimitKey(string $identifier): string {
    return _rateLimitDir() . '/' . md5($identifier) . '.json';
}

function checkRateLimit(string $identifier, int $maxAttempts = 5, int $lockoutSeconds = 900): ?array {
    $file = _rateLimitKey($identifier);
    $data = ['attempts' => 0, 'first_at' => time(), 'locked_until' => 0];

    if (is_file($file)) {
        $fp = fopen($file, 'rb');
        if ($fp && flock($fp, LOCK_SH)) {
            $size = filesize($file);
            if ($size > 0) {
                $raw = @fread($fp, $size);
                if ($raw && $raw !== false) $data = json_decode($raw, true) ?: $data;
            }
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    if (isset($data['locked_until']) && $data['locked_until'] > 0 && $data['locked_until'] > time()) {
        $retryAfter = $data['locked_until'] - time();
        return ['locked' => true, 'retry_after' => $retryAfter];
    }
    if (isset($data['locked_until']) && $data['locked_until'] > 0 && $data['locked_until'] <= time()) {
        @unlink($file);
    }
    return ['locked' => false, 'attempts' => $data['attempts'] ?? 0];
}

function recordFailedAttempt(string $identifier, int $maxAttempts = 5, int $lockoutSeconds = 900): void {
    $file = _rateLimitKey($identifier);
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $fp = @fopen($file, 'c+b');
    if (!$fp) return;

    if (flock($fp, LOCK_EX)) {
        $data = ['attempts' => 0, 'first_at' => time(), 'locked_until' => 0];
        $raw = @fread($fp, filesize($file) ?: 1);
        if ($raw && $raw !== false) $data = json_decode($raw, true) ?: $data;

        $data['attempts'] = ($data['attempts'] ?? 0) + 1;
        $elapsed = time() - ($data['first_at'] ?? time());
        if ($elapsed > $lockoutSeconds) {
            $data['attempts'] = 1;
            $data['first_at'] = time();
        }
        if ($data['attempts'] >= $maxAttempts) {
            $data['locked_until'] = time() + $lockoutSeconds;
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

function clearRateLimit(string $identifier): void {
    $file = _rateLimitKey($identifier);
    if (is_file($file)) @unlink($file);
}

// ==========================================================================
// CSRF PROTECTION
// ==========================================================================

function generateCsrfToken(): string {
    ensureSessionStarted();
    if (empty($_SESSION['restocloud_csrf_token'])) {
        $_SESSION['restocloud_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['restocloud_csrf_token'];
}

function validateCsrfToken(?string $token): bool {
    ensureSessionStarted();
    if (!$token || empty($_SESSION['restocloud_csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['restocloud_csrf_token'], $token);
}

function requireCsrfToken(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrfToken($token)) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Token CSRF inválido. Recargue la página."]);
        exit;
    }
}
