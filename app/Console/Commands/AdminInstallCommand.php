<?php

namespace App\Console\Commands;

use App\Services\AdminAccess\LoginId;
use App\Services\AdminAccess\Passwords;
use App\Services\AdminAccess\Roles;
use Vayu\Console\Command;
use Vayu\Database\DB;

/**
 * php vayu admin:install [--email=you@shop.in] [--phone=98xxxxxxxx] [--name="Store Owner"]
 *
 * Seeds the starting roles and, only when no Owner exists yet, creates the default admin
 * (role Owner) from ADMIN_EMAIL / ADMIN_PHONE in .env (or the flags). Its random password is
 * printed once here and must be changed on first login. Safe to run on every deploy.
 */
class AdminInstallCommand extends Command
{
    protected static string $name = 'admin:install';

    protected static string $description = 'Seed admin roles and create the default Owner login (prints its password once)';

    public function handle(array $args): int
    {
        $opt = [];
        foreach ($args as $a) {
            if (preg_match('/^--(email|phone|name)=(.*)$/s', $a, $m)) {
                $opt[$m[1]] = trim($m[2], " \"'");
            }
        }
        try {
            $made = Roles::sync();
        } catch (\Throwable $e) {
            $this->error('Could not seed roles — did you run `php vayu migrate`? ' . $e->getMessage());

            return 1;
        }
        if ($made > 0) {
            $this->info("Seeded {$made} role(s).");
        }
        $ownerRole = Roles::ownerRoleId();
        $owners = (int) (DB::selectOne('SELECT COUNT(*) AS n FROM admin_user_roles WHERE role_id = ?', [$ownerRole])['n'] ?? 0);
        if ($owners > 0) {
            $this->comment('An Owner account already exists — nothing to create.');

            return 0;
        }

        $rawEmail = (string) ($opt['email'] ?? env('ADMIN_EMAIL', ''));
        $rawPhone = (string) ($opt['phone'] ?? env('ADMIN_PHONE', ''));
        $email = trim($rawEmail) === '' ? null : LoginId::email($rawEmail);
        $phone = trim($rawPhone) === '' ? null : LoginId::phone($rawPhone);
        if (trim($rawEmail) !== '' && $email === null) {
            $this->error("ADMIN_EMAIL \"{$rawEmail}\" is not a valid email.");

            return 1;
        }
        if (trim($rawPhone) !== '' && $phone === null) {
            $this->error("ADMIN_PHONE \"{$rawPhone}\" is not a 10-digit Indian mobile number.");

            return 1;
        }
        if ($email === null && $phone === null) {
            $this->error('Set ADMIN_EMAIL and/or ADMIN_PHONE in .env (or pass --email= / --phone=) — it becomes the Owner\'s login ID.');

            return 1;
        }
        foreach (['email' => $email, 'phone' => $phone] as $col => $v) {
            if ($v !== null && DB::selectOne("SELECT id FROM admin_users WHERE {$col} = ?", [$v])) {
                $this->error("A staff account with this {$col} already exists ({$v}). Give it the Owner role instead, or use another {$col}.");

                return 1;
            }
        }

        $password = Passwords::temporary();
        $now = date('Y-m-d H:i:s');
        $id = DB::table('admin_users')->insertGetId([
            'name' => ($opt['name'] ?? '') !== '' ? mb_substr($opt['name'], 0, 120) : 'Store Owner',
            'email' => $email, 'phone' => $phone, 'password' => Passwords::hash($password),
            'status' => 'invited', 'must_change_password' => 1, 'temp_password_expires_at' => null,
            'session_version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('admin_user_roles')->insert(['admin_user_id' => $id, 'role_id' => $ownerRole]);

        $this->info('Default admin (Owner) created.');
        $this->line('  Login at:  ' . base_url('admin/login'));
        $this->line('  Login ID:  ' . implode('  or  ', array_filter([$email, $phone])));
        $this->line("  Password:  \033[1m{$password}\033[0m");
        $this->comment('  This password is shown only once. You will be asked to set your own on first login.');

        return 0;
    }
}
