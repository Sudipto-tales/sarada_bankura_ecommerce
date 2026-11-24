<?php

namespace App\Services\Order;

use App\Services\Inventory\InventoryException;
use App\Services\Inventory\InventoryService;
use App\Services\NotificationService;
use App\Services\Payment\Ledger;
use Vayu\Database\DB;

/**
 * Returns & replacements (Stage 6) — the ONLY writer of return_requests, return_request_items and
 * product_return_policies.
 *
 *   submitted ─approve─► approved ─receive─► received ─resolve─► resolved
 *       └─reject─► rejected
 *
 * Owner rules: only delivered orders; every request needs an admin decision; no cancel after
 * submit; partial quantities (approve / receive fewer than asked); a line can never be claimed
 * beyond delivered − already claimed. Eligibility comes from the order_item snapshot
 * (return_allowed / replacement_allowed + days from delivery, IST), not the live policy — the admin
 * may accept outside policy only with a written reason.
 *
 * Stock: received units go back via InventoryService::restockFromReturn (per line, optional for
 * damaged stone); a replacement leaves via InventoryService::consumeForOrder at its own dispatch.
 * Money: resolving a return credits the returned value against the order (PaymentService::syncOrder
 * lowers what the order owes, so a paid order shows "refund due"); the refund itself is recorded
 * through Payment\Ledger::refund with return_request_id. Events go to order_status_history with
 * the request number as prefix, so the order timeline and the return timeline share one log.
 */
final class ReturnService
{
    public const STATUSES = [
        'submitted' => 'Waiting for review', 'approved' => 'Approved — awaiting goods', 'received' => 'Goods received',
        'resolved' => 'Completed', 'rejected' => 'Declined',
    ];
    public const SHORT = ['submitted' => 'To review', 'approved' => 'Approved', 'received' => 'Received', 'resolved' => 'Completed', 'rejected' => 'Declined'];
    public const TONES = ['submitted' => 'amber', 'approved' => 'blue', 'received' => 'violet', 'resolved' => 'green', 'rejected' => 'red'];
    public const FLOW = ['submitted', 'approved', 'received', 'resolved'];
    public const TYPES = ['return' => 'Return & refund', 'replacement' => 'Replacement'];
    public const REASONS = [
        'damaged' => 'Damaged / broken in transit',
        'defect' => 'Crack, stain or defect in the stone',
        'wrong_item' => 'Wrong item, size or shade',
        'not_as_expected' => 'Not as expected',
        'excess' => 'Ordered too much',
        'other' => 'Other',
    ];
    private const EPS = 0.0005;

    /* ================================================================
     | Eligibility
     | ================================================================ */

    /**
     * Per order line: delivered qty, already claimed, still available and whether a return /
     * replacement is inside policy today.
     *
     * @return list<array<string,mixed>>
     */
    public static function lines(int $orderId): array
    {
        $o = DB::selectOne('SELECT id, order_status, delivered_at FROM orders WHERE id = ?', [$orderId]);
        if ($o === null) {
            return [];
        }
        $delivered = $o['order_status'] === 'delivered';
        $claimed = self::claimed($orderId);
        $today = date('Y-m-d');
        $out = [];
        foreach (DB::select('SELECT * FROM order_items WHERE order_id = ? ORDER BY sort, id', [$orderId]) as $it) {
            $qty = (float) $it['quantity'];
            $c = $claimed[(int) $it['id']] ?? 0.0;
            $row = $it + ['claimed' => $c, 'available' => $delivered ? max(0.0, round($qty - $c, 3)) : 0.0];
            foreach (['return', 'replacement'] as $t) {
                $allowed = (int) $it[$t . '_allowed'] === 1;
                $days = (int) $it[$t . '_days'];
                $until = $delivered && $allowed && $days > 0 && $o['delivered_at'] ? date('Y-m-d', (int) strtotime((string) $o['delivered_at']) + $days * 86400) : null;
                $row[$t . '_until'] = $until;
                $row[$t . '_ok'] = $delivered && $allowed && ($until === null || $today <= $until);
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Quantity already claimed per order item by requests that are not declined: a waiting request
     * counts what was asked, an approved one what was approved, a received/completed one what came back.
     *
     * @return array<int,float>
     */
    public static function claimed(int $orderId): array
    {
        $rows = DB::select("SELECT i.order_item_id, SUM(CASE r.status
                WHEN 'submitted' THEN i.requested_quantity
                WHEN 'approved' THEN COALESCE(i.approved_quantity, 0)
                ELSE COALESCE(i.received_quantity, 0) END) AS q
            FROM return_request_items i JOIN return_requests r ON r.id = i.return_request_id
            WHERE r.order_id = ? AND r.status <> 'rejected' GROUP BY i.order_item_id", [$orderId]);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['order_item_id']] = round((float) $r['q'], 3);
        }

        return $out;
    }

    /** Can a new request be raised on this order at all (delivered + something left)? */
    public static function canRequest(int $orderId): bool
    {
        foreach (self::lines($orderId) as $l) {
            if ($l['available'] > self::EPS) {
                return true;
            }
        }

        return false;
    }

    /**
     * Value of $qty units of an order line, including its share of the order discount and GST
     * (what the customer actually paid for them), in paise.
     */
    public static function valueCents(array $item, array $order, float $qty): int
    {
        $lineQty = (float) $item['quantity'];
        $subtotal = PaymentService::cents($order['subtotal']);
        if ($lineQty <= 0 || $subtotal <= 0 || $qty <= 0) {
            return 0;
        }
        $line = PaymentService::cents($item['line_total']) * min(1.0, $qty / $lineQty);

        return (int) round($line * PaymentService::cents($order['grand_total']) / $subtotal);
    }

    /** Σ value credited to an order by completed returns (lowers what the order owes), in paise. */
    public static function creditCents(int $orderId): int
    {
        $s = DB::selectOne("SELECT COALESCE(SUM(i.refund_amount), 0) AS s FROM return_request_items i JOIN return_requests r ON r.id = i.return_request_id
            WHERE r.order_id = ? AND r.status = 'resolved' AND r.request_type = 'return'", [$orderId]);

        return PaymentService::cents($s['s'] ?? 0);
    }

    /* ================================================================
     | Submit (admin, on behalf of the customer)
     | ================================================================ */

    /**
     * in: request_type, reason (REASONS key), description, items[order_item_id] = qty,
     *     outside_policy (bool) + override_reason.
     *
     * @param array<string,mixed> $in
     * @return array{id:int, request_number:string}
     */
    public static function submit(int $orderId, array $in, ?int $by = null): array
    {
        $o = self::order($orderId);
        if ($o['order_status'] !== 'delivered') {
            throw new ReturnException('Returns and replacements open only after the order is delivered. ' . $o['order_number'] . ' is ' . strtolower(OrderService::STATUSES[$o['order_status']] ?? $o['order_status']) . '.');
        }
        $type = (string) ($in['request_type'] ?? '');
        if (!isset(self::TYPES[$type])) {
            throw new ReturnException('Choose return (refund) or replacement.');
        }
        $reasonKey = (string) ($in['reason'] ?? '');
        if (!isset(self::REASONS[$reasonKey])) {
            throw new ReturnException('Choose the reason for the request.');
        }
        $description = trim(preg_replace('/[ \t]+/', ' ', (string) ($in['description'] ?? '')) ?? '');
        if ($reasonKey === 'other' && mb_strlen($description) < 3) {
            throw new ReturnException('Describe the problem when the reason is “Other”.');
        }
        $override = !empty($in['outside_policy']);
        $overrideReason = trim((string) ($in['override_reason'] ?? ''));
        if ($override && mb_strlen($overrideReason) < 5) {
            throw new ReturnException('Write why this is accepted outside the return policy (e.g. “Goodwill — regular contractor”).');
        }
        $want = [];
        foreach ((array) ($in['items'] ?? []) as $itemId => $q) {
            $q = round((float) num_or_null(is_string($q) ? trim($q) : $q), 3);
            if ($q < 0) {
                throw new ReturnException('Quantities can’t be negative.');
            }
            if ($q > 0) {
                $want[(int) $itemId] = $q;
            }
        }
        if (!$want) {
            throw new ReturnException('Enter the quantity to ' . ($type === 'return' ? 'return' : 'replace') . ' for at least one item.');
        }
        $now = date('Y-m-d H:i:s');

        $res = DB::transaction(static function () use ($o, $orderId, $type, $reasonKey, $description, $override, $overrideReason, $want, $by, $now): array {
            self::lockOrder($orderId);
            $lines = [];
            foreach (self::lines($orderId) as $l) {
                $lines[(int) $l['id']] = $l;
            }
            foreach ($want as $itemId => $q) {
                $l = $lines[$itemId] ?? null;
                if ($l === null) {
                    throw new ReturnException('That item isn’t part of order ' . $o['order_number'] . '.');
                }
                if ($q > $l['available'] + self::EPS) {
                    throw new ReturnException($l['product_name'] . ': only ' . InventoryService::qty($l['available']) . ' ' . $l['unit'] . ' can still be '
                        . ($type === 'return' ? 'returned' : 'replaced') . ' (' . InventoryService::qty((float) $l['quantity']) . ' delivered, ' . InventoryService::qty($l['claimed']) . ' already in returns).');
                }
                if (!$l[$type . '_ok'] && !$override) {
                    $why = (int) $l[$type . '_allowed'] !== 1
                        ? 'isn’t ' . ($type === 'return' ? 'returnable' : 'replaceable') . ' under its policy'
                        : 'was only ' . ($type === 'return' ? 'returnable' : 'replaceable') . ' until ' . date('j M Y', (int) strtotime((string) $l[$type . '_until']));
                    throw new ReturnException($l['product_name'] . ' ' . $why . '. Tick “Accept outside policy” and write why, if you still want to take it back.');
                }
            }
            $number = DocumentNumber::next('return');
            $desc = $description !== '' ? mb_substr($description, 0, 2000) : null;
            $id = (int) DB::table('return_requests')->insertGetId([
                'request_number' => $number,
                'order_id' => $orderId,
                'customer_id' => (int) $o['customer_id'],
                'request_type' => $type,
                'reason' => self::REASONS[$reasonKey],
                'description' => $desc,
                'status' => 'submitted',
                'admin_note' => $override ? mb_substr('Accepted outside policy: ' . $overrideReason, 0, 1000) : null,
                'created_at' => $now,
            ]);
            foreach ($want as $itemId => $q) {
                $l = $lines[$itemId];
                DB::table('return_request_items')->insert([
                    'return_request_id' => $id,
                    'order_item_id' => $itemId,
                    'product_id' => (int) $l['product_id'],
                    'variant_id' => (int) $l['variant_id'],
                    'requested_quantity' => $q,
                    'resolution_type' => $type === 'return' ? 'refund' : 'replacement',
                    'replacement_product_id' => $type === 'replacement' ? (int) $l['product_id'] : null,
                    'replacement_variant_id' => $type === 'replacement' ? (int) $l['variant_id'] : null,
                    'created_at' => $now,
                ]);
            }
            self::log($orderId, $number, $by, self::TYPES[$type] . ' requested — ' . self::itemSummary($want, $lines) . ' · ' . self::REASONS[$reasonKey]
                . ($override ? ' · outside policy: ' . $overrideReason : ''));

            return ['id' => $id, 'request_number' => $number];
        });

        NotificationService::notify('return.requested', ($type === 'return' ? 'Return' : 'Replacement') . ' requested on ' . $o['order_number'],
            $res['request_number'] . ' · ' . $o['customer_name'] . ' · ' . self::REASONS[$reasonKey] . ' — review and accept or decline.',
            route('admin.returns.show', ['id' => $res['id']]), 'warning', ['return_id' => $res['id'], 'order_id' => $orderId],
            'return:submitted:' . $res['id'], 'undo-2');

        return $res;
    }

    /* ================================================================
     | Admin decision
     | ================================================================ */

    /**
     * Accept, possibly fewer than asked: items[return_request_item_id] = approved qty (0 = not
     * accepted for that line, at least one line > 0).
     *
     * @param array<string,mixed> $in
     */
    public static function approve(int $id, array $in, ?int $by = null): void
    {
        $r = self::find($id);
        self::expect($r, 'submitted', 'approved');
        $items = self::items($id);
        $qty = self::quantities($in, $items, 'requested_quantity', 'approve');
        $note = blank_to_null(mb_substr(trim((string) ($in['admin_note'] ?? '')), 0, 1000));
        $now = date('Y-m-d H:i:s');

        DB::transaction(static function () use ($r, $items, $qty, $note, $by, $now): void {
            self::guarded($r, 'submitted', ['status' => 'approved', 'approved_by' => $by, 'approved_at' => $now,
                'admin_note' => self::joinNote($r['admin_note'], $note)]);
            foreach ($items as $it) {
                DB::table('return_request_items')->where('id', (int) $it['id'])->update(['approved_quantity' => $qty[(int) $it['id']]]);
            }
            $partial = array_filter($items, static fn($it) => $qty[(int) $it['id']] + self::EPS < (float) $it['requested_quantity']);
            self::log((int) $r['order_id'], (string) $r['request_number'], $by, 'Approved' . ($partial ? ' (partly) — ' . self::qtySummary($items, $qty) : '') . ($note ? ' · ' . $note : ''));
        });
        NotificationService::markReadByKey('return:submitted:' . $id);
        NotificationService::notify('return.approved', $r['request_number'] . ' approved',
            ($r['request_type'] === 'return' ? 'Return' : 'Replacement') . ' on ' . $r['order_number'] . ' — collect the goods, then mark them received.',
            route('admin.returns.show', ['id' => $id]), 'success', ['return_id' => $id, 'order_id' => (int) $r['order_id']], null, 'check');
    }

    /** Decline: the note is shown to the customer on their order page. */
    public static function reject(int $id, string $note, ?int $by = null): void
    {
        $note = trim(preg_replace('/\s+/', ' ', $note) ?? '');
        if (mb_strlen($note) < 3) {
            throw new ReturnException('Write a short note for the customer explaining why the request is declined.');
        }
        $r = self::find($id);
        self::expect($r, 'submitted', 'declined');
        $now = date('Y-m-d H:i:s');
        DB::transaction(static function () use ($r, $note, $by, $now): void {
            self::guarded($r, 'submitted', ['status' => 'rejected', 'approved_by' => $by, 'completed_at' => $now,
                'admin_note' => self::joinNote($r['admin_note'], 'Declined: ' . mb_substr($note, 0, 900))]);
            self::log((int) $r['order_id'], (string) $r['request_number'], $by, 'Declined — ' . $note);
        });
        NotificationService::markReadByKey('return:submitted:' . $id);
    }

    /**
     * Goods are back: items[id] = received qty (≤ approved), restock[id] = 1 to put that line back
     * into stock (untick for broken stone). Returns stock warnings (never blocks).
     *
     * @param array<string,mixed> $in
     * @return list<string>
     */
    public static function receive(int $id, array $in, ?int $by = null): array
    {
        $r = self::find($id);
        self::expect($r, 'approved', 'received');
        $items = array_values(array_filter(self::items($id), static fn($it) => (float) $it['approved_quantity'] > 0));
        $qty = self::quantities($in, $items, 'approved_quantity', 'receive');
        $restock = (array) ($in['restock'] ?? []);
        $now = date('Y-m-d H:i:s');
        $warnings = [];
        $restocked = [];

        DB::transaction(static function () use ($r, $items, $qty, $restock, $by, $now, &$warnings, &$restocked): void {
            self::guarded($r, 'approved', ['status' => 'received']);
            foreach ($items as $it) {
                $q = $qty[(int) $it['id']];
                DB::table('return_request_items')->where('id', (int) $it['id'])->update(['received_quantity' => $q]);
                if ($q <= 0 || empty($restock[$it['id']])) {
                    continue;
                }
                try {
                    $m = InventoryService::restockFromReturn((int) $it['product_id'], $q, $it['unit'] ?: null, [
                        'reference_no' => $r['request_number'], 'notes' => 'Returned on ' . $r['order_number'], 'created_by' => $by,
                    ]);
                    if ($m !== null) {
                        $restocked[] = $it['product_name'];
                    }
                } catch (InventoryException $e) {
                    $warnings[] = $it['product_name'] . ' — ' . $e->getMessage();
                }
            }
            $kept = array_filter($items, static fn($it) => $qty[(int) $it['id']] > 0 && empty($restock[$it['id']]));
            self::log((int) $r['order_id'], (string) $r['request_number'], $by, 'Goods received — ' . self::qtySummary($items, $qty)
                . ($restocked ? ' · back in stock: ' . implode(', ', $restocked) : '')
                . ($kept ? ' · not restocked: ' . implode(', ', array_column($kept, 'product_name')) : '')
                . ($warnings ? ' · Stock: ' . implode('; ', $warnings) : ''));
        });
        if ($warnings) {
            NotificationService::notify('order.stock_issue', 'Returned stock not added for ' . $r['request_number'], implode(' ', $warnings),
                route('admin.returns.show', ['id' => $id]), 'warning');
        }

        return $warnings;
    }

    /**
     * Finish a received request.
     *  - return:      credit_amount (≤ value of what came back; default = that value) is credited
     *                 against the order; a paid order then shows the refund due.
     *  - replacement: dispatch the same item again (own stock-out); courier_name / tracking_number.
     *
     * @param array<string,mixed> $in
     * @return array{credit:float, warnings:list<string>}
     */
    public static function resolve(int $id, array $in, ?int $by = null): array
    {
        $r = self::find($id);
        self::expect($r, 'received', 'completed');
        $o = self::order((int) $r['order_id']);
        $items = array_values(array_filter(self::items($id), static fn($it) => (float) $it['received_quantity'] > 0));
        $now = date('Y-m-d H:i:s');
        $warnings = [];
        $credit = 0;

        if ($r['request_type'] === 'return') {
            $values = [];
            foreach ($items as $it) {
                $values[(int) $it['id']] = self::valueCents($it, $o, (float) $it['received_quantity']);
            }
            $max = array_sum($values);
            $raw = trim(str_replace([',', '₹', ' '], '', (string) ($in['credit_amount'] ?? '')));
            $credit = $raw === '' ? $max : PaymentService::cents((float) num_or_null($raw));
            if ($raw !== '' && !is_numeric($raw)) {
                throw new ReturnException('Enter the amount to credit for the returned goods.');
            }
            if ($credit < 0 || $credit > $max) {
                throw new ReturnException('The credit can be between ₹0 and ' . Money::inr($max / 100) . ' (the value of the goods that came back).');
            }
            // Spread the credit over the lines in proportion to their value (last line takes the remainder).
            $alloc = [];
            $left = $credit;
            $ids = array_keys($values);
            foreach ($ids as $n => $iid) {
                $share = $n === count($ids) - 1 ? $left : ($max > 0 ? intdiv($credit * $values[$iid], $max) : 0);
                $alloc[$iid] = $share;
                $left -= $share;
            }
            $note = blank_to_null(mb_substr(trim((string) ($in['note'] ?? '')), 0, 500));
            DB::transaction(static function () use ($r, $alloc, $credit, $max, $note, $by, $now): void {
                self::guarded($r, 'received', ['status' => 'resolved', 'completed_at' => $now]);
                foreach ($alloc as $iid => $c) {
                    DB::table('return_request_items')->where('id', $iid)->update(['refund_amount' => $c / 100]);
                }
                PaymentService::syncOrder((int) $r['order_id']);
                self::log((int) $r['order_id'], (string) $r['request_number'], $by, 'Completed — ' . Money::inr($credit / 100) . ' credited for returned goods'
                    . ($credit < $max ? ' (value ' . Money::inr($max / 100) . ')' : '') . ($note ? ' · ' . $note : ''));
            });
        } else {
            $courier = blank_to_null(mb_substr(trim((string) ($in['courier_name'] ?? '')), 0, 120));
            $tracking = blank_to_null(mb_substr(trim((string) ($in['tracking_number'] ?? '')), 0, 80));
            DB::transaction(static function () use ($r, $items, $courier, $tracking, $by, $now, &$warnings): void {
                self::guarded($r, 'received', ['status' => 'resolved', 'completed_at' => $now]);
                foreach ($items as $it) {
                    try {
                        InventoryService::consumeForOrder((int) ($it['replacement_product_id'] ?: $it['product_id']), (float) $it['received_quantity'], $it['unit'] ?: null, [
                            'reference_no' => $r['request_number'], 'notes' => 'Replacement dispatched for ' . $r['order_number'], 'created_by' => $by,
                        ]);
                    } catch (InventoryException $e) {
                        $warnings[] = $it['product_name'] . ' — ' . $e->getMessage();
                    }
                }
                self::log((int) $r['order_id'], (string) $r['request_number'], $by, 'Replacement dispatched — '
                    . implode(', ', array_map(static fn($it) => $it['product_name'] . ' × ' . InventoryService::qty((float) $it['received_quantity']) . ' ' . $it['unit'], $items))
                    . ($courier ? ' · ' . $courier : '') . ($tracking ? ' #' . $tracking : '') . ($warnings ? ' · Stock: ' . implode('; ', $warnings) : ''));
            });
            if ($warnings) {
                NotificationService::notify('order.stock_issue', 'Stock not deducted for replacement ' . $r['request_number'], implode(' ', $warnings),
                    route('admin.returns.show', ['id' => $id]), 'warning');
            }
        }

        return ['credit' => (float) ($credit / 100), 'warnings' => $warnings];
    }

    /**
     * Money sent back for a completed return — recorded through the payments ledger (the only
     * writer of payment_refunds), linked to this request.
     *
     * @param array<string,mixed> $in amount, method, payment_account_id, reference, refunded_at, reason
     */
    public static function recordRefund(int $id, array $in, ?int $by = null): array
    {
        $r = self::find($id);
        if ($r['request_type'] !== 'return' || $r['status'] !== 'resolved') {
            throw new ReturnException('A refund can be recorded once the return is completed and its value credited.');
        }
        $credited = PaymentService::cents(DB::selectOne('SELECT COALESCE(SUM(refund_amount), 0) AS s FROM return_request_items WHERE return_request_id = ?', [$id])['s'] ?? 0);
        $already = PaymentService::cents(DB::selectOne("SELECT COALESCE(SUM(amount), 0) AS s FROM payment_refunds WHERE return_request_id = ? AND status = 'paid'", [$id])['s'] ?? 0);
        $amount = PaymentService::cents((float) num_or_null(str_replace([',', '₹', ' '], '', (string) ($in['amount'] ?? ''))));
        if ($amount > $credited - $already) {
            throw new ReturnException('This return was credited ' . Money::inr($credited / 100) . ($already ? ' and ' . Money::inr($already / 100) . ' is already refunded' : '')
                . ' — refund at most ' . Money::inr(max(0, $credited - $already) / 100) . ' against it.');
        }
        $in['return_request_id'] = $id;
        if (trim((string) ($in['reason'] ?? '')) === '') {
            $in['reason'] = 'Return ' . $r['request_number'];
        }

        return Ledger::refund((int) $r['order_id'], $in, $by);
    }

    /* ================================================================
     | Return policies (product level; new orders snapshot them)
     | ================================================================ */

    /**
     * Same policy for one or many products. in: return_allowed, return_days, replacement_allowed,
     * replacement_days, return_conditions, is_active.
     *
     * @param list<int> $productIds
     * @param array<string,mixed> $in
     */
    public static function savePolicy(array $productIds, array $in): int
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$productIds) {
            throw new ReturnException('Pick at least one product.');
        }
        $known = DB::table('products')->whereIn('id', $productIds)->count();
        if ($known !== count($productIds)) {
            throw new ReturnException('Some of those products no longer exist — reload the page.');
        }
        $row = ['return_allowed' => !empty($in['return_allowed']) ? 1 : 0, 'replacement_allowed' => !empty($in['replacement_allowed']) ? 1 : 0];
        foreach (['return', 'replacement'] as $t) {
            $d = trim((string) ($in[$t . '_days'] ?? ''));
            if ($d !== '' && (!ctype_digit($d) || (int) $d > 365)) {
                throw new ReturnException('Days must be a whole number from 0 to 365 (0 = no time limit).');
            }
            $row[$t . '_days'] = $row[$t . '_allowed'] ? (int) $d : 0;
        }
        $row['return_conditions'] = blank_to_null(mb_substr(trim((string) ($in['return_conditions'] ?? '')), 0, 2000));
        $row['is_active'] = array_key_exists('is_active', $in) ? (!empty($in['is_active']) ? 1 : 0) : 1;
        $now = date('Y-m-d H:i:s');
        DB::transaction(static function () use ($productIds, $row, $now): void {
            foreach ($productIds as $pid) {
                $n = DB::table('product_return_policies')->where('product_id', $pid)->where('variant_id', 0)->update($row + ['updated_at' => $now]);
                if ($n === 0 && !DB::table('product_return_policies')->where('product_id', $pid)->where('variant_id', 0)->exists()) {
                    DB::table('product_return_policies')->insert($row + ['product_id' => $pid, 'variant_id' => 0, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        });

        return count($productIds);
    }

    /* ================================================================
     | Reads for the views
     | ================================================================ */

    /** @return array<string,mixed> request + order number/name, or throws */
    public static function find(int $id): array
    {
        $r = DB::selectOne('SELECT r.*, o.order_number, o.customer_name, o.customer_phone, o.public_token FROM return_requests r JOIN orders o ON o.id = r.order_id WHERE r.id = ?', [$id]);
        if ($r === null) {
            throw new ReturnException('That return request no longer exists.');
        }

        return $r;
    }

    /** @return list<array<string,mixed>> request items with the order-line snapshot */
    public static function items(int $id): array
    {
        return DB::select('SELECT i.*, oi.product_name, oi.variant_name, oi.sku, oi.unit, oi.quantity, oi.line_total, oi.unit_price
            FROM return_request_items i JOIN order_items oi ON oi.id = i.order_item_id WHERE i.return_request_id = ? ORDER BY oi.sort, i.id', [$id]);
    }

    /** @return list<array<string,mixed>> requests on an order, newest first, with a line summary */
    public static function forOrder(int $orderId): array
    {
        $rows = DB::select('SELECT * FROM return_requests WHERE order_id = ? ORDER BY id DESC', [$orderId]);
        foreach ($rows as &$r) {
            $r['items'] = self::items((int) $r['id']);
        }

        return $rows;
    }

    /** Timeline of one request: the order-history rows written for it. */
    public static function events(array $r): array
    {
        return DB::select('SELECT * FROM order_status_history WHERE order_id = ? AND note LIKE ? ORDER BY id DESC', [(int) $r['order_id'], $r['request_number'] . ' · %']);
    }

    /** Is an order-history note a return event? Returns [request number, text] or null. */
    public static function parseEvent(?string $note): ?array
    {
        $prefix = preg_quote(DocumentNumber::prefix('return'), '#');

        return $note !== null && preg_match('#^(' . $prefix . '/\d{2}-\d{2}/\d+) · (.*)$#s', $note, $m) ? [$m[1], $m[2]] : null;
    }

    /* ================================================================ */

    private static function order(int $orderId): array
    {
        $o = DB::selectOne('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if ($o === null) {
            throw new ReturnException('Order not found.');
        }

        return $o;
    }

    private static function lockOrder(int $orderId): void
    {
        if (DB::connection()->driver() === 'mysql') {
            DB::selectOne('SELECT id FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        }
    }

    private static function expect(array $r, string $from, string $verb): void
    {
        if ($r['status'] !== $from) {
            throw new ReturnException($r['request_number'] . ' is ' . strtolower(self::SHORT[$r['status']] ?? $r['status']) . ' — it can’t be ' . $verb . ' now.');
        }
    }

    /** Guarded status update: only one request can move it out of $from. */
    private static function guarded(array $r, string $from, array $set): void
    {
        $sets = implode(', ', array_map(static fn($k) => $k . ' = ?', array_keys($set)));
        $n = DB::update("UPDATE return_requests SET $sets WHERE id = ? AND status = ?", array_merge(array_values($set), [(int) $r['id'], $from]));
        if ($n !== 1) {
            throw new ReturnException($r['request_number'] . ' was just updated by someone else — reload and try again.');
        }
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<int,float> item id → qty (0..$capField)
     */
    private static function quantities(array $in, array $items, string $capField, string $verb): array
    {
        $given = (array) ($in['items'] ?? []);
        $out = [];
        foreach ($items as $it) {
            $iid = (int) $it['id'];
            $cap = (float) $it[$capField];
            $raw = $given[$iid] ?? $given[(string) $iid] ?? null;
            $q = $raw === null || trim((string) $raw) === '' ? $cap : round((float) num_or_null(trim((string) $raw)), 3);
            if ($q < 0 || $q > $cap + self::EPS) {
                throw new ReturnException($it['product_name'] . ': enter 0 to ' . InventoryService::qty($cap) . ' ' . $it['unit'] . '.');
            }
            $out[$iid] = min($q, $cap);
        }
        if (array_sum($out) <= 0) {
            throw new ReturnException($verb === 'approve'
                ? 'Approve at least one unit — or decline the request instead.'
                : 'Enter what actually came back (at least one unit). If nothing has arrived yet, keep the request approved until it does.');
        }

        return $out;
    }

    private static function joinNote(?string $old, ?string $new): ?string
    {
        $parts = array_filter([trim((string) $old), trim((string) $new)], static fn($p) => $p !== '');

        return $parts ? mb_substr(implode("\n", $parts), 0, 2000) : null;
    }

    /** @param array<int,float> $want @param array<int,array> $lines */
    private static function itemSummary(array $want, array $lines): string
    {
        return implode(', ', array_map(static fn($iid, $q) => $lines[$iid]['product_name'] . ' × ' . InventoryService::qty($q) . ' ' . $lines[$iid]['unit'], array_keys($want), $want));
    }

    /** @param list<array> $items @param array<int,float> $qty */
    private static function qtySummary(array $items, array $qty): string
    {
        return implode(', ', array_map(static fn($it) => $it['product_name'] . ' × ' . InventoryService::qty($qty[(int) $it['id']]) . ' ' . $it['unit'], $items));
    }

    private static function log(int $orderId, string $number, ?int $by, string $text): void
    {
        OrderService::history($orderId, 'delivered', 'delivered', $by, $number . ' · ' . $text);
    }
}
