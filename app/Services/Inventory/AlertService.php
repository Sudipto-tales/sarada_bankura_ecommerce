<?php

namespace App\Services\Inventory;

use App\Services\NotificationService;
use Vayu\Database\DB;

/**
 * Turns stock levels into alert state + notifications. Called inline after every stock
 * change (inside the same transaction) and by the scheduled `inventory:check` safety net.
 *
 *   healthy → low      open "low" alert, notify
 *   low     → out      resolve "low", open "out", notify
 *   out     → low      resolve "out", open "low", notify
 *   low/out → healthy  resolve alert, notify "back in stock"
 *   same condition     nothing (no repeated notifications)
 */
final class AlertService
{
    public const HEALTHY = 'healthy';
    public const LOW = 'low';
    public const OUT = 'out';

    /** Effective threshold for a stock row (row value, else the settings default). */
    public static function threshold(array $stock): float
    {
        return $stock['low_stock_threshold'] !== null && $stock['low_stock_threshold'] !== ''
            ? (float) $stock['low_stock_threshold']
            : InventorySettings::defaultThreshold();
    }

    public static function statusFor(float $qty, float $threshold): string
    {
        if (round($qty, 3) <= 0) {
            return self::OUT;
        }

        return round($qty, 3) <= round($threshold, 3) ? self::LOW : self::HEALTHY;
    }

    /**
     * Re-evaluate one stock row. Returns what happened:
     * ['status' => healthy|low|out, 'opened' => low|out|null, 'resolved' => list<low|out>, 'notified' => list<int>]
     *
     * @param array<string,mixed> $stock inventory_stock row
     * @return array{status:string, opened:?string, resolved:list<string>, notified:list<int>}
     */
    public static function evaluate(array $stock): array
    {
        $now = date('Y-m-d H:i:s');
        $qty = (float) $stock['quantity'];
        $threshold = self::threshold($stock);
        $status = self::statusFor($qty, $threshold);
        $result = ['status' => $status, 'opened' => null, 'resolved' => [], 'notified' => []];

        if ($status !== $stock['status']) {
            DB::table('inventory_stock')->where('id', (int) $stock['id'])->update(['status' => $status]);
        }

        // When inventory is switched off we keep the cached status right but raise nothing.
        if (!InventorySettings::enabled()) {
            return $result;
        }

        $active = DB::table('inventory_alerts')->where('stock_id', (int) $stock['id'])->where('status', 'active')->get();
        $keep = null;
        foreach ($active as $alert) {
            if ($status !== self::HEALTHY && $alert['type'] === $status && $keep === null) {
                $keep = $alert; // same condition still active → nothing new
                continue;
            }
            DB::table('inventory_alerts')->where('id', (int) $alert['id'])->update(['status' => 'resolved', 'resolved_at' => $now]);
            // The condition it described is over: clear its unread notification from the panel.
            NotificationService::markReadByKey(self::dedupeKey((int) $stock['product_id'], (string) $alert['type'], (int) $alert['id']));
            $result['resolved'][] = (string) $alert['type'];
        }

        $product = DB::table('products')->where('id', (int) $stock['product_id'])->first(['id', 'name', 'sku']);
        $name = $product['name'] ?? ('Product #' . $stock['product_id']);
        $qtyText = InventoryService::qty($qty) . ' ' . $stock['unit'];
        $url = 'admin/inventory/stock?product=' . (int) $stock['product_id'];
        $data = ['product_id' => (int) $stock['product_id'], 'sku' => $product['sku'] ?? null, 'quantity' => round($qty, 3), 'unit' => $stock['unit'], 'threshold' => round($threshold, 3)];

        if ($status !== self::HEALTHY && $keep === null) {
            $alertId = DB::table('inventory_alerts')->insertGetId([
                'stock_id' => (int) $stock['id'],
                'product_id' => (int) $stock['product_id'],
                'variant_id' => null,
                'type' => $status,
                'status' => 'active',
                'qty_at_trigger' => round($qty, 3),
                'threshold_at_trigger' => round($threshold, 3),
                'triggered_at' => $now,
                'resolved_at' => null,
            ]);
            $result['opened'] = $status;

            $isOut = $status === self::OUT;
            if (InventorySettings::bool($isOut ? 'notify_out' : 'notify_low')) {
                $nid = NotificationService::notify(
                    $isOut ? 'stock.out' : 'stock.low',
                    $isOut ? $name . ' is out of stock' : $name . ' is running low',
                    $qtyText . ' · threshold ' . InventoryService::qty($threshold),
                    $url,
                    $isOut ? 'danger' : 'warning',
                    $data + ['alert_id' => $alertId],
                    self::dedupeKey((int) $stock['product_id'], $status, $alertId),
                    $isOut ? 'package-x' : 'triangle-alert',
                );
                if ($nid) {
                    $result['notified'][] = $nid;
                }
            }
        }

        if ($status === self::HEALTHY && $result['resolved'] && InventorySettings::bool('notify_recovered')) {
            $nid = NotificationService::notify(
                'stock.recovered',
                $name . ' is back in stock',
                $qtyText . ' available',
                $url,
                'success',
                $data,
                null,
                'package-check',
            );
            if ($nid) {
                $result['notified'][] = $nid;
            }
        }

        return $result;
    }

    /**
     * Re-evaluate every tracked product (used by the scheduled check).
     *
     * @return array{checked:int, opened:int, resolved:int, notified:int}
     */
    public static function evaluateAll(): array
    {
        $summary = ['checked' => 0, 'opened' => 0, 'resolved' => 0, 'notified' => 0];
        foreach (DB::table('inventory_stock')->orderBy('id')->get() as $stock) {
            $r = InventoryService::atomic(static fn() => self::evaluate($stock));
            $summary['checked']++;
            $summary['opened'] += $r['opened'] ? 1 : 0;
            $summary['resolved'] += count($r['resolved']);
            $summary['notified'] += count($r['notified']);
        }

        return $summary;
    }

    /** Resolve every active alert of a product without notifying (used when tracking stops). */
    public static function resolveAllFor(int $productId): int
    {
        $n = 0;
        foreach (DB::table('inventory_alerts')->where('product_id', $productId)->where('status', 'active')->get() as $alert) {
            DB::table('inventory_alerts')->where('id', (int) $alert['id'])->update(['status' => 'resolved', 'resolved_at' => date('Y-m-d H:i:s')]);
            NotificationService::markReadByKey(self::dedupeKey($productId, (string) $alert['type'], (int) $alert['id']));
            $n++;
        }

        return $n;
    }

    private static function dedupeKey(int $productId, string $type, int $alertId): string
    {
        return 'stock:' . $productId . ':' . $type . ':' . $alertId;
    }
}
