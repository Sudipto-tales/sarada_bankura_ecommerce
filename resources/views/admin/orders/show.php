<?php
use App\Services\Customer\Phone;
use App\Services\Order\DocumentService as Docs;
use App\Services\Order\OrderService as OS;

$active = 'orders';
$title = (string) $o['order_number'];
include __DIR__ . '/_helpers.php';
$oid = (int) $o['id'];
$st = (string) $o['order_status'];
$next = OS::next($st);
$shipping = OS::address($o['shipping_address']);
$billing = OS::address($o['billing_address']);
$taxRows = Docs::taxRows(Docs::taxMode($o), $o['gst_rate'] !== null ? (float) $o['gst_rate'] : null, (float) $o['tax_amount']);
$flowIdx = array_search($st, OS::FLOW, true);
$paidPct = (float) $o['grand_total'] > 0 ? min(100, round((float) $o['paid_amount'] / (float) $o['grand_total'] * 100)) : 0;
$phone = (string) $o['customer_phone'];
$waText = static fn(string $t): string => 'https://wa.me/91' . rawurlencode($phone) . '?text=' . rawurlencode($t);
$biz = (string) business('name');
$tr = route('admin.orders.transition', ['id' => $oid]);
$histIcon = ['pending' => 'file-plus', 'accepted' => 'check', 'processing' => 'hammer', 'packed' => 'package', 'dispatched' => 'truck', 'delivered' => 'package-check', 'rejected' => 'x'];

// Payments (Stage 5)
$dueNow = \App\Services\Order\PaymentService::dueNow($o);
$methods = \App\Services\Order\PaymentService::METHODS;
$toVerify = array_values(array_filter($attempts, static fn($a) => $a['status'] === 'submitted'));
$lastReq = $requests[0] ?? null;
$lastReqText = $lastReq ? 'Last sent: ' . (\App\Services\Payment\PaymentRequests::CHANNELS[$lastReq['channel']] ?? $lastReq['channel']) . ' · ' . \App\Services\Payment\PaymentRequests::maskRecipient((string) $lastReq['channel'], (string) $lastReq['recipient']) . ' · ' . $when($lastReq['created_at']) : '';
$reqAttrs = 'data-pm-request data-url="' . e(route('admin.orders.payment-request', ['id' => $oid])) . '" data-number="' . e((string) $o['order_number']) . '" data-due="' . e((string) $dueNow) . '"'
    . ' data-email="' . e((string) $o['customer_email']) . '" data-phone="' . e($phone) . '" data-message="' . e($requestMsg) . '" data-cooldown="' . (int) $cooldown . '" data-last="' . e($lastReqText) . '"';
// One timeline: status changes + money events.
$events = [];
foreach ($history as $h) {
    $same = $h['old_status'] === $h['new_status'];
    $events[] = ['at' => $h['created_at'], 'tone' => OS::TONES[$h['new_status']] ?? 'grey', 'icon' => $same ? 'key-round' : ($histIcon[$h['new_status']] ?? 'circle'),
        'title' => $same ? 'Updated' : (OS::STATUSES[$h['new_status']] ?? $h['new_status']), 'note' => $h['note']];
}
foreach ($payments as $p) {
    $events[] = ['at' => $p['created_at'], 'tone' => $p['status'] === 'void' ? 'grey' : 'green', 'icon' => 'indian-rupee',
        'title' => $inr($p['amount']) . ' received' . ($p['status'] === 'void' ? ' (voided)' : ''),
        'note' => $p['payment_number'] . ' · ' . ($methods[$p['method']] ?? $p['method']) . ($p['collected_via'] === 'window' ? ' via payment window' : ' at the shop') . ($p['status'] === 'void' ? ' · Voided: ' . $p['void_reason'] : '')];
}
foreach ($refunds as $r) {
    $events[] = ['at' => $r['created_at'], 'tone' => $r['status'] === 'void' ? 'grey' : 'violet', 'icon' => 'undo-2',
        'title' => $inr($r['amount']) . ' refunded' . ($r['status'] === 'void' ? ' (voided)' : ''), 'note' => $r['refund_number'] . ' · ' . $r['reason']];
}
foreach ($attempts as $a) {
    if ($a['status'] === 'expired') {
        $events[] = ['at' => $a['updated_at'], 'tone' => 'grey', 'icon' => 'timer-off', 'title' => 'Payment window closed without paying', 'note' => $a['attempt_number'] . ' · ' . $inr($a['amount'])];
    } elseif ($a['submitted_at']) {
        $events[] = ['at' => $a['submitted_at'], 'tone' => 'amber', 'icon' => 'send', 'title' => $inr($a['amount']) . ' submitted by the customer',
            'note' => $a['attempt_number'] . ' · ' . $a['payer_name'] . ($a['payer_reference'] ? ' · UTR ' . $a['payer_reference'] : ' · screenshot')];
        if ($a['status'] === 'rejected') {
            $events[] = ['at' => $a['reviewed_at'], 'tone' => 'red', 'icon' => 'ban', 'title' => 'Payment rejected', 'note' => $a['attempt_number'] . ' · ' . $a['reject_reason']];
        }
    }
}
foreach ($requests as $q) {
    $events[] = ['at' => $q['created_at'], 'tone' => $q['status'] === 'failed' ? 'red' : 'blue', 'icon' => 'send',
        'title' => $inr($q['amount']) . ' requested via ' . (\App\Services\Payment\PaymentRequests::CHANNELS[$q['channel']] ?? $q['channel']) . ($q['status'] === 'failed' ? ' — failed' : ''),
        'note' => 'To ' . \App\Services\Payment\PaymentRequests::maskRecipient((string) $q['channel'], (string) $q['recipient']) . ($q['status'] === 'opened' ? ' (sent from your app)' : '') . ($q['error'] ? ' · ' . $q['error'] : '')];
}
usort($events, static fn($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs"><a href="<?= route('admin.orders') ?>">Orders</a> <i data-lucide="chevron-right"></i> <?= e((string) $o['order_number']) ?></div>
    <h1 class="ord-h1"><?= e((string) $o['order_number']) ?> <?= $ordStatus($st) ?></h1></div>
  <div class="flex gap-2 flex-wrap">
    <?php if ($st === 'pending'): ?><button class="btn danger-text" type="button" data-dialog="rejected"><i data-lucide="x" style="width:16px"></i>Reject</button><?php endif; ?>
    <?php if ($next && $next !== 'rejected'): ?>
      <?php if (in_array($next, OS::NEEDS_DIALOG, true) || $next === 'accepted'): ?>
      <button class="btn gold" type="button" data-dialog="<?= e($next) ?>"><?= e(OS::ACTIONS[$next]) ?><i data-lucide="arrow-right" style="width:16px"></i></button>
      <?php else: ?>
      <form method="post" action="<?= e($tr) ?>"><?= csrf_field() ?><input type="hidden" name="to" value="<?= e($next) ?>"><button class="btn gold" type="submit"><?= e(OS::ACTIONS[$next]) ?><i data-lucide="arrow-right" style="width:16px"></i></button></form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($st !== 'rejected'): ?>
<ol class="ord-steps card" aria-label="Order progress">
  <?php foreach (OS::FLOW as $i => $s): $done = $flowIdx !== false && $i <= $flowIdx; ?>
  <li class="<?= $done ? 'done' : '' ?><?= $i === $flowIdx ? ' now' : '' ?>"><span class="ord-dot"><?php if ($done): ?><i data-lucide="check"></i><?php else: ?><?= $i + 1 ?><?php endif; ?></span><b><?= e(OS::STATUSES[$s]) ?></b>
    <small><?= $s === 'pending' ? $when($o['created_at'], 'j M, g:i a') : ($done ? $when($o[$s . '_at'] ?? null, 'j M, g:i a') : '') ?></small></li>
  <?php endforeach; ?>
</ol>
<?php else: ?>
<div class="ord-rejected card"><i data-lucide="circle-x"></i><div><b>Order declined <?= $when($o['rejected_at']) ?></b>
  <p>Customer sees: “<?= e((string) $o['customer_note']) ?>”<?= $o['reject_reason'] ? ' · Internal reason: ' . e((string) $o['reject_reason']) : '' ?></p>
  <?php if ((float) $o['refund_due'] > 0): ?><p class="ord-due"><?= $inr($o['refund_due']) ?> was paid and must be refunded manually.</p><?php endif; ?></div></div>
<?php endif; ?>

<?php if ($otp): ?>
<section class="card ord-otp" role="status">
  <div><div class="eyebrow">Delivery OTP — shown only now</div>
    <div class="ord-otp-code" id="otpCode"><?= e(implode(' ', str_split((string) $otp, 3))) ?></div>
    <p class="text-xs muted" style="margin:4px 0 0">Send it to the customer. They give it to the delivery person on arrival. Valid <?= (int) business('delivery.otp_days') ?> days.</p></div>
  <div class="flex gap-2 flex-wrap">
    <a class="btn gold" target="_blank" rel="noopener" href="<?= e($waText('Namaskar ' . $o['customer_name'] . ', your order ' . $o['order_number'] . ' from ' . $biz . ' is on the way. Delivery OTP: ' . $otp . ' — share it with our delivery person only after you receive the material. Track: ' . $orderUrl)) ?>"><i data-lucide="message-circle" style="width:16px"></i>Send on WhatsApp</a>
    <button class="btn" type="button" data-copy="<?= e((string) $otp) ?>"><i data-lucide="copy" style="width:16px"></i>Copy</button>
  </div>
</section>
<?php endif; ?>

<?php if ($warnings): ?>
<div class="ord-warnbox"><i data-lucide="triangle-alert"></i><div><b>Stock is short for this order</b> — you can still accept it; stock is only taken out at dispatch.<ul><?php foreach ($warnings as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<div class="ord-detail">
  <div class="stack" style="gap:18px">
    <section class="card" style="padding:0">
      <div class="card-h" style="padding:20px 20px 0"><div><div class="eyebrow">Items</div><h3><?= count($items) ?> line<?= count($items) === 1 ? '' : 's' ?></h3></div><span class="text-xs muted">Placed <?= $when($o['created_at']) ?> · <?= e(OS::CHANNEL_DETAILS[$o['channel_detail']] ?? '') ?></span></div>
      <div class="tablewrap" style="border:0;margin-top:12px;border-radius:0">
        <table class="data ord-items">
          <thead><tr><th>Product</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">Discount</th><th class="r">Amount</th></tr></thead>
          <tbody>
          <?php foreach ($items as $it): ?>
            <tr>
              <td><a class="flex items-center gap-3" href="<?= route('admin.products.show', ['id' => $it['product_id']]) ?>"><span class="ord-thumb"><?php if ($it['cover']): ?><img src="<?= e(base_url((string) $it['cover'])) ?>" alt=""><?php else: ?><i data-lucide="gem"></i><?php endif; ?></span>
                <span><b><?= e((string) $it['product_name']) ?></b><small class="block muted text-xs"><?= e(implode(' · ', array_filter([$it['variant_name'], $it['sku']]))) ?></small></span></a></td>
              <td class="r whitespace-nowrap"><?= $qtyFmt($it['quantity']) ?> <?= e((string) $it['unit']) ?></td>
              <td class="r"><?= $inr($it['unit_price']) ?></td>
              <td class="r"><?= (float) $it['discount_amount'] > 0 ? '−' . $inr($it['discount_amount']) : '<span class="muted">—</span>' ?></td>
              <td class="r"><b><?= $inr($it['line_total']) ?></b></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="ord-totals">
        <div><span>Items total</span><b><?= $inr($o['subtotal']) ?></b></div>
        <?php if ((float) $o['discount_amount'] > 0): ?><div><span>Order discount</span><b>−<?= $inr($o['discount_amount']) ?></b></div><?php endif; ?>
        <?php foreach ($taxRows as $t): ?><div><span><?= e($t['label']) ?></span><b><?= $inr($t['amount']) ?></b></div><?php endforeach; ?>
        <?php if (!$taxRows): ?><div><span>GST</span><b class="muted">Not charged</b></div><?php endif; ?>
        <div class="grand"><span>Total</span><b><?= $inr($o['grand_total']) ?></b></div>
        <p class="text-xs muted">Delivery included · <?= e(\App\Services\Order\Money::words((float) $o['grand_total'])) ?></p>
      </div>
    </section>

    <section class="card">
      <div class="card-h"><div><div class="eyebrow">Ship &amp; bill</div><h3>Addresses</h3></div><span class="text-xs muted">Copied at order time — later edits to the customer don’t change it</span></div>
      <div class="cu-addr-grid">
        <article class="cu-addr-card"><div class="cu-addr-card-h"><b>Delivery</b></div>
          <?php if ($shipping): ?><p><b><?= e((string) $shipping['name']) ?></b> · <?= e(Phone::format((string) $shipping['phone'])) ?><br><?= implode('<br>', array_map('e', OS::addressLines($shipping))) ?></p>
          <?php else: ?><p class="muted">Customer collects from the showroom.</p><?php endif; ?></article>
        <article class="cu-addr-card"><div class="cu-addr-card-h"><b>Billing</b></div>
          <?php if ($billing): ?><p><b><?= e((string) $billing['name']) ?></b><br><?= implode('<br>', array_map('e', OS::addressLines($billing))) ?></p>
          <?php else: ?><p class="muted"><?= e((string) $o['customer_name']) ?> · <?= e(Phone::format($phone)) ?></p><?php endif; ?>
          <small class="muted">Place of supply: <?= e((string) $o['place_of_supply']) ?></small></article>
      </div>
    </section>

    <?php if ($delivery): ?>
    <section class="card">
      <div class="card-h"><div><div class="eyebrow">Delivery</div><h3><?= $delivery['delivery_date'] ? 'Delivered ' . $when($delivery['delivery_date']) : 'Out for delivery' ?></h3></div>
        <?php if ($st === 'dispatched'): ?><form method="post" action="<?= route('admin.orders.otp', ['id' => $oid]) ?>"><?= csrf_field() ?><button class="btn" type="submit"><i data-lucide="key-round" style="width:16px"></i>New OTP</button></form><?php endif; ?></div>
      <dl class="kv">
        <dt>Dispatched</dt><dd><?= $when($delivery['dispatch_date']) ?></dd>
        <dt>Courier</dt><dd><?= e((string) ($delivery['courier_name'] ?: '—')) ?></dd>
        <dt>Tracking</dt><dd><?php if ($delivery['tracking_url']): ?><a class="linkbtn" target="_blank" rel="noopener" href="<?= e((string) $delivery['tracking_url']) ?>"><?= e((string) ($delivery['tracking_number'] ?: 'Open tracking')) ?> <i data-lucide="external-link"></i></a><?php else: ?><?= e((string) ($delivery['tracking_number'] ?: '—')) ?><?php endif; ?></dd>
        <dt>OTP</dt><dd><?= $delivery['otp_verified_at'] ? 'Verified ' . $when($delivery['otp_verified_at']) : ($delivery['delivery_date'] ? 'Confirmed manually' : 'Sent ' . $when($delivery['otp_sent_at']) . ' · ' . (int) $delivery['otp_attempts'] . ' wrong tr' . ((int) $delivery['otp_attempts'] === 1 ? 'y' : 'ies')) ?></dd>
        <?php if ($delivery['delivery_note']): ?><dt>Note</dt><dd><?= e((string) $delivery['delivery_note']) ?></dd><?php endif; ?>
      </dl>
    </section>
    <?php endif; ?>

    <section class="card">
      <div class="card-h"><div><div class="eyebrow">Activity</div><h3>Timeline</h3></div></div>
      <ol class="ord-timeline">
        <?php foreach ($events as $ev): ?>
        <li><span class="ord-tl-ic ord-<?= e($ev['tone']) ?>"><i data-lucide="<?= e($ev['icon']) ?>"></i></span>
          <div><b><?= e((string) $ev['title']) ?></b><small><?= $when($ev['at']) ?></small><?php if ($ev['note']): ?><p><?= e((string) $ev['note']) ?></p><?php endif; ?></div></li>
        <?php endforeach; ?>
      </ol>
    </section>
  </div>

  <aside class="stack" style="gap:18px">
    <section class="card ord-paycard" id="payment">
      <div class="card-h"><div><div class="eyebrow">Payment</div><h3><?= $payBadge($o) ?></h3></div>
        <?php if ($payments): ?><a class="linkbtn" href="<?= route('admin.payments') ?>?tab=received&amp;q=<?= rawurlencode((string) $o['order_number']) ?>">All <i data-lucide="arrow-right"></i></a><?php endif; ?></div>
      <div class="ord-paybar"><i style="width:<?= $paidPct ?>%"></i><?php if ((float) $o['pending_verification_amount'] > 0 && (float) $o['grand_total'] > 0): ?><i class="pending" style="width:<?= min(100 - $paidPct, round((float) $o['pending_verification_amount'] / (float) $o['grand_total'] * 100)) ?>%"></i><?php endif; ?></div>
      <div class="ord-paynums">
        <div><small>Total</small><b><?= $inr($o['grand_total']) ?></b></div>
        <div><small>Paid</small><b><?= $inr($o['paid_amount']) ?></b></div>
        <?php if ((float) $o['pending_verification_amount'] > 0): ?><div><small>To verify</small><b class="pm-amber"><?= $inr($o['pending_verification_amount']) ?></b></div><?php endif; ?>
        <div><small>Due</small><b class="<?= (float) $o['due_amount'] > 0 ? 'ord-due' : '' ?>"><?= $inr($o['due_amount']) ?></b></div>
      </div>
      <?php if ((float) $o['refund_due'] > 0): ?><div class="pm-refundbar"><i data-lucide="undo-2"></i><span><b>Refund <?= $inr($o['refund_due']) ?> due</b><?= $st === 'rejected' ? ' — the order was declined' : ' — paid more than the total' ?></span>
        <button type="button" class="btn sm" data-pm-refund data-url="<?= e(route('admin.orders.refunds.store', ['id' => $oid])) ?>" data-number="<?= e((string) $o['order_number']) ?>" data-max="<?= e((string) $o['paid_amount']) ?>" data-suggest="<?= e((string) $o['refund_due']) ?>" data-reason="<?= $st === 'rejected' ? 'Order declined' : 'Overpaid' ?>">Record refund</button></div><?php endif; ?>

      <?php foreach ($toVerify as $a): ?>
      <div class="pm-verifyrow"><span class="pm-dot"></span><div><b><?= $inr($a['amount']) ?> to verify</b><small><?= e((string) $a['payer_name']) ?> · <?= $a['payer_reference'] ? 'UTR ' . e((string) $a['payer_reference']) : 'screenshot' ?> · <?= $when($a['submitted_at'], 'j M, g:i a') ?></small></div>
        <button type="button" class="btn gold sm" data-pm-verify="<?= (int) $a['id'] ?>">Verify</button></div>
      <?php endforeach; ?>

      <div class="pm-actions">
        <?php if ($st !== 'rejected'): ?><button type="button" class="btn sm" data-pm-record data-url="<?= e(route('admin.orders.payments.store', ['id' => $oid])) ?>" data-number="<?= e((string) $o['order_number']) ?>" data-due="<?= e((string) $o['due_amount']) ?>"><i data-lucide="plus" style="width:14px"></i>Record payment</button><?php endif; ?>
        <?php if ($dueNow > 0): ?><button type="button" class="btn sm" <?= $reqAttrs ?>><i data-lucide="send" style="width:14px"></i>Request <?= $inr0($dueNow) ?></button><?php endif; ?>
        <?php if ((float) $o['paid_amount'] > 0 && (float) $o['refund_due'] <= 0): ?><button type="button" class="btn sm" data-pm-refund data-url="<?= e(route('admin.orders.refunds.store', ['id' => $oid])) ?>" data-number="<?= e((string) $o['order_number']) ?>" data-max="<?= e((string) $o['paid_amount']) ?>" data-suggest="0"><i data-lucide="undo-2" style="width:14px"></i>Refund</button><?php endif; ?>
      </div>
      <?php if ($dueNow > 0 && !$hasUpi): ?><p class="text-xs muted" style="margin:10px 0 0"><i data-lucide="info" style="width:13px;vertical-align:-2px"></i> Customers can’t pay online until a UPI ID is added under <a class="linkbtn" href="<?= route('admin.payments.accounts') ?>">Receiving accounts</a>.</p><?php endif; ?>

      <?php if ($payments || $refunds): ?>
      <ul class="pm-ledger">
        <?php foreach ($payments as $p): $void = $p['status'] === 'void'; ?>
        <li class="<?= $void ? 'void' : '' ?>"><i data-lucide="<?= $p['collected_via'] === 'window' ? 'smartphone' : 'store' ?>"></i>
          <div><b><?= $inr($p['amount']) ?></b> <span class="muted text-xs"><?= e($methods[$p['method']] ?? $p['method']) ?><?= $p['account_name'] ? ' · ' . e((string) $p['account_name']) : '' ?></span>
            <small><?= e((string) $p['payment_number']) ?> · <?= $when($p['received_at'], 'j M, g:i a') ?><?= $p['reference'] ? ' · ' . e((string) $p['reference']) : '' ?><?= $void ? ' · voided: ' . e((string) $p['void_reason']) : '' ?></small></div>
          <span class="pm-ledger-acts"><a class="iconbtn" title="Print receipt" target="_blank" rel="noopener" href="<?= route('admin.payments.receipt', ['id' => $p['id']]) ?>"><i data-lucide="printer"></i></a>
            <?php if (!$void): ?><button type="button" class="iconbtn" title="Void (mistake)" data-pm-void data-url="<?= e(route('admin.payments.void', ['id' => $p['id']])) ?>" data-title="Void <?= e((string) $p['payment_number']) ?>"><i data-lucide="ban"></i></button><?php endif; ?></span></li>
        <?php endforeach; ?>
        <?php foreach ($refunds as $r): $void = $r['status'] === 'void'; ?>
        <li class="refund<?= $void ? ' void' : '' ?>"><i data-lucide="undo-2"></i>
          <div><b>−<?= $inr($r['amount']) ?></b> <span class="muted text-xs">refund · <?= e($methods[$r['method']] ?? $r['method']) ?></span>
            <small><?= e((string) $r['refund_number']) ?> · <?= $when($r['refunded_at'], 'j M, g:i a') ?> · <?= e((string) $r['reason']) ?><?= $void ? ' · voided' : '' ?></small></div>
          <span class="pm-ledger-acts"><?php if (!$void): ?><button type="button" class="iconbtn" title="Void (mistake)" data-pm-void data-url="<?= e(route('admin.payments.refunds.void', ['id' => $r['id']])) ?>" data-title="Void <?= e((string) $r['refund_number']) ?>"><i data-lucide="ban"></i></button><?php endif; ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <p class="text-xs muted" style="margin:12px 0 0">Payment never moves the order status — dispatch is allowed with money due.</p>
    </section>

    <section class="card">
      <div class="card-h"><h3>Customer</h3><?php if ($customer): ?><a class="linkbtn" href="<?= route('admin.customers.show', ['id' => $customer['id']]) ?>">Profile <i data-lucide="arrow-right"></i></a><?php endif; ?></div>
      <p style="margin:0"><b><?= e((string) $o['customer_name']) ?></b><br><span class="text-sm"><?= e(Phone::format($phone)) ?></span><?= $o['customer_email'] ? '<br><span class="text-sm muted">' . e((string) $o['customer_email']) . '</span>' : '' ?></p>
      <?php if ($customer): ?><p class="text-xs muted" style="margin:8px 0 0"><?= (int) $customer['total_orders'] ?> delivered order<?= (int) $customer['total_orders'] === 1 ? '' : 's' ?></p><?php endif; ?>
      <div class="flex gap-2" style="margin-top:12px"><a class="btn" href="tel:+91<?= e($phone) ?>"><i data-lucide="phone" style="width:15px"></i>Call</a><a class="btn" target="_blank" rel="noopener" href="<?= e($waText('Namaskar ' . $o['customer_name'] . ', here is your order ' . $o['order_number'] . ' from ' . $biz . ': ' . $orderUrl)) ?>"><i data-lucide="message-circle" style="width:15px"></i>Send order link</a></div>
    </section>

    <section class="card">
      <div class="card-h"><h3>Documents</h3></div>
      <div class="ord-docs">
        <?php if ($slip): ?><a href="<?= route('admin.orders.slip', ['id' => $oid]) ?>" target="_blank" rel="noopener"><i data-lucide="package"></i><span><b>Packing slip</b><small>No prices · <?= $when($slip['created_at'], 'j M') ?></small></span><i data-lucide="printer"></i></a>
        <?php else: ?><span class="off"><i data-lucide="package"></i><span><b>Packing slip</b><small>Created when packed</small></span></span><?php endif; ?>
        <?php if ($invoice): ?><a href="<?= route('admin.orders.invoice', ['id' => $oid]) ?>" target="_blank" rel="noopener"><i data-lucide="file-text"></i><span><b><?= e((string) $invoice['title']) ?></b><small><?= e((string) $invoice['invoice_number']) ?></small></span><i data-lucide="printer"></i></a>
        <?php else: ?><span class="off"><i data-lucide="file-text"></i><span><b>Invoice</b><small>Issued at dispatch</small></span></span><?php endif; ?>
        <a href="<?= e($orderUrl) ?>" target="_blank" rel="noopener"><i data-lucide="globe"></i><span><b>Customer order page</b><small>Status, items, amount due</small></span><i data-lucide="external-link"></i></a>
      </div>
      <button type="button" class="linkbtn" data-copy="<?= e($orderUrl) ?>" style="margin-top:10px"><i data-lucide="link"></i>Copy order link</button>
    </section>

    <?php if ($o['notes'] || $o['internal_note']): ?>
    <section class="card">
      <div class="card-h"><h3>Notes</h3></div>
      <?php if ($o['notes']): ?><p class="text-sm" style="margin:0 0 8px"><span class="eyebrow" style="display:block">On invoice</span><?= nl2br(e((string) $o['notes'])) ?></p><?php endif; ?>
      <?php if ($o['internal_note']): ?><p class="text-sm" style="margin:0"><span class="eyebrow" style="display:block">Internal</span><?= nl2br(e((string) $o['internal_note'])) ?></p><?php endif; ?>
    </section>
    <?php endif; ?>
  </aside>
</div>

<!-- Dialogs: only for steps that need details -->
<div class="modal-backdrop" id="dlg-accepted"><form class="modal" method="post" action="<?= e($tr) ?>"><?= csrf_field() ?><input type="hidden" name="to" value="accepted">
  <div class="modal-h"><div><div class="eyebrow"><?= e((string) $o['order_number']) ?></div><h3>Accept this order?</h3></div><button type="button" class="iconbtn" data-close aria-label="Close"><i data-lucide="x"></i></button></div>
  <?php if ($warnings): ?><div class="ord-warnbox sm"><i data-lucide="triangle-alert"></i><div><b>Stock is short</b><ul><?php foreach ($warnings as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul><small>You can still accept — orders never reserve stock.</small></div></div><?php endif; ?>
  <p class="text-sm muted">Once accepted the order can’t be cancelled — it moves on to processing, packing and dispatch.</p>
  <div class="field"><label>Note (optional)</label><input name="note" maxlength="500" placeholder="e.g. Confirmed on phone with Rina"></div>
  <div class="modal-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn gold" type="submit"><i data-lucide="check" style="width:16px"></i>Accept order</button></div>
</form></div>

<div class="modal-backdrop" id="dlg-rejected"><form class="modal" method="post" action="<?= e($tr) ?>"><?= csrf_field() ?><input type="hidden" name="to" value="rejected">
  <div class="modal-h"><div><div class="eyebrow"><?= e((string) $o['order_number']) ?></div><h3>Decline this order</h3></div><button type="button" class="iconbtn" data-close aria-label="Close"><i data-lucide="x"></i></button></div>
  <div class="stack">
    <div class="field"><label>Message for the customer *</label><textarea name="customer_note" rows="3" maxlength="1000" required>Sorry, we can’t take this order right now. Please call us and we’ll suggest an alternative.</textarea><div class="hint">Shown on their order page.</div></div>
    <div class="field"><label>Internal reason</label><input name="reject_reason" maxlength="255" placeholder="e.g. Lot finished, price changed"></div>
    <?php if ((float) $o['paid_amount'] > 0): ?><p class="ord-due text-sm"><?= $inr($o['paid_amount']) ?> was paid — refund it manually.</p><?php endif; ?>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn danger-text" type="submit"><i data-lucide="x" style="width:16px"></i>Decline order</button></div>
</form></div>

<div class="modal-backdrop" id="dlg-dispatched"><form class="modal" method="post" action="<?= e($tr) ?>"><?= csrf_field() ?><input type="hidden" name="to" value="dispatched">
  <div class="modal-h"><div><div class="eyebrow"><?= e((string) $o['order_number']) ?></div><h3>Dispatch</h3></div><button type="button" class="iconbtn" data-close aria-label="Close"><i data-lucide="x"></i></button></div>
  <div class="stack">
    <div class="grid2"><div class="field"><label>Courier / vehicle</label><input name="courier_name" maxlength="120" placeholder="Own truck, WB-67 1234"></div><div class="field"><label>Tracking / LR no.</label><input name="tracking_number" maxlength="80"></div></div>
    <div class="field"><label>Tracking link</label><input name="tracking_url" type="url" maxlength="255" placeholder="https://…"></div>
    <div class="notice"><b>What happens:</b> the <?= e(Docs::title($o)) ?> is issued with the next number, tracked stock is taken out, and a delivery OTP is created for you to send.
      <?php if ((float) $o['due_amount'] > 0): ?><br><span class="ord-due"><?= $inr($o['due_amount']) ?> is still due</span> — dispatch is allowed; collect it as agreed.<?php endif; ?></div>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn gold" type="submit"><i data-lucide="truck" style="width:16px"></i>Dispatch &amp; issue invoice</button></div>
</form></div>

<div class="modal-backdrop" id="dlg-delivered"><form class="modal" method="post" action="<?= e($tr) ?>"><?= csrf_field() ?><input type="hidden" name="to" value="delivered">
  <div class="modal-h"><div><div class="eyebrow"><?= e((string) $o['order_number']) ?></div><h3>Confirm delivery</h3></div><button type="button" class="iconbtn" data-close aria-label="Close"><i data-lucide="x"></i></button></div>
  <div class="stack">
    <div class="field" id="otpField"><label>Delivery OTP from the customer</label><input name="otp" inputmode="numeric" maxlength="7" autocomplete="one-time-code" placeholder="6 digits" class="ord-otp-in"></div>
    <label class="checkline"><input type="checkbox" name="manual" value="1" id="manualToggle"> The customer can’t give the OTP — confirm manually</label>
    <div class="field" id="manualField" hidden><label>How was delivery confirmed? *</label><input name="manual_reason" maxlength="255" placeholder="e.g. Collected in person by Rina, ID checked"></div>
    <div class="field"><label>Delivery note</label><input name="delivery_note" maxlength="500" placeholder="Optional — e.g. 2 boxes chipped, replaced on site"></div>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn gold" type="submit"><i data-lucide="package-check" style="width:16px"></i>Mark delivered</button></div>
</form></div>
<?php
include __DIR__ . '/../payments/_modals.php';
$scripts .= '<script>window.ORD_DO=' . json_encode($do) . ';</script>' . <<<'JS'
<script>
(function(){
  function open(id){var m=document.getElementById('dlg-'+id);if(!m)return;m.classList.add('show');var f=m.querySelector('input:not([type=hidden]),textarea');if(f)setTimeout(function(){f.focus()},30);}
  document.querySelectorAll('[data-dialog]').forEach(function(b){b.addEventListener('click',function(){open(b.dataset.dialog)})});
  document.querySelectorAll('.modal-backdrop:not(.pm-modal)').forEach(function(m){
    m.querySelectorAll('[data-close]').forEach(function(b){b.addEventListener('click',function(){m.classList.remove('show')})});
    m.addEventListener('click',function(e){if(e.target===m)m.classList.remove('show')});
    m.querySelector('form').addEventListener('submit',function(){var b=m.querySelector('button[type=submit]');setTimeout(function(){b.disabled=true},0)});
  });
  document.addEventListener('keydown',function(e){if(e.key==='Escape')document.querySelectorAll('.modal-backdrop.show').forEach(function(m){m.classList.remove('show')})});
  var mt=document.getElementById('manualToggle');
  if(mt)mt.addEventListener('change',function(){document.getElementById('manualField').hidden=!mt.checked;document.getElementById('otpField').hidden=mt.checked;});
  document.querySelectorAll('[data-copy]').forEach(function(b){b.addEventListener('click',function(){
    (navigator.clipboard?navigator.clipboard.writeText(b.dataset.copy):Promise.reject()).then(function(){msToast('Copied')},function(){prompt('Copy:',b.dataset.copy)});
  })});
  if(window.ORD_DO)open(window.ORD_DO);
})();
</script>
JS;
include __DIR__ . '/../partials/footer.php';
