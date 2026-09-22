<?php
declare(strict_types=1);

require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/student_requirements.php';
require_once __DIR__ . '/document_review_access.php';

const DOCUMENT_REVIEW_CLAIM_TTL_SECONDS = 480;
const DOCUMENT_REVIEW_CLAIM_MIGRATION_HINT = 'Run supabase/migrations/063_document_review_claim.sql';

function document_review_claim_column_missing(?string $body): bool
{
    $body = strtolower((string) $body);

    return str_contains($body, 'review_claimed_by') || str_contains($body, 'review_claimed_at');
}

function document_review_claim_cutoff_iso(): string
{
    return gmdate('Y-m-d\TH:i:s\Z', time() - DOCUMENT_REVIEW_CLAIM_TTL_SECONDS);
}

function document_review_claim_is_active(array $submission, ?int $now = null): bool
{
    $by = trim((string) ($submission['review_claimed_by'] ?? ''));
    if ($by === '') {
        return false;
    }
    $at = strtotime((string) ($submission['review_claimed_at'] ?? '')) ?: 0;
    if ($at <= 0) {
        return false;
    }
    $now = $now ?? time();

    return ($now - $at) < DOCUMENT_REVIEW_CLAIM_TTL_SECONDS;
}

function document_review_claim_held_by(array $submission, string $userId): bool
{
    if (!document_review_claim_is_active($submission)) {
        return false;
    }

    return trim((string) ($submission['review_claimed_by'] ?? '')) === trim($userId);
}

function document_review_in_review_message(array $submission): string
{
    $reviewerId = trim((string) ($submission['review_claimed_by'] ?? ''));
    $who = 'another teacher';
    if ($reviewerId !== '') {
        $profiles = document_review_load_user_profiles([$reviewerId]);
        if (isset($profiles[$reviewerId])) {
            $who = document_review_teacher_display_name($profiles[$reviewerId]);
        }
    }

    return $who . ' is already reviewing this student. Pick another pending submission, or wait until they finish.';
}

function document_review_claim_or_filter(string $userId): string
{
    $cutoff = document_review_claim_cutoff_iso();

    return 'or=(review_claimed_by.is.null,review_claimed_by.eq.' . rawurlencode($userId)
        . ',review_claimed_at.lt.' . rawurlencode('"' . $cutoff . '"') . ')';
}

/**
 * @return array{ok: bool, skipped?: bool, conflict?: bool, error?: string, submission?: array<string, mixed>|null}
 */
function document_review_claim_take(string $eventId, string $studentId, string $userId): array
{
    $headers = student_requirement_headers();
    $submission = fetch_student_submissions_map([$eventId], $headers, $studentId)[$eventId][$studentId] ?? null;
    if (!is_array($submission)) {
        return ['ok' => false, 'error' => 'No student submission found.'];
    }

    $status = strtolower(trim((string) ($submission['status'] ?? '')));
    if ($status !== 'pending_review') {
        return ['ok' => true, 'skipped' => true, 'submission' => $submission];
    }

    if (document_review_claim_held_by($submission, $userId)) {
        $heartbeat = document_review_claim_heartbeat($eventId, $studentId, $userId, $submission);
        if (($heartbeat['ok'] ?? false) || ($heartbeat['skipped'] ?? false)) {
            return ['ok' => true, 'submission' => $heartbeat['submission'] ?? $submission];
        }
    }

    $submissionId = trim((string) ($submission['id'] ?? ''));
    if ($submissionId === '') {
        return ['ok' => false, 'error' => 'Submission is missing an id.'];
    }

    $now = gmdate('c');
    $payload = json_encode([
        'review_claimed_by' => $userId,
        'review_claimed_at' => $now,
        'updated_at' => $now,
    ], JSON_UNESCAPED_SLASHES);

    $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_student_submissions'
        . '?id=eq.' . rawurlencode($submissionId)
        . '&status=eq.pending_review'
        . '&' . document_review_claim_or_filter($userId);
    $patchRes = supabase_request('PATCH', $patchUrl, student_requirement_write_headers(), $payload);
    if (!($patchRes['ok'] ?? false)) {
        if (document_review_claim_column_missing((string) ($patchRes['body'] ?? ''))) {
            return ['ok' => true, 'skipped' => true, 'error' => DOCUMENT_REVIEW_CLAIM_MIGRATION_HINT, 'submission' => $submission];
        }
        return [
            'ok' => false,
            'error' => build_error(
                $patchRes['body'] ?? null,
                (int) ($patchRes['status'] ?? 0),
                $patchRes['error'] ?? null,
                'Failed to claim submission.'
            ),
        ];
    }

    $updated = json_decode((string) ($patchRes['body'] ?? ''), true);
    if (is_array($updated) && isset($updated[0]) && is_array($updated[0])) {
        return ['ok' => true, 'submission' => $updated[0]];
    }

    $fresh = fetch_student_submissions_map([$eventId], $headers, $studentId)[$eventId][$studentId] ?? $submission;
    if (is_array($fresh) && document_review_claim_held_by($fresh, $userId)) {
        return ['ok' => true, 'submission' => $fresh];
    }

    return [
        'ok' => false,
        'conflict' => true,
        'error' => document_review_in_review_message(is_array($fresh) ? $fresh : $submission),
        'submission' => is_array($fresh) ? $fresh : $submission,
    ];
}

/**
 * @param array<string, mixed>|null $submission
 * @return array{ok: bool, skipped?: bool, error?: string, submission?: array<string, mixed>|null}
 */
function document_review_claim_heartbeat(string $eventId, string $studentId, string $userId, ?array $submission = null): array
{
    $headers = student_requirement_headers();
    if (!is_array($submission)) {
        $submission = fetch_student_submissions_map([$eventId], $headers, $studentId)[$eventId][$studentId] ?? null;
    }
    if (!is_array($submission)) {
        return ['ok' => false, 'error' => 'No student submission found.'];
    }

    $submissionId = trim((string) ($submission['id'] ?? ''));
    if ($submissionId === '') {
        return ['ok' => false, 'error' => 'Submission is missing an id.'];
    }

    $now = gmdate('c');
    $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_student_submissions'
        . '?id=eq.' . rawurlencode($submissionId)
        . '&status=eq.pending_review'
        . '&review_claimed_by=eq.' . rawurlencode($userId);
    $patchRes = supabase_request(
        'PATCH',
        $patchUrl,
        student_requirement_write_headers(),
        json_encode(['review_claimed_at' => $now, 'updated_at' => $now], JSON_UNESCAPED_SLASHES)
    );
    if (!($patchRes['ok'] ?? false)) {
        if (document_review_claim_column_missing((string) ($patchRes['body'] ?? ''))) {
            return ['ok' => true, 'skipped' => true, 'submission' => $submission];
        }
        return ['ok' => false, 'error' => 'Failed to keep review lock.'];
    }

    $updated = json_decode((string) ($patchRes['body'] ?? ''), true);
    if (is_array($updated) && isset($updated[0]) && is_array($updated[0])) {
        return ['ok' => true, 'submission' => $updated[0]];
    }

    return ['ok' => false, 'conflict' => true, 'error' => 'This review lock is no longer yours.', 'submission' => $submission];
}

/**
 * @return array{ok: bool, skipped?: bool, error?: string}
 */
function document_review_claim_release(string $eventId, string $studentId, string $userId): array
{
    $headers = student_requirement_headers();
    $submission = fetch_student_submissions_map([$eventId], $headers, $studentId)[$eventId][$studentId] ?? null;
    if (!is_array($submission)) {
        return ['ok' => true, 'skipped' => true];
    }

    $claimedBy = trim((string) ($submission['review_claimed_by'] ?? ''));
    if ($claimedBy === '' || $claimedBy !== $userId) {
        return ['ok' => true, 'skipped' => true];
    }

    $submissionId = trim((string) ($submission['id'] ?? ''));
    if ($submissionId === '') {
        return ['ok' => true, 'skipped' => true];
    }

    $now = gmdate('c');
    $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_student_submissions'
        . '?id=eq.' . rawurlencode($submissionId)
        . '&review_claimed_by=eq.' . rawurlencode($userId);
    $patchRes = supabase_request(
        'PATCH',
        $patchUrl,
        student_requirement_write_headers(),
        json_encode([
            'review_claimed_by' => null,
            'review_claimed_at' => null,
            'updated_at' => $now,
        ], JSON_UNESCAPED_SLASHES)
    );
    if (!($patchRes['ok'] ?? false) && document_review_claim_column_missing((string) ($patchRes['body'] ?? ''))) {
        return ['ok' => true, 'skipped' => true];
    }

    return ['ok' => true];
}

function document_review_decision_patch_url(string $submissionId, string $reviewerId, bool $withClaimFilter): string
{
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_student_submissions'
        . '?id=eq.' . rawurlencode($submissionId)
        . '&status=eq.pending_review';
    if ($withClaimFilter) {
        $url .= '&' . document_review_claim_or_filter($reviewerId);
    }

    return $url;
}
