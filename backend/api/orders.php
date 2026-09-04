<?php
// Order action handlers

function orderHistoryItems(array $items): array {
    $rows = [];
    foreach (array_values($items) as $lineNo => $item) {
        if (!is_array($item)) continue;
        $quantity = (float)($item['quantity'] ?? $item['qty'] ?? 1);
        if ($quantity <= 0) $quantity = 1;
        $unitPrice = (float)($item['price'] ?? $item['unitPrice'] ?? 0);
        $name = trim((string)($item['name'] ?? $item['itemName'] ?? 'Producto')) ?: 'Producto';
        $productId = $item['productId'] ?? $item['product_id'] ?? $item['id'] ?? $item['segundoId'] ?? $item['sopaId'] ?? $item['platoId'] ?? $item['extraId'] ?? null;
        $rows[] = [
            'line_no' => $lineNo,
            'product_id' => $productId !== null && $productId !== '' ? (string)$productId : null,
            'item_type' => trim((string)($item['type'] ?? '')) ?: null,
            'item_name' => $name,
            'category_id' => ($item['categoryId'] ?? $item['category_id'] ?? null) ?: null,
            'category_name' => ($item['categoryName'] ?? $item['category_name'] ?? $item['category'] ?? null) ?: null,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'line_total' => round($unitPrice * $quantity, 2),
            'unit_cost' => is_numeric($item['cost'] ?? null) ? (float)$item['cost'] : null,
            'service_type' => ($item['serviceType'] ?? $item['service_type'] ?? null) ?: null,
            'item_snapshot' => json_encode($item, JSON_UNESCAPED_UNICODE)
        ];
    }
    return $rows;
}

function orderHistoryPayments($paymentMethod, float $total, bool $paid): array {
    if (!$paid) return [];
    $allowed = ['efectivo', 'qr', 'tarjeta', 'transferencia', 'otro'];
    $decoded = is_array($paymentMethod) ? $paymentMethod : json_decode((string)$paymentMethod, true);
    $payments = [];
    if (is_array($decoded)) {
        foreach ($allowed as $method) {
            $amount = (float)($decoded[$method] ?? 0);
            if ($amount > 0) $payments[$method] = round($amount, 2);
        }
    } else {
        $method = in_array((string)$paymentMethod, $allowed, true) ? (string)$paymentMethod : 'efectivo';
        $payments[$method] = round($total, 2);
    }
    if (!$payments && $total > 0) $payments['efectivo'] = round($total, 2);
    return $payments;
}

function persistOrderHistory(PDO $pdo, array $authContext, string $orderId, array $items, $paymentMethod, float $total, bool $paid, ?array $discounts = null, ?array $appliedPromo = null): void {
    $scope = ['order_id' => $orderId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']];
    $deleteItems = $pdo->prepare("DELETE FROM `pedido_items` WHERE `order_id` = :order_id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    $deleteItems->execute($scope);
    $insertItem = $pdo->prepare("INSERT INTO `pedido_items` (id, order_id, line_no, product_id, item_type, item_name, category_id, category_name, unit_price, quantity, line_total, unit_cost, service_type, item_snapshot, tenant_id, branch_id) VALUES (:id, :order_id, :line_no, :product_id, :item_type, :item_name, :category_id, :category_name, :unit_price, :quantity, :line_total, :unit_cost, :service_type, :item_snapshot, :tenant_id, :branch_id)");
    foreach (orderHistoryItems($items) as $row) {
        $insertItem->execute(array_merge($row, $scope, ['id' => 'pit_' . substr(hash('sha256', $authContext['tenant_id'] . '|' . $authContext['branch_id'] . '|' . $orderId . '|' . $row['line_no']), 0, 40)]));
    }

    $deletePayments = $pdo->prepare("DELETE FROM `pedido_pagos` WHERE `order_id` = :order_id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    $deletePayments->execute($scope);
    $insertPayment = $pdo->prepare("INSERT INTO `pedido_pagos` (id, order_id, payment_method, amount, raw_payment_method, tenant_id, branch_id) VALUES (:id, :order_id, :payment_method, :amount, :raw_payment_method, :tenant_id, :branch_id)");
    foreach (orderHistoryPayments($paymentMethod, $total, $paid) as $method => $amount) {
        $insertPayment->execute(array_merge($scope, ['id' => 'ppay_' . bin2hex(random_bytes(12)), 'payment_method' => $method, 'amount' => $amount, 'raw_payment_method' => is_string($paymentMethod) ? $paymentMethod : json_encode($paymentMethod, JSON_UNESCAPED_UNICODE)]));
    }

    if (is_array($discounts)) {
        $deleteDiscounts = $pdo->prepare("DELETE FROM `pedido_descuentos` WHERE `order_id` = :order_id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
        $deleteDiscounts->execute($scope);
        $insertDiscount = $pdo->prepare("INSERT INTO `pedido_descuentos` (id, order_id, discount_id, discount_name, discount_type, amount, basis_amount, discount_snapshot, tenant_id, branch_id) VALUES (:id, :order_id, :discount_id, :discount_name, :discount_type, :amount, :basis_amount, :discount_snapshot, :tenant_id, :branch_id)");
        foreach (array_values($discounts) as $discount) {
            if (!is_array($discount)) continue;
            $amount = (float)($discount['amount'] ?? $discount['discountAmount'] ?? $discount['total_discount'] ?? 0);
            if ($amount <= 0) continue;
            $insertDiscount->execute(array_merge($scope, [
                'id' => 'pdisc_' . bin2hex(random_bytes(12)),
                'discount_id' => ($discount['id'] ?? $discount['discountId'] ?? null) ?: null,
                'discount_name' => ($discount['name'] ?? $discount['discountName'] ?? null) ?: null,
                'discount_type' => ($discount['type'] ?? null) ?: null,
                'amount' => round($amount, 2),
                'basis_amount' => is_numeric($discount['basisAmount'] ?? null) ? (float)$discount['basisAmount'] : null,
                'discount_snapshot' => json_encode($discount, JSON_UNESCAPED_UNICODE)
            ]));
        }
    } elseif (is_array($appliedPromo) && !empty($appliedPromo)) {
        $deleteDiscounts = $pdo->prepare("DELETE FROM `pedido_descuentos` WHERE `order_id` = :order_id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
        $deleteDiscounts->execute($scope);
        $insertDiscount = $pdo->prepare("INSERT INTO `pedido_descuentos` (id, order_id, discount_id, discount_name, discount_type, amount, basis_amount, discount_snapshot, tenant_id, branch_id) VALUES (:id, :order_id, :discount_id, :discount_name, :discount_type, :amount, :basis_amount, :discount_snapshot, :tenant_id, :branch_id)");
        $promoAmount = (float)($appliedPromo['discount'] ?? $appliedPromo['amount'] ?? 0);
        if ($promoAmount > 0) {
            $insertDiscount->execute(array_merge($scope, [
                'id' => 'pdisc_' . bin2hex(random_bytes(12)),
                'discount_id' => $appliedPromo['plan_id'] ?? $appliedPromo['id'] ?? null,
                'discount_name' => $appliedPromo['plan_name'] ?? $appliedPromo['name'] ?? null,
                'discount_type' => 'promo_' . ($appliedPromo['type'] ?? 'manual'),
                'amount' => round($promoAmount, 2),
                'basis_amount' => null,
                'discount_snapshot' => json_encode($appliedPromo, JSON_UNESCAPED_UNICODE)
            ]));
        }
    }
}

function ensureOrderCustomer(PDO $pdo, array $authContext, string $customerId, string $customerName): string {
    if ($customerId !== '') {
        $stmt = $pdo->prepare("SELECT id FROM `clientes` WHERE id = :id AND tenant_id = :tenant_id AND active = 1");
        $stmt->execute(['id' => $customerId, 'tenant_id' => $authContext['tenant_id']]);
        if ($stmt->fetchColumn()) return $customerId;
    }
    $stmt = $pdo->prepare("SELECT id FROM `clientes` WHERE tenant_id = :tenant_id AND name = :name AND active = 1 LIMIT 1");
    $stmt->execute(['tenant_id' => $authContext['tenant_id'], 'name' => $customerName]);
    $existing = $stmt->fetchColumn();
    if ($existing) return (string)$existing;
    $id = 'cli_' . bin2hex(random_bytes(10));
    $stmt = $pdo->prepare("INSERT INTO `clientes` (id, name, tenant_id, branch_id) VALUES (:id, :name, :tenant_id, :branch_id)");
    $stmt->execute(['id' => $id, 'name' => $customerName, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    return $id;
}

function syncOrderReceivable(PDO $pdo, array $authContext, string $orderId, string $customerId, float $amount, string $dueDate): void {
    $stmt = $pdo->prepare("INSERT INTO `cuentas_por_cobrar` (id, order_id, customer_id, original_amount, balance, due_date, status, tenant_id, branch_id) VALUES (:id, :order_id, :customer_id, :original_amount, :balance, :due_date, 'pendiente', :tenant_id, :branch_id) ON DUPLICATE KEY UPDATE customer_id = VALUES(customer_id), original_amount = VALUES(original_amount), due_date = VALUES(due_date), status = CASE WHEN balance <= 0 THEN 'pagada' WHEN due_date < CURDATE() THEN 'vencida' ELSE 'pendiente' END");
    $stmt->execute(['id' => 'cxc_' . bin2hex(random_bytes(10)), 'order_id' => $orderId, 'customer_id' => $customerId, 'original_amount' => $amount, 'balance' => $amount, 'due_date' => $dueDate, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
}

function handle_save_order(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'orders');
    if (!isset($input['id']) || $input['id'] === '' || empty($input['customer']) || !is_array($input['items']) || 
        !isset($input['total']) || !is_numeric($input['total']) || $input['total'] < 0 ||
        empty($input['status'])) {
        echo json_encode(["status" => "error", "message" => "Datos del pedido inválidos"]);
        return;
    }
    $validStatuses = ['pendiente', 'completado', 'anulado'];
    if (!in_array($input['status'], $validStatuses)) {
        echo json_encode(["status" => "error", "message" => "Estado del pedido inválido"]);
        return;
    }
    $validPayments = ['efectivo', 'qr', 'tarjeta', 'credito'];
    $paymentMethod = isset($input['paymentMethod']) ? $input['paymentMethod'] : 'efectivo';
    
    if (is_array($paymentMethod)) {
        $paymentMethod = json_encode($paymentMethod);
    } elseif (!in_array($paymentMethod, $validPayments)) {
        $paymentMethod = 'efectivo';
    }

    $isCredit = $paymentMethod === 'credito';
    $customerName = trim((string)$input['customer']);
    $customerId = '';
    $dueDate = null;
    $creditOverrideReason = trim((string)($input['credit_override_reason'] ?? ''));
    if ($isCredit) {
        $dueDate = trim((string)($input['dueDate'] ?? $input['due_date'] ?? ''));
        if ($customerName === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            echo json_encode(["status" => "error", "message" => "Las ventas a crédito requieren cliente y fecha de vencimiento"]);
            return;
        }
        $customerId = ensureOrderCustomer($pdo, $authContext, trim((string)($input['customerId'] ?? $input['customer_id'] ?? '')), $customerName);
        $input['status'] = 'completado';
    } elseif (!empty($input['customerId']) || !empty($input['customer_id'])) {
        $selectedCustomer = findTenantCustomer($pdo, $authContext, trim((string)($input['customerId'] ?? $input['customer_id'])));
        if (!$selectedCustomer) {
            echo json_encode(["status" => "error", "message" => "El cliente seleccionado no pertenece a este restaurante"]);
            return;
        }
        $customerId = $selectedCustomer['id'];
    }
    
    $rawTs = $input['timestamp'] ?? '';
    if ($rawTs && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $rawTs)) {
        $ts = str_replace('T', ' ', substr($rawTs, 0, 19));
    } else {
        $ts = date('Y-m-d H:i:s');
    }

    $deliveryType = isset($input['deliveryType']) ? $input['deliveryType'] : 'mesa';
    if (!in_array($deliveryType, ['mesa', 'llevar', 'delivery'], true)) {
        $deliveryType = 'mesa';
    }
    
    $paid = !empty($input['paid']) ? 1 : 0;

    $soldAt = null;
    if (($paid || $isCredit || $input['status'] === 'completado') && !empty($input['soldAt'])) {
        $soldAtDt = false;
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/', $input['soldAt'])) {
            $soldAtDt = @DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $input['soldAt'], new DateTimeZone('UTC'));
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $input['soldAt'])) {
            $soldAtDt = new DateTime($input['soldAt'], new DateTimeZone('UTC'));
            $soldAtDt->setTimezone(new DateTimeZone('UTC'));
        }
        if ($soldAtDt) {
            $soldAt = $soldAtDt->format('Y-m-d H:i:s');
        } else {
            $soldAt = date('Y-m-d H:i:s');
        }
    }
    if (($paid || $isCredit || $input['status'] === 'completado') && $soldAt === null) $soldAt = date('Y-m-d H:i:s');
    
    if ($isCredit) $paid = 0;
    $pdo->beginTransaction();
    try {
    if ($isCredit) {
        $customerStmt = $pdo->prepare("SELECT credit_limit FROM `clientes` WHERE id = :id AND tenant_id = :tenant_id AND active = 1 FOR UPDATE");
        $customerStmt->execute(['id' => $customerId, 'tenant_id' => $authContext['tenant_id']]);
        $creditLimit = (float)$customerStmt->fetchColumn();
        if ($creditLimit > 0) {
            $exposureStmt = $pdo->prepare("SELECT COALESCE(SUM(balance), 0) FROM `cuentas_por_cobrar` WHERE tenant_id = :tenant_id AND customer_id = :customer_id AND status IN ('pendiente', 'vencida') FOR UPDATE");
            $exposureStmt->execute(['tenant_id' => $authContext['tenant_id'], 'customer_id' => $customerId]);
            $exposure = (float)$exposureStmt->fetchColumn();
            $requestedCredit = round($exposure + (float)$input['total'], 2);
            if ($requestedCredit > $creditLimit + 0.01) {
                $canOverride = in_array($authContext['role'], ['owner', 'super_admin'], true) && $creditOverrideReason !== '';
                if (!$canOverride) {
                    throw new InvalidArgumentException('El crédito solicitado supera el límite del cliente. Requiere autorización del propietario y un motivo.');
                }
            }
        }
    }
    $stmt = $pdo->prepare("INSERT INTO `pedidos` (`id`, `customer`, `items`, `total`, `payment_method`, `service_state`, `status`, `timestamp`, `tenant_id`, `branch_id`, `delivery_type`, `paid`, `sold_at`, `customer_id`, `due_date`, `subtotal`, `discount_total`, `applied_promo`, `coupon_code`)
        VALUES (:id, :customer, :items, :total, :payment_method, :service_state, :status, :timestamp, :tenant_id, :branch_id, :delivery_type, :paid, :sold_at, :customer_id, :due_date, :subtotal, :discount_total, :applied_promo, :coupon_code)
        ON DUPLICATE KEY UPDATE `customer` = VALUES(`customer`), `items` = VALUES(`items`), `total` = VALUES(`total`), `payment_method` = VALUES(`payment_method`), `service_state` = VALUES(`service_state`), `status` = VALUES(`status`), `delivery_type` = VALUES(`delivery_type`), `paid` = VALUES(`paid`), `sold_at` = VALUES(`sold_at`), `customer_id` = VALUES(`customer_id`), `due_date` = VALUES(`due_date`), `subtotal` = VALUES(`subtotal`), `discount_total` = VALUES(`discount_total`), `applied_promo` = VALUES(`applied_promo`), `coupon_code` = VALUES(`coupon_code`)");
    $stmt->execute([
        'id' => $input['id'],
        'customer' => $input['customer'],
        'items' => json_encode($input['items']),
        'total' => (float)$input['total'],
        'payment_method' => $paymentMethod,
        'service_state' => isset($input['serviceState']) ? $input['serviceState'] : null,
        'status' => $input['status'],
        'timestamp' => $ts,
        'tenant_id' => $authContext['tenant_id'],
        'branch_id' => $authContext['branch_id'],
        'delivery_type' => $deliveryType,
        'paid' => $paid,
        'sold_at' => $soldAt,
        'customer_id' => $customerId ?: null,
        'due_date' => $dueDate,
        'subtotal' => isset($input['subtotal']) ? (float)$input['subtotal'] : null,
        'discount_total' => isset($input['discountTotal']) ? (float)$input['discountTotal'] : ((float)($input['discount_total'] ?? 0)),
        'applied_promo' => !empty($input['appliedPromo']) ? json_encode($input['appliedPromo']) : null,
        'coupon_code' => !empty($input['couponCode']) ? $input['couponCode'] : null
    ]);
    if ($stmt->rowCount() === 0 && $pdo->lastInsertId() === '0') {
        $pdo->rollBack();
        echo json_encode(["status" => "error", "message" => "Pedido ya existe o no se pudo guardar"]);
        return;
    }
    if ($customerId !== '') {
        $pdo->prepare("UPDATE `clientes` SET `last_seen_at` = NOW(), `updated_at` = NOW() WHERE `id` = :id AND `tenant_id` = :tenant_id")->execute(['id' => $customerId, 'tenant_id' => $authContext['tenant_id']]);
    }
    persistOrderHistory($pdo, $authContext, (string)$input['id'], $input['items'], $paymentMethod, (float)$input['total'], (bool)$paid, $input['appliedDiscounts'] ?? $input['discounts'] ?? $input['applied_discounts'] ?? null, $input['appliedPromo'] ?? $input['applied_promo'] ?? null);
    if ($isCredit) syncOrderReceivable($pdo, $authContext, (string)$input['id'], $customerId, (float)$input['total'], $dueDate);
    $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    writeAuditLog($pdo, $authContext, 'order.save', 'pedido', $input['id'], ['status' => $input['status'], 'credit_override_reason' => $creditOverrideReason ?: null]);
    broadcastWebSocketEvent('order.created', [
        'id' => $input['id'],
        'status' => $input['status'],
        'customer' => $input['customer'],
        'deliveryType' => $deliveryType,
        'serviceState' => $input['serviceState'] ?? null,
        'items' => $input['items'],
        'total' => (float)$input['total'],
        'paymentMethod' => $paymentMethod,
        'timestamp' => $ts,
        'paid' => $paid,
        'soldAt' => $soldAt
    ], $authContext);
    echo json_encode(["status" => "success"]);
}

function handle_update_order_state(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'orders');
    if (!isset($input['id'], $input['state'])) {
        echo json_encode(["status" => "error", "message" => "Datos requeridos: id y state"]);
        return;
    }
    $validStates = ['esperando_sopa', 'esperando_segundo', 'comiendo', 'esperando_cuenta'];
    if (!in_array($input['state'], $validStates, true)) {
        echo json_encode(["status" => "error", "message" => "Estado de servicio inválido"]);
        return;
    }
    $stmt = $pdo->prepare("UPDATE `pedidos` SET `service_state` = :state WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `status` = 'pendiente'");
    $stmt->execute(['state' => $input['state'], 'id' => $input['id'], 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    writeAuditLog($pdo, $authContext, 'order.state.update', 'pedido', $input['id'], ['state' => $input['state']]);
    broadcastWebSocketEvent('order.updated', ['id' => $input['id'], 'serviceState' => $input['state']], $authContext);
    echo json_encode(["status" => "success"]);
}

function handle_complete_order(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'orders');
    if (!isset($input['id'], $input['paymentMethod'])) {
        echo json_encode(["status" => "error", "message" => "Datos requeridos: id y paymentMethod"]);
        return;
    }
    $validPayments = ['efectivo', 'qr', 'tarjeta'];
    $paymentMethod = $input['paymentMethod'];
    
    if (is_array($paymentMethod)) {
        $paymentMethod = json_encode($paymentMethod);
    } elseif (!in_array($paymentMethod, $validPayments)) {
        echo json_encode(["status" => "error", "message" => "Método de pago inválido"]);
        return;
    }

    $soldAt = null;
    if (!empty($input['soldAt'])) {
        $soldAtDt = false;
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/', $input['soldAt'])) {
            $soldAtDt = @DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $input['soldAt'], new DateTimeZone('UTC'));
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $input['soldAt'])) {
            $soldAtDt = new DateTime($input['soldAt'], new DateTimeZone('UTC'));
            $soldAtDt->setTimezone(new DateTimeZone('UTC'));
        }
        $soldAt = $soldAtDt ? $soldAtDt->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
    } else {
        $soldAt = date('Y-m-d H:i:s');
    }
    
    $pdo->beginTransaction();
    $orderStmt = $pdo->prepare("SELECT `items`, `total` FROM `pedidos` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `status` = 'pendiente' FOR UPDATE");
    $orderStmt->execute(['id' => $input['id'], 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    $existingOrder = $orderStmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare("UPDATE `pedidos` SET `status` = 'completado', `payment_method` = :method, `paid` = 1, `sold_at` = :sold_at WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `status` = 'pendiente'");
    $stmt->execute(['method' => $paymentMethod, 'sold_at' => $soldAt, 'id' => $input['id'], 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    if ($stmt->rowCount() === 0) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(["status" => "error", "message" => "Pedido no encontrado o ya fue procesado"]);
        return;
    }
    persistOrderHistory($pdo, $authContext, (string)$input['id'], json_decode($existingOrder['items'] ?? '[]', true) ?: [], $paymentMethod, (float)($existingOrder['total'] ?? 0), true, array_key_exists('appliedDiscounts', $input) ? $input['appliedDiscounts'] : null);
    $pdo->commit();
    writeAuditLog($pdo, $authContext, 'order.complete', 'pedido', $input['id']);
    broadcastWebSocketEvent('order.completed', ['id' => $input['id']], $authContext);
    echo json_encode(["status" => "success"]);
}

function orderCancellationLineTotal(array $item, float $quantity): float {
    $unitPrice = (float)($item['price'] ?? 0);
    foreach (($item['salsas'] ?? []) as $salsa) $unitPrice += (float)($salsa['salsaPrice'] ?? 0);
    return round($unitPrice * $quantity, 2);
}

function handle_cancel_order(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'orders');
    if (!isset($input['id'])) {
        echo json_encode(["status" => "error", "message" => "Datos requeridos: id"]);
        return;
    }
    if (trim((string)($input['reason'] ?? '')) === '') {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "El motivo de la cancelación es obligatorio"]);
        return;
    }
    $inputId = $input['id'];
    $requestedItems = is_array($input['items'] ?? null) ? $input['items'] : [];

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT * FROM `pedidos` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `status` = 'pendiente' FOR UPDATE");
    $stmt->execute(['id' => $inputId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        $pdo->rollBack();
        echo json_encode(["status" => "error", "message" => "Pedido no encontrado o ya fue procesado."]);
        return;
    }
    if (!empty($order['paid'])) {
        $pdo->rollBack();
        throw new InvalidArgumentException('La venta ya está cobrada. Use el flujo de devolución.');
    }
    $beforeOrder = $order;

    $rawItems = json_decode($order['items'] ?? '[]', true) ?: [];
    $orderItems = array_values($rawItems);
    $isFullCancellation = !$requestedItems;
    $remainingItems = [];
    $cancelledTotal = 0.0;

    if (!$isFullCancellation) {
        $requestedByLine = [];
        foreach ($requestedItems as $requested) {
            $lineNo = (int)($requested['line_no'] ?? -1);
            $quantity = (float)($requested['quantity'] ?? 0);
            if ($lineNo < 0 || !isset($orderItems[$lineNo]) || $quantity <= 0) {
                throw new InvalidArgumentException('Producto o cantidad de cancelación inválida');
            }
            $available = (float)($orderItems[$lineNo]['qty'] ?? $orderItems[$lineNo]['quantity'] ?? 1);
            if ($quantity > $available + 0.0001) throw new InvalidArgumentException('La cantidad a cancelar excede la cantidad del pedido');
            $requestedByLine[$lineNo] = ($requestedByLine[$lineNo] ?? 0) + $quantity;
        }

        foreach ($orderItems as $lineNo => $item) {
            $originalQuantity = (float)($item['qty'] ?? $item['quantity'] ?? 1);
            $cancelQuantity = min($originalQuantity, (float)($requestedByLine[$lineNo] ?? 0));
            $remainingQuantity = $originalQuantity - $cancelQuantity;
            $cancelledTotal += orderCancellationLineTotal($item, $cancelQuantity);
            if ($remainingQuantity > 0.0001) {
                $item['qty'] = $remainingQuantity;
                $remainingItems[] = $item;
            }
        }
        if ($cancelledTotal <= 0) throw new InvalidArgumentException('Seleccione al menos un producto');
        $isFullCancellation = !$remainingItems;
    }

    if ($isFullCancellation) {
        $stmt = $pdo->prepare("UPDATE `pedidos` SET `status` = 'anulado' WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
        $stmt->execute(['id' => $inputId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    } else {
        $newTotal = max(0, round((float)$order['total'] - $cancelledTotal, 2));
        $stmt = $pdo->prepare("UPDATE `pedidos` SET `items` = :items, `total` = :total WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `status` = 'pendiente'");
        $stmt->execute(['items' => json_encode($remainingItems, JSON_UNESCAPED_UNICODE), 'total' => $newTotal, 'id' => $inputId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
        $order['items'] = json_encode($remainingItems, JSON_UNESCAPED_UNICODE);
        $order['total'] = $newTotal;
        persistOrderHistory($pdo, $authContext, (string)$inputId, $remainingItems, $order['payment_method'] ?? 'efectivo', $newTotal, !empty($order['paid']), null);
    }
    $pdo->commit();
    $afterOrder = $order;
    if ($isFullCancellation) $afterOrder['status'] = 'anulado';
    writeAuditLog($pdo, $authContext, $isFullCancellation ? 'order.cancel' : 'order.cancel_partial', 'pedido', $inputId, ['cancelled_items' => $requestedItems], $input['reason'] ?? null, $beforeOrder, $afterOrder);
    broadcastWebSocketEvent($isFullCancellation ? 'order.cancelled' : 'order.updated', ['id' => $inputId], $authContext);
    echo json_encode(["status" => "success", "full_cancelled" => $isFullCancellation, "order" => [
        'id' => $inputId,
        'status' => $isFullCancellation ? 'anulado' : 'pendiente',
        'items' => json_decode($afterOrder['items'] ?? '[]', true) ?: [],
        'total' => (float)($afterOrder['total'] ?? 0)
    ]]);
}

function handle_append_order_items(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'orders');
    $orderId = trim($input['id'] ?? '');
    $newItems = $input['items'] ?? [];

    if ($orderId === '' || !is_array($newItems) || empty($newItems)) {
        echo json_encode(["status" => "error", "message" => "ID de pedido y al menos un item son requeridos"]);
        return;
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT * FROM `pedidos` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `status` = 'pendiente' FOR UPDATE");
    $stmt->execute(['id' => $orderId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        $pdo->rollBack();
        echo json_encode(["status" => "error", "message" => "Pedido no encontrado o ya fue procesado"]);
        return;
    }

    $existingItems = json_decode($order['items'], true) ?? [];
    $addedTotal = 0;
    $markPaid = !empty($input['markPaid']);
    foreach ($newItems as $item) {
        $newItem = [
            'type' => $item['type'] ?? 'segundo',
            'name' => $item['name'] ?? '',
            'price' => (float)($item['price'] ?? 0),
            'quantity' => (int)($item['quantity'] ?? 1),
            'serviceType' => $item['serviceType'] ?? 'servirse'
        ];
        if ($markPaid) {
            $newItem['paid'] = true;
        }
        if (!empty($item['sopaId'])) {
            $newItem['sopaId'] = $item['sopaId'];
            $newItem['sopaName'] = $item['sopaName'] ?? '';
        }
        if (!empty($item['segundoId'])) {
            $newItem['segundoId'] = $item['segundoId'];
            $newItem['segundoName'] = $item['segundoName'] ?? '';
        }
        if (!empty($item['platoId'])) {
            $newItem['platoId'] = $item['platoId'];
        }
        if (!empty($item['extraId'])) {
            $newItem['extraId'] = $item['extraId'];
        }
        $existingItems[] = $newItem;
        $addedTotal += (float)($item['price'] ?? 0) * (int)($item['quantity'] ?? 1);
    }

    $newTotal = (float)$order['total'] + $addedTotal;
    $stmt = $pdo->prepare("UPDATE `pedidos` SET `items` = :items, `total` = :total WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    $stmt->execute([
        'items' => json_encode($existingItems, JSON_UNESCAPED_UNICODE),
        'total' => $newTotal,
        'id' => $orderId,
        'tenant_id' => $authContext['tenant_id'],
        'branch_id' => $authContext['branch_id']
    ]);

    persistOrderHistory($pdo, $authContext, $orderId, $existingItems, $order['payment_method'] ?? 'efectivo', $newTotal, $markPaid || !empty($order['paid']));
    $pdo->commit();
    writeAuditLog($pdo, $authContext, 'order.items.append', 'pedido', $orderId, ['added_count' => count($newItems), 'new_total' => $newTotal]);
    broadcastWebSocketEvent('order.appended', ['id' => $orderId, 'total' => $newTotal], $authContext);
    echo json_encode(["status" => "success", "total" => $newTotal, "items" => $existingItems]);
}

function handle_annul_sale(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    if (!isset($input['id'])) {
        echo json_encode(["status" => "error", "message" => "Datos requeridos: id"]);
        return;
    }
    if (trim((string)($input['reason'] ?? '')) === '') {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "El motivo de la anulación es obligatorio"]);
        return;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM `pedidos` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `status` = 'completado' FOR UPDATE");
        $stmt->execute(['id' => $input['id'], 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            $pdo->rollBack();
            echo json_encode(["status" => "error", "message" => "Venta no encontrada o ya fue procesada."]);
            return;
        }

        $items = json_decode($order['items'], true);
        if (is_array($items)) {
            foreach ($items as $item) {
                $type = isset($item['type']) ? $item['type'] : '';
                $qty = isset($item['quantity']) ? max(1, (int)$item['quantity']) : 1;

                if ($type === 'almuerzo' || $type === 'sopa') {
                    $sopaId = isset($item['sopaId']) ? $item['sopaId'] : '';
                    if ($sopaId) {
                        $stmtSopa = $pdo->prepare("UPDATE `sopas` SET `stock` = GREATEST(0, `stock` + :qty) WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
                        $stmtSopa->execute(['qty' => $qty, 'id' => $sopaId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
                    }
                    if ($type === 'almuerzo') {
                        $segId = isset($item['segundoId']) ? $item['segundoId'] : '';
                        if ($segId) {
                            $stmtSeg = $pdo->prepare("UPDATE `segundos` SET `stock` = GREATEST(0, `stock` + :qty) WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
                            $stmtSeg->execute(['qty' => $qty, 'id' => $segId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
                        }
                    }
                } else if ($type === 'segundo') {
                    $segId = isset($item['segundoId']) ? $item['segundoId'] : '';
                    if ($segId) {
                        $stmtSeg2 = $pdo->prepare("UPDATE `segundos` SET `stock` = GREATEST(0, `stock` + :qty) WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
                        $stmtSeg2->execute(['qty' => $qty, 'id' => $segId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
                    }
                } else if ($type === 'plato_extra') {
                    $peId = isset($item['platoId']) ? $item['platoId'] : '';
                    if ($peId) {
                        $stmtPe = $pdo->prepare("UPDATE `platos_extras` SET `stock` = GREATEST(0, `stock` + :qty) WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
                        $stmtPe->execute(['qty' => $qty, 'id' => $peId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
                    }
                } else if ($type === 'extra') {
                    $extId = isset($item['extraId']) ? $item['extraId'] : (isset($item['id']) ? $item['id'] : '');
                    if ($extId) {
                        $stmtExt = $pdo->prepare("UPDATE `gaseosas` SET `stock` = GREATEST(0, `stock` + :qty) WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
                        $stmtExt->execute(['qty' => $qty, 'id' => $extId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
                    }
                } else if ($type === 'salsa') {
                    $salsaId = isset($item['salsaId']) ? $item['salsaId'] : (isset($item['id']) ? $item['id'] : '');
                    if ($salsaId) {
                        $stmtSalsa = $pdo->prepare("UPDATE `salsas` SET `stock` = GREATEST(0, `stock` + :qty) WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
                        $stmtSalsa->execute(['qty' => $qty, 'id' => $salsaId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
                    }
                }
            }
        }

        $stmt = $pdo->prepare("UPDATE `pedidos` SET `status` = 'anulado' WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
        $stmt->execute(['id' => $input['id'], 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $afterOrder = $order;
    $afterOrder['status'] = 'anulado';
    writeAuditLog($pdo, $authContext, 'sale.annul', 'pedido', $input['id'], [], $input['reason'] ?? null, $order, $afterOrder);
    echo json_encode(["status" => "success"]);
}
