<?php

namespace App\Services\AdminAccess;

use Vayu\Database\DB;

/**
 * 6-digit password codes (docs/admin-access.md §4.5): email only for now. Valid 10 minutes, 5 wrong
 * tries, resend after 60 s, at most 5 codes an hour. Stored as HMAC-SHA256 (APP_KEY), never plain.
 * Only writer of admin_otps. A WhatsApp provider plugs in later as another OtpChannel.
 */
final class Otp
{
    public const TTL = 600;
    public const MAX_TRIES = 5;
    public const RESEND_AFTER = 60;
    public const PER_HOUR = 5;

    private static ?OtpChannel $channel = null;

    /** Swap the channel (tests; a future WhatsApp provider). */
    public static function useChannel(?OtpChannel $channel): void
    {
        self::$channel = $channel;
    }

    public static function channel(): OtpChannel
    {
        return self::$channel ??= new EmailOtpChannel();
    }

    /** Send a fresh code to the account. @throws AccessException */
    public static function send(int $userId, string $purpose): void
    {
        $u = Guard::find($userId);
        if ($u === null || $u['status'] === 'disabled') {
            throw new AccessException('This account can’t receive codes.');
        }
        $ch = self::channel();
        if (!$ch->canReach($u)) {
            throw new AccessException('Codes are sent by email and this account has no email. Ask an admin to reset your password.');
        }
        $wait = self::resendIn($userId, $purpose);
        if ($wait > 0) {
            throw new AccessException('A code was just sent. You can ask for a new one in ' . $wait . ' seconds.', $wait);
        }
        $hour = (int) (DB::selectOne('SELECT COUNT(*) AS n FROM admin_otps WHERE admin_user_id = ? AND created_at >= ?', [$userId, date('Y-m-d H:i:s', time() - 3600)])['n'] ?? 0);
        if ($hour >= self::PER_HOUR) {
            throw new AccessException('Too many codes asked for in the last hour. Please try again later.', 3600);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now = date('Y-m-d H:i:s');
        DB::update('UPDATE admin_otps SET used_at = ? WHERE admin_user_id = ? AND purpose = ? AND used_at IS NULL', [$now, $userId, $purpose]);
        $id = DB::table('admin_otps')->insertGetId([
            'admin_user_id' => $userId, 'code_hash' => self::hash($code), 'channel' => $ch->name(), 'purpose' => $purpose,
            'attempts' => 0, 'expires_at' => date('Y-m-d H:i:s', time() + self::TTL), 'created_at' => $now,
        ]);
        try {
            $ch->send($u, $code, $purpose);
        } catch (\RuntimeException $e) {
            DB::delete('DELETE FROM admin_otps WHERE id = ?', [$id]);   // a failed send doesn't use up the hourly quota
            throw new AccessException('We couldn’t send the code: ' . $e->getMessage());
        }
    }

    /** Check (and use up) a code. @throws AccessException */
    public static function verify(int $userId, string $purpose, string $code): void
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        $row = DB::selectOne('SELECT * FROM admin_otps WHERE admin_user_id = ? AND purpose = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1', [$userId, $purpose]);
        if ($row === null) {
            throw new AccessException('Ask for a code first (or ask for a new one).');
        }
        if ((string) $row['expires_at'] < date('Y-m-d H:i:s')) {
            throw new AccessException('That code has expired (codes last 10 minutes). Ask for a new one.');
        }
        if ((int) $row['attempts'] >= self::MAX_TRIES) {
            throw new AccessException('Too many wrong codes. Ask for a new one.');
        }
        if (strlen($code) !== 6 || !hash_equals((string) $row['code_hash'], self::hash($code))) {
            DB::update('UPDATE admin_otps SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
            $left = self::MAX_TRIES - (int) $row['attempts'] - 1;
            throw new AccessException($left > 0
                ? 'That code is not correct. ' . $left . ' tr' . ($left === 1 ? 'y' : 'ies') . ' left.'
                : 'Too many wrong codes. Ask for a new one.');
        }
        DB::update('UPDATE admin_otps SET used_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $row['id']]);
    }

    /** Seconds before another code may be sent (0 = now). */
    public static function resendIn(int $userId, string $purpose): int
    {
        $last = DB::selectOne('SELECT created_at FROM admin_otps WHERE admin_user_id = ? AND purpose = ? ORDER BY id DESC LIMIT 1', [$userId, $purpose]);

        return $last ? max(0, strtotime((string) $last['created_at']) + self::RESEND_AFTER - time()) : 0;
    }

    private static function hash(string $code): string
    {
        $key = (string) (config('app.key') ?: 'vayu-admin-otp');

        return hash_hmac('sha256', $code, $key);
    }

    /** "su•••@gmail.com" for screens. */
    public static function maskEmail(?string $email): string
    {
        $e = (string) $email;
        $at = strpos($e, '@');
        if ($at === false) {
            return '';
        }

        return mb_substr($e, 0, min(2, $at)) . '•••' . substr($e, $at);
    }
}
