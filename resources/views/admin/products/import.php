<?php
$active = 'products.import';
$title = 'Bulk upload';
$plan = $plan ?? null;
$result = $result ?? null;
$meta = $meta ?? [];
$token = $token ?? '';
$limits = $limits ?? ['upload' => '2M', 'post' => '8M'];
$importError = session()->get('import_error');
$actLabel = ['create' => 'New', 'update' => 'Update', 'variants' => 'Variants only', 'skip' => 'Skipped', 'error' => 'Error'];
$toBytes = static function (string $v): int {
    $n = (float) $v;
    return (int) match (strtoupper(substr(trim($v), -1))) { 'G' => $n * 1073741824, 'M' => $n * 1048576, 'K' => $n * 1024, default => $n };
};
include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div>
    <div class="text-xs muted mb-1"><a href="<?= route('admin.products.index') ?>">Products</a> / Bulk upload</div>
    <h1 style="display:flex;align-items:center;gap:10px">Bulk upload<span style="color:#dca71f">.</span>
      <button type="button" class="helpq" data-import-help title="How does bulk upload work?" aria-label="How does bulk upload work?">?</button>
    </h1>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn" href="<?= route('admin.products.import.template', ['format' => 'csv']) ?>"><i data-lucide="file-text" style="width:16px"></i>CSV template</a>
    <a class="btn gold" href="<?= route('admin.products.import.template', ['format' => 'xlsx']) ?>"><i data-lucide="file-spreadsheet" style="width:16px"></i>Excel template</a>
  </div>
</div>

<?php if ($importError): ?>
<div class="notice" style="margin-bottom:16px;background:#fbe6e1;border-color:#f2c9bf;color:#a2402f;font-size:12.5px">⚠ <?= e((string) $importError) ?></div>
<?php endif; ?>

<?php if ($result): /* ---------------------------------------------------------- RESULT */ ?>
<div class="card" style="margin-bottom:16px">
  <div class="sectionhead" style="margin-bottom:16px"><div class="sectionicon" style="background:#dff3e9;color:#1f7a53"><i data-lucide="circle-check-big"></i></div>
    <div><h2>Import finished</h2><div class="text-xs muted"><?= e((string) ($meta['sheet'] ?? 'File')) ?> · <?= e((string) ($result['finished'] ?? '')) ?></div></div></div>
  <div class="sumgrid">
    <div class="sumbox g"><b><?= (int) $result['created'] ?></b><span>products created</span></div>
    <div class="sumbox y"><b><?= (int) $result['updated'] ?></b><span>products updated</span></div>
    <div class="sumbox b"><b><?= (int) $result['variants'] ?></b><span>variants saved</span></div>
    <div class="sumbox"><b><?= (int) $result['media'] ?></b><span>images / videos saved</span></div>
    <div class="sumbox r"><b><?= (int) $result['skipped'] ?></b><span>rows skipped</span></div>
  </div>
  <?php if (array_filter($result['auto'] ?? [])): ?>
  <div style="margin-top:16px"><b class="text-xs">Created automatically</b>
    <div class="autolist"><?php foreach ($result['auto'] as $kind => $list): foreach ($list as $label): ?><span class="chip"><span class="muted"><?= e($kind) ?>:</span> <?= e($label) ?></span><?php endforeach; endforeach; ?></div></div>
  <?php endif; ?>
  <?php if (!empty($result['warnings'])): ?>
  <div class="notice" style="margin-top:14px;background:#fff8e4;border-color:#f0d78a;color:#86600f"><b>Media warnings (<?= count($result['warnings']) ?>)</b><ul class="msgs w"><?php foreach (array_slice($result['warnings'], 0, 30) as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <div class="footeractions" style="margin-top:18px">
    <a class="btn" href="<?= route('admin.products.import.report', ['token' => $token]) ?>"><i data-lucide="download" style="width:15px"></i>Row report (CSV)</a>
    <div style="display:flex;gap:8px"><a class="btn" href="<?= route('admin.products.import') ?>"><i data-lucide="upload" style="width:15px"></i>Upload another file</a><a class="btn gold" href="<?= route('admin.products.index') ?>">View products →</a></div>
  </div>
</div>

<?php elseif ($plan): /* -------------------------------------------------------- PREVIEW */
    $s = $plan['summary'];
    $ready = $s['create'] + $s['update'] + $s['variants'];
?>
<div class="card" style="margin-bottom:16px">
  <div class="card-h">
    <div class="sectionhead" style="margin:0"><div class="sectionicon"><i data-lucide="scan-search"></i></div>
      <div><h2>Preview — nothing saved yet</h2><div class="text-xs muted"><?= e((string) ($meta['sheet'] ?? '')) ?><?= !empty($meta['variants']) ? ' + ' . e($meta['variants']) : '' ?><?= !empty($meta['zip']) ? ' + ' . e($meta['zip']) : ' · no images ZIP' ?> · mode: <?= e(\App\Services\Import\ProductImporter::MODES[$plan['mode']] ?? $plan['mode']) ?></div></div></div>
    <button type="button" class="helpq" data-import-help title="Help">?</button>
  </div>
  <div class="sumgrid">
    <div class="sumbox g"><b><?= $s['create'] ?></b><span>new products</span></div>
    <div class="sumbox y"><b><?= $s['update'] ?></b><span>updates</span></div>
    <div class="sumbox b"><b><?= $s['variant_rows'] ?></b><span>variants</span></div>
    <div class="sumbox"><b><?= $s['skip'] ?></b><span>skipped</span></div>
    <div class="sumbox r"><b><?= $s['error'] ?></b><span>rows with errors</span></div>
  </div>
  <?php if (array_filter($plan['creates'])): ?>
  <div class="notice" style="margin-top:14px;background:#f3f8ff;border-color:#c9daf2;color:#2f5f9e;font-size:12px">
    <b><i data-lucide="sparkles" style="width:13px;vertical-align:-2px"></i> These will be created automatically:</b>
    <div class="autolist"><?php foreach ($plan['creates'] as $kind => $list): foreach ($list as $label): ?><span class="chip" style="background:#fff;border-color:#c9daf2"><span class="muted"><?= e($kind) ?>:</span> <?= e($label) ?></span><?php endforeach; endforeach; ?></div>
  </div>
  <?php endif; ?>
  <?php foreach ($plan['notices'] as $n): ?><div class="notice" style="margin-top:10px"><?= e($n) ?></div><?php endforeach; ?>

  <form method="post" action="<?= route('admin.products.import.commit', ['token' => $token]) ?>" class="footeractions" style="margin-top:18px" id="commitForm">
    <?= csrf_field() ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn ghost" href="<?= route('admin.products.import') ?>"><i data-lucide="arrow-left" style="width:15px"></i>Start over</a>
      <?php if ($s['error'] || $s['skip']): ?><a class="btn" href="<?= route('admin.products.import.report', ['token' => $token]) ?>"><i data-lucide="download" style="width:15px"></i>Error report (CSV)</a><?php endif; ?>
    </div>
    <button class="btn gold" type="submit" <?= $ready ? '' : 'disabled' ?> id="commitBtn"><i data-lucide="check" style="width:16px"></i>Import <?= $ready ?> row<?= $ready === 1 ? '' : 's' ?><?= $s['error'] ? ' · skip ' . $s['error'] . ' with errors' : '' ?></button>
  </form>
</div>

<div class="card" style="padding:0">
  <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 16px;gap:10px;flex-wrap:wrap">
    <b style="font-size:14px">Rows (<?= count($plan['rows']) ?>)</b>
    <div class="rowfilter" id="rowFilter">
      <button type="button" class="on" data-f="">All</button>
      <?php foreach ($actLabel as $k => $l): if (!$s[$k]) continue; ?><button type="button" data-f="<?= $k ?>"><?= $l ?> (<?= $s[$k] ?>)</button><?php endforeach; ?>
    </div>
  </div>
  <div class="tablewrap" style="border-radius:0 0 24px 24px;border-left:0;border-right:0;border-bottom:0">
    <table class="data" id="planTable">
      <thead><tr><th>Line</th><th>SKU / product</th><th>Result</th><th>Details</th></tr></thead>
      <tbody>
      <?php foreach ($plan['rows'] as $r):
          $d = $r['data'] ?? null;
          $bits = [];
          if ($d) {
              $p = $d['product'];
              if (isset($p['status'])) $bits[] = str_replace('_', ' ', $p['status']);
              if (isset($d['pricing']['price'])) $bits[] = '₹' . rtrim(rtrim(number_format((float) $d['pricing']['price'], 2), '0'), '.') . (isset($d['pricing']['unit']) ? ' / ' . $d['pricing']['unit'] : '');
              if (!empty($d['categories']['list'])) $bits[] = count($d['categories']['list']) . ' categor' . (count($d['categories']['list']) === 1 ? 'y' : 'ies');
              $na = count(array_filter(array_keys($d['attrs']), static fn($k) => $k !== '__new'));
              if ($na) $bits[] = $na . ' attribute' . ($na === 1 ? '' : 's');
              if (!empty($d['media'])) $bits[] = count(array_filter($d['media'], static fn($m) => empty($m['clear']))) . ' media';
          }
          if ($r['variants']) $bits[] = count($r['variants']) . ' variant' . (count($r['variants']) === 1 ? '' : 's');
      ?>
        <tr data-act="<?= e($r['action']) ?>">
          <td class="muted"><?= e((string) $r['line']) ?></td>
          <td><b><?= e((string) $r['sku'] ?: '—') ?></b><small class="block muted text-xs"><?= e((string) $r['name']) ?></small></td>
          <td><span class="act <?= e($r['action']) ?>"><?= $actLabel[$r['action']] ?? $r['action'] ?></span></td>
          <td>
            <?php if ($bits): ?><span class="text-xs muted"><?= e(implode(' · ', $bits)) ?></span><?php endif; ?>
            <?php if ($r['errors']): ?><ul class="msgs e"><?php foreach ($r['errors'] as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul><?php endif; ?>
            <?php if ($r['warnings']): ?><ul class="msgs w"><?php foreach ($r['warnings'] as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else: /* ---------------------------------------------------------------- UPLOAD */ ?>
<div class="card" style="margin-bottom:16px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;background:linear-gradient(135deg,#fff7dc,#fffdf8)">
  <div class="sectionicon"><i data-lucide="lightbulb"></i></div>
  <div style="flex:1;min-width:220px"><b>First time?</b> <span class="muted" style="font-size:13px">See the 5-step process, the image options and every column in one place.</span></div>
  <button type="button" class="btn" data-import-help><span class="helpq" style="width:22px;height:22px;font-size:12px;box-shadow:none">?</span>How it works &amp; formats</button>
</div>

<form method="post" enctype="multipart/form-data" action="<?= route('admin.products.import.preview') ?>" class="card" id="uploadForm">
  <?= csrf_field() ?>
  <div class="sectionhead"><div class="sectionicon"><i data-lucide="upload"></i></div><div><h2>Upload your file</h2><div class="text-xs muted">You'll see a full preview before anything is saved.</div></div></div>

  <div class="upgrid">
    <label class="dropzone" id="sheetZone">
      <input type="file" name="sheet" accept=".xlsx,.csv" required>
      <div class="dz-ic"><i data-lucide="file-spreadsheet"></i></div>
      <b>Products file <span style="color:#c4850b">*</span></b>
      <small>.xlsx (Products + Variants sheets) or .csv — click or drop</small>
      <div class="dz-file"></div>
    </label>
    <label class="dropzone" id="zipZone">
      <input type="file" name="images_zip" accept=".zip">
      <div class="dz-ic"><i data-lucide="file-archive"></i></div>
      <b>Images ZIP <span class="muted" style="font-weight:600">optional</span></b>
      <small>Photos named as in the image columns. Links need no ZIP.</small>
      <div class="dz-file"></div>
    </label>
    <label class="dropzone" id="varZone">
      <input type="file" name="variants_file" accept=".csv,.xlsx">
      <div class="dz-ic"><i data-lucide="layers"></i></div>
      <b>Variants CSV <span class="muted" style="font-weight:600">optional</span></b>
      <small>Only for CSV uploads — the Excel template has a Variants sheet.</small>
      <div class="dz-file"></div>
    </label>
  </div>

  <div class="field" style="margin-top:18px">
    <label>When a SKU already exists</label>
    <div class="modepick">
      <?php foreach ($modes as $k => $l): ?><label><input type="radio" name="mode" value="<?= e($k) ?>" <?= $k === 'upsert' ? 'checked' : '' ?>><?= e($l) ?></label><?php endforeach; ?>
    </div>
  </div>

  <div class="footeractions">
    <span class="hint" id="sizeHint">Max <?= e($limits['upload']) ?> per file · <?= e($limits['post']) ?> total. For big photo sets use image links instead of one huge ZIP.</span>
    <button class="btn gold" type="submit" id="previewBtn"><i data-lucide="scan-search" style="width:16px"></i>Check file &amp; preview</button>
  </div>
</form>
<?php endif; ?>

<?php
include __DIR__ . '/partials/import-help.php';
$maxFile = $toBytes($limits['upload']);
$maxPost = $toBytes($limits['post']);
$scripts = <<<JS
<script>
(function(){
  const maxFile={$maxFile},maxPost={$maxPost};
  document.querySelectorAll('.dropzone').forEach(z=>{
    const inp=z.querySelector('input'),out=z.querySelector('.dz-file');
    const show=()=>{const f=inp.files[0];z.classList.toggle('has',!!f);if(f)out.textContent='✓ '+f.name+' · '+(f.size/1048576).toFixed(1)+' MB';};
    inp.addEventListener('change',show);
    ['dragenter','dragover'].forEach(ev=>z.addEventListener(ev,e=>{e.preventDefault();z.classList.add('drag')}));
    ['dragleave','drop'].forEach(ev=>z.addEventListener(ev,e=>{e.preventDefault();z.classList.remove('drag')}));
    z.addEventListener('drop',e=>{if(e.dataTransfer.files.length){inp.files=e.dataTransfer.files;show();}});
  });
  const form=document.getElementById('uploadForm');
  if(form)form.addEventListener('submit',e=>{
    let total=0,big=null;form.querySelectorAll('input[type=file]').forEach(i=>{const f=i.files[0];if(f){total+=f.size;if(f.size>maxFile)big=f;}});
    const h=document.getElementById('sizeHint');
    if(big||total>maxPost){e.preventDefault();h.style.color='#b4452f';h.textContent=big?('"'+big.name+'" is larger than the server limit. Use a smaller ZIP or image links.'):'All files together are larger than the server limit.';return;}
    const b=document.getElementById('previewBtn');b.disabled=true;b.lastChild.textContent=' Checking rows…';
  });
  const cf=document.getElementById('commitForm');
  if(cf)cf.addEventListener('submit',()=>{const b=document.getElementById('commitBtn');setTimeout(()=>{b.disabled=true;b.lastChild.textContent=' Importing — downloading images may take a minute…';},0);});
  const rf=document.getElementById('rowFilter');
  if(rf)rf.addEventListener('click',e=>{const b=e.target.closest('button');if(!b)return;
    rf.querySelectorAll('button').forEach(x=>x.classList.toggle('on',x===b));
    document.querySelectorAll('#planTable tbody tr').forEach(tr=>tr.classList.toggle('hidden',!!b.dataset.f&&tr.dataset.act!==b.dataset.f));});
})();
</script>
JS;
include __DIR__ . '/../partials/footer.php';
?>
