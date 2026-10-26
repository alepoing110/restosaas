<?php
// Refund (devolución) report handlers.

function refundRequireSchema(PDO $pdo): void {
    $stmt = $pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('pedido_reversiones', 'pedido_reversion_items')");
    $tables = array_unique($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if (count($tables) < 2) throw new RuntimeException('Devoluciones requiere ejecutar las migraciones v112 y v113');
}

function refundOrderItems(array $rawItems): array {
    $items = [];
    foreach (array_values($rawItems) as $index => $item) {
        if (!is_array($item)) continue;
        $quantity = max(0, (float)($item['qty'] ?? $item['quantity'] ?? 1));
        if ($quantity <= 0) continue;
        $unitPrice = (float)($item['price'] ?? 0);
        foreach (($item['salsas'] ?? []) as $salsa) $unitPrice += (float)($salsa['salsaPrice'] ?? 0);
        foreach (($item['accompaniments'] ?? []) as $accompaniment) $unitPrice += (float)($accompaniment['accompanimentPrice'] ?? 0);
        $items[] = [
            'line_no' => $index,
            'item_name' => (string)($item['name'] ?? 'Producto'),
            'item_type' => $item['type'] ?? null,
            'unit_price' => round($unitPrice, 2),
            'quantity' => $quantity,
        ];
    }
    return $items;
}

function handle_create_refund(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'orders');
    refundRequireSchema($pdo);

    $orderId = trim((string)($input['order_id'] ?? $input['id'] ?? ''));
    $scope = (string)($input['scope'] ?? 'pedido_completo');
    $method = (string)($input['refund_method'] ?? 'efectivo');
    $reason = trim((string)($input['reason'] ?? ''));
    $allowedScopes = ['producto_parcial', 'producto_total', 'pedido_completo', 'venta_completa'];
    $allowedMethods = ['efectivo', 'qr', 'tarjeta', 'transferencia', 'otro'];
    if ($orderId === '' || !in_array($scope, $allowedScopes, true) || !in_array($method, $allowedMethods, true) || $reason === '') {
        throw new InvalidArgumentException('Pedido, tipo, método y motivo son obligatorios');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM `pedidos` WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid FOR UPDATE");
        $stmt->execute(['id' => $orderId, 'tid' => $authContext['tenant_id'], 'bid' => $authContext['branch_id']]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) throw new InvalidArgumentException('Venta no encontrada');
        if ($order['status'] !== 'completado' && !($order['status'] === 'pendiente' && !empty($order['paid']))) {
            throw new InvalidArgumentException('Solo se pueden devolver ventas cobradas');
        }

        $orderItems = refundOrderItems(json_decode($order['items'] ?? '[]', true) ?: []);
        if (!$orderItems) throw new InvalidArgumentException('La venta no tiene productos devolvibles');

        if (!empty($order['closure_id'])) {
            $closedStmt = $pdo->prepare("SELECT 1 FROM `caja_cierres_historico` WHERE `id` = :cid AND `tenant_id` = :tid AND `branch_id` = :bid LIMIT 1");
            $closedStmt->execute(['cid' => $order['closure_id'], 'tid' => $authContext['tenant_id'], 'bid' => $authContext['branch_id']]);
            if ($closedStmt->fetchColumn()) {
                throw new InvalidArgumentException('Esta venta ya fue incluida en un cierre de caja. La devolución debe procesarse desde ajustes post-cierre.');
            }
        }

        $usedStmt = $pdo->prepare("SELECT ri.line_no, COALESCE(SUM(ri.quantity), 0) AS quantity FROM `pedido_reversion_items` ri INNER JOIN `pedido_reversiones` r ON r.id = ri.reversal_id WHERE ri.order_id = :oid AND ri.tenant_id = :tid AND ri.branch_id = :bid AND r.status <> 'anulada' GROUP BY ri.line_no");
        $usedStmt->execute(['oid' => $orderId, 'tid' => $authContext['tenant_id'], 'bid' => $authContext['branch_id']]);
        $used = [];
        foreach ($usedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $used[(int)$row['line_no']] = (float)$row['quantity'];

        $requested = is_array($input['items'] ?? null) ? $input['items'] : [];
        if (!$requested || in_array($scope, ['pedido_completo', 'venta_completa'], true)) {
            $requested = array_map(fn($item) => ['line_no' => $item['line_no'], 'quantity' => $item['quantity']], $orderItems);
        }
        $selected = [];
        foreach ($requested as $request) {
            $lineNo = (int)($request['line_no'] ?? -1);
            $source = null;
            foreach ($orderItems as $item) if ($item['line_no'] === $lineNo) { $source = $item; break; }
            if (!$source) throw new InvalidArgumentException('Producto de devolución no encontrado');
            $quantity = (float)($request['quantity'] ?? 0);
            $remaining = $source['quantity'] - ($used[$lineNo] ?? 0);
            if ($quantity <= 0 || $quantity > $remaining + 0.0001) throw new InvalidArgumentException('La cantidad a devolver excede la cantidad disponible');
            $source['quantity'] = $quantity;
            $source['line_total'] = round($source['unit_price'] * $quantity, 2);
            $selected[] = $source;
        }
        if (!$selected) throw new InvalidArgumentException('Seleccione al menos un producto');

        $total = round(array_sum(array_column($selected, 'line_total')), 2);
        if (in_array($scope, ['pedido_completo', 'venta_completa'], true)) $total = min((float)$order['total'], $total);
        if ($total <= 0) throw new InvalidArgumentException('El importe de devolución debe ser mayor a cero');

        $reversalId = 'rev_' . bin2hex(random_bytes(12));
        $userName = $authContext['user_name'] ?? $authContext['name'] ?? null;
        $insert = $pdo->prepare("INSERT INTO `pedido_reversiones` (id, order_id, scope, total_refunded, refund_method, reason, status, created_by, created_by_name, tenant_id, branch_id) VALUES (:id, :order_id, :scope, :total, :method, :reason, 'aplicada', :user_id, :user_name, :tid, :bid)");
        $insert->execute(['id' => $reversalId, 'order_id' => $orderId, 'scope' => $scope, 'total' => $total, 'method' => $method, 'reason' => $reason, 'user_id' => $authContext['user_id'] ?? null, 'user_name' => $userName, 'tid' => $authContext['tenant_id'], 'bid' => $authContext['branch_id']]);

        $insertItem = $pdo->prepare("INSERT INTO `pedido_reversion_items` (id, reversal_id, order_id, line_no, item_name, item_type, unit_price, quantity, line_total, tenant_id, branch_id) VALUES (:id, :reversal_id, :order_id, :line_no, :item_name, :item_type, :unit_price, :quantity, :line_total, :tid, :bid)");
        foreach ($selected as $item) {
            $insertItem->execute(['id' => 'revi_' . bin2hex(random_bytes(10)), 'reversal_id' => $reversalId, 'order_id' => $orderId, 'line_no' => $item['line_no'], 'item_name' => $item['item_name'], 'item_type' => $item['item_type'], 'unit_price' => $item['unit_price'], 'quantity' => $item['quantity'], 'line_total' => $item['line_total'], 'tid' => $authContext['tenant_id'], 'bid' => $authContext['branch_id']]);
        }

        $originalItems = json_decode($order['items'] ?? '[]', true) ?: [];
        $restoreStmt = $pdo->prepare("UPDATE `products` SET `stock` = `stock` + :qty WHERE `id` = :pid AND `tenant_id` = :tid AND `branch_id` = :bid");
        foreach ($selected as $item) {
            $orig = $originalItems[$item['line_no']] ?? null;
            if (!$orig) continue;
            $productId = $orig['sopaId'] ?? $orig['segundoId'] ?? $orig['platoId'] ?? $orig['extraId'] ?? $orig['product_id'] ?? null;
            if (!$productId) continue;
            $restoreStmt->execute(['qty' => (int)$item['quantity'], 'pid' => $productId, 'tid' => $authContext['tenant_id'], 'bid' => $authContext['branch_id']]);
        }

        $pdo->commit();
        writeAuditLog($pdo, $authContext, 'refund.apply', 'pedido_reversiones', $reversalId, ['order_id' => $orderId, 'scope' => $scope, 'total_refunded' => $total], $reason, null, ['status' => 'aplicada']);
        broadcastWebSocketEvent('order.refunded', ['id' => $orderId, 'reversal_id' => $reversalId], $authContext);
        echo json_encode(['status' => 'success', 'reversal_id' => $reversalId, 'total_refunded' => $total]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function handle_get_refunds_report(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'financial_reports');
    try {
        refundRequireSchema($pdo);
        [$start, $end, $startDate, $endDate] = financialDateRange($input);
        $selectedBranchId = isset($input['branch_id']) ? (string)$input['branch_id'] : '';
        [$branchIds, $branches] = financialBranchScope($pdo, $authContext, $selectedBranchId);
        $params = [':tid' => $authContext['tenant_id'], ':start' => $start, ':end' => $end];
        $branchSql = financialScopeSql($branchIds, $params, 'r');

        $refundStmt = $pdo->prepare("
            SELECT r.id, r.order_id, r.scope, r.total_refunded, r.refund_method, r.reason, r.status,
                   r.created_by, r.created_by_name, r.branch_id, r.created_at,
                   p.customer, p.total AS order_total, p.status AS order_status
            FROM `pedido_reversiones` r
            LEFT JOIN `pedidos` p ON p.id = r.order_id AND p.tenant_id = r.tenant_id AND p.branch_id = r.branch_id
            WHERE r.tenant_id = :tid AND r.branch_id IN ($branchSql)
              AND r.created_at >= :start AND r.created_at < :end
            ORDER BY r.created_at DESC
        ");
        $refundStmt->execute($params);
        $refunds = $refundStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $refundItemsStmt = $pdo->prepare("
            SELECT ri.reversal_id, ri.item_name, ri.item_type, ri.unit_price, ri.quantity, ri.line_total
            FROM `pedido_reversion_items` ri
            INNER JOIN `pedido_reversiones` r ON r.id = ri.reversal_id AND r.tenant_id = ri.tenant_id AND r.branch_id = ri.branch_id
            WHERE ri.tenant_id = :tid AND ri.branch_id IN ($branchSql)
              AND r.created_at >= :start AND r.created_at < :end
            ORDER BY ri.item_name
        ");
        $refundItemsStmt->execute($params);
        $allRefundItems = $refundItemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $itemsByReversal = [];
        foreach ($allRefundItems as $ri) {
            $rid = $ri['reversal_id'];
            if (!isset($itemsByReversal[$rid])) $itemsByReversal[$rid] = [];
            $itemsByReversal[$rid][] = $ri;
        }

        foreach ($refunds as &$refund) {
            $refund['total_refunded'] = (float)$refund['total_refunded'];
            $refund['order_total'] = (float)($refund['order_total'] ?? 0);
            $refund['items'] = $itemsByReversal[$refund['id']] ?? [];
            foreach ($refund['items'] as &$item) {
                $item['unit_price'] = (float)$item['unit_price'];
                $item['quantity'] = (float)$item['quantity'];
                $item['line_total'] = (float)$item['line_total'];
            }
            unset($item);
        }
        unset($refund);

        $totalRefunded = 0.0;
        $refundCount = 0;
        $cashRefunds = 0.0;
        $digitalRefunds = 0.0;
        $productReturned = [];
        $reasonCounts = [];
        $byMethod = ['efectivo' => 0.0, 'qr' => 0.0, 'tarjeta' => 0.0, 'transferencia' => 0.0, 'otro' => 0.0];
        $byScope = ['producto_parcial' => 0.0, 'producto_total' => 0.0, 'pedido_completo' => 0.0, 'venta_completa' => 0.0];
        $byBranch = [];

        foreach ($refunds as $refund) {
            if ($refund['status'] === 'anulada') continue;
            $totalRefunded += $refund['total_refunded'];
            $refundCount++;

            $method = $refund['refund_method'];
            if (in_array($method, array_keys($byMethod), true)) {
                $byMethod[$method] += $refund['total_refunded'];
            } else {
                $byMethod['otro'] += $refund['total_refunded'];
            }

            if ($method === 'efectivo') {
                $cashRefunds += $refund['total_refunded'];
            } else {
                $digitalRefunds += $refund['total_refunded'];
            }

            $scope = $refund['scope'];
            if (isset($byScope[$scope])) {
                $byScope[$scope] += $refund['total_refunded'];
            }

            $branchId = $refund['branch_id'];
            if (!isset($byBranch[$branchId])) {
                $branchName = '';
                foreach ($branches as $b) {
                    if ($b['id'] === $branchId) { $branchName = $b['name']; break; }
                }
                $byBranch[$branchId] = ['branch_id' => $branchId, 'branch_name' => $branchName, 'total_refunded' => 0.0, 'count' => 0];
            }
            $byBranch[$branchId]['total_refunded'] += $refund['total_refunded'];
            $byBranch[$branchId]['count']++;

            $reason = $refund['reason'] ?? 'Solicitud del cliente';
            $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;

            foreach ($refund['items'] as $item) {
                $name = $item['item_name'];
                $productReturned[$name] = ($productReturned[$name] ?? 0) + (int)$item['quantity'];
            }
        }

        arsort($productReturned);
        $topReturned = [];
        foreach (array_slice($productReturned, 0, 10, true) as $name => $qty) {
            $topReturned[] = ['name' => $name, 'quantity' => $qty];
        }

        arsort($reasonCounts);
        $topReasons = [];
        foreach (array_slice($reasonCounts, 0, 10, true) as $reason => $count) {
            $topReasons[] = ['reason' => $reason, 'count' => $count];
        }

        $dailyRefunds = [];
        foreach ($refunds as $refund) {
            if ($refund['status'] === 'anulada') continue;
            $day = substr($refund['created_at'], 0, 10);
            if (!isset($dailyRefunds[$day])) $dailyRefunds[$day] = ['date' => $day, 'total' => 0.0, 'count' => 0];
            $dailyRefunds[$day]['total'] += $refund['total_refunded'];
            $dailyRefunds[$day]['count']++;
        }
        usort($dailyRefunds, fn($a, $b) => strcmp($a['date'], $b['date']));

        echo json_encode([
            'status' => 'success',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'branch_id' => $selectedBranchId,
            'branches' => $branches,
            'summary' => [
                'total_refunded' => round($totalRefunded, 2),
                'refund_count' => $refundCount,
                'cash_refunds' => round($cashRefunds, 2),
                'digital_refunds' => round($digitalRefunds, 2),
                'most_returned_product' => $topReturned[0]['name'] ?? null,
                'most_returned_product_qty' => $topReturned[0]['quantity'] ?? 0,
                'most_common_reason' => $topReasons[0]['reason'] ?? null,
                'most_common_reason_count' => $topReasons[0]['count'] ?? 0,
            ],
            'by_method' => array_map(fn($v) => round($v, 2), $byMethod),
            'by_scope' => array_map(fn($v) => round($v, 2), $byScope),
            'by_branch' => array_values($byBranch),
            'top_returned_products' => $topReturned,
            'top_reasons' => $topReasons,
            'daily' => array_map(fn($row) => ['date' => $row['date'], 'total' => round($row['total'], 2), 'count' => $row['count']], $dailyRefunds),
            'refunds' => $refunds,
        ]);
    } catch (Throwable $e) {
        error_log('[RestoCloud][get_refunds_report] ' . $e->getMessage());
        http_response_code($e instanceof InvalidArgumentException ? 400 : ($e instanceof RuntimeException ? 503 : 500));
        echo json_encode(['status' => 'error', 'message' => $e instanceof InvalidArgumentException ? $e->getMessage() : ($e instanceof RuntimeException ? $e->getMessage() : 'No se pudo generar el reporte de devoluciones')]);
    }
}
