<?php
use App\Services\Inventory\InventoryService as Inv;

$active = 'inventory.stock';
$title = 'Stock management';
$tracked = $tracked ?? [];
$untracked = $untracked ?? [];
$units = $units ?? [];
$categories = $categories ?? [];
$stats = $stats ?? ['tracked' => 0, 'products' => 0, 'healthy' => 0, 'low' => 0, 'out' => 0, 'by_unit' => []];
$reasons = $reasons ?? [];
$focus = $focus ?? 0;
$statusFilter = in_array($statusFilter ?? '', ['healthy', 'low', 'out'], true) ? $statusFilter : '';
$labels = ['healthy' => 'Healthy', 'low' => 'Low stock', 'out' => 'Out of stock'];

$when = static function (?string $dt): array {
    if (!$dt) {
        return ['—', ''];
    }
    $t = strtotime($dt);
    $d = (int) floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', $t))) / 86400);
    $rel = $d === 0 ? 'Today' : ($d === 1 ? 'Yesterday' : $d . ' days ago');

    return [date('d M', $t), $rel . ', ' . date('H:i', $t)];
};

// Data the modals need, keyed by product id.
$js = [];
foreach ($tracked as $r) {
    $pid = (int) $r['id'];
    $js[$pid] = [
        'id' => $pid, 'name' => $r['name'], 'sku' => $r['sku'], 'unit' => $r['unit'],
        'qty' => (float) $r['quantity'], 'threshold' => (float) $r['threshold'],
        'units' => $r['units'], 'factors' => $r['factors'],
        'receive' => route('admin.inventory.receive', ['id' => $pid]),
        'adjust' => route('admin.inventory.adjust', ['id' => $pid]),
        'history' => route('admin.inventory.history', ['id' => $pid]),
    ];
}
$untrackedJs = [];
foreach ($untracked as $r) {
    $untrackedJs[(int) $r['id']] = ['unit' => $r['selling_unit'] ?: ''];
}

include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs">Inventory <i data-lucide="chevron-right"></i> Stock management</div><h1>Stock management<span style="color:#dca71f">.</span></h1></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($untracked): ?>
    <button class="btn" type="button" data-open="openingModal"><i data-lucide="list-checks" style="width:16px"></i>Opening stock setup</button>
    <button class="btn" type="button" data-track><i data-lucide="circle-plus" style="width:16px"></i>Start tracking</button>
    <?php endif; ?>
    <?php if ($tracked): ?>
    <button class="btn" type="button" onclick="msExportTable('stockExport','stock-register.csv')"><i data-lucide="download" style="width:16px"></i>Export</button>
    <button class="btn" type="button" data-adjust><i data-lucide="sliders-horizontal" style="width:16px"></i>Adjust stock</button>
    <button class="btn gold" type="button" data-receive><i data-lucide="plus" style="width:16px"></i>Receive stock</button>
    <?php endif; ?>
  </div>
</div>
<?php if (empty($enabled)): ?><div class="notice">Inventory alerts are switched off — stock is still recorded, but no low / out-of-stock alerts are raised.</div><?php endif; ?>

<div class="statstrip">
  <div class="stat glass clickable<?= $statusFilter === '' ? ' on' : '' ?>" data-stat="">
    <span class="metric-icon"><i data-lucide="boxes"></i></span>
    <div><small>Tracked products</small><b><?= $stats['tracked'] ?></b>
      <?php if ($stats['by_unit']): ?><div class="unit-totals"><?php foreach (array_slice($stats['by_unit'], 0, 4, true) as $u => $q): ?><span><?= Inv::qty($q) ?> <?= e((string) $u) ?></span><?php endforeach; ?></div>
      <?php else: ?><em>of <?= $stats['products'] ?> products</em><?php endif; ?>
    </div>
  </div>
  <div class="stat glass clickable<?= $statusFilter === 'healthy' ? ' on' : '' ?>" data-stat="healthy"><span class="metric-icon" style="background:#dff3e9;color:#1f7a53"><i data-lucide="circle-check"></i></span><div><small>Healthy</small><b><?= $stats['healthy'] ?></b><em>Above threshold</em></div></div>
  <div class="stat glass clickable<?= $statusFilter === 'low' ? ' on' : '' ?>" data-stat="low"><span class="metric-icon" style="background:#ffe6c4;color:#a2632a"><i data-lucide="triangle-alert"></i></span><div><small>Low stock</small><b><?= $stats['low'] ?></b><em>At or below threshold</em></div></div>
  <div class="stat glass clickable<?= $statusFilter === 'out' ? ' on' : '' ?>" data-stat="out"><span class="metric-icon" style="background:#f8d7d1;color:#b4452f"><i data-lucide="package-x"></i></span><div><small>Out of stock</small><b><?= $stats['out'] ?></b><em>Nothing on hand</em></div></div>
</div>

<?php if ($tracked): ?>
<div class="card toolbar-card">
  <select class="btn" id="fStatus"><option value="">All stock statuses</option><?php foreach ($labels as $k => $l): ?><option value="<?= $k ?>"<?= $statusFilter === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  <?php if ($categories): ?><select class="btn" id="fCategory"><option value="">All categories</option><?php foreach ($categories as $cid => $cn): ?><option value="<?= (int) $cid ?>"><?= e((string) $cn) ?></option><?php endforeach; ?></select><?php endif; ?>
  <?php if (count($stats['by_unit']) > 1): ?><select class="btn" id="fUnit"><option value="">All units</option><?php foreach (array_keys($stats['by_unit']) as $u): ?><option value="<?= e((string) $u) ?>"><?= e((string) $u) ?></option><?php endforeach; ?></select><?php endif; ?>
  <div class="search" style="margin-left:auto;min-width:220px;flex:0 1 320px"><i data-lucide="search" style="width:16px"></i><input id="fSearch" placeholder="Search products or SKU…" style="border:0;background:transparent;outline:none;width:100%;font:inherit;font-size:13px"></div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-h" style="align-items:flex-start">
    <div><div class="eyebrow">Inventory control</div><h3>Stock register</h3><p class="text-xs muted mt-1">Physical stock per product. Receive material, correct counts and set the low-stock warning level.</p></div>
    <span class="text-xs muted" id="matchCount"><?= count($tracked) ?> tracked product<?= count($tracked) === 1 ? '' : 's' ?></span>
  </div>

  <?php if (!$tracked): ?>
  <div class="inv-empty">
    <div class="iconbox"><i data-lucide="boxes"></i></div>
    <h3 style="font-weight:800;font-size:17px;margin:0">No stock tracked yet</h3>
    <p class="muted text-sm" style="max-width:440px;margin:8px auto 18px">Only products you start tracking appear here. Enter the stock you have today for several products at once, or add them one by one.</p>
    <?php if ($untracked): ?>
    <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
      <button class="btn gold" type="button" data-open="openingModal"><i data-lucide="list-checks" style="width:16px"></i>Opening stock setup</button>
      <button class="btn" type="button" data-track><i data-lucide="circle-plus" style="width:16px"></i>Track one product</button>
    </div>
    <?php else: ?>
    <a class="btn gold" href="<?= route('admin.products.create') ?>"><i data-lucide="plus" style="width:16px"></i>Add a product first</a>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="inv-head"><span>Product</span><span class="hide-lg">SKU</span><span>Stock</span><span>Threshold</span><span>Status</span><span class="hide-lg">Last movement</span><span></span></div>
  <div id="stockRows">
  <?php foreach ($tracked as $r):
      $pid = (int) $r['id'];
      [$wDate, $wRel] = $when($r['last_movement_at']);
      $custom = $r['low_stock_threshold'] !== null;
  ?>
    <div class="inv-row <?= e((string) $r['status']) ?><?= $focus === $pid ? ' focus' : '' ?>" id="stock-<?= $pid ?>"
         data-status="<?= e((string) $r['status']) ?>" data-category="<?= (int) $r['category_id'] ?>" data-unit="<?= e((string) $r['unit']) ?>"
         data-search="<?= e(mb_strtolower($r['name'] . ' ' . $r['sku'])) ?>">
      <div class="inv-prod">
        <span class="thumb"><?php if ($r['cover']): ?><img src="<?= base_url((string) $r['cover']) ?>" alt=""><?php else: ?><i data-lucide="gem"></i><?php endif; ?></span>
        <span class="min-w-0"><b><?= e((string) $r['name']) ?></b><small><?= e((string) ($r['category'] ?: 'Uncategorised')) ?><?= $r['product_status'] !== 'active' ? ' · ' . e(str_replace('_', ' ', (string) $r['product_status'])) : '' ?></small></span>
      </div>
      <span class="hide-lg muted text-sm"><?= e((string) $r['sku']) ?></span>
      <span class="inv-qty"><b><?= Inv::qty((float) $r['quantity']) ?></b><small><?= e((string) $r['unit']) ?></small></span>
      <form class="inv-th hide-sm" method="post" action="<?= route('admin.inventory.threshold', ['id' => $pid]) ?>">
        <?= csrf_field() ?>
        <input name="threshold" inputmode="decimal" value="<?= $custom ? Inv::qty((float) $r['low_stock_threshold']) : '' ?>" placeholder="<?= Inv::qty((float) $defaultThreshold) ?>" title="Low-stock threshold — leave blank to use the default (<?= Inv::qty((float) $defaultThreshold) ?>)" aria-label="Low-stock threshold for <?= e((string) $r['name']) ?>">
        <small><?= $custom ? '' : 'default' ?></small>
      </form>
      <span><span class="stock-pill <?= e((string) $r['status']) ?>"><?= $labels[$r['status']] ?? e((string) $r['status']) ?></span></span>
      <span class="inv-when hide-lg"><?= $wDate ?><small><?= $wRel ?></small></span>
      <span class="inv-acts">
        <button type="button" class="btn sm" data-receive="<?= $pid ?>" title="Receive stock"><i data-lucide="plus" style="width:14px"></i>Receive</button>
        <button type="button" class="iconbtn" data-adjust="<?= $pid ?>" title="Adjust stock"><i data-lucide="sliders-horizontal"></i></button>
        <button type="button" class="iconbtn" data-history="<?= $pid ?>" title="Movement history"><i data-lucide="history"></i></button>
        <button type="button" class="iconbtn danger" data-stop="<?= e(route('admin.inventory.stop', ['id' => $pid])) ?>" data-stop-name="<?= e((string) $r['name']) ?>" title="Stop tracking"><i data-lucide="circle-off"></i></button>
      </span>
    </div>
  <?php endforeach; ?>
  <p class="muted text-sm hidden" id="noMatch" style="padding:30px;text-align:center">No products match these filters.</p>
  </div>

  <!-- Plain table used only for CSV export. -->
  <table id="stockExport" class="hidden"><thead><tr><th>Product</th><th>SKU</th><th>Quantity</th><th>Unit</th><th>Threshold</th><th>Status</th><th>Last movement</th></tr></thead><tbody>
  <?php foreach ($tracked as $r): ?><tr data-pid="<?= (int) $r['id'] ?>"><td><?= e((string) $r['name']) ?></td><td><?= e((string) $r['sku']) ?></td><td><?= Inv::qty((float) $r['quantity']) ?></td><td><?= e((string) $r['unit']) ?></td><td><?= Inv::qty((float) $r['threshold']) ?></td><td><?= $labels[$r['status']] ?? '' ?></td><td><?= e((string) $r['last_movement_at']) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>

<?php if ($untracked && $tracked): ?>
<p class="muted text-xs" style="margin:14px 4px"><?= count($untracked) ?> product<?= count($untracked) === 1 ? ' is' : 's are' ?> not tracked. <button type="button" class="linkbtn" data-open="openingModal">Set opening stock →</button></p>
<?php endif; ?>

<!-- Receive stock -->
<div class="modal-backdrop" id="receiveModal">
  <form class="modal" method="post" id="receiveForm">
    <?= csrf_field() ?>
    <div class="modal-h"><div><div class="eyebrow">Stock in</div><h3>Receive new stock</h3><p class="text-xs muted mt-1">Record material that arrived. Stock goes up and the movement is saved with your reference.</p></div><button type="button" class="iconbtn" data-close><i data-lucide="x"></i></button></div>
    <div class="stack">
      <div class="field"><label>Product *</label><select data-product required></select></div>
      <div class="stockcard"><div><small>Current stock</small><b data-current>—</b></div><span class="stock-pill" data-pill></span></div>
      <div class="field"><label>Received quantity *</label>
        <div class="qtyrow"><input name="quantity" inputmode="decimal" required placeholder="e.g. 200" autocomplete="off"><select name="entered_unit" data-units></select></div>
        <div class="convhint" data-conv></div>
      </div>
      <div class="grid2">
        <div class="field"><label>Reference number</label><input name="reference_no" maxlength="64" placeholder="e.g. GRN-001 / challan no."></div>
        <div class="field"><label>Notes</label><input name="notes" maxlength="500" placeholder="e.g. New material received"></div>
      </div>
      <div class="newstock"><div><small>New physical stock</small><b data-new>—</b></div><span class="delta pos" data-delta></span></div>
    </div>
    <div class="modal-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn gold" type="submit"><i data-lucide="check" style="width:16px"></i>Confirm receipt</button></div>
  </form>
</div>

<!-- Adjust stock -->
<div class="modal-backdrop" id="adjustModal">
  <form class="modal" method="post" id="adjustForm">
    <?= csrf_field() ?>
    <input type="hidden" name="mode" value="count">
    <div class="modal-h"><div><div class="eyebrow">Stock correction</div><h3>Adjust stock</h3><p class="text-xs muted mt-1">Correct the system quantity to what is physically there. A reason is required and the change is logged.</p></div><button type="button" class="iconbtn" data-close><i data-lucide="x"></i></button></div>
    <div class="stack">
      <div class="field"><label>Product *</label><select data-product required></select></div>
      <div class="rowfilter" data-modes><button type="button" class="on" data-mode="count">Set physical count</button><button type="button" data-mode="delta">Add or remove</button></div>
      <div class="stockcard"><div><small>System quantity</small><b data-current>—</b></div><span class="stock-pill" data-pill></span></div>
      <div class="field" data-for="count"><label>Physical count *</label><div class="qtyrow"><input name="counted" inputmode="decimal" placeholder="e.g. 680" autocomplete="off"><input data-unitlabel disabled></div></div>
      <div class="field hidden" data-for="delta"><label>Quantity to add (+) or remove (−) *</label><div class="qtyrow"><input name="delta" inputmode="decimal" placeholder="e.g. -20" autocomplete="off"><input data-unitlabel disabled></div></div>
      <div class="grid2">
        <div class="field"><label>Reason *</label><select name="reason" required><option value="">Choose a reason…</option><?php foreach ($reasons as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field hidden" data-other><label>Describe the reason *</label><input name="reason_other" maxlength="200" placeholder="e.g. Used for showroom display"></div>
        <div class="field" data-ref><label>Reference number</label><input name="reference_no" maxlength="64" placeholder="e.g. Count sheet 12"></div>
      </div>
      <div class="field"><label>Notes</label><input name="notes" maxlength="500" placeholder="Anything worth remembering about this correction"></div>
      <div class="newstock"><div><small>Stock after adjustment</small><b data-new>—</b></div><span class="delta" data-delta></span></div>
    </div>
    <div class="modal-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn gold" type="submit"><i data-lucide="check" style="width:16px"></i>Save adjustment</button></div>
  </form>
</div>

<!-- Start tracking one product -->
<div class="modal-backdrop" id="trackModal">
  <form class="modal" method="post" action="<?= route('admin.inventory.track') ?>" id="trackForm">
    <?= csrf_field() ?>
    <div class="modal-h"><div><div class="eyebrow">Inventory setup</div><h3>Start tracking a product</h3><p class="text-xs muted mt-1">Choose the unit you count this product in and how much you have today.</p></div><button type="button" class="iconbtn" data-close><i data-lucide="x"></i></button></div>
    <div class="stack">
      <div class="field"><label>Product *</label><select name="product_id" required><option value="">Choose a product…</option><?php foreach ($untracked as $r): ?><option value="<?= (int) $r['id'] ?>"><?= e((string) $r['name']) ?> — <?= e((string) $r['sku']) ?></option><?php endforeach; ?></select></div>
      <div class="grid3">
        <div class="field"><label>Stock unit *</label><select name="unit" required><option value="">Unit…</option><?php foreach ($units as $u): ?><option value="<?= e((string) $u) ?>"><?= e((string) $u) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Opening quantity</label><input name="quantity" inputmode="decimal" placeholder="0"></div>
        <div class="field"><label>Low-stock threshold</label><input name="threshold" inputmode="decimal" placeholder="Default <?= Inv::qty((float) $defaultThreshold) ?>"></div>
      </div>
      <div class="hint" style="margin-top:-4px">The stock unit cannot be changed later. Pick the unit you physically count in (e.g. sq ft for slabs, box for tiles).</div>
      <div class="field"><label>Notes</label><input name="notes" maxlength="500" placeholder="e.g. Counted on 22 Nov"></div>
    </div>
    <div class="modal-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn gold" type="submit"><i data-lucide="circle-plus" style="width:16px"></i>Start tracking</button></div>
  </form>
</div>

<!-- Opening stock setup (many products) -->
<div class="modal-backdrop" id="openingModal">
  <form class="modal wide" method="post" action="<?= route('admin.inventory.opening') ?>" id="openingForm">
    <?= csrf_field() ?>
    <div class="modal-h"><div><div class="eyebrow">Inventory setup</div><h3>Opening stock setup</h3><p class="text-xs muted mt-1">Tick the products you want to track and enter the stock you have today. Everything is saved together — if one row has a problem, nothing is saved.</p></div><button type="button" class="iconbtn" data-close><i data-lucide="x"></i></button></div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
      <div class="search" style="flex:1;min-width:200px"><i data-lucide="search" style="width:16px"></i><input id="openSearch" placeholder="Filter products…" style="border:0;background:transparent;outline:none;width:100%;font:inherit;font-size:13px"></div>
      <span class="text-xs muted" id="openCount">0 selected</span>
    </div>
    <div class="opening-table">
      <table class="data">
        <thead><tr><th style="width:36px"><input type="checkbox" id="openAll" aria-label="Select all"></th><th>Product</th><th style="width:130px">Stock unit</th><th style="width:130px">Opening qty</th><th style="width:130px">Threshold</th></tr></thead>
        <tbody>
        <?php foreach ($untracked as $r): $pid = (int) $r['id']; ?>
          <tr data-search="<?= e(mb_strtolower($r['name'] . ' ' . $r['sku'])) ?>">
            <td><input type="checkbox" name="rows[<?= $pid ?>][on]" value="1" data-on aria-label="Track <?= e((string) $r['name']) ?>"></td>
            <td><b style="font-size:13px"><?= e((string) $r['name']) ?></b><div class="muted" style="font-size:11px"><?= e((string) $r['sku']) ?></div></td>
            <td><select name="rows[<?= $pid ?>][unit]"><?php foreach ($units as $u): ?><option value="<?= e((string) $u) ?>"<?= strcasecmp((string) $u, (string) $r['selling_unit']) === 0 ? ' selected' : '' ?>><?= e((string) $u) ?></option><?php endforeach; ?></select></td>
            <td><input type="text" inputmode="decimal" name="rows[<?= $pid ?>][quantity]" placeholder="0"></td>
            <td><input type="text" inputmode="decimal" name="rows[<?= $pid ?>][threshold]" placeholder="<?= Inv::qty((float) $defaultThreshold) ?>"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="modal-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn gold" type="submit"><i data-lucide="check" style="width:16px"></i>Save opening stock</button></div>
  </form>
</div>

<!-- Movement history -->
<div class="modal-backdrop" id="historyModal">
  <div class="modal">
    <div class="modal-h"><div><div class="eyebrow">Movement history</div><h3 data-title>History</h3><p class="text-xs muted mt-1" data-sub></p></div><button type="button" class="iconbtn" data-close><i data-lucide="x"></i></button></div>
    <div class="hist-list" data-list><p class="muted text-sm">Loading…</p></div>
  </div>
</div>

<form method="post" id="stopForm" class="hidden"><?= csrf_field() ?></form>
<script type="application/json" id="stockData"><?= json_encode(['stock' => (object) $js, 'untracked' => (object) $untrackedJs, 'labels' => $labels], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>

<?php
$scripts = <<<'JS'
<script>
(function(){
  const D=JSON.parse(document.getElementById('stockData').textContent), S=D.stock;
  const ids=Object.keys(S);
  const fmt=n=>{n=Math.round(n*1000)/1000;return n.toLocaleString('en-IN',{maximumFractionDigits:3})};
  const num=v=>{v=String(v||'').replace(/[,\s]/g,'');return v===''||isNaN(v)?null:parseFloat(v)};
  const statusOf=(q,t)=>q<=0?'out':(q<=t?'low':'healthy');
  const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  // ---- modal plumbing
  function show(id){const m=document.getElementById(id);m.classList.add('show');const f=m.querySelector('input:not([type=hidden]):not([disabled]),select');if(f)setTimeout(()=>f.focus(),30);return m}
  document.querySelectorAll('.modal-backdrop').forEach(m=>{
    m.querySelectorAll('[data-close]').forEach(b=>b.onclick=()=>m.classList.remove('show'));
    m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('show')});
  });
  document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-backdrop.show').forEach(m=>m.classList.remove('show'))});
  document.querySelectorAll('[data-open]').forEach(b=>b.onclick=()=>show(b.dataset.open));

  function fillProducts(sel,pid){
    sel.innerHTML=ids.map(id=>'<option value="'+id+'">'+esc(S[id].name)+' — '+esc(S[id].sku)+'</option>').join('');
    if(pid)sel.value=pid;
  }
  function paintCurrent(m,p){
    m.querySelector('[data-current]').textContent=fmt(p.qty)+' '+p.unit;
    const st=statusOf(p.qty,p.threshold),pill=m.querySelector('[data-pill]');
    pill.className='stock-pill '+st;pill.textContent=D.labels[st];
  }

  // ---- receive
  const rm=document.getElementById('receiveModal'),rf=document.getElementById('receiveForm'),rSel=rf.querySelector('[data-product]'),rUnits=rf.querySelector('[data-units]');
  function rCalc(){
    const p=S[rSel.value];if(!p)return;
    const q=num(rf.quantity.value),u=rUnits.value,f=p.factors[u]||1,add=q?q*f:0;
    rf.querySelector('[data-conv]').textContent=q&&u!==p.unit?'= '+fmt(add)+' '+p.unit+' (1 '+u+' = '+fmt(f)+' '+p.unit+')':'';
    rf.querySelector('[data-new]').textContent=fmt(p.qty+add)+' '+p.unit;
    rf.querySelector('[data-delta]').textContent=add?'+'+fmt(add)+' '+p.unit:'';
  }
  function rPick(){
    const p=S[rSel.value];if(!p)return;
    rf.action=p.receive;paintCurrent(rf,p);
    rUnits.innerHTML=p.units.map(u=>'<option value="'+esc(u)+'">'+esc(u)+'</option>').join('');
    rUnits.disabled=p.units.length<2;rCalc();
  }
  function openReceive(pid){rf.reset();fillProducts(rSel,pid||ids[0]);rPick();show('receiveModal');rf.quantity.focus()}
  rSel.onchange=rPick;rUnits.onchange=rCalc;rf.quantity.oninput=rCalc;
  rf.addEventListener('submit',()=>{rUnits.disabled=false});

  // ---- adjust
  const af=document.getElementById('adjustForm'),aSel=af.querySelector('[data-product]');
  function aMode(mode){
    af.mode.value=mode;
    af.querySelectorAll('[data-mode]').forEach(b=>b.classList.toggle('on',b.dataset.mode===mode));
    af.querySelectorAll('[data-for]').forEach(x=>x.classList.toggle('hidden',x.dataset.for!==mode));
    aCalc();
  }
  function aCalc(){
    const p=S[aSel.value];if(!p)return;
    let after=null;
    if(af.mode.value==='count'){const c=num(af.counted.value);if(c!==null)after=c}
    else{const d=num(af.delta.value);if(d!==null)after=p.qty+d}
    const nb=af.querySelector('[data-new]'),db=af.querySelector('[data-delta]');
    if(after===null){nb.textContent='—';db.textContent='';db.className='delta';return}
    const diff=Math.round((after-p.qty)*1000)/1000;
    nb.textContent=fmt(after)+' '+p.unit;
    db.className='delta '+(diff<0?'neg':'pos');
    db.textContent=after<0?'Below zero':(diff===0?'No change':(diff>0?'+':'−')+fmt(Math.abs(diff))+' '+p.unit);
  }
  function aPick(){const p=S[aSel.value];if(!p)return;af.action=p.adjust;paintCurrent(af,p);af.querySelectorAll('[data-unitlabel]').forEach(i=>i.value=p.unit);aCalc()}
  function openAdjust(pid){af.reset();fillProducts(aSel,pid||ids[0]);aMode('count');aPick();af.querySelector('[data-other]').classList.add('hidden');af.querySelector('[data-ref]').classList.remove('hidden');show('adjustModal');af.counted.focus()}
  aSel.onchange=aPick;af.counted.oninput=aCalc;af.delta.oninput=aCalc;
  af.querySelectorAll('[data-mode]').forEach(b=>b.onclick=()=>aMode(b.dataset.mode));
  af.reason.onchange=()=>{const o=af.reason.value==='other';af.querySelector('[data-other]').classList.toggle('hidden',!o);af.querySelector('[data-ref]').classList.toggle('hidden',o);af.reason_other.required=o};

  // ---- start tracking: preselect the product's selling unit
  const tf=document.getElementById('trackForm');
  tf.product_id.onchange=()=>{const u=(D.untracked[tf.product_id.value]||{}).unit;if(u){[...tf.unit.options].forEach(o=>{if(o.value.toLowerCase()===u.toLowerCase())tf.unit.value=o.value})}};
  document.querySelectorAll('[data-track]').forEach(b=>b.onclick=()=>{tf.reset();show('trackModal')});

  // ---- opening stock grid
  const of=document.getElementById('openingForm');
  if(of){
    const rows=[...of.querySelectorAll('tbody tr')];
    const count=()=>{const n=rows.filter(r=>r.querySelector('[data-on]').checked).length;document.getElementById('openCount').textContent=n+' selected';rows.forEach(r=>r.classList.toggle('on',r.querySelector('[data-on]').checked))};
    rows.forEach(r=>{
      r.querySelector('[data-on]').onchange=count;
      r.querySelectorAll('input[type=text]').forEach(i=>i.addEventListener('input',()=>{if(i.value.trim()!==''){r.querySelector('[data-on]').checked=true;count()}}));
    });
    document.getElementById('openAll').onchange=e=>{rows.filter(r=>!r.classList.contains('hidden')).forEach(r=>r.querySelector('[data-on]').checked=e.target.checked);count()};
    document.getElementById('openSearch').oninput=e=>{const q=e.target.value.trim().toLowerCase();rows.forEach(r=>r.classList.toggle('hidden',!!q&&r.dataset.search.indexOf(q)<0))};
  }

  // ---- history
  const hm=document.getElementById('historyModal');
  const icons={opening:['flag','#efe7f6','#6f4a92'],stock_in:['package-plus','#dff3e9','#1f7a53'],adjust_in:['trending-up','#e3eef8','#3d6a92'],adjust_out:['trending-down','#fbe1dc','#b4452f'],import:['file-spreadsheet','#fff0bf','#91670a']};
  async function openHistory(pid){
    const p=S[pid];hm.querySelector('[data-title]').textContent=p.name;hm.querySelector('[data-sub]').textContent=p.sku+' · now '+fmt(p.qty)+' '+p.unit;
    const list=hm.querySelector('[data-list]');list.innerHTML='<p class="muted text-sm">Loading…</p>';show('historyModal');
    try{
      const r=await fetch(p.history,{headers:{Accept:'application/json'}});const j=await r.json();
      list.innerHTML=j.movements.length?j.movements.map(m=>{
        const ic=icons[m.type]||['circle','#efeae0','#7d7462'],pos=m.change>=0;
        const extra=[m.entered?'Entered '+esc(m.entered):'',m.reason?esc(m.reason):'',m.reference?'Ref '+esc(m.reference):'',m.notes?esc(m.notes):''].filter(Boolean).join(' · ');
        return '<div class="hist-item"><span class="hist-ic" style="background:'+ic[1]+';color:'+ic[2]+'"><i data-lucide="'+ic[0]+'"></i></span>'
          +'<div><b style="font-size:13px">'+esc(m.label)+'</b> <span class="muted text-xs">· '+esc(m.source)+'</span><small>'+esc(m.date)+' · '+fmt(m.before)+' → '+fmt(m.after)+' '+esc(p.unit)+'</small>'+(extra?'<small>'+extra+'</small>':'')+'</div>'
          +'<span class="chg '+(pos?'pos':'neg')+'">'+(pos?'+':'−')+fmt(Math.abs(m.change))+'</span></div>';
      }).join(''):'<p class="muted text-sm">No movements yet.</p>';
      lucide.createIcons();
    }catch(e){list.innerHTML='<p class="muted text-sm">Could not load the history. Refresh and try again.</p>'}
  }

  // ---- row + header buttons
  document.querySelectorAll('[data-receive]').forEach(b=>b.onclick=()=>openReceive(b.dataset.receive));
  document.querySelectorAll('[data-adjust]').forEach(b=>b.onclick=()=>openAdjust(b.dataset.adjust));
  document.querySelectorAll('[data-history]').forEach(b=>b.onclick=()=>openHistory(b.dataset.history));
  document.querySelectorAll('[data-stop]').forEach(b=>b.onclick=()=>{
    if(!confirm('Stop tracking "'+b.dataset.stopName+'"?\n\nIt will leave the stock register and its alerts will close. Its movement history is kept, and you can track it again later.'))return;
    const f=document.getElementById('stopForm');f.action=b.dataset.stop;f.submit();
  });

  // ---- threshold: save on change
  document.querySelectorAll('.inv-th input').forEach(i=>{
    const start=i.value;
    i.addEventListener('change',()=>{if(i.value!==start)i.form.submit()});
    i.addEventListener('keydown',e=>{if(e.key==='Escape'){i.value=start;i.blur()}});
  });

  // ---- filters (client-side)
  const rows=[...document.querySelectorAll('.inv-row')],fs=id=>document.getElementById(id);
  function filter(){
    const st=(fs('fStatus')||{}).value||'',cat=(fs('fCategory')||{}).value||'',un=(fs('fUnit')||{}).value||'',q=((fs('fSearch')||{}).value||'').trim().toLowerCase();
    let n=0;
    rows.forEach(r=>{const ok=(!st||r.dataset.status===st)&&(!cat||r.dataset.category===cat)&&(!un||r.dataset.unit===un)&&(!q||r.dataset.search.indexOf(q)>=0);r.classList.toggle('hidden',!ok);if(ok)n++});
    document.querySelectorAll('#stockExport tbody tr').forEach(t=>{const r=document.getElementById('stock-'+t.dataset.pid);t.classList.toggle('hidden',!!r&&r.classList.contains('hidden'))});
    const nm=fs('noMatch');if(nm)nm.classList.toggle('hidden',n>0||!rows.length);
    const mc=fs('matchCount');if(mc)mc.textContent=(n===rows.length?n+' tracked product':n+' matching product')+(n===1?'':'s');
    document.querySelectorAll('[data-stat]').forEach(s=>s.classList.toggle('on',s.dataset.stat===st));
  }
  ['fStatus','fCategory','fUnit'].forEach(id=>{const x=fs(id);if(x)x.onchange=filter});
  const se=fs('fSearch');if(se)se.oninput=filter;
  document.querySelectorAll('[data-stat]').forEach(s=>s.onclick=()=>{const x=fs('fStatus');if(x){x.value=s.dataset.stat;filter()}});
  filter();

  // ---- arriving from a notification (?product=ID): scroll to the row
  const focus=document.querySelector('.inv-row.focus');
  if(focus){focus.scrollIntoView({block:'center',behavior:'smooth'});setTimeout(()=>focus.classList.remove('focus'),4000)}
  // "Receive stock" from a notification: ?product=ID&receive=1 opens the receive form for that product.
  const qs=new URLSearchParams(location.search);
  // ?receive=1 / ?adjust=1 (optionally with &product=ID) open the matching form — used by the overview and notifications.
  const qp=qs.get('product'),pick=S[qp]?qp:'';
  if(ids.length&&(qs.get('receive')==='1'||qs.get('adjust')==='1')){
    qs.get('receive')==='1'?openReceive(pick):openAdjust(pick);
    history.replaceState(null,'',location.pathname+(pick?'?product='+pick:''));
  }
})();
</script>
JS;
include __DIR__ . '/../partials/footer.php';
?>
