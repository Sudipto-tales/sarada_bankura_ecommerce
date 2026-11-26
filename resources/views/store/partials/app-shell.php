<?php
/**
 * Floating glass tab bar, offline pill, navigation progress and pull-to-refresh (docs/mobile-app.md).
 * @var bool $tabbar @var string $active
 */
use App\Services\Store\AppMode;
use App\Services\Store\Context;

$customer = Context::customer();
$cartCount = 0;
try {
    $cartCount = Context::cartCount();
} catch (\Throwable) {
}
$tabs = [
    ['/', 'house', 'Home', 'home'],
    ['/shop', 'layout-grid', 'Shop', 'shop'],
    ['/saved', 'heart', 'Saved', 'saved'],
    ['/cart', 'shopping-bag', 'Bag', 'bag'],
    [$customer ? '/account' : '/signin', 'user-round', $customer ? 'Account' : 'Sign in', 'account'],
];
?>
<div id="navProgress" class="np" aria-hidden="true"></div>
<div id="netBar" class="netbar" role="status" hidden><?= store_icon('wifi-off') ?> You’re offline · showing saved pages</div>
<?php if (AppMode::active()): ?><div id="ptr" class="ptr" aria-hidden="true"><?= store_icon('rotate-cw') ?></div><?php endif; ?>
<?php if ($tabbar): ?>
<nav id="tabBar" class="tabbar" aria-label="Quick">
  <?php foreach ($tabs as [$href, $icon, $label, $key]): $on = $active === $key; ?>
  <a href="<?= e(store_url($href)) ?>" class="tab-i"<?= $on ? ' aria-current="page"' : '' ?>>
    <span class="relative"><?= store_icon($icon) ?><?php if ($key === 'bag'): ?><b data-count="bag" class="<?= $cartCount ? 'grid' : 'hidden' ?> tab-badge bg-copper"><?= (int) $cartCount ?></b><?php elseif ($key === 'saved'): ?><b data-count="saved" class="hidden tab-badge bg-teal">0</b><?php endif; ?></span>
    <span><?= e($label) ?></span>
  </a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
