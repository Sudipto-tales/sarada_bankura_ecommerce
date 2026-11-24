<?php
use App\Controllers\Admin\EnquiryController;
use App\Services\Customer\Phone;
use App\Services\Store\Enquiries;

$active = 'enquiries';
$title = 'Enquiries';
$statusCls = ['new' => 'warn', 'contacted' => 'ok', 'quoted' => 'ok', 'converted' => 'ok', 'closed' => 'off'];
$url = static function (array $over = []) use ($status, $q): string {
    $p = array_filter(array_merge(['status' => $status, 'q' => $q], $over), static fn($v) => $v !== null && $v !== '' && $v !== 'open');

    return route('admin.enquiries') . ($p ? '?' . http_build_query($p) : '');
};
$return = (string) parse_url($url(['page' => $page > 1 ? $page : null]), PHP_URL_QUERY);
$when = static function (?string $ts): string {
    if (!$ts) {
        return '';
    }
    $t = strtotime($ts);
    $mins = (int) floor((time() - $t) / 60);

    return $mins < 1 ? 'just now' : ($mins < 60 ? $mins . ' min ago' : ($mins < 1440 ? floor($mins / 60) . ' h ago' : date('j M, H:i', $t)));
};
$qty = static fn($n): string => rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');

include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs">Customers <i data-lucide="chevron-right"></i> Enquiries</div><h1>Enquiries<span style="color:#dca71f">.</span></h1></div>
  <div class="flex gap-2">
    <button class="btn" type="button" onclick="msExportTable('enqTable','enquiries.csv')"><i data-lucide="download" style="width:16px"></i>Export</button>
  </div>
</div>

<nav class="segtabs" aria-label="Status">
  <?php foreach (['open' => 'Open', 'new' => 'New', 'contacted' => 'Contacted', 'quoted' => 'Quoted', 'converted' => 'Converted', 'closed' => 'Closed', 'all' => 'All'] as $k => $l): ?>
  <a class="<?= $status === $k ? 'on' : '' ?>" href="<?= e($url(['status' => $k, 'page' => null])) ?>"><?= $l ?> <span class="cu-count"><?= (int) ($counts[$k] ?? 0) ?></span></a>
  <?php endforeach; ?>
</nav>

<form class="card toolbar-card glass" method="get" action="<?= route('admin.enquiries') ?>">
  <?php if ($status !== 'open'): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
  <label class="search-in"><i data-lucide="search"></i><input name="q" value="<?= e($q) ?>" placeholder="Name, mobile, ENQ number or text…" autocomplete="off"></label>
  <?php if ($q !== ''): ?><a class="linkbtn" href="<?= e($url(['q' => null, 'page' => null])) ?>"><i data-lucide="x"></i>Clear</a><?php endif; ?>
</form>

<div class="card" style="padding:0">
  <div class="card-h" style="padding:20px 20px 0;align-items:flex-start">
    <div><div class="eyebrow">Storefront</div><h3>People asking for a price, a visit or a stone</h3><p class="text-xs muted mt-1">From the contact page and product pages. Each one is linked to the customer with that mobile.</p></div>
    <span class="text-xs muted"><?= number_format($total) ?> enquir<?= $total === 1 ? 'y' : 'ies' ?></span>
  </div>
  <div class="tablewrap" style="border:0;border-radius:0 0 24px 24px;margin-top:12px">
    <table class="data" id="enqTable">
      <thead><tr><th>Enquiry</th><th>From</th><th>About</th><th>Quantity</th><th>Source</th><th>Assigned</th><th>Status</th><th>Received</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r):
            $about = $r['product_name'] ? $r['product_name'] . ($r['variant_title'] || $r['variant_sku'] ? ' · ' . ($r['variant_title'] ?: $r['variant_sku']) : '') : ($r['category_name'] ?: '');
            $detail = [
                'id' => (int) $r['id'], 'number' => $r['enquiry_number'], 'name' => $r['name'], 'phone' => Phone::format((string) $r['phone']),
                'email' => $r['email'], 'city' => $r['city'], 'about' => $about, 'message' => $r['message'],
                'quantity' => $r['quantity'] !== null ? $qty($r['quantity']) . ' ' . $r['unit'] : '',
                'source' => Enquiries::SOURCES[$r['source']] ?? $r['source'], 'status' => $r['status'], 'assigned' => (string) ($r['assigned_to'] ?? ''),
                'note' => (string) ($r['internal_note'] ?? ''), 'received' => date('j M Y, H:i', strtotime((string) $r['created_at'])),
                'action' => route('admin.enquiries.update', ['id' => (int) $r['id']]),
                'wa' => EnquiryController::waLink($r), 'tel' => 'tel:+91' . $r['phone'],
                'customer' => $r['customer_id'] ? route('admin.customers.show', ['id' => (int) $r['customer_id']]) : '',
                'customer_orders' => (int) ($r['total_orders'] ?? 0),
                'order' => $r['customer_id'] && $canOrder ? route('admin.orders.create') . '?customer=' . (int) $r['customer_id'] : '',
                'order_number' => (string) ($r['order_number'] ?? ''),
                'product' => $r['product_slug'] ? base_url('/product/' . rawurlencode((string) $r['product_slug'])) : '',
                'ip' => (string) ($r['ip'] ?? ''),
            ];
        ?>
        <tr class="click-row" data-open-detail="<?= e(json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"<?= (int) $r['id'] === $open ? ' data-autoopen' : '' ?>>
          <td class="whitespace-nowrap"><b><?= e((string) $r['enquiry_number']) ?></b></td>
          <td><b><?= e((string) $r['name']) ?></b><small class="block muted text-xs"><?= e(Phone::format((string) $r['phone'])) ?><?= $r['city'] ? ' · ' . e((string) $r['city']) : '' ?></small></td>
          <td style="max-width:320px"><?= $about !== '' ? '<b>' . e($about) . '</b>' : '' ?><small class="block muted text-xs" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:320px"><?= e(mb_strimwidth(str_replace("\n", ' ', (string) $r['message']), 0, 110, '…')) ?></small></td>
          <td class="whitespace-nowrap"><?= $r['quantity'] !== null ? e($qty($r['quantity']) . ' ' . $r['unit']) : '<span class="muted">—</span>' ?></td>
          <td><?= e(Enquiries::SOURCES[$r['source']] ?? (string) $r['source']) ?></td>
          <td><?= $r['assignee_name'] ? e((string) $r['assignee_name']) : '<span class="muted">—</span>' ?></td>
          <td><span class="status-dot <?= $statusCls[$r['status']] ?? 'off' ?>"><?= e(Enquiries::STATUSES[$r['status']] ?? (string) $r['status']) ?></span><?= $r['order_number'] ? '<small class="block muted text-xs">' . e((string) $r['order_number']) . '</small>' : '' ?></td>
          <td class="whitespace-nowrap text-xs muted" title="<?= e((string) $r['created_at']) ?>"><?= e($when((string) $r['created_at'])) ?></td>
          <td><button type="button" class="iconbtn" title="Open"><i data-lucide="panel-right-open"></i></button></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <tr><td colspan="9"><div class="cu-empty">
          <span class="metric-icon"><i data-lucide="<?= $q !== '' ? 'search-x' : 'inbox' ?>"></i></span>
          <?php if ($q !== ''): ?><b>No enquiries match “<?= e($q) ?>”</b><small>Try a mobile number or an ENQ number.</small>
          <?php else: ?><b>Nothing here yet</b><small>Enquiries from the contact page and product pages land here, with a notification.</small><?php endif; ?>
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

<div class="modal-backdrop" id="enqModal">
  <form class="modal" method="post" id="enqForm">
    <?= csrf_field() ?>
    <input type="hidden" name="return" value="<?= e($return) ?>">
    <div class="modal-h">
      <div><div class="eyebrow" id="eNumber">Enquiry</div><h3 id="eName"></h3><p class="text-xs muted mt-1" id="eMeta"></p></div>
      <button type="button" class="iconbtn" data-close aria-label="Close"><i data-lucide="x"></i></button>
    </div>
    <div class="revbox">
      <b id="eAbout"></b>
      <p id="eMessage" class="text-sm" style="margin:10px 0;white-space:pre-line"></p>
      <div class="text-xs muted" id="eFacts"></div>
    </div>
    <div class="flex gap-2 flex-wrap" style="margin-top:14px">
      <a class="btn" id="eWa" target="_blank" rel="noopener"><i data-lucide="message-circle" style="width:15px"></i>WhatsApp</a>
      <a class="btn" id="eTel"><i data-lucide="phone" style="width:15px"></i>Call</a>
      <a class="btn" id="eCustomer"><i data-lucide="user-round" style="width:15px"></i>Customer</a>
      <a class="btn" id="eProduct" target="_blank" rel="noopener"><i data-lucide="external-link" style="width:15px"></i>Product page</a>
      <a class="btn gold" id="eOrder"><i data-lucide="receipt-text" style="width:15px"></i>Create order</a>
    </div>
    <div class="grid" style="grid-template-columns:1fr 1fr;gap:12px;margin-top:16px">
      <div class="field"><label for="eStatus">Status</label>
        <select name="status" id="eStatus"<?= $canReply ? '' : ' disabled' ?>><?php foreach (Enquiries::STATUSES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="eAssigned">Assigned to</label>
        <select name="assigned_to" id="eAssigned"<?= $canAssign ? '' : ' disabled' ?>><option value="">Nobody</option><?php foreach ($staff as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e((string) $s['name']) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="field" style="margin-top:12px"><label for="eNote">Internal note <span class="muted" style="font-weight:600">· staff only</span></label>
      <textarea name="internal_note" id="eNote" rows="3" placeholder="Called, sent photos of lot 14, quoted ₹…"<?= $canReply ? '' : ' disabled' ?>></textarea></div>
    <div class="modal-f">
      <button type="button" class="btn ghost" data-close>Close</button>
      <?php if ($canReply || $canAssign): ?><button type="submit" class="btn gold"><i data-lucide="check" style="width:15px"></i>Save</button><?php endif; ?>
    </div>
  </form>
</div>
<?php
$scripts = <<<'JS'
<script>
(function(){
  var m=document.getElementById('enqModal'),f=document.getElementById('enqForm');
  function $(id){return document.getElementById(id);}
  function link(id,href){var a=$(id);if(href){a.href=href;a.hidden=false;}else{a.hidden=true;}}
  m.querySelectorAll('[data-close]').forEach(function(b){b.onclick=function(){m.classList.remove('show');};});
  m.addEventListener('click',function(e){if(e.target===m)m.classList.remove('show');});
  document.addEventListener('keydown',function(e){if(e.key==='Escape')m.classList.remove('show');});
  function open(d){
    f.action=d.action;
    $('eNumber').textContent=d.number+' · '+d.source;
    $('eName').textContent=d.name;
    $('eMeta').textContent=[d.phone,d.email,d.city].filter(Boolean).join(' · ');
    $('eAbout').textContent=d.about||'General enquiry';
    $('eMessage').textContent=d.message;
    $('eFacts').textContent=[d.quantity&&('About '+d.quantity),'Received '+d.received,d.customer_orders?(d.customer_orders+' past order'+(d.customer_orders>1?'s':'')):'',d.order_number&&('Order '+d.order_number),d.ip&&('From '+d.ip)].filter(Boolean).join(' · ');
    link('eWa',d.wa);link('eTel',d.tel);link('eCustomer',d.customer);link('eProduct',d.product);link('eOrder',d.order);
    $('eStatus').value=d.status;$('eAssigned').value=d.assigned;$('eNote').value=d.note;
    m.classList.add('show');
  }
  document.querySelectorAll('[data-open-detail]').forEach(function(r){
    r.addEventListener('click',function(e){if(!e.target.closest('a'))open(JSON.parse(r.dataset.openDetail));});
  });
  var auto=document.querySelector('[data-autoopen]');if(auto)open(JSON.parse(auto.dataset.openDetail));
})();
</script>
JS;
include __DIR__ . '/../partials/footer.php';
