<?php

namespace App\Controllers\Store;

use App\Services\Store\Catalog;
use App\Services\Store\View;
use Vayu\Http\Request;
use Vayu\Http\Response;

/** Brands page (docs/storefront.md S1): active brands with up to 4 stones each, ?tier= filter. */
class BrandController
{
    private const TIERS = ['all', 'signature', 'core', 'value'];

    public function index(Request $request): Response
    {
        $tier = (string) $request->query('tier', 'all');
        if (!in_array($tier, self::TIERS, true)) {
            $tier = 'all';
        }
        $brands = Catalog::brands(null, 4);
        $counts = [];
        foreach ($brands as $b) {
            $counts[$b['tier']] = ($counts[$b['tier']] ?? 0) + 1;
        }

        return View::page('store.brands', ['brands' => $brands, 'tier' => $tier, 'tierCounts' => $counts], [
            'title' => 'Brands',
            'description' => 'The stone brands and quarry lots we stock at ' . business('name') . ', ' . business('address.city', 'Bankura') . '.',
            'active' => 'brands',
            'canonical' => base_url('/brands'),
        ]);
    }
}
