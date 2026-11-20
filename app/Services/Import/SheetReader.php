<?php

namespace App\Services\Import;

/**
 * Reads CSV and XLSX files into plain arrays without third-party libraries
 * (no Composer on this project). XLSX is parsed with ZipArchive + XMLReader.
 *
 * Every sheet comes back as a list of associative rows keyed by normalised
 * header ("SKU *" → "sku", "Attr: Finish" → "attr:finish"). Each row also has
 * a "__line" key with its 1-based spreadsheet line number for error messages.
 */
final class SheetReader
{
    /** @return array<string, list<array<string,string|int>>> sheet name (lowercase) → rows */
    public static function read(string $path, string $ext): array
    {
        $ext = strtolower($ext);
        if ($ext === 'csv' || $ext === 'txt') {
            return ['products' => self::rowsFromMatrix(self::csvMatrix($path))];
        }
        if ($ext === 'xlsx') {
            $out = [];
            foreach (self::xlsxMatrices($path) as $name => $matrix) {
                $out[strtolower(trim($name))] = self::rowsFromMatrix($matrix);
            }

            return $out;
        }

        throw new \RuntimeException('Unsupported file type ".' . $ext . '". Upload .xlsx or .csv.');
    }

    public static function header(string $h): string
    {
        $h = strtolower(trim(str_replace("\u{FEFF}", '', $h)));
        $h = rtrim($h, " *");
        $h = preg_replace('/\s*:\s*/', ':', $h) ?? $h;

        return preg_replace('/[\s\-]+/', '_', $h) ?? $h;
    }

    /** @param list<list<string>> $matrix */
    private static function rowsFromMatrix(array $matrix): array
    {
        // First non-empty row is the header.
        $headers = null;
        $rows = [];
        foreach ($matrix as $i => $cells) {
            $nonEmpty = array_filter($cells, static fn($c) => trim((string) $c) !== '');
            if ($headers === null) {
                if ($nonEmpty) {
                    $headers = array_map([self::class, 'header'], $cells);
                }
                continue;
            }
            if (!$nonEmpty) {
                continue;
            }
            $row = ['__line' => $i + 1];
            foreach ($headers as $c => $h) {
                if ($h === '') {
                    continue;
                }
                $row[$h] = trim((string) ($cells[$c] ?? ''));
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return list<list<string>> */
    private static function csvMatrix(string $path): array
    {
        $raw = (string) file_get_contents($path);
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        } elseif (!mb_check_encoding($raw, 'UTF-8')) {
            // Excel "CSV" saved on Windows is often Windows-1252.
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $firstLine = strtok($raw, "\n") ?: '';
        $delim = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : (substr_count($firstLine, "\t") > substr_count($firstLine, ',') ? "\t" : ',');

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $out = [];
        while (($cells = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            $out[] = array_map(static fn($c) => (string) $c, $cells);
        }
        fclose($fh);

        return $out;
    }

    /** @return array<string, list<list<string>>> */
    private static function xlsxMatrices(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Could not open the Excel file — is it a real .xlsx?');
        }

        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $r = new \XMLReader();
            $r->XML($xml);
            $buf = null;
            while ($r->read()) {
                if ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'si') {
                    $buf = '';
                } elseif ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 't' && $buf !== null) {
                    $buf .= $r->readString();
                } elseif ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'rPh') {
                    $r->next(); // skip phonetic runs
                } elseif ($r->nodeType === \XMLReader::END_ELEMENT && $r->localName === 'si') {
                    $shared[] = $buf ?? '';
                    $buf = null;
                }
            }
        }

        // Sheet name → file path via workbook rels.
        $rels = [];
        $relXml = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
        foreach ($relXml?->Relationship ?? [] as $rel) {
            $target = ltrim((string) $rel['Target'], '/');
            $rels[(string) $rel['Id']] = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
        }
        $wb = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
        $sheets = [];
        if ($wb) {
            foreach ($wb->sheets->sheet ?? [] as $s) {
                $rid = (string) ($s->attributes('r', true)['id'] ?? '');
                if (isset($rels[$rid])) {
                    $sheets[(string) $s['name']] = $rels[$rid];
                }
            }
        }

        $out = [];
        foreach ($sheets as $name => $file) {
            $xml = $zip->getFromName($file);
            if ($xml === false) {
                continue;
            }
            $out[$name] = self::sheetMatrix($xml, $shared);
        }
        $zip->close();

        return $out;
    }

    /** @param list<string> $shared @return list<list<string>> */
    private static function sheetMatrix(string $xml, array $shared): array
    {
        $r = new \XMLReader();
        $r->XML($xml);
        $matrix = [];
        $rowIdx = -1;
        while ($r->read()) {
            if ($r->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($r->localName === 'row') {
                $rowIdx = ((int) $r->getAttribute('r') ?: $rowIdx + 2) - 1;
                $matrix[$rowIdx] ??= [];
            } elseif ($r->localName === 'c') {
                $ref = (string) $r->getAttribute('r');
                $type = (string) $r->getAttribute('t');
                $col = self::colIndex($ref);
                $value = '';
                if (!$r->isEmptyElement) {
                    $cell = new \SimpleXMLElement($r->readOuterXml());
                    if ($type === 'inlineStr') {
                        $value = '';
                        foreach ($cell->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                            $value .= (string) $t;
                        }
                    } else {
                        $v = (string) ($cell->v ?? '');
                        $value = match ($type) {
                            's' => $shared[(int) $v] ?? '',
                            'b' => $v === '1' ? 'yes' : 'no',
                            default => self::number($v),
                        };
                    }
                }
                $matrix[$rowIdx][$col] = $value;
            }
        }
        ksort($matrix);
        $out = [];
        $prev = -1;
        foreach ($matrix as $i => $cells) {
            for ($gap = $prev + 1; $gap < $i; $gap++) {
                $out[] = [];
            }
            $prev = $i;
            $row = [];
            $max = $cells ? max(array_keys($cells)) : -1;
            for ($c = 0; $c <= $max; $c++) {
                $row[] = $cells[$c] ?? '';
            }
            $out[] = $row;
        }

        return $out;
    }

    /** Excel stores 1250.5 as "1250.5" but also floats like "0.30000000000000004". */
    private static function number(string $v): string
    {
        if ($v === '' || !is_numeric($v) || !str_contains(strtolower($v), 'e') && !preg_match('/\.\d{10,}/', $v)) {
            return $v;
        }

        return rtrim(rtrim(sprintf('%.6F', (float) $v), '0'), '.');
    }

    private static function colIndex(string $ref): int
    {
        $letters = preg_replace('/\d+/', '', $ref) ?? 'A';
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return max(0, $n - 1);
    }
}
