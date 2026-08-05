<?php
declare(strict_types=1);

/**
 * Extract registrar-style certificate codes from PPTX / PDF binary.
 *
 * Patterns supported (examples):
 *   LU-AA-FO-180-01
 *   LU:AA-CE-192.01.01
 */

function certificate_code_extract_regex(): string
{
    // Token segments of letters/digits joined by - : . with at least one separator.
    return '/\b[A-Z0-9]{1,8}(?:[-:.][A-Z0-9]{1,8}){2,6}\b/i';
}

function certificate_code_normalize(string $code): string
{
    return trim($code);
}

/**
 * @return list<array{code:string,scanned_from:string}>
 */
function certificate_codes_from_text(string $text): array
{
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    if ($text === '') {
        return [];
    }

    $regex = certificate_code_extract_regex();
    if (!preg_match_all($regex, $text, $matches, PREG_OFFSET_CAPTURE)) {
        return [];
    }

    $out = [];
    $seen = [];
    foreach ($matches[0] as $match) {
        $raw = certificate_code_normalize((string) ($match[0] ?? ''));
        if ($raw === '') {
            continue;
        }
        // Skip the export placeholder itself.
        if (strcasecmp($raw, '{{certificate_code}}') === 0) {
            continue;
        }
        // Require at least one digit somewhere (filters random words).
        if (!preg_match('/\d/', $raw)) {
            continue;
        }
        $key = strtoupper($raw);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $offset = (int) ($match[1] ?? 0);
        $start = max(0, $offset - 24);
        $snippet = trim(mb_substr($text, $start, 72));
        $out[] = [
            'code' => $raw,
            'scanned_from' => $snippet,
        ];
    }
    return $out;
}

function certificate_extract_text_from_pptx(string $binary): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required to read PPTX files.');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'pcimp_');
    if ($tmp === false) {
        throw new RuntimeException('Unable to create temp file.');
    }
    file_put_contents($tmp, $binary);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        @unlink($tmp);
        throw new InvalidArgumentException('Invalid PPTX file.');
    }

    $chunks = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if ($name === '') {
            continue;
        }
        $lower = strtolower($name);
        if (
            str_starts_with($lower, 'ppt/slides/slide')
            || str_starts_with($lower, 'ppt/notesSlides/')
            || str_contains($lower, 'slideLayouts/')
        ) {
            if (!str_ends_with($lower, '.xml')) {
                continue;
            }
            $xml = $zip->getFromIndex($i);
            if (!is_string($xml) || $xml === '') {
                continue;
            }
            // Extract <a:t>...</a:t> text runs.
            if (preg_match_all('#<a:t[^>]*>(.*?)</a:t>#si', $xml, $m)) {
                foreach ($m[1] as $piece) {
                    $chunks[] = html_entity_decode(strip_tags((string) $piece), ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            }
        }
    }
    $zip->close();
    @unlink($tmp);
    return implode(' ', $chunks);
}

function certificate_extract_text_from_pdf(string $binary): string
{
    // Best-effort: pull printable strings and common PDF text operators.
    $chunks = [];
    if (preg_match_all('/\\((?:\\\\.|[^\\\\)]){2,}\\)/s', $binary, $m)) {
        foreach ($m[0] as $lit) {
            $inner = substr((string) $lit, 1, -1);
            $inner = str_replace(['\\n', '\\r', '\\t', '\\(', '\\)', '\\\\'], ["\n", "\r", "\t", '(', ')', '\\'], $inner);
            if (preg_match('/[A-Za-z0-9]/', $inner)) {
                $chunks[] = $inner;
            }
        }
    }
    // Also harvest long ASCII runs (sometimes codes sit outside literal strings).
    if (preg_match_all('/[A-Z0-9][A-Z0-9\\-:\\.]{6,}/i', $binary, $m2)) {
        foreach ($m2[0] as $run) {
            $chunks[] = (string) $run;
        }
    }
    return implode(' ', $chunks);
}

/**
 * Find a usable TTF for slide text preview (best-effort).
 */
function certificate_preview_find_ttf(): ?string
{
    $candidates = [
        __DIR__ . '/../assets/fonts/DejaVuSans.ttf',
        __DIR__ . '/../assets/fonts/Inter-Regular.ttf',
        'C:/Windows/Fonts/arial.ttf',
        'C:/Windows/Fonts/calibri.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
    ];
    foreach ($candidates as $path) {
        if (is_string($path) && is_file($path)) {
            return $path;
        }
    }
    return null;
}

/**
 * @return array{x:int,y:int,w:int,h:int}
 */
function certificate_pptx_parse_xfrm(string $xml): array
{
    $x = 0;
    $y = 0;
    $w = 0;
    $h = 0;
    if (preg_match('/<a:off\b[^>]*\bx="(-?\d+)"[^>]*\by="(-?\d+)"/i', $xml, $m)) {
        $x = (int) $m[1];
        $y = (int) $m[2];
    } elseif (preg_match('/<a:off\b[^>]*\by="(-?\d+)"[^>]*\bx="(-?\d+)"/i', $xml, $m)) {
        $y = (int) $m[1];
        $x = (int) $m[2];
    }
    if (preg_match('/<a:ext\b[^>]*\bcx="(\d+)"[^>]*\bcy="(\d+)"/i', $xml, $m)) {
        $w = (int) $m[1];
        $h = (int) $m[2];
    } elseif (preg_match('/<a:ext\b[^>]*\bcy="(\d+)"[^>]*\bcx="(\d+)"/i', $xml, $m)) {
        $h = (int) $m[1];
        $w = (int) $m[2];
    }
    return ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
}

/**
 * PPT text body vertical anchor + insets (EMU).
 *
 * @return array{anchor:string,tIns:int,bIns:int,lIns:int,rIns:int}
 */
function certificate_pptx_parse_body_pr(string $xml): array
{
    $anchor = 't';
    $tIns = 0;
    $bIns = 0;
    $lIns = 0;
    $rIns = 0;
    if (preg_match('/<a:bodyPr\b([^>]*)\/?>/i', $xml, $m)) {
        $attrs = (string) $m[1];
        if (preg_match('/\banchor="(t|ctr|b)"/i', $attrs, $a)) {
            $anchor = strtolower((string) $a[1]);
        }
        if (preg_match('/\btIns="(-?\d+)"/i', $attrs, $v)) {
            $tIns = (int) $v[1];
        }
        if (preg_match('/\bbIns="(-?\d+)"/i', $attrs, $v)) {
            $bIns = (int) $v[1];
        }
        if (preg_match('/\blIns="(-?\d+)"/i', $attrs, $v)) {
            $lIns = (int) $v[1];
        }
        if (preg_match('/\brIns="(-?\d+)"/i', $attrs, $v)) {
            $rIns = (int) $v[1];
        }
    }
    return compact('anchor', 'tIns', 'bIns', 'lIns', 'rIns');
}

/**
 * Convert PPT shape xfrm → Fabric-friendly box using visual text position.
 * Tall bottom/center-anchored boxes (common for footer codes) collapse to the glyph line
 * so Import does not pin text to the top of the box (which looked like it sat on the border).
 *
 * @param array{x:int,y:int,w:int,h:int} $boxEmu
 * @param array{fontSize:?float,textAlign:?string,fontWeight:?string,fontFamily:?string} $style
 * @return array{left:float,top:float,width:float,height:float}
 */
function certificate_pptx_layout_visual_box(
    array $boxEmu,
    array $style,
    string $xml,
    float $emuPerPx,
    bool $preferTightHeight = false
): array {
    $left = $boxEmu['x'] / $emuPerPx;
    $top = $boxEmu['y'] / $emuPerPx;
    $width = max(1.0, $boxEmu['w'] / $emuPerPx);
    $height = max(1.0, $boxEmu['h'] / $emuPerPx);

    $body = certificate_pptx_parse_body_pr($xml);
    $left += $body['lIns'] / $emuPerPx;
    $width = max(1.0, $width - ($body['lIns'] + $body['rIns']) / $emuPerPx);

    $font = isset($style['fontSize']) && is_numeric($style['fontSize'])
        ? (float) $style['fontSize']
        : 0.0;
    if ($font < 6.0) {
        $font = max(10.0, min(28.0, $height * 0.55));
    }
    $lineH = max($font * 1.25, 14.0);

    // Content area after vertical insets.
    $innerTop = $top + ($body['tIns'] / $emuPerPx);
    $innerH = max(1.0, $height - ($body['tIns'] + $body['bIns']) / $emuPerPx);

    // When the shape is much taller than one line, place by anchor so footer codes
    // land below the gold border (PPT bottom of frame) instead of on it (Fabric top).
    if ($preferTightHeight && $innerH > $lineH * 1.5) {
        if ($body['anchor'] === 'ctr') {
            $top = $innerTop + (($innerH - $lineH) / 2.0);
        } elseif ($body['anchor'] === 'b' || $innerH > ($lineH * 2.2)) {
            // Explicit bottom-anchor, OR oversized footer frame (WPS/PPT often leaves
            // tall boxes with default top anchor while glyphs sit low in the frame).
            $top = $innerTop + $innerH - $lineH;
        } else {
            $top = $innerTop;
        }
        $height = $lineH;
    } else {
        $top = $innerTop;
        $height = $innerH;
        if ($preferTightHeight && $height > $lineH * 1.35) {
            if ($body['anchor'] === 'b' || $height > ($lineH * 2.0)) {
                $top = $top + $height - $lineH;
            } elseif ($body['anchor'] === 'ctr') {
                $top = $top + (($height - $lineH) / 2.0);
            }
            $height = $lineH;
        }
    }

    return [
        'left' => round($left, 2),
        'top' => round($top, 2),
        'width' => round(max(1.0, $width), 2),
        'height' => round(max(1.0, $height), 2),
    ];
}

/**
 * Parse cNvPr name + descr="pc:…" from a shape/pic block.
 *
 * @return array{name:string,fabric_id:string,is_line_companion:bool}
 */
function certificate_pptx_parse_cnvpr(string $xml): array
{
    $name = '';
    $fabricId = '';
    $isLineCompanion = false;
    if (preg_match('/<p:cNvPr\b[^>]*>/i', $xml, $tagM)) {
        $tag = $tagM[0];
        if (preg_match('/\bname="([^"]*)"/i', $tag, $nm)) {
            $name = html_entity_decode((string) $nm[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        if (preg_match('/\bdescr="([^"]*)"/i', $tag, $dm)) {
            $descr = html_entity_decode((string) $dm[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            if (str_starts_with($descr, 'pc:')) {
                $fabricId = substr($descr, 3);
            }
        }
    }
    // Signature underline companions use descr="pc:{id}:line".
    if ($fabricId !== '' && str_ends_with($fabricId, ':line')) {
        $fabricId = substr($fabricId, 0, -5);
        $isLineCompanion = true;
    }
    $lname = strtolower($name);
    if (!$isLineCompanion && (str_starts_with($lname, 'line ') || $lname === 'line')) {
        $isLineCompanion = true;
    }
    return ['name' => $name, 'fabric_id' => $fabricId, 'is_line_companion' => $isLineCompanion];
}

/**
 * Extract shape text from a slide shape XML fragment.
 */
function certificate_pptx_shape_text(string $xml): string
{
    $chunks = [];
    if (preg_match_all('#<a:t[^>]*>(.*?)</a:t>#si', $xml, $m)) {
        foreach ($m[1] as $piece) {
            $chunks[] = html_entity_decode(strip_tags((string) $piece), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    }
    return trim(implode("\n", $chunks));
}

/**
 * Read first text run style from a shape (matches export: sz = fontPx * 75).
 *
 * @return array{fontSize:?float,textAlign:?string,fontWeight:?string,fontFamily:?string}
 */
function certificate_pptx_parse_text_style(string $xml): array
{
    $fontSize = null;
    $textAlign = null;
    $fontWeight = null;
    $fontFamily = null;

    // Prefer the first run's rPr sz (hundredths of a point).
    if (preg_match('/<a:rPr\b([^>]*)>/i', $xml, $rPrM)) {
        $attrs = (string) $rPrM[1];
        if (preg_match('/\bsz="(\d+)"/i', $attrs, $szM)) {
            $fontSize = round(((int) $szM[1]) / 75.0, 2);
        }
        if (preg_match('/\bb="1"/i', $attrs)) {
            $fontWeight = 'bold';
        }
    }
    if ($fontSize === null && preg_match('/\bsz="(\d+)"/i', $xml, $szM)) {
        $fontSize = round(((int) $szM[1]) / 75.0, 2);
    }

    if (preg_match('/<a:pPr\b[^>]*\balgn="(l|ctr|r|just)"/i', $xml, $alM)) {
        $textAlign = match (strtolower((string) $alM[1])) {
            'ctr' => 'center',
            'r' => 'right',
            'just' => 'justify',
            default => 'left',
        };
    }

    if (preg_match('/<a:latin\b[^>]*\btypeface="([^"]+)"/i', $xml, $ffM)) {
        $fontFamily = html_entity_decode((string) $ffM[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    return [
        'fontSize' => $fontSize,
        'textAlign' => $textAlign,
        'fontWeight' => $fontWeight,
        'fontFamily' => $fontFamily !== null && $fontFamily !== '' ? $fontFamily : null,
    ];
}

/**
 * True when shape text is (or clearly is) the registrar certificate code.
 */
function certificate_pptx_text_looks_like_code(string $text): bool
{
    $text = trim($text);
    if ($text === '') {
        return false;
    }
    if (strcasecmp($text, 'CERTIFICATE-CODE-HERE') === 0) {
        return true;
    }
    if (preg_match('/^\{\{\s*certificate_code\s*\}\}$/i', $text)) {
        return true;
    }
    if (mb_strlen($text) > 48) {
        return false;
    }
    $codes = certificate_codes_from_text($text);
    if ($codes === []) {
        return false;
    }
    // Whole shape is basically just the code (not a long paragraph that mentions one).
    $only = preg_replace('/\s+/u', '', $text) ?? $text;
    $code = preg_replace('/\s+/u', '', (string) ($codes[0]['code'] ?? '')) ?? '';
    return $code !== '' && strcasecmp($only, $code) === 0;
}

/**
 * Layout map from registrar PPTX (positions/sizes in canvas pixels @ 9525 EMU/px).
 *
 * @return array{
 *   items:list<array{id:string,kind:string,left:float,top:float,width:float,height:float,text:string,name:string}>,
 *   canvas_width:float,
 *   canvas_height:float,
 *   matched:int
 * }
 */
function certificate_extract_layout_from_pptx(string $binary): array
{
    if (!class_exists('ZipArchive') || !str_starts_with($binary, 'PK')) {
        throw new InvalidArgumentException('Invalid PPTX file.');
    }
    $emuPerPx = 9525.0;
    if (function_exists('certificate_pptx_emu_per_px')) {
        $emuPerPx = certificate_pptx_emu_per_px();
    }

    $tmp = tempnam(sys_get_temp_dir(), 'pclayout_');
    if ($tmp === false) {
        throw new RuntimeException('Unable to create temp file.');
    }
    file_put_contents($tmp, $binary);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        @unlink($tmp);
        throw new InvalidArgumentException('Invalid PPTX file.');
    }

    try {
        $slideW = 10715650;
        $slideH = 7560313;
        $presXml = $zip->getFromName('ppt/presentation.xml');
        if (is_string($presXml) && preg_match('/<p:sldSz\b[^>]*\bcx="(\d+)"[^>]*\bcy="(\d+)"/i', $presXml, $sz)) {
            $slideW = max(1, (int) $sz[1]);
            $slideH = max(1, (int) $sz[2]);
        } elseif (is_string($presXml) && preg_match('/<p:sldSz\b[^>]*\bcy="(\d+)"[^>]*\bcx="(\d+)"/i', $presXml, $sz)) {
            $slideH = max(1, (int) $sz[1]);
            $slideW = max(1, (int) $sz[2]);
        }

        $slideXml = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if (preg_match('#^ppt/slides/slide\d+\.xml$#i', $name)) {
                $slideXml = $zip->getFromIndex($i);
                break;
            }
        }
        if (!is_string($slideXml) || $slideXml === '') {
            $slideXml = $zip->getFromName('ppt/slides/slide1.xml') ?: '';
        }
        if ($slideXml === '') {
            return [
                'items' => [],
                'canvas_width' => round($slideW / $emuPerPx, 2),
                'canvas_height' => round($slideH / $emuPerPx, 2),
                'matched' => 0,
            ];
        }

        $items = [];
        /** @var array<string,array{line:?array,label:?array}> $signatureParts */
        $signatureParts = [];
        $blocks = [];
        if (preg_match_all('/<(?:p:pic|p:sp|p:cxnSp)\b[\s\S]*?<\/(?:p:pic|p:sp|p:cxnSp)>/i', $slideXml, $bm)) {
            $blocks = $bm[0];
        }
        foreach ($blocks as $block) {
            $meta = certificate_pptx_parse_cnvpr($block);
            $name = $meta['name'];
            $fabricId = $meta['fabric_id'];
            $isLineCompanion = !empty($meta['is_line_companion']);
            $box = certificate_pptx_parse_xfrm($block);
            if ($box['w'] < 1 || $box['h'] < 1) {
                continue;
            }
            $text = certificate_pptx_shape_text($block);
            $style = certificate_pptx_parse_text_style($block);
            $kind = 'object';
            $id = $fabricId;

            $lname = strtolower($name);
            $isCodeShape = ($lname === 'certificate code' || $fabricId === 'certificate_code' || certificate_pptx_text_looks_like_code($text));
            if ($isCodeShape) {
                $kind = 'certificate_code';
                $id = 'certificate_code';
            } elseif ($lname === 'background' || $fabricId === 'background') {
                $kind = 'background';
                $id = 'background';
            } elseif ($lname === 'signature label' || ($fabricId !== '' && $isLineCompanion)) {
                // Collect signature line + label; merge after loop so Fabric keeps one object.
                // Also buffers lone line companions (merged only when a label shares the same pc: id).
                if ($fabricId === '') {
                    continue;
                }
                if (!isset($signatureParts[$fabricId])) {
                    $signatureParts[$fabricId] = ['line' => null, 'label' => null];
                }
                $vis = certificate_pptx_layout_visual_box($box, $style, $block, $emuPerPx, false);
                $part = [
                    'left' => $vis['left'],
                    'top' => $vis['top'],
                    'width' => $vis['width'],
                    'height' => $vis['height'],
                    'text' => $text,
                    'name' => $name,
                    'fontSize' => $style['fontSize'],
                    'textAlign' => $style['textAlign'],
                    'fontWeight' => $style['fontWeight'],
                    'fontFamily' => $style['fontFamily'],
                ];
                if ($isLineCompanion || str_starts_with($lname, 'line')) {
                    // Lines use raw xfrm (no text anchor).
                    $part['left'] = round($box['x'] / $emuPerPx, 2);
                    $part['top'] = round($box['y'] / $emuPerPx, 2);
                    $part['width'] = round($box['w'] / $emuPerPx, 2);
                    $part['height'] = round($box['h'] / $emuPerPx, 2);
                    $signatureParts[$fabricId]['line'] = $part;
                } else {
                    $signatureParts[$fabricId]['label'] = $part;
                }
                continue;
            } elseif ($id === '') {
                // Legacy exports: best-effort match by unique placeholder text later.
                if ($text === '') {
                    continue;
                }
                // Don't let "Authorized Signature" alone warp a combined signature object.
                if (preg_match('/^authorized\s+signature$/i', $text)) {
                    continue;
                }
                $kind = 'text_fallback';
                $id = 'text:' . md5(mb_strtolower(trim($text)));
            }

            $vis = ($text !== '' || $kind === 'certificate_code')
                ? certificate_pptx_layout_visual_box($box, $style, $block, $emuPerPx, $kind === 'certificate_code')
                : [
                    'left' => round($box['x'] / $emuPerPx, 2),
                    'top' => round($box['y'] / $emuPerPx, 2),
                    'width' => round($box['w'] / $emuPerPx, 2),
                    'height' => round($box['h'] / $emuPerPx, 2),
                ];

            $items[] = [
                'id' => $id,
                'kind' => $kind,
                'left' => $vis['left'],
                'top' => $vis['top'],
                'width' => $vis['width'],
                'height' => $vis['height'],
                'text' => $text,
                'name' => $name,
                'fontSize' => $style['fontSize'],
                'textAlign' => $style['textAlign'],
                'fontWeight' => $style['fontWeight'],
                'fontFamily' => $style['fontFamily'],
            ];
        }

        // Merge signature line + label into one combined box (matches single Fabric text object).
        // Line-only entries (non-signature strokes) fall through as normal move targets.
        foreach ($signatureParts as $sigId => $parts) {
            $line = $parts['line'];
            $label = $parts['label'];
            if ($label === null && $line === null) {
                continue;
            }
            if ($label === null && $line !== null) {
                // Plain line shape — keep as a regular object layout item.
                $items[] = [
                    'id' => $sigId,
                    'kind' => 'object',
                    'left' => (float) $line['left'],
                    'top' => (float) $line['top'],
                    'width' => (float) $line['width'],
                    'height' => (float) $line['height'],
                    'text' => '',
                    'name' => (string) ($line['name'] ?? 'Line'),
                ];
                continue;
            }
            $boxes = array_values(array_filter([$line, $label]));
            $left = min(array_map(static fn (array $b): float => (float) $b['left'], $boxes));
            $top = min(array_map(static fn (array $b): float => (float) $b['top'], $boxes));
            $right = max(array_map(static fn (array $b): float => (float) $b['left'] + (float) $b['width'], $boxes));
            $bottom = max(array_map(static fn (array $b): float => (float) $b['top'] + (float) $b['height'], $boxes));
            $labelText = is_array($label) ? (string) ($label['text'] ?? '') : '';
            $items[] = [
                'id' => $sigId,
                'kind' => 'signature',
                'left' => $left,
                'top' => $top,
                'width' => max(1.0, $right - $left),
                'height' => max(1.0, $bottom - $top),
                'text' => $labelText,
                'name' => 'Signature',
                'fontSize' => is_array($label) ? ($label['fontSize'] ?? null) : null,
                'textAlign' => is_array($label) ? ($label['textAlign'] ?? null) : null,
                'fontWeight' => is_array($label) ? ($label['fontWeight'] ?? null) : null,
                'fontFamily' => is_array($label) ? ($label['fontFamily'] ?? null) : null,
            ];
        }

        return [
            'items' => certificate_pptx_layout_bind_signatory_names(
                certificate_pptx_layout_dedupe_code_items($items)
            ),
            'canvas_width' => round($slideW / $emuPerPx, 2),
            'canvas_height' => round($slideH / $emuPerPx, 2),
            'matched' => count($items),
        ];
    } finally {
        $zip->close();
        @unlink($tmp);
    }
}

/**
 * Attach orphan person-name shapes (e.g. Juan Dela Cruz) onto the nearest signature item.
 *
 * @param list<array<string,mixed>> $items
 * @return list<array<string,mixed>>
 */
function certificate_pptx_layout_bind_signatory_names(array $items): array
{
    $sigIdxs = [];
    foreach ($items as $i => $item) {
        if (!is_array($item)) {
            continue;
        }
        if ((string) ($item['kind'] ?? '') === 'signature') {
            $sigIdxs[] = $i;
        }
    }

    $consume = [];
    foreach ($items as $i => $item) {
        if (!is_array($item) || in_array($i, $sigIdxs, true)) {
            continue;
        }
        $kind = (string) ($item['kind'] ?? '');
        $text = trim((string) ($item['text'] ?? ''));
        if ($text === '' || $kind === 'certificate_code' || $kind === 'background') {
            continue;
        }
        if (!certificate_pptx_layout_looks_like_signatory_name($text)) {
            continue;
        }

        // Prefer an existing signature item; else promote this name into a signature-kind item.
        if ($sigIdxs !== []) {
            $bestSig = $sigIdxs[0];
            $bestDist = PHP_FLOAT_MAX;
            $nameLeft = (float) ($item['left'] ?? 0);
            $nameTop = (float) ($item['top'] ?? 0);
            foreach ($sigIdxs as $si) {
                $sig = $items[$si];
                $dx = $nameLeft - (float) ($sig['left'] ?? 0);
                $dy = $nameTop - (float) ($sig['top'] ?? 0);
                $dist = ($dx * $dx) + ($dy * $dy);
                if ($dist < $bestDist) {
                    $bestDist = $dist;
                    $bestSig = $si;
                }
            }
            $existing = trim((string) ($items[$bestSig]['text'] ?? ''));
            if ($existing === ''
                || preg_match('/^authorized\s+signature$/i', $existing)
                || strcasecmp($existing, $text) === 0
            ) {
                $items[$bestSig]['text'] = $text;
            } elseif (!str_contains(mb_strtolower($existing), mb_strtolower($text))) {
                // Name + title already on signature: put name first if missing.
                $items[$bestSig]['text'] = $text . "\n" . $existing;
            }
            // Expand signature box to include the name shape.
            $sig = $items[$bestSig];
            $left = min((float) ($sig['left'] ?? 0), (float) ($item['left'] ?? 0));
            $top = min((float) ($sig['top'] ?? 0), (float) ($item['top'] ?? 0));
            $right = max(
                (float) ($sig['left'] ?? 0) + (float) ($sig['width'] ?? 0),
                (float) ($item['left'] ?? 0) + (float) ($item['width'] ?? 0)
            );
            $bottom = max(
                (float) ($sig['top'] ?? 0) + (float) ($sig['height'] ?? 0),
                (float) ($item['top'] ?? 0) + (float) ($item['height'] ?? 0)
            );
            $items[$bestSig]['left'] = $left;
            $items[$bestSig]['top'] = $top;
            $items[$bestSig]['width'] = max(1.0, $right - $left);
            $items[$bestSig]['height'] = max(1.0, $bottom - $top);
            $consume[$i] = true;
        } else {
            // Do NOT promote orphan names to kind=signature — that moved wrong objects
            // when sync treated them as signature boxes. Soft-bind (text-only) handles these.
            continue;
        }
    }

    if ($consume === []) {
        return $items;
    }
    $out = [];
    foreach ($items as $i => $item) {
        if (isset($consume[$i])) {
            continue;
        }
        $out[] = $item;
    }
    return $out;
}

/**
 * @see certificate_pptx_sync_looks_like_signatory_name (kept here so extract works without sync include)
 */
function certificate_pptx_layout_looks_like_signatory_name(string $text): bool
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($text === '' || str_contains($text, '{{') || mb_strlen($text) > 48) {
        return false;
    }
    if (preg_match('/^authorized\s+signature$/i', $text)) {
        return false;
    }
    if (certificate_pptx_text_looks_like_code($text)) {
        return false;
    }
    if (preg_match('/\b(of|the|a|an|for|is|to|and|with|during|organized|session|theme|given|certificate|appreciation|apppreciation|presented|proudly|attending|college|computing|studies|summit)\b/i', $text)) {
        return false;
    }
    $words = preg_split('/\s+/u', $text) ?: [];
    $n = count($words);
    if ($n < 2 || $n > 5) {
        return false;
    }
    foreach ($words as $w) {
        if (!preg_match('/^\p{Lu}[\p{L}\.\-\']*$/u', $w)) {
            return false;
        }
    }
    return true;
}

/**
 * Keep a single certificate_code layout item (prefer real code text, then bottom-left).
 *
 * @param list<array<string,mixed>> $items
 * @return list<array<string,mixed>>
 */
function certificate_pptx_layout_dedupe_code_items(array $items): array
{
    $codeIdxs = [];
    foreach ($items as $i => $item) {
        if (!is_array($item)) {
            continue;
        }
        $kind = (string) ($item['kind'] ?? '');
        $id = (string) ($item['id'] ?? '');
        if ($kind === 'certificate_code' || $id === 'certificate_code') {
            $codeIdxs[] = $i;
        }
    }
    if (count($codeIdxs) <= 1) {
        return $items;
    }

    $best = $codeIdxs[0];
    $bestScore = -1.0;
    foreach ($codeIdxs as $i) {
        $it = $items[$i];
        $txt = trim((string) ($it['text'] ?? ''));
        $isPlaceholder = ($txt === ''
            || stripos($txt, 'CERTIFICATE-CODE') !== false
            || preg_match('/^\{\{\s*certificate_code\s*\}\}$/i', $txt));
        $score = ($isPlaceholder ? 0.0 : 1000.0)
            + ((float) ($it['top'] ?? 0) * 0.01)
            - ((float) ($it['left'] ?? 0) * 0.001);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $i;
        }
    }

    $out = [];
    foreach ($items as $i => $item) {
        if (in_array($i, $codeIdxs, true) && $i !== $best) {
            continue;
        }
        $out[] = $item;
    }
    return $out;
}

/**
 * Best available image data URL from a Fabric canvas_state (avoids tiny library thumbs).
 */
function certificate_preview_from_canvas_state(array $canvasState): ?string
{
    $candidates = [];
    if (!empty($canvasState['background_data_url']) && is_string($canvasState['background_data_url'])) {
        $candidates[] = $canvasState['background_data_url'];
    }
    if (isset($canvasState['backgroundImage']) && is_array($canvasState['backgroundImage'])) {
        foreach (['src', 'source'] as $k) {
            if (!empty($canvasState['backgroundImage'][$k]) && is_string($canvasState['backgroundImage'][$k])) {
                $candidates[] = $canvasState['backgroundImage'][$k];
            }
        }
    }
    if (isset($canvasState['objects']) && is_array($canvasState['objects'])) {
        foreach ($canvasState['objects'] as $obj) {
            if (!is_array($obj)) {
                continue;
            }
            $type = strtolower((string) ($obj['type'] ?? ''));
            if ($type !== 'image') {
                continue;
            }
            foreach (['src', 'source'] as $k) {
                if (!empty($obj[$k]) && is_string($obj[$k])) {
                    $candidates[] = $obj[$k];
                }
            }
        }
    }
    $best = null;
    $bestLen = 0;
    foreach ($candidates as $src) {
        $src = trim($src);
        if (!str_starts_with($src, 'data:image/')) {
            continue;
        }
        $len = strlen($src);
        if ($len > $bestLen) {
            $bestLen = $len;
            $best = $src;
        }
    }
    return $best;
}

/**
 * Read PulseConnect template id embedded in PPTX core properties.
 */
function certificate_extract_template_id_from_pptx(string $binary): string
{
    if (!class_exists('ZipArchive') || !str_starts_with($binary, 'PK')) {
        return '';
    }
    $tmp = tempnam(sys_get_temp_dir(), 'pctpl_');
    if ($tmp === false) {
        return '';
    }
    file_put_contents($tmp, $binary);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        @unlink($tmp);
        return '';
    }
    try {
        $core = $zip->getFromName('docProps/core.xml');
        if (!is_string($core) || $core === '') {
            return '';
        }
        if (preg_match('/pulseconnect-template:([a-zA-Z0-9_-]{8,128})/', $core, $m)) {
            return (string) $m[1];
        }
        return '';
    } finally {
        $zip->close();
        @unlink($tmp);
    }
}

/**
 * Best-effort: match layout object ids to a teacher's saved template.
 */
function certificate_match_template_by_layout(string $userId, array $layout): string
{
    $userId = trim($userId);
    $items = $layout['items'] ?? [];
    if ($userId === '' || !is_array($items) || $items === []) {
        return '';
    }
    $layoutIds = [];
    $layoutTexts = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = trim((string) ($item['id'] ?? ''));
        $kind = (string) ($item['kind'] ?? '');
        $text = trim((string) ($item['text'] ?? ''));
        if ($id !== '' && $kind !== 'background' && $kind !== 'certificate_code' && !str_starts_with($id, 'text:')) {
            $layoutIds[$id] = true;
        }
        // Unique placeholders / long lines help match when PPT has no pc: ids.
        if ($text !== ''
            && $kind !== 'certificate_code'
            && !preg_match('/^authorized\s+signature$/i', $text)
            && (str_contains($text, '{{') || mb_strlen($text) >= 24)
        ) {
            $layoutTexts[mb_strtolower($text)] = true;
        }
    }
    if ($layoutIds === [] && $layoutTexts === []) {
        return '';
    }

    $headers = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
        . '?select=id,canvas_state,created_by'
        . '&created_by=eq.' . rawurlencode($userId)
        . '&order=created_at.desc'
        . '&limit=40';
    $res = supabase_request('GET', $url, $headers);
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    if (!is_array($rows)) {
        return '';
    }

    $bestId = '';
    $bestScore = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $tid = trim((string) ($row['id'] ?? ''));
        $canvas = $row['canvas_state'] ?? null;
        if (is_string($canvas)) {
            $canvas = json_decode($canvas, true);
        }
        if ($tid === '' || !is_array($canvas) || !isset($canvas['objects']) || !is_array($canvas['objects'])) {
            continue;
        }
        $score = 0;
        $seenText = [];
        foreach ($canvas['objects'] as $obj) {
            if (!is_array($obj)) {
                continue;
            }
            $oid = trim((string) ($obj['id'] ?? ''));
            if ($oid !== '' && isset($layoutIds[$oid])) {
                $score += 3;
            }
            $type = strtolower((string) ($obj['type'] ?? ''));
            if (in_array($type, ['i-text', 'text', 'textbox'], true)) {
                $tkey = mb_strtolower(trim((string) ($obj['text'] ?? '')));
                if ($tkey !== '' && isset($layoutTexts[$tkey]) && !isset($seenText[$tkey])) {
                    $seenText[$tkey] = true;
                    $score += 2;
                }
            }
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestId = $tid;
        }
    }
    return $bestScore >= 2 ? $bestId : '';
}

/**
 * @return array{kind:string,codes:list<array{code:string,scanned_from:string}>,text_length:int,layout:array,template_id:string}
 */
function certificate_extract_codes_from_upload(string $binary, string $filename, string $mime = ''): array
{
    $filename = strtolower($filename);
    $mime = strtolower($mime);
    $kind = 'pptx';
    if (str_ends_with($filename, '.pdf') || str_contains($mime, 'pdf')) {
        $kind = 'pdf';
    } elseif (str_ends_with($filename, '.pptx') || str_contains($mime, 'presentation') || str_contains($mime, 'pptx')) {
        $kind = 'pptx';
    } else {
        if (str_starts_with($binary, '%PDF')) {
            $kind = 'pdf';
        } elseif (str_starts_with($binary, 'PK')) {
            $kind = 'pptx';
        } else {
            throw new InvalidArgumentException('Only PPTX uploads are supported.');
        }
    }

    $text = $kind === 'pdf'
        ? certificate_extract_text_from_pdf($binary)
        : certificate_extract_text_from_pptx($binary);

    $codes = certificate_codes_from_text($text);
    $layout = [
        'items' => [],
        'canvas_width' => 0.0,
        'canvas_height' => 0.0,
        'matched' => 0,
    ];
    $templateId = '';
    if ($kind === 'pptx') {
        try {
            $layout = certificate_extract_layout_from_pptx($binary);
        } catch (Throwable $e) {
            // Codes still usable even if layout parse fails.
        }
        $templateId = certificate_extract_template_id_from_pptx($binary);
    }

    return [
        'kind' => $kind,
        'codes' => $codes,
        'text_length' => strlen($text),
        'layout' => $layout,
        'template_id' => $templateId,
    ];
}
