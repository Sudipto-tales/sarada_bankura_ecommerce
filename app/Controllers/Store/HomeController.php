<?php

namespace App\Controllers\Store;

use App\Services\Store\Catalog;
use App\Services\Store\View;
use Vayu\Http\Response;

/** Storefront home (docs/storefront.md S1). */
class HomeController
{
    public function index(): Response
    {
        return View::page('store.home', ['home' => Catalog::home()], [
            'active' => 'home',
            'canonical' => base_url('/'),
        ]);
    }
}
