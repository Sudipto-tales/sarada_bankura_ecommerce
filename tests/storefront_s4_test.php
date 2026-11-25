<?php

use App\Services\Order\OrderService;
use App\Services\Payment\PaymentException;
use App\Services\Payment\PayWindow;
use App\Services\Store\Cart;
use App\Services\Store\Checkout;
use App\Services\Store\View;
use Vayu\Application;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Validation\ValidationException;

/** Stage 8 S4: checkout form → server-built order input → pay window → order; track lookup; reskinned /order + /pay. */
return function (Assert $assert): void {
    $app = new Application(dirname(__DIR__));
    $app->config()->set('database.default', 'sqlite');
    $app->config()->set('database.connections.sqlite.database', ':memory:');
    $app->config()->set('cache.default', 'array');
    $app->config()->set('store.cache_ttl', 0);
    $app->config()->set('store.enabled', true);
    $app->config()->set('business.address.state', 'West Bengal');
    $app->config()->set('business.gstin', '');
    $app->config()->set('business.tax.prices_include_tax', false);
    $app->config()->set('business.tax.gst_rate', 18);
    $router = $app->router();
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
    session()->start();

    $now = date('Y-m-d H:i:s');
    $product = static function (string $name, array $pricing) use ($now): int {
        $id = (int) DB::table('products')->insertGetId(['product_type_id' => 1, 'status' => 'active', 'sku' => 'SKU-' . strtoupper(substr(md5($name), 0, 6)),
            'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'is_enquiry_only' => 0, 'show_price' => 1,
            'is_featured' => 0, 'is_bestseller' => 0, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('product_pricing')->insert($pricing + ['product_id' => $id, 'compare_at_price' => null, 'currency' => 'INR', 'unit' => 'sq ft']);

        return $id;
    };
    $kota = $product('Kota Stone', ['price' => 50]);
    $tile = $product('Vitrified Tile', ['price' => 900, 'unit' => 'box']);
    $errs = static function (callable $fn): array {
        try { $fn(); return []; } catch (ValidationException $e) { return $e->errors(); }
    };
    $form = [
        'name' => 'Ritika Sen', 'phone' => '98300 12345', 'email' => 'ritika@example.test',
        'pincode' => '722101', 'state' => 'west bengal', 'city' => 'Bankura', 'area' => 'Lalbazar', 'landmark' => 'Near Kali temple',
        'line1' => '12 Machantala Road', 'line2' => '', 'billing_same' => '1', 'buyer_note' => 'Call before 10 am', 'pay_choice' => 'advance', 'terms' => '1',
        // tampering attempts — none of these may reach the order
        'unit_price' => 1, 'items' => [['product_id' => $kota, 'quantity' => 1, 'unit_price' => 1]], 'discount_amount' => 999, 'customer_id' => 1,
    ];

    // ---------------------------------------------------------------- states + tax mode
    $assert->that('state: case-insensitive + aliases', Checkout::canonicalState('WEST  bengal') === 'West Bengal' && Checkout::canonicalState('Orissa') === 'Odisha' && Checkout::canonicalState('Narnia') === null);
    $assert->that('tax mode: none without GST, CGST+SGST at home, IGST outside', Checkout::taxMode('Jharkhand', 0) === 'none' && Checkout::taxMode('West Bengal', 18) === 'cgst_sgst' && Checkout::taxMode('Jharkhand', 18) === 'igst' && Checkout::taxMode(null, 18) === 'cgst_sgst');

    // ---------------------------------------------------------------- validation
    $e = $errs(fn() => Checkout::buildOrderInput([], []));
    $assert->that('build: empty form + empty bag → errors keyed by field', isset($e['cart'], $e['name'], $e['phone'], $e['pincode'], $e['state'], $e['city'], $e['line1'], $e['terms']));
    Cart::add($kota, 0, 100);
    Cart::add($tile, 0, 2);
    $lines = Cart::lines();
    $e = $errs(fn() => Checkout::buildOrderInput(['phone' => '12345', 'pincode' => '012345', 'state' => 'Narnia', 'email' => 'nope'] + $form, $lines));
    $assert->that('build: bad phone / pincode / state / email refused', isset($e['phone'], $e['pincode'], $e['state'], $e['email']) && !isset($e['cart']));
    $e = $errs(fn() => Checkout::buildOrderInput(['billing_same' => '0'] + $form, $lines));
    $assert->that('build: separate billing needs its own address', isset($e['bill_line1'], $e['bill_pincode'], $e['bill_state'], $e['bill_city']));
    $e = $errs(fn() => Checkout::buildOrderInput(['buyer_note' => str_repeat('x', 1001)] + $form, $lines));
    $assert->that('build: note length capped', isset($e['buyer_note']));

    $in = Checkout::buildOrderInput($form, $lines);
    $assert->that('build: prices from the DB, never the request', $in['items'] === [
        ['product_id' => $kota, 'variant_id' => 0, 'quantity' => 100.0, 'unit_price' => '50.00'],
        ['product_id' => $tile, 'variant_id' => 0, 'quantity' => 2.0, 'unit_price' => '900.00'],
    ]);
    $assert->that('build: no discount / customer_id from the form; new customer by phone', !isset($in['discount_amount']) && !isset($in['customer_id']) && $in['new_customer']['phone'] === '9830012345');
    $assert->that('build: address cleaned (canonical state, landmark kept)', $in['new_shipping']['state'] === 'West Bengal' && str_contains((string) $in['new_shipping']['address_line2'], 'Near Kali temple') && $in['billing_same'] === 1);
    $assert->that('build: no GSTIN → no GST (bag total = order total)', $in['gst_enabled'] === 0 && Cart::taxRate() === 0.0);
    $q = OrderService::quote($in);
    $assert->equals('build: OrderService prices it like the bag', Cart::totals($lines)['total'], $q['grand_total']);

    // ---------------------------------------------------------------- start
    $msg = '';
    try { Checkout::start($form); } catch (PaymentException $ex) { $msg = $ex->getMessage(); }
    $assert->that('start: no UPI account → refused, nothing kept', str_contains($msg, 'isn’t set up') && session()->get(Checkout::SESSION) === null && DB::table('orders')->count() === 0);
    DB::table('payment_accounts')->insert(['type' => 'upi', 'name' => 'SBI UPI', 'upi_id' => 'maasarada@sbi', 'payee_name' => 'Maa Sarada', 'is_active' => 1, 'is_default_upi' => 1, 'created_at' => $now, 'updated_at' => $now]);

    $app->config()->set('business.gstin', '19ABCDE1234F1Z5');
    $a1 = Checkout::start(['state' => 'Jharkhand', 'pincode' => '834001', 'city' => 'Ranchi'] + $form, '10.0.0.9', 'UA');
    $snap = json_decode((string) $a1['cart_snapshot'], true);
    $assert->that('start: GST on with a GSTIN (18 %)', $snap['gst_enabled'] === 1 && (float) $snap['gst_rate'] === 18.0);
    $total = round((100 * 50 + 2 * 900) * 1.18, 2);
    $assert->equals('start: advance = 20 % of the GST total, rounded up', \App\Services\Order\PaymentService::advance($total), (float) $a1['amount']);
    $assert->that('start: attempt open, no order, token in session', $a1['status'] === 'open' && $a1['order_id'] === null && DB::table('orders')->count() === 0 && session()->get(Checkout::SESSION)['token'] === $a1['public_token']);
    $a2 = Checkout::start(['pay_choice' => 'full', 'state' => 'Jharkhand', 'pincode' => '834001', 'city' => 'Ranchi'] + $form);
    $assert->that('start again: previous window closed, full amount', DB::table('payment_attempts')->where('id', $a1['id'])->value('status') === 'expired' && (float) $a2['amount'] === $total);

    $assert->that('finish: before submit → refused, cart kept', !Checkout::finish($a2['public_token'])['ok'] && Cart::current() !== null);
    $assert->that('finish: someone else’s token → refused', !Checkout::finish($a1['public_token'])['ok'] && !Checkout::finish(str_repeat('a', 32))['ok']);

    // ---------------------------------------------------------------- submit → order
    PayWindow::submit($a2['public_token'], ['payer_name' => 'Ritika Sen', 'payer_reference' => '123412341234']);
    $o = DB::selectOne('SELECT * FROM orders ORDER BY id DESC LIMIT 1');
    $assert->that('submit: online website order, pending', $o['channel'] === 'online' && $o['channel_detail'] === 'website' && $o['order_status'] === 'pending');
    $assert->that('submit: prices + GST as quoted, IGST outside West Bengal', (float) $o['grand_total'] === $total && $o['place_of_supply'] === 'Jharkhand'
        && \App\Services\Order\DocumentService::taxMode($o) === 'igst');
    $assert->that('submit: buyer note stored', $o['buyer_note'] === 'Call before 10 am');
    $c = DB::selectOne('SELECT * FROM customers WHERE id = ?', [(int) $o['customer_id']]);
    $assert->that('submit: customer created as online', $c['phone'] === '9830012345' && $c['source'] === 'online');
    $assert->that('submit: full payment awaiting verification', (float) $o['pending_verification_amount'] === $total);

    $r = Checkout::finish($a2['public_token']);
    $assert->that('finish: bag converted to the order', $r['ok'] && $r['order_token'] === $o['public_token'] && Cart::current() === null
        && (int) DB::table('carts')->value('converted_order_id') === (int) $o['id'] && session()->get(Checkout::SESSION) === null);
    $assert->that('finish: second time refused (session cleared)', !Checkout::finish($a2['public_token'])['ok']);

    // ---------------------------------------------------------------- reconcile (tab closed after submit)
    Cart::reset();
    Cart::add($kota, 0, 10);
    $a3 = Checkout::start($form);
    PayWindow::submit($a3['public_token'], ['payer_name' => 'Ritika Sen', 'payer_reference' => '999988887777']);
    $rec = Checkout::reconcile();
    $assert->that('reconcile: submitted window finishes the bag', $rec !== null && $rec['ok'] && Cart::current() === null);
    $assert->that('reconcile: nothing pending → null', Checkout::reconcile() === null);
    $assert->that('second order reused the customer (same mobile)', (int) DB::table('customers')->count() === 1 && DB::table('orders')->count() === 2);

    // ---------------------------------------------------------------- HTTP: routes, pages, track
    $req = static fn(string $method, string $path, array $body = [], array $headers = []): Request => new Request($method, $path, [], $body, $headers, ['REMOTE_ADDR' => '10.0.3.1'], [], null);
    $assert->that('routes: checkout + track registered', route('store.checkout') !== null && route('store.checkout.start') !== null && route('store.checkout.done') !== null && route('store.track') !== null);
    // S5: checkout needs an account — a guest is sent to sign in and comes back to /checkout.
    $res = $router->dispatch($req('GET', '/checkout'));
    $assert->that('GET /checkout as a guest → /signin?next=/checkout', $res->getStatus() === 302 && str_ends_with((string) ($res->getHeaders()['Location'] ?? ''), '/signin?next=%2Fcheckout'));
    \App\Services\Store\CustomerAuth::$sendCookie = false;
    \App\Services\Store\CustomerAuth::register(['name' => 'Ritika Sen', 'phone' => '9830012345', 'password' => 'stone-lover-1']);
    \App\Services\Store\Context::setCustomer(\App\Services\Store\CustomerAuth::resolve());
    $res = $router->dispatch($req('GET', '/checkout'));
    $assert->that('GET /checkout with an empty bag → /cart', $res->getStatus() === 302 && str_ends_with((string) ($res->getHeaders()['Location'] ?? ''), '/cart'));
    Cart::reset();
    Cart::add($tile, 0, 1);
    $res = $router->dispatch($req('GET', '/checkout'));
    $html = $res->getContent();
    $assert->that('GET /checkout renders form, summary and the pay window', $res->getStatus() === 200 && str_contains($html, 'id="co"') && str_contains($html, 'Vitrified Tile')
        && str_contains($html, 'data-mode="checkout"') && str_contains($html, 'West Bengal') && !str_contains($html, 'cdn.tailwindcss'));
    $assert->that('GET /checkout shows IGST + CGST/SGST rows (GSTIN set)', str_contains($html, 'IGST @ 18%') && str_contains($html, 'CGST @ 9%'));
    $res = $router->dispatch($req('POST', '/checkout/start', ['name' => ''], ['Accept' => 'application/json']));
    $j = json_decode($res->getContent(), true);
    $assert->that('POST /checkout/start (JSON) → 422 with field errors', $res->getStatus() === 422 && isset($j['errors']['name'], $j['errors']['terms']) && !isset($j['errors']['phone']));
    $res = $router->dispatch($req('POST', '/checkout/start', $form, ['Accept' => 'application/json']));
    $j = json_decode($res->getContent(), true);
    $assert->that('POST /checkout/start (JSON) → window payload', $res->getStatus() === 200 && $j['ok'] && isset($j['token'], $j['submit_url'], $j['upi']['uri']) && $j['amount'] === \App\Services\Order\PaymentService::advance(1062.0));
    $res = $router->dispatch($req('POST', '/checkout/done', ['token' => $j['token']]));
    $assert->that('POST /checkout/done before paying → back to the bag', $res->getStatus() === 302 && str_ends_with((string) ($res->getHeaders()['Location'] ?? ''), '/cart') && Cart::current() !== null);

    $res = $router->dispatch($req('GET', '/order/' . $o['public_token']));
    $html = $res->getContent();
    $assert->that('/order reskinned: store layout, order number, IGST, under review', $res->getStatus() === 200 && str_contains($html, 'store.css') && str_contains($html, e((string) $o['order_number']))
        && str_contains($html, 'IGST @ 18%') && str_contains($html, 'Under review') && str_contains($html, 'Call before 10 am'));
    $res = $router->dispatch($req('GET', '/pay/' . $o['public_token']));
    $assert->that('/pay reskinned: nothing due while the full payment is checked', $res->getStatus() === 200 && str_contains($res->getContent(), 'store.css') && !str_contains($res->getContent(), 'data-pay '));
    $assert->that('/order unknown token → 404', $router->dispatch($req('GET', '/order/' . str_repeat('0', 32)))->getStatus() === 404);

    $res = $router->dispatch($req('GET', '/track'));
    $assert->that('GET /track renders both forms', $res->getStatus() === 200 && substr_count($res->getContent(), 'name="order_number"') === 2);
    $res = $router->dispatch($req('POST', '/track', ['order_number' => strtolower((string) $o['order_number']), 'phone' => '+91 98300 12345']));
    $assert->that('POST /track: number (any case) + mobile → order page', ($res->getHeaders()['Location'] ?? '') === route('order.public', ['token' => $o['public_token']]));
    $res = $router->dispatch($req('POST', '/track', ['order_number' => $o['order_number'], 'phone' => '9830012345', 'go' => 'pay']));
    $assert->that('POST /track go=pay → pay page', ($res->getHeaders()['Location'] ?? '') === route('pay.show', ['token' => $o['public_token']]));
    $res = $router->dispatch($req('POST', '/track', ['order_number' => $o['order_number'], 'phone' => '9830099999']));
    $miss = (string) session()->get('error');
    $router->dispatch($req('POST', '/track', ['order_number' => 'MSO/00-00/9999', 'phone' => '9830012345']));
    $assert->that('POST /track: wrong mobile and unknown number give the same message', str_ends_with((string) ($res->getHeaders()['Location'] ?? ''), '/track') && $miss !== '' && $miss === (string) session()->get('error'));
    for ($i = 0; $i < 12; $i++) {
        $res = $router->dispatch($req('POST', '/track', ['order_number' => $o['order_number'], 'phone' => '9830012345']));
    }
    $assert->that('POST /track: throttled after 10 per hour', str_ends_with((string) ($res->getHeaders()['Location'] ?? ''), '/track') && str_contains((string) session()->get('error'), 'Too many'));

    // escaping: customer-entered text on the order page
    DB::update('UPDATE orders SET buyer_note = ? WHERE id = ?', ['<script>alert(1)</script>', (int) $o['id']]);
    $html = $router->dispatch($req('GET', '/order/' . $o['public_token']))->getContent();
    $assert->that('/order escapes the buyer note', !str_contains($html, '<script>alert(1)</script>') && str_contains($html, '&lt;script&gt;'));

    $app->config()->set('business.gstin', '');
    $_COOKIE = [];
    Cart::reset();
};
