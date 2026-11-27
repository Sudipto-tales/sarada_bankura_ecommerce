<?php

namespace App\Controllers\Store;

use App\Services\Store\Enquiries;
use App\Services\Store\ProductPage;
use App\Services\Store\View;
use Vayu\Http\Request;
use Vayu\Http\Response;

/** Product page (docs/storefront.md): /product/{slug}?v={variant sku}. Works without JS. */
class ProductController
{
    public function show(Request $request, string $slug): Response
    {
        View::loadHelpers();
        $v = $request->query('v');
        $d = ProductPage::load($slug, is_string($v) ? mb_substr($v, 0, 64) : null);
        if ($d === null) {
            abort(404);
        }
        $p = $d['product'];
        $canonical = base_url($p['url']);
        $og = $d['gallery'] !== [] ? '<meta property="og:image" content="' . e(base_url($d['gallery'][0]['full'])) . '">' : '';

        return View::page('store.product', $d + [
            'stamp' => Enquiries::stamp(),
            'enquiry' => [
                'errors' => (array) (session()->get('enquiry_errors') ?? []),
                'old' => (array) (session()->get('enquiry_old') ?? []),
                'thanks' => session()->get('enquiry_thanks'),
            ],
        ], [
            'title' => $p['meta_title'] !== '' ? $p['meta_title'] : $p['name'],
            'description' => $p['meta_description'] !== '' ? $p['meta_description'] : ($p['short'] !== '' ? $p['short'] : null),
            'active' => 'shop',
            'tabbar' => false,
            'canonical' => $canonical,
            'head' => $og . '<meta property="og:type" content="product">'
                . '<script type="application/ld+json">' . store_json(ProductPage::jsonLd($d)) . '</script>',
        ]);
    }
}
