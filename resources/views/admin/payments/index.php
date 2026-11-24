<?php
use App\Controllers\Admin\PaymentController as PC;
use App\Services\Customer\Phone;
use App\Services\Order\PaymentService as PS;
use App\Services\Payment\PaymentRequests;
use App\Services\Payment\PayWindow;

$active = 'payments.' . $tab;
$title = 'Payments';
include __DIR__ . '/../orders/_helpers.php';
$url = static function (array $over = []) use ($tab, $q): string {
    $p = array_filter(array_merge(['tab' => $tab, 'q' => $q], $over), static fn($v) => $v !== null && $v !== '');

    return route('admin.payments') . ($p ? '?' . http_build_query($p) : '');
};
$orderLink = static fn($id, $num): string => $id ? '<a class="ord-no" href="' . e(route('admin.orders.show', ['id' => $id])) . '#payment"><b>' . e((string) $num) . '</b></a>' : '<span class="muted">No order</span>';
$ctx = ['checkout' => 'Checkout', 'order' => 'Order page', 'invoice' => 'Invoice', 'request' => 'Pay link'];
$counts = ['verify' => (int) $kpi['verify']['n']];

include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs">Payments <i data-lucide="chevron-right"></i> <?= e(PC::TABS[$tab]) ?></div><h1>Payments<span style="color:#dca71f">.</span></h1></div>
  <a class="btn" href="<?= route('admin.payments.accounts') ?>"><i data-lucide="lock" style="width:16px"></i>Receiving accounts</a>
</div>

<?php if (!$hasUpi): ?>
<div class="ord-warnbox"><i data-lucide="triangle-alert"></i><div><b>Customers can’t pay online yet</b> — add your UPI ID under <a class="linkbtn" href="<?= route('admin.payments.accounts') ?>">Receiving accounts</a>. Until then the Pay buttons on order pages, invoices and pay links stay hidden.</div></div>
<?php endif; ?>

<div class="statstrip">
  <a class="stat glass clickable<?= $tab === 'verify' ? ' on' : '' ?>" href="<?= e($url(['tab' => 'verify', 'q' => null])) ?>"><span class="metric-icon" style="background:#fff0d6;color:#a2632a"><i data-lucide="scan-search"></i></span><div><small>To verify</small><b><?= (int) $kpi['verify']['n'] ?></b><em><?= $inr0($kpi['verify']['s']) ?> waiting</em></div></a>
  <a class="stat glass clickable<?= $tab === 'received' ? ' on' : '' ?>" href="<?= e($url(['tab' => 'received', 'q' => null])) ?>"><span class="metric-icon" style="background:#e4f4e9;color:#267a55"><i data-lucide="indian-rupee"></i></span><div><small>Received today</small><b><?= $inr0($kpi['today']['s']) ?></b><em><?= $inr0($kpi['month']['s']) ?> this month · <?= $inr0($kpi['month']['w']) ?> online</em></div></a>
  <a class="stat glass clickable" href="<?= route('admin.orders') ?>?payment=due"><span class="metric-icon" style="background:#fbe1dc;color:#b3483a"><i data-lucide="hourglass"></i></span><div><small>Still due</small><b><?= $inr0($kpi['due']['s']) ?></b><em><?= (int) $kpi['due']['n'] ?> order<?= (int) $kpi['due']['n'] === 1 ? '' : 's' ?></em></div></a>
  <a class="stat glass clickable<?= $tab === 'refunds' ? ' on' : '' ?>" href="<?= e($url(['tab' => 'refunds', 'q' => null])) ?>"><span class="metric-icon" style="background:#efe7f6;color:#6f4a92"><i data-lucide="undo-2"></i></span><div><small>Refunds due</small><b><?= $inr0($kpi['refund']['s']) ?></b><em><?= (int) $kpi['refund']['n'] ?> order<?= (int) $kpi['refund']['n'] === 1 ? '' : 's' ?> · <?= (int) $kpi['failed']['n'] ?> failed attempt<?= (int) $kpi['failed']['n'] === 1 ? '' : 's' ?> (7 days)</em></div></a>
</div>

<nav class="ord-chips" aria-label="Payment lists">
  <?php foreach (PC::TABS as $k => $l): ?>
  <a class="<?= $tab === $k ? 'on' : '' ?>" href="<?= e($url(['tab' => $k, 'q' => null, 'page' => null])) ?>"><?= e($l) ?><?php if (isset($counts[$k])): ?> <b><?= $counts[$k] ?></b><?php endif; ?></a>
  <?php endforeach; ?>
</nav>

<form class="card toolbar-card glass" method="get" action="<?= route('admin.payments') ?>">
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <span class="text-xs muted"><?= number_format($total) ?> <?= $tab === 'requests' ? 'request' : 'entr' ?><?= $tab === 'requests' ? ($total === 1 ? '' : 's') : ($total === 1 ? 'y' : 'ies') ?></span>
  <?php if ($q !== ''): ?><a class="linkbtn" href="<?= e($url(['q' => null])) ?>"><i data-lucide="x"></i>Clear</a><?php endif; ?>
  <label class="search-in"><i data-lucide="search"></i><input name="q" value="<?= e($q) ?>" placeholder="<?= $tab === 'failed' ? 'Order no., ref or mobile…' : 'Order no., receipt, UTR or name…' ?>" autocomplete="off"></label>
</form>

<?php if ($tab === 'refunds' && $refundDue): ?>
<section class="card pm-duecard">
  <div class="card-h"><div><div class="eyebrow">Money to give back</div><h3>Refunds due</h3></div><span class="text-xs muted">Send the money first, then record it.</span></div>
  <ul class="pm-ledger">
    <?php foreach ($refundDue as $r): ?>
    <li><i data-lucide="undo-2"></i><div><b><?= $inr($r['refund_due']) ?></b> <span class="muted text-xs"><?= e((string) $r['customer_name']) ?></span><small><?= $orderLink($r['id'], $r['order_number']) ?> · <?= $r['order_status'] === 'rejected' ? 'declined order' : 'overpaid' ?></small></div>
      <span class="pm-ledger-acts"><button type="button" class="btn sm" data-pm-refund data-url="<?= e(route('admin.orders.refunds.store', ['id' => $r['id']])) ?>" data-number="<?= e((string) $r['order_number']) ?>" data-max="<?= e((string) $r['refund_due']) ?>" data-suggest="<?= e((string) $r['refund_due']) ?>" data-reason="<?= $r['order_status'] === 'rejected' ? 'Order declined' : 'Overpaid' ?>">Record refund</button></span></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<div class="card" style="padding:0">
  <div class="tablewrap" style="border:0;border-radius:24px">
    <table class="data ord-table">
    <?php if ($tab === 'verify'): ?>
      <thead><tr><th>Submitted</th><th>Order</th><th>Customer says</th><th>Proof</th><th class="r">Amount</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $a): ?>
        <tr>
          <td><b><?= $when($a['submitted_at'], 'j M, g:i a') ?></b><small class="block muted text-xs"><?= e((string) $a['attempt_number']) ?> · <?= e($ctx[$a['context']] ?? $a['context']) ?></small></td>
          <td><?= $orderLink($a['order_id'], $a['order_number']) ?><?php if ($a['order_id']): ?><small class="block muted text-xs">Due <?= $inr($a['due_amount']) ?> of <?= $inr($a['grand_total']) ?></small><?php endif; ?></td>
          <td><b><?= e((string) $a['payer_name']) ?></b><small class="block muted text-xs"><?= e(Phone::format((string) $a['customer_phone'])) ?></small></td>
          <td><?= $a['payer_reference'] ? '<span class="pm-utr">' . e((string) $a['payer_reference']) . '</span>' : '' ?><?= $a['proof_media_id'] ? ' <i data-lucide="image" class="ord-ch" title="Screenshot attached"></i>' : '' ?></td>
          <td class="r"><b><?= $inr($a['amount']) ?></b></td>
          <td class="r"><button type="button" class="btn gold sm" data-pm-verify="<?= (int) $a['id'] ?>">Verify</button></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6"><div class="cu-empty"><span class="metric-icon"><i data-lucide="check-check"></i></span><b>Nothing to verify</b><small>When a customer submits a UPI payment it appears here. Check your bank app, then confirm the amount you actually received.</small></div></td></tr><?php endif; ?>
      </tbody>
    <?php elseif ($tab === 'received'): ?>
      <thead><tr><th>Received</th><th>Receipt</th><th>Order</th><th>Method</th><th>Reference</th><th class="r">Amount</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $p): $void = $p['status'] === 'void'; ?>
        <tr class="<?= $void ? 'pm-void' : '' ?>">
          <td><?= $when($p['received_at'], 'j M Y, g:i a') ?></td>
          <td><b><?= e((string) $p['payment_number']) ?></b><?= $void ? '<small class="block text-xs ord-due">Voided · ' . e((string) $p['void_reason']) . '</small>' : '' ?></td>
          <td><?= $orderLink($p['order_id'], $p['order_number']) ?><small class="block muted text-xs"><?= e((string) $p['customer_name']) ?></small></td>
          <td><?= e(PS::METHODS[$p['method']] ?? $p['method']) ?><small class="block muted text-xs"><?= $p['collected_via'] === 'window' ? 'Online window' : 'At the shop' ?><?= $p['account_name'] ? ' · ' . e((string) $p['account_name']) : '' ?></small></td>
          <td class="text-xs"><?= e((string) ($p['reference'] ?: '—')) ?></td>
          <td class="r"><b><?= $inr($p['amount']) ?></b></td>
          <td class="r"><a class="iconbtn" title="Print receipt" target="_blank" rel="noopener" href="<?= route('admin.payments.receipt', ['id' => $p['id']]) ?>"><i data-lucide="printer"></i></a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7"><div class="cu-empty"><span class="metric-icon"><i data-lucide="indian-rupee"></i></span><b>No payments yet</b><small>Verified UPI payments and money recorded at the shop appear here.</small></div></td></tr><?php endif; ?>
      </tbody>
    <?php elseif ($tab === 'failed'): ?>
      <thead><tr><th>Opened</th><th>Where</th><th>Order</th><th>Customer</th><th class="r">Amount</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $a): $cart = $a['cart_snapshot'] ? json_decode((string) $a['cart_snapshot'], true) : null; ?>
        <tr>
          <td><?= $when($a['created_at'], 'j M, g:i a') ?><small class="block muted text-xs"><?= e((string) $a['attempt_number']) ?> · ref <?= e(PayWindow::shortRef((string) $a['attempt_number'])) ?></small></td>
          <td><?= e($ctx[$a['context']] ?? $a['context']) ?><small class="block muted text-xs"><?= $a['pay_choice'] === 'advance' ? '20 % advance' : ($a['pay_choice'] === 'full' ? 'Full amount' : 'Amount due') ?></small></td>
          <td><?= $orderLink($a['order_id'], $a['order_number']) ?><?= $cart ? '<small class="block muted text-xs">' . count((array) ($cart['items'] ?? [])) . ' item cart, no order created</small>' : '' ?></td>
          <td><b><?= e((string) ($a['customer_name'] ?: '—')) ?></b><small class="block muted text-xs"><?= e(Phone::format((string) $a['customer_phone'])) ?></small></td>
          <td class="r"><b><?= $inr($a['amount']) ?></b></td>
          <td class="r whitespace-nowrap"><?php if ($a['customer_phone']): ?><a class="btn sm" target="_blank" rel="noopener" href="https://wa.me/91<?= e((string) $a['customer_phone']) ?>?text=<?= rawurlencode('Namaskar, we noticed your payment of ' . \App\Services\Order\Money::inr($a['amount']) . ' to ' . business('name') . ' (ref ' . PayWindow::shortRef((string) $a['attempt_number']) . ') was not completed. If you already paid, please reply with your payment ID / UTR.') ?>"><i data-lucide="message-circle" style="width:14px"></i>Follow up</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6"><div class="cu-empty"><span class="metric-icon"><i data-lucide="timer-off"></i></span><b>No failed attempts</b><small>A payment window that times out or is closed without submitting shows here, so a customer who paid but didn’t submit can be followed up.</small></div></td></tr><?php endif; ?>
      </tbody>
    <?php elseif ($tab === 'refunds'): ?>
      <thead><tr><th>Refunded</th><th>Refund</th><th>Order</th><th>Method</th><th>Reason</th><th class="r">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $void = $r['status'] === 'void'; ?>
        <tr class="<?= $void ? 'pm-void' : '' ?>">
          <td><?= $when($r['refunded_at'], 'j M Y, g:i a') ?></td>
          <td><b><?= e((string) $r['refund_number']) ?></b><?= $void ? '<small class="block text-xs ord-due">Voided</small>' : '' ?></td>
          <td><?= $orderLink($r['order_id'], $r['order_number']) ?><small class="block muted text-xs"><?= e((string) $r['customer_name']) ?></small></td>
          <td><?= e(PS::METHODS[$r['method']] ?? $r['method']) ?><small class="block muted text-xs"><?= e((string) ($r['account_name'] ?: '')) ?><?= $r['reference'] ? ' · ' . e((string) $r['reference']) : '' ?></small></td>
          <td class="text-xs"><?= e((string) $r['reason']) ?></td>
          <td class="r"><b>−<?= $inr($r['amount']) ?></b></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6"><div class="cu-empty"><span class="metric-icon"><i data-lucide="undo-2"></i></span><b>No refunds recorded</b><small>Refunds are paid back by hand (UPI / bank / cash) and recorded on the order.</small></div></td></tr><?php endif; ?>
      </tbody>
    <?php else: ?>
      <thead><tr><th>Sent</th><th>Order</th><th>Channel</th><th>To</th><th>Status</th><th class="r">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $l): ?>
        <tr>
          <td><?= $when($l['created_at'], 'j M, g:i a') ?></td>
          <td><?= $orderLink($l['order_id'], $l['order_number']) ?><small class="block muted text-xs"><?= e((string) $l['customer_name']) ?></small></td>
          <td><?= e(PaymentRequests::CHANNELS[$l['channel']] ?? $l['channel']) ?></td>
          <td class="text-xs"><?= e(PaymentRequests::maskRecipient((string) $l['channel'], (string) $l['recipient'])) ?></td>
          <td><span class="ord-pay ord-<?= ['sent' => 'green', 'opened' => 'blue', 'failed' => 'red'][$l['status']] ?? 'grey' ?>"><?= e(['sent' => 'Sent', 'opened' => 'Opened in app', 'failed' => 'Failed'][$l['status']] ?? $l['status']) ?></span><?= $l['error'] ? '<small class="block text-xs ord-due">' . e((string) $l['error']) . '</small>' : '' ?></td>
          <td class="r"><b><?= $inr($l['amount']) ?></b></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6"><div class="cu-empty"><span class="metric-icon"><i data-lucide="send"></i></span><b>No payment requests yet</b><small>Use “Request” on an order with money due to send a pay link by WhatsApp, email or SMS.</small></div></td></tr><?php endif; ?>
      </tbody>
    <?php endif; ?>
    </table>
  </div>
</div>
<?php if ($pages > 1): ?>
<div class="pager">
  <?php if ($page > 1): ?><a class="btn" href="<?= e($url(['page' => $page - 1 > 1 ? $page - 1 : null])) ?>"><i data-lucide="chevron-left" style="width:15px"></i>Newer</a><?php endif; ?>
  <span class="text-xs muted">Page <?= $page ?> of <?= $pages ?></span>
  <?php if ($page < $pages): ?><a class="btn" href="<?= e($url(['page' => $page + 1])) ?>">Older<i data-lucide="chevron-right" style="width:15px"></i></a><?php endif; ?>
</div>
<?php endif; ?>
<?php
include __DIR__ . '/_modals.php';
include __DIR__ . '/../partials/footer.php';
