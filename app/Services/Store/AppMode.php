<?php

namespace App\Services\Store;

/** Requests from the Android app carry "MaaSaradaApp/<version>" in the user agent (docs/mobile-app.md). */
final class AppMode
{
    public const UA_TOKEN = 'MaaSaradaApp';

    private static ?bool $active = null;

    public static function detect(?string $userAgent): ?string
    {
        if ($userAgent === null || !preg_match('#\b' . self::UA_TOKEN . '/([0-9A-Za-z.\-]{1,20})#', $userAgent, $m)) {
            return null;
        }

        return $m[1];
    }

    public static function active(): bool
    {
        if (self::$active === null) {
            try {
                $ua = request()->header('User-Agent');
            } catch (\Throwable) {
                $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
            }
            self::$active = self::detect(is_string($ua) ? $ua : null) !== null;
        }

        return self::$active;
    }

    public static function force(?bool $on): void
    {
        self::$active = $on;
    }
}
