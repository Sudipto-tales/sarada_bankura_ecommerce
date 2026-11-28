<?php

use App\Services\Admin\GlobalSearch;
use App\Services\AdminAccess\Guard;
use App\Services\AdminAccess\Passwords;
use App\Services\AdminAccess\Roles;
use App\Services\Store\View;
use Vayu\Application;
use Vayu\Database\DB;
use Vayu\Http\Request;

/** Admin topbar global search: grouped suggestions (JSON), permissions, LIKE safety, results page, header wiring. */
return function (Assert $assert): void {
    $app = new Application(dirname(__DIR__));
    $app->config()->set('database.default', 'sqlite');
    $app->config()->set('database.connections.sqlite.database', ':memory:');
    $app->config()->set('cache.default', 'array');
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
    $_COOKIE = [];
    session()->start();
    $now = date('Y-m-d H:i:s');
    $get = static function (string $path, string $accept = 'application/json') use ($router) {
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);

        return $router->dispatch(new Request('GET', (string) parse_url($path, PHP_URL_PATH), $query, [], ['Accept' => $accept], ['REMOTE_ADDR' => '10.0.7.1'], [], null));
    };
    $json = static fn(string $q) => json_decode($get('/admin/search/suggest?q=' . rawurlencode($q))->getContent(), true);
    $titles = static fn(array $r, string $g): array => array_column($r['groups'][$g]['items'] ?? [], 'title');

    // ---- data ----
    DB::table('product_types')->insert(['name' => 'Marble', 'slug' => 'marble']);
    $pid = (int) DB::table('products')->insertGetId(['name' => 'Statuario White', 'sku' => 'STA-100', 'slug' => 'statuario-white', 'status' => 'active', 'product_type_id' => 1, 'created_at' => $now]);
    DB::table('product_pricing')->insert(['product_id' => $pid, 'price' => 1250, 'unit' => 'sqft']);
    DB::table('products')->insert(['name' => 'Black Galaxy', 'sku' => 'GAL_9', 'slug' => 'black-galaxy', 'status' => 'draft', 'product_type_id' => 1, 'created_at' => $now]);
    $cid = (int) DB::table('customers')->insertGetId(['name' => 'Ramesh Das', 'phone' => '9830012345', 'company' => 'Das Builders', 'customer_type' => 'builder', 'created_at' => $now, 'updated_at' => $now]);
    $oid = (int) DB::table('orders')->insertGetId(['order_number' => 'MSO/26-27/0042', 'customer_id' => $cid, 'customer_name' => 'Ramesh Das', 'customer_phone' => '9830012345',
        'order_status' => 'processing', 'public_token' => md5('o42'), 'grand_total' => 15000, 'created_at' => $now, 'updated_at' => $now]);
    $rid = (int) DB::table('return_requests')->insertGetId(['request_number' => 'RET/26-27/0007', 'order_id' => $oid, 'customer_id' => $cid, 'request_type' => 'return', 'reason' => 'Cracked', 'status' => 'submitted', 'created_at' => $now]);
    $eid = (int) DB::table('enquiries')->insertGetId(['enquiry_number' => 'ENQ/26-27/0003', 'name' => 'Sunita Pal', 'phone' => '9900011122', 'message' => 'Need statuario for kitchen', 'status' => 'new', 'created_at' => $now, 'updated_at' => $now]);

    // ---- auth ----
    $assert->that('guest: suggest is behind admin login', $get('/admin/search/suggest?q=sta')->getStatus() !== 200);
    Roles::sync();
    $mk = static function (string $email, string $role) use ($now): void {
        $id = (int) DB::table('admin_users')->insertGetId(['name' => $email, 'email' => $email, 'password' => Passwords::hash('admin-pass-1'), 'status' => 'active',
            'must_change_password' => 0, 'session_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('admin_user_roles')->insert(['admin_user_id' => $id, 'role_id' => (int) DB::table('roles')->where('slug', $role)->value('id')]);
    };
    $mk('owner@shop.in', 'owner');
    $mk('cat@shop.in', 'catalogue-manager');
    Guard::attempt('owner@shop.in', 'admin-pass-1');

    // ---- suggestions ----
    $r = $json('s');
    $assert->that('1 char: no query, empty groups', $r['groups'] === [] && $r['total'] === 0);
    $r = $json('statuario');
    $assert->equals('product by name', ['Statuario White'], $titles($r, 'products'));
    $assert->that('product item: sku + price in sub, status badge, admin link', str_contains($r['groups']['products']['items'][0]['sub'], 'STA-100')
        && str_contains($r['groups']['products']['items'][0]['sub'], '1,250') && $r['groups']['products']['items'][0]['badge'] === 'active'
        && str_ends_with($r['groups']['products']['items'][0]['url'], '/admin/products/' . $pid));
    $assert->that('enquiry message matches too, deep-links with open=', ($r['groups']['enquiries']['items'][0]['url'] ?? '') !== '' && str_contains($r['groups']['enquiries']['items'][0]['url'], 'open=' . $eid));
    $assert->that('"see all" links carry the query', str_contains($r['groups']['products']['more'], '/admin/products?q=statuario'));
    $assert->equals('order by number fragment', ['MSO/26-27/0042'], $titles($json('0042'), 'orders'));
    $r = $json('98300 12345');
    $assert->that('phone with a space finds the customer and the order', $titles($r, 'customers') === ['Ramesh Das'] && $titles($r, 'orders') === ['MSO/26-27/0042']);
    $assert->that('customer item: initials + show link', ($r['groups']['customers']['items'][0]['initials'] ?? '') === 'RD' && str_ends_with($r['groups']['customers']['items'][0]['url'], '/admin/customers/' . $cid));
    $assert->equals('customer by company', ['Ramesh Das'], $titles($json('das build'), 'customers'));
    $r = $json('RET/26');
    $assert->that('return by number → show page', $titles($r, 'returns') === ['RET/26-27/0007'] && str_ends_with($r['groups']['returns']['items'][0]['url'], '/admin/returns/' . $rid));
    $assert->that('page: "low stock" → Low-stock alerts first', ($titles($json('low stock'), 'pages')[0] ?? '') === 'Low-stock alerts');
    $assert->that('page: "new order" → Create an order', in_array('Create an order', $titles($json('new order'), 'pages'), true));
    $assert->that('only wildcards ("%%", "__") → nothing, not every row', $json('%%')['total'] === 0 && $json('__')['total'] === 0);
    $assert->equals('SKU with an underscore is found', ['Black Galaxy'], $titles($json('GAL_9'), 'products'));
    $assert->that('no hits → empty groups', $json('zzqqxx')['groups'] === []);
    $assert->that('owner: staff page is searchable', in_array('Staff', $titles($json('staff'), 'pages'), true));

    // ---- permissions ----
    Guard::forget();
    session()->forget('admin');
    Guard::attempt('cat@shop.in', 'admin-pass-1');
    $r = $json('statuario');
    $assert->that('no enquiries.view → enquiry group hidden', !isset($r['groups']['enquiries']) && isset($r['groups']['products']));
    $assert->that('no staff.manage → Staff page not suggested', !in_array('Staff', $titles($json('staff'), 'pages'), true));
    $assert->that('quick links respect permissions too', !in_array('Staff', array_column(GlobalSearch::quickLinks(), 'title'), true));

    // ---- results page + header wiring ----
    Guard::forget();
    session()->forget('admin');
    Guard::attempt('owner@shop.in', 'admin-pass-1');
    $html = $get('/admin/search?q=ramesh', 'text/html')->getContent();
    $assert->that('results page: groups, highlight, open-in links, query kept in the box', str_contains($html, 'Results for “ramesh”') && str_contains($html, '<mark>Ramesh</mark>')
        && str_contains($html, 'Open in Customers') && str_contains($html, 'value="ramesh"'));
    $html = $get('/admin/search?q=zzqqxx', 'text/html')->getContent();
    $assert->that('results page: empty state', str_contains($html, 'No matches for “zzqqxx”'));
    $html = $get('/admin/search', 'text/html')->getContent();
    $assert->that('results page: no query shows quick actions', str_contains($html, 'Search across the whole admin') && str_contains($html, 'Add a product'));
    $assert->that('topbar form posts to the search page, has the live panel + suggest URL', str_contains($html, 'id="gsForm"') && str_contains($html, '/admin/search/suggest')
        && str_contains($html, 'id="gsPanel"') && str_contains($html, 'role="combobox"'));
};
