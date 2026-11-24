<?php

namespace App\Controllers\Store;

use App\Services\Store\Enquiries;
use Vayu\Http\Request;
use Vayu\Http\Response;

/**
 * POST /enquiry — every public enquiry form (contact, product, home, calculator) posts here.
 * Plain form post → redirect back to the page it came from (`back`, a local path) with a thank-you
 * or the errors; fetch() callers (Accept: application/json) get JSON.
 */
class EnquiryController
{
    public function store(Request $request): Response
    {
        $input = (array) $request->input();
        $res = Enquiries::submit($input, $request->ip(), $request->header('User-Agent'));
        $back = self::back($input['back'] ?? null);

        if ($request->wantsJson()) {
            return (new Response())->json($res['ok']
                ? ['ok' => true, 'number' => $res['number'] ?? null, 'message' => self::thanks($res)]
                : ['ok' => false, 'errors' => $res['errors'] ?? []], $res['ok'] ? 200 : 422);
        }

        if (!$res['ok']) {
            session()->flash('enquiry_errors', $res['errors'] ?? []);
            session()->flash('enquiry_old', array_intersect_key($input, array_flip(['name', 'phone', 'email', 'city', 'category_id', 'quantity', 'message', 'visit_date', 'kind'])));
            session()->flash('error', reset($res['errors']) ?: 'Please check the form.');

            return redirect($back . '#enquire');
        }
        session()->flash('enquiry_thanks', $res['number'] ?? true);
        session()->flash('success', self::thanks($res));

        return redirect($back . '#enquire');
    }

    private static function thanks(array $res): string
    {
        return 'Thank you — we’ll call you within a working day' . (!empty($res['number']) ? ' (ref. ' . $res['number'] . ')' : '') . '.';
    }

    /** Only same-site paths: "/product/x" yes, "//evil.com" or "https://…" no. */
    public static function back(mixed $path): string
    {
        $path = is_string($path) ? trim($path) : '';
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\') || preg_match('/[\x00-\x1f]/', $path)) {
            return base_url('/contact');
        }
        $path = strtok($path, '#') ?: '/contact';

        return base_url($path);
    }
}
