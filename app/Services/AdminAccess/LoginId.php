<?php

namespace App\Services\AdminAccess;

/**
 * "Email or mobile number" login IDs: contains @ = email (lower-cased), otherwise a 10-digit
 * Indian mobile (+91, spaces, dashes and a leading 0 are stripped).
 */
final class LoginId
{
    /** @return array{0:'email'|'phone'|null,1:string} [kind, normalised value]; kind null = not usable */
    public static function parse(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [null, ''];
        }
        if (str_contains($raw, '@')) {
            $email = self::email($raw);

            return [$email === null ? null : 'email', $email ?? mb_strtolower($raw)];
        }
        $phone = self::phone($raw);

        return [$phone === null ? null : 'phone', $phone ?? preg_replace('/\D+/', '', $raw)];
    }

    public static function email(?string $raw): ?string
    {
        $v = mb_strtolower(trim((string) $raw));

        return $v !== '' && mb_strlen($v) <= 190 && filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : null;
    }

    public static function phone(?string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $raw) ?? '';
        if (strlen($d) === 12 && str_starts_with($d, '91')) {
            $d = substr($d, 2);
        } elseif (strlen($d) === 11 && str_starts_with($d, '0')) {
            $d = substr($d, 1);
        }

        return preg_match('/^[6-9]\d{9}$/', $d) ? $d : null;
    }

    /** "98300 12345" for display. */
    public static function showPhone(?string $phone): string
    {
        $p = (string) $phone;

        return strlen($p) === 10 ? substr($p, 0, 5) . ' ' . substr($p, 5) : $p;
    }
}
