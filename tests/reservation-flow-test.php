<?php

require_once __DIR__ . '/../backend/agent/tools.php';
require_once __DIR__ . '/../backend/api/reservations.php';

$items = chatbot_normalize_reservation_items([
    ['product_id' => 'sopa-1', 'name' => 'Sopa de mani', 'type' => 'sopa', 'quantity' => 2, 'price' => 7],
    ['product_id' => 'segundo-1', 'name' => 'Silpancho', 'type' => 'segundo', 'quantity' => 1, 'price' => 12],
    ['product_id' => 'segundo-2', 'name' => 'Pollo', 'type' => 'segundo', 'quantity' => 1, 'price' => 12],
    ['product_id' => 'extra-1', 'name' => 'Alitas', 'type' => 'plato_extra', 'quantity' => 1, 'price' => 25],
    ['product_id' => 'drink-1', 'name' => 'Refresco', 'type' => 'refresco', 'quantity' => 1, 'price' => 5],
], 17, 'para_llevar');

$valid = count($items) === 4
    && $items[0]['type'] === 'almuerzo'
    && $items[0]['quantity'] === 1
    && $items[0]['serviceType'] === 'llevar'
    && $items[0]['detail'] === ''
    && $items[1]['type'] === 'almuerzo'
    && $items[2]['platoId'] === 'extra-1'
    && $items[3]['type'] === 'extra'
    && $items[3]['extraId'] === 'drink-1';

if (!$valid) {
    fwrite(STDERR, "La normalización de reserva del agente es inconsistente.\n");
    exit(1);
}

$stockUsage = reservationStockUsage($items);
if (($stockUsage['sopa-1'] ?? 0) !== 2 || ($stockUsage['segundo-1'] ?? 0) !== 1 || ($stockUsage['segundo-2'] ?? 0) !== 1 || ($stockUsage['extra-1'] ?? 0) !== 1 || ($stockUsage['drink-1'] ?? 0) !== 1) {
    fwrite(STDERR, "El bloqueo de stock de reservas es inconsistente.\n");
    exit(1);
}

echo "Flujo de reservas manuales y del agente normalizado\n";
