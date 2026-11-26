<?php

namespace App\Controllers\Store;

use App\Services\Customer\Phone;
use App\Services\Store\View;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

/**
 * Track an order / pay a balance (docs/storefront.md): order number + the mobile it was
 * placed with → /order/{token} (or /pay/{token}). The same message for every miss (no way to
 * probe which order numbers exist), 10 lookups per hour per IP.
 */
class TrackController
{
    public const PER_HOUR = 10;

    public function show(): Response
    {
        View::loadHelpers();

        return View::page('store.track', [], [
            'title' => 'Track your order',
            'description' => 'Check your order status or pay a balance with your order number and mobile number.',
            'active' => 'track',
            'head' => '<meta name="robots" content="noindex">',
        ]);
    }

    public function find(Request $request): Response
    {
        $go = $request->input('go') === 'pay' ? 'pay' : 'order';
        $number = strtoupper(trim(preg_replace('/\s+/', '', (string) $request->input('order_number', '')) ?? ''));
        $phone = Phone::normalize((string) $request->input('phone', ''));
        $back = (string) route('store.track') . ($go === 'pay' ? '#pay' : '');

        if ($number === '' || $phone === null) {
            session()->flash('error', 'Enter your order number and the 10-digit mobile number you ordered with.');
            session()->flashInput(['order_number' => $number, 'go' => $go]);

            return redirect($back);
        }
        $key = 'track:' . sha1((string) $request->ip());
        $limiter = null;
        try {
            $limiter = app('rate_limiter');
        } catch (\Throwable) {
        }
        if ($limiter !== null && $limiter->tooManyAttempts($key, self::PER_HOUR)) {
            session()->flash('error', 'Too many tries. Please wait an hour, or message us on WhatsApp with your order number.');

            return redirect($back);
        }
        $limiter?->hit($key, 3600);

        $o = DB::selectOne('SELECT public_token, customer_phone FROM orders WHERE UPPER(order_number) = ?', [$number]);
        if ($o === null || !hash_equals((string) Phone::normalize((string) $o['customer_phone']), $phone)) {
            session()->flash('error', 'We couldn’t find an order with that number and mobile. Check both, or message us on WhatsApp.');
            session()->flashInput(['order_number' => $number, 'go' => $go]);

            return redirect($back);
        }

        return redirect((string) route($go === 'pay' ? 'pay.show' : 'order.public', ['token' => $o['public_token']]));
    }
}
