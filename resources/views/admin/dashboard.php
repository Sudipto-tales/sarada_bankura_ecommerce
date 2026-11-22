<?php
$active = 'overview';
$title = 'Overview';
$stats = $stats ?? [];
$recent = $recent ?? [];
$byStatus = $byStatus ?? [];
$coverage = $coverage ?? [];
$daily = $daily ?? ['this' => array_fill(0, 30, 0), 'last' => array_fill(0, 30, 0), 'labels' => []];
$byCategory = $byCategory ?? [];
$stock = $stock ?? ['tracked' => 0, 'healthy' => 0, 'low' => 0, 'out' => 0, 'untracked' => 0];
$stockAttention = $stockAttention ?? [];
$s = static fn($k) => (int) ($stats[$k] ?? 0);
$total = $s('products');
$pct = static fn(int $n, int $of): int => $of > 0 ? (int) round($n / $of * 100) : 0;

// Period-over-period change for "added" products.
$prev = $s('addedPrev30');
$now30 = $s('added30');
$change = $prev > 0 ? (int) round(($now30 - $prev) / $prev * 100) : ($now30 > 0 ? 100 : 0);

$ago = static function (?string $ts): string {
    if (!$ts) return '—';
    $d = time() - strtotime($ts);
    if ($d < 60) return 'just now';
    if ($d < 3600) return floor($d / 60) . 'm ago';
    if ($d < 86400) return floor($d / 3600) . 'h ago';
    if ($d < 86400 * 7) return floor($d / 86400) . 'd ago';
    return date('M j', strtotime($ts));
};
$statusTone = ['active' => 't-green', 'draft' => 't-sand', 'coming_soon' => 't-blue', 'archived' => 't-rose'];
$statusText = ['active' => 'Live on store', 'draft' => 'Draft', 'coming_soon' => 'Coming soon', 'archived' => 'Archived'];

include __DIR__ . '/partials/header.php';
?>
<div class="dash">

  <!-- Catalogue size (≈ Total Sales) -->
  <section class="card d-sales">
    <div class="card-h">
      <div class="flex items-center gap-3">
        <span class="metric-icon"><i data-lucide="gem"></i></span>
        <h3 style="font-size:15px">Catalogue <span class="muted" style="font-weight:500;font-size:12px">(SKUs)</span></h3>
      </div>
      <a class="muted" href="<?= route('admin.products.index') ?>" title="All products"><i data-lucide="trending-up" style="width:18px"></i></a>
    </div>
    <div class="flex items-end justify-between gap-3">
      <div class="bignum"><?= number_format($total) ?></div>
      <div class="minibars" aria-hidden="true">
        <?php $tail = array_slice($daily['this'], -12); $mx = max(1, ...$tail);
        foreach ($tail as $i => $n): $h = $n > 0 ? 14 + (int) round($n / $mx * 38) : 8 + ($i * 3) % 16; ?>
          <i style="height:<?= $h ?>px;<?= $n > 0 ? '' : 'opacity:.35' ?>"></i>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="mt-3 text-[13px] muted">
      <span class="delta<?= $change < 0 ? ' down' : '' ?>"><i data-lucide="<?= $change < 0 ? 'arrow-down' : 'arrow-up' ?>"></i><?= abs($change) ?>%</span>
      &nbsp;added vs previous 30 days
    </div>
    <div class="substats">
      <div><b><?= number_format($s('active')) ?></b><span>Live</span></div>
      <div><b><?= $pct($s('active'), $total) ?>%</b><span>Live rate</span></div>
      <div><b><?= number_format($s('media')) ?></b><span>Media files</span></div>
    </div>
  </section>

  <!-- Hero -->
  <section class="hero d-hero">
    <div class="hero-strip glass">
      <div>
        <span class="ic"><i data-lucide="sun" id="greetIcon"></i></span>
        <span><b id="greet">Good day, Admin</b><small>Your showroom, in motion.</small></span>
      </div>
      <div>
        <span class="ic" style="background:#eef2f8;color:#5b7bb0"><i data-lucide="rocket"></i></span>
        <span><b>Storefront launches in <span id="dLeft">—</span></b><small>Going live 25 Nov 2026</small></span>
      </div>
    </div>

    <div class="hero-art">
      <?php
      // Glass call-outs over the warehouse scene. x/y = % position of the pointed-at spot; side = which way the tag opens.
      $spots = [
          ['Storage racks', 'Palletised stock', 'layers', 21, 33, 'r'],
          ['Upper bays', 'Overflow inventory', 'boxes', 44, 16, 'r'],
          ['Brand sign', 'Maa Sarada', 'shopping-bag', 60, 23, 'r'],
          ['Packing station', 'Orders packed here', 'package-open', 50, 44, 'l'],
          ['Pallet jack', 'Moves heavy loads', 'forklift', 25, 66, 'r'],
          ['Dispatch conveyor', 'Out for delivery', 'truck', 68, 72, 'l'],
          ['Loading bay', 'Inbound & outbound', 'warehouse', 88, 48, 'l'],
      ];
      ?>
      <div class="wh">
        <img src="<?= base_url('assets/image/warehouse.webp') ?>" alt="Isometric warehouse with stocked racks, packing table and dispatch conveyor" width="1200" height="900">
        <?php foreach ($spots as $i => [$name, $sub, $icon, $x, $y, $side]): ?>
        <div class="wh-spot <?= $side === 'l' ? 'is-l' : '' ?>" style="left:<?= $x ?>%;top:<?= $y ?>%;--d:<?= $i * 0.35 ?>s">
          <span class="wh-dot"></span>
          <span class="wh-tag glass"><i data-lucide="<?= $icon ?>"></i><span><b><?= e($name) ?></b><small><?= e($sub) ?></small></span></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="hero-kpis">
      <?php
      $kpis = [
          ['Brands', $s('brands'), 'bookmark', route('admin.brands')],
          ['Categories', $s('categories'), 'layout-grid', route('admin.categories')],
          ['Featured', $s('featured'), 'star', route('admin.products.index')],
          ['Enquiry-only', $s('enquiry'), 'message-circle-question', route('admin.products.index')],
      ];
      foreach ($kpis as [$label, $val, $icon, $href]): ?>
      <a class="kpi glass" href="<?= $href ?>">
        <span class="top"><span class="ic"><i data-lucide="<?= $icon ?>"></i></span><?= e($label) ?></span>
        <span class="row"><b><?= number_format($val) ?></b></span>
      </a>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Catalogue pipeline (≈ Order Fulfillment) -->
  <section class="card d-fulfil">
    <div class="card-h"><h3>Catalogue pipeline</h3><a class="viewall" href="<?= route('admin.products.index') ?>">View all <i data-lucide="arrow-right"></i></a></div>
    <div class="flow">
      <?php
      $flow = [
          ['All products', $total, 'clipboard-list', 't-gold'],
          ['Draft', $s('draft'), 'file-pen', 't-sand'],
          ['Coming soon', $s('coming_soon'), 'clock', 't-blue'],
          ['Live', $s('active'), 'circle-check', 't-green'],
          ['Archived', $s('archived'), 'archive', 't-rose'],
      ];
      foreach ($flow as [$label, $n, $icon, $tone]):
          $w = $label === 'All products' ? ($total > 0 ? 100 : 0) : $pct($n, $total); ?>
      <div class="flow-row <?= $tone ?>">
        <div class="l"><span class="ic"><i data-lucide="<?= $icon ?>"></i></span><?= e($label) ?><b><?= number_format($n) ?></b></div>
        <div class="bar"><span style="width:<?= $w ?>%"></span></div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Recently added (≈ Live Shipments) -->
  <section class="card d-recent">
    <div class="card-h"><h3>Recently added</h3><a class="viewall" href="<?= route('admin.products.index') ?>">View all <i data-lucide="arrow-right"></i></a></div>
    <div class="flow">
      <?php if (!$recent): ?>
        <div class="ship t-sand" style="text-align:center;padding:26px 14px">
          <i data-lucide="package-plus" style="width:22px;color:#a08c66"></i>
          <div class="text-[13px] muted mt-2">No products yet.</div>
          <a class="btn gold mt-3" style="padding:8px 12px" href="<?= route('admin.products.create') ?>"><i data-lucide="plus" style="width:15px"></i>Add product</a>
        </div>
      <?php else: foreach (array_slice($recent, 0, 4) as $p):
          $st = (string) $p['status']; ?>
        <a class="ship <?= $statusTone[$st] ?? 't-sand' ?>" href="<?= route('admin.products.edit', ['id' => $p['id']]) ?>">
          <div class="l"><i data-lucide="box"></i><span><?= e((string) $p['sku']) ?></span><small><?= e($ago($p['created_at'] ?? null)) ?></small></div>
          <span class="st"><?= e($statusText[$st] ?? ucfirst($st)) ?></span>
        </a>
      <?php endforeach; endif; ?>
    </div>
  </section>

  <!-- Trend (≈ Revenue Trend) -->
  <section class="card d-trend">
    <div class="card-h"><h3>Products added</h3><span class="metric-icon" style="width:26px;height:26px;border-radius:8px;background:#e3ebf8;color:#3d7bd9"><i data-lucide="arrow-up-right" style="width:14px"></i></span></div>
    <div class="legend mb-3"><span><i style="background:#efb323"></i>This period</span><span><i style="background:#c9bda4"></i>Last period</span></div>
    <div style="height:230px"><canvas id="trendChart"></canvas></div>
  </section>

  <!-- Readiness (≈ Sales Channels) -->
  <section class="card d-channels">
    <div class="card-h"><h3 style="font-size:17px">Storefront readiness</h3><a class="viewall" href="<?= route('admin.products.index') ?>">View all <i data-lucide="arrow-right"></i></a></div>
    <div class="chan">
      <?php
      $ready = [
          ['Images uploaded', $coverage['media'] ?? 0, 'image', 't-green', '#43bf83'],
          ['Price set', $coverage['pricing'] ?? 0, 'indian-rupee', 't-rose', '#e07a64'],
          ['Categorised', $coverage['category'] ?? 0, 'layout-grid', 't-gold', '#efb323'],
          ['SEO complete', $coverage['seo'] ?? 0, 'search-check', 't-blue', '#6a9be6'],
      ];
      foreach ($ready as [$label, $n, $icon, $tone, $col]): $p = $pct((int) $n, $total); ?>
      <div class="chan-row <?= $tone ?>">
        <span class="ic"><i data-lucide="<?= $icon ?>"></i></span>
        <span class="name"><?= e($label) ?></span>
        <span class="pct"><?= $p ?>%</span>
        <span class="bar"><span style="width:<?= $p ?>%;background:<?= $col ?>"></span></span>
        <span class="val"><?= (int) $n ?> / <?= $total ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>

<!-- Inventory stock card -->
<section class="card dash-stock">
  <div class="card-h">
    <div class="flex items-center gap-3">
      <span class="metric-icon" style="background:#eaf5ee;color:#2f9e63"><i data-lucide="archive"></i></span>
      <h3 style="font-size:17px">Inventory <span class="muted" style="font-weight:500;font-size:12px">· stock health</span></h3>
    </div>
    <a class="viewall" href="<?= route('admin.inventory') ?>">Manage <i data-lucide="arrow-right"></i></a>
  </div>
  <?php if ((int) ($stock['tracked'] ?? 0) === 0): ?>
    <div class="stock-empty muted">
      <i data-lucide="package-search"></i>
      <span>No products are tracking stock yet. <a href="<?= route('admin.inventory.stock') ?>">Start tracking →</a></span>
    </div>
  <?php else: ?>
    <div class="stock-grid">
      <div class="stock-tiles">
        <?php
        $tiles = [
            ['Tracked', (int) $stock['tracked'], '#5b7bb0', route('admin.inventory.stock')],
            ['In stock', (int) $stock['healthy'], '#2f9e63', route('admin.inventory.stock') . '?status=healthy'],
            ['Low', (int) $stock['low'], '#c98a12', route('admin.inventory.stock') . '?status=low'],
            ['Out', (int) $stock['out'], '#c2412a', route('admin.inventory.stock') . '?status=out'],
        ];
        foreach ($tiles as [$lbl, $n, $col, $href]): ?>
        <a class="stock-tile" href="<?= $href ?>">
          <span class="dot" style="background:<?= $col ?>"></span>
          <b><?= number_format($n) ?></b>
          <span class="muted"><?= $lbl ?></span>
        </a>
        <?php endforeach; ?>
      </div>
      <div class="stock-att">
        <?php if ($stockAttention): ?>
          <div class="stock-att-h">Needs attention</div>
          <?php foreach ($stockAttention as $a): $isOut = (string) $a['status'] === 'out';
              $qd = rtrim(rtrim((string) $a['quantity'], '0'), '.'); $qd = $qd === '' ? '0' : $qd; ?>
          <a class="stock-att-row" href="<?= route('admin.inventory.stock') ?>?product=<?= (int) $a['id'] ?>">
            <span class="pill <?= $isOut ? 'out' : 'low' ?>"><?= $isOut ? 'Out' : 'Low' ?></span>
            <span class="nm"><?= e((string) $a['name']) ?></span>
            <span class="q"><?= e($qd) ?> <span class="muted"><?= e((string) $a['unit']) ?></span></span>
          </a>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="stock-ok"><i data-lucide="circle-check-big"></i> All tracked products are in healthy stock.</div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</section>

<div class="dash-bottom">
  <!-- Recent products (≈ Recent Sales) -->
  <section class="card" style="padding:20px 8px 8px">
    <div class="card-h" style="padding:0 12px"><h3 style="font-size:17px">Recent products</h3><a class="viewall" href="<?= route('admin.products.index') ?>">View all <i data-lucide="arrow-right"></i></a></div>
    <div style="overflow:auto">
      <table class="data">
        <thead><tr><th>Product</th><th>Type</th><th>Brand</th><th>Price</th><th>Status</th><th>Added</th></tr></thead>
        <tbody>
          <?php if (!$recent): ?>
            <tr><td colspan="6" style="text-align:center;padding:34px" class="muted">Nothing here yet. <a style="color:#b17a13;font-weight:700" href="<?= route('admin.products.create') ?>">Add your first product →</a></td></tr>
          <?php else: foreach ($recent as $p): $st = (string) $p['status']; ?>
          <tr>
            <td>
              <a href="<?= route('admin.products.edit', ['id' => $p['id']]) ?>" style="display:flex;align-items:center;gap:12px">
                <span class="thumbmini"><?php if ($p['cover'] ?? null): ?><img src="<?= base_url($p['cover']) ?>" alt=""><?php else: ?><i data-lucide="image"></i><?php endif; ?></span>
                <span><span style="font-weight:700;display:block"><?= e((string) $p['name']) ?></span><span class="muted" style="font-size:11.5px"><?= e((string) $p['sku']) ?></span></span>
              </a>
            </td>
            <td class="muted"><?= e((string) ($p['type_name'] ?? '—')) ?></td>
            <td class="muted"><?= e((string) ($p['brand_name'] ?? '—')) ?></td>
            <td style="font-weight:700;white-space:nowrap"><?= $p['price'] !== null ? '₹' . number_format((float) $p['price']) . ($p['unit'] ? '<span class="muted" style="font-weight:500">/' . e((string) $p['unit']) . '</span>' : '') : '<span class="muted">—</span>' ?></td>
            <td><span class="badge <?= e($st) ?>"><?= e(str_replace('_', ' ', $st)) ?></span></td>
            <td class="muted" style="white-space:nowrap"><?= e($ago($p['created_at'] ?? null)) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- Top categories + quick actions -->
  <section class="card">
    <div class="card-h"><h3 style="font-size:17px">Top categories</h3><a class="viewall" href="<?= route('admin.categories') ?>">Manage <i data-lucide="arrow-right"></i></a></div>
    <div class="flow">
      <?php if (!$byCategory): ?>
        <p class="text-[13px] muted">No categories yet.</p>
      <?php else: $cmax = max(1, ...array_map(static fn($c) => (int) $c['n'], $byCategory));
        $tones = ['t-gold', 't-green', 't-blue', 't-rose'];
        foreach ($byCategory as $i => $c): ?>
        <div class="flow-row <?= $tones[$i % 4] ?>">
          <div class="l"><span class="ic"><i data-lucide="layers"></i></span><?= e((string) $c['name']) ?><b><?= (int) $c['n'] ?></b></div>
          <div class="bar"><span style="width:<?= (int) round((int) $c['n'] / $cmax * 100) ?>%"></span></div>
        </div>
      <?php endforeach; endif; ?>
    </div>
    <div class="grid grid-cols-2 gap-2 mt-4">
      <a class="quickaction" href="<?= route('admin.products.create') ?>"><i data-lucide="plus" style="width:16px"></i>Add product</a>
      <a class="quickaction" href="<?= route('admin.attributes') ?>"><i data-lucide="sliders-horizontal" style="width:16px"></i>Attributes</a>
    </div>
  </section>
</div>

<?php
$trendJson = json_encode($daily);
$scripts = <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
(function(){
  var h=new Date().getHours();
  var g=h<5?'Good evening':h<12?'Good morning':h<17?'Good afternoon':'Good evening';
  var ge=document.getElementById('greet'); if(ge) ge.textContent=g+', Admin';
  if(h>=18||h<5){var gi=document.getElementById('greetIcon'); if(gi){gi.setAttribute('data-lucide','moon');lucide.createIcons();}}

  var t=new Date('2026-11-25T00:00:00').getTime();
  var days=Math.max(0,Math.ceil((t-Date.now())/86400000));
  var el=document.getElementById('dLeft'); if(el) el.textContent=days===0?'— today!':days+(days===1?' day':' days');

  var d=$trendJson, cv=document.getElementById('trendChart');
  if(cv && window.Chart){
    var ctx=cv.getContext('2d'), grad=ctx.createLinearGradient(0,0,0,230);
    grad.addColorStop(0,'rgba(239,179,35,.32)'); grad.addColorStop(1,'rgba(239,179,35,0)');
    new Chart(cv,{type:'line',data:{labels:d.labels,datasets:[
      {label:'This period',data:d['this'],borderColor:'#efb323',backgroundColor:grad,fill:true,tension:.42,borderWidth:2.4,pointRadius:0,pointHoverRadius:4},
      {label:'Last period',data:d.last,borderColor:'#c9bda4',fill:false,tension:.42,borderWidth:2,pointRadius:0,pointHoverRadius:4}
    ]},options:{maintainAspectRatio:false,interaction:{mode:'index',intersect:false},
      plugins:{legend:{display:false},tooltip:{backgroundColor:'#292821',padding:10,cornerRadius:10}},
      scales:{x:{grid:{display:false},border:{display:false},ticks:{color:'#8b806d',maxTicksLimit:4,maxRotation:0,font:{family:'Manrope',size:11}}},
              y:{beginAtZero:true,grid:{color:'rgba(160,130,80,.12)'},border:{display:false},ticks:{color:'#8b806d',precision:0,maxTicksLimit:4,font:{family:'Manrope',size:11}}}}}});
  }
})();
</script>
JS;
include __DIR__ . '/partials/footer.php';
?>
