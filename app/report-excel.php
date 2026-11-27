<?php
declare(strict_types=1);

function helperReportExcel(string $event, string $meta, array $columns, array $entries): string
{
    $rows = '';
    foreach ([['Hjælperoversigt'], [$event], [$meta], [], $columns, ...($entries ?: [['Stævnet har endnu ingen vagtgrupper.']])] as $index => $values) {
        $number = $index + 1;
        $cells = '';
        foreach ($values as $column => $value) {
            if ($value === null) {
                continue;
            }
            $ref = chr(65 + $column) . $number;
            if ($number > 5 && in_array($column, [2,3], true)) {
                $serial = ($value + (int) date('Z', $value)) / 86400 + 25569;
                $cells .= '<c r="' . $ref . '" s="2"><v>' . sprintf('%.10F', $serial) . '</v></c>';
            } elseif (is_int($value)) {
                $cells .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
            } else {
                $style = $number === 5 ? ' s="1"' : '';
                $cells .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">' . excelText($value) . '</t></is></c>';
            }
        }
        $rows .= '<row r="' . $number . '">' . $cells . '</row>';
    }

    $filter = 'A5:J' . (count($entries) + 5);
    $worksheet = <<<XML
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetViews><sheetView workbookViewId="0"><pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>
  <cols>
    <col min="1" max="2" width="26" customWidth="1"/>
    <col min="3" max="4" width="22" customWidth="1"/>
    <col min="5" max="5" width="26" customWidth="1"/>
    <col min="6" max="6" width="36" customWidth="1"/>
    <col min="7" max="8" width="13" customWidth="1"/>
    <col min="9" max="9" width="18" customWidth="1"/>
    <col min="10" max="10" width="26" customWidth="1"/>
  </cols>
  <sheetData>$rows</sheetData>
  <autoFilter ref="$filter"/>
  <mergeCells count="3"><mergeCell ref="A1:J1"/><mergeCell ref="A2:J2"/><mergeCell ref="A3:J3"/></mergeCells>
</worksheet>
XML;
    $parts = [
        '[Content_Types].xml' => <<<'XML'
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>
XML,
        '_rels/.rels' => <<<'XML'
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML,
        'xl/workbook.xml' => <<<'XML'
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets><sheet name="Hjælperoversigt" sheetId="1" r:id="rId1"/></sheets>
</workbook>
XML,
        'xl/_rels/workbook.xml.rels' => <<<'XML'
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML,
        'xl/styles.xml' => <<<'XML'
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <numFmts count="1"><numFmt numFmtId="164" formatCode="yyyy-mm-dd hh:mm"/></numFmts>
  <fonts count="2">
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
  </fonts>
  <fills count="3">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF204432"/><bgColor indexed="64"/></patternFill></fill>
  </fills>
  <borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="3">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
    <xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>
  </cellXfs>
  <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>
XML,
        'xl/worksheets/sheet1.xml' => $worksheet,
    ];
    $path = tempnam(sys_get_temp_dir(), 'staevnevagt-xlsx-');
    if ($path === false) {
        throw new RuntimeException('Excel-filen kunne ikke oprettes.');
    }
    try {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Excel-filen kunne ikke åbnes.');
        }
        foreach ($parts as $name => $xml) {
            if (!$zip->addFromString($name, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . $xml)) {
                $zip->close();
                throw new RuntimeException('Excel-filen kunne ikke skrives.');
            }
        }
        if (!$zip->close()) {
            throw new RuntimeException('Excel-filen kunne ikke gemmes.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Excel-filen kunne ikke læses.');
        }
        return $contents;
    } finally {
        unlink($path);
    }
}

function excelText(string $text): string
{
    $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);
    return htmlspecialchars($text, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}
