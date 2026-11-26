<?php
/**
 * "Sensitive changes: ask twice" (docs/README.md). Include once per page, then call
 *   msSensitive({ title, changes: [{label, from, to}], typeName, danger, submit(otp, typedName) → Promise<{ok, message}> })
 * Step 1 reviews exactly what changes (values already masked by the caller), step 2 asks for the OTP
 * again. A wrong OTP (or any refusal) leaves the dialog open with the server's message; nothing is
 * written unless submit() resolves ok. Also exposes msOtpBoxes(el) → { value(), clear(), focus() }.
 */
?>
<div class="modal-backdrop sc-modal" id="sensitiveConfirm" aria-hidden="true"><div class="modal" role="dialog" aria-modal="true" aria-labelledby="scTitle">
  <div class="modal-h"><div><div class="eyebrow">Sensitive change · <span data-sc="step">Step 1 of 2</span></div><h3 id="scTitle" data-sc="title">Confirm change</h3></div><button type="button" class="iconbtn" data-sc-close aria-label="Close"><i data-lucide="x"></i></button></div>
  <div data-sc-pane="review">
    <p class="text-sm muted" style="margin:-6px 0 12px">Check exactly what changes:</p>
    <ul class="sc-changes" data-sc="changes"></ul>
    <div class="field" data-sc="typebox" hidden style="margin-top:14px"><label>Type <b data-sc="typename"></b> to confirm</label><input data-sc="typed" autocomplete="off"></div>
    <div class="modal-f"><button type="button" class="btn" data-sc-close>Cancel</button><button type="button" class="btn gold" data-sc="next">Looks right — continue</button></div>
  </div>
  <div data-sc-pane="otp" hidden>
    <p class="text-sm" style="margin:-6px 0 14px">Enter the 6-digit OTP to save this change.</p>
    <div class="otp-boxes" data-sc="otp"></div>
    <p class="err" data-sc="err" style="text-align:center"></p>
    <div class="modal-f"><button type="button" class="btn" data-sc="back">Back</button><button type="button" class="btn gold" data-sc="save"><i data-lucide="lock" style="width:15px"></i>Verify &amp; save</button></div>
  </div>
</div></div>
<?php
$scripts = ($scripts ?? '') . <<<'JS'
<script>
(function(){
  window.msOtpBoxes=function(el,onComplete){
    el.innerHTML='';var boxes=[];
    for(var i=0;i<6;i++){var b=document.createElement('input');b.className='otp-box';b.inputMode='numeric';b.maxLength=1;b.autocomplete=i?'off':'one-time-code';b.setAttribute('aria-label','Digit '+(i+1));el.appendChild(b);boxes.push(b);}
    function val(){return boxes.map(function(b){return b.value}).join('')}
    boxes.forEach(function(b,i){
      b.addEventListener('input',function(){b.value=b.value.replace(/\D/g,'').slice(-1);if(b.value&&i<5)boxes[i+1].focus();if(val().length===6&&onComplete)onComplete(val());});
      b.addEventListener('keydown',function(e){if(e.key==='Backspace'&&!b.value&&i>0){boxes[i-1].focus();boxes[i-1].value='';}if(e.key==='Enter'&&val().length===6&&onComplete)onComplete(val());});
      b.addEventListener('paste',function(e){var t=(e.clipboardData||window.clipboardData).getData('text').replace(/\D/g,'').slice(0,6);if(!t)return;e.preventDefault();
        t.split('').forEach(function(c,k){if(boxes[k])boxes[k].value=c});boxes[Math.min(t.length,5)].focus();if(t.length===6&&onComplete)onComplete(t);});
    });
    return{value:val,clear:function(){boxes.forEach(function(b){b.value=''});boxes[0].focus()},focus:function(){boxes[0].focus()}};
  };
  var M=document.getElementById('sensitiveConfirm'),q=function(k){return M.querySelector('[data-sc="'+k+'"]')},opts=null,otp=null,busy=false;
  function pane(n){M.querySelectorAll('[data-sc-pane]').forEach(function(p){p.hidden=p.dataset.scPane!==n});q('step').textContent=n==='review'?'Step 1 of 2':'Step 2 of 2';}
  function close(){M.classList.remove('show');opts=null;}
  function esc(s){return String(s==null||s===''?'—':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
  window.msSensitive=function(o){
    opts=o;q('title').textContent=o.title||'Confirm change';
    q('changes').innerHTML=(o.changes||[]).map(function(c){return '<li><span>'+esc(c.label)+'</span><s>'+esc(c.from)+'</s><i data-lucide="arrow-right"></i><b>'+esc(c.to)+'</b></li>'}).join('')||'<li><span>No fields changed</span></li>';
    q('typebox').hidden=!o.typeName;q('typename').textContent=o.typeName||'';q('typed').value='';
    q('next').className='btn '+(o.danger?'danger-text':'gold');q('next').disabled=!(o.changes||[]).length&&!o.typeName;
    q('err').textContent='';pane('review');M.classList.add('show');if(window.lucide)lucide.createIcons();
    setTimeout(function(){(o.typeName?q('typed'):q('next')).focus()},40);
  };
  function save(){
    if(busy||!opts)return;var code=otp.value();if(code.length!==6){q('err').textContent='Enter all 6 digits.';return;}
    busy=true;q('save').disabled=true;q('err').textContent='';
    Promise.resolve(opts.submit(code,q('typed').value)).then(function(r){
      busy=false;q('save').disabled=false;
      if(r&&r.ok){close();return;}
      q('err').textContent=(r&&r.message)||'Not saved.';otp.clear();
    },function(){busy=false;q('save').disabled=false;q('err').textContent='Network problem — nothing was saved.';});
  }
  otp=msOtpBoxes(q('otp'),save);
  q('next').addEventListener('click',function(){
    if(opts.typeName&&q('typed').value.trim().toLowerCase()!==opts.typeName.trim().toLowerCase()){msToast.error('Type the name exactly to continue.');return;}
    pane('otp');otp.clear();
  });
  q('back').addEventListener('click',function(){pane('review')});
  q('save').addEventListener('click',save);
  M.querySelectorAll('[data-sc-close]').forEach(function(b){b.addEventListener('click',close)});
  M.addEventListener('click',function(e){if(e.target===M)close()});
})();
</script>
JS;
