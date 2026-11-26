<?php
/**
 * Delete your account (signed in; docs/mobile-app.md phase 2, CustomerAuth::deleteAccount).
 * @var array $user @var int $orders orders that are kept @var int $open of those, not finished yet
 */
$err = static fn(string $k): string => '<em class="block not-italic text-[12.5px] text-copper mt-1 empty:hidden">' . e((string) (error($k) ?? '')) . '</em>';
$inv = static fn(string $k): string => error($k) !== null ? ' aria-invalid="true"' : '';
?>
<div class="wrap pt-8 pb-12">
  <nav class="text-[13px] text-ink-500 flex items-center gap-1.5" aria-label="Breadcrumb">
    <a href="<?= e(store_url('/account/profile')) ?>" class="hover:text-ink">Your account</a><?= store_icon('chevron-right', '!w-3.5 !h-3.5') ?>
    <span class="text-ink font-semibold" aria-current="page">Delete account</span>
  </nav>
  <h1 class="font-display text-[34px] sm:text-[42px] leading-tight mt-3">Delete your account</h1>
  <p class="text-ink-500 mt-2 max-w-2xl">This can’t be undone. You can always make a new account later with the same mobile number.</p>

  <div class="mt-6 grid lg:grid-cols-2 gap-4 max-w-5xl">
    <section class="bg-white rounded-[24px] border border-ink/5 p-6">
      <h2 class="font-semibold text-[16px] flex items-center gap-2"><?= store_icon('trash-2', 'text-copper') ?> Deleted now</h2>
      <ul class="mt-3 space-y-2 text-[14px] text-ink-500 list-disc pl-5">
        <li>Your sign-in: name, mobile number, email and password. Every phone and computer is signed out.</li>
        <li>Your saved addresses, saved stones and your bag.</li>
        <li>Your name and contact details on reviews and enquiries you sent. Reviews stay up as “A customer”.</li>
        <li>WhatsApp offers: you won’t get any more.</li>
      </ul>
    </section>
    <section class="bg-white rounded-[24px] border border-ink/5 p-6">
      <h2 class="font-semibold text-[16px] flex items-center gap-2"><?= store_icon('archive', 'text-teal') ?> Kept, because the law requires it</h2>
      <ul class="mt-3 space-y-2 text-[14px] text-ink-500 list-disc pl-5">
        <li><?= $orders > 0 ? '<b class="text-ink">Your ' . $orders . ' order' . ($orders === 1 ? '' : 's') . '</b>' : 'Any orders' ?>, with the name, phone and address on them, payments and invoices. GST law makes us keep these for about six years.</li>
        <li>If you bought from our showroom before you made this account, the showroom keeps its own customer record. Ask us if you want that removed too.</li>
      </ul>
    </section>
  </div>

  <?php if ($open > 0): ?>
  <p class="mt-4 max-w-5xl rounded-2xl bg-gold/15 text-[#7a5c2c] px-5 py-4 text-[14px] flex gap-2"><?= store_icon('triangle-alert', 'shrink-0 mt-0.5') ?>
    <span>You have <?= $open ?> order<?= $open === 1 ? '' : 's' ?> still in progress. We’ll still deliver <?= $open === 1 ? 'it' : 'them' ?>, but you won’t see <?= $open === 1 ? 'it' : 'them' ?> in an account any more. Use <a class="underline" href="<?= e(store_url('/track')) ?>">Track order</a> with the order number and your mobile instead.</span></p>
  <?php endif; ?>

  <form method="post" action="<?= e((string) route('store.account.destroy')) ?>" class="mt-6 bg-white rounded-[24px] border border-ink/5 p-6 sm:p-8 max-w-2xl" novalidate>
    <?= csrf_field() ?>
    <label class="block"><span class="lbl">Your password</span>
      <input type="password" name="password" class="field" autocomplete="current-password" maxlength="72" required<?= $inv('password') ?>><?= $err('password') ?></label>
    <label class="mt-4 flex items-start gap-3 text-[14px] cursor-pointer">
      <input type="checkbox" name="confirm" value="1" class="mt-1 w-4 h-4 accent-copper"<?= $inv('confirm') ?>>
      <span>I understand my account will be deleted and this can’t be undone.<?= $err('confirm') ?></span>
    </label>
    <div class="mt-6 flex flex-wrap gap-2">
      <button class="btn btn-copper"><?= store_icon('trash-2') ?> Delete my account</button>
      <a href="<?= e(store_url('/account/profile')) ?>" class="btn btn-line">Keep my account</a>
    </div>
    <p class="mt-4 text-[12.5px] text-ink-400">Forgot your password? <a class="underline" href="<?= e(store_url('/account-deletion')) ?>">Ask us to delete it instead</a>.</p>
  </form>
</div>
