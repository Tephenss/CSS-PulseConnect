<?php
declare(strict_types=1);

/**
 * Build an editable PPTX from a Fabric.js canvas JSON so text/logos
 * remain real PowerPoint objects (not a flat screenshot).
 */

require_once __DIR__ . '/certificate_code_extract.php';

function certificate_pptx_normalize_png(string $imageBinary): string
{
    if ($imageBinary === '') {
        throw new InvalidArgumentException('Image data is empty.');
    }
    if (str_starts_with($imageBinary, "\x89PNG\r\n\x1a\n")) {
        return $imageBinary;
    }
    if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
        throw new RuntimeException('GD extension is required to convert images for PPTX export.');
    }
    $img = @imagecreatefromstring($imageBinary);
    if ($img === false) {
        throw new InvalidArgumentException('Unsupported or corrupt image data for PPTX export.');
    }
    if (function_exists('imagealphablending') && function_exists('imagesavealpha')) {
        imagealphablending($img, false);
        imagesavealpha($img, true);
    }
    ob_start();
    $ok = imagepng($img, null, 6);
    imagedestroy($img);
    $png = (string) ob_get_clean();
    if (!$ok || $png === '' || !str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
        throw new RuntimeException('Failed to encode PNG for PPTX export.');
    }
    return $png;
}

/**
 * Prefer keeping JPEG/PNG as-is (no GD re-encode). Convert only when needed.
 *
 * @return array{ext:string,binary:string} ext is png|jpeg
 */
function certificate_pptx_media_from_data_url(string $dataUrl, bool $forcePng = false): array
{
    $dataUrl = trim($dataUrl);
    if (preg_match('#^data:image/([a-zA-Z0-9.+-]+);base64,#i', $dataUrl, $m) !== 1) {
        throw new InvalidArgumentException('Invalid image data URL.');
    }
    $mime = strtolower((string) $m[1]);
    $b64 = substr($dataUrl, (int) strpos($dataUrl, ',') + 1);
    $bin = base64_decode($b64, true);
    if ($bin === false || $bin === '') {
        throw new InvalidArgumentException('Unable to decode image data.');
    }
    if (!$forcePng && ($mime === 'jpeg' || $mime === 'jpg') && str_starts_with($bin, "\xFF\xD8")) {
        return ['ext' => 'jpeg', 'binary' => $bin];
    }
    if (!$forcePng && $mime === 'png' && str_starts_with($bin, "\x89PNG")) {
        return ['ext' => 'png', 'binary' => $bin];
    }
    return ['ext' => 'png', 'binary' => certificate_pptx_normalize_png($bin)];
}

function certificate_pptx_png_from_data_url(string $dataUrl): string
{
    $media = certificate_pptx_media_from_data_url($dataUrl, true);
    return $media['binary'];
}

function certificate_pptx_xml_text(string $text): string
{
    // OOXML forbids control chars except tab/lf/cr in <a:t>.
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function certificate_pptx_font_family(string $font): string
{
    $font = trim($font, " \t\"'");
    if ($font === '') {
        return 'Calibri';
    }
    // Strip CSS fallbacks: "Inter, sans-serif" → Inter
    if (str_contains($font, ',')) {
        $font = trim(explode(',', $font, 2)[0], " \t\"'");
    }
    $key = strtolower($font);
    // Web fonts → closest fonts commonly installed with Windows/Office.
    $map = [
        'inter' => 'Arial',
        'roboto' => 'Arial',
        'open sans' => 'Arial',
        'montserrat' => 'Arial',
        'poppins' => 'Arial',
        'lato' => 'Arial',
        'nunito' => 'Arial',
        'source sans pro' => 'Arial',
        'helvetica' => 'Arial',
        'helvetica neue' => 'Arial',
        'system-ui' => 'Arial',
        'ui-sans-serif' => 'Arial',
        'sans-serif' => 'Arial',
        'serif' => 'Times New Roman',
        'monospace' => 'Courier New',
    ];
    if (isset($map[$key])) {
        return $map[$key];
    }
    // Keep known Office-safe faces as-is.
    return preg_replace('/[^\w\s\-]/', '', $font) ?: 'Arial';
}

/**
 * If text is "_____\\nAuthorized Signature", emit a real line + label (avoids stray underscore artifacts).
 *
 * @return null|array{line:bool,label:string}
 */
function certificate_pptx_parse_signature_text(string $text): ?array
{
    $text = str_replace("\r\n", "\n", $text);
    $text = str_replace("\r", "\n", $text);
    // Em/en dashes via \x{...} (PCRE); \uXXXX is invalid in PHP.
    if (!preg_match('/^([_\x{2014}\x{2013}\-]{6,})\s*\n+(.+)$/u', trim($text), $m)) {
        return null;
    }
    $label = trim((string) ($m[2] ?? ''));
    if ($label === '') {
        return null;
    }
    return ['line' => true, 'label' => $label];
}

function certificate_pptx_color_hex(?string $fill): string
{
    $fill = trim((string) $fill);
    if (preg_match('/^#([0-9a-fA-F]{6})$/', $fill, $m)) {
        return strtoupper($m[1]);
    }
    if (preg_match('/^#([0-9a-fA-F]{3})$/', $fill, $m)) {
        $h = $m[1];
        return strtoupper($h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2]);
    }
    if (preg_match('/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i', $fill, $m)) {
        return sprintf('%02X%02X%02X', (int) $m[1], (int) $m[2], (int) $m[3]);
    }
    return '111827';
}

/**
 * @return array{left:float,top:float,width:float,height:float,angle:float}
 */
function certificate_pptx_fabric_box(array $obj): array
{
    $scaleX = (float) ($obj['scaleX'] ?? 1);
    $scaleY = (float) ($obj['scaleY'] ?? 1);
    $width = (float) ($obj['width'] ?? 0) * $scaleX;
    $height = (float) ($obj['height'] ?? 0) * $scaleY;
    // Prefer Fabric's absolute bounding box when present.
    if (isset($obj['aCoords']) && is_array($obj['aCoords'])) {
        $xs = [];
        $ys = [];
        foreach (['tl', 'tr', 'br', 'bl'] as $k) {
            if (!isset($obj['aCoords'][$k]) || !is_array($obj['aCoords'][$k])) {
                continue;
            }
            $xs[] = (float) ($obj['aCoords'][$k]['x'] ?? 0);
            $ys[] = (float) ($obj['aCoords'][$k]['y'] ?? 0);
        }
        if (count($xs) >= 2 && count($ys) >= 2) {
            $minX = min($xs);
            $maxX = max($xs);
            $minY = min($ys);
            $maxY = max($ys);
            return [
                'left' => $minX,
                'top' => $minY,
                'width' => max(1.0, $maxX - $minX),
                'height' => max(1.0, $maxY - $minY),
                'angle' => 0.0, // already baked into aCoords
            ];
        }
    }

    $left = (float) ($obj['left'] ?? 0);
    $top = (float) ($obj['top'] ?? 0);
    $originX = (string) ($obj['originX'] ?? 'left');
    $originY = (string) ($obj['originY'] ?? 'top');
    if ($originX === 'center') {
        $left -= $width / 2;
    } elseif ($originX === 'right') {
        $left -= $width;
    }
    if ($originY === 'center') {
        $top -= $height / 2;
    } elseif ($originY === 'bottom') {
        $top -= $height;
    }
    return [
        'left' => $left,
        'top' => $top,
        'width' => max(1.0, $width),
        'height' => max(1.0, $height),
        'angle' => (float) ($obj['angle'] ?? 0),
    ];
}

/**
 * @param list<array<string,mixed>> $objects
 * @return list<array<string,mixed>>
 */
function certificate_pptx_flatten_fabric_objects(array $objects): array
{
    $out = [];
    foreach ($objects as $obj) {
        if (!is_array($obj)) {
            continue;
        }
        $type = strtolower((string) ($obj['type'] ?? ''));
        if ($type === 'group' && isset($obj['objects']) && is_array($obj['objects'])) {
            // Groups are complex; flatten children with approximate absolute offsets.
            $gLeft = (float) ($obj['left'] ?? 0);
            $gTop = (float) ($obj['top'] ?? 0);
            foreach ($obj['objects'] as $child) {
                if (!is_array($child)) {
                    continue;
                }
                $child['left'] = (float) ($child['left'] ?? 0) + $gLeft;
                $child['top'] = (float) ($child['top'] ?? 0) + $gTop;
                $out[] = $child;
            }
            continue;
        }
        $out[] = $obj;
    }
    return $out;
}

/**
 * @return array{ext:string,binary:string}|null
 */
function certificate_pptx_resolve_image_media(array $obj): ?array
{
    $candidates = [];
    foreach (['src', 'source'] as $k) {
        if (!empty($obj[$k]) && is_string($obj[$k])) {
            $candidates[] = $obj[$k];
        }
    }
    if (isset($obj['_originalElement']) && is_array($obj['_originalElement']) && !empty($obj['_originalElement']['src'])) {
        $candidates[] = (string) $obj['_originalElement']['src'];
    }

    foreach ($candidates as $src) {
        $src = trim((string) $src);
        if ($src === '') {
            continue;
        }
        try {
            if (str_starts_with($src, 'data:image/')) {
                return certificate_pptx_media_from_data_url($src, false);
            }
            if (preg_match('#^https?://#i', $src) === 1) {
                $bin = @file_get_contents($src);
                if (!is_string($bin) || $bin === '') {
                    continue;
                }
                if (str_starts_with($bin, "\xFF\xD8")) {
                    return ['ext' => 'jpeg', 'binary' => $bin];
                }
                if (str_starts_with($bin, "\x89PNG")) {
                    return ['ext' => 'png', 'binary' => $bin];
                }
                return ['ext' => 'png', 'binary' => certificate_pptx_normalize_png($bin)];
            }
        } catch (Throwable $e) {
            continue;
        }
    }
    return null;
}

function certificate_pptx_resolve_image_binary(array $obj): ?string
{
    $media = certificate_pptx_resolve_image_media($obj);
    return $media['binary'] ?? null;
}

/** EMU per canvas pixel (96dpi). */
function certificate_pptx_emu_per_px(): float
{
    return 9525.0;
}

function certificate_pptx_new_fabric_id(): string
{
    try {
        return 'pc_' . bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        return 'pc_' . substr(sha1(uniqid((string) mt_rand(), true)), 0, 16);
    }
}

/**
 * Ensure a Fabric object has a stable id for PPTX round-trip.
 */
function certificate_pptx_ensure_object_id(array &$obj): string
{
    $id = trim((string) ($obj['id'] ?? ''));
    if ($id === '') {
        $id = certificate_pptx_new_fabric_id();
        $obj['id'] = $id;
    }
    return $id;
}

/**
 * Build <p:cNvPr …/> with optional descr="pc:{fabricId}" for re-import.
 */
function certificate_pptx_cnvpr_xml(int $numericId, string $name, string $fabricId = ''): string
{
    $nameEsc = htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $descr = '';
    if ($fabricId !== '') {
        $descrEsc = htmlspecialchars('pc:' . $fabricId, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $descr = ' descr="' . $descrEsc . '"';
    }
    return '<p:cNvPr id="' . $numericId . '" name="' . $nameEsc . '"' . $descr . '/>';
}

/**
 * Emit a horizontal signature line as a thin filled rectangle.
 * Avoids <p:sp prst="line"> + txBody, which PowerPoint flags as corrupt.
 */
function certificate_pptx_line_shape_xml(
    int $id,
    int $x,
    int $y,
    int $w,
    string $strokeHex = '111827',
    int $strokeEmu = 19050,
    string $fabricId = ''
): string {
    $h = max(9525, (int) round($strokeEmu)); // ~1–2px at 96dpi
    $w = max(1, $w);
    $x = max(0, $x);
    $y = max(0, $y);
    $cnvPr = certificate_pptx_cnvpr_xml($id, 'Line ' . $id, $fabricId !== '' ? $fabricId . ':line' : '');
    return <<<XML
      <p:sp>
        <p:nvSpPr>
          {$cnvPr}
          <p:cNvSpPr><a:spLocks noTextEdit="1"/></p:cNvSpPr>
          <p:nvPr/>
        </p:nvSpPr>
        <p:spPr>
          <a:xfrm>
            <a:off x="{$x}" y="{$y}"/>
            <a:ext cx="{$w}" cy="{$h}"/>
          </a:xfrm>
          <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
          <a:solidFill><a:srgbClr val="{$strokeHex}"/></a:solidFill>
          <a:ln><a:noFill/></a:ln>
        </p:spPr>
        <p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="en-US"/></a:p></p:txBody>
      </p:sp>
XML;
}

/**
 * @param array<string,mixed> $canvasState Fabric toJSON() payload
 * @param callable|null $onProgress function(int $pct, string $label): void
 */
function certificate_pptx_build_from_fabric(
    array $canvasState,
    string $title = 'Certificate Template',
    string $templateId = '',
    ?callable $onProgress = null
): string {
    $progress = static function (int $pct, string $label) use ($onProgress): void {
        if ($onProgress === null) {
            return;
        }
        try {
            $onProgress(max(1, min(99, $pct)), $label);
        } catch (Throwable $e) {
            // Ignore progress callback failures.
        }
    };

    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive extension is required to export PPTX.');
    }

    $progress(18, 'Measuring canvas…');

    $canvasW = (float) ($canvasState['width'] ?? 1123);
    $canvasH = (float) ($canvasState['height'] ?? 794);
    if ($canvasW < 10) {
        $canvasW = 1123;
    }
    if ($canvasH < 10) {
        $canvasH = 794;
    }

    // Match canvas pixel aspect exactly (A4 landscape 1123×794 → real A4 EMUs @ 96dpi).
    $emuPerPx = certificate_pptx_emu_per_px();
    $slideW = max(1, (int) round($canvasW * $emuPerPx));
    $slideH = max(1, (int) round($canvasH * $emuPerPx));
    $pxToEmu = static fn (float $px): int => (int) round($px * $emuPerPx);

    $shapeXml = [];
    $mediaFiles = []; // path => binary
    $mediaExtByIndex = []; // imageIndex => png|jpeg
    $nextId = 2; // 1 reserved for group
    $imageIndex = 0;
    $hasBgPicture = false;
    $bgCnvPr = static function (int $nid) use (&$nextId): string {
        return certificate_pptx_cnvpr_xml($nid, 'Background', 'background');
    };
    $addMedia = static function (array $media) use (&$mediaFiles, &$mediaExtByIndex, &$imageIndex): string {
        $ext = (($media['ext'] ?? '') === 'jpeg') ? 'jpeg' : 'png';
        $imageIndex++;
        $mediaExtByIndex[$imageIndex] = $ext;
        $mediaFiles['ppt/media/image' . $imageIndex . '.' . $ext] = $media['binary'];
        return 'rIdImg' . $imageIndex;
    };

    $progress(22, 'Embedding background…');

    // Prefer rasterized background from the editor (most reliable for Canva-style BGs).
    $bgDataUrl = trim((string) ($canvasState['background_data_url'] ?? ''));
    if ($bgDataUrl !== '' && str_starts_with($bgDataUrl, 'data:image/')) {
        try {
            $media = certificate_pptx_media_from_data_url($bgDataUrl, false);
            $rid = $addMedia($media);
            $cnv = $bgCnvPr($nextId);
            $shapeXml[] = <<<XML
      <p:pic>
        <p:nvPicPr>
          {$cnv}
          <p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr>
          <p:nvPr/>
        </p:nvPicPr>
        <p:blipFill>
          <a:blip r:embed="{$rid}"/>
          <a:stretch><a:fillRect/></a:stretch>
        </p:blipFill>
        <p:spPr>
          <a:xfrm>
            <a:off x="0" y="0"/>
            <a:ext cx="{$slideW}" cy="{$slideH}"/>
          </a:xfrm>
          <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
        </p:spPr>
      </p:pic>
XML;
            $nextId++;
            $hasBgPicture = true;
        } catch (Throwable $e) {
            // Fall through to Fabric backgroundImage / solid fill.
        }
    }

    // Fallback: Fabric backgroundImage object (force full-slide, ignore broken geometry).
    if (!$hasBgPicture && isset($canvasState['backgroundImage']) && is_array($canvasState['backgroundImage'])) {
        $media = certificate_pptx_resolve_image_media($canvasState['backgroundImage']);
        if ($media !== null) {
            $rid = $addMedia($media);
            $cnv = $bgCnvPr($nextId);
            $shapeXml[] = <<<XML
      <p:pic>
        <p:nvPicPr>
          {$cnv}
          <p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr>
          <p:nvPr/>
        </p:nvPicPr>
        <p:blipFill>
          <a:blip r:embed="{$rid}"/>
          <a:stretch><a:fillRect/></a:stretch>
        </p:blipFill>
        <p:spPr>
          <a:xfrm>
            <a:off x="0" y="0"/>
            <a:ext cx="{$slideW}" cy="{$slideH}"/>
          </a:xfrm>
          <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
        </p:spPr>
      </p:pic>
XML;
            $nextId++;
            $hasBgPicture = true;
        }
    }

    // Solid canvas fill only when there is no background picture (avoids the "white box" users drag).
    if (!$hasBgPicture) {
        $bg = $canvasState['background'] ?? ($canvasState['backgroundColor'] ?? null);
        if (is_string($bg) && trim($bg) !== '' && strtolower(trim($bg)) !== 'transparent') {
            $hex = certificate_pptx_color_hex($bg);
            $cnv = $bgCnvPr($nextId);
            $shapeXml[] = <<<XML
      <p:sp>
        <p:nvSpPr>
          {$cnv}
          <p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr>
          <p:nvPr/>
        </p:nvSpPr>
        <p:spPr>
          <a:xfrm>
            <a:off x="0" y="0"/>
            <a:ext cx="{$slideW}" cy="{$slideH}"/>
          </a:xfrm>
          <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
          <a:solidFill><a:srgbClr val="{$hex}"/></a:solidFill>
          <a:ln><a:noFill/></a:ln>
        </p:spPr>
        <p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="en-US"/></a:p></p:txBody>
      </p:sp>
XML;
            $nextId++;
        }
    }

    $objects = [];
    if (isset($canvasState['objects']) && is_array($canvasState['objects'])) {
        foreach (certificate_pptx_flatten_fabric_objects($canvasState['objects']) as $obj) {
            $objects[] = $obj;
        }
    }
    if ($objects === [] && $shapeXml === []) {
        throw new InvalidArgumentException('Canvas has no objects to export. Add text/images first.');
    }

    $objectTotal = max(1, count($objects));
    $objectDone = 0;
    $exportedCertificateCode = false;
    $progress(30, 'Placing text and images…');

    foreach ($objects as $obj) {
        try {
        if (!is_array($obj)) {
            continue;
        }
        if (array_key_exists('visible', $obj) && $obj['visible'] === false) {
            continue;
        }
        $fabricId = certificate_pptx_ensure_object_id($obj);
        $type = strtolower((string) ($obj['type'] ?? ''));
        $objName = strtolower(trim((string) ($obj['name'] ?? '')));
        $isCertCode = ($fabricId === 'certificate_code' || $objName === 'certificate code');
        if ($isCertCode) {
            // Stable id so Import can match PPT → Canva exactly.
            $obj['id'] = 'certificate_code';
            $obj['name'] = 'Certificate Code';
            $fabricId = 'certificate_code';
            $exportedCertificateCode = true;
        }
        $box = certificate_pptx_fabric_box($obj);
        $alignForClamp = 'left';
        if (in_array($type, ['i-text', 'text', 'textbox'], true)) {
            $rawTextEarly = (string) ($obj['text'] ?? '');
            $fontSizePxEarly = (float) ($obj['fontSize'] ?? 24) * (float) ($obj['scaleY'] ?? 1);
            $alignForClamp = strtolower((string) ($obj['textAlign'] ?? 'left'));
            if (!in_array($alignForClamp, ['left', 'center', 'right', 'justify'], true)) {
                $alignForClamp = 'left';
            }
            // Shrink oversized cert-code textboxes before slide-edge clamp so Import
            // doesn't get a sliver frame parked on the right (clipped "LU-AA-…").
            if ($isCertCode && $rawTextEarly !== '' && function_exists('certificate_pptx_fit_code_box')) {
                $fit = certificate_pptx_fit_code_box(
                    (float) $box['left'],
                    (float) $box['top'],
                    (float) $box['width'],
                    (float) $box['height'],
                    $rawTextEarly,
                    $fontSizePxEarly,
                    $alignForClamp,
                    $canvasW
                );
                $box['left'] = $fit['left'];
                $box['top'] = $fit['top'];
                $box['width'] = $fit['width'];
                $box['height'] = $fit['height'];
                $alignForClamp = $fit['textAlign'];
            }
        }
        $x = max(0, $pxToEmu($box['left']));
        $y = max(0, $pxToEmu($box['top']));
        $w = max(1, $pxToEmu($box['width']));
        $h = max(1, $pxToEmu($box['height']));
        if ($x + $w > $slideW) {
            $origRight = $x + $w;
            $origCenter = $x + (int) round($w / 2);
            if ($alignForClamp === 'right') {
                $w = max(1, min($w, $slideW));
                $x = max(0, ($origRight <= $slideW ? $origRight : $slideW) - $w);
            } elseif ($alignForClamp === 'center') {
                $w = max(1, min($w, $slideW));
                $x = max(0, min($slideW - $w, $origCenter - (int) round($w / 2)));
            } else {
                $w = max(1, $slideW - $x);
            }
        }
        if ($y + $h > $slideH) {
            $h = max(1, $slideH - $y);
        }
        $rotAttr = '';
        if (abs($box['angle']) > 0.01) {
            $rot = (int) round($box['angle'] * 60000);
            $rotAttr = ' rot="' . $rot . '"';
        }

        if (in_array($type, ['i-text', 'text', 'textbox'], true)) {
            $rawText = (string) ($obj['text'] ?? '');
            if ($rawText === '') {
                continue;
            }

            $fontSizePx = (float) ($obj['fontSize'] ?? 24) * (float) ($obj['scaleY'] ?? 1);
            $sz = max(100, (int) round($fontSizePx * 75));
            $bold = (!empty($obj['fontWeight']) && (string) $obj['fontWeight'] !== 'normal' && (string) $obj['fontWeight'] !== '400');
            $italic = (!empty($obj['fontStyle']) && str_contains(strtolower((string) $obj['fontStyle']), 'italic'));
            $underline = !empty($obj['underline']);
            $fill = certificate_pptx_color_hex(isset($obj['fill']) ? (string) $obj['fill'] : '#111827');
            $font = certificate_pptx_font_family((string) ($obj['fontFamily'] ?? 'Arial'));
            $align = $alignForClamp;
            if (!in_array($align, ['left', 'center', 'right', 'justify'], true)) {
                $align = 'left';
            }
            $algn = match ($align) {
                'center' => 'ctr',
                'right' => 'r',
                'justify' => 'just',
                default => 'l',
            };
            $bAttr = $bold ? ' b="1"' : '';
            $iAttr = $italic ? ' i="1"' : '';
            $uAttr = $underline ? ' u="sng"' : '';

            // "_____\\nAuthorized Signature" → real PPT line + label (no stray underscore glyphs).
            $sig = certificate_pptx_parse_signature_text($rawText);
            if ($sig !== null) {
                // Keep line length = Fabric text box width for accurate re-import.
                $lineW = max(1, $w);
                $lineX = $x;
                if ($align === 'center') {
                    $lineX = max(0, (int) round($x + ($w - $lineW) / 2));
                } elseif ($align === 'right') {
                    $lineX = max(0, $x + $w - $lineW);
                }
                $lineY = $y + (int) max(0, round($h * 0.12));
                $strokeEmu = max(12700, (int) round(1.5 * 12700));
                $shapeXml[] = certificate_pptx_line_shape_xml($nextId, $lineX, $lineY, $lineW, $fill, $strokeEmu, $fabricId);
                $nextId++;

                $label = $sig['label'];
                $labelH = max($pxToEmu(max(10.0, $fontSizePx * 1.2)), (int) round($h * 0.5));
                $labelY = min($slideH - $labelH, $lineY + $pxToEmu(8.0));
                // Clamp label into original Fabric box bottom so combined import box ≈ Fabric box.
                if ($labelY + $labelH > $y + $h) {
                    $labelY = max($lineY + $pxToEmu(4.0), $y + $h - $labelH);
                }
                $esc = certificate_pptx_xml_text($label);
                $cnv = certificate_pptx_cnvpr_xml($nextId, 'Signature Label', $fabricId);
                $shapeXml[] = <<<XML
      <p:sp>
        <p:nvSpPr>
          {$cnv}
          <p:cNvSpPr txBox="1"/>
          <p:nvPr/>
        </p:nvSpPr>
        <p:spPr>
          <a:xfrm{$rotAttr}>
            <a:off x="{$x}" y="{$labelY}"/>
            <a:ext cx="{$w}" cy="{$labelH}"/>
          </a:xfrm>
          <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
          <a:noFill/>
          <a:ln><a:noFill/></a:ln>
        </p:spPr>
        <p:txBody>
          <a:bodyPr wrap="square" lIns="0" tIns="0" rIns="0" bIns="0" anchor="t"/>
          <a:lstStyle/>
          <a:p>
            <a:pPr algn="{$algn}"/>
            <a:r>
              <a:rPr lang="en-US" sz="{$sz}"{$bAttr}{$iAttr} dirty="0">
                <a:solidFill><a:srgbClr val="{$fill}"/></a:solidFill>
                <a:latin typeface="{$font}"/>
                <a:ea typeface="{$font}"/>
                <a:cs typeface="{$font}"/>
              </a:rPr>
              <a:t>{$esc}</a:t>
            </a:r>
          </a:p>
        </p:txBody>
      </p:sp>
XML;
                $nextId++;
                continue;
            }

            // Skip pure underscore-only text (orphan signature dashes).
            if (preg_match('/^[_\x{2014}\x{2013}\-\s]{4,}$/u', trim($rawText)) === 1) {
                $lineW = max($w, $pxToEmu(120.0));
                $strokeEmu = max(12700, (int) round(1.5 * 12700));
                $shapeXml[] = certificate_pptx_line_shape_xml($nextId, $x, $y + (int) round($h / 2), $lineW, $fill, $strokeEmu, $fabricId);
                $nextId++;
                continue;
            }

            $lineCount = max(1, count(preg_split("/\r\n|\n|\r/", $rawText) ?: [$rawText]));
            // Keep Fabric-exact box for accurate PPT→Canva re-import (no artificial inflate).
            // Tiny boxes still get a small readability floor so PPT remains editable.
            if (!$isCertCode) {
                $minTextH = $pxToEmu(max(8.0, $fontSizePx * 0.9));
                $minTextW = $pxToEmu(max(12.0, $fontSizePx * 0.5));
                if ($h < $minTextH) {
                    $h = $minTextH;
                }
                if ($w < $minTextW) {
                    $w = $minTextW;
                }
            }
            unset($lineCount);

            $paras = preg_split("/\r\n|\n|\r/", $rawText) ?: [$rawText];
            $pXml = '';
            foreach ($paras as $line) {
                // Drop underscore-only paragraphs inside multi-line text (extra signature dash).
                if (preg_match('/^[_\x{2014}\x{2013}\-]{4,}$/u', trim((string) $line)) === 1) {
                    continue;
                }
                $esc = certificate_pptx_xml_text((string) $line);
                if ($esc === '') {
                    $pXml .= '<a:p><a:pPr algn="' . $algn . '"/><a:endParaRPr lang="en-US"/></a:p>';
                    continue;
                }
                $pXml .= <<<XML
            <a:p>
              <a:pPr algn="{$algn}"/>
              <a:r>
                <a:rPr lang="en-US" sz="{$sz}"{$bAttr}{$iAttr}{$uAttr} dirty="0">
                  <a:solidFill><a:srgbClr val="{$fill}"/></a:solidFill>
                  <a:latin typeface="{$font}"/>
                  <a:ea typeface="{$font}"/>
                  <a:cs typeface="{$font}"/>
                </a:rPr>
                <a:t>{$esc}</a:t>
              </a:r>
            </a:p>
XML;
            }
            if ($pXml === '') {
                continue;
            }

            $shapeName = $isCertCode ? 'Certificate Code' : ('Text ' . $nextId);
            $cnv = certificate_pptx_cnvpr_xml($nextId, $shapeName, $fabricId);
            $shapeXml[] = <<<XML
      <p:sp>
        <p:nvSpPr>
          {$cnv}
          <p:cNvSpPr txBox="1"/>
          <p:nvPr/>
        </p:nvSpPr>
        <p:spPr>
          <a:xfrm{$rotAttr}>
            <a:off x="{$x}" y="{$y}"/>
            <a:ext cx="{$w}" cy="{$h}"/>
          </a:xfrm>
          <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
          <a:noFill/>
          <a:ln><a:noFill/></a:ln>
        </p:spPr>
        <p:txBody>
          <a:bodyPr wrap="square" lIns="0" tIns="0" rIns="0" bIns="0" anchor="t"/>
          <a:lstStyle/>
          {$pXml}
        </p:txBody>
      </p:sp>
XML;
            $nextId++;
            continue;
        }

        if ($type === 'line') {
            $stroke = isset($obj['stroke']) ? trim((string) $obj['stroke']) : '#111827';
            if ($stroke === '' || strtolower($stroke) === 'transparent') {
                $stroke = '#111827';
            }
            $sHex = certificate_pptx_color_hex($stroke);
            $sw = max(12700, (int) round(((float) ($obj['strokeWidth'] ?? 1.5)) * 12700));
            $shapeXml[] = certificate_pptx_line_shape_xml($nextId, $x, $y + (int) round($h / 2), max($w, 1), $sHex, $sw, $fabricId);
            $nextId++;
            continue;
        }

        if ($type === 'image') {
            $media = certificate_pptx_resolve_image_media($obj);
            if ($media === null) {
                continue;
            }
            $rid = $addMedia($media);
            $cnv = certificate_pptx_cnvpr_xml($nextId, 'Image ' . $imageIndex, $fabricId);
            $shapeXml[] = <<<XML
      <p:pic>
        <p:nvPicPr>
          {$cnv}
          <p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr>
          <p:nvPr/>
        </p:nvPicPr>
        <p:blipFill>
          <a:blip r:embed="{$rid}"/>
          <a:stretch><a:fillRect/></a:stretch>
        </p:blipFill>
        <p:spPr>
          <a:xfrm{$rotAttr}>
            <a:off x="{$x}" y="{$y}"/>
            <a:ext cx="{$w}" cy="{$h}"/>
          </a:xfrm>
          <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
        </p:spPr>
      </p:pic>
XML;
            $nextId++;
            continue;
        }

        if (in_array($type, ['rect', 'rectangle'], true)) {
            // Skip full-page white/near-white plates when a real BG picture already covers the slide.
            $coverRatio = ($box['width'] * $box['height']) / max(1.0, $canvasW * $canvasH);
            $fillRaw = isset($obj['fill']) ? strtolower(trim((string) $obj['fill'])) : '';
            $isPalePlate = $fillRaw === '' || $fillRaw === '#fff' || $fillRaw === '#ffffff'
                || $fillRaw === 'white' || $fillRaw === 'rgb(255,255,255)' || $fillRaw === 'rgba(255,255,255,1)';
            if ($hasBgPicture && $coverRatio > 0.85 && $isPalePlate) {
                continue;
            }
            $fill = certificate_pptx_color_hex(isset($obj['fill']) ? (string) $obj['fill'] : '#FFFFFF');
            $fillXml = (!empty($obj['fill']) && (string) $obj['fill'] !== 'transparent')
                ? '<a:solidFill><a:srgbClr val="' . $fill . '"/></a:solidFill>'
                : '<a:noFill/>';
            $stroke = isset($obj['stroke']) ? trim((string) $obj['stroke']) : '';
            $lnXml = '<a:ln><a:noFill/></a:ln>';
            if ($stroke !== '' && strtolower($stroke) !== 'transparent') {
                $sHex = certificate_pptx_color_hex($stroke);
                $sw = max(12700, (int) round(((float) ($obj['strokeWidth'] ?? 1)) * 12700));
                $lnXml = '<a:ln w="' . $sw . '"><a:solidFill><a:srgbClr val="' . $sHex . '"/></a:solidFill></a:ln>';
            }
            $cnv = certificate_pptx_cnvpr_xml($nextId, 'Shape ' . $nextId, $fabricId);
            $shapeXml[] = <<<XML
      <p:sp>
        <p:nvSpPr>
          {$cnv}
          <p:cNvSpPr/>
          <p:nvPr/>
        </p:nvSpPr>
        <p:spPr>
          <a:xfrm{$rotAttr}>
            <a:off x="{$x}" y="{$y}"/>
            <a:ext cx="{$w}" cy="{$h}"/>
          </a:xfrm>
          <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
          {$fillXml}
          {$lnXml}
        </p:spPr>
        <p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="en-US"/></a:p></p:txBody>
      </p:sp>
XML;
            $nextId++;
        }
        } finally {
            $objectDone++;
            if ($objectDone === 1 || $objectDone === $objectTotal || ($objectDone % 3) === 0) {
                $pct = 30 + (int) round(($objectDone / $objectTotal) * 45);
                $progress($pct, 'Placing objects (' . $objectDone . '/' . $objectTotal . ')…');
            }
        }
    }

    if ($shapeXml === []) {
        throw new InvalidArgumentException('No exportable text/images found on the canvas.');
    }

    $progress(78, 'Adding certificate code…');

    // Only add a default code box when the canvas has none — never duplicate / override position.
    if (!$exportedCertificateCode) {
        $codeId = $nextId;
        $codeY = (int) ($slideH * 0.88);
        $codeH = (int) ($slideH * 0.05);
        $codeX = (int) ($slideW * 0.05);
        $codeW = (int) ($slideW * 0.35);
        $codeCnv = certificate_pptx_cnvpr_xml($codeId, 'Certificate Code', 'certificate_code');
        $shapeXml[] = <<<XML
      <p:sp>
        <p:nvSpPr>
          {$codeCnv}
          <p:cNvSpPr txBox="1"/>
          <p:nvPr/>
        </p:nvSpPr>
        <p:spPr>
          <a:xfrm>
            <a:off x="{$codeX}" y="{$codeY}"/>
            <a:ext cx="{$codeW}" cy="{$codeH}"/>
          </a:xfrm>
          <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
          <a:noFill/>
          <a:ln><a:noFill/></a:ln>
        </p:spPr>
        <p:txBody>
          <a:bodyPr wrap="square" lIns="0" tIns="0" rIns="0" bIns="0" anchor="t"/>
          <a:lstStyle/>
          <a:p>
            <a:pPr algn="l"/>
            <a:r>
              <a:rPr lang="en-US" sz="1400" b="1" dirty="0">
                <a:solidFill><a:srgbClr val="111827"/></a:solidFill>
                <a:latin typeface="Arial"/>
                <a:ea typeface="Arial"/>
                <a:cs typeface="Arial"/>
              </a:rPr>
              <a:t>CERTIFICATE-CODE-HERE</a:t>
            </a:r>
          </a:p>
        </p:txBody>
      </p:sp>
XML;
        $nextId++;
    }

    $shapesJoined = implode("\n", $shapeXml);

    // Slide relationships: layout + images
    $slideRels = [
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>',
    ];
    for ($i = 1; $i <= $imageIndex; $i++) {
        $ext = $mediaExtByIndex[$i] ?? 'png';
        $slideRels[] = '<Relationship Id="rIdImg' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image' . $i . '.' . $ext . '"/>';
    }
    $slideRelsXml = implode("\n  ", $slideRels);

    $progress(88, 'Packaging PPTX…');
    // No docProps thumbnail — skip GD preview work; editable slide content is enough.
    $pptx = certificate_pptx_package($title, $slideW, $slideH, $shapesJoined, $slideRelsXml, $mediaFiles, null, $templateId);
    $progress(95, 'Package ready…');
    return $pptx;
}

/**
 * @param array<string,string> $mediaFiles path => binary
 */
function certificate_pptx_package(
    string $title,
    int $slideW,
    int $slideH,
    string $shapesXml,
    string $slideRelsInner,
    array $mediaFiles,
    ?string $thumbnailJpeg = null,
    string $templateId = ''
): string {
    $tmp = tempnam(sys_get_temp_dir(), 'pcpptx_');
    if ($tmp === false) {
        throw new RuntimeException('Unable to create temp file for PPTX.');
    }
    $pptxPath = $tmp . '.pptx';
    @unlink($tmp);

    $zip = new ZipArchive();
    if ($zip->open($pptxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to open PPTX archive.');
    }

    $safeTitle = htmlspecialchars(mb_substr($title !== '' ? $title : 'Certificate Template', 0, 120), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $hasThumb = is_string($thumbnailJpeg) && $thumbnailJpeg !== '' && str_starts_with($thumbnailJpeg, "\xFF\xD8");
    $tplMeta = '';
    $templateId = trim($templateId);
    if ($templateId !== '' && preg_match('/^[a-zA-Z0-9_-]{8,128}$/', $templateId) === 1) {
        $tplEsc = htmlspecialchars('pulseconnect-template:' . $templateId, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $tplMeta = "\n  <cp:keywords>{$tplEsc}</cp:keywords>\n  <cp:category>{$tplEsc}</cp:category>";
    }

    $contentTypesDefaults = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Default Extension="png" ContentType="image/png"/>
  <Default Extension="jpeg" ContentType="image/jpeg"/>
  <Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>
  <Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>
  <Override PartName="/ppt/slides/slide1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>
  <Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>
  <Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
XML;
    if ($hasThumb) {
        $contentTypesDefaults .= "\n  <Override PartName=\"/docProps/thumbnail.jpeg\" ContentType=\"image/jpeg\"/>";
    }
    $contentTypesDefaults .= "\n</Types>";

    $rootRels = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
XML;
    if ($hasThumb) {
        $rootRels .= "\n  <Relationship Id=\"rId4\" Type=\"http://schemas.openxmlformats.org/package/2006/relationships/metadata/thumbnail\" Target=\"docProps/thumbnail.jpeg\"/>";
    }
    $rootRels .= "\n</Relationships>";

    $files = [
        '[Content_Types].xml' => $contentTypesDefaults,
        '_rels/.rels' => $rootRels,
        'docProps/core.xml' => <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <dc:title>{$safeTitle}</dc:title>
  <dc:creator>PulseConnect</dc:creator>
  <cp:lastModifiedBy>PulseConnect</cp:lastModifiedBy>{$tplMeta}
  <dcterms:created xsi:type="dcterms:W3CDTF">2026-01-01T00:00:00Z</dcterms:created>
  <dcterms:modified xsi:type="dcterms:W3CDTF">2026-01-01T00:00:00Z</dcterms:modified>
</cp:coreProperties>
XML,
        'docProps/app.xml' => <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
  <Application>PulseConnect</Application>
  <PresentationFormat>On-screen Show (16:9)</PresentationFormat>
  <Slides>1</Slides>
</Properties>
XML,
        'ppt/_rels/presentation.xml.rels' => <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/>
</Relationships>
XML,
        'ppt/presentation.xml' => <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" saveSubsetFonts="1">
  <p:sldMasterIdLst>
    <p:sldMasterId id="2147483648" r:id="rId1"/>
  </p:sldMasterIdLst>
  <p:sldIdLst>
    <p:sldId id="256" r:id="rId3"/>
  </p:sldIdLst>
  <p:sldSz cx="{$slideW}" cy="{$slideH}"/>
  <p:notesSz cx="6858000" cy="9144000"/>
</p:presentation>
XML,
        'ppt/slideMasters/_rels/slideMaster1.xml.rels' => <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>
</Relationships>
XML,
        'ppt/slideMasters/slideMaster1.xml' => <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sldMaster xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">
  <p:cSld>
    <p:bg><p:bgRef idx="1001"><a:schemeClr val="bg1"/></p:bgRef></p:bg>
    <p:spTree>
      <p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>
      <p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>
    </p:spTree>
  </p:cSld>
  <p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>
  <p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>
</p:sldMaster>
XML,
        'ppt/slideLayouts/_rels/slideLayout1.xml.rels' => <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>
</Relationships>
XML,
        'ppt/slideLayouts/slideLayout1.xml' => <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sldLayout xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" type="blank" preserve="1">
  <p:cSld name="Blank">
    <p:spTree>
      <p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>
      <p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>
    </p:spTree>
  </p:cSld>
  <p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>
</p:sldLayout>
XML,
        'ppt/slides/_rels/slide1.xml.rels' => <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  {$slideRelsInner}
</Relationships>
XML,
        'ppt/slides/slide1.xml' => <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">
  <p:cSld>
    <p:spTree>
      <p:nvGrpSpPr>
        <p:cNvPr id="1" name=""/>
        <p:cNvGrpSpPr/>
        <p:nvPr/>
      </p:nvGrpSpPr>
      <p:grpSpPr>
        <a:xfrm>
          <a:off x="0" y="0"/>
          <a:ext cx="0" cy="0"/>
          <a:chOff x="0" y="0"/>
          <a:chExt cx="0" cy="0"/>
        </a:xfrm>
      </p:grpSpPr>
{$shapesXml}
    </p:spTree>
  </p:cSld>
  <p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>
</p:sld>
XML,
        'ppt/theme/theme1.xml' => <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Office Theme">
  <a:themeElements>
    <a:clrScheme name="Office">
      <a:dk1><a:sysClr val="windowText" lastClr="000000"/></a:dk1>
      <a:lt1><a:sysClr val="window" lastClr="FFFFFF"/></a:lt1>
      <a:dk2><a:srgbClr val="1F2937"/></a:dk2>
      <a:lt2><a:srgbClr val="E7E6E6"/></a:lt2>
      <a:accent1><a:srgbClr val="F97316"/></a:accent1>
      <a:accent2><a:srgbClr val="ED7D31"/></a:accent2>
      <a:accent3><a:srgbClr val="A5A5A5"/></a:accent3>
      <a:accent4><a:srgbClr val="FFC000"/></a:accent4>
      <a:accent5><a:srgbClr val="5B9BD5"/></a:accent5>
      <a:accent6><a:srgbClr val="70AD47"/></a:accent6>
      <a:hlink><a:srgbClr val="0563C1"/></a:hlink>
      <a:folHlink><a:srgbClr val="954F72"/></a:folHlink>
    </a:clrScheme>
    <a:fontScheme name="Office">
      <a:majorFont><a:latin typeface="Arial"/><a:ea typeface="Arial"/><a:cs typeface="Arial"/></a:majorFont>
      <a:minorFont><a:latin typeface="Arial"/><a:ea typeface="Arial"/><a:cs typeface="Arial"/></a:minorFont>
    </a:fontScheme>
    <a:fmtScheme name="Office">
      <a:fillStyleLst>
        <a:solidFill><a:schemeClr val="phClr"/></a:solidFill>
        <a:solidFill><a:schemeClr val="phClr"/></a:solidFill>
        <a:solidFill><a:schemeClr val="phClr"/></a:solidFill>
      </a:fillStyleLst>
      <a:lnStyleLst>
        <a:ln w="12700"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>
        <a:ln w="12700"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>
        <a:ln w="12700"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>
      </a:lnStyleLst>
      <a:effectStyleLst>
        <a:effectStyle><a:effectLst/></a:effectStyle>
        <a:effectStyle><a:effectLst/></a:effectStyle>
        <a:effectStyle><a:effectLst/></a:effectStyle>
      </a:effectStyleLst>
      <a:bgFillStyleLst>
        <a:solidFill><a:schemeClr val="phClr"/></a:solidFill>
        <a:solidFill><a:schemeClr val="phClr"/></a:solidFill>
        <a:solidFill><a:schemeClr val="phClr"/></a:solidFill>
      </a:bgFillStyleLst>
    </a:fmtScheme>
  </a:themeElements>
  <a:objectDefaults/>
  <a:extraClrSchemeLst/>
</a:theme>
XML,
    ];

    foreach ($files as $path => $xml) {
        $zip->addFromString($path, str_replace("\r\n", "\n", $xml));
    }
    foreach ($mediaFiles as $path => $bin) {
        $zip->addFromString($path, $bin);
    }
    if ($hasThumb) {
        $zip->addFromString('docProps/thumbnail.jpeg', $thumbnailJpeg);
    }

    if (!$zip->close()) {
        @unlink($pptxPath);
        throw new RuntimeException('Failed to finalize PPTX archive.');
    }
    $bytes = (string) file_get_contents($pptxPath);
    @unlink($pptxPath);
    if ($bytes === '' || !str_starts_with($bytes, 'PK')) {
        throw new RuntimeException('Failed to read generated PPTX.');
    }
    return $bytes;
}

/** @deprecated Prefer certificate_pptx_build_from_fabric */
function certificate_pptx_build(string $imageBinary, string $title = 'Certificate Template'): string
{
    $png = certificate_pptx_normalize_png($imageBinary);
    $slideW = 12192000;
    $slideH = 6858000;
    $media = ['ppt/media/image1.png' => $png];
    $shapes = <<<XML
      <p:pic>
        <p:nvPicPr>
          <p:cNvPr id="2" name="Certificate Design" descr="pc:background"/>
          <p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr>
          <p:nvPr/>
        </p:nvPicPr>
        <p:blipFill>
          <a:blip r:embed="rIdImg1"/>
          <a:stretch><a:fillRect/></a:stretch>
        </p:blipFill>
        <p:spPr>
          <a:xfrm>
            <a:off x="0" y="0"/>
            <a:ext cx="{$slideW}" cy="{$slideH}"/>
          </a:xfrm>
          <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
        </p:spPr>
      </p:pic>
      <p:sp>
        <p:nvSpPr>
          <p:cNvPr id="3" name="Certificate Code" descr="pc:certificate_code"/>
          <p:cNvSpPr txBox="1"/>
          <p:nvPr/>
        </p:nvSpPr>
        <p:spPr>
          <a:xfrm>
            <a:off x="1828800" y="6050280"/>
            <a:ext cx="8534400" cy="548640"/>
          </a:xfrm>
          <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
          <a:noFill/>
        </p:spPr>
        <p:txBody>
          <a:bodyPr wrap="square" anchor="ctr"/>
          <a:lstStyle/>
          <a:p>
            <a:pPr algn="ctr"/>
            <a:r>
              <a:rPr lang="en-US" sz="1800" b="1">
                <a:solidFill><a:srgbClr val="111827"/></a:solidFill>
                <a:latin typeface="Calibri"/>
              </a:rPr>
              <a:t>CERTIFICATE-CODE-HERE</a:t>
            </a:r>
          </a:p>
        </p:txBody>
      </p:sp>
XML;
    $rels = <<<'XML'
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>
  <Relationship Id="rIdImg1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image1.png"/>
XML;
    return certificate_pptx_package($title, $slideW, $slideH, $shapes, $rels, $media);
}
