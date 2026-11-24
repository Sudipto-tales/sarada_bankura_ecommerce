<?php
use App\Services\Order\ReturnService as RS;

$active = 'orders.returns';
$title = 'Returns & replacements';
include __DIR__ . '/../orders/_helpers.php';
$url = static function (array $over = []) use ($f): string {
    $q = array_filter(array_merge($f, $over), static fn($v) => $v !== null && $v !== '');

    return route('admin.returns') . ($q ? '?' . http_build_query($q) : '');
};
$all = array_sum($counts);
$hasFilters = $f['status'] !== '' || $f['type'] !== '' || $f['q'] !== '';
$retStatus = static fn(string $s): string => '<span class="ord-st ord-' . e(RS::TONES[$s] ?? 'grey') . '">' . e(RS::SHORT[$s] ?? ucfirst($s)) . '</span>';
$nextLabel = ['submitted' => 'Review', 'approved' => 'Mark received', 'received' => 'Complete'];

include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs">Orders <i data-lucide="chevron-right"></i> Returns &amp; replacements</div><h1>Returns<span style="color:#dca71f">.</span></h1></div>
  <div class="flex gap-2 flex-wrap">
    <a class="btn" href="<?= route('admin.return-policies') ?>"><i data-lucide="shield-check" style="width:16px"></i>Return policies</a>
    <a class="btn gold" href="<?= route('admin.orders') ?>?status=delivered"><i data-lucide="undo-2" style="width:16px"></i>New request from an order</a>
  </div>
</div>

<div class="statstrip">
  <a class="stat glass clickable<?= $f['status'] === 'submitted' ? ' on' : '' ?>" href="<?= e($url(['status' => 'submitted', 'page' => null])) ?>"><span class="metric-icon" style="background:#fff0d6;color:#a2632a"><i data-lucide="inbox"></i></span><div><small>To review</small><b><?= $counts['submitted'] ?></b><em>Accept or decline</em></div></a>
  <a class="stat glass clickable<?= $f['status'] === 'approved' ? ' on' : '' ?>" href="<?= e($url(['status' => 'approved', 'page' => null])) ?>"><span class="metric-icon" style="background:#e3eef8;color:#3d6a92"><i data-lucide="truck"></i></span><div><small>Awaiting goods</small><b><?= $counts['approved'] ?></b><em>Approved, not back yet</em></div></a>
  <a class="stat glass clickable<?= $f['status'] === 'received' ? ' on' : '' ?>" href="<?= e($url(['status' => 'received', 'page' => null])) ?>"><span class="metric-icon" style="background:#efe7f6;color:#6f4a92"><i data-lucide="package-open"></i></span><div><small>To complete</small><b><?= $counts['received'] ?></b><em>Credit or send replacement</em></div></a>
  <div class="stat glass"><span class="metric-icon" style="background:#e4f4e9;color:#267a55"><i data-lucide="indian-rupee"></i></span><div><small>Credited this month</small><b><?= $inr0($kpi['credited_month']) ?></b><em><?= $kpi['refund_due'] ? '<a class="ord-due" href="' . route('admin.orders') . '?payment=refund">' . $kpi['refund_due'] . ' refund' . ($kpi['refund_due'] === 1 ? '' : 's') . ' to send</a>' : 'No refunds pending' ?></em></div></div>
</div>

<nav class="ord-chips" aria-label="Filter by status">
  <a class="<?= $f['status'] === '' ? 'on' : '' ?>" href="<?= e($url(['status' => null, 'page' => null])) ?>">All <b><?= $all ?></b></a>
  <?php foreach (RS::SHORT as $k => $l): ?>
  <a class="<?= $f['status'] === $k ? 'on' : '' ?><?= $counts[$k] ? '' : ' zero' ?>" href="<?= e($url(['status' => $k, 'page' => null])) ?>"><i class="dot ord-<?= RS::TONES[$k] ?>"></i><?= $l ?> <b><?= $counts[$k] ?></b></a>
  <?php endforeach; ?>
</nav>

<form class="card toolbar-card glass" method="get" action="<?= route('admin.returns') ?>" id="retFilters">
  <?php if ($f['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($f['status']) ?>"><?php endif; ?>
  <select class="btn" name="type" aria-label="Type"><option value="">Returns &amp; replacements</option><?php foreach (RS::TYPES as $k => $l): ?><option value="<?= $k ?>"<?= $f['type'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <?php if ($hasFilters): ?><a class="linkbtn" href="<?= route('admin.returns') ?>"><i data-lucide="x"></i>Clear</a><?php endif; ?>
  <label class="search-in"><i data-lucide="search"></i><input name="q" value="<?= e($f['q']) ?>" placeholder="Request no., order no., customer or mobile…" autocomplete="off"></label>
</form>

<div class="card" style="padding:0">
  <div class="tablewrap" style="border:0;border-radius:24px">
    <table class="data ord-table">
      <thead><tr><th>Request</th><th>Order &amp; customer</th><th>Items</th><th>Type</th><th>Status</th><th>Next step</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $show = route('admin.returns.show', ['id' => $r['id']]); $st = (string) $r['status']; ?>
        <tr>
          <td><a href="<?= e($show) ?>" class="ord-no"><b><?= e((string) $r['request_number']) ?></b></a><small class="block muted text-xs"><?= $when($r['created_at'], 'j M, g:i a') ?></small></td>
          <td><a href="<?= route('admin.orders.show', ['id' => $r['order_id']]) ?>"><b><?= e((string) $r['order_number']) ?></b></a><small class="block muted text-xs"><?= e((string) $r['customer_name']) ?> · <?= e(\App\Services\Customer\Phone::format((string) $r['customer_phone'])) ?></small></td>
          <td class="ret-itemcell"><?php foreach (array_slice($r['items'], 0, 2) as $it): $q = $it['received_quantity'] ?? $it['approved_quantity'] ?? $it['requested_quantity']; ?>
            <span><?= e((string) $it['product_name']) ?> <b>× <?= $qtyFmt($q) ?> <?= e((string) $it['unit']) ?></b></span><?php endforeach; ?>
            <?php if (count($r['items']) > 2): ?><small class="muted">+<?= count($r['items']) - 2 ?> more</small><?php endif; ?>
            <small class="block muted text-xs"><?= e((string) $r['reason']) ?></small></td>
          <td><span class="ret-type <?= $r['request_type'] === 'return' ? 'refund' : 'swap' ?>"><i data-lucide="<?= $r['request_type'] === 'return' ? 'undo-2' : 'repeat' ?>"></i><?= e(RS::TYPES[$r['request_type']] ?? $r['request_type']) ?></span>
            <?php if ((float) $r['credited'] > 0): ?><small class="block text-xs muted"><?= $inr($r['credited']) ?> credited</small><?php endif; ?></td>
          <td><?= $retStatus($st) ?></td>
          <td><?php if (isset($nextLabel[$st])): ?><a class="btn ord-next" href="<?= e($show) ?>"><?= e($nextLabel[$st]) ?><i data-lucide="arrow-right" style="width:14px"></i></a><?php else: ?><a class="linkbtn" href="<?= e($show) ?>">View</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="6"><div class="cu-empty">
          <span class="metric-icon"><i data-lucide="<?= $hasFilters ? 'search-x' : 'undo-2' ?>"></i></span>
          <?php if ($hasFilters): ?><b>No requests match</b><small>Try another status or clear the search.</small><a class="btn" href="<?= route('admin.returns') ?>">Clear filters</a>
          <?php else: ?><b>No returns or replacements yet</b><small>When a customer asks to return or replace something, open their delivered order and use “Return / replace”. Set which products can come back on the Return policies page.</small>
            <a class="btn" href="<?= route('admin.return-policies') ?>"><i data-lucide="shield-check" style="width:16px"></i>Set return policies</a><?php endif; ?>
        </div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($pages > 1): ?>
<div class="pager">
  <?php if ($page > 1): ?><a class="btn" href="<?= e($url(['page' => $page - 1 > 1 ? $page - 1 : null])) ?>"><i data-lucide="chevron-left" style="width:15px"></i>Newer</a><?php endif; ?>
  <span class="text-xs muted">Page <?= $page ?> of <?= $pages ?> · <?= number_format($total) ?> requests</span>
  <?php if ($page < $pages): ?><a class="btn" href="<?= e($url(['page' => $page + 1])) ?>">Older<i data-lucide="chevron-right" style="width:15px"></i></a><?php endif; ?>
</div>
<?php endif; ?>
<?php
$scripts = ($scripts ?? '') . <<<'JS'
<script>
(function(){var f=document.getElementById('retFilters');f.querySelectorAll('select').forEach(function(s){s.addEventListener('change',function(){f.submit()})});})();
</script>
JS;
include __DIR__ . '/../partials/footer.php';
