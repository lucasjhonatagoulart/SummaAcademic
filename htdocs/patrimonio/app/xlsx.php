<?php

declare(strict_types=1);

function xlsx_col(int $number): string
{
    $result = '';
    while ($number > 0) {
        $remainder = ($number - 1) % 26;
        $result = chr(65 + $remainder) . $result;
        $number = intdiv($number - 1, 26);
    }
    return $result;
}

function xlsx_cell(mixed $value, string $ref): string
{
    if ($value === null || $value === '') return '<c r="' . $ref . '"/>';
    if (is_int($value) || is_float($value)) {
        return '<c r="' . $ref . '" t="n"><v>' . htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</v></c>';
    }
    $text = (string)$value;
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? '';
    $text = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
}

function xlsx_sheet_xml(array $rows): string
{
    $xmlRows = '';
    foreach ($rows as $r => $row) {
        $rowNumber = $r + 1;
        $xmlCells = '';
        foreach (array_values($row) as $c => $value) $xmlCells .= xlsx_cell($value, xlsx_col($c + 1) . $rowNumber);
        $xmlRows .= '<row r="' . $rowNumber . '">' . $xmlCells . '</row>';
    }
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetViews><sheetView workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="18"/><sheetData>'
        . $xmlRows . '</sheetData></worksheet>';
}

function xlsx_build(array $sheets): string
{
    if (!$sheets) throw new RuntimeException('A planilha não tem abas.');
    $files = [];
    $contentTypes = '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    $workbookSheets = '';
    $relationships = '';
    $position = 0;
    foreach ($sheets as $name => $rows) {
        $id = ++$position;
        $name = (string)$name;
        $safeName = htmlspecialchars($name, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $workbookSheets .= '<sheet name="' . $safeName . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
        $relationships .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
        $contentTypes .= '<Override PartName="/xl/worksheets/sheet' . $id . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $files['xl/worksheets/sheet' . $id . '.xml'] = xlsx_sheet_xml($rows);
    }
    $files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>' . $contentTypes . '</Types>';
    $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
        . $workbookSheets . '</sheets></workbook>';
    $files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relationships . '</Relationships>';
    return zip_build_stored($files);
}
