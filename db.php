<?php
// Auto-detect environment and load appropriate .env file
// Production (InfinityFree): document_root contains 'infinityfree' or 'epizy'
// Development (XAMPP/local): everything else
$docRoot = strtolower($_SERVER['DOCUMENT_ROOT'] ?? __DIR__);
$isProduction = (strpos($docRoot, 'infinityfree') !== false) || (strpos($docRoot, 'epizy') !== false);

$envFile = $isProduction ? __DIR__ . '/.env.production' : __DIR__ . '/.env.local';

// Fallback: if the selected file doesn't exist, try .env
if (!is_file($envFile)) {
    $envFile = __DIR__ . '/.env';
}

// Parse .env file into array (always works, regardless of putenv availability)
$envVars = [];
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
        $envVars[$key] = $value;
        // Also try putenv (may be disabled on shared hosts)
        if (!getenv($key)) {
            @putenv("$key=$value");
        }
        $_ENV[$key] = $value;
    }
}

// Helper: get env var from array, $_ENV, or getenv()
function rc_env($key, $default = '') {
    global $envVars;
    if (isset($envVars[$key])) return $envVars[$key];
    if (isset($_ENV[$key])) return $_ENV[$key];
    $val = getenv($key);
    return $val !== false ? $val : $default;
}

$host = rc_env('DB_HOST', '127.0.0.1');
$user = rc_env('DB_USER', 'root');
$pass = rc_env('DB_PASS', '');
$db_name = rc_env('DB_NAME', 'pos_pension');

try {
    // Connect directly to the target database
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Unknown database') !== false) {
            // First run: create database and reconnect
            $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$db_name`");
        } else {
            throw $e;
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `tenants` (
        `id` VARCHAR(50) PRIMARY KEY,
        `slug` VARCHAR(100) NOT NULL UNIQUE,
        `name` VARCHAR(150) NOT NULL,
        `active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `business_type` VARCHAR(50) DEFAULT 'restaurante',
        `plan_level` ENUM('basico','premium','enterprise') DEFAULT 'basico'
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `branches` (
        `id` VARCHAR(50) PRIMARY KEY,
        `tenant_id` VARCHAR(50) NOT NULL,
        `name` VARCHAR(150) NOT NULL,
        `active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_branches_tenant` (`tenant_id`),
        CONSTRAINT `fk_branches_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
        `id` VARCHAR(50) PRIMARY KEY,
        `tenant_id` VARCHAR(50) NOT NULL,
        `branch_id` VARCHAR(50) NOT NULL,
        `name` VARCHAR(150) NOT NULL,
        `email` VARCHAR(180) NOT NULL UNIQUE,
        `password_hash` VARCHAR(255) NOT NULL,
        `role` ENUM('super_admin','owner','admin','cajero') NOT NULL DEFAULT 'owner',
        `active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_users_tenant_branch` (`tenant_id`, `branch_id`),
        CONSTRAINT `fk_users_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_users_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `user_sessions` (
        `id` VARCHAR(50) PRIMARY KEY,
        `user_id` VARCHAR(50) NOT NULL,
        `tenant_id` VARCHAR(50) NOT NULL,
        `branch_id` VARCHAR(50) NOT NULL,
        `started_at` DATETIME NOT NULL,
        `last_seen_at` DATETIME NOT NULL,
        `ended_at` DATETIME DEFAULT NULL,
        `ip_address` VARCHAR(45) DEFAULT NULL,
        `user_agent` VARCHAR(255) DEFAULT NULL,
        INDEX `idx_user_sessions_user` (`user_id`, `ended_at`),
        INDEX `idx_user_sessions_tenant_branch` (`tenant_id`, `branch_id`),
        CONSTRAINT `fk_user_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `plans` (
        `id` VARCHAR(50) PRIMARY KEY,
        `code` VARCHAR(50) NOT NULL UNIQUE,
        `name` VARCHAR(100) NOT NULL,
        `description` TEXT,
        `price_monthly` DECIMAL(10,2) NOT NULL DEFAULT 0,
        `price_yearly` DECIMAL(10,2) NOT NULL DEFAULT 0,
        `max_branches` INT NOT NULL DEFAULT 1,
        `max_users` INT NOT NULL DEFAULT 5,
        `max_products` INT NOT NULL DEFAULT 50,
        `features` JSON,
        `trial_days` INT NOT NULL DEFAULT 14,
        `active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `tenant_subscriptions` (
        `id` VARCHAR(50) PRIMARY KEY,
        `tenant_id` VARCHAR(50) NOT NULL UNIQUE,
        `plan_code` VARCHAR(50) NOT NULL DEFAULT 'legacy',
        `plan_id` VARCHAR(50) DEFAULT NULL,
        `billing_period` ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
        `status` ENUM('trial','active','past_due','suspended') NOT NULL DEFAULT 'trial',
        `plan_snapshot` JSON DEFAULT NULL,
        `starts_at` DATETIME DEFAULT NULL,
        `ends_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT `fk_tenant_subscriptions_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_tenant_subscriptions_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans`(`id`) ON DELETE SET NULL
    )");

    // Migration: add new columns if table already exists
    try { $pdo->exec("ALTER TABLE `tenant_subscriptions` ADD COLUMN `plan_id` VARCHAR(50) DEFAULT NULL AFTER `plan_code`"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE `tenant_subscriptions` ADD COLUMN `billing_period` ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly' AFTER `plan_id`"); } catch (Throwable $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS `audit_logs` (
        `id` VARCHAR(50) PRIMARY KEY,
        `tenant_id` VARCHAR(50) NOT NULL,
        `branch_id` VARCHAR(50) DEFAULT NULL,
        `user_id` VARCHAR(50) DEFAULT NULL,
        `action` VARCHAR(100) NOT NULL,
        `entity_type` VARCHAR(100) NOT NULL,
        `entity_id` VARCHAR(100) DEFAULT NULL,
        `payload` LONGTEXT DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_audit_logs_tenant_branch_created` (`tenant_id`, `branch_id`, `created_at`)
    )");

    // Create tables if they don't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS `config_precios` (
        `id` VARCHAR(50) NOT NULL,
        `valor` DECIMAL(10,2) NOT NULL,
        `tenant_id` VARCHAR(26) DEFAULT NULL,
        `branch_id` VARCHAR(26) DEFAULT NULL,
        UNIQUE KEY `uniq_precio` (`id`, `tenant_id`, `branch_id`)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `config_general` (
        `id` VARCHAR(50) NOT NULL,
        `value` VARCHAR(255) NOT NULL,
        `tenant_id` VARCHAR(26) DEFAULT NULL,
        `branch_id` VARCHAR(26) DEFAULT NULL,
        UNIQUE KEY `uniq_general` (`id`, `tenant_id`, `branch_id`)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `inventario_sopa` (
        `total` INT NOT NULL DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `segundos` (
        `id` VARCHAR(50) PRIMARY KEY,
        `name` VARCHAR(150) NOT NULL,
        `stock` INT NOT NULL DEFAULT 0,
        `active` TINYINT(1) NOT NULL DEFAULT 1,
        `tenant_id` VARCHAR(26) DEFAULT NULL,
        `branch_id` VARCHAR(26) DEFAULT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `platos_extras` (
        `id` VARCHAR(50) PRIMARY KEY,
        `name` VARCHAR(150) NOT NULL,
        `price` DECIMAL(10,2) NOT NULL,
        `stock` INT NOT NULL DEFAULT 0,
        `tenant_id` VARCHAR(26) DEFAULT NULL,
        `branch_id` VARCHAR(26) DEFAULT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `gaseosas` (
        `id` VARCHAR(50) PRIMARY KEY,
        `name` VARCHAR(150) NOT NULL,
        `price` DECIMAL(10,2) NOT NULL,
        `stock` INT NOT NULL DEFAULT 0,
        `tenant_id` VARCHAR(26) DEFAULT NULL,
        `branch_id` VARCHAR(26) DEFAULT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `pedidos` (
        `id` VARCHAR(50) PRIMARY KEY,
        `customer` VARCHAR(150) NOT NULL,
        `items` TEXT NOT NULL,
        `total` DECIMAL(10,2) NOT NULL,
        `payment_method` VARCHAR(50) NOT NULL DEFAULT 'efectivo',
        `service_state` VARCHAR(50) DEFAULT NULL,
        `status` VARCHAR(50) NOT NULL DEFAULT 'pendiente',
        `timestamp` DATETIME NOT NULL,
        `closure_id` VARCHAR(26) DEFAULT NULL,
        `tenant_id` VARCHAR(26) DEFAULT NULL,
        `branch_id` VARCHAR(26) DEFAULT NULL,
        `delivery_type` VARCHAR(20) DEFAULT 'mesa',
        `paid` TINYINT(1) NOT NULL DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `caja_movimientos` (
        `id` VARCHAR(50) PRIMARY KEY,
        `type` VARCHAR(50) NOT NULL,
        `description` VARCHAR(255) NOT NULL,
        `amount` DECIMAL(10,2) NOT NULL,
        `timestamp` DATETIME NOT NULL,
        `closure_id` VARCHAR(26) DEFAULT NULL,
        `tenant_id` VARCHAR(26) DEFAULT NULL,
        `branch_id` VARCHAR(26) DEFAULT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `caja_cierres_historico` (
        `id` VARCHAR(50) PRIMARY KEY,
        `fecha` DATE NOT NULL,
        `caja_inicial` DECIMAL(10,2) NOT NULL,
        `ingresos_efectivo` DECIMAL(10,2) NOT NULL,
        `egresos` DECIMAL(10,2) NOT NULL,
        `efectivo_esperado` DECIMAL(10,2) NOT NULL,
        `efectivo_real` DECIMAL(10,2) NOT NULL,
        `diferencia` DECIMAL(10,2) NOT NULL,
        `utilidad_neta` DECIMAL(10,2) NOT NULL,
        `almuerzos_vendidos` INT NOT NULL DEFAULT 0,
        `segundos_vendidos` INT NOT NULL DEFAULT 0,
        `sopas_vendidas` INT NOT NULL DEFAULT 0,
        `extras_vendidos` INT NOT NULL DEFAULT 0,
        `timestamp` DATETIME NOT NULL,
        `tenant_id` VARCHAR(26) DEFAULT NULL,
        `branch_id` VARCHAR(26) DEFAULT NULL,
        UNIQUE KEY `uniq_cierre_per_day` (`tenant_id`, `branch_id`, `fecha`)
    )");

    // Migration system: track which migrations have been applied
    $pdo->exec("CREATE TABLE IF NOT EXISTS `_migrations` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(255) NOT NULL UNIQUE,
        `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    // Run migrations on every startup (safe: uses IF NOT EXISTS / ADD COLUMN IF NOT EXISTS)
    $appliedMigrations = $pdo->query("SELECT `name` FROM `_migrations`")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $migrations = include __DIR__ . '/db_migrations.php';
    $stmtInsertMigration = $pdo->prepare("INSERT INTO `_migrations` (`name`, `applied_at`) VALUES (:name, NOW())");
    foreach ($migrations as $name => $sql) {
        if (!in_array($name, $appliedMigrations)) {
            try {
                $pdo->exec($sql);
                $stmtInsertMigration->execute(['name' => $name]);
            } catch (Throwable $e) {
                // Skip if column/index already exists (backward compat)
            }
        }
    }

    // Validate critical columns exist — prevents confusing "error de conexión" messages
    $requiredColumns = [
        'pedidos'       => ['delivery_type', 'closure_id', 'tenant_id', 'branch_id'],
        'reservations'  => ['delivery_type', 'items', 'total', 'tenant_id', 'branch_id'],
        'products'      => ['category_id', 'tenant_id', 'branch_id'],
    ];
    foreach ($requiredColumns as $table => $columns) {
        foreach ($columns as $col) {
            $check = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $check->execute([$db_name, $table, $col]);
            if ($check->fetchColumn() == 0) {
                error_log("[RestoCloud] Missing column: $table.$col — run migrations or set RUN_MIGRATIONS=1");
                echo json_encode([
                    "status" => "error",
                    "message" => "Falta la columna '$col' en la tabla '$table'. Ejecute las migraciones o contacte al administrador."
                ]);
                exit;
            }
        }
    }

    $legacyTenantId = 'tenant_legacy';
    $legacyBranchId = 'branch_main';
    $legacyUserId = 'user_owner_legacy';
    $legacyUserEmail = 'owner@legacy.restocloud.local';
    $legacyPasswordHash = password_hash('admin12345', PASSWORD_DEFAULT);

    $plansSeed = [
        ['id' => 'plan_starter', 'code' => 'starter', 'name' => 'Starter', 'description' => 'Para negocios pequeños que empiezan', 'price_monthly' => 29.00, 'price_yearly' => 290.00, 'max_branches' => 1, 'max_users' => 5, 'max_products' => 50, 'features' => json_encode(['pos' => true, 'reportes' => 'basicos', 'reservations' => false, 'inventory' => true, 'caja' => true, 'delivery' => false, 'multi_branch' => false, 'priority_support' => false]), 'trial_days' => 14],
        ['id' => 'plan_pro', 'code' => 'pro', 'name' => 'Pro', 'description' => 'Para negocios en crecimiento', 'price_monthly' => 79.00, 'price_yearly' => 790.00, 'max_branches' => 3, 'max_users' => 15, 'max_products' => 200, 'features' => json_encode(['pos' => true, 'reportes' => 'completos', 'reservations' => true, 'inventory' => true, 'caja' => true, 'delivery' => true, 'multi_branch' => false, 'priority_support' => false]), 'trial_days' => 14],
        ['id' => 'plan_enterprise', 'code' => 'enterprise', 'name' => 'Enterprise', 'description' => 'Para cadenas y franquicias', 'price_monthly' => 199.00, 'price_yearly' => 1990.00, 'max_branches' => 999, 'max_users' => 999, 'max_products' => 999, 'features' => json_encode(['pos' => true, 'reportes' => 'completos', 'reservations' => true, 'inventory' => true, 'caja' => true, 'delivery' => true, 'multi_branch' => true, 'priority_support' => true]), 'trial_days' => 14]
    ];
    $stmtPlan = $pdo->prepare("INSERT IGNORE INTO `plans` (`id`, `code`, `name`, `description`, `price_monthly`, `price_yearly`, `max_branches`, `max_users`, `max_products`, `features`, `trial_days`) VALUES (:id, :code, :name, :description, :price_monthly, :price_yearly, :max_branches, :max_users, :max_products, :features, :trial_days)");
    foreach ($plansSeed as $plan) {
        $stmtPlan->execute($plan);
    }

    $stmtTenant = $pdo->prepare("INSERT IGNORE INTO `tenants` (`id`, `slug`, `name`, `business_type`, `plan_level`) VALUES (:id, :slug, :name, 'restaurante', 'premium')");
    $stmtTenant->execute([
        'id' => $legacyTenantId,
        'slug' => 'legacy',
        'name' => 'Legacy RestoCloud'
    ]);

    $stmtBranch = $pdo->prepare("INSERT IGNORE INTO `branches` (`id`, `tenant_id`, `name`) VALUES (:id, :tenant_id, :name)");
    $stmtBranch->execute([
        'id' => $legacyBranchId,
        'tenant_id' => $legacyTenantId,
        'name' => 'Sucursal Principal'
    ]);

    $stmtSubscription = $pdo->prepare("
        INSERT IGNORE INTO `tenant_subscriptions` (`id`, `tenant_id`, `plan_code`, `status`, `starts_at`)
        VALUES (:id, :tenant_id, 'legacy', 'trial', NOW())
    ");
    $stmtSubscription->execute([
        'id' => 'sub_legacy',
        'tenant_id' => $legacyTenantId
    ]);

    $stmtUser = $pdo->prepare("
        INSERT IGNORE INTO `users` (`id`, `tenant_id`, `branch_id`, `name`, `email`, `password_hash`, `role`)
        VALUES (:id, :tenant_id, :branch_id, :name, :email, :password_hash, 'super_admin')
    ");
    $stmtUser->execute([
        'id' => $legacyUserId,
        'tenant_id' => $legacyTenantId,
        'branch_id' => $legacyBranchId,
        'name' => 'Owner Legacy',
        'email' => $legacyUserEmail,
        'password_hash' => $legacyPasswordHash
    ]);

    $tenantTables = [
        'config_precios',
        'config_general',
        'segundos',
        'platos_extras',
        'gaseosas',
        'pedidos',
        'caja_movimientos',
        'caja_cierres_historico',
        'menus',
        'products',
        'tables_config',
        'sopas',
        'categories',
        'reservations',
        'stock_history',
        'stock_daily_snapshot',
        'discounts'
    ];

    foreach ($tenantTables as $tableName) {
        try {
            $stmtBackfill = $pdo->prepare("
                UPDATE `$tableName`
                SET `tenant_id` = :tenant_id, `branch_id` = :branch_id
                WHERE `tenant_id` IS NULL OR `branch_id` IS NULL
            ");
            $stmtBackfill->execute([
                'tenant_id' => $legacyTenantId,
                'branch_id' => $legacyBranchId
            ]);
        } catch (Throwable $e) {
            // Backfill best-effort for backward compatibility
        }
    }

    // Backfill missing products records for existing catalog items
    try {
        $tables = [
            'segundos' => 'segundo',
            'sopas' => 'sopa',
            'platos_extras' => 'plato_extra',
            'gaseosas' => 'refresco'
        ];
        foreach ($tables as $catalogTable => $productType) {
            $pdo->prepare("
                INSERT IGNORE INTO `products` (`id`, `name`, `type`, `price`, `stock`, `menu_id`, `active`, `tenant_id`, `branch_id`)
                SELECT c.`id`, c.`name`, :ptype, COALESCE(c.`price`, 0), COALESCE(c.`stock`, 0), NULL, 1, c.`tenant_id`, c.`branch_id`
                FROM `$catalogTable` c
                LEFT JOIN `products` p ON p.`id` = c.`id` AND p.`tenant_id` = c.`tenant_id` AND p.`branch_id` = c.`branch_id`
                WHERE c.`tenant_id` = :tenant_id AND c.`branch_id` = :branch_id AND p.`id` IS NULL
            ")->execute(['ptype' => $productType, 'tenant_id' => $legacyTenantId, 'branch_id' => $legacyBranchId]);
        }
    } catch (Throwable $e) {
        error_log("[RestoCloud] products backfill failed: " . $e->getMessage());
    }

    // Seed default data disabled — system starts empty
    // Uncomment blocks below to re-seed defaults
    /*
    $countPrices = $pdo->query("SELECT COUNT(*) FROM `config_precios`")->fetchColumn();
    if ($countPrices == 0) {
        $pdo->exec("INSERT INTO `config_precios` (`id`, `valor`) VALUES 
            ('almuerzo', 15.00),
            ('segundo', 12.00),
            ('sopa', 6.00)
        ");
    }

    $countGeneral = $pdo->query("SELECT COUNT(*) FROM `config_general`")->fetchColumn();
    if ($countGeneral == 0) {
        $pdo->exec("INSERT INTO `config_general` (`id`, `value`) VALUES 
            ('nombre_restaurante', 'RestoCloud'),
            ('direccion', 'Calle Sucre #123, Local Central'),
            ('telefono', 'Telf: 4567890 - Cochabamba')
        ");
    }

    $countSopa = $pdo->query("SELECT COUNT(*) FROM `inventario_sopa`")->fetchColumn();
    if ($countSopa == 0) {
        $pdo->exec("INSERT INTO `inventario_sopa` (`total`) VALUES (0)");
    }

    $countSegundos = $pdo->query("SELECT COUNT(*) FROM `segundos`")->fetchColumn();
    if ($countSegundos == 0) {
        $pdo->exec("INSERT INTO `segundos` (`id`, `name`, `stock`) VALUES 
            ('sec_1', 'Silpancho Cochabambino', 20),
            ('sec_2', 'Aji de Fideo', 15)
        ");
    }

    $countPlatos = $pdo->query("SELECT COUNT(*) FROM `platos_extras`")->fetchColumn();
    if ($countPlatos == 0) {
        $pdo->exec("INSERT INTO `platos_extras` (`id`, `name`, `price`, `stock`) VALUES 
            ('pe_1', 'Pique Macho Especial', 35.00, 10),
            ('pe_2', 'Chicharron de Cerdo', 30.00, 8)
        ");
    }

    $countGaseosas = $pdo->query("SELECT COUNT(*) FROM `gaseosas`")->fetchColumn();
    if ($countGaseosas == 0) {
        $pdo->exec("INSERT INTO `gaseosas` (`id`, `name`, `price`, `stock`) VALUES 
            ('ext_1', 'Coca-Cola 500ml', 6.00, 24),
            ('ext_2', 'Fanta Mini', 3.50, 18),
            ('ext_3', 'Mocochinchi Vaso', 4.00, 30)
        ");
    }
    */

} catch (Throwable $e) {
    $msg = $e->getMessage();
    error_log("[RestoCloud] DB error: " . $msg);

    if (strpos($msg, 'Unknown column') !== false) {
        preg_match("/Unknown column '(\w+)' in/", $msg, $m);
        $col = $m[1] ?? 'desconocida';
        echo json_encode([
            "status" => "error",
            "message" => "Falta la columna '$col'. Ejecute las migraciones (RUN_MIGRATIONS=1) o contacte al administrador."
        ]);
    } elseif (strpos($msg, 'Table') !== false && strpos($msg, "doesn't exist") !== false) {
        preg_match("/Table '(\w+[\.\w]*)'/", $msg, $m);
        $table = $m[1] ?? 'desconocida';
        echo json_encode([
            "status" => "error",
            "message" => "Falta la tabla '$table'. Ejecute las migraciones (RUN_MIGRATIONS=1) o contacte al administrador."
        ]);
    } elseif (strpos($msg, 'Access denied') !== false) {
        echo json_encode([
            "status" => "error",
            "message" => "Credenciales de base de datos incorrectas. Verifique .env.production"
        ]);
    } elseif (strpos($msg, 'SQLSTATE[HY000]') !== false) {
        echo json_encode([
            "status" => "error",
            "message" => "No se pudo conectar al servidor de base de datos. Verifique que el host '$host' esté disponible."
        ]);
    } else {
        error_log("[RestoCloud] Unhandled DB error: " . $msg);
        echo json_encode([
            "status" => "error",
            "message" => "Error interno de base de datos. Contacte al administrador."
        ]);
    }
    exit;
}
