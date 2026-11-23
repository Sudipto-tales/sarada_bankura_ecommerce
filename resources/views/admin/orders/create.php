<?php
use App\Services\Order\OrderService as OS;

$active = 'orders.create';
$title = 'Create order';
$cfg = [
    'lookupCustomers' => route('admin.orders.lookup.customers'),
    'lookupAddresses' => route('admin.orders.lookup.addresses', ['id' => '000']),
    'lookupProducts' => route('admin.orders.lookup.products'),
    'pincode' => route('admin.lookup.pincode', ['code' => '000000']),
    'customerUrl' => route('admin.customers.show', ['id' => '000']),
    'sellerState' => $sellerState,
    'hasGstin' => $hasGstin,
    'customer' => $customer,
];

include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs"><a href="<?= route('admin.orders') ?>">Orders</a> <i data-lucide="chevron-right"></i> Create order</div><h1>Create order<span style="color:#dca71f">.</span></h1></div>
  <a class="btn" href="<?= route('admin.orders') ?>">Cancel</a>
</div>

<form id="ordForm" method="post" action="<?= route('admin.orders.store') ?>" novalidate>
  <?= csrf_field() ?>
  <div class="ord-errors" id="ordErrors" role="alert" hidden></div>
  <div class="layout ord-create">
    <div class="stack" style="gap:18px">

      <section class="card" id="custCard">
        <div class="sectionhead" style="margin-bottom:16px"><span class="sectionicon"><i data-lucide="user-round"></i></span><div><h2>Customer</h2><p class="text-xs muted">Search by name, firm or mobile — or add someone new.</p></div></div>
        <input type="hidden" name="customer_id" id="customerId" value="">
        <div id="custPick">
          <div class="ord-search">
            <label class="search-in" style="margin:0;min-width:0"><i data-lucide="search"></i><input id="custQ" placeholder="Type a name or mobile…" autocomplete="off" aria-label="Search customers" aria-controls="custResults"></label>
            <div class="ord-drop" id="custResults" role="listbox" hidden></div>
          </div>
          <button type="button" class="linkbtn" id="custNewBtn" style="margin-top:12px"><i data-lucide="user-plus"></i>New customer</button>
        </div>
        <div class="ord-chosen" id="custChosen" hidden>
          <span class="avatar-sq" id="custAv"></span>
          <div class="min-w-0"><b id="custName"></b><small class="block muted text-xs" id="custMeta"></small></div>
          <button type="button" class="btn" id="custChange">Change</button>
        </div>
        <div class="stack" id="custNew" hidden style="gap:12px">
          <div class="grid3" style="gap:12px">
            <div class="field"><label>Name *</label><input name="new_customer[name]" maxlength="120" autocomplete="off" placeholder="Customer name"></div>
            <div class="field"><label>Mobile *</label><input name="new_customer[phone]" inputmode="tel" maxlength="16" placeholder="98300 12345"></div>
            <div class="field"><label>Email</label><input name="new_customer[email]" type="email" maxlength="160" placeholder="Optional"></div>
          </div>
          <p class="hint" style="margin:0">If this mobile already belongs to a customer, the order goes to them.</p>
          <button type="button" class="linkbtn" id="custSearchBtn"><i data-lucide="search"></i>Search existing customers instead</button>
        </div>
      </section>

      <section class="card">
        <div class="sectionhead" style="margin-bottom:16px"><span class="sectionicon" style="background:#e4f4e9;color:#267a55"><i data-lucide="truck"></i></span><div><h2>Delivery</h2><p class="text-xs muted">Delivery is included in the price — no shipping charge.</p></div></div>
        <div class="seg-types" style="margin-bottom:14px">
          <label><input type="radio" name="delivery" value="ship" checked><span><i data-lucide="truck"></i><b>Deliver to site</b><small>Choose or add the delivery address.</small></span></label>
          <label><input type="radio" name="delivery" value="pickup"><span><i data-lucide="store"></i><b>Customer collects</b><small>Picks up from the showroom.</small></span></label>
        </div>
        <div id="shipBox">
          <div class="ord-addrs" id="shipList"></div>
          <div class="ord-newaddr" id="shipNew" data-prefix="new_shipping"></div>
        </div>
        <label class="checkline" id="billSameWrap" style="margin-top:14px"><input type="checkbox" name="billing_same" value="1" id="billSame" checked> Billing address is the same as delivery</label>
        <div id="billBox" hidden style="margin-top:14px">
          <div class="eyebrow" style="margin-bottom:8px">Billing address</div>
          <div class="ord-addrs" id="billList"></div>
          <div class="ord-newaddr" id="billNew" data-prefix="new_billing"></div>
        </div>
      </section>

      <section class="card">
        <div class="sectionhead" style="margin-bottom:16px"><span class="sectionicon" style="background:#e3eef8;color:#3d6a92"><i data-lucide="package"></i></span><div><h2>Products</h2><p class="text-xs muted">Prices fill from the catalogue and can be changed for this order.</p></div></div>
        <div class="ord-search">
          <label class="search-in" style="margin:0;min-width:0"><i data-lucide="search"></i><input id="prodQ" placeholder="Search product name or SKU…" autocomplete="off" aria-label="Search products" aria-controls="prodResults"></label>
          <div class="ord-drop" id="prodResults" role="listbox" hidden></div>
        </div>
        <div class="ord-lines" id="lines"></div>
        <div class="ord-lines-empty" id="linesEmpty"><i data-lucide="package-search"></i>No products yet — search above to add the first line.</div>
      </section>

      <section class="card">
        <div class="sectionhead" style="margin-bottom:16px"><span class="sectionicon" style="background:#efe7f6;color:#6f4a92"><i data-lucide="notebook-pen"></i></span><div><h2>Details</h2></div></div>
        <div class="grid2">
          <div class="field"><label for="chDetail">Sale came in by</label><select id="chDetail" name="channel_detail"><?php foreach (OS::CHANNEL_DETAILS as $k => $l): if ($k === 'website') continue; ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label for="notes">Note on invoice</label><input id="notes" name="notes" maxlength="500" placeholder="e.g. Deliver before Sunday"></div>
        </div>
        <div class="field" style="margin-top:14px"><label for="inote">Internal note</label><textarea id="inote" name="internal_note" rows="2" maxlength="2000" placeholder="Staff only — never printed"></textarea></div>
      </section>
    </div>

    <aside class="rightcol">
      <section class="rightcard ord-sum">
        <div class="eyebrow">Order summary</div>
        <div class="ord-sum-rows">
          <div><span>Items (<span id="sumLines">0</span>)</span><b id="sumSub">₹0.00</b></div>
          <div class="ord-sum-in"><label for="disc">Order discount</label><span class="ord-money"><i>₹</i><input id="disc" name="discount_amount" inputmode="decimal" placeholder="0"></span></div>
          <div class="ord-sum-in"><label class="ord-gst"><input type="checkbox" class="switch-in" name="gst_enabled" value="1" id="gstOn"<?= $gstDefault ? ' checked' : '' ?>><span>GST</span></label>
            <span class="ord-money pct"><input id="gstRate" name="gst_rate" inputmode="decimal" value="<?= e(rtrim(rtrim(number_format($gstRate, 2, '.', ''), '0'), '.')) ?>"><i>%</i></span></div>
          <div id="taxRows"></div>
          <div class="ord-total"><span>Total</span><b id="sumTotal">₹0.00</b></div>
        </div>
        <p class="ord-gst-note" id="gstNote"></p>
        <?php if (!$hasGstin): ?><p class="ord-gst-warn" id="gstWarn"<?= $gstDefault ? '' : ' hidden' ?>><i data-lucide="triangle-alert"></i>No seller GSTIN is set (BUSINESS_GSTIN in .env), so GST can’t be charged — the order will be refused. Turn GST off; the document prints as a Bill of Supply.</p><?php endif; ?>
        <button class="btn gold" type="submit" id="submitBtn" style="width:100%;justify-content:center;margin-top:12px"><i data-lucide="check" style="width:16px"></i>Create order</button>
        <p class="text-xs muted" style="margin:10px 0 0">The order starts as <b>Pending</b>. Accept it on the next screen. Stock is only taken out when it’s dispatched.</p>
      </section>
    </aside>
  </div>
</form>

<template id="addrFormTpl">
  <div class="stack" style="gap:10px">
    <div class="cu-pin">
      <div class="field"><label>Pincode</label><input data-f="pincode" inputmode="numeric" maxlength="6" placeholder="722101"></div>
      <div class="cu-pin-status" data-pin-status><i data-lucide="map-pin"></i><span>Pincode fills district and state.</span></div>
    </div>
    <div class="field"><label>Address *</label><input data-f="address_line1" maxlength="255" placeholder="House / shop no., building, street"></div>
    <div class="grid3" style="gap:10px">
      <div class="field"><label>Post office / area</label><input data-f="post_office" maxlength="120" data-po></div>
      <div class="field"><label>City / town</label><input data-f="city" maxlength="120" data-city></div>
      <div class="field"><label>State</label><input data-f="state" maxlength="80" data-state></div>
    </div>
    <input type="hidden" data-f="district" data-district><input type="hidden" data-f="label">
  </div>
</template>
<?php
$scripts = '<script>window.ORDC=' . json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . ';</script>' . <<<'JS'
<script>
(function(){
  var C=window.ORDC,form=document.getElementById('ordForm'),token=(document.querySelector('meta[name=csrf-token]')||{}).content||'';
  var $=function(id){return document.getElementById(id)},n=0;
  var inr=function(v){return '₹'+Number(v||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2})};
  var num=function(v){v=String(v==null?'':v).replace(/,/g,'').trim();return v===''||isNaN(v)?0:Number(v)};
  var esc=function(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})};
  function debounce(fn,ms){var t;return function(){var a=arguments;clearTimeout(t);t=setTimeout(function(){fn.apply(null,a)},ms)}}
  function getJSON(u){return fetch(u,{headers:{Accept:'application/json'},credentials:'same-origin'}).then(function(r){return r.json()})}

  /* ---------- searchable dropdowns ---------- */
  function picker(input,box,url,render,choose){
    var items=[],idx=-1;
    function show(list){items=list;idx=-1;box.innerHTML='';
      if(!list.length){box.innerHTML='<div class="ord-drop-empty">No matches</div>';box.hidden=false;return;}
      list.forEach(function(it,i){var b=document.createElement('button');b.type='button';b.className='ord-opt';b.setAttribute('role','option');b.innerHTML=render(it);
        b.addEventListener('mousedown',function(e){e.preventDefault();choose(it);close();});box.appendChild(b);});
      box.hidden=false;lucide.createIcons();}
    function close(){box.hidden=true;}
    var run=debounce(function(){var q=input.value.trim();if(q.length<(url===C.lookupProducts?1:2)){close();return;}
      getJSON(url+'?q='+encodeURIComponent(q)).then(function(j){if(input.value.trim()===q)show(j.items||[])}).catch(close)},220);
    input.addEventListener('input',run);input.addEventListener('focus',function(){if(input.value.trim())run()});
    input.addEventListener('blur',function(){setTimeout(close,120)});
    input.addEventListener('keydown',function(e){
      var opts=box.querySelectorAll('.ord-opt');if(box.hidden||!opts.length)return;
      if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();idx=(idx+(e.key==='ArrowDown'?1:-1)+opts.length)%opts.length;opts.forEach(function(o,i){o.classList.toggle('on',i===idx)});}
      if(e.key==='Enter'){e.preventDefault();choose(items[idx<0?0:idx]);close();}
      if(e.key==='Escape')close();
    });
  }

  /* ---------- customer ---------- */
  function setCustomer(c){
    $('customerId').value=c?c.id:'';
    $('custPick').hidden=!!c;$('custChosen').hidden=!c;$('custNew').hidden=true;
    if(c){$('custName').textContent=c.name;$('custMeta').textContent=[c.phone_fmt||c.phone,c.company,c.email].filter(Boolean).join(' · ');
      $('custAv').textContent=(c.name||'?').split(/\s+/).slice(0,2).map(function(p){return p[0]}).join('').toUpperCase();}
    loadAddresses(c?c.id:null);
  }
  picker($('custQ'),$('custResults'),C.lookupCustomers,function(c){return '<b>'+esc(c.name)+'</b><small>'+esc([c.phone_fmt,c.company].filter(Boolean).join(' · '))+'</small>'},setCustomer);
  $('custChange').addEventListener('click',function(){setCustomer(null);$('custQ').value='';$('custQ').focus()});
  $('custNewBtn').addEventListener('click',function(){$('custPick').hidden=true;$('custNew').hidden=false;$('customerId').value='';loadAddresses(null);form.querySelector('[name="new_customer[name]"]').focus()});
  $('custSearchBtn').addEventListener('click',function(){$('custNew').hidden=true;$('custPick').hidden=false;$('custQ').focus()});

  /* ---------- addresses ---------- */
  var addresses=[];
  function addrForm(box){
    if(box.dataset.built)return;box.dataset.built=1;
    box.appendChild($('addrFormTpl').content.cloneNode(true));
    box.querySelectorAll('[data-f]').forEach(function(i){i.name=box.dataset.prefix+'['+i.dataset.f+']'});
    var pin=box.querySelector('[data-f=pincode]'),last='';
    pin.addEventListener('input',function(){var d=pin.value.replace(/\D/g,'');pin.value=d;if(d.length===6&&d!==last){last=d;pinLookup(box,d)}});
    box.querySelector('[data-state]').addEventListener('input',totals);
    lucide.createIcons();
  }
  function pinLookup(box,code){
    var s=box.querySelector('[data-pin-status]');s.className='cu-pin-status loading';s.querySelector('span').textContent='Looking up '+code+'…';
    getJSON(C.pincode.replace('000000',code)).then(function(j){
      if(!j.ok){s.className='cu-pin-status bad';s.querySelector('span').textContent=j.reason==='unavailable'?'Lookup unavailable — type the details.':'No post office for '+code+'.';return;}
      box.querySelector('[data-state]').value=j.state||'';box.querySelector('[data-district]').value=j.district||'';
      var city=box.querySelector('[data-city]');if(!city.value)city.value=j.district||'';
      var po=box.querySelector('[data-po]');if(!po.value&&j.areas&&j.areas.length)po.value=j.areas[0];
      s.className='cu-pin-status ok';s.querySelector('span').textContent=(j.district?j.district+', ':'')+j.state;totals();
    }).catch(function(){s.className='cu-pin-status bad';s.querySelector('span').textContent='Lookup unavailable — type the details.'});
  }
  function renderAddrList(listEl,newBox,name){
    listEl.innerHTML='';
    addresses.forEach(function(a,i){
      var l=document.createElement('label');l.className='ord-addr';
      l.innerHTML='<input type="radio" name="'+name+'" value="'+a.id+'"'+(i===0?' checked':'')+'><span><b>'+esc(a.label||'Address')+(a.is_default?' <em>Default</em>':'')+'</b><small>'+a.lines.map(esc).join('<br>')+'</small></span>';
      l.querySelector('input').dataset.state=a.state||'';listEl.appendChild(l);
    });
    var l=document.createElement('label');l.className='ord-addr new';
    l.innerHTML='<input type="radio" name="'+name+'" value="new"'+(addresses.length?'':' checked')+'><span><b><i data-lucide="plus" style="width:14px;vertical-align:-2px"></i> New address</b><small>Saved to the customer too.</small></span>';
    listEl.appendChild(l);
    function sync(){var v=(listEl.querySelector('input:checked')||{}).value;newBox.hidden=v!=='new';if(v==='new')addrForm(newBox);totals();}
    listEl.querySelectorAll('input').forEach(function(r){r.addEventListener('change',sync)});sync();lucide.createIcons();
  }
  function loadAddresses(id){
    addresses=[];
    var done=function(){renderAddrList($('shipList'),$('shipNew'),'shipping_address_id');renderAddrList($('billList'),$('billNew'),'billing_address_id');delivery();};
    if(!id){done();return;}
    getJSON(C.lookupAddresses.replace('000',id)).then(function(j){addresses=j.items||[];done();}).catch(done);
  }
  function delivery(){
    var pickup=form.querySelector('[name=delivery]:checked').value==='pickup';
    // Pickup: no delivery address, and no billing address needed (the invoice shows name + mobile).
    $('shipBox').hidden=pickup;$('billSameWrap').hidden=pickup;
    $('billBox').hidden=pickup||$('billSame').checked;
    // disable hidden inputs so they aren't posted
    $('shipBox').querySelectorAll('input').forEach(function(i){i.disabled=pickup});
    $('billBox').querySelectorAll('input').forEach(function(i){i.disabled=$('billBox').hidden});
    totals();
  }
  form.querySelectorAll('[name=delivery]').forEach(function(r){r.addEventListener('change',delivery)});
  $('billSame').addEventListener('change',delivery);

  /* ---------- product lines ---------- */
  function addLine(p){
    var k='l'+(++n),row=document.createElement('div');row.className='ord-line';row.dataset.k=k;
    var vsel='';
    if(p.variants.length){vsel='<select name="items['+k+'][variant_id]" data-variant aria-label="Option"><option value="">Choose option…</option>'+p.variants.map(function(v){return '<option value="'+v.id+'" data-price="'+(v.price==null?'':v.price)+'" data-unit="'+esc(v.unit||'')+'">'+esc(v.title)+'</option>'}).join('')+'</select>';}
    row.innerHTML='<input type="hidden" name="items['+k+'][product_id]" value="'+p.id+'">'
      +'<span class="ord-thumb">'+(p.cover?'<img src="'+esc(p.cover)+'" alt="">':'<i data-lucide="gem"></i>')+'</span>'
      +'<div class="ord-line-main"><b>'+esc(p.name)+'</b><small>'+esc(p.sku)+(p.stock?' · <span class="'+(p.stock_low?'ord-low':'')+'">Stock '+esc(p.stock)+'</span>':'')+'</small>'+vsel+'</div>'
      +'<label class="ord-cell"><span>Qty</span><span class="ord-qty"><input name="items['+k+'][quantity]" inputmode="decimal" value="1" data-qty><i data-unit>'+esc(p.unit||'')+'</i></span></label>'
      +'<label class="ord-cell"><span>Price</span><span class="ord-money"><i>₹</i><input name="items['+k+'][unit_price]" inputmode="decimal" value="'+(p.price==null?'':p.price)+'" data-price></span></label>'
      +'<label class="ord-cell"><span>Discount</span><span class="ord-money"><i>₹</i><input name="items['+k+'][discount]" inputmode="decimal" placeholder="0" data-disc></span></label>'
      +'<div class="ord-cell ord-amt"><span>Amount</span><b data-amt>₹0.00</b></div>'
      +'<button type="button" class="iconbtn danger" data-rm title="Remove line" aria-label="Remove '+esc(p.name)+'"><i data-lucide="x"></i></button>';
    $('lines').appendChild(row);
    var v=row.querySelector('[data-variant]');
    if(v)v.addEventListener('change',function(){var o=v.selectedOptions[0];if(o&&o.dataset.price!=='')row.querySelector('[data-price]').value=o.dataset.price;row.querySelector('[data-unit]').textContent=(o&&o.dataset.unit)||p.unit||'';totals();});
    row.querySelector('[data-rm]').addEventListener('click',function(){row.remove();totals();});
    row.querySelectorAll('input').forEach(function(i){i.addEventListener('input',totals)});
    lucide.createIcons();totals();
    (v||row.querySelector('[data-qty]')).focus();
  }
  picker($('prodQ'),$('prodResults'),C.lookupProducts,function(p){
    return '<span class="ord-thumb sm">'+(p.cover?'<img src="'+esc(p.cover)+'" alt="">':'<i data-lucide="gem"></i>')+'</span><span><b>'+esc(p.name)+'</b><small>'+esc(p.sku)
      +(p.price!=null?' · '+inr(p.price)+(p.unit?' / '+esc(p.unit):''):'')+(p.variants.length?' · '+p.variants.length+' options':'')+(p.stock?' · stock '+esc(p.stock):'')+'</small></span>';
  },function(p){addLine(p);$('prodQ').value='';});

  /* ---------- totals ---------- */
  function supplyState(){
    var pickup=form.querySelector('[name=delivery]:checked').value==='pickup';
    if(pickup)return C.sellerState;
    var r=$('shipList').querySelector('input:checked');
    if(r&&r.value!=='new')return r.dataset.state||C.sellerState;
    var s=$('shipNew').querySelector('[data-state]');return (s&&s.value.trim())||C.sellerState;
  }
  function totals(){
    var sub=0,lines=$('lines').querySelectorAll('.ord-line');
    lines.forEach(function(r){
      var amt=Math.max(0,Math.round((num(r.querySelector('[data-qty]').value)*num(r.querySelector('[data-price]').value)-num(r.querySelector('[data-disc]').value))*100)/100);
      r.querySelector('[data-amt]').textContent=inr(amt);sub+=amt;
    });
    $('linesEmpty').hidden=lines.length>0;$('sumLines').textContent=lines.length;$('sumSub').textContent=inr(sub);
    var taxable=Math.max(0,sub-num($('disc').value)),on=$('gstOn').checked,rate=on?num($('gstRate').value):0,tax=Math.round(taxable*rate)/100;
    $('gstRate').disabled=!on;
    var st=supplyState(),same=st.trim().toLowerCase()===C.sellerState.trim().toLowerCase(),rows='';
    if(on&&rate>0){
      if(same){var h=Math.round(tax*50)/100;rows='<div><span>CGST '+(rate/2)+'%</span><b>'+inr(h)+'</b></div><div><span>SGST '+(rate/2)+'%</span><b>'+inr(Math.round((tax-h)*100)/100)+'</b></div>';}
      else rows='<div><span>IGST '+rate+'%</span><b>'+inr(tax)+'</b></div>';
    }
    $('taxRows').innerHTML=rows;$('sumTotal').textContent=inr(taxable+tax);
    $('gstNote').textContent=on&&rate>0?'Place of supply: '+st+(same?' (same state → CGST + SGST)':' (other state → IGST)'):'No GST on this order.';
    var w=$('gstWarn');if(w)w.hidden=!on;
  }
  ['disc','gstRate'].forEach(function(id){$(id).addEventListener('input',totals)});$('gstOn').addEventListener('change',totals);

  /* ---------- submit (JSON → inline errors, no lost form) ---------- */
  form.addEventListener('submit',function(e){
    e.preventDefault();var btn=$('submitBtn');btn.disabled=true;
    form.querySelectorAll('.is-bad').forEach(function(i){i.classList.remove('is-bad')});form.querySelectorAll('.err.js').forEach(function(x){x.remove()});
    fetch(form.action,{method:'POST',body:new FormData(form),headers:{Accept:'application/json','X-CSRF-TOKEN':token},credentials:'same-origin'})
      .then(function(r){return r.json().then(function(j){return{status:r.status,j:j}})})
      .then(function(res){
        if(res.j.redirect){location.href=res.j.redirect;return;}
        btn.disabled=false;var errs=res.j.errors||{},list=[],first=null;
        Object.keys(errs).forEach(function(k){
          var parts=k.split('.'),name=parts[0]+parts.slice(1).map(function(p){return '['+p+']'}).join('');
          var el=form.querySelector('[name="'+name+'"]');
          if(el){el.classList.add('is-bad');var d=document.createElement('div');d.className='err js';d.textContent=errs[k][0];(el.closest('.field,.ord-cell,.ord-line-main')||el.parentNode).appendChild(d);first=first||el;}
          else list.push(errs[k][0]);
        });
        var box=$('ordErrors');box.hidden=!list.length&&!!first;box.innerHTML='<b>Check the order</b>'+(list.length?'<ul>'+list.map(function(m){return '<li>'+esc(m)+'</li>'}).join('')+'</ul>':'<p>Fix the highlighted fields.</p>');
        if(!list.length&&first)box.hidden=true;
        (first||box).scrollIntoView({behavior:'smooth',block:'center'});if(first)first.focus({preventScroll:true});
        if(!Object.keys(errs).length)msToast.error(res.j.message||'Could not create the order.');
      }).catch(function(){btn.disabled=false;msToast.error('Network problem — the order was not created. Try again.');});
  });

  if(C.customer)setCustomer(Object.assign({phone_fmt:C.customer.phone},C.customer));else loadAddresses(null);
  delivery();totals();
})();
</script>
JS;
include __DIR__ . '/../partials/footer.php';
