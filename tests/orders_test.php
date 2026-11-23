<?php

use App\Services\Customer\CustomerService as CS;
use App\Services\Inventory\InventoryService as Inv;
use App\Services\Order\DocumentNumber;
use App\Services\Order\DocumentService as Docs;
use App\Services\Order\Money;
use App\Services\Order\OrderException;
use App\Services\Order\OrderService as Orders;
use Vayu\Application;
use Vayu\Database\DB;
use Vayu\Validation\ValidationException;

/** Stage 4: numbering, totals + snapshots, workflow whitelist, dispatch stock-out, invoice, OTP, customer stats. */
return function (Assert $assert): void {
    $app = new Application(dirname(__DIR__));
    $app->config()->set('database.default', 'sqlite');
    $app->config()->set('database.connections.sqlite.database', ':memory:');
    $files = glob(dirname(__DIR__) . '/database/migrations/2026_*.php');
    sort($files);
    foreach ($files as $f) {
        ob_start();
        (require $f)->up();
        ob_end_clean();
    }
    $now = date('Y-m-d H:i:s');
    DB::table('units')->insert(['name' => 'Square foot', 'symbol' => 'sq ft']);
    DB::table('units')->insert(['name' => 'Box', 'symbol' => 'box']);
    DB::table('product_types')->insert(['name' => 'Marble', 'slug' => 'marble']);
    $pid = (int) DB::table('products')->insertGetId(['name' => 'Statuario', 'sku' => 'STA', 'slug' => 'statuario', 'status' => 'active', 'product_type_id' => 1, 'created_at' => $now]);
    DB::table('product_pricing')->insert(['product_id' => $pid, 'price' => 250, 'unit' => 'box', 'pack_qty' => 10, 'pack_unit' => 'sq ft']);
    $pid2 = (int) DB::table('products')->insertGetId(['name' => 'Granite', 'sku' => 'GRA', 'slug' => 'granite', 'status' => 'active', 'product_type_id' => 1, 'created_at' => $now]);
    DB::table('product_pricing')->insert(['product_id' => $pid2, 'price' => 100, 'unit' => 'sq ft']);
    $vid = (int) DB::table('product_variants')->insertGetId(['product_id' => $pid2, 'sku' => 'GRA-18', 'title' => '18 mm / Polished', 'price' => 120, 'status' => 'active']);
    $pid3 = (int) DB::table('products')->insertGetId(['name' => 'Old stone', 'sku' => 'OLD', 'slug' => 'old', 'status' => 'archived', 'product_type_id' => 1, 'created_at' => $now]);
    DB::table('product_return_policies')->insert(['product_id' => $pid, 'variant_id' => 0, 'return_allowed' => 1, 'return_days' => 7, 'is_active' => 1]);
    Inv::startTracking($pid, 'box', 5);

    // Money + numbering
    $assert->equals('money: Indian grouping', '1,23,45,678.50', Money::format(12345678.5));
    $assert->equals('money: small', '999.00', Money::format(999));
    $assert->equals('words: lakh', 'Rupees One Lakh Twenty Three Thousand Four Hundred Fifty Six Only', Money::words(123456));
    $assert->equals('words: paise', 'Rupees Ten and Fifty Paise Only', Money::words(10.5));
    $assert->equals('fy: 31 Mar 2027 IST', '26-27', DocumentNumber::fy(strtotime('2027-03-31 23:00:00 Asia/Kolkata')));
    $assert->equals('fy: 1 Apr 2027 IST', '27-28', DocumentNumber::fy(strtotime('2027-04-01 00:30:00 Asia/Kolkata')));
    $threw = false;
    try { DocumentNumber::next('order'); } catch (\LogicException) { $threw = true; }
    $assert->that('number: refused outside a transaction', $threw);
    $n1 = DB::transaction(fn() => DocumentNumber::next('return', strtotime('2026-12-01')));
    $n2 = DB::transaction(fn() => DocumentNumber::next('return', strtotime('2026-12-02')));
    try { DB::transaction(function () { DocumentNumber::next('return', strtotime('2026-12-03')); throw new \RuntimeException('x'); }); } catch (\RuntimeException) {}
    $n3 = DB::transaction(fn() => DocumentNumber::next('return', strtotime('2026-12-04')));
    $assert->equals('number: sequential', ['RET/26-27/0001', 'RET/26-27/0002', 'RET/26-27/0003'], [$n1, $n2, $n3]);
    $assert->equals('number: new FY restarts', 'RET/27-28/0001', DB::transaction(fn() => DocumentNumber::next('return', strtotime('2027-05-01'))));

    // Create — validation
    $fails = static function (array $in): array {
        try { Orders::create($in); return []; } catch (ValidationException $e) { return $e->errors(); }
    };
    $e = $fails(['new_customer' => ['name' => 'X', 'phone' => '12'], 'items' => []]);
    $assert->that('create: bad phone + no items + no address', isset($e['new_customer.phone'], $e['items'], $e['new_shipping.address_line1']));
    $e = $fails(['new_customer' => ['name' => 'Rina', 'phone' => '9830012345'], 'delivery' => 'pickup',
        'items' => [['product_id' => $pid2, 'quantity' => 2], ['product_id' => $pid3, 'quantity' => 1], ['product_id' => $pid, 'quantity' => 0]]]);
    $assert->that('create: variant required when product has options', isset($e['items.0.variant_id']));
    $assert->that('create: archived product refused', isset($e['items.1.product_id']));
    $assert->that('create: zero qty refused', isset($e['items.2.quantity']));
    $e = $fails(['new_customer' => ['name' => 'Rina', 'phone' => '9830012345'], 'delivery' => 'pickup', 'discount_amount' => '9999',
        'gst_enabled' => '1', 'gst_rate' => '40', 'items' => [['product_id' => $pid, 'quantity' => 1]]]);
    $assert->that('create: discount > subtotal and bad GST', isset($e['discount_amount'], $e['gst_rate']));
    $assert->equals('create: nothing written on failure', 0, (int) DB::table('customers')->count() + (int) DB::table('orders')->count());

    $e = $fails(['new_customer' => ['name' => 'Rina', 'phone' => '9830012345'], 'delivery' => 'pickup', 'gst_enabled' => '1', 'gst_rate' => '18',
        'items' => [['product_id' => $pid, 'quantity' => 1]]]);
    $assert->that('create: no seller GSTIN → GST refused', str_contains($e['gst_rate'][0] ?? '', 'GSTIN'));
    $app->config()->set('business.gstin', '19ABCDE1234F1Z5');

    // Create — happy path: new customer, new address (other state), GST on top
    $oid = Orders::create([
        'new_customer' => ['name' => 'Rina Das', 'phone' => '+91 98300 12345'], 'channel_detail' => 'phone',
        'new_shipping' => ['address_line1' => 'Plot 4, Salt Lake', 'city' => 'Bhubaneswar', 'state' => 'Odisha', 'pincode' => '751001'], 'billing_same' => '1',
        'items' => [
            ['product_id' => $pid, 'quantity' => '3', 'discount' => '50'],                         // 3 box × 250 − 50 = 700
            ['product_id' => $pid2, 'variant_id' => $vid, 'quantity' => '10', 'unit_price' => '110'], // 10 × 110 = 1100
        ],
        'discount_amount' => '100', 'gst_enabled' => '1', 'gst_rate' => '18', 'notes' => 'Deliver before Sunday',
    ], 7);
    $o = DB::selectOne('SELECT * FROM orders WHERE id = ?', [$oid]);
    $assert->that('create: order number series', str_starts_with((string) $o['order_number'], 'MSO/') && str_ends_with((string) $o['order_number'], '/0001'));
    $assert->equals('totals: subtotal', 1800.0, (float) $o['subtotal']);
    $assert->equals('totals: GST 18% on 1700', 306.0, (float) $o['tax_amount']);
    $assert->equals('totals: grand total (GST on top)', 2006.0, (float) $o['grand_total']);
    $assert->equals('payment cache: due = total', 2006.0, (float) $o['due_amount']);
    $assert->equals('payment cache: unpaid', 'unpaid', $o['payment_status']);
    $assert->equals('snapshot: place of supply', 'Odisha', $o['place_of_supply']);
    $assert->that('snapshot: billing = shipping', $o['billing_address'] === $o['shipping_address'] && $o['billing_address'] !== null);
    $items = DB::select('SELECT * FROM order_items WHERE order_id = ? ORDER BY sort', [$oid]);
    $assert->equals('snapshot: variant name + sku', ['18 mm / Polished', 'GRA-18'], [$items[1]['variant_name'], $items[1]['sku']]);
    $assert->equals('snapshot: product unit', 'box', $items[0]['unit']);
    $assert->equals('snapshot: return policy', [1, 7], [(int) $items[0]['return_allowed'], (int) $items[0]['return_days']]);
    $cid = (int) $o['customer_id'];
    $assert->equals('customer: created with mobile', '9830012345', DB::table('customers')->where('id', $cid)->value('phone'));
    $assert->equals('customer: new address saved + default', 1, (int) DB::table('customer_addresses')->where('customer_id', $cid)->where('is_default', 1)->count());
    $assert->that('stats: last_order_at set on create', DB::table('customers')->where('id', $cid)->value('last_order_at') !== null);
    $assert->equals('stats: not counted until delivered', 0, (int) DB::table('customers')->where('id', $cid)->value('total_orders'));

    // Same mobile again → same customer, existing address id
    $addrId = (int) DB::table('customer_addresses')->where('customer_id', $cid)->value('id');
    $oid2 = Orders::create(['new_customer' => ['name' => 'R Das', 'phone' => '9830012345'], 'shipping_address_id' => (string) $addrId, 'billing_same' => '1',
        'items' => [['product_id' => $pid, 'quantity' => 1]]]);
    $assert->equals('create: known mobile reuses customer', $cid, (int) DB::table('orders')->where('id', $oid2)->value('customer_id'));
    $assert->equals('create: no GST → tax 0', 0.0, (float) DB::table('orders')->where('id', $oid2)->value('tax_amount'));

    // Workflow whitelist
    $refused = static function (callable $fn): bool { try { $fn(); return false; } catch (OrderException) { return true; } };
    $assert->that('flow: pending → packed refused', $refused(fn() => Orders::transition($oid, 'packed')));
    $assert->that('flow: reject needs a customer note', $refused(fn() => Orders::transition($oid2, 'rejected')));
    Orders::transition($oid2, 'rejected', ['customer_note' => 'Out of this lot, sorry.']);
    $assert->that('flow: rejected is terminal', $refused(fn() => Orders::transition($oid2, 'accepted')));
    $assert->equals('flow: rejected owes nothing', 0.0, (float) DB::table('orders')->where('id', $oid2)->value('due_amount'));

    $assert->equals('accept: stock warning (needs 3 box, 5 in stock → none)', [], Orders::stockWarnings($oid));
    Inv::adjustTo($pid, 2, 'count_correction');
    $assert->equals('accept: stock warning when short', 1, count(Orders::stockWarnings($oid)));
    Orders::transition($oid, 'accepted', ['by' => 7]);
    $assert->that('flow: accepted cannot be rejected (no cancel after confirm)', $refused(fn() => Orders::transition($oid, 'rejected', ['customer_note' => 'x'])));
    Orders::transition($oid, 'processing');
    Orders::transition($oid, 'packed');
    $assert->equals('packed: packing slip created', 1, (int) DB::table('order_invoices')->where('order_id', $oid)->where('document_type', 'packing_slip')->count());
    $assert->equals('packed: no invoice yet', null, Docs::invoiceFor($oid));

    // Dispatch: invoice + stock out (negative allowed) + OTP
    $res = Orders::transition($oid, 'dispatched', ['courier_name' => 'Own truck', 'tracking_number' => 'WB-12', 'tracking_url' => 'javascript:alert(1)']);
    $inv = Docs::invoiceFor($oid);
    $assert->that('dispatch: invoice numbered', $inv && preg_match('#^MSM/\d\d-\d\d/0001$#', (string) $inv['invoice_number']) === 1);
    $assert->equals('dispatch: GSTIN + GST → Tax Invoice', Docs::TAX_INVOICE, $inv['title']);
    $assert->equals('title: no GST charged → Bill of Supply', Docs::BILL_OF_SUPPLY, Docs::title(['gst_rate' => null]));
    $assert->equals('dispatch: other state → IGST', 'igst', $inv['tax_mode']);
    $assert->equals('dispatch: stock may go negative (2 − 3)', -1.0, (float) DB::table('inventory_stock')->where('product_id', $pid)->value('quantity'));
    $assert->equals('dispatch: sale movement references order', $o['order_number'], DB::table('inventory_movements')->where('type', 'sale')->value('reference_no'));
    $assert->equals('dispatch: untracked product skipped', 1, (int) DB::table('inventory_movements')->where('type', 'sale')->count());
    $assert->equals('dispatch: unsafe tracking URL dropped', null, DB::table('order_deliveries')->where('order_id', $oid)->value('tracking_url'));
    $assert->that('dispatch: 6-digit OTP returned, only hash stored', preg_match('/^\d{6}$/', (string) $res['otp']) === 1
        && DB::table('order_deliveries')->where('order_id', $oid)->value('otp_hash') !== $res['otp']);
    $assert->equals('tax rows: IGST one line', 1, count(Docs::taxRows('igst', 18, 306)));
    $assert->equals('tax rows: CGST+SGST halves', [153.0, 153.0], array_column(Docs::taxRows('cgst_sgst', 18, 306), 'amount'));

    // Delivered: OTP attempts, manual override
    $wrong = $res['otp'] === '000000' ? '111111' : '000000';
    $assert->that('deliver: wrong OTP refused', $refused(fn() => Orders::transition($oid, 'delivered', ['otp' => $wrong])));
    $assert->equals('deliver: attempt counted', 1, (int) DB::table('order_deliveries')->where('order_id', $oid)->value('otp_attempts'));
    $assert->that('deliver: manual needs a reason', $refused(fn() => Orders::transition($oid, 'delivered', ['manual' => 1, 'manual_reason' => 'ok'])));
    $otp = Orders::resendOtp($oid);
    $assert->that('deliver: old OTP dead after resend', $otp === $res['otp'] || $refused(fn() => Orders::transition($oid, 'delivered', ['otp' => $res['otp']])));
    Orders::transition($oid, 'delivered', ['otp' => $otp]);
    $assert->equals('deliver: delivered', 'delivered', DB::table('orders')->where('id', $oid)->value('order_status'));
    $c = DB::selectOne('SELECT * FROM customers WHERE id = ?', [$cid]);
    $assert->equals('stats: delivered order counted', [1, 2006.0], [(int) $c['total_orders'], (float) $c['total_spent']]);
    $assert->equals('history: one row per step (+ OTP resend)', 7, (int) DB::table('order_status_history')->where('order_id', $oid)->count());

    // Bulk: same status only
    $a = Orders::create(['customer_id' => $cid, 'delivery' => 'pickup', 'items' => [['product_id' => $pid, 'quantity' => 1]]]);
    $b = Orders::create(['customer_id' => $cid, 'delivery' => 'pickup', 'items' => [['product_id' => $pid, 'quantity' => 1]]]);
    $assert->that('bulk: dispatch is one-by-one', $refused(fn() => Orders::bulk([$a, $b], 'dispatched')));
    $r = Orders::bulk([$a, $b], 'accepted');
    $assert->equals('bulk: both accepted', 2, count($r['moved']));
    Orders::transition($a, 'processing');
    $assert->that('bulk: mixed statuses refused', $refused(fn() => Orders::bulk([$a, $b], 'processing')));
    $assert->equals('pickup: no address, seller state', business('address.state'), DB::table('orders')->where('id', $a)->value('place_of_supply'));
    $assert->equals('pickup: CGST+SGST mode', 'cgst_sgst', Docs::taxMode(['gst_rate' => 18, 'place_of_supply' => business('address.state')]));

    // Nested transactions join the outer one
    try {
        DB::transaction(function () use ($cid) {
            CS::syncTags($cid, []);
            DB::transaction(fn() => DB::table('customers')->where('id', $cid)->update(['notes' => 'inner']));
            throw new \RuntimeException('roll back');
        });
    } catch (\RuntimeException) {}
    $assert->equals('transaction: inner write rolled back with outer', null, DB::table('customers')->where('id', $cid)->value('notes'));
};
