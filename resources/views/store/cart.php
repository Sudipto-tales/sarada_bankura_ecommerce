<?php
/**
 * Bag (design: html/storefront/cart.html; docs/storefront.md). Server-rendered from
 * Cart::lines() (re-priced from the DB on every view). Every control is a form post; store.js
 * only adds "Save for later" to the local shortlist before the remove form submits.
 *
 * @var list<array> $lines @var array $totals @var int|float $advance_percent @var bool $tax_included
 * @var string|null $checkout @var list<array> $reco
 */
$num = static fn($n): string => \App\Services\Store\Purchasable::fmt((float) $n);
$step = ['Bag', 'Details', 'Pay by UPI'];
?>
<div class="wrap pt-8">
  <div class="flex flex-wrap items-end justify-between gap-4">
    <div><p class="eyebrow">Your bag</p><h1 class="font-display text-[40px] sm:text-[48px] leading-tight tracking-tight mt-1">Review your stones</h1></div>
    <a href="<?= e(store_url('/shop')) ?>" class="inline-flex items-center gap-1.5 text-[14px] font-semibold hover:text-teal"><?= store_icon('arrow-left') ?> Keep shopping</a>
  </div>

  <ol class="mt-6 flex items-center gap-2 text-[13px] text-ink-500" aria-label="Checkout steps">
    <?php foreach ($step as $i => $s): ?>
      <?php if ($i): ?><li class="w-8 h-px bg-ink/20" aria-hidden="true"></li><?php endif; ?>
      <li class="flex items-center gap-2<?= $i === 0 ? ' text-ink font-semibold' : '' ?>"<?= $i === 0 ? ' aria-current="step"' : '' ?>><span class="w-6 h-6 rounded-full <?= $i === 0 ? 'bg-ink text-cream' : 'border border-ink/25' ?> grid place-items-center text-[12px]"><?= $i + 1 ?></span><?= e($s) ?></li>
    <?php endforeach; ?>
  </ol>

  <?php if ($lines === []): ?>
  <div class="mt-8 bg-white rounded-[28px] border border-ink/5 p-12 text-center">
    <span class="w-16 h-16 mx-auto rounded-full bg-cream grid place-items-center"><?= store_icon('shopping-bag', '!w-7 !h-7') ?></span>
    <h2 class="font-display text-[30px] mt-5">Your bag is empty</h2>
    <p class="text-ink-500 mt-1">Find a stone for your floor, wall or kitchen.</p>
    <div class="mt-6 flex flex-wrap justify-center gap-2"><a href="<?= e(store_url('/shop')) ?>" class="btn btn-dark">Shop stone</a><a href="<?= e(store_url('/saved')) ?>" class="btn btn-line">View saved</a><a href="<?= e(store_url('/calculator')) ?>" class="btn btn-line">Area calculator</a></div>
  </div>
  <?php else: ?>
  <div class="mt-8 grid lg:grid-cols-[minmax(0,1fr),420px] gap-8 items-start">
    <div class="space-y-3">
      <?php foreach ($lines as $l): $id = (int) $l['line_id']; ?>
      <article class="bg-white rounded-[24px] border border-ink/5 p-4 sm:p-5 grid grid-cols-[88px,1fr] sm:grid-cols-[120px,1fr,auto] gap-4 sm:gap-6 items-center<?= $l['line_ok'] ? '' : ' opacity-80' ?>">
        <a href="<?= e(store_url($l['url'])) ?>" class="row-span-2 sm:row-span-1 relative block aspect-square rounded-2xl overflow-hidden bg-cream<?= $l['line_ok'] ? '' : ' grayscale' ?>" tabindex="-1" aria-hidden="true"><?= store_img($l['image'], $l['name'], 'absolute inset-0 w-full h-full object-cover') ?></a>
        <div class="min-w-0">
          <?php if ($l['origin'] !== ''): ?><p class="text-[11px] uppercase tracking-[.12em] text-ink-400"><?= e($l['origin']) ?></p><?php endif; ?>
          <h2 class="font-display text-[22px] leading-tight"><a href="<?= e(store_url($l['url'])) ?>" class="hover:text-teal"><?= e($l['name']) ?></a></h2>
          <?php if ($l['variant_title']): ?><p class="mt-1"><span class="text-[12px] bg-cream rounded-md px-2 py-0.5"><?= e($l['variant_title']) ?></span></p><?php endif; ?>
          <?php if ($l['line_ok']): ?>
            <p class="text-[12.5px] text-ink-400 mt-1.5">SKU <?= e($l['sku']) ?> · <?= e(store_inr($l['price'])) ?> / <?= e($l['unit']) ?><?php if ($l['compare']): ?> <del class="text-ink-300"><?= e(store_inr($l['compare'])) ?></del><?php endif; ?><?php if ($l['pack_qty'] && $l['pack_unit']): ?> · <?= e($num($l['qty'])) ?> <?= e($l['unit']) ?> = <?= e($num($l['qty'] * $l['pack_qty'])) ?> <?= e($l['pack_unit']) ?><?php endif; ?></p>
            <?php if ($l['price_changed'] !== null): ?><p class="text-[12.5px] text-copper mt-1 flex items-center gap-1"><?= store_icon('info') ?> Price <?= $l['price'] > $l['price_changed'] ? 'went up' : 'dropped' ?> from <?= e(store_inr($l['price_changed'])) ?> since you added it</p><?php endif; ?>
            <?php if ($l['stock'] === 'low'): ?><p class="text-[12.5px] text-copper mt-1 flex items-center gap-1"><?= store_icon('flame') ?> Few left in this lot</p>
            <?php elseif ($l['stock'] === 'out'): ?><p class="text-[12.5px] text-ink-500 mt-1 flex items-center gap-1"><?= store_icon('clock') ?> Made to order — we’ll confirm the dispatch date</p><?php endif; ?>
          <?php else: ?>
            <p class="text-[13px] text-copper mt-1.5 flex items-center gap-1" role="alert"><?= store_icon('alert-circle') ?> <?= e((string) $l['line_reason']) ?></p>
          <?php endif; ?>
        </div>
        <div class="col-start-2 sm:col-start-3 flex sm:flex-col items-center sm:items-end justify-between gap-3">
          <?php if ($l['line_ok'] || $l['ok']): ?>
          <form method="post" action="<?= e((string) route('store.cart.update')) ?>" class="flex items-center gap-2" data-cart-qty>
            <?= csrf_field() ?><input type="hidden" name="line" value="<?= $id ?>">
            <div class="flex items-center h-10 rounded-full border border-ink/15 bg-cream-50">
              <button type="button" class="w-10 h-10 grid place-items-center" data-step="-<?= e($num($l['step'])) ?>" aria-label="Less"><?= store_icon('minus') ?></button>
              <input name="qty" value="<?= e($num($l['qty'])) ?>" type="number" min="<?= e($num($l['min_qty'] ?? $l['step'])) ?>" step="<?= e($num($l['step'])) ?>" inputmode="decimal" class="w-16 text-center bg-transparent font-semibold outline-none" aria-label="Quantity of <?= e($l['name']) ?> in <?= e($l['unit']) ?>">
              <button type="button" class="w-10 h-10 grid place-items-center" data-step="<?= e($num($l['step'])) ?>" aria-label="More"><?= store_icon('plus') ?></button>
            </div>
            <button class="text-[12.5px] font-semibold text-teal" data-js-hide>Update</button>
          </form>
          <span class="text-[11.5px] text-ink-400 -mt-1 hidden sm:block"><?= e($l['unit']) ?><?= $l['min_qty'] ? ' · min ' . e($num($l['min_qty'])) : '' ?></span>
          <?php endif; ?>
          <p class="font-display text-[22px]"><?= $l['line_ok'] ? e(store_inr($l['line_total'])) : '—' ?></p>
          <div class="flex gap-3 text-[12.5px] font-semibold">
            <form method="post" action="<?= e((string) route('store.cart.remove')) ?>"><?= csrf_field() ?><input type="hidden" name="line" value="<?= $id ?>"><input type="hidden" name="later" value="1"><button class="text-ink-500 hover:text-teal" data-later="<?= e($l['slug']) ?>">Save for later</button></form>
            <form method="post" action="<?= e((string) route('store.cart.remove')) ?>"><?= csrf_field() ?><input type="hidden" name="line" value="<?= $id ?>"><button class="text-ink-500 hover:text-copper">Remove</button></form>
          </div>
        </div>
      </article>
      <?php endforeach; ?>

      <?php if ($totals['coverage'] > 0): ?>
      <div class="bg-teal-50 rounded-2xl px-5 py-4 flex flex-wrap items-center gap-3 text-[14px]"><?= store_icon('ruler', 'text-teal') ?><span>Your bag covers about <b><?= e(number_format($totals['coverage'], 0, '.', ',')) ?> sq ft</b>. Not sure it’s enough?</span><a href="<?= e(store_url('/calculator')) ?>" class="font-semibold text-teal ml-auto">Check with the calculator →</a></div>
      <?php endif; ?>
    </div>

    <aside class="lg:sticky lg:top-[92px] bg-white rounded-[28px] border border-ink/5 p-6 sm:p-7" aria-label="Order summary">
      <h2 class="font-display text-[26px]">Order summary</h2>
      <dl class="mt-5 space-y-3 text-[14.5px]">
        <div class="flex justify-between"><dt class="text-ink-500">Subtotal (<?= (int) $totals['count'] ?> item<?= $totals['count'] === 1 ? '' : 's' ?>)</dt><dd class="font-semibold"><?= e(store_inr($totals['subtotal'])) ?></dd></div>
        <?php if ($totals['savings'] > 0): ?><div class="flex justify-between text-teal"><dt>You save vs. list price</dt><dd class="font-semibold"><?= e(store_inr($totals['savings'])) ?></dd></div><?php endif; ?>
        <div class="flex justify-between"><dt class="text-ink-500">Delivery</dt><dd class="font-semibold text-teal">Included</dd></div>
        <?php if ($totals['tax_rate'] > 0): ?>
        <div class="flex justify-between gap-3"><dt class="text-ink-500">GST <?= e($num($totals['tax_rate'])) ?>% <span class="text-[12px] text-ink-400">(CGST + SGST in West Bengal)</span></dt><dd class="font-semibold"><?= e(store_inr($totals['tax'])) ?></dd></div>
        <?php elseif ($tax_included): ?>
        <div class="flex justify-between"><dt class="text-ink-500">GST</dt><dd class="text-ink-500">Included in prices</dd></div>
        <?php endif; ?>
        <div class="flex justify-between items-end pt-3 border-t border-ink/10 text-[17px]"><dt class="font-semibold">Total</dt><dd class="font-display text-[26px] leading-none"><?= e(store_inr($totals['total'])) ?></dd></div>
      </dl>
      <?php if ($totals['blocked'] > 0): ?>
      <p class="mt-4 text-[13px] text-copper flex gap-2" role="alert"><?= store_icon('alert-circle', 'shrink-0 mt-0.5') ?> <?= (int) $totals['blocked'] ?> line<?= $totals['blocked'] === 1 ? ' can’t' : 's can’t' ?> be bought right now and <?= $totals['blocked'] === 1 ? 'is' : 'are' ?> left out of the total. Remove <?= $totals['blocked'] === 1 ? 'it' : 'them' ?> to continue.</p>
      <?php endif; ?>
      <?php if ($totals['total'] > 0): ?>
      <div class="mt-5 rounded-2xl bg-cream p-4 text-[13.5px]">
        <p class="font-semibold flex items-center gap-2"><?= store_icon('smartphone', 'text-teal') ?> Pay by UPI at checkout</p>
        <div class="mt-3 grid grid-cols-2 gap-2"><div class="bg-white rounded-xl p-3"><p class="text-ink-400 text-[12px]"><?= (int) $advance_percent ?>% advance</p><p class="font-bold"><?= e(store_inr($totals['advance'])) ?></p></div><div class="bg-white rounded-xl p-3"><p class="text-ink-400 text-[12px]">Or pay in full</p><p class="font-bold"><?= e(store_inr($totals['total'])) ?></p></div></div>
      </div>
      <?php endif; ?>
      <?php if ($checkout !== null && $totals['blocked'] === 0 && $totals['count'] > 0): ?>
        <a href="<?= e($checkout) ?>" class="btn btn-copper w-full mt-5">Continue to checkout <?= store_icon('arrow-right') ?></a>
      <?php elseif ($checkout === null): ?>
        <a href="<?= e(store_wa_link('Hello! I’d like to order: ' . implode(', ', array_map(fn ($l) => $l['name'] . ($l['variant_title'] ? ' (' . $l['variant_title'] . ')' : '') . ' × ' . $num($l['qty']) . ' ' . $l['unit'], array_filter($lines, fn ($l) => $l['line_ok']))))) ?>" class="btn btn-copper w-full mt-5" target="_blank" rel="noopener"><?= store_icon('message-circle') ?> Order this bag on WhatsApp</a>
        <p class="mt-3 text-[12px] text-ink-400 text-center">Online checkout opens soon — your bag is kept for <?= (int) \App\Services\Store\Cart::COOKIE_DAYS ?> days on this device.</p>
      <?php else: ?>
        <button class="btn btn-copper w-full mt-5" disabled>Continue to checkout</button>
      <?php endif; ?>
      <?php if ($checkout !== null): ?><p class="mt-3 text-[12px] text-ink-400 text-center">Your order is placed only after you submit your payment details.</p><?php endif; ?>
      <div class="mt-5 pt-5 border-t border-ink/10 grid grid-cols-3 text-center text-[11.5px] text-ink-500 gap-2"><span><?= store_icon('shield-check', 'mx-auto text-teal') ?>Checked by hand</span><span><?= store_icon('receipt-text', 'mx-auto text-teal') ?>GST invoice</span><span><?= store_icon('package-check', 'mx-auto text-teal') ?>Breakage replaced</span></div>
    </aside>
  </div>
  <?php endif; ?>

  <?php if ($reco !== []): ?>
  <section class="mt-20">
    <div class="mb-6"><p class="eyebrow">Finish the job</p><h2 class="font-display text-[34px] leading-tight mt-1">Often bought together</h2></div>
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-6 gap-3 sm:gap-5">
      <?php foreach ($reco as $c): ?><?= store_card($c) ?><?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
</div>
