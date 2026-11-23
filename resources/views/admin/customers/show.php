<?php
use App\Services\Customer\CustomerService as CS;
use App\Services\Customer\Phone;

$active = 'customers';
$title = (string) $c['name'];
include __DIR__ . '/_helpers.php';
$cid = (int) $c['id'];
$statusRev = ['pending' => 'warn', 'published' => 'ok', 'flagged' => 'bad', 'archived' => 'off'];

include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs"><a href="<?= route('admin.customers') ?>">Customers</a> <i data-lucide="chevron-right"></i> <?= e((string) $c['name']) ?></div><h1>Customer<span style="color:#dca71f">.</span></h1></div>
  <div class="flex gap-2">
    <a class="btn" href="tel:+91<?= e((string) $c['phone']) ?>"><i data-lucide="phone" style="width:16px"></i>Call</a>
    <a class="btn" href="https://wa.me/91<?= e((string) $c['phone']) ?>" target="_blank" rel="noopener"><i data-lucide="message-circle" style="width:16px"></i>WhatsApp</a>
    <a class="btn gold" href="<?= route('admin.customers.edit', ['id' => $cid]) ?>"><i data-lucide="pencil" style="width:16px"></i>Edit</a>
  </div>
</div>

<section class="card cu-hero">
  <?= $cuAvatar($c, 'lg') ?>
  <div class="min-w-0">
    <h2><?= e((string) $c['name']) ?><?php if ($c['company']): ?> <small><?= e((string) $c['company']) ?></small><?php endif; ?></h2>
    <div class="cu-hero-meta">
      <span><i data-lucide="smartphone"></i><?= e(Phone::format((string) $c['phone'])) ?></span>
      <?php if ($c['email']): ?><span><i data-lucide="mail"></i><a href="mailto:<?= e((string) $c['email']) ?>"><?= e((string) $c['email']) ?></a></span><?php endif; ?>
      <span><i data-lucide="calendar"></i>Customer since <?= $cuDate($c['created_at']) ?></span>
    </div>
    <div class="cu-hero-badges"><?= $cuType((string) $c['customer_type']) ?> <?= $cuLife((string) $c['lifecycle']) ?> <?= $cuStatus((string) $c['status']) ?>
      <?= (int) $c['is_online'] ? '<span class="pill" style="background:#e3eef8;color:#3d6a92">Online account</span>' : '<span class="pill pill-off">Offline</span>' ?>
      <?= (int) $c['marketing_consent'] ? '<span class="pill pill-gold">Subscribed</span>' : '' ?></div>
  </div>
</section>

<div class="cu-detail">
  <div class="stack" style="gap:18px">
    <section class="card">
      <div class="card-h"><div><div class="eyebrow">Purchases</div><h3>Order summary</h3></div><span class="text-xs muted">Delivered orders only</span></div>
      <div class="cu-stats four">
        <div class="t-gold"><small>Orders</small><b><?= (int) $c['total_orders'] ?></b></div>
        <div class="t-green"><small>Total spent</small><b><?= $cuMoney($c['total_spent']) ?></b></div>
        <div class="t-blue"><small>Avg. order value</small><b><?= $cuMoney($aov) ?></b></div>
        <div class="t-sand"><small>Last order</small><b><?= $cuDate($c['last_order_at']) ?></b></div>
      </div>
    </section>

    <section class="card">
      <div class="card-h"><div><div class="eyebrow">Delivery &amp; billing</div><h3>Addresses</h3></div><a class="linkbtn" href="<?= route('admin.customers.edit', ['id' => $cid]) ?>">Manage <i data-lucide="arrow-right"></i></a></div>
      <?php if ($addresses): ?>
      <div class="cu-addr-grid">
        <?php foreach ($addresses as $a): ?>
        <article class="cu-addr-card<?= (int) $a['is_default'] ? ' default' : '' ?>">
          <div class="cu-addr-card-h"><b><?= e((string) ($a['label'] ?: 'Address')) ?></b>
            <?= (int) $a['is_default'] ? '<span class="pill pill-gold">Default</span>' : '' ?><span class="pill pill-off"><?= e(CS::ADDRESS_TYPES[$a['type']] ?? $a['type']) ?></span></div>
          <?php $lines = array_filter([
              (string) $a['address_line1'], (string) $a['address_line2'],
              implode(', ', array_filter([$a['area'], $a['post_office'], $a['city']])),
              trim(implode(', ', array_filter([$a['district'] !== $a['city'] ? $a['district'] : null, $a['state']])) . ' ' . $a['pincode']),
          ], static fn($l) => trim($l) !== ''); ?>
          <p><?= implode('<br>', array_map('e', $lines)) ?></p>
          <?php if ($a['name'] || $a['phone']): ?><small class="muted"><i data-lucide="user-round" style="width:12px;vertical-align:-2px"></i> <?= e(trim($a['name'] . ' ' . Phone::format((string) $a['phone']))) ?></small><?php endif; ?>
        </article>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="cu-empty sm"><b>No address yet</b><small>Add one so orders and invoices can be filled in.</small><a class="btn" href="<?= route('admin.customers.edit', ['id' => $cid]) ?>">Add address</a></div>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-h"><div><div class="eyebrow">Feedback</div><h3>Reviews</h3></div><span class="text-xs muted">Matched by mobile number</span></div>
      <?php if ($reviews): ?>
      <div class="cu-reviews">
        <?php foreach ($reviews as $r): ?>
        <a class="cu-review" href="<?= route('admin.reviews') ?>?status=<?= e((string) $r['status']) ?>">
          <span class="stars"><?= str_repeat('★', (int) $r['rating']) ?><i><?= str_repeat('★', 5 - (int) $r['rating']) ?></i></span>
          <span class="min-w-0"><b><?= e((string) $r['title']) ?></b><small class="block muted text-xs"><?= e((string) ($r['product_name'] ?? 'Deleted product')) ?> · <?= $cuDate($r['created_at']) ?></small></span>
          <span class="status-dot <?= $statusRev[$r['status']] ?? 'off' ?>"><?= e(ucfirst((string) $r['status'])) ?></span>
        </a>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <p class="text-sm muted" style="margin:0">No reviews from this mobile yet.</p>
      <?php endif; ?>
    </section>
  </div>

  <div class="stack" style="gap:18px">
    <section class="card">
      <div class="card-h"><h3>Profile</h3></div>
      <dl class="kv">
        <dt>Type</dt><dd><?= e(CS::TYPES[$c['customer_type']] ?? $c['customer_type']) ?></dd>
        <dt>Firm</dt><dd><?= e((string) ($c['company'] ?: '—')) ?></dd>
        <dt>GSTIN</dt><dd><?= e((string) ($c['gst_number'] ?: '—')) ?></dd>
        <dt>Joined via</dt><dd><?= e(CS::SOURCES[$c['source']] ?? $c['source']) ?></dd>
        <dt>Updated</dt><dd><?= $cuDate($c['updated_at'], 'j M Y, H:i') ?></dd>
        <dt>Customer ID</dt><dd>#<?= $cid ?></dd>
      </dl>
    </section>

    <section class="card">
      <div class="card-h"><h3>Tags</h3><a class="linkbtn" href="<?= route('admin.customers.edit', ['id' => $cid]) ?>">Edit</a></div>
      <div class="cu-tags"><?php foreach ($tags as $t): ?><a href="<?= route('admin.customers') ?>?tag=<?= (int) $t['id'] ?>"><?= $cuTag($t) ?></a><?php endforeach; ?><?= $tags ? '' : '<span class="text-sm muted">No tags</span>' ?></div>
    </section>

    <section class="card">
      <div class="card-h"><h3>Segments</h3><a class="linkbtn" href="<?= route('admin.customers.segments') ?>">All segments</a></div>
      <?php if ($segments): ?>
      <div class="cu-seglist"><?php foreach ($segments as $s): ?>
        <a href="<?= route('admin.customers.segments.show', ['id' => $s['id']]) ?>"><i data-lucide="<?= $s['type'] === 'static' ? 'list-checks' : 'sparkles' ?>"></i><?= e((string) $s['name']) ?></a>
      <?php endforeach; ?></div>
      <?php else: ?><p class="text-sm muted" style="margin:0">Not in any segment yet.</p><?php endif; ?>
    </section>

    <section class="card">
      <div class="card-h"><h3>Online account</h3><?= $user ? '<span class="pill">Linked</span>' : '<span class="pill pill-off">None</span>' ?></div>
      <?php if ($user): ?>
      <dl class="kv"><dt>Email</dt><dd><?= e((string) ($user['email'] ?? '—')) ?></dd><dt>Status</dt><dd><?= e(ucfirst((string) $user['status'])) ?></dd><dt>Registered</dt><dd><?= $cuDate($user['created_at']) ?></dd><dt>Last sign-in</dt><dd><?= $cuDate($user['last_login_at'], 'j M Y, H:i') ?></dd></dl>
      <?php else: ?><p class="text-sm muted" style="margin:0">Links automatically when this mobile signs up on the online store.</p><?php endif; ?>
    </section>

    <section class="card">
      <div class="card-h"><h3>Notes</h3></div>
      <?php if ($c['notes']): ?><p class="cu-notes"><?= nl2br(e((string) $c['notes'])) ?></p><?php else: ?><p class="text-sm muted" style="margin:0">No notes.</p><?php endif; ?>
    </section>

    <button type="button" class="btn ghost danger-text" style="justify-content:center" data-del="<?= e(route('admin.customers.delete', ['id' => $cid])) ?>" data-del-name="<?= e((string) $c['name']) ?>"><i data-lucide="trash-2" style="width:16px"></i>Delete customer</button>
  </div>
</div>
<?php
$scripts = '<script>msBindDelete();</script>';
include __DIR__ . '/../partials/footer.php';
