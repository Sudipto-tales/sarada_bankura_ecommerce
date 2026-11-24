<?php

use App\Services\Order\OrderService as Orders;
use App\Services\Order\PaymentService as PS;
use App\Services\Payment\Accounts;
use App\Services\Payment\Ledger;
use App\Services\Payment\PaymentException;
use App\Services\Payment\PaymentRequests;
use App\Services\Payment\PayWindow;
use Vayu\Application;
use Vayu\Database\DB;
use Vayu\Validation\ValidationException;

/** Stage 5: accounts (OTP + audit), payment cache, window, verify/reject, shop payments, refunds, voids, requests, checkout. */
return function (Assert $assert): void {
    $app = new Application(dirname(__DIR__));
    $app->config()->set('database.default', 'sqlite');
    $app->config()->set('database.connections.sqlite.database', ':memory:');
    $app->config()->set('cache.default', 'array'); // rate limiter must not remember earlier runs
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
    $mk = static fn(array $over = []): int => Orders::create(array_replace_recursive([
        'new_customer' => ['name' => 'Ramesh Das', 'phone' => '9830012345'], 'delivery' => 'pickup',
        'items' => [['product_id' => $pid, 'variant_id' => 0, 'quantity' => 5, 'unit_price' => 1000]],
    ], $over));
    $order = static fn(int $id): array => DB::selectOne('SELECT * FROM orders WHERE id = ?', [$id]);
    $errs = static function (callable $fn): array {
        try { $fn(); return []; } catch (ValidationException $e) { return $e->errors(); }
    };
    $msg = static function (callable $fn): string {
        try { $fn(); return ''; } catch (PaymentException $e) { return $e->getMessage(); }
    };

    // ---- rounding ----
    $assert->equals('advance: 20 % rounded up', 200.01, PS::advance(1000.01));
    $assert->equals('advance: exact', 1000.0, PS::advance(5000));
    $assert->equals('advance: tiny rounds up', 0.01, PS::advance(0.01));
    $assert->equals('cents: float noise', 30, PS::cents(0.1 + 0.2));

    // ---- accounts: OTP, validation, default, audit masking, delete guard ----
    $assert->that('account: wrong OTP refused', $msg(fn() => Accounts::save(['type' => 'cash', 'name' => 'Counter', 'is_active' => 1], null, '000000')) !== '');
    $e = $errs(fn() => Accounts::save(['type' => 'upi', 'name' => 'SBI', 'upi_id' => 'not-a-vpa', 'is_active' => 1], null, '123456'));
    $assert->that('account: bad UPI ID + missing payee', isset($e['upi_id'], $e['payee_name']));
    $e = $errs(fn() => Accounts::save(['type' => 'bank', 'name' => 'SBI Current', 'bank_name' => 'SBI', 'account_holder' => 'MS', 'account_number' => '12ab', 'ifsc' => 'XX'], null, '123456'));
    $assert->that('account: bad bank no. + IFSC', isset($e['account_number'], $e['ifsc']));
    $assert->equals('window: no UPI account → refused', 'Online payment isn’t set up yet. Please contact us to pay.', $msg(fn() => PayWindow::forOrder($order($mk()))));
    $upi1 = Accounts::save(['type' => 'upi', 'name' => 'SBI UPI', 'upi_id' => 'MaaSarada@SBI', 'payee_name' => 'Maa Sarada Marble', 'is_active' => 1, 'is_default_upi' => 1], null, '123 456');
    $upi2 = Accounts::save(['type' => 'upi', 'name' => 'HDFC UPI', 'upi_id' => 'newshop@hdfc', 'payee_name' => 'Maa Sarada', 'is_active' => 1, 'is_default_upi' => 1], null, '123456');
    $bank = Accounts::save(['type' => 'bank', 'name' => 'SBI Current', 'bank_name' => 'State Bank of India', 'account_holder' => 'Maa Sarada Marble', 'account_number' => '30012345674521', 'ifsc' => 'sbin0001234', 'is_active' => 1], null, '123456');
    $cash = Accounts::save(['type' => 'cash', 'name' => 'Shop counter', 'is_active' => 1], null, '123456');
    $assert->equals('account: UPI ID lower-cased', 'maasarada@sbi', Accounts::find($upi1)['upi_id']);
    $assert->equals('account: only one default UPI', [$upi2], array_map('intval', array_column(DB::select('SELECT id FROM payment_accounts WHERE is_default_upi = 1'), 'id')));
    Accounts::setDefault($upi1, '123456');
    $assert->equals('account: set default moves it', $upi1, (int) Accounts::defaultUpi()['id']);
    $log = DB::selectOne("SELECT changes FROM audit_logs WHERE entity_id = ? AND action = 'create'", [$bank]);
    $assert->that('audit: account no. masked to last 4', str_contains((string) $log['changes'], '••••4521') && !str_contains((string) $log['changes'], '30012345674521'));
    Accounts::save(['type' => 'upi', 'name' => 'SBI UPI', 'upi_id' => 'maasarada@sbi', 'payee_name' => 'Maa Sarada Marble & Stone', 'is_active' => 1, 'is_default_upi' => 1], $upi1, '123456');
    $upd = json_decode((string) DB::selectOne("SELECT changes FROM audit_logs WHERE entity_id = ? AND action = 'update'", [$upi1])['changes'], true);
    $assert->equals('audit: only changed fields logged', ['payee_name'], array_keys($upd));
    $assert->that('delete: needs the exact name', $msg(fn() => Accounts::delete($upi2, 'HDFC', '123456')) !== '');
    Accounts::delete($upi2, 'hdfc upi', '123456');
    $assert->that('delete: unused account deleted', Accounts::find($upi2) === null);

    // ---- window on an order ----
    $o1 = $mk();                                     // 5 × 1000 = 5000
    $assert->equals('order: unpaid, full due', ['unpaid', 5000.0], [$order($o1)['payment_status'], (float) $order($o1)['due_amount']]);
    $a = PayWindow::forOrder($order($o1), 'invoice', '1.2.3.4');
    $assert->equals('window: amount = due', 5000.0, (float) $a['amount']);
    $assert->that('window: number from series', str_starts_with((string) $a['attempt_number'], 'PAT/'));
    $assert->equals('window: second open reuses attempt', $a['id'], PayWindow::forOrder($order($o1), 'invoice', '1.2.3.4')['id']);
    $p = PayWindow::present($a);
    $assert->that('window: UPI link carries amount + VPA + ref', str_contains($p['upi']['uri'], 'am=5000.00') && str_contains($p['upi']['uri'], 'pa=maasarada%40sbi') && str_contains($p['upi']['uri'], PayWindow::shortRef((string) $a['attempt_number'])));
    $assert->equals('window: bank details for big amounts', '30012345674521', $p['bank']['number']);
    $e = $errs(fn() => PayWindow::submit($a['public_token'], ['payer_name' => 'R']));
    $assert->that('submit: needs name and UTR or screenshot', isset($e['payer_name'], $e['payer_reference']));
    $e = $errs(fn() => PayWindow::submit($a['public_token'], ['payer_name' => 'Ramesh', 'payer_reference' => 'abc']));
    $assert->that('submit: UTR format checked', isset($e['payer_reference']));
    PayWindow::submit($a['public_token'], ['payer_name' => 'Ramesh K Das', 'payer_reference' => '4312 9876 5012']);
    $s = DB::selectOne('SELECT * FROM payment_attempts WHERE id = ?', [$a['id']]);
    $assert->equals('submit: UTR normalised', ['submitted', '431298765012'], [$s['status'], $s['payer_reference']]);
    $assert->equals('submit: awaiting verification cached', 5000.0, (float) $order($o1)['pending_verification_amount']);
    $assert->equals('submit: due now excludes the pending amount', 0.0, PS::dueNow($order($o1)));
    $assert->that('submit: can’t open another while it is checked', str_contains($msg(fn() => PayWindow::forOrder($order($o1))), 'being checked'));
    $assert->equals('submit: notification raised', 1, (int) DB::table('notifications')->where('type', 'payment.submitted')->count());
    $assert->equals('submit: double submit is harmless', 'submitted', PayWindow::submit($a['public_token'], ['payer_name' => 'X'])['status']);

    // verify with a different amount than expected
    $e = $errs(fn() => Ledger::verify((int) $a['id'], ['amount' => '', 'payment_account_id' => $upi1]));
    $assert->that('verify: amount required (blank on purpose)', isset($e['amount']));
    $e = $errs(fn() => Ledger::verify((int) $a['id'], ['amount' => '4000', 'payment_account_id' => $upi1, 'received_at' => date('Y-m-d H:i', time() + 86400)]));
    $assert->that('verify: future date refused', isset($e['received_at']));
    $v = Ledger::verify((int) $a['id'], ['amount' => '4,000', 'payment_account_id' => $upi1, 'notes' => 'SBI app']);
    $assert->equals('verify: difference reported', -1000.0, $v['difference']);
    $assert->that('verify: receipt number', str_starts_with($v['payment_number'], 'RCP/'));
    $oo = $order($o1);
    $assert->equals('verify: paid / due / status', [4000.0, 1000.0, 0.0, 'partially_paid'], [(float) $oo['paid_amount'], (float) $oo['due_amount'], (float) $oo['pending_verification_amount'], $oo['payment_status']]);
    $assert->equals('verify: order status untouched', 'pending', $oo['order_status']);
    $assert->that('verify: twice refused', $msg(fn() => Ledger::verify((int) $a['id'], ['amount' => 1, 'payment_account_id' => $upi1])) !== '');
    $assert->equals('verify: notification marked read', 0, (int) DB::table('notifications')->where('type', 'payment.submitted')->whereNull('read_at')->count());

    // second payment, duplicate UTR guard, reject
    $b = PayWindow::forOrder($order($o1), 'order');
    $assert->equals('window: now asks the remaining 1000', 1000.0, (float) $b['amount']);
    PayWindow::submit($b['public_token'], ['payer_name' => 'Ramesh', 'payer_reference' => '431298765012']);
    $assert->equals('duplicate UTR spotted', 1, count(Ledger::duplicateReference('431298765012', (int) $b['id'])));
    $assert->that('reject: reason required', $msg(fn() => Ledger::reject((int) $b['id'], '')) !== '');
    Ledger::reject((int) $b['id'], 'UTR already used on an earlier payment');
    $assert->equals('reject: pending cleared, paid unchanged', [0.0, 4000.0], [(float) $order($o1)['pending_verification_amount'], (float) $order($o1)['paid_amount']]);

    // ---- expiry ----
    $c = PayWindow::forOrder($order($o1), 'order');
    DB::update('UPDATE payment_attempts SET expires_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 20), $c['id']]);
    $assert->equals('expiry: still inside the 30 s grace', 0, PayWindow::expireStale());
    DB::update('UPDATE payment_attempts SET expires_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 40), $c['id']]);
    $assert->that('expiry: late submit refused', str_contains($msg(fn() => PayWindow::submit($c['public_token'], ['payer_name' => 'Ramesh', 'payer_reference' => '999988887777'])), 'Time ran out'));
    $assert->equals('expiry: marked expired', 'expired', DB::table('payment_attempts')->where('id', $c['id'])->value('status'));
    $d = PayWindow::forOrder($order($o1), 'order');
    PayWindow::close($d['public_token']);
    $assert->equals('close: closed window = failed attempt', 'expired', DB::table('payment_attempts')->where('id', $d['id'])->value('status'));

    // ---- shop payment, overpay, refund, void ----
    $e = $errs(fn() => Ledger::record($o1, ['method' => 'cheque', 'amount' => 100, 'payment_account_id' => $bank]));
    $assert->that('record: cheque needs its number', isset($e['reference']));
    $e = $errs(fn() => Ledger::record($o1, ['method' => 'upi', 'amount' => 100]));
    $assert->that('record: non-cash needs an account', isset($e['payment_account_id']));
    Ledger::record($o1, ['method' => 'cash', 'amount' => 1500]);  // 500 too much
    $oo = $order($o1);
    $assert->equals('record: overpaid → refund due', ['overpaid', 500.0, 0.0], [$oo['payment_status'], (float) $oo['refund_due'], (float) $oo['due_amount']]);
    $assert->equals('window: nothing due when overpaid', 'Nothing is due on this order — thank you!', $msg(fn() => PayWindow::forOrder($oo)));
    $e = $errs(fn() => Ledger::refund($o1, ['method' => 'upi', 'amount' => 99999, 'reason' => 'x']));
    $assert->that('refund: capped at paid', isset($e['amount']));
    $r = Ledger::refund($o1, ['method' => 'upi', 'amount' => 500, 'payment_account_id' => $upi1, 'reason' => 'Overpaid by ₹500', 'reference' => 'RF1']);
    $assert->that('refund: number', str_starts_with($r['refund_number'], 'RFD/'));
    $oo = $order($o1);
    $assert->equals('refund: back to paid', ['paid', 5000.0, 0.0], [$oo['payment_status'], (float) $oo['paid_amount'], (float) $oo['refund_due']]);
    $cashPay = (int) DB::table('payments')->where('order_id', $o1)->where('method', 'cash')->value('id');
    Ledger::voidPayment($cashPay, 'Typed the wrong amount');
    $oo = $order($o1);
    $assert->equals('void: payment stops counting', ['partially_paid', 3500.0, 1500.0], [$oo['payment_status'], (float) $oo['paid_amount'], (float) $oo['due_amount']]);
    Ledger::voidRefund((int) $r['refund_id'], 'Refund bounced');
    $assert->equals('void refund: paid back up', 4000.0, (float) $order($o1)['paid_amount']);

    // ---- rejected order ----
    $o2 = $mk(['new_customer' => ['phone' => '9830099999', 'name' => 'Sita']]);
    Ledger::record($o2, ['method' => 'cash', 'amount' => 1000]);
    Orders::transition($o2, 'rejected', ['customer_note' => 'Sorry, lot finished.']);
    $oo = $order($o2);
    $assert->equals('rejected: owes nothing, all paid is refund due', [0.0, 1000.0, 'overpaid'], [(float) $oo['due_amount'], (float) $oo['refund_due'], $oo['payment_status']]);
    $assert->that('rejected: no new payments', $msg(fn() => Ledger::record($o2, ['method' => 'cash', 'amount' => 1])) !== '');
    $assert->that('rejected: no window', $msg(fn() => PayWindow::forOrder($oo)) !== '');
    Ledger::refund($o2, ['method' => 'cash', 'amount' => 1000, 'reason' => 'Order declined']);
    $assert->equals('rejected: refunded', ['refunded', 0.0], [$order($o2)['payment_status'], (float) $order($o2)['refund_due']]);

    // ---- payment requests ----
    $sent = [];
    PaymentRequests::$mailer = static function (string $to, string $s, string $b) use (&$sent): void { $sent[] = [$to, $s, $b]; };
    $o3 = $mk(['new_customer' => ['phone' => '9830077777', 'name' => 'Gita']]);
    $assert->that('request: bad phone refused', $msg(fn() => PaymentRequests::send($o3, 'whatsapp', '12345')) !== '');
    $w = PaymentRequests::send($o3, 'whatsapp', '+91 98300 77777');
    $assert->that('request: wa.me link with pay link', str_starts_with((string) $w['open_url'], 'https://wa.me/919830077777?text=') && str_contains(rawurldecode((string) $w['open_url']), '/pay/' . $order($o3)['public_token']));
    $assert->equals('request: logged opened, amount = due', ['opened', 5000.0], [$w['status'], $w['amount']]);
    $assert->that('request: 30 s cooldown', str_contains($msg(fn() => PaymentRequests::send($o3, 'sms', '9830077777')), 'wait'));
    DB::update('UPDATE orders SET last_payment_request_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 31), $o3]);
    $m = PaymentRequests::send($o3, 'email', 'Gita@Example.com', 'Please pay');
    $assert->equals('request: email sent via mailer, link appended', ['sent', 'gita@example.com', true], [$m['status'], $sent[0][0], str_contains($sent[0][2], '/pay/')]);
    DB::update('UPDATE orders SET last_payment_request_at = NULL WHERE id = ?', [$o3]);
    PaymentRequests::$mailer = static function (): void { throw new \RuntimeException('SMTP down'); };
    $assert->that('request: failed email reported', str_contains($msg(fn() => PaymentRequests::send($o3, 'email', 'gita@example.com')), 'SMTP down'));
    $assert->equals('request: failed email keeps no cooldown', null, $order($o3)['last_payment_request_at']);
    $assert->equals('request: 3 logs (opened, sent, failed)', ['opened', 'sent', 'failed'], array_column(DB::select('SELECT status FROM payment_request_logs WHERE order_id = ? ORDER BY id', [$o3]), 'status'));
    PaymentRequests::$mailer = null;
    $assert->equals('request: payment status untouched', 'unpaid', $order($o3)['payment_status']);

    // ---- checkout: order only on submit ----
    $cart = ['new_customer' => ['name' => 'Online Buyer', 'phone' => '9830066666'], 'delivery' => 'pickup',
        'items' => [['product_id' => $pid, 'variant_id' => 0, 'quantity' => 3, 'unit_price' => 1000]]];
    $before = (int) DB::table('orders')->count();
    $q = Orders::quote($cart);
    $assert->equals('quote: priced', 3000.0, $q['grand_total']);
    $assert->equals('quote: nothing kept (no order, no customer)', [$before, null], [(int) DB::table('orders')->count(), DB::table('customers')->where('phone', '9830066666')->value('id')]);
    $ck = PayWindow::forCheckout($cart, 'advance');
    $assert->equals('checkout: 20 % advance', [600.0, null], [(float) $ck['amount'], $ck['order_id']]);
    $assert->equals('checkout: no order yet', $before, (int) DB::table('orders')->count());
    $done = PayWindow::submit($ck['public_token'], ['payer_name' => 'Online Buyer', 'payer_reference' => '111122223333']);
    $oc = $order((int) $done['order_id']);
    $assert->equals('checkout: online order created on submit', ['online', 'website', 'pending'], [$oc['channel'], $oc['channel_detail'], $oc['order_status']]);
    $assert->equals('checkout: 600 awaiting verification, 2400 due now', [600.0, 2400.0], [(float) $oc['pending_verification_amount'], PS::dueNow($oc)]);
    $assert->that('checkout: number series stayed gapless', (int) substr((string) $oc['order_number'], -4) === $before + 1);
    $ck2 = PayWindow::forCheckout($cart, 'full');
    DB::update('UPDATE payment_attempts SET expires_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 60), $ck2['id']]);
    PayWindow::expireStale();
    $assert->equals('checkout expired: no order, failed attempt kept', ['expired', null, 3000.0], [DB::table('payment_attempts')->where('id', $ck2['id'])->value('status'), DB::table('payment_attempts')->where('id', $ck2['id'])->value('order_id'), (float) $ck2['amount']]);
};
