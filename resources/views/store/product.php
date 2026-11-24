<?php
/**
 * Product page (design: html/storefront/product.html; docs/storefront.md §3.4).
 * Server renders the selected variant (?v=SKU) completely; without JS the variant table rows and a
 * <select> switch variants by link / GET. store.js swaps price, SKU, images and specs in place from
 * the JSON block at the end, and keeps ?v= in the address bar.
 *
 * @var array $product @var array $card @var array|null $brand @var array $pricing @var array|null $primary @var array|null $parent
 * @var list<array> $rooms @var list<array> $tags @var list<array> $groups @var list<array> $variants @var array|null $selected
 * @var list<array> $gallery @var list<array> $specs @var array $overrides @var array|null $returns @var array $reviews
 * @var list<array> $related @var int|float $advance_percent @var float $gst_rate @var bool $tax_included @var string $stamp @var array $enquiry
 */
$p = $product;
$v = $selected;
$url = store_url($p['url']);
$price = $v['price'] ?? $pricing['price'];
$compare = $v ? $v['compare'] : $pricing['compare'];
$off = $v ? $v['off'] : $pricing['off'];
$unit = ($v['unit'] ?? '') ?: ($pricing['unit'] ?: 'unit');
$packQty = $v['pack_qty'] ?? $pricing['pack_qty'];
$packUnit = ($v['pack_unit'] ?? '') ?: $pricing['pack_unit'];
$minQty = $v['min_qty'] ?? $pricing['min_qty'];
$step = $pricing['step'] ?? 1.0;
$qty = max((float) ($minQty ?? 0), $step, 1.0);
$num = static fn($n): string => rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');
$gstLine = !$tax_included && $gst_rate > 0;
$line = round($price * $qty, 2);
$gst = $gstLine ? round($line * $gst_rate / 100, 2) : 0.0;
$advance = ceil(($line + $gst) * $advance_percent) / 100;
$activeVariants = array_values(array_filter($variants, fn ($x) => $x['active']));
$tones = ['ink' => 'bg-ink text-cream', 'white' => 'bg-white text-ink-500', 'gold' => 'bg-gold text-white', 'teal' => 'bg-teal text-white', 'copper' => 'bg-copper-50 text-copper'];
$attrMap = [];
foreach ($specs as $s) {
    $attrMap[$s['code']] = $s['value'];
}
$selOver = $v ? ($overrides[$v['id']] ?? []) : [];
$out = $p['stock'] === 'out';
$roomOptions = array_map(fn ($r) => [$r['id'], $r['parent'] . ' · ' . $r['name']], $rooms);
$cart = store_cart_ready();
$variantTitle = static fn(?array $x): string => $x ? $x['title'] : '';
$waPhotos = store_wa_link('Hi, please send photos of ' . $p['name'] . ($v ? ' (' . $v['title'] . ')' : ''));
$enq = static fn(string $kind, string $submit, bool $dark = false) => store_partial('enquiry-form', [
    'source' => 'product', 'kind' => $kind, 'back' => $p['url'], 'stamp' => $stamp, 'state' => $enquiry,
    'product' => ['id' => $p['id'], 'name' => $p['name']], 'variantId' => $v['id'] ?? 0, 'categories' => $kind === 'notify' ? [] : $roomOptions,
    'fields' => ['quantity' => $kind !== 'notify', 'message' => $kind !== 'notify'], 'submit' => $submit, 'dark' => $dark,
]);
$crumbs = array_values(array_filter([
    ['/', 'Home'], ['/shop', 'Shop'],
    $parent ? [$parent['url'], $parent['name']] : null,
    $primary ? [$primary['url'], $primary['name']] : null,
]));
?>
<div class="wrap pt-6">
  <nav class="text-[13px] text-ink-500 flex items-center gap-1.5 flex-wrap" aria-label="Breadcrumb">
    <?php foreach ($crumbs as [$h, $l]): ?><a href="<?= e(store_url($h)) ?>" class="hover:text-ink"><?= e($l) ?></a><?= store_icon('chevron-right', '!w-3.5 !h-3.5') ?><?php endforeach; ?>
    <span class="text-ink font-semibold truncate max-w-[60vw]" aria-current="page"><?= e($p['name']) ?></span>
  </nav>

  <!-- ========== gallery + buy box ========== -->
  <section class="mt-5 grid lg:grid-cols-[minmax(0,1.15fr),minmax(0,1fr)] xl:grid-cols-[minmax(0,1.3fr),minmax(0,1fr)] gap-8 xl:gap-14 items-start">
    <div class="lg:sticky lg:top-[92px] min-w-0">
      <div class="grid <?= count($gallery) > 1 ? 'grid-cols-[64px,minmax(0,1fr)] sm:grid-cols-[84px,minmax(0,1fr)]' : 'grid-cols-1' ?> gap-3">
        <?php if (count($gallery) > 1): ?>
        <div id="thumbs" class="flex flex-col gap-2.5 max-h-[640px] overflow-y-auto no-scrollbar">
          <?php foreach ($gallery as $i => $g): ?>
          <a href="<?= e(base_url($g['full'])) ?>" data-shot="<?= $i ?>" class="block aspect-square rounded-xl overflow-hidden ring-2 <?= $i === 0 ? 'ring-ink' : 'ring-transparent hover:ring-ink/30' ?>" aria-label="Image <?= $i + 1 ?> of <?= count($gallery) ?>"<?= $i === 0 ? ' aria-current="true"' : '' ?>>
            <?= store_img($g, '', 'w-full h-full object-cover') ?>
          </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="relative min-w-0">
          <div id="mainImg" class="relative aspect-[4/5] sm:aspect-square rounded-[24px] overflow-hidden bg-cream-200">
            <?php if ($gallery !== []): ?>
              <img src="<?= e(base_url($gallery[0]['full'])) ?>" alt="<?= e($gallery[0]['alt'] !== '' ? $gallery[0]['alt'] : $p['name']) ?>" class="absolute inset-0 w-full h-full object-cover" fetchpriority="high" data-main>
            <?php else: ?>
              <?= store_img(null, $p['name'], 'absolute inset-0 w-full h-full') ?>
            <?php endif; ?>
          </div>
          <?php if ($p['badges'] !== []): ?>
          <div class="badges absolute top-4 left-4 flex flex-col items-start gap-1.5">
            <?php foreach ($p['badges'] as $b): ?><span class="<?= $tones[$b['tone']] ?? $tones['ink'] ?>"><?= e($b['label']) ?></span><?php endforeach; ?>
          </div>
          <?php endif; ?>
          <button type="button" class="absolute top-4 right-4 w-11 h-11 rounded-full bg-white/90 backdrop-blur grid place-items-center shadow-card text-ink-400" data-save="<?= e($p['slug']) ?>" aria-label="Save <?= e($p['name']) ?>" aria-pressed="false"><?= store_icon('heart') ?></button>
          <p class="mt-2.5 text-[12px] text-ink-400 flex items-start gap-1.5"><?= store_icon('info', 'mt-0.5 shrink-0') ?> Natural stone — every lot varies. Ask for photos of the exact slabs we’ll send.</p>
        </div>
      </div>
    </div>

    <div id="buy" class="min-w-0">
      <p class="text-[12px] uppercase tracking-[.18em] text-ink-400">
        <?php if ($brand): ?><a href="<?= e(store_url('/shop?brand[]=' . rawurlencode($brand['slug']))) ?>" class="hover:text-teal"><?= e($brand['name']) ?></a> · <?php endif; ?>
        SKU <span data-sku><?= e($v['sku'] ?? $p['sku']) ?></span>
      </p>
      <h1 class="font-display text-[36px] sm:text-[48px] leading-[1.04] tracking-tight mt-2 break-words"><?= e($p['name']) ?></h1>
      <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-[13.5px]">
        <?php if ($reviews['count'] > 0): ?>
        <a href="#reviews" class="flex items-center gap-1.5"><span class="text-gold tracking-wider" aria-hidden="true"><?= store_stars($reviews['avg']) ?></span><b><?= e(number_format($reviews['avg'], 1)) ?></b><span class="text-ink-500 underline-offset-2 hover:underline"><?= (int) $reviews['count'] ?> review<?= $reviews['count'] === 1 ? '' : 's' ?></span></a>
        <?php else: ?><span class="text-ink-400">No reviews yet</span><?php endif; ?>
        <?php if (($attrMap['stone_type'] ?? '') !== '' || ($attrMap['origin'] ?? '') !== ''): ?><span class="text-ink-500"><?= e(implode(' · ', array_filter([$attrMap['stone_type'] ?? '', $attrMap['origin'] ?? '']))) ?></span><?php endif; ?>
      </div>
      <?php if ($p['short'] !== ''): ?><p class="mt-4 text-ink-500 text-[15.5px] leading-relaxed"><?= e($p['short']) ?></p><?php endif; ?>

      <?php if ($p['status'] === 'coming_soon'): ?>
      <!-- ---- coming soon: notify me ---- -->
      <div id="enquire" class="mt-6 rounded-[24px] bg-ink text-cream p-6 scroll-mt-28">
        <p class="text-[12px] uppercase tracking-[.16em] text-cream/60">Coming soon</p>
        <p class="font-display text-[26px] mt-1"><?= $p['show_price'] && $price > 0 ? 'Expected from ' . e(store_inr($price)) . ' / ' . e($unit) : 'Price at launch' ?></p>
        <p class="text-cream/70 text-[14px] mt-1 mb-4">The first lot is on its way. Leave your number and we’ll message you the day it lands.</p>
        <?= $enq('notify', 'Notify me', true) ?>
      </div>
      <?= store_partial('spec-teaser', ['specs' => $specs]) ?>

      <?php elseif (!$p['show_price'] || $price <= 0): ?>
      <!-- ---- enquiry only / price on request ---- -->
      <div id="enquire" class="mt-6 rounded-[24px] bg-white border border-ink/5 p-6 scroll-mt-28">
        <p class="text-[12px] uppercase tracking-[.16em] text-ink-400"><?= $p['is_enquiry_only'] ? 'Enquiry only' : 'Price on request' ?></p>
        <p class="font-display text-[26px] mt-1"><?= $p['is_enquiry_only'] ? 'Quoted for your project' : 'Ask for today’s rate' ?></p>
        <p class="text-ink-500 text-[14px] mt-1 mb-5"><?= $p['is_enquiry_only'] ? 'Priced per lot and size. Tell us about your space and we’ll call with a quote.' : 'The rate on this stone moves with each shipment. We’ll call you back within a working day.' ?></p>
        <?= $enq('quote', 'Request a quote') ?>
      </div>
      <?= store_partial('spec-teaser', ['specs' => $specs]) ?>

      <?php else: ?>
      <!-- ---- buyable ---- -->
      <div class="mt-6 flex flex-wrap items-end gap-x-3 gap-y-1">
        <b class="font-display text-[42px] leading-none font-normal" data-price><?= e(store_inr($price)) ?></b><span class="text-ink-500 pb-1">/ <span data-unit><?= e($unit) ?></span></span>
        <span data-compare-wrap class="contents"<?= $compare ? '' : ' hidden' ?>><del class="text-ink-300 pb-1" data-compare><?= $compare ? e(store_inr($compare)) : '' ?></del><span class="pb-1 text-[13px] font-bold text-copper bg-copper-50 px-2 py-0.5 rounded-md"><span data-off><?= (int) $off ?></span>% off</span></span>
      </div>
      <p class="text-[12.5px] text-ink-400 mt-1.5">
        <span data-pack<?= $packQty && $packUnit ? '' : ' hidden' ?>><?php if ($packQty && $packUnit): ?>1 <?= e($unit) ?> = <?= e($num($packQty)) ?> <?= e($packUnit) ?> (<?= e(store_inr($price / $packQty)) ?>/<?= e($packUnit) ?>) · <?php endif; ?></span>
        <?= $gstLine ? 'GST ' . e($num($gst_rate)) . '% added at checkout' : 'Price includes GST' ?> · Delivery included
      </p>

      <?php if ($activeVariants !== []): ?>
      <div class="mt-6 space-y-5" data-js-only hidden id="opts">
        <?php foreach ($groups as $g): $selVal = $v['values'][$g['id']] ?? null; ?>
        <fieldset>
          <legend class="text-[13px] font-semibold mb-2"><?= e($g['name']) ?>: <span class="font-normal text-ink-500" data-group-label="<?= (int) $g['id'] ?>"><?php foreach ($g['values'] as $ov) { if ($ov['id'] === $selVal) { echo e($ov['value']); } } ?></span></legend>
          <div class="flex flex-wrap gap-2">
            <?php foreach ($g['values'] as $ov): ?>
            <label class="cursor-pointer">
              <input type="radio" class="peer sr-only" name="opt-<?= (int) $g['id'] ?>" value="<?= (int) $ov['id'] ?>" data-group="<?= (int) $g['id'] ?>"<?= $ov['id'] === $selVal ? ' checked' : '' ?>>
              <span class="inline-flex items-center h-10 px-4 rounded-full border border-ink/15 bg-white text-[13.5px] font-semibold peer-checked:bg-ink peer-checked:text-cream peer-checked:border-ink peer-focus-visible:ring-2 peer-focus-visible:ring-teal" data-opt="<?= (int) $ov['id'] ?>"><?= e($ov['value']) ?></span>
            </label>
            <?php endforeach; ?>
          </div>
        </fieldset>
        <?php endforeach; ?>
        <p class="text-[12.5px] text-ink-500 flex items-center gap-1.5" data-combo-msg><?= store_icon('check-circle-2', 'text-teal') ?> Selected: <b class="text-ink" data-variant-title><?= e($variantTitle($v)) ?></b></p>
      </div>
      <noscript>
        <form method="get" action="<?= e($url) ?>" class="mt-6 flex flex-wrap items-end gap-2">
          <label class="flex-1 min-w-[220px]"><span class="lbl">Option</span>
            <select name="v" class="field"><?php foreach ($activeVariants as $x): ?><option value="<?= e($x['sku']) ?>"<?= $v && $x['id'] === $v['id'] ? ' selected' : '' ?>><?= e($x['title']) ?> — <?= e(store_inr($x['price'])) ?>/<?= e($x['unit']) ?></option><?php endforeach; ?></select></label>
          <button class="btn btn-line">Show</button>
        </form>
      </noscript>
      <?php endif; ?>

      <?php if ($cart): ?><form id="buyForm" method="post" action="<?= e((string) route('store.cart.add')) ?>" class="mt-6 rounded-[24px] bg-white border border-ink/5 p-5">
      <?php else: ?><div id="buyForm" class="mt-6 rounded-[24px] bg-white border border-ink/5 p-5"><?php endif; ?>
        <?php if ($cart): ?><?= csrf_field() ?><input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="variant_id" value="<?= (int) ($v['id'] ?? 0) ?>" data-variant-input><?php endif; ?>
        <div class="flex flex-wrap items-end gap-4 justify-between">
          <div><label for="qty" class="lbl">Quantity (<span data-unit><?= e($unit) ?></span>)</label>
            <div class="flex items-center h-12 rounded-full border border-ink/15 bg-cream-50 w-fit">
              <button type="button" class="w-12 h-12 grid place-items-center" data-qty="-1" aria-label="Less"><?= store_icon('minus') ?></button>
              <input id="qty" name="qty" type="number" min="<?= e($num($minQty ?? $step)) ?>" step="<?= e($num($step)) ?>" value="<?= e($num($qty)) ?>" inputmode="decimal" class="w-20 text-center bg-transparent font-semibold outline-none" aria-describedby="qtyHelp">
              <button type="button" class="w-12 h-12 grid place-items-center" data-qty="1" aria-label="More"><?= store_icon('plus') ?></button>
            </div>
            <p id="qtyHelp" class="text-[11.5px] text-ink-400 mt-1" data-min-help<?= $minQty ? '' : ' hidden' ?>>Minimum <span data-min><?= e($num($minQty ?? 0)) ?></span> <?= e($unit) ?></p>
          </div>
          <div class="text-right"><p class="text-[12px] text-ink-400">Line total</p><p class="font-display text-[26px] leading-tight" data-line><?= e(store_inr($line)) ?></p>
            <p class="text-[12px] text-ink-400"><?php if ($gstLine): ?>+ GST <span data-gst><?= e(store_inr($gst)) ?></span> · <?php endif; ?><?= e($num($advance_percent)) ?>% advance <span data-advance><?= e(store_inr($advance)) ?></span></p></div>
        </div>
        <details class="mt-4" data-js-only hidden>
          <summary class="cursor-pointer list-none text-[13.5px] font-semibold text-teal flex items-center gap-1.5"><?= store_icon('ruler') ?> Not sure how much? Work it out from your room</summary>
          <div class="mt-3 grid grid-cols-3 gap-2 items-end">
            <label><span class="text-[12px] text-ink-500">Length (ft)</span><input data-calc="l" type="number" min="0" step="any" value="15" class="field !h-10"></label>
            <label><span class="text-[12px] text-ink-500">Width (ft)</span><input data-calc="w" type="number" min="0" step="any" value="12" class="field !h-10"></label>
            <button type="button" data-calc-use class="btn btn-dark !h-10 !px-3 text-[13px]">Use</button>
          </div>
          <p class="mt-2 text-[12.5px] text-ink-500" data-calc-out></p>
        </details>
      <?= $cart ? '</form>' : '</div>' ?>

      <?php if ($p['stock'] === 'low'): ?><p class="mt-4 text-[13px] text-copper flex items-center gap-1.5"><?= store_icon('flame') ?> Only a few slabs left in this lot — order soon or ask us to hold them.</p><?php endif; ?>
      <?php if ($out): ?><p class="mt-4 text-[13px] text-ink-500 flex items-center gap-1.5"><?= store_icon('package-search') ?> Made to order — we’ll confirm the dispatch date when you order.</p><?php endif; ?>

      <div class="mt-5 grid sm:grid-cols-2 gap-3">
        <?php if ($cart): ?>
          <button form="buyForm" class="btn btn-dark" data-add><?= store_icon('shopping-bag') ?> Add to bag</button>
          <button form="buyForm" name="next" value="checkout" class="btn btn-copper">Buy now <?= store_icon('arrow-right') ?></button>
        <?php else: ?>
          <a href="#enquire" class="btn btn-dark"><?= store_icon('send') ?> Request this stone</a>
          <?php if (($wa = store_wa_link('Hi, I’d like to order ' . $p['name'] . ($v ? ' (' . $v['title'] . ')' : ''))) !== ''): ?>
          <a href="<?= e($wa) ?>" rel="noopener" class="btn btn-line" data-wa-order data-wa-base="<?= e('Hi, I’d like to order ' . $p['name']) ?>"><?= store_icon('message-circle') ?> Order on WhatsApp</a>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <div class="mt-5 grid grid-cols-3 gap-2 text-center text-[12px] text-ink-500">
        <div class="bg-white rounded-xl py-3 border border-ink/5"><?= store_icon('truck', 'mx-auto text-teal') ?><p class="mt-1">Delivery included</p></div>
        <div class="bg-white rounded-xl py-3 border border-ink/5"><?= store_icon('smartphone', 'mx-auto text-teal') ?><p class="mt-1"><?= e($num($advance_percent)) ?>% UPI advance</p></div>
        <div class="bg-white rounded-xl py-3 border border-ink/5"><?= store_icon('receipt-text', 'mx-auto text-teal') ?><p class="mt-1">GST invoice</p></div>
      </div>
      <?php if ($waPhotos !== ''): ?>
      <a href="<?= e($waPhotos) ?>" rel="noopener" class="mt-4 flex items-center justify-between gap-3 bg-teal-50 rounded-2xl px-4 py-3.5 text-[14px] hover:bg-teal-100"><span class="flex items-center gap-2.5"><?= store_icon('camera', 'text-teal') ?><span><b>See the actual slabs</b><br><span class="text-ink-500 text-[13px]">We’ll WhatsApp you photos of this lot</span></span></span><?= store_icon('arrow-up-right') ?></a>
      <?php endif; ?>

      <?php if (!$cart): ?>
      <div id="enquire" class="mt-6 rounded-[24px] bg-white border border-ink/5 p-6 scroll-mt-28">
        <p class="eyebrow">Order or ask</p>
        <p class="font-display text-[24px] mt-1 mb-4">Tell us what you need</p>
        <?= $enq('quote', 'Send request') ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>

  <!-- ========== details ========== -->
  <section class="mt-16 grid lg:grid-cols-12 gap-6">
    <div class="lg:col-span-7 space-y-6 min-w-0">
      <article class="bg-white rounded-[24px] border border-ink/5 p-7">
        <p class="eyebrow">About this stone</p>
        <h2 class="font-display text-[28px] leading-tight mt-1">Why people choose it</h2>
        <div class="text-[15px] text-ink-500 mt-3 leading-relaxed space-y-3"><?php foreach (preg_split('/\n\s*\n/', trim($p['description'] !== '' ? $p['description'] : ($p['short'] !== '' ? $p['short'] : 'Natural ' . mb_strtolower($attrMap['stone_type'] ?? 'stone') . ', chosen slab by slab at our showroom.'))) ?: [] as $para): ?><p><?= nl2br(e(trim($para))) ?></p><?php endforeach; ?></div>
        <?php if ($rooms !== []): ?>
        <div class="mt-5 flex flex-wrap gap-2"><span class="text-[12px] uppercase tracking-[.14em] text-ink-400 font-semibold w-full">Used for</span>
          <?php foreach ($rooms as $r): ?><a href="<?= e(store_url($r['url'])) ?>" class="flex items-center gap-2 h-10 pl-1 pr-3.5 rounded-full bg-cream hover:bg-cream-300 text-[13.5px] font-medium"><?= store_img($r['image'], '', 'w-8 h-8 rounded-full object-cover') ?><?= e($r['parent'] . ' · ' . $r['name']) ?></a><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($tags !== []): ?>
        <div class="mt-3 flex flex-wrap gap-2">
          <?php foreach ($tags as $t): ?><a href="<?= e(store_url('/shop?tag[]=' . rawurlencode($t['slug']))) ?>" class="flex items-center gap-1.5 h-8 px-3 rounded-full border border-teal/20 text-teal text-[12.5px] font-semibold hover:bg-teal-50"><?= store_icon($t['icon']) ?><?= e($t['name']) ?></a><?php endforeach; ?>
        </div>
        <?php endif; ?>
      </article>

      <article class="bg-white rounded-[24px] border border-ink/5 p-7" id="options">
        <?php if ($variants === []): ?>
          <p class="eyebrow">Options</p>
          <h2 class="font-display text-[28px] leading-tight mt-1">One size, one finish</h2>
          <p class="text-ink-500 mt-2 text-[14.5px]"><?= e($p['name']) ?> comes as <?= e(implode(', ', array_filter([$attrMap['thickness'] ?? '', $attrMap['finish'] ?? ''])) ?: 'a single option') ?>.<?php if ($p['show_price'] && $pricing['price'] > 0): ?> Priced at <?= e(store_inr($pricing['price'])) ?> / <?= e($pricing['unit'] ?: 'unit') ?>.<?php endif; ?></p>
        <?php else: ?>
          <div class="flex items-end justify-between gap-4"><div><p class="eyebrow">All options</p><h2 class="font-display text-[28px] leading-tight mt-1">Sizes, finishes &amp; prices</h2></div><p class="text-[12.5px] text-ink-400 hidden sm:block">Pick a row to select it</p></div>
          <div class="mt-5 overflow-x-auto -mx-2 px-2">
            <table class="w-full text-[14px] min-w-[520px]">
              <thead><tr class="text-left text-[12px] uppercase tracking-[.1em] text-ink-400">
                <?php foreach ($groups as $g): ?><th class="py-2 pr-4 font-semibold" scope="col"><?= e($g['name']) ?></th><?php endforeach; ?>
                <th class="py-2 pr-4 font-semibold" scope="col">SKU</th><th class="py-2 pr-4 font-semibold text-right" scope="col">Price</th><th class="py-2 font-semibold text-right" scope="col">Status</th></tr></thead>
              <tbody>
              <?php foreach ($variants as $x): $isSel = $v && $x['id'] === $v['id']; ?>
                <tr class="border-t border-ink/5 <?= $x['active'] ? 'hover:bg-cream-50' : 'opacity-50' ?> <?= $isSel ? 'bg-teal-50/60' : '' ?>" data-row="<?= (int) $x['id'] ?>">
                  <?php foreach ($groups as $gi => $g): $val = '—'; foreach ($g['values'] as $ov) { if (($x['values'][$g['id']] ?? null) === $ov['id']) { $val = $ov['value']; } } ?>
                  <td class="py-3 pr-4 <?= $isSel ? 'font-semibold' : '' ?>"><?php if ($gi === 0 && $x['active']): ?><a href="<?= e($url . '?v=' . rawurlencode($x['sku'])) ?>" class="hover:text-teal" data-pick="<?= (int) $x['id'] ?>"><?= e($val) ?></a><?php else: ?><?= e($val) ?><?php endif; ?></td>
                  <?php endforeach; ?>
                  <td class="py-3 pr-4 text-ink-400 text-[12.5px] font-mono"><?php if ($groups === [] && $x['active']): ?><a href="<?= e($url . '?v=' . rawurlencode($x['sku'])) ?>" data-pick="<?= (int) $x['id'] ?>" class="hover:text-teal"><?= e($x['sku']) ?></a><?php else: ?><?= e($x['sku']) ?><?php endif; ?></td>
                  <td class="py-3 pr-4 text-right whitespace-nowrap"><?php if ($p['show_price'] && $x['price'] > 0): ?><b><?= e(store_inr($x['price'])) ?></b><span class="text-ink-400 text-[12px]">/<?= e($x['unit'] ?: $unit) ?></span><?php if ($x['compare']): ?><br><del class="text-[12px] text-ink-300"><?= e(store_inr($x['compare'])) ?></del><?php endif; ?><?php else: ?>—<?php endif; ?></td>
                  <td class="py-3 text-right" data-row-status><?= $isSel ? '<span class="text-[11.5px] font-bold text-teal">Selected</span>' : ($x['active'] ? '<span class="text-[11.5px] text-ink-500">Available</span>' : '<span class="text-[11.5px] text-ink-400">Not offered</span>') ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </article>

      <article class="bg-white rounded-[24px] border border-ink/5 p-7 scroll-mt-28" id="reviews">
        <div class="flex flex-wrap items-end justify-between gap-4"><div><p class="eyebrow">Reviews</p><h2 class="font-display text-[28px] leading-tight mt-1">What buyers say</h2></div>
          <?php if ($p['status'] === 'active'): ?><a href="<?= e(store_url('/review/' . rawurlencode($p['slug']))) ?>" class="btn btn-line !h-10 !px-4 text-[13.5px]"><?= store_icon('pen-line') ?> Write a review</a><?php endif; ?></div>
        <?php if ($reviews['count'] === 0): ?>
          <p class="mt-5 text-ink-500">No reviews yet.<?php if ($p['status'] === 'active'): ?> Bought this stone? <a href="<?= e(store_url('/review/' . rawurlencode($p['slug']))) ?>" class="text-teal font-semibold">Be the first to review it.</a><?php endif; ?></p>
        <?php else: ?>
          <div class="mt-5 grid sm:grid-cols-[auto,1fr] gap-6 items-center">
            <div class="text-center sm:text-left"><p class="font-display text-[60px] leading-none"><?= e(number_format($reviews['avg'], 1)) ?></p><p class="text-gold tracking-wider mt-1" aria-label="<?= e(number_format($reviews['avg'], 1)) ?> out of 5"><?= store_stars($reviews['avg']) ?></p><p class="text-[13px] text-ink-500"><?= (int) $reviews['count'] ?> review<?= $reviews['count'] === 1 ? '' : 's' ?></p></div>
            <div class="space-y-1.5 text-[12.5px]">
              <?php foreach ($reviews['dist'] as $star => $pct): ?><div class="flex items-center gap-2"><span class="w-3 text-ink-500"><?= (int) $star ?></span><span class="flex-1 h-1.5 rounded-full bg-cream-200 overflow-hidden"><i class="block h-full bg-gold rounded-full" style="width:<?= (int) $pct ?>%"></i></span><span class="w-8 text-right text-ink-400"><?= (int) $pct ?>%</span></div><?php endforeach; ?>
            </div>
          </div>
          <?php if (count($reviews['applications']) > 1): ?>
          <div class="mt-5 flex flex-wrap gap-2" data-js-only hidden id="revFilter">
            <button type="button" class="chip h-8 px-3 rounded-full border border-ink/15 text-[12.5px] font-semibold" data-app="" aria-pressed="true">All</button>
            <?php foreach ($reviews['applications'] as $a): ?><button type="button" class="chip h-8 px-3 rounded-full border border-ink/15 text-[12.5px] font-semibold" data-app="<?= e($a) ?>" aria-pressed="false"><?= e($a) ?></button><?php endforeach; ?>
          </div>
          <?php endif; ?>
          <div class="mt-4 divide-y divide-ink/5">
            <?php foreach ($reviews['items'] as $r): ?>
            <article class="py-5" data-review-app="<?= e($r['application']) ?>">
              <div class="flex items-center justify-between gap-3"><span class="text-gold tracking-wider text-[14px]" aria-label="<?= (int) $r['rating'] ?> out of 5"><?= store_stars($r['rating']) ?></span><span class="text-[12px] text-ink-400"><?= e($r['date']) ?></span></div>
              <?php if ($r['title'] !== ''): ?><h3 class="font-semibold mt-2"><?= e($r['title']) ?></h3><?php endif; ?>
              <?php if ($r['body'] !== ''): ?><p class="text-[14.5px] text-ink-500 mt-1 leading-relaxed"><?= nl2br(e($r['body'])) ?></p><?php endif; ?>
              <p class="mt-2 text-[12.5px] text-ink-400"><b class="text-ink"><?= e($r['name']) ?></b><?= $r['city'] !== '' ? ' · ' . e($r['city']) : '' ?><?= $r['application'] !== '' ? ' · ' . e($r['application']) : '' ?>
                <?php if ($r['verified']): ?><span class="text-teal ml-1 inline-flex items-center gap-1"><?= store_icon('badge-check') ?>Verified buyer</span><?php endif; ?></p>
              <?php if ($r['response'] !== ''): ?><div class="mt-3 text-[13.5px] bg-cream rounded-xl px-4 py-3 border-l-2 border-teal"><b class="text-[12px]"><?= e((string) business('name', 'Maa Sarada')) ?> replied</b><p class="text-ink-500"><?= nl2br(e($r['response'])) ?></p></div><?php endif; ?>
            </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </article>
    </div>

    <aside class="lg:col-span-5 space-y-6 min-w-0">
      <article class="bg-white rounded-[24px] border border-ink/5 p-7">
        <p class="eyebrow">Specifications</p>
        <dl class="mt-4 divide-y divide-ink/5 text-[14px]" id="specs">
          <?php $shown = []; foreach ($specs as $s): $shown[] = $s['label']; $ov = $selOver[$s['label']] ?? null; ?>
          <div class="flex justify-between gap-4 py-2.5" data-spec="<?= e($s['label']) ?>" data-base="<?= e($s['value']) ?>"><dt class="text-ink-500"><?= e($s['label']) ?></dt><dd class="font-semibold text-right"><span data-spec-value><?= e($ov ?? $s['value']) ?></span><span data-spec-sel class="text-[10.5px] font-bold uppercase tracking-wide text-teal bg-teal-50 rounded px-1.5 py-0.5 ml-1"<?= $ov !== null ? '' : ' hidden' ?>>selected</span></dd></div>
          <?php endforeach; ?>
          <?php foreach ($groups as $g): if (in_array($g['name'], $shown, true)) { continue; } $ov = $selOver[$g['name']] ?? null; ?>
          <div class="flex justify-between gap-4 py-2.5" data-spec="<?= e($g['name']) ?>" data-base=""<?= $ov === null ? ' hidden' : '' ?>><dt class="text-ink-500"><?= e($g['name']) ?></dt><dd class="font-semibold text-right"><span data-spec-value><?= e((string) $ov) ?></span><span data-spec-sel class="text-[10.5px] font-bold uppercase tracking-wide text-teal bg-teal-50 rounded px-1.5 py-0.5 ml-1">selected</span></dd></div>
          <?php endforeach; ?>
          <div class="flex justify-between gap-4 py-2.5"><dt class="text-ink-500">Sold by</dt><dd class="font-semibold text-right"><span data-unit><?= e($unit) ?></span><span data-pack-spec<?= $packQty && $packUnit ? '' : ' hidden' ?>> (<span data-pack-qty><?= e($num($packQty ?? 0)) ?></span> <?= e($packUnit) ?> each)</span></dd></div>
          <?php if ($brand): ?><div class="flex justify-between gap-4 py-2.5"><dt class="text-ink-500">Brand</dt><dd class="font-semibold text-right"><?= e($brand['name']) ?><?php if (($brand['country'] ?? '') !== ''): ?> <span class="text-ink-400 font-normal">· <?= e((string) $brand['country']) ?></span><?php endif; ?></dd></div><?php endif; ?>
        </dl>
      </article>
      <article class="bg-white rounded-[24px] border border-ink/5 p-7">
        <p class="eyebrow">Delivery &amp; returns</p>
        <ul class="mt-4 space-y-4 text-[14px]">
          <li class="flex gap-3"><span class="w-9 h-9 rounded-xl bg-teal-50 text-teal grid place-items-center shrink-0"><?= store_icon('truck') ?></span><div><b>Delivery included</b><p class="text-ink-500 text-[13.5px]">The price includes delivery to your site. We call before we dispatch.</p></div></li>
          <?php if ($returns): ?>
          <li class="flex gap-3"><span class="w-9 h-9 rounded-xl bg-teal-50 text-teal grid place-items-center shrink-0"><?= store_icon('package-check') ?></span><div>
            <b><?= $returns['replacement'] ? 'Replacement' . ($returns['replacement_days'] ? ' within ' . (int) $returns['replacement_days'] . ' days' : '') : 'No replacement' ?></b>
            <p class="text-ink-500 text-[13.5px]"><?= $returns['replacement'] ? 'For pieces broken in transit. Report with photos — we approve each request.' : 'This stone is cut or sourced to order.' ?></p></div></li>
          <li class="flex gap-3"><span class="w-9 h-9 rounded-xl bg-teal-50 text-teal grid place-items-center shrink-0"><?= store_icon('undo-2') ?></span><div>
            <b><?= $returns['return'] ? 'Returns' . ($returns['return_days'] ? ' within ' . (int) $returns['return_days'] . ' days' : '') : 'No returns' ?></b>
            <p class="text-ink-500 text-[13.5px]"><?= $returns['conditions'] !== '' ? e($returns['conditions']) : ($returns['return'] ? 'Uncut, unused stone in its original packing.' : 'Natural stone cut for your order can’t be returned.') ?> <a href="<?= e(store_url('/policy#returns')) ?>" class="text-teal font-semibold">Policy</a></p></div></li>
          <?php else: ?>
          <li class="flex gap-3"><span class="w-9 h-9 rounded-xl bg-teal-50 text-teal grid place-items-center shrink-0"><?= store_icon('package-check') ?></span><div><b>Returns &amp; replacements</b><p class="text-ink-500 text-[13.5px]">Broken in transit? Report it with photos and we’ll sort it out. <a href="<?= e(store_url('/policy#returns')) ?>" class="text-teal font-semibold">Read the policy</a></p></div></li>
          <?php endif; ?>
        </ul>
      </article>
      <article class="bg-ink text-cream rounded-[24px] p-7">
        <p class="text-[12px] uppercase tracking-[.2em] text-cream/60 font-semibold">Care</p>
        <h2 class="font-display text-[24px] leading-tight mt-1">Keep it looking new</h2>
        <ul class="mt-4 space-y-2.5 text-[14px] text-cream/80">
          <li class="flex gap-2"><?= store_icon('check', 'mt-1 text-[#E9B48F] shrink-0') ?>Wipe spills — lemon, vinegar and cola etch marble.</li>
          <li class="flex gap-2"><?= store_icon('check', 'mt-1 text-[#E9B48F] shrink-0') ?>Mop with plain water or a pH-neutral cleaner.</li>
          <li class="flex gap-2"><?= store_icon('check', 'mt-1 text-[#E9B48F] shrink-0') ?>Re-seal every 12–18 months in kitchens.</li>
        </ul>
      </article>
    </aside>
  </section>

  <?php if ($related !== []): ?>
  <section class="mt-20">
    <div class="flex items-end justify-between gap-6 mb-6"><div><p class="eyebrow">You may also like</p><h2 class="font-display text-[30px] sm:text-[34px] leading-tight mt-1">More for <?= e(mb_strtolower($primary['name'] ?? 'your space')) ?></h2></div>
      <a href="<?= e(store_url($primary['url'] ?? '/shop')) ?>" class="hidden sm:inline-flex items-center gap-1.5 text-[14px] font-semibold hover:text-teal">See all <?= store_icon('arrow-up-right') ?></a></div>
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-6 gap-3 sm:gap-5">
      <?php foreach ($related as $c): ?><?= store_card($c) ?><?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
</div>

<!-- mobile buy bar -->
<div class="lg:hidden fixed bottom-[57px] inset-x-0 z-20 bg-white border-t border-ink/10 px-4 py-2.5 flex items-center gap-3">
  <?php if ($p['buyable']): ?>
    <div class="leading-tight flex-1 min-w-0"><b class="text-[17px]" data-line><?= e(store_inr($line)) ?></b><p class="text-[11.5px] text-ink-500 truncate"><span data-qty-label><?= e($num($qty)) ?></span> <span data-unit><?= e($unit) ?></span><?= $v ? ' · <span data-variant-title>' . e($v['title']) . '</span>' : '' ?></p></div>
    <?php if ($cart): ?><button form="buyForm" class="btn btn-dark !h-11"><?= store_icon('shopping-bag') ?> Add</button>
    <?php else: ?><a href="#enquire" class="btn btn-dark !h-11"><?= store_icon('send') ?> Request</a><?php endif; ?>
  <?php else: ?>
    <a href="#enquire" class="btn btn-dark !h-11 w-full"><?= $p['status'] === 'coming_soon' ? 'Notify me' : 'Request a quote' ?></a>
  <?php endif; ?>
</div>

<script type="application/json" id="product-data"><?= store_json([
    'url' => $url,
    'selected' => $v['id'] ?? 0,
    'variants' => array_map(fn ($x) => [
        'id' => $x['id'], 'sku' => $x['sku'], 'title' => $x['title'], 'price' => $x['price'], 'compare' => $x['compare'], 'off' => $x['off'],
        'unit' => $x['unit'] ?: $unit, 'pack_qty' => $x['pack_qty'], 'pack_unit' => $x['pack_unit'], 'min_qty' => $x['min_qty'],
        'active' => $x['active'], 'values' => (object) $x['values'], 'images' => $x['images'],
    ], $variants),
    'groups' => array_map(fn ($g) => ['id' => $g['id'], 'values' => array_map(fn ($ov) => ['id' => $ov['id'], 'value' => $ov['value']], $g['values'])], $groups),
    'overrides' => (object) $overrides,
    'shots' => array_map(fn ($g) => ['full' => base_url($g['full']), 'alt' => $g['alt'] !== '' ? $g['alt'] : $p['name']], $gallery),
    'price' => $pricing['price'], 'unit' => $unit, 'pack_qty' => $pricing['pack_qty'], 'pack_unit' => $pricing['pack_unit'],
    'min_qty' => $pricing['min_qty'], 'step' => $step,
    'gst' => $gstLine ? $gst_rate : 0, 'advance' => $advance_percent,
    'wa' => store_wa_number(),
]) ?></script>
