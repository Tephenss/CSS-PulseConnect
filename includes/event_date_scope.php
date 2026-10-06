<?php
declare(strict_types=1);

/** Return the current calendar year and the following calendar year as one date scope. */
function event_date_scope_window(?DateTimeImmutable $now = null): array
{
    $timezone = new DateTimeZone('Asia/Manila');
    $now = ($now ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone);
    $startYear = (int) $now->format('Y');
    $endYear = $startYear + 1;
    $start = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $startYear), $timezone);
    $end = new DateTimeImmutable(sprintf('%04d-12-31 23:59:59', $endYear), $timezone);

    return [
        'label' => $startYear . '-' . $endYear,
        'start_year' => $startYear,
        'end_year' => $endYear,
        'start' => $start,
        'end' => $end,
    ];
}

function event_datetime_is_in_date_scope(DateTimeImmutable $date): bool
{
    $window = event_date_scope_window();
    $localDate = $date->setTimezone(new DateTimeZone('Asia/Manila'));

    return $localDate >= $window['start'] && $localDate <= $window['end'];
}

function event_date_scope_error(): string
{
    $window = event_date_scope_window();

    return 'Event dates must be within the 2-year scope (January 1, ' . $window['start_year']
        . ' to December 31, ' . $window['end_year'] . ').';
}
