<?php
use App\Controllers\Admin\BrandController;

$active = 'brands';
$title = 'Brands';
$rows = $rows ?? [];
$stats = $stats ?? ['active' => 0, 'products' => 0, 'unbranded' => 0, 'featured' => 0, 'countries' => 0];
$success = $success ?? null;
$error = $error ?? null;
$avatarTone = ['#fff0bf;color:#91670a', '#e3eef8;color:#3d6a92', '#efe7f6;color:#6f4a92', '#e4f4e9;color:#267a55', '#fbe1dc;color:#b3483a'];
$initials = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $s = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $s .= mb_strtoupper(mb_substr($p, 0, 1));
    }

    return $s ?: '?';
};
$stars = static function (?float $avg): string {
    if (!$avg) {
        return '<span class="muted text-xs">No reviews</span>';
    }
    $full = (int) round($avg);

    return '<span class="stars">' . str_repeat('★', $full) . '<i>' . str_repeat('★', 5 - $full) . '</i></span> <b>' . number_format($avg, 1) . '</b>';
};
$maxProducts = max(1, ...array_map(static fn($r) => (int) $r['product_count'], $rows ?: [['product_count' => 0]]));
$statusCls = ['active' => 'ok', 'draft' => 'warn', 'archived' => 'off'];

include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs">Catalog <i data-lucide="chevron-right"></i> Brands</div><h1>Brands<span style="color:#dca71f">.</span></h1></div>
  <div class="flex gap-2">
    <button class="btn" type="button" onclick="msExportTable('brandTable','brands.csv')"><i data-lucide="download" style="width:16px"></i>Export</button>
    <button class="btn gold" type="button" data-open-brand><i data-lucide="plus" style="width:16px"></i>Add brand</button>
  </div>
</div>
<?php include __DIR__ . '/../partials/catalog-tabs.php'; ?>

<div class="statstrip">
  <div class="stat glass"><span class="metric-icon"><i data-lucide="bookmark"></i></span><div><small>Active brands</small><b><?= $stats['active'] ?></b><em><?= count($rows) ?> brand profiles</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#e3eef8;color:#3d6a92"><i data-lucide="package"></i></span><div><small>Branded products</small><b><?= $stats['products'] ?></b><em><?= $stats['unbranded'] ?> without a brand</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#e4f4e9;color:#267a55"><i data-lucide="globe"></i></span><div><small>Origin countries</small><b><?= $stats['countries'] ?></b><em>Indian &amp; imported stone</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#fff0bf;color:#9b6c0a"><i data-lucide="star"></i></span><div><small>Storefront placement</small><b><?= $stats['featured'] ?></b><em>Featured brands</em></div></div>
</div>

<?php if ($rows): ?>
<div class="card" style="margin-bottom:18px">
  <div class="card-h" style="align-items:flex-start">
    <div><div class="eyebrow">Brand performance</div><h3>Catalogue depth by label</h3><p class="text-xs muted mt-1">Which quarries, importers and house labels carry the assortment — and how shoppers rate them.</p></div>
    <span class="text-xs muted"><?= count($rows) ?> labels tracked</span>
  </div>
  <div class="perf-list">
    <?php foreach (array_slice($rows, 0, 6) as $i => $r): ?>
    <div class="perf-row">
      <span class="avatar-sq" style="background:<?= $avatarTone[$i % count($avatarTone)] ?>"><?= e($initials((string) $r['name'])) ?></span>
      <div class="min-w-0"><b><?= e((string) $r['name']) ?></b><small><?= (int) $r['product_count'] ?> products · <?= (int) $r['review_count'] ?> reviews</small></div>
      <div class="bar"><i style="width:<?= max(4, round((int) $r['product_count'] / $maxProducts * 100)) ?>%"></i></div>
      <span class="text-sm"><?= $stars($r['avg_rating'] !== null ? (float) $r['avg_rating'] : null) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card toolbar-card glass">
  <select class="btn" id="fTier"><option value="">All tiers</option><?php foreach (BrandController::TIERS as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select>
  <select class="btn" id="fType"><option value="">All brand types</option><?php foreach (BrandController::TYPES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select>
  <select class="btn" id="fStatus"><option value="">All statuses</option><?php foreach (BrandController::STATUSES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select>
  <label class="checkline"><input type="checkbox" id="fArchived"> Archived brands</label>
  <label class="search-in"><i data-lucide="search"></i><input id="fSearch" placeholder="Search brand or country…"></label>
</div>

<div class="card" style="padding:0">
  <div class="card-h" style="padding:20px 20px 0;align-items:flex-start">
    <div><div class="eyebrow">Brand portfolio</div><h3>Catalogue labels</h3><p class="text-xs muted mt-1">Manage brand visibility with product depth and ratings in the same view.</p></div>
    <span class="text-xs muted" id="brandCount"></span>
  </div>
  <div class="tablewrap" style="border:0;border-radius:0 0 24px 24px;margin-top:12px">
    <table class="data" id="brandTable">
      <thead><tr><th>Brand</th><th>Products</th><th>Avg rating</th><th>Tier</th><th>Placement</th><th>Status</th><th data-noexport></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $i => $r):
            $payload = e((string) json_encode([
                'id' => (int) $r['id'], 'name' => $r['name'], 'country' => $r['country'] ?? '', 'tier' => $r['tier'], 'brand_type' => $r['brand_type'],
                'status' => $r['status'], 'is_featured' => (int) $r['is_featured'], 'description' => $r['description'] ?? '',
                'website' => $r['website'] ?? '', 'contact_email' => $r['contact_email'] ?? '',
                'logo' => $r['logo'] ? base_url((string) $r['logo']) : '',
                'action' => route('admin.brands.update', ['id' => $r['id']]),
            ]));
            $search = strtolower($r['name'] . ' ' . ($r['country'] ?? ''));
        ?>
        <tr data-tier="<?= e((string) $r['tier']) ?>" data-type="<?= e((string) $r['brand_type']) ?>" data-status="<?= e((string) $r['status']) ?>" data-search="<?= e($search) ?>">
          <td>
            <span class="flex items-center gap-3">
              <?php if ($r['logo']): ?><span class="avatar-sq"><img src="<?= base_url((string) $r['logo']) ?>" alt=""></span>
              <?php else: ?><span class="avatar-sq" style="background:<?= $avatarTone[$i % count($avatarTone)] ?>"><?= e($initials((string) $r['name'])) ?></span><?php endif; ?>
              <span><b><?= e((string) $r['name']) ?></b><small class="block muted text-xs"><?= e((string) ($r['country'] ?: '—')) ?> · <?= e(BrandController::TYPES[$r['brand_type']] ?? 'House label') ?></small></span>
            </span>
          </td>
          <td><b><?= (int) $r['product_count'] ?></b><small class="block muted text-xs"><?= (int) $r['active_count'] ?> live in catalogue</small></td>
          <td><?= $stars($r['avg_rating'] !== null ? (float) $r['avg_rating'] : null) ?><?php if ((int) $r['review_count']): ?><small class="block muted text-xs"><?= (int) $r['review_count'] ?> reviews</small><?php endif; ?></td>
          <td><span class="tierpill tier-<?= e((string) $r['tier']) ?>"><?= e(BrandController::TIERS[$r['tier']] ?? 'Core') ?></span></td>
          <td><?= (int) $r['is_featured'] ? '<span class="pill pill-gold">★ Featured</span>' : '<span class="muted text-xs">Standard</span>' ?></td>
          <td><span class="status-dot <?= $statusCls[$r['status']] ?? 'off' ?>"><?= e(BrandController::STATUSES[$r['status']] ?? 'Active') ?></span></td>
          <td data-noexport><span class="row-actions">
            <button type="button" class="iconbtn" title="Edit" data-edit-brand="<?= $payload ?>"><i data-lucide="pencil"></i></button>
            <button type="button" class="iconbtn danger" title="Delete" data-del="<?= e(route('admin.brands.delete', ['id' => $r['id']])) ?>" data-del-name="<?= e((string) $r['name']) ?>"><i data-lucide="trash-2"></i></button>
          </span></td>
        </tr>
        <?php endforeach; ?>
        <tr class="empty-row hidden"><td colspan="7" style="text-align:center;padding:36px" class="muted">No brands match these filters.</td></tr>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-backdrop" id="brandModal">
  <form class="modal" method="post" enctype="multipart/form-data" action="<?= route('admin.brands.store') ?>" id="brandForm" data-store="<?= route('admin.brands.store') ?>">
    <?= csrf_field() ?>
    <div class="modal-h">
      <div><div class="eyebrow">Brand portfolio</div><h3 id="brandTitle">Add a brand</h3><p class="text-xs muted mt-1">A house label, quarry, importer or supplier shoppers can browse by.</p></div>
      <button type="button" class="iconbtn" data-close><i data-lucide="x"></i></button>
    </div>
    <div class="stack">
      <div class="grid2">
        <div class="field"><label>Brand name *</label><input name="name" required maxlength="120" placeholder="e.g. Makrana Quarry Co."></div>
        <div class="field"><label>Brand type</label><select name="brand_type"><?php foreach (BrandController::TYPES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="grid2">
        <div class="field"><label>Origin country</label><input name="country" list="countryList" maxlength="64" placeholder="e.g. India"><datalist id="countryList"><?php foreach (['India', 'Italy', 'Turkey', 'Spain', 'Greece', 'Vietnam', 'Iran', 'Brazil', 'China', 'Portugal'] as $c): ?><option value="<?= $c ?>"></option><?php endforeach; ?></datalist></div>
        <div class="field"><label>Brand tier</label><select name="tier"><?php foreach (BrandController::TIERS as $k => $l): ?><option value="<?= $k ?>"<?= $k === 'core' ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="grid2">
        <div class="field"><label>Website</label><input name="website" maxlength="200" placeholder="example.com"></div>
        <div class="field"><label>Contact email</label><input name="contact_email" type="email" maxlength="160" placeholder="sales@example.com"></div>
      </div>
      <div class="field"><label>Description</label><textarea name="description" rows="2" placeholder="What this brand is known for…"></textarea></div>
      <div class="grid3">
        <div class="field"><label>Status</label><select name="status"><?php foreach (BrandController::STATUSES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Placement</label><select name="is_featured"><option value="0">Standard</option><option value="1">Featured</option></select></div>
        <div class="field"><label>Logo <span class="muted" style="font-weight:600">PNG/JPG ≤ 2 MB</span></label><input type="file" name="logo" accept="image/png,image/jpeg,image/webp" style="padding:9px"></div>
      </div>
      <div class="logoprev hidden" id="logoPrev"><img alt=""><span class="text-xs muted">Current logo — upload a file to replace it.</span></div>
    </div>
    <div class="modal-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn gold" type="submit" id="brandSubmit"><i data-lucide="check" style="width:16px"></i>Create brand</button></div>
  </form>
</div>

<?php
$scripts = <<<'JS'
<script>
(function(){
  const modal=document.getElementById('brandModal'),form=document.getElementById('brandForm'),f=n=>form.elements[n];
  function open(d){
    form.reset();const edit=!!d.id;
    form.action=edit?d.action:form.dataset.store;
    document.getElementById('brandTitle').textContent=edit?'Edit brand':'Add a brand';
    document.getElementById('brandSubmit').lastChild.textContent=edit?'Save changes':'Create brand';
    ['name','country','website','contact_email','description'].forEach(k=>f(k).value=d[k]||'');
    f('tier').value=d.tier||'core';f('brand_type').value=d.brand_type||'house';f('status').value=d.status||'active';f('is_featured').value=String(d.is_featured||0);
    const p=document.getElementById('logoPrev');p.classList.toggle('hidden',!d.logo);if(d.logo)p.querySelector('img').src=d.logo;
    modal.classList.add('show');f('name').focus();
  }
  document.querySelectorAll('[data-open-brand]').forEach(b=>b.onclick=()=>open({}));
  document.querySelectorAll('[data-edit-brand]').forEach(b=>b.onclick=()=>open(JSON.parse(b.dataset.editBrand)));
  modal.querySelectorAll('[data-close]').forEach(b=>b.onclick=()=>modal.classList.remove('show'));
  modal.addEventListener('click',e=>{if(e.target===modal)modal.classList.remove('show')});
  msBindDelete();
  msFilterTable('brandTable',{tier:'fTier',type:'fType',status:'fStatus'},'fSearch','fArchived','brandCount','brand');
})();
</script>
JS;
include __DIR__ . '/../partials/footer.php';
?>
