<?php
declare(strict_types=1);

/**
 * Resize and compress an uploaded image before sending it to Supabase Storage.
 * If GD is unavailable, returns the validated original bytes unchanged so
 * uploads keep working (Hostinger should enable GD for actual compression).
 *
 * @return array{bytes:string,mime:string,ext:string,compressed:bool,width:int,height:int}
 */
function image_upload_optimize(
    string $rawBytes,
    string $sourceMime,
    int $maxWidth,
    int $maxHeight,
    int $jpegQuality = 82
): array {
    $sourceMime = strtolower(trim($sourceMime));
    $originalExt = match ($sourceMime) {
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => 'jpg',
    };

    $info = @getimagesizefromstring($rawBytes);
    $srcWidth = is_array($info) ? (int) ($info[0] ?? 0) : 0;
    $srcHeight = is_array($info) ? (int) ($info[1] ?? 0) : 0;

    if (
        $rawBytes === ''
        || $srcWidth < 1
        || $srcHeight < 1
        || !function_exists('imagecreatefromstring')
        || !function_exists('imagecreatetruecolor')
        || !function_exists('imagejpeg')
    ) {
        return [
            'bytes' => $rawBytes,
            'mime' => $sourceMime,
            'ext' => $originalExt,
            'compressed' => false,
            'width' => $srcWidth,
            'height' => $srcHeight,
        ];
    }

    $source = @imagecreatefromstring($rawBytes);
    if ($source === false) {
        return [
            'bytes' => $rawBytes,
            'mime' => $sourceMime,
            'ext' => $originalExt,
            'compressed' => false,
            'width' => $srcWidth,
            'height' => $srcHeight,
        ];
    }

    $scale = min(
        1.0,
        max(64, $maxWidth) / $srcWidth,
        max(64, $maxHeight) / $srcHeight
    );
    $width = max(1, (int) round($srcWidth * $scale));
    $height = max(1, (int) round($srcHeight * $scale));
    $target = imagecreatetruecolor($width, $height);
    if ($target === false) {
        imagedestroy($source);
        return [
            'bytes' => $rawBytes,
            'mime' => $sourceMime,
            'ext' => $originalExt,
            'compressed' => false,
            'width' => $srcWidth,
            'height' => $srcHeight,
        ];
    }

    // JPEG has no alpha channel; use a white background for PNG/WEBP uploads.
    $white = imagecolorallocate($target, 255, 255, 255);
    imagefilledrectangle($target, 0, 0, $width, $height, $white);
    imagecopyresampled(
        $target,
        $source,
        0,
        0,
        0,
        0,
        $width,
        $height,
        $srcWidth,
        $srcHeight
    );
    imagedestroy($source);

    ob_start();
    $written = imagejpeg($target, null, max(40, min(92, $jpegQuality)));
    $optimized = ob_get_clean();
    imagedestroy($target);

    if (!$written || !is_string($optimized) || $optimized === '') {
        return [
            'bytes' => $rawBytes,
            'mime' => $sourceMime,
            'ext' => $originalExt,
            'compressed' => false,
            'width' => $srcWidth,
            'height' => $srcHeight,
        ];
    }

    // Never replace the original with a larger JPEG.
    if (strlen($optimized) >= strlen($rawBytes) && $scale >= 1.0) {
        return [
            'bytes' => $rawBytes,
            'mime' => $sourceMime,
            'ext' => $originalExt,
            'compressed' => false,
            'width' => $srcWidth,
            'height' => $srcHeight,
        ];
    }

    return [
        'bytes' => $optimized,
        'mime' => 'image/jpeg',
        'ext' => 'jpg',
        'compressed' => true,
        'width' => $width,
        'height' => $height,
    ];
}
