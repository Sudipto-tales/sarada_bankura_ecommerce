<?php

namespace App\Services\Store;

use App\Services\Customer\Phone;
use App\Services\Customer\PincodeLookup;
use App\Services\Order\DocumentService as Docs;
use App\Services\Payment\PayWindow;
use Vayu\Database\DB;
use Vayu\Validation\ValidationException;

/**
 * Storefront checkout (docs/storefront.md §3.6): bag → server-built order input → PayWindow.
 *
 *   start()   validate the form, build the OrderService::create input from the re-priced bag
 *             (prices from the DB, never the request), open a 6-minute checkout attempt and
 *             remember its token in the session. No order exists yet.
 *   (customer pays and submits in the window → PayWindow::submit creates the order)
 *   finish()  token must be this session's attempt and submitted → Cart::convert → order page.
 *
 * The form's field names are the keys of the errors thrown here, so the page can show them inline.
 */
final class Checkout
{
    public const SESSION = 'checkout_attempt';
    public const NOTE_MAX = 1000;

    /** States + union territories (the place of supply decides CGST + SGST vs IGST). */
    public const STATES = [
        'Andaman and Nicobar Islands', 'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chandigarh', 'Chhattisgarh',
        'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jammu and Kashmir',
        'Jharkhand', 'Karnataka', 'Kerala', 'Ladakh', 'Lakshadweep', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya',
        'Mizoram', 'Nagaland', 'Odisha', 'Puducherry', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura',
        'Uttar Pradesh', 'Uttarakhand', 'West Bengal',
    ];

    /* ================================================================ read */

    /**
     * Bag lines + totals + tax rows for one state (default: the shop's state).
     *
     * @return array{lines: list<array>, totals: array, tax_rows: list<array{label:string, amount:float}>, ready: bool}
     */
    public static function summary(?string $state = null): array
    {
        $lines = Cart::lines();
        $totals = Cart::totals($lines);
        $rows = Docs::taxRows(self::taxMode($state, $totals['tax_rate']), $totals['tax_rate'] ?: null, $totals['tax']);

        return ['lines' => $lines, 'totals' => $totals, 'tax_rows' => $rows, 'ready' => $totals['count'] > 0 && $totals['blocked'] === 0];
    }

    /** cgst_sgst inside the shop's state, igst outside, none without GST (= DocumentService::taxMode). */
    public static function taxMode(?string $state, float $rate): string
    {
        if ($rate <= 0) {
            return 'none';
        }

        return Docs::taxMode(['gst_rate' => $rate, 'place_of_supply' => $state ?: (string) business('address.state')]);
    }

    /** Matches a state name case-insensitively ("west bengal" → "West Bengal"); null if unknown. */
    public static function canonicalState(string $s): ?string
    {
        $s = mb_strtolower(trim(preg_replace('/\s+/', ' ', str_replace('&', 'and', $s)) ?? ''));
        foreach (self::STATES as $name) {
            if (mb_strtolower($name) === $s) {
                return $name;
            }
        }
        $aliases = ['orissa' => 'Odisha', 'pondicherry' => 'Puducherry', 'nct of delhi' => 'Delhi', 'new delhi' => 'Delhi', 'uttaranchal' => 'Uttarakhand'];

        return $aliases[$s] ?? null;
    }

    /* ================================================================ build */

    /**
     * Validate the checkout form and build the OrderService::create input from the bag lines.
     * Throws ValidationException keyed by form field.
     *
     * @param array<string,mixed> $in raw POST
     * @param list<array<string,mixed>> $lines Cart::lines()
     * @return array<string,mixed>
     */
    public static function buildOrderInput(array $in, array $lines): array
    {
        // ---- signed in (S5): the account's phone, and optionally one of its saved addresses ----
        $customer = Context::customer();
        $cid = (int) ($customer['customer_id'] ?? 0);
        $savedAddress = null;
        if ($cid > 0) {
            $in['phone'] = (string) DB::table('customers')->where('id', $cid)->value('phone'); // the phone is the account; not editable here
            $aid = (int) (is_scalar($in['address_id'] ?? null) ? $in['address_id'] : 0);
            $savedAddress = $aid > 0 ? DB::selectOne('SELECT * FROM customer_addresses WHERE id = ? AND customer_id = ?', [$aid, $cid]) : null;
            if ($savedAddress !== null) {
                // Use the saved address as stored (the order links to it; no duplicate is created).
                $in = array_merge($in, [
                    'pincode' => (string) $savedAddress['pincode'], 'state' => (string) $savedAddress['state'],
                    'city' => (string) ($savedAddress['city'] ?: $savedAddress['district']), 'area' => (string) ($savedAddress['area'] ?: $savedAddress['post_office']),
                    'line1' => (string) $savedAddress['address_line1'], 'line2' => (string) $savedAddress['address_line2'], 'landmark' => '',
                ]);
            }
        }

        $t = static fn(string $k, int $max): string => mb_substr(trim(preg_replace('/\s+/', ' ', is_scalar($in[$k] ?? null) ? (string) $in[$k] : '') ?? ''), 0, $max);
        $err = [];

        // ---- bag ----
        $ok = array_values(array_filter($lines, fn ($l) => $l['line_ok']));
        if ($lines === []) {
            $err['cart'][] = 'Your bag is empty.';
        } elseif (count($ok) !== count($lines)) {
            $err['cart'][] = 'Some stones in your bag can’t be bought right now. Remove them from your bag to continue.';
        }

        // ---- contact ----
        $name = $t('name', 120);
        if (mb_strlen($name) < 2) {
            $err['name'][] = 'Enter your name.';
        }
        $phone = Phone::normalize($t('phone', 20));
        if ($phone === null) {
            $err['phone'][] = 'Enter your 10-digit mobile number.';
        }
        $email = mb_strtolower($t('email', 160));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err['email'][] = 'Enter a valid email address, or leave it empty.';
        }

        // ---- delivery address ----
        $ship = self::address($in, '', $err);
        $ship['name'] = $name;
        $ship['phone'] = $phone;

        // ---- billing ----
        $billingSame = !isset($in['billing_same']) || !empty($in['billing_same']);
        $bill = null;
        if (!$billingSame) {
            $bill = self::address($in, 'bill_', $err);
            $bill['name'] = $t('bill_name', 120) ?: $name;
            $bill['phone'] = $phone;
        }

        $note = trim(is_scalar($in['buyer_note'] ?? null) ? (string) $in['buyer_note'] : '');
        if (mb_strlen($note) > self::NOTE_MAX) {
            $err['buyer_note'][] = 'Keep the note under ' . self::NOTE_MAX . ' characters.';
        }
        if (empty($in['terms'])) {
            $err['terms'][] = 'Please accept the terms and returns policy to continue.';
        }

        if ($err) {
            throw new ValidationException($err);
        }

        $rate = Cart::taxRate();
        $input = [
            'delivery' => 'ship',
            'shipping_address_id' => 'new',
            'new_shipping' => $ship,
            'billing_same' => $billingSame ? 1 : 0,
            'items' => array_map(fn ($l) => [
                'product_id' => (int) $l['product_id'],
                'variant_id' => (int) $l['variant_id'],
                'quantity' => (float) $l['qty'],
                'unit_price' => number_format((float) $l['price'], 2, '.', ''), // server price, frozen for the 6 minutes
            ], $ok),
            'gst_enabled' => $rate > 0 ? 1 : 0,
            'gst_rate' => $rate > 0 ? $rate : null,
            'buyer_note' => $note !== '' ? $note : null,
            'channel_detail' => 'website',
        ];
        if ($savedAddress !== null) {
            $input['shipping_address_id'] = (string) $savedAddress['id'];
            unset($input['new_shipping']);
        }
        if (!$billingSame) {
            $input['billing_address_id'] = 'new';
            $input['new_billing'] = $bill;
        }
        if ($cid > 0) {
            $input['customer_id'] = $cid;
        } else {
            $input['new_customer'] = ['name' => $name, 'phone' => $phone, 'email' => $email];
        }

        return $input;
    }

    /**
     * One address block (prefix '' = delivery, 'bill_' = billing).
     *
     * @param array<string,mixed> $in
     * @param array<string, list<string>> $err
     * @return array<string,mixed> CustomerService::cleanAddresses shape
     */
    private static function address(array $in, string $p, array &$err): array
    {
        $t = static fn(string $k, int $max): string => mb_substr(trim(preg_replace('/\s+/', ' ', is_scalar($in[$p . $k] ?? null) ? (string) $in[$p . $k] : '') ?? ''), 0, $max);
        $pin = preg_replace('/\D+/', '', $t('pincode', 12)) ?? '';
        if (!PincodeLookup::valid($pin)) {
            $err[$p . 'pincode'][] = 'Enter the 6-digit pincode.';
        }
        $state = self::canonicalState($t('state', 80));
        if ($state === null) {
            $err[$p . 'state'][] = 'Pick your state.';
        }
        $city = $t('city', 120);
        if ($city === '') {
            $err[$p . 'city'][] = 'Enter your district or city.';
        }
        $line1 = $t('line1', 255);
        if (mb_strlen($line1) < 3) {
            $err[$p . 'line1'][] = $p === '' ? 'Enter your house / building and street.' : 'Enter the billing street address.';
        }
        $line2 = $t('line2', 200);
        $landmark = $t('landmark', 80);
        if ($landmark !== '') {
            $line2 = trim($line2 . ($line2 !== '' ? ' · ' : '') . 'Landmark: ' . $landmark);
        }

        return [
            'type' => $p === '' ? 'shipping' : 'billing',
            'address_line1' => $line1,
            'address_line2' => $line2 !== '' ? mb_substr($line2, 0, 255) : null,
            'area' => $t('area', 120) ?: null,
            'city' => $city,
            'district' => $city,
            'state' => $state,
            'pincode' => $pin,
            'country' => 'India',
        ];
    }

    /* ================================================================ start / finish */

    /**
     * Validate, build, open the payment window. Throws ValidationException (form errors) or
     * PaymentException (no UPI account, too many windows, total 0).
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed> payment_attempts row
     */
    public static function start(array $in, ?string $ip = null, ?string $ua = null): array
    {
        $cart = Cart::current();
        $input = self::buildOrderInput($in, $cart === null ? [] : Cart::lines());
        $input['_cart_id'] = (int) $cart['id']; // ignored by OrderService; lets staff trace the bag

        // A new window replaces this visitor's previous unsubmitted one (double click, "Try again").
        $prev = self::session();
        if ($prev !== null) {
            PayWindow::close($prev['token']);
        }
        try {
            $a = PayWindow::forCheckout($input, (string) ($in['pay_choice'] ?? 'advance'), $ip, $ua);
        } catch (ValidationException $e) {
            throw new ValidationException(['cart' => array_values(array_unique(array_merge(...array_values($e->errors()))))]);
        }
        session()->put(self::SESSION, ['token' => (string) $a['public_token'], 'cart_id' => (int) $cart['id']]);

        return $a;
    }

    /**
     * After the customer submitted their payment: the bag becomes the order's.
     *
     * @return array{ok: bool, order_token?: string, order_number?: string, message?: string}
     */
    public static function finish(string $token): array
    {
        $s = self::session();
        if ($s === null || !hash_equals($s['token'], $token)) {
            return ['ok' => false, 'message' => 'We couldn’t match this payment to your checkout. If you paid, check your WhatsApp or contact us with your payment ID.'];
        }
        $a = PayWindow::find($token);
        if ($a === null || !in_array($a['status'], ['submitted', 'verified'], true)) {
            return ['ok' => false, 'message' => 'Your payment details weren’t submitted yet. Place the order again to open a new payment window.'];
        }
        session()->forget(self::SESSION);
        if (!$a['order_id']) {
            // PayWindow kept the payment but couldn't create the order (bag changed while paying).
            return ['ok' => false, 'message' => 'We received your payment details, but a stone in your order changed while you paid. Our team will call you to confirm — please don’t pay again.'];
        }
        Cart::convert((int) $s['cart_id'], (int) $a['order_id']);
        $o = DB::selectOne('SELECT order_number, public_token FROM orders WHERE id = ?', [(int) $a['order_id']]);

        return ['ok' => true, 'order_token' => (string) $o['public_token'], 'order_number' => (string) $o['order_number']];
    }

    /**
     * The window was submitted but the page never reached /checkout/done (closed tab, lost
     * connection). Called on /cart and /checkout: finishes it so the paid bag doesn't linger.
     *
     * @return array{ok: bool, order_token?: string, order_number?: string, message?: string}|null  finish() result
     */
    public static function reconcile(): ?array
    {
        $s = self::session();
        if ($s === null) {
            return null;
        }
        $a = PayWindow::find($s['token']);
        if ($a === null || in_array($a['status'], ['expired', 'rejected'], true)) {
            session()->forget(self::SESSION);

            return null;
        }
        if ($a['status'] === 'open') {
            return null;
        }
        return self::finish($s['token']);
    }

    /** @return array{token: string, cart_id: int}|null */
    private static function session(): ?array
    {
        $s = session()->get(self::SESSION);

        return is_array($s) && isset($s['token'], $s['cart_id']) && is_string($s['token']) ? ['token' => $s['token'], 'cart_id' => (int) $s['cart_id']] : null;
    }
}
