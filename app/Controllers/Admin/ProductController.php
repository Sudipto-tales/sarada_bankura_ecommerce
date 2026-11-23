<?php

namespace App\Controllers\Admin;

use App\Models\Product;
use App\Requests\StoreProductRequest;
use App\Services\Media\MediaException;
use App\Services\Media\MediaService as Media;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;
use Vayu\Validation\ValidationException;
use Vayu\Validation\Validator;

class ProductController
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        $type = (string) $request->query('type', '');
        $category = (string) $request->query('category', '');

        $where = [];
        $bind = [];
        if ($q !== '') {
            $where[] = '(p.name LIKE ? OR p.sku LIKE ?)';
            $bind[] = "%{$q}%";
            $bind[] = "%{$q}%";
        }
        if ($status !== '') {
            $where[] = 'p.status = ?';
            $bind[] = $status;
        }
        if ($type !== '') {
            $where[] = 'p.product_type_id = ?';
            $bind[] = (int) $type;
        }
        if ($category !== '') {
            $where[] = 'p.id IN (SELECT product_id FROM product_category_map WHERE category_id = ? OR category_id IN (SELECT id FROM product_categories WHERE parent_id = ?))';
            $bind[] = (int) $category;
            $bind[] = (int) $category;
        }
        $sql = 'SELECT p.*, pt.name AS type_name, b.name AS brand_name,
                    pr.price, pr.currency, pr.unit AS selling_unit,
                    s.id AS stock_id, s.quantity AS stock_qty, s.unit AS stock_unit, s.status AS stock_status,
                    ' . Media::coverSql('product', 'p.id') . ' AS cover,
                    (SELECT COUNT(*) FROM media_links ml WHERE ml.owner_type = \'product\' AND ml.owner_id = p.id) AS media_count,
                    (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id) AS variant_count
             FROM products p
             LEFT JOIN product_types pt ON pt.id = p.product_type_id
             LEFT JOIN brands b ON b.id = p.brand_id
             LEFT JOIN product_pricing pr ON pr.product_id = p.id
             LEFT JOIN inventory_stock s ON s.product_id = p.id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY p.id DESC';

        return view('admin.products.index', [
            'products' => DB::select($sql, $bind),
            'types' => DB::select('SELECT id, name FROM product_types ORDER BY name'),
            'categories' => $this->categoryOptions(),
            'filters' => ['q' => $q, 'status' => $status, 'type' => $type, 'category' => $category],
        ]);
    }

    public function destroy(Request $request, string $id): Response
    {
        $pid = (int) $id;
        $name = DB::table('products')->where('id', $pid)->value('name');
        if ($name !== null) {
            // Links go first; files are removed only when nothing else uses them.
            foreach (DB::select('SELECT id FROM product_variants WHERE product_id = ?', [$pid]) as $v) {
                Media::detachAll('variant', (int) $v['id']);
            }
            Media::detachAll('product', $pid);
            $this->deleteVariants($pid);
            // Close its stock alerts (clears their unread notifications), then drop inventory rows with the product.
            \App\Services\Inventory\AlertService::resolveAllFor($pid);
            foreach (['product_pricing', 'product_attribute_values', 'product_category_map', 'product_tags', 'product_reviews', 'inventory_stock', 'inventory_alerts', 'inventory_movements'] as $t) {
                DB::table($t)->where('product_id', $pid)->delete();
            }
            DB::table('products')->where('id', $pid)->delete();
            session()->flash('success', 'Product "' . $name . '" deleted.');
        }

        return redirect(route('admin.products.index'));
    }

    public function create(): Response
    {
        return view('admin.products.create', $this->formData() + [
            'stockEnabled' => \App\Services\Inventory\InventorySettings::enabled(),
        ]);
    }

    public function store(StoreProductRequest $request): Response
    {
        $variants = $this->validatedVariants($request, 0);
        $product = Product::create($this->productAttributes($request));
        $id = (int) $product->getKey();

        $this->saveRelations($id, $request);
        $this->saveVariants($id, $variants);
        $this->storeMedia($request, $id);
        $this->applyStockTracking($id, $request);

        session()->flash('success', 'Product "' . $product->getAttribute('name') . '" created.');

        return redirect(route('admin.products.index'));
    }

    public function edit(Request $request, string $id): Response
    {
        $pid = (int) $id;
        $product = DB::table('products')->where('id', $pid)->first();
        if ($product === null) {
            return redirect(route('admin.products.index'));
        }

        $values = [];
        foreach (DB::select('SELECT attribute_id, value FROM product_attribute_values WHERE product_id = ?', [$pid]) as $r) {
            $values[(int) $r['attribute_id']] = $r['value'];
        }
        $cats = DB::select('SELECT category_id, is_primary FROM product_category_map WHERE product_id = ?', [$pid]);
        $tags = DB::select('SELECT t.name FROM tags t JOIN product_tags pt ON pt.tag_id = t.id WHERE pt.product_id = ?', [$pid]);

        return view('admin.products.create', $this->formData() + [
            'isEdit' => true,
            'product' => $product,
            'pricing' => DB::table('product_pricing')->where('product_id', $pid)->first(),
            'attrValues' => $values,
            'selectedCats' => array_map(static fn($c) => (int) $c['category_id'], $cats),
            'primaryCat' => (int) (array_values(array_filter($cats, static fn($c) => (int) $c['is_primary'] === 1))[0]['category_id'] ?? 0),
            'tagString' => implode(', ', array_map(static fn($t) => $t['name'], $tags)),
            'media' => Media::linksFor('product', $pid, ['cover', 'gallery', 'video']),
            'variants' => $this->loadVariants($pid),
            'stockRow' => \App\Services\Inventory\InventoryService::stockFor($pid),
            'stockEnabled' => \App\Services\Inventory\InventorySettings::enabled(),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $pid = (int) $id;
        if (!DB::table('products')->where('id', $pid)->exists()) {
            return redirect(route('admin.products.index'));
        }

        $data = $request->input();
        Validator::make($data, [
            'name' => 'required|string|min:2|max:255',
            'sku' => 'required|string|max:64|unique:products,sku,' . $pid,
            'status' => 'required|in:draft,active,coming_soon,archived',
            'product_type_id' => 'required',
            'short_description' => 'nullable|max:500',
            'price' => 'nullable|numeric',
            'compare_at_price' => 'nullable|numeric',
            'cost_price' => 'nullable|numeric',
            'meta_description' => 'nullable|max:500',
        ], [
            'sku.unique' => 'This SKU is already in use.',
        ])->validate();
        $variants = $this->validatedVariants($request, $pid);

        $attrs = $this->productAttributes($request);
        $attrs['slug'] = $this->uniqueSlug($request->input('slug') ?: $request->input('name'), $pid);
        DB::table('products')->where('id', $pid)->update($attrs);

        $this->saveRelations($pid, $request, true);
        $this->saveVariants($pid, $variants);
        $this->storeMedia($request, $pid);
        $this->applyStockTracking($pid, $request);

        session()->flash('success', 'Product "' . $request->input('name') . '" updated.');

        return redirect(route('admin.products.index'));
    }

    /** Remove an image/video from this product (and its variants). The file stays in the library if used elsewhere. */
    public function deleteMedia(Request $request, string $id, string $mediaId): Response
    {
        $pid = (int) $id;
        $mid = (int) $mediaId;
        foreach ($this->variantIds($pid) as $vid) {
            Media::detach('variant', $vid, $mid);
        }
        Media::detach('product', $pid, $mid);
        Media::ensureCover('product', $pid);

        return $this->galleryReply($request, $pid, 'Removed from this product.');
    }

    /** Attach files already in the library ("pick from library"). */
    public function attachMedia(Request $request, string $id): Response
    {
        $pid = (int) $id;
        if (!DB::table('products')->where('id', $pid)->exists()) {
            return $this->galleryReply($request, $pid, 'Product not found.', false);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('media_ids', [])))));
        try {
            foreach ($ids as $mid) {
                $m = Media::find($mid);
                if ($m === null || !in_array($m['kind'], ['image', 'video'], true) || $m['disk'] !== 'public') {
                    continue;
                }
                Media::attach('product', $pid, $mid, $m['kind'] === 'video' ? 'video' : 'gallery');
            }
            Media::ensureCover('product', $pid);
        } catch (MediaException $e) {
            return $this->galleryReply($request, $pid, $e->getMessage(), false);
        }

        return $this->galleryReply($request, $pid, count($ids) . ' file' . (count($ids) === 1 ? '' : 's') . ' added.');
    }

    public function reorderMedia(Request $request, string $id): Response
    {
        $pid = (int) $id;
        $order = array_filter(array_map('intval', explode(',', (string) $request->input('order', ''))));
        Media::reorder('product', $pid, $order);

        return $this->galleryReply($request, $pid, 'Order saved.');
    }

    public function coverMedia(Request $request, string $id, string $mediaId): Response
    {
        $pid = (int) $id;
        try {
            Media::setCover('product', $pid, (int) $mediaId);
        } catch (MediaException $e) {
            return $this->galleryReply($request, $pid, $e->getMessage(), false);
        }

        return $this->galleryReply($request, $pid, 'Cover updated.');
    }

    /** Show an image for one variant (variant_id = 0 → none). An image belongs to at most one variant. */
    public function variantMedia(Request $request, string $id, string $mediaId): Response
    {
        $pid = (int) $id;
        $mid = (int) $mediaId;
        $vid = (int) $request->input('variant_id', 0);
        $variants = $this->variantIds($pid);
        if ($vid && !in_array($vid, $variants, true)) {
            return $this->galleryReply($request, $pid, 'That variant is not part of this product.', false);
        }
        if (!DB::table('media_links')->where('owner_type', 'product')->where('owner_id', $pid)->where('media_id', $mid)->exists()) {
            return $this->galleryReply($request, $pid, 'That file is not attached to this product.', false);
        }
        foreach ($variants as $v) {
            if ($v !== $vid) {
                DB::table('media_links')->where('owner_type', 'variant')->where('owner_id', $v)->where('media_id', $mid)->delete();
            }
        }
        if ($vid) {
            Media::attach('variant', $vid, $mid, 'gallery');
        }

        return $this->galleryReply($request, $pid, $vid ? 'Assigned to the variant.' : 'Variant assignment cleared.');
    }

    /** Detail page: gallery management + read-only summary. Editing stays on the wizard. */
    public function show(Request $request, string $id): Response
    {
        $pid = (int) $id;
        $product = DB::selectOne(
            'SELECT p.*, pt.name AS type_name, b.name AS brand_name FROM products p
             LEFT JOIN product_types pt ON pt.id = p.product_type_id LEFT JOIN brands b ON b.id = p.brand_id WHERE p.id = ?',
            [$pid]
        );
        if ($product === null) {
            session()->flash('error', 'Product not found.');

            return redirect(route('admin.products.index'));
        }
        $variants = DB::select('SELECT id, sku, title, price, status, is_default FROM product_variants WHERE product_id = ? ORDER BY sort, id', [$pid]);
        $variantOf = [];
        if ($variants) {
            $vids = array_map(static fn($v) => (int) $v['id'], $variants);
            foreach (DB::select("SELECT owner_id, media_id FROM media_links WHERE owner_type = 'variant' AND owner_id IN (" . implode(',', $vids) . ')') as $l) {
                $variantOf[(int) $l['media_id']] = (int) $l['owner_id'];
            }
        }
        $reviews = DB::selectOne(
            "SELECT COUNT(*) AS n, AVG(rating) AS avg,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending
             FROM product_reviews WHERE product_id = ?",
            [$pid]
        ) ?? ['n' => 0, 'avg' => null, 'pending' => 0];

        return view('admin.products.show', [
            'active' => 'products',
            'product' => $product,
            'pricing' => DB::table('product_pricing')->where('product_id', $pid)->first(),
            'media' => Media::linksFor('product', $pid, ['cover', 'gallery', 'video']),
            'variantOf' => $variantOf,
            'variants' => $variants,
            'categories' => DB::select('SELECT c.name, m.is_primary FROM product_category_map m JOIN product_categories c ON c.id = m.category_id WHERE m.product_id = ? ORDER BY m.is_primary DESC, c.name', [$pid]),
            'tags' => DB::select('SELECT t.name FROM tags t JOIN product_tags pt ON pt.tag_id = t.id WHERE pt.product_id = ? ORDER BY t.name', [$pid]),
            'attributes' => DB::select('SELECT a.name, a.unit, v.value FROM product_attribute_values v JOIN attributes a ON a.id = v.attribute_id WHERE v.product_id = ? ORDER BY a.sort, a.name', [$pid]),
            'stock' => \App\Services\Inventory\InventoryService::stockFor($pid),
            'reviews' => $reviews,
            'limits' => ['image' => Media::uploadLimit('image'), 'video' => Media::uploadLimit('video')],
            'success' => session()->get('success'),
            'error' => session()->get('error'),
        ]);
    }

    /** @return list<int> */
    private function variantIds(int $pid): array
    {
        return array_map(static fn($r) => (int) $r['id'], DB::select('SELECT id FROM product_variants WHERE product_id = ?', [$pid]));
    }

    private function galleryReply(Request $request, int $pid, string $message, bool $ok = true): Response
    {
        if ($request->wantsJson()) {
            return json([
                'ok' => $ok, $ok ? 'message' : 'error' => $message,
                'media' => $ok ? array_map(static fn($m) => [
                    'id' => (int) $m['id'], 'role' => $m['role'], 'kind' => $m['kind'], 'thumb_url' => $m['thumb_url'], 'url' => $m['url'],
                ], Media::linksFor('product', $pid, ['cover', 'gallery', 'video'])) : null,
            ], $ok ? 200 : 422);
        }
        session()->flash($ok ? 'success' : 'error', $message);
        $back = (string) ($request->header('Referer') ?? '');

        return redirect(str_contains($back, '/admin/products/') ? $back : route('admin.products.show', ['id' => $pid]));
    }

    /** @return array<string,mixed> */
    private function productAttributes(Request $request): array
    {
        return [
            'external_product_id' => $request->input('external_product_id') ?: null,
            'status' => $request->input('status', 'draft'),
            'sku' => $request->input('sku'),
            'name' => $request->input('name'),
            'slug' => $this->uniqueSlug($request->input('slug') ?: $request->input('name')),
            'short_description' => $request->input('short_description') ?: null,
            'description' => $request->input('description') ?: null,
            'product_type_id' => (int) $request->input('product_type_id'),
            'brand_id' => $request->input('brand_id') ? (int) $request->input('brand_id') : null,
            'is_enquiry_only' => $request->input('is_enquiry_only') ? 1 : 0,
            'show_price' => $request->input('show_price') ? 1 : 0,
            'is_featured' => $request->input('is_featured') ? 1 : 0,
            'is_bestseller' => $request->input('is_bestseller') ? 1 : 0,
            'meta_title' => $request->input('meta_title') ?: null,
            'meta_description' => $request->input('meta_description') ?: null,
        ];
    }

    /**
     * Optional "Track stock" toggle from the product form. Only ever *starts* tracking —
     * it never stops it or overwrites an existing stock count (those live on the Stock page),
     * so re-saving a product keeps its stock intact.
     */
    private function applyStockTracking(int $id, Request $request): void
    {
        if (!(bool) $request->input('track_stock')) {
            return;
        }
        if (!\App\Services\Inventory\InventorySettings::enabled()) {
            return;
        }
        if (\App\Services\Inventory\InventoryService::isTracked($id)) {
            return;
        }
        $unit = trim((string) ($request->input('stock_unit') ?: $request->input('selling_unit')));
        if ($unit === '') {
            session()->flash('error', 'Stock tracking was not started: choose a stock unit first.');
            return;
        }
        $threshold = $request->input('low_stock_threshold');
        try {
            \App\Services\Inventory\InventoryService::startTracking(
                $id,
                $unit,
                (float) (num_or_null($request->input('opening_qty')) ?? 0),
                is_numeric($threshold) ? (float) $threshold : null,
                ['source' => 'opening', 'notes' => 'Opening stock set from product form'],
            );
        } catch (\App\Services\Inventory\InventoryException $e) {
            session()->flash('error', 'Stock tracking was not started: ' . $e->getMessage());
        }
    }

    private function saveRelations(int $id, Request $request, bool $replace = false): void
    {
        if ($replace) {
            foreach (['product_pricing', 'product_attribute_values', 'product_category_map', 'product_tags'] as $t) {
                DB::table($t)->where('product_id', $id)->delete();
            }
        }

        DB::table('product_pricing')->insert([
            'product_id' => $id,
            'price' => num_or_null($request->input('price')),
            'compare_at_price' => num_or_null($request->input('compare_at_price')),
            'cost_price' => num_or_null($request->input('cost_price')),
            'currency' => $request->input('currency', 'INR'),
            'unit' => $request->input('selling_unit') ?: null,
            'pack_qty' => num_or_null($request->input('pack_qty')),
            'pack_unit' => num_or_null($request->input('pack_qty')) !== null ? blank_to_null($request->input('pack_unit')) : null,
        ]);

        foreach ((array) $request->input('attr', []) as $attributeId => $value) {
            $value = is_array($value) ? implode(', ', $value) : trim((string) $value);
            if ($value === '') {
                continue;
            }
            DB::table('product_attribute_values')->insert([
                'product_id' => $id,
                'attribute_id' => (int) $attributeId,
                'value' => $value,
            ]);
        }

        $primary = (int) $request->input('primary_category');
        foreach ((array) $request->input('categories', []) as $catId) {
            DB::table('product_category_map')->insert([
                'product_id' => $id,
                'category_id' => (int) $catId,
                'is_primary' => ((int) $catId === $primary) ? 1 : 0,
            ]);
        }

        foreach ($this->splitTags($request->input('tags', '')) as $tagName) {
            $slug = $this->slugify($tagName);
            $tagId = DB::table('tags')->where('slug', $slug)->value('id');
            if (!$tagId) {
                $tagId = DB::table('tags')->insertGetId(['name' => $tagName, 'slug' => $slug]);
            }
            DB::table('product_tags')->insert(['product_id' => $id, 'tag_id' => (int) $tagId]);
        }
    }

    /** @return array<string,mixed> */
    private function formData(): array
    {
        $type = DB::table('product_types')->first();
        $typeId = $type ? (int) (is_array($type) ? $type['id'] : $type->getAttribute('id')) : 0;

        return [
            'productType' => $type,
            'brands' => DB::select('SELECT id, name FROM brands ORDER BY name'),
            'categories' => $this->categoryOptions(),
            'units' => DB::select('SELECT symbol, name, kind FROM units ORDER BY sort, name'),
            'optionGroups' => $this->optionGroups(),
            'attributes' => $this->attributeFields($typeId),
        ];
    }

    /**
     * Active attributes for the product type, each with its predefined values
     * ("value" = text stored on the product, e.g. "600 × 1200 mm").
     *
     * @return list<array<string,mixed>>
     */
    private function attributeFields(int $typeId): array
    {
        $attrs = DB::select(
            "SELECT a.id, a.code, a.name, a.type, a.unit, a.display, a.description
             FROM attributes a
             JOIN product_type_attributes pta ON pta.attribute_id = a.id
             WHERE pta.product_type_id = ? AND a.status <> 'archived'
             ORDER BY pta.sort, a.sort",
            [$typeId]
        );
        $values = [];
        foreach (DB::select('SELECT attribute_id, label, swatch, unit FROM attribute_values ORDER BY sort, id') as $v) {
            $values[(int) $v['attribute_id']][] = [
                'value' => trim($v['label'] . ($v['unit'] ? ' ' . $v['unit'] : '')),
                'swatch' => $v['swatch'],
            ];
        }
        foreach ($attrs as &$a) {
            $a['values'] = $values[(int) $a['id']] ?? [];
        }

        return $attrs;
    }

    /**
     * Categories ordered as a tree: each top-level followed by its sub-categories (depth 1).
     *
     * @return list<array<string,mixed>>
     */
    private function categoryOptions(): array
    {
        $rows = DB::select('SELECT id, name, parent_id FROM product_categories ORDER BY sort, name');
        $kids = [];
        foreach ($rows as $r) {
            if ($r['parent_id']) {
                $kids[(int) $r['parent_id']][] = $r + ['depth' => 1];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            if (!$r['parent_id']) {
                $out[] = $r + ['depth' => 0];
                foreach ($kids[(int) $r['id']] ?? [] as $k) {
                    $out[] = $k;
                }
            }
        }

        return $out;
    }

    /** @return list<array{id:int,name:string,values:list<array{id:int,value:string}>}> */
    private function optionGroups(): array
    {
        $groups = [];
        foreach (DB::select('SELECT id, name FROM option_groups ORDER BY sort, name') as $g) {
            $groups[(int) $g['id']] = ['id' => (int) $g['id'], 'name' => $g['name'], 'values' => []];
        }
        foreach (DB::select('SELECT id, option_group_id, value FROM option_values ORDER BY sort, id') as $v) {
            if (isset($groups[(int) $v['option_group_id']])) {
                $groups[(int) $v['option_group_id']]['values'][] = ['id' => (int) $v['id'], 'value' => $v['value']];
            }
        }

        return array_values($groups);
    }

    /** @return list<array<string,mixed>> variant rows in the same shape the form posts */
    private function loadVariants(int $pid): array
    {
        $out = [];
        foreach (DB::select('SELECT * FROM product_variants WHERE product_id = ? ORDER BY sort, id', [$pid]) as $v) {
            $ids = array_map(static fn($r) => (int) $r['option_value_id'], DB::select('SELECT option_value_id FROM product_variant_values WHERE variant_id = ?', [(int) $v['id']]));
            $out[] = [
                'id' => (int) $v['id'], 'sku' => $v['sku'], 'title' => $v['title'], 'values' => implode(',', $ids),
                'price' => $v['price'], 'compare_at_price' => $v['compare_at_price'], 'unit' => $v['unit'] ?? '',
                'status' => $v['status'], 'is_default' => (int) $v['is_default'],
            ];
        }

        return $out;
    }

    /**
     * Normalise + validate posted variants before anything is written.
     * Returns [] when "has variants" is off (which removes existing variants on save).
     *
     * @return list<array<string,mixed>>
     */
    private function validatedVariants(Request $request, int $productId): array
    {
        if (!$request->input('has_variants')) {
            return [];
        }
        $default = (string) $request->input('default_variant', '');
        $rows = [];
        $errors = [];
        $seen = [];
        foreach ((array) $request->input('variants', []) as $key => $v) {
            if (!is_array($v)) {
                continue;
            }
            $sku = trim((string) ($v['sku'] ?? ''));
            $title = trim((string) ($v['title'] ?? ''));
            $values = array_values(array_filter(array_map('intval', explode(',', (string) ($v['values'] ?? '')))));
            if ($title === '' || !$values) {
                continue;
            }
            if ($sku === '') {
                $errors['variants'][] = 'Variant "' . $title . '" needs a SKU.';
                continue;
            }
            if (isset($seen[strtolower($sku)])) {
                $errors['variants'][] = 'Variant SKU "' . $sku . '" is used twice.';
                continue;
            }
            $seen[strtolower($sku)] = true;
            $taken = DB::select('SELECT product_id FROM product_variants WHERE LOWER(sku) = ?', [mb_strtolower($sku)]);
            if ($taken && (int) $taken[0]['product_id'] !== $productId) {
                $errors['variants'][] = 'Variant SKU "' . $sku . '" already belongs to another product.';
                continue;
            }
            $status = (string) ($v['status'] ?? 'active');
            $rows[] = [
                'id' => (int) ($v['id'] ?? 0),
                'sku' => $sku,
                'title' => $title,
                'values' => $values,
                'price' => num_or_null($v['price'] ?? null),
                'compare_at_price' => num_or_null($v['compare_at_price'] ?? null),
                'unit' => trim((string) ($v['unit'] ?? '')) ?: null,
                'status' => isset(VariantController::STATUSES[$status]) ? $status : 'active',
                'is_default' => (string) $key === $default ? 1 : 0,
            ];
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        if ($rows && !array_filter($rows, static fn($r) => $r['is_default'])) {
            $rows[0]['is_default'] = 1;
        }

        return $rows;
    }

    /** Upsert variants by id; variants of this product not in $rows are removed. */
    private function saveVariants(int $pid, array $rows): void
    {
        $existing = array_map(static fn($r) => (int) $r['id'], DB::select('SELECT id FROM product_variants WHERE product_id = ?', [$pid]));
        $now = date('Y-m-d H:i:s');
        // Free SKUs of variants that will be removed first, so a re-generated row can reuse them.
        $postedIds = array_filter(array_map(static fn($r) => $r['id'], $rows));
        foreach (array_diff($existing, $postedIds) as $gone) {
            DB::table('media_links')->where('owner_type', 'variant')->where('owner_id', $gone)->delete();
            DB::table('product_variant_values')->where('variant_id', $gone)->delete();
            DB::table('product_variants')->where('id', $gone)->delete();
        }
        foreach ($rows as $sort => $r) {
            $data = [
                'sku' => $r['sku'], 'title' => $r['title'], 'price' => $r['price'], 'compare_at_price' => $r['compare_at_price'],
                'unit' => $r['unit'], 'status' => $r['status'], 'is_default' => $r['is_default'], 'sort' => $sort, 'updated_at' => $now,
            ];
            if ($r['id'] && in_array($r['id'], $existing, true)) {
                $vid = $r['id'];
                DB::table('product_variants')->where('id', $vid)->update($data);
                DB::table('product_variant_values')->where('variant_id', $vid)->delete();
            } else {
                $vid = (int) DB::table('product_variants')->insertGetId($data + ['product_id' => $pid, 'created_at' => $now]);
            }
            foreach ($r['values'] as $ovid) {
                DB::table('product_variant_values')->insert(['variant_id' => $vid, 'option_value_id' => $ovid]);
            }
        }
    }

    private function deleteVariants(int $pid): void
    {
        foreach (DB::select('SELECT id FROM product_variants WHERE product_id = ?', [$pid]) as $v) {
            DB::table('product_variant_values')->where('variant_id', (int) $v['id'])->delete();
            DB::table('media_links')->where('owner_type', 'variant')->where('owner_id', (int) $v['id'])->delete();
        }
        DB::table('product_variants')->where('product_id', $pid)->delete();
    }

    /**
     * Wizard step 2. The shared uploader puts files in the library first and posts their ids
     * (media_ids[] in display order, cover_media_id). Plain file inputs still work without JS.
     */
    private function storeMedia(Request $request, int $productId): void
    {
        $errors = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('media_ids', [])))));
        foreach (['cover' => 'cover', 'gallery1' => 'gallery', 'gallery2' => 'gallery', 'gallery3' => 'gallery', 'video' => 'video'] as $field => $role) {
            $file = $request->file($field);
            if (!$file || $file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            try {
                $m = Media::storeUpload($file, ['kinds' => $role === 'video' ? ['video'] : ['image']]);
                Media::attach('product', $productId, (int) $m['id'], $role === 'cover' ? 'gallery' : $role);
                if ($role === 'cover') {
                    Media::setCover('product', $productId, (int) $m['id']);
                }
            } catch (MediaException $e) {
                $errors[] = $e->getMessage();
            }
        }
        $sort = (int) (DB::selectOne("SELECT MAX(sort) AS s FROM media_links WHERE owner_type = 'product' AND owner_id = ?", [$productId])['s'] ?? 0);
        foreach ($ids as $mid) {
            $m = Media::find($mid);
            if ($m === null || $m['disk'] !== 'public' || !in_array($m['kind'], ['image', 'video'], true)) {
                continue;
            }
            Media::attach('product', $productId, $mid, $m['kind'] === 'video' ? 'video' : 'gallery', ++$sort);
        }
        // Cover radio can point at a new file or one already attached (setCover checks the link).
        $cover = (int) $request->input('cover_media_id', 0);
        $currentCover = (int) (DB::table('media_links')->where('owner_type', 'product')->where('owner_id', $productId)->where('role', 'cover')->value('media_id') ?? 0);
        if ($cover && $cover !== $currentCover) {
            try {
                Media::setCover('product', $productId, $cover);
            } catch (MediaException $e) {
                $errors[] = $e->getMessage();
            }
        }
        Media::ensureCover('product', $productId);
        if ($errors) {
            session()->flash('error', implode(' ', $errors));
        }
    }

    /** @return list<string> */
    private function splitTags(string $raw): array
    {
        $tags = array_filter(array_map('trim', explode(',', $raw)));

        return array_values(array_unique($tags));
    }

    private function uniqueSlug(string $source, int $ignoreId = 0): string
    {
        $base = $this->slugify($source) ?: 'product';
        $slug = $base;
        $i = 2;
        while (DB::table('products')->whereRaw('LOWER(slug) = ?', [$slug])->where('id', '!=', $ignoreId)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim($value, '-');
    }
}
