<?php

namespace App\Console\Commands;

use App\Services\Payment\PayWindow;
use Vayu\Console\Command;

/**
 * php vayu payments:expire [--quiet]
 *
 * Marks payment windows that ran out (6 min + 30 s grace) as expired — failed attempts the admin
 * can follow up under Payments ▸ Failed attempts. Pages also do this lazily when they load, so
 * the cron only keeps the list tidy. Cron, every 5 minutes:
 *   * /5 * * * * cd /path/to/project && php vayu payments:expire --quiet
 */
class PaymentExpireCommand extends Command
{
    protected static string $name = 'payments:expire';

    protected static string $description = 'Mark payment windows that ran out as expired (cron)';

    public function handle(array $args): int
    {
        try {
            $n = PayWindow::expireStale();
        } catch (\Throwable $e) {
            $this->error('Could not expire payment windows: ' . $e->getMessage());

            return 1;
        }
        if (!in_array('--quiet', $args, true) && !in_array('-q', $args, true)) {
            $this->info($n . ' payment window' . ($n === 1 ? '' : 's') . ' marked expired.');
        }

        return 0;
    }
}
