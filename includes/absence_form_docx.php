<?php
declare(strict_types=1);

const ABSENCE_FORM_DOCX_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

function absence_form_template_path(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'templates'
        . DIRECTORY_SEPARATOR . 'LU-AA-FO-115-Approved-Absence-Form.docx';
}

function absence_form_month_abbrev(DateTimeInterface $dt): string
{
    return match ((int) $dt->format('n')) {
        1 => 'Jan.',
        2 => 'Feb.',
        3 => 'Mar.',
        4 => 'Apr.',
        5 => 'May',
        6 => 'Jun.',
        7 => 'Jul.',
        8 => 'Aug.',
        9 => 'Sept.',
        10 => 'Oct.',
        11 => 'Nov.',
        12 => 'Dec.',
        default => $dt->format('M') . '.',
    };
}

function absence_form_format_clock(?DateTimeInterface $dt): string
{
    if ($dt === null) {
        return '';
    }
    return strtolower($dt->format('g:ia'));
}

function absence_form_format_time_range(?DateTimeInterface $start, ?DateTimeInterface $end): string
{
    $a = absence_form_format_clock($start);
    $b = absence_form_format_clock($end);
    if ($a === '' && $b === '') {
        return '';
    }
    if ($a === '') {
        return $b;
    }
    if ($b === '') {
        return $a;
    }
    return $a . '-' . $b;
}

/**
 * Simple: Sept. 11, 2026
 * Same-month range: Sept. 11-12, 2026
 * Cross-month: Sept. 30-Oct. 1, 2026
 *
 * @param list<DateTimeInterface> $dates
 */
function absence_form_format_date_range(array $dates): string
{
    $uniq = [];
    foreach ($dates as $dt) {
        if (!$dt instanceof DateTimeInterface) {
            continue;
        }
        $key = $dt->format('Y-m-d');
        if (!isset($uniq[$key])) {
            $uniq[$key] = DateTimeImmutable::createFromInterface($dt);
        }
    }
    ksort($uniq);
    $list = array_values($uniq);
    if ($list === []) {
        return '';
    }
    $first = $list[0];
    $last = $list[count($list) - 1];
    if ($first->format('Y-m-d') === $last->format('Y-m-d')) {
        return absence_form_month_abbrev($first) . ' ' . $first->format('j, Y');
    }
    if ($first->format('Y') === $last->format('Y') && $first->format('n') === $last->format('n')) {
        return absence_form_month_abbrev($first) . ' ' . $first->format('j') . '-' . $last->format('j, Y');
    }
    if ($first->format('Y') === $last->format('Y')) {
        return absence_form_month_abbrev($first) . ' ' . $first->format('j')
            . '-' . absence_form_month_abbrev($last) . ' ' . $last->format('j, Y');
    }
    return absence_form_month_abbrev($first) . ' ' . $first->format('j, Y')
        . '-' . absence_form_month_abbrev($last) . ' ' . $last->format('j, Y');
}

/**
 * @param list<array<string,mixed>> $sessions
 * @return array{dates:string,times:string,venue:string,weekdays:list<string>,date_objects:list<DateTimeImmutable>}
 */
function absence_form_event_header(array $event, array $sessions, DateTimeZone $tz): array
{
    $toLocal = static function (?string $raw) use ($tz): ?DateTimeImmutable {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($raw))->setTimezone($tz);
        } catch (Throwable $e) {
            return null;
        }
    };

    $venue = trim((string) ($event['location'] ?? ''));
    $dateObjects = [];
    $timeParts = [];
    $venues = [];
    if ($venue !== '') {
        $venues[$venue] = true;
    }

    if ($sessions !== []) {
        foreach ($sessions as $session) {
            if (!is_array($session)) {
                continue;
            }
            $start = $toLocal((string) ($session['start_at'] ?? ''));
            $end = $toLocal((string) ($session['end_at'] ?? ''));
            if ($start instanceof DateTimeImmutable) {
                $dateObjects[] = $start;
            }
            if ($end instanceof DateTimeImmutable) {
                $dateObjects[] = $end;
            }
            $slot = absence_form_format_time_range($start, $end);
            if ($slot !== '') {
                $timeParts[] = $slot;
            }
            $loc = trim((string) ($session['location'] ?? ''));
            if ($loc !== '') {
                $venues[$loc] = true;
            }
        }
    } else {
        $start = $toLocal((string) ($event['start_at'] ?? ''));
        $end = $toLocal((string) ($event['end_at'] ?? ''));
        if ($start instanceof DateTimeImmutable) {
            $dateObjects[] = $start;
        }
        if ($end instanceof DateTimeImmutable) {
            $dateObjects[] = $end;
        }
        $slot = absence_form_format_time_range($start, $end);
        if ($slot !== '') {
            $timeParts[] = $slot;
        }
    }

    $weekdays = [];
    if (function_exists('student_class_schedule_weekday_codes_for_dates')) {
        $weekdays = student_class_schedule_weekday_codes_for_dates($dateObjects);
    }

    return [
        'dates' => absence_form_format_date_range($dateObjects),
        'times' => implode('/', $timeParts),
        'venue' => implode(' / ', array_keys($venues)),
        'weekdays' => $weekdays,
        'date_objects' => $dateObjects,
    ];
}

function absence_form_cell_text(DOMElement $tc): string
{
    return trim(preg_replace('/\s+/', ' ', $tc->textContent) ?? '');
}

/**
 * @return list<DOMElement>
 */
function absence_form_row_cells(DOMElement $tr): array
{
    $cells = [];
    foreach ($tr->childNodes as $child) {
        if ($child instanceof DOMElement && $child->localName === 'tc') {
            $cells[] = $child;
        }
    }
    return $cells;
}

/**
 * @return list<DOMElement>
 */
function absence_form_table_rows(DOMElement $tbl): array
{
    $rows = [];
    foreach ($tbl->childNodes as $child) {
        if ($child instanceof DOMElement && $child->localName === 'tr') {
            $rows[] = $child;
        }
    }
    return $rows;
}

function absence_form_clear_cell(DOMElement $tc): void
{
    $toRemove = [];
    foreach ($tc->childNodes as $child) {
        if ($child instanceof DOMElement && $child->localName === 'p') {
            $toRemove[] = $child;
        }
    }
    foreach ($toRemove as $p) {
        $tc->removeChild($p);
    }
}

/**
 * @param list<string> $lines
 */
function absence_form_set_cell_lines(DOMElement $tc, array $lines, bool $bold = false): void
{
    $doc = $tc->ownerDocument;
    if (!$doc instanceof DOMDocument) {
        return;
    }
    absence_form_clear_cell($tc);
    $lines = $lines === [] ? [''] : $lines;
    foreach ($lines as $line) {
        $line = trim($line);
        $p = $doc->createElementNS(ABSENCE_FORM_DOCX_NS, 'w:p');
        $r = $doc->createElementNS(ABSENCE_FORM_DOCX_NS, 'w:r');
        $rPr = $doc->createElementNS(ABSENCE_FORM_DOCX_NS, 'w:rPr');
        $fonts = $doc->createElementNS(ABSENCE_FORM_DOCX_NS, 'w:rFonts');
        $fonts->setAttribute('w:ascii', 'Century Gothic');
        $fonts->setAttribute('w:hAnsi', 'Century Gothic');
        $fonts->setAttribute('w:cs', 'Arial');
        $sz = $doc->createElementNS(ABSENCE_FORM_DOCX_NS, 'w:sz');
        $sz->setAttribute('w:val', '20');
        $szCs = $doc->createElementNS(ABSENCE_FORM_DOCX_NS, 'w:szCs');
        $szCs->setAttribute('w:val', '20');
        $rPr->appendChild($fonts);
        if ($bold) {
            $rPr->appendChild($doc->createElementNS(ABSENCE_FORM_DOCX_NS, 'w:b'));
        }
        $rPr->appendChild($sz);
        $rPr->appendChild($szCs);
        $r->appendChild($rPr);
        $t = $doc->createElementNS(ABSENCE_FORM_DOCX_NS, 'w:t');
        if ($line !== '' && ($line !== trim($line) || str_contains($line, '  '))) {
            $t->setAttribute('xml:space', 'preserve');
        }
        $t->appendChild($doc->createTextNode($line));
        $r->appendChild($t);
        $p->appendChild($r);
        $tc->appendChild($p);
    }
}

/**
 * Join subject labels with a trailing comma on every item except the last.
 *
 * @param list<mixed> $lines
 * @return list<string>
 */
function absence_form_comma_separated_lines(array $lines): array
{
    $clean = [];
    foreach ($lines as $line) {
        $text = trim((string) $line);
        if ($text === '') {
            continue;
        }
        $clean[] = rtrim($text, ',');
    }
    $n = count($clean);
    if ($n === 0) {
        return [''];
    }
    if ($n === 1) {
        return $clean;
    }
    $out = [];
    foreach ($clean as $i => $text) {
        $out[] = $i < $n - 1 ? $text . ',' : $text;
    }
    return $out;
}

function absence_form_program_from_section(string $sectionName): string
{
    $raw = trim($sectionName);
    if ($raw === '' || strcasecmp($raw, 'IRREGULAR') === 0) {
        return $raw !== '' ? $raw : '';
    }
    if (preg_match('/^(BSIT SD|BSIT BA|BSCS|BSIT)\b/i', $raw, $m)) {
        $p = strtoupper(trim((string) $m[1]));
        return $p === 'BSIT' ? 'BSIT SD' : $p;
    }
    return $raw;
}

/**
 * @param list<array{
 *   name:string,
 *   program:string,
 *   year_level:string,
 *   course_codes:list<string>,
 *   course_descriptions:list<string>,
 *   instructors:list<string>
 * }> $students
 */
function absence_form_fill_document_xml(string $xml, string $dateText, string $timeText, string $venueText, array $students): string
{
    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = true;
    $doc->formatOutput = false;
    if (@$doc->loadXML($xml) !== true) {
        throw new RuntimeException('Could not read the absence-form template.');
    }
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('w', ABSENCE_FORM_DOCX_NS);

    $tables = $xp->query('//w:tbl');
    if (!$tables instanceof DOMNodeList || $tables->length < 2) {
        throw new RuntimeException('Absence-form template is missing the student table.');
    }
    $tbl = $tables->item(1);
    if (!$tbl instanceof DOMElement) {
        throw new RuntimeException('Absence-form template is missing the student table.');
    }

    $rows = absence_form_table_rows($tbl);
    if (count($rows) < 4) {
        throw new RuntimeException('Absence-form template table is incomplete.');
    }

    $headerCells = absence_form_row_cells($rows[0]);
    if (count($headerCells) >= 6) {
        absence_form_set_cell_lines($headerCells[1], [$dateText], true);
        absence_form_set_cell_lines($headerCells[3], [$timeText], true);
        absence_form_set_cell_lines($headerCells[5], [$venueText], true);
    }

    $templateRow = $rows[3];
    $dataRows = array_slice($rows, 3);
    $needed = max(1, count($students));

    while (count($dataRows) < $needed) {
        $clone = $templateRow->cloneNode(true);
        if (!$clone instanceof DOMElement) {
            break;
        }
        $tbl->appendChild($clone);
        $dataRows[] = $clone;
    }

    $rows = absence_form_table_rows($tbl);
    $dataRows = array_slice($rows, 3);

    foreach ($dataRows as $i => $tr) {
        $cells = absence_form_row_cells($tr);
        if (count($cells) < 6) {
            continue;
        }
        $student = $students[$i] ?? null;
        if (!is_array($student)) {
            absence_form_set_cell_lines($cells[0], ['']);
            absence_form_set_cell_lines($cells[1], ['']);
            absence_form_set_cell_lines($cells[2], ['']);
            absence_form_set_cell_lines($cells[3], ['']);
            absence_form_set_cell_lines($cells[4], ['']);
            absence_form_set_cell_lines($cells[5], ['']);
            continue;
        }
        absence_form_set_cell_lines($cells[0], $student['course_codes'] ?? []);
        absence_form_set_cell_lines($cells[1], absence_form_comma_separated_lines($student['course_descriptions'] ?? []));
        absence_form_set_cell_lines($cells[2], absence_form_comma_separated_lines($student['instructors'] ?? []));
        absence_form_set_cell_lines($cells[3], [(string) ($student['name'] ?? '')]);
        absence_form_set_cell_lines($cells[4], [(string) ($student['program'] ?? '')]);
        absence_form_set_cell_lines($cells[5], [(string) ($student['year_level'] ?? '')]);
    }

    $out = $doc->saveXML();
    if (!is_string($out) || $out === '') {
        throw new RuntimeException('Could not write the absence-form document.');
    }
    return $out;
}

/**
 * @param list<array<string,mixed>> $students
 */
function absence_form_build_docx(string $dateText, string $timeText, string $venueText, array $students): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required to export the absence form.');
    }
    $template = absence_form_template_path();
    if (!is_file($template)) {
        throw new RuntimeException('Absence-form template is missing on the server.');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'pulse_abs_');
    if ($tmp === false) {
        throw new RuntimeException('Unable to allocate a temporary file.');
    }
    $zipPath = $tmp . '.docx';
    if (!@copy($template, $zipPath)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to copy the absence-form template.');
    }
    @unlink($tmp);

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        @unlink($zipPath);
        throw new RuntimeException('Unable to open the absence-form template.');
    }
    $xml = $zip->getFromName('word/document.xml');
    if (!is_string($xml) || $xml === '') {
        $zip->close();
        @unlink($zipPath);
        throw new RuntimeException('Absence-form template document.xml is missing.');
    }
    $filled = absence_form_fill_document_xml($xml, $dateText, $timeText, $venueText, $students);
    $zip->deleteName('word/document.xml');
    $zip->addFromString('word/document.xml', $filled);
    $zip->close();

    $binary = (string) file_get_contents($zipPath);
    @unlink($zipPath);
    if ($binary === '') {
        throw new RuntimeException('Absence-form export was empty.');
    }
    return $binary;
}

/**
 * Sort like admin Blocks: BSIT SD → BSIT BA → BSCS → other → IRREGULAR, then year, letter, surname.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function absence_form_sort_students(array $rows): array
{
    $programRank = static function (string $program, string $section): int {
        $p = strtoupper(trim($program));
        if ($p === '' ) {
            $p = strtoupper(trim($section));
        }
        if ($p === 'IRREGULAR' || strcasecmp(trim($section), 'IRREGULAR') === 0) {
            return 90;
        }
        if (str_starts_with($p, 'BSIT SD')) {
            return 10;
        }
        if (str_starts_with($p, 'BSIT BA')) {
            return 20;
        }
        if (str_starts_with($p, 'BSCS')) {
            return 30;
        }
        if (str_starts_with($p, 'BSIT')) {
            return 15;
        }
        return 50;
    };

    $yearNum = static function (string $year, string $section): int {
        if (preg_match('/\b([1-4])\b/', $year, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/\b([1-4])[A-Z]\b/i', $section, $m)) {
            return (int) $m[1];
        }
        return 9;
    };

    $letter = static function (string $section): string {
        if (preg_match('/\b[1-4]([A-F])\b/i', $section, $m)) {
            return strtoupper($m[1]);
        }
        return 'Z';
    };

    usort($rows, static function (array $a, array $b) use ($programRank, $yearNum, $letter): int {
        $secA = (string) ($a['section'] ?? '');
        $secB = (string) ($b['section'] ?? '');
        $p = $programRank((string) ($a['program'] ?? ''), $secA)
            <=> $programRank((string) ($b['program'] ?? ''), $secB);
        if ($p !== 0) {
            return $p;
        }
        $y = $yearNum((string) ($a['year_level'] ?? ''), $secA)
            <=> $yearNum((string) ($b['year_level'] ?? ''), $secB);
        if ($y !== 0) {
            return $y;
        }
        $l = $letter($secA) <=> $letter($secB);
        if ($l !== 0) {
            return $l;
        }
        $ln = strcasecmp((string) ($a['last_name'] ?? ''), (string) ($b['last_name'] ?? ''));
        if ($ln !== 0) {
            return $ln;
        }
        return strcasecmp((string) ($a['first_name'] ?? ''), (string) ($b['first_name'] ?? ''));
    });
    return $rows;
}
