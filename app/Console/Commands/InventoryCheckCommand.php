<?php

namespace App\Console\Commands;

use App\Services\Inventory\InventoryCheck;
use Vayu\Console\Command;

/**
 * php vayu inventory:check [--dry-run] [--quiet]
 *
 * Re-checks every tracked product's stock alerts and clears old read notifications.
 * Meant for cron every 15 minutes:
 *   * /15 * * * * cd /path/to/project && php vayu inventory:check --quiet
 * Exit codes: 0 ok (or another run is in progress), 1 failed.
 */
class InventoryCheckCommand extends Command
{
    protected static string $name = 'inventory:check';

    protected static string $description = 'Re-check stock alerts and clear old notifications (cron)';

    public function handle(array $args): int
    {
        $dry = in_array('--dry-run', $args, true);
        $quiet = in_array('--quiet', $args, true) || in_array('-q', $args, true);

        $r = InventoryCheck::make()->run($dry, 'cron');
        $line = InventoryCheck::logLine($r, $dry ? 'cron dry-run' : 'cron');
        if (!$dry) {
            InventoryCheck::log($line);
        }

        if ($r['status'] === 'locked') {
            if (!$quiet) {
                $this->comment('Another inventory check is still running — skipped.');
            }

            return 0;
        }
        if ($r['status'] === 'failed') {
            $this->error('Inventory check failed: ' . ($r['error'] ?? 'unknown error'));

            return 1;
        }
        if (!$quiet) {
            if ($dry) {
                $this->comment('Dry run — nothing was saved.');
            }
            if (!$r['enabled']) {
                $this->comment('Stock alerts are switched off — alert state was not changed.');
            }
            $this->table(['checked', 'alerts opened', 'alerts resolved', 'notifications', 'old notifications removed', 'time'], [[
                (string) $r['checked'], (string) $r['opened'], (string) $r['resolved'], (string) $r['notified'], (string) $r['pruned'], $r['ms'] . ' ms',
            ]]);
            $this->info($dry ? 'Dry run complete.' : 'Inventory check complete.');
        }

        return 0;
    }
}
