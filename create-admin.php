<?php
/**
 * Create SaaS Admin User
 * Run: php create-admin.php
 */

require_once __DIR__ . '/db.php';

$email = 'admin@restocloud.com';
$role = 'super_admin';
$name = 'SaaS Admin';
$password = bin2hex(random_bytes(8));

try {
    $stmt = $pdo->prepare("SELECT id FROM `users` WHERE `email` = :email LIMIT 1");
    $stmt->execute(['email' => $email]);
    if ($stmt->fetch()) {
        echo "User $email already exists.\n";
        exit(0);
    }
    
    $stmt = $pdo->prepare("SELECT id FROM `tenants` LIMIT 1");
    $stmt->execute();
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tenant) {
        $tenantId = 'tenant_' . bin2hex(random_bytes(12));
        $branchId = 'branch_' . bin2hex(random_bytes(12));
        
        $pdo->prepare("INSERT INTO `tenants` (`id`, `slug`, `name`, `active`) VALUES (:id, :slug, :name, 1)")
            ->execute(['id' => $tenantId, 'slug' => 'saas-admin', 'name' => 'SaaS Administration']);
        
        $pdo->prepare("INSERT INTO `branches` (`id`, `tenant_id`, `name`, `active`) VALUES (:id, :tid, :name, 1)")
            ->execute(['id' => $branchId, 'tid' => $tenantId, 'name' => 'Main Branch']);
    } else {
        $stmt = $pdo->prepare("SELECT id FROM `branches` WHERE `tenant_id` = :tid LIMIT 1");
        $stmt->execute(['tid' => $tenant['id']]);
        $branch = $stmt->fetch(PDO::FETCH_ASSOC);
        $tenantId = $tenant['id'];
        $branchId = $branch['id'];
    }
    
    $userId = 'user_' . bin2hex(random_bytes(12));
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    
    $pdo->prepare("
        INSERT INTO `users` (`id`, `tenant_id`, `branch_id`, `name`, `email`, `password_hash`, `role`, `active`)
        VALUES (:id, :tid, :bid, :name, :email, :hash, :role, 1)
    ")->execute([
        'id' => $userId,
        'tid' => $tenantId,
        'bid' => $branchId,
        'name' => $name,
        'email' => $email,
        'hash' => $passwordHash,
        'role' => $role
    ]);
    
    echo "Admin user created!\n";
    echo "Email: $email\n";
    echo "Password: $password\n";
    
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
