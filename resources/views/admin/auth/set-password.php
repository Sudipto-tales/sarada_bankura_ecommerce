<?php
$title = 'Set your password';
$eyebrow = 'First login';
$heading = 'Set your password';
$lead = 'Hi ' . e($user['name']) . ' — you logged in with a temporary password. Choose your own to continue. At least 8 characters; a short sentence is easiest to remember.';
include __DIR__ . '/_top.php';
?>
<form method="post" action="<?= route('admin.password.set.submit') ?>" class="auth-form">
  <?= csrf_field() ?>
  <input type="text" name="username" value="<?= e((string) ($user['email'] ?? $user['phone'])) ?>" autocomplete="username" hidden>
  <div class="field">
    <label for="pw1">New password</label>
    <div class="auth-pw">
      <input id="pw1" type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password" autofocus>
      <button type="button" class="auth-eye" data-reveal="pw1" aria-label="Show password" aria-pressed="false"><i data-lucide="eye"></i></button>
    </div>
  </div>
  <div class="field">
    <label for="pw2">Type it again</label>
    <input id="pw2" type="password" name="password_confirmation" required minlength="8" maxlength="72" autocomplete="new-password">
  </div>
  <button class="btn gold auth-submit" type="submit"><i data-lucide="check"></i>Save and continue</button>
</form>
<form method="post" action="<?= route('admin.logout') ?>" class="auth-foot"><?= csrf_field() ?><button type="submit" class="auth-link">Not you? Log out</button></form>
<?php include __DIR__ . '/_bottom.php'; ?>
