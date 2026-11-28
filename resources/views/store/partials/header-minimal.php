<?php /** Checkout / pay header (design: renderChrome({minimal: true})). @var string $active */ ?>
<header class="bg-cream border-b border-ink/10"><div class="wrap h-[72px] flex items-center justify-between">
  <a href="<?= e(store_url('/')) ?>" class="flex items-center gap-2.5">
    <img src="<?= e(store_asset('image/logo-mark.webp')) ?>" alt="" class="h-11 w-auto" width="44" height="44">
    <span class="leading-tight"><span class="block font-display text-[19px]">Maa Sarada</span><span class="block text-[10.5px] uppercase tracking-[.18em] text-ink-500">Marble &amp; Sanitation · <?= e(business('address.city', 'Bankura')) ?></span></span>
  </a>
  <?php if (in_array($active ?? '', ['checkout', 'pay'], true)): ?><span class="flex items-center gap-2 text-[13px] text-ink-500"><?= store_icon('lock-keyhole', 'text-teal') ?> Secure checkout</span><?php endif; ?>
</div></header>
