<?php
use App\Services\Inventory\InventoryService as Inv;

$active = 'inventory.overview';
$title = 'Stock overview';
$counts = $counts ?? ['healthy' => 0, 'low' => 0, 'out' => 0];
$byUnit = $byUnit ?? [];
$byCategory = $byCategory ?? [];
$attention = $attention ?? [];
$flow = $flow ?? [];
$recent = $recent ?? [];
$tracked = $tracked ?? 0;
$labels = ['healthy' => 'Healthy', 'low' => 'Low stock', 'out' => 'Out of stock'];
$typeIcon = ['opening' => ['flag', '#efe7f6', '#6f4a92'], 'stock_in' => ['package-plus', '#dff3e9', '#1f7a53'], 'adjust_in' => ['trending-up', '#e3eef8', '#3d6a92'], 'adjust_out' => ['trending-down', '#fbe1dc', '#b4452f'], 'import' => ['file-spreadsheet', '#fff0bf', '#91670a']];
$pct = static fn(int $n) => $tracked ? round($n / $tracked * 100, 1) : 0;
$stockUrl = route('admin.inventory.stock');
include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs">Inventory <i data-lucide="chevron-right"></i> Stock overview</div><h1>Stock overview<span style="color:#dca71f">.</span></h1></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($tracked): ?>
    <a class="btn" href="<?= route('admin.inventory') ?>?export=csv"><i data-lucide="download" style="width:16px"></i>Export inventory</a>
    <a class="btn" href="<?= $stockUrl ?>?adjust=1"><i data-lucide="sliders-horizontal" style="width:16px"></i>Adjust stock</a>
    <a class="btn gold" href="<?= $stockUrl ?>?receive=1"><i data-lucide="plus" style="width:16px"></i>Receive stock</a>
    <?php endif; ?>
  </div>
</div>
<?php if (empty($enabled)): ?><div class="notice">Inventory alerts are switched off — stock is recorded but no low / out-of-stock alerts are raised. <a class="linkbtn" href="<?= route('admin.inventory.alerts') ?>#settings">Settings →</a></div><?php endif; ?>

<?php if (!$tracked): ?>
<div class="card inv-empty">
  <div class="iconbox"><i data-lucide="boxes"></i></div>
  <h3 style="font-weight:800;font-size:17px;margin:0">No stock tracked yet</h3>
  <p class="muted text-sm" style="max-width:460px;margin:8px auto 18px">Start by entering the stock you have today. You can do it for many products at once on the Stock management page, or add a <code>stock_qty</code> column to your bulk upload.</p>
  <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
    <a class="btn gold" href="<?= $stockUrl ?>"><i data-lucide="list-checks" style="width:16px"></i>Set opening stock</a>
    <a class="btn" href="<?= route('admin.products.import') ?>"><i data-lucide="file-spreadsheet" style="width:16px"></i>Bulk upload</a>
  </div>
</div>
<?php else: ?>

<div class="statstrip">
  <a class="stat glass" href="<?= $stockUrl ?>"><span class="metric-icon"><i data-lucide="boxes"></i></span><div><small>Tracked products</small><b><?= $tracked ?></b><em>of <?= (int) $products ?> in the catalogue</em></div></a>
  <div class="stat glass"><span class="metric-icon" style="background:#e3eef8;color:#3d6a92"><i data-lucide="warehouse"></i></span><div><small>Stock on hand</small>
    <div class="unit-totals" style="margin-top:8px"><?php foreach (array_slice($byUnit, 0, 4, true) as $u => $x): ?><span><?= Inv::qty($x['qty']) ?> <?= e((string) $u) ?></span><?php endforeach; ?></div>
    <em style="margin-top:6px">Totals per unit — units are never added together</em></div></div>
  <a class="stat glass" href="<?= $stockUrl ?>?status=low"><span class="metric-icon" style="background:#ffe6c4;color:#a2632a"><i data-lucide="triangle-alert"></i></span><div><small>Low stock</small><b><?= $counts['low'] ?></b><em>At or below threshold</em></div></a>
  <a class="stat glass" href="<?= $stockUrl ?>?status=out"><span class="metric-icon" style="background:#f8d7d1;color:#b4452f"><i data-lucide="package-x"></i></span><div><small>Out of stock</small><b><?= $counts['out'] ?></b><em><?= (int) $activeAlerts ?> active alert<?= $activeAlerts === 1 ? '' : 's' ?></em></div></a>
</div>

<div class="card" style="margin-bottom:18px">
  <div class="card-h"><div><div class="eyebrow">Stock condition</div><h3>Health of tracked stock</h3></div><span class="text-xs muted"><?= $tracked ?> products</span></div>
  <div class="hbar" role="img" aria-label="<?= $counts['healthy'] ?> healthy, <?= $counts['low'] ?> low, <?= $counts['out'] ?> out of stock">
    <?php foreach (['healthy', 'low', 'out'] as $k): if (!$counts[$k]) continue; ?><span class="<?= $k ?>" style="width:<?= $pct($counts[$k]) ?>%"></span><?php endforeach; ?>
  </div>
  <div class="hlegend">
    <?php foreach (['healthy', 'low', 'out'] as $k): ?><a href="<?= $stockUrl ?>?status=<?= $k ?>"><i class="<?= $k ?>"></i><?= $labels[$k] ?> <b><?= $counts[$k] ?></b><span class="muted"><?= $pct($counts[$k]) ?>%</span></a><?php endforeach; ?>
  </div>
</div>

<div class="ov-grid">
  <div class="card">
    <div class="card-h" style="align-items:flex-start">
      <div><div class="eyebrow">Needs attention</div><h3>Low &amp; out of stock</h3><p class="text-xs muted mt-1">Out of stock first, then the products closest to running out.</p></div>
      <?php if ($attentionTotal > count($attention)): ?><a class="linkbtn" href="<?= $stockUrl ?>?status=low">All <?= $attentionTotal ?> <i data-lucide="arrow-right"></i></a><?php endif; ?>
    </div>
    <?php if (!$attention): ?>
      <div class="nf-empty"><div class="nf-bell" style="background:linear-gradient(145deg,#e6f6ec,#cdeedb);color:#1f7a53"><i data-lucide="circle-check"></i></div><b>All stock is healthy</b><small>Nothing is at or below its low-stock threshold.</small></div>
    <?php else: ?>
    <div class="tablewrap">
      <table class="data">
        <thead><tr><th>Product</th><th>Stock</th><th class="hide-sm">Threshold</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($attention as $r): $pid = (int) $r['id']; ?>
          <tr>
            <td><a class="inv-prod" href="<?= $stockUrl ?>?product=<?= $pid ?>" style="color:inherit">
              <span class="thumb"><?php if ($r['cover']): ?><img src="<?= base_url((string) $r['cover']) ?>" alt=""><?php else: ?><i data-lucide="gem"></i><?php endif; ?></span>
              <span class="min-w-0"><b style="font-size:13px"><?= e((string) $r['name']) ?></b><small><?= e((string) $r['sku']) ?></small></span></a></td>
            <td><b class="<?= $r['status'] === 'out' ? 'text-out' : 'text-low' ?>"><?= Inv::qty((float) $r['quantity']) ?></b> <span class="muted text-xs"><?= e((string) $r['unit']) ?></span></td>
            <td class="hide-sm muted"><?= Inv::qty((float) $r['threshold']) ?></td>
            <td><span class="stock-pill <?= e((string) $r['status']) ?>"><?= $labels[$r['status']] ?></span></td>
            <td style="text-align:right"><a class="btn sm" style="padding:7px 11px;font-size:12px" href="<?= $stockUrl ?>?product=<?= $pid ?>&receive=1"><i data-lucide="plus" style="width:14px"></i>Receive</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="stack" style="gap:18px">
    <div class="card">
      <div class="card-h"><div><div class="eyebrow">By unit</div><h3>Stock on hand</h3></div></div>
      <?php foreach ($byUnit as $u => $x): ?>
      <div class="ov-row"><span class="unitchip-sm"><?= e((string) $u) ?></span><b><?= Inv::qty($x['qty']) ?></b><span class="muted text-xs"><?= $x['products'] ?> product<?= $x['products'] === 1 ? '' : 's' ?></span></div>
      <?php endforeach; ?>
    </div>
    <div class="card">
      <div class="card-h"><div><div class="eyebrow">Last 30 days</div><h3>Stock flow</h3></div><a class="linkbtn" href="<?= route('admin.inventory.movements') ?>?from=<?= date('Y-m-d', strtotime('-30 days')) ?>">Movements <i data-lucide="arrow-right"></i></a></div>
      <?php if (!$flow): ?><p class="muted text-sm">No stock received or adjusted in the last 30 days.</p><?php endif; ?>
      <?php foreach ($flow as $f): ?>
      <div class="ov-row"><span class="unitchip-sm"><?= e((string) $f['unit']) ?></span>
        <span class="flowin" title="Received"><i data-lucide="arrow-down-left"></i>+<?= Inv::qty((float) $f['received']) ?></span>
        <span class="flowout" title="Removed (adjustments)"><i data-lucide="arrow-up-right"></i>−<?= Inv::qty((float) $f['removed']) ?></span>
        <span class="muted text-xs" style="margin-left:auto"><?= (int) $f['moves'] ?> move<?= (int) $f['moves'] === 1 ? '' : 's' ?></span></div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="ov-grid" style="margin-top:18px">
  <div class="card">
    <div class="card-h" style="align-items:flex-start"><div><div class="eyebrow">Activity</div><h3>Recent stock movements</h3></div><a class="linkbtn" href="<?= route('admin.inventory.movements') ?>">View all <i data-lucide="arrow-right"></i></a></div>
    <div class="hist-list" style="max-height:none">
      <?php foreach ($recent as $m): $ic = $typeIcon[$m['type']] ?? ['circle', '#efeae0', '#7d7462']; $chg = (float) $m['qty_change']; ?>
      <div class="hist-item">
        <span class="hist-ic" style="background:<?= $ic[1] ?>;color:<?= $ic[2] ?>"><i data-lucide="<?= $ic[0] ?>"></i></span>
        <div class="min-w-0"><b style="font-size:13px"><?= e((string) ($m['name'] ?? 'Deleted product')) ?></b> <span class="muted text-xs">· <?= e(Inv::TYPES[$m['type']] ?? $m['type']) ?></span>
          <small><?= date('d M, H:i', strtotime((string) $m['created_at'])) ?> · <?= Inv::qty((float) $m['qty_before']) ?> → <?= Inv::qty((float) $m['qty_after']) ?> <?= e((string) $m['unit']) ?><?= $m['reference_no'] ? ' · Ref ' . e((string) $m['reference_no']) : '' ?></small></div>
        <span class="chg <?= $chg >= 0 ? 'pos' : 'neg' ?>"><?= $chg >= 0 ? '+' : '−' ?><?= Inv::qty(abs($chg)) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-h"><div><div class="eyebrow">By category</div><h3>Where stock is low</h3></div></div>
    <?php foreach ($byCategory as $c => $x): $h = $x['products'] - $x['low'] - $x['out']; ?>
    <div class="ov-cat">
      <div class="ov-cat-h"><b><?= e((string) $c) ?></b><span class="muted text-xs"><?= $x['products'] ?> tracked<?= $x['out'] ? ' · <span class="text-out">' . $x['out'] . ' out</span>' : '' ?><?= $x['low'] ? ' · <span class="text-low">' . $x['low'] . ' low</span>' : '' ?></span></div>
      <div class="hbar thin"><?php foreach (['healthy' => $h, 'low' => $x['low'], 'out' => $x['out']] as $k => $n): if (!$n) continue; ?><span class="<?= $k ?>" style="width:<?= round($n / $x['products'] * 100, 1) ?>%"></span><?php endforeach; ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../partials/footer.php'; ?>
