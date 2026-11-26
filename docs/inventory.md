# Inventory & stock alerts

**Built (2026-11-22).** A stock register tracked **per product**, never per variant (no
warehouses, purchase orders, suppliers or reservations). Orders never check stock; dispatch may
take it below zero (see orders.md).

| Page | Route |
| --- | --- |
| Stock overview | `/admin/inventory` |
| Stock management (receive, adjust, opening stock) | `/admin/inventory/stock` |
| Stock movements (audit trail, CSV export) | `/admin/inventory/movements` |
| Stock alerts + settings + cron help | `/admin/inventory/alerts` |
| Notifications | `/admin/notifications` (also the topbar bell) |

All stock changes go through `App\Services\Inventory\InventoryService`, which writes an
`inventory_movements` row and re-evaluates alerts in the same transaction. Alerts and
"low / out of stock" notifications therefore fire **immediately** when stock changes.

## Where stock shows

- **Topbar** Low / Out chips (only while something is tracked) → `/admin/inventory/stock?status=low|out`.
- **Dashboard** stock card: Tracked / In stock / Low / Out tiles + a "Needs attention" list.
- **Products list** Stock column (qty + unit + status, or "Not tracked").
- **Product wizard, step 4:** "Track stock" switch with opening qty, unit and low-stock threshold.
  Saving only ever **starts** tracking; it never overwrites or stops it, so re-saving a product
  keeps its stock.
- **Bulk import:** optional `stock_qty` / `stock_unit` / `low_stock_threshold` columns on the
  Products sheet. A different `stock_qty` becomes an adjustment movement (shown in the preview);
  `-` on `stock_qty` is refused, `-` on the threshold = default; the unit can't change by upload.
- Deep links: `/admin/inventory/stock?product=ID&receive=1` (or `adjust=1`) opens the dialog.

Notifications use the shared `notifications` table via `NotificationService` (bell panel in the
topbar polls every 60 s; full page at `/admin/notifications`).

## Scheduled check (cron)

`php vayu inventory:check` is a safety net that also housekeeps:

1. takes an exclusive file lock (`storage/cache/inventory-check.lock`) — an overlapping run exits with code 0;
2. re-evaluates every tracked product (skipped while stock alerts are switched off);
3. deletes **read** notifications older than *Keep read notifications* (default 90 days; unread are never deleted);
4. writes one line to `storage/logs/inventory.log`.

On failure it exits with code 1 and adds one "Inventory check failed" notification
(not repeated while unread); the next successful run marks it read.

Options: `--dry-run` (report only, nothing saved) and `--quiet` (no output, for cron).

### Install

Every 15 minutes:

```
*/15 * * * * cd /path/to/project && php vayu inventory:check --quiet >> storage/logs/cron.log 2>&1
```

- **Hostinger / cPanel:** add it under *Cron Jobs* in the panel (see deployment.md for the exact
  production lines; `crontab` isn't available over SSH there).
- **VPS:** `crontab -e` and paste the line above, or use the helper below.

### Install with the helper script (idempotent)

`bin/install-cron.sh` installs this line and the `payments:expire` line (every 5 min) for you, detecting the project path
and the full `php` path automatically. It is **idempotent** — it only edits the
crontab the first time; later runs find the job already present and skip it, so it is
safe to call on every deploy (e.g. from a `git pull` deploy hook):

```
bin/install-cron.sh            # install if not already present
bin/install-cron.sh --show     # print the current crontab
bin/install-cron.sh --remove   # remove the job this project installed
```

The crontab is an OS-level thing, separate from your code — committing the script does
**not** install anything. It still has to be *run* on the server (by hand over SSH, or
from your deploy script). On shared cPanel hosts `crontab` over SSH may be disabled in
favour of the *Cron Jobs* web UI; run `crontab -l` once to confirm it works.

The Stock alerts page shows when the check last ran and warns if the scheduled run has not
been seen for over an hour. "Run check now" on that page runs the same check by hand.
