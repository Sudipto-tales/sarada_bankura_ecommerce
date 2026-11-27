<?php

namespace App\Services\Store;

use App\Services\AdminAccess\LoginId;
use App\Services\Customer\CustomerService;
use App\Services\Customer\Phone;
use App\Services\Mail\Mail;
use Vayu\Database\DB;
use Vayu\Validation\ValidationException;

/**
 * Storefront customer accounts (docs/storefront.md). The only writer of storefront
 * `users` rows, customer_auth_codes and customer_remember_tokens. Staff log in separately
 * (admin_users, App\Services\AdminAccess\Guard); the two never share a session key or cookie.
 *
 * - Identity = the 10-digit mobile (users.phone unique). Email is optional and only used for
 *   password reset codes. Registering links the showroom customer with the same phone
 *   (CustomerService::linkUser), so past offline orders show up in the account at once.
 * - Session: session('customer') = {id, ver}. users.session_version bump signs every other
 *   browser out (password change / reset). "Stay signed in" = selector:validator cookie, 30 days,
 *   rotated on every use.
 * - Throttle (rate_limiter, fixed window 15 min): 5 wrong passwords per login ID, 20 per IP;
 *   registrations 10 per hour per IP; reset codes 3 per hour per account, 10 per IP.
 * - Signing in merges the guest bag (Cart::adopt) and the guest saved list (Saved::merge).
 * - Deleting the account (deleteAccount, a Google Play requirement) wipes the sign-in and everything
 *   personal, and keeps orders, payments and invoices for GST record-keeping (docs/mobile-app.md).
 */
final class CustomerAuth
{
    public const SESSION = 'customer';
    public const COOKIE = 'ms_remember';
    public const REMEMBER_DAYS = 30;
    public const MIN_PASSWORD = 8;
    public const MAX_PER_ID = 5;
    public const MAX_PER_IP = 20;
    public const WINDOW = 900;
    public const CODE_MINUTES = 15;
    public const CODE_TRIES = 5;

    /** Tests: don't send headers. */
    public static bool $sendCookie = true;

    /* ================================================================ current customer */

    /**
     * The signed-in customer (users row + customer_id), from the session or a remember cookie.
     * Called once per request by the StoreCustomer middleware.
     */
    public static function resolve(): ?array
    {
        return self::fromSession() ?? self::fromRememberCookie();
    }

    private static function fromSession(): ?array
    {
        $s = session()->get(self::SESSION);
        if (!is_array($s) || empty($s['id'])) {
            return null;
        }
        $u = self::find((int) $s['id']);
        if ($u === null || $u['status'] !== 'active' || (int) $u['session_version'] !== (int) ($s['ver'] ?? -1)) {
            session()->forget(self::SESSION);

            return null;
        }

        return $u;
    }

    private static function fromRememberCookie(): ?array
    {
        $raw = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $raw, $m)) {
            if ($raw !== '') {
                self::clearCookie();
            }

            return null;
        }
        $row = DB::selectOne('SELECT * FROM customer_remember_tokens WHERE selector = ?', [$m[1]]);
        $u = $row ? self::find((int) $row['user_id']) : null;
        if ($row === null || $u === null || $u['status'] !== 'active' || (string) $row['expires_at'] < self::now()
            || !hash_equals((string) $row['validator_hash'], hash('sha256', $m[2]))) {
            if ($row !== null) {
                DB::delete('DELETE FROM customer_remember_tokens WHERE id = ?', [$row['id']]);
            }
            self::clearCookie();

            return null;
        }
        // Rotate: a copied cookie stops working after the next visit.
        DB::delete('DELETE FROM customer_remember_tokens WHERE id = ?', [$row['id']]);
        self::startSession($u);
        self::remember((int) $u['id']);

        return $u;
    }

    /** users row + customer_id (null when the account has no customer yet). */
    public static function find(int $id): ?array
    {
        $u = DB::selectOne('SELECT * FROM users WHERE id = ?', [$id]);
        if ($u === null) {
            return null;
        }
        $cid = DB::table('customers')->where('user_id', $id)->value('id');
        if ($cid === null && $u['phone']) {
            $cid = CustomerService::linkUser($id); // account made before linking existed
        }
        $u['customer_id'] = $cid !== null ? (int) $cid : null;

        return $u;
    }

    /* ================================================================ register / sign in */

    /**
     * Create an account and sign in. Throws ValidationException (field-keyed messages).
     *
     * @param array<string,mixed> $in name, phone, email?, password, marketing_consent?, remember?
     * @param list<string> $savedSlugs guest saved list to merge
     */
    public static function register(array $in, ?string $ip = null, array $savedSlugs = []): array
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) ($in['name'] ?? '')) ?? '');
        $phone = Phone::normalize((string) ($in['phone'] ?? ''));
        $emailRaw = trim((string) ($in['email'] ?? ''));
        $email = $emailRaw === '' ? null : LoginId::email($emailRaw);
        $password = (string) ($in['password'] ?? '');
        $err = [];

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $err['name'][] = 'Enter your full name.';
        }
        if ($phone === null) {
            $err['phone'][] = 'Enter your 10-digit mobile number.';
        } elseif (DB::table('users')->where('phone', $phone)->exists()) {
            $err['phone'][] = 'An account with this mobile number already exists. Sign in instead, or use “Forgot password”.';
        }
        if ($emailRaw !== '' && $email === null) {
            $err['email'][] = 'Enter a valid email address, or leave it empty.';
        } elseif ($email !== null && DB::selectOne('SELECT id FROM users WHERE LOWER(email) = ?', [$email]) !== null) {
            $err['email'][] = 'This email is already on another account. Sign in instead, or leave it empty.';
        }
        if (($p = self::passwordProblem($password)) !== null) {
            $err['password'][] = $p;
        }
        if ($err === [] && !self::hit('register:ip:' . sha1((string) $ip), 10, 3600)) {
            $err['phone'][] = 'Too many new accounts from this connection. Please try again in an hour.';
        }
        if ($err) {
            throw new ValidationException($err);
        }

        $now = self::now();
        $uid = DB::transaction(static function () use ($name, $phone, $email, $password, $in, $now): int {
            $uid = (int) DB::table('users')->insertGetId([
                'name' => $name, 'email' => $email, 'password' => password_hash($password, PASSWORD_DEFAULT), 'role' => 'customer',
                'phone' => $phone, 'status' => 'active', 'session_version' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $cid = CustomerService::linkUser($uid);
            if ($cid !== null) {
                $c = DB::selectOne('SELECT email FROM customers WHERE id = ?', [$cid]);
                $set = ['updated_at' => $now];
                if (!empty($in['marketing_consent'])) {
                    $set['marketing_consent'] = 1; // opting in here never removes an earlier opt-in
                }
                if ($email !== null && empty($c['email'])) {
                    $set['email'] = $email;
                }
                DB::table('customers')->where('id', $cid)->update($set);
            }

            return $uid;
        });

        return self::login(self::find($uid), !empty($in['remember']), $savedSlugs);
    }

    /**
     * Phone-or-email + password. Returns the users row. Throws AuthException (message safe to show).
     *
     * @param list<string> $savedSlugs
     */
    public static function attempt(string $loginId, string $password, bool $remember = false, ?string $ip = null, array $savedSlugs = []): array
    {
        [$kind, $id] = LoginId::parse($loginId);
        if ($id === '' || $password === '') {
            throw new AuthException('Enter your mobile number (or email) and your password.');
        }
        $idKey = 'signin:id:' . sha1($id);
        $ipKey = 'signin:ip:' . sha1((string) $ip);
        $limiter = self::limiter();
        if ($limiter !== null) {
            foreach ([[$idKey, self::MAX_PER_ID], [$ipKey, self::MAX_PER_IP]] as [$k, $max]) {
                if ($limiter->tooManyAttempts($k, $max)) {
                    $min = max(1, (int) ceil($limiter->availableIn($k) / 60));
                    throw new AuthException('Too many wrong tries. Please wait ' . $min . ' minute' . ($min === 1 ? '' : 's') . ', or use “Forgot password”.');
                }
            }
        }
        $u = match ($kind) {
            'email' => DB::selectOne('SELECT * FROM users WHERE LOWER(email) = ?', [$id]),
            'phone' => DB::selectOne('SELECT * FROM users WHERE phone = ?', [$id]),
            default => null,
        };
        $hash = $u !== null && $u['password'] ? (string) $u['password'] : '$2y$10$pgO7Vivh9qYTMAveJEl3Te71J3ZFLac8WdqC1ad5.3ZMXS2yZZvgq';
        $ok = password_verify($password, $hash) && $u !== null && $u['password'];
        if (!$ok) {
            $limiter?->hit($idKey, self::WINDOW);
            $limiter?->hit($ipKey, self::WINDOW);
            throw new AuthException('That mobile number / email and password don’t match. Check them, or use “Forgot password”.');
        }
        // Only after the right password: say why the account can't sign in.
        if (($u['status'] ?? 'active') !== 'active') {
            throw new AuthException('This account is switched off. Please message us on WhatsApp and we’ll help.');
        }
        if (($u['role'] ?? '') === 'admin') {
            throw new AuthException('Staff accounts sign in at /admin, not on the shop.');
        }
        $limiter?->clear($idKey);
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            DB::table('users')->where('id', (int) $u['id'])->update(['password' => password_hash($password, PASSWORD_DEFAULT)]);
        }

        return self::login(self::find((int) $u['id']), $remember, $savedSlugs);
    }

    /** Start the session, merge bag + saved list, set last_login_at. @param list<string> $savedSlugs */
    private static function login(?array $u, bool $remember, array $savedSlugs): array
    {
        if ($u === null) {
            throw new AuthException('Something went wrong. Please try again.');
        }
        self::startSession($u);
        DB::table('users')->where('id', (int) $u['id'])->update(['last_login_at' => self::now()]);
        if ($remember) {
            self::remember((int) $u['id']);
        }
        Cart::adopt((int) $u['id'], $u['customer_id']);
        if ($u['customer_id'] !== null && $savedSlugs !== []) {
            Saved::merge((int) $u['customer_id'], $savedSlugs);
        }
        Context::setCustomer($u);

        return $u;
    }

    public static function logout(): void
    {
        \App\Services\Push\Devices::forgetSessionDevice(); // before the session is regenerated
        $raw = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (preg_match('/^([a-f0-9]{24}):/', $raw, $m)) {
            DB::delete('DELETE FROM customer_remember_tokens WHERE selector = ?', [$m[1]]);
        }
        self::clearCookie();
        session()->forget(self::SESSION);
        session()->regenerate();
        Cart::forgetCookie(); // the bag stays on the account and comes back at the next sign-in
        Context::setCustomer(null);
    }

    /* ================================================================ account deletion */

    public const DELETED_NAME = 'Deleted customer';

    /**
     * Delete the signed-in customer's account (password required), then sign out.
     *
     * Wiped: the login (name, phone, email, password → the users row stays as an empty tombstone
     * so ids in old records still resolve), remember tokens, reset codes, saved list, bag; reviews
     * and enquiries lose the name / phone / email. A customer record the website created is emptied
     * too (addresses, tags, notes, GSTIN). A record the showroom had before the account is only
     * unlinked: it is the shop's own customer file, not data collected by the account.
     * Kept: orders, order items, payments, refunds and payment screenshots. Orders carry their own
     * name / phone / address snapshot, which GST law requires us to keep.
     * The app's push tokens (app_devices) are deleted too.
     *
     * @return array{orders: int} orders kept
     */
    public static function deleteAccount(int $userId, string $password, ?string $ip = null): array
    {
        $u = self::find($userId);
        if ($u === null || ($u['status'] ?? '') !== 'active') {
            throw new AuthException('This account can’t be deleted right now. Please message us on WhatsApp.');
        }
        if (!self::hit('delete:id:' . $userId, 5, self::WINDOW)) {
            throw new ValidationException(['password' => ['Too many wrong tries. Please wait 15 minutes and try again.']]);
        }
        if (!$u['password'] || !password_verify($password, (string) $u['password'])) {
            throw new ValidationException(['password' => ['That password isn’t right.']]);
        }
        $cid = $u['customer_id'];
        $now = self::now();
        $orders = $cid ? (int) (DB::selectOne('SELECT COUNT(*) AS n FROM orders WHERE customer_id = ?', [$cid])['n'] ?? 0) : 0;

        DB::transaction(static function () use ($userId, $cid, $now): void {
            $c = $cid ? DB::selectOne('SELECT * FROM customers WHERE id = ?', [$cid]) : null;
            $phone = $c['phone'] ?? null;
            $carts = array_map('intval', array_column(DB::select('SELECT id FROM carts WHERE user_id = ?' . ($cid ? ' OR customer_id = ?' : ''),
                $cid ? [$userId, $cid] : [$userId]), 'id'));
            foreach ($carts as $cartId) {
                DB::delete('DELETE FROM cart_items WHERE cart_id = ?', [$cartId]);
                DB::delete('DELETE FROM carts WHERE id = ?', [$cartId]);
            }
            if ($cid) {
                DB::delete('DELETE FROM customer_saved_items WHERE customer_id = ?', [$cid]);
                Inbox::forget($cid);
                DB::update('UPDATE product_reviews SET customer_id = NULL, customer_name = ?, customer_email = NULL, customer_phone = NULL WHERE customer_id = ?'
                    . ($phone ? ' OR customer_phone = ?' : ''), $phone ? ['A customer', $cid, $phone] : ['A customer', $cid]);
                DB::update('UPDATE enquiries SET customer_id = NULL, name = ?, phone = ?, email = NULL WHERE customer_id = ?'
                    . ($phone ? ' OR phone = ?' : ''), $phone ? [self::DELETED_NAME, '', $cid, $phone] : [self::DELETED_NAME, '', $cid]);
                $set = ['user_id' => null, 'is_online' => 0, 'marketing_consent' => 0, 'updated_at' => $now];
                if (($c['source'] ?? '') === 'online') {
                    $set += ['name' => self::DELETED_NAME, 'phone' => 'deleted-' . $cid, 'email' => null, 'company' => null,
                        'gst_number' => null, 'notes' => null, 'status' => 'inactive'];
                    DB::delete('DELETE FROM customer_addresses WHERE customer_id = ?', [$cid]);
                    DB::delete('DELETE FROM customer_tag_map WHERE customer_id = ?', [$cid]);
                    DB::delete('DELETE FROM customer_segment_members WHERE customer_id = ?', [$cid]);
                }
                DB::table('customers')->where('id', $cid)->update($set);
            }
            DB::delete('DELETE FROM customer_remember_tokens WHERE user_id = ?', [$userId]);
            DB::delete('DELETE FROM customer_auth_codes WHERE user_id = ?', [$userId]);
            \App\Services\Push\Devices::forgetUser($userId);
            DB::update('UPDATE users SET name = ?, email = NULL, phone = NULL, password = NULL, status = ?, session_version = session_version + 1,
                email_verified_at = NULL, phone_verified_at = NULL, last_login_at = NULL, updated_at = ? WHERE id = ?', [self::DELETED_NAME, 'deleted', $now, $userId]);
        });
        \App\Services\Payment\Audit::log('customer_account', $userId, 'delete', ['customer_id' => $cid, 'orders_kept' => $orders]);
        self::logout();

        return ['orders' => $orders];
    }

    /* ================================================================ passwords */

    /** Change password while signed in (current password required). Signs out other browsers. */
    public static function changePassword(int $userId, string $current, string $new, string $confirm): void
    {
        $u = DB::selectOne('SELECT * FROM users WHERE id = ?', [$userId]);
        $err = [];
        if ($u === null || !$u['password'] || !password_verify($current, (string) $u['password'])) {
            $err['current_password'][] = 'Your current password isn’t right.';
        }
        if (($p = self::passwordProblem($new)) !== null) {
            $err['password'][] = $p;
        } elseif (!hash_equals($new, $confirm)) {
            $err['password_confirmation'][] = 'The two new passwords don’t match.';
        } elseif ($u !== null && $u['password'] && password_verify($new, (string) $u['password'])) {
            $err['password'][] = 'Choose a new password — this is the same as the current one.';
        }
        if ($err) {
            throw new ValidationException($err);
        }
        self::setPassword($userId, $new);
        self::startSession(self::find($userId));
    }

    /**
     * Forgot password, step 1: email a 6-digit code. The result never says whether an account
     * exists. 'mail_off' = email isn't set up on the server (page shows "message us on WhatsApp").
     *
     * @return array{ok: bool, mail_off?: bool, message: string}
     */
    public static function sendResetCode(string $email, ?string $ip = null): array
    {
        $email = LoginId::email($email);
        if ($email === null) {
            return ['ok' => false, 'message' => 'Enter the email address on your account.'];
        }
        if (!Mail::configured()) {
            return ['ok' => false, 'mail_off' => true, 'message' => 'We can’t send emails right now. Message us on WhatsApp from your registered mobile and we’ll reset your password.'];
        }
        $same = ['ok' => true, 'message' => 'If that email is on an account, we’ve sent a 6-digit code to it. It works for ' . self::CODE_MINUTES . ' minutes.'];
        if (!self::hit('reset:ip:' . sha1((string) $ip), 10, 3600) || !self::hit('reset:id:' . sha1($email), 3, 3600)) {
            return ['ok' => false, 'message' => 'Too many codes asked for. Please wait an hour, or message us on WhatsApp.'];
        }
        $u = DB::selectOne("SELECT id, name, status FROM users WHERE LOWER(email) = ? AND role <> 'admin'", [$email]);
        if ($u === null || $u['status'] !== 'active') {
            return $same;
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now = self::now();
        DB::update("UPDATE customer_auth_codes SET used_at = ? WHERE user_id = ? AND purpose = 'reset_password' AND used_at IS NULL", [$now, $u['id']]);
        DB::table('customer_auth_codes')->insert([
            'user_id' => (int) $u['id'], 'purpose' => 'reset_password', 'channel' => 'email',
            'code_hash' => password_hash($code, PASSWORD_DEFAULT), 'attempts' => 0,
            'expires_at' => date('Y-m-d H:i:s', time() + self::CODE_MINUTES * 60), 'ip' => $ip !== null ? mb_substr($ip, 0, 45) : null, 'created_at' => $now,
        ]);
        $shop = (string) business('name', 'Maa Sarada Marble & Stone');
        try {
            Mail::send($email, $shop . ': your password reset code', "Namaskar {$u['name']},\n\nYour code to reset your password is: {$code}\n\n"
                . 'It works for ' . self::CODE_MINUTES . " minutes. If you didn’t ask for it, you can ignore this email — your password stays the same.\n\n— {$shop}");
        } catch (\RuntimeException) {
            return ['ok' => false, 'mail_off' => true, 'message' => 'We couldn’t send the email just now. Try again in a few minutes, or message us on WhatsApp.'];
        }

        return $same;
    }

    /**
     * Forgot password, step 2: email + code + new password → password changed, other browsers
     * signed out, and this one signed in. Throws ValidationException.
     *
     * @param list<string> $savedSlugs
     */
    public static function resetPassword(string $email, string $code, string $new, ?string $ip = null, array $savedSlugs = []): array
    {
        $email = LoginId::email($email);
        $code = preg_replace('/\D+/', '', $code) ?? '';
        $bad = ['code' => ['That code isn’t right or has expired. Ask for a new one.']];
        if (($p = self::passwordProblem($new)) !== null) {
            throw new ValidationException(['password' => [$p]]);
        }
        $u = $email !== null ? DB::selectOne('SELECT id, status FROM users WHERE LOWER(email) = ?', [$email]) : null;
        $row = $u !== null ? DB::selectOne("SELECT * FROM customer_auth_codes WHERE user_id = ? AND purpose = 'reset_password' AND used_at IS NULL ORDER BY id DESC LIMIT 1", [$u['id']]) : null;
        if ($row === null || strlen($code) !== 6 || (string) $row['expires_at'] < self::now() || (int) $row['attempts'] >= self::CODE_TRIES || $u['status'] !== 'active') {
            throw new ValidationException($bad);
        }
        if (!password_verify($code, (string) $row['code_hash'])) {
            DB::update('UPDATE customer_auth_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
            throw new ValidationException($bad);
        }
        DB::update('UPDATE customer_auth_codes SET used_at = ? WHERE id = ?', [self::now(), $row['id']]);
        self::setPassword((int) $u['id'], $new);
        DB::table('users')->where('id', (int) $u['id'])->update(['email_verified_at' => self::now()]); // the code proved the email

        return self::login(self::find((int) $u['id']), false, $savedSlugs);
    }

    /** New password + bump session_version + drop every remember token (all other browsers sign out). */
    private static function setPassword(int $userId, string $new): void
    {
        DB::update('UPDATE users SET password = ?, session_version = session_version + 1, updated_at = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), self::now(), $userId]);
        DB::delete('DELETE FROM customer_remember_tokens WHERE user_id = ?', [$userId]);
    }

    public static function passwordProblem(string $p): ?string
    {
        if (mb_strlen($p) < self::MIN_PASSWORD) {
            return 'Use at least ' . self::MIN_PASSWORD . ' characters.';
        }
        if (strlen($p) > 72) {
            return 'That password is too long (72 characters at most).';
        }
        if (trim($p) === '') {
            return 'The password can’t be only spaces.';
        }

        return null;
    }

    /* ================================================================ profile */

    /**
     * Name, email, WhatsApp consent. The phone can't change here (it is the identity: "contact us").
     * Writes users + the linked customer together so admin and shop show the same name.
     */
    public static function updateProfile(array $u, array $in): void
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) ($in['name'] ?? '')) ?? '');
        $emailRaw = trim((string) ($in['email'] ?? ''));
        $email = $emailRaw === '' ? null : LoginId::email($emailRaw);
        $err = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $err['name'][] = 'Enter your full name.';
        }
        if ($emailRaw !== '' && $email === null) {
            $err['email'][] = 'Enter a valid email address, or leave it empty.';
        } elseif ($email !== null && DB::selectOne('SELECT id FROM users WHERE LOWER(email) = ? AND id <> ?', [$email, $u['id']]) !== null) {
            $err['email'][] = 'This email is already on another account.';
        }
        if ($err) {
            throw new ValidationException($err);
        }
        $now = self::now();
        $emailChanged = mb_strtolower((string) $u['email']) !== (string) $email;
        DB::transaction(static function () use ($u, $name, $email, $emailChanged, $in, $now): void {
            DB::table('users')->where('id', (int) $u['id'])->update(['name' => $name, 'email' => $email, 'updated_at' => $now]
                + ($emailChanged ? ['email_verified_at' => null] : []));
            if ($u['customer_id']) {
                DB::table('customers')->where('id', (int) $u['customer_id'])->update([
                    'name' => $name, 'email' => $email, 'marketing_consent' => !empty($in['marketing_consent']) ? 1 : 0, 'updated_at' => $now,
                ]);
            }
        });
    }

    /* ================================================================ helpers */

    private static function startSession(?array $u): void
    {
        if ($u === null) {
            return;
        }
        session()->regenerate();
        session()->put(self::SESSION, ['id' => (int) $u['id'], 'ver' => (int) $u['session_version']]);
    }

    private static function remember(int $userId): void
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $exp = time() + self::REMEMBER_DAYS * 86400;
        DB::table('customer_remember_tokens')->insert([
            'user_id' => $userId, 'selector' => $selector, 'validator_hash' => hash('sha256', $validator),
            'expires_at' => date('Y-m-d H:i:s', $exp), 'last_used_at' => self::now(),
            'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null, 'created_at' => self::now(),
        ]);
        $_COOKIE[self::COOKIE] = $selector . ':' . $validator;
        self::setCookie($selector . ':' . $validator, $exp);
    }

    private static function clearCookie(): void
    {
        unset($_COOKIE[self::COOKIE]);
        self::setCookie('', time() - 3600);
    }

    private static function setCookie(string $value, int $expires): void
    {
        if (!self::$sendCookie || PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $value, [
            'expires' => $expires, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
    }

    /** Count one try; false when the limit is already used up. */
    private static function hit(string $key, int $max, int $decay): bool
    {
        $l = self::limiter();
        if ($l === null) {
            return true;
        }
        if ($l->tooManyAttempts($key, $max)) {
            return false;
        }
        $l->hit($key, $decay);

        return true;
    }

    private static function limiter(): ?\Vayu\RateLimiting\RateLimiter
    {
        try {
            return app('rate_limiter');
        } catch (\Throwable) {
            return null;
        }
    }

    /** A safe place to go after signing in: a same-site path only (no //host, no scheme). */
    public static function safeNext(?string $next, string $fallback = '/account'): string
    {
        $n = trim((string) $next);
        if ($n === '' || $n[0] !== '/' || str_starts_with($n, '//') || str_contains($n, '\\') || preg_match('/[\x00-\x1f]/', $n)
            || preg_match('#^/(signin|register|signout|admin)\b#', $n)) {
            return $fallback;
        }

        return mb_substr($n, 0, 500);
    }

    private static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
