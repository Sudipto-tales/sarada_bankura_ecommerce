<?php
/**
 * Receiving accounts (docs/payments.md §5). Nothing sensitive is rendered here: the list arrives
 * from POST /admin/payments/accounts/unlock after the OTP, and is held only for this page view.
 */
$active = 'payments.accounts';
$title = 'Receiving accounts';
include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs"><a href="<?= route('admin.payments') ?>">Payments</a> <i data-lucide="chevron-right"></i> Receiving accounts</div><h1>Receiving accounts<span style="color:#dca71f">.</span></h1></div>
  <button class="btn gold" type="button" id="accAdd" disabled><i data-lucide="plus" style="width:16px"></i>Add account</button>
</div>

<section class="card pa-lock" id="accLock">
  <span class="metric-icon"><i data-lucide="lock-keyhole"></i></span>
  <h3>Enter the OTP to view accounts</h3>
  <p class="text-sm muted">Bank and UPI details stay hidden until the OTP is accepted, and lock again when you leave or refresh this page.</p>
  <div class="otp-boxes" id="accOtp"></div>
  <p class="err" id="accOtpErr"></p>
  <button class="btn gold" type="button" id="accUnlock"><i data-lucide="unlock" style="width:15px"></i>Unlock</button>
  <p class="text-xs muted" style="margin-top:14px">The OTP is a fixed placeholder until SMS / email OTP is set up — ask the owner.</p>
</section>

<div id="accBody" hidden>
  <div class="pa-grid" id="accList"></div>
  <section class="card" style="margin-top:18px">
    <div class="card-h"><div><div class="eyebrow">Audit log</div><h3>Change history</h3></div><span class="text-xs muted">Account numbers and UPI IDs are masked</span></div>
    <div class="tablewrap" style="border:0;margin-top:8px"><table class="data"><thead><tr><th>When</th><th>Action</th><th>Changes</th><th>IP</th></tr></thead><tbody id="accHistory"></tbody></table></div>
  </section>
</div>

<div class="modal-backdrop" id="accModal"><form class="modal" id="accForm" novalidate>
  <div class="modal-h"><div><div class="eyebrow">Receiving account</div><h3 id="accFormTitle">Add account</h3></div><button type="button" class="iconbtn" data-close aria-label="Close"><i data-lucide="x"></i></button></div>
  <div class="stack">
    <div class="field"><label>Type</label>
      <div class="pm-channels" id="accTypes">
        <?php foreach ($types as $k => $l): ?><label><input type="radio" name="type" value="<?= e($k) ?>"<?= $k === 'upi' ? ' checked' : '' ?>><span><i data-lucide="<?= ['upi' => 'smartphone', 'bank' => 'landmark', 'cash' => 'banknote'][$k] ?>"></i><?= e($l) ?></span></label><?php endforeach; ?>
      </div></div>
    <div class="field"><label>Name *</label><input name="name" maxlength="80" placeholder="e.g. SBI current, Shop counter"><div class="err" data-err="name"></div></div>
    <div class="grid2" data-for="upi"><div class="field"><label>UPI ID *</label><input name="upi_id" maxlength="80" placeholder="yourshop@bank" autocomplete="off" spellcheck="false"><div class="err" data-err="upi_id"></div></div>
      <div class="field"><label>Payee name *</label><input name="payee_name" maxlength="120" placeholder="Shown in the customer’s UPI app"><div class="err" data-err="payee_name"></div></div></div>
    <div data-for="bank" class="stack">
      <div class="grid2"><div class="field"><label>Bank *</label><input name="bank_name" maxlength="120" placeholder="State Bank of India"><div class="err" data-err="bank_name"></div></div>
        <div class="field"><label>Account holder *</label><input name="account_holder" maxlength="120"><div class="err" data-err="account_holder"></div></div></div>
      <div class="grid2"><div class="field"><label>Account number *</label><input name="account_number" inputmode="numeric" maxlength="20" autocomplete="off"><div class="err" data-err="account_number"></div></div>
        <div class="field"><label>IFSC *</label><input name="ifsc" maxlength="11" style="text-transform:uppercase" autocomplete="off"><div class="err" data-err="ifsc"></div></div></div>
    </div>
    <div class="field"><label>Notes</label><input name="notes" maxlength="500" placeholder="Optional — staff only"></div>
    <label class="checkline"><input type="checkbox" name="is_active" value="1" checked> Active (can be picked when recording payments)</label>
    <label class="checkline" data-for="upi"><input type="checkbox" name="is_default_upi" value="1"> Show this UPI ID in the customer payment window</label>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn gold" type="submit"><i data-lucide="shield-check" style="width:16px"></i>Review &amp; save</button></div>
</form></div>
<?php
include __DIR__ . '/../partials/sensitive-confirm.php';
$cfg = json_encode([
    'unlock' => route('admin.payments.accounts.unlock'),
    'store' => route('admin.payments.accounts.store'),
    'update' => route('admin.payments.accounts.update', ['id' => '__ID__']),
    'default' => route('admin.payments.accounts.default', ['id' => '__ID__']),
    'delete' => route('admin.payments.accounts.delete', ['id' => '__ID__']),
    'labels' => $labels, 'types' => $types,
]);
$scripts .= '<script>window.ACC=' . $cfg . ';</script>' . <<<'JS'
<script>
(function(){
  var csrf=(document.querySelector('meta[name="csrf-token"]')||{}).content||'';
  var viewOtp=null,accounts=[],editing=null;
  var esc=function(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})};
  var inr=function(n){return '₹'+Number(n).toLocaleString('en-IN',{maximumFractionDigits:0})};
  function post(url,data){var fd=new FormData();fd.append('_token',csrf);Object.keys(data).forEach(function(k){if(data[k]!==null&&data[k]!==undefined)fd.append(k,data[k])});
    return fetch(url,{method:'POST',body:fd,credentials:'same-origin',headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf}}).then(function(r){return r.json().catch(function(){return{ok:false,message:'Server error ('+r.status+').'}})});}
  function mask(k,v){if(v===null||v===undefined||v==='')return '—';v=String(v);
    if(k==='account_number')return '••••'+v.slice(-4);
    if(k==='upi_id'&&v.indexOf('@')>0){var p=v.split('@');return p[0].slice(0,5)+'••@'+p[1];}
    if(k==='is_active'||k==='is_default_upi')return (v==='1'||v===1||v===true)?'Yes':'No';
    if(k==='type')return ACC.types[v]||v;return v;}

  /* unlock */
  var lockErr=document.getElementById('accOtpErr');
  var boxes=msOtpBoxes(document.getElementById('accOtp'),unlock);boxes.focus();
  document.getElementById('accUnlock').addEventListener('click',function(){unlock(boxes.value())});
  function unlock(code){if(code.length!==6){lockErr.textContent='Enter all 6 digits.';return;}lockErr.textContent='';
    post(ACC.unlock,{otp:code}).then(function(j){if(!j.ok){lockErr.textContent=j.message||'Not accepted.';boxes.clear();return;}viewOtp=code;render(j);});}
  function refresh(){return post(ACC.unlock,{otp:viewOtp}).then(function(j){if(j.ok)render(j)});}

  function render(j){
    accounts=j.accounts;document.getElementById('accLock').hidden=true;document.getElementById('accBody').hidden=false;document.getElementById('accAdd').disabled=false;
    var L=document.getElementById('accList');
    L.innerHTML=accounts.length?accounts.map(function(a){
      var icon={upi:'smartphone',bank:'landmark',cash:'banknote'}[a.type];
      var rows=a.type==='upi'?'<dt>UPI ID</dt><dd><b>'+esc(a.upi_id)+'</b></dd><dt>Payee</dt><dd>'+esc(a.payee_name)+'</dd>'
        :a.type==='bank'?'<dt>Bank</dt><dd>'+esc(a.bank_name)+'</dd><dt>Holder</dt><dd>'+esc(a.account_holder)+'</dd><dt>Account</dt><dd><b data-reveal="'+esc(a.account_number)+'">'+esc(a.masked_number)+'</b> <button type="button" class="linkbtn" data-show>show</button></dd><dt>IFSC</dt><dd>'+esc(a.ifsc)+'</dd>'
        :'<dt>Use</dt><dd>Cash collected at the shop</dd>';
      return '<article class="card pa-card'+(a.is_active?'':' off')+'"><div class="pa-card-h"><span class="metric-icon"><i data-lucide="'+icon+'"></i></span><div><b>'+esc(a.name)+'</b><small>'+esc(ACC.types[a.type])+(a.is_active?'':' · inactive')+'</small></div>'
        +(a.is_default_upi?'<span class="ord-pay ord-green">In payment window</span>':'')+'</div><dl class="kv">'+rows+(a.notes?'<dt>Notes</dt><dd>'+esc(a.notes)+'</dd>':'')+'</dl>'
        +'<p class="text-xs muted" style="margin:10px 0 0">'+a.payments+' payment'+(a.payments===1?'':'s')+' · '+inr(a.received)+' received'+(a.updated?' · updated '+esc(a.updated):'')+'</p>'
        +'<div class="pa-acts"><button type="button" class="btn sm" data-edit="'+a.id+'"><i data-lucide="pencil" style="width:14px"></i>Edit</button>'
        +(a.type==='upi'&&a.is_active&&!a.is_default_upi?'<button type="button" class="btn sm" data-default="'+a.id+'">Use in payment window</button>':'')
        +'<button type="button" class="iconbtn" title="Delete" data-delete="'+a.id+'"><i data-lucide="trash-2"></i></button></div></article>';
    }).join(''):'<div class="card cu-empty" style="grid-column:1/-1"><span class="metric-icon"><i data-lucide="wallet"></i></span><b>No receiving accounts yet</b><small>Add your UPI ID first — it is what customers see in the payment window. Add a bank account for big transfers and a cash counter for shop payments.</small></div>';
    var H=document.getElementById('accHistory');
    H.innerHTML=j.history.length?j.history.map(function(h){
      var ch=Object.keys(h.changes||{}).map(function(k){var c=h.changes[k];return '<span class="sc-line"><b>'+esc(ACC.labels[k]||k)+'</b> '+esc(c[0]==null?'—':c[0])+' → '+esc(c[1]==null?'—':c[1])+'</span>'}).join('');
      return '<tr><td class="whitespace-nowrap">'+esc(h.when)+'</td><td>'+esc({create:'Added',update:'Changed',delete:'Deleted',set_default:'Payment window UPI'}[h.action]||h.action)+'</td><td class="text-xs">'+ch+'</td><td class="text-xs muted">'+esc(h.ip||'')+'</td></tr>';
    }).join(''):'<tr><td colspan="4" class="muted text-sm">No changes yet.</td></tr>';
    if(window.lucide)lucide.createIcons();
  }

  /* form */
  var M=document.getElementById('accModal'),F=document.getElementById('accForm');
  M.querySelectorAll('[data-close]').forEach(function(b){b.addEventListener('click',function(){M.classList.remove('show')})});
  function typeSync(){var t=F.type.value;M.querySelectorAll('[data-for]').forEach(function(el){el.hidden=el.dataset.for!==t})}
  F.querySelectorAll('[name=type]').forEach(function(r){r.addEventListener('change',typeSync)});
  function openForm(a){editing=a||null;F.reset();F.querySelectorAll('[data-err]').forEach(function(e){e.textContent=''});
    document.getElementById('accFormTitle').textContent=a?'Edit '+a.name:'Add account';
    if(a){['name','upi_id','payee_name','bank_name','account_holder','account_number','ifsc','notes'].forEach(function(k){F[k].value=a[k]||''});F.type.value=a.type;F.is_active.checked=!!a.is_active;F.is_default_upi.checked=!!a.is_default_upi;}
    else{F.is_default_upi.checked=!accounts.some(function(x){return x.is_default_upi});}
    typeSync();M.classList.add('show');setTimeout(function(){F.name.focus()},40);}
  document.getElementById('accAdd').addEventListener('click',function(){openForm(null)});
  function formData(){var d={};['type','name','upi_id','payee_name','bank_name','account_holder','account_number','ifsc','notes'].forEach(function(k){d[k]=(F[k].value||'').trim()});
    d.is_active=F.is_active.checked?'1':'';d.is_default_upi=F.is_default_upi.checked&&d.type==='upi'?'1':'';
    if(d.type==='upi')d.upi_id=d.upi_id.toLowerCase();if(d.type==='bank'){d.ifsc=d.ifsc.toUpperCase();d.account_number=d.account_number.replace(/\s+/g,'');}
    if(d.type!=='upi'){d.upi_id='';d.payee_name='';}if(d.type!=='bank'){d.bank_name='';d.account_holder='';d.account_number='';d.ifsc='';}return d;}
  function changes(old,d){var out=[];Object.keys(ACC.labels).forEach(function(k){
      var o=old?(old[k]==null?'':String(old[k])):'',n=d[k]==null?'':String(d[k]);
      if(k==='is_active'||k==='is_default_upi'){o=old?(old[k]?'1':''):'';n=d[k]?'1':'';}
      if(o!==n)out.push({label:ACC.labels[k],from:old?mask(k,old[k]):'—',to:mask(k,d[k])});});return out;}
  F.addEventListener('submit',function(e){e.preventDefault();var d=formData(),ch=changes(editing,d);
    if(!ch.length){msToast.info('Nothing changed.');return;}
    msSensitive({title:editing?'Change '+editing.name:'Add '+(d.name||'account'),changes:ch,submit:function(otp){
      return post(editing?ACC.update.replace('__ID__',editing.id):ACC.store,Object.assign({otp:otp},d)).then(function(j){
        if(j.errors){M.classList.add('show');Object.keys(j.errors).forEach(function(k){var el=F.querySelector('[data-err="'+k+'"]');if(el)el.textContent=j.errors[k][0]});
          return{ok:true};} // close the confirm; the form shows what to fix (nothing was saved)
        if(j.ok){M.classList.remove('show');msToast.success(j.message);refresh();}
        return j;});
    }});
  });

  /* card actions */
  document.getElementById('accList').addEventListener('click',function(e){
    var b=e.target.closest('button');if(!b)return;
    if(b.hasAttribute('data-show')){var el=b.previousElementSibling;var full=el.dataset.reveal;el.textContent=el.textContent===full?'••••'+full.slice(-4):full;b.textContent=el.textContent===full?'hide':'show';return;}
    var find=function(id){return accounts.find(function(a){return String(a.id)===String(id)})};
    if(b.dataset.edit){openForm(find(b.dataset.edit));return;}
    if(b.dataset.default){var a=find(b.dataset.default),cur=accounts.find(function(x){return x.is_default_upi});
      msSensitive({title:'Change the payment-window UPI ID',changes:[{label:'Customers pay to',from:cur?cur.name+' · '+mask('upi_id',cur.upi_id):'—',to:a.name+' · '+mask('upi_id',a.upi_id)}],
        submit:function(otp){return post(ACC.default.replace('__ID__',a.id),{otp:otp}).then(function(j){if(j.ok){msToast.success(j.message);refresh();}return j;})}});return;}
    if(b.dataset.delete){var d=find(b.dataset.delete);
      msSensitive({title:'Delete '+d.name,danger:true,typeName:d.name,changes:[{label:'Account',from:d.label,to:'deleted'}],
        submit:function(otp,typed){return post(ACC.delete.replace('__ID__',d.id),{otp:otp,confirm_name:typed}).then(function(j){if(j.ok){msToast.success(j.message);refresh();}return j;})}});}
  });
  window.addEventListener('pagehide',function(){viewOtp=null;accounts=[];});
})();
</script>
JS;
include __DIR__ . '/../partials/footer.php';
