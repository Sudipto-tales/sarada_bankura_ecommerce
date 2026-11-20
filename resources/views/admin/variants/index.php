<?php
$active = 'variants';
$title = 'Variants & options';
$groups = $groups ?? [];
$values = $values ?? [];
$variants = $variants ?? [];
$units = $units ?? [];
$stats = $stats ?? ['products' => 0, 'catalog' => 0, 'skus' => 0, 'active' => 0, 'groups' => 0];
$productTotal = max(1, (int) ($productTotal ?? 0));
$success = $success ?? null;
$error = $error ?? null;
$statuses = \App\Controllers\Admin\VariantController::STATUSES;
$statusTone = ['active' => 'ok', 'inactive' => 'warn', 'archived' => 'off'];
$sym = ['INR' => '₹', 'USD' => '$', 'EUR' => '€'];
$groupIcons = ['thickness' => 'ruler', 'finish' => 'sparkles', 'size' => 'scan', 'grade' => 'award'];

$productNames = [];
foreach ($variants as $v) {
    $productNames[(int) $v['pid']] = $v['product_name'];
}
asort($productNames);

include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs">Catalog <i data-lucide="chevron-right"></i> Variants &amp; options</div><h1>Variants &amp; options<span style="color:#dca71f">.</span></h1></div>
  <div class="flex gap-2">
    <button class="btn" type="button" id="exportBtn"><i data-lucide="download" style="width:16px"></i>Export</button>
    <button class="btn gold" type="button" data-open-groups><i data-lucide="sparkles" style="width:16px"></i>Manage product options</button>
  </div>
</div>
<?php if ($success): ?><div class="notice ok">✓ <?= e($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice bad"><?= e($error) ?></div><?php endif; ?>

<div class="statstrip">
  <div class="stat glass"><span class="metric-icon"><i data-lucide="box"></i></span><div><small>Variant products</small><b><?= $stats['products'] ?></b><em><?= $stats['catalog'] ?> products in catalogue</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#e3eef8;color:#3d6a92"><i data-lucide="tag"></i></span><div><small>Variant SKUs</small><b><?= $stats['skus'] ?></b><em><?= $stats['active'] ?> active</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#fbf0e4;color:#a2632a"><i data-lucide="eye-off"></i></span><div><small>Inactive / archived</small><b><?= $stats['skus'] - $stats['active'] ?></b><em>Hidden from storefront</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#eaf5e9;color:#2f7650"><i data-lucide="layers"></i></span><div><small>Option groups</small><b><?= $stats['groups'] ?></b><em>Reusable choices</em></div></div>
</div>

<div class="card" style="margin-bottom:18px">
  <div class="card-h" style="align-items:flex-start">
    <div><div class="eyebrow">Option architecture</div><h3>Reusable product choices</h3><p class="text-xs muted mt-1">Option groups shape variants across the catalogue. Pick them in the product editor → Pricing &amp; variants.</p></div>
    <span class="text-xs muted"><?= count($groups) ?> option group<?= count($groups) === 1 ? '' : 's' ?></span>
  </div>
  <div class="optgrid">
    <?php foreach ($groups as $g): $gv = $values[(int) $g['id']] ?? []; $pct = (int) round((int) $g['product_count'] / $productTotal * 100); ?>
    <button type="button" class="option-card" data-open-groups data-group="<?= e((string) $g['name']) ?>">
      <span class="iconbox"><i data-lucide="<?= $groupIcons[$g['slug']] ?? 'tag' ?>"></i></span>
      <span class="min-w-0" style="flex:1"><b><?= e((string) $g['name']) ?></b><small><?= (int) $g['product_count'] ?> product<?= (int) $g['product_count'] === 1 ? '' : 's' ?> · <?= count($gv) ?> value<?= count($gv) === 1 ? '' : 's' ?></small></span>
      <span class="pill pill-gold"><?= $pct ?>%</span>
    </button>
    <?php endforeach; ?>
    <?php if (!$groups): ?><p class="text-xs muted">No option groups yet — create one with “Manage product options”.</p><?php endif; ?>
  </div>
</div>

<div class="card toolbar-card">
  <select id="productFilter" class="btn"><option value="">All variant products</option><?php foreach ($productNames as $pid => $pn): ?><option value="<?= $pid ?>"><?= e((string) $pn) ?></option><?php endforeach; ?></select>
  <select id="statusFilter" class="btn"><option value="">All statuses</option><?php foreach ($statuses as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select>
  <label class="search-in"><i data-lucide="search"></i><input id="variantSearch" placeholder="Search combination, SKU or product"></label>
</div>

<div class="card" style="padding:0">
  <div class="card-h" style="padding:20px 20px 0;align-items:flex-start">
    <div><div class="eyebrow">Variant matrix</div><h3>Sellable combinations</h3><p class="text-xs muted mt-1">Keep SKU, price, unit and status aligned with the parent product.</p></div>
    <span class="text-xs muted" id="matchCount"><?= count($variants) ?> matching variants</span>
  </div>
  <div class="tablewrap" style="border:0;border-radius:0;margin-top:14px">
    <table class="data" id="variantTable">
      <thead><tr><th data-sort="product">Product</th><th data-sort="title">Combination</th><th data-sort="sku">Variant SKU</th><th data-sort="price">Price</th><th data-sort="status">Status</th><th></th></tr></thead>
      <tbody>
        <?php if (!$variants): ?>
        <tr><td colspan="6" style="text-align:center;padding:40px" class="muted">No variants yet. Open a product → <b>Pricing &amp; variants</b>, pick option groups and generate combinations.</td></tr>
        <?php endif; ?>
        <?php foreach ($variants as $v):
            $unit = $v['unit'] ?: $v['product_unit'];
            $cur = $sym[$v['currency'] ?? 'INR'] ?? '₹';
            $edit = e((string) json_encode([
                'sku' => $v['sku'], 'price' => $v['price'], 'compare_at_price' => $v['compare_at_price'],
                'unit' => $v['unit'] ?? '', 'status' => $v['status'], 'label' => $v['product_name'] . ' · ' . $v['title'],
                'action' => route('admin.variants.update', ['id' => $v['id']]),
            ]));
        ?>
        <tr data-row data-pid="<?= (int) $v['pid'] ?>" data-status="<?= e((string) $v['status']) ?>" data-product="<?= e((string) $v['product_name']) ?>" data-title="<?= e((string) $v['title']) ?>" data-sku="<?= e((string) $v['sku']) ?>" data-price="<?= e((string) ($v['price'] ?? '')) ?>">
          <td><span class="flex items-center gap-3">
            <span class="prodthumb"><?php if ($v['cover']): ?><img src="<?= base_url((string) $v['cover']) ?>" alt=""><?php else: ?><i data-lucide="gem"></i><?php endif; ?></span>
            <span><a href="<?= route('admin.products.edit', ['id' => $v['pid']]) ?>"><b><?= e((string) $v['product_name']) ?></b></a><small class="block muted"><?= e((string) ($v['brand_name'] ?: $v['product_sku'])) ?></small></span>
          </span></td>
          <td><b><?= e((string) $v['title']) ?></b><?= (int) $v['is_default'] ? ' <span class="pill pill-gold">Default</span>' : '' ?></td>
          <td class="muted"><code style="font-size:12px"><?= e((string) $v['sku']) ?></code></td>
          <td><?php if ($v['price'] !== null && $v['price'] !== ''): ?><b><?= $cur . number_format((float) $v['price'], 2) ?></b><?= $unit ? '<span class="muted text-xs"> / ' . e((string) $unit) . '</span>' : '' ?><?php else: ?><span class="muted">On request</span><?php endif; ?></td>
          <td><span class="status-dot <?= $statusTone[$v['status']] ?? 'off' ?>"><?= e($statuses[$v['status']] ?? (string) $v['status']) ?></span></td>
          <td class="row-actions">
            <button type="button" class="iconbtn" title="Edit variant" data-edit-variant="<?= $edit ?>"><i data-lucide="pencil"></i></button>
            <button type="button" class="iconbtn danger" title="Delete variant" data-del="<?= e(route('admin.variants.delete', ['id' => $v['id']])) ?>" data-del-name="<?= e((string) $v['sku']) ?>"><i data-lucide="trash-2"></i></button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Manage option groups -->
<div class="modal-backdrop" id="groupModal">
  <div class="modal">
    <div class="modal-h">
      <div><div class="eyebrow">Product options</div><h3>Manage option groups</h3><p class="text-xs muted mt-1">Create reusable choices for product variants. Using an existing group name adds the new values to it.</p></div>
      <button type="button" class="iconbtn" data-close><i data-lucide="x"></i></button>
    </div>
    <form method="post" action="<?= route('admin.options.store') ?>" class="stack" id="groupForm">
      <?= csrf_field() ?>
      <div class="field"><label>Option group name</label><input required name="name" placeholder="e.g. Thickness" list="groupNames"><datalist id="groupNames"><?php foreach ($groups as $g): ?><option value="<?= e((string) $g['name']) ?>"></option><?php endforeach; ?></datalist></div>
      <div class="field"><label>Option values <span class="muted">(comma separated)</span></label><input required name="values" placeholder="16 mm, 18 mm, 20 mm"></div>
      <div class="modal-f" style="margin-top:0"><button type="button" class="btn" data-close>Cancel</button><button class="btn gold" type="submit"><i data-lucide="plus" style="width:16px"></i>Save option group</button></div>
    </form>
    <div class="existing">
      <div class="text-xs font-extrabold mb-2">Existing option groups</div>
      <?php foreach ($groups as $g): ?>
      <div class="groupline">
        <b><?= e((string) $g['name']) ?></b>
        <span class="chips">
          <?php foreach ($values[(int) $g['id']] ?? [] as $ov): ?>
          <span class="chip"><?= e((string) $ov['value']) ?><?php if (!(int) $ov['used']): ?><button type="button" title="Remove value" data-del="<?= e(route('admin.options.values.delete', ['id' => $ov['id']])) ?>" data-del-name="<?= e((string) $ov['value']) ?>">×</button><?php endif; ?></span>
          <?php endforeach; ?>
        </span>
        <?php if (!(int) $g['product_count']): ?><button type="button" class="iconbtn danger" title="Delete group" data-del="<?= e(route('admin.options.delete', ['id' => $g['id']])) ?>" data-del-name="<?= e((string) $g['name']) ?>"><i data-lucide="trash-2"></i></button><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Edit a variant -->
<div class="modal-backdrop" id="variantModal">
  <form class="modal" method="post" id="variantForm">
    <?= csrf_field() ?>
    <div class="modal-h">
      <div><div class="eyebrow">Variant</div><h3 id="variantLabel">Edit variant</h3><p class="text-xs muted mt-1">To change the combination itself, edit the parent product.</p></div>
      <button type="button" class="iconbtn" data-close><i data-lucide="x"></i></button>
    </div>
    <div class="stack">
      <div class="grid2">
        <div class="field"><label>Variant SKU *</label><input name="sku" required></div>
        <div class="field"><label>Status</label><select name="status"><?php foreach ($statuses as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="grid3">
        <div class="field"><label>Price</label><input name="price" type="number" step="0.01" placeholder="On request"></div>
        <div class="field"><label>Compare-at</label><input name="compare_at_price" type="number" step="0.01"></div>
        <div class="field"><label>Unit</label><select name="unit"><option value="">Product unit</option><?php foreach ($units as $u): ?><option value="<?= e((string) $u['symbol']) ?>"><?= e((string) $u['symbol']) ?></option><?php endforeach; ?></select></div>
      </div>
    </div>
    <div class="modal-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn gold" type="submit"><i data-lucide="check" style="width:16px"></i>Save variant</button></div>
  </form>
</div>

<?php
$scripts = <<<'JS'
<script>
(function(){
  const $=id=>document.getElementById(id);
  const gm=$('groupModal'),vm=$('variantModal');
  [gm,vm].forEach(m=>{m.querySelectorAll('[data-close]').forEach(b=>b.onclick=()=>m.classList.remove('show'));m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('show')})});
  document.querySelectorAll('[data-open-groups]').forEach(b=>b.onclick=()=>{
    const f=$('groupForm');f.reset();if(b.dataset.group)f.elements.name.value=b.dataset.group;
    gm.classList.add('show');(b.dataset.group?f.elements.values:f.elements.name).focus();
  });
  document.querySelectorAll('[data-edit-variant]').forEach(b=>b.onclick=()=>{
    const d=JSON.parse(b.dataset.editVariant),f=$('variantForm');
    f.action=d.action;$('variantLabel').textContent=d.label;
    ['sku','price','compare_at_price','unit','status'].forEach(k=>f.elements[k].value=d[k]??'');
    vm.classList.add('show');
  });

  const rows=[...document.querySelectorAll('tr[data-row]')],tbody=rows[0]?.parentNode;
  function filter(){
    const q=$('variantSearch').value.toLowerCase(),p=$('productFilter').value,s=$('statusFilter').value;let n=0;
    rows.forEach(r=>{const ok=(!p||r.dataset.pid===p)&&(!s||r.dataset.status===s)&&(!q||(r.dataset.product+' '+r.dataset.title+' '+r.dataset.sku).toLowerCase().includes(q));r.classList.toggle('hidden',!ok);if(ok)n++});
    $('matchCount').textContent=n+' matching variant'+(n===1?'':'s');
  }
  ['variantSearch','productFilter','statusFilter'].forEach(id=>$(id).addEventListener('input',filter));
  let key='',asc=true;
  document.querySelectorAll('#variantTable th[data-sort]').forEach(th=>th.onclick=()=>{
    const k=th.dataset.sort;asc=key===k?!asc:true;key=k;
    rows.sort((a,b)=>{const x=a.dataset[k]||'',y=b.dataset[k]||'';return (k==='price'?(+x||0)-(+y||0):x.localeCompare(y,undefined,{numeric:true}))*(asc?1:-1)}).forEach(r=>tbody.appendChild(r));
    document.querySelectorAll('#variantTable th').forEach(t=>t.classList.toggle('sorted',t===th));
  });
  $('exportBtn').onclick=()=>{
    const data=[['Product','Combination','SKU','Price','Status'],...rows.filter(r=>!r.classList.contains('hidden')).map(r=>[r.dataset.product,r.dataset.title,r.dataset.sku,r.dataset.price,r.dataset.status])];
    const csv=data.map(r=>r.map(v=>'"'+String(v).replaceAll('"','""')+'"').join(',')).join('\n');
    const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));a.download='maa-sarada-variants.csv';a.click();
  };
  msBindDelete();
})();
</script>
JS;
include __DIR__ . '/../partials/footer.php';
?>
