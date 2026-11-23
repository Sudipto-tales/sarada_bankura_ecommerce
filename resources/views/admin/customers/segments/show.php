<?php
use App\Services\Customer\Phone;
use App\Services\Customer\SegmentMatcher;

$active = 'customers.segments';
$title = (string) $seg['name'];
include __DIR__ . '/../_helpers.php';
$sid = (int) $seg['id'];
$isStatic = $seg['type'] === 'static';
$url = static fn(array $q = []): string => route('admin.customers.segments.show', ['id' => $sid]) . ($q ? '?' . http_build_query($q) : '');

include __DIR__ . '/../../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs"><a href="<?= route('admin.customers') ?>">Customers</a> <i data-lucide="chevron-right"></i> <a href="<?= route('admin.customers.segments') ?>">Segments</a> <i data-lucide="chevron-right"></i> <?= e((string) $seg['name']) ?></div>
    <h1><?= e((string) $seg['name']) ?><span style="color:#dca71f">.</span></h1></div>
  <div class="flex gap-2">
    <a class="btn" href="<?= route('admin.customers') ?>?segment=<?= $sid ?>&amp;export=csv"><i data-lucide="download" style="width:16px"></i>Export</a>
    <?php if (!$isStatic): ?>
    <form method="post" action="<?= route('admin.customers.segments.refresh', ['id' => $sid]) ?>"><?= csrf_field() ?><button class="btn" type="submit" title="Last refreshed <?= $cuDate($seg['refreshed_at'], 'j M, H:i') ?>"><i data-lucide="refresh-cw" style="width:16px"></i>Refresh</button></form>
    <?php endif; ?>
    <a class="btn gold" href="<?= route('admin.customers.segments.edit', ['id' => $sid]) ?>"><i data-lucide="pencil" style="width:16px"></i>Edit</a>
  </div>
</div>

<section class="card seg-summary">
  <div>
    <div class="eyebrow"><?= $isStatic ? 'Static segment' : 'Dynamic segment · ' . ($seg['match_type'] === 'any' ? 'matches any rule' : 'matches all rules') ?><?= (int) $seg['is_active'] ? '' : ' · paused' ?></div>
    <?php if ($seg['description']): ?><p class="text-sm" style="margin:6px 0 0"><?= e((string) $seg['description']) ?></p><?php endif; ?>
    <div class="seg-rules" style="margin-top:12px">
      <?php if ($isStatic): ?><span class="seg-rule"><i data-lucide="hand"></i>Members added by hand</span>
      <?php else: foreach ($conditions as $j => $r): ?>
        <?php if ($j > 0): ?><span class="seg-join"><?= $seg['match_type'] === 'any' ? 'or' : 'and' ?></span><?php endif; ?>
        <span class="seg-rule"><?= e(SegmentMatcher::describe($r, $tagNames)) ?></span>
      <?php endforeach; endif; ?>
    </div>
  </div>
  <div class="cu-stats four">
    <div class="t-gold"><small>Members</small><b><?= number_format($total) ?></b></div>
    <div class="t-blue"><small>Online accounts</small><b><?= number_format((int) ($stats['online'] ?? 0)) ?></b></div>
    <div class="t-green"><small>Subscribed</small><b><?= number_format((int) ($stats['subscribed'] ?? 0)) ?></b></div>
    <div class="t-sand"><small>Total spent</small><b><?= $cuMoney($stats['spent'] ?? 0) ?></b></div>
  </div>
</section>

<?php if ($isStatic): ?>
<form class="card seg-add" method="post" action="<?= route('admin.customers.segments.members.add', ['id' => $sid]) ?>">
  <?= csrf_field() ?>
  <div class="card-h"><div><div class="eyebrow">Hand-picked</div><h3>Add customers</h3></div><button class="btn gold" type="submit" id="addBtn" disabled><i data-lucide="user-plus" style="width:16px"></i>Add selected</button></div>
  <?php if ($candidates): ?>
  <label class="search-in" style="margin:0 0 10px;max-width:420px"><i data-lucide="search"></i><input id="candSearch" placeholder="Filter by name or mobile…" autocomplete="off"></label>
  <div class="seg-cands" id="cands">
    <?php foreach ($candidates as $k): ?>
    <label data-search="<?= e(mb_strtolower($k['name'] . ' ' . $k['phone'])) ?>"><input type="checkbox" name="customers[]" value="<?= (int) $k['id'] ?>"><span><b><?= e((string) $k['name']) ?></b><small><?= e(Phone::format((string) $k['phone'])) ?></small></span></label>
    <?php endforeach; ?>
  </div>
  <?php else: ?><p class="text-sm muted" style="margin:0">Every customer is already in this segment.</p><?php endif; ?>
</form>
<?php endif; ?>

<div class="card" style="padding:0">
  <div class="card-h" style="padding:20px 20px 0"><div><div class="eyebrow">Members</div><h3><?= number_format($total) ?> customer<?= $total === 1 ? '' : 's' ?></h3></div>
    <?php if (!$isStatic && $seg['refreshed_at']): ?><span class="text-xs muted">Cache refreshed <?= $cuDate($seg['refreshed_at'], 'j M Y, H:i') ?></span><?php endif; ?></div>
  <div class="tablewrap" style="border:0;border-radius:0 0 24px 24px;margin-top:12px">
    <table class="data">
      <thead><tr><th>Customer</th><th>Mobile</th><th>Type</th><th>City</th><th>Orders</th><th>Spent</th><th>Last order</th><th>Status</th><?= $isStatic ? '<th></th>' : '' ?></tr></thead>
      <tbody>
        <?php foreach ($members as $c): $cid = (int) $c['id']; ?>
        <tr>
          <td><a class="flex items-center gap-3" href="<?= route('admin.customers.show', ['id' => $cid]) ?>"><?= $cuAvatar($c) ?><span><b><?= e((string) $c['name']) ?></b><small class="block muted text-xs"><?= e((string) ($c['company'] ?: ($c['email'] ?: ''))) ?></small></span></a></td>
          <td class="whitespace-nowrap"><?= e(Phone::format((string) $c['phone'])) ?></td>
          <td><?= $cuType((string) $c['customer_type']) ?></td>
          <td><?= e((string) ($cities[$cid] ?? '—')) ?></td>
          <td><?= (int) $c['total_orders'] ?></td>
          <td><?= $cuMoney($c['total_spent']) ?></td>
          <td><?= $cuDate($c['last_order_at']) ?></td>
          <td><?= $cuStatus((string) $c['status']) ?></td>
          <?php if ($isStatic): ?><td><form method="post" action="<?= route('admin.customers.segments.members.delete', ['id' => $sid, 'customerId' => $cid]) ?>"><?= csrf_field() ?><button class="iconbtn danger" type="submit" title="Remove from segment" aria-label="Remove from segment"><i data-lucide="user-minus"></i></button></form></td><?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php if (!$members): ?>
        <tr><td colspan="<?= $isStatic ? 9 : 8 ?>"><div class="cu-empty sm"><b>No one matches yet</b><small><?= $isStatic ? 'Add customers above.' : 'Customers join automatically when they match the rules.' ?></small></div></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($pages > 1): ?>
<div class="pager">
  <?php if ($page > 1): ?><a class="btn" href="<?= e($url($page - 1 > 1 ? ['page' => $page - 1] : [])) ?>"><i data-lucide="chevron-left" style="width:15px"></i>Previous</a><?php endif; ?>
  <span class="text-xs muted">Page <?= $page ?> of <?= $pages ?></span>
  <?php if ($page < $pages): ?><a class="btn" href="<?= e($url(['page' => $page + 1])) ?>">Next<i data-lucide="chevron-right" style="width:15px"></i></a><?php endif; ?>
</div>
<?php endif; ?>
<div style="margin-top:18px;text-align:right"><button type="button" class="btn ghost danger-text" data-del="<?= e(route('admin.customers.segments.delete', ['id' => $sid])) ?>" data-del-name="<?= e((string) $seg['name']) ?>"><i data-lucide="trash-2" style="width:16px"></i>Delete segment</button></div>
<?php
$scripts = <<<'JS'
<script>
(function(){
  msBindDelete();
  var s=document.getElementById('candSearch'),list=document.getElementById('cands'),btn=document.getElementById('addBtn');
  if(s)s.addEventListener('input',function(){var q=s.value.trim().toLowerCase().replace(/\s+/g,'');list.querySelectorAll('label').forEach(function(l){l.hidden=q&&l.dataset.search.replace(/\s+/g,'').indexOf(q)<0;});});
  if(list)list.addEventListener('change',function(){var n=list.querySelectorAll(':checked').length;btn.disabled=!n;btn.lastChild.textContent=n?'Add '+n+' selected':'Add selected';});
})();
</script>
JS;
include __DIR__ . '/../../partials/footer.php';
