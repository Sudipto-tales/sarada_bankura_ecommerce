<?php

use App\Controllers\Store\EnquiryController;
use App\Services\Store\Enquiries;
use App\Services\Store\Listing;
use App\Services\Store\ProductPage;
use App\Services\Store\View;
use Vayu\Application;
use Vayu\Database\DB;

/** Stage 8 S2: shop listing (filters / facets / search / sort / pages), product page data, enquiries. */
return function (Assert $assert): void {
    $app = new Application(dirname(__DIR__));
    $app->config()->set('database.default', 'sqlite');
    $app->config()->set('database.connections.sqlite.database', ':memory:');
    $app->config()->set('cache.default', 'array');
    $app->config()->set('store.cache_ttl', 0);
    $files = glob(dirname(__DIR__) . '/database/migrations/2026_*.php');
    sort($files);
    foreach ($files as $f) {
        ob_start();
        (require $f)->up();
        ob_end_clean();
    }
    View::loadHelpers();

    // ---------------------------------------------------------------- fixtures
    $now = date('Y-m-d H:i:s');
    $cat = static function (string $name, string $slug, ?int $parent = null) use ($now): int {
        return (int) DB::table('product_categories')->insertGetId(['name' => $name, 'slug' => $slug, 'parent_id' => $parent, 'is_visible' => 1,
            'is_featured' => 0, 'sort' => 0, 'created_at' => $now, 'updated_at' => $now]);
    };
    $floor = $cat('Flooring', 'flooring');
    $living = $cat('Living Room', 'flooring-living-room', $floor);
    $bath = $cat('Bathroom', 'flooring-bathroom', $floor);
    $walls = $cat('Walls', 'walls');
    $brandA = (int) DB::table('brands')->insertGetId(['name' => 'Carrara Co', 'slug' => 'carrara-co', 'tier' => 'signature', 'status' => 'active', 'is_featured' => 0, 'created_at' => $now, 'updated_at' => $now]);
    $brandB = (int) DB::table('brands')->insertGetId(['name' => 'Rajasthan Quarry', 'slug' => 'rajasthan', 'tier' => 'core', 'status' => 'active', 'is_featured' => 0, 'created_at' => $now, 'updated_at' => $now]);

    $attr = static function (string $code, string $name, string $usedFor, int $sort) use ($now): int {
        return (int) DB::table('attributes')->insertGetId(['code' => $code, 'name' => $name, 'type' => 'select', 'display' => 'text', 'used_for' => $usedFor,
            'status' => 'active', 'sort' => $sort, 'created_at' => $now, 'updated_at' => $now]);
    };
    $aFinish = $attr('finish', 'Finish', 'variants_filters', 1);
    $aColour = $attr('colour', 'Colour', 'variants_filters', 2);
    $aOrigin = $attr('origin', 'Origin', 'filters', 3);
    $aEdge = $attr('edge_profile', 'Edge profile', 'specs', 4);
    foreach ([['Statuario', '#f4f1ea', 'white'], ['Carrara', '#eceae4', 'white'], ['Nero Marquina', '#1d1d1d', 'black'], ['Botticino', '#e3d5bb', null]] as $i => [$l, $sw, $fam]) {
        DB::table('attribute_values')->insert(['attribute_id' => $aColour, 'label' => $l, 'swatch' => $sw, 'family' => $fam, 'sort' => $i]);
    }
    $gFinish = (int) DB::table('option_groups')->insertGetId(['name' => 'Finish', 'slug' => 'finish', 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $gSize = (int) DB::table('option_groups')->insertGetId(['name' => 'Slab size', 'slug' => 'slab-size', 'sort' => 2, 'created_at' => $now, 'updated_at' => $now]);
    $ov = static fn(int $g, string $v): int => (int) DB::table('option_values')->insertGetId(['option_group_id' => $g, 'value' => $v, 'sort' => 0]);
    $oPol = $ov($gFinish, 'Polished');
    $oHon = $ov($gFinish, 'Honed');
    $oBig = $ov($gSize, '8 x 4 ft');
    $oSmall = $ov($gSize, '4 x 2 ft');
    $tag = static fn(string $slug, string $purpose): int => (int) DB::table('tags')->insertGetId(['name' => ucfirst(str_replace('-', ' ', $slug)), 'slug' => $slug, 'purpose' => $purpose, 'status' => 'active']);
    $tHeat = $tag('heat-resistant', 'discovery');
    $tVastu = $tag('vastu', 'discovery');
    $tSecret = $tag('clearance', 'internal');

    $product = static function (string $name, array $p = [], ?array $pricing = []) use ($now, $brandA): int {
        $id = (int) DB::table('products')->insertGetId($p + ['product_type_id' => 1, 'status' => 'active', 'sku' => 'SKU-' . strtoupper(substr(md5($name), 0, 6)),
            'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'brand_id' => $brandA, 'is_enquiry_only' => 0, 'show_price' => 1,
            'is_featured' => 0, 'is_bestseller' => 0, 'created_at' => $now, 'updated_at' => $now]);
        if ($pricing !== null) {
            DB::table('product_pricing')->insert($pricing + ['product_id' => $id, 'price' => 100, 'compare_at_price' => null, 'currency' => 'INR', 'unit' => 'sq ft']);
        }

        return $id;
    };
    $pav = static fn(int $pid, int $aid, string $v) => DB::table('product_attribute_values')->insert(['product_id' => $pid, 'attribute_id' => $aid, 'value' => $v]);
    $map = static fn(int $pid, int $cid, int $primary = 1) => DB::table('product_category_map')->insert(['product_id' => $pid, 'category_id' => $cid, 'is_primary' => $primary]);

    $statuario = $product('Statuario Marble', ['is_featured' => 1, 'short_description' => 'Bright white Italian marble'], ['price' => 640, 'compare_at_price' => 800]);
    $pav($statuario, $aColour, 'Statuario');
    $pav($statuario, $aOrigin, 'Italy');
    $pav($statuario, $aFinish, 'Polished');
    $pav($statuario, $aEdge, 'Bullnose');
    $map($statuario, $living);
    $vars = [];
    foreach ([['STA-POL-8', 'Polished · 8x4', 700, 900, [$oPol, $oBig], 'active', 1], ['STA-HON-8', 'Honed · 8x4', 680, null, [$oHon, $oBig], 'active', 0],
              ['STA-POL-4', 'Polished · 4x2', 600, null, [$oPol, $oSmall], 'active', 0], ['STA-HON-4', 'Honed · 4x2', 550, null, [$oHon, $oSmall], 'inactive', 0]] as $i => [$sku, $t, $pr, $cmp, $vals, $st, $def]) {
        $vid = (int) DB::table('product_variants')->insertGetId(['product_id' => $statuario, 'sku' => $sku, 'title' => $t, 'price' => $pr, 'compare_at_price' => $cmp,
            'status' => $st, 'is_default' => $def, 'sort' => $i, 'created_at' => $now, 'updated_at' => $now]);
        foreach ($vals as $o) {
            DB::table('product_variant_values')->insert(['variant_id' => $vid, 'option_value_id' => $o]);
        }
        $vars[$sku] = $vid;
    }
    $nero = $product('Nero Marquina', ['brand_id' => $brandB, 'is_bestseller' => 1], ['price' => 320]);
    $pav($nero, $aColour, 'Nero Marquina');
    $pav($nero, $aFinish, 'Honed');
    $pav($nero, $aOrigin, 'Spain');
    $map($nero, $bath);
    $botti = $product('Botticino Classic', [], ['price' => 180, 'compare_at_price' => 240]);
    $pav($botti, $aColour, 'Botticino');
    $map($botti, $living);
    $map($botti, $walls, 0);
    $onyx = $product('Honey Onyx', ['is_enquiry_only' => 1, 'show_price' => 0], ['price' => 2400]);
    $pav($onyx, $aColour, 'Carrara');
    $map($onyx, $walls);
    $soon = $product('Soon Granite', ['status' => 'coming_soon'], ['price' => 400]);
    $map($soon, $bath);
    $draft = $product('Draft Stone', ['status' => 'draft'], ['price' => 50]);
    $map($draft, $living);
    foreach ([[$statuario, $tHeat], [$nero, $tHeat], [$nero, $tVastu], [$botti, $tSecret], [$draft, $tHeat]] as [$pid, $tid]) {
        DB::table('product_tags')->insert(['product_id' => $pid, 'tag_id' => $tid]);
    }
    foreach ([[$nero, 5], [$nero, 5], [$statuario, 3]] as [$pid, $r]) {
        DB::table('product_reviews')->insert(['product_id' => $pid, 'customer_name' => 'Asha Roy', 'rating' => $r, 'title' => 'Nice', 'body' => 'Good stone for our floor.',
            'application' => $r === 5 ? 'Flooring' : 'Kitchen countertop', 'source' => 'storefront', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now]);
    }

    $run = static function (array $q, ?int $catId = null): array {
        $c = null;
        if ($catId !== null) {
            foreach (\App\Services\Store\Catalog::categoryTree() as $t) {
                if ($t['id'] === $catId) {
                    $c = $t;
                }
                foreach ($t['children'] as $ch) {
                    if ($ch['id'] === $catId) {
                        $c = $ch;
                    }
                }
            }
        }

        return Listing::run(Listing::fromQuery($q, $c));
    };
    $ids = static fn(array $r): array => (function ($a) { sort($a); return $a; })($r['ids']);
    $facet = static function (array $r, string $key): array {
        foreach ($r['facets'] as $f) {
            if ($f['key'] === $key) {
                return array_column($f['values'], 'count', 'value');
            }
        }

        return [];
    };

    // ---------------------------------------------------------------- query string whitelist
    $s = Listing::fromQuery(['q' => '  white   marble ', 'sort' => 'evil', 'page' => 'abc', 'min' => '-5', 'max' => '1e3', 'attr' => ['finish' => ['Polished', ['x']], 'bad key' => ['x'], 'colour' => 'white'],
        'avail' => ['in', 'hack'], 'offer' => '1', 'brand' => str_repeat('x', 500), 'junk' => 'y', 'tag' => [['nested']]]);
    $assert->equals('state: q trimmed + squashed', 'white marble', $s['q']);
    $assert->that('state: unknown sort → featured, bad page → 1', $s['sort'] === 'featured' && $s['page'] === 1);
    $assert->that('state: non-digit prices dropped', $s['min'] === '' && $s['max'] === '');
    $assert->equals('state: attr keys whitelisted, nested arrays dropped, scalar accepted', ['colour' => ['white'], 'finish' => ['Polished']], $s['attr']);
    $assert->equals('state: avail values whitelisted', ['in'], $s['avail']);
    $assert->that('state: long value capped, nested tag dropped, junk ignored', mb_strlen($s['brand'][0]) === 80 && $s['tag'] === [] && !isset($s['junk']));
    $assert->equals('query: canonical order, empty parts left out', '?q=white%20marble&attr[colour][]=white&attr[finish][]=Polished&brand[]=' . str_repeat('x', 80) . '&avail[]=in&offer=1', Listing::query($s));
    $assert->equals('query: default state = no query string', '', Listing::query(Listing::fromQuery([])));

    // ---------------------------------------------------------------- scope + filters
    $all = $run([]);
    $assert->equals('listing: listed products only (draft hidden)', 5, $all['total']);
    $assert->that('listing: featured first, coming soon last', $all['ids'][0] === $statuario && end($all['ids']) === $soon);
    $assert->equals('category: room scope', [$statuario, $botti], $ids($run([], $living)));
    $assert->equals('category: space includes its rooms', [$statuario, $nero, $botti, $soon], $ids($run([], $floor)));
    $assert->equals('attr: product attribute value', [$nero], $ids($run(['attr' => ['origin' => ['Spain']]])));
    $assert->equals('attr: option group "Finish" merged into the Finish facet (variant Honed)', [$statuario, $nero], $ids($run(['attr' => ['finish' => ['honed']]])));
    $assert->equals('attr: inactive variant values do not count', [], $ids($run(['opt' => ['slab-size' => ['nothing']]])));
    $assert->equals('opt: group without an attribute is its own facet', [$statuario], $ids($run(['opt' => ['slab-size' => ['4 x 2 ft']]])));
    $assert->equals('facet: OR inside one facet', [$nero, $botti], $ids($run(['attr' => ['colour' => ['black', 'Botticino']]])));
    $assert->equals('facet: AND across facets', [$nero], $ids($run(['attr' => ['finish' => ['Honed']], 'tag' => ['vastu']])));
    $assert->equals('colour: family groups stone names', [$statuario, $onyx], $ids($run(['attr' => ['colour' => ['white']]])));
    $assert->equals('colour: a stone name selects its family (home finder links)', [$statuario, $onyx], $ids($run(['attr' => ['colour' => ['Statuario']]])));
    $colours = $facet($all, 'attr:colour');
    $assert->equals('colour facet: families + names without a family', ['white' => 2, 'black' => 1, 'Botticino' => 1], $colours);
    $assert->equals('brand filter', [$nero], $ids($run(['brand' => ['rajasthan']])));
    $assert->equals('tag filter: discovery tags only (internal tag is not a facet)', [], $ids($run(['tag' => ['clearance']])));
    $assert->that('tag facet lists discovery tags only', !isset($facet($all, 'tag')['clearance']) && $facet($all, 'tag')['heat-resistant'] === 2);
    $r = $run(['attr' => ['origin' => ['Spain']]]);
    $assert->equals('facet counts ignore their own filter', ['Italy' => 1, 'Spain' => 1], $facet($r, 'attr:origin'));
    $assert->equals('facet counts follow other filters', ['black' => 1], $facet($r, 'attr:colour'));
    $assert->equals('price: only buyable products inside the range', [$nero, $botti], $ids($run(['min' => '100', 'max' => '400'])));
    $assert->equals('price: variant (cheapest active) price used', [$statuario], $ids($run(['min' => '590', 'max' => '610'])));
    $assert->equals('avail=price hides enquiry-only and coming soon', [$statuario, $nero, $botti], $ids($run(['avail' => ['price']])));
    $assert->equals('avail=nosoon', 4, $run(['avail' => ['nosoon']])['total']);
    $assert->equals('offer: compare-at above price (product or variant)', [$statuario, $botti], $ids($run(['offer' => '1'])));
    $assert->equals('search: variant SKU', [$statuario], $ids($run(['q' => 'sta-hon'])));
    $assert->equals('search: brand name', [$nero], $ids($run(['q' => 'rajasthan'])));
    $assert->equals('search: every word must match', [$statuario], $ids($run(['q' => 'white italian'])));
    $assert->equals('search: internal tag names are not searchable', [], $ids($run(['q' => 'clearance'])));
    $assert->equals('injection-looking values just match nothing', 0, $run(['q' => "' OR 1=1 --", 'attr' => ['finish' => ["x' OR '1'='1"]]])['total']);
    $assert->equals('sort low: buyable first, cheapest first', [$botti, $nero, $statuario], array_slice($run(['sort' => 'low'])['ids'], 0, 3));
    $assert->equals('sort high', $statuario, $run(['sort' => 'high'])['ids'][0]);
    $assert->equals('sort rating', $nero, $run(['sort' => 'rating'])['ids'][0]);
    $chips = $run(['attr' => ['colour' => ['white']], 'min' => '100'])['chips'];
    $assert->equals('chips: labels + remove links', [['White', '?min=100'], ['₹100 – any', '?attr[colour][]=white']], array_map(fn ($c) => [$c['label'], $c['query']], $chips));
    $assert->equals('filter count badge', 3, Listing::filterCount(Listing::fromQuery(['q' => 'a', 'attr' => ['colour' => ['white', 'black']]])));

    for ($i = 0; $i < 30; $i++) {
        $pid = $product('Bulk Stone ' . $i, [], ['price' => 100 + $i]);
        $map($pid, $walls);
    }
    $r = $run(['page' => '2'], $walls);
    $assert->that('pages: 24 per page', $r['total'] === 32 && $r['pages'] === 2 && count($r['ids']) === 8 && $r['page'] === 2);
    $assert->equals('pages: beyond the last page clamps to it', 2, $run(['page' => '99'], $walls)['page']);

    // ---------------------------------------------------------------- product page
    $assert->that('pdp: draft / unknown → null', ProductPage::load('draft-stone') === null && ProductPage::load('nope') === null);
    $assert->that('pdp: coming soon is listed', ProductPage::load('soon-granite') !== null);
    $d = ProductPage::load('statuario-marble');
    $assert->equals('pdp: default variant selected', 'STA-POL-8', $d['selected']['sku']);
    $assert->equals('pdp: ?v= picks the variant', 'STA-HON-8', ProductPage::load('statuario-marble', 'sta-hon-8')['selected']['sku']);
    $assert->equals('pdp: inactive SKU ignored → default', 'STA-POL-8', ProductPage::load('statuario-marble', 'STA-HON-4')['selected']['sku']);
    $assert->that('pdp: inactive variant listed (struck) but archived never', count($d['variants']) === 4 && !$d['variants'][3]['active']);
    $assert->equals('pdp: option groups in use, ordered', ['Finish', 'Slab size'], array_column($d['groups'], 'name'));
    $assert->that('pdp: option values are a list (JSON array for the picker)', array_is_list($d['groups'][0]['values']) && array_column($d['groups'][0]['values'], 'value') === ['Honed', 'Polished']);
    $assert->equals('pdp: variant overrides the matching spec row', ['Finish' => 'Honed', 'Slab size' => '8 x 4 ft'], $d['overrides'][$vars['STA-HON-8']]);
    $assert->that('pdp: specs include spec-only attributes', in_array('Edge profile', array_column($d['specs'], 'label'), true));
    $assert->that('pdp: breadcrumb = primary room + its space', $d['primary']['id'] === $living && $d['parent']['id'] === $floor && $d['primary']['url'] === '/shop/flooring/flooring-living-room');
    $assert->that('pdp: buyable, discovery tags only', $d['product']['buyable'] && array_column($d['tags'], 'slug') === ['heat-resistant']);
    $assert->that('pdp: related excludes itself and drafts', !in_array($statuario, array_column($d['related'], 'id'), true) && !in_array($draft, array_column($d['related'], 'id'), true)
        && ($d['related'][0]['id'] ?? 0) === $botti);
    $assert->that('pdp: no return policy row → null', $d['returns'] === null);
    DB::table('product_return_policies')->insert(['product_id' => $statuario, 'variant_id' => 0, 'return_allowed' => 0, 'replacement_allowed' => 1, 'return_days' => 0, 'replacement_days' => 7, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('product_return_policies')->insert(['product_id' => $statuario, 'variant_id' => $vars['STA-HON-8'], 'return_allowed' => 1, 'replacement_allowed' => 1, 'return_days' => 3, 'replacement_days' => 7, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $assert->that('pdp: product return row, variant row wins for that variant', ProductPage::load('statuario-marble')['returns']['return'] === false
        && ProductPage::load('statuario-marble', 'STA-HON-8')['returns']['return_days'] === 3);
    $ld = ProductPage::jsonLd($d);
    $assert->that('json-ld: offer with the selected variant price', $ld['offers']['price'] === '700.00' && $ld['offers']['priceCurrency'] === 'INR');
    $assert->that('json-ld: no offer when the price is hidden', !isset(ProductPage::jsonLd(ProductPage::load('honey-onyx'))['offers']));
    $reviews = $d['reviews'];
    $assert->that('pdp: published reviews summary', $reviews['count'] === 1 && $reviews['avg'] === 3.0 && $reviews['items'][0]['name'] === 'Asha R.');

    $html = View::html('store.product', $d + ['stamp' => Enquiries::stamp(), 'enquiry' => ['errors' => [], 'old' => [], 'thanks' => null]], []);
    $assert->that('view pdp: selected variant rendered server-side (works without JS)', str_contains($html, 'STA-POL-8') && str_contains($html, '₹700'));
    $assert->that('view pdp: variant rows are links (?v=SKU), inactive row is not', str_contains($html, '?v=STA-HON-8') && !str_contains($html, '?v=STA-HON-4'));
    $assert->that('view pdp: no-JS variant select', str_contains($html, '<noscript>') && str_contains($html, 'name="v"'));
    $assert->that('view pdp: buy bar opens the options sheet (link to #options without JS)', str_contains($html, 'href="#options" data-sheet-open="optSheet"')
        && str_contains($html, 'id="optSheet" role="dialog" aria-modal="true"') && str_contains($html, 'data-sheet-slot') && substr_count($html, 'id="opts"') === 1);
    $onyxHtml = View::html('store.product', ProductPage::load('honey-onyx') + ['stamp' => Enquiries::stamp(), 'enquiry' => ['errors' => [], 'old' => [], 'thanks' => null]], []);
    $assert->that('view pdp: enquiry-only shows the quote form, no price', str_contains($onyxHtml, 'name="kind" value="quote"') && !str_contains($onyxHtml, '₹2,400'));
    $assert->that('view pdp: no options sheet without option groups', !str_contains($onyxHtml, 'id="optSheet"') && !str_contains($onyxHtml, 'data-sheet-open'));
    $soonHtml = View::html('store.product', ProductPage::load('soon-granite') + ['stamp' => Enquiries::stamp(), 'enquiry' => ['errors' => [], 'old' => [], 'thanks' => 'ENQ/26-27/0009']], []);
    $assert->that('view pdp: after sending, the thank-you replaces the form', str_contains($soonHtml, 'ENQ/26-27/0009') && !str_contains($soonHtml, 'name="kind" value="notify"'));

    $state = Listing::fromQuery(['attr' => ['colour' => ['white']]]);
    $res = Listing::run($state);
    $shopHtml = View::html('store.shop', ['state' => $state, 'result' => $res, 'cards' => \App\Services\Store\Catalog::cards($res['ids']), 'path' => '/shop', 'top' => null, 'room' => null,
        'hero' => ['title' => 'All stone', 'title_plain' => 'Shop', 'kicker' => 'Shop', 'copy' => 'x', 'image' => null], 'subChips' => [], 'tree' => [], 'origins' => [], 'total' => 5, 'others' => []], []);
    $assert->that('view shop: filter form is a GET form with the checked value', str_contains($shopHtml, 'id="facets" method="get"') && preg_match('#name="attr\[colour\]\[\]" value="white" checked#', $shopHtml) === 1);
    $assert->that('view shop: sort sheet lists every sort, current one checked; native select kept', str_contains($shopHtml, 'id="sortSheet" role="dialog"')
        && substr_count($shopHtml, 'data-sort-pick=') === count(Listing::SORTS) && str_contains($shopHtml, 'data-sort-pick="featured" aria-checked="true"')
        && str_contains($shopHtml, 'data-sheet-open="sortSheet"') && str_contains($shopHtml, '<select id="sort" name="sort" form="facets"'));
    $assert->that('view shop: results + chip', str_contains($shopHtml, 'Statuario Marble') && !str_contains($shopHtml, 'Nero Marquina</a>') && str_contains($shopHtml, 'Remove filter White'));

    // ---------------------------------------------------------------- enquiries
    $old = time() - 60;
    $good = ['name' => 'Rina Das', 'phone' => '+91 98300 12345', 'message' => 'Need flooring for a 3BHK.', 'source' => 'contact', 'ts' => Enquiries::stamp($old), 'website' => '', 'city' => 'Bankura'];
    $assert->that('enquiry: honeypot → silent ok, nothing stored', Enquiries::submit(array_replace($good, ['website' => 'x']), '10.0.0.1', 'UA')['spam'] ?? false);
    $good['website'] = '';
    $assert->that('enquiry: forged / too-fast stamp → spam', (Enquiries::submit(['ts' => '123.abc'] + $good, '10.0.0.1', null)['spam'] ?? false)
        && (Enquiries::submit(['ts' => Enquiries::stamp(time())] + $good, '10.0.0.1', null)['spam'] ?? false));
    $assert->equals('enquiry: nothing stored by spam', 0, DB::table('enquiries')->count());
    $res = Enquiries::submit($good, '10.0.0.1', 'UA');
    $row = DB::selectOne('SELECT * FROM enquiries WHERE id = ?', [$res['id'] ?? 0]);
    $assert->that('enquiry: stored with an ENQ number', $res['ok'] && preg_match('#^ENQ/\d\d-\d\d/0001$#', (string) ($row['enquiry_number'] ?? '')) === 1);
    $assert->that('enquiry: phone normalised, status new, source kept', $row['phone'] === '9830012345' && $row['status'] === 'new' && $row['source'] === 'contact');
    $cust = DB::selectOne('SELECT * FROM customers WHERE phone = ?', ['9830012345']);
    $assert->that('enquiry: new online customer created by phone', $cust !== null && (int) $row['customer_id'] === (int) $cust['id'] && $cust['source'] === 'online');
    $assert->that('enquiry: admin notified', DB::table('notifications')->where('type', 'enquiry.received')->count() === 1);
    $again = Enquiries::submit($good, '10.0.0.1', 'UA');
    $assert->that('enquiry: same text again = duplicate, no new row', ($again['duplicate'] ?? false) && DB::table('enquiries')->count() === 1);
    $p2 = Enquiries::submit(['message' => '', 'source' => 'product', 'product_id' => (string) $statuario, 'variant_id' => (string) $vars['STA-HON-8'], 'quantity' => '450', 'kind' => 'quote'] + $good, '10.0.0.1', 'UA');
    $row2 = DB::selectOne('SELECT * FROM enquiries WHERE id = ?', [$p2['id']]);
    $assert->that('enquiry: product + variant linked, message says what was asked', (int) $row2['product_id'] === $statuario && (int) $row2['variant_id'] === $vars['STA-HON-8']
        && str_starts_with((string) $row2['message'], 'Quote request: Statuario Marble (Honed · 8x4)'));
    $assert->that('enquiry: same mobile → same customer, quantity + unit kept', (int) $row2['customer_id'] === (int) $cust['id'] && (float) $row2['quantity'] === 450.0 && $row2['unit'] === 'sq ft');
    $p3 = Enquiries::submit(['message' => 'x', 'product_id' => (string) $statuario, 'variant_id' => (string) $vars['STA-POL-8'] + 1000, 'source' => 'product'] + $good, '10.0.0.2', 'UA');
    $assert->equals('enquiry: a variant of another product is ignored', 0, (int) DB::selectOne('SELECT variant_id FROM enquiries WHERE id = ?', [$p3['id']])['variant_id']);
    $bad = Enquiries::submit(['product_id' => (string) $draft, 'source' => 'product'] + $good, '10.0.0.3', 'UA');
    $assert->that('enquiry: draft product refused', !$bad['ok'] && isset($bad['errors']['form']));
    $bad = Enquiries::submit(['name' => 'A', 'phone' => '12345', 'message' => '', 'email' => 'nope', 'quantity' => '-4'] + $good, '10.0.0.3', 'UA');
    $assert->equals('enquiry: validation errors', ['email', 'message', 'name', 'phone', 'quantity'], (function ($k) { sort($k); return $k; })(array_keys($bad['errors'] ?? [])));
    $existing = (int) DB::table('customers')->insertGetId(['name' => 'Showroom Sita', 'phone' => '9876543210', 'customer_type' => 'individual', 'status' => 'active', 'source' => 'showroom', 'created_at' => $now, 'updated_at' => $now]);
    $r4 = Enquiries::submit(['phone' => '09876543210', 'name' => 'Sita', 'message' => 'Visit?'] + $good, '10.0.0.4', 'UA');
    $assert->equals('enquiry: showroom customer matched by phone (no duplicate customer)', $existing, (int) DB::selectOne('SELECT customer_id FROM enquiries WHERE id = ?', [$r4['id']])['customer_id']);
    for ($i = 0; $i < 8; $i++) {
        $last = Enquiries::submit(['message' => 'Flood ' . $i] + $good, '10.9.9.9', 'UA');
    }
    $assert->that('enquiry: per-IP hourly limit', !$last['ok'] && str_contains($last['errors']['form'] ?? '', 'short time'));

    // admin update
    $eid = (int) $res['id'];
    $threw = static function (callable $f): bool { try { $f(); } catch (\InvalidArgumentException) { return true; } return false; };
    $assert->that('update: unknown status / non-staff assignee refused', $threw(fn () => Enquiries::update($eid, ['status' => 'hacked'])) && $threw(fn () => Enquiries::update($eid, ['assigned_to' => '999'])));
    $staff = (int) DB::table('admin_users')->insertGetId(['name' => 'Mitra', 'email' => 'm@example.test', 'password' => 'x', 'status' => 'active', 'must_change_password' => 0, 'session_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
    Enquiries::update($eid, ['status' => 'contacted', 'assigned_to' => (string) $staff, 'internal_note' => "  Called.\r\nSent photos.  "]);
    $row = DB::selectOne('SELECT * FROM enquiries WHERE id = ?', [$eid]);
    $assert->that('update: status, assignee, note saved', $row['status'] === 'contacted' && (int) $row['assigned_to'] === $staff && $row['internal_note'] === "Called.\nSent photos.");
    $assert->that('update: handled enquiry clears its notification', DB::table('notifications')->where('dedupe_key', 'enquiry:' . $eid)->whereNull('read_at')->count() === 0);
    Enquiries::update($eid, ['assigned_to' => '']);
    $assert->that('update: unassign; other fields untouched', DB::selectOne('SELECT assigned_to, status FROM enquiries WHERE id = ?', [$eid]) === ['assigned_to' => null, 'status' => 'contacted']);
    $counts = Enquiries::counts();
    $assert->that('counts per status', $counts['contacted'] === 1 && $counts['new'] >= 3);

    // safe redirect target
    $assert->that('back: local paths only', EnquiryController::back('//evil.example') === base_url('/contact') && EnquiryController::back('https://evil.example') === base_url('/contact')
        && EnquiryController::back("/x\0y") === base_url('/contact') && EnquiryController::back('/product/a#enquire') === base_url('/product/a'));
};
