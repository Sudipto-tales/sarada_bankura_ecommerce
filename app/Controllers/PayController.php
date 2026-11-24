<?php

namespace App\Controllers;

use App\Services\Order\DocumentService as Docs;
use App\Services\Order\PaymentService;
use App\Services\Payment\Accounts;
use App\Services\Payment\PaymentException;
use App\Services\Payment\PayWindow;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

/**
 * Public payment window endpoints (no login; unguessable tokens).
 *
 *   GET  /pay/{order token}                 pay link page (summary + "Pay now"); the timer starts on Pay now
 *   POST /pay/{order token}/start           open / resume a 6-minute attempt → JSON for pay-window.js
 *   GET  /pay/attempt/{attempt token}       attempt state (JSON)
 *   POST /pay/attempt/{attempt token}/submit payer name + UTR / screenshot
 *   POST /pay/attempt/{attempt token}/close  closed without submitting → failed attempt
 */
class PayController
{
    public function show(string $token): Response
    {
        $o = self::order($token);
        if ($o === null) {
            return Response::make(view('documents.not-found', ['what' => 'payment link'])->getContent(), 404);
        }
        PayWindow::expireStale();
        $o = self::order($token);

        return view('documents.pay', [
            'o' => $o,
            'dueNow' => PaymentService::dueNow($o),
            'canPay' => Accounts::defaultUpi() !== null,
            'business' => business(),
            'logo' => business('logo') && is_file(app()->publicPath((string) business('logo'))) ? base_url((string) business('logo')) : null,
            'items' => DB::select('SELECT product_name, variant_name, quantity, unit, line_total FROM order_items WHERE order_id = ? ORDER BY sort, id', [(int) $o['id']]),
            'taxRows' => Docs::taxRows(Docs::taxMode($o), $o['gst_rate'] !== null ? (float) $o['gst_rate'] : null, (float) $o['tax_amount']),
            'lastRejected' => self::lastRejected((int) $o['id']),
        ]);
    }

    public function start(Request $request, string $token): Response
    {
        $o = self::order($token);
        if ($o === null) {
            return json(['ok' => false, 'message' => 'This payment link is not valid.'], 404);
        }
        try {
            $a = PayWindow::forOrder($o, (string) $request->input('context', 'order'), $request->ip(), (string) $request->header('User-Agent'));
        } catch (PaymentException $e) {
            return json(['ok' => false, 'message' => $e->getMessage()], 409);
        }

        return json(['ok' => true] + self::payload($a));
    }

    public function status(string $token): Response
    {
        PayWindow::expireStale();
        $a = PayWindow::find($token);

        return $a ? json(['ok' => true] + self::payload($a)) : json(['ok' => false, 'message' => 'Not found.'], 404);
    }

    public function submit(Request $request, string $token): Response
    {
        try {
            $a = PayWindow::submit($token, $request->input(), $request->file('proof'));
        } catch (PaymentException $e) {
            $a = PayWindow::find($token);

            return json(['ok' => false, 'message' => $e->getMessage(), 'status' => $a['status'] ?? null], 409);
        }

        return json(['ok' => true, 'status' => $a['status'], 'number' => $a['attempt_number'], 'order_number' => $a['order_number'],
            'order_url' => $a['order_token'] ? route('order.public', ['token' => $a['order_token']]) : null]);
    }

    public function close(string $token): Response
    {
        PayWindow::close($token);

        return json(['ok' => true]);
    }

    /* ---------------------------------------------------------------- */

    /** @return array<string,mixed> */
    private static function payload(array $a): array
    {
        return PayWindow::present($a) + [
            'submit_url' => route('pay.submit', ['token' => $a['public_token']]),
            'close_url' => route('pay.close', ['token' => $a['public_token']]),
            'status_url' => route('pay.status', ['token' => $a['public_token']]),
            'expired_message' => PayWindow::expiredMessage(),
            'whatsapp' => preg_replace('/\D/', '', (string) business('whatsapp')) ?: null,
        ];
    }

    private static function order(string $token): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $token) ? DB::selectOne('SELECT * FROM orders WHERE public_token = ?', [$token]) : null;
    }

    /** The latest rejected submission (shown to the customer with the reason) if nothing newer exists. */
    public static function lastRejected(int $orderId): ?array
    {
        $a = DB::selectOne("SELECT status, amount, reject_reason, reviewed_at FROM payment_attempts WHERE order_id = ? AND status IN ('submitted', 'verified', 'rejected') ORDER BY id DESC LIMIT 1", [$orderId]);

        return $a && $a['status'] === 'rejected' ? $a : null;
    }
}
