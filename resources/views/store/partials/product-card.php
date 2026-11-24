<?php
/**
 * Product card (design: store.js card()). Used by home, shop, related and saved via store_card($c).
 * $c comes from Catalog::cards(). Works without JS: every action is a link or a form.
 * @var array $c
 */
$tones = [
    'ink' => 'bg-ink text-cream', 'white' => 'bg-white text-ink-500', 'gold' => 'bg-gold text-white',
    'teal' => 'bg-teal text-white', 'copper' => 'bg-copper-50 text-copper',
];
$url = store_url($c['url']);
$line = 'w-full h-10 rounded-full border border-ink/20 hover:border-ink text-[13px] font-semibold flex items-center justify-center gap-1.5';
$dark = 'w-full h-10 rounded-full bg-ink hover:bg-ink-700 text-cream text-[13px] font-semibold flex items-center justify-center gap-1.5';
$sub = $c['specs'] !== [] ? implode(' · ', $c['specs']) : $c['stone_type'];
?>
<article class="card group bg-white rounded-[20px] border border-ink/5 hover:shadow-card transition overflow-hidden flex flex-col"<?= isset($c['tab_keys']) ? ' data-tabs="' . e(implode(' ', $c['tab_keys'])) . '"' : '' ?>>
  <a href="<?= e($url) ?>" class="card-img relative block aspect-square overflow-hidden bg-cream" tabindex="-1" aria-hidden="true">
    <?= store_img($c['image'], $c['name'] . ' stone', 'absolute inset-0 w-full h-full object-cover') ?>
    <?php if ($c['image_alt']): ?><?= store_img($c['image_alt'], '', 'alt absolute inset-0 w-full h-full object-cover') ?><?php endif; ?>
    <?php if ($c['badges'] !== []): ?>
    <span class="badges absolute top-3 left-3 flex flex-col items-start gap-1">
      <?php foreach ($c['badges'] as $b): ?><span class="<?= $tones[$b['tone']] ?? $tones['ink'] ?>"><?= e($b['label']) ?></span><?php endforeach; ?>
    </span>
    <?php endif; ?>
  </a>
  <div class="p-3.5 sm:p-4 flex flex-col flex-1">
    <div class="flex items-start justify-between gap-2">
      <p class="text-[11px] uppercase tracking-[.12em] text-ink-400 truncate"><?= e($c['origin'] ?: ' ') ?></p>
      <button type="button" class="p-1.5 -mt-1 -mr-1.5 rounded-full hover:bg-cream text-ink-400" data-save="<?= e($c['slug']) ?>" aria-label="Save <?= e($c['name']) ?>" aria-pressed="false"><?= store_icon('heart') ?></button>
    </div>
    <h3 class="font-display text-[19px] sm:text-[20px] leading-tight mt-0.5"><a href="<?= e($url) ?>" class="hover:text-teal"><?= e($c['name']) ?></a></h3>
    <?php if ($sub !== ''): ?><p class="text-[12.5px] text-ink-500 mt-1 truncate"><?= e($sub) ?></p><?php endif; ?>
    <?php if ($c['reviews'] > 0): ?>
    <p class="text-[12px] mt-1.5"><span class="text-gold">★</span> <b><?= e(number_format($c['rating'], 1)) ?></b> <span class="text-ink-400">(<?= (int) $c['reviews'] ?>)</span><?php if ($c['need']): ?> <span class="text-ink-300 mx-1">·</span><span class="text-teal"><?= e($c['need']) ?></span><?php endif; ?></p>
    <?php elseif ($c['need']): ?>
    <p class="text-[12px] mt-1.5 text-teal"><?= e($c['need']) ?></p>
    <?php endif; ?>

    <div class="mt-3">
      <?php if ($c['status'] === 'coming_soon'): ?>
        <p class="text-[13px] text-ink-500">Launching soon<?php if ($c['show_price'] && $c['price'] > 0): ?> · from <b class="text-ink"><?= e(store_inr($c['price'])) ?></b>/<?= e($c['unit']) ?><?php endif; ?></p>
      <?php elseif (!$c['show_price'] || $c['price'] <= 0): ?>
        <p class="text-[14px] font-semibold">Price on request</p>
        <p class="text-[11.5px] text-ink-400"><?= $c['is_enquiry_only'] ? 'Enquiry only · we call with a quote' : 'Ask for today’s rate' ?></p>
      <?php else: ?>
        <p class="flex items-baseline gap-1.5 flex-wrap">
          <?php if ($c['from']): ?><span class="text-[11.5px] text-ink-400">from</span><?php endif; ?>
          <b class="text-[18px] font-bold"><?= e(store_inr($c['price'])) ?></b>
          <?php if ($c['unit'] !== ''): ?><span class="text-[12.5px] text-ink-500">/ <?= e($c['unit']) ?></span><?php endif; ?>
          <?php if ($c['compare']): ?><del class="text-[12.5px] text-ink-300"><?= e(store_inr($c['compare'])) ?></del><span class="text-[11.5px] font-bold text-copper"><?= (int) $c['off'] ?>% off</span><?php endif; ?>
        </p>
        <?php if ($c['pack_qty'] && $c['pack_unit']): ?>
        <p class="text-[11.5px] text-ink-400">1 <?= e($c['unit'] ?: 'box') ?> = <?= e(rtrim(rtrim(number_format($c['pack_qty'], 3, '.', ''), '0'), '.')) ?> <?= e($c['pack_unit']) ?> · <?= e(store_inr($c['price'] / $c['pack_qty'])) ?>/<?= e($c['pack_unit']) ?></p>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="mt-auto pt-4">
      <?php if ($c['cta'] === 'notify'): ?>
        <a href="<?= e($url) ?>#enquire" class="<?= $line ?>"><?= store_icon('bell') ?> Notify me</a>
      <?php elseif ($c['cta'] === 'enquire'): ?>
        <a href="<?= e($url) ?>#enquire" class="<?= $line ?>"><?= store_icon('message-circle') ?> Enquire</a>
      <?php elseif ($c['cta'] === 'choose'): ?>
        <a href="<?= e($url) ?>" class="<?= $dark ?>"><?= store_icon('sliders-horizontal') ?> Choose <?= e($c['option_groups'] !== [] ? implode(' & ', array_slice($c['option_groups'], 0, 2)) : 'options') ?></a>
      <?php elseif (store_cart_ready()): ?>
        <form method="post" action="<?= e((string) route('store.cart.add')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="product_id" value="<?= (int) $c['id'] ?>">
          <input type="hidden" name="variant_id" value="<?= (int) $c['default_variant_id'] ?>">
          <button class="<?= $dark ?>"><?= store_icon('shopping-bag') ?> Add to bag</button>
        </form>
      <?php else: ?>
        <a href="<?= e($url) ?>" class="<?= $dark ?>"><?= store_icon('shopping-bag') ?> View &amp; buy</a>
      <?php endif; ?>
    </div>
  </div>
</article>
