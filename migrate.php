#!/usr/bin/env php
<?php
/**
 * RestoCloud Migration Script
 * Run manually: php migrate.php
 * Or on deploy: php migrate.php --force
 *
 * In development, db.php auto-runs migrations when RUN_MIGRATIONS=1 (or APP_ENV=development).
 * In production, run this script on each deploy instead.
 */

// Auto-detect environment for CLI (migrate.php runs from terminal)
// Check file paths for InfinityFree indicators
$cwd = strtolower(getcwd());
$docRoot = strtolower($_SERVER['DOCUMENT_ROOT'] ?? '');
$checkPath = $cwd . ' ' . $docRoot . ' ' . strtolower(php_uname());
$isProduction = (strpos($checkPath, 'infinityfree') !== false) || (strpos($checkPath, 'epizy') !== false);

$envFile = $isProduction ? __DIR__ . '/.env.production' : __DIR__ . '/.env.local';

if (!is_file($envFile)) {
    $envFile = __DIR__ . '/.env';
}

if (is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        $value = trim($value, '"\'');
        if (!getenv($key)) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$db_name = getenv('DB_NAME') ?: 'pos_pension';

$force = in_array('--force', $argv ?? []);

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db_name`");

    // Ensure _migrations table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `_migrations` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(255) NOT NULL UNIQUE,
        `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $appliedMigrations = $pdo->query("SELECT `name` FROM `_migrations`")->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $migrations = include __DIR__ . '/db_migrations.php';

    $applied = 0;
    $skipped = 0;
    foreach ($migrations as $name => $sql) {
        if (in_array($name, $appliedMigrations) && !$force) {
            $skipped++;
            continue;
        }
        try {
            $pdo->exec($sql);
            $pdo->prepare("INSERT INTO `_migrations` (`name`, `applied_at`) VALUES (:name, NOW())")->execute(['name' => $name]);
            echo "  [OK] $name\n";
            $applied++;
        } catch (Throwable $e) {
            echo "  [SKIP] $name: " . $e->getMessage() . "\n";
        }
    }

    // Seed legacy tenant
    $legacyTenantId = 'tenant_legacy';
    $legacyBranchId = 'branch_main';
    $legacyUserId = 'user_owner_legacy';
    $legacyUserEmail = 'owner@legacy.restocloud.local';
    $legacyPasswordHash = password_hash('admin12345', PASSWORD_DEFAULT);

    $pdo->prepare("INSERT IGNORE INTO `tenants` (`id`, `slug`, `name`, `business_type`, `plan_level`) VALUES (:id, :slug, :name, 'restaurante', 'premium')")->execute(['id' => $legacyTenantId, 'slug' => 'legacy', 'name' => 'Legacy RestoCloud']);
    $pdo->prepare("INSERT IGNORE INTO `branches` (`id`, `tenant_id`, `name`) VALUES (:id, :tenant_id, :name)")->execute(['id' => $legacyBranchId, 'tenant_id' => $legacyTenantId, 'name' => 'Sucursal Principal']);
    $pdo->prepare("INSERT IGNORE INTO `tenant_subscriptions` (`id`, `tenant_id`, `plan_code`, `status`, `starts_at`) VALUES (:id, :tenant_id, 'legacy', 'trial', NOW())")->execute(['id' => 'sub_legacy', 'tenant_id' => $legacyTenantId]);
    $pdo->prepare("INSERT IGNORE INTO `users` (`id`, `tenant_id`, `branch_id`, `name`, `email`, `password_hash`, `role`) VALUES (:id, :tenant_id, :branch_id, :name, :email, :password_hash, 'super_admin')")->execute(['id' => $legacyUserId, 'tenant_id' => $legacyTenantId, 'branch_id' => $legacyBranchId, 'name' => 'Owner Legacy', 'email' => $legacyUserEmail, 'password_hash' => $legacyPasswordHash]);

    // Backfill tenant/branch on legacy data
    $tenantTables = ['config_precios', 'config_general', 'inventario_sopa', 'segundos', 'platos_extras', 'gaseosas', 'pedidos', 'caja_movimientos', 'caja_cierres_historico', 'menus', 'products', 'tables_config', 'sopas'];
    foreach ($tenantTables as $tableName) {
        try {
            $pdo->prepare("UPDATE `$tableName` SET `tenant_id` = :tid, `branch_id` = :bid WHERE `tenant_id` IS NULL OR `branch_id` IS NULL")->execute(['tid' => $legacyTenantId, 'bid' => $legacyBranchId]);
        } catch (Throwable $e) {}
    }

    echo "\nMigration complete: $applied applied, $skipped skipped.\n";
} catch (Throwable $e) {
    error_log("[RestoCloud] Migration failed: " . $e->getMessage());
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
