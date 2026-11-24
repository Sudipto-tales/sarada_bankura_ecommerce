<?php

namespace App\Services\Store;

use App\Services\Order\PaymentService;
use Vayu\Database\DB;

/**
 * Everything the product page shows (docs/storefront.md §3.4). Read-only.
 *
 *   ProductPage::load('statuario-marble', 'STA-18-POL')   // ?v= SKU picks the variant
 *
 * Returns null when the product is not listed (draft / archived / unknown slug → 404).
 * Variants: every non-archived variant is returned; only `active` ones can be chosen, inactive
 * ones show as "Not offered" in the table and strike their combination in the picker.
 */
final class ProductPage
{
    /** Attribute codes shown in the short spec grid of the enquiry / coming-soon box. */
    public const TEASER = ['origin', 'finish', 'thickness', 'grade'];
    public const RELATED = 8;
    public const REVIEWS = 30;

    /** @return array<string,mixed>|null */
    public static function load(string $slug, ?string $sku = null): ?array
    {
        $p = DB::selectOne('SELECT * FROM products WHERE slug = ? AND status IN ' . Catalog::LISTED, [mb_strtolower(trim($slug))]);
        if ($p === null) {
            return null;
        }
        $id = (int) $p['id'];
        $card = Catalog::cards([$id])[0] ?? null;
        if ($card === null) {
            return null;
        }

        $pricing = DB::selectOne('SELECT * FROM product_pricing WHERE product_id = ? ORDER BY id LIMIT 1', [$id]);
        $brand = $p['brand_id'] ? DB::selectOne("SELECT id, name, slug, country, tier FROM brands WHERE id = ? AND status = 'active'", [(int) $p['brand_id']]) : null;

        [$primary, $parent, $rooms] = self::categories($id);
        $options = self::options($id);
        $variants = self::variants($id, $pricing, $options['byVariant']);
        $selected = self::pick($variants, $sku);
        $gallery = self::gallery($id, $variants);

        $specs = self::specs($id);
        $showPrice = $card['show_price'];
        $buyable = $card['buyable'] && ($variants === [] || $selected !== null);

        return [
            'product' => [
                'id' => $id,
                'slug' => (string) $p['slug'],
                'sku' => (string) $p['sku'],
                'name' => (string) $p['name'],
                'status' => (string) $p['status'],
                'short' => (string) ($p['short_description'] ?? ''),
                'description' => (string) ($p['description'] ?? ''),
                'meta_title' => (string) ($p['meta_title'] ?? ''),
                'meta_description' => (string) ($p['meta_description'] ?? ''),
                'is_enquiry_only' => (int) $p['is_enquiry_only'] === 1,
                'show_price' => $showPrice,
                'buyable' => $buyable,
                'stock' => $card['stock'],
                'badges' => $card['badges'],
                'rating' => $card['rating'],
                'reviews' => $card['reviews'],
                'url' => $card['url'],
            ],
            'card' => $card,
            'brand' => $brand,
            'pricing' => self::pricing($pricing),
            'primary' => $primary,
            'parent' => $parent,
            'rooms' => $rooms,
            'tags' => self::tags($id),
            'groups' => $options['groups'],
            'variants' => $variants,
            'selected' => $selected,
            'gallery' => $gallery,
            'specs' => $specs,
            'overrides' => self::overrides($specs, $options['groups'], $variants),
            'returns' => self::returns($id, $selected ? $selected['id'] : 0),
            'reviews' => self::reviews($id),
            'related' => self::related($id, $primary, $parent),
            'advance_percent' => PaymentService::ADVANCE_PERCENT,
            'gst_rate' => (float) business('tax.gst_rate', 18),
            'tax_included' => (bool) business('tax.prices_include_tax', false),
        ];
    }

    /* ================================================================ parts */

    /** @return array{0:?array, 1:?array, 2:list<array>} primary category, its parent, every visible sub-category ("used for") */
    private static function categories(int $id): array
    {
        $rows = DB::select('SELECT c.id, c.parent_id, c.name, c.slug, m.is_primary, pc.name AS parent_name, pc.slug AS parent_slug
                            FROM product_category_map m JOIN product_categories c ON c.id = m.category_id
                            LEFT JOIN product_categories pc ON pc.id = c.parent_id
                            WHERE m.product_id = ? AND c.is_visible = 1 ORDER BY m.is_primary DESC, c.sort, c.name', [$id]);
        // The primary row; else the first sub-category (deeper breadcrumb); else the first row.
        $primary = null;
        foreach ($rows as $r) {
            if ((int) $r['is_primary'] === 1) {
                $primary = $r;
                break;
            }
        }
        foreach ($primary ? [] : $rows as $r) {
            if ($r['parent_id'] !== null) {
                $primary = $r;
                break;
            }
        }
        $primary ??= $rows[0] ?? null;
        $parent = $primary && $primary['parent_id'] !== null
            ? ['id' => (int) $primary['parent_id'], 'name' => (string) $primary['parent_name'], 'slug' => (string) $primary['parent_slug']]
            : null;
        $images = [];
        $subIds = array_map(fn ($r) => (int) $r['id'], array_filter($rows, fn ($r) => $r['parent_id'] !== null));
        if ($subIds !== []) {
            foreach (Catalog::categoryTree() as $top) {
                foreach ($top['children'] as $c) {
                    $images[$c['id']] = $c['image'];
                }
            }
        }
        $rooms = [];
        foreach ($rows as $r) {
            if ($r['parent_id'] !== null) {
                $rooms[] = [
                    'id' => (int) $r['id'], 'name' => (string) $r['name'], 'parent' => (string) $r['parent_name'],
                    'url' => '/shop/' . rawurlencode((string) $r['parent_slug']) . '/' . rawurlencode((string) $r['slug']),
                    'image' => $images[(int) $r['id']] ?? null,
                ];
            }
        }

        return [
            $primary ? ['id' => (int) $primary['id'], 'name' => (string) $primary['name'], 'slug' => (string) $primary['slug'],
                'url' => $parent ? '/shop/' . rawurlencode($parent['slug']) . '/' . rawurlencode((string) $primary['slug']) : '/shop/' . rawurlencode((string) $primary['slug'])] : null,
            $parent ? $parent + ['url' => '/shop/' . rawurlencode($parent['slug'])] : null,
            $rooms,
        ];
    }

    /** @return list<array{name:string, slug:string, icon:string}> */
    private static function tags(int $id): array
    {
        return array_map(fn ($r) => ['name' => (string) $r['name'], 'slug' => (string) $r['slug'], 'icon' => Catalog::iconName($r['icon'] ?? null)], DB::select(
            "SELECT t.name, t.slug, t.icon FROM product_tags pt JOIN tags t ON t.id = pt.tag_id
             WHERE pt.product_id = ? AND t.purpose = 'discovery' AND t.status = 'active' ORDER BY t.name", [$id]
        ));
    }

    /**
     * Option groups used by this product's (non-archived) variants, each with the values in use.
     *
     * @return array{groups:list<array>, byVariant:array<int, array<int,int>>} byVariant = variant id => [group id => value id]
     */
    private static function options(int $id): array
    {
        $rows = DB::select("SELECT v.id AS variant_id, g.id AS gid, g.name AS gname, g.sort AS gsort, ov.id AS vid, ov.value, ov.sort AS vsort
                            FROM product_variants v
                            JOIN product_variant_values vv ON vv.variant_id = v.id
                            JOIN option_values ov ON ov.id = vv.option_value_id
                            JOIN option_groups g ON g.id = ov.option_group_id
                            WHERE v.product_id = ? AND v.status <> 'archived'
                            ORDER BY g.sort, g.name, ov.sort, ov.value", [$id]);
        $groups = [];
        $by = [];
        foreach ($rows as $r) {
            $gid = (int) $r['gid'];
            $groups[$gid] ??= ['id' => $gid, 'name' => (string) $r['gname'], 'values' => []];
            $groups[$gid]['values'][(int) $r['vid']] = ['id' => (int) $r['vid'], 'value' => (string) $r['value']];
            $by[(int) $r['variant_id']][$gid] = (int) $r['vid'];
        }

        return [
            'groups' => array_values(array_map(fn ($g) => ['values' => array_values($g['values'])] + $g, $groups)),
            'byVariant' => $by,
        ];
    }

    /**
     * @param array<int, array<int,int>> $values
     * @return list<array<string,mixed>>
     */
    private static function variants(int $id, ?array $pricing, array $values): array
    {
        $unit = (string) ($pricing['unit'] ?? '');
        $out = [];
        foreach (DB::select("SELECT * FROM product_variants WHERE product_id = ? AND status <> 'archived' ORDER BY sort, id", [$id]) as $v) {
            $price = (float) $v['price'];
            $compare = $v['compare_at_price'] !== null && (float) $v['compare_at_price'] > $price ? (float) $v['compare_at_price'] : null;
            $packQty = $v['pack_qty'] !== null ? (float) $v['pack_qty'] : ($pricing && $pricing['pack_qty'] !== null ? (float) $pricing['pack_qty'] : null);
            $out[] = [
                'id' => (int) $v['id'],
                'sku' => (string) $v['sku'],
                'title' => (string) ($v['title'] ?: $v['sku']),
                'price' => $price,
                'compare' => $compare,
                'off' => $compare ? (int) round((1 - $price / $compare) * 100) : 0,
                'unit' => (string) ($v['unit'] ?: $unit),
                'pack_qty' => $packQty,
                'pack_unit' => (string) ($v['pack_unit'] ?: ($pricing['pack_unit'] ?? '')),
                'min_qty' => $v['min_order_qty'] !== null ? (float) $v['min_order_qty'] : ($pricing && $pricing['min_order_qty'] !== null ? (float) $pricing['min_order_qty'] : null),
                'active' => (string) $v['status'] === 'active',
                'is_default' => (int) $v['is_default'] === 1,
                'values' => $values[(int) $v['id']] ?? [],
                'images' => [],
            ];
        }

        return $out;
    }

    /** ?v= SKU (active only), else the default active variant, else the first active one. */
    private static function pick(array $variants, ?string $sku): ?array
    {
        $active = array_values(array_filter($variants, fn ($v) => $v['active']));
        if ($active === []) {
            return null;
        }
        if ($sku !== null && $sku !== '') {
            foreach ($active as $v) {
                if (strcasecmp($v['sku'], $sku) === 0) {
                    return $v;
                }
            }
        }
        foreach ($active as $v) {
            if ($v['is_default']) {
                return $v;
            }
        }

        return $active[0];
    }

    /**
     * Gallery: product cover, room shots, gallery; then each variant's own images. Variant rows
     * get `images` = indexes into this list so the page can jump to them.
     *
     * @param list<array> $variants (by reference: images filled in)
     * @return list<array{src:string, full:string, alt:string, role:string}>
     */
    private static function gallery(int $id, array &$variants): array
    {
        $shots = [];
        $seen = [];
        foreach (DB::select("SELECT l.role, m.id, m.path, m.thumb_path, m.alt_text FROM media_links l JOIN media m ON m.id = l.media_id
                             WHERE l.owner_type = 'product' AND l.owner_id = ? AND l.role IN ('cover','room','gallery') AND m.kind = 'image' AND m.disk = 'public'
                             ORDER BY CASE l.role WHEN 'cover' THEN 0 WHEN 'room' THEN 1 ELSE 2 END, l.sort, l.id", [$id]) as $r) {
            if (!isset($seen[(int) $r['id']])) {
                $seen[(int) $r['id']] = count($shots);
                $shots[] = self::img($r);
            }
        }
        if ($variants !== []) {
            $ids = array_map(fn ($v) => $v['id'], $variants);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $byVariant = [];
            foreach (DB::select("SELECT l.owner_id, m.id, m.path, m.thumb_path, m.alt_text, l.role FROM media_links l JOIN media m ON m.id = l.media_id
                                 WHERE l.owner_type = 'variant' AND l.owner_id IN ($in) AND l.role IN ('cover','gallery','room') AND m.kind = 'image' AND m.disk = 'public'
                                 ORDER BY CASE l.role WHEN 'cover' THEN 0 ELSE 1 END, l.sort, l.id", $ids) as $r) {
                if (!isset($seen[(int) $r['id']])) {
                    $seen[(int) $r['id']] = count($shots);
                    $shots[] = self::img($r);
                }
                $byVariant[(int) $r['owner_id']][] = $seen[(int) $r['id']];
            }
            foreach ($variants as &$v) {
                $v['images'] = $byVariant[$v['id']] ?? [];
            }
            unset($v);
        }

        return $shots;
    }

    /** @return list<array{code:string, label:string, value:string}> every attribute with a value, in attribute order */
    private static function specs(int $id): array
    {
        $out = [];
        foreach (DB::select("SELECT a.code, a.name, a.unit, pav.value FROM product_attribute_values pav JOIN attributes a ON a.id = pav.attribute_id
                             WHERE pav.product_id = ? AND a.status = 'active' ORDER BY a.sort, a.name", [$id]) as $r) {
            $v = trim((string) $r['value']);
            if ($v !== '') {
                $out[] = ['code' => (string) $r['code'], 'label' => (string) $r['name'], 'value' => str_replace(',', ', ', preg_replace('/\s*,\s*/', ',', $v) ?? $v)];
            }
        }

        return $out;
    }

    /**
     * Spec rows a variant overrides: option group "Finish" replaces attribute "Finish", etc.
     * Returns variant id => [label => value] (labels match spec labels, or are new rows).
     *
     * @return array<int, array<string,string>>
     */
    private static function overrides(array $specs, array $groups, array $variants): array
    {
        $names = [];
        foreach ($groups as $g) {
            $label = $g['name'];
            foreach ($specs as $s) {
                if (Listing::norm($s['label']) === Listing::norm($g['name'])) {
                    $label = $s['label'];
                }
            }
            $vals = [];
            foreach ($g['values'] as $v) {
                $vals[$v['id']] = $v['value'];
            }
            $names[$g['id']] = [$label, $vals];
        }
        $out = [];
        foreach ($variants as $v) {
            foreach ($v['values'] as $gid => $vid) {
                if (isset($names[$gid][1][$vid])) {
                    $out[$v['id']][$names[$gid][0]] = $names[$gid][1][$vid];
                }
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private static function pricing(?array $r): array
    {
        $price = $r ? (float) $r['price'] : 0.0;
        $compare = $r && $r['compare_at_price'] !== null && (float) $r['compare_at_price'] > $price ? (float) $r['compare_at_price'] : null;

        return [
            'price' => $price,
            'compare' => $compare,
            'off' => $compare ? (int) round((1 - $price / $compare) * 100) : 0,
            'unit' => (string) ($r['unit'] ?? ''),
            'pack_qty' => $r && $r['pack_qty'] !== null ? (float) $r['pack_qty'] : null,
            'pack_unit' => (string) ($r['pack_unit'] ?? ''),
            'min_qty' => $r && $r['min_order_qty'] !== null ? (float) $r['min_order_qty'] : null,
            'step' => $r && $r['order_step'] !== null && (float) $r['order_step'] > 0 ? (float) $r['order_step'] : null,
        ];
    }

    /** Return policy: the selected variant's row, else the product row, else null (page shows the general policy link). */
    private static function returns(int $id, int $variantId): ?array
    {
        $r = DB::selectOne('SELECT * FROM product_return_policies WHERE product_id = ? AND variant_id IN (?, 0) AND is_active = 1
                            ORDER BY variant_id DESC LIMIT 1', [$id, $variantId]);

        return $r ? [
            'return' => (int) $r['return_allowed'] === 1,
            'return_days' => (int) $r['return_days'],
            'replacement' => (int) $r['replacement_allowed'] === 1,
            'replacement_days' => (int) $r['replacement_days'],
            'conditions' => (string) ($r['return_conditions'] ?? ''),
        ] : null;
    }

    /** Published reviews: summary + the latest few, with the applications used (filter chips). @return array<string,mixed> */
    private static function reviews(int $id): array
    {
        $dist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $sum = 0;
        $n = 0;
        foreach (DB::select("SELECT rating, COUNT(*) AS n FROM product_reviews WHERE product_id = ? AND status = 'published' GROUP BY rating", [$id]) as $r) {
            $k = max(1, min(5, (int) $r['rating']));
            $dist[$k] += (int) $r['n'];
            $sum += $k * (int) $r['n'];
            $n += (int) $r['n'];
        }
        $items = array_map(fn ($r) => [
            'name' => Catalog::shortName((string) $r['customer_name']),
            'city' => (string) ($r['city'] ?? ''),
            'application' => (string) ($r['application'] ?? ''),
            'rating' => max(1, min(5, (int) $r['rating'])),
            'title' => (string) ($r['title'] ?? ''),
            'body' => (string) ($r['body'] ?? ''),
            'response' => (string) ($r['response'] ?? ''),
            'verified' => (int) ($r['is_verified_purchase'] ?? 0) === 1,
            'date' => $r['created_at'] ? date('j M Y', strtotime((string) $r['created_at'])) : '',
        ], DB::select("SELECT customer_name, city, application, rating, title, body, response, is_verified_purchase, created_at
                       FROM product_reviews WHERE product_id = ? AND status = 'published'
                       ORDER BY created_at DESC, id DESC LIMIT " . self::REVIEWS, [$id]));
        $apps = array_values(array_unique(array_filter(array_map(fn ($r) => $r['application'], $items))));

        return [
            'count' => $n,
            'avg' => $n ? round($sum / $n, 1) : 0.0,
            'dist' => array_map(fn ($c) => $n ? (int) round($c * 100 / $n) : 0, $dist),
            'items' => $items,
            'applications' => $apps,
        ];
    }

    /** Same primary sub-category first, then the parent space, then shared discovery tags. @return list<array> */
    private static function related(int $id, ?array $primary, ?array $parent): array
    {
        $ids = [];
        $take = function (array $rows) use (&$ids, $id): void {
            foreach ($rows as $r) {
                $pid = (int) $r['id'];
                if ($pid !== $id && !in_array($pid, $ids, true) && count($ids) < self::RELATED) {
                    $ids[] = $pid;
                }
            }
        };
        $order = ' ORDER BY CASE p.status WHEN \'active\' THEN 0 ELSE 1 END, p.is_featured DESC, p.is_bestseller DESC, p.id DESC LIMIT ' . (self::RELATED + 1);
        foreach (array_filter([$primary['id'] ?? null, $parent['id'] ?? null]) as $cid) {
            $take(DB::select('SELECT DISTINCT p.id, p.status, p.is_featured, p.is_bestseller FROM products p JOIN product_category_map m ON m.product_id = p.id
                              JOIN product_categories c ON c.id = m.category_id
                              WHERE (c.id = ? OR c.parent_id = ?) AND p.status IN ' . Catalog::LISTED . $order, [$cid, $cid]));
        }
        if (count($ids) < self::RELATED) {
            $take(DB::select("SELECT p.id, p.status, p.is_featured, p.is_bestseller, COUNT(*) AS shared FROM product_tags mine
                              JOIN tags t ON t.id = mine.tag_id AND t.purpose = 'discovery' AND t.status = 'active'
                              JOIN product_tags other ON other.tag_id = mine.tag_id
                              JOIN products p ON p.id = other.product_id
                              WHERE mine.product_id = ? AND p.status IN " . Catalog::LISTED . '
                              GROUP BY p.id, p.status, p.is_featured, p.is_bestseller
                              ORDER BY shared DESC, p.is_featured DESC, p.id DESC LIMIT ' . (self::RELATED + 1), [$id]));
        }

        return Catalog::cards($ids);
    }

    /** @return array{src:string, full:string, alt:string, role:string} */
    private static function img(array $r): array
    {
        return [
            'src' => (string) ($r['thumb_path'] ?: $r['path']),
            'full' => (string) $r['path'],
            'alt' => (string) ($r['alt_text'] ?? ''),
            'role' => (string) ($r['role'] ?? 'gallery'),
        ];
    }

    /* ================================================================ JSON-LD */

    /** schema.org Product (+ Offer only when a price is shown). @param array<string,mixed> $d from load() */
    public static function jsonLd(array $d): array
    {
        $p = $d['product'];
        $v = $d['selected'];
        $ld = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $p['name'],
            'sku' => $v['sku'] ?? $p['sku'],
            'url' => base_url($p['url']),
        ];
        if ($p['short'] !== '' || $p['description'] !== '') {
            $ld['description'] = mb_strimwidth(strip_tags($p['short'] ?: $p['description']), 0, 300, '…');
        }
        if ($d['gallery'] !== []) {
            $ld['image'] = array_map(fn ($g) => base_url($g['full']), array_slice($d['gallery'], 0, 4));
        }
        if ($d['brand']) {
            $ld['brand'] = ['@type' => 'Brand', 'name' => $d['brand']['name']];
        }
        if ($p['reviews'] > 0) {
            $ld['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => $p['rating'], 'reviewCount' => $p['reviews']];
        }
        $price = $v['price'] ?? $d['pricing']['price'];
        if ($p['show_price'] && $price > 0) {
            $ld['offers'] = [
                '@type' => 'Offer',
                'price' => number_format($price, 2, '.', ''),
                'priceCurrency' => 'INR',
                'url' => base_url($p['url']) . ($v ? '?v=' . rawurlencode($v['sku']) : ''),
                'availability' => $p['status'] === 'coming_soon' ? 'https://schema.org/PreOrder'
                    : ($p['stock'] === 'out' ? 'https://schema.org/BackOrder' : 'https://schema.org/InStock'),
                'seller' => ['@type' => 'Organization', 'name' => (string) business('name', 'Maa Sarada')],
            ];
        }

        return $ld;
    }
}
