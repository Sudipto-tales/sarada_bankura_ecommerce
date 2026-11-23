<?php

namespace App\Controllers\Admin;

use App\Models\ProductCategory;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

class CatalogController
{
    public const UNIT_KINDS = ['area', 'length', 'count', 'pack', 'weight'];

    public function categories(): Response
    {
        $rows = DB::select(
            'SELECT c.*,
                    (SELECT COUNT(DISTINCT m.product_id) FROM product_category_map m
                      WHERE m.category_id = c.id OR m.category_id IN (SELECT s.id FROM product_categories s WHERE s.parent_id = c.id)) AS product_count
             FROM product_categories c ORDER BY c.sort, c.name'
        );

        $top = [];
        $children = [];
        foreach ($rows as $r) {
            if ($r['parent_id']) {
                $children[(int) $r['parent_id']][] = $r;
            } else {
                $top[] = $r;
            }
        }

        return view('admin.catalog.categories', [
            'active' => 'categories',
            'top' => $top,
            'children' => $children,
            'stats' => [
                'total' => count($rows),
                'top' => count($top),
                'subs' => count($rows) - count($top),
                'featured' => count(array_filter($rows, static fn($r) => (int) $r['is_featured'] === 1)),
                'visible' => count(array_filter($rows, static fn($r) => (int) $r['is_visible'] === 1)),
            ],
            'success' => session()->get('success'),
            'error' => session()->get('error'),
        ]);
    }

    public function storeCategory(Request $request): Response
    {
        $name = trim((string) $request->input('name'));
        if ($name !== '') {
            $data = $this->categoryData($request);
            $data['slug'] = self::slug('product_categories', $name);
            ProductCategory::create($data);
            session()->flash('success', ($data['parent_id'] ? 'Sub-category "' : 'Category "') . $name . '" added.');
        }

        return redirect(route('admin.categories'));
    }

    public function updateCategory(Request $request, string $id): Response
    {
        $cid = (int) $id;
        $name = trim((string) $request->input('name'));
        if ($name !== '' && DB::table('product_categories')->where('id', $cid)->exists()) {
            $data = $this->categoryData($request);
            // A category cannot be its own parent, and a category with children stays top-level (2 levels max).
            if ($data['parent_id'] === $cid || DB::table('product_categories')->where('parent_id', $cid)->exists()) {
                $data['parent_id'] = null;
            }
            DB::table('product_categories')->where('id', $cid)->update($data + ['updated_at' => date('Y-m-d H:i:s')]);
            session()->flash('success', 'Category "' . $name . '" updated.');
        }

        return redirect(route('admin.categories'));
    }

    public function deleteCategory(Request $request, string $id): Response
    {
        $cid = (int) $id;
        $name = DB::table('product_categories')->where('id', $cid)->value('name');
        if ($name === null) {
            return redirect(route('admin.categories'));
        }
        if (DB::table('product_categories')->where('parent_id', $cid)->exists()) {
            session()->flash('error', '"' . $name . '" has sub-categories. Move or delete them first.');

            return redirect(route('admin.categories'));
        }
        DB::table('product_category_map')->where('category_id', $cid)->delete();
        DB::table('product_categories')->where('id', $cid)->delete();
        session()->flash('success', 'Category "' . $name . '" deleted.');

        return redirect(route('admin.categories'));
    }

    /** @return array<string,mixed> */
    private function categoryData(Request $request): array
    {
        $parent = (int) $request->input('parent_id');
        // Only top-level categories may be parents (keeps the tree at 2 levels).
        if ($parent && DB::table('product_categories')->where('id', $parent)->value('parent_id')) {
            $parent = 0;
        }

        return [
            'parent_id' => $parent ?: null,
            'name' => trim((string) $request->input('name')),
            'description' => trim((string) $request->input('description')) ?: null,
            'is_visible' => $request->input('is_visible', '1') === '1' ? 1 : 0,
            'is_featured' => $request->input('is_featured') === '1' ? 1 : 0,
            'sort' => (int) $request->input('sort', 0),
        ];
    }

    public function units(): Response
    {
        $rows = DB::select(
            "SELECT u.*,
                    (SELECT COUNT(*) FROM product_pricing p WHERE p.unit = u.symbol OR p.pack_unit = u.symbol) AS pricing_count,
                    (SELECT COUNT(*) FROM product_variants v WHERE v.unit = u.symbol) AS variant_count,
                    (SELECT COUNT(*) FROM attributes a WHERE a.unit = u.symbol) AS attribute_count
             FROM units u ORDER BY u.sort, u.name"
        );

        return view('admin.catalog.units', ['active' => 'units', 'rows' => $rows, 'success' => session()->get('success'), 'error' => session()->get('error')]);
    }

    public function storeUnit(Request $request): Response
    {
        $name = trim((string) $request->input('name'));
        $symbol = trim((string) $request->input('symbol'));
        $kind = (string) $request->input('kind', 'count');
        if (!in_array($kind, self::UNIT_KINDS, true)) {
            $kind = 'count';
        }
        if ($name !== '' && $symbol !== '') {
            if (DB::table('units')->whereRaw('LOWER(symbol) = ?', [mb_strtolower($symbol)])->exists()) {
                session()->flash('error', 'A unit with symbol "' . $symbol . '" already exists.');
            } else {
                $now = date('Y-m-d H:i:s');
                $sort = (int) DB::table('units')->count();
                DB::table('units')->insert(['name' => $name, 'symbol' => $symbol, 'kind' => $kind, 'sort' => $sort, 'created_at' => $now, 'updated_at' => $now]);
                session()->flash('success', 'Unit "' . $name . ' (' . $symbol . ')" added.');
            }
        }

        return redirect(route('admin.units'));
    }

    public function deleteUnit(Request $request, string $id): Response
    {
        $unit = DB::table('units')->where('id', (int) $id)->first();
        if ($unit !== null) {
            $sym = $unit['symbol'];
            $used = DB::table('product_pricing')->where('unit', $sym)->exists()
                || DB::table('product_pricing')->where('pack_unit', $sym)->exists()
                || DB::table('product_variants')->where('unit', $sym)->exists()
                || DB::table('attributes')->where('unit', $sym)->exists();
            if ($used) {
                session()->flash('error', '"' . $sym . '" is in use by products, variants or attributes and cannot be deleted.');
            } else {
                DB::table('units')->where('id', (int) $id)->delete();
                session()->flash('success', 'Unit "' . $sym . '" deleted.');
            }
        }

        return redirect(route('admin.units'));
    }

    /** Unique slug for $table based on $source ("Wall Cladding" → wall-cladding, wall-cladding-2…). */
    public static function slug(string $table, string $source): string
    {
        $base = self::slugify($source) ?: 'item';
        $slug = $base;
        $i = 2;
        while (DB::table($table)->whereRaw('LOWER(slug) = ?', [$slug])->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    public static function slugify(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($value))) ?? '', '-');
    }
}
