<?php
// Shared helper functions used by action handlers

function tenantParams(array $authContext): array {
    return [
        'tenant_id' => $authContext['tenant_id'],
        'branch_id' => $authContext['branch_id']
    ];
}

function _backfillProductsForTenant(PDO $pdo, array $authContext): void {
    $params = tenantParams($authContext);

    $tables = [
        'segundos' => 'segundo',
        'sopas' => 'sopa',
        'platos_extras' => 'plato_extra',
        'gaseosas' => 'refresco'
    ];
    foreach ($tables as $catalogTable => $productType) {
        try {
            if ($catalogTable === 'gaseosas') {
                $pdo->prepare("
                    INSERT INTO `products` (`id`, `name`, `type`, `price`, `stock`, `menu_id`, `active`, `tenant_id`, `branch_id`)
                    SELECT c.`id`, c.`name`, :gaseosa_type, COALESCE(c.`price`, 0), COALESCE(c.`stock`, 0), NULL, 1, c.`tenant_id`, c.`branch_id`
                    FROM `$catalogTable` c
                    WHERE c.`tenant_id` = :gaseosa_tenant_id AND c.`branch_id` = :gaseosa_branch_id
                    ON DUPLICATE KEY UPDATE `type` = VALUES(`type`), `price` = VALUES(`price`), `name` = VALUES(`name`), `active` = VALUES(`active`)
                ")->execute([
                    'gaseosa_type' => $productType,
                    'gaseosa_tenant_id' => $params['tenant_id'],
                    'gaseosa_branch_id' => $params['branch_id']
                ]);
            } else {
                $pdo->prepare("
                    INSERT INTO `products` (`id`, `name`, `type`, `price`, `stock`, `menu_id`, `active`, `accepts_salsa`, `accepts_accompaniment`, `max_included_accompaniments`, `tenant_id`, `branch_id`)
                    SELECT c.`id`, c.`name`, :catalog_type, 0, COALESCE(c.`stock`, 0), NULL, 1, COALESCE(c.`accepts_salsa`, 0), COALESCE(c.`accepts_accompaniment`, 0), COALESCE(c.`max_included_accompaniments`, 0), c.`tenant_id`, c.`branch_id`
                    FROM `$catalogTable` c
                    WHERE c.`tenant_id` = :catalog_tenant_id AND c.`branch_id` = :catalog_branch_id
                    ON DUPLICATE KEY UPDATE `accepts_salsa` = VALUES(`accepts_salsa`), `accepts_accompaniment` = VALUES(`accepts_accompaniment`), `max_included_accompaniments` = VALUES(`max_included_accompaniments`), `name` = VALUES(`name`), `active` = VALUES(`active`)
                ")->execute([
                    'catalog_type' => $productType,
                    'catalog_tenant_id' => $params['tenant_id'],
                    'catalog_branch_id' => $params['branch_id']
                ]);
            }
        } catch (Throwable $e) {
            error_log("[RestoCloud] products backfill failed for $catalogTable: " . $e->getMessage());
        }
    }
}

function initializeBranchTemplate(PDO $pdo, string $tenantId, string $branchId, string $tenantName): void
{
    $menuId = 'menu_' . substr(hash('sha256', $branchId), 0, 20);
    $suffix = substr(hash('sha256', $branchId), 0, 16);
    $prices = ['almuerzo' => 15.00, 'segundo' => 12.00, 'sopa' => 6.00];
    foreach ($prices as $id => $value) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO `config_precios` (`id`, `valor`, `tenant_id`, `branch_id`) VALUES (:id, :value, :tid, :bid)");
        $stmt->execute(['id' => $id, 'value' => $value, 'tid' => $tenantId, 'bid' => $branchId]);
    }
    foreach ([
        'nombre_restaurante' => $tenantName,
        'direccion' => 'Por configurar',
        'telefono' => 'Por configurar',
        'pais' => 'Bolivia'
    ] as $id => $value) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO `config_general` (`id`, `value`, `tenant_id`, `branch_id`) VALUES (:id, :value, :tid, :bid)");
        $stmt->execute(['id' => $id, 'value' => $value, 'tid' => $tenantId, 'bid' => $branchId]);
    }

    $stmt = $pdo->prepare("INSERT IGNORE INTO `menus` (`id`, `name`, `active`, `tenant_id`, `branch_id`) VALUES (:id, 'Menú del Día', 1, :tid, :bid)");
    $stmt->execute(['id' => $menuId, 'tid' => $tenantId, 'bid' => $branchId]);

    $catalog = [
        ['segundos', 'seg_' . $suffix, 'Plato principal', 0, 0],
        ['sopas', 'sopa_' . $suffix, 'Sopa del día', 0, 0],
        ['platos_extras', 'extra_' . $suffix, 'Plato extra', 0, 10],
        ['gaseosas', 'beb_' . $suffix, 'Bebida', 0, 5]
    ];
    foreach ($catalog as [$table, $id, $name, $stock, $price]) {
        if (in_array($table, ['platos_extras', 'gaseosas'], true)) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO `$table` (`id`, `name`, `price`, `stock`, `tenant_id`, `branch_id`) VALUES (:id, :name, :price, :stock, :tid, :bid)");
            $stmt->execute(compact('id', 'name', 'price', 'stock') + ['tid' => $tenantId, 'bid' => $branchId]);
        } else {
            $stmt = $pdo->prepare("INSERT IGNORE INTO `$table` (`id`, `name`, `stock`, `active`, `tenant_id`, `branch_id`) VALUES (:id, :name, :stock, 1, :tid, :bid)");
            $stmt->execute(compact('id', 'name', 'stock') + ['tid' => $tenantId, 'bid' => $branchId]);
        }
    }
    $tableId = 'tbl_' . $suffix;
    $stmt = $pdo->prepare("INSERT IGNORE INTO `tables_config` (`id`, `name`, `icon`, `sort_order`, `active`, `tenant_id`, `branch_id`) VALUES (:id, 'Mesa 1', 'fa-chair', 1, 1, :tid, :bid)");
    $stmt->execute(['id' => $tableId, 'tid' => $tenantId, 'bid' => $branchId]);

    _backfillProductsForTenant($pdo, ['tenant_id' => $tenantId, 'branch_id' => $branchId]);
}

function buildReportContext(array $authContext, array $business = [], array $filters = []): array {
    $tenantName = $authContext['tenant_name'] ?? 'RestoCloud';
    $branchName = $authContext['branch_name'] ?? 'Principal';
    $businessName = $business['nombre_restaurante'] ?? $tenantName;

    return [
        'generated_at' => date('c'),
        'tenant' => [
            'id' => $authContext['tenant_id'] ?? '',
            'slug' => $authContext['tenant_slug'] ?? '',
            'name' => $tenantName
        ],
        'branch' => [
            'id' => $authContext['branch_id'] ?? '',
            'name' => $branchName
        ],
        'business' => [
            'name' => $businessName,
            'address' => $business['direccion'] ?? '',
            'phone' => $business['telefono'] ?? ''
        ],
        'user' => [
            'id' => $authContext['user_id'] ?? '',
            'name' => $authContext['user_name'] ?? '',
            'email' => $authContext['user_email'] ?? '',
            'role' => $authContext['role'] ?? ''
        ],
        'filters' => $filters
    ];
}

function loadCatalogState(PDO $pdo, array $authContext) {
    $cacheKey = $authContext['tenant_id'] . ':' . $authContext['branch_id'];
    return cacheGetOrSet('catalog', $cacheKey, 30, function() use ($pdo, $authContext) {
        _backfillProductsForTenant($pdo, $authContext);
        return _loadCatalogStateFromDb($pdo, $authContext);
    });
}

function menuIsActiveNow(array $menu): bool {
    if (empty($menu['active'])) return false;
    $start = trim((string)($menu['start_time'] ?? ''));
    $end = trim((string)($menu['end_time'] ?? ''));
    if ($start === '' || $end === '') return true;
    $now = date('H:i:s');
    $start = strlen($start) === 5 ? $start . ':00' : $start;
    $end = strlen($end) === 5 ? $end . ':00' : $end;
    return $start <= $end ? ($now >= $start && $now <= $end) : ($now >= $start || $now <= $end);
}

function normalizeStockUsage(array $items): array {
    $usage = [];
    $add = static function ($productId, $quantity) use (&$usage): void {
        $productId = trim((string)$productId);
        $quantity = (int)$quantity;
        if ($productId === '' || $quantity <= 0) return;
        $usage[$productId] = ($usage[$productId] ?? 0) + $quantity;
    };
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $quantity = max(1, (int)($item['quantity'] ?? $item['qty'] ?? 1));
        $type = (string)($item['type'] ?? '');
        if ($type === 'almuerzo') {
            $add($item['sopaId'] ?? null, $quantity);
            $add($item['segundoId'] ?? null, $quantity);
        } else {
            $productId = match ($type) {
                'sopa' => $item['sopaId'] ?? $item['product_id'] ?? null,
                'segundo' => $item['segundoId'] ?? $item['product_id'] ?? null,
                'plato_extra' => $item['platoId'] ?? $item['product_id'] ?? null,
                'extra', 'refresco' => $item['extraId'] ?? $item['product_id'] ?? null,
                'salsa' => $item['salsaId'] ?? $item['product_id'] ?? null,
                'acompanamiento' => $item['accompanimentId'] ?? $item['product_id'] ?? null,
                default => $item['product_id'] ?? null,
            };
            $add($productId, $quantity);
        }
        foreach (($item['salsas'] ?? []) as $salsa) {
            $add($salsa['salsaId'] ?? $salsa['id'] ?? null, $quantity);
        }
        foreach (($item['accompaniments'] ?? []) as $accompaniment) {
            $add($accompaniment['accompanimentId'] ?? $accompaniment['id'] ?? null, $quantity);
        }
    }
    return $usage;
}

function recordProductStockEvent(PDO $pdo, array $authContext, string $productId, int $before, int $change, int $after, string $eventType, string $referenceType, string $referenceId): void {
    try {
        $stmt = $pdo->prepare("SELECT `name` FROM `products` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
        $stmt->execute(['id' => $productId, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
        $name = $stmt->fetchColumn();
        if (!$name) return;
        $stmt = $pdo->prepare("INSERT INTO `stock_history` (`id`, `tenant_id`, `branch_id`, `item_type`, `item_id`, `event_type`, `quantity_before`, `quantity_change`, `quantity_after`, `reference_type`, `reference_id`, `created_at`) VALUES (:id, :tenant_id, :branch_id, 'product', :item_id, :event_type, :before, :change, :after, :reference_type, :reference_id, NOW())");
        $stmt->execute(['id' => 'stk_' . bin2hex(random_bytes(12)), 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id'], 'item_id' => $productId, 'event_type' => $eventType, 'before' => $before, 'change' => $change, 'after' => $after, 'reference_type' => $referenceType, 'reference_id' => $referenceId]);
    } catch (Throwable $e) {
        error_log('[RestoCloud] stock history write failed: ' . $e->getMessage());
    }
}

function reserveStock(PDO $pdo, array $authContext, string $referenceType, string $referenceId, array $items): void {
    $usage = normalizeStockUsage($items);
    $scope = ['tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id'], 'reference_type' => $referenceType, 'reference_id' => $referenceId];
    $existing = $pdo->prepare("SELECT `quantity`, `status` FROM `stock_reservations` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `reference_type` = :reference_type AND `reference_id` = :reference_id AND `product_id` = :product_id FOR UPDATE");
    $deduct = $pdo->prepare("UPDATE `products` SET `stock` = `stock` - :deduct_qty WHERE `id` = :product_id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `active` = 1 AND `stock` >= :check_qty");
    $stock = $pdo->prepare("SELECT `stock` FROM `products` WHERE `id` = :product_id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    $insert = $pdo->prepare("INSERT INTO `stock_reservations` (`id`, `tenant_id`, `branch_id`, `reference_type`, `reference_id`, `product_id`, `quantity`, `status`) VALUES (:id, :tenant_id, :branch_id, :reference_type, :reference_id, :product_id, :quantity, 'active')");
    foreach ($usage as $productId => $quantity) {
        $params = $scope + ['product_id' => $productId];
        $existing->execute($params);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        $previousQuantity = ($row && $row['status'] === 'active') ? (int)$row['quantity'] : 0;
        if ($previousQuantity > $quantity) throw new InvalidArgumentException('No se puede reducir una reserva sin cancelar los productos.');
        if ($previousQuantity === $quantity) continue;
        $change = $quantity - $previousQuantity;
        $deduct->execute([
            'tenant_id' => $authContext['tenant_id'],
            'branch_id' => $authContext['branch_id'],
            'product_id' => $productId,
            'deduct_qty' => $change,
            'check_qty' => $change
        ]);
        if ($deduct->rowCount() !== 1) throw new InvalidArgumentException('Stock insuficiente o producto no disponible.');
        $stock->execute([
            'product_id' => $productId,
            'tenant_id' => $authContext['tenant_id'],
            'branch_id' => $authContext['branch_id']
        ]);
        $after = (int)$stock->fetchColumn();
        if ($row) {
            $pdo->prepare("UPDATE `stock_reservations` SET `quantity` = :quantity, `status` = 'active', `released_at` = NULL WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `reference_type` = :reference_type AND `reference_id` = :reference_id AND `product_id` = :product_id")->execute([
                'quantity' => $quantity,
                'tenant_id' => $authContext['tenant_id'],
                'branch_id' => $authContext['branch_id'],
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'product_id' => $productId
            ]);
        } else {
            $insert->execute([
                'id' => 'sr_' . bin2hex(random_bytes(12)),
                'tenant_id' => $authContext['tenant_id'],
                'branch_id' => $authContext['branch_id'],
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'product_id' => $productId,
                'quantity' => $quantity
            ]);
        }
        recordProductStockEvent($pdo, $authContext, $productId, $after + $change, -$change, $after, 'deduct', $referenceType, $referenceId);
    }
}

function releaseStock(PDO $pdo, array $authContext, string $referenceType, string $referenceId, ?array $items = null): void {
    $usage = $items === null ? null : normalizeStockUsage($items);
    $scope = ['tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id'], 'reference_type' => $referenceType, 'reference_id' => $referenceId];
    $stmt = $pdo->prepare("SELECT `product_id`, `quantity` FROM `stock_reservations` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `reference_type` = :reference_type AND `reference_id` = :reference_id AND `status` = 'active' FOR UPDATE");
    $stmt->execute($scope);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $restore = $pdo->prepare("UPDATE `products` SET `stock` = `stock` + :quantity WHERE `id` = :product_id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    $stock = $pdo->prepare("SELECT `stock` FROM `products` WHERE `id` = :product_id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    foreach ($reservations as $reservation) {
        $productId = $reservation['product_id'];
        $requested = $usage[$productId] ?? 0;
        $quantity = $usage === null ? (int)$reservation['quantity'] : min((int)$reservation['quantity'], $requested);
        if ($quantity <= 0) continue;
        $params = $scope + ['product_id' => $productId, 'quantity' => $quantity];
        $restore->execute([
            'quantity' => $quantity,
            'product_id' => $productId,
            'tenant_id' => $authContext['tenant_id'],
            'branch_id' => $authContext['branch_id']
        ]);
        $stock->execute([
            'product_id' => $productId,
            'tenant_id' => $authContext['tenant_id'],
            'branch_id' => $authContext['branch_id']
        ]);
        $after = (int)$stock->fetchColumn();
        if ($quantity >= (int)$reservation['quantity']) {
            $pdo->prepare("UPDATE `stock_reservations` SET `status` = 'released', `released_at` = NOW() WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `reference_type` = :reference_type AND `reference_id` = :reference_id AND `product_id` = :product_id")->execute([
                'tenant_id' => $authContext['tenant_id'],
                'branch_id' => $authContext['branch_id'],
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'product_id' => $productId
            ]);
        } else {
            $pdo->prepare("UPDATE `stock_reservations` SET `quantity` = `quantity` - :quantity WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `reference_type` = :reference_type AND `reference_id` = :reference_id AND `product_id` = :product_id")->execute([
                'quantity' => $quantity,
                'tenant_id' => $authContext['tenant_id'],
                'branch_id' => $authContext['branch_id'],
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'product_id' => $productId
            ]);
        }
        recordProductStockEvent($pdo, $authContext, $productId, $after - $quantity, $quantity, $after, 'restore', $referenceType, $referenceId);
    }
}

function transferStockReservation(PDO $pdo, array $authContext, string $reservationId, string $orderId, array $items, string $orderStatus = 'pendiente'): void {
    $scope = ['tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id'], 'reservation_id' => $reservationId];
    $rows = $pdo->prepare("SELECT `product_id`, `quantity` FROM `stock_reservations` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `reference_type` = 'reservation' AND `reference_id` = :reservation_id AND `status` = 'active' FOR UPDATE");
    $rows->execute($scope);
    $reservedUsage = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) $reservedUsage[$row['product_id']] = (int)$row['quantity'];
    $requestedUsage = normalizeStockUsage($items);
    foreach ($reservedUsage as $productId => $reservedQty) {
        if (!isset($requestedUsage[$productId]) || $requestedUsage[$productId] < $reservedQty) {
            throw new InvalidArgumentException('Los productos del pedido no cubren la reserva de stock.');
        }
    }
    $stmt = $pdo->prepare("UPDATE `stock_reservations` SET `reference_type` = 'order', `reference_id` = :order_id WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `reference_type` = 'reservation' AND `reference_id` = :reservation_id AND `status` = 'active'");
    $stmt->execute($scope + ['order_id' => $orderId]);
    $resStatus = $orderStatus === 'completado' ? 'completada' : 'confirmada';
    $pdo->prepare("UPDATE `reservations` SET `order_id` = :order_id, `status` = :res_status, `updated_at` = NOW() WHERE `id` = :reservation_id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `order_id` IS NULL")->execute($scope + ['order_id' => $orderId, 'res_status' => $resStatus]);
}

function _loadCatalogStateFromDb(PDO $pdo, array $authContext) {
    $params = tenantParams($authContext);

    $stmt = $pdo->prepare("SELECT * FROM `config_precios` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    $stmt->execute($params);
    $priceRows = $stmt->fetchAll();
    $prices = [];
    foreach ($priceRows as $row) {
        $prices[$row['id']] = (float)$row['valor'];
    }

    $stmt = $pdo->prepare("SELECT * FROM `config_general` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    $stmt->execute($params);
    $generalRows = $stmt->fetchAll();
    $business = [];
    foreach ($generalRows as $row) {
        $business[$row['id']] = $row['value'];
    }

    $stmt = $pdo->prepare("SELECT * FROM `segundos` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    $stmt->execute($params);
    $seconds = $stmt->fetchAll();
    foreach ($seconds as &$s) {
        $s['stock'] = (int)$s['stock'];
        $s['active'] = isset($s['active']) ? (bool)$s['active'] : true;
        $s['accepts_salsa'] = isset($s['accepts_salsa']) ? (bool)$s['accepts_salsa'] : false;
        $s['accepts_accompaniment'] = !empty($s['accepts_accompaniment']);
        $s['max_included_accompaniments'] = (int)($s['max_included_accompaniments'] ?? 0);
    }
    unset($s);

    $stmt = $pdo->prepare("SELECT * FROM `platos_extras` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    $stmt->execute($params);
    $platosExtras = $stmt->fetchAll();
    foreach ($platosExtras as &$p) {
        $p['price'] = (float)$p['price'];
        $p['stock'] = (int)$p['stock'];
        $p['accepts_salsa'] = isset($p['accepts_salsa']) ? (bool)$p['accepts_salsa'] : false;
        $p['accepts_accompaniment'] = !empty($p['accepts_accompaniment']);
        $p['max_included_accompaniments'] = (int)($p['max_included_accompaniments'] ?? 0);
    }
    unset($p);

    $stmt = $pdo->prepare("SELECT * FROM `gaseosas` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    $stmt->execute($params);
    $extras = $stmt->fetchAll();
    foreach ($extras as &$e) {
        $e['price'] = (float)$e['price'];
        $e['stock'] = (int)$e['stock'];
    }
    unset($e);

    $sopas = [];
    try {
        $stmt = $pdo->prepare("SELECT * FROM `sopas` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
        $stmt->execute($params);
        $sopaRows = $stmt->fetchAll();
        foreach ($sopaRows as &$s) {
            $s['stock'] = (int)$s['stock'];
            $s['active'] = isset($s['active']) ? (bool)$s['active'] : true;
            $s['accepts_salsa'] = isset($s['accepts_salsa']) ? (bool)$s['accepts_salsa'] : false;
            $s['accepts_accompaniment'] = !empty($s['accepts_accompaniment']);
            $s['max_included_accompaniments'] = (int)($s['max_included_accompaniments'] ?? 0);
            $sopas[] = $s;
        }
        unset($s);
    } catch (Throwable $e) {
        error_log("[RestoCloud] loadCatalogState sopas query failed: " . $e->getMessage());
    }

    $salsas = [];
    try {
        $stmt = $pdo->prepare("SELECT s.*, COALESCE(p.stock, s.stock) AS stock FROM `salsas` s LEFT JOIN `products` p ON p.id = s.id AND p.tenant_id = s.tenant_id AND p.branch_id = s.branch_id WHERE s.tenant_id = :tenant_id AND s.branch_id = :branch_id");
        $stmt->execute($params);
        $salsaRows = $stmt->fetchAll();
        foreach ($salsaRows as &$sa) {
            $sa['price'] = (float)$sa['price'];
            $sa['stock'] = (int)$sa['stock'];
            $sa['active'] = isset($sa['active']) ? (bool)$sa['active'] : true;
            $salsas[] = $sa;
        }
        unset($sa);
    } catch (Throwable $e) {
        error_log("[RestoCloud] loadCatalogState salsas query failed: " . $e->getMessage());
    }

    $accompaniments = [];
    try {
        $stmt = $pdo->prepare("SELECT a.*, COALESCE(p.stock, a.stock) AS stock FROM `acompanamientos` a LEFT JOIN `products` p ON p.id = a.id AND p.tenant_id = a.tenant_id AND p.branch_id = a.branch_id WHERE a.tenant_id = :tenant_id AND a.branch_id = :branch_id ORDER BY a.`name`");
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $accompaniment) {
            $accompaniment['price_extra'] = (float)$accompaniment['price_extra'];
            $accompaniment['stock'] = (int)$accompaniment['stock'];
            $accompaniment['active'] = (bool)$accompaniment['active'];
            $accompaniments[] = $accompaniment;
        }
    } catch (Throwable $e) {
        error_log("[RestoCloud] loadCatalogState acompanamientos query failed: " . $e->getMessage());
    }

    $menus = [];
    try {
        $stmt = $pdo->prepare("SELECT * FROM `menus` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id ORDER BY `name` ASC");
        $stmt->execute($params);
        $menuRows = $stmt->fetchAll();
        foreach ($menuRows as &$m) {
            $m['active'] = !empty($m['active']);
            $m['available_now'] = menuIsActiveNow($m);
            $menus[] = $m;
        }
        unset($m);
    } catch (Throwable $e) {
        error_log("[RestoCloud] loadCatalogState menus query failed: " . $e->getMessage());
    }

    $products = [];
    try {
        $stmt = $pdo->prepare("SELECT * FROM `products` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `active` = 1 ORDER BY `type`, `name` ASC");
        $stmt->execute($params);
        $productRows = $stmt->fetchAll();
        foreach ($productRows as &$p) {
            $p['price'] = (float)$p['price'];
            $p['stock'] = (int)$p['stock'];
            $p['active'] = (bool)$p['active'];
            $p['accepts_salsa'] = !empty($p['accepts_salsa']);
            $p['accepts_accompaniment'] = !empty($p['accepts_accompaniment']);
            $p['max_included_accompaniments'] = (int)($p['max_included_accompaniments'] ?? 0);
            $products[] = $p;
        }
        unset($p);
    } catch (Throwable $e) {
        error_log("[RestoCloud] loadCatalogState products query failed: " . $e->getMessage());
    }

    return [
        "prices" => $prices,
        "business" => $business,
        "seconds" => $seconds,
        "sopas" => $sopas,
        "platosExtras" => $platosExtras,
        "extras" => $extras,
        "salsas" => $salsas,
        "accompaniments" => $accompaniments,
        "menus" => $menus,
        "products" => $products,
        "tables" => loadTables($pdo, $authContext)
    ];
}

function loadTables(PDO $pdo, array $authContext) {
    $tables = [];
    try {
        $stmt = $pdo->prepare("SELECT * FROM `tables_config` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `active` = 1 ORDER BY `sort_order` ASC, `created_at` ASC");
        $stmt->execute(tenantParams($authContext));
        $rows = $stmt->fetchAll();
        foreach ($rows as &$t) {
            $t['sort_order'] = (int)$t['sort_order'];
            $t['active'] = (bool)$t['active'];
            $tables[] = $t;
        }
        unset($t);
    } catch (Throwable $e) {
        error_log("[RestoCloud] loadTables query failed: " . $e->getMessage());
    }
    return $tables;
}

function normalizeOrders(array $orders) {
    foreach ($orders as &$order) {
        $order['total'] = (float)$order['total'];
        $order['items'] = json_decode($order['items'], true) ?? [];
        $pm = $order['payment_method'] ?? 'efectivo';
        $decoded = json_decode($pm, true);
        if (is_array($decoded)) {
            $order['payment_method'] = $decoded;
        }
        $order['paymentMethod'] = $order['payment_method'];
        $order['delivery_type'] = $order['delivery_type'] ?? 'mesa';
        $order['deliveryType'] = $order['delivery_type'];
        $order['tableId'] = $order['table_id'] ?? null;
        $order['kitchenNote'] = $order['kitchen_note'] ?? '';
        $order['waiterNote'] = $order['waiter_note'] ?? '';
        $order['pickupTime'] = $order['pickup_time'] ?? null;
        $order['serviceState'] = $order['service_state'] ?? null;
        $order['paid'] = !empty($order['paid']);
        $ts = $order['timestamp'] ?? '';
        if ($ts && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $ts)) {
            $order['timestamp'] = str_replace(' ', 'T', substr($ts, 0, 19));
        }
        $soldAt = $order['sold_at'] ?? null;
        if ($soldAt && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $soldAt)) {
            $order['soldAt'] = $soldAt . 'Z';
        } else {
            $order['soldAt'] = null;
        }
        unset($order['sold_at']);
        $totalRefunded = (float)($order['total_refunded'] ?? 0);
        $order['total_refunded'] = $totalRefunded;
        $order['has_refunds'] = $totalRefunded > 0;
        $order['refund_count'] = (int)($order['refund_count'] ?? 0);
        $order['subtotal'] = isset($order['subtotal']) ? (float)$order['subtotal'] : null;
        $order['discountTotal'] = (float)($order['discount_total'] ?? 0);
        $order['couponCode'] = $order['coupon_code'] ?? null;
        $order['createdByUserId'] = $order['created_by_user_id'] ?? null;
        $order['createdByName'] = $order['created_by_name'] ?? null;
        $order['paidByUserId'] = $order['paid_by_user_id'] ?? null;
        $order['paidByName'] = $order['paid_by_name'] ?? null;
        $appliedPromoRaw = $order['applied_promo'] ?? null;
        $order['appliedPromo'] = $appliedPromoRaw ? json_decode($appliedPromoRaw, true) : null;
        unset($order['discount_total'], $order['applied_promo'], $order['coupon_code']);
    }
    unset($order);
    return $orders;
}

function loadActiveOrders(PDO $pdo, array $authContext) {
    $stmt = $pdo->prepare("SELECT * FROM `pedidos` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `status` = 'pendiente' AND `closure_id` IS NULL ORDER BY `timestamp` ASC");
    $stmt->execute(tenantParams($authContext));
    return normalizeOrders($stmt->fetchAll());
}

function loadOpenSalesHistory(PDO $pdo, array $authContext, $limit = null) {
    $sql = "SELECT * FROM `pedidos` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND ((`status` IN ('completado', 'anulado')) OR (`status` = 'pendiente' AND `paid` = 1)) AND `closure_id` IS NULL ORDER BY COALESCE(`sold_at`, `timestamp`) DESC";
    if ($limit !== null) {
        $sql .= " LIMIT " . max(1, (int)$limit);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute(tenantParams($authContext));
    return normalizeOrders($stmt->fetchAll());
}

function loadCajaMovimientos(PDO $pdo, array $authContext) {
    $stmt = $pdo->prepare("SELECT * FROM `caja_movimientos` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `closure_id` IS NULL ORDER BY `timestamp` ASC");
    $stmt->execute(tenantParams($authContext));
    $movimientos = $stmt->fetchAll();
    foreach ($movimientos as &$mov) {
        $mov['amount'] = (float)$mov['amount'];
    }
    unset($mov);
    return $movimientos;
}

function loadSalesHistoryRange(PDO $pdo, array $authContext, string $startDate, string $endDate) {
    $stmt = $pdo->prepare("
        SELECT p.*,
               COALESCE(refund_data.total_refunded, 0) AS total_refunded,
               COALESCE(refund_data.refund_count, 0) AS refund_count
        FROM `pedidos` p
        LEFT JOIN (
            SELECT order_id, SUM(total_refunded) AS total_refunded, COUNT(*) AS refund_count
            FROM `pedido_reversiones`
            WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND status != 'anulada'
            GROUP BY order_id
        ) refund_data ON refund_data.order_id = p.id
        WHERE p.`tenant_id` = :tenant_id AND p.`branch_id` = :branch_id
          AND ((p.`status` IN ('completado', 'anulado')) OR (p.`status` = 'pendiente' AND p.`paid` = 1))
          AND COALESCE(p.`sold_at`, p.`timestamp`) BETWEEN :start AND :end
        ORDER BY COALESCE(p.`sold_at`, p.`timestamp`) DESC
    ");
    $stmt->execute([
        ':tenant_id' => $authContext['tenant_id'],
        ':branch_id' => $authContext['branch_id'],
        ':start' => $startDate . ' 00:00:00',
        ':end' => $endDate . ' 23:59:59'
    ]);
    return normalizeOrders($stmt->fetchAll());
}

function loadCajaMovimientosRange(PDO $pdo, array $authContext, string $startDate, string $endDate) {
    $stmt = $pdo->prepare("
        SELECT * FROM `caja_movimientos`
        WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id
          AND `timestamp` BETWEEN :start AND :end
        ORDER BY `timestamp` ASC
    ");
    $stmt->execute([
        ':tenant_id' => $authContext['tenant_id'],
        ':branch_id' => $authContext['branch_id'],
        ':start' => $startDate . ' 00:00:00',
        ':end' => $endDate . ' 23:59:59'
    ]);
    $movimientos = $stmt->fetchAll();
    foreach ($movimientos as &$mov) {
        $mov['amount'] = (float)$mov['amount'];
    }
    unset($mov);
    return $movimientos;
}

function loadCajaCierres(PDO $pdo, array $authContext, $limit = null) {
    $sql = "SELECT * FROM `caja_cierres_historico` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id ORDER BY `timestamp` DESC";
    if ($limit !== null) {
        $sql .= " LIMIT " . max(1, (int)$limit);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute(tenantParams($authContext));
    $cierres = $stmt->fetchAll();
    foreach ($cierres as &$cierre) {
        $cierre['caja_inicial'] = (float)$cierre['caja_inicial'];
        $cierre['ingresos_efectivo'] = (float)$cierre['ingresos_efectivo'];
        $cierre['egresos'] = (float)$cierre['egresos'];
        $cierre['efectivo_esperado'] = (float)$cierre['efectivo_esperado'];
        $cierre['efectivo_real'] = (float)$cierre['efectivo_real'];
        $cierre['diferencia'] = (float)$cierre['diferencia'];
        $cierre['utilidad_neta'] = (float)$cierre['utilidad_neta'];
    }
    unset($cierre);
    return $cierres;
}

function dashboardOrderItemUnitTotal(array $item): float {
    $unitTotal = (float)($item['price'] ?? 0);
    foreach (($item['salsas'] ?? []) as $salsa) $unitTotal += (float)($salsa['salsaPrice'] ?? 0);
    foreach (($item['accompaniments'] ?? []) as $accompaniment) $unitTotal += (float)($accompaniment['accompanimentPrice'] ?? 0);
    return $unitTotal;
}

function loadDashboardData(PDO $pdo, array $authContext, string $startDate, string $endDate) {
    $params = [
        ':start' => $startDate . ' 00:00:00',
        ':end' => $endDate . ' 23:59:59',
        ':tenant_id' => $authContext['tenant_id'],
        ':branch_id' => $authContext['branch_id']
    ];

    $summaryStmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN `status` = 'completado' OR (`status` = 'pendiente' AND `paid` = 1) THEN `total` ELSE 0 END), 0) AS totalRevenue,
            COUNT(CASE WHEN `status` = 'completado' OR (`status` = 'pendiente' AND `paid` = 1) THEN 1 END) AS totalOrders,
            COUNT(CASE WHEN `status` = 'anulado' THEN 1 END) AS totalAnulled
        FROM `pedidos`
        WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND COALESCE(`sold_at`, `timestamp`) BETWEEN :start AND :end AND (`status` IN ('completado', 'anulado') OR (`status` = 'pendiente' AND `paid` = 1))
    ");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch();
    $totalRevenue = (float)$summary['totalRevenue'];
    $totalOrders = (int)$summary['totalOrders'];
    $avgTicket = $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0;

    $byDayStmt = $pdo->prepare("
        SELECT DATE(COALESCE(`sold_at`, `timestamp`)) AS `day`,
               SUM(`total`) AS revenue,
               COUNT(*) AS orders
        FROM `pedidos`
        WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND COALESCE(`sold_at`, `timestamp`) BETWEEN :start AND :end AND (`status` = 'completado' OR (`status` = 'pendiente' AND `paid` = 1))
        GROUP BY DATE(COALESCE(`sold_at`, `timestamp`))
        ORDER BY `day` ASC
    ");
    $byDayStmt->execute($params);
    $salesByDay = [];
    foreach ($byDayStmt->fetchAll() as $row) {
        $salesByDay[] = ['day' => $row['day'], 'revenue' => (float)$row['revenue'], 'orders' => (int)$row['orders']];
    }

    $ordersStmt = $pdo->prepare("
        SELECT `items` FROM `pedidos`
        WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND COALESCE(`sold_at`, `timestamp`) BETWEEN :start AND :end AND (`status` = 'completado' OR (`status` = 'pendiente' AND `paid` = 1))
    ");
    $ordersStmt->execute($params);
    $categoryMap = ['sopa' => 'Sopa', 'segundo' => 'Segundo', 'plato_extra' => 'Plato Extra', 'refresco' => 'Refresco'];
    $catCounts = [];
    $catRevenue = [];
    $productCounts = [];
    $productRevenue = [];
    while ($row = $ordersStmt->fetch()) {
        $items = json_decode($row['items'], true) ?? [];
        foreach ($items as $item) {
            $type = $item['type'] ?? '';
            $name = $item['name'] ?? 'Desconocido';
            $qty = max(1, (int)($item['qty'] ?? $item['quantity'] ?? 1));
            $price = dashboardOrderItemUnitTotal($item);
            $catLabel = $categoryMap[$type] ?? ucfirst($type);
            $catCounts[$catLabel] = ($catCounts[$catLabel] ?? 0) + $qty;
            $catRevenue[$catLabel] = ($catRevenue[$catLabel] ?? 0) + ($price * $qty);
            $productCounts[$name] = ($productCounts[$name] ?? 0) + $qty;
            $productRevenue[$name] = ($productRevenue[$name] ?? 0) + ($price * $qty);
        }
    }
    $salesByCategory = [];
    foreach ($catCounts as $cat => $count) {
        $salesByCategory[] = ['category' => $cat, 'count' => $count, 'revenue' => round($catRevenue[$cat] ?? 0, 2)];
    }
    usort($salesByCategory, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

    $topProducts = [];
    arsort($productCounts);
    $count = 0;
    foreach ($productCounts as $name => $qty) {
        if ($count >= 10) break;
        $topProducts[] = ['name' => $name, 'count' => $qty, 'revenue' => round($productRevenue[$name] ?? 0, 2)];
        $count++;
    }

    $payStmt = $pdo->prepare("
        SELECT `payment_method`, `total`
        FROM `pedidos`
        WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND COALESCE(`sold_at`, `timestamp`) BETWEEN :start AND :end AND (`status` = 'completado' OR (`status` = 'pendiente' AND `paid` = 1))
    ");
    $payStmt->execute($params);
    $paymentAgg = ['efectivo' => 0, 'qr' => 0, 'tarjeta' => 0, 'transferencia' => 0, 'otro' => 0];
    $paymentCounts = ['efectivo' => 0, 'qr' => 0, 'tarjeta' => 0, 'transferencia' => 0, 'otro' => 0];
    foreach ($payStmt->fetchAll() as $row) {
        $pm = $row['payment_method'] ?? 'efectivo';
        $total = (float)$row['total'];
        $decoded = json_decode($pm, true);
        if (is_array($decoded)) {
            foreach ($paymentAgg as $method => $_) {
                if ((float)($decoded[$method] ?? 0) <= 0) continue;
                $paymentAgg[$method] += (float)$decoded[$method];
                $paymentCounts[$method]++;
            }
        } else {
            $method = array_key_exists($pm, $paymentAgg) ? $pm : 'otro';
            $paymentAgg[$method] += $total;
            $paymentCounts[$method]++;
        }
    }
    $paymentMethods = [];
    foreach ($paymentAgg as $method => $total) {
        if ($total > 0) {
            $paymentMethods[] = ['method' => $method, 'count' => $paymentCounts[$method], 'total' => round($total, 2)];
        }
    }

    $hourStmt = $pdo->prepare("
        SELECT HOUR(COALESCE(`sold_at`, `timestamp`)) AS hr, COUNT(*) AS orders
        FROM `pedidos`
        WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND COALESCE(`sold_at`, `timestamp`) BETWEEN :start AND :end AND (`status` = 'completado' OR (`status` = 'pendiente' AND `paid` = 1))
        GROUP BY HOUR(COALESCE(`sold_at`, `timestamp`))
        ORDER BY `hr` ASC
    ");
    $hourStmt->execute($params);
    $peakHours = [];
    foreach ($hourStmt->fetchAll() as $row) {
        $peakHours[] = ['hour' => (int)$row['hr'], 'orders' => (int)$row['orders']];
    }

    $weekStmt = $pdo->prepare("
        SELECT YEARWEEK(COALESCE(`sold_at`, `timestamp`), 1) AS wk,
               SUM(`total`) AS revenue,
               COUNT(*) AS orders
        FROM `pedidos`
        WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND (`status` = 'completado' OR (`status` = 'pendiente' AND `paid` = 1)) AND COALESCE(`sold_at`, `timestamp`) BETWEEN DATE_SUB(:start, INTERVAL 90 DAY) AND :end
        GROUP BY YEARWEEK(COALESCE(`sold_at`, `timestamp`), 1)
        ORDER BY `wk` ASC
    ");
    $weekStmt->execute($params);
    $weeklyTrend = [];
    foreach ($weekStmt->fetchAll() as $row) {
        $weeklyTrend[] = ['week' => $row['wk'], 'revenue' => (float)$row['revenue'], 'orders' => (int)$row['orders']];
    }

    $detailStmt = $pdo->prepare("
        SELECT `id`, `customer`, `total`, `payment_method`, `timestamp`, `sold_at`, `items`, `status`, `paid`, `delivery_type`, `table_id`, `subtotal`, `discount_total`
        FROM `pedidos`
        WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND COALESCE(`sold_at`, `timestamp`) BETWEEN :start AND :end AND (`status` = 'completado' OR (`status` = 'pendiente' AND `paid` = 1))
        ORDER BY COALESCE(`sold_at`, `timestamp`) DESC
    ");
    $detailStmt->execute($params);
    $orderDetails = normalizeOrders($detailStmt->fetchAll());

    return [
        'summary' => [
            'totalRevenue' => $totalRevenue,
            'totalOrders' => $totalOrders,
            'avgTicket' => $avgTicket,
            'totalAnulled' => (int)$summary['totalAnulled']
        ],
        'salesByDay' => $salesByDay,
        'salesByCategory' => $salesByCategory,
        'topProducts' => $topProducts,
        'paymentMethods' => $paymentMethods,
        'peakHours' => $peakHours,
        'weeklyTrend' => $weeklyTrend,
        'orderDetails' => $orderDetails
    ];
}

function loadSaasAdminData(PDO $pdo): array
{
    $plans = $pdo->query("SELECT * FROM `plans` ORDER BY `price_monthly` ASC")->fetchAll(PDO::FETCH_ASSOC);
    $plansById = [];
    foreach ($plans as $plan) {
        $plan['features'] = json_decode($plan['features'] ?? '{}', true);
        $plan['active'] = (bool)$plan['active'];
        $plansById[$plan['id']] = $plan;
    }

    $tenants = $pdo->query("
        SELECT
            t.id, t.slug, t.name, t.active, t.business_type, t.plan_level, t.created_at,
            COALESCE(ts.plan_code, 'legacy') AS plan_code,
            COALESCE(ts.status, 'trial') AS subscription_status,
            ts.plan_id,
            ts.billing_period,
            ts.payment_method,
            ts.payment_notes,
            ts.verified_by,
            ts.verified_at,
            ts.starts_at,
            ts.ends_at,
            (SELECT u.name FROM `users` u WHERE u.tenant_id = t.id AND u.role = 'owner' ORDER BY u.created_at ASC LIMIT 1) AS owner_name,
            (SELECT u.email FROM `users` u WHERE u.tenant_id = t.id AND u.role = 'owner' ORDER BY u.created_at ASC LIMIT 1) AS owner_email,
            (SELECT COUNT(*) FROM `branches` b WHERE b.tenant_id = t.id) AS branches_count,
            (SELECT COUNT(*) FROM `users` u WHERE u.tenant_id = t.id) AS users_count
        FROM `tenants` t
        LEFT JOIN `tenant_subscriptions` ts ON ts.tenant_id = t.id
        ORDER BY t.created_at DESC
    ")->fetchAll();

    foreach ($tenants as &$tenant) {
        $tenant['active'] = (bool)$tenant['active'];
        $tenant['branches_count'] = (int)$tenant['branches_count'];
        $tenant['users_count'] = (int)$tenant['users_count'];

        $plan = null;
        if ($tenant['plan_id'] && isset($plansById[$tenant['plan_id']])) {
            $plan = $plansById[$tenant['plan_id']];
        }
        $tenant['plan'] = $plan;
    }
    unset($tenant);

    $branches = $pdo->query("
        SELECT b.id, b.tenant_id, b.name, b.active, b.created_at, t.name AS tenant_name
        FROM `branches` b
        INNER JOIN `tenants` t ON t.id = b.tenant_id
        ORDER BY b.created_at DESC
    ")->fetchAll();

    foreach ($branches as &$branch) {
        $branch['active'] = (bool)$branch['active'];
    }
    unset($branch);

    $users = $pdo->query("
        SELECT u.id, u.tenant_id, u.branch_id, u.name, u.email, u.role, u.active, u.created_at,
               t.name AS tenant_name, b.name AS branch_name
        FROM `users` u
        INNER JOIN `tenants` t ON t.id = u.tenant_id
        INNER JOIN `branches` b ON b.id = u.branch_id
        ORDER BY u.created_at DESC
    ")->fetchAll();

    foreach ($users as &$user) {
        $user['active'] = (bool)$user['active'];
    }
    unset($user);

    return [
        'saasAdmin' => [
            'tenants' => $tenants,
            'branches' => $branches,
            'users' => $users,
            'plans' => $plans,
            'summary' => [
                'tenants' => count($tenants),
                'branches' => count($branches),
                'users' => count($users),
                'activeSubscriptions' => count(array_filter($tenants, fn($tenant) => in_array($tenant['subscription_status'], ['trial', 'active', 'past_due'], true))),
                'plans' => count($plans)
            ]
        ]
    ];
}

function checkPlanLimits(PDO $pdo, string $tenantId, string $type): array
{
    $stmt = $pdo->prepare("SELECT ts.plan_id, ts.plan_snapshot, p.max_branches, p.max_users, p.max_products FROM `tenant_subscriptions` ts LEFT JOIN `plans` p ON p.id = ts.plan_id WHERE ts.tenant_id = :tid LIMIT 1");
    $stmt->execute(['tid' => $tenantId]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sub || !$sub['plan_id']) {
        return ['allowed' => true, 'limit' => null, 'current' => null, 'plan' => null];
    }

    $snapshot = $sub['plan_snapshot'] ? json_decode($sub['plan_snapshot'], true) : null;
    $maxBranches = $snapshot['max_branches'] ?? (int)$sub['max_branches'];
    $maxUsers = $snapshot['max_users'] ?? (int)$sub['max_users'];
    $maxProducts = $snapshot['max_products'] ?? (int)$sub['max_products'];

    $limitMap = [
        'branches' => ['limit' => $maxBranches, 'table' => 'branches'],
        'users' => ['limit' => $maxUsers, 'table' => 'users'],
        'products' => ['limit' => $maxProducts, 'table' => 'products']
    ];

    if (!isset($limitMap[$type])) {
        return ['allowed' => true, 'limit' => null, 'current' => null, 'plan' => $sub];
    }

    $info = $limitMap[$type];
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `{$info['table']}` WHERE `tenant_id` = :tid");
    $countStmt->execute(['tid' => $tenantId]);
    $current = (int)$countStmt->fetchColumn();

    return [
        'allowed' => $current < $info['limit'],
        'limit' => $info['limit'],
        'current' => $current,
        'plan' => $sub
    ];
}

function getTenantFeatures(PDO $pdo, string $tenantId): array
{
    $defaultFeatures = ['pos' => true, 'reportes' => 'basicos', 'reservations' => false, 'inventory' => true, 'caja' => true, 'delivery' => false, 'multi_branch' => false, 'priority_support' => false];

    $stmt = $pdo->prepare("SELECT ts.plan_snapshot, p.features FROM `tenant_subscriptions` ts LEFT JOIN `plans` p ON p.id = ts.plan_id WHERE ts.tenant_id = :tid AND ts.status IN ('trial', 'active', 'past_due') LIMIT 1");
    $stmt->execute(['tid' => $tenantId]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sub) {
        return $defaultFeatures;
    }

    if ($sub['plan_snapshot']) {
        $snapshot = json_decode($sub['plan_snapshot'], true);
        if (isset($snapshot['features']) && is_array($snapshot['features'])) {
            return $snapshot['features'];
        }
    }

    if ($sub['features']) {
        $decoded = json_decode($sub['features'], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return $defaultFeatures;
}

function createPlanSnapshot(PDO $pdo, string $planId): ?string
{
    $stmt = $pdo->prepare("SELECT * FROM `plans` WHERE `id` = :id LIMIT 1");
    $stmt->execute(['id' => $planId]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan) return null;

    $snapshot = [
        'plan_id' => $plan['id'],
        'plan_code' => $plan['code'],
        'plan_name' => $plan['name'],
        'features' => json_decode($plan['features'], true) ?: [],
        'max_branches' => (int)$plan['max_branches'],
        'max_users' => (int)$plan['max_users'],
        'max_products' => (int)$plan['max_products'],
        'price_monthly' => (float)$plan['price_monthly'],
        'price_yearly' => (float)$plan['price_yearly'],
        'trial_days' => (int)($plan['trial_days'] ?? 14)
    ];
    return json_encode($snapshot);
}

function logPlanChange(PDO $pdo, string $tenantId, ?string $oldPlanId, ?string $newPlanId, ?string $oldSnapshot, ?string $newSnapshot, ?string $changedBy): void
{
    $id = 'pcl_' . bin2hex(random_bytes(12));
    $stmt = $pdo->prepare("INSERT INTO `plan_changes_log` (`id`, `tenant_id`, `old_plan_id`, `new_plan_id`, `old_snapshot`, `new_snapshot`, `changed_by`, `created_at`) VALUES (:id, :tenant_id, :old_plan_id, :new_plan_id, :old_snapshot, :new_snapshot, :changed_by, NOW())");
    $stmt->execute([
        'id' => $id,
        'tenant_id' => $tenantId,
        'old_plan_id' => $oldPlanId,
        'new_plan_id' => $newPlanId,
        'old_snapshot' => $oldSnapshot,
        'new_snapshot' => $newSnapshot,
        'changed_by' => $changedBy
    ]);
}

function logStockEvent(PDO $pdo, array $authContext, string $itemType, string $itemId, string $itemName, string $eventType, int $qtyBefore, int $qtyChange, int $qtyAfter, ?string $refType = null, ?string $refId = null): void {
    try {
        $id = 'sh_' . bin2hex(random_bytes(12));
        $stmt = $pdo->prepare("
            INSERT INTO `stock_history` (`id`, `tenant_id`, `branch_id`, `item_type`, `item_id`, `event_type`, `quantity_before`, `quantity_change`, `quantity_after`, `reference_type`, `reference_id`, `created_at`)
            VALUES (:id, :tid, :bid, :item_type, :item_id, :event_type, :qty_before, :qty_change, :qty_after, :ref_type, :ref_id, NOW())
        ");
        $stmt->execute([
            'id' => $id, 'tid' => $authContext['tenant_id'], 'bid' => $authContext['branch_id'],
            'item_type' => $itemType, 'item_id' => $itemId, 'event_type' => $eventType,
            'qty_before' => $qtyBefore, 'qty_change' => $qtyChange, 'qty_after' => $qtyAfter,
            'ref_type' => $refType, 'ref_id' => $refId
        ]);
    } catch (Throwable $e) {
        error_log("[RestoCloud] logStockEvent failed: " . $e->getMessage());
    }
}

function getStockTable(string $itemType): string {
    $map = ['segundo' => 'segundos', 'sopa' => 'sopas', 'plato_extra' => 'platos_extras', 'gaseosa' => 'gaseosas', 'salsa' => 'salsas'];
    return $map[$itemType] ?? 'products';
}
