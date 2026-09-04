<?php
// Customers and accounts receivable handlers.

function normalizeCustomerPhone(string $phone): ?string {
    $normalized = preg_replace('/\D+/', '', $phone);
    return $normalized !== '' ? $normalized : null;
}

function findTenantCustomer(PDO $pdo, array $authContext, string $customerId): ?array {
    if ($customerId === '') return null;
    $stmt = $pdo->prepare("SELECT * FROM `clientes` WHERE id = :id AND tenant_id = :tenant_id AND active = 1 LIMIT 1");
    $stmt->execute(['id' => $customerId, 'tenant_id' => $authContext['tenant_id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function handle_get_customers(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'financial_reports');
    $search = trim((string)($input['search'] ?? ''));
    $sql = "SELECT c.id, c.name, c.phone, c.whatsapp_phone, c.email, c.address, c.credit_limit, c.source, c.notes, c.marketing_opt_in, c.last_seen_at, c.created_at,
        (SELECT COUNT(*) FROM `pedidos` p WHERE p.tenant_id = c.tenant_id AND p.customer_id = c.id AND p.status <> 'anulado') AS order_count,
        (SELECT COALESCE(SUM(p.total), 0) FROM `pedidos` p WHERE p.tenant_id = c.tenant_id AND p.customer_id = c.id AND p.status <> 'anulado') AS total_spent,
        (SELECT MAX(COALESCE(p.sold_at, p.timestamp)) FROM `pedidos` p WHERE p.tenant_id = c.tenant_id AND p.customer_id = c.id AND p.status <> 'anulado') AS last_order_at,
        (SELECT COALESCE(SUM(cxc.balance), 0) FROM `cuentas_por_cobrar` cxc WHERE cxc.tenant_id = c.tenant_id AND cxc.customer_id = c.id AND cxc.status IN ('pendiente', 'vencida')) AS receivable_balance
        FROM `clientes` c WHERE c.tenant_id = :tenant_id AND c.active = 1";
    $params = ['tenant_id' => $authContext['tenant_id']];
    if ($search !== '') { $sql .= " AND (c.name LIKE :search OR c.phone LIKE :search OR c.whatsapp_phone LIKE :search)"; $params['search'] = '%' . $search . '%'; }
    $sql .= " ORDER BY name ASC LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($customers as &$customer) {
        $customer['credit_limit'] = (float)$customer['credit_limit'];
        $customer['total_spent'] = (float)$customer['total_spent'];
        $customer['receivable_balance'] = (float)$customer['receivable_balance'];
        $customer['order_count'] = (int)$customer['order_count'];
    }
    unset($customer);
    echo json_encode(['status' => 'success', 'customers' => $customers]);
}

function handle_save_customer(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'financial_reports');
    $id = trim((string)($input['id'] ?? ''));
    $name = trim((string)($input['name'] ?? ''));
    $creditLimit = is_numeric($input['credit_limit'] ?? null) ? (float)$input['credit_limit'] : 0.0;
    if ($name === '' || strlen($name) > 150 || $creditLimit < 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Datos del cliente inválidos']);
        return;
    }
    $phone = trim((string)($input['phone'] ?? ''));
    $normalizedPhone = normalizeCustomerPhone($phone);
    if ($id !== '' && !findTenantCustomer($pdo, $authContext, $id)) throw new InvalidArgumentException('Cliente no encontrado.');
    if ($id === '' && $normalizedPhone) {
        $existing = $pdo->prepare("SELECT id FROM `clientes` WHERE tenant_id = :tenant_id AND phone_normalized = :phone AND active = 1 LIMIT 1");
        $existing->execute(['tenant_id' => $authContext['tenant_id'], 'phone' => $normalizedPhone]);
        $id = (string)($existing->fetchColumn() ?: '');
    }
    $id = $id ?: 'cli_' . bin2hex(random_bytes(10));
    $stmt = $pdo->prepare("INSERT INTO `clientes` (id, name, phone, phone_normalized, whatsapp_phone, email, address, credit_limit, source, notes, marketing_opt_in, tenant_id, branch_id, last_seen_at, updated_at) VALUES (:id, :name, :phone, :phone_normalized, :whatsapp_phone, :email, :address, :credit_limit, :source, :notes, :marketing_opt_in, :tenant_id, :branch_id, NOW(), NOW()) ON DUPLICATE KEY UPDATE name = VALUES(name), phone = VALUES(phone), phone_normalized = VALUES(phone_normalized), whatsapp_phone = VALUES(whatsapp_phone), email = VALUES(email), address = VALUES(address), credit_limit = VALUES(credit_limit), source = VALUES(source), notes = VALUES(notes), marketing_opt_in = VALUES(marketing_opt_in), active = 1, updated_at = NOW()");
    $stmt->execute(['id' => $id, 'name' => $name, 'phone' => $phone ?: null, 'phone_normalized' => $normalizedPhone, 'whatsapp_phone' => trim((string)($input['whatsapp_phone'] ?? '')) ?: ($phone ?: null), 'email' => trim((string)($input['email'] ?? '')) ?: null, 'address' => trim((string)($input['address'] ?? '')) ?: null, 'credit_limit' => $creditLimit, 'source' => trim((string)($input['source'] ?? 'manual')) ?: 'manual', 'notes' => trim((string)($input['notes'] ?? '')) ?: null, 'marketing_opt_in' => !empty($input['marketing_opt_in']) ? 1 : 0, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    writeAuditLog($pdo, $authContext, 'customer.save', 'cliente', $id);
    echo json_encode(['status' => 'success', 'id' => $id]);
}

function handle_get_customer_profile(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'financial_reports');
    $customer = findTenantCustomer($pdo, $authContext, trim((string)($input['id'] ?? '')));
    if (!$customer) throw new InvalidArgumentException('Cliente no encontrado.');
    $scope = ['tenant_id' => $authContext['tenant_id'], 'customer_id' => $customer['id']];
    $orders = $pdo->prepare("SELECT id, customer, total, status, delivery_type, payment_method, paid, COALESCE(sold_at, timestamp) AS occurred_at FROM `pedidos` WHERE tenant_id = :tenant_id AND customer_id = :customer_id ORDER BY COALESCE(sold_at, timestamp) DESC LIMIT 20");
    $orders->execute($scope);
    $reservations = $pdo->prepare("SELECT id, reservation_date, reservation_time, delivery_type, status, total, source FROM `reservations` WHERE tenant_id = :tenant_id AND customer_id = :customer_id ORDER BY reservation_date DESC, reservation_time DESC LIMIT 20");
    $reservations->execute($scope);
    $receivables = $pdo->prepare("SELECT id, original_amount, balance, due_date, status, branch_id, created_at FROM `cuentas_por_cobrar` WHERE tenant_id = :tenant_id AND customer_id = :customer_id ORDER BY due_date DESC LIMIT 20");
    $receivables->execute($scope);
    $conversations = $pdo->prepare("SELECT id, wa_phone, customer_name, status, updated_at FROM `chatbot_conversations` WHERE tenant_id = :tenant_id AND customer_id = :customer_id ORDER BY updated_at DESC LIMIT 10");
    $conversations->execute($scope);
    $interactions = $pdo->prepare("SELECT id, type, content, occurred_at FROM `customer_interactions` WHERE tenant_id = :tenant_id AND customer_id = :customer_id ORDER BY occurred_at DESC LIMIT 30");
    $interactions->execute($scope);
    $tasks = $pdo->prepare("SELECT id, receivable_id, status, due_date, note, completed_at, created_at FROM `collection_tasks` WHERE tenant_id = :tenant_id AND customer_id = :customer_id ORDER BY status = 'pendiente' DESC, due_date ASC, created_at DESC LIMIT 30");
    $tasks->execute($scope);
    $summary = $pdo->prepare("SELECT COUNT(*) AS order_count, COALESCE(SUM(total), 0) AS total_spent, MAX(COALESCE(sold_at, timestamp)) AS last_order_at FROM `pedidos` WHERE tenant_id = :tenant_id AND customer_id = :customer_id AND status <> 'anulado'");
    $summary->execute($scope);
    $data = $summary->fetch(PDO::FETCH_ASSOC) ?: [];
    $balance = $pdo->prepare("SELECT COALESCE(SUM(balance), 0) FROM `cuentas_por_cobrar` WHERE tenant_id = :tenant_id AND customer_id = :customer_id AND status IN ('pendiente', 'vencida')");
    $balance->execute($scope);
    $data['total_spent'] = (float)($data['total_spent'] ?? 0);
    $data['order_count'] = (int)($data['order_count'] ?? 0);
    $data['receivable_balance'] = (float)$balance->fetchColumn();
    echo json_encode(['status' => 'success', 'customer' => $customer, 'summary' => $data, 'orders' => $orders->fetchAll(PDO::FETCH_ASSOC), 'reservations' => $reservations->fetchAll(PDO::FETCH_ASSOC), 'receivables' => $receivables->fetchAll(PDO::FETCH_ASSOC), 'conversations' => $conversations->fetchAll(PDO::FETCH_ASSOC), 'interactions' => $interactions->fetchAll(PDO::FETCH_ASSOC), 'collection_tasks' => $tasks->fetchAll(PDO::FETCH_ASSOC)]);
}

function handle_save_customer_interaction(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'financial_reports');
    $customerId = trim((string)($input['customer_id'] ?? ''));
    $type = trim((string)($input['type'] ?? 'nota'));
    $content = trim((string)($input['content'] ?? ''));
    if (!findTenantCustomer($pdo, $authContext, $customerId) || !in_array($type, ['nota', 'llamada', 'whatsapp', 'cobranza'], true) || $content === '' || mb_strlen($content) > 2000) {
        throw new InvalidArgumentException('Los datos de la interacción son inválidos.');
    }
    $id = 'cint_' . bin2hex(random_bytes(10));
    $stmt = $pdo->prepare("INSERT INTO `customer_interactions` (`id`, `customer_id`, `type`, `content`, `occurred_at`, `created_by`, `tenant_id`, `branch_id`) VALUES (:id, :customer_id, :type, :content, NOW(), :created_by, :tenant_id, :branch_id)");
    $stmt->execute(['id' => $id, 'customer_id' => $customerId, 'type' => $type, 'content' => $content, 'created_by' => $authContext['user_id'], 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    writeAuditLog($pdo, $authContext, 'customer.interaction.save', 'cliente', $customerId, ['interaction_id' => $id, 'type' => $type]);
    echo json_encode(['status' => 'success', 'id' => $id]);
}

function handle_save_collection_task(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'financial_reports');
    $id = trim((string)($input['id'] ?? '')) ?: 'ctask_' . bin2hex(random_bytes(10));
    $customerId = trim((string)($input['customer_id'] ?? ''));
    $receivableId = trim((string)($input['receivable_id'] ?? ''));
    $status = trim((string)($input['status'] ?? 'pendiente'));
    $dueDate = trim((string)($input['due_date'] ?? ''));
    $note = trim((string)($input['note'] ?? ''));
    if (!findTenantCustomer($pdo, $authContext, $customerId) || !in_array($status, ['pendiente', 'en_progreso', 'completada', 'cancelada'], true) || $note === '' || mb_strlen($note) > 2000 || ($dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate))) {
        throw new InvalidArgumentException('Los datos de la tarea de cobranza son inválidos.');
    }
    if ($receivableId !== '') {
        $receivable = $pdo->prepare("SELECT id FROM `cuentas_por_cobrar` WHERE id = :id AND customer_id = :customer_id AND tenant_id = :tenant_id LIMIT 1");
        $receivable->execute(['id' => $receivableId, 'customer_id' => $customerId, 'tenant_id' => $authContext['tenant_id']]);
        if (!$receivable->fetchColumn()) throw new InvalidArgumentException('La cuenta por cobrar no corresponde al cliente.');
    }
    $stmt = $pdo->prepare("INSERT INTO `collection_tasks` (`id`, `customer_id`, `receivable_id`, `status`, `due_date`, `note`, `completed_at`, `created_by`, `tenant_id`, `branch_id`, `updated_at`) VALUES (:id, :customer_id, :receivable_id, :status, :due_date, :note, CASE WHEN :status = 'completada' THEN NOW() ELSE NULL END, :created_by, :tenant_id, :branch_id, NOW()) ON DUPLICATE KEY UPDATE `status` = VALUES(`status`), `due_date` = VALUES(`due_date`), `note` = VALUES(`note`), `completed_at` = CASE WHEN VALUES(`status`) = 'completada' THEN COALESCE(`completed_at`, NOW()) ELSE NULL END, `updated_at` = NOW()");
    $stmt->execute(['id' => $id, 'customer_id' => $customerId, 'receivable_id' => $receivableId ?: null, 'status' => $status, 'due_date' => $dueDate ?: null, 'note' => $note, 'created_by' => $authContext['user_id'], 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
    writeAuditLog($pdo, $authContext, 'customer.collection_task.save', 'cliente', $customerId, ['task_id' => $id, 'status' => $status]);
    echo json_encode(['status' => 'success', 'id' => $id]);
}

function receivableBranchScope(PDO $pdo, array $authContext, ?string $branchId = null): array {
    [$branchIds, $branches] = financialBranchScope($pdo, $authContext, $branchId ?? '');
    return [$branchIds, $branches];
}

function handle_get_receivables(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'financial_reports');
    [$branchIds] = receivableBranchScope($pdo, $authContext, isset($input['branch_id']) ? (string)$input['branch_id'] : '');
    $updateParams = [':tenant_id' => $authContext['tenant_id'], ':today' => date('Y-m-d')];
    $updateBranchSql = financialScopeSql($branchIds, $updateParams, 'rxu');
    $pdo->prepare("UPDATE `cuentas_por_cobrar` SET status = 'vencida' WHERE tenant_id = :tenant_id AND branch_id IN ($updateBranchSql) AND balance > 0 AND status = 'pendiente' AND due_date < :today")->execute($updateParams);
    $params = [':tenant_id' => $authContext['tenant_id']];
    $branchSql = financialScopeSql($branchIds, $params, 'rx');
    $sql = "SELECT cxc.id, cxc.order_id, cxc.customer_id, c.name AS customer_name, c.phone, cxc.original_amount, cxc.balance, cxc.due_date, cxc.status, cxc.created_at, cxc.branch_id FROM `cuentas_por_cobrar` cxc INNER JOIN `clientes` c ON c.id = cxc.customer_id AND c.tenant_id = cxc.tenant_id WHERE cxc.tenant_id = :tenant_id AND cxc.branch_id IN ($branchSql)";
    $status = trim((string)($input['status'] ?? ''));
    if (in_array($status, ['pendiente', 'vencida', 'pagada', 'cancelada'], true)) { $sql .= " AND cxc.status = :status"; $params[':status'] = $status; }
    $search = trim((string)($input['search'] ?? ''));
    if ($search !== '') { $sql .= " AND (c.name LIKE :search OR cxc.order_id LIKE :search)"; $params[':search'] = '%' . $search . '%'; }
    $sql .= " ORDER BY cxc.status = 'vencida' DESC, cxc.due_date ASC, c.name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $summary = ['original_amount' => 0.0, 'balance' => 0.0, 'count' => count($rows)];
    foreach ($rows as &$row) { $row['original_amount'] = (float)$row['original_amount']; $row['balance'] = (float)$row['balance']; $summary['original_amount'] += $row['original_amount']; $summary['balance'] += $row['balance']; }
    unset($row);
    $summary['original_amount'] = round($summary['original_amount'], 2);
    $summary['balance'] = round($summary['balance'], 2);
    echo json_encode(['status' => 'success', 'summary' => $summary, 'receivables' => $rows]);
}

function handle_save_receivable_payment(PDO $pdo, ?array $authContext, array $input): void {
    requirePermission($authContext, 'financial_reports');
    $id = trim((string)($input['receivable_id'] ?? ''));
    $amount = is_numeric($input['amount'] ?? null) ? round((float)$input['amount'], 2) : 0.0;
    $method = trim((string)($input['payment_method'] ?? 'efectivo'));
    if ($id === '' || $amount <= 0 || !in_array($method, ['efectivo', 'qr', 'tarjeta', 'transferencia', 'otro'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Datos del cobro inválidos']);
        return;
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT cxc.*, p.total AS order_total FROM `cuentas_por_cobrar` cxc INNER JOIN `pedidos` p ON p.id = cxc.order_id AND p.tenant_id = cxc.tenant_id AND p.branch_id = cxc.branch_id WHERE cxc.id = :id AND cxc.tenant_id = :tenant_id AND cxc.branch_id = :branch_id FOR UPDATE");
        $stmt->execute(['id' => $id, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
        $receivable = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$receivable || (float)$receivable['balance'] <= 0) throw new InvalidArgumentException('La cuenta no tiene saldo pendiente');
        if ($amount > (float)$receivable['balance'] + 0.01) throw new InvalidArgumentException('El cobro supera el saldo pendiente');
        $newBalance = max(0, round((float)$receivable['balance'] - $amount, 2));
        $paymentId = 'rcp_' . bin2hex(random_bytes(10));
        $paymentStmt = $pdo->prepare("INSERT INTO `cobros_cuentas_por_cobrar` (id, receivable_id, amount, payment_method, reference, paid_at, created_by, tenant_id, branch_id) VALUES (:id, :receivable_id, :amount, :payment_method, :reference, NOW(), :created_by, :tenant_id, :branch_id)");
        $paymentStmt->execute(['id' => $paymentId, 'receivable_id' => $id, 'amount' => $amount, 'payment_method' => $method, 'reference' => trim((string)($input['reference'] ?? '')) ?: null, 'created_by' => $authContext['user_id'], 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
        $status = $newBalance <= 0 ? 'pagada' : ((string)$receivable['due_date'] < date('Y-m-d') ? 'vencida' : 'pendiente');
        $updateStmt = $pdo->prepare("UPDATE `cuentas_por_cobrar` SET balance = :balance, status = :status, closed_at = CASE WHEN :status = 'pagada' THEN NOW() ELSE closed_at END WHERE id = :id AND tenant_id = :tenant_id AND branch_id = :branch_id");
        $updateStmt->execute(['balance' => $newBalance, 'status' => $status, 'id' => $id, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
        $existingPayment = $pdo->prepare("SELECT id FROM `pedido_pagos` WHERE order_id = :order_id AND tenant_id = :tenant_id AND branch_id = :branch_id AND payment_method = :payment_method LIMIT 1");
        $existingPayment->execute(['order_id' => $receivable['order_id'], 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id'], 'payment_method' => $method]);
        $paymentRowId = $existingPayment->fetchColumn();
        if ($paymentRowId) {
            $pdo->prepare("UPDATE `pedido_pagos` SET amount = amount + :amount, raw_payment_method = :raw WHERE id = :id")->execute(['amount' => $amount, 'raw' => $method, 'id' => $paymentRowId]);
        } else {
            $pdo->prepare("INSERT INTO `pedido_pagos` (id, order_id, payment_method, amount, raw_payment_method, tenant_id, branch_id) VALUES (:id, :order_id, :payment_method, :amount, :raw, :tenant_id, :branch_id)")->execute(['id' => 'ppay_' . bin2hex(random_bytes(10)), 'order_id' => $receivable['order_id'], 'payment_method' => $method, 'amount' => $amount, 'raw' => $method, 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
        }
        if ($newBalance <= 0) $pdo->prepare("UPDATE `pedidos` SET paid = 1 WHERE id = :id AND tenant_id = :tenant_id AND branch_id = :branch_id")->execute(['id' => $receivable['order_id'], 'tenant_id' => $authContext['tenant_id'], 'branch_id' => $authContext['branch_id']]);
        $pdo->commit();
        writeAuditLog($pdo, $authContext, 'receivable.payment.save', 'cobro_cuenta', $paymentId, ['receivable_id' => $id, 'amount' => $amount, 'balance' => $newBalance]);
        echo json_encode(['status' => 'success', 'payment_id' => $paymentId, 'balance' => $newBalance, 'receivable_status' => $status]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
        echo json_encode(['status' => 'error', 'message' => $e instanceof InvalidArgumentException ? $e->getMessage() : 'No se pudo registrar el cobro']);
    }
}
