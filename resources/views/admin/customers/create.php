<?php
use App\Services\Customer\CustomerService as CS;
use App\Services\Customer\Phone;

$active = $isEdit ? 'customers' : 'customers.create';
$title = $isEdit ? 'Edit ' . $c['name'] : 'Add customer';
include __DIR__ . '/_helpers.php';

$hasOld = session()->hasOldInput();
// Value for a field: old input after a failed save, else the stored value.
$v = static function (string $key, mixed $default = '') use ($c, $hasOld) {
    return $hasOld ? old($key, $default) : ($c[$key] ?? $default);
};
$phoneVal = $hasOld ? old('phone') : ($c ? Phone::format((string) $c['phone']) : '');
$checkedTags = $hasOld ? array_map('intval', (array) old('tags', [])) : $tagIds;

// Address cards: old input (keeps keys so errors line up), else stored rows, else one blank card.
$addrRows = [];
if ($hasOld) {
    foreach ((array) old('addresses', []) as $k => $a) {
        if (is_array($a)) $addrRows[(string) $k] = $a;
    }
    $defaultKey = (string) old('default_address', '');
} else {
    foreach ($addresses as $i => $a) {
        $addrRows['a' . $i] = $a;
    }
    $defaultKey = '';
    foreach ($addrRows as $k => $a) {
        if ((int) ($a['is_default'] ?? 0)) $defaultKey = (string) $k;
    }
}
if (!$addrRows) $addrRows['a0'] = [];
if ($defaultKey === '' || !isset($addrRows[$defaultKey])) $defaultKey = (string) array_key_first($addrRows);

$err = static fn(string $k): string => ($m = error($k)) !== null ? '<div class="err">' . e($m) . '</div>' : '';
$bad = static fn(string $k): string => error($k) !== null ? ' aria-invalid="true" class="is-bad"' : '';

$addressCard = static function (string $key, array $a, bool $isDefault) use ($err): string {
    $val = static fn(string $f): string => e((string) ($a[$f] ?? ''));
    $n = static fn(string $f): string => 'addresses[' . e($key) . '][' . $f . ']';
    $badA = static fn(string $f): string => error('addresses.' . $key . '.' . $f) !== null ? ' aria-invalid="true" class="is-bad"' : '';
    $type = (string) ($a['type'] ?? 'both');
    $types = '';
    foreach (CS::ADDRESS_TYPES as $k => $l) {
        $types .= '<option value="' . $k . '"' . ($type === $k ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    $po = e((string) ($a['post_office'] ?? ''));
    ob_start(); ?>
    <div class="cu-addr" data-key="<?= e($key) ?>">
      <input type="hidden" name="<?= $n('id') ?>" value="<?= $val('id') ?>">
      <div class="cu-addr-h">
        <label class="cu-default"><input type="radio" name="default_address" value="<?= e($key) ?>"<?= $isDefault ? ' checked' : '' ?>><span>Default</span></label>
        <input class="cu-addr-label" name="<?= $n('label') ?>" value="<?= $val('label') ?>" maxlength="40" placeholder="Label, e.g. Home or Site – Lalbazar" aria-label="Address label">
        <select name="<?= $n('type') ?>" aria-label="Address use"><?= $types ?></select>
        <button type="button" class="iconbtn danger" data-remove-addr title="Remove address" aria-label="Remove address"><i data-lucide="trash-2"></i></button>
      </div>
      <div class="stack" style="gap:12px">
        <div class="cu-pin">
          <div class="field"><label>Pincode</label><input name="<?= $n('pincode') ?>" value="<?= $val('pincode') ?>" inputmode="numeric" maxlength="6" placeholder="722101" data-pincode autocomplete="postal-code"<?= $badA('pincode') ?>><?= $err('addresses.' . $key . '.pincode') ?></div>
          <div class="cu-pin-status" data-pin-status aria-live="polite"><i data-lucide="map-pin"></i><span>Type a 6-digit pincode to fill the district, state and post office.</span></div>
        </div>
        <div class="field"><label>Address *</label><input name="<?= $n('address_line1') ?>" value="<?= $val('address_line1') ?>" maxlength="255" placeholder="House / shop no., building, street" autocomplete="address-line1"<?= $badA('address_line1') ?>><?= $err('addresses.' . $key . '.address_line1') ?></div>
        <div class="field"><input name="<?= $n('address_line2') ?>" value="<?= $val('address_line2') ?>" maxlength="255" placeholder="Landmark (optional)" aria-label="Address line 2" autocomplete="address-line2"></div>
        <div class="grid2" style="gap:12px">
          <div class="field"><label>Post office / area</label><input name="<?= $n('post_office') ?>" value="<?= $po ?>" maxlength="120" list="po-<?= e($key) ?>" data-po placeholder="Pick after the pincode"><datalist id="po-<?= e($key) ?>"></datalist></div>
          <div class="field"><label>Locality</label><input name="<?= $n('area') ?>" value="<?= $val('area') ?>" maxlength="120" placeholder="e.g. Lokepur"></div>
        </div>
        <div class="grid3" style="gap:12px">
          <div class="field"><label>City / town</label><input name="<?= $n('city') ?>" value="<?= $val('city') ?>" maxlength="120" data-city autocomplete="address-level2"></div>
          <div class="field"><label>District</label><input name="<?= $n('district') ?>" value="<?= $val('district') ?>" maxlength="120" data-district></div>
          <div class="field"><label>State</label><input name="<?= $n('state') ?>" value="<?= $val('state') ?>" maxlength="80" data-state autocomplete="address-level1"></div>
        </div>
        <details class="cu-more"<?= ($a['name'] ?? '') !== '' || ($a['phone'] ?? '') !== '' ? ' open' : '' ?>>
          <summary>Different receiver at this address</summary>
          <div class="grid2" style="gap:12px;margin-top:10px">
            <div class="field"><label>Receiver name</label><input name="<?= $n('name') ?>" value="<?= $val('name') ?>" maxlength="120" placeholder="Site supervisor"></div>
            <div class="field"><label>Receiver mobile</label><input name="<?= $n('phone') ?>" value="<?= $val('phone') ?>" inputmode="tel" maxlength="16"<?= $badA('phone') ?>><?= $err('addresses.' . $key . '.phone') ?></div>
          </div>
        </details>
        <input type="hidden" name="<?= $n('country') ?>" value="<?= $val('country') ?: 'India' ?>">
      </div>
    </div>
    <?php return (string) ob_get_clean();
};

include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div>
    <div class="crumbs"><a href="<?= route('admin.customers') ?>">Customers</a> <i data-lucide="chevron-right"></i> <?= $isEdit ? e((string) $c['name']) : 'Add customer' ?></div>
    <h1><?= $isEdit ? 'Edit customer' : 'Add customer' ?><span style="color:#dca71f">.</span></h1>
  </div>
  <div class="flex gap-2">
    <a class="btn" href="<?= $isEdit ? route('admin.customers.show', ['id' => $c['id']]) : route('admin.customers') ?>">Cancel</a>
    <button class="btn gold" type="submit" form="cuForm"><i data-lucide="check" style="width:16px"></i><?= $isEdit ? 'Save changes' : 'Save customer' ?></button>
  </div>
</div>

<form id="cuForm" method="post" action="<?= $isEdit ? route('admin.customers.update', ['id' => $c['id']]) : route('admin.customers.store') ?>" novalidate>
  <?= csrf_field() ?>
  <div class="cu-form">
    <div class="stack" style="gap:18px">
      <section class="card">
        <div class="sectionhead" style="margin-bottom:18px"><span class="sectionicon"><i data-lucide="user-round"></i></span><div><h2>Basic info</h2><p class="text-xs muted">The mobile number is the customer’s identity — one customer per number.</p></div></div>
        <div class="stack">
          <div class="grid2">
            <div class="field"><label for="cuName">Full name *</label><input id="cuName" name="name" value="<?= e((string) $v('name')) ?>" maxlength="120" required autocomplete="name" placeholder="e.g. Rina Das"<?= $bad('name') ?>><?= $err('name') ?></div>
            <div class="field"><label for="cuPhone">Mobile *</label><input id="cuPhone" name="phone" value="<?= e((string) $phoneVal) ?>" inputmode="tel" maxlength="16" required autocomplete="tel" placeholder="98300 12345"<?= $bad('phone') ?>><?= $err('phone') ?: '<div class="hint">10 digits; +91 and a leading 0 are removed.</div>' ?></div>
          </div>
          <div class="grid2">
            <div class="field"><label for="cuEmail">Email</label><input id="cuEmail" name="email" type="email" value="<?= e((string) $v('email')) ?>" maxlength="160" autocomplete="email" placeholder="name@example.com"<?= $bad('email') ?>><?= $err('email') ?></div>
            <div class="field"><label for="cuType">Customer type</label><select id="cuType" name="customer_type"><?php foreach (CS::TYPES as $k => $l): ?><option value="<?= $k ?>"<?= (string) $v('customer_type', 'individual') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
          </div>
          <div class="grid2">
            <div class="field"><label for="cuCompany">Firm / company</label><input id="cuCompany" name="company" value="<?= e((string) $v('company')) ?>" maxlength="160" placeholder="For builders, dealers and architects"></div>
            <div class="field"><label for="cuGst">GSTIN</label><input id="cuGst" name="gst_number" value="<?= e((string) $v('gst_number')) ?>" maxlength="15" placeholder="19ABCDE1234F1Z5" style="text-transform:uppercase"<?= $bad('gst_number') ?>><?= $err('gst_number') ?: '<div class="hint">Optional — only for business customers.</div>' ?></div>
          </div>
          <div class="grid2">
            <div class="field"><label for="cuStatus">Status</label><select id="cuStatus" name="status"><?php foreach (CS::STATUSES as $k => $l): ?><option value="<?= $k ?>"<?= (string) $v('status', 'active') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <?php if (!$isEdit): ?>
            <div class="field"><label for="cuSource">Joined via</label><select id="cuSource" name="source"><?php foreach (['admin' => CS::SOURCES['admin'], 'showroom' => CS::SOURCES['showroom']] as $k => $l): ?><option value="<?= $k ?>"<?= (string) $v('source', 'showroom') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <?php else: ?>
            <div class="field"><label>Joined via</label><input value="<?= e(CS::SOURCES[$c['source']] ?? $c['source']) ?>" disabled></div>
            <?php endif; ?>
          </div>
          <label class="cu-consent">
            <input type="hidden" name="marketing_consent" value="0">
            <input type="checkbox" class="switch-in" name="marketing_consent" value="1"<?= (int) $v('marketing_consent', 0) ? ' checked' : '' ?>>
            <span><b>Subscribed to offers &amp; updates</b><small>Only tick this when the customer agreed to hear about new stone and offers.</small></span>
          </label>
        </div>
      </section>

      <section class="card">
        <div class="sectionhead" style="margin-bottom:16px"><span class="sectionicon" style="background:#efe7f6;color:#6f4a92"><i data-lucide="tags"></i></span><div><h2>Tags</h2><p class="text-xs muted">Your own labels for this customer — separate from product tags.</p></div>
          <a class="linkbtn" style="margin-left:auto" href="<?= route('admin.customers.tags') ?>">Manage <i data-lucide="arrow-right"></i></a></div>
        <input type="hidden" name="tags[]" value="">
        <div class="cu-tagpick">
          <?php foreach ($allTags as $t): ?>
          <label class="cu-tagopt"><input type="checkbox" name="tags[]" value="<?= (int) $t['id'] ?>"<?= in_array((int) $t['id'], $checkedTags, true) ? ' checked' : '' ?>><span style="--tone:<?= e(explode(';', CS::TAG_COLORS[$t['color']] ?? CS::TAG_COLORS['gold'])[0]) ?>"><?= e((string) $t['name']) ?></span></label>
          <?php endforeach; ?>
          <?php if (!$allTags): ?><span class="text-xs muted">No customer tags yet — type some below.</span><?php endif; ?>
        </div>
        <div class="field" style="margin-top:14px"><label for="cuNewTags">New tags</label><input id="cuNewTags" name="new_tags" value="<?= e((string) old('new_tags')) ?>" maxlength="200" placeholder="Comma-separated, e.g. Repeat buyer, Kolkata project"></div>
      </section>

      <section class="card">
        <div class="sectionhead" style="margin-bottom:16px"><span class="sectionicon" style="background:#e3eef8;color:#3d6a92"><i data-lucide="notebook-pen"></i></span><div><h2>Notes</h2><p class="text-xs muted">Internal only — never shown to the customer.</p></div></div>
        <div class="field"><textarea name="notes" rows="4" maxlength="4000" placeholder="Preferred stone, site visits, how they found us…" aria-label="Notes"><?= e((string) $v('notes')) ?></textarea></div>
      </section>
    </div>

    <div class="stack" style="gap:18px">
      <section class="card">
        <div class="sectionhead" style="margin-bottom:16px"><span class="sectionicon" style="background:#e4f4e9;color:#267a55"><i data-lucide="map-pinned"></i></span><div><h2>Addresses</h2><p class="text-xs muted">Pincode fills district, state and post office automatically.</p></div></div>
        <?= error('addresses') ? '<div class="err" style="margin:-6px 0 12px">' . e((string) error('addresses')) . '</div>' : '' ?>
        <div class="stack" id="cuAddrs" style="gap:14px">
          <?php foreach ($addrRows as $k => $a) echo $addressCard((string) $k, $a, (string) $k === $defaultKey); ?>
        </div>
        <button type="button" class="btn" id="cuAddAddr" style="margin-top:14px;width:100%;justify-content:center"><i data-lucide="plus" style="width:16px"></i>Add another address</button>
        <template id="cuAddrTpl"><?= $addressCard('__KEY__', [], false) ?></template>
      </section>

      <section class="card">
        <div class="card-h"><div><div class="eyebrow">Online store</div><h3>Online account</h3></div><?= $user ? '<span class="pill">Linked</span>' : '<span class="pill pill-off">Not registered</span>' ?></div>
        <?php if ($user): ?>
        <dl class="kv"><dt>Login email</dt><dd><?= e((string) ($user['email'] ?? '—')) ?></dd><dt>Mobile</dt><dd><?= e(Phone::format((string) $user['phone'])) ?></dd><dt>Last sign-in</dt><dd><?= $cuDate($user['last_login_at'], 'j M Y, H:i') ?></dd></dl>
        <?php else: ?>
        <p class="text-sm muted" style="margin:0">When someone signs up on the online store with this mobile number, their account links to this customer automatically — no duplicate records.</p>
        <?php endif; ?>
      </section>

      <?php if ($isEdit): ?>
      <section class="card">
        <div class="card-h"><div><div class="eyebrow">Purchases</div><h3>Order stats</h3></div><span class="text-xs muted">Delivered orders</span></div>
        <div class="cu-stats">
          <div><small>Orders</small><b><?= (int) $c['total_orders'] ?></b></div>
          <div><small>Spent</small><b><?= $cuMoney($c['total_spent']) ?></b></div>
          <div><small>Avg. order</small><b><?= $cuMoney($aov) ?></b></div>
          <div><small>Last order</small><b><?= $cuDate($c['last_order_at']) ?></b></div>
        </div>
      </section>
      <?php endif; ?>
    </div>
  </div>

  <div class="cu-actions">
    <?php if ($isEdit): ?>
    <button type="button" class="btn ghost danger-text" data-del="<?= e(route('admin.customers.delete', ['id' => $c['id']])) ?>" data-del-name="<?= e((string) $c['name']) ?>"><i data-lucide="trash-2" style="width:16px"></i>Delete customer</button>
    <?php endif; ?>
    <span style="flex:1"></span>
    <?php if (!$isEdit): ?><button class="btn" type="submit" name="_after" value="new">Save &amp; add another</button><?php endif; ?>
    <button class="btn gold" type="submit"><i data-lucide="check" style="width:16px"></i><?= $isEdit ? 'Save changes' : 'Save customer' ?></button>
  </div>
</form>
<?php
$lookup = json_encode(route('admin.lookup.pincode', ['code' => '000000']));
$scripts = '<script>window.MS_PINCODE_URL=' . $lookup . ';</script>' . <<<'JS'
<script>
(function(){
  var box=document.getElementById('cuAddrs'),tpl=document.getElementById('cuAddrTpl'),n=0;
  function bind(card){
    card.querySelector('[data-remove-addr]').addEventListener('click',function(){
      var cards=box.querySelectorAll('.cu-addr');
      if(cards.length===1){card.querySelectorAll('input:not([type=radio]):not([type=hidden]),select').forEach(function(i){i.value=i.tagName==='SELECT'?'both':''});card.querySelector('[name$="[id]"]').value='';return;}
      var wasDefault=card.querySelector('[type=radio]').checked;card.remove();
      if(wasDefault){var r=box.querySelector('[type=radio]');if(r)r.checked=true;}
    });
    var pin=card.querySelector('[data-pincode]'),last='';
    function go(){
      var code=pin.value.replace(/\D/g,'');if(pin.value!==code)pin.value=code;
      if(code.length!==6||code===last)return;last=code;lookup(card,code);
    }
    pin.addEventListener('input',go);pin.addEventListener('blur',go);
    if(pin.value.length===6){last=pin.value;lookup(card,pin.value,true);}
  }
  function status(card,state,msg){
    var s=card.querySelector('[data-pin-status]');s.className='cu-pin-status '+state;
    var icon={loading:'loader-circle',ok:'circle-check',bad:'circle-alert',idle:'map-pin'}[state]||'map-pin';
    s.innerHTML='<i data-lucide="'+icon+'"></i><span></span>';s.querySelector('span').textContent=msg;lucide.createIcons();
  }
  // keep=true (page load): only offer the post-office list, never overwrite what was saved.
  function fill(card,d,keep){
    var set=function(sel,val,force){var el=card.querySelector(sel);if(el&&val&&!keep&&(force||!el.value.trim()))el.value=val;};
    set('[data-state]',d.state,true);set('[data-district]',d.district,true);set('[data-city]',d.district,false);
    var dl=card.querySelector('datalist');dl.innerHTML='';
    (d.areas||[]).forEach(function(a){var o=document.createElement('option');o.value=a;dl.appendChild(o);});
    var po=card.querySelector('[data-po]');
    if(!keep&&d.areas&&d.areas.length===1&&!po.value)po.value=d.areas[0];
    status(card,'ok',(d.district?d.district+', ':'')+d.state+' · '+(d.areas||[]).length+' post office'+((d.areas||[]).length===1?'':'s')+' — pick one below.');
  }
  function lookup(card,code,keep){
    if(!keep)status(card,'loading','Looking up '+code+'…');
    fetch(window.MS_PINCODE_URL.replace('000000',code),{headers:{Accept:'application/json'},credentials:'same-origin'})
      .then(function(r){return r.json()})
      .then(function(j){
        if(j.ok)return fill(card,j,keep);
        if(keep)return;
        status(card,'bad',j.reason==='not_found'||j.reason==='invalid'?'No post office found for '+code+' — check the pincode.':'Couldn’t look up the pincode right now — enter the details by hand.');
      })
      .catch(function(){if(!keep)status(card,'bad','Couldn’t look up the pincode right now — enter the details by hand.')});
  }
  box.querySelectorAll('.cu-addr').forEach(bind);
  document.getElementById('cuAddAddr').addEventListener('click',function(){
    if(box.querySelectorAll('.cu-addr').length>=10){msToast.warning('A customer can keep up to 10 addresses.');return;}
    var key='n'+(++n)+Date.now().toString(36);
    var wrap=document.createElement('div');wrap.innerHTML=tpl.innerHTML.replace(/__KEY__/g,key);
    var card=wrap.firstElementChild;box.appendChild(card);bind(card);lucide.createIcons();
    card.querySelector('[data-pincode]').focus();
  });
  // Live-format the mobile as 5 + 5 digits.
  var ph=document.getElementById('cuPhone');
  ph.addEventListener('blur',function(){var d=ph.value.replace(/\D/g,'');if(d.length===12&&d.indexOf('91')===0)d=d.slice(2);if(d.length===11&&d[0]==='0')d=d.slice(1);if(d.length===10)ph.value=d.slice(0,5)+' '+d.slice(5);});
  var firstBad=document.querySelector('[aria-invalid=true]');if(firstBad)firstBad.focus();
  msBindDelete();
})();
</script>
JS;
include __DIR__ . '/../partials/footer.php';
