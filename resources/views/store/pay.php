<?php
/**
 * /pay/{token} — the payment-request link, in the storefront look (design: html/storefront/pay.html).
 * The 6-minute timer starts only on "Pay now". Logic stays in PayController.
 *
 * @var array $o @var list<array> $items @var list<array> $taxRows @var float $dueNow @var bool $canPay @var ?array $lastRejected
 */
use App\Services\Store\Purchasable;

$num = static fn($n): string => Purchasable::fmt((float) $n);
$st = (string) $o['order_status'];
$pending = (float) $o['pending_verification_amount'];
$first = trim(explode(' ', trim((string) $o['customer_name']))[0] ?? '');
$canPayNow = $st !== 'rejected' && $dueNow > 0 && $canPay;
?>
<div class="w-full max-w-[640px] mx-auto px-4 pt-10">
  <div class="bg-white rounded-[28px] border border-ink/5 overflow-hidden">
    <div class="bg-ink text-cream p-7">
      <p class="text-[12px] uppercase tracking-[.16em] text-cream/60">Payment</p>
      <h1 class="font-display text-[32px] sm:text-[34px] leading-tight mt-1"><?= $first !== '' ? 'Hi ' . e($first) . ', ' : '' ?><?= $st === 'rejected' ? 'no payment needed' : ($dueNow > 0 ? 'here’s what’s due' : 'you’re all set') ?></h1>
      <p class="text-cream/70 mt-1 text-[14px]">Order <b class="text-cream"><?= e((string) $o['order_number']) ?></b> · placed <?= e(date('j M Y', strtotime((string) $o['created_at']))) ?></p>
    </div>
    <div class="p-7">
      <?php if ($st === 'rejected'): ?>
        <p class="rounded-2xl bg-cream p-4 text-[14.5px] border-l-2 border-copper">This order was not accepted. <?= $o['customer_note'] ? nl2br(e((string) $o['customer_note'])) : '' ?></p>
      <?php else: ?>
      <ul class="divide-y divide-ink/5">
        <?php foreach ($items as $it): ?>
        <li class="py-3 flex gap-3 items-center">
          <span class="relative shrink-0 w-12 h-12 rounded-xl overflow-hidden bg-cream"><?= store_img($it['image'], (string) $it['product_name'], 'absolute inset-0 w-full h-full object-cover') ?></span>
          <div class="flex-1 min-w-0 text-[14px] leading-tight"><b><?= e((string) $it['product_name']) ?></b><br><span class="text-ink-400 text-[12.5px]"><?= $it['variant_name'] ? e((string) $it['variant_name']) . ' · ' : '' ?><?= e($num($it['quantity'])) ?> <?= e((string) $it['unit']) ?></span></div>
          <span class="font-semibold text-[14px] whitespace-nowrap"><?= e(store_inr($it['line_total'])) ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
      <dl class="mt-4 pt-4 border-t border-ink/10 space-y-2 text-[14.5px]">
        <?php if ((float) $o['discount_amount'] > 0): ?><div class="flex justify-between text-teal"><dt>Discount</dt><dd>− <?= e(store_inr($o['discount_amount'])) ?></dd></div><?php endif; ?>
        <?php foreach ($taxRows as $t): ?><div class="flex justify-between"><dt class="text-ink-500"><?= e($t['label']) ?></dt><dd><?= e(store_inr($t['amount'])) ?></dd></div><?php endforeach; ?>
        <div class="flex justify-between"><dt class="text-ink-500">Order total</dt><dd><?= e(store_inr($o['grand_total'])) ?></dd></div>
        <div class="flex justify-between text-teal"><dt>Paid</dt><dd>− <?= e(store_inr($o['paid_amount'])) ?></dd></div>
        <?php if ($pending > 0): ?><div class="flex justify-between text-[#8a6a35]"><dt>Under review</dt><dd>− <?= e(store_inr($pending)) ?></dd></div><?php endif; ?>
        <div class="flex justify-between items-end pt-3 border-t border-ink/10"><dt class="font-semibold">Pay now</dt><dd class="font-display text-[34px] leading-none"><?= e(store_inr(max(0, $dueNow))) ?></dd></div>
      </dl>
      <?php if ($lastRejected): ?><p class="mt-4 rounded-xl bg-copper-50 text-copper-600 px-4 py-3 text-[13px]">Your last payment of <?= e(store_inr($lastRejected['amount'])) ?> could not be confirmed: <?= e((string) $lastRejected['reject_reason']) ?></p><?php endif; ?>
      <?php if ($canPayNow): ?>
        <button type="button" class="btn btn-copper w-full mt-6 !h-14 text-[16px]" data-pay hidden><?= store_icon('smartphone') ?> Pay now <?= e(store_inr($dueNow)) ?></button>
        <noscript><p class="mt-4 text-[13px] text-copper text-center">Paying here needs JavaScript. Turn it on, or message us on WhatsApp.</p></noscript>
      <?php elseif ($dueNow > 0): ?>
        <p class="mt-6 rounded-xl bg-cream px-4 py-3 text-[14px]">Online payment isn’t set up yet. Please call or WhatsApp us to pay.</p>
      <?php elseif ($pending > 0): ?>
        <p class="mt-6 text-center text-ink-500">We received your payment details for <?= e(store_inr($pending)) ?> and are confirming them with our bank. Nothing more is due right now.</p>
      <?php else: ?>
        <p class="mt-6 text-center text-teal font-semibold">Nothing to pay right now — thank you!</p>
      <?php endif; ?>
      <?php endif; ?>
      <div class="mt-6 flex flex-wrap justify-center gap-2">
        <a class="btn btn-line !h-11" href="<?= e((string) route('order.public', ['token' => $o['public_token']])) ?>">View order status</a>
        <a class="btn btn-line !h-11" href="<?= e(store_wa_link('Hello, about payment for order ' . $o['order_number'])) ?>" target="_blank" rel="noopener"><?= store_icon('message-circle') ?> WhatsApp us</a>
      </div>
    </div>
  </div>
  <?php if ($canPayNow): ?><p class="text-center text-[12.5px] text-ink-400 mt-5">The 6-minute timer starts only when you press Pay now. UPI or bank transfer, checked by hand.</p><?php endif; ?>
</div>
<?php if ($canPayNow): ?>
<?= store_partial('pay-window', ['start' => (string) route('pay.start', ['token' => $o['public_token']]), 'context' => 'request', 'amount' => store_inr($dueNow)]) ?>
<?php endif; ?>
