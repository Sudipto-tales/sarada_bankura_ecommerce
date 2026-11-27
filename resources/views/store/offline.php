<?php
/** Shown by the service worker when a page isn't saved on this device (docs/mobile-app.md). */
?>
<div class="wrap pt-16 pb-6">
  <div class="max-w-xl mx-auto text-center">
    <span class="inline-grid place-items-center w-16 h-16 rounded-full bg-white shadow-card text-teal"><?= store_icon('wifi-off', '!w-7 !h-7') ?></span>
    <h1 class="font-display text-[34px] sm:text-[44px] leading-tight tracking-tight mt-5">You’re offline</h1>
    <p class="text-ink-500 mt-2">This page isn’t saved on your phone yet. Stones you opened earlier still work without internet.</p>
    <div class="mt-6 flex flex-wrap gap-3 justify-center">
      <button type="button" class="btn btn-dark" data-retry><?= store_icon('rotate-cw') ?> Try again</button>
      <a href="<?= e(store_url('/')) ?>" class="btn btn-line"><?= store_icon('house') ?> Home</a>
    </div>
  </div>
  <div id="offlineList" class="max-w-xl mx-auto mt-10" hidden>
    <p class="eyebrow">Saved on this device</p>
    <ul class="mt-3 grid gap-2"></ul>
  </div>
</div>
