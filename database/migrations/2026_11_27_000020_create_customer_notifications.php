<?php

use Vayu\Database\Migrations\Migration;
use Vayu\Database\Schema\Blueprint;
use Vayu\Database\Schema\Schema;

/**
 * The customer's in-app notification inbox (docs/mobile-app.md, "In-app notifications").
 * Single writer: App\Services\Store\Inbox. Filled by the same order hooks as push
 * (App\Services\Push\OrderPush), so it works with or without FCM.
 *
 *   url   same-site path the notification opens (the order page)
 *   tag   groups one order's updates (order-<id>)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_notifications', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('customer_id')->index();
            $t->string('title', 150);
            $t->string('body', 500);
            $t->string('url', 255)->nullable();
            $t->string('tag', 40)->nullable();
            $t->dateTime('read_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notifications');
    }
};
