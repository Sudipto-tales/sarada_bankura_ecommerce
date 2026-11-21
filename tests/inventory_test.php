<?php

use App\Services\Inventory\AlertService;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\InventoryService as Inv;
use App\Services\Inventory\InventorySettings;
use App\Services\NotificationService as Notes;
use Vayu\Application;
use Vayu\Database\DB;
use Vayu\Database\Schema\Blueprint;
use Vayu\Database\Schema\Schema;

/** Inventory phase 1: stock service, alert transitions, notifications. Runs on an in-memory DB. */
return function (Assert $assert): void {
    $app = new Application(dirname(__DIR__));
    $app->config()->set('database.default', 'sqlite');
    $app->config()->set('database.connections.sqlite.database', ':memory:');

    // Minimal catalogue tables the services read.
    Schema::create('products', function (Blueprint $t): void {
        $t->id();
        $t->string('sku');
        $t->string('name');
    });
    Schema::create('product_pricing', function (Blueprint $t): void {
        $t->id();
        $t->bigInteger('product_id');
        $t->string('unit')->nullable();
        $t->decimal('pack_qty', 12, 3)->nullable();
        $t->string('pack_unit')->nullable();
    });
    Schema::create('units', function (Blueprint $t): void {
        $t->id();
        $t->string('symbol');
    });
    foreach (['sq ft', 'box', 'piece'] as $u) {
        DB::table('units')->insert(['symbol' => $u]);
    }
    (require dirname(__DIR__) . '/database/migrations/2026_11_22_000006_create_inventory_and_notifications_tables.php')->up();
    (require dirname(__DIR__) . '/database/migrations/2026_11_22_000007_add_unit_to_inventory_movements.php')->up();
    InventorySettings::flush();

    $marble = DB::table('products')->insertGetId(['sku' => 'MAR-001', 'name' => 'Italian Marble']);
    $tiles = DB::table('products')->insertGetId(['sku' => 'TIL-001', 'name' => 'Floor Tiles']);
    $granite = DB::table('products')->insertGetId(['sku' => 'GRA-001', 'name' => 'Granite Slab']);
    DB::table('product_pricing')->insert(['product_id' => $tiles, 'unit' => 'box', 'pack_qty' => 10.76, 'pack_unit' => 'sq ft']);

    $throws = static function (callable $fn): ?string {
        try {
            $fn();
        } catch (InventoryException $e) {
            return $e->getMessage();
        }

        return null;
    };
    $activeAlerts = static fn(int $pid) => DB::table('inventory_alerts')->where('product_id', $pid)->where('status', 'active')->pluck('type');
    $count = static fn(string $table) => DB::table($table)->count();

    // --- settings defaults
    $assert->that('settings seeded: enabled', InventorySettings::enabled());
    $assert->equals('default threshold = 10', 10.0, InventorySettings::defaultThreshold());

    // --- start tracking
    $s = Inv::startTracking($marble, 'SQ FT', 500, 100, ['created_by' => 1]);
    $assert->equals('unit canonicalised to units.symbol', 'sq ft', $s['unit']);
    $assert->equals('opening qty stored', 500.0, (float) $s['quantity']);
    $assert->equals('healthy at 500 / 100', 'healthy', $s['status']);
    $open = DB::table('inventory_movements')->where('product_id', $marble)->first();
    $assert->that('opening movement written', $open['type'] === 'opening' && (float) $open['qty_after'] === 500.0 && $open['source'] === 'opening');
    $assert->that('cannot track twice', $throws(fn() => Inv::startTracking($marble, 'sq ft')) !== null);
    $assert->that('unknown unit rejected', $throws(fn() => Inv::startTracking($granite, 'litre')) !== null);
    $assert->that('unknown product rejected', $throws(fn() => Inv::startTracking(9999, 'piece')) !== null);
    $assert->equals('no notifications while healthy', 0, $count('notifications'));

    // --- receive (same unit)
    $m = Inv::receive($marble, 200, ['reference_no' => 'GRN-001', 'notes' => 'New material received']);
    $assert->that('receive: 500 → 700', (float) $m['qty_before'] === 500.0 && (float) $m['qty_after'] === 700.0 && $m['type'] === 'stock_in');
    $assert->equals('receive keeps reference', 'GRN-001', $m['reference_no']);
    $assert->that('receive rejects 0', $throws(fn() => Inv::receive($marble, 0)) !== null);
    $assert->that('receive rejects negative', $throws(fn() => Inv::receive($marble, -5)) !== null);
    $assert->that('receive on untracked product rejected', $throws(fn() => Inv::receive($granite, 5)) !== null);

    // --- adjust to physical count
    $m = Inv::adjustTo($marble, 680, 'damaged');
    $assert->that('adjust 700 → 680 = adjust_out −20', $m['type'] === 'adjust_out' && (float) $m['qty_change'] === -20.0);
    $assert->equals('reason code mapped to label', 'Damaged / broken material', $m['reason']);
    $assert->that('adjust requires reason', $throws(fn() => Inv::adjustTo($marble, 600, '  ')) !== null);
    $assert->that('adjust with same count rejected', $throws(fn() => Inv::adjustTo($marble, 680, 'other')) !== null);
    $assert->that('adjustBy cannot go below zero', $throws(fn() => Inv::adjustBy($marble, -1000, 'other')) !== null);
    $assert->equals('failed adjust leaves stock unchanged', 680.0, (float) Inv::stockFor($marble)['quantity']);

    // --- unit conversion snapshot: tiles stocked in sq ft, received in boxes
    Inv::startTracking($tiles, 'sq ft', 0, 50);
    $assert->equals('tracking at 0 opens OUT alert', ['out'], $activeAlerts($tiles));
    $assert->equals('out notification created', 1, DB::table('notifications')->where('type', 'stock.out')->count());
    $assert->equals('enterable units offer box', ['sq ft', 'box'], Inv::enterableUnits($tiles));
    $m = Inv::receive($tiles, 2, ['entered_unit' => 'box']);
    $assert->that('2 box → 21.52 sq ft', abs((float) $m['qty_change'] - 21.52) < 0.0001);
    $assert->that('snapshot stored', $m['entered_unit'] === 'box' && (float) $m['entered_qty'] === 2.0 && abs((float) $m['conversion_factor'] - 10.76) < 1e-6);
    $assert->equals('out → low: low alert active', ['low'], $activeAlerts($tiles));
    $assert->equals('out notification cleared from unread', 0, DB::table('notifications')->where('type', 'stock.out')->whereNull('read_at')->count());
    $assert->equals('low notification created', 1, DB::table('notifications')->where('type', 'stock.low')->count());
    // Pack change later must not rewrite history.
    DB::table('product_pricing')->where('product_id', $tiles)->update(['pack_qty' => 12]);
    $assert->that('history keeps old factor', abs((float) DB::table('inventory_movements')->where('id', $m['id'])->value('conversion_factor') - 10.76) < 1e-6);
    $assert->that('no conversion for marble without pack', $throws(fn() => Inv::receive($marble, 1, ['entered_unit' => 'box'])) !== null);

    // --- no duplicate alerts / notifications on repeated checks
    $before = $count('notifications');
    AlertService::evaluateAll();
    AlertService::evaluateAll();
    $assert->equals('repeat checks add no notifications', $before, $count('notifications'));
    $assert->equals('still one active alert for tiles', 1, count($activeAlerts($tiles)));

    // --- low → out → healthy transitions on marble (threshold 100)
    Inv::adjustTo($marble, 90, 'count_correction');
    $assert->equals('680 → 90: LOW', 'low', Inv::stockFor($marble)['status']);
    Inv::adjustTo($marble, 0, 'count_correction');
    $assert->equals('90 → 0: OUT', 'out', Inv::stockFor($marble)['status']);
    $assert->equals('low resolved, out active', ['out'], $activeAlerts($marble));
    $assert->equals('marble low alert resolved', 1, DB::table('inventory_alerts')->where('product_id', $marble)->where('type', 'low')->where('status', 'resolved')->count());
    Inv::receive($marble, 300);
    $assert->equals('0 → 300: HEALTHY', 'healthy', Inv::stockFor($marble)['status']);
    $assert->equals('no active alerts after recovery', [], $activeAlerts($marble));
    $assert->equals('recovered notification', 1, DB::table('notifications')->where('type', 'stock.recovered')->count());

    // --- threshold change re-evaluates
    Inv::setThreshold($marble, 300);
    $assert->equals('qty = threshold → LOW', 'low', Inv::stockFor($marble)['status']);
    Inv::setThreshold($marble, null);
    $assert->equals('cleared threshold → default 10 → HEALTHY', 'healthy', Inv::stockFor($marble)['status']);

    // --- settings default change caught by the check
    Inv::startTracking($granite, 'piece', 25);
    InventorySettings::set('default_threshold', '30');
    $r = AlertService::evaluateAll();
    $assert->equals('evaluateAll catches default threshold change', 'low', Inv::stockFor($granite)['status']);
    $assert->that('evaluateAll summary counts', $r['checked'] === 3 && $r['opened'] === 1);
    InventorySettings::set('default_threshold', '10');

    // --- disabled inventory: status still cached, nothing raised
    InventorySettings::set('is_enabled', '0');
    $n = $count('notifications');
    $a = $count('inventory_alerts');
    Inv::adjustTo($marble, 0, 'other');
    $assert->equals('disabled: status still OUT', 'out', Inv::stockFor($marble)['status']);
    $assert->that('disabled: no alerts or notifications', $count('notifications') === $n && $count('inventory_alerts') === $a);
    InventorySettings::set('is_enabled', '1');
    AlertService::evaluateAll();
    $assert->equals('re-enabled check opens the alert', ['out'], $activeAlerts($marble));

    // --- import path
    $r = Inv::setFromImport($marble, 0, null, null);
    $assert->equals('import same qty = unchanged', 'unchanged', $r['action']);
    $r = Inv::setFromImport($marble, 480, 'sq ft', null);
    $assert->that('import diff = changed 0 → 480', $r['action'] === 'changed' && $r['before'] === 0.0 && $r['after'] === 480.0);
    $last = DB::table('inventory_movements')->where('product_id', $marble)->orderBy('id', 'desc')->first();
    $assert->that('import movement tagged', $last['type'] === 'import' && $last['source'] === 'import' && $last['reason'] === Inv::IMPORT_REASON);
    $assert->that('import cannot change unit', $throws(fn() => Inv::setFromImport($marble, 10, 'box', null)) !== null);
    $new = DB::table('products')->insertGetId(['sku' => 'NEW-1', 'name' => 'New Onyx']);
    DB::table('product_pricing')->insert(['product_id' => $new, 'unit' => 'piece']);
    $r = Inv::setFromImport($new, 12, null, 5);
    $assert->that('import starts tracking with selling unit', $r['action'] === 'started' && $r['unit'] === 'piece');
    $assert->equals('import opening movement source', 'import', DB::table('inventory_movements')->where('product_id', $new)->value('source'));

    // --- import inside an outer transaction rolls back with it
    $mv = $count('inventory_movements');
    try {
        DB::transaction(function () use ($marble): void {
            Inv::setFromImport($marble, 1, null, null);
            throw new RuntimeException('import failed later');
        });
    } catch (RuntimeException) {
    }
    $assert->that('outer rollback undoes stock + movement', (float) Inv::stockFor($marble)['quantity'] === 480.0 && $count('inventory_movements') === $mv);

    // --- stop tracking
    Inv::stopTracking($tiles);
    $assert->that('stop tracking removes row, keeps history', !Inv::isTracked($tiles) && DB::table('inventory_movements')->where('product_id', $tiles)->count() > 0);
    $assert->equals('history keeps its unit after stop tracking', ['sq ft'], array_values(array_unique(DB::table('inventory_movements')->where('product_id', $tiles)->pluck('unit'))));
    $assert->equals('stop tracking resolves alerts', [], $activeAlerts($tiles));

    // --- notification service
    $id = Notes::notify('import.completed', 'Bulk upload completed', '3 products', null, 'info', [], 'imp:1');
    $assert->that('dedupe skips unread duplicate', Notes::notify('import.completed', 'again', null, null, 'info', [], 'imp:1') === null);
    $unread = Notes::unreadCount();
    $assert->that('markRead', Notes::markRead($id) && Notes::unreadCount() === $unread - 1);
    $assert->that('after read, same key allowed again', Notes::notify('import.completed', 'again', null, null, 'info', [], 'imp:1') !== null);
    $latest = Notes::latest(3);
    $assert->that('latest newest-first with decoded data', count($latest) === 3 && is_array($latest[0]['data']));
    Notes::markAllRead();
    $assert->equals('markAllRead', 0, Notes::unreadCount());
    DB::table('notifications')->where('id', $id)->update(['created_at' => '2020-01-01 00:00:00']);
    $assert->equals('prune old read notifications', 1, Notes::prune(90));
    $assert->equals('qty formatting', '10.76', Inv::qty(10.760));

    // --- notification panel queries (phase 3)
    DB::table('notifications')->delete();
    $a = Notes::notify('stock.low', 'A low', null, null, 'warning');
    $b = Notes::notify('stock.out', 'B out', null, null, 'danger');
    $c = Notes::notify('import.completed', 'C import');
    Notes::markRead($c);
    $assert->equals('tabCounts', ['all' => 3, 'unread' => 2, 'stock' => 2, 'system' => 0], Notes::tabCounts());
    $assert->equals('search tab=stock', 2, Notes::search(['tab' => 'stock'])['total']);
    $assert->equals('search tab=system', 1, Notes::search(['tab' => 'system'])['total']);
    $assert->equals('search tab=unread', 2, Notes::search(['tab' => 'unread'])['total']);
    $assert->equals('search type + state=read', 1, Notes::search(['type' => 'import.completed', 'state' => 'read'])['total']);
    $assert->equals('search paginates', 1, count(Notes::search([], 1, 1)['items']));
    $assert->equals('markReadMany', 2, Notes::markReadMany([$a, $b]));
    $assert->equals('markUnreadMany', 1, Notes::markUnreadMany([$c]));
    $assert->equals('deleteMany', 2, Notes::deleteMany([$a, $b]));
    $assert->equals('empty id list is a no-op', 0, Notes::deleteMany([]));
    $assert->that('find hydrates', Notes::find($c)['is_read'] === false && Notes::find(999999) === null);
};
