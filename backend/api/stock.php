<?php
// Stock history and reporting action handlers

function handle_get_stock_history(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'reports');
    $itemType = $input['item_type'] ?? null;
    $itemId = $input['item_id'] ?? null;
    $startDate = $input['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
    $endDate = $input['end_date'] ?? date('Y-m-d');

    $params = [
        'tid' => $authContext['tenant_id'],
        'bid' => $authContext['branch_id'],
        'start' => $startDate . ' 00:00:00',
        'end' => $endDate . ' 23:59:59',
    ];

    $sql = "SELECT * FROM `stock_history` WHERE `tenant_id` = :tid AND `branch_id` = :bid AND `created_at` BETWEEN :start AND :end";
    if ($itemType) { $sql .= " AND `item_type` = :item_type"; $params['item_type'] = $itemType; }
    if ($itemId) { $sql .= " AND `item_id` = :item_id"; $params['item_id'] = $itemId; }
    $sql .= " ORDER BY `created_at` DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(["status" => "success", "history" => $history]);
}

function handle_get_stock_report(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'reports');
    $startDate = $input['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
    $endDate = $input['end_date'] ?? date('Y-m-d');

    $params = [
        'tid' => $authContext['tenant_id'],
        'bid' => $authContext['branch_id'],
        'start' => $startDate,
        'end' => $endDate,
    ];

    $sql = "SELECT * FROM `stock_daily_snapshot` WHERE `tenant_id` = :tid AND `branch_id` = :bid AND `snapshot_date` BETWEEN :start AND :end ORDER BY `snapshot_date` ASC, `item_type` ASC, `item_name` ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $snapshots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byItem = [];
    foreach ($snapshots as $snap) {
        $key = $snap['item_type'] . ':' . $snap['item_id'];
        if (!isset($byItem[$key])) {
            $byItem[$key] = [
                'item_type' => $snap['item_type'],
                'item_id' => $snap['item_id'],
                'item_name' => $snap['item_name'],
                'daily' => [],
                'total_sold' => 0,
            ];
        }
        $byItem[$key]['daily'][] = [
            'date' => $snap['snapshot_date'],
            'opening' => (int)$snap['opening_stock'],
            'closing' => (int)$snap['closing_stock'],
            'sold' => (int)$snap['sold_count'],
        ];
        $byItem[$key]['total_sold'] += (int)$snap['sold_count'];
    }

    usort($byItem, fn($a, $b) => $b['total_sold'] <=> $a['total_sold']);

    $catalog = loadCatalogState($pdo, $authContext);

    echo json_encode([
        "status" => "success",
        "reportContext" => buildReportContext($authContext, $catalog['business'] ?? [], [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'label' => 'Reporte de stock'
        ]),
        "snapshots" => $snapshots,
        "by_item" => array_values($byItem),
        "period" => ["start" => $startDate, "end" => $endDate]
    ]);
}
