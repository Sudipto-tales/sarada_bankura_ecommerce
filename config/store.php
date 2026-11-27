<?php

/**
 * Storefront switches (docs/storefront.md). Read with config('store.enabled').
 *
 *   enabled      false → every storefront page shows the coming-soon page (owner default until S4
 *                is verified on MySQL). A logged-in admin still sees the real storefront (preview bar).
 *   launch_at    countdown target on the coming-soon page (IST).
 *   cache_ttl    seconds the header/footer menu data (Catalog::chrome) is cached.
 *   app_play_url the Google Play listing (docs/mobile-app.md). Empty until the app is live on Play:
 *                /app then offers only the direct APK, and the banner shows only when a release exists.
 */
return [
    'enabled' => filter_var(env('STOREFRONT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'launch_at' => env('STOREFRONT_LAUNCH_AT', '2026-11-25T00:00:00'),
    'cache_ttl' => (int) env('STOREFRONT_CACHE_TTL', 600),
    'hours' => env('BUSINESS_HOURS', 'Mon–Sat · 10:00–20:00 IST'),
    'app_play_url' => env('APP_PLAY_URL', ''),
];
