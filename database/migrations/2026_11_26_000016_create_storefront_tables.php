<?php

use Vayu\Database\Migrations\Migration;
use Vayu\Database\Schema\Blueprint;
use Vayu\Database\Schema\Schema;

/**
 * Stage 8 storefront (docs/storefront.md §1). The only storefront migration.
 *
 * ALTER (all nullable, no data change):
 *   tags.icon                              Lucide icon name for discovery chips
 *   product_pricing.min_order_qty/order_step   "minimum 50 sq ft", "+/- in steps of a box"
 *   product_variants.pack_qty/pack_unit/min_order_qty   NULL = use the product's pricing row
 *   orders.buyer_note                      the customer's own checkout note (customer_note = our note TO them)
 *   attribute_values.family                colour family (white | black | beige …) for the shop facet (owner default: yes)
 *
 * New tables:
 *   carts / cart_items                     cookie token cart (core session dies after ~24 min idle)
 *   customer_saved_items                   signed-in "saved" list
 *   enquiries                              contact / product / home / calculator enquiries
 *   customer_auth_codes                    storefront reset codes (admin has admin_otps)
 *   customer_remember_tokens               "stay signed in" (selector + hashed validator)
 *
 * Statuses are strings, FKs are indexed bigints (Blueprint has no enum/foreign keys).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tags', function (Blueprint $t): void {
            $t->string('icon', 40)->nullable();
        });
        Schema::table('product_pricing', function (Blueprint $t): void {
            $t->decimal('min_order_qty', 12, 3)->nullable();
            $t->decimal('order_step', 12, 3)->nullable();
        });
        Schema::table('product_variants', function (Blueprint $t): void {
            $t->decimal('pack_qty', 12, 3)->nullable();
            $t->string('pack_unit', 16)->nullable();
            $t->decimal('min_order_qty', 12, 3)->nullable();
        });
        Schema::table('orders', function (Blueprint $t): void {
            $t->text('buyer_note')->nullable();
        });
        Schema::table('attribute_values', function (Blueprint $t): void {
            $t->string('family', 30)->nullable();
        });

        Schema::create('carts', function (Blueprint $t): void {
            $t->id();
            $t->string('token', 64)->unique();                   // cookie ms_cart (30 days)
            $t->bigInteger('customer_id')->nullable()->index();
            $t->bigInteger('user_id')->nullable()->index();
            $t->string('status', 12)->default('active');         // active | converted | abandoned
            $t->bigInteger('converted_order_id')->nullable();
            $t->dateTime('last_seen_at')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->addIndex(['status', 'last_seen_at']);
        });

        Schema::create('cart_items', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('cart_id')->index();
            $t->bigInteger('product_id');
            $t->bigInteger('variant_id')->default(0);
            $t->decimal('quantity', 12, 3);
            $t->decimal('price_at_add', 12, 2)->nullable();      // only for "price changed since you added it"
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->uniqueIndex(['cart_id', 'product_id', 'variant_id']);
        });

        Schema::create('customer_saved_items', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('customer_id')->index();
            $t->bigInteger('product_id');
            $t->bigInteger('variant_id')->default(0);
            $t->dateTime('created_at')->nullable();
            $t->uniqueIndex(['customer_id', 'product_id', 'variant_id']);
        });

        Schema::create('enquiries', function (Blueprint $t): void {
            $t->id();
            $t->string('enquiry_number', 30)->unique();          // ENQ/26-27/0001 (DocumentNumber)
            $t->bigInteger('customer_id')->nullable()->index();
            $t->string('name', 120);
            $t->string('phone', 15);
            $t->string('email', 190)->nullable();
            $t->string('city', 80)->nullable();
            $t->bigInteger('category_id')->nullable();
            $t->bigInteger('product_id')->nullable();
            $t->bigInteger('variant_id')->default(0);
            $t->decimal('quantity', 12, 3)->nullable();
            $t->string('unit', 16)->nullable();
            $t->text('message');
            $t->string('source', 12)->default('contact');        // contact | product | home | calculator
            $t->string('status', 12)->default('new')->index();   // new | contacted | quoted | converted | closed
            $t->bigInteger('assigned_to')->nullable();
            $t->bigInteger('converted_order_id')->nullable();
            $t->text('internal_note')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });

        Schema::create('customer_auth_codes', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('user_id')->index();
            $t->string('purpose', 20);                           // reset_password | verify_phone (reserved for WhatsApp)
            $t->string('channel', 10);                           // email | whatsapp
            $t->string('code_hash', 255);
            $t->integer('attempts')->default(0);
            $t->dateTime('expires_at');
            $t->dateTime('used_at')->nullable();
            $t->string('ip', 45)->nullable();
            $t->dateTime('created_at')->nullable();
        });

        Schema::create('customer_remember_tokens', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('user_id')->index();
            $t->string('selector', 32)->unique();
            $t->string('validator_hash', 64);                    // sha256 of the validator
            $t->dateTime('expires_at');
            $t->dateTime('last_used_at')->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->dateTime('created_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['customer_remember_tokens', 'customer_auth_codes', 'enquiries', 'customer_saved_items', 'cart_items', 'carts'] as $table) {
            Schema::dropIfExists($table);
        }
        // SQLite cannot drop columns without a table rebuild; the nullable ALTER columns are harmless.
    }
};
