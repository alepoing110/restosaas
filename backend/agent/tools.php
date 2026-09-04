<?php

function chatbot_tool_definitions(): array
{
    return [
        [
            'type' => 'function',
            'function' => [
                'name' => 'list_menu',
                'description' => 'Muestra la carta/menú disponible del restaurante con precios. Puede filtrar por categoría: almuerzo (sopas y segundos — el cliente elige una de cada para su almuerzo), extras (platos extra y bebidas), o todo el menú.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'category' => [
                            'type' => 'string',
                            'enum' => ['almuerzo', 'extras', 'todo'],
                            'description' => 'Categoría del menú a mostrar. almuerzo=sopas+segundos, extras=platos_extra+refresco, todo=todo el menú.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'check_availability',
                'description' => 'Verifica disponibilidad de mesa para una fecha, hora y cantidad de personas.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string', 'description' => 'Fecha en formato YYYY-MM-DD'],
                        'time' => ['type' => 'string', 'description' => 'Hora en formato HH:MM'],
                        'party_size' => ['type' => 'integer', 'description' => 'Cantidad de personas'],
                    ],
                    'required' => ['date', 'time', 'party_size'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'recommend_time',
                'description' => 'Sugiere horarios recomendados según disponibilidad.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string', 'description' => 'Fecha en formato YYYY-MM-DD'],
                        'party_size' => ['type' => 'integer', 'description' => 'Cantidad de personas'],
                    ],
                    'required' => ['date', 'party_size'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'create_reservation',
                'description' => 'Crea una reserva cuando ya están definidos todos los datos: nombre, fecha, hora, personas y platos elegidos.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_name' => ['type' => 'string', 'description' => 'Nombre del cliente'],
                        'phone' => ['type' => 'string', 'description' => 'Teléfono del cliente'],
                        'party_size' => ['type' => 'integer', 'description' => 'Cantidad de personas'],
                        'reservation_date' => ['type' => 'string', 'description' => 'Fecha en formato YYYY-MM-DD'],
                        'reservation_time' => ['type' => 'string', 'description' => 'Hora en formato HH:MM'],
                        'delivery_type' => ['type' => 'string', 'enum' => ['para_servirse', 'para_llevar'], 'description' => 'Si es para servirse en mesa o para llevar'],
                        'items' => [
                            'type' => 'array',
                            'description' => 'Lista de platos/items pedidos',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'product_id' => ['type' => 'string'],
                                    'name' => ['type' => 'string'],
                                    'quantity' => ['type' => 'integer'],
                                    'price' => ['type' => 'number'],
                                ],
                            ],
                        ],
                        'notes' => ['type' => 'string', 'description' => 'Notas especiales: dirección de delivery, referencia, salsas y división de salsas'],
                    ],
                    'required' => ['customer_name', 'phone', 'party_size', 'reservation_date', 'reservation_time', 'delivery_type', 'items'],
                ],
            ],
        ],
    ];
}

function chatbot_execute_tool(PDO $pdo, array $businessContext, string $conversationId, string $toolName, array $arguments): array
{
    switch ($toolName) {
        case 'list_menu':
            return chatbot_get_menu($pdo, $businessContext, (string)($arguments['category'] ?? 'todo'));

        case 'check_availability':
            $table = chatbot_recommend_table(
                $pdo,
                $businessContext,
                (string)($arguments['date'] ?? ''),
                (string)($arguments['time'] ?? ''),
                max(1, (int)($arguments['party_size'] ?? 1))
            );
            return [
                'available' => (bool)$table,
                'table' => $table,
            ];

        case 'recommend_time':
            return [
                'recommendations' => chatbot_recommend_times(
                    $pdo,
                    $businessContext,
                    (string)($arguments['date'] ?? date('Y-m-d')),
                    max(1, (int)($arguments['party_size'] ?? 1))
                ),
            ];

        case 'create_reservation':
            $reservation = chatbot_create_reservation($pdo, $businessContext, $conversationId, $arguments);
            return [
                'reservation' => $reservation,
            ];
    }

    return ['error' => 'Tool no soportada'];
}

function chatbot_get_menu(PDO $pdo, array $businessContext, string $category = 'todo'): array
{
    $catalog = loadCatalogState($pdo, $businessContext);
    $menu = [];

    $almuerzoTypes = ['sopa', 'segundo'];
    $extrasTypes = ['plato_extra', 'refresco'];

    $activeMenuIds = [];
    foreach (($catalog['menus'] ?? []) as $m) {
        if (!empty($m['active'])) {
            $activeMenuIds[] = $m['id'];
        }
    }

    $configPrices = $catalog['prices'] ?? [];
    $almuerzoPrice = (float)($configPrices['almuerzo'] ?? 0);
    $products = $catalog['products'] ?? [];

    foreach ($products as $product) {
        $type = $product['type'] ?? '';
        $price = (float)($product['price'] ?? 0);
        if ($price <= 0) {
            $price = (float)($configPrices[$type] ?? 0);
        }
        if ($price <= 0) continue;

        if (!empty($product['menu_id']) && !in_array($product['menu_id'], $activeMenuIds, true)) continue;

        if ($category === 'almuerzo' && !in_array($type, $almuerzoTypes, true)) continue;
        if ($category === 'extras' && !in_array($type, $extrasTypes, true)) continue;

        $menu[] = [
            'id' => $product['id'],
            'name' => $product['name'],
            'type' => $type,
            'price' => $price,
            'stock' => (int)($product['stock'] ?? 0),
            'accepts_salsa' => (bool)($product['accepts_salsa'] ?? false),
        ];
    }

    $salsas = [];
    try {
        $stmt = $pdo->prepare("SELECT `id`, `name`, `price` FROM `salsas` WHERE `active` = 1 AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id ORDER BY `name`");
        $stmt->execute(['tenant_id' => $businessContext['tenant_id'], 'branch_id' => $businessContext['branch_id']]);
        $salsas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[chatbot] salsas query failed: ' . $e->getMessage());
    }

    $result = ['items' => $menu, 'category' => $category, 'salsas' => $salsas];
    if ($category === 'almuerzo' && $almuerzoPrice > 0) {
        $result['combo_price'] = $almuerzoPrice;
        $result['combo_note'] = 'El almuerzo completo (una sopa + un segundo) cuesta un precio fijo de Bs ' . number_format($almuerzoPrice, 2) . '. NO se suman los precios individuales.';
    }

    if ($category === 'almuerzo') {
        $sopas = array_filter($menu, fn($i) => $i['type'] === 'sopa' && (int)$i['stock'] > 0);
        $segundos = array_filter($menu, fn($i) => $i['type'] === 'segundo' && (int)$i['stock'] > 0);
        $result['auto_select'] = [];
        if (count($sopas) === 1) {
            $s = reset($sopas);
            $result['auto_select']['sopa'] = $s;
        }
        if (count($segundos) === 1) {
            $s = reset($segundos);
            $result['auto_select']['segundo'] = $s;
        }
    }

    return $result;
}

function chatbot_create_reservation(PDO $pdo, array $businessContext, string $conversationId, array $arguments): array
{
    $customerName = trim((string)($arguments['customer_name'] ?? ''));
    $phone = trim((string)($arguments['phone'] ?? ''));
    $partySize = max(1, (int)($arguments['party_size'] ?? 1));
    $reservationDate = trim((string)($arguments['reservation_date'] ?? ''));
    $reservationTime = trim((string)($arguments['reservation_time'] ?? ''));
    $deliveryType = trim((string)($arguments['delivery_type'] ?? ''));
    $notes = trim((string)($arguments['notes'] ?? ''));
    $items = $arguments['items'] ?? [];

    $date = DateTime::createFromFormat('!Y-m-d', $reservationDate);
    $timeValue = substr($reservationTime, 0, 5);
    $time = DateTime::createFromFormat('!H:i', $timeValue);
    if (
        $customerName === '' || $phone === '' || $partySize > 100 || !is_array($items) || empty($items)
        || !preg_match('/^\+?[0-9]{7,15}$/', preg_replace('/\s+/', '', $phone))
        || !$date || $date->format('Y-m-d') !== $reservationDate || $reservationDate !== date('Y-m-d')
        || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $reservationTime) || !$time || $time->format('H:i') !== $timeValue
        || !in_array($deliveryType, ['para_servirse', 'para_llevar'], true)
    ) {
        throw new RuntimeException('Datos insuficientes para crear la reserva.');
    }

    $requestedItems = [];
    foreach ($items as $item) {
        $productId = trim((string)($item['product_id'] ?? ''));
        $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
        if ($productId === '' || $quantity === false || $quantity < 1 || $quantity > 100) {
            throw new RuntimeException('Cada plato debe incluir un product_id y una cantidad válida.');
        }
        $requestedItems[$productId] = ($requestedItems[$productId] ?? 0) + $quantity;
    }

    $pdo->beginTransaction();
    try {
        $normalizedPhone = preg_replace('/\D+/', '', $phone);
        $customerLookup = $pdo->prepare("SELECT `id` FROM `clientes` WHERE `tenant_id` = :tenant_id AND `phone_normalized` = :phone AND `active` = 1 LIMIT 1");
        $customerLookup->execute(['tenant_id' => $businessContext['tenant_id'], 'phone' => $normalizedPhone]);
        $customerId = (string)($customerLookup->fetchColumn() ?: '');
        if ($customerId === '') {
            $customerId = 'cli_' . bin2hex(random_bytes(10));
            $customerInsert = $pdo->prepare("INSERT INTO `clientes` (`id`, `name`, `phone`, `phone_normalized`, `whatsapp_phone`, `source`, `tenant_id`, `branch_id`, `last_seen_at`, `updated_at`) VALUES (:id, :name, :phone, :phone_normalized, :whatsapp_phone, 'whatsapp_bot', :tenant_id, :branch_id, NOW(), NOW())");
            $customerInsert->execute(['id' => $customerId, 'name' => $customerName, 'phone' => $phone, 'phone_normalized' => $normalizedPhone, 'whatsapp_phone' => $phone, 'tenant_id' => $businessContext['tenant_id'], 'branch_id' => $businessContext['branch_id']]);
        } else {
            $pdo->prepare("UPDATE `clientes` SET `name` = :name, `whatsapp_phone` = :phone, `last_seen_at` = NOW(), `updated_at` = NOW() WHERE `id` = :id AND `tenant_id` = :tenant_id")->execute(['id' => $customerId, 'name' => $customerName, 'phone' => $phone, 'tenant_id' => $businessContext['tenant_id']]);
        }
        $priceStmt = $pdo->prepare("SELECT `id`, `valor` FROM `config_precios` WHERE `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
        $priceStmt->execute($businessContext);
        $prices = [];
        foreach ($priceStmt->fetchAll(PDO::FETCH_ASSOC) as $price) {
            $prices[$price['id']] = (float)$price['valor'];
        }

        $productStmt = $pdo->prepare("SELECT `id`, `name`, `type`, `price`, `stock` FROM `products` WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `active` = 1 FOR UPDATE");
        $items = [];
        foreach ($requestedItems as $productId => $quantity) {
            $productStmt->execute([
                'id' => $productId,
                'tenant_id' => $businessContext['tenant_id'],
                'branch_id' => $businessContext['branch_id'],
            ]);
            $product = $productStmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                throw new RuntimeException('Uno de los platos seleccionados ya no está disponible.');
            }
            if ((int)$product['stock'] < $quantity) {
                throw new RuntimeException('El plato "' . $product['name'] . '" no tiene suficiente stock.');
            }

            $price = (float)$product['price'];
            if ($price <= 0) {
                $price = (float)($prices[$product['type']] ?? 0);
            }
            if ($price <= 0) {
                throw new RuntimeException('El plato "' . $product['name'] . '" no tiene un precio configurado.');
            }
            $items[] = [
                'product_id' => $product['id'],
                'name' => $product['name'],
                'type' => $product['type'],
                'quantity' => $quantity,
                'price' => $price,
            ];
        }

        $items = chatbot_normalize_reservation_items($items, (float)($prices['almuerzo'] ?? 0), $deliveryType);

        $total = array_reduce($items, fn(float $sum, array $item): float => $sum + ($item['price'] * $item['quantity']), 0.0);
        $stockStmt = $pdo->prepare("UPDATE `products` SET `stock` = `stock` - :qty WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id AND `stock` >= :qty");
        foreach ($requestedItems as $productId => $quantity) {
            $stockStmt->execute([
                'qty' => $quantity,
                'id' => $productId,
                'tenant_id' => $businessContext['tenant_id'],
                'branch_id' => $businessContext['branch_id'],
            ]);
            if ($stockStmt->rowCount() !== 1) {
                throw new RuntimeException('El stock cambió mientras procesábamos la reserva. Inténtalo nuevamente.');
            }
        }

        $reservationId = 'res_' . bin2hex(random_bytes(8));
        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $stmt = $pdo->prepare("INSERT INTO `reservations` (`id`, `tenant_id`, `branch_id`, `customer_id`, `customer_name`, `phone`, `party_size`, `delivery_type`, `reservation_date`, `reservation_time`, `table_id`, `items`, `total`, `notes`, `status`, `source`, `verification_status`, `created_at`, `updated_at`) VALUES (:id, :tenant_id, :branch_id, :customer_id, :customer_name, :phone, :party_size, :delivery_type, :reservation_date, :reservation_time, NULL, :items, :total, :notes, 'pendiente', 'bot', 'pendiente', NOW(), NOW())");
        $stmt->execute([
            'id' => $reservationId,
            'tenant_id' => $businessContext['tenant_id'],
            'branch_id' => $businessContext['branch_id'],
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'phone' => $phone,
            'party_size' => $partySize,
            'delivery_type' => $deliveryType,
            'reservation_date' => $reservationDate,
            'reservation_time' => $reservationTime,
            'items' => $itemsJson,
            'total' => $total,
            'notes' => $notes !== '' ? $notes : null,
        ]);

        chatbot_update_conversation_context($pdo, $conversationId, [
            'reservation_id' => $reservationId,
            'customer_name' => $customerName,
            'phone' => $phone,
            'reservation_date' => $reservationDate,
            'reservation_time' => $reservationTime,
        ]);
        $update = $pdo->prepare("UPDATE `chatbot_conversations` SET `reservation_id` = :reservation_id, `customer_id` = :customer_id, `customer_name` = :customer_name, `updated_at` = NOW() WHERE `id` = :id AND `tenant_id` = :tenant_id AND `branch_id` = :branch_id");
        $update->execute([
            'id' => $conversationId,
            'reservation_id' => $reservationId,
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'tenant_id' => $businessContext['tenant_id'],
            'branch_id' => $businessContext['branch_id'],
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Conversación no encontrada para esta sucursal.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'id' => $reservationId,
        'customer_name' => $customerName,
        'phone' => $phone,
        'customer_id' => $customerId,
        'party_size' => $partySize,
        'reservation_date' => $reservationDate,
        'reservation_time' => substr($reservationTime, 0, 5),
        'table' => null,
        'items' => $items,
        'total' => $total,
        'delivery_type' => $deliveryType,
        'status' => 'pendiente',
        'source' => 'bot',
        'verification_status' => 'pendiente',
    ];
}

function chatbot_normalize_reservation_items(array $items, float $almuerzoPrice, string $deliveryType): array
{
    $serviceType = $deliveryType === 'para_llevar' ? 'llevar' : 'servirse';
    $sopas = array_values(array_filter($items, fn(array $item): bool => $item['type'] === 'sopa'));
    $segundos = array_values(array_filter($items, fn(array $item): bool => $item['type'] === 'segundo'));
    $normalized = [];
    $sopaIndex = 0;
    $segundoIndex = 0;

    // Pair each soup with a second so chatbot reservations use the same
    // Almuerzo Completo structure generated by the manual reservation form.
    while ($almuerzoPrice > 0 && isset($sopas[$sopaIndex], $segundos[$segundoIndex])) {
        $quantity = min((int)$sopas[$sopaIndex]['quantity'], (int)$segundos[$segundoIndex]['quantity']);
        if ($quantity <= 0) break;
        $normalized[] = [
            'type' => 'almuerzo',
            'id' => 'resitem_' . bin2hex(random_bytes(8)),
            'name' => 'Almuerzo Completo',
            'price' => $almuerzoPrice,
            'quantity' => $quantity,
            'detail' => '',
            'serviceType' => $serviceType,
            'sopaId' => $sopas[$sopaIndex]['product_id'],
            'sopaName' => $sopas[$sopaIndex]['name'],
            'segundoId' => $segundos[$segundoIndex]['product_id'],
            'segundoName' => $segundos[$segundoIndex]['name'],
        ];
        $sopas[$sopaIndex]['quantity'] -= $quantity;
        $segundos[$segundoIndex]['quantity'] -= $quantity;
        if ($sopas[$sopaIndex]['quantity'] === 0) $sopaIndex++;
        if ($segundos[$segundoIndex]['quantity'] === 0) $segundoIndex++;
    }

    foreach (array_merge(array_slice($sopas, $sopaIndex), array_slice($segundos, $segundoIndex)) as $item) {
        if ((int)$item['quantity'] <= 0) continue;
        $isSopa = $item['type'] === 'sopa';
        $normalized[] = [
            'type' => $item['type'],
            'id' => $item['product_id'],
            'name' => $item['name'],
            'price' => $item['price'],
            'quantity' => $item['quantity'],
            'detail' => '',
            'serviceType' => $serviceType,
            $isSopa ? 'sopaId' : 'segundoId' => $item['product_id'],
            $isSopa ? 'sopaName' : 'segundoName' => $item['name'],
        ];
    }

    foreach ($items as $item) {
        if (in_array($item['type'], ['sopa', 'segundo'], true)) continue;
        $isExtra = $item['type'] === 'refresco';
        $normalized[] = [
            'type' => $isExtra ? 'extra' : $item['type'],
            'id' => $item['product_id'],
            'name' => $item['name'],
            'price' => $item['price'],
            'quantity' => $item['quantity'],
            'detail' => '',
            'serviceType' => $serviceType,
            $isExtra ? 'extraId' : 'platoId' => $item['product_id'],
        ];
    }

    return $normalized;
}
