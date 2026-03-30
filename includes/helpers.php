<?php
declare(strict_types=1);

function clean_string(string $v): string
{
    return trim(preg_replace('/\s+/', ' ', $v) ?? '');
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

