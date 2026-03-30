<?php
declare(strict_types=1);

function current_user(): ?array
{
    return (isset($_SESSION['user']) && is_array($_SESSION['user'])) ? $_SESSION['user'] : null;
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        header('Location: login.php?unauth=1');
        exit;
    }
    return $user;
}

function require_role(array $allowedRoles): array
{
    $user = require_login();
    $role = isset($user['role']) ? (string) $user['role'] : 'student';
    if (!in_array($role, $allowedRoles, true)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    return $user;
}

