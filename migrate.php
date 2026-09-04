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

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

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

putenv('RC_ENV_FILE=' . $envFile);
$_ENV['RC_ENV_FILE'] = $envFile;

$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$db_name = getenv('DB_NAME') ?: 'pos_pension';

$force = in_array('--force', $argv ?? []);

try {
    // CLI is the only place allowed to bootstrap schema. Web requests only connect.
    putenv('RUN_MIGRATIONS=1');
    $_ENV['RUN_MIGRATIONS'] = '1';
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/backend/api/orders.php';

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
        if (in_array($name, $appliedMigrations, true)) {
            $skipped++;
            continue;
        }
        try {
            $pdo->exec($sql);
            $pdo->prepare("INSERT INTO `_migrations` (`name`, `applied_at`) VALUES (:name, NOW())")->execute(['name' => $name]);
            echo "  [OK] $name\n";
            $applied++;
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate') !== false || stripos($msg, 'already exists') !== false) {
                $pdo->prepare("INSERT IGNORE INTO `_migrations` (`name`, `applied_at`) VALUES (:name, NOW())")->execute(['name' => $name]);
                echo "  [SKIP] $name (already applied)\n";
                $skipped++;
            } else {
                echo "  [ERROR] $name: $msg\n";
                throw $e;
            }
        }
    }

    // Populate normalized sales history for orders created before v101-v103.
    $backfillCheck = $pdo->query("SELECT COUNT(*) FROM `pedido_items`")->fetchColumn();
    $ordersStmt = $pdo->query("SELECT `id`, `items`, `payment_method`, `total`, `paid`, `tenant_id`, `branch_id` FROM `pedidos` WHERE `tenant_id` IS NOT NULL AND `branch_id` IS NOT NULL");
    $backfilled = 0;
    $existsStmt = $pdo->prepare("SELECT 1 FROM `pedido_items` WHERE `order_id` = :order_id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id LIMIT 1");
    while ($order = $ordersStmt->fetch(PDO::FETCH_ASSOC)) {
        $existsStmt->execute(['order_id' => $order['id'], 'tenant_id' => $order['tenant_id'], 'branch_id' => $order['branch_id']]);
        if ($existsStmt->fetchColumn()) continue;
        persistOrderHistory($pdo, [
            'tenant_id' => $order['tenant_id'],
            'branch_id' => $order['branch_id'],
            'user_id' => null
        ], (string)$order['id'], json_decode($order['items'], true) ?: [], $order['payment_method'], (float)$order['total'], !empty($order['paid']));
        $backfilled++;
    }
    echo "Normalized order history: $backfilled orders backfilled (existing rows: $backfillCheck).\n";

    echo "\nMigration complete: $applied applied, $skipped skipped.\n";
} catch (Throwable $e) {
    error_log("[RestoCloud] Migration failed: " . $e->getMessage());
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
