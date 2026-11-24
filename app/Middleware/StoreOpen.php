<?php

namespace App\Middleware;

use App\Services\Store\Context;
use Vayu\Http\Request;
use Vayu\Http\Response;
use Vayu\Middleware\MiddlewareInterface;

/**
 * Storefront launch switch (docs/storefront.md §3.1, owner default §6 Q4).
 * STOREFRONT_ENABLED=false → "/" shows the coming-soon page and every other storefront page
 * redirects to "/". A logged-in admin sees the real storefront with a preview bar.
 */
class StoreOpen implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next): Response
    {
        if (Context::visible()) {
            $response = $next($request);
            if (Context::previewing()) {
                // Never let a shared cache keep the preview for the public.
                $response->header('Cache-Control', 'no-store, private');
                $response->header('X-Robots-Tag', 'noindex');
            }

            return $response;
        }

        if (rtrim($request->path, '/') === '') {
            return view('coming-soon', ['launch' => (string) config('store.launch_at')])
                ->header('X-Robots-Tag', 'noindex');
        }

        return redirect(base_url('/'));
    }
}
