<?php
/** Track an order / pay a balance (design: html/storefront/track.html; docs/storefront.md). */
$go = (string) old('go', '');
$num = static fn(string $for): string => $go === $for ? (string) old('order_number', '') : '';
?>
<div class="wrap pt-8">
  <div class="grid lg:grid-cols-2 gap-6 items-stretch">
    <section class="bg-white rounded-[28px] border border-ink/5 p-7 sm:p-10">
      <p class="eyebrow">Track order</p><h1 class="font-display text-[40px] leading-tight mt-1">Where’s my stone?</h1>
      <p class="text-ink-500 mt-2">Enter your order number and the mobile number you ordered with.</p>
      <form method="post" action="<?= e((string) route('store.track.find')) ?>" class="mt-6 space-y-4">
        <?= csrf_field() ?><input type="hidden" name="go" value="order">
        <label class="block"><span class="lbl">Order number</span><input name="order_number" value="<?= e($num('order')) ?>" required maxlength="40" class="field" placeholder="e.g. <?= e((string) business('numbering.order', 'MSO')) ?>/26-27/0142" autocapitalize="characters"></label>
        <label class="block"><span class="lbl">Mobile number</span><input name="phone" required inputmode="numeric" maxlength="14" class="field" placeholder="10-digit mobile" autocomplete="tel-national"></label>
        <button class="btn btn-dark w-full">Track order</button>
      </form>
      <p class="mt-5 text-[13.5px] text-ink-500">Your order page link is also in the WhatsApp message we sent you.</p>
    </section>
    <section id="pay" class="rounded-[28px] bg-ink text-cream p-7 sm:p-10 flex flex-col scroll-mt-28">
      <p class="text-[12px] uppercase tracking-[.2em] text-cream/60 font-semibold">Pay a balance</p><h2 class="font-display text-[40px] leading-tight mt-1">Pay what’s due</h2>
      <p class="text-cream/70 mt-2">Got a payment link from us? Open it — or look up your order here. The amount is worked out for you.</p>
      <form method="post" action="<?= e((string) route('store.track.find')) ?>" class="mt-6 space-y-4">
        <?= csrf_field() ?><input type="hidden" name="go" value="pay">
        <label class="block"><span class="lbl">Order number</span><input name="order_number" value="<?= e($num('pay')) ?>" required maxlength="40" class="field !bg-cream !text-ink" autocapitalize="characters"></label>
        <label class="block"><span class="lbl">Mobile number</span><input name="phone" required inputmode="numeric" maxlength="14" class="field !bg-cream !text-ink" autocomplete="tel-national"></label>
        <button class="btn btn-copper w-full">Find balance</button>
      </form>
      <div class="mt-auto pt-8 grid grid-cols-3 gap-3 text-[12.5px] text-cream/70">
        <span><?= store_icon('smartphone', 'text-[#E9B48F]') ?><br>UPI or bank transfer</span>
        <span><?= store_icon('shield-check', 'text-[#E9B48F]') ?><br>Checked by hand</span>
        <span><?= store_icon('receipt-text', 'text-[#E9B48F]') ?><br>Receipt for every payment</span>
      </div>
    </section>
  </div>
</div>
