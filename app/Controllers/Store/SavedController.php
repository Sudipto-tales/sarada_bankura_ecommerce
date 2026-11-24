<?php

namespace App\Controllers\Store;

use App\Services\Store\Catalog;
use App\Services\Store\View;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

/**
 * Saved stones (docs/storefront.md §3.8). Until S5 the shortlist lives in the browser
 * (localStorage `ms.saved`, a list of product slugs); the page asks /saved/cards for their cards.
 * Signed-in saving (customer_saved_items) + merge arrive with accounts in S5.
 */
class SavedController
{
    public const MAX = 60;

    public function index(): Response
    {
        View::loadHelpers();

        return View::page('store.saved', [], [
            'title' => 'Saved stones',
            'active' => 'saved',
            'head' => '<meta name="robots" content="noindex">',
        ]);
    }

    /** GET /saved/cards?s=slug1,slug2 → card HTML for the listed ones, in that order. */
    public function cards(Request $request): Response
    {
        View::loadHelpers();
        $slugs = array_slice(array_values(array_unique(array_filter(
            explode(',', mb_substr((string) $request->query('s', ''), 0, 4000)),
            fn ($s) => preg_match('/^[a-z0-9][a-z0-9-]{0,190}$/', $s) === 1
        ))), 0, self::MAX);
        $html = '';
        if ($slugs !== []) {
            $in = implode(',', array_fill(0, count($slugs), '?'));
            $bySlug = [];
            foreach (DB::select("SELECT id, slug FROM products WHERE slug IN ($in)", $slugs) as $r) {
                $bySlug[(string) $r['slug']] = (int) $r['id'];
            }
            $ids = array_values(array_filter(array_map(fn ($s) => $bySlug[$s] ?? 0, $slugs)));
            foreach (Catalog::cards($ids) as $c) {
                $html .= store_card($c);
            }
        }

        return (new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']));
    }
}
