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
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $origin);
}
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-CSRF-Token");
header("Access-Control-Allow-Credentials: true");

require_once 'db.php';
require_once 'backend/auth.php';
require_once __DIR__ . '/backend/cache.php';

if (function_exists('rc_env') && rc_env('APP_ENV', 'production') === 'production') {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    if (!$isHttps && PHP_SAPI !== 'cli') {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        if ($host !== '') {
            header('Location: https://' . $host . $uri, true, 308);
            exit;
        }
    }
}

// Load shared helpers and action handler modules
require_once __DIR__ . '/backend/api/helpers.php';
require_once __DIR__ . '/backend/api/auth.php';
require_once __DIR__ . '/backend/api/dashboard.php';
require_once __DIR__ . '/backend/api/orders.php';
require_once __DIR__ . '/backend/api/inventory.php';
require_once __DIR__ . '/backend/api/cash.php';
require_once __DIR__ . '/backend/api/financial.php';
require_once __DIR__ . '/backend/api/receivables.php';
require_once __DIR__ . '/backend/api/refunds.php';
require_once __DIR__ . '/backend/api/promos.php';
require_once __DIR__ . '/backend/api/tables.php';
require_once __DIR__ . '/backend/api/reservations.php';
require_once __DIR__ . '/backend/api/saas.php';
require_once __DIR__ . '/backend/api/export.php';
require_once __DIR__ . '/backend/api/categories.php';
require_once __DIR__ . '/backend/api/stock.php';
require_once __DIR__ . '/backend/api/discounts.php';
require_once __DIR__ . '/backend/api/ws-broadcast.php';
require_once __DIR__ . '/backend/api/chatbot-admin.php';
require_once __DIR__ . '/backend/api/chatbot-simulate.php';
require_once __DIR__ . '/backend/api/chatbot-simulate-local.php';
require_once __DIR__ . '/backend/api/chatbot-tools.php';
require_once __DIR__ . '/backend/agent/conversation.php';
require_once __DIR__ . '/backend/agent/whatsapp.php';

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
unset($input['action']);

$authContext = resolveAuthContext($pdo);
$publicActions = getPublicActions();

if (!in_array($action, $publicActions, true) && $action !== '' && !$authContext) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Sesión requerida"]);
    exit;
}

if (!in_array($action, $publicActions, true) && $authContext && !touchUserSession($pdo, $authContext)) {
    ensureSessionStarted();
    session_destroy();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"], $params["secure"], $params["httponly"]
        );
    }
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Sesión expirada por inactividad. Inicie sesión nuevamente."]);
    exit;
}

function errorResponse($action, Throwable $e) {
    $isClientError = $e instanceof InvalidArgumentException;
    http_response_code($isClientError ? 400 : 500);

    $logDir = __DIR__ . '/storage/logs';
    if (!is_dir($logDir)) mkdir($logDir, 0750, true);

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

    echo json_encode(["status" => "error", "message" => $isClientError ? $e->getMessage() : "Error interno del servidor"]);
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
    'switch_branch'               => 'handle_switch_branch',

    // State / read-only
    'get_state'                   => 'handle_get_state',
    'get_pos_state'               => 'handle_get_pos_state',
    'get_active_orders'           => 'handle_get_active_orders',
    'get_inventory'               => 'handle_get_inventory',
    'get_reports'                 => 'handle_get_reports',
    'get_dashboard'               => 'handle_get_dashboard',
    'get_financial_report'        => 'handle_get_financial_report',
    'get_branch_comparison'       => 'handle_get_branch_comparison',
    'get_cash_flow'               => 'handle_get_cash_flow',
    'get_control_report'          => 'handle_get_control_report',
    'get_customers'               => 'handle_get_customers',
    'get_customer_profile'        => 'handle_get_customer_profile',
    'get_receivables'             => 'handle_get_receivables',
    'get_refunds_report'          => 'handle_get_refunds_report',
    'create_refund'               => 'handle_create_refund',
    'suggest_promos'              => 'handle_suggest_promos',
    'validate_promo'              => 'handle_validate_promo',
    'apply_promo_coupon'          => 'handle_apply_promo_coupon',
    'get_promo_plans'             => 'handle_get_promo_plans',
    'save_promo_plan'             => 'handle_save_promo_plan',
    'delete_promo_plan'           => 'handle_delete_promo_plan',

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
    'close_cash_day'              => 'handle_close_cash_day',
    'save_caja_cierre'            => 'handle_save_caja_cierre',
    'reset_data'                  => 'handle_reset_data',
    'save_financial_expense'      => 'handle_save_financial_expense',
    'delete_financial_expense'    => 'handle_delete_financial_expense',
    'save_customer'               => 'handle_save_customer',
    'save_customer_interaction'   => 'handle_save_customer_interaction',
    'save_collection_task'        => 'handle_save_collection_task',
    'save_receivable_payment'     => 'handle_save_receivable_payment',

    // Tables
    'save_table'                  => 'handle_save_table',
    'delete_table'                => 'handle_delete_table',
    'reorder_tables'              => 'handle_reorder_tables',

    // Config
    'save_prices'                 => 'handle_save_prices',
    'save_business_info'          => 'handle_save_business_info',
    'save_print_settings'         => 'handle_save_print_settings',

    // Reservations
    'get_reservations'            => 'handle_get_reservations',
    'save_reservation'            => 'handle_save_reservation',
    'update_reservation_status'   => 'handle_update_reservation_status',
    'delete_reservation'          => 'handle_delete_reservation',
    'verify_bot_reservations'     => 'handle_verify_bot_reservations',
    'mark_bot_reservations_printed' => 'handle_mark_bot_reservations_printed',

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

    // Chatbot admin
    'get_chatbot_conversations'   => 'handle_get_chatbot_conversations',
    'get_chatbot_messages'        => 'handle_get_chatbot_messages',
    'chatbot_simulate'            => 'handle_chatbot_simulate',
    'chatbot_close_conversation'  => 'handle_chatbot_close_conversation',
    'delete_chatbot_conversation' => 'handle_delete_chatbot_conversation',
    'chatbot_simulate_local'      => 'handle_chatbot_simulate_local',
    'chatbot_save_reply_local'    => 'handle_chatbot_save_reply_local',
    'chatbot_execute_tool'        => 'handle_chatbot_execute_tool',
    'update_chatbot_attention'    => 'handle_update_chatbot_attention',
    'send_human_whatsapp_reply'   => 'handle_send_human_whatsapp_reply',

];

// HTTP policy lives beside the router so new actions cannot accidentally skip
// method enforcement, CSRF, or rate limiting.
$readActions = [
    'auth_me', 'get_state', 'get_pos_state', 'get_active_orders', 'get_inventory',
    'get_reports', 'get_dashboard', 'get_financial_report', 'get_branch_comparison', 'get_cash_flow', 'get_control_report', 'get_customers', 'get_customer_profile', 'get_receivables', 'get_refunds_report', 'get_promo_plans', 'get_products_by_menu', 'get_daily_report',
    'get_reservations', 'get_saas_admin', 'saas_get_plans', 'get_tenant_users',
    'get_categories', 'export_tenant_data', 'get_stock_history', 'get_stock_report',
    'get_discounts', 'get_chatbot_conversations', 'get_chatbot_messages',
];
$postActions = array_values(array_diff(array_keys($actionHandlers), $readActions));
$publicPostActions = ['auth_login', 'admin_login', 'public_register'];

if ($action === '' || $action === null) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "No action specified"]);
    exit;
}

if (!isset($actionHandlers[$action])) {
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "Acción desconocida o no soportada"]);
    exit;
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$allowedMethod = in_array($action, $readActions, true) ? 'GET' : 'POST';
if ($requestMethod !== $allowedMethod) {
    http_response_code(405);
    header('Allow: ' . $allowedMethod . ', OPTIONS');
    echo json_encode(["status" => "error", "message" => "Método HTTP no permitido para esta acción"]);
    exit;
}

if ($requestMethod === 'POST' && !in_array($action, $publicPostActions, true)) {
    requireCsrfToken();
}

if ($requestMethod === 'POST' && $authContext && !in_array($action, $publicPostActions, true)) {
    $rlKey = 'mutating:' . $authContext['user_id'] . ':' . $action;
    $rl = checkRateLimit($rlKey, 30, 60);
    if (!empty($rl['locked'])) {
        http_response_code(429);
        echo json_encode(["status" => "error", "message" => "Demasiadas solicitudes. Espere {$rl['retry_after']} segundos."]);
        exit;
    }
}

$handler = $actionHandlers[$action];
try {
    set_error_handler(function($severity, $message, $file, $line) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    ob_start();
    $handler($pdo, $authContext, $input);
    $output = ob_get_clean();

    restore_error_handler();

    if ($authContext && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $newToken = generateCsrfToken();
        $data = json_decode($output, true);
        if (is_array($data)) {
            $data['csrf_token'] = $newToken;
            echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        } else {
            error_log("[RestoCloud] Non-JSON handler output for action=$action: " . substr($output, 0, 500));
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Error interno del servidor', 'csrf_token' => $newToken]);
        }
    } else {
        $data = json_decode($output, true);
        if (is_array($data)) {
            echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        } else {
            echo $output;
        }
    }
} catch (Throwable $e) {
    restore_error_handler();
    errorResponse($action, $e);
}
