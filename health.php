<?php
// Health check endpoint for monitoring
header("Content-Type: application/json; charset=UTF-8");

$checks = [
    'status' => 'healthy',
    'timestamp' => date('c'),
    'version' => '2.0.0',
    'checks' => []
];

// Database check
try {
    require_once __DIR__ . '/db.php';
    $stmt = $pdo->query("SELECT 1");
    $checks['checks']['database'] = ['status' => 'ok', 'response_time_ms' => 0];
} catch (Throwable $e) {
    $checks['checks']['database'] = ['status' => 'error', 'message' => $e->getMessage()];
    $checks['status'] = 'unhealthy';
}

// Storage writable check
$storageDir = __DIR__ . '/storage';
$logsDir = $storageDir . '/logs';
$sessionsDir = $storageDir . '/sessions';

$checks['checks']['storage_writable'] = [
    'status' => is_dir($storageDir) && is_writable($storageDir) ? 'ok' : 'error',
    'logs_writable' => is_dir($logsDir) && is_writable($logsDir),
    'sessions_writable' => is_dir($sessionsDir) && is_writable($sessionsDir)
];

// PHP version check
$checks['checks']['php_version'] = [
    'status' => version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'warning',
    'version' => PHP_VERSION,
    'required' => '8.1.0'
];

// Extensions check
$requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'mbstring'];
$missingExtensions = array_filter($requiredExtensions, fn($ext) => !extension_loaded($ext));
$checks['checks']['extensions'] = [
    'status' => empty($missingExtensions) ? 'ok' : 'error',
    'missing' => $missingExtensions
];

// Cache check
$checks['checks']['cache'] = [
    'status' => is_dir($storageDir . '/cache') || is_writable($storageDir) ? 'ok' : 'warning'
];

// Overall status
$hasError = in_array('error', array_column($checks['checks'], 'status'));
$checks['status'] = $hasError ? 'unhealthy' : 'healthy';

http_response_code($hasError ? 503 : 200);
echo json_encode($checks, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
