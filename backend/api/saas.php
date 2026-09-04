<?php
// SaaS admin action handlers

function handle_get_saas_admin(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'saas_admin');
    echo json_encode(array_merge(["status" => "success"], loadSaasAdminData($pdo)));
}

function handle_saas_create_tenant(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'saas_admin');

    $tenantName = trim($input['tenant_name'] ?? '');
    $tenantSlug = trim($input['tenant_slug'] ?? '');
    $ownerName = trim($input['owner_name'] ?? '');
    $ownerEmail = strtolower(trim($input['owner_email'] ?? ''));
    $ownerPassword = (string)($input['owner_password'] ?? '');
    $branchName = trim($input['branch_name'] ?? '');
    $planCode = trim($input['plan_code'] ?? 'starter');
    $subscriptionStatus = trim($input['subscription_status'] ?? 'trial');
    $businessType = trim($input['business_type'] ?? 'restaurante');
    $planLevel = trim($input['plan_level'] ?? 'basico');

    $validBusinessTypes = ['restaurante', 'cafeteria', 'tienda', 'bar', 'otro'];
    if (!in_array($businessType, $validBusinessTypes, true)) $businessType = 'restaurante';
    $validPlanLevels = ['basico', 'premium', 'enterprise'];
    if (!in_array($planLevel, $validPlanLevels, true)) $planLevel = 'basico';

    if ($tenantName === '' || $tenantSlug === '' || $ownerName === '' || $ownerEmail === '' || $ownerPassword === '' || $branchName === '') {
        echo json_encode(["status" => "error", "message" => "Completa todos los campos requeridos"]);
        return;
    }

    if (!preg_match('/^[a-z0-9-]{3,50}$/', $tenantSlug)) {
        echo json_encode(["status" => "error", "message" => "El slug debe tener solo minúsculas, números y guiones"]);
        return;
    }

    if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(["status" => "error", "message" => "Email inválido"]);
        return;
    }

    if (strlen($ownerPassword) < 8) {
        echo json_encode(["status" => "error", "message" => "La contraseña debe tener al menos 8 caracteres"]);
        return;
    }
    if (!preg_match('/[A-Z]/', $ownerPassword) || !preg_match('/[a-z]/', $ownerPassword) || !preg_match('/[0-9]/', $ownerPassword)) {
        echo json_encode(["status" => "error", "message" => "La contraseña debe incluir al menos una mayúscula, una minúscula y un número"]);
        return;
    }

    $validStatuses = ['trial', 'active', 'past_due', 'suspended', 'pending_payment'];
    if (!in_array($subscriptionStatus, $validStatuses, true)) {
        $subscriptionStatus = 'trial';
    }

    $tenantId = 'tenant_' . bin2hex(random_bytes(8));
    $branchId = 'branch_' . bin2hex(random_bytes(8));
    $userId = 'user_' . bin2hex(random_bytes(8));
    $subscriptionId = 'sub_' . bin2hex(random_bytes(8));

    $slugCheck = $pdo->prepare("SELECT id FROM `tenants` WHERE slug = :slug LIMIT 1");
    $slugCheck->execute(['slug' => $tenantSlug]);
    if ($slugCheck->fetch()) {
        echo json_encode(["status" => "error", "message" => "El slug ya está en uso"]);
        return;
    }
    $emailCheck = $pdo->prepare("SELECT id FROM `users` WHERE email = :email LIMIT 1");
    $emailCheck->execute(['email' => $ownerEmail]);
    if ($emailCheck->fetch()) {
        echo json_encode(["status" => "error", "message" => "Ya existe un usuario con este email"]);
        return;
    }
    $planRow = $pdo->prepare("SELECT id, trial_days FROM `plans` WHERE `code` = :code AND `active` = 1 LIMIT 1");
    $planRow->execute(['code' => $planCode]);
    $plan = $planRow->fetch(PDO::FETCH_ASSOC);
    if (!$plan) {
        echo json_encode(["status" => "error", "message" => "El plan seleccionado no está disponible"]);
        return;
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("INSERT INTO `tenants` (`id`, `slug`, `name`, `business_type`, `plan_level`, `active`, `created_at`) VALUES (:id, :slug, :name, :business_type, :plan_level, 1, NOW())");
    $stmt->execute(['id' => $tenantId, 'slug' => $tenantSlug, 'name' => $tenantName, 'business_type' => $businessType, 'plan_level' => $planLevel]);

    $stmt = $pdo->prepare("INSERT INTO `branches` (`id`, `tenant_id`, `name`, `active`, `created_at`) VALUES (:id, :tenant_id, :name, 1, NOW())");
    $stmt->execute(['id' => $branchId, 'tenant_id' => $tenantId, 'name' => $branchName]);

    $stmt = $pdo->prepare("INSERT INTO `users` (`id`, `tenant_id`, `branch_id`, `name`, `email`, `password_hash`, `role`, `active`, `created_at`) VALUES (:id, :tenant_id, :branch_id, :name, :email, :password_hash, 'owner', 1, NOW())");
    $stmt->execute(['id' => $userId, 'tenant_id' => $tenantId, 'branch_id' => $branchId, 'name' => $ownerName, 'email' => $ownerEmail, 'password_hash' => password_hash($ownerPassword, PASSWORD_DEFAULT)]);

    $planId = $plan['id'];
    $trialDays = (int)($plan['trial_days'] ?? 14);
    $endsAt = $subscriptionStatus === 'trial' ? date('Y-m-d H:i:s', strtotime("+{$trialDays} days")) : null;
    $snapshot = $planId ? createPlanSnapshot($pdo, $planId) : null;

    $stmt = $pdo->prepare("INSERT INTO `tenant_subscriptions` (`id`, `tenant_id`, `plan_code`, `plan_id`, `status`, `starts_at`, `ends_at`, `plan_snapshot`, `created_at`) VALUES (:id, :tenant_id, :plan_code, :plan_id, :status, NOW(), :ends_at, :plan_snapshot, NOW())");
    $stmt->execute(['id' => $subscriptionId, 'tenant_id' => $tenantId, 'plan_code' => $planCode, 'plan_id' => $planId, 'status' => $subscriptionStatus, 'ends_at' => $endsAt, 'plan_snapshot' => $snapshot]);

    $stmt = $pdo->prepare("INSERT INTO `user_branch_access` (`user_id`, `tenant_id`, `branch_id`) VALUES (:uid, :tid, :bid)");
    $stmt->execute(['uid' => $userId, 'tid' => $tenantId, 'bid' => $branchId]);
    initializeBranchTemplate($pdo, $tenantId, $branchId, $tenantName);

    $pdo->commit();
    writeAuditLog($pdo, $authContext, 'saas.tenant.create', 'tenant', $tenantId, ['owner_email' => $ownerEmail, 'branch_id' => $branchId]);

    echo json_encode(array_merge(["status" => "success", "message" => "Tenant creado"], loadSaasAdminData($pdo)));
}

function handle_saas_create_branch(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'saas_admin');

    $tenantId = trim($input['tenant_id'] ?? '');
    $branchName = trim($input['branch_name'] ?? '');
    if ($tenantId === '' || $branchName === '') {
        echo json_encode(["status" => "error", "message" => "Tenant y nombre de sucursal son requeridos"]);
        return;
    }

    $tenantStmt = $pdo->prepare("SELECT name FROM `tenants` WHERE id = :tid AND active = 1 LIMIT 1");
    $tenantStmt->execute(['tid' => $tenantId]);
    $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);
    if (!$tenant) {
        echo json_encode(["status" => "error", "message" => "Tenant no válido o inactivo"]);
        return;
    }
    $limit = checkPlanLimits($pdo, $tenantId, 'branches');
    if (!$limit['allowed']) {
        echo json_encode(["status" => "error", "message" => "Límite de sucursales alcanzado ({$limit['current']}/{$limit['limit']})"]);
        return;
    }

    $branchId = 'branch_' . bin2hex(random_bytes(8));
    $pdo->beginTransaction();
    try {
    $stmt = $pdo->prepare("INSERT INTO `branches` (`id`, `tenant_id`, `name`, `active`, `created_at`) VALUES (:id, :tenant_id, :name, 1, NOW())");
    $stmt->execute(['id' => $branchId, 'tenant_id' => $tenantId, 'name' => $branchName]);
    $stmt = $pdo->prepare("INSERT IGNORE INTO `user_branch_access` (`user_id`, `tenant_id`, `branch_id`) SELECT id, tenant_id, :bid FROM users WHERE tenant_id = :tid AND role = 'owner' AND active = 1");
    $stmt->execute(['bid' => $branchId, 'tid' => $tenantId]);
    initializeBranchTemplate($pdo, $tenantId, $branchId, $tenant['name']);
    $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    writeAuditLog($pdo, $authContext, 'saas.branch.create', 'branch', $branchId, ['tenant_id' => $tenantId]);
    echo json_encode(array_merge(["status" => "success"], loadSaasAdminData($pdo)));
}

function handle_saas_create_user(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'saas_admin');

    $tenantId = trim($input['tenant_id'] ?? '');
    $branchId = trim($input['branch_id'] ?? '');
    $name = trim($input['name'] ?? '');
    $email = strtolower(trim($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $role = trim($input['role'] ?? 'admin');
    $validRoles = ['owner', 'admin', 'cajero'];

    if ($tenantId === '' || $branchId === '' || $name === '' || $email === '' || $password === '') {
        echo json_encode(["status" => "error", "message" => "Completa todos los campos del usuario"]);
        return;
    }

    $limit = checkPlanLimits($pdo, $tenantId, 'users');
    if (!$limit['allowed']) {
        echo json_encode(["status" => "error", "message" => "Límite de usuarios alcanzado ({$limit['current']}/{$limit['limit']})"]);
        return;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(["status" => "error", "message" => "Email inválido"]);
        return;
    }

    if (strlen($password) < 8) {
        echo json_encode(["status" => "error", "message" => "La contraseña debe tener al menos 8 caracteres"]);
        return;
    }
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        echo json_encode(["status" => "error", "message" => "La contraseña debe incluir al menos una mayúscula, una minúscula y un número"]);
        return;
    }

    if (!in_array($role, $validRoles, true)) {
        $role = 'admin';
    }

    $branchCheck = $pdo->prepare("SELECT id FROM `branches` WHERE `id` = :bid AND `tenant_id` = :tid");
    $branchCheck->execute(['bid' => $branchId, 'tid' => $tenantId]);
    if (!$branchCheck->fetch()) {
        echo json_encode(["status" => "error", "message" => "Sucursal no válida para este tenant"]);
        return;
    }

    $emailCheck = $pdo->prepare("SELECT id FROM `users` WHERE email = :email LIMIT 1");
    $emailCheck->execute(['email' => $email]);
    if ($emailCheck->fetch()) {
        echo json_encode(["status" => "error", "message" => "Ya existe un usuario con ese email"]);
        return;
    }

    $userId = 'user_' . bin2hex(random_bytes(8));
    $stmt = $pdo->prepare("INSERT INTO `users` (`id`, `tenant_id`, `branch_id`, `name`, `email`, `password_hash`, `role`, `active`, `created_at`) VALUES (:id, :tenant_id, :branch_id, :name, :email, :password_hash, :role, 1, NOW())");
    $stmt->execute(['id' => $userId, 'tenant_id' => $tenantId, 'branch_id' => $branchId, 'name' => $name, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => $role]);
    if ($role === 'owner') {
        $stmt = $pdo->prepare("INSERT INTO `user_branch_access` (`user_id`, `tenant_id`, `branch_id`) SELECT :uid, :tid, id FROM `branches` WHERE tenant_id = :tid AND active = 1");
        $stmt->execute(['uid' => $userId, 'tid' => $tenantId]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO `user_branch_access` (`user_id`, `tenant_id`, `branch_id`) VALUES (:uid, :tid, :bid)");
        $stmt->execute(['uid' => $userId, 'tid' => $tenantId, 'bid' => $branchId]);
    }

    writeAuditLog($pdo, $authContext, 'saas.user.create', 'user', $userId, ['tenant_id' => $tenantId, 'branch_id' => $branchId, 'role' => $role]);
    echo json_encode(array_merge(["status" => "success"], loadSaasAdminData($pdo)));
}

function handle_saas_update_subscription(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'saas_admin');

    $tenantId = trim($input['tenant_id'] ?? '');
    $planCode = trim($input['plan_code'] ?? 'starter');
    $status = trim($input['status'] ?? 'trial');
    $validStatuses = ['trial', 'active', 'past_due', 'suspended', 'pending_payment'];
    $inputStartsAt = trim($input['starts_at'] ?? '');
    $inputEndsAt = trim($input['ends_at'] ?? '');

    if ($tenantId === '') {
        echo json_encode(["status" => "error", "message" => "Tenant requerido"]);
        return;
    }

    if (!in_array($status, $validStatuses, true)) {
        $status = 'trial';
    }

    $stmt = $pdo->prepare("SELECT id, plan_id, plan_snapshot, starts_at, ends_at FROM `tenant_subscriptions` WHERE `tenant_id` = :tenant_id LIMIT 1");
    $stmt->execute(['tenant_id' => $tenantId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    $oldSnapshot = $existing ? $existing['plan_snapshot'] : null;
    $oldPlanId = $existing ? $existing['plan_id'] : null;

    $planRow = $pdo->prepare("SELECT id, trial_days FROM `plans` WHERE `code` = :code LIMIT 1");
    $planRow->execute(['code' => $planCode]);
    $plan = $planRow->fetch(PDO::FETCH_ASSOC);
    $planId = $plan ? $plan['id'] : null;
    $trialDays = $plan ? (int)($plan['trial_days'] ?? 14) : 14;
    $newSnapshot = $planId ? createPlanSnapshot($pdo, $planId) : null;

    // Determine ends_at: use admin input if provided, else auto-calculate for trial
    if ($inputEndsAt !== '') {
        $endsAt = date('Y-m-d H:i:s', strtotime($inputEndsAt));
    } elseif ($status === 'trial') {
        $endsAt = date('Y-m-d H:i:s', strtotime("+{$trialDays} days"));
    } else {
        $endsAt = $existing ? $existing['ends_at'] : null;
    }

    // Determine starts_at: use admin input if provided, else keep existing or set now
    if ($inputStartsAt !== '') {
        $startsAt = date('Y-m-d H:i:s', strtotime($inputStartsAt));
    } elseif ($existing && $existing['starts_at']) {
        $startsAt = $existing['starts_at'];
    } else {
        $startsAt = date('Y-m-d H:i:s');
    }

    if ($existing) {
        $stmt = $pdo->prepare("UPDATE `tenant_subscriptions` SET `plan_code` = :plan_code, `status` = :status, `plan_id` = :pid, `plan_snapshot` = :snapshot, `starts_at` = :starts_at, `ends_at` = :ends_at WHERE `tenant_id` = :tenant_id");
        $stmt->execute(['plan_code' => $planCode, 'status' => $status, 'pid' => $planId, 'snapshot' => $newSnapshot, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'tenant_id' => $tenantId]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO `tenant_subscriptions` (`id`, `tenant_id`, `plan_code`, `plan_id`, `status`, `plan_snapshot`, `starts_at`, `ends_at`, `created_at`) VALUES (:id, :tenant_id, :plan_code, :pid, :status, :snapshot, :starts_at, :ends_at, NOW())");
        $stmt->execute(['id' => 'sub_' . bin2hex(random_bytes(8)), 'tenant_id' => $tenantId, 'plan_code' => $planCode, 'pid' => $planId, 'status' => $status, 'snapshot' => $newSnapshot, 'starts_at' => $startsAt, 'ends_at' => $endsAt]);
    }

    logPlanChange($pdo, $tenantId, $oldPlanId, $planId, $oldSnapshot, $newSnapshot, $authContext['user_id'] ?? null);

    writeAuditLog($pdo, $authContext, 'saas.subscription.update', 'tenant_subscription', $tenantId, ['plan_code' => $planCode, 'status' => $status]);
    echo json_encode(array_merge(["status" => "success"], loadSaasAdminData($pdo)));
}

function handle_saas_approve_payment(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'saas_admin');

    $tenantId = trim($input['tenant_id'] ?? '');
    $paymentNotes = trim($input['payment_notes'] ?? '');

    if ($tenantId === '') {
        echo json_encode(["status" => "error", "message" => "Tenant requerido"]);
        return;
    }

    $stmt = $pdo->prepare("SELECT id, plan_id, plan_code, status, plan_snapshot FROM `tenant_subscriptions` WHERE `tenant_id` = :tenant_id LIMIT 1");
    $stmt->execute(['tenant_id' => $tenantId]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sub) {
        echo json_encode(["status" => "error", "message" => "Suscripción no encontrada"]);
        return;
    }

    if ($sub['status'] !== 'pending_payment') {
        echo json_encode(["status" => "error", "message" => "Esta suscripción no está pendiente de pago"]);
        return;
    }

    $adminName = $authContext['user_name'] ?? 'Admin';
    $stmt = $pdo->prepare("UPDATE `tenant_subscriptions` SET `status` = 'active', `payment_notes` = :notes, `verified_by` = :admin, `verified_at` = NOW() WHERE `tenant_id` = :tenant_id");
    $stmt->execute(['notes' => $paymentNotes, 'admin' => $adminName, 'tenant_id' => $tenantId]);

    writeAuditLog($pdo, $authContext, 'saas.subscription.approve', 'tenant_subscription', $tenantId, ['plan_code' => $sub['plan_code'], 'verified_by' => $adminName]);
    echo json_encode(array_merge(["status" => "success", "message" => "Pago aprobado. Suscripción activada."], loadSaasAdminData($pdo)));
}

function handle_saas_reject_payment(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'saas_admin');

    $tenantId = trim($input['tenant_id'] ?? '');
    $reason = trim($input['reason'] ?? '');

    if ($tenantId === '') {
        echo json_encode(["status" => "error", "message" => "Tenant requerido"]);
        return;
    }

    $stmt = $pdo->prepare("SELECT id, plan_code, status FROM `tenant_subscriptions` WHERE `tenant_id` = :tenant_id LIMIT 1");
    $stmt->execute(['tenant_id' => $tenantId]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sub) {
        echo json_encode(["status" => "error", "message" => "Suscripción no encontrada"]);
        return;
    }

    if ($sub['status'] !== 'pending_payment') {
        echo json_encode(["status" => "error", "message" => "Esta suscripción no está pendiente de pago"]);
        return;
    }

    $adminName = $authContext['user_name'] ?? 'Admin';
    $stmt = $pdo->prepare("UPDATE `tenant_subscriptions` SET `status` = 'suspended', `payment_notes` = :notes, `verified_by` = :admin, `verified_at` = NOW() WHERE `tenant_id` = :tenant_id");
    $stmt->execute(['notes' => "RECHAZADO: {$reason}", 'admin' => $adminName, 'tenant_id' => $tenantId]);

    writeAuditLog($pdo, $authContext, 'saas.subscription.reject', 'tenant_subscription', $tenantId, ['plan_code' => $sub['plan_code'], 'reason' => $reason]);
    echo json_encode(array_merge(["status" => "success", "message" => "Pago rechazado."], loadSaasAdminData($pdo)));
}

function handle_saas_edit_tenant(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'saas_admin');
    $id = trim($input['id'] ?? '');
    $name = trim($input['name'] ?? '');
    $businessType = trim($input['business_type'] ?? 'restaurante');
    $planLevel = trim($input['plan_level'] ?? 'basico');
    $active = isset($input['active']) ? (int)$input['active'] : 1;

    if ($id === '' || $name === '') {
        echo json_encode(["status" => "error", "message" => "ID y nombre son requeridos"]);
        return;
    }

    $validBusinessTypes = ['restaurante', 'cafeteria', 'tienda', 'bar', 'otro'];
    if (!in_array($businessType, $validBusinessTypes, true)) $businessType = 'restaurante';
    $validPlanLevels = ['basico', 'premium', 'enterprise'];
    if (!in_array($planLevel, $validPlanLevels, true)) $planLevel = 'basico';

    $stmt = $pdo->prepare("UPDATE `tenants` SET `name` = :name, `business_type` = :business_type, `plan_level` = :plan_level, `active` = :active WHERE `id` = :id");
    $stmt->execute(['name' => $name, 'business_type' => $businessType, 'plan_level' => $planLevel, 'active' => $active, 'id' => $id]);

    writeAuditLog($pdo, $authContext, 'saas.tenant.update', 'tenant', $id);
    echo json_encode(array_merge(["status" => "success"], loadSaasAdminData($pdo)));
}

function handle_saas_delete_tenant(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'saas_admin');
    $id = trim($input['id'] ?? '');
    if ($id === '' || $id === 'tenant_legacy') {
        echo json_encode(["status" => "error", "message" => "No se puede eliminar este tenant"]);
        return;
    }
    $pdo->beginTransaction();
    try {
        $orphanTables = [
            'pedidos', 'caja_movimientos', 'caja_cierres_historico',
            'segundos', 'sopas', 'platos_extras', 'gaseosas', 'salsas',
            'menus', 'products', 'tables_config', 'categories',
            'config_precios', 'config_general', 'reservations', 'discounts',
            'audit_logs', 'stock_history', 'stock_daily_snapshot',
            'user_sessions', 'user_branch_access', 'plan_changes_log', 'tenant_subscriptions',
            'users', 'branches'
        ];
        foreach ($orphanTables as $table) {
            $stmt = $pdo->prepare("DELETE FROM `$table` WHERE `tenant_id` = :id");
            $stmt->execute(['id' => $id]);
        }
        $stmt = $pdo->prepare("DELETE FROM `tenants` WHERE `id` = :id");
        $stmt->execute(['id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    writeAuditLog($pdo, $authContext, 'saas.tenant.delete', 'tenant', $id);
    echo json_encode(array_merge(["status" => "success"], loadSaasAdminData($pdo)));
}

function handle_saas_edit_branch(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'saas_admin');
    $id = trim($input['id'] ?? '');
    $name = trim($input['name'] ?? '');
    $active = isset($input['active']) ? (int)$input['active'] : 1;
    if ($id === '' || $name === '') {
        echo json_encode(["status" => "error", "message" => "ID y nombre son requeridos"]);
        return;
    }
    $stmt = $pdo->prepare("UPDATE `branches` SET `name` = :name, `active` = :active WHERE `id` = :id");
    $stmt->execute(['name' => $name, 'active' => $active, 'id' => $id]);
    writeAuditLog($pdo, $authContext, 'saas.branch.update', 'branch', $id);
    echo json_encode(array_merge(["status" => "success"], loadSaasAdminData($pdo)));
}

function handle_saas_delete_branch(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'saas_admin');
    $id = trim($input['id'] ?? '');
    if ($id === '' || $id === 'branch_main') {
        echo json_encode(["status" => "error", "message" => "No se puede eliminar esta sucursal"]);
        return;
    }
    $pdo->beginTransaction();
    try {
        $orphanTables = [
            'pedidos', 'caja_movimientos', 'caja_cierres_historico',
            'segundos', 'sopas', 'platos_extras', 'gaseosas', 'salsas',
            'menus', 'products', 'tables_config', 'categories',
            'config_precios', 'config_general', 'reservations', 'discounts',
            'audit_logs', 'stock_history', 'stock_daily_snapshot',
            'user_sessions', 'user_branch_access', 'users'
        ];
        foreach ($orphanTables as $table) {
            $stmt = $pdo->prepare("DELETE FROM `$table` WHERE `branch_id` = :id AND `tenant_id` = (SELECT `tenant_id` FROM `branches` WHERE `id` = :id2)");
            $stmt->execute(['id' => $id, 'id2' => $id]);
        }
        $stmt = $pdo->prepare("DELETE FROM `branches` WHERE `id` = :id");
        $stmt->execute(['id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    writeAuditLog($pdo, $authContext, 'saas.branch.delete', 'branch', $id);
    echo json_encode(array_merge(["status" => "success"], loadSaasAdminData($pdo)));
}

function handle_saas_edit_user(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'saas_admin');
    $id = trim($input['id'] ?? '');
    $name = trim($input['name'] ?? '');
    $email = strtolower(trim($input['email'] ?? ''));
    $role = trim($input['role'] ?? 'cajero');
    $active = isset($input['active']) ? (int)$input['active'] : 1;
    $branchId = trim($input['branch_id'] ?? '');
    $password = $input['password'] ?? '';

    if ($id === '' || $name === '' || $email === '') {
        echo json_encode(["status" => "error", "message" => "ID, nombre y email son requeridos"]);
        return;
    }

    // Protect super_admin role from being changed
    $currentCheck = $pdo->prepare("SELECT role FROM `users` WHERE `id` = :id");
    $currentCheck->execute(['id' => $id]);
    $currentRow = $currentCheck->fetch(PDO::FETCH_ASSOC);
    if ($currentRow && $currentRow['role'] === 'super_admin' && $role !== 'super_admin') {
        echo json_encode(["status" => "error", "message" => "No se puede cambiar el rol de un super admin"]);
        return;
    }

    $validRoles = ['owner', 'admin', 'cajero', 'super_admin'];
    if (!in_array($role, $validRoles, true)) $role = 'cajero';

    if ($password !== '' && strlen($password) < 8) {
        echo json_encode(["status" => "error", "message" => "La contraseña debe tener al menos 8 caracteres"]);
        return;
    }

    $userRow = $pdo->prepare("SELECT tenant_id FROM `users` WHERE `id` = :id");
    $userRow->execute(['id' => $id]);
    $userData = $userRow->fetch(PDO::FETCH_ASSOC);
    if (!$userData) {
        echo json_encode(["status" => "error", "message" => "Usuario no encontrado"]);
        return;
    }
    if ($branchId !== '') {
        $branchCheck = $pdo->prepare("SELECT id FROM `branches` WHERE `id` = :bid AND `tenant_id` = :tid");
        $branchCheck->execute(['bid' => $branchId, 'tid' => $userData['tenant_id']]);
        if (!$branchCheck->fetch()) {
            echo json_encode(["status" => "error", "message" => "Sucursal no válida para este tenant"]);
            return;
        }
    }

    if ($password !== '') {
        $stmt = $pdo->prepare("UPDATE `users` SET `name` = :name, `email` = :email, `role` = :role, `active` = :active, `branch_id` = :branch_id, `password_hash` = :password_hash WHERE `id` = :id");
        $stmt->execute(['name' => $name, 'email' => $email, 'role' => $role, 'active' => $active, 'branch_id' => $branchId, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $id]);
    } else {
        $stmt = $pdo->prepare("UPDATE `users` SET `name` = :name, `email` = :email, `role` = :role, `active` = :active, `branch_id` = :branch_id WHERE `id` = :id");
        $stmt->execute(['name' => $name, 'email' => $email, 'role' => $role, 'active' => $active, 'branch_id' => $branchId, 'id' => $id]);
    }
    if ($branchId !== '') {
        $accessStmt = $pdo->prepare("INSERT IGNORE INTO `user_branch_access` (`user_id`, `tenant_id`, `branch_id`) VALUES (:uid, :tid, :bid)");
        $accessStmt->execute(['uid' => $id, 'tid' => $userData['tenant_id'], 'bid' => $branchId]);
    }
    if ($role === 'owner') {
        $accessStmt = $pdo->prepare("INSERT IGNORE INTO `user_branch_access` (`user_id`, `tenant_id`, `branch_id`) SELECT :uid, :tid, id FROM `branches` WHERE tenant_id = :tid AND active = 1");
        $accessStmt->execute(['uid' => $id, 'tid' => $userData['tenant_id']]);
    }
    writeAuditLog($pdo, $authContext, 'saas.user.update', 'user', $id);
    echo json_encode(array_merge(["status" => "success"], loadSaasAdminData($pdo)));
}

function handle_saas_delete_user(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'saas_admin');
    $id = trim($input['id'] ?? '');
    if ($id === '' || $id === 'user_owner_legacy') {
        echo json_encode(["status" => "error", "message" => "No se puede eliminar este usuario"]);
        return;
    }
    $check = $pdo->prepare("SELECT id, role FROM `users` WHERE id = :id");
    $check->execute(['id' => $id]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        echo json_encode(["status" => "error", "message" => "Usuario no encontrado"]);
        return;
    }
    if ($existing['role'] === 'super_admin') {
        echo json_encode(["status" => "error", "message" => "No se puede eliminar a un super admin"]);
        return;
    }
    $stmt = $pdo->prepare("UPDATE `user_sessions` SET `ended_at` = NOW() WHERE `user_id` = :id AND `ended_at` IS NULL");
    $stmt->execute(['id' => $id]);
    $stmt = $pdo->prepare("UPDATE `users` SET `active` = 0 WHERE `id` = :id");
    $stmt->execute(['id' => $id]);
    writeAuditLog($pdo, $authContext, 'saas.user.delete', 'user', $id);
    echo json_encode(array_merge(["status" => "success"], loadSaasAdminData($pdo)));
}

// ==========================================================================
// TENANT-SCOPED USER MANAGEMENT
// ==========================================================================

function handle_get_tenant_users(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    $tenantId = $authContext['tenant_id'];

    $branches = $pdo->prepare("SELECT id, name, active FROM `branches` WHERE `tenant_id` = :tid ORDER BY name");
    $branches->execute(['tid' => $tenantId]);
    $branchList = $branches->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT u.id, u.name, u.email, u.role, u.active, u.created_at, b.name AS branch_name FROM `users` u INNER JOIN `branches` b ON b.id = u.branch_id WHERE u.tenant_id = :tid AND u.role != 'super_admin' ORDER BY u.created_at DESC");
    $stmt->execute(['tid' => $tenantId]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as &$u) { $u['active'] = (bool)$u['active']; }

    echo json_encode(["status" => "success", "branches" => $branchList, "users" => $users]);
}

function handle_create_tenant_user(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    $tenantId = $authContext['tenant_id'];

    $name = trim($input['name'] ?? '');
    $email = strtolower(trim($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $role = trim($input['role'] ?? 'cajero');
    $branchId = trim($input['branch_id'] ?? '');

    if ($name === '' || $email === '' || $password === '' || $branchId === '') {
        echo json_encode(["status" => "error", "message" => "Completa todos los campos"]);
        return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(["status" => "error", "message" => "Email inválido"]);
        return;
    }
    if (strlen($password) < 8) {
        echo json_encode(["status" => "error", "message" => "La contraseña debe tener al menos 8 caracteres"]);
        return;
    }
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        echo json_encode(["status" => "error", "message" => "La contraseña debe incluir al menos una mayúscula, una minúscula y un número"]);
        return;
    }
    $validRoles = ['admin', 'cajero'];
    if (!in_array($role, $validRoles, true)) $role = 'cajero';

    $stmt = $pdo->prepare("SELECT id FROM `branches` WHERE id = :bid AND tenant_id = :tid");
    $stmt->execute(['bid' => $branchId, 'tid' => $tenantId]);
    if (!$stmt->fetch()) {
        echo json_encode(["status" => "error", "message" => "Sucursal no válida"]);
        return;
    }

    $stmt = $pdo->prepare("SELECT id FROM `users` WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => $email]);
    if ($stmt->fetch()) {
        echo json_encode(["status" => "error", "message" => "Ya existe un usuario con ese email"]);
        return;
    }

    $userId = 'usr_' . bin2hex(random_bytes(10));
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO `users` (id, tenant_id, branch_id, name, email, password_hash, role, active) VALUES (:id, :tid, :bid, :name, :email, :pass, :role, 1)");
    $stmt->execute(['id' => $userId, 'tid' => $tenantId, 'bid' => $branchId, 'name' => $name, 'email' => $email, 'pass' => $hash, 'role' => $role]);
    writeAuditLog($pdo, $authContext, 'tenant.user.create', 'user', $userId);
    echo json_encode(["status" => "success", "message" => "Usuario creado"]);
}

function handle_edit_tenant_user(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    $tenantId = $authContext['tenant_id'];
    $id = trim($input['id'] ?? '');
    $name = trim($input['name'] ?? '');
    $email = strtolower(trim($input['email'] ?? ''));
    $role = trim($input['role'] ?? '');
    $branchId = trim($input['branch_id'] ?? '');
    $password = (string)($input['password'] ?? '');
    $active = isset($input['active']) ? (int)$input['active'] : 1;

    if ($id === '' || $name === '' || $email === '') {
        echo json_encode(["status" => "error", "message" => "Completa todos los campos"]);
        return;
    }

    $stmt = $pdo->prepare("SELECT id, role AS current_role FROM `users` WHERE id = :id AND tenant_id = :tid");
    $stmt->execute(['id' => $id, 'tid' => $tenantId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        echo json_encode(["status" => "error", "message" => "Usuario no encontrado"]);
        return;
    }

    if ($existing['current_role'] === 'super_admin') {
        echo json_encode(["status" => "error", "message" => "No se puede editar un super admin desde este panel"]);
        return;
    }

    if ($existing['current_role'] === 'owner' && $active === 0) {
        echo json_encode(["status" => "error", "message" => "No puedes desactivar tu propia cuenta"]);
        return;
    }

    $validRoles = ['admin', 'cajero'];
    if ($role !== '' && !in_array($role, $validRoles, true)) $role = 'cajero';

    if ($password !== '' && strlen($password) < 8) {
        echo json_encode(["status" => "error", "message" => "La contraseña debe tener al menos 8 caracteres"]);
        return;
    }

    if ($branchId !== '') {
        $stmt = $pdo->prepare("SELECT id FROM `branches` WHERE id = :bid AND tenant_id = :tid");
        $stmt->execute(['bid' => $branchId, 'tid' => $tenantId]);
        if (!$stmt->fetch()) {
            echo json_encode(["status" => "error", "message" => "Sucursal no válida"]);
            return;
        }
    }

    $stmt = $pdo->prepare("SELECT id FROM `users` WHERE email = :email AND tenant_id = :tid AND id != :id LIMIT 1");
    $stmt->execute(['email' => $email, 'tid' => $tenantId, 'id' => $id]);
    if ($stmt->fetch()) {
        echo json_encode(["status" => "error", "message" => "Ya existe otro usuario con ese email"]);
        return;
    }

    $sql = "UPDATE `users` SET name = :name, email = :email, active = :active";
    $params = ['name' => $name, 'email' => $email, 'active' => $active, 'id' => $id];
    if ($role !== '') {
        $sql .= ", role = :role";
        $params['role'] = $role;
    }
    if ($branchId !== '') {
        $sql .= ", branch_id = :bid";
        $params['bid'] = $branchId;
    }
    if ($password !== '') {
        $sql .= ", password_hash = :pass";
        $params['pass'] = password_hash($password, PASSWORD_DEFAULT);
    }
    $sql .= " WHERE id = :id AND tenant_id = :tid";
    $params['tid'] = $tenantId;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($branchId !== '') {
        $accessStmt = $pdo->prepare("INSERT IGNORE INTO `user_branch_access` (`user_id`, `tenant_id`, `branch_id`) VALUES (:uid, :tid, :bid)");
        $accessStmt->execute(['uid' => $id, 'tid' => $tenantId, 'bid' => $branchId]);
    }
    if ($role === 'owner') {
        $accessStmt = $pdo->prepare("INSERT IGNORE INTO `user_branch_access` (`user_id`, `tenant_id`, `branch_id`) SELECT :uid, :tid, id FROM `branches` WHERE tenant_id = :tid AND active = 1");
        $accessStmt->execute(['uid' => $id, 'tid' => $tenantId]);
    }
    writeAuditLog($pdo, $authContext, 'tenant.user.update', 'user', $id);
    echo json_encode(["status" => "success", "message" => "Usuario actualizado"]);
}

function handle_delete_tenant_user(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    $tenantId = $authContext['tenant_id'];
    $id = trim($input['id'] ?? '');

    if ($id === '') {
        echo json_encode(["status" => "error", "message" => "ID requerido"]);
        return;
    }

    $stmt = $pdo->prepare("SELECT id, role FROM `users` WHERE id = :id AND tenant_id = :tid");
    $stmt->execute(['id' => $id, 'tid' => $tenantId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        echo json_encode(["status" => "error", "message" => "Usuario no encontrado"]);
        return;
    }
    if ($existing['role'] === 'owner') {
        echo json_encode(["status" => "error", "message" => "No se puede eliminar al owner"]);
        return;
    }
    if ($existing['role'] === 'super_admin') {
        echo json_encode(["status" => "error", "message" => "No se puede eliminar un super admin"]);
        return;
    }

    $stmt = $pdo->prepare("UPDATE `user_sessions` SET `ended_at` = NOW() WHERE `user_id` = :id AND `tenant_id` = :tid AND `ended_at` IS NULL");
    $stmt->execute(['id' => $id, 'tid' => $tenantId]);
    $stmt = $pdo->prepare("UPDATE `users` SET `active` = 0 WHERE `id` = :id AND `tenant_id` = :tid");
    $stmt->execute(['id' => $id, 'tid' => $tenantId]);
    writeAuditLog($pdo, $authContext, 'tenant.user.delete', 'user', $id);
    echo json_encode(["status" => "success", "message" => "Usuario eliminado"]);
}

// ==========================================================================
// PLANS CRUD
// ==========================================================================

function handle_saas_get_plans(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'saas_admin');
    $plans = $pdo->query("SELECT * FROM `plans` ORDER BY `price_monthly` ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($plans as &$plan) {
        $plan['features'] = json_decode($plan['features'] ?? '{}', true);
        $plan['active'] = (bool)$plan['active'];
    }
    echo json_encode(["status" => "success", "plans" => $plans]);
}

function handle_saas_create_plan(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'saas_admin');

    $code = strtolower(trim($input['code'] ?? ''));
    $name = trim($input['name'] ?? '');
    $description = trim($input['description'] ?? '');
    $priceMonthly = (float)($input['price_monthly'] ?? 0);
    $priceYearly = (float)($input['price_yearly'] ?? 0);
    $maxBranches = (int)($input['max_branches'] ?? 1);
    $maxUsers = (int)($input['max_users'] ?? 5);
    $maxProducts = (int)($input['max_products'] ?? 50);
    $features = $input['features'] ?? [];
    $trialDays = (int)($input['trial_days'] ?? 14);

    if ($code === '' || $name === '') {
        echo json_encode(["status" => "error", "message" => "Código y nombre son requeridos"]);
        return;
    }

    if (!preg_match('/^[a-z0-9_]{2,30}$/', $code)) {
        echo json_encode(["status" => "error", "message" => "El código debe tener solo minúsculas, números y guiones bajos"]);
        return;
    }

    $exists = $pdo->prepare("SELECT id FROM `plans` WHERE `code` = :code LIMIT 1");
    $exists->execute(['code' => $code]);
    if ($exists->fetch()) {
        echo json_encode(["status" => "error", "message" => "Ya existe un plan con ese código"]);
        return;
    }

    $planId = 'plan_' . bin2hex(random_bytes(8));
    $stmt = $pdo->prepare("INSERT INTO `plans` (`id`, `code`, `name`, `description`, `price_monthly`, `price_yearly`, `max_branches`, `max_users`, `max_products`, `features`, `trial_days`) VALUES (:id, :code, :name, :description, :price_monthly, :price_yearly, :max_branches, :max_users, :max_products, :features, :trial_days)");
    $stmt->execute([
        'id' => $planId,
        'code' => $code,
        'name' => $name,
        'description' => $description,
        'price_monthly' => $priceMonthly,
        'price_yearly' => $priceYearly,
        'max_branches' => $maxBranches,
        'max_users' => $maxUsers,
        'max_products' => $maxProducts,
        'features' => json_encode($features),
        'trial_days' => $trialDays
    ]);

    writeAuditLog($pdo, $authContext, 'saas.plan.create', 'plan', $planId, ['code' => $code, 'name' => $name]);
    echo json_encode(array_merge(["status" => "success", "message" => "Plan creado"], loadSaasAdminData($pdo)));
}

function handle_saas_update_plan(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'saas_admin');

    $id = trim($input['id'] ?? '');
    $name = trim($input['name'] ?? '');
    $description = trim($input['description'] ?? '');
    $priceMonthly = isset($input['price_monthly']) ? (float)$input['price_monthly'] : null;
    $priceYearly = isset($input['price_yearly']) ? (float)$input['price_yearly'] : null;
    $maxBranches = isset($input['max_branches']) ? (int)$input['max_branches'] : null;
    $maxUsers = isset($input['max_users']) ? (int)$input['max_users'] : null;
    $maxProducts = isset($input['max_products']) ? (int)$input['max_products'] : null;
    $features = $input['features'] ?? null;
    $trialDays = isset($input['trial_days']) ? (int)$input['trial_days'] : null;
    $active = isset($input['active']) ? (int)$input['active'] : null;

    if ($id === '' || $name === '') {
        echo json_encode(["status" => "error", "message" => "ID y nombre son requeridos"]);
        return;
    }

    $sql = "UPDATE `plans` SET name = :name, description = :description";
    $params = ['name' => $name, 'description' => $description, 'id' => $id];

    if ($priceMonthly !== null) { $sql .= ", price_monthly = :price_monthly"; $params['price_monthly'] = $priceMonthly; }
    if ($priceYearly !== null) { $sql .= ", price_yearly = :price_yearly"; $params['price_yearly'] = $priceYearly; }
    if ($maxBranches !== null) { $sql .= ", max_branches = :max_branches"; $params['max_branches'] = $maxBranches; }
    if ($maxUsers !== null) { $sql .= ", max_users = :max_users"; $params['max_users'] = $maxUsers; }
    if ($maxProducts !== null) { $sql .= ", max_products = :max_products"; $params['max_products'] = $maxProducts; }
    if ($features !== null) { $sql .= ", features = :features"; $params['features'] = json_encode($features); }
    if ($trialDays !== null) { $sql .= ", trial_days = :trial_days"; $params['trial_days'] = $trialDays; }
    if ($active !== null) { $sql .= ", active = :active"; $params['active'] = $active; }

    $sql .= " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    writeAuditLog($pdo, $authContext, 'saas.plan.update', 'plan', $id, ['name' => $name]);
    echo json_encode(array_merge(["status" => "success", "message" => "Plan actualizado"], loadSaasAdminData($pdo)));
}

function handle_saas_delete_plan(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'saas_admin');
    $id = trim($input['id'] ?? '');

    if ($id === '') {
        echo json_encode(["status" => "error", "message" => "ID requerido"]);
        return;
    }

    $reserved = ['plan_starter', 'plan_pro', 'plan_enterprise'];
    if (in_array($id, $reserved, true)) {
        echo json_encode(["status" => "error", "message" => "No se pueden eliminar los planes del sistema"]);
        return;
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `tenant_subscriptions` WHERE `plan_id` = :id AND `status` IN ('trial', 'active', 'past_due')");
    $countStmt->execute(['id' => $id]);
    $activeCount = (int)$countStmt->fetchColumn();
    if ($activeCount > 0) {
        echo json_encode(["status" => "error", "message" => "No se puede eliminar: {$activeCount} tenant(s) suscrito(s) a este plan. Migra primero a otro plan."]);
        return;
    }

    $stmt = $pdo->prepare("UPDATE `tenant_subscriptions` SET `plan_id` = NULL WHERE `plan_id` = :id");
    $stmt->execute(['id' => $id]);
    $stmt = $pdo->prepare("DELETE FROM `plans` WHERE `id` = :id");
    $stmt->execute(['id' => $id]);

    writeAuditLog($pdo, $authContext, 'saas.plan.delete', 'plan', $id);
    echo json_encode(array_merge(["status" => "success", "message" => "Plan eliminado"], loadSaasAdminData($pdo)));
}

function handle_public_register(PDO $pdo, ?array $authContext, array $input): void {
    $ownerEmail = strtolower(trim($input['email'] ?? ''));
    $rateLimitResult = checkRateLimit('register:' . ($ownerEmail ?: 'unknown'));
    if (!empty($rateLimitResult['locked'])) {
        http_response_code(429);
        echo json_encode(["status" => "error", "message" => "Demasiados intentos. Intente nuevamente en {$rateLimitResult['retry_after']} segundos."]);
        return;
    }

    $nombreRestaurante = trim($input['nombre_restaurante'] ?? '');
    $ownerName = trim($input['name'] ?? '');
    $ownerPassword = (string)($input['password'] ?? '');
    $pais = trim($input['pais'] ?? 'Bolivia');
    $requestedPlanCode = trim($input['plan_code'] ?? 'starter');

    if ($nombreRestaurante === '' || $ownerName === '' || $ownerEmail === '' || $ownerPassword === '') {
        recordFailedAttempt('register:' . ($ownerEmail ?: 'unknown'));
        echo json_encode(["status" => "error", "message" => "Todos los campos son requeridos"]);
        return;
    }

    if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
        recordFailedAttempt('register:' . $ownerEmail);
        echo json_encode(["status" => "error", "message" => "Email inválido"]);
        return;
    }

    if (strlen($ownerPassword) < 8) {
        recordFailedAttempt('register:' . $ownerEmail);
        echo json_encode(["status" => "error", "message" => "La contraseña debe tener al menos 8 caracteres"]);
        return;
    }

    $checkStmt = $pdo->prepare("SELECT id FROM `users` WHERE `email` = :email LIMIT 1");
    $checkStmt->execute(['email' => $ownerEmail]);
    if ($checkStmt->fetch()) {
        recordFailedAttempt('register:' . $ownerEmail);
        echo json_encode(["status" => "error", "message" => "Este email ya está registrado"]);
        return;
    }

    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($nombreRestaurante));
    $slug = trim($slug, '-');
    if (strlen($slug) < 3) $slug = 'negocio-' . bin2hex(random_bytes(3));

    $slugCheck = $pdo->prepare("SELECT id FROM `tenants` WHERE `slug` = :slug LIMIT 1");
    $slugCheck->execute(['slug' => $slug]);
    if ($slugCheck->fetch()) {
        $slug = $slug . '-' . bin2hex(random_bytes(3));
    }

    $tenantId = 'tenant_' . bin2hex(random_bytes(8));
    $branchId = 'branch_' . bin2hex(random_bytes(8));
    $userId = 'user_' . bin2hex(random_bytes(8));
    $subscriptionId = 'sub_' . bin2hex(random_bytes(8));

    $planRow = $pdo->prepare("SELECT id, trial_days FROM `plans` WHERE `code` = :code AND `active` = 1 LIMIT 1");
    $planRow->execute(['code' => $requestedPlanCode]);
    $plan = $planRow->fetch(PDO::FETCH_ASSOC);
    if (!$plan) {
        $planRow2 = $pdo->prepare("SELECT id, trial_days FROM `plans` WHERE `code` = 'starter' AND `active` = 1 LIMIT 1");
        $planRow2->execute();
        $plan = $planRow2->fetch(PDO::FETCH_ASSOC);
        $requestedPlanCode = 'starter';
    }
    $planId = $plan ? $plan['id'] : 'plan_starter';
    $trialDays = $plan ? (int)($plan['trial_days'] ?? 14) : 14;

    $isPaidPlan = ($requestedPlanCode !== 'starter');
    $subscriptionStatus = $isPaidPlan ? 'pending_payment' : 'trial';
    $endsAt = $isPaidPlan ? null : date('Y-m-d H:i:s', strtotime("+{$trialDays} days"));

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("INSERT INTO `tenants` (`id`, `slug`, `name`, `business_type`, `plan_level`, `active`, `created_at`) VALUES (:id, :slug, :name, 'restaurante', 'basico', 1, NOW())");
        $stmt->execute(['id' => $tenantId, 'slug' => $slug, 'name' => $nombreRestaurante]);

        $stmt = $pdo->prepare("INSERT INTO `branches` (`id`, `tenant_id`, `name`, `active`, `created_at`) VALUES (:id, :tenant_id, :name, 1, NOW())");
        $stmt->execute(['id' => $branchId, 'tenant_id' => $tenantId, 'name' => 'Sucursal Principal']);

        $stmt = $pdo->prepare("INSERT INTO `users` (`id`, `tenant_id`, `branch_id`, `name`, `email`, `password_hash`, `role`, `active`, `created_at`) VALUES (:id, :tenant_id, :branch_id, :name, :email, :password_hash, 'owner', 1, NOW())");
        $stmt->execute(['id' => $userId, 'tenant_id' => $tenantId, 'branch_id' => $branchId, 'name' => $ownerName, 'email' => $ownerEmail, 'password_hash' => password_hash($ownerPassword, PASSWORD_DEFAULT)]);

        $snapshot = createPlanSnapshot($pdo, $planId);
        $stmt = $pdo->prepare("INSERT INTO `tenant_subscriptions` (`id`, `tenant_id`, `plan_code`, `plan_id`, `status`, `plan_snapshot`, `starts_at`, `ends_at`, `created_at`) VALUES (:id, :tenant_id, :plan_code, :plan_id, :status, :snapshot, NOW(), :ends_at, NOW())");
        $stmt->execute(['id' => $subscriptionId, 'tenant_id' => $tenantId, 'plan_code' => $requestedPlanCode, 'plan_id' => $planId, 'status' => $subscriptionStatus, 'snapshot' => $snapshot, 'ends_at' => $endsAt]);

        $stmt = $pdo->prepare("INSERT INTO `user_branch_access` (`user_id`, `tenant_id`, `branch_id`, `created_at`) VALUES (:user_id, :tenant_id, :branch_id, NOW())");
        $stmt->execute(['user_id' => $userId, 'tenant_id' => $tenantId, 'branch_id' => $branchId]);
        initializeBranchTemplate($pdo, $tenantId, $branchId, $nombreRestaurante);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo json_encode(["status" => "error", "message" => "Error al crear la cuenta"]);
        return;
    }

    if ($isPaidPlan) {
        clearRateLimit('register:' . $ownerEmail);
        echo json_encode([
            "status" => "success",
            "message" => "Cuenta creada. Tu plan {$requestedPlanCode} está pendiente de verificación de pago.",
            "subscription_status" => "pending_payment",
            "plan_code" => $requestedPlanCode
        ]);
        return;
    }

    $stmt = $pdo->prepare("SELECT id FROM `users` WHERE `email` = :email AND `tenant_id` = :tid LIMIT 1");
    $stmt->execute(['email' => $ownerEmail, 'tid' => $tenantId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $sessionId = 'ses_' . bin2hex(random_bytes(12));
    $stmt = $pdo->prepare("INSERT INTO `user_sessions` (`id`, `user_id`, `tenant_id`, `branch_id`, `started_at`, `last_seen_at`, `ip_address`, `user_agent`) VALUES (:id, :user_id, :tenant_id, :branch_id, NOW(), NOW(), :ip, :ua)");
    $stmt->execute([
        'id' => $sessionId,
        'user_id' => $userId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        'ua' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ]);

    $_SESSION['restocloud_session_id'] = $sessionId;

    clearRateLimit('register:' . $ownerEmail);
    echo json_encode([
        "status" => "success",
        "message" => "Cuenta creada. Trial de {$trialDays} días activo.",
        "redirect" => true
    ]);
}
