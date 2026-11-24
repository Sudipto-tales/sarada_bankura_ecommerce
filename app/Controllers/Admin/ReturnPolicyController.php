<?php

namespace App\Controllers\Admin;

use App\Services\Media\MediaService;
use App\Services\Order\ReturnException;
use App\Services\Order\ReturnService as Returns;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

/** Product return policies (Stage 6). New orders copy the policy onto each line; changing it never affects past orders. */
class ReturnPolicyController
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $f = ['q' => trim((string) $request->query('q', '')), 'show' => (string) $request->query('show', '')];
        $where = ["p.status <> 'archived'"];
        $bind = [];
        if ($f['q'] !== '') {
            $where[] = '(LOWER(p.name) LIKE ? OR LOWER(p.sku) LIKE ?)';
            $q = '%' . mb_strtolower($f['q']) . '%';
            array_push($bind, $q, $q);
        }
        $where[] = match ($f['show']) {
            'return' => 'rp.is_active = 1 AND rp.return_allowed = 1',
            'replacement' => 'rp.is_active = 1 AND rp.replacement_allowed = 1',
            'none' => '(rp.id IS NULL OR rp.is_active = 0 OR (rp.return_allowed = 0 AND rp.replacement_allowed = 0))',
            default => '1 = 1',
        };
        $from = 'FROM products p LEFT JOIN product_return_policies rp ON rp.product_id = p.id AND rp.variant_id = 0 WHERE ' . implode(' AND ', $where);
        $total = (int) (DB::selectOne("SELECT COUNT(*) AS n $from", $bind)['n'] ?? 0);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) $request->query('page', 1)));
        $rows = DB::select('SELECT p.id, p.name, p.sku, p.status, rp.return_allowed, rp.return_days, rp.replacement_allowed, rp.replacement_days, rp.return_conditions, rp.is_active, rp.updated_at,
                ' . MediaService::coverSql('product', 'p.id') . " AS cover $from ORDER BY p.name LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $bind);
        $stats = DB::selectOne("SELECT COUNT(*) AS products,
                SUM(CASE WHEN rp.is_active = 1 AND rp.return_allowed = 1 THEN 1 ELSE 0 END) AS returnable,
                SUM(CASE WHEN rp.is_active = 1 AND rp.replacement_allowed = 1 THEN 1 ELSE 0 END) AS replaceable,
                SUM(CASE WHEN rp.is_active = 1 AND (rp.return_allowed = 1 OR rp.replacement_allowed = 1) THEN 1 ELSE 0 END) AS anyok
            FROM products p LEFT JOIN product_return_policies rp ON rp.product_id = p.id AND rp.variant_id = 0 WHERE p.status <> 'archived'") ?? [];

        return view('admin.returns.policies', compact('rows', 'f', 'page', 'pages', 'total', 'stats'));
    }

    /** One product (edit) or many (bulk "apply to selected"). */
    public function save(Request $request): Response
    {
        $ids = array_map('intval', (array) $request->input('product_ids', []));
        try {
            $n = Returns::savePolicy($ids, $request->input());
            session()->flash('success', $n === 1 ? 'Return policy saved. New orders use it; past orders keep the policy they were placed with.'
                : 'Policy applied to ' . $n . ' products. New orders use it; past orders keep theirs.');
        } catch (ReturnException $e) {
            session()->flash('error', $e->getMessage());
        }

        return redirect((string) ($request->header('Referer') ?: route('admin.return-policies')));
    }
}
