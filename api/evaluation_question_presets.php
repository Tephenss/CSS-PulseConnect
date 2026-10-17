<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/evaluation_feedback_lib.php';
require_once __DIR__ . '/../includes/event_sessions.php';

function evaluation_question_preset_schema_error(array $response): ?string
{
    $body = strtolower((string) ($response['body'] ?? ''));
    if ($body === '') {
        return null;
    }

    if (str_contains($body, 'evaluation_question_presets_questions_check')
        || (str_contains($body, '23514') && str_contains($body, 'evaluation_question_presets'))) {
        return 'The database still only accepts event-level preset questions. Run migration 067_evaluation_question_preset_sections.sql to enable seminar question sets.';
    }

    if (str_contains($body, 'evaluation_question_presets')
        && (str_contains($body, 'does not exist')
            || str_contains($body, 'schema cache')
            || str_contains($body, '42p01')
            || str_contains($body, 'pgrst205'))) {
        return 'Question presets are not set up in the database. Run migration 065_evaluation_question_presets.sql. If that migration was already applied before event-structure filtering was added, also run 066_evaluation_question_presets_structure.sql.';
    }

    if (str_contains($body, 'event_structure')
        && (str_contains($body, 'column') || str_contains($body, 'schema cache') || str_contains($body, 'does not exist'))) {
        return 'The question preset table needs the event-structure update. Run migration 066_evaluation_question_presets_structure.sql.';
    }

    return null;
}

function normalize_question_preset_items(mixed $rawQuestions): ?array
{
    if (!is_array($rawQuestions) || count($rawQuestions) > 50) {
        return null;
    }

    $questions = [];
    foreach ($rawQuestions as $question) {
        if (!is_array($question)) {
            return null;
        }
        $questionText = clean_string((string) ($question['question_text'] ?? ''));
        $fieldType = strtolower(trim((string) ($question['field_type'] ?? 'text')));
        $textLength = function_exists('mb_strlen') ? mb_strlen($questionText) : strlen($questionText);
        if ($questionText === '' || $textLength > 500 || !in_array($fieldType, ['text', 'rating'], true)) {
            return null;
        }
        $questions[] = [
            'question_text' => $questionText,
            'field_type' => $fieldType,
            'required' => (bool) ($question['required'] ?? false),
        ];
    }

    return $questions;
}

function normalize_question_preset_sets(mixed $raw, string $eventStructure): ?array
{
    if (!is_array($raw)) {
        return null;
    }

    $isStructured = array_key_exists('event_questions', $raw) || array_key_exists('seminars', $raw);
    if (!$isStructured) {
        // Continue to support presets saved by the earlier event-questions-only version.
        $eventQuestions = normalize_question_preset_items($raw);
        if ($eventQuestions === null) {
            return null;
        }
        $seminars = [];
    } else {
        $eventQuestions = normalize_question_preset_items($raw['event_questions'] ?? null);
        $rawSeminars = $raw['seminars'] ?? null;
        $expectedSeminars = match ($eventStructure) {
            'one_seminar' => 1,
            'two_seminars' => 2,
            default => 0,
        };
        if ($eventQuestions === null || !is_array($rawSeminars) || count($rawSeminars) !== $expectedSeminars) {
            return null;
        }

        $seminars = [];
        foreach (array_values($rawSeminars) as $index => $seminar) {
            if (!is_array($seminar) || (int) ($seminar['seminar_no'] ?? 0) !== $index + 1) {
                return null;
            }
            $seminarQuestions = normalize_question_preset_items($seminar['questions'] ?? null);
            if ($seminarQuestions === null) {
                return null;
            }
            $seminars[] = [
                'seminar_no' => $index + 1,
                'questions' => $seminarQuestions,
            ];
        }
    }

    $total = count($eventQuestions);
    foreach ($seminars as $seminar) {
        $total += count($seminar['questions']);
    }
    if ($total < 1 || $total > 50) {
        return null;
    }

    return [
        'event_questions' => $eventQuestions,
        'seminars' => $seminars,
    ];
}

function question_preset_sets_count(array $sets): int
{
    $total = count(is_array($sets['event_questions'] ?? null) ? $sets['event_questions'] : []);
    foreach (is_array($sets['seminars'] ?? null) ? $sets['seminars'] : [] as $seminar) {
        $total += count(is_array($seminar['questions'] ?? null) ? $seminar['questions'] : []);
    }

    return $total;
}

$user = require_role(['teacher']);
$userId = trim((string) ($user['id'] ?? ''));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$data = [];

if ($method === 'GET') {
    $eventId = trim((string) ($_GET['event_id'] ?? ''));
} else {
    $data = require_post_json();
    require_csrf_from_json($data);
    $eventId = trim((string) ($data['event_id'] ?? ''));
}

if ($eventId === '' || $userId === '') {
    json_response(['ok' => false, 'error' => 'event_id required'], 400);
}

$readHeaders = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=id,created_by,event_mode,event_structure'
    . '&id=eq.' . rawurlencode($eventId)
    . '&limit=1';
$eventRes = supabase_request('GET', $eventUrl, $readHeaders);
$eventRows = ($eventRes['ok'] ?? false) ? json_decode((string) ($eventRes['body'] ?? ''), true) : null;
$event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;
if (!is_array($event) || (string) ($event['created_by'] ?? '') !== $userId) {
    json_response(['ok' => false, 'error' => 'Event not found or access denied'], 403);
}
$eventSessions = fetch_event_sessions($eventId, $readHeaders);
$eventStructure = resolve_event_structure_key($event, $eventSessions);

if ($method === 'GET') {
    $presetsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_question_presets'
        . '?select=id,preset_name,questions,created_at,updated_at'
        . '&created_by=eq.' . rawurlencode($userId)
        . '&event_structure=eq.' . rawurlencode($eventStructure)
        . '&order=updated_at.desc';
    $presetsRes = supabase_request('GET', $presetsUrl, $readHeaders);
    if (!($presetsRes['ok'] ?? false)) {
        $schemaError = evaluation_question_preset_schema_error($presetsRes);
        json_response([
            'ok' => false,
            'error' => $schemaError ?? build_error($presetsRes['body'] ?? null, (int) ($presetsRes['status'] ?? 0), $presetsRes['error'] ?? null, 'Could not load question presets.'),
        ], 500);
    }

    $rows = json_decode((string) ($presetsRes['body'] ?? ''), true);
    $presets = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $questionSets = normalize_question_preset_sets($row['questions'] ?? null, $eventStructure);
        if ($questionSets === null) {
            continue;
        }
        $presets[] = [
            'id' => (string) ($row['id'] ?? ''),
            'preset_name' => (string) ($row['preset_name'] ?? ''),
            'question_count' => question_preset_sets_count($questionSets),
        ];
    }
    json_response(['ok' => true, 'presets' => $presets]);
}

$action = trim((string) ($data['action'] ?? ''));
if ($action === 'save') {
    $presetName = clean_string((string) ($data['preset_name'] ?? ''));
    $rawQuestions = $data['questions'] ?? null;
    $nameLength = function_exists('mb_strlen') ? mb_strlen($presetName) : strlen($presetName);
    if ($presetName === '' || $nameLength > 80) {
        json_response(['ok' => false, 'error' => 'Preset name must be between 1 and 80 characters.'], 400);
    }
    $questionSets = normalize_question_preset_sets($rawQuestions, $eventStructure);
    if ($questionSets === null) {
        json_response(['ok' => false, 'error' => 'Add 1 to 50 valid questions across Event Questions and the matching seminar sections before saving.'], 400);
    }

    $writeHeaders = [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: return=representation',
    ];
    $saveUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_question_presets?select=id,preset_name,questions';
    $saveRes = supabase_request('POST', $saveUrl, $writeHeaders, json_encode([[
        'created_by' => $userId,
        'event_structure' => $eventStructure,
        'preset_name' => $presetName,
        'questions' => $questionSets,
    ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if (!($saveRes['ok'] ?? false)) {
        $body = (string) ($saveRes['body'] ?? '');
        $schemaError = evaluation_question_preset_schema_error($saveRes);
        if ($schemaError !== null) {
            json_response(['ok' => false, 'error' => $schemaError], 500);
        }
        if (str_contains($body, '23505') || stripos($body, 'evaluation_question_presets_owner_name_idx') !== false) {
            json_response(['ok' => false, 'error' => 'A preset with that name already exists. Choose another name.'], 409);
        }
        json_response(['ok' => false, 'error' => build_error($body, (int) ($saveRes['status'] ?? 0), $saveRes['error'] ?? null, 'Could not save preset.')], 500);
    }

    $savedRows = json_decode((string) ($saveRes['body'] ?? ''), true);
    $savedPreset = is_array($savedRows) && isset($savedRows[0]) && is_array($savedRows[0]) ? $savedRows[0] : null;
    json_response(['ok' => true, 'preset' => $savedPreset]);
}

if ($action === 'delete') {
    $presetId = trim((string) ($data['preset_id'] ?? ''));
    if ($presetId === '') {
        json_response(['ok' => false, 'error' => 'Choose a preset first.'], 400);
    }

    $deleteHeaders = $readHeaders;
    $deleteHeaders[] = 'Prefer: return=representation';
    $deleteUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_question_presets'
        . '?select=id,preset_name'
        . '&id=eq.' . rawurlencode($presetId)
        . '&created_by=eq.' . rawurlencode($userId)
        . '&event_structure=eq.' . rawurlencode($eventStructure);
    $deleteRes = supabase_request('DELETE', $deleteUrl, $deleteHeaders);
    if (!($deleteRes['ok'] ?? false)) {
        $schemaError = evaluation_question_preset_schema_error($deleteRes);
        json_response([
            'ok' => false,
            'error' => $schemaError ?? build_error($deleteRes['body'] ?? null, (int) ($deleteRes['status'] ?? 0), $deleteRes['error'] ?? null, 'Could not remove preset.'),
        ], 500);
    }

    $deletedRows = json_decode((string) ($deleteRes['body'] ?? ''), true);
    $deletedPreset = is_array($deletedRows) && isset($deletedRows[0]) && is_array($deletedRows[0]) ? $deletedRows[0] : null;
    if (!is_array($deletedPreset)) {
        json_response(['ok' => false, 'error' => 'Preset not found for this event structure.'], 404);
    }

    json_response(['ok' => true, 'preset_name' => (string) ($deletedPreset['preset_name'] ?? '')]);
}

if ($action === 'apply') {
    $presetId = trim((string) ($data['preset_id'] ?? ''));
    if ($presetId === '') {
        json_response(['ok' => false, 'error' => 'Choose a preset first.'], 400);
    }

    $presetUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_question_presets'
        . '?select=id,preset_name,questions,event_structure'
        . '&id=eq.' . rawurlencode($presetId)
        . '&created_by=eq.' . rawurlencode($userId)
        . '&event_structure=eq.' . rawurlencode($eventStructure)
        . '&limit=1';
    $presetRes = supabase_request('GET', $presetUrl, $readHeaders);
    if (!($presetRes['ok'] ?? false)) {
        $schemaError = evaluation_question_preset_schema_error($presetRes);
        json_response(['ok' => false, 'error' => $schemaError ?? 'Could not read the selected preset.'], 500);
    }
    $presetRows = ($presetRes['ok'] ?? false) ? json_decode((string) ($presetRes['body'] ?? ''), true) : null;
    $preset = is_array($presetRows) && isset($presetRows[0]) && is_array($presetRows[0]) ? $presetRows[0] : null;
    $questionSets = is_array($preset)
        ? normalize_question_preset_sets($preset['questions'] ?? null, $eventStructure)
        : null;
    if (!is_array($preset) || $questionSets === null) {
        json_response(['ok' => false, 'error' => 'Preset is unavailable for this event structure, or it contains no valid questions.'], 404);
    }

    $seminarSets = $questionSets['seminars'];
    if ($seminarSets !== [] && count($eventSessions) !== count($seminarSets)) {
        json_response(['ok' => false, 'error' => 'The target event does not have the same number of seminar sessions as this preset.'], 409);
    }

    $seminarPayloads = [];
    foreach ($seminarSets as $seminarSet) {
        $seminarIndex = (int) ($seminarSet['seminar_no'] ?? 0) - 1;
        $sessionId = trim((string) ($eventSessions[$seminarIndex]['id'] ?? ''));
        if ($sessionId === '') {
            json_response(['ok' => false, 'error' => 'Could not match this preset to the target event seminars.'], 409);
        }
        $seminarPayloads[] = [
            'session_id' => $sessionId,
            'questions' => $seminarSet['questions'],
        ];
    }

    $rpcUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/rpc/replace_event_evaluation_questions_from_preset';
    $rpcHeaders = $readHeaders;
    $rpcHeaders[] = 'Content-Type: application/json';
    $replaceRes = supabase_request('POST', $rpcUrl, $rpcHeaders, json_encode([
        'p_event_id' => $eventId,
        'p_event_questions' => $questionSets['event_questions'],
        'p_seminar_questions' => $seminarPayloads,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if (!($replaceRes['ok'] ?? false)) {
        $body = (string) ($replaceRes['body'] ?? '');
        if (str_contains($body, 'FEEDBACK_EXISTS')) {
            json_response([
                'ok' => false,
                'error' => 'Cannot replace these questions because students have already submitted feedback. Existing feedback was kept safe.',
            ], 409);
        }
        if (str_contains($body, 'SEMINAR_STRUCTURE_MISMATCH')) {
            json_response(['ok' => false, 'error' => 'The preset seminar sections do not match this event. No questions were changed.'], 409);
        }
        if (str_contains($body, 'INVALID_QUESTION_SET')) {
            json_response(['ok' => false, 'error' => 'This preset contains invalid questions. No questions were changed.'], 400);
        }
        if (str_contains($body, 'EVENT_NOT_FOUND')) {
            json_response(['ok' => false, 'error' => 'Event not found. No questions were changed.'], 404);
        }
        json_response([
            'ok' => false,
            'error' => build_error($body, (int) ($replaceRes['status'] ?? 0), $replaceRes['error'] ?? null, 'Could not replace the event questions.'),
        ], 500);
    }

    $addedCount = question_preset_sets_count($questionSets);
    json_response([
        'ok' => true,
        'added' => $addedCount,
        'replaced' => true,
        'preset_name' => (string) ($preset['preset_name'] ?? ''),
    ]);
}

json_response(['ok' => false, 'error' => 'Invalid action.'], 400);
