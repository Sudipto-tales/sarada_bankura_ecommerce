<?php
/** /pay/{order token} — the payment-request link. The 6-minute timer starts only on "Pay now". */
use App\Services\Order\Money;

$docTitle = 'Pay for order ' . $o['order_number'] . ' · ' . $business['name'];
$st = (string) $o['order_status'];
$qty = static fn($q): string => rtrim(rtrim(number_format((float) $q, 3, '.', ','), '0'), '.');
$pending = (float) $o['pending_verification_amount'];
include __DIR__ . '/_head.php';
?>
<body>
<main class="pub">
  <header class="pub-head">
    <span class="pub-logo"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php else: ?><b><?= e(mb_substr((string) $business['name'], 0, 2)) ?></b><?php endif; ?></span>
    <div><h1><?= e((string) $business['name']) ?></h1><small class="muted">Payment for order <?= e((string) $o['order_number']) ?></small></div>
  </header>

  <section class="pub-card">
    <div class="eyebrow">Amount to pay now</div>
    <?php if ($st === 'rejected'): ?>
      <h2 class="serif" style="font-size:24px;margin:6px 0 8px">No payment needed</h2>
      <p class="pub-note" style="margin:0">This order was not accepted. <?= $o['customer_note'] ? nl2br(e((string) $o['customer_note'])) : '' ?></p>
    <?php elseif ($dueNow > 0): ?>
      <p class="serif num" style="font-size:38px;font-weight:700;margin:6px 0 4px">₹<?= Money::format($dueNow) ?></p>
      <p class="muted" style="margin:0 0 16px">Order total ₹<?= Money::format($o['grand_total']) ?> · paid ₹<?= Money::format($o['paid_amount']) ?><?= $pending > 0 ? ' · ₹' . Money::format($pending) . ' being checked' : '' ?></p>
      <?php if ($canPay): ?>
        <button type="button" class="doc-btn" data-pay hidden style="width:100%;justify-content:center;padding:14px;font-size:15px">Pay now ₹<?= Money::format($dueNow) ?></button>
        <p class="muted" style="font-size:12px;margin:10px 0 0">Pay by UPI (any app) or bank transfer. You get 6 minutes to pay and confirm with your payment ID.</p>
      <?php else: ?>
        <p class="pub-note" style="margin:0">Online payment isn’t set up yet. Please call or WhatsApp us to pay.</p>
      <?php endif; ?>
    <?php elseif ($pending > 0): ?>
      <h2 class="serif" style="font-size:24px;margin:6px 0 8px">Payment being checked</h2>
      <p class="muted" style="margin:0">We received your payment details for ₹<?= Money::format($pending) ?> and are confirming them with our bank. Nothing more is due right now.</p>
    <?php else: ?>
      <h2 class="serif" style="font-size:24px;margin:6px 0 8px">All paid — thank you!</h2>
      <p class="muted" style="margin:0">Nothing is due on this order.</p>
    <?php endif; ?>
    <?php if ($lastRejected && $st !== 'rejected'): ?><p class="pub-note" style="margin:14px 0 0;background:#f7e1dc;color:#6b2214">Your last payment of ₹<?= Money::format($lastRejected['amount']) ?> could not be confirmed: <?= e((string) $lastRejected['reject_reason']) ?></p><?php endif; ?>
  </section>

  <section class="pub-card">
    <h2>Order <?= e((string) $o['order_number']) ?></h2>
    <table class="pub-items">
      <?php foreach ($items as $it): ?>
      <tr><td><b><?= e((string) $it['product_name']) ?></b><small><?= e(implode(' · ', array_filter([$it['variant_name'], $qty($it['quantity']) . ' ' . $it['unit']]))) ?></small></td>
        <td class="r num">₹<?= Money::format($it['line_total']) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <div class="pub-sums" style="margin-top:10px">
      <?php if ((float) $o['discount_amount'] > 0): ?><div><span class="muted">Discount</span><span class="num">−₹<?= Money::format($o['discount_amount']) ?></span></div><?php endif; ?>
      <?php foreach ($taxRows as $t): ?><div><span class="muted"><?= e($t['label']) ?></span><span class="num">₹<?= Money::format($t['amount']) ?></span></div><?php endforeach; ?>
      <div class="total"><span>Total</span><span class="num">₹<?= Money::format($o['grand_total']) ?></span></div>
    </div>
  </section>

  <div class="pub-links">
    <a class="doc-btn ghost" href="<?= e((string) route('order.public', ['token' => $o['public_token']])) ?>">View order status</a>
    <?php if ($business['whatsapp']): ?><a class="doc-btn ghost" target="_blank" rel="noopener" href="https://wa.me/<?= e(preg_replace('/\D/', '', (string) $business['whatsapp'])) ?>?text=<?= rawurlencode('Hello, about payment for order ' . $o['order_number']) ?>">WhatsApp us</a><?php endif; ?>
  </div>
  <p class="pub-foot"><?= e((string) $business['name']) ?> · <?= e((string) ($business['address']['city'] ?? '')) ?></p>
</main>
<?php $payContext = 'request'; $payFloating = false; include __DIR__ . '/_pay-window.php'; ?>
</body>
</html>
