<?php

use Vayu\Database\Migrations\Migration;
use Vayu\Database\Schema\Blueprint;
use Vayu\Database\Schema\Schema;

/**
 * Stage 7: WhatsApp channel (docs/whatsapp.md).
 *
 *   whatsapp_messages    every message we send (api or wa.me fallback) and every message a customer
 *                        sends us (webhook). Written only by App\Services\WhatsApp\WhatsAppService
 *                        and the webhook handler. OTP codes are stored masked.
 *   whatsapp_campaigns   marketing broadcasts (phase W4 — table created now so nothing re-migrates)
 *
 * No credentials table: config comes from .env through config/whatsapp.php.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $t): void {
            $t->id();
            $t->string('direction', 3)->default('out');            // out | in
            $t->string('wamid', 128)->nullable()->unique();         // Meta message id
            $t->string('phone', 20);                                // 10-digit Indian mobile
            $t->string('template', 60)->nullable();                 // app event / template key
            $t->string('category', 20)->nullable();                 // authentication | utility | marketing | service
            $t->text('params_json')->nullable();                    // masked for authentication
            $t->text('body_preview')->nullable();
            $t->string('mode', 10)->default('api');                 // api | fallback
            $t->string('status', 12)->default('queued');            // queued | sent | delivered | read | failed | received | opened
            $t->string('error_code', 20)->nullable();
            $t->string('error_text', 255)->nullable();
            $t->string('related_type', 30)->nullable();             // order | payment | payment_attempt | customer | campaign …
            $t->bigInteger('related_id')->nullable();
            $t->bigInteger('campaign_id')->nullable();
            $t->bigInteger('sent_by')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('status_at')->nullable();
            $t->addIndex(['phone', 'created_at']);
            $t->addIndex(['related_type', 'related_id']);
            $t->addIndex(['status']);
        });

        Schema::create('whatsapp_campaigns', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 120);
            $t->string('template', 60);
            $t->text('params_json')->nullable();
            $t->bigInteger('segment_id')->nullable();
            $t->string('status', 12)->default('draft');             // draft | scheduled | sending | done | cancelled
            $t->dateTime('scheduled_at')->nullable();               // IST
            $t->integer('total')->default(0);
            $t->integer('sent_count')->default(0);
            $t->integer('delivered_count')->default(0);
            $t->integer('read_count')->default(0);             // "read" is reserved in MySQL
            $t->integer('failed_count')->default(0);
            $t->bigInteger('created_by')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['whatsapp_campaigns', 'whatsapp_messages'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
