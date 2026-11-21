<?php

use Vayu\Database\DB;
use Vayu\Database\Migrations\Migration;
use Vayu\Database\Schema\Blueprint;
use Vayu\Database\Schema\Schema;

/**
 * Snapshot the stock unit on every movement, so history keeps its unit even after a
 * product stops being tracked (its inventory_stock row is removed then).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $t): void {
            $t->string('unit', 32)->nullable();
        });
        DB::statement('UPDATE inventory_movements SET unit = (SELECT s.unit FROM inventory_stock s WHERE s.id = inventory_movements.stock_id) WHERE unit IS NULL');
    }

    public function down(): void
    {
        // SQLite cannot drop a column without a table rebuild; the nullable column is harmless.
    }
};
