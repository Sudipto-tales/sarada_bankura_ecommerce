<?php
/**
 * Brands (design: html/storefront/brands.html). Data: Catalog::brands(null, 4); tier filter is a ?tier= link.
 * @var list<array> $brands @var string $tier @var array<string,int> $tierCounts
 */
$labels = ['all' => 'All', 'signature' => 'Signature', 'core' => 'Core', 'value' => 'Value'];
$shown = array_values(array_filter($brands, fn ($b) => $tier === 'all' || $b['tier'] === $tier));
?>
<div class="wrap pt-8">
  <p class="eyebrow">Our brands</p>
  <h1 class="font-display text-[40px] sm:text-[50px] leading-tight tracking-tight mt-1">Sourced from the right quarries</h1>
  <p class="text-ink-500 mt-2 max-w-2xl">Signature lines are hand-picked lots we stand behind personally. Core lines are dependable everyday stone at honest prices.</p>

  <nav class="mt-6 flex gap-2 overflow-x-auto no-scrollbar" aria-label="Filter by tier">
    <?php foreach ($labels as $k => $l): if ($k !== 'all' && ($tierCounts[$k] ?? 0) === 0) { continue; } ?>
    <a href="<?= e(store_url('/brands' . ($k === 'all' ? '' : '?tier=' . $k))) ?>" class="chip shrink-0 inline-flex items-center gap-1 h-10 px-4 rounded-full border border-ink/15 text-[13.5px] font-semibold hover:border-ink" aria-pressed="<?= $k === $tier ? 'true' : 'false' ?>"<?= $k === $tier ? ' aria-current="page"' : '' ?>><?= e($l) ?> <span class="opacity-50 font-normal"><?= (int) ($k === 'all' ? count($brands) : ($tierCounts[$k] ?? 0)) ?></span></a>
    <?php endforeach; ?>
  </nav>

  <div class="mt-8 space-y-6">
    <?php foreach ($shown as $b): ?>
    <section class="bg-white rounded-[28px] border border-ink/5 overflow-hidden grid lg:grid-cols-[360px,1fr]" id="<?= e($b['slug']) ?>">
      <div class="relative min-h-[220px] bg-ink">
        <?= store_img($b['image'], '', 'absolute inset-0 w-full h-full ' . ($b['is_logo'] ? 'object-contain p-10 bg-cream' : 'object-cover'), true, true, true) ?>
        <div class="absolute inset-0 bg-gradient-to-t from-ink/80 to-transparent"></div>
        <div class="absolute left-6 right-6 bottom-6 text-cream">
          <span class="text-[10.5px] font-bold uppercase tracking-[.12em] px-2 py-0.5 rounded-md <?= $b['tier'] === 'signature' ? 'bg-gold text-white' : 'bg-cream text-ink' ?>"><?= e($b['tier']) ?></span>
          <h2 class="font-display text-[32px] leading-tight mt-2"><?= e($b['name']) ?></h2>
          <p class="text-cream/80 text-[13.5px]"><?= e(trim(($b['country'] !== '' ? $b['country'] . ' · ' : '') . $b['count'] . ' stone' . ($b['count'] === 1 ? '' : 's'))) ?></p>
        </div>
      </div>
      <div class="p-6">
        <?php if ($b['description'] !== ''): ?><p class="text-ink-500"><?= e($b['description']) ?></p><?php endif; ?>
        <?php if ($b['products'] !== []): ?>
        <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
          <?php foreach ($b['products'] as $p): ?>
          <a href="<?= e(store_url($p['url'])) ?>" class="group">
            <div class="aspect-square rounded-2xl overflow-hidden bg-cream"><?= store_img($p['image'], $p['name'], 'w-full h-full object-cover group-hover:scale-105 transition') ?></div>
            <p class="text-[13.5px] font-semibold mt-2 leading-tight group-hover:text-teal"><?= e($p['name']) ?></p>
            <p class="text-[12px] text-ink-400"><?= e(store_price_text($p)) ?></p>
          </a>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="mt-4 text-[13.5px] text-ink-400">Stones from this brand are coming online soon. Ask us what's in the showroom.</p>
        <?php endif; ?>
        <?php if ($b['count'] > 0): ?>
        <a href="<?= e(store_url('/shop?brand[]=' . rawurlencode($b['slug']))) ?>" class="mt-5 inline-flex items-center gap-1.5 font-semibold text-[14px] hover:text-teal">All <?= e($b['name']) ?> stones <?= store_icon('arrow-right') ?></a>
        <?php endif; ?>
      </div>
    </section>
    <?php endforeach; ?>
    <?php if ($shown === []): ?><p class="text-ink-500">No brands in this tier yet.</p><?php endif; ?>
  </div>
</div>
