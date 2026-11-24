<?php

namespace App\Controllers\Admin;

use App\Services\Media\MediaService as Media;
use App\Services\Order\Money;
use App\Services\Order\PaymentService;
use App\Services\Payment\Accounts;
use App\Services\Payment\Ledger;
use App\Services\Payment\PaymentException;
use App\Services\Payment\PaymentRequests;
use App\Services\Payment\PayWindow;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

/**
 * Payments: overview + verification queue, received payments, failed attempts, refunds, requests;
 * the verify / reject / record / refund / void / request actions (all through Ledger,
 * PaymentRequests — nothing here writes money tables directly); printable receipts.
 */
class PaymentController
{
    private const PER_PAGE = 30;
    public const TABS = ['verify' => 'To verify', 'received' => 'Received', 'failed' => 'Failed attempts', 'refunds' => 'Refunds', 'requests' => 'Requests'];

    public function index(Request $request): Response
    {
        PayWindow::expireStale();
        $tab = (string) $request->query('tab', 'verify');
        $tab = isset(self::TABS[$tab]) ? $tab : 'verify';
        $q = trim((string) $request->query('q', ''));
        $page = max(1, (int) $request->query('page', 1));
        $today = date('Y-m-d 00:00:00');
        $month = date('Y-m-01 00:00:00');
        $week = date('Y-m-d H:i:s', time() - 7 * 86400);

        $kpi = [
            'verify' => DB::selectOne("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS s FROM payment_attempts WHERE status = 'submitted'"),
            'today' => DB::selectOne("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS s FROM payments WHERE status = 'received' AND received_at >= ?", [$today]),
            'month' => DB::selectOne("SELECT COALESCE(SUM(amount), 0) AS s, COALESCE(SUM(CASE WHEN collected_via = 'window' THEN amount ELSE 0 END), 0) AS w FROM payments WHERE status = 'received' AND received_at >= ?", [$month]),
            'due' => DB::selectOne("SELECT COUNT(*) AS n, COALESCE(SUM(due_amount), 0) AS s FROM orders WHERE order_status <> 'rejected' AND due_amount > 0"),
            'refund' => DB::selectOne('SELECT COUNT(*) AS n, COALESCE(SUM(refund_due), 0) AS s FROM orders WHERE refund_due > 0'),
            'failed' => DB::selectOne("SELECT COUNT(*) AS n FROM payment_attempts WHERE status = 'expired' AND created_at >= ?", [$week]),
        ];

        $like = '%' . mb_strtolower($q) . '%';
        [$sql, $bind] = match ($tab) {
            'verify' => ["SELECT a.*, o.order_number, o.grand_total, o.due_amount, o.paid_amount FROM payment_attempts a LEFT JOIN orders o ON o.id = a.order_id
                WHERE a.status = 'submitted'" . ($q !== '' ? ' AND (LOWER(a.attempt_number) LIKE ? OR LOWER(COALESCE(o.order_number, \'\')) LIKE ? OR LOWER(COALESCE(a.payer_reference, \'\')) LIKE ? OR LOWER(COALESCE(a.payer_name, \'\')) LIKE ?)' : '') . '
                ORDER BY a.submitted_at, a.id', $q !== '' ? [$like, $like, $like, $like] : []],
            'received' => ["SELECT p.*, o.order_number, o.customer_name, pa.name AS account_name FROM payments p JOIN orders o ON o.id = p.order_id LEFT JOIN payment_accounts pa ON pa.id = p.payment_account_id"
                . ($q !== '' ? ' WHERE (LOWER(p.payment_number) LIKE ? OR LOWER(o.order_number) LIKE ? OR LOWER(COALESCE(p.reference, \'\')) LIKE ? OR LOWER(o.customer_name) LIKE ?)' : '') . '
                ORDER BY p.received_at DESC, p.id DESC', $q !== '' ? [$like, $like, $like, $like] : []],
            'failed' => ["SELECT a.*, o.order_number FROM payment_attempts a LEFT JOIN orders o ON o.id = a.order_id WHERE a.status = 'expired'"
                . ($q !== '' ? ' AND (LOWER(a.attempt_number) LIKE ? OR LOWER(COALESCE(o.order_number, \'\')) LIKE ? OR COALESCE(a.customer_phone, \'\') LIKE ?)' : '') . '
                ORDER BY a.id DESC', $q !== '' ? [$like, $like, $like] : []],
            'refunds' => ["SELECT r.*, o.order_number, o.customer_name, pa.name AS account_name FROM payment_refunds r JOIN orders o ON o.id = r.order_id LEFT JOIN payment_accounts pa ON pa.id = r.payment_account_id"
                . ($q !== '' ? ' WHERE (LOWER(r.refund_number) LIKE ? OR LOWER(o.order_number) LIKE ?)' : '') . ' ORDER BY r.refunded_at DESC, r.id DESC', $q !== '' ? [$like, $like] : []],
            'requests' => ['SELECT l.*, o.order_number, o.customer_name FROM payment_request_logs l JOIN orders o ON o.id = l.order_id'
                . ($q !== '' ? ' WHERE LOWER(o.order_number) LIKE ?' : '') . ' ORDER BY l.id DESC', $q !== '' ? [$like] : []],
        };
        $total = (int) (DB::selectOne("SELECT COUNT(*) AS n FROM ($sql) t", $bind)['n'] ?? 0);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $rows = DB::select($sql . ' LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $bind);

        return view('admin.payments.index', [
            'tab' => $tab, 'q' => $q, 'rows' => $rows, 'kpi' => $kpi, 'page' => $page, 'pages' => $pages, 'total' => $total,
            'refundDue' => $tab === 'refunds' ? DB::select('SELECT id, order_number, customer_name, refund_due, order_status FROM orders WHERE refund_due > 0 ORDER BY id DESC LIMIT 50') : [],
            'payAccounts' => Accounts::all(true),
            'hasUpi' => Accounts::defaultUpi() !== null,
        ]);
    }

    /** JSON for the verify window. */
    public function attempt(string $id): Response
    {
        $a = DB::selectOne('SELECT * FROM payment_attempts WHERE id = ?', [(int) $id]);
        if ($a === null) {
            return json(['ok' => false, 'message' => 'Not found.'], 404);
        }
        $o = $a['order_id'] ? DB::selectOne('SELECT id, order_number, grand_total, paid_amount, due_amount, order_status, customer_name FROM orders WHERE id = ?', [(int) $a['order_id']]) : null;
        $proof = $a['proof_media_id'] ? Media::find((int) $a['proof_media_id']) : null;
        $acc = $a['payment_account_id'] ? Accounts::find((int) $a['payment_account_id']) : null;

        return json(['ok' => true, 'attempt' => [
            'id' => (int) $a['id'], 'number' => $a['attempt_number'], 'ref' => PayWindow::shortRef((string) $a['attempt_number']), 'status' => $a['status'],
            'amount' => (float) $a['amount'], 'amount_fmt' => Money::inr($a['amount']), 'payer_name' => $a['payer_name'], 'payer_reference' => $a['payer_reference'],
            'submitted' => $a['submitted_at'] ? date('j M Y, g:i a', strtotime((string) $a['submitted_at'])) : null,
            'submitted_local' => $a['submitted_at'] ? date('Y-m-d\TH:i', strtotime((string) $a['submitted_at'])) : date('Y-m-d\TH:i'),
            'account_id' => $acc ? (int) $acc['id'] : null, 'account' => $acc ? Accounts::label($acc) : null,
            'context' => $a['context'], 'phone' => $a['customer_phone'],
        ], 'order' => $o ? [
            'id' => (int) $o['id'], 'number' => $o['order_number'], 'customer' => $o['customer_name'], 'total' => Money::inr($o['grand_total']),
            'paid' => (float) $o['paid_amount'], 'due' => (float) $o['due_amount'], 'url' => route('admin.orders.show', ['id' => $o['id']]),
        ] : null, 'proof' => $proof ? [
            'url' => route('admin.media.file', ['id' => $proof['id']]), 'thumb' => route('admin.media.file', ['id' => $proof['id']]) . ($proof['thumb_path'] ? '?thumb=1' : ''),
            'image' => $proof['kind'] === 'image', 'name' => $proof['original_name'],
        ] : null, 'duplicates' => Ledger::duplicateReference($a['payer_reference'], (int) $a['id']),
            'verify_url' => route('admin.payments.attempts.verify', ['id' => $a['id']]),
            'reject_url' => route('admin.payments.attempts.reject', ['id' => $a['id']]),
        ]);
    }

    public function verify(Request $request, string $id): Response
    {
        return $this->run($request, function () use ($request, $id): array {
            $r = Ledger::verify((int) $id, $request->input());
            $d = $r['difference'];

            return [$r['payment_number'] . ' recorded.' . ($d < 0 ? ' ' . Money::inr(-$d) . ' less than expected — the order shows it as still due.' : ($d > 0 ? ' ' . Money::inr($d) . ' more than expected — check whether a refund is due.' : '')), $r];
        });
    }

    public function reject(Request $request, string $id): Response
    {
        return $this->run($request, function () use ($request, $id): array {
            Ledger::reject((int) $id, (string) $request->input('reason'));

            return ['Payment rejected. The customer sees your reason on their order page.', []];
        });
    }

    public function record(Request $request, string $id): Response
    {
        return $this->run($request, function () use ($request, $id): array {
            $r = Ledger::record((int) $id, $request->input());

            return ['Payment ' . $r['payment_number'] . ' recorded.', $r];
        });
    }

    public function refund(Request $request, string $id): Response
    {
        return $this->run($request, function () use ($request, $id): array {
            $r = Ledger::refund((int) $id, $request->input());

            return ['Refund ' . $r['refund_number'] . ' recorded.', $r];
        });
    }

    public function void(Request $request, string $id): Response
    {
        return $this->run($request, function () use ($request, $id): array {
            Ledger::voidPayment((int) $id, (string) $request->input('reason'));

            return ['Payment voided — it no longer counts towards the order.', []];
        });
    }

    public function voidRefund(Request $request, string $id): Response
    {
        return $this->run($request, function () use ($request, $id): array {
            Ledger::voidRefund((int) $id, (string) $request->input('reason'));

            return ['Refund entry voided.', []];
        });
    }

    /** POST /orders/{id}/payment-request — JSON (the dialog opens wa.me / sms: itself). */
    public function request(Request $request, string $id): Response
    {
        try {
            $r = PaymentRequests::send((int) $id, (string) $request->input('channel'), (string) $request->input('to'), (string) $request->input('message'));
        } catch (PaymentException $e) {
            return json(['ok' => false, 'message' => $e->getMessage()], 409);
        }
        $msg = $r['status'] === 'sent'
            ? Money::inr($r['amount']) . ' requested by email to ' . $r['recipient'] . '.'
            : (PaymentRequests::CHANNELS[$request->input('channel')] ?? '') . ' opened in a new tab — press Send there to deliver it.';

        return json(['ok' => true, 'message' => $msg] + $r + ['cooldown' => PaymentRequests::COOLDOWN]);
    }

    public function receipt(string $id): Response
    {
        $p = DB::selectOne('SELECT * FROM payments WHERE id = ?', [(int) $id]);
        $o = $p ? DB::selectOne('SELECT * FROM orders WHERE id = ?', [(int) $p['order_id']]) : null;
        if ($o === null) {
            session()->flash('warning', 'That payment no longer exists.');

            return redirect(route('admin.payments') . '?tab=received');
        }

        return view('documents.receipt', [
            'p' => $p, 'o' => $o,
            'account' => $p['payment_account_id'] ? Accounts::find((int) $p['payment_account_id']) : null,
            'business' => business(),
            'logo' => business('logo') && is_file(app()->publicPath((string) business('logo'))) ? base_url((string) business('logo')) : null,
        ]);
    }

    /* ---------------------------------------------------------------- */

    /** @param \Closure(): array{0:string,1:array<string,mixed>} $fn */
    private function run(Request $request, \Closure $fn): Response
    {
        try {
            [$msg, $data] = $fn();
        } catch (PaymentException $e) {
            if ($request->wantsJson()) {
                return json(['ok' => false, 'message' => $e->getMessage()], 409);
            }
            session()->flash('error', $e->getMessage());

            return redirect((string) ($request->header('Referer') ?: route('admin.payments')));
        }
        session()->flash('success', $msg);

        return $request->wantsJson() ? json(['ok' => true, 'message' => $msg] + $data) : redirect((string) ($request->header('Referer') ?: route('admin.payments')));
    }
}
