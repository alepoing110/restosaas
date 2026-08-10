<?php
header("Content-Type: application/json; charset=UTF-8");
date_default_timezone_set('America/La_Paz');

$allowedOrigins = [
    'http://localhost',
    'http://localhost:80',
    'http://127.0.0.1',
    'http://127.0.0.1:80',
    'http://localhost:3000',
    'http://127.0.0.1:3000',
    'https://simplefoot.ifree.page',
    'http://simplefoot.ifree.page',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: " . $origin);
}
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-CSRF-Token");
header("Access-Control-Allow-Credentials: true");

require_once 'db.php';
require_once 'backend/auth.php';
require_once __DIR__ . '/backend/cache.php';

// Load shared helpers and action handler modules
require_once __DIR__ . '/backend/api/helpers.php';
require_once __DIR__ . '/backend/api/auth.php';
require_once __DIR__ . '/backend/api/dashboard.php';
require_once __DIR__ . '/backend/api/orders.php';
require_once __DIR__ . '/backend/api/inventory.php';
require_once __DIR__ . '/backend/api/cash.php';
require_once __DIR__ . '/backend/api/tables.php';
require_once __DIR__ . '/backend/api/reservations.php';
require_once __DIR__ . '/backend/api/saas.php';
require_once __DIR__ . '/backend/api/export.php';
require_once __DIR__ . '/backend/api/categories.php';
require_once __DIR__ . '/backend/api/stock.php';
require_once __DIR__ . '/backend/api/discounts.php';
require_once __DIR__ . '/backend/api/ws-broadcast.php';

// Parse request payload
$action = isset($_GET['action']) ? $_GET['action'] : '';
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if (!is_array($input)) {
    $input = [];
}
$input = array_merge($_GET, $input);

$authContext = resolveAuthContext($pdo);
$publicActions = getPublicActions();

if (!in_array($action, $publicActions, true) && $action !== '' && !$authContext) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Sesión requerida"]);
    exit;
}

if (!touchUserSession($pdo, $authContext)) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Sesión expirada por inactividad. Inicie sesión nuevamente."]);
    exit;
}

// CSRF Protection: validate token on mutating requests (also for logged-in users on public actions)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!in_array($action, $publicActions, true) || $authContext)) {
    requireCsrfToken();
}

function errorResponse($action, Throwable $e) {
    http_response_code(500);

    $logDir = __DIR__ . '/storage/logs';
    if (!is_dir($logDir)) mkdir($logDir, 0777, true);

    $logEntry = [
        'timestamp' => date('c'),
        'level' => 'error',
        'action' => $action,
        'message' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace_first' => explode("\n", $e->getTraceAsString())[0] ?? '',
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ];

    $logFile = $logDir . '/error-' . date('Y-m-d') . '.log';
    file_put_contents($logFile, json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

    error_log("[RestoCloud][$action] " . $e->getMessage());

    echo json_encode(["status" => "error", "message" => "Error interno del servidor"]);
}

// ==========================================================================
// ACTION ROUTER — maps action names to handler functions
// ==========================================================================

$actionHandlers = [
    // Auth
    'auth_login'                  => 'handle_auth_login',
    'admin_login'                 => 'handle_admin_login',
    'auth_me'                     => 'handle_auth_me',
    'auth_logout'                 => 'handle_auth_logout',

    // State / read-only
    'get_state'                   => 'handle_get_state',
    'get_pos_state'               => 'handle_get_pos_state',
    'get_active_orders'           => 'handle_get_active_orders',
    'get_inventory'               => 'handle_get_inventory',
    'get_reports'                 => 'handle_get_reports',
    'get_dashboard'               => 'handle_get_dashboard',

    // Orders
    'save_order'                  => 'handle_save_order',
    'update_order_state'          => 'handle_update_order_state',
    'complete_order'              => 'handle_complete_order',
    'cancel_order'                => 'handle_cancel_order',
    'append_order_items'          => 'handle_append_order_items',
    'annul_sale'                  => 'handle_annul_sale',

    // Inventory
    'save_item'                   => 'handle_save_item',
    'delete_item'                 => 'handle_delete_item',
    'save_product'                => 'handle_save_product',
    'delete_product'              => 'handle_delete_product',
    'save_menu'                   => 'handle_save_menu',
    'delete_menu'                 => 'handle_delete_menu',
    'get_products_by_menu'        => 'handle_get_products_by_menu',

    // Cash
    'get_daily_report'           => 'handle_get_daily_report',
    'save_caja_movimiento'        => 'handle_save_caja_movimiento',
    'delete_caja_movimiento'      => 'handle_delete_caja_movimiento',
    'save_caja_cierre'            => 'handle_save_caja_cierre',
    'reset_data'                  => 'handle_reset_data',

    // Tables
    'save_table'                  => 'handle_save_table',
    'delete_table'                => 'handle_delete_table',
    'reorder_tables'              => 'handle_reorder_tables',

    // Config
    'save_prices'                 => 'handle_save_prices',
    'save_business_info'          => 'handle_save_business_info',

    // Reservations
    'get_reservations'            => 'handle_get_reservations',
    'save_reservation'            => 'handle_save_reservation',
    'update_reservation_status'   => 'handle_update_reservation_status',
    'delete_reservation'          => 'handle_delete_reservation',

    // SaaS Admin
    'get_saas_admin'              => 'handle_get_saas_admin',
    'saas_create_tenant'          => 'handle_saas_create_tenant',
    'saas_create_branch'          => 'handle_saas_create_branch',
    'saas_create_user'            => 'handle_saas_create_user',
    'saas_update_subscription'    => 'handle_saas_update_subscription',
    'saas_edit_tenant'            => 'handle_saas_edit_tenant',
    'saas_delete_tenant'          => 'handle_saas_delete_tenant',
    'saas_edit_branch'            => 'handle_saas_edit_branch',
    'saas_delete_branch'          => 'handle_saas_delete_branch',
    'saas_edit_user'              => 'handle_saas_edit_user',
    'saas_delete_user'            => 'handle_saas_delete_user',
    'saas_get_plans'              => 'handle_saas_get_plans',
    'saas_create_plan'            => 'handle_saas_create_plan',
    'saas_update_plan'            => 'handle_saas_update_plan',
    'saas_delete_plan'            => 'handle_saas_delete_plan',
    'saas_approve_payment'        => 'handle_saas_approve_payment',
    'saas_reject_payment'         => 'handle_saas_reject_payment',
    'public_register'             => 'handle_public_register',

    // Tenant Users
    'get_tenant_users'            => 'handle_get_tenant_users',
    'create_tenant_user'          => 'handle_create_tenant_user',
    'edit_tenant_user'            => 'handle_edit_tenant_user',
    'delete_tenant_user'          => 'handle_delete_tenant_user',

    // Categories
    'get_categories'              => 'handle_get_categories',
    'save_category'               => 'handle_save_category',
    'delete_category'             => 'handle_delete_category',
    'reorder_categories'          => 'handle_reorder_categories',

    // Export
    'export_tenant_data'          => 'handle_export_tenant_data',
    'import_tenant_data'          => 'handle_import_tenant_data',

    // Stock History
    'get_stock_history'           => 'handle_get_stock_history',
    'get_stock_report'            => 'handle_get_stock_report',

    // Discounts
    'get_discounts'               => 'handle_get_discounts',
    'save_discount'               => 'handle_save_discount',
    'delete_discount'             => 'handle_delete_discount',
    'calculate_discount'          => 'handle_calculate_discount',
];

if ($action === '' || $action === null) {
    echo json_encode(["status" => "error", "message" => "No action specified"]);
    exit;
}

if (!isset($actionHandlers[$action])) {
    echo json_encode(["status" => "error", "message" => "Acción desconocida o no soportada"]);
    exit;
}

$handler = $actionHandlers[$action];
try {
    $handler($pdo, $authContext, $input);
} catch (Throwable $e) {
    errorResponse($action, $e);
}
