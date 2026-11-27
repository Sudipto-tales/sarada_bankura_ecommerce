<?php

namespace App\Services\Push;

use Vayu\Database\DB;

/**
 * Push devices (docs/mobile-app.md phase 4). The only writer of app_devices.
 *
 * The app registers its FCM token after the customer signs in (and on every launch while signed
 * in, which keeps last_seen_at fresh and follows token rotation). A token moves to whoever
 * registered it last, so a shared phone never gets the previous customer's order updates.
 * The token is also kept in the session, so signing out removes exactly this phone.
 */
final class Devices
{
    public const SESSION = 'app_push_token';
    public const MAX_PER_USER = 10;
    public const MAX_FAILURES = 5;

    /** FCM registration tokens: base64url-ish with ':' separators, ~140–200 characters. */
    public static function validToken(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_:\-]{32,255}$/', $token);
    }

    public static function register(int $userId, ?int $customerId, string $token, ?string $appVersion = null): void
    {
        $now = date('Y-m-d H:i:s');
        $version = $appVersion !== null && preg_match('/^[0-9A-Za-z.\-]{1,20}$/', $appVersion) ? $appVersion : null;
        $row = DB::selectOne('SELECT id FROM app_devices WHERE token = ?', [$token]);
        if ($row) {
            DB::update('UPDATE app_devices SET user_id = ?, customer_id = ?, app_version = ?, last_seen_at = ?, failures = 0, updated_at = ? WHERE id = ?',
                [$userId, $customerId, $version, $now, $now, (int) $row['id']]);
        } else {
            DB::table('app_devices')->insert(['user_id' => $userId, 'customer_id' => $customerId, 'token' => $token, 'platform' => 'android',
                'app_version' => $version, 'last_seen_at' => $now, 'failures' => 0, 'created_at' => $now, 'updated_at' => $now]);
        }
        // Old phones of the same customer age out instead of piling up.
        $keep = array_column(DB::select('SELECT id FROM app_devices WHERE user_id = ? ORDER BY last_seen_at DESC, id DESC LIMIT ' . self::MAX_PER_USER, [$userId]), 'id');
        if (count($keep) === self::MAX_PER_USER) {
            DB::delete('DELETE FROM app_devices WHERE user_id = ? AND id NOT IN (' . implode(',', array_map('intval', $keep)) . ')', [$userId]);
        }
        try {
            session()->put(self::SESSION, $token);
        } catch (\Throwable) {
            // CLI / tests without a session
        }
    }

    /**
     * Short hash of the token this session registered ('' = none yet). The layout prints it so the
     * app re-sends its token after a sign-out / sign-in or a remember-me login (a new session),
     * and skips the POST on every other page.
     */
    public static function sessionTag(): string
    {
        try {
            $token = session()->get(self::SESSION);
        } catch (\Throwable) {
            return '';
        }

        return is_string($token) && $token !== '' ? self::tag($token) : '';
    }

    public static function tag(string $token): string
    {
        return substr(hash('sha256', $token), 0, 16);
    }

    public static function forgetToken(string $token): void
    {
        DB::delete('DELETE FROM app_devices WHERE token = ?', [$token]);
    }

    /** Sign-out: this phone stops getting the customer's updates. */
    public static function forgetSessionDevice(): void
    {
        try {
            $token = session()->get(self::SESSION);
            session()->forget(self::SESSION);
        } catch (\Throwable) {
            return;
        }
        if (is_string($token) && $token !== '') {
            self::forgetToken($token);
        }
    }

    /** Account deletion: every phone of this login. */
    public static function forgetUser(int $userId): void
    {
        DB::delete('DELETE FROM app_devices WHERE user_id = ?', [$userId]);
    }

    /**
     * Tokens of the customer's signed-in phones. Also matched through the login linked to the
     * customer (customers.user_id): a phone often registers before the first checkout creates the
     * customer record, so its row has customer_id NULL until the next app session.
     *
     * @return list<string>
     */
    public static function tokensForCustomer(int $customerId): array
    {
        return array_column(DB::select('SELECT token FROM app_devices WHERE customer_id = ?'
            . ' OR user_id IN (SELECT user_id FROM customers WHERE id = ? AND user_id IS NOT NULL)'
            . ' ORDER BY last_seen_at DESC LIMIT ' . self::MAX_PER_USER, [$customerId, $customerId]), 'token');
    }

    /** FCM said the token is gone (app uninstalled / data cleared). */
    public static function prune(string $token): void
    {
        self::forgetToken($token);
    }

    /** A temporary failure; drop the token after a run of them. */
    public static function failed(string $token): void
    {
        DB::update('UPDATE app_devices SET failures = failures + 1, updated_at = ? WHERE token = ?', [date('Y-m-d H:i:s'), $token]);
        DB::delete('DELETE FROM app_devices WHERE token = ? AND failures >= ?', [$token, self::MAX_FAILURES]);
    }

    public static function countFor(int $userId): int
    {
        return (int) (DB::selectOne('SELECT COUNT(*) AS n FROM app_devices WHERE user_id = ?', [$userId])['n'] ?? 0);
    }
}
