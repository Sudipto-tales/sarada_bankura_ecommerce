<?php
/**
 * /order/{token} in the storefront look (design: html/storefront/order.html). Logic stays in
 * PublicOrderController; this only renders. The delivery OTP itself is never shown here (only
 * its hash is stored); the customer receives it on WhatsApp / email at dispatch.
 *
 * @var array $o @var list<array> $items @var list<array> $taxRows @var float $dueNow @var bool $canPay
 * @var ?array $lastRejected @var ?array $delivery @var array $shipping @var ?string $invoiceUrl @var ?string $invoiceNumber
 * @var array<string,string> $history @var list<array> $payments @var list<array> $returns @var ?string $placed
 */
use App\Services\Order\OrderService as OS;
use App\Services\Store\Purchasable;

$num = static fn($n): string => Purchasable::fmt((float) $n);
$when = static fn(?string $d): string => $d ? date('j M, g:i A', strtotime($d)) : '';
$st = (string) $o['order_status'];
$idx = array_search($st, OS::FLOW, true);
$steps = [
    'pending' => ['Order placed', 'We’re checking your payment', 'receipt'],
    'accepted' => ['Confirmed', 'Your stone is reserved for you', 'badge-check'],
    'processing' => ['Preparing', 'Cutting and polishing to size', 'hammer'],
    'packed' => ['Packed', 'Crated and ready to load', 'package'],
    'dispatched' => ['On the way', 'Your stone has left our yard', 'truck'],
    'delivered' => ['Delivered', 'Checked and signed with your OTP', 'house'],
];
$pending = (float) $o['pending_verification_amount'];
$due = (float) $o['due_amount'];
$waText = 'Hello, about my order ' . $o['order_number'];
$canPayNow = $st !== 'rejected' && $dueNow > 0 && $canPay;
$kinds = [
    'verified' => ['circle-check', 'text-teal', 'Confirmed'],
    'pending' => ['clock', 'text-gold', 'Under review'],
    'rejected' => ['circle-x', 'text-copper', 'Not received'],
    'refund' => ['undo-2', 'text-copper', 'Refunded'],
];
$retLabel = ['submitted' => 'We’re reviewing your request', 'approved' => 'Approved — we’ll collect the goods', 'received' => 'Goods received', 'resolved' => 'Completed', 'rejected' => 'Not accepted'];
?>
<div class="w-full max-w-[1500px] mx-auto px-4 sm:px-6 lg:px-10 pt-8">
  <?php if ($placed): ?>
  <div class="mb-8 rounded-[28px] bg-ink text-cream p-6 sm:p-8 flex flex-col sm:flex-row gap-5 sm:items-center" role="status">
    <span class="w-14 h-14 rounded-full bg-teal grid place-items-center shrink-0"><?= store_icon('check', '!w-7 !h-7') ?></span>
    <div class="flex-1"><h2 class="font-display text-[30px] leading-tight">Thank you — your order is placed</h2>
      <p class="text-cream/75 mt-1">We’re checking your payment<?= $pending > 0 ? ' of ' . e(store_inr($pending)) : '' ?>. You’ll hear from us on WhatsApp when it’s confirmed. Keep this page’s link to check your order any time.</p></div>
    <button type="button" class="btn bg-cream text-ink !h-11 shrink-0" data-copy-link hidden data-js-only><?= store_icon('link') ?> Copy link</button>
  </div>
  <?php endif; ?>

  <div class="flex flex-wrap items-end justify-between gap-4">
    <div><p class="eyebrow">Order</p><h1 class="font-display text-[40px] sm:text-[46px] leading-tight tracking-tight mt-1"><?= e((string) $o['order_number']) ?></h1>
      <p class="text-ink-500 text-[14px]">Placed <?= e($when((string) $o['created_at'])) ?> · <?= e((string) $o['customer_name']) ?></p></div>
    <div class="flex gap-2 no-print">
      <a href="<?= e(store_wa_link($waText)) ?>" class="btn btn-line !h-11" target="_blank" rel="noopener"><?= store_icon('message-circle') ?> Help</a>
      <button type="button" onclick="print()" class="btn btn-line !h-11"><?= store_icon('printer') ?> Print</button>
    </div>
  </div>

  <div class="mt-8 grid lg:grid-cols-[minmax(0,1fr),420px] gap-6 items-start">
    <div class="space-y-6 min-w-0">
      <section class="bg-white rounded-[24px] border border-ink/5 p-6 sm:p-8">
        <?php if ($st === 'rejected'): ?>
        <div class="flex items-start gap-4">
          <span class="w-12 h-12 rounded-full bg-copper-50 text-copper grid place-items-center shrink-0"><?= store_icon('circle-x', '!w-6 !h-6') ?></span>
          <div><p class="text-[12px] uppercase tracking-[.16em] text-copper font-semibold">Order not accepted</p><h2 class="font-display text-[28px] leading-tight mt-1">We couldn’t take this order</h2>
            <?php if ($o['customer_note']): ?><div class="mt-4 rounded-2xl bg-cream p-4 text-[14.5px] border-l-2 border-copper"><b class="text-[12.5px] block mb-1">Note from <?= e((string) business('name')) ?></b><?= nl2br(e((string) $o['customer_note'])) ?></div><?php endif; ?>
            <p class="text-[13.5px] text-ink-500 mt-3">Questions? <a href="<?= e(store_wa_link($waText)) ?>" class="text-teal font-semibold" target="_blank" rel="noopener">Message us on WhatsApp</a>.</p></div>
        </div>
        <?php else: $cur = $steps[$st] ?? $steps['pending']; ?>
        <p class="eyebrow">Status</p><h2 class="font-display text-[30px] leading-tight mt-1"><?= e($cur[0]) ?></h2><p class="text-ink-500 text-[14px]"><?= e($cur[1]) ?></p>
        <ol class="mt-8 grid grid-cols-6 gap-1">
          <?php foreach (OS::FLOW as $i => $s): $done = $idx !== false && $i < $idx; $now = $i === $idx; ?>
          <li class="text-center relative"<?= $now ? ' aria-current="step"' : '' ?>>
            <?php if ($i): ?><span class="absolute top-5 right-1/2 w-full h-0.5 <?= $idx !== false && $i <= $idx ? 'bg-teal' : 'bg-ink/10' ?>" aria-hidden="true"></span><?php endif; ?>
            <span class="relative mx-auto w-10 h-10 rounded-full grid place-items-center <?= $done ? 'bg-teal text-white' : ($now ? 'bg-ink text-cream ring-4 ring-teal/20' : 'bg-cream text-ink-300') ?>"><?= store_icon($done ? 'check' : $steps[$s][2]) ?></span>
            <p class="mt-2 text-[11px] sm:text-[13px] font-semibold leading-tight<?= $idx === false || $i > $idx ? ' text-ink-300' : '' ?>"><?= e($steps[$s][0]) ?></p>
            <p class="hidden sm:block text-[11.5px] text-ink-400"><?= e($idx !== false && $i <= $idx ? $when($history[$s] ?? null) : '') ?></p>
          </li>
          <?php endforeach; ?>
        </ol>
        <?php if ($delivery && $st === 'dispatched'): ?>
        <div class="mt-6 rounded-2xl bg-cream p-4 grid sm:grid-cols-3 gap-3 text-[13.5px]">
          <div><p class="text-ink-400 text-[12px]">Dispatched</p><b><?= e($when((string) $delivery['dispatch_date'])) ?></b></div>
          <?php if ($delivery['courier_name']): ?><div><p class="text-ink-400 text-[12px]">By</p><b><?= e((string) $delivery['courier_name']) ?><?= $delivery['tracking_number'] ? ' · #' . e((string) $delivery['tracking_number']) : '' ?></b></div><?php endif; ?>
          <?php if ($delivery['tracking_url']): ?><div><p class="text-ink-400 text-[12px]">Tracking</p><a class="font-semibold text-teal" href="<?= e((string) $delivery['tracking_url']) ?>" target="_blank" rel="noopener">Track shipment</a></div><?php endif; ?>
        </div>
        <p class="mt-3 text-[13px] text-ink-500 flex items-start gap-2"><?= store_icon('key-round', 'text-teal shrink-0 mt-0.5') ?> We sent you a delivery OTP. Share it with our delivery person only after the stone is unloaded and checked.</p>
        <?php endif; ?>
        <?php endif; ?>
      </section>

      <section class="bg-white rounded-[24px] border border-ink/5 p-6 sm:p-8">
        <h2 class="font-display text-[24px]">Items</h2>
        <ul class="mt-4 divide-y divide-ink/5">
          <?php foreach ($items as $it): ?>
          <li class="py-4 flex gap-4 items-center">
            <span class="relative shrink-0 w-16 h-16 rounded-xl overflow-hidden bg-cream"><?= store_img($it['image'], (string) $it['product_name'], 'absolute inset-0 w-full h-full object-cover') ?></span>
            <div class="flex-1 min-w-0">
              <?php if ($it['slug']): ?><a href="<?= e(store_url('/product/' . rawurlencode((string) $it['slug']))) ?>" class="font-semibold hover:text-teal"><?= e((string) $it['product_name']) ?></a><?php else: ?><b><?= e((string) $it['product_name']) ?></b><?php endif; ?>
              <p class="text-[12.5px] text-ink-400"><?= $it['variant_name'] ? e((string) $it['variant_name']) . ' · ' : '' ?>SKU <?= e((string) $it['sku']) ?></p>
              <p class="text-[13px] text-ink-500"><?= e($num($it['quantity'])) ?> <?= e((string) $it['unit']) ?> × <?= e(store_inr($it['unit_price'])) ?></p>
            </div>
            <p class="font-semibold whitespace-nowrap"><?= e(store_inr($it['line_total'])) ?></p>
          </li>
          <?php endforeach; ?>
        </ul>
      </section>

      <?php if ($returns): ?>
      <section class="bg-white rounded-[24px] border border-ink/5 p-6 sm:p-8">
        <h2 class="font-display text-[24px]">Returns &amp; replacements</h2>
        <ul class="mt-4 divide-y divide-ink/5 text-[14px]">
          <?php foreach ($returns as $rq): ?>
          <li class="py-3 flex flex-wrap justify-between gap-3">
            <div><b><?= $rq['request_type'] === 'return' ? 'Return' : 'Replacement' ?> · <?= e((string) $rq['request_number']) ?></b>
              <p class="text-[12.5px] text-ink-400"><?= e(implode(', ', array_map(fn ($ri) => $ri['product_name'] . ' × ' . $num($ri['received_quantity'] ?? $ri['approved_quantity'] ?? $ri['requested_quantity']) . ' ' . $ri['unit'], $rq['items']))) ?></p>
              <?php if ($rq['status'] === 'rejected' && $rq['admin_note']): ?><p class="text-[12.5px] text-ink-500"><?= e((string) preg_replace('/^.*Declined: /s', '', (string) $rq['admin_note'])) ?></p><?php endif; ?></div>
            <span class="font-semibold"><?= e($retLabel[$rq['status']] ?? (string) $rq['status']) ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <section class="grid sm:grid-cols-2 gap-6">
        <div class="bg-white rounded-[24px] border border-ink/5 p-6"><h2 class="font-semibold flex items-center gap-2"><?= store_icon('map-pin', 'text-teal') ?> Delivering to</h2>
          <?php if ($shipping): ?><address class="not-italic text-[14px] text-ink-500 mt-3 leading-relaxed"><b class="text-ink"><?= e((string) $shipping['name']) ?></b><br><?= implode('<br>', array_map('e', OS::addressLines($shipping))) ?></address>
          <?php else: ?><p class="text-[14px] text-ink-500 mt-3">Collect from our showroom: <?= e(store_address()) ?></p><?php endif; ?>
          <?php if (!empty($o['buyer_note'])): ?><p class="mt-3 text-[13px] text-ink-500 border-t border-ink/5 pt-3"><b class="text-ink">Your note:</b> <?= nl2br(e((string) $o['buyer_note'])) ?></p><?php endif; ?>
        </div>
        <div class="bg-white rounded-[24px] border border-ink/5 p-6"><h2 class="font-semibold flex items-center gap-2"><?= store_icon('file-text', 'text-teal') ?> Documents</h2>
          <ul class="mt-3 space-y-2 text-[14px]">
            <li class="flex items-center gap-2"><?= store_icon('file') ?><button type="button" onclick="print()" class="font-semibold hover:text-teal">Order summary</button></li>
            <li class="flex items-center gap-2<?= $invoiceUrl ? '' : ' text-ink-300' ?>"><?= store_icon('receipt-text') ?><?php if ($invoiceUrl): ?><a href="<?= e($invoiceUrl) ?>" class="font-semibold hover:text-teal">Invoice<?= $invoiceNumber ? ' · ' . e((string) $invoiceNumber) : '' ?></a><?php else: ?>Invoice <span class="text-[11.5px] ml-auto">At dispatch</span><?php endif; ?></li>
          </ul>
        </div>
      </section>
    </div>

    <aside class="space-y-6 lg:sticky lg:top-[92px]">
      <section class="bg-white rounded-[28px] border border-ink/5 p-6 sm:p-7">
        <h2 class="font-display text-[24px]">Payment</h2>
        <dl class="mt-4 space-y-2.5 text-[14.5px]">
          <div class="flex justify-between"><dt class="text-ink-500">Subtotal</dt><dd><?= e(store_inr($o['subtotal'])) ?></dd></div>
          <?php if ((float) $o['discount_amount'] > 0): ?><div class="flex justify-between text-teal"><dt>Discount</dt><dd>− <?= e(store_inr($o['discount_amount'])) ?></dd></div><?php endif; ?>
          <?php foreach ($taxRows as $t): ?><div class="flex justify-between"><dt class="text-ink-500"><?= e($t['label']) ?></dt><dd><?= e(store_inr($t['amount'])) ?></dd></div><?php endforeach; ?>
          <div class="flex justify-between"><dt class="text-ink-500">Delivery</dt><dd class="text-teal font-semibold">Included</dd></div>
          <div class="flex justify-between pt-3 border-t border-ink/10 font-semibold"><dt>Order total</dt><dd><?= e(store_inr($o['grand_total'])) ?></dd></div>
          <?php if ($st !== 'rejected'): ?>
          <div class="flex justify-between text-teal"><dt>Paid &amp; confirmed</dt><dd class="font-semibold"><?= e(store_inr($o['paid_amount'])) ?></dd></div>
          <?php if ($pending > 0): ?><div class="flex justify-between text-[#8a6a35]"><dt>Under review</dt><dd class="font-semibold"><?= e(store_inr($pending)) ?></dd></div><?php endif; ?>
          <div class="flex justify-between items-end pt-3 border-t border-ink/10"><dt class="font-semibold">Due</dt><dd class="font-display text-[30px] leading-none <?= $due > 0 ? 'text-copper' : 'text-teal' ?>"><?= $due > 0 ? e(store_inr($due)) : 'Nothing' ?></dd></div>
          <?php endif; ?>
        </dl>
        <?php if ($lastRejected && $st !== 'rejected'): ?><p class="mt-4 rounded-xl bg-copper-50 text-copper-600 px-4 py-3 text-[13px]">Your payment of <?= e(store_inr($lastRejected['amount'])) ?> could not be confirmed: <?= e((string) $lastRejected['reject_reason']) ?> Contact us if you think this is a mistake.</p><?php endif; ?>
        <?php if ((float) $o['refund_due'] > 0): ?><p class="mt-4 rounded-xl bg-cream px-4 py-3 text-[13px]"><?= e(store_inr($o['refund_due'])) ?> will be refunded to you. Our team will contact you.</p><?php endif; ?>
        <?php if ($canPayNow): ?>
        <button type="button" class="btn btn-copper w-full mt-5 no-print" data-pay hidden><?= store_icon('smartphone') ?> Pay <?= e(store_inr($dueNow)) ?> by UPI</button>
        <p class="text-[12px] text-ink-400 text-center mt-2">Pay any time before delivery. You get 6 minutes to pay and confirm with your payment ID.</p>
        <?php elseif ($st !== 'rejected' && $pending > 0 && $dueNow <= 0): ?>
        <p class="mt-4 text-[13px] text-ink-500">We received your payment details and are confirming them with our bank. Nothing more is due right now.</p>
        <?php endif; ?>
        <?php if ($payments): ?>
        <h3 class="mt-6 text-[12px] uppercase tracking-[.14em] text-ink-400 font-semibold">Payments</h3>
        <ul class="mt-2 space-y-2">
          <?php foreach ($payments as $p): [$icon, $tone, $label] = $kinds[$p['kind']]; ?>
          <li class="flex items-center gap-3 rounded-xl <?= $p['kind'] === 'refund' ? 'bg-copper-50' : 'bg-cream-50 border border-ink/5' ?> px-3.5 py-2.5 text-[13.5px]">
            <?= store_icon($icon, $tone . ' shrink-0') ?>
            <div class="flex-1 min-w-0 leading-tight"><b><?= e(store_inr($p['amount'])) ?></b> · <?= e($p['method']) ?><p class="text-[12px] text-ink-400 truncate"><?= e($p['ref']) ?><?= $p['reference'] ? ' · ' . e((string) $p['reference']) : '' ?> · <?= e($when($p['at'])) ?></p></div>
            <span class="text-[11.5px] font-bold <?= $tone ?>"><?= e($label) ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </section>

      <?php if ($st === 'delivered'): ?>
      <section class="bg-white rounded-[24px] border border-ink/5 p-6 no-print">
        <h2 class="font-semibold flex items-center gap-2"><?= store_icon('package-check', 'text-teal') ?> Something wrong?</h2>
        <p class="text-[13.5px] text-ink-500 mt-1">Report broken or damaged pieces soon after delivery — send us photos on WhatsApp and we’ll sort out a replacement.</p>
        <a href="<?= e(store_wa_link($waText . ': I need a replacement / return')) ?>" class="btn btn-line w-full mt-4 !h-11" target="_blank" rel="noopener"><?= store_icon('message-circle') ?> Report a problem</a>
        <?php foreach (array_slice(array_values(array_filter($items, fn ($i) => $i['slug'])), 0, 3) as $it): ?>
        <a href="<?= e((string) route('review.show', ['slug' => $it['slug']])) ?>" class="btn w-full !h-11 mt-2 text-teal"><?= store_icon('star') ?> Review <?= e((string) $it['product_name']) ?></a>
        <?php endforeach; ?>
      </section>
      <?php endif; ?>
    </aside>
  </div>
</div>
<?php if ($canPayNow): ?>
<?= store_partial('pay-window', ['start' => (string) route('pay.start', ['token' => $o['public_token']]), 'context' => 'order', 'amount' => store_inr($dueNow)]) ?>
<?php endif; ?>
