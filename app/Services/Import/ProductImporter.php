<?php

namespace App\Services\Import;

use App\Controllers\Admin\CatalogController;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\InventoryService as Inv;
use Vayu\Database\DB;

/**
 * Bulk product import.
 *
 *   analyse()  — dry run: maps every row, validates it and works out what would be
 *                created / updated / auto-created. Writes nothing.
 *   commit()   — writes the valid rows of an analysed plan inside one transaction.
 *
 * Cell rules: on create a blank cell means "use the default"; on update a blank
 * cell means "keep what is there" and a single "-" clears the value.
 * Unknown brands, categories, tags, attribute values and option values are
 * created automatically (and listed in the preview first).
 */
final class ProductImporter
{
    public const CLEAR = '-';
    public const MODES = ['upsert' => 'Create new + update existing', 'create' => 'Only create new products', 'update' => 'Only update existing products'];
    public const STATUSES = ['draft', 'active', 'coming_soon', 'archived'];
    public const VARIANT_STATUSES = ['active', 'inactive', 'archived'];
    public const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];
    public const VIDEO_EXT = ['mp4', 'webm', 'mov', 'm4v'];
    public const MEDIA_SLOTS = [
        'cover_image' => ['image', 1],
        'gallery_1' => ['image', 0],
        'gallery_2' => ['image', 0],
        'gallery_3' => ['image', 0],
        'video' => ['video', 0],
    ];
    private const MAX_MEDIA_BYTES = 20 * 1024 * 1024;

    /** @var array<string,mixed> */
    private array $lk = [];

    // ------------------------------------------------------------------ columns

    /**
     * Column definitions used by the template, the in-sheet guide and the help modal.
     *
     * @return array{products: list<array{key:string,required:bool,group:string,help:string,example:string}>, variants: list<array{key:string,required:bool,group:string,help:string,example:string}>}
     */
    public static function columns(): array
    {
        $c = static fn(string $key, string $group, string $help, string $example = '', bool $req = false) => compact('key', 'group', 'help', 'example') + ['required' => $req];

        $products = [
            $c('sku', 'Basics', 'Unique product code. Used to match existing products on re-upload.', 'MS-STA-001', true),
            $c('name', 'Basics', 'Product name shown on the storefront.', 'Statuario White Marble', true),
            $c('slug', 'Basics', 'URL part. Leave blank to build it from the name.', 'statuario-white-marble'),
            $c('status', 'Basics', 'draft · active · coming_soon · archived. Blank = draft.', 'active'),
            $c('product_type', 'Basics', 'Product type name or slug. Blank = the default type.', 'marble-stone'),
            $c('brand', 'Catalog', 'Brand name or slug. A new brand is created if it does not exist.', 'Rajasthan Quarry'),
            $c('primary_category', 'Catalog', 'Main category. Use "Parent > Child" for a sub-category. Created if missing.', 'Flooring > Living Room'),
            $c('categories', 'Catalog', 'Extra categories, separated by |. Created if missing.', 'Flooring > Bathroom | Indian'),
            $c('tags', 'Catalog', 'Tags separated by | or comma. New tags are created.', 'premium | white'),
            $c('short_description', 'Content', 'Up to 500 characters.', 'Classic Italian-look white marble with grey veining.'),
            $c('description', 'Content', 'Full description.', ''),
            $c('price', 'Pricing', 'Selling price (numbers only; ₹ and commas are ignored).', '450'),
            $c('compare_at_price', 'Pricing', 'Old / MRP price shown struck through.', '520'),
            $c('cost_price', 'Pricing', 'Your cost (never shown to shoppers).', '310'),
            $c('currency', 'Pricing', 'Blank = INR.', 'INR'),
            $c('unit', 'Pricing', 'Selling unit — must match a unit symbol (see Lookups).', 'sq ft'),
            $c('pack_qty', 'Pricing', 'Box contents quantity.', '10.76'),
            $c('pack_unit', 'Pricing', 'Unit of the box contents.', 'sq ft'),
            $c('is_enquiry_only', 'Flags', 'yes / no. Blank = no.', 'no'),
            $c('show_price', 'Flags', 'yes / no. Blank = yes.', 'yes'),
            $c('is_featured', 'Flags', 'yes / no. Blank = no.', 'yes'),
            $c('is_bestseller', 'Flags', 'yes / no. Blank = no.', 'no'),
            $c('stock_qty', 'Inventory', 'Optional. Physical stock you have now. Blank = stock not touched. On a product already tracked, a different number is saved as a stock correction.', '500'),
            $c('stock_unit', 'Inventory', 'Optional. Unit you count stock in (see Lookups). Blank = the selling unit. Cannot change once tracked.', 'sq ft'),
            $c('low_stock_threshold', 'Inventory', 'Optional. Warn when stock falls to this level. Blank = default; "-" = back to default.', '100'),
            $c('external_product_id', 'Other', 'ID from another system (ERP / Tally).', ''),
            $c('meta_title', 'SEO', 'Search result title.', ''),
            $c('meta_description', 'SEO', 'Search result description, up to 500 characters.', ''),
        ];
        foreach (self::attributeDefs() as $a) {
            $multi = $a['type'] === 'multiselect';
            $products[] = $c('attr:' . $a['code'], 'Attributes', $a['name'] . ($multi ? ' — several values separated by |' : '') . '. New values are added to the library.', $a['values'][0] ?? '');
        }
        $products[] = $c('cover_image', 'Media', 'Main image: a file name inside your images ZIP, or a full https:// link.', 'statuario-cover.jpg');
        $products[] = $c('gallery_1', 'Media', 'Gallery image (file name in ZIP or link).', 'https://example.com/statuario-2.jpg');
        $products[] = $c('gallery_2', 'Media', 'Gallery image (file name in ZIP or link).', '');
        $products[] = $c('gallery_3', 'Media', 'Gallery image (file name in ZIP or link).', '');
        $products[] = $c('video', 'Media', 'Video (mp4/webm): file name in ZIP or link.', '');

        $variants = [
            $c('product_sku', 'Variant', 'SKU of the parent product (from the Products sheet or already in the catalogue).', 'MS-STA-001', true),
            $c('variant_sku', 'Variant', 'Unique code for this variant. Existing variant SKUs are updated.', 'MS-STA-001-18P', true),
            $c('title', 'Variant', 'Label. Blank = built from the options, e.g. "18 mm / Polished".', ''),
        ];
        foreach (self::optionDefs() as $g) {
            $variants[] = $c('opt:' . $g['slug'], 'Options', $g['name'] . ' — e.g. ' . implode(', ', array_slice($g['values'], 0, 3)) . '. New values are created.', $g['values'][0] ?? '');
        }
        $variants[] = $c('price', 'Pricing', 'Variant price. Blank = product price.', '480');
        $variants[] = $c('compare_at_price', 'Pricing', 'Old / MRP price.', '');
        $variants[] = $c('unit', 'Pricing', 'Overrides the product selling unit.', '');
        $variants[] = $c('status', 'Variant', 'active · inactive · archived. Blank = active.', 'active');
        $variants[] = $c('is_default', 'Variant', 'yes for the variant selected first.', 'yes');

        return ['products' => $products, 'variants' => $variants];
    }

    /** @return list<array{id:int,code:string,name:string,type:string,values:list<string>}> */
    public static function attributeDefs(): array
    {
        $vals = [];
        foreach (DB::select('SELECT attribute_id, label, unit FROM attribute_values ORDER BY sort, id') as $v) {
            $vals[(int) $v['attribute_id']][] = trim($v['label'] . ($v['unit'] ? ' ' . $v['unit'] : ''));
        }
        $out = [];
        foreach (DB::select("SELECT id, code, name, type FROM attributes WHERE status <> 'archived' ORDER BY sort, id") as $a) {
            $out[] = ['id' => (int) $a['id'], 'code' => $a['code'], 'name' => $a['name'], 'type' => $a['type'], 'values' => $vals[(int) $a['id']] ?? []];
        }

        return $out;
    }

    /** @return list<array{id:int,slug:string,name:string,values:list<string>}> */
    public static function optionDefs(): array
    {
        $vals = [];
        foreach (DB::select('SELECT option_group_id, value FROM option_values ORDER BY sort, id') as $v) {
            $vals[(int) $v['option_group_id']][] = $v['value'];
        }
        $out = [];
        foreach (DB::select('SELECT id, slug, name FROM option_groups ORDER BY sort, name') as $g) {
            $out[] = ['id' => (int) $g['id'], 'slug' => $g['slug'], 'name' => $g['name'], 'values' => $vals[(int) $g['id']] ?? []];
        }

        return $out;
    }

    // ------------------------------------------------------------------ analyse

    /**
     * @param list<array<string,mixed>> $productRows
     * @param list<array<string,mixed>> $variantRows
     * @return array<string,mixed> plan
     */
    public function analyse(array $productRows, array $variantRows, ?string $zipPath, string $mode): array
    {
        $mode = isset(self::MODES[$mode]) ? $mode : 'upsert';
        $this->loadLookups();
        $zipIndex = $zipPath ? self::zipIndex($zipPath) : null;
        $creates = ['brand' => [], 'category' => [], 'tag' => [], 'attribute value' => [], 'option value' => []];
        $notices = [];

        $knownCols = array_column(self::columns()['products'], 'key');
        $unknown = [];
        foreach ($productRows as $r) {
            foreach (array_keys($r) as $k) {
                if ($k !== '__line' && !in_array($k, $knownCols, true)) {
                    $unknown[$k] = true;
                }
            }
        }
        if ($unknown) {
            $notices[] = 'Ignored unknown column(s): ' . implode(', ', array_keys($unknown)) . '.';
        }

        $rows = [];
        $bySku = [];
        foreach ($productRows as $raw) {
            $row = $this->analyseProduct($raw, $mode, $zipIndex, $creates);
            $key = strtolower((string) $row['sku']);
            if ($key !== '' && isset($bySku[$key])) {
                $row['errors'][] = 'SKU "' . $row['sku'] . '" appears twice in the file (first on line ' . $rows[$bySku[$key]]['line'] . ').';
            } elseif ($key !== '') {
                $bySku[$key] = count($rows);
            }
            $rows[] = $row;
        }

        // Variants attach to a product row in this file, or to an existing product.
        $seenVariant = [];
        $orphanVariants = [];
        foreach ($variantRows as $raw) {
            $v = $this->analyseVariant($raw, $creates);
            $vk = strtolower($v['sku']);
            if ($vk !== '' && isset($seenVariant[$vk])) {
                $v['errors'][] = 'Variant SKU "' . $v['sku'] . '" appears twice (first on variants line ' . $seenVariant[$vk] . ').';
            }
            $seenVariant[$vk] = $v['line'];
            $pk = strtolower($v['product_sku']);
            if (isset($bySku[$pk])) {
                $idx = $bySku[$pk];
                $owner = $rows[$idx]['product_id'];
                if ($owner && isset($this->lk['variantSku'][$vk]) && $this->lk['variantSku'][$vk] !== $owner) {
                    $v['errors'][] = 'Variant SKU "' . $v['sku'] . '" already belongs to another product.';
                } elseif (!$owner && isset($this->lk['variantSku'][$vk])) {
                    $v['errors'][] = 'Variant SKU "' . $v['sku'] . '" already belongs to another product.';
                }
                $rows[$idx]['variants'][] = $v;
                if ($v['errors']) {
                    $rows[$idx]['errors'][] = 'Variant (variants line ' . $v['line'] . '): ' . implode(' ', $v['errors']);
                }
            } elseif (isset($this->lk['productSku'][$pk])) {
                $orphanVariants[$pk][] = $v;
            } else {
                $v['errors'][] = 'Product SKU "' . $v['product_sku'] . '" is not in the Products sheet or the catalogue.';
                $orphanVariants['?' . $v['line']][] = $v;
            }
        }
        // Variant-only rows for products already in the catalogue become "update variants" rows.
        foreach ($orphanVariants as $pk => $list) {
            $pid = str_starts_with($pk, '?') ? 0 : $this->lk['productSku'][$pk];
            $errs = [];
            foreach ($list as $v) {
                if ($pid && isset($this->lk['variantSku'][strtolower($v['sku'])]) && $this->lk['variantSku'][strtolower($v['sku'])] !== $pid) {
                    $v['errors'][] = 'Variant SKU "' . $v['sku'] . '" already belongs to another product.';
                }
                foreach ($v['errors'] as $e) {
                    $errs[] = 'Variant (variants line ' . $v['line'] . '): ' . $e;
                }
            }
            $rows[] = [
                'line' => 'V' . $list[0]['line'], 'sku' => $list[0]['product_sku'], 'name' => $pid ? (string) DB::table('products')->where('id', $pid)->value('name') : '',
                'action' => $pid ? ($mode === 'create' ? 'skip' : 'variants') : 'error', 'product_id' => $pid ?: null,
                'errors' => $errs, 'warnings' => [], 'data' => null, 'variants' => $list, 'variantsOnly' => true,
            ];
        }

        $summary = ['create' => 0, 'update' => 0, 'variants' => 0, 'skip' => 0, 'error' => 0, 'variant_rows' => 0, 'stock_start' => 0, 'stock_change' => 0, 'stock_same' => 0];
        foreach ($rows as &$r) {
            if ($r['errors']) {
                $r['action'] = 'error';
            }
            $summary[$r['action']]++;
            if ($r['action'] !== 'error' && $r['action'] !== 'skip') {
                $summary['variant_rows'] += count($r['variants']);
                if (!empty($r['data']['stock'])) {
                    $summary['stock_' . $r['data']['stock']['action']]++;
                }
            }
        }
        unset($r);

        // Only list auto-creations that a valid row will actually trigger.
        $needed = ['brand' => [], 'category' => [], 'tag' => [], 'attribute value' => [], 'option value' => []];
        foreach ($rows as $r) {
            if ($r['action'] === 'error' || $r['action'] === 'skip') {
                continue;
            }
            foreach ($r['creates'] ?? [] as [$kind, $label]) {
                $needed[$kind][strtolower($label)] = $label;
            }
            foreach ($r['variants'] as $v) {
                foreach ($v['creates'] as [$kind, $label]) {
                    $needed[$kind][strtolower($label)] = $label;
                }
            }
        }

        return [
            'mode' => $mode,
            'rows' => $rows,
            'summary' => $summary,
            'creates' => array_map('array_values', $needed),
            'notices' => $notices,
            'has_zip' => $zipIndex !== null,
        ];
    }

    /** @param array<string,mixed> $raw */
    private function analyseProduct(array $raw, string $mode, ?array $zipIndex, array &$creates): array
    {
        $errors = [];
        $warnings = [];
        $rowCreates = [];
        $sku = trim((string) ($raw['sku'] ?? ''));
        $pid = $this->lk['productSku'][strtolower($sku)] ?? null;
        $isUpdate = $pid !== null;
        $action = $isUpdate ? 'update' : 'create';
        if ($mode === 'create' && $isUpdate) {
            $action = 'skip';
            $warnings[] = 'SKU already exists — skipped (mode: only create new).';
        } elseif ($mode === 'update' && !$isUpdate) {
            $action = 'skip';
            $warnings[] = 'SKU not in the catalogue — skipped (mode: only update existing).';
        }

        // cell(): null = blank (keep/default) · CLEAR = "-" · otherwise trimmed string
        $cell = static function (string $k) use ($raw): ?string {
            if (!array_key_exists($k, $raw)) {
                return null;
            }
            $v = trim((string) $raw[$k]);

            return $v === '' ? null : $v;
        };

        $p = [];
        if ($sku === '') {
            $errors[] = 'SKU is required.';
        } elseif (mb_strlen($sku) > 64) {
            $errors[] = 'SKU is longer than 64 characters.';
        }
        $p['sku'] = $sku;

        $name = $cell('name');
        if ($name === null || $name === self::CLEAR) {
            if (!$isUpdate) {
                $errors[] = 'Name is required.';
            }
        } elseif (mb_strlen($name) > 255) {
            $errors[] = 'Name is longer than 255 characters.';
        } else {
            $p['name'] = $name;
        }

        if (($v = $cell('slug')) !== null && $v !== self::CLEAR) {
            $p['slug_source'] = $v;
        } elseif (!$isUpdate || isset($p['name']) && $v === self::CLEAR) {
            $p['slug_source'] = $name ?? '';
        }

        if (($v = $cell('status')) !== null) {
            $s = str_replace([' ', '-'], '_', strtolower($v));
            $s = $s === 'comingsoon' ? 'coming_soon' : ($s === 'live' || $s === 'published' ? 'active' : $s);
            if (!in_array($s, self::STATUSES, true)) {
                $errors[] = 'Status "' . $v . '" is not valid (use ' . implode(', ', self::STATUSES) . ').';
            } else {
                $p['status'] = $s;
            }
        } elseif (!$isUpdate) {
            $p['status'] = 'draft';
        }

        if (($v = $cell('product_type')) !== null && $v !== self::CLEAR) {
            $tid = $this->lk['types'][CatalogController::slugify($v)] ?? $this->lk['typesByName'][mb_strtolower($v)] ?? null;
            if ($tid === null) {
                $errors[] = 'Product type "' . $v . '" does not exist.';
            } else {
                $p['product_type_id'] = $tid;
            }
        } elseif (!$isUpdate) {
            $p['product_type_id'] = $this->lk['defaultType'];
            if (!$this->lk['defaultType']) {
                $errors[] = 'No product type exists yet — create one first.';
            }
        }

        if (($v = $cell('brand')) !== null) {
            if ($v === self::CLEAR) {
                $p['brand'] = null;
            } else {
                $bid = $this->lk['brands'][CatalogController::slugify($v)] ?? $this->lk['brandsByName'][mb_strtolower($v)] ?? null;
                $p['brand'] = $bid ? ['id' => $bid] : ['create' => $v];
                if (!$bid) {
                    $rowCreates[] = ['brand', $v];
                }
            }
        }

        foreach (['short_description' => 500, 'description' => 0, 'meta_title' => 255, 'meta_description' => 500, 'external_product_id' => 64] as $k => $max) {
            if (($v = $cell($k)) !== null) {
                if ($v !== self::CLEAR && $max && mb_strlen($v) > $max) {
                    $errors[] = str_replace('_', ' ', ucfirst($k)) . ' is longer than ' . $max . ' characters.';
                    continue;
                }
                $p[$k] = $v === self::CLEAR ? null : $v;
            }
        }

        foreach (['is_enquiry_only' => 0, 'show_price' => 1, 'is_featured' => 0, 'is_bestseller' => 0] as $k => $def) {
            $v = $cell($k);
            if ($v === null || $v === self::CLEAR) {
                if (!$isUpdate || $v === self::CLEAR) {
                    $p[$k] = $def;
                }
                continue;
            }
            $b = self::bool($v);
            if ($b === null) {
                $errors[] = str_replace('_', ' ', $k) . ': "' . $v . '" — use yes or no.';
            } else {
                $p[$k] = $b;
            }
        }

        // Pricing.
        $pricing = [];
        foreach (['price', 'compare_at_price', 'cost_price', 'pack_qty'] as $k) {
            if (($v = $cell($k)) === null) {
                continue;
            }
            if ($v === self::CLEAR) {
                $pricing[$k] = null;
                continue;
            }
            $n = self::number($v);
            if ($n === null || $n < 0) {
                $errors[] = str_replace('_', ' ', ucfirst($k)) . ' "' . $v . '" is not a valid number.';
            } else {
                $pricing[$k] = $n;
            }
        }
        if (isset($pricing['price'], $pricing['compare_at_price']) && $pricing['compare_at_price'] < $pricing['price']) {
            $warnings[] = 'Compare-at price is lower than the price.';
        }
        if (($v = $cell('currency')) !== null) {
            $pricing['currency'] = $v === self::CLEAR ? 'INR' : strtoupper(substr($v, 0, 8));
        }
        foreach (['unit', 'pack_unit'] as $k) {
            if (($v = $cell($k)) === null) {
                continue;
            }
            if ($v === self::CLEAR) {
                $pricing[$k] = null;
                continue;
            }
            $u = $this->unit($v);
            if ($u === null) {
                $errors[] = ucfirst(str_replace('_', ' ', $k)) . ' "' . $v . '" is not a known unit (' . implode(', ', $this->lk['unitList']) . ').';
            } else {
                $pricing[$k] = $u;
            }
        }

        $stock = $this->analyseStock($cell, $pid, $pricing, $errors, $warnings);

        // Categories.
        $cats = null;
        $primaryTok = $cell('primary_category');
        $catsTok = $cell('categories');
        if ($primaryTok !== null || $catsTok !== null) {
            $cats = ['list' => [], 'primary' => null];
            $tokens = [];
            if ($primaryTok !== null && $primaryTok !== self::CLEAR) {
                $tokens[] = $primaryTok;
            }
            if ($catsTok !== null && $catsTok !== self::CLEAR) {
                $tokens = array_merge($tokens, self::split($catsTok, '|'));
            }
            $seen = [];
            foreach ($tokens as $i => $tok) {
                $ref = $this->categoryRef($tok, $err);
                if ($ref === null) {
                    $errors[] = $err;
                    continue;
                }
                $key = json_encode($ref);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                if (isset($ref['create'])) {
                    $rowCreates[] = ['category', implode(' > ', array_filter([$ref['create'][0], $ref['create'][1]]))];
                }
                $cats['list'][] = $ref;
                if ($i === 0) {
                    $cats['primary'] = count($cats['list']) - 1;
                }
            }
        }

        // Tags.
        $tags = null;
        if (($v = $cell('tags')) !== null) {
            $tags = $v === self::CLEAR ? [] : self::split($v, '|,');
            foreach ($tags as $t) {
                if (!isset($this->lk['tags'][CatalogController::slugify($t)])) {
                    $rowCreates[] = ['tag', $t];
                }
            }
        }

        // Attributes.
        $attrs = [];
        foreach ($this->lk['attributes'] as $code => $a) {
            if (($v = $cell('attr:' . $code)) === null) {
                continue;
            }
            if ($v === self::CLEAR) {
                $attrs[$a['id']] = null;
                continue;
            }
            $parts = $a['type'] === 'multiselect' ? self::split($v, '|,') : [$v];
            $out = [];
            foreach ($parts as $part) {
                $canon = $a['values'][self::norm($part)] ?? null;
                if ($canon === null) {
                    $rowCreates[] = ['attribute value', $a['name'] . ': ' . $part];
                    $out[] = $part;
                    $attrs['__new'][$a['id']][] = $part;
                } else {
                    $out[] = $canon;
                }
            }
            $attrs[$a['id']] = implode(', ', $out);
        }

        // Media.
        $media = null;
        foreach (self::MEDIA_SLOTS as $slot => [$type, $isCover]) {
            if (($v = $cell($slot)) === null) {
                continue;
            }
            $media ??= [];
            if ($v === self::CLEAR) {
                $media[] = ['slot' => $slot, 'type' => $type, 'clear' => true];
                continue;
            }
            $allowed = $type === 'video' ? self::VIDEO_EXT : self::IMAGE_EXT;
            if (preg_match('#^https?://#i', $v)) {
                if (!filter_var($v, FILTER_VALIDATE_URL)) {
                    $warnings[] = $slot . ': "' . $v . '" is not a valid link — skipped.';
                    continue;
                }
                $media[] = ['slot' => $slot, 'type' => $type, 'cover' => $isCover, 'url' => $v];
                continue;
            }
            $ext = strtolower(pathinfo($v, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) {
                $warnings[] = $slot . ': "' . $v . '" must be ' . implode('/', $allowed) . ' — skipped.';
                continue;
            }
            if ($zipIndex === null) {
                $warnings[] = $slot . ': "' . $v . '" needs the images ZIP — none uploaded, image skipped.';
                continue;
            }
            $entry = $zipIndex[strtolower(basename(str_replace('\\', '/', $v)))] ?? null;
            if ($entry === null) {
                $warnings[] = $slot . ': "' . $v . '" was not found in the ZIP — skipped.';
                continue;
            }
            $media[] = ['slot' => $slot, 'type' => $type, 'cover' => $isCover, 'zip' => $entry];
        }

        return [
            'line' => (int) ($raw['__line'] ?? 0),
            'sku' => $sku,
            'name' => $name ?? ($isUpdate ? (string) DB::table('products')->where('id', $pid)->value('name') : ''),
            'action' => $action,
            'product_id' => $pid,
            'errors' => $errors,
            'warnings' => $warnings,
            'creates' => $rowCreates,
            'data' => ['product' => $p, 'pricing' => $pricing, 'categories' => $cats, 'tags' => $tags, 'attrs' => $attrs, 'media' => $media, 'stock' => $stock],
            'variants' => [],
        ];
    }

    /**
     * Optional stock columns (stock_qty, stock_unit, low_stock_threshold). Stock is per product.
     *
     *   not tracked + stock_qty   → start tracking (opening movement, source import)
     *   tracked + different qty   → "import" adjustment (shown as before → after in the preview)
     *   blank stock_qty           → stock untouched; "-" is refused (use Stop tracking instead)
     *   low_stock_threshold "-"   → back to the default threshold
     *
     * @param \Closure(string):?string $cell
     * @param array<string,mixed> $pricing parsed pricing of this row (for the default stock unit)
     * @return array{action:string, qty:?float, unit:?string, before:?float, threshold:float|string|null}|null
     */
    private function analyseStock(\Closure $cell, ?int $pid, array $pricing, array &$errors, array &$warnings): ?array
    {
        $qtyRaw = $cell('stock_qty');
        $unitRaw = $cell('stock_unit');
        $thRaw = $cell('low_stock_threshold');
        if ($qtyRaw === null && $unitRaw === null && $thRaw === null) {
            return null;
        }
        $tracked = $pid ? DB::table('inventory_stock')->where('product_id', $pid)->first() : null;
        $n = count($errors);

        $parse = static function (string $v, string $label) use (&$errors): ?float {
            $s = str_replace([',', ' '], '', $v);
            if (!is_numeric($s) || (float) $s < 0) {
                $errors[] = $label . ' "' . $v . '" must be a number of 0 or more.';

                return null;
            }
            if (abs((float) $s - round((float) $s, 3)) > 1e-9) {
                $errors[] = $label . ' "' . $v . '" can have at most 3 decimal places.';

                return null;
            }

            return round((float) $s, 3);
        };

        $qty = null;
        if ($qtyRaw === self::CLEAR) {
            $errors[] = 'stock_qty cannot be cleared with "-". To stop tracking a product, use Stop tracking on the Stock management page.';
        } elseif ($qtyRaw !== null) {
            $qty = $parse($qtyRaw, 'stock_qty');
        }

        $unit = null;
        if ($unitRaw === self::CLEAR) {
            $errors[] = 'stock_unit cannot be cleared.';
        } elseif ($unitRaw !== null) {
            $unit = $this->unit($unitRaw);
            if ($unit === null) {
                $errors[] = 'stock_unit "' . $unitRaw . '" is not a known unit (' . implode(', ', $this->lk['unitList']) . ').';
            } elseif ($tracked && strcasecmp($unit, (string) $tracked['unit']) !== 0) {
                $errors[] = 'stock_unit "' . $unit . '" differs from the tracked stock unit "' . $tracked['unit'] . '". The stock unit cannot be changed by upload.';
            }
        }

        $threshold = null;
        if ($thRaw === self::CLEAR) {
            $threshold = 'default';
        } elseif ($thRaw !== null) {
            $threshold = $parse($thRaw, 'low_stock_threshold');
        }

        if (count($errors) > $n) {
            return null;
        }

        if (!$tracked) {
            if ($qty === null) {
                $warnings[] = 'stock_unit / low_stock_threshold ignored: add stock_qty to start tracking this product\'s stock.';

                return null;
            }
            if ($unit === null) {
                $unit = array_key_exists('unit', $pricing) ? $pricing['unit'] : ($pid ? DB::table('product_pricing')->where('product_id', $pid)->value('unit') : null);
                if (!$unit) {
                    $errors[] = 'stock_unit is required to start tracking stock (the product has no selling unit to use).';

                    return null;
                }
            }

            return ['action' => 'start', 'qty' => $qty, 'unit' => (string) $unit, 'before' => null, 'threshold' => $threshold === 'default' ? null : $threshold];
        }

        $before = round((float) $tracked['quantity'], 3);
        $action = $qty === null || $qty == $before ? 'same' : 'change';

        return ['action' => $action, 'qty' => $qty, 'unit' => (string) $tracked['unit'], 'before' => $before, 'threshold' => $threshold];
    }

    /** @param array<string,mixed> $raw */
    private function analyseVariant(array $raw, array &$creates): array
    {
        $errors = [];
        $vc = [];
        $get = static fn(string $k): string => trim((string) ($raw[$k] ?? ''));
        $productSku = $get('product_sku');
        $sku = $get('variant_sku');
        if ($productSku === '') {
            $errors[] = 'product_sku is required.';
        }
        if ($sku === '') {
            $errors[] = 'variant_sku is required.';
        } elseif (mb_strlen($sku) > 64) {
            $errors[] = 'variant_sku is longer than 64 characters.';
        }

        $options = [];
        $labels = [];
        foreach ($this->lk['options'] as $slug => $g) {
            $v = $get('opt:' . $slug);
            if ($v === '') {
                continue;
            }
            $id = $g['values'][self::norm($v)] ?? null;
            if ($id === null) {
                $vc[] = ['option value', $g['name'] . ': ' . $v];
                $options[] = ['group' => $g['id'], 'create' => $v];
                $labels[] = $v;
            } else {
                $options[] = ['id' => $id];
                $labels[] = $g['labels'][$id];
            }
        }
        $title = $get('title') ?: implode(' / ', $labels);
        if ($title === '' && !isset($this->lk['variantSku'][mb_strtolower($sku)])) {
            $errors[] = 'Give the new variant a title or at least one option value.';
        }

        $num = function (string $k) use ($get, &$errors): ?float {
            $v = $get($k);
            if ($v === '' || $v === self::CLEAR) {
                return null;
            }
            $n = self::number($v);
            if ($n === null) {
                $errors[] = $k . ' "' . $v . '" is not a valid number.';
            }

            return $n;
        };
        $unit = null;
        if (($u = $get('unit')) !== '' && $u !== self::CLEAR) {
            $unit = $this->unit($u);
            if ($unit === null) {
                $errors[] = 'Unit "' . $u . '" is not a known unit.';
            }
        }
        $status = strtolower($get('status')) ?: 'active';
        if (!in_array($status, self::VARIANT_STATUSES, true)) {
            $errors[] = 'Variant status "' . $status . '" is not valid (active, inactive, archived).';
        }
        $def = $get('is_default') === '' ? false : (bool) self::bool($get('is_default'));
        // Columns actually filled — an existing variant only gets these updated (blank = keep).
        $provided = [];
        foreach (['title', 'price', 'compare_at_price', 'unit', 'status', 'is_default'] as $k) {
            if ($get($k) !== '') {
                $provided[] = $k;
            }
        }
        if ($options && !in_array('title', $provided, true)) {
            $provided[] = 'title';
        }

        return [
            'line' => (int) ($raw['__line'] ?? 0),
            'product_sku' => $productSku,
            'sku' => $sku,
            'title' => mb_substr($title, 0, 255),
            'options' => $options,
            'price' => $num('price'),
            'compare_at_price' => $num('compare_at_price'),
            'unit' => $unit,
            'status' => $status,
            'is_default' => $def ? 1 : 0,
            'options_given' => (bool) $options,
            'provided' => $provided,
            'errors' => $errors,
            'creates' => $vc,
        ];
    }

    // ------------------------------------------------------------------ commit

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed> result
     */
    public function commit(array $plan, ?string $zipPath): array
    {
        $this->loadLookups();
        $zip = null;
        if ($zipPath && is_file($zipPath)) {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                $zip = null;
            }
        }
        $result = ['created' => 0, 'updated' => 0, 'variants' => 0, 'skipped' => 0, 'media' => 0, 'stock_started' => 0, 'stock_changed' => 0, 'auto' => [], 'warnings' => [], 'products' => []];
        $newFiles = [];
        $cache = [];

        try {
            DB::transaction(function () use ($plan, $zip, &$result, &$newFiles, &$cache): void {
                foreach ($plan['rows'] as $row) {
                    if (in_array($row['action'], ['error', 'skip'], true)) {
                        $result['skipped']++;
                        continue;
                    }
                    $pid = $row['product_id'] ? (int) $row['product_id'] : 0;
                    if ($pid && !DB::table('products')->where('id', $pid)->exists()) {
                        $pid = 0;
                    }
                    if (empty($row['variantsOnly'])) {
                        $pid = $this->writeProduct($row, $pid, $cache, $result);
                        $result['products'][] = ['id' => $pid, 'sku' => $row['sku'], 'name' => $row['name'], 'action' => $row['action']];
                        if ($row['data']['media'] !== null) {
                            $this->writeMedia($pid, $row['data']['media'], $zip, $row['sku'], $newFiles, $result);
                        }
                        if (!empty($row['data']['stock'])) {
                            $this->writeStock($pid, $row['data']['stock'], $row['sku'], $result);
                        }
                    }
                    if ($pid && $row['variants']) {
                        $this->writeVariants($pid, $row['variants'], $cache, $result);
                    }
                }
            });
        } catch (\Throwable $e) {
            foreach ($newFiles as $f) {
                @unlink($f);
            }
            throw $e;
        } finally {
            $zip?->close();
        }
        foreach ($result['auto'] as $k => $list) {
            $result['auto'][$k] = array_values(array_unique($list));
        }

        return $result;
    }

    /**
     * Apply a row's stock through InventoryService (same transaction as the rest of the import).
     * Re-checked against the live stock row, because stock may have moved since the preview.
     *
     * @param array{action:string, qty:?float, unit:?string, before:?float, threshold:float|string|null} $s
     */
    private function writeStock(int $pid, array $s, string $sku, array &$result): void
    {
        $meta = ['source' => 'import', 'notes' => 'Bulk upload'];
        try {
            if (!Inv::isTracked($pid)) {
                if ($s['qty'] === null) {
                    return;
                }
                Inv::startTracking($pid, (string) $s['unit'], (float) $s['qty'], is_numeric($s['threshold']) ? (float) $s['threshold'] : null, $meta);
                $result['stock_started']++;

                return;
            }
            if ($s['threshold'] !== null) {
                Inv::setThreshold($pid, $s['threshold'] === 'default' ? null : (float) $s['threshold']);
            }
            if ($s['qty'] !== null && Inv::setFromImport($pid, (float) $s['qty'], null, null, $meta)['action'] === 'changed') {
                $result['stock_changed']++;
            }
        } catch (InventoryException $e) {
            throw new \RuntimeException('Stock for ' . $sku . ': ' . $e->getMessage(), 0, $e);
        }
    }

    private function writeProduct(array $row, int $pid, array &$cache, array &$result): int
    {
        $d = $row['data'];
        $p = $d['product'];
        $now = date('Y-m-d H:i:s');
        $cols = [];
        foreach (['sku', 'name', 'status', 'product_type_id', 'short_description', 'description', 'meta_title', 'meta_description', 'external_product_id', 'is_enquiry_only', 'show_price', 'is_featured', 'is_bestseller'] as $k) {
            if (array_key_exists($k, $p)) {
                $cols[$k] = $p[$k];
            }
        }
        if (array_key_exists('brand', $p)) {
            $cols['brand_id'] = $p['brand'] === null ? null : $this->resolveBrand($p['brand'], $cache, $result);
        }
        if (array_key_exists('slug_source', $p)) {
            $cols['slug'] = $this->uniqueProductSlug((string) $p['slug_source'] ?: $row['sku'], $pid);
        }

        if ($pid) {
            if ($cols) {
                DB::table('products')->where('id', $pid)->update($cols + ['updated_at' => $now]);
            }
            $result['updated']++;
        } else {
            $pid = (int) DB::table('products')->insertGetId($cols + ['created_at' => $now, 'updated_at' => $now]);
            $result['created']++;
        }

        // Pricing: merge provided columns into the existing row.
        if ($d['pricing'] || !DB::table('product_pricing')->where('product_id', $pid)->exists()) {
            $existing = DB::table('product_pricing')->where('product_id', $pid)->first();
            $base = ['price' => null, 'compare_at_price' => null, 'cost_price' => null, 'currency' => 'INR', 'unit' => null, 'pack_qty' => null, 'pack_unit' => null];
            if ($existing) {
                foreach ($base as $k => $_) {
                    $base[$k] = is_array($existing) ? $existing[$k] : $existing->getAttribute($k);
                }
            }
            $merged = array_merge($base, $d['pricing']);
            if ($merged['pack_qty'] === null) {
                $merged['pack_unit'] = null;
            }
            DB::table('product_pricing')->where('product_id', $pid)->delete();
            DB::table('product_pricing')->insert($merged + ['product_id' => $pid]);
        }

        // Attributes (new values first go into the library).
        foreach ($d['attrs']['__new'] ?? [] as $attrId => $labels) {
            foreach ($labels as $label) {
                $key = 'av:' . $attrId . ':' . self::norm($label);
                if (!isset($cache[$key])) {
                    $exists = DB::select('SELECT id FROM attribute_values WHERE attribute_id = ? AND lower(label) = ?', [$attrId, mb_strtolower($label)]);
                    if (!$exists) {
                        $sort = (int) DB::table('attribute_values')->where('attribute_id', $attrId)->max('sort');
                        DB::table('attribute_values')->insert(['attribute_id' => $attrId, 'label' => $label, 'sort' => $sort + 1]);
                        $result['auto']['attribute value'][] = $label;
                    }
                    $cache[$key] = true;
                }
            }
        }
        foreach ($d['attrs'] as $attrId => $value) {
            if ($attrId === '__new') {
                continue;
            }
            DB::table('product_attribute_values')->where('product_id', $pid)->where('attribute_id', (int) $attrId)->delete();
            if ($value !== null && $value !== '') {
                DB::table('product_attribute_values')->insert(['product_id' => $pid, 'attribute_id' => (int) $attrId, 'value' => $value]);
            }
        }

        if ($d['categories'] !== null) {
            DB::table('product_category_map')->where('product_id', $pid)->delete();
            $done = [];
            foreach ($d['categories']['list'] as $i => $ref) {
                $cid = $this->resolveCategory($ref, $cache, $result);
                if (isset($done[$cid])) {
                    continue;
                }
                $done[$cid] = true;
                DB::table('product_category_map')->insert(['product_id' => $pid, 'category_id' => $cid, 'is_primary' => $i === ($d['categories']['primary'] ?? 0) ? 1 : 0]);
            }
        }

        if ($d['tags'] !== null) {
            DB::table('product_tags')->where('product_id', $pid)->delete();
            $done = [];
            foreach ($d['tags'] as $name) {
                $slug = CatalogController::slugify($name);
                if ($slug === '' || isset($done[$slug])) {
                    continue;
                }
                $done[$slug] = true;
                $tid = DB::table('tags')->where('slug', $slug)->value('id');
                if (!$tid) {
                    $tid = DB::table('tags')->insertGetId(['name' => $name, 'slug' => $slug]);
                    $result['auto']['tag'][] = $name;
                }
                DB::table('product_tags')->insert(['product_id' => $pid, 'tag_id' => (int) $tid]);
            }
        }

        return $pid;
    }

    private function writeMedia(int $pid, array $media, ?\ZipArchive $zip, string $sku, array &$newFiles, array &$result): void
    {
        $dir = app()->publicPath('assets/uploads/products');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        // Filling any image column replaces the product's images; the video column replaces its video.
        $replace = [];
        foreach ($media as $m) {
            $replace[$m['type']] = true;
        }
        foreach (array_keys($replace) as $type) {
            foreach (DB::select('SELECT id, path FROM product_media WHERE product_id = ? AND type = ?', [$pid, $type]) as $old) {
                $full = app()->publicPath($old['path']);
                if (is_file($full) && str_contains($old['path'], 'assets/uploads/')) {
                    // Deleted only after a successful commit would be ideal; keep it simple: remove now.
                    @unlink($full);
                }
                DB::table('product_media')->where('id', (int) $old['id'])->delete();
            }
        }
        $sort = (int) DB::table('product_media')->where('product_id', $pid)->max('sort');
        $hasCover = false;
        foreach ($media as $m) {
            if (!empty($m['clear'])) {
                continue;
            }
            $allowed = $m['type'] === 'video' ? self::VIDEO_EXT : self::IMAGE_EXT;
            $bytes = null;
            $ext = '';
            $source = '';
            if (isset($m['zip'])) {
                $source = $m['zip'];
                $stat = $zip?->statName($m['zip']);
                if (!$zip || !$stat || $stat['size'] > self::MAX_MEDIA_BYTES) {
                    $result['warnings'][] = $sku . ': ' . $m['slot'] . ' "' . basename($m['zip']) . '" could not be read from the ZIP (missing or over 20 MB).';
                    continue;
                }
                $bytes = $zip->getFromName($m['zip']);
                $ext = strtolower(pathinfo($m['zip'], PATHINFO_EXTENSION));
            } elseif (isset($m['url'])) {
                $source = $m['url'];
                [$bytes, $ext, $err] = self::download($m['url'], $m['type']);
                if ($bytes === null) {
                    $result['warnings'][] = $sku . ': ' . $m['slot'] . ' link failed — ' . $err;
                    continue;
                }
            }
            if ($bytes === null || $bytes === false || $bytes === '') {
                continue;
            }
            if ($m['type'] === 'image' && @getimagesizefromstring($bytes) === false && $ext !== 'avif') {
                $result['warnings'][] = $sku . ': ' . $m['slot'] . ' "' . basename($source) . '" is not a valid image.';
                continue;
            }
            if (!in_array($ext, $allowed, true)) {
                $ext = $m['type'] === 'video' ? 'mp4' : 'jpg';
            }
            $name = bin2hex(random_bytes(8)) . '.' . $ext;
            if (@file_put_contents($dir . '/' . $name, $bytes) === false) {
                $result['warnings'][] = $sku . ': could not save ' . $m['slot'] . '.';
                continue;
            }
            $newFiles[] = $dir . '/' . $name;
            $isCover = $m['cover'] && !$hasCover ? 1 : 0;
            $hasCover = $hasCover || $isCover;
            DB::table('product_media')->insert([
                'product_id' => $pid, 'path' => 'assets/uploads/products/' . $name, 'type' => $m['type'], 'is_cover' => $isCover, 'sort' => ++$sort,
            ]);
            $result['media']++;
        }
        // No cover given but images exist → first image becomes the cover.
        if (!DB::table('product_media')->where('product_id', $pid)->where('is_cover', 1)->exists()) {
            $first = DB::select("SELECT id FROM product_media WHERE product_id = ? AND type = 'image' ORDER BY sort LIMIT 1", [$pid]);
            if ($first) {
                DB::table('product_media')->where('id', (int) $first[0]['id'])->update(['is_cover' => 1]);
            }
        }
    }

    private function writeVariants(int $pid, array $variants, array &$cache, array &$result): void
    {
        $now = date('Y-m-d H:i:s');
        $sort = (int) DB::table('product_variants')->where('product_id', $pid)->max('sort');
        $hasDefault = false;
        foreach ($variants as $v) {
            $ids = [];
            foreach ($v['options'] as $o) {
                $ids[] = isset($o['id']) ? (int) $o['id'] : $this->resolveOptionValue((int) $o['group'], (string) $o['create'], $cache, $result);
            }
            $data = [
                'sku' => $v['sku'], 'title' => $v['title'], 'price' => $v['price'], 'compare_at_price' => $v['compare_at_price'],
                'unit' => $v['unit'], 'status' => $v['status'], 'is_default' => $v['is_default'], 'updated_at' => $now,
            ];
            $existing = DB::select('SELECT id FROM product_variants WHERE sku = ? AND product_id = ?', [$v['sku'], $pid]);
            if ($existing) {
                $vid = (int) $existing[0]['id'];
                $provided = $v['provided'] ?? array_keys($data);
                $upd = array_intersect_key($data, array_flip(array_merge($provided, ['updated_at'])));
                DB::table('product_variants')->where('id', $vid)->update($upd);
                if (!($v['options_given'] ?? true)) {
                    $ids = array_map(static fn($r) => (int) $r['option_value_id'], DB::select('SELECT option_value_id FROM product_variant_values WHERE variant_id = ?', [$vid]));
                }
                DB::table('product_variant_values')->where('variant_id', $vid)->delete();
            } else {
                $vid = (int) DB::table('product_variants')->insertGetId($data + ['product_id' => $pid, 'sort' => ++$sort, 'created_at' => $now]);
            }
            foreach (array_unique($ids) as $ovid) {
                DB::table('product_variant_values')->insert(['variant_id' => $vid, 'option_value_id' => $ovid]);
            }
            if ($v['is_default'] && !$hasDefault) {
                DB::table('product_variants')->where('product_id', $pid)->where('id', '!=', $vid)->update(['is_default' => 0]);
                $hasDefault = true;
            }
            $result['variants']++;
        }
        if (!DB::table('product_variants')->where('product_id', $pid)->where('is_default', 1)->exists()) {
            $first = DB::select('SELECT id FROM product_variants WHERE product_id = ? ORDER BY sort, id LIMIT 1', [$pid]);
            if ($first) {
                DB::table('product_variants')->where('id', (int) $first[0]['id'])->update(['is_default' => 1]);
            }
        }
    }

    // ------------------------------------------------------------------ resolvers

    private function resolveBrand(array $ref, array &$cache, array &$result): int
    {
        if (isset($ref['id'])) {
            return (int) $ref['id'];
        }
        $name = (string) $ref['create'];
        $key = 'brand:' . CatalogController::slugify($name);
        if (!isset($cache[$key])) {
            $id = DB::table('brands')->where('slug', CatalogController::slugify($name))->value('id');
            if (!$id) {
                $now = date('Y-m-d H:i:s');
                $id = DB::table('brands')->insertGetId(['name' => $name, 'slug' => CatalogController::slug('brands', $name), 'created_at' => $now, 'updated_at' => $now]);
                $result['auto']['brand'][] = $name;
            }
            $cache[$key] = (int) $id;
        }

        return $cache[$key];
    }

    private function resolveCategory(array $ref, array &$cache, array &$result): int
    {
        if (isset($ref['id'])) {
            return (int) $ref['id'];
        }
        [$parentName, $childName, $parentId] = $ref['create'];
        $now = date('Y-m-d H:i:s');
        if (!$parentId) {
            $pk = 'cat:' . CatalogController::slugify($parentName);
            if (!isset($cache[$pk])) {
                $found = DB::select('SELECT id FROM product_categories WHERE parent_id IS NULL AND lower(name) = ?', [mb_strtolower($parentName)]);
                if ($found) {
                    $cache[$pk] = (int) $found[0]['id'];
                } else {
                    $cache[$pk] = (int) DB::table('product_categories')->insertGetId(['name' => $parentName, 'slug' => CatalogController::slug('product_categories', $parentName), 'created_at' => $now, 'updated_at' => $now]);
                    $result['auto']['category'][] = $parentName;
                }
            }
            $parentId = $cache[$pk];
        }
        if ($childName === null) {
            return (int) $parentId;
        }
        $ck = 'cat:' . $parentId . '>' . CatalogController::slugify($childName);
        if (!isset($cache[$ck])) {
            $found = DB::select('SELECT id FROM product_categories WHERE parent_id = ? AND lower(name) = ?', [$parentId, mb_strtolower($childName)]);
            if ($found) {
                $cache[$ck] = (int) $found[0]['id'];
            } else {
                $parentSlug = (string) DB::table('product_categories')->where('id', $parentId)->value('slug');
                $cache[$ck] = (int) DB::table('product_categories')->insertGetId([
                    'parent_id' => $parentId, 'name' => $childName,
                    'slug' => CatalogController::slug('product_categories', $parentSlug . ' ' . $childName),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $parentLabel = (string) DB::table('product_categories')->where('id', $parentId)->value('name');
                $result['auto']['category'][] = $parentLabel . ' > ' . $childName;
            }
        }

        return $cache[$ck];
    }

    private function resolveOptionValue(int $groupId, string $value, array &$cache, array &$result): int
    {
        $key = 'opt:' . $groupId . ':' . self::norm($value);
        if (!isset($cache[$key])) {
            $found = DB::select('SELECT id FROM option_values WHERE option_group_id = ? AND lower(value) = ?', [$groupId, mb_strtolower($value)]);
            if ($found) {
                $cache[$key] = (int) $found[0]['id'];
            } else {
                $sort = (int) DB::table('option_values')->where('option_group_id', $groupId)->max('sort');
                $cache[$key] = (int) DB::table('option_values')->insertGetId(['option_group_id' => $groupId, 'value' => $value, 'sort' => $sort + 1]);
                $result['auto']['option value'][] = $value;
            }
        }

        return $cache[$key];
    }

    /**
     * Category token → ['id'=>n] or ['create'=>[parentName, childName|null, existingParentId|null]].
     * Accepts a slug, a name, or "Parent > Child".
     */
    private function categoryRef(string $tok, ?string &$err): ?array
    {
        $err = null;
        $parts = array_values(array_filter(array_map('trim', preg_split('/\s*(?:>|›|\/)\s*/u', $tok) ?: []), 'strlen'));
        if (count($parts) > 2) {
            $err = 'Category "' . $tok . '" has more than 2 levels (only Parent > Child is supported).';

            return null;
        }
        $cats = $this->lk['categories'];
        if (count($parts) === 1) {
            $one = $parts[0];
            $slug = CatalogController::slugify($one);
            foreach ($cats as $c) {
                if ($c['slug'] === $slug) {
                    return ['id' => $c['id']];
                }
            }
            $byName = array_values(array_filter($cats, static fn($c) => mb_strtolower($c['name']) === mb_strtolower($one)));
            usort($byName, static fn($a, $b) => (int) (bool) $a['parent_id'] <=> (int) (bool) $b['parent_id']);
            if ($byName) {
                return ['id' => $byName[0]['id']];
            }

            return ['create' => [$one, null, null]];
        }
        [$pName, $cName] = $parts;
        $parent = null;
        foreach ($cats as $c) {
            if (!$c['parent_id'] && (mb_strtolower($c['name']) === mb_strtolower($pName) || $c['slug'] === CatalogController::slugify($pName))) {
                $parent = $c;
                break;
            }
        }
        if ($parent === null) {
            foreach ($cats as $c) {
                if ($c['parent_id'] && mb_strtolower($c['name']) === mb_strtolower($pName)) {
                    $err = 'Category "' . $pName . '" is a sub-category, so it cannot have children (2 levels max).';

                    return null;
                }
            }

            return ['create' => [$pName, $cName, null]];
        }
        foreach ($cats as $c) {
            if ((int) $c['parent_id'] === $parent['id'] && (mb_strtolower($c['name']) === mb_strtolower($cName) || $c['slug'] === CatalogController::slugify($cName) || $c['slug'] === CatalogController::slugify($parent['slug'] . ' ' . $cName))) {
                return ['id' => $c['id']];
            }
        }

        return ['create' => [$parent['name'], $cName, $parent['id']]];
    }

    // ------------------------------------------------------------------ helpers

    private function loadLookups(): void
    {
        $lk = ['brands' => [], 'brandsByName' => [], 'types' => [], 'typesByName' => [], 'tags' => [], 'units' => [], 'unitList' => [], 'categories' => [], 'attributes' => [], 'options' => [], 'productSku' => [], 'variantSku' => []];
        foreach (DB::select('SELECT id, name, slug FROM brands') as $b) {
            $lk['brands'][$b['slug']] = (int) $b['id'];
            $lk['brandsByName'][mb_strtolower($b['name'])] = (int) $b['id'];
        }
        foreach (DB::select('SELECT id, name, slug FROM product_types ORDER BY id') as $t) {
            $lk['types'][$t['slug']] = (int) $t['id'];
            $lk['typesByName'][mb_strtolower($t['name'])] = (int) $t['id'];
        }
        $lk['defaultType'] = $lk['types'] ? reset($lk['types']) : 0;
        foreach (DB::select('SELECT slug FROM tags') as $t) {
            $lk['tags'][$t['slug']] = true;
        }
        foreach (DB::select('SELECT symbol, name FROM units ORDER BY sort, name') as $u) {
            $lk['units'][mb_strtolower($u['symbol'])] = $u['symbol'];
            $lk['units'][mb_strtolower($u['name'])] = $u['symbol'];
            $lk['units'][self::norm($u['symbol'])] = $u['symbol'];
            $lk['unitList'][] = $u['symbol'];
        }
        foreach (DB::select('SELECT id, name, slug, parent_id FROM product_categories') as $c) {
            $lk['categories'][] = ['id' => (int) $c['id'], 'name' => $c['name'], 'slug' => $c['slug'], 'parent_id' => $c['parent_id'] ? (int) $c['parent_id'] : null];
        }
        foreach (self::attributeDefs() as $a) {
            $vals = [];
            foreach ($a['values'] as $label) {
                $vals[self::norm($label)] = $label;
            }
            $lk['attributes'][$a['code']] = ['id' => $a['id'], 'name' => $a['name'], 'type' => $a['type'], 'values' => $vals];
        }
        foreach (DB::select('SELECT id, slug, name FROM option_groups') as $g) {
            $lk['options'][$g['slug']] = ['id' => (int) $g['id'], 'name' => $g['name'], 'values' => [], 'labels' => []];
        }
        $groupSlug = array_column(DB::select('SELECT id, slug FROM option_groups'), 'slug', 'id');
        foreach (DB::select('SELECT id, option_group_id, value FROM option_values') as $v) {
            $slug = $groupSlug[$v['option_group_id']] ?? null;
            if ($slug !== null) {
                $lk['options'][$slug]['values'][self::norm($v['value'])] = (int) $v['id'];
                $lk['options'][$slug]['labels'][(int) $v['id']] = $v['value'];
            }
        }
        foreach (DB::select('SELECT id, sku FROM products') as $p) {
            $lk['productSku'][mb_strtolower($p['sku'])] = (int) $p['id'];
        }
        foreach (DB::select('SELECT product_id, sku FROM product_variants') as $v) {
            $lk['variantSku'][mb_strtolower($v['sku'])] = (int) $v['product_id'];
        }
        $this->lk = $lk;
    }

    private function unit(string $v): ?string
    {
        return $this->lk['units'][mb_strtolower(trim($v))] ?? $this->lk['units'][self::norm($v)] ?? null;
    }

    private function uniqueProductSlug(string $source, int $ignoreId): string
    {
        $base = CatalogController::slugify($source) ?: 'product';
        $slug = $base;
        $i = 2;
        while (DB::table('products')->where('slug', $slug)->where('id', '!=', $ignoreId)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    /** lowercase, no spaces, "x" and "*" → "×" so "600x1200mm" matches "600 × 1200 mm". */
    public static function norm(string $v): string
    {
        $v = mb_strtolower(trim($v));
        $v = preg_replace('/(?<=\d)\s*[x\*]\s*(?=\d)/u', '×', $v) ?? $v;

        return preg_replace('/\s+/u', '', $v) ?? $v;
    }

    /** @return list<string> */
    public static function split(string $v, string $seps): array
    {
        $parts = preg_split('/[' . preg_quote($seps, '/') . ']/u', $v) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $parts), 'strlen')));
    }

    public static function bool(string $v): ?int
    {
        $v = strtolower(trim($v));

        return match (true) {
            in_array($v, ['1', 'yes', 'y', 'true', 'on', 'x', '✓'], true) => 1,
            in_array($v, ['0', 'no', 'n', 'false', 'off'], true) => 0,
            default => null,
        };
    }

    public static function number(string $v): ?float
    {
        $v = str_replace([',', '₹', 'Rs.', 'Rs', 'INR', ' '], '', trim($v));

        return is_numeric($v) ? round((float) $v, 3) : null;
    }

    /** @return array<string,string>|null lowercase basename → entry name */
    public static function zipIndex(string $zipPath): ?array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return null;
        }
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_ends_with($name, '/') || str_starts_with(basename($name), '.') || str_contains($name, '__MACOSX')) {
                continue;
            }
            $out[strtolower(basename($name))] ??= $name;
        }
        $zip->close();

        return $out;
    }

    /** @return array{0:?string,1:string,2:string} bytes, extension, error */
    public static function download(string $url, string $type): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $ip = $host !== '' ? gethostbyname($host) : '';
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return [null, '', 'host "' . $host . '" is not reachable or is a private address.'];
        }
        $ch = curl_init($url);
        $buf = '';
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (catalogue importer)',
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buf): int {
                $buf .= $chunk;

                return strlen($buf) > self::MAX_MEDIA_BYTES ? 0 : strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ctype = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
        $error = curl_error($ch);
        curl_close($ch);
        if (strlen($buf) > self::MAX_MEDIA_BYTES) {
            return [null, '', 'file is larger than 20 MB.'];
        }
        if ($ok === false || $code >= 400 || $buf === '') {
            return [null, '', $code >= 400 ? 'server answered HTTP ' . $code . '.' : ($error ?: 'empty response.')];
        }
        $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif', 'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'];
        $ext = $map[trim(explode(';', $ctype)[0])] ?? strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if ($type === 'image' && !str_starts_with($ctype, 'image/') && @getimagesizefromstring($buf) === false) {
            return [null, '', 'link did not return an image (got ' . ($ctype ?: 'unknown type') . ').'];
        }

        return [$buf, $ext, ''];
    }
}
