<?php

use Vayu\Database\Migrations\Migration;
use Vayu\Database\Schema\Blueprint;
use Vayu\Database\Schema\Schema;

/**
 * Stage 4: orders + documents (docs/orders.md).
 *
 *   orders                 one row per order; addresses + customer snapshotted; payment columns are a
 *                          cache written only by PaymentService::syncOrder (Stage 5 fills payments)
 *   order_items            product/variant/price/policy snapshot per line (tax is order-level)
 *   order_status_history   every status change (who / when / note)
 *   order_invoices         documents: tax_invoice (at dispatch), packing_slip (at packed); public token
 *   order_deliveries       courier, tracking, dispatch/delivery dates, delivery OTP (hash only)
 *   document_counters      gapless number series per financial year (taken FOR UPDATE)
 *   product_return_policies, return_requests, return_request_items
 *                          created now, UI in Stage 6
 *
 * Money is DECIMAL(12,2), quantities DECIMAL(12,3). Statuses are strings, FKs are indexed bigints.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $t): void {
            $t->id();
            $t->string('order_number', 32)->unique();
            $t->bigInteger('customer_id')->index();
            $t->string('customer_name', 120);
            $t->string('customer_phone', 20)->nullable();
            $t->string('customer_email', 160)->nullable();
            $t->bigInteger('billing_address_id')->nullable();
            $t->bigInteger('shipping_address_id')->nullable();
            $t->text('billing_address')->nullable();            // JSON snapshot
            $t->text('shipping_address')->nullable();           // JSON snapshot; NULL = collected from showroom
            $t->string('place_of_supply', 80)->nullable();
            $t->string('order_status', 20)->default('pending')->index();
            $t->string('channel', 10)->default('offline');      // online | offline
            $t->string('channel_detail', 16)->default('showroom'); // website|walk_in|showroom|phone|whatsapp
            $t->string('public_token', 40)->unique();
            $t->decimal('subtotal', 12, 2)->default(0);         // Σ line totals (after line discounts)
            $t->decimal('discount_amount', 12, 2)->default(0);  // order-level discount
            $t->decimal('gst_rate', 5, 2)->nullable();          // NULL = no GST on this order
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('grand_total', 12, 2)->default(0);
            // payment cache — PaymentService::syncOrder only
            $t->string('payment_status', 20)->default('unpaid')->index();
            $t->decimal('paid_amount', 12, 2)->default(0);
            $t->decimal('due_amount', 12, 2)->default(0);
            $t->decimal('pending_verification_amount', 12, 2)->default(0);
            $t->decimal('refund_due', 12, 2)->default(0);
            $t->dateTime('last_payment_request_at')->nullable();
            $t->text('notes')->nullable();                       // shown on documents
            $t->text('internal_note')->nullable();               // staff only
            $t->text('customer_note')->nullable();               // shown on /order/{token} (e.g. reject reason)
            $t->bigInteger('accepted_by')->nullable();
            $t->dateTime('accepted_at')->nullable();
            $t->bigInteger('rejected_by')->nullable();
            $t->dateTime('rejected_at')->nullable();
            $t->string('reject_reason', 255)->nullable();
            $t->dateTime('processing_at')->nullable();
            $t->dateTime('packed_at')->nullable();
            $t->bigInteger('packed_by')->nullable();
            $t->dateTime('dispatched_at')->nullable();
            $t->dateTime('delivered_at')->nullable();
            $t->bigInteger('created_by')->nullable();
            $t->timestamps();
            $t->addIndex(['channel', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('order_id')->index();
            $t->bigInteger('product_id')->index();
            $t->bigInteger('variant_id')->default(0);
            $t->string('product_name', 255);
            $t->string('variant_name', 255)->nullable();
            $t->string('sku', 64)->nullable();
            $t->string('unit', 32)->nullable();
            $t->decimal('quantity', 12, 3);
            $t->decimal('unit_price', 12, 2);
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('line_total', 12, 2);
            $t->string('hsn_code', 16)->nullable();
            $t->boolean('return_allowed')->default(false);
            $t->boolean('replacement_allowed')->default(false);
            $t->integer('return_days')->default(0);
            $t->integer('replacement_days')->default(0);
            $t->integer('sort')->default(0);
            $t->dateTime('created_at')->nullable();
        });

        Schema::create('order_status_history', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('order_id')->index();
            $t->string('old_status', 20)->nullable();
            $t->string('new_status', 20);
            $t->bigInteger('changed_by')->nullable();
            $t->text('note')->nullable();
            $t->dateTime('created_at')->nullable();
        });

        Schema::create('order_invoices', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('order_id')->index();
            $t->string('document_type', 20);                    // tax_invoice | packing_slip
            $t->string('invoice_number', 32)->nullable()->unique();
            $t->string('title', 40)->nullable();                 // Tax Invoice | Invoice | Bill of Supply
            $t->string('public_token', 40)->unique();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('gst_rate', 5, 2)->nullable();
            $t->string('tax_mode', 12)->default('none');         // cgst_sgst | igst | none
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->date('invoice_date')->nullable();
            $t->string('file_path', 255)->nullable();
            $t->bigInteger('created_by')->nullable();
            $t->dateTime('created_at')->nullable();
        });

        Schema::create('order_deliveries', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('order_id')->unique();
            $t->string('courier_name', 120)->nullable();
            $t->string('tracking_number', 80)->nullable();
            $t->string('tracking_url', 255)->nullable();
            $t->dateTime('dispatch_date')->nullable();
            $t->dateTime('delivery_date')->nullable();
            $t->string('otp_hash', 128)->nullable();
            $t->dateTime('otp_expires_at')->nullable();
            $t->integer('otp_attempts')->default(0);
            $t->dateTime('otp_sent_at')->nullable();
            $t->dateTime('otp_verified_at')->nullable();
            $t->string('verified_by', 20)->nullable();           // otp | manual
            $t->text('delivery_note')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });

        Schema::create('document_counters', function (Blueprint $t): void {
            $t->id();
            $t->string('series', 16);                            // order|invoice|payment|refund|attempt|return
            $t->string('fy', 8);                                  // 26-27
            $t->string('prefix', 16);
            $t->integer('last_number')->default(0);
            $t->dateTime('updated_at')->nullable();
            $t->uniqueIndex(['series', 'fy']);
        });

        Schema::create('product_return_policies', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('product_id');
            $t->bigInteger('variant_id')->default(0);
            $t->boolean('return_allowed')->default(false);
            $t->boolean('replacement_allowed')->default(false);
            $t->integer('return_days')->default(0);
            $t->integer('replacement_days')->default(0);
            $t->text('return_conditions')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->uniqueIndex(['product_id', 'variant_id']);
        });

        Schema::create('return_requests', function (Blueprint $t): void {
            $t->id();
            $t->string('request_number', 32)->unique();
            $t->bigInteger('order_id')->index();
            $t->bigInteger('customer_id')->index();
            $t->string('request_type', 16);                      // return | replacement
            $t->string('reason', 120);
            $t->text('description')->nullable();
            $t->string('status', 16)->default('submitted');      // submitted|approved|rejected|received|resolved
            $t->text('admin_note')->nullable();
            $t->bigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->dateTime('created_at')->nullable();
        });

        Schema::create('return_request_items', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('return_request_id')->index();
            $t->bigInteger('order_item_id')->index();
            $t->bigInteger('product_id');
            $t->bigInteger('variant_id')->default(0);
            $t->decimal('requested_quantity', 12, 3);
            $t->decimal('approved_quantity', 12, 3)->nullable();
            $t->decimal('received_quantity', 12, 3)->nullable();
            $t->string('resolution_type', 16)->nullable();       // refund | replacement
            $t->decimal('refund_amount', 12, 2)->nullable();
            $t->bigInteger('replacement_product_id')->nullable();
            $t->bigInteger('replacement_variant_id')->nullable();
            $t->dateTime('created_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['return_request_items', 'return_requests', 'product_return_policies', 'document_counters',
            'order_deliveries', 'order_invoices', 'order_status_history', 'order_items', 'orders'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
