<?php
$active = 'categories';
$title = 'Categories';
$top = $top ?? [];
$children = $children ?? [];
$stats = $stats ?? ['total' => 0, 'top' => 0, 'subs' => 0, 'featured' => 0, 'visible' => 0];
$success = $success ?? null;
$error = $error ?? null;
$tints = ['tint-green', 'tint-blue', 'tint-pink', 'tint-orange', 'tint-yellow'];
$icons = ['layers', 'square-stack', 'chef-hat', 'gem', 'mountain', 'grid-2x2', 'sparkles'];

$payload = static fn(array $c): string => e((string) json_encode([
    'id' => (int) $c['id'],
    'name' => $c['name'],
    'parent_id' => $c['parent_id'] ? (int) $c['parent_id'] : '',
    'description' => $c['description'] ?? '',
    'is_visible' => (int) ($c['is_visible'] ?? 1),
    'is_featured' => (int) ($c['is_featured'] ?? 0),
    'sort' => (int) ($c['sort'] ?? 0),
    'action' => route('admin.categories.update', ['id' => $c['id']]),
]));
$visPill = static fn($c): string => (int) $c['is_visible'] === 1
    ? '<span class="pill">• Visible</span>'
    : '<span class="pill pill-off">• Hidden</span>';

include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs">Catalog <i data-lucide="chevron-right"></i> Categories</div><h1>Categories<span style="color:#dca71f">.</span></h1></div>
  <button class="btn gold" type="button" data-open-cat><i data-lucide="plus" style="width:16px"></i>Add category</button>
</div>
<?php if ($success): ?><div class="notice ok">✓ <?= e($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice bad"><?= e($error) ?></div><?php endif; ?>

<div class="statstrip">
  <div class="stat glass"><span class="metric-icon"><i data-lucide="tag"></i></span><div><small>Total categories</small><b><?= $stats['total'] ?></b><em>Catalogue taxonomy</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#e3eef8;color:#3d6a92"><i data-lucide="folder-tree"></i></span><div><small>Top-level</small><b><?= $stats['top'] ?></b><em>Main browse groups</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#efe7f6;color:#6f4a92"><i data-lucide="git-branch"></i></span><div><small>Sub-categories</small><b><?= $stats['subs'] ?></b><em>Discovery paths</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#fff0bf;color:#9b6c0a"><i data-lucide="star"></i></span><div><small>Featured</small><b><?= $stats['featured'] ?></b><em><?= $stats['visible'] ?> visible now</em></div></div>
</div>

<div class="card">
  <div class="card-h" style="align-items:flex-start">
    <div><div class="eyebrow">Taxonomy</div><h3>All categories</h3><p class="text-xs muted mt-1">Top-level groups give shoppers a clear starting point. Open one to see and add its sub-categories.</p></div>
    <span class="text-xs muted"><?= $stats['top'] ?> top-level · <?= $stats['subs'] ?> sub-categories</span>
  </div>

  <div class="cat-head"><span>Category</span><span class="hide-sm">Products</span><span class="hide-sm">Featured</span><span class="hide-md">Order</span><span></span></div>
  <?php if (!$top): ?><p class="muted text-sm" style="padding:30px;text-align:center">No categories yet — add your first one.</p><?php endif; ?>
  <?php foreach ($top as $i => $c): $cid = (int) $c['id']; $subs = $children[$cid] ?? []; ?>
  <div class="cat-block">
    <div class="category-row <?= $tints[$i % count($tints)] ?>">
      <button type="button" class="cat-main" data-toggle-subs="<?= $cid ?>">
        <span class="iconbox"><i data-lucide="<?= $icons[$i % count($icons)] ?>"></i></span>
        <span class="min-w-0"><b><?= e((string) $c['name']) ?></b><small><?= count($subs) ?> sub-categor<?= count($subs) === 1 ? 'y' : 'ies' ?> · <?= $visPill($c) ?></small></span>
      </button>
      <span class="hide-sm text-sm"><?= (int) $c['product_count'] ?></span>
      <span class="hide-sm"><?= (int) $c['is_featured'] ? '<span class="pill pill-gold">★ Featured</span>' : '<span class="muted text-xs">—</span>' ?></span>
      <span class="hide-md muted text-sm"><?= (int) $c['sort'] ?></span>
      <span class="row-actions">
        <button type="button" class="iconbtn" title="Show sub-categories" data-toggle-subs="<?= $cid ?>"><i data-lucide="chevron-down"></i></button>
        <button type="button" class="iconbtn" title="Edit" data-edit-cat="<?= $payload($c) ?>"><i data-lucide="pencil"></i></button>
        <button type="button" class="iconbtn danger" title="Delete" data-del="<?= e(route('admin.categories.delete', ['id' => $cid])) ?>" data-del-name="<?= e((string) $c['name']) ?>"><i data-lucide="trash-2"></i></button>
      </span>
    </div>
    <div class="subrows hidden" id="subs-<?= $cid ?>">
      <?php foreach ($subs as $s): ?>
      <div class="subrow">
        <span class="muted">↳</span><b><?= e((string) $s['name']) ?></b><?= $visPill($s) ?><?= (int) $s['is_featured'] ? '<span class="pill pill-gold">★</span>' : '' ?>
        <span class="muted text-xs" style="margin-left:auto"><?= (int) $s['product_count'] ?> product<?= (int) $s['product_count'] === 1 ? '' : 's' ?></span>
        <button type="button" class="iconbtn" title="Edit" data-edit-cat="<?= $payload($s) ?>"><i data-lucide="pencil"></i></button>
        <button type="button" class="iconbtn danger" title="Delete" data-del="<?= e(route('admin.categories.delete', ['id' => $s['id']])) ?>" data-del-name="<?= e((string) $s['name']) ?>"><i data-lucide="trash-2"></i></button>
      </div>
      <?php endforeach; ?>
      <div class="subrow"><button type="button" class="linkbtn" data-open-cat data-parent="<?= $cid ?>"><i data-lucide="plus"></i>Add sub-category to <?= e((string) $c['name']) ?></button></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="modal-backdrop" id="catModal">
  <form class="modal" method="post" action="<?= route('admin.categories.store') ?>" id="catForm" data-store="<?= route('admin.categories.store') ?>">
    <?= csrf_field() ?>
    <div class="modal-h">
      <div><div class="eyebrow">Catalog setup</div><h3 id="catTitle">Add category</h3><p class="text-xs muted mt-1">Create a category shoppers can browse. Pick a parent to make it a sub-category.</p></div>
      <button type="button" class="iconbtn" data-close><i data-lucide="x"></i></button>
    </div>
    <div class="stack">
      <div class="field"><label>Category name *</label><input name="name" required placeholder="e.g. Countertops"></div>
      <div class="field"><label>Parent category</label>
        <select name="parent_id"><option value="">None — top-level category</option>
          <?php foreach ($top as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e((string) $c['name']) ?></option><?php endforeach; ?>
        </select>
        <div class="hint">Categories that already have sub-categories always stay top-level.</div>
      </div>
      <div class="field"><label>Description</label><textarea name="description" rows="3" placeholder="Briefly describe this category…"></textarea></div>
      <div class="grid3">
        <div class="field"><label>Visibility</label><select name="is_visible"><option value="1">Visible</option><option value="0">Hidden</option></select></div>
        <div class="field"><label>Featured</label><select name="is_featured"><option value="0">No</option><option value="1">Yes</option></select></div>
        <div class="field"><label>Sort order</label><input name="sort" type="number" value="0"></div>
      </div>
    </div>
    <div class="modal-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn gold" type="submit" id="catSubmit"><i data-lucide="plus" style="width:16px"></i>Create category</button></div>
  </form>
</div>

<?php
$scripts = <<<'JS'
<script>
(function(){
  const modal=document.getElementById('catModal'),form=document.getElementById('catForm');
  const f=n=>form.elements[n];
  function open(data){
    form.reset();
    const edit=!!data.id;
    form.action=edit?data.action:form.dataset.store;
    document.getElementById('catTitle').textContent=edit?'Edit category':(data.parent_id?'Add sub-category':'Add category');
    document.getElementById('catSubmit').lastChild.textContent=edit?'Save changes':'Create category';
    f('name').value=data.name||'';f('parent_id').value=data.parent_id||'';f('description').value=data.description||'';
    f('is_visible').value=String(data.is_visible??1);f('is_featured').value=String(data.is_featured??0);f('sort').value=data.sort??0;
    // A category cannot be its own parent.
    [...f('parent_id').options].forEach(o=>o.disabled=edit&&o.value===String(data.id));
    modal.classList.add('show');f('name').focus();
  }
  document.querySelectorAll('[data-open-cat]').forEach(b=>b.onclick=()=>open({parent_id:b.dataset.parent||''}));
  document.querySelectorAll('[data-edit-cat]').forEach(b=>b.onclick=()=>open(JSON.parse(b.dataset.editCat)));
  modal.querySelectorAll('[data-close]').forEach(b=>b.onclick=()=>modal.classList.remove('show'));
  modal.addEventListener('click',e=>{if(e.target===modal)modal.classList.remove('show')});
  document.querySelectorAll('[data-toggle-subs]').forEach(b=>b.onclick=()=>{
    const el=document.getElementById('subs-'+b.dataset.toggleSubs);el.classList.toggle('hidden');
    b.closest('.cat-block').classList.toggle('open',!el.classList.contains('hidden'));
  });
  msBindDelete();
})();
</script>
JS;
include __DIR__ . '/../partials/footer.php';
?>
