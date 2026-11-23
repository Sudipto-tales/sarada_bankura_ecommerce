<?php
use App\Services\Customer\Phone;
use App\Services\Order\OrderService as OS;

$o = $order;
$docTitle = 'Packing slip ' . $o['order_number'];
$qty = static fn($q): string => rtrim(rtrim(number_format((float) $q, 3, '.', ','), '0'), '.');
include __DIR__ . '/_head.php';
?>
<body>
<div class="doc-bar">
  <div><b>Packing slip · <?= e((string) $o['order_number']) ?></b><p>No prices — this travels with the material. The tax invoice is issued at dispatch.</p></div>
  <div style="display:flex;gap:8px"><a class="doc-btn ghost" href="<?= route('admin.orders.show', ['id' => $o['id']]) ?>">Back to order</a><button class="doc-btn" type="button" onclick="window.print()">Print</button></div>
</div>
<article class="paper">
  <?php include __DIR__ . '/_brand.php'; ?>
  <div class="paper-in">
    <header class="doc-head">
      <div><div class="eyebrow">From</div><h1><?= e((string) $business['name']) ?></h1>
        <div class="tag"><?= e(trim(($business['address']['city'] ?? '') . ', ' . ($business['address']['state'] ?? ''), ', ')) ?><?= $business['phone'] ? ' · ' . e((string) $business['phone']) : '' ?></div></div>
      <div class="doc-title"><div class="eyebrow">Logistics</div><h2>PACKING SLIP</h2><div class="doc-no">Order <span class="num"><?= e((string) $o['order_number']) ?></span></div></div>
    </header>

    <section class="slip-ship">
      <div class="slip-to">
        <div class="eyebrow">Ship to</div>
        <?php if ($shipping): ?>
        <h3><?= e((string) $shipping['name']) ?></h3>
        <p><?= implode('<br>', array_map('e', OS::addressLines($shipping))) ?></p>
        <p class="ph">☎ <?= e(Phone::format((string) $shipping['phone'])) ?></p>
        <?php else: ?>
        <h3><?= e((string) $o['customer_name']) ?></h3><p>Customer collects from the showroom</p><p class="ph">☎ <?= e(Phone::format((string) $o['customer_phone'])) ?></p>
        <?php endif; ?>
      </div>
      <div class="slip-box">
        <div class="doc-qr" style="justify-content:center;flex-direction:column;text-align:center"><div class="qr" id="qrcode" aria-label="QR code"></div><p><b><?= e($qrLabel) ?></b></p></div>
        <dl class="doc-dl" style="text-align:left;margin-top:12px">
          <dt>Order date</dt><dd><?= e(date('j M Y', strtotime((string) $o['created_at']))) ?></dd>
          <dt>Packed</dt><dd><?= e(date('j M Y', strtotime((string) ($o['packed_at'] ?: $slip['created_at'])))) ?></dd>
          <dt>Lines</dt><dd><?= count($items) ?></dd>
        </dl>
      </div>
    </section>

    <table class="doc-items" style="margin-top:24px">
      <thead><tr><th style="width:34px">#</th><th>Description</th><th>Option / SKU</th><th class="r" style="width:110px">Qty</th><th style="width:70px">Unit</th><th style="width:60px">Packed</th></tr></thead>
      <tbody>
      <?php foreach ($items as $i => $it): ?>
        <tr><td class="muted"><?= $i + 1 ?></td><td><b><?= e((string) $it['product_name']) ?></b></td>
          <td><?= e(implode(' · ', array_filter([$it['variant_name'], $it['sku']]))) ?></td>
          <td class="r num"><b><?= $qty($it['quantity']) ?></b></td><td><?= e((string) $it['unit']) ?></td><td><span class="slip-check"></span></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($o['notes']): ?><p style="margin-top:18px;font-size:12px"><b>Note:</b> <?= e((string) $o['notes']) ?></p><?php endif; ?>

    <footer class="doc-foot" style="margin-top:40px">
      <div class="doc-sign" style="text-align:left"><div class="line" style="margin-left:0"></div><b>Packed by</b></div>
      <div class="doc-sign"><div class="line"></div><b>Received in good condition</b><small>Customer signature &amp; date</small></div>
    </footer>
    <p class="doc-fine" style="margin-top:30px">The tax invoice travels with the shipment — scan the QR to view it.</p>
  </div>
</article>
<script src="<?= base_url('assets/vendor/qrcode.min.js') ?>"></script>
<script>
(function(){var el=document.getElementById('qrcode');if(window.QRCode)new QRCode(el,{text:<?= json_encode($qrUrl) ?>,width:88,height:88,colorDark:'#111111',colorLight:'#ffffff',correctLevel:QRCode.CorrectLevel.M});})();
</script>
</body>
</html>
