<?php

namespace App\Controllers\Admin;

use App\Services\Inventory\AlertService;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\InventoryService as Inv;
use App\Services\Inventory\InventorySettings;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

/**
 * Inventory admin pages. Phase 2: Stock management (register table + receive / adjust /
 * start-tracking / opening-stock / threshold / stop-tracking actions + history JSON).
 * All stock writes go through App\Services\Inventory\InventoryService.
 */
class InventoryController
{
    public function stock(Request $request): Response
    {
        $rows = DB::select(
            "SELECT p.id, p.sku, p.name, p.status AS product_status,
                    s.id AS stock_id, s.unit, s.quantity, s.low_stock_threshold, s.status, s.last_movement_at,
                    pr.unit AS selling_unit, pr.pack_qty, pr.pack_unit,
                    (SELECT path FROM product_media m WHERE m.product_id = p.id AND m.is_cover = 1 LIMIT 1) AS cover,
                    (SELECT c.name FROM product_category_map cm JOIN product_categories c ON c.id = cm.category_id
                      WHERE cm.product_id = p.id ORDER BY cm.is_primary DESC LIMIT 1) AS category,
                    (SELECT cm.category_id FROM product_category_map cm WHERE cm.product_id = p.id ORDER BY cm.is_primary DESC LIMIT 1) AS category_id
             FROM products p
             LEFT JOIN inventory_stock s ON s.product_id = p.id
             LEFT JOIN product_pricing pr ON pr.product_id = p.id
             ORDER BY CASE s.status WHEN 'out' THEN 0 WHEN 'low' THEN 1 ELSE 2 END, p.name"
        );

        $default = InventorySettings::defaultThreshold();
        $tracked = [];
        $untracked = [];
        foreach ($rows as $r) {
            if ($r['stock_id'] === null) {
                $untracked[] = $r;
                continue;
            }
            $r['threshold'] = $r['low_stock_threshold'] !== null ? (float) $r['low_stock_threshold'] : $default;
            $r['units'] = Inv::enterableUnits((int) $r['id']);
            $r['factors'] = [];
            foreach ($r['units'] as $u) {
                $r['factors'][$u] = Inv::conversionFactor((int) $r['id'], $u, (string) $r['unit']);
            }
            $tracked[] = $r;
        }

        $count = static fn(string $s) => count(array_filter($tracked, static fn($r) => $r['status'] === $s));
        $byUnit = [];
        foreach ($tracked as $r) {
            $byUnit[$r['unit']] = ($byUnit[$r['unit']] ?? 0) + (float) $r['quantity'];
        }
        arsort($byUnit);

        $categories = [];
        foreach ($tracked as $r) {
            if ($r['category_id']) {
                $categories[(int) $r['category_id']] = $r['category'];
            }
        }
        asort($categories);

        return view('admin.inventory.stock', [
            'active' => 'inventory.stock',
            'tracked' => $tracked,
            'untracked' => $untracked,
            'units' => DB::table('units')->orderBy('sort')->pluck('symbol'),
            'categories' => $categories,
            'stats' => [
                'tracked' => count($tracked),
                'products' => count($rows),
                'healthy' => $count(AlertService::HEALTHY),
                'low' => $count(AlertService::LOW),
                'out' => $count(AlertService::OUT),
                'by_unit' => $byUnit,
            ],
            'defaultThreshold' => $default,
            'enabled' => InventorySettings::enabled(),
            'reasons' => Inv::ADJUST_REASONS,
            'focus' => (int) $request->query('product', 0),
            'statusFilter' => (string) $request->query('status', ''),
            'success' => session()->get('success'),
            'error' => session()->get('error'),
        ]);
    }

    public function track(Request $request): Response
    {
        $pid = (int) $request->input('product_id');

        return $this->attempt($pid, function () use ($request, $pid): string {
            $row = Inv::startTracking(
                $pid,
                (string) $request->input('unit'),
                $this->number($request->input('quantity'), 'Opening quantity', true) ?? 0.0,
                $this->number($request->input('threshold'), 'Low-stock threshold'),
                ['source' => 'opening'] + $this->meta($request),
            );

            return 'Now tracking ' . $this->name($pid) . ' — opening stock ' . Inv::qty((float) $row['quantity']) . ' ' . $row['unit'] . '.';
        });
    }

    /** Opening stock setup: start tracking several products at once. All or nothing. */
    public function opening(Request $request): Response
    {
        $input = $request->input('rows');
        $input = is_array($input) ? $input : [];
        $errors = [];
        $done = 0;

        try {
            DB::transaction(function () use ($input, $request, &$errors, &$done): void {
                foreach ($input as $pid => $r) {
                    if (!is_array($r) || empty($r['on'])) {
                        continue;
                    }
                    $pid = (int) $pid;
                    try {
                        Inv::startTracking(
                            $pid,
                            (string) ($r['unit'] ?? ''),
                            $this->number($r['quantity'] ?? '', 'Opening quantity', true) ?? 0.0,
                            $this->number($r['threshold'] ?? '', 'Low-stock threshold'),
                            ['notes' => 'Opening stock setup', 'source' => 'opening'] + $this->meta($request),
                        );
                        $done++;
                    } catch (InventoryException $e) {
                        $errors[] = $this->name($pid) . ': ' . $e->getMessage();
                    }
                }
                if ($errors) {
                    throw new InventoryException('rollback');
                }
            });
        } catch (InventoryException) {
            session()->flash('error', 'Nothing was saved. Fix these and try again — ' . implode(' · ', $errors));

            return redirect(route('admin.inventory.stock'));
        }

        session()->flash($done ? 'success' : 'error', $done
            ? 'Opening stock saved for ' . $done . ' product' . ($done === 1 ? '' : 's') . '.'
            : 'Tick at least one product to set its opening stock.');

        return redirect(route('admin.inventory.stock'));
    }

    public function receive(Request $request, string $id): Response
    {
        $pid = (int) $id;

        return $this->attempt($pid, function () use ($request, $pid): string {
            $qty = $this->number($request->input('quantity'), 'Received quantity');
            if ($qty === null) {
                throw new InventoryException('Enter the received quantity.');
            }
            $m = Inv::receive($pid, $qty, $this->meta($request) + ['entered_unit' => (string) $request->input('entered_unit')]);
            $unit = Inv::stockFor($pid)['unit'];

            return 'Received ' . Inv::qty((float) $m['qty_change']) . ' ' . $unit . ' of ' . $this->name($pid)
                . ' — stock is now ' . Inv::qty((float) $m['qty_after']) . ' ' . $unit . '.';
        });
    }

    public function adjust(Request $request, string $id): Response
    {
        $pid = (int) $id;

        return $this->attempt($pid, function () use ($request, $pid): string {
            $reason = (string) $request->input('reason');
            if ($reason === 'other' && trim((string) $request->input('reason_other')) !== '') {
                $reason = 'Other: ' . trim((string) $request->input('reason_other'));
            }
            $meta = $this->meta($request);
            if ($request->input('mode') === 'delta') {
                $delta = $this->number($request->input('delta'), 'Quantity', true, allowNegative: true);
                if ($delta === null) {
                    throw new InventoryException('Enter how much to add or remove.');
                }
                $m = Inv::adjustBy($pid, $delta, $reason, $meta);
            } else {
                $count = $this->number($request->input('counted'), 'Physical count', true);
                if ($count === null) {
                    throw new InventoryException('Enter the physical count.');
                }
                $m = Inv::adjustTo($pid, $count, $reason, $meta);
            }
            $unit = Inv::stockFor($pid)['unit'];
            $change = (float) $m['qty_change'];

            return 'Adjusted ' . $this->name($pid) . ' by ' . ($change > 0 ? '+' : '−') . Inv::qty(abs($change)) . ' ' . $unit
                . ' — stock is now ' . Inv::qty((float) $m['qty_after']) . ' ' . $unit . '.';
        });
    }

    public function threshold(Request $request, string $id): Response
    {
        $pid = (int) $id;

        return $this->attempt($pid, function () use ($request, $pid): string {
            $value = $this->number($request->input('threshold'), 'Low-stock threshold');
            $row = Inv::setThreshold($pid, $value);

            return 'Low-stock threshold for ' . $this->name($pid) . ' set to '
                . ($value === null ? 'the default (' . Inv::qty(InventorySettings::defaultThreshold()) . ')' : Inv::qty($value) . ' ' . $row['unit']) . '.';
        });
    }

    public function stop(Request $request, string $id): Response
    {
        $pid = (int) $id;

        return $this->attempt($pid, function () use ($pid): string {
            Inv::stopTracking($pid);

            return 'Stopped tracking ' . $this->name($pid) . '. Its movement history is kept.';
        }, focus: false);
    }

    /** Recent movements for the history drawer. */
    public function history(Request $request, string $id): Response
    {
        $pid = (int) $id;
        $rows = DB::table('inventory_movements')->where('product_id', $pid)->orderBy('id', 'desc')->limit(30)->get();
        $stock = Inv::stockFor($pid);

        return json([
            'product' => ['id' => $pid, 'name' => $this->name($pid), 'unit' => $stock['unit'] ?? null, 'quantity' => $stock ? (float) $stock['quantity'] : null],
            'movements' => array_map(static fn(array $m) => [
                'date' => date('d M Y, H:i', strtotime((string) $m['created_at'])),
                'type' => $m['type'],
                'label' => Inv::TYPES[$m['type']] ?? $m['type'],
                'change' => (float) $m['qty_change'],
                'before' => (float) $m['qty_before'],
                'after' => (float) $m['qty_after'],
                'entered' => $m['entered_unit'] && (float) $m['conversion_factor'] != 1.0
                    ? Inv::qty((float) $m['entered_qty']) . ' ' . $m['entered_unit'] . ' × ' . rtrim(rtrim(number_format((float) $m['conversion_factor'], 6, '.', ''), '0'), '.')
                    : null,
                'reason' => $m['reason'],
                'reference' => $m['reference_no'],
                'notes' => $m['notes'],
                'source' => $m['source'],
            ], $rows),
        ]);
    }

    /* ================================================================
     | Phase 5 — overview, movements, alerts, settings
     | ================================================================ */

    /** Stock overview: KPIs, needs-attention list, totals by unit / category, recent movements. */
    public function overview(Request $request): Response
    {
        if ($request->query('export') === 'csv') {
            return $this->exportStock();
        }
        $rows = DB::select(
            "SELECT p.id, p.sku, p.name, s.unit, s.quantity, s.low_stock_threshold, s.status, s.last_movement_at,
                    (SELECT path FROM product_media m WHERE m.product_id = p.id AND m.is_cover = 1 LIMIT 1) AS cover,
                    (SELECT c.name FROM product_category_map cm JOIN product_categories c ON c.id = cm.category_id
                      WHERE cm.product_id = p.id ORDER BY cm.is_primary DESC LIMIT 1) AS category
             FROM inventory_stock s JOIN products p ON p.id = s.product_id
             ORDER BY p.name"
        );
        $default = InventorySettings::defaultThreshold();
        $counts = ['healthy' => 0, 'low' => 0, 'out' => 0];
        $byUnit = [];
        $byCategory = [];
        $attention = [];
        foreach ($rows as &$r) {
            $r['threshold'] = $r['low_stock_threshold'] !== null ? (float) $r['low_stock_threshold'] : $default;
            $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
            $u = (string) $r['unit'];
            $byUnit[$u] ??= ['qty' => 0.0, 'products' => 0];
            $byUnit[$u]['qty'] += (float) $r['quantity'];
            $byUnit[$u]['products']++;
            $c = (string) ($r['category'] ?: 'Uncategorised');
            $byCategory[$c] ??= ['products' => 0, 'low' => 0, 'out' => 0];
            $byCategory[$c]['products']++;
            if ($r['status'] !== AlertService::HEALTHY) {
                $byCategory[$c][$r['status']]++;
                $attention[] = $r;
            }
        }
        unset($r);
        // Out first, then the lowest share of threshold.
        usort($attention, static fn($a, $b) => [$a['status'] === 'out' ? 0 : 1, $a['threshold'] > 0 ? $a['quantity'] / $a['threshold'] : 0]
            <=> [$b['status'] === 'out' ? 0 : 1, $b['threshold'] > 0 ? $b['quantity'] / $b['threshold'] : 0]);
        uasort($byUnit, static fn($a, $b) => $b['products'] <=> $a['products']);
        uasort($byCategory, static fn($a, $b) => ($b['out'] * 100 + $b['low'] * 10 + $b['products']) <=> ($a['out'] * 100 + $a['low'] * 10 + $a['products']));

        $since = date('Y-m-d H:i:s', strtotime('-30 days'));
        $flow = DB::select(
            "SELECT s.unit,
                    SUM(CASE WHEN m.qty_change > 0 AND m.type IN ('stock_in') THEN m.qty_change ELSE 0 END) AS received,
                    SUM(CASE WHEN m.qty_change < 0 THEN -m.qty_change ELSE 0 END) AS removed,
                    COUNT(*) AS moves
             FROM inventory_movements m JOIN inventory_stock s ON s.id = m.stock_id
             WHERE m.created_at >= ? AND m.type != 'opening' GROUP BY s.unit ORDER BY moves DESC",
            [$since]
        );

        return view('admin.inventory.overview', [
            'active' => 'inventory.overview',
            'tracked' => count($rows),
            'products' => DB::table('products')->count(),
            'counts' => $counts,
            'byUnit' => $byUnit,
            'byCategory' => array_slice($byCategory, 0, 6, true),
            'attention' => array_slice($attention, 0, 8),
            'attentionTotal' => count($attention),
            'flow' => $flow,
            'recent' => $this->movementQuery([])->limit(8)->get(),
            'activeAlerts' => DB::table('inventory_alerts')->where('status', 'active')->count(),
            'enabled' => InventorySettings::enabled(),
        ]);
    }

    public function movements(Request $request): Response
    {
        $f = [
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
            'product' => (int) $request->query('product', 0),
            'type' => array_key_exists((string) $request->query('type'), Inv::TYPES) ? (string) $request->query('type') : '',
            'source' => in_array($request->query('source'), Inv::SOURCES, true) ? (string) $request->query('source') : '',
        ];
        if ($request->query('export') === 'csv') {
            $out = [['date', 'sku', 'product', 'movement', 'change', 'before', 'after', 'unit', 'entered', 'reason', 'reference', 'notes', 'source', 'by']];
            foreach ($this->movementQuery($f)->limit(20000)->get() as $m) {
                $out[] = [$m['created_at'], $m['sku'], $m['name'], Inv::TYPES[$m['type']] ?? $m['type'], Inv::qty((float) $m['qty_change']), Inv::qty((float) $m['qty_before']),
                    Inv::qty((float) $m['qty_after']), $m['unit'], $this->entered($m), $m['reason'], $m['reference_no'], $m['notes'], $m['source'], 'Admin'];
            }

            return $this->csv('stock-movements-' . date('Ymd-His') . '.csv', $out);
        }

        $per = 50;
        $page = max(1, (int) $request->query('page', 1));
        $total = $this->movementQuery($f)->count();
        $rows = $this->movementQuery($f)->limit($per)->offset(($page - 1) * $per)->get();
        foreach ($rows as &$m) {
            $m['entered'] = $this->entered($m);
        }
        unset($m);

        return view('admin.inventory.movements', [
            'active' => 'inventory.movements',
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $per)),
            'filters' => $f,
            'productsList' => DB::select('SELECT DISTINCT p.id, p.name, p.sku FROM inventory_movements m JOIN products p ON p.id = m.product_id ORDER BY p.name'),
            'types' => Inv::TYPES,
            'sources' => Inv::SOURCES,
        ]);
    }

    public function alerts(Request $request): Response
    {
        $tab = in_array($request->query('tab'), ['active', 'resolved', 'all'], true) ? (string) $request->query('tab') : 'active';
        $q = DB::table('inventory_alerts')
            ->leftJoin('products', 'products.id', '=', 'inventory_alerts.product_id')
            ->leftJoin('inventory_stock', 'inventory_stock.id', '=', 'inventory_alerts.stock_id')
            ->select(['inventory_alerts.*', 'products.name', 'products.sku', 'inventory_stock.quantity AS current_qty', 'inventory_stock.unit',
                'inventory_stock.low_stock_threshold', 'inventory_stock.id AS live_stock']);
        if ($tab !== 'all') {
            $q->where('inventory_alerts.status', $tab);
        }
        $rows = $q->orderBy($tab === 'resolved' ? 'inventory_alerts.resolved_at' : 'inventory_alerts.triggered_at', 'desc')->limit(200)->get();
        $default = InventorySettings::defaultThreshold();
        foreach ($rows as &$a) {
            $a['threshold_now'] = $a['live_stock'] ? ($a['low_stock_threshold'] !== null ? (float) $a['low_stock_threshold'] : $default) : null;
        }
        unset($a);

        $count = static fn(string $status, ?string $type = null) => (function () use ($status, $type) {
            $q = DB::table('inventory_alerts')->where('status', $status);
            if ($type) {
                $q->where('type', $type);
            }

            return $q->count();
        })();

        return view('admin.inventory.alerts', [
            'active' => 'inventory.alerts',
            'rows' => $rows,
            'tab' => $tab,
            'stats' => [
                'active' => $count('active'),
                'out' => $count('active', 'out'),
                'low' => $count('active', 'low'),
                'resolved30' => DB::table('inventory_alerts')->where('status', 'resolved')->where('resolved_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')))->count(),
            ],
            'enabled' => InventorySettings::enabled(),
            'settings' => InventorySettings::all(),
            'lastCheck' => $this->lastCheck(),
            'success' => session()->get('success'),
            'error' => session()->get('error'),
        ]);
    }

    /** "Run check now": re-evaluate every tracked product (same as the scheduled check). */
    public function check(Request $request): Response
    {
        $r = \App\Services\Inventory\InventoryCheck::make()->run(false, 'manual');
        \App\Services\Inventory\InventoryCheck::log(\App\Services\Inventory\InventoryCheck::logLine($r, 'manual'));
        if ($r['status'] === 'locked') {
            session()->flash('error', 'A check is already running — try again in a moment.');
        } elseif ($r['status'] === 'failed') {
            session()->flash('error', 'The check failed: ' . ($r['error'] ?? 'unknown error'));
        } else {
            session()->flash('success', ($r['enabled'] ? 'Checked ' . $r['checked'] . ' product' . ($r['checked'] === 1 ? '' : 's') . ': '
                . $r['opened'] . ' new alert' . ($r['opened'] === 1 ? '' : 's') . ', ' . $r['resolved'] . ' resolved.' : 'Alerts are switched off, so no alerts were checked.')
                . ($r['pruned'] ? ' Removed ' . $r['pruned'] . ' old read notification' . ($r['pruned'] === 1 ? '' : 's') . '.' : ''));
        }

        return redirect(route('admin.inventory.alerts'));
    }

    public function saveSettings(Request $request): Response
    {
        try {
            $th = $this->number($request->input('default_threshold'), 'Default low-stock threshold');
            if ($th === null) {
                throw new InventoryException('Enter a default low-stock threshold.');
            }
            $days = (int) $request->input('retention_days', 90);
            if ($days < 7 || $days > 3650) {
                throw new InventoryException('Keep read notifications for between 7 and 3650 days.');
            }
            InventorySettings::set('default_threshold', (string) $th);
            InventorySettings::set('retention_days', (string) $days);
            foreach (['is_enabled', 'notify_low', 'notify_out', 'notify_recovered', 'notify_import'] as $k) {
                InventorySettings::set($k, $request->input($k) ? '1' : '0');
            }
            // A new default threshold or switching alerts on changes alert state straight away.
            $r = AlertService::evaluateAll();
            session()->flash('success', 'Inventory settings saved.' . ($r['opened'] || $r['resolved'] ? ' Alerts updated: ' . $r['opened'] . ' opened, ' . $r['resolved'] . ' resolved.' : ''));
        } catch (InventoryException $e) {
            session()->flash('error', $e->getMessage());
        }

        return redirect(route('admin.inventory.alerts') . '#settings');
    }

    /** @return array{at:?string, source:string, summary:array<string,mixed>, ago:?int, cronAt:?string, cronStale:bool} */
    private function lastCheck(): array
    {
        $at = InventorySettings::get('last_check_at') ?: null;
        $cronAt = null;
        // Has cron ever run? Look for a cron line in the log (the button also records last_check_at).
        $log = app()->storagePath('logs/inventory.log');
        if (is_file($log)) {
            $tail = (string) @file_get_contents($log, false, null, max(0, filesize($log) - 20000));
            if (preg_match_all('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d) \[cron\] (ok|failed)/m', $tail, $m)) {
                $cronAt = end($m[1]);
            }
        }

        return [
            'at' => $at,
            'source' => InventorySettings::get('last_check_source') ?: 'cron',
            'summary' => json_decode(InventorySettings::get('last_check_summary') ?: '[]', true) ?: [],
            'ago' => $at ? time() - (int) strtotime($at) : null,
            'cronAt' => $cronAt,
            'cronStale' => !$cronAt || time() - (int) strtotime($cronAt) > 3600,
        ];
    }

    /** @param array{from?:string,to?:string,product?:int,type?:string,source?:string} $f */
    private function movementQuery(array $f): \Vayu\Database\QueryBuilder
    {
        $q = DB::table('inventory_movements')
            ->leftJoin('products', 'products.id', '=', 'inventory_movements.product_id')
            ->leftJoin('inventory_stock', 'inventory_stock.product_id', '=', 'inventory_movements.product_id')
            ->select(['inventory_movements.*', 'products.name', 'products.sku', 'COALESCE(inventory_movements.unit, inventory_stock.unit) AS unit']);
        if (!empty($f['from']) && strtotime($f['from'])) {
            $q->where('inventory_movements.created_at', '>=', date('Y-m-d 00:00:00', strtotime($f['from'])));
        }
        if (!empty($f['to']) && strtotime($f['to'])) {
            $q->where('inventory_movements.created_at', '<=', date('Y-m-d 23:59:59', strtotime($f['to'])));
        }
        if (!empty($f['product'])) {
            $q->where('inventory_movements.product_id', (int) $f['product']);
        }
        if (!empty($f['type'])) {
            $q->where('inventory_movements.type', $f['type']);
        }
        if (!empty($f['source'])) {
            $q->where('inventory_movements.source', $f['source']);
        }

        return $q->orderBy('inventory_movements.created_at', 'desc')->orderBy('inventory_movements.id', 'desc');
    }

    private function entered(array $m): ?string
    {
        if (!$m['entered_unit'] || $m['conversion_factor'] === null || (float) $m['conversion_factor'] == 1.0) {
            return null;
        }

        return Inv::qty((float) $m['entered_qty']) . ' ' . $m['entered_unit'] . ' × ' . rtrim(rtrim(number_format((float) $m['conversion_factor'], 6, '.', ''), '0'), '.');
    }

    private function exportStock(): Response
    {
        $default = InventorySettings::defaultThreshold();
        $out = [['sku', 'product', 'quantity', 'unit', 'low_stock_threshold', 'status', 'last_movement']];
        foreach (DB::select('SELECT p.sku, p.name, s.* FROM inventory_stock s JOIN products p ON p.id = s.product_id ORDER BY p.name') as $r) {
            $out[] = [$r['sku'], $r['name'], Inv::qty((float) $r['quantity']), $r['unit'],
                Inv::qty($r['low_stock_threshold'] !== null ? (float) $r['low_stock_threshold'] : $default), $r['status'], $r['last_movement_at']];
        }

        return $this->csv('inventory-' . date('Ymd-His') . '.csv', $out);
    }

    /** @param list<list<mixed>> $rows */
    private function csv(string $filename, array $rows): Response
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        foreach ($rows as $r) {
            fputcsv($fh, array_map(static fn($v) => $v === null ? '' : (string) $v, $r), ',', '"', '');
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        return Response::make($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="' . $filename . '"']);
    }

    /* ---------------------------------------------------------------- */

    /** Run a stock action; flash its message or the InventoryException text, then go back to the register. */
    private function attempt(int $pid, \Closure $action, bool $focus = true): Response
    {
        try {
            session()->flash('success', $action());
        } catch (InventoryException $e) {
            session()->flash('error', $e->getMessage());
        }

        return redirect(route('admin.inventory.stock') . ($focus && $pid ? '?product=' . $pid : ''));
    }

    /**
     * Parse a typed quantity ("1,250.5" ok). Blank → null.
     */
    private function number(mixed $raw, string $label, bool $allowZero = true, bool $allowNegative = false): ?float
    {
        $s = trim(str_replace([',', ' '], '', (string) $raw));
        if ($s === '') {
            return null;
        }
        if (!is_numeric($s)) {
            throw new InventoryException($label . ' must be a number.');
        }
        $v = (float) $s;
        if (!$allowNegative && $v < 0) {
            throw new InventoryException($label . ' cannot be negative.');
        }
        if (!$allowZero && $v == 0.0) {
            throw new InventoryException($label . ' must be greater than zero.');
        }
        if (abs($v - round($v, 3)) > 1e-9) {
            throw new InventoryException($label . ' can have at most 3 decimal places.');
        }

        return $v;
    }

    /** @return array<string,mixed> */
    private function meta(Request $request): array
    {
        return [
            'reference_no' => (string) $request->input('reference_no', ''),
            'notes' => (string) $request->input('notes', ''),
            'source' => 'manual',
            'created_by' => null, // no admin auth yet
        ];
    }

    private function name(int $pid): string
    {
        return (string) (DB::table('products')->where('id', $pid)->value('name') ?? 'Product #' . $pid);
    }
}
