<?php
$active = 'products';
$title = 'Products';
$products = $products ?? [];
$types = $types ?? [];
$categories = $categories ?? [];
$filters = $filters ?? ['q' => '', 'status' => '', 'type' => '', 'category' => ''];
$success = session()->get('success');
$row = static fn($r, $k, $d = '') => is_array($r) ? ($r[$k] ?? $d) : ($r->getAttribute($k) ?? $d);
$statuses = ['draft' => 'Draft', 'active' => 'Active', 'coming_soon' => 'Coming soon', 'archived' => 'Archived'];
include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div>
    <div class="text-xs muted mb-1">Catalog</div>
    <h1>Products<span style="color:#dca71f">.</span> <span class="muted" style="font-size:14px;font-weight:600">(<?= count($products) ?>)</span></h1>
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <button type="button" class="helpq" data-import-help title="How does bulk upload work?" aria-label="How does bulk upload work?">?</button>
    <a class="btn" href="<?= route('admin.products.import') ?>"><i data-lucide="file-spreadsheet" style="width:16px"></i>Bulk upload</a>
    <a class="btn gold" href="<?= route('admin.products.create') ?>"><i data-lucide="plus" style="width:16px"></i>Add product</a>
  </div>
</div>


<form method="get" action="<?= route('admin.products.index') ?>" class="card" style="padding:14px;margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
  <div class="search" style="flex:1;min-width:200px;max-width:none">
    <i data-lucide="search" style="width:16px"></i>
    <input name="q" value="<?= e($filters['q']) ?>" placeholder="Search name or SKU…" style="border:0;background:transparent;outline:none;width:100%;font:inherit;font-size:13px;color:inherit">
  </div>
  <select name="status" class="btn" style="font-weight:600">
    <option value="">All statuses</option>
    <?php foreach ($statuses as $v => $lbl): ?><option value="<?= $v ?>" <?= $filters['status'] === $v ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
  </select>
  <select name="type" class="btn" style="font-weight:600">
    <option value="">All types</option>
    <?php foreach ($types as $t): $tid = $row($t, 'id'); ?><option value="<?= e((string) $tid) ?>" <?= (string) $filters['type'] === (string) $tid ? 'selected' : '' ?>><?= e((string) $row($t, 'name')) ?></option><?php endforeach; ?>
  </select>
  <select name="category" class="btn" style="font-weight:600">
    <option value="">All categories</option>
    <?php foreach ($categories as $c): $cid = $row($c, 'id'); ?><option value="<?= e((string) $cid) ?>" <?= (string) $filters['category'] === (string) $cid ? 'selected' : '' ?>><?= ((int) ($c['depth'] ?? 0)) ? '&nbsp;&nbsp;↳ ' : '' ?><?= e((string) $row($c, 'name')) ?></option><?php endforeach; ?>
  </select>
  <button type="submit" class="btn gold"><i data-lucide="filter" style="width:15px"></i>Filter</button>
  <?php if ($filters['q'] || $filters['status'] || $filters['type'] || $filters['category']): ?>
  <a class="btn ghost" href="<?= route('admin.products.index') ?>">Clear</a>
  <?php endif; ?>
</form>

<div class="card" style="padding:0">
  <div class="tablewrap">
    <table class="data">
      <thead>
        <tr><th>Product</th><th>Type</th><th>Brand</th><th>Price</th><th>Stock</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$products): ?>
          <tr><td colspan="7" style="text-align:center;padding:40px" class="muted">No products match. <a style="color:#b17a13;font-weight:700" href="<?= route('admin.products.create') ?>">Add a product →</a></td></tr>
        <?php else: foreach ($products as $p):
            $pid = $row($p, 'id');
            $status = (string) $row($p, 'status', 'draft');
            $price = $row($p, 'price');
            $showPrice = (int) $row($p, 'show_price', 1);
            $enquiry = (int) $row($p, 'is_enquiry_only', 0);
            $cover = $row($p, 'cover');
        ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:12px">
                <div style="width:42px;height:42px;border-radius:10px;overflow:hidden;background:#f0e7d4;flex-shrink:0">
                  <?php if ($cover): ?><img src="<?= base_url($cover) ?>" style="width:100%;height:100%;object-fit:cover"><?php endif; ?>
                </div>
                <div>
                  <div style="font-weight:700;display:flex;align-items:center;gap:6px"><a href="<?= route('admin.products.show', ['id' => $pid]) ?>" style="color:inherit;text-decoration:none"><?= e((string) $row($p, 'name')) ?></a>
                    <?php if ((int) $row($p, 'is_featured', 0)): ?><i data-lucide="star" style="width:13px;color:#e9aa13" title="Featured"></i><?php endif; ?>
                  </div>
                  <div class="muted" style="font-size:11px"><?= e((string) $row($p, 'sku')) ?></div>
                </div>
              </div>
            </td>
            <td class="muted"><?= e((string) $row($p, 'type_name', '—')) ?><?php if ($vc = (int) $row($p, 'variant_count', 0)): ?><a class="chip" style="margin-left:6px" href="<?= route('admin.variants') ?>"><?= $vc ?> variant<?= $vc === 1 ? '' : 's' ?></a><?php endif; ?></td>
            <td class="muted"><?= e((string) ($row($p, 'brand_name') ?: '—')) ?></td>
            <td>
              <?php if ($enquiry): ?><span class="muted">Enquiry only</span>
              <?php elseif ($showPrice && $price !== null && $price !== ''): ?>
                <?= e((string) $row($p, 'currency', 'INR')) ?> <?= e((string) $price) ?><?php if ($u = $row($p, 'selling_unit')): ?><span class="muted">/<?= e((string) $u) ?></span><?php endif; ?>
              <?php else: ?><span class="muted">—</span><?php endif; ?>
            </td>
            <td style="white-space:nowrap">
              <?php
                $stId = $row($p, 'stock_id');
                if (!$stId) {
                    echo '<span class="muted" style="font-size:12px">Not tracked</span>';
                } else {
                    $sStatus = (string) $row($p, 'stock_status', 'healthy');
                    $qty = rtrim(rtrim((string) $row($p, 'stock_qty', '0'), '0'), '.');
                    $qty = $qty === '' ? '0' : $qty;
                    $sUnit = (string) $row($p, 'stock_unit', '');
                    $dot = ['healthy' => '#2f9e63', 'low' => '#c98a12', 'out' => '#c2412a'][$sStatus] ?? '#9b958a';
                    $lbl = ['healthy' => 'In stock', 'low' => 'Low', 'out' => 'Out'][$sStatus] ?? $sStatus;
              ?>
              <a href="<?= route('admin.inventory.stock') ?>?product=<?= (int) $pid ?>" style="display:inline-flex;align-items:center;gap:7px;color:inherit" title="Manage stock">
                <span style="width:8px;height:8px;border-radius:50%;background:<?= $dot ?>;flex-shrink:0"></span>
                <span><b style="font-weight:700"><?= e($qty) ?></b><?php if ($sUnit): ?> <span class="muted" style="font-size:11px"><?= e($sUnit) ?></span><?php endif; ?><span class="muted" style="font-size:11px;display:block;line-height:1"><?= e($lbl) ?></span></span>
              </a>
              <?php } ?>
            </td>
            <td><span class="badge <?= e($status) ?>"><?= e(str_replace('_', ' ', $status)) ?></span></td>
            <td style="text-align:right;white-space:nowrap">
              <a class="btn ghost" title="Images &amp; details" style="padding:7px 9px" href="<?= route('admin.products.show', ['id' => $pid]) ?>"><i data-lucide="images" style="width:15px"></i></a>
              <a class="btn ghost" title="Edit" style="padding:7px 9px" href="<?= route('admin.products.edit', ['id' => $pid]) ?>"><i data-lucide="pencil" style="width:15px"></i></a>
              <form method="post" action="<?= route('admin.products.destroy', ['id' => $pid]) ?>" onsubmit="return confirm('Delete this product? This cannot be undone.')" style="display:inline">
                <?= csrf_field() ?>
                <button type="submit" class="btn ghost" title="Delete" style="padding:7px 9px"><i data-lucide="trash-2" style="width:15px;color:#b4452f"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/partials/import-help.php'; ?>
<?php include __DIR__ . '/../partials/footer.php'; ?>
