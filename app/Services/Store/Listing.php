<?php

namespace App\Services\Store;

use Vayu\Database\DB;

/**
 * Shop listing (docs/storefront.md): filters, facet counts, search, sort and pages.
 *
 *   $state  = Listing::fromQuery($request->query, $category);   // whitelist + clean the query string
 *   $result = Listing::run($state);                              // ids for this page, total, facets, chips
 *
 * How it works: one cached "facet index" holds a small row per listed product (its categories,
 * brand, tags, attribute + option values, cheapest price, flags, search text). Filtering, counting
 * and sorting run in PHP over that index, so the SQL never changes with the query string — no
 * dynamic WHERE clauses, nothing from the URL ever reaches a query. The catalogue is hundreds of
 * products, not millions; the index is a few queries and is cached for config('store.cache_ttl').
 *
 * Query string (all optional, anything else is ignored):
 *   q                  search words (all must match: name, SKU, variant SKU, brand, tags, attributes)
 *   attr[code][]       attribute facet values (colour accepts a family "white" or a stone name)
 *   opt[group][]       option-group values that have no matching attribute
 *   brand[] tag[]      brand slugs, discovery-tag slugs
 *   min max            price per unit (buyable products only)
 *   avail[]            in (ready to order) | price (price shown) | nosoon (hide coming soon)
 *   offer=1            on offer (compare-at price above price)
 *   sort               featured | best | rating | low | high | new
 *   page               1-based
 * Facets are OR inside one facet and AND across facets. Each facet's counts ignore its own filter.
 */
final class Listing
{
    public const PER_PAGE = 24;
    public const SORTS = ['featured' => 'Featured', 'best' => 'Bestselling', 'rating' => 'Top rated', 'low' => 'Price: low to high', 'high' => 'Price: high to low', 'new' => 'Newest'];
    public const AVAIL = ['in' => 'Ready to order', 'price' => 'Price shown online', 'nosoon' => 'Hide coming soon'];
    public const MAX_VALUES = 30;   // per facet, from the URL
    public const MAX_Q = 80;

    /** Facets opened by default (design: type, finish, "good for"). */
    private const OPEN = ['stone_type', 'finish', 'tag'];

    /* ================================================================ state */

    /**
     * Clean the query string into a listing state. Unknown keys and values that are not strings
     * are dropped; counts and lengths are capped.
     *
     * @param array<string,mixed>      $query
     * @param array<string,mixed>|null $category a row from Catalog::categoryTree() (or a child), or null
     * @return array<string,mixed>
     */
    public static function fromQuery(array $query, ?array $category = null): array
    {
        $list = static function ($v): array {
            $v = is_array($v) ? $v : ($v === null || $v === '' ? [] : [$v]);
            $out = [];
            foreach ($v as $x) {
                if (is_string($x) || is_int($x)) {
                    $x = trim(mb_substr((string) $x, 0, 80));
                    if ($x !== '' && !in_array($x, $out, true)) {
                        $out[] = $x;
                    }
                }
            }

            return array_slice($out, 0, self::MAX_VALUES);
        };
        $group = static function ($v) use ($list): array {
            $out = [];
            if (is_array($v)) {
                foreach ($v as $k => $vals) {
                    if (is_string($k) && preg_match('/^[a-z0-9_-]{1,40}$/', $k)) {
                        $vals = $list($vals);
                        if ($vals !== []) {
                            $out[$k] = $vals;
                        }
                    }
                }
            }
            ksort($out);

            return $out;
        };
        $money = static function ($v): string {
            if (!is_string($v) && !is_int($v)) {
                return '';
            }
            $v = trim((string) $v);

            return preg_match('/^\d{1,7}$/', $v) ? (string) (int) $v : '';
        };

        $q = is_string($query['q'] ?? null) ? trim(preg_replace('/\s+/u', ' ', $query['q']) ?? '') : '';
        $sort = is_string($query['sort'] ?? null) && isset(self::SORTS[$query['sort']]) ? $query['sort'] : 'featured';
        $page = is_string($query['page'] ?? null) && ctype_digit($query['page']) ? max(1, min(9999, (int) $query['page'])) : 1;

        return [
            'category' => $category,
            'q' => mb_substr($q, 0, self::MAX_Q),
            'attr' => $group($query['attr'] ?? null),
            'opt' => $group($query['opt'] ?? null),
            'brand' => $list($query['brand'] ?? null),
            'tag' => $list($query['tag'] ?? null),
            'min' => $money($query['min'] ?? null),
            'max' => $money($query['max'] ?? null),
            'avail' => array_values(array_intersect(array_keys(self::AVAIL), $list($query['avail'] ?? null))),
            'offer' => ($query['offer'] ?? '') === '1',
            'sort' => $sort,
            'page' => $page,
        ];
    }

    /* ================================================================ run */

    /**
     * @param array<string,mixed> $state from fromQuery()
     * @return array{ids:list<int>, total:int, page:int, pages:int, facets:list<array>, chips:list<array>, filters:int, price:array}
     */
    public static function run(array $state): array
    {
        $index = self::index();
        $defs = self::facetDefs($index);

        $scope = self::scope($index['rows'], $state);
        $matching = array_values(array_filter($scope, fn ($r) => self::passes($r, $state, $defs, null)));
        usort($matching, self::sorter($state['sort']));

        $total = count($matching);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($state['page'], $pages);
        $slice = array_slice($matching, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        $facets = [];
        foreach ($defs as $key => $def) {
            $counts = [];
            foreach ($scope as $r) {
                if (!self::passes($r, $state, $defs, $key)) {
                    continue;
                }
                foreach (array_unique(self::valuesOf($r, $def)) as $v) {
                    $counts[$v] = ($counts[$v] ?? 0) + 1;
                }
            }
            $selected = self::selected($state, $def);
            $values = [];
            foreach ($def['values'] as $norm => $meta) {
                $on = in_array($norm, $selected, true);
                if (!isset($counts[$norm]) && !$on) {
                    continue;
                }
                $values[] = $meta + ['count' => $counts[$norm] ?? 0, 'on' => $on];
            }
            if ($values === []) {
                continue;
            }
            $facets[] = [
                'key' => $key,
                'label' => $def['label'],
                'param' => $def['param'],
                'swatch' => $def['swatch'],
                'open' => $selected !== [] || in_array($def['code'], self::OPEN, true),
                'selected' => count($selected),
                'values' => $values,
            ];
        }

        // Price range of buyable products in scope (placeholder hints for the min/max inputs).
        $prices = array_map(fn ($r) => $r['price'], array_filter($scope, fn ($r) => $r['buyable']));

        return [
            'ids' => array_map(fn ($r) => $r['id'], $slice),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'facets' => $facets,
            'chips' => self::chips($state, $defs),
            'filters' => self::filterCount($state),
            'price' => ['low' => $prices ? (int) floor(min($prices)) : 0, 'high' => $prices ? (int) ceil(max($prices)) : 0],
        ];
    }

    /** Number of active filters (badge on the mobile "Filters" button). @param array<string,mixed> $state */
    public static function filterCount(array $state): int
    {
        $n = count($state['brand']) + count($state['tag']) + count($state['avail']) + ($state['offer'] ? 1 : 0) + ($state['q'] !== '' ? 1 : 0)
            + ($state['min'] !== '' || $state['max'] !== '' ? 1 : 0);
        foreach (['attr', 'opt'] as $g) {
            foreach ($state[$g] as $vals) {
                $n += count($vals);
            }
        }

        return $n;
    }

    /* ================================================================ URLs */

    /**
     * Canonical query string for a state with optional changes. Empty values are left out and
     * keys come in a fixed order, so the same filters always give the same URL.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $change keys to replace (e.g. ['page' => 2], ['sort' => 'low'])
     */
    public static function query(array $state, array $change = []): string
    {
        $s = array_replace($state, $change);
        $parts = [];
        $add = static function (string $k, string $v) use (&$parts): void {
            $parts[] = $k . '=' . rawurlencode($v);
        };
        if ($s['q'] !== '') {
            $add('q', $s['q']);
        }
        foreach (['attr', 'opt'] as $g) {
            foreach ($s[$g] as $code => $vals) {
                foreach ($vals as $v) {
                    $add($g . '[' . $code . '][]', (string) $v);
                }
            }
        }
        foreach (['brand', 'tag', 'avail'] as $k) {
            foreach ($s[$k] as $v) {
                $add($k . '[]', (string) $v);
            }
        }
        if ($s['min'] !== '') {
            $add('min', $s['min']);
        }
        if ($s['max'] !== '') {
            $add('max', $s['max']);
        }
        if ($s['offer']) {
            $add('offer', '1');
        }
        if ($s['sort'] !== 'featured') {
            $add('sort', $s['sort']);
        }
        if ((int) $s['page'] > 1) {
            $add('page', (string) (int) $s['page']);
        }

        return $parts ? '?' . str_replace(['%5B', '%5D'], ['[', ']'], implode('&', $parts)) : '';
    }

    /** State with one facet value removed (chip "x" links). @param array<string,mixed> $state */
    public static function without(array $state, string $param, string $value = ''): array
    {
        $state['page'] = 1;
        if (preg_match('/^(attr|opt)\[([a-z0-9_-]+)\]$/', $param, $m)) {
            $state[$m[1]][$m[2]] = array_values(array_filter($state[$m[1]][$m[2]] ?? [], fn ($v) => self::norm((string) $v) !== self::norm($value)));
            if ($state[$m[1]][$m[2]] === []) {
                unset($state[$m[1]][$m[2]]);
            }
        } elseif (in_array($param, ['brand', 'tag', 'avail'], true)) {
            $state[$param] = array_values(array_filter($state[$param], fn ($v) => self::norm((string) $v) !== self::norm($value)));
        } elseif ($param === 'price') {
            $state['min'] = $state['max'] = '';
        } elseif ($param === 'q') {
            $state['q'] = '';
        } elseif ($param === 'offer') {
            $state['offer'] = false;
        }

        return $state;
    }

    /** State with every filter cleared (keeps the category and sort). @param array<string,mixed> $state */
    public static function cleared(array $state): array
    {
        return array_replace($state, ['q' => '', 'attr' => [], 'opt' => [], 'brand' => [], 'tag' => [], 'min' => '', 'max' => '', 'avail' => [], 'offer' => false, 'page' => 1]);
    }

    /* ================================================================ index */

    /** Drop the cached facet index (call after catalogue edits when instant refresh matters). */
    public static function forget(): void
    {
        cache()->forget('store.listing.v1');
    }

    /** @return array{rows:list<array>, attributes:list<array>, groups:list<array>, brands:array, tags:array, families:array} */
    public static function index(): array
    {
        $ttl = (int) config('store.cache_ttl', 600);

        return $ttl > 0 ? cache()->remember('store.listing.v1', $ttl, fn () => self::buildIndex()) : self::buildIndex();
    }

    /** @return array<string,mixed> */
    private static function buildIndex(): array
    {
        $rows = [];
        foreach (DB::select('SELECT p.id, p.slug, p.name, p.sku, p.status, p.is_enquiry_only, p.show_price, p.is_featured, p.is_bestseller,
                                    p.short_description, p.brand_id, b.slug AS brand_slug, b.name AS brand_name
                             FROM products p LEFT JOIN brands b ON b.id = p.brand_id AND b.status = \'active\'
                             WHERE p.status IN ' . Catalog::LISTED) as $p) {
            $rows[(int) $p['id']] = [
                'id' => (int) $p['id'],
                'status' => (string) $p['status'],
                'show_price' => (int) $p['show_price'] === 1 && (int) $p['is_enquiry_only'] === 0,
                'featured' => (int) $p['is_featured'] === 1,
                'bestseller' => (int) $p['is_bestseller'] === 1,
                'brand' => $p['brand_slug'] !== null ? (string) $p['brand_slug'] : null,
                'cats' => [],
                'tags' => [],
                'values' => [],      // attribute code / "opt:slug" => list of normalised values
                'price' => 0.0,
                'compare' => null,
                'stock' => null,
                'rating' => 0.0,
                'reviews' => 0,
                'text' => [(string) $p['name'], (string) $p['sku'], (string) ($p['short_description'] ?? ''), (string) ($p['brand_name'] ?? '')],
            ];
        }
        if ($rows === []) {
            return ['rows' => [], 'attributes' => [], 'groups' => [], 'brands' => [], 'tags' => [], 'families' => []];
        }

        // Categories (each product also counts in the parent of its sub-category).
        foreach (DB::select('SELECT m.product_id, m.category_id, c.parent_id, c.name FROM product_category_map m
                             JOIN product_categories c ON c.id = m.category_id WHERE c.is_visible = 1') as $r) {
            if (isset($rows[(int) $r['product_id']])) {
                $row = &$rows[(int) $r['product_id']];
                $row['cats'][] = (int) $r['category_id'];
                if ($r['parent_id'] !== null) {
                    $row['cats'][] = (int) $r['parent_id'];
                }
                $row['text'][] = (string) $r['name'];
                unset($row);
            }
        }

        // Pricing: the product row, replaced by the cheapest active variant when there are variants.
        foreach (DB::select('SELECT product_id, price, compare_at_price FROM product_pricing ORDER BY id DESC') as $r) {
            if (isset($rows[(int) $r['product_id']])) {
                $rows[(int) $r['product_id']]['price'] = (float) $r['price'];
                $rows[(int) $r['product_id']]['compare'] = $r['compare_at_price'] !== null ? (float) $r['compare_at_price'] : null;
            }
        }
        $cheapest = [];
        foreach (DB::select("SELECT id, product_id, sku, title, price, compare_at_price FROM product_variants WHERE status = 'active' ORDER BY sort, id") as $v) {
            $pid = (int) $v['product_id'];
            if (!isset($rows[$pid])) {
                continue;
            }
            $rows[$pid]['text'][] = (string) $v['sku'];
            $rows[$pid]['text'][] = (string) ($v['title'] ?? '');
            if (!isset($cheapest[$pid]) || (float) $v['price'] < $cheapest[$pid][0]) {
                $cheapest[$pid] = [(float) $v['price'], $v['compare_at_price'] !== null ? (float) $v['compare_at_price'] : null];
            }
            if ($v['compare_at_price'] !== null && (float) $v['compare_at_price'] > (float) $v['price']) {
                $rows[$pid]['variant_offer'] = true;
            }
        }
        foreach ($cheapest as $pid => [$price, $compare]) {
            $rows[$pid]['price'] = $price;
            $rows[$pid]['compare'] = $compare;
        }

        // Attributes shown as facets, and their library values (label, swatch, colour family).
        $attributes = [];
        foreach (DB::select("SELECT id, code, name, type FROM attributes WHERE status = 'active' AND used_for IN ('filters','variants_filters') ORDER BY sort, name") as $a) {
            $attributes[(int) $a['id']] = ['id' => (int) $a['id'], 'code' => (string) $a['code'], 'name' => (string) $a['name'], 'values' => []];
        }
        $families = [];
        foreach (DB::select('SELECT attribute_id, label, swatch, family, sort FROM attribute_values ORDER BY sort, label') as $v) {
            if (isset($attributes[(int) $v['attribute_id']])) {
                $attributes[(int) $v['attribute_id']]['values'][self::norm((string) $v['label'])] = [
                    'label' => (string) $v['label'], 'swatch' => (string) ($v['swatch'] ?? ''), 'family' => trim((string) ($v['family'] ?? '')),
                ];
                if ($attributes[(int) $v['attribute_id']]['code'] === 'colour' && trim((string) $v['family']) !== '') {
                    $families[self::norm((string) $v['label'])] = self::norm((string) $v['family']);
                }
            }
        }
        $byAttr = [];
        foreach ($attributes as $a) {
            $byAttr[$a['id']] = $a['code'];
        }
        $seen = [];   // attribute code => norm => display label actually used by products
        foreach (DB::select('SELECT pav.product_id, pav.attribute_id, pav.value, a.name FROM product_attribute_values pav
                             JOIN attributes a ON a.id = pav.attribute_id') as $r) {
            $pid = (int) $r['product_id'];
            if (!isset($rows[$pid])) {
                continue;
            }
            foreach (array_map('trim', explode(',', (string) $r['value'])) as $val) {
                if ($val === '') {
                    continue;
                }
                $rows[$pid]['text'][] = $val;
                $code = $byAttr[(int) $r['attribute_id']] ?? null;
                if ($code !== null) {
                    $rows[$pid]['values'][$code][] = self::norm($val);
                    $seen[$code][self::norm($val)] ??= $val;
                }
            }
        }

        // Option groups: merged into the attribute with the same name (Finish, Thickness, Size,
        // Grade), otherwise a facet of their own ("opt:slug"). Active variants only.
        $attrByName = [];
        foreach ($attributes as $a) {
            $attrByName[self::norm($a['name'])] = $a['code'];
        }
        $groups = [];
        foreach (DB::select("SELECT DISTINCT v.product_id, g.id AS gid, g.name AS gname, g.slug AS gslug, g.sort AS gsort, ov.value, ov.sort AS vsort
                             FROM product_variants v
                             JOIN product_variant_values vv ON vv.variant_id = v.id
                             JOIN option_values ov ON ov.id = vv.option_value_id
                             JOIN option_groups g ON g.id = ov.option_group_id
                             WHERE v.status = 'active' ORDER BY g.sort, g.name, ov.sort, ov.value") as $r) {
            $pid = (int) $r['product_id'];
            if (!isset($rows[$pid])) {
                continue;
            }
            $val = trim((string) $r['value']);
            if ($val === '') {
                continue;
            }
            $code = $attrByName[self::norm((string) $r['gname'])] ?? null;
            if ($code === null) {
                $slug = preg_replace('/[^a-z0-9_-]+/', '-', mb_strtolower((string) ($r['gslug'] ?: $r['gname']))) ?: 'option';
                $code = 'opt:' . $slug;
                $groups[$slug] ??= ['slug' => $slug, 'name' => (string) $r['gname']];
            }
            $rows[$pid]['values'][$code][] = self::norm($val);
            $seen[$code][self::norm($val)] ??= $val;
            $rows[$pid]['text'][] = $val;
        }

        // Discovery tags + merchandising tags (text search); only discovery tags are a facet.
        $tags = [];
        foreach (DB::select("SELECT pt.product_id, t.slug, t.name, t.purpose FROM product_tags pt JOIN tags t ON t.id = pt.tag_id
                             WHERE t.status = 'active' AND t.purpose IN ('discovery','merchandising') ORDER BY t.name") as $r) {
            $pid = (int) $r['product_id'];
            if (!isset($rows[$pid])) {
                continue;
            }
            $rows[$pid]['text'][] = (string) $r['name'];
            if ($r['purpose'] === 'discovery') {
                $rows[$pid]['tags'][] = self::norm((string) $r['slug']);
                $tags[self::norm((string) $r['slug'])] = (string) $r['name'];
            }
        }

        $brands = [];
        foreach (DB::select("SELECT slug, name FROM brands WHERE status = 'active' ORDER BY name") as $b) {
            $brands[self::norm((string) $b['slug'])] = (string) $b['name'];
        }

        foreach (DB::select("SELECT product_id, status FROM inventory_stock") as $r) {
            $pid = (int) $r['product_id'];
            if (isset($rows[$pid])) {
                $s = (string) $r['status'];
                $prev = $rows[$pid]['stock'];
                if ($prev === null || $s === 'out' || ($s === 'low' && $prev === 'healthy')) {
                    $rows[$pid]['stock'] = $s;
                }
            }
        }
        foreach (DB::select("SELECT product_id, AVG(rating) AS a, COUNT(*) AS n FROM product_reviews WHERE status = 'published' GROUP BY product_id") as $r) {
            if (isset($rows[(int) $r['product_id']])) {
                $rows[(int) $r['product_id']]['rating'] = round((float) $r['a'], 2);
                $rows[(int) $r['product_id']]['reviews'] = (int) $r['n'];
            }
        }

        foreach ($rows as &$row) {
            $row['cats'] = array_values(array_unique($row['cats']));
            $row['tags'] = array_values(array_unique($row['tags']));
            foreach ($row['values'] as $k => $vals) {
                $row['values'][$k] = array_values(array_unique($vals));
            }
            $row['buyable'] = $row['status'] === 'active' && $row['show_price'] && $row['price'] > 0;
            $row['offer'] = $row['buyable'] && (($row['compare'] !== null && $row['compare'] > $row['price']) || !empty($row['variant_offer']));
            $row['text'] = mb_strtolower(implode(' ', $row['text']));
            unset($row['variant_offer']);
        }
        unset($row);

        // Facet value lists in display order: library values first (by sort), then any others products use.
        $attrList = [];
        foreach ($attributes as $a) {
            $attrList[] = $a + ['seen' => $seen[$a['code']] ?? []];
        }
        $groupList = [];
        foreach ($groups as $g) {
            $groupList[] = $g + ['seen' => $seen['opt:' . $g['slug']] ?? []];
        }

        return [
            'rows' => array_values($rows),
            'attributes' => $attrList,
            'groups' => $groupList,
            'brands' => $brands,
            'tags' => $tags,
            'families' => $families,
        ];
    }

    /**
     * Facet definitions: key => [label, param, code, swatch, values norm => {value, label, swatch}].
     *
     * @param array<string,mixed> $index
     * @return array<string, array<string,mixed>>
     */
    private static function facetDefs(array $index): array
    {
        $defs = [];
        foreach ($index['attributes'] as $a) {
            if ($a['seen'] === []) {
                continue;
            }
            $values = [];
            if ($a['code'] === 'colour' && $index['families'] !== []) {
                // Colour: group stone names into families; names without a family stay as they are.
                foreach ($a['seen'] as $norm => $label) {
                    $fam = $index['families'][$norm] ?? null;
                    $key = $fam ?? $norm;
                    if (!isset($values[$key])) {
                        $values[$key] = ['value' => $fam !== null ? $fam : $label, 'label' => $fam !== null ? mb_convert_case($fam, MB_CASE_TITLE) : $label,
                            'swatch' => self::swatchFor($a, $fam, $norm)];
                    }
                }
            } else {
                $order = array_keys($a['values']);
                $seen = $a['seen'];
                uksort($seen, function ($x, $y) use ($order, $seen) {
                    $ix = array_search($x, $order, true);
                    $iy = array_search($y, $order, true);
                    if ($ix !== false || $iy !== false) {
                        return ($ix === false ? PHP_INT_MAX : $ix) <=> ($iy === false ? PHP_INT_MAX : $iy);
                    }

                    return strnatcasecmp($seen[$x], $seen[$y]);
                });
                foreach ($seen as $norm => $label) {
                    $values[$norm] = ['value' => $label, 'label' => $a['values'][$norm]['label'] ?? $label, 'swatch' => $a['values'][$norm]['swatch'] ?? ''];
                }
            }
            $defs['attr:' . $a['code']] = [
                'label' => $a['name'], 'param' => 'attr[' . $a['code'] . ']', 'code' => $a['code'], 'kind' => 'attr',
                'swatch' => $a['code'] === 'colour', 'values' => $values, 'colour' => $a['code'] === 'colour',
                'families' => $a['code'] === 'colour' ? $index['families'] : [],
            ];
        }
        foreach ($index['groups'] as $g) {
            $values = [];
            $seen = $g['seen'];
            uasort($seen, 'strnatcasecmp');
            foreach ($seen as $norm => $label) {
                $values[$norm] = ['value' => $label, 'label' => $label, 'swatch' => ''];
            }
            $defs['opt:' . $g['slug']] = ['label' => $g['name'], 'param' => 'opt[' . $g['slug'] . ']', 'code' => $g['slug'], 'kind' => 'opt',
                'swatch' => false, 'values' => $values, 'colour' => false];
        }
        if ($index['tags'] !== []) {
            $values = [];
            foreach ($index['tags'] as $slug => $name) {
                $values[$slug] = ['value' => $slug, 'label' => $name, 'swatch' => ''];
            }
            $defs['tag'] = ['label' => 'Good for', 'param' => 'tag', 'code' => 'tag', 'kind' => 'tag', 'swatch' => false, 'values' => $values, 'colour' => false];
        }
        if ($index['brands'] !== []) {
            $values = [];
            foreach ($index['brands'] as $slug => $name) {
                $values[$slug] = ['value' => $slug, 'label' => $name, 'swatch' => ''];
            }
            $defs['brand'] = ['label' => 'Brand', 'param' => 'brand', 'code' => 'brand', 'kind' => 'brand', 'swatch' => false, 'values' => $values, 'colour' => false];
        }

        return $defs;
    }

    /** @param array<string,mixed> $attr */
    private static function swatchFor(array $attr, ?string $family, string $norm): string
    {
        if ($family !== null) {
            foreach ($attr['values'] as $v) {
                if (self::norm($v['family']) === $family && $v['swatch'] !== '') {
                    return $v['swatch'];
                }
            }
        }

        return $attr['values'][$norm]['swatch'] ?? '';
    }

    /* ================================================================ filtering */

    /** Products in the category / search / offer scope (the base the facet counts are taken in). @return list<array> */
    private static function scope(array $rows, array $state): array
    {
        $cat = $state['category'] !== null ? (int) $state['category']['id'] : null;
        $words = $state['q'] === '' ? [] : array_slice(array_filter(explode(' ', mb_strtolower($state['q'])), fn ($w) => $w !== ''), 0, 8);

        return array_values(array_filter($rows, function ($r) use ($cat, $words, $state) {
            if ($cat !== null && !in_array($cat, $r['cats'], true)) {
                return false;
            }
            foreach ($words as $w) {
                if (!str_contains($r['text'], $w)) {
                    return false;
                }
            }

            return !$state['offer'] || $r['offer'];
        }));
    }

    /** Does a row pass every filter except $skip (a facet key, 'price' or 'avail')? */
    private static function passes(array $r, array $state, array $defs, ?string $skip): bool
    {
        foreach ($defs as $key => $def) {
            if ($key === $skip) {
                continue;
            }
            $want = self::selected($state, $def);
            if ($want === []) {
                continue;
            }
            if (array_intersect($want, self::valuesOf($r, $def)) === []) {
                return false;
            }
        }
        if ($skip !== 'price' && ($state['min'] !== '' || $state['max'] !== '')) {
            if (!$r['buyable']) {
                return false;
            }
            if ($state['min'] !== '' && $r['price'] < (float) $state['min']) {
                return false;
            }
            if ($state['max'] !== '' && $r['price'] > (float) $state['max']) {
                return false;
            }
        }
        if ($skip !== 'avail') {
            if (in_array('in', $state['avail'], true) && !($r['status'] === 'active' && $r['stock'] !== 'out')) {
                return false;
            }
            if (in_array('price', $state['avail'], true) && !$r['buyable']) {
                return false;
            }
            if (in_array('nosoon', $state['avail'], true) && $r['status'] === 'coming_soon') {
                return false;
            }
        }

        return true;
    }

    /** A row's normalised values for one facet (colour stone names become their family). @return list<string> */
    private static function valuesOf(array $r, array $def): array
    {
        return match ($def['kind']) {
            'tag' => $r['tags'],
            'brand' => $r['brand'] !== null ? [self::norm($r['brand'])] : [],
            'opt' => $r['values']['opt:' . $def['code']] ?? [],
            default => $def['colour']
                ? array_map(fn ($v) => isset($def['values'][$v]) ? $v : self::familyKey($def, $v), $r['values'][$def['code']] ?? [])
                : ($r['values'][$def['code']] ?? []),
        };
    }

    /** Colour stone name → the facet key it is listed under (its family). */
    private static function familyKey(array $def, string $norm): string
    {
        foreach ($def['values'] as $key => $meta) {
            if (self::norm((string) $meta['value']) === $norm) {
                return $key;
            }
        }
        return $def['families'][$norm] ?? $norm;
    }

    /** Normalised selected values of one facet (colour: a stone name in the URL selects its family). @return list<string> */
    private static function selected(array $state, array $def): array
    {
        $raw = match ($def['kind']) {
            'attr' => $state['attr'][$def['code']] ?? [],
            'opt' => $state['opt'][$def['code']] ?? [],
            'tag' => $state['tag'],
            'brand' => $state['brand'],
            default => [],
        };
        $out = [];
        foreach ($raw as $v) {
            $n = self::norm((string) $v);
            if ($def['colour'] && !isset($def['values'][$n])) {
                $n = self::familyKey($def, $n);
            }
            $out[] = $n;
        }

        return array_values(array_unique($out));
    }

    /** @return \Closure(array,array):int */
    private static function sorter(string $sort): \Closure
    {
        $soon = fn ($r) => (int) ($r['status'] === 'coming_soon');

        return match ($sort) {
            'best' => fn ($a, $b) => [$b['bestseller'], $b['reviews'], $b['id']] <=> [$a['bestseller'], $a['reviews'], $a['id']],
            'rating' => fn ($a, $b) => [$b['rating'], $b['reviews'], $b['id']] <=> [$a['rating'], $a['reviews'], $a['id']],
            'low' => fn ($a, $b) => [$b['buyable'], $a['price'], $b['id']] <=> [$a['buyable'], $b['price'], $a['id']],
            'high' => fn ($a, $b) => [$b['buyable'], $b['price'], $b['id']] <=> [$a['buyable'], $a['price'], $a['id']],
            'new' => fn ($a, $b) => $b['id'] <=> $a['id'],
            // featured first, coming soon last, then bestsellers, most reviewed, newest
            default => fn ($a, $b) => [$soon($a), $b['featured'], $b['bestseller'], $b['reviews'], $b['id']]
                <=> [$soon($b), $a['featured'], $a['bestseller'], $a['reviews'], $a['id']],
        };
    }

    /** Active-filter chips with the URL that removes each one. @return list<array{label:string, query:string}> */
    private static function chips(array $state, array $defs): array
    {
        $chips = [];
        $add = function (string $label, string $param, string $value = '') use (&$chips, $state): void {
            $chips[] = ['label' => $label, 'query' => self::query(self::without($state, $param, $value))];
        };
        if ($state['q'] !== '') {
            $add('“' . $state['q'] . '”', 'q');
        }
        if ($state['offer']) {
            $add('On offer', 'offer');
        }
        foreach ($defs as $def) {
            $raw = match ($def['kind']) {
                'attr' => $state['attr'][$def['code']] ?? [],
                'opt' => $state['opt'][$def['code']] ?? [],
                'tag' => $state['tag'],
                'brand' => $state['brand'],
                default => [],
            };
            foreach ($raw as $v) {
                $n = self::norm((string) $v);
                $key = $def['colour'] && !isset($def['values'][$n]) ? self::familyKey($def, $n) : $n;
                $label = $def['values'][$key]['label'] ?? (string) $v;
                $add($label, $def['param'], (string) $v);
            }
        }
        if ($state['min'] !== '' || $state['max'] !== '') {
            $add(self::rupees((int) ($state['min'] ?: 0)) . ' – ' . ($state['max'] !== '' ? self::rupees((int) $state['max']) : 'any'), 'price');
        }
        foreach ($state['avail'] as $a) {
            $add(self::AVAIL[$a], 'avail', $a);
        }

        return $chips;
    }

    private static function rupees(int $v): string
    {
        return \App\Services\Order\Money::inr($v, 0);
    }

    public static function norm(string $v): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $v) ?? ''));
    }
}
