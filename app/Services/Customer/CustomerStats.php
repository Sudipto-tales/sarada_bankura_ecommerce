<?php

namespace App\Services\Customer;

use Vayu\Database\DB;

/**
 * Order-derived customer stats: total_orders, total_spent, last_order_at, lifecycle.
 *
 * Live since Stage 4. OrderService calls recalculate() when an order is created, rejected or
 * delivered. Only this class writes those four columns:
 *
 *   total_orders   delivered orders
 *   total_spent    Σ grand_total of delivered orders
 *   last_order_at  when the latest non-rejected order was placed (drives "Inactive 90 days")
 *   lifecycle      new / returning / vip from the two totals
 */
final class CustomerStats
{
    public const VIP_SPEND = 200000;
    public const VIP_ORDERS = 3;

    public static function enabled(): bool
    {
        return true;
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
        if (!DB::table('customers')->where('id', $customerId)->exists()) {
            return;
        }
        $d = DB::selectOne("SELECT COUNT(*) AS n, COALESCE(SUM(grand_total), 0) AS spent FROM orders WHERE customer_id = ? AND order_status = 'delivered'", [$customerId]);
        $last = DB::selectOne("SELECT MAX(created_at) AS at FROM orders WHERE customer_id = ? AND order_status <> 'rejected'", [$customerId]);
        $orders = (int) ($d['n'] ?? 0);
        $spent = round((float) ($d['spent'] ?? 0), 2);
        DB::table('customers')->where('id', $customerId)->update([
            'total_orders' => $orders,
            'total_spent' => $spent,
            'last_order_at' => $last['at'] ?? null,
            'lifecycle' => self::lifecycle($orders, $spent),
        ]);
        SegmentMatcher::refreshForCustomer($customerId);
    }

    /** Average order value, 0 when there are no orders yet. */
    public static function aov(int $orders, float $spent): float
    {
        return $orders > 0 ? round($spent / $orders, 2) : 0.0;
    }
}
