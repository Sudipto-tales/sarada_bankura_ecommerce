<?php

namespace App\Services\Order;

use Vayu\Database\DB;
use Vayu\Database\Schema\Schema;

/**
 * The ONLY writer of the payment cache on `orders` (paid_amount, due_amount, payment_status,
 * pending_verification_amount, refund_due) — see the single-writer table in docs/README.md.
 *
 * Recording, verification, refunds and voids live in App\Services\Payment\Ledger and the
 * payment window in App\Services\Payment\PayWindow; every one of them ends by calling syncOrder().
 *
 * Money rule (docs/payments.md "Rounding"): compare and add in integer paise, round once where a
 * value is created — the advance rounds UP, everything else half-up.
 */
final class PaymentService
{
    public const STATUSES = ['unpaid' => 'Unpaid', 'partially_paid' => 'Partly paid', 'paid' => 'Paid', 'overpaid' => 'Overpaid', 'refunded' => 'Refunded'];

    public const METHODS = ['upi' => 'UPI', 'bank_transfer' => 'Bank transfer', 'cash' => 'Cash', 'cheque' => 'Cheque'];

    /** Minimum advance at checkout (owner: 20 %). */
    public const ADVANCE_PERCENT = 20;

    private static ?bool $ready = null;

    /** Money → integer paise (exact comparisons). */
    public static function cents(float|int|string|null $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    /** 20 % advance, rounded UP to the paisa. */
    public static function advance(float|int|string $total): float
    {
        $c = self::cents($total);

        return intdiv($c * self::ADVANCE_PERCENT + 99, 100) / 100;
    }

    /**
     * What the customer can pay right now: outstanding minus submissions still awaiting
     * verification (so the same balance can't be paid twice while the first is unchecked).
     *
     * @param array<string,mixed> $order
     */
    public static function dueNow(array $order): float
    {
        if (($order['order_status'] ?? '') === 'rejected') {
            return 0.0;
        }

        return max(0, self::cents($order['due_amount'] ?? 0) - self::cents($order['pending_verification_amount'] ?? 0)) / 100;
    }

    /** True once the Stage 5 payment tables exist. */
    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('payments');
    }

    /** Recompute and store an order's payment cache. Returns the new values. */
    public static function syncOrder(int $orderId): array
    {
        $o = DB::selectOne('SELECT id, grand_total, order_status FROM orders WHERE id = ?', [$orderId]);
        if ($o === null) {
            return [];
        }
        $total = self::cents($o['grand_total']);
        $paid = 0;
        $pending = 0;
        $refunded = 0;
        if (self::ready()) {
            $paid = self::cents(DB::selectOne("SELECT COALESCE(SUM(amount), 0) AS s FROM payments WHERE order_id = ? AND status = 'received'", [$orderId])['s'] ?? 0);
            $refunded = self::cents(DB::selectOne("SELECT COALESCE(SUM(amount), 0) AS s FROM payment_refunds WHERE order_id = ? AND status = 'paid'", [$orderId])['s'] ?? 0);
            $pending = self::cents(DB::selectOne("SELECT COALESCE(SUM(amount), 0) AS s FROM payment_attempts WHERE order_id = ? AND status = 'submitted'", [$orderId])['s'] ?? 0);
        }
        $net = $paid - $refunded;
        // Goods taken back on a completed return are credited: the order owes that much less
        // (Stage 6, ReturnService) — so a fully paid order shows the credit as refund due.
        $credit = ReturnService::creditCents($orderId);
        // A rejected order owes nothing; anything kept is due back to the customer.
        $owes = $o['order_status'] === 'rejected' ? 0 : max(0, $total - $credit);
        $status = match (true) {
            $refunded > 0 && $net <= 0, $credit > 0 && $owes === 0 && $net <= 0 => 'refunded',
            $net <= 0 => 'unpaid',
            $net > $owes => 'overpaid',
            $net === $owes => 'paid',
            default => 'partially_paid',
        };
        $row = [
            'paid_amount' => $net / 100,
            'due_amount' => max(0, $owes - $net) / 100,
            'pending_verification_amount' => $o['order_status'] === 'rejected' ? 0 : $pending / 100,
            'refund_due' => max(0, $net - $owes) / 100,
            'payment_status' => $status,
        ];
        DB::table('orders')->where('id', $orderId)->update($row);

        return $row;
    }
}
