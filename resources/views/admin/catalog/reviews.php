<?php
use App\Controllers\Admin\ReviewController;

$active = 'reviews';
$title = 'Reviews & ratings';
$rows = $rows ?? [];
$dist = $dist ?? [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
$products = $products ?? [];
$stats = $stats ?? ['avg' => 0, 'published' => 0, 'moderation' => 0, 'response' => 0];
$success = $success ?? null;
$error = $error ?? null;
$distMax = max(1, ...array_values($dist));
$stars = static fn(int $n): string => '<span class="stars">' . str_repeat('★', $n) . '<i>' . str_repeat('★', 5 - $n) . '</i></span>';
$statusCls = ['published' => 'ok', 'pending' => 'warn', 'flagged' => 'bad', 'archived' => 'off'];

include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs">Catalog <i data-lucide="chevron-right"></i> Reviews &amp; ratings</div><h1>Reviews &amp; ratings<span style="color:#dca71f">.</span></h1></div>
  <div class="flex gap-2">
    <button class="btn" type="button" onclick="msExportTable('reviewTable','reviews.csv')"><i data-lucide="download" style="width:16px"></i>Export</button>
    <button class="btn gold" type="button" data-open-review <?= $products ? '' : 'disabled title="Add a product first"' ?>><i data-lucide="plus" style="width:16px"></i>Add review</button>
  </div>
</div>
<?php include __DIR__ . '/../partials/catalog-tabs.php'; ?>
<?php if ($success): ?><div class="notice ok">✓ <?= e($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice bad"><?= e($error) ?></div><?php endif; ?>

<div class="statstrip">
  <div class="stat glass"><span class="metric-icon"><i data-lucide="star"></i></span><div><small>Average rating</small><b><?= $stats['published'] ? number_format($stats['avg'], 1) : '—' ?></b><em><?= $stats['published'] ?> published reviews</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#e4f4e9;color:#267a55"><i data-lucide="eye"></i></span><div><small>Published</small><b><?= $stats['published'] ?></b><em>Visible to shoppers</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#fbe1dc;color:#b3483a"><i data-lucide="shield-alert"></i></span><div><small>Needs moderation</small><b><?= $stats['moderation'] ?></b><em>Pending or flagged</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#e3eef8;color:#3d6a92"><i data-lucide="message-square-reply"></i></span><div><small>Response rate</small><b><?= $stats['response'] ?>%</b><em>Published reviews answered</em></div></div>
</div>

<div class="rev-top">
  <div class="card">
    <div class="eyebrow">Rating distribution</div>
    <h3 class="font-extrabold text-base mt-1">Customer sentiment at a glance</h3>
    <p class="text-xs muted mt-1">Published feedback only, so moderation work does not distort storefront sentiment.</p>
    <div class="dist">
      <?php foreach ($dist as $star => $n): ?>
      <div class="dist-row"><span><?= $star ?> <i style="color:#e2a91d;font-style:normal">★</i></span><div class="bar"><i style="width:<?= $n ? max(3, round($n / $distMax * 100)) : 0 ?>%"></i></div><b><?= $n ?></b></div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card">
    <div class="eyebrow">Moderation flow</div>
    <h3 class="font-extrabold text-base mt-1">Protect trust without hiding criticism</h3>
    <div class="flowsteps">
      <div><span class="metric-icon" style="background:#e4f4e9;color:#267a55"><i data-lucide="badge-check"></i></span><div><b>Publish genuine feedback</b><p>Verified purchases and showroom visits stay visible even when the rating is low.</p></div></div>
      <div><span class="metric-icon" style="background:#fbe1dc;color:#b3483a"><i data-lucide="flag"></i></span><div><b>Flag policy concerns</b><p>Use Flagged for abusive content, competitor spam or delivery disputes needing support.</p></div></div>
      <div><span class="metric-icon" style="background:#e3eef8;color:#3d6a92"><i data-lucide="message-circle"></i></span><div><b>Reply with context</b><p>Explain natural vein variation, polishing care or replacement steps — replies stay on the review.</p></div></div>
    </div>
  </div>
</div>

<div class="card toolbar-card glass">
  <select class="btn" id="fSource"><option value="">All sources</option><?php foreach (ReviewController::SOURCES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select>
  <select class="btn" id="fRating"><option value="">All ratings</option><?php for ($s = 5; $s >= 1; $s--): ?><option value="<?= $s ?>"><?= $s ?> star<?= $s > 1 ? 's' : '' ?></option><?php endfor; ?></select>
  <select class="btn" id="fStatus"><option value="">All statuses</option><?php foreach (ReviewController::STATUSES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select>
  <label class="checkline"><input type="checkbox" id="fArchived"> Archived reviews</label>
  <label class="search-in"><i data-lucide="search"></i><input id="fSearch" placeholder="Search customer, product, review…"></label>
</div>

<form class="card" style="padding:0" method="post" action="<?= route('admin.reviews.bulk') ?>" id="bulkForm">
  <?= csrf_field() ?>
  <div class="card-h" style="padding:20px 20px 0;align-items:flex-start;flex-wrap:wrap">
    <div><div class="eyebrow">Voice of customer</div><h3>Review moderation queue</h3><p class="text-xs muted mt-1">Keep ratings, policy review and merchant responses connected to each product.</p></div>
    <div class="bulkbar">
      <span class="text-xs muted" id="reviewCount"></span>
      <span class="text-xs" id="selCount">0 selected</span>
      <button class="btn" name="action" value="publish" disabled data-bulk><i data-lucide="check" style="width:15px"></i>Publish selected</button>
      <button class="btn" name="action" value="archive" disabled data-bulk><i data-lucide="archive" style="width:15px"></i>Archive selected</button>
      <button class="btn ghost" type="button" disabled data-bulk id="clearSel">Clear</button>
    </div>
  </div>
  <div class="tablewrap" style="border:0;border-radius:0 0 24px 24px;margin-top:12px">
    <table class="data" id="reviewTable">
      <thead><tr><th data-noexport style="width:36px"><input type="checkbox" id="selAll" aria-label="Select all"></th><th>Review / product</th><th>Customer</th><th>Rating</th><th>Source</th><th>Date</th><th>Status</th><th data-noexport></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r):
            $rating = max(1, min(5, (int) $r['rating']));
            $payload = e((string) json_encode([
                'title' => $r['title'] ?? '', 'body' => $r['body'] ?? '', 'rating' => $rating, 'status' => $r['status'],
                'customer' => $r['customer_name'], 'email' => $r['customer_email'] ?? '', 'city' => $r['city'] ?? '', 'application' => $r['application'] ?? '',
                'product' => ($r['product_name'] ?? 'Deleted product') . ($r['product_sku'] ? ' · ' . $r['product_sku'] : ''),
                'source' => ReviewController::SOURCES[$r['source']] ?? $r['source'], 'date' => date('M j, Y', strtotime((string) $r['created_at'])),
                'response' => $r['response'] ?? '', 'responded' => $r['responded_at'] ? date('M j, Y', strtotime((string) $r['responded_at'])) : '',
                'action' => route('admin.reviews.update', ['id' => $r['id']]), 'delete' => route('admin.reviews.delete', ['id' => $r['id']]),
            ]));
        ?>
        <tr data-source="<?= e((string) $r['source']) ?>" data-rating="<?= $rating ?>" data-status="<?= e((string) $r['status']) ?>"
            data-search="<?= e(strtolower($r['customer_name'] . ' ' . ($r['customer_email'] ?? '') . ' ' . ($r['product_name'] ?? '') . ' ' . ($r['product_sku'] ?? '') . ' ' . ($r['title'] ?? '') . ' ' . ($r['body'] ?? ''))) ?>">
          <td data-noexport><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" class="selrow" aria-label="Select review"></td>
          <td style="max-width:320px">
            <b>“<?= e((string) ($r['title'] ?: mb_strimwidth((string) $r['body'], 0, 50, '…'))) ?>”</b>
            <small class="block muted text-xs"><?= e((string) ($r['product_name'] ?? 'Deleted product')) ?><?= $r['product_sku'] ? ' · ' . e((string) $r['product_sku']) : '' ?><?= $r['application'] ? ' · ' . e((string) $r['application']) : '' ?></small>
            <?php if ($r['response']): ?><small class="replied"><i data-lucide="corner-down-right"></i>Replied</small><?php endif; ?>
          </td>
          <td><b><?= e((string) $r['customer_name']) ?></b><small class="block muted text-xs"><?= e((string) ($r['customer_email'] ?: ($r['city'] ?? ''))) ?></small></td>
          <td><?= $stars($rating) ?> <b class="text-sm"><?= number_format($rating, 1) ?></b></td>
          <td class="text-sm"><?= e(ReviewController::SOURCES[$r['source']] ?? (string) $r['source']) ?></td>
          <td class="text-sm muted" style="white-space:nowrap"><?= e(date('M j, Y', strtotime((string) $r['created_at']))) ?></td>
          <td><span class="status-dot <?= $statusCls[$r['status']] ?? 'off' ?>"><?= e(ReviewController::STATUSES[$r['status']] ?? (string) $r['status']) ?></span></td>
          <td data-noexport><span class="row-actions"><button type="button" class="iconbtn" title="Open review" data-open-detail="<?= $payload ?>"><i data-lucide="message-square-text"></i></button></span></td>
        </tr>
        <?php endforeach; ?>
        <tr class="empty-row hidden"><td colspan="8" style="text-align:center;padding:40px" class="muted">
          <?php if (!$rows): ?><b>No reviews yet</b><br>Reviews from the storefront and showroom will land here for moderation.<?php else: ?><b>No reviews found</b><br>Try changing your filters or search term.<?php endif; ?>
        </td></tr>
      </tbody>
    </table>
  </div>
</form>

<!-- Add review -->
<div class="modal-backdrop" id="addModal">
  <form class="modal" method="post" action="<?= route('admin.reviews.store') ?>">
    <?= csrf_field() ?>
    <div class="modal-h">
      <div><div class="eyebrow">Voice of customer</div><h3>Add review</h3><p class="text-xs muted mt-1">Log feedback collected in the showroom, on WhatsApp or after installation.</p></div>
      <button type="button" class="iconbtn" data-close><i data-lucide="x"></i></button>
    </div>
    <div class="stack">
      <div class="grid2">
        <div class="field"><label>Customer name *</label><input name="customer_name" required maxlength="120"></div>
        <div class="field"><label>Email</label><input name="customer_email" type="email" maxlength="160"></div>
      </div>
      <div class="field"><label>Product *</label>
        <select name="product_id" required><option value="">Choose a product…</option>
          <?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e((string) $p['name']) ?> · <?= e((string) $p['sku']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="grid3">
        <div class="field"><label>Rating</label><select name="rating"><?php for ($s = 5; $s >= 1; $s--): ?><option value="<?= $s ?>"><?= str_repeat('★', $s) ?> <?= $s ?></option><?php endfor; ?></select></div>
        <div class="field"><label>Installed as</label><select name="application"><option value="">—</option><?php foreach (ReviewController::APPLICATIONS as $a): ?><option><?= e($a) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>City</label><input name="city" maxlength="64" placeholder="e.g. Bankura"></div>
      </div>
      <div class="field"><label>Review title</label><input name="title" maxlength="160" placeholder="e.g. Flawless polish on our living room floor"></div>
      <div class="field"><label>Review details</label><textarea name="body" rows="3"></textarea></div>
      <div class="grid2">
        <div class="field"><label>Source</label><select name="source"><?php foreach (ReviewController::SOURCES as $k => $l): ?><option value="<?= $k ?>"<?= $k === 'showroom' ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Status</label><select name="status"><?php foreach (ReviewController::STATUSES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
      </div>
    </div>
    <div class="modal-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn gold" type="submit"><i data-lucide="check" style="width:16px"></i>Save review</button></div>
  </form>
</div>

<!-- Review details / moderation -->
<div class="modal-backdrop" id="detailModal">
  <form class="modal" method="post" id="detailForm">
    <?= csrf_field() ?>
    <div class="modal-h">
      <div><div class="eyebrow">Review details</div><h3 id="dTitle">Review</h3><p class="text-xs muted mt-1" id="dProduct"></p></div>
      <button type="button" class="iconbtn" data-close><i data-lucide="x"></i></button>
    </div>
    <div class="revbox">
      <div class="flex items-center justify-between gap-2"><span id="dStars" class="stars" style="font-size:17px"></span><span id="dStatus" class="status-dot"></span></div>
      <p id="dBody" class="text-sm" style="margin:12px 0;white-space:pre-line"></p>
      <div class="text-xs muted" id="dMeta"></div>
    </div>
    <div class="field" style="margin-top:16px"><label>Merchant response <span class="muted" style="font-weight:600" id="dResponded"></span></label><textarea name="response" rows="4" placeholder="Thank the customer, explain natural variation in the stone, or share next steps…"></textarea></div>
    <input type="hidden" name="status" id="dStatusInput">
    <div class="modal-f" style="justify-content:space-between;flex-wrap:wrap">
      <button type="button" class="btn ghost" id="dDelete" style="color:#b4452f"><i data-lucide="trash-2" style="width:15px"></i>Delete</button>
      <span class="flex gap-2 flex-wrap">
        <button type="submit" class="btn" data-set-status="flagged"><i data-lucide="flag" style="width:15px"></i>Flag for review</button>
        <button type="submit" class="btn" data-set-status="published"><i data-lucide="check" style="width:15px"></i>Publish</button>
        <button type="submit" class="btn gold"><i data-lucide="send" style="width:15px"></i>Save response</button>
      </span>
    </div>
  </form>
</div>

<?php
$scripts = <<<'JS'
<script>
(function(){
  const statusCls={published:'ok',pending:'warn',flagged:'bad',archived:'off'};
  const addM=document.getElementById('addModal'),detM=document.getElementById('detailModal'),df=document.getElementById('detailForm');
  [addM,detM].forEach(m=>{m.querySelectorAll('[data-close]').forEach(b=>b.onclick=()=>m.classList.remove('show'));m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('show')});});
  document.querySelectorAll('[data-open-review]').forEach(b=>b.onclick=()=>addM.classList.add('show'));
  document.querySelectorAll('[data-open-detail]').forEach(b=>b.onclick=()=>{
    const d=JSON.parse(b.dataset.openDetail);
    df.action=d.action;
    document.getElementById('dTitle').textContent=d.title?'“'+d.title+'”':'Review from '+d.customer;
    document.getElementById('dProduct').textContent=d.product;
    document.getElementById('dStars').innerHTML='★'.repeat(d.rating)+'<i>'+'★'.repeat(5-d.rating)+'</i>';
    const st=document.getElementById('dStatus');st.className='status-dot '+(statusCls[d.status]||'off');st.textContent=d.status.charAt(0).toUpperCase()+d.status.slice(1);
    document.getElementById('dBody').textContent=d.body||'No written review.';
    document.getElementById('dMeta').textContent=[d.customer,d.email,d.city,d.application&&('Installed as '+d.application),d.source,d.date].filter(Boolean).join(' · ');
    document.getElementById('dResponded').textContent=d.responded?'· replied '+d.responded:'';
    df.elements.response.value=d.response;
    document.getElementById('dStatusInput').value=d.status;
    document.getElementById('dDelete').onclick=()=>{if(!confirm('Delete this review? This cannot be undone.'))return;const f=document.getElementById('msDelForm');f.action=d.delete;f.submit();};
    detM.classList.add('show');
  });
  df.querySelectorAll('[data-set-status]').forEach(b=>b.addEventListener('click',()=>document.getElementById('dStatusInput').value=b.dataset.setStatus));

  // Bulk selection
  const sels=[...document.querySelectorAll('.selrow')],all=document.getElementById('selAll');
  function sync(){
    const n=sels.filter(c=>c.checked&&!c.closest('tr').classList.contains('hidden')).length;
    document.getElementById('selCount').textContent=n+' selected';
    document.querySelectorAll('[data-bulk]').forEach(b=>b.disabled=!n);
  }
  sels.forEach(c=>c.addEventListener('change',sync));
  all.addEventListener('change',()=>{sels.forEach(c=>{if(!c.closest('tr').classList.contains('hidden'))c.checked=all.checked});sync();});
  document.getElementById('clearSel').onclick=()=>{sels.forEach(c=>c.checked=false);all.checked=false;sync();};
  document.getElementById('bulkForm').addEventListener('submit',()=>sels.forEach(c=>{if(c.closest('tr').classList.contains('hidden'))c.checked=false}));
  msFilterTable('reviewTable',{source:'fSource',rating:'fRating',status:'fStatus'},'fSearch','fArchived','reviewCount','review');
  ['fSource','fRating','fStatus','fSearch','fArchived'].forEach(id=>document.getElementById(id).addEventListener('input',sync));
})();
</script>
JS;
include __DIR__ . '/../partials/footer.php';
?>
