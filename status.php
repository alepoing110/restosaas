<?php
// This probe intentionally does not load db.php. It distinguishes a hosting
// outage from an application migration or database configuration problem.
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
echo json_encode([
    'status' => 'ok',
    'message' => 'El servidor PHP de RestoCloud está respondiendo.',
    'service' => 'restocloud'
]);
