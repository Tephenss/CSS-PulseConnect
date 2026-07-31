<?php
declare(strict_types=1);

/**
 * Mobile attendance write path: Firestore ingress → Supabase (with file queue fallback).
 */
require_once __DIR__ . '/scan_write_queue.php';
require_once __DIR__ . '/firestore_scan_middleware.php';

/**
 * Execute an attendance write through Firebase ingress middleware, then Supabase.
 *
 * @param array{type:string,url:string,method:string,headers:array,body:string,meta?:array} $job
 * @return array{ok:bool,queued?:bool,body?:mixed,error?:string,queue_id?:string,status?:string,middleware?:string}
 */
function mobile_attendance_write_guarded(
    string $eventId,
    string $scanKind,
    string $subjectKey,
    array $job
): array {
    $eventId = trim($eventId);
    if ($eventId === '') {
        return scan_write_attempt_or_queue($job);
    }

    if (firestore_scan_should_throttle($eventId)) {
        return [
            'ok' => false,
            'error' => 'Check-in is busy right now. Please try again in a few seconds.',
            'status' => 'throttled',
        ];
    }

    $subjectHash = firestore_scan_subject_hash($subjectKey);
    $ingressId = firestore_scan_record_ingress($eventId, $scanKind, $subjectHash);

    $outcome = scan_write_attempt_or_queue($job);
    $ok = ($outcome['ok'] ?? false) === true;

    if ($ok) {
        $queued = !empty($outcome['queued']);
        firestore_scan_complete_ingress(
            $ingressId,
            $eventId,
            $queued ? 'queued' : 'completed'
        );
        $outcome['middleware'] = $queued ? 'firebase_queued_supabase' : 'firebase_supabase';
        return $outcome;
    }

    firestore_scan_complete_ingress($ingressId, $eventId, 'failed');
    return $outcome;
}

/**
 * @return array{ok:bool,error?:string,status?:string}
 */
function mobile_attendance_require_write(array $outcome, string $fallbackError): array
{
    if (($outcome['ok'] ?? false) === true) {
        return ['ok' => true];
    }
    return [
        'ok' => false,
        'error' => (string) ($outcome['error'] ?? $fallbackError),
        'status' => (string) ($outcome['status'] ?? 'error'),
    ];
}

function mobile_attendance_queued_suffix(array $outcome): string
{
    return !empty($outcome['queued']) ? ' (syncing in background)' : '';
}
