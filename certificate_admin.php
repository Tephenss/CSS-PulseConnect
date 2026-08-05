<?php
declare(strict_types=1);
// Legacy URL — certificate editor is teacher-facing now.
$query = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== ''
    ? ('?' . $_SERVER['QUERY_STRING'])
    : '';
header('Location: /certificate_editor' . $query, true, 301);
exit;
