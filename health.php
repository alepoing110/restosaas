<?php
// Public health check. Keep the response intentionally small: monitoring needs
// availability, not application internals or server fingerprints.
header("Content-Type: application/json; charset=UTF-8");
try {
    require_once __DIR__ . '/db.php';
    $pdo->query("SELECT 1");
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['status' => 'error']);
    exit;
}
echo json_encode(['status' => 'ok']);
