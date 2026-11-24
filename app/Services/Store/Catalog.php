<?php

namespace App\Services\Store;

use App\Services\Order\PaymentService;
use Vayu\Database\DB;

/**
 * Read-only storefront queries (docs/storefront.md §2, §3.2). Never writes.
 *
 *   chrome()        header / mega menu / drawer / footer data, cached (config store.cache_ttl)
 *   cards($ids)     product cards in the given order: price, badges, images, rating, CTA state
 *   home()          every block of the home page
 *   brandsPage()    brands with their first products
 *
 * Visibility rule used everywhere: listed = status IN ('active','coming_soon'). The price is shown
 * only when show_price = 1 and is_enquiry_only = 0. Stock never blocks (owner default: an empty
 * shelf shows "Made to order").
 */
final class Catalog
{
    public const LISTED = "('active','coming_soon')";

    /* ================================================================ chrome */

    /** @return array{spaces:list<array>, origins:list<array>, needs:list<array>, featured:?array, total:int} */
    public static function chrome(): array
    {
        $ttl = (int) config('store.cache_ttl', 600);
        if ($ttl <= 0) {
            return self::buildChrome();
        }

        return cache()->remember('store.chrome.v1', $ttl, fn () => self::buildChrome());
    }

    public static function forgetChrome(): void
    {
        cache()->forget('store.chrome.v1');
    }

    /** @return array<string,mixed> */
    private static function buildChrome(): array
    {
        $tree = self::categoryTree();
        $spaces = array_values(array_filter($tree, fn ($c) => $c['children'] !== []));
        $origins = array_values(array_filter($tree, fn ($c) => $c['children'] === []));

        $featuredId = DB::select('SELECT id FROM products WHERE status = ? AND is_featured = 1 ORDER BY id DESC LIMIT 1', ['active']);
        $featured = $featuredId ? (self::cards([(int) $featuredId[0]['id']])[0] ?? null) : null;

        return [
            'spaces' => $spaces,
            'origins' => $origins,
            'needs' => self::needs(),
            'featured' => $featured,
            'total' => self::listedCount(),
        ];
    }

    /**
     * Visible top-level categories (sorted) each with visible children; counts are distinct listed
     * products in the category or any child.
     *
     * @return list<array<string,mixed>>
     */
    public static function categoryTree(): array
    {
        $rows = DB::select('SELECT id, parent_id, name, slug, description, is_featured, sort FROM product_categories
                            WHERE is_visible = 1 ORDER BY sort, name');

        $own = [];
        foreach (DB::select('SELECT m.category_id, COUNT(DISTINCT m.product_id) AS n FROM product_category_map m
                             JOIN products p ON p.id = m.product_id WHERE p.status IN ' . self::LISTED . '
                             GROUP BY m.category_id') as $r) {
            $own[(int) $r['category_id']] = (int) $r['n'];
        }
        $top = [];
        foreach (DB::select('SELECT COALESCE(c.parent_id, c.id) AS top_id, COUNT(DISTINCT m.product_id) AS n
                             FROM product_category_map m JOIN product_categories c ON c.id = m.category_id
                             JOIN products p ON p.id = m.product_id WHERE p.status IN ' . self::LISTED . '
                             GROUP BY COALESCE(c.parent_id, c.id)') as $r) {
            $top[(int) $r['top_id']] = (int) $r['n'];
        }

        $images = self::categoryImages(array_map(fn ($r) => (int) $r['id'], $rows));

        $byParent = [];
        $tops = [];
        foreach ($rows as $r) {
            $c = [
                'id' => (int) $r['id'],
                'parent_id' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
                'name' => (string) $r['name'],
                'slug' => (string) $r['slug'],
                'description' => (string) ($r['description'] ?? ''),
                'is_featured' => (int) $r['is_featured'] === 1,
                'image' => $images[(int) $r['id']] ?? null,
                'count' => $own[(int) $r['id']] ?? 0,
                'children' => [],
            ];
            if ($c['parent_id'] === null) {
                $c['count'] = $top[$c['id']] ?? 0;
                $tops[$c['id']] = $c;
            } else {
                $byParent[$c['parent_id']][] = $c;
            }
        }
        foreach ($byParent as $pid => $kids) {
            if (isset($tops[$pid])) {
                $tops[$pid]['children'] = $kids;
            }
        }

        return array_values($tops);
    }

    /**
     * Category picture: its own cover (media_links category/cover), else the cover of a listed
     * product in it (or a child). Null = the view draws a stone placeholder.
     *
     * @param list<int> $ids
     * @return array<int, array{src:string, full:string}>
     */
    private static function categoryImages(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (DB::select("SELECT l.owner_id, m.path, m.thumb_path FROM media_links l JOIN media m ON m.id = l.media_id
                             WHERE l.owner_type = 'category' AND l.role = 'cover' AND m.kind = 'image' AND m.disk = 'public'
                               AND l.owner_id IN ($in) ORDER BY l.sort, l.id", $ids) as $r) {
            $out[(int) $r['owner_id']] ??= self::img($r);
        }

        $missing = array_values(array_diff($ids, array_keys($out)));
        if ($missing !== []) {
            $in = implode(',', array_fill(0, count($missing), '?'));
            $rows = DB::select("SELECT c.id AS category_id, m.path, m.thumb_path
                                FROM product_categories c
                                JOIN product_categories cc ON (cc.id = c.id OR cc.parent_id = c.id)
                                JOIN product_category_map pm ON pm.category_id = cc.id
                                JOIN products p ON p.id = pm.product_id AND p.status IN " . self::LISTED . "
                                JOIN media_links l ON l.owner_type = 'product' AND l.owner_id = p.id AND l.role IN ('room','cover')
                                JOIN media m ON m.id = l.media_id AND m.kind = 'image' AND m.disk = 'public'
                                WHERE c.id IN ($in)
                                ORDER BY c.id, p.is_featured DESC, CASE l.role WHEN 'room' THEN 0 ELSE 1 END, p.id DESC, l.sort", $missing);
            foreach ($rows as $r) {
                $out[(int) $r['category_id']] ??= self::img($r);
            }
        }

        return $out;
    }

    /** Discovery tags (purpose = discovery, active) with listed-product counts. @return list<array> */
    public static function needs(): array
    {
        $rows = DB::select("SELECT t.id, t.name, t.slug, t.description, t.icon,
                                   (SELECT COUNT(DISTINCT pt.product_id) FROM product_tags pt JOIN products p ON p.id = pt.product_id
                                     WHERE pt.tag_id = t.id AND p.status IN " . self::LISTED . ") AS n
                            FROM tags t WHERE t.purpose = 'discovery' AND t.status = 'active' ORDER BY t.name");

        return array_map(fn ($r) => [
            'name' => (string) $r['name'],
            'slug' => (string) $r['slug'],
            'copy' => (string) ($r['description'] ?? ''),
            'icon' => self::iconName($r['icon'] ?? null),
            'count' => (int) $r['n'],
        ], $rows);
    }

    public static function listedCount(): int
    {
        return (int) (DB::select('SELECT COUNT(*) AS n FROM products WHERE status IN ' . self::LISTED)[0]['n'] ?? 0);
    }

    /* ================================================================ cards */

    /**
     * Product cards for the given ids, in that order. Unlisted products are dropped.
     *
     * @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    public static function cards(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));

        $products = [];
        foreach (DB::select("SELECT id, slug, name, sku, status, is_enquiry_only, show_price, is_featured, is_bestseller, short_description, brand_id
                             FROM products WHERE id IN ($in) AND status IN " . self::LISTED, $ids) as $r) {
            $products[(int) $r['id']] = $r;
        }
        if ($products === []) {
            return [];
        }
        $ids = array_values(array_filter($ids, fn ($id) => isset($products[$id])));
        $in = implode(',', array_fill(0, count($ids), '?'));

        $pricing = [];
        foreach (DB::select("SELECT product_id, price, compare_at_price, unit, pack_qty, pack_unit FROM product_pricing
                             WHERE product_id IN ($in) ORDER BY id", $ids) as $r) {
            $pricing[(int) $r['product_id']] ??= $r;
        }

        $variants = [];
        foreach (DB::select("SELECT id, product_id, sku, title, price, compare_at_price, unit, is_default FROM product_variants
                             WHERE product_id IN ($in) AND status = 'active' ORDER BY sort, id", $ids) as $r) {
            $variants[(int) $r['product_id']][] = $r;
        }

        $groups = [];
        foreach (DB::select("SELECT DISTINCT v.product_id, g.name, g.sort FROM product_variants v
                             JOIN product_variant_values vv ON vv.variant_id = v.id
                             JOIN option_values ov ON ov.id = vv.option_value_id
                             JOIN option_groups g ON g.id = ov.option_group_id
                             WHERE v.product_id IN ($in) AND v.status = 'active' ORDER BY g.sort, g.name", $ids) as $r) {
            $groups[(int) $r['product_id']][] = mb_strtolower((string) $r['name']);
        }

        $attrs = [];
        foreach (DB::select("SELECT pav.product_id, a.code, pav.value FROM product_attribute_values pav
                             JOIN attributes a ON a.id = pav.attribute_id
                             WHERE pav.product_id IN ($in) AND a.code IN ('origin','finish','thickness','stone_type','colour')", $ids) as $r) {
            if (trim((string) $r['value']) !== '') {
                $attrs[(int) $r['product_id']][(string) $r['code']] = trim((string) $r['value']);
            }
        }

        $ratings = [];
        foreach (DB::select("SELECT product_id, AVG(rating) AS avg_rating, COUNT(*) AS n FROM product_reviews
                             WHERE status = 'published' AND product_id IN ($in) GROUP BY product_id", $ids) as $r) {
            $ratings[(int) $r['product_id']] = ['avg' => round((float) $r['avg_rating'], 1), 'count' => (int) $r['n']];
        }

        $stock = [];
        foreach (DB::select("SELECT product_id, status FROM inventory_stock WHERE product_id IN ($in)", $ids) as $r) {
            $s = (string) $r['status'];
            $prev = $stock[(int) $r['product_id']] ?? null;
            // worst wins: out > low > healthy
            if ($prev === null || ($s === 'out') || ($s === 'low' && $prev === 'healthy')) {
                $stock[(int) $r['product_id']] = $s;
            }
        }

        $tags = [];
        foreach (DB::select("SELECT pt.product_id, t.slug, t.name, t.purpose, t.description FROM product_tags pt
                             JOIN tags t ON t.id = pt.tag_id
                             WHERE pt.product_id IN ($in) AND t.status = 'active' AND t.purpose IN ('discovery','merchandising')
                             ORDER BY t.name", $ids) as $r) {
            $tags[(int) $r['product_id']][] = $r;
        }

        $images = self::productImages($ids);

        $out = [];
        foreach ($ids as $id) {
            $out[] = self::card(
                $products[$id],
                $pricing[$id] ?? null,
                $variants[$id] ?? [],
                $groups[$id] ?? [],
                $attrs[$id] ?? [],
                $ratings[$id] ?? ['avg' => 0.0, 'count' => 0],
                $stock[$id] ?? null,
                $tags[$id] ?? [],
                $images[$id] ?? ['main' => null, 'alt' => null],
            );
        }

        return $out;
    }

    /**
     * Card images: main = cover (else first gallery), alt (hover) = room shot, else the next gallery image.
     *
     * @param list<int> $ids
     * @return array<int, array{main:?array, alt:?array}>
     */
    private static function productImages(array $ids): array
    {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::select("SELECT l.owner_id, l.role, m.path, m.thumb_path, m.alt_text FROM media_links l JOIN media m ON m.id = l.media_id
                            WHERE l.owner_type = 'product' AND l.owner_id IN ($in) AND l.role IN ('cover','gallery','room')
                              AND m.kind = 'image' AND m.disk = 'public'
                            ORDER BY l.owner_id, CASE l.role WHEN 'cover' THEN 0 WHEN 'gallery' THEN 1 ELSE 2 END, l.sort, l.id", $ids);
        $by = [];
        foreach ($rows as $r) {
            $by[(int) $r['owner_id']][] = $r;
        }
        $out = [];
        foreach ($by as $pid => $list) {
            $main = null;
            $room = null;
            $next = null;
            foreach ($list as $r) {
                if ($r['role'] === 'room') {
                    $room ??= $r;
                } elseif ($main === null) {
                    $main = $r;
                } else {
                    $next ??= $r;
                }
            }
            $main ??= $room;
            $alt = $room !== null && $room !== $main ? $room : $next;
            $out[$pid] = ['main' => $main ? self::img($main) : null, 'alt' => $alt ? self::img($alt) : null];
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private static function card(array $p, ?array $pricing, array $variants, array $groups, array $attrs, array $rating, ?string $stock, array $tags, array $images): array
    {
        $status = (string) $p['status'];
        $showPrice = (int) $p['show_price'] === 1 && (int) $p['is_enquiry_only'] === 0;
        $unit = (string) ($pricing['unit'] ?? '');

        // Price shown on the card: the cheapest active variant, else the product price.
        $price = $pricing !== null ? (float) $pricing['price'] : 0.0;
        $compare = $pricing !== null && $pricing['compare_at_price'] !== null ? (float) $pricing['compare_at_price'] : null;
        $defaultVariant = null;
        if ($variants !== []) {
            $cheapest = null;
            foreach ($variants as $v) {
                if ($cheapest === null || (float) $v['price'] < (float) $cheapest['price']) {
                    $cheapest = $v;
                }
                if ((int) $v['is_default'] === 1) {
                    $defaultVariant = $v;
                }
            }
            $price = (float) $cheapest['price'];
            $compare = $cheapest['compare_at_price'] !== null ? (float) $cheapest['compare_at_price'] : null;
            $unit = (string) ($cheapest['unit'] ?: $unit);
            $defaultVariant ??= $variants[0];
        }
        if ($compare !== null && $compare <= $price) {
            $compare = null;
        }

        $buyable = $status === 'active' && $showPrice && $price > 0;
        $tagSlugs = array_map(fn ($t) => (string) $t['slug'], $tags);
        $need = null;
        foreach ($tags as $t) {
            if ($t['purpose'] === 'discovery' && trim((string) ($t['description'] ?? '')) !== '') {
                $need = ucfirst((string) $t['name']);
                break;
            }
        }

        // Badges: one main badge + optional stock badge.
        $badges = [];
        if ($status === 'coming_soon') {
            $badges[] = ['label' => 'Coming soon', 'tone' => 'ink'];
        } elseif ($stock === 'out') {
            $badges[] = ['label' => 'Made to order', 'tone' => 'white'];
        } elseif ((int) $p['is_bestseller'] === 1) {
            $badges[] = ['label' => 'Bestseller', 'tone' => 'gold'];
        } elseif (in_array('new-arrival', $tagSlugs, true)) {
            $badges[] = ['label' => 'New', 'tone' => 'teal'];
        } elseif (in_array('premium', $tagSlugs, true)) {
            $badges[] = ['label' => 'Premium', 'tone' => 'ink'];
        }
        if ($stock === 'low' && $status === 'active') {
            $badges[] = ['label' => 'Few left', 'tone' => 'copper'];
        }

        // Call to action.
        if ($status === 'coming_soon') {
            $cta = 'notify';
        } elseif (!$showPrice || $price <= 0) {
            $cta = 'enquire';
        } elseif (count($variants) > 1) {
            $cta = 'choose';
        } else {
            $cta = 'add';
        }

        $specs = array_values(array_filter([
            $attrs['finish'] ?? null,
            $attrs['thickness'] ?? null,
            count($variants) > 1 ? count($variants) . ' options' : null,
        ]));

        $packQty = $pricing !== null && $pricing['pack_qty'] !== null ? (float) $pricing['pack_qty'] : null;

        return [
            'id' => (int) $p['id'],
            'slug' => (string) $p['slug'],
            'sku' => (string) $p['sku'],
            'name' => (string) $p['name'],
            'url' => self::productUrl((string) $p['slug']),
            'status' => $status,
            'short' => (string) ($p['short_description'] ?? ''),
            'brand_id' => $p['brand_id'] !== null ? (int) $p['brand_id'] : null,
            'is_featured' => (int) $p['is_featured'] === 1,
            'is_bestseller' => (int) $p['is_bestseller'] === 1,
            'is_enquiry_only' => (int) $p['is_enquiry_only'] === 1,
            'show_price' => $showPrice,
            'buyable' => $buyable,
            'price' => $price,
            'compare' => $compare,
            'off' => $compare ? (int) round((1 - $price / $compare) * 100) : 0,
            'from' => count($variants) > 1,
            'unit' => $unit,
            'pack_qty' => $packQty,
            'pack_unit' => $pricing['pack_unit'] ?? null,
            'variants' => count($variants),
            'default_variant_id' => $defaultVariant ? (int) $defaultVariant['id'] : 0,
            'option_groups' => array_values(array_unique($groups)),
            'origin' => $attrs['origin'] ?? '',
            'stone_type' => $attrs['stone_type'] ?? '',
            'colour' => $attrs['colour'] ?? '',
            'specs' => $specs,
            'rating' => $rating['avg'],
            'reviews' => $rating['count'],
            'need' => $need,
            'stock' => $stock,
            'tags' => $tagSlugs,
            'badges' => $badges,
            'cta' => $cta,
            'image' => $images['main'],
            'image_alt' => $images['alt'],
            'on_offer' => $buyable && $compare !== null,
        ];
    }

    /* ================================================================ home */

    /** @return array<string,mixed> */
    public static function home(): array
    {
        $chrome = self::chrome();

        $ids = array_map(fn ($r) => (int) $r['id'], DB::select(
            'SELECT id FROM products WHERE status IN ' . self::LISTED . '
             ORDER BY CASE status WHEN \'active\' THEN 0 ELSE 1 END, is_featured DESC, is_bestseller DESC, id DESC LIMIT 24'
        ));
        $cards = self::cards($ids);

        $tabs = [
            'best' => ['Bestsellers', fn ($c) => $c['is_bestseller']],
            'new' => ['New arrivals', fn ($c) => in_array('new-arrival', $c['tags'], true)],
            'premium' => ['Premium', fn ($c) => in_array('premium', $c['tags'], true)],
            'offer' => ['On offer', fn ($c) => $c['on_offer']],
        ];
        $tabList = [];
        foreach ($cards as $i => $c) {
            $keys = ['all'];
            foreach ($tabs as $k => [, $test]) {
                if ($test($c)) {
                    $keys[] = $k;
                }
            }
            $cards[$i]['tab_keys'] = $keys;
        }
        foreach ($tabs as $k => [$label, $test]) {
            $n = count(array_filter($cards, $test));
            if ($n > 0) {
                $tabList[] = ['key' => $k, 'label' => $label, 'count' => $n];
            }
        }
        $tabList[] = ['key' => 'all', 'label' => 'All', 'count' => count($cards)];

        $featured = array_values(array_filter($cards, fn ($c) => $c['is_featured'] && $c['image'] !== null));

        return [
            'chrome' => $chrome,
            'slides' => self::slides($featured),
            'finder' => self::finder($chrome['spaces']),
            'cards' => $cards,
            'tabs' => $tabList,
            'total' => $chrome['total'],
            'spotlight' => self::spotlight($cards),
            'brands' => self::brands(3),
            'reviews' => self::reviewSummary(4),
            'advance_percent' => PaymentService::ADVANCE_PERCENT,
            'gst_rate' => (float) business('tax.gst_rate', 18),
        ];
    }

    /**
     * Hero slides: featured products with an image (room shot preferred). With none, one
     * brand slide over a stone placeholder.
     *
     * @param list<array> $featured
     * @return list<array<string,mixed>>
     */
    private static function slides(array $featured): array
    {
        $slides = [[
            'image' => null,
            'kicker' => business('address.city', 'Bankura') . ' showroom',
            'title' => "Stone for the\nrooms you live in.",
            'copy' => 'Italian and Indian marble, granite and onyx for floors, walls and kitchens. See every slab in our showroom before it reaches your home.',
            'href' => '#collection',
            'cta' => 'Explore the collection',
        ]];
        foreach (array_slice($featured, 0, 4) as $c) {
            $slides[] = [
                'image' => $c['image_alt'] ?? $c['image'],
                'kicker' => trim(($c['stone_type'] ?: 'Featured') . ($c['origin'] ? ' · ' . $c['origin'] : '')),
                'title' => $c['name'],
                'copy' => $c['short'] !== '' ? $c['short'] : 'Chosen slab by slab. Ask us for photos of the exact lot.',
                'href' => $c['url'],
                'cta' => 'See this stone',
            ];
        }
        if ($featured !== []) {
            $slides[0]['image'] = $featured[0]['image_alt'] ?? $featured[0]['image'];
        }

        return $slides;
    }

    /**
     * Stone finder data: where (spaces) → which room (children) → colour (attribute_values of the
     * `colour` attribute). `matches` maps category id / colour label → listed product ids so the
     * page can show a live count without another request.
     *
     * @return array<string,mixed>
     */
    private static function finder(array $spaces): array
    {
        $colours = array_map(fn ($r) => ['label' => (string) $r['label'], 'swatch' => (string) ($r['swatch'] ?: '#E0D5C2')], DB::select(
            "SELECT v.label, v.swatch FROM attribute_values v JOIN attributes a ON a.id = v.attribute_id
             WHERE a.code = 'colour' ORDER BY v.sort, v.label"
        ));

        $byCat = [];
        foreach (DB::select('SELECT m.category_id, c.parent_id, m.product_id FROM product_category_map m
                             JOIN product_categories c ON c.id = m.category_id
                             JOIN products p ON p.id = m.product_id WHERE p.status IN ' . self::LISTED) as $r) {
            $byCat[(int) $r['category_id']][(int) $r['product_id']] = true;
            if ($r['parent_id'] !== null) {
                $byCat[(int) $r['parent_id']][(int) $r['product_id']] = true;
            }
        }
        $byColour = [];
        foreach (DB::select("SELECT pav.value, pav.product_id FROM product_attribute_values pav JOIN attributes a ON a.id = pav.attribute_id
                             JOIN products p ON p.id = pav.product_id
                             WHERE a.code = 'colour' AND p.status IN " . self::LISTED) as $r) {
            foreach (array_map('trim', explode(',', (string) $r['value'])) as $label) {
                if ($label !== '') {
                    $byColour[$label][(int) $r['product_id']] = true;
                }
            }
        }

        return [
            'spaces' => $spaces,
            'colours' => $colours,
            'matches' => [
                'cat' => array_map(fn ($set) => array_keys($set), $byCat),
                'colour' => array_map(fn ($set) => array_keys($set), $byColour),
            ],
        ];
    }

    /**
     * "Stone of the month": the first featured buyable card, else the first buyable card. Adds a
     * small gallery, the variant list (price per variant) and spec rows.
     *
     * @param list<array> $cards
     * @return array<string,mixed>|null
     */
    private static function spotlight(array $cards): ?array
    {
        $pick = null;
        foreach ($cards as $c) {
            if ($c['buyable'] && $c['is_featured']) {
                $pick = $c;
                break;
            }
        }
        if ($pick === null) {
            foreach ($cards as $c) {
                if ($c['buyable']) {
                    $pick = $c;
                    break;
                }
            }
        }
        if ($pick === null) {
            return null;
        }
        $id = $pick['id'];

        $shots = [];
        foreach (DB::select("SELECT m.path, m.thumb_path, m.alt_text FROM media_links l JOIN media m ON m.id = l.media_id
                             WHERE l.owner_type = 'product' AND l.owner_id = ? AND l.role IN ('cover','gallery','room')
                               AND m.kind = 'image' AND m.disk = 'public'
                             ORDER BY CASE l.role WHEN 'room' THEN 0 WHEN 'cover' THEN 1 ELSE 2 END, l.sort, l.id LIMIT 5", [$id]) as $r) {
            $shots[] = self::img($r);
        }

        $variants = array_map(fn ($v) => [
            'id' => (int) $v['id'],
            'sku' => (string) $v['sku'],
            'title' => (string) ($v['title'] ?: $v['sku']),
            'price' => (float) $v['price'],
            'compare' => $v['compare_at_price'] !== null && (float) $v['compare_at_price'] > (float) $v['price'] ? (float) $v['compare_at_price'] : null,
            'is_default' => (int) $v['is_default'] === 1,
        ], DB::select("SELECT id, sku, title, price, compare_at_price, is_default FROM product_variants
                       WHERE product_id = ? AND status = 'active' ORDER BY sort, id", [$id]));

        $specs = [];
        foreach (DB::select("SELECT a.name, pav.value FROM product_attribute_values pav JOIN attributes a ON a.id = pav.attribute_id
                             WHERE pav.product_id = ? AND a.code IN ('origin','stone_type','pattern','grade','slab_size','water_absorption')
                               AND a.status = 'active' ORDER BY a.sort", [$id]) as $r) {
            if (trim((string) $r['value']) !== '') {
                $specs[] = ['label' => (string) $r['name'], 'value' => trim((string) $r['value'])];
            }
        }
        $rooms = array_map(fn ($r) => (string) $r['name'], DB::select(
            'SELECT c.name FROM product_category_map m JOIN product_categories c ON c.id = m.category_id
             WHERE m.product_id = ? AND c.parent_id IS NOT NULL AND c.is_visible = 1 ORDER BY c.sort, c.name', [$id]
        ));

        $brand = $pick['brand_id'] ? DB::select('SELECT name FROM brands WHERE id = ?', [$pick['brand_id']]) : [];

        return $pick + [
            'brand' => $brand ? (string) $brand[0]['name'] : '',
            'shots' => $shots,
            'variant_list' => $variants,
            'spec_rows' => $specs,
            'rooms' => $rooms,
        ];
    }

    /* ================================================================ brands */

    /**
     * Active brands, featured first, with listed-product counts and a picture (logo link, else a
     * product cover).
     *
     * @return list<array<string,mixed>>
     */
    public static function brands(?int $limit = null, int $productsEach = 0): array
    {
        $rows = DB::select("SELECT b.id, b.name, b.slug, b.tier, b.country, b.brand_type, b.description, b.is_featured,
                                   (SELECT COUNT(*) FROM products p WHERE p.brand_id = b.id AND p.status IN " . self::LISTED . ") AS n
                            FROM brands b WHERE b.status = 'active'
                            ORDER BY b.is_featured DESC, CASE b.tier WHEN 'signature' THEN 0 WHEN 'core' THEN 1 ELSE 2 END, b.name"
            . ($limit ? ' LIMIT ' . (int) $limit : ''));
        if ($rows === []) {
            return [];
        }
        $ids = array_map(fn ($r) => (int) $r['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));

        $logos = [];
        foreach (DB::select("SELECT l.owner_id, m.path, m.thumb_path FROM media_links l JOIN media m ON m.id = l.media_id
                             WHERE l.owner_type = 'brand' AND l.role IN ('logo','cover') AND m.kind = 'image' AND m.disk = 'public'
                               AND l.owner_id IN ($in) ORDER BY l.sort, l.id", $ids) as $r) {
            $logos[(int) $r['owner_id']] ??= self::img($r);
        }

        $productIds = [];
        foreach ($ids as $bid) {
            $take = max($productsEach, 1);
            $productIds[$bid] = array_map(fn ($r) => (int) $r['id'], DB::select(
                'SELECT id FROM products WHERE brand_id = ? AND status IN ' . self::LISTED . ' ORDER BY is_featured DESC, is_bestseller DESC, id DESC LIMIT ' . $take,
                [$bid]
            ));
        }
        $cards = [];
        foreach (self::cards(array_merge(...array_values($productIds))) as $c) {
            $cards[$c['id']] = $c;
        }

        return array_map(function ($r) use ($logos, $productIds, $cards, $productsEach) {
            $bid = (int) $r['id'];
            $mine = array_values(array_filter(array_map(fn ($id) => $cards[$id] ?? null, $productIds[$bid])));

            return [
                'id' => $bid,
                'name' => (string) $r['name'],
                'slug' => (string) $r['slug'],
                'tier' => (string) ($r['tier'] ?: 'core'),
                'country' => (string) ($r['country'] ?? ''),
                'type' => (string) ($r['brand_type'] ?? ''),
                'description' => (string) ($r['description'] ?? ''),
                'count' => (int) $r['n'],
                'image' => $logos[$bid] ?? ($mine[0]['image'] ?? null),
                'is_logo' => isset($logos[$bid]),
                'products' => $productsEach > 0 ? $mine : [],
            ];
        }, $rows);
    }

    /* ================================================================ reviews */

    /** Published reviews: average, count, 5→1 distribution and the latest few. @return array<string,mixed> */
    public static function reviewSummary(int $latest = 4): array
    {
        $dist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $sum = 0;
        $n = 0;
        foreach (DB::select("SELECT r.rating, COUNT(*) AS n FROM product_reviews r JOIN products p ON p.id = r.product_id
                             WHERE r.status = 'published' AND p.status IN " . self::LISTED . ' GROUP BY r.rating') as $r) {
            $k = max(1, min(5, (int) $r['rating']));
            $dist[$k] += (int) $r['n'];
            $sum += $k * (int) $r['n'];
            $n += (int) $r['n'];
        }

        $items = DB::select("SELECT r.id, r.product_id, r.customer_name, r.city, r.application, r.rating, r.title, r.body, r.response,
                                    r.is_verified_purchase, r.created_at, p.name AS product_name, p.slug AS product_slug
                             FROM product_reviews r JOIN products p ON p.id = r.product_id
                             WHERE r.status = 'published' AND p.status IN " . self::LISTED . '
                             ORDER BY r.created_at DESC, r.id DESC LIMIT ' . (int) $latest);
        $covers = [];
        if ($items !== []) {
            foreach (self::productImages(array_values(array_unique(array_map(fn ($r) => (int) $r['product_id'], $items)))) as $pid => $img) {
                $covers[$pid] = $img['main'];
            }
        }

        return [
            'count' => $n,
            'avg' => $n ? round($sum / $n, 1) : 0.0,
            'dist' => array_map(fn ($c) => $n ? (int) round($c * 100 / $n) : 0, $dist),
            'items' => array_map(fn ($r) => [
                'name' => self::shortName((string) $r['customer_name']),
                'city' => (string) ($r['city'] ?? ''),
                'application' => (string) ($r['application'] ?? ''),
                'rating' => (int) $r['rating'],
                'title' => (string) ($r['title'] ?? ''),
                'body' => (string) ($r['body'] ?? ''),
                'response' => (string) ($r['response'] ?? ''),
                'verified' => (int) ($r['is_verified_purchase'] ?? 0) === 1,
                'product' => (string) $r['product_name'],
                'product_url' => self::productUrl((string) $r['product_slug']),
                'image' => $covers[(int) $r['product_id']] ?? null,
            ], $items),
        ];
    }

    /* ================================================================ helpers */

    public static function productUrl(string $slug): string
    {
        return '/product/' . rawurlencode($slug);
    }

    /** "Ritika Sen" → "Ritika S." (reviews never show a full surname). */
    public static function shortName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if (count($parts) < 2) {
            return trim($name);
        }

        return $parts[0] . ' ' . mb_strtoupper(mb_substr((string) end($parts), 0, 1)) . '.';
    }

    /** Lucide icon names only (letters, digits, dashes); anything else falls back to "sparkles". */
    public static function iconName(?string $icon): string
    {
        $icon = trim((string) $icon);

        return preg_match('/^[a-z0-9-]{2,40}$/', $icon) ? $icon : 'sparkles';
    }

    /** @return array{src:string, full:string, alt:string} public image paths (use with base_url) */
    private static function img(array $r): array
    {
        return [
            'src' => (string) ($r['thumb_path'] ?: $r['path']),
            'full' => (string) $r['path'],
            'alt' => (string) ($r['alt_text'] ?? ''),
        ];
    }
}
