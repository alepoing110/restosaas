<?php
// Export & Import action handlers

function handle_export_tenant_data(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];

    $export = [
        'exported_at' => date('c'),
        'tenant_id' => $tid,
        'branch_id' => $bid,
    ];

    $tables = ['config_precios', 'config_general', 'segundos', 'sopas', 'platos_extras', 'gaseosas', 'menus', 'products', 'tables_config', 'categories'];
    foreach ($tables as $tbl) {
        $stmt = $pdo->prepare("SELECT * FROM `$tbl` WHERE `tenant_id` = :tid AND `branch_id` = :bid");
        $stmt->execute(['tid' => $tid, 'bid' => $bid]);
        $export[$tbl] = $stmt->fetchAll();
    }

    $stmtOrders = $pdo->prepare("SELECT * FROM `pedidos` WHERE `tenant_id` = :tid AND `branch_id` = :bid AND `timestamp` >= DATE_SUB(NOW(), INTERVAL 90 DAY) ORDER BY `timestamp` DESC LIMIT 5000");
    $stmtOrders->execute(['tid' => $tid, 'bid' => $bid]);
    $export['pedidos_recent'] = $stmtOrders->fetchAll();

    $stmtCierres = $pdo->prepare("SELECT * FROM `caja_cierres_historico` WHERE `tenant_id` = :tid AND `branch_id` = :bid ORDER BY `timestamp` DESC LIMIT 500");
    $stmtCierres->execute(['tid' => $tid, 'bid' => $bid]);
    $export['caja_cierres_historico'] = $stmtCierres->fetchAll();

    $json = json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (strlen($json) > 10485760) {
        $json = json_encode($export, JSON_UNESCAPED_UNICODE);
    }

    $safeTid = preg_replace('/[^a-zA-Z0-9_-]/', '', $tid);
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="restocloud-backup-' . $safeTid . '-' . date('Y-m-d') . '.json"');
    echo $json;
}

function handle_import_tenant_data(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'settings');
    $tid = $authContext['tenant_id'];
    $bid = $authContext['branch_id'];

    $data = $input['data'] ?? null;
    if (!$data || !is_array($data)) {
        echo json_encode(["status" => "error", "message" => "Datos de importación inválidos"]);
        return;
    }

    $confirm = $input['confirm'] ?? false;
    if (!$confirm) {
        $summary = [];
        $tables = ['config_precios', 'config_general', 'segundos', 'sopas', 'platos_extras', 'gaseosas', 'menus', 'products', 'tables_config', 'categories'];
        foreach ($tables as $tbl) {
            $summary[$tbl] = count($data[$tbl] ?? []);
        }
        $summary['pedidos'] = count($data['pedidos_recent'] ?? []);
        echo json_encode(["status" => "confirm", "message" => "Se importarán los siguientes registros", "summary" => $summary]);
        return;
    }

    $pdo->beginTransaction();
    try {
        $tableMap = [
            'config_precios' => 'config_precios',
            'config_general' => 'config_general',
            'segundos' => 'segundos',
            'sopas' => 'sopas',
            'platos_extras' => 'platos_extras',
            'gaseosas' => 'gaseosas',
            'menus' => 'menus',
            'products' => 'products',
            'tables_config' => 'tables_config',
            'categories' => 'categories',
        ];

        foreach ($tableMap as $key => $table) {
            $rows = $data[$key] ?? [];
            if (empty($rows)) continue;

            $stmt = $pdo->prepare("DELETE FROM `$table` WHERE `tenant_id` = :tid AND `branch_id` = :bid");
            $stmt->execute(['tid' => $tid, 'bid' => $bid]);

            foreach ($rows as $row) {
                $row['tenant_id'] = $tid;
                $row['branch_id'] = $bid;
                $columns = array_keys($row);
                $safeColumns = array_filter($columns, fn($c) => preg_match('/^[a-zA-Z0-9_]+$/', $c));
                $safeColumns = array_values($safeColumns);
                $placeholders = array_map(fn($c) => ":$c", $safeColumns);
                $filteredRow = array_intersect_key($row, array_flip($safeColumns));
                $sql = "INSERT INTO `$table` (`" . implode('`, `', $safeColumns) . "`) VALUES (" . implode(', ', $placeholders) . ")";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($filteredRow);
            }
        }

        $pdo->commit();
        cacheInvalidateTenant('catalog', $tid, $bid);
        writeAuditLog($pdo, $authContext, 'data.import', 'tenant', $tid);
        echo json_encode(["status" => "success", "message" => "Datos importados correctamente"]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
