<?php

namespace App\Controllers\Store;

use App\Services\Order\PaymentService;
use App\Services\Payment\PayWindow;
use App\Services\Store\View;
use Vayu\Http\Response;

/** Static storefront pages (docs/storefront.md S1): policies. */
class PageController
{
    public const POLICY_SECTIONS = ['delivery', 'payments', 'orders', 'returns', 'privacy', 'terms'];

    public function policy(): Response
    {
        return View::page('store.policy', [
            'advance' => PaymentService::ADVANCE_PERCENT,
            'gst' => (float) business('tax.gst_rate', 18),
            'taxIncluded' => (bool) business('tax.prices_include_tax', false),
            'windowMinutes' => intdiv(PayWindow::SECONDS, 60),
            'otpDays' => (int) business('delivery.otp_days', 7),
        ], [
            'title' => 'Policies',
            'description' => 'Delivery, payments, orders, returns and privacy at ' . business('name') . '.',
            'canonical' => base_url('/policy'),
        ]);
    }

    /** /policy/returns → /policy#returns (one page, linkable sections). */
    public function policySection(string $page): Response
    {
        if (!in_array($page, self::POLICY_SECTIONS, true)) {
            abort(404);
        }

        return redirect(base_url('/policy') . '#' . $page, 301);
    }
}
