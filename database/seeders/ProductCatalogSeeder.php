<?php

use App\Models\Attribute;
use App\Models\Brand;
use App\Models\ProductCategory;
use App\Models\ProductType;
use Vayu\Database\DB;
use Vayu\Database\Seeder;

return new class extends Seeder {
    public function run(): void
    {
        if (ProductType::query()->count() > 0) {
            echo "Catalog already seeded; skipping.\n";
            return;
        }

        $type = ProductType::create(['name' => 'Marble & Stone', 'slug' => 'marble-stone']);

        $attributes = [
            ['origin', 'Origin', 'select', null],
            ['colour', 'Colour', 'select', null],
            ['pattern', 'Pattern / Vein', 'text', null],
            ['finish', 'Finish', 'select', null],
            ['thickness', 'Thickness', 'number', 'mm'],
            ['application', 'Application', 'multiselect', null],
            ['grade', 'Grade', 'select', null],
            ['slab_size', 'Typical Slab Size', 'text', 'mm'],
        ];

        $sort = 0;
        foreach ($attributes as [$code, $name, $atype, $unit]) {
            $attr = Attribute::create(['code' => $code, 'name' => $name, 'type' => $atype, 'unit' => $unit]);
            DB::table('product_type_attributes')->insert([
                'product_type_id' => $type->getKey(),
                'attribute_id' => $attr->getKey(),
                'is_required' => in_array($code, ['origin', 'finish'], true) ? 1 : 0,
                'sort' => $sort++,
            ]);
        }

        foreach (['Flooring', 'Wall Cladding', 'Countertops', 'Imported', 'Indian'] as $cat) {
            ProductCategory::create(['parent_id' => null, 'name' => $cat, 'slug' => strtolower(str_replace(' ', '-', $cat))]);
        }

        foreach (['Maa Sarada Studio', 'Imported Collection', 'Rajasthan Quarry'] as $brand) {
            Brand::create(['name' => $brand, 'slug' => strtolower(str_replace(' ', '-', $brand))]);
        }

        echo "Seeded: 1 product type, 8 attributes, 5 categories, 3 brands.\n";
    }
};
