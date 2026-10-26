<?php

// Verify that setup diagnostics remain useful while never exposing raw SQL.
$source = file_get_contents(__DIR__ . '/../db.php');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
try {
    foreach (['RC-ADMIN-CONFIG', 'RC-DB-CONNECTION', 'RC-DB-MIGRATION', 'RC-DB-SCHEMA', 'RC-DB-UNKNOWN'] as $code) {
        $assert(str_contains($source, $code), "Falta el código de diagnóstico $code.");
    }
    $assert(str_contains($source, "preg_match('/^Migration\\s+"), 'La migración fallida debe poder identificarse de forma segura.');
    $assert(!str_contains($source, '"details" => $msg'), 'No se debe enviar el error interno al navegador.');
    echo "DB error response test: OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "DB error response test: FAIL - " . $e->getMessage() . "\n");
    exit(1);
}
