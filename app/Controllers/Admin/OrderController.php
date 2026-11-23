<?php

namespace App\Controllers\Admin;

use App\Services\Customer\Phone;
use App\Services\Inventory\InventoryService;
use App\Services\Order\DocumentService;
use App\Services\Order\OrderException;
use App\Services\Order\OrderService as Orders;
use App\Services\Order\PaymentService;
use App\Services\Media\MediaService;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

/** Orders: one list with status/payment chips, create, detail, one-step transitions, bulk moves. */
class OrderController
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $f = [
            'status' => (string) $request->query('status', ''),
            'payment' => (string) $request->query('payment', ''),
            'channel' => (string) $request->query('channel', ''),
            'q' => trim((string) $request->query('q', '')),
        ];
        $where = ['1 = 1'];
        $bind = [];
        if (isset(Orders::STATUSES[$f['status']])) {
            $where[] = 'o.order_status = ?';
            $bind[] = $f['status'];
        } elseif ($f['status'] === 'open') {
            $where[] = "o.order_status NOT IN ('delivered', 'rejected')";
        }
        if ($f['payment'] === 'due') {
            $where[] = "o.due_amount > 0 AND o.order_status <> 'rejected'";
        } elseif (isset(PaymentService::STATUSES[$f['payment']])) {
            $where[] = 'o.payment_status = ?';
            $bind[] = $f['payment'];
        }
        if ($f['channel'] === 'online' || $f['channel'] === 'offline') {
            $where[] = 'o.channel = ?';
            $bind[] = $f['channel'];
        }
        if ($f['q'] !== '') {
            $digits = preg_replace('/\D+/', '', $f['q']) ?? '';
            $where[] = '(LOWER(o.order_number) LIKE ? OR LOWER(o.customer_name) LIKE ?' . (strlen($digits) >= 3 ? ' OR o.customer_phone LIKE ?' : '') . ')';
            $q = '%' . mb_strtolower($f['q']) . '%';
            array_push($bind, $q, $q);
            if (strlen($digits) >= 3) {
                $bind[] = '%' . $digits . '%';
            }
        }
        $w = implode(' AND ', $where);
        $total = (int) (DB::selectOne("SELECT COUNT(*) AS n FROM orders o WHERE $w", $bind)['n'] ?? 0);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) $request->query('page', 1)));
        $rows = DB::select("SELECT o.*, (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) AS item_count
            FROM orders o WHERE $w ORDER BY o.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $bind);
        $warn = [];
        foreach ($rows as $r) {
            if ($r['order_status'] === 'pending') {
                $warn[(int) $r['id']] = Orders::stockWarnings((int) $r['id']);
            }
        }

        $counts = array_fill_keys(array_keys(Orders::STATUSES), 0);
        foreach (DB::select('SELECT order_status, COUNT(*) AS n FROM orders GROUP BY order_status') as $c) {
            $counts[$c['order_status']] = (int) $c['n'];
        }
        $today = date('Y-m-d 00:00:00');
        $kpi = DB::selectOne("SELECT
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS today,
                SUM(CASE WHEN created_at >= ? AND order_status <> 'rejected' THEN grand_total ELSE 0 END) AS today_value,
                SUM(CASE WHEN order_status NOT IN ('delivered', 'rejected') THEN 1 ELSE 0 END) AS open,
                SUM(CASE WHEN order_status NOT IN ('delivered', 'rejected') THEN grand_total ELSE 0 END) AS open_value,
                SUM(CASE WHEN order_status <> 'rejected' AND due_amount > 0 THEN 1 ELSE 0 END) AS due_count,
                SUM(CASE WHEN order_status <> 'rejected' THEN due_amount ELSE 0 END) AS due_total
            FROM orders", [$today, $today]) ?? [];

        return view('admin.orders.index', [
            'rows' => $rows, 'warn' => $warn, 'filters' => $f, 'counts' => $counts, 'kpi' => $kpi,
            'page' => $page, 'pages' => $pages, 'total' => $total,
        ]);
    }

    public function create(Request $request): Response
    {
        $customer = null;
        if (ctype_digit((string) $request->query('customer', ''))) {
            $customer = DB::selectOne('SELECT id, name, phone, email, company, customer_type FROM customers WHERE id = ?', [(int) $request->query('customer')]);
        }

        return view('admin.orders.create', [
            'customer' => $customer,
            'gstDefault' => trim((string) business('gstin')) !== '',
            'gstRate' => (float) (business('tax.gst_rate') ?: 18),
            'sellerState' => (string) business('address.state'),
            'hasGstin' => trim((string) business('gstin')) !== '',
        ]);
    }

    public function store(Request $request): Response
    {
        $id = Orders::create($request->input());
        $o = DB::selectOne('SELECT order_number, grand_total FROM orders WHERE id = ?', [$id]);
        session()->flash('success', 'Order ' . $o['order_number'] . ' created — waiting for you to accept it.');
        $to = route('admin.orders.show', ['id' => $id]);

        return $request->wantsJson() ? json(['ok' => true, 'redirect' => $to]) : redirect($to);
    }

    public function show(Request $request, string $id): Response
    {
        $oid = (int) $id;
        $o = DB::selectOne('SELECT * FROM orders WHERE id = ?', [$oid]);
        if ($o === null) {
            session()->flash('warning', 'That order no longer exists.');

            return redirect(route('admin.orders'));
        }
        $items = DB::select('SELECT i.*, ' . MediaService::coverSql('product', 'i.product_id') . ' AS cover FROM order_items i WHERE i.order_id = ? ORDER BY i.sort, i.id', [$oid]);

        return view('admin.orders.show', [
            'o' => $o,
            'items' => $items,
            'history' => DB::select('SELECT * FROM order_status_history WHERE order_id = ? ORDER BY id DESC', [$oid]),
            'delivery' => DB::selectOne('SELECT * FROM order_deliveries WHERE order_id = ?', [$oid]),
            'invoice' => DocumentService::invoiceFor($oid),
            'slip' => DB::selectOne("SELECT * FROM order_invoices WHERE order_id = ? AND document_type = 'packing_slip'", [$oid]),
            'customer' => DB::selectOne('SELECT id, name, phone, email, company, customer_type, total_orders FROM customers WHERE id = ?', [(int) $o['customer_id']]),
            'warnings' => $o['order_status'] === 'pending' ? Orders::stockWarnings($oid) : [],
            'otp' => session()->get('order_otp'),
            'orderUrl' => self::absolute((string) route('order.public', ['token' => $o['public_token']])),
            'do' => (string) $request->query('do', ''),
        ]);
    }

    /** POST /orders/{id}/status — the one next step (or reject). */
    public function transition(Request $request, string $id): Response
    {
        $oid = (int) $id;
        $to = (string) $request->input('to');
        try {
            $res = Orders::transition($oid, $to, $request->input() + ['by' => null]);
        } catch (OrderException $e) {
            session()->flash('error', $e->getMessage());

            return redirect($this->back($request, $oid));
        }
        $num = (string) DB::table('orders')->where('id', $oid)->value('order_number');
        $msg = [
            'accepted' => 'Order ' . $num . ' accepted.',
            'rejected' => 'Order ' . $num . ' declined. The customer sees your note on their order page.',
            'processing' => 'Order ' . $num . ' is being prepared.',
            'packed' => 'Order ' . $num . ' packed — the packing slip is ready to print.',
            'dispatched' => 'Order ' . $num . ' dispatched. Invoice issued and stock updated.',
            'delivered' => 'Order ' . $num . ' delivered.',
        ][$to] ?? 'Order updated.';
        session()->flash('success', $msg);
        if ($res['warnings']) {
            session()->flash('warning', 'Some stock wasn’t deducted: ' . implode(' ', $res['warnings']));
        }
        if ($res['otp']) {
            session()->flash('order_otp', $res['otp']);

            return redirect(route('admin.orders.show', ['id' => $oid]));
        }

        return redirect($this->back($request, $oid));
    }

    public function bulk(Request $request): Response
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('ids', [])))));
        try {
            $r = Orders::bulk($ids, (string) $request->input('to'));
            $n = count($r['moved']);
            if ($n) {
                session()->flash('success', $n . ' order' . ($n === 1 ? '' : 's') . ' moved to ' . strtolower(Orders::STATUSES[$request->input('to')] ?? '') . '.');
            }
            if ($r['failed']) {
                session()->flash('warning', implode(' ', array_map(static fn($k, $v) => $k . ': ' . $v, array_keys($r['failed']), $r['failed'])));
            }
        } catch (OrderException $e) {
            session()->flash('error', $e->getMessage());
        }

        return redirect((string) ($request->header('Referer') ?: route('admin.orders')));
    }

    public function resendOtp(Request $request, string $id): Response
    {
        try {
            session()->flash('order_otp', Orders::resendOtp((int) $id));
            session()->flash('success', 'New delivery OTP generated — send it to the customer. The old one no longer works.');
        } catch (OrderException $e) {
            session()->flash('error', $e->getMessage());
        }

        return redirect(route('admin.orders.show', ['id' => (int) $id]));
    }

    /* ---------------- JSON lookups for the create form ---------------- */

    public function lookupCustomers(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return json(['items' => []]);
        }
        $digits = preg_replace('/\D+/', '', $q) ?? '';
        $like = '%' . mb_strtolower($q) . '%';
        $rows = DB::select('SELECT id, name, phone, email, company, customer_type FROM customers WHERE merged_into_id IS NULL AND (LOWER(name) LIKE ? OR LOWER(company) LIKE ?'
            . (strlen($digits) >= 3 ? ' OR phone LIKE ?' : '') . ') ORDER BY name LIMIT 8',
            strlen($digits) >= 3 ? [$like, $like, '%' . (Phone::normalize($digits) ?? $digits) . '%'] : [$like, $like]);

        return json(['items' => array_map(static fn($r) => $r + ['phone_fmt' => Phone::format((string) $r['phone'])], $rows)]);
    }

    public function lookupAddresses(Request $request, string $id): Response
    {
        $rows = DB::select('SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, id', [(int) $id]);

        return json(['items' => array_map(static fn($a) => [
            'id' => (int) $a['id'], 'label' => $a['label'], 'type' => $a['type'], 'is_default' => (int) $a['is_default'],
            'state' => $a['state'], 'lines' => Orders::addressLines($a),
        ], $rows)]);
    }

    public function lookupProducts(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $like = '%' . mb_strtolower($q) . '%';
        $rows = DB::select("SELECT p.id, p.name, p.sku, p.status, pr.price, pr.unit, pr.pack_qty, pr.pack_unit, s.quantity AS stock_qty, s.unit AS stock_unit,
                " . MediaService::coverSql('product', 'p.id') . " AS cover
            FROM products p LEFT JOIN product_pricing pr ON pr.product_id = p.id LEFT JOIN inventory_stock s ON s.product_id = p.id
            WHERE p.status <> 'archived'" . ($q !== '' ? ' AND (LOWER(p.name) LIKE ? OR LOWER(p.sku) LIKE ? OR p.id IN (SELECT product_id FROM product_variants WHERE LOWER(sku) LIKE ?))' : '') . '
            ORDER BY p.name LIMIT 12', $q !== '' ? [$like, $like, $like] : []);
        $variants = [];
        if ($rows) {
            $ids = implode(',', array_map(static fn($r) => (int) $r['id'], $rows));
            foreach (DB::select("SELECT id, product_id, title, sku, price, unit FROM product_variants WHERE product_id IN ($ids) AND status = 'active' ORDER BY sort, id") as $v) {
                $variants[(int) $v['product_id']][] = ['id' => (int) $v['id'], 'title' => $v['title'], 'sku' => $v['sku'], 'price' => $v['price'] !== null ? (float) $v['price'] : null, 'unit' => $v['unit']];
            }
        }

        return json(['items' => array_map(static fn($r) => [
            'id' => (int) $r['id'], 'name' => $r['name'], 'sku' => $r['sku'], 'status' => $r['status'],
            'price' => $r['price'] !== null ? (float) $r['price'] : null, 'unit' => $r['unit'],
            'stock' => $r['stock_qty'] !== null ? InventoryService::qty((float) $r['stock_qty']) . ' ' . $r['stock_unit'] : null,
            'stock_low' => $r['stock_qty'] !== null && (float) $r['stock_qty'] <= 0,
            'cover' => $r['cover'] ? base_url((string) $r['cover']) : null,
            'variants' => $variants[(int) $r['id']] ?? [],
        ], $rows)]);
    }

    /* ---------------------------------------------------------------- */

    public static function absolute(string $path): string
    {
        return preg_match('#^https?://#', $path) ? $path : base_url(ltrim($path, '/'));
    }

    private function back(Request $request, int $oid): string
    {
        $ref = (string) ($request->header('Referer') ?? '');

        // Never bounce back into an open dialog (?do=dispatch).
        $ref = (string) preg_replace('/([?&])do=[^&]*&?/', '$1', $ref);

        return str_contains($ref, '/admin/orders') && !str_contains($ref, '/create') ? rtrim($ref, '?&') : route('admin.orders.show', ['id' => $oid]);
    }
}
