<?php
declare(strict_types=1);

function clean_string(string $v): string
{
    return trim(preg_replace('/\s+/', ' ', $v) ?? '');
}

function clean_text(string $v): string
{
    return trim($v);
}

function format_date_local(?string $dateStr, string $format = 'M d, Y · g:i A'): string
{
    if (!$dateStr) return '';
    try {
        $dt = new DateTimeImmutable($dateStr);
        $dt = $dt->setTimezone(new DateTimeZone('Asia/Manila'));
        return $dt->format($format);
    } catch(Throwable $e) {
        return $dateStr;
    }
}

function build_display_name(string $first, string $middle, string $last, string $suffix): string
{
    $parts = [];
    if ($first !== '') {
        $parts[] = $first;
    }
    if ($middle !== '') {
        $parts[] = $middle;
    }
    if ($last !== '') {
        $parts[] = $last;
    }

    $base = implode(' ', $parts);
    if ($suffix !== '') {
        return $base . ', ' . $suffix;
    }

    return $base;
}

