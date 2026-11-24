<?php
use App\Services\AdminAccess\LoginId;
use App\Services\AdminAccess\Otp;

$active = 'account';
$title = 'My account';
$reasons = ['ok' => 'Logged in', 'remember' => 'Remembered device', 'wrong_password' => 'Wrong password', 'locked' => 'Blocked (too many tries)', 'disabled' => 'Account off', 'expired' => 'Temporary password expired', 'unknown' => 'Unknown'];
include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs">Account <i data-lucide="chevron-right"></i> <?= e($user['name']) ?></div><h1>My account<span style="color:#dca71f">.</span></h1></div>
  <form method="post" action="<?= route('admin.logout') ?>"><?= csrf_field() ?><button class="btn" type="submit"><i data-lucide="log-out" style="width:16px"></i>Log out</button></form>
</div>

<div class="layout" style="grid-template-columns:minmax(0,1fr) 380px">
  <div class="stack" style="gap:16px">
    <div class="card glass acc-profile">
      <span class="avatar acc-avatar"><?= e(mb_strtoupper(mb_substr((string) $user['name'], 0, 1))) ?></span>
      <div>
        <h3><?= e($user['name']) ?></h3>
        <p class="muted text-xs">
          <?php if ($user['email']): ?><span><i data-lucide="mail"></i><?= e($user['email']) ?></span><?php endif; ?>
          <?php if ($user['phone']): ?><span><i data-lucide="smartphone"></i><?= e(LoginId::showPhone($user['phone'])) ?></span><?php endif; ?>
        </p>
        <div><?php foreach ($roles as $r): ?><span class="pill<?= $r['slug'] === 'owner' ? ' pill-gold' : '' ?>"><?= e($r['name']) ?></span> <?php endforeach; ?></div>
      </div>
    </div>

    <div class="card glass">
      <div class="card-h"><h3>Login history</h3><span class="text-xs muted">Last 15</span></div>
      <?php if (!$history): ?><p class="text-xs muted">Nothing yet.</p><?php else: ?>
      <div class="tablewrap"><table class="data">
        <thead><tr><th>When</th><th>Result</th><th>IP</th><th>Device</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
          <tr>
            <td><?= e(date('d M Y, g:i a', strtotime((string) $h['created_at']))) ?></td>
            <td><span class="pill<?= (int) $h['success'] ? '' : ' pill-off' ?>"><?= e($reasons[$h['reason']] ?? (string) $h['reason']) ?></span></td>
            <td class="muted"><?= e((string) $h['ip']) ?></td>
            <td class="muted text-xs acc-ua" title="<?= e((string) $h['user_agent']) ?>"><?= e(mb_strimwidth((string) $h['user_agent'], 0, 60, '…')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>

  <aside class="stack" style="gap:16px">
    <div class="card glass" id="password">
      <div class="card-h"><h3>Change password</h3></div>
      <?php if (!$user['email']): ?>
        <p class="text-xs muted">Codes for changing your password are sent by email, and your account has no email. Ask an admin to reset your password (they can send it by WhatsApp).</p>
      <?php else: ?>
        <p class="text-xs muted mb-3">We email a 6-digit code to <b><?= e(Otp::maskEmail($user['email'])) ?></b>. Enter it with your new password. Your other devices are logged out afterwards.</p>
        <form method="post" action="<?= route('admin.account.code') ?>" class="mb-3"><?= csrf_field() ?>
          <button class="btn" type="submit" id="accSend" data-wait="<?= (int) $resendIn ?>"<?= $resendIn > 0 ? ' disabled' : '' ?>><i data-lucide="send" style="width:15px"></i><span><?= $codeSent ? 'Send a new code' : 'Email me a code' ?></span></button>
        </form>
        <?php if ($codeSent): ?>
        <form method="post" action="<?= route('admin.account.password') ?>" class="stack" style="gap:12px">
          <?= csrf_field() ?>
          <input type="text" name="username" value="<?= e((string) $user['email']) ?>" autocomplete="username" hidden>
          <div class="field"><label for="accCode">6-digit code</label><input id="accCode" name="code" required inputmode="numeric" maxlength="7" autocomplete="one-time-code" class="auth-code" placeholder="••••••"></div>
          <div class="field"><label for="accPw1">New password</label><input id="accPw1" type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password"></div>
          <div class="field"><label for="accPw2">Type it again</label><input id="accPw2" type="password" name="password_confirmation" required minlength="8" maxlength="72" autocomplete="new-password"></div>
          <button class="btn gold" type="submit" style="justify-content:center"><i data-lucide="check" style="width:16px"></i>Change password</button>
        </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="card glass">
      <div class="card-h"><h3>Devices</h3></div>
      <p class="text-xs muted mb-3"><?= count($devices) ? count($devices) . ' remembered device' . (count($devices) === 1 ? '' : 's') . ' (30-day “Remember this device”).' : 'No remembered devices.' ?> Lost a phone or used a shared computer? Log out everywhere else.</p>
      <?php foreach ($devices as $d): ?><div class="text-xs acc-device"><i data-lucide="monitor-smartphone"></i><span><?= e(mb_strimwidth((string) $d['device'], 0, 70, '…')) ?></span></div><?php endforeach; ?>
      <form method="post" action="<?= route('admin.account.logoutOthers') ?>" onsubmit="return confirm('Log out all your other devices and browsers?')"><?= csrf_field() ?>
        <button class="btn" type="submit" style="width:100%;justify-content:center;margin-top:10px"><i data-lucide="monitor-x" style="width:15px"></i>Log out other devices</button>
      </form>
    </div>
  </aside>
</div>
<?php
$scripts = '<script>(function(){var b=document.getElementById("accSend");if(!b)return;var s=b.querySelector("span"),t=s.textContent,w=+b.dataset.wait;(function tick(){if(w>0){b.disabled=true;s.textContent=t+" ("+w+"s)";w--;setTimeout(tick,1000);}else{b.disabled=false;s.textContent=t;}})();})();</script>';
include __DIR__ . '/../partials/footer.php';
?>
