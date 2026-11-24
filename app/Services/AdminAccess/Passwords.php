<?php

namespace App\Services\AdminAccess;

/** Temporary passwords + the password rule (min 8, max 72 bytes for bcrypt, confirmed). */
final class Passwords
{
    // No look-alikes: 0/O/o, 1/l/I are left out.
    private const LOWER = 'abcdefghjkmnpqrstuvwxyz';
    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const DIGIT = '23456789';

    public const MIN = 8;

    /** Random, easy to type: 10 chars with at least one lower, one upper and two digits. */
    public static function temporary(int $length = 10): string
    {
        $pick = static fn(string $set): string => $set[random_int(0, strlen($set) - 1)];
        $chars = [$pick(self::UPPER), $pick(self::DIGIT), $pick(self::DIGIT)];
        while (count($chars) < $length) {
            $chars[] = $pick(self::LOWER);
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {          // Fisher–Yates with a CSPRNG
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    public static function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    /**
     * Checks a new password; returns an error message or null.
     * $currentHash: the new one may not be the same as the current (or temporary) password.
     */
    public static function problem(string $new, string $confirm, ?string $currentHash = null): ?string
    {
        if (mb_strlen($new) < self::MIN) {
            return 'Use at least ' . self::MIN . ' characters.';
        }
        if (strlen($new) > 72) {
            return 'That password is too long (72 characters at most).';
        }
        if (trim($new) === '') {
            return 'The password can’t be only spaces.';
        }
        if (!hash_equals($new, $confirm)) {
            return 'The two passwords don’t match.';
        }
        if ($currentHash !== null && password_verify($new, $currentHash)) {
            return 'Choose a new password — this is the same as the current one.';
        }

        return null;
    }
}
