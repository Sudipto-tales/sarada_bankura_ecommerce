<?php

use Vayu\Database\DB;
use Vayu\Database\Migrations\Migration;
use Vayu\Database\Schema\Blueprint;
use Vayu\Database\Schema\Schema;

/**
 * Inventory = a simple stock register, tracked per product (not per variant).
 *
 *  inventory_settings   key/value configuration (defaults inserted below)
 *  inventory_stock      current physical stock — one row per tracked product
 *  inventory_movements  every change to stock, with before/after and unit-conversion snapshot
 *  inventory_alerts     low / out-of-stock alert state and history
 *  notifications        shared admin notifications (bell panel), not inventory-specific
 *
 * Only app/Services/Inventory/InventoryService writes to inventory_stock.quantity.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_settings', function (Blueprint $t): void {
            $t->id();
            $t->string('key', 64)->unique();
            $t->text('value')->nullable();
            $t->timestamps();
        });

        Schema::create('inventory_stock', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('product_id')->unique();               // one row per tracked product
            $t->bigInteger('variant_id')->nullable()->index();    // reserved for per-variant stock later; unused now
            $t->string('unit', 32);                               // stock unit symbol (units.symbol)
            $t->decimal('quantity', 14, 3)->default(0);
            $t->decimal('low_stock_threshold', 14, 3)->nullable(); // null = settings default_threshold
            $t->string('status', 16)->default('healthy')->index(); // healthy|low|out (cached)
            $t->dateTime('last_movement_at')->nullable();
            $t->timestamps();
        });

        Schema::create('inventory_movements', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('stock_id')->index();
            $t->bigInteger('product_id')->index();
            $t->bigInteger('variant_id')->nullable();
            $t->string('type', 16)->index();                      // opening|stock_in|adjust_in|adjust_out|import
            $t->decimal('qty_change', 14, 3);
            $t->decimal('qty_before', 14, 3);
            $t->decimal('qty_after', 14, 3);
            $t->decimal('entered_qty', 14, 3)->nullable();        // what the admin typed…
            $t->string('entered_unit', 32)->nullable();           // …in which unit
            $t->decimal('conversion_factor', 14, 6)->nullable();  // snapshot: entered × factor = stock unit
            $t->string('reason')->nullable();
            $t->string('reference_no', 64)->nullable();
            $t->text('notes')->nullable();
            $t->string('source', 16)->default('manual')->index(); // manual|import|opening|system
            $t->bigInteger('created_by')->nullable();             // admin user id once auth exists
            $t->dateTime('created_at')->nullable()->index();
        });

        Schema::create('inventory_alerts', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('stock_id')->index();
            $t->bigInteger('product_id')->index();
            $t->bigInteger('variant_id')->nullable();
            $t->string('type', 16);                               // low|out
            $t->string('status', 16)->default('active')->index(); // active|resolved
            $t->decimal('qty_at_trigger', 14, 3);
            $t->decimal('threshold_at_trigger', 14, 3)->nullable();
            $t->dateTime('triggered_at');
            $t->dateTime('resolved_at')->nullable();
        });

        Schema::create('notifications', function (Blueprint $t): void {
            $t->id();
            $t->string('type', 48)->index();                      // stock.low, stock.out, import.completed…
            $t->string('level', 16)->default('info');             // info|success|warning|danger
            $t->string('title');
            $t->string('body', 500)->nullable();
            $t->string('url')->nullable();                        // relative admin path
            $t->string('icon', 32)->nullable();                   // lucide icon name
            $t->json('data')->nullable();
            $t->string('dedupe_key', 120)->nullable()->index();
            $t->bigInteger('user_id')->nullable()->index();       // null = all admins
            $t->dateTime('read_at')->nullable()->index();
            $t->dateTime('created_at')->nullable()->index();
        });

        $now = date('Y-m-d H:i:s');
        $defaults = [
            'is_enabled' => '1',
            'default_threshold' => '10',
            'notify_low' => '1',
            'notify_out' => '1',
            'notify_recovered' => '1',
            'notify_import' => '1',
            'retention_days' => '90',
        ];
        foreach ($defaults as $key => $value) {
            DB::table('inventory_settings')->insert(['key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        foreach (['notifications', 'inventory_alerts', 'inventory_movements', 'inventory_stock', 'inventory_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
