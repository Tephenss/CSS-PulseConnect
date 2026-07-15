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
        'event_published', 'reg_open', 'proposal-documents', 'proposal_requirements_requested' => 'info',
        'reg_approved', 'certificate_ready', 'certificate' => 'success',
        'proposal-rejected', 'proposal_rejected' => 'warning',
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

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/user_notifications?on_conflict=user_id,dedupe_key';
    supabase_request('POST', $url, user_notification_headers(), $payload);
}

function dispatch_user_notifications(array $userIds, string $title, string $body, array $data = []): bool
{
    $userIds = array_values(array_unique(array_filter(array_map(
        static fn ($value): string => trim((string) $value),
        $userIds
    ))));

    if ($userIds === []) {
        return false;
    }

    persist_user_notifications($userIds, $title, $body, $data);

    $inList = '(' . implode(',', array_map('rawurlencode', $userIds)) . ')';
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
        return false;
    }

    $tokenRows = json_decode((string) ($tokensRes['body'] ?? ''), true);
    $tokens = [];
    if (is_array($tokenRows)) {
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

    if ($tokens === []) {
        return false;
    }

    return send_fcm_notification(array_keys($tokens), $title, $body, $data) === true;
}
