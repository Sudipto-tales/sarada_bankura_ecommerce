<?php

namespace App\Services\Customer;

/** Indian mobile numbers: the customer identity is the bare 10 digits (no +91 / leading 0). */
final class Phone
{
    public static function normalize(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && $digits[0] === '0') {
            $digits = substr($digits, 1);
        }

        return preg_match('/^[6-9]\d{9}$/', $digits) ? $digits : null;
    }

    /** 9830012345 → "98300 12345" (display only). */
    public static function format(?string $phone): string
    {
        $p = (string) $phone;

        return strlen($p) === 10 ? substr($p, 0, 5) . ' ' . substr($p, 5) : $p;
    }
}
