<?php
// Owner financial report and expense ledger handlers.

function financialBranchScope(PDO $pdo, array $authContext, ?string $branchId): array {
    $stmt = $pdo->prepare("SELECT b.id, b.name FROM `user_branch_access` uba INNER JOIN `branches` b ON b.id = uba.branch_id AND b.tenant_id = uba.tenant_id WHERE uba.user_id = :uid AND uba.tenant_id = :tid AND b.active = 1 ORDER BY b.name");
    $stmt->execute(['uid' => $authContext['user_id'], 'tid' => $authContext['tenant_id']]);
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [['id' => $authContext['branch_id'], 'name' => $authContext['branch_name']]];
    $allowed = array_column($branches, 'id');
    if ($branchId !== null && $branchId !== '' && !in_array($branchId, $allowed, true)) {
        throw new InvalidArgumentException('Sucursal no autorizada');
    }
    $selected = $branchId !== null && $branchId !== '' ? [$branchId] : $allowed;
    if (!$selected) throw new RuntimeException('No hay sucursales autorizadas para este usuario');
    return [$selected, $branches];
}

function financialScopeSql(array $branchIds, array &$params, string $prefix = 'b'): string {
    $tokens = [];
    foreach (array_values($branchIds) as $i => $id) {
        $key = ':' . $prefix . $i;
        $tokens[] = $key;
        $params[$key] = $id;
    }
    return implode(',', $tokens);
}

function financialDateRange(array $input): array {
    $start = trim((string)($input['start_date'] ?? date('Y-m-d', strtotime('-6 days'))));
    $end = trim((string)($input['end_date'] ?? date('Y-m-d')));
    $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
    $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
    $startErrors = DateTimeImmutable::getLastErrors();
    $invalidErrors = is_array($startErrors) && ($startErrors['warning_count'] || $startErrors['error_count']);
    if (!$startDate || !$endDate || $invalidErrors || $startDate->format('Y-m-d') !== $start || $endDate->format('Y-m-d') !== $end || $start > $end) {
        throw new InvalidArgumentException('Rango de fechas inválido');
    }
    return [$start . ' 00:00:00', $endDate->modify('+1 day')->format('Y-m-d') . ' 00:00:00', $start, $end];
}

function financialRequireSchema(PDO $pdo): void {
    $stmt = $pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('gastos_financieros', 'user_branch_access')");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count(array_unique($tables)) < 2) throw new RuntimeException('Finanzas requiere ejecutar las migraciones de base de datos');
}

function financialPaymentParts(string $method, float $total): array {
    $parts = ['efectivo' => 0.0, 'qr' => 0.0, 'tarjeta' => 0.0, 'transferencia' => 0.0, 'otro' => 0.0];
    $decoded = json_decode($method, true);
    if (is_array($decoded)) {
        foreach ($parts as $key => $_) $parts[$key] = (float)($decoded[$key] ?? 0);
        return $parts;
    }
    $key = in_array($method, array_keys($parts), true) ? $method : 'efectivo';
    $parts[$key] = $total;
    return $parts;
}

function handle_get_financial_report(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'financial_reports');
    try {
        financialRequireSchema($pdo);
        [$start, $end, $startDate, $endDate] = financialDateRange($input);
        $selectedBranchId = isset($input['branch_id']) ? (string)$input['branch_id'] : '';
        [$branchIds, $branches] = financialBranchScope($pdo, $authContext, $selectedBranchId);
        $params = [':tid' => $authContext['tenant_id'], ':start' => $start, ':end' => $end];
        $branchSql = financialScopeSql($branchIds, $params);

        $salesStmt = $pdo->prepare("SELECT `total`, `payment_method`, `status`, `items`, `branch_id`, COALESCE(`sold_at`, `timestamp`) AS sale_time FROM `pedidos` WHERE `tenant_id` = :tid AND `branch_id` IN ($branchSql) AND COALESCE(`sold_at`, `timestamp`) >= :start AND COALESCE(`sold_at`, `timestamp`) < :end AND (`status` IN ('completado', 'anulado') OR (`status` = 'pendiente' AND `paid` = 1))");
        $salesStmt->execute($params);
        $summaryStmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN status = 'completado' OR (status = 'pendiente' AND paid = 1) THEN total ELSE 0 END), 0) AS gross_sales, COUNT(CASE WHEN status = 'completado' OR (status = 'pendiente' AND paid = 1) THEN 1 END) AS sales_count, COALESCE(SUM(CASE WHEN status = 'anulado' THEN total ELSE 0 END), 0) AS annulled_amount, COUNT(CASE WHEN status = 'anulado' THEN 1 END) AS annulled_count FROM pedidos WHERE tenant_id = :tid AND branch_id IN ($branchSql) AND COALESCE(sold_at, timestamp) >= :start AND COALESCE(sold_at, timestamp) < :end AND (status IN ('completado', 'anulado') OR (status = 'pendiente' AND paid = 1))");
         $summaryStmt->execute($params);
         $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
         $summary = ['gross_sales' => (float)($summaryRow['gross_sales'] ?? 0), 'annulled_amount' => (float)($summaryRow['annulled_amount'] ?? 0), 'sales_count' => (int)($summaryRow['sales_count'] ?? 0), 'annulled_count' => (int)($summaryRow['annulled_count'] ?? 0)];
         $discountTotalStmt = $pdo->prepare("SELECT COALESCE(SUM(pd.amount), 0) FROM `pedido_descuentos` pd INNER JOIN `pedidos` p ON p.id = pd.order_id AND p.tenant_id = pd.tenant_id AND p.branch_id = pd.branch_id WHERE pd.tenant_id = :tid AND pd.branch_id IN ($branchSql) AND COALESCE(p.sold_at, p.timestamp) >= :start AND COALESCE(p.sold_at, p.timestamp) < :end AND (p.status = 'completado' OR (p.status = 'pendiente' AND p.paid = 1))");
         $discountTotalStmt->execute($params);
         $summary['discount_amount'] = (float)$discountTotalStmt->fetchColumn();
         $refundTotalParams = [':tid' => $authContext['tenant_id'], ':start' => $start, ':end' => $end];
         $refundTotalBranches = financialScopeSql($branchIds, $refundTotalParams, 'rt');
         $refundTotalStmt = $pdo->prepare("SELECT COALESCE(SUM(r.total_refunded), 0) FROM `pedido_reversiones` r WHERE r.tenant_id = :tid AND r.branch_id IN ($refundTotalBranches) AND r.created_at >= :start AND r.created_at < :end AND r.status != 'anulada'");
         $refundTotalStmt->execute($refundTotalParams);
         $summary['refund_amount'] = (float)$refundTotalStmt->fetchColumn();
         $summary['net_sales'] = $summary['gross_sales'] - $summary['refund_amount'];
         $summary['gross_sales'] += $summary['discount_amount'];
         $payments = ['efectivo' => 0.0, 'qr' => 0.0, 'tarjeta' => 0.0, 'transferencia' => 0.0, 'otro' => 0.0];
         $normalizedPaymentParams = [':tid' => $authContext['tenant_id'], ':start' => $start, ':end' => $end];
         $normalizedPaymentBranches = financialScopeSql($branchIds, $normalizedPaymentParams, 'np');
         $normalizedPaymentStmt = $pdo->prepare("SELECT pp.payment_method, COALESCE(SUM(pp.amount), 0) AS amount FROM `pedido_pagos` pp INNER JOIN `pedidos` p ON p.id = pp.order_id AND p.tenant_id = pp.tenant_id AND p.branch_id = pp.branch_id WHERE pp.tenant_id = :tid AND pp.branch_id IN ($normalizedPaymentBranches) AND COALESCE(p.sold_at, p.timestamp) >= :start AND COALESCE(p.sold_at, p.timestamp) < :end AND (p.status = 'completado' OR (p.status = 'pendiente' AND p.paid = 1)) GROUP BY pp.payment_method");
         $normalizedPaymentStmt->execute($normalizedPaymentParams);
         $normalizedPaymentsFound = false;
         foreach ($normalizedPaymentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
             $method = in_array($row['payment_method'], array_keys($payments), true) ? $row['payment_method'] : 'otro';
             $payments[$method] += (float)$row['amount'];
             $normalizedPaymentsFound = true;
         }
         $daily = [];
        $dailyStmt = $pdo->prepare("SELECT DATE(COALESCE(sold_at, timestamp)) AS day, COALESCE(SUM(total), 0) AS sales, COUNT(*) AS count FROM pedidos WHERE tenant_id = :tid AND branch_id IN ($branchSql) AND COALESCE(sold_at, timestamp) >= :start AND COALESCE(sold_at, timestamp) < :end AND (status = 'completado' OR (status = 'pendiente' AND paid = 1)) GROUP BY DATE(COALESCE(sold_at, timestamp)) ORDER BY day ASC");
        $dailyStmt->execute($params);
        foreach ($dailyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $daily[$row['day']] = ['date' => $row['day'], 'sales' => (float)$row['sales'], 'count' => (int)$row['count'], 'expenses' => 0.0];
         $products = [];
         $normalizedProductParams = [':tid' => $authContext['tenant_id'], ':start' => $start, ':end' => $end];
         $normalizedProductBranches = financialScopeSql($branchIds, $normalizedProductParams, 'nprod');
         $normalizedProductStmt = $pdo->prepare("SELECT pi.item_name AS name, COALESCE(SUM(pi.quantity * pi.unit_price), 0) AS revenue FROM `pedido_items` pi INNER JOIN `pedidos` p ON p.id = pi.order_id AND p.tenant_id = pi.tenant_id AND p.branch_id = pi.branch_id WHERE pi.tenant_id = :tid AND pi.branch_id IN ($normalizedProductBranches) AND COALESCE(p.sold_at, p.timestamp) >= :start AND COALESCE(p.sold_at, p.timestamp) < :end AND (p.status = 'completado' OR (p.status = 'pendiente' AND p.paid = 1)) GROUP BY pi.item_name ORDER BY revenue DESC");
         $normalizedProductStmt->execute($normalizedProductParams);
         foreach ($normalizedProductStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $products[$row['name']] = (float)$row['revenue'];
         while ($sale = $salesStmt->fetch(PDO::FETCH_ASSOC)) {
             $total = (float)$sale['total'];
             if ($sale['status'] === 'anulado') continue;
             if (!$normalizedPaymentsFound) foreach (financialPaymentParts((string)$sale['payment_method'], $total) as $key => $amount) $payments[$key] += $amount;
             $day = substr($sale['sale_time'], 0, 10);
            if (!isset($daily[$day])) $daily[$day] = ['date' => $day, 'sales' => 0.0, 'count' => 0, 'expenses' => 0.0];
             if ($products) continue;
             foreach (json_decode($sale['items'], true) ?: [] as $item) {
                $name = trim((string)($item['name'] ?? 'Producto')) ?: 'Producto';
                $qty = (int)($item['quantity'] ?? $item['qty'] ?? 1);
                $products[$name] = ($products[$name] ?? 0) + ($qty * (float)($item['price'] ?? 0));
            }
        }

        $legacyParams = [':tid' => $authContext['tenant_id'], ':start' => $start, ':end' => $end];
        $legacyBranches = financialScopeSql($branchIds, $legacyParams, 'lb');
        $legacyStmt = $pdo->prepare("SELECT `description`, `amount`, `branch_id`, `timestamp` FROM `caja_movimientos` WHERE `tenant_id` = :tid AND `branch_id` IN ($legacyBranches) AND `type` = 'egreso' AND `timestamp` >= :start AND `timestamp` < :end ORDER BY `timestamp` DESC");
        $legacyStmt->execute($legacyParams);
        $legacyExpenses = [];
        $legacyTotal = 0.0;
        while ($row = $legacyStmt->fetch(PDO::FETCH_ASSOC)) {
            $amount = (float)$row['amount'];
            $legacyTotal += $amount;
            $day = substr($row['timestamp'], 0, 10);
            if (!isset($daily[$day])) $daily[$day] = ['date' => $day, 'sales' => 0.0, 'count' => 0, 'expenses' => 0.0];
            $daily[$day]['expenses'] += $amount;
            $legacyExpenses[] = ['id' => null, 'description' => $row['description'], 'category' => 'caja', 'amount' => $amount, 'payment_method' => 'efectivo', 'date' => $day, 'legacy' => true];
        }

        $expenseParams = [':tid' => $authContext['tenant_id'], ':start_date' => $startDate, ':end_date' => $endDate];
        $expenseBranches = financialScopeSql($branchIds, $expenseParams, 'eb');
        $expenseStmt = $pdo->prepare("SELECT id, fecha AS date, description, category, amount, payment_method, reference, employee_name, payment_period, branch_id FROM `gastos_financieros` WHERE tenant_id = :tid AND branch_id IN ($expenseBranches) AND fecha >= :start_date AND fecha <= :end_date ORDER BY fecha DESC, created_at DESC");
        $expenseStmt->execute($expenseParams);
        $expenses = $legacyExpenses;
        $financialTotal = 0.0;
        $cashExpenses = $legacyTotal;
        $byCategory = ['caja' => $legacyTotal];
        $byPayment = ['efectivo' => $legacyTotal];
        while ($row = $expenseStmt->fetch(PDO::FETCH_ASSOC)) {
            $row['amount'] = (float)$row['amount'];
            $expenses[] = $row;
            $financialTotal += $row['amount'];
            if ($row['payment_method'] === 'efectivo') $cashExpenses += $row['amount'];
            $byCategory[$row['category']] = ($byCategory[$row['category']] ?? 0) + $row['amount'];
            $byPayment[$row['payment_method']] = ($byPayment[$row['payment_method']] ?? 0) + $row['amount'];
            $day = $row['date'];
            if (!isset($daily[$day])) $daily[$day] = ['date' => $day, 'sales' => 0.0, 'count' => 0, 'expenses' => 0.0];
            $daily[$day]['expenses'] += $row['amount'];
        }
        foreach ($daily as &$row) { $row['sales'] = round($row['sales'], 2); $row['expenses'] = round($row['expenses'], 2); }
        unset($row);
        usort($daily, fn($a, $b) => strcmp($a['date'], $b['date']));
        arsort($products);
        $topProducts = [];
        foreach (array_slice($products, 0, 10, true) as $name => $amount) $topProducts[] = ['name' => $name, 'revenue' => round($amount, 2)];
        foreach ($byCategory as &$value) $value = round($value, 2); foreach ($byPayment as &$value) $value = round($value, 2);
         echo json_encode(['status' => 'success', 'start_date' => $startDate, 'end_date' => $endDate, 'branch_id' => $selectedBranchId, 'branches' => $branches, 'summary' => [
             'gross_sales' => round($summary['gross_sales'], 2), 'discount_amount' => round($summary['discount_amount'], 2), 'refund_amount' => round($summary['refund_amount'], 2), 'annulled_amount' => round($summary['annulled_amount'], 2), 'net_sales' => round($summary['net_sales'], 2), 'sales_count' => $summary['sales_count'], 'annulled_count' => $summary['annulled_count'], 'cash_legacy_expenses' => round($legacyTotal, 2), 'financial_expenses' => round($financialTotal, 2), 'total_expenses' => round($legacyTotal + $financialTotal, 2), 'cash_expenses' => round($cashExpenses, 2), 'operating_profit' => round($summary['net_sales'] - $legacyTotal - $financialTotal, 2), 'average_ticket' => $summary['sales_count'] ? round($summary['net_sales'] / $summary['sales_count'], 2) : 0
        ], 'payments' => array_map(fn($v) => round($v, 2), $payments), 'expenses_by_category' => $byCategory, 'expenses_by_payment' => $byPayment, 'daily' => $daily, 'top_products' => $topProducts, 'expenses' => $expenses]);
    } catch (Throwable $e) {
        error_log('[RestoCloud][get_financial_report] ' . $e->getMessage());
        http_response_code($e instanceof InvalidArgumentException ? 400 : ($e instanceof RuntimeException ? 503 : 500));
        echo json_encode(['status' => 'error', 'message' => $e instanceof InvalidArgumentException ? $e->getMessage() : ($e instanceof RuntimeException ? $e->getMessage() : 'No se pudo generar el reporte financiero')]);
    }
}

function handle_get_branch_comparison(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'financial_reports');
    try {
        [$start, $end, $startDate, $endDate] = financialDateRange($input);
        [$branchIds, $branches] = financialBranchScope($pdo, $authContext, '');
        $params = [':tid' => $authContext['tenant_id'], ':start' => $start, ':end' => $end];
        $branchSql = financialScopeSql($branchIds, $params, 'bc');
        $result = [];
        foreach ($branches as $branch) {
            if (!in_array($branch['id'], $branchIds, true)) continue;
            $result[$branch['id']] = ['branch_id' => $branch['id'], 'branch_name' => $branch['name'], 'net_sales' => 0.0, 'sales_count' => 0, 'annulled_amount' => 0.0, 'annulled_count' => 0, 'expenses' => 0.0];
        }
        $salesStmt = $pdo->prepare("SELECT branch_id, COALESCE(SUM(CASE WHEN status = 'completado' OR (status = 'pendiente' AND paid = 1) THEN total ELSE 0 END), 0) AS net_sales, COUNT(CASE WHEN status = 'completado' OR (status = 'pendiente' AND paid = 1) THEN 1 END) AS sales_count, COALESCE(SUM(CASE WHEN status = 'anulado' THEN total ELSE 0 END), 0) AS annulled_amount, COUNT(CASE WHEN status = 'anulado' THEN 1 END) AS annulled_count FROM `pedidos` WHERE tenant_id = :tid AND branch_id IN ($branchSql) AND COALESCE(sold_at, timestamp) >= :start AND COALESCE(sold_at, timestamp) < :end AND (status IN ('completado', 'anulado') OR (status = 'pendiente' AND paid = 1)) GROUP BY branch_id");
        $salesStmt->execute($params);
        foreach ($salesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!isset($result[$row['branch_id']])) continue;
            $result[$row['branch_id']]['net_sales'] = (float)$row['net_sales'];
            $result[$row['branch_id']]['sales_count'] = (int)$row['sales_count'];
            $result[$row['branch_id']]['annulled_amount'] = (float)$row['annulled_amount'];
            $result[$row['branch_id']]['annulled_count'] = (int)$row['annulled_count'];
        }
        $expenseParams = [':tid' => $authContext['tenant_id'], ':start_date' => $startDate, ':end_date' => $endDate];
        $expenseBranches = financialScopeSql($branchIds, $expenseParams, 'bce');
        $expenseStmt = $pdo->prepare("SELECT branch_id, COALESCE(SUM(amount), 0) AS expenses FROM `gastos_financieros` WHERE tenant_id = :tid AND branch_id IN ($expenseBranches) AND fecha >= :start_date AND fecha <= :end_date GROUP BY branch_id");
        $expenseStmt->execute($expenseParams);
        foreach ($expenseStmt->fetchAll(PDO::FETCH_ASSOC) as $row) if (isset($result[$row['branch_id']])) $result[$row['branch_id']]['expenses'] += (float)$row['expenses'];
        $legacyParams = [':tid' => $authContext['tenant_id'], ':start' => $start, ':end' => $end];
        $legacyBranches = financialScopeSql($branchIds, $legacyParams, 'bcl');
        $legacyStmt = $pdo->prepare("SELECT branch_id, COALESCE(SUM(amount), 0) AS expenses FROM `caja_movimientos` WHERE tenant_id = :tid AND branch_id IN ($legacyBranches) AND type = 'egreso' AND timestamp >= :start AND timestamp < :end GROUP BY branch_id");
        $legacyStmt->execute($legacyParams);
        foreach ($legacyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) if (isset($result[$row['branch_id']])) $result[$row['branch_id']]['expenses'] += (float)$row['expenses'];
        foreach ($result as &$row) {
            $row['expenses'] = round($row['expenses'], 2);
            $row['operating_profit'] = round($row['net_sales'] - $row['expenses'], 2);
            $row['average_ticket'] = $row['sales_count'] > 0 ? round($row['net_sales'] / $row['sales_count'], 2) : 0.0;
            $row['net_sales'] = round($row['net_sales'], 2);
            $row['annulled_amount'] = round($row['annulled_amount'], 2);
        }
        unset($row);
        usort($result, fn($a, $b) => $b['net_sales'] <=> $a['net_sales']);
        echo json_encode(['status' => 'success', 'start_date' => $startDate, 'end_date' => $endDate, 'branches' => array_values($result)]);
    } catch (Throwable $e) {
        error_log('[RestoCloud][get_branch_comparison] ' . $e->getMessage());
        http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
        echo json_encode(['status' => 'error', 'message' => $e instanceof InvalidArgumentException ? $e->getMessage() : 'No se pudo generar la comparación por sucursal']);
    }
}

function handle_get_cash_flow(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'financial_reports');
    try {
        [$start, $end, $startDate, $endDate] = financialDateRange($input);
        [$branchIds] = financialBranchScope($pdo, $authContext, '');
        $days = [];
        $cursor = new DateTimeImmutable($startDate);
        $last = new DateTimeImmutable($endDate);
        while ($cursor <= $last) {
            $key = $cursor->format('Y-m-d');
            $days[$key] = ['date' => $key, 'opening' => 0.0, 'cash_sales' => 0.0, 'cash_expenses' => 0.0, 'expected_closing' => 0.0, 'actual_closing' => null, 'variance' => null];
            $cursor = $cursor->modify('+1 day');
        }
        $params = [':tid' => $authContext['tenant_id'], ':start' => $start, ':end' => $end];
        $branchSql = financialScopeSql($branchIds, $params, 'cf');
        $openingStmt = $pdo->prepare("SELECT DATE(timestamp) AS day, COALESCE(SUM(CASE WHEN type = 'apertura' THEN amount ELSE 0 END), 0) AS opening, COALESCE(SUM(CASE WHEN type = 'egreso' THEN amount ELSE 0 END), 0) AS legacy_expenses FROM `caja_movimientos` WHERE tenant_id = :tid AND branch_id IN ($branchSql) AND timestamp >= :start AND timestamp < :end GROUP BY DATE(timestamp)");
        $openingStmt->execute($params);
        foreach ($openingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) if (isset($days[$row['day']])) { $days[$row['day']]['opening'] += (float)$row['opening']; $days[$row['day']]['cash_expenses'] += (float)$row['legacy_expenses']; }
        $salesStmt = $pdo->prepare("SELECT DATE(COALESCE(p.sold_at, p.timestamp)) AS day, COALESCE(SUM(pp.amount), 0) AS cash_sales FROM `pedido_pagos` pp INNER JOIN `pedidos` p ON p.id = pp.order_id AND p.tenant_id = pp.tenant_id AND p.branch_id = pp.branch_id WHERE pp.tenant_id = :tid AND pp.branch_id IN ($branchSql) AND pp.payment_method = 'efectivo' AND COALESCE(p.sold_at, p.timestamp) >= :start AND COALESCE(p.sold_at, p.timestamp) < :end AND (p.status = 'completado' OR (p.status = 'pendiente' AND p.paid = 1)) GROUP BY DATE(COALESCE(p.sold_at, p.timestamp))");
        $salesStmt->execute($params);
        foreach ($salesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) if (isset($days[$row['day']])) $days[$row['day']]['cash_sales'] += (float)$row['cash_sales'];
        $expenseParams = [':tid' => $authContext['tenant_id'], ':start_date' => $startDate, ':end_date' => $endDate];
        $expenseBranches = financialScopeSql($branchIds, $expenseParams, 'cfe');
        $expenseStmt = $pdo->prepare("SELECT fecha AS day, COALESCE(SUM(amount), 0) AS cash_expenses FROM `gastos_financieros` WHERE tenant_id = :tid AND branch_id IN ($expenseBranches) AND payment_method = 'efectivo' AND fecha >= :start_date AND fecha <= :end_date GROUP BY fecha");
        $expenseStmt->execute($expenseParams);
        foreach ($expenseStmt->fetchAll(PDO::FETCH_ASSOC) as $row) if (isset($days[$row['day']])) $days[$row['day']]['cash_expenses'] += (float)$row['cash_expenses'];
        $closureParams = [':tid' => $authContext['tenant_id'], ':start_date' => $startDate, ':end_date' => $endDate];
        $closureBranches = financialScopeSql($branchIds, $closureParams, 'cfc');
        $closureStmt = $pdo->prepare("SELECT fecha AS day, COALESCE(SUM(efectivo_real), 0) AS actual_closing, COALESCE(SUM(diferencia), 0) AS variance FROM `caja_cierres_historico` WHERE tenant_id = :tid AND branch_id IN ($closureBranches) AND fecha >= :start_date AND fecha <= :end_date GROUP BY fecha");
        $closureStmt->execute($closureParams);
        foreach ($closureStmt->fetchAll(PDO::FETCH_ASSOC) as $row) if (isset($days[$row['day']])) { $days[$row['day']]['actual_closing'] = (float)$row['actual_closing']; $days[$row['day']]['variance'] = (float)$row['variance']; }
        $openingCarry = 0.0;
        foreach ($days as &$row) {
            $row['opening'] += $openingCarry;
            $row['expected_closing'] = $row['opening'] + $row['cash_sales'] - $row['cash_expenses'];
            $row['variance'] = $row['actual_closing'] === null ? null : round($row['actual_closing'] - $row['expected_closing'], 2);
            $openingCarry = $row['expected_closing'];
            foreach (['opening', 'cash_sales', 'cash_expenses', 'expected_closing', 'actual_closing', 'variance'] as $key) if ($row[$key] !== null) $row[$key] = round($row[$key], 2);
        }
        unset($row);
        $firstDay = reset($days) ?: ['opening' => 0];
        echo json_encode(['status' => 'success', 'start_date' => $startDate, 'end_date' => $endDate, 'summary' => ['opening' => round((float)$firstDay['opening'], 2), 'cash_sales' => round(array_sum(array_column($days, 'cash_sales')), 2), 'cash_expenses' => round(array_sum(array_column($days, 'cash_expenses')), 2), 'expected_closing' => round($openingCarry, 2)], 'daily' => array_values($days)]);
    } catch (Throwable $e) {
        error_log('[RestoCloud][get_cash_flow] ' . $e->getMessage());
        http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
        echo json_encode(['status' => 'error', 'message' => $e instanceof InvalidArgumentException ? $e->getMessage() : 'No se pudo generar el flujo de caja']);
    }
}

function handle_get_control_report(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'financial_reports');
    try {
        [$start, $end, $startDate, $endDate] = financialDateRange($input);
        [$branchIds] = financialBranchScope($pdo, $authContext, '');
        $params = [':tid' => $authContext['tenant_id'], ':start' => $start, ':end' => $end];
        $branchSql = financialScopeSql($branchIds, $params, 'ctl');
        $discountStmt = $pdo->prepare("SELECT pd.order_id, pd.discount_id, pd.discount_name, pd.discount_type, pd.amount, pd.created_at, p.customer, p.total, p.branch_id FROM `pedido_descuentos` pd INNER JOIN `pedidos` p ON p.id = pd.order_id AND p.tenant_id = pd.tenant_id AND p.branch_id = pd.branch_id WHERE pd.tenant_id = :tid AND pd.branch_id IN ($branchSql) AND COALESCE(p.sold_at, p.timestamp) >= :start AND COALESCE(p.sold_at, p.timestamp) < :end ORDER BY pd.created_at DESC");
        $discountStmt->execute($params);
        $discounts = $discountStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $discountSummary = ['count' => count($discounts), 'amount' => 0.0];
        foreach ($discounts as &$discount) { $discount['amount'] = (float)$discount['amount']; $discountSummary['amount'] += $discount['amount']; }
        unset($discount);
        $annulStmt = $pdo->prepare("SELECT p.id, p.customer, p.total, p.status, COALESCE(p.sold_at, p.timestamp) AS event_time, p.branch_id, (SELECT al.reason FROM `audit_logs` al WHERE al.tenant_id = p.tenant_id AND al.entity_id = p.id AND al.action IN ('sale.annul', 'order.cancel') AND al.reason IS NOT NULL ORDER BY al.created_at DESC LIMIT 1) AS reason FROM `pedidos` p WHERE p.tenant_id = :tid AND p.branch_id IN ($branchSql) AND COALESCE(p.sold_at, p.timestamp) >= :start AND COALESCE(p.sold_at, p.timestamp) < :end AND p.status = 'anulado' ORDER BY event_time DESC");
        $annulStmt->execute($params);
        $annulled = $annulStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $annulSummary = ['count' => count($annulled), 'amount' => 0.0];
        foreach ($annulled as &$sale) { $sale['total'] = (float)$sale['total']; $annulSummary['amount'] += $sale['total']; }
        unset($sale);
        $hourStmt = $pdo->prepare("SELECT HOUR(COALESCE(sold_at, timestamp)) AS hour, COUNT(*) AS orders, COALESCE(SUM(total), 0) AS sales FROM `pedidos` WHERE tenant_id = :tid AND branch_id IN ($branchSql) AND COALESCE(sold_at, timestamp) >= :start AND COALESCE(sold_at, timestamp) < :end AND (status = 'completado' OR (status = 'pendiente' AND paid = 1)) GROUP BY HOUR(COALESCE(sold_at, timestamp)) ORDER BY hour");
        $hourStmt->execute($params);
        $hourly = $hourStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($hourly as &$hour) { $hour['hour'] = (int)$hour['hour']; $hour['orders'] = (int)$hour['orders']; $hour['sales'] = (float)$hour['sales']; }
        unset($hour);
        $refundCtrlParams = [':tid' => $authContext['tenant_id'], ':start' => $start, ':end' => $end];
        $refundCtrlBranches = financialScopeSql($branchIds, $refundCtrlParams, 'rctl');
        $refundCtrlStmt = $pdo->prepare("SELECT COUNT(*) AS refund_count, COALESCE(SUM(total_refunded), 0) AS refund_total FROM `pedido_reversiones` WHERE tenant_id = :tid AND branch_id IN ($refundCtrlBranches) AND created_at >= :start AND created_at < :end AND status != 'anulada'");
        $refundCtrlStmt->execute($refundCtrlParams);
        $refundCtrlRow = $refundCtrlStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $refundSummary = ['count' => (int)($refundCtrlRow['refund_count'] ?? 0), 'amount' => (float)($refundCtrlRow['refund_total'] ?? 0)];
        echo json_encode(['status' => 'success', 'start_date' => $startDate, 'end_date' => $endDate, 'discount_summary' => ['count' => $discountSummary['count'], 'amount' => round($discountSummary['amount'], 2)], 'annulled_summary' => ['count' => $annulSummary['count'], 'amount' => round($annulSummary['amount'], 2)], 'refund_summary' => ['count' => $refundSummary['count'], 'amount' => round($refundSummary['amount'], 2)], 'discounts' => $discounts, 'annulled' => $annulled, 'hourly' => $hourly]);
    } catch (Throwable $e) {
        error_log('[RestoCloud][get_control_report] ' . $e->getMessage());
        http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
        echo json_encode(['status' => 'error', 'message' => $e instanceof InvalidArgumentException ? $e->getMessage() : 'No se pudo generar el reporte de control']);
    }
}

function handle_save_financial_expense(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'financial_reports');
    try {
        [$branchIds] = financialBranchScope($pdo, $authContext, (string)($input['branch_id'] ?? ''));
        $branchId = (string)($input['branch_id'] ?? '');
        if ($branchId === '' || !in_array($branchId, $branchIds, true)) throw new InvalidArgumentException('Seleccione una sucursal válida');
        $date = trim((string)($input['date'] ?? date('Y-m-d')));
        $category = trim((string)($input['category'] ?? 'otros'));
        $payment = trim((string)($input['payment_method'] ?? 'efectivo'));
        $allowedCategories = ['compras', 'sueldos', 'alquiler', 'servicios', 'transporte', 'impuestos', 'marketing', 'mantenimiento', 'otros'];
        $allowedPayments = ['efectivo', 'qr', 'tarjeta', 'transferencia', 'otro'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || trim((string)($input['description'] ?? '')) === '' || !is_numeric($input['amount'] ?? null) || (float)$input['amount'] <= 0 || !in_array($category, $allowedCategories, true) || !in_array($payment, $allowedPayments, true)) throw new InvalidArgumentException('Datos del gasto inválidos');
        $closureStmt = $pdo->prepare("SELECT 1 FROM `caja_cierres_historico` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `fecha` = :date LIMIT 1");
        $closureStmt->execute(['tenant_id' => $authContext['tenant_id'], 'branch_id' => $branchId, 'date' => $date]);
        if ($closureStmt->fetchColumn()) throw new InvalidArgumentException('El día ya está cerrado. Registre el gasto en la fecha operativa actual.');
        $employeeName = $category === 'sueldos' ? trim((string)($input['employee_name'] ?? '')) : null;
        $paymentPeriod = $category === 'sueldos' ? trim((string)($input['payment_period'] ?? '')) : null;
        if ($category === 'sueldos' && ($employeeName === '' || $paymentPeriod === '')) throw new InvalidArgumentException('Para sueldos, el nombre del empleado y el periodo son obligatorios');
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO `gastos_financieros` (id, fecha, description, category, amount, payment_method, reference, employee_name, payment_period, tenant_id, branch_id, created_by) VALUES (:id, :fecha, :description, :category, :amount, :payment, :reference, :employee_name, :payment_period, :tenant_id, :branch_id, :created_by)");
        $stmt->execute(['id' => trim((string)($input['id'] ?? ('exp_' . bin2hex(random_bytes(8))))), 'fecha' => $date, 'description' => trim($input['description']), 'category' => $category, 'amount' => (float)$input['amount'], 'payment' => $payment, 'reference' => trim((string)($input['reference'] ?? '')) ?: null, 'employee_name' => $employeeName ?: null, 'payment_period' => $paymentPeriod ?: null, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $branchId, 'created_by' => $authContext['user_id']]);
        writeAuditLog($pdo, $authContext, 'financial.expense.save', 'gastos_financieros');
        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } catch (Throwable $e) { http_response_code($e instanceof InvalidArgumentException ? 400 : 500); echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); }
}

function handle_delete_financial_expense(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'financial_reports');
    $requestedBranch = trim((string)($input['branch_id'] ?? ''));
    if ($requestedBranch === '') { http_response_code(400); echo json_encode(['status' => 'error', 'message' => 'Sucursal requerida para eliminar el gasto']); return; }
    try { [$branchIds] = financialBranchScope($pdo, $authContext, $requestedBranch); } catch (Throwable $e) { http_response_code(403); echo json_encode(['status' => 'error', 'message' => 'Sucursal no autorizada']); return; }
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("DELETE FROM `gastos_financieros` WHERE id = :id AND tenant_id = :tenant_id AND branch_id = :branch_id AND closure_id IS NULL");
    $stmt->execute(['id' => $input['id'] ?? '', 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $branchIds[0]]);
    if (!$stmt->rowCount()) { http_response_code(404); echo json_encode(['status' => 'error', 'message' => 'Gasto no encontrado']); return; }
    writeAuditLog($pdo, $authContext, 'financial.expense.delete', 'gastos_financieros', $input['id']);
    $pdo->commit();
    echo json_encode(['status' => 'success']);
}
