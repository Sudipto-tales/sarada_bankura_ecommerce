<?php

namespace App\Services\Order;

use App\Services\Customer\CustomerService;
use App\Services\Customer\CustomerStats;
use App\Services\Customer\Phone;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\InventoryService;
use App\Services\NotificationService;
use Vayu\Database\DB;
use Vayu\Validation\ValidationException;

/**
 * Orders: create (customer/address/product snapshots + totals) and the status workflow.
 *
 *   pending ─accept─► accepted ─► processing ─► packed ─► dispatched ─► delivered
 *      └─reject─► rejected
 *
 * The ONLY writer of orders.order_status + order_status_history. Owner rules: orders never check
 * or reserve stock (accept shows a warning only); no cancellation after accept; stock leaves at
 * dispatch (may go negative); GST is one optional percent added on top of (subtotal − discount).
 */
final class OrderService
{
    public const STATUSES = [
        'pending' => 'Pending', 'accepted' => 'Accepted', 'processing' => 'Processing', 'packed' => 'Packed',
        'dispatched' => 'Dispatched', 'delivered' => 'Delivered', 'rejected' => 'Rejected',
    ];
    /** Status → tone class (roadmap colour tokens). */
    public const TONES = [
        'pending' => 'amber', 'accepted' => 'blue', 'processing' => 'indigo', 'packed' => 'violet',
        'dispatched' => 'teal', 'delivered' => 'green', 'rejected' => 'red',
    ];
    public const FLOW = ['pending', 'accepted', 'processing', 'packed', 'dispatched', 'delivered'];
    public const ALLOWED = [
        'pending' => ['accepted', 'rejected'],
        'accepted' => ['processing'],
        'processing' => ['packed'],
        'packed' => ['dispatched'],
        'dispatched' => ['delivered'],
    ];
    /** Button label for the one next step. */
    public const ACTIONS = [
        'accepted' => 'Accept', 'processing' => 'Start processing', 'packed' => 'Mark packed',
        'dispatched' => 'Dispatch', 'delivered' => 'Mark delivered', 'rejected' => 'Reject',
    ];
    /** Steps that need a dialog (data) — everything else is one click and can be bulk-applied. */
    public const NEEDS_DIALOG = ['rejected', 'dispatched', 'delivered'];
    public const BULK = ['accepted', 'processing', 'packed'];
    public const CHANNEL_DETAILS = ['showroom' => 'Showroom', 'walk_in' => 'Walk-in', 'phone' => 'Phone', 'whatsapp' => 'WhatsApp', 'website' => 'Website'];
    public const MAX_ITEMS = 60;

    /** The single allowed forward step (pending → accepted), or null at the end. */
    public static function next(string $status): ?string
    {
        return self::ALLOWED[$status][0] ?? null;
    }

    /* ================================================================
     | Create
     | ================================================================ */

    /**
     * Validate + create an order (admin "Create order"). Throws ValidationException.
     * $opts['online'] = true marks a storefront order (channel online / website) — used only by
     * PayWindow when a checkout payment is submitted (the order exists only after that).
     *
     * @param array<string,mixed> $in
     * @param array<string,mixed> $opts
     * @return int order id
     */
    public static function create(array $in, ?int $by = null, array $opts = []): int
    {
        $online = !empty($opts['online']);
        $err = [];

        // ---- customer: existing, or new (a known mobile reuses that customer) ----
        $customerId = (int) ($in['customer_id'] ?? 0);
        $newCustomer = null;
        if ($customerId > 0) {
            if (!DB::table('customers')->where('id', $customerId)->exists()) {
                $err['customer_id'][] = 'Pick a customer.';
            }
        } else {
            $nc = (array) ($in['new_customer'] ?? []);
            $name = trim(preg_replace('/\s+/', ' ', (string) ($nc['name'] ?? '')) ?? '');
            $phone = Phone::normalize((string) ($nc['phone'] ?? ''));
            if ($phone === null) {
                $err['new_customer.phone'][] = 'Enter the customer’s 10-digit mobile, or pick an existing customer.';
            }
            if ($phone !== null && ($existing = DB::table('customers')->where('phone', $phone)->value('id')) !== null) {
                $customerId = (int) $existing;
            } else {
                if (mb_strlen($name) < 2) {
                    $err['new_customer.name'][] = 'Enter the customer’s name.';
                }
                $email = mb_strtolower(trim((string) ($nc['email'] ?? '')));
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $err['new_customer.email'][] = 'Enter a valid email address.';
                }
                $newCustomer = ['name' => $name, 'phone' => (string) $phone, 'email' => $email, 'customer_type' => 'individual', 'source' => $online ? 'online' : 'showroom'];
            }
        }

        // ---- addresses ----
        $pickup = ($in['delivery'] ?? 'ship') === 'pickup';
        [$shipping, $shipNew] = $pickup ? [null, null] : self::addressChoice($in, 'shipping', $customerId, $err);
        $billingSame = !empty($in['billing_same']) || $pickup && empty($in['billing_address_id']);
        if ($billingSame && !$pickup) {
            [$billing, $billNew] = [$shipping, null];
        } elseif ($pickup && empty($in['billing_address_id'])) {
            [$billing, $billNew] = [null, null];
        } else {
            [$billing, $billNew] = self::addressChoice($in, 'billing', $customerId, $err);
        }

        // ---- items ----
        [$items, $itemErr] = self::cleanItems((array) ($in['items'] ?? []));
        $err += $itemErr;

        // ---- totals ----
        $subtotal = round(array_sum(array_column($items, 'line_total')), 2);
        $discount = round(max(0.0, (float) num_or_null($in['discount_amount'] ?? 0)), 2);
        if ($discount > $subtotal) {
            $err['discount_amount'][] = 'The discount can’t be more than the item total (' . Money::inr($subtotal) . ').';
        }
        $gstRate = null;
        if (!empty($in['gst_enabled'])) {
            $gstRate = round((float) num_or_null($in['gst_rate'] ?? 0), 2);
            if (trim((string) business('gstin')) === '') {
                // A business without GST registration issues a Bill of Supply and may not charge GST.
                $err['gst_rate'][] = 'GST can’t be charged until the seller GSTIN is set (BUSINESS_GSTIN in .env). Turn GST off for this order.';
            } elseif ($gstRate <= 0 || $gstRate > 28) {
                $err['gst_rate'][] = 'GST must be between 0.01 % and 28 %.';
            }
        }
        $channelDetail = (string) ($in['channel_detail'] ?? 'showroom');
        if (!isset(self::CHANNEL_DETAILS[$channelDetail]) || $channelDetail === 'website') {
            $channelDetail = 'showroom';
        }
        if ($online) {
            $channelDetail = 'website';
        }

        if ($err) {
            throw new ValidationException($err);
        }

        $taxable = round($subtotal - $discount, 2);
        $tax = $gstRate ? round($taxable * $gstRate / 100, 2) : 0.0;
        $now = date('Y-m-d H:i:s');

        $orderId = DB::transaction(static function () use ($customerId, $newCustomer, $shipping, $shipNew, $billing, $billNew, $billingSame, $pickup,
            $items, $subtotal, $discount, $gstRate, $tax, $taxable, $channelDetail, $online, $in, $by, $now): int {
            if ($newCustomer !== null) {
                $customerId = CustomerService::save(CustomerService::validate($newCustomer));
            }
            $cust = DB::selectOne('SELECT * FROM customers WHERE id = ?', [$customerId]);
            if ($shipNew !== null) {
                $shipping = self::saveNewAddress($customerId, $shipNew);
            }
            if ($billingSame && !$pickup) {
                $billing = $shipping;
            } elseif ($billNew !== null) {
                $billing = self::saveNewAddress($customerId, $billNew);
            }
            $shipSnap = $shipping ? self::snapshot($shipping, $cust) : null;
            $billSnap = $billing ? self::snapshot($billing, $cust) : null;
            $place = ($shipSnap['state'] ?? null) ?: (($billSnap['state'] ?? null) ?: (string) business('address.state'));

            $orderId = (int) DB::table('orders')->insertGetId([
                'order_number' => DocumentNumber::next('order'),
                'customer_id' => $customerId,
                'customer_name' => (string) $cust['name'],
                'customer_phone' => $cust['phone'],
                'customer_email' => $cust['email'],
                'billing_address_id' => $billing['id'] ?? null,
                'shipping_address_id' => $shipping['id'] ?? null,
                'billing_address' => $billSnap ? json_encode($billSnap, JSON_UNESCAPED_UNICODE) : null,
                'shipping_address' => $shipSnap ? json_encode($shipSnap, JSON_UNESCAPED_UNICODE) : null,
                'place_of_supply' => $place,
                'order_status' => 'pending',
                'channel' => $online ? 'online' : 'offline',
                'channel_detail' => $channelDetail,
                'public_token' => bin2hex(random_bytes(16)),
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'gst_rate' => $gstRate,
                'tax_amount' => $tax,
                'grand_total' => round($taxable + $tax, 2),
                'due_amount' => round($taxable + $tax, 2),
                'notes' => blank_to_null(mb_substr(trim((string) ($in['notes'] ?? '')), 0, 2000)),
                'internal_note' => blank_to_null(mb_substr(trim((string) ($in['internal_note'] ?? '')), 0, 2000)),
                'buyer_note' => blank_to_null(mb_substr(trim((string) ($in['buyer_note'] ?? '')), 0, 1000)),
                'created_by' => $by,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($items as $i => $it) {
                DB::table('order_items')->insert($it + ['order_id' => $orderId, 'sort' => $i, 'created_at' => $now]);
            }
            self::history($orderId, null, 'pending', $by, $pickup ? 'Order created · customer collects from the showroom' : 'Order created');
            PaymentService::syncOrder($orderId);

            return $orderId;
        });
        // $customerId may have been created inside the transaction — read it back from the order.
        CustomerStats::recalculate((int) DB::table('orders')->where('id', $orderId)->value('customer_id'));

        return $orderId;
    }

    /**
     * Price an order input without keeping anything: the order is created inside a transaction
     * that is then rolled back (the number series gives its number back — still gapless).
     * Throws ValidationException like create().
     *
     * @param array<string,mixed> $in
     * @return array{subtotal:float, discount_amount:float, tax_amount:float, grand_total:float, customer_name:string, customer_phone:?string}
     */
    public static function quote(array $in): array
    {
        $q = null;
        try {
            DB::transaction(static function () use ($in, &$q): void {
                $id = self::create($in, null, ['online' => true]);
                $q = DB::selectOne('SELECT subtotal, discount_amount, tax_amount, grand_total, customer_name, customer_phone FROM orders WHERE id = ?', [$id]);
                throw new QuoteRollback();
            });
        } catch (QuoteRollback) {
            // expected — nothing is kept
        }

        return [
            'subtotal' => (float) $q['subtotal'], 'discount_amount' => (float) $q['discount_amount'], 'tax_amount' => (float) $q['tax_amount'],
            'grand_total' => (float) $q['grand_total'], 'customer_name' => (string) $q['customer_name'], 'customer_phone' => $q['customer_phone'],
        ];
    }

    /**
     * One address choice: an existing id of this customer, or "new" + fields.
     *
     * @param  array<string, list<string>> $err
     * @return array{0: ?array, 1: ?array}  [existing row, new address data]
     */
    private static function addressChoice(array $in, string $kind, int $customerId, array &$err): array
    {
        $choice = (string) ($in[$kind . '_address_id'] ?? '');
        if ($choice !== '' && $choice !== 'new') {
            $row = $customerId ? DB::selectOne('SELECT * FROM customer_addresses WHERE id = ? AND customer_id = ?', [(int) $choice, $customerId]) : null;
            if ($row === null) {
                $err[$kind . '_address_id'][] = 'Pick one of this customer’s addresses.';
            }

            return [$row, null];
        }
        [$rows, $aErr] = CustomerService::cleanAddresses(['a' => (array) ($in['new_' . $kind] ?? [])], 'a');
        foreach ($aErr as $k => $m) {
            $err[str_replace('addresses.a.', 'new_' . $kind . '.', $k)] = $m;
        }
        if (!$rows && !$aErr) {
            $err['new_' . $kind . '.address_line1'][] = $kind === 'shipping'
                ? 'Enter the delivery address, or choose “Customer collects from showroom”.'
                : 'Enter the billing address, or tick “same as delivery”.';
        }
        if ($rows) {
            unset($rows[0]['id']);
            $rows[0]['type'] = $kind === 'shipping' ? 'shipping' : 'billing';
        }

        return [null, $rows[0] ?? null];
    }

    /** @param array<string,mixed> $a cleaned address */
    private static function saveNewAddress(int $customerId, array $a): array
    {
        $now = date('Y-m-d H:i:s');
        $hasDefault = DB::table('customer_addresses')->where('customer_id', $customerId)->where('is_default', 1)->exists();
        $id = (int) DB::table('customer_addresses')->insertGetId(array_merge($a, [
            'customer_id' => $customerId, 'is_default' => $hasDefault ? 0 : 1, 'created_at' => $now, 'updated_at' => $now,
        ]));

        return DB::selectOne('SELECT * FROM customer_addresses WHERE id = ?', [$id]);
    }

    /** Frozen copy of an address for the order/invoice (later edits to the customer don't change it). */
    public static function snapshot(array $a, array $customer): array
    {
        return [
            'id' => (int) $a['id'],
            'label' => $a['label'] ?? null,
            'name' => $a['name'] ?: $customer['name'],
            'phone' => $a['phone'] ?: $customer['phone'],
            'address_line1' => $a['address_line1'],
            'address_line2' => $a['address_line2'] ?? null,
            'area' => $a['area'] ?? null,
            'post_office' => $a['post_office'] ?? null,
            'city' => $a['city'] ?? null,
            'district' => $a['district'] ?? null,
            'state' => $a['state'] ?? null,
            'pincode' => $a['pincode'] ?? null,
            'country' => $a['country'] ?? 'India',
        ];
    }

    /**
     * Validate item rows and build their snapshots.
     *
     * @param  array<int|string, mixed> $rows
     * @return array{0: list<array<string,mixed>>, 1: array<string, list<string>>}
     */
    public static function cleanItems(array $rows): array
    {
        $out = [];
        $err = [];
        $hsn = (string) (business('tax.hsn_default') ?: '');
        foreach ($rows as $key => $r) {
            if (!is_array($r) || (int) ($r['product_id'] ?? 0) <= 0) {
                continue;
            }
            $f = static fn(string $x): string => 'items.' . $key . '.' . $x;
            $pid = (int) $r['product_id'];
            $vid = (int) ($r['variant_id'] ?? 0);
            $p = DB::selectOne('SELECT p.id, p.name, p.sku, p.status, pr.price, pr.unit FROM products p LEFT JOIN product_pricing pr ON pr.product_id = p.id WHERE p.id = ?', [$pid]);
            if ($p === null || $p['status'] === 'archived') {
                $err[$f('product_id')][] = 'This product is no longer available.';
                continue;
            }
            $v = null;
            if ($vid > 0) {
                $v = DB::selectOne("SELECT id, title, sku, price, unit, status FROM product_variants WHERE id = ? AND product_id = ?", [$vid, $pid]);
                if ($v === null || $v['status'] === 'archived') {
                    $err[$f('variant_id')][] = 'Pick a valid option for ' . $p['name'] . '.';
                    continue;
                }
            } elseif (DB::table('product_variants')->where('product_id', $pid)->where('status', 'active')->exists()) {
                $err[$f('variant_id')][] = 'Pick which option of ' . $p['name'] . ' (size / finish).';
                continue;
            }
            $qty = round((float) num_or_null($r['quantity'] ?? 0), 3);
            if ($qty <= 0 || $qty > 9999999) {
                $err[$f('quantity')][] = 'Enter a quantity above 0.';
            }
            $priceIn = trim((string) ($r['unit_price'] ?? ''));
            $price = $priceIn === '' ? (float) ($v['price'] ?? $p['price'] ?? 0) : (float) num_or_null($priceIn);
            $price = round($price, 2);
            if ($price < 0 || !is_numeric($priceIn === '' ? '0' : $priceIn)) {
                $err[$f('unit_price')][] = 'Enter a valid price.';
            }
            $gross = round($qty * $price, 2);
            $lineDisc = round(max(0.0, (float) num_or_null($r['discount'] ?? 0)), 2);
            if ($lineDisc > $gross) {
                $err[$f('discount')][] = 'Discount is more than the line amount.';
            }
            $policy = DB::selectOne('SELECT * FROM product_return_policies WHERE product_id = ? AND variant_id IN (?, 0) AND is_active = 1 ORDER BY variant_id DESC LIMIT 1', [$pid, $vid]);
            $out[] = [
                'product_id' => $pid,
                'variant_id' => $vid,
                'product_name' => (string) $p['name'],
                'variant_name' => $v['title'] ?? null,
                'sku' => $v['sku'] ?? $p['sku'],
                'unit' => ($v['unit'] ?? null) ?: ($p['unit'] ?: null),
                'quantity' => $qty,
                'unit_price' => $price,
                'discount_amount' => $lineDisc,
                'line_total' => round(max(0.0, $gross - $lineDisc), 2),
                'hsn_code' => $hsn !== '' ? $hsn : null,
                'return_allowed' => (int) ($policy['return_allowed'] ?? 0),
                'replacement_allowed' => (int) ($policy['replacement_allowed'] ?? 0),
                'return_days' => (int) ($policy['return_days'] ?? 0),
                'replacement_days' => (int) ($policy['replacement_days'] ?? 0),
            ];
        }
        if (!$out && !$err) {
            $err['items'][] = 'Add at least one product.';
        }
        if (count($out) > self::MAX_ITEMS) {
            $err['items'][] = 'An order can have up to ' . self::MAX_ITEMS . ' lines.';
        }

        return [$out, $err];
    }

    /* ================================================================
     | Status workflow
     | ================================================================ */

    /**
     * Move an order one step. Returns ['otp' => plaintext delivery OTP (dispatch only), 'warnings' => [...]].
     *
     * meta: by, note, customer_note + reject_reason (reject), courier_name/tracking_number/tracking_url
     * (dispatch), otp or manual_reason (delivered).
     *
     * @param array<string,mixed> $meta
     * @return array{otp: ?string, warnings: list<string>, otp_sent: ?string} otp_sent = whatsapp|email|null
     */
    public static function transition(int $orderId, string $to, array $meta = []): array
    {
        $o = DB::selectOne('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if ($o === null) {
            throw new OrderException('Order not found.');
        }
        $from = (string) $o['order_status'];
        if (!in_array($to, self::ALLOWED[$from] ?? [], true)) {
            throw new OrderException('Order ' . $o['order_number'] . ' is ' . strtolower(self::STATUSES[$from] ?? $from)
                . ' — it can’t be moved to ' . strtolower(self::STATUSES[$to] ?? $to) . '.');
        }
        $by = isset($meta['by']) ? (int) $meta['by'] : null;
        $note = blank_to_null(mb_substr(trim((string) ($meta['note'] ?? '')), 0, 1000));
        $now = date('Y-m-d H:i:s');
        $set = ['order_status' => $to, 'updated_at' => $now];
        $warnings = [];
        $otp = null;

        if ($to === 'rejected') {
            $customerNote = trim((string) ($meta['customer_note'] ?? ''));
            if ($customerNote === '') {
                throw new OrderException('Write a short note for the customer explaining why the order was declined.');
            }
            $set += ['customer_note' => mb_substr($customerNote, 0, 1000), 'reject_reason' => blank_to_null(mb_substr(trim((string) ($meta['reject_reason'] ?? '')), 0, 255)),
                'rejected_at' => $now, 'rejected_by' => $by];
        }
        if ($to === 'delivered') {
            // Checked before the transaction so a wrong OTP still counts as an attempt.
            $method = self::checkDelivery($orderId, $meta);
            $note = trim(($note ?? '') . ($method === 'manual' ? ' Confirmed without OTP: ' . trim((string) $meta['manual_reason']) : ' OTP verified')) ?: null;
        }

        DB::transaction(static function () use ($o, $orderId, $from, $to, $set, $now, $by, &$note, &$warnings, &$otp, $meta): void {
            $set += match ($to) {
                'accepted' => ['accepted_at' => $now, 'accepted_by' => $by],
                'processing' => ['processing_at' => $now],
                'packed' => ['packed_at' => $now, 'packed_by' => $by],
                'dispatched' => ['dispatched_at' => $now],
                'delivered' => ['delivered_at' => $now],
                default => [],
            };
            // Guarded update: only one request can move the order out of $from.
            $sets = implode(', ', array_map(static fn($k) => $k . ' = ?', array_keys($set)));
            $n = DB::update("UPDATE orders SET $sets WHERE id = ? AND order_status = ?", array_merge(array_values($set), [$orderId, $from]));
            if ($n !== 1) {
                throw new OrderException('Order ' . $o['order_number'] . ' was just updated by someone else — reload and try again.');
            }

            if ($to === 'packed') {
                DocumentService::packingSlip($orderId, $by);
            }
            if ($to === 'dispatched') {
                $courier = blank_to_null(mb_substr(trim((string) ($meta['courier_name'] ?? '')), 0, 120));
                $tracking = blank_to_null(mb_substr(trim((string) ($meta['tracking_number'] ?? '')), 0, 80));
                $url = trim((string) ($meta['tracking_url'] ?? ''));
                $url = $url !== '' && filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url) ? mb_substr($url, 0, 255) : null;
                DB::table('order_deliveries')->insert(['order_id' => $orderId, 'courier_name' => $courier, 'tracking_number' => $tracking,
                    'tracking_url' => $url, 'dispatch_date' => $now, 'created_at' => $now, 'updated_at' => $now]);
                $inv = DocumentService::issueInvoice($orderId, $by);
                $warnings = self::consumeStock($orderId, (string) $o['order_number'], $by);
                $otp = self::newOtp($orderId);
                $note = trim(($note ?? '') . ' Invoice ' . $inv['invoice_number'] . ($courier ? ' · ' . $courier : '') . ($tracking ? ' #' . $tracking : '')) ?: null;
                if ($warnings) {
                    $note .= ' · Stock: ' . implode('; ', $warnings);
                }
            }
            if ($to === 'delivered') {
                DB::table('order_deliveries')->where('order_id', $orderId)->update(['delivery_date' => $now, 'updated_at' => $now,
                    'delivery_note' => blank_to_null(mb_substr(trim((string) ($meta['delivery_note'] ?? '')), 0, 1000))]);
            }
            self::history($orderId, $from, $to, $by, $note);
        });

        if ($to === 'rejected') {
            PaymentService::syncOrder($orderId);
        }
        if (in_array($to, ['rejected', 'delivered'], true)) {
            CustomerStats::recalculate((int) $o['customer_id']);
        }
        if ($warnings) {
            NotificationService::notify('order.stock_issue', 'Stock not deducted for ' . $o['order_number'],
                implode(' ', $warnings), route('admin.orders.show', ['id' => $orderId]), 'warning');
        }
        // WhatsApp (Stage 7): customer update + delivery OTP; no-op while dormant, never throws.
        $otpSent = \App\Services\WhatsApp\FlowNotifier::orderTransition($orderId, $to, $otp);
        \App\Services\Push\OrderPush::transition($orderId, $to); // app push (dispatched / delivered), never throws

        return ['otp' => $otp, 'warnings' => $warnings, 'otp_sent' => $otpSent];
    }

    /**
     * Move several orders that are all in the same status one (dialog-free) step.
     *
     * @param  list<int> $ids
     * @return array{moved: list<string>, failed: array<string,string>}
     */
    public static function bulk(array $ids, string $to, ?int $by = null): array
    {
        if (!in_array($to, self::BULK, true)) {
            throw new OrderException('“' . (self::ACTIONS[$to] ?? $to) . '” needs details for each order — do it one at a time.');
        }
        $from = array_search($to, array_map(static fn($n) => $n[0], self::ALLOWED), true);
        $rows = $ids ? DB::table('orders')->whereIn('id', $ids)->get(['id', 'order_number', 'order_status']) : [];
        $statuses = array_values(array_unique(array_column($rows, 'order_status')));
        if (count($statuses) > 1 || ($statuses && $statuses[0] !== $from)) {
            throw new OrderException('Select orders that are all ' . strtolower(self::STATUSES[$from] ?? (string) $from) . ' to ' . strtolower(self::ACTIONS[$to]) . ' them together.');
        }
        $moved = [];
        $failed = [];
        foreach ($rows as $r) {
            try {
                self::transition((int) $r['id'], $to, ['by' => $by, 'note' => 'Bulk update']);
                $moved[] = (string) $r['order_number'];
            } catch (OrderException $e) {
                $failed[(string) $r['order_number']] = $e->getMessage();
            }
        }

        return ['moved' => $moved, 'failed' => $failed];
    }

    /**
     * Non-blocking stock check shown when accepting: tracked products whose stock is below what
     * this order needs.
     *
     * @return list<string>
     */
    public static function stockWarnings(int $orderId): array
    {
        $need = [];
        foreach (DB::select('SELECT product_id, product_name, unit, SUM(quantity) AS qty FROM order_items WHERE order_id = ? GROUP BY product_id, product_name, unit', [$orderId]) as $r) {
            $stock = InventoryService::stockFor((int) $r['product_id']);
            if ($stock === null) {
                continue;
            }
            try {
                $factor = InventoryService::conversionFactor((int) $r['product_id'], (string) ($r['unit'] ?: $stock['unit']), (string) $stock['unit']);
            } catch (InventoryException) {
                $need[] = $r['product_name'] . ': ordered in ' . $r['unit'] . ', stock kept in ' . $stock['unit'] . ' — can’t compare.';
                continue;
            }
            $qty = round((float) $r['qty'] * $factor, 3);
            if ($qty > (float) $stock['quantity'] + 0.0005) {
                $need[] = $r['product_name'] . ': needs ' . InventoryService::qty($qty) . ' ' . $stock['unit'] . ', ' . InventoryService::qty((float) $stock['quantity']) . ' in stock.';
            }
        }

        return $need;
    }

    /** @return list<string> warnings for lines whose stock couldn't be deducted */
    private static function consumeStock(int $orderId, string $orderNumber, ?int $by): array
    {
        $warnings = [];
        foreach (DB::select('SELECT product_id, product_name, unit, SUM(quantity) AS qty FROM order_items WHERE order_id = ? GROUP BY product_id, product_name, unit', [$orderId]) as $r) {
            try {
                InventoryService::consumeForOrder((int) $r['product_id'], (float) $r['qty'], $r['unit'] ?: null, [
                    'reference_no' => $orderNumber, 'notes' => 'Order dispatched', 'created_by' => $by,
                ]);
            } catch (InventoryException $e) {
                $warnings[] = $r['product_name'] . ' — ' . $e->getMessage();
            }
        }

        return $warnings;
    }

    /* ================================================================
     | Delivery OTP (hash only; shown once to the admin to send to the customer)
     | ================================================================ */

    public static function newOtp(int $orderId): string
    {
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $days = max(1, (int) (business('delivery.otp_days') ?: 7));
        DB::table('order_deliveries')->where('order_id', $orderId)->update([
            'otp_hash' => self::otpHash($orderId, $otp),
            'otp_expires_at' => date('Y-m-d H:i:s', time() + $days * 86400),
            'otp_attempts' => 0,
            'otp_sent_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $otp;
    }

    /** New OTP for a dispatched order (the old one stops working). */
    public static function resendOtp(int $orderId, ?int $by = null, ?string &$sentVia = null): string
    {
        if (DB::table('orders')->where('id', $orderId)->value('order_status') !== 'dispatched') {
            throw new OrderException('A delivery OTP is only needed while the order is out for delivery.');
        }
        $otp = self::newOtp($orderId);
        self::history($orderId, 'dispatched', 'dispatched', $by, 'New delivery OTP generated');
        $sentVia = \App\Services\WhatsApp\FlowNotifier::deliveryOtp($orderId, $otp); // WhatsApp (Stage 7)

        return $otp;
    }

    /** @return string otp|manual */
    private static function checkDelivery(int $orderId, array $meta): string
    {
        $manual = trim((string) ($meta['manual_reason'] ?? ''));
        if (!empty($meta['manual'])) {
            if (mb_strlen($manual) < 5) {
                throw new OrderException('Explain why the delivery is confirmed without the OTP (who received it, how it was checked).');
            }

            return 'manual';
        }
        $d = DB::selectOne('SELECT * FROM order_deliveries WHERE order_id = ?', [$orderId]);
        if ($d === null || !$d['otp_hash']) {
            throw new OrderException('No delivery OTP exists for this order — generate a new one.');
        }
        $max = max(1, (int) (business('delivery.otp_attempts') ?: 5));
        if ((int) $d['otp_attempts'] >= $max) {
            throw new OrderException('Too many wrong OTPs. Generate a new OTP and send it to the customer again.');
        }
        if ($d['otp_expires_at'] && strtotime((string) $d['otp_expires_at']) < time()) {
            throw new OrderException('This OTP has expired. Generate a new one.');
        }
        $otp = preg_replace('/\D+/', '', (string) ($meta['otp'] ?? '')) ?? '';
        if (strlen($otp) !== 6 || !hash_equals((string) $d['otp_hash'], self::otpHash($orderId, $otp))) {
            DB::update('UPDATE order_deliveries SET otp_attempts = otp_attempts + 1 WHERE order_id = ?', [$orderId]);
            $left = $max - (int) $d['otp_attempts'] - 1;
            throw new OrderException('Wrong OTP.' . ($left > 0 ? ' ' . $left . ' tr' . ($left === 1 ? 'y' : 'ies') . ' left.' : ' Generate a new OTP.'));
        }
        DB::table('order_deliveries')->where('order_id', $orderId)->update(['otp_verified_at' => date('Y-m-d H:i:s'), 'verified_by' => 'otp']);

        return 'otp';
    }

    private static function otpHash(int $orderId, string $otp): string
    {
        $key = (string) (config('app.key') ?: env('APP_KEY', 'vayu-delivery'));

        return hash_hmac('sha256', $orderId . '|' . $otp, $key);
    }

    /* ================================================================ */

    public static function history(int $orderId, ?string $from, string $to, ?int $by, ?string $note): void
    {
        DB::table('order_status_history')->insert([
            'order_id' => $orderId, 'old_status' => $from, 'new_status' => $to, 'changed_by' => $by,
            'note' => $note !== null ? mb_substr($note, 0, 2000) : null, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Address JSON → array (empty when the customer collects from the showroom). */
    public static function address(?string $json): array
    {
        $a = $json ? json_decode($json, true) : null;

        return is_array($a) ? $a : [];
    }

    /** Lines of an address snapshot for display. */
    public static function addressLines(array $a): array
    {
        if (!$a) {
            return [];
        }

        return array_values(array_filter([
            (string) ($a['address_line1'] ?? ''),
            (string) ($a['address_line2'] ?? ''),
            implode(', ', array_filter([$a['area'] ?? null, $a['post_office'] ?? null, $a['city'] ?? null])),
            trim(implode(', ', array_filter([($a['district'] ?? null) !== ($a['city'] ?? null) ? ($a['district'] ?? null) : null, $a['state'] ?? null])) . ' ' . ($a['pincode'] ?? '')),
        ], static fn($l) => trim($l) !== ''));
    }
}

/** @internal used by OrderService::quote() to roll back a priced-but-not-kept order. */
final class QuoteRollback extends \RuntimeException
{
}
