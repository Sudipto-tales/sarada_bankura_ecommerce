<?php

namespace App\Controllers\Admin;

use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

/**
 * Attribute library (Veylix "Attributes" page): reusable characteristics with predefined values.
 *
 * Display modes:
 *  - text    plain labels (Finish: Polished, Honed…)
 *  - swatch  label + hex colour (Colour: Statuario White #f3f1ec)
 *  - size    length × width + unit per value (Size: 600 × 1200 mm, 9 × 5 ft)
 *  - measure single number + unit per value (Thickness: 18 mm)
 */
class AttributeController
{
    public const DISPLAYS = ['text' => 'Text labels', 'swatch' => 'Colour swatch', 'size' => 'Size (L × W + unit)', 'measure' => 'Measurement (number + unit)'];
    public const USED_FOR = ['variants_filters' => 'Variants and filters', 'filters' => 'Filters only', 'specs' => 'Specifications only'];

    public function index(Request $request): Response
    {
        $attrs = DB::select(
            'SELECT a.*,
                    (SELECT COUNT(DISTINCT v.product_id) FROM product_attribute_values v WHERE v.attribute_id = a.id) AS product_count
             FROM attributes a ORDER BY a.sort, a.name'
        );
        $values = [];
        foreach (DB::select('SELECT * FROM attribute_values ORDER BY sort, id') as $v) {
            $values[(int) $v['attribute_id']][] = $v;
        }
        foreach ($attrs as &$a) {
            $a['values'] = $values[(int) $a['id']] ?? [];
        }
        unset($a);

        $active = array_filter($attrs, static fn($a) => $a['status'] !== 'archived');

        return view('admin.catalog.attributes', [
            'active' => 'attributes',
            'attrs' => $attrs,
            'units' => DB::select('SELECT symbol, name, kind FROM units ORDER BY sort, name'),
            'stats' => [
                'active' => count($active),
                'archived' => count($attrs) - count($active),
                'values' => array_sum(array_map(static fn($a) => count($a['values']), $attrs)),
                'variant' => count(array_filter($active, static fn($a) => $a['used_for'] === 'variants_filters')),
            ],
            'editId' => (int) $request->input('edit', 0),
            'success' => session()->get('success'),
            'error' => session()->get('error'),
        ]);
    }

    public function store(Request $request): Response
    {
        return $this->save($request, null);
    }

    public function update(Request $request, string $id): Response
    {
        return $this->save($request, (int) $id);
    }

    public function destroy(Request $request, string $id): Response
    {
        $aid = (int) $id;
        $name = DB::table('attributes')->where('id', $aid)->value('name');
        if ($name === null) {
            return redirect(route('admin.attributes'));
        }
        if (DB::table('product_attribute_values')->where('attribute_id', $aid)->exists()) {
            session()->flash('error', '"' . $name . '" is used on products. Archive it instead so existing product details stay intact.');

            return redirect(route('admin.attributes'));
        }
        DB::table('attribute_values')->where('attribute_id', $aid)->delete();
        DB::table('product_type_attributes')->where('attribute_id', $aid)->delete();
        DB::table('attributes')->where('id', $aid)->delete();
        session()->flash('success', 'Attribute "' . $name . '" deleted.');

        return redirect(route('admin.attributes'));
    }

    private function save(Request $request, ?int $id): Response
    {
        $name = trim((string) $request->input('name'));
        if ($name === '') {
            session()->flash('error', 'Give the attribute a name.');

            return redirect(route('admin.attributes'));
        }
        if ($id !== null && !DB::table('attributes')->where('id', $id)->exists()) {
            return redirect(route('admin.attributes'));
        }

        $display = (string) $request->input('display', 'text');
        $display = isset(self::DISPLAYS[$display]) ? $display : 'text';
        $usedFor = (string) $request->input('used_for', 'filters');
        $usedFor = isset(self::USED_FOR[$usedFor]) ? $usedFor : 'filters';
        $defaultUnit = $this->validUnit((string) $request->input('unit'));
        $hasUnits = in_array($display, ['size', 'measure'], true);

        $values = $this->collectValues((array) $request->input('values', []), $display, $hasUnits ? $defaultUnit : null);
        $multiple = $request->input('selection') === 'multiple';
        $type = $values ? ($multiple ? 'multiselect' : 'select') : ($display === 'measure' ? 'number' : 'text');

        $now = date('Y-m-d H:i:s');
        $data = [
            'name' => $name,
            'display' => $display,
            'used_for' => $usedFor,
            'type' => $type,
            'unit' => $hasUnits ? $defaultUnit : null,
            'status' => $request->input('status') === 'archived' ? 'archived' : 'active',
            'description' => trim((string) $request->input('description')) ?: null,
            'updated_at' => $now,
        ];

        if ($id === null) {
            $id = DB::table('attributes')->insertGetId($data + [
                'code' => $this->uniqueCode($name),
                'sort' => (int) DB::table('attributes')->max('sort') + 1,
                'created_at' => $now,
            ]);
        } else {
            DB::table('attributes')->where('id', $id)->update($data);
        }

        // Product values are stored as text, so replacing the predefined list never breaks products.
        DB::table('attribute_values')->where('attribute_id', $id)->delete();
        foreach ($values as $i => $v) {
            DB::table('attribute_values')->insert($v + ['attribute_id' => $id, 'sort' => $i]);
        }

        $typeId = (int) DB::table('product_types')->value('id');
        if ($typeId && !DB::table('product_type_attributes')->where('attribute_id', $id)->exists()) {
            DB::table('product_type_attributes')->insert([
                'product_type_id' => $typeId, 'attribute_id' => $id, 'is_required' => 0,
                'sort' => (int) DB::table('product_type_attributes')->max('sort') + 1,
            ]);
        }

        session()->flash('success', 'Attribute "' . $name . '" saved with ' . count($values) . ' value' . (count($values) === 1 ? '' : 's') . '.');

        return redirect(route('admin.attributes'));
    }

    /**
     * Normalise posted value rows. Size rows post a/b (length/width), measure rows post a,
     * swatch rows post label + swatch, text rows post label. Duplicates are dropped.
     *
     * @param array<mixed> $rows
     * @return list<array{label:string,swatch:?string,unit:?string}>
     */
    private function collectValues(array $rows, string $display, ?string $defaultUnit): array
    {
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            $unit = null;
            $swatch = null;

            if ($display === 'size') {
                $a = $this->num($row['a'] ?? '');
                $b = $this->num($row['b'] ?? '');
                if ($a !== '') {
                    $label = $b !== '' ? $a . ' × ' . $b : $a;
                }
            } elseif ($display === 'measure') {
                $a = $this->num($row['a'] ?? '');
                if ($a !== '') {
                    $label = $a;
                }
            }
            if ($label === '') {
                continue;
            }
            if ($display === 'size' || $display === 'measure') {
                $unit = $this->validUnit((string) ($row['unit'] ?? '')) ?? $defaultUnit;
            }
            if ($display === 'swatch') {
                $hex = strtolower(trim((string) ($row['swatch'] ?? '')));
                $swatch = preg_match('/^#[0-9a-f]{6}$/', $hex) ? $hex : '#d9cdb5';
            }

            $key = strtolower($label . '|' . $unit);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['label' => mb_substr($label, 0, 120), 'swatch' => $swatch, 'unit' => $unit];
        }

        return $out;
    }

    private function num(mixed $v): string
    {
        $v = trim(str_replace(',', '.', (string) $v));

        return is_numeric($v) ? rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.') : '';
    }

    private function validUnit(string $symbol): ?string
    {
        $symbol = trim($symbol);

        return $symbol !== '' && DB::table('units')->where('symbol', $symbol)->exists() ? $symbol : null;
    }

    private function uniqueCode(string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_') ?: 'attribute';
        $code = $base;
        $i = 2;
        while (DB::table('attributes')->where('code', $code)->exists()) {
            $code = $base . '_' . $i++;
        }

        return $code;
    }
}
