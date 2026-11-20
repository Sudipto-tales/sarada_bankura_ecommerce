<?php

namespace App\Services\Import;

/**
 * Minimal dependency-free .xlsx writer for the import template:
 * several sheets, styled header row, frozen header, column widths and
 * dropdown (list) data validations. Cells are written as inline strings
 * so SKUs like "00123" never turn into numbers.
 */
final class XlsxWriter
{
    /** @var list<array{name:string,rows:list<list<string>>,widths:array<int,int>,required:list<int>,validations:list<array{col:int,source:string,strict:bool}>,headerRow:bool}> */
    private array $sheets = [];

    /**
     * @param list<list<string|int|float|null>> $rows first row = header
     * @param array<int,int> $widths column index → width
     * @param list<int> $required header columns highlighted as required
     * @param list<array{col:int,source:string,strict:bool}> $validations source = sheet range ("Lookups!$A$2:$A$40") or quoted csv list ("\"yes,no\"")
     */
    public function addSheet(string $name, array $rows, array $widths = [], array $required = [], array $validations = [], bool $headerRow = true): void
    {
        $this->sheets[] = [
            'name' => $name,
            'rows' => array_map(static fn($r) => array_map(static fn($c) => (string) ($c ?? ''), $r), $rows),
            'widths' => $widths,
            'required' => $required,
            'validations' => $validations,
            'headerRow' => $headerRow,
        ];
    }

    public function save(string $path): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot write ' . $path);
        }
        $n = count($this->sheets);

        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i = 1; $i <= $n; $i++) {
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $zip->addFromString('[Content_Types].xml', $ct . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');

        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $i => $s) {
            $id = $i + 1;
            $wb .= '<sheet name="' . self::esc($s['name']) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
            $rels .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
            $zip->addFromString('xl/worksheets/sheet' . $id . '.xml', $this->sheetXml($s));
        }
        $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $zip->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->close();
    }

    private function sheetXml(array $s): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        if ($s['headerRow']) {
            $x .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        }
        $x .= '<sheetFormatPr defaultRowHeight="18"/>';
        $cols = count($s['rows'][0] ?? []);
        if ($cols) {
            $x .= '<cols>';
            for ($c = 0; $c < $cols; $c++) {
                $w = $s['widths'][$c] ?? 18;
                $x .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"' . ($s['headerRow'] ? '' : ' style="3"') . '/>';
            }
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        foreach ($s['rows'] as $r => $row) {
            $x .= '<row r="' . ($r + 1) . '">';
            foreach ($row as $c => $val) {
                $style = 0;
                if ($r === 0 && $s['headerRow']) {
                    $style = in_array($c, $s['required'], true) ? 2 : 1;
                } elseif (!$s['headerRow']) {
                    $style = 3;
                }
                $x .= '<c r="' . self::col($c) . ($r + 1) . '" t="inlineStr"' . ($style ? ' s="' . $style . '"' : '') . '><is><t xml:space="preserve">' . self::esc($val) . '</t></is></c>';
            }
            $x .= '</row>';
        }
        $x .= '</sheetData>';
        if ($s['validations']) {
            $x .= '<dataValidations count="' . count($s['validations']) . '">';
            foreach ($s['validations'] as $v) {
                $L = self::col($v['col']);
                $x .= '<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorStyle="' . ($v['strict'] ? 'stop' : 'information') . '"'
                    . ' errorTitle="Not in list" error="' . self::esc($v['strict'] ? 'Pick a value from the list.' : 'Not in the list — it will be created automatically on import.') . '"'
                    . ' sqref="' . $L . '2:' . $L . '2000"><formula1>' . self::esc($v['source']) . '</formula1></dataValidation>';
            }
            $x .= '</dataValidations>';
        }

        return $x . '</worksheet>';
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FF3F2C03"/><name val="Calibri"/></font></fonts>'
            . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF3ECE0"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFDF7A"/></patternFill></fill></fills>'
            . '<borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="4"><xf/>'
            . '<xf fontId="1" fillId="2" applyFont="1" applyFill="1"/>'
            . '<xf fontId="1" fillId="3" applyFont="1" applyFill="1"/>'
            . '<xf applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf></cellXfs></styleSheet>';
    }

    public static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }

        return $s;
    }

    private static function esc(string $v): string
    {
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v) ?? '';

        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
