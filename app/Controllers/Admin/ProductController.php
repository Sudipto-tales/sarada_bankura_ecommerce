<?php

namespace App\Controllers\Admin;

use App\Models\Product;
use App\Requests\StoreProductRequest;
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
                    (SELECT path FROM product_media m WHERE m.product_id = p.id AND m.is_cover = 1 LIMIT 1) AS cover,
                    (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id) AS variant_count
             FROM products p
             LEFT JOIN product_types pt ON pt.id = p.product_type_id
             LEFT JOIN brands b ON b.id = p.brand_id
             LEFT JOIN product_pricing pr ON pr.product_id = p.id';
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
            $this->deleteVariants($pid);
            foreach (['product_pricing', 'product_attribute_values', 'product_category_map', 'product_tags', 'product_media', 'product_reviews'] as $t) {
                DB::table($t)->where('product_id', $pid)->delete();
            }
            DB::table('products')->where('id', $pid)->delete();
            session()->flash('success', 'Product "' . $name . '" deleted.');
        }

        return redirect(route('admin.products.index'));
    }

    public function create(): Response
    {
        return view('admin.products.create', $this->formData());
    }

    public function store(StoreProductRequest $request): Response
    {
        $variants = $this->validatedVariants($request, 0);
        $product = Product::create($this->productAttributes($request));
        $id = (int) $product->getKey();

        $this->saveRelations($id, $request);
        $this->saveVariants($id, $variants);
        $this->storeMedia($request, $id);

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
            'media' => DB::select('SELECT id, path, type, is_cover FROM product_media WHERE product_id = ? ORDER BY sort', [$pid]),
            'variants' => $this->loadVariants($pid),
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

        session()->flash('success', 'Product "' . $request->input('name') . '" updated.');

        return redirect(route('admin.products.index'));
    }

    public function deleteMedia(Request $request, string $id, string $mediaId): Response
    {
        $pid = (int) $id;
        $row = DB::table('product_media')->where('id', (int) $mediaId)->where('product_id', $pid)->first();
        if ($row !== null) {
            $full = app()->publicPath($row['path']);
            if (is_file($full)) {
                @unlink($full);
            }
            DB::table('product_media')->where('id', (int) $mediaId)->delete();
        }

        return redirect(route('admin.products.edit', ['id' => $pid]));
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

    private function saveRelations(int $id, Request $request, bool $replace = false): void
    {
        if ($replace) {
            foreach (['product_pricing', 'product_attribute_values', 'product_category_map', 'product_tags'] as $t) {
                DB::table($t)->where('product_id', $id)->delete();
            }
        }

        DB::table('product_pricing')->insert([
            'product_id' => $id,
            'price' => $request->input('price') !== '' ? $request->input('price') : null,
            'compare_at_price' => $request->input('compare_at_price') !== '' ? $request->input('compare_at_price') : null,
            'cost_price' => $request->input('cost_price') !== '' ? $request->input('cost_price') : null,
            'currency' => $request->input('currency', 'INR'),
            'unit' => $request->input('selling_unit') ?: null,
            'pack_qty' => is_numeric($request->input('pack_qty')) ? $request->input('pack_qty') : null,
            'pack_unit' => is_numeric($request->input('pack_qty')) ? ($request->input('pack_unit') ?: null) : null,
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
        $num = static fn($v) => ($v === null || $v === '' || !is_numeric($v)) ? null : $v;
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
            $taken = DB::select('SELECT product_id FROM product_variants WHERE sku = ?', [$sku]);
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
                'price' => $num($v['price'] ?? null),
                'compare_at_price' => $num($v['compare_at_price'] ?? null),
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
        }
        DB::table('product_variants')->where('product_id', $pid)->delete();
    }

    private function storeMedia(Request $request, int $productId): void
    {
        $dir = app()->publicPath('assets/uploads/products');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $slots = [['cover', 'image', 1], ['gallery1', 'image', 0], ['gallery2', 'image', 0], ['gallery3', 'image', 0], ['video', 'video', 0]];
        $sort = 0;
        foreach ($slots as [$field, $type, $isCover]) {
            $file = $request->file($field);
            if (!$file || !$file->isValid()) {
                continue;
            }
            $ext = preg_replace('/[^a-z0-9]/i', '', $file->getClientExtension()) ?: 'bin';
            $name = bin2hex(random_bytes(8)) . '.' . strtolower($ext);
            if (!@move_uploaded_file($file->getPathname(), $dir . '/' . $name)) {
                continue;
            }
            DB::table('product_media')->insert([
                'product_id' => $productId,
                'path' => 'assets/uploads/products/' . $name,
                'type' => $type,
                'is_cover' => $isCover,
                'sort' => $sort++,
            ]);
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
        while (DB::table('products')->where('slug', $slug)->where('id', '!=', $ignoreId)->exists()) {
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
