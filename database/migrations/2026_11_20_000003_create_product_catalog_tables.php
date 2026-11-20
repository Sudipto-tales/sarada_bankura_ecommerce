<?php

use Vayu\Database\Migrations\Migration;
use Vayu\Database\Schema\Blueprint;
use Vayu\Database\Schema\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_types', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->timestamps();
        });

        Schema::create('brands', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->timestamps();
        });

        Schema::create('product_categories', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('parent_id')->nullable()->index();
            $t->string('name');
            $t->string('slug')->unique();
            $t->timestamps();
        });

        Schema::create('products', function (Blueprint $t): void {
            $t->id();
            $t->string('external_product_id', 64)->nullable()->index();
            $t->string('status', 32)->default('draft')->index();
            $t->string('sku', 64)->unique();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('short_description', 500)->nullable();
            $t->text('description')->nullable();
            $t->bigInteger('product_type_id')->index();
            $t->bigInteger('brand_id')->nullable()->index();
            $t->boolean('is_enquiry_only')->default(false);
            $t->boolean('show_price')->default(true);
            $t->boolean('is_featured')->default(false)->index();
            $t->boolean('is_bestseller')->default(false);
            $t->string('meta_title')->nullable();
            $t->string('meta_description', 500)->nullable();
            $t->timestamps();
        });

        Schema::create('product_category_map', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('product_id')->index();
            $t->bigInteger('category_id')->index();
            $t->boolean('is_primary')->default(false);
        });

        Schema::create('attributes', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 64)->unique();
            $t->string('name');
            $t->string('type', 32)->default('text'); // text|number|select|multiselect
            $t->string('unit', 32)->nullable();
            $t->timestamps();
        });

        Schema::create('product_type_attributes', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('product_type_id')->index();
            $t->bigInteger('attribute_id')->index();
            $t->boolean('is_required')->default(false);
            $t->integer('sort')->default(0);
        });

        Schema::create('product_attribute_values', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('product_id')->index();
            $t->bigInteger('attribute_id')->index();
            $t->text('value');
        });

        Schema::create('tags', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
        });

        Schema::create('product_tags', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('product_id')->index();
            $t->bigInteger('tag_id')->index();
        });

        Schema::create('product_media', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('product_id')->index();
            $t->string('path');
            $t->string('type', 16)->default('image'); // image|video
            $t->boolean('is_cover')->default(false);
            $t->integer('sort')->default(0);
        });

        Schema::create('product_pricing', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('product_id')->index();
            $t->decimal('price', 12, 2)->nullable();
            $t->decimal('compare_at_price', 12, 2)->nullable();
            $t->decimal('cost_price', 12, 2)->nullable();
            $t->string('currency', 8)->default('INR');
            $t->string('unit', 32)->nullable(); // selling unit: sqft, slab, piece
        });
    }

    public function down(): void
    {
        foreach ([
            'product_pricing', 'product_media', 'product_tags', 'tags',
            'product_attribute_values', 'product_type_attributes', 'attributes',
            'product_category_map', 'products', 'product_categories', 'brands', 'product_types',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
