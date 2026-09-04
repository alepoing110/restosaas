<?php
// Reservation action handlers

function reservationStockUsage(array $items): array {
    $usage = [];
    $add = function ($id, $quantity) use (&$usage): void {
        $id = trim((string)$id);
        $quantity = (int)$quantity;
        if ($id !== '' && $quantity > 0) $usage[$id] = ($usage[$id] ?? 0) + $quantity;
    };
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $quantity = max(1, (int)($item['quantity'] ?? $item['qty'] ?? 1));
        $type = $item['type'] ?? '';
        if ($type === 'almuerzo') {
            $add($item['sopaId'] ?? null, $quantity);
            $add($item['segundoId'] ?? null, $quantity);
            continue;
        }
        $id = match ($type) {
            'sopa' => $item['sopaId'] ?? $item['id'] ?? $item['product_id'] ?? null,
            'segundo' => $item['segundoId'] ?? $item['id'] ?? $item['product_id'] ?? null,
            'plato_extra' => $item['platoId'] ?? $item['id'] ?? $item['product_id'] ?? null,
            'extra', 'refresco' => $item['extraId'] ?? $item['id'] ?? $item['product_id'] ?? null,
            default => $item['id'] ?? $item['product_id'] ?? null,
        };
        $add($id, $quantity);
    }
    return $usage;
}

function syncReservationStock(PDO $pdo, array $authContext, array $previousItems, array $nextItems): void {
    $previous = reservationStockUsage($previousItems);
    $next = reservationStockUsage($nextItems);
    $reserve = $pdo->prepare("UPDATE `products` SET `stock` = `stock` - :quantity WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `active` = 1 AND `stock` >= :quantity");
    $release = $pdo->prepare("UPDATE `products` SET `stock` = `stock` + :quantity WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    foreach (array_unique(array_merge(array_keys($previous), array_keys($next))) as $productId) {
        $difference = ($next[$productId] ?? 0) - ($previous[$productId] ?? 0);
        if ($difference === 0) continue;
        $params = ['id' => $productId, 'quantity' => abs($difference), 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']];
        if ($difference > 0) {
            $reserve->execute($params);
            if ($reserve->rowCount() !== 1) {
                throw new InvalidArgumentException('Stock insuficiente o producto no disponible para la reserva.');
            }
        } else {
            $release->execute($params);
            if ($release->rowCount() !== 1) {
                throw new InvalidArgumentException('No se pudo restaurar el stock de la reserva.');
            }
        }
    }
}

function handle_get_reservations(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    $date = trim($input['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }
    $statusFilter = trim($input['status'] ?? '');

    $sql = "SELECT * FROM `reservations` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `reservation_date` = :date";
    $params = [
        'tenant_id' => $authContext['tenant_id'],
        'branch_id' => $authContext['branch_id'],
        'date' => $date
    ];
    if ($statusFilter !== '' && in_array($statusFilter, ['pendiente', 'confirmada', 'cancelada', 'completada'], true)) {
        $sql .= " AND `status` = :status";
        $params['status'] = $statusFilter;
    }
    $sql .= " ORDER BY `reservation_time` ASC, `created_at` ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reservations = $stmt->fetchAll();
    foreach ($reservations as &$r) {
        $r['party_size'] = (int)$r['party_size'];
        $r['total'] = (float)($r['total'] ?? 0);
        $r['delivery_type'] = $r['delivery_type'] ?? 'para_servirse';
        $r['items'] = !empty($r['items']) ? json_decode($r['items'], true) ?? [] : [];
    }

    echo json_encode([
        "status" => "success",
        "reservations" => $reservations
    ]);
}

function handle_save_reservation(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    $id = trim($input['id'] ?? '');
    $customerName = trim($input['customer_name'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $partySize = isset($input['party_size']) ? (int)$input['party_size'] : 1;
    $deliveryType = trim($input['delivery_type'] ?? 'para_servirse');
    $reservationDate = trim($input['reservation_date'] ?? '');
    $reservationTime = trim($input['reservation_time'] ?? '');
    $tableId = trim($input['table_id'] ?? '');
    $notes = trim($input['notes'] ?? '');
    $items = is_array($input['items'] ?? null) ? $input['items'] : [];
    $customerId = trim((string)($input['customer_id'] ?? $input['customerId'] ?? ''));

    if (!in_array($deliveryType, ['para_servirse', 'para_llevar'], true)) {
        $deliveryType = 'para_servirse';
    }

    if ($customerName === '' || $reservationDate === '' || $reservationTime === '') {
        echo json_encode(["status" => "error", "message" => "Nombre, fecha y hora son requeridos"]);
        return;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reservationDate) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $reservationTime)) {
        echo json_encode(["status" => "error", "message" => "Fecha u hora con formato inválido"]);
        return;
    }
    if ($partySize < 1 || $partySize > 50) {
        echo json_encode(["status" => "error", "message" => "Cantidad de personas inválida"]);
        return;
    }

    if ($deliveryType === 'para_servirse' && $tableId !== '') {
        $conflictSql = "SELECT id, reservation_time FROM reservations WHERE tenant_id = :tenant_id AND branch_id = :branch_id AND reservation_date = :date AND table_id = :table_id AND status IN ('pendiente','confirmada')";
        $conflictParams = [
            'tenant_id' => $authContext['tenant_id'],
            'branch_id' => $authContext['branch_id'],
            'date' => $reservationDate,
            'table_id' => $tableId
        ];
        if ($id !== '') {
            $conflictSql .= " AND id != :exclude_id";
            $conflictParams['exclude_id'] = $id;
        }
        $conflictStmt = $pdo->prepare($conflictSql);
        $conflictStmt->execute($conflictParams);
        $durationMinutes = (int)($input['duration_minutes'] ?? 120);
        while ($existing = $conflictStmt->fetch(PDO::FETCH_ASSOC)) {
            $existingTime = $existing['reservation_time'];
            $existingMinutes = (int)substr($existingTime, 0, 2) * 60 + (int)substr($existingTime, 3, 2);
            $existingEnd = $existingMinutes + $durationMinutes;
            $newMinutes = (int)substr($reservationTime, 0, 2) * 60 + (int)substr($reservationTime, 3, 2);
            $newEnd = $newMinutes + $durationMinutes;
            if ($newMinutes < $existingEnd && $newEnd > $existingMinutes) {
                echo json_encode(["status" => "error", "message" => "Esta mesa ya está reservada para ese horario"]);
                return;
            }
        }
    }

    $itemsJson = !empty($items) ? json_encode($items, JSON_UNESCAPED_UNICODE) : null;
    $total = 0.0;
    foreach ($items as $item) {
        $itemPrice = (float)($item['price'] ?? 0);
        $itemQty = max(1, (int)($item['quantity'] ?? 1));
        $salsasTotal = array_reduce($item['salsas'] ?? [], fn(float $sum, $salsa): float => $sum + (float)($salsa['salsaPrice'] ?? 0), 0.0);
        $total += ($itemPrice + $salsasTotal) * $itemQty;
    }

    $pdo->beginTransaction();
    try {
        $previousItems = [];
        if ($id === '') {
            if ($customerId !== '' && !findTenantCustomer($pdo, $authContext, $customerId)) {
                throw new InvalidArgumentException('El cliente seleccionado no pertenece a este restaurante.');
            }
            $id = 'res_' . bin2hex(random_bytes(8));
            $stmt = $pdo->prepare("INSERT INTO `reservations` (`id`, `tenant_id`, `branch_id`, `customer_id`, `customer_name`, `phone`, `party_size`, `delivery_type`, `reservation_date`, `reservation_time`, `table_id`, `items`, `total`, `notes`, `created_at`, `updated_at`) VALUES (:id, :tenant_id, :branch_id, :customer_id, :customer_name, :phone, :party_size, :delivery_type, :reservation_date, :reservation_time, :table_id, :items, :total, :notes, NOW(), NOW())");
        } else {
            $currentStmt = $pdo->prepare("SELECT `items`, `status`, `customer_id` FROM `reservations` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id FOR UPDATE");
            $currentStmt->execute(['id' => $id, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
            $current = $currentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$current || in_array($current['status'], ['cancelada', 'completada'], true)) {
                throw new InvalidArgumentException('La reserva no se puede editar en su estado actual.');
            }
            $previousItems = json_decode($current['items'] ?? '[]', true) ?: [];
            $customerId = $customerId ?: (string)($current['customer_id'] ?? '');
            if ($customerId !== '' && !findTenantCustomer($pdo, $authContext, $customerId)) {
                throw new InvalidArgumentException('El cliente seleccionado no pertenece a este restaurante.');
            }
            $stmt = $pdo->prepare("UPDATE `reservations` SET `customer_id` = :customer_id, `customer_name` = :customer_name, `phone` = :phone, `party_size` = :party_size, `delivery_type` = :delivery_type, `reservation_date` = :reservation_date, `reservation_time` = :reservation_time, `table_id` = :table_id, `items` = :items, `total` = :total, `notes` = :notes, `updated_at` = NOW() WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
        }
        $stmt->execute(['id' => $id, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id'], 'customer_id' => $customerId ?: null, 'customer_name' => $customerName, 'phone' => $phone ?: null, 'party_size' => $partySize, 'delivery_type' => $deliveryType, 'reservation_date' => $reservationDate, 'reservation_time' => $reservationTime, 'table_id' => $tableId ?: null, 'items' => $itemsJson, 'total' => $total, 'notes' => $notes ?: null]);
        if ($customerId !== '') {
            $pdo->prepare("UPDATE `clientes` SET `last_seen_at` = NOW(), `updated_at` = NOW() WHERE `id` = :id AND `tenant_id` = :tenant_id")->execute(['id' => $customerId, 'tenant_id' => $authContext['tenant_id']]);
        }
        syncReservationStock($pdo, $authContext, $previousItems, $items);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    writeAuditLog($pdo, $authContext, 'reservation.save', 'reservation', $id);
    echo json_encode(["status" => "success", "id" => $id]);
}

function handle_update_reservation_status(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    $id = trim($input['id'] ?? '');
    $newStatus = trim($input['status'] ?? '');
    $validStatuses = ['pendiente', 'confirmada', 'cancelada', 'completada'];

    if ($id === '' || !in_array($newStatus, $validStatuses, true)) {
        echo json_encode(["status" => "error", "message" => "ID y estado válido son requeridos"]);
        return;
    }

    $pdo->beginTransaction();
    try {
    $currentStmt = $pdo->prepare("SELECT status, items FROM reservations WHERE id = :id AND tenant_id = :tenant_id AND branch_id = :branch_id FOR UPDATE");
    $currentStmt->execute(['id' => $id, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    $current = $currentStmt->fetch();
    if (!$current) {
        $pdo->rollBack();
        echo json_encode(["status" => "error", "message" => "Reserva no encontrada"]);
        return;
    }

    $allowed = [
        'pendiente' => ['confirmada', 'completada', 'cancelada'],
        'confirmada' => ['completada', 'cancelada'],
    ];
    $currentStatus = $current['status'];
    if (!isset($allowed[$currentStatus]) || !in_array($newStatus, $allowed[$currentStatus], true)) {
        $pdo->rollBack();
        echo json_encode(["status" => "error", "message" => "No se puede cambiar de '$currentStatus' a '$newStatus'"]);
        return;
    }

    $stmt = $pdo->prepare("
        UPDATE `reservations` SET `status` = :status, `updated_at` = NOW()
        WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id
    ");
    $stmt->execute([
        'status' => $newStatus,
        'id' => $id,
        'tenant_id' => $authContext['tenant_id'],
        'branch_id' => $authContext['branch_id']
    ]);
    if ($newStatus === 'cancelada') {
        syncReservationStock($pdo, $authContext, json_decode($current['items'] ?? '[]', true) ?: [], []);
    }
    $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    writeAuditLog($pdo, $authContext, 'reservation.status.update', 'reservation', $id, ['status' => $newStatus]);
    echo json_encode(["status" => "success"]);
}

function handle_delete_reservation(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    $id = trim($input['id'] ?? '');
    if ($id === '') {
        echo json_encode(["status" => "error", "message" => "ID de reserva requerido"]);
        return;
    }

    $pdo->beginTransaction();
    try {
    $statusStmt = $pdo->prepare("SELECT status, items FROM reservations WHERE id = :id AND tenant_id = :tenant_id AND branch_id = :branch_id FOR UPDATE");
    $statusStmt->execute(['id' => $id, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    $row = $statusStmt->fetch();
    if (!$row) {
        $pdo->rollBack();
        echo json_encode(["status" => "error", "message" => "Reserva no encontrada"]);
        return;
    }
    if ($row['status'] === 'completada') {
        $pdo->rollBack();
        echo json_encode(["status" => "error", "message" => "No se puede eliminar una reserva completada"]);
        return;
    }

    $stmt = $pdo->prepare("DELETE FROM `reservations` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    $stmt->execute([
        'id' => $id,
        'tenant_id' => $authContext['tenant_id'],
        'branch_id' => $authContext['branch_id']
    ]);
    if ($row['status'] !== 'cancelada') {
        syncReservationStock($pdo, $authContext, json_decode($row['items'] ?? '[]', true) ?: [], []);
    }
    $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    writeAuditLog($pdo, $authContext, 'reservation.delete', 'reservation', $id);
    echo json_encode(["status" => "success"]);
}

function reservationBatchIds(array $input): array {
    $ids = $input['ids'] ?? [];
    if (!is_array($ids)) return [];
    $ids = array_values(array_unique(array_filter(array_map(fn($id) => trim((string)$id), $ids))));
    return count($ids) <= 100 ? $ids : [];
}

function handle_verify_bot_reservations(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    $ids = reservationBatchIds($input);
    if (empty($ids)) {
        echo json_encode(["status" => "error", "message" => "Seleccione entre una y 100 reservas del bot."]);
        return;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$authContext['tenant_id'], $authContext['branch_id']], $ids);
    $stmt = $pdo->prepare("UPDATE `reservations` SET `verification_status` = 'verificada', `updated_at` = NOW() WHERE `tenant_id` = ? AND `branch_id` = ? AND `id` IN ($placeholders) AND `source` = 'bot' AND `status` IN ('pendiente', 'confirmada')");
    $stmt->execute($params);
    writeAuditLog($pdo, $authContext, 'reservation.bot.verify', 'reservation', null, ['ids' => $ids]);
    echo json_encode(["status" => "success", "verified_ids" => $ids]);
}

function handle_mark_bot_reservations_printed(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    $ids = reservationBatchIds($input);
    if (empty($ids)) {
        echo json_encode(["status" => "error", "message" => "Seleccione entre una y 100 reservas del bot."]);
        return;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$authContext['tenant_id'], $authContext['branch_id']], $ids);
    $stmt = $pdo->prepare("UPDATE `reservations` SET `kitchen_printed_at` = NOW(), `updated_at` = NOW() WHERE `tenant_id` = ? AND `branch_id` = ? AND `id` IN ($placeholders) AND `source` = 'bot' AND `verification_status` = 'verificada' AND `status` IN ('pendiente', 'confirmada')");
    $stmt->execute($params);
    writeAuditLog($pdo, $authContext, 'reservation.bot.kitchen_print', 'reservation', null, ['ids' => $ids]);
    echo json_encode(["status" => "success"]);
}
