<?php

namespace App\Controllers\Admin;

use App\Services\Customer\CustomerService as Customers;
use App\Services\Customer\CustomerStats;
use App\Services\Customer\Phone;
use App\Services\Customer\SegmentMatcher;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

/** Customers (online + offline). Writes go through CustomerService; segment rules through SegmentMatcher. */
class CustomerController
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $f = [
            'q' => trim((string) $request->query('q', '')),
            'type' => (string) $request->query('type', ''),
            'status' => (string) $request->query('status', ''),
            'segment' => (string) $request->query('segment', ''),
            'tag' => (string) $request->query('tag', ''),
            'online' => (string) $request->query('online', ''),
            'sort' => (string) $request->query('sort', 'recent'),
        ];
        [$where, $bind] = $this->filters($f);
        $order = match ($f['sort']) {
            'name' => 'c.name ASC',
            'spent' => 'c.total_spent DESC, c.name ASC',
            'last_order' => 'c.last_order_at IS NULL, c.last_order_at DESC, c.id DESC',
            default => 'c.id DESC',
        };

        if ($request->query('export') === 'csv') {
            return $this->export($where, $bind, $order);
        }

        $total = (int) (DB::selectOne('SELECT COUNT(*) AS n FROM customers c WHERE ' . $where, $bind)['n'] ?? 0);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) $request->query('page', 1)));
        $rows = DB::select('SELECT c.* FROM customers c WHERE ' . $where . ' ORDER BY ' . $order
            . ' LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $bind);
        [$addresses, $tags] = $this->decorate(array_map(static fn($r) => (int) $r['id'], $rows));

        $monthStart = date('Y-m-01 00:00:00');
        $kpi = DB::selectOne('SELECT COUNT(*) AS total,
                SUM(CASE WHEN is_online = 1 THEN 1 ELSE 0 END) AS online,
                SUM(CASE WHEN marketing_consent = 1 THEN 1 ELSE 0 END) AS subscribed,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS new_month,
                SUM(CASE WHEN customer_type IN (\'builder\', \'dealer\', \'architect\') THEN 1 ELSE 0 END) AS trade
            FROM customers WHERE merged_into_id IS NULL', [$monthStart]) ?? [];

        return view('admin.customers.index', [
            'rows' => $rows,
            'addresses' => $addresses,
            'tags' => $tags,
            'filters' => $f,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'kpi' => array_map('intval', $kpi),
            'segments' => DB::select('SELECT id, name, type, member_count FROM customer_segments WHERE is_active = 1 ORDER BY name'),
            'allTags' => DB::select('SELECT id, name, color FROM customer_tags ORDER BY name'),
        ]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(Request $request, string $id): Response
    {
        $c = DB::selectOne('SELECT * FROM customers WHERE id = ?', [(int) $id]);

        return $c ? $this->form($c) : redirect(route('admin.customers'));
    }

    public function store(Request $request): Response
    {
        $cid = Customers::save(Customers::validate($request->input()));
        $name = (string) DB::table('customers')->where('id', $cid)->value('name');
        session()->flash('success', 'Customer "' . $name . '" added.');

        return redirect($request->input('_after') === 'new' ? route('admin.customers.create') : route('admin.customers.show', ['id' => $cid]));
    }

    public function update(Request $request, string $id): Response
    {
        $cid = (int) $id;
        if (!DB::table('customers')->where('id', $cid)->exists()) {
            return redirect(route('admin.customers'));
        }
        Customers::save(Customers::validate($request->input(), $cid), $cid);
        session()->flash('success', 'Customer "' . trim((string) $request->input('name')) . '" updated.');

        return redirect(route('admin.customers.show', ['id' => $cid]));
    }

    public function show(Request $request, string $id): Response
    {
        $cid = (int) $id;
        $c = DB::selectOne('SELECT * FROM customers WHERE id = ?', [$cid]);
        if ($c === null) {
            session()->flash('warning', 'That customer no longer exists.');

            return redirect(route('admin.customers'));
        }
        $segments = [];
        foreach (DB::select('SELECT * FROM customer_segments WHERE is_active = 1 ORDER BY name') as $s) {
            [$sql, $bind] = SegmentMatcher::forSegment($s);
            if (DB::selectOne('SELECT 1 AS ok FROM customers c WHERE c.id = ? AND (' . $sql . ')', array_merge([$cid], $bind))) {
                $segments[] = $s;
            }
        }

        return view('admin.customers.show', [
            'c' => $c,
            'addresses' => DB::select('SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, id', [$cid]),
            'tags' => DB::select('SELECT t.* FROM customer_tags t JOIN customer_tag_map m ON m.tag_id = t.id WHERE m.customer_id = ? ORDER BY t.name', [$cid]),
            'segments' => $segments,
            'user' => $c['user_id'] ? DB::selectOne('SELECT id, name, email, phone, status, last_login_at, created_at FROM users WHERE id = ?', [(int) $c['user_id']]) : null,
            'reviews' => DB::select('SELECT r.id, r.rating, r.title, r.status, r.created_at, p.name AS product_name, p.id AS product_id
                FROM product_reviews r LEFT JOIN products p ON p.id = r.product_id WHERE r.customer_id = ? ORDER BY r.id DESC LIMIT 10', [$cid]),
            'aov' => CustomerStats::aov((int) $c['total_orders'], (float) $c['total_spent']),
        ]);
    }

    public function destroy(Request $request, string $id): Response
    {
        $name = Customers::delete((int) $id);
        if ($name !== null) {
            session()->flash('success', 'Customer "' . $name . '" deleted. Their reviews were kept.');
        }

        return redirect(route('admin.customers'));
    }

    /* ---------------------------------------------------------------- */

    private function form(?array $c): Response
    {
        $cid = $c ? (int) $c['id'] : 0;

        return view('admin.customers.create', [
            'c' => $c,
            'isEdit' => $c !== null,
            'addresses' => $cid ? DB::select('SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, id', [$cid]) : [],
            'tagIds' => $cid ? array_map(static fn($r) => (int) $r['tag_id'], DB::select('SELECT tag_id FROM customer_tag_map WHERE customer_id = ?', [$cid])) : [],
            'allTags' => DB::select('SELECT id, name, color FROM customer_tags ORDER BY name'),
            'user' => $c && $c['user_id'] ? DB::selectOne('SELECT id, email, phone, last_login_at FROM users WHERE id = ?', [(int) $c['user_id']]) : null,
            'aov' => $c ? CustomerStats::aov((int) $c['total_orders'], (float) $c['total_spent']) : 0,
        ]);
    }

    /**
     * @param  array<string,string> $f
     * @return array{0:string, 1:list<mixed>}
     */
    private function filters(array $f): array
    {
        $where = ['c.merged_into_id IS NULL'];
        $bind = [];
        if ($f['q'] !== '') {
            $q = '%' . mb_strtolower($f['q']) . '%';
            $digits = preg_replace('/\D+/', '', $f['q']) ?? '';
            $where[] = '(LOWER(c.name) LIKE ? OR LOWER(c.email) LIKE ? OR LOWER(c.company) LIKE ?' . ($digits !== '' ? ' OR c.phone LIKE ?' : '') . ')';
            array_push($bind, $q, $q, $q);
            if ($digits !== '') {
                $bind[] = '%' . (Phone::normalize($digits) ?? $digits) . '%';
            }
        }
        if (isset(Customers::TYPES[$f['type']])) {
            $where[] = 'c.customer_type = ?';
            $bind[] = $f['type'];
        }
        if (isset(Customers::STATUSES[$f['status']])) {
            $where[] = 'c.status = ?';
            $bind[] = $f['status'];
        }
        if ($f['online'] === 'yes' || $f['online'] === 'no') {
            $where[] = 'c.is_online = ' . ($f['online'] === 'yes' ? '1' : '0');
        }
        if ($f['tag'] !== '' && ctype_digit($f['tag'])) {
            $where[] = 'EXISTS (SELECT 1 FROM customer_tag_map m WHERE m.customer_id = c.id AND m.tag_id = ?)';
            $bind[] = (int) $f['tag'];
        }
        if ($f['segment'] !== '' && ctype_digit($f['segment'])) {
            $seg = DB::selectOne('SELECT * FROM customer_segments WHERE id = ?', [(int) $f['segment']]);
            if ($seg) {
                // Live rules, not the cache: the list always agrees with the segment preview.
                [$sql, $sb] = SegmentMatcher::forSegment($seg);
                $where[] = '(' . $sql . ')';
                array_push($bind, ...$sb);
            }
        }

        return [implode(' AND ', $where), $bind];
    }

    /**
     * Default address + tags for a page of customers (two queries, not N).
     *
     * @param  list<int> $ids
     * @return array{0: array<int, array<string,mixed>>, 1: array<int, list<array<string,mixed>>>}
     */
    private function decorate(array $ids): array
    {
        if (!$ids) {
            return [[], []];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $addresses = [];
        foreach (DB::select("SELECT * FROM customer_addresses WHERE customer_id IN ($in) ORDER BY is_default DESC, id", $ids) as $a) {
            $addresses[(int) $a['customer_id']] ??= $a;
        }
        $tags = [];
        foreach (DB::select("SELECT m.customer_id, t.id, t.name, t.color FROM customer_tag_map m JOIN customer_tags t ON t.id = m.tag_id WHERE m.customer_id IN ($in) ORDER BY t.name", $ids) as $t) {
            $tags[(int) $t['customer_id']][] = $t;
        }

        return [$addresses, $tags];
    }

    /** @param list<mixed> $bind */
    private function export(string $where, array $bind, string $order): Response
    {
        $rows = DB::select('SELECT c.* FROM customers c WHERE ' . $where . ' ORDER BY ' . $order, $bind);
        [$addresses, $tags] = $this->decorate(array_map(static fn($r) => (int) $r['id'], $rows));
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['ID', 'Name', 'Mobile', 'Email', 'Company', 'GSTIN', 'Type', 'Status', 'Lifecycle', 'Online account', 'Subscribed',
            'Address', 'City', 'District', 'State', 'Pincode', 'Tags', 'Orders', 'Spent', 'Last order', 'Customer since']);
        foreach ($rows as $r) {
            $a = $addresses[(int) $r['id']] ?? [];
            fputcsv($fh, [
                $r['id'], $r['name'], $r['phone'], $r['email'], $r['company'], $r['gst_number'],
                Customers::TYPES[$r['customer_type']] ?? $r['customer_type'], Customers::STATUSES[$r['status']] ?? $r['status'],
                Customers::LIFECYCLES[$r['lifecycle']] ?? $r['lifecycle'], (int) $r['is_online'] ? 'Yes' : 'No', (int) $r['marketing_consent'] ? 'Yes' : 'No',
                trim(($a['address_line1'] ?? '') . ' ' . ($a['address_line2'] ?? '')), $a['city'] ?? '', $a['district'] ?? '', $a['state'] ?? '', $a['pincode'] ?? '',
                implode('; ', array_map(static fn($t) => $t['name'], $tags[(int) $r['id']] ?? [])),
                $r['total_orders'], $r['total_spent'], $r['last_order_at'], $r['created_at'],
            ]);
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        return Response::make($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="customers-' . date('Y-m-d') . '.csv"']);
    }
}
