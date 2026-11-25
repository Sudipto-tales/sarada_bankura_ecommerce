<?php

use App\Services\Customer\CustomerService;
use App\Services\Store\AuthException;
use App\Services\Store\Cart;
use App\Services\Store\Checkout;
use App\Services\Store\Context;
use App\Services\Store\CustomerAuth;
use App\Services\Store\Saved;
use App\Services\Store\View;
use Vayu\Application;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Validation\ValidationException;

/** Stage 8 S5: customer accounts — register / sign in / remember / reset, bag + saved merge, account pages, login-gated checkout. */
return function (Assert $assert): void {
    $app = new Application(dirname(__DIR__));
    $app->config()->set('database.default', 'sqlite');
    $app->config()->set('database.connections.sqlite.database', ':memory:');
    $app->config()->set('cache.default', 'array');
    $app->config()->set('store.cache_ttl', 0);
    $app->config()->set('store.enabled', true);
    $app->config()->set('business.gstin', '');
    $router = $app->router();
    $router->aliasMiddleware('throttle', \Vayu\Middleware\ThrottleRequests::class); // bootstrap/app.php does this in production
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
    $reset = static function (): void {
        session()->forget(CustomerAuth::SESSION);
        $_COOKIE = [];
        Cart::reset();
        Context::setCustomer(null);
    };
    $errs = static function (callable $fn): array {
        try { $fn(); return []; } catch (ValidationException $e) { return $e->errors(); }
    };
    $authErr = static function (callable $fn): ?string {
        try { $fn(); return null; } catch (AuthException $e) { return $e->getMessage(); }
    };

    $now = date('Y-m-d H:i:s');
    $product = static function (string $name, float $price) use ($now): int {
        $id = (int) DB::table('products')->insertGetId(['product_type_id' => 1, 'status' => 'active', 'sku' => 'SKU-' . strtoupper(substr(md5($name), 0, 6)),
            'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'is_enquiry_only' => 0, 'show_price' => 1,
            'is_featured' => 0, 'is_bestseller' => 0, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('product_pricing')->insert(['product_id' => $id, 'price' => $price, 'compare_at_price' => null, 'currency' => 'INR', 'unit' => 'sq ft']);

        return $id;
    };
    $kota = $product('Kota Stone', 50);
    $makrana = $product('Makrana White', 220);

    // ---------------------------------------------------------------- schema
    DB::table('users')->insert(['name' => 'Legacy', 'email' => null, 'password' => null, 'role' => 'user', 'created_at' => $now]);
    $assert->that('migration 000017: users.email is nullable (phone-only accounts)', DB::table('users')->where('name', 'Legacy')->exists());
    DB::table('users')->where('name', 'Legacy')->delete();

    // ---------------------------------------------------------------- a showroom customer with history
    $showroom = CustomerService::save(CustomerService::validate(['name' => 'Ritika Sen', 'phone' => '98300 12345', 'source' => 'showroom']));
    DB::table('orders')->insert(['order_number' => 'MSO/26-27/0001', 'customer_id' => $showroom, 'customer_name' => 'Ritika Sen', 'customer_phone' => '9830012345',
        'order_status' => 'delivered', 'channel' => 'offline', 'channel_detail' => 'showroom', 'public_token' => str_repeat('a', 32),
        'subtotal' => 5000, 'grand_total' => 5000, 'due_amount' => 1200, 'created_at' => $now, 'updated_at' => $now]);

    // ---------------------------------------------------------------- register: validation
    $e = $errs(fn() => CustomerAuth::register(['name' => 'R', 'phone' => '12345', 'email' => 'nope', 'password' => 'short']));
    $assert->that('register: bad name / phone / email / short password refused', isset($e['name'], $e['phone'], $e['email'], $e['password']));
    $assert->that('register: nothing written on refusal', DB::table('users')->count() === 0);

    // ---------------------------------------------------------------- guest bag + guest saved list, then register
    Cart::add($kota, 0, 100);
    $guestCart = Cart::current();
    $u = CustomerAuth::register(['name' => 'Ritika Sen', 'phone' => '+91 98300-12345', 'email' => 'Ritika@Example.test', 'password' => 'stone-lover-1', 'marketing_consent' => '1'],
        '10.0.5.1', ['makrana-white', 'no-such-stone', '../evil']);
    $assert->that('register: linked to the showroom customer by phone (no duplicate customer)', $u['customer_id'] === $showroom && DB::table('customers')->count() === 1);
    $assert->that('register: showroom customer marked online + consent + email filled',
        (int) DB::table('customers')->where('id', $showroom)->value('is_online') === 1 && (int) DB::table('customers')->where('id', $showroom)->value('marketing_consent') === 1
        && DB::table('customers')->where('id', $showroom)->value('email') === 'ritika@example.test');
    $row = DB::selectOne('SELECT * FROM users WHERE id = ?', [$u['id']]);
    $assert->that('register: phone normalised, email lower-cased, password hashed', $row['phone'] === '9830012345' && $row['email'] === 'ritika@example.test'
        && $row['password'] !== 'stone-lover-1' && password_verify('stone-lover-1', (string) $row['password']));
    $assert->that('register: signed in (session holds id + version)', session()->get(CustomerAuth::SESSION)['id'] === (int) $u['id'] && CustomerAuth::resolve()['id'] === $u['id']);
    $assert->that('register: guest bag now belongs to the account', (int) DB::table('carts')->where('id', (int) $guestCart['id'])->value('user_id') === (int) $u['id']
        && (int) DB::table('carts')->where('id', (int) $guestCart['id'])->value('customer_id') === $showroom);
    $assert->equals('register: guest saved list merged (unknown / bad slugs skipped)', ['makrana-white'], Saved::slugs($showroom));
    $e = $errs(fn() => CustomerAuth::register(['name' => 'Someone', 'phone' => '9830012345', 'email' => 'RITIKA@example.test', 'password' => 'another-pass']));
    $assert->that('register: same phone / email again refused', isset($e['phone'], $e['email']));

    // ---------------------------------------------------------------- sign out → bag stays on the account
    CustomerAuth::logout();
    $assert->that('logout: session cleared, bag let go in this browser', session()->get(CustomerAuth::SESSION) === null && Cart::current() === null && Cart::count() === 0);

    // ---------------------------------------------------------------- sign in
    $reset();
    $assert->that('sign in: wrong password → same vague message', str_contains((string) $authErr(fn() => CustomerAuth::attempt('9830012345', 'wrong-pass', false, '10.0.5.2')), 'don’t match'));
    $assert->that('sign in: unknown account → same message', str_contains((string) $authErr(fn() => CustomerAuth::attempt('9000000000', 'whatever1', false, '10.0.5.2')), 'don’t match'));
    // A second guest bag on another device, with an overlapping line.
    Cart::add($kota, 0, 20);
    Cart::add($makrana, 0, 10);
    $second = Cart::current();
    $u2 = CustomerAuth::attempt('ritika@example.test', 'stone-lover-1', false, '10.0.5.2', ['kota-stone']);
    $assert->that('sign in by email works', $u2['id'] === $u['id']);
    $lines = DB::select('SELECT product_id, quantity FROM cart_items WHERE cart_id = ? ORDER BY product_id', [$second['id']]);
    $assert->that('merge: account bag folded into this bag (same line → quantities added)', count($lines) === 2 && (float) $lines[0]['quantity'] === 120.0 && (float) $lines[1]['quantity'] === 10.0);
    $assert->that('merge: the older account bag is closed', DB::table('carts')->where('id', (int) $guestCart['id'])->value('status') === 'abandoned');
    $assert->equals('merge: saved list keeps account items + adds new', ['makrana-white', 'kota-stone'], Saved::slugs($showroom));
    CustomerAuth::logout();
    $reset();
    CustomerAuth::attempt('98300 12345', 'stone-lover-1');
    $assert->that('sign in on a fresh browser → the account bag comes back', Cart::current() !== null && (int) Cart::current()['id'] === (int) $second['id'] && Cart::count() === 2);

    // ---------------------------------------------------------------- throttle
    $reset();
    for ($i = 0; $i < CustomerAuth::MAX_PER_ID; $i++) {
        $authErr(fn() => CustomerAuth::attempt('9830012345', 'bad-' . $i, false, '10.0.5.' . (10 + $i)));
    }
    $assert->that('throttle: 5 wrong tries lock the login ID (even the right password)', str_contains((string) $authErr(fn() => CustomerAuth::attempt('9830012345', 'stone-lover-1', false, '10.0.5.99')), 'Too many'));
    app('rate_limiter')->clear('signin:id:' . sha1('9830012345'));

    // ---------------------------------------------------------------- status + staff
    DB::table('users')->where('id', (int) $u['id'])->update(['status' => 'blocked']);
    $assert->that('switched-off account: told only after the right password', str_contains((string) $authErr(fn() => CustomerAuth::attempt('9830012345', 'stone-lover-1')), 'switched off')
        && session()->get(CustomerAuth::SESSION) === null);
    DB::table('users')->where('id', (int) $u['id'])->update(['status' => 'active']);

    // ---------------------------------------------------------------- remember me + session version
    $reset();
    CustomerAuth::attempt('9830012345', 'stone-lover-1', true);
    $cookie = (string) ($_COOKIE[CustomerAuth::COOKIE] ?? '');
    $tok = DB::selectOne('SELECT * FROM customer_remember_tokens');
    $assert->that('remember: selector:validator cookie, only a hash stored', preg_match('/^[a-f0-9]{24}:[a-f0-9]{64}$/', $cookie) === 1
        && $tok['validator_hash'] === hash('sha256', explode(':', $cookie)[1]) && !str_contains(json_encode($tok), explode(':', $cookie)[1]));
    session()->forget(CustomerAuth::SESSION);
    $back = CustomerAuth::resolve();
    $assert->that('remember: no session → signed in from the cookie, token rotated', $back !== null && (int) $back['id'] === (int) $u['id']
        && (string) $_COOKIE[CustomerAuth::COOKIE] !== $cookie && DB::table('customer_remember_tokens')->where('selector', explode(':', $cookie)[0])->exists() === false);
    session()->forget(CustomerAuth::SESSION);
    $rotated = (string) $_COOKIE[CustomerAuth::COOKIE];
    $_COOKIE[CustomerAuth::COOKIE] = $cookie;
    $assert->that('remember: an old (copied) cookie no longer works', CustomerAuth::resolve() === null);
    $_COOKIE[CustomerAuth::COOKIE] = substr($rotated, 0, 25) . str_repeat('0', 64);
    $assert->that('remember: wrong validator refused and token dropped', CustomerAuth::resolve() === null && DB::table('customer_remember_tokens')->count() === 0);

    // ---------------------------------------------------------------- change password
    $reset();
    CustomerAuth::attempt('9830012345', 'stone-lover-1', true);
    $otherBrowser = session()->get(CustomerAuth::SESSION);
    $e = $errs(fn() => CustomerAuth::changePassword((int) $u['id'], 'wrong', 'new-pass-123', 'new-pass-123'));
    $assert->that('change password: current required', isset($e['current_password']));
    $e = $errs(fn() => CustomerAuth::changePassword((int) $u['id'], 'stone-lover-1', 'new-pass-123', 'new-pass-124'));
    $assert->that('change password: confirmation must match', isset($e['password_confirmation']));
    CustomerAuth::changePassword((int) $u['id'], 'stone-lover-1', 'new-pass-123', 'new-pass-123');
    $assert->that('change password: this browser stays signed in, remember tokens dropped', CustomerAuth::resolve() !== null && DB::table('customer_remember_tokens')->count() === 0);
    session()->put(CustomerAuth::SESSION, $otherBrowser);
    $assert->that('change password: an older session (other browser) is signed out', CustomerAuth::resolve() === null);

    // ---------------------------------------------------------------- forgot / reset (email not set up locally → mail_off)
    $r = CustomerAuth::sendResetCode('ritika@example.test', '10.0.6.1');
    $assert->that('forgot: without MAIL_* → "message us on WhatsApp", no code stored', !$r['ok'] && !empty($r['mail_off']) && DB::table('customer_auth_codes')->count() === 0);
    // Simulate a code having been sent.
    DB::table('customer_auth_codes')->insert(['user_id' => (int) $u['id'], 'purpose' => 'reset_password', 'channel' => 'email', 'code_hash' => password_hash('123456', PASSWORD_DEFAULT),
        'attempts' => 0, 'expires_at' => date('Y-m-d H:i:s', time() + 900), 'created_at' => $now]);
    $reset();
    $e = $errs(fn() => CustomerAuth::resetPassword('ritika@example.test', '654321', 'reset-pass-1'));
    $assert->that('reset: wrong code refused + counted', isset($e['code']) && (int) DB::table('customer_auth_codes')->value('attempts') === 1);
    CustomerAuth::resetPassword('ritika@example.test', '123 456', 'reset-pass-1');
    $assert->that('reset: right code → new password, signed in, code used once', CustomerAuth::resolve() !== null
        && password_verify('reset-pass-1', (string) DB::table('users')->where('id', (int) $u['id'])->value('password'))
        && DB::table('customer_auth_codes')->whereNull('used_at')->count() === 0);
    $reset();
    $e = $errs(fn() => CustomerAuth::resetPassword('ritika@example.test', '123456', 'reset-pass-2'));
    $assert->that('reset: a used code can’t be used again', isset($e['code']));
    DB::table('customer_auth_codes')->insert(['user_id' => (int) $u['id'], 'purpose' => 'reset_password', 'channel' => 'email', 'code_hash' => password_hash('111111', PASSWORD_DEFAULT),
        'attempts' => CustomerAuth::CODE_TRIES, 'expires_at' => date('Y-m-d H:i:s', time() + 900), 'created_at' => $now]);
    $assert->that('reset: 5 wrong tries kill the code (even the right one)', isset($errs(fn() => CustomerAuth::resetPassword('ritika@example.test', '111111', 'reset-pass-2'))['code']));

    // ---------------------------------------------------------------- safe "next"
    $assert->that('next: same-site paths only', CustomerAuth::safeNext('/checkout') === '/checkout' && CustomerAuth::safeNext('//evil.test/x') === '/account'
        && CustomerAuth::safeNext('https://evil.test') === '/account' && CustomerAuth::safeNext('/\\evil') === '/account' && CustomerAuth::safeNext('/signin') === '/account'
        && CustomerAuth::safeNext('/admin') === '/account' && CustomerAuth::safeNext("/x\r\nSet-Cookie: a") === '/account');

    // ---------------------------------------------------------------- addresses
    $reset();
    CustomerAuth::attempt('9830012345', 'reset-pass-1');
    $e = $errs(fn() => CustomerService::saveOwnAddress($showroom, ['address_line1' => '', 'pincode' => '012345']));
    $assert->that('address: street + valid pincode required', isset($e['address_line1']) || isset($e['pincode']));
    $a1 = CustomerService::saveOwnAddress($showroom, ['label' => 'Home', 'address_line1' => '12 Machantala Road', 'city' => 'Bankura', 'state' => 'West Bengal', 'pincode' => '722101']);
    $a2 = CustomerService::saveOwnAddress($showroom, ['label' => 'Site', 'address_line1' => 'Plot 22, Sarada Pally', 'city' => 'Bishnupur', 'state' => 'West Bengal', 'pincode' => '722122']);
    $assert->that('address: first one becomes the default', (int) DB::table('customer_addresses')->where('id', $a1)->value('is_default') === 1 && (int) DB::table('customer_addresses')->where('id', $a2)->value('is_default') === 0);
    CustomerService::makeDefaultAddress($showroom, $a2);
    $assert->that('address: only one default', (int) DB::table('customer_addresses')->where('customer_id', $showroom)->where('is_default', 1)->count() === 1 && (int) DB::table('customer_addresses')->where('id', $a2)->value('is_default') === 1);
    $other = CustomerService::save(CustomerService::validate(['name' => 'Someone Else', 'phone' => '9876543210']));
    $assert->that('address: can’t touch another customer’s address', !CustomerService::makeDefaultAddress($other, $a1) && !CustomerService::deleteOwnAddress($other, $a1)
        && isset($errs(fn() => CustomerService::saveOwnAddress($other, ['address_line1' => 'x street', 'state' => 'West Bengal', 'pincode' => '722101'], $a1))['address']));
    CustomerService::deleteOwnAddress($showroom, $a2);
    $assert->that('address: removing the default promotes another', (int) DB::table('customer_addresses')->where('id', $a1)->value('is_default') === 1);

    // ---------------------------------------------------------------- checkout as a signed-in customer
    Context::setCustomer(CustomerAuth::resolve());
    $lines = Cart::lines();
    $form = ['name' => 'Ritika Sen', 'phone' => '9000000000', 'address_id' => (string) $a1, 'pincode' => '', 'state' => '', 'city' => '', 'line1' => '', 'billing_same' => '1', 'terms' => '1', 'pay_choice' => 'advance'];
    $in = Checkout::buildOrderInput($form, $lines);
    $assert->that('checkout: account customer + account phone (typed phone ignored)', $in['customer_id'] === $showroom && !isset($in['new_customer']));
    $assert->that('checkout: saved address used by id (no duplicate address)', $in['shipping_address_id'] === (string) $a1 && !isset($in['new_shipping']));
    $e = $errs(fn() => Checkout::buildOrderInput(['address_id' => (string) DB::table('customer_addresses')->insertGetId(['customer_id' => $other, 'address_line1' => 'Not yours', 'state' => 'Bihar', 'pincode' => '800001', 'created_at' => $now])] + $form, $lines));
    $assert->that('checkout: another customer’s address id is ignored (fields then required)', isset($e['pincode'], $e['line1']));

    // ---------------------------------------------------------------- HTTP
    $req = static fn(string $method, string $path, array $body = [], array $headers = [], array $query = []): Request => new Request($method, $path, $query, $body, $headers, ['REMOTE_ADDR' => '10.0.7.1'], [], null);
    $loc = static fn($res): string => (string) ($res->getHeaders()['Location'] ?? '');
    $html = $router->dispatch($req('GET', '/account'))->getContent();
    $assert->that('GET /account: orders list includes the showroom order with Pay', str_contains($html, 'MSO/26-27/0001') && str_contains($html, 'Showroom') && str_contains($html, '/pay/' . str_repeat('a', 32)));
    $html = $router->dispatch($req('GET', '/account/addresses'))->getContent();
    $assert->that('GET /account/addresses: saved address + edit/remove', str_contains($html, '12 Machantala Road') && str_contains($html, '/account/addresses/' . $a1 . '/delete'));
    $html = $router->dispatch($req('GET', '/account/profile'))->getContent();
    $assert->that('GET /account/profile: profile + change password', str_contains($html, 'Change password') && str_contains($html, 'ritika@example.test'));
    $assert->that('GET /account/nope → /account', str_ends_with($loc($router->dispatch($req('GET', '/account/nope'))), '/account'));
    $html = $router->dispatch($req('GET', '/'))->getContent();
    $assert->that('layout: signed in → saved list handed to store.js + header says Account', str_contains($html, 'id="store-account">{') && str_contains($html, '"makrana-white"') && str_contains($html, 'href="' . store_url('/account') . '"'));
    $res = $router->dispatch($req('POST', '/saved/toggle', ['slug' => 'makrana-white', 'on' => '0'], ['Accept' => 'application/json']));
    $j = json_decode($res->getContent(), true);
    $assert->that('POST /saved/toggle (signed in) → removed', $res->getStatus() === 200 && $j['ok'] && $j['saved'] === false && $j['slugs'] === ['kota-stone']);
    $res = $router->dispatch($req('GET', '/signin'));
    $assert->that('GET /signin while signed in → /account', str_ends_with($loc($res), '/account'));

    $router->dispatch($req('POST', '/signout'));
    $reset();
    $res = $router->dispatch($req('GET', '/account/addresses'));
    $assert->that('guest GET /account/addresses → /signin?next=…', str_contains($loc($res), '/signin?next=%2Faccount%2Faddresses'));
    $res = $router->dispatch($req('POST', '/saved/toggle', ['slug' => 'kota-stone'], ['Accept' => 'application/json']));
    $assert->that('guest POST /saved/toggle → 401 + sign-in URL', $res->getStatus() === 401 && json_decode($res->getContent(), true)['signin'] === true);
    $res = $router->dispatch($req('GET', '/signin', [], [], ['next' => '/checkout']));
    $html = $res->getContent();
    $assert->that('GET /signin?next=/checkout: both forms, bag-kept note, next carried', $res->getStatus() === 200 && str_contains($html, 'Your bag is kept')
        && str_contains($html, 'name="next" value="/checkout"') && str_contains($html, 'action="' . route('store.register') . '"') && str_contains($html, 'name="saved"'));
    $html = $router->dispatch($req('GET', '/'))->getContent();
    $assert->that('layout: guest → no account data for store.js, header says Sign in', str_contains($html, 'id="store-account">null<') && str_contains($html, 'Sign in'));
    $res = $router->dispatch($req('POST', '/signin', ['login' => '9830012345', 'password' => 'reset-pass-1', 'next' => '//evil.test']));
    $assert->that('POST /signin → signed in, unsafe next replaced by /account', str_ends_with($loc($res), '/account') && !str_contains($loc($res), 'evil') && session()->get(CustomerAuth::SESSION) !== null);
    CustomerAuth::logout();
    $reset();
    $res = $router->dispatch($req('POST', '/signin', ['login' => '9830012345', 'password' => 'nope-nope', 'next' => '/checkout']));
    $assert->that('POST /signin wrong password → back to /signin with next kept', str_contains($loc($res), '/signin?next=%2Fcheckout') && session()->get(CustomerAuth::SESSION) === null);
    $res = $router->dispatch($req('POST', '/register', ['name' => 'Anil Das', 'phone' => '9123456780', 'password' => 'marble-123', 'next' => '/checkout']));
    $assert->that('POST /register → signed in and on to /checkout', str_ends_with($loc($res), '/checkout') && (int) session()->get(CustomerAuth::SESSION)['id'] > 0
        && DB::table('customers')->where('phone', '9123456780')->value('source') === 'online');
    $assert->that('storefront sign-ups are customer accounts (staff live in admin_users)', DB::table('users')->where('phone', '9123456780')->value('role') === 'customer');
    CustomerAuth::logout();
    $reset();
};
