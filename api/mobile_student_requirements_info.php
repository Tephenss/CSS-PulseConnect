<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/student_requirements.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);

$eventId = trim((string) ($data['event_id'] ?? ''));
$userId = trim((string) ($data['user_id'] ?? ''));
if ($eventId === '' || $userId === '') {
    json_response(['ok' => false, 'error' => 'event_id and user_id are required.'], 400);
}

$headers = mobile_api_supabase_headers();
$requirements = fetch_student_requirements_map([$eventId], $headers)[$eventId] ?? [];
$documents = fetch_student_documents_map([$eventId], $headers, $userId)[$eventId][$userId] ?? [];
$access = resolve_student_document_access($eventId, $userId, $headers);

$documentsByRequirement = [];
foreach ($documents as $doc) {
    if (!is_array($doc)) {
        continue;
    }
    $reqId = trim((string) ($doc['requirement_id'] ?? ''));
    if ($reqId !== '') {
        $documentsByRequirement[$reqId] = $doc;
    }
}

json_response([
    'ok' => true,
    'requirements' => $requirements,
    'documents' => $documents,
    'documents_by_requirement' => $documentsByRequirement,
    'access' => [
        'required' => (bool) ($access['required'] ?? false),
        'complete' => (bool) ($access['complete'] ?? false),
        'approved' => (bool) ($access['approved'] ?? false),
        'status' => (string) ($access['status'] ?? ''),
        'message' => (string) ($access['message'] ?? ''),
        'decline_reason' => (string) ($access['decline_reason'] ?? ''),
        'summary' => $access['summary'] ?? null,
    ],
    'submission' => $access['submission'] ?? null,
], 200);
