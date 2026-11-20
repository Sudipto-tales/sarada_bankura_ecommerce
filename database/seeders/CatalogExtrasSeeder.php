<?php

use Vayu\Database\DB;
use Vayu\Database\Seeder;

/** Units, option groups and sample sub-categories. Idempotent: each block skips if data exists. */
return new class extends Seeder {
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        if ((int) DB::table('units')->count() === 0) {
            $units = [
                ['Square foot', 'sq ft', 'area'], ['Square metre', 'sq m', 'area'],
                ['Running foot', 'running ft', 'length'], ['Millimetre', 'mm', 'length'],
                ['Centimetre', 'cm', 'length'], ['Inch', 'inch', 'length'], ['Foot', 'ft', 'length'],
                ['Piece', 'piece', 'count'], ['Slab', 'slab', 'count'],
                ['Box', 'box', 'pack'], ['Set', 'set', 'pack'],
                ['Kilogram', 'kg', 'weight'], ['Tonne', 'ton', 'weight'],
            ];
            foreach ($units as $i => [$name, $symbol, $kind]) {
                DB::table('units')->insert(['name' => $name, 'symbol' => $symbol, 'kind' => $kind, 'sort' => $i, 'created_at' => $now, 'updated_at' => $now]);
            }
            // Old free-text unit "sqft" → "sq ft".
            DB::table('product_pricing')->where('unit', 'sqft')->update(['unit' => 'sq ft']);
            DB::table('product_pricing')->where('unit', 'sqm')->update(['unit' => 'sq m']);
            echo "Seeded units.\n";
        }

        if ((int) DB::table('option_groups')->count() === 0) {
            $groups = [
                'Thickness' => ['16 mm', '18 mm', '20 mm', '30 mm'],
                'Finish' => ['Polished', 'Honed', 'Leather'],
                'Size' => ['600 × 600 mm', '800 × 800 mm', '800 × 1600 mm', 'Slab'],
                'Grade' => ['Commercial', 'Premium', 'Extra'],
            ];
            $gs = 0;
            foreach ($groups as $name => $values) {
                $gid = DB::table('option_groups')->insertGetId(['name' => $name, 'slug' => strtolower($name), 'sort' => $gs++, 'created_at' => $now, 'updated_at' => $now]);
                foreach ($values as $i => $v) {
                    DB::table('option_values')->insert(['option_group_id' => $gid, 'value' => $v, 'sort' => $i]);
                }
            }
            echo "Seeded option groups.\n";
        }

        if ((int) DB::table('product_categories')->whereNotNull('parent_id')->count() === 0) {
            $subs = [
                'flooring' => ['Living Room', 'Bathroom', 'Outdoor'],
                'wall-cladding' => ['Interior Walls', 'Exterior Facade'],
                'countertops' => ['Kitchen', 'Vanity Tops'],
            ];
            foreach ($subs as $parentSlug => $names) {
                $pid = DB::table('product_categories')->where('slug', $parentSlug)->value('id');
                if (!$pid) {
                    continue;
                }
                foreach ($names as $i => $n) {
                    $slug = $parentSlug . '-' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($n)), '-');
                    DB::table('product_categories')->insert(['parent_id' => (int) $pid, 'name' => $n, 'slug' => $slug, 'sort' => $i, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
            echo "Seeded sub-categories.\n";
        }
    }
};
