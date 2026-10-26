<?php
/**
 * Initialize the first SaaS administrator from environment configuration.
 * Run: php migrate.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/db.php';

$count = (int)$pdo->query("SELECT COUNT(*) FROM `users` WHERE `role` = 'super_admin' AND `active` = 1")->fetchColumn();
if ($count === 0) {
    fwrite(STDERR, "No hay superadmin activo. Si users ya contiene registros, la inicialización no los modifica ni promueve. Revise los usuarios existentes.\n");
    exit(1);
}
echo "Superadmin activo verificado. La contraseña inicial solo se aplica al crear el primer usuario; no se restablece desde el entorno.\n";
