<?php
use App\Services\Customer\SegmentMatcher;

$active = 'customers.segments';
$title = $isEdit ? 'Edit segment' : 'New segment';
$hasOld = session()->hasOldInput();
$v = static fn(string $k, mixed $d = '') => $hasOld ? old($k, $d) : ($seg[$k] ?? $d);
$initial = $hasOld ? array_values(array_filter((array) old('conditions', []), 'is_array')) : $conditions;
$rowErrors = [];
foreach (errors() as $k => $msgs) {
    if (preg_match('/^conditions\.(\d+)$/', $k, $m)) $rowErrors[(int) $m[1]] = $msgs[0];
}
$type = (string) $v('type', 'dynamic');
$match = (string) $v('match_type', 'all');
$isActive = (int) $v('is_active', 1);

$groups = [];
foreach ($fields as $key => $def) {
    $groups[$def['group']][$key] = $def;
}
$cfg = [
    'fields' => array_map(static fn($d) => ['label' => $d['label'], 'group' => $d['group'], 'type' => $d['type'], 'options' => $d['options'] ?? null, 'hint' => $d['hint'] ?? null], $fields),
    'groups' => SegmentMatcher::GROUPS,
    'operators' => SegmentMatcher::OPERATORS,
    'noValue' => SegmentMatcher::NO_VALUE,
    'tags' => array_map(static fn($t) => ['id' => (int) $t['id'], 'name' => $t['name']], $tags),
    'initial' => $initial ?: [['field' => 'total_spent', 'operator' => 'gte', 'value' => '']],
    'errors' => (object) $rowErrors,
    'preview' => route('admin.customers.segments.preview'),
    'total' => $totalCustomers,
];

include __DIR__ . '/../../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs"><a href="<?= route('admin.customers') ?>">Customers</a> <i data-lucide="chevron-right"></i> <a href="<?= route('admin.customers.segments') ?>">Segments</a> <i data-lucide="chevron-right"></i> <?= $isEdit ? e((string) $seg['name']) : 'New' ?></div>
    <h1><?= $isEdit ? 'Edit segment' : 'New segment' ?><span style="color:#dca71f">.</span></h1></div>
  <div class="flex gap-2">
    <a class="btn" href="<?= $isEdit ? route('admin.customers.segments.show', ['id' => $seg['id']]) : route('admin.customers.segments') ?>">Cancel</a>
    <button class="btn gold" type="submit" form="segForm"><i data-lucide="check" style="width:16px"></i><?= $isEdit ? 'Save segment' : 'Create segment' ?></button>
  </div>
</div>

<form id="segForm" method="post" action="<?= $isEdit ? route('admin.customers.segments.update', ['id' => $seg['id']]) : route('admin.customers.segments.store') ?>" novalidate>
  <?= csrf_field() ?>
  <div class="layout">
    <div class="stack" style="gap:18px">
      <section class="card">
        <div class="sectionhead" style="margin-bottom:18px"><span class="sectionicon"><i data-lucide="filter"></i></span><div><h2>Segment details</h2><p class="text-xs muted">A name your team will recognise in filters and campaigns.</p></div></div>
        <div class="stack">
          <div class="grid2">
            <div class="field"><label for="segName">Name *</label><input id="segName" name="name" value="<?= e((string) $v('name')) ?>" maxlength="120" required placeholder="e.g. Kolkata builders"<?= error('name') ? ' aria-invalid="true" class="is-bad"' : '' ?>><?= error('name') ? '<div class="err">' . e((string) error('name')) . '</div>' : '' ?></div>
            <div class="field"><label for="segDesc">Description</label><input id="segDesc" name="description" value="<?= e((string) $v('description')) ?>" maxlength="255" placeholder="Who is in it and why"></div>
          </div>
          <div class="field"><label>Segment type</label>
            <div class="seg-types">
              <label><input type="radio" name="type" value="dynamic"<?= $type !== 'static' ? ' checked' : '' ?>><span><i data-lucide="sparkles"></i><b>Dynamic</b><small>Follows rules — customers join and leave automatically.</small></span></label>
              <label><input type="radio" name="type" value="static"<?= $type === 'static' ? ' checked' : '' ?>><span><i data-lucide="list-checks"></i><b>Static</b><small>A fixed list you add customers to by hand.</small></span></label>
            </div>
          </div>
          <label class="cu-consent"><input type="hidden" name="is_active" value="0"><input type="checkbox" class="switch-in" name="is_active" value="1"<?= $isActive ? ' checked' : '' ?>>
            <span><b>Active</b><small>Paused segments are hidden from the customer-list tabs.</small></span></label>
        </div>
      </section>

      <section class="card" id="rulesCard"<?= $type === 'static' ? ' hidden' : '' ?>>
        <div class="card-h" style="align-items:flex-start">
          <div class="sectionhead" style="margin:0"><span class="sectionicon" style="background:#e3eef8;color:#3d6a92"><i data-lucide="list-filter"></i></span><div><h2>Rules</h2><p class="text-xs muted">Customers who match</p></div></div>
          <div class="seg-match" role="radiogroup" aria-label="Match">
            <label><input type="radio" name="match_type" value="all"<?= $match !== 'any' ? ' checked' : '' ?>><span>All rules</span></label>
            <label><input type="radio" name="match_type" value="any"<?= $match === 'any' ? ' checked' : '' ?>><span>Any rule</span></label>
          </div>
        </div>
        <?= error('conditions') ? '<div class="err" style="margin:-4px 0 12px">' . e((string) error('conditions')) . '</div>' : '' ?>
        <div id="segRules" class="seg-rows"></div>
        <button type="button" class="btn" id="segAdd" style="margin-top:12px"><i data-lucide="plus" style="width:16px"></i>Add rule</button>
        <div class="seg-groups-hint">
          <?php foreach ($groups as $g => $defs): ?><span><b><?= e($g) ?></b> <?= e(implode(', ', array_map(static fn($d) => $d['label'], $defs))) ?></span><?php endforeach; ?>
        </div>
      </section>

      <section class="card" id="staticCard"<?= $type === 'static' ? '' : ' hidden' ?>>
        <div class="sectionhead" style="margin:0"><span class="sectionicon" style="background:#e4f4e9;color:#267a55"><i data-lucide="hand"></i></span><div><h2>Hand-picked members</h2><p class="text-xs muted">Save the segment, then add customers from its page.</p></div></div>
      </section>
    </div>

    <aside class="rightcol">
      <section class="rightcard seg-preview" id="segPreview" aria-live="polite">
        <div class="eyebrow">Live preview</div>
        <div class="seg-big"><b data-count>—</b><small>of <?= number_format($totalCustomers) ?> customers match</small></div>
        <div class="seg-bar lg"><i data-bar style="width:0"></i></div>
        <div class="seg-sample" data-sample></div>
        <p class="text-xs muted" data-note style="margin:10px 0 0">Updates as you edit the rules.</p>
      </section>
      <section class="rightcard">
        <div class="eyebrow">Good to know</div>
        <ul class="seg-tips">
          <li>Orders, spend and last-order rules start matching when the orders module goes live.</li>
          <li>City, district, state and pincode match <em>any</em> of the customer’s addresses.</li>
          <li>Dates use Indian time (IST).</li>
        </ul>
      </section>
    </aside>
  </div>
</form>
<?php
$scripts = '<script>window.SEG=' . json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . ';</script>' . <<<'JS'
<script>
(function(){
  var C=window.SEG,box=document.getElementById('segRules'),form=document.getElementById('segForm'),n=0,timer=null,seq=0;
  var token=(document.querySelector('meta[name=csrf-token]')||{}).content||'';
  function el(tag,attrs,text){var e=document.createElement(tag);Object.keys(attrs||{}).forEach(function(k){e.setAttribute(k,attrs[k])});if(text!=null)e.textContent=text;return e;}
  function fieldSelect(name,val){
    var s=el('select',{name:name,'aria-label':'Field'});
    C.groups.forEach(function(g){
      var og=el('optgroup',{label:g});
      Object.keys(C.fields).forEach(function(k){if(C.fields[k].group===g){var o=el('option',{value:k},C.fields[k].label);if(k===val)o.selected=true;og.appendChild(o);}});
      s.appendChild(og);
    });
    return s;
  }
  function valueInput(row,def,op,val){
    var i=row.dataset.i,wrap=row.querySelector('.seg-val');wrap.innerHTML='';
    var name='conditions['+i+'][value]',input;
    if(C.noValue.indexOf(op)>=0){wrap.appendChild(el('input',{type:'hidden',name:name,value:''}));wrap.classList.add('none');return;}
    wrap.classList.remove('none');
    if(def.type==='select'){input=el('select',{name:name,'aria-label':'Value'});Object.keys(def.options).forEach(function(k){var o=el('option',{value:k},def.options[k]);if(k===val)o.selected=true;input.appendChild(o);});}
    else if(def.type==='tag'){input=el('select',{name:name,'aria-label':'Tag'});if(!C.tags.length)input.appendChild(el('option',{value:''},'No customer tags yet'));C.tags.forEach(function(t){var o=el('option',{value:String(t.id)},t.name);if(String(t.id)===String(val))o.selected=true;input.appendChild(o);});}
    else if(def.type==='date'&&(op==='before'||op==='after')){input=el('input',{type:'date',name:name,value:/^\d{4}-\d{2}-\d{2}$/.test(val||'')?val:'','aria-label':'Date'});}
    else if(def.type==='date'){input=el('input',{type:'number',min:'1',step:'1',name:name,value:val||'',placeholder:'90','aria-label':'Days'});wrap.appendChild(input);wrap.appendChild(el('span',{class:'seg-unit'},'days'));return;}
    else if(def.type==='number'){input=el('input',{type:'number',step:'any',min:'0',name:name,value:val||'',placeholder:'0','aria-label':'Value'});}
    else{input=el('input',{type:'text',name:name,value:val||'',maxlength:'120',placeholder:'e.g. Bankura','aria-label':'Value'});}
    wrap.appendChild(input);
  }
  function opSelect(row,def,op){
    var s=row.querySelector('.seg-op');s.innerHTML='';
    var ops=C.operators[def.type];if(!ops[op])op=Object.keys(ops)[0];
    Object.keys(ops).forEach(function(k){var o=el('option',{value:k},ops[k]);if(k===op)o.selected=true;s.appendChild(o);});
    return op;
  }
  function addRow(c,err){
    var i=n++,row=el('div',{class:'seg-row','data-i':String(i)});
    var join=el('span',{class:'seg-join'});row.appendChild(join);
    var fs=fieldSelect('conditions['+i+'][field]',c.field);row.appendChild(fs);
    var os=el('select',{class:'seg-op',name:'conditions['+i+'][operator]','aria-label':'Condition'});row.appendChild(os);
    row.appendChild(el('div',{class:'seg-val'}));
    var rm=el('button',{type:'button',class:'iconbtn danger',title:'Remove rule','aria-label':'Remove rule'});rm.innerHTML='<i data-lucide="x"></i>';row.appendChild(rm);
    if(err){var e=el('div',{class:'err seg-err'},err);row.appendChild(e);row.classList.add('bad');}
    function sync(keepVal){
      var def=C.fields[fs.value];var op=opSelect(row,def,keepVal?c.operator:null);
      valueInput(row,def,op,keepVal?c.value:'');
    }
    fs.addEventListener('change',function(){sync(false);changed();});
    os.addEventListener('change',function(){var def=C.fields[fs.value],cur=row.querySelector('.seg-val [name]');valueInput(row,def,os.value,cur?cur.value:'');changed();});
    rm.addEventListener('click',function(){row.remove();joins();changed();});
    box.appendChild(row);sync(true);joins();lucide.createIcons();
    return row;
  }
  function joins(){
    var any=form.querySelector('[name=match_type]:checked').value==='any';
    box.querySelectorAll('.seg-row').forEach(function(r,idx){r.querySelector('.seg-join').textContent=idx===0?'Where':(any?'or':'and');});
  }
  function changed(){clearTimeout(timer);timer=setTimeout(preview,350);}
  function preview(){
    var p=document.getElementById('segPreview'),my=++seq;
    if(form.querySelector('[name=type]:checked').value==='static'){p.querySelector('[data-count]').textContent='—';p.querySelector('[data-bar]').style.width='0';p.querySelector('[data-sample]').innerHTML='';p.querySelector('[data-note]').textContent='Static segments are filled by hand after saving.';return;}
    p.classList.add('busy');
    var fd=new FormData(form);
    fetch(C.preview,{method:'POST',body:fd,headers:{'X-CSRF-TOKEN':token,Accept:'application/json'},credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){
      if(my!==seq)return;p.classList.remove('busy');
      p.querySelector('[data-count]').textContent=j.count.toLocaleString('en-IN');
      p.querySelector('[data-bar]').style.width=(j.total?Math.max(j.count?3:0,Math.round(j.count/j.total*100)):0)+'%';
      var s=p.querySelector('[data-sample]');s.innerHTML='';
      j.sample.forEach(function(c){var a=el('a',{href:c.url},c.name);s.appendChild(a);});
      if(j.count>j.sample.length&&j.sample.length)s.appendChild(el('span',{class:'muted'},'+'+(j.count-j.sample.length).toLocaleString('en-IN')+' more'));
      var errs=Object.keys(j.errors||{}).length;
      p.querySelector('[data-note]').textContent=!j.rules?'Add a complete rule to see who matches.':errs?errs+' rule'+(errs===1?' is':'s are')+' incomplete and not counted yet.':'Updates as you edit the rules.';
    }).catch(function(){if(my!==seq)return;p.classList.remove('busy');p.querySelector('[data-note]').textContent='Preview unavailable — you can still save.';});
  }
  C.initial.forEach(function(c,i){addRow(c,C.errors[i]);});
  document.getElementById('segAdd').addEventListener('click',function(){var r=addRow({field:'customer_type',operator:'is',value:''});r.querySelector('select').focus();changed();});
  box.addEventListener('input',changed);
  form.querySelectorAll('[name=match_type]').forEach(function(r){r.addEventListener('change',function(){joins();changed();});});
  form.querySelectorAll('[name=type]').forEach(function(r){r.addEventListener('change',function(){
    var st=form.querySelector('[name=type]:checked').value==='static';
    document.getElementById('rulesCard').hidden=st;document.getElementById('staticCard').hidden=!st;
    // static segments post no rules
    box.querySelectorAll('[name]').forEach(function(i){i.disabled=st;});
    preview();
  });});
  if(form.querySelector('[name=type]:checked').value==='static')box.querySelectorAll('[name]').forEach(function(i){i.disabled=true;});
  var bad=document.querySelector('[aria-invalid=true],.seg-row.bad select');if(bad)bad.focus();
  preview();
})();
</script>
JS;
include __DIR__ . '/../../partials/footer.php';
