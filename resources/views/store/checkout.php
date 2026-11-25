<?php
/**
 * Checkout (design: html/storefront/checkout.html; docs/storefront.md §3.6). The summary is the
 * re-priced bag; "Place order" posts this form to /checkout/start through the payment window
 * (pay-window.js, checkout mode). The order is created only when the payment is submitted.
 *
 * @var list<array> $lines @var array $totals @var list<array> $tax_rows @var list<string> $states
 * @var string $home_state @var int|float $advance_percent @var bool $tax_included @var bool $can_pay
 * @var array<string,string> $prefill
 */
use App\Services\Order\DocumentService as Docs;
use App\Services\Store\Purchasable;

$num = static fn($n): string => Purchasable::fmt((float) $n);
$v = static fn(string $k): string => (string) old($k, $prefill[$k] ?? '');
$err = static fn(string $k): string => '<em class="block not-italic text-[12.5px] text-copper mt-1 empty:hidden" data-err-for="' . e($k) . '">' . e((string) (error($k) ?? '')) . '</em>';
$inv = static fn(string $k): string => error($k) !== null ? ' aria-invalid="true"' : '';
$rate = (float) $totals['tax_rate'];
$intra = Docs::taxRows('cgst_sgst', $rate ?: null, (float) $totals['tax']);
$inter = Docs::taxRows('igst', $rate ?: null, (float) $totals['tax']);
$state = $v('state') ?: '';
$plan = old('pay_choice', 'advance') === 'full' ? 'full' : 'advance';
$billSame = (string) old('billing_same', '1') !== '0';
$balance = round($totals['total'] - $totals['advance'], 2);
$stateSelect = static function (string $name, string $selected, string $id) use ($states, $inv): string {
    $h = '<select name="' . e($name) . '" id="' . e($id) . '" class="field" autocomplete="address-level1" required' . $inv($name) . '><option value="">Pick state</option>';
    foreach ($states as $s) {
        $h .= '<option' . (strcasecmp($s, $selected) === 0 ? ' selected' : '') . '>' . e($s) . '</option>';
    }

    return $h . '</select>';
};
?>
<div class="w-full max-w-[1500px] mx-auto px-4 sm:px-6 lg:px-10 pt-8">
  <a href="<?= e((string) route('store.cart')) ?>" class="inline-flex items-center gap-1.5 text-[13.5px] font-semibold text-ink-500 hover:text-ink"><?= store_icon('arrow-left') ?> Back to bag</a>
  <div class="mt-3 flex flex-wrap items-end justify-between gap-4">
    <h1 class="font-display text-[40px] sm:text-[46px] leading-tight tracking-tight">Checkout</h1>
    <ol class="flex items-center gap-2 text-[13px] text-ink-500" aria-label="Checkout steps">
      <li class="flex items-center gap-2"><span class="w-6 h-6 rounded-full bg-teal text-white grid place-items-center"><?= store_icon('check', '!w-3.5 !h-3.5') ?></span>Bag</li><li class="w-8 h-px bg-ink/20" aria-hidden="true"></li>
      <li class="flex items-center gap-2 text-ink font-semibold" aria-current="step"><span class="w-6 h-6 rounded-full bg-ink text-cream grid place-items-center text-[12px]">2</span>Details</li><li class="w-8 h-px bg-ink/20" aria-hidden="true"></li>
      <li class="flex items-center gap-2"><span class="w-6 h-6 rounded-full border border-ink/25 grid place-items-center text-[12px]">3</span>Pay by UPI</li>
    </ol>
  </div>

  <form id="co" method="post" action="<?= e((string) route('store.checkout.start')) ?>" class="mt-8 grid lg:grid-cols-[minmax(0,1fr),440px] gap-8 items-start" novalidate
    data-home-state="<?= e($home_state) ?>" data-pincode-url="<?= e((string) route('store.checkout.pincode', ['code' => '000000'])) ?>">
    <?= csrf_field() ?>
    <div class="space-y-5 min-w-0">

      <section class="bg-white rounded-[24px] border border-ink/5 p-6 sm:p-8">
        <h2 class="font-display text-[24px] flex items-center gap-3"><span class="w-8 h-8 rounded-full bg-ink text-cream text-[14px] font-sans font-semibold grid place-items-center">1</span>Your details</h2>
        <div class="mt-6 grid sm:grid-cols-2 gap-4">
          <label class="block"><span class="lbl">Mobile number <span class="text-copper">*</span></span>
            <span class="flex"><span class="h-11 px-3 grid place-items-center rounded-l-xl border border-r-0 border-ink/10 bg-cream text-[14px] text-ink-500">+91</span><input name="phone" id="phone" value="<?= e($v('phone')) ?>" required inputmode="numeric" maxlength="14" placeholder="10-digit mobile" class="field !rounded-l-none" autocomplete="tel-national"<?= $inv('phone') ?>></span>
            <?= $err('phone') ?><span class="text-[12px] text-ink-400 mt-1 block">We use this to find your past orders and to call before delivery.</span></label>
          <label class="block"><span class="lbl">Full name <span class="text-copper">*</span></span><input name="name" id="name" value="<?= e($v('name')) ?>" required maxlength="120" class="field" autocomplete="name"<?= $inv('name') ?>><?= $err('name') ?></label>
          <label class="block sm:col-span-2"><span class="lbl">Email <span class="text-ink-400 font-normal">(optional — for your invoice)</span></span><input type="email" name="email" value="<?= e($v('email')) ?>" maxlength="160" class="field" autocomplete="email"<?= $inv('email') ?>><?= $err('email') ?></label>
        </div>
      </section>

      <section class="bg-white rounded-[24px] border border-ink/5 p-6 sm:p-8">
        <h2 class="font-display text-[24px] flex items-center gap-3"><span class="w-8 h-8 rounded-full bg-ink text-cream text-[14px] font-sans font-semibold grid place-items-center">2</span>Delivery address</h2>
        <div class="mt-6 grid sm:grid-cols-6 gap-4">
          <label class="block sm:col-span-2"><span class="lbl">Pincode <span class="text-copper">*</span></span>
            <span class="relative block"><input name="pincode" id="pin" value="<?= e($v('pincode')) ?>" required inputmode="numeric" maxlength="6" placeholder="6 digits" class="field pr-9" autocomplete="postal-code"<?= $inv('pincode') ?>><span id="pinSpin" hidden class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 border-2 border-teal border-t-transparent rounded-full animate-spin"></span></span>
            <?= $err('pincode') ?><span id="pinMsg" class="text-[12px] text-ink-400 mt-1 block" aria-live="polite">We fill in the state and city for you.</span></label>
          <label class="block sm:col-span-2"><span class="lbl">State <span class="text-copper">*</span></span><?= $stateSelect('state', $state, 'state') ?><?= $err('state') ?></label>
          <label class="block sm:col-span-2"><span class="lbl">District / city <span class="text-copper">*</span></span><input name="city" id="city" value="<?= e($v('city')) ?>" required maxlength="120" class="field" autocomplete="address-level2"<?= $inv('city') ?>><?= $err('city') ?></label>
          <label class="block sm:col-span-3"><span class="lbl">Area / post office</span><input name="area" id="area" value="<?= e($v('area')) ?>" maxlength="120" class="field" list="areaList" autocomplete="address-level3"><datalist id="areaList"></datalist></label>
          <label class="block sm:col-span-3"><span class="lbl">Landmark <span class="text-ink-400 font-normal">(optional)</span></span><input name="landmark" value="<?= e($v('landmark')) ?>" maxlength="80" class="field" placeholder="Near temple, school…"></label>
          <label class="block sm:col-span-6"><span class="lbl">House / building, street <span class="text-copper">*</span></span><input name="line1" id="line1" value="<?= e($v('line1')) ?>" required maxlength="255" class="field" autocomplete="address-line1"<?= $inv('line1') ?>><?= $err('line1') ?></label>
          <label class="block sm:col-span-6"><span class="lbl">Road, village or locality</span><input name="line2" value="<?= e($v('line2')) ?>" maxlength="200" class="field" autocomplete="address-line2"></label>
        </div>
        <input type="hidden" name="billing_same" value="0">
        <label class="mt-4 flex items-center gap-2.5 cursor-pointer text-[14px]"><input type="checkbox" name="billing_same" value="1" id="sameBill"<?= $billSame ? ' checked' : '' ?> class="w-4 h-4 accent-[#1E6E74]"> Billing address is the same</label>
        <div id="billBox" class="mt-4 p-4 rounded-2xl bg-cream-50 border border-ink/5 grid sm:grid-cols-6 gap-4"<?= $billSame ? ' hidden' : '' ?>>
          <label class="block sm:col-span-6"><span class="lbl">Billing name <span class="text-ink-400 font-normal">(person or business)</span></span><input name="bill_name" value="<?= e($v('bill_name')) ?>" maxlength="120" class="field"></label>
          <label class="block sm:col-span-6"><span class="lbl">Billing address <span class="text-copper">*</span></span><input name="bill_line1" value="<?= e($v('bill_line1')) ?>" maxlength="255" class="field"<?= $inv('bill_line1') ?>><?= $err('bill_line1') ?></label>
          <label class="block sm:col-span-2"><span class="lbl">Pincode <span class="text-copper">*</span></span><input name="bill_pincode" value="<?= e($v('bill_pincode')) ?>" inputmode="numeric" maxlength="6" class="field"<?= $inv('bill_pincode') ?>><?= $err('bill_pincode') ?></label>
          <label class="block sm:col-span-2"><span class="lbl">State <span class="text-copper">*</span></span><?= $stateSelect('bill_state', $v('bill_state'), 'billState') ?><?= $err('bill_state') ?></label>
          <label class="block sm:col-span-2"><span class="lbl">City <span class="text-copper">*</span></span><input name="bill_city" value="<?= e($v('bill_city')) ?>" maxlength="120" class="field"<?= $inv('bill_city') ?>><?= $err('bill_city') ?></label>
        </div>
        <label class="block mt-5"><span class="lbl">Note for us <span class="text-ink-400 font-normal">(optional)</span></span><textarea name="buyer_note" rows="2" maxlength="<?= (int) \App\Services\Store\Checkout::NOTE_MAX ?>" class="field" placeholder="Best time to deliver, floor number, lift access, unloading help…"><?= e($v('buyer_note')) ?></textarea><?= $err('buyer_note') ?></label>
        <p class="mt-3 text-[12.5px] text-ink-500 flex items-center gap-1.5"><?= store_icon('truck', 'text-teal') ?> Delivery is included in the price. We’ll call to fix a date once your order is confirmed.</p>
      </section>

      <section class="bg-white rounded-[24px] border border-ink/5 p-6 sm:p-8">
        <h2 class="font-display text-[24px] flex items-center gap-3"><span class="w-8 h-8 rounded-full bg-ink text-cream text-[14px] font-sans font-semibold grid place-items-center">3</span>How much to pay now</h2>
        <div class="mt-6 grid sm:grid-cols-2 gap-3" id="plans">
          <label class="plan cursor-pointer rounded-2xl border border-ink/15 bg-cream-50 p-4 flex gap-3 transition"><input type="radio" name="pay_choice" value="advance"<?= $plan === 'advance' ? ' checked' : '' ?> class="mt-1 w-4 h-4 accent-[#15211F]"><span><span class="block font-semibold"><?= (int) $advance_percent ?>% advance</span><span class="block font-display text-[24px] mt-0.5"><?= e(store_inr($totals['advance'])) ?></span><span class="block text-[12.5px] text-ink-500 mt-0.5">Balance <?= e(store_inr($balance)) ?> before delivery. We’ll send you a payment link.</span></span></label>
          <label class="plan cursor-pointer rounded-2xl border border-ink/15 bg-cream-50 p-4 flex gap-3 transition"><input type="radio" name="pay_choice" value="full"<?= $plan === 'full' ? ' checked' : '' ?> class="mt-1 w-4 h-4 accent-[#15211F]"><span><span class="block font-semibold">Pay in full</span><span class="block font-display text-[24px] mt-0.5"><?= e(store_inr($totals['total'])) ?></span><span class="block text-[12.5px] text-ink-500 mt-0.5">Nothing left to pay. Fastest to dispatch.</span></span></label>
        </div>
        <div class="mt-5 flex items-center gap-3 rounded-2xl bg-cream p-4 text-[13.5px]"><span class="w-10 h-10 rounded-xl bg-white grid place-items-center text-teal shrink-0"><?= store_icon('scan-qr-code') ?></span><p><b>UPI only</b> — GPay, PhonePe, Paytm or any UPI app. Bank transfer details are shown in the next step for large amounts. We confirm every payment by hand.</p></div>
        <label class="mt-5 flex items-start gap-2.5 text-[14px] cursor-pointer"><input type="checkbox" name="terms" value="1" id="terms"<?= old('terms') ? ' checked' : '' ?> class="mt-0.5 w-4 h-4 accent-[#1E6E74]"><span>I agree to the <a href="<?= e(store_url('/policy')) ?>#terms" class="text-teal font-semibold" target="_blank">terms</a> and <a href="<?= e(store_url('/policy')) ?>#returns" class="text-teal font-semibold" target="_blank">returns policy</a>. I understand an order can’t be cancelled once it’s confirmed.</span></label>
        <?= $err('terms') ?>
        <p id="coErr" class="mt-4 text-[13.5px] text-copper bg-copper-50 rounded-xl px-4 py-3" role="alert"<?= error('cart') === null ? ' hidden' : '' ?>><?= e((string) (error('cart') ?? '')) ?></p>
        <?php if ($can_pay): ?>
        <button id="placeBtn" class="btn btn-copper w-full mt-5 !h-14 text-[16px]"><?= store_icon('lock-keyhole') ?> Place order &amp; pay <span data-paynow><?= e(store_inr($plan === 'full' ? $totals['total'] : $totals['advance'])) ?></span></button>
        <p class="mt-3 text-[12.5px] text-ink-400 text-center">A 6-minute payment window opens. Your order is created when you submit your payment details.</p>
        <noscript><p class="mt-3 text-[13px] text-copper text-center">Paying by UPI needs JavaScript. Turn it on, or <a class="font-semibold underline" href="<?= e(store_wa_link('Hello! I’d like to order the stones in my bag.')) ?>">order on WhatsApp</a>.</p></noscript>
        <?php else: ?>
        <p class="mt-5 rounded-xl bg-cream px-4 py-3 text-[14px]">Online payment isn’t set up yet. <a href="<?= e(store_wa_link('Hello! I’d like to order: ' . implode(', ', array_map(fn ($l) => $l['name'] . ($l['variant_title'] ? ' (' . $l['variant_title'] . ')' : '') . ' × ' . $num($l['qty']) . ' ' . $l['unit'], $lines)))) ?>" class="font-semibold text-teal" target="_blank" rel="noopener">Send this order on WhatsApp</a> and we’ll confirm it.</p>
        <?php endif; ?>
      </section>
    </div>

    <aside class="lg:sticky lg:top-6 bg-white rounded-[28px] border border-ink/5 p-6 sm:p-7" aria-label="Your order">
      <div class="flex items-center justify-between"><h2 class="font-display text-[24px]">Your order</h2><a href="<?= e((string) route('store.cart')) ?>" class="text-[13px] font-semibold text-teal">Edit</a></div>
      <ul class="mt-5 space-y-4">
        <?php foreach ($lines as $l): ?>
        <li class="flex gap-3">
          <span class="relative shrink-0 w-14 h-14 rounded-xl overflow-hidden bg-cream"><?= store_img($l['image'], $l['name'], 'absolute inset-0 w-full h-full object-cover') ?></span>
          <div class="min-w-0 flex-1"><p class="font-semibold leading-tight truncate"><?= e($l['name']) ?></p><p class="text-[12.5px] text-ink-400"><?= $l['variant_title'] ? e($l['variant_title']) . ' · ' : '' ?><?= e($num($l['qty'])) ?> <?= e($l['unit']) ?> × <?= e(store_inr($l['price'])) ?></p></div>
          <p class="font-semibold text-[14px] whitespace-nowrap"><?= e(store_inr($l['line_total'])) ?></p>
        </li>
        <?php endforeach; ?>
      </ul>
      <dl class="mt-6 pt-5 border-t border-ink/10 space-y-2.5 text-[14.5px]">
        <div class="flex justify-between"><dt class="text-ink-500">Subtotal</dt><dd><?= e(store_inr($totals['subtotal'])) ?></dd></div>
        <div class="flex justify-between"><dt class="text-ink-500">Delivery</dt><dd class="text-teal font-semibold">Included</dd></div>
        <?php if ($rate > 0): ?>
        <div data-tax="cgst_sgst"<?= Docs::taxMode(['gst_rate' => $rate, 'place_of_supply' => $state ?: $home_state]) === 'cgst_sgst' ? '' : ' hidden' ?> class="space-y-2.5">
          <?php foreach ($intra as $t): ?><div class="flex justify-between"><dt class="text-ink-500"><?= e($t['label']) ?></dt><dd><?= e(store_inr($t['amount'])) ?></dd></div><?php endforeach; ?>
        </div>
        <div data-tax="igst"<?= Docs::taxMode(['gst_rate' => $rate, 'place_of_supply' => $state ?: $home_state]) === 'igst' ? '' : ' hidden' ?> class="space-y-2.5">
          <?php foreach ($inter as $t): ?><div class="flex justify-between gap-3"><dt class="text-ink-500"><?= e($t['label']) ?> <span class="text-[12px] text-ink-400">(outside <?= e($home_state) ?>)</span></dt><dd><?= e(store_inr($t['amount'])) ?></dd></div><?php endforeach; ?>
        </div>
        <?php elseif ($tax_included): ?>
        <div class="flex justify-between"><dt class="text-ink-500">GST</dt><dd class="text-ink-500">Included in prices</dd></div>
        <?php endif; ?>
        <div class="flex justify-between items-end pt-3 border-t border-ink/10"><dt class="font-semibold text-[16px]">Total</dt><dd class="font-display text-[26px] leading-none"><?= e(store_inr($totals['total'])) ?></dd></div>
      </dl>
      <div class="mt-4 rounded-2xl bg-ink text-cream p-4 space-y-1.5">
        <div class="flex justify-between"><span>Pay now</span><b class="text-[18px]" data-paynow><?= e(store_inr($plan === 'full' ? $totals['total'] : $totals['advance'])) ?></b></div>
        <p class="flex justify-between text-cream/70 text-[13px]" data-plan-note="advance"<?= $plan === 'advance' ? '' : ' hidden' ?>><span>Balance — pay any time before delivery</span><span><?= e(store_inr($balance)) ?></span></p>
        <p class="text-cream/70 text-[13px]" data-plan-note="full"<?= $plan === 'full' ? '' : ' hidden' ?>>Nothing more to pay later.</p>
      </div>
      <script type="application/json" id="co-amounts"><?= store_json(['advance' => store_inr($totals['advance']), 'full' => store_inr($totals['total'])]) ?></script>
    </aside>
  </form>
</div>
<?php if ($can_pay): ?>
<?= store_partial('pay-window', [
    'start' => (string) route('store.checkout.start'), 'context' => 'checkout',
    'amount' => store_inr($plan === 'full' ? $totals['total'] : $totals['advance']),
    'mode' => 'checkout', 'form' => '#co', 'done' => (string) route('store.checkout.done'),
]) ?>
<?php endif; ?>
