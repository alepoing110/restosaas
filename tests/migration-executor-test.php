<?php

require_once __DIR__ . '/../db.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$table = 'rc_migration_fixture_' . bin2hex(random_bytes(6));
$indexTable = 'rc_index_fixture_' . bin2hex(random_bytes(6));
try {
    // Reproduces production's v69 state: a legacy table without either a
    // primary key or the required tenant-aware unique index.
    // INFORMATION_SCHEMA intentionally excludes temporary tables, while the
    // migration executor checks it just like a shared MySQL host does.
    $pdo->exec("CREATE TABLE `$table` (`id` VARCHAR(50) NOT NULL, `tenant_id` VARCHAR(50) NULL, `branch_id` VARCHAR(50) NULL, `valor` DECIMAL(10,2) NOT NULL)");
    $migration = "ALTER TABLE `$table` DROP PRIMARY KEY, ADD UNIQUE KEY `uniq_precio` (`id`, `tenant_id`, `branch_id`)";
    rc_execute_migration($pdo, $migration);
    $indexCheck = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $indexCheck->execute([$table, 'uniq_precio']);
    $assert((int)$indexCheck->fetchColumn() > 0, 'No se agregó el índice único cuando faltaba la clave primaria.');

    // Re-running a partially recovered migration must be harmless.
    rc_execute_migration($pdo, $migration);

    // Simulate a request timing out after MySQL created a standalone index but
    // before the migration name was stored in _migrations.
    $pdo->exec("CREATE TABLE `$indexTable` (`id` INT NOT NULL, `value` VARCHAR(30) NOT NULL)");
    $pdo->exec("CREATE INDEX `idx_value` ON `$indexTable` (`value`)");
    rc_execute_migration($pdo, "CREATE INDEX `idx_value` ON `$indexTable` (`value`)");
    echo "Migration executor test: OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Migration executor test: FAIL - " . $e->getMessage() . "\n");
    exit(1);
} finally {
    $pdo->exec("DROP TABLE IF EXISTS `$table`");
    $pdo->exec("DROP TABLE IF EXISTS `$indexTable`");
}
