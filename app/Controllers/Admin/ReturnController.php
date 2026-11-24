<?php

namespace App\Controllers\Admin;

use App\Services\Order\Money;
use App\Services\Order\ReturnException;
use App\Services\Order\ReturnService as Returns;
use App\Services\Payment\Accounts;
use App\Services\Payment\PaymentException;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

/** Returns & replacements (Stage 6): list, detail, raise from an order, and the admin steps. All writes go through ReturnService. */
class ReturnController
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $f = [
            'status' => (string) $request->query('status', ''),
            'type' => (string) $request->query('type', ''),
            'q' => trim((string) $request->query('q', '')),
        ];
        $where = ['1 = 1'];
        $bind = [];
        if (isset(Returns::STATUSES[$f['status']])) {
            $where[] = 'r.status = ?';
            $bind[] = $f['status'];
        } elseif ($f['status'] === 'open') {
            $where[] = "r.status IN ('submitted', 'approved', 'received')";
        }
        if (isset(Returns::TYPES[$f['type']])) {
            $where[] = 'r.request_type = ?';
            $bind[] = $f['type'];
        }
        if ($f['q'] !== '') {
            $digits = preg_replace('/\D+/', '', $f['q']) ?? '';
            $q = '%' . mb_strtolower($f['q']) . '%';
            $where[] = '(LOWER(r.request_number) LIKE ? OR LOWER(o.order_number) LIKE ? OR LOWER(o.customer_name) LIKE ?' . (strlen($digits) >= 3 ? ' OR o.customer_phone LIKE ?' : '') . ')';
            array_push($bind, $q, $q, $q);
            if (strlen($digits) >= 3) {
                $bind[] = '%' . $digits . '%';
            }
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) (DB::selectOne("SELECT COUNT(*) AS n FROM return_requests r JOIN orders o ON o.id = r.order_id WHERE $sqlWhere", $bind)['n'] ?? 0);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) $request->query('page', 1)));
        $rows = DB::select("SELECT r.*, o.order_number, o.customer_name, o.customer_phone,
                (SELECT COUNT(*) FROM return_request_items i WHERE i.return_request_id = r.id) AS item_count,
                (SELECT COALESCE(SUM(i.refund_amount), 0) FROM return_request_items i WHERE i.return_request_id = r.id) AS credited
            FROM return_requests r JOIN orders o ON o.id = r.order_id WHERE $sqlWhere ORDER BY r.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $bind);
        foreach ($rows as &$r) {
            $r['items'] = Returns::items((int) $r['id']);
        }
        unset($r);
        $counts = array_fill_keys(array_keys(Returns::STATUSES), 0);
        foreach (DB::select('SELECT status, COUNT(*) AS n FROM return_requests GROUP BY status') as $c) {
            $counts[$c['status']] = (int) $c['n'];
        }
        $monthStart = date('Y-m-01 00:00:00');
        $kpi = [
            'credited_month' => (float) (DB::selectOne("SELECT COALESCE(SUM(i.refund_amount), 0) AS s FROM return_request_items i JOIN return_requests r ON r.id = i.return_request_id
                WHERE r.status = 'resolved' AND r.completed_at >= ?", [$monthStart])['s'] ?? 0),
            'refund_due' => (int) (DB::selectOne("SELECT COUNT(DISTINCT o.id) AS n FROM orders o JOIN return_requests r ON r.order_id = o.id WHERE o.refund_due > 0 AND r.status = 'resolved' AND r.request_type = 'return'")['n'] ?? 0),
        ];

        return view('admin.returns.index', compact('rows', 'counts', 'kpi', 'f', 'page', 'pages', 'total'));
    }

    public function show(string $id): Response
    {
        try {
            $r = Returns::find((int) $id);
        } catch (ReturnException $e) {
            session()->flash('warning', $e->getMessage());

            return redirect(route('admin.returns'));
        }
        $o = DB::selectOne('SELECT * FROM orders WHERE id = ?', [(int) $r['order_id']]);
        $items = Returns::items((int) $r['id']);
        $refunds = DB::select('SELECT f.*, pa.name AS account_name FROM payment_refunds f LEFT JOIN payment_accounts pa ON pa.id = f.payment_account_id WHERE f.return_request_id = ? ORDER BY f.id DESC', [(int) $r['id']]);
        $values = [];
        foreach ($items as $it) {
            $q = (float) ($it['received_quantity'] ?? $it['approved_quantity'] ?? $it['requested_quantity']);
            $values[(int) $it['id']] = Returns::valueCents($it, $o, $q) / 100;
        }
        $stock = [];
        foreach ($items as $it) {
            $s = \App\Services\Inventory\InventoryService::stockFor((int) $it['product_id']);
            $stock[(int) $it['product_id']] = $s ? \App\Services\Inventory\InventoryService::qty((float) $s['quantity']) . ' ' . $s['unit'] : null;
        }

        return view('admin.returns.show', [
            'rq' => $r,
            'o' => $o,
            'items' => $items,
            'values' => $values,
            'stock' => $stock,
            'events' => Returns::events($r),
            'refunds' => $refunds,
            'refunded' => array_sum(array_map(static fn($x) => $x['status'] === 'paid' ? (float) $x['amount'] : 0.0, $refunds)),
            'credited' => array_sum(array_map(static fn($it) => (float) $it['refund_amount'], $items)),
            'payAccounts' => Accounts::all(true),
        ]);
    }

    /** Raise a request from the order detail page (admin, on behalf of the customer). */
    public function store(Request $request, string $id): Response
    {
        try {
            $res = Returns::submit((int) $id, $request->input());
        } catch (ReturnException $e) {
            session()->flash('error', $e->getMessage());

            return redirect(route('admin.orders.show', ['id' => (int) $id]) . '?do=return');
        }
        session()->flash('success', 'Request ' . $res['request_number'] . ' created — review it and accept or decline.');

        return redirect(route('admin.returns.show', ['id' => $res['id']]));
    }

    public function approve(Request $request, string $id): Response
    {
        return $this->step($id, fn() => Returns::approve((int) $id, $request->input()), 'Approved. Collect the goods, then mark them received.');
    }

    public function reject(Request $request, string $id): Response
    {
        return $this->step($id, fn() => Returns::reject((int) $id, (string) $request->input('note', '')), 'Request declined. The customer sees your note on their order page.');
    }

    public function receive(Request $request, string $id): Response
    {
        $warnings = [];
        $res = $this->step($id, function () use ($request, $id, &$warnings): void {
            $warnings = Returns::receive((int) $id, $request->input());
        }, 'Goods received.');
        if ($warnings) {
            session()->flash('warning', 'Some stock wasn’t added back: ' . implode(' ', $warnings));
        }

        return $res;
    }

    public function resolve(Request $request, string $id): Response
    {
        try {
            $out = Returns::resolve((int) $id, $request->input());
        } catch (ReturnException $e) {
            session()->flash('error', $e->getMessage());

            return redirect(route('admin.returns.show', ['id' => (int) $id]));
        }
        $r = Returns::find((int) $id);
        if ($r['request_type'] === 'return') {
            $due = (float) DB::table('orders')->where('id', (int) $r['order_id'])->value('refund_due');
            session()->flash('success', 'Completed — ' . Money::inr($out['credit']) . ' credited to ' . $r['order_number'] . '.'
                . ($due > 0 ? ' Send ' . Money::inr($due) . ' back to the customer, then record the refund.' : ''));
        } else {
            session()->flash('success', 'Replacement dispatched — stock updated.');
        }
        if ($out['warnings']) {
            session()->flash('warning', 'Some stock wasn’t deducted: ' . implode(' ', $out['warnings']));
        }

        return redirect(route('admin.returns.show', ['id' => (int) $id]));
    }

    /** Money sent back for a completed return (fetch from the shared refund dialog). */
    public function refund(Request $request, string $id): Response
    {
        try {
            $r = Returns::recordRefund((int) $id, $request->input());
        } catch (ReturnException | PaymentException $e) {
            if ($request->wantsJson()) {
                return json(['ok' => false, 'message' => $e->getMessage()], 409);
            }
            session()->flash('error', $e->getMessage());

            return redirect(route('admin.returns.show', ['id' => (int) $id]));
        }
        $msg = 'Refund ' . $r['refund_number'] . ' recorded.';
        session()->flash('success', $msg);

        return $request->wantsJson() ? json(['ok' => true, 'message' => $msg] + $r) : redirect(route('admin.returns.show', ['id' => (int) $id]));
    }

    /* ---------------------------------------------------------------- */

    private function step(string $id, \Closure $fn, string $msg): Response
    {
        try {
            $fn();
            if ($msg !== '') {
                session()->flash('success', $msg);
            }
        } catch (ReturnException $e) {
            session()->flash('error', $e->getMessage());
        }

        return redirect(route('admin.returns.show', ['id' => (int) $id]));
    }
}
