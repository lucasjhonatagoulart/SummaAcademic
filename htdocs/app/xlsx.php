<?php
// Gerador de planilha Excel (.xlsx) sem bibliotecas externas: usa ZipArchive (disponível no InfinityFree).
// Cada aba: ['name' => 'Nome', 'cols' => [['Título', largura, tipo], ...], 'rows' => [[...], ...]]
// Tipos: 'text', 'money' (centavos → R$ com 2 casas), 'date' (AAAA-MM-DD → data do Excel), 'int'.

function xlsx_col(int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; }
function xlsx_esc(string $v): string { return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v), ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function xlsx_date(string $d): ?float { $t = strtotime($d . ' 00:00:00 UTC'); return $t === false ? null : $t / 86400 + 25569; }

function xlsx_sheet_xml(array $sh): string {
    $cols = '';
    foreach ($sh['cols'] as $i => $c) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . ($c[1] ?? 16) . '" customWidth="1"/>';
    $rowsXml = '<row r="1">';
    foreach ($sh['cols'] as $i => $c) $rowsXml .= '<c r="' . xlsx_col($i) . '1" t="inlineStr" s="1"><is><t>' . xlsx_esc($c[0]) . '</t></is></c>';
    $rowsXml .= '</row>';
    $r = 1;
    foreach ($sh['rows'] as $row) {
        $r++; $rowsXml .= '<row r="' . $r . '">';
        foreach ($sh['cols'] as $i => $c) {
            $v = $row[$i] ?? null; $ref = xlsx_col($i) . $r; $type = $c[2] ?? 'text';
            if ($v === null || $v === '') { continue; }
            if ($type === 'money' && is_numeric($v)) $rowsXml .= '<c r="' . $ref . '" s="2"><v>' . number_format($v / 100, 2, '.', '') . '</v></c>';
            elseif ($type === 'date' && ($n = xlsx_date((string) $v)) !== null) $rowsXml .= '<c r="' . $ref . '" s="3"><v>' . $n . '</v></c>';
            elseif ($type === 'int' && is_numeric($v)) $rowsXml .= '<c r="' . $ref . '"><v>' . (int) $v . '</v></c>';
            elseif ($type === 'bold') $rowsXml .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t xml:space="preserve">' . xlsx_esc((string) $v) . '</t></is></c>';
            else $rowsXml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_esc((string) $v) . '</t></is></c>';
        }
        $rowsXml .= '</row>';
    }
    $last = xlsx_col(count($sh['cols']) - 1) . max(1, $r);
    $freeze = empty($sh['nofreeze']) ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' : '<sheetViews><sheetView workbookViewId="0"/></sheetViews>';
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . $freeze . '<cols>' . $cols . '</cols><sheetData>' . $rowsXml . '</sheetData>'
        . (empty($sh['nofilter']) && $r > 1 ? '<autoFilter ref="A1:' . $last . '"/>' : '') . '</worksheet>';
}

/** Monta o .xlsx num arquivo temporário e devolve o caminho. */
function xlsx_build(array $sheets): string {
    if (!class_exists('ZipArchive')) throw new RuntimeException('O servidor não tem a extensão Zip do PHP.');
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $z = new ZipArchive();
    if ($z->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Não foi possível criar o arquivo.');
    $ct = ''; $wbSheets = ''; $rels = '';
    foreach ($sheets as $i => $sh) {
        $n = $i + 1;
        $z->addFromString("xl/worksheets/sheet$n.xml", xlsx_sheet_xml($sh));
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $wbSheets .= '<sheet name="' . xlsx_esc(mb_substr(str_replace(['/', '\\', '?', '*', '[', ']', ':'], '-', $sh['name']), 0, 31)) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
    }
    $k = count($sheets) + 1;
    $rels .= '<Relationship Id="rId' . $k . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>' . $ct . '</Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>');
    $z->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>' . xlsx_esc(BRAND['full']) . '</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wbSheets . '</sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
    // estilos: 0 normal · 1 cabeçalho (negrito, fundo azul-claro) · 2 valor 0,00 · 3 data dd/mm/aaaa
    $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0.00"/><numFmt numFmtId="165" formatCode="dd/mm/yyyy"/></numFmts>'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDCE6F4"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    $z->close();
    return $tmp;
}

/** Envia o .xlsx para download e encerra. */
function xlsx_download(array $sheets, string $filename): void {
    $tmp = xlsx_build($sheets);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: private, no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}
