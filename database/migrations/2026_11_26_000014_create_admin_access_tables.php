<?php

use Vayu\Database\Migrations\Migration;
use Vayu\Database\Schema\Blueprint;
use Vayu\Database\Schema\Schema;

/**
 * Admin access (docs/admin-access.md). Staff accounts are separate from the customers'
 * `users` table.
 *
 *   admin_users            staff logins (email and/or phone, ≥1), status invited|active|disabled
 *   roles                  Owner + 7 starting roles (seeded by Roles::sync / admin:install)
 *   role_permissions       role ↔ permission string ('orders.accept'); the list itself is config/permissions.php
 *   admin_user_roles       staff ↔ roles (several allowed; permissions add up)
 *   admin_invites          invite / reset links (/admin/login?invite=…), token stored hashed, 72 h
 *   admin_otps             6-digit codes (email now; whatsapp reserved), stored hashed, 10 min
 *   admin_remember_tokens  "Remember this device" (30 days), token stored hashed
 *   admin_login_attempts   lockout counter + login history
 *
 * audit_logs (Stage 5) is reused: actor_id = admin_users.id. Statuses are strings, FKs indexed bigints.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 120);
            $t->string('email', 190)->nullable()->unique();       // stored lower-case
            $t->string('phone', 15)->nullable()->unique();        // 10-digit Indian mobile
            $t->string('password', 255);
            $t->string('status', 10)->default('invited');         // invited | active | disabled
            $t->boolean('must_change_password')->default(true);
            $t->dateTime('temp_password_expires_at')->nullable(); // NULL = no expiry (admin:install)
            $t->integer('session_version')->default(1);           // bump = log out every other session
            $t->dateTime('last_login_at')->nullable();
            $t->string('last_login_ip', 45)->nullable();
            $t->dateTime('password_changed_at')->nullable();
            $t->bigInteger('created_by')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });

        Schema::create('roles', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 60);
            $t->string('slug', 60)->unique();
            $t->text('description')->nullable();
            $t->boolean('is_system')->default(false);            // Owner = locked
            $t->integer('sort')->default(0);
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });

        Schema::create('role_permissions', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('role_id');
            $t->string('permission', 64);                         // 'orders.accept' or '*' (Owner)
            $t->uniqueIndex(['role_id', 'permission']);
        });

        Schema::create('admin_user_roles', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('admin_user_id');
            $t->bigInteger('role_id')->index();
            $t->uniqueIndex(['admin_user_id', 'role_id']);
        });

        Schema::create('admin_invites', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('admin_user_id')->index();
            $t->string('token_hash', 64)->unique();               // sha256 of the link token
            $t->string('purpose', 10);                            // invite | reset
            $t->string('channels', 40)->nullable();               // email,whatsapp,copy
            $t->dateTime('expires_at');
            $t->dateTime('used_at')->nullable();
            $t->bigInteger('created_by')->nullable();
            $t->dateTime('created_at')->nullable();
        });

        Schema::create('admin_otps', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('admin_user_id');
            $t->string('code_hash', 64);                          // HMAC-SHA256 with APP_KEY
            $t->string('channel', 10)->default('email');          // email (whatsapp reserved)
            $t->string('purpose', 20);                            // change_password | forgot_password
            $t->integer('attempts')->default(0);
            $t->dateTime('expires_at');
            $t->dateTime('used_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->addIndex(['admin_user_id', 'created_at']);
        });

        Schema::create('admin_remember_tokens', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('admin_user_id')->index();
            $t->string('token_hash', 64)->unique();
            $t->string('device', 120)->nullable();
            $t->dateTime('expires_at');
            $t->dateTime('last_used_at')->nullable();
            $t->dateTime('created_at')->nullable();
        });

        Schema::create('admin_login_attempts', function (Blueprint $t): void {
            $t->id();
            $t->string('login_id', 190);                          // normalised email / phone as typed
            $t->bigInteger('admin_user_id')->nullable()->index();
            $t->string('ip', 45)->nullable();
            $t->boolean('success')->default(false);
            $t->string('reason', 30)->nullable();                 // ok | wrong_password | unknown | locked | disabled | expired | remember
            $t->string('user_agent', 255)->nullable();
            $t->dateTime('created_at')->nullable();
            $t->addIndex(['login_id', 'created_at']);
            $t->addIndex(['ip', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['admin_login_attempts', 'admin_remember_tokens', 'admin_otps', 'admin_invites', 'admin_user_roles', 'role_permissions', 'roles', 'admin_users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
