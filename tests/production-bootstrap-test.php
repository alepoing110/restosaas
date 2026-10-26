<?php

// Local-only end-to-end probe. The randomly named test database is dropped.
require_once __DIR__ . '/../db.php';
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Esta prueba solo puede ejecutarse con MySQL local.\n");
    exit(1);
}

$testDatabase = 'rc_bootstrap_test_' . bin2hex(random_bytes(6));
$password = 'production-test-' . bin2hex(random_bytes(12));
$run = static function (string $migrations, string $initialPassword) use ($testDatabase, $host, $user, $pass): void {
    $environment = array_merge(getenv(), [
        'RC_ENV_FILE' => __DIR__ . '/fixtures/production-bootstrap.env',
        'APP_ENV' => 'production',
        'RUN_MIGRATIONS' => $migrations,
        'DB_HOST' => $host,
        'DB_USER' => $user,
        'DB_PASS' => $pass,
        'DB_NAME' => $testDatabase,
        'INITIAL_ADMIN_EMAIL' => 'production-test@example.test',
        'INITIAL_ADMIN_PASSWORD' => $initialPassword
    ]);
    // InfinityFree can use a generic document root. Production detection must
    // still choose .env.production based on the public host.
    $code = 'ini_set("error_log", "php://stderr"); $_SERVER["DOCUMENT_ROOT"] = "/home/vol/htdocs"; $_SERVER["HTTP_HOST"] = "simplefood.ifree.page"; require "db.php"; echo "PRODUCTION_BOOTSTRAP_OK";';
    if ($migrations === '1') {
        $code = "define('RESTOCLOUD_MIGRATION_MODE', true); " . $code;
    }
    $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $environment);
    if (!is_resource($process)) throw new RuntimeException('No se pudo iniciar el proceso de prueba.');
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0 || strpos($output, 'PRODUCTION_BOOTSTRAP_OK') === false) {
        throw new RuntimeException('Falló el arranque de producción: ' . $errors . ' ' . $output);
    }
};

$created = false;
$failed = false;
try {
    $pdo->exec("CREATE DATABASE `$testDatabase` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $run('1', $password);
    $probe = new PDO("mysql:host=$host;dbname=$testDatabase;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $plans = $probe->query("SELECT COUNT(*) FROM `plans` WHERE `code` IN ('starter', 'pro', 'enterprise') AND `active` = 1")->fetchColumn();
    if ((int)$plans !== 3) {
        throw new RuntimeException('No se inicializaron los planes base de producción.');
    }
    // Reproduce the reported state: existing migrated tables, no users, and
    // normal production mode with automatic migrations disabled.
    $probe->exec('DELETE FROM `user_branch_access`');
    $probe->exec('DELETE FROM `users`');
    $run('0', $password);
    $admin = $probe->query('SELECT * FROM `users`')->fetch(PDO::FETCH_ASSOC);
    if (!$admin || $admin['role'] !== 'super_admin' || !password_verify($password, $admin['password_hash'])) {
        throw new RuntimeException('No se creó el superadmin con RUN_MIGRATIONS=0.');
    }
    $run('0', 'another-test-password');
    $unchanged = $probe->query('SELECT * FROM `users`')->fetch(PDO::FETCH_ASSOC);
    if ((int)$probe->query('SELECT COUNT(*) FROM `users`')->fetchColumn() !== 1 || $unchanged['id'] !== $admin['id'] || $unchanged['password_hash'] !== $admin['password_hash']) {
        throw new RuntimeException('El arranque repetido modificó o duplicó el administrador.');
    }
    echo "Production bootstrap test: OK (clean install, RUN_MIGRATIONS=0, idempotency)\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Production bootstrap test: FAIL - " . $e->getMessage() . "\n");
    $failed = true;
} finally {
    if ($created) $pdo->exec("DROP DATABASE `$testDatabase`");
}
exit($failed ? 1 : 0);
