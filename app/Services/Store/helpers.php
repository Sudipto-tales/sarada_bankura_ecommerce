<?php

/**
 * Storefront view helpers (docs/storefront.md §2). Loaded by App\Services\Store\View.
 * Everything that prints user/DB text escapes it with e().
 */

use App\Services\Order\Money;
use App\Services\Store\View;

if (!function_exists('store_url')) {
    function store_url(string $path = '/'): string
    {
        return base_url($path === '/' ? '' : $path) ?: '/';
    }

    /** /signin with a same-site "come back here" path (CustomerAuth::safeNext). */
    function store_signin_url(?string $next = null, string $tab = ''): string
    {
        $n = \App\Services\Store\CustomerAuth::safeNext($next, '');

        return store_url('/signin') . ($n !== '' ? '?next=' . rawurlencode($n) : '') . ($tab !== '' ? '#' . $tab : '');
    }

    /** A file under public/assets/. */
    function store_asset(string $path): string
    {
        $file = app()->publicPath('assets/' . ltrim($path, '/'));
        $v = is_file($file) ? '?v=' . filemtime($file) : '';

        return base_url('assets/' . ltrim($path, '/')) . $v;
    }

    /** ₹1,23,456 (no paise when the amount is whole). */
    function store_inr(float|int|string|null $amount): string
    {
        $v = (float) $amount;

        return Money::inr($v, abs($v - round($v)) < 0.005 ? 0 : 2);
    }

    function store_icon(string $name, string $class = ''): string
    {
        return '<i data-lucide="' . e($name) . '"' . ($class !== '' ? ' class="' . e($class) . '"' : '') . ' aria-hidden="true"></i>';
    }

    function store_stars(float $rating): string
    {
        $n = max(0, min(5, (int) round($rating)));

        return str_repeat('★', $n) . str_repeat('☆', 5 - $n);
    }

    /**
     * <img> for a Catalog image (['src' => thumb, 'full' => original, 'alt' => …]), or a stone
     * placeholder <div> when there is none. $wide = use the full-size file (hero, spotlight).
     *
     * @param array{src:string, full:string, alt?:string}|null $img
     */
    function store_img(?array $img, string $alt, string $class, bool $wide = false, bool $lazy = true, bool $dark = false): string
    {
        if ($img === null) {
            return '<div class="stone-ph' . ($dark ? ' dark' : '') . ' ' . e($class) . '" role="img" aria-label="' . e($alt) . '"></div>';
        }
        $src = base_url($wide ? $img['full'] : $img['src']);
        $srcset = !$wide && $img['full'] !== $img['src']
            ? ' srcset="' . e(base_url($img['src'])) . ' 480w, ' . e(base_url($img['full'])) . ' 1600w" sizes="(min-width:1536px) 16vw, (min-width:1280px) 25vw, (min-width:768px) 33vw, 50vw"'
            : '';
        $altText = ($img['alt'] ?? '') !== '' ? (string) $img['alt'] : $alt;

        return '<img src="' . e($src) . '"' . $srcset . ' alt="' . e($altText) . '" class="' . e($class) . '"'
            . ($lazy ? ' loading="lazy" decoding="async"' : ' fetchpriority="high"') . '>';
    }

    /** One product card (resources/views/store/partials/product-card.php). @param array<string,mixed> $c */
    function store_card(array $c): string
    {
        ob_start();
        include app()->basePath('resources/views/store/partials/product-card.php');

        return (string) ob_get_clean();
    }

    /** Price line for a card: "from ₹590 / sq ft ₹790 25% off", or the reason there is no price. @param array<string,mixed> $c */
    function store_price_text(array $c): string
    {
        if ($c['status'] === 'coming_soon') {
            return $c['show_price'] && $c['price'] > 0 ? 'Coming soon · from ' . store_inr($c['price']) . ' / ' . $c['unit'] : 'Coming soon';
        }
        if (!$c['show_price'] || $c['price'] <= 0) {
            return 'Price on request';
        }

        return ($c['from'] ? 'from ' : '') . store_inr($c['price']) . ($c['unit'] !== '' ? ' / ' . $c['unit'] : '');
    }

    /** True once S3 registers the cart routes; until then cards link to the product page. */
    function store_cart_ready(): bool
    {
        return route('store.cart.add') !== null;
    }

    /** Digits-only WhatsApp number (Indian 10-digit numbers get 91). */
    function store_wa_number(): string
    {
        $d = preg_replace('/\D+/', '', (string) business('whatsapp', '')) ?? '';
        if (strlen($d) === 10) {
            $d = '91' . $d;
        }

        return $d;
    }

    /** wa.me link with a prefilled message, or '' when no number is configured. */
    function store_wa_link(string $text = ''): string
    {
        $n = store_wa_number();
        if ($n === '') {
            return '';
        }

        return 'https://wa.me/' . $n . ($text !== '' ? '?text=' . rawurlencode($text) : '');
    }

    function store_tel(): string
    {
        $d = preg_replace('/[^\d+]/', '', (string) business('phone', '')) ?? '';

        return $d !== '' ? 'tel:' . $d : '';
    }

    /** One-line shop address from config/business.php. */
    function store_address(): string
    {
        $a = (array) business('address', []);
        $parts = array_filter([
            $a['line1'] ?? '', $a['line2'] ?? '', $a['city'] ?? '',
            trim(($a['state'] ?? '') . ' ' . ($a['pincode'] ?? '')),
        ], fn ($v) => trim((string) $v) !== '');

        return implode(', ', $parts);
    }

    /** JSON for an inline <script type="application/json"> block (safe against </script>). */
    function store_json(mixed $data): string
    {
        return (string) json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    }

    /** Render a store partial with variables. @param array<string,mixed> $vars */
    function store_partial(string $name, array $vars = []): string
    {
        View::loadHelpers();
        extract($vars, EXTR_SKIP);
        ob_start();
        include app()->basePath('resources/views/store/partials/' . $name . '.php');

        return (string) ob_get_clean();
    }
}
