<?php

namespace App\Controllers\Store;

use App\Controllers\PayController;
use App\Services\Customer\PincodeLookup;
use App\Services\Order\PaymentService;
use App\Services\Payment\Accounts;
use App\Services\Payment\PaymentException;
use App\Services\Store\Checkout;
use App\Services\Store\Context;
use App\Services\Store\View;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;
use Vayu\Validation\ValidationException;

/**
 * Checkout (docs/storefront.md §3.6).
 *
 *   GET  /checkout                    details form + summary (bag empty / blocked → /cart)
 *   POST /checkout/start              validate + open the 6-minute payment window → JSON for pay-window.js
 *   POST /checkout/done               after "I have paid — Submit": bag → order, redirect to the order page
 *   GET  /checkout/pincode/{code}     pincode → district / state / post offices (our cache in front of the API)
 */
class CheckoutController
{
    public function show(): Response
    {
        View::loadHelpers();
        if (($r = Checkout::reconcile()) !== null) {
            return $this->finished($r);
        }
        $sum = Checkout::summary();
        if ($sum['lines'] === []) {
            return redirect((string) route('store.cart'));
        }
        if (!$sum['ready']) {
            session()->flash('error', 'Some stones in your bag can’t be bought right now. Remove them to continue.');

            return redirect((string) route('store.cart'));
        }

        return View::page('store.checkout', $sum + [
            'states' => Checkout::STATES,
            'home_state' => (string) business('address.state', 'West Bengal'),
            'advance_percent' => PaymentService::ADVANCE_PERCENT,
            'tax_included' => (bool) business('tax.prices_include_tax', false),
            'can_pay' => Accounts::defaultUpi() !== null,
            'prefill' => self::prefill(),
        ], [
            'title' => 'Checkout',
            'layout' => 'minimal',
            'active' => 'checkout',
            'chrome' => false,
            'head' => '<meta name="robots" content="noindex">',
        ]);
    }

    public function start(Request $request): Response
    {
        try {
            $a = Checkout::start((array) $request->input(), $request->ip(), (string) $request->header('User-Agent'));
        } catch (ValidationException $e) {
            if (!$request->wantsJson()) {
                throw $e; // redirect back with errors + old input
            }

            return json(['ok' => false, 'message' => 'Please check the highlighted details.', 'errors' => $e->errors()], 422);
        } catch (PaymentException $e) {
            if (!$request->wantsJson()) {
                session()->flash('error', $e->getMessage());

                return redirect((string) route('store.checkout'));
            }

            return json(['ok' => false, 'message' => $e->getMessage()], 409);
        }
        if (!$request->wantsJson()) {
            // The payment window needs JavaScript (QR code, timer). Close the attempt again.
            \App\Services\Payment\PayWindow::close((string) $a['public_token']);
            session()->flash('error', 'Please turn on JavaScript to pay by UPI, or message us on WhatsApp to order.');

            return redirect((string) route('store.checkout'));
        }

        return json(['ok' => true] + PayController::payload($a));
    }

    public function done(Request $request): Response
    {
        $r = Checkout::finish((string) $request->input('token', ''));

        return $this->finished($r);
    }

    public function pincode(string $code): Response
    {
        try {
            $res = PincodeLookup::find($code);
        } catch (\Throwable) {
            $res = ['ok' => false, 'reason' => 'unavailable'];
        }
        if ($res['ok']) {
            $res['state'] = Checkout::canonicalState((string) $res['state']) ?? $res['state'];
        }

        return json($res, $res['ok'] || ($res['reason'] ?? '') !== 'invalid' ? 200 : 422);
    }

    /* ---------------------------------------------------------------- */

    /** @param array{ok: bool, order_token?: string, order_number?: string, message?: string} $r */
    private function finished(array $r): Response
    {
        if ($r['ok']) {
            session()->flash('order_placed', $r['order_number']);

            return redirect((string) route('order.public', ['token' => $r['order_token']]));
        }
        session()->flash('error', (string) $r['message']);

        return redirect((string) route('store.cart'));
    }

    /** Signed-in customer (S5) → their name / phone / email / default address. */
    private static function prefill(): array
    {
        $u = Context::customer();
        $cid = (int) ($u['customer_id'] ?? 0);
        if ($cid <= 0) {
            return [];
        }
        $c = DB::selectOne('SELECT name, phone, email FROM customers WHERE id = ?', [$cid]) ?? [];
        $a = DB::selectOne("SELECT * FROM customer_addresses WHERE customer_id = ? AND type IN ('shipping', 'both') ORDER BY is_default DESC, id DESC LIMIT 1", [$cid]) ?? [];

        return array_filter([
            'name' => $c['name'] ?? null, 'phone' => $c['phone'] ?? null, 'email' => $c['email'] ?? null,
            'pincode' => $a['pincode'] ?? null, 'state' => $a['state'] ?? null, 'city' => $a['city'] ?? ($a['district'] ?? null),
            'area' => $a['area'] ?? null, 'line1' => $a['address_line1'] ?? null, 'line2' => $a['address_line2'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
