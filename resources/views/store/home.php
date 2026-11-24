<?php
/**
 * Home (design: html/storefront/index.html). Data: Catalog::home().
 * Works without JS: slide 1 shows, finder is a plain GET form, collection shows every card.
 * @var array $home @var array $page
 */
$chrome = $home['chrome'];
$cards = $home['cards'];
$slides = $home['slides'];
$finder = $home['finder'];
$spot = $home['spotlight'];
$reviews = $home['reviews'];
$adv = (int) $home['advance_percent'];
$spaces = $chrome['spaces'];
$featuredSpaces = array_values(array_filter($spaces, fn ($c) => $c['is_featured']));
$spaceCards = $featuredSpaces !== [] ? $featuredSpaces : $spaces;
$origins = $chrome['origins'];
$needs = $chrome['needs'];
$wa = store_wa_link('Hello, I would like to know more about your stone.');
$tel = store_tel();
$address = store_address();
$firstSpace = $spaces[0]['id'] ?? null;
?>
<!-- ============ HERO ============ -->
<section class="wrap pt-5">
  <div class="relative rounded-[28px] overflow-hidden bg-ink min-h-[560px] lg:min-h-[620px] 2xl:min-h-[720px] isolate">
    <div id="slides" class="absolute inset-0 -z-10">
      <?php foreach ($slides as $i => $s): ?>
        <?= store_img($s['image'], '', 'slide absolute inset-0 w-full h-full object-cover' . ($i === 0 ? ' on' : ''), true, $i > 0, true) ?>
      <?php endforeach; ?>
    </div>
    <div class="absolute inset-0 -z-10 bg-gradient-to-r from-ink/85 via-ink/55 to-ink/10"></div>
    <div class="grid lg:grid-cols-12 gap-8 h-full p-6 sm:p-10 lg:p-14">
      <div class="lg:col-span-7 text-cream flex flex-col justify-center py-6">
        <span class="inline-flex w-fit items-center gap-2 text-[11.5px] uppercase tracking-[.2em] text-cream/80 border border-cream/25 rounded-full px-3 py-1.5"><span class="w-1.5 h-1.5 rounded-full bg-copper"></span><span id="slideKicker"><?= e($slides[0]['kicker']) ?></span></span>
        <h1 id="slideTitle" class="font-display font-light text-[44px] sm:text-[58px] lg:text-[68px] leading-[1.02] tracking-tight mt-5"><?= nl2br(e($slides[0]['title'])) ?></h1>
        <p id="slideCopy" class="mt-5 max-w-lg text-cream/80 text-[16.5px] leading-relaxed"><?= e($slides[0]['copy']) ?></p>
        <div class="mt-8 flex flex-wrap items-center gap-3">
          <a id="slideCta" href="<?= e($slides[0]['href'][0] === '#' ? $slides[0]['href'] : store_url($slides[0]['href'])) ?>" class="inline-flex items-center gap-2 h-12 px-6 rounded-full bg-copper hover:bg-copper-600 text-white font-semibold"><span id="slideCtaText"><?= e($slides[0]['cta']) ?></span> <?= store_icon('arrow-up-right') ?></a>
          <a href="#visit" class="inline-flex items-center gap-2 h-12 px-5 rounded-full border border-cream/30 hover:bg-cream/10 text-cream font-medium"><?= store_icon('store') ?> Visit the showroom</a>
        </div>
        <?php if (count($slides) > 1): ?>
        <div class="mt-10 flex items-center gap-3 text-cream/70 text-[12px]">
          <button type="button" id="prev" class="w-9 h-9 grid place-items-center rounded-full border border-cream/25 hover:bg-cream/10" aria-label="Previous slide"><?= store_icon('chevron-left') ?></button>
          <button type="button" id="next" class="w-9 h-9 grid place-items-center rounded-full border border-cream/25 hover:bg-cream/10" aria-label="Next slide"><?= store_icon('chevron-right') ?></button>
          <div id="dots" class="flex gap-1.5 ml-2">
            <?php foreach ($slides as $i => $s): ?><button type="button" class="h-1.5 rounded-full transition-all <?= $i === 0 ? 'w-8 bg-cream' : 'w-3 bg-cream/35' ?>" aria-label="Slide <?= $i + 1 ?>"></button><?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($spaces !== []): ?>
      <!-- Stone finder: where → which room → colour -->
      <div class="lg:col-span-5 flex items-center lg:justify-end">
        <form id="finder" action="<?= e(store_url('/shop')) ?>" method="get" class="w-full max-w-md bg-cream/95 backdrop-blur rounded-3xl p-6 shadow-2xl">
          <div class="flex items-center justify-between">
            <h2 class="font-display text-[24px] leading-tight">Find your stone</h2>
            <span class="text-[12px] text-ink-500"><b id="finderCount" class="text-ink"><?= (int) ($spaces[0]['count'] ?? 0) ?></b> matches</span>
          </div>
          <p class="text-[13px] text-ink-500 mt-1">Pick where it goes. We'll narrow it down.</p>

          <fieldset class="mt-5">
            <legend class="text-[11.5px] uppercase tracking-[.14em] text-ink-500 font-semibold mb-2">1 · Where</legend>
            <div class="grid grid-cols-3 gap-2">
              <?php foreach (array_slice($spaces, 0, 3) as $i => $c): ?>
              <label class="cursor-pointer">
                <input type="radio" name="cat" value="<?= e($c['slug']) ?>" data-space="<?= (int) $c['id'] ?>" class="peer sr-only"<?= $i === 0 ? ' checked' : '' ?>>
                <span class="flex flex-col items-center gap-1 py-3 px-1 leading-tight rounded-2xl border border-ink/10 bg-white text-[12.5px] font-semibold text-center hover:border-ink/40 peer-checked:bg-ink peer-checked:text-cream peer-checked:border-ink peer-focus-visible:ring-4 peer-focus-visible:ring-teal/30"><?= store_icon(['grid-2x2', 'brick-wall', 'chef-hat'][$i] ?? 'square', '!w-5 !h-5') ?><?= e($c['name']) ?></span>
              </label>
              <?php endforeach; ?>
            </div>
          </fieldset>
          <fieldset class="mt-4">
            <legend class="text-[11.5px] uppercase tracking-[.14em] text-ink-500 font-semibold mb-2">2 · Which room</legend>
            <div class="flex flex-wrap gap-2" id="fRoom">
              <?php foreach (array_slice($spaces, 0, 3) as $c): ?>
                <?php foreach ($c['children'] as $s): ?>
                <label class="cursor-pointer" data-parent="<?= (int) $c['id'] ?>"<?= $c['id'] !== $firstSpace ? ' hidden' : '' ?>>
                  <input type="radio" name="cat" value="<?= e($s['slug']) ?>" data-room="<?= (int) $s['id'] ?>" class="peer sr-only">
                  <span class="inline-flex items-center px-3.5 h-9 rounded-full border border-ink/10 bg-white text-[13px] font-medium hover:border-ink/40 peer-checked:bg-ink peer-checked:text-cream peer-checked:border-ink peer-focus-visible:ring-4 peer-focus-visible:ring-teal/30"><?= e($s['name']) ?></span>
                </label>
                <?php endforeach; ?>
              <?php endforeach; ?>
            </div>
          </fieldset>
          <?php if ($finder['colours'] !== []): ?>
          <fieldset class="mt-4">
            <legend class="text-[11.5px] uppercase tracking-[.14em] text-ink-500 font-semibold mb-2">3 · Colour <span class="normal-case tracking-normal font-normal">(optional)</span></legend>
            <div class="flex flex-wrap gap-2">
              <?php foreach ($finder['colours'] as $col): ?>
              <label class="cursor-pointer" title="<?= e($col['label']) ?>">
                <input type="checkbox" name="attr[colour][]" value="<?= e($col['label']) ?>" data-colour class="peer sr-only">
                <span class="block w-9 h-9 rounded-full ring-1 ring-ink/15 transition hover:scale-110 peer-checked:ring-2 peer-checked:ring-teal peer-checked:ring-offset-2 peer-focus-visible:ring-2 peer-focus-visible:ring-teal" style="background:<?= e(preg_match('/^#[0-9a-fA-F]{3,8}$/', $col['swatch']) ? $col['swatch'] : '#E0D5C2') ?>"><span class="sr-only"><?= e($col['label']) ?></span></span>
              </label>
              <?php endforeach; ?>
            </div>
          </fieldset>
          <?php endif; ?>
          <button class="mt-6 w-full h-12 rounded-full bg-ink hover:bg-ink-700 text-cream font-semibold flex items-center justify-center gap-2">Show stones <?= store_icon('arrow-right') ?></button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Promise strip -->
  <div class="mt-4 grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
    <?php foreach ([
        ['truck', 'Delivery included', 'No shipping charge at checkout'],
        ['smartphone', $adv . '% advance by UPI', 'Or pay in full. Balance at dispatch'],
        ['receipt-text', business('gstin') ? 'GST invoice' : 'Proper bill', 'With a QR to check it online'],
        ['store', 'See the slab first', 'Showroom visit in ' . business('address.city', 'Bankura')],
    ] as [$icon, $head, $sub]): ?>
    <div class="flex items-center gap-3 bg-white rounded-2xl px-4 py-3.5 border border-ink/5"><span class="w-10 h-10 shrink-0 rounded-xl bg-teal-50 text-teal grid place-items-center"><?= store_icon($icon) ?></span><div class="leading-tight"><b class="text-[14px]"><?= e($head) ?></b><p class="text-[12.5px] text-ink-500"><?= e($sub) ?></p></div></div>
    <?php endforeach; ?>
  </div>
</section>

<?php if ($spaceCards !== [] || $origins !== []): ?>
<!-- ============ SHOP BY SPACE ============ -->
<section id="spaces" class="wrap mt-20">
  <div class="flex items-end justify-between gap-6 mb-7">
    <div>
      <p class="eyebrow">Shop by space</p>
      <h2 class="font-display text-[34px] sm:text-[42px] leading-tight tracking-tight mt-1">Where will your stone go?</h2>
    </div>
    <a href="<?= e(store_url('/shop')) ?>" class="hidden sm:inline-flex items-center gap-1.5 text-[14px] font-semibold hover:text-teal">All stone <?= store_icon('arrow-up-right') ?></a>
  </div>
  <?php if ($spaceCards !== []): ?>
  <div class="grid md:grid-cols-3 gap-4">
    <?php foreach (array_slice($spaceCards, 0, 3) as $c): $kids = array_slice($c['children'], 0, 3); ?>
    <article class="group bg-white rounded-[24px] overflow-hidden border border-ink/5 flex flex-col">
      <a href="<?= e(store_url('/shop/' . $c['slug'])) ?>" class="relative block aspect-[4/3] overflow-hidden bg-ink">
        <?= store_img($c['image'], $c['name'], 'absolute inset-0 w-full h-full object-cover group-hover:scale-[1.04] transition duration-700', true, true, true) ?>
        <div class="absolute inset-0 bg-gradient-to-t from-ink/70 via-transparent to-transparent"></div>
        <div class="absolute left-5 bottom-4 right-5 text-cream flex items-end justify-between gap-3">
          <div><h3 class="font-display text-[30px] leading-none"><?= e($c['name']) ?></h3><?php if ($c['description'] !== ''): ?><p class="text-[13px] text-cream/80 mt-1.5"><?= e($c['description']) ?></p><?php endif; ?></div>
          <span class="shrink-0 text-[12px] bg-cream/15 backdrop-blur rounded-full px-2.5 py-1"><?= (int) $c['count'] ?> stones</span>
        </div>
      </a>
      <?php if ($kids !== []): ?>
      <div class="p-4 grid <?= ['', 'grid-cols-1', 'grid-cols-2 md:grid-cols-1 lg:grid-cols-2', 'grid-cols-2 sm:grid-cols-3 md:grid-cols-1 lg:grid-cols-2 xl:grid-cols-3'][count($kids)] ?> gap-2">
        <?php foreach ($kids as $s): ?>
        <a href="<?= e(store_url('/shop/' . $c['slug'] . '/' . $s['slug'])) ?>" class="flex items-center gap-2.5 p-2 rounded-xl hover:bg-cream">
          <?= store_img($s['image'], '', 'w-10 h-10 shrink-0 rounded-lg object-cover') ?>
          <span class="leading-tight min-w-0"><b class="block text-[13px] font-semibold truncate"><?= e($s['name']) ?></b><small class="text-ink-400 text-[11.5px]"><?= (int) $s['count'] ?> stones</small></span>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($origins !== []): ?>
  <div class="grid md:grid-cols-2 gap-4 mt-4">
    <?php foreach (array_slice($origins, 0, 2) as $i => $c): ?>
    <a href="<?= e(store_url('/shop/' . $c['slug'])) ?>" class="group relative rounded-[24px] overflow-hidden <?= $i ? 'bg-copper-50' : 'bg-teal-50' ?> p-6 sm:p-8 flex items-center gap-6 min-h-[200px]">
      <div class="flex-1">
        <p class="eyebrow<?= $i ? ' !text-copper' : '' ?>"><?= (int) $c['count'] ?> stones</p>
        <h3 class="font-display text-[34px] leading-tight mt-1"><?= e($c['name']) ?> stone</h3>
        <?php if ($c['description'] !== ''): ?><p class="text-ink-500 text-[14px] mt-1"><?= e($c['description']) ?></p><?php endif; ?>
        <span class="mt-4 inline-flex items-center gap-1.5 font-semibold text-[14px]">Explore <?= store_icon('arrow-up-right') ?></span>
      </div>
      <?php if ($c['image']): ?><div class="hidden sm:block shrink-0"><?= store_img($c['image'], '', 'w-[150px] h-[150px] rounded-2xl object-cover ring-1 ring-ink/10 group-hover:rotate-2 transition') ?></div><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<!-- ============ COLLECTION ============ -->
<section id="collection" class="wrap mt-20">
  <div class="flex flex-col md:flex-row md:items-end justify-between gap-5 mb-7">
    <div>
      <p class="eyebrow">The collection</p>
      <h2 class="font-display text-[34px] sm:text-[42px] leading-tight tracking-tight mt-1">Stones people are choosing</h2>
    </div>
    <?php if (count($home['tabs']) > 1): ?>
    <div id="tabs" role="tablist" aria-label="Filter the collection" class="flex gap-2 overflow-x-auto no-scrollbar">
      <?php foreach ($home['tabs'] as $t): ?>
      <button type="button" role="tab" class="tab shrink-0 px-4 h-10 rounded-full border border-ink/15 text-[13.5px] font-semibold hover:border-ink" aria-selected="<?= $t['key'] === 'all' ? 'true' : 'false' ?>" data-tab="<?= e($t['key']) ?>"><?= e($t['label']) ?> <span class="opacity-50 font-normal"><?= (int) $t['count'] ?></span></button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php if ($cards !== []): ?>
  <div id="grid" class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-6 gap-3 sm:gap-5">
    <?php foreach ($cards as $c): ?><?= store_card($c) ?><?php endforeach; ?>
  </div>
  <div class="mt-8 text-center">
    <a href="<?= e(store_url('/shop')) ?>" class="inline-flex items-center gap-2 h-12 px-6 rounded-full border border-ink/20 hover:border-ink font-semibold text-[14px]">View all <?= (int) $home['total'] ?> stones <?= store_icon('arrow-right') ?></a>
  </div>
  <?php else: ?>
  <div class="bg-white rounded-[24px] border border-ink/5 p-10 text-center">
    <span class="mx-auto w-12 h-12 rounded-2xl bg-teal-50 text-teal grid place-items-center"><?= store_icon('gem') ?></span>
    <h3 class="font-display text-[26px] mt-4">Our stones are being photographed</h3>
    <p class="text-ink-500 mt-1 max-w-md mx-auto">The online collection opens shortly. Until then, visit the showroom or message us — we'll send photos of the slabs in stock.</p>
    <div class="mt-5 flex flex-wrap gap-3 justify-center">
      <?php if ($wa !== ''): ?><a href="<?= e($wa) ?>" class="btn btn-dark" rel="noopener"><?= store_icon('message-circle') ?> WhatsApp us</a><?php endif; ?>
      <a href="#visit" class="btn btn-line">Visit the showroom</a>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php if ($spot): $dv = null; foreach ($spot['variant_list'] as $v) { if ($v['is_default']) { $dv = $v; } } $dv ??= $spot['variant_list'][0] ?? null;
    $spotPrice = $dv['price'] ?? $spot['price']; $spotCompare = $dv ? $dv['compare'] : $spot['compare']; $mainShot = $spot['shots'][0] ?? $spot['image']; ?>
<!-- ============ SPOTLIGHT ============ -->
<section class="wrap mt-20">
  <div id="spot" class="rounded-[28px] overflow-hidden bg-ink text-cream grid lg:grid-cols-2"
       data-price="<?= e((string) $spotPrice) ?>" data-gst="<?= e((string) $home['gst_rate']) ?>" data-advance="<?= $adv ?>" data-tax-included="<?= business('tax.prices_include_tax') ? '1' : '0' ?>">
    <div class="relative min-h-[380px] lg:min-h-[620px]">
      <?= store_img($mainShot, $spot['name'], 'absolute inset-0 w-full h-full object-cover', true, true, true) ?>
      <?php if (count($spot['shots']) > 1): ?>
      <div class="absolute left-5 bottom-5 right-5 flex gap-2" id="spotThumbs">
        <?php foreach ($spot['shots'] as $i => $s): ?>
        <button type="button" class="w-16 h-16 rounded-xl overflow-hidden ring-2 <?= $i === 0 ? 'ring-cream' : 'ring-transparent' ?> hover:ring-cream/70" data-shot="<?= e(base_url($s['full'])) ?>" aria-label="Photo <?= $i + 1 ?>"><?= store_img($s, '', 'w-full h-full object-cover') ?></button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <span class="absolute top-5 left-5 bg-cream text-ink text-[11px] uppercase tracking-[.16em] font-bold px-3 py-1.5 rounded-full">Stone of the month</span>
    </div>
    <div class="p-7 sm:p-10 lg:p-12 flex flex-col">
      <p class="text-[12px] uppercase tracking-[.2em] text-cream/60"><?= $spot['brand'] !== '' ? e($spot['brand']) . ' · ' : '' ?><span id="spotSku"><?= e($dv['sku'] ?? $spot['sku']) ?></span></p>
      <h2 class="font-display text-[38px] sm:text-[46px] leading-[1.05] tracking-tight mt-2"><?= e($spot['name']) ?></h2>
      <?php if ($spot['short'] !== ''): ?><p class="text-cream/70 mt-3 max-w-lg"><?= e($spot['short']) ?></p><?php endif; ?>
      <?php if ($spot['reviews'] > 0): ?><div class="mt-5 flex items-center gap-2 text-[13px]"><span class="text-gold tracking-wider"><?= store_stars($spot['rating']) ?></span><span class="text-cream/60"><?= e(number_format($spot['rating'], 1)) ?> · <?= (int) $spot['reviews'] ?> review<?= $spot['reviews'] === 1 ? '' : 's' ?></span></div><?php endif; ?>

      <?php if (count($spot['variant_list']) > 1): ?>
      <fieldset class="mt-6">
        <legend class="text-[11.5px] uppercase tracking-[.14em] text-cream/55 font-semibold mb-2">Options</legend>
        <div class="flex flex-wrap gap-2">
          <?php foreach ($spot['variant_list'] as $v): ?>
          <label class="cursor-pointer">
            <input type="radio" name="spot_variant" class="peer sr-only" value="<?= (int) $v['id'] ?>" data-price="<?= e((string) $v['price']) ?>" data-compare="<?= e((string) ($v['compare'] ?? '')) ?>" data-sku="<?= e($v['sku']) ?>"<?= $dv && $v['id'] === $dv['id'] ? ' checked' : '' ?>>
            <span class="inline-flex items-center px-3.5 h-9 rounded-full border border-cream/25 text-[13px] font-medium hover:border-cream/60 peer-checked:bg-cream peer-checked:text-ink peer-checked:border-cream peer-focus-visible:ring-2 peer-focus-visible:ring-cream"><?= e($v['title']) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <?php endif; ?>

      <?php if ($spot['spec_rows'] !== [] || $spot['rooms'] !== []): ?>
      <dl class="mt-6 grid grid-cols-2 sm:grid-cols-4 gap-2 text-[13px]">
        <?php foreach (array_slice($spot['spec_rows'], 0, 6) as $r): ?><div class="bg-cream/[.06] rounded-xl px-4 py-3"><dt class="text-cream/50 text-[11.5px]"><?= e($r['label']) ?></dt><dd class="font-semibold mt-0.5"><?= e($r['value']) ?></dd></div><?php endforeach; ?>
        <?php if ($spot['rooms'] !== []): ?><div class="bg-cream/[.06] rounded-xl px-4 py-3 col-span-2"><dt class="text-cream/50 text-[11.5px]">Rooms</dt><dd class="font-semibold mt-0.5"><?= e(implode(', ', $spot['rooms'])) ?></dd></div><?php endif; ?>
      </dl>
      <?php endif; ?>

      <div class="mt-6 bg-cream/[.06] rounded-2xl p-5 border border-cream/10">
        <div class="flex flex-wrap items-end gap-4 justify-between">
          <div>
            <p class="text-[11.5px] uppercase tracking-[.14em] text-cream/55 font-semibold">Price</p>
            <p class="mt-1"><b id="spotPrice" class="font-display text-[34px] font-normal"><?= e(store_inr($spotPrice)) ?></b> <?php if ($spot['unit'] !== ''): ?><span class="text-cream/60">/ <?= e($spot['unit']) ?></span><?php endif; ?> <del id="spotCompare" class="text-cream/40 ml-1"><?= $spotCompare ? e(store_inr($spotCompare)) : '' ?></del></p>
          </div>
          <label class="block">
            <span class="text-[11.5px] uppercase tracking-[.14em] text-cream/55 font-semibold">Your area (<?= e($spot['unit'] ?: 'units') ?>)</span>
            <input id="spotArea" type="number" min="1" step="1" value="450" inputmode="numeric" class="mt-1 block w-36 h-11 px-3 rounded-xl bg-cream text-ink font-semibold outline-none focus:ring-4 focus:ring-teal/40">
          </label>
        </div>
        <div class="mt-4 grid grid-cols-3 gap-3 text-[13px]">
          <div><p class="text-cream/55">With 8% wastage</p><b id="calcArea" class="text-[16px]">—</b></div>
          <div><p class="text-cream/55">Estimated total<?= business('tax.prices_include_tax') ? '' : ' (incl. GST)' ?></p><b id="calcTotal" class="text-[16px]">—</b></div>
          <div><p class="text-cream/55"><?= $adv ?>% advance</p><b id="calcAdvance" class="text-[16px] text-[#E9B48F]">—</b></div>
        </div>
      </div>

      <div class="mt-6 flex flex-wrap gap-3">
        <a href="<?= e(store_url($spot['url'])) ?>" class="h-12 px-6 rounded-full bg-copper hover:bg-copper-600 text-white font-semibold flex items-center gap-2"><?= store_icon('shopping-bag') ?> Full details &amp; buy</a>
        <?php $spotWa = store_wa_link('Hello, I am interested in ' . $spot['name'] . '.'); if ($spotWa !== ''): ?>
        <a href="<?= e($spotWa) ?>" rel="noopener" class="h-12 px-5 rounded-full border border-cream/25 hover:bg-cream/10 font-medium flex items-center gap-2"><?= store_icon('message-circle') ?> Ask on WhatsApp</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($needs !== []): ?>
<!-- ============ SHOP BY NEED ============ -->
<section class="wrap mt-20">
  <div class="mb-7">
    <p class="eyebrow">Shop by need</p>
    <h2 class="font-display text-[34px] sm:text-[42px] leading-tight tracking-tight mt-1">Built for how you'll use it</h2>
  </div>
  <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
    <?php foreach ($needs as $t): ?>
    <a href="<?= e(store_url('/shop?tag[]=' . rawurlencode($t['slug']))) ?>" class="group bg-white rounded-[20px] border border-ink/5 p-5 hover:border-teal/40 hover:shadow-card transition flex flex-col min-h-[160px]">
      <span class="w-11 h-11 rounded-xl bg-teal-50 text-teal grid place-items-center group-hover:bg-teal group-hover:text-white transition"><?= store_icon($t['icon']) ?></span>
      <h3 class="font-semibold mt-4"><?= e($t['name']) ?></h3>
      <p class="text-[12.5px] text-ink-500 mt-0.5"><?= e($t['copy'] !== '' ? $t['copy'] : 'Browse the range') ?></p>
      <span class="mt-auto pt-3 text-[12px] text-ink-400"><?= (int) $t['count'] ?> stones →</span>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- ============ PLAN YOUR SPACE ============ -->
<section id="tools" class="wrap mt-20">
  <div class="mb-7">
    <p class="eyebrow">Plan your space</p>
    <h2 class="font-display text-[34px] sm:text-[42px] leading-tight tracking-tight mt-1">Decide with confidence</h2>
  </div>
  <div class="grid md:grid-cols-4 md:grid-rows-2 gap-4 md:h-[440px]">
    <a href="<?= e(store_url('/calculator')) ?>" class="group relative md:col-span-2 md:row-span-2 rounded-[24px] overflow-hidden min-h-[280px] bg-teal text-white p-7 flex flex-col justify-between">
      <span class="w-12 h-12 rounded-xl bg-white/15 grid place-items-center"><?= store_icon('ruler', '!w-6 !h-6') ?></span>
      <div>
        <h3 class="font-display text-[32px] leading-tight">Area &amp; cost calculator</h3>
        <p class="text-white/75 mt-1.5 text-[14px] max-w-md">Enter your room size. We work out square feet, boxes and wastage, and what it costs with GST.</p>
        <span class="mt-4 inline-flex items-center gap-1.5 font-semibold text-[14px]">Open the calculator <?= store_icon('arrow-right') ?></span>
      </div>
    </a>
    <a href="<?= e(store_url('/brands')) ?>" class="group rounded-[24px] bg-white border border-ink/5 p-6 flex flex-col justify-between min-h-[180px] hover:shadow-card transition">
      <span class="w-11 h-11 rounded-xl bg-cream grid place-items-center"><?= store_icon('gem') ?></span>
      <div><h3 class="font-display text-[23px] leading-tight">Our brands</h3><p class="text-ink-500 text-[13.5px] mt-1">Signature lots and everyday stone.</p></div>
    </a>
    <a href="<?= e(store_url('/track')) ?>" class="group rounded-[24px] bg-white border border-ink/5 p-6 flex flex-col justify-between min-h-[180px] hover:shadow-card transition">
      <span class="w-11 h-11 rounded-xl bg-cream grid place-items-center"><?= store_icon('package-search') ?></span>
      <div><h3 class="font-display text-[23px] leading-tight">Track an order</h3><p class="text-ink-500 text-[13.5px] mt-1">Order number and phone is all you need.</p></div>
    </a>
    <a href="<?= e(store_url('/saved')) ?>" class="group md:col-span-2 rounded-[24px] bg-copper-50 border border-copper/10 p-6 flex flex-col justify-between min-h-[180px]">
      <span class="w-11 h-11 rounded-xl bg-white grid place-items-center text-copper"><?= store_icon('heart') ?></span>
      <div><h3 class="font-display text-[23px] leading-tight">Your shortlist</h3><p class="text-ink-500 text-[13.5px] mt-1">Tap the heart on any stone to compare later.</p></div>
    </a>
  </div>
</section>

<!-- ============ HOW ORDERING WORKS ============ -->
<section class="wrap mt-20">
  <div class="rounded-[28px] bg-cream-200 p-6 sm:p-10">
    <div class="grid lg:grid-cols-12 gap-8">
      <div class="lg:col-span-4">
        <p class="eyebrow">How ordering works</p>
        <h2 class="font-display text-[32px] leading-tight tracking-tight mt-1">Simple, and you're never in the dark.</h2>
        <p class="text-ink-500 mt-3 text-[14.5px]">Some rare stones are <b class="text-ink">enquiry only</b> — send a request and we call you back with a quote.</p>
      </div>
      <ol class="lg:col-span-8 grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <li class="bg-white rounded-2xl p-5"><span class="font-display text-[28px] text-teal">01</span><h3 class="font-semibold mt-2">Choose your stone</h3><p class="text-[13.5px] text-ink-500 mt-1">Online, or see the slab at the showroom.</p></li>
        <li class="bg-white rounded-2xl p-5"><span class="font-display text-[28px] text-teal">02</span><h3 class="font-semibold mt-2">Pay by UPI</h3><p class="text-[13.5px] text-ink-500 mt-1"><?= $adv ?>% advance or the full amount. Add your UTR or a screenshot.</p></li>
        <li class="bg-white rounded-2xl p-5"><span class="font-display text-[28px] text-teal">03</span><h3 class="font-semibold mt-2">We confirm</h3><p class="text-[13.5px] text-ink-500 mt-1">We check your payment and confirm your order.</p></li>
        <li class="bg-white rounded-2xl p-5"><span class="font-display text-[28px] text-teal">04</span><h3 class="font-semibold mt-2">Delivered</h3><p class="text-[13.5px] text-ink-500 mt-1">Delivery is included. Your <?= business('gstin') ? 'GST invoice' : 'bill' ?> comes with it.</p></li>
      </ol>
    </div>
  </div>
</section>

<?php if ($home['brands'] !== []): ?>
<!-- ============ BRANDS ============ -->
<section id="brands" class="wrap mt-20">
  <div class="flex items-end justify-between gap-6 mb-7">
    <div>
      <p class="eyebrow">Our brands</p>
      <h2 class="font-display text-[34px] sm:text-[42px] leading-tight tracking-tight mt-1">Sourced from the right quarries</h2>
    </div>
    <a href="<?= e(store_url('/brands')) ?>" class="hidden sm:inline-flex items-center gap-1.5 text-[14px] font-semibold hover:text-teal">All brands <?= store_icon('arrow-up-right') ?></a>
  </div>
  <div class="grid md:grid-cols-3 gap-4">
    <?php foreach ($home['brands'] as $b): ?>
    <a href="<?= e(store_url('/shop?brand[]=' . rawurlencode($b['slug']))) ?>" class="group bg-white rounded-[24px] border border-ink/5 overflow-hidden flex">
      <?= store_img($b['image'], '', 'w-28 sm:w-32 shrink-0 ' . ($b['is_logo'] ? 'object-contain p-3 bg-cream' : 'object-cover')) ?>
      <div class="p-5 flex-1 min-w-0">
        <div class="flex items-center gap-2"><span class="text-[10.5px] font-bold uppercase tracking-[.12em] px-2 py-0.5 rounded-md <?= $b['tier'] === 'signature' ? 'bg-gold/15 text-[#8a6a35]' : 'bg-teal-50 text-teal' ?>"><?= e($b['tier']) ?></span><?php if ($b['country'] !== ''): ?><span class="text-[12px] text-ink-400"><?= e($b['country']) ?></span><?php endif; ?></div>
        <h3 class="font-display text-[22px] mt-2 leading-tight group-hover:text-teal"><?= e($b['name']) ?></h3>
        <?php if ($b['description'] !== ''): ?><p class="text-[13px] text-ink-500 mt-1 line-clamp-2"><?= e($b['description']) ?></p><?php endif; ?>
        <p class="text-[12px] text-ink-400 mt-2"><?= (int) $b['count'] ?> stones →</p>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($reviews['count'] > 0): ?>
<!-- ============ REVIEWS ============ -->
<section class="wrap mt-20">
  <div class="grid lg:grid-cols-12 gap-6">
    <div class="lg:col-span-4 bg-white rounded-[24px] p-7 border border-ink/5 flex flex-col">
      <p class="eyebrow">Customer reviews</p>
      <div class="flex items-end gap-3 mt-3"><span class="font-display text-[64px] leading-none"><?= e(number_format($reviews['avg'], 1)) ?></span><div class="pb-2"><div class="text-gold tracking-wider"><?= store_stars($reviews['avg']) ?></div><p class="text-[13px] text-ink-500"><?= (int) $reviews['count'] ?> published review<?= $reviews['count'] === 1 ? '' : 's' ?></p></div></div>
      <div class="mt-5 space-y-1.5 text-[12.5px]">
        <?php foreach ($reviews['dist'] as $star => $pct): ?>
        <div class="flex items-center gap-2"><span class="w-3 text-ink-500"><?= (int) $star ?></span><span class="flex-1 h-1.5 rounded-full bg-cream-200 overflow-hidden"><i class="block h-full bg-gold rounded-full" style="width:<?= (int) $pct ?>%"></i></span><span class="w-8 text-right text-ink-400"><?= (int) $pct ?>%</span></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="lg:col-span-8 grid md:grid-cols-2 gap-4">
      <?php foreach ($reviews['items'] as $r): ?>
      <article class="bg-white rounded-[24px] p-6 border border-ink/5 flex flex-col">
        <div class="flex items-center justify-between"><span class="text-gold tracking-wider text-[14px]" aria-label="<?= (int) $r['rating'] ?> out of 5"><?= store_stars($r['rating']) ?></span><?php if ($r['verified']): ?><span class="text-[11.5px] text-teal flex items-center gap-1"><?= store_icon('badge-check') ?> Verified buyer</span><?php endif; ?></div>
        <?php if ($r['title'] !== ''): ?><h3 class="font-semibold mt-3"><?= e($r['title']) ?></h3><?php endif; ?>
        <p class="text-[14px] text-ink-500 mt-1.5 leading-relaxed"><?= e($r['body']) ?></p>
        <?php if ($r['response'] !== ''): ?><div class="mt-3 text-[13px] bg-cream rounded-xl px-3.5 py-2.5"><b class="text-[12px]">Maa Sarada replied</b><p class="text-ink-500"><?= e($r['response']) ?></p></div><?php endif; ?>
        <a href="<?= e(store_url($r['product_url'])) ?>" class="mt-auto pt-4 flex items-center gap-3 border-t border-ink/5">
          <?= store_img($r['image'], '', 'w-10 h-10 shrink-0 rounded-lg object-cover') ?>
          <div class="leading-tight text-[12.5px]"><b><?= e($r['name']) ?></b><?= $r['city'] !== '' ? ' · ' . e($r['city']) : '' ?><p class="text-ink-400"><?= e($r['product'] . ($r['application'] !== '' ? ' · ' . $r['application'] : '')) ?></p></div>
        </a>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ============ SHOWROOM ============ -->
<section id="visit" class="wrap mt-20">
  <div class="grid lg:grid-cols-2 rounded-[28px] overflow-hidden bg-white border border-ink/5">
    <div class="relative min-h-[320px] bg-ink">
      <?= store_img($chrome['featured']['image_alt'] ?? null, '', 'absolute inset-0 w-full h-full object-cover opacity-70', true, true, true) ?>
      <div class="absolute inset-0 bg-gradient-to-t from-ink/85 to-ink/10"></div>
      <div class="absolute left-6 right-6 bottom-6 text-cream">
        <h2 class="font-display text-[34px] leading-tight">Visit the showroom</h2>
        <p class="text-cream/80 mt-1"><?= e($address !== '' ? $address : business('address.city', 'Bankura') . ', ' . business('address.state', 'West Bengal')) ?></p>
        <div class="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-[13.5px] text-cream/85">
          <span class="flex items-center gap-1.5"><?= store_icon('clock') ?> <?= e((string) config('store.hours')) ?></span>
          <?php if (business('phone')): ?><span class="flex items-center gap-1.5"><?= store_icon('phone') ?> <?= e((string) business('phone')) ?></span><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="p-7 sm:p-10 flex flex-col">
      <h3 class="font-display text-[28px] leading-tight">Tell us about your project</h3>
      <p class="text-ink-500 text-[14px] mt-1">Send us your room size and the look you want. We'll come back with stone options and a price — usually the same day.</p>
      <ul class="mt-6 space-y-3 text-[14px]">
        <li class="flex gap-3"><span class="w-9 h-9 shrink-0 rounded-xl bg-teal-50 text-teal grid place-items-center"><?= store_icon('images') ?></span><span><b>Photos of the exact lot</b><br><span class="text-ink-500">Ask, and we'll send pictures of the slabs you'll get.</span></span></li>
        <li class="flex gap-3"><span class="w-9 h-9 shrink-0 rounded-xl bg-teal-50 text-teal grid place-items-center"><?= store_icon('ruler') ?></span><span><b>Measured quotes</b><br><span class="text-ink-500">Share your room sizes; we include wastage and cutting.</span></span></li>
        <li class="flex gap-3"><span class="w-9 h-9 shrink-0 rounded-xl bg-teal-50 text-teal grid place-items-center"><?= store_icon('truck') ?></span><span><b>Delivery included</b><br><span class="text-ink-500">Across Bankura, Bishnupur, Durgapur and nearby.</span></span></li>
      </ul>
      <div class="mt-auto pt-7 flex flex-wrap gap-3">
        <?php if ($wa !== ''): ?><a href="<?= e($wa) ?>" rel="noopener" class="btn btn-dark"><?= store_icon('message-circle') ?> WhatsApp us</a><?php endif; ?>
        <?php if ($tel !== ''): ?><a href="<?= e($tel) ?>" class="btn btn-line"><?= store_icon('phone') ?> Call the showroom</a><?php endif; ?>
        <a href="<?= e(store_url('/contact')) ?>" class="btn btn-line"><?= store_icon('send') ?> Send an enquiry</a>
      </div>
    </div>
  </div>
</section>

<script type="application/json" id="home-data"><?= store_json([
    'slides' => array_map(fn ($s) => ['kicker' => $s['kicker'], 'title' => $s['title'], 'copy' => $s['copy'], 'cta' => $s['cta'],
        'href' => $s['href'][0] === '#' ? $s['href'] : store_url($s['href'])], $slides),
    'matches' => $finder['matches'],
]) ?></script>
