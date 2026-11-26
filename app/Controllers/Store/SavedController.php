<?php

namespace App\Controllers\Store;

use App\Services\Store\Catalog;
use App\Services\Store\Context;
use App\Services\Store\Saved;
use App\Services\Store\View;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

/**
 * Saved stones (docs/storefront.md). Guests keep the shortlist in the browser
 * (localStorage `ms.saved`, product slugs). Signed in (S5), the account's list
 * (customer_saved_items via Store\Saved) is the truth: the layout hands it to store.js, which
 * mirrors it locally and sends each heart tap to POST /saved/toggle. The guest list is merged
 * into the account at sign-in. The page asks /saved/cards for the cards either way.
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

    /** POST /saved/toggle {slug, on?} (signed in) → JSON {ok, saved, slugs}. */
    public function toggle(Request $request): Response
    {
        $cid = (int) (Context::customer()['customer_id'] ?? 0);
        $slug = (string) $request->input('slug', '');
        $on = $request->input('on');
        [$saved, $err] = $cid > 0 ? Saved::toggle($cid, $slug, $on === null || $on === '' ? null : (bool) (int) $on) : [false, 'Please sign in again.'];

        return json(['ok' => $err === null, 'saved' => $saved, 'message' => $err, 'slugs' => $cid > 0 ? Saved::slugs($cid) : []], $err === null ? 200 : 422);
    }
}
