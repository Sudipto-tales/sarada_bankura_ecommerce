<?php
$active = 'orders.policies';
$title = 'Return policies';
include __DIR__ . '/../orders/_helpers.php';
$url = static function (array $over = []) use ($f): string {
    $q = array_filter(array_merge($f, $over), static fn($v) => $v !== null && $v !== '');

    return route('admin.return-policies') . ($q ? '?' . http_build_query($q) : '');
};
$days = static fn(int $d): string => $d > 0 ? $d . ' day' . ($d === 1 ? '' : 's') : 'no time limit';
$products = (int) ($stats['products'] ?? 0);
$returnable = (int) ($stats['returnable'] ?? 0);
$replaceable = (int) ($stats['replaceable'] ?? 0);

include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs">Orders <i data-lucide="chevron-right"></i> Return policies</div><h1>Return policies<span style="color:#dca71f">.</span></h1>
    <p class="text-sm muted" style="margin:4px 0 0">Set which products can come back after delivery. Each new order copies the policy onto its lines — changing it here never affects orders already placed.</p></div>
  <a class="btn" href="<?= route('admin.returns') ?>"><i data-lucide="undo-2" style="width:16px"></i>Returns &amp; replacements</a>
</div>

<div class="statstrip">
  <a class="stat glass clickable<?= $f['show'] === '' ? ' on' : '' ?>" href="<?= e($url(['show' => null, 'page' => null])) ?>"><span class="metric-icon"><i data-lucide="gem"></i></span><div><small>Products</small><b><?= $products ?></b><em>Not archived</em></div></a>
  <a class="stat glass clickable<?= $f['show'] === 'return' ? ' on' : '' ?>" href="<?= e($url(['show' => 'return', 'page' => null])) ?>"><span class="metric-icon" style="background:#e4f4e9;color:#267a55"><i data-lucide="undo-2"></i></span><div><small>Returnable</small><b><?= $returnable ?></b><em>Return &amp; refund allowed</em></div></a>
  <a class="stat glass clickable<?= $f['show'] === 'replacement' ? ' on' : '' ?>" href="<?= e($url(['show' => 'replacement', 'page' => null])) ?>"><span class="metric-icon" style="background:#e3eef8;color:#3d6a92"><i data-lucide="repeat"></i></span><div><small>Replaceable</small><b><?= $replaceable ?></b><em>Replacement allowed</em></div></a>
  <a class="stat glass clickable<?= $f['show'] === 'none' ? ' on' : '' ?>" href="<?= e($url(['show' => 'none', 'page' => null])) ?>"><span class="metric-icon" style="background:#efeae0;color:#7d7462"><i data-lucide="ban"></i></span><div><small>No returns</small><b><?= max(0, $products - (int) ($stats['anyok'] ?? 0)) ?></b><em>Final sale (default)</em></div></a>
</div>

<form class="card toolbar-card glass" method="get" action="<?= route('admin.return-policies') ?>">
  <?php if ($f['show'] !== ''): ?><input type="hidden" name="show" value="<?= e($f['show']) ?>"><?php endif; ?>
  <?php if ($f['q'] !== '' || $f['show'] !== ''): ?><a class="linkbtn" href="<?= route('admin.return-policies') ?>"><i data-lucide="x"></i>Clear</a><?php endif; ?>
  <label class="search-in"><i data-lucide="search"></i><input name="q" value="<?= e($f['q']) ?>" placeholder="Product name or SKU…" autocomplete="off"></label>
</form>

<div class="card" style="padding:0">
  <div class="tablewrap" style="border:0;border-radius:24px">
    <table class="data ord-table">
      <thead><tr><th class="ord-check"><input type="checkbox" id="checkAll" aria-label="Select all"></th><th>Product</th><th>Return &amp; refund</th><th>Replacement</th><th>Conditions</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $p): $on = (int) ($p['is_active'] ?? 0) === 1; $ret = $on && (int) $p['return_allowed'] === 1; $rep = $on && (int) $p['replacement_allowed'] === 1;
          $data = ['id' => (int) $p['id'], 'name' => $p['name'], 'return_allowed' => (int) ($p['return_allowed'] ?? 0), 'return_days' => (int) ($p['return_days'] ?? 0),
              'replacement_allowed' => (int) ($p['replacement_allowed'] ?? 0), 'replacement_days' => (int) ($p['replacement_days'] ?? 0),
              'return_conditions' => (string) ($p['return_conditions'] ?? ''), 'is_active' => $p['is_active'] === null ? 1 : (int) $p['is_active']]; ?>
        <tr>
          <td class="ord-check"><input type="checkbox" class="rp-pick" value="<?= (int) $p['id'] ?>" aria-label="Select <?= e((string) $p['name']) ?>"></td>
          <td><div class="flex items-center gap-3"><span class="ord-thumb sm"><?php if ($p['cover']): ?><img src="<?= e(base_url((string) $p['cover'])) ?>" alt=""><?php else: ?><i data-lucide="gem"></i><?php endif; ?></span>
            <span><b><?= e((string) $p['name']) ?></b><small class="block muted text-xs"><?= e((string) $p['sku']) ?><?= $p['status'] !== 'active' ? ' · ' . e(str_replace('_', ' ', (string) $p['status'])) : '' ?></small></span></div></td>
          <td><?= $ret ? '<span class="ret-pol yes"><i data-lucide="check"></i>' . e($days((int) $p['return_days'])) . '</span>' : '<span class="ret-pol no">No</span>' ?></td>
          <td><?= $rep ? '<span class="ret-pol yes"><i data-lucide="check"></i>' . e($days((int) $p['replacement_days'])) . '</span>' : '<span class="ret-pol no">No</span>' ?></td>
          <td class="text-xs muted ret-cond"><?= $p['return_conditions'] ? e(mb_strimwidth((string) $p['return_conditions'], 0, 90, '…')) : '—' ?><?= $p['is_active'] !== null && !$on ? ' <span class="ord-st ord-grey">Paused</span>' : '' ?></td>
          <td class="r"><button type="button" class="btn sm" data-policy="<?= e(json_encode($data, JSON_UNESCAPED_UNICODE)) ?>"><i data-lucide="pencil" style="width:14px"></i>Edit</button></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="6"><div class="cu-empty"><span class="metric-icon"><i data-lucide="search-x"></i></span><b>No products here</b><small>Try another filter or search.</small></div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="ord-bulk" id="rpBulk" hidden>
  <span><b id="rpN">0</b> selected</span><span style="flex:1"></span>
  <button type="button" class="btn" id="rpClear">Clear</button>
  <button type="button" class="btn gold" id="rpBulkGo"><i data-lucide="shield-check" style="width:16px"></i>Set policy for selected</button>
</div>
<?php if ($pages > 1): ?>
<div class="pager">
  <?php if ($page > 1): ?><a class="btn" href="<?= e($url(['page' => $page - 1 > 1 ? $page - 1 : null])) ?>"><i data-lucide="chevron-left" style="width:15px"></i>Previous</a><?php endif; ?>
  <span class="text-xs muted">Page <?= $page ?> of <?= $pages ?> · <?= number_format($total) ?> products</span>
  <?php if ($page < $pages): ?><a class="btn" href="<?= e($url(['page' => $page + 1])) ?>">Next<i data-lucide="chevron-right" style="width:15px"></i></a><?php endif; ?>
</div>
<?php endif; ?>

<div class="modal-backdrop" id="rpModal"><form class="modal" method="post" action="<?= route('admin.return-policies.save') ?>"><?= csrf_field() ?>
  <div id="rpIds"></div>
  <div class="modal-h"><div><div class="eyebrow" id="rpFor">Product</div><h3>Return policy</h3></div><button type="button" class="iconbtn" data-close aria-label="Close"><i data-lucide="x"></i></button></div>
  <div class="stack">
    <div class="ret-polrow"><label class="checkline"><input type="checkbox" name="return_allowed" value="1" id="rpRet"> <b>Return &amp; refund</b></label>
      <label class="ret-days">within <input name="return_days" inputmode="numeric" maxlength="3" id="rpRetDays"> days</label></div>
    <div class="ret-polrow"><label class="checkline"><input type="checkbox" name="replacement_allowed" value="1" id="rpRep"> <b>Replacement</b></label>
      <label class="ret-days">within <input name="replacement_days" inputmode="numeric" maxlength="3" id="rpRepDays"> days</label></div>
    <p class="text-xs muted" style="margin:-4px 0 0">Days count from delivery (Indian time). 0 or blank = no time limit.</p>
    <div class="field"><label>Conditions (shown to staff)</label><textarea name="return_conditions" rows="3" maxlength="2000" id="rpCond" placeholder="e.g. Unopened boxes only · no cut-to-size slabs · customer pays return transport"></textarea></div>
    <input type="hidden" name="is_active" value="0"><label class="checkline"><input type="checkbox" name="is_active" value="1" id="rpActive" checked> Policy active (untick to pause without losing the settings)</label>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn gold" type="submit"><i data-lucide="check" style="width:16px"></i>Save policy</button></div>
</form></div>
<?php
$scripts = ($scripts ?? '') . <<<'JS'
<script>
(function(){
  var m=document.getElementById('rpModal'),ids=document.getElementById('rpIds');
  function el(id){return document.getElementById(id)}
  function sync(){el('rpRetDays').disabled=!el('rpRet').checked;el('rpRepDays').disabled=!el('rpRep').checked;}
  function open(list,d,label){
    ids.innerHTML=list.map(function(i){return '<input type="hidden" name="product_ids[]" value="'+parseInt(i,10)+'">'}).join('');
    el('rpFor').textContent=label;el('rpRet').checked=!!d.return_allowed;el('rpRep').checked=!!d.replacement_allowed;
    el('rpRetDays').value=d.return_days||'';el('rpRepDays').value=d.replacement_days||'';el('rpCond').value=d.return_conditions||'';el('rpActive').checked=d.is_active!==0;
    sync();m.classList.add('show');
  }
  ['rpRet','rpRep'].forEach(function(i){el(i).addEventListener('change',sync)});
  document.querySelectorAll('[data-policy]').forEach(function(b){b.addEventListener('click',function(){var d=JSON.parse(b.dataset.policy);open([d.id],d,d.name)})});
  m.querySelectorAll('[data-close]').forEach(function(b){b.addEventListener('click',function(){m.classList.remove('show')})});
  m.addEventListener('click',function(e){if(e.target===m)m.classList.remove('show')});
  document.addEventListener('keydown',function(e){if(e.key==='Escape')m.classList.remove('show')});
  var picks=[].slice.call(document.querySelectorAll('.rp-pick')),all=el('checkAll'),bar=el('rpBulk');
  function count(){var on=picks.filter(function(p){return p.checked});bar.hidden=!on.length;el('rpN').textContent=on.length;if(all)all.checked=on.length&&on.length===picks.length;return on;}
  picks.forEach(function(p){p.addEventListener('change',count)});
  if(all)all.addEventListener('change',function(){picks.forEach(function(p){p.checked=all.checked});count()});
  el('rpClear').addEventListener('click',function(){picks.forEach(function(p){p.checked=false});count()});
  el('rpBulkGo').addEventListener('click',function(){var on=count();open(on.map(function(p){return p.value}),{return_allowed:1,return_days:7,replacement_allowed:1,replacement_days:7,is_active:1},on.length+' products — same policy for all')});
})();
</script>
JS;
include __DIR__ . '/../partials/footer.php';
