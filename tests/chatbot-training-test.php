<?php

require_once __DIR__ . '/../backend/agent/chatbot.php';

$cases = json_decode(file_get_contents(__DIR__ . '/fixtures/chatbot-order-training.json'), true, 512, JSON_THROW_ON_ERROR);
$prompt = chatbot_system_prompt(['nombre_restaurante' => 'Restaurante de prueba']);
$failed = [];

foreach ($cases as $case) {
    if (empty($case['id']) || empty($case['message']) || !is_array($case['expected'] ?? null)) {
        $failed[] = 'Caso inválido: ' . ($case['id'] ?? 'sin id');
    }
}

foreach ([
    'alitas', 'pollo broaster', 'salchipapas', 'delivery', 'dirección', 'salsas',
    'doce y cuarto', 'dos reservas separadas', 'confirmación explícita',
] as $expectedRule) {
    if (!str_contains(mb_strtolower($prompt), $expectedRule)) {
        $failed[] = 'Regla de entrenamiento ausente: ' . $expectedRule;
    }
}

if ($failed) {
    fwrite(STDERR, implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo count($cases) . " casos de entrenamiento del agente validados\n";
