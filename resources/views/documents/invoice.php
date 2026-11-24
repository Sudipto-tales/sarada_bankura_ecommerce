<?php
use App\Services\Customer\Phone;
use App\Services\Order\Money;
use App\Services\Order\OrderService as OS;

$o = $order;
$isTax = $title === \App\Services\Order\DocumentService::TAX_INVOICE;
$docTitle = ($doc['invoice_number'] ?? 'Invoice') . ' · ' . $business['name'];
$qty = static fn($q): string => rtrim(rtrim(number_format((float) $q, 3, '.', ','), '0'), '.');
$due = (float) $o['due_amount'];
$sellerAddr = array_filter([$business['address']['line1'] ?? '', $business['address']['line2'] ?? '',
    trim(($business['address']['city'] ?? '') . ', ' . ($business['address']['state'] ?? '') . ' ' . ($business['address']['pincode'] ?? ''), ', ')]);
include __DIR__ . '/_head.php';
?>
<body>
<div class="doc-bar">
  <div><b><?= e($title) ?> <?= e((string) $doc['invoice_number']) ?></b><p><?= $public ? 'Issued by ' . e((string) $business['name']) : 'Print or save as PDF (A4). The QR opens this invoice online.' ?></p></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($public && !empty($orderUrl)): ?><a class="doc-btn ghost" href="<?= e($orderUrl) ?>">Order status</a><?php endif; ?>
    <?php if (!$public): ?><a class="doc-btn ghost" href="<?= route('admin.orders.show', ['id' => $o['id']]) ?>">Back to order</a><?php endif; ?>
    <button class="doc-btn" type="button" onclick="window.print()">Print / Save PDF</button>
  </div>
</div>
<article class="paper">
  <?php include __DIR__ . '/_brand.php'; ?>
  <div class="paper-in">
    <header class="doc-head">
      <div>
        <div class="eyebrow">Marble · Granite · Natural stone</div>
        <h1><?= e((string) $business['legal_name']) ?></h1>
        <div class="tag"><?= e(implode(', ', $sellerAddr)) ?><?php if ($business['gstin']): ?><br>GSTIN <b><?= e((string) $business['gstin']) ?></b><?php endif; ?><?php if (!empty($business['address']['state_code'])): ?> · State code <?= e((string) $business['address']['state_code']) ?><?php endif; ?></div>
      </div>
      <div class="doc-title">
        <div class="eyebrow"><?= $isTax ? 'Original for recipient' : 'Sales document' ?></div>
        <h2 class="<?= $isTax ? '' : 'sm' ?>"><?= e(mb_strtoupper($title)) ?></h2>
        <div class="doc-no">No. <span class="num"><?= e((string) $doc['invoice_number']) ?></span></div>
      </div>
    </header>

    <section class="doc-parties">
      <div><div class="eyebrow">Bill to</div>
        <h3><?= e((string) ($billing['name'] ?? $o['customer_name'])) ?></h3>
        <p><?= $billing ? implode('<br>', array_map('e', OS::addressLines($billing))) . '<br>' : '' ?><?= e(Phone::format((string) $o['customer_phone'])) ?></p></div>
      <div><div class="eyebrow">Ship to</div>
        <?php if ($shipping): ?><h3><?= e((string) $shipping['name']) ?></h3><p><?= implode('<br>', array_map('e', OS::addressLines($shipping))) ?><br><?= e(Phone::format((string) $shipping['phone'])) ?></p>
        <?php else: ?><p style="margin-top:8px">Collected from the showroom</p><?php endif; ?></div>
      <div><div class="eyebrow">Invoice details</div>
        <dl class="doc-dl">
          <dt>Invoice date</dt><dd><?= e(date('j M Y', strtotime((string) $doc['invoice_date']))) ?></dd>
          <dt>Order no.</dt><dd><?= e((string) $o['order_number']) ?></dd>
          <dt>Order date</dt><dd><?= e(date('j M Y', strtotime((string) $o['created_at']))) ?></dd>
          <dt>Place of supply</dt><dd><?= e((string) $o['place_of_supply']) ?></dd>
        </dl></div>
    </section>

    <table class="doc-items">
      <thead><tr><th style="width:34px">#</th><th>Description</th><th style="width:70px">HSN</th><th class="r" style="width:90px">Qty</th><th class="r" style="width:90px">Rate (₹)</th><th class="r" style="width:110px">Amount (₹)</th></tr></thead>
      <tbody>
      <?php foreach ($items as $i => $it): ?>
        <tr>
          <td class="muted"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></td>
          <td><b><?= e((string) $it['product_name']) ?></b><small><?= e(implode(' · ', array_filter([$it['variant_name'], $it['sku']]))) ?><?= (float) $it['discount_amount'] > 0 ? ' · discount ₹' . Money::format($it['discount_amount']) : '' ?></small></td>
          <td class="muted"><?= e((string) ($it['hsn_code'] ?? '')) ?></td>
          <td class="r num"><?= $qty($it['quantity']) ?> <?= e((string) $it['unit']) ?></td>
          <td class="r num"><?= Money::format($it['unit_price']) ?></td>
          <td class="r num"><b><?= Money::format($it['line_total']) ?></b></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="doc-sub"><span>Amounts in Indian Rupees (INR)</span><span>Delivery included in price</span></div>

    <section class="doc-bottom">
      <div class="doc-notes">
        <div class="eyebrow">Notes</div>
        <?php if ($o['notes']): ?><p><?= nl2br(e((string) $o['notes'])) ?></p><?php endif; ?>
        <p>Thank you for choosing <?= e((string) $business['name']) ?>. Please inspect the material at delivery and mention the invoice number in all payment references.</p>
        <?php if (!$isTax): ?><p class="muted" style="font-size:10.5px"><?= $business['gstin'] ? 'No GST charged on this invoice.' : 'Seller not registered under GST — no GST is charged on this bill of supply.' ?></p><?php endif; ?>
      </div>
      <div class="doc-sums">
        <div><span>Sub total</span><b class="num">₹<?= Money::format($doc['subtotal']) ?></b></div>
        <?php if ((float) $doc['discount_amount'] > 0): ?><div><span>Discount</span><b class="num">−₹<?= Money::format($doc['discount_amount']) ?></b></div><?php endif; ?>
        <?php if ($taxRows): ?><div><span>Taxable value</span><b class="num">₹<?= Money::format((float) $doc['subtotal'] - (float) $doc['discount_amount']) ?></b></div><?php endif; ?>
        <?php foreach ($taxRows as $t): ?><div><span><?= e($t['label']) ?></span><b class="num">₹<?= Money::format($t['amount']) ?></b></div><?php endforeach; ?>
        <div class="total"><span>Total</span><b class="num">₹<?= Money::format($total) ?></b></div>
        <div class="doc-due">
          <small><?= $due > 0 ? 'Amount due' : 'Invoice total' ?></small>
          <b class="num">₹<?= Money::format($due > 0 ? $due : $total) ?></b>
          <p><?= e($words) ?></p>
        </div>
        <?php if ($due <= 0 && (float) $o['paid_amount'] > 0): ?><p style="text-align:right;margin:10px 0 0"><span class="doc-stamp paid">Paid</span></p><?php endif; ?>
      </div>
    </section>

    <footer>
      <div class="doc-foot">
        <div class="doc-qr"><div class="qr" id="qrcode" aria-label="QR code linking to this invoice"></div><p><b>Scan invoice</b>Opens this invoice online — share it or pay the balance.</p></div>
        <div class="doc-sign"><div class="line"></div><b>Authorised signatory</b><small>For <?= e((string) $business['legal_name']) ?></small></div>
      </div>
      <?php include __DIR__ . '/_contact.php'; ?>
      <p class="doc-fine">This is a computer-generated <?= e(mb_strtolower($title)) ?>.</p>
    </footer>
  </div>
</article>
<script src="<?= base_url('assets/vendor/qrcode.min.js') ?>"></script>
<script>
(function(){var el=document.getElementById('qrcode');if(window.QRCode)new QRCode(el,{text:<?= json_encode($qrUrl) ?>,width:88,height:88,colorDark:'#111111',colorLight:'#ffffff',correctLevel:QRCode.CorrectLevel.M});})();
</script>
<?php if ($public) { $o = $order; $payContext = 'invoice'; $qrLoaded = true; include __DIR__ . '/_pay-window.php'; } ?>
</body>
</html>
