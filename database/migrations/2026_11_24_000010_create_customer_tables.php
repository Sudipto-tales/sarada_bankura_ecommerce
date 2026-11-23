<?php

use Vayu\Database\Migrations\Migration;
use Vayu\Database\Schema\Blueprint;
use Vayu\Database\Schema\Schema;

/**
 * Stage 3: customers (docs/customers.md).
 *
 *   users (ALTER)                 online login stays on users: phone, status, verified/last-login
 *   customers                     one row per person or firm; phone (10 digits) is the identity
 *   customer_addresses            billing / shipping / both, filled from the pincode lookup
 *   customer_tags + _tag_map      the customer vocabulary (VIP, Builder…), separate from product tags
 *   customer_segments             saved groups: dynamic (rules) or static (hand-picked members)
 *   customer_segment_conditions   one rule row = field + operator + value
 *   customer_segment_members      cache of who matched (static segments: the members themselves)
 *   pincodes                      lookup cache for /admin/lookup/pincode/{code}
 *
 * Order stats (total_orders / total_spent / last_order_at / lifecycle) stay at their defaults until
 * Stage 4 switches App\Services\Customer\CustomerStats on.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t): void {
            $t->string('phone', 20)->nullable()->unique();
            $t->string('status', 20)->default('active');
            $t->dateTime('email_verified_at')->nullable();
            $t->dateTime('phone_verified_at')->nullable();
            $t->dateTime('last_login_at')->nullable();
        });

        Schema::create('customers', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 120);
            $t->string('phone', 20)->unique();                   // normalised 10-digit mobile
            $t->string('email', 160)->nullable()->index();
            $t->string('company', 160)->nullable();
            $t->string('gst_number', 20)->nullable();
            $t->string('customer_type', 20)->default('individual'); // individual|builder|dealer|architect|other
            $t->string('status', 20)->default('active');         // active|inactive|blocked
            $t->string('lifecycle', 20)->default('new');         // new|returning|vip (CustomerStats)
            $t->string('source', 20)->default('admin');          // admin|online|showroom|import
            $t->boolean('is_online')->default(false);
            $t->bigInteger('user_id')->nullable()->index();
            $t->boolean('marketing_consent')->default(false);
            $t->integer('total_orders')->default(0);
            $t->decimal('total_spent', 12, 2)->default(0);
            $t->dateTime('last_order_at')->nullable();
            $t->text('notes')->nullable();
            $t->bigInteger('merged_into_id')->nullable()->index();
            $t->bigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('customer_addresses', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('customer_id')->index();
            $t->string('label', 40)->nullable();                  // Home, Site – Gobindanagar, Office…
            $t->string('type', 16)->default('both');             // billing|shipping|both
            $t->boolean('is_default')->default(false);
            $t->string('name', 120)->nullable();                  // receiver, when not the customer
            $t->string('phone', 20)->nullable();
            $t->string('address_line1', 255);
            $t->string('address_line2', 255)->nullable();
            $t->string('area', 120)->nullable();                  // locality typed by hand
            $t->string('post_office', 120)->nullable();           // chosen from the pincode lookup
            $t->string('city', 120)->nullable();
            $t->string('district', 120)->nullable();
            $t->string('state', 80)->nullable();
            $t->string('pincode', 6)->nullable()->index();
            $t->string('country', 60)->default('India');
            $t->timestamps();
        });

        Schema::create('customer_tags', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 60);
            $t->string('slug', 80)->unique();
            $t->string('color', 16)->default('gold');            // gold|green|blue|rose|sand|violet
            $t->string('description', 255)->nullable();
            $t->timestamps();
        });

        Schema::create('customer_tag_map', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('customer_id')->index();
            $t->bigInteger('tag_id')->index();
            $t->uniqueIndex(['customer_id', 'tag_id']);
        });

        Schema::create('customer_segments', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 120);
            $t->string('slug', 140)->unique();
            $t->string('description', 255)->nullable();
            $t->string('type', 16)->default('dynamic');          // dynamic|static
            $t->string('match_type', 8)->default('all');         // all (AND) | any (OR)
            $t->boolean('is_active')->default(true);
            $t->integer('member_count')->default(0);
            $t->dateTime('refreshed_at')->nullable();
            $t->bigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('customer_segment_conditions', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('segment_id')->index();
            $t->string('field', 40);
            $t->string('operator', 20);
            $t->string('value', 255)->nullable();
            $t->integer('sort_order')->default(0);
        });

        Schema::create('customer_segment_members', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('segment_id')->index();
            $t->bigInteger('customer_id')->index();
            $t->dateTime('matched_at')->nullable();
            $t->uniqueIndex(['segment_id', 'customer_id']);
        });

        Schema::create('pincodes', function (Blueprint $t): void {
            $t->id();
            $t->string('pincode', 6)->unique();
            $t->string('district', 120)->nullable();
            $t->string('state', 80)->nullable();
            $t->string('state_code', 4)->nullable();
            $t->text('areas')->nullable();                        // JSON list of post-office names
            $t->dateTime('cached_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['pincodes', 'customer_segment_members', 'customer_segment_conditions', 'customer_segments',
            'customer_tag_map', 'customer_tags', 'customer_addresses', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
        // users columns stay: SQLite cannot drop columns without a rebuild and they are nullable/defaulted.
    }
};
