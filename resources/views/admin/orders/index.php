<?php
use App\Services\Order\OrderService as OS;

$active = 'orders';
$title = 'Orders';
$f = $filters;
include __DIR__ . '/_helpers.php';
$url = static function (array $over = []) use ($f): string {
    $q = array_filter(array_merge($f, $over), static fn($v) => $v !== null && $v !== '');

    return route('admin.orders') . ($q ? '?' . http_build_query($q) : '');
};
$all = array_sum($counts);
$hasFilters = $f['status'] !== '' || $f['payment'] !== '' || $f['channel'] !== '' || $f['q'] !== '';

include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs">Orders <i data-lucide="chevron-right"></i> All orders</div><h1>Orders<span style="color:#dca71f">.</span></h1></div>
  <a class="btn gold" href="<?= route('admin.orders.create') ?>"><i data-lucide="plus" style="width:16px"></i>Create order</a>
</div>

<div class="statstrip">
  <div class="stat glass"><span class="metric-icon"><i data-lucide="sun"></i></span><div><small>Orders today</small><b><?= (int) ($kpi['today'] ?? 0) ?></b><em><?= $inr0($kpi['today_value'] ?? 0) ?> booked</em></div></div>
  <a class="stat glass clickable<?= $f['status'] === 'open' ? ' on' : '' ?>" href="<?= e($url(['status' => 'open', 'payment' => null, 'page' => null])) ?>"><span class="metric-icon" style="background:#e3eef8;color:#3d6a92"><i data-lucide="loader"></i></span><div><small>Open orders</small><b><?= (int) ($kpi['open'] ?? 0) ?></b><em><?= $inr0($kpi['open_value'] ?? 0) ?> in progress</em></div></a>
  <a class="stat glass clickable<?= $f['payment'] === 'due' ? ' on' : '' ?>" href="<?= e($url(['payment' => 'due', 'status' => null, 'page' => null])) ?>"><span class="metric-icon" style="background:#fbe1dc;color:#b3483a"><i data-lucide="indian-rupee"></i></span><div><small>Amount due</small><b><?= $inr0($kpi['due_total'] ?? 0) ?></b><em><?= (int) ($kpi['due_count'] ?? 0) ?> order<?= (int) ($kpi['due_count'] ?? 0) === 1 ? '' : 's' ?> with money due</em></div></a>
  <a class="stat glass clickable<?= $f['status'] === 'delivered' ? ' on' : '' ?>" href="<?= e($url(['status' => 'delivered', 'payment' => null, 'page' => null])) ?>"><span class="metric-icon" style="background:#e4f4e9;color:#267a55"><i data-lucide="package-check"></i></span><div><small>Delivered</small><b><?= (int) $counts['delivered'] ?></b><em>All time</em></div></a>
</div>

<nav class="ord-chips" aria-label="Filter by status">
  <a class="<?= $f['status'] === '' ? 'on' : '' ?>" href="<?= e($url(['status' => null, 'page' => null])) ?>">All <b><?= $all ?></b></a>
  <?php foreach (OS::STATUSES as $k => $l): ?>
  <a class="<?= $f['status'] === $k ? 'on' : '' ?><?= $counts[$k] ? '' : ' zero' ?>" href="<?= e($url(['status' => $k, 'page' => null])) ?>"><i class="dot ord-<?= OS::TONES[$k] ?>"></i><?= $l ?> <b><?= $counts[$k] ?></b></a>
  <?php endforeach; ?>
</nav>

<form class="card toolbar-card glass" method="get" action="<?= route('admin.orders') ?>" id="ordFilters">
  <?php if ($f['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($f['status']) ?>"><?php endif; ?>
  <select class="btn" name="payment" aria-label="Payment"><option value="">Any payment</option><?php foreach (['due' => 'Money due', 'unpaid' => 'Unpaid', 'partially_paid' => 'Partly paid', 'paid' => 'Paid'] as $k => $l): ?><option value="<?= $k ?>"<?= $f['payment'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  <select class="btn" name="channel" aria-label="Channel"><option value="">Online &amp; offline</option><option value="offline"<?= $f['channel'] === 'offline' ? ' selected' : '' ?>>Offline store</option><option value="online"<?= $f['channel'] === 'online' ? ' selected' : '' ?>>Online store</option></select>
  <?php if ($hasFilters): ?><a class="linkbtn" href="<?= route('admin.orders') ?>"><i data-lucide="x"></i>Clear</a><?php endif; ?>
  <label class="search-in"><i data-lucide="search"></i><input name="q" value="<?= e($f['q']) ?>" placeholder="Order no., customer or mobile…" autocomplete="off"></label>
</form>

<form method="post" action="<?= route('admin.orders.bulk') ?>" id="bulkForm">
  <?= csrf_field() ?>
  <div class="card" style="padding:0">
    <div class="tablewrap" style="border:0;border-radius:24px">
      <table class="data ord-table">
        <thead><tr><th class="ord-check"><input type="checkbox" id="checkAll" aria-label="Select all"></th><th>Order</th><th>Customer</th><th>Items</th><th>Total</th><th>Payment</th><th>Status</th><th>Next step</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $o):
              $oid = (int) $o['id']; $st = (string) $o['order_status']; $next = OS::next($st); $show = route('admin.orders.show', ['id' => $oid]);
          ?>
          <tr data-status="<?= e($st) ?>">
            <td class="ord-check"><?php if ($next && in_array($next, OS::BULK, true)): ?><input type="checkbox" name="ids[]" value="<?= $oid ?>" data-status="<?= e($st) ?>" aria-label="Select <?= e((string) $o['order_number']) ?>"><?php endif; ?></td>
            <td><a href="<?= e($show) ?>" class="ord-no"><b><?= e((string) $o['order_number']) ?></b></a>
              <small class="block muted text-xs"><i data-lucide="<?= $channelIcon[$o['channel_detail']] ?? 'store' ?>" class="ord-ch" aria-hidden="true"></i><?= e(OS::CHANNEL_DETAILS[$o['channel_detail']] ?? $o['channel_detail']) ?> · <?= $when($o['created_at'], 'j M, g:i a') ?></small></td>
            <td><a href="<?= route('admin.customers.show', ['id' => $o['customer_id']]) ?>"><b><?= e((string) $o['customer_name']) ?></b></a><small class="block muted text-xs"><?= e(\App\Services\Customer\Phone::format((string) $o['customer_phone'])) ?></small></td>
            <td><?= (int) $o['item_count'] ?> line<?= (int) $o['item_count'] === 1 ? '' : 's' ?></td>
            <td class="whitespace-nowrap"><b><?= $inr($o['grand_total']) ?></b><?php if ($o['gst_rate'] !== null): ?><small class="block muted text-xs">incl. GST <?= e(\App\Services\Order\DocumentService::pct((float) $o['gst_rate'])) ?></small><?php endif; ?></td>
            <td><?= $payBadge($o) ?><?php if ((float) $o['due_amount'] > 0 && $st !== 'rejected'): ?><small class="block text-xs ord-due"><?= $inr($o['due_amount']) ?> due</small><?php endif; ?></td>
            <td><?= $ordStatus($st) ?><?php if (!empty($warn[$oid])): ?> <i data-lucide="triangle-alert" class="ord-warn" title="<?= e(implode("\n", $warn[$oid])) ?>"></i><?php endif; ?></td>
            <td>
              <?php if ($next && !in_array($next, OS::NEEDS_DIALOG, true)): ?>
              <button type="submit" class="btn ord-next" formaction="<?= e(route('admin.orders.transition', ['id' => $oid])) ?>" name="to" value="<?= e($next) ?>"
                <?= !empty($warn[$oid]) ? 'data-warn="' . e("Stock is short:\n" . implode("\n", $warn[$oid]) . "\n\nAccept anyway?") . '"' : '' ?>><?= e(OS::ACTIONS[$next]) ?><i data-lucide="arrow-right" style="width:14px"></i></button>
              <?php elseif ($next): ?>
              <a class="btn ord-next" href="<?= e($show) ?>?do=<?= e($next) ?>"><?= e(OS::ACTIONS[$next]) ?>…</a>
              <?php else: ?><a class="linkbtn" href="<?= e($show) ?>">View</a><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
          <tr><td colspan="8"><div class="cu-empty">
            <span class="metric-icon"><i data-lucide="<?= $hasFilters ? 'search-x' : 'receipt-text' ?>"></i></span>
            <?php if ($hasFilters): ?><b>No orders match</b><small>Try another status or clear the search.</small><a class="btn" href="<?= route('admin.orders') ?>">Clear filters</a>
            <?php else: ?><b>No orders yet</b><small>Create an order for a showroom, phone or WhatsApp sale. Online store orders will appear here too.</small><a class="btn gold" href="<?= route('admin.orders.create') ?>"><i data-lucide="plus" style="width:16px"></i>Create the first order</a><?php endif; ?>
          </div></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="ord-bulk" id="bulkBar" hidden>
    <span><b id="bulkN">0</b> selected</span>
    <span class="muted text-xs" id="bulkHint"></span>
    <span style="flex:1"></span>
    <button type="button" class="btn" id="bulkClear">Clear</button>
    <button type="submit" class="btn gold" name="to" id="bulkGo"></button>
  </div>
</form>
<?php if ($pages > 1): ?>
<div class="pager">
  <?php if ($page > 1): ?><a class="btn" href="<?= e($url(['page' => $page - 1 > 1 ? $page - 1 : null])) ?>"><i data-lucide="chevron-left" style="width:15px"></i>Newer</a><?php endif; ?>
  <span class="text-xs muted">Page <?= $page ?> of <?= $pages ?> · <?= number_format($total) ?> orders</span>
  <?php if ($page < $pages): ?><a class="btn" href="<?= e($url(['page' => $page + 1])) ?>">Older<i data-lucide="chevron-right" style="width:15px"></i></a><?php endif; ?>
</div>
<?php endif; ?>
<?php
$labels = json_encode(['next' => array_map(static fn($n) => $n[0], OS::ALLOWED), 'actions' => OS::ACTIONS, 'statuses' => OS::STATUSES]);
$scripts = '<script>window.ORD=' . $labels . ';</script>' . <<<'JS'
<script>
(function(){
  var f=document.getElementById('ordFilters');
  f.querySelectorAll('select').forEach(function(s){s.addEventListener('change',function(){f.submit()})});
  var form=document.getElementById('bulkForm'),bar=document.getElementById('bulkBar'),all=document.getElementById('checkAll');
  var boxes=[].slice.call(form.querySelectorAll('input[name="ids[]"]'));
  // The per-row buttons submit this same form; make sure only that row's order is posted.
  form.addEventListener('submit',function(e){
    var b=e.submitter;
    if(b&&b.dataset.warn&&!confirm(b.dataset.warn)){e.preventDefault();return;}
    if(b&&b.classList.contains('ord-next'))boxes.forEach(function(x){x.disabled=true});
  });
  function sync(){
    var on=boxes.filter(function(b){return b.checked}),st={};on.forEach(function(b){st[b.dataset.status]=1});
    var keys=Object.keys(st),go=document.getElementById('bulkGo');
    bar.hidden=!on.length;document.getElementById('bulkN').textContent=on.length;
    if(keys.length===1){var nx=ORD.next[keys[0]];go.disabled=false;go.value=nx;go.textContent=ORD.actions[nx]+' ('+on.length+')';document.getElementById('bulkHint').textContent='All '+ORD.statuses[keys[0]].toLowerCase();}
    else{go.disabled=true;go.textContent='Pick one status';document.getElementById('bulkHint').textContent='Selected orders are in different steps — bulk moves work on one step at a time.';}
    all.checked=on.length&&on.length===boxes.length;
  }
  boxes.forEach(function(b){b.addEventListener('change',sync)});
  if(all)all.addEventListener('change',function(){boxes.forEach(function(b){b.checked=all.checked});sync()});
  document.getElementById('bulkClear').addEventListener('click',function(){boxes.forEach(function(b){b.checked=false});sync()});
  if(!boxes.length&&all)all.disabled=true;
})();
</script>
JS;
include __DIR__ . '/../partials/footer.php';
