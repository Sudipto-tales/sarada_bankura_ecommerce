<?php

namespace App\Services\Customer;

use Vayu\Database\DB;

/**
 * Order-derived customer stats: total_orders, total_spent, last_order_at, lifecycle.
 *
 * DORMANT until Stage 4 (orders). The columns ship at their defaults (0 / NULL / "new") and the
 * orders module calls recalculate() whenever an order is delivered, returned or refunded. Only
 * this class writes those four columns.
 */
final class CustomerStats
{
    public const VIP_SPEND = 200000;
    public const VIP_ORDERS = 3;

    /** Stage 4 flips this once the orders table exists. */
    public static function enabled(): bool
    {
        return false;
    }

    /** new: ≤ 1 order · returning: 2+ orders · vip: spend ≥ ₹2,00,000 and 3+ orders (same rule as the VIP segment). */
    public static function lifecycle(int $orders, float $spent): string
    {
        if ($orders >= self::VIP_ORDERS && $spent >= self::VIP_SPEND) {
            return 'vip';
        }

        return $orders >= 2 ? 'returning' : 'new';
    }

    /**
     * Recompute one customer's stats from their delivered orders, then refresh the dynamic
     * segments they could move in or out of. No-op while dormant.
     */
    public static function recalculate(int $customerId): void
    {
        if (!self::enabled()) {
            return;
        }
        // Stage 4: SELECT COUNT(*), SUM(grand_total), MAX(delivered_at) FROM orders
        //          WHERE customer_id = ? AND status = 'delivered' … then:
        $row = DB::selectOne('SELECT total_orders, total_spent FROM customers WHERE id = ?', [$customerId]);
        if ($row === null) {
            return;
        }
        DB::table('customers')->where('id', $customerId)->update([
            'lifecycle' => self::lifecycle((int) $row['total_orders'], (float) $row['total_spent']),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        SegmentMatcher::refreshForCustomer($customerId);
    }

    /** Average order value, 0 when there are no orders yet. */
    public static function aov(int $orders, float $spent): float
    {
        return $orders > 0 ? round($spent / $orders, 2) : 0.0;
    }
}
