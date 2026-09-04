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
    'backend/agent/whatsapp.php',
    'backend/agent/conversation.php',
    'backend/agent/recommendations.php',
    'backend/agent/tools.php',
    'backend/agent/chatbot.php',
    'webhook.php',
    'whatsapp-worker.php',
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
assert_test('api.php enforces centralized HTTP methods', str_contains($apiContent, '$readActions') && str_contains($apiContent, 'Método HTTP no permitido'));
assert_test('api.php protects every authenticated POST with CSRF', str_contains($apiContent, '!in_array($action, $publicPostActions, true)'));
assert_test('api.php rate limits every authenticated POST', str_contains($apiContent, "'mutating:' . \$authContext['user_id']"));
$openApiContent = file_get_contents(__DIR__ . '/../openapi.json');
$openApi = json_decode($openApiContent, true);
assert_test('openapi.json is valid JSON', is_array($openApi));

// ==========================================================================
// Test 3: Handler functions are defined
// ==========================================================================
echo "\n[3] Handler Functions Defined\n";

// Count handlers in router
preg_match_all("/'(\w+)'\s*=>\s*'handle_(\w+)'/", $apiContent, $matches);
$handlerCount = count($matches[1]);
assert_test("Router has $handlerCount action handlers", $handlerCount >= 40);
assert_test('openapi contract documents every routed action', is_array($openApi)
    && empty(array_diff($matches[1], array_merge(
        $openApi['x-restocloud-action-contract']['read_get'] ?? [],
        $openApi['x-restocloud-action-contract']['operation_post'] ?? []
    ))));

// Verify key handlers exist
$keyHandlers = [
    'auth_login', 'auth_logout', 'auth_me', 'switch_branch',
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

// ===========================================================================
// Test 3b: WhatsApp webhook and reservation-agent safeguards
// ===========================================================================
echo "\n[3b] WhatsApp and Reservation Agent Safeguards\n";

if (!function_exists('rc_env')) {
    function rc_env($key, $default = '') {
        return $_ENV[$key] ?? $default;
    }
}
require_once __DIR__ . '/../backend/agent/whatsapp.php';

$_ENV['WHATSAPP_APP_SECRET'] = 'test-secret';
$_SERVER['HTTP_X_HUB_SIGNATURE_256'] = 'sha256=' . hash_hmac('sha256', 'payload', 'test-secret');
assert_test('WhatsApp accepts a valid signature', whatsapp_validate_signature('payload'));
$_SERVER['HTTP_X_HUB_SIGNATURE_256'] = '';
assert_test('WhatsApp rejects a missing signature', !whatsapp_validate_signature('payload'));
$_ENV['WHATSAPP_APP_SECRET'] = '';
assert_test('WhatsApp rejects requests without an app secret', !whatsapp_validate_signature('payload'));

$webhookContent = file_get_contents(__DIR__ . '/../webhook.php');
$toolsAgentContent = file_get_contents(__DIR__ . '/../backend/agent/tools.php');
assert_test('Webhook enqueues events for asynchronous processing', str_contains($webhookContent, 'whatsapp_enqueue_event'));
assert_test('Webhook rejects unknown WhatsApp numbers', str_contains($webhookContent, 'Número de WhatsApp no configurado'));
$whatsappContent = file_get_contents(__DIR__ . '/../backend/agent/whatsapp.php');
assert_test('WhatsApp parser iterates all entries and changes', str_contains($whatsappContent, 'foreach (($payload[\'entry\'] ?? []) as $entry)') && str_contains($whatsappContent, 'foreach (($entry[\'changes\'] ?? []) as $change)'));
assert_test('Reservation tool requires delivery type and items', str_contains($toolsAgentContent, "'delivery_type', 'items'"));
assert_test('Reservation prices are read from canonical product data', str_contains($toolsAgentContent, 'SELECT `id`, `name`, `type`, `price`, `stock` FROM `products`'));
assert_test('Reservation stock update is conditional', str_contains($toolsAgentContent, 'AND `stock` >= :qty'));
assert_test('Reservation creation uses a transaction', str_contains($toolsAgentContent, '$pdo->beginTransaction()') && str_contains($toolsAgentContent, '$pdo->rollBack()'));
$workerContent = file_get_contents(__DIR__ . '/../whatsapp-worker.php');
assert_test('Worker retries failed WhatsApp events', str_contains($workerContent, "'received', 'failed', 'ready'") && str_contains($workerContent, 'next_attempt_at'));

// ==========================================================================
// Test 4: CSRF protection
// ==========================================================================
echo "\n[4] CSRF Protection\n";

$authContent = file_get_contents(__DIR__ . '/../backend/auth.php');
assert_test('generateCsrfToken function exists', str_contains($authContent, 'function generateCsrfToken'));
assert_test('validateCsrfToken function exists', str_contains($authContent, 'function validateCsrfToken'));
assert_test('requireCsrfToken function exists', str_contains($authContent, 'function requireCsrfToken'));
assert_test('CSRF token in auth payload', str_contains($authContent, 'csrf_token'));
assert_test('multi-branch access helper exists', str_contains($authContent, 'function getAuthorizedBranches'));
assert_test('auth payload includes authorized branches', str_contains($authContent, 'authorizedBranches'));

echo "\n[4b] SaaS Isolation and Catalog\n";
$saasContent = file_get_contents(__DIR__ . '/../backend/api/saas.php');
$inventoryContent = file_get_contents(__DIR__ . '/../backend/api/inventory.php');
$helpersApiContent = file_get_contents(__DIR__ . '/../backend/api/helpers.php');
assert_test('branch access migration exists', str_contains(file_get_contents(__DIR__ . '/../db_migrations.php'), 'user_branch_access'));
assert_test('branch switch handler exists', str_contains(file_get_contents(__DIR__ . '/../backend/api/auth.php'), 'function handle_switch_branch'));
assert_test('tenant onboarding initializes template', str_contains($saasContent, 'initializeBranchTemplate'));
assert_test('catalog save uses a transaction', str_contains($inventoryContent, '$pdo->beginTransaction()'));
assert_test('catalog save enforces product limits', str_contains($inventoryContent, 'checkPlanLimits($pdo, $tid, \'products\')'));
assert_test('product menu association is scoped', str_contains($inventoryContent, 'El menú no pertenece a la sucursal activa'));
assert_test('branch template helper exists', str_contains($helpersApiContent, 'function initializeBranchTemplate'));
assert_test('public registration grants branch access', str_contains($saasContent, 'INSERT INTO `user_branch_access`'));
assert_test('public registration initializes branch template', str_contains($saasContent, 'initializeBranchTemplate($pdo, $tenantId, $branchId, $nombreRestaurante)'));

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
assert_test('api-client supports branch switching', str_contains($apiClientContent, 'switchBranch'));
assert_test('read requests with filters use GET query parameters', str_contains($apiClientContent, "getReports: (startDate, endDate) => request('get_reports', null, { method: 'GET'"));
assert_test('catalog filters are available', str_contains(file_get_contents(__DIR__ . '/../js/views/menu-config-view.js'), 'setCatalogFilter'));
assert_test('SaaS list filters are available', str_contains(file_get_contents(__DIR__ . '/../js/controllers/saas-admin-controller.js'), 'setSaasListFilter'));
assert_test('SaaS views apply filters locally', str_contains(file_get_contents(__DIR__ . '/../js/views/saas-admin-view.js'), 'state.saasAdmin?.filters'));

// ==========================================================================
// Summary
// ==========================================================================
echo "\n" . str_repeat('=', 50) . "\n";
echo "Results: $passed passed, $failed failed\n";
echo str_repeat('=', 50) . "\n\n";

exit($failed > 0 ? 1 : 0);
