<?php

use App\Services\Inventory\InventoryService as Inv;
use App\Services\Order\OrderService as Orders;
use App\Services\Order\PaymentService as PS;
use App\Services\Order\ReturnException;
use App\Services\Order\ReturnService as Returns;
use App\Services\Payment\Accounts;
use App\Services\Payment\Ledger;
use Vayu\Application;
use Vayu\Database\DB;

/** Stage 6: policies, eligibility (delivered, snapshot, days, qty), approve/reject, receive → restock, credit → refund, replacement stock-out. */
return function (Assert $assert): void {
    $app = new Application(dirname(__DIR__));
    $app->config()->set('database.default', 'sqlite');
    $app->config()->set('database.connections.sqlite.database', ':memory:');
    $app->config()->set('cache.default', 'array');
    $router = $app->router();
    require dirname(__DIR__) . '/routes/web.php';
    $files = glob(dirname(__DIR__) . '/database/migrations/2026_*.php');
    sort($files);
    foreach ($files as $f) {
        ob_start();
        (require $f)->up();
        ob_end_clean();
    }
    $now = date('Y-m-d H:i:s');
    DB::table('units')->insert(['name' => 'Box', 'symbol' => 'box']);
    DB::table('product_types')->insert(['name' => 'Marble', 'slug' => 'marble']);
    $pid = (int) DB::table('products')->insertGetId(['name' => 'Statuario', 'sku' => 'STA', 'slug' => 'statuario', 'status' => 'active', 'product_type_id' => 1, 'created_at' => $now]);
    DB::table('product_pricing')->insert(['product_id' => $pid, 'price' => 1000, 'unit' => 'box']);
    $pid2 = (int) DB::table('products')->insertGetId(['name' => 'Granite', 'sku' => 'GRA', 'slug' => 'granite', 'status' => 'active', 'product_type_id' => 1, 'created_at' => $now]);
    DB::table('product_pricing')->insert(['product_id' => $pid2, 'price' => 500, 'unit' => 'box']);
    Inv::startTracking($pid, 'box', 100);
    $stock = static fn(): float => (float) Inv::stockFor($pid)['quantity'];
    $order = static fn(int $id): array => DB::selectOne('SELECT * FROM orders WHERE id = ?', [$id]);
    $req = static fn(int $id): array => DB::selectOne('SELECT * FROM return_requests WHERE id = ?', [$id]);
    $msg = static function (callable $fn): string {
        try { $fn(); return ''; } catch (ReturnException $e) { return $e->getMessage(); }
    };
    $mk = static fn(array $over = []): int => Orders::create(array_replace_recursive([
        'new_customer' => ['name' => 'Ramesh Das', 'phone' => '9830012345'], 'delivery' => 'pickup',
        'items' => [['product_id' => $pid, 'variant_id' => 0, 'quantity' => 5, 'unit_price' => 1000]],
    ], $over));
    $deliver = static function (int $id): void {
        foreach (['accepted', 'processing', 'packed', 'dispatched'] as $s) {
            Orders::transition($id, $s);
        }
        Orders::transition($id, 'delivered', ['manual' => 1, 'manual_reason' => 'Collected at the showroom']);
    };
    $line = static fn(int $orderId, int $productId): array => DB::selectOne('SELECT * FROM order_items WHERE order_id = ? AND product_id = ?', [$orderId, $productId]);

    // ---- policies ----
    $assert->that('policy: days must be 0–365', $msg(fn() => Returns::savePolicy([$pid], ['return_allowed' => 1, 'return_days' => '400'])) !== '');
    $assert->that('policy: needs a product', $msg(fn() => Returns::savePolicy([], ['return_allowed' => 1])) !== '');
    $before = $mk(['items' => [['product_id' => $pid, 'variant_id' => 0, 'quantity' => 2], ['product_id' => $pid2, 'variant_id' => 0, 'quantity' => 1]]]);
    $assert->equals('policy: bulk save', 2, Returns::savePolicy([$pid, $pid2], ['return_allowed' => 1, 'return_days' => '7', 'replacement_allowed' => 1, 'replacement_days' => '0']));
    Returns::savePolicy([$pid2], ['return_allowed' => 0, 'return_days' => '9', 'replacement_allowed' => 1, 'replacement_days' => '3']);
    $assert->equals('policy: one row per product (upsert)', 2, DB::table('product_return_policies')->count());
    $p2 = DB::selectOne('SELECT * FROM product_return_policies WHERE product_id = ?', [$pid2]);
    $assert->equals('policy: days cleared when not allowed', [0, 0, 1, 3], [(int) $p2['return_allowed'], (int) $p2['return_days'], (int) $p2['replacement_allowed'], (int) $p2['replacement_days']]);

    // ---- eligibility: only after delivery ----
    $o1 = $mk();                                   // 5 × 1000, snapshot: return 7 d, replacement no limit
    $i1 = (int) $line($o1, $pid)['id'];
    $assert->that('submit: refused before delivery', str_contains($msg(fn() => Returns::submit($o1, ['request_type' => 'return', 'reason' => 'damaged', 'items' => [$i1 => 1]])), 'after the order is delivered'));
    $assert->that('canRequest: false before delivery', !Returns::canRequest($o1));
    $deliver($o1);
    $assert->equals('dispatch took stock', 95.0, $stock());
    $assert->that('canRequest: true once delivered', Returns::canRequest($o1));
    $assert->that('submit: needs a type', $msg(fn() => Returns::submit($o1, ['reason' => 'damaged', 'items' => [$i1 => 1]])) !== '');
    $assert->that('submit: needs a reason', $msg(fn() => Returns::submit($o1, ['request_type' => 'return', 'items' => [$i1 => 1]])) !== '');
    $assert->that('submit: other needs a description', $msg(fn() => Returns::submit($o1, ['request_type' => 'return', 'reason' => 'other', 'items' => [$i1 => 1]])) !== '');
    $assert->that('submit: needs a quantity', $msg(fn() => Returns::submit($o1, ['request_type' => 'return', 'reason' => 'damaged', 'items' => [$i1 => 0]])) !== '');
    $assert->that('submit: more than delivered refused', str_contains($msg(fn() => Returns::submit($o1, ['request_type' => 'return', 'reason' => 'damaged', 'items' => [$i1 => 6]])), 'only 5 box'));
    $assert->that('submit: item from another order refused', $msg(fn() => Returns::submit($o1, ['request_type' => 'return', 'reason' => 'damaged', 'items' => [(int) $line($before, $pid)['id'] => 1]])) !== '');

    // ---- submit + partial approval + quantity guard ----
    $r1 = Returns::submit($o1, ['request_type' => 'return', 'reason' => 'damaged', 'description' => 'Two boxes cracked', 'items' => [$i1 => '3']]);
    $assert->that('submit: numbered RET/…', str_starts_with($r1['request_number'], 'RET/'));
    $assert->equals('submit: status submitted', 'submitted', $req($r1['id'])['status']);
    $assert->that('notify: return.requested', DB::table('notifications')->where('type', 'return.requested')->whereNull('read_at')->exists());
    $assert->equals('claimed: waiting request counts what was asked', 2.0, Returns::lines($o1)[0]['available']);
    $assert->that('submit: can’t exceed delivered − already claimed', $msg(fn() => Returns::submit($o1, ['request_type' => 'return', 'reason' => 'excess', 'items' => [$i1 => 3]])) !== '');
    $ri1 = (int) DB::table('return_request_items')->where('return_request_id', $r1['id'])->value('id');
    $assert->that('receive: refused before approval', $msg(fn() => Returns::receive($r1['id'], ['items' => [$ri1 => 1]])) !== '');
    $assert->that('approve: more than asked refused', $msg(fn() => Returns::approve($r1['id'], ['items' => [$ri1 => 4]])) !== '');
    $assert->that('approve: zero everywhere refused', $msg(fn() => Returns::approve($r1['id'], ['items' => [$ri1 => 0]])) !== '');
    Returns::approve($r1['id'], ['items' => [$ri1 => 2], 'admin_note' => 'Third box looks fine on the photo']);
    $assert->equals('approve: partial qty stored', [2.0, 'approved'], [(float) DB::table('return_request_items')->where('id', $ri1)->value('approved_quantity'), $req($r1['id'])['status']]);
    $assert->equals('claimed: approved counts approved qty', 3.0, Returns::lines($o1)[0]['available']);
    $assert->that('notify: requested marked read', !DB::table('notifications')->where('type', 'return.requested')->whereNull('read_at')->exists());
    $assert->that('notify: return.approved', DB::table('notifications')->where('type', 'return.approved')->exists());
    $assert->that('approve: no second decision (no cancel / re-decide)', $msg(fn() => Returns::approve($r1['id'], ['items' => [$ri1 => 1]])) !== '');
    $assert->that('reject: refused once approved', $msg(fn() => Returns::reject($r1['id'], 'Changed my mind')) !== '');

    // ---- reject frees the quantity ----
    $r2 = Returns::submit($o1, ['request_type' => 'return', 'reason' => 'excess', 'items' => [$i1 => 3]]);
    $assert->equals('claimed: all 5 now in returns', 0.0, Returns::lines($o1)[0]['available']);
    $assert->that('reject: needs a customer note', $msg(fn() => Returns::reject($r2['id'], ' ')) !== '');
    Returns::reject($r2['id'], 'Cut-to-size slabs can’t be taken back.');
    $assert->equals('reject: status + note', ['rejected', true], [$req($r2['id'])['status'], str_contains((string) $req($r2['id'])['admin_note'], 'Cut-to-size')]);
    $assert->equals('claimed: declined request frees qty', 3.0, Returns::lines($o1)[0]['available']);

    // ---- receive → restock ----
    $assert->that('receive: more than approved refused', $msg(fn() => Returns::receive($r1['id'], ['items' => [$ri1 => 3]])) !== '');
    Returns::receive($r1['id'], ['items' => [$ri1 => 2], 'restock' => [$ri1 => 1]]);
    $assert->equals('receive: back in stock', 97.0, $stock());
    $mv = DB::selectOne("SELECT * FROM inventory_movements WHERE type = 'return_in' ORDER BY id DESC LIMIT 1");
    $assert->equals('receive: movement return_in with request no.', [2.0, $r1['request_number']], [(float) $mv['qty_change'], $mv['reference_no']]);
    $assert->equals('receive: status received', 'received', $req($r1['id'])['status']);

    // ---- credit + refund (paid order) ----
    $cash = Accounts::save(['type' => 'cash', 'name' => 'Shop counter', 'is_active' => 1], null, '123456');
    Ledger::record($o1, ['method' => 'cash', 'amount' => 5000, 'payment_account_id' => $cash]);
    $assert->equals('order: fully paid before credit', 'paid', $order($o1)['payment_status']);
    $assert->that('refund: refused before completed', $msg(fn() => Returns::recordRefund($r1['id'], ['amount' => 100, 'method' => 'cash', 'reason' => 'x'])) !== '');
    $assert->that('resolve: credit above value refused', $msg(fn() => Returns::resolve($r1['id'], ['credit_amount' => '2000.01'])) !== '');
    $res = Returns::resolve($r1['id'], []);
    $assert->equals('resolve: default credit = value of returned goods', 2000.0, $res['credit']);
    $o = $order($o1);
    $assert->equals('credit: paid order shows refund due', ['overpaid', 2000.0, 0.0], [$o['payment_status'], (float) $o['refund_due'], (float) $o['due_amount']]);
    $assert->that('refund: more than credited refused', str_contains($msg(fn() => Returns::recordRefund($r1['id'], ['amount' => 2500, 'method' => 'cash', 'reason' => 'x'])), 'at most'));
    $rf = Returns::recordRefund($r1['id'], ['amount' => '2000', 'method' => 'upi', 'payment_account_id' => $cash]);
    $o = $order($o1);
    $assert->equals('refund: linked to the return', $r1['id'], (int) DB::table('payment_refunds')->where('id', $rf['refund_id'])->value('return_request_id'));
    $assert->equals('refund: order settles (paid 3000 of 3000 owed)', ['paid', 3000.0, 0.0, 0.0], [$o['payment_status'], (float) $o['paid_amount'], (float) $o['refund_due'], (float) $o['due_amount']]);
    $assert->that('refund: nothing more against it', $msg(fn() => Returns::recordRefund($r1['id'], ['amount' => 1, 'method' => 'cash'])) !== '');

    // ---- replacement: own stock-out, no restock when damaged ----
    $r3 = Returns::submit($o1, ['request_type' => 'replacement', 'reason' => 'defect', 'items' => [$i1 => 1]]);
    $ri3 = (int) DB::table('return_request_items')->where('return_request_id', $r3['id'])->value('id');
    Returns::approve($r3['id'], ['items' => [$ri3 => 1]]);
    Returns::receive($r3['id'], ['items' => [$ri3 => 1]]);  // restock unticked: broken slab
    $assert->equals('replacement: broken piece not restocked', 97.0, $stock());
    Returns::resolve($r3['id'], ['courier_name' => 'Own van', 'tracking_number' => 'WB-9']);
    $assert->equals('replacement: dispatched from stock', 96.0, $stock());
    $mv = DB::selectOne("SELECT * FROM inventory_movements WHERE type = 'sale' ORDER BY id DESC LIMIT 1");
    $assert->equals('replacement: sale movement with request no.', $r3['request_number'], $mv['reference_no']);
    $assert->equals('replacement: no money change', ['paid', 0.0], [$order($o1)['payment_status'], (float) $order($o1)['refund_due']]);
    $ev = Returns::events($req($r3['id']) + ['request_number' => $r3['request_number']]);
    $assert->equals('timeline: 4 events for the request', 4, count($ev));
    $assert->that('timeline: dispatch note has courier', str_contains((string) $ev[0]['note'], 'Own van #WB-9'));
    $assert->equals('timeline: parseEvent', $r3['request_number'], Returns::parseEvent((string) $ev[0]['note'])[0] ?? null);
    $assert->equals('claimed: 2 returned + 1 replaced', 2.0, Returns::lines($o1)[0]['available']);

    // ---- snapshot policy + days window + override ----
    $deliver($before);                              // created before any policy → no returns allowed
    $bi = (int) $line($before, $pid)['id'];
    $assert->that('policy: snapshot, not live policy', str_contains($msg(fn() => Returns::submit($before, ['request_type' => 'return', 'reason' => 'damaged', 'items' => [$bi => 1]])), 'outside policy'));
    $assert->that('override: needs a reason', $msg(fn() => Returns::submit($before, ['request_type' => 'return', 'reason' => 'damaged', 'outside_policy' => 1, 'override_reason' => 'ok', 'items' => [$bi => 1]])) !== '');
    $r4 = Returns::submit($before, ['request_type' => 'return', 'reason' => 'damaged', 'outside_policy' => 1, 'override_reason' => 'Goodwill for a regular contractor', 'items' => [$bi => 1]]);
    $assert->that('override: noted on the request', str_contains((string) $req($r4['id'])['admin_note'], 'outside policy'));

    $o5 = $mk(['items' => [['product_id' => $pid2, 'variant_id' => 0, 'quantity' => 4]]]);
    $deliver($o5);
    $i5 = (int) $line($o5, $pid2)['id'];
    $l5 = Returns::lines($o5)[0];
    $assert->equals('days: product 2 replace-only, 3 days', [false, true], [$l5['return_ok'], $l5['replacement_ok']]);
    DB::table('orders')->where('id', $o5)->update(['delivered_at' => date('Y-m-d H:i:s', time() - 5 * 86400)]);
    $assert->that('days: replacement window over after 3 days', str_contains($msg(fn() => Returns::submit($o5, ['request_type' => 'replacement', 'reason' => 'damaged', 'items' => [$i5 => 1]])), 'only replaceable until'));
    DB::table('orders')->where('id', $o1)->update(['delivered_at' => date('Y-m-d H:i:s', time() - 30 * 86400)]);
    $l1 = Returns::lines($o1)[0];
    $assert->equals('days: 0 = no time limit (replacement), 7-day return over', [false, true], [$l1['return_ok'], $l1['replacement_ok']]);

    // ---- unpaid order: credit lowers the due, no refund ----
    $o6 = $mk(['items' => [['product_id' => $pid, 'variant_id' => 0, 'quantity' => 5, 'unit_price' => 1000]], 'discount_amount' => 500]);
    $deliver($o6);
    $i6 = (int) $line($o6, $pid)['id'];
    $r6 = Returns::submit($o6, ['request_type' => 'return', 'reason' => 'excess', 'items' => [$i6 => 1]]);
    $ri6 = (int) DB::table('return_request_items')->where('return_request_id', $r6['id'])->value('id');
    Returns::approve($r6['id'], ['items' => [$ri6 => 1]]);
    Returns::receive($r6['id'], ['items' => [$ri6 => 1], 'restock' => [$ri6 => 1]]);
    $res = Returns::resolve($r6['id'], []);
    $assert->equals('value: includes the order discount share (4500 / 5)', 900.0, $res['credit']);
    $o = $order($o6);
    $assert->equals('credit: unpaid order owes less', ['unpaid', 3600.0, 0.0], [$o['payment_status'], (float) $o['due_amount'], (float) $o['refund_due']]);
    $assert->that('resolve: no second completion', $msg(fn() => Returns::resolve($r6['id'], [])) !== '');

    // ---- lower credit (damage deduction) ----
    $r7 = Returns::submit($o6, ['request_type' => 'return', 'reason' => 'excess', 'items' => [$i6 => 1]]);
    $ri7 = (int) DB::table('return_request_items')->where('return_request_id', $r7['id'])->value('id');
    Returns::approve($r7['id'], ['items' => [$ri7 => 1]]);
    Returns::receive($r7['id'], ['items' => [$ri7 => 1], 'restock' => [$ri7 => 1]]);
    Returns::resolve($r7['id'], ['credit_amount' => '450.50', 'note' => 'Edge chipped']);
    $assert->equals('credit: admin may credit less', 450.5, (float) DB::table('return_request_items')->where('id', $ri7)->value('refund_amount'));
    $assert->equals('credit: due follows', 3149.5, (float) $order($o6)['due_amount']);
    $assert->equals('syncOrder: idempotent', 3149.5, (float) PS::syncOrder($o6)['due_amount']);
};
