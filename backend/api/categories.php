<?php
// Category action handlers

function handle_get_categories(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'inventory');
    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];

    $stmt = $pdo->prepare("SELECT * FROM `categories` WHERE `tenant_id` = :tid AND `branch_id` = :bid ORDER BY `sort_order` ASC, `name` ASC");
    $stmt->execute(['tid' => $tid, 'bid' => $bid]);
    $categories = $stmt->fetchAll();
    foreach ($categories as &$c) {
        $c['sort_order'] = (int)$c['sort_order'];
        $c['active'] = (bool)$c['active'];
    }

    echo json_encode(["status" => "success", "categories" => $categories]);
}

function handle_save_category(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'inventory');
    $id = catalogId($input['id'] ?? '');
    $name = catalogName($input['name'] ?? '');
    $type = catalogString($input['type'] ?? 'general', 'tipo');
    $sortOrder = array_key_exists('sort_order', $input) ? catalogInteger($input['sort_order'], 'orden', 0, 10000) : 0;
    $active = array_key_exists('active', $input) ? catalogBoolean($input['active'], 'estado') : 1;

    $validTypes = ['sopa', 'segundo', 'plato_extra', 'refresco', 'general'];
    if (!in_array($type, $validTypes, true)) throw new InvalidArgumentException('Tipo de categoría inválido.');

    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];
    $pdo->beginTransaction();

    $checkStmt = $pdo->prepare("SELECT id FROM `categories` WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
    $checkStmt->execute(['id' => $id, 'tid' => $tid, 'bid' => $bid]);
    $exists = $checkStmt->fetch();
    if (!$exists) {
        $globalId = $pdo->prepare("SELECT `id` FROM `categories` WHERE `id` = :id LIMIT 1");
        $globalId->execute(['id' => $id]);
        if ($globalId->fetch()) throw new InvalidArgumentException('El ID ya pertenece a otra categoría.');
    }
    assertCatalogNameAvailable($pdo, 'categories', $name, $id, $authContext);

    if ($exists) {
        $stmt = $pdo->prepare("UPDATE `categories` SET `name` = :name, `type` = :type, `sort_order` = :sort_order, `active` = :active WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
        $stmt->execute(['name' => $name, 'type' => $type, 'sort_order' => $sortOrder, 'active' => $active, 'id' => $id, 'tid' => $tid, 'bid' => $bid]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO `categories` (`id`, `tenant_id`, `branch_id`, `name`, `type`, `sort_order`, `active`) VALUES (:id, :tid, :bid, :name, :type, :sort_order, :active)");
        $stmt->execute(['id' => $id, 'tid' => $tid, 'bid' => $bid, 'name' => $name, 'type' => $type, 'sort_order' => $sortOrder, 'active' => $active]);
    }

    writeAuditLog($pdo, $authContext, 'category.save', 'category', $id);
    $pdo->commit();
    cacheInvalidateTenant('catalog', $tid, $bid);
    echo json_encode(["status" => "success"]);
}

function handle_delete_category(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'inventory');
    $id = catalogId($input['id'] ?? '');

    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("DELETE FROM `categories` WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
    $stmt->execute(['id' => $id, 'tid' => $tid, 'bid' => $bid]);
    if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('La categoría ya no existe o no pertenece a esta sucursal.');

    writeAuditLog($pdo, $authContext, 'category.delete', 'category', $id);
    $pdo->commit();
    cacheInvalidateTenant('catalog', $tid, $bid);
    echo json_encode(["status" => "success"]);
}

function handle_reorder_categories(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'inventory');
    $order = $input['order'] ?? [];
    if (!is_array($order)) {
        echo json_encode(["status" => "error", "message" => "Formato inválido"]);
        return;
    }
    if (count($order) > 10000) throw new InvalidArgumentException('La lista de categorías es demasiado extensa.');
    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];

    $ids = array_map(static fn($id) => catalogId($id), $order);
    if (count($ids) !== count(array_unique($ids))) throw new InvalidArgumentException('La lista contiene categorías repetidas.');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE `categories` SET `sort_order` = :sort_order WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
        $existsStmt = $pdo->prepare("SELECT 1 FROM `categories` WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
        foreach ($ids as $index => $id) {
            $existsStmt->execute(['id' => $id, 'tid' => $tid, 'bid' => $bid]);
            if (!$existsStmt->fetchColumn()) throw new InvalidArgumentException('Una categoría ya no existe o no pertenece a esta sucursal.');
            $stmt->execute(['sort_order' => $index + 1, 'id' => $id, 'tid' => $tid, 'bid' => $bid]);
        }
        writeAuditLog($pdo, $authContext, 'category.reorder', 'category_list');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    cacheInvalidateTenant('catalog', $tid, $bid);
    echo json_encode(["status" => "success"]);
}
