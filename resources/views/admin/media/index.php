<?php
$active = 'media';
$title = 'Media library';
$rows = $rows ?? [];
$filters = $filters ?? ['q' => '', 'kind' => '', 'owner' => '', 'disk' => ''];
$stats = $stats ?? ['files' => 0, 'bytes' => 0, 'images' => 0, 'videos' => 0, 'documents' => 0, 'unused' => 0];
$page = $page ?? 1;
$pages = $pages ?? 1;
$total = $total ?? 0;
$human = [\App\Services\Media\MediaService::class, 'humanBytes'];
$filtered = implode('', $filters) !== '';
$pageUrl = static fn(int $p): string => route('admin.media') . '?' . http_build_query(array_filter($filters + ['page' => $p > 1 ? $p : null], static fn($v) => $v !== '' && $v !== null));
$owners = ['' => 'Any usage', 'unused' => 'Unused only', 'product' => 'Products', 'variant' => 'Variants', 'brand' => 'Brands', 'category' => 'Categories'];
include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs">Workspace <i data-lucide="chevron-right"></i> Media &amp; files</div><h1>Media library<span style="color:#dca71f">.</span></h1></div>
  <button class="btn gold" type="button" onclick="document.querySelector('#libUploader [data-dropzone]').click()"><i data-lucide="upload" style="width:16px"></i>Upload files</button>
</div>

<div class="statstrip">
  <div class="stat glass"><span class="metric-icon"><i data-lucide="images"></i></span><div><small>All files</small><b><?= number_format($stats['files']) ?></b><em><?= e($human((int) $stats['bytes'])) ?> stored</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#e4f4e9;color:#267a55"><i data-lucide="image"></i></span><div><small>Images</small><b><?= number_format($stats['images']) ?></b><em>With thumbnails</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#e3eef8;color:#3d6a92"><i data-lucide="film"></i></span><div><small>Videos &amp; documents</small><b><?= number_format($stats['videos'] + $stats['documents']) ?></b><em><?= (int) $stats['videos'] ?> video · <?= (int) $stats['documents'] ?> document</em></div></div>
  <a class="stat glass" href="<?= route('admin.media') ?>?owner=unused" style="color:inherit;text-decoration:none"><span class="metric-icon" style="background:#ffe6c4;color:#a2632a"><i data-lucide="unlink"></i></span><div><small>Unused</small><b><?= number_format($stats['unused']) ?></b><em>Not on any product or brand</em></div></a>
</div>

<div class="card" style="margin-bottom:18px">
  <?php $up = ['id' => 'libUploader', 'kinds' => ['image', 'video'], 'title' => 'Drop images or videos here, or click to choose', 'compact' => true];
  include __DIR__ . '/../partials/uploader.php'; ?>
</div>

<form method="get" action="<?= route('admin.media') ?>" class="card toolbar-card">
  <input class="btn" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Search MED-000123, file name or alt text" style="flex:1;min-width:220px;font-weight:500" aria-label="Search">
  <select name="kind" class="btn" aria-label="Kind"><option value="">All kinds</option><?php foreach (['image' => 'Images', 'video' => 'Videos', 'document' => 'Documents'] as $k => $l): ?><option value="<?= $k ?>"<?= $filters['kind'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  <select name="owner" class="btn" aria-label="Usage"><?php foreach ($owners as $k => $l): ?><option value="<?= $k ?>"<?= $filters['owner'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  <button type="submit" class="btn gold"><i data-lucide="filter" style="width:15px"></i>Filter</button>
  <?php if ($filtered): ?><a class="btn ghost" href="<?= route('admin.media') ?>">Clear</a><?php endif; ?>
</form>

<form method="post" action="<?= route('admin.media.bulkDelete') ?>" class="card" id="mlForm">
  <?= csrf_field() ?>
  <div class="card-h" style="flex-wrap:wrap">
    <div><div class="eyebrow">Files</div><h3><?= number_format($total) ?> file<?= $total === 1 ? '' : 's' ?><?= $filtered ? ' match' : '' ?></h3></div>
    <div class="row-actions hidden" id="mlBulk" style="gap:8px;align-items:center">
      <span class="text-xs muted" id="mlSel"></span>
      <button class="btn" type="submit" onclick="return confirm('Delete the selected unused files? This cannot be undone.')"><i data-lucide="trash-2" style="width:15px;color:#b4452f"></i>Delete selected</button>
    </div>
  </div>
  <?php if (!$rows): ?>
    <div class="ml-empty"><span class="up-cloud"><i data-lucide="images"></i></span><b><?= $filtered ? 'Nothing matches these filters' : 'No files yet' ?></b><?= $filtered ? 'Try another search or kind.' : 'Upload product photos above; they can then be reused on any product.' ?></div>
  <?php else: ?>
  <p class="text-xs muted" style="margin:-6px 0 14px">Only unused files can be selected for deletion. Files in use show where they appear in their details.</p>
  <div class="ml-grid" id="mlGrid">
    <?php foreach ($rows as $m): $used = (int) $m['used_count']; ?>
    <div class="ml-card" tabindex="0" role="button" data-id="<?= (int) $m['id'] ?>" aria-label="<?= e($m['media_no'] . ' ' . $m['original_name']) ?>">
      <?php if (!$used): ?><input type="checkbox" class="ml-check" name="ids[]" value="<?= (int) $m['id'] ?>" aria-label="Select <?= e($m['media_no']) ?>"><?php endif; ?>
      <span class="ml-kind"><?= e($m['kind'] === 'image' ? strtoupper((string) $m['extension']) : $m['kind']) ?></span>
      <div class="ml-prev"><?php if ($m['thumb_url']): ?><img src="<?= e($m['thumb_url']) ?>" alt="<?= e((string) $m['alt_text']) ?>" loading="lazy"><?php else: ?><i data-lucide="<?= $m['kind'] === 'video' ? 'film' : 'file-text' ?>"></i><?php endif; ?></div>
      <div class="ml-body">
        <div class="ml-no"><?= e($m['media_no']) ?></div>
        <div class="ml-name" title="<?= e((string) $m['original_name']) ?>"><?= e((string) ($m['title'] ?: $m['original_name'])) ?></div>
        <div class="ml-meta"><span><?= e($m['size_label']) ?></span><?php if ($m['width']): ?><span>· <?= (int) $m['width'] ?>×<?= (int) $m['height'] ?></span><?php endif; ?>
          <?php if ($used): ?><span class="pill" style="margin-left:auto">Used <?= $used ?>×</span><?php else: ?><span class="pill pill-off" style="margin-left:auto">Unused</span><?php endif; ?>
          <?php if ($m['disk'] === 'private'): ?><span class="pill pill-gold">Private</span><?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($pages > 1): ?>
  <div class="pager">
    <?php if ($page > 1): ?><a class="btn" href="<?= e($pageUrl($page - 1)) ?>"><i data-lucide="chevron-left" style="width:15px"></i>Newer</a><?php endif; ?>
    <span class="text-xs muted">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn" href="<?= e($pageUrl($page + 1)) ?>">Older<i data-lucide="chevron-right" style="width:15px"></i></a><?php endif; ?>
  </div>
  <?php endif; ?>
</form>

<div class="modal-backdrop" id="mdModal">
  <div class="modal wide" role="dialog" aria-modal="true" aria-labelledby="mdTitle">
    <div class="modal-h"><div><div class="eyebrow" id="mdNo">Media</div><h3 id="mdTitle">File details</h3></div><button type="button" class="iconbtn" data-close aria-label="Close"><i data-lucide="x"></i></button></div>
    <div class="grid2" style="align-items:start">
      <div>
        <div class="md-prev" id="mdPrev"></div>
        <dl class="md-facts" id="mdFacts"></dl>
      </div>
      <div class="stack">
        <form id="mdForm" class="stack" style="gap:12px">
          <div class="field"><label for="mdAlt">Alt text</label><input id="mdAlt" name="alt_text" maxlength="255" placeholder="e.g. Statuario white marble slab with grey veins"><div class="hint">Describes the image for search engines and screen readers.</div></div>
          <div class="field"><label for="mdTitleIn">Title</label><input id="mdTitleIn" name="title" maxlength="255"></div>
          <div><button class="btn gold" type="submit"><i data-lucide="check" style="width:15px"></i>Save details</button></div>
        </form>
        <div><div class="eyebrow" style="margin-bottom:8px">Used in</div><div class="md-use" id="mdUse"></div></div>
      </div>
    </div>
    <div class="modal-f">
      <button type="button" class="btn" id="mdCopy"><i data-lucide="link" style="width:15px"></i>Copy link</button>
      <a class="btn" id="mdOpen" target="_blank" rel="noopener"><i data-lucide="external-link" style="width:15px"></i>Open</a>
      <button type="button" class="btn" id="mdDelete"><i data-lucide="trash-2" style="width:15px;color:#b4452f"></i>Delete</button>
    </div>
  </div>
</div>
<?php
$scripts = ($scripts ?? '') . <<<'JS'
<script>
(function(){
  var M=window.msMedia, modal=document.getElementById('mdModal'), cur=null;
  var form=document.getElementById('mlForm'), bulk=document.getElementById('mlBulk'), sel=document.getElementById('mlSel');
  function syncBulk(){var n=form.querySelectorAll('.ml-check:checked').length;bulk.classList.toggle('hidden',!n);sel.textContent=n+' selected';
    form.querySelectorAll('.ml-card').forEach(function(c){var cb=c.querySelector('.ml-check');c.classList.toggle('sel',!!(cb&&cb.checked));});}
  form.addEventListener('change',function(e){if(e.target.classList.contains('ml-check'))syncBulk();});
  document.getElementById('libUploader').addEventListener('ms:idle',function(){setTimeout(function(){location.reload();},900);});

  function open(id){
    fetch(M.url('/admin/media/'+id),{headers:{Accept:'application/json'},credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
      if(!j.ok){msToast.error(j.error||'Not found');return;}
      var m=cur=j.media;
      document.getElementById('mdNo').textContent=m.media_no;
      document.getElementById('mdTitle').textContent=m.title||m.name;
      var prev=document.getElementById('mdPrev');
      prev.innerHTML=m.kind==='image'?'<img src="'+M.esc(m.url)+'" alt="'+M.esc(m.alt_text||'')+'">':(m.kind==='video'?'<video src="'+M.esc(m.url)+'" controls preload="metadata"></video>':'<i data-lucide="file-text"></i>');
      var f=[['File',m.name],['Kind',m.kind+(m.disk==='private'?' · private':'')],['Size',m.size+(m.width?' · '+m.width+'×'+m.height+' px':'')],['Type',m.mime||'—'],['Uploaded',m.created_at||'—'],['Checksum',(m.checksum||'').slice(0,12)]];
      document.getElementById('mdFacts').innerHTML=f.map(function(r){return '<dt>'+r[0]+'</dt><dd>'+M.esc(r[1])+'</dd>';}).join('');
      document.getElementById('mdAlt').value=m.alt_text||'';
      document.getElementById('mdTitleIn').value=m.title||'';
      var use=document.getElementById('mdUse');
      use.innerHTML=j.usage.length?j.usage.map(function(u){var inner='<i data-lucide="'+(u.owner_type==='brand'?'award':'package')+'" style="width:15px"></i>'+M.esc(u.label)+'<em>'+M.esc(u.role)+'</em>';
        return u.url?'<a href="'+M.esc(u.url)+'">'+inner+'</a>':'<span>'+inner+'</span>';}).join(''):'<span class="muted">Not used anywhere yet. Safe to delete.</span>';
      var del=document.getElementById('mdDelete');del.disabled=!!j.usage.length;del.title=j.usage.length?'Remove it where it is used first':'';
      document.getElementById('mdOpen').href=m.url;
      modal.classList.add('show');
      if(window.lucide)lucide.createIcons();
    });
  }
  document.querySelectorAll('.ml-card').forEach(function(c){
    c.addEventListener('click',function(e){if(e.target.classList.contains('ml-check'))return;open(c.dataset.id);});
    c.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();open(c.dataset.id);}});
  });
  modal.addEventListener('click',function(e){if(e.target===modal||e.target.closest('[data-close]'))modal.classList.remove('show');});
  document.addEventListener('keydown',function(e){if(e.key==='Escape')modal.classList.remove('show');});
  document.getElementById('mdForm').addEventListener('submit',function(e){e.preventDefault();if(!cur)return;
    M.post(M.url('/admin/media/'+cur.id+'/update'),{alt_text:document.getElementById('mdAlt').value,title:document.getElementById('mdTitleIn').value})
      .then(function(j){j.ok?msToast.success(j.message):msToast.error(j.error);});});
  document.getElementById('mdCopy').onclick=function(){if(!cur)return;var u=new URL(cur.url,location.href).href;
    (navigator.clipboard?navigator.clipboard.writeText(u):Promise.reject()).then(function(){msToast.success('Link copied');},function(){prompt('Copy this link',u);});};
  document.getElementById('mdDelete').onclick=function(){if(!cur||!confirm('Delete '+cur.media_no+'? This cannot be undone.'))return;
    M.post(M.url('/admin/media/'+cur.id+'/delete'),{}).then(function(j){if(j.ok){location.reload();}else msToast.error(j.error);});};
})();
</script>
JS;
include __DIR__ . '/../partials/footer.php';
