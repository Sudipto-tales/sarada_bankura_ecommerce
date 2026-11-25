<?php
/**
 * Payment window in the storefront look (same hooks as documents/_pay-window.php, driven by
 * public/assets/js/pay-window.js). Used by /checkout, /order/{token} and /pay/{token}.
 *
 * @var string $start   start URL (pay.start or store.checkout.start)
 * @var string $context order | request | checkout
 * @var string $amount  amount shown before the window opens (₹…)
 * @var string|null $mode 'checkout' → posts $form, then $done
 * @var string|null $form CSS selector of the checkout form
 * @var string|null $done store.checkout.done URL
 */
$mode ??= null;
?>
<div class="fixed inset-0 z-[70] bg-ink/60 flex items-start justify-center overflow-auto px-3 py-6 sm:py-10" id="payWindow" hidden
  data-start="<?= e($start) ?>" data-context="<?= e($context) ?>" data-csrf="<?= e(csrf_token()) ?>"
  <?php if ($mode === 'checkout'): ?>data-mode="checkout" data-form="<?= e((string) $form) ?>" data-done="<?= e((string) $done) ?>"<?php endif; ?>>
  <div class="w-full max-w-[540px] bg-white rounded-[28px] shadow-card overflow-hidden" role="dialog" aria-modal="true" aria-labelledby="pwTitle">
    <header class="flex items-center gap-3 px-6 py-4 bg-ink text-cream">
      <div class="flex-1 min-w-0"><p class="text-[11.5px] uppercase tracking-[.16em] text-cream/60" data-pw-ref>Payment</p><h2 id="pwTitle" class="font-display text-[26px] leading-tight">Pay <span data-pw-amount><?= e($amount) ?></span></h2></div>
      <div class="pw-timer font-bold text-[18px] tabular-nums px-3 py-1.5 rounded-xl bg-white/10" data-pw-timer aria-live="off">06:00</div>
      <button type="button" class="w-9 h-9 grid place-items-center rounded-full hover:bg-white/10 text-[24px] leading-none" data-pw-close aria-label="Close">×</button>
    </header>

    <div class="p-6" data-pw-state="loading"><p class="text-ink-500 flex items-center gap-2"><span class="w-4 h-4 border-2 border-teal border-t-transparent rounded-full animate-spin"></span> Opening a secure payment window…</p></div>

    <div class="p-5 sm:p-6 space-y-4" data-pw-state="open" hidden>
      <p class="rounded-xl bg-[#FBEFD5] text-[#5c3d00] font-semibold text-[13px] px-4 py-2.5">Don’t close or refresh this window until you submit.</p>
      <section class="rounded-2xl border border-ink/10 p-4">
        <h3 class="flex items-center gap-2 font-semibold text-[14px]"><span class="w-6 h-6 rounded-full bg-ink text-cream grid place-items-center text-[12px]">1</span> Pay by UPI</h3>
        <div class="mt-3 flex flex-wrap gap-4 items-start">
          <div class="w-[168px] h-[168px] shrink-0 rounded-xl bg-cream overflow-hidden" data-pw-qr aria-label="UPI QR code"></div>
          <div class="flex-1 min-w-[180px] flex flex-col gap-1">
            <span class="text-[12px] text-ink-400">UPI ID</span>
            <div class="flex items-center gap-2 text-[15px] break-all"><b data-pw-vpa>—</b><button type="button" class="text-[12.5px] font-semibold text-teal underline underline-offset-2" data-pw-copy="vpa">Copy</button></div>
            <span class="text-[12.5px] text-ink-500" data-pw-payee></span>
            <a class="btn btn-dark !h-11 mt-2" data-pw-app href="#" hidden>Open UPI app</a>
            <p class="mt-2 text-[12px] text-ink-400">Ref <b class="text-ink" data-pw-refcode>—</b> — keep it in the payment note.</p>
          </div>
        </div>
        <details class="mt-3 text-[13px]" data-pw-bank hidden>
          <summary class="cursor-pointer font-semibold">Above ₹1 lakh? Bank transfer details</summary>
          <dl class="mt-3 grid grid-cols-[auto,1fr] gap-x-4 gap-y-1.5">
            <dt class="text-ink-400">Bank</dt><dd class="font-semibold" data-pw-b="bank"></dd>
            <dt class="text-ink-400">Account name</dt><dd class="font-semibold" data-pw-b="holder"></dd>
            <dt class="text-ink-400">Account no.</dt><dd class="font-semibold"><span data-pw-b="number"></span> <button type="button" class="text-[12px] text-teal underline" data-pw-copy="acct">Copy</button></dd>
            <dt class="text-ink-400">IFSC</dt><dd class="font-semibold" data-pw-b="ifsc"></dd>
          </dl>
          <p class="mt-2 text-ink-500">Then confirm below with your IMPS / NEFT reference.</p>
        </details>
      </section>
      <form class="rounded-2xl border border-ink/10 p-4 space-y-3" data-pw-form novalidate>
        <h3 class="flex items-center gap-2 font-semibold text-[14px]"><span class="w-6 h-6 rounded-full bg-ink text-cream grid place-items-center text-[12px]">2</span> Confirm your payment</h3>
        <label class="block"><span class="lbl">Name on your UPI / bank account <span class="text-copper">*</span></span><input name="payer_name" class="field" autocomplete="name" maxlength="120" required><em class="block not-italic text-[12.5px] text-copper mt-1 empty:hidden" data-err="payer_name"></em></label>
        <label class="block"><span class="lbl">Payment ID / UTR</span><input name="payer_reference" class="field" inputmode="text" maxlength="40" placeholder="12-digit number from your UPI app"><em class="block not-italic text-[12.5px] text-copper mt-1 empty:hidden" data-err="payer_reference"></em></label>
        <label class="block"><span class="lbl">— or — screenshot of the payment</span><input type="file" name="proof" accept="image/*,application/pdf" class="block w-full text-[13px] file:mr-3 file:rounded-full file:border-0 file:bg-cream file:px-4 file:py-2 file:font-semibold"><em class="block not-italic text-[12.5px] text-copper mt-1 empty:hidden" data-err="proof"></em></label>
        <button class="btn btn-copper w-full pw-submit disabled:opacity-60" type="submit">I have paid — Submit</button>
        <p class="text-[13px] text-copper" data-pw-error hidden></p>
      </form>
    </div>

    <div class="p-8 text-center" data-pw-state="done" hidden>
      <span class="w-14 h-14 mx-auto rounded-full bg-teal text-white grid place-items-center text-[26px] font-bold">✓</span>
      <h3 class="font-display text-[26px] mt-4">Thank you — payment submitted</h3>
      <p class="text-ink-500 mt-1">We’ll check it against our bank and confirm on WhatsApp. Your reference: <b class="text-ink" data-pw-done-ref></b>.</p>
      <?php if ($mode === 'checkout'): ?><p class="text-[13px] text-ink-400 mt-3">Opening your order…</p><?php endif; ?>
      <a class="btn btn-dark mt-5" data-pw-done-link href="#">View my order</a>
    </div>

    <div class="p-8 text-center" data-pw-state="expired" hidden>
      <span class="w-14 h-14 mx-auto rounded-full bg-copper-50 text-copper grid place-items-center text-[26px] font-bold">!</span>
      <h3 class="font-display text-[26px] mt-4">Time ran out</h3>
      <p class="text-ink-500 mt-1">If you already paid, <b class="text-ink">don’t pay again</b> — message us on WhatsApp with your payment ID.</p>
      <div class="mt-5 flex flex-wrap justify-center gap-2"><button type="button" class="btn btn-dark" data-pw-retry>Try again</button><a class="btn btn-line" data-pw-wa href="#" target="_blank" rel="noopener" hidden>WhatsApp us</a></div>
    </div>

    <div class="p-8 text-center" data-pw-state="error" hidden>
      <p class="text-[15px]" data-pw-msg></p>
      <button type="button" class="btn btn-line mt-5" data-pw-close>Close</button>
    </div>
  </div>
</div>
<script src="<?= e(store_asset('vendor/qrcode.min.js')) ?>" defer></script>
<script src="<?= e(store_asset('js/pay-window.js')) ?>" defer></script>
