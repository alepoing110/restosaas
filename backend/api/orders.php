<?php
// Order action handlers

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
    $validPayments = ['efectivo', 'qr', 'tarjeta'];
    $paymentMethod = isset($input['paymentMethod']) ? $input['paymentMethod'] : 'efectivo';
    
    if (is_array($paymentMethod)) {
        $paymentMethod = json_encode($paymentMethod);
    } elseif (!in_array($paymentMethod, $validPayments)) {
        $paymentMethod = 'efectivo';
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
    
    $stmt = $pdo->prepare("INSERT INTO `pedidos` (`id`, `customer`, `items`, `total`, `payment_method`, `service_state`, `status`, `timestamp`, `tenant_id`, `branch_id`, `delivery_type`, `paid`) 
        VALUES (:id, :customer, :items, :total, :payment_method, :service_state, :status, :timestamp, :tenant_id, :branch_id, :delivery_type, :paid)
        ON DUPLICATE KEY UPDATE `customer` = VALUES(`customer`), `items` = VALUES(`items`), `total` = VALUES(`total`), `payment_method` = VALUES(`payment_method`), `service_state` = VALUES(`service_state`), `status` = VALUES(`status`), `delivery_type` = VALUES(`delivery_type`), `paid` = VALUES(`paid`)");
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
        'paid' => $paid
    ]);
    if ($stmt->rowCount() === 0) {
        echo json_encode(["status" => "error", "message" => "Pedido ya existe o no se pudo guardar"]);
        return;
    }
    writeAuditLog($pdo, $authContext, 'order.save', 'pedido', $input['id'], ['status' => $input['status']]);
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
        'paid' => $paid
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
    
    $stmt = $pdo->prepare("UPDATE `pedidos` SET `status` = 'completado', `payment_method` = :method WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `status` = 'pendiente'");
    $stmt->execute(['method' => $paymentMethod, 'id' => $input['id'], 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    if ($stmt->rowCount() === 0) {
        echo json_encode(["status" => "error", "message" => "Pedido no encontrado o ya fue procesado"]);
        return;
    }
    writeAuditLog($pdo, $authContext, 'order.complete', 'pedido', $input['id']);
    broadcastWebSocketEvent('order.completed', ['id' => $input['id']], $authContext);
    echo json_encode(["status" => "success"]);
}

function handle_cancel_order(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'orders');
    if (!isset($input['id'])) {
        echo json_encode(["status" => "error", "message" => "Datos requeridos: id"]);
        return;
    }
    $inputId = $input['id'];

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT * FROM `pedidos` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `status` = 'pendiente' FOR UPDATE");
    $stmt->execute(['id' => $inputId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        $pdo->rollBack();
        echo json_encode(["status" => "error", "message" => "Pedido no encontrado o ya fue procesado."]);
        return;
    }

    $stmt = $pdo->prepare("UPDATE `pedidos` SET `status` = 'anulado' WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    $stmt->execute(['id' => $inputId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    $pdo->commit();
    writeAuditLog($pdo, $authContext, 'order.cancel', 'pedido', $inputId);
    broadcastWebSocketEvent('order.cancelled', ['id' => $inputId], $authContext);
    echo json_encode(["status" => "success"]);
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

    writeAuditLog($pdo, $authContext, 'sale.annul', 'pedido', $input['id']);
    echo json_encode(["status" => "success"]);
}
