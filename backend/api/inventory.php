<?php
// Inventory & catalog action handlers

function _invalidateCatalogCache(array $authContext): void {
    cacheInvalidateTenant('catalog', $authContext['tenant_id'], $authContext['branch_id']);
}

function _ensureProductRecord(PDO $pdo, array $authContext, string $id, string $name, string $type, float $price, int $stock): void {
    $typeMap = ['segundo' => 'segundo', 'sopa' => 'sopa', 'plato_extra' => 'plato_extra', 'extra' => 'refresco'];
    $productType = $typeMap[$type] ?? null;
    if (!$productType) return;

    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];

    try {
        $check = $pdo->prepare("SELECT id, menu_id FROM `products` WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
        $check->execute(['id' => $id, 'tid' => $tid, 'bid' => $bid]);
        $existing = $check->fetch();

        if ($existing) {
            $stmt = $pdo->prepare("UPDATE `products` SET `name` = :name, `price` = :price, `stock` = :stock WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
            $stmt->execute(['name' => $name, 'price' => $price, 'stock' => $stock, 'id' => $id, 'tid' => $tid, 'bid' => $bid]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO `products` (`id`, `name`, `type`, `price`, `stock`, `menu_id`, `tenant_id`, `branch_id`) VALUES (:id, :name, :type, :price, :stock, NULL, :tenant_id, :branch_id)");
            $stmt->execute(['id' => $id, 'name' => $name, 'type' => $productType, 'price' => $price, 'stock' => $stock, 'tenant_id' => $tid, 'branch_id' => $bid]);
        }
    } catch (Throwable $e) {
        error_log("[RestoCloud] _ensureProductRecord failed for $id: " . $e->getMessage());
    }
}

function handle_save_item(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'inventory');
    $type = $input['type'] ?? '';
    $id = $input['id'] ?? '';
    $name = $input['name'] ?? '';
    $price = isset($input['price']) ? (float)$input['price'] : 0.0;
    $stock = isset($input['stock']) ? (int)$input['stock'] : 0;
    $active = isset($input['active']) ? (int)$input['active'] : 1;

    if (empty($id) || empty($name) || $stock < 0) {
        echo json_encode(["status" => "error", "message" => "Datos del item inválidos"]);
        return;
    }

    $validTypes = ['segundo', 'plato_extra', 'extra', 'sopa', 'salsa'];
    if (!in_array($type, $validTypes)) {
        echo json_encode(["status" => "error", "message" => "Tipo de item inválido"]);
        return;
    }

    if ($price < 0 && in_array($type, ['plato_extra', 'extra'])) {
        echo json_encode(["status" => "error", "message" => "Precio inválido"]);
        return;
    }

    $acceptsSalsa = isset($input['accepts_salsa']) ? (int)$input['accepts_salsa'] : 0;
    $tableMap = ['segundo' => 'segundos', 'sopa' => 'sopas', 'plato_extra' => 'platos_extras', 'extra' => 'gaseosas', 'salsa' => 'salsas'];
    $table = $tableMap[$type];
    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];

    $checkStmt = $pdo->prepare("SELECT id FROM `$table` WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
    $checkStmt->execute(['id' => $id, 'tid' => $tid, 'bid' => $bid]);
    $exists = $checkStmt->fetch();

    // Build column sets based on type
    $hasPrice = in_array($type, ['plato_extra', 'extra', 'salsa']);
    $hasStock = in_array($type, ['segundo', 'sopa', 'plato_extra', 'extra']);
    $hasActive = in_array($type, ['segundo', 'sopa', 'salsa']);
    $hasAcceptsSalsa = in_array($type, ['segundo', 'sopa', 'plato_extra']);

    if ($exists) {
        $sets = ['`name` = :name'];
        $params = ['name' => $name, 'id' => $id, 'tid' => $tid, 'bid' => $bid];
        if ($hasPrice) { $sets[] = '`price` = :price'; $params['price'] = $price; }
        if ($hasStock) { $sets[] = '`stock` = :stock'; $params['stock'] = $stock; }
        if ($hasActive) { $sets[] = '`active` = :active'; $params['active'] = $active; }
        if ($hasAcceptsSalsa) { $sets[] = '`accepts_salsa` = :accepts_salsa'; $params['accepts_salsa'] = $acceptsSalsa; }
        $stmt = $pdo->prepare("UPDATE `$table` SET " . implode(', ', $sets) . " WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
        $stmt->execute($params);
    } else {
        $cols = ['`id`', '`name`', '`tenant_id`', '`branch_id`'];
        $placeholders = [':id', ':name', ':tenant_id', ':branch_id'];
        $params = ['id' => $id, 'name' => $name, 'tenant_id' => $tid, 'branch_id' => $bid];
        if ($hasPrice) { $cols[] = '`price`'; $placeholders[] = ':price'; $params['price'] = $price; }
        if ($hasStock) { $cols[] = '`stock`'; $placeholders[] = ':stock'; $params['stock'] = $stock; }
        if ($hasActive) { $cols[] = '`active`'; $placeholders[] = ':active'; $params['active'] = $active; }
        if ($hasAcceptsSalsa) { $cols[] = '`accepts_salsa`'; $placeholders[] = ':accepts_salsa'; $params['accepts_salsa'] = $acceptsSalsa; }
        $stmt = $pdo->prepare("INSERT INTO `$table` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")");
        $stmt->execute($params);
    }
    writeAuditLog($pdo, $authContext, 'catalog.item.save', $type, $id);
    _ensureProductRecord($pdo, $authContext, $id, $name, $type, $price, $stock);
    _invalidateCatalogCache($authContext);
    echo json_encode(["status" => "success"]);
}

function handle_delete_item(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'inventory');
    $type = $input['type'] ?? '';
    $id = $input['id'] ?? '';

    $stmt = null;
    if ($type === 'segundo') {
        $stmt = $pdo->prepare("DELETE FROM `segundos` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    } else if ($type === 'sopa') {
        $stmt = $pdo->prepare("DELETE FROM `sopas` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    } else if ($type === 'plato_extra') {
        $stmt = $pdo->prepare("DELETE FROM `platos_extras` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    } else if ($type === 'extra') {
        $stmt = $pdo->prepare("DELETE FROM `gaseosas` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    } else if ($type === 'salsa') {
        $stmt = $pdo->prepare("DELETE FROM `salsas` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    }

    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => "Tipo de item inválido"]);
        return;
    }

    $stmt->execute(['id' => $id, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);

    // Also delete corresponding product record to prevent orphaned records
    try {
        $productStmt = $pdo->prepare("DELETE FROM `products` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
        $productStmt->execute(['id' => $id, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    } catch (Throwable $e) {
        // Ignore errors for backward compatibility
    }

    writeAuditLog($pdo, $authContext, 'catalog.item.delete', $type, $id);
    _invalidateCatalogCache($authContext);
    echo json_encode(["status" => "success"]);
}

function handle_save_product(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'inventory');
    $id = $input['id'] ?? '';
    $name = trim($input['name'] ?? '');
    $type = $input['type'] ?? '';
    $price = isset($input['price']) ? (float)$input['price'] : 0.0;
    $stock = isset($input['stock']) ? (int)$input['stock'] : 0;
    $menuId = !empty($input['menu_id']) ? $input['menu_id'] : null;

    if (empty($id) || empty($name)) {
        echo json_encode(["status" => "error", "message" => "Nombre e ID son requeridos"]);
        return;
    }

    $validTypes = ['sopa', 'segundo', 'plato_extra', 'refresco'];
    if (!in_array($type, $validTypes)) {
        echo json_encode(["status" => "error", "message" => "Tipo de producto inválido"]);
        return;
    }

    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];

    $checkStmt = $pdo->prepare("SELECT id FROM `products` WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
    $checkStmt->execute(['id' => $id, 'tid' => $tid, 'bid' => $bid]);
    $exists = $checkStmt->fetch();

    if ($exists) {
        $stmt = $pdo->prepare("UPDATE `products` SET `name` = :name, `type` = :type, `price` = :price, `stock` = :stock, `menu_id` = :menu_id WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
        $stmt->execute(['name' => $name, 'type' => $type, 'price' => $price, 'stock' => $stock, 'menu_id' => $menuId, 'id' => $id, 'tid' => $tid, 'bid' => $bid]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO `products` (`id`, `name`, `type`, `price`, `stock`, `menu_id`, `tenant_id`, `branch_id`) VALUES (:id, :name, :type, :price, :stock, :menu_id, :tenant_id, :branch_id)");
        $stmt->execute(['id' => $id, 'name' => $name, 'type' => $type, 'price' => $price, 'stock' => $stock, 'menu_id' => $menuId, 'tenant_id' => $tid, 'branch_id' => $bid]);
    }

    writeAuditLog($pdo, $authContext, 'product.save', 'product', $id);
    _invalidateCatalogCache($authContext);
    echo json_encode(["status" => "success"]);
}

function handle_delete_product(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'inventory');
    $id = $input['id'] ?? '';
    if (empty($id)) {
        echo json_encode(["status" => "error", "message" => "ID requerido"]);
        return;
    }
    $stmt = $pdo->prepare("DELETE FROM `products` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    $stmt->execute(['id' => $id, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    writeAuditLog($pdo, $authContext, 'product.delete', 'product', $id);
    _invalidateCatalogCache($authContext);
    echo json_encode(["status" => "success"]);
}

function handle_save_menu(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'inventory');
    $id = $input['id'] ?? '';
    $name = trim($input['name'] ?? '');
    $startTime = $input['start_time'] ?? null;
    $endTime = $input['end_time'] ?? null;
    $active = isset($input['active']) ? (int)$input['active'] : 1;

    if (empty($id) || empty($name)) {
        echo json_encode(["status" => "error", "message" => "Nombre e ID son requeridos"]);
        return;
    }

    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];

    $checkStmt = $pdo->prepare("SELECT id FROM `menus` WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
    $checkStmt->execute(['id' => $id, 'tid' => $tid, 'bid' => $bid]);
    $exists = $checkStmt->fetch();

    if ($exists) {
        $stmt = $pdo->prepare("UPDATE `menus` SET `name` = :name, `start_time` = :start_time, `end_time` = :end_time, `active` = :active WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
        $stmt->execute(['name' => $name, 'start_time' => $startTime, 'end_time' => $endTime, 'active' => $active, 'id' => $id, 'tid' => $tid, 'bid' => $bid]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO `menus` (`id`, `name`, `start_time`, `end_time`, `active`, `tenant_id`, `branch_id`) VALUES (:id, :name, :start_time, :end_time, :active, :tenant_id, :branch_id)");
        $stmt->execute(['id' => $id, 'name' => $name, 'start_time' => $startTime, 'end_time' => $endTime, 'active' => $active, 'tenant_id' => $tid, 'branch_id' => $bid]);
    }

    writeAuditLog($pdo, $authContext, 'menu.save', 'menu', $id);
    _invalidateCatalogCache($authContext);
    echo json_encode(["status" => "success"]);
}

function handle_delete_menu(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'inventory');
    $id = $input['id'] ?? '';
    if (empty($id)) {
        echo json_encode(["status" => "error", "message" => "ID requerido"]);
        return;
    }
    $stmt = $pdo->prepare("UPDATE `products` SET `menu_id` = NULL WHERE `menu_id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    $stmt->execute(['id' => $id, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    $stmt = $pdo->prepare("DELETE FROM `menus` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    $stmt->execute(['id' => $id, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    writeAuditLog($pdo, $authContext, 'menu.delete', 'menu', $id);
    _invalidateCatalogCache($authContext);
    echo json_encode(["status" => "success"]);
}

function handle_get_products_by_menu(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'pos');
    $menuId = $input['menu_id'] ?? null;
    if ($menuId) {
        $stmt = $pdo->prepare("SELECT * FROM `products` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `menu_id` = :menu_id AND `active` = 1 ORDER BY `type`, `name`");
        $stmt->execute(['tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id'], 'menu_id' => $menuId]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM `products` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `active` = 1 ORDER BY `type`, `name`");
        $stmt->execute(['tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    }
    $products = $stmt->fetchAll();
    foreach ($products as &$p) {
        $p['price'] = (float)$p['price'];
        $p['stock'] = (int)$p['stock'];
    }
    echo json_encode(["status" => "success", "products" => $products]);
}
