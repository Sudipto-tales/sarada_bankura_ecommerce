<?php

namespace App\Services\AdminAccess;

use Vayu\Database\DB;

/**
 * Admin login session (docs/admin-access.md §4.4). Only writer of admin_login_attempts and
 * admin_remember_tokens.
 *
 * - Login ID = email or 10-digit mobile. 5 wrong tries for one account (email + phone together)
 *   within 15 min → that account is locked for 15 min; 10 wrong tries from one IP within 15 min → that IP is locked (a shop shares
 *   one Wi-Fi, so the IP limit is a little looser). Uses REMOTE_ADDR, never X-Forwarded-For.
 * - Session: id regenerated on login, idle logout after 2 h, session_version on the account
 *   ("log out everywhere" = bump it). Optional "Remember this device" cookie (30 days, rotated on use).
 */
final class Guard
{
    public const IDLE_SECONDS = 7200;
    public const REMEMBER_DAYS = 30;
    public const COOKIE = 'ms_admin_remember';
    public const MAX_PER_ID = 5;
    public const MAX_PER_IP = 10;
    public const WINDOW = 900;          // 15 min to count failures …
    public const LOCK = 900;            // … and 15 min locked

    private static ?array $user = null;
    private static bool $resolved = false;

    /* ------------------------------------------------------------ current user */

    /** The logged-in staff row (from the session, else a valid remember cookie), or null. */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;
        self::$user = self::fromSession() ?? self::fromRememberCookie();

        return self::$user;
    }

    public static function id(): ?int
    {
        $u = self::user();

        return $u ? (int) $u['id'] : null;
    }

    public static function can(string $permission): bool
    {
        $id = self::id();

        return $id !== null && Roles::can($id, $permission);
    }

    /** Forget the per-request cache (tests; after the account row changes). */
    public static function forget(): void
    {
        self::$user = null;
        self::$resolved = false;
        Roles::flush();
    }

    private static function fromSession(): ?array
    {
        $s = session()->get('admin');
        if (!is_array($s) || empty($s['id'])) {
            return null;
        }
        $u = self::find((int) $s['id']);
        $idle = time() - (int) ($s['last'] ?? 0) > self::IDLE_SECONDS;
        if ($u === null || $idle || (int) $u['session_version'] !== (int) ($s['ver'] ?? 0) || self::refusal($u) !== null) {
            session()->forget('admin');

            return null;
        }
        $s['last'] = time();
        session()->put('admin', $s);

        return $u;
    }

    private static function fromRememberCookie(): ?array
    {
        $raw = (string) ($_COOKIE[self::COOKIE] ?? '');
        if ($raw === '' || !ctype_xdigit($raw) || strlen($raw) !== 64) {
            return null;
        }
        $row = DB::selectOne('SELECT * FROM admin_remember_tokens WHERE token_hash = ?', [hash('sha256', $raw)]);
        $u = $row ? self::find((int) $row['admin_user_id']) : null;
        if ($row === null || $u === null || (string) $row['expires_at'] < self::now() || self::refusal($u) !== null) {
            if ($row !== null) {
                DB::delete('DELETE FROM admin_remember_tokens WHERE id = ?', [$row['id']]);
            }
            self::clearCookie();

            return null;
        }
        // Rotate: one cookie = one use, so a copied cookie stops working after the next visit.
        DB::delete('DELETE FROM admin_remember_tokens WHERE id = ?', [$row['id']]);
        self::startSession($u);
        self::remember((int) $u['id']);
        self::record((string) ($u['email'] ?? $u['phone']), (int) $u['id'], true, 'remember');

        return $u;
    }

    /* ------------------------------------------------------------ login / logout */

    /**
     * Check the login ID + password and start the session. Returns the account row.
     * @throws AccessException with a message safe to show (and retryAfter seconds when locked)
     */
    public static function attempt(string $loginId, string $password, bool $remember = false): array
    {
        [$kind, $id] = LoginId::parse($loginId);
        if ($id === '' || $password === '') {
            throw new AccessException('Enter your email or mobile number and your password.');
        }
        $ip = self::ip();
        $u = $kind === null ? null : DB::selectOne('SELECT * FROM admin_users WHERE ' . ($kind === 'email' ? 'email' : 'phone') . ' = ?', [$id]);
        $uid = $u ? (int) $u['id'] : null;
        $wait = self::lockedFor($id, $ip, $uid);
        if ($wait > 0) {
            self::record($id, $uid, false, 'locked');
            throw new AccessException(self::lockMessage($wait), $wait);
        }
        if ($u === null) {
            password_verify($password, '$2y$10$pgO7Vivh9qYTMAveJEl3Te71J3ZFLac8WdqC1ad5.3ZMXS2yZZvgq'); // same timing as a real check
            self::record($id, null, false, 'unknown');
            self::failAfterRecord($id, $ip, null);
        }
        if (!password_verify($password, (string) $u['password'])) {
            self::record($id, $uid, false, 'wrong_password');
            self::failAfterRecord($id, $ip, $uid);
        }
        // Only after the right password: say why the account can't log in (no status leak to guessers).
        $why = self::refusal($u);
        if ($why !== null) {
            self::record($id, (int) $u['id'], false, $u['status'] === 'disabled' ? 'disabled' : 'expired');
            throw new AccessException($why);
        }

        if (password_needs_rehash((string) $u['password'], PASSWORD_DEFAULT)) {
            DB::update('UPDATE admin_users SET password = ? WHERE id = ?', [Passwords::hash($password), $u['id']]);
        }
        $now = self::now();
        DB::update('UPDATE admin_users SET last_login_at = ?, last_login_ip = ?, updated_at = ? WHERE id = ?', [$now, $ip, $now, $u['id']]);
        self::record($id, (int) $u['id'], true, 'ok');
        $u = self::find((int) $u['id']);
        self::startSession($u);
        if ($remember) {
            self::remember((int) $u['id']);
        }

        return $u;
    }

    private static function failAfterRecord(string $id, ?string $ip, ?int $uid): never
    {
        $wait = self::lockedFor($id, $ip, $uid);
        if ($wait > 0) {
            throw new AccessException(self::lockMessage($wait), $wait);
        }
        throw new AccessException('Wrong login ID or password.');
    }

    /** Log out this browser (and forget this device). */
    public static function logout(): void
    {
        $raw = (string) ($_COOKIE[self::COOKIE] ?? '');
        if ($raw !== '') {
            DB::delete('DELETE FROM admin_remember_tokens WHERE token_hash = ?', [hash('sha256', $raw)]);
            self::clearCookie();
        }
        session()->forget('admin');
        session()->regenerate();
        self::forget();
    }

    /**
     * Log out every other session/device of this account (password changed, "log out other devices").
     * The current browser stays logged in when it belongs to $userId.
     */
    public static function logoutOthers(int $userId): void
    {
        DB::update('UPDATE admin_users SET session_version = session_version + 1 WHERE id = ?', [$userId]);
        $keep = (string) ($_COOKIE[self::COOKIE] ?? '');
        $mine = self::current($userId);
        DB::delete('DELETE FROM admin_remember_tokens WHERE admin_user_id = ? AND token_hash <> ?', [$userId, $mine && $keep !== '' ? hash('sha256', $keep) : '-']);
        if ($mine) {
            $s = (array) session()->get('admin');
            $s['ver'] = (int) DB::selectOne('SELECT session_version FROM admin_users WHERE id = ?', [$userId])['session_version'];
            session()->put('admin', $s);
        }
        self::forget();
    }

    /** Log out every session/device of this account, including a current one (admin reset, disable). */
    public static function logoutEverywhere(int $userId): void
    {
        DB::update('UPDATE admin_users SET session_version = session_version + 1 WHERE id = ?', [$userId]);
        DB::delete('DELETE FROM admin_remember_tokens WHERE admin_user_id = ?', [$userId]);
        self::forget();
    }

    /* ------------------------------------------------------------ rules */

    /** Why this (password-checked) account may not be logged in, or null. */
    public static function refusal(array $u): ?string
    {
        if ($u['status'] === 'disabled') {
            return 'This account is switched off. Ask the shop owner if you still need access.';
        }
        if ((int) $u['must_change_password'] === 1 && !empty($u['temp_password_expires_at']) && (string) $u['temp_password_expires_at'] < self::now()) {
            return $u['status'] === 'invited'
                ? 'Your invite has expired (links and temporary passwords last 72 hours). Ask an admin to resend it.'
                : 'Your temporary password has expired (it lasts 72 hours). Ask an admin to reset it again.';
        }

        return null;
    }

    /**
     * Seconds until this login may be tried again (0 = not locked). Counted per account when the
     * login ID belongs to one (email and phone share the count), else per typed login ID; and per IP.
     */
    public static function lockedFor(string $loginId, ?string $ip, ?int $userId = null): int
    {
        $since = date('Y-m-d H:i:s', time() - self::WINDOW - self::LOCK);
        [$col, $key] = $userId !== null ? ['admin_user_id', $userId] : ['login_id', $loginId];
        $lastOk = (int) (DB::selectOne("SELECT MAX(id) AS id FROM admin_login_attempts WHERE {$col} = ? AND success = 1", [$key])['id'] ?? 0);
        $byId = DB::select("SELECT created_at FROM admin_login_attempts WHERE {$col} = ? AND success = 0 AND reason IN ('wrong_password','unknown')
            AND created_at >= ? AND id > ? ORDER BY id DESC LIMIT " . self::MAX_PER_ID, [$key, $since, $lastOk]);
        $wait = self::waitFrom($byId, self::MAX_PER_ID);
        if ($ip !== null && $ip !== '') {
            $byIp = DB::select("SELECT created_at FROM admin_login_attempts WHERE ip = ? AND success = 0 AND reason IN ('wrong_password','unknown')
                AND created_at >= ? ORDER BY id DESC LIMIT " . self::MAX_PER_IP, [$ip, $since]);
            $wait = max($wait, self::waitFrom($byIp, self::MAX_PER_IP));
        }

        return $wait;
    }

    /** $rows newest first: locked when $max failures fall within WINDOW; lock runs LOCK s from the newest. */
    private static function waitFrom(array $rows, int $max): int
    {
        if (count($rows) < $max) {
            return 0;
        }
        $newest = strtotime((string) $rows[0]['created_at']);
        $oldest = strtotime((string) $rows[$max - 1]['created_at']);
        if ($newest - $oldest > self::WINDOW) {
            return 0;
        }

        return max(0, $newest + self::LOCK - time());
    }

    public static function lockMessage(int $seconds): string
    {
        $min = max(1, (int) ceil($seconds / 60));

        return 'Too many wrong tries. For safety, login is paused — try again in ' . $min . ' minute' . ($min === 1 ? '' : 's') . '.';
    }

    /* ------------------------------------------------------------ helpers */

    public static function find(int $id): ?array
    {
        return DB::selectOne('SELECT * FROM admin_users WHERE id = ?', [$id]);
    }

    /** Is the current browser logged in as $userId? */
    private static function current(int $userId): bool
    {
        $s = session()->get('admin');

        return is_array($s) && (int) ($s['id'] ?? 0) === $userId;
    }

    private static function startSession(array $u): void
    {
        session()->regenerate();
        session()->put('admin', ['id' => (int) $u['id'], 'ver' => (int) $u['session_version'], 'last' => time(), 'at' => time()]);
        self::$user = $u;
        self::$resolved = true;
        Roles::flush();
    }

    private static function remember(int $userId): void
    {
        $raw = bin2hex(random_bytes(32));
        $exp = time() + self::REMEMBER_DAYS * 86400;
        DB::table('admin_remember_tokens')->insert([
            'admin_user_id' => $userId, 'token_hash' => hash('sha256', $raw),
            'device' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown device'), 0, 120),
            'expires_at' => date('Y-m-d H:i:s', $exp), 'last_used_at' => self::now(), 'created_at' => self::now(),
        ]);
        self::setCookie($raw, $exp);
        $_COOKIE[self::COOKIE] = $raw;
    }

    private static function setCookie(string $value, int $expires): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $value, [
            'expires' => $expires, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
    }

    private static function clearCookie(): void
    {
        unset($_COOKIE[self::COOKIE]);
        self::setCookie('', time() - 3600);
    }

    private static function record(string $loginId, ?int $userId, bool $ok, string $reason): void
    {
        DB::table('admin_login_attempts')->insert([
            'login_id' => mb_substr($loginId, 0, 190), 'admin_user_id' => $userId, 'ip' => self::ip(),
            'success' => $ok ? 1 : 0, 'reason' => $reason,
            'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
            'created_at' => self::now(),
        ]);
    }

    /** The real peer address (X-Forwarded-For is client-controlled, so it is not trusted here). */
    public static function ip(): ?string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return $ip === '' ? null : mb_substr($ip, 0, 45);
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
