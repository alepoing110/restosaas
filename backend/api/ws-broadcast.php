<?php
// WebSocket event broadcaster - writes events for the WebSocket server to broadcast

function broadcastWebSocketEvent(string $eventType, array $data, array $authContext): void {
    $eventDir = __DIR__ . '/../../storage/ws';
    if (!is_dir($eventDir)) @mkdir($eventDir, 0750, true);
    $eventFile = $eventDir . '/restocloud_ws_events.jsonl';

    $event = json_encode([
        'type' => $eventType,
        'data' => $data,
        'tenant_id' => $authContext['tenant_id'] ?? '',
        'branch_id' => $authContext['branch_id'] ?? '',
        'timestamp' => date('c')
    ]);

    @file_put_contents($eventFile, $event . "\n", FILE_APPEND | LOCK_EX);

    if (filesize($eventFile) > 1048576) {
        $lines = @file($eventFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines) && count($lines) > 100) {
            $keep = array_slice($lines, -100);
            @file_put_contents($eventFile, implode("\n", $keep) . "\n", LOCK_EX);
        }
    }
}
