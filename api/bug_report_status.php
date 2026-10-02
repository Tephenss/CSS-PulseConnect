<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/firestore_bug_reports.php';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$data = require_post_json();
$ticketCode = trim((string) ($data['ticket_code'] ?? ''));
$id = firestore_bug_report_normalize_ticket($ticketCode);
if ($id === null) {
    json_response(['ok' => false, 'error' => 'Enter the complete ticket code, including all six groups.'], 400);
}

$report = firestore_bug_report_get($id);
if ($report === null) {
    json_response(['ok' => false, 'error' => 'We could not find that ticket. Check the code and try again.'], 404);
}

$status = (string) ($report['status'] ?? 'new');
if (!in_array($status, ['new', 'reviewing', 'resolved'], true)) {
    $status = 'new';
}

json_response([
    'ok' => true,
    'ticket' => [
        'ticket_code' => firestore_bug_report_ticket_code($id),
        'status' => $status,
        'public_update' => (string) ($report['public_update'] ?? ''),
        'created_at' => (string) ($report['created_at'] ?? ''),
        'updated_at' => (string) ($report['updated_at'] ?? ''),
    ],
]);
