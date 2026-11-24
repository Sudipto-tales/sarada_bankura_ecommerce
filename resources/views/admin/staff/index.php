<?php
use App\Services\AdminAccess\LoginId;

$active = 'staff';
$title = 'Staff';
$statusLabel = [
    'active' => ['Active', 'pill'], 'invited' => ['Invited', 'pill pill-gold'], 'invite_expired' => ['Invite expired', 'pill pill-red'],
    'reset_pending' => ['Password reset sent', 'pill pill-gold'], 'reset_expired' => ['Reset expired', 'pill pill-red'], 'disabled' => ['Switched off', 'pill pill-off'],
];
$count = ['all' => count($rows), 'active' => 0, 'pending' => 0, 'disabled' => 0];
foreach ($rows as $r) {
    $s = $r['display_status'];
    $count[$s === 'active' ? 'active' : ($s === 'disabled' ? 'disabled' : 'pending')]++;
}
$hasOldStaff = old('name') !== '' || old('email') !== '' || old('phone') !== '';
$oldRoles = array_map('intval', (array) old('roles', []));
include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs">Settings <i data-lucide="chevron-right"></i> Staff &amp; roles</div><h1>Staff<span style="color:#dca71f">.</span></h1></div>
  <button class="btn gold" type="button" data-staff-add><i data-lucide="user-plus" style="width:16px"></i>Add staff</button>
</div>

<div class="statstrip">
  <div class="stat glass"><span class="metric-icon"><i data-lucide="users"></i></span><div><small>Staff</small><b><?= $count['all'] ?></b><em>Admin logins</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#e4f4e9;color:#267a55"><i data-lucide="user-check"></i></span><div><small>Active</small><b><?= $count['active'] ?></b><em>Set their own password</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#fff0bf;color:#91670a"><i data-lucide="mail-question"></i></span><div><small>Waiting</small><b><?= $count['pending'] ?></b><em>Invite or reset not used yet</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#efeae0;color:#8b806d"><i data-lucide="user-x"></i></span><div><small>Switched off</small><b><?= $count['disabled'] ?></b><em>Can’t log in</em></div></div>
</div>

<?php if (is_array($issued)): $loginIds = array_filter([$issued['email'], $issued['phone'] ? LoginId::showPhone($issued['phone']) : null]); ?>
<div class="card glass st-issued" role="region" aria-label="Login details">
  <div class="card-h">
    <span class="flex items-center gap-2"><span class="metric-icon" style="background:#e4f4e9;color:#267a55"><i data-lucide="key-round"></i></span><span><h3><?= e($issued['title']) ?> · <?= e($issued['name']) ?></h3><span class="text-xs muted">Shown <b>only once</b> — copy it now if the message didn’t go out. Valid for 72 hours.</span></span></span>
  </div>
  <div class="st-issued-grid">
    <div><small>Login ID</small><b><?= e(implode(' or ', $loginIds)) ?></b></div>
    <div><small>Temporary password</small><b class="st-mono" id="stPw"><?= e($issued['password']) ?></b><button type="button" class="linkbtn" data-copy="#stPw"><i data-lucide="copy"></i>Copy</button></div>
    <div class="st-wide"><small>Login link</small><b class="st-mono st-link" id="stLink"><?= e($issued['link']) ?></b><button type="button" class="linkbtn" data-copy="#stLink"><i data-lucide="copy"></i>Copy</button></div>
  </div>
  <textarea id="stMsg" class="hidden" readonly><?= e($issued['message']) ?></textarea>
  <div class="st-issued-f">
    <?php if ($issued['emailed']): ?><span class="pill"><i data-lucide="mail-check" style="width:13px"></i>&nbsp;Emailed to <?= e($issued['emailed']) ?></span><?php endif; ?>
    <?php if ($issued['email_error']): ?><span class="pill pill-red"><i data-lucide="mail-x" style="width:13px"></i>&nbsp;Email not sent</span><?php endif; ?>
    <?php if ($issued['whatsapp']): ?><a class="btn gold" href="<?= e($issued['whatsapp']) ?>" target="_blank" rel="noopener"><i data-lucide="message-circle" style="width:16px"></i>Open WhatsApp &amp; send</a>
    <?php elseif ($issued['phone']): ?><a class="btn" href="<?= e('https://wa.me/91' . $issued['phone'] . '?text=' . rawurlencode($issued['message'])) ?>" target="_blank" rel="noopener"><i data-lucide="message-circle" style="width:16px"></i>Send by WhatsApp</a><?php endif; ?>
    <button type="button" class="btn" data-copy="#stMsg"><i data-lucide="clipboard-copy" style="width:16px"></i>Copy whole message</button>
  </div>
</div>
<?php endif; ?>

<?php if (!$mailReady): ?><div class="notice" style="margin-bottom:16px"><b>Email isn’t set up yet</b> (MAIL_* in .env), so invites can’t be emailed and staff can’t use “Forgot password”. Use WhatsApp or copy the details for now.</div><?php endif; ?>

<div class="card glass" style="padding:0">
  <div class="tablewrap" style="border:0">
    <table class="data st-table">
      <thead><tr><th>Name</th><th>Login ID</th><th>Roles</th><th>Status</th><th>Last login</th><th data-noexport></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $id = (int) $r['id'];
          $isMe = $id === $me;
          $isOwnerRow = in_array('owner', array_column($r['roles'], 'slug'), true);
          $locked = $isOwnerRow && !$meOwner;            // only an Owner touches an Owner
          [$lbl, $cls] = $statusLabel[$r['display_status']] ?? [$r['display_status'], 'pill'];
          $payload = e((string) json_encode(['id' => $id, 'name' => $r['name'], 'email' => $r['email'], 'phone' => $r['phone'], 'roles' => array_column($r['roles'], 'id'),
              'me' => $isMe, 'action' => route('admin.staff.update', ['id' => $id])]));
          $sendPayload = e((string) json_encode(['id' => $id, 'name' => $r['name'], 'email' => (bool) $r['email'], 'phone' => (bool) $r['phone']]));
      ?>
        <tr class="<?= $r['status'] === 'disabled' ? 'st-off' : '' ?>">
          <td><span class="flex items-center gap-2"><span class="avatar" style="width:32px;height:32px;font-size:13px"><?= e(mb_strtoupper(mb_substr((string) $r['name'], 0, 1))) ?></span><b><?= e($r['name']) ?></b><?php if ($isMe): ?><span class="pill pill-off">You</span><?php endif; ?></span></td>
          <td class="text-xs">
            <?php if ($r['email']): ?><div><i data-lucide="mail" class="st-ic"></i><?= e($r['email']) ?></div><?php endif; ?>
            <?php if ($r['phone']): ?><div><i data-lucide="smartphone" class="st-ic"></i><?= e(LoginId::showPhone($r['phone'])) ?></div><?php endif; ?>
          </td>
          <td><?php foreach ($r['roles'] as $ro): ?><span class="pill<?= $ro['slug'] === 'owner' ? ' pill-gold' : ' pill-off' ?>" style="margin:2px"><?= e($ro['name']) ?></span><?php endforeach; ?></td>
          <td><span class="<?= $cls ?>"><?= e($lbl) ?></span><?php if (in_array($r['display_status'], ['invited', 'reset_pending'], true) && $r['temp_password_expires_at']): ?><div class="text-xs muted">until <?= e(date('d M, g:i a', strtotime((string) $r['temp_password_expires_at']))) ?></div><?php endif; ?></td>
          <td class="text-xs muted"><?= $r['last_login_at'] ? e(date('d M Y, g:i a', strtotime((string) $r['last_login_at']))) : 'Never' ?></td>
          <td>
            <?php if (!$locked): ?>
            <span class="row-actions">
              <button type="button" class="iconbtn" title="Edit" data-staff-edit="<?= $payload ?>"><i data-lucide="pencil"></i></button>
              <?php if (!$isMe && $r['status'] === 'invited'): ?>
                <button type="button" class="iconbtn" title="Resend invite" data-staff-send="resend" data-staff="<?= $sendPayload ?>" data-url="<?= e(route('admin.staff.resend', ['id' => $id])) ?>"><i data-lucide="send"></i></button>
              <?php endif; ?>
              <?php if (!$isMe && $r['status'] !== 'disabled'): ?>
                <button type="button" class="iconbtn" title="Reset password" data-staff-send="reset" data-staff="<?= $sendPayload ?>" data-url="<?= e(route('admin.staff.reset', ['id' => $id])) ?>"><i data-lucide="key-round"></i></button>
                <form method="post" action="<?= e(route('admin.staff.disable', ['id' => $id])) ?>" onsubmit="return confirm('Switch off <?= e(addslashes((string) $r['name'])) ?>? They are logged out at once and can’t log in until switched back on.')" style="display:inline"><?= csrf_field() ?><button class="iconbtn danger" title="Switch off"><i data-lucide="user-x"></i></button></form>
              <?php elseif (!$isMe): ?>
                <form method="post" action="<?= e(route('admin.staff.enable', ['id' => $id])) ?>" style="display:inline"><?= csrf_field() ?><button class="iconbtn" title="Switch back on"><i data-lucide="user-check"></i></button></form>
              <?php endif; ?>
            </span>
            <?php else: ?><span class="text-xs muted" title="Only an Owner can change an Owner">Owner</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add / edit staff -->
<div class="modal-backdrop" id="staffModal">
  <form class="modal st-modal" method="post" action="<?= route('admin.staff.store') ?>" id="staffForm" data-store="<?= route('admin.staff.store') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="edit_url" id="stEditUrl" value="">
    <div class="modal-h"><div><div class="eyebrow">Staff &amp; roles</div><h3 id="staffTitle">Add staff</h3></div><button type="button" class="iconbtn" data-close aria-label="Close"><i data-lucide="x"></i></button></div>
    <div class="stack" style="gap:12px">
      <div class="field"><label for="stName">Name</label><input id="stName" name="name" required maxlength="120" value="<?= e((string) old('name')) ?>"><?php if (error('name')): ?><small class="st-err"><?= e(error('name')) ?></small><?php endif; ?></div>
      <div class="st-two">
        <div class="field"><label for="stEmail">Email</label><input id="stEmail" name="email" type="email" maxlength="190" value="<?= e((string) old('email')) ?>" placeholder="name@example.com"><?php if (error('email')): ?><small class="st-err"><?= e(error('email')) ?></small><?php endif; ?></div>
        <div class="field"><label for="stPhone">Mobile (WhatsApp)</label><input id="stPhone" name="phone" inputmode="tel" maxlength="16" value="<?= e((string) old('phone')) ?>" placeholder="98300 12345"><?php if (error('phone')): ?><small class="st-err"><?= e(error('phone')) ?></small><?php endif; ?></div>
      </div>
      <p class="text-xs muted" style="margin:-4px 0 0">At least one of email / mobile — it becomes their login ID.</p>
      <div class="field">
        <label>Roles <span class="muted" style="font-weight:600">· permissions add up</span></label>
        <div class="st-roles">
          <?php foreach ($roles as $ro): $isOwnerRole = $ro['slug'] === 'owner'; ?>
          <label class="st-role<?= $isOwnerRole && !$meOwner ? ' disabled' : '' ?>">
            <input type="checkbox" name="roles[]" value="<?= (int) $ro['id'] ?>"<?= in_array((int) $ro['id'], $oldRoles, true) ? ' checked' : '' ?><?= $isOwnerRole && !$meOwner ? ' disabled' : '' ?> data-owner="<?= $isOwnerRole ? 1 : 0 ?>">
            <span><b><?= e($ro['name']) ?></b><small><?= e((string) $ro['description']) ?><?= $isOwnerRole && !$meOwner ? ' Only an Owner can give this role.' : '' ?></small></span>
          </label>
          <?php endforeach; ?>
        </div>
        <?php if (error('roles')): ?><small class="st-err"><?= e(error('roles')) ?></small><?php endif; ?>
        <small class="muted text-xs" id="stSelfRoles" hidden>You can’t change your own roles.</small>
      </div>
      <div class="field" id="stSendField">
        <label for="stSend">Send login details by</label>
        <select id="stSend" name="send">
          <option value="email"<?= $mailReady ? '' : ' disabled' ?>>Email<?= $mailReady ? '' : ' (not set up)' ?></option>
          <option value="whatsapp">WhatsApp (opens with the message typed — you press Send)</option>
          <option value="both"<?= $mailReady ? '' : ' disabled' ?>>Email + WhatsApp</option>
          <option value="none">Don’t send — I’ll copy it</option>
        </select>
        <small class="muted text-xs">A random temporary password and a login link (72 hours) are made. They set their own password on first login.</small>
      </div>
    </div>
    <div class="modal-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn gold" type="submit" id="staffSubmit"><i data-lucide="user-plus" style="width:16px"></i>Add &amp; make invite</button></div>
  </form>
</div>

<!-- Resend invite / reset password -->
<div class="modal-backdrop" id="sendModal">
  <form class="modal" method="post" id="sendForm">
    <?= csrf_field() ?>
    <div class="modal-h"><div><div class="eyebrow" id="sendWho"></div><h3 id="sendTitle">Reset password</h3></div><button type="button" class="iconbtn" data-close aria-label="Close"><i data-lucide="x"></i></button></div>
    <p class="text-xs muted mb-3" id="sendLead"></p>
    <div class="stack" style="gap:12px">
      <div class="field">
        <label for="sendVia">Send by</label>
        <select id="sendVia" name="send">
          <option value="email" data-need="email">Email</option>
          <option value="whatsapp" data-need="phone">WhatsApp (you press Send)</option>
          <option value="both" data-need="both">Email + WhatsApp</option>
          <option value="none">Don’t send — I’ll copy it</option>
        </select>
      </div>
      <div class="field" id="sendPwField"><label for="sendPw">Your password (to confirm)</label><input id="sendPw" type="password" name="my_password" autocomplete="current-password"></div>
    </div>
    <div class="modal-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn gold" type="submit" id="sendSubmit"><i data-lucide="key-round" style="width:16px"></i>Reset password</button></div>
  </form>
</div>
<?php
$reopen = $hasOldStaff || errors() ? 'true' : 'false';
// After a failed edit, reopen in edit mode on the same person (the URL must be one of ours).
$oldEdit = (string) old('edit_url');
$oldEdit = preg_match('#^/admin/staff/\d+/update$#', $oldEdit) ? $oldEdit : '';
$reopenEdit = json_encode(['action' => $oldEdit, 'name' => (string) old('name'), 'roles' => $oldRoles, 'me' => false], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$mailJs = $mailReady ? 'true' : 'false';
$scripts = <<<JS
<script>
(function(){
  var mailReady={$mailJs};
  function show(m){m.classList.add('show');var f=m.querySelector('input:not([type=hidden]):not([disabled]),select');if(f)f.focus();}
  document.querySelectorAll('.modal-backdrop').forEach(function(m){
    m.addEventListener('click',function(e){if(e.target===m||e.target.closest('[data-close]'))m.classList.remove('show');});
  });
  document.addEventListener('keydown',function(e){if(e.key==='Escape')document.querySelectorAll('.modal-backdrop.show').forEach(function(m){m.classList.remove('show')});});

  var sm=document.getElementById('staffModal'),sf=document.getElementById('staffForm');
  function setMode(edit,d){
    document.getElementById('staffTitle').textContent=edit?'Edit '+d.name:'Add staff';
    document.getElementById('staffSubmit').lastChild.textContent=edit?'Save changes':'Add & make invite';
    document.getElementById('stSendField').hidden=edit;
    sf.action=edit?d.action:sf.dataset.store;document.getElementById('stEditUrl').value=edit?d.action:'';
    document.getElementById('stSelfRoles').hidden=!(edit&&d.me);
    sf.querySelectorAll('input[name="roles[]"]').forEach(function(c){
      if(edit)c.checked=d.roles.indexOf(+c.value)>=0;
      c.dataset.lock=edit&&d.me?'1':'';
      c.disabled=(c.dataset.owner==='1'&&c.closest('.st-role').classList.contains('disabled'))||(edit&&d.me);
    });
    // locked checkboxes don't post: keep their values with hidden copies
    sf.querySelectorAll('input[data-keep]').forEach(function(h){h.remove()});
    if(edit)sf.querySelectorAll('input[name="roles[]"]:disabled:checked').forEach(function(c){var h=document.createElement('input');h.type='hidden';h.name='roles[]';h.value=c.value;h.dataset.keep=1;sf.appendChild(h);});
  }
  document.querySelector('[data-staff-add]').addEventListener('click',function(){
    sf.reset();sf.elements.name.value='';sf.elements.email.value='';sf.elements.phone.value='';
    sf.querySelectorAll('input[name="roles[]"]').forEach(function(c){c.checked=false});
    setMode(false,{});sf.elements.send.value=mailReady?'email':'whatsapp';show(sm);
  });
  document.querySelectorAll('[data-staff-edit]').forEach(function(b){b.addEventListener('click',function(){
    var d=JSON.parse(b.dataset.staffEdit);sf.elements.name.value=d.name||'';sf.elements.email.value=d.email||'';sf.elements.phone.value=d.phone||'';
    setMode(true,d);show(sm);
  });});
  // pick a sensible send channel from what the person has
  function bestSend(sel,hasEmail,hasPhone){
    [].forEach.call(sel.options,function(o){var n=o.dataset.need;o.disabled=(n==='email'&&(!hasEmail||!mailReady))||(n==='phone'&&!hasPhone)||(n==='both'&&(!hasEmail||!hasPhone||!mailReady));});
    sel.value=hasEmail&&mailReady?'email':(hasPhone?'whatsapp':'none');
  }
  var em=document.getElementById('stEmail'),ph=document.getElementById('stPhone'),ss=document.getElementById('stSend');
  [].forEach.call(ss.options,function(o){if(o.value==='email')o.dataset.need='email';if(o.value==='whatsapp')o.dataset.need='phone';if(o.value==='both')o.dataset.need='both';});
  function syncAdd(){if(!document.getElementById('stSendField').hidden){var keep=ss.value;bestSend(ss,em.value.trim()!=='',ph.value.trim()!=='');if(keep&&!ss.querySelector('option[value="'+keep+'"]').disabled)ss.value=keep;}}
  em.addEventListener('input',syncAdd);ph.addEventListener('input',syncAdd);

  var dm=document.getElementById('sendModal'),df=document.getElementById('sendForm');
  document.querySelectorAll('[data-staff-send]').forEach(function(b){b.addEventListener('click',function(){
    var d=JSON.parse(b.dataset.staff),reset=b.dataset.staffSend==='reset';
    df.action=b.dataset.url;df.reset();
    document.getElementById('sendWho').textContent=d.name;
    document.getElementById('sendTitle').textContent=reset?'Reset password':'Resend invite';
    document.getElementById('sendLead').textContent=reset
      ?'A new temporary password and login link (72 hours) are made. Their current password stops working and they are logged out everywhere.'
      :'A new temporary password and login link (72 hours) are made. The old link and password stop working.';
    document.getElementById('sendPwField').hidden=!reset;document.getElementById('sendPw').required=reset;
    document.getElementById('sendSubmit').lastChild.textContent=reset?'Reset password':'Resend invite';
    bestSend(document.getElementById('sendVia'),d.email,d.phone);show(dm);
  });});

  document.querySelectorAll('[data-copy]').forEach(function(b){b.addEventListener('click',function(){
    var el=document.querySelector(b.dataset.copy),txt=el.value!==undefined&&el.tagName==='TEXTAREA'?el.value:el.textContent;
    (navigator.clipboard?navigator.clipboard.writeText(txt):Promise.reject()).then(function(){msToast('Copied')},function(){
      var t=document.createElement('textarea');t.value=txt;document.body.appendChild(t);t.select();try{document.execCommand('copy');msToast('Copied')}catch(e){msToast.error('Copy failed — select it by hand.')}t.remove();});
  });});

  if({$reopen}){var oe={$reopenEdit};if(oe.action){setMode(true,oe);}else{setMode(false,{});syncAdd();}show(sm);}
})();
</script>
JS;
include __DIR__ . '/../partials/footer.php';
?>
