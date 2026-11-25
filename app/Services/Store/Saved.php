<?php

namespace App\Services\Store;

use Vayu\Database\DB;

/**
 * Signed-in saved stones (docs/storefront.md §3.8). Only writer of customer_saved_items.
 * Guests keep their list in the browser (localStorage `ms.saved`, product slugs); at sign-in
 * that list is merged here once, and from then on the browser mirrors the account's list.
 * Saving is per product (variant_id 0) — the heart sits on product cards.
 */
final class Saved
{
    public const MAX = 60;

    /** Slugs, oldest first (same order as the guest list). @return list<string> */
    public static function slugs(int $customerId): array
    {
        return array_map(static fn($r) => (string) $r['slug'], DB::select(
            'SELECT p.slug FROM customer_saved_items s JOIN products p ON p.id = s.product_id WHERE s.customer_id = ? ORDER BY s.id',
            [$customerId]
        ));
    }

    /** Save or un-save one product. Returns [saved now?, error message or null]. @return array{0: bool, 1: ?string} */
    public static function toggle(int $customerId, string $slug, ?bool $want = null): array
    {
        $pid = self::productId($slug);
        if ($pid === null) {
            return [false, 'That stone isn’t in the shop any more.'];
        }
        $has = DB::table('customer_saved_items')->where('customer_id', $customerId)->where('product_id', $pid)->where('variant_id', 0)->exists();
        $want ??= !$has;
        if ($want && !$has) {
            if ((int) DB::table('customer_saved_items')->where('customer_id', $customerId)->count() >= self::MAX) {
                return [false, 'You can keep up to ' . self::MAX . ' saved stones. Remove a few first.'];
            }
            DB::table('customer_saved_items')->insert(['customer_id' => $customerId, 'product_id' => $pid, 'variant_id' => 0, 'created_at' => date('Y-m-d H:i:s')]);
        } elseif (!$want && $has) {
            DB::table('customer_saved_items')->where('customer_id', $customerId)->where('product_id', $pid)->where('variant_id', 0)->delete();
        }

        return [$want, null];
    }

    /** Add the guest's slugs (unknown / duplicate ones skipped, capped at MAX). Returns how many were added. @param list<string> $slugs */
    public static function merge(int $customerId, array $slugs): int
    {
        $added = 0;
        foreach (self::cleanSlugs($slugs) as $s) {
            $pid = self::productId($s);
            if ($pid === null || DB::table('customer_saved_items')->where('customer_id', $customerId)->where('product_id', $pid)->where('variant_id', 0)->exists()) {
                continue;
            }
            [$on, $err] = self::toggle($customerId, $s, true);
            if ($err !== null) {
                break; // full
            }
            $added += $on ? 1 : 0;
        }

        return $added;
    }

    /** Slugs from a form field / JSON list: valid shape, unique, at most MAX. @return list<string> */
    public static function cleanSlugs(mixed $raw): array
    {
        if (is_string($raw)) {
            $j = json_decode($raw, true);
            $raw = is_array($j) ? $j : explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = array_values(array_unique(array_filter(array_map('strval', array_filter($raw, 'is_scalar')),
            static fn($s) => preg_match('/^[a-z0-9][a-z0-9-]{0,190}$/', $s) === 1)));

        return array_slice($out, 0, self::MAX);
    }

    private static function productId(string $slug): ?int
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,190}$/', $slug) !== 1) {
            return null;
        }
        $id = DB::table('products')->where('slug', $slug)->value('id');

        return $id !== null ? (int) $id : null;
    }
}
