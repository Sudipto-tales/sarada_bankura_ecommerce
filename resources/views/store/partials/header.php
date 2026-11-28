<?php
/**
 * Utility bar + sticky header + mega menu + mobile drawer (design: store.js renderChrome()).
 * @var array|null $chrome @var string $active
 */
use App\Services\Order\PaymentService;
use App\Services\Store\Context;

$spaces = $chrome['spaces'] ?? [];
$origins = $chrome['origins'] ?? [];
$needs = array_values(array_filter($chrome['needs'] ?? [], fn ($t) => $t['copy'] !== ''));
$featured = $chrome['featured'] ?? null;
$cartCount = 0;
try {
    $cartCount = Context::cartCount();
} catch (\Throwable) {
}
$customer = Context::customer();
$tel = store_tel();
$on = fn (string $k) => $active === $k ? ' bg-ink/5' : '';
$q = (string) (request()->query('q') ?? '');
$inApp = \App\Services\Store\AppMode::active();
$unread = 0;
try {
    $unread = $customer ? \App\Services\Store\Inbox::unread((int) ($customer['customer_id'] ?? 0)) : 0;
} catch (\Throwable) {
}
?>
<?php if (!$inApp): ?>
<div class="bg-ink text-cream/80 text-[12.5px]"><div class="wrap h-9 flex items-center justify-between gap-4">
  <div class="flex items-center gap-5 overflow-x-auto no-scrollbar whitespace-nowrap">
    <span class="flex items-center gap-1.5"><?= store_icon('truck') ?> Delivery included in every price</span>
    <span class="hidden sm:flex items-center gap-1.5"><?= store_icon('smartphone') ?> Pay <?= (int) PaymentService::ADVANCE_PERCENT ?>% advance or full by UPI</span>
    <span class="hidden md:flex items-center gap-1.5"><?= store_icon('receipt-text') ?> <?= business('gstin') ? 'GST invoice on every order' : 'Proper bill with every order' ?></span>
  </div>
  <div class="hidden md:flex items-center gap-5 whitespace-nowrap">
    <a href="<?= e(store_url('/track')) ?>" class="hover:text-cream">Track order</a>
    <a href="<?= e(store_url('/track')) ?>#pay" class="hover:text-cream">Pay balance</a>
    <?php if ($tel !== ''): ?><a href="<?= e($tel) ?>" class="flex items-center gap-1.5 hover:text-cream"><?= store_icon('phone') ?> Showroom</a><?php endif; ?>
  </div>
</div></div>
<?php endif; ?>

<header class="sticky top-0 z-30 bg-cream/90 backdrop-blur border-b border-ink/10">
  <div class="wrap h-[72px] flex items-center gap-4 lg:gap-8">
    <button type="button" id="menuBtn" class="lg:hidden -ml-1 p-2 rounded-lg hover:bg-ink/5" aria-label="Open menu" aria-controls="drawer" aria-expanded="false"><?= store_icon('menu', '!w-6 !h-6') ?></button>
    <a href="<?= e(store_url('/')) ?>" class="flex items-center gap-2 sm:gap-2.5 shrink-0" aria-label="<?= e(business('name')) ?> home">
      <img src="<?= e(store_asset('image/logo-mark.webp')) ?>" alt="" class="h-8 sm:h-11 w-auto" width="44" height="44">
      <span class="block leading-tight"><span class="block font-display text-[17px] sm:text-[19px] tracking-tight">Maa Sarada</span><span class="hidden sm:block text-[10.5px] uppercase tracking-[.18em] text-ink-500">Marble &amp; Sanitation · <?= e(business('address.city', 'Bankura')) ?></span></span>
    </a>

    <nav class="hidden lg:flex items-center gap-1 text-[14px] font-medium" aria-label="Main">
      <?php if ($spaces !== []): ?>
      <div class="has-mega">
        <a href="<?= e(store_url('/shop')) ?>" class="flex items-center gap-1 px-3 py-2 rounded-full hover:bg-ink/5<?= $on('shop') ?>">Shop by space <?= store_icon('chevron-down', '!w-4 !h-4') ?></a>
        <div class="mega absolute left-0 right-0 top-full"><div class="wrap"><div class="bg-white rounded-b-2xl shadow-card border border-ink/10 border-t-0 p-7 grid grid-cols-12 gap-8">
          <?php foreach (array_slice($spaces, 0, 3) as $c): ?>
          <div class="col-span-2">
            <a href="<?= e(store_url('/shop/' . $c['slug'])) ?>" class="font-semibold text-[15px] hover:text-teal"><?= e($c['name']) ?></a>
            <ul class="mt-3 space-y-1">
              <?php foreach ($c['children'] as $s): ?>
              <li><a href="<?= e(store_url('/shop/' . $c['slug'] . '/' . $s['slug'])) ?>" class="flex items-center justify-between gap-3 py-1.5 px-2 -mx-2 rounded-lg hover:bg-cream text-[14px] text-ink-500 hover:text-ink"><?= e($s['name']) ?><span class="text-[11.5px] text-ink-300"><?= (int) $s['count'] ?></span></a></li>
              <?php endforeach; ?>
              <li><a href="<?= e(store_url('/shop/' . $c['slug'])) ?>" class="block py-1.5 text-[13px] font-semibold text-teal">All <?= e(mb_strtolower($c['name'])) ?> →</a></li>
            </ul>
          </div>
          <?php endforeach; ?>
          <div class="col-span-2">
            <?php if ($origins !== []): ?>
            <p class="font-semibold text-[15px]">By origin</p>
            <ul class="mt-3 space-y-1">
              <?php foreach ($origins as $c): ?>
              <li><a href="<?= e(store_url('/shop/' . $c['slug'])) ?>" class="flex items-center justify-between py-1.5 px-2 -mx-2 rounded-lg hover:bg-cream text-[14px] text-ink-500 hover:text-ink"><?= e($c['name']) ?><span class="text-[11.5px] text-ink-300"><?= (int) $c['count'] ?></span></a></li>
              <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <?php if ($needs !== []): ?>
            <p class="font-semibold text-[15px] mt-5">By need</p>
            <div class="mt-2 flex flex-wrap gap-1.5">
              <?php foreach ($needs as $t): ?><a href="<?= e(store_url('/shop?tag[]=' . rawurlencode($t['slug']))) ?>" class="text-[12px] px-2.5 py-1 rounded-full bg-cream hover:bg-cream-300"><?= e($t['name']) ?></a><?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php if ($featured): ?>
          <a href="<?= e(store_url($featured['url'])) ?>" class="col-span-<?= count($spaces) >= 3 ? '4' : '6' ?> relative rounded-2xl overflow-hidden min-h-[230px] group bg-ink">
            <?= store_img($featured['image_alt'] ?? $featured['image'], $featured['name'], 'absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition duration-700', true, true, true) ?>
            <div class="absolute inset-0 bg-gradient-to-t from-ink/80 to-transparent"></div>
            <div class="absolute left-5 bottom-5 text-cream"><p class="text-[11px] uppercase tracking-[.16em] text-cream/70">Featured</p><p class="font-display text-[24px]"><?= e($featured['name']) ?></p><p class="text-[13px] text-cream/80"><?= e(store_price_text($featured)) ?></p></div>
          </a>
          <?php endif; ?>
        </div></div></div>
      </div>
      <?php else: ?>
      <a href="<?= e(store_url('/shop')) ?>" class="px-3 py-2 rounded-full hover:bg-ink/5<?= $on('shop') ?>">Shop</a>
      <?php endif; ?>
      <?php foreach ($origins as $c): ?>
      <a href="<?= e(store_url('/shop/' . $c['slug'])) ?>" class="px-3 py-2 rounded-full hover:bg-ink/5"><?= e($c['name']) ?></a>
      <?php endforeach; ?>
      <a href="<?= e(store_url('/brands')) ?>" class="px-3 py-2 rounded-full hover:bg-ink/5<?= $on('brands') ?>">Brands</a>
      <a href="<?= e(store_url('/shop?offer=1')) ?>" class="px-3 py-2 rounded-full hover:bg-ink/5 text-copper">Offers</a>
      <a href="<?= e(store_url('/calculator')) ?>" class="px-3 py-2 rounded-full hover:bg-ink/5<?= $on('plan') ?>">Plan your space</a>
    </nav>

    <form action="<?= e(store_url('/shop')) ?>" method="get" class="relative flex-1 max-w-md ml-auto hidden md:block" role="search">
      <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-ink-400"><?= store_icon('search') ?></span>
      <input name="q" type="search" autocomplete="off" value="<?= e($q) ?>" placeholder="Search stone, room or need…" aria-label="Search" class="w-full h-11 pl-10 pr-4 rounded-full bg-white border border-ink/10 focus:border-teal focus:ring-4 focus:ring-teal/10 outline-none text-[14px]">
    </form>

    <div class="flex items-center gap-1 ml-auto md:ml-0">
      <a href="<?= e(store_url('/shop')) ?>" class="md:hidden p-2.5 rounded-full hover:bg-ink/5" aria-label="Search"><?= store_icon('search') ?></a>
      <?php if ($customer): ?><a href="<?= e(store_url('/account/notifications')) ?>" class="relative p-2.5 rounded-full hover:bg-ink/5" aria-label="Notifications<?= $unread ? ', ' . $unread . ' new' : '' ?>"><?= store_icon('bell') ?><b data-count="inbox" class="<?= $unread ? 'grid' : 'hidden' ?> absolute top-0.5 right-0.5 min-w-[17px] h-[17px] px-1 rounded-full bg-copper text-white text-[10px] place-items-center"><?= $unread > 9 ? '9+' : $unread ?></b></a><?php endif; ?>
      <a href="<?= e(store_url('/saved')) ?>" class="app-hide relative p-2.5 rounded-full hover:bg-ink/5" aria-label="Saved"><?= store_icon('heart') ?><b data-count="saved" class="hidden absolute top-0.5 right-0.5 min-w-[17px] h-[17px] px-1 rounded-full bg-teal text-white text-[10px] place-items-center">0</b></a>
      <a href="<?= e(store_url('/cart')) ?>" class="app-hide relative p-2.5 rounded-full hover:bg-ink/5" aria-label="Bag<?= $cartCount ? ', ' . $cartCount . ' items' : '' ?>"><?= store_icon('shopping-bag') ?><b data-count="bag" class="<?= $cartCount ? 'grid' : 'hidden' ?> absolute top-0.5 right-0.5 min-w-[17px] h-[17px] px-1 rounded-full bg-copper text-white text-[10px] place-items-center"><?= (int) $cartCount ?></b></a>
      <a href="<?= e(store_url($customer ? '/account' : '/signin')) ?>" class="hidden lg:flex items-center gap-2 ml-1 pl-3 pr-4 h-10 rounded-full border border-ink/15 hover:border-ink/40 text-[13.5px] font-medium<?= $active === 'account' ? ' border-ink/40' : '' ?>"><?= store_icon('user-round') ?> <?= $customer ? 'Account' : 'Sign in' ?></a>
    </div>
  </div>
</header>

<div id="drawer" class="fixed inset-0 z-50 hidden" role="dialog" aria-modal="true" aria-label="Menu">
  <div class="absolute inset-0 bg-ink/40" data-close></div>
  <div class="absolute left-0 top-0 bottom-0 w-[86%] max-w-sm bg-cream p-5 overflow-y-auto">
    <div class="flex items-center justify-between mb-5"><span class="font-display text-xl">Shop</span><button type="button" class="p-2 rounded-full hover:bg-ink/5" data-close aria-label="Close menu"><?= store_icon('x') ?></button></div>
    <form action="<?= e(store_url('/shop')) ?>" method="get" role="search" class="relative mb-4">
      <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-ink-400"><?= store_icon('search') ?></span>
      <input name="q" type="search" placeholder="Search stone, room or need…" aria-label="Search" class="w-full h-11 pl-10 pr-4 rounded-full bg-white border border-ink/10 outline-none text-[14px]">
    </form>
    <div class="space-y-2">
      <?php foreach ($spaces as $c): ?>
      <details class="bg-white rounded-xl px-4 py-3">
        <summary class="flex items-center justify-between font-semibold cursor-pointer list-none"><?= e($c['name']) ?><?= store_icon('chevron-down') ?></summary>
        <ul class="mt-2">
          <?php foreach ($c['children'] as $s): ?>
          <li><a href="<?= e(store_url('/shop/' . $c['slug'] . '/' . $s['slug'])) ?>" class="flex justify-between py-2 text-ink-500"><?= e($s['name']) ?><span class="text-ink-300 text-[12px]"><?= (int) $s['count'] ?></span></a></li>
          <?php endforeach; ?>
          <li><a href="<?= e(store_url('/shop/' . $c['slug'])) ?>" class="block py-2 font-semibold text-teal text-[13px]">All <?= e(mb_strtolower($c['name'])) ?></a></li>
        </ul>
      </details>
      <?php endforeach; ?>
      <?php foreach ($origins as $c): ?>
      <a href="<?= e(store_url('/shop/' . $c['slug'])) ?>" class="flex justify-between bg-white rounded-xl px-4 py-3 font-semibold"><?= e($c['name']) ?><span class="text-ink-300 text-[12px] font-normal"><?= (int) $c['count'] ?></span></a>
      <?php endforeach; ?>
    </div>
    <div class="mt-6 grid gap-1 text-[15px]">
      <a href="<?= e(store_url('/brands')) ?>" class="py-2">Brands</a>
      <a href="<?= e(store_url('/shop?offer=1')) ?>" class="py-2 text-copper">Offers</a>
      <a href="<?= e(store_url('/calculator')) ?>" class="py-2">Plan your space</a>
      <a href="<?= e(store_url('/track')) ?>" class="py-2">Track order</a>
      <a href="<?= e(store_url($customer ? '/account' : '/signin')) ?>" class="py-2"><?= $customer ? 'Account' : 'Sign in' ?></a>
      <a href="<?= e(store_url('/policy')) ?>" class="py-2">Policies</a>
    </div>
  </div>
</div>
