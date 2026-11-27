<?php
/**
 * Footer (a compact one in the Android app). The bottom tab bar lives in app-shell.php.
 * @var array|null $chrome @var string $active
 */
$spaces = $chrome['spaces'] ?? [];
$origins = $chrome['origins'] ?? [];
$address = store_address();
$wa = store_wa_link('Hello, I have a question about your stone.');
$tel = store_tel();
$year = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y');
if (\App\Services\Store\AppMode::active()):
?>
<footer class="mt-16 border-t border-ink/10">
  <div class="wrap py-8 grid gap-5 text-[13.5px] text-ink-500">
    <div class="grid grid-cols-2 gap-2">
      <a href="<?= e(store_url('/track')) ?>" class="app-row"><?= store_icon('package-search') ?> Track order</a>
      <a href="<?= e(store_url('/contact')) ?>" class="app-row"><?= store_icon('map-pin') ?> Visit showroom</a>
      <?php if ($wa !== ''): ?><a href="<?= e($wa) ?>" class="app-row" rel="noopener"><?= store_icon('message-circle') ?> WhatsApp</a><?php endif; ?>
      <?php if ($tel !== ''): ?><a href="<?= e($tel) ?>" class="app-row"><?= store_icon('phone') ?> Call us</a><?php endif; ?>
    </div>
    <p class="flex flex-wrap gap-x-4 gap-y-1 text-[12.5px]"><a href="<?= e(store_url('/policy')) ?>">Policies</a><a href="<?= e(store_url('/policy')) ?>#privacy">Privacy</a><a href="<?= e(store_url('/policy')) ?>#terms">Terms</a><span>© <?= e($year) ?> <?= e(business('name')) ?></span></p>
  </div>
</footer>
<?php return; endif; ?>
<footer class="mt-24 bg-ink text-cream/75">
  <div class="wrap py-14 grid grid-cols-2 lg:grid-cols-12 gap-10">
    <div class="col-span-2 lg:col-span-4">
      <div class="flex items-center gap-3">
        <img src="<?= e(store_asset('image/logo.webp')) ?>" alt="" class="h-14 w-auto" width="56" height="56" loading="lazy">
        <div><p class="font-display text-[22px] text-cream">Maa Sarada</p><p class="text-[11px] uppercase tracking-[.18em]">Marble &amp; Stone · <?= e(business('address.city', 'Bankura')) ?></p></div>
      </div>
      <p class="mt-5 max-w-xs text-[14px] leading-relaxed">Natural stone for homes across Bankura, Bishnupur, Durgapur and beyond. Chosen slab by slab.</p>
      <p class="mt-4 text-[13px]"><?= $address !== '' ? e($address) . '<br>' : '' ?><?= e((string) config('store.hours')) ?></p>
      <div class="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-[13px]">
        <?php if ($tel !== ''): ?><a href="<?= e($tel) ?>" class="flex items-center gap-1.5 hover:text-cream"><?= store_icon('phone') ?> <?= e((string) business('phone')) ?></a><?php endif; ?>
        <?php if ($wa !== ''): ?><a href="<?= e($wa) ?>" class="flex items-center gap-1.5 hover:text-cream" rel="noopener"><?= store_icon('message-circle') ?> WhatsApp</a><?php endif; ?>
        <?php if (business('email')): ?><a href="mailto:<?= e((string) business('email')) ?>" class="flex items-center gap-1.5 hover:text-cream"><?= store_icon('mail') ?> <?= e((string) business('email')) ?></a><?php endif; ?>
      </div>
      <div class="mt-5 flex items-center gap-2 text-[12px]"><span class="px-2.5 py-1 rounded-md bg-cream/10">UPI</span><span class="px-2.5 py-1 rounded-md bg-cream/10"><?= business('gstin') ? 'GST invoice' : 'Proper bill' ?></span><span class="px-2.5 py-1 rounded-md bg-cream/10">Delivery included</span></div>
    </div>

    <div class="col-span-2 lg:col-span-4 grid grid-cols-2 gap-8">
      <?php foreach (array_slice($spaces, 0, 3) as $c): ?>
      <div>
        <h3 class="text-cream font-semibold text-[14px] mb-3"><a href="<?= e(store_url('/shop/' . $c['slug'])) ?>"><?= e($c['name']) ?></a></h3>
        <ul class="space-y-2 text-[14px]">
          <?php foreach ($c['children'] as $s): ?><li><a href="<?= e(store_url('/shop/' . $c['slug'] . '/' . $s['slug'])) ?>" class="hover:text-cream"><?= e($s['name']) ?></a></li><?php endforeach; ?>
        </ul>
      </div>
      <?php endforeach; ?>
      <?php if ($origins !== []): ?>
      <div>
        <h3 class="text-cream font-semibold text-[14px] mb-3">Origin</h3>
        <ul class="space-y-2 text-[14px]">
          <?php foreach ($origins as $c): ?><li><a href="<?= e(store_url('/shop/' . $c['slug'])) ?>" class="hover:text-cream"><?= e($c['name']) ?></a></li><?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
    </div>

    <div class="lg:col-span-2">
      <h3 class="text-cream font-semibold text-[14px] mb-3">Plan</h3>
      <ul class="space-y-2 text-[14px]">
        <li><a href="<?= e(store_url('/calculator')) ?>" class="hover:text-cream">Calculator</a></li>
        <li><a href="<?= e(store_url('/saved')) ?>" class="hover:text-cream">Saved</a></li>
        <li><a href="<?= e(store_url('/brands')) ?>" class="hover:text-cream">Brands</a></li>
        <li><a href="<?= e(store_url('/shop?offer=1')) ?>" class="hover:text-cream">Offers</a></li>
      </ul>
    </div>
    <div class="lg:col-span-2">
      <h3 class="text-cream font-semibold text-[14px] mb-3">Help</h3>
      <ul class="space-y-2 text-[14px]">
        <li><a href="<?= e(store_url('/track')) ?>" class="hover:text-cream">Track your order</a></li>
        <li><a href="<?= e(store_url('/track')) ?>#pay" class="hover:text-cream">Pay a balance</a></li>
        <li><a href="<?= e(store_url('/policy')) ?>#returns" class="hover:text-cream">Returns &amp; replacements</a></li>
        <li><a href="<?= e(store_url('/policy')) ?>#delivery" class="hover:text-cream">Delivery</a></li>
        <li><a href="<?= e(store_url('/contact')) ?>" class="hover:text-cream">Contact &amp; visit</a></li>
      </ul>
    </div>
  </div>
  <div class="border-t border-cream/10"><div class="wrap py-5 flex flex-col sm:flex-row gap-2 justify-between text-[12.5px] text-cream/50">
    <span>© <?= e($year) ?> <?= e(business('name')) ?>, <?= e(business('address.city', 'Bankura')) ?></span>
    <span class="flex flex-wrap gap-x-4 gap-y-1"><a href="<?= e(store_url('/policy')) ?>#privacy" class="hover:text-cream">Privacy</a><a href="<?= e(store_url('/policy')) ?>#terms" class="hover:text-cream">Terms</a><span>Prices include delivery · <?= business('tax.prices_include_tax') ? 'GST included' : 'GST extra' ?></span></span>
  </div></div>
</footer>
