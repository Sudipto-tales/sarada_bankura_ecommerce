<?php

namespace App\Controllers\Store;

use App\Http\FileResponse;
use App\Services\AppReleases;
use App\Services\Store\View;
use Vayu\Http\Response;

/**
 * The Android app on the storefront (docs/mobile-app.md phase 3).
 *
 *   GET /app                Play badge + direct APK, version, changelog, SHA-256, install steps, QR
 *   GET /app/download       streams the published APK and counts it (outside StoreOpen)
 *   GET /app/version   update check for sideloaded installs (outside StoreOpen, never cached)
 */
class AppController
{
    public function page(): Response
    {
        View::loadHelpers();
        return View::page('store.app', [
            'release' => AppReleases::latest(),
            'playUrl' => AppReleases::playUrl(),
        ], [
            'title' => 'Get the app',
            'active' => 'app',
            'description' => 'The ' . business('name') . ' Android app: browse stone, save favourites, order and track deliveries.',
            'canonical' => base_url('/app'),
            'scripts' => '<script src="' . e(store_asset('vendor/qrcode.min.js')) . '" defer></script>',
        ]);
    }

    public function download(): Response
    {
        $r = AppReleases::latest();
        if ($r === null || !is_file(AppReleases::path($r))) {
            return redirect(base_url('/app'));
        }
        AppReleases::countDownload((int) $r['id']);

        return new FileResponse(AppReleases::path($r), AppReleases::fileName($r), AppReleases::MIME, [
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    public function version(): Response
    {
        return (new Response())->json(AppReleases::versionInfo(), 200, [
            'Cache-Control' => 'no-store',
            'Access-Control-Allow-Origin' => '*',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
