<?php

$files = [__DIR__ . '/../db.php', __DIR__ . '/../db_migrations.php'];
$source = implode("\n", array_map('file_get_contents', $files));
try {
    if (preg_match('/\bJSON\s+DEFAULT\s+NULL\b/i', $source)) {
        throw new RuntimeException('MySQL 5.7 no admite DEFAULT en columnas JSON.');
    }
    $migrations = include __DIR__ . '/../db_migrations.php';
    foreach (['v69_fix_config_precios_pk', 'v70_fix_config_general_pk'] as $name) {
        if (!isset($migrations[$name])) throw new RuntimeException("Falta la migración $name.");
    }
    echo "Migration portability test: OK (no JSON defaults; scoped key migrations present)\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Migration portability test: FAIL - " . $e->getMessage() . "\n");
    exit(1);
}
