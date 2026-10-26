<?php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/api/auth.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$suffix = bin2hex(random_bytes(6));
$sourceTenant = 'ts_source_' . $suffix;
$targetTenant = 'ts_target_' . $suffix;
$inactiveTenant = 'ts_inactive_' . $suffix;
$sourceBranch = 'ts_source_branch_' . $suffix;
$targetBranch = 'ts_target_branch_' . $suffix;
$userId = 'ts_user_' . $suffix;
$sessionId = 'ts_session_' . $suffix;

$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO `tenants` (id, slug, name, active) VALUES (?, ?, ?, ?), (?, ?, ?, ?), (?, ?, ?, ?)")
        ->execute([
            $sourceTenant, 'ts-source-' . $suffix, 'Tenant source', 1,
            $targetTenant, 'ts-target-' . $suffix, 'Tenant target', 1,
            $inactiveTenant, 'ts-inactive-' . $suffix, 'Tenant inactive', 0
        ]);
    $pdo->prepare("INSERT INTO `branches` (id, tenant_id, name, active) VALUES (?, ?, ?, 1), (?, ?, ?, 1)")
        ->execute([$sourceBranch, $sourceTenant, 'Source branch', $targetBranch, $targetTenant, 'Target branch']);
    $pdo->prepare("INSERT INTO `users` (id, tenant_id, branch_id, name, email, password_hash, role, active) VALUES (?, ?, ?, ?, ?, ?, 'super_admin', 1)")
        ->execute([$userId, $sourceTenant, $sourceBranch, 'Tenant switch test', 'ts-' . $suffix . '@example.test', password_hash('test-password', PASSWORD_DEFAULT)]);
    $pdo->prepare("INSERT INTO `user_sessions` (id, user_id, tenant_id, branch_id, started_at, last_seen_at) VALUES (?, ?, ?, ?, NOW(), NOW())")
        ->execute([$sessionId, $userId, $sourceTenant, $sourceBranch]);

    ensureSessionStarted();
    $_SESSION['restocloud_session_id'] = $sessionId;
    $context = resolveAuthContext($pdo);
    $assert($context && $context['tenant_id'] === $sourceTenant, 'No se pudo crear el contexto inicial.');

    $available = getAvailableTenants($pdo, $context);
    $availableIds = array_column($available, 'id');
    $assert(in_array($targetTenant, $availableIds, true), 'El tenant activo no aparece disponible.');
    $assert(!in_array($inactiveTenant, $availableIds, true), 'El tenant inactivo aparece disponible.');

    ob_start();
    handle_switch_tenant($pdo, $context, ['tenant_id' => $targetTenant]);
    $switchResponse = json_decode(ob_get_clean(), true);
    $assert(($switchResponse['status'] ?? '') === 'success', 'No se pudo cambiar al tenant activo.');
    $assert(($switchResponse['tenant']['id'] ?? '') === $targetTenant, 'La respuesta no contiene el tenant seleccionado.');
    $nextContext = resolveAuthContext($pdo);
    $assert($nextContext && $nextContext['tenant_id'] === $targetTenant, 'La sesión no cambió al tenant seleccionado.');
    $assert($nextContext['branch_id'] === $targetBranch, 'La sesión no seleccionó una sucursal del tenant destino.');

    ob_start();
    handle_switch_tenant($pdo, $nextContext, ['tenant_id' => $inactiveTenant]);
    $inactiveResponse = json_decode(ob_get_clean(), true);
    $assert(($inactiveResponse['status'] ?? '') === 'error', 'Se permitió seleccionar un tenant inactivo.');

    $pdo->rollBack();
    echo "Tenant switch integration test: OK\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "Tenant switch integration test: FAIL - " . $e->getMessage() . "\n");
    exit(1);
}
