<?php
$active = 'notifications';
$title = 'Notifications';
$items = $items ?? [];
$filters = $filters ?? ['type' => '', 'state' => '', 'from' => '', 'to' => ''];
$counts = $counts ?? ['all' => 0, 'unread' => 0, 'stock' => 0, 'system' => 0];
$types = $types ?? [];
$page = $page ?? 1;
$pages = $pages ?? 1;
$filtered = $filters['type'] !== '' || $filters['state'] !== '' || $filters['from'] !== '' || $filters['to'] !== '';
$pageUrl = static fn(int $p): string => route('admin.notifications') . '?' . http_build_query(array_filter($filters + ['page' => $p > 1 ? $p : null], static fn($v) => $v !== '' && $v !== null));
include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs">Workspace <i data-lucide="chevron-right"></i> Notifications</div><h1>Notifications<span style="color:#dca71f">.</span></h1></div>
  <?php if ($counts['unread']): ?>
  <form method="post" action="<?= route('admin.notifications.readAll') ?>"><?= csrf_field() ?><button class="btn gold" type="submit"><i data-lucide="check-check" style="width:16px"></i>Mark all as read</button></form>
  <?php endif; ?>
</div>

<div class="statstrip">
  <div class="stat glass"><span class="metric-icon"><i data-lucide="bell"></i></span><div><small>All notifications</small><b><?= $counts['all'] ?></b><em>Stock alerts, uploads &amp; checks</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#fff0bf;color:#9b6c0a"><i data-lucide="bell-dot"></i></span><div><small>Unread</small><b><?= $counts['unread'] ?></b><em>Waiting for you</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#ffe6c4;color:#a2632a"><i data-lucide="boxes"></i></span><div><small>Unread stock alerts</small><b><?= $counts['stock'] ?></b><em>Low &amp; out of stock</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#e3eef8;color:#3d6a92"><i data-lucide="activity"></i></span><div><small>Unread system</small><b><?= $counts['system'] ?></b><em>Uploads &amp; checks</em></div></div>
</div>

<form method="get" action="<?= route('admin.notifications') ?>" class="card toolbar-card">
  <select name="type" class="btn"><option value="">All types</option><?php foreach ($types as $k => $l): ?><option value="<?= e($k) ?>"<?= $filters['type'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <select name="state" class="btn"><option value="">Read &amp; unread</option><option value="unread"<?= $filters['state'] === 'unread' ? ' selected' : '' ?>>Unread only</option><option value="read"<?= $filters['state'] === 'read' ? ' selected' : '' ?>>Read only</option></select>
  <label class="text-xs muted" style="display:flex;align-items:center;gap:6px">From <input type="date" name="from" value="<?= e($filters['from']) ?>" class="btn" style="padding:8px 10px"></label>
  <label class="text-xs muted" style="display:flex;align-items:center;gap:6px">To <input type="date" name="to" value="<?= e($filters['to']) ?>" class="btn" style="padding:8px 10px"></label>
  <button type="submit" class="btn gold"><i data-lucide="filter" style="width:15px"></i>Filter</button>
  <?php if ($filtered): ?><a class="btn ghost" href="<?= route('admin.notifications') ?>">Clear</a><?php endif; ?>
</form>

<form method="post" action="<?= route('admin.notifications.bulk') ?>" class="card nf-page" id="nfBulk">
  <?= csrf_field() ?>
  <div class="card-h" style="align-items:center;flex-wrap:wrap">
    <div style="display:flex;align-items:center;gap:12px">
      <?php if ($items): ?><input type="checkbox" class="nf-check" style="margin:0" id="nfAll" aria-label="Select all on this page"><?php endif; ?>
      <div><div class="eyebrow">Activity</div><h3><?= number_format($total ?? 0) ?> notification<?= ($total ?? 0) === 1 ? '' : 's' ?><?= $filtered ? ' match' : '' ?></h3></div>
    </div>
    <div class="row-actions hidden" id="nfBulkBar" style="gap:8px">
      <span class="text-xs muted" id="nfSel"></span>
      <button class="btn" type="submit" name="action" value="read"><i data-lucide="check" style="width:15px"></i>Mark read</button>
      <button class="btn" type="submit" name="action" value="unread"><i data-lucide="mail" style="width:15px"></i>Mark unread</button>
      <button class="btn" type="submit" name="action" value="delete" onclick="return confirm('Delete the selected notifications?')"><i data-lucide="trash-2" style="width:15px;color:#b4452f"></i>Delete</button>
    </div>
  </div>

  <?php if (!$items): ?>
  <div class="nf-empty"><div class="nf-bell"><i data-lucide="bell-ring"></i></div><b><?= $filtered ? 'Nothing matches these filters' : 'You’re all caught up' ?></b><small><?= $filtered ? 'Try a different type or date range.' : 'Stock alerts, bulk uploads and system checks will appear here.' ?></small></div>
  <?php endif; ?>

  <?php $group = ''; foreach ($items as $n): ?>
    <?php if ($n['group'] !== $group): $group = $n['group']; ?><div class="nf-group"><?= e($group) ?></div><?php endif; ?>
    <div class="nf-item<?= $n['is_read'] ? '' : ' unread' ?>">
      <input type="checkbox" class="nf-check" name="ids[]" value="<?= (int) $n['id'] ?>" aria-label="Select “<?= e($n['title']) ?>”">
      <span class="nf-ic <?= e($n['level']) ?>"><i data-lucide="<?= e($n['icon']) ?>"></i></span>
      <a class="nf-txt" href="<?= e($n['open']) ?>" style="color:inherit;text-decoration:none">
        <b><?= e($n['title']) ?><span class="nf-typechip"><?= e($n['type_label']) ?></span></b>
        <?php if ($n['body']): ?><small><?= e($n['body']) ?></small><?php endif; ?>
        <?php if ($n['action']): ?><span class="nf-act" onclick="event.preventDefault();location.href=<?= e(json_encode($n['action']['open'])) ?>"><i data-lucide="package-plus"></i><?= e($n['action']['label']) ?></span><?php endif; ?>
      </a>
      <span class="nf-side">
        <span class="nf-ago" title="<?= e($n['when']) ?>"><?= e($n['when']) ?></span>
        <?php if (!$n['is_read']): ?><span class="nf-dot" aria-label="Unread"></span><?php endif; ?>
      </span>
    </div>
  <?php endforeach; ?>

  <?php if ($pages > 1): ?>
  <div class="pager">
    <?php if ($page > 1): ?><a class="btn" href="<?= e($pageUrl($page - 1)) ?>"><i data-lucide="chevron-left" style="width:15px"></i>Newer</a><?php endif; ?>
    <span class="text-xs muted">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn" href="<?= e($pageUrl($page + 1)) ?>">Older<i data-lucide="chevron-right" style="width:15px"></i></a><?php endif; ?>
  </div>
  <?php endif; ?>
</form>

<?php
$scripts = <<<'JS'
<script>
(function(){
  const f=document.getElementById('nfBulk');if(!f)return;
  const boxes=[...f.querySelectorAll('input[name="ids[]"]')],all=document.getElementById('nfAll'),bar=document.getElementById('nfBulkBar'),sel=document.getElementById('nfSel');
  function sync(){const n=boxes.filter(b=>b.checked).length;bar.classList.toggle('hidden',!n);sel.textContent=n+' selected';if(all){all.checked=n&&n===boxes.length;all.indeterminate=n>0&&n<boxes.length}}
  boxes.forEach(b=>b.addEventListener('change',sync));
  if(all)all.addEventListener('change',()=>{boxes.forEach(b=>b.checked=all.checked);sync()});
})();
</script>
JS;
include __DIR__ . '/../partials/footer.php';
?>
