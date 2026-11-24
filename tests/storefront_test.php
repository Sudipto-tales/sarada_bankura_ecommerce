<?php

use App\Middleware\StoreOpen;
use App\Services\AdminAccess\Guard;
use App\Services\Store\Catalog;
use App\Services\Store\Context;
use App\Services\Store\View;
use Vayu\Application;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

/** Stage 8 S1: migration 000016, Catalog visibility/price/badge rules, launch switch, views render. */
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

    // ---------------------------------------------------------------- migration
    $cols = static fn(string $t): array => array_column(DB::select("PRAGMA table_info($t)"), 'name');
    foreach (['carts', 'cart_items', 'customer_saved_items', 'enquiries', 'customer_auth_codes', 'customer_remember_tokens'] as $t) {
        $assert->that("migration: $t created", $cols($t) !== []);
    }
    $assert->that('migration: tags.icon', in_array('icon', $cols('tags'), true));
    $assert->that('migration: pricing min_order_qty + order_step', !array_diff(['min_order_qty', 'order_step'], $cols('product_pricing')));
    $assert->that('migration: variant pack_qty/pack_unit/min_order_qty', !array_diff(['pack_qty', 'pack_unit', 'min_order_qty'], $cols('product_variants')));
    $assert->that('migration: orders.buyer_note', in_array('buyer_note', $cols('orders'), true));
    $assert->that('migration: attribute_values.family', in_array('family', $cols('attribute_values'), true));
    $threw = false;
    DB::table('carts')->insert(['token' => str_repeat('a', 40), 'status' => 'active']);
    $cartId = (int) DB::selectOne('SELECT id FROM carts')['id'];
    DB::table('cart_items')->insert(['cart_id' => $cartId, 'product_id' => 1, 'variant_id' => 0, 'quantity' => 1]);
    try {
        DB::table('cart_items')->insert(['cart_id' => $cartId, 'product_id' => 1, 'variant_id' => 0, 'quantity' => 2]);
    } catch (\Throwable) {
        $threw = true;
    }
    $assert->that('migration: one cart line per product+variant', $threw);

    // ---------------------------------------------------------------- fixtures
    $now = date('Y-m-d H:i:s');
    $cat = static function (string $name, ?int $parent = null, int $visible = 1) use ($now): int {
        return (int) DB::table('product_categories')->insertGetId(['name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)),
            'parent_id' => $parent, 'is_visible' => $visible, 'is_featured' => 0, 'sort' => 0, 'created_at' => $now, 'updated_at' => $now]);
    };
    $floor = $cat('Flooring');
    $living = $cat('Living Room', $floor);
    $bath = $cat('Bathroom', $floor);
    $imported = $cat('Imported');
    $hidden = $cat('Hidden', null, 0);
    $brand = (int) DB::table('brands')->insertGetId(['name' => 'Studio', 'slug' => 'studio', 'tier' => 'signature', 'status' => 'active', 'is_featured' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('brands')->insert(['name' => 'Gone', 'slug' => 'gone', 'tier' => 'core', 'status' => 'inactive', 'is_featured' => 0, 'created_at' => $now, 'updated_at' => $now]);
    $tag = static fn(string $slug, string $purpose, ?string $icon = null, ?string $desc = null): int => (int) DB::table('tags')->insertGetId(
        ['name' => ucfirst(str_replace('-', ' ', $slug)), 'slug' => $slug, 'purpose' => $purpose, 'status' => 'active', 'icon' => $icon, 'description' => $desc]);
    $tNew = $tag('new-arrival', 'merchandising');
    $tHeat = $tag('heat-resistant', 'discovery', 'flame', 'Hot pans');
    $tBad = $tag('odd-icon', 'discovery', '<script>', null);
    $tInternal = $tag('clearance', 'internal');

    $product = static function (string $name, array $p = [], ?array $pricing = []) use ($now, $brand): int {
        $id = (int) DB::table('products')->insertGetId($p + ['product_type_id' => 1, 'status' => 'active', 'sku' => strtoupper(substr($name, 0, 3)) . rand(100, 999),
            'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'brand_id' => $brand, 'is_enquiry_only' => 0, 'show_price' => 1,
            'is_featured' => 0, 'is_bestseller' => 0, 'created_at' => $now, 'updated_at' => $now]);
        if ($pricing !== null) {
            DB::table('product_pricing')->insert($pricing + ['product_id' => $id, 'price' => 100, 'compare_at_price' => null, 'currency' => 'INR', 'unit' => 'sq ft']);
        }

        return $id;
    };
    $plain = $product('Plain Marble', ['is_bestseller' => 1], ['price' => 240, 'compare_at_price' => 320]);
    $variant = $product('Variant Marble', ['is_featured' => 1], ['price' => 640, 'compare_at_price' => 850]);
    foreach ([[590, 790, 0, 'active'], [520, 500, 1, 'active'], [400, null, 0, 'inactive']] as $i => [$pr, $cmp, $def, $st]) {
        DB::table('product_variants')->insert(['product_id' => $variant, 'sku' => 'V' . $i, 'title' => 'V' . $i, 'price' => $pr, 'compare_at_price' => $cmp,
            'status' => $st, 'is_default' => $def, 'sort' => $i, 'created_at' => $now, 'updated_at' => $now]);
    }
    $enquiry = $product('Rare Onyx', ['is_enquiry_only' => 1, 'show_price' => 0], ['price' => 1450]);
    $soon = $product('Soon Gold', ['status' => 'coming_soon'], ['price' => 980]);
    $draft = $product('Draft Stone', ['status' => 'draft']);
    $archived = $product('Old Stone', ['status' => 'archived']);
    $outStock = $product('Empty Shelf', [], ['price' => 195]);
    $lowStock = $product('Few Left', [], ['price' => 300]);
    $noPrice = $product('No Pricing Row', [], null);

    DB::table('inventory_stock')->insert(['product_id' => $outStock, 'unit' => 'sq ft', 'quantity' => 0, 'status' => 'out', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('inventory_stock')->insert(['product_id' => $lowStock, 'unit' => 'sq ft', 'quantity' => 3, 'status' => 'low', 'created_at' => $now, 'updated_at' => $now]);
    foreach ([[$plain, $living], [$variant, $living], [$variant, $imported], [$draft, $bath], [$soon, $bath], [$enquiry, $hidden]] as [$pid, $cid]) {
        DB::table('product_category_map')->insert(['product_id' => $pid, 'category_id' => $cid, 'is_primary' => 1]);
    }
    foreach ([[$plain, $tHeat], [$draft, $tHeat], [$outStock, $tNew], [$plain, $tInternal]] as [$pid, $tid]) {
        DB::table('product_tags')->insert(['product_id' => $pid, 'tag_id' => $tid]);
    }
    foreach ([[$plain, 5, 'published'], [$plain, 4, 'published'], [$plain, 1, 'pending'], [$draft, 5, 'published']] as [$pid, $r, $st]) {
        DB::table('product_reviews')->insert(['product_id' => $pid, 'customer_name' => 'Ritika Sen', 'rating' => $r, 'title' => 'T', 'body' => 'Body',
            'source' => 'website', 'status' => $st, 'created_at' => $now, 'updated_at' => $now]);
    }

    // ---------------------------------------------------------------- cards
    $cards = [];
    foreach (Catalog::cards([$plain, $variant, $enquiry, $soon, $draft, $archived, $outStock, $lowStock, $noPrice, 99999]) as $c) {
        $cards[$c['id']] = $c;
    }
    $assert->that('cards: draft, archived and unknown ids dropped', !isset($cards[$draft]) && !isset($cards[$archived]) && !isset($cards[99999]));
    $assert->equals('cards: order kept', [$plain, $variant, $enquiry, $soon, $outStock, $lowStock, $noPrice], array_keys($cards));
    $c = $cards[$plain];
    $assert->that('plain: buyable, price + compare + 25% off', $c['buyable'] && $c['price'] === 240.0 && $c['compare'] === 320.0 && $c['off'] === 25 && $c['cta'] === 'add');
    $assert->equals('plain: rating from published reviews only', [4.5, 2], [$c['rating'], $c['reviews']]);
    $assert->that('plain: bestseller badge, discovery need shown, internal tag hidden', $c['badges'][0]['label'] === 'Bestseller' && $c['need'] === 'Heat resistant');
    $assert->that('plain: internal tag never in card tags', !in_array('clearance', $c['tags'], true));
    $c = $cards[$variant];
    $assert->that('variant: cheapest ACTIVE variant (inactive 400 ignored)', $c['price'] === 520.0 && $c['from'] === true && $c['variants'] === 2);
    $assert->that('variant: compare below price is dropped', $c['compare'] === null && $c['on_offer'] === false);
    $assert->that('variant: CTA = choose, default variant picked', $c['cta'] === 'choose' && $c['default_variant_id'] > 0);
    $c = $cards[$enquiry];
    $assert->that('enquiry-only: not buyable, CTA enquire', !$c['buyable'] && !$c['show_price'] && $c['cta'] === 'enquire');
    $c = $cards[$soon];
    $assert->that('coming soon: not buyable, CTA notify, badge', !$c['buyable'] && $c['cta'] === 'notify' && $c['badges'][0]['label'] === 'Coming soon');
    $c = $cards[$outStock];
    $assert->that('out of stock: still buyable, "Made to order" badge (owner default)', $c['buyable'] && $c['badges'][0]['label'] === 'Made to order');
    $assert->that('low stock: "Few left" badge', in_array('Few left', array_column($cards[$lowStock]['badges'], 'label'), true));
    $assert->that('no pricing row: price on request, not buyable', !$cards[$noPrice]['buyable'] && $cards[$noPrice]['cta'] === 'enquire');

    // ---------------------------------------------------------------- tree, needs, brands, reviews
    $tree = Catalog::categoryTree();
    $byName = array_column($tree, null, 'name');
    $assert->that('tree: hidden category left out', !isset($byName['Hidden']));
    $assert->equals('tree: Flooring counts listed products in it or its children (draft excluded)', 3, $byName['Flooring']['count']);
    $kids = array_column($byName['Flooring']['children'], 'count', 'name');
    ksort($kids);
    $assert->equals('tree: child counts', ['Bathroom' => 1, 'Living Room' => 2], $kids);
    $needs = array_column(Catalog::needs(), null, 'slug');
    $assert->that('needs: discovery only', isset($needs['heat-resistant']) && !isset($needs['new-arrival']) && !isset($needs['clearance']));
    $assert->equals('needs: count ignores draft products', 1, $needs['heat-resistant']['count']);
    $assert->equals('needs: unsafe icon name falls back', 'sparkles', $needs['odd-icon']['icon']);
    $brands = Catalog::brands(null, 4);
    $assert->that('brands: inactive brand hidden', count($brands) === 1 && $brands[0]['slug'] === 'studio');
    $assert->that('brands: products listed only', !in_array($draft, array_column($brands[0]['products'], 'id'), true));
    $rs = Catalog::reviewSummary();
    $assert->that('reviews: published reviews of listed products only', $rs['count'] === 2 && $rs['avg'] === 4.5);
    $assert->equals('reviews: surname shortened', 'Ritika S.', $rs['items'][0]['name']);

    $home = Catalog::home();
    $assert->that('home: spotlight is the featured buyable product', $home['spotlight'] !== null && $home['spotlight']['id'] === $variant);
    $assert->that('home: inactive variant not offered in spotlight', count($home['spotlight']['variant_list']) === 2);
    $tabs = array_column($home['tabs'], 'count', 'key');
    $assert->that('home: tabs only when non-empty, "all" last', $tabs['all'] === 7 && !isset($tabs['premium']) && end($home['tabs'])['key'] === 'all');

    // ---------------------------------------------------------------- views render
    $html = View::html('store.home', ['home' => $home], ['active' => 'home']);
    $assert->that('view: home renders products', str_contains($html, 'Plain Marble') && str_contains($html, 'Variant Marble'));
    $assert->that('view: draft never rendered', !str_contains($html, 'Draft Stone'));
    $assert->that('view: no CDN scripts/styles', !preg_match('#src="https?://(cdn|unpkg|fonts)#', $html) && !str_contains($html, 'fonts.googleapis'));
    DB::table('products')->where('id', $plain)->update(['name' => '<b>x</b> Marble']);
    $html = View::html('store.home', ['home' => Catalog::home()], []);
    $assert->that('view: product names escaped', str_contains($html, '&lt;b&gt;x&lt;/b&gt; Marble') && !str_contains($html, '<b>x</b> Marble'));
    $assert->that('view: brands + policy render', str_contains(View::html('store.brands', ['brands' => $brands, 'tier' => 'all', 'tierCounts' => ['signature' => 1]], []), 'Studio')
        && str_contains(View::html('store.policy', ['advance' => 20, 'gst' => 18.0, 'taxIncluded' => false, 'windowMinutes' => 6, 'otpDays' => 7], []), '20% advance'));

    // ---------------------------------------------------------------- cart count
    $_COOKIE[Context::CART_COOKIE] = str_repeat('a', 40);
    Context::reset();
    $assert->equals('context: cart count from cookie', 1, Context::cartCount());
    $_COOKIE[Context::CART_COOKIE] = "bad'; DROP";
    Context::reset();
    $assert->equals('context: malformed cookie ignored', 0, Context::cartCount());
    unset($_COOKIE[Context::CART_COOKIE]);

    // ---------------------------------------------------------------- launch switch
    $mw = new StoreOpen();
    $next = static fn(Request $r): Response => Response::make('STORE');
    $req = static fn(string $path): Request => new Request('GET', $path, [], [], [], ['REMOTE_ADDR' => '10.0.0.1'], [], null);
    $app->config()->set('store.enabled', false);
    session()->forget('admin');
    Guard::forget();
    $r = $mw->handle($req('/'), $next);
    $assert->that('closed: "/" shows coming soon', $r->getStatus() === 200 && str_contains($r->getContent(), 'Coming Soon') && !str_contains($r->getContent(), 'STORE'));
    $r = $mw->handle($req('/brands'), $next);
    $assert->that('closed: other pages redirect home', $r->getStatus() === 302);

    $adminId = (int) DB::table('admin_users')->insertGetId(['name' => 'Owner', 'email' => 'o@example.test', 'password' => password_hash('x', PASSWORD_DEFAULT),
        'status' => 'active', 'must_change_password' => 0, 'session_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
    session()->put('admin', ['id' => $adminId, 'ver' => 1, 'last' => time()]);
    Guard::forget();
    $r = $mw->handle($req('/brands'), $next);
    $headers = array_change_key_case($r->getHeaders());
    $assert->that('closed + admin: preview shown, not cached, noindex', $r->getContent() === 'STORE' && Context::previewing()
        && str_contains((string) ($headers['cache-control'] ?? ''), 'no-store') && isset($headers['x-robots-tag']));
    session()->forget('admin');
    Guard::forget();

    $app->config()->set('store.enabled', true);
    $r = $mw->handle($req('/brands'), $next);
    $assert->that('open: everyone sees the store, no preview', $r->getContent() === 'STORE' && !Context::previewing());
    $app->config()->set('store.enabled', false);
};
