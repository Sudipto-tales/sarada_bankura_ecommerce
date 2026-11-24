<?php
use App\Services\Order\Money;
use App\Services\Order\OrderService as OS;

$docTitle = 'Order ' . $o['order_number'] . ' · ' . $business['name'];
$st = (string) $o['order_status'];
$qty = static fn($q): string => rtrim(rtrim(number_format((float) $q, 3, '.', ','), '0'), '.');
$headline = [
    'pending' => 'We’ve received your order', 'accepted' => 'Order confirmed', 'processing' => 'Your material is being prepared',
    'packed' => 'Packed and ready', 'dispatched' => 'On the way', 'delivered' => 'Delivered', 'rejected' => 'Order not accepted',
][$st] ?? 'Your order';
$tone = ['delivered' => 'good', 'rejected' => 'bad', 'pending' => 'warn'][$st] ?? '';
$idx = array_search($st, OS::FLOW, true);
$due = (float) $o['due_amount'];
include __DIR__ . '/_head.php';
?>
<body>
<main class="pub">
  <header class="pub-head">
    <span class="pub-logo"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php else: ?><b><?= e(mb_substr((string) $business['name'], 0, 2)) ?></b><?php endif; ?></span>
    <div><h1><?= e((string) $business['name']) ?></h1><small class="muted">Order <?= e((string) $o['order_number']) ?> · placed <?= e(date('j M Y', strtotime((string) $o['created_at']))) ?></small></div>
  </header>

  <section class="pub-card">
    <div class="pub-status"><div><div class="eyebrow">Order status</div><h2><?= e($headline) ?></h2></div><span class="pub-pill <?= $tone ?>"><?= e(OS::STATUSES[$st] ?? $st) ?></span></div>
    <?php if ($st !== 'rejected'): ?>
    <ol class="pub-steps"><?php foreach (OS::FLOW as $i => $s): ?><li class="<?= $idx !== false && $i <= $idx ? 'done' : '' ?>"><?= e(OS::STATUSES[$s]) ?></li><?php endforeach; ?></ol>
    <?php endif; ?>
    <?php if ($st === 'rejected' && $o['customer_note']): ?><p class="pub-note" style="margin:14px 0 0"><?= nl2br(e((string) $o['customer_note'])) ?></p><?php endif; ?>
    <?php if ($delivery && $st === 'dispatched'): ?>
    <p style="margin:14px 0 0;font-size:13px">Dispatched <?= e(date('j M, g:i a', strtotime((string) $delivery['dispatch_date']))) ?><?= $delivery['courier_name'] ? ' · ' . e((string) $delivery['courier_name']) : '' ?><?= $delivery['tracking_number'] ? ' · #' . e((string) $delivery['tracking_number']) : '' ?>
      <?php if ($delivery['tracking_url']): ?> · <a href="<?= e((string) $delivery['tracking_url']) ?>" target="_blank" rel="noopener">Track</a><?php endif; ?><br>
      <span class="muted">Share your delivery OTP with our delivery person only after you receive the material.</span></p>
    <?php endif; ?>
  </section>

  <section class="pub-card">
    <h2>Items</h2>
    <table class="pub-items">
      <?php foreach ($items as $it): ?>
      <tr><td><b><?= e((string) $it['product_name']) ?></b><small><?= e(implode(' · ', array_filter([$it['variant_name'], $qty($it['quantity']) . ' ' . $it['unit'] . ' × ₹' . Money::format($it['unit_price'])]))) ?></small></td>
        <td class="r num"><b>₹<?= Money::format($it['line_total']) ?></b></td></tr>
      <?php endforeach; ?>
    </table>
    <div class="pub-sums" style="margin-top:10px">
      <div><span class="muted">Items total</span><span class="num">₹<?= Money::format($o['subtotal']) ?></span></div>
      <?php if ((float) $o['discount_amount'] > 0): ?><div><span class="muted">Discount</span><span class="num">−₹<?= Money::format($o['discount_amount']) ?></span></div><?php endif; ?>
      <?php foreach ($taxRows as $t): ?><div><span class="muted"><?= e($t['label']) ?></span><span class="num">₹<?= Money::format($t['amount']) ?></span></div><?php endforeach; ?>
      <div class="total"><span>Total</span><span class="num">₹<?= Money::format($o['grand_total']) ?></span></div>
      <?php if ($st !== 'rejected'): ?>
      <div><span class="muted">Paid</span><span class="num">₹<?= Money::format($o['paid_amount']) ?></span></div>
      <?php if ((float) $o['pending_verification_amount'] > 0): ?><div><span class="muted">Being checked</span><span class="num">₹<?= Money::format($o['pending_verification_amount']) ?></span></div><?php endif; ?>
      <div><b>Amount due</b><b class="num">₹<?= Money::format($due) ?></b></div>
      <?php endif; ?>
    </div>
    <?php if ((float) $o['pending_verification_amount'] > 0 && $st !== 'rejected'): ?><p class="pub-note" style="margin:12px 0 0">We received your payment details for ₹<?= Money::format($o['pending_verification_amount']) ?> and are checking them with our bank.</p><?php endif; ?>
    <?php if ($lastRejected && $st !== 'rejected'): ?><p class="pub-note" style="margin:12px 0 0;background:#f7e1dc;color:#6b2214">Your payment of ₹<?= Money::format($lastRejected['amount']) ?> could not be confirmed: <?= e((string) $lastRejected['reject_reason']) ?> Please contact us if you think this is a mistake.</p><?php endif; ?>
    <?php if ((float) $o['refund_due'] > 0): ?><p class="pub-note" style="margin:12px 0 0">₹<?= Money::format($o['refund_due']) ?> will be refunded to you. Our team will contact you.</p><?php endif; ?>
  </section>

  <?php if ($shipping): ?>
  <section class="pub-card"><h2>Delivery address</h2><p style="margin:0;font-size:13px;line-height:1.6"><b><?= e((string) $shipping['name']) ?></b><br><?= implode('<br>', array_map('e', OS::addressLines($shipping))) ?></p></section>
  <?php endif; ?>

  <div class="pub-links">
    <?php if ($invoiceUrl): ?><a class="doc-btn ghost" href="<?= e($invoiceUrl) ?>">View invoice</a><?php endif; ?>
    <?php if ($business['phone']): ?><a class="doc-btn ghost" href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $business['phone'])) ?>">Call us</a><?php endif; ?>
    <?php if ($business['whatsapp']): ?><a class="doc-btn ghost" target="_blank" rel="noopener" href="https://wa.me/<?= e(preg_replace('/\D/', '', (string) $business['whatsapp'])) ?>?text=<?= rawurlencode('Hello, about my order ' . $o['order_number']) ?>">WhatsApp us</a><?php endif; ?>
  </div>
  <p class="pub-foot"><?= e((string) $business['name']) ?> · <?= e((string) ($business['address']['city'] ?? '')) ?><?= $business['email'] ? ' · ' . e((string) $business['email']) : '' ?></p>
</main>
<?php $payContext = 'order'; include __DIR__ . '/_pay-window.php'; ?>
</body>
</html>
