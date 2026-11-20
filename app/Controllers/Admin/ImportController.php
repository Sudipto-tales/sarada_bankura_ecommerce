<?php

namespace App\Controllers\Admin;

use App\Services\Import\ProductImporter;
use App\Services\Import\SheetReader;
use App\Services\Import\XlsxWriter;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

/**
 * Bulk product upload: template downloads → upload → dry-run preview → commit.
 * Uploaded files and the analysed plan live in storage/imports/{token}/ between steps.
 */
class ImportController
{
    public function show(Request $request): Response
    {
        $this->cleanup();
        $data = ['limits' => self::limits(), 'modes' => ProductImporter::MODES, 'columns' => ProductImporter::columns()];

        $token = (string) $request->query('token', '');
        if ($token !== '' && ($dir = $this->dir($token)) && is_file($dir . '/plan.json')) {
            $data['token'] = $token;
            $data['plan'] = json_decode((string) file_get_contents($dir . '/plan.json'), true);
            $data['meta'] = json_decode((string) @file_get_contents($dir . '/meta.json'), true) ?: [];
            if (is_file($dir . '/result.json')) {
                $data['result'] = json_decode((string) file_get_contents($dir . '/result.json'), true);
            }
        }

        return view('admin.products.import', $data);
    }

    public function preview(Request $request): Response
    {
        $sheet = $request->file('sheet');
        if (!$sheet || !$sheet->isValid()) {
            return $this->back(self::uploadError($sheet?->getError() ?? UPLOAD_ERR_NO_FILE, 'spreadsheet'));
        }
        $ext = strtolower($sheet->getClientExtension());
        if (!in_array($ext, ['xlsx', 'csv', 'txt'], true)) {
            return $this->back('The product file must be .xlsx or .csv (got .' . $ext . '). In Excel use File → Save As → Excel Workbook or CSV UTF-8.');
        }

        $token = bin2hex(random_bytes(12));
        $dir = app()->storagePath('imports/' . $token);
        @mkdir($dir, 0775, true);
        $sheetPath = $dir . '/sheet.' . $ext;
        move_uploaded_file($sheet->getPathname(), $sheetPath);
        $meta = ['sheet' => $sheet->getClientOriginalName(), 'created' => date('Y-m-d H:i:s')];

        $variantsFile = $request->file('variants_file');
        $variantsPath = null;
        if ($variantsFile && $variantsFile->isValid()) {
            $vext = strtolower($variantsFile->getClientExtension());
            if (!in_array($vext, ['csv', 'txt', 'xlsx'], true)) {
                return $this->back('The variants file must be .csv or .xlsx.');
            }
            $variantsPath = $dir . '/variants.' . $vext;
            move_uploaded_file($variantsFile->getPathname(), $variantsPath);
            $meta['variants'] = $variantsFile->getClientOriginalName();
        } elseif ($variantsFile && $variantsFile->getError() !== UPLOAD_ERR_NO_FILE) {
            return $this->back(self::uploadError($variantsFile->getError(), 'variants file'));
        }

        $zip = $request->file('images_zip');
        $zipPath = null;
        if ($zip && $zip->isValid()) {
            if (strtolower($zip->getClientExtension()) !== 'zip') {
                return $this->back('Images must be uploaded as a single .zip file.');
            }
            $zipPath = $dir . '/images.zip';
            move_uploaded_file($zip->getPathname(), $zipPath);
            if (ProductImporter::zipIndex($zipPath) === null) {
                return $this->back('The images ZIP could not be opened — is it a valid .zip?');
            }
            $meta['zip'] = $zip->getClientOriginalName();
        } elseif ($zip && $zip->getError() !== UPLOAD_ERR_NO_FILE) {
            return $this->back(self::uploadError($zip->getError(), 'images ZIP'));
        }

        try {
            $sheets = SheetReader::read($sheetPath, $ext);
            [$products, $variants] = $this->pickSheets($sheets);
            if ($variantsPath) {
                $vs = SheetReader::read($variantsPath, pathinfo($variantsPath, PATHINFO_EXTENSION));
                $variants = array_merge($variants, $vs['variants'] ?? reset($vs) ?: []);
            }
        } catch (\Throwable $e) {
            return $this->back($e->getMessage());
        }
        if (!$products && !$variants) {
            return $this->back('No product rows found. Keep the header row (sku, name, …) as the first row and fill rows below it.');
        }
        if ($products && !array_key_exists('sku', $products[0])) {
            return $this->back('The first row must be the column headers and include "sku". Download the template to see the exact headers.');
        }
        if (count($products) + count($variants) > 5000) {
            return $this->back('That file has more than 5,000 rows. Split it into smaller files.');
        }

        $mode = (string) $request->input('mode', 'upsert');
        $plan = (new ProductImporter())->analyse($products, $variants, $zipPath, $mode);
        file_put_contents($dir . '/plan.json', json_encode($plan));
        file_put_contents($dir . '/meta.json', json_encode($meta));

        return redirect(route('admin.products.import') . '?token=' . $token);
    }

    public function commit(Request $request, string $token): Response
    {
        $dir = $this->dir($token);
        if (!$dir || !is_file($dir . '/plan.json')) {
            return $this->back('This import preview has expired. Upload the file again.');
        }
        if (is_file($dir . '/result.json')) {
            return redirect(route('admin.products.import') . '?token=' . $token);
        }
        $plan = json_decode((string) file_get_contents($dir . '/plan.json'), true);
        try {
            $result = (new ProductImporter())->commit($plan, is_file($dir . '/images.zip') ? $dir . '/images.zip' : null);
        } catch (\Throwable $e) {
            return $this->back('Import failed and nothing was saved: ' . $e->getMessage());
        }
        file_put_contents($dir . '/result.json', json_encode($result + ['finished' => date('Y-m-d H:i:s')]));
        @unlink($dir . '/images.zip');

        return redirect(route('admin.products.import') . '?token=' . $token);
    }

    /** CSV of every row's problems — fix in Excel and re-upload. */
    public function report(Request $request, string $token): Response
    {
        $dir = $this->dir($token);
        if (!$dir || !is_file($dir . '/plan.json')) {
            return redirect(route('admin.products.import'));
        }
        $plan = json_decode((string) file_get_contents($dir . '/plan.json'), true);
        $out = [['line', 'sku', 'name', 'result', 'errors', 'warnings']];
        foreach ($plan['rows'] as $r) {
            $out[] = [(string) $r['line'], $r['sku'], $r['name'], $r['action'], implode(' | ', $r['errors']), implode(' | ', $r['warnings'])];
        }

        return $this->csvResponse('import-report-' . date('Ymd-His') . '.csv', $out);
    }

    public function template(Request $request, string $format): Response
    {
        $cols = ProductImporter::columns();
        $pKeys = array_column($cols['products'], 'key');
        $vKeys = array_column($cols['variants'], 'key');
        $samples = $this->sampleRows($pKeys, $vKeys);

        if ($format === 'csv') {
            return $this->csvResponse('maa-sarada-products-template.csv', array_merge([$pKeys], $samples['products']));
        }
        if ($format === 'variants-csv') {
            return $this->csvResponse('maa-sarada-variants-template.csv', array_merge([$vKeys], $samples['variants']));
        }
        if ($format === 'guide-csv') {
            $rows = [['sheet', 'column', 'required', 'group', 'what to enter', 'example']];
            foreach (['products' => 'Products', 'variants' => 'Variants'] as $k => $label) {
                foreach ($cols[$k] as $c) {
                    $rows[] = [$label, $c['key'], $c['required'] ? 'yes' : '', $c['group'], $c['help'], $c['example']];
                }
            }

            return $this->csvResponse('maa-sarada-import-column-guide.csv', $rows);
        }
        if ($format !== 'xlsx') {
            return redirect(route('admin.products.import'));
        }

        // Lookups sheet: one column per list, used by the dropdowns.
        $lists = [
            'status' => ProductImporter::STATUSES,
            'yes_no' => ['yes', 'no'],
            'brands' => array_column(DB::select('SELECT name FROM brands ORDER BY name'), 'name'),
            'categories' => $this->categoryPaths(),
            'units' => array_column(DB::select('SELECT symbol FROM units ORDER BY sort, name'), 'symbol'),
            'product_types' => array_column(DB::select('SELECT slug FROM product_types ORDER BY id'), 'slug'),
            'tags' => array_column(DB::select('SELECT name FROM tags ORDER BY name'), 'name'),
            'variant_status' => ProductImporter::VARIANT_STATUSES,
        ];
        foreach (ProductImporter::attributeDefs() as $a) {
            $lists['attr:' . $a['code']] = $a['values'];
        }
        foreach (ProductImporter::optionDefs() as $g) {
            $lists['opt:' . $g['slug']] = $g['values'];
        }
        $lookupRows = [array_keys($lists)];
        $max = max(array_map('count', $lists) ?: [0]);
        for ($i = 0; $i < $max; $i++) {
            $lookupRows[] = array_map(static fn($l) => $l[$i] ?? '', array_values($lists));
        }
        $listCol = array_flip(array_keys($lists));
        $range = static function (string $list) use ($lists, $listCol): string {
            $L = XlsxWriter::col($listCol[$list]);

            return 'Lookups!$' . $L . '$2:$' . $L . '$' . (max(1, count($lists[$list])) + 1);
        };

        $pVal = [];
        $vVal = [];
        $map = ['status' => ['status', true], 'brand' => ['brands', false], 'primary_category' => ['categories', false], 'unit' => ['units', true], 'pack_unit' => ['units', true], 'product_type' => ['product_types', true],
            'is_enquiry_only' => ['yes_no', true], 'show_price' => ['yes_no', true], 'is_featured' => ['yes_no', true], 'is_bestseller' => ['yes_no', true]];
        foreach ($pKeys as $i => $k) {
            if (isset($map[$k]) && $lists[$map[$k][0]]) {
                $pVal[] = ['col' => $i, 'source' => $range($map[$k][0]), 'strict' => $map[$k][1]];
            } elseif (str_starts_with($k, 'attr:') && !empty($lists[$k]) && $k !== 'attr:application') {
                $pVal[] = ['col' => $i, 'source' => $range($k), 'strict' => false];
            }
        }
        foreach ($vKeys as $i => $k) {
            if ($k === 'status') {
                $vVal[] = ['col' => $i, 'source' => $range('variant_status'), 'strict' => true];
            } elseif ($k === 'is_default') {
                $vVal[] = ['col' => $i, 'source' => $range('yes_no'), 'strict' => true];
            } elseif ($k === 'unit') {
                $vVal[] = ['col' => $i, 'source' => $range('units'), 'strict' => true];
            } elseif (str_starts_with($k, 'opt:') && !empty($lists[$k])) {
                $vVal[] = ['col' => $i, 'source' => $range($k), 'strict' => false];
            }
        }
        $req = static fn(array $defs) => array_keys(array_filter(array_column($defs, 'required')));
        $widths = static function (array $keys): array {
            $w = [];
            foreach ($keys as $i => $k) {
                $w[$i] = in_array($k, ['name', 'short_description', 'description', 'categories', 'primary_category', 'meta_description', 'cover_image', 'gallery_1', 'gallery_2', 'gallery_3'], true) ? 30 : max(14, strlen($k) + 4);
            }

            return $w;
        };

        $guide = [['Column', 'Sheet', 'Required', 'Group', 'What to enter', 'Example']];
        foreach (['products' => 'Products', 'variants' => 'Variants'] as $k => $label) {
            foreach ($cols[$k] as $c) {
                $guide[] = [$c['key'], $label, $c['required'] ? 'Required' : '', $c['group'], $c['help'], $c['example']];
            }
        }
        $guide[] = ['', '', '', '', '', ''];
        $guide[] = ['RULES', '', '', '', 'Rows are matched by sku: a new sku creates a product, an existing sku updates it.', ''];
        $guide[] = ['', '', '', '', 'When updating, a blank cell keeps the current value. Type a single "-" to clear a value.', ''];
        $guide[] = ['', '', '', '', 'Unknown brands, categories, tags, attribute and option values are created automatically.', ''];
        $guide[] = ['', '', '', '', 'Images: put the file name from your ZIP (e.g. statuario-cover.jpg) or a full https:// link.', ''];
        $guide[] = ['', '', '', '', 'Filling any image column on an update replaces that product\'s images.', ''];
        $guide[] = ['', '', '', '', 'Delete the two sample rows before uploading.', ''];

        $x = new XlsxWriter();
        $x->addSheet('Products', array_merge([$pKeys], $samples['products']), $widths($pKeys), $req($cols['products']), $pVal);
        $x->addSheet('Variants', array_merge([$vKeys], $samples['variants']), $widths($vKeys), $req($cols['variants']), $vVal);
        $x->addSheet('Guide', $guide, [0 => 22, 1 => 11, 2 => 10, 3 => 12, 4 => 80, 5 => 32]);
        $x->addSheet('Lookups', $lookupRows, array_fill(0, count($lists), 22));
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $x->save($tmp);
        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);

        return Response::make($bin, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="maa-sarada-products-template.xlsx"',
            'Content-Length' => (string) strlen($bin),
        ]);
    }

    // ------------------------------------------------------------------

    /** @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>} */
    private function pickSheets(array $sheets): array
    {
        $products = $sheets['products'] ?? null;
        $variants = $sheets['variants'] ?? [];
        if ($products === null) {
            foreach ($sheets as $name => $rows) {
                if ($name !== 'variants' && $rows && array_key_exists('sku', $rows[0])) {
                    $products = $rows;
                    break;
                }
            }
        }
        // A lone CSV that is actually a variants file.
        if ($products && !array_key_exists('sku', $products[0]) && array_key_exists('variant_sku', $products[0])) {
            return [[], $products];
        }
        // Drop the template's sample rows if the user forgot to.
        $isSample = static fn($r) => str_starts_with(strtoupper((string) ($r['sku'] ?? $r['product_sku'] ?? '')), 'SAMPLE-');

        return [array_values(array_filter($products ?? [], static fn($r) => !$isSample($r))), array_values(array_filter($variants, static fn($r) => !$isSample($r)))];
    }

    private function sampleRows(array $pKeys, array $vKeys): array
    {
        $attrs = [];
        foreach (ProductImporter::attributeDefs() as $a) {
            $attrs['attr:' . $a['code']] = [$a['values'][0] ?? '', $a['values'][1] ?? ($a['values'][0] ?? '')];
        }
        if (isset($attrs['attr:application'])) {
            $vals = ProductImporter::attributeDefs();
            foreach ($vals as $a) {
                if ($a['code'] === 'application') {
                    $attrs['attr:application'] = [implode(' | ', array_slice($a['values'], 0, 2)), $a['values'][2] ?? ''];
                }
            }
        }
        $p = [
            ['sku' => 'SAMPLE-STA-001', 'name' => 'Statuario White Marble', 'status' => 'active', 'brand' => 'Imported Collection', 'primary_category' => 'Flooring > Living Room', 'categories' => 'Imported',
                'tags' => 'premium | white', 'short_description' => 'Italian white marble with soft grey veining.', 'price' => '450', 'compare_at_price' => '520', 'currency' => 'INR', 'unit' => 'sq ft',
                'pack_qty' => '', 'show_price' => 'yes', 'is_featured' => 'yes', 'cover_image' => 'statuario-cover.jpg', 'gallery_1' => 'https://example.com/statuario-2.jpg'],
            ['sku' => 'SAMPLE-RJG-002', 'name' => 'Rajasthan Green Marble', 'status' => 'draft', 'brand' => 'New Quarry Partner', 'primary_category' => 'Wall Cladding > Feature Walls', 'categories' => '',
                'tags' => 'vastu-friendly', 'short_description' => 'Deep green Indian marble.', 'price' => '', 'unit' => 'sq ft', 'is_enquiry_only' => 'yes', 'show_price' => 'no', 'cover_image' => 'rajasthan-green.webp'],
        ];
        $out = ['products' => [], 'variants' => []];
        foreach ($p as $i => $row) {
            $out['products'][] = array_map(static fn($k) => $row[$k] ?? ($attrs[$k][$i] ?? ''), $pKeys);
        }
        $opts = [];
        foreach (ProductImporter::optionDefs() as $g) {
            $opts['opt:' . $g['slug']] = $g['values'];
        }
        $v = [
            ['product_sku' => 'SAMPLE-STA-001', 'variant_sku' => 'SAMPLE-STA-001-A', 'price' => '450', 'status' => 'active', 'is_default' => 'yes', 'i' => 0],
            ['product_sku' => 'SAMPLE-STA-001', 'variant_sku' => 'SAMPLE-STA-001-B', 'price' => '495', 'status' => 'active', 'is_default' => 'no', 'i' => 1],
        ];
        foreach ($v as $row) {
            $out['variants'][] = array_map(static function ($k) use ($row, $opts) {
                if (isset($row[$k])) {
                    return $row[$k];
                }
                if (in_array($k, ['opt:thickness', 'opt:finish'], true)) {
                    return $opts[$k][$row['i']] ?? '';
                }

                return '';
            }, $vKeys);
        }

        return $out;
    }

    /** @return list<string> */
    private function categoryPaths(): array
    {
        $rows = DB::select('SELECT id, name, parent_id FROM product_categories ORDER BY sort, name');
        $names = array_column($rows, 'name', 'id');
        $out = [];
        foreach ($rows as $r) {
            $out[] = $r['parent_id'] ? ($names[$r['parent_id']] ?? '') . ' > ' . $r['name'] : $r['name'];
        }
        sort($out);

        return $out;
    }

    private function csvResponse(string $filename, array $rows): Response
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF"); // BOM so Excel opens it as UTF-8 (₹, ×)
        foreach ($rows as $r) {
            fputcsv($fh, $r, ',', '"', '');
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        return Response::make($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function dir(string $token): ?string
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $token)) {
            return null;
        }
        $dir = app()->storagePath('imports/' . $token);

        return is_dir($dir) ? $dir : null;
    }

    /** Remove import folders older than a day. */
    private function cleanup(): void
    {
        foreach (glob(app()->storagePath('imports/*'), GLOB_ONLYDIR) ?: [] as $d) {
            if (filemtime($d) < time() - 86400) {
                foreach (glob($d . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($d);
            }
        }
    }

    private function back(string $error): Response
    {
        session()->flash('import_error', $error);

        return redirect(route('admin.products.import'));
    }

    /** @return array{upload:string,post:string} */
    public static function limits(): array
    {
        return ['upload' => (string) ini_get('upload_max_filesize'), 'post' => (string) ini_get('post_max_size')];
    }

    private static function uploadError(int $code, string $what): string
    {
        $l = self::limits();

        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The ' . $what . ' is larger than the server allows (' . $l['upload'] . ' per file). Use a smaller ZIP or image links instead.',
            UPLOAD_ERR_PARTIAL => 'The ' . $what . ' upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE => 'Choose a ' . $what . ' to upload.',
            default => 'The ' . $what . ' could not be uploaded (error ' . $code . ').',
        };
    }
}
