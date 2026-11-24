<?php

namespace App\Services\Payment;

use App\Services\NotificationService;
use App\Services\Order\DocumentNumber;
use App\Services\Order\Money;
use App\Services\Order\PaymentService;
use Vayu\Database\DB;
use Vayu\Validation\ValidationException;

/**
 * Money in and out of an order — the only writer of `payments` and `payment_refunds`, and of the
 * verify/reject step on `payment_attempts`. Every method ends with PaymentService::syncOrder().
 * Nothing here changes the order status (docs/payments.md: payment never moves an order).
 */
final class Ledger
{
    /* ============================================================ verify a window submission */

    /**
     * Admin confirms a submitted attempt with the amount the bank actually shows.
     *
     * @param array<string,mixed> $in amount, payment_account_id, received_at, notes
     * @return array{payment_id:int, payment_number:string, difference:float}
     */
    public static function verify(int $attemptId, array $in, ?int $by = null): array
    {
        $a = DB::selectOne('SELECT * FROM payment_attempts WHERE id = ?', [$attemptId]);
        if ($a === null) {
            throw new PaymentException('That payment submission no longer exists.');
        }
        if ($a['status'] !== 'submitted') {
            throw new PaymentException('Payment ' . $a['attempt_number'] . ' is ' . $a['status'] . ' — only submitted payments can be verified.');
        }
        if (!$a['order_id']) {
            throw new PaymentException('This payment isn’t linked to an order yet. Create the order first, then record the payment on it.');
        }
        $order = self::order((int) $a['order_id']);
        $v = self::validateMoney($in, true);
        $now = date('Y-m-d H:i:s');

        $res = DB::transaction(static function () use ($a, $order, $v, $by, $now): array {
            $n = DB::update("UPDATE payment_attempts SET status = 'verified', reviewed_by = ?, reviewed_at = ?, updated_at = ? WHERE id = ? AND status = 'submitted'",
                [$by, $now, $now, (int) $a['id']]);
            if ($n !== 1) {
                throw new PaymentException('Payment ' . $a['attempt_number'] . ' was just reviewed by someone else — reload the page.');
            }
            $acc = Accounts::find($v['payment_account_id']);
            $number = DocumentNumber::next('payment');
            $pid = (int) DB::table('payments')->insertGetId([
                'payment_number' => $number,
                'order_id' => (int) $order['id'],
                'customer_id' => (int) $order['customer_id'],
                'payment_attempt_id' => (int) $a['id'],
                'method' => ($acc['type'] ?? '') === 'bank' ? 'bank_transfer' : 'upi',
                'collected_via' => 'window',
                'payment_account_id' => $v['payment_account_id'],
                'amount' => $v['amount'],
                'reference' => $a['payer_reference'],
                'received_at' => $v['received_at'],
                'notes' => $v['notes'],
                'status' => 'received',
                'created_by' => $by,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('payment_attempts')->where('id', (int) $a['id'])->update(['payment_id' => $pid]);
            PaymentService::syncOrder((int) $order['id']);

            return ['payment_id' => $pid, 'payment_number' => $number];
        });
        NotificationService::markReadByKey('payment:submitted:' . $a['id']);

        return $res + ['difference' => (float) ((PaymentService::cents($v['amount']) - PaymentService::cents($a['amount'])) / 100)];
    }

    /** Fake / unmatched submission: needs a reason (the customer sees it on their order page). */
    public static function reject(int $attemptId, string $reason, ?int $by = null): void
    {
        $reason = trim(preg_replace('/\s+/', ' ', $reason) ?? '');
        if (mb_strlen($reason) < 3) {
            throw new PaymentException('Write why this payment is rejected — the customer sees it.');
        }
        $a = DB::selectOne('SELECT * FROM payment_attempts WHERE id = ?', [$attemptId]);
        if ($a === null || $a['status'] !== 'submitted') {
            throw new PaymentException('Only submitted payments can be rejected.');
        }
        $now = date('Y-m-d H:i:s');
        DB::transaction(static function () use ($a, $reason, $by, $now): void {
            $n = DB::update("UPDATE payment_attempts SET status = 'rejected', reject_reason = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ? WHERE id = ? AND status = 'submitted'",
                [mb_substr($reason, 0, 255), $by, $now, $now, (int) $a['id']]);
            if ($n !== 1) {
                throw new PaymentException('Payment ' . $a['attempt_number'] . ' was just reviewed by someone else — reload the page.');
            }
            if ($a['order_id']) {
                PaymentService::syncOrder((int) $a['order_id']);
            }
        });
        NotificationService::markReadByKey('payment:submitted:' . $a['id']);
    }

    /**
     * Other verified payments that carry the same UTR (duplicate guard — a warning, the admin decides).
     *
     * @return list<array{payment_number:string, order_number:string, order_id:int}>
     */
    public static function duplicateReference(?string $reference, int $exceptAttemptId = 0): array
    {
        $ref = trim((string) $reference);
        if ($ref === '') {
            return [];
        }

        return DB::select("SELECT p.payment_number, o.order_number, o.id AS order_id FROM payments p JOIN orders o ON o.id = p.order_id
            WHERE p.status = 'received' AND UPPER(p.reference) = ? AND COALESCE(p.payment_attempt_id, 0) <> ? ORDER BY p.id", [strtoupper($ref), $exceptAttemptId]);
    }

    /* ============================================================ record at the shop */

    /**
     * Cash / cheque / bank / UPI received outside the window.
     *
     * @param array<string,mixed> $in amount, method, payment_account_id, reference, received_at, notes
     */
    public static function record(int $orderId, array $in, ?int $by = null): array
    {
        $order = self::order($orderId);
        if ($order['order_status'] === 'rejected') {
            throw new PaymentException('Order ' . $order['order_number'] . ' was declined — don’t take money on it. Record a refund instead if anything was paid.');
        }
        $method = (string) ($in['method'] ?? '');
        $err = [];
        if (!isset(PaymentService::METHODS[$method])) {
            $err['method'][] = 'Choose how the money was paid.';
        }
        try {
            $v = self::validateMoney($in, $method !== 'cash');
        } catch (ValidationException $e) {
            $err += $e->errors();
        }
        $reference = blank_to_null(mb_substr(trim((string) ($in['reference'] ?? '')), 0, 80));
        if (in_array($method, ['cheque', 'bank_transfer'], true) && $reference === null) {
            $err['reference'][] = $method === 'cheque' ? 'Enter the cheque number.' : 'Enter the UTR / transfer reference.';
        }
        if ($err) {
            throw new ValidationException($err);
        }
        $now = date('Y-m-d H:i:s');

        return DB::transaction(static function () use ($order, $v, $method, $reference, $by, $now): array {
            $number = DocumentNumber::next('payment');
            $pid = (int) DB::table('payments')->insertGetId([
                'payment_number' => $number,
                'order_id' => (int) $order['id'],
                'customer_id' => (int) $order['customer_id'],
                'payment_attempt_id' => null,
                'method' => $method,
                'collected_via' => 'shop',
                'payment_account_id' => $v['payment_account_id'],
                'amount' => $v['amount'],
                'reference' => $reference,
                'received_at' => $v['received_at'],
                'notes' => $v['notes'],
                'status' => 'received',
                'created_by' => $by,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            PaymentService::syncOrder((int) $order['id']);

            return ['payment_id' => $pid, 'payment_number' => $number];
        });
    }

    /** Correct a mistake: the payment stays listed (struck through) and stops counting. */
    public static function voidPayment(int $paymentId, string $reason, ?int $by = null): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new PaymentException('Write why this payment is being voided.');
        }
        $p = DB::selectOne('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        if ($p === null || $p['status'] !== 'received') {
            throw new PaymentException('Only received payments can be voided.');
        }
        $now = date('Y-m-d H:i:s');
        DB::transaction(static function () use ($p, $reason, $now): void {
            $n = DB::update("UPDATE payments SET status = 'void', void_reason = ?, voided_at = ?, updated_at = ? WHERE id = ? AND status = 'received'",
                [mb_substr($reason, 0, 255), $now, $now, (int) $p['id']]);
            if ($n !== 1) {
                throw new PaymentException('That payment was just changed — reload the page.');
            }
            PaymentService::syncOrder((int) $p['order_id']);
        });
    }

    /* ============================================================ refunds */

    /**
     * Money the admin already sent back outside the system (overpayment, declined order, return).
     *
     * @param array<string,mixed> $in amount, method, payment_account_id, reference, reason, refunded_at, payment_id
     */
    public static function refund(int $orderId, array $in, ?int $by = null): array
    {
        $order = self::order($orderId);
        $err = [];
        $method = (string) ($in['method'] ?? '');
        if (!isset(PaymentService::METHODS[$method])) {
            $err['method'][] = 'Choose how the money was sent back.';
        }
        $amount = round((float) num_or_null($in['amount'] ?? 0), 2);
        $paid = PaymentService::cents($order['paid_amount']);
        if ($amount <= 0) {
            $err['amount'][] = 'Enter the refunded amount.';
        } elseif (PaymentService::cents($amount) > $paid) {
            $err['amount'][] = 'You can refund at most what was paid (' . Money::inr($paid / 100) . ').';
        }
        $acc = (int) ($in['payment_account_id'] ?? 0);
        if ($acc && Accounts::find($acc) === null) {
            $err['payment_account_id'][] = 'Pick the account the refund went from.';
        }
        $when = self::dateTime($in['refunded_at'] ?? null, 'refunded_at', $err);
        $reason = blank_to_null(mb_substr(trim((string) ($in['reason'] ?? '')), 0, 255));
        if ($reason === null) {
            $err['reason'][] = 'Write the reason, e.g. “Overpaid by ₹500” or “Order declined”.';
        }
        $paymentId = (int) ($in['payment_id'] ?? 0);
        if ($paymentId && !DB::table('payments')->where('id', $paymentId)->where('order_id', $orderId)->exists()) {
            $paymentId = 0;
        }
        if ($err) {
            throw new ValidationException($err);
        }
        $now = date('Y-m-d H:i:s');

        return DB::transaction(static function () use ($order, $amount, $method, $acc, $when, $reason, $paymentId, $in, $by, $now): array {
            $number = DocumentNumber::next('refund');
            $rid = (int) DB::table('payment_refunds')->insertGetId([
                'refund_number' => $number,
                'order_id' => (int) $order['id'],
                'payment_id' => $paymentId ?: null,
                'return_request_id' => !empty($in['return_request_id']) ? (int) $in['return_request_id'] : null,
                'amount' => $amount,
                'payment_account_id' => $acc ?: null,
                'method' => $method,
                'reference' => blank_to_null(mb_substr(trim((string) ($in['reference'] ?? '')), 0, 80)),
                'reason' => $reason,
                'status' => 'paid',
                'refunded_at' => $when,
                'created_by' => $by,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            PaymentService::syncOrder((int) $order['id']);

            return ['refund_id' => $rid, 'refund_number' => $number];
        });
    }

    public static function voidRefund(int $refundId, string $reason, ?int $by = null): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new PaymentException('Write why this refund entry is being voided.');
        }
        $r = DB::selectOne('SELECT * FROM payment_refunds WHERE id = ?', [$refundId]);
        if ($r === null || $r['status'] !== 'paid') {
            throw new PaymentException('Only recorded refunds can be voided.');
        }
        $now = date('Y-m-d H:i:s');
        DB::transaction(static function () use ($r, $reason, $now): void {
            DB::update("UPDATE payment_refunds SET status = 'void', void_reason = ?, updated_at = ? WHERE id = ? AND status = 'paid'", [mb_substr($reason, 0, 255), $now, (int) $r['id']]);
            PaymentService::syncOrder((int) $r['order_id']);
        });
    }

    /* ============================================================ helpers */

    private static function order(int $orderId): array
    {
        $o = DB::selectOne('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if ($o === null) {
            throw new PaymentException('Order not found.');
        }

        return $o;
    }

    /**
     * @param array<string,mixed> $in
     * @return array{amount:float, payment_account_id:?int, received_at:string, notes:?string}
     */
    private static function validateMoney(array $in, bool $accountRequired): array
    {
        $err = [];
        $raw = trim((string) ($in['amount'] ?? ''));
        $amount = round((float) num_or_null(str_replace([',', '₹', ' '], '', $raw)), 2);
        if ($raw === '' || $amount <= 0) {
            $err['amount'][] = 'Enter the amount actually received.';
        } elseif ($amount > 9999999999.99) {
            $err['amount'][] = 'That amount is too large.';
        }
        $accId = (int) ($in['payment_account_id'] ?? 0);
        $acc = $accId ? Accounts::find($accId) : null;
        if ($accId && $acc === null) {
            $err['payment_account_id'][] = 'Pick the account the money came into.';
        } elseif ($accountRequired && $acc === null) {
            $err['payment_account_id'][] = 'Pick the account the money came into (add one under Payments ▸ Receiving accounts).';
        }
        $when = self::dateTime($in['received_at'] ?? null, 'received_at', $err);
        if ($err) {
            throw new ValidationException($err);
        }

        return [
            'amount' => $amount,
            'payment_account_id' => $acc ? (int) $acc['id'] : null,
            'received_at' => $when,
            'notes' => blank_to_null(mb_substr(trim((string) ($in['notes'] ?? '')), 0, 1000)),
        ];
    }

    /** "2026-11-24T15:40" / "2026-11-24 15:40" (IST, not in the future) → Y-m-d H:i:s; blank = now. */
    private static function dateTime(mixed $raw, string $field, array &$err): string
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return date('Y-m-d H:i:s');
        }
        $ts = strtotime(str_replace('T', ' ', $s));
        if ($ts === false) {
            $err[$field][] = 'Enter a valid date and time.';

            return date('Y-m-d H:i:s');
        }
        if ($ts > time() + 300) {
            $err[$field][] = 'That date is in the future.';
        }

        return date('Y-m-d H:i:s', $ts);
    }
}
