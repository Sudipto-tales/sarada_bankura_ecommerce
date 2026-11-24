<?php

use App\Services\Order\OrderService as Orders;
use App\Services\Payment\Accounts;
use App\Services\Payment\Ledger;
use App\Services\Payment\PaymentRequests;
use App\Services\Payment\PayWindow;
use App\Services\WhatsApp\FlowNotifier;
use App\Services\WhatsApp\Webhook;
use App\Services\WhatsApp\WhatsAppService as WA;
use Vayu\Application;
use Vayu\Database\DB;

/** Stage 7 (W1–W3): dormant fallback, payloads, failures, OTP masking + limits, webhook, STOP, flow hooks. The Graph API is stubbed. */
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
    $cfg = $app->config();
    $dormant = static function () use ($cfg): void {
        $cfg->set('whatsapp.enabled', false);
        foreach (array_keys(WA::REQUIRED) as $k) {
            $cfg->set('whatsapp.' . $k, '');
        }
    };
    $activate = static function () use ($cfg): void {
        $cfg->set('whatsapp.enabled', true);
        $cfg->set('whatsapp.access_token', 'EAAG-secret-token');
        $cfg->set('whatsapp.phone_number_id', '1234567890');
        $cfg->set('whatsapp.business_account_id', '999');
        $cfg->set('whatsapp.app_secret', 'app-secret');
        $cfg->set('whatsapp.webhook_verify_token', 'verify-me');
    };
    // HTTP stub: records every call, answers with $reply (or a callable).
    $calls = [];
    $reply = null;
    WA::$http = static function (string $m, string $url, array $h, ?string $body) use (&$calls, &$reply): array {
        $calls[] = ['method' => $m, 'url' => $url, 'headers' => $h, 'body' => $body === null ? null : json_decode($body, true)];

        return is_callable($reply) ? $reply($m, $url) : $reply;
    };
    $nextId = 0;
    $okReply = static function () use (&$nextId): array {
        return ['status' => 200, 'body' => json_encode(['messages' => [['id' => 'wamid.N' . (++$nextId)]]])];
    };
    $reply = $okReply;
    $msgs = static fn(): array => DB::select('SELECT * FROM whatsapp_messages ORDER BY id');
    $last = static fn(): ?array => DB::selectOne('SELECT * FROM whatsapp_messages ORDER BY id DESC LIMIT 1');

    $now = date('Y-m-d H:i:s');
    DB::table('units')->insert(['name' => 'Box', 'symbol' => 'box']);
    DB::table('product_types')->insert(['name' => 'Marble', 'slug' => 'marble']);
    $pid = (int) DB::table('products')->insertGetId(['name' => 'Statuario', 'sku' => 'STA', 'slug' => 'statuario', 'status' => 'active', 'product_type_id' => 1, 'created_at' => $now]);
    DB::table('product_pricing')->insert(['product_id' => $pid, 'price' => 1000, 'unit' => 'box']);
    $mk = static fn(array $over = []): int => Orders::create(array_replace_recursive([
        'new_customer' => ['name' => 'Ramesh Das', 'phone' => '9830012345', 'email' => 'ramesh@example.com'], 'delivery' => 'pickup',
        'items' => [['product_id' => $pid, 'variant_id' => 0, 'quantity' => 5, 'unit_price' => 1000]],
    ], $over));
    $order = static fn(int $id): array => DB::selectOne('SELECT * FROM orders WHERE id = ?', [$id]);
    $toDispatch = static function (int $id): array {
        Orders::transition($id, 'accepted');
        Orders::transition($id, 'processing');
        Orders::transition($id, 'packed');

        return Orders::transition($id, 'dispatched', ['courier_name' => 'Own truck']);
    };

    // ================================================================ W1 dormant
    $dormant();
    $assert->that('dormant: not active', !WA::isActive());
    $assert->equals('dormant: credentials all reported, no values', [false, false, false, false, false, false], array_values(WA::credentials()));
    $cfg->set('whatsapp.enabled', true);
    $cfg->set('whatsapp.access_token', 'x');
    $assert->that('dormant: enabled but values missing stays dormant', !WA::isActive());
    $dormant();
    $r = WA::send('payment_request', '+91 98300 12345', ['name' => 'Ramesh', 'amount' => '₹5,000.00', 'order_number' => 'MSO/1'], ['fallback_text' => 'Hi Ramesh, pay here', 'related_type' => 'order', 'related_id' => 1]);
    $assert->equals('dormant: fallback mode with wa.me URL', ['fallback', true, 'https://wa.me/919830012345?text=' . rawurlencode('Hi Ramesh, pay here')], [$r->mode, $r->ok, $r->fallbackUrl]);
    $assert->equals('dormant: no HTTP call', 0, count($calls));
    $assert->equals('dormant: hand-off logged as opened / fallback', ['fallback', 'opened', '9830012345'], [$last()['mode'], $last()['status'], $last()['phone']]);
    $r = WA::send('order_confirmed', '9830012345', ['name' => 'Ramesh']);
    $assert->that('dormant: automatic send without text → nothing, not logged', !$r->ok && $r->fallbackUrl === null && count($msgs()) === 1);
    $r = WA::send('auth_otp', '9830012345', ['code' => '123456'], ['fallback_text' => 'code 123456']);
    $assert->that('dormant: OTP never gets a wa.me fallback', $r->fallbackUrl === null && count($msgs()) === 1);
    $r = WA::send('order_confirmed', '12345', []);
    $assert->that('phone: invalid refused before any call', !$r->ok && str_contains((string) $r->error, '10-digit'));
    $assert->that('unknown event is a programming error', (static function (): bool { try { WA::send('nope', '9830012345', []); return false; } catch (\InvalidArgumentException $e) { return true; } })());

    // ================================================================ W1 active
    $activate();
    $assert->that('active: all set', WA::isActive());
    $r = WA::send('order_confirmed', '09830012345', ['name' => 'Ramesh', 'order_number' => 'MSO/26-27/0001', 'total' => '₹5,000.00'], ['button' => 'tok123', 'related_type' => 'order', 'related_id' => 7]);
    $c = $calls[0];
    $assert->equals('active: POST to /{ver}/{phone id}/messages', ['POST', 'https://graph.facebook.com/v21.0/1234567890/messages'], [$c['method'], $c['url']]);
    $assert->that('active: bearer token header', in_array('Authorization: Bearer EAAG-secret-token', $c['headers'], true));
    $assert->equals('active: to 91XXXXXXXXXX, template + language', ['919830012345', 'template', 'order_confirmed', 'en'], [$c['body']['to'], $c['body']['type'], $c['body']['template']['name'], $c['body']['template']['language']['code']]);
    $assert->equals('active: body params in config order', ['Ramesh', 'MSO/26-27/0001', '₹5,000.00'], array_column($c['body']['template']['components'][0]['parameters'], 'text'));
    $assert->equals('active: URL button gets only the token', ['button', 'url', 'tok123'], [$c['body']['template']['components'][1]['type'], $c['body']['template']['components'][1]['sub_type'], $c['body']['template']['components'][1]['parameters'][0]['text']]);
    $assert->equals('active: sent with wamid, logged', ['api', true, 'wamid.N1', 'sent', 'wamid.N1'], [$r->mode, $r->sent(), $r->messageId, $last()['status'], $last()['wamid']]);

    $cfg->set('whatsapp.templates.order_confirmed.name', 'order_confirmed_v2');
    WA::send('order_confirmed', '9830012345', ['name' => 'R', 'order_number' => 'X', 'total' => '1']);
    $assert->equals('config: template rename is config only', 'order_confirmed_v2', $calls[1]['body']['template']['name']);
    $cfg->set('whatsapp.templates.order_confirmed.name', 'order_confirmed');

    // failure → failed row + wa.me fallback, token never leaks
    $reply = ['status' => 400, 'body' => '{"error":{"message":"Bad token EAAG-secret-token","code":131030,"error_data":{"details":"Recipient not on WhatsApp (EAAG-secret-token)"}}}'];
    $r = WA::send('payment_request', '9830012345', ['name' => 'R', 'amount' => '₹1', 'order_number' => 'X'], ['fallback_text' => 'Pay here']);
    $row = $last();
    $assert->equals('failure: failed row with Meta error code', ['failed', '131030'], [$row['status'], $row['error_code']]);
    $assert->that('failure: wa.me fallback offered', $r->mode === 'api' && !$r->ok && str_starts_with((string) $r->fallbackUrl, 'https://wa.me/919830012345'));
    $assert->that('failure: token scrubbed from error + log', !str_contains((string) $r->error, 'EAAG-secret-token') && !str_contains(json_encode($msgs()), 'EAAG-secret-token'));
    $reply = static function (): array { throw new \RuntimeException('timeout after 8 s'); };
    $r = WA::send('order_delivered', '9830012345', ['name' => 'R', 'order_number' => 'X']);
    $assert->that('failure: transport error → failed, no exception', !$r->ok && $last()['status'] === 'failed' && str_contains((string) $last()['error_text'], 'timeout'));

    // OTP: masked, copy-code button, limits
    $reply = $okReply;
    $calls = [];
    $r = WA::send('auth_otp', '9830099999', ['code' => '482913']);
    $assert->that('otp: sent', $r->sent());
    $assert->equals('otp: copy-code button carries the code', '482913', $calls[0]['body']['template']['components'][1]['parameters'][0]['text']);
    $row = $last();
    $assert->that('otp: code never stored in clear', !str_contains((string) $row['params_json'] . $row['body_preview'], '482913') && str_contains((string) $row['params_json'], WA::MASK));
    $r = WA::send('auth_otp', '9830099999', ['code' => '111111']);
    $assert->that('otp: 1 per minute per phone', !$r->ok && str_contains((string) $r->error, 'wait a minute') && count($calls) === 1);
    DB::update("UPDATE whatsapp_messages SET created_at = ? WHERE phone = '9830099999'", [date('Y-m-d H:i:s', time() - 120)]);
    for ($i = 0; $i < 4; $i++) {
        DB::table('whatsapp_messages')->insert(['direction' => 'out', 'phone' => '9830099999', 'template' => 'auth_otp', 'category' => 'authentication', 'mode' => 'api', 'status' => 'sent', 'created_at' => date('Y-m-d H:i:s', time() - 600)]);
    }
    $assert->that('otp: 5 per hour per phone', str_contains((string) WA::send('auth_otp', '9830099999', ['code' => '1'])->error, 'last hour'));

    $assert->that('cost: month-to-date estimate per category', WA::costEstimate()['rows']['utility']['n'] >= 2 && WA::costEstimate()['total'] > 0);

    // ================================================================ W2 webhook
    $assert->equals('webhook: verify echoes challenge', '42', Webhook::verify(['hub_mode' => 'subscribe', 'hub_verify_token' => 'verify-me', 'hub_challenge' => '42']));
    $assert->equals('webhook: dotted keys work too', '43', Webhook::verify(['hub.mode' => 'subscribe', 'hub.verify_token' => 'verify-me', 'hub.challenge' => '43']));
    $assert->that('webhook: wrong verify token → null (403)', Webhook::verify(['hub_mode' => 'subscribe', 'hub_verify_token' => 'nope', 'hub_challenge' => '1']) === null);
    $raw = '{"entry":[]}';
    $assert->that('signature: valid accepted', Webhook::validSignature($raw, 'sha256=' . hash_hmac('sha256', $raw, 'app-secret')));
    $assert->that('signature: wrong secret rejected', !Webhook::validSignature($raw, 'sha256=' . hash_hmac('sha256', $raw, 'other')));
    $assert->that('signature: tampered body rejected', !Webhook::validSignature($raw . ' ', 'sha256=' . hash_hmac('sha256', $raw, 'app-secret')));
    $assert->that('signature: missing header rejected', !Webhook::validSignature($raw, null));

    $st = static fn(string $id, string $s, array $extra = []): array => ['entry' => [['changes' => [['value' => ['statuses' => [['id' => $id, 'status' => $s, 'timestamp' => (string) time()] + $extra]]]]]]];
    $statusOf = static fn(string $wamid): string => (string) DB::selectOne('SELECT status FROM whatsapp_messages WHERE wamid = ?', [$wamid])['status'];
    Webhook::handle($st('wamid.N1', 'delivered'));
    $assert->equals('status: sent → delivered', 'delivered', $statusOf('wamid.N1'));
    Webhook::handle($st('wamid.N1', 'read'));
    $assert->equals('status: delivered → read', 'read', $statusOf('wamid.N1'));
    $res = Webhook::handle($st('wamid.N1', 'delivered'));
    $assert->equals('status: late delivered after read ignored', ['read', 0, 1], [$statusOf('wamid.N1'), $res['statuses'], $res['ignored']]);
    Webhook::handle($st('wamid.N1', 'failed'));
    $assert->equals('status: failed after read ignored', 'read', $statusOf('wamid.N1'));
    WA::send('order_delivered', '9830012345', ['name' => 'R', 'order_number' => 'X']);
    $w = (string) $last()['wamid'];
    Webhook::handle($st($w, 'failed', ['errors' => [['code' => 131026, 'title' => 'Message undeliverable']]]));
    $assert->equals('status: failed with error code', ['failed', '131026', 'Message undeliverable'], [$last()['status'], $last()['error_code'], $last()['error_text']]);
    $assert->equals('status: unknown wamid ignored', 1, Webhook::handle($st('wamid.unknown', 'read'))['ignored']);

    // inbound + STOP
    $cust = (int) DB::table('customers')->insertGetId(['name' => 'Gita Pal', 'phone' => '9830055555', 'marketing_consent' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $in = static fn(string $id, string $text, string $from = '919830055555'): array => ['entry' => [['changes' => [['value' => [
        'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Gita']]],
        'messages' => [['id' => $id, 'from' => $from, 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => $text]]]]]]]]];
    $res = Webhook::handle($in('wamid.IN1', 'Is the slab available?'));
    $row = DB::selectOne("SELECT * FROM whatsapp_messages WHERE wamid = 'wamid.IN1'");
    $assert->equals('inbound: stored as in / received / customer', ['in', 'received', '9830055555', 'customer', $cust, 'Is the slab available?'], [$row['direction'], $row['status'], $row['phone'], $row['related_type'], (int) $row['related_id'], $row['body_preview']]);
    $assert->that('inbound: 24 h window open', WA::windowOpen('9830055555') && !WA::windowOpen('9830012345'));
    $n = DB::selectOne("SELECT * FROM notifications WHERE type = 'whatsapp.inbound' ORDER BY id DESC LIMIT 1");
    $assert->that('inbound: bell notification names the customer', $n !== null && str_contains((string) $n['title'], 'Gita Pal replied'));
    $assert->equals('inbound: duplicate webhook is a no-op', [0, 1], [Webhook::handle($in('wamid.IN1', 'Is the slab available?'))['inbound'], (int) DB::selectOne("SELECT COUNT(*) AS n FROM whatsapp_messages WHERE wamid = 'wamid.IN1'")['n']]);
    $res = Webhook::handle($in('wamid.IN2', ' stop. '));
    $assert->equals('STOP: clears marketing consent', [1, 0], [$res['opt_outs'], (int) DB::selectOne('SELECT marketing_consent FROM customers WHERE id = ?', [$cust])['marketing_consent']]);
    $assert->that('STOP: logged on the message', str_contains((string) DB::selectOne("SELECT error_text FROM whatsapp_messages WHERE wamid = 'wamid.IN2'")['error_text'], 'Opt-out'));
    $assert->equals('STOP: "unsubscribe" from unknown number stored, nothing to clear', 1, Webhook::handle($in('wamid.IN3', 'UNSUBSCRIBE', '919000000001'))['opt_outs']);
    $assert->equals('STOP: words inside a sentence are not an opt-out', 0, Webhook::handle($in('wamid.IN4', 'please stop by tomorrow'))['opt_outs']);

    // HTTP layer of the webhook (controller)
    $ctl = new \App\Controllers\WhatsAppWebhookController();
    $req = static fn(string $method, array $query = [], string $raw = '', array $headers = []): \Vayu\Http\Request => new \Vayu\Http\Request($method, '/webhooks/whatsapp', $query, [], $headers, [], [], $raw);
    $assert->equals('http: GET verify → 200 + challenge', [200, 'abc'], [$ctl->verify($req('GET', ['hub_mode' => 'subscribe', 'hub_verify_token' => 'verify-me', 'hub_challenge' => 'abc']))->getStatus(), $ctl->verify($req('GET', ['hub_mode' => 'subscribe', 'hub_verify_token' => 'verify-me', 'hub_challenge' => 'abc']))->getContent()]);
    $assert->equals('http: GET bad token → 403', 403, $ctl->verify($req('GET', ['hub_mode' => 'subscribe', 'hub_verify_token' => 'x', 'hub_challenge' => 'abc']))->getStatus());
    $body = json_encode($st('wamid.N2', 'delivered'));
    $assert->equals('http: POST bad signature → 403', 403, $ctl->receive($req('POST', [], (string) $body, ['X-Hub-Signature-256' => 'sha256=00']))->getStatus());
    $assert->equals('http: POST signed → 200', 200, $ctl->receive($req('POST', [], (string) $body, ['X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', (string) $body, 'app-secret')]))->getStatus());
    $dormant();
    $assert->equals('http: dormant GET → 404', 404, $ctl->verify($req('GET', ['hub_mode' => 'subscribe', 'hub_verify_token' => '', 'hub_challenge' => 'abc']))->getStatus());
    $assert->equals('http: dormant POST → 404', 404, $ctl->receive($req('POST', [], '{}', ['X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', '{}', '')]))->getStatus());

    // ================================================================ W3 hooks — dormant keeps today's behaviour
    DB::delete('DELETE FROM whatsapp_messages');
    $calls = [];
    $mails = [];
    FlowNotifier::$mailer = static function (string $to, string $s, string $b) use (&$mails): void { $mails[] = [$to, $s, $b]; };
    $o1 = $mk();
    $res = $toDispatch($o1);
    $assert->that('dormant hooks: dispatch still returns the OTP for the wa.me card', strlen((string) $res['otp']) === 6 && $res['otp_sent'] === null);
    $otpVia = 'x';
    Orders::resendOtp($o1, null, $otpVia);
    $assert->that('dormant hooks: resend OTP not sent anywhere (admin sends it)', $otpVia === null && !$mails);
    $assert->equals('dormant hooks: no API calls, nothing logged', [0, 0], [count($calls), count($msgs())]);
    $w = PaymentRequests::send($o1, 'whatsapp', '9830012345');
    $assert->that('dormant request: same wa.me link as before', $w['status'] === 'opened' && str_starts_with((string) $w['open_url'], 'https://wa.me/919830012345?text=') && str_contains(rawurldecode((string) $w['open_url']), '/pay/' . $order($o1)['public_token']));
    $assert->equals('dormant request: WA log row = opened fallback', ['fallback', 'opened', 'payment_request'], [$last()['mode'], $last()['status'], $last()['template']]);
    $upi = Accounts::save(['type' => 'upi', 'name' => 'SBI UPI', 'upi_id' => 'maasarada@sbi', 'payee_name' => 'Maa Sarada', 'is_active' => 1, 'is_default_upi' => 1], null, '123456');
    Ledger::record($o1, ['method' => 'cash', 'amount' => 1000]);
    $assert->equals('dormant hooks: payment received sends nothing', [0, 1], [count($calls), count($msgs())]);

    // ================================================================ W3 hooks — active
    $activate();
    DB::delete('DELETE FROM whatsapp_messages');
    $calls = [];
    $reply = $okReply;
    $o2 = $mk(['new_customer' => ['name' => 'Bimal Roy', 'phone' => '9830066666', 'email' => 'bimal@example.com']]);
    $res = $toDispatch($o2);
    $tpls = array_map(static fn($c) => $c['body']['template']['name'], $calls);
    $assert->equals('active hooks: confirmed, dispatched, OTP in order', ['order_confirmed', 'order_dispatched', 'auth_otp'], $tpls);
    $assert->equals('active hooks: confirmed params', ['Bimal', $order($o2)['order_number'], '₹5,000.00'], array_column($calls[0]['body']['template']['components'][0]['parameters'], 'text'));
    $assert->equals('active hooks: order button → order token', $order($o2)['public_token'], $calls[0]['body']['template']['components'][1]['parameters'][0]['text']);
    $assert->equals('active hooks: OTP delivered on WhatsApp', 'whatsapp', $res['otp_sent']);
    $assert->equals('active hooks: OTP code went to Meta, not to the log', [(string) $res['otp'], false], [$calls[2]['body']['template']['components'][0]['parameters'][0]['text'], str_contains(json_encode($msgs()), (string) $res['otp'])]);
    $assert->that('active hooks: rows linked to the order', count(FlowNotifier::forOrder($o2)) === 3);

    // OTP resend inside 60 s → WhatsApp refuses (limit) → email fallback (never wa.me)
    $via = null;
    $otp2 = Orders::resendOtp($o2, null, $via);
    $assert->equals('otp fallback: emailed when WhatsApp can’t send', ['email', 'bimal@example.com', true], [$via, $mails[0][0] ?? null, str_contains($mails[0][2] ?? '', $otp2)]);

    $calls = [];
    Orders::transition($o2, 'delivered', ['otp' => $otp2]);
    $assert->equals('active hooks: delivered → order_delivered with invoice token', ['order_delivered', \App\Services\Order\DocumentService::invoiceFor($o2)['public_token']],
        [$calls[0]['body']['template']['name'], $calls[0]['body']['template']['components'][1]['parameters'][0]['text']]);

    // payments
    $calls = [];
    Ledger::record($o2, ['method' => 'cash', 'amount' => 2000]);
    $assert->equals('active hooks: shop payment → payment_received with balance', ['payment_received', ['Bimal', '₹2,000.00', $order($o2)['order_number'], '₹3,000.00']],
        [$calls[0]['body']['template']['name'], array_column($calls[0]['body']['template']['components'][0]['parameters'], 'text')]);
    $a = PayWindow::forOrder($order($o2), 'order');
    PayWindow::submit($a['public_token'], ['payer_name' => 'Bimal', 'payer_reference' => '431298765012']);
    $calls = [];
    Ledger::verify((int) $a['id'], ['amount' => '3000', 'payment_account_id' => $upi]);
    $assert->equals('active hooks: verified window payment → payment_received, balance 0', ['payment_received', '₹0.00'],
        [$calls[0]['body']['template']['name'] ?? null, $calls[0]['body']['template']['components'][0]['parameters'][3]['text'] ?? null]);
    $o3 = $mk(['new_customer' => ['name' => 'Chandan', 'phone' => '9830077777']]);
    $b = PayWindow::forOrder($order($o3), 'order');
    PayWindow::submit($b['public_token'], ['payer_name' => 'Chandan', 'payer_reference' => '555566667777']);
    $calls = [];
    Ledger::reject((int) $b['id'], 'No such UTR in our bank statement');
    $assert->equals('active hooks: rejected → payment_rejected with the reason', ['payment_rejected', 'No such UTR in our bank statement'],
        [$calls[0]['body']['template']['name'] ?? null, $calls[0]['body']['template']['components'][0]['parameters'][2]['text'] ?? null]);

    // payment request real send + API failure fallback
    $calls = [];
    $w = PaymentRequests::send($o3, 'whatsapp', '9830077777');
    $assert->equals('active request: template sent, no wa.me tab', ['sent', null, 'payment_request'], [$w['status'], $w['open_url'], $calls[0]['body']['template']['name']]);
    $assert->equals('active request: pay button → order token', $order($o3)['public_token'], $calls[0]['body']['template']['components'][1]['parameters'][0]['text']);
    DB::update('UPDATE orders SET last_payment_request_at = NULL WHERE id = ?', [$o3]);
    $reply = ['status' => 500, 'body' => '{"error":{"message":"Service unavailable","code":2}}'];
    $w = PaymentRequests::send($o3, 'whatsapp', '9830077777');
    $logRow = DB::selectOne('SELECT * FROM payment_request_logs WHERE id = ?', [$w['log_id']]);
    $assert->that('active request: API failed → wa.me button + error noted', $w['status'] === 'opened' && str_starts_with((string) $w['open_url'], 'https://wa.me/919830077777') && str_contains((string) $logRow['error'], 'Service unavailable'));

    // a failing API never breaks the order flow
    $o4 = $mk(['new_customer' => ['name' => 'Dipa', 'phone' => '9830088888']]);
    $reply = static function (): array { throw new \RuntimeException('network down'); };
    Orders::transition($o4, 'accepted');
    $assert->equals('active hooks: API down → order still accepted', 'accepted', $order($o4)['order_status']);
    $cfg->set('whatsapp.auto.order_confirmed', false);
    $calls = [];
    $o5 = $mk(['new_customer' => ['name' => 'Esha', 'phone' => '9830011111']]);
    Orders::transition($o5, 'accepted');
    $assert->equals('config: auto switch off → no send', 0, count($calls));

    FlowNotifier::$mailer = null;
    WA::$http = null;
};
