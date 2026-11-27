<?php

use App\Console\Commands\StoreDemoAccountCommand;
use App\Services\Customer\CustomerService;
use App\Services\Store\Cart;
use App\Services\Store\Context;
use App\Services\Store\CustomerAuth;
use App\Services\Store\View;
use Vayu\Application;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Validation\ValidationException;

/** Android app phase 2 (docs/mobile-app.md): account deletion, privacy policy, Play reviewer login. */
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
    CustomerAuth::$sendCookie = false;
    $_COOKIE = [];
    Cart::reset();
    session()->start();
    $errs = static function (callable $fn): array {
        try { $fn(); return []; } catch (ValidationException $e) { return $e->errors(); }
    };
    $req = static fn(string $method, string $path, array $body = []): Request => new Request($method, $path, [], $body, [], ['REMOTE_ADDR' => '10.0.8.1'], [], null);
    $loc = static fn($res): string => (string) ($res->getHeaders()['Location'] ?? '');
    $now = date('Y-m-d H:i:s');
    $order = static function (int $cid, string $no, string $status) use ($now): void {
        DB::table('orders')->insert(['order_number' => $no, 'customer_id' => $cid, 'customer_name' => 'Anita Pal', 'customer_phone' => '9800011111',
            'shipping_address' => '{"line1":"12 Lalbazar"}', 'order_status' => $status, 'channel' => 'online', 'channel_detail' => 'website',
            'public_token' => str_repeat(substr(md5($no), 0, 1), 32), 'subtotal' => 900, 'grand_total' => 900, 'due_amount' => 0, 'created_at' => $now, 'updated_at' => $now]);
    };

    // ---------------------------------------------------------------- an online-only customer with history
    $u = CustomerAuth::register(['name' => 'Anita Pal', 'phone' => '98000 11111', 'email' => 'anita@example.in', 'password' => 'stone-lover-1', 'remember' => '1', 'marketing_consent' => '1'], '10.0.8.1');
    $uid = (int) $u['id'];
    $cid = (int) $u['customer_id'];
    $order($cid, 'MSO/26-27/0101', 'delivered');
    $order($cid, 'MSO/26-27/0102', 'processing');
    $addr = CustomerService::saveOwnAddress($cid, ['address_line1' => '12 Lalbazar', 'state' => 'West Bengal', 'pincode' => '722101']);
    DB::table('customer_saved_items')->insert(['customer_id' => $cid, 'product_id' => 7, 'variant_id' => 0, 'created_at' => $now]);
    $cart = (int) DB::table('carts')->insertGetId(['token' => str_repeat('c', 40), 'customer_id' => $cid, 'user_id' => $uid, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('cart_items')->insert(['cart_id' => $cart, 'product_id' => 7, 'variant_id' => 0, 'quantity' => 2, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('customer_auth_codes')->insert(['user_id' => $uid, 'purpose' => 'reset_password', 'channel' => 'email', 'code_hash' => 'x', 'expires_at' => $now, 'created_at' => $now]);
    DB::table('product_reviews')->insert(['product_id' => 7, 'customer_name' => 'Anita P.', 'customer_email' => 'anita@example.in', 'customer_phone' => '9800011111', 'customer_id' => $cid, 'rating' => 5, 'status' => 'published']);
    DB::table('enquiries')->insert(['enquiry_number' => 'ENQ/26-27/0101', 'customer_id' => null, 'name' => 'Anita', 'phone' => '9800011111', 'email' => 'anita@example.in', 'message' => 'Kota for 2 rooms', 'created_at' => $now, 'updated_at' => $now]);
    $assert->that('setup: signed in with a remember token', CustomerAuth::resolve() !== null && DB::table('customer_remember_tokens')->where('user_id', $uid)->count() === 1);

    // ---------------------------------------------------------------- pages
    Context::setCustomer(CustomerAuth::resolve());
    $html = $router->dispatch($req('GET', '/account/profile'))->getContent();
    $assert->that('profile: links to /account/delete', str_contains($html, 'id="delete"') && str_contains($html, '/account/delete"'));
    $html = $router->dispatch($req('GET', '/account/delete'))->getContent();
    $assert->that('delete page: password + confirm box, orders kept count, open-order warning, noindex', str_contains($html, 'name="password"')
        && str_contains($html, 'name="confirm" value="1"') && str_contains($html, 'Your 2 orders') && str_contains($html, '1 order still in progress') && str_contains($html, 'noindex'));

    // ---------------------------------------------------------------- refusals
    $assert->that('delete: wrong password refused', isset($errs(fn() => CustomerAuth::deleteAccount($uid, 'wrong-one-99'))['password']));
    $res = $router->dispatch($req('POST', '/account/delete', ['password' => 'stone-lover-1']));
    $assert->that('delete: POST without the confirm box goes back', str_ends_with($loc($res), '/account/delete') && DB::table('users')->where('id', $uid)->value('status') === 'active');
    $assert->that('delete: nothing changed after refusals', DB::table('users')->where('id', $uid)->value('phone') === '9800011111' && DB::table('customer_addresses')->where('customer_id', $cid)->count() === 1);

    // ---------------------------------------------------------------- delete
    $res = $router->dispatch($req('POST', '/account/delete', ['password' => 'stone-lover-1', 'confirm' => '1']));
    $assert->that('delete: redirects to the public page with done=1, signed out', str_ends_with($loc($res), '/account-deletion?done=1')
        && session()->get(CustomerAuth::SESSION) === null && Context::customer() === null);
    $row = DB::selectOne('SELECT * FROM users WHERE id = ?', [$uid]);
    $assert->that('delete: login emptied (name, phone, email, password), status deleted', $row['name'] === CustomerAuth::DELETED_NAME && $row['phone'] === null
        && $row['email'] === null && $row['password'] === null && $row['status'] === 'deleted');
    $assert->that('delete: remember tokens + reset codes gone', DB::table('customer_remember_tokens')->where('user_id', $uid)->count() === 0
        && DB::table('customer_auth_codes')->where('user_id', $uid)->count() === 0);
    $assert->that('delete: saved list, bag and addresses gone', DB::table('customer_saved_items')->where('customer_id', $cid)->count() === 0
        && DB::table('carts')->where('id', $cart)->count() === 0 && DB::table('cart_items')->where('cart_id', $cart)->count() === 0 && DB::table('customer_addresses')->where('id', $addr)->count() === 0);
    $c = DB::selectOne('SELECT * FROM customers WHERE id = ?', [$cid]);
    $assert->that('delete: website-made customer record emptied and unlinked', $c['name'] === CustomerAuth::DELETED_NAME && $c['phone'] === 'deleted-' . $cid
        && $c['email'] === null && $c['user_id'] === null && (int) $c['marketing_consent'] === 0 && $c['status'] === 'inactive');
    $assert->that('delete: orders kept with their snapshot (GST)', DB::table('orders')->where('customer_id', $cid)->count() === 2
        && DB::table('orders')->where('order_number', 'MSO/26-27/0101')->value('customer_phone') === '9800011111');
    $rv = DB::selectOne('SELECT * FROM product_reviews WHERE product_id = 7');
    $assert->that('delete: review stays, anonymous', $rv['customer_name'] === 'A customer' && $rv['customer_email'] === null && $rv['customer_phone'] === null && $rv['customer_id'] === null);
    $en = DB::selectOne("SELECT * FROM enquiries WHERE enquiry_number = 'ENQ/26-27/0101'");
    $assert->that('delete: enquiry matched by phone loses name / phone / email', $en['name'] === CustomerAuth::DELETED_NAME && $en['phone'] === '' && $en['email'] === null);
    $audit = DB::selectOne("SELECT * FROM audit_logs WHERE entity_type = 'customer_account' AND entity_id = ?", [$uid]);
    $assert->that('delete: audit log row, orders_kept = 2', $audit !== null && $audit['action'] === 'delete' && json_decode($audit['changes'], true)['orders_kept'] === 2);
    $assert->that('delete: old password no longer signs in', $errs(fn() => null) === [] && (static function (): bool {
        try { CustomerAuth::attempt('9800011111', 'stone-lover-1'); return false; } catch (\App\Services\Store\AuthException) { return true; }
    })());
    $assert->that('delete: deleted user can\'t be deleted twice', (static function () use ($uid): bool {
        try { CustomerAuth::deleteAccount($uid, 'stone-lover-1'); return false; } catch (\App\Services\Store\AuthException) { return true; }
    })());
    $assert->that('delete: /account/delete now needs sign-in', str_contains($loc($router->dispatch($req('GET', '/account/delete'))), '/signin'));

    $u2 = CustomerAuth::register(['name' => 'Anita Pal', 'phone' => '9800011111', 'password' => 'fresh-start-22'], '10.0.8.2');
    $assert->that('re-register: same mobile works, gets a fresh customer (old orders stay detached)', (int) $u2['customer_id'] !== $cid
        && DB::table('orders')->where('customer_id', (int) $u2['customer_id'])->count() === 0);
    CustomerAuth::logout();

    // ---------------------------------------------------------------- a showroom customer's account: record kept, only unlinked
    $show = CustomerService::save(CustomerService::validate(['name' => 'Ritika Sen', 'phone' => '98300 12345', 'source' => 'showroom']));
    $sAddr = CustomerService::saveOwnAddress($show, ['address_line1' => 'Station Road', 'state' => 'West Bengal', 'pincode' => '722101']);
    $order($show, 'MSO/26-27/0103', 'delivered');
    $u3 = CustomerAuth::register(['name' => 'Ritika Sen', 'phone' => '9830012345', 'password' => 'marble-floor-3'], '10.0.8.3');
    $assert->that('setup: account linked to the showroom record', (int) $u3['customer_id'] === $show);
    CustomerAuth::deleteAccount((int) $u3['id'], 'marble-floor-3');
    $c = DB::selectOne('SELECT * FROM customers WHERE id = ?', [$show]);
    $assert->that('showroom: record keeps name / phone / address, just unlinked', $c['name'] === 'Ritika Sen' && $c['phone'] === '9830012345'
        && $c['user_id'] === null && (int) $c['is_online'] === 0 && DB::table('customer_addresses')->where('id', $sAddr)->count() === 1);
    $assert->that('showroom: the login is still emptied', DB::table('users')->where('id', (int) $u3['id'])->value('phone') === null);

    // ---------------------------------------------------------------- public pages
    $res = $router->dispatch($req('GET', '/account-deletion'));
    $html = $res->getContent();
    $assert->that('account-deletion: public, explains in-app steps, without sign-in, deleted vs kept', $res->getStatus() === 200
        && str_contains($html, 'Account → Profile') && str_contains($html, 'Without signing in') && str_contains($html, 'What we keep') && str_contains($html, '/policy#privacy'));
    $html = $router->dispatch($req('GET', '/policy'))->getContent();
    $assert->that('privacy: says what is collected (incl. push token, UTR, screenshot), why, how long, how to delete', str_contains($html, 'What we collect')
        && str_contains($html, 'Google Firebase') && str_contains($html, 'UTR') && str_contains($html, 'screenshot') && str_contains($html, 'How long we keep it')
        && str_contains($html, '/account-deletion') && str_contains($html, 'Last updated'));

    // ---------------------------------------------------------------- Play reviewer login
    $cmd = new class ($app) extends StoreDemoAccountCommand {
        public string $out = '';
        protected function line(string $message = ''): void { $this->out .= $message . "\n"; }
        protected function info(string $message): void { $this->line($message); }
        protected function comment(string $message): void { $this->line($message); }
        protected function error(string $message): void { $this->line($message); }
    };
    $code = $cmd->handle([]);
    $code2 = $cmd->handle(['--phone=9830012345']);           // the showroom customer's number
    $code3 = $cmd->handle(['--phone=9800011111']);           // Anita's new real account
    $code4 = $cmd->handle(['--phone=9000000099']);
    $out = $cmd->out;
    $assert->that('demo: refuses no phone, a showroom customer\'s phone and a real account', $code === 1 && $code2 === 1 && $code3 === 1);
    preg_match('/Password:\s+(\S+)/', $out, $m);
    $demo = DB::selectOne("SELECT * FROM users WHERE phone = '9000000099'");
    $dc = (int) DB::table('customers')->where('user_id', (int) $demo['id'])->value('id');
    $assert->that('demo: created, tagged Google Play review, password printed once', $code4 === 0 && isset($m[1])
        && DB::selectOne("SELECT 1 AS x FROM customer_tag_map m JOIN customer_tags t ON t.id = m.tag_id WHERE m.customer_id = ? AND t.slug = 'play-review'", [$dc]) !== null);
    $assert->that('demo: the printed password signs in', CustomerAuth::attempt('9000000099', $m[1])['id'] === (int) $demo['id']);
    CustomerAuth::logout();
    $cmd->out = '';
    $code5 = $cmd->handle(['--phone=9000000099']);
    $out = $cmd->out;
    preg_match('/Password:\s+(\S+)/', $out, $m2);
    $assert->that('demo: rerun resets the password (old one stops working)', $code5 === 0 && $m2[1] !== $m[1]
        && password_verify($m2[1], (string) DB::table('users')->where('id', (int) $demo['id'])->value('password'))
        && DB::table('users')->where('phone', '9000000099')->count() === 1);
};
