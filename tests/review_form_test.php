<?php

use App\Services\Reviews\ReviewSubmission as RS;
use Vayu\Application;
use Vayu\Database\DB;

/** Stage 2: public review form rules (validation, spam guards, rate limit, write + notification). */
return function (Assert $assert): void {
    $app = new Application(dirname(__DIR__));
    $app->config()->set('database.default', 'sqlite');
    $app->config()->set('database.connections.sqlite.database', ':memory:');
    DB::statement("CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, sku TEXT, slug TEXT, status TEXT, short_description TEXT)");
    DB::statement("CREATE TABLE product_reviews (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, customer_name TEXT, customer_email TEXT,
        city TEXT, application TEXT, rating INTEGER, title TEXT, body TEXT, source TEXT, status TEXT, response TEXT, responded_at TEXT,
        created_at TEXT, updated_at TEXT)");
    (require dirname(__DIR__) . '/database/migrations/2026_11_23_000009_extend_product_reviews.php')->up();
    (require dirname(__DIR__) . '/database/migrations/2026_11_22_000006_create_inventory_and_notifications_tables.php')->up();
    (require dirname(__DIR__) . '/database/migrations/2026_11_23_000008_create_media_tables.php')->up();
    DB::table('products')->insert(['name' => 'Statuario', 'sku' => 'S1', 'slug' => 'statuario', 'status' => 'active']);
    DB::table('products')->insert(['name' => 'Draft Stone', 'sku' => 'D1', 'slug' => 'draft-stone', 'status' => 'draft']);

    // Product lookup
    $assert->that('product: active found by slug (case-insensitive)', RS::product('Statuario') !== null);
    $assert->that('product: draft not reviewable', RS::product('draft-stone') === null);
    $assert->that('product: unknown slug', RS::product('nope') === null);
    $product = RS::product('statuario');

    // Time-trap stamp
    $assert->that('stamp: fresh form too fast', !RS::stampOk(RS::stamp()));
    $assert->that('stamp: 10s old is fine', RS::stampOk(RS::stamp(time() - 10)));
    $assert->that('stamp: expired after 2 days', !RS::stampOk(RS::stamp(time() - 86400 * 3)));
    $tampered = preg_replace('/^\d+/', (string) (time() - 60), RS::stamp(time() - 10));
    $assert->that('stamp: tampered time rejected', !RS::stampOk((string) $tampered));
    $assert->that('stamp: garbage rejected', !RS::stampOk('abc'));

    // Validation
    $good = ['rating' => '5', 'title' => 'Beautiful floor', 'body' => 'Polished beautifully and the fitting team was careful.', 'name' => 'Rina Das',
        'contact' => '+91 98300 12345', 'city' => 'Bankura', 'application' => 'Flooring', 'ts' => RS::stamp(time() - 30), 'website' => ''];
    [$d, $e] = RS::validate($good);
    $assert->equals('validate: clean input has no errors', [], $e);
    $assert->equals('validate: +91 mobile normalised', '9830012345', $d['customer_phone']);
    $assert->equals('validate: application kept', 'Flooring', $d['application']);
    [, $e] = RS::validate(['rating' => '0', 'title' => ' ', 'body' => 'too short', 'name' => 'A', 'contact' => '12345']);
    $assert->equals('validate: all required errors', ['body', 'contact', 'name', 'rating', 'title'], (function ($k) { sort($k); return $k; })(array_keys($e)));
    [$d] = RS::validate($good + ['contact' => 'Rina@Example.com']);
    [$d2] = RS::validate(array_merge($good, ['contact' => 'Rina@Example.com']));
    $assert->equals('validate: email lower-cased', 'rina@example.com', $d2['customer_email']);
    [$d3] = RS::validate(array_merge($good, ['application' => 'Rocket']));
    $assert->equals('validate: unknown application dropped', null, $d3['application']);
    [, $e4] = RS::validate(array_merge($good, ['body' => 'Great stone http://spam.example and http://more.example here']));
    $assert->that('validate: links refused', isset($e4['body']));

    // Spam guards: pretend success, store nothing
    $ip = '203.0.113.' . random_int(1, 254) . ':' . bin2hex(random_bytes(3));
    $res = RS::submit($product, array_merge($good, ['website' => 'http://bot']), [], $ip, 'bot');
    $assert->that('honeypot: answers ok but flags spam', $res['ok'] && !empty($res['spam']));
    $res = RS::submit($product, array_merge($good, ['ts' => RS::stamp()]), [], $ip, 'fast');
    $assert->that('time trap: instant submit is spam', !empty($res['spam']));
    $assert->equals('spam: nothing stored', 0, (int) DB::table('product_reviews')->count());

    // Real submit
    $res = RS::submit($product, $good, [], $ip, 'Mozilla/5.0 test');
    $assert->that('submit: ok with review id', $res['ok'] && ($res['review_id'] ?? 0) > 0);
    $row = DB::table('product_reviews')->where('id', (int) $res['review_id'])->first();
    $assert->equals('submit: pending', 'pending', $row['status']);
    $assert->equals('submit: source storefront', 'storefront', $row['source']);
    $assert->equals('submit: ip stored', $ip, $row['ip']);
    $assert->equals('submit: not verified yet', 0, (int) $row['is_verified_purchase']);
    $note = DB::table('notifications')->where('type', 'review.submitted')->first();
    $assert->that('submit: admin notified', $note !== null && str_contains((string) $note['title'], '5★'));

    // Double submit (refresh) → same review, no second row
    $again = RS::submit($product, $good, [], $ip, 'x');
    $assert->equals('duplicate: returns the same review', $res['review_id'], $again['review_id']);
    $assert->equals('duplicate: still one row', 1, (int) DB::table('product_reviews')->count());

    // Rate limit per IP
    for ($i = 0; $i < RS::MAX_PER_HOUR; $i++) {
        RS::submit($product, array_merge($good, ['body' => $good['body'] . ' Note ' . $i . '.']), [], $ip, 'x');
    }
    $blocked = RS::submit($product, array_merge($good, ['body' => $good['body'] . ' Blocked.']), [], $ip, 'x');
    $assert->that('rate limit: blocked after ' . RS::MAX_PER_HOUR . ' per hour', !$blocked['ok'] && isset($blocked['errors']['form']));
    $assert->equals('rate limit: stored only the allowed ones', RS::MAX_PER_HOUR, (int) DB::table('product_reviews')->count());
    app('rate_limiter')->clear('review:ip:' . $ip);
};
