<?php

use Vayu\Database\Migrations\Migration;
use Vayu\Database\Schema\Blueprint;
use Vayu\Database\Schema\Schema;

/**
 * Stage 5: payments (docs/payments.md).
 *
 *   payment_accounts       where the shop receives money (UPI / bank / cash counter)
 *   payment_attempts       one per payment-window opening; doubles as "failed payments" + audit trail
 *   payments               money actually received, confirmed by the admin (window verify or shop)
 *   payment_refunds        money sent back (overpayment, declined order, return)
 *   payment_request_logs   every payment request the admin sends (email / WhatsApp / SMS)
 *   audit_logs             sensitive changes (receiving accounts…), values masked
 *
 * The cached payment columns on `orders` already exist (Stage 4) and are written only by
 * PaymentService::syncOrder(). Money DECIMAL(12,2); statuses are strings; FKs are indexed bigints.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_accounts', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 80);
            $t->string('type', 10);                               // upi | bank | cash
            $t->string('upi_id', 80)->nullable();
            $t->string('payee_name', 120)->nullable();
            $t->string('bank_name', 120)->nullable();
            $t->string('account_holder', 120)->nullable();
            $t->string('account_number', 34)->nullable();
            $t->string('ifsc', 11)->nullable();
            $t->boolean('is_default_upi')->default(false);
            $t->boolean('is_active')->default(true);
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('payment_attempts', function (Blueprint $t): void {
            $t->id();
            $t->string('attempt_number', 32)->unique();
            $t->string('public_token', 40)->unique();
            $t->string('context', 12);                            // checkout | order | invoice | request
            $t->bigInteger('order_id')->nullable()->index();      // NULL until a checkout attempt is submitted
            $t->bigInteger('customer_id')->nullable();
            $t->string('customer_name', 120)->nullable();
            $t->string('customer_phone', 20)->nullable();
            $t->string('pay_choice', 10)->default('due');         // advance | full | due
            $t->decimal('amount', 12, 2);
            $t->bigInteger('payment_account_id')->nullable();
            $t->text('cart_snapshot')->nullable();                // JSON, checkout only (server-built order input)
            $t->string('status', 12)->default('open');            // open|submitted|expired|verified|rejected
            $t->dateTime('expires_at');
            $t->string('payer_name', 120)->nullable();
            $t->string('payer_reference', 64)->nullable()->index(); // UTR / IMPS / NEFT ref
            $t->bigInteger('proof_media_id')->nullable();          // media (private disk)
            $t->dateTime('submitted_at')->nullable();
            $t->bigInteger('payment_id')->nullable();             // the payments row once verified
            $t->bigInteger('reviewed_by')->nullable();
            $t->dateTime('reviewed_at')->nullable();
            $t->string('reject_reason', 255)->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamps();
            $t->addIndex(['status', 'expires_at']);
        });

        Schema::create('payments', function (Blueprint $t): void {
            $t->id();
            $t->string('payment_number', 32)->unique();
            $t->bigInteger('order_id')->index();
            $t->bigInteger('customer_id')->index();
            $t->bigInteger('payment_attempt_id')->nullable();     // NULL = recorded at the shop
            $t->string('method', 16);                             // upi | bank_transfer | cash | cheque
            $t->string('collected_via', 8)->default('shop');      // window | shop
            $t->bigInteger('payment_account_id')->nullable();
            $t->decimal('amount', 12, 2);
            $t->string('reference', 80)->nullable()->index();
            $t->dateTime('received_at');
            $t->text('notes')->nullable();
            $t->string('status', 10)->default('received');        // received | void
            $t->string('void_reason', 255)->nullable();
            $t->dateTime('voided_at')->nullable();
            $t->bigInteger('created_by')->nullable();
            $t->timestamps();
            $t->addIndex(['status', 'received_at']);
        });

        Schema::create('payment_refunds', function (Blueprint $t): void {
            $t->id();
            $t->string('refund_number', 32)->unique();
            $t->bigInteger('order_id')->index();
            $t->bigInteger('payment_id')->nullable();
            $t->bigInteger('return_request_id')->nullable();
            $t->decimal('amount', 12, 2);
            $t->bigInteger('payment_account_id')->nullable();
            $t->string('method', 16);
            $t->string('reference', 80)->nullable();
            $t->string('reason', 255)->nullable();
            $t->string('status', 10)->default('paid');            // paid | void
            $t->string('void_reason', 255)->nullable();
            $t->dateTime('refunded_at');
            $t->bigInteger('created_by')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });

        Schema::create('payment_request_logs', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('order_id');
            $t->string('channel', 10);                            // email | whatsapp | sms
            $t->string('recipient', 160);
            $t->decimal('amount', 12, 2);
            $t->text('message')->nullable();
            $t->string('status', 10);                             // sent | failed | opened
            $t->string('error', 255)->nullable();
            $t->bigInteger('sent_by')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->addIndex(['order_id', 'created_at']);
        });

        Schema::create('audit_logs', function (Blueprint $t): void {
            $t->id();
            $t->string('entity_type', 40);
            $t->bigInteger('entity_id')->nullable();
            $t->string('action', 20);                             // create | update | delete | set_default
            $t->text('changes')->nullable();                      // JSON, sensitive values masked
            $t->bigInteger('actor_id')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->dateTime('created_at')->nullable();
            $t->addIndex(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'payment_request_logs', 'payment_refunds', 'payments', 'payment_attempts', 'payment_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
