<?php

use App\Services\Customer\CustomerService as CS;
use App\Services\Customer\CustomerStats;
use App\Services\Customer\Phone;
use App\Services\Customer\PincodeLookup;
use App\Services\Customer\SegmentMatcher as SM;
use Vayu\Application;
use Vayu\Database\DB;
use Vayu\Validation\ValidationException;

/** Stage 3: customers — phone identity, validation, online linking, segments, pincode cache. */
return function (Assert $assert): void {
    $app = new Application(dirname(__DIR__));
    $app->config()->set('database.default', 'sqlite');
    $app->config()->set('database.connections.sqlite.database', ':memory:');
    (require dirname(__DIR__) . '/database/migrations/2026_11_20_000001_create_users_table.php')->up();
    DB::statement('CREATE TABLE product_reviews (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, customer_phone TEXT, customer_email TEXT, customer_id INTEGER)');
    (require dirname(__DIR__) . '/database/migrations/2026_11_24_000010_create_customer_tables.php')->up();
    (require dirname(__DIR__) . '/database/seeders/CustomerSeeder.php')->run();

    // Phone
    $assert->equals('phone: +91 stripped', '9830012345', Phone::normalize('+91 98300-12345'));
    $assert->equals('phone: leading 0 stripped', '9830012345', Phone::normalize('09830012345'));
    $assert->equals('phone: landline-ish refused', null, Phone::normalize('0342 2250 12'));
    $assert->equals('phone: starts with 5 refused', null, Phone::normalize('5830012345'));
    $assert->equals('phone: formatted', '98300 12345', Phone::format('9830012345'));

    // Seeder
    $assert->equals('seed: 6 default segments', 6, (int) DB::table('customer_segments')->count());
    $assert->equals('seed: 5 tags', 5, (int) DB::table('customer_tags')->count());
    (require dirname(__DIR__) . '/database/seeders/CustomerSeeder.php')->run();
    $assert->equals('seed: idempotent', 6, (int) DB::table('customer_segments')->count());

    // Validation
    $fails = static function (array $in, ?int $id = null): array {
        try {
            CS::validate($in, $id);

            return [];
        } catch (ValidationException $e) {
            return $e->errors();
        }
    };
    $e = $fails(['name' => 'A', 'phone' => '123', 'email' => 'x@', 'gst_number' => 'ABC']);
    $assert->equals('validate: name/phone/email/gst errors', ['email', 'gst_number', 'name', 'phone'], (function ($k) { sort($k); return $k; })(array_keys($e)));
    $e = $fails(['name' => 'Rina', 'phone' => '9830012345', 'addresses' => ['k1' => ['city' => 'Bankura', 'pincode' => '012345']]]);
    $assert->that('validate: address needs line 1', isset($e['addresses.k1.address_line1']));
    $assert->that('validate: bad pincode', isset($e['addresses.k1.pincode']));
    $e = $fails(['name' => 'Rina', 'phone' => '9830012345', 'addresses' => ['k1' => ['type' => 'both', 'country' => 'India']]]);
    $assert->equals('validate: untouched blank address ignored', [], $e);

    // An online account exists before the offline customer is added → linked by phone.
    DB::table('users')->insert(['name' => 'Rina D', 'email' => 'rina@example.com', 'phone' => '9830012345', 'role' => 'user']);
    DB::table('product_reviews')->insert(['product_id' => 1, 'customer_phone' => '9830012345']);
    DB::table('product_reviews')->insert(['product_id' => 1, 'customer_phone' => '9000000000']);

    $data = CS::validate([
        'name' => '  Rina   Das ', 'phone' => '+91 98300 12345', 'email' => 'Rina@Example.COM', 'gst_number' => '19abcde1234f1z5',
        'customer_type' => 'builder', 'marketing_consent' => '1', 'source' => 'showroom', 'tags' => ['1', '999'], 'new_tags' => 'Kolkata project, kolkata PROJECT',
        'default_address' => 'b',
        'addresses' => [
            'a' => ['label' => 'Home', 'address_line1' => '12 Lalbazar', 'pincode' => '722101', 'city' => 'Bankura', 'state' => 'West Bengal'],
            'b' => ['label' => 'Site', 'address_line1' => 'Plot 4', 'pincode' => '700091', 'city' => 'Kolkata', 'phone' => '094330 12345'],
        ],
    ]);
    $assert->equals('validate: name squashed', 'Rina Das', $data['customer']['name']);
    $assert->equals('validate: email lower-cased', 'rina@example.com', $data['customer']['email']);
    $assert->equals('validate: GSTIN upper-cased', '19ABCDE1234F1Z5', $data['customer']['gst_number']);
    $assert->equals('validate: unknown tag id dropped', [1], $data['tag_ids']);
    $assert->equals('validate: new tags de-duplicated', 1, count($data['new_tags']));
    $assert->equals('validate: receiver phone normalised', '9433012345', $data['addresses'][1]['phone']);
    $cid = CS::save($data);
    $c = DB::selectOne('SELECT * FROM customers WHERE id = ?', [$cid]);
    $assert->that('save: linked to online account by phone', (int) $c['is_online'] === 1 && (int) $c['user_id'] === 1);
    $assert->equals('save: source kept', 'showroom', $c['source']);
    $assert->equals('save: review from same phone attached', 1, (int) DB::table('product_reviews')->where('customer_id', $cid)->count());
    $assert->equals('save: chosen default address', 'Site', DB::table('customer_addresses')->where('customer_id', $cid)->where('is_default', 1)->value('label'));
    $assert->equals('save: 2 tags (VIP + new)', 2, (int) DB::table('customer_tag_map')->where('customer_id', $cid)->count());
    $assert->that('save: new tag created', DB::table('customer_tags')->where('slug', 'kolkata-project')->exists());

    $e = $fails(['name' => 'Someone', 'phone' => '98300 12345']);
    $assert->that('validate: duplicate phone refused', str_contains($e['phone'][0] ?? '', 'Rina Das'));
    $assert->equals('validate: own phone allowed on edit', [], $fails(['name' => 'Rina Das', 'phone' => '9830012345'], $cid));

    // Segments: defaults after save
    $builders = (int) DB::table('customer_segments')->where('slug', 'builders-contractors')->value('id');
    $online = (int) DB::table('customer_segments')->where('slug', 'online-customers')->value('id');
    $inactive = (int) DB::table('customer_segments')->where('slug', 'inactive-customers')->value('id');
    $member = static fn(int $sid, int $c) => DB::table('customer_segment_members')->where('segment_id', $sid)->where('customer_id', $c)->exists();
    $assert->that('segment: builder joined Builders', $member($builders, $cid));
    $assert->that('segment: linked customer joined Online', $member($online, $cid));
    $assert->that('segment: no orders → not Inactive', !$member($inactive, $cid));

    // Update: addresses upserted by id, removed ones deleted, source can't change.
    $addr = DB::select('SELECT id, label FROM customer_addresses WHERE customer_id = ? ORDER BY id', [$cid]);
    CS::save(CS::validate(['name' => 'Rina Das', 'phone' => '9830012345', 'customer_type' => 'dealer', 'source' => 'online', 'tags' => [],
        'addresses' => ['x' => ['id' => $addr[0]['id'], 'label' => 'Home 2', 'address_line1' => '12 Lalbazar']]], $cid), $cid);
    $rows = DB::select('SELECT id, label, is_default FROM customer_addresses WHERE customer_id = ?', [$cid]);
    $assert->that('update: address kept its id, other removed', count($rows) === 1 && (int) $rows[0]['id'] === (int) $addr[0]['id'] && $rows[0]['label'] === 'Home 2');
    $assert->equals('update: sole address becomes default', 1, (int) $rows[0]['is_default']);
    $assert->equals('update: source unchanged', 'showroom', DB::table('customers')->where('id', $cid)->value('source'));
    $assert->that('update: left Builders segment', !$member($builders, $cid));

    // Second customer, offline, Bankura address, no consent.
    $c2 = CS::save(CS::validate(['name' => 'Amit Kundu', 'phone' => '9000000000', 'customer_type' => 'architect',
        'addresses' => ['a' => ['address_line1' => 'Station Rd', 'city' => 'Bankura', 'district' => 'Bankura', 'pincode' => '722101']]]));
    $assert->equals('reviews: second phone attached too', $c2, (int) DB::table('product_reviews')->where('customer_phone', '9000000000')->value('customer_id'));

    // Matcher: each field type, on SQLite
    $count = static fn(array $rules, string $m = 'all') => SM::count($rules, $m);
    $r = static fn(string $f, string $o, ?string $v = null) => ['field' => $f, 'operator' => $o, 'value' => $v];
    $assert->equals('match: select is', 1, $count([$r('customer_type', 'is', 'architect')]));
    $assert->equals('match: select is_not', 1, $count([$r('customer_type', 'is_not', 'architect')]));
    $assert->equals('match: address city case-insensitive', 1, $count([$r('city', 'is', 'BANKURA')]));
    $assert->equals('match: address is_not → customers without it', 1, $count([$r('city', 'is_not', 'bankura')]));
    $assert->equals('match: pincode starts_with', 1, $count([$r('pincode', 'starts_with', '722')]));
    $assert->equals('match: city is_empty', 1, $count([$r('city', 'is_empty')]));
    $assert->equals('match: number gte (dormant spend)', 0, $count([$r('total_spent', 'gte', '1')]));
    $assert->equals('match: number lte orders', 2, $count([$r('total_orders', 'lte', '1')]));
    $assert->equals('match: aov eq 0', 2, $count([$r('aov', 'eq', '0')]));
    $assert->equals('match: bool online', 1, $count([$r('is_online', 'is_true')]));
    $assert->equals('match: bool consent false', 2, $count([$r('marketing_consent', 'is_false')]));
    $assert->equals('match: has_email (edit cleared it)', 0, $count([$r('has_email', 'is_true')]));
    $assert->equals('match: has_email false', 2, $count([$r('has_email', 'is_false')]));
    $assert->equals('match: created in last 1 day', 2, $count([$r('created_at', 'in_last_days', '1')]));
    $assert->equals('match: last order never', 2, $count([$r('last_order_at', 'is_empty')]));
    $assert->equals('match: date after tomorrow', 0, $count([$r('created_at', 'after', date('Y-m-d', time() + 86400))]));
    $assert->equals('match: date before tomorrow', 2, $count([$r('created_at', 'before', date('Y-m-d', time() + 86400))]));
    $vip = (int) DB::table('customer_tags')->where('slug', 'vip')->value('id');
    CS::syncTags($c2, [$vip]);
    $assert->equals('match: tag has', 1, $count([$r('tag', 'has', (string) $vip)]));
    $assert->equals('match: tag has_not', 1, $count([$r('tag', 'has_not', (string) $vip)]));
    $assert->equals('match: AND', 0, $count([$r('customer_type', 'is', 'architect'), $r('is_online', 'is_true')]));
    $assert->equals('match: OR', 2, $count([$r('customer_type', 'is', 'architect'), $r('is_online', 'is_true')], 'any'));
    $assert->equals('match: no rules → nobody', 0, $count([]));

    // clean(): whitelist + errors
    [$ok, $errs] = SM::clean([
        ['field' => 'customer_type', 'operator' => 'is', 'value' => 'dealer'],
        ['field' => 'evil; DROP', 'operator' => 'is', 'value' => 'x'],
        ['field' => 'total_spent', 'operator' => 'gte', 'value' => 'abc'],
        ['field' => 'customer_type', 'operator' => 'is', 'value' => 'pilot'],
        ['field' => 'is_online', 'operator' => 'is_true', 'value' => 'ignored'],
        ['field' => 'created_at', 'operator' => 'before', 'value' => '2026-02-30'],
    ]);
    $assert->equals('clean: 2 valid rules kept', 2, count($ok));
    $assert->equals('clean: errors on rows 1,2,3,5', [1, 2, 3, 5], array_keys($errs));
    $assert->equals('clean: no-value operator stores null', null, $ok[1]['value']);
    $assert->equals('describe: days phrasing', 'Last order more than 90 days ago', SM::describe($r('last_order_at', 'older_than_days', '90')));
    $assert->equals('describe: tag name', 'Customer tag has VIP', SM::describe($r('tag', 'has', (string) $vip), [$vip => 'VIP']));

    // Static segment members + refresh counts
    $sid = (int) DB::table('customer_segments')->insertGetId(['name' => 'Diwali', 'slug' => 'diwali', 'type' => 'static']);
    DB::table('customer_segment_members')->insert(['segment_id' => $sid, 'customer_id' => $c2]);
    $assert->equals('static: refresh counts members', 1, SM::refresh($sid));
    [$sql, $bind] = SM::forSegment(DB::selectOne('SELECT * FROM customer_segments WHERE id = ?', [$sid]));
    $assert->equals('static: forSegment uses members', 1, (int) DB::selectOne("SELECT COUNT(*) AS n FROM customers c WHERE $sql", $bind)['n']);

    // Stats stub
    $assert->that('stats: live since Stage 4', CustomerStats::enabled());
    $assert->equals('stats: lifecycle vip', 'vip', CustomerStats::lifecycle(3, 200000));
    $assert->equals('stats: lifecycle returning', 'returning', CustomerStats::lifecycle(2, 5000));
    $assert->equals('stats: aov', 2500.0, CustomerStats::aov(2, 5000));

    // linkUser (storefront hook): new phone creates an online customer, existing phone links.
    DB::table('users')->insert(['name' => 'Web Buyer', 'email' => 'web@example.com', 'phone' => '+91 7000000001', 'role' => 'user']);
    $new = CS::linkUser(2);
    $assert->that('linkUser: creates online customer', $new !== null && DB::table('customers')->where('id', $new)->value('source') === 'online');
    DB::table('users')->insert(['name' => 'Amit', 'email' => 'amit@example.com', 'phone' => '9000000000', 'role' => 'user']);
    $assert->equals('linkUser: existing phone links, no duplicate', $c2, CS::linkUser(3));
    $assert->equals('linkUser: customer count', 3, (int) DB::table('customers')->count());

    // Pincode cache with a fake upstream
    $calls = 0;
    PincodeLookup::$fetcher = function (string $code) use (&$calls): array {
        $calls++;
        return match ($code) {
            '722101' => ['status' => 200, 'body' => json_encode(['data' => ['district' => 'Bankura', 'state' => 'West Bengal', 'stateCode' => 'WB',
                'offices' => [['name' => 'Lalbazar  SO'], ['name' => 'Bankura HO'], ['name' => 'Bankura HO']]]])],
            '999999' => ['status' => 404, 'body' => '{}'],
            default => ['status' => 0, 'body' => null],
        };
    };
    $assert->equals('pincode: invalid', 'invalid', PincodeLookup::find('012345')['reason'] ?? '');
    $p = PincodeLookup::find('722101');
    $assert->that('pincode: remote hit normalised + de-duplicated', $p['ok'] && $p['areas'] === ['Bankura HO', 'Lalbazar SO'] && $p['cached'] === false);
    $p = PincodeLookup::find('722101');
    $assert->that('pincode: second call from cache', $p['cached'] === true && $calls === 1);
    $assert->equals('pincode: not found', 'not_found', PincodeLookup::find('999999')['reason'] ?? '');
    $assert->equals('pincode: outage', 'unavailable', PincodeLookup::find('700091')['reason'] ?? '');
    DB::table('pincodes')->where('pincode', '722101')->update(['cached_at' => '2020-01-01 00:00:00']);
    $p = PincodeLookup::find('722101');
    $assert->that('pincode: stale cache refreshed from upstream', $p['ok'] && $p['cached'] === false && $calls === 4);
    PincodeLookup::$fetcher = null;

    // Delete: child rows gone, reviews unlinked, segment counts updated.
    CS::delete($c2);
    $assert->that('delete: customer gone', !DB::table('customers')->where('id', $c2)->exists());
    $assert->equals('delete: addresses gone', 0, (int) DB::table('customer_addresses')->where('customer_id', $c2)->count());
    $assert->equals('delete: members gone', 0, (int) DB::table('customer_segment_members')->where('customer_id', $c2)->count());
    $assert->equals('delete: static segment recounted', 0, (int) DB::table('customer_segments')->where('id', $sid)->value('member_count'));
    $assert->equals('delete: review kept but unlinked', null, DB::table('product_reviews')->where('customer_phone', '9000000000')->value('customer_id'));
};
