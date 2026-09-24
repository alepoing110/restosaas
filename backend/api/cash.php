<?php
// Cash register (caja) action handlers

function _countOrderItems(PDO $pdo, string $tid, string $bid, string $date): array {
    [$start, $end] = cashDateRange($date);
    $saleCondition = "(`sold_at` >= :start AND `sold_at` < :end) OR (`sold_at` IS NULL AND `timestamp` >= :start AND `timestamp` < :end)";
    $ordCountStmt = $pdo->prepare("SELECT `items` FROM `pedidos` WHERE `tenant_id` = :tid AND `branch_id` = :bid AND (`status` = 'completado' OR (`status` = 'pendiente' AND `paid` = 1)) AND `closure_id` IS NULL AND ($saleCondition)");
    $ordCountStmt->execute(['tid' => $tid, 'bid' => $bid, 'start' => $start, 'end' => $end]);
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

function cashDateRange(string $date): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new InvalidArgumentException('La fecha de cierre es inválida.');
    $start = new DateTimeImmutable($date . ' 00:00:00');
    if ($start->format('Y-m-d') !== $date) throw new InvalidArgumentException('La fecha de cierre es inválida.');
    return [$start->format('Y-m-d H:i:s'), $start->modify('+1 day')->format('Y-m-d H:i:s')];
}

function cashPaymentBreakdown($paymentMethod, float $total): array {
    $amounts = ['efectivo' => 0.0, 'qr' => 0.0, 'tarjeta' => 0.0, 'transferencia' => 0.0, 'otro' => 0.0];
    $decoded = is_array($paymentMethod) ? $paymentMethod : json_decode((string)$paymentMethod, true);
    if (is_array($decoded)) {
        foreach ($amounts as $method => $_) $amounts[$method] = max(0, (float)($decoded[$method] ?? 0));
        return $amounts;
    }
    $method = (string)$paymentMethod;
    $amounts[in_array($method, array_keys($amounts), true) ? $method : ($method === '' ? 'efectivo' : 'otro')] = $total;
    return $amounts;
}

function cashCloseData(PDO $pdo, array $authContext, string $date): array {
    [$start, $end] = cashDateRange($date);
    $scope = ['tid' => $authContext['tenant_id'], 'bid' => $authContext['branch_id']];
    $rangeScope = $scope + ['start' => $start, 'end' => $end];
    $saleCondition = "(`sold_at` >= :start AND `sold_at` < :end) OR (`sold_at` IS NULL AND `timestamp` >= :start AND `timestamp` < :end)";
    $salesStmt = $pdo->prepare("SELECT `id`, `items`, `total`, `payment_method` FROM `pedidos` WHERE `tenant_id` = :tid AND `branch_id` = :bid AND `closure_id` IS NULL AND (`status` = 'completado' OR (`status` = 'pendiente' AND `paid` = 1)) AND ($saleCondition) FOR UPDATE");
    $salesStmt->execute($rangeScope);
    $sales = $salesStmt->fetchAll(PDO::FETCH_ASSOC);
    $paymentBreakdown = cashPaymentBreakdown([], 0);
    $counts = ['almuerzos' => 0, 'segs' => 0, 'sopas' => 0, 'extras' => 0];
    $totalRevenue = 0.0;
    foreach ($sales as $sale) {
        $total = (float)$sale['total'];
        $totalRevenue += $total;
        foreach (cashPaymentBreakdown($sale['payment_method'], $total) as $method => $amount) $paymentBreakdown[$method] += $amount;
        foreach (json_decode($sale['items'] ?? '[]', true) ?: [] as $item) {
            $qty = max(1, (int)($item['qty'] ?? $item['quantity'] ?? 1));
            if (($item['type'] ?? '') === 'almuerzo') { $counts['almuerzos'] += $qty; $counts['segs'] += $qty; $counts['sopas'] += $qty; }
            elseif (($item['type'] ?? '') === 'segundo') $counts['segs'] += $qty;
            elseif (($item['type'] ?? '') === 'sopa') $counts['sopas'] += $qty;
            else $counts['extras'] += $qty;
        }
    }
    $movementStmt = $pdo->prepare("SELECT `id`, `type`, `amount` FROM `caja_movimientos` WHERE `tenant_id` = :tid AND `branch_id` = :bid AND `closure_id` IS NULL AND `timestamp` >= :start AND `timestamp` < :end FOR UPDATE");
    $movementStmt->execute($rangeScope);
    $movements = $movementStmt->fetchAll(PDO::FETCH_ASSOC);
    $opening = 0.0; $movementExpenses = 0.0;
    foreach ($movements as $movement) {
        if ($movement['type'] === 'apertura') $opening += (float)$movement['amount'];
        elseif ($movement['type'] === 'egreso') $movementExpenses += (float)$movement['amount'];
    }
    $expenseStmt = $pdo->prepare("SELECT `id`, `amount`, `payment_method` FROM `gastos_financieros` WHERE `tenant_id` = :tid AND `branch_id` = :bid AND `closure_id` IS NULL AND `fecha` = :date FOR UPDATE");
    $expenseStmt->execute($scope + ['date' => $date]);
    $expenses = $expenseStmt->fetchAll(PDO::FETCH_ASSOC);
    $financialExpenses = 0.0; $cashExpenses = 0.0;
    foreach ($expenses as $expense) { $financialExpenses += (float)$expense['amount']; if ($expense['payment_method'] === 'efectivo') $cashExpenses += (float)$expense['amount']; }
    $refundStmt = $pdo->prepare("SELECT `id`, `total_refunded`, `refund_method` FROM `pedido_reversiones` WHERE `tenant_id` = :tid AND `branch_id` = :bid AND `closure_id` IS NULL AND `status` = 'aplicada' AND `created_at` >= :start AND `created_at` < :end FOR UPDATE");
    $refundStmt->execute($rangeScope);
    $refunds = $refundStmt->fetchAll(PDO::FETCH_ASSOC);
    $refundTotal = 0.0; $cashRefunds = 0.0;
    foreach ($refunds as $refund) { $refundTotal += (float)$refund['total_refunded']; if ($refund['refund_method'] === 'efectivo') $cashRefunds += (float)$refund['total_refunded']; }
    return ['sales' => $sales, 'movements' => $movements, 'expenses' => $expenses, 'refunds' => $refunds, 'counts' => $counts, 'payment_breakdown' => $paymentBreakdown, 'opening' => $opening, 'movement_expenses' => $movementExpenses, 'financial_expenses' => $financialExpenses, 'cash_expenses' => $cashExpenses, 'refund_total' => $refundTotal, 'cash_refunds' => $cashRefunds, 'total_revenue' => $totalRevenue, 'cash_expected' => $opening + $paymentBreakdown['efectivo'] - $movementExpenses - $cashExpenses - $cashRefunds];
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
    $closedDay = $pdo->prepare("SELECT 1 FROM `caja_cierres_historico` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `fecha` = :date LIMIT 1");
    $closedDay->execute(['tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id'], 'date' => substr($ts, 0, 10)]);
    if ($closedDay->fetchColumn()) throw new InvalidArgumentException('El día ya está cerrado. Registre el movimiento en la fecha operativa actual.');
    $pdo->beginTransaction();
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
    $pdo->commit();
    echo json_encode(["status" => "success"]);
}

function handle_delete_caja_movimiento(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    if (!isset($input['id'])) {
        echo json_encode(["status" => "error", "message" => "Datos requeridos: id"]);
        return;
    }
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("DELETE FROM `caja_movimientos` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `closure_id` IS NULL");
    $stmt->execute(['id' => $input['id'], 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    if ($stmt->rowCount() === 0) {
        echo json_encode(["status" => "error", "message" => "Movimiento no encontrado o ya incluido en un cierre"]);
        return;
    }
    writeAuditLog($pdo, $authContext, 'cash.movement.delete', 'caja_movimiento', $input['id']);
    $pdo->commit();
    echo json_encode(["status" => "success"]);
}

function handle_close_cash_day(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    $date = trim((string)($input['date'] ?? date('Y-m-d')));
    $physicalCash = $input['physical_cash'] ?? null;
    if (!is_numeric($physicalCash) || (float)$physicalCash < 0) throw new InvalidArgumentException('Ingrese el efectivo físico contado.');
    cashDateRange($date);
    $pdo->beginTransaction();
    try {
        $scope = ['tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id'], 'date' => $date];
        $existingStmt = $pdo->prepare("SELECT * FROM `caja_cierres_historico` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `fecha` = :date FOR UPDATE");
        $existingStmt->execute($scope);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $pdo->commit();
            echo json_encode(['status' => 'success', 'already_closed' => true, 'closure' => $existing]);
            return;
        }
        $data = cashCloseData($pdo, $authContext, $date);
        [, $end] = cashDateRange($date);
        $pendingStmt = $pdo->prepare("SELECT `id`, `customer`, `timestamp` FROM `pedidos` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `closure_id` IS NULL AND `status` = 'pendiente' AND `paid` = 0 AND `timestamp` < :end ORDER BY `timestamp` ASC FOR UPDATE");
        $pendingStmt->execute(['tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id'], 'end' => $end]);
        $pending = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
        if ($pending && empty($input['pending_reviewed'])) {
            $pdo->commit();
            echo json_encode(['status' => 'success', 'requires_pending_review' => true, 'pending_orders' => $pending]);
            return;
        }
        $closureId = trim((string)($input['idempotency_key'] ?? ''));
        if ($closureId === '') $closureId = 'cierre_' . bin2hex(random_bytes(12));
        $expenses = $data['movement_expenses'] + $data['cash_expenses'];
        $expectedCash = round($data['cash_expected'], 2);
        $realCash = round((float)$physicalCash, 2);
        $stmt = $pdo->prepare("INSERT INTO `caja_cierres_historico` (`id`, `fecha`, `caja_inicial`, `ingresos_efectivo`, `egresos`, `devoluciones_total`, `devoluciones_efectivo`, `efectivo_esperado`, `efectivo_real`, `diferencia`, `utilidad_neta`, `almuerzos_vendidos`, `segundos_vendidos`, `sopas_vendidas`, `extras_vendidos`, `timestamp`, `tenant_id`, `branch_id`) VALUES (:id, :date, :opening, :cash_sales, :expenses, :refund_total, :cash_refunds, :expected, :real, :difference, :profit, :almuerzos, :segundos, :sopas, :extras, NOW(), :tenant_id, :branch_id)");
        $stmt->execute($scope + ['id' => $closureId, 'opening' => $data['opening'], 'cash_sales' => $data['payment_breakdown']['efectivo'], 'expenses' => $expenses, 'refund_total' => $data['refund_total'], 'cash_refunds' => $data['cash_refunds'], 'expected' => $expectedCash, 'real' => $realCash, 'difference' => round($realCash - $expectedCash, 2), 'profit' => round($data['total_revenue'] - $data['financial_expenses'] - $data['movement_expenses'] - $data['refund_total'], 2), 'almuerzos' => $data['counts']['almuerzos'], 'segundos' => $data['counts']['segs'], 'sopas' => $data['counts']['sopas'], 'extras' => $data['counts']['extras']]);
        [$start, $end] = cashDateRange($date);
        $archiveScope = ['closure_id' => $closureId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']];
        $rangeArchiveScope = $archiveScope + ['start' => $start, 'end' => $end];
        $saleCondition = "(`sold_at` >= :start AND `sold_at` < :end) OR (`sold_at` IS NULL AND `timestamp` >= :start AND `timestamp` < :end)";
        $pdo->prepare("UPDATE `pedidos` SET `closure_id` = :closure_id WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `closure_id` IS NULL AND (`status` = 'completado' OR (`status` = 'pendiente' AND `paid` = 1) OR `status` = 'anulado') AND ($saleCondition)")->execute($rangeArchiveScope);
        $pdo->prepare("UPDATE `caja_movimientos` SET `closure_id` = :closure_id WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `closure_id` IS NULL AND `timestamp` >= :start AND `timestamp` < :end")->execute($rangeArchiveScope);
        $pdo->prepare("UPDATE `gastos_financieros` SET `closure_id` = :closure_id WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `closure_id` IS NULL AND `fecha` = :date")->execute($archiveScope + ['date' => $date]);
        $pdo->prepare("UPDATE `pedido_reversiones` SET `closure_id` = :closure_id WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `closure_id` IS NULL AND `status` = 'aplicada' AND `created_at` >= :start AND `created_at` < :end")->execute($rangeArchiveScope);
        writeAuditLog($pdo, $authContext, 'cash.day.close', 'caja_cierre', $closureId, ['date' => $date, 'pending_reviewed' => !empty($input['pending_reviewed'])]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (stripos($e->getMessage(), 'duplicate') !== false) {
            $retry = $pdo->prepare("SELECT * FROM `caja_cierres_historico` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `fecha` = :date LIMIT 1");
            $retry->execute(['tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id'], 'date' => $date]);
            $closure = $retry->fetch(PDO::FETCH_ASSOC);
            if ($closure) {
                echo json_encode(['status' => 'success', 'already_closed' => true, 'closure' => $closure]);
                return;
            }
        }
        throw $e;
    }
    echo json_encode(['status' => 'success', 'already_closed' => false, 'closure' => ['id' => $closureId, 'fecha' => $date, 'efectivo_esperado' => $expectedCash, 'efectivo_real' => $realCash, 'diferencia' => round($realCash - $expectedCash, 2)]]);
}

function handle_save_caja_cierre(PDO $pdo, ?array $authContext, array $input): void {
    throw new InvalidArgumentException('Use el cierre diario de caja para registrar un cierre.');
}

function handle_legacy_save_caja_cierre(PDO $pdo, ?array $authContext, array $input): void {
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

function handle_reset_data(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    throw new InvalidArgumentException('El cierre diario ya no elimina pedidos. Use close_cash_day.');
    /* Legacy destructive reset retained below only as migration reference.
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

    if ($closureId) {
        $stmtArchivePedidos = $pdo->prepare("UPDATE `pedidos` SET `closure_id` = :closure_id WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `closure_id` IS NULL AND (`status` IN ('completado', 'anulado') OR (`status` = 'pendiente' AND `paid` = 1))");
        $stmtArchivePedidos->execute(['closure_id' => $closureId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);

        $stmtArchiveMovimientos = $pdo->prepare("UPDATE `caja_movimientos` SET `closure_id` = :closure_id WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `closure_id` IS NULL");
        $stmtArchiveMovimientos->execute(['closure_id' => $closureId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    }

    $stmtDeletePending = $pdo->prepare("REMOVED: pending orders are preserved by close_cash_day");
    $stmtDeletePending->execute(tenantParams($authContext));

    $pdo->commit();
    writeAuditLog($pdo, $authContext, 'cash.reset', 'tenant_day', $closureId);
    cacheInvalidateTenant('catalog', $authContext['tenant_id'], $authContext['branch_id']);
    echo json_encode(["status" => "success"]);
    */
}
