<?php

namespace App\Services\Customer;

use App\Controllers\Admin\CatalogController;
use Vayu\Database\DB;
use Vayu\Validation\ValidationException;

/**
 * Single writer for customers, their addresses and tag assignments.
 *
 *  - phone (10-digit Indian mobile) is the identity: unique, normalised before every write
 *  - a customer whose phone matches a `users` row is linked to that online account
 *  - reviews left from the same phone are attached to the customer
 *  - after every write the customer's dynamic segment memberships are refreshed
 */
final class CustomerService
{
    public const TYPES = ['individual' => 'Individual', 'builder' => 'Builder / contractor', 'dealer' => 'Dealer', 'architect' => 'Architect / designer', 'other' => 'Other'];
    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive', 'blocked' => 'Blocked'];
    public const LIFECYCLES = ['new' => 'New', 'returning' => 'Returning', 'vip' => 'VIP'];
    public const SOURCES = ['admin' => 'Added by staff', 'showroom' => 'Showroom walk-in', 'online' => 'Online store', 'import' => 'Import'];
    public const ADDRESS_TYPES = ['both' => 'Billing & shipping', 'shipping' => 'Shipping', 'billing' => 'Billing'];
    public const TAG_COLORS = ['gold' => '#fff0bf;color:#91670a', 'green' => '#e4f4e9;color:#267a55', 'blue' => '#e3eef8;color:#3d6a92',
        'rose' => '#fbe1dc;color:#b3483a', 'violet' => '#efe7f6;color:#6f4a92', 'sand' => '#f1e8d6;color:#6d5e43'];
    public const MAX_ADDRESSES = 10;

    /**
     * Validate a create/edit form. Throws ValidationException (→ redirect back with errors + old input).
     *
     * @param  array<string,mixed> $in
     * @return array{customer: array<string,mixed>, addresses: list<array<string,mixed>>, tag_ids: list<int>, new_tags: list<string>}
     */
    public static function validate(array $in, ?int $id = null): array
    {
        $err = [];
        $name = trim(preg_replace('/\s+/', ' ', (string) ($in['name'] ?? '')) ?? '');
        if (mb_strlen($name) < 2) {
            $err['name'][] = 'Enter the customer’s name.';
        }
        $phone = Phone::normalize((string) ($in['phone'] ?? ''));
        if ($phone === null) {
            $err['phone'][] = 'Enter a 10-digit Indian mobile number.';
        } else {
            $other = DB::selectOne('SELECT id, name FROM customers WHERE phone = ?' . ($id ? ' AND id <> ?' : ''), $id ? [$phone, $id] : [$phone]);
            if ($other) {
                $err['phone'][] = 'This mobile already belongs to ' . $other['name'] . ' (customer #' . $other['id'] . ').';
            }
        }
        $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err['email'][] = 'Enter a valid email address.';
        }
        $gst = strtoupper(preg_replace('/\s+/', '', (string) ($in['gst_number'] ?? '')) ?? '');
        if ($gst !== '' && !preg_match('/^\d{2}[A-Z0-9]{13}$/', $gst)) {
            $err['gst_number'][] = 'A GSTIN is 15 characters and starts with the 2-digit state code.';
        }
        $type = (string) ($in['customer_type'] ?? 'individual');
        $status = (string) ($in['status'] ?? 'active');
        $source = (string) ($in['source'] ?? 'admin');

        [$addresses, $addrErr] = self::cleanAddresses((array) ($in['addresses'] ?? []), $in['default_address'] ?? null);
        $err += $addrErr;

        $tagIds = array_values(array_unique(array_filter(array_map('intval', (array) ($in['tags'] ?? [])))));
        if ($tagIds) {
            $known = array_map(static fn($r) => (int) $r['id'], DB::table('customer_tags')->whereIn('id', $tagIds)->get(['id']));
            $tagIds = array_values(array_intersect($tagIds, $known));
        }
        $newTags = [];
        foreach (preg_split('/[,;]+/', (string) ($in['new_tags'] ?? '')) ?: [] as $t) {
            $t = trim($t);
            if ($t !== '' && mb_strlen($t) <= 60) {
                $newTags[mb_strtolower($t)] = $t;
            }
        }

        if ($err) {
            throw new ValidationException($err);
        }

        return [
            'customer' => [
                'name' => mb_substr($name, 0, 120),
                'phone' => $phone,
                'email' => $email !== '' ? mb_substr($email, 0, 160) : null,
                'company' => blank_to_null(mb_substr(trim((string) ($in['company'] ?? '')), 0, 160)),
                'gst_number' => $gst !== '' ? $gst : null,
                'customer_type' => isset(self::TYPES[$type]) ? $type : 'individual',
                'status' => isset(self::STATUSES[$status]) ? $status : 'active',
                'source' => isset(self::SOURCES[$source]) ? $source : 'admin',
                'marketing_consent' => !empty($in['marketing_consent']) ? 1 : 0,
                'notes' => blank_to_null(trim((string) ($in['notes'] ?? ''))),
            ],
            'addresses' => $addresses,
            'tag_ids' => $tagIds,
            'new_tags' => array_values($newTags),
        ];
    }

    /**
     * @param  array<int|string,mixed> $rows
     * @return array{0: list<array<string,mixed>>, 1: array<string, list<string>>}
     */
    public static function cleanAddresses(array $rows, mixed $defaultKey): array
    {
        $out = [];
        $err = [];
        $text = static fn(array $r, string $k, int $max): ?string => blank_to_null(mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($r[$k] ?? '')) ?? ''), 0, $max));
        foreach ($rows as $key => $r) {
            if (!is_array($r)) {
                continue;
            }
            $a = [
                'id' => (int) ($r['id'] ?? 0) ?: null,
                'label' => $text($r, 'label', 40),
                'type' => isset(self::ADDRESS_TYPES[$r['type'] ?? '']) ? $r['type'] : 'both',
                'name' => $text($r, 'name', 120),
                'phone' => null,
                'address_line1' => $text($r, 'address_line1', 255),
                'address_line2' => $text($r, 'address_line2', 255),
                'area' => $text($r, 'area', 120),
                'post_office' => $text($r, 'post_office', 120),
                'city' => $text($r, 'city', 120),
                'district' => $text($r, 'district', 120),
                'state' => $text($r, 'state', 80),
                'pincode' => preg_replace('/\D+/', '', (string) ($r['pincode'] ?? '')) ?: null,
                'country' => $text($r, 'country', 60) ?? 'India',
                'is_default' => (string) $defaultKey === (string) $key ? 1 : 0,
            ];
            $filled = array_filter([$a['label'], $a['name'], $a['address_line1'], $a['address_line2'], $a['area'], $a['city'], $a['pincode'], (string) ($r['phone'] ?? '')], static fn($v) => $v !== null && $v !== '');
            if (!$filled) {
                continue; // an untouched blank address card
            }
            $field = static fn(string $f): string => 'addresses.' . $key . '.' . $f;
            if ($a['address_line1'] === null) {
                $err[$field('address_line1')][] = 'Enter the street / building for this address.';
            }
            if ($a['pincode'] !== null && !PincodeLookup::valid($a['pincode'])) {
                $err[$field('pincode')][] = 'A pincode is 6 digits and does not start with 0.';
            }
            if (trim((string) ($r['phone'] ?? '')) !== '') {
                $a['phone'] = Phone::normalize((string) $r['phone']);
                if ($a['phone'] === null) {
                    $err[$field('phone')][] = 'Enter a 10-digit mobile number.';
                }
            }
            $out[] = $a;
        }
        if (count($out) > self::MAX_ADDRESSES) {
            $err['addresses'][] = 'A customer can keep up to ' . self::MAX_ADDRESSES . ' addresses.';
        }
        // Exactly one default when there are addresses.
        if ($out && !array_filter($out, static fn($a) => $a['is_default'])) {
            $out[0]['is_default'] = 1;
        }

        return [$out, $err];
    }

    /**
     * Create or update a customer from validated data. Returns the customer id.
     *
     * @param array{customer: array<string,mixed>, addresses: list<array<string,mixed>>, tag_ids: list<int>, new_tags: list<string>} $data
     */
    public static function save(array $data, ?int $id = null): int
    {
        $now = date('Y-m-d H:i:s');
        $cid = DB::transaction(static function () use ($data, $id, $now): int {
            $row = $data['customer'];
            if ($id) {
                unset($row['source']); // how they joined never changes after creation
                DB::table('customers')->where('id', $id)->update($row + ['updated_at' => $now]);
                $cid = $id;
            } else {
                $cid = (int) DB::table('customers')->insertGetId($row + ['created_at' => $now, 'updated_at' => $now]);
            }
            self::saveAddresses($cid, $data['addresses'], $now);
            $tagIds = array_merge($data['tag_ids'], array_map([self::class, 'tagId'], $data['new_tags']));
            self::syncTags($cid, $tagIds);
            self::linkOnlineAccount($cid);
            self::attachReviews($cid);

            return $cid;
        });
        SegmentMatcher::refreshForCustomer($cid);

        return $cid;
    }

    /** @param list<array<string,mixed>> $addresses */
    private static function saveAddresses(int $cid, array $addresses, string $now): void
    {
        $keep = [];
        foreach ($addresses as $a) {
            $aid = $a['id'];
            unset($a['id']);
            if ($aid && DB::table('customer_addresses')->where('id', $aid)->where('customer_id', $cid)->exists()) {
                DB::table('customer_addresses')->where('id', $aid)->update($a + ['updated_at' => $now]);
            } else {
                $aid = (int) DB::table('customer_addresses')->insertGetId($a + ['customer_id' => $cid, 'created_at' => $now, 'updated_at' => $now]);
            }
            $keep[] = $aid;
        }
        $q = DB::table('customer_addresses')->where('customer_id', $cid);
        if ($keep) {
            $q->whereNotIn('id', $keep);
        }
        $q->delete();
    }

    /**
     * One address from the storefront account page (S5): add ($id null) or edit one of the
     * customer's own. Throws ValidationException with keys line1 / pincode / … (form field names).
     * $makeDefault, or the customer's first address → it becomes the only default.
     *
     * @param array<string,mixed> $in label, name, phone, address_line1, address_line2, area, post_office, city, district, state, pincode
     */
    public static function saveOwnAddress(int $cid, array $in, ?int $id = null, bool $makeDefault = false): int
    {
        if ($id !== null && !DB::table('customer_addresses')->where('id', $id)->where('customer_id', $cid)->exists()) {
            throw new ValidationException(['address' => ['That address is no longer on your account.']]);
        }
        if ($id === null && (int) DB::table('customer_addresses')->where('customer_id', $cid)->count() >= self::MAX_ADDRESSES) {
            throw new ValidationException(['address' => ['You can keep up to ' . self::MAX_ADDRESSES . ' addresses. Remove one first.']]);
        }
        [$rows, $err] = self::cleanAddresses(['a' => ['type' => 'both'] + $in], 'a');
        $fields = [];
        foreach ($err as $k => $m) {
            $fields[str_replace('addresses.a.', '', $k)] = $m;
        }
        if ($rows === [] && $fields === []) {
            $fields['address_line1'][] = 'Enter the house / street for this address.';
        }
        $a = $rows[0] ?? [];
        if ($fields === [] && ($a['pincode'] ?? null) === null) {
            $fields['pincode'][] = 'Enter the 6-digit pincode.';
        }
        if ($fields === [] && ($a['state'] ?? null) === null) {
            $fields['state'][] = 'Pick the state.';
        }
        if ($fields) {
            throw new ValidationException($fields);
        }
        unset($a['id'], $a['is_default']);
        $now = date('Y-m-d H:i:s');

        return DB::transaction(static function () use ($cid, $a, $id, $makeDefault, $now): int {
            $first = !DB::table('customer_addresses')->where('customer_id', $cid)->where('is_default', 1)->exists();
            if ($id !== null) {
                DB::table('customer_addresses')->where('id', $id)->update($a + ['updated_at' => $now]);
            } else {
                $id = (int) DB::table('customer_addresses')->insertGetId($a + ['customer_id' => $cid, 'is_default' => 0, 'created_at' => $now, 'updated_at' => $now]);
            }
            if ($makeDefault || $first) {
                self::makeDefaultAddress($cid, $id);
            }

            return $id;
        });
    }

    /** The customer's own address becomes the default (others lose it). False when it isn't theirs. */
    public static function makeDefaultAddress(int $cid, int $id): bool
    {
        if (!DB::table('customer_addresses')->where('id', $id)->where('customer_id', $cid)->exists()) {
            return false;
        }
        DB::update('UPDATE customer_addresses SET is_default = CASE WHEN id = ? THEN 1 ELSE 0 END WHERE customer_id = ?', [$id, $cid]);

        return true;
    }

    /**
     * Remove one of the customer's own addresses. Orders keep their frozen copy (orders.*_address
     * JSON), so history is unaffected. The next newest address becomes the default.
     */
    public static function deleteOwnAddress(int $cid, int $id): bool
    {
        $row = DB::selectOne('SELECT id, is_default FROM customer_addresses WHERE id = ? AND customer_id = ?', [$id, $cid]);
        if ($row === null) {
            return false;
        }
        DB::table('customer_addresses')->where('id', $id)->delete();
        if ((int) $row['is_default'] === 1) {
            $next = DB::table('customer_addresses')->where('customer_id', $cid)->orderBy('id', 'desc')->value('id');
            if ($next !== null) {
                self::makeDefaultAddress($cid, (int) $next);
            }
        }

        return true;
    }

    /** @param list<int> $tagIds */
    public static function syncTags(int $cid, array $tagIds): void
    {
        $tagIds = array_values(array_unique(array_filter($tagIds)));
        DB::table('customer_tag_map')->where('customer_id', $cid)->delete();
        if ($tagIds) {
            DB::table('customer_tag_map')->insert(array_map(static fn($t) => ['customer_id' => $cid, 'tag_id' => $t], $tagIds));
        }
    }

    /** Find a customer tag by name or create it. */
    public static function tagId(string $name): int
    {
        $slug = CatalogController::slugify($name) ?: 'tag';
        $id = DB::table('customer_tags')->where('slug', $slug)->value('id');
        if ($id !== null) {
            return (int) $id;
        }
        $now = date('Y-m-d H:i:s');

        return (int) DB::table('customer_tags')->insertGetId(['name' => mb_substr($name, 0, 60), 'slug' => $slug, 'color' => 'sand', 'created_at' => $now, 'updated_at' => $now]);
    }

    /** Phone = identity: link this customer to the online account (users row) with the same phone. */
    public static function linkOnlineAccount(int $cid): void
    {
        $c = DB::selectOne('SELECT id, phone, user_id FROM customers WHERE id = ?', [$cid]);
        if ($c === null || $c['user_id']) {
            return;
        }
        $uid = DB::table('users')->where('phone', $c['phone'])->value('id');
        if ($uid !== null) {
            DB::table('customers')->where('id', $cid)->update(['user_id' => (int) $uid, 'is_online' => 1]);
        }
    }

    /**
     * Storefront hook (called once online registration exists): a users row was created or got a
     * phone. Links the existing offline customer with that phone, or creates a new online customer.
     */
    public static function linkUser(int $userId): ?int
    {
        $u = DB::selectOne('SELECT id, name, email, phone FROM users WHERE id = ?', [$userId]);
        $phone = $u ? Phone::normalize((string) $u['phone']) : null;
        if ($phone === null) {
            return null;
        }
        $now = date('Y-m-d H:i:s');
        $cid = DB::table('customers')->where('phone', $phone)->value('id');
        if ($cid !== null) {
            DB::table('customers')->where('id', (int) $cid)->update(['user_id' => $userId, 'is_online' => 1, 'updated_at' => $now]);
        } else {
            $cid = DB::table('customers')->insertGetId([
                'name' => mb_substr((string) $u['name'], 0, 120), 'phone' => $phone, 'email' => $u['email'] ?: null,
                'source' => 'online', 'is_online' => 1, 'user_id' => $userId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            self::attachReviews((int) $cid);
        }
        SegmentMatcher::refreshForCustomer((int) $cid);

        return (int) $cid;
    }

    /** Reviews submitted from this customer's mobile (Stage 2 form) get linked to them. */
    public static function attachReviews(int $cid): int
    {
        $phone = DB::table('customers')->where('id', $cid)->value('phone');

        return $phone ? DB::update('UPDATE product_reviews SET customer_id = ? WHERE customer_id IS NULL AND customer_phone = ?', [$cid, $phone]) : 0;
    }

    /** Delete a customer and everything that only belongs to them. Reviews stay, unlinked. */
    public static function delete(int $cid): ?string
    {
        $name = DB::table('customers')->where('id', $cid)->value('name');
        if ($name === null) {
            return null;
        }
        $segments = array_map(static fn($r) => (int) $r['segment_id'], DB::select('SELECT segment_id FROM customer_segment_members WHERE customer_id = ?', [$cid]));
        DB::transaction(static function () use ($cid): void {
            foreach (['customer_addresses', 'customer_tag_map', 'customer_segment_members'] as $t) {
                DB::table($t)->where('customer_id', $cid)->delete();
            }
            DB::update('UPDATE product_reviews SET customer_id = NULL WHERE customer_id = ?', [$cid]);
            DB::table('customers')->where('id', $cid)->delete();
        });
        foreach (array_unique($segments) as $sid) {
            DB::table('customer_segments')->where('id', $sid)->update(['member_count' => (int) DB::table('customer_segment_members')->where('segment_id', $sid)->count()]);
        }

        return (string) $name;
    }

    /** Initials for avatars: "Rina Das" → "RD". */
    public static function initials(string $name): string
    {
        $s = '';
        foreach (array_slice(preg_split('/\s+/', trim($name)) ?: [], 0, 2) as $p) {
            $s .= mb_strtoupper(mb_substr($p, 0, 1));
        }

        return $s ?: '?';
    }

    /** One-line address for tables: "Lalbazar, Bankura 722101". */
    public static function shortAddress(?array $a): string
    {
        if (!$a) {
            return '';
        }
        $place = $a['city'] ?: ($a['district'] ?? '');
        $first = $a['area'] ?: ($a['post_office'] ?? '');
        $bits = array_filter([$first !== $place ? $first : null, $place]);

        return trim(implode(', ', $bits) . ' ' . ($a['pincode'] ?? ''));
    }
}
