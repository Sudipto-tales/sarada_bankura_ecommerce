<?php

namespace App\Services\Store;

use App\Services\Customer\CustomerService;
use App\Services\Customer\Phone;
use App\Services\NotificationService;
use App\Services\Order\DocumentNumber;
use Vayu\Database\DB;

/**
 * Storefront enquiries (docs/storefront.md). The only writer of the `enquiries` table.
 *
 *   Enquiries::submit($input, 'product', $ip, $ua)   public forms: contact / product / home / calculator
 *   Enquiries::update($id, [...], $by)               admin inbox: status, assignee, internal note
 *
 * Spam guards (same idea as ReviewSubmission, no captcha service): CSRF (framework), a honeypot
 * field `website`, a signed "form shown at" time trap `ts`, a per-IP hourly limit and a same-phone
 * same-text duplicate check. A caught bot gets the normal "thanks" answer and nothing is stored.
 *
 * Phone = identity: the enquiry is linked to the customer with that mobile, or a new online
 * customer is created, so the person shows up in /admin/customers with their enquiry history.
 */
final class Enquiries
{
    public const SOURCES = ['contact' => 'Contact page', 'product' => 'Product page', 'home' => 'Home page', 'calculator' => 'Calculator'];
    public const STATUSES = ['new' => 'New', 'contacted' => 'Contacted', 'quoted' => 'Quoted', 'converted' => 'Converted', 'closed' => 'Closed'];
    /** What the person wants (product page): a quote, or a message when a coming-soon stone lands. */
    public const KINDS = ['quote' => 'Quote request', 'notify' => 'Notify when available', 'restock' => 'Restock request', 'photos' => 'Photos of the lot', 'general' => 'Enquiry'];
    public const MAX_PER_HOUR = 6;
    public const MIN_SECONDS = 3;
    public const MAX_FORM_AGE = 86400 * 2;
    public const MESSAGE_MAX = 2000;

    /** Signed timestamp for the hidden `ts` field. */
    public static function stamp(?int $time = null): string
    {
        $t = (string) ($time ?? time());

        return $t . '.' . substr(hash_hmac('sha256', 'enquiry|' . $t, self::secret()), 0, 20);
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

        return hash_equals(substr(hash_hmac('sha256', 'enquiry|' . $m[1], self::secret()), 0, 20), $m[2]);
    }

    /**
     * @param array<string,mixed> $in request fields
     * @return array{ok:bool, spam?:bool, errors?:array<string,string>, id?:int, number?:string, duplicate?:bool}
     */
    public static function submit(array $in, ?string $ip, ?string $userAgent, ?int $now = null): array
    {
        if (trim((string) ($in['website'] ?? '')) !== '' || !self::stampOk((string) ($in['ts'] ?? ''), $now)) {
            return ['ok' => true, 'spam' => true];
        }

        $limiter = app('rate_limiter');
        $key = 'enquiry:ip:' . ($ip ?: 'unknown');
        if ($limiter->tooManyAttempts($key, self::MAX_PER_HOUR)) {
            $mins = max(1, (int) ceil($limiter->availableIn($key) / 60));

            return ['ok' => false, 'errors' => ['form' => 'You have sent several enquiries in a short time. Please try again in ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . ', or message us on WhatsApp.']];
        }

        [$data, $errors] = self::validate($in);
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        // Double submit / refresh: same mobile, same text, last 24 h → the first one stands.
        $since = date('Y-m-d H:i:s', time() - 86400);
        $dup = $data['product_id'] !== null
            ? DB::selectOne('SELECT id, enquiry_number FROM enquiries WHERE phone = ? AND message = ? AND product_id = ? AND created_at >= ?', [$data['phone'], $data['message'], $data['product_id'], $since])
            : DB::selectOne('SELECT id, enquiry_number FROM enquiries WHERE phone = ? AND message = ? AND product_id IS NULL AND created_at >= ?', [$data['phone'], $data['message'], $since]);
        if ($dup) {
            return ['ok' => true, 'id' => (int) $dup['id'], 'number' => (string) $dup['enquiry_number'], 'duplicate' => true];
        }

        $limiter->hit($key, 3600);
        $ts = date('Y-m-d H:i:s');
        [$id, $number] = DB::transaction(static function () use ($data, $ip, $userAgent, $ts): array {
            $cid = self::customerFor($data);
            $number = DocumentNumber::next('enquiry');
            $id = (int) DB::table('enquiries')->insertGetId($data + [
                'enquiry_number' => $number,
                'customer_id' => $cid,
                'status' => 'new',
                'ip' => $ip ? mb_substr($ip, 0, 45) : null,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                'created_at' => $ts,
                'updated_at' => $ts,
            ]);

            return [$id, $number];
        });

        $about = $data['product_id'] ? (string) (DB::selectOne('SELECT name FROM products WHERE id = ?', [$data['product_id']])['name'] ?? '') : '';
        NotificationService::notify(
            'enquiry.received',
            'New enquiry from ' . $data['name'] . ($about !== '' ? ' · ' . $about : ''),
            mb_strimwidth(str_replace("\n", ' ', $data['message']), 0, 140, '…') . ' — ' . Phone::format($data['phone']) . ($data['city'] ? ', ' . $data['city'] : ''),
            (string) route('admin.enquiries') . '?open=' . $id,
            'info',
            ['enquiry_id' => $id, 'number' => $number, 'source' => $data['source']],
            'enquiry:' . $id,
            'message-circle-question',
        );

        return ['ok' => true, 'id' => $id, 'number' => $number];
    }

    /**
     * @param array<string,mixed> $in
     * @return array{0:array<string,mixed>, 1:array<string,string>}
     */
    public static function validate(array $in): array
    {
        $errors = [];
        $str = static fn(string $k, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', is_string($in[$k] ?? null) ? $in[$k] : '') ?? ''), 0, $max);

        $source = is_string($in['source'] ?? null) && isset(self::SOURCES[$in['source']]) ? $in['source'] : 'contact';
        $kind = is_string($in['kind'] ?? null) && isset(self::KINDS[$in['kind']]) ? $in['kind'] : ($source === 'product' ? 'quote' : 'general');

        $name = $str('name', 120);
        if (mb_strlen($name) < 2) {
            $errors['name'] = 'Add your name.';
        }
        $phone = Phone::normalize(is_string($in['phone'] ?? null) ? $in['phone'] : '');
        if ($phone === null) {
            $errors['phone'] = 'Enter a 10-digit mobile number.';
        }
        $email = mb_strtolower($str('email', 190));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address, or leave it blank.';
        }

        // Product (optional): must be listed; a variant must belong to it.
        $productId = null;
        $variantId = 0;
        $unit = null;
        $product = null;
        if (is_scalar($in['product_id'] ?? null) && ctype_digit((string) $in['product_id']) && (int) $in['product_id'] > 0) {
            $product = DB::selectOne('SELECT p.id, p.name, pp.unit FROM products p LEFT JOIN product_pricing pp ON pp.product_id = p.id
                                      WHERE p.id = ? AND p.status IN ' . Catalog::LISTED, [(int) $in['product_id']]);
            if ($product === null) {
                $errors['form'] = 'That stone is no longer listed. Please send a general enquiry instead.';
            } else {
                $productId = (int) $product['id'];
                $unit = $product['unit'] ?: null;
                if (is_scalar($in['variant_id'] ?? null) && ctype_digit((string) $in['variant_id']) && (int) $in['variant_id'] > 0) {
                    $v = DB::selectOne("SELECT id, title, sku, unit FROM product_variants WHERE id = ? AND product_id = ? AND status <> 'archived'", [(int) $in['variant_id'], $productId]);
                    if ($v) {
                        $variantId = (int) $v['id'];
                        $unit = $v['unit'] ?: $unit;
                        $product['variant'] = (string) ($v['title'] ?: $v['sku']);
                    }
                }
            }
        }

        // Space / room (optional): a visible category.
        $categoryId = null;
        if (is_scalar($in['category_id'] ?? null) && ctype_digit((string) $in['category_id']) && (int) $in['category_id'] > 0) {
            $c = DB::selectOne('SELECT id FROM product_categories WHERE id = ? AND is_visible = 1', [(int) $in['category_id']]);
            $categoryId = $c ? (int) $c['id'] : null;
        }

        $qty = null;
        $rawQty = is_scalar($in['quantity'] ?? null) ? trim((string) $in['quantity']) : '';
        if ($rawQty !== '') {
            if (!is_numeric($rawQty) || (float) $rawQty <= 0 || (float) $rawQty > 9999999) {
                $errors['quantity'] = 'Enter the approximate area or quantity as a number.';
            } else {
                $qty = round((float) $rawQty, 3);
                $unit ??= 'sq ft';
            }
        }

        $message = trim(str_replace("\r\n", "\n", is_string($in['message'] ?? null) ? $in['message'] : ''));
        if (mb_strlen($message) > self::MESSAGE_MAX) {
            $errors['message'] = 'Please keep the message under ' . self::MESSAGE_MAX . ' characters.';
        } elseif (preg_match_all('#https?://|www\.#i', $message) > 2) {
            $errors['message'] = 'Please leave out links.';
        }
        $visit = $str('visit_date', 10);
        if ($visit !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $visit)) {
            $visit = '';
        }
        if ($message === '' && $product === null && $kind === 'general') {
            $errors['message'] = 'Tell us a little about what you need.';
        }

        // The stored message always says what was asked, so the inbox reads well without opening it.
        $lead = $product !== null ? self::KINDS[$kind] . ': ' . $product['name'] . (isset($product['variant']) ? ' (' . $product['variant'] . ')' : '') : '';
        $parts = array_values(array_filter([$lead, $message, $visit !== '' ? 'Would like to visit the showroom on ' . date('j M Y', strtotime($visit)) . '.' : ''], fn ($s) => $s !== ''));

        return [[
            'name' => $name,
            'phone' => (string) $phone,
            'email' => $email !== '' ? $email : null,
            'city' => $str('city', 80) ?: null,
            'category_id' => $categoryId,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'quantity' => $qty,
            'unit' => $qty !== null ? mb_substr((string) $unit, 0, 16) : null,
            'message' => mb_substr(implode("\n\n", $parts), 0, self::MESSAGE_MAX + 300),
            'source' => $source,
        ], $errors];
    }

    /** Existing customer with this mobile, else a new online customer. Runs inside submit()'s transaction. */
    private static function customerFor(array $data): ?int
    {
        $cid = DB::table('customers')->where('phone', $data['phone'])->value('id');
        if ($cid !== null) {
            return (int) $cid;
        }
        try {
            return CustomerService::save(CustomerService::validate([
                'name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'] ?? '',
                'customer_type' => 'individual', 'source' => 'online', 'notes' => 'Created from an enquiry.',
            ]));
        } catch (\Throwable) {
            return null; // never lose the enquiry because the customer record could not be made
        }
    }

    /* ================================================================ admin */

    /**
     * Admin changes from the inbox drawer. Only the keys given are changed.
     *
     * @param array{status?:string, assigned_to?:int|string|null, internal_note?:string, converted_order_id?:int|null} $in
     */
    public static function update(int $id, array $in): void
    {
        $row = DB::selectOne('SELECT * FROM enquiries WHERE id = ?', [$id]);
        if ($row === null) {
            throw new \InvalidArgumentException('That enquiry no longer exists.');
        }
        $set = [];
        if (array_key_exists('status', $in)) {
            if (!isset(self::STATUSES[(string) $in['status']])) {
                throw new \InvalidArgumentException('Unknown status.');
            }
            $set['status'] = (string) $in['status'];
        }
        if (array_key_exists('assigned_to', $in)) {
            $uid = is_scalar($in['assigned_to']) && ctype_digit((string) $in['assigned_to']) ? (int) $in['assigned_to'] : 0;
            if ($uid > 0 && !DB::table('admin_users')->where('id', $uid)->where('status', 'active')->exists()) {
                throw new \InvalidArgumentException('Pick a staff member from the list.');
            }
            $set['assigned_to'] = $uid > 0 ? $uid : null;
        }
        if (array_key_exists('internal_note', $in)) {
            $set['internal_note'] = blank_to_null(mb_substr(trim(str_replace("\r\n", "\n", (string) $in['internal_note'])), 0, 4000));
        }
        if (array_key_exists('converted_order_id', $in)) {
            $oid = (int) $in['converted_order_id'];
            if ($oid > 0 && !DB::table('orders')->where('id', $oid)->exists()) {
                throw new \InvalidArgumentException('That order does not exist.');
            }
            $set['converted_order_id'] = $oid > 0 ? $oid : null;
            if ($oid > 0) {
                $set['status'] = 'converted';
            }
        }
        if ($set === []) {
            return;
        }
        DB::table('enquiries')->where('id', $id)->update($set + ['updated_at' => date('Y-m-d H:i:s')]);
        if (($set['status'] ?? 'new') !== 'new') {
            NotificationService::markReadByKey('enquiry:' . $id);
        }
    }

    /** Tab counts for the inbox. @return array<string,int> */
    public static function counts(): array
    {
        $out = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach (DB::select('SELECT status, COUNT(*) AS n FROM enquiries GROUP BY status') as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }

        return $out;
    }

    private static function secret(): string
    {
        $key = (string) config('app.key', '');

        return $key !== '' ? $key : 'vayu-enquiry-' . __DIR__;
    }
}
