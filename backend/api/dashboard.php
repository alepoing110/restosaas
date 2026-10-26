<?php
// Dashboard & state action handlers (read-only endpoints)

function handle_get_state(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'pos');
    $catalog = loadCatalogState($pdo, $authContext);
    $features = getTenantFeatures($pdo, $authContext['tenant_id']);
    $response = array_merge(["status" => "success"], buildAuthPayload($authContext), $catalog, [
        "cart" => [],
        "activeOrders" => loadActiveOrders($pdo, $authContext),
        "salesHistory" => loadOpenSalesHistory($pdo, $authContext),
        "cajaMovimientos" => loadCajaMovimientos($pdo, $authContext),
        "cajaCierres" => loadCajaCierres($pdo, $authContext),
        "features" => $features
    ]);

    if (hasPermission($authContext, 'saas_admin')) {
        $response = array_merge($response, loadSaasAdminData($pdo));
    }

    echo json_encode($response);
}

function handle_get_pos_state(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'pos');
    $catalog = loadCatalogState($pdo, $authContext);
    echo json_encode(array_merge(["status" => "success"], buildAuthPayload($authContext), $catalog, [
        "activeOrders" => loadActiveOrders($pdo, $authContext),
        "salesHistory" => loadOpenSalesHistory($pdo, $authContext)
    ]));
}

function handle_get_active_orders(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'pos');
    echo json_encode([
        "status" => "success",
        "activeOrders" => loadActiveOrders($pdo, $authContext),
        "tables" => loadTables($pdo, $authContext)
    ]);
}

function handle_get_inventory(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'inventory');
    $catalog = loadCatalogState($pdo, $authContext);
    echo json_encode(array_merge(["status" => "success"], buildAuthPayload($authContext), $catalog));
}

function handle_get_reports(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'reports');
    $startDate = $input['start_date'] ?? null;
    $endDate = $input['end_date'] ?? null;

    $hasDateFilter = $startDate && $endDate;
    if ($hasDateFilter) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            echo json_encode(["status" => "error", "message" => "Formato de fecha inválido (use YYYY-MM-DD)"]);
            return;
        }
        $salesHistory = loadSalesHistoryRange($pdo, $authContext, $startDate, $endDate);
        $cajaMovimientos = loadCajaMovimientosRange($pdo, $authContext, $startDate, $endDate);
        $scope = 'date_range';
        $label = "Reporte del $startDate al $endDate";
    } else {
        $salesHistory = loadOpenSalesHistory($pdo, $authContext, 500);
        $cajaMovimientos = loadCajaMovimientos($pdo, $authContext);
        $scope = 'open_cash_day';
        $label = 'Jornada de caja abierta';
    }

    $catalog = loadCatalogState($pdo, $authContext);
    echo json_encode([
        "status" => "success",
        "reportContext" => buildReportContext($authContext, $catalog['business'] ?? [], array_merge(
            ['scope' => $scope, 'label' => $label],
            $hasDateFilter ? ['start_date' => $startDate, 'end_date' => $endDate] : []
        )),
        "salesHistory" => $salesHistory,
        "cajaMovimientos" => $cajaMovimientos,
        "cajaCierres" => loadCajaCierres($pdo, $authContext, 200)
    ]);
}

function handle_get_dashboard(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'dashboard');
    $startDate = $input['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
    $endDate = $input['end_date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        echo json_encode(["status" => "error", "message" => "Formato de fecha inválido (use YYYY-MM-DD)"]);
        return;
    }
    $data = loadDashboardData($pdo, $authContext, $startDate, $endDate);
    $catalog = loadCatalogState($pdo, $authContext);
    echo json_encode(array_merge(["status" => "success"], $data, [
        "reportContext" => buildReportContext($authContext, $catalog['business'] ?? [], [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'label' => 'Dashboard ejecutivo'
        ])
    ]));
}

function handle_save_prices(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    foreach (['almuerzo', 'segundo', 'sopa'] as $priceKey) {
        $value = $input[$priceKey] ?? null;
        $raw = is_string($value) ? trim($value) : (is_int($value) || is_float($value) ? (string)$value : '');
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $raw) || (float)$raw > 99999999.99) {
            throw new InvalidArgumentException('Los precios deben ser montos no negativos con hasta 2 decimales.');
        }
    }
    $pdo->beginTransaction();
    $upsert = $pdo->prepare("INSERT INTO `config_precios` (`id`, `valor`, `tenant_id`, `branch_id`) VALUES (:id, :valor, :tenant_id, :branch_id) ON DUPLICATE KEY UPDATE `valor` = VALUES(`valor`)");
    foreach (['almuerzo', 'segundo', 'sopa'] as $priceKey) {
        $upsert->execute(['id' => $priceKey, 'valor' => (float)$input[$priceKey], 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    }
    writeAuditLog($pdo, $authContext, 'config.prices.save', 'config_precios');
    $pdo->commit();
    cacheInvalidateTenant('catalog', $authContext['tenant_id'], $authContext['branch_id']);
    echo json_encode(["status" => "success"]);
}

function handle_save_business_info(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    if (empty($input['nombre_restaurante']) || empty($input['direccion']) || empty($input['telefono'])) {
        echo json_encode(["status" => "error", "message" => "Todos los campos son requeridos"]);
        return;
    }
    $pdo->beginTransaction();
    $nombre = trim($input['nombre_restaurante']);
    $direccion = trim($input['direccion']);
    $telefono = trim($input['telefono']);
    $whatsapp = trim((string)($input['whatsapp_number'] ?? ''));
    $whatsappPhoneId = trim((string)($input['whatsapp_phone_id'] ?? ''));
    if (strlen($nombre) > 100 || strlen($direccion) > 200 || strlen($telefono) > 20 || strlen($whatsapp) > 30 || strlen($whatsappPhoneId) > 80) {
        echo json_encode(["status" => "error", "message" => "Longitud de campos excedida (nombre: 100, dirección: 200, teléfono: 20, WhatsApp: 30, Phone ID: 80)"]);
        return;
    }
    $upsert = $pdo->prepare("INSERT INTO `config_general` (`id`, `value`, `tenant_id`, `branch_id`) VALUES (:id, :value, :tenant_id, :branch_id) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
    $configMap = [
        'nombre_restaurante' => $input['nombre_restaurante'],
        'direccion' => $input['direccion'],
        'telefono' => $input['telefono'],
        'pais' => $input['pais'] ?? 'Bolivia',
        'whatsapp_number' => $whatsapp,
        'whatsapp_phone_id' => $whatsappPhoneId
    ];
    foreach ($configMap as $configId => $configValue) {
        $upsert->execute(['id' => $configId, 'value' => $configValue, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    }
    writeAuditLog($pdo, $authContext, 'config.business.save', 'config_general');
    $pdo->commit();
    cacheInvalidateTenant('catalog', $authContext['tenant_id'], $authContext['branch_id']);
    echo json_encode(["status" => "success"]);
}

function handle_save_print_settings(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    $allowed = [
        'print_enabled' => !empty($input['print_enabled']) ? '1' : '0',
        'waiter_ticket_enabled' => !empty($input['waiter_ticket_enabled']) ? '1' : '0',
        'print_paper_width' => in_array((string)($input['print_paper_width'] ?? '80'), ['58', '80'], true) ? (string)$input['print_paper_width'] : '80',
        'printer_kitchen' => trim((string)($input['printer_kitchen'] ?? '')),
        'printer_payment' => trim((string)($input['printer_payment'] ?? '')),
        'printer_customer' => trim((string)($input['printer_customer'] ?? '')),
        'printer_waiter' => trim((string)($input['printer_waiter'] ?? ''))
    ];
    foreach (['printer_kitchen', 'printer_payment', 'printer_customer', 'printer_waiter'] as $key) {
        if (strlen($allowed[$key]) > 120) {
            echo json_encode(['status' => 'error', 'message' => 'El nombre de una impresora es demasiado largo']);
            return;
        }
    }
    $pdo->beginTransaction();
    $upsert = $pdo->prepare("INSERT INTO `config_general` (`id`, `value`, `tenant_id`, `branch_id`) VALUES (:id, :value, :tenant_id, :branch_id) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
    foreach ($allowed as $id => $value) {
        $upsert->execute(['id' => $id, 'value' => $value, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    }
    writeAuditLog($pdo, $authContext, 'config.print.save', 'config_general');
    $pdo->commit();
    cacheInvalidateTenant('catalog', $authContext['tenant_id'], $authContext['branch_id']);
    echo json_encode(['status' => 'success']);
}
