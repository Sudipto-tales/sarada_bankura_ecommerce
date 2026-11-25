<?php
/**
 * Sign in · create account · forgot / reset password (design: html/storefront/signin.html;
 * docs/storefront.md §11). One page, four panels; the server shows the right one ($tab) so it
 * works without JavaScript, store.js switches them in place. Every form carries `next` and the
 * guest saved list (`saved`, filled by store.js) so nothing is lost by signing in.
 *
 * @var string $next @var bool $forCheckout @var string $tab @var bool $mailOn @var string $resetEmail
 */
$err = static fn(string $k): string => '<em class="block not-italic text-[12.5px] text-copper mt-1 empty:hidden">' . e((string) (error($k) ?? '')) . '</em>';
$inv = static fn(string $k): string => error($k) !== null ? ' aria-invalid="true"' : '';
$hidden = csrf_field() . '<input type="hidden" name="next" value="' . e($next) . '"><input type="hidden" name="saved" value="">';
$tabUrl = static fn(string $t): string => store_url('/signin') . '?tab=' . $t . ($next !== '' ? '&next=' . rawurlencode($next) : '');
$wa = store_wa_link('Namaskar, please help me reset my password on the website. My registered mobile is: ');
$show = static fn(string $p): string => $tab === $p ? '' : ' hidden';
$tabSel = $tab === 'up' ? 'up' : 'in';
?>
<div class="wrap pt-8">
  <div class="grid lg:grid-cols-2 rounded-[28px] overflow-hidden bg-white border border-ink/5 min-h-[640px]">
    <div class="relative hidden lg:block">
      <img src="<?= e(store_asset('image/defaults/bathroom-hero.jpg')) ?>" alt="" class="absolute inset-0 w-full h-full object-cover">
      <div class="absolute inset-0 bg-gradient-to-t from-ink/85 to-ink/10"></div>
      <div class="absolute left-10 right-10 bottom-10 text-cream">
        <h2 class="font-display text-[38px] leading-tight"><?= $forCheckout ? 'One step before you pay.' : 'Your orders, addresses and saved stones — in one place.' ?></h2>
        <ul class="mt-5 space-y-2 text-cream/80 text-[14.5px]">
          <li class="flex gap-2"><?= store_icon('check', 'mt-1 shrink-0') ?> Track every order and pay balances</li>
          <li class="flex gap-2"><?= store_icon('check', 'mt-1 shrink-0') ?> Check out faster with saved addresses</li>
          <li class="flex gap-2"><?= store_icon('check', 'mt-1 shrink-0') ?> Shop with us in person? The same number links your showroom orders.</li>
        </ul>
      </div>
    </div>

    <div class="p-7 sm:p-12 flex flex-col justify-center">
      <?php if ($forCheckout): ?>
      <p class="mb-6 max-w-md rounded-2xl bg-teal-50 text-teal px-4 py-3 text-[13.5px] flex gap-2.5"><?= store_icon('shopping-bag', 'shrink-0 mt-0.5') ?><span><b>Your bag is kept.</b> Sign in or create an account (takes a minute) and we’ll take you straight to checkout.</span></p>
      <?php endif; ?>
      <div role="tablist" class="flex gap-1 p-1 rounded-full bg-cream w-fit">
        <a href="<?= e($tabUrl('in')) ?>" role="tab" data-auth-tab="in" class="tab px-5 h-10 rounded-full text-[14px] font-semibold grid place-items-center" aria-selected="<?= $tabSel === 'in' ? 'true' : 'false' ?>">Sign in</a>
        <a href="<?= e($tabUrl('up')) ?>" role="tab" data-auth-tab="up" class="tab px-5 h-10 rounded-full text-[14px] font-semibold grid place-items-center" aria-selected="<?= $tabSel === 'up' ? 'true' : 'false' ?>">Create account</a>
      </div>

      <form data-auth data-auth-panel="in" method="post" action="<?= e((string) route('store.signin.post')) ?>" class="mt-8 space-y-4 max-w-md"<?= $show('in') ?>>
        <?= $hidden ?>
        <h1 class="font-display text-[36px] leading-tight">Welcome back</h1>
        <label class="block"><span class="lbl">Mobile number or email</span><input name="login" value="<?= e((string) old('login', '')) ?>" required maxlength="190" class="field" autocomplete="username" inputmode="email"></label>
        <label class="block"><span class="lbl flex justify-between">Password <a href="<?= e($tabUrl('forgot')) ?>" data-auth-go="forgot" class="text-teal font-semibold">Forgot?</a></span><input name="password" required type="password" maxlength="72" class="field" autocomplete="current-password"></label>
        <label class="flex items-center gap-2.5 text-[13.5px]"><input type="checkbox" name="remember" value="1" class="w-4 h-4 accent-[#1E6E74]"> Keep me signed in on this device</label>
        <button class="btn btn-dark w-full">Sign in</button>
        <p class="text-[13.5px] text-ink-500">New here? <a href="<?= e($tabUrl('up')) ?>" data-auth-tab="up" class="text-teal font-semibold">Create an account</a></p>
      </form>

      <form data-auth data-auth-panel="up" method="post" action="<?= e((string) route('store.register')) ?>" class="mt-8 space-y-4 max-w-md" novalidate<?= $show('up') ?>>
        <?= $hidden ?>
        <h1 class="font-display text-[36px] leading-tight">Create your account</h1>
        <label class="block"><span class="lbl">Full name</span><input name="name" value="<?= e((string) old('name', '')) ?>" required maxlength="120" class="field" autocomplete="name"<?= $inv('name') ?>><?= $err('name') ?></label>
        <label class="block"><span class="lbl">Mobile number</span>
          <span class="flex"><span class="h-11 px-3 grid place-items-center rounded-l-xl border border-r-0 border-ink/10 bg-cream text-[14px] text-ink-500">+91</span><input name="phone" value="<?= e((string) old('phone', '')) ?>" required inputmode="numeric" maxlength="14" class="field !rounded-l-none" autocomplete="tel-national"<?= $inv('phone') ?>></span>
          <?= $err('phone') ?><span class="text-[12px] text-ink-400 mt-1 block">Already bought from our showroom? Use the same number and we’ll link your orders.</span></label>
        <label class="block"><span class="lbl">Email <span class="text-ink-400 font-normal">(optional — needed to reset your password by email)</span></span><input type="email" name="email" value="<?= e((string) old('email', '')) ?>" maxlength="190" class="field" autocomplete="email"<?= $inv('email') ?>><?= $err('email') ?></label>
        <label class="block"><span class="lbl">Password <span class="text-ink-400 font-normal">(at least 8 characters)</span></span><input name="password" required type="password" minlength="8" maxlength="72" class="field" autocomplete="new-password"<?= $inv('password') ?>><?= $err('password') ?></label>
        <label class="flex items-start gap-2.5 text-[13.5px]"><input type="checkbox" name="marketing_consent" value="1"<?= old('marketing_consent') ? ' checked' : '' ?> class="mt-0.5 w-4 h-4 accent-[#1E6E74]"> Send me new arrivals and offers on WhatsApp</label>
        <label class="flex items-center gap-2.5 text-[13.5px]"><input type="checkbox" name="remember" value="1" class="w-4 h-4 accent-[#1E6E74]"> Keep me signed in on this device</label>
        <button class="btn btn-dark w-full">Create account</button>
        <p class="text-[13.5px] text-ink-500">Have an account? <a href="<?= e($tabUrl('in')) ?>" data-auth-tab="in" class="text-teal font-semibold">Sign in</a></p>
      </form>

      <form data-auth data-auth-panel="forgot" method="post" action="<?= e((string) route('store.signin.forgot')) ?>" class="mt-8 space-y-4 max-w-md"<?= $show('forgot') ?>>
        <?= $hidden ?>
        <h1 class="font-display text-[36px] leading-tight">Forgot your password?</h1>
        <?php if ($mailOn): ?>
        <p class="text-ink-500 text-[14.5px]">Enter the email on your account and we’ll send a 6-digit code to set a new password.</p>
        <label class="block"><span class="lbl">Email</span><input type="email" name="email" value="<?= e($resetEmail) ?>" required maxlength="190" class="field" autocomplete="email"></label>
        <button class="btn btn-dark w-full">Send code</button>
        <?php endif; ?>
        <div class="rounded-2xl bg-cream p-4 text-[13.5px]">
          <p><?= $mailOn ? 'No email on your account?' : 'We reset passwords by hand for now.' ?> Message us on WhatsApp from your registered mobile and we’ll reset it for you.</p>
          <?php if ($wa !== ''): ?><a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="mt-3 btn btn-line !h-10 !px-4 inline-flex"><?= store_icon('message-circle') ?> Message us on WhatsApp</a><?php endif; ?>
        </div>
        <p class="text-[13.5px]"><a href="<?= e($tabUrl('in')) ?>" data-auth-go="in" class="text-teal font-semibold">← Back to sign in</a></p>
      </form>

      <form data-auth data-auth-panel="reset" method="post" action="<?= e((string) route('store.signin.reset')) ?>" class="mt-8 space-y-4 max-w-md" novalidate<?= $show('reset') ?>>
        <?= $hidden ?>
        <h1 class="font-display text-[36px] leading-tight">Set a new password</h1>
        <p class="text-ink-500 text-[14.5px]">Enter the 6-digit code from your email. It works for <?= (int) \App\Services\Store\CustomerAuth::CODE_MINUTES ?> minutes.</p>
        <label class="block"><span class="lbl">Email</span><input type="email" name="email" value="<?= e($resetEmail ?: (string) old('email', '')) ?>" required maxlength="190" class="field" autocomplete="email"<?= $inv('email') ?>><?= $err('email') ?></label>
        <label class="block"><span class="lbl">6-digit code</span><input name="code" required inputmode="numeric" maxlength="6" pattern="\d{6}" class="field tracking-[.3em]" autocomplete="one-time-code"<?= $inv('code') ?>><?= $err('code') ?></label>
        <label class="block"><span class="lbl">New password <span class="text-ink-400 font-normal">(at least 8 characters)</span></span><input name="password" required type="password" minlength="8" maxlength="72" class="field" autocomplete="new-password"<?= $inv('password') ?>><?= $err('password') ?></label>
        <button class="btn btn-dark w-full">Change password and sign in</button>
        <p class="text-[13.5px] text-ink-500">No code? <a href="<?= e($tabUrl('forgot')) ?>" data-auth-go="forgot" class="text-teal font-semibold">Send it again</a></p>
      </form>
    </div>
  </div>
</div>
