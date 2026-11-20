<?php
use App\Controllers\Admin\AttributeController;

$active = 'attributes';
$title = 'Attributes';
$attrs = $attrs ?? [];
$units = $units ?? [];
$stats = $stats ?? ['active' => 0, 'archived' => 0, 'values' => 0, 'variant' => 0];
$editId = $editId ?? 0;
$success = $success ?? null;
$error = $error ?? null;
$tints = ['tint-green', 'tint-blue', 'tint-pink', 'tint-orange', 'tint-yellow'];
$displayIcon = ['text' => 'type', 'swatch' => 'palette', 'size' => 'ruler', 'measure' => 'ruler-dimension-line'];
$fmt = static fn(array $v): string => trim($v['label'] . ($v['unit'] ? ' ' . $v['unit'] : ''));

$payload = [];
foreach ($attrs as $a) {
    $payload[(int) $a['id']] = [
        'id' => (int) $a['id'],
        'name' => $a['name'],
        'display' => $a['display'] ?: 'text',
        'used_for' => $a['used_for'] ?: 'filters',
        'status' => $a['status'] ?: 'active',
        'unit' => $a['unit'] ?? '',
        'selection' => $a['type'] === 'multiselect' ? 'multiple' : 'single',
        'description' => $a['description'] ?? '',
        'products' => (int) $a['product_count'],
        'action' => route('admin.attributes.update', ['id' => $a['id']]),
        'delete' => route('admin.attributes.delete', ['id' => $a['id']]),
        'values' => array_map(static fn($v) => ['label' => $v['label'], 'swatch' => $v['swatch'], 'unit' => $v['unit']], $a['values']),
    ];
}

include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs">Catalog <i data-lucide="chevron-right"></i> Attributes</div><h1>Attributes<span style="color:#dca71f">.</span></h1></div>
  <a class="btn" href="<?= route('admin.units') ?>"><i data-lucide="ruler" style="width:16px"></i>Manage units</a>
</div>
<?php include __DIR__ . '/../partials/catalog-tabs.php'; ?>
<?php if ($success): ?><div class="notice ok">✓ <?= e($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice bad"><?= e($error) ?></div><?php endif; ?>

<div class="statstrip">
  <div class="stat glass"><span class="metric-icon"><i data-lucide="sliders-horizontal"></i></span><div><small>Active attributes</small><b><?= $stats['active'] ?></b><em><?= $stats['archived'] ?> archived</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#e3eef8;color:#3d6a92"><i data-lucide="list"></i></span><div><small>Predefined values</small><b><?= $stats['values'] ?></b><em>Reusable on every product</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#efe7f6;color:#6f4a92"><i data-lucide="git-branch"></i></span><div><small>Variant-ready</small><b><?= $stats['variant'] ?></b><em>Size, thickness, finish…</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#fff0bf;color:#9b6c0a"><i data-lucide="ruler"></i></span><div><small>Units available</small><b><?= count($units) ?></b><em>mm, ft, sq ft, box…</em></div></div>
</div>

<div class="attr-layout">
  <section class="card">
    <div class="card-h" style="align-items:flex-start">
      <div>
        <h3>Your attribute library</h3>
        <p class="text-xs muted mt-1">Reusable choices for every marble &amp; stone product.</p>
        <label class="checkline mt-2"><input type="checkbox" id="showArchived"> Archived attributes</label>
      </div>
      <button class="btn" type="button" data-new-attr><i data-lucide="plus" style="width:16px"></i>New</button>
    </div>

    <?php if (!$attrs): ?><p class="muted text-sm" style="padding:30px;text-align:center">No attributes yet — create your first one.</p><?php endif; ?>
    <?php foreach ($attrs as $i => $a): $archived = $a['status'] === 'archived'; $vals = $a['values']; ?>
    <article class="attr-card <?= $tints[$i % count($tints)] ?><?= $archived ? ' is-archived hidden' : '' ?>" data-attr-card="<?= (int) $a['id'] ?>">
      <div class="attr-top">
        <b><i data-lucide="<?= $displayIcon[$a['display']] ?? 'type' ?>"></i><?= e((string) $a['name']) ?></b>
        <span class="<?= $archived ? 'muted' : 'attr-status' ?>"><?= $archived ? 'Archived' : 'Active' ?></span>
      </div>
      <div class="vchips">
        <?php if (!$vals): ?><span class="muted text-xs">Free entry on each product<?= $a['unit'] ? ' · in ' . e((string) $a['unit']) : '' ?></span><?php endif; ?>
        <?php foreach (array_slice($vals, 0, 14) as $v): ?>
        <span class="vchip"><?php if ($v['swatch']): ?><i class="sw" style="background:<?= e((string) $v['swatch']) ?>"></i><?php endif; ?><?= e($fmt($v)) ?></span>
        <?php endforeach; ?>
        <?php if (count($vals) > 14): ?><span class="vchip muted">+<?= count($vals) - 14 ?> more</span><?php endif; ?>
      </div>
      <div class="attr-foot">
        <span><?= e(AttributeController::USED_FOR[$a['used_for']] ?? 'Filters only') ?><?= $a['type'] === 'multiselect' ? ' · multi-select' : '' ?> · <?= (int) $a['product_count'] ?> product<?= (int) $a['product_count'] === 1 ? '' : 's' ?></span>
        <button type="button" class="linkbtn" data-edit-attr="<?= (int) $a['id'] ?>">Edit <i data-lucide="arrow-right"></i></button>
      </div>
    </article>
    <?php endforeach; ?>
  </section>

  <form class="card attr-form" method="post" action="<?= route('admin.attributes.store') ?>" id="attrForm" data-store="<?= route('admin.attributes.store') ?>">
    <?= csrf_field() ?>
    <div class="card-h" style="align-items:flex-start;margin-bottom:6px">
      <div><h3 id="attrTitle" style="font-size:19px">Create an attribute</h3><p class="text-xs muted mt-1" id="attrHelp">Name each value. Colour swatches pick real colours; sizes and measurements pick a unit per value.</p></div>
      <button type="button" class="btn ghost hidden" id="attrCancel" style="white-space:nowrap;flex-shrink:0"><i data-lucide="x" style="width:15px"></i>Cancel edit</button>
    </div>

    <div class="subbox attr-values">
      <div class="attr-namerow">
        <div class="field"><label>Option name</label><input name="name" required placeholder="e.g. Colour or Size" maxlength="80"></div>
        <div class="field"><label>Display as</label>
          <select name="display" id="attrDisplay">
            <?php foreach (AttributeController::DISPLAYS as $k => $label): ?><option value="<?= $k ?>"><?= e($label) ?></option><?php endforeach; ?>
          </select>
        </div>
        <button type="button" class="iconbtn danger hidden" id="attrDelete" title="Delete attribute"><i data-lucide="trash-2"></i></button>
      </div>

      <div class="field hidden" id="unitField" style="margin-top:14px">
        <label>Default unit <span class="muted" style="font-weight:600">· each value can override it</span></label>
        <select name="unit" id="attrUnit"></select>
      </div>

      <div class="valhead" id="valHead"></div>
      <div id="valRows" class="valrows"></div>
      <button type="button" class="addval" id="addVal"><i data-lucide="plus"></i>Add value</button>
      <p class="hint" id="valHint">Leave values empty to let staff type a free value on each product.</p>
    </div>

    <div class="grid3" style="margin-top:18px">
      <div class="field"><label>Used for</label>
        <select name="used_for"><?php foreach (AttributeController::USED_FOR as $k => $label): ?><option value="<?= $k ?>"><?= e($label) ?></option><?php endforeach; ?></select>
      </div>
      <div class="field"><label>Selection on product</label>
        <select name="selection"><option value="single">One value</option><option value="multiple">Multiple values</option></select>
      </div>
      <div class="field"><label>Status <span style="color:#c2523c">*</span></label>
        <select name="status"><option value="active">Active</option><option value="archived">Archived</option></select>
      </div>
    </div>
    <div class="field" style="margin-top:16px"><label>Description <span class="muted" style="font-weight:600">(optional)</span></label><input name="description" placeholder="e.g. Slab / tile thickness, shown in the spec table" maxlength="255"></div>

    <p class="text-xs muted" style="margin:16px 0 18px">Updates apply when this attribute is next chosen on a product. Values already saved on products stay unchanged.</p>
    <button class="btn gold" type="submit" id="attrSubmit"><i data-lucide="check" style="width:16px"></i>Save attribute</button>
  </form>
</div>

<script type="application/json" id="attrData"><?= json_encode(['attrs' => $payload, 'units' => $units, 'edit' => $editId], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php
$scripts = <<<'JS'
<script>
(function(){
  const data=JSON.parse(document.getElementById('attrData').textContent);
  const form=document.getElementById('attrForm'),rows=document.getElementById('valRows'),head=document.getElementById('valHead');
  const display=document.getElementById('attrDisplay'),unitSel=document.getElementById('attrUnit'),unitField=document.getElementById('unitField');
  const el=n=>form.elements[n];
  let seq=0;
  const esc=s=>String(s??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

  function unitOptions(selected,withInherit){
    const mode=display.value;
    const list=data.units.filter(u=>mode==='size'?u.kind==='length':true);
    let h=withInherit?'<option value="">Default</option>':'<option value="">— none —</option>';
    list.forEach(u=>h+=`<option value="${esc(u.symbol)}" ${u.symbol===selected?'selected':''}>${esc(u.symbol)}</option>`);
    return h;
  }
  function headFor(mode){
    return {text:'<span>Value</span>',swatch:'<span>Colour</span><span>Name</span>',size:'<span>Length</span><span></span><span>Width</span><span>Unit</span>',measure:'<span>Value</span><span>Unit</span>'}[mode];
  }
  function rowHtml(v){
    const i=seq++,mode=display.value,n=`values[${i}]`;
    let inner='';
    if(mode==='swatch'){
      inner=`<input type="color" name="${n}[swatch]" value="${esc(v.swatch||'#d9cdb5')}" class="swpick" aria-label="Colour">
             <input name="${n}[label]" value="${esc(v.label)}" placeholder="e.g. Statuario White" class="grow">`;
    }else if(mode==='size'){
      const [a,b]=String(v.label||'').split('×').map(s=>s.trim());
      inner=`<input name="${n}[a]" value="${esc(a)}" inputmode="decimal" placeholder="600" class="num">
             <span class="times">×</span>
             <input name="${n}[b]" value="${esc(b)}" inputmode="decimal" placeholder="1200" class="num">
             <select name="${n}[unit]" class="unitpick">${unitOptions(v.unit||'',true)}</select>`;
    }else if(mode==='measure'){
      inner=`<input name="${n}[a]" value="${esc(v.label)}" inputmode="decimal" placeholder="18" class="num grow">
             <select name="${n}[unit]" class="unitpick">${unitOptions(v.unit||'',true)}</select>`;
    }else{
      inner=`<i data-lucide="tag" class="lead"></i><input name="${n}[label]" value="${esc(v.label)}" placeholder="e.g. Polished" class="grow">`;
    }
    return `<div class="valrow mode-${mode}">${inner}<button type="button" class="valx" title="Remove" aria-label="Remove value">×</button></div>`;
  }
  function addRow(v,focus){
    rows.insertAdjacentHTML('beforeend',rowHtml(v||{}));
    lucide.createIcons();
    if(focus){const inp=rows.lastElementChild.querySelector('input:not([type=color])');inp&&inp.focus();}
  }
  function readRows(){
    // Keep entered values when switching display mode.
    return [...rows.querySelectorAll('.valrow')].map(r=>{
      const g=s=>{const x=r.querySelector(s);return x?x.value.trim():''};
      const a=g('[name$="[a]"]'),b=g('[name$="[b]"]');
      return {label:g('[name$="[label]"]')||(a&&b?a+' × '+b:a),swatch:g('[name$="[swatch]"]'),unit:g('[name$="[unit]"]')};
    }).filter(v=>v.label);
  }
  function render(values){
    const mode=display.value,hasUnit=mode==='size'||mode==='measure';
    unitField.classList.toggle('hidden',!hasUnit);
    const cur=unitSel.value||(unitSel.dataset.pending||'');
    unitSel.innerHTML=unitOptions(cur,false);
    if(hasUnit&&!unitSel.value){unitSel.value=data.units.some(u=>u.symbol==='mm')?'mm':'';}
    head.className='valhead mode-'+mode;head.innerHTML=headFor(mode)+'<span></span>';
    rows.innerHTML='';
    (values.length?values:[{}]).forEach(v=>addRow(v,false));
    document.getElementById('valHint').textContent=mode==='size'
      ?'Enter length × width; pick mm, cm, inch or ft per size. "Default" uses the default unit.'
      :mode==='measure'?'Enter just the number — the unit is picked per value or from the default unit.'
      :mode==='swatch'?'Pick the closest colour; shoppers see it as a swatch on filters.'
      :'Leave values empty to let staff type a free value on each product.';
  }
  function open(a,quiet){
    form.reset();
    const edit=!!a;
    a=a||{display:'text',used_for:'variants_filters',status:'active',selection:'single',values:[]};
    form.action=edit?a.action:form.dataset.store;
    el('name').value=a.name||'';display.value=a.display;el('used_for').value=a.used_for;
    el('status').value=a.status;el('selection').value=a.selection;el('description').value=a.description||'';
    unitSel.innerHTML='';unitSel.dataset.pending=a.unit||'';
    render(a.values);
    unitSel.dataset.pending='';
    document.getElementById('attrTitle').textContent=edit?'Edit “'+a.name+'”':'Create an attribute';
    document.getElementById('attrCancel').classList.toggle('hidden',!edit);
    const del=document.getElementById('attrDelete');del.classList.toggle('hidden',!edit);
    del.dataset.del=edit?a.delete:'';del.dataset.delName=a.name||'';
    del.title=edit&&a.products?'Used on '+a.products+' product(s) — archive instead':'Delete attribute';
    document.querySelectorAll('[data-attr-card]').forEach(c=>c.classList.toggle('editing',edit&&+c.dataset.attrCard===a.id));
    if(quiet)return;
    if(edit&&window.innerWidth<961)form.scrollIntoView({behavior:'smooth'});
    el('name').focus();
  }

  display.addEventListener('change',()=>render(readRows()));
  document.getElementById('addVal').onclick=()=>addRow({},true);
  rows.addEventListener('click',e=>{
    const x=e.target.closest('.valx');if(!x)return;
    x.closest('.valrow').remove();if(!rows.children.length)addRow({},false);
  });
  rows.addEventListener('keydown',e=>{
    if(e.key==='Enter'&&e.target.matches('input')){e.preventDefault();
      const row=e.target.closest('.valrow');
      if(row===rows.lastElementChild)addRow({},true);else{const n=row.nextElementSibling.querySelector('input:not([type=color])');n&&n.focus();}
    }
  });
  document.getElementById('attrDelete').onclick=function(){
    if(!this.dataset.del||!confirm('Delete "'+this.dataset.delName+'"? This cannot be undone.'))return;
    const f=document.getElementById('msDelForm');f.action=this.dataset.del;f.submit();
  };
  document.querySelectorAll('[data-new-attr]').forEach(b=>b.onclick=()=>open(null));
  document.getElementById('attrCancel').onclick=()=>open(null);
  document.querySelectorAll('[data-edit-attr]').forEach(b=>b.onclick=()=>open(data.attrs[b.dataset.editAttr]));
  document.getElementById('showArchived').onchange=function(){
    document.querySelectorAll('.attr-card.is-archived').forEach(c=>c.classList.toggle('hidden',!this.checked));
  };
  open(data.edit&&data.attrs[data.edit]?data.attrs[data.edit]:null,true);
})();
</script>
JS;
include __DIR__ . '/../partials/footer.php';
?>
