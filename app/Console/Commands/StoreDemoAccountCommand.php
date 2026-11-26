<?php

namespace App\Console\Commands;

use App\Services\AdminAccess\Passwords;
use App\Services\Customer\CustomerService;
use App\Services\Customer\Phone;
use Vayu\Console\Command;
use Vayu\Database\DB;

/**
 * php vayu store:demo-account --phone=9xxxxxxxxx [--name="Play Review"]
 *
 * Creates the customer account Google Play reviewers sign in with (Play Console → App content →
 * App access), or resets its password if it already exists. Prints the login once. The customer is
 * tagged "Google Play review" so staff can spot it in admin and reject any order it places.
 *
 * Use a mobile number the shop controls (a spare SIM), never a customer's number. If a reviewer
 * deletes the account (Play tests account deletion), run this again and update the Play form.
 * docs/mobile-app.md phase 2.
 */
class StoreDemoAccountCommand extends Command
{
    public const TAG = 'play-review';

    protected static string $name = 'store:demo-account';

    protected static string $description = 'Create or reset the Google Play reviewer customer login (prints its password once)';

    public function handle(array $args): int
    {
        $opt = [];
        foreach ($args as $a) {
            if (preg_match('/^--(phone|name)=(.*)$/s', $a, $m)) {
                $opt[$m[1]] = trim($m[2], " \"'");
            }
        }
        $phone = Phone::normalize($opt['phone'] ?? '');
        if ($phone === null) {
            $this->error('Pass --phone=<10-digit mobile the shop controls>. Reviewers sign in with it.');

            return 1;
        }
        $name = ($opt['name'] ?? '') !== '' ? mb_substr($opt['name'], 0, 120) : 'Play Review';
        $now = date('Y-m-d H:i:s');
        $password = Passwords::temporary(12);
        $tagId = $this->tag($now);

        $u = DB::selectOne('SELECT * FROM users WHERE phone = ?', [$phone]);
        if ($u !== null) {
            $cid = DB::table('customers')->where('user_id', (int) $u['id'])->value('id');
            if ($cid === null || !DB::table('customer_tag_map')->where('customer_id', (int) $cid)->where('tag_id', $tagId)->exists()) {
                $this->error("{$phone} already belongs to a real customer account. Use another number.");

                return 1;
            }
            DB::update('UPDATE users SET password = ?, status = ?, session_version = session_version + 1, updated_at = ? WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), 'active', $now, (int) $u['id']]);
            $this->info('Reset the password of the existing Play review account.');
        } else {
            if (DB::table('customers')->where('phone', $phone)->exists()) {
                $this->error("{$phone} is already a showroom customer. Use a number that isn't anyone's customer record.");

                return 1;
            }
            $uid = (int) DB::table('users')->insertGetId([
                'name' => $name, 'email' => null, 'password' => password_hash($password, PASSWORD_DEFAULT), 'role' => 'customer',
                'phone' => $phone, 'status' => 'active', 'session_version' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $cid = CustomerService::linkUser($uid);
            if ($cid === null) {
                $this->error('Could not create the customer record.');

                return 1;
            }
            DB::table('customers')->where('id', $cid)->update(['notes' => 'Google Play reviewer test account. Reject any order it places.', 'updated_at' => $now]);
            DB::table('customer_tag_map')->insert(['customer_id' => $cid, 'tag_id' => $tagId]);
            $this->info('Created the Play review account.');
        }

        $this->line('');
        $this->line('  Play Console → App content → App access → "All or some functionality is restricted":');
        $this->line("    Username (mobile): {$phone}");
        $this->line("    Password:          {$password}");
        $this->line('    Instructions:      Sign in from Account → Sign in. Checkout needs a sign-in; do not pay, orders from this account are rejected.');
        $this->line('');
        $this->comment('The password is shown only now. Running this again makes a new one.');

        return 0;
    }

    private function tag(string $now): int
    {
        $id = DB::table('customer_tags')->where('slug', self::TAG)->value('id');

        return $id !== null ? (int) $id : (int) DB::table('customer_tags')->insertGetId([
            'name' => 'Google Play review', 'slug' => self::TAG, 'color' => 'rose',
            'description' => 'Test account for Google Play reviewers. Reject any orders it places.', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
