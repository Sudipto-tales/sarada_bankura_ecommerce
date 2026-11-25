<?php

namespace App\Middleware;

use App\Services\Store\Context;
use App\Services\Store\View;
use Vayu\Http\Request;
use Vayu\Http\Response;
use Vayu\Middleware\MiddlewareInterface;

/**
 * Pages that need a customer account (checkout, /account, saving to the account). A guest is sent
 * to /signin?next=<this page> and comes straight back after signing in or creating an account.
 * JSON callers get 401 with the sign-in URL. Runs after StoreCustomer.
 */
class RequireCustomer implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next): Response
    {
        if (Context::customer() !== null) {
            return $next($request)->header('Cache-Control', 'no-store, private');
        }
        View::loadHelpers();
        $path = '/' . ltrim((string) $request->path, '/');
        $back = $request->method === 'GET' ? $path : (string) parse_url((string) $request->header('Referer'), PHP_URL_PATH);
        $signin = store_signin_url($back !== '' ? $back : '/account');
        if ($request->wantsJson()) {
            return json(['ok' => false, 'signin' => true, 'message' => 'Please sign in or create an account to continue.', 'url' => $signin], 401);
        }

        return redirect($signin);
    }
}
