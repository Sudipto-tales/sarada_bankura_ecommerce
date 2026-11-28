<?php

namespace App\Services\Admin;

use App\Services\AdminAccess\Guard;
use App\Services\Customer\Phone;
use App\Services\Media\MediaService;
use App\Services\Order\Money;
use Vayu\Database\DB;

/**
 * Admin topbar search: one query → grouped hits across pages, products, orders, customers, enquiries and returns.
 * Used by the live dropdown (GET /admin/search/suggest, few per group) and the full page (GET /admin/search).
 * Portable LIKE rule (SQLite + MySQL): no ESCAPE clause — '%' is stripped, '_' is left as a harmless 1-char wildcard.
 */
final class GlobalSearch
{
    public const MIN = 2;

    /** Admin pages and actions, matched on label + keywords. [label, route, keywords, icon, permission|null] */
    private const PAGES = [
        ['Dashboard', 'admin.dashboard', 'home overview sales', 'layout-dashboard', null],
        ['Products', 'admin.products.index', 'catalogue items list', 'package', null],
        ['Add a product', 'admin.products.create', 'new product create', 'package-plus', null],
        ['Bulk import products', 'admin.products.import', 'upload excel csv import', 'file-up', null],
        ['Variants', 'admin.variants', 'sizes options finish', 'layers', null],
        ['Categories', 'admin.categories', 'category', 'folder-tree', null],
        ['Brands', 'admin.brands', 'brand maker', 'badge-check', null],
        ['Tags', 'admin.tags', 'labels', 'tag', null],
        ['Attributes', 'admin.attributes', 'specs properties', 'list-tree', null],
        ['Units', 'admin.units', 'sqft box piece measure', 'ruler', null],
        ['Reviews', 'admin.reviews', 'ratings feedback', 'star', null],
        ['Media library', 'admin.media', 'images photos files upload', 'image', null],
        ['Orders', 'admin.orders', 'sales bills', 'receipt-text', null],
        ['Create an order', 'admin.orders.create', 'new order bill walk-in', 'circle-plus', null],
        ['Payments', 'admin.payments', 'upi verify refund money', 'wallet', null],
        ['Payment accounts', 'admin.payments.accounts', 'upi bank account qr', 'landmark', null],
        ['Returns', 'admin.returns', 'replacement refund return', 'undo-2', null],
        ['Return policies', 'admin.return-policies', 'return days policy', 'shield-check', null],
        ['Customers', 'admin.customers', 'clients buyers', 'users', null],
        ['Add a customer', 'admin.customers.create', 'new customer create', 'user-plus', null],
        ['Customer segments', 'admin.customers.segments', 'groups segment', 'users-round', null],
        ['Enquiries', 'admin.enquiries', 'leads quotes contact', 'message-square-text', 'enquiries.view'],
        ['Inventory', 'admin.inventory', 'stock warehouse', 'warehouse', null],
        ['Stock levels', 'admin.inventory.stock', 'quantity stock adjust', 'boxes', null],
        ['Stock movements', 'admin.inventory.movements', 'history in out ledger', 'arrow-left-right', null],
        ['Low-stock alerts', 'admin.inventory.alerts', 'low out of stock reorder', 'triangle-alert', null],
        ['Notifications', 'admin.notifications', 'alerts inbox bell', 'bell', null],
        ['WhatsApp settings', 'admin.settings.whatsapp', 'whatsapp messages', 'message-circle', null],
        ['Android app releases', 'admin.app-releases', 'apk app mobile release', 'smartphone', 'app_releases.manage'],
        ['Staff', 'admin.staff', 'team users employees access', 'shield-user', 'staff.manage'],
        ['My account', 'admin.account', 'password profile logout', 'circle-user', null],
    ];

    /** Groups in display order: key => [label, icon]. */
    public const GROUPS = [
        'pages' => ['Pages', 'compass'],
        'products' => ['Products', 'package'],
        'orders' => ['Orders', 'receipt-text'],
        'customers' => ['Customers', 'users'],
        'enquiries' => ['Enquiries', 'message-square-text'],
        'returns' => ['Returns', 'undo-2'],
    ];

    /** @return array{q:string, groups: array<string, array{label:string, icon:string, more:string, items:list<array>}>, total:int} */
    public static function run(string $q, int $per = 5): array
    {
        $q = trim(mb_substr($q, 0, 80));
        $out = ['q' => $q, 'groups' => [], 'total' => 0];
        if (mb_strlen($q) < self::MIN) {
            return $out;
        }
        // '%' is dropped; '_' stays (a one-character wildcard still matches a literal '_' in SKUs like GAL_9).
        $needle = str_replace('%', '', mb_strtolower($q));
        if (mb_strlen(trim($needle, " _")) < self::MIN) {
            return $out;
        }
        $like = '%' . $needle . '%';
        $digits = preg_replace('/\D+/', '', $q) ?? '';
        $phone = strlen($digits) >= 4 ? '%' . (strlen($digits) >= 10 ? (Phone::normalize($digits) ?? $digits) : $digits) . '%' : null;

        $groups = [
            'pages' => self::pages($q, $per),
            'products' => self::products($like, $per),
            'orders' => self::orders($like, $phone, $per),
            'customers' => self::customers($like, $phone, $per),
            'enquiries' => Guard::can('enquiries.view') ? self::enquiries($like, $phone, $per) : [],
            'returns' => self::returns($like, $per),
        ];
        $more = [
            'pages' => '',
            'products' => route('admin.products.index') . '?q=' . rawurlencode($q),
            'orders' => route('admin.orders') . '?q=' . rawurlencode($q),
            'customers' => route('admin.customers') . '?q=' . rawurlencode($q),
            'enquiries' => route('admin.enquiries') . '?status=all&q=' . rawurlencode($q),
            'returns' => route('admin.returns') . '?q=' . rawurlencode($q),
        ];
        foreach ($groups as $k => $items) {
            if ($items) {
                $out['groups'][$k] = ['label' => self::GROUPS[$k][0], 'icon' => self::GROUPS[$k][1], 'more' => $more[$k], 'items' => $items];
                $out['total'] += count($items);
            }
        }

        return $out;
    }

    /** Quick links shown before anything is typed. */
    public static function quickLinks(): array
    {
        $want = ['admin.products.create', 'admin.orders.create', 'admin.customers.create', 'admin.products.import', 'admin.inventory.alerts', 'admin.payments'];
        $out = [];
        foreach (self::PAGES as [$label, $name, , $icon, $perm]) {
            if (in_array($name, $want, true) && ($perm === null || Guard::can($perm))) {
                $out[] = ['title' => $label, 'url' => route($name), 'icon' => $icon];
            }
        }

        return $out;
    }

    private static function pages(string $q, int $per): array
    {
        $words = preg_split('/\s+/', mb_strtolower($q)) ?: [];
        $hits = [];
        foreach (self::PAGES as [$label, $name, $keys, $icon, $perm]) {
            if ($perm !== null && !Guard::can($perm)) {
                continue;
            }
            $hay = mb_strtolower($label . ' ' . $keys);
            $score = 0;
            foreach ($words as $w) {
                if ($w === '' || !str_contains($hay, $w)) {
                    continue 2;
                }
                $score += str_starts_with(mb_strtolower($label), $w) ? 3 : (preg_match('/\b' . preg_quote($w, '/') . '/u', $hay) ? 2 : 1);
            }
            $hits[] = ['score' => $score, 'title' => $label, 'sub' => 'Go to page', 'url' => route($name), 'icon' => $icon];
        }
        usort($hits, static fn($a, $b) => $b['score'] <=> $a['score']);

        return array_map(static function ($h) { unset($h['score']); return $h; }, array_slice($hits, 0, min($per, 4)));
    }

    private static function products(string $like, int $per): array
    {
        $rows = DB::select("SELECT p.id, p.name, p.sku, p.status, pr.price, pr.unit, b.name AS brand_name,
                " . MediaService::coverSql('product', 'p.id') . " AS cover
            FROM products p
            LEFT JOIN product_pricing pr ON pr.product_id = p.id
            LEFT JOIN brands b ON b.id = p.brand_id
            WHERE LOWER(p.name) LIKE ? OR LOWER(p.sku) LIKE ? OR p.id IN (SELECT product_id FROM product_variants WHERE LOWER(sku) LIKE ?)
            ORDER BY CASE WHEN LOWER(p.sku) LIKE ? THEN 0 WHEN p.status = 'archived' THEN 2 ELSE 1 END, p.name
            LIMIT " . (int) $per, [$like, $like, $like, trim($like, '%')]);

        return array_map(static fn($r) => [
            'title' => $r['name'],
            'sub' => implode(' · ', array_filter([$r['sku'], $r['brand_name'], $r['price'] !== null ? Money::inr($r['price'], 0) . ($r['unit'] ? ' / ' . $r['unit'] : '') : null])),
            'badge' => str_replace('_', ' ', (string) $r['status']),
            'tone' => ['active' => 'ok', 'draft' => 'off', 'coming_soon' => 'warn', 'archived' => 'bad'][$r['status']] ?? 'off',
            'thumb' => $r['cover'] ? base_url((string) $r['cover']) : null,
            'url' => route('admin.products.show', ['id' => $r['id']]),
        ], $rows);
    }

    private static function orders(string $like, ?string $phone, int $per): array
    {
        $bind = [$like, $like];
        if ($phone) {
            $bind[] = $phone;
        }
        $rows = DB::select('SELECT id, order_number, customer_name, customer_phone, order_status, grand_total, created_at FROM orders
            WHERE LOWER(order_number) LIKE ? OR LOWER(customer_name) LIKE ?' . ($phone ? ' OR customer_phone LIKE ?' : '') . '
            ORDER BY created_at DESC, id DESC LIMIT ' . (int) $per, $bind);

        return array_map(static fn($r) => [
            'title' => $r['order_number'],
            'sub' => implode(' · ', array_filter([$r['customer_name'], Money::inr($r['grand_total'], 0), $r['created_at'] ? date('j M Y', strtotime((string) $r['created_at'])) : null])),
            'badge' => str_replace('_', ' ', (string) $r['order_status']),
            'tone' => in_array($r['order_status'], ['delivered', 'completed'], true) ? 'ok' : (in_array($r['order_status'], ['cancelled', 'rejected'], true) ? 'bad' : 'warn'),
            'url' => route('admin.orders.show', ['id' => $r['id']]),
        ], $rows);
    }

    private static function customers(string $like, ?string $phone, int $per): array
    {
        $bind = [$like, $like, $like];
        if ($phone) {
            $bind[] = $phone;
        }
        $rows = DB::select('SELECT id, name, phone, email, company, customer_type, total_orders FROM customers
            WHERE merged_into_id IS NULL AND (LOWER(name) LIKE ? OR LOWER(company) LIKE ? OR LOWER(email) LIKE ?' . ($phone ? ' OR phone LIKE ?' : '') . ')
            ORDER BY name LIMIT ' . (int) $per, $bind);

        return array_map(static fn($r) => [
            'title' => $r['name'],
            'sub' => implode(' · ', array_filter([$r['company'], $r['phone'] ? Phone::format((string) $r['phone']) : null, $r['email'], (int) $r['total_orders'] ? $r['total_orders'] . ' orders' : null])),
            'badge' => str_replace('_', ' ', (string) $r['customer_type']),
            'tone' => 'off',
            'initials' => self::initials((string) $r['name']),
            'url' => route('admin.customers.show', ['id' => $r['id']]),
        ], $rows);
    }

    private static function enquiries(string $like, ?string $phone, int $per): array
    {
        $bind = [$like, $like, $like];
        if ($phone) {
            $bind[] = $phone;
        }
        $rows = DB::select('SELECT e.id, e.enquiry_number, e.name, e.phone, e.status, e.created_at, p.name AS product_name FROM enquiries e
            LEFT JOIN products p ON p.id = e.product_id
            WHERE LOWER(e.enquiry_number) LIKE ? OR LOWER(e.name) LIKE ? OR LOWER(e.message) LIKE ?' . ($phone ? ' OR e.phone LIKE ?' : '') . '
            ORDER BY e.created_at DESC, e.id DESC LIMIT ' . (int) $per, $bind);

        return array_map(static fn($r) => [
            'title' => $r['name'] . ' — ' . $r['enquiry_number'],
            'sub' => implode(' · ', array_filter([$r['product_name'], $r['phone'] ? Phone::format((string) $r['phone']) : null, $r['created_at'] ? date('j M Y', strtotime((string) $r['created_at'])) : null])),
            'badge' => (string) $r['status'],
            'tone' => $r['status'] === 'new' ? 'warn' : ($r['status'] === 'closed' ? 'off' : 'ok'),
            'url' => route('admin.enquiries') . '?' . http_build_query(['status' => 'all', 'q' => $r['enquiry_number'], 'open' => $r['id']]),
        ], $rows);
    }

    private static function returns(string $like, int $per): array
    {
        $rows = DB::select('SELECT r.id, r.request_number, r.request_type, r.status, r.created_at, o.order_number, o.customer_name FROM return_requests r
            LEFT JOIN orders o ON o.id = r.order_id
            WHERE LOWER(r.request_number) LIKE ? OR LOWER(o.order_number) LIKE ?
            ORDER BY r.created_at DESC, r.id DESC LIMIT ' . (int) $per, [$like, $like]);

        return array_map(static fn($r) => [
            'title' => $r['request_number'],
            'sub' => implode(' · ', array_filter([ucfirst((string) $r['request_type']), $r['order_number'], $r['customer_name']])),
            'badge' => (string) $r['status'],
            'tone' => $r['status'] === 'resolved' ? 'ok' : ($r['status'] === 'rejected' ? 'bad' : 'warn'),
            'url' => route('admin.returns.show', ['id' => $r['id']]),
        ], $rows);
    }

    private static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $s = '';
        foreach (array_slice($parts, 0, 2) as $p) {
            $s .= mb_strtoupper(mb_substr($p, 0, 1));
        }

        return $s ?: '?';
    }
}
