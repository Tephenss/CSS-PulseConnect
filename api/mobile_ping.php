<?php
declare(strict_types=1);

/**
 * Lightweight reachability probe for mobile offline detection.
 * No auth / secrets — returns ok only.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code(200);
echo json_encode(['ok' => true, 'pong' => true], JSON_UNESCAPED_SLASHES);
