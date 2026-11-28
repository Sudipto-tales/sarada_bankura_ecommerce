<?php

namespace App\Services\Store;

/**
 * Requests from the Android app carry "MaaSaradaApp/<version>" in the user agent (docs/mobile-app.md).
 *
 * Page loads that go through the service worker lose that token (Android WebView sends its default
 * user agent for worker fetches, and Capacitor re-fetches them natively), so the layout also sets the
 * COOKIE below once it sees the Capacitor bridge, and that cookie counts as app mode too.
 */
final class AppMode
{
    public const UA_TOKEN = 'MaaSaradaApp';
    public const COOKIE = 'ms_app';

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
            self::$active = self::detect(is_string($ua) ? $ua : null) !== null || ($_COOKIE[self::COOKIE] ?? null) === '1';
        }

        return self::$active;
    }

    public static function force(?bool $on): void
    {
        self::$active = $on;
    }
}
