<?php

namespace App\Controllers\Store;

use App\Services\Store\View;
use Vayu\Http\Response;

/** Service worker + offline page (docs/mobile-app.md). */
class AppShellController
{
    public const PRECACHE = ['css/store.css', 'js/store.js', 'vendor/lucide/lucide-0.544.0.min.js', 'image/logo.webp'];
    public const FONTS = ['manrope-latin.woff2', 'fraunces-latin.woff2'];

    public function worker(): Response
    {
        View::loadHelpers();
        $template = app()->basePath('resources/sw/sw.js');
        $offline = store_url('/offline');
        $urls = array_map('store_asset', self::PRECACHE);
        foreach (self::FONTS as $f) {
            $urls[] = base_url('assets/vendor/fonts/' . $f);
        }
        $urls[] = $offline;

        $stamp = [filemtime($template), filemtime(app()->basePath('resources/views/store/offline.php'))];
        foreach (self::PRECACHE as $p) {
            $stamp[] = @filemtime(app()->publicPath('assets/' . $p)) ?: 0;
        }
        $version = substr(hash('sha256', implode('|', $stamp) . '|' . implode('|', $urls)), 0, 12);

        $js = strtr((string) file_get_contents($template), [
            '__VERSION__' => $version,
            '__PRECACHE__' => json_encode(array_values($urls), JSON_UNESCAPED_SLASHES),
            '__OFFLINE__' => json_encode($offline, JSON_UNESCAPED_SLASHES),
        ]);

        return new Response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    public function offline(): Response
    {
        return View::page('store.offline', [], ['title' => 'Offline', 'chrome' => false, 'head' => '<meta name="robots" content="noindex">'])
            ->header('Cache-Control', 'no-cache');
    }
}
