<?php

use Vayu\Database\Migrations\Migration;
use Vayu\Database\Schema\Blueprint;
use Vayu\Database\Schema\Schema;

/**
 * Android app releases (docs/mobile-app.md phase 3). Single writer: App\Services\AppReleases.
 * The APK itself lives in storage/uploads/app/ (shared across deploys), never under public/.
 *
 *   version_code        unique, must keep increasing (Android refuses a lower one)
 *   min_supported_code  builds below this are told to update (forced)
 *   status              draft (staff only) | published (the one public release) | archived
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_releases', function (Blueprint $t): void {
            $t->id();
            $t->string('version_name', 40);
            $t->integer('version_code')->unique();
            $t->string('file_path', 255);
            $t->bigInteger('size_bytes')->default(0);
            $t->string('sha256', 64);
            $t->text('changelog')->nullable();
            $t->integer('min_supported_code')->default(0);
            $t->string('status', 12)->default('draft')->index();
            $t->dateTime('published_at')->nullable();
            $t->bigInteger('published_by')->nullable();
            $t->bigInteger('uploaded_by')->nullable();
            $t->integer('downloads')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_releases');
    }
};
