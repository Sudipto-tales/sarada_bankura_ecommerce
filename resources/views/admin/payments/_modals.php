<?php
/**
 * Payment dialogs shared by the order detail, the order list and the payments page:
 * verify a submission, record a payment, record a refund, request payment, void.
 * Needs $payAccounts (active receiving accounts). Triggers (buttons anywhere on the page):
 *   [data-pm-verify="<attempt id>"]
 *   [data-pm-record][data-url][data-number][data-due]
 *   [data-pm-refund][data-url][data-number][data-max][data-suggest]
 *   [data-pm-request][data-url][data-number][data-due][data-email][data-phone][data-message][data-cooldown][data-last]
 *   [data-pm-void][data-url][data-title]
 * Forms post with fetch (Accept: JSON): field errors show inline, success reloads the page (toast).
 */
use App\Services\Order\PaymentService as PS;

$accOpts = static function (array $types = ['upi', 'bank', 'cash'], bool $blank = true) use ($payAccounts): string {
    $h = $blank ? '<option value="">Choose…</option>' : '';
    foreach ($payAccounts as $a) {
        if (in_array($a['type'], $types, true)) {
            $h .= '<option value="' . (int) $a['id'] . '" data-type="' . e((string) $a['type']) . '">' . e(\App\Services\Payment\Accounts::label($a)) . '</option>';
        }
    }

    return $h;
};
$methodOpts = '';
foreach (PS::METHODS as $k => $l) {
    $methodOpts .= '<option value="' . $k . '">' . e($l) . '</option>';
}
$nowLocal = date('Y-m-d\TH:i');
?>
<!-- Verify a window submission -->
<div class="modal-backdrop pm-modal" id="pm-verify"><div class="modal">
  <div class="modal-h"><div><div class="eyebrow" data-v="title">Verify payment</div><h3>Check your bank, then confirm</h3></div><button type="button" class="iconbtn" data-close aria-label="Close"><i data-lucide="x"></i></button></div>
  <div class="pm-loading muted text-sm" data-v="loading">Loading…</div>
  <div data-v="body" hidden>
    <div class="pm-claim">
      <div class="pm-claim-grid">
        <div><small>Customer says they paid</small><b data-v="amount"></b></div>
        <div><small>Name on UPI</small><b data-v="payer"></b></div>
        <div><small>Payment ID / UTR</small><b data-v="utr"></b> <button type="button" class="linkbtn" data-v="copyutr" hidden><i data-lucide="copy"></i></button></div>
        <div><small>Submitted</small><b data-v="when"></b></div>
      </div>
      <p class="text-xs muted" style="margin:10px 0 0">Paid to <b data-v="account"></b> · ref <b data-v="ref"></b> · <a class="linkbtn" data-v="order" href="#"></a></p>
      <a class="pm-proof" data-v="proof" href="#" target="_blank" rel="noopener" hidden><img alt="Payment screenshot" data-v="proofimg"><span data-v="proofname"></span></a>
      <div class="ord-warnbox sm" data-v="dupes" hidden><i data-lucide="triangle-alert"></i><div><b>This UTR is already on another payment</b><ul data-v="dupelist"></ul><small>Check before confirming — it may be a reused screenshot.</small></div></div>
    </div>
    <form data-pm-form data-v="form" class="stack" style="margin-top:14px">
      <div class="grid2">
        <div class="field"><label>Amount actually received * <span class="muted">(type what the bank shows)</span></label><input name="amount" inputmode="decimal" autocomplete="off" placeholder="₹"><div class="err" data-err="amount"></div></div>
        <div class="field"><label>Received into *</label><select name="payment_account_id"><?= $accOpts(['upi', 'bank']) ?></select><div class="err" data-err="payment_account_id"></div></div>
      </div>
      <div class="pm-diff" data-v="diff" hidden></div>
      <div class="grid2">
        <div class="field"><label>Received on *</label><input type="datetime-local" name="received_at" max="<?= $nowLocal ?>"><div class="err" data-err="received_at"></div></div>
        <div class="field"><label>Notes</label><input name="notes" maxlength="500" placeholder="e.g. Seen in SBI app"></div>
      </div>
      <div class="field" data-v="rejectbox" hidden><label>Why is it rejected? * <span class="muted">(the customer sees this)</span></label><input name="reason" maxlength="255" placeholder="e.g. No payment with this UTR reached our account"><div class="err" data-err="reason"></div></div>
      <div class="modal-f" style="justify-content:space-between">
        <button type="button" class="btn danger-text" data-v="reject"><i data-lucide="x" style="width:16px"></i>Reject…</button>
        <button type="submit" class="btn gold" data-v="confirm"><i data-lucide="check" style="width:16px"></i>Confirm received</button>
      </div>
    </form>
  </div>
</div></div>

<!-- Record a payment taken at the shop -->
<div class="modal-backdrop pm-modal" id="pm-record"><form class="modal" data-pm-form>
  <div class="modal-h"><div><div class="eyebrow" data-f="number"></div><h3>Record payment</h3></div><button type="button" class="iconbtn" data-close aria-label="Close"><i data-lucide="x"></i></button></div>
  <div class="stack">
    <div class="grid2">
      <div class="field"><label>Amount received *</label><input name="amount" inputmode="decimal" autocomplete="off" placeholder="₹"><div class="hint" data-f="duehint"></div><div class="err" data-err="amount"></div></div>
      <div class="field"><label>Paid by *</label><select name="method"><?= $methodOpts ?></select><div class="err" data-err="method"></div></div>
    </div>
    <div class="pm-diff" data-f="over" hidden></div>
    <div class="grid2">
      <div class="field"><label>Received into</label><select name="payment_account_id"><?= $accOpts() ?></select><div class="hint">Required except for cash.</div><div class="err" data-err="payment_account_id"></div></div>
      <div class="field"><label>Reference</label><input name="reference" maxlength="80" placeholder="Cheque no. / UTR"><div class="err" data-err="reference"></div></div>
    </div>
    <div class="grid2">
      <div class="field"><label>Received on</label><input type="datetime-local" name="received_at" value="<?= $nowLocal ?>" max="<?= $nowLocal ?>"><div class="err" data-err="received_at"></div></div>
      <div class="field"><label>Notes</label><input name="notes" maxlength="500"></div>
    </div>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn gold" type="submit"><i data-lucide="indian-rupee" style="width:16px"></i>Record payment</button></div>
</form></div>

<!-- Record a refund already sent -->
<div class="modal-backdrop pm-modal" id="pm-refund"><form class="modal" data-pm-form>
  <div class="modal-h"><div><div class="eyebrow" data-f="number"></div><h3>Record refund</h3></div><button type="button" class="iconbtn" data-close aria-label="Close"><i data-lucide="x"></i></button></div>
  <p class="text-sm muted" style="margin-top:-6px">Send the money back first (UPI / bank / cash), then record it here.</p>
  <div class="stack">
    <div class="grid2">
      <div class="field"><label>Amount refunded *</label><input name="amount" inputmode="decimal" autocomplete="off"><div class="hint" data-f="maxhint"></div><div class="err" data-err="amount"></div></div>
      <div class="field"><label>Sent by *</label><select name="method"><?= $methodOpts ?></select><div class="err" data-err="method"></div></div>
    </div>
    <div class="grid2">
      <div class="field"><label>Sent from</label><select name="payment_account_id"><?= $accOpts() ?></select><div class="err" data-err="payment_account_id"></div></div>
      <div class="field"><label>Reference</label><input name="reference" maxlength="80" placeholder="UTR / cheque no."></div>
    </div>
    <div class="grid2">
      <div class="field"><label>Reason *</label><input name="reason" maxlength="255" placeholder="Overpaid / order declined / return"><div class="err" data-err="reason"></div></div>
      <div class="field"><label>Refunded on</label><input type="datetime-local" name="refunded_at" value="<?= $nowLocal ?>" max="<?= $nowLocal ?>"><div class="err" data-err="refunded_at"></div></div>
    </div>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn gold" type="submit"><i data-lucide="undo-2" style="width:16px"></i>Record refund</button></div>
</form></div>

<!-- Request payment -->
<div class="modal-backdrop pm-modal" id="pm-request"><form class="modal" data-pm-form data-keep>
  <div class="modal-h"><div><div class="eyebrow" data-f="number"></div><h3>Request payment</h3></div><button type="button" class="iconbtn" data-close aria-label="Close"><i data-lucide="x"></i></button></div>
  <div class="stack">
    <div class="pm-reqamt"><small>Amount due</small><b data-f="due"></b><span class="text-xs muted">from the order — the link always asks the amount due when opened</span></div>
    <div class="field"><label>Send via</label>
      <div class="pm-channels">
        <label><input type="radio" name="channel" value="whatsapp" checked><span><i data-lucide="message-circle"></i>WhatsApp</span></label>
        <label><input type="radio" name="channel" value="email"><span><i data-lucide="mail"></i>Email</span></label>
        <label><input type="radio" name="channel" value="sms"><span><i data-lucide="smartphone"></i>SMS</span></label>
      </div></div>
    <div class="field"><label data-f="tolabel">Mobile</label><input name="to" autocomplete="off"><div class="err" data-err="to"></div></div>
    <div class="field"><label>Message</label><textarea name="message" rows="4" maxlength="1000"></textarea><div class="hint">The pay link is added if you remove it.</div></div>
    <p class="text-xs muted" data-f="last" style="margin:0"></p>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>Close</button><button class="btn gold" type="submit" data-f="send"><i data-lucide="send" style="width:16px"></i><span>Send request</span></button></div>
</form></div>

<!-- Void a payment / refund entry -->
<div class="modal-backdrop pm-modal" id="pm-void"><form class="modal" data-pm-form>
  <div class="modal-h"><div><div class="eyebrow">Correct a mistake</div><h3 data-f="title">Void</h3></div><button type="button" class="iconbtn" data-close aria-label="Close"><i data-lucide="x"></i></button></div>
  <p class="text-sm muted" style="margin-top:-6px">The entry stays in the history, struck through, and stops counting towards the order.</p>
  <div class="field"><label>Reason *</label><input name="reason" maxlength="255" placeholder="e.g. Typed ₹5,000 instead of ₹500"><div class="err" data-err="reason"></div></div>
  <div class="modal-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn danger-text" type="submit"><i data-lucide="ban" style="width:16px"></i>Void entry</button></div>
</form></div>
<?php
$pmCfg = json_encode(['attempt' => route('admin.payments.attempts.show', ['id' => '__ID__'])]);
$scripts = ($scripts ?? '') . '<script>window.PM=' . $pmCfg . ';</script>' . <<<'JS'
<script>
(function(){
  var csrf=(document.querySelector('meta[name="csrf-token"]')||{}).content||'';
  var inr=function(n){return '₹'+Number(n).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2})};
  var cents=function(v){var n=parseFloat(String(v).replace(/[,₹\s]/g,''));return isNaN(n)?null:Math.round(n*100)};
  function show(m){m.classList.add('show');var f=m.querySelector('input:not([type=hidden]):not([type=radio]),textarea');if(f)setTimeout(function(){f.focus()},40);}
  function hide(m){m.classList.remove('show')}
  function clearErr(f){f.querySelectorAll('[data-err]').forEach(function(e){e.textContent=''})}
  document.querySelectorAll('.pm-modal').forEach(function(m){
    m.querySelectorAll('[data-close]').forEach(function(b){b.addEventListener('click',function(){hide(m)})});
    m.addEventListener('click',function(e){if(e.target===m)hide(m)});
  });
  function send(form,url,extra){
    var fd=new FormData(form);fd.append('_token',csrf);if(extra)Object.keys(extra).forEach(function(k){fd.set(k,extra[k])});
    var btns=[].slice.call(form.closest('.modal').querySelectorAll('button[type=submit],button[data-v=reject]'));btns.forEach(function(b){b.disabled=true});
    clearErr(form.closest('.modal'));
    return fetch(url,{method:'POST',body:fd,credentials:'same-origin',headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf}})
      .then(function(r){return r.json().catch(function(){return{ok:false,message:'Server error ('+r.status+').'}})})
      .then(function(j){
        btns.forEach(function(b){b.disabled=false});
        if(j.errors){Object.keys(j.errors).forEach(function(k){var el=form.closest('.modal').querySelector('[data-err="'+k+'"]');if(el)el.textContent=j.errors[k][0];else msToast.error(j.errors[k][0])});return null;}
        if(!j.ok){msToast.error(j.message||'That didn’t work.');return null;}
        return j;
      },function(){btns.forEach(function(b){b.disabled=false});msToast.error('Network problem — try again.');return null;});
  }
  function reloadOn(j){if(j)location.reload()}

  /* verify */
  var V=document.getElementById('pm-verify'),vq=function(k){return V.querySelector('[data-v="'+k+'"]')},cur=null,rejecting=false;
  function diff(){
    var c=cents(vq('form').amount.value),box=vq('diff');
    if(c===null||!cur){box.hidden=true;return;}
    var exp=Math.round(cur.attempt.amount*100),d=c-exp;
    box.hidden=d===0;box.className='pm-diff '+(d<0?'bad':'warn');
    box.textContent=d<0?inr(c/100)+' received vs '+inr(exp/100)+' expected — the order will show '+inr(-d/100)+' more due.'
                     :inr(c/100)+' received vs '+inr(exp/100)+' expected — '+inr(d/100)+' extra; the order may need a refund.';
  }
  function setReject(on){rejecting=on;vq('rejectbox').hidden=!on;vq('reject').innerHTML=on?'Cancel reject':'<i data-lucide="x" style="width:16px"></i>Reject…';
    vq('confirm').innerHTML=on?'<i data-lucide="ban" style="width:16px"></i>Reject payment':'<i data-lucide="check" style="width:16px"></i>Confirm received';vq('confirm').classList.toggle('gold',!on);vq('confirm').classList.toggle('danger-text',on);vq('confirm').disabled=!on&&!!cur&&!cur.order;
    if(window.lucide)lucide.createIcons();if(on)vq('form').reason.focus();}
  function openVerify(id){
    show(V);vq('loading').hidden=false;vq('body').hidden=true;setReject(false);
    fetch(PM.attempt.replace('__ID__',id),{headers:{'Accept':'application/json'},credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){
      if(!j.ok){msToast.error(j.message||'Not found');hide(V);return;}
      cur=j;var a=j.attempt,f=vq('form');
      vq('title').textContent='Verify '+a.number+(j.order?' · '+j.order.number:'');
      vq('amount').textContent=a.amount_fmt;vq('payer').textContent=a.payer_name||'—';vq('utr').textContent=a.payer_reference||'— (screenshot only)';
      vq('copyutr').hidden=!a.payer_reference;vq('copyutr').onclick=function(){navigator.clipboard&&navigator.clipboard.writeText(a.payer_reference).then(function(){msToast('UTR copied')})};
      vq('when').textContent=a.submitted||'—';vq('account').textContent=a.account||'—';vq('ref').textContent=a.ref;
      var o=vq('order');if(j.order){o.textContent='Order '+j.order.number+' · '+j.order.customer;o.href=j.order.url;o.hidden=false}else{o.textContent='No order — create one first';o.removeAttribute('href')}
      var p=vq('proof');p.hidden=!j.proof;if(j.proof){p.href=j.proof.url;vq('proofimg').hidden=!j.proof.image;if(j.proof.image)vq('proofimg').src=j.proof.thumb;vq('proofname').textContent=j.proof.image?'Open full screenshot':j.proof.name}
      vq('dupes').hidden=!j.duplicates.length;vq('dupelist').innerHTML=j.duplicates.map(function(d){return '<li>'+d.payment_number+' on order '+d.order_number+'</li>'}).join('');
      f.reset();f.received_at.value=a.submitted_local;if(a.account_id)f.payment_account_id.value=a.account_id;
      f.dataset.verify=j.verify_url;f.dataset.reject=j.reject_url;vq('confirm').disabled=!j.order;
      vq('loading').hidden=true;vq('body').hidden=false;diff();if(window.lucide)lucide.createIcons();setTimeout(function(){f.amount.focus()},40);
    });
  }
  if(V){
    vq('form').amount.addEventListener('input',diff);
    vq('reject').addEventListener('click',function(){setReject(!rejecting)});
    vq('form').addEventListener('submit',function(e){e.preventDefault();var f=e.target;send(f,rejecting?f.dataset.reject:f.dataset.verify).then(reloadOn)});
  }

  /* record / refund / void */
  function fill(m,b){m.querySelectorAll('[data-f]').forEach(function(el){var k=el.dataset.f;if(b.dataset[k]!==undefined&&el.tagName!=='BUTTON')el.textContent=b.dataset[k]})}
  var R=document.getElementById('pm-record'),F=document.getElementById('pm-refund'),X=document.getElementById('pm-void'),Q=document.getElementById('pm-request');
  if(R){var rf=R.querySelector('form')||R;
    R.querySelector('[name=amount]').addEventListener('input',function(){var due=Math.round(parseFloat(R.dataset.due||0)*100),c=cents(this.value),ov=R.querySelector('[data-f=over]');
      ov.hidden=!(c!==null&&c>due);ov.className='pm-diff warn';ov.textContent=c>due?inr((c-due)/100)+' more than due — the extra will show as a refund to give back.':'';});
    R.querySelector('[name=method]').addEventListener('change',function(){var t=this.value==='cash'?'cash':(this.value==='upi'?'upi':(this.value==='bank_transfer'||this.value==='cheque'?'bank':''));var s=R.querySelector('[name=payment_account_id]');
      var hit=[].slice.call(s.options).find(function(o){return o.dataset.type===t});if(hit&&!s.value)s.value=hit.value;});
  }
  function openForm(m,b){var f=m.matches('form')?m:m.querySelector('form');f.reset();clearErr(m);fill(m,b);f.dataset.url=b.dataset.url;m.dataset.due=b.dataset.due||'';show(m);return f;}
  document.addEventListener('click',function(e){
    var b=e.target.closest('[data-pm-verify],[data-pm-record],[data-pm-refund],[data-pm-void],[data-pm-request]');if(!b)return;e.preventDefault();
    if(b.dataset.pmVerify){openVerify(b.dataset.pmVerify);return;}
    if(b.hasAttribute('data-pm-record')){var f=openForm(R,b);R.querySelector('[data-f=duehint]').textContent=parseFloat(b.dataset.due)>0?'Due now '+inr(b.dataset.due):'Nothing due';R.querySelector('[data-f=over]').hidden=true;
      var cs=[].slice.call(f.payment_account_id.options).find(function(o){return o.dataset.type==='cash'});f.method.value='cash';if(cs)f.payment_account_id.value=cs.value;return;}
    if(b.hasAttribute('data-pm-refund')){var g=openForm(F,b);F.querySelector('[data-f=maxhint]').textContent='Up to '+inr(b.dataset.max)+' (what was paid)';if(parseFloat(b.dataset.suggest)>0)g.amount.value=b.dataset.suggest;if(b.dataset.reason)g.reason.value=b.dataset.reason;return;}
    if(b.hasAttribute('data-pm-void')){openForm(X,b);X.querySelector('[data-f=title]').textContent=b.dataset.title||'Void';return;}
    if(b.hasAttribute('data-pm-request')){openRequest(b);}
  });
  [R,F,X].forEach(function(m){if(!m)return;var f=m.matches('form')?m:m.querySelector('form');f.addEventListener('submit',function(e){e.preventDefault();send(f,f.dataset.url).then(reloadOn)})});

  /* request */
  var reqBtn=null,cdTimer=null;
  function cooldown(sec){var s=Q.querySelector('[data-f=send]'),span=s.querySelector('span');clearInterval(cdTimer);
    function t(){if(sec<=0){s.disabled=false;span.textContent='Send request';clearInterval(cdTimer);return;}s.disabled=true;span.textContent='Wait '+sec+' s';sec--;}t();cdTimer=setInterval(t,1000);}
  function chan(){var f=Q.querySelector('form')||Q,c=f.querySelector('[name=channel]:checked').value;
    Q.querySelector('[data-f=tolabel]').textContent=c==='email'?'Email':'Mobile';var to=f.querySelector('[name=to]');to.type=c==='email'?'email':'tel';
    to.value=c==='email'?(reqBtn.dataset.email||''):(reqBtn.dataset.phone||'');to.placeholder=c==='email'?'name@example.com':'10-digit mobile';}
  function openRequest(b){reqBtn=b;var f=openForm(Q,b);f.message.value=b.dataset.message||'';Q.querySelector('[data-f=due]').textContent=inr(b.dataset.due);
    Q.querySelector('[data-f=last]').textContent=b.dataset.last||'';chan();cooldown(parseInt(b.dataset.cooldown||'0',10));}
  if(Q){var qf=Q.querySelector('form')||Q;
    qf.querySelectorAll('[name=channel]').forEach(function(r){r.addEventListener('change',chan)});
    qf.addEventListener('submit',function(e){e.preventDefault();
      var c=qf.querySelector('[name=channel]:checked').value,win=c==='whatsapp'?window.open('','_blank'):null;
      send(qf,qf.dataset.url).then(function(j){
        if(!j){if(win)win.close();return;}
        if(j.open_url){if(c==='whatsapp'&&win){win.location=j.open_url}else{location.href=j.open_url}}
        msToast.success(j.message);Q.querySelector('[data-f=last]').textContent='Last sent: just now via '+c;reqBtn.dataset.cooldown=String(j.cooldown);reqBtn.dataset.last='Last sent: just now via '+c;cooldown(j.cooldown);
      });
    });
  }
})();
</script>
JS;
