<?php
$active = 'products';
$p = $product;
$pid = (int) $p['id'];
$title = (string) $p['name'];
$media = $media ?? [];
$variants = $variants ?? [];
$variantOf = $variantOf ?? [];
$pricing = $pricing ?? null;
$stock = $stock ?? null;
$reviews = $reviews ?? ['n' => 0, 'avg' => null, 'pending' => 0];
$money = static fn($v, $cur = 'INR') => $v === null || $v === '' ? '—' : ($cur === 'INR' ? '₹' : $cur . ' ') . number_format((float) $v, 2);
$imgCount = count(array_filter($media, static fn($m) => $m['kind'] === 'image'));
$noAlt = count(array_filter($media, static fn($m) => $m['kind'] === 'image' && trim((string) $m['alt_text']) === ''));
include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div>
    <div class="crumbs">Products <i data-lucide="chevron-right"></i> <a href="<?= route('admin.products.index') ?>" style="color:inherit">All products</a> <i data-lucide="chevron-right"></i> <?= e($p['sku']) ?></div>
    <h1><?= e($p['name']) ?><span style="color:#dca71f">.</span></h1>
    <div style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap">
      <span class="badge <?= e($p['status']) ?>"><?= e(str_replace('_', ' ', (string) $p['status'])) ?></span>
      <?php if ((int) $p['is_featured']): ?><span class="pill pill-gold">Featured</span><?php endif; ?>
      <?php if ((int) $p['is_bestseller']): ?><span class="pill pill-gold">Bestseller</span><?php endif; ?>
      <?php if ((int) $p['is_enquiry_only']): ?><span class="pill pill-off">Enquiry only</span><?php endif; ?>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn" href="<?= route('admin.products.index') ?>"><i data-lucide="arrow-left" style="width:15px"></i>All products</a>
    <a class="btn gold" href="<?= route('admin.products.edit', ['id' => $pid]) ?>"><i data-lucide="pencil" style="width:15px"></i>Edit product</a>
  </div>
</div>

<div class="pd-layout">
  <div class="card">
    <div class="card-h" style="flex-wrap:wrap">
      <div><div class="eyebrow">Gallery</div><h3><?= $imgCount ?> image<?= $imgCount === 1 ? '' : 's' ?><?= count($media) > $imgCount ? ' · ' . (count($media) - $imgCount) . ' video' : '' ?></h3>
        <p class="text-xs muted mt-1">Drag to reorder. The cover shows on product cards and search.<?php if ($noAlt): ?> <b style="color:#b4452f"><?= $noAlt ?> without alt text.</b><?php endif; ?></p></div>
      <button class="btn" type="button" id="pickLib"><i data-lucide="library" style="width:15px"></i>Pick from library</button>
    </div>
    <?php $up = ['id' => 'galUploader', 'kinds' => ['image', 'video'], 'owner_type' => 'product', 'owner_id' => $pid, 'title' => 'Drop product photos or a video here', 'compact' => true, 'limits' => $limits ?? []];
    include __DIR__ . '/../partials/uploader.php'; ?>

    <div class="gal-grid" id="galGrid">
      <?php if (!$media): ?><div class="gal-empty">No images yet. Upload some above or pick from the library.</div><?php endif; ?>
      <?php foreach ($media as $m): $mid = (int) $m['id']; $alt = trim((string) $m['alt_text']); ?>
      <div class="gal-item<?= $m['role'] === 'cover' ? ' cover' : '' ?>" draggable="true" data-id="<?= $mid ?>">
        <?php if ($m['role'] === 'cover'): ?><span class="gal-badge">Cover</span><?php elseif ($m['kind'] === 'video'): ?><span class="gal-badge video">Video</span><?php endif; ?>
        <span class="gal-grip" title="Drag to reorder"><i data-lucide="grip-vertical"></i></span>
        <div class="gal-img"><?php if ($m['thumb_url']): ?><img src="<?= e($m['thumb_url']) ?>" alt="<?= e($alt) ?>"><?php else: ?><i data-lucide="film"></i><?php endif; ?></div>
        <div class="gal-foot">
          <div class="gal-alt<?= $m['kind'] === 'image' && $alt === '' ? ' missing' : '' ?>" title="<?= e($alt) ?>"><?= e($m['media_no']) ?> · <?= $m['kind'] === 'image' ? ($alt !== '' ? e($alt) : 'No alt text') : e($m['size_label']) ?></div>
          <?php if ($variants && $m['kind'] === 'image'): ?>
          <select data-variant aria-label="Show for variant">
            <option value="0">All variants</option>
            <?php foreach ($variants as $v): ?><option value="<?= (int) $v['id'] ?>"<?= ($variantOf[$mid] ?? 0) === (int) $v['id'] ? ' selected' : '' ?>><?= e($v['title']) ?></option><?php endforeach; ?>
          </select>
          <?php endif; ?>
          <div class="gal-acts">
            <?php if ($m['kind'] === 'image' && $m['role'] !== 'cover'): ?><button type="button" class="iconbtn" data-act="cover" title="Make cover"><i data-lucide="star"></i></button><?php endif; ?>
            <?php if ($m['kind'] === 'image'): ?><button type="button" class="iconbtn" data-act="alt" title="Alt text"><i data-lucide="text-cursor-input"></i></button><?php endif; ?>
            <a class="iconbtn" href="<?= e($m['url']) ?>" target="_blank" rel="noopener" title="Open full size"><i data-lucide="maximize-2"></i></a>
            <button type="button" class="iconbtn danger" data-act="remove" title="Remove from product" style="margin-left:auto"><i data-lucide="x"></i></button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="pd-side">
    <div class="card">
      <div class="card-h"><div><div class="eyebrow">Product</div><h3>Details</h3></div></div>
      <dl class="kv">
        <dt>SKU</dt><dd><?= e($p['sku']) ?></dd>
        <dt>Type</dt><dd><?= e((string) ($p['type_name'] ?? '—')) ?></dd>
        <dt>Brand</dt><dd><?= e((string) ($p['brand_name'] ?: '—')) ?></dd>
        <dt>Categories</dt><dd><?= $categories ? implode(', ', array_map(static fn($c) => e($c['name']) . ((int) $c['is_primary'] ? ' <span class="pill pill-gold">primary</span>' : ''), $categories)) : '—' ?></dd>
        <dt>Tags</dt><dd><?= $tags ? implode('', array_map(static fn($t) => '<span class="chip">' . e($t['name']) . '</span>', $tags)) : '—' ?></dd>
        <dt>Updated</dt><dd><?= e((string) ($p['updated_at'] ?? '—')) ?></dd>
      </dl>
      <?php if ($p['short_description']): ?><p class="text-sm" style="margin:14px 0 0;color:#5d5544"><?= e($p['short_description']) ?></p><?php endif; ?>
    </div>

    <div class="card">
      <div class="card-h"><div><div class="eyebrow">Pricing</div><h3><?= $pricing ? $money($pricing['price'], $pricing['currency'] ?? 'INR') : '—' ?><?php if ($pricing && $pricing['unit']): ?><span class="muted text-sm" style="font-weight:600"> / <?= e($pricing['unit']) ?></span><?php endif; ?></h3></div></div>
      <dl class="kv">
        <dt>Compare at</dt><dd><?= $pricing ? $money($pricing['compare_at_price'], $pricing['currency'] ?? 'INR') : '—' ?></dd>
        <dt>Cost</dt><dd><?= $pricing ? $money($pricing['cost_price'], $pricing['currency'] ?? 'INR') : '—' ?></dd>
        <?php if ($pricing && $pricing['pack_qty']): ?><dt>Pack</dt><dd><?= e(rtrim(rtrim((string) $pricing['pack_qty'], '0'), '.')) ?> <?= e((string) $pricing['pack_unit']) ?></dd><?php endif; ?>
        <dt>Show price</dt><dd><?= (int) $p['show_price'] ? 'Yes' : 'No' ?></dd>
      </dl>
    </div>

    <div class="card">
      <div class="card-h"><div><div class="eyebrow">Inventory</div><h3>Stock</h3></div><a class="btn ghost" href="<?= route('admin.inventory.stock') ?>?product=<?= $pid ?>">Manage</a></div>
      <?php if ($stock): ?>
        <div style="display:flex;align-items:baseline;gap:8px"><b style="font-size:26px"><?= e(rtrim(rtrim(number_format((float) $stock['quantity'], 3, '.', ''), '0'), '.')) ?></b><span class="muted"><?= e($stock['unit']) ?></span>
          <span class="pill<?= $stock['status'] === 'healthy' ? '' : ($stock['status'] === 'low' ? ' pill-gold' : ' pill-off') ?>" style="margin-left:auto"><?= e(ucfirst((string) $stock['status'])) ?></span></div>
        <?php if ($stock['low_stock_threshold'] !== null): ?><p class="text-xs muted mt-1">Low-stock alert at <?= e(rtrim(rtrim((string) $stock['low_stock_threshold'], '0'), '.')) ?> <?= e($stock['unit']) ?></p><?php endif; ?>
      <?php else: ?><p class="text-sm muted" style="margin:0">Stock is not tracked for this product.</p><?php endif; ?>
    </div>

    <div class="card">
      <div class="card-h"><div><div class="eyebrow">Reviews</div><h3><?= (int) $reviews['n'] ? number_format((float) $reviews['avg'], 1) . ' ★' : 'No reviews yet' ?></h3></div><a class="btn ghost" href="<?= route('admin.reviews') ?>">Open</a></div>
      <p class="text-sm muted" style="margin:0"><?= (int) $reviews['n'] ?> review<?= (int) $reviews['n'] === 1 ? '' : 's' ?><?= (int) $reviews['pending'] ? ' · ' . (int) $reviews['pending'] . ' waiting for approval' : '' ?></p>
    </div>

    <?php if ($variants): ?>
    <div class="card">
      <div class="card-h"><div><div class="eyebrow">Variants</div><h3><?= count($variants) ?> option<?= count($variants) === 1 ? '' : 's' ?></h3></div></div>
      <div class="stack" style="gap:8px">
        <?php foreach ($variants as $v): ?>
        <div style="display:flex;justify-content:space-between;gap:8px;font-size:13px"><span><b><?= e($v['title']) ?></b><?= (int) $v['is_default'] ? ' <span class="pill pill-gold">default</span>' : '' ?><br><span class="muted text-xs"><?= e($v['sku']) ?></span></span><span><?= $money($v['price']) ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($attributes): ?>
    <div class="card">
      <div class="card-h"><div><div class="eyebrow">Attributes</div><h3>Specifications</h3></div></div>
      <dl class="kv"><?php foreach ($attributes as $a): ?><dt><?= e($a['name']) ?></dt><dd><?= e($a['value']) ?><?= $a['unit'] ? ' ' . e($a['unit']) : '' ?></dd><?php endforeach; ?></dl>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php
$scripts = ($scripts ?? '') . '<script>window.MS_PRODUCT=' . json_encode(['id' => $pid], JSON_HEX_TAG) . ';</script>' . <<<'JS'
<script>
(function(){
  var M=window.msMedia, pid=window.MS_PRODUCT.id, grid=document.getElementById('galGrid');
  var base='/admin/products/'+pid+'/media';
  function reload(){location.reload();}
  function act(path,data,okMsg){return M.post(M.url(base+path),data||{}).then(function(j){if(j.ok){if(okMsg!==false)msToast.success(j.message);}else msToast.error(j.error||'Something went wrong');return j;});}

  document.getElementById('galUploader').addEventListener('ms:idle',function(){setTimeout(reload,700);});
  document.getElementById('pickLib').onclick=function(){
    M.pick({kinds:['image','video'],multiple:true}).then(function(list){
      if(!list.length)return;
      act('/attach',{media_ids:list.map(function(m){return m.id;})}).then(function(j){if(j.ok)setTimeout(reload,500);});
    });
  };

  grid.addEventListener('click',function(e){
    var b=e.target.closest('[data-act]');if(!b)return;
    var item=b.closest('.gal-item'), id=item.dataset.id;
    if(b.dataset.act==='cover'){act('/'+id+'/cover').then(function(j){if(j.ok)setTimeout(reload,400);});}
    if(b.dataset.act==='remove'){if(!confirm('Remove this file from the product? It stays in the media library if used elsewhere.'))return;
      act('/'+id+'/delete').then(function(j){if(j.ok){item.remove();setTimeout(reload,500);}});}
    if(b.dataset.act==='alt'){
      var cur=item.querySelector('img')?item.querySelector('img').alt:'';
      var v=prompt('Alt text: describe what the photo shows (e.g. "Statuario marble slab, polished finish")',cur);
      if(v===null)return;
      M.post(M.url('/admin/media/'+id+'/update'),{alt_text:v}).then(function(j){if(j.ok){msToast.success('Alt text saved');setTimeout(reload,500);}else msToast.error(j.error);});
    }
  });
  grid.addEventListener('change',function(e){
    if(!e.target.matches('[data-variant]'))return;
    var id=e.target.closest('.gal-item').dataset.id;
    act('/'+id+'/variant',{variant_id:e.target.value});
  });

  // Drag & drop reorder (HTML5); saved when dropped.
  var dragged=null;
  grid.addEventListener('dragstart',function(e){dragged=e.target.closest('.gal-item');if(!dragged)return;dragged.classList.add('dragging');e.dataTransfer.effectAllowed='move';});
  grid.addEventListener('dragend',function(){if(dragged)dragged.classList.remove('dragging');grid.querySelectorAll('.over').forEach(function(x){x.classList.remove('over');});dragged=null;});
  grid.addEventListener('dragover',function(e){var t=e.target.closest('.gal-item');if(!dragged||!t||t===dragged)return;e.preventDefault();
    grid.querySelectorAll('.over').forEach(function(x){x.classList.remove('over');});t.classList.add('over');});
  grid.addEventListener('drop',function(e){var t=e.target.closest('.gal-item');if(!dragged||!t||t===dragged)return;e.preventDefault();
    var r=t.getBoundingClientRect(), after=(e.clientX-r.left)>r.width/2;
    t.parentNode.insertBefore(dragged,after?t.nextSibling:t);t.classList.remove('over');
    var order=Array.prototype.map.call(grid.querySelectorAll('.gal-item'),function(x){return x.dataset.id;});
    act('/reorder',{order:order.join(',')});
  });
})();
</script>
JS;
include __DIR__ . '/../partials/footer.php';
