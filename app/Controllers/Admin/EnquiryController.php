<?php

namespace App\Controllers\Admin;

use App\Services\AdminAccess\Guard;
use App\Services\Customer\Phone;
use App\Services\Store\Enquiries;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

/**
 * /admin/enquiries — storefront enquiries inbox (docs/storefront.md).
 * Permissions (config/permissions.php): enquiries.view to see, enquiries.reply to change status /
 * notes, enquiries.assign to set the assignee. Writes go through App\Services\Store\Enquiries.
 */
class EnquiryController
{
    public const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        if (!Guard::can('enquiries.view')) {
            abort(403, 'You don’t have access to enquiries.');
        }
        $status = (string) $request->query('status', 'open');
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 80));
        $page = max(1, (int) $request->query('page', 1));

        $where = [];
        $bind = [];
        if ($status === 'open') {
            $where[] = "e.status IN ('new','contacted','quoted')";
        } elseif (isset(Enquiries::STATUSES[$status])) {
            $where[] = 'e.status = ?';
            $bind[] = $status;
        } else {
            $status = 'all';
        }
        if ($q !== '') {
            // Same portable rule as SegmentMatcher: drop LIKE wildcards instead of an ESCAPE clause (SQLite + MySQL).
            $like = '%' . str_replace(['%', '_'], '', mb_strtolower($q)) . '%';
            $digits = preg_replace('/\D+/', '', $q) ?? '';
            $where[] = '(LOWER(e.name) LIKE ? OR LOWER(e.enquiry_number) LIKE ? OR LOWER(e.message) LIKE ?'
                . (strlen($digits) >= 4 ? ' OR e.phone LIKE ?' : '') . ')';
            array_push($bind, $like, $like, $like);
            if (strlen($digits) >= 4) {
                $bind[] = '%' . $digits . '%';
            }
        }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int) (DB::selectOne('SELECT COUNT(*) AS n FROM enquiries e' . $sqlWhere, $bind)['n'] ?? 0);
        $rows = DB::select('SELECT e.*, p.name AS product_name, p.slug AS product_slug, v.title AS variant_title, v.sku AS variant_sku,
                                   c.name AS category_name, cu.name AS customer_name, cu.total_orders, au.name AS assignee_name, o.order_number
                            FROM enquiries e
                            LEFT JOIN products p ON p.id = e.product_id
                            LEFT JOIN product_variants v ON v.id = e.variant_id AND e.variant_id > 0
                            LEFT JOIN product_categories c ON c.id = e.category_id
                            LEFT JOIN customers cu ON cu.id = e.customer_id
                            LEFT JOIN admin_users au ON au.id = e.assigned_to
                            LEFT JOIN orders o ON o.id = e.converted_order_id'
            . $sqlWhere . ' ORDER BY CASE e.status WHEN \'new\' THEN 0 ELSE 1 END, e.created_at DESC, e.id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $bind);

        $counts = Enquiries::counts();
        $counts['open'] = $counts['new'] + $counts['contacted'] + $counts['quoted'];
        $counts['all'] = array_sum(array_intersect_key($counts, Enquiries::STATUSES));

        return view('admin.enquiries.index', [
            'active' => 'enquiries',
            'rows' => $rows,
            'status' => $status,
            'q' => $q,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'total' => $total,
            'counts' => $counts,
            'staff' => DB::select("SELECT id, name FROM admin_users WHERE status = 'active' ORDER BY name"),
            'open' => (int) $request->query('open', 0),
            'canReply' => Guard::can('enquiries.reply'),
            'canAssign' => Guard::can('enquiries.assign'),
            'canOrder' => Guard::can('orders.accept'),
            'success' => session()->get('success'),
            'error' => session()->get('error'),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $in = [];
        if (Guard::can('enquiries.reply')) {
            foreach (['status', 'internal_note'] as $k) {
                if ($request->input($k) !== null) {
                    $in[$k] = (string) $request->input($k);
                }
            }
        }
        if (Guard::can('enquiries.assign') && $request->input('assigned_to') !== null) {
            $in['assigned_to'] = (string) $request->input('assigned_to');
        }
        $back = route('admin.enquiries') . self::keepQuery((string) $request->input('return', ''));
        if ($in === []) {
            session()->flash('error', 'You don’t have permission to change enquiries.');

            return redirect($back);
        }
        try {
            Enquiries::update((int) $id, $in);
            $n = DB::table('enquiries')->where('id', (int) $id)->value('enquiry_number');
            session()->flash('success', 'Enquiry ' . $n . ' saved.');
        } catch (\InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        }

        return redirect($back);
    }

    /** Keep the inbox filters after saving (only known keys). */
    private static function keepQuery(string $qs): string
    {
        parse_str(ltrim($qs, '?'), $q);
        $keep = array_intersect_key($q, array_flip(['status', 'q', 'page']));
        $keep = array_filter($keep, 'is_string');

        return $keep ? '?' . http_build_query($keep) : '';
    }

    /** wa.me reply link with a short greeting. */
    public static function waLink(array $e): string
    {
        $phone = Phone::normalize((string) $e['phone']);
        if ($phone === null) {
            return '';
        }
        $about = $e['product_name'] ? ' about ' . $e['product_name'] : '';

        return 'https://wa.me/91' . $phone . '?text=' . rawurlencode('Namaskar ' . strtok((string) $e['name'], ' ') . ', this is ' . business('name') . '. Thank you for your enquiry' . $about . ' (' . $e['enquiry_number'] . ').');
    }
}
