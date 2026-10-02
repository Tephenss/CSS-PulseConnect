<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/firestore_bug_reports.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    $action = trim((string) ($_GET['action'] ?? 'list'));
    if ($action === 'list') {
        $reports = firestore_bug_report_list(200);
        if ($reports === null) {
            error_log('bug_reports_review: Firestore list failed');
            json_response(['ok' => false, 'error' => 'Unable to load bug reports right now.'], 503);
        }
        json_response(['ok' => true, 'reports' => $reports]);
    }
    if ($action === 'detail') {
        $id = trim((string) ($_GET['id'] ?? ''));
        if (!firestore_bug_report_valid_id($id)) {
            json_response(['ok' => false, 'error' => 'Invalid report id.'], 400);
        }
        $report = firestore_bug_report_get($id);
        if ($report === null) {
            json_response(['ok' => false, 'error' => 'Report not found.'], 404);
        }
        json_response(['ok' => true, 'report' => $report]);
    }
    json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
}

if ($method !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$data = require_post_json();
if ((string) ($data['action'] ?? '') !== 'update') {
    json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
}

$id = trim((string) ($data['id'] ?? ''));
$status = trim((string) ($data['status'] ?? ''));
$reviewNote = trim(str_replace(["\r\n", "\r"], "\n", (string) ($data['review_note'] ?? '')));
$reviewNote = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $reviewNote) ?? '';
$publicUpdate = trim(str_replace(["\r\n", "\r"], "\n", (string) ($data['public_update'] ?? '')));
$publicUpdate = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $publicUpdate) ?? '';
if (!firestore_bug_report_valid_id($id)) {
    json_response(['ok' => false, 'error' => 'Invalid report id.'], 400);
}
if (!in_array($status, ['new', 'reviewing', 'resolved'], true)) {
    json_response(['ok' => false, 'error' => 'Choose a valid review status.'], 400);
}
if (mb_strlen($reviewNote, 'UTF-8') > 1500) {
    json_response(['ok' => false, 'error' => 'Reviewer note must be 1,500 characters or fewer.'], 400);
}
if (mb_strlen($publicUpdate, 'UTF-8') > 1500) {
    json_response(['ok' => false, 'error' => 'Reporter update must be 1,500 characters or fewer.'], 400);
}
if (firestore_bug_report_get($id) === null) {
    json_response(['ok' => false, 'error' => 'Report not found.'], 404);
}
if (!firestore_bug_report_update($id, $status, $reviewNote, $publicUpdate)) {
    error_log('bug_reports_review: Firestore update failed id=' . $id);
    json_response(['ok' => false, 'error' => 'Could not save the review. Please try again.'], 503);
}

json_response(['ok' => true]);
