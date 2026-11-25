<?php

use Vayu\Database\DB;
use Vayu\Database\Migrations\Migration;
use Vayu\Database\Schema\Blueprint;
use Vayu\Database\Schema\Schema;

/**
 * Stage 8 S5 storefront accounts (docs/storefront.md §11).
 *
 *   users.email     NOT NULL → NULL. Customers register with a mobile number; email is optional.
 *                   The unique index stays (both MySQL and SQLite allow many NULLs in a unique index).
 *   users.session_version   bump = every other signed-in browser is signed out (password change / reset).
 *
 * Blueprint has no ->change(), so the column change is driver-specific: MySQL MODIFY, SQLite a
 * table rebuild (copy → drop → rename → indexes back).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t): void {
            $t->integer('session_version')->default(0);
        });

        if (DB::connection()->driver() === 'mysql') {
            DB::statement('ALTER TABLE users MODIFY email VARCHAR(255) NULL');

            return;
        }

        $cols = ['id', 'name', 'email', 'password', 'role', 'created_at', 'updated_at', 'phone', 'status',
            'email_verified_at', 'phone_verified_at', 'last_login_at', 'session_version'];
        $list = '"' . implode('", "', $cols) . '"';
        DB::statement('CREATE TABLE "users_s5" (
            "id" INTEGER PRIMARY KEY AUTOINCREMENT,
            "name" VARCHAR(255) NOT NULL,
            "email" VARCHAR(255) NULL,
            "password" VARCHAR(255) NULL,
            "role" VARCHAR(255) NOT NULL DEFAULT \'user\',
            "created_at" DATETIME NULL,
            "updated_at" DATETIME NULL,
            "phone" VARCHAR(20) NULL,
            "status" VARCHAR(20) NOT NULL DEFAULT \'active\',
            "email_verified_at" DATETIME NULL,
            "phone_verified_at" DATETIME NULL,
            "last_login_at" DATETIME NULL,
            "session_version" INTEGER NOT NULL DEFAULT 0
        )');
        DB::statement("INSERT INTO \"users_s5\" ($list) SELECT $list FROM \"users\"");
        DB::statement('DROP TABLE "users"');
        DB::statement('ALTER TABLE "users_s5" RENAME TO "users"');
        DB::statement('CREATE UNIQUE INDEX "users_email_unique" ON "users" ("email")');
        DB::statement('CREATE UNIQUE INDEX "users_phone_unique" ON "users" ("phone")');
    }

    public function down(): void
    {
        // Making email NOT NULL again would fail for phone-only accounts; the extra column is harmless.
    }
};
