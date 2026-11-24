<?php

namespace App\Services\Store;

use Vayu\Database\DB;

/**
 * Data for the area & cost calculator (docs/storefront.md §3.9): every buyable stone with its
 * buyable options (price, unit, pack size, minimum, step). The maths (rooms → sq ft → boxes,
 * wastage) runs in the browser; "Add to bag" posts to /cart/add, which checks everything again.
 * Same rules as Purchasable, done in two batched queries instead of one per product.
 */
final class Calculator
{
    public const LIMIT = 120;

    /** @return list<array<string,mixed>> */
    public static function stones(): array
    {
        $rows = DB::select("SELECT p.id, p.slug, p.name, p.sku, pr.price, pr.compare_at_price, pr.unit, pr.pack_qty, pr.pack_unit, pr.min_order_qty, pr.order_step
                            FROM products p LEFT JOIN product_pricing pr ON pr.product_id = p.id
                            WHERE p.status = 'active' AND p.is_enquiry_only = 0 AND p.show_price = 1
                            ORDER BY p.is_featured DESC, p.is_bestseller DESC, p.name LIMIT " . self::LIMIT);
        if ($rows === []) {
            return [];
        }
        $ids = array_map(fn ($r) => (int) $r['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $variants = [];
        foreach (DB::select("SELECT id, product_id, title, sku, price, unit, pack_qty, pack_unit, min_order_qty FROM product_variants
                             WHERE product_id IN ($in) AND status = 'active' ORDER BY product_id, is_default DESC, sort, id", $ids) as $v) {
            $variants[(int) $v['product_id']][] = $v;
        }
        $cards = [];
        foreach (Catalog::cards($ids) as $c) {
            $cards[$c['id']] = $c;
        }

        $num = static fn($x): ?float => $x !== null && (float) $x > 0 ? (float) $x : null;
        $out = [];
        foreach ($rows as $p) {
            $pid = (int) $p['id'];
            $opts = [];
            $list = $variants[$pid] ?? [null];
            foreach ($list as $v) {
                $price = round((float) ($v['price'] ?? $p['price'] ?? 0), 2);
                if ($price <= 0) {
                    continue;
                }
                $opts[] = [
                    'id' => $v !== null ? (int) $v['id'] : 0,
                    'title' => $v['title'] ?? '',
                    'sku' => (string) (($v['sku'] ?? '') ?: $p['sku']),
                    'price' => $price,
                    'unit' => (string) ((($v['unit'] ?? '') ?: $p['unit']) ?: 'unit'),
                    'pack_qty' => $num($v['pack_qty'] ?? null) ?? $num($p['pack_qty']),
                    'pack_unit' => (($v['pack_unit'] ?? '') ?: $p['pack_unit']) ?: null,
                    'min_qty' => $num($v['min_order_qty'] ?? null) ?? $num($p['min_order_qty']),
                ];
            }
            if ($opts === []) {
                continue;
            }
            $c = $cards[$pid] ?? null;
            $out[] = [
                'id' => $pid,
                'name' => (string) $p['name'],
                'url' => Catalog::productUrl((string) $p['slug']),
                'image' => $c['image'] ?? null,
                'step' => $num($p['order_step']) ?? 1.0,
                'options' => $opts,
                'from' => min(array_column($opts, 'price')),
            ];
        }

        return $out;
    }
}
