<?php
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/backend/agent/whatsapp.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $mode = $_GET['hub_mode'] ?? ($_GET['hub.mode'] ?? '');
    $verifyToken = $_GET['hub_verify_token'] ?? ($_GET['hub.verify_token'] ?? '');
    $challenge = $_GET['hub_challenge'] ?? ($_GET['hub.challenge'] ?? '');

    if ($mode === 'subscribe' && $verifyToken !== '' && hash_equals(rc_env('WHATSAPP_VERIFY_TOKEN', ''), $verifyToken)) {
        http_response_code(200);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $challenge;
        exit;
    }

    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Verificación inválida']);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';
if (!whatsapp_validate_signature($rawBody)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Firma inválida']);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Payload inválido']);
    exit;
}

$queued = 0;
$ignored = 0;
foreach (whatsapp_parse_webhook_messages($payload) as $message) {
    if (($message['type'] ?? '') !== 'text' || trim((string)($message['text'] ?? '')) === '') {
        $ignored++;
        continue;
    }

    $businessContext = whatsapp_resolve_business_context(
        $pdo,
        (string)($message['phone_number_id'] ?? ''),
        (string)($message['display_phone_number'] ?? '')
    );
    if (!$businessContext) {
        error_log('[RestoCloud][webhook] Número de WhatsApp no configurado.');
        $ignored++;
        continue;
    }

    if (whatsapp_enqueue_event($pdo, $message, $businessContext)) {
        $queued++;
    } else {
        $ignored++;
    }
}

// Meta requires a fast acknowledgement; LLM and WhatsApp calls run in whatsapp-worker.php.
echo json_encode(['status' => 'accepted', 'queued' => $queued, 'ignored' => $ignored]);
