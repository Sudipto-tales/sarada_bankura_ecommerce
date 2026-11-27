<?php

use App\Services\Push\Fcm;
use App\Services\Push\OrderPush;
use App\Services\Store\AppMode;
use App\Services\Store\Cart;
use App\Services\Store\Context;
use App\Services\Store\CustomerAuth;
use App\Services\Store\Inbox;
use App\Services\Store\View;
use Vayu\Application;
use Vayu\Database\DB;
use Vayu\Http\Request;

/** In-app notifications (docs/mobile-app.md): inbox rows from order hooks without FCM, bell, tab, poll endpoint, deletion. */
return function (Assert $assert): void {
    $app = new Application(dirname(__DIR__));
    $app->config()->set('database.default', 'sqlite');
    $app->config()->set('database.connections.sqlite.database', ':memory:');
    $app->config()->set('cache.default', 'array');
    $app->config()->set('store.cache_ttl', 0);
    $app->config()->set('store.enabled', true);
    $app->config()->set('store.fcm_service_account', '');
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
    CustomerAuth::$sendCookie = false;
    $_COOKIE = [];
    Cart::reset();
    session()->start();
    AppMode::force(false);
    Fcm::$account = null;
    Fcm::$http = null;
    $req = static function (string $method, string $path, array $headers = []): Request {
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);

        return new Request($method, (string) parse_url($path, PHP_URL_PATH), $query, [], $headers + ['Accept' => 'application/json'], ['REMOTE_ADDR' => '10.0.9.2'], [], null);
    };
    $page = static fn(string $path): string => $router->dispatch(new Request('GET', $path, [], [], ['Accept' => 'text/html'], ['REMOTE_ADDR' => '10.0.9.2'], [], null))->getContent();
    $now = date('Y-m-d H:i:s');

    // ---------------------------------------------------------------- guest
    $res = $router->dispatch($req('GET', '/account/notifications/new'));
    $assert->that('poll: guest → 401 JSON', $res->getStatus() === 401);
    $html = $page('/brands');
    $assert->that('guest: no bell, no inbox config', !str_contains($html, 'data-count="inbox"') && !str_contains($html, 'id="store-inbox"'));

    // ---------------------------------------------------------------- order hooks fill the inbox without FCM
    $u = CustomerAuth::register(['name' => 'Rina Das', 'phone' => '98000 33333', 'password' => 'stone-lover-2'], '10.0.9.2');
    $cid = (int) $u['customer_id'];
    Context::setCustomer(CustomerAuth::resolve());
    $oid = (int) DB::table('orders')->insertGetId(['order_number' => 'MSO/26-27/0301', 'customer_id' => $cid, 'customer_name' => 'Rina Das', 'customer_phone' => '9800033333',
        'shipping_address' => '{"line1":"4 Machantala"}', 'order_status' => 'processing', 'channel' => 'online', 'channel_detail' => 'website',
        'public_token' => md5('MSO/26-27/0301'), 'subtotal' => 900, 'grand_total' => 900, 'paid_amount' => 900, 'due_amount' => 0, 'created_at' => $now, 'updated_at' => $now]);

    $html = $page('/brands');
    $assert->that('signed in, nothing yet: bell without a count, inbox config with after=0',
        str_contains($html, 'data-count="inbox" class="hidden') && str_contains($html, 'id="store-inbox"') && str_contains($html, '"after":0'));

    $assert->that('FCM off: dispatched sends no push', !Fcm::configured() && OrderPush::transition($oid, 'dispatched') === 0);
    $row = DB::selectOne('SELECT * FROM customer_notifications WHERE customer_id = ?', [$cid]);
    $assert->that('…but the inbox gets the update, linked to the order page', $row !== null && str_contains((string) $row['title'], 'is on its way')
        && $row['url'] === '/order/' . md5('MSO/26-27/0301') && $row['tag'] === 'order-' . $oid && $row['read_at'] === null);
    OrderPush::transition($oid, 'packed');
    $assert->that('packed: not a customer-facing event, no row', Inbox::unread($cid) === 1);
    $first = (int) $row['id'];

    $html = $page('/brands');
    $assert->that('bell shows the unread count and links to the inbox', str_contains($html, 'Notifications, 1 new') && str_contains($html, '/account/notifications"')
        && str_contains($html, '"after":' . $first));

    // ---------------------------------------------------------------- the poller
    $j = json_decode($router->dispatch($req('GET', '/account/notifications/new?after=0'))->getContent(), true);
    $assert->that('poll after=0: unread 1, one item with a full order URL', ($j['unread'] ?? null) === 1 && count($j['items'] ?? []) === 1
        && str_ends_with((string) $j['items'][0]['url'], '/order/' . md5('MSO/26-27/0301')));
    $j = json_decode($router->dispatch($req('GET', '/account/notifications/new?after=' . $first))->getContent(), true);
    $assert->that('poll after the newest id: count only, no items (no repeat card)', ($j['unread'] ?? null) === 1 && ($j['items'] ?? null) === []);
    OrderPush::transition($oid, 'delivered');
    $j = json_decode($router->dispatch($req('GET', '/account/notifications/new?after=' . $first))->getContent(), true);
    $assert->that('poll: a new update shows up as one item', ($j['unread'] ?? null) === 2 && count($j['items']) === 1 && str_contains($j['items'][0]['title'], 'delivered'));
    $res = $router->dispatch($req('GET', '/account/notifications/new'));
    $assert->that('poll: never cached', str_contains((string) ($res->getHeaders()['Cache-Control'] ?? ''), 'no-store'));

    // ---------------------------------------------------------------- the tab
    $html = $page('/account/notifications');
    $assert->that('tab: lists both, newest first, unread marked', strpos($html, 'delivered') < strpos($html, 'is on its way') && substr_count($html, 'aria-label="New"') === 2);
    $assert->that('opening the tab marks them read', Inbox::unread($cid) === 0);
    $html = $page('/account/notifications');
    $assert->that('second visit: nothing marked new, bell count hidden', substr_count($html, 'aria-label="New"') === 0 && str_contains($html, 'data-count="inbox" class="hidden'));
    $assert->that('account nav has the Notifications tab', str_contains($page('/account'), '/account/notifications"'));

    // ---------------------------------------------------------------- keep the newest KEEP
    for ($i = 0; $i < Inbox::KEEP + 5; $i++) {
        Inbox::add($cid, 'Note ' . $i, 'Body');
    }
    $assert->that('inbox keeps the newest ' . Inbox::KEEP, (int) DB::table('customer_notifications')->where('customer_id', $cid)->count() === Inbox::KEEP
        && Inbox::latest($cid, 1)[0]['title'] === 'Note ' . (Inbox::KEEP + 4));
    $assert->that('add: off-site URLs dropped, no customer → nothing stored', Inbox::add($cid, 'x', 'y', 'https://evil.example/') > 0
        && Inbox::latest($cid, 1)[0]['url'] === null && Inbox::add(0, 'x', 'y') === 0);

    // ---------------------------------------------------------------- the page's poller is shipped
    $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/store.js');
    $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/store.css');
    $assert->that('store.js polls while visible and on resume; built CSS has the card', str_contains($js, "function inbox()") && str_contains($js, 'visibilitychange')
        && str_contains($js, 'document.hidden') && str_contains($css, '.inbox-pop'));

    // ---------------------------------------------------------------- account deletion clears it
    CustomerAuth::deleteAccount((int) $u['id'], 'stone-lover-2', '10.0.9.2');
    $assert->that('account deletion removes the inbox', (int) DB::table('customer_notifications')->where('customer_id', $cid)->count() === 0);
    Context::setCustomer(null);
};
