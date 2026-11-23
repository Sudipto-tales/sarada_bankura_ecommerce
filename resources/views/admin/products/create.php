<?php
$brands = $brands ?? [];
$categories = $categories ?? [];
$attributes = $attributes ?? [];
$productType = $productType ?? null;

$isEdit = $isEdit ?? false;
$product = $product ?? null;
$pricing = $pricing ?? null;
$attrValues = $attrValues ?? [];
$selectedCats = $selectedCats ?? [];
$primaryCat = $primaryCat ?? 0;
$tagString = $tagString ?? '';
$media = $media ?? [];
$units = $units ?? [];
$optionGroups = $optionGroups ?? [];
$variants = $variants ?? [];
$stockRow = $stockRow ?? null;
$isTracked = $stockRow !== null;
$stockEnabled = $stockEnabled ?? true;

$active = 'products.create';
$title = $isEdit ? 'Edit product' : 'Add product';

$typeId = $productType ? (is_array($productType) ? $productType['id'] : $productType->getAttribute('id')) : 0;
$typeName = $productType ? (is_array($productType) ? $productType['name'] : $productType->getAttribute('name')) : 'Default';

$pv = static fn($k, $d = null) => $product ? (is_array($product) ? ($product[$k] ?? $d) : $product->getAttribute($k)) : $d;
$prc = static fn($k, $d = null) => $pricing ? (is_array($pricing) ? ($pricing[$k] ?? $d) : $pricing->getAttribute($k)) : $d;
$productId = (int) ($pv('id', 0));

$allErrors = errors();
$hasErr = !empty($allErrors);
$cur = static fn($key, $modelVal = '') => $hasErr ? old($key, $modelVal) : $modelVal;
$chk = static fn($key, $modelVal) => $hasErr ? (bool) old($key, '') : (bool) $modelVal;

$oldAttr = is_array(old('attr', [])) ? old('attr', []) : [];
$oldCats = is_array(old('categories', [])) ? old('categories', []) : [];
$effCats = $hasErr ? array_map('intval', $oldCats) : $selectedCats;
$effPrimary = $hasErr ? (int) old('primary_category', 0) : $primaryCat;

$oldVariants = old('variants', null);
$effVariants = ($hasErr && is_array($oldVariants)) ? array_values(array_map(static fn($k, $v) => (array) $v + ['key' => $k], array_keys($oldVariants), $oldVariants)) : $variants;
$effHasVariants = $hasErr ? (bool) old('has_variants', '') : (bool) $variants;
$effDefault = $hasErr ? (string) old('default_variant', '') : '';
$unitKinds = ['area' => 'Area', 'length' => 'Length', 'count' => 'Count', 'pack' => 'Pack', 'weight' => 'Weight'];
$unitOptions = static function (string $selected, string $placeholder) use ($units, $unitKinds): string {
    $by = [];
    foreach ($units as $u) {
        $by[$u['kind']][] = $u;
    }
    $html = '<option value="">' . e($placeholder) . '</option>';
    $known = false;
    foreach ($unitKinds as $k => $label) {
        if (empty($by[$k])) {
            continue;
        }
        $html .= '<optgroup label="' . $label . '">';
        foreach ($by[$k] as $u) {
            $sel = $selected === (string) $u['symbol'];
            $known = $known || $sel;
            $html .= '<option value="' . e((string) $u['symbol']) . '"' . ($sel ? ' selected' : '') . '>' . e((string) $u['symbol']) . ' — ' . e((string) $u['name']) . '</option>';
        }
        $html .= '</optgroup>';
    }
    if ($selected !== '' && !$known) {
        $html .= '<option selected value="' . e($selected) . '">' . e($selected) . '</option>';
    }

    return $html;
};

$formAction = $isEdit ? route('admin.products.update', ['id' => $productId]) : route('admin.products.store');

include __DIR__ . '/../partials/header.php';
?>
<form method="post" action="<?= $formAction ?>" enctype="multipart/form-data" id="productForm">
<?= csrf_field() ?>

<div class="pagehead">
  <div>
    <a class="text-xs muted mb-2" style="display:inline-block" href="<?= route('admin.products.index') ?>">← Back to products</a>
    <h1><?= $isEdit ? 'Edit product' : 'Add product' ?><span style="color:#dca71f">.</span></h1>
  </div>
  <div class="flex gap-2">
    <button type="submit" class="btn" name="save_as" value="draft"><i data-lucide="save" style="width:16px"></i>Save draft</button>
    <button type="submit" class="btn gold"><i data-lucide="check" style="width:16px"></i><?= $isEdit ? 'Update product' : 'Create product' ?></button>
  </div>
</div>

<?php if ($allErrors): ?>
<div class="notice" style="margin-bottom:16px;background:#fbeceb;border-color:#eacfcb;color:#b4452f">
  Please fix the highlighted fields:
  <ul style="margin:6px 0 0;padding-left:18px">
    <?php foreach ($allErrors as $field => $msgs): ?><li><?= e((string) ($msgs[0] ?? '')) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="layout">
  <section class="formcard">

    <!-- Step 1: Information -->
    <div class="wizstep" data-step="0">
      <div class="sectionhead"><div class="sectionicon"><i data-lucide="package"></i></div><div><h2>Product information</h2><p class="text-xs muted mt-1">Name, SKU and storefront content.</p></div></div>
      <div class="stack">
        <div class="grid2">
          <div class="field"><label>Product name *</label><input name="name" value="<?= e((string) $cur('name', (string) $pv('name', ''))) ?>" placeholder="e.g. Statuario White Marble"><?php if ($m = error('name')): ?><div class="err"><?= e($m) ?></div><?php endif; ?></div>
          <div class="field"><label>SKU *</label><input name="sku" value="<?= e((string) $cur('sku', (string) $pv('sku', ''))) ?>" placeholder="e.g. MS-STAT-001"><?php if ($m = error('sku')): ?><div class="err"><?= e($m) ?></div><?php endif; ?></div>
        </div>
        <div class="grid2">
          <div class="field"><label>Existing / External product ID</label><input name="external_product_id" value="<?= e((string) $cur('external_product_id', (string) $pv('external_product_id', ''))) ?>" placeholder="Old catalogue ID (optional)"></div>
          <div class="field"><label>URL slug</label><input name="slug" value="<?= e((string) $cur('slug', (string) $pv('slug', ''))) ?>" placeholder="Auto-generated from name if blank"></div>
        </div>
        <div class="field"><label>Short description</label><textarea name="short_description" placeholder="A concise summary for cards and listings."><?= e((string) $cur('short_description', (string) $pv('short_description', ''))) ?></textarea><div class="hint">Recommended: 160–220 characters.</div></div>
        <div class="field"><label>Description</label><textarea name="description" style="min-height:150px" placeholder="Detailed product description…"><?= e((string) $cur('description', (string) $pv('description', ''))) ?></textarea></div>
      </div>
    </div>

    <!-- Step 2: Media -->
    <div class="wizstep hidden" data-step="1">
      <div class="sectionhead"><div class="sectionicon"><i data-lucide="image"></i></div><div><h2>Product media</h2><p class="text-xs muted mt-1">Photos and an optional video. Files go to the media library and are attached when you save.</p></div></div>
      <div class="stack">
        <?php if ($isEdit && $media): ?>
        <div class="subbox">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap"><b class="text-sm">Current media</b>
            <a class="btn ghost" style="padding:6px 10px" href="<?= route('admin.products.show', ['id' => $productId]) ?>"><i data-lucide="images" style="width:14px"></i>Reorder, alt text &amp; variants</a></div>
          <div class="wiz-media" style="margin-top:12px">
            <?php foreach ($media as $md): ?>
            <div class="gal-item<?= $md['role'] === 'cover' ? ' cover' : '' ?>">
              <?php if ($md['role'] === 'cover'): ?><span class="gal-badge">Cover</span><?php elseif ($md['kind'] === 'video'): ?><span class="gal-badge video">Video</span><?php endif; ?>
              <div class="gal-img"><?php if ($md['thumb_url']): ?><img src="<?= e($md['thumb_url']) ?>" alt="<?= e((string) $md['alt_text']) ?>"><?php else: ?><i data-lucide="film"></i><?php endif; ?></div>
              <div class="gal-foot">
                <?php if ($md['kind'] === 'image'): ?><label><input type="radio" name="cover_media_id" value="<?= (int) $md['id'] ?>"<?= $md['role'] === 'cover' ? ' checked' : '' ?>>Cover</label><?php endif; ?>
                <button type="button" class="iconbtn danger" title="Remove" data-del-media="<?= e(route('admin.products.media.delete', ['id' => $productId, 'mediaId' => $md['id']])) ?>" style="margin-left:auto"><i data-lucide="x"></i></button>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php $up = ['id' => 'wizUploader', 'kinds' => ['image', 'video'], 'title' => 'Drop product photos or a video here, or click to choose'];
        include __DIR__ . '/../partials/uploader.php'; ?>
        <div class="wiz-media" id="wizNew"></div>
        <div><button type="button" class="btn" id="wizPick"><i data-lucide="library" style="width:15px"></i>Pick from library</button>
          <span class="hint" style="margin-left:8px">Reuse a photo already uploaded, e.g. the same slab on two products.</span></div>
        <noscript><div class="field"><label>Cover image</label><input name="cover" type="file" accept="image/png,image/jpeg,image/webp"></div></noscript>
      </div>
    </div>

    <!-- Step 3: Catalog & Attributes -->
    <div class="wizstep hidden" data-step="2">
      <div class="sectionhead"><div class="sectionicon"><i data-lucide="layout-grid"></i></div><div><h2>Catalog &amp; attributes</h2><p class="text-xs muted mt-1">Type, brand, categories and <?= e((string) $typeName) ?> attributes.</p></div></div>
      <input type="hidden" name="product_type_id" value="<?= e((string) $typeId) ?>">
      <div class="stack">
        <div class="grid2">
          <div class="field"><label>Product type</label><input value="<?= e((string) $typeName) ?>" disabled></div>
          <div class="field"><label>Brand</label>
            <?php $curBrand = (string) $cur('brand_id', (string) $pv('brand_id', '')); ?>
            <select name="brand_id"><option value="">Select a brand</option>
              <?php foreach ($brands as $b): $bid = is_array($b) ? $b['id'] : $b->getAttribute('id'); $bn = is_array($b) ? $b['name'] : $b->getAttribute('name'); ?>
              <option value="<?= e((string) $bid) ?>" <?= $curBrand === (string) $bid ? 'selected' : '' ?>><?= e((string) $bn) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="subbox">
          <b class="text-sm">Categories</b>
          <p class="text-xs muted mt-1 mb-3">Select categories or sub-categories and pick one primary (radio). Manage the tree on the <a href="<?= route('admin.categories') ?>" style="color:#9b6c0a;font-weight:800">Categories</a> page.</p>
          <?php if (!$categories): ?><p class="text-xs muted">No categories yet.</p><?php endif; ?>
          <?php foreach ($categories as $c): $cid = (int) $c['id']; $cn = $c['name']; $sub = (int) ($c['depth'] ?? 0) === 1; ?>
          <label class="checkline" style="justify-content:space-between;padding:7px 0;border-bottom:1px solid #f1e8d6<?= $sub ? ';padding-left:26px' : '' ?>">
            <span style="display:flex;gap:8px;align-items:center"><?= $sub ? '<span class="muted">↳</span>' : '' ?><input type="checkbox" name="categories[]" value="<?= $cid ?>" <?= in_array($cid, $effCats, true) ? 'checked' : '' ?>> <?= $sub ? e((string) $cn) : '<b>' . e((string) $cn) . '</b>' ?></span>
            <span style="display:flex;gap:6px;align-items:center;font-size:11px" class="muted">Primary <input type="radio" name="primary_category" value="<?= $cid ?>" <?= $effPrimary === $cid ? 'checked' : '' ?>></span>
          </label>
          <?php endforeach; ?>
        </div>

        <div class="field"><label>Tags</label><input name="tags" value="<?= e((string) $cur('tags', $tagString)) ?>" placeholder="Comma separated, e.g. premium, imported, white"></div>

        <div class="subbox">
          <b class="text-sm">Attributes</b>
          <p class="text-xs muted mt-1 mb-3">Characteristics specific to <?= e((string) $typeName) ?>. Values come from the <a href="<?= route('admin.attributes') ?>" style="font-weight:800;color:#9b6c0a">attribute library</a>.</p>
          <div class="grid2">
            <?php foreach ($attributes as $a):
                $aid = (int) $a['id'];
                $aname = (string) $a['name'];
                $atype = (string) $a['type'];
                $aunit = $a['unit'] ?? null;
                $adisplay = (string) ($a['display'] ?? 'text');
                $avals = $a['values'] ?? [];
                $attrVal = $oldAttr[$aid] ?? ($attrValues[$aid] ?? '');
                $picked = is_array($attrVal) ? array_map('strval', $attrVal) : array_values(array_filter(array_map('trim', explode(',', (string) $attrVal)), 'strlen'));
                $known = array_map(static fn($v) => $v['value'], $avals);
                $cur1 = is_array($attrVal) ? (string) ($attrVal[0] ?? '') : (string) $attrVal;
            ?>
            <div class="field<?= $atype === 'multiselect' || $adisplay === 'swatch' ? ' span2' : '' ?>">
              <label><?= e($aname) ?><?= !$avals && $aunit ? ' <span class="muted">(' . e((string) $aunit) . ')</span>' : '' ?></label>
              <?php if (!$avals): ?>
                <input name="attr[<?= $aid ?>]" type="<?= $atype === 'number' ? 'number' : 'text' ?>" step="any" value="<?= e(is_array($attrVal) ? implode(', ', $attrVal) : (string) $attrVal) ?>">
              <?php elseif ($atype === 'multiselect'): ?>
                <div class="chips">
                  <?php foreach ($avals as $v): ?><label class="pickchip"><input type="checkbox" name="attr[<?= $aid ?>][]" value="<?= e($v['value']) ?>" <?= in_array($v['value'], $picked, true) ? 'checked' : '' ?>><span><?= e($v['value']) ?></span></label><?php endforeach; ?>
                  <?php foreach (array_diff($picked, $known) as $extra): ?><label class="pickchip"><input type="checkbox" name="attr[<?= $aid ?>][]" value="<?= e($extra) ?>" checked><span><?= e($extra) ?></span></label><?php endforeach; ?>
                </div>
              <?php elseif ($adisplay === 'swatch'): ?>
                <div class="chips">
                  <label class="pickchip"><input type="radio" name="attr[<?= $aid ?>]" value="" <?= $cur1 === '' ? 'checked' : '' ?>><span class="muted">None</span></label>
                  <?php foreach ($avals as $v): ?><label class="pickchip"><input type="radio" name="attr[<?= $aid ?>]" value="<?= e($v['value']) ?>" <?= $cur1 === $v['value'] ? 'checked' : '' ?>><span><i class="sw" style="background:<?= e((string) $v['swatch']) ?>"></i><?= e($v['value']) ?></span></label><?php endforeach; ?>
                  <?php if ($cur1 !== '' && !in_array($cur1, $known, true)): ?><label class="pickchip"><input type="radio" name="attr[<?= $aid ?>]" value="<?= e($cur1) ?>" checked><span><?= e($cur1) ?></span></label><?php endif; ?>
                </div>
              <?php else: ?>
                <select name="attr[<?= $aid ?>]">
                  <option value="">—</option>
                  <?php foreach ($avals as $v): ?><option value="<?= e($v['value']) ?>" <?= $cur1 === $v['value'] ? 'selected' : '' ?>><?= e($v['value']) ?></option><?php endforeach; ?>
                  <?php if ($cur1 !== '' && !in_array($cur1, $known, true)): ?><option value="<?= e($cur1) ?>" selected><?= e($cur1) ?></option><?php endif; ?>
                </select>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Step 4: Pricing -->
    <div class="wizstep hidden" data-step="3">
      <div class="sectionhead"><div class="sectionicon"><i data-lucide="badge-dollar-sign"></i></div><div><h2>Pricing &amp; variants</h2><p class="text-xs muted mt-1">Price, selling unit, box contents and sellable variants.</p></div></div>
      <?php $curCurrency = (string) $cur('currency', (string) $prc('currency', 'INR')); $curUnit = (string) $cur('selling_unit', (string) $prc('unit', '')); $curPackQty = (string) $cur('pack_qty', (string) ($prc('pack_qty', '') ?? '')); $curPackUnit = (string) $cur('pack_unit', (string) ($prc('pack_unit', '') ?? '')); ?>
      <div class="stack">
        <div class="grid3">
          <div class="field"><label>Selling price</label><input name="price" type="number" step="0.01" value="<?= e((string) $cur('price', (string) $prc('price', ''))) ?>" placeholder="0.00"></div>
          <div class="field"><label>Compare-at price</label><input name="compare_at_price" type="number" step="0.01" value="<?= e((string) $cur('compare_at_price', (string) $prc('compare_at_price', ''))) ?>" placeholder="0.00"></div>
          <div class="field"><label>Cost per item</label><input name="cost_price" type="number" step="0.01" value="<?= e((string) $cur('cost_price', (string) $prc('cost_price', ''))) ?>" placeholder="0.00"></div>
        </div>
        <div class="grid2">
          <div class="field"><label>Currency</label><select name="currency"><?php foreach (['INR', 'USD', 'EUR'] as $cc): ?><option <?= $curCurrency === $cc ? 'selected' : '' ?>><?= $cc ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>Selling unit <span class="muted">· price is per this unit</span></label>
            <select name="selling_unit" id="sellingUnit"><?= $unitOptions($curUnit, 'Select unit') ?></select>
          </div>
        </div>
        <div class="subbox packbox">
          <div><b class="text-sm">Box / pack contents</b><p class="text-xs muted mt-1">Optional. For tiles sold by the box, e.g. “1 box = 10.76 sq ft”. Leave blank if not sold in packs.</p></div>
          <div class="packrow">
            <span class="text-sm">1 <b id="packOf"><?= e($curUnit ?: 'unit') ?></b> =</span>
            <div class="field"><input name="pack_qty" type="number" step="0.001" min="0" value="<?= e($curPackQty) ?>" placeholder="10.76"></div>
            <div class="field"><select name="pack_unit"><?= $unitOptions($curPackUnit, 'unit') ?></select></div>
          </div>
          <div class="hint">Need another unit (e.g. cm, box, set)? Add it on the <a href="<?= route('admin.units') ?>" target="_blank" style="font-weight:800">Units</a> page.</div>
        </div>
        <div class="subbox" style="display:grid;gap:10px">
          <label class="checkline"><input type="checkbox" name="show_price" value="1" <?= $chk('show_price', $isEdit ? $pv('show_price', 1) : 1) ? 'checked' : '' ?>> Show price on storefront</label>
          <label class="checkline"><input type="checkbox" name="is_enquiry_only" value="1" <?= $chk('is_enquiry_only', $isEdit ? $pv('is_enquiry_only', 0) : 0) ? 'checked' : '' ?>> Enquiry only (hide price, show enquiry button)</label>
        </div>

        <?php $effTrack = $hasErr ? (bool) old('track_stock', '') : $isTracked; ?>
        <div class="subbox stockbox">
          <div class="flex items-center justify-between gap-3" style="flex-wrap:wrap">
            <div><b class="text-sm">Stock tracking</b><p class="text-xs muted mt-1">Keep a running stock count for this product. Receipts and adjustments are managed on the Stock page.</p></div>
            <?php if ($isEdit && $isTracked): ?>
              <span class="chip" style="background:#eaf5ee;color:#2f7a53;border-color:#cfe8d8"><i data-lucide="check" style="width:13px"></i>Tracking on</span>
            <?php else: ?>
              <label class="switch"><input type="checkbox" name="track_stock" value="1" id="trackStock" <?= $effTrack ? 'checked' : '' ?> <?= $stockEnabled ? '' : 'disabled' ?>><span></span><b class="text-xs">Track stock for this product</b></label>
            <?php endif; ?>
          </div>
          <?php if ($isEdit && $isTracked): ?>
            <div class="stockinfo">
              <div>
                <span class="muted text-xs">Current stock</span>
                <b style="font-size:18px"><?= e(rtrim(rtrim((string) ($stockRow['quantity'] ?? '0'), '0'), '.') ?: '0') ?> <span class="muted" style="font-weight:600;font-size:13px"><?= e((string) ($stockRow['unit'] ?? '')) ?></span></b>
              </div>
              <a class="btn" href="<?= route('admin.inventory.stock') ?>?product=<?= (int) $productId ?>" target="_blank"><i data-lucide="archive" style="width:15px"></i>Manage stock</a>
            </div>
          <?php elseif (!$stockEnabled): ?>
            <div class="hint" style="margin-top:12px">Inventory tracking is turned off in <a href="<?= route('admin.inventory.alerts') ?>" target="_blank" style="font-weight:800">Inventory settings</a>. Turn it on to track stock.</div>
          <?php else: ?>
            <div id="stockFields" class="grid3 <?= $effTrack ? '' : 'hidden' ?>" style="margin-top:14px">
              <div class="field"><label>Opening quantity</label><input name="opening_qty" type="number" step="0.001" min="0" value="<?= e((string) $cur('opening_qty', '0')) ?>" placeholder="0"></div>
              <div class="field"><label>Stock unit</label><select name="stock_unit" id="stockUnit"><?= $unitOptions((string) $cur('stock_unit', $curUnit), 'Select unit') ?></select></div>
              <div class="field"><label>Low-stock alert <span class="muted">· optional</span></label><input name="low_stock_threshold" type="number" step="0.001" min="0" value="<?= e((string) $cur('low_stock_threshold', '')) ?>" placeholder="Default"></div>
            </div>
          <?php endif; ?>
        </div>

        <div class="subbox variantbox">
          <div class="flex items-center justify-between gap-3" style="flex-wrap:wrap">
            <div><b class="text-sm">Variants</b><p class="text-xs muted mt-1">Sell this product in several thicknesses, finishes or sizes — each with its own SKU and price.</p></div>
            <label class="switch"><input type="checkbox" name="has_variants" value="1" id="hasVariants" <?= $effHasVariants ? 'checked' : '' ?>><span></span><b class="text-xs">This product has variants</b></label>
          </div>
          <?php if ($m = error('variants')): ?><div class="err"><?= e($m) ?></div><?php endif; ?>
          <div id="variantBuilder" class="<?= $effHasVariants ? '' : 'hidden' ?>">
            <?php if (!$optionGroups): ?>
            <p class="text-xs muted" style="margin-top:12px">No option groups yet. Create them on <a href="<?= route('admin.variants') ?>" style="font-weight:800;color:#9b6c0a">Variants &amp; options</a>.</p>
            <?php endif; ?>
            <div class="optpick" id="optionPicker">
              <?php foreach ($optionGroups as $g): ?>
              <div class="optgroup">
                <span class="optname"><?= e($g['name']) ?></span>
                <span class="chips">
                  <?php foreach ($g['values'] as $ov): ?><label class="pickchip"><input type="checkbox" value="<?= $ov['id'] ?>" data-group="<?= $g['id'] ?>" data-label="<?= e($ov['value']) ?>"><span><?= e($ov['value']) ?></span></label><?php endforeach; ?>
                </span>
              </div>
              <?php endforeach; ?>
            </div>
            <div class="flex items-center gap-3" style="margin:12px 0 4px;flex-wrap:wrap">
              <button type="button" class="btn gold" id="genVariants"><i data-lucide="wand-sparkles" style="width:16px"></i>Generate combinations</button>
              <span class="text-xs muted" id="genHint">Tick values above, then generate. Existing rows keep their SKU and price.</span>
            </div>
            <div class="tablewrap" style="margin-top:10px">
              <table class="data vtable">
                <thead><tr><th>Default</th><th>Combination</th><th>SKU *</th><th>Price</th><th>Compare-at</th><th>Unit</th><th>Status</th><th></th></tr></thead>
                <tbody id="variantRows"></tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Step 5: SEO & Publishing -->
    <div class="wizstep hidden" data-step="4">
      <div class="sectionhead"><div class="sectionicon"><i data-lucide="search-check"></i></div><div><h2>SEO &amp; publishing</h2><p class="text-xs muted mt-1">Search appearance, status and merchandising.</p></div></div>
      <?php $curStatus = (string) $cur('status', (string) $pv('status', 'draft')); ?>
      <div class="stack">
        <div class="field"><label>Search engine title</label><input name="meta_title" value="<?= e((string) $cur('meta_title', (string) $pv('meta_title', ''))) ?>" placeholder="Product name · Maa Sarada"></div>
        <div class="field"><label>Meta description</label><textarea name="meta_description" placeholder="150–160 characters for search results."><?= e((string) $cur('meta_description', (string) $pv('meta_description', ''))) ?></textarea></div>
        <div class="grid2">
          <div class="field"><label>Status</label>
            <select name="status">
              <?php foreach (['draft' => 'Draft', 'active' => 'Active', 'coming_soon' => 'Coming soon', 'archived' => 'Archived'] as $v => $lbl): ?>
              <option value="<?= $v ?>" <?= $curStatus === $v ? 'selected' : '' ?>><?= $lbl ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field" style="display:grid;gap:10px;align-content:end">
            <label class="checkline"><input type="checkbox" name="is_featured" value="1" <?= $chk('is_featured', $isEdit ? $pv('is_featured', 0) : 0) ? 'checked' : '' ?>> Featured product</label>
            <label class="checkline"><input type="checkbox" name="is_bestseller" value="1" <?= $chk('is_bestseller', $isEdit ? $pv('is_bestseller', 0) : 0) ? 'checked' : '' ?>> Bestseller</label>
          </div>
        </div>
      </div>
    </div>

    <div class="footeractions">
      <button type="button" class="btn" id="prevStep" disabled>← Previous</button>
      <span class="text-xs muted" id="stepText">Step 1 of 5</span>
      <button type="button" class="btn gold" id="nextStep">Continue →</button>
    </div>
  </section>

  <aside class="rightcol">
    <div class="rightcard">
      <div class="text-[10px] font-extrabold tracking-widest" style="color:#b17a13">PRODUCT SETUP</div>
      <h3 class="font-extrabold text-base mt-1"><?= $isEdit ? 'Edit details' : 'Product details' ?></h3>
      <div class="progress"><span id="progressBar" style="width:20%"></span></div>
      <div id="steps"></div>
    </div>
  </aside>
</div>
</form>

<?php
$scripts = ($scripts ?? '') . <<<'JS'
<script>
(function(){
  const steps=[
    {nav:'Information',desc:'Name, SKU and content'},
    {nav:'Media',desc:'Cover, gallery and video'},
    {nav:'Catalog',desc:'Type, categories, attributes'},
    {nav:'Pricing & variants',desc:'Price, units and variants'},
    {nav:'SEO & publish',desc:'Search, status, merchandising'}
  ];
  const panels=[...document.querySelectorAll('.wizstep')];
  let active=0;
  const $=s=>document.querySelector(s);
  function renderSteps(){
    $('#steps').innerHTML=steps.map((s,i)=>`<div class="step ${i===active?'active':''} ${i<active?'done':''}" data-goto="${i}"><span class="stepnum">${i<active?'✓':i+1}</span><span class="stepcopy"><b>${s.nav}</b><small>${s.desc}</small></span></div>`).join('');
    document.querySelectorAll('[data-goto]').forEach(el=>el.onclick=()=>{active=+el.dataset.goto;sync()});
    if(window.lucide)lucide.createIcons();
  }
  function sync(){
    panels.forEach((p,i)=>p.classList.toggle('hidden',i!==active));
    $('#prevStep').disabled=active===0;
    $('#nextStep').textContent=active===steps.length-1?'Review →':'Continue →';
    $('#stepText').textContent=`Step ${active+1} of ${steps.length}`;
    $('#progressBar').style.width=((active+1)/steps.length*100)+'%';
    renderSteps();
  }
  $('#prevStep').onclick=()=>{if(active>0){active--;sync()}};
  $('#nextStep').onclick=()=>{if(active<steps.length-1){active++;sync()}};
  // New media (wizard step 2): each uploaded/picked file becomes a hidden media_ids[] + a tile with a cover radio.
  const wizNew=document.getElementById('wizNew'), added=new Set();
  const hasCover=()=>!!document.querySelector('input[name=cover_media_id]:checked');
  function addMedia(m){
    if(added.has(m.id))return;added.add(m.id);
    const esc=window.msMedia.esc, tile=document.createElement('div');tile.className='gal-item';
    const first=m.kind==='image'&&!hasCover();
    tile.innerHTML='<input type="hidden" name="media_ids[]" value="'+m.id+'">'+(m.kind==='video'?'<span class="gal-badge video">Video</span>':'')+
      '<div class="gal-img">'+(m.thumb_url?'<img src="'+esc(m.thumb_url)+'" alt="">':'<i data-lucide="film"></i>')+'</div>'+
      '<div class="gal-foot">'+(m.kind==='image'?'<label><input type="radio" name="cover_media_id" value="'+m.id+'"'+(first?' checked':'')+'>Cover</label>':'<span class="text-xs muted">'+esc(m.media_no)+'</span>')+
      '<button type="button" class="iconbtn danger" title="Don\'t attach" style="margin-left:auto" data-unadd><i data-lucide="x"></i></button></div>';
    tile.querySelector('[data-unadd]').onclick=()=>{added.delete(m.id);tile.remove();};
    wizNew.appendChild(tile);if(window.lucide)lucide.createIcons();
  }
  document.getElementById('wizUploader')?.addEventListener('ms:uploaded',e=>addMedia(e.detail));
  document.getElementById('wizPick')?.addEventListener('click',()=>window.msMedia.pick({kinds:['image','video'],multiple:true}).then(list=>list.forEach(addMedia)));
  document.querySelector('button[name=save_as]')?.addEventListener('click',()=>{const s=document.querySelector('select[name=status]');if(s)s.value='draft';});
  const token=document.querySelector('input[name=_token]')?.value||'';
  document.querySelectorAll('[data-del-media]').forEach(btn=>{
    btn.addEventListener('click',()=>{
      if(!confirm('Remove this image?'))return;
      const f=document.createElement('form');f.method='post';f.action=btn.dataset.delMedia;
      const t=document.createElement('input');t.type='hidden';t.name='_token';t.value=token;f.appendChild(t);
      document.body.appendChild(f);f.submit();
    });
  });
  sync();
})();
</script>
JS;
$scripts .= '<script>window.MS_VARIANTS=' . json_encode([
    'groups' => $optionGroups,
    'rows' => $effVariants,
    'default' => $effDefault,
    'units' => array_map(static fn($u) => $u['symbol'], $units),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
$scripts .= <<<'JS'
<script>
(function(){
  const D=window.MS_VARIANTS,$=s=>document.querySelector(s),tbody=$('#variantRows');
  if(!tbody)return;
  const label={};D.groups.forEach(g=>g.values.forEach(v=>label[v.id]={value:v.value,group:g.id}));
  const groupOrder=D.groups.map(g=>g.id);
  let seq=0;
  const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const keyOf=ids=>ids.map(Number).sort((a,b)=>a-b).join(',');
  const abbr=s=>String(s).toUpperCase().replace(/[^A-Z0-9]+/g,'').slice(0,4)||'X';
  const unitSel=sel=>'<option value="">Product unit</option>'+D.units.map(u=>`<option ${u===sel?'selected':''}>${esc(u)}</option>`).join('');
  function addRow(r,isDefault){
    const k=r.key??('n'+(seq++));
    const tr=document.createElement('tr');tr.dataset.key=keyOf(String(r.values).split(',').filter(Boolean));
    tr.innerHTML=`<td><input type="radio" name="default_variant" value="${esc(k)}" ${isDefault?'checked':''}></td>
      <td><b>${esc(r.title)}</b><input type="hidden" name="variants[${esc(k)}][id]" value="${esc(r.id||'')}"><input type="hidden" name="variants[${esc(k)}][values]" value="${esc(r.values)}"><input type="hidden" name="variants[${esc(k)}][title]" value="${esc(r.title)}"></td>
      <td><input name="variants[${esc(k)}][sku]" value="${esc(r.sku)}" style="min-width:150px"></td>
      <td><input name="variants[${esc(k)}][price]" type="number" step="0.01" value="${esc(r.price)}" placeholder="—" style="width:100px"></td>
      <td><input name="variants[${esc(k)}][compare_at_price]" type="number" step="0.01" value="${esc(r.compare_at_price)}" placeholder="—" style="width:100px"></td>
      <td><select name="variants[${esc(k)}][unit]">${unitSel(r.unit||'')}</select></td>
      <td><select name="variants[${esc(k)}][status]">${['active','inactive','archived'].map(s=>`<option value="${s}" ${s===(r.status||'active')?'selected':''}>${s[0].toUpperCase()+s.slice(1)}</option>`).join('')}</select></td>
      <td><button type="button" class="iconbtn danger" title="Remove">×</button></td>`;
    tr.querySelector('button').onclick=()=>{tr.remove();empty()};
    tbody.appendChild(tr);
  }
  function empty(){if(!tbody.children.length)tbody.innerHTML='<tr class="emptyrow"><td colspan="8" class="muted" style="text-align:center;padding:22px">No variants yet.</td></tr>';else tbody.querySelector('.emptyrow')?.remove()}
  // Initial rows (saved variants, or posted rows after a validation error).
  D.rows.forEach((r,i)=>{seq=Math.max(seq,i+1);addRow(r,D.default!==''?String(r.key)===D.default:+r.is_default===1)});
  // Pre-tick the values already used.
  const used=new Set(D.rows.flatMap(r=>String(r.values).split(',').filter(Boolean)));
  document.querySelectorAll('#optionPicker input').forEach(c=>c.checked=used.has(c.value));
  empty();

  $('#genVariants').onclick=()=>{
    const picked={};document.querySelectorAll('#optionPicker input:checked').forEach(c=>(picked[c.dataset.group]??=[]).push(c.value));
    const lists=groupOrder.filter(g=>picked[g]).map(g=>picked[g]);
    if(!lists.length){$('#genHint').textContent='Tick at least one value first.';return}
    const combos=lists.reduce((acc,l)=>acc.flatMap(a=>l.map(v=>[...a,v])),[[]]);
    const existing={};tbody.querySelectorAll('tr[data-key]').forEach(tr=>existing[tr.dataset.key]=tr);
    const base=(document.querySelector('input[name=sku]').value||'SKU').trim().toUpperCase();
    const price=document.querySelector('input[name=price]').value;
    const keep=new Set();let added=0;
    combos.forEach(ids=>{
      const k=keyOf(ids);keep.add(k);if(existing[k])return;
      const title=ids.map(id=>label[id].value).join(' / ');
      addRow({values:ids.join(','),title,sku:base+'-'+ids.map(id=>abbr(label[id].value)).join('-'),price,status:'active'},!tbody.querySelector('input[type=radio]'));added++;
    });
    let removed=0;Object.entries(existing).forEach(([k,tr])=>{if(!keep.has(k)){tr.remove();removed++}});
    if(!tbody.querySelector('input[type=radio]:checked'))tbody.querySelector('input[type=radio]')?.click();
    empty();
    $('#genHint').textContent=`${combos.length} combination${combos.length===1?'':'s'} · ${added} added · ${removed} removed.`;
  };
  $('#hasVariants').onchange=e=>$('#variantBuilder').classList.toggle('hidden',!e.target.checked);
  const ts=$('#trackStock');ts&&(ts.onchange=e=>{
    $('#stockFields').classList.toggle('hidden',!e.target.checked);
    // On first enable, default the stock unit to the selling unit when unset.
    const stu=$('#stockUnit'),su2=$('#sellingUnit');
    if(e.target.checked&&stu&&!stu.value&&su2&&su2.value)stu.value=su2.value;
  });
  const su=$('#sellingUnit');su&&su.addEventListener('change',()=>$('#packOf').textContent=su.value||'unit');
})();
</script>
JS;
include __DIR__ . '/../partials/footer.php';
?>
