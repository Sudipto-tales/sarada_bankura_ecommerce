<?php
/**
 * Storefront layout (docs/storefront.md). Vars: $content (rendered page), $page (meta), $chrome (Catalog::chrome() or null).
 * @var string $content @var array $page @var array|null $chrome
 */
use App\Services\Store\AppMode;
use App\Services\Store\Context;

$siteName = (string) business('name', 'Maa Sarada Marble & Stone');
$title = $page['title'] ? $page['title'] . ' · ' . $siteName : $siteName . ' — ' . business('address.city', 'Bankura');
$description = $page['description'] ?: 'Italian and Indian marble, granite and onyx for floors, walls and countertops. Showroom in '
    . business('address.city', 'Bankura') . ', ' . business('address.state', 'West Bengal') . '. Delivery included, UPI payments.';
$minimal = $page['layout'] === 'minimal';
$inApp = AppMode::active();
$tabbar = !$minimal && ($page['tabbar'] ?? true);
$flash = [];
foreach (['success', 'error', 'warning', 'info'] as $k) {
    if (session()->has($k)) {
        $flash[] = ['type' => $k, 'message' => (string) session()->get($k)];
    }
}
?><!doctype html>
<html lang="en-IN"<?= $inApp ? ' class="is-app"' : '' ?> data-sw="<?= e(store_url('/service-worker')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover<?= $inApp ? ', maximum-scale=1, user-scalable=no' : '' ?>">
<?php if ($inApp): ?><meta name="format-detection" content="telephone=no"><?php endif; ?>
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($description) ?>">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<?php if ($page['canonical']): ?><link rel="canonical" href="<?= e($page['canonical']) ?>"><?php endif; ?>
<?php if (Context::previewing()): ?><meta name="robots" content="noindex"><?php endif; ?>
<meta property="og:site_name" content="<?= e($siteName) ?>">
<meta property="og:title" content="<?= e($page['title'] ?: $siteName) ?>">
<meta property="og:description" content="<?= e($description) ?>">
<meta name="theme-color" content="#F6F2EA">
<link rel="icon" href="<?= e(store_asset('image/logo.webp')) ?>">
<link rel="preload" href="<?= e(base_url('assets/vendor/fonts/manrope-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?= e(base_url('assets/vendor/fonts/fraunces-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(store_asset('css/store.css')) ?>">
<script type="speculationrules"><?= store_json(['prefetch' => [[
    'where' => ['and' => [
        ['href_matches' => '/*'],
        ['not' => ['href_matches' => ['/checkout*', '/pay/*', '/order/*', '/cart*', '/account*', '/signin*', '/signout', '/admin*', '/app/download*']]],
        ['not' => ['selector_matches' => '[rel~=nofollow], [data-no-prefetch], [target], [download]']],
    ]],
    'eagerness' => 'moderate',
]]]) ?></script>
<?= $page['head'] ?>
</head>
<body class="font-sans text-[15px]<?= $tabbar ? ' has-tabbar' : ($minimal ? '' : ' pb-20 lg:pb-0') ?>"<?= !empty($page['body_attrs']) ? ' ' . $page['body_attrs'] : '' ?>>
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 bg-ink text-cream px-3 py-2 rounded">Skip to content</a>
<?php if (Context::previewing()): ?>
<div class="bg-copper text-white text-[12.5px]"><div class="wrap py-2 flex flex-wrap items-center justify-between gap-2">
  <span class="flex items-center gap-2"><?= store_icon('eye') ?> <b>Preview</b> — the public still sees the coming-soon page. Set <code class="bg-white/15 px-1.5 rounded">STOREFRONT_ENABLED=true</code> to launch.</span>
  <a href="<?= e(store_url('/admin')) ?>" class="underline underline-offset-2">Back to admin</a>
</div></div>
<?php endif; ?>
<?= store_partial($minimal ? 'header-minimal' : 'header', ['chrome' => $chrome, 'active' => $page['active']]) ?>
<?php if (!$minimal && !$inApp && ($page['active'] ?? '') !== 'app' && \App\Services\AppReleases::available()): ?>
<div id="appBanner" class="lg:hidden" hidden><div class="wrap pt-3"><div class="flex items-center gap-3 rounded-2xl bg-white border border-ink/5 shadow-card px-3 py-2.5">
  <img src="<?= e(store_asset('image/logo.webp')) ?>" alt="" class="w-10 h-10 rounded-xl object-contain bg-cream" width="40" height="40">
  <p class="flex-1 min-w-0 leading-tight"><b class="text-[14px] block truncate">Get the <?= e(business('name')) ?> app</b><span class="text-[12px] text-ink-500">Faster, and works on a weak signal</span></p>
  <a href="<?= e(store_url('/app')) ?>" class="btn btn-dark !h-9 !px-4 text-[13px]">Get</a>
  <button type="button" data-app-banner-close class="w-8 h-8 -mr-1 rounded-full grid place-items-center text-ink-400 hover:bg-ink/5" aria-label="Not now"><?= store_icon('x') ?></button>
</div></div></div>
<?php endif; ?>
<main id="main"><?= $content ?></main>
<?= store_partial($minimal ? 'footer-minimal' : 'footer', ['chrome' => $chrome, 'active' => $page['active']]) ?>
<?= store_partial('app-shell', ['tabbar' => $tabbar, 'active' => $page['active']]) ?>
<div id="toast" class="toast" role="status" aria-live="polite"></div>
<script type="application/json" id="store-flash"><?= store_json($flash) ?></script>
<?php $acct = Context::customer(); ?>
<script type="application/json" id="store-account"><?= store_json($acct && $acct['customer_id'] ? ['saved' => \App\Services\Store\Saved::slugs((int) $acct['customer_id']), 'url' => (string) route('store.saved.toggle')] : null) ?></script>
<script src="<?= e(store_asset('vendor/lucide/lucide-0.544.0.min.js')) ?>" defer></script>
<script src="<?= e(store_asset('js/store.js')) ?>" defer></script>
<?= $page['scripts'] ?>
</body>
</html>
