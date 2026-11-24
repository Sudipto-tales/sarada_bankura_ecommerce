<?php

use App\Services\Store\Calculator;
use App\Services\Store\Cart;
use App\Services\Store\Context;
use App\Services\Store\Purchasable;
use App\Services\Store\View;
use Vayu\Application;
use Vayu\Database\DB;

/** Stage 8 S3: Purchasable rule, cart add / update / remove, re-pricing, totals, calculator data. */
return function (Assert $assert): void {
    $app = new Application(dirname(__DIR__));
    $app->config()->set('database.default', 'sqlite');
    $app->config()->set('database.connections.sqlite.database', ':memory:');
    $app->config()->set('cache.default', 'array');
    $app->config()->set('store.cache_ttl', 0);
    $router = $app->router();
    require dirname(__DIR__) . '/routes/web.php';
    $files = glob(dirname(__DIR__) . '/database/migrations/2026_*.php');
    sort($files);
    foreach ($files as $f) {
        ob_start();
        (require $f)->up();
        ob_end_clean();
    }
    View::loadHelpers();
    Cart::$sendCookie = false;
    $_COOKIE = [];
    Cart::reset();

    // ---------------------------------------------------------------- fixtures
    $now = date('Y-m-d H:i:s');
    $product = static function (string $name, array $p = [], ?array $pricing = []) use ($now): int {
        $id = (int) DB::table('products')->insertGetId($p + ['product_type_id' => 1, 'status' => 'active', 'sku' => 'SKU-' . strtoupper(substr(md5($name), 0, 6)),
            'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'is_enquiry_only' => 0, 'show_price' => 1,
            'is_featured' => 0, 'is_bestseller' => 0, 'created_at' => $now, 'updated_at' => $now]);
        if ($pricing !== null) {
            DB::table('product_pricing')->insert($pricing + ['product_id' => $id, 'price' => 100, 'compare_at_price' => null, 'currency' => 'INR', 'unit' => 'sq ft']);
        }

        return $id;
    };
    $variant = static fn(int $pid, string $sku, ?float $price, string $status = 'active', array $x = []): int => (int) DB::table('product_variants')->insertGetId($x + [
        'product_id' => $pid, 'sku' => $sku, 'title' => $sku, 'price' => $price, 'compare_at_price' => null, 'status' => $status, 'is_default' => 0, 'sort' => 0,
        'created_at' => $now, 'updated_at' => $now]);

    $plain = $product('Kota Stone', [], ['price' => 45, 'compare_at_price' => 60, 'min_order_qty' => 50, 'order_step' => 10]);
    $tile = $product('Vitrified Tile', [], ['price' => 900, 'unit' => 'box', 'pack_qty' => 16, 'pack_unit' => 'sq ft']);
    $multi = $product('Statuario Marble', [], ['price' => 600]);
    $vBig = $variant($multi, 'STA-BIG', 700, 'active', ['compare_at_price' => 900, 'min_order_qty' => 20]);
    $vNull = $variant($multi, 'STA-STD', null); // no own price → product price
    $vOff = $variant($multi, 'STA-OFF', 500, 'inactive');
    $other = $product('Nero Marquina', [], ['price' => 320]);
    $vOther = $variant($other, 'NERO-1', 330);
    $enquiry = $product('Onyx Slab', ['is_enquiry_only' => 1], ['price' => 5000]);
    $hidden = $product('Secret Price', ['show_price' => 0], ['price' => 100]);
    $soon = $product('New Lot', ['status' => 'coming_soon'], ['price' => 100]);
    $draft = $product('Draft Stone', ['status' => 'draft'], ['price' => 100]);
    $archived = $product('Old Stone', ['status' => 'archived'], ['price' => 100]);
    $zero = $product('Zero Price', [], ['price' => 0]);
    $noPricing = $product('No Pricing', [], null);
    DB::table('inventory_stock')->insert(['product_id' => $plain, 'unit' => 'sq ft', 'quantity' => 0, 'status' => 'out', 'created_at' => $now, 'updated_at' => $now]);

    // ---------------------------------------------------------------- Purchasable
    $c = Purchasable::check($plain);
    $assert->that('buyable: simple product, price + unit + rules from the DB', $c['ok'] && $c['price'] === 45.0 && $c['compare'] === 60.0 && $c['unit'] === 'sq ft' && $c['min_qty'] === 50.0 && $c['step'] === 10.0);
    $assert->that('buyable: out of stock never blocks (made to order)', $c['ok'] && $c['stock'] === 'out');
    foreach ([[$enquiry, 'enquiry-only'], [$hidden, 'price hidden'], [$soon, 'coming soon'], [$draft, 'draft'], [$archived, 'archived'], [$zero, 'zero price'], [$noPricing, 'no pricing row'], [999999, 'unknown id'], [0, 'id 0'], [-3, 'negative id']] as [$pid, $why]) {
        $r = Purchasable::check($pid);
        $assert->that("not buyable: $why", !$r['ok'] && is_string($r['reason']) && $r['reason'] !== '');
    }
    $assert->that('variants: none chosen while active variants exist → refused', !Purchasable::check($multi)['ok']);
    $assert->that('variants: inactive variant refused', !Purchasable::check($multi, $vOff)['ok']);
    $assert->that('variants: another product’s variant refused', !Purchasable::check($multi, $vOther)['ok']);
    $assert->that('variants: variant on a product without variants refused', !Purchasable::check($plain, $vBig)['ok']);
    $b = Purchasable::check($multi, $vBig);
    $assert->that('variants: own price / compare / min', $b['ok'] && $b['price'] === 700.0 && $b['compare'] === 900.0 && $b['min_qty'] === 20.0 && $b['sku'] === 'STA-BIG');
    $assert->that('variants: no own price → product price (= OrderService rule)', Purchasable::check($multi, $vNull)['price'] === 600.0);

    // quantity rules
    $q = static fn(int $pid, mixed $raw, int $vid = 0) => Purchasable::quantity(Purchasable::check($pid, $vid), $raw);
    $assert->that('qty: blank → default = max(min, step)', $q($plain, null) === [50.0, null] && $q($tile, '') === [1.0, null]);
    $assert->that('qty: rounded UP to the order step', $q($plain, '61') === [70.0, null] && $q($plain, '70') === [70.0, null]);
    $assert->that('qty: below minimum refused', $q($plain, '40')[0] === null && str_contains((string) $q($plain, '40')[1], '50'));
    $assert->that('qty: step 1 rounds fractions up', $q($tile, '2.2') === [3.0, null]);
    foreach (['0', '-5', 'abc', '1e9', 'INF', 'NAN', [5], true] as $bad) {
        $assert->that('qty: refused ' . json_encode($bad), $q($tile, $bad)[0] === null);
    }
    $assert->that('qty: "1,200" commas accepted', $q($tile, '1,200') === [1200.0, null]);

    // ---------------------------------------------------------------- cart: add
    $assert->that('no cookie → no cart, count 0, no rows created by reading', Cart::current() === null && Cart::count() === 0 && Cart::lines() === [] && DB::table('carts')->count() === 0);
    $r = Cart::add($enquiry, 0, 1);
    $assert->that('add: unbuyable refused and no cart created', !$r['ok'] && DB::table('carts')->count() === 0);
    $r = Cart::add($plain, 0, '55', '1.2.3.4', 'UA');
    $assert->that('add: creates the cart lazily + token + line (qty rounded to step)', $r['ok'] && $r['count'] === 1 && $r['qty'] === 60.0 && DB::table('carts')->count() === 1 && strlen((string) Cart::token()) === 48);
    $assert->that('add: Context count sees the new cart in the same request', Context::cartCount() === 1);
    $line = DB::selectOne('SELECT * FROM cart_items');
    $assert->that('add: price_at_add from the DB', (float) $line['price_at_add'] === 45.0 && (int) $line['variant_id'] === 0);
    $r = Cart::add($plain, 0, '20');
    $assert->that('add: same product adds to the line (re-validated)', $r['ok'] && $r['count'] === 1 && (float) DB::table('cart_items')->value('quantity') === 80.0);
    $assert->that('add: variant required', !Cart::add($multi, 0, 1)['ok']);
    $assert->that('add: inactive / foreign variant refused', !Cart::add($multi, $vOff, 1)['ok'] && !Cart::add($multi, $vOther, 1)['ok']);
    $assert->that('add: below variant minimum refused', !Cart::add($multi, $vBig, 5)['ok']);
    $assert->that('add: variant line', Cart::add($multi, $vBig, 20)['ok'] && Cart::add($tile, 0, 3)['ok'] && Cart::count() === 3);
    $assert->that('add: huge qty refused', !Cart::add($tile, 0, '99999999')['ok']);

    // ---------------------------------------------------------------- tampering: the client never sets a price
    $_POST = ['product_id' => $tile, 'variant_id' => 0, 'qty' => 1, 'price' => 1, 'unit_price' => 1, 'price_at_add' => 1];
    Cart::add($tile, 0, 1);
    $assert->that('tamper: extra price fields ignored (price from DB)', (float) DB::table('cart_items')->where('product_id', $tile)->value('price_at_add') === 900.0);
    $_POST = [];

    // ---------------------------------------------------------------- lines + totals
    $lines = Cart::lines();
    $by = [];
    foreach ($lines as $l) {
        $by[$l['product_id']] = $l;
    }
    $assert->that('lines: 3 lines, all ok', count($lines) === 3 && array_sum(array_map(fn ($l) => $l['line_ok'] ? 1 : 0, $lines)) === 3);
    $assert->that('lines: totals per line', $by[$plain]['line_total'] === 3600.0 && $by[$multi]['line_total'] === 14000.0 && $by[$tile]['line_total'] === 3600.0);
    $assert->that('lines: coverage (sq ft + boxes × pack)', $by[$plain]['coverage'] === 80.0 && $by[$tile]['coverage'] === 64.0);
    $assert->that('lines: savings vs compare price', $by[$plain]['savings'] === 1200.0 && $by[$multi]['savings'] === 4000.0);
    $assert->that('lines: variant product url keeps ?v=SKU', str_ends_with($by[$multi]['url'], '?v=STA-BIG'));

    $app->config()->set('business.gstin', '');
    $app->config()->set('business.tax.prices_include_tax', false);
    $t = Cart::totals($lines);
    $assert->that('totals: no GSTIN → no GST added (OrderService would refuse it)', $t['tax_rate'] === 0.0 && $t['tax'] === 0.0 && $t['total'] === 21200.0);
    $app->config()->set('business.gstin', '19ABCDE1234F1Z5');
    $app->config()->set('business.tax.gst_rate', 18);
    $t = Cart::totals($lines);
    $assert->that('totals: GSTIN set → GST on top', $t['tax_rate'] === 18.0 && $t['tax'] === 3816.0 && $t['total'] === 25016.0);
    $assert->that('totals: advance = PaymentService rule (rounded up)', $t['advance'] === \App\Services\Order\PaymentService::advance(25016.0));
    $app->config()->set('business.tax.prices_include_tax', true);
    $assert->that('totals: prices include tax → nothing added', Cart::totals($lines)['tax'] === 0.0);
    $app->config()->set('business.tax.prices_include_tax', false);

    // re-pricing: the DB price wins on every view
    DB::update('UPDATE product_pricing SET price = 50 WHERE product_id = ?', [$plain]);
    $l = array_values(array_filter(Cart::lines(), fn ($x) => $x['product_id'] === $plain))[0];
    $assert->that('re-price: new DB price used + "price changed" notice', $l['price'] === 50.0 && $l['line_total'] === 4000.0 && $l['price_changed'] === 45.0);

    // a line that became unbuyable stays visible but leaves the totals
    DB::update("UPDATE products SET is_enquiry_only = 1 WHERE id = ?", [$tile]);
    $lines = Cart::lines();
    $tl = array_values(array_filter($lines, fn ($x) => $x['product_id'] === $tile))[0];
    $t = Cart::totals($lines);
    $assert->that('unbuyable later: greyed with reason, out of totals, counted as blocked', !$tl['line_ok'] && $tl['line_reason'] !== null && $tl['line_total'] === 0.0 && $t['blocked'] === 1 && $t['count'] === 2);
    DB::update("UPDATE products SET is_enquiry_only = 0 WHERE id = ?", [$tile]);
    DB::update('UPDATE product_pricing SET min_order_qty = 100 WHERE product_id = ?', [$plain]);
    $pl = array_values(array_filter(Cart::lines(), fn ($x) => $x['product_id'] === $plain))[0];
    $assert->that('minimum raised after adding → line flagged (qty form still offered)', !$pl['line_ok'] && $pl['ok'] && str_contains((string) $pl['line_reason'], '100'));
    DB::update('UPDATE product_pricing SET min_order_qty = 50 WHERE product_id = ?', [$plain]);
    DB::update("UPDATE product_variants SET status = 'archived' WHERE id = ?", [$vBig]);
    $ml = array_values(array_filter(Cart::lines(), fn ($x) => $x['product_id'] === $multi))[0];
    $assert->that('archived variant → line blocked', !$ml['line_ok']);
    DB::update("UPDATE product_variants SET status = 'active' WHERE id = ?", [$vBig]);

    // ---------------------------------------------------------------- update / remove + ownership
    $plainLine = (int) DB::table('cart_items')->where('product_id', $plain)->value('id');
    $assert->that('update: qty set (rounded to step)', Cart::update($plainLine, '91')['ok'] && (float) DB::table('cart_items')->where('id', $plainLine)->value('quantity') === 100.0);
    $assert->that('update: below minimum refused, qty unchanged', !Cart::update($plainLine, '10')['ok'] && (float) DB::table('cart_items')->where('id', $plainLine)->value('quantity') === 100.0);
    $assert->that('update: blank / junk refused', !Cart::update($plainLine, '')['ok'] && !Cart::update($plainLine, 'x')['ok']);

    // another visitor's cart
    $mine = Cart::token();
    Cart::reset();
    $_COOKIE[Context::CART_COOKIE] = str_repeat('b', 48);
    $assert->that('ownership: unknown token = empty bag', Cart::current() === null && Cart::count() === 0);
    $assert->that('ownership: can’t update or remove someone else’s line', !Cart::update($plainLine, 200)['ok'] && Cart::remove($plainLine)['ok'] && DB::table('cart_items')->where('id', $plainLine)->exists());
    Cart::add($tile, 0, 2);
    $assert->that('ownership: unknown cookie token is not adopted — a fresh cart + token', Cart::token() !== str_repeat('b', 48) && DB::table('carts')->count() === 2);
    Cart::reset();
    $_COOKIE[Context::CART_COOKIE] = "bad'token";
    $assert->that('cookie: malformed token ignored', Cart::token() === null);
    Cart::reset();
    $_COOKIE[Context::CART_COOKIE] = $mine;

    $assert->that('update: qty 0 removes the line', Cart::update($plainLine, '0')['ok'] && !DB::table('cart_items')->where('id', $plainLine)->exists());
    $tileLine = (int) DB::table('cart_items')->where('product_id', $tile)->where('cart_id', (int) Cart::current()['id'])->value('id');
    $rm = Cart::remove($tileLine);
    $assert->that('remove: returns slug for "save for later"', $rm['ok'] && $rm['slug'] === 'vitrified-tile' && Cart::count() === 1);

    // converted cart is closed
    $cid = (int) Cart::current()['id'];
    Cart::convert($cid, 42);
    $assert->that('convert: cart no longer current, count 0', Cart::current() === null && Cart::count() === 0 && (int) DB::table('carts')->where('id', $cid)->value('converted_order_id') === 42);
    Cart::add($tile, 0, 1);
    $assert->that('after convert: next add starts a new cart', Cart::current() !== null && (int) Cart::current()['id'] !== $cid);

    // line cap
    Cart::reset();
    $_COOKIE = [];
    $ids = [];
    for ($i = 0; $i < Cart::MAX_LINES; $i++) {
        $ids[] = $product('Bulk ' . $i, [], ['price' => 10 + $i]);
    }
    foreach ($ids as $id) {
        Cart::add($id, 0, 1);
    }
    $assert->that('cap: ' . Cart::MAX_LINES . ' lines max', Cart::count() === Cart::MAX_LINES && !Cart::add($tile, 0, 1)['ok'] && Cart::add($ids[0], 0, 1)['ok']);

    // ---------------------------------------------------------------- calculator data
    $stones = Calculator::stones();
    $names = array_column($stones, 'name');
    $assert->that('calculator: only buyable stones', in_array('Kota Stone', $names, true) && !array_intersect(['Onyx Slab', 'Secret Price', 'New Lot', 'Draft Stone', 'Old Stone', 'Zero Price', 'No Pricing'], $names));
    $st = array_values(array_filter($stones, fn ($s) => $s['id'] === $multi))[0];
    $assert->that('calculator: active variants only, null price → product price', count($st['options']) === 2 && in_array(600.0, array_column($st['options'], 'price'), true) && !in_array($vOff, array_column($st['options'], 'id'), true));
    $tl = array_values(array_filter($stones, fn ($s) => $s['id'] === $tile))[0];
    $assert->that('calculator: pack size for box products', $tl['options'][0]['pack_qty'] === 16.0 && $tl['options'][0]['pack_unit'] === 'sq ft');

    // ---------------------------------------------------------------- views render
    Cart::reset();
    $_COOKIE = [];
    Cart::add($plain, 0, 50);
    $html = View::html('store.cart', ['lines' => Cart::lines(), 'totals' => Cart::totals(Cart::lines()), 'advance_percent' => 20, 'tax_included' => false, 'checkout' => null, 'reco' => []]);
    $assert->that('cart view: line, update + remove forms with CSRF, WhatsApp fallback before S4', str_contains($html, 'Kota Stone') && str_contains($html, 'name="line"') && substr_count($html, 'name="_token"') >= 3 && str_contains($html, 'Order this bag on WhatsApp'));
    $empty = View::html('store.cart', ['lines' => [], 'totals' => Cart::totals([]), 'advance_percent' => 20, 'tax_included' => false, 'checkout' => null, 'reco' => []]);
    $assert->that('cart view: empty state', str_contains($empty, 'Your bag is empty'));
    $calc = View::html('store.calculator', ['stones' => $stones, 'tax_rate' => 18.0, 'advance_percent' => 20]);
    $assert->that('calculator view: add form posts to /cart/add + JSON data', str_contains($calc, 'id="calcForm"') && str_contains($calc, 'id="calc-data"') && str_contains($calc, '/cart/add'));
    $assert->that('card: Add to bag is now a POST form', str_contains(store_card(\App\Services\Store\Catalog::cards([$tile])[0]), 'action="' . route('store.cart.add') . '"'));

    $_COOKIE = [];
    Cart::reset();
};
