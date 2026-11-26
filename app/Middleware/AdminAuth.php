<?php

namespace App\Middleware;

use App\Services\AdminAccess\Guard;
use Vayu\Http\Request;
use Vayu\Http\Response;
use Vayu\Middleware\MiddlewareInterface;

/**
 * Protects the /admin area (docs/admin-access.md). Not logged in → /admin/login?next=…
 * (JSON callers get 401). Logged in with a temporary password → only the "Set your password"
 * screen and Log out are reachable until a real password is set.
 * The login / invite / forgot-password routes are registered outside this middleware.
 */
class AdminAuth implements MiddlewareInterface
{
    /** Reachable while must_change_password is on. */
    private const FIRST_PASSWORD_PATHS = ['/admin/password/set', '/admin/logout'];

    public function handle(Request $request, \Closure $next): Response
    {
        $user = Guard::user();
        if ($user === null) {
            if ($request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest' || $request->method !== 'GET') {
                if ($request->wantsJson()) {
                    return (new Response())->json(['message' => 'Your session has ended. Please log in again.', 'login' => '/admin/login'], 401);
                }
                session()->flash('warning', 'Your session has ended. Please log in again.');

                return redirect(route('admin.login'));
            }
            $target = $request->path . ($request->query ? '?' . http_build_query($request->query) : '');

            return redirect(route('admin.login') . (rtrim($request->path, '/') !== '/admin' ? '?next=' . rawurlencode($target) : ''));
        }

        if ((int) $user['must_change_password'] === 1 && !in_array(rtrim($request->path, '/'), self::FIRST_PASSWORD_PATHS, true)) {
            if ($request->wantsJson()) {
                return (new Response())->json(['message' => 'Set your own password first.'], 403);
            }

            return redirect(route('admin.password.set'));
        }

        $request->setAttribute('admin', $user);
        $response = $next($request);

        // Admin pages hold private data: don't let the browser cache them (Back after logout).
        $headers = array_change_key_case($response->getHeaders());
        if (!isset($headers['cache-control'])) {
            $response->header('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
