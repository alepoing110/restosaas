<?php
// Order action handlers

function orderHistoryItems(array $items): array {
    $rows = [];
    foreach (array_values($items) as $lineNo => $item) {
        if (!is_array($item)) continue;
        $quantity = (float)($item['quantity'] ?? $item['qty'] ?? 1);
        if ($quantity <= 0) $quantity = 1;
        $unitPrice = (float)($item['price'] ?? $item['unitPrice'] ?? 0);
        foreach (($item['salsas'] ?? []) as $salsa) $unitPrice += (float)($salsa['salsaPrice'] ?? 0);
        foreach (($item['accompaniments'] ?? []) as $accompaniment) $unitPrice += (float)($accompaniment['accompanimentPrice'] ?? 0);
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

function normalizeOrderPaymentMethod($paymentMethod, float $total): string {
    $allowed = ['efectivo', 'qr', 'tarjeta', 'transferencia', 'otro'];
    $decoded = is_array($paymentMethod) ? $paymentMethod : json_decode((string)$paymentMethod, true);
    if (!is_array($decoded)) {
        if (!in_array((string)$paymentMethod, $allowed, true)) throw new InvalidArgumentException('Método de pago inválido.');
        return (string)$paymentMethod;
    }

    $sum = 0.0;
    $normalized = [];
    foreach ($decoded as $method => $amount) {
        if (!in_array($method, $allowed, true) || !is_numeric($amount) || (float)$amount < 0) {
            throw new InvalidArgumentException('Método de pago inválido o monto negativo: ' . $method);
        }
        $value = round((float)$amount, 2);
        if ($value > 0) $normalized[$method] = $value;
        $sum += $value;
    }
    if (!$normalized || abs(round($sum, 2) - round($total, 2)) > 0.01) {
        throw new InvalidArgumentException('La suma de pagos debe coincidir exactamente con el total del pedido.');
    }
    return json_encode($normalized, JSON_UNESCAPED_UNICODE);
}

function canonicalizeOrderItems(PDO $pdo, array $authContext, array $items): array {
    $pricesStmt = $pdo->prepare("SELECT `id`, `valor` FROM `config_precios` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    $pricesStmt->execute(tenantParams($authContext));
    $basePrices = [];
    foreach ($pricesStmt->fetchAll(PDO::FETCH_ASSOC) as $price) $basePrices[$price['id']] = (float)$price['valor'];
    $productStmt = $pdo->prepare("SELECT `id`, `name`, `type`, `price`, `stock`, `accepts_salsa`, `accepts_accompaniment`, `max_included_accompaniments` FROM `products` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `active` = 1 FOR UPDATE");
    $salsaStmt = $pdo->prepare("SELECT `id`, `name`, `price` FROM `salsas` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `active` = 1");
    $accompanimentStmt = $pdo->prepare("SELECT `id`, `name`, `price_extra` FROM `acompanamientos` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `active` = 1");
    $loadProduct = static function (string $id, string $expectedType) use ($productStmt, $authContext): array {
        $productStmt->execute(['id' => $id, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);
        if (!$product || $product['type'] !== $expectedType) throw new InvalidArgumentException('Producto no disponible para el pedido.');
        return $product;
    };
    $canonical = [];
    usort($items, static function ($a, $b) {
        $aKey = ($a['sopaId'] ?? '') . ($a['segundoId'] ?? '') . ($a['platoId'] ?? '') . ($a['extraId'] ?? '') . ($a['product_id'] ?? '');
        $bKey = ($b['sopaId'] ?? '') . ($b['segundoId'] ?? '') . ($b['platoId'] ?? '') . ($b['extraId'] ?? '') . ($b['product_id'] ?? '');
        return strcmp($aKey, $bKey);
    });
    foreach ($items as $item) {
        if (!is_array($item)) throw new InvalidArgumentException('Producto de pedido inválido.');
        $type = (string)($item['type'] ?? '');
        $quantity = max(1, (int)($item['quantity'] ?? $item['qty'] ?? 1));
        $line = $item;
        $line['qty'] = $quantity;
        if ($type === 'almuerzo') {
            $sopa = $loadProduct((string)($item['sopaId'] ?? ''), 'sopa');
            $segundo = $loadProduct((string)($item['segundoId'] ?? ''), 'segundo');
            $line['name'] = 'Almuerzo Completo';
            $line['sopaId'] = $sopa['id'];
            $line['sopaName'] = $sopa['name'];
            $line['segundoId'] = $segundo['id'];
            $line['segundoName'] = $segundo['name'];
            $line['price'] = (float)($basePrices['almuerzo'] ?? 0);
            $canUseSalsa = !empty($sopa['accepts_salsa']) || !empty($segundo['accepts_salsa']);
            $accompanimentProduct = !empty($segundo['accepts_accompaniment']) ? $segundo : $sopa;
        } else {
            $field = match ($type) {
                'sopa' => 'sopaId', 'segundo' => 'segundoId', 'plato_extra' => 'platoId', 'extra' => 'extraId', 'salsa' => 'salsaId', 'acompanamiento' => 'accompanimentId', default => null,
            };
            $productType = $type === 'extra' ? 'refresco' : $type;
            if (!$field || !in_array($productType, ['sopa', 'segundo', 'plato_extra', 'refresco', 'salsa', 'acompanamiento'], true)) throw new InvalidArgumentException('Tipo de producto inválido.');
            $catalogId = (string)($item[$field] ?? $item['product_id'] ?? '');
            if ($type === 'salsa') {
                $salsaStmt->execute(['id' => $catalogId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
                $salsa = $salsaStmt->fetch(PDO::FETCH_ASSOC);
                if (!$salsa) throw new InvalidArgumentException('Salsa no disponible para el pedido.');
                $product = ['id' => $salsa['id'], 'name' => $salsa['name'], 'type' => 'salsa', 'price' => (float)$salsa['price'], 'stock' => 0, 'accepts_salsa' => 0, 'accepts_accompaniment' => 0, 'max_included_accompaniments' => 0];
            } elseif ($type === 'acompanamiento') {
                $accompanimentStmt->execute(['id' => $catalogId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
                $accompaniment = $accompanimentStmt->fetch(PDO::FETCH_ASSOC);
                if (!$accompaniment) throw new InvalidArgumentException('Acompañamiento no disponible para el pedido.');
                $product = ['id' => $accompaniment['id'], 'name' => $accompaniment['name'], 'type' => 'acompanamiento', 'price' => (float)$accompaniment['price_extra'], 'stock' => 0, 'accepts_salsa' => 0, 'accepts_accompaniment' => 0, 'max_included_accompaniments' => 0];
            } else {
                $product = $loadProduct($catalogId, $productType);
            }
            $line[$field] = $product['id'];
            $line['product_id'] = $product['id'];
            $line['name'] = $product['name'];
            if ($type === 'sopa') $line['sopaName'] = $product['name'];
            if ($type === 'segundo') $line['segundoName'] = $product['name'];
            $line['price'] = (float)$product['price'] > 0 ? (float)$product['price'] : (float)($basePrices[$type] ?? 0);
            $canUseSalsa = !empty($product['accepts_salsa']);
            $accompanimentProduct = $product;
        }
        if ((float)$line['price'] < 0 || ($type !== 'extra' && (float)$line['price'] <= 0)) throw new InvalidArgumentException('El producto no tiene un precio configurado.');
        $line['salsas'] = [];
        foreach (($item['salsas'] ?? []) as $salsa) {
            if (!$canUseSalsa) throw new InvalidArgumentException('El producto seleccionado no permite salsa.');
            $salsaStmt->execute(['id' => (string)($salsa['salsaId'] ?? ''), 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
            $canonicalSalsa = $salsaStmt->fetch(PDO::FETCH_ASSOC);
            if (!$canonicalSalsa) throw new InvalidArgumentException('La salsa seleccionada no está disponible.');
            $requestedMode = mb_strtolower(trim((string)($salsa['salsaMode'] ?? $salsa['salsa_mode'] ?? 'banar')));
            $salsaMode = in_array($requestedMode, ['banar', 'bañar', 'banado', 'bañado'], true) ? 'banar' : 'aparte';
            $line['salsas'][] = ['salsaId' => $canonicalSalsa['id'], 'salsaName' => $canonicalSalsa['name'], 'salsaPrice' => 0.0, 'salsaMode' => $salsaMode];
        }
        $line['accompaniments'] = [];
        $maxIncluded = !empty($accompanimentProduct['accepts_accompaniment']) ? max(0, (int)$accompanimentProduct['max_included_accompaniments']) : 0;
        foreach (($item['accompaniments'] ?? []) as $index => $accompaniment) {
            if (empty($accompanimentProduct['accepts_accompaniment'])) throw new InvalidArgumentException('El producto seleccionado no permite acompañamientos.');
            $accompanimentStmt->execute(['id' => (string)($accompaniment['accompanimentId'] ?? $accompaniment['id'] ?? ''), 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
            $canonicalAccompaniment = $accompanimentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$canonicalAccompaniment) throw new InvalidArgumentException('El acompañamiento seleccionado no está disponible.');
            $mode = $index < $maxIncluded ? 'included' : 'extra';
            $line['accompaniments'][] = ['accompanimentId' => $canonicalAccompaniment['id'], 'accompanimentName' => $canonicalAccompaniment['name'], 'accompanimentMode' => $mode, 'accompanimentPrice' => $mode === 'extra' ? (float)$canonicalAccompaniment['price_extra'] : 0.0];
        }
        $canonical[] = $line;
    }
    return $canonical;
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
        $promoAmount = (float)($appliedPromo['discount_amount'] ?? $appliedPromo['discountAmount'] ?? $appliedPromo['discount'] ?? $appliedPromo['amount'] ?? 0);
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
    $paymentMethod = isset($input['paymentMethod']) ? $input['paymentMethod'] : 'efectivo';
    $isCredit = $paymentMethod === 'credito';
    if (!$isCredit && !is_array($paymentMethod) && !in_array($paymentMethod, ['efectivo', 'qr', 'tarjeta', 'transferencia', 'otro'], true)) {
        throw new InvalidArgumentException('Método de pago inválido.');
    }
    $customerName = trim((string)$input['customer']);
    $kitchenNote = mb_substr(trim((string)($input['kitchenNote'] ?? $input['kitchen_note'] ?? '')), 0, 500);
    $waiterNote = mb_substr(trim((string)($input['waiterNote'] ?? $input['waiter_note'] ?? '')), 0, 500);
    $pickupTime = trim((string)($input['pickupTime'] ?? $input['pickup_time'] ?? ''));
    if ($pickupTime !== '' && !preg_match('/^\d{2}:\d{2}$/', $pickupTime)) $pickupTime = '';
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
    $tableId = trim((string)($input['tableId'] ?? $input['table_id'] ?? ''));
    
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

    if ($soldAt) {
        $saleDate = substr($soldAt, 0, 10);
        $closedCheck = $pdo->prepare("SELECT 1 FROM `caja_cierres_historico` WHERE `tenant_id` = :tid AND `branch_id` = :bid AND `fecha` = :date LIMIT 1");
        $closedCheck->execute(['tid' => $authContext['tenant_id'], 'bid' => $authContext['branch_id'], 'date' => $saleDate]);
        if ($closedCheck->fetchColumn()) throw new InvalidArgumentException('La fecha de venta pertenece a un día ya cerrado. Registre la operación en la fecha operativa actual.');
    }

    if ($isCredit) $paid = 0;
    $pdo->beginTransaction();
    try {
    if ($deliveryType === 'mesa') {
        $tableStmt = $pdo->prepare("SELECT `id`, `name` FROM `tables_config` WHERE (`id` = :table_id OR `name` = :table_name) AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `active` = 1 LIMIT 1 FOR UPDATE");
        $tableStmt->execute(['table_id' => $tableId, 'table_name' => $customerName, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
        $tableRow = $tableStmt->fetch(PDO::FETCH_ASSOC);
        if (!$tableRow) throw new InvalidArgumentException('La mesa seleccionada no pertenece a esta sucursal.');
        $tableId = (string)$tableRow['id'];
        $occupiedStmt = $pdo->prepare("SELECT `id` FROM `pedidos` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `status` = 'pendiente' AND (`table_id` = :table_id OR (`table_id` IS NULL AND `customer` LIKE :legacy_customer)) AND `id` <> :order_id LIMIT 1 FOR UPDATE");
        $occupiedStmt->execute(['tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id'], 'table_id' => $tableId, 'legacy_customer' => $tableRow['name'] . ' - %', 'order_id' => (string)$input['id']]);
        if ($occupiedStmt->fetchColumn()) throw new InvalidArgumentException('La mesa seleccionada ya tiene un pedido activo.');
    } else {
        $tableId = null;
    }
    _backfillProductsForTenant($pdo, $authContext);
    $input['items'] = canonicalizeOrderItems($pdo, $authContext, $input['items']);
    $subtotal = 0.0;
    foreach ($input['items'] as $item) {
        $salsaTotal = array_sum(array_map(static fn($salsa) => (float)($salsa['salsaPrice'] ?? 0), $item['salsas'] ?? []));
        $accompanimentTotal = array_sum(array_map(static fn($accompaniment) => (float)($accompaniment['accompanimentPrice'] ?? 0), $item['accompaniments'] ?? []));
        $subtotal += ((float)$item['price'] + $salsaTotal + $accompanimentTotal) * (int)$item['qty'];
    }
    $requestedDiscount = (float)($input['discountTotal'] ?? $input['discount_total'] ?? 0);
    $discountTotal = max(0, min($requestedDiscount, $subtotal));
    $input['subtotal'] = round($subtotal, 2);
    $input['discountTotal'] = round($discountTotal, 2);
    $input['total'] = round($subtotal - $discountTotal, 2);
     $existingOrder = $pdo->prepare("SELECT `id`, `closure_id`, `status` FROM `pedidos` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id FOR UPDATE");
    $existingOrder->execute(['id' => $input['id'], 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    $existingOrderRow = $existingOrder->fetch(PDO::FETCH_ASSOC);
    if ($existingOrderRow && !empty($existingOrderRow['closure_id'])) throw new InvalidArgumentException('El pedido ya está incluido en un cierre de caja.');
    $orderExists = (bool)$existingOrderRow;

    if ($orderExists) {
        $existingStatus = $existingOrderRow['status'] ?? 'pendiente';
        if ($existingStatus === 'completado') throw new InvalidArgumentException('El pedido ya está cobrado y no puede modificarse.');
        if ($existingStatus === 'anulado') throw new InvalidArgumentException('El pedido está anulado y no puede modificarse.');
    }

    if ($paid && !$isCredit) $paymentMethod = normalizeOrderPaymentMethod($paymentMethod, (float)$input['total']);

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
    $stmt = $pdo->prepare("INSERT INTO `pedidos` (`id`, `customer`, `created_by_user_id`, `created_by_name`, `paid_by_user_id`, `paid_by_name`, `kitchen_note`, `waiter_note`, `pickup_time`, `items`, `total`, `payment_method`, `service_state`, `status`, `timestamp`, `tenant_id`, `branch_id`, `table_id`, `delivery_type`, `paid`, `sold_at`, `customer_id`, `reservation_id`, `due_date`, `subtotal`, `discount_total`, `applied_promo`, `coupon_code`)
        VALUES (:id, :customer, :created_by_user_id, :created_by_name, :paid_by_user_id, :paid_by_name, :kitchen_note, :waiter_note, :pickup_time, :items, :total, :payment_method, :service_state, :status, :timestamp, :tenant_id, :branch_id, :table_id, :delivery_type, :paid, :sold_at, :customer_id, :reservation_id, :due_date, :subtotal, :discount_total, :applied_promo, :coupon_code)
         ON DUPLICATE KEY UPDATE `customer` = VALUES(`customer`), `kitchen_note` = VALUES(`kitchen_note`), `waiter_note` = VALUES(`waiter_note`), `pickup_time` = VALUES(`pickup_time`), `items` = VALUES(`items`), `total` = VALUES(`total`), `payment_method` = VALUES(`payment_method`), `service_state` = VALUES(`service_state`), `status` = VALUES(`status`), `table_id` = VALUES(`table_id`), `delivery_type` = VALUES(`delivery_type`), `paid` = VALUES(`paid`), `paid_by_user_id` = IF(VALUES(`paid`) = 1, VALUES(`paid_by_user_id`), `paid_by_user_id`), `paid_by_name` = IF(VALUES(`paid`) = 1, VALUES(`paid_by_name`), `paid_by_name`), `sold_at` = VALUES(`sold_at`), `customer_id` = VALUES(`customer_id`), `reservation_id` = VALUES(`reservation_id`), `due_date` = VALUES(`due_date`), `subtotal` = VALUES(`subtotal`), `discount_total` = VALUES(`discount_total`), `applied_promo` = VALUES(`applied_promo`), `coupon_code` = VALUES(`coupon_code`)");
    $stmt->execute([
        'id' => $input['id'],
         'customer' => $input['customer'],
         'created_by_user_id' => $authContext['user_id'] ?? null,
         'created_by_name' => $authContext['user_name'] ?? null,
         'paid_by_user_id' => ($paid && !$isCredit) ? ($authContext['user_id'] ?? null) : null,
         'paid_by_name' => ($paid && !$isCredit) ? ($authContext['user_name'] ?? null) : null,
        'kitchen_note' => $kitchenNote ?: null,
        'waiter_note' => $waiterNote ?: null,
        'pickup_time' => $pickupTime ?: null,
        'items' => json_encode($input['items']),
        'total' => (float)$input['total'],
        'payment_method' => $paymentMethod,
        'service_state' => isset($input['serviceState']) ? $input['serviceState'] : null,
        'status' => $input['status'],
        'timestamp' => $ts,
        'tenant_id' => $authContext['tenant_id'],
        'branch_id' => $authContext['branch_id'],
        'table_id' => $tableId,
        'delivery_type' => $deliveryType,
        'paid' => $paid,
        'sold_at' => $soldAt,
        'customer_id' => $customerId ?: null,
        'reservation_id' => !empty($input['reservationId']) ? (string)$input['reservationId'] : null,
        'due_date' => $dueDate,
        'subtotal' => isset($input['subtotal']) ? (float)$input['subtotal'] : null,
        'discount_total' => isset($input['discountTotal']) ? (float)$input['discountTotal'] : ((float)($input['discount_total'] ?? 0)),
        'applied_promo' => !empty($input['appliedPromo']) ? json_encode($input['appliedPromo']) : null,
        'coupon_code' => !empty($input['couponCode']) ? $input['couponCode'] : null
    ]);
    if ($input['status'] === 'anulado') releaseStock($pdo, $authContext, 'order', (string)$input['id']);
    elseif (!empty($input['reservationId']) && !$orderExists) transferStockReservation($pdo, $authContext, (string)$input['reservationId'], (string)$input['id'], $input['items'], (string)($input['status'] ?? 'pendiente'));
    else reserveStock($pdo, $authContext, 'order', (string)$input['id'], $input['items']);
    if ($paid && !$isCredit && !empty($input['reservationId'])) {
        $pdo->prepare("UPDATE `reservations` SET `paid_by_user_id` = :user_id, `paid_by_name` = :user_name, `updated_at` = NOW() WHERE `id` = :reservation_id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id")->execute(['user_id' => $authContext['user_id'] ?? null, 'user_name' => $authContext['user_name'] ?? null, 'reservation_id' => (string)$input['reservationId'], 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    }
    if ($customerId !== '') {
        $pdo->prepare("UPDATE `clientes` SET `last_seen_at` = NOW(), `updated_at` = NOW() WHERE `id` = :id AND `tenant_id` = :tenant_id")->execute(['id' => $customerId, 'tenant_id' => $authContext['tenant_id']]);
    }
    persistOrderHistory($pdo, $authContext, (string)$input['id'], $input['items'], $paymentMethod, (float)$input['total'], (bool)$paid, $input['appliedDiscounts'] ?? $input['discounts'] ?? $input['applied_discounts'] ?? null, $input['appliedPromo'] ?? $input['applied_promo'] ?? null);
    if ($isCredit) syncOrderReceivable($pdo, $authContext, (string)$input['id'], $customerId, (float)$input['total'], $dueDate);
    writeAuditLog($pdo, $authContext, 'order.save', 'pedido', $input['id'], ['status' => $input['status'], 'credit_override_reason' => $creditOverrideReason ?: null]);
    $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
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
    $paymentMethod = $input['paymentMethod'];

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

    $saleDate = substr($soldAt, 0, 10);
    $closedCheck = $pdo->prepare("SELECT 1 FROM `caja_cierres_historico` WHERE `tenant_id` = :tid AND `branch_id` = :bid AND `fecha` = :date LIMIT 1");
    $closedCheck->execute(['tid' => $authContext['tenant_id'], 'bid' => $authContext['branch_id'], 'date' => $saleDate]);
    if ($closedCheck->fetchColumn()) {
        echo json_encode(["status" => "error", "message" => "La fecha de venta pertenece a un día ya cerrado. Registre la operación en la fecha operativa actual."]);
        return;
    }
    
    $pdo->beginTransaction();
    $orderStmt = $pdo->prepare("SELECT `items`, `total`, `reservation_id` FROM `pedidos` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `status` = 'pendiente' FOR UPDATE");
    $orderStmt->execute(['id' => $input['id'], 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    $existingOrder = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$existingOrder) {
        $pdo->rollBack();
        echo json_encode(["status" => "error", "message" => "Pedido no encontrado o ya fue procesado"]);
        return;
    }
    try {
        $paymentMethod = normalizeOrderPaymentMethod($paymentMethod, (float)$existingOrder['total']);
    } catch (InvalidArgumentException $e) {
        $pdo->rollBack();
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        return;
    }
    $stmt = $pdo->prepare("UPDATE `pedidos` SET `status` = 'completado', `payment_method` = :method, `paid` = 1, `paid_by_user_id` = :paid_by_user_id, `paid_by_name` = :paid_by_name, `sold_at` = COALESCE(`sold_at`, :sold_at) WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `status` = 'pendiente'");
    $stmt->execute(['method' => $paymentMethod, 'paid_by_user_id' => $authContext['user_id'] ?? null, 'paid_by_name' => $authContext['user_name'] ?? null, 'sold_at' => $soldAt, 'id' => $input['id'], 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    if ($stmt->rowCount() === 0) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(["status" => "error", "message" => "Pedido no encontrado o ya fue procesado"]);
        return;
    }
    if (!empty($existingOrder['reservation_id'])) {
        $reservationPaid = $pdo->prepare("UPDATE `reservations` SET `paid_by_user_id` = :user_id, `paid_by_name` = :user_name, `updated_at` = NOW() WHERE `id` = :reservation_id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
        $reservationPaid->execute(['user_id' => $authContext['user_id'] ?? null, 'user_name' => $authContext['user_name'] ?? null, 'reservation_id' => $existingOrder['reservation_id'], 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    }
    persistOrderHistory($pdo, $authContext, (string)$input['id'], json_decode($existingOrder['items'] ?? '[]', true) ?: [], $paymentMethod, (float)($existingOrder['total'] ?? 0), true, array_key_exists('appliedDiscounts', $input) ? $input['appliedDiscounts'] : null);
    writeAuditLog($pdo, $authContext, 'order.complete', 'pedido', $input['id']);
    $pdo->commit();
    broadcastWebSocketEvent('order.completed', ['id' => $input['id']], $authContext);
    echo json_encode(["status" => "success"]);
}

function orderCancellationLineTotal(array $item, float $quantity): float {
    $unitPrice = (float)($item['price'] ?? 0);
    foreach (($item['salsas'] ?? []) as $salsa) $unitPrice += (float)($salsa['salsaPrice'] ?? 0);
    foreach (($item['accompaniments'] ?? []) as $accompaniment) $unitPrice += (float)($accompaniment['accompanimentPrice'] ?? 0);
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
    $cancelledItems = [];
    $cancelledTotal = 0.0;

    if (!$isFullCancellation) {
        $requestedByLine = [];
        foreach ($requestedItems as $requested) {
            $lineNo = (int)($requested['line_no'] ?? -1);
            $quantity = (float)($requested['quantity'] ?? 0);
            if ($lineNo < 0 || !isset($orderItems[$lineNo]) || $quantity <= 0 || floor($quantity) !== $quantity) {
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
            if ($cancelQuantity > 0) {
                $cancelledItem = $item;
                $cancelledItem['qty'] = (int)$cancelQuantity;
                $cancelledItems[] = $cancelledItem;
            }
            if ($remainingQuantity > 0.0001) {
                $item['qty'] = $remainingQuantity;
                $remainingItems[] = $item;
            }
        }
        if ($cancelledTotal <= 0) throw new InvalidArgumentException('Seleccione al menos un producto');
        $isFullCancellation = !$remainingItems;
    }

    if ($isFullCancellation) {
        releaseStock($pdo, $authContext, 'order', (string)$inputId);
        $stmt = $pdo->prepare("UPDATE `pedidos` SET `status` = 'anulado' WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
        $stmt->execute(['id' => $inputId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    } else {
        releaseStock($pdo, $authContext, 'order', (string)$inputId, $cancelledItems);
        $newSubtotal = 0.0;
        foreach ($remainingItems as $ri) {
            $salsaTotal = array_sum(array_map(static fn($s) => (float)($s['salsaPrice'] ?? 0), $ri['salsas'] ?? []));
            $accompanimentTotal = array_sum(array_map(static fn($a) => (float)($a['accompanimentPrice'] ?? 0), $ri['accompaniments'] ?? []));
            $newSubtotal += ((float)($ri['price'] ?? 0) + $salsaTotal + $accompanimentTotal) * (int)($ri['qty'] ?? $ri['quantity'] ?? 1);
        }
        $newSubtotal = round($newSubtotal, 2);
        $previousDiscount = max(0, min((float)($order['discount_total'] ?? 0), $newSubtotal));
        $newTotal = max(0, round($newSubtotal - $previousDiscount, 2));
        $stmt = $pdo->prepare("UPDATE `pedidos` SET `items` = :items, `total` = :total, `subtotal` = :subtotal, `discount_total` = :discount_total WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `status` = 'pendiente'");
        $stmt->execute(['items' => json_encode($remainingItems, JSON_UNESCAPED_UNICODE), 'total' => $newTotal, 'subtotal' => $newSubtotal, 'discount_total' => $previousDiscount, 'id' => $inputId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
        $order['items'] = json_encode($remainingItems, JSON_UNESCAPED_UNICODE);
        $order['total'] = $newTotal;
        persistOrderHistory($pdo, $authContext, (string)$inputId, $remainingItems, $order['payment_method'] ?? 'efectivo', $newTotal, !empty($order['paid']), null);
    }
    $afterOrder = $order;
    if ($isFullCancellation) $afterOrder['status'] = 'anulado';
    writeAuditLog($pdo, $authContext, $isFullCancellation ? 'order.cancel' : 'order.cancel_partial', 'pedido', $inputId, ['cancelled_items' => $requestedItems], $input['reason'] ?? null, $beforeOrder, $afterOrder);
    $pdo->commit();
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

    $wasPaid = !empty($order['paid']);
    $markPaid = !empty($input['markPaid']);
    $newPaymentMethod = $input['paymentMethod'] ?? null;
    if ($wasPaid && !$markPaid) {
        throw new InvalidArgumentException('La comanda ya está pagada. Debe registrar el pago de los nuevos productos.');
    }
    if (($wasPaid || $markPaid) && $newPaymentMethod === null) {
        throw new InvalidArgumentException('Seleccione el método de pago para los nuevos productos.');
    }

    $existingItems = json_decode($order['items'], true) ?? [];
    _backfillProductsForTenant($pdo, $authContext);
    $newItems = canonicalizeOrderItems($pdo, $authContext, $newItems);
    $additionBatchId = 'add_' . bin2hex(random_bytes(12));
    $additionTimestamp = date('c');
    $addedTotal = 0;
    foreach ($newItems as $item) {
        $newItem = $item;
        $newItem['serviceType'] = $item['serviceType'] ?? 'servirse';
        $newItem['isAdditional'] = true;
        $newItem['additionBatchId'] = $additionBatchId;
        $newItem['addedByUserId'] = $authContext['user_id'] ?? null;
        $newItem['addedByName'] = $authContext['user_name'] ?? null;
        $newItem['addedAt'] = $additionTimestamp;
        if ($markPaid) {
            $newItem['paid'] = true;
        }
        $existingItems[] = $newItem;
        $addedTotal += orderCancellationLineTotal($newItem, (int)$newItem['qty']);
    }

    if ($wasPaid || $markPaid) {
        $allowedPaymentMethods = ['efectivo', 'qr', 'tarjeta'];
        if (is_array($newPaymentMethod)) {
            $newPaymentSum = 0.0;
            foreach ($newPaymentMethod as $method => $amount) {
                if (!in_array($method, $allowedPaymentMethods, true) || !is_numeric($amount) || (float)$amount < 0) {
                    throw new InvalidArgumentException('Método de pago inválido para los nuevos productos.');
                }
                $newPaymentSum += (float)$amount;
            }
        } else {
            if (!in_array((string)$newPaymentMethod, $allowedPaymentMethods, true)) {
                throw new InvalidArgumentException('Método de pago inválido para los nuevos productos.');
            }
            $newPaymentSum = $addedTotal;
        }
        if (abs(round($newPaymentSum, 2) - round($addedTotal, 2)) > 0.01) {
            throw new InvalidArgumentException('El pago de los nuevos productos no coincide con su total.');
        }
    }

    reserveStock($pdo, $authContext, 'order', $orderId, $existingItems);
    $newSubtotal = 0.0;
    foreach ($existingItems as $item) {
        $newSubtotal += orderCancellationLineTotal($item, (int)($item['qty'] ?? $item['quantity'] ?? 1));
    }
    $existingDiscount = max(0, min((float)($order['discount_total'] ?? 0), $newSubtotal));
    $newTotal = round($newSubtotal - $existingDiscount, 2);

    $finalPaid = $wasPaid || $markPaid;
    // Adding items never closes the active order; the table remains occupied
    // until the explicit "Cerrar" action completes the sale.
    $finalStatus = 'pendiente';
    $historyPaymentMethod = $order['payment_method'] ?? 'efectivo';
    if ($finalPaid) {
        $paymentParts = [];
        $paymentRows = $pdo->prepare("SELECT `payment_method`, `amount` FROM `pedido_pagos` WHERE `order_id` = :order_id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
        $paymentRows->execute(['order_id' => $orderId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
        foreach ($paymentRows->fetchAll(PDO::FETCH_ASSOC) as $paymentRow) {
            $method = (string)($paymentRow['payment_method'] ?? 'efectivo');
            $paymentParts[$method] = ($paymentParts[$method] ?? 0) + (float)$paymentRow['amount'];
        }
        if (!$paymentParts) {
            foreach (orderHistoryPayments($historyPaymentMethod, (float)($order['total'] ?? 0), true) as $method => $amount) {
                $paymentParts[$method] = ($paymentParts[$method] ?? 0) + $amount;
            }
        }
        foreach (orderHistoryPayments($newPaymentMethod, $addedTotal, true) as $method => $amount) {
            $paymentParts[$method] = ($paymentParts[$method] ?? 0) + $amount;
        }
        $historyPaymentMethod = $paymentParts;
    }

    $stmt = $pdo->prepare("UPDATE `pedidos` SET `items` = :items, `subtotal` = :subtotal, `discount_total` = :discount_total, `total` = :total, `paid` = :paid, `payment_method` = :payment_method, `status` = :status_value WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    $stmt->execute([
        'items' => json_encode($existingItems, JSON_UNESCAPED_UNICODE),
        'subtotal' => round($newSubtotal, 2),
        'discount_total' => round($existingDiscount, 2),
        'total' => $newTotal,
        'paid' => $finalPaid ? 1 : 0,
        'payment_method' => is_array($historyPaymentMethod) ? json_encode($historyPaymentMethod, JSON_UNESCAPED_UNICODE) : $historyPaymentMethod,
        'status_value' => $finalStatus,
        'id' => $orderId,
        'tenant_id' => $authContext['tenant_id'],
        'branch_id' => $authContext['branch_id']
    ]);

    persistOrderHistory($pdo, $authContext, $orderId, $existingItems, $historyPaymentMethod, $newTotal, $finalPaid);
    writeAuditLog($pdo, $authContext, 'order.items.append', 'pedido', $orderId, ['added_count' => count($newItems), 'new_total' => $newTotal]);
    $pdo->commit();
    broadcastWebSocketEvent('order.appended', ['id' => $orderId, 'total' => $newTotal, 'status' => $finalStatus, 'paid' => $finalPaid], $authContext);
    echo json_encode(["status" => "success", "total" => $newTotal, "items" => $existingItems, "additionBatchId" => $additionBatchId, "statusValue" => $finalStatus, "paid" => $finalPaid, "paymentMethod" => $historyPaymentMethod]);
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
        $stmt = $pdo->prepare("SELECT * FROM `pedidos` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `closure_id` IS NULL AND `status` = 'completado' FOR UPDATE");
        $stmt->execute(['id' => $input['id'], 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            $pdo->rollBack();
            echo json_encode(["status" => "error", "message" => "Venta no encontrada o ya fue procesada."]);
            return;
        }

        releaseStock($pdo, $authContext, 'order', (string)$input['id']);

        if (($order['payment_method'] ?? '') === 'credito') {
            $pdo->prepare("UPDATE `cuentas_por_cobrar` SET `status` = 'cancelada', `balance` = 0 WHERE `order_id` = :order_id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `status` <> 'cancelada'")->execute(['order_id' => $input['id'], 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
        }

        $stmt = $pdo->prepare("UPDATE `pedidos` SET `status` = 'anulado' WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
        $stmt->execute(['id' => $input['id'], 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
        $afterOrder = $order;
        $afterOrder['status'] = 'anulado';
        writeAuditLog($pdo, $authContext, 'sale.annul', 'pedido', $input['id'], [], $input['reason'] ?? null, $order, $afterOrder);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    echo json_encode(["status" => "success"]);
}
