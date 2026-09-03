<?php
declare(strict_types=1);

/**
 * Echo CCS favicon / apple-touch-icon link tags (cache-busted).
 * Inline PNG first so the tab icon appears while HTML/CSS is still loading.
 */
function favicon_inline_data_uri(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $root = dirname(__DIR__);
    $candidates = [
        $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'favicon-32.png',
        $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'favicon-48.png',
        $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'CCS.png',
    ];

    foreach ($candidates as $path) {
        if (!is_file($path)) {
            continue;
        }
        $bytes = file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            continue;
        }
        $cached = 'data:image/png;base64,' . base64_encode($bytes);
        return $cached;
    }

    $cached = '';
    return $cached;
}

function render_favicon_tags(): void
{
    $root = dirname(__DIR__);
    $versionFor = static function (string $relativePath) use ($root): string {
        $full = $root . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        return is_file($full) ? (string) max(1, (int) @filemtime($full)) : '1';
    };

    $inline = favicon_inline_data_uri();
    if ($inline !== '') {
        echo '<link rel="icon" type="image/png" href="' . htmlspecialchars($inline, ENT_QUOTES, 'UTF-8') . '"/>';
    }

    $icoV = $versionFor('/favicon.ico');
    $png32 = '/assets/favicon-32.png';
    $png48 = '/assets/favicon-48.png';
    $apple = '/assets/apple-touch-icon.png';
    $fallback = '/assets/CCS.png';

    if (is_file($root . DIRECTORY_SEPARATOR . 'favicon.ico')) {
        $icoHref = htmlspecialchars('/favicon.ico?v=' . $icoV, ENT_QUOTES, 'UTF-8');
        echo '<link rel="icon" href="' . $icoHref . '" sizes="any"/>';
        echo '<link rel="shortcut icon" href="' . $icoHref . '"/>';
    }

    if (is_file($root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'favicon-32.png')) {
        $href = htmlspecialchars($png32 . '?v=' . $versionFor($png32), ENT_QUOTES, 'UTF-8');
        echo '<link rel="icon" type="image/png" sizes="32x32" href="' . $href . '"/>';
    }
    if (is_file($root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'favicon-48.png')) {
        $href = htmlspecialchars($png48 . '?v=' . $versionFor($png48), ENT_QUOTES, 'UTF-8');
        echo '<link rel="icon" type="image/png" sizes="48x48" href="' . $href . '"/>';
    } else {
        $href = htmlspecialchars($fallback . '?v=' . $versionFor($fallback), ENT_QUOTES, 'UTF-8');
        echo '<link rel="icon" type="image/png" href="' . $href . '"/>';
    }

    $appleFile = $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'apple-touch-icon.png';
    $appleHref = is_file($appleFile)
        ? htmlspecialchars($apple . '?v=' . $versionFor($apple), ENT_QUOTES, 'UTF-8')
        : htmlspecialchars($fallback . '?v=' . $versionFor($fallback), ENT_QUOTES, 'UTF-8');
    echo '<link rel="apple-touch-icon" href="' . $appleHref . '"/>';
}
