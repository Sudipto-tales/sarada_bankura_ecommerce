<?php

use App\Services\Order\OrderService;
use App\Services\Push\Devices;
use App\Services\Push\Fcm;
use App\Services\Push\OrderPush;
use App\Services\Store\AppMode;
use App\Services\Store\Cart;
use App\Services\Store\Context;
use App\Services\Store\CustomerAuth;
use App\Services\Store\View;
use Vayu\Application;
use Vayu\Database\DB;
use Vayu\Http\Request;

/** Android app phase 4 (docs/mobile-app.md): push devices, FCM sender, order hooks, App Links, app-mode page bits. */
return function (Assert $assert): void {
    $app = new Application(dirname(__DIR__));
    $app->config()->set('database.default', 'sqlite');
    $app->config()->set('database.connections.sqlite.database', ':memory:');
    $app->config()->set('cache.default', 'array');
    $app->config()->set('store.cache_ttl', 0);
    $app->config()->set('store.enabled', true);
    $app->config()->set('store.fcm_service_account', '');
    $app->config()->set('store.app_cert_sha256', '');
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
    $ua = 'Mozilla/5.0 (Linux; Android 15) Chrome/140 Mobile MaaSaradaApp/1.2.0';
    $req = static fn(string $method, string $path, array $body = [], array $headers = []): Request
        => new Request($method, $path, [], $body, $headers + ['Accept' => 'application/json'], ['REMOTE_ADDR' => '10.0.9.1'], [], null);
    $tok = static fn(string $c): string => 'd' . str_repeat($c, 20) . ':APA91b' . str_repeat($c, 120);
    $now = date('Y-m-d H:i:s');

    // ---------------------------------------------------------------- register a device
    $res = $router->dispatch($req('POST', '/app/devices', ['token' => $tok('a')]));
    $assert->that('POST /app/devices: guest → 401 JSON', $res->getStatus() === 401 && DB::table('app_devices')->count() === 0);

    $u = CustomerAuth::register(['name' => 'Anita Pal', 'phone' => '98000 22222', 'password' => 'stone-lover-1'], '10.0.9.1');
    $uid = (int) $u['id'];
    $cid = (int) $u['customer_id'];
    Context::setCustomer(CustomerAuth::resolve());
    $res = $router->dispatch($req('POST', '/app/devices', ['token' => 'not a token']));
    $assert->that('POST /app/devices: junk token refused', $res->getStatus() === 422 && DB::table('app_devices')->count() === 0);
    $res = $router->dispatch($req('POST', '/app/devices', ['token' => $tok('a')], ['User-Agent' => $ua]));
    $row = DB::selectOne('SELECT * FROM app_devices');
    $assert->that('POST /app/devices: stored for the customer with the app version, token in session', $res->getStatus() === 200
        && (int) $row['user_id'] === $uid && (int) $row['customer_id'] === $cid && $row['app_version'] === '1.2.0'
        && session()->get(Devices::SESSION) === $tok('a'));
    $router->dispatch($req('POST', '/app/devices', ['token' => $tok('a')]));
    $assert->that('register again: same row, no duplicate', DB::table('app_devices')->count() === 1);
    Devices::register($uid, $cid, $tok('b'));
    $assert->equals('second phone: two rows', 2, Devices::countFor($uid));
    Devices::register($uid, null, $tok('z')); // registered before the first checkout made the customer record
    $assert->that('phone registered without a customer id still gets the customer\'s pushes (via customers.user_id)',
        in_array($tok('z'), Devices::tokensForCustomer($cid), true) && count(Devices::tokensForCustomer($cid)) === 3);
    Devices::forgetToken($tok('z'));
    session()->put(Devices::SESSION, $tok('b'));

    // ---------------------------------------------------------------- page bits in app mode
    $html = $router->dispatch($req('GET', '/brands'))->getContent();
    $assert->that('browser: no bridge script, no push meta', !str_contains($html, 'app-bridge.js') && !str_contains($html, 'name="ms-push"'));
    AppMode::force(true);
    $html = $router->dispatch($req('GET', '/brands'))->getContent();
    $assert->that('app, signed in, FCM not set up: bridge but no push meta (the app never calls register without Firebase)',
        str_contains($html, 'js/app-bridge.js') && !str_contains($html, 'name="ms-push"'));
    Fcm::$account = ['project_id' => 'ms-store', 'client_email' => 'push@ms-store.iam.gserviceaccount.com', 'private_key' => 'stub'];
    $html = $router->dispatch($req('GET', '/brands'))->getContent();
    $assert->that('app, signed in: bridge script + push endpoint meta', str_contains($html, 'js/app-bridge.js') && str_contains($html, 'name="ms-push" content="') && str_contains($html, '/app/devices"'));
    $assert->that('push meta: data-reg = tag of the token this session registered', str_contains($html, 'data-reg="' . Devices::tag($tok('b')) . '"')
        && strlen(Devices::tag($tok('b'))) === 16 && Devices::tag($tok('b')) === substr(hash('sha256', $tok('b')), 0, 16));
    $saved = session()->get(Devices::SESSION);
    session()->forget(Devices::SESSION);
    $html = $router->dispatch($req('GET', '/brands'))->getContent();
    $assert->that('push meta: new session (after sign-out / remember-me) → empty data-reg, so the app re-sends', str_contains($html, 'data-reg=""'));
    session()->put(Devices::SESSION, $saved);
    $bridge = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/app-bridge.js');
    $assert->that('bridge: resend is keyed to data-reg, not a per-app-session flag', str_contains($bridge, "getAttribute('data-reg')") && str_contains($bridge, "digest('SHA-256'"));
    AppMode::force(false);
    Fcm::$account = null;
    $sw = $router->dispatch($req('GET', '/service-worker'))->getContent();
    $assert->that('service worker: precaches the bridge, never caches /app/devices or /.well-known', str_contains($sw, 'js/app-bridge.js') && str_contains($sw, 'app\/(download|version|devices)') && str_contains($sw, '\.well-known'));
    $pw = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/pay-window.js');
    $assert->that('pay window: no QR and a "Pay with UPI app" button in the app', str_contains($pw, "qr.hidden = inApp") && str_contains($pw, 'Pay with UPI app'));

    // ---------------------------------------------------------------- push off: hooks are no-ops
    $order = static function (string $no) use ($cid, $now): int {
        return (int) DB::table('orders')->insertGetId(['order_number' => $no, 'customer_id' => $cid, 'customer_name' => 'Anita Pal', 'customer_phone' => '9800022222',
            'shipping_address' => '{"line1":"12 Lalbazar"}', 'order_status' => 'processing', 'channel' => 'online', 'channel_detail' => 'website',
            'public_token' => md5($no), 'subtotal' => 900, 'grand_total' => 900, 'paid_amount' => 900, 'due_amount' => 0, 'created_at' => $now, 'updated_at' => $now]);
    };
    $oid = $order('MSO/26-27/0201');
    $calls = [];
    Fcm::$http = static function (string $m, string $url, array $h, string $body) use (&$calls): array {
        $calls[] = [$url, $h, $body];
        if (str_contains($url, 'oauth2')) {
            return ['status' => 200, 'body' => '{"access_token":"ya29.test","expires_in":3599}'];
        }
        if (str_contains($body, 'APA91b' . str_repeat('b', 10))) {
            return ['status' => 404, 'body' => '{"error":{"code":404,"message":"Requested entity was not found.","details":[{"errorCode":"UNREGISTERED"}]}}'];
        }

        return ['status' => 200, 'body' => '{"name":"projects/ms/messages/1"}'];
    };
    $assert->that('push off: not configured, hook sends nothing, no HTTP', !Fcm::configured() && OrderPush::transition($oid, 'dispatched') === 0 && $calls === []);

    // ---------------------------------------------------------------- push on (stubbed FCM)
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $pub = openssl_pkey_get_details($key)['key'];
    Fcm::$account = ['project_id' => 'ms-store', 'client_email' => 'push@ms-store.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token'];
    $sa = Fcm::serviceAccount();
    $jwt = (string) Fcm::jwt($sa, 1_800_000_000);
    [$h64, $c64, $s64] = explode('.', $jwt) + ['', '', ''];
    $dec = static fn(string $s): string => (string) base64_decode(strtr($s, '-_', '+/'));
    $claims = json_decode($dec($c64), true);
    $assert->that('JWT: RS256, signed by the service account key, right scope / audience / expiry',
        json_decode($dec($h64), true)['alg'] === 'RS256' && openssl_verify($h64 . '.' . $c64, $dec($s64), $pub, OPENSSL_ALGO_SHA256) === 1
        && $claims['iss'] === $sa['client_email'] && $claims['scope'] === Fcm::SCOPE && $claims['aud'] === $sa['token_uri'] && $claims['exp'] === 1_800_003_600);

    $sent = OrderPush::transition($oid, 'dispatched');
    $sends = array_values(array_filter($calls, static fn($c) => str_contains($c[0], 'fcm.googleapis.com')));
    $msg = json_decode($sends[0][2] ?? '{}', true)['message'] ?? [];
    $assert->that('dispatched: one token exchange, a send per phone', count($calls) === 3 && str_contains($calls[0][0], 'oauth2') && count($sends) === 2
        && str_ends_with($sends[0][0], '/v1/projects/ms-store/messages:send') && in_array('Authorization: Bearer ya29.test', $sends[0][1], true));
    $assert->that('dispatched: title, order page url, orders channel', str_contains($msg['notification']['title'] ?? '', 'MSO/26-27/0201 is on its way')
        && ($msg['data']['url'] ?? '') === '/order/' . md5('MSO/26-27/0201') && ($msg['android']['notification']['channel_id'] ?? '') === 'orders');
    $assert->that('dispatched: unregistered token pruned, the live one counted', $sent === 1 && Devices::countFor($uid) === 1
        && DB::table('app_devices')->where('token', $tok('a'))->count() === 1);
    $calls = [];
    OrderPush::transition($oid, 'delivered');
    $assert->that('delivered: access token reused from cache (no second exchange)', count($calls) === 1 && str_contains($calls[0][0], 'fcm.googleapis.com')
        && str_contains($calls[0][2], 'delivered'));
    $calls = [];
    $assert->that('packed / accepted: no push (owner list: placed, paid, dispatched, delivered)', OrderPush::transition($oid, 'packed') === 0 && OrderPush::transition($oid, 'accepted') === 0 && $calls === []);
    $assert->that('placed: amount from the payment attempt', OrderPush::placed($oid, '1250.00') === 1 && str_contains($calls[0][2], '₹1,250'));
    Devices::register($uid, $cid, $tok('c'));
    OrderPush::$budget = -1.0; // already used up after the first phone
    $calls = [];
    $assert->that('time budget: a slow FCM stops after the first phone instead of stalling the request', OrderPush::transition($oid, 'delivered') === 1 && count($calls) === 1);
    OrderPush::$budget = 8.0;
    Devices::forgetToken($tok('c'));
    $calls = [];
    $pid = (int) DB::table('payments')->insertGetId(['payment_number' => 'PAY/26-27/0001', 'order_id' => $oid, 'customer_id' => $cid, 'method' => 'upi', 'amount' => 900,
        'received_at' => $now, 'status' => 'received', 'created_at' => $now, 'updated_at' => $now]);
    $assert->that('payment received: "Fully paid" when nothing is due', OrderPush::paymentReceived($pid) === 1 && str_contains($calls[0][2], 'Fully paid'));

    // A failing FCM never breaks the order flow.
    Fcm::$http = static fn(): array => throw new \RuntimeException('network down');
    $assert->that('FCM throws: hook returns 0, no exception', OrderPush::transition($oid, 'delivered') === 0);
    Fcm::$http = static fn(string $m, string $url): array => str_contains($url, 'oauth2')
        ? ['status' => 200, 'body' => '{"access_token":"x","expires_in":3599}'] : ['status' => 503, 'body' => '{"error":{"message":"busy"}}'];
    cache()->forget('push.fcm.access_token');
    for ($i = 0; $i < Devices::MAX_FAILURES; $i++) {
        OrderPush::transition($oid, 'delivered');
    }
    $assert->that('repeated failures: the token is dropped after ' . Devices::MAX_FAILURES, Devices::countFor($uid) === 0);

    // Real transitions call the hook (OrderService → OrderPush).
    $src = (string) file_get_contents(dirname(__DIR__) . '/app/Services/Order/OrderService.php')
        . file_get_contents(dirname(__DIR__) . '/app/Services/Payment/Ledger.php') . file_get_contents(dirname(__DIR__) . '/app/Services/Payment/PayWindow.php');
    $assert->that('hooks wired: transition, verify + record, checkout submit', substr_count($src, 'OrderPush::') === 4 && class_exists(OrderService::class));
    Fcm::$http = null;
    Fcm::$account = null;

    // ---------------------------------------------------------------- sign-out and account deletion
    Devices::register($uid, $cid, $tok('c'));
    Devices::register($uid, $cid, $tok('d'));
    CustomerAuth::logout();
    $assert->that('sign-out: this phone (the session token) removed, the other phone kept', DB::table('app_devices')->where('token', $tok('d'))->count() === 0
        && DB::table('app_devices')->where('token', $tok('c'))->count() === 1);
    CustomerAuth::attempt('9800022222', 'stone-lover-1');
    Devices::register($uid, $cid, $tok('e'));
    CustomerAuth::deleteAccount($uid, 'stone-lover-1');
    $assert->equals('account deletion: every push token of the login deleted', 0, Devices::countFor($uid));

    // A phone handed to another customer follows the new sign-in.
    $u2 = CustomerAuth::register(['name' => 'Ritika Sen', 'phone' => '9830099999', 'password' => 'marble-floor-3'], '10.0.9.2');
    Devices::register($uid, $cid, $tok('f'));
    Devices::register((int) $u2['id'], (int) $u2['customer_id'], $tok('f'));
    $assert->that('shared phone: the token moves to the new customer', Devices::tokensForCustomer($cid) === [] && Devices::tokensForCustomer((int) $u2['customer_id']) === [$tok('f')]);
    for ($i = 0; $i < Devices::MAX_PER_USER + 3; $i++) {
        Devices::register((int) $u2['id'], (int) $u2['customer_id'], $tok(chr(ord('g') + $i)));
    }
    $assert->equals('cap: at most ' . Devices::MAX_PER_USER . ' phones per login', Devices::MAX_PER_USER, Devices::countFor((int) $u2['id']));
    CustomerAuth::logout();

    // ---------------------------------------------------------------- App Links
    $res = $router->dispatch($req('GET', '/.well-known/assetlinks.json'));
    $assert->that('assetlinks: empty list until the fingerprint is set', $res->getStatus() === 200 && json_decode($res->getContent(), true) === []);
    $fp = implode(':', str_split(strtoupper(hash('sha256', 'cert')), 2));
    config()->set('store.app_cert_sha256', strtolower($fp) . ', nonsense');
    $j = json_decode($router->dispatch($req('GET', '/.well-known/assetlinks.json'))->getContent(), true);
    $assert->that('assetlinks: package + valid fingerprints only (upper-cased)', ($j[0]['target']['package_name'] ?? '') === 'com.maasarada.store'
        && ($j[0]['target']['sha256_cert_fingerprints'] ?? null) === [$fp] && $j[0]['relation'] === ['delegate_permission/common.handle_all_urls']);
};
