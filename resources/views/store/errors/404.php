<?php
/** Storefront 404 (no design existed; built in the store style). @var string $message */
$wa = store_wa_link('Hello, I was looking for something on your website.');
?>
<div class="wrap pt-14 pb-6">
  <div class="max-w-2xl mx-auto text-center">
    <p class="eyebrow">Page not found</p>
    <h1 class="font-display text-[44px] sm:text-[56px] leading-tight tracking-tight mt-2">This slab isn't in the showroom.</h1>
    <p class="text-ink-500 mt-3">The page may have moved, or the stone is no longer listed. Try a search, or start from the collection.</p>
    <form action="<?= e(store_url('/shop')) ?>" method="get" role="search" class="relative mt-7 max-w-md mx-auto">
      <span class="absolute left-4 top-1/2 -translate-y-1/2 text-ink-400"><?= store_icon('search') ?></span>
      <input name="q" type="search" placeholder="Search stone, room or need…" aria-label="Search" class="w-full h-12 pl-11 pr-4 rounded-full bg-white border border-ink/10 focus:border-teal focus:ring-4 focus:ring-teal/10 outline-none">
    </form>
    <div class="mt-6 flex flex-wrap gap-3 justify-center">
      <a href="<?= e(store_url('/')) ?>" class="btn btn-dark"><?= store_icon('house') ?> Back to home</a>
      <?php if ($wa !== ''): ?><a href="<?= e($wa) ?>" rel="noopener" class="btn btn-line"><?= store_icon('message-circle') ?> Ask us on WhatsApp</a><?php endif; ?>
    </div>
  </div>
</div>
