<?php
// validation.php
// Safaricom will POST here first. Return ResultCode 0 to accept.
header('Content-Type: application/json');

$raw = file_get_contents('php://input');
// optional logging for debugging
file_put_contents(__DIR__ . '/logs/validation_' . date('Ymd_His') . '.json', $raw . PHP_EOL, FILE_APPEND);

echo json_encode([
    'ResultCode' => 0,
    'ResultDesc' => 'Accepted'
]);
