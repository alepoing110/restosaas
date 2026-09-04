<?php
// Cash register (caja) action handlers

function _countOrderItems(PDO $pdo, string $tid, string $bid, string $date): array {
    $ordCountStmt = $pdo->prepare("SELECT `items` FROM `pedidos` WHERE `tenant_id` = :tid AND `branch_id` = :bid AND (`status` = 'completado' OR (`status` = 'pendiente' AND `paid` = 1)) AND `closure_id` IS NULL AND DATE(`timestamp`) = :date");
    $ordCountStmt->execute(['tid' => $tid, 'bid' => $bid, 'date' => $date]);
    $almuerzos = 0; $segs = 0; $sopas = 0; $extras = 0;
    while ($row = $ordCountStmt->fetch()) {
        $items = json_decode($row['items'], true) ?? [];
        foreach ($items as $item) {
            $t = $item['type'] ?? '';
            if ($t === 'almuerzo') { $almuerzos++; $segs++; $sopas++; }
            elseif ($t === 'segundo') $segs++;
            elseif ($t === 'sopa') $sopas++;
            else $extras++;
        }
    }
    return ['almuerzos' => $almuerzos, 'segs' => $segs, 'sopas' => $sopas, 'extras' => $extras];
}

function _financialCashExpenseForDate(PDO $pdo, string $tenantId, string $branchId, string $date): float {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(`amount`), 0) FROM `gastos_financieros` WHERE `tenant_id` = :tid AND `branch_id` = :bid AND `fecha` = :date AND `payment_method` = 'efectivo'");
    $stmt->execute(['tid' => $tenantId, 'bid' => $branchId, 'date' => $date]);
    return (float)$stmt->fetchColumn();
}

function handle_get_daily_report(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'reports');
    $date = trim($input['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];
    $startTs = $date . ' 00:00:00';
    $endTs = $date . ' 23:59:59';

    $params = ['tid' => $tid, 'bid' => $bid, 'start' => $startTs, 'end' => $endTs];

    $stmt = $pdo->prepare("
        SELECT 
            COUNT(CASE WHEN `status` = 'completado' OR (`status` = 'pendiente' AND `paid` = 1) THEN 1 END) AS total_orders,
            COALESCE(SUM(CASE WHEN `status` = 'completado' OR (`status` = 'pendiente' AND `paid` = 1) THEN `total` ELSE 0 END), 0) AS total_revenue,
            COUNT(CASE WHEN `status` = 'anulado' THEN 1 END) AS annulled_orders,
            COALESCE(SUM(CASE WHEN `status` = 'anulado' THEN `total` ELSE 0 END), 0) AS annulled_amount
        FROM `pedidos`
        WHERE `tenant_id` = :tid AND `branch_id` = :bid AND `timestamp` BETWEEN :start AND :end
    ");
    $stmt->execute($params);
    $sales = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT `payment_method`, `total`
        FROM `pedidos`
        WHERE `tenant_id` = :tid AND `branch_id` = :bid AND `timestamp` BETWEEN :start AND :end AND (`status` = 'completado' OR (`status` = 'pendiente' AND `paid` = 1))
    ");
    $stmt->execute($params);
    $paymentBreakdown = ['efectivo' => 0, 'qr' => 0, 'tarjeta' => 0];
    foreach ($stmt->fetchAll() as $row) {
        $pm = $row['payment_method'] ?? 'efectivo';
        $decoded = json_decode($pm, true);
        $total = (float)$row['total'];
        if (is_array($decoded)) {
            if (isset($decoded['efectivo'])) $paymentBreakdown['efectivo'] += (float)$decoded['efectivo'];
            if (isset($decoded['qr'])) $paymentBreakdown['qr'] += (float)$decoded['qr'];
            if (isset($decoded['tarjeta'])) $paymentBreakdown['tarjeta'] += (float)$decoded['tarjeta'];
        } else {
            $method = in_array($pm, ['efectivo', 'qr', 'tarjeta']) ? $pm : 'efectivo';
            $paymentBreakdown[$method] += $total;
        }
    }

    $stmt = $pdo->prepare("
        SELECT `type`, `description`, `amount`
        FROM `caja_movimientos`
        WHERE `tenant_id` = :tid AND `branch_id` = :bid AND `timestamp` BETWEEN :start AND :end
    ");
    $stmt->execute($params);
    $movements = $stmt->fetchAll();
    $apertura = 0;
    $egresos = 0;
    $egresosList = [];
    foreach ($movements as $mov) {
        if ($mov['type'] === 'apertura') $apertura += (float)$mov['amount'];
        if ($mov['type'] === 'egreso') {
            $egresos += (float)$mov['amount'];
            $egresosList[] = ['description' => $mov['description'], 'amount' => (float)$mov['amount']];
        }
    }
    $registeredCashExpenses = _financialCashExpenseForDate($pdo, $tid, $bid, $date);
    if ($registeredCashExpenses > 0) {
        $egresos += $registeredCashExpenses;
        $egresosList[] = ['description' => 'Gastos financieros pagados en efectivo', 'amount' => $registeredCashExpenses];
    }

    $counts = _countOrderItems($pdo, $tid, $bid, $date);

    $ingresosEfectivo = $paymentBreakdown['efectivo'];
    $utilidadNeta = $sales['total_revenue'] - $egresos;

    $catalog = loadCatalogState($pdo, $authContext);

    echo json_encode([
        "status" => "success",
        "date" => $date,
        "reportContext" => buildReportContext($authContext, $catalog['business'] ?? [], [
            'date' => $date,
            'label' => 'Reporte diario de caja'
        ]),
        "summary" => [
            "total_orders" => (int)$sales['total_orders'],
            "total_revenue" => (float)$sales['total_revenue'],
            "annulled_orders" => (int)$sales['annulled_orders'],
            "annulled_amount" => (float)$sales['annulled_amount'],
            "apertura" => $apertura,
            "egresos" => $egresos,
            "utilidad_neta" => round($utilidadNeta, 2),
            "ingresos_efectivo" => round($ingresosEfectivo, 2),
        ],
        "payment_breakdown" => $paymentBreakdown,
        "egresos_list" => $egresosList,
        "category_counts" => $counts
    ]);
}

function handle_save_caja_movimiento(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    if (!isset($input['id']) || $input['id'] === '' || empty($input['type']) || empty($input['description']) || 
        !isset($input['amount']) || !is_numeric($input['amount']) || $input['amount'] <= 0) {
        echo json_encode(["status" => "error", "message" => "Datos del movimiento inválidos"]);
        return;
    }
    $validTypes = ['apertura', 'egreso'];
    if (!in_array($input['type'], $validTypes)) {
        echo json_encode(["status" => "error", "message" => "Tipo de movimiento inválido"]);
        return;
    }
    $rawTs = $input['timestamp'] ?? '';
    if ($rawTs && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $rawTs)) {
        $ts = str_replace('T', ' ', substr($rawTs, 0, 19));
    } else {
        $ts = date('Y-m-d H:i:s');
    }
    $stmt = $pdo->prepare("INSERT INTO `caja_movimientos` (`id`, `type`, `description`, `amount`, `timestamp`, `tenant_id`, `branch_id`) 
        VALUES (:id, :type, :description, :amount, :timestamp, :tenant_id, :branch_id)");
    $stmt->execute([
        'id' => $input['id'],
        'type' => $input['type'],
        'description' => $input['description'],
        'amount' => (float)$input['amount'],
        'timestamp' => $ts,
        'tenant_id' => $authContext['tenant_id'],
        'branch_id' => $authContext['branch_id']
    ]);
    writeAuditLog($pdo, $authContext, 'cash.movement.save', 'caja_movimiento', $input['id']);
    echo json_encode(["status" => "success"]);
}

function handle_delete_caja_movimiento(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    if (!isset($input['id'])) {
        echo json_encode(["status" => "error", "message" => "Datos requeridos: id"]);
        return;
    }
    $stmt = $pdo->prepare("DELETE FROM `caja_movimientos` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `closure_id` IS NULL");
    $stmt->execute(['id' => $input['id'], 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    if ($stmt->rowCount() === 0) {
        echo json_encode(["status" => "error", "message" => "Movimiento no encontrado o ya incluido en un cierre"]);
        return;
    }
    writeAuditLog($pdo, $authContext, 'cash.movement.delete', 'caja_movimiento', $input['id']);
    echo json_encode(["status" => "success"]);
}

function handle_save_caja_cierre(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    if (!isset($input['id']) || $input['id'] === '' || empty($input['fecha']) || 
        !isset($input['caja_inicial']) || !is_numeric($input['caja_inicial']) ||
        !isset($input['egresos']) || !is_numeric($input['egresos']) ||
        !isset($input['efectivo_real']) || !is_numeric($input['efectivo_real'])) {
        echo json_encode(["status" => "error", "message" => "Datos del cierre inválidos (id, fecha, caja_inicial, egresos, efectivo_real requeridos)"]);
        return;
    }

    $fecha = $input['fecha'];
    $cajaInicial = (float)$input['caja_inicial'];
    $egresos = (float)$input['egresos'];
    $efectivoReal = (float)$input['efectivo_real'];
    $tenantId = $authContext['tenant_id'];
    $branchId = $authContext['branch_id'];

    $movStmt = $pdo->prepare("SELECT `type`, `amount` FROM `caja_movimientos` WHERE `tenant_id` = :tid AND `branch_id` = :bid AND DATE(`timestamp`) = :date");
    $movStmt->execute(['tid' => $tenantId, 'bid' => $branchId, 'date' => $fecha]);
    $aperturaDb = 0;
    $egresosDb = 0;
    foreach ($movStmt->fetchAll(PDO::FETCH_ASSOC) as $mov) {
        if ($mov['type'] === 'apertura') $aperturaDb += (float)$mov['amount'];
        if ($mov['type'] === 'egreso') $egresosDb += (float)$mov['amount'];
    }
    $egresos += _financialCashExpenseForDate($pdo, $tenantId, $branchId, $fecha);

    $orderStmt = $pdo->prepare("SELECT `total`, `payment_method` FROM `pedidos` WHERE `tenant_id` = :tid AND `branch_id` = :bid AND (`status` = 'completado' OR (`status` = 'pendiente' AND `paid` = 1)) AND `closure_id` IS NULL AND DATE(`timestamp`) = :date");
    $orderStmt->execute(['tid' => $tenantId, 'bid' => $branchId, 'date' => $fecha]);
    $ingresosEfectivo = 0;
    $totalRevenue = 0;
    foreach ($orderStmt->fetchAll(PDO::FETCH_ASSOC) as $ord) {
        $pm = $ord['payment_method'] ?? 'efectivo';
        $total = (float)$ord['total'];
        $totalRevenue += $total;
        $decoded = json_decode($pm, true);
        if (is_array($decoded)) {
            $ingresosEfectivo += (float)($decoded['efectivo'] ?? 0);
        } else {
            if ($pm === 'efectivo') $ingresosEfectivo += $total;
        }
    }

    $counts = _countOrderItems($pdo, $tenantId, $branchId, $fecha);
    $almuerzos = $counts['almuerzos']; $segs = $counts['segs']; $sopas = $counts['sopas']; $extras = $counts['extras'];

    $efectivoEsperado = $cajaInicial + $ingresosEfectivo - $egresos;
    $diferencia = $efectivoReal - $efectivoEsperado;
    $utilidadNeta = $totalRevenue - $egresos;

    $stmt = $pdo->prepare("INSERT INTO `caja_cierres_historico` 
        (`id`, `fecha`, `caja_inicial`, `ingresos_efectivo`, `egresos`, `efectivo_esperado`, `efectivo_real`, `diferencia`, `utilidad_neta`, `almuerzos_vendidos`, `segundos_vendidos`, `sopas_vendidas`, `extras_vendidos`, `timestamp`, `tenant_id`, `branch_id`) 
        VALUES (:id, :fecha, :caja_inicial, :ingresos_efectivo, :egresos, :efectivo_esperado, :efectivo_real, :diferencia, :utilidad_neta, :almuerzos, :segundos, :sopas, :extras, NOW(), :tenant_id, :branch_id)");
    $stmt->execute([
        'id' => $input['id'],
        'fecha' => $fecha,
        'caja_inicial' => $cajaInicial,
        'ingresos_efectivo' => $ingresosEfectivo,
        'egresos' => $egresos,
        'efectivo_esperado' => $efectivoEsperado,
        'efectivo_real' => $efectivoReal,
        'diferencia' => $diferencia,
        'utilidad_neta' => $utilidadNeta,
        'almuerzos' => $almuerzos,
        'segundos' => $segs,
        'sopas' => $sopas,
        'extras' => $extras,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ]);
    writeAuditLog($pdo, $authContext, 'cash.closure.save', 'caja_cierre', $input['id']);
    echo json_encode(["status" => "success"]);
}

function _collectResetData(PDO $pdo, array $authContext, string $today): array {
    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];

    $movStmt = $pdo->prepare("SELECT `type`, `amount` FROM `caja_movimientos` WHERE `tenant_id` = :tid AND `branch_id` = :bid AND DATE(`timestamp`) = :date");
    $movStmt->execute(['tid' => $tid, 'bid' => $bid, 'date' => $today]);
    $apertura = 0;
    $egresos = 0;
    foreach ($movStmt->fetchAll(PDO::FETCH_ASSOC) as $mov) {
        if ($mov['type'] === 'apertura') $apertura += (float)$mov['amount'];
        if ($mov['type'] === 'egreso') $egresos += (float)$mov['amount'];
    }
    $egresos += _financialCashExpenseForDate($pdo, $tid, $bid, $today);

    $orderStmt = $pdo->prepare("SELECT `total`, `payment_method` FROM `pedidos` WHERE `tenant_id` = :tid AND `branch_id` = :bid AND (`status` = 'completado' OR (`status` = 'pendiente' AND `paid` = 1)) AND `closure_id` IS NULL AND DATE(`timestamp`) = :date");
    $orderStmt->execute(['tid' => $tid, 'bid' => $bid, 'date' => $today]);
    $totalRevenue = 0;
    $ingresosEfectivo = 0;
    foreach ($orderStmt->fetchAll(PDO::FETCH_ASSOC) as $ord) {
        $pm = $ord['payment_method'] ?? 'efectivo';
        $total = (float)$ord['total'];
        $totalRevenue += $total;
        $decoded = json_decode($pm, true);
        if (is_array($decoded)) {
            $ingresosEfectivo += (float)($decoded['efectivo'] ?? 0);
        } else {
            if ($pm === 'efectivo') $ingresosEfectivo += $total;
        }
    }

    $efectivoEsperado = $apertura + $ingresosEfectivo - $egresos;

    $counts = _countOrderItems($pdo, $tid, $bid, $today);

    return [
        'apertura' => $apertura,
        'ingresos_efectivo' => $ingresosEfectivo,
        'egresos' => $egresos,
        'efectivo_esperado' => $efectivoEsperado,
        'total_revenue' => $totalRevenue,
        'utilidad_neta' => $totalRevenue - $egresos,
        'almuerzos' => $counts['almuerzos'], 'segs' => $counts['segs'], 'sopas' => $counts['sopas'], 'extras' => $counts['extras'],
    ];
}

function _createClosure(PDO $pdo, array $authContext, string $closureId, string $today, array $data): void {
    $stmt = $pdo->prepare("INSERT INTO `caja_cierres_historico`
        (`id`, `tenant_id`, `branch_id`, `fecha`, `caja_inicial`, `ingresos_efectivo`, `egresos`, `efectivo_esperado`, `efectivo_real`, `diferencia`, `utilidad_neta`, `almuerzos_vendidos`, `segundos_vendidos`, `sopas_vendidas`, `extras_vendidos`, `timestamp`)
        VALUES (:id, :tenant_id, :branch_id, :fecha, :caja_inicial, :ingresos_efectivo, :egresos, :efectivo_esperado, :efectivo_real, :diferencia, :utilidad_neta, :almuerzos, :segundos, :sopas, :extras, NOW())");
    $stmt->execute([
        'id' => $closureId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id'],
        'fecha' => $today, 'caja_inicial' => $data['apertura'], 'ingresos_efectivo' => $data['ingresos_efectivo'],
        'egresos' => $data['egresos'], 'efectivo_esperado' => $data['efectivo_esperado'], 'efectivo_real' => $data['efectivo_esperado'],
        'diferencia' => 0, 'utilidad_neta' => $data['utilidad_neta'],
        'almuerzos' => $data['almuerzos'], 'segundos' => $data['segs'], 'sopas' => $data['sopas'], 'extras' => $data['extras']
    ]);
}

function _deductStockFromOrders(PDO $pdo, array $authContext, array $completedOrders): void {
    $gaseosasUsage = [];
    $segundosUsage = [];
    $sopasUsage = [];
    $platosExtrasUsage = [];
    $salsasUsage = [];

    foreach ($completedOrders as $order) {
        $items = json_decode($order['items'], true);
        if (!is_array($items)) continue;
        foreach ($items as $item) {
            $type = $item['type'] ?? '';
            if ($type === 'almuerzo') {
                $segId = $item['segundoId'] ?? '';
                if ($segId) $segundosUsage[$segId] = ($segundosUsage[$segId] ?? 0) + 1;
                $sopaId = $item['sopaId'] ?? '';
                if ($sopaId) $sopasUsage[$sopaId] = ($sopasUsage[$sopaId] ?? 0) + 1;
            } elseif ($type === 'segundo') {
                $segId = $item['segundoId'] ?? '';
                if ($segId) $segundosUsage[$segId] = ($segundosUsage[$segId] ?? 0) + 1;
            } elseif ($type === 'sopa') {
                $sopaId = $item['sopaId'] ?? '';
                if ($sopaId) $sopasUsage[$sopaId] = ($sopasUsage[$sopaId] ?? 0) + 1;
            } elseif ($type === 'plato_extra') {
                $peId = $item['platoId'] ?? '';
                if ($peId) $platosExtrasUsage[$peId] = ($platosExtrasUsage[$peId] ?? 0) + 1;
            } elseif ($type === 'extra') {
                $extId = $item['extraId'] ?? ($item['id'] ?? '');
                if ($extId) $gaseosasUsage[$extId] = ($gaseosasUsage[$extId] ?? 0) + 1;
            } elseif ($type === 'salsa') {
                $salsaId = $item['salsaId'] ?? ($item['id'] ?? '');
                if ($salsaId) $salsasUsage[$salsaId] = ($salsasUsage[$salsaId] ?? 0) + 1;
            }
        }
    }

    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];

    $stmtG = $pdo->prepare("UPDATE `gaseosas` SET `stock` = GREATEST(0, `stock` - :qty) WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    foreach ($gaseosasUsage as $id => $qty) $stmtG->execute(['qty' => $qty, 'id' => $id, 'tenant_id' => $tid, 'branch_id' => $bid]);

    $stmtS = $pdo->prepare("UPDATE `segundos` SET `stock` = GREATEST(0, `stock` - :qty) WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    foreach ($segundosUsage as $id => $qty) $stmtS->execute(['qty' => $qty, 'id' => $id, 'tenant_id' => $tid, 'branch_id' => $bid]);

    $stmtPE = $pdo->prepare("UPDATE `platos_extras` SET `stock` = GREATEST(0, `stock` - :qty) WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    foreach ($platosExtrasUsage as $id => $qty) $stmtPE->execute(['qty' => $qty, 'id' => $id, 'tenant_id' => $tid, 'branch_id' => $bid]);

    $stmtSp = $pdo->prepare("UPDATE `sopas` SET `stock` = GREATEST(0, `stock` - :qty) WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    foreach ($sopasUsage as $id => $qty) $stmtSp->execute(['qty' => $qty, 'id' => $id, 'tenant_id' => $tid, 'branch_id' => $bid]);

    $stmtSa = $pdo->prepare("UPDATE `salsas` SET `stock` = GREATEST(0, `stock` - :qty) WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    foreach ($salsasUsage as $id => $qty) $stmtSa->execute(['qty' => $qty, 'id' => $id, 'tenant_id' => $tid, 'branch_id' => $bid]);

    $today = date('Y-m-d');
    $usageMap = ['gaseosa' => $gaseosasUsage, 'segundo' => $segundosUsage, 'plato_extra' => $platosExtrasUsage, 'sopa' => $sopasUsage, 'salsa' => $salsasUsage];
    foreach ($usageMap as $itemType => $usage) {
        $table = getStockTable($itemType);
        foreach ($usage as $itemId => $soldQty) {
            $fetchStmt = $pdo->prepare("SELECT `stock`, `name` FROM `$table` WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
            $fetchStmt->execute(['id' => $itemId, 'tid' => $tid, 'bid' => $bid]);
            $row = $fetchStmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) continue;
            $closingStock = (int)$row['stock'];
            $openingStock = $closingStock + $soldQty;
            $snapId = 'snap_' . bin2hex(random_bytes(12));
            $snapStmt = $pdo->prepare("INSERT INTO `stock_daily_snapshot` (`id`, `tenant_id`, `branch_id`, `item_type`, `item_id`, `item_name`, `snapshot_date`, `opening_stock`, `closing_stock`, `sold_count`)
                VALUES (:id, :tid, :bid, :item_type, :item_id, :item_name, :date, :opening, :closing, :sold)
                ON DUPLICATE KEY UPDATE `closing_stock` = :closing2, `sold_count` = :sold2");
            $snapStmt->execute([
                'id' => $snapId, 'tid' => $tid, 'bid' => $bid,
                'item_type' => $itemType, 'item_id' => $itemId, 'item_name' => $row['name'],
                'date' => $today, 'opening' => $openingStock, 'closing' => $closingStock, 'sold' => $soldQty,
                'closing2' => $closingStock, 'sold2' => $soldQty
            ]);
            logStockEvent($pdo, $authContext, $itemType, $itemId, $row['name'], 'deduct', $openingStock, -$soldQty, $closingStock, 'cierre', null);
        }
    }
}

function handle_reset_data(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    $confirm = $input['confirm'] ?? false;
    if (!$confirm) {
        echo json_encode(["status" => "error", "message" => "Se eliminarán todos los pedidos pendientes. Envíe confirm: true para proceder."]);
        return;
    }
    $pdo->beginTransaction();
    $closureId = $input['closure_id'] ?? null;
    $today = date('Y-m-d');

    if ($closureId) {
        $data = _collectResetData($pdo, $authContext, $today);
        try {
            _createClosure($pdo, $authContext, $closureId, $today, $data);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(["status" => "error", "message" => "Ya existe un cierre de caja para hoy. No se puede ejecutar dos veces."]);
            return;
        }
    }

    $stmt = $pdo->prepare("SELECT * FROM `pedidos` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND (`status` = 'completado' OR (`status` = 'pendiente' AND `paid` = 1)) AND `closure_id` IS NULL");
    $stmt->execute(tenantParams($authContext));
    $completedOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    _deductStockFromOrders($pdo, $authContext, $completedOrders);

    if ($closureId) {
        $stmtArchivePedidos = $pdo->prepare("UPDATE `pedidos` SET `closure_id` = :closure_id WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `closure_id` IS NULL AND (`status` IN ('completado', 'anulado') OR (`status` = 'pendiente' AND `paid` = 1))");
        $stmtArchivePedidos->execute(['closure_id' => $closureId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);

        $stmtArchiveMovimientos = $pdo->prepare("UPDATE `caja_movimientos` SET `closure_id` = :closure_id WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `closure_id` IS NULL");
        $stmtArchiveMovimientos->execute(['closure_id' => $closureId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    }

    $stmtDeletePending = $pdo->prepare("DELETE FROM `pedidos` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `status` = 'pendiente' AND `closure_id` IS NULL");
    $stmtDeletePending->execute(tenantParams($authContext));

    $pdo->commit();
    writeAuditLog($pdo, $authContext, 'cash.reset', 'tenant_day', $closureId);
    cacheInvalidateTenant('catalog', $authContext['tenant_id'], $authContext['branch_id']);
    echo json_encode(["status" => "success"]);
}
