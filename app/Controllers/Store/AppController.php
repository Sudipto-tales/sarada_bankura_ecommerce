<?php

namespace App\Controllers\Store;

use App\Http\FileResponse;
use App\Services\AppReleases;
use App\Services\Push\Devices;
use App\Services\Store\AppMode;
use App\Services\Store\Context;
use App\Services\Store\View;
use Vayu\Http\Request;
use Vayu\Http\Response;

/**
 * The Android app on the storefront (docs/mobile-app.md phase 3).
 *
 *   GET /app                Play badge + direct APK, version, changelog, SHA-256, install steps, QR
 *   GET /app/download       streams the published APK and counts it (outside StoreOpen)
 *   GET /app/version   update check for sideloaded installs (outside StoreOpen, never cached)
 *   POST /app/devices       phase 4: the app's FCM token for the signed-in customer (push)
 *   GET /.well-known/assetlinks.json  phase 4: App Links (outside StoreOpen)
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

    /** POST /app/devices {token} (signed in, from the app) → JSON {ok}. */
    public function registerDevice(Request $request): Response
    {
        $u = Context::customer();
        $token = trim((string) $request->input('token', ''));
        if ($u === null || !Devices::validToken($token)) {
            return json(['ok' => false, 'message' => 'Invalid device token.'], 422);
        }
        $ua = $request->header('User-Agent');
        Devices::register((int) $u['id'], $u['customer_id'] !== null ? (int) $u['customer_id'] : null, $token,
            AppMode::detect(is_string($ua) ? $ua : null));

        return json(['ok' => true]);
    }

    /**
     * Digital Asset Links for Android App Links: lets shop links open in the app. Lists the signing
     * certificate fingerprints from APP_CERT_SHA256 (Play Console ▸ App integrity); empty list = off.
     */
    public function assetLinks(): Response
    {
        $prints = [];
        foreach (explode(',', (string) config('store.app_cert_sha256', '')) as $f) {
            $f = strtoupper(trim($f));
            if (preg_match('/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/', $f)) {
                $prints[] = $f;
            }
        }
        $body = $prints === [] ? [] : [[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => ['namespace' => 'android_app', 'package_name' => AppReleases::PACKAGE, 'sha256_cert_fingerprints' => $prints],
        ]];

        return (new Response())->json($body, 200, ['Cache-Control' => 'public, max-age=3600']);
    }
}
