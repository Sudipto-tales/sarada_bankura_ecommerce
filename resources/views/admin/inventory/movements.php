<?php
use App\Services\Inventory\InventoryService as Inv;

$active = 'inventory.movements';
$title = 'Stock movements';
$rows = $rows ?? [];
$filters = $filters ?? ['from' => '', 'to' => '', 'product' => 0, 'type' => '', 'source' => ''];
$types = $types ?? [];
$sources = $sources ?? [];
$productsList = $productsList ?? [];
$page = $page ?? 1;
$pages = $pages ?? 1;
$total = $total ?? 0;
$typeTone = ['opening' => 'purple', 'stock_in' => 'green', 'adjust_in' => 'blue', 'adjust_out' => 'red', 'import' => 'gold', 'sale' => 'red', 'return_in' => 'green'];
$sourceLabel = ['manual' => 'Manual', 'import' => 'Bulk upload', 'opening' => 'Setup', 'system' => 'System', 'order' => 'Order'];
$query = array_filter($filters, static fn($v) => $v !== '' && $v !== 0);
$url = static fn(array $extra = []) => route('admin.inventory.movements') . (($q = http_build_query(array_filter($extra + $query, static fn($v) => $v !== '' && $v !== 0 && $v !== null))) ? '?' . $q : '');
include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs">Inventory <i data-lucide="chevron-right"></i> Stock movements</div><h1>Stock movements<span style="color:#dca71f">.</span></h1></div>
  <?php if ($total): ?><a class="btn" href="<?= e($url(['export' => 'csv'])) ?>"><i data-lucide="download" style="width:16px"></i>Export<?= $query ? ' filtered' : '' ?> CSV</a><?php endif; ?>
</div>

<form method="get" action="<?= route('admin.inventory.movements') ?>" class="card toolbar-card">
  <label class="text-xs muted" style="display:flex;align-items:center;gap:6px">From <input type="date" name="from" value="<?= e($filters['from']) ?>" class="btn" style="padding:8px 10px"></label>
  <label class="text-xs muted" style="display:flex;align-items:center;gap:6px">To <input type="date" name="to" value="<?= e($filters['to']) ?>" class="btn" style="padding:8px 10px"></label>
  <select name="product" class="btn" style="max-width:240px"><option value="">All products</option><?php foreach ($productsList as $p): ?><option value="<?= (int) $p['id'] ?>"<?= (int) $filters['product'] === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
  <select name="type" class="btn"><option value="">All movements</option><?php foreach ($types as $k => $l): ?><option value="<?= e($k) ?>"<?= $filters['type'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <select name="source" class="btn"><option value="">All sources</option><?php foreach ($sources as $s): ?><option value="<?= e($s) ?>"<?= $filters['source'] === $s ? ' selected' : '' ?>><?= e($sourceLabel[$s] ?? $s) ?></option><?php endforeach; ?></select>
  <button type="submit" class="btn gold"><i data-lucide="filter" style="width:15px"></i>Filter</button>
  <?php if ($query): ?><a class="btn ghost" href="<?= route('admin.inventory.movements') ?>">Clear</a><?php endif; ?>
</form>

<div class="card" style="padding:0">
  <div class="card-h" style="padding:18px 20px 4px;align-items:flex-start">
    <div><div class="eyebrow">Audit trail</div><h3><?= number_format($total) ?> movement<?= $total === 1 ? '' : 's' ?><?= $query ? ' match' : '' ?></h3><p class="text-xs muted mt-1">Every change to physical stock, newest first. Click a row for reference, notes and unit conversion.</p></div>
  </div>
  <?php if (!$rows): ?>
    <div class="nf-empty"><div class="nf-bell"><i data-lucide="history"></i></div><b><?= $query ? 'No movements match these filters' : 'No stock movements yet' ?></b><small><?= $query ? 'Try a wider date range or another product.' : 'Receiving, adjusting and bulk uploads are recorded here.' ?></small></div>
  <?php else: ?>
  <div class="tablewrap" style="border-radius:0 0 24px 24px;border-left:0;border-right:0;border-bottom:0;margin-top:12px">
    <table class="data mv-table">
      <thead><tr><th>Date</th><th>Product</th><th>Movement</th><th style="text-align:right">Quantity</th><th class="hide-sm">Before → after</th><th class="hide-sm">By</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $i => $m): $chg = (float) $m['qty_change']; $has = $m['reason'] || $m['reference_no'] || $m['notes'] || $m['entered']; ?>
        <tr class="mv-row<?= $has ? ' has-more' : '' ?>" <?= $has ? 'data-toggle="mv-' . $i . '" tabindex="0" aria-expanded="false"' : '' ?>>
          <td style="white-space:nowrap"><b style="font-size:13px"><?= date('d M Y', strtotime((string) $m['created_at'])) ?></b><small class="block muted text-xs"><?= date('H:i', strtotime((string) $m['created_at'])) ?></small></td>
          <td><?php if ($m['name']): ?><a href="<?= e($url(['product' => (int) $m['product_id'], 'page' => null])) ?>" style="color:inherit;font-weight:700"><?= e((string) $m['name']) ?></a><small class="block muted text-xs"><?= e((string) $m['sku']) ?></small><?php else: ?><span class="muted">Deleted product</span><?php endif; ?></td>
          <td><span class="mv-pill <?= $typeTone[$m['type']] ?? '' ?>"><?= e($types[$m['type']] ?? $m['type']) ?></span><?php if ($m['reason']): ?><small class="block muted text-xs mt-1"><?= e((string) $m['reason']) ?></small><?php endif; ?></td>
          <td style="text-align:right;white-space:nowrap"><b class="<?= $chg > 0 ? 'text-in' : ($chg < 0 ? 'text-out' : 'muted') ?>"><?= $chg > 0 ? '+' : ($chg < 0 ? '−' : '') ?><?= Inv::qty(abs($chg)) ?></b> <span class="muted text-xs"><?= e((string) $m['unit']) ?></span></td>
          <td class="hide-sm muted" style="white-space:nowrap"><?= Inv::qty((float) $m['qty_before']) ?> → <b style="color:#2f2b23"><?= Inv::qty((float) $m['qty_after']) ?></b></td>
          <td class="hide-sm"><span class="text-xs"><?= e($sourceLabel[$m['source']] ?? $m['source']) ?></span><small class="block muted text-xs">Admin</small></td>
          <td style="width:30px"><?php if ($has): ?><i data-lucide="chevron-down" class="mv-chev" style="width:16px;color:#a99c86"></i><?php endif; ?></td>
        </tr>
        <?php if ($has): ?>
        <tr class="mv-detail hidden" id="mv-<?= $i ?>"><td colspan="7">
          <div class="mv-grid">
            <?php if ($m['reference_no']): ?><div><small>Reference</small><b><?= e((string) $m['reference_no']) ?></b></div><?php endif; ?>
            <?php if ($m['reason']): ?><div><small>Reason</small><b><?= e((string) $m['reason']) ?></b></div><?php endif; ?>
            <?php if ($m['entered']): ?><div><small>Entered as</small><b><?= e($m['entered']) ?> = <?= Inv::qty(abs($chg)) ?> <?= e((string) $m['unit']) ?></b></div><?php endif; ?>
            <?php if ($m['notes']): ?><div style="grid-column:1/-1"><small>Notes</small><b style="font-weight:600"><?= nl2br(e((string) $m['notes'])) ?></b></div><?php endif; ?>
          </div>
        </td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php if ($pages > 1): ?>
<div class="pager">
  <?php if ($page > 1): ?><a class="btn" href="<?= e($url(['page' => $page - 1 > 1 ? $page - 1 : null])) ?>"><i data-lucide="chevron-left" style="width:15px"></i>Newer</a><?php endif; ?>
  <span class="text-xs muted">Page <?= $page ?> of <?= $pages ?></span>
  <?php if ($page < $pages): ?><a class="btn" href="<?= e($url(['page' => $page + 1])) ?>">Older<i data-lucide="chevron-right" style="width:15px"></i></a><?php endif; ?>
</div>
<?php endif; ?>
<?php
$scripts = <<<'JS'
<script>
document.querySelectorAll('.mv-row.has-more').forEach(function(r){
  function t(){var d=document.getElementById(r.dataset.toggle),open=d.classList.toggle('hidden')===false;r.classList.toggle('open',open);r.setAttribute('aria-expanded',open?'true':'false')}
  r.addEventListener('click',function(e){if(!e.target.closest('a'))t()});
  r.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();t()}});
});
</script>
JS;
include __DIR__ . '/../partials/footer.php';
?>
