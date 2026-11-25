<?php

namespace App\Middleware;

use App\Services\Store\Context;
use App\Services\Store\CustomerAuth;
use Vayu\Http\Request;
use Vayu\Http\Response;
use Vayu\Middleware\MiddlewareInterface;

/**
 * Loads the signed-in storefront customer (docs/storefront.md §3.1, §11): session('customer'),
 * else a valid "stay signed in" cookie. Never blocks a page — RequireCustomer does that.
 */
class StoreCustomer implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next): Response
    {
        $user = CustomerAuth::resolve();
        Context::setCustomer($user);
        if ($user !== null) {
            $request->setAttribute('customer', $user);
        }

        return $next($request);
    }
}
