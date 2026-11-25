<?php

namespace App\Services\Store;

use App\Services\Order\PaymentService;
use Vayu\Database\DB;

/**
 * The shopping bag (docs/storefront.md §3.5). Only writer of carts / cart_items.
 *
 * A cart is a DB row found by a random token in the long-lived cookie `ms_cart` (30 days), created
 * lazily on the first add — never on a page visit. Lines store product + variant + quantity only;
 * price_at_add exists solely to show "price changed since you added it". Every read re-prices from
 * the DB through Purchasable, so nothing the browser sends can change a price.
 */
final class Cart
{
    public const COOKIE_DAYS = 30;
    public const MAX_LINES = 60; // = OrderService::MAX_ITEMS (an order can't hold more)

    /** Token for this request (set after creating a cart, so the same request sees it). */
    private static ?string $token = null;
    /** Tests: don't send headers. */
    public static bool $sendCookie = true;

    /* ================================================================ token / row */

    public static function token(): ?string
    {
        if (self::$token !== null) {
            return self::$token === '' ? null : self::$token; // '' = let go of at sign-out
        }
        $t = (string) ($_COOKIE[Context::CART_COOKIE] ?? '');

        return preg_match('/^[A-Za-z0-9]{32,64}$/', $t) ? $t : null;
    }

    /** The visitor's active cart row, or null (no cookie, unknown token, converted/abandoned). */
    public static function current(): ?array
    {
        $t = self::token();

        return $t === null ? null : DB::selectOne("SELECT * FROM carts WHERE token = ? AND status = 'active'", [$t]);
    }

    /** Current cart, else a new one + cookie. */
    private static function currentOrCreate(?string $ip, ?string $ua): array
    {
        $cart = self::current();
        if ($cart !== null) {
            return $cart;
        }
        $now = date('Y-m-d H:i:s');
        $token = bin2hex(random_bytes(24)); // 48 chars
        $customer = Context::customer();
        $id = (int) DB::table('carts')->insertGetId([
            'token' => $token, 'customer_id' => $customer['customer_id'] ?? null, 'user_id' => $customer['id'] ?? null, 'status' => 'active',
            'last_seen_at' => $now, 'ip' => $ip !== null ? mb_substr($ip, 0, 45) : null, 'user_agent' => $ua !== null ? mb_substr($ua, 0, 255) : null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        self::setToken($token);

        return DB::selectOne('SELECT * FROM carts WHERE id = ?', [$id]);
    }

    private static function setToken(string $token): void
    {
        self::$token = $token;
        Context::reset();
        if (self::$sendCookie && !headers_sent()) {
            setcookie(Context::CART_COOKIE, $token, [
                'expires' => time() + self::COOKIE_DAYS * 86400, 'path' => '/',
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
    }

    private static function touch(int $cartId): void
    {
        $now = date('Y-m-d H:i:s');
        DB::update('UPDATE carts SET last_seen_at = ?, updated_at = ? WHERE id = ?', [$now, $now, $cartId]);
        Context::reset();
    }

    /* ================================================================ writes */

    /**
     * Add a product (same product + variant adds to the existing line's quantity).
     *
     * @return array{ok: bool, error?: string, count: int, qty?: float, name?: string, unit?: string}
     */
    public static function add(int $productId, int $variantId, mixed $qty, ?string $ip = null, ?string $ua = null): array
    {
        $info = Purchasable::check($productId, $variantId);
        if (!$info['ok']) {
            return ['ok' => false, 'error' => (string) $info['reason'], 'count' => self::count()];
        }
        $cart = self::current();
        $line = $cart !== null ? DB::selectOne('SELECT * FROM cart_items WHERE cart_id = ? AND product_id = ? AND variant_id = ?', [$cart['id'], $productId, $info['variant_id']]) : null;
        // Adding to an existing line: the minimum applies to the new total, not to this addition.
        [$q, $err] = Purchasable::quantity($info, $qty, $line === null);
        if ($err !== null) {
            return ['ok' => false, 'error' => $err, 'count' => self::count()];
        }
        if ($line === null && $cart !== null && self::count() >= self::MAX_LINES) {
            return ['ok' => false, 'error' => 'Your bag can hold up to ' . self::MAX_LINES . ' different stones.', 'count' => self::count()];
        }
        if ($line !== null) {
            [$q, $err] = Purchasable::quantity($info, (float) $line['quantity'] + $q);
            if ($err !== null) {
                return ['ok' => false, 'error' => $err, 'count' => self::count()];
            }
        }

        $cart ??= self::currentOrCreate($ip, $ua);
        $now = date('Y-m-d H:i:s');
        if ($line !== null) {
            DB::update('UPDATE cart_items SET quantity = ?, updated_at = ? WHERE id = ?', [$q, $now, $line['id']]);
        } else {
            DB::table('cart_items')->insert([
                'cart_id' => $cart['id'], 'product_id' => $productId, 'variant_id' => $info['variant_id'], 'quantity' => $q,
                'price_at_add' => $info['price'], 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        self::touch((int) $cart['id']);

        return ['ok' => true, 'count' => self::count(), 'qty' => $q, 'name' => $info['name'], 'unit' => $info['unit']];
    }

    /**
     * Set a line's quantity (0 removes it). The line must belong to the visitor's cart.
     *
     * @return array{ok: bool, error?: string, count: int}
     */
    public static function update(int $lineId, mixed $qty): array
    {
        $line = self::ownLine($lineId);
        if ($line === null) {
            return ['ok' => false, 'error' => 'That line is no longer in your bag.', 'count' => self::count()];
        }
        if (is_numeric($qty) && (float) $qty <= 0) {
            return self::remove($lineId);
        }
        $info = Purchasable::check((int) $line['product_id'], (int) $line['variant_id']);
        if (!$info['ok']) {
            return ['ok' => false, 'error' => (string) $info['reason'], 'count' => self::count()];
        }
        if ($qty === null || $qty === '') {
            return ['ok' => false, 'error' => 'Enter a quantity above 0.', 'count' => self::count()];
        }
        [$q, $err] = Purchasable::quantity($info, $qty);
        if ($err !== null) {
            return ['ok' => false, 'error' => $err, 'count' => self::count()];
        }
        DB::update('UPDATE cart_items SET quantity = ?, updated_at = ? WHERE id = ?', [$q, date('Y-m-d H:i:s'), $lineId]);
        self::touch((int) $line['cart_id']);

        return ['ok' => true, 'count' => self::count()];
    }

    /** @return array{ok: bool, count: int, slug?: string} */
    public static function remove(int $lineId): array
    {
        $line = self::ownLine($lineId);
        if ($line === null) {
            return ['ok' => true, 'count' => self::count()];
        }
        DB::delete('DELETE FROM cart_items WHERE id = ?', [$lineId]);
        self::touch((int) $line['cart_id']);
        $slug = DB::table('products')->where('id', (int) $line['product_id'])->value('slug');

        return ['ok' => true, 'count' => self::count(), 'slug' => $slug !== null ? (string) $slug : null];
    }

    /** Checkout (S4): the paid order now owns these lines. */
    public static function convert(int $cartId, int $orderId): void
    {
        DB::update("UPDATE carts SET status = 'converted', converted_order_id = ?, updated_at = ? WHERE id = ? AND status = 'active'", [$orderId, date('Y-m-d H:i:s'), $cartId]);
        Context::reset();
    }

    /**
     * Sign-in (S5): the guest bag and the account's open bag become one. The bag in this browser
     * (cookie) is kept and takes the account's lines (same product + variant → quantities added);
     * the account's other open bags are closed. No guest bag → the account's bag comes to this browser.
     * A cookie bag that already belongs to a different account is never taken over.
     */
    public static function adopt(int $userId, ?int $customerId): void
    {
        $guest = self::current();
        if ($guest !== null && $guest['user_id'] !== null && (int) $guest['user_id'] !== $userId) {
            $guest = null;
            self::forgetCookie();
        }
        $now = date('Y-m-d H:i:s');
        $others = DB::select("SELECT * FROM carts WHERE user_id = ? AND status = 'active'" . ($guest ? ' AND id <> ' . (int) $guest['id'] : '') . ' ORDER BY last_seen_at DESC, id DESC', [$userId]);
        if ($guest === null) {
            if ($others !== []) {
                self::setToken((string) $others[0]['token']);
                DB::update('UPDATE carts SET customer_id = ?, last_seen_at = ?, updated_at = ? WHERE id = ?', [$customerId, $now, $now, $others[0]['id']]);
                $guest = $others[0];
                $others = array_slice($others, 1);
            } else {
                return;
            }
        }
        $gid = (int) $guest['id'];
        DB::transaction(static function () use ($gid, $others, $userId, $customerId, $now): void {
            DB::update('UPDATE carts SET user_id = ?, customer_id = ?, last_seen_at = ?, updated_at = ? WHERE id = ?', [$userId, $customerId, $now, $now, $gid]);
            foreach ($others as $o) {
                foreach (DB::select('SELECT * FROM cart_items WHERE cart_id = ? ORDER BY id', [$o['id']]) as $l) {
                    $mine = DB::selectOne('SELECT id, quantity FROM cart_items WHERE cart_id = ? AND product_id = ? AND variant_id = ?', [$gid, $l['product_id'], $l['variant_id']]);
                    if ($mine !== null) {
                        DB::update('UPDATE cart_items SET quantity = ?, updated_at = ? WHERE id = ?', [min(9999999, round((float) $mine['quantity'] + (float) $l['quantity'], 3)), $now, $mine['id']]);
                    } elseif ((int) DB::table('cart_items')->where('cart_id', $gid)->count() < self::MAX_LINES) {
                        DB::table('cart_items')->insert([
                            'cart_id' => $gid, 'product_id' => $l['product_id'], 'variant_id' => $l['variant_id'], 'quantity' => $l['quantity'],
                            'price_at_add' => $l['price_at_add'], 'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }
                }
                DB::update("UPDATE carts SET status = 'abandoned', updated_at = ? WHERE id = ?", [$now, $o['id']]);
            }
        });
        Context::reset();
    }

    /** Sign-out: this browser lets go of the bag (it stays on the account for the next sign-in). */
    public static function forgetCookie(): void
    {
        self::$token = '';
        unset($_COOKIE[Context::CART_COOKIE]);
        Context::reset();
        if (self::$sendCookie && !headers_sent()) {
            setcookie(Context::CART_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
        }
    }

    private static function ownLine(int $lineId): ?array
    {
        $cart = self::current();

        return $cart === null || $lineId <= 0 ? null : DB::selectOne('SELECT * FROM cart_items WHERE id = ? AND cart_id = ?', [$lineId, $cart['id']]);
    }

    /* ================================================================ reads */

    public static function count(): int
    {
        $cart = self::current();

        return $cart === null ? 0 : (int) DB::table('cart_items')->where('cart_id', (int) $cart['id'])->count();
    }

    /**
     * Every line, re-priced from the DB. A line that can't be bought any more keeps `ok = false` +
     * `reason` and is left out of the totals; the bag page shows it greyed with Remove.
     *
     * @return list<array<string,mixed>>
     */
    public static function lines(): array
    {
        $cart = self::current();
        if ($cart === null) {
            return [];
        }
        $rows = DB::select('SELECT * FROM cart_items WHERE cart_id = ? ORDER BY id', [$cart['id']]);
        $cards = [];
        foreach (Catalog::cards(array_values(array_unique(array_map(fn ($r) => (int) $r['product_id'], $rows)))) as $c) {
            $cards[$c['id']] = $c;
        }
        $variantImages = self::variantImages(array_values(array_filter(array_map(fn ($r) => (int) $r['variant_id'], $rows))));

        $out = [];
        foreach ($rows as $r) {
            $info = Purchasable::check((int) $r['product_id'], (int) $r['variant_id']);
            $qty = round((float) $r['quantity'], 3);
            $reason = $info['reason'];
            if ($info['ok']) {
                [, $qErr] = Purchasable::quantity($info, $qty);
                $reason = $qErr; // e.g. the minimum went up after it was added
            }
            $ok = $reason === null;
            $line = $ok ? round($qty * $info['price'], 2) : 0.0;
            $card = $cards[(int) $r['product_id']] ?? null;
            $priceAtAdd = $r['price_at_add'] !== null ? (float) $r['price_at_add'] : null;
            $coverage = 0.0;
            if ($ok) {
                if (self::isSqFt($info['unit'])) {
                    $coverage = $qty;
                } elseif ($info['pack_qty'] && self::isSqFt((string) $info['pack_unit'])) {
                    $coverage = $qty * $info['pack_qty'];
                }
            }
            $out[] = $info + [
                'line_id' => (int) $r['id'],
                'qty' => $qty,
                'line_total' => $line,
                'savings' => $ok && $info['compare'] ? round($qty * ($info['compare'] - $info['price']), 2) : 0.0,
                'price_changed' => $ok && $priceAtAdd !== null && abs($priceAtAdd - $info['price']) >= 0.01 ? $priceAtAdd : null,
                'coverage' => round($coverage, 2),
                'image' => $variantImages[(int) $r['variant_id']] ?? ($card['image'] ?? null),
                'origin' => $card['origin'] ?? '',
                'url' => Catalog::productUrl($info['slug'] !== '' ? $info['slug'] : (string) ($card['slug'] ?? '')) . ($info['ok'] && $info['variant_id'] ? '?v=' . rawurlencode($info['sku']) : ''),
                'line_ok' => $ok,
                'line_reason' => $reason,
            ];
        }

        return $out;
    }

    /**
     * Totals preview (same maths as an order: subtotal − discount + GST; delivery included).
     * GST is added only when prices exclude tax AND a seller GSTIN is set (OrderService refuses GST
     * without one), so the bag never promises a total the order would not have.
     *
     * @param list<array<string,mixed>> $lines
     * @return array{subtotal: float, savings: float, tax_rate: float, tax: float, total: float, advance: float, coverage: float, count: int, blocked: int}
     */
    public static function totals(array $lines): array
    {
        $ok = array_filter($lines, fn ($l) => $l['line_ok']);
        $subtotal = round(array_sum(array_column($ok, 'line_total')), 2);
        $rate = self::taxRate();
        $tax = $rate > 0 ? round($subtotal * $rate / 100, 2) : 0.0;
        $total = round($subtotal + $tax, 2);

        return [
            'subtotal' => $subtotal,
            'savings' => round(array_sum(array_column($ok, 'savings')), 2),
            'tax_rate' => $rate,
            'tax' => $tax,
            'total' => $total,
            'advance' => $total > 0 ? PaymentService::advance($total) : 0.0,
            'coverage' => round(array_sum(array_column($ok, 'coverage')), 2),
            'count' => count($ok),
            'blocked' => count($lines) - count($ok),
        ];
    }

    /** GST % added on top of online prices (0 = none). Checkout (S4) uses the same rule. */
    public static function taxRate(): float
    {
        if ((bool) business('tax.prices_include_tax', false) || trim((string) business('gstin')) === '') {
            return 0.0;
        }

        return max(0.0, (float) business('tax.gst_rate', 18));
    }

    /** First image linked to each variant (falls back to the product cover). */
    private static function variantImages(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($variantIds), '?'));
        $out = [];
        foreach (DB::select("SELECT l.owner_id, m.path, m.thumb_path, m.alt_text FROM media_links l JOIN media m ON m.id = l.media_id
                             WHERE l.owner_type = 'variant' AND l.owner_id IN ($in) AND l.role IN ('cover','gallery','room') AND m.kind = 'image' AND m.disk = 'public'
                             ORDER BY CASE l.role WHEN 'cover' THEN 0 ELSE 1 END, l.sort, l.id", $variantIds) as $m) {
            $out[(int) $m['owner_id']] ??= Catalog::img($m);
        }

        return $out;
    }

    private static function isSqFt(string $unit): bool
    {
        return in_array(strtolower(str_replace([' ', '.', '_'], '', $unit)), ['sqft', 'sft', 'squarefeet', 'squarefoot'], true);
    }

    /** Tests. */
    public static function reset(): void
    {
        self::$token = null;
        Context::reset();
    }
}
