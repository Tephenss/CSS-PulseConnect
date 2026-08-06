<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$outPath = $root . DIRECTORY_SEPARATOR . 'favicon.ico';

$sources = [
    $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'favicon-32.png',
    $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'favicon-48.png',
];

$images = [];
foreach ($sources as $srcPath) {
    if (!is_file($srcPath)) {
        fwrite(STDERR, "Missing {$srcPath}\n");
        exit(1);
    }
    $png = file_get_contents($srcPath);
    if ($png === false || $png === '') {
        fwrite(STDERR, "Empty {$srcPath}\n");
        exit(1);
    }
    $info = @getimagesize($srcPath);
    $size = is_array($info) ? (int) ($info[0] ?? 0) : 0;
    if ($size <= 0) {
        $size = 32;
    }
    $images[] = ['size' => $size, 'data' => $png];
}

$count = count($images);
$offset = 6 + (16 * $count);
$dir = '';
$payload = '';
foreach ($images as $img) {
    $size = (int) $img['size'];
    $data = (string) $img['data'];
    $dir .= pack(
        'CCCCvvVV',
        $size >= 256 ? 0 : $size,
        $size >= 256 ? 0 : $size,
        0,
        0,
        1,
        32,
        strlen($data),
        $offset
    );
    $offset += strlen($data);
    $payload .= $data;
}

$ico = pack('vvv', 0, 1, $count) . $dir . $payload;
if (file_put_contents($outPath, $ico) === false) {
    fwrite(STDERR, "Failed writing favicon.ico\n");
    exit(1);
}

echo 'Wrote ' . strlen($ico) . " bytes → {$outPath}\n";
