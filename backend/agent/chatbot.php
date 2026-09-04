<?php

function chatbot_system_prompt(array $business, array $conversationContext = [], string $waPhone = '', string $knownCustomerName = ''): string
{
    $name = $business['nombre_restaurante'] ?? 'el restaurante';
    $today = date('Y-m-d');
    $now = date('H:i');

    $contextInfo = '';
    if (!empty($conversationContext['reservation_id'])) {
        $contextInfo .= "\nYa existe una reserva confirmada (ID: {$conversationContext['reservation_id']}). Si el cliente quiere modificarla o hacer otra, pídele los nuevos datos.";
    }
    if ($waPhone !== '' && !str_starts_with($waPhone, 'sim_')) {
        $contextInfo .= "\nTELÉFONO DEL CLIENTE (del chat): $waPhone. Úsalo directamente en create_reservation SIN preguntarlo.";
    }
    if ($knownCustomerName !== '') {
        $contextInfo .= "\nNOMBRE DEL PERFIL DE WHATSAPP: $knownCustomerName. Úsalo solo si el cliente no proporciona un nombre distinto en el mensaje.";
    }

    $prompt = "Eres el asistente de pedidos de $name. Ayudas a clientes a hacer pedidos para comer en el restaurante o para llevar.\n";
    $prompt .= "\n";
    $prompt .= "FECHA Y HORA ACTUALES: $today, $now\n";
    $prompt .= "\n";
    $prompt .= "MENÚ - CATEGORÍAS:\n";
    $prompt .= "- almuerzo: sopas y segundos (platos principales del día)\n";
    $prompt .= "- extras: platos extra y bebidas/gaseosas\n";
    $prompt .= "- todo: todo el menú completo\n";
    $prompt .= "\n";
    $prompt .= "FLUJO OBLIGATORIO (paso a paso):\n";
    $prompt .= "1. Saluda brevemente.\n";
    $prompt .= "2. Antes de confirmar o reservar cualquier plato, USA list_menu para obtener IDs, precios y stock actuales. Cuando el cliente pida VER el menú, platos, precios o qué hay → USA list_menu:\n";
    $prompt .= "   - Si pide menú del almuerzo → category='almuerzo'\n";
    $prompt .= "   - Si pide menú de extras, bebidas → category='extras'\n";
    $prompt .= "   - Si solo dice menú sin especificar → pregunta qué menú quiere\n";
    $prompt .= "   - Si dice todo el menú → category='todo'\n";
    $prompt .= "3. Si el cliente pregunta qué es el almuerzo o qué incluye → responde que el almuerzo incluye elegir una sopa y un segundo. Pregúntale si quiere ver las opciones disponibles.\n";
    $prompt .= "4. Si el cliente confirma que quiere ver las opciones del almuerzo → llama list_menu con category='almuerzo' para mostrar sopas y segundos con precios y stock.\n";
    $prompt .= "5. Si solo hay UNA sopa o UN segundo disponibles (campo auto_select del resultado), NO preguntes cuál quiere — selecciónalo automáticamente y avísale al cliente cuál es.\n";
    $prompt .= "6. Si algún plato tiene stock 0, avisa al cliente y sugiere alternativas. NUNCA confirmes un plato agotado.\n";
    $prompt .= "7. Extrae todos los datos que el cliente ya escribió; no repitas preguntas respondidas.\n";
    $prompt .= "8. Si faltan datos, pregunta únicamente los faltantes: modalidad (servirse/llevar), hora o nombre. No preguntes la fecha ni el teléfono cuando ya estén disponibles.\n";
    $prompt .= "9. Cuando tengas nombre, platos, modalidad y hora, presenta un resumen estructurado y pide confirmación explícita. NO uses create_reservation todavía.\n";
    $prompt .= "10. Solo después de una respuesta afirmativa al resumen (por ejemplo: sí, confirmo, correcto) → USA create_reservation con TODOS los parámetros requeridos:\n";
    $prompt .= "   - customer_name: nombre del cliente\n";
    $prompt .= "   - phone: teléfono del cliente (extraído del chat de WhatsApp o pedido al cliente)\n";
    $prompt .= "   - party_size: 1 (default, no preguntes)\n";
    $prompt .= "   - reservation_date: SIEMPRE hoy ($today)\n";
    $prompt .= "   - reservation_time: hora que el cliente indique\n";
    $prompt .= "   - delivery_type: 'para_servirse' o 'para_llevar'\n";
    $prompt .= "   - items: array con CADA PLATO por separado (una sopa Y un segundo si es almuerzo completo). Cada item DEBE tener el product_id EXACTO que aparece en el menú (list_menu). NUNCA inventes IDs como 'combo_almuerzo'. Ejemplo: [{\"product_id\":\"p_abc123\",\"name\":\"Sopa de mani\",\"quantity\":1,\"price\":7},{\"product_id\":\"p_def456\",\"name\":\"Pollo a la mostaza\",\"quantity\":1,\"price\":17}]\n";
    $prompt .= "11. Después de crear la reserva → confirma los detalles al cliente.\n";
    $prompt .= "\n";
    $prompt .= "REGLAS IMPORTANTES:\n";
    $prompt .= "- La fecha SIEMPRE es HOY. NUNCA preguntes la fecha.\n";
    $prompt .= "- Si es para servirse en mesa, la mesa queda SIN ASIGNAR. No preguntes número de mesa.\n";
    $prompt .= "- Solo pide: nombre, platos, tipo (mesa/llevar), y hora.\n";
    $prompt .= "- party_size SIEMPRE es 1. No preguntes cantidad de personas.\n";
    $prompt .= "- Teléfono: si ya lo tienes del chat o la petición, úsalo directamente SIN preguntarlo. Solo pídelo si no lo tienes.\n";
    $prompt .= "- SIEMPRE usa las herramientas cuando el cliente las necesite. Nunca inventes datos del menú o disponibilidad.\n";
    $prompt .= "- Responde en español, breve y directo. NUNCA muestres tu razonamiento interno al cliente.\n";
    $prompt .= "- Los precios se muestran con el símbolo Bs (bolivianos), nunca con $.\n";
    $prompt .= "- Verifica el stock antes de confirmar un pedido. Si stock es 0, informa al cliente y sugiere alternativas.\n";
    $prompt .= "- Si el cliente pide almuerzo completo (sopa + segundo), el precio es el del almuerzo, NO la suma de sopa + segundo.\n";
    $prompt .= "- Si el cliente pide solo sopa o solo segundo, cobra el precio individual de ese plato.\n";
    $prompt .= "- Si falta un dato para create_reservation, pídelo al cliente antes de intentar crear la reserva.\n";
    $prompt .= "- El campo items DEBE incluir los platos con sus precios reales del menú.\n";
    $prompt .= "- NUNCA inventes product_id. USA SIEMPRE los IDs exactos que devuelve list_menu. Si el menú muestra id='p_abc123', ese es el product_id.\n";
    $prompt .= "- Para almuerzo completo, envía DOS items separados (sopa + segundo), NO un combo.\n";
    $prompt .= "- Si el cliente dice 'almuerzo completo', debe incluir una sopa y un segundo. Si no especifica uno y hay varias opciones, pregunta cuál desea.\n";
    $prompt .= "- Las cantidades de segundos se asignan a los almuerzos en el mismo orden indicado. Si no coinciden con la cantidad de almuerzos, pide aclaración.\n";
    $prompt .= "- Una sopa extra, un segundo extra, una bebida, alitas (también escrito 'alista'), pollo broaster, salchipapas u otro plato extra NO forman parte del almuerzo y deben ir como item independiente.\n";
    $prompt .= "- Para alitas, pollo broaster, salchipapas y otros extras usa list_menu con category='extras'. Respeta exactamente tamaño, cantidad y precio del catálogo; nunca infieras precios por el texto del cliente.\n";
    $prompt .= "- Si el cliente pide delivery, usa delivery_type='para_llevar', exige dirección y guarda dirección, referencias, salsas y observaciones en notes.\n";
    $prompt .= "- Para alitas u otros platos con salsas, consulta las salsas disponibles y conserva la elección exacta en notes, incluso si divide sabores por mitades.\n";
    $prompt .= "- Si el cliente mezcla 'para servirse' y 'para llevar' en un solo mensaje, explica que se crearán dos reservas separadas y pide confirmación de esa división. Tras confirmarla, llama create_reservation una vez por cada modalidad; nunca combines ambas en una sola reserva.\n";
    $prompt .= "- Reconoce como equivalentes: 'para ahí', 'para mesa', 'para la pensión' y 'para servirse' = para_servirse; 'recoger', 'recojo', 'pasaré' y 'para llevar' = para_llevar.\n";
    $prompt .= "- Interpreta horas: 'doce y cuarto' = 12:15, '12 y media' = 12:30, 'mediodía' = 12:00 y '1 pm' = 13:00.\n";
    $prompt .= "\nEJEMPLOS DE MENSAJES REALES:\n";
    $prompt .= "Cliente: '1 almuerzo con segundo chicharrón de pollo para Claudia Huanca'. Respuesta: pide solamente modalidad y hora; conserva Claudia Huanca como nombre.\n";
    $prompt .= "Cliente: '2 almuerzos completos, 1 chicharrón y 1 albóndiga, para Marco, recojo a las 12:20'. Respuesta: pide solo confirmación de la sopa si hay más de una opción; modalidad=para_llevar, hora=12:20, nombre=Marco.\n";
    $prompt .= "Cliente: '3 almuerzos completos, 2 para servirse y 1 para llevar, pasaremos a las 12:30'. Respuesta: indica que se crearán dos reservas separadas y pide confirmación, sin inventar la distribución de segundos.\n";
    $prompt .= "Cliente: '1 almuerzo completo con albóndiga y 1 sopa extra. Para ahí. Flia Flores'. Respuesta: registra un almuerzo y una sopa independiente; pide solo la hora.\n";
    $prompt .= "Cliente: 'Una alita de 25, mitad miel y mostaza y mitad BBQ. Recogeré en media hora, Cliente E'. Respuesta: consulta extras y salsas; conserva ambas salsas como nota y pregunta la hora exacta si no puede calcularla.\n";
    $prompt .= "Cliente: 'Dos pollos broaster y una salchipapa para llevar'. Respuesta: consulta extras, confirma cantidades y pide solo nombre y hora.\n";
    $prompt .= "Cliente: 'Tres almuerzos para delivery a Avenida Ejemplo 123'. Respuesta: registra la dirección en notas y pide solamente segundo, sopa, hora y nombre que falten.\n";
    $prompt .= "- Si un plato tiene salsas disponibles (accepts_salsa=true), menciónalo al cliente. Las salsas se sirven a la mesa sin costo adicional.\n";
    $prompt .= $contextInfo;

    return $prompt;
}

function chatbot_handle_message(PDO $pdo, array $businessContext, array $businessConfig, string $waPhone, string $customerName, string $messageText): array
{
    $conversation = chatbot_find_or_create_conversation($pdo, $businessContext, $waPhone, $customerName);
    chatbot_append_message($pdo, $conversation['id'], 'user', $messageText);

    $history = chatbot_load_recent_messages($pdo, $conversation['id'], 8);
    $messages = [
        ['role' => 'system', 'content' => chatbot_system_prompt($businessConfig, $conversation['context'] ?? [], $waPhone, (string)($conversation['customer_name'] ?? $customerName))],
    ];
    foreach ($history as $message) {
        $msg = ['role' => $message['role']];
        if (array_key_exists('content', $message)) {
            $msg['content'] = $message['content'];
        }
        if (!empty($message['tool_calls'])) {
            $msg['tool_calls'] = $message['tool_calls'];
        }
        if (!empty($message['tool_call_id'])) {
            $msg['tool_call_id'] = $message['tool_call_id'];
        }
        $messages[] = $msg;
    }

    $response = rc_llm_chat($messages, chatbot_tool_definitions(), ['max_tokens' => 900]);
    $choice = $response['choices'][0]['message'] ?? [];
    $toolCalls = $choice['tool_calls'] ?? [];
    $usage = $response['usage'] ?? [];

    $contentRaw = trim((string)($choice['content'] ?? ''));
    error_log('[chatbot-debug] content=' . substr($contentRaw, 0, 100) . ' tool_calls=' . count($toolCalls));

    if ($toolCalls) {
        $toolResults = [];
        foreach ($toolCalls as $toolCall) {
            $toolCallId = $toolCall['id'] ?? 'tool_' . count($toolResults);
            $toolName = $toolCall['function']['name'] ?? '';
            $arguments = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: [];
            try {
                $toolResults[$toolCallId] = chatbot_execute_tool($pdo, $businessContext, $conversation['id'], $toolName, $arguments);
            } catch (Throwable $e) {
                $toolResults[$toolCallId] = ['error' => $e->getMessage()];
                error_log('[chatbot] tool error: ' . $e->getMessage());
            }
        }

        chatbot_append_message(
            $pdo,
            $conversation['id'],
            'assistant',
            'Tool call: ' . $toolName,
            $toolCalls,
            $toolResults,
            (int)($usage['prompt_tokens'] ?? 0),
            (int)($usage['completion_tokens'] ?? 0)
        );

        $followUpMessages = array_merge($messages, [[
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => $toolCalls,
        ]]);
        foreach ($toolCalls as $toolCall) {
            $toolCallId = $toolCall['id'] ?? 'tool_1';
            $followUpMessages[] = [
                'role' => 'tool',
                'tool_call_id' => $toolCallId,
                'content' => json_encode($toolResults[$toolCallId] ?? ['error' => 'Resultado no disponible'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        $followUpResponse = rc_llm_chat($followUpMessages, null, ['max_tokens' => 900]);
        $assistantText = trim((string)($followUpResponse['choices'][0]['message']['content'] ?? ''));
        if ($assistantText === '') {
            $firstToolResult = reset($toolResults) ?: [];
            $firstToolName = $toolCalls[0]['function']['name'] ?? '';
            if (!empty($firstToolResult['error'])) {
                $assistantText = 'No pude procesar esa acción: ' . $firstToolResult['error'] . '. ¿Qué datos tienes disponibles para la reserva?';
            } else {
                $assistantText = chatbot_fallback_reply_for_tool($firstToolName, $firstToolResult);
            }
        }
        chatbot_append_message(
            $pdo,
            $conversation['id'],
            'assistant',
            $assistantText,
            null,
            $toolResults,
            (int)($followUpResponse['usage']['prompt_tokens'] ?? 0),
            (int)($followUpResponse['usage']['completion_tokens'] ?? 0)
        );

        return [
            'conversation' => chatbot_get_conversation($pdo, $conversation['id']),
            'reply' => $assistantText,
            'tool_result' => reset($toolResults) ?: null,
        ];
    }

    $assistantText = trim((string)($choice['content'] ?? ''));
    if ($assistantText === '') {
        $restaurantName = $businessConfig['nombre_restaurante'] ?? 'nuestro restaurante';
        $assistantText = '¡Hola! Bienvenido a ' . $restaurantName . '. ¿Qué te gustaría pedir? Puedo mostrarte nuestro menú.';
    }
    chatbot_append_message(
        $pdo,
        $conversation['id'],
        'assistant',
        $assistantText,
        null,
        null,
        (int)($usage['prompt_tokens'] ?? 0),
        (int)($usage['completion_tokens'] ?? 0)
    );

    return [
        'conversation' => chatbot_get_conversation($pdo, $conversation['id']),
        'reply' => $assistantText,
        'tool_result' => null,
    ];
}

function chatbot_fallback_reply_for_tool(string $toolName, array $toolResult): string
{
    if ($toolName === 'list_menu') {
        $items = $toolResult['items'] ?? [];
        $category = $toolResult['category'] ?? 'todo';
        $salsas = $toolResult['salsas'] ?? [];
        if (empty($items)) {
            return 'No hay platos disponibles en este momento para la categoría solicitada.';
        }

        $out = '';

        if ($category === 'almuerzo') {
            $comboPrice = $toolResult['combo_price'] ?? 0;
            $out .= "ALMUERZO (Sopa + Segundo)";
            if ($comboPrice > 0) {
                $out .= " — Bs " . number_format($comboPrice, 2);
            }
            $out .= "\n\n";

            $sopas = array_filter($items, fn($i) => $i['type'] === 'sopa');
            $segundos = array_filter($items, fn($i) => $i['type'] === 'segundo');

            $out .= "Sopas:\n";
            foreach ($sopas as $item) {
                $stock = (int)($item['stock'] ?? 0);
                $salsaTag = !empty($item['accepts_salsa']) ? ' ✓ salsa' : '';
                $stockTag = $stock <= 0 ? ' [AGOTADO]' : '';
                $out .= "• " . $item['name'] . $salsaTag . $stockTag . "\n";
            }

            $out .= "\nSegundos:\n";
            foreach ($segundos as $item) {
                $stock = (int)($item['stock'] ?? 0);
                $salsaTag = !empty($item['accepts_salsa']) ? ' ✓ salsa' : '';
                $stockTag = $stock <= 0 ? ' [AGOTADO]' : '';
                $out .= "• " . $item['name'] . $salsaTag . $stockTag . "\n";
            }

            $autoSelect = $toolResult['auto_select'] ?? [];
            if (!empty($autoSelect['sopa'])) {
                $out .= "\n(auto-seleccionado: " . $autoSelect['sopa']['name'] . " — única sopa disponible)\n";
            }
            if (!empty($autoSelect['segundo'])) {
                $out .= "\n(auto-seleccionado: " . $autoSelect['segundo']['name'] . " — único segundo disponible)\n";
            }

        } else {
            if ($category === 'extras') {
                $out .= "EXTRAS Y BEBIDAS\n\n";
            } else {
                $out .= "MENÚ COMPLETO\n\n";
            }

            $groups = [];
            foreach ($items as $item) {
                $type = $item['type'] ?? 'otro';
                $groups[$type][] = $item;
            }

            $typeLabels = [
                'sopa' => 'Sopas',
                'segundo' => 'Segundos',
                'plato_extra' => 'Platos Extras',
                'refresco' => 'Bebidas',
            ];

            foreach ($groups as $type => $groupItems) {
                $label = $typeLabels[$type] ?? ucfirst($type);
                $out .= $label . ":\n";
                foreach ($groupItems as $item) {
                    $stock = (int)($item['stock'] ?? 0);
                    $salsaTag = !empty($item['accepts_salsa']) ? ' ✓ salsa' : '';
                    $stockTag = $stock <= 0 ? ' [AGOTADO]' : '';
                    $out .= "• " . $item['name'] . " — Bs " . number_format($item['price'], 2) . $salsaTag . $stockTag . "\n";
                }
                $out .= "\n";
            }
        }

        if (!empty($salsas)) {
            $salsaNames = array_map(fn($s) => $s['name'], $salsas);
            $out .= "Salsas: " . implode(', ', $salsaNames) . "\n\n";
        }

        $outOfStock = array_filter($items, fn($i) => (int)($i['stock'] ?? 0) <= 0);
        if (!empty($outOfStock)) {
            $out .= "Algunos platos están agotados. Elige otros por favor.\n\n";
        }

        $out .= "¿Qué te gustaría pedir?";
        return rtrim($out);
    }
    if ($toolName === 'check_availability') {
        if (!empty($toolResult['available']) && !empty($toolResult['table']['name'])) {
            return 'Sí, tenemos disponibilidad. Te puedo ofrecer la ' . $toolResult['table']['name'] . '. Si deseas, continuo con tu reserva.';
        }
        return 'No encontré disponibilidad exacta para ese horario. Puedo proponerte otro horario si me indicas la fecha y cantidad de personas.';
    }
    if ($toolName === 'recommend_time' && !empty($toolResult['recommendations'])) {
        $parts = [];
        foreach ($toolResult['recommendations'] as $recommendation) {
            $parts[] = $recommendation['time'];
        }
        return 'Te recomiendo estos horarios disponibles: ' . implode(', ', $parts) . '.';
    }
    if ($toolName === 'create_reservation' && !empty($toolResult['reservation'])) {
        $reservation = $toolResult['reservation'];
        $tableName = $reservation['delivery_type'] === 'para_llevar' ? 'Para llevar' : 'Para servirse en mesa';
        $items = $reservation['items'] ?? [];
        $itemList = '';
        if (!empty($items)) {
            $itemList = "\n\nPedido:";
            $isCombo = false;
            foreach ($items as $item) {
                $qty = $item['quantity'] ?? 1;
                $salsas = $item['salsas'] ?? [];
                $salsaStr = !empty($salsas) ? ' con ' . implode(' y ', array_map(fn($s) => $s['name'] ?? $s, $salsas)) : '';
                $itemList .= "\n• " . $qty . "x " . $item['name'] . $salsaStr;
                if (!empty($item['is_combo'])) $isCombo = true;
            }
            if ($isCombo) {
                $itemList .= "\n(Almuerzo completo)";
            }
        }
        return "Reserva confirmada para " . $reservation['customer_name'] . "\n\n"
            . "Hoy " . date('d/m', strtotime($reservation['reservation_date'])) . " — " . $reservation['reservation_time'] . "\n"
            . $tableName . $itemList . "\n\n"
            . "Total: Bs " . number_format($reservation['total'] ?? 0, 2);
    }
    return 'Entendido. Sigo ayudándote con la reserva.';
}
