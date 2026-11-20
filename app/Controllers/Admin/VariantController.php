<?php

namespace App\Controllers\Admin;

use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

/** Variants & options: reusable option groups and the sellable variant matrix. */
class VariantController
{
    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived'];

    public function index(): Response
    {
        $productTotal = (int) DB::table('products')->count();

        $groups = DB::select(
            'SELECT g.id, g.name, g.slug,
                    (SELECT COUNT(DISTINCT v.product_id)
                       FROM product_variants v
                       JOIN product_variant_values pvv ON pvv.variant_id = v.id
                       JOIN option_values ov ON ov.id = pvv.option_value_id
                      WHERE ov.option_group_id = g.id) AS product_count
             FROM option_groups g ORDER BY g.sort, g.name'
        );
        $values = [];
        foreach (DB::select(
            'SELECT ov.id, ov.option_group_id, ov.value,
                    (SELECT COUNT(*) FROM product_variant_values pvv WHERE pvv.option_value_id = ov.id) AS used
             FROM option_values ov ORDER BY ov.sort, ov.id'
        ) as $v) {
            $values[(int) $v['option_group_id']][] = $v;
        }

        $variants = DB::select(
            'SELECT v.*, p.name AS product_name, p.sku AS product_sku, p.id AS pid, b.name AS brand_name,
                    pr.unit AS product_unit, pr.currency,
                    (SELECT path FROM product_media m WHERE m.product_id = p.id AND m.is_cover = 1 LIMIT 1) AS cover
             FROM product_variants v
             JOIN products p ON p.id = v.product_id
             LEFT JOIN brands b ON b.id = p.brand_id
             LEFT JOIN product_pricing pr ON pr.product_id = p.id
             ORDER BY p.name, v.sort, v.id'
        );

        $variantProducts = count(array_unique(array_map(static fn($v) => (int) $v['product_id'], $variants)));

        return view('admin.variants.index', [
            'active' => 'variants',
            'groups' => $groups,
            'values' => $values,
            'variants' => $variants,
            'units' => DB::select('SELECT symbol, name, kind FROM units ORDER BY sort, name'),
            'stats' => [
                'products' => $variantProducts,
                'catalog' => $productTotal,
                'skus' => count($variants),
                'active' => count(array_filter($variants, static fn($v) => $v['status'] === 'active')),
                'groups' => count($groups),
            ],
            'productTotal' => $productTotal,
            'success' => session()->get('success'),
            'error' => session()->get('error'),
        ]);
    }

    /** Create a group, or add new values to an existing group with the same name. */
    public function storeGroup(Request $request): Response
    {
        $name = trim((string) $request->input('name'));
        $vals = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $request->input('values', ''))))));
        if ($name === '' || !$vals) {
            session()->flash('error', 'Give the option group a name and at least one value.');

            return redirect(route('admin.variants'));
        }

        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-') ?: 'option';
        $now = date('Y-m-d H:i:s');
        $gid = (int) DB::table('option_groups')->where('slug', $slug)->value('id');
        $created = $gid === 0;
        if ($created) {
            $gid = (int) DB::table('option_groups')->insertGetId([
                'name' => $name, 'slug' => $slug, 'sort' => (int) DB::table('option_groups')->count(),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $existing = array_map('strtolower', array_map(static fn($r) => $r['value'], DB::select('SELECT value FROM option_values WHERE option_group_id = ?', [$gid])));
        $sort = count($existing);
        $added = 0;
        foreach ($vals as $v) {
            if (in_array(strtolower($v), $existing, true)) {
                continue;
            }
            DB::table('option_values')->insert(['option_group_id' => $gid, 'value' => $v, 'sort' => $sort++]);
            $added++;
        }

        session()->flash('success', $created
            ? 'Option group "' . $name . '" created with ' . $added . ' value' . ($added === 1 ? '' : 's') . '.'
            : $added . ' new value' . ($added === 1 ? '' : 's') . ' added to "' . $name . '".');

        return redirect(route('admin.variants'));
    }

    public function deleteGroup(Request $request, string $id): Response
    {
        $gid = (int) $id;
        $name = DB::table('option_groups')->where('id', $gid)->value('name');
        if ($name !== null) {
            $used = DB::select(
                'SELECT COUNT(*) AS n FROM product_variant_values pvv JOIN option_values ov ON ov.id = pvv.option_value_id WHERE ov.option_group_id = ?',
                [$gid]
            );
            if ((int) ($used[0]['n'] ?? 0) > 0) {
                session()->flash('error', '"' . $name . '" is used by variants. Remove those variants first.');
            } else {
                DB::table('option_values')->where('option_group_id', $gid)->delete();
                DB::table('option_groups')->where('id', $gid)->delete();
                session()->flash('success', 'Option group "' . $name . '" deleted.');
            }
        }

        return redirect(route('admin.variants'));
    }

    public function deleteValue(Request $request, string $id): Response
    {
        $vid = (int) $id;
        $value = DB::table('option_values')->where('id', $vid)->value('value');
        if ($value !== null) {
            if (DB::table('product_variant_values')->where('option_value_id', $vid)->exists()) {
                session()->flash('error', '"' . $value . '" is used by variants and cannot be removed.');
            } else {
                DB::table('option_values')->where('id', $vid)->delete();
                session()->flash('success', 'Value "' . $value . '" removed.');
            }
        }

        return redirect(route('admin.variants'));
    }

    public function update(Request $request, string $id): Response
    {
        $vid = (int) $id;
        $variant = DB::table('product_variants')->where('id', $vid)->first();
        if ($variant === null) {
            return redirect(route('admin.variants'));
        }

        $sku = trim((string) $request->input('sku'));
        if ($sku === '') {
            session()->flash('error', 'Variant SKU is required.');

            return redirect(route('admin.variants'));
        }
        if (DB::table('product_variants')->where('sku', $sku)->where('id', '!=', $vid)->exists()) {
            session()->flash('error', 'SKU "' . $sku . '" is already used by another variant.');

            return redirect(route('admin.variants'));
        }

        $status = (string) $request->input('status', 'active');
        $num = static fn($v) => ($v === null || $v === '' || !is_numeric($v)) ? null : $v;
        DB::table('product_variants')->where('id', $vid)->update([
            'sku' => $sku,
            'price' => $num($request->input('price')),
            'compare_at_price' => $num($request->input('compare_at_price')),
            'unit' => $request->input('unit') ?: null,
            'status' => isset(self::STATUSES[$status]) ? $status : 'active',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        session()->flash('success', 'Variant "' . $sku . '" updated.');

        return redirect(route('admin.variants'));
    }

    public function destroy(Request $request, string $id): Response
    {
        $vid = (int) $id;
        $sku = DB::table('product_variants')->where('id', $vid)->value('sku');
        if ($sku !== null) {
            DB::table('product_variant_values')->where('variant_id', $vid)->delete();
            DB::table('product_variants')->where('id', $vid)->delete();
            session()->flash('success', 'Variant "' . $sku . '" deleted.');
        }

        return redirect(route('admin.variants'));
    }
}
