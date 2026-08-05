<?php
declare(strict_types=1);
// Legacy URL — Cert Templates is teacher-facing now.
$query = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== ''
    ? ('?' . $_SERVER['QUERY_STRING'])
    : '';
header('Location: /certificates_library' . $query, true, 301);
exit;
