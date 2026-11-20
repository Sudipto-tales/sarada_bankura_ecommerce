<?php

use Vayu\Database\DB;
use Vayu\Database\Seeder;

/**
 * Marble & stone attribute library, brand profiles and governed tags.
 * Idempotent: attributes are matched by code, values only seeded when an attribute has none,
 * brand/tag rows only touched where they still carry defaults.
 */
return new class extends Seeder {
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');
        $typeId = (int) DB::table('product_types')->value('id');

        // code => [name, display, used_for, selection, unit, description, values]
        // values: text → 'Label'; swatch → ['Label', '#hex']; size → ['600 × 600', 'mm']; measure → ['18', 'mm']
        $library = [
            'stone_type' => ['Stone type', 'text', 'filters', 'select', null, 'Material family shoppers filter by first.',
                ['Marble', 'Granite', 'Onyx', 'Quartzite', 'Travertine', 'Sandstone', 'Slate', 'Limestone', 'Engineered quartz']],
            'colour' => ['Colour', 'swatch', 'variants_filters', 'select', null, 'Base tone of the stone, shown as swatches on the storefront.',
                [['Statuario White', '#f3f1ec'], ['Carrara Grey', '#d6d6d2'], ['Botticino Beige', '#e3d2b4'], ['Nero Black', '#22211f'],
                 ['Emperador Brown', '#6b4a33'], ['Verde Green', '#2f5d47'], ['Rosso Red', '#8d2f2a'], ['Onyx Honey', '#d6a25a'],
                 ['Makrana Pink', '#e8c2b6'], ['Ivory Cream', '#efe4cc']]],
            'slab_size' => ['Size', 'size', 'variants_filters', 'select', 'mm', 'Tile and slab dimensions (length × width). Each size carries its own unit.',
                [['600 × 600', 'mm'], ['800 × 800', 'mm'], ['600 × 1200', 'mm'], ['800 × 1600', 'mm'], ['1200 × 2400', 'mm'],
                 ['2 × 2', 'ft'], ['2 × 4', 'ft'], ['9 × 5', 'ft'], ['10 × 6', 'ft']]],
            'thickness' => ['Thickness', 'measure', 'variants_filters', 'select', 'mm', 'Slab / tile thickness.',
                [['10', 'mm'], ['16', 'mm'], ['18', 'mm'], ['20', 'mm'], ['25', 'mm'], ['30', 'mm']]],
            'finish' => ['Finish', 'text', 'variants_filters', 'select', null, 'Surface treatment.',
                ['Polished', 'Honed', 'Leather', 'Flamed', 'Brushed', 'Sandblasted', 'Tumbled', 'Antique']],
            'origin' => ['Origin', 'text', 'filters', 'select', null, 'Quarry region or country.',
                ['Makrana, Rajasthan', 'Rajnagar, Rajasthan', 'Ambaji, Gujarat', 'Udaipur, Rajasthan', 'Italy', 'Turkey', 'Spain', 'Greece', 'Vietnam', 'Iran']],
            'pattern' => ['Pattern / vein', 'text', 'filters', 'select', null, 'Visual movement of the stone.',
                ['Heavy veining', 'Light veining', 'Cloudy', 'Speckled', 'Solid', 'Book-matched', 'Crystalline']],
            'application' => ['Application', 'text', 'filters', 'multiselect', null, 'Where the stone can be installed.',
                ['Flooring', 'Wall cladding', 'Kitchen countertop', 'Vanity top', 'Staircase', 'Outdoor paving', 'Temple & murti', 'Table top']],
            'grade' => ['Grade', 'text', 'variants_filters', 'select', null, 'Quality grading for the lot.',
                ['Commercial', 'Standard', 'Premium', 'Exotic']],
            'edge_profile' => ['Edge profile', 'text', 'specs', 'select', null, 'Edge machining for countertops and steps.',
                ['Straight', 'Half bullnose', 'Full bullnose', 'Bevel', 'Ogee', 'Mitred']],
            'water_absorption' => ['Water absorption', 'measure', 'specs', 'select', '%', 'Technical spec — lower is better for wet areas.',
                [['0.1', '%'], ['0.2', '%'], ['0.5', '%']]],
        ];

        if (!DB::table('units')->where('symbol', '%')->exists()) {
            DB::table('units')->insert(['name' => 'Percent', 'symbol' => '%', 'kind' => 'count', 'sort' => 99, 'created_at' => $now, 'updated_at' => $now]);
        }

        $sort = 0;
        foreach ($library as $code => [$name, $display, $usedFor, $selection, $unit, $desc, $values]) {
            $id = DB::table('attributes')->where('code', $code)->value('id');
            $data = ['name' => $name, 'display' => $display, 'used_for' => $usedFor, 'type' => $selection,
                'unit' => $unit, 'description' => $desc, 'sort' => $sort++, 'updated_at' => $now];
            if ($id) {
                DB::table('attributes')->where('id', (int) $id)->update($data);
            } else {
                $id = DB::table('attributes')->insertGetId($data + ['code' => $code, 'status' => 'active', 'created_at' => $now]);
            }
            $id = (int) $id;
            if ($typeId && !DB::table('product_type_attributes')->where('attribute_id', $id)->exists()) {
                DB::table('product_type_attributes')->insert(['product_type_id' => $typeId, 'attribute_id' => $id, 'is_required' => 0, 'sort' => $sort]);
            }
            DB::table('product_type_attributes')->where('attribute_id', $id)->update(['sort' => $sort]);

            if (DB::table('attribute_values')->where('attribute_id', $id)->exists()) {
                continue;
            }
            foreach ($values as $i => $v) {
                $row = ['attribute_id' => $id, 'label' => is_array($v) ? $v[0] : $v, 'swatch' => null, 'unit' => null, 'sort' => $i];
                if ($display === 'swatch') {
                    $row['swatch'] = $v[1];
                } elseif (in_array($display, ['size', 'measure'], true)) {
                    $row['unit'] = $v[1];
                }
                DB::table('attribute_values')->insert($row);
            }
        }

        $brands = [
            'maa-sarada-studio' => ['India', 'signature', 'house', 1, 'Our own curated range, cut and finished at the Bankura yard.'],
            'imported-collection' => ['Italy', 'signature', 'importer', 1, 'Imported Italian, Turkish and Spanish marble lots.'],
            'rajasthan-quarry' => ['India', 'core', 'quarry', 0, 'Direct-from-quarry Makrana and Rajnagar marble.'],
        ];
        foreach ($brands as $slug => [$country, $tier, $btype, $featured, $desc]) {
            DB::table('brands')->where('slug', $slug)->whereNull('country')->update([
                'country' => $country, 'tier' => $tier, 'brand_type' => $btype, 'is_featured' => $featured, 'description' => $desc,
            ]);
        }

        $tags = [
            ['Premium', 'merchandising'], ['Imported', 'discovery'], ['Bestseller', 'merchandising'], ['New arrival', 'merchandising'],
            ['Book-matched', 'discovery'], ['Heat resistant', 'discovery'], ['Low maintenance', 'discovery'], ['Outdoor safe', 'discovery'],
            ['Vastu friendly', 'discovery'], ['Showroom only', 'internal'], ['Clearance lot', 'internal'], ['Fragile handling', 'internal'],
        ];
        foreach ($tags as [$name, $purpose]) {
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
            if (DB::table('tags')->where('slug', $slug)->exists()) {
                DB::table('tags')->where('slug', $slug)->update(['purpose' => $purpose]);
            } else {
                DB::table('tags')->insert(['name' => $name, 'slug' => $slug, 'purpose' => $purpose, 'status' => 'active']);
            }
        }

        echo "Seeded attribute library (" . count($library) . " attributes), brand profiles and tags.\n";
    }
};
