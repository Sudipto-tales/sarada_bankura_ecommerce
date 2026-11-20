<?php

use Vayu\Database\Migrations\Migration;
use Vayu\Database\Schema\Blueprint;
use Vayu\Database\Schema\Schema;

/**
 * Veylix-style catalogue management:
 *  - attribute library (display mode, usage, status) + reusable attribute values with swatch / unit
 *  - brand profile fields (origin country, tier, placement, status)
 *  - tag governance fields (purpose, status)
 *  - product reviews & ratings with moderation + merchant response
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('attributes', function (Blueprint $t): void {
            $t->string('display', 16)->default('text');            // text|swatch|size|measure
            $t->string('used_for', 24)->default('filters');        // variants_filters|filters|specs
            $t->string('status', 16)->default('active');           // active|archived
            $t->text('description')->nullable();
            $t->integer('sort')->default(0);
        });

        // Predefined values for an attribute. Size / measure values carry their own unit
        // ("600 × 1200" + "mm"); colour values carry a hex swatch.
        Schema::create('attribute_values', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('attribute_id')->index();
            $t->string('label');
            $t->string('swatch', 16)->nullable();
            $t->string('unit', 32)->nullable();
            $t->integer('sort')->default(0);
        });

        Schema::table('brands', function (Blueprint $t): void {
            $t->string('country', 64)->nullable();                 // quarry / origin country
            $t->string('tier', 16)->default('core');               // signature|core|value
            $t->string('brand_type', 24)->default('house');        // house|quarry|importer|supplier
            $t->text('description')->nullable();
            $t->string('website')->nullable();
            $t->string('contact_email')->nullable();
            $t->string('logo')->nullable();
            $t->boolean('is_featured')->default(false);
            $t->string('status', 16)->default('active');           // active|draft|archived
        });

        Schema::table('tags', function (Blueprint $t): void {
            $t->string('purpose', 16)->default('discovery');       // discovery|merchandising|internal
            $t->text('description')->nullable();
            $t->string('status', 16)->default('active');           // active|archived
        });

        Schema::create('product_reviews', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('product_id')->index();
            $t->string('customer_name');
            $t->string('customer_email')->nullable();
            $t->string('city', 64)->nullable();
            $t->string('application', 64)->nullable();             // where the stone was installed
            $t->integer('rating')->default(5);                     // 1–5
            $t->string('title')->nullable();
            $t->text('body')->nullable();
            $t->string('source', 24)->default('storefront');       // verified|storefront|showroom|imported
            $t->string('status', 16)->default('pending')->index(); // pending|published|flagged|archived
            $t->text('response')->nullable();
            $t->dateTime('responded_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
        Schema::dropIfExists('attribute_values');
        // SQLite cannot drop the added columns on attributes / brands / tags without a table rebuild.
    }
};
