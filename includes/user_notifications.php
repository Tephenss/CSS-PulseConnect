<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/fcm.php';

function user_notification_headers(): array
{
    return [
        'Content-Type: application/json',
        'Accept: application/json',
        'Prefer: resolution=merge-duplicates,return=minimal',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
}

function user_notification_dedupe_key(array $data): ?string
{
    $type = strtolower(trim((string) ($data['type'] ?? '')));
    $eventId = trim((string) ($data['event_id'] ?? ''));
    if ($type === '' || $eventId === '') {
        return null;
    }

    return $type . ':' . $eventId;
}

function user_notification_type_from_payload(array $data): string
{
    $type = strtolower(trim((string) ($data['type'] ?? 'info')));
    return match ($type) {
        'event_published', 'reg_open', 'reg_extended', 'proposal-documents', 'proposal_requirements_requested' => 'info',
        'reg_approved', 'certificate_ready', 'certificate' => 'success',
        'proposal-rejected', 'proposal_rejected', 'eval_open' => 'warning',
        default => 'info',
    };
}

function persist_user_notifications(array $userIds, string $title, string $body, array $data = []): void
{
    $userIds = array_values(array_unique(array_filter(array_map(
        static fn ($value): string => trim((string) $value),
        $userIds
    ))));

    if ($userIds === []) {
        return;
    }

    $eventId = trim((string) ($data['event_id'] ?? ''));
    $dedupeKey = user_notification_dedupe_key($data);
    $notificationType = user_notification_type_from_payload($data);
    $now = gmdate('c');

    $rows = [];
    foreach ($userIds as $userId) {
        $row = [
            'user_id' => $userId,
            'notification_type' => $notificationType,
            'title' => mb_substr(trim($title), 0, 180),
            'body' => mb_substr(trim($body), 0, 500),
            'data' => $data,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if ($eventId !== '') {
            $row['event_id'] = $eventId;
        }
        if ($dedupeKey !== null) {
            $row['dedupe_key'] = $dedupeKey;
        }
        $rows[] = $row;
    }

    $payload = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        return;
    }

    // Partial unique index (user_id, dedupe_key) WHERE dedupe_key IS NOT NULL
    // cannot be targeted by PostgREST on_conflict → 42P10 ERROR spam.
    // Delete matching dedupe rows then insert.
    $headers = user_notification_headers();
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $uid = trim((string) ($row['user_id'] ?? ''));
        $dedupe = trim((string) ($row['dedupe_key'] ?? ''));
        if ($uid !== '' && $dedupe !== '') {
            supabase_request(
                'DELETE',
                rtrim(SUPABASE_URL, '/') . '/rest/v1/user_notifications'
                    . '?user_id=eq.' . rawurlencode($uid)
                    . '&dedupe_key=eq.' . rawurlencode($dedupe),
                $headers
            );
        }
    }
    supabase_request(
        'POST',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/user_notifications',
        $headers,
        $payload
    );
}

function dispatch_user_notifications(array $userIds, string $title, string $body, array $data = []): bool
{
    $result = dispatch_user_notifications_detailed($userIds, $title, $body, $data);
    return !empty($result['fcm_ok']);
}

/**
 * @return array{targets:int,tokens:int,inbox:bool,fcm_ok:bool,error:?string}
 */
function dispatch_user_notifications_detailed(array $userIds, string $title, string $body, array $data = []): array
{
    $userIds = array_values(array_unique(array_filter(array_map(
        static fn ($value): string => trim((string) $value),
        $userIds
    ))));

    if ($userIds === []) {
        return [
            'targets' => 0,
            'tokens' => 0,
            'inbox' => false,
            'fcm_ok' => false,
            'error' => 'no_targets',
        ];
    }

    persist_user_notifications($userIds, $title, $body, $data);

    $tokens = [];
    $chunkSize = 80;
    for ($offset = 0; $offset < count($userIds); $offset += $chunkSize) {
        $chunk = array_slice($userIds, $offset, $chunkSize);
        $inList = '(' . implode(',', $chunk) . ')';
        $tokensRes = supabase_request(
            'GET',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/fcm_tokens?select=token&user_id=in.' . $inList,
            [
                'Accept: application/json',
                'apikey: ' . SUPABASE_KEY,
                'Authorization: Bearer ' . SUPABASE_KEY,
            ]
        );

        if (!($tokensRes['ok'] ?? false)) {
            error_log('dispatch_user_notifications: fcm_tokens lookup failed for chunk offset ' . $offset . ' body=' . substr((string) ($tokensRes['body'] ?? ''), 0, 300));
            continue;
        }

        $tokenRows = json_decode((string) ($tokensRes['body'] ?? ''), true);
        if (!is_array($tokenRows)) {
            continue;
        }
        foreach ($tokenRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $token = trim((string) ($row['token'] ?? ''));
            if ($token !== '') {
                $tokens[$token] = true;
            }
        }
    }

    $tokenCount = count($tokens);
    if ($tokenCount === 0) {
        // Inbox was already saved. No mobile tokens is normal for web-only users.
        error_log('dispatch_user_notifications: no FCM tokens for ' . count($userIds) . ' users (inbox still saved)');
        return [
            'targets' => count($userIds),
            'tokens' => 0,
            'inbox' => true,
            'fcm_ok' => false,
            'fcm_skipped' => true,
            'error' => 'no_fcm_tokens',
            'detail' => null,
            'http_status' => null,
        ];
    }

    $fcm = send_fcm_notification_detailed(array_keys($tokens), $title, $body, $data);
    $sent = (int) ($fcm['sent'] ?? 0);
    $failed = (int) ($fcm['failed'] ?? 0);
    $delivered = $sent > 0;
    return [
        'targets' => count($userIds),
        'tokens' => $tokenCount,
        'inbox' => true,
        'fcm_ok' => $delivered,
        'error' => $delivered ? null : (string) ($fcm['error'] ?? 'fcm_send_failed'),
        'detail' => $delivered ? null : ($fcm['detail'] ?? null),
        'http_status' => $fcm['http_status'] ?? null,
        'fcm_sent' => $sent,
        'fcm_failed' => $failed,
        'partial' => $delivered && $failed > 0,
    ];
}
