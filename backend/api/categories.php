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
    $id = $input['id'] ?? '';
    $name = trim($input['name'] ?? '');
    $type = $input['type'] ?? 'general';
    $sortOrder = isset($input['sort_order']) ? (int)$input['sort_order'] : 0;
    $active = isset($input['active']) ? (int)$input['active'] : 1;

    if (empty($id) || empty($name)) {
        echo json_encode(["status" => "error", "message" => "Nombre e ID son requeridos"]);
        return;
    }

    $validTypes = ['sopa', 'segundo', 'plato_extra', 'refresco', 'general'];
    if (!in_array($type, $validTypes, true)) $type = 'general';

    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];

    $checkStmt = $pdo->prepare("SELECT id FROM `categories` WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
    $checkStmt->execute(['id' => $id, 'tid' => $tid, 'bid' => $bid]);
    $exists = $checkStmt->fetch();

    if ($exists) {
        $stmt = $pdo->prepare("UPDATE `categories` SET `name` = :name, `type` = :type, `sort_order` = :sort_order, `active` = :active WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
        $stmt->execute(['name' => $name, 'type' => $type, 'sort_order' => $sortOrder, 'active' => $active, 'id' => $id, 'tid' => $tid, 'bid' => $bid]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO `categories` (`id`, `tenant_id`, `branch_id`, `name`, `type`, `sort_order`, `active`) VALUES (:id, :tid, :bid, :name, :type, :sort_order, :active)");
        $stmt->execute(['id' => $id, 'tid' => $tid, 'bid' => $bid, 'name' => $name, 'type' => $type, 'sort_order' => $sortOrder, 'active' => $active]);
    }

    writeAuditLog($pdo, $authContext, 'category.save', 'category', $id);
    cacheInvalidateTenant('catalog', $tid, $bid);
    echo json_encode(["status" => "success"]);
}

function handle_delete_category(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'inventory');
    $id = $input['id'] ?? '';
    if (empty($id)) {
        echo json_encode(["status" => "error", "message" => "ID requerido"]);
        return;
    }

    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];

    $stmt = $pdo->prepare("DELETE FROM `categories` WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
    $stmt->execute(['id' => $id, 'tid' => $tid, 'bid' => $bid]);

    writeAuditLog($pdo, $authContext, 'category.delete', 'category', $id);
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
    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];

    $stmt = $pdo->prepare("UPDATE `categories` SET `sort_order` = :sort_order WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
    foreach ($order as $index => $id) {
        $stmt->execute(['sort_order' => $index + 1, 'id' => $id, 'tid' => $tid, 'bid' => $bid]);
    }

    writeAuditLog($pdo, $authContext, 'category.reorder', 'category_list');
    cacheInvalidateTenant('catalog', $tid, $bid);
    echo json_encode(["status" => "success"]);
}
