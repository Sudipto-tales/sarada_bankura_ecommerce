<?php
use App\Controllers\Admin\TagController;

$active = 'tags';
$title = 'Tags';
$rows = $rows ?? [];
$examples = $examples ?? [];
$stats = $stats ?? ['active' => 0, 'tagged' => 0, 'assignments' => 0, 'merch' => 0, 'unused' => 0];
$success = $success ?? null;
$error = $error ?? null;
$purposeCls = ['discovery' => 'tier-core', 'merchandising' => 'tier-signature', 'internal' => 'tier-value'];
$live = array_values(array_filter($rows, static fn($r) => $r['status'] !== 'archived'));
$maxUse = max(1, ...array_map(static fn($r) => (int) $r['product_count'], $live ?: [['product_count' => 0]]));

include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs">Catalog <i data-lucide="chevron-right"></i> Tags</div><h1>Tags<span style="color:#dca71f">.</span></h1></div>
  <div class="flex gap-2">
    <button class="btn" type="button" onclick="msExportTable('tagTable','tags.csv')"><i data-lucide="download" style="width:16px"></i>Export</button>
    <button class="btn gold" type="button" data-open-tag><i data-lucide="plus" style="width:16px"></i>Add tag</button>
  </div>
</div>
<?php include __DIR__ . '/../partials/catalog-tabs.php'; ?>
<?php if ($success): ?><div class="notice ok">✓ <?= e($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice bad"><?= e($error) ?></div><?php endif; ?>

<div class="statstrip">
  <div class="stat glass"><span class="metric-icon"><i data-lucide="tag"></i></span><div><small>Active tags</small><b><?= $stats['active'] ?></b><em><?= count($rows) ?> governed labels</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#e3eef8;color:#3d6a92"><i data-lucide="package-check"></i></span><div><small>Tagged products</small><b><?= $stats['tagged'] ?></b><em><?= $stats['assignments'] ?> product-tag assignments</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#fff0bf;color:#9b6c0a"><i data-lucide="megaphone"></i></span><div><small>Merchandising tags</small><b><?= $stats['merch'] ?></b><em>Campaign-ready labels</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:<?= $stats['unused'] ? '#fbe1dc;color:#b3483a' : '#e4f4e9;color:#267a55' ?>"><i data-lucide="<?= $stats['unused'] ? 'circle-alert' : 'circle-check' ?>"></i></span><div><small>Unused tags</small><b><?= $stats['unused'] ?></b><em><?= $stats['unused'] ? 'Assign or archive them' : 'Taxonomy is tidy' ?></em></div></div>
</div>

<?php if ($live): ?>
<div class="card" style="margin-bottom:18px">
  <div class="card-h" style="align-items:flex-start">
    <div><div class="eyebrow">Tag governance</div><h3>Keep discovery labels intentional</h3><p class="text-xs muted mt-1">Track usage so merchandising tags stay useful instead of becoming an unmaintained list.</p></div>
    <span class="text-xs muted"><?= $stats['assignments'] ?> assignments</span>
  </div>
  <div class="usegrid">
    <?php foreach (array_slice($live, 0, 8) as $r): ?>
    <div class="use-row">
      <div><b><?= e((string) $r['name']) ?></b><small><?= e(TagController::PURPOSES[$r['purpose']] ?? '') ?></small></div>
      <div class="bar"><i style="width:<?= max(3, round((int) $r['product_count'] / $maxUse * 100)) ?>%"></i></div>
      <span class="text-sm"><b><?= (int) $r['product_count'] ?></b></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card toolbar-card glass">
  <select class="btn" id="fPurpose"><option value="">All purposes</option><?php foreach (TagController::PURPOSES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select>
  <select class="btn" id="fStatus"><option value="">All statuses</option><option value="active">Active</option><option value="unused">Unused</option><option value="archived">Archived</option></select>
  <label class="checkline"><input type="checkbox" id="fArchived"> Archived tags</label>
  <label class="search-in"><i data-lucide="search"></i><input id="fSearch" placeholder="Search tags…"></label>
</div>

<div class="card" style="padding:0">
  <div class="card-h" style="padding:20px 20px 0;align-items:flex-start">
    <div><div class="eyebrow">Catalogue taxonomy</div><h3>Tag library</h3><p class="text-xs muted mt-1">A controlled vocabulary for campaigns, storefront search and internal yard workflows.</p></div>
    <span class="text-xs muted" id="tagCount"></span>
  </div>
  <div class="tablewrap" style="border:0;border-radius:0 0 24px 24px;margin-top:12px">
    <table class="data" id="tagTable">
      <thead><tr><th>Tag</th><th>Purpose</th><th>Products</th><th>Examples</th><th>Status</th><th data-noexport></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r):
            $n = (int) $r['product_count'];
            $state = $r['status'] === 'archived' ? 'archived' : ($n === 0 ? 'unused' : 'active');
            $ex = $examples[(int) $r['id']] ?? [];
            $payload = e((string) json_encode(['id' => (int) $r['id'], 'name' => $r['name'], 'purpose' => $r['purpose'], 'description' => $r['description'] ?? '',
                'status' => $r['status'], 'action' => route('admin.tags.update', ['id' => $r['id']])]));
        ?>
        <tr data-purpose="<?= e((string) $r['purpose']) ?>" data-status="<?= $state ?>" data-search="<?= e(strtolower($r['name'] . ' ' . $r['slug'] . ' ' . ($r['description'] ?? ''))) ?>">
          <td><span class="flex items-center gap-2"><span class="tagicon"><i data-lucide="tag"></i></span><span><b><?= e((string) $r['name']) ?></b><small class="block muted text-xs"><?= e((string) $r['slug']) ?></small></span></span></td>
          <td><span class="tierpill <?= $purposeCls[$r['purpose']] ?? 'tier-core' ?>"><?= e(TagController::PURPOSES[$r['purpose']] ?? 'Search & discovery') ?></span></td>
          <td><?= $n ?> product<?= $n === 1 ? '' : 's' ?></td>
          <td class="muted text-xs" style="max-width:260px"><?= $ex ? e(implode(', ', $ex)) : 'No products assigned' ?></td>
          <td><span class="status-dot <?= ['active' => 'ok', 'unused' => 'warn', 'archived' => 'off'][$state] ?>"><?= ucfirst($state) ?></span></td>
          <td data-noexport><span class="row-actions">
            <button type="button" class="iconbtn" title="Edit" data-edit-tag="<?= $payload ?>"><i data-lucide="pencil"></i></button>
            <button type="button" class="iconbtn danger" title="Delete" data-del="<?= e(route('admin.tags.delete', ['id' => $r['id']])) ?>" data-del-name="<?= e((string) $r['name']) ?>"><i data-lucide="trash-2"></i></button>
          </span></td>
        </tr>
        <?php endforeach; ?>
        <tr class="empty-row hidden"><td colspan="6" style="text-align:center;padding:36px" class="muted"><b>No tags found</b><br>Try changing your filters or search term.</td></tr>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-backdrop" id="tagModal">
  <form class="modal" method="post" action="<?= route('admin.tags.store') ?>" id="tagForm" data-store="<?= route('admin.tags.store') ?>">
    <?= csrf_field() ?>
    <div class="modal-h">
      <div><div class="eyebrow">Catalogue taxonomy</div><h3 id="tagTitle">Add tag</h3><p class="text-xs muted mt-1">A reusable label for products, storefront search and merchandising.</p></div>
      <button type="button" class="iconbtn" data-close><i data-lucide="x"></i></button>
    </div>
    <div class="stack">
      <div class="field"><label>Tag name *</label><input name="name" required maxlength="60" placeholder="e.g. Book-matched"></div>
      <div class="grid2">
        <div class="field"><label>Purpose</label><select name="purpose"><?php foreach (TagController::PURPOSES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Status</label><select name="status"><option value="active">Active</option><option value="archived">Archived</option></select></div>
      </div>
      <div class="field"><label>Description <span class="muted" style="font-weight:600">(optional)</span></label><textarea name="description" rows="2" placeholder="When should staff use this tag?"></textarea></div>
      <p class="hint">Search &amp; discovery tags appear in storefront filters; Merchandising drives badges and campaigns; Internal is only visible to staff.</p>
    </div>
    <div class="modal-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn gold" type="submit" id="tagSubmit"><i data-lucide="check" style="width:16px"></i>Create tag</button></div>
  </form>
</div>

<?php
$scripts = <<<'JS'
<script>
(function(){
  const modal=document.getElementById('tagModal'),form=document.getElementById('tagForm'),f=n=>form.elements[n];
  function open(d){
    form.reset();const edit=!!d.id;
    form.action=edit?d.action:form.dataset.store;
    document.getElementById('tagTitle').textContent=edit?'Edit tag':'Add tag';
    document.getElementById('tagSubmit').lastChild.textContent=edit?'Save changes':'Create tag';
    f('name').value=d.name||'';f('purpose').value=d.purpose||'discovery';f('status').value=d.status||'active';f('description').value=d.description||'';
    modal.classList.add('show');f('name').focus();
  }
  document.querySelectorAll('[data-open-tag]').forEach(b=>b.onclick=()=>open({}));
  document.querySelectorAll('[data-edit-tag]').forEach(b=>b.onclick=()=>open(JSON.parse(b.dataset.editTag)));
  modal.querySelectorAll('[data-close]').forEach(b=>b.onclick=()=>modal.classList.remove('show'));
  modal.addEventListener('click',e=>{if(e.target===modal)modal.classList.remove('show')});
  msBindDelete();
  msFilterTable('tagTable',{purpose:'fPurpose',status:'fStatus'},'fSearch','fArchived','tagCount','tag');
})();
</script>
JS;
include __DIR__ . '/../partials/footer.php';
?>
