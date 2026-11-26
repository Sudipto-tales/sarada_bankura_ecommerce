<?php

namespace App\Controllers\Store;

use App\Services\Order\PaymentService;
use App\Services\Store\Calculator;
use App\Services\Store\Cart;
use App\Services\Store\Catalog;
use App\Services\Store\View;
use Vayu\Http\Request;
use Vayu\Http\Response;

/**
 * Bag + area calculator (docs/storefront.md). Every write is a plain form post that
 * redirects back with a toast; store.js sends the same forms with fetch (Accept: application/json)
 * and gets JSON instead. Prices are never read from the request.
 */
class CartController
{
    public function show(): Response
    {
        View::loadHelpers();
        // A checkout that was paid but never reached /checkout/done: finish it now.
        $done = \App\Services\Store\Checkout::reconcile();
        if ($done !== null) {
            session()->flash($done['ok'] ? 'success' : 'error', $done['ok'] ? 'Your order ' . $done['order_number'] . ' is placed — we’re checking your payment.' : (string) $done['message']);
            if ($done['ok']) {
                return redirect((string) route('order.public', ['token' => $done['order_token']]));
            }
        }
        $lines = Cart::lines();
        $inBag = array_map(fn ($l) => $l['product_id'], $lines);

        return View::page('store.cart', [
            'lines' => $lines,
            'totals' => Cart::totals($lines),
            'advance_percent' => PaymentService::ADVANCE_PERCENT,
            'tax_included' => (bool) business('tax.prices_include_tax', false),
            'checkout' => route('store.checkout'),
            'reco' => $lines === [] ? [] : self::reco($inBag),
        ], [
            'title' => 'Your bag',
            'active' => 'bag',
            'head' => '<meta name="robots" content="noindex">',
        ]);
    }

    public function add(Request $request): Response
    {
        $in = (array) $request->input();
        $res = Cart::add((int) ($in['product_id'] ?? 0), (int) ($in['variant_id'] ?? 0), $in['qty'] ?? null, $request->ip(), $request->header('User-Agent'));
        $msg = $res['ok'] ? 'Added ' . \App\Services\Store\Purchasable::fmt((float) $res['qty']) . ' ' . $res['unit'] . ' of ' . $res['name'] . ' to your bag' : (string) $res['error'];

        if ($request->wantsJson()) {
            return (new Response())->json(['ok' => $res['ok'], 'count' => $res['count'], 'message' => $msg], $res['ok'] ? 200 : 422);
        }
        session()->flash($res['ok'] ? 'success' : 'error', $msg);
        if ($res['ok'] && ($in['next'] ?? '') === 'checkout') {
            return redirect((string) (route('store.checkout') ?? route('store.cart')));
        }

        return redirect(self::back($request));
    }

    public function update(Request $request): Response
    {
        $in = (array) $request->input();
        $res = Cart::update((int) ($in['line'] ?? 0), $in['qty'] ?? null);

        return $this->done($request, $res, $res['ok'] ? 'Bag updated' : (string) $res['error']);
    }

    /** Remove a line. `later=1` = "Save for later": store.js adds it to the saved list first. */
    public function remove(Request $request): Response
    {
        $in = (array) $request->input();
        $res = Cart::remove((int) ($in['line'] ?? 0));

        return $this->done($request, $res, !empty($in['later']) ? 'Moved to your saved list' : 'Removed from your bag');
    }

    public function calculator(): Response
    {
        View::loadHelpers();
        $stones = Calculator::stones();

        return View::page('store.calculator', [
            'stones' => $stones,
            'tax_rate' => Cart::taxRate(),
            'advance_percent' => PaymentService::ADVANCE_PERCENT,
        ], [
            'title' => 'Area & cost calculator',
            'description' => 'Work out how much stone to order for your rooms — cutting waste, boxes and GST included.',
            'active' => 'plan',
            'canonical' => base_url('/calculator'),
        ]);
    }

    /* ================================================================ helpers */

    private function done(Request $request, array $res, string $msg): Response
    {
        if ($request->wantsJson()) {
            return (new Response())->json(['ok' => $res['ok'], 'count' => $res['count'], 'message' => $msg], $res['ok'] ? 200 : 422);
        }
        session()->flash($res['ok'] ? 'success' : 'error', $msg);

        return redirect((string) route('store.cart'));
    }

    /** Same-site Referer path (+ query), else /cart. */
    public static function back(Request $request): string
    {
        $ref = (string) $request->header('Referer', '');
        $u = $ref !== '' ? parse_url($ref) : false;
        $base = parse_url(base_url('/'));
        if (!is_array($u) || !isset($u['path']) || (isset($u['host']) && strcasecmp($u['host'], (string) ($_SERVER['HTTP_HOST'] ?? $base['host'] ?? '')) !== 0 && strcasecmp($u['host'], (string) ($base['host'] ?? '')) !== 0)) {
            return (string) route('store.cart');
        }
        $prefix = rtrim((string) ($base['path'] ?? ''), '/');
        $path = $u['path'];
        if ($prefix !== '' && str_starts_with($path, $prefix)) {
            $path = substr($path, strlen($prefix)) ?: '/';
        }

        return EnquiryController::back($path . (isset($u['query']) ? '?' . $u['query'] : ''));
    }

    /** "Often bought together": buyable featured / best-selling stones not already in the bag. */
    private static function reco(array $exclude): array
    {
        $ids = array_map('intval', array_column(\Vayu\Database\DB::select(
            "SELECT id FROM products WHERE status = 'active' AND is_enquiry_only = 0 AND show_price = 1 ORDER BY is_featured DESC, is_bestseller DESC, id DESC LIMIT 30"
        ), 'id'));
        $cards = array_filter(Catalog::cards(array_values(array_diff($ids, $exclude))), fn ($c) => $c['buyable']);

        return array_slice(array_values($cards), 0, 6);
    }
}
