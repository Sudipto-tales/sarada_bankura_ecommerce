<?php

namespace App\Services\Store;

use App\Services\AdminAccess\Guard;
use Vayu\Database\DB;

/**
 * Per-request storefront state (docs/storefront.md): is the store open, is an admin
 * previewing it, who is the signed-in customer, how many lines are in the bag.
 * Filled by the StoreOpen / StoreCustomer middleware; read by the layout and controllers.
 */
final class Context
{
    public const CART_COOKIE = 'ms_cart';

    private static ?array $customer = null;
    private static ?int $cartCount = null;

    /** STOREFRONT_ENABLED. */
    public static function isOpen(): bool
    {
        return (bool) config('store.enabled', false);
    }

    /** The public can't see the store yet, but a logged-in admin can (preview bar shown). */
    public static function previewing(): bool
    {
        return !self::isOpen() && Guard::user() !== null;
    }

    /** Can this visitor see the real storefront? */
    public static function visible(): bool
    {
        return self::isOpen() || Guard::user() !== null;
    }

    public static function setCustomer(?array $user): void
    {
        self::$customer = $user;
    }

    /** Signed-in storefront customer (users row) or null. Sign-in itself arrives in S5. */
    public static function customer(): ?array
    {
        return self::$customer;
    }

    /** Lines in the visitor's active cart (cookie ms_cart); 0 when there is none. */
    public static function cartCount(): int
    {
        if (self::$cartCount !== null) {
            return self::$cartCount;
        }
        $token = Cart::token();
        if ($token === null) {
            return self::$cartCount = 0;
        }
        $row = DB::select("SELECT COUNT(*) AS n FROM cart_items i JOIN carts c ON c.id = i.cart_id
                           WHERE c.token = ? AND c.status = 'active'", [$token]);

        return self::$cartCount = (int) ($row[0]['n'] ?? 0);
    }

    /** Tests. */
    public static function reset(): void
    {
        self::$customer = null;
        self::$cartCount = null;
    }
}
