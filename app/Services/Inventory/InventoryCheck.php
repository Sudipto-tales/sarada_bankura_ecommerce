<?php

namespace App\Services\Inventory;

use App\Services\NotificationService;
use Vayu\Database\DB;

/**
 * The scheduled inventory check (cron: `php vayu inventory:check`) and the
 * "Run check now" button share this.
 *
 *   1. take an exclusive file lock (a second overlapping run exits straight away)
 *   2. re-evaluate every tracked product's alert state (skipped while alerts are off)
 *   3. delete read notifications older than `retention_days`
 *   4. remember when it ran (shown on the Stock alerts page)
 *
 * A failure raises one "Inventory check failed" notification (not repeated while unread)
 * and is cleared again by the next successful run.
 */
final class InventoryCheck
{
    public const FAILED_KEY = 'system:inventory-check-failed';

    public function __construct(private string $lockFile)
    {
    }

    public static function make(): self
    {
        return new self(app()->storagePath('cache/inventory-check.lock'));
    }

    /**
     * @return array{status:string, checked:int, opened:int, resolved:int, notified:int, pruned:int, enabled:bool, dry_run:bool, ms:int, error?:string}
     *         status = ok | locked | failed
     */
    public function run(bool $dryRun = false, string $source = 'cron'): array
    {
        $started = microtime(true);
        $result = ['status' => 'ok', 'checked' => 0, 'opened' => 0, 'resolved' => 0, 'notified' => 0, 'pruned' => 0,
            'enabled' => InventorySettings::enabled(), 'dry_run' => $dryRun, 'ms' => 0];

        $dir = dirname($this->lockFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $lock = @fopen($this->lockFile, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) {
                fclose($lock);
            }

            return ['status' => 'locked'] + $result;
        }

        try {
            InventorySettings::flush();
            $pdo = DB::connection()->pdo();
            if ($dryRun) {
                $pdo->beginTransaction(); // everything below is rolled back
            }
            try {
                if ($result['enabled']) {
                    $result = array_merge($result, AlertService::evaluateAll());
                }
                $result['pruned'] = NotificationService::prune((int) InventorySettings::get('retention_days'));
            } finally {
                if ($dryRun && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }

            if (!$dryRun) {
                NotificationService::markReadByKey(self::FAILED_KEY); // a previous failure is over
                $result['ms'] = (int) round((microtime(true) - $started) * 1000);
                InventorySettings::set('last_check_at', date('Y-m-d H:i:s'));
                InventorySettings::set('last_check_source', $source);
                InventorySettings::set('last_check_summary', json_encode(array_intersect_key($result, array_flip(['checked', 'opened', 'resolved', 'pruned', 'enabled']))));
            }
        } catch (\Throwable $e) {
            $result['status'] = 'failed';
            $result['error'] = $e->getMessage();
            if (!$dryRun) {
                try {
                    NotificationService::notify(
                        'system.check_failed',
                        'Inventory check failed',
                        mb_substr($e->getMessage(), 0, 300),
                        'admin/inventory/alerts',
                        'danger',
                        ['source' => $source, 'at' => date('Y-m-d H:i:s')],
                        self::FAILED_KEY,
                        'octagon-alert',
                    );
                } catch (\Throwable) {
                    // the database itself may be what failed
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $result['ms'] = (int) round((microtime(true) - $started) * 1000);

        return $result;
    }

    /** One log line, e.g. "2026-11-22 10:15:00 ok checked=12 opened=1 resolved=0 pruned=3 (41 ms)". */
    public static function logLine(array $r, string $source): string
    {
        $line = date('Y-m-d H:i:s') . ' [' . $source . '] ' . $r['status'];
        if ($r['status'] !== 'locked') {
            $line .= ($r['dry_run'] ? ' (dry run)' : '') . ($r['enabled'] ? '' : ' alerts-off')
                . ' checked=' . $r['checked'] . ' opened=' . $r['opened'] . ' resolved=' . $r['resolved']
                . ' notified=' . $r['notified'] . ' pruned=' . $r['pruned'] . ' (' . $r['ms'] . ' ms)';
        }
        if (!empty($r['error'])) {
            $line .= ' error: ' . str_replace(["\r", "\n"], ' ', $r['error']);
        }

        return $line;
    }

    /** Append to storage/logs/inventory.log (never throws). */
    public static function log(string $line): void
    {
        $dir = app()->storagePath('logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($dir . '/inventory.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
