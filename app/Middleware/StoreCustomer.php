<?php

namespace App\Middleware;

use App\Services\Store\Context;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;
use Vayu\Middleware\MiddlewareInterface;

/**
 * Loads the signed-in storefront customer from the session (docs/storefront.md §3.1).
 * Sign-in / register and the "stay signed in" cookie come in S5 (Store\CustomerAuth); until
 * then this only reads session('customer_user_id').
 */
class StoreCustomer implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next): Response
    {
        $id = (int) session()->get('customer_user_id', 0);
        $user = null;
        if ($id > 0) {
            $rows = DB::select('SELECT * FROM users WHERE id = ?', [$id]);
            $user = $rows[0] ?? null;
            if ($user === null || (isset($user['status']) && $user['status'] !== 'active')) {
                session()->forget('customer_user_id');
                $user = null;
            }
        }
        Context::setCustomer($user);
        if ($user !== null) {
            $request->setAttribute('customer', $user);
        }

        return $next($request);
    }
}
