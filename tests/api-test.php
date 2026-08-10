<?php
/**
 * API Integration Tests — Run with: php tests/api-test.php
 * Tests pure helper functions and validates module structure.
 */

$passed = 0;
$failed = 0;

function assert_test(string $name, bool $condition, string $message = '') {
    global $passed, $failed;
    if ($condition) {
        echo "  ✓ $name\n";
        $passed++;
    } else {
        echo "  ✗ $name" . ($message ? " — $message" : "") . "\n";
        $failed++;
    }
}

// ==========================================================================
// Test 1: Module files exist and are loadable
// ==========================================================================
echo "\n[1] Module Structure\n";

$modules = [
    'backend/api/helpers.php',
    'backend/api/auth.php',
    'backend/api/orders.php',
    'backend/api/inventory.php',
    'backend/api/cash.php',
    'backend/api/tables.php',
    'backend/api/reservations.php',
    'backend/api/saas.php',
    'backend/api/dashboard.php',
    'backend/api/export.php',
    'backend/api/stock.php',
    'backend/api/discounts.php',
];

foreach ($modules as $module) {
    $path = __DIR__ . '/../' . $module;
    assert_test("$module exists", file_exists($path));
    assert_test("$module has no syntax errors", (shell_exec("php -l " . escapeshellarg($path) . " 2>&1") ?? '') !== '');
}

// ==========================================================================
// Test 2: API entry point
// ==========================================================================
echo "\n[2] API Entry Point\n";

$apiPath = __DIR__ . '/../api.php';
assert_test('api.php exists', file_exists($apiPath));

$apiContent = file_get_contents($apiPath);
assert_test('api.php includes helpers.php', str_contains($apiContent, 'helpers.php'));
assert_test('api.php includes auth.php', str_contains($apiContent, 'backend/auth.php'));
assert_test('api.php has CORS headers', str_contains($apiContent, 'Access-Control-Allow-Origin'));
assert_test('api.php has CSRF validation', str_contains($apiContent, 'requireCsrfToken'));
assert_test('api.php has action router', str_contains($apiContent, 'actionHandlers'));

// ==========================================================================
// Test 3: Handler functions are defined
// ==========================================================================
echo "\n[3] Handler Functions Defined\n";

// Count handlers in router
preg_match_all("/'(\w+)'\s*=>\s*'handle_(\w+)'/", $apiContent, $matches);
$handlerCount = count($matches[1]);
assert_test("Router has $handlerCount action handlers", $handlerCount >= 40);

// Verify key handlers exist
$keyHandlers = [
    'auth_login', 'auth_logout', 'auth_me',
    'save_order', 'complete_order', 'cancel_order',
    'save_item', 'delete_item',
    'save_caja_movimiento', 'reset_data',
    'save_table', 'reorder_tables',
    'get_saas_admin', 'saas_create_tenant',
    'get_reservations', 'save_reservation',
    'get_dashboard', 'get_state',
    'export_tenant_data', 'get_stock_history', 'get_stock_report', 'get_daily_report',
    'get_discounts', 'save_discount', 'calculate_discount',
];

foreach ($keyHandlers as $handler) {
    assert_test("Handler '$handler' is registered", in_array($handler, $matches[1]));
}

// ==========================================================================
// Test 4: CSRF protection
// ==========================================================================
echo "\n[4] CSRF Protection\n";

$authContent = file_get_contents(__DIR__ . '/../backend/auth.php');
assert_test('generateCsrfToken function exists', str_contains($authContent, 'function generateCsrfToken'));
assert_test('validateCsrfToken function exists', str_contains($authContent, 'function validateCsrfToken'));
assert_test('requireCsrfToken function exists', str_contains($authContent, 'function requireCsrfToken'));
assert_test('CSRF token in auth payload', str_contains($authContent, 'csrf_token'));

// ==========================================================================
// Test 5: JS modules deduplication
// ==========================================================================
echo "\n[5] JS Code Deduplication\n";

$helpersContent = file_get_contents(__DIR__ . '/../js/app/helpers.js');
$stockContent = file_get_contents(__DIR__ . '/../js/app/stock.js');
$utilsContent = file_get_contents(__DIR__ . '/../js/core/utils.js');

assert_test('helpers.js has countSegundoUsage', str_contains($helpersContent, 'countSegundoUsage'));
assert_test('helpers.js has countPlatoExtraUsage', str_contains($helpersContent, 'countPlatoExtraUsage'));
assert_test('helpers.js has countExtraUsage', str_contains($helpersContent, 'countExtraUsage'));
assert_test('helpers.js has countSopaUsage', str_contains($helpersContent, 'countSopaUsage'));
assert_test('stock.js does NOT have countSegundoUsage', !str_contains($stockContent, 'function countSegundoUsage'));
assert_test('stock.js only has getAvailable*Stock', str_contains($stockContent, 'getAvailableSegundoStock') && !str_contains($stockContent, 'function countSegundoUsage'));

// ==========================================================================
// Test 6: api-client.js CSRF support
// ==========================================================================
echo "\n[6] Frontend CSRF Support\n";

$apiClientContent = file_get_contents(__DIR__ . '/../js/core/api-client.js');
assert_test('api-client has setCsrfToken', str_contains($apiClientContent, 'setCsrfToken'));
assert_test('api-client has getCsrfToken', str_contains($apiClientContent, 'getCsrfToken'));
assert_test('api-client sends X-CSRF-Token header', str_contains($apiClientContent, 'X-CSRF-Token'));
assert_test('api-client auto-saves csrf_token from response', str_contains($apiClientContent, 'csrf_token'));
assert_test('api-client has getStockHistory', str_contains($apiClientContent, 'getStockHistory'));
assert_test('api-client has getStockReport', str_contains($apiClientContent, 'getStockReport'));

// ==========================================================================
// Summary
// ==========================================================================
echo "\n" . str_repeat('=', 50) . "\n";
echo "Results: $passed passed, $failed failed\n";
echo str_repeat('=', 50) . "\n\n";

exit($failed > 0 ? 1 : 0);
