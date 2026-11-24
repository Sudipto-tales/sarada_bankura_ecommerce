<?php
$title = 'Set a new password';
$eyebrow = 'Password help';
$heading = 'Enter your code';
$lead = 'We sent a 6-digit code to <b>' . e($email) . '</b> (if it belongs to a staff account). It is valid for 10 minutes — check spam too.';
include __DIR__ . '/_top.php';
?>
<form method="post" action="<?= route('admin.forgot.reset.submit') ?>" class="auth-form">
  <?= csrf_field() ?>
  <input type="text" name="username" value="<?= e($email) ?>" autocomplete="username" hidden>
  <div class="field">
    <label for="code">6-digit code</label>
    <input id="code" name="code" required inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" class="auth-code" placeholder="••••••" autofocus>
  </div>
  <div class="field">
    <label for="pw1">New password</label>
    <div class="auth-pw">
      <input id="pw1" type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password">
      <button type="button" class="auth-eye" data-reveal="pw1" aria-label="Show password" aria-pressed="false"><i data-lucide="eye"></i></button>
    </div>
  </div>
  <div class="field">
    <label for="pw2">Type it again</label>
    <input id="pw2" type="password" name="password_confirmation" required minlength="8" maxlength="72" autocomplete="new-password">
  </div>
  <button class="btn gold auth-submit" type="submit"><i data-lucide="check"></i>Set new password</button>
</form>
<form method="post" action="<?= route('admin.forgot.send') ?>" class="auth-foot">
  <?= csrf_field() ?><input type="hidden" name="login_id" value="<?= e($email) ?>">
  <button type="submit" class="auth-link" id="resend"<?= $resendIn > 0 ? ' disabled' : '' ?> data-wait="<?= (int) $resendIn ?>">Send a new code</button>
  · <a class="auth-link" href="<?= route('admin.login') ?>">Back to log in</a>
</form>
<?php
$scripts = '<script>(function(){var b=document.getElementById("resend"),w=+b.dataset.wait,t=b.textContent;function tick(){if(w>0){b.disabled=true;b.textContent=t+" ("+w+"s)";w--;setTimeout(tick,1000);}else{b.disabled=false;b.textContent=t;}}tick();})();</script>';
include __DIR__ . '/_bottom.php';
?>
