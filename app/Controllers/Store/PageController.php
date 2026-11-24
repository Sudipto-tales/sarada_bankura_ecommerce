<?php

namespace App\Controllers\Store;

use App\Services\Order\PaymentService;
use App\Services\Payment\PayWindow;
use App\Services\Store\Catalog;
use App\Services\Store\Enquiries;
use App\Services\Store\View;
use Vayu\Http\Response;

/** Static storefront pages (docs/storefront.md S1–S2): policies, contact. */
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

    /** Contact / visit page with the general enquiry form (posts to /enquiry). */
    public function contact(): Response
    {
        return View::page('store.contact', [
            'spaces' => Catalog::chrome()['spaces'],
            'stamp' => Enquiries::stamp(),
            'advance' => PaymentService::ADVANCE_PERCENT,
            'gst' => (float) business('tax.gst_rate', 18),
            'taxIncluded' => (bool) business('tax.prices_include_tax', false),
            'enquiry' => [
                'errors' => (array) (session()->get('enquiry_errors') ?? []),
                'old' => (array) (session()->get('enquiry_old') ?? []),
                'thanks' => session()->get('enquiry_thanks'),
            ],
        ], [
            'title' => 'Contact & visit',
            'description' => 'Visit the ' . business('name') . ' showroom in ' . business('address.city', 'Bankura') . ', call, WhatsApp or send us your project for a quote.',
            'active' => 'contact',
            'canonical' => base_url('/contact'),
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
