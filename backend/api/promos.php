<?php
// Promotion engine: suggest, validate, apply promo plans and coupons.

function promoRequireSchema(PDO $pdo): void {
    $stmt = $pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('discounts', 'coupon_usage_log', 'pedido_cotizaciones', 'promo_groups', 'promo_group_products')");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($tables) < 5) throw new RuntimeException('Promociones requiere ejecutar las migraciones de base de datos');
}

function promoNow(): string {
    date_default_timezone_set('America/La_Paz');
    return date('Y-m-d H:i:s');
}

function promoToday(): string {
    return date('Y-m-d');
}

function promoCurrentHour(): string {
    return date('H:i:s');
}

function promoCurrentDay(): string {
    return strtolower(date('l'));
}

function promoParseChannels($channels): array {
    if (is_null($channels)) return ['pos', 'mesa', 'llevar', 'delivery', 'reserva'];
    if (is_string($channels)) {
        $decoded = json_decode($channels, true);
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }
        return is_array($decoded) ? $decoded : [$channels];
    }
    return $channels;
}

function promoParseDays($days): ?array {
    if (is_null($days)) return null;
    if (is_string($days)) {
        $decoded = json_decode($days, true);
        return is_array($decoded) ? $decoded : null;
    }
    return $days;
}

function promoLoadGroups(PDO $pdo, string $discountId, string $tenantId, string $branchId): array {
    $groupsStmt = $pdo->prepare("SELECT * FROM `promo_groups` WHERE `discount_id` = :did AND `tenant_id` = :tid AND `branch_id` = :bid ORDER BY `group_no` ASC");
    $groupsStmt->execute(['did' => $discountId, 'tid' => $tenantId, 'bid' => $branchId]);
    $groups = $groupsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$groups) return [];
    $productsStmt = $pdo->prepare("SELECT * FROM `promo_group_products` WHERE `group_id` = :gid AND `tenant_id` = :tid AND `branch_id` = :bid");
    foreach ($groups as &$group) {
        $group['quantity_required'] = (int)$group['quantity_required'];
        $group['free_quantity'] = (int)$group['free_quantity'];
        $productsStmt->execute(['gid' => $group['id'], 'tid' => $tenantId, 'bid' => $branchId]);
        $group['products'] = $productsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    unset($group);
    return $groups;
}

function promoItemMatchesTarget(array $item, array $target): bool {
    $productId = (string)($item['product_id'] ?? $item['productId'] ?? '');
    $productType = (string)($item['product_type'] ?? $item['productType'] ?? $item['type'] ?? '');
    if (!empty($target['product_id'])) return $productId === (string)$target['product_id'];
    return $productType === (string)($target['product_type'] ?? '');
}

function promoItemPrice(array $item): float {
    return (float)($item['price'] ?? $item['unit_price'] ?? 0);
}

function promoEligibleByGroups(array $items, array $groups): array {
    $eligible = [];
    foreach ($groups as $group) {
        $matches = [];
        foreach ($items as $item) {
            foreach (($group['products'] ?? []) as $target) {
                if (promoItemMatchesTarget($item, $target)) { $matches[] = $item; break; }
            }
        }
        $required = (int)$group['quantity_required'] + (int)$group['free_quantity'];
        $count = array_sum(array_map(fn($item) => (int)($item['quantity'] ?? $item['qty'] ?? 1), $matches));
        if (!$matches || $count < max(1, $required)) return [false, [], 'No se cumple la cantidad de productos requerida'];
        $eligible = array_merge($eligible, $matches);
    }
    return [true, $eligible, ''];
}

function promoCheckRequirements(array $plan, array $items, float $subtotal, string $channel, array $groups = []): array {
    $eligible = [];
    $reasons = [];

    if (!$plan['active']) return [false, ['Plan inactivo'], []];

    $now = promoToday();
    if (!empty($plan['start_date']) && $plan['start_date'] > $now) { $reasons[] = 'Aún no inicia'; return [false, $reasons, []]; }
    if (!empty($plan['end_date']) && $plan['end_date'] < $now) { $reasons[] = 'Ya venció'; return [false, $reasons, []]; }

    $channels = promoParseChannels($plan['channels'] ?? null);
    // POS is the general point-of-sale channel; specific POS locations remain
    // available when a promotion must be restricted to mesa, llevar or delivery.
    $channelAllowed = in_array($channel, $channels, true)
        || ($channel !== 'reserva' && in_array('pos', $channels, true));
    if (!$channelAllowed) { $reasons[] = 'No aplica para este canal'; return [false, $reasons, []]; }

    $days = promoParseDays($plan['applicable_days'] ?? null);
    if ($days && !in_array(promoCurrentDay(), $days, true)) { $reasons[] = 'No aplica este día'; return [false, $reasons, []]; }

    if (!empty($plan['start_hour']) && !empty($plan['end_hour'])) {
        $nowTime = promoCurrentHour();
        $isOvernight = $plan['start_hour'] > $plan['end_hour'];
        $insideSchedule = $isOvernight
            ? ($nowTime >= $plan['start_hour'] || $nowTime <= $plan['end_hour'])
            : ($nowTime >= $plan['start_hour'] && $nowTime <= $plan['end_hour']);
        if (!$insideSchedule) {
            $reasons[] = 'Fuera del horario válido';
            return [false, $reasons, []];
        }
    }

    if (!empty($plan['min_subtotal']) && $subtotal < (float)$plan['min_subtotal']) {
        $reasons[] = 'Subtotal mínimo Bs ' . number_format((float)$plan['min_subtotal'], 2);
        return [false, $reasons, []];
    }

    if ($groups) {
        [$groupOk, $eligible, $groupReason] = promoEligibleByGroups($items, $groups);
        if (!$groupOk) return [false, [$groupReason], []];
    } else {

    $appTypes = json_decode($plan['applicable_types'] ?? 'null', true);
    if ($appTypes) {
        foreach ($items as $item) {
            $itemType = $item['type'] ?? '';
            if (in_array($itemType, $appTypes, true)) {
                $eligible[] = $item;
            }
        }
        if (empty($eligible)) {
            $reasons[] = 'No hay productos elegibles para esta promoción';
            return [false, $reasons, []];
        }
    } else {
        $eligible = $items;
    }
    }

    if (!empty($plan['min_quantity'])) {
        $totalQty = 0;
        foreach ($eligible as $item) $totalQty += (int)($item['quantity'] ?? $item['qty'] ?? 1);
        if ($totalQty < (int)$plan['min_quantity']) {
            $reasons[] = 'Se requieren mínimo ' . $plan['min_quantity'] . ' unidades';
            return [false, $reasons, []];
        }
    }

    return [true, [], $eligible];
}

function promoCalcDiscount(array $plan, array $eligibleItems, float $subtotal): float {
    $type = $plan['type'];
    $value = (float)$plan['value'];

    if ($type === 'percentage') {
        $basis = 0;
        foreach ($eligibleItems as $item) {
            $basis += promoItemPrice($item) * (int)($item['quantity'] ?? $item['qty'] ?? 1);
        }
        return round($basis * $value / 100, 2);
    }

    if ($type === 'fixed') {
        $eligibleTotal = 0;
        foreach ($eligibleItems as $item) {
            $eligibleTotal += promoItemPrice($item) * (int)($item['quantity'] ?? $item['qty'] ?? 1);
        }
        return min($value, $eligibleTotal);
    }

    if ($type === 'buy_x_get_y') {
        $totalCount = 0;
        foreach ($eligibleItems as $item) $totalCount += (int)($item['quantity'] ?? $item['qty'] ?? 1);
        $buyQty = max(1, (int)($plan['min_quantity'] ?? 1));
        $freeQty = max(1, (int)($plan['free_quantity'] ?? 1));
        if ($totalCount < $buyQty + $freeQty) return 0;
        $groups = intdiv($totalCount, $buyQty + $freeQty);
        $unitPrices = [];
        foreach ($eligibleItems as $item) {
            $unitPrice = promoItemPrice($item);
            $quantity = (int)($item['quantity'] ?? $item['qty'] ?? 1);
            for ($i = 0; $i < $quantity; $i++) $unitPrices[] = $unitPrice;
        }
        sort($unitPrices, SORT_NUMERIC);
        $freeUnits = min(count($unitPrices), $groups * $freeQty);
        return round(array_sum(array_slice($unitPrices, 0, $freeUnits)), 2);
    }

    if ($type === 'menu_price') {
        $totalItemPrice = 0;
        foreach ($eligibleItems as $item) {
            $totalItemPrice += promoItemPrice($item) * (int)($item['quantity'] ?? $item['qty'] ?? 1);
        }
        $promoPrice = $value;
        return max(0, round($totalItemPrice - $promoPrice, 2));
    }

    return 0;
}

function promoCheckLimits(PDO $pdo, array $plan, array $authContext): array {
    $reasons = [];

    if (!empty($plan['coupon_code'])) {
        $code = strtoupper(trim($plan['coupon_code']));
        $planUses = $pdo->prepare("SELECT COUNT(*) FROM `coupon_usage_log` WHERE `discount_id` = :did AND `tenant_id` = :tid AND `branch_id` = :bid");
        $planUses->execute(['did' => $plan['id'], 'tid' => $authContext['tenant_id'], 'bid' => $authContext['branch_id']]);
        $usedCount = (int)$planUses->fetchColumn();

        if (!empty($plan['max_global_uses']) && $usedCount >= (int)$plan['max_global_uses']) {
            $reasons[] = 'Cupón agotado (' . $usedCount . '/' . $plan['max_global_uses'] . ' usos)';
        }
    }

    return [empty($reasons), $reasons];
}

function handle_suggest_promos(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'pos');
    try {
        promoRequireSchema($pdo);
        $items = $input['items'] ?? [];
        $channel = $input['channel'] ?? 'pos';
        $couponCode = strtoupper(trim($input['coupon_code'] ?? ''));
        $tid = $authContext['tenant_id'];
        $bid = $authContext['branch_id'];
        $now = promoNow();

        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += promoItemPrice($item) * (int)($item['quantity'] ?? $item['qty'] ?? 1);
        }

        $stmt = $pdo->prepare("SELECT * FROM `discounts` WHERE `tenant_id` = :tid AND `branch_id` = :bid AND `active` = 1");
        $stmt->execute(['tid' => $tid, 'bid' => $bid]);
        $allPlans = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $groupMap = [];
        foreach ($allPlans as $loadedPlan) {
            $groupMap[$loadedPlan['id']] = promoLoadGroups($pdo, $loadedPlan['id'], $tid, $bid);
        }

        $suggested = [];
        $couponPlan = null;
        $couponError = null;

        foreach ($allPlans as $plan) {
            $plan['value'] = (float)$plan['value'];
            $plan['min_quantity'] = (int)$plan['min_quantity'];
            $plan['free_quantity'] = (int)$plan['free_quantity'];
            $planGroups = $groupMap[$plan['id']] ?? [];
            if (count($planGroups) === 1) {
                $plan['min_quantity'] = $planGroups[0]['quantity_required'];
                $plan['free_quantity'] = $planGroups[0]['free_quantity'];
            }
            $isCoupon = !empty($plan['coupon_code']);

            if ($isCoupon && $couponCode !== '') {
                if (strtoupper($plan['coupon_code']) === $couponCode) {
                    $couponPlan = $plan;
                }
                continue;
            }
            if ($isCoupon) continue;

            [$ok, $reasons, $eligible] = promoCheckRequirements($plan, $items, $subtotal, $channel, $planGroups);
            if (!$ok) continue;

            [$limitsOk, $limitReasons] = promoCheckLimits($pdo, $plan, $authContext);
            if (!$limitsOk) { $reasons = array_merge($reasons, $limitReasons); continue; }

            $discountAmount = promoCalcDiscount($plan, $eligible, $subtotal);
            if ($discountAmount <= 0) continue;

            $suggested[] = [
                'id' => $plan['id'],
                'name' => $plan['name'],
                'description' => $plan['description'] ?? '',
                'type' => $plan['type'],
                'value' => $plan['value'],
                'discount_amount' => round(min($discountAmount, $subtotal), 2),
                'priority' => (int)$plan['priority'],
                'stackable' => (bool)$plan['stackable'],
            ];
        }

        if ($couponCode !== '' && $couponPlan) {
            $couponGroups = $groupMap[$couponPlan['id']] ?? [];
            if (count($couponGroups) === 1) {
                $couponPlan['min_quantity'] = $couponGroups[0]['quantity_required'];
                $couponPlan['free_quantity'] = $couponGroups[0]['free_quantity'];
            }
            [$ok, $reasons, $eligible] = promoCheckRequirements($couponPlan, $items, $subtotal, $channel, $couponGroups);
            [$limitsOk, $limitReasons] = promoCheckLimits($pdo, $couponPlan, $authContext);
            if ($ok && $limitsOk) {
                $discountAmount = promoCalcDiscount($couponPlan, $eligible, $subtotal);
                if ($discountAmount > 0) {
                    $suggested[] = [
                        'id' => $couponPlan['id'],
                        'name' => $couponPlan['name'],
                        'description' => $couponPlan['description'] ?? '',
                        'type' => $couponPlan['type'],
                        'value' => $couponPlan['value'],
                        'discount_amount' => round(min($discountAmount, $subtotal), 2),
                        'priority' => (int)$couponPlan['priority'],
                        'stackable' => (bool)$couponPlan['stackable'],
                        'coupon' => true,
                    ];
                }
            } else {
                $allReasons = array_merge($reasons, $limitReasons);
                $couponError = implode(', ', $allReasons);
            }
        } elseif ($couponCode !== '' && !$couponPlan) {
            $couponError = 'Cupón no encontrado o inactivo';
        }

        usort($suggested, fn($a, $b) => $b['priority'] <=> $a['priority'] ?: $b['discount_amount'] <=> $a['discount_amount']);

        echo json_encode([
            'status' => 'success',
            'subtotal' => round($subtotal, 2),
            'suggested' => $suggested,
            'coupon_error' => $couponError,
        ]);
    } catch (Throwable $e) {
        error_log('[RestoCloud][suggest_promos] ' . $e->getMessage());
        http_response_code($e instanceof InvalidArgumentException ? 400 : ($e instanceof RuntimeException ? 503 : 500));
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

function handle_validate_promo(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'pos');
    try {
        promoRequireSchema($pdo);
        $planId = $input['plan_id'] ?? '';
        $items = $input['items'] ?? [];
        $channel = $input['channel'] ?? 'pos';
        $tid = $authContext['tenant_id'];
        $bid = $authContext['branch_id'];

        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += promoItemPrice($item) * (int)($item['quantity'] ?? $item['qty'] ?? 1);
        }

        $stmt = $pdo->prepare("SELECT * FROM `discounts` WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
        $stmt->execute(['id' => $planId, 'tid' => $tid, 'bid' => $bid]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan) throw new InvalidArgumentException('Plan no encontrado');

        $plan['value'] = (float)$plan['value'];
        $plan['min_quantity'] = (int)$plan['min_quantity'];
        $plan['free_quantity'] = (int)$plan['free_quantity'];
        $planGroups = promoLoadGroups($pdo, $plan['id'], $tid, $bid);
        if (count($planGroups) === 1) {
            $plan['min_quantity'] = $planGroups[0]['quantity_required'];
            $plan['free_quantity'] = $planGroups[0]['free_quantity'];
        }

        [$ok, $reasons, $eligible] = promoCheckRequirements($plan, $items, $subtotal, $channel, $planGroups);
        if (!$ok) throw new InvalidArgumentException(implode(', ', $reasons));

        [$limitsOk, $limitReasons] = promoCheckLimits($pdo, $plan, $authContext);
        if (!$limitsOk) throw new InvalidArgumentException(implode(', ', $limitReasons));

        $discountAmount = promoCalcDiscount($plan, $eligible, $subtotal);
        $discountAmount = round(min($discountAmount, $subtotal), 2);

        echo json_encode([
            'status' => 'success',
            'valid' => true,
            'plan_id' => $plan['id'],
            'plan_name' => $plan['name'],
            'discount_amount' => $discountAmount,
            'subtotal' => round($subtotal, 2),
            'total' => round(max(0, $subtotal - $discountAmount), 2),
        ]);
    } catch (Throwable $e) {
        http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

function handle_apply_promo_coupon(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'pos');
    try {
        promoRequireSchema($pdo);
        $couponCode = strtoupper(trim($input['coupon_code'] ?? ''));
        $items = $input['items'] ?? [];
        $channel = $input['channel'] ?? 'pos';
        $orderId = $input['order_id'] ?? null;
        $reservationId = $input['reservation_id'] ?? null;
        $tid = $authContext['tenant_id'];
        $bid = $authContext['branch_id'];

        if ($couponCode === '') throw new InvalidArgumentException('Código de cupón requerido');

        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += promoItemPrice($item) * (int)($item['quantity'] ?? $item['qty'] ?? 1);
        }

        $stmt = $pdo->prepare("SELECT * FROM `discounts` WHERE `tenant_id` = :tid AND `branch_id` = :bid AND `active` = 1 AND `coupon_code` IS NOT NULL AND UPPER(`coupon_code`) = :code");
        $stmt->execute(['tid' => $tid, 'bid' => $bid, 'code' => $couponCode]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan) throw new InvalidArgumentException('Cupón no encontrado o inactivo');

        $plan['value'] = (float)$plan['value'];
        $plan['min_quantity'] = (int)$plan['min_quantity'];
        $plan['free_quantity'] = (int)$plan['free_quantity'];
        $planGroups = promoLoadGroups($pdo, $plan['id'], $tid, $bid);
        if (count($planGroups) === 1) {
            $plan['min_quantity'] = $planGroups[0]['quantity_required'];
            $plan['free_quantity'] = $planGroups[0]['free_quantity'];
        }

        [$ok, $reasons, $eligible] = promoCheckRequirements($plan, $items, $subtotal, $channel, $planGroups);
        if (!$ok) throw new InvalidArgumentException(implode(', ', $reasons));

        [$limitsOk, $limitReasons] = promoCheckLimits($pdo, $plan, $authContext);
        if (!$limitsOk) throw new InvalidArgumentException(implode(', ', $limitReasons));

        $discountAmount = promoCalcDiscount($plan, $eligible, $subtotal);
        $discountAmount = round(min($discountAmount, $subtotal), 2);

        $usageId = 'cuplog_' . bin2hex(random_bytes(8));
        $usageStmt = $pdo->prepare("INSERT INTO `coupon_usage_log` (id, discount_id, coupon_code, order_id, reservation_id, tenant_id, branch_id) VALUES (:id, :did, :code, :oid, :rid, :tid, :bid)");
        $usageStmt->execute([
            'id' => $usageId, 'did' => $plan['id'], 'code' => $couponCode,
            'oid' => $orderId, 'rid' => $reservationId, 'tid' => $tid, 'bid' => $bid,
        ]);

        echo json_encode([
            'status' => 'success',
            'valid' => true,
            'plan_id' => $plan['id'],
            'plan_name' => $plan['name'],
            'coupon_code' => $couponCode,
            'discount_amount' => $discountAmount,
            'subtotal' => round($subtotal, 2),
            'total' => round(max(0, $subtotal - $discountAmount), 2),
        ]);
    } catch (Throwable $e) {
        http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

function handle_get_promo_plans(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];

    $stmt = $pdo->prepare("SELECT * FROM `discounts` WHERE `tenant_id` = :tid AND `branch_id` = :bid ORDER BY `priority` DESC, `created_at` DESC");
    $stmt->execute(['tid' => $tid, 'bid' => $bid]);
    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($plans as &$plan) {
        $plan['value'] = (float)$plan['value'];
        $plan['min_quantity'] = (int)$plan['min_quantity'];
        $plan['free_quantity'] = (int)$plan['free_quantity'];
        $plan['active'] = (bool)$plan['active'];
        $plan['priority'] = (int)($plan['priority'] ?? 0);
        $plan['stackable'] = (bool)($plan['stackable'] ?? false);
        $plan['applicable_types'] = json_decode($plan['applicable_types'] ?? 'null', true);
        $plan['channels'] = json_decode($plan['channels'] ?? 'null', true);
        $plan['applicable_days'] = json_decode($plan['applicable_days'] ?? 'null', true);
        $plan['min_subtotal'] = $plan['min_subtotal'] !== null ? (float)$plan['min_subtotal'] : null;
        $plan['max_global_uses'] = $plan['max_global_uses'] !== null ? (int)$plan['max_global_uses'] : null;
        $plan['max_customer_uses'] = $plan['max_customer_uses'] !== null ? (int)$plan['max_customer_uses'] : null;
        $plan['coupon_code'] = $plan['coupon_code'] ?? null;
        $plan['groups'] = promoLoadGroups($pdo, $plan['id'], $tid, $bid);

        if (!empty($plan['coupon_code'])) {
            $usageStmt = $pdo->prepare("SELECT COUNT(*) FROM `coupon_usage_log` WHERE `discount_id` = :did AND `tenant_id` = :tid AND `branch_id` = :bid");
            $usageStmt->execute(['did' => $plan['id'], 'tid' => $tid, 'bid' => $bid]);
            $plan['current_uses'] = (int)$usageStmt->fetchColumn();
        } else {
            $plan['current_uses'] = 0;
        }
    }

    echo json_encode(['status' => 'success', 'plans' => $plans]);
}

function handle_save_promo_plan(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];

    $id = $input['id'] ?? null;
    $name = trim($input['name'] ?? '');
    $description = trim($input['description'] ?? '');
    $type = $input['type'] ?? 'percentage';
    $value = (float)($input['value'] ?? 0);
    $minQuantity = (int)($input['min_quantity'] ?? 0);
    $freeQuantity = (int)($input['free_quantity'] ?? 0);
    $applicableTypes = $input['applicable_types'] ?? null;
    $channels = $input['channels'] ?? null;
    $priority = (int)($input['priority'] ?? 0);
    $stackable = isset($input['stackable']) ? (int)$input['stackable'] : 0;
    $couponCode = strtoupper(trim($input['coupon_code'] ?? ''));
    $maxGlobalRaw = $input['max_global_uses'] ?? null;
    $maxCustomerRaw = $input['max_customer_uses'] ?? null;
    $minSubtotalRaw = $input['min_subtotal'] ?? null;
    $maxGlobalUses = $maxGlobalRaw !== null && $maxGlobalRaw !== '' ? (int)$maxGlobalRaw : null;
    $maxCustomerUses = $maxCustomerRaw !== null && $maxCustomerRaw !== '' ? (int)$maxCustomerRaw : null;
    $minSubtotal = $minSubtotalRaw !== null && $minSubtotalRaw !== '' ? (float)$minSubtotalRaw : null;
    $applicableDays = $input['applicable_days'] ?? null;
    $startHour = $input['start_hour'] ?? null;
    $endHour = $input['end_hour'] ?? null;
    $startDate = $input['start_date'] ?? null;
    $endDate = $input['end_date'] ?? null;
    $active = isset($input['active']) ? (int)$input['active'] : 1;
    $respectOpen = isset($input['respetar_cotizaciones_abiertas']) ? (int)$input['respetar_cotizaciones_abiertas'] : 0;
    $groups = is_array($input['groups'] ?? null) ? $input['groups'] : [];

    if ($name === '') throw new InvalidArgumentException('Nombre requerido');
    if (!in_array($type, ['percentage', 'fixed', 'buy_x_get_y', 'menu_price'], true)) throw new InvalidArgumentException('Tipo inválido');
    if (!is_finite($value) || $value < 0) throw new InvalidArgumentException('El valor de la promoción no es válido.');
    if ($type === 'percentage' && ($value <= 0 || $value > 100)) throw new InvalidArgumentException('El porcentaje debe estar entre 0 y 100.');
    if ($type === 'fixed' && $value <= 0) throw new InvalidArgumentException('El monto fijo debe ser mayor que cero.');
    if ($type === 'menu_price' && $value <= 0) throw new InvalidArgumentException('El precio de menú debe ser mayor que cero.');
    if ($type === 'buy_x_get_y' && ($minQuantity < 1 || $freeQuantity < 1)) {
        throw new InvalidArgumentException('Compra X y lleva Y requiere cantidades mayores que cero.');
    }
    if (!$groups) throw new InvalidArgumentException('Debe seleccionar al menos un grupo de productos');
    if ($type === 'buy_x_get_y' && count($groups) !== 1) {
        throw new InvalidArgumentException('Compra X y lleva Y debe utilizar un solo grupo de productos.');
    }
    if ($type === 'menu_price' && count($groups) < 2) throw new InvalidArgumentException('Un combo necesita al menos dos grupos de productos');
    if ($startDate === '' || $endDate === '' || $startDate === null || $endDate === null) {
        throw new InvalidArgumentException('La fecha de inicio y la fecha de fin son obligatorias.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$endDate)) {
        throw new InvalidArgumentException('Las fechas de la promoción no tienen un formato válido.');
    }
    if ($endDate < $startDate) throw new InvalidArgumentException('La fecha de fin no puede ser anterior a la fecha de inicio.');
    if (($startHour && !$endHour) || (!$startHour && $endHour)) {
        throw new InvalidArgumentException('Complete ambas horas o déjelas vacías para aplicar la promoción las 24 horas.');
    }
    if ($startHour && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string)$startHour)) throw new InvalidArgumentException('Hora de inicio inválida.');
    if ($endHour && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string)$endHour)) throw new InvalidArgumentException('Hora de fin inválida.');

    if (is_string($applicableTypes)) {
        $applicableTypes = json_decode($applicableTypes, true) ?: null;
    }
    if (is_string($channels)) {
        $channels = json_decode($channels, true) ?: promoParseChannels($channels);
    }
    if (is_string($applicableDays)) {
        $applicableDays = json_decode($applicableDays, true) ?: null;
    }
    $applicableTypesJson = $applicableTypes ? json_encode($applicableTypes) : null;
    $channelsJson = $channels ? json_encode($channels) : null;
    $daysJson = $applicableDays ? json_encode($applicableDays) : null;

    if ($couponCode !== '') {
        $checkStmt = $pdo->prepare("SELECT id FROM `discounts` WHERE `tenant_id` = :tid AND `branch_id` = :bid AND UPPER(`coupon_code`) = :code AND id != :id");
        $checkStmt->execute(['tid' => $tid, 'bid' => $bid, 'code' => $couponCode, 'id' => $id ?? '']);
        if ($checkStmt->fetch()) throw new InvalidArgumentException('Este código de cupón ya está en uso');
    }

    $pdo->beginTransaction();
    try {
      if ($id) {
         $existsStmt = $pdo->prepare("SELECT 1 FROM `discounts` WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
         $existsStmt->execute(['id' => $id, 'tid' => $tid, 'bid' => $bid]);
         if (!$existsStmt->fetchColumn()) throw new InvalidArgumentException('La promoción que intenta editar no existe.');

         $stmt = $pdo->prepare("UPDATE `discounts` SET `name` = :name, `description` = :desc, `type` = :type, `value` = :value, `min_quantity` = :min_qty, `free_quantity` = :free_qty, `applicable_types` = :app_types, `channels` = :channels, `priority` = :priority, `stackable` = :stackable, `coupon_code` = :coupon, `max_global_uses` = :max_global, `max_customer_uses` = :max_customer, `min_subtotal` = :min_sub, `applicable_days` = :days, `start_hour` = :start_hour, `end_hour` = :end_hour, `start_date` = :start_date, `end_date` = :end_date, `active` = :active, `respetar_cotizaciones_abiertas` = :respect_open WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
        $stmt->execute([
            'name' => $name, 'desc' => $description ?: null, 'type' => $type, 'value' => $value,
            'min_qty' => $minQuantity, 'free_qty' => $freeQuantity, 'app_types' => $applicableTypesJson,
            'channels' => $channelsJson, 'priority' => $priority, 'stackable' => $stackable,
            'coupon' => $couponCode ?: null, 'max_global' => $maxGlobalUses, 'max_customer' => $maxCustomerUses,
            'min_sub' => $minSubtotal, 'days' => $daysJson, 'start_hour' => $startHour, 'end_hour' => $endHour,
             'start_date' => $startDate, 'end_date' => $endDate, 'active' => $active, 'respect_open' => $respectOpen,
            'id' => $id, 'tid' => $tid, 'bid' => $bid,
        ]);
      } else {
         $id = 'plan_' . bin2hex(random_bytes(12));
         $stmt = $pdo->prepare("INSERT INTO `discounts` (`id`, `tenant_id`, `branch_id`, `name`, `description`, `type`, `value`, `min_quantity`, `free_quantity`, `applicable_types`, `channels`, `priority`, `stackable`, `coupon_code`, `max_global_uses`, `max_customer_uses`, `min_subtotal`, `applicable_days`, `start_hour`, `end_hour`, `start_date`, `end_date`, `active`, `respetar_cotizaciones_abiertas`) VALUES (:id, :tid, :bid, :name, :desc, :type, :value, :min_qty, :free_qty, :app_types, :channels, :priority, :stackable, :coupon, :max_global, :max_customer, :min_sub, :days, :start_hour, :end_hour, :start_date, :end_date, :active, :respect_open)");
        $stmt->execute([
            'id' => $id, 'tid' => $tid, 'bid' => $bid, 'name' => $name, 'desc' => $description ?: null,
            'type' => $type, 'value' => $value, 'min_qty' => $minQuantity, 'free_qty' => $freeQuantity,
            'app_types' => $applicableTypesJson, 'channels' => $channelsJson, 'priority' => $priority,
            'stackable' => $stackable, 'coupon' => $couponCode ?: null, 'max_global' => $maxGlobalUses,
            'max_customer' => $maxCustomerUses, 'min_sub' => $minSubtotal, 'days' => $daysJson,
            'start_hour' => $startHour, 'end_hour' => $endHour, 'start_date' => $startDate,
             'end_date' => $endDate, 'active' => $active, 'respect_open' => $respectOpen,
        ]);
      }

    $oldGroupsStmt = $pdo->prepare("SELECT `id` FROM `promo_groups` WHERE `discount_id` = :did AND `tenant_id` = :tid AND `branch_id` = :bid");
    $oldGroupsStmt->execute(['did' => $id, 'tid' => $tid, 'bid' => $bid]);
    $oldGroupIds = $oldGroupsStmt->fetchAll(PDO::FETCH_COLUMN);
    if ($oldGroupIds) {
        $oldGroupPlaceholders = implode(',', array_fill(0, count($oldGroupIds), '?'));
        $deleteGroupProducts = $pdo->prepare("DELETE FROM `promo_group_products` WHERE `tenant_id` = ? AND `branch_id` = ? AND `group_id` IN ($oldGroupPlaceholders)");
        $deleteGroupProducts->execute(array_merge([$tid, $bid], $oldGroupIds));
    }
    $deleteGroups = $pdo->prepare("DELETE FROM `promo_groups` WHERE `discount_id` = :did AND `tenant_id` = :tid AND `branch_id` = :bid");
    $deleteGroups->execute(['did' => $id, 'tid' => $tid, 'bid' => $bid]);
    $insertGroup = $pdo->prepare("INSERT INTO `promo_groups` (`id`, `discount_id`, `group_no`, `quantity_required`, `free_quantity`, `tenant_id`, `branch_id`) VALUES (:id, :did, :group_no, :quantity_required, :free_quantity, :tid, :bid)");
    $insertProduct = $pdo->prepare("INSERT INTO `promo_group_products` (`id`, `group_id`, `product_id`, `product_type`, `product_name`, `tenant_id`, `branch_id`) VALUES (:id, :group_id, :product_id, :product_type, :product_name, :tid, :bid)");
    foreach (array_values($groups) as $groupNo => $group) {
        $groupId = 'pgrp_' . bin2hex(random_bytes(10));
        $quantityRequired = $type === 'buy_x_get_y'
            ? $minQuantity
            : max(1, (int)($group['quantity_required'] ?? 1));
        $freeQuantity = $type === 'buy_x_get_y'
            ? $freeQuantity
            : max(0, (int)($group['free_quantity'] ?? 0));
        $insertGroup->execute(['id' => $groupId, 'did' => $id, 'group_no' => $groupNo + 1, 'quantity_required' => $quantityRequired, 'free_quantity' => $freeQuantity, 'tid' => $tid, 'bid' => $bid]);
        $products = is_array($group['products'] ?? null) ? $group['products'] : [];
        if (!$products) throw new InvalidArgumentException('Cada grupo debe tener productos seleccionados');
        foreach ($products as $product) {
            $productType = trim((string)($product['product_type'] ?? ''));
            $productId = isset($product['product_id']) && $product['product_id'] !== '' ? (string)$product['product_id'] : null;
            $productName = trim((string)($product['product_name'] ?? $product['name'] ?? $productType));
            if ($productType === '' || $productName === '') throw new InvalidArgumentException('Producto de promoción inválido');
            $insertProduct->execute(['id' => 'pgt_' . bin2hex(random_bytes(10)), 'group_id' => $groupId, 'product_id' => $productId, 'product_type' => $productType, 'product_name' => $productName, 'tid' => $tid, 'bid' => $bid]);
        }
    }

    cacheInvalidateTenant('catalog', $tid, $bid);
    writeAuditLog($pdo, $authContext, 'promo.plan.save', 'discounts', $id);
    $pdo->commit();
    echo json_encode(['status' => 'success', 'id' => $id]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function handle_delete_promo_plan(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    $id = $input['id'] ?? '';
    if (!$id) throw new InvalidArgumentException('ID requerido');

    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];
    $stmt = $pdo->prepare("DELETE FROM `discounts` WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
    $stmt->execute(['id' => $id, 'tid' => $tid, 'bid' => $bid]);
    $groupStmt = $pdo->prepare("DELETE FROM `promo_groups` WHERE `discount_id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
    $groupStmt->execute(['id' => $id, 'tid' => $tid, 'bid' => $bid]);

    cacheInvalidateTenant('catalog', $tid, $bid);
    writeAuditLog($pdo, $authContext, 'promo.plan.delete', 'discounts', $id);
    echo json_encode(['status' => 'success']);
}
