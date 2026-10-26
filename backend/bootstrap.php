<?php

/** Create the first administrator only; never reset or promote an existing user. */
function rc_bootstrap_initial_admin(PDO $pdo, string $email, string $password): bool
{
    if ((int)$pdo->query('SELECT COUNT(*) FROM `users`')->fetchColumn() > 0) {
        return false;
    }

    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        throw new RuntimeException('Configure INITIAL_ADMIN_EMAIL y INITIAL_ADMIN_PASSWORD (mínimo 8 caracteres) para inicializar el administrador.');
    }

    // Two first requests must not create two administrators. This also protects
    // hosts whose pre-existing tables use nontransactional storage engines.
    $lockName = 'rc_initial_admin_' . substr(hash('sha256', (string)$pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 40);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('No se pudo obtener el bloqueo de inicialización del administrador.');
    }

    try {
        if ((int)$pdo->query('SELECT COUNT(*) FROM `users`')->fetchColumn() > 0) {
            return false;
        }
        $pdo->beginTransaction();
        try {
            $tenant = $pdo->query('SELECT `id` FROM `tenants` WHERE `active` = 1 ORDER BY `created_at`, `id` LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if (!$tenant) {
                $tenantId = 'tenant_' . bin2hex(random_bytes(12));
                $slug = 'restocloud-' . bin2hex(random_bytes(4));
                $pdo->prepare('INSERT INTO `tenants` (`id`, `slug`, `name`, `active`) VALUES (?, ?, ?, 1)')
                    ->execute([$tenantId, $slug, 'RestoCloud']);
            } else {
                $tenantId = $tenant['id'];
            }

            $branchStmt = $pdo->prepare('SELECT `id` FROM `branches` WHERE `tenant_id` = ? AND `active` = 1 ORDER BY `created_at`, `id` LIMIT 1');
            $branchStmt->execute([$tenantId]);
            $branchId = $branchStmt->fetchColumn();
            if (!$branchId) {
                $branchId = 'branch_' . bin2hex(random_bytes(12));
                $pdo->prepare('INSERT INTO `branches` (`id`, `tenant_id`, `name`, `active`) VALUES (?, ?, ?, 1)')
                    ->execute([$branchId, $tenantId, 'Sucursal Principal']);
            }

            $userId = 'user_' . bin2hex(random_bytes(12));
            $pdo->prepare("INSERT INTO `users` (`id`, `tenant_id`, `branch_id`, `name`, `email`, `password_hash`, `role`, `active`) VALUES (?, ?, ?, ?, ?, ?, 'super_admin', 1)")
                ->execute([$userId, $tenantId, $branchId, 'Administrador principal', $email, password_hash($password, PASSWORD_DEFAULT)]);
            $pdo->prepare('INSERT INTO `user_branch_access` (`user_id`, `tenant_id`, `branch_id`) VALUES (?, ?, ?)')
                ->execute([$userId, $tenantId, $branchId]);
            $pdo->commit();
            error_log('[RestoCloud] Initial super_admin created successfully.');
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
}
