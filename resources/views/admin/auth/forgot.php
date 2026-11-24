<?php
$title = 'Forgot password';
$eyebrow = 'Password help';
$heading = 'Forgot your password?';
$lead = 'Enter the email you log in with. We will email you a 6-digit code to set a new password.';
include __DIR__ . '/_top.php';
?>
<?php if (!$mailReady): ?>
  <div class="auth-msg warn"><i data-lucide="triangle-alert"></i><span>Email isn’t set up on this site yet, so codes can’t be sent. Ask an admin to reset your password.</span></div>
<?php endif; ?>
<form method="post" action="<?= route('admin.forgot.send') ?>" class="auth-form">
  <?= csrf_field() ?>
  <div class="field">
    <label for="fid">Email</label>
    <input id="fid" name="login_id" value="<?= e((string) (session()->get('admin_login_id') ?? '')) ?>" required autocomplete="username" inputmode="email" placeholder="you@example.com" autofocus>
  </div>
  <button class="btn gold auth-submit" type="submit"><i data-lucide="send"></i>Email me a code</button>
  <p class="auth-foot">Only have a mobile login? Codes go by email only — ask an admin to reset your password.<br><a class="auth-link" href="<?= route('admin.login') ?>"><i data-lucide="arrow-left"></i>Back to log in</a></p>
</form>
<?php include __DIR__ . '/_bottom.php'; ?>
