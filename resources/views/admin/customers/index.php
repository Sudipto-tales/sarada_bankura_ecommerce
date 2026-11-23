<?php
use App\Services\Customer\CustomerService as CS;
use App\Services\Customer\Phone;

$active = 'customers';
$title = 'Customers';
$f = $filters;
include __DIR__ . '/_helpers.php';
$url = static function (array $over = []) use ($f): string {
    $q = array_filter(array_merge($f, $over), static fn($v) => $v !== null && $v !== '' && $v !== 'recent');

    return route('admin.customers') . ($q ? '?' . http_build_query($q) : '');
};
$hasFilters = $f['q'] !== '' || $f['type'] !== '' || $f['status'] !== '' || $f['tag'] !== '' || $f['online'] !== '' || $f['segment'] !== '';
$segName = '';
foreach ($segments as $s) {
    if ((string) $s['id'] === $f['segment']) $segName = $s['name'];
}

include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs">Customers <i data-lucide="chevron-right"></i> All customers</div><h1>Customers<span style="color:#dca71f">.</span></h1></div>
  <div class="flex gap-2">
    <a class="btn" href="<?= e($url(['export' => 'csv', 'page' => null])) ?>"><i data-lucide="download" style="width:16px"></i>Export</a>
    <a class="btn gold" href="<?= route('admin.customers.create') ?>"><i data-lucide="user-plus" style="width:16px"></i>Add customer</a>
  </div>
</div>

<nav class="segtabs" aria-label="Segments">
  <a class="<?= $f['segment'] === '' ? 'on' : '' ?>" href="<?= e($url(['segment' => null, 'page' => null])) ?>">All customers</a>
  <?php foreach (array_slice($segments, 0, 7) as $s): ?>
  <a class="<?= (string) $s['id'] === $f['segment'] ? 'on' : '' ?>" href="<?= e($url(['segment' => $s['id'], 'page' => null])) ?>"><?= e((string) $s['name']) ?> <span class="cu-count"><?= (int) $s['member_count'] ?></span></a>
  <?php endforeach; ?>
  <a href="<?= route('admin.customers.segments') ?>" title="Manage segments"><i data-lucide="sliders-horizontal" style="width:14px;vertical-align:-2px"></i></a>
</nav>

<div class="statstrip">
  <div class="stat glass"><span class="metric-icon"><i data-lucide="users"></i></span><div><small>Customers</small><b><?= number_format($kpi['total'] ?? 0) ?></b><em><?= number_format($kpi['trade'] ?? 0) ?> trade (builder, dealer, architect)</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#e3eef8;color:#3d6a92"><i data-lucide="globe"></i></span><div><small>Online accounts</small><b><?= number_format($kpi['online'] ?? 0) ?></b><em><?= number_format(max(0, ($kpi['total'] ?? 0) - ($kpi['online'] ?? 0))) ?> offline only</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#e4f4e9;color:#267a55"><i data-lucide="sparkles"></i></span><div><small>New this month</small><b><?= number_format($kpi['new_month'] ?? 0) ?></b><em>Since 1 <?= date('M') ?></em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#fff0bf;color:#9b6c0a"><i data-lucide="mail-check"></i></span><div><small>Subscribed</small><b><?= number_format($kpi['subscribed'] ?? 0) ?></b><em>Agreed to offers &amp; updates</em></div></div>
</div>

<form class="card toolbar-card glass" method="get" action="<?= route('admin.customers') ?>" id="cuFilters">
  <?php if ($f['segment'] !== ''): ?><input type="hidden" name="segment" value="<?= e($f['segment']) ?>"><?php endif; ?>
  <select class="btn" name="type" aria-label="Customer type"><option value="">All types</option><?php foreach (CS::TYPES as $k => $l): ?><option value="<?= $k ?>"<?= $f['type'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <select class="btn" name="status" aria-label="Status"><option value="">All statuses</option><?php foreach (CS::STATUSES as $k => $l): ?><option value="<?= $k ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <select class="btn" name="online" aria-label="Online account"><option value="">Online &amp; offline</option><option value="yes"<?= $f['online'] === 'yes' ? ' selected' : '' ?>>Has online account</option><option value="no"<?= $f['online'] === 'no' ? ' selected' : '' ?>>Offline only</option></select>
  <select class="btn" name="tag" aria-label="Tag"><option value="">All tags</option><?php foreach ($allTags as $t): ?><option value="<?= (int) $t['id'] ?>"<?= $f['tag'] === (string) $t['id'] ? ' selected' : '' ?>><?= e((string) $t['name']) ?></option><?php endforeach; ?></select>
  <select class="btn" name="sort" aria-label="Sort"><?php foreach (['recent' => 'Newest first', 'name' => 'Name A–Z', 'spent' => 'Highest spend', 'last_order' => 'Recent order'] as $k => $l): ?><option value="<?= $k ?>"<?= $f['sort'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  <?php if ($hasFilters): ?><a class="linkbtn" href="<?= route('admin.customers') ?>"><i data-lucide="x"></i>Clear</a><?php endif; ?>
  <label class="search-in"><i data-lucide="search"></i><input name="q" value="<?= e($f['q']) ?>" placeholder="Name, mobile, email or firm…" autocomplete="off"></label>
</form>

<div class="card" style="padding:0">
  <div class="card-h" style="padding:20px 20px 0;align-items:flex-start">
    <div><div class="eyebrow"><?= $segName !== '' ? 'Segment' : 'Customer book' ?></div><h3><?= $segName !== '' ? e($segName) : 'Everyone you sell to' ?></h3><p class="text-xs muted mt-1">Showroom walk-ins and online accounts in one list — the mobile number links them.</p></div>
    <span class="text-xs muted"><?= number_format($total) ?> customer<?= $total === 1 ? '' : 's' ?></span>
  </div>
  <div class="tablewrap" style="border:0;border-radius:0 0 24px 24px;margin-top:12px">
    <table class="data cu-table">
      <thead><tr><th>Customer</th><th>Mobile</th><th>Type</th><th>Location</th><th>Tags</th><th>Orders</th><th>Spent</th><th>Last order</th><th>Lifecycle</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $c): $cid = (int) $c['id']; $a = $addresses[$cid] ?? null; $show = route('admin.customers.show', ['id' => $cid]); ?>
        <tr class="click-row" data-href="<?= e($show) ?>">
          <td><a class="flex items-center gap-3" href="<?= e($show) ?>"><?= $cuAvatar($c) ?>
            <span class="min-w-0"><b class="cu-name"><?= e((string) $c['name']) ?><?php if ((int) $c['is_online']): ?> <i data-lucide="globe" class="cu-online" aria-label="Online account"></i><?php endif; ?></b>
            <small class="block muted text-xs"><?= e((string) ($c['company'] ?: ($c['email'] ?: 'No email'))) ?></small></span></a></td>
          <td class="whitespace-nowrap"><?= e(Phone::format((string) $c['phone'])) ?></td>
          <td><?= $cuType((string) $c['customer_type']) ?></td>
          <td><?= $a ? e(CS::shortAddress($a)) : '<span class="muted text-xs">No address</span>' ?></td>
          <td><span class="cu-tags"><?php foreach (array_slice($tags[$cid] ?? [], 0, 3) as $t) echo $cuTag($t); ?><?php if (count($tags[$cid] ?? []) > 3): ?><span class="muted text-xs">+<?= count($tags[$cid]) - 3 ?></span><?php endif; ?></span></td>
          <td><?= (int) $c['total_orders'] ?: '<span class="muted">0</span>' ?></td>
          <td><?= $cuMoney($c['total_spent']) ?></td>
          <td class="whitespace-nowrap"><?= $cuDate($c['last_order_at']) ?></td>
          <td><?= $cuLife((string) $c['lifecycle']) ?><?php if ((int) $c['marketing_consent']): ?> <i data-lucide="mail-check" class="cu-sub" aria-label="Subscribed"></i><?php endif; ?></td>
          <td><?= $cuStatus((string) $c['status']) ?></td>
          <td><span class="row-actions">
            <a class="iconbtn" title="Edit" href="<?= route('admin.customers.edit', ['id' => $cid]) ?>"><i data-lucide="pencil"></i></a>
            <button type="button" class="iconbtn danger" title="Delete" data-del="<?= e(route('admin.customers.delete', ['id' => $cid])) ?>" data-del-name="<?= e((string) $c['name']) ?>"><i data-lucide="trash-2"></i></button>
          </span></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr><td colspan="11"><div class="cu-empty">
          <span class="metric-icon"><i data-lucide="<?= $hasFilters ? 'search-x' : 'users' ?>"></i></span>
          <?php if ($hasFilters): ?><b>No customers match these filters</b><small>Try another segment or clear the search.</small><a class="btn" href="<?= route('admin.customers') ?>">Clear filters</a>
          <?php else: ?><b>No customers yet</b><small>Add showroom customers here; online accounts with the same mobile link up automatically.</small><a class="btn gold" href="<?= route('admin.customers.create') ?>"><i data-lucide="user-plus" style="width:16px"></i>Add your first customer</a><?php endif; ?>
        </div></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($pages > 1): ?>
<div class="pager">
  <?php if ($page > 1): ?><a class="btn" href="<?= e($url(['page' => $page - 1 > 1 ? $page - 1 : null])) ?>"><i data-lucide="chevron-left" style="width:15px"></i>Previous</a><?php endif; ?>
  <span class="text-xs muted">Page <?= $page ?> of <?= $pages ?></span>
  <?php if ($page < $pages): ?><a class="btn" href="<?= e($url(['page' => $page + 1])) ?>">Next<i data-lucide="chevron-right" style="width:15px"></i></a><?php endif; ?>
</div>
<?php endif; ?>
<?php
$scripts = <<<'JS'
<script>
(function(){
  var f=document.getElementById('cuFilters');
  f.querySelectorAll('select').forEach(function(s){s.addEventListener('change',function(){f.submit()})});
  document.querySelectorAll('.click-row').forEach(function(r){r.addEventListener('click',function(e){if(!e.target.closest('a,button'))location.href=r.dataset.href})});
  msBindDelete();
})();
</script>
JS;
include __DIR__ . '/../partials/footer.php';
