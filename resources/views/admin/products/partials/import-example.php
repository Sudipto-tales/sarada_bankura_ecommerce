<?php
/**
 * "What does one row look like?" — an example product row plus how to write image addresses.
 * Used on the upload form and inside the preview modal.
 */
$exRow = [
    ['sku', 'KAJ-GVT-001', 'Required. Your own unique code — re-uploading the same sku updates the product.'],
    ['name', 'Breccia Azure', 'Required.'],
    ['status', 'draft', 'draft · active · coming_soon · archived'],
    ['brand', 'Kajaria', 'Created if new.'],
    ['primary_category', 'Tiles > Glazed Vitrified Tiles', 'Parent > Child. Created if new.'],
    ['tags', 'gvt | large format | polish', 'Separate with | or comma.'],
    ['short_description', 'Glazed vitrified tile, 120×240 cm, polish finish.', 'Up to 500 characters.'],
    ['price', '85', 'Numbers only. Blank = no price.'],
    ['unit', 'sq ft', 'Must be a known unit.'],
    ['is_enquiry_only', 'no', 'yes / no'],
    ['attr:slab_size', '1200 × 2400 mm', 'Library sizes are in mm.'],
    ['attr:finish', 'Polished', 'One value.'],
    ['attr:application', 'Flooring | Wall cladding', 'Several values: separate with |.'],
    ['cover_image', 'kajariya1.jpg', 'Main photo — just the file name from your ZIP.'],
    ['gallery_1', 'kajariya1-room.jpg', 'Optional extra photos (gallery_1 … gallery_3).'],
];
?>
<div class="exrow">
  <div class="exrow-h"><i data-lucide="table-2"></i><b>Example row</b><span class="muted">one product = one row · headers in row 1 exactly as shown</span></div>
  <div class="exrow-scroll">
    <table class="exsheet">
      <thead><tr><th></th><?php foreach ($exRow as [$k]): ?><th><?= e($k) ?></th><?php endforeach; ?></tr></thead>
      <tbody><tr><td class="ln">2</td><?php foreach ($exRow as [$k, $v]): ?><td class="<?= in_array($k, ['cover_image', 'gallery_1'], true) ? 'img' : '' ?>"><?= e($v) ?></td><?php endforeach; ?></tr></tbody>
    </table>
  </div>
  <details class="exrow-notes"><summary>What each cell means</summary>
    <ul><?php foreach ($exRow as [$k, $v, $n]): ?><li><code><?= e($k) ?></code> <?= e($n) ?></li><?php endforeach; ?></ul>
  </details>

  <div class="exrow-h" style="margin-top:14px"><i data-lucide="image"></i><b>Image address — what to type in <code>cover_image</code> / <code>gallery_1…3</code></b></div>
  <div class="imgways">
    <div class="ok"><span>✓</span><code>kajariya1.jpg</code><small><b>Just the file name</b> — the photo must be inside the images ZIP you upload with the sheet. Capital letters don't matter.</small></div>
    <div class="ok"><span>✓</span><code>photos/kajariya1.jpg</code><small>Folder part is ignored — only <code>kajariya1.jpg</code> is looked up in the ZIP.</small></div>
    <div class="ok"><span>✓</span><code>https://cdn.example.com/kajariya1.jpg</code><small>A direct image link — downloaded during import. No ZIP needed.</small></div>
    <div class="no"><span>✕</span><code>kajariya1</code><small>No extension — skipped. Use .jpg .jpeg .png .webp .gif .avif (video: .mp4 .webm .mov).</small></div>
    <div class="no"><span>✕</span><code>https://drive.google.com/file/d/…/view</code><small>Share/preview pages are web pages, not images. Use the ZIP instead.</small></div>
  </div>
  <div class="zipdemo">
    <div><b>images.zip</b></div>
    <div>├─ kajariya1.jpg <span class="muted">← cover_image of KAJ-GVT-001</span></div>
    <div>├─ kajariya1-room.jpg <span class="muted">← gallery_1 of KAJ-GVT-001</span></div>
    <div>└─ photos/kajariya2.jpg <span class="muted">← folders are fine</span></div>
  </div>
  <p class="text-xs muted" style="margin:8px 0 0">Every file name must be unique across the ZIP. Max 20 MB per image. On an update, filling any image column replaces that product's photos; leave them blank to keep the current ones.</p>
</div>
<?php if (empty($GLOBALS['__importExampleCss'])): $GLOBALS['__importExampleCss'] = true; ?>
<style>
.exrow{border:1px solid #eadcc5;border-radius:16px;background:#fffdf8;padding:14px 16px}
.exrow-h{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:13px;margin-bottom:8px}
.exrow-h svg{width:16px;height:16px;color:#c4850b}
.exrow-h .muted{font-size:11.5px}
.exrow-scroll{overflow-x:auto;border:1px solid #e5d6bb;border-radius:10px;background:#fff}
.exsheet{border-collapse:collapse;font-size:11.5px;white-space:nowrap}
.exsheet th,.exsheet td{padding:6px 9px;border-left:1px solid #f0e6d4;text-align:left}
.exsheet th{background:#f3ece0;font-weight:800;color:#5d5241;font-family:ui-monospace,monospace;font-size:11px}
.exsheet td{border-top:1px solid #f0e6d4}
.exsheet .ln{color:#a3977f;text-align:center;background:#faf6ee}
.exsheet td.img{background:#eef5ff;color:#2f5f9e;font-weight:700}
.exrow-notes{margin-top:8px;font-size:12px}
.exrow-notes summary{cursor:pointer;font-weight:700;color:#7a6b52}
.exrow-notes ul{margin:6px 0 0;padding-left:18px;columns:2;column-gap:24px}
.exrow-notes li{margin:2px 0;break-inside:avoid}
.imgways{display:grid;gap:6px}
.imgways>div{display:grid;grid-template-columns:22px minmax(0,auto) 1fr;gap:10px;align-items:baseline;font-size:12px;padding:6px 10px;border-radius:10px}
.imgways .ok{background:#effaf3}.imgways .ok>span{color:#1f7a53;font-weight:800}
.imgways .no{background:#fdf0ec}.imgways .no>span{color:#b4452f;font-weight:800}
.imgways code{word-break:break-all}
.imgways small{color:#6b604d}
.zipdemo{margin-top:10px;font-family:ui-monospace,monospace;font-size:11.5px;background:#2c281f;color:#f3ece0;border-radius:10px;padding:10px 12px;line-height:1.6}
.zipdemo .muted{color:#a3977f}
@media (max-width:640px){.exrow-notes ul{columns:1}.imgways>div{grid-template-columns:18px 1fr}.imgways small{grid-column:2}}
</style>
<?php endif; ?>
