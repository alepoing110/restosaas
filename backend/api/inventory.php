<?php
// Inventory & catalog action handlers

function _invalidateCatalogCache(array $authContext): void {
    cacheInvalidateTenant('catalog', $authContext['tenant_id'], $authContext['branch_id']);
}

const CATALOG_NAME_MAX_LENGTH = 100;
const CATALOG_STOCK_MAX = 1000000;
const CATALOG_MONEY_MAX = 99999999.99;

function catalogString($value, string $field): string {
    if (!is_string($value)) throw new InvalidArgumentException("El campo $field debe ser texto.");
    return $value;
}

function catalogId($value): string {
    $id = catalogString($value, 'ID');
    if (!preg_match('/^[A-Za-z0-9_-]{1,50}$/', $id)) throw new InvalidArgumentException('ID de catálogo inválido.');
    return $id;
}

function catalogName($value): string {
    $name = trim((string)preg_replace('/\s+/u', ' ', catalogString($value, 'nombre')));
    $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    if ($name === '' || $length > CATALOG_NAME_MAX_LENGTH) throw new InvalidArgumentException('El nombre debe tener entre 1 y 100 caracteres.');
    return $name;
}

function catalogNormalizedName(string $name): string {
    $name = trim((string)preg_replace('/\s+/u', ' ', $name));
    return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
}

function catalogMoney($value, string $field = 'precio'): float {
    if (!is_int($value) && !is_float($value) && !is_string($value)) throw new InvalidArgumentException("El campo $field debe ser numérico.");
    $raw = is_string($value) ? trim($value) : (string)$value;
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $raw)) throw new InvalidArgumentException("El campo $field debe ser un monto válido con hasta 2 decimales.");
    $amount = (float)$raw;
    if (!is_finite($amount) || $amount < 0 || $amount > CATALOG_MONEY_MAX) throw new InvalidArgumentException("El campo $field está fuera de rango.");
    return $amount;
}

function catalogInteger($value, string $field, int $min, int $max): int {
    if (is_int($value)) $number = $value;
    elseif (is_string($value) && preg_match('/^\d+$/', trim($value))) $number = (int)trim($value);
    else throw new InvalidArgumentException("El campo $field debe ser un número entero.");
    if ($number < $min || $number > $max) throw new InvalidArgumentException("El campo $field está fuera de rango.");
    return $number;
}

function catalogBoolean($value, string $field): int {
    return catalogInteger($value, $field, 0, 1);
}

function catalogTime($value, string $field): ?string {
    if ($value === null || $value === '') return null;
    $time = catalogString($value, $field);
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $time)) throw new InvalidArgumentException("El campo $field debe tener formato HH:MM.");
    return substr($time, 0, 5);
}

function assertCatalogNameAvailable(PDO $pdo, string $table, string $name, string $id, array $authContext): void {
    $stmt = $pdo->prepare("SELECT `id`, `name` FROM `$table` WHERE `tenant_id` = :tid AND `branch_id` = :bid");
    $stmt->execute(['tid' => $authContext['tenant_id'], 'bid' => $authContext['branch_id']]);
    $normalized = catalogNormalizedName($name);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['id'] !== $id && catalogNormalizedName((string)$row['name']) === $normalized) {
            throw new InvalidArgumentException('Ya existe un registro con ese nombre en esta categoría.');
        }
    }
}

function _ensureProductRecord(PDO $pdo, array $authContext, string $id, string $name, string $type, float $price, int $stock, int $active = 1, ?int $acceptsAccompaniment = null, ?int $maxIncludedAccompaniments = null, ?int $acceptsSalsa = null): void {
    $typeMap = ['segundo' => 'segundo', 'sopa' => 'sopa', 'plato_extra' => 'plato_extra', 'extra' => 'refresco', 'salsa' => 'salsa', 'acompanamiento' => 'acompanamiento'];
    $productType = $typeMap[$type] ?? null;
    if (!$productType) return;

    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];

    $check = $pdo->prepare("SELECT id FROM `products` WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
    $check->execute(['id' => $id, 'tid' => $tid, 'bid' => $bid]);
    if ($check->fetch()) {
        $sets = ['`name` = :name', '`price` = :price', '`stock` = :stock', '`active` = :active'];
        $params = ['name' => $name, 'price' => $price, 'stock' => $stock, 'active' => $active, 'id' => $id, 'tid' => $tid, 'bid' => $bid];
        if ($acceptsAccompaniment !== null) {
            $sets[] = '`accepts_accompaniment` = :accepts_accompaniment';
            $sets[] = '`max_included_accompaniments` = :max_included_accompaniments';
            $params['accepts_accompaniment'] = $acceptsAccompaniment;
            $params['max_included_accompaniments'] = $maxIncludedAccompaniments ?? 0;
        }
        if ($acceptsSalsa !== null) {
            $sets[] = '`accepts_salsa` = :accepts_salsa';
            $params['accepts_salsa'] = $acceptsSalsa;
        }
        $stmt = $pdo->prepare("UPDATE `products` SET " . implode(', ', $sets) . " WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
        $stmt->execute($params);
    } else {
        $stmt = $pdo->prepare("INSERT INTO `products` (`id`, `name`, `type`, `price`, `stock`, `menu_id`, `active`, `accepts_salsa`, `accepts_accompaniment`, `max_included_accompaniments`, `tenant_id`, `branch_id`) VALUES (:id, :name, :type, :price, :stock, NULL, :active, :accepts_salsa, :accepts_accompaniment, :max_included_accompaniments, :tenant_id, :branch_id)");
        $stmt->execute(['id' => $id, 'name' => $name, 'type' => $productType, 'price' => $price, 'stock' => $stock, 'active' => $active, 'accepts_salsa' => $acceptsSalsa ?? 0, 'accepts_accompaniment' => $acceptsAccompaniment ?? 0, 'max_included_accompaniments' => $maxIncludedAccompaniments ?? 0, 'tenant_id' => $tid, 'branch_id' => $bid]);
    }
}

function handle_save_item(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'inventory');
    $type = catalogString($input['type'] ?? '', 'tipo');
    $id = catalogId($input['id'] ?? '');
    $name = catalogName($input['name'] ?? '');

    $validTypes = ['segundo', 'plato_extra', 'extra', 'sopa', 'salsa', 'acompanamiento'];
    if (!in_array($type, $validTypes)) {
        echo json_encode(["status" => "error", "message" => "Tipo de item inválido"]);
        return;
    }

    $price = array_key_exists('price', $input) ? catalogMoney($input['price']) : 0.0;
    $stockProvided = array_key_exists('stock', $input);
    $stock = $stockProvided ? catalogInteger($input['stock'], 'stock', 0, CATALOG_STOCK_MAX) : 0;
    $active = array_key_exists('active', $input) ? catalogBoolean($input['active'], 'estado') : 1;
    $acceptsSalsa = array_key_exists('accepts_salsa', $input) ? catalogBoolean($input['accepts_salsa'], 'permite salsa') : null;
    $acceptsAccompaniment = array_key_exists('accepts_accompaniment', $input) ? catalogBoolean($input['accepts_accompaniment'], 'permite acompañamientos') : null;
    $maxIncludedAccompaniments = array_key_exists('max_included_accompaniments', $input) ? catalogInteger($input['max_included_accompaniments'], 'máximo de acompañamientos incluidos', 0, 20) : null;
    $tableMap = ['segundo' => 'segundos', 'sopa' => 'sopas', 'plato_extra' => 'platos_extras', 'extra' => 'gaseosas', 'salsa' => 'salsas', 'acompanamiento' => 'acompanamientos'];
    $table = $tableMap[$type];
    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];

    $checkStmt = $pdo->prepare("SELECT id, stock FROM `$table` WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
    $checkStmt->execute(['id' => $id, 'tid' => $tid, 'bid' => $bid]);
    $exists = $checkStmt->fetch();
    if ($exists && !$stockProvided && array_key_exists('stock', $exists)) $stock = (int)$exists['stock'];
    if (!$exists) {
        $globalId = $pdo->prepare("SELECT `tenant_id`, `branch_id` FROM `$table` WHERE `id` = :id LIMIT 1");
        $globalId->execute(['id' => $id]);
        if ($globalId->fetch()) throw new InvalidArgumentException('El ID ya pertenece a otro registro.');
        assertCatalogNameAvailable($pdo, $table, $name, $id, $authContext);
    } else {
        assertCatalogNameAvailable($pdo, $table, $name, $id, $authContext);
    }
    if (!$exists && !in_array($type, ['salsa', 'acompanamiento'], true)) {
        $limit = checkPlanLimits($pdo, $tid, 'products');
        if (!$limit['allowed']) {
            echo json_encode(["status" => "error", "message" => "Límite de productos alcanzado ({$limit['current']}/{$limit['limit']})"]);
            return;
        }
    }

    // Build column sets based on type
    $hasPrice = in_array($type, ['plato_extra', 'extra', 'salsa', 'acompanamiento']);
    $priceColumn = $type === 'acompanamiento' ? 'price_extra' : 'price';
    $hasStock = in_array($type, ['segundo', 'sopa', 'plato_extra', 'extra', 'salsa', 'acompanamiento']);
    $hasActive = in_array($type, ['segundo', 'sopa', 'salsa', 'acompanamiento']);
    $hasAcceptsSalsa = in_array($type, ['segundo', 'sopa', 'plato_extra']);
    $hasAccompaniments = in_array($type, ['segundo', 'sopa', 'plato_extra']);

    $pdo->beginTransaction();
    try {
    if ($exists) {
        $sets = ['`name` = :name'];
        $params = ['name' => $name, 'id' => $id, 'tid' => $tid, 'bid' => $bid];
        if ($hasPrice) { $sets[] = "`$priceColumn` = :price"; $params['price'] = $price; }
        if ($hasStock) { $sets[] = '`stock` = :stock'; $params['stock'] = $stock; }
        if ($hasActive) { $sets[] = '`active` = :active'; $params['active'] = $active; }
        if ($hasAcceptsSalsa && $acceptsSalsa !== null) { $sets[] = '`accepts_salsa` = :accepts_salsa'; $params['accepts_salsa'] = $acceptsSalsa; }
        if ($hasAccompaniments && $acceptsAccompaniment !== null) { $sets[] = '`accepts_accompaniment` = :accepts_accompaniment'; $params['accepts_accompaniment'] = $acceptsAccompaniment; $sets[] = '`max_included_accompaniments` = :max_included_accompaniments'; $params['max_included_accompaniments'] = $maxIncludedAccompaniments ?? 0; }
        $stmt = $pdo->prepare("UPDATE `$table` SET " . implode(', ', $sets) . " WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
        $stmt->execute($params);
    } else {
        $cols = ['`id`', '`name`', '`tenant_id`', '`branch_id`'];
        $placeholders = [':id', ':name', ':tenant_id', ':branch_id'];
        $params = ['id' => $id, 'name' => $name, 'tenant_id' => $tid, 'branch_id' => $bid];
        if ($hasPrice) { $cols[] = "`$priceColumn`"; $placeholders[] = ':price'; $params['price'] = $price; }
        if ($hasStock) { $cols[] = '`stock`'; $placeholders[] = ':stock'; $params['stock'] = $stock; }
        if ($hasActive) { $cols[] = '`active`'; $placeholders[] = ':active'; $params['active'] = $active; }
        if ($hasAcceptsSalsa) { $cols[] = '`accepts_salsa`'; $placeholders[] = ':accepts_salsa'; $params['accepts_salsa'] = $acceptsSalsa ?? 0; }
        if ($hasAccompaniments) { $cols[] = '`accepts_accompaniment`'; $placeholders[] = ':accepts_accompaniment'; $params['accepts_accompaniment'] = $acceptsAccompaniment ?? 0; $cols[] = '`max_included_accompaniments`'; $placeholders[] = ':max_included_accompaniments'; $params['max_included_accompaniments'] = $maxIncludedAccompaniments ?? 0; }
        $stmt = $pdo->prepare("INSERT INTO `$table` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")");
        $stmt->execute($params);
    }
    _ensureProductRecord($pdo, $authContext, $id, $name, $type, $price, $stock, $hasActive ? $active : 1, $acceptsAccompaniment, $maxIncludedAccompaniments, $hasAcceptsSalsa ? $acceptsSalsa : null);
    writeAuditLog($pdo, $authContext, 'catalog.item.save', $type, $id);
    $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        if ($e instanceof PDOException && $e->getCode() === '23000') {
            throw new InvalidArgumentException('Ya existe un registro con esos datos.');
        }
        throw $e;
    }
    _invalidateCatalogCache($authContext);
    echo json_encode(["status" => "success"]);
}

function handle_delete_item(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'inventory');
    $type = catalogString($input['type'] ?? '', 'tipo');
    $id = catalogId($input['id'] ?? '');

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
    } else if ($type === 'acompanamiento') {
        $stmt = $pdo->prepare("DELETE FROM `acompanamientos` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    }

    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => "Tipo de item inválido"]);
        return;
    }

    $pdo->beginTransaction();
    try {
        $stmt->execute(['id' => $id, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
        if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('El registro ya no existe o no pertenece a esta sucursal.');
        $productStmt = $pdo->prepare("DELETE FROM `products` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
        $productStmt->execute(['id' => $id, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
        writeAuditLog($pdo, $authContext, 'catalog.item.delete', $type, $id);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    _invalidateCatalogCache($authContext);
    echo json_encode(["status" => "success"]);
}

function handle_save_product(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'inventory');
    $id = catalogId($input['id'] ?? '');
    $name = catalogName($input['name'] ?? '');
    $type = catalogString($input['type'] ?? '', 'tipo');
    $price = catalogMoney($input['price'] ?? 0);
    $stock = catalogInteger($input['stock'] ?? 0, 'stock', 0, CATALOG_STOCK_MAX);
    $menuId = !empty($input['menu_id']) ? catalogId($input['menu_id']) : null;

    $validTypes = ['sopa', 'segundo', 'plato_extra', 'refresco'];
    if (!in_array($type, $validTypes)) {
        echo json_encode(["status" => "error", "message" => "Tipo de producto inválido"]);
        return;
    }
    if ($type === 'refresco') $menuId = null;

    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];

    if ($menuId !== null) {
        $menuCheck = $pdo->prepare("SELECT id FROM `menus` WHERE id = :id AND tenant_id = :tid AND branch_id = :bid LIMIT 1");
        $menuCheck->execute(['id' => $menuId, 'tid' => $tid, 'bid' => $bid]);
        if (!$menuCheck->fetch()) {
            echo json_encode(["status" => "error", "message" => "El menú no pertenece a la sucursal activa"]);
            return;
        }
    }

    $checkStmt = $pdo->prepare("SELECT id, type FROM `products` WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
    $checkStmt->execute(['id' => $id, 'tid' => $tid, 'bid' => $bid]);
    $exists = $checkStmt->fetch();

    if (!$exists) {
        echo json_encode(["status" => "error", "message" => "Crea los productos mediante el catálogo operativo"]);
        return;
    }
    if ($exists['type'] !== $type) throw new InvalidArgumentException('No se puede cambiar el tipo de un producto existente.');

    $pdo->beginTransaction();
    if ($exists) {
        $stmt = $pdo->prepare("UPDATE `products` SET `name` = :name, `type` = :type, `price` = :price, `stock` = :stock, `menu_id` = :menu_id WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
        $stmt->execute(['name' => $name, 'type' => $type, 'price' => $price, 'stock' => $stock, 'menu_id' => $menuId, 'id' => $id, 'tid' => $tid, 'bid' => $bid]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO `products` (`id`, `name`, `type`, `price`, `stock`, `menu_id`, `tenant_id`, `branch_id`) VALUES (:id, :name, :type, :price, :stock, :menu_id, :tenant_id, :branch_id)");
        $stmt->execute(['id' => $id, 'name' => $name, 'type' => $type, 'price' => $price, 'stock' => $stock, 'menu_id' => $menuId, 'tenant_id' => $tid, 'branch_id' => $bid]);
    }

    writeAuditLog($pdo, $authContext, 'product.save', 'product', $id);
    $pdo->commit();
    _invalidateCatalogCache($authContext);
    echo json_encode(["status" => "success"]);
}

function handle_delete_product(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'inventory');
    $id = catalogId($input['id'] ?? '');
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("DELETE FROM `products` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    $stmt->execute(['id' => $id, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('El producto ya no existe o no pertenece a esta sucursal.');
    writeAuditLog($pdo, $authContext, 'product.delete', 'product', $id);
    $pdo->commit();
    _invalidateCatalogCache($authContext);
    echo json_encode(["status" => "success"]);
}

function handle_save_menu(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'inventory');
    $id = catalogId($input['id'] ?? '');
    $name = catalogName($input['name'] ?? '');
    $startTime = catalogTime($input['start_time'] ?? null, 'hora de inicio');
    $endTime = catalogTime($input['end_time'] ?? null, 'hora de fin');
    $active = array_key_exists('active', $input) ? catalogBoolean($input['active'], 'estado') : 1;
    if (($startTime === null) !== ($endTime === null)) throw new InvalidArgumentException('Debe indicar ambas horas o ninguna.');

    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];

    $checkStmt = $pdo->prepare("SELECT id FROM `menus` WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
    $checkStmt->execute(['id' => $id, 'tid' => $tid, 'bid' => $bid]);
    $exists = $checkStmt->fetch();
    if (!$exists) {
        $globalId = $pdo->prepare("SELECT `id` FROM `menus` WHERE `id` = :id LIMIT 1");
        $globalId->execute(['id' => $id]);
        if ($globalId->fetch()) throw new InvalidArgumentException('El ID ya pertenece a otro menú.');
    }
    assertCatalogNameAvailable($pdo, 'menus', $name, $id, $authContext);
    $pdo->beginTransaction();

    if ($exists) {
        $stmt = $pdo->prepare("UPDATE `menus` SET `name` = :name, `start_time` = :start_time, `end_time` = :end_time, `active` = :active WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
        $stmt->execute(['name' => $name, 'start_time' => $startTime, 'end_time' => $endTime, 'active' => $active, 'id' => $id, 'tid' => $tid, 'bid' => $bid]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO `menus` (`id`, `name`, `start_time`, `end_time`, `active`, `tenant_id`, `branch_id`) VALUES (:id, :name, :start_time, :end_time, :active, :tenant_id, :branch_id)");
        $stmt->execute(['id' => $id, 'name' => $name, 'start_time' => $startTime, 'end_time' => $endTime, 'active' => $active, 'tenant_id' => $tid, 'branch_id' => $bid]);
    }

    writeAuditLog($pdo, $authContext, 'menu.save', 'menu', $id);
    $pdo->commit();
    _invalidateCatalogCache($authContext);
    echo json_encode(["status" => "success"]);
}

function handle_delete_menu(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'inventory');
    $id = catalogId($input['id'] ?? '');
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("UPDATE `products` SET `menu_id` = NULL WHERE `menu_id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    $stmt->execute(['id' => $id, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    $stmt = $pdo->prepare("DELETE FROM `menus` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    $stmt->execute(['id' => $id, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('El menú ya no existe o no pertenece a esta sucursal.');
    writeAuditLog($pdo, $authContext, 'menu.delete', 'menu', $id);
    $pdo->commit();
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
