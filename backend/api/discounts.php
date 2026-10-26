<?php
// Discounts action handlers

function handle_get_discounts(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];

    $stmt = $pdo->prepare("SELECT * FROM `discounts` WHERE `tenant_id` = :tid AND `branch_id` = :bid ORDER BY `created_at` DESC");
    $stmt->execute(['tid' => $tid, 'bid' => $bid]);
    $discounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($discounts as &$d) {
        $d['value'] = (float)$d['value'];
        $d['min_quantity'] = (int)$d['min_quantity'];
        $d['free_quantity'] = (int)$d['free_quantity'];
        $d['active'] = (bool)$d['active'];
        $d['applicable_types'] = json_decode($d['applicable_types'] ?? 'null', true);
    }

    echo json_encode(["status" => "success", "discounts" => $discounts]);
}

function handle_save_discount(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];

    $id = $input['id'] ?? null;
    $name = trim($input['name'] ?? '');
    $type = $input['type'] ?? 'percentage';
    $value = (float)($input['value'] ?? 0);
    $minQuantity = (int)($input['min_quantity'] ?? 0);
    $freeQuantity = (int)($input['free_quantity'] ?? 0);
    $applicableTypes = $input['applicable_types'] ?? null;
    $startDate = $input['start_date'] ?? null;
    $endDate = $input['end_date'] ?? null;
    $active = isset($input['active']) ? (int)$input['active'] : 1;

    if ($name === '') {
        echo json_encode(["status" => "error", "message" => "Nombre requerido"]);
        return;
    }

    if (!in_array($type, ['percentage', 'fixed', 'buy_x_get_y'])) {
        echo json_encode(["status" => "error", "message" => "Tipo de descuento inválido"]);
        return;
    }

    $applicableTypesJson = $applicableTypes ? json_encode($applicableTypes) : null;
    $pdo->beginTransaction();

    if ($id) {
        $stmt = $pdo->prepare("UPDATE `discounts` SET `name` = :name, `type` = :type, `value` = :value, `min_quantity` = :min_qty, `free_quantity` = :free_qty, `applicable_types` = :app_types, `start_date` = :start_date, `end_date` = :end_date, `active` = :active WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
        $stmt->execute([
            'name' => $name, 'type' => $type, 'value' => $value, 'min_qty' => $minQuantity,
            'free_qty' => $freeQuantity, 'app_types' => $applicableTypesJson,
            'start_date' => $startDate, 'end_date' => $endDate, 'active' => $active,
            'id' => $id, 'tid' => $tid, 'bid' => $bid
        ]);
    } else {
        $id = 'disc_' . bin2hex(random_bytes(12));
        $stmt = $pdo->prepare("INSERT INTO `discounts` (`id`, `tenant_id`, `branch_id`, `name`, `type`, `value`, `min_quantity`, `free_quantity`, `applicable_types`, `start_date`, `end_date`, `active`) VALUES (:id, :tid, :bid, :name, :type, :value, :min_qty, :free_qty, :app_types, :start_date, :end_date, :active)");
        $stmt->execute([
            'id' => $id, 'tid' => $tid, 'bid' => $bid, 'name' => $name, 'type' => $type,
            'value' => $value, 'min_qty' => $minQuantity, 'free_qty' => $freeQuantity,
            'app_types' => $applicableTypesJson, 'start_date' => $startDate,
            'end_date' => $endDate, 'active' => $active
        ]);
    }

    cacheInvalidateTenant('catalog', $tid, $bid);
    writeAuditLog($pdo, $authContext, 'discount.save', 'discount', $id);
    $pdo->commit();
    echo json_encode(["status" => "success", "id" => $id]);
}

function handle_delete_discount(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    $id = $input['id'] ?? null;
    if (!$id) {
        echo json_encode(["status" => "error", "message" => "ID requerido"]);
        return;
    }
    $pdo->beginTransaction();

    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];
    $stmt = $pdo->prepare("DELETE FROM `discounts` WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
    $stmt->execute(['id' => $id, 'tid' => $tid, 'bid' => $bid]);

    cacheInvalidateTenant('catalog', $tid, $bid);
    writeAuditLog($pdo, $authContext, 'discount.delete', 'discount', $id);
    $pdo->commit();
    echo json_encode(["status" => "success"]);
}

function handle_calculate_discount(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'pos');
    $items = $input['items'] ?? [];
    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];

    $stmt = $pdo->prepare("SELECT * FROM `discounts` WHERE `tenant_id` = :tid AND `branch_id` = :bid AND `active` = 1 AND (start_date IS NULL OR start_date <= CURDATE()) AND (end_date IS NULL OR end_date >= CURDATE())");
    $stmt->execute(['tid' => $tid, 'bid' => $bid]);
    $activeDiscounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalDiscount = 0;
    $appliedDiscounts = [];

    foreach ($activeDiscounts as $discount) {
        $appTypes = json_decode($discount['applicable_types'] ?? 'null', true);
        $eligibleItems = [];
        foreach ($items as $item) {
            $itemType = $item['type'] ?? '';
            if ($appTypes && !in_array($itemType, $appTypes)) continue;
            $eligibleItems[] = $item;
        }

        if (empty($eligibleItems)) continue;

        if ($discount['type'] === 'percentage') {
            $subtotal = 0;
            foreach ($eligibleItems as $item) {
                $subtotal += (float)($item['price'] ?? 0) * (int)($item['quantity'] ?? 1);
            }
            $discAmount = round($subtotal * (float)$discount['value'] / 100, 2);
            if ($discAmount > 0) {
                $totalDiscount += $discAmount;
                $appliedDiscounts[] = ['id' => $discount['id'], 'name' => $discount['name'], 'amount' => $discAmount];
            }
        } elseif ($discount['type'] === 'fixed') {
            $totalQty = 0;
            foreach ($eligibleItems as $item) {
                $totalQty += (int)($item['quantity'] ?? 1);
            }
            if ($totalQty >= ($discount['min_quantity'] ?: 1)) {
                $discAmount = (float)$discount['value'];
                $totalDiscount += $discAmount;
                $appliedDiscounts[] = ['id' => $discount['id'], 'name' => $discount['name'], 'amount' => $discAmount];
            }
        } elseif ($discount['type'] === 'buy_x_get_y') {
            $totalCount = 0;
            foreach ($eligibleItems as $item) {
                $totalCount += (int)($item['quantity'] ?? 1);
            }
            $buyQty = $discount['min_quantity'] ?: 1;
            $freeQty = $discount['free_quantity'] ?: 1;
            if ($totalCount >= $buyQty + $freeQty) {
                $groups = intdiv($totalCount, $buyQty + $freeQty);
                $avgPrice = 0;
                $totalEligibleQty = 0;
                foreach ($eligibleItems as $item) {
                    $avgPrice += (float)($item['price'] ?? 0) * (int)($item['quantity'] ?? 1);
                    $totalEligibleQty += (int)($item['quantity'] ?? 1);
                }
                $avgPrice = $totalEligibleQty > 0 ? $avgPrice / $totalEligibleQty : 0;
                $discAmount = round($groups * $freeQty * $avgPrice, 2);
                if ($discAmount > 0) {
                    $totalDiscount += $discAmount;
                    $appliedDiscounts[] = ['id' => $discount['id'], 'name' => $discount['name'], 'amount' => $discAmount];
                }
            }
        }
    }

    echo json_encode([
        "status" => "success",
        "total_discount" => round($totalDiscount, 2),
        "applied_discounts" => $appliedDiscounts
    ]);
}
