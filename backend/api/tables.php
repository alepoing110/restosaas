<?php
// Table configuration action handlers

function handle_save_table(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    if (empty($input['name'])) {
        echo json_encode(["status" => "error", "message" => "Nombre de la mesa es requerido"]);
        return;
    }
    $id = !empty($input['id']) ? $input['id'] : 'tbl_' . bin2hex(random_bytes(6));
    $name = trim($input['name']);
    $icon = !empty($input['icon']) ? $input['icon'] : 'fa-chair';
    $sortOrder = isset($input['sort_order']) ? (int)$input['sort_order'] : 0;
    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];
    $pdo->beginTransaction();

    $checkStmt = $pdo->prepare("SELECT id FROM `tables_config` WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
    $checkStmt->execute(['id' => $id, 'tid' => $tid, 'bid' => $bid]);
    $exists = $checkStmt->fetch();

    if ($exists) {
        $stmt = $pdo->prepare("UPDATE `tables_config` SET `name` = :name, `icon` = :icon, `sort_order` = :sort_order WHERE `id` = :id AND `tenant_id` = :tid AND `branch_id` = :bid");
        $stmt->execute(['name' => $name, 'icon' => $icon, 'sort_order' => $sortOrder, 'id' => $id, 'tid' => $tid, 'bid' => $bid]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO `tables_config` (`id`, `name`, `icon`, `sort_order`, `tenant_id`, `branch_id`) VALUES (:id, :name, :icon, :sort_order, :tenant_id, :branch_id)");
        $stmt->execute(['id' => $id, 'name' => $name, 'icon' => $icon, 'sort_order' => $sortOrder, 'tenant_id' => $tid, 'branch_id' => $bid]);
    }
    writeAuditLog($pdo, $authContext, 'table.save', 'table', $id);
    $pdo->commit();
    cacheInvalidateTenant('catalog', $authContext['tenant_id'], $authContext['branch_id']);
    echo json_encode(["status" => "success", "id" => $id]);
}

function handle_delete_table(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    $id = $input['id'] ?? '';
    if (empty($id)) {
        echo json_encode(["status" => "error", "message" => "ID requerido"]);
        return;
    }
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("DELETE FROM `tables_config` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    $stmt->execute(['id' => $id, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    writeAuditLog($pdo, $authContext, 'table.delete', 'table', $id);
    $pdo->commit();
    cacheInvalidateTenant('catalog', $authContext['tenant_id'], $authContext['branch_id']);
    echo json_encode(["status" => "success"]);
}

function handle_reorder_tables(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    $order = $input['order'] ?? [];
    if (!is_array($order)) {
        echo json_encode(["status" => "error", "message" => "Formato inválido"]);
        return;
    }
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("UPDATE `tables_config` SET `sort_order` = :sort_order WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
    foreach ($order as $index => $id) {
        $stmt->execute(['sort_order' => $index + 1, 'id' => $id, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    }
    writeAuditLog($pdo, $authContext, 'table.reorder', 'table_list');
    $pdo->commit();
    cacheInvalidateTenant('catalog', $authContext['tenant_id'], $authContext['branch_id']);
    echo json_encode(["status" => "success"]);
}
