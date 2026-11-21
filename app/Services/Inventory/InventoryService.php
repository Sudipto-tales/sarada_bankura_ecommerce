<?php

namespace App\Services\Inventory;

use Vayu\Database\DB;

/**
 * The only code that changes physical stock (table inventory_stock).
 *
 * Every public write runs in one transaction: lock the stock row → change quantity →
 * write an inventory_movements row (before / after / unit snapshot) → re-evaluate alerts.
 * Stock is tracked per product; a product is "tracked" when it has an inventory_stock row.
 *
 * $meta keys accepted by the write methods (all optional):
 *   reference_no, notes, source (manual|import|opening|system), created_by (admin id),
 *   entered_unit (receive only: unit the quantity was typed in — converted to the stock unit)
 */
final class InventoryService
{
    public const TYPES = [
        'opening' => 'Opening stock',
        'stock_in' => 'Stock in',
        'adjust_in' => 'Adjustment in',
        'adjust_out' => 'Adjustment out',
        'import' => 'Bulk upload',
    ];
    public const SOURCES = ['manual', 'import', 'opening', 'system'];
    public const ADJUST_REASONS = [
        'count_correction' => 'Stock-count correction',
        'damaged' => 'Damaged / broken material',
        'sample_display' => 'Sample or display piece',
        'returned' => 'Returned by customer',
        'other' => 'Other',
    ];
    public const IMPORT_REASON = 'Bulk upload stock count';
    private const SCALE = 3;

    /* ----------------------------------------------------------------
     | Reads
     | ---------------------------------------------------------------- */

    /** @return array<string,mixed>|null */
    public static function stockFor(int $productId): ?array
    {
        $row = DB::table('inventory_stock')->where('product_id', $productId)->first();

        return $row ?: null;
    }

    public static function isTracked(int $productId): bool
    {
        return DB::table('inventory_stock')->where('product_id', $productId)->exists();
    }

    /**
     * Factor that converts a quantity in $from into $to for this product, using the
     * product's pack definition (product_pricing.unit = pack unit, e.g. "box";
     * pack_qty + pack_unit = its contents, e.g. 10.76 "sq ft").
     */
    public static function conversionFactor(int $productId, string $from, string $to): float
    {
        if (self::sameUnit($from, $to)) {
            return 1.0;
        }
        $p = DB::table('product_pricing')->where('product_id', $productId)->first(['unit', 'pack_qty', 'pack_unit']);
        $pack = $p ? (float) $p['pack_qty'] : 0.0;
        if ($p && $pack > 0 && $p['unit'] && $p['pack_unit']) {
            if (self::sameUnit($from, (string) $p['unit']) && self::sameUnit($to, (string) $p['pack_unit'])) {
                return $pack;            // 1 box = 10.76 sq ft
            }
            if (self::sameUnit($from, (string) $p['pack_unit']) && self::sameUnit($to, (string) $p['unit'])) {
                return 1 / $pack;        // 1 sq ft = 1/10.76 box
            }
        }

        throw new InventoryException("Cannot convert {$from} to {$to} for this product. Set its box contents (pack quantity) first, or enter the quantity in {$to}.");
    }

    /** Units the receive form may offer for a product: its stock unit, plus the pack/selling pair when convertible. */
    public static function enterableUnits(int $productId): array
    {
        $stock = self::stockFor($productId);
        if (!$stock) {
            return [];
        }
        $units = [(string) $stock['unit']];
        $p = DB::table('product_pricing')->where('product_id', $productId)->first(['unit', 'pack_qty', 'pack_unit']);
        if ($p && (float) $p['pack_qty'] > 0) {
            foreach ([$p['unit'], $p['pack_unit']] as $u) {
                if ($u && !in_array(strtolower((string) $u), array_map('strtolower', $units), true)) {
                    try {
                        self::conversionFactor($productId, (string) $u, (string) $stock['unit']);
                        $units[] = (string) $u;
                    } catch (InventoryException) {
                    }
                }
            }
        }

        return $units;
    }

    /* ----------------------------------------------------------------
     | Writes
     | ---------------------------------------------------------------- */

    /**
     * Start tracking a product with an opening quantity (0 allowed). Always writes an
     * "opening" movement so the history shows when tracking began.
     *
     * @param array<string,mixed> $meta
     * @return array<string,mixed> the new stock row
     */
    public static function startTracking(int $productId, string $unit, float $openingQty = 0.0, ?float $threshold = null, array $meta = []): array
    {
        $unit = self::validUnit($unit);
        $openingQty = self::validQty($openingQty, 'Opening quantity', allowZero: true);
        $threshold = $threshold === null ? null : self::validQty($threshold, 'Low-stock threshold', allowZero: true);

        return self::atomic(function () use ($productId, $unit, $openingQty, $threshold, $meta): array {
            if (!DB::table('products')->where('id', $productId)->exists()) {
                throw new InventoryException('Product not found.');
            }
            if (self::isTracked($productId)) {
                throw new InventoryException('Stock is already tracked for this product.');
            }
            $now = date('Y-m-d H:i:s');
            $stockId = DB::table('inventory_stock')->insertGetId([
                'product_id' => $productId,
                'variant_id' => null,
                'unit' => $unit,
                'quantity' => $openingQty,
                'low_stock_threshold' => $threshold,
                'status' => AlertService::HEALTHY,
                'last_movement_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            self::writeMovement($stockId, $productId, 'opening', 0.0, $openingQty, $meta + ['source' => 'opening', 'unit' => $unit], $now);
            $stock = self::stockFor($productId);
            AlertService::evaluate($stock);

            return self::stockFor($productId);
        });
    }

    /**
     * Record material received. $qty is in $meta['entered_unit'] (default: the stock unit)
     * and is converted to the stock unit; the conversion factor is stored on the movement.
     *
     * @param array<string,mixed> $meta
     * @return array<string,mixed> the movement row
     */
    public static function receive(int $productId, float $qty, array $meta = []): array
    {
        $qty = self::validQty($qty, 'Received quantity');

        return self::atomic(function () use ($productId, $qty, $meta): array {
            $stock = self::lock($productId);
            $enteredUnit = trim((string) ($meta['entered_unit'] ?? '')) ?: (string) $stock['unit'];
            $factor = self::conversionFactor($productId, $enteredUnit, (string) $stock['unit']);
            $change = round($qty * $factor, self::SCALE);
            if ($change <= 0) {
                throw new InventoryException('Received quantity is too small after converting to ' . $stock['unit'] . '.');
            }

            return self::apply($stock, 'stock_in', $change, $meta + [
                'source' => 'manual', 'entered_qty' => $qty, 'entered_unit' => $enteredUnit, 'conversion_factor' => $factor,
            ]);
        });
    }

    /**
     * Correct stock to a physical count. The difference becomes an adjust_in / adjust_out
     * movement. A reason is required; a count equal to the system quantity is rejected.
     *
     * @param array<string,mixed> $meta
     * @return array<string,mixed> the movement row
     */
    public static function adjustTo(int $productId, float $countedQty, string $reason, array $meta = []): array
    {
        $countedQty = self::validQty($countedQty, 'Physical count', allowZero: true);
        $reason = self::validReason($reason);

        return self::atomic(function () use ($productId, $countedQty, $reason, $meta): array {
            $stock = self::lock($productId);
            $change = round($countedQty - (float) $stock['quantity'], self::SCALE);
            if ($change == 0.0) {
                throw new InventoryException('The physical count matches the system stock — nothing to adjust.');
            }

            return self::apply($stock, $change > 0 ? 'adjust_in' : 'adjust_out', $change, $meta + ['source' => 'manual', 'reason' => $reason]);
        });
    }

    /**
     * Add (+) or remove (−) a quantity, e.g. "−5 damaged". Stock cannot go below zero.
     *
     * @param array<string,mixed> $meta
     * @return array<string,mixed> the movement row
     */
    public static function adjustBy(int $productId, float $delta, string $reason, array $meta = []): array
    {
        $delta = round($delta, self::SCALE);
        if (!is_finite($delta) || $delta == 0.0) {
            throw new InventoryException('Adjustment quantity must not be zero.');
        }
        $reason = self::validReason($reason);

        return self::atomic(function () use ($productId, $delta, $reason, $meta): array {
            $stock = self::lock($productId);
            if ((float) $stock['quantity'] + $delta < -0.0005) {
                throw new InventoryException('Cannot remove ' . self::qty(abs($delta)) . ' ' . $stock['unit'] . ' — only ' . self::qty((float) $stock['quantity']) . ' in stock.');
            }

            return self::apply($stock, $delta > 0 ? 'adjust_in' : 'adjust_out', $delta, $meta + ['source' => 'manual', 'reason' => $reason]);
        });
    }

    /**
     * Bulk-upload path. Not tracked → start tracking (opening movement, source import).
     * Tracked and the count differs → "import" movement with IMPORT_REASON.
     * $unit (if given) must match the existing stock unit; $threshold null = keep.
     *
     * @param array<string,mixed> $meta
     * @return array{action:string, before:?float, after:float, unit:string}
     *         action = started | changed | unchanged
     */
    public static function setFromImport(int $productId, float $qty, ?string $unit, ?float $threshold, array $meta = []): array
    {
        $qty = self::validQty($qty, 'stock_qty', allowZero: true);
        $meta += ['source' => 'import'];

        return self::atomic(function () use ($productId, $qty, $unit, $threshold, $meta): array {
            $stock = self::stockFor($productId);
            if (!$stock) {
                $unit = $unit ?: self::defaultUnitFor($productId);
                if (!$unit) {
                    throw new InventoryException('stock_unit is required: the product has no selling unit to use.');
                }
                $row = self::startTracking($productId, $unit, $qty, $threshold, $meta);

                return ['action' => 'started', 'before' => null, 'after' => (float) $row['quantity'], 'unit' => (string) $row['unit']];
            }

            if ($unit !== null && $unit !== '' && !self::sameUnit($unit, (string) $stock['unit'])) {
                throw new InventoryException('stock_unit "' . $unit . '" differs from the tracked stock unit "' . $stock['unit'] . '". The stock unit cannot be changed by upload.');
            }
            if ($threshold !== null) {
                self::setThreshold($productId, $threshold);
            }
            $stock = self::lock($productId);
            $before = (float) $stock['quantity'];
            $change = round($qty - $before, self::SCALE);
            if ($change == 0.0) {
                return ['action' => 'unchanged', 'before' => $before, 'after' => $before, 'unit' => (string) $stock['unit']];
            }
            self::apply($stock, 'import', $change, $meta + ['reason' => self::IMPORT_REASON]);

            return ['action' => 'changed', 'before' => $before, 'after' => $qty, 'unit' => (string) $stock['unit']];
        });
    }

    /** Set (or clear with null → use default) the low-stock threshold, then re-evaluate alerts. */
    public static function setThreshold(int $productId, ?float $threshold): array
    {
        $threshold = $threshold === null ? null : self::validQty($threshold, 'Low-stock threshold', allowZero: true);

        return self::atomic(function () use ($productId, $threshold): array {
            $stock = self::lock($productId);
            DB::table('inventory_stock')->where('id', (int) $stock['id'])->update(['low_stock_threshold' => $threshold, 'updated_at' => date('Y-m-d H:i:s')]);
            AlertService::evaluate(self::stockFor($productId));

            return self::stockFor($productId);
        });
    }

    /**
     * Stop tracking a product: removes its stock row and resolves its alerts.
     * Movement history is kept (it is keyed by product_id too).
     */
    public static function stopTracking(int $productId): void
    {
        self::atomic(function () use ($productId): void {
            $stock = self::lock($productId);
            AlertService::resolveAllFor($productId);
            DB::table('inventory_stock')->where('id', (int) $stock['id'])->delete();
        });
    }

    /* ----------------------------------------------------------------
     | Helpers
     | ---------------------------------------------------------------- */

    /** Run $fn in a transaction, or inside the caller's transaction if one is open (e.g. bulk import commit). */
    public static function atomic(\Closure $fn): mixed
    {
        if (DB::connection()->pdo()->inTransaction()) {
            return $fn();
        }

        return DB::transaction($fn);
    }

    /** Format a quantity without trailing zeros: 500, 10.76, 0.5. */
    public static function qty(float $value): string
    {
        $s = number_format(round($value, self::SCALE), self::SCALE, '.', ',');

        return rtrim(rtrim($s, '0'), '.');
    }

    /** The selling unit of a product (used as the stock unit when none is given). */
    public static function defaultUnitFor(int $productId): ?string
    {
        $p = DB::table('product_pricing')->where('product_id', $productId)->first(['unit', 'pack_unit', 'pack_qty']);
        if (!$p) {
            return null;
        }

        return ($p['unit'] ?: null);
    }

    /**
     * Lock the stock row for writing and return it fresh. The UPDATE takes SQLite's write
     * lock up front, so the read-modify-write below cannot interleave with another request.
     *
     * @return array<string,mixed>
     */
    private static function lock(int $productId): array
    {
        $n = DB::table('inventory_stock')->where('product_id', $productId)->update(['updated_at' => date('Y-m-d H:i:s')]);
        if ($n === 0) {
            throw new InventoryException('Stock is not tracked for this product. Start tracking it first.');
        }

        return self::stockFor($productId);
    }

    /**
     * @param array<string,mixed> $stock locked row
     * @param array<string,mixed> $meta
     * @return array<string,mixed> movement row
     */
    private static function apply(array $stock, string $type, float $change, array $meta): array
    {
        $now = date('Y-m-d H:i:s');
        $before = round((float) $stock['quantity'], self::SCALE);
        $after = round($before + $change, self::SCALE);
        if ($after < 0) {
            throw new InventoryException('Stock cannot go below zero (' . self::qty($before) . ' ' . $stock['unit'] . ' in stock).');
        }
        DB::table('inventory_stock')->where('id', (int) $stock['id'])->update(['quantity' => $after, 'last_movement_at' => $now, 'updated_at' => $now]);
        $movementId = self::writeMovement((int) $stock['id'], (int) $stock['product_id'], $type, $before, $after, ['unit' => $stock['unit']] + $meta, $now);
        AlertService::evaluate(self::stockFor((int) $stock['product_id']));

        return DB::table('inventory_movements')->where('id', $movementId)->first();
    }

    /** @param array<string,mixed> $meta */
    private static function writeMovement(int $stockId, int $productId, string $type, float $before, float $after, array $meta, string $now): int
    {
        $source = in_array($meta['source'] ?? 'manual', self::SOURCES, true) ? $meta['source'] : 'manual';
        $text = static fn(string $k, int $max) => isset($meta[$k]) && trim((string) $meta[$k]) !== '' ? mb_substr(trim((string) $meta[$k]), 0, $max) : null;

        return DB::table('inventory_movements')->insertGetId([
            'stock_id' => $stockId,
            'product_id' => $productId,
            'variant_id' => null,
            'type' => $type,
            'qty_change' => round($after - $before, self::SCALE),
            'qty_before' => round($before, self::SCALE),
            'qty_after' => round($after, self::SCALE),
            'entered_qty' => isset($meta['entered_qty']) ? round((float) $meta['entered_qty'], self::SCALE) : null,
            'entered_unit' => $text('entered_unit', 32),
            'conversion_factor' => isset($meta['conversion_factor']) ? round((float) $meta['conversion_factor'], 6) : null,
            'reason' => $text('reason', 255),
            'reference_no' => $text('reference_no', 64),
            'notes' => $text('notes', 2000),
            'source' => $source,
            'unit' => $text('unit', 32),
            'created_by' => isset($meta['created_by']) ? (int) $meta['created_by'] : null,
            'created_at' => $now,
        ]);
    }

    private static function validQty(float $qty, string $label, bool $allowZero = false): float
    {
        if (!is_finite($qty)) {
            throw new InventoryException($label . ' must be a number.');
        }
        $qty = round($qty, self::SCALE);
        if ($qty < 0 || (!$allowZero && $qty == 0.0)) {
            throw new InventoryException($label . ($allowZero ? ' cannot be negative.' : ' must be greater than zero.'));
        }
        if ($qty > 99999999999) {
            throw new InventoryException($label . ' is too large.');
        }

        return $qty;
    }

    private static function validReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InventoryException('A reason is required for stock adjustments.');
        }

        return self::ADJUST_REASONS[$reason] ?? mb_substr($reason, 0, 255);
    }

    /** Return the canonical unit symbol from the units table. */
    private static function validUnit(string $unit): string
    {
        $unit = trim($unit);
        foreach (DB::table('units')->pluck('symbol') as $symbol) {
            if (self::sameUnit($unit, (string) $symbol)) {
                return (string) $symbol;
            }
        }

        throw new InventoryException('Unknown stock unit "' . $unit . '". Add it on the Units page first.');
    }

    private static function sameUnit(string $a, string $b): bool
    {
        return strtolower(trim($a)) === strtolower(trim($b));
    }
}
