<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/student_roster.php';
require_once __DIR__ . '/../includes/event_targeting.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);

$user = mobile_api_require_user($data);
unset($user['_session_token'], $user['_session_id']);

// Keep session refreshes consistent with login: the users row may not contain
// the course/year for students whose academic data lives in student_roster.
if (strtolower(trim((string) ($user['role'] ?? ''))) === 'student') {
    $roster = student_roster_fetch_by_user_id((string) ($user['id'] ?? ''));
    if ($roster === null) {
        $studentNo = student_roster_normalize_no((string) ($user['student_id'] ?? ''));
        if ($studentNo !== '') {
            $roster = student_roster_fetch_by_no($studentNo);
        }
    }

    if ($roster !== null) {
        $user = event_targeting_apply_roster($user, $roster);
        $year = extract_student_year_level($user);
        if ($year !== '') {
            $user['year_level'] = $year;
        }
        $user['is_irregular'] = student_section_is_irregular($user) || !empty($roster['is_irregular']);
    }
}

json_response([
    'ok' => true,
    'user' => $user,
], 200);
