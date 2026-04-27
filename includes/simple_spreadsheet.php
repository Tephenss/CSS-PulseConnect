<?php
declare(strict_types=1);

function spreadsheet_xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function spreadsheet_column_label(int $index): string
{
    $index++;
    $label = '';
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $label = chr(65 + $mod) . $label;
        $index = intdiv($index - 1, 26);
    }
    return $label;
}

function spreadsheet_cell_xml(string $cellRef, string $value, int $styleIndex = 0): string
{
    $styleAttr = $styleIndex > 0 ? ' s="' . $styleIndex . '"' : '';
    return '<c r="' . $cellRef . '"' . $styleAttr . ' t="inlineStr"><is><t xml:space="preserve">'
        . spreadsheet_xml_escape($value)
        . '</t></is></c>';
}

function build_simple_xlsx(array $headerRow, array $dataRows, string $sheetName = 'Registration Access', array $options = []): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required to export Excel files.');
    }

    $columnCount = max(1, count($headerRow));
    $lastColumnLabel = spreadsheet_column_label($columnCount - 1);
    $title = trim((string) ($options['title'] ?? 'PulseConnect Registration Access'));
    $subtitle = trim((string) ($options['subtitle'] ?? 'Mark the PAID column with YES or a check mark for students who may register.'));
    $instruction = trim((string) ($options['instruction'] ?? 'Only update the PAID and PAYMENT NOTE columns. Leave the student details unchanged.'));
    $columnWidths = is_array($options['columnWidths'] ?? null)
        ? array_values($options['columnWidths'])
        : [];
    $hiddenColumns = [];
    foreach ((array) ($options['hiddenColumns'] ?? []) as $index) {
        if (is_numeric($index)) {
            $hiddenColumns[(int) $index] = true;
        }
    }

    $rows = [
        ['values' => [$title], 'style' => 1, 'height' => 30],
        ['values' => [$subtitle], 'style' => 2, 'height' => 22],
        ['values' => [$instruction], 'style' => 3, 'height' => 38],
        ['values' => $headerRow, 'style' => 4, 'height' => 24],
    ];

    foreach ($dataRows as $row) {
        $rows[] = [
            'values' => array_values(is_array($row) ? $row : []),
            'style' => 5,
            'height' => 20,
        ];
    }

    $sheetXmlRows = [];
    foreach ($rows as $rowIndex => $rowMeta) {
        $row = array_values(is_array($rowMeta['values'] ?? null) ? $rowMeta['values'] : []);
        $rowStyle = (int) ($rowMeta['style'] ?? 0);
        $rowHeight = (int) ($rowMeta['height'] ?? 20);
        $cells = [];
        foreach ($row as $columnIndex => $value) {
            $cellRef = spreadsheet_column_label($columnIndex) . ($rowIndex + 1);
            $styleIndex = $rowStyle;

            if ($rowStyle === 5) {
                if (in_array($columnIndex, [0, 1, 3, 4, 6, 8, 9], true)) {
                    $styleIndex = 6;
                }
                if ($columnIndex === 7) {
                    $styleIndex = 7;
                }
                if ($columnIndex === 6 && trim((string) $value) !== '') {
                    $styleIndex = 8;
                }
            }

            $cells[] = spreadsheet_cell_xml($cellRef, (string) $value, $styleIndex);
        }
        $sheetXmlRows[] = '<row r="' . ($rowIndex + 1) . '" ht="' . $rowHeight . '" customHeight="1">'
            . implode('', $cells)
            . '</row>';
    }

    $colsXml = '';
    if ($columnWidths !== []) {
        $cols = [];
        foreach ($columnWidths as $index => $width) {
            $numericWidth = is_numeric($width) ? (float) $width : 14.0;
            $hiddenAttr = isset($hiddenColumns[$index]) ? ' hidden="1"' : '';
            $cols[] = '<col min="' . ($index + 1) . '" max="' . ($index + 1) . '" width="' . $numericWidth . '" customWidth="1"' . $hiddenAttr . '/>';
        }
        $colsXml = '<cols>' . implode('', $cols) . '</cols>';
    }

    $mergeCellsXml = '<mergeCells count="3">'
        . '<mergeCell ref="A1:' . $lastColumnLabel . '1"/>'
        . '<mergeCell ref="A2:' . $lastColumnLabel . '2"/>'
        . '<mergeCell ref="A3:' . $lastColumnLabel . '3"/>'
        . '</mergeCells>';

    $lastDataRow = max(4, count($rows));
    $autoFilterXml = '<autoFilter ref="A4:' . $lastColumnLabel . $lastDataRow . '"/>';

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="20"/>'
        . $colsXml
        . '<sheetData>' . implode('', $sheetXmlRows) . '</sheetData>'
        . $autoFilterXml
        . $mergeCellsXml
        . '</worksheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . spreadsheet_xml_escape($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';

    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="5">'
        . '<font><sz val="11"/><name val="Calibri"/><color rgb="FF111827"/></font>'
        . '<font><b/><sz val="18"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/><color rgb="FF9A3412"/></font>'
        . '<font><sz val="10"/><name val="Calibri"/><color rgb="FF374151"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font>'
        . '</fonts>'
        . '<fills count="8">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF97316"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFEDD5"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE0F2FE"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF0F766E"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFDCFCE7"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FFE5E7EB"/></left><right style="thin"><color rgb="FFE5E7EB"/></right><top style="thin"><color rgb="FFE5E7EB"/></top><bottom style="thin"><color rgb="FFE5E7EB"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="9">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="4" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $now = gmdate('Y-m-d\TH:i:s\Z');
    $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
        . ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
        . ' xmlns:dcterms="http://purl.org/dc/terms/"'
        . ' xmlns:dcmitype="http://purl.org/dc/dcmitype/"'
        . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:creator>PulseConnect</dc:creator>'
        . '<cp:lastModifiedBy>PulseConnect</cp:lastModifiedBy>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
        . '</cp:coreProperties>';

    $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
        . ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>PulseConnect</Application>'
        . '</Properties>';

    $tmpFile = tempnam(sys_get_temp_dir(), 'pulse_xlsx_');
    if ($tmpFile === false) {
        throw new RuntimeException('Unable to allocate a temporary file for Excel export.');
    }

    $zipFile = $tmpFile . '.xlsx';
    if (!@rename($tmpFile, $zipFile)) {
        @unlink($tmpFile);
        throw new RuntimeException('Unable to prepare the export file.');
    }

    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($zipFile);
        throw new RuntimeException('Unable to build the Excel workbook.');
    }

    $zip->addFromString('[Content_Types].xml', $contentTypesXml);
    $zip->addFromString('_rels/.rels', $relsXml);
    $zip->addFromString('docProps/app.xml', $appXml);
    $zip->addFromString('docProps/core.xml', $coreXml);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    $binary = (string) file_get_contents($zipFile);
    @unlink($zipFile);

    if ($binary === '') {
        throw new RuntimeException('Excel export was empty.');
    }

    return $binary;
}

function read_uploaded_spreadsheet_rows(string $path, string $originalName): array
{
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension === 'csv') {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to read the uploaded CSV file.');
        }

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = array_map(static fn ($value): string => trim((string) $value), $row);
        }
        fclose($handle);
        return $rows;
    }

    if ($extension !== 'xlsx') {
        throw new RuntimeException('Please upload the exported .xlsx or .csv file.');
    }

    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required to import Excel files.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Unable to open the uploaded Excel file.');
    }

    $sharedStrings = [];
    $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
    if (is_string($sharedStringsXml) && $sharedStringsXml !== '') {
        $xml = @simplexml_load_string($sharedStringsXml);
        if ($xml !== false) {
            $xml->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach (($xml->xpath('//main:si') ?: []) as $item) {
                $item->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $texts = $item->xpath('.//main:t') ?: [];
                $value = '';
                foreach ($texts as $textNode) {
                    $value .= (string) $textNode;
                }
                $sharedStrings[] = $value;
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    if (!is_string($sheetXml) || $sheetXml === '') {
        throw new RuntimeException('The uploaded Excel file does not contain a readable first worksheet.');
    }

    $xml = @simplexml_load_string($sheetXml);
    if ($xml === false) {
        throw new RuntimeException('Unable to parse the uploaded Excel worksheet.');
    }

    $xml->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rows = [];
    foreach (($xml->xpath('//main:sheetData/main:row') ?: []) as $rowNode) {
        $rowNode->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rowValues = [];
        foreach (($rowNode->xpath('./main:c') ?: []) as $cell) {
            $cell->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $reference = (string) ($cell['r'] ?? '');
            $columnLetters = preg_replace('/[^A-Z]/', '', strtoupper($reference));
            $columnIndex = 0;
            for ($i = 0; $i < strlen($columnLetters); $i++) {
                $columnIndex = ($columnIndex * 26) + (ord($columnLetters[$i]) - 64);
            }
            $columnIndex = max(0, $columnIndex - 1);

            $type = (string) ($cell['t'] ?? '');
            $value = '';
            if ($type === 'inlineStr') {
                $texts = $cell->xpath('./main:is/main:t') ?: [];
                foreach ($texts as $textNode) {
                    $value .= (string) $textNode;
                }
            } elseif ($type === 's') {
                $index = (int) ($cell->v ?? 0);
                $value = $sharedStrings[$index] ?? '';
            } else {
                $value = (string) ($cell->v ?? '');
            }

            $rowValues[$columnIndex] = trim($value);
        }

        if ($rowValues === []) {
            $rows[] = [];
            continue;
        }

        ksort($rowValues);
        $normalizedRow = [];
        $lastIndex = max(array_keys($rowValues));
        for ($i = 0; $i <= $lastIndex; $i++) {
            $normalizedRow[] = $rowValues[$i] ?? '';
        }
        $rows[] = $normalizedRow;
    }

    return $rows;
}
