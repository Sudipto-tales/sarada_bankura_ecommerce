<?php

namespace App\Services\Payment;

use App\Services\Customer\Phone;
use App\Services\Media\MediaException;
use App\Services\Media\MediaService as Media;
use App\Services\NotificationService;
use App\Services\Order\DocumentNumber;
use App\Services\Order\Money;
use App\Services\Order\OrderService;
use App\Services\Order\PaymentService;
use Vayu\Database\DB;
use Vayu\Http\UploadedFile;
use Vayu\Validation\ValidationException;

/**
 * The customer payment window (docs/payments.md §1): one attempt per opening, 6 minutes, the
 * amount fixed by the server. The only writer of payment_attempts up to "submitted" (Ledger does
 * verify / reject).
 *
 *   order / invoice / request  amount = what is due now (outstanding − awaiting verification)
 *   checkout                   amount = 20 % advance (rounded up) or full; the order is created
 *                              only when the customer submits — an expired checkout leaves no order
 */
final class PayWindow
{
    public const SECONDS = 360;          // 6-minute window
    public const GRACE = 30;             // slow uploads still accepted this long after expiry
    public const UPI_LIMIT = 100000;     // above this, point to bank transfer
    public const CONTEXTS = ['checkout', 'order', 'invoice', 'request'];
    public const STATUSES = ['open' => 'Open', 'submitted' => 'To verify', 'expired' => 'Expired', 'verified' => 'Verified', 'rejected' => 'Rejected'];
    private const OPENS_PER_HOUR = 12;

    /* ============================================================ open */

    /**
     * Open (or resume) a window for an existing order.
     *
     * @param array<string,mixed> $order
     * @return array<string,mixed> attempt row
     */
    public static function forOrder(array $order, string $context = 'order', ?string $ip = null, ?string $ua = null): array
    {
        self::expireStale();
        $context = in_array($context, ['order', 'invoice', 'request'], true) ? $context : 'order';
        $order = DB::selectOne('SELECT * FROM orders WHERE id = ?', [(int) $order['id']]) ?? $order;
        if ($order['order_status'] === 'rejected') {
            throw new PaymentException('This order was not accepted, so no payment is needed. If you already paid, we will contact you about a refund.');
        }
        $due = PaymentService::dueNow($order);
        if ($due <= 0) {
            throw new PaymentException((float) $order['pending_verification_amount'] > 0
                ? 'Your last payment is being checked by our team. Nothing more is due until it is confirmed.'
                : 'Nothing is due on this order — thank you!');
        }
        $account = self::account();
        $open = DB::selectOne("SELECT * FROM payment_attempts WHERE order_id = ? AND status = 'open' AND expires_at > ? ORDER BY id DESC LIMIT 1",
            [(int) $order['id'], date('Y-m-d H:i:s')]);
        if ($open && PaymentService::cents($open['amount']) === PaymentService::cents($due)) {
            return $open; // second tab / double click: keep the same attempt and timer
        }
        self::throttle('order:' . $order['id'] . ':' . $ip);

        return self::create([
            'context' => $context,
            'order_id' => (int) $order['id'],
            'customer_id' => (int) $order['customer_id'],
            'customer_name' => $order['customer_name'],
            'customer_phone' => $order['customer_phone'],
            'pay_choice' => 'due',
            'amount' => $due,
            'payment_account_id' => (int) $account['id'],
            'ip' => $ip, 'user_agent' => $ua,
        ]);
    }

    /**
     * Storefront checkout: price the server-built order input, take the advance or the full
     * amount, and hold the input on the attempt. No order exists yet.
     * $orderInput must be built by server code (cart → OrderService::create input), never raw POST.
     *
     * @param array<string,mixed> $orderInput
     * @return array<string,mixed> attempt row
     */
    public static function forCheckout(array $orderInput, string $choice, ?string $ip = null, ?string $ua = null): array
    {
        self::expireStale();
        $account = self::account();
        $q = OrderService::quote($orderInput); // throws ValidationException for a bad cart
        if ($q['grand_total'] <= 0) {
            throw new PaymentException('Your cart total is zero.');
        }
        $choice = $choice === 'full' ? 'full' : 'advance';
        self::throttle('checkout:' . $ip);

        return self::create([
            'context' => 'checkout',
            'order_id' => null,
            'customer_id' => null,
            'customer_name' => $q['customer_name'],
            'customer_phone' => $q['customer_phone'],
            'pay_choice' => $choice,
            'amount' => $choice === 'full' ? $q['grand_total'] : PaymentService::advance($q['grand_total']),
            'payment_account_id' => (int) $account['id'],
            'cart_snapshot' => json_encode($orderInput, JSON_UNESCAPED_UNICODE),
            'ip' => $ip, 'user_agent' => $ua,
        ]);
    }

    /* ============================================================ submit / close */

    /**
     * "I have paid — Submit": payer UPI name + UTR and/or screenshot.
     *
     * @param array<string,mixed> $in payer_name, payer_reference
     * @return array<string,mixed> the updated attempt (+ order_number / order_token)
     */
    public static function submit(string $token, array $in, ?UploadedFile $proof = null): array
    {
        $a = self::find($token);
        if ($a === null) {
            throw new PaymentException('This payment window is no longer valid. Please start again.');
        }
        if ($a['status'] === 'submitted' || $a['status'] === 'verified') {
            return self::withOrder($a); // already done (double submit)
        }
        if ($a['status'] !== 'open') {
            throw new PaymentException(self::expiredMessage());
        }
        if (time() > strtotime((string) $a['expires_at']) + self::GRACE) {
            DB::update("UPDATE payment_attempts SET status = 'expired', updated_at = ? WHERE id = ? AND status = 'open'", [date('Y-m-d H:i:s'), (int) $a['id']]);
            throw new PaymentException(self::expiredMessage());
        }

        $err = [];
        $name = trim(preg_replace('/\s+/', ' ', (string) ($in['payer_name'] ?? '')) ?? '');
        if (mb_strlen($name) < 2) {
            $err['payer_name'][] = 'Enter the name shown on your UPI / bank account.';
        }
        $ref = strtoupper(preg_replace('/[\s]+/', '', (string) ($in['payer_reference'] ?? '')) ?? '');
        if ($ref !== '' && !preg_match('/^[A-Z0-9\-]{6,40}$/', $ref)) {
            $err['payer_reference'][] = 'The payment ID / UTR is usually 12 digits — letters, digits and dashes only.';
        }
        $hasProof = $proof !== null && $proof->getError() !== UPLOAD_ERR_NO_FILE;
        if ($ref === '' && !$hasProof) {
            $err['payer_reference'][] = 'Enter the payment ID / UTR, or upload a screenshot of the payment.';
        }
        if ($err) {
            throw new ValidationException($err);
        }

        $media = null;
        if ($hasProof) {
            try {
                $media = Media::storeUpload($proof, ['kinds' => ['image', 'document'], 'disk' => 'private', 'dedupe' => false,
                    'title' => 'Payment proof ' . $a['attempt_number']]);
            } catch (MediaException $e) {
                throw new ValidationException(['proof' => [$e->getMessage()]]);
            }
        }

        $now = date('Y-m-d H:i:s');
        $orderProblem = null;
        try {
            DB::transaction(static function () use ($a, $name, $ref, $media, $now, &$orderProblem): void {
                $n = DB::update("UPDATE payment_attempts SET status = 'submitted', payer_name = ?, payer_reference = ?, proof_media_id = ?, submitted_at = ?, updated_at = ?
                    WHERE id = ? AND status = 'open'", [mb_substr($name, 0, 120), $ref ?: null, $media['id'] ?? null, $now, $now, (int) $a['id']]);
                if ($n !== 1) {
                    throw new PaymentException('This payment was already submitted.');
                }
                $orderId = $a['order_id'] ? (int) $a['order_id'] : null;
                if ($a['context'] === 'checkout' && $orderId === null) {
                    try {
                        $orderId = OrderService::create((array) json_decode((string) $a['cart_snapshot'], true), null, ['online' => true]);
                        $o = DB::selectOne('SELECT customer_id FROM orders WHERE id = ?', [$orderId]);
                        DB::table('payment_attempts')->where('id', (int) $a['id'])->update(['order_id' => $orderId, 'customer_id' => (int) $o['customer_id']]);
                    } catch (ValidationException $e) {
                        // The cart changed while they paid (product archived…). Keep the payment; staff follow up.
                        $orderProblem = implode(' ', array_merge(...array_values($e->errors())));
                        $orderId = null;
                    }
                }
                if ($media) {
                    Media::attach('payment_attempt', (int) $a['id'], (int) $media['id'], 'proof');
                }
                if ($orderId) {
                    PaymentService::syncOrder($orderId);
                }
            });
        } catch (\Throwable $e) {
            if ($media && empty($media['duplicate'])) {
                Media::purgeIfUnused((int) $media['id']);
            }
            throw $e;
        }

        $a = self::find($token);
        $order = $a['order_id'] ? DB::selectOne('SELECT id, order_number FROM orders WHERE id = ?', [(int) $a['order_id']]) : null;
        $who = $name . ($a['customer_phone'] ? ' · ' . Phone::format((string) $a['customer_phone']) : '');
        if ($a['context'] === 'checkout' && $order) {
            NotificationService::notify('order.new', 'New website order ' . $order['order_number'],
                Money::inr($a['amount']) . ' paid by UPI, waiting for verification. ' . $who, route('admin.orders.show', ['id' => $order['id']]), 'info', ['order_id' => (int) $order['id']]);
        }
        NotificationService::notify('payment.submitted',
            'Verify ' . Money::inr($a['amount']) . ($order ? ' · ' . $order['order_number'] : ' · no order'),
            $who . ($ref ? ' · UTR ' . $ref : ' · screenshot') . ($orderProblem ? ' · Order not created: ' . $orderProblem : ''),
            route('admin.payments') . '?tab=verify', $orderProblem ? 'warning' : 'info',
            ['attempt_id' => (int) $a['id'], 'order_id' => $order['id'] ?? null], 'payment:submitted:' . $a['id']);

        return self::withOrder($a);
    }

    /** The customer closed the window without submitting: a failed attempt (admin can follow up). */
    public static function close(string $token): void
    {
        DB::update("UPDATE payment_attempts SET status = 'expired', updated_at = ? WHERE public_token = ? AND status = 'open'", [date('Y-m-d H:i:s'), $token]);
    }

    /** Mark open attempts past their time (+ grace) as expired. Lazy on read + cron (payments:expire). */
    public static function expireStale(): int
    {
        $now = date('Y-m-d H:i:s');

        return DB::update("UPDATE payment_attempts SET status = 'expired', updated_at = ? WHERE status = 'open' AND expires_at < ?",
            [$now, date('Y-m-d H:i:s', time() - self::GRACE)]);
    }

    /* ============================================================ read */

    public static function find(string $token): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $token) ? DB::selectOne('SELECT * FROM payment_attempts WHERE public_token = ?', [$token]) : null;
    }

    /**
     * Everything the window needs (JSON for pay-window.js).
     *
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    public static function present(array $a): array
    {
        $acc = $a['payment_account_id'] ? Accounts::find((int) $a['payment_account_id']) : null;
        $bank = Accounts::bankForWindow();
        $order = $a['order_id'] ? DB::selectOne('SELECT order_number, public_token FROM orders WHERE id = ?', [(int) $a['order_id']]) : null;
        $amount = (float) $a['amount'];
        $note = trim(($order ? 'Order ' . $order['order_number'] . ' ' : '') . self::shortRef((string) $a['attempt_number']));

        return [
            'token' => $a['public_token'],
            'number' => $a['attempt_number'],
            'ref' => self::shortRef((string) $a['attempt_number']),
            'status' => $a['status'],
            'amount' => $amount,
            'amount_fmt' => Money::inr($amount),
            'seconds_left' => max(0, strtotime((string) $a['expires_at']) - time()),
            'order_number' => $order['order_number'] ?? null,
            'upi' => $acc && $acc['type'] === 'upi' ? [
                'id' => $acc['upi_id'], 'payee' => $acc['payee_name'],
                'uri' => self::upiUri((string) $acc['upi_id'], (string) $acc['payee_name'], $amount, $note),
            ] : null,
            'bank' => $bank ? ['bank' => $bank['bank_name'], 'holder' => $bank['account_holder'], 'number' => $bank['account_number'], 'ifsc' => $bank['ifsc']] : null,
            'over_upi_limit' => $amount > self::UPI_LIMIT,
            'reject_reason' => $a['status'] === 'rejected' ? $a['reject_reason'] : null,
        ];
    }

    /**
     * upi://pay link (also the QR content). The reference goes in the note (tn) so the admin can spot
     * it in the bank app. `tr` is left out on purpose: it is meant for merchant VPAs, and some apps
     * refuse personal/current-account VPAs that carry it.
     */
    public static function upiUri(string $vpa, string $payee, float $amount, string $note): string
    {
        return 'upi://pay?' . http_build_query([
            'pa' => $vpa, 'pn' => $payee, 'am' => number_format($amount, 2, '.', ''), 'cu' => 'INR', 'tn' => mb_substr($note, 0, 50),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** PAT/26-27/0042 → PAT-2627-0042 (no slashes — safe in UPI notes and bank statements). */
    public static function shortRef(string $number): string
    {
        return str_replace('/', '-', str_replace('-', '', $number));
    }

    public static function expiredMessage(): string
    {
        return 'Time ran out. If you already paid, don’t pay again — contact us on WhatsApp with your payment ID.';
    }

    /* ============================================================ internals */

    private static function account(): array
    {
        $acc = Accounts::defaultUpi();
        if ($acc === null) {
            throw new PaymentException('Online payment isn’t set up yet. Please contact us to pay.');
        }

        return $acc;
    }

    private static function throttle(string $key): void
    {
        try {
            $limiter = app('rate_limiter');
        } catch (\Throwable) {
            return;
        }
        $key = 'paywin:' . sha1($key);
        if ($limiter->tooManyAttempts($key, self::OPENS_PER_HOUR)) {
            throw new PaymentException('Too many payment windows opened. Please wait a few minutes or contact us.');
        }
        $limiter->hit($key, 3600);
    }

    /** @param array<string,mixed> $row */
    private static function create(array $row): array
    {
        $now = date('Y-m-d H:i:s');
        $id = DB::transaction(static fn(): int => (int) DB::table('payment_attempts')->insertGetId($row + [
            'attempt_number' => DocumentNumber::next('attempt'),
            'public_token' => bin2hex(random_bytes(16)),
            'status' => 'open',
            'expires_at' => date('Y-m-d H:i:s', time() + self::SECONDS),
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        return DB::selectOne('SELECT * FROM payment_attempts WHERE id = ?', [$id]);
    }

    /** @param array<string,mixed> $a */
    private static function withOrder(array $a): array
    {
        $o = $a['order_id'] ? DB::selectOne('SELECT order_number, public_token FROM orders WHERE id = ?', [(int) $a['order_id']]) : null;

        return $a + ['order_number' => $o['order_number'] ?? null, 'order_token' => $o['public_token'] ?? null];
    }
}
