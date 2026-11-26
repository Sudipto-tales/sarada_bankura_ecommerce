<?php
/**
 * Public account deletion page (Google Play's "delete account" URL; docs/mobile-app.md phase 2).
 * Explains how to delete in the app / on the website, or by message without signing in.
 * @var bool $done just deleted (redirect from /account/delete) @var bool $signedIn
 */
$wa = store_wa_link('Hi, please delete my ' . business('name') . ' account. My registered mobile number is: ');
$email = (string) business('email', '');
$phone = (string) business('phone', '');
?>
<div class="wrap pt-8 pb-12 max-w-3xl">
  <p class="eyebrow">Your data</p>
  <h1 class="font-display text-[34px] sm:text-[42px] leading-tight mt-1">Delete your account</h1>

  <?php if ($done): ?>
  <p class="mt-5 rounded-2xl bg-teal-50 text-teal px-5 py-4 text-[14.5px] font-semibold flex gap-2"><?= store_icon('check-circle-2', 'shrink-0 mt-0.5') ?> Your account is deleted. You can close this page.</p>
  <?php endif; ?>

  <div class="prose-policy mt-6 space-y-6 text-[15px] text-ink-500 leading-relaxed">
    <section><h2>In the app or on the website</h2>
      <ol>
        <li>Sign in to your <?= e(business('name')) ?> account.</li>
        <li>Open <b>Account → Profile</b> and tap <b>Delete account</b> at the bottom.</li>
        <li>Enter your password and confirm. Your account is deleted at once.</li>
      </ol>
      <?php if ($signedIn): ?><p><a class="btn btn-copper" href="<?= e(store_url('/account/delete')) ?>">Delete my account</a></p><?php endif; ?>
    </section>

    <section><h2>Without signing in</h2>
      <p>Lost your phone or forgot your password? Message us <b>from your registered mobile number</b> and ask us to delete your account. We check the number, delete the account and reply when it’s done, within 30 days and usually much sooner.</p>
      <ul>
        <?php if ($wa !== ''): ?><li>WhatsApp: <a href="<?= e($wa) ?>" rel="noopener">message us</a></li><?php endif; ?>
        <?php if ($email !== ''): ?><li>Email: <a href="mailto:<?= e($email) ?>?subject=<?= rawurlencode('Delete my account') ?>"><?= e($email) ?></a> (include your registered mobile number)</li><?php endif; ?>
        <?php if ($phone !== ''): ?><li>Phone: <?= e($phone) ?></li><?php endif; ?>
        <li>Or visit the showroom: <?= e(implode(', ', array_filter([(string) business('address.line1', ''), (string) business('address.city', '')]))) ?></li>
      </ul>
    </section>

    <section><h2>What is deleted</h2>
      <ul>
        <li>Your sign-in: name, mobile number, email and password. Every device is signed out.</li>
        <li>Saved addresses, saved stones and your bag.</li>
        <li>Your name and contact details on reviews and enquiries. Reviews stay up as “A customer”.</li>
        <li>Your WhatsApp offers opt-in.</li>
      </ul>
    </section>

    <section><h2>What we keep, and for how long</h2>
      <ul>
        <li><b>Orders, payments and invoices</b>, including the name, phone, address and payment details (UTR, payment screenshot) on them. Indian GST law requires us to keep these records, currently for about six years. Then we delete them.</li>
        <li>If you were our showroom customer before you made an online account, the showroom keeps its own customer record. Ask us and we’ll remove what the law doesn’t require us to keep.</li>
      </ul>
      <p>Read the full <a href="<?= e(store_url('/policy')) ?>#privacy">privacy policy</a>.</p>
    </section>
  </div>
</div>
