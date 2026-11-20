<?php
$active = $active ?? '';
$title = $title ?? 'Admin';
$nav = static fn(string ...$keys): string => in_array($active, $keys, true) ? ' active' : '';
$productKeys = ['products', 'products.create', 'products.import', 'variants', 'categories', 'attributes', 'units', 'brands', 'tags', 'reviews'];
$productsOpen = in_array($active, $productKeys, true);

// Topbar quick counts (cheap COUNT queries; never break the page if the DB is unavailable).
$hdr = ['products' => 0, 'draft' => 0, 'coming_soon' => 0];
try {
    foreach (\Vayu\Database\DB::select('SELECT status, COUNT(*) AS n FROM products GROUP BY status') as $r) {
        $hdr['products'] += (int) $r['n'];
        if (isset($hdr[$r['status']])) {
            $hdr[$r['status']] = (int) $r['n'];
        }
    }
} catch (\Throwable $e) {
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($title) ?> · Maa Sarada</title>
<link rel="icon" type="image/png" href="<?= base_url('assets/image/favicon.png') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
<script>try{if(localStorage.getItem('ms.sidebar')==='mini')document.documentElement.classList.add('sb-mini')}catch(e){}</script>
<script src="https://unpkg.com/lucide@0.544.0/dist/umd/lucide.min.js"></script>
</head>
<body>
<div class="sb-backdrop" onclick="document.body.classList.remove('sb-open')"></div>
<aside class="sidebar glass" id="sidebar">
  <button class="sb-collapse" type="button" onclick="msToggleSidebar()" aria-label="Collapse sidebar"><i data-lucide="chevron-left"></i></button>
  <a class="brand" href="<?= route('admin.dashboard') ?>">
    <div class="brandmark"><i data-lucide="gem"></i></div>
    <div class="brandname">Maa Sarada</div>
    <div class="brandtag">Marble &amp; stone, in harmony.</div>
  </a>
  <nav>
    <div class="navlabel">Operate</div>
    <a class="navitem<?= $nav('overview') ?>" href="<?= route('admin.dashboard') ?>" title="Overview"><i data-lucide="house"></i><span>Overview</span></a>

    <details class="navgroup"<?= $productsOpen ? ' open' : '' ?>>
      <summary class="navitem<?= $productsOpen ? ' current' : '' ?>" title="Products"><i data-lucide="package"></i><span>Products</span><i data-lucide="chevron-down" class="chev"></i></summary>
      <div class="subnav">
        <a class="subitem<?= $nav('products') ?>" href="<?= route('admin.products.index') ?>">All products</a>
        <a class="subitem<?= $nav('products.create') ?>" href="<?= route('admin.products.create') ?>">Add product</a>
        <a class="subitem<?= $nav('products.import') ?>" href="<?= route('admin.products.import') ?>">Bulk upload</a>
        <a class="subitem<?= $nav('variants') ?>" href="<?= route('admin.variants') ?>">Variants &amp; options</a>
        <a class="subitem<?= $nav('categories') ?>" href="<?= route('admin.categories') ?>">Categories</a>
        <a class="subitem<?= $nav('attributes') ?>" href="<?= route('admin.attributes') ?>">Attributes</a>
        <a class="subitem<?= $nav('units') ?>" href="<?= route('admin.units') ?>">Units</a>
        <a class="subitem<?= $nav('brands') ?>" href="<?= route('admin.brands') ?>">Brands</a>
        <a class="subitem<?= $nav('tags') ?>" href="<?= route('admin.tags') ?>">Tags</a>
        <a class="subitem<?= $nav('reviews') ?>" href="<?= route('admin.reviews') ?>">Reviews &amp; ratings</a>
      </div>
    </details>
    <a class="navitem" href="#" title="Enquiries"><i data-lucide="clipboard-list"></i><span>Enquiries</span><i data-lucide="chevron-down" class="chev"></i></a>
    <a class="navitem" href="#" title="Customers"><i data-lucide="users"></i><span>Customers</span><i data-lucide="chevron-down" class="chev"></i></a>

    <div class="navlabel">Commerce</div>
    <a class="navitem" href="#" title="Pricing &amp; quotes"><i data-lucide="tag"></i><span>Pricing &amp; quotes</span><i data-lucide="chevron-down" class="chev"></i></a>
    <a class="navitem" href="#" title="Online store"><i data-lucide="store"></i><span>Online store</span><i data-lucide="chevron-down" class="chev"></i></a>
    <a class="navitem" href="#" title="Showroom"><i data-lucide="warehouse"></i><span>Showroom</span><i data-lucide="chevron-down" class="chev"></i></a>

    <div class="navlabel">Grow &amp; connect</div>
    <a class="navitem" href="#" title="Reports"><i data-lucide="chart-no-axes-column"></i><span>Reports</span><i data-lucide="chevron-down" class="chev"></i></a>
    <a class="navitem" href="#" title="Communication"><i data-lucide="messages-square"></i><span>Communication</span><i data-lucide="chevron-down" class="chev"></i></a>
    <a class="navitem" href="#" title="Media &amp; files"><i data-lucide="images"></i><span>Media &amp; files</span><i data-lucide="chevron-down" class="chev"></i></a>
    <a class="navitem" href="#" title="Settings"><i data-lucide="settings"></i><span>Settings</span></a>
  </nav>
</aside>
<main>
<header class="topbar">
  <button class="tb-menu glass" type="button" onclick="document.body.classList.add('sb-open')" aria-label="Open menu"><i data-lucide="menu"></i></button>
  <div class="tb-group glass tb-left">
    <form class="search" method="get" action="<?= route('admin.products.index') ?>">
      <i data-lucide="search"></i>
      <input name="q" placeholder="Search products, SKUs, enquiries…" autocomplete="off" id="globalSearch">
      <kbd><i data-lucide="command"></i> K</kbd>
    </form>
    <a class="tb-chip" href="<?= route('admin.products.index') ?>">
      <span class="tb-ic" style="background:#dff3e9;color:#1f7a53"><i data-lucide="package"></i></span>
      <span><small>Products</small><b><?= number_format($hdr['products']) ?></b></span>
    </a>
    <a class="tb-chip" href="<?= route('admin.products.index') ?>?status=draft">
      <span class="tb-ic" style="background:#ffe8a1;color:#91670a"><i data-lucide="file-pen"></i></span>
      <span><small>Drafts</small><b><?= number_format($hdr['draft']) ?></b></span>
    </a>
    <a class="tb-chip" href="<?= route('admin.products.index') ?>?status=coming_soon">
      <span class="tb-ic" style="background:#fbe1dc;color:#c2523c"><i data-lucide="clock"></i></span>
      <span><small>Coming soon</small><b><?= number_format($hdr['coming_soon']) ?></b></span>
    </a>
  </div>
  <div class="tb-group glass tb-right">
    <div class="tb-pill tb-date"><i data-lucide="calendar"></i><b id="tbDate">Last 30 days</b><i data-lucide="chevron-down" class="chev"></i></div>
    <div class="tb-icon" title="India · INR"><span class="flag-in"></span></div>
    <button class="tb-icon" type="button" title="Notifications"><i data-lucide="bell"></i><span class="dot"></span></button>
    <div class="tb-user">
      <span class="avatar">A</span>
      <span class="who"><b>Admin</b><small>Store Owner</small></span>
      <i data-lucide="chevron-down" class="chev"></i>
    </div>
  </div>
</header>
