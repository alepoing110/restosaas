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
    try {
        foreach ($tables as $catalogTable => $productType) {
            $pdo->prepare("
                INSERT IGNORE INTO `products` (`id`, `name`, `type`, `price`, `stock`, `menu_id`, `active`, `tenant_id`, `branch_id`)
                SELECT c.`id`, c.`name`, :ptype, COALESCE(c.`price`, 0), COALESCE(c.`stock`, 0), NULL, 1, c.`tenant_id`, c.`branch_id`
                FROM `$catalogTable` c
                LEFT JOIN `products` p ON p.`id` = c.`id` AND p.`tenant_id` = c.`tenant_id` AND p.`branch_id` = c.`branch_id`
                WHERE c.`tenant_id` = :tenant_id AND c.`branch_id` = :branch_id AND p.`id` IS NULL
            ")->execute(array_merge(['ptype' => $productType], $params));
        }
    } catch (Throwable $e) {
        error_log("[RestoCloud] _backfillProductsForTenant failed: " . $e->getMessage());
    }
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
    }
    unset($s);

    $stmt = $pdo->prepare("SELECT * FROM `platos_extras` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    $stmt->execute($params);
    $platosExtras = $stmt->fetchAll();
    foreach ($platosExtras as &$p) {
        $p['price'] = (float)$p['price'];
        $p['stock'] = (int)$p['stock'];
        $p['accepts_salsa'] = isset($p['accepts_salsa']) ? (bool)$p['accepts_salsa'] : false;
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
            $sopas[] = $s;
        }
        unset($s);
    } catch (Throwable $e) {
        error_log("[RestoCloud] loadCatalogState sopas query failed: " . $e->getMessage());
    }

    $salsas = [];
    try {
        $stmt = $pdo->prepare("SELECT * FROM `salsas` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
        $stmt->execute($params);
        $salsaRows = $stmt->fetchAll();
        foreach ($salsaRows as &$sa) {
            $sa['price'] = (float)$sa['price'];
            $sa['active'] = isset($sa['active']) ? (bool)$sa['active'] : true;
            $salsas[] = $sa;
        }
        unset($sa);
    } catch (Throwable $e) {
        error_log("[RestoCloud] loadCatalogState salsas query failed: " . $e->getMessage());
    }

    $menus = [];
    try {
        $stmt = $pdo->prepare("SELECT * FROM `menus` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id ORDER BY `name` ASC");
        $stmt->execute($params);
        $menuRows = $stmt->fetchAll();
        foreach ($menuRows as &$m) {
            $m['active'] = (bool)$m['active'];
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
        $order['serviceState'] = $order['service_state'] ?? null;
        $order['paid'] = !empty($order['paid']);
        $ts = $order['timestamp'] ?? '';
        if ($ts && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $ts)) {
            $order['timestamp'] = str_replace(' ', 'T', substr($ts, 0, 19));
        }
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
    $sql = "SELECT * FROM `pedidos` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `status` IN ('completado', 'anulado') AND `closure_id` IS NULL ORDER BY `timestamp` DESC";
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
        SELECT * FROM `pedidos`
        WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id
          AND `status` IN ('completado', 'anulado')
          AND `timestamp` BETWEEN :start AND :end
        ORDER BY `timestamp` DESC
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

function loadDashboardData(PDO $pdo, array $authContext, string $startDate, string $endDate) {
    $params = [
        ':start' => $startDate . ' 00:00:00',
        ':end' => $endDate . ' 23:59:59',
        ':tenant_id' => $authContext['tenant_id'],
        ':branch_id' => $authContext['branch_id']
    ];

    $summaryStmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN `status` = 'completado' THEN `total` ELSE 0 END), 0) AS totalRevenue,
            COUNT(CASE WHEN `status` = 'completado' THEN 1 END) AS totalOrders,
            COUNT(CASE WHEN `status` = 'anulado' THEN 1 END) AS totalAnulled
        FROM `pedidos`
        WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `timestamp` BETWEEN :start AND :end AND `status` IN ('completado', 'anulado')
    ");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch();
    $totalRevenue = (float)$summary['totalRevenue'];
    $totalOrders = (int)$summary['totalOrders'];
    $avgTicket = $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0;

    $byDayStmt = $pdo->prepare("
        SELECT DATE(`timestamp`) AS `day`,
               SUM(`total`) AS revenue,
               COUNT(*) AS orders
        FROM `pedidos`
        WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `timestamp` BETWEEN :start AND :end AND `status` = 'completado'
        GROUP BY DATE(`timestamp`)
        ORDER BY `day` ASC
    ");
    $byDayStmt->execute($params);
    $salesByDay = [];
    foreach ($byDayStmt->fetchAll() as $row) {
        $salesByDay[] = ['day' => $row['day'], 'revenue' => (float)$row['revenue'], 'orders' => (int)$row['orders']];
    }

    $ordersStmt = $pdo->prepare("
        SELECT `items` FROM `pedidos`
        WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `timestamp` BETWEEN :start AND :end AND `status` = 'completado'
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
            $qty = (int)($item['quantity'] ?? 1);
            $price = (float)($item['price'] ?? 0);
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
        WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `timestamp` BETWEEN :start AND :end AND `status` = 'completado'
    ");
    $payStmt->execute($params);
    $paymentAgg = ['efectivo' => 0, 'qr' => 0, 'tarjeta' => 0];
    $paymentCounts = ['efectivo' => 0, 'qr' => 0, 'tarjeta' => 0];
    foreach ($payStmt->fetchAll() as $row) {
        $pm = $row['payment_method'] ?? 'efectivo';
        $total = (float)$row['total'];
        $decoded = json_decode($pm, true);
        if (is_array($decoded)) {
            if (isset($decoded['efectivo']) && $decoded['efectivo'] > 0) {
                $paymentAgg['efectivo'] += (float)$decoded['efectivo'];
                $paymentCounts['efectivo']++;
            }
            if (isset($decoded['qr']) && $decoded['qr'] > 0) {
                $paymentAgg['qr'] += (float)$decoded['qr'];
                $paymentCounts['qr']++;
            }
            if (isset($decoded['tarjeta']) && $decoded['tarjeta'] > 0) {
                $paymentAgg['tarjeta'] += (float)$decoded['tarjeta'];
                $paymentCounts['tarjeta']++;
            }
        } else {
            $method = in_array($pm, ['efectivo', 'qr', 'tarjeta']) ? $pm : 'efectivo';
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
        SELECT HOUR(`timestamp`) AS hr, COUNT(*) AS orders
        FROM `pedidos`
        WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `timestamp` BETWEEN :start AND :end AND `status` = 'completado'
        GROUP BY HOUR(`timestamp`)
        ORDER BY `hr` ASC
    ");
    $hourStmt->execute($params);
    $peakHours = [];
    foreach ($hourStmt->fetchAll() as $row) {
        $peakHours[] = ['hour' => (int)$row['hr'], 'orders' => (int)$row['orders']];
    }

    $weekStmt = $pdo->prepare("
        SELECT YEARWEEK(`timestamp`, 1) AS wk,
               SUM(`total`) AS revenue,
               COUNT(*) AS orders
        FROM `pedidos`
        WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `status` = 'completado' AND `timestamp` BETWEEN DATE_SUB(:start, INTERVAL 90 DAY) AND :end
        GROUP BY YEARWEEK(`timestamp`, 1)
        ORDER BY `wk` ASC
    ");
    $weekStmt->execute($params);
    $weeklyTrend = [];
    foreach ($weekStmt->fetchAll() as $row) {
        $weeklyTrend[] = ['week' => $row['wk'], 'revenue' => (float)$row['revenue'], 'orders' => (int)$row['orders']];
    }

    $detailStmt = $pdo->prepare("
        SELECT `id`, `customer`, `total`, `payment_method`, `timestamp`, `items`
        FROM `pedidos`
        WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `timestamp` BETWEEN :start AND :end AND `status` = 'completado'
        ORDER BY `timestamp` DESC
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
