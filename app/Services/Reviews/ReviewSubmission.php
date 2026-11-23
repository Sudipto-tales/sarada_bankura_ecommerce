<?php

namespace App\Services\Reviews;

use App\Controllers\Admin\ReviewController;
use App\Services\Media\MediaException;
use App\Services\Media\MediaService as Media;
use App\Services\NotificationService;
use Vayu\Database\DB;
use Vayu\Http\UploadedFile;

/**
 * Public review form (/review/{slug}): validation, spam guards and the write.
 *
 * Spam guards (no captcha service): CSRF (framework), a honeypot field, a signed "form shown at"
 * time trap, a per-IP rate limit and a same-text duplicate check. Every new review is `pending`
 * and lands in /admin/reviews with a `review.submitted` notification.
 */
final class ReviewSubmission
{
    public const MAX_PER_HOUR = 5;
    public const MIN_SECONDS = 3;          // humans don't fill the form faster than this
    public const MAX_FORM_AGE = 86400 * 2; // a form left open for 2 days must be reloaded
    public const MAX_PHOTOS = 3;
    public const PHOTO_BYTES = 5 * 1024 * 1024;
    public const BODY_MIN = 20;
    public const BODY_MAX = 1000;

    /** Signed timestamp for the hidden `ts` field. */
    public static function stamp(?int $time = null): string
    {
        $t = (string) ($time ?? time());

        return $t . '.' . substr(hash_hmac('sha256', 'review|' . $t, self::secret()), 0, 20);
    }

    /** Product a public review can be written for (active only), or null. */
    public static function product(string $slug): ?array
    {
        $p = DB::selectOne(
            "SELECT id, name, sku, slug, short_description FROM products WHERE slug = ? AND status = 'active'",
            [mb_strtolower(trim($slug))]
        );
        if ($p) {
            $cover = Media::cover('product', (int) $p['id']);
            $p['image'] = $cover ? ($cover['thumb_url'] ?? $cover['url']) : null;
        }

        return $p;
    }

    /**
     * @param array<string,mixed>              $input  request fields
     * @param list<UploadedFile|null>          $photos photo1..photo3
     * @return array{ok:bool, spam?:bool, errors?:array<string,string>, review_id?:int}
     */
    public static function submit(array $product, array $input, array $photos, ?string $ip, ?string $userAgent): array
    {
        // Honeypot / time trap: answer "ok" so bots learn nothing, but store nothing.
        if (trim((string) ($input['website'] ?? '')) !== '' || !self::stampOk((string) ($input['ts'] ?? ''))) {
            return ['ok' => true, 'spam' => true];
        }

        $limiter = app('rate_limiter');
        $key = 'review:ip:' . ($ip ?: 'unknown');
        if ($limiter->tooManyAttempts($key, self::MAX_PER_HOUR)) {
            $mins = max(1, (int) ceil($limiter->availableIn($key) / 60));

            return ['ok' => false, 'errors' => ['form' => 'You have sent several reviews in a short time. Please try again in ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . '.']];
        }

        [$data, $errors] = self::validate($input);
        $files = [];
        foreach (array_slice($photos, 0, self::MAX_PHOTOS) as $i => $f) {
            if (!$f instanceof UploadedFile || $f->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (in_array($f->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $f->getSize() > self::PHOTO_BYTES) {
                $errors['photos'] = 'Each photo can be up to ' . Media::humanBytes(min(self::PHOTO_BYTES, Media::serverLimit())) . '.';
                continue;
            }
            $files[] = $f;
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        // Same person pasting the same review twice (double submit / refresh).
        $since = date('Y-m-d H:i:s', time() - 86400);
        $dup = DB::selectOne(
            'SELECT id FROM product_reviews WHERE product_id = ? AND ip = ? AND body = ? AND created_at >= ?',
            [(int) $product['id'], $ip, $data['body'], $since]
        );
        if ($dup) {
            return ['ok' => true, 'review_id' => (int) $dup['id']];
        }

        $limiter->hit($key, 3600);
        $now = date('Y-m-d H:i:s');
        $rid = (int) DB::table('product_reviews')->insertGetId($data + [
            'product_id' => (int) $product['id'],
            'source' => 'storefront',
            'status' => 'pending',
            'is_verified_purchase' => 0,
            'ip' => $ip ? mb_substr($ip, 0, 45) : null,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $photoErrors = 0;
        foreach ($files as $f) {
            try {
                // Private until a person has checked the review; never shared with other uploads.
                $m = Media::storeUpload($f, ['kinds' => ['image'], 'disk' => 'private', 'dedupe' => false,
                    'alt' => 'Customer photo: ' . $product['name']]);
                Media::attach('review', $rid, (int) $m['id'], 'photo');
            } catch (MediaException $e) {
                $photoErrors++;
            }
        }

        NotificationService::notify(
            'review.submitted',
            'New ' . $data['rating'] . '★ review for ' . $product['name'],
            '“' . mb_strimwidth((string) $data['title'], 0, 80, '…') . '” by ' . $data['customer_name']
                . ($data['city'] ? ', ' . $data['city'] : '') . '. Waiting for approval.',
            route('admin.reviews') . '?status=pending',
            $data['rating'] <= 2 ? 'warning' : 'info',
            ['review_id' => $rid, 'product_id' => (int) $product['id'], 'photos' => count($files) - $photoErrors],
        );

        return ['ok' => true, 'review_id' => $rid, 'photo_errors' => $photoErrors];
    }

    /**
     * @param array<string,mixed> $in
     * @return array{0:array<string,mixed>, 1:array<string,string>}
     */
    public static function validate(array $in): array
    {
        $errors = [];
        $str = static fn(string $k, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($in[$k] ?? '')) ?? ''), 0, $max);

        $rating = (int) ($in['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            $errors['rating'] = 'Choose a star rating.';
        }
        $title = $str('title', 80);
        if ($title === '') {
            $errors['title'] = 'Add a headline.';
        }
        $body = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
        $len = mb_strlen($body);
        if ($len < self::BODY_MIN) {
            $errors['body'] = 'Write a few more words (at least ' . self::BODY_MIN . ' characters).';
        } elseif ($len > self::BODY_MAX) {
            $errors['body'] = 'Please keep it under ' . self::BODY_MAX . ' characters.';
        } elseif (preg_match_all('#https?://|www\.#i', $body) > 1) {
            $errors['body'] = 'Please leave out links.';
        }
        $name = $str('name', 120);
        if (mb_strlen($name) < 2) {
            $errors['name'] = 'Add your name.';
        }

        // "Mobile or email" — optional, never shown publicly.
        $contact = trim((string) ($in['contact'] ?? ''));
        $email = null;
        $phone = null;
        if ($contact !== '') {
            if (filter_var($contact, FILTER_VALIDATE_EMAIL)) {
                $email = mb_strtolower(mb_substr($contact, 0, 160));
            } else {
                $digits = preg_replace('/\D+/', '', $contact) ?? '';
                if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
                    $digits = substr($digits, 2);
                } elseif (strlen($digits) === 11 && $digits[0] === '0') {
                    $digits = substr($digits, 1);
                }
                if (preg_match('/^[6-9]\d{9}$/', $digits)) {
                    $phone = $digits;
                } else {
                    $errors['contact'] = 'Enter a 10-digit mobile number or an email address.';
                }
            }
        }
        $application = trim((string) ($in['application'] ?? ''));

        return [[
            'rating' => max(1, min(5, $rating)),
            'title' => $title,
            'body' => $body,
            'customer_name' => $name,
            'customer_email' => $email,
            'customer_phone' => $phone,
            'city' => $str('city', 64) ?: null,
            'application' => in_array($application, ReviewController::APPLICATIONS, true) ? $application : null,
        ], $errors];
    }

    public static function stampOk(string $stamp, ?int $now = null): bool
    {
        $now ??= time();
        if (!preg_match('/^(\d{9,11})\.([a-f0-9]{20})$/', $stamp, $m)) {
            return false;
        }
        $age = $now - (int) $m[1];
        if ($age < self::MIN_SECONDS || $age > self::MAX_FORM_AGE) {
            return false;
        }

        return hash_equals(substr(hash_hmac('sha256', 'review|' . $m[1], self::secret()), 0, 20), $m[2]);
    }

    private static function secret(): string
    {
        $key = (string) config('app.key', '');

        return $key !== '' ? $key : 'vayu-review-' . __DIR__;
    }
}
