<?php

namespace App\Controllers\Admin;

use Vayu\Database\DB;
use Vayu\Http\Response;

class DashboardController
{
    public function index(): Response
    {
        $byStatus = [];
        foreach (DB::select('SELECT status, COUNT(*) AS n FROM products GROUP BY status') as $r) {
            $byStatus[$r['status']] = (int) $r['n'];
        }

        $count = static fn(string $sql, array $bind = []): int => (int) (DB::select($sql, $bind)[0]['n'] ?? 0);
        $total = (int) DB::table('products')->count();

        // Date boundaries are computed in PHP (app timezone = IST) and bound as params, so the
        // SQL is portable across SQLite and MySQL (no datetime('now') / julianday / NOW()).
        $today = new \DateTimeImmutable('today');
        $now = new \DateTimeImmutable('now');
        $ago30 = $now->modify('-30 days')->format('Y-m-d H:i:s');
        $ago60 = $now->modify('-60 days')->format('Y-m-d H:i:s');

        $stats = [
            'products' => $total,
            'active' => $byStatus['active'] ?? 0,
            'draft' => $byStatus['draft'] ?? 0,
            'coming_soon' => $byStatus['coming_soon'] ?? 0,
            'archived' => $byStatus['archived'] ?? 0,
            'featured' => (int) DB::table('products')->where('is_featured', 1)->count(),
            'bestseller' => (int) DB::table('products')->where('is_bestseller', 1)->count(),
            'enquiry' => (int) DB::table('products')->where('is_enquiry_only', 1)->count(),
            'brands' => (int) DB::table('brands')->count(),
            'categories' => (int) DB::table('product_categories')->count(),
            'tags' => (int) DB::table('tags')->count(),
            'types' => (int) DB::table('product_types')->count(),
            'media' => (int) DB::table('media')->count(),
            'added30' => $count('SELECT COUNT(*) AS n FROM products WHERE created_at >= ?', [$ago30]),
            'addedPrev30' => $count('SELECT COUNT(*) AS n FROM products WHERE created_at < ? AND created_at >= ?', [$ago30, $ago60]),
        ];

        // Readiness: share of products that have each piece filled in.
        $coverage = [
            'media' => $count("SELECT COUNT(DISTINCT owner_id) AS n FROM media_links WHERE owner_type = 'product'"),
            'pricing' => $count('SELECT COUNT(DISTINCT product_id) AS n FROM product_pricing WHERE price IS NOT NULL'),
            'category' => $count('SELECT COUNT(DISTINCT product_id) AS n FROM product_category_map'),
            'seo' => $count("SELECT COUNT(*) AS n FROM products WHERE COALESCE(meta_title,'') <> '' AND COALESCE(meta_description,'') <> ''"),
        ];

        // Products added per day: this 30-day window vs the previous one.
        $daily = ['this' => array_fill(0, 30, 0), 'last' => array_fill(0, 30, 0)];
        // Group by calendar day (DATE() works in both engines); days with no rows stay 0.
        $rows = DB::select(
            'SELECT DATE(created_at) AS d, COUNT(*) AS n FROM products
             WHERE created_at >= ? GROUP BY DATE(created_at)',
            [$today->modify('-59 days')->format('Y-m-d 00:00:00')]
        );
        foreach ($rows as $r) {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $r['d']);
            if (!$day) {
                continue;
            }
            $ago = (int) $day->diff($today)->format('%r%a');
            if ($ago >= 0 && $ago < 30) {
                $daily['this'][29 - $ago] = (int) $r['n'];
            } elseif ($ago >= 30 && $ago < 60) {
                $daily['last'][59 - $ago] = (int) $r['n'];
            }
        }
        $labels = [];
        for ($i = 29; $i >= 0; $i--) {
            $labels[] = $today->modify("-{$i} days")->format('M j');
        }
        $daily['labels'] = $labels;

        $byCategory = DB::select(
            'SELECT c.name, COUNT(m.product_id) AS n FROM product_categories c
             LEFT JOIN product_category_map m ON m.category_id = c.id
             GROUP BY c.id ORDER BY n DESC, c.name LIMIT 4'
        );

        // Inventory snapshot for the dashboard stock card.
        $stock = ['tracked' => 0, 'healthy' => 0, 'low' => 0, 'out' => 0];
        foreach (DB::select('SELECT status, COUNT(*) AS n FROM inventory_stock GROUP BY status') as $r) {
            $stock['tracked'] += (int) $r['n'];
            if (isset($stock[$r['status']])) {
                $stock[$r['status']] = (int) $r['n'];
            }
        }
        $stock['untracked'] = max(0, $total - $stock['tracked']);
        $stockAttention = DB::select(
            "SELECT p.id, p.name, s.quantity, s.unit, s.status
             FROM inventory_stock s JOIN products p ON p.id = s.product_id
             WHERE s.status IN ('out','low')
             ORDER BY CASE s.status WHEN 'out' THEN 0 ELSE 1 END, s.quantity ASC
             LIMIT 5"
        );

        $recent = DB::select(
            'SELECT p.id, p.name, p.sku, p.status, p.created_at, p.updated_at, pt.name AS type_name, b.name AS brand_name,
                    ' . \App\Services\Media\MediaService::coverSql('product', 'p.id') . ' AS cover,
                    (SELECT price FROM product_pricing pp WHERE pp.product_id = p.id LIMIT 1) AS price,
                    (SELECT unit FROM product_pricing pp WHERE pp.product_id = p.id LIMIT 1) AS unit
             FROM products p
             LEFT JOIN product_types pt ON pt.id = p.product_type_id
             LEFT JOIN brands b ON b.id = p.brand_id
             ORDER BY p.id DESC LIMIT 6'
        );

        return view('admin.dashboard', [
            'stats' => $stats,
            'recent' => $recent,
            'byStatus' => $byStatus,
            'coverage' => $coverage,
            'daily' => $daily,
            'byCategory' => $byCategory,
            'stock' => $stock,
            'stockAttention' => $stockAttention,
        ]);
    }
}
