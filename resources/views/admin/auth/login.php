<?php
$title = 'Log in';
$heading = $invite ? 'Welcome, ' . $invite['name'] : 'Log in';
$lead = $invite
    ? 'Your login ID is filled in. Enter the <b>temporary password</b> from your message — you will set your own next.'
    : 'Use the email or mobile number your admin set up for you.';
include __DIR__ . '/_top.php';
?>
<form method="post" action="<?= route('admin.login.submit') ?>" class="auth-form" autocomplete="on">
  <?= csrf_field() ?>
  <?php if ($next !== ''): ?><input type="hidden" name="next" value="<?= e($next) ?>"><?php endif; ?>
  <div class="field">
    <label for="loginId">Email or mobile number</label>
    <input id="loginId" name="login_id" value="<?= e($loginId) ?>" required autocomplete="username" inputmode="email" placeholder="you@example.com or 98300 12345"<?= $loginId === '' ? ' autofocus' : '' ?>>
  </div>
  <div class="field">
    <label for="pw">Password <a class="auth-link" href="<?= route('admin.forgot') ?>">Forgot password?</a></label>
    <div class="auth-pw">
      <input id="pw" type="password" name="password" required autocomplete="current-password"<?= $loginId !== '' ? ' autofocus' : '' ?>>
      <button type="button" class="auth-eye" data-reveal="pw" aria-label="Show password" aria-pressed="false"><i data-lucide="eye"></i></button>
    </div>
  </div>
  <label class="auth-check"><input type="checkbox" name="remember" value="1"> Remember this device for 30 days</label>
  <button class="btn gold auth-submit" type="submit"><i data-lucide="log-in"></i>Log in</button>
  <p class="auth-foot">Locked out or no email? Ask the shop owner to reset your password.</p>
</form>
<?php include __DIR__ . '/_bottom.php'; ?>
