<?php

use Vayu\Database\Migrations\Migration;
use Vayu\Database\Schema\Blueprint;
use Vayu\Database\Schema\Schema;

/**
 * Units of measure, product variants (option groups → values → sellable combinations)
 * and sub-category support fields on product_categories.
 */
return new class extends Migration {
    public function up(): void
    {
        // Sub-categories already work through product_categories.parent_id; these are the
        // fields the category form needs (Veylix: description, visibility, featured).
        Schema::table('product_categories', function (Blueprint $t): void {
            $t->text('description')->nullable();
            $t->boolean('is_visible')->default(true);
            $t->boolean('is_featured')->default(false);
            $t->integer('sort')->default(0);
        });

        // Units: selling units (sq ft, box, slab…) and measurement units (mm, cm…).
        Schema::create('units', function (Blueprint $t): void {
            $t->id();
            $t->string('name');                         // Square foot
            $t->string('symbol', 32)->unique();         // sq ft — stored on pricing/variants/attributes
            $t->string('kind', 16)->default('count');   // area|length|count|pack|weight
            $t->integer('sort')->default(0);
            $t->timestamps();
        });

        // Pack conversion: "1 box contains 10 sq ft".
        Schema::table('product_pricing', function (Blueprint $t): void {
            $t->decimal('pack_qty', 12, 3)->nullable();
            $t->string('pack_unit', 32)->nullable();
        });

        // Reusable option groups (Thickness, Finish, Size) and their values.
        Schema::create('option_groups', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->integer('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('option_values', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('option_group_id')->index();
            $t->string('value');
            $t->integer('sort')->default(0);
        });

        // A sellable combination of a product (e.g. "18 mm / Polished").
        Schema::create('product_variants', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('product_id')->index();
            $t->string('sku', 64)->unique();
            $t->string('title');                        // combination label
            $t->decimal('price', 12, 2)->nullable();
            $t->decimal('compare_at_price', 12, 2)->nullable();
            $t->string('unit', 32)->nullable();         // overrides product selling unit
            $t->string('status', 16)->default('active'); // active|inactive|archived
            $t->boolean('is_default')->default(false);
            $t->integer('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('product_variant_values', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('variant_id')->index();
            $t->bigInteger('option_value_id')->index();
        });
    }

    public function down(): void
    {
        foreach (['product_variant_values', 'product_variants', 'option_values', 'option_groups', 'units'] as $table) {
            Schema::dropIfExists($table);
        }
        // SQLite cannot drop the added columns on product_categories / product_pricing without a rebuild.
    }
};
