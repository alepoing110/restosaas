<?php

// Connection-local temporary tables shadow real tables. No tenant/user data is
// deleted or updated in the application's persistent tables.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../backend/bootstrap.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$tables = ['tenants', 'branches', 'users', 'user_branch_access'];
$failed = false;
try {
    foreach ($tables as $table) {
        $template = 'rc_bootstrap_fixture_' . $table;
        $pdo->exec("CREATE TEMPORARY TABLE `$template` LIKE `$table`");
        $pdo->exec("CREATE TEMPORARY TABLE `$table` LIKE `$template`");
        $pdo->exec("DROP TEMPORARY TABLE `$template`");
    }
    $invalidRejected = false;
    try {
        rc_bootstrap_initial_admin($pdo, '', '');
    } catch (RuntimeException $e) {
        $invalidRejected = true;
    }
    $assert($invalidRejected, 'Se aceptaron credenciales iniciales vacías.');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM `users`')->fetchColumn() === 0, 'Se creó un usuario sin credenciales.');

    $password = 'integration-only-' . bin2hex(random_bytes(12));
    $assert(rc_bootstrap_initial_admin($pdo, ' INITIAL@EXAMPLE.TEST ', $password), 'No se creó el administrador inicial.');
    $admin = $pdo->query('SELECT * FROM `users`')->fetch(PDO::FETCH_ASSOC);
    $assert($admin['email'] === 'initial@example.test', 'El email no se normalizó.');
    $assert($admin['role'] === 'super_admin' && (int)$admin['active'] === 1, 'El administrador no está activo o su rol es incorrecto.');
    $assert(password_verify($password, $admin['password_hash']), 'El hash no valida la contraseña inicial.');
    $assert($admin['password_hash'] !== $password, 'La contraseña se guardó sin hash.');
    $branch = $pdo->query('SELECT * FROM `branches`')->fetch(PDO::FETCH_ASSOC);
    $assert($branch['id'] === $admin['branch_id'] && $branch['tenant_id'] === $admin['tenant_id'] && (int)$branch['active'] === 1, 'El contexto tenant/sucursal no es válido.');
    $access = $pdo->query('SELECT * FROM `user_branch_access`')->fetch(PDO::FETCH_ASSOC);
    $assert($access['user_id'] === $admin['id'] && $access['branch_id'] === $branch['id'], 'Falta el acceso inicial a la sucursal.');

    $assert(!rc_bootstrap_initial_admin($pdo, 'different@example.test', 'different-password'), 'Se duplicó el administrador al repetir el arranque.');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM `users`')->fetchColumn() === 1, 'Hay más de un usuario tras repetir el arranque.');
    $assert($pdo->query('SELECT password_hash FROM `users`')->fetchColumn() === $admin['password_hash'], 'Se reemplazó una contraseña existente.');

    $pdo->exec("UPDATE `users` SET `role` = 'owner'");
    $assert(!rc_bootstrap_initial_admin($pdo, 'initial@example.test', $password), 'Se promovió un usuario existente automáticamente.');
    $assert($pdo->query('SELECT role FROM `users`')->fetchColumn() === 'owner', 'Se alteró el rol existente.');

    $pdo->exec('DELETE FROM `users`');
    $pdo->exec('DELETE FROM `user_branch_access`');
    $pdo->exec('UPDATE `tenants` SET `active` = 0');
    $pdo->exec('UPDATE `branches` SET `active` = 0');
    $assert(rc_bootstrap_initial_admin($pdo, 'initial@example.test', $password), 'No se creó un contexto activo cuando solo había tenants desactivados.');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM `tenants` WHERE `active` = 1')->fetchColumn() === 1, 'No se creó un tenant activo.');

    echo "Admin bootstrap integration test: OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Admin bootstrap integration test: FAIL - " . $e->getMessage() . "\n");
    $failed = true;
} finally {
    foreach (array_reverse($tables) as $table) {
        $pdo->exec("DROP TEMPORARY TABLE IF EXISTS `$table`");
    }
}
exit($failed ? 1 : 0);
