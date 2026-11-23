<?php

use Vayu\Database\Migrations\Migration;
use Vayu\Database\Schema\Blueprint;
use Vayu\Database\Schema\Schema;

/**
 * Stage 2: public review form (/review/{slug}).
 *
 *   customer_phone        the form asks for "mobile or email"; phone is what later matches orders
 *   customer_id/order_id  filled once customers (Stage 3) and orders (Stage 4) exist
 *   is_verified_purchase  set automatically later by matching phone/email to a delivered order
 *   ip / user_agent       spam review and rate-limit evidence (shown to admins only)
 *
 * Review photos are media links (owner_type = review, role = photo), not columns.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_reviews', function (Blueprint $t): void {
            $t->string('customer_phone', 20)->nullable();
            $t->bigInteger('customer_id')->nullable()->index();
            $t->bigInteger('order_id')->nullable()->index();
            $t->boolean('is_verified_purchase')->default(false);
            $t->string('ip', 45)->nullable()->index();
            $t->string('user_agent', 255)->nullable();
        });
    }

    public function down(): void
    {
        // SQLite cannot drop columns without a table rebuild; the nullable columns are harmless.
    }
};
