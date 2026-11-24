<?php
/**
 * Area & cost calculator (design: html/storefront/calculator.html; docs/storefront.md §3.9).
 * The page lists buyable stones (Calculator::stones()); store.js does the room maths and fills the
 * hidden product_id / variant_id / qty of the "Add to bag" form, which /cart/add validates again.
 *
 * @var list<array> $stones @var float $tax_rate @var int|float $advance_percent
 */
$first = $stones[0] ?? null;
$num = static fn($n): string => \App\Services\Store\Purchasable::fmt((float) $n);
?>
<div class="wrap pt-8">
  <p class="eyebrow">Plan your space</p>
  <h1 class="font-display text-[40px] sm:text-[48px] leading-tight tracking-tight mt-1">Area &amp; cost calculator</h1>
  <p class="text-ink-500 mt-2 max-w-2xl">Add your rooms, pick a stone and we’ll work out how much to order — including cutting waste, boxes<?= $tax_rate > 0 ? ' and GST' : '' ?>.</p>
  <noscript><p class="mt-4 rounded-2xl bg-copper-50 text-copper px-4 py-3 text-[14px]">The calculator needs JavaScript. You can still pick a stone on the <a class="underline font-semibold" href="<?= e(store_url('/shop')) ?>">shop page</a> and enter your quantity there.</p></noscript>

  <?php if ($first === null): ?>
  <div class="mt-8 bg-white rounded-[28px] border border-ink/5 p-12 text-center">
    <h2 class="font-display text-[28px]">No stones with online prices yet</h2>
    <p class="text-ink-500 mt-1">Send us your room sizes and we’ll quote.</p>
    <a href="<?= e(store_url('/contact')) ?>" class="btn btn-dark mt-6">Ask for a quote</a>
  </div>
  <?php else: ?>
  <div class="mt-8 grid lg:grid-cols-[minmax(0,1fr),420px] gap-6 items-start" id="calc">
    <div class="space-y-6 min-w-0">
      <section class="bg-white rounded-[24px] border border-ink/5 p-5 sm:p-8">
        <div class="flex items-center justify-between gap-3"><h2 class="font-display text-[24px]">1 · Your rooms</h2><button type="button" id="addRoom" class="btn btn-line !h-10 !px-4 text-[13.5px]"><?= store_icon('plus') ?> Add room</button></div>
        <div id="rooms" class="mt-5 space-y-3">
          <?php foreach ([['Living room', 18, 14, 0], ['Dining', 12, 10, 6]] as [$n, $l, $w, $m]): ?>
          <div class="grid grid-cols-2 sm:grid-cols-[1fr,90px,90px,100px,auto] gap-2 items-end" data-room>
            <label class="col-span-2 sm:col-span-1"><span class="text-[12px] text-ink-500">Room</span><input class="field !h-10" value="<?= e($n) ?>" data-k="name" maxlength="40"></label>
            <label><span class="text-[12px] text-ink-500">Length ft</span><input type="number" min="0" step="0.1" inputmode="decimal" class="field !h-10" value="<?= $l ?>" data-k="l"></label>
            <label><span class="text-[12px] text-ink-500">Width ft</span><input type="number" min="0" step="0.1" inputmode="decimal" class="field !h-10" value="<?= $w ?>" data-k="w"></label>
            <label title="Cupboards, kitchen platform, etc."><span class="text-[12px] text-ink-500">Minus sq ft</span><input type="number" min="0" step="0.1" inputmode="decimal" class="field !h-10" value="<?= $m ?>" data-k="minus"></label>
            <button type="button" class="w-10 h-10 rounded-full hover:bg-cream grid place-items-center text-ink-400" data-del aria-label="Remove room"><?= store_icon('trash-2') ?></button>
          </div>
          <?php endforeach; ?>
        </div>
        <label class="mt-5 flex flex-wrap items-center gap-3 text-[14px]">Extra for cutting &amp; breakage
          <select id="waste" class="field !w-36 !h-10"><option value="5">5%</option><option value="8" selected>8%</option><option value="10">10%</option><option value="15">15% (diagonal)</option></select></label>
      </section>

      <section class="bg-white rounded-[24px] border border-ink/5 p-5 sm:p-8">
        <h2 class="font-display text-[24px]">2 · Choose a stone</h2>
        <fieldset class="mt-5 grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-3" id="stones">
          <legend class="sr-only">Stone</legend>
          <?php foreach ($stones as $i => $s): ?>
          <label class="cursor-pointer">
            <input type="radio" name="stone" value="<?= (int) $s['id'] ?>" class="peer sr-only"<?= $i === 0 ? ' checked' : '' ?>>
            <span class="block text-left rounded-2xl border border-ink/10 p-2 hover:border-ink/40 peer-checked:border-ink peer-checked:ring-1 peer-checked:ring-ink peer-checked:bg-cream-50 peer-focus-visible:ring-2 peer-focus-visible:ring-teal">
              <span class="relative block w-full aspect-[4/3] rounded-xl overflow-hidden bg-cream"><?= store_img($s['image'], $s['name'], 'absolute inset-0 w-full h-full object-cover') ?></span>
              <span class="block text-[13.5px] font-semibold mt-2 leading-tight"><?= e($s['name']) ?></span>
              <span class="block text-[12px] text-ink-400"><?= count($s['options']) > 1 ? 'from ' : '' ?><?= e(store_inr($s['from'])) ?>/<?= e($s['options'][0]['unit']) ?></span>
            </span>
          </label>
          <?php endforeach; ?>
        </fieldset>
        <div id="opts" class="mt-5" hidden>
          <label for="opt" class="lbl">Size / finish</label>
          <select id="opt" class="field"></select>
        </div>
      </section>
    </div>

    <aside class="lg:sticky lg:top-[92px] rounded-[28px] bg-ink text-cream p-6 sm:p-7" aria-live="polite">
      <p class="text-[12px] uppercase tracking-[.16em] text-cream/60">Your estimate</p>
      <h2 class="font-display text-[26px] leading-tight mt-1" data-out="name"><?= e($first['name']) ?></h2>
      <p class="text-cream/60 text-[13px]" data-out="rate"></p>
      <dl class="mt-5 space-y-2.5 text-[14px]">
        <div class="flex justify-between"><dt class="text-cream/70">Floor area</dt><dd data-out="area">—</dd></div>
        <div class="flex justify-between"><dt class="text-cream/70">+ <span data-out="waste">8</span>% extra</dt><dd data-out="need">—</dd></div>
        <div class="flex justify-between font-semibold"><dt>Order</dt><dd data-out="order">—</dd></div>
        <div class="flex justify-between pt-3 border-t border-cream/10"><dt class="text-cream/70">Stone</dt><dd data-out="sub">—</dd></div>
        <?php if ($tax_rate > 0): ?><div class="flex justify-between"><dt class="text-cream/70">GST <?= e($num($tax_rate)) ?>%</dt><dd data-out="gst">—</dd></div><?php endif; ?>
        <div class="flex justify-between"><dt class="text-cream/70">Delivery</dt><dd class="text-[#9FD3D6]">Included</dd></div>
        <div class="flex justify-between items-end pt-3 border-t border-cream/10"><dt class="font-semibold">Total</dt><dd class="font-display text-[34px] leading-none" data-out="total">—</dd></div>
        <div class="flex justify-between text-[#E9B48F]"><dt><?= (int) $advance_percent ?>% advance</dt><dd class="font-semibold" data-out="advance">—</dd></div>
      </dl>
      <form method="post" action="<?= e((string) route('store.cart.add')) ?>" id="calcForm" class="mt-6">
        <?= csrf_field() ?>
        <input type="hidden" name="product_id" value="<?= (int) $first['id'] ?>">
        <input type="hidden" name="variant_id" value="<?= (int) $first['options'][0]['id'] ?>">
        <input type="hidden" name="qty" value="">
        <button class="btn btn-copper w-full" disabled><?= store_icon('shopping-bag') ?> <span data-out="add">Add to bag</span></button>
      </form>
      <a href="<?= e(store_url($first['url'])) ?>" class="btn w-full mt-2 border border-cream/25 hover:bg-cream/10" data-out="view">View stone</a>
      <p class="text-[12px] text-cream/50 mt-4">Estimates only. For stairs, skirting and countertops, send us your drawing on <a class="underline" href="<?= e(store_wa_link('Hello! I’d like a quote for stairs / skirting / countertops.')) ?>" target="_blank" rel="noopener">WhatsApp</a>.</p>
    </aside>
  </div>
  <script type="application/json" id="calc-data"><?= store_json(['stones' => $stones, 'tax_rate' => $tax_rate, 'advance_percent' => $advance_percent, 'base' => rtrim(store_url('/'), '/')]) ?></script>
  <?php endif; ?>
</div>
