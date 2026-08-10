<?php
// Reservation action handlers

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
        $total += $itemPrice * $itemQty;
    }

    if ($id === '') {
        $id = 'res_' . bin2hex(random_bytes(8));
        $stmt = $pdo->prepare("
            INSERT INTO `reservations` (`id`, `tenant_id`, `branch_id`, `customer_name`, `phone`, `party_size`, `delivery_type`, `reservation_date`, `reservation_time`, `table_id`, `items`, `total`, `notes`, `created_at`, `updated_at`)
            VALUES (:id, :tenant_id, :branch_id, :customer_name, :phone, :party_size, :delivery_type, :reservation_date, :reservation_time, :table_id, :items, :total, :notes, NOW(), NOW())
        ");
    } else {
        $stmt = $pdo->prepare("
            UPDATE `reservations` SET `customer_name` = :customer_name, `phone` = :phone, `party_size` = :party_size,
            `delivery_type` = :delivery_type, `reservation_date` = :reservation_date, `reservation_time` = :reservation_time,
            `table_id` = :table_id, `items` = :items, `total` = :total, `notes` = :notes, `updated_at` = NOW()
            WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id
        ");
    }
    $stmt->execute([
        'id' => $id,
        'tenant_id' => $authContext['tenant_id'],
        'branch_id' => $authContext['branch_id'],
        'customer_name' => $customerName,
        'phone' => $phone ?: null,
        'party_size' => $partySize,
        'delivery_type' => $deliveryType,
        'reservation_date' => $reservationDate,
        'reservation_time' => $reservationTime,
        'table_id' => $tableId ?: null,
        'items' => $itemsJson,
        'total' => $total,
        'notes' => $notes ?: null
    ]);
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

    $currentStmt = $pdo->prepare("SELECT status FROM reservations WHERE id = :id AND tenant_id = :tenant_id AND branch_id = :branch_id");
    $currentStmt->execute(['id' => $id, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    $current = $currentStmt->fetch();
    if (!$current) {
        echo json_encode(["status" => "error", "message" => "Reserva no encontrada"]);
        return;
    }

    $allowed = [
        'pendiente' => ['confirmada', 'completada', 'cancelada'],
        'confirmada' => ['completada', 'cancelada'],
    ];
    $currentStatus = $current['status'];
    if (!isset($allowed[$currentStatus]) || !in_array($newStatus, $allowed[$currentStatus], true)) {
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

    $statusStmt = $pdo->prepare("SELECT status FROM reservations WHERE id = :id AND tenant_id = :tenant_id AND branch_id = :branch_id");
    $statusStmt->execute(['id' => $id, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    $row = $statusStmt->fetch();
    if (!$row) {
        echo json_encode(["status" => "error", "message" => "Reserva no encontrada"]);
        return;
    }
    if ($row['status'] === 'completada') {
        echo json_encode(["status" => "error", "message" => "No se puede eliminar una reserva completada"]);
        return;
    }

    $stmt = $pdo->prepare("DELETE FROM `reservations` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    $stmt->execute([
        'id' => $id,
        'tenant_id' => $authContext['tenant_id'],
        'branch_id' => $authContext['branch_id']
    ]);
    writeAuditLog($pdo, $authContext, 'reservation.delete', 'reservation', $id);
    echo json_encode(["status" => "success"]);
}
