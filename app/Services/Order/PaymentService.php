<?php

namespace App\Services\Order;

use Vayu\Database\DB;
use Vayu\Database\Schema\Schema;

/**
 * The ONLY writer of the payment cache on `orders` (paid_amount, due_amount, payment_status,
 * pending_verification_amount, refund_due) — see the single-writer table in docs/roadmap.md.
 *
 * Stage 4 ships the sync only: until Stage 5 creates `payments` / `payment_attempts` /
 * `payment_refunds`, every order is unpaid with the full total due. Stage 5 adds recording,
 * verification and refunds, and they all end by calling syncOrder().
 */
final class PaymentService
{
    public const STATUSES = ['unpaid' => 'Unpaid', 'partially_paid' => 'Partly paid', 'paid' => 'Paid', 'overpaid' => 'Overpaid', 'refunded' => 'Refunded'];

    private static ?bool $ready = null;

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
        $total = round((float) $o['grand_total'], 2);
        $paid = 0.0;
        $pending = 0.0;
        $refunded = 0.0;
        if (self::ready()) {
            $paid = (float) (DB::selectOne("SELECT COALESCE(SUM(amount), 0) AS s FROM payments WHERE order_id = ? AND status = 'received'", [$orderId])['s'] ?? 0);
            if (Schema::hasTable('payment_refunds')) {
                $refunded = (float) (DB::selectOne("SELECT COALESCE(SUM(amount), 0) AS s FROM payment_refunds WHERE order_id = ? AND status = 'paid'", [$orderId])['s'] ?? 0);
            }
            if (Schema::hasTable('payment_attempts')) {
                $pending = (float) (DB::selectOne("SELECT COALESCE(SUM(amount), 0) AS s FROM payment_attempts WHERE order_id = ? AND status = 'submitted'", [$orderId])['s'] ?? 0);
            }
        }
        $net = round($paid - $refunded, 2);
        // A rejected order owes nothing; anything kept is due back to the customer.
        $owes = $o['order_status'] === 'rejected' ? 0.0 : $total;
        $due = round(max(0.0, $owes - $net), 2);
        $refundDue = round(max(0.0, $net - $owes), 2);
        $status = match (true) {
            $refunded > 0 && $net <= 0 => 'refunded',
            $net <= 0 => 'unpaid',
            $net > $owes && $owes > 0 => 'overpaid',
            $net >= $owes && $owes > 0 => 'paid',
            $owes <= 0 => 'overpaid',
            default => 'partially_paid',
        };
        $row = [
            'paid_amount' => $net,
            'due_amount' => $due,
            'pending_verification_amount' => round($pending, 2),
            'refund_due' => $refundDue,
            'payment_status' => $status,
        ];
        DB::table('orders')->where('id', $orderId)->update($row);

        return $row;
    }
}
