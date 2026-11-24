<?php

namespace App\Services\Store;

use Vayu\Database\DB;

/**
 * One rule (docs/storefront.md §3.2): can this product / variant be bought online, at what price,
 * and in which quantities. Everything comes from the DB, never from the request.
 *
 *   buyable = products.status = 'active' AND is_enquiry_only = 0 AND show_price = 1 AND price > 0
 *             AND (no active variants OR an active variant of this product is chosen)
 *
 * Price = variant price ?? product price (the same rule as OrderService::cleanItems, which re-prices
 * the order in S4). Stock never blocks (owner rule §6 Q1).
 */
final class Purchasable
{
    public const MAX_QTY = 9999999;

    /**
     * @return array{ok: bool, reason: ?string, product_id: int, variant_id: int, name: string, slug: string,
     *               variant_title: ?string, sku: string, price: float, compare: ?float, unit: string,
     *               pack_qty: ?float, pack_unit: ?string, min_qty: ?float, step: float, stock: ?string}
     */
    public static function check(int $productId, int $variantId = 0): array
    {
        $no = static fn(string $reason, array $base = []): array => $base + [
            'ok' => false, 'reason' => $reason, 'product_id' => $productId, 'variant_id' => $variantId, 'name' => '', 'slug' => '',
            'variant_title' => null, 'sku' => '', 'price' => 0.0, 'compare' => null, 'unit' => '', 'pack_qty' => null, 'pack_unit' => null,
            'min_qty' => null, 'step' => 1.0, 'stock' => null,
        ];

        $p = $productId > 0 ? DB::selectOne(
            'SELECT p.id, p.slug, p.name, p.sku, p.status, p.is_enquiry_only, p.show_price,
                    pr.price, pr.compare_at_price, pr.unit, pr.pack_qty, pr.pack_unit, pr.min_order_qty, pr.order_step, st.status AS stock
             FROM products p LEFT JOIN product_pricing pr ON pr.product_id = p.id LEFT JOIN inventory_stock st ON st.product_id = p.id
             WHERE p.id = ?',
            [$productId]
        ) : null;
        if ($p === null || !in_array($p['status'], ['active', 'coming_soon'], true)) {
            return $no('This stone is no longer available.');
        }
        $base = ['name' => (string) $p['name'], 'slug' => (string) $p['slug'], 'sku' => (string) $p['sku'], 'stock' => $p['stock'] !== null ? (string) $p['stock'] : null];
        if ($p['status'] === 'coming_soon') {
            return $no('Launching soon — not on sale yet.', $base);
        }
        if ((int) $p['is_enquiry_only'] === 1 || (int) $p['show_price'] !== 1) {
            return $no('Price on request — send us an enquiry.', $base);
        }

        $v = null;
        if ($variantId > 0) {
            $v = DB::selectOne("SELECT id, title, sku, price, compare_at_price, unit, pack_qty, pack_unit, min_order_qty, status
                                FROM product_variants WHERE id = ? AND product_id = ?", [$variantId, $productId]);
            if ($v === null || $v['status'] !== 'active') {
                return $no('This option is no longer offered — pick another.', $base);
            }
        } elseif (DB::table('product_variants')->where('product_id', $productId)->where('status', 'active')->exists()) {
            return $no('Pick a size / finish first.', $base);
        }

        $price = round((float) ($v['price'] ?? $p['price'] ?? 0), 2);
        if ($price <= 0) {
            return $no('Price on request — send us an enquiry.', $base);
        }
        $compareRaw = $v !== null ? $v['compare_at_price'] : $p['compare_at_price'];
        $compare = $compareRaw !== null && (float) $compareRaw > $price ? round((float) $compareRaw, 2) : null;
        $num = static fn($x): ?float => $x !== null && (float) $x > 0 ? (float) $x : null;
        $step = $num($p['order_step']) ?? 1.0;

        return [
            'ok' => true,
            'reason' => null,
            'product_id' => $productId,
            'variant_id' => $v !== null ? (int) $v['id'] : 0,
            'name' => (string) $p['name'],
            'slug' => (string) $p['slug'],
            'variant_title' => $v['title'] ?? null,
            'sku' => (string) (($v['sku'] ?? '') ?: $p['sku']),
            'price' => $price,
            'compare' => $compare,
            'unit' => (string) ((($v['unit'] ?? '') ?: $p['unit']) ?: 'unit'),
            'pack_qty' => $num($v['pack_qty'] ?? null) ?? $num($p['pack_qty']),
            'pack_unit' => (($v['pack_unit'] ?? '') ?: $p['pack_unit']) ?: null,
            'min_qty' => $num($v['min_order_qty'] ?? null) ?? $num($p['min_order_qty']),
            'step' => $step,
            'stock' => $base['stock'],
        ];
    }

    /** The quantity a plain "Add to bag" (no qty field) adds: max(minimum, one step). */
    public static function defaultQty(array $info): float
    {
        return self::roundUp(max((float) ($info['min_qty'] ?? 0), (float) $info['step']), (float) $info['step']);
    }

    /**
     * Clean a requested quantity: a number above 0, rounded UP to the order step, at least the
     * minimum (unless $checkMin = false: an addition to an existing line), at most MAX_QTY.
     *
     * @return array{0: ?float, 1: ?string} [qty, error]
     */
    public static function quantity(array $info, mixed $raw, bool $checkMin = true): array
    {
        $raw = is_string($raw) ? trim(str_replace(',', '', $raw)) : $raw;
        if ($raw === null || $raw === '') {
            return [self::defaultQty($info), null];
        }
        if (!is_numeric($raw) || !is_finite((float) $raw) || (float) $raw <= 0) {
            return [null, 'Enter a quantity above 0.'];
        }
        $qty = self::roundUp((float) $raw, (float) $info['step']);
        if ($qty > self::MAX_QTY) {
            return [null, 'That quantity is too large — call us for bulk orders.'];
        }
        $min = (float) ($info['min_qty'] ?? 0);
        if ($checkMin && $min > 0 && $qty + 1e-9 < $min) {
            return [null, 'The minimum for ' . $info['name'] . ' is ' . self::fmt($min) . ' ' . $info['unit'] . '.'];
        }

        return [$qty, null];
    }

    /** 12.5 → 12.5, 3.000 → 3 (quantities in messages and inputs). */
    public static function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    }

    private static function roundUp(float $qty, float $step): float
    {
        if ($step <= 0) {
            return round($qty, 3);
        }
        $n = ceil(round($qty / $step, 6));

        return round(max(1, $n) * $step, 3);
    }
}
