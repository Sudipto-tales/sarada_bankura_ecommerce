<?php
use App\Services\Inventory\InventoryService as Inv;

$active = 'inventory.alerts';
$title = 'Stock alerts';
$rows = $rows ?? [];
$tab = $tab ?? 'active';
$stats = $stats ?? ['active' => 0, 'out' => 0, 'low' => 0, 'resolved30' => 0];
$settings = $settings ?? [];
$on = static fn(string $k) => ($settings[$k] ?? '0') === '1';
$dur = static function (string $from, ?string $to): string {
    $s = max(0, (($to ? strtotime($to) : time()) - strtotime($from)));

    return match (true) {
        $s < 3600 => max(1, (int) floor($s / 60)) . ' min',
        $s < 86400 => (int) floor($s / 3600) . ' h',
        default => (int) floor($s / 86400) . ' day' . ((int) floor($s / 86400) === 1 ? '' : 's'),
    };
};
include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs">Inventory <i data-lucide="chevron-right"></i> Stock alerts</div><h1>Stock alerts<span style="color:#dca71f">.</span></h1></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn" href="#settings"><i data-lucide="settings-2" style="width:16px"></i>Alert settings</a>
    <form method="post" action="<?= route('admin.inventory.check') ?>"><?= csrf_field() ?><button class="btn gold" type="submit"><i data-lucide="refresh-cw" style="width:16px"></i>Run check now</button></form>
  </div>
</div>
<?php if (!empty($success)): ?><div class="notice ok">✓ <?= e($success) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice bad"><?= e($error) ?></div><?php endif; ?>
<?php if (empty($enabled)): ?><div class="notice">Alerts are switched off. Existing alerts stay as they are and no new ones are raised until you switch them back on below.</div><?php endif; ?>

<?php $lc = $lastCheck ?? null; if ($lc): $agoTxt = static function (?int $s): string {
    if ($s === null) return 'never';
    return match (true) { $s < 90 => 'just now', $s < 3600 => floor($s / 60) . ' min ago', $s < 86400 => floor($s / 3600) . ' h ago', default => floor($s / 86400) . ' days ago' };
}; ?>
<div class="checkbar<?= $lc['cronStale'] ? ' warn' : '' ?>">
  <span class="cb-ic"><i data-lucide="<?= $lc['cronStale'] ? 'clock' : 'circle-check' ?>"></i></span>
  <div class="min-w-0">
    <b>Last check: <?= $agoTxt($lc['ago']) ?><?= $lc['at'] ? ' · ' . e($lc['source'] === 'manual' ? 'run by hand' : 'scheduled') : '' ?></b>
    <small><?php if ($lc['cronStale']): ?>The scheduled check <?= $lc['cronAt'] ? 'last ran ' . e(date('d M, H:i', strtotime($lc['cronAt']))) : 'has not run yet' ?>. Alerts still fire instantly when stock changes; set up the cron job so old notifications are cleared and missed alerts are caught. <a class="linkbtn" href="#cron">How →</a>
    <?php else: ?>Scheduled check is running<?= isset($lc['summary']['checked']) ? ' · ' . (int) $lc['summary']['checked'] . ' products checked last time' : '' ?>.<?php endif; ?></small>
  </div>
</div>
<?php endif; ?>

<div class="statstrip">
  <a class="stat glass" href="?tab=active"><span class="metric-icon" style="background:#fff0bf;color:#9b6c0a"><i data-lucide="bell-ring"></i></span><div><small>Active alerts</small><b><?= $stats['active'] ?></b><em>Need restocking</em></div></a>
  <div class="stat glass"><span class="metric-icon" style="background:#f8d7d1;color:#b4452f"><i data-lucide="package-x"></i></span><div><small>Out of stock</small><b><?= $stats['out'] ?></b><em>Nothing on hand</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#ffe6c4;color:#a2632a"><i data-lucide="triangle-alert"></i></span><div><small>Low stock</small><b><?= $stats['low'] ?></b><em>At or below threshold</em></div></div>
  <a class="stat glass" href="?tab=resolved"><span class="metric-icon" style="background:#dff3e9;color:#1f7a53"><i data-lucide="circle-check"></i></span><div><small>Resolved</small><b><?= $stats['resolved30'] ?></b><em>In the last 30 days</em></div></a>
</div>

<div class="card" style="padding:0">
  <div class="card-h" style="padding:18px 20px 6px;flex-wrap:wrap">
    <div><div class="eyebrow">Alert history</div><h3><?= ['active' => 'Active alerts', 'resolved' => 'Resolved alerts', 'all' => 'All alerts'][$tab] ?></h3></div>
    <nav class="segtabs" style="margin:0" aria-label="Alert status">
      <?php foreach (['active' => 'Active', 'resolved' => 'Resolved', 'all' => 'All'] as $k => $l): ?><a class="<?= $tab === $k ? 'on' : '' ?>" href="?tab=<?= $k ?>"><?= $l ?></a><?php endforeach; ?>
    </nav>
  </div>
  <?php if (!$rows): ?>
    <div class="nf-empty"><div class="nf-bell" style="<?= $tab === 'active' ? 'background:linear-gradient(145deg,#e6f6ec,#cdeedb);color:#1f7a53' : '' ?>"><i data-lucide="<?= $tab === 'active' ? 'circle-check' : 'bell' ?>"></i></div><b><?= $tab === 'active' ? 'No active alerts' : 'No alerts yet' ?></b><small><?= $tab === 'active' ? 'Every tracked product is above its low-stock threshold.' : 'Alerts appear when a product falls to its threshold or runs out.' ?></small></div>
  <?php else: ?>
  <div class="tablewrap" style="border-radius:0 0 24px 24px;border-left:0;border-right:0;border-bottom:0;margin-top:12px">
    <table class="data">
      <thead><tr><th>Product</th><th>Current stock</th><th class="hide-sm">Threshold</th><th>Alert</th><th class="hide-sm">Raised</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $a): $link = $a['live_stock'] ? route('admin.inventory.stock') . '?product=' . (int) $a['product_id'] : null; ?>
        <tr<?= $link ? ' class="click-row" data-href="' . e($link) . '" tabindex="0"' : '' ?>>
          <td><b style="font-size:13px"><?= e((string) ($a['name'] ?? 'Deleted product')) ?></b><small class="block muted text-xs"><?= e((string) ($a['sku'] ?? '')) ?></small></td>
          <td><?php if ($a['live_stock']): ?><b><?= Inv::qty((float) $a['current_qty']) ?></b> <span class="muted text-xs"><?= e((string) $a['unit']) ?></span><?php else: ?><span class="muted text-xs">Not tracked</span><?php endif; ?>
            <small class="block muted text-xs">was <?= Inv::qty((float) $a['qty_at_trigger']) ?> when raised</small></td>
          <td class="hide-sm muted"><?= $a['threshold_now'] !== null ? Inv::qty((float) $a['threshold_now']) : Inv::qty((float) $a['threshold_at_trigger']) ?></td>
          <td><span class="stock-pill <?= e((string) $a['type']) ?>"><?= $a['type'] === 'out' ? 'Out of stock' : 'Low stock' ?></span></td>
          <td class="hide-sm" style="white-space:nowrap"><span class="text-xs"><?= date('d M, H:i', strtotime((string) $a['triggered_at'])) ?></span><small class="block muted text-xs"><?= $a['status'] === 'active' ? 'for ' . $dur((string) $a['triggered_at'], null) : 'lasted ' . $dur((string) $a['triggered_at'], $a['resolved_at']) ?></small></td>
          <td><?php if ($a['status'] === 'active'): ?><span class="mv-pill red">● Active</span><?php if ($link): ?> <a class="btn sm" style="padding:6px 10px;font-size:12px;margin-left:6px" href="<?= e($link) ?>&receive=1"><i data-lucide="plus" style="width:13px"></i>Receive</a><?php endif; ?>
              <?php else: ?><span class="mv-pill">Resolved</span><small class="block muted text-xs mt-1"><?= date('d M, H:i', strtotime((string) $a['resolved_at'])) ?></small><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<form method="post" action="<?= route('admin.inventory.settings') ?>" class="card" id="settings" style="margin-top:18px">
  <?= csrf_field() ?>
  <div class="card-h" style="align-items:flex-start"><div><div class="eyebrow">Settings</div><h3>Alerts &amp; notifications</h3><p class="text-xs muted mt-1">Saving re-checks every tracked product straight away, so a new default threshold takes effect immediately.</p></div></div>
  <div class="set-grid">
    <label class="set-row"><span><b>Stock alerts</b><small>Raise low and out-of-stock alerts when stock changes.</small></span><input type="checkbox" class="switch-in" name="is_enabled" value="1"<?= $on('is_enabled') ? ' checked' : '' ?>></label>
    <div class="set-row"><span><b>Default low-stock threshold</b><small>Used by products without their own threshold.</small></span><input name="default_threshold" inputmode="decimal" value="<?= e(Inv::qty((float) ($settings['default_threshold'] ?? 10))) ?>" class="set-in" required></div>
    <label class="set-row"><span><b>Notify: out of stock</b><small>Bell notification when a product runs out.</small></span><input type="checkbox" class="switch-in" name="notify_out" value="1"<?= $on('notify_out') ? ' checked' : '' ?>></label>
    <label class="set-row"><span><b>Notify: low stock</b><small>Bell notification when a product reaches its threshold.</small></span><input type="checkbox" class="switch-in" name="notify_low" value="1"<?= $on('notify_low') ? ' checked' : '' ?>></label>
    <label class="set-row"><span><b>Notify: back in stock</b><small>When an alert clears after receiving stock.</small></span><input type="checkbox" class="switch-in" name="notify_recovered" value="1"<?= $on('notify_recovered') ? ' checked' : '' ?>></label>
    <label class="set-row"><span><b>Notify: bulk upload finished</b><small>Summary after every bulk product upload.</small></span><input type="checkbox" class="switch-in" name="notify_import" value="1"<?= $on('notify_import') ? ' checked' : '' ?>></label>
    <div class="set-row"><span><b>Keep read notifications</b><small>Older read notifications are cleared by the scheduled check. Unread ones are always kept.</small></span><span style="display:flex;align-items:center;gap:8px"><input name="retention_days" type="number" min="7" max="3650" value="<?= (int) ($settings['retention_days'] ?? 90) ?>" class="set-in"><span class="muted text-xs">days</span></span></div>
  </div>
  <div class="modal-f"><button class="btn gold" type="submit"><i data-lucide="check" style="width:16px"></i>Save settings</button></div>
</form>

<div class="card" id="cron" style="margin-top:18px">
  <div class="card-h" style="align-items:flex-start"><div><div class="eyebrow">Scheduled check</div><h3>Set up the cron job (once, on the server)</h3><p class="text-xs muted mt-1">Runs every 15 minutes: re-checks every product's alert state and clears read notifications older than the setting above. Two runs can never overlap.</p></div></div>
  <div class="cron-box"><code id="cronLine">*/15 * * * * cd <?= e(app()->basePath()) ?> &amp;&amp; php vayu inventory:check --quiet &gt;&gt; storage/logs/cron.log 2&gt;&amp;1</code>
    <button type="button" class="btn sm" style="padding:7px 11px;font-size:12px" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('cronLine').innerText).then(()=>{this.lastChild.textContent='Copied'})"><i data-lucide="copy" style="width:14px"></i><span>Copy</span></button></div>
  <ul class="cron-steps">
    <li><b>cPanel:</b> Cron Jobs → Common settings “Once per 15 minutes” → paste the command part (after the five <code>*</code> fields). Use the full PHP path if needed, e.g. <code>/usr/local/bin/php</code>.</li>
    <li><b>VPS / Linux:</b> run <code>crontab -e</code> and paste the whole line.</li>
    <li><b>Test it by hand:</b> <code>php vayu inventory:check --dry-run</code> shows what would change without saving.</li>
  </ul>
</div>
<?php
$scripts = <<<'JS'
<script>
document.querySelectorAll('.click-row').forEach(function(r){
  r.addEventListener('click',function(e){if(!e.target.closest('a,button'))location.href=r.dataset.href});
  r.addEventListener('keydown',function(e){if(e.key==='Enter')location.href=r.dataset.href});
});
</script>
JS;
include __DIR__ . '/../partials/footer.php';
?>
