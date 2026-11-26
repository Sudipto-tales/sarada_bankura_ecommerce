<?php

use Vayu\Database\Migrations\Migration;
use Vayu\Database\Schema\Blueprint;
use Vayu\Database\Schema\Schema;

/**
 * Push devices for the Android app (docs/mobile-app.md phase 4). Single writer: App\Services\Push\Devices.
 * One row per FCM token. A token belongs to the signed-in customer who last registered it; signing out
 * or deleting the account removes it, and FCM's "unregistered" answer prunes it.
 *
 *   user_id       the storefront login (users.id)
 *   customer_id   users.customer_id at registration: orders are matched on this
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_devices', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('user_id')->index();
            $t->bigInteger('customer_id')->nullable()->index();
            $t->string('token', 255)->unique();
            $t->string('platform', 12)->default('android');
            $t->string('app_version', 20)->nullable();
            $t->dateTime('last_seen_at')->nullable();
            $t->integer('failures')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_devices');
    }
};
