<?php

// Integration check: all writes are rolled back before the process exits.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../backend/api/helpers.php';
require_once __DIR__ . '/../backend/api/receivables.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$suffix = bin2hex(random_bytes(6));
$tenantId = 'it_tenant_' . $suffix;
$branchA = 'it_branch_a_' . $suffix;
$branchB = 'it_branch_b_' . $suffix;
$customerId = 'it_customer_' . $suffix;

$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO `tenants` (id, slug, name, active) VALUES (?, ?, ?, 1)")
        ->execute([$tenantId, 'it-' . $suffix, 'Integration tenant']);
    $pdo->prepare("INSERT INTO `branches` (id, tenant_id, name, active) VALUES (?, ?, ?, 1), (?, ?, ?, 1)")
        ->execute([$branchA, $tenantId, 'Branch A', $branchB, $tenantId, 'Branch B']);
    $pdo->prepare("INSERT INTO `clientes` (id, name, tenant_id, branch_id, active) VALUES (?, ?, ?, ?, 1)")
        ->execute([$customerId, 'Integration customer', $tenantId, $branchA]);

    $contextA = ['tenant_id' => $tenantId, 'branch_id' => $branchA];
    $contextB = ['tenant_id' => $tenantId, 'branch_id' => $branchB];
    $assert(findTenantCustomer($pdo, $contextA, $customerId) !== null, 'La sucursal propietaria no pudo leer el cliente.');
    $assert(findTenantCustomer($pdo, $contextB, $customerId) === null, 'Una sucursal pudo leer un cliente ajeno.');

    $pdo->rollBack();
    echo "Isolation integration test: OK\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "Isolation integration test: FAIL - " . $e->getMessage() . "\n");
    exit(1);
}
