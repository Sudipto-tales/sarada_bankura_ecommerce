<?php
/** Payment receipt (A4). Needs $p (payment), $o (order), $account, $business, $logo. */
use App\Services\Order\Money;
use App\Services\Order\PaymentService as PS;

$docTitle = 'Receipt ' . $p['payment_number'];
$void = $p['status'] === 'void';
$billing = \App\Services\Order\OrderService::address($o['billing_address']) ?: \App\Services\Order\OrderService::address($o['shipping_address']);
include __DIR__ . '/_head.php';
?>
<body>
<div class="doc-bar">
  <div><b>Payment receipt · <?= e((string) $p['payment_number']) ?></b><p><?= $void ? 'This payment was voided — the receipt is not valid.' : 'Give this to the customer as proof of payment.' ?></p></div>
  <div style="display:flex;gap:8px"><a class="doc-btn ghost" href="<?= route('admin.orders.show', ['id' => $o['id']]) ?>#payment">Back to order</a><button class="doc-btn" type="button" onclick="window.print()">Print</button></div>
</div>
<article class="paper" style="min-height:auto">
  <?php include __DIR__ . '/_brand.php'; ?>
  <div class="paper-in">
    <header class="doc-head">
      <div><div class="eyebrow">Received by</div><h1><?= e((string) $business['legal_name']) ?></h1>
        <div class="tag"><?= e(trim(($business['address']['city'] ?? '') . ', ' . ($business['address']['state'] ?? ''), ', ')) ?><?= $business['gstin'] ? ' · GSTIN ' . e((string) $business['gstin']) : '' ?></div></div>
      <div class="doc-title"><div class="eyebrow">Payment</div><h2>RECEIPT</h2><div class="doc-no">No. <span class="num"><?= e((string) $p['payment_number']) ?></span></div></div>
    </header>

    <?php if ($void): ?><p class="doc-stamp" style="margin:22px 0 0;color:var(--bad);border-color:var(--bad)">VOID — <?= e((string) $p['void_reason']) ?></p><?php endif; ?>

    <section class="doc-parties" style="margin-top:26px">
      <div><div class="eyebrow">Received from</div>
        <h3 style="margin:6px 0 4px;font-size:16px"><?= e((string) ($billing['name'] ?? $o['customer_name'])) ?></h3>
        <p style="margin:0;color:var(--ink2)"><?= $billing ? implode('<br>', array_map('e', \App\Services\Order\OrderService::addressLines($billing))) : e(\App\Services\Customer\Phone::format((string) $o['customer_phone'])) ?></p></div>
      <div><dl class="doc-dl">
        <dt>Date</dt><dd><?= e(date('j M Y, g:i a', strtotime((string) $p['received_at']))) ?></dd>
        <dt>Order</dt><dd><?= e((string) $o['order_number']) ?></dd>
        <dt>Paid by</dt><dd><?= e(PS::METHODS[$p['method']] ?? $p['method']) ?></dd>
        <?php if ($p['reference']): ?><dt>Reference</dt><dd class="num"><?= e((string) $p['reference']) ?></dd><?php endif; ?>
        <?php if ($account): ?><dt>Into</dt><dd><?= e((string) $account['name']) ?></dd><?php endif; ?>
      </dl></div>
    </section>

    <div style="margin:30px 0 8px;padding:22px 24px;border:1px solid var(--line);border-radius:14px;display:flex;justify-content:space-between;align-items:flex-end;gap:20px;flex-wrap:wrap">
      <div><div class="eyebrow">Amount received</div><p style="margin:6px 0 0;color:var(--ink2)"><?= e(Money::words((float) $p['amount'])) ?></p></div>
      <b class="num serif" style="font-size:34px">₹<?= Money::format($p['amount']) ?></b>
    </div>
    <div class="pub-sums" style="max-width:340px;margin-left:auto">
      <div><span class="muted">Order total</span><span class="num">₹<?= Money::format($o['grand_total']) ?></span></div>
      <div><span class="muted">Paid so far</span><span class="num">₹<?= Money::format($o['paid_amount']) ?></span></div>
      <div class="total"><span>Balance due</span><span class="num">₹<?= Money::format($o['due_amount']) ?></span></div>
    </div>

    <footer style="margin-top:40px">
      <div class="doc-foot"><div></div><div class="doc-sign"><div class="line"></div><b>Authorised signatory</b><small>For <?= e((string) $business['legal_name']) ?></small></div></div>
      <?php include __DIR__ . '/_contact.php'; ?>
      <p class="doc-fine">This is a computer-generated receipt. It is not a tax invoice.</p>
    </footer>
  </div>
</article>
</body>
</html>
