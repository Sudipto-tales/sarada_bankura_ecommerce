<?php
/**
 * Saved stones (design: html/storefront/saved.html). Until S5 the list lives in this browser
 * (localStorage ms.saved); store.js fetches the cards from /saved/cards. The visualiser block from
 * the design was dropped with the visualiser (S1).
 */
?>
<div class="wrap pt-8" id="savedPage" data-cards="<?= e((string) route('store.saved.cards')) ?>">
  <div class="flex flex-wrap items-end justify-between gap-4">
    <div>
      <p class="eyebrow">Saved</p>
      <h1 class="font-display text-[40px] sm:text-[46px] leading-tight tracking-tight mt-1">Your shortlist</h1>
      <p class="text-ink-500 text-[14px]">Saved on this device. Signing in to keep it across phones is coming soon.</p>
    </div>
    <a id="share" href="<?= e(store_wa_link()) ?>" class="btn btn-line !h-11" target="_blank" rel="noopener" hidden><?= store_icon('share-2') ?> Share on WhatsApp</a>
  </div>
  <noscript><p class="mt-6 text-ink-500">Your shortlist is kept in the browser and needs JavaScript to show.</p></noscript>
  <div id="savedGrid" class="mt-8 grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-6 gap-3 sm:gap-5" aria-live="polite"></div>
  <div id="savedEmpty" class="mt-8 bg-white rounded-[28px] border border-ink/5 p-12 text-center" hidden>
    <span class="w-16 h-16 mx-auto rounded-full bg-cream grid place-items-center"><?= store_icon('heart', '!w-7 !h-7') ?></span>
    <h2 class="font-display text-[28px] mt-5">Nothing saved yet</h2>
    <p class="text-ink-500 mt-1">Tap the heart on any stone to shortlist it here.</p>
    <a href="<?= e(store_url('/shop')) ?>" class="btn btn-dark mt-6">Browse stone</a>
  </div>
</div>
