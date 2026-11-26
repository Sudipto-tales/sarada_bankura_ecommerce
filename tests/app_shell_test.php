<?php

use App\Services\Store\AppMode;
use App\Services\Store\Cart;
use App\Services\Store\Context;
use App\Services\Store\View;
use Vayu\Application;
use Vayu\Http\Request;

/** Android app phase 1 (docs/mobile-app.md): app mode, glass tab bar, service worker, offline page, skeleton shop grid. */
return function (Assert $assert): void {
    $app = new Application(dirname(__DIR__));
    $app->config()->set('database.default', 'sqlite');
    $app->config()->set('database.connections.sqlite.database', ':memory:');
    $app->config()->set('cache.default', 'array');
    $app->config()->set('store.cache_ttl', 0);
    $app->config()->set('store.enabled', true);
    $router = $app->router();
    $router->aliasMiddleware('throttle', \Vayu\Middleware\ThrottleRequests::class);
    require dirname(__DIR__) . '/routes/web.php';
    $files = glob(dirname(__DIR__) . '/database/migrations/2026_*.php');
    sort($files);
    foreach ($files as $f) {
        ob_start();
        (require $f)->up();
        ob_end_clean();
    }
    View::loadHelpers();
    Cart::$sendCookie = false;
    $_COOKIE = [];
    Cart::reset();
    Context::reset();
    session()->start();
    $req = static fn(string $path): Request => new Request('GET', $path, [], [], [], ['REMOTE_ADDR' => '10.0.9.1'], [], null);

    $assert->equals('detect: app user agent gives its version', '1.2.0', AppMode::detect('Mozilla/5.0 (Linux; Android 15) Chrome/131 Mobile MaaSaradaApp/1.2.0'));
    $assert->that('detect: browsers and lookalikes are not the app', AppMode::detect('Mozilla/5.0 Chrome/131') === null && AppMode::detect(null) === null
        && AppMode::detect('XMaaSaradaApp/1') === null);

    AppMode::force(false);
    $html = $router->dispatch($req('/brands'))->getContent();
    $assert->that('web: tab bar with 5 tabs, no is-app class, zoom allowed', substr_count($html, 'class="tab-i"') === 5
        && !str_contains($html, 'class="is-app"') && !str_contains($html, 'user-scalable=no') && str_contains($html, 'viewport-fit=cover'));
    $assert->that('web: utility bar + full footer shown, worker URL on <html>', str_contains($html, 'Delivery included in every price')
        && str_contains($html, 'data-sw="') && str_contains($html, 'Track your order'));
    $assert->that('web: speculation rules keep checkout, pay, account and admin out', str_contains($html, 'type="speculationrules"')
        && str_contains($html, '/checkout*') && str_contains($html, '/admin*') && str_contains($html, '/account*'));
    $assert->that('tab bar: Sign in tab for guests, offline pill present', str_contains($html, '>Sign in</span>') && str_contains($html, 'id="netBar"'));

    AppMode::force(true);
    $html = $router->dispatch($req('/brands'))->getContent();
    $assert->that('app: is-app class, no zoom, pull to refresh', str_contains($html, 'class="is-app"') && str_contains($html, 'user-scalable=no') && str_contains($html, 'id="ptr"'));
    $assert->that('app: no utility bar, compact footer, header bag/saved hidden', !str_contains($html, 'Delivery included in every price')
        && str_contains($html, 'Visit showroom') && !str_contains($html, 'Track your order') && str_contains($html, 'app-hide relative'));
    $assert->that('app: brands tab not marked, still 5 tabs', substr_count($html, 'class="tab-i"') === 5 && !str_contains($html, 'class="tab-i" aria-current'));

    $html = $router->dispatch($req('/saved'))->getContent();
    $assert->that('tab bar: active tab marked', (bool) preg_match('#href="[^"]*/saved" class="tab-i" aria-current="page"#', $html));
    AppMode::force(null);

    $res = $router->dispatch($req('/service-worker'));
    $js = $res->getContent();
    $headers = array_change_key_case($res->getHeaders());
    $assert->that('worker: javascript, revalidated every time', str_contains((string) ($headers['content-type'] ?? ''), 'javascript')
        && ($headers['cache-control'] ?? '') === 'no-cache');
    $assert->that('worker: placeholders filled (version, precache, offline)', !str_contains($js, '__VERSION__') && !str_contains($js, '__PRECACHE__')
        && !str_contains($js, '__OFFLINE__') && (bool) preg_match("#const VERSION = '[0-9a-f]{12}'#", $js));
    $assert->that('worker: precaches the stylesheet, script and offline page', str_contains($js, 'css/store.css?v=') && str_contains($js, 'js/store.js?v=')
        && str_contains($js, '/offline"'));
    $assert->that('worker: never caches checkout, pay, cart, account, sign-in, admin', (bool) preg_match('#const NEVER = /\^\\\\/\(checkout\|pay\|order\|cart\|account\|signin\|signout\|register\|admin#', $js));
    $assert->equals('worker: same version on every request', $js, $router->dispatch($req('/service-worker'))->getContent());

    // Skeleton shop grid: the grid carries the shop base for store.js; the placeholders are styled in the build.
    $now = date('Y-m-d H:i:s');
    $pid = (int) \Vayu\Database\DB::table('products')->insertGetId(['product_type_id' => 1, 'status' => 'active', 'sku' => 'SKU-SKEL', 'name' => 'Skel Marble',
        'slug' => 'skel-marble', 'brand_id' => 0, 'is_enquiry_only' => 0, 'show_price' => 1, 'is_featured' => 0, 'is_bestseller' => 0, 'created_at' => $now, 'updated_at' => $now]);
    \Vayu\Database\DB::table('product_pricing')->insert(['product_id' => $pid, 'price' => 100, 'compare_at_price' => null, 'currency' => 'INR', 'unit' => 'sq ft']);
    $html = $router->dispatch($req('/shop'))->getContent();
    $assert->that('shop: results grid carries the shop base and announces updates', (bool) preg_match('#id="results"[^>]*data-grid data-shop="[^"]*/shop" aria-live="polite"#', $html)
        && str_contains($html, 'Skel Marble'));
    $root = dirname(__DIR__);
    $css = (string) file_get_contents($root . '/public/assets/css/store.css');
    $assert->that('skeleton: built CSS hides cards while busy and has the placeholder blocks', str_contains($css, '[data-grid][aria-busy=true]>.card')
        && str_contains($css, '.skel-card') && str_contains($css, '.skel-img') && str_contains($css, '@keyframes skel'));
    $sj = (string) file_get_contents($root . '/public/assets/js/store.js');
    $assert->that('skeleton: store.js swaps in placeholders and undoes them on pageshow', str_contains($sj, "grid.setAttribute('aria-busy', 'true')")
        && str_contains($sj, "grid.removeAttribute('aria-busy')"));

    $app->config()->set('store.enabled', false);
    $res = $router->dispatch($req('/offline'));
    $html = $res->getContent();
    $assert->that('offline page: loads even with the store closed, noindex', $res->getStatus() === 200 && str_contains($html, 'You’re offline')
        && str_contains($html, 'noindex') && str_contains($html, 'id="offlineList"'));
    $app->config()->set('store.enabled', true);
};
