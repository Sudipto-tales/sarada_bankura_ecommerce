<?php
$active = $active ?? '';
$title = $title ?? 'Admin';
$nav = static fn(string ...$keys): string => in_array($active, $keys, true) ? ' active' : '';
$productKeys = ['products', 'products.create', 'products.import', 'variants', 'categories', 'attributes', 'units', 'brands', 'tags', 'reviews'];
$productsOpen = in_array($active, $productKeys, true);
$inventoryOpen = str_starts_with($active, 'inventory');
$customersOpen = str_starts_with($active, 'customers');
$ordersOpen = str_starts_with($active, 'orders');
$paymentsOpen = str_starts_with($active, 'payments');

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
$nfUnread = 0;
try {
    $nfUnread = \App\Services\NotificationService::unreadCount();
} catch (\Throwable $e) {
}
$ordPending = 0;
try {
    $ordPending = (int) \Vayu\Database\DB::table('orders')->where('order_status', 'pending')->count();
} catch (\Throwable $e) {
}
// Payments (Stage 5): submissions waiting for verification + refunds owed.
$pay = ['verify' => 0, 'refund' => 0];
try {
    $pay['verify'] = (int) \Vayu\Database\DB::table('payment_attempts')->where('status', 'submitted')->count();
    $pay['refund'] = (int) (\Vayu\Database\DB::selectOne('SELECT COUNT(*) AS n FROM orders WHERE refund_due > 0')['n'] ?? 0);
} catch (\Throwable $e) {
}
// Topbar stock chips — only when inventory is actually tracked for some product.
$stk = ['tracked' => 0, 'low' => 0, 'out' => 0];
try {
    foreach (\Vayu\Database\DB::select('SELECT status, COUNT(*) AS n FROM inventory_stock GROUP BY status') as $r) {
        $stk['tracked'] += (int) $r['n'];
        if (isset($stk[$r['status']])) {
            $stk[$r['status']] = (int) $r['n'];
        }
    }
} catch (\Throwable $e) {
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="ms-base" content="<?= e(rtrim((string) (parse_url(base_url(''), PHP_URL_PATH) ?? ''), '/')) ?>">
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
    <details class="navgroup"<?= $inventoryOpen ? ' open' : '' ?>>
      <summary class="navitem<?= $inventoryOpen ? ' current' : '' ?>" title="Inventory"><i data-lucide="archive"></i><span>Inventory</span><i data-lucide="chevron-down" class="chev"></i></summary>
      <div class="subnav">
        <a class="subitem<?= $nav('inventory.overview') ?>" href="<?= route('admin.inventory') ?>">Stock overview</a>
        <a class="subitem<?= $nav('inventory.stock') ?>" href="<?= route('admin.inventory.stock') ?>">Stock management</a>
        <a class="subitem<?= $nav('inventory.movements') ?>" href="<?= route('admin.inventory.movements') ?>">Stock movements</a>
        <a class="subitem<?= $nav('inventory.alerts') ?>" href="<?= route('admin.inventory.alerts') ?>">Stock alerts</a>
      </div>
    </details>
    <details class="navgroup"<?= $ordersOpen ? ' open' : '' ?>>
      <summary class="navitem<?= $ordersOpen ? ' current' : '' ?>" title="Orders"><i data-lucide="receipt-text"></i><span>Orders</span><?php if ($ordPending): ?><em class="nav-badge"><?= $ordPending > 99 ? '99+' : $ordPending ?></em><?php endif; ?><i data-lucide="chevron-down" class="chev"></i></summary>
      <div class="subnav">
        <a class="subitem<?= $nav('orders') ?>" href="<?= route('admin.orders') ?>">All orders</a>
        <a class="subitem<?= $nav('orders.create') ?>" href="<?= route('admin.orders.create') ?>">Create order</a>
        <?php /* Stage 6: returns */ $retOpen = 0; try { $retOpen = (int) \Vayu\Database\DB::table('return_requests')->where('status', 'submitted')->count(); } catch (\Throwable $e) { } ?>
        <a class="subitem<?= $nav('orders.returns') ?>" href="<?= route('admin.returns') ?>">Returns &amp; replacements<?= $retOpen ? ' (' . $retOpen . ')' : '' ?></a>
        <a class="subitem<?= $nav('orders.policies') ?>" href="<?= route('admin.return-policies') ?>">Return policies</a>
      </div>
    </details>
    <details class="navgroup"<?= $paymentsOpen ? ' open' : '' ?>>
      <summary class="navitem<?= $paymentsOpen ? ' current' : '' ?>" title="Payments"><i data-lucide="indian-rupee"></i><span>Payments</span><?php if ($pay['verify']): ?><em class="nav-badge"><?= $pay['verify'] > 99 ? '99+' : $pay['verify'] ?></em><?php endif; ?><i data-lucide="chevron-down" class="chev"></i></summary>
      <div class="subnav">
        <a class="subitem<?= $nav('payments.verify') ?>" href="<?= route('admin.payments') ?>">To verify<?= $pay['verify'] ? ' (' . $pay['verify'] . ')' : '' ?></a>
        <a class="subitem<?= $nav('payments.received') ?>" href="<?= route('admin.payments') ?>?tab=received">Received</a>
        <a class="subitem<?= $nav('payments.requests') ?>" href="<?= route('admin.payments') ?>?tab=requests">Payment requests</a>
        <a class="subitem<?= $nav('payments.failed') ?>" href="<?= route('admin.payments') ?>?tab=failed">Failed attempts</a>
        <a class="subitem<?= $nav('payments.refunds') ?>" href="<?= route('admin.payments') ?>?tab=refunds">Refunds<?= $pay['refund'] ? ' (' . $pay['refund'] . ' due)' : '' ?></a>
        <a class="subitem<?= $nav('payments.accounts') ?>" href="<?= route('admin.payments.accounts') ?>">Receiving accounts</a>
      </div>
    </details>
    <a class="navitem" href="#" title="Enquiries"><i data-lucide="clipboard-list"></i><span>Enquiries</span><i data-lucide="chevron-down" class="chev"></i></a>
    <details class="navgroup"<?= $customersOpen ? ' open' : '' ?>>
      <summary class="navitem<?= $customersOpen ? ' current' : '' ?>" title="Customers"><i data-lucide="users"></i><span>Customers</span><i data-lucide="chevron-down" class="chev"></i></summary>
      <div class="subnav">
        <a class="subitem<?= $nav('customers') ?>" href="<?= route('admin.customers') ?>">All customers</a>
        <a class="subitem<?= $nav('customers.create') ?>" href="<?= route('admin.customers.create') ?>">Add customer</a>
        <a class="subitem<?= $nav('customers.segments') ?>" href="<?= route('admin.customers.segments') ?>">Segments</a>
        <a class="subitem<?= $nav('customers.tags') ?>" href="<?= route('admin.customers.tags') ?>">Customer tags</a>
      </div>
    </details>

    <div class="navlabel">Commerce</div>
    <a class="navitem" href="#" title="Pricing &amp; quotes"><i data-lucide="tag"></i><span>Pricing &amp; quotes</span><i data-lucide="chevron-down" class="chev"></i></a>
    <a class="navitem" href="#" title="Online store"><i data-lucide="store"></i><span>Online store</span><i data-lucide="chevron-down" class="chev"></i></a>
    <a class="navitem" href="#" title="Showroom"><i data-lucide="warehouse"></i><span>Showroom</span><i data-lucide="chevron-down" class="chev"></i></a>

    <div class="navlabel">Grow &amp; connect</div>
    <a class="navitem" href="#" title="Reports"><i data-lucide="chart-no-axes-column"></i><span>Reports</span><i data-lucide="chevron-down" class="chev"></i></a>
    <a class="navitem" href="#" title="Communication"><i data-lucide="messages-square"></i><span>Communication</span><i data-lucide="chevron-down" class="chev"></i></a>
    <a class="navitem<?= $nav('media') ?>" href="<?= route('admin.media') ?>" title="Media &amp; files"><i data-lucide="images"></i><span>Media &amp; files</span></a>
    <?php /* Settings (WhatsApp, Stage 7) — System / Payment settings are not planned yet. */ $settingsOpen = str_starts_with($active, 'settings'); ?>
    <details class="navgroup"<?= $settingsOpen ? ' open' : '' ?>>
      <summary class="navitem<?= $settingsOpen ? ' current' : '' ?>" title="Settings"><i data-lucide="settings"></i><span>Settings</span><i data-lucide="chevron-down" class="chev"></i></summary>
      <div class="subnav">
        <a class="subitem<?= $nav('settings.whatsapp') ?>" href="<?= route('admin.settings.whatsapp') ?>">WhatsApp</a>
        <span class="subitem is-soon" title="Coming soon">System <small>soon</small></span>
        <span class="subitem is-soon" title="Coming soon">Payment <small>soon</small></span>
      </div>
    </details>
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
    <?php if ($ordPending > 0): ?>
    <a class="tb-chip" href="<?= route('admin.orders') ?>?status=pending">
      <span class="tb-ic" style="background:#fff0bf;color:#91670a"><i data-lucide="receipt-text"></i></span>
      <span><small>Pending orders</small><b><?= number_format($ordPending) ?></b></span>
    </a>
    <?php endif; ?>
    <?php if ($pay['verify'] > 0): ?>
    <a class="tb-chip" href="<?= route('admin.payments') ?>">
      <span class="tb-ic" style="background:#fff0d6;color:#a2632a"><i data-lucide="scan-search"></i></span>
      <span><small>To verify</small><b><?= number_format($pay['verify']) ?></b></span>
    </a>
    <?php endif; ?>
    <?php if ($pay['refund'] > 0): ?>
    <a class="tb-chip" href="<?= route('admin.payments') ?>?tab=refunds">
      <span class="tb-ic" style="background:#fbe1dc;color:#b3483a"><i data-lucide="undo-2"></i></span>
      <span><small>Refund due</small><b><?= number_format($pay['refund']) ?></b></span>
    </a>
    <?php endif; ?>
    <?php if ($stk['tracked'] > 0): ?>
    <a class="tb-chip" href="<?= route('admin.inventory.stock') ?>?status=low">
      <span class="tb-ic" style="background:#fdeccb;color:#a66a09"><i data-lucide="trending-down"></i></span>
      <span><small>Low stock</small><b><?= number_format($stk['low']) ?></b></span>
    </a>
    <a class="tb-chip" href="<?= route('admin.inventory.stock') ?>?status=out">
      <span class="tb-ic" style="background:#f6dcd6;color:#b33b24"><i data-lucide="package-x"></i></span>
      <span><small>Out of stock</small><b><?= number_format($stk['out']) ?></b></span>
    </a>
    <?php endif; ?>
  </div>
  <div class="tb-group glass tb-right">
    <div class="tb-pill tb-date"><i data-lucide="calendar"></i><b id="tbDate">Last 30 days</b><i data-lucide="chevron-down" class="chev"></i></div>
    <div class="tb-icon" title="India · INR"><span class="flag-in"></span></div>
    <button class="tb-icon" type="button" id="nfBell" title="Notifications" aria-haspopup="dialog" aria-expanded="false" aria-controls="nfPanel"
            aria-label="Notifications<?= $nfUnread ? ', ' . $nfUnread . ' unread' : '' ?>" data-feed="<?= e(route('admin.notifications.feed')) ?>">
      <i data-lucide="bell"></i><span class="nf-badge<?= $nfUnread ? '' : ' hidden' ?>" id="nfBadge"><?= $nfUnread > 9 ? '9+' : $nfUnread ?></span>
    </button>
    <?php // Admin access: logged-in staff chip + menu (My account, Staff, Log out).
    $msMe = \App\Services\AdminAccess\Guard::user();
    $msMeRoles = $msMe ? implode(', ', array_column(\App\Services\AdminAccess\Roles::forUser((int) $msMe['id']), 'name')) : '';
    ?>
    <details class="tb-user-menu">
      <summary class="tb-user" aria-label="Account menu">
        <span class="avatar"><?= e(mb_strtoupper(mb_substr((string) ($msMe['name'] ?? 'A'), 0, 1))) ?></span>
        <span class="who"><b><?= e((string) ($msMe['name'] ?? 'Admin')) ?></b><small><?= e($msMeRoles !== '' ? mb_strimwidth($msMeRoles, 0, 28, '…') : 'Staff') ?></small></span>
        <i data-lucide="chevron-down" class="chev"></i>
      </summary>
      <div class="tb-menu-pop glass" role="menu">
        <div class="tb-menu-id"><b><?= e((string) ($msMe['name'] ?? '')) ?></b><small><?= e((string) ($msMe['email'] ?? $msMe['phone'] ?? '')) ?></small></div>
        <a role="menuitem" href="<?= route('admin.account') ?>"<?= $nav('account') ? ' class="on"' : '' ?>><i data-lucide="user-round"></i>My account</a>
        <a role="menuitem" href="<?= route('admin.account') ?>#password"><i data-lucide="key-round"></i>Change password</a>
        <?php if ($msMe && \App\Services\AdminAccess\Guard::can('staff.manage')): ?><a role="menuitem" href="<?= route('admin.staff') ?>"<?= $nav('staff') ? ' class="on"' : '' ?>><i data-lucide="users-round"></i>Staff</a><?php endif; ?>
        <form method="post" action="<?= route('admin.logout') ?>"><?= csrf_field() ?><button type="submit" role="menuitem"><i data-lucide="log-out"></i>Log out</button></form>
      </div>
    </details>
  </div>
  <div class="nf-panel" id="nfPanel" role="dialog" aria-label="Notifications" hidden>
    <div class="nf-head">
      <h3>Notifications</h3>
      <button type="button" class="linkbtn" id="nfReadAll" data-url="<?= e(route('admin.notifications.readAll')) ?>"><i data-lucide="check-check"></i>Mark all read</button>
    </div>
    <div class="nf-tabs" role="tablist">
      <button type="button" role="tab" class="on" data-tab="all" aria-selected="true">All</button>
      <button type="button" role="tab" data-tab="unread" aria-selected="false">Unread <span data-count="unread"></span></button>
      <button type="button" role="tab" data-tab="stock" aria-selected="false">Stock <span data-count="stock"></span></button>
      <button type="button" role="tab" data-tab="system" aria-selected="false">System <span data-count="system"></span></button>
    </div>
    <div class="nf-list" id="nfList" aria-live="polite"></div>
    <a class="nf-foot" href="<?= route('admin.notifications') ?>">View all notifications <i data-lucide="arrow-right"></i></a>
  </div>
</header>
