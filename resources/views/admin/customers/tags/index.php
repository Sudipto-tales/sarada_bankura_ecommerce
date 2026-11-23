<?php
use App\Services\Customer\CustomerService as CS;

$active = 'customers.tags';
$title = 'Customer tags';

include __DIR__ . '/../../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs"><a href="<?= route('admin.customers') ?>">Customers</a> <i data-lucide="chevron-right"></i> Tags</div><h1>Customer tags<span style="color:#dca71f">.</span></h1></div>
  <button class="btn gold" type="button" data-open-tag><i data-lucide="plus" style="width:16px"></i>Add tag</button>
</div>

<div class="statstrip">
  <div class="stat glass"><span class="metric-icon"><i data-lucide="tags"></i></span><div><small>Tags</small><b><?= $stats['tags'] ?></b><em>Customer vocabulary</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#e4f4e9;color:#267a55"><i data-lucide="user-check"></i></span><div><small>Tagged customers</small><b><?= $stats['tagged'] ?></b><em>of <?= $stats['customers'] ?> customers</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#e3eef8;color:#3d6a92"><i data-lucide="user-x"></i></span><div><small>Untagged</small><b><?= max(0, $stats['customers'] - $stats['tagged']) ?></b><em>No label yet</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#f1e8d6;color:#6d5e43"><i data-lucide="tag"></i></span><div><small>Unused tags</small><b><?= $stats['unused'] ?></b><em>On no customer</em></div></div>
</div>

<div class="notice" style="margin-bottom:18px"><b>Separate from product tags.</b> These labels describe <em>people</em> — VIP, Builder, Dealer… Use them in segment rules (“Customer tag has VIP”) and to filter the customer list.</div>

<?php if ($rows): ?>
<div class="cu-taggrid">
  <?php foreach ($rows as $t):
      $tone = CS::TAG_COLORS[$t['color']] ?? CS::TAG_COLORS['gold'];
      $payload = e((string) json_encode(['id' => (int) $t['id'], 'name' => $t['name'], 'color' => $t['color'], 'description' => $t['description'] ?? '', 'action' => route('admin.customers.tags.update', ['id' => $t['id']])]));
  ?>
  <article class="card cu-tagcard">
    <div class="cu-tagcard-h">
      <span class="cu-tag lg" style="background:<?= $tone ?>"><?= e((string) $t['name']) ?></span>
      <span class="row-actions">
        <button type="button" class="iconbtn" title="Edit" data-edit-tag="<?= $payload ?>"><i data-lucide="pencil"></i></button>
        <button type="button" class="iconbtn danger" title="Delete" data-del="<?= e(route('admin.customers.tags.delete', ['id' => $t['id']])) ?>" data-del-name="<?= e((string) $t['name']) ?>"><i data-lucide="trash-2"></i></button>
      </span>
    </div>
    <p class="text-xs muted"><?= e((string) ($t['description'] ?: 'No description')) ?></p>
    <div class="cu-tagcard-f">
      <a href="<?= route('admin.customers') ?>?tag=<?= (int) $t['id'] ?>"><b><?= (int) $t['customer_count'] ?></b> customer<?= (int) $t['customer_count'] === 1 ? '' : 's' ?> <i data-lucide="arrow-right" style="width:13px;vertical-align:-2px"></i></a>
      <?php if (!empty($examples[(int) $t['id']])): ?><small class="muted"><?= e(implode(', ', $examples[(int) $t['id']])) ?><?= (int) $t['customer_count'] > 3 ? '…' : '' ?></small><?php endif; ?>
    </div>
  </article>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card cu-empty"><span class="metric-icon"><i data-lucide="tags"></i></span><b>No customer tags yet</b><small>Create labels like VIP or Builder, or run the CustomerSeeder for the defaults.</small><button class="btn gold" type="button" data-open-tag>Add a tag</button></div>
<?php endif; ?>

<div class="modal-backdrop" id="tagModal">
  <form class="modal" method="post" action="<?= route('admin.customers.tags.store') ?>" id="tagForm" data-store="<?= route('admin.customers.tags.store') ?>">
    <?= csrf_field() ?>
    <div class="modal-h">
      <div><div class="eyebrow">Customer tags</div><h3 id="tagTitle">Add a tag</h3></div>
      <button type="button" class="iconbtn" data-close aria-label="Close"><i data-lucide="x"></i></button>
    </div>
    <div class="stack">
      <div class="field"><label for="tagName">Name *</label><input id="tagName" name="name" required maxlength="60" placeholder="e.g. Repeat buyer"></div>
      <div class="field"><label>Colour</label>
        <div class="cu-swatches"><?php foreach (CS::TAG_COLORS as $k => $tone): ?>
          <label><input type="radio" name="color" value="<?= $k ?>"<?= $k === 'gold' ? ' checked' : '' ?>><span style="background:<?= $tone ?>"><?= ucfirst($k) ?></span></label>
        <?php endforeach; ?></div>
      </div>
      <div class="field"><label for="tagDesc">Description</label><input id="tagDesc" name="description" maxlength="255" placeholder="When staff should use this tag"></div>
    </div>
    <div class="modal-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn gold" type="submit" id="tagSubmit"><i data-lucide="check" style="width:16px"></i>Create tag</button></div>
  </form>
</div>
<?php
$scripts = <<<'JS'
<script>
(function(){
  var modal=document.getElementById('tagModal'),form=document.getElementById('tagForm');
  function open(d){
    form.reset();var edit=!!d.id;form.action=edit?d.action:form.dataset.store;
    document.getElementById('tagTitle').textContent=edit?'Edit tag':'Add a tag';
    document.getElementById('tagSubmit').lastChild.textContent=edit?'Save changes':'Create tag';
    form.elements.name.value=d.name||'';form.elements.description.value=d.description||'';
    var c=form.querySelector('[name=color][value="'+(d.color||'gold')+'"]');if(c)c.checked=true;
    modal.classList.add('show');form.elements.name.focus();
  }
  document.querySelectorAll('[data-open-tag]').forEach(function(b){b.onclick=function(){open({})}});
  document.querySelectorAll('[data-edit-tag]').forEach(function(b){b.onclick=function(){open(JSON.parse(b.dataset.editTag))}});
  modal.querySelectorAll('[data-close]').forEach(function(b){b.onclick=function(){modal.classList.remove('show')}});
  modal.addEventListener('click',function(e){if(e.target===modal)modal.classList.remove('show')});
  document.addEventListener('keydown',function(e){if(e.key==='Escape')modal.classList.remove('show')});
  msBindDelete();
})();
</script>
JS;
include __DIR__ . '/../../partials/footer.php';
