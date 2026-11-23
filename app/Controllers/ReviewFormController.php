<?php

namespace App\Controllers;

use App\Controllers\Admin\ReviewController;
use App\Services\Reviews\ReviewSubmission;
use Vayu\Http\Request;
use Vayu\Http\Response;

/**
 * Public "write a review" page: /review/{slug}. Standalone now; the storefront embeds it later.
 */
class ReviewFormController
{
    public function show(Request $request, string $slug): Response
    {
        $product = ReviewSubmission::product($slug);
        if ($product === null) {
            abort(404, 'This product is not available for reviews.');
        }

        return view('review', [
            'product' => $product,
            'applications' => ReviewController::APPLICATIONS,
            'stamp' => ReviewSubmission::stamp(),
            'thanks' => (bool) session()->get('review_thanks'),
            'errors' => (array) (session()->get('review_errors') ?? []),
            'old' => (array) (session()->get('review_old') ?? []),
            'maxPhotos' => ReviewSubmission::MAX_PHOTOS,
            'photoLimit' => min(ReviewSubmission::PHOTO_BYTES, \App\Services\Media\MediaService::serverLimit()),
        ]);
    }

    public function submit(Request $request, string $slug): Response
    {
        $product = ReviewSubmission::product($slug);
        if ($product === null) {
            abort(404, 'This product is not available for reviews.');
        }
        $input = (array) $request->input();
        $photos = [];
        for ($i = 1; $i <= ReviewSubmission::MAX_PHOTOS; $i++) {
            $photos[] = $request->file('photo' . $i);
        }
        // Rate limit on the socket address: X-Forwarded-For is client-controlled.
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? $request->ip() ?? '');
        $res = ReviewSubmission::submit($product, $input, $photos, $ip !== '' ? $ip : null, $request->header('User-Agent'));

        $back = route('review.show', ['slug' => $product['slug']]) ?? '/review/' . $product['slug'];
        if ($res['ok']) {
            session()->flash('review_thanks', true);

            return redirect($back . '#thanks');
        }
        session()->flash('review_errors', $res['errors'] ?? []);
        unset($input['_token'], $input['ts'], $input['website']);
        session()->flash('review_old', array_map(static fn($v) => is_string($v) ? mb_substr($v, 0, 2000) : '', $input));

        return redirect($back . '#review');
    }
}
