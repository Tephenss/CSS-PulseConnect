<?php
declare(strict_types=1);

/**
 * Apply registrar PPTX layout deltas onto a Fabric canvas_state.
 * Accuracy rules:
 *  - Positions always follow PPT top-left bounding boxes (origin-aware).
 *  - Images/shapes resize to PPT size (1:1 box).
 *  - Text uses PPT box + PPT fontSize when available (never guess from box height alone).
 *  - Signature (line+label) moves as one block — never warped.
 *  - Coords are scaled if PPT slide px size ≠ template canvas size.
 */

require_once __DIR__ . '/certificate_code_extract.php';

/**
 * @param array<string,mixed> $layout From certificate_extract_layout_from_pptx or client
 * @return array{
 *   canvas_state:array<string,mixed>,
 *   updated:int,
 *   added_code:bool,
 *   skipped:int,
 *   warnings:list<string>
 * }
 */
function certificate_pptx_sync_apply_layout(array $canvasState, array $layout): array
{
    $items = $layout['items'] ?? [];
    if (!is_array($items)) {
        $items = [];
    }

    if (!isset($canvasState['objects']) || !is_array($canvasState['objects'])) {
        $canvasState['objects'] = [];
    }

    $canvasW = (float) ($canvasState['width'] ?? 0);
    $canvasH = (float) ($canvasState['height'] ?? 0);
    if ($canvasW < 10) {
        $canvasW = 1123.0;
    }
    if ($canvasH < 10) {
        $canvasH = 794.0;
    }

    // Normalize PPT coords into this template's pixel space.
    // CRITICAL: use ONE uniform scale (not independent X/Y) so shapes aren't stretched
    // when PPT slide aspect ≠ canvas aspect (e.g. widescreen PPT vs A4 canvas).
    $layoutW = (float) ($layout['canvas_width'] ?? 0);
    $layoutH = (float) ($layout['canvas_height'] ?? 0);
    $scale = 1.0;
    $offsetX = 0.0;
    $offsetY = 0.0;
    if ($layoutW > 10 && $layoutH > 10) {
        $aspectLayout = $layoutW / $layoutH;
        $aspectCanvas = $canvasW / $canvasH;
        $aspectDelta = abs($aspectLayout - $aspectCanvas) / max(0.01, $aspectCanvas);

        if ($aspectDelta <= 0.02) {
            // Same aspect — map with independent scales (usually ~equal) for exact fill.
            $scaleX = abs($layoutW - $canvasW) / $canvasW > 0.01 ? ($canvasW / $layoutW) : 1.0;
            $scaleY = abs($layoutH - $canvasH) / $canvasH > 0.01 ? ($canvasH / $layoutH) : 1.0;
            // Still force uniform if the two scales diverge (float noise / rounding).
            if (abs($scaleX - $scaleY) / max($scaleX, $scaleY, 0.01) > 0.01) {
                $scale = min($scaleX, $scaleY);
                $offsetX = ($canvasW - $layoutW * $scale) / 2.0;
                $offsetY = ($canvasH - $layoutH * $scale) / 2.0;
                $scaleX = $scale;
                $scaleY = $scale;
            }
        } else {
            // Different aspect (common: registrar widescreen vs A4 template).
            // Prefer 1:1 PPT pixels: resize canvas to the slide so positions match PowerPoint.
            $canvasState['width'] = round($layoutW, 2);
            $canvasState['height'] = round($layoutH, 2);
            $fit = min($layoutW / $canvasW, $layoutH / $canvasH);
            $ox = ($layoutW - $canvasW * $fit) / 2.0;
            $oy = ($layoutH - $canvasH * $fit) / 2.0;
            foreach ($canvasState['objects'] as $i => $obj) {
                if (!is_array($obj)) {
                    continue;
                }
                $canvasState['objects'][$i]['left'] = round(((float) ($obj['left'] ?? 0)) * $fit + $ox, 2);
                $canvasState['objects'][$i]['top'] = round(((float) ($obj['top'] ?? 0)) * $fit + $oy, 2);
                $canvasState['objects'][$i]['scaleX'] = round(((float) ($obj['scaleX'] ?? 1)) * $fit, 4);
                $canvasState['objects'][$i]['scaleY'] = round(((float) ($obj['scaleY'] ?? 1)) * $fit, 4);
                if (isset($obj['fontSize']) && is_numeric($obj['fontSize'])) {
                    $canvasState['objects'][$i]['fontSize'] = round(max(6.0, ((float) $obj['fontSize']) * $fit), 2);
                }
                certificate_pptx_sync_clear_stale_geometry($canvasState['objects'][$i]);
            }
            if (!empty($canvasState['backgroundImage']) && is_array($canvasState['backgroundImage'])) {
                $bg = &$canvasState['backgroundImage'];
                $bg['left'] = round(((float) ($bg['left'] ?? 0)) * $fit + $ox, 2);
                $bg['top'] = round(((float) ($bg['top'] ?? 0)) * $fit + $oy, 2);
                $bg['scaleX'] = round(((float) ($bg['scaleX'] ?? 1)) * $fit, 4);
                $bg['scaleY'] = round(((float) ($bg['scaleY'] ?? 1)) * $fit, 4);
                unset($bg);
            }
            $canvasW = $layoutW;
            $canvasH = $layoutH;
            $scaleX = 1.0;
            $scaleY = 1.0;
            $offsetX = 0.0;
            $offsetY = 0.0;
        }
    } else {
        $scaleX = 1.0;
        $scaleY = 1.0;
    }
    if (!isset($scaleX)) {
        $scaleX = $scale;
        $scaleY = $scale;
    }

    // Ensure every object has an id (for future exports).
    foreach ($canvasState['objects'] as $i => $obj) {
        if (!is_array($obj)) {
            continue;
        }
        if (trim((string) ($obj['id'] ?? '')) === '') {
            try {
                $canvasState['objects'][$i]['id'] = 'pc_' . bin2hex(random_bytes(8));
            } catch (Throwable $e) {
                $canvasState['objects'][$i]['id'] = 'pc_' . substr(sha1(uniqid((string) mt_rand(), true)), 0, 16);
            }
        }
    }

    /** @var array<string,int> $byId */
    $byId = [];
    /** @var array<string,list<int>> $byText */
    $byText = [];
    foreach ($canvasState['objects'] as $i => $obj) {
        if (!is_array($obj)) {
            continue;
        }
        $id = trim((string) ($obj['id'] ?? ''));
        if ($id !== '') {
            $byId[$id] = $i;
        }
        $name = strtolower(trim((string) ($obj['name'] ?? '')));
        if ($name === 'certificate code' || $id === 'certificate_code') {
            $byId['certificate_code'] = $i;
        }
        $type = strtolower((string) ($obj['type'] ?? ''));
        if (in_array($type, ['i-text', 'text', 'textbox'], true)) {
            $textKey = mb_strtolower(trim((string) ($obj['text'] ?? '')));
            if ($textKey !== '') {
                $byText[$textKey][] = $i;
            }
        }
    }

    $updated = 0;
    $skipped = 0;
    $addedCode = false;
    $warnings = [];
    /** @var array<int,true> $used */
    $used = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $kind = (string) ($item['kind'] ?? 'object');
        if ($kind === 'background') {
            continue;
        }

        $left = (float) ($item['left'] ?? 0) * $scaleX + $offsetX;
        $top = (float) ($item['top'] ?? 0) * $scaleY + $offsetY;
        $width = max(1.0, (float) ($item['width'] ?? 1) * $scaleX);
        $height = max(1.0, (float) ($item['height'] ?? 1) * $scaleY);
        $itemId = trim((string) ($item['id'] ?? ''));
        $itemText = trim((string) ($item['text'] ?? ''));
        $fontScale = ($scaleX + $scaleY) / 2.0;
        $style = [
            'fontSize' => isset($item['fontSize']) && is_numeric($item['fontSize'])
                ? (float) $item['fontSize'] * $fontScale
                : null,
            'textAlign' => isset($item['textAlign']) && is_string($item['textAlign']) ? $item['textAlign'] : null,
            'fontWeight' => isset($item['fontWeight']) && is_string($item['fontWeight']) ? $item['fontWeight'] : null,
            'fontFamily' => isset($item['fontFamily']) && is_string($item['fontFamily']) ? $item['fontFamily'] : null,
        ];

        if ($kind === 'certificate_code' || $itemId === 'certificate_code') {
            $idx = $byId['certificate_code'] ?? null;
            $codeText = '{{certificate_code}}';
            if ($itemText !== ''
                && stripos($itemText, 'CERTIFICATE-CODE') === false
                && !preg_match('/^\{\{\s*certificate_code\s*\}\}$/i', $itemText)
            ) {
                $codeText = $itemText;
            }
            if ($idx === null) {
                $codeObj = certificate_pptx_sync_new_code_object($left, $top, $width, $height, $codeText, $style);
                $canvasState['objects'][] = $codeObj;
                $byId['certificate_code'] = count($canvasState['objects']) - 1;
                $used[count($canvasState['objects']) - 1] = true;
                $addedCode = true;
                $updated++;
            } else {
                certificate_pptx_sync_apply_exact_box(
                    $canvasState['objects'][$idx],
                    $left,
                    $top,
                    $width,
                    $height,
                    $codeText,
                    $style
                );
                $canvasState['objects'][$idx]['name'] = 'Certificate Code';
                $canvasState['objects'][$idx]['id'] = 'certificate_code';
                certificate_pptx_sync_clear_stale_geometry($canvasState['objects'][$idx]);
                $used[$idx] = true;
                $updated++;
            }
            continue;
        }

        if ($kind === 'signature') {
            $idx = certificate_pptx_sync_find_signature_index($canvasState['objects']);
            if ($idx !== null) {
                // Only apply person-name labels — never "of Apppreciation" / header fragments.
                $safeLabel = certificate_pptx_sync_safe_signature_label($itemText);
                certificate_pptx_sync_apply_signature_box(
                    $canvasState['objects'][$idx],
                    $left,
                    $top,
                    $width,
                    $height,
                    $safeLabel
                );
                certificate_pptx_sync_clear_stale_geometry($canvasState['objects'][$idx]);
                $used[$idx] = true;
                $updated++;
            } else {
                $skipped++;
            }
            continue;
        }

        $idx = null;
        // 1) Stable pc:id
        if ($itemId !== '' && isset($byId[$itemId]) && !isset($used[$byId[$itemId]])) {
            $idx = $byId[$itemId];
        }
        // 2) Unique identical text (1:1)
        if ($idx === null && $itemText !== '') {
            if (preg_match('/^authorized\s+signature$/i', $itemText)) {
                $skipped++;
                continue;
            }
            $textKey = mb_strtolower($itemText);
            if (isset($byText[$textKey]) && count($byText[$textKey]) === 1) {
                $cand = $byText[$textKey][0];
                if (!isset($used[$cand])) {
                    $idx = $cand;
                }
            }
        }

        // 2b) Bottom-region person name → signature label BEFORE spatial match
        // (otherwise "Juan Dela Cruz" snaps to nearby "Overall Chair" title).
        if ($idx === null && $itemText !== '' && $top > ($canvasH * 0.55)
            && certificate_pptx_sync_looks_like_signatory_name($itemText)
        ) {
            $sigIdx = certificate_pptx_sync_find_signature_index($canvasState['objects']);
            if ($sigIdx !== null) {
                certificate_pptx_sync_set_signature_label($canvasState['objects'][$sigIdx], $itemText);
                certificate_pptx_sync_clear_stale_geometry($canvasState['objects'][$sigIdx]);
                $used[$sigIdx] = true;
                $updated++;
                continue;
            }
        }

        // 3) Spatial nearest unused object of same type (header↔header, logo↔logo, …)
        if ($idx === null) {
            $prefer = 'any';
            if ($itemText !== '' || $kind === 'text_fallback') {
                $prefer = 'text';
            } elseif (isset($item['name']) && stripos((string) $item['name'], 'image') !== false) {
                $prefer = 'image';
            }
            if ($itemText === '' && $kind === 'object') {
                $prefer = 'image';
            }
            $idx = certificate_pptx_sync_match_by_position(
                $canvasState['objects'],
                $used,
                $left,
                $top,
                $width,
                $height,
                $prefer,
                $canvasW,
                $canvasH
            );
            if ($idx === null && $prefer === 'image') {
                $idx = certificate_pptx_sync_match_by_position(
                    $canvasState['objects'],
                    $used,
                    $left,
                    $top,
                    $width,
                    $height,
                    'any',
                    $canvasW,
                    $canvasH
                );
            }
        }

        if ($idx === null) {
            $skipped++;
            continue;
        }

        $obj = &$canvasState['objects'][$idx];
        $type = strtolower((string) ($obj['type'] ?? ''));
        $isText = in_array($type, ['i-text', 'text', 'textbox'], true);
        $isSignatureObj = $isText && (
            certificate_pptx_sync_is_signature_object($obj)
            || preg_match('/^authorized\s+signature$/i', trim((string) ($obj['text'] ?? ''))) === 1
        );

        if ($isSignatureObj) {
            $safeLabel = certificate_pptx_sync_safe_signature_label($itemText);
            certificate_pptx_sync_apply_signature_box(
                $obj,
                $left,
                $top,
                $width,
                $height,
                $safeLabel
            );
            certificate_pptx_sync_clear_stale_geometry($obj);
            $used[$idx] = true;
            unset($obj);
            $updated++;
            continue;
        }

        $mode = $isText ? $type : (in_array($type, ['image', 'rect', 'rectangle', 'line'], true) ? $type : 'shape');
        certificate_pptx_sync_apply_box($obj, $left, $top, $width, $height, $mode, $style);
        certificate_pptx_sync_clear_stale_geometry($obj);
        $used[$idx] = true;
        unset($obj);
        $updated++;
    }

    if ($skipped > 0) {
        $warnings[] = $skipped . ' PPT shape(s) could not be matched to the saved template (missing pc: ids). Re-export from Cert Templates for best accuracy.';
    }

    return [
        'canvas_state' => $canvasState,
        'updated' => $updated,
        'added_code' => $addedCode,
        'skipped' => $skipped,
        'warnings' => $warnings,
    ];
}

function certificate_pptx_sync_is_signature_object(array $obj): bool
{
    $text = str_replace(["\r\n", "\r"], "\n", (string) ($obj['text'] ?? ''));
    return preg_match('/^([_\x{2014}\x{2013}\-]{6,})\s*\n+.+$/u', trim($text)) === 1;
}

/**
 * Drop cached absolute coords so Fabric reloads from left/top/scale.
 *
 * @param array<string,mixed> $obj
 */
function certificate_pptx_sync_clear_stale_geometry(array &$obj): void
{
    unset($obj['aCoords'], $obj['oCoords'], $obj['lineCoords'], $obj['pathOffset']);
}

/**
 * Visual width/height of a Fabric object in canvas pixels.
 *
 * @param array<string,mixed> $obj
 * @return array{w:float,h:float,sx:float,sy:float}
 */
function certificate_pptx_sync_visual_size(array $obj): array
{
    $sx = (float) ($obj['scaleX'] ?? 1);
    $sy = (float) ($obj['scaleY'] ?? 1);
    if ($sx <= 0) {
        $sx = 1.0;
    }
    if ($sy <= 0) {
        $sy = 1.0;
    }
    $w = max(1.0, (float) ($obj['width'] ?? 0) * $sx);
    $h = max(1.0, (float) ($obj['height'] ?? 0) * $sy);
    return ['w' => $w, 'h' => $h, 'sx' => $sx, 'sy' => $sy];
}

/**
 * Set Fabric left/top so the object's visual top-left lands at ($left, $top).
 *
 * @param array<string,mixed> $obj
 */
function certificate_pptx_sync_set_top_left(array &$obj, float $left, float $top, float $visualW, float $visualH): void
{
    $originX = strtolower((string) ($obj['originX'] ?? 'left'));
    $originY = strtolower((string) ($obj['originY'] ?? 'top'));

    $fabricLeft = $left;
    $fabricTop = $top;
    if ($originX === 'center') {
        $fabricLeft = $left + ($visualW / 2.0);
    } elseif ($originX === 'right') {
        $fabricLeft = $left + $visualW;
    }
    if ($originY === 'center') {
        $fabricTop = $top + ($visualH / 2.0);
    } elseif ($originY === 'bottom') {
        $fabricTop = $top + $visualH;
    }

    $obj['left'] = round($fabricLeft, 2);
    $obj['top'] = round($fabricTop, 2);
}

/**
 * Position a signature text block using the combined PPT line+label box without warping.
 * Also apply PPT signatory label (e.g. "Juan Dela Cruz") into the Fabric text.
 *
 * @param array<string,mixed> $obj
 */
function certificate_pptx_sync_apply_signature_box(
    array &$obj,
    float $left,
    float $top,
    float $width,
    float $height,
    ?string $labelText = null
): void {
    $size = certificate_pptx_sync_visual_size($obj);
    // Only move when PPT box is in the lower half — bad/mis-tagged coords were piling
    // signature labels onto the CERTIFICATE header.
    $canvasHintTop = (float) ($obj['top'] ?? 0);
    $moveOk = $top > 200.0; // landscape certs: signature lives well below the title band
    if ($moveOk) {
        certificate_pptx_sync_set_top_left($obj, $left, $top, $size['w'], $size['h']);
        $obj['scaleX'] = $size['sx'];
        $obj['scaleY'] = $size['sy'];
    }
    if ($labelText !== null) {
        certificate_pptx_sync_set_signature_label($obj, $labelText);
    }
    unset($width, $height, $canvasHintTop);
}

/**
 * True when text looks like a person name (Juan Dela Cruz), not certificate copy.
 * Requires Title Case words; rejects "of Apppreciation", body phrases, codes.
 */
function certificate_pptx_sync_looks_like_signatory_name(string $text): bool
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($text === '' || str_contains($text, '{{') || mb_strlen($text) > 48) {
        return false;
    }
    if (preg_match('/^authorized\s+signature$/i', $text)) {
        return false;
    }
    if (function_exists('certificate_pptx_text_looks_like_code') && certificate_pptx_text_looks_like_code($text)) {
        return false;
    }
    // Certificate / subtitle / body fragments (this was matching "of Apppreciation").
    if (preg_match('/\b(of|the|a|an|for|is|to|and|with|during|organized|session|theme|given|certificate|appreciation|apppreciation|presented|proudly|attending|college|computing|studies|summit)\b/i', $text)) {
        return false;
    }
    $words = preg_split('/\s+/u', $text) ?: [];
    $n = count($words);
    if ($n < 2 || $n > 5) {
        return false;
    }
    foreach ($words as $w) {
        // Title Case / proper name tokens only (Juan, Dela, Cruz, McDonald).
        if (!preg_match('/^\p{Lu}[\p{L}\.\-\']*$/u', $w)) {
            return false;
        }
    }
    return true;
}

/**
 * Visual center of a Fabric object in canvas pixels.
 *
 * @param array<string,mixed> $obj
 * @return array{cx:float,cy:float,w:float,h:float}
 */
function certificate_pptx_sync_object_center(array $obj): array
{
    $size = certificate_pptx_sync_visual_size($obj);
    $left = (float) ($obj['left'] ?? 0);
    $top = (float) ($obj['top'] ?? 0);
    $ox = strtolower((string) ($obj['originX'] ?? 'left'));
    $oy = strtolower((string) ($obj['originY'] ?? 'top'));
    if ($ox === 'center') {
        $left -= $size['w'] / 2.0;
    } elseif ($ox === 'right') {
        $left -= $size['w'];
    }
    if ($oy === 'center') {
        $top -= $size['h'] / 2.0;
    } elseif ($oy === 'bottom') {
        $top -= $size['h'];
    }
    return [
        'cx' => $left + $size['w'] / 2.0,
        'cy' => $top + $size['h'] / 2.0,
        'w' => $size['w'],
        'h' => $size['h'],
    ];
}

/**
 * 1:1 spatial match: nearest unused template object of compatible type.
 *
 * @param list<array<string,mixed>> $objects
 * @param array<int,true> $used
 * @param 'text'|'image'|'shape'|'any' $prefer
 */
function certificate_pptx_sync_match_by_position(
    array $objects,
    array $used,
    float $left,
    float $top,
    float $width,
    float $height,
    string $prefer,
    float $canvasW,
    float $canvasH
): ?int {
    $cx = $left + $width / 2.0;
    $cy = $top + $height / 2.0;
    $maxDist = hypot($canvasW, $canvasH) * 0.14;
    $best = null;
    $bestScore = PHP_FLOAT_MAX;

    foreach ($objects as $i => $obj) {
        if (!is_array($obj) || isset($used[$i])) {
            continue;
        }
        $type = strtolower((string) ($obj['type'] ?? ''));
        $isText = in_array($type, ['i-text', 'text', 'textbox'], true);
        $isImage = ($type === 'image');
        $isShape = in_array($type, ['rect', 'rectangle', 'line', 'circle', 'triangle', 'path'], true);
        if ($prefer === 'text' && !$isText) {
            continue;
        }
        if ($prefer === 'image' && !$isImage) {
            continue;
        }
        if ($prefer === 'shape' && !$isShape && !$isImage) {
            continue;
        }
        // Never steal certificate_code or signature via loose spatial match.
        $oid = trim((string) ($obj['id'] ?? ''));
        $oname = strtolower(trim((string) ($obj['name'] ?? '')));
        if ($oid === 'certificate_code' || $oname === 'certificate code') {
            continue;
        }
        if ($isText && (certificate_pptx_sync_is_signature_object($obj) || preg_match('/^authorized\s+signature$/i', trim((string) ($obj['text'] ?? ''))))) {
            continue;
        }

        $c = certificate_pptx_sync_object_center($obj);
        $dist = hypot($c['cx'] - $cx, $c['cy'] - $cy);
        if ($dist > $maxDist) {
            continue;
        }
        // Prefer similar box size (1:1 element compare).
        $sizeRatio = max($width, $c['w']) / max(1.0, min($width, $c['w']));
        $sizeRatioH = max($height, $c['h']) / max(1.0, min($height, $c['h']));
        if ($sizeRatio > 4.0 || $sizeRatioH > 4.0) {
            continue;
        }
        $score = $dist + ($sizeRatio + $sizeRatioH - 2.0) * 20.0;
        if ($score < $bestScore) {
            $bestScore = $score;
            $best = (int) $i;
        }
    }
    return $best;
}

/**
 * Safe signature label from PPT — only person names (never "of Apppreciation").
 */
function certificate_pptx_sync_safe_signature_label(?string $text): ?string
{
    $text = trim((string) $text);
    if ($text === '') {
        return null;
    }
    // Multi-line: take first line if it's a name; allow "Name\nTitle".
    $lines = preg_split("/\r\n|\n|\r/", $text) ?: [$text];
    $first = trim((string) ($lines[0] ?? ''));
    if (!certificate_pptx_sync_looks_like_signatory_name($first)) {
        return null;
    }
    if (count($lines) === 1) {
        return $first;
    }
    $rest = [];
    for ($i = 1; $i < count($lines); $i++) {
        $line = trim((string) $lines[$i]);
        if ($line !== '') {
            $rest[] = $line;
        }
    }
    return $rest === [] ? $first : ($first . "\n" . implode("\n", $rest));
}

/**
 * Rewrite Fabric signature object label while keeping the underscore line.
 *
 * @param array<string,mixed> $obj
 */
function certificate_pptx_sync_set_signature_label(array &$obj, string $labelText): void
{
    $safe = certificate_pptx_sync_safe_signature_label($labelText);
    if ($safe === null) {
        return;
    }
    $labelText = $safe;

    $text = str_replace(["\r\n", "\r"], "\n", (string) ($obj['text'] ?? ''));
    $trimmed = trim($text);
    if (strcasecmp($trimmed, $labelText) === 0) {
        return;
    }
    // Plain editor label (no underscore line): replace with the registrar name only.
    if (preg_match('/^authorized\s+signature$/i', $trimmed)
        || !preg_match('/^[_\x{2014}\x{2013}\-]{6,}/u', $trimmed)
    ) {
        $obj['text'] = $labelText;
        return;
    }
    if (preg_match('/^([_\x{2014}\x{2013}\-]{6,})\s*\n+/u', $trimmed, $m)) {
        $obj['text'] = $m[1] . "\n" . $labelText;
    } else {
        $obj['text'] = $labelText;
    }
}

/**
 * Find first Fabric signature text object index.
 * Matches underscore+label blocks, name hints, or plain "Authorized Signature".
 *
 * @param list<array<string,mixed>> $objects
 */
function certificate_pptx_sync_find_signature_index(array $objects): ?int
{
    $plainAuth = null;
    foreach ($objects as $i => $obj) {
        if (!is_array($obj)) {
            continue;
        }
        $type = strtolower((string) ($obj['type'] ?? ''));
        if (!in_array($type, ['i-text', 'text', 'textbox'], true)) {
            continue;
        }
        $name = strtolower(trim((string) ($obj['name'] ?? '')));
        $text = str_replace(["\r\n", "\r"], "\n", trim((string) ($obj['text'] ?? '')));
        if ($name === 'signature' || $name === 'signature label') {
            return (int) $i;
        }
        if (certificate_pptx_sync_is_signature_object($obj)) {
            return (int) $i;
        }
        // Prefer bottom-left plain "Authorized Signature" (common editor export without underscores).
        if (preg_match('/^authorized\s+signature$/i', $text)) {
            $plainAuth = (int) $i;
        }
    }
    return $plainAuth;
}

/**
 * Pin an object to an exact PPT bounding box (origin left/top, scale 1).
 * Used for certificate_code so Import preview matches PowerPoint 1:1.
 *
 * @param array<string,mixed> $obj
 */
/**
 * @param array{fontSize:?float,textAlign:?string,fontWeight:?string,fontFamily:?string}|null $style
 */
function certificate_pptx_sync_apply_exact_box(
    array &$obj,
    float $left,
    float $top,
    float $width,
    float $height,
    ?string $text = null,
    ?array $style = null
): void {
    $type = strtolower((string) ($obj['type'] ?? 'textbox'));
    $isText = in_array($type, ['i-text', 'text', 'textbox'], true);

    $obj['originX'] = 'left';
    $obj['originY'] = 'top';
    $obj['left'] = round($left, 2);
    $obj['top'] = round($top, 2);
    $obj['width'] = round(max(1.0, $width), 2);
    $obj['height'] = round(max(1.0, $height), 2);
    $obj['scaleX'] = 1;
    $obj['scaleY'] = 1;
    $obj['angle'] = 0;

    if ($isText) {
        $pptFont = isset($style['fontSize']) && is_numeric($style['fontSize']) ? (float) $style['fontSize'] : null;
        if ($pptFont !== null && $pptFont >= 6.0) {
            // Authoritative PPT run size (same sz÷75 factor as export).
            $obj['fontSize'] = round($pptFont, 2);
        } else {
            $curFont = (float) ($obj['fontSize'] ?? 0);
            if ($curFont < 6.0 || $curFont > $height * 1.2) {
                $obj['fontSize'] = round(max(9.0, min(72.0, $height * 0.55)), 1);
            }
        }
        if ($text !== null && trim($text) !== '') {
            $obj['text'] = $text;
        }
        if (!empty($style['textAlign']) && is_string($style['textAlign'])) {
            $obj['textAlign'] = $style['textAlign'];
        } elseif (!isset($obj['textAlign']) || $obj['textAlign'] === '') {
            $obj['textAlign'] = 'left';
        }
        if (!empty($style['fontWeight']) && is_string($style['fontWeight'])) {
            $obj['fontWeight'] = $style['fontWeight'];
        }
        if (!empty($style['fontFamily']) && is_string($style['fontFamily'])) {
            $obj['fontFamily'] = $style['fontFamily'];
        }
    }
}

/**
 * @param array<string,mixed> $obj
 * @param string $mode text|textbox|image|rect|rectangle|line|shape
 * @param array{fontSize:?float,textAlign:?string,fontWeight:?string,fontFamily:?string}|null $style
 */
function certificate_pptx_sync_apply_box(
    array &$obj,
    float $left,
    float $top,
    float $width,
    float $height,
    string $mode,
    ?array $style = null
): void {
    $baseW = (float) ($obj['width'] ?? 0);
    $baseH = (float) ($obj['height'] ?? 0);
    $curSx = (float) ($obj['scaleX'] ?? 1);
    $curSy = (float) ($obj['scaleY'] ?? 1);
    if ($curSx <= 0) {
        $curSx = 1.0;
    }
    if ($curSy <= 0) {
        $curSy = 1.0;
    }
    if ($baseW < 1) {
        $baseW = $width;
        $obj['width'] = $width;
    }
    if ($baseH < 1) {
        $baseH = $height;
        $obj['height'] = $height;
    }

    $isText = in_array($mode, ['text', 'textbox', 'i-text'], true);
    $newSx = $curSx;
    $newSy = $curSy;

    if ($isText) {
        // Always bake PPT box at scale 1; use PPT fontSize when present.
        $obj['width'] = round(max(1.0, $width), 2);
        $obj['height'] = round(max(1.0, $height), 2);
        $pptFont = isset($style['fontSize']) && is_numeric($style['fontSize']) ? (float) $style['fontSize'] : null;
        if ($pptFont !== null && $pptFont >= 6.0) {
            $obj['fontSize'] = round($pptFont, 2);
        } else {
            $visualH = max(1.0, $baseH * $curSy);
            $curFont = (float) ($obj['fontSize'] ?? 24);
            if ($curFont < 1) {
                $curFont = 24.0;
            }
            $fontRatio = $height / max(1.0, $visualH);
            if (abs($fontRatio - 1.0) > 0.04) {
                $obj['fontSize'] = round(max(8.0, min(120.0, $curFont * $fontRatio)), 1);
            }
        }
        if (!empty($style['textAlign']) && is_string($style['textAlign'])) {
            $obj['textAlign'] = $style['textAlign'];
        }
        if (!empty($style['fontWeight']) && is_string($style['fontWeight'])) {
            $obj['fontWeight'] = $style['fontWeight'];
        }
        if (!empty($style['fontFamily']) && is_string($style['fontFamily'])) {
            $obj['fontFamily'] = $style['fontFamily'];
        }
        $newSx = 1.0;
        $newSy = 1.0;
        $baseW = (float) $obj['width'];
        $baseH = (float) $obj['height'];
    } else {
        // Images / shapes / lines: PPT size is authoritative.
        $newSx = max(0.05, $width / max(1.0, $baseW));
        $newSy = max(0.05, $height / max(1.0, $baseH));
        if ($mode === 'line') {
            // Keep stroke thickness stable; only stretch length (X).
            $newSy = $curSy;
        }
    }

    $obj['scaleX'] = round($newSx, 4);
    $obj['scaleY'] = round($newSy, 4);
    $visualW = max(1.0, $baseW * $newSx);
    $visualH = max(1.0, $baseH * $newSy);
    certificate_pptx_sync_set_top_left($obj, $left, $top, $visualW, $visualH);
}

/**
 * @param array{fontSize:?float,textAlign:?string,fontWeight:?string,fontFamily:?string}|null $style
 * @return array<string,mixed>
 */
function certificate_pptx_sync_new_code_object(
    float $left,
    float $top,
    float $width,
    float $height,
    string $sampleText = '',
    ?array $style = null
): array {
    $pptFont = isset($style['fontSize']) && is_numeric($style['fontSize']) ? (float) $style['fontSize'] : null;
    $fontSize = ($pptFont !== null && $pptFont >= 6.0)
        ? $pptFont
        : max(10.0, min(28.0, $height * 0.55));
    $text = trim($sampleText);
    if ($text === ''
        || stripos($text, 'CERTIFICATE-CODE') !== false
        || preg_match('/^\{\{\s*certificate_code\s*\}\}$/i', $text)
    ) {
        $text = '{{certificate_code}}';
    }
    $align = (!empty($style['textAlign']) && is_string($style['textAlign'])) ? $style['textAlign'] : 'left';
    $weight = (!empty($style['fontWeight']) && is_string($style['fontWeight'])) ? $style['fontWeight'] : 'bold';
    $family = (!empty($style['fontFamily']) && is_string($style['fontFamily'])) ? $style['fontFamily'] : 'Arial';
    return [
        'type' => 'textbox',
        'version' => '5.3.0',
        'originX' => 'left',
        'originY' => 'top',
        'left' => round($left, 2),
        'top' => round($top, 2),
        'width' => round(max(1.0, $width), 2),
        'height' => round(max(1.0, $height), 2),
        'fill' => '#111827',
        'stroke' => null,
        'strokeWidth' => 1,
        'strokeDashArray' => null,
        'strokeLineCap' => 'butt',
        'strokeDashOffset' => 0,
        'strokeLineJoin' => 'miter',
        'strokeUniform' => false,
        'strokeMiterLimit' => 4,
        'scaleX' => 1,
        'scaleY' => 1,
        'angle' => 0,
        'flipX' => false,
        'flipY' => false,
        'opacity' => 1,
        'shadow' => null,
        'visible' => true,
        'backgroundColor' => '',
        'fillRule' => 'nonzero',
        'paintFirst' => 'fill',
        'globalCompositeOperation' => 'source-over',
        'skewX' => 0,
        'skewY' => 0,
        'fontFamily' => $family,
        'fontWeight' => $weight,
        'fontSize' => round($fontSize, 1),
        'text' => $text,
        'underline' => false,
        'overline' => false,
        'linethrough' => false,
        'textAlign' => $align,
        'fontStyle' => 'normal',
        'lineHeight' => 1.16,
        'textBackgroundColor' => '',
        'charSpacing' => 0,
        'styles' => [],
        'direction' => 'ltr',
        'path' => null,
        'pathStartOffset' => 0,
        'pathSide' => 'left',
        'pathAlign' => 'baseline',
        'minWidth' => 20,
        'splitByGrapheme' => false,
        'id' => 'certificate_code',
        'name' => 'Certificate Code',
        'selectable' => true,
        'evented' => true,
    ];
}

/**
 * Code box from PPT layout when sync did not create a certificate_code object.
 * Prefer exact PPT box (scaled); otherwise bottom-left inset — never a wide centered band.
 *
 * @param array<string,mixed> $layout
 * @return array{left:float,top:float,width:float,height:float,style:?array}
 */
function certificate_pptx_sync_fallback_code_box(array $layout, float $cw, float $ch): array
{
    $codeItem = null;
    if (isset($layout['items']) && is_array($layout['items'])) {
        foreach ($layout['items'] as $it) {
            if (!is_array($it)) {
                continue;
            }
            if ((string) ($it['kind'] ?? '') === 'certificate_code' || (string) ($it['id'] ?? '') === 'certificate_code') {
                $codeItem = $it;
                break;
            }
        }
    }
    if ($codeItem !== null) {
        $lw = (float) ($layout['canvas_width'] ?? 0);
        $lh = (float) ($layout['canvas_height'] ?? 0);
        $sx = 1.0;
        $sy = 1.0;
        $ox = 0.0;
        $oy = 0.0;
        if ($lw > 10 && $lh > 10 && $cw > 10 && $ch > 10) {
            $aspectL = $lw / $lh;
            $aspectC = $cw / $ch;
            if (abs($aspectL - $aspectC) / max(0.01, $aspectC) > 0.02) {
                $uni = min($cw / $lw, $ch / $lh);
                $sx = $uni;
                $sy = $uni;
                $ox = ($cw - $lw * $uni) / 2.0;
                $oy = ($ch - $lh * $uni) / 2.0;
            } else {
                $sx = abs($lw - $cw) / $cw > 0.01 ? ($cw / $lw) : 1.0;
                $sy = abs($lh - $ch) / $ch > 0.01 ? ($ch / $lh) : 1.0;
                if (abs($sx - $sy) / max($sx, $sy, 0.01) > 0.01) {
                    $uni = min($sx, $sy);
                    $sx = $uni;
                    $sy = $uni;
                    $ox = ($cw - $lw * $uni) / 2.0;
                    $oy = ($ch - $lh * $uni) / 2.0;
                }
            }
        }
        $fontScale = ($sx + $sy) / 2.0;
        return [
            'left' => (float) ($codeItem['left'] ?? 0) * $sx + $ox,
            'top' => (float) ($codeItem['top'] ?? 0) * $sy + $oy,
            'width' => max(40.0, (float) ($codeItem['width'] ?? 220) * $sx),
            'height' => max(14.0, (float) ($codeItem['height'] ?? 22) * $sy),
            'style' => [
                'fontSize' => isset($codeItem['fontSize']) && is_numeric($codeItem['fontSize'])
                    ? (float) $codeItem['fontSize'] * $fontScale
                    : null,
                'textAlign' => isset($codeItem['textAlign']) && is_string($codeItem['textAlign']) ? $codeItem['textAlign'] : 'left',
                'fontWeight' => isset($codeItem['fontWeight']) && is_string($codeItem['fontWeight']) ? $codeItem['fontWeight'] : 'bold',
                'fontFamily' => isset($codeItem['fontFamily']) && is_string($codeItem['fontFamily']) ? $codeItem['fontFamily'] : 'Arial',
            ],
        ];
    }
    return [
        'left' => $cw * 0.05,
        'top' => $ch * 0.88,
        'width' => min(320.0, $cw * 0.35),
        'height' => max(18.0, $ch * 0.035),
        'style' => null,
    ];
}

/**
 * Load + ownership-check a library template, apply layout, PATCH canvas_state.
 *
 * @return array{ok:bool,error?:string,updated?:int,added_code?:bool,warnings?:list<string>,canvas_state?:array}
 */
function certificate_pptx_sync_template(
    string $templateId,
    string $userId,
    array $layout,
    ?string $eventId = null,
    ?string $thumbnailUrl = null,
    ?string $sampleCode = null
): array {
    $templateId = trim($templateId);
    $userId = trim($userId);
    if ($templateId === '' || $userId === '') {
        return ['ok' => false, 'error' => 'template_id required'];
    }

    $headers = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
        . '?select=id,title,canvas_state,created_by,event_id,thumbnail_url'
        . '&id=eq.' . rawurlencode($templateId)
        . '&limit=1';
    $res = supabase_request('GET', $url, $headers);
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    $row = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    if (!$row) {
        return ['ok' => false, 'error' => 'Template not found'];
    }

    $owner = (string) ($row['created_by'] ?? '');
    $linked = (string) ($row['event_id'] ?? '');
    $eventId = $eventId !== null ? trim($eventId) : '';
    if ($owner !== $userId && !($eventId !== '' && $linked === $eventId)) {
        return ['ok' => false, 'error' => 'Forbidden'];
    }
    // Never mutate a reusable Library row (event_id IS NULL) when syncing for an event.
    if ($eventId !== '' && $linked === '') {
        return ['ok' => false, 'error' => 'Sync target must be an event-scoped template copy'];
    }
    if ($eventId !== '' && $linked !== '' && $linked !== $eventId) {
        return ['ok' => false, 'error' => 'Template is linked to a different event'];
    }

    $canvas = $row['canvas_state'] ?? null;
    if (is_string($canvas)) {
        $canvas = json_decode($canvas, true);
    }
    if (!is_array($canvas)) {
        return ['ok' => false, 'error' => 'Template has no canvas state'];
    }

    // If PPT Certificate Code shape still has placeholder text, stamp first scanned code onto layout item.
    $sampleCode = trim((string) ($sampleCode ?? ''));
    if ($sampleCode !== '' && isset($layout['items']) && is_array($layout['items'])) {
        foreach ($layout['items'] as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $kind = (string) ($item['kind'] ?? '');
            $id = (string) ($item['id'] ?? '');
            if ($kind !== 'certificate_code' && $id !== 'certificate_code') {
                continue;
            }
            $txt = trim((string) ($item['text'] ?? ''));
            if ($txt === '' || stripos($txt, 'CERTIFICATE-CODE') !== false || preg_match('/^\{\{\s*certificate_code\s*\}\}$/i', $txt)) {
                $layout['items'][$i]['text'] = $sampleCode;
            }
        }
        // If no certificate_code layout item exists, still ensure object text after sync.
    }

    $sync = certificate_pptx_sync_apply_layout($canvas, $layout);

    // Guarantee sample code is on the Certificate Code object even without a PPT code shape text.
    if ($sampleCode !== '' && isset($sync['canvas_state']['objects']) && is_array($sync['canvas_state']['objects'])) {
        $found = false;
        foreach ($sync['canvas_state']['objects'] as $i => $obj) {
            if (!is_array($obj)) {
                continue;
            }
            $oid = trim((string) ($obj['id'] ?? ''));
            $oname = strtolower(trim((string) ($obj['name'] ?? '')));
            if ($oid === 'certificate_code' || $oname === 'certificate code') {
                $sync['canvas_state']['objects'][$i]['text'] = $sampleCode;
                $sync['canvas_state']['objects'][$i]['id'] = 'certificate_code';
                $sync['canvas_state']['objects'][$i]['name'] = 'Certificate Code';
                $found = true;
                break;
            }
        }
        if (!$found && $sampleCode !== '') {
            // Prefer PPT code box; only then a small bottom-left default (never wide centered band).
            $cw = (float) ($sync['canvas_state']['width'] ?? 1123);
            $ch = (float) ($sync['canvas_state']['height'] ?? 794);
            $box = certificate_pptx_sync_fallback_code_box($layout, $cw, $ch);
            $sync['canvas_state']['objects'][] = certificate_pptx_sync_new_code_object(
                $box['left'],
                $box['top'],
                $box['width'],
                $box['height'],
                $sampleCode,
                $box['style']
            );
            $sync['added_code'] = true;
        }
    }

    $payload = [
        'canvas_state' => $sync['canvas_state'],
        'updated_at' => gmdate('c'),
    ];
    // Keep existing thumbnail until the browser persists a fresh post-sync preview.
    if ($thumbnailUrl !== null && is_string($thumbnailUrl) && $thumbnailUrl !== '') {
        $payload['thumbnail_url'] = $thumbnailUrl;
    }

    $patchHeaders = [
        'Accept: application/json',
        'Content-Type: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: return=representation',
    ];
    $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates?id=eq.' . rawurlencode($templateId);
    $patch = supabase_request('PATCH', $patchUrl, $patchHeaders, json_encode($payload));
    if (!$patch['ok']) {
        return ['ok' => false, 'error' => 'Failed to update template canvas'];
    }

    return [
        'ok' => true,
        'updated' => $sync['updated'],
        'added_code' => $sync['added_code'],
        'warnings' => $sync['warnings'],
        'canvas_state' => $sync['canvas_state'],
    ];
}

/**
 * Sync PPTX layout onto an event_session_certificate_templates row (seminar copy).
 * Never touches Library certificate_templates.
 *
 * @return array{ok:bool,error?:string,updated?:int,added_code?:bool,warnings?:list<string>,canvas_state?:array}
 */
function certificate_pptx_sync_session_template(
    string $sessionTemplateId,
    string $userId,
    array $layout,
    ?string $sampleCode = null
): array {
    $sessionTemplateId = trim($sessionTemplateId);
    $userId = trim($userId);
    if ($sessionTemplateId === '' || $userId === '') {
        return ['ok' => false, 'error' => 'session_template_id required'];
    }

    $headers = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificate_templates'
        . '?select=id,title,canvas_state,created_by,session_id'
        . '&id=eq.' . rawurlencode($sessionTemplateId)
        . '&limit=1';
    $res = supabase_request('GET', $url, $headers);
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    $row = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    if (!$row) {
        return ['ok' => false, 'error' => 'Seminar template not found'];
    }
    $owner = (string) ($row['created_by'] ?? '');
    if ($owner !== '' && $owner !== $userId) {
        return ['ok' => false, 'error' => 'Forbidden'];
    }

    $canvas = $row['canvas_state'] ?? null;
    if (is_string($canvas)) {
        $canvas = json_decode($canvas, true);
    }
    if (!is_array($canvas)) {
        return ['ok' => false, 'error' => 'Seminar template has no canvas state'];
    }

    $sampleCode = trim((string) ($sampleCode ?? ''));
    if ($sampleCode !== '' && isset($layout['items']) && is_array($layout['items'])) {
        foreach ($layout['items'] as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $kind = (string) ($item['kind'] ?? '');
            $id = (string) ($item['id'] ?? '');
            if ($kind !== 'certificate_code' && $id !== 'certificate_code') {
                continue;
            }
            $txt = trim((string) ($item['text'] ?? ''));
            if ($txt === '' || stripos($txt, 'CERTIFICATE-CODE') !== false || preg_match('/^\{\{\s*certificate_code\s*\}\}$/i', $txt)) {
                $layout['items'][$i]['text'] = $sampleCode;
            }
        }
    }

    $sync = certificate_pptx_sync_apply_layout($canvas, $layout);

    if ($sampleCode !== '' && isset($sync['canvas_state']['objects']) && is_array($sync['canvas_state']['objects'])) {
        $found = false;
        foreach ($sync['canvas_state']['objects'] as $i => $obj) {
            if (!is_array($obj)) {
                continue;
            }
            $oid = trim((string) ($obj['id'] ?? ''));
            $oname = strtolower(trim((string) ($obj['name'] ?? '')));
            if ($oid === 'certificate_code' || $oname === 'certificate code') {
                $sync['canvas_state']['objects'][$i]['text'] = $sampleCode;
                $sync['canvas_state']['objects'][$i]['id'] = 'certificate_code';
                $sync['canvas_state']['objects'][$i]['name'] = 'Certificate Code';
                $found = true;
                break;
            }
        }
        if (!$found) {
            $cw = (float) ($sync['canvas_state']['width'] ?? 1123);
            $ch = (float) ($sync['canvas_state']['height'] ?? 794);
            $box = certificate_pptx_sync_fallback_code_box($layout, $cw, $ch);
            $sync['canvas_state']['objects'][] = certificate_pptx_sync_new_code_object(
                $box['left'],
                $box['top'],
                $box['width'],
                $box['height'],
                $sampleCode,
                $box['style']
            );
            $sync['added_code'] = true;
        }
    }

    $patchHeaders = [
        'Accept: application/json',
        'Content-Type: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: return=representation',
    ];
    $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificate_templates?id=eq.'
        . rawurlencode($sessionTemplateId);
    $patch = supabase_request('PATCH', $patchUrl, $patchHeaders, json_encode([
        'canvas_state' => $sync['canvas_state'],
        'updated_at' => gmdate('c'),
    ]));
    if (!$patch['ok']) {
        return ['ok' => false, 'error' => 'Failed to update seminar template canvas'];
    }

    return [
        'ok' => true,
        'updated' => $sync['updated'],
        'added_code' => $sync['added_code'] ?? false,
        'warnings' => $sync['warnings'],
        'canvas_state' => $sync['canvas_state'],
    ];
}
