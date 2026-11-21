<?php

use App\Services\Inventory\InventoryCheck;
use App\Services\Inventory\InventoryService as Inv;
use App\Services\Inventory\InventorySettings;
use App\Services\NotificationService as Notes;
use Vayu\Application;
use Vayu\Database\DB;
use Vayu\Database\Schema\Blueprint;
use Vayu\Database\Schema\Schema;

/** Phase 6: scheduled inventory check — lock, dry run, prune, failure notification, recovery. */
return function (Assert $assert): void {
    $app = new Application(dirname(__DIR__));
    $app->config()->set('database.default', 'sqlite');
    $app->config()->set('database.connections.sqlite.database', ':memory:');

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
    DB::table('units')->insert(['symbol' => 'piece']);
    (require dirname(__DIR__) . '/database/migrations/2026_11_22_000006_create_inventory_and_notifications_tables.php')->up();
    (require dirname(__DIR__) . '/database/migrations/2026_11_22_000007_add_unit_to_inventory_movements.php')->up();
    InventorySettings::flush();

    $lockFile = sys_get_temp_dir() . '/vayu-inv-check-' . getmypid() . '.lock';
    $check = new InventoryCheck($lockFile);

    $pid = DB::table('products')->insertGetId(['sku' => 'CHK-1', 'name' => 'Check Slab']);
    Inv::startTracking($pid, 'piece', 25);            // healthy at default threshold 10
    InventorySettings::set('default_threshold', '30'); // now 25 ≤ 30 → should become LOW on the next check

    // --- dry run: reports, saves nothing
    $r = $check->run(true);
    $assert->that('dry run reports the change', $r['status'] === 'ok' && $r['opened'] === 1 && $r['dry_run']);
    $assert->equals('dry run saved no alert', 0, DB::table('inventory_alerts')->count());
    $assert->equals('dry run saved no status', 'healthy', Inv::stockFor($pid)['status']);
    $assert->that('dry run does not record last_check_at', InventorySettings::get('last_check_at') === '');

    // --- real run
    $r = $check->run(false, 'cron');
    $assert->that('run opens the alert', $r['status'] === 'ok' && $r['checked'] === 1 && $r['opened'] === 1);
    $assert->equals('status saved', 'low', Inv::stockFor($pid)['status']);
    $assert->that('last_check_at recorded', InventorySettings::get('last_check_at') !== '' && InventorySettings::get('last_check_source') === 'cron');
    $r = $check->run();
    $assert->that('second run is a no-op (no duplicate alert)', $r['opened'] === 0 && DB::table('inventory_alerts')->count() === 1);

    // --- lock: a run while another holds the lock is skipped
    $h = fopen($lockFile, 'c');
    flock($h, LOCK_EX);
    $assert->equals('overlapping run is skipped', 'locked', $check->run()['status']);
    flock($h, LOCK_UN);
    fclose($h);
    $assert->equals('runs again once the lock is free', 'ok', $check->run()['status']);

    // --- prune: only read notifications older than retention_days
    DB::table('notifications')->delete();
    $old = date('Y-m-d H:i:s', strtotime('-100 days'));
    DB::table('notifications')->insert(['type' => 'x', 'level' => 'info', 'title' => 'old read', 'read_at' => $old, 'created_at' => $old]);
    DB::table('notifications')->insert(['type' => 'x', 'level' => 'info', 'title' => 'old unread', 'read_at' => null, 'created_at' => $old]);
    DB::table('notifications')->insert(['type' => 'x', 'level' => 'info', 'title' => 'recent read', 'read_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s', strtotime('-5 days'))]);
    $r = $check->run();
    $assert->equals('prunes only old read notifications', 1, $r['pruned']);
    $assert->equals('unread + recent kept', ['old unread', 'recent read'], DB::table('notifications')->orderBy('id')->pluck('title'));

    // --- alerts switched off: no alert changes, prune still runs
    InventorySettings::set('is_enabled', '0');
    InventorySettings::set('default_threshold', '10');
    $r = $check->run();
    $assert->that('alerts off: nothing evaluated', $r['status'] === 'ok' && !$r['enabled'] && $r['checked'] === 0);
    $assert->equals('alerts off: alert left as is', 1, DB::table('inventory_alerts')->where('status', 'active')->count());
    InventorySettings::set('is_enabled', '1');
    $assert->equals('re-enabled: alert resolves', 1, $check->run()['resolved']);

    // --- failure: one notification, not repeated, cleared after recovery
    DB::statement('ALTER TABLE inventory_stock RENAME TO inventory_stock_bak');
    $r1 = $check->run();
    $r2 = $check->run();
    $assert->that('failure reported', $r1['status'] === 'failed' && str_contains((string) $r1['error'], 'inventory_stock'));
    $assert->equals('one failure notification while unread', 1, DB::table('notifications')->where('type', 'system.check_failed')->count());
    $assert->equals('failure notification is unread', 1, DB::table('notifications')->where('dedupe_key', InventoryCheck::FAILED_KEY)->whereNull('read_at')->count());
    DB::statement('ALTER TABLE inventory_stock_bak RENAME TO inventory_stock');
    $assert->equals('recovers', 'ok', $check->run()['status']);
    $assert->equals('failure notification cleared after recovery', 0, DB::table('notifications')->where('dedupe_key', InventoryCheck::FAILED_KEY)->whereNull('read_at')->count());

    // --- log line
    $line = InventoryCheck::logLine(['status' => 'ok', 'checked' => 3, 'opened' => 1, 'resolved' => 0, 'notified' => 1, 'pruned' => 2, 'enabled' => true, 'dry_run' => false, 'ms' => 12], 'cron');
    $assert->that('log line format', (bool) preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d \[cron\] ok checked=3 opened=1 resolved=0 notified=1 pruned=2 \(12 ms\)$/', $line));

    @unlink($lockFile);
};
