<?php
/**
 * "?" help modal for bulk upload. Include once per page; open it with any element carrying [data-import-help].
 * Expects $columns (ProductImporter::columns()) or builds it.
 */
use App\Services\Import\ProductImporter;

$helpColumns = $columns ?? ProductImporter::columns();
$helpLimits = \App\Controllers\Admin\ImportController::limits();
$onImportPage = ($active ?? '') === 'products.import';
$tpl = static fn(string $f) => route('admin.products.import.template', ['format' => $f]);
?>
<div class="modal-backdrop" id="importHelp" role="dialog" aria-modal="true" aria-labelledby="importHelpTitle">
  <div class="modal wide">
    <div class="modal-h">
      <div>
        <div class="eyebrow">Bulk upload · Excel or CSV</div>
        <h3 id="importHelpTitle">How bulk product upload works</h3>
        <p class="text-xs muted mt-1">Add or update hundreds of products from one spreadsheet. Nothing is saved until you check the preview and press <b>Import</b>.</p>
      </div>
      <div style="display:flex;gap:6px">
        <button type="button" class="iconbtn" title="Replay animation" data-help-replay><i data-lucide="rotate-ccw"></i></button>
        <button type="button" class="iconbtn" data-close title="Close"><i data-lucide="x"></i></button>
      </div>
    </div>

    <!-- Visual process -->
    <div class="proc" id="procSteps">
      <div class="proc-step"><div class="proc-ic"><i data-lucide="file-down"></i><span class="proc-n">1</span></div><div><b>Download the template</b><p>The Excel file already has dropdowns for your brands, categories, units and attribute values.</p></div></div>
      <div class="proc-step"><div class="proc-ic"><i data-lucide="table-2"></i><span class="proc-n">2</span></div><div><b>One row per product</b><p>Only <code>sku</code> and <code>name</code> are required. Put variants on the <i>Variants</i> sheet.</p></div></div>
      <div class="proc-step"><div class="proc-ic"><i data-lucide="images"></i><span class="proc-n">3</span></div><div><b>Add images</b><p>ZIP your photos and type the file names, or paste <code>https://</code> image links.</p></div></div>
      <div class="proc-step"><div class="proc-ic"><i data-lucide="scan-search"></i><span class="proc-n">4</span></div><div><b>Upload &amp; preview</b><p>Every row is checked and marked <span class="act create">New</span> <span class="act update">Update</span> or <span class="act error">Error</span>. Nothing is saved yet.</p></div></div>
      <div class="proc-step"><div class="proc-ic"><i data-lucide="circle-check-big"></i><span class="proc-n">5</span></div><div><b>Confirm import</b><p>Valid rows are saved together. Download the error report, fix it in Excel, upload again.</p></div></div>
    </div>

    <div class="howgrid">
      <div class="howcard">
        <h4><i data-lucide="fingerprint"></i>Rows are matched by SKU</h4>
        A new SKU creates a product; a SKU already in the catalogue updates it.
        <div class="minisheet">
          <div><span></span><span>sku</span><span>name</span><span>result</span></div>
          <div><span>2</span><span>MS-STA-001</span><span>Statuario</span><span class="new">＋ new</span></div>
          <div><span>3</span><span>MS-RJG-002</span><span>Green</span><span class="upd">↻ update</span></div>
        </div>
        <p style="margin:8px 0 0">When updating, a <b>blank cell keeps</b> the current value. Type a single <code>-</code> to clear it.</p>
      </div>
      <div class="howcard">
        <h4><i data-lucide="image"></i>Images: ZIP or link — both work</h4>
        <div class="srcrow"><i data-lucide="file-archive"></i><code>statuario-cover.jpg</code><span class="muted">→ found in images.zip</span></div>
        <div class="srcrow"><i data-lucide="link"></i><code>https://…/slab-2.jpg</code><span class="muted">→ downloaded</span></div>
        <p style="margin:8px 0 0">Folders inside the ZIP don't matter, only the file name. Up to 20 MB per image. This server accepts uploads up to <b><?= e($helpLimits['upload']) ?></b> per file.</p>
      </div>
      <div class="howcard">
        <h4><i data-lucide="sparkles"></i>Created automatically</h4>
        If a value isn't in the catalogue yet, it's created during the import. The preview lists them first.
        <div class="autolist">
          <span class="chip">Brands</span><span class="chip">Categories</span><span class="chip">Sub-categories</span><span class="chip">Tags</span><span class="chip">Attribute values</span><span class="chip">Option values</span>
        </div>
        <p style="margin:8px 0 0">Sub-category: write <code>Flooring &gt; Living Room</code>. Several values: separate with <code>|</code>.</p>
      </div>
    </div>

    <div class="card-h" style="margin-bottom:10px"><h3 style="font-size:14px">Download the format</h3><span class="text-xs muted">Delete the SAMPLE rows before uploading — they are skipped anyway.</span></div>
    <div class="dlgrid">
      <a class="dlcard" href="<?= $tpl('xlsx') ?>"><span class="fic xlsx">XLSX</span><span class="rec">RECOMMENDED</span><b>Excel template</b><small>Products + Variants sheets, dropdowns, column guide and lookup lists.</small></a>
      <a class="dlcard" href="<?= $tpl('csv') ?>"><span class="fic csv">CSV</span><b>Products CSV</b><small>Same columns as the Products sheet. Save as “CSV UTF-8”.</small></a>
      <a class="dlcard" href="<?= $tpl('variants-csv') ?>"><span class="fic var">CSV</span><b>Variants CSV</b><small>Optional — sizes, finishes and thicknesses linked by <code>product_sku</code>.</small></a>
      <a class="dlcard" href="<?= $tpl('guide-csv') ?>"><span class="fic guide">DOC</span><b>Column guide</b><small>Every column, whether it's required, and an example value.</small></a>
    </div>

    <details class="colref">
      <summary><i data-lucide="list" style="width:15px"></i>All columns (<?= count($helpColumns['products']) ?> product · <?= count($helpColumns['variants']) ?> variant)<span class="muted" style="font-weight:600;margin-left:auto;font-size:11.5px"><span class="reqdot"></span> required</span></summary>
      <div style="max-height:320px;overflow:auto">
        <table class="data">
          <thead><tr><th>Column</th><th>Group</th><th>What to enter</th><th>Example</th></tr></thead>
          <tbody>
          <?php foreach (['products' => 'Products sheet', 'variants' => 'Variants sheet'] as $sheetKey => $sheetLabel): ?>
            <tr><td colspan="4" style="background:#f7f1e5;font-weight:800;font-size:11.5px"><?= $sheetLabel ?></td></tr>
            <?php foreach ($helpColumns[$sheetKey] as $c): ?>
            <tr><td><code><?= e($c['key']) ?></code><?= $c['required'] ? '<span class="reqdot" title="Required"></span>' : '' ?></td><td class="muted"><?= e($c['group']) ?></td><td><?= e($c['help']) ?></td><td class="muted"><?= e($c['example']) ?></td></tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>

    <div class="modal-f">
      <button type="button" class="btn" data-close>Close</button>
      <?php if ($onImportPage): ?>
      <button type="button" class="btn gold" data-close data-focus-upload><i data-lucide="upload" style="width:16px"></i>Start uploading</button>
      <?php else: ?>
      <a class="btn gold" href="<?= route('admin.products.import') ?>"><i data-lucide="upload" style="width:16px"></i>Go to bulk upload</a>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
(function(){
  const m=document.getElementById('importHelp');if(!m)return;
  const steps=[].slice.call(m.querySelectorAll('.proc-step'));let timers=[];
  function play(){timers.forEach(clearTimeout);timers=[];steps.forEach(s=>s.classList.remove('on'));
    steps.forEach((s,i)=>timers.push(setTimeout(()=>s.classList.add('on'),180+i*380)));}
  function open(){m.classList.add('show');play();}
  function close(){m.classList.remove('show');}
  document.addEventListener('click',e=>{if(e.target.closest('[data-import-help]')){e.preventDefault();open();}});
  m.querySelectorAll('[data-close]').forEach(b=>b.addEventListener('click',close));
  m.querySelector('[data-help-replay]').addEventListener('click',play);
  m.addEventListener('click',e=>{if(e.target===m)close()});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&m.classList.contains('show'))close()});
  const f=m.querySelector('[data-focus-upload]');if(f)f.addEventListener('click',()=>{const z=document.getElementById('sheetZone');if(z){z.scrollIntoView({behavior:'smooth',block:'center'});z.classList.add('drag');setTimeout(()=>z.classList.remove('drag'),1200);}});
  if(location.hash==='#how')open();
})();
</script>
