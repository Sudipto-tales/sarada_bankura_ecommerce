<?php
use App\Services\Customer\Phone;
use App\Services\Order\ReturnService as RS;

$active = 'orders.returns';
$title = (string) $rq['request_number'];
include __DIR__ . '/../orders/_helpers.php';
$rid = (int) $rq['id'];
$st = (string) $rq['status'];
$isReturn = $rq['request_type'] === 'return';
$flowIdx = array_search($st, RS::FLOW, true);
$retStatus = static fn(string $s): string => '<span class="ord-st ord-' . e(RS::TONES[$s] ?? 'grey') . '">' . e(RS::STATUSES[$s] ?? ucfirst($s)) . '</span>';
$receivedAt = null;
foreach ($events as $ev) {
    if (str_contains((string) $ev['note'], ' · Goods received')) {
        $receivedAt = $ev['created_at'];
    }
}
$stepAt = ['submitted' => $rq['created_at'], 'approved' => $rq['approved_at'], 'received' => $receivedAt, 'resolved' => $st === 'resolved' ? $rq['completed_at'] : null];
$stepLabel = ['submitted' => 'Requested', 'approved' => 'Approved', 'received' => 'Goods back', 'resolved' => $isReturn ? 'Credited' : 'Replacement sent'];
$valueTotal = array_sum($values);
$remaining = max(0, round($credited - $refunded, 2));
$refundSuggest = min($remaining, (float) $o['refund_due']);
$evIcon = static function (string $t): array {
    return match (true) {
        str_starts_with($t, 'Approved') => ['check', 'blue'],
        str_starts_with($t, 'Declined') => ['x', 'red'],
        str_starts_with($t, 'Goods received') => ['package-open', 'violet'],
        str_starts_with($t, 'Completed') => ['indian-rupee', 'green'],
        str_starts_with($t, 'Replacement dispatched') => ['truck', 'green'],
        default => ['undo-2', 'amber'],
    };
};
$qtyIn = static fn(string $name, float $value, float $max, string $unit): string => '<span class="ret-qty"><input name="' . e($name) . '" inputmode="decimal" value="' . e(rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.')) . '" data-max="' . e((string) $max) . '" aria-label="Quantity"><em>' . e($unit) . '</em></span>';

include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs"><a href="<?= route('admin.returns') ?>">Returns &amp; replacements</a> <i data-lucide="chevron-right"></i> <?= e((string) $rq['request_number']) ?></div>
    <h1 class="ord-h1"><?= e((string) $rq['request_number']) ?> <?= $retStatus($st) ?></h1>
    <p class="text-sm muted" style="margin:4px 0 0"><span class="ret-type <?= $isReturn ? 'refund' : 'swap' ?>"><i data-lucide="<?= $isReturn ? 'undo-2' : 'repeat' ?>"></i><?= e(RS::TYPES[$rq['request_type']]) ?></span> · order <a class="linkbtn" href="<?= route('admin.orders.show', ['id' => $rq['order_id']]) ?>"><?= e((string) $rq['order_number']) ?></a> · <?= e((string) $rq['customer_name']) ?></p></div>
  <a class="btn" href="<?= route('admin.orders.show', ['id' => $rq['order_id']]) ?>"><i data-lucide="receipt-text" style="width:16px"></i>Open order</a>
</div>

<?php if ($st === 'rejected'): ?>
<div class="ord-rejected card"><i data-lucide="circle-x"></i><div><b>Declined <?= $when($rq['completed_at']) ?></b><p><?= nl2br(e((string) $rq['admin_note'])) ?></p></div></div>
<?php else: ?>
<ol class="ord-steps ret-steps card" aria-label="Request progress">
  <?php foreach (RS::FLOW as $i => $s): $done = $flowIdx !== false && $i <= $flowIdx; ?>
  <li class="<?= $done ? 'done' : '' ?><?= $i === $flowIdx ? ' now' : '' ?>"><span class="ord-dot"><?php if ($done): ?><i data-lucide="check"></i><?php else: ?><?= $i + 1 ?><?php endif; ?></span><b><?= e($stepLabel[$s]) ?></b><small><?= $done ? $when($stepAt[$s], 'j M, g:i a') : '' ?></small></li>
  <?php endforeach; ?>
</ol>
<?php endif; ?>

<div class="ord-detail">
  <div class="stack" style="gap:18px">
    <section class="card" style="padding:0">
      <div class="card-h" style="padding:20px 20px 0"><div><div class="eyebrow">Items</div><h3><?= count($items) ?> line<?= count($items) === 1 ? '' : 's' ?></h3></div><span class="text-xs muted">Requested <?= $when($rq['created_at']) ?></span></div>
      <div class="tablewrap" style="border:0;margin-top:12px;border-radius:0">
        <table class="data ord-items">
          <thead><tr><th>Product</th><th class="r">Delivered</th><th class="r">Asked</th><th class="r">Approved</th><th class="r">Received</th><?php if ($isReturn): ?><th class="r">Value</th><?php endif; ?></tr></thead>
          <tbody>
          <?php foreach ($items as $it): $u = (string) $it['unit']; ?>
            <tr>
              <td><b><?= e((string) $it['product_name']) ?></b><small class="block muted text-xs"><?= e(implode(' · ', array_filter([$it['variant_name'], $it['sku']]))) ?></small></td>
              <td class="r whitespace-nowrap"><?= $qtyFmt($it['quantity']) ?> <?= e($u) ?></td>
              <td class="r whitespace-nowrap"><b><?= $qtyFmt($it['requested_quantity']) ?></b> <?= e($u) ?></td>
              <td class="r whitespace-nowrap"><?= $it['approved_quantity'] !== null ? $qtyFmt($it['approved_quantity']) . ' ' . e($u) : '<span class="muted">—</span>' ?></td>
              <td class="r whitespace-nowrap"><?= $it['received_quantity'] !== null ? $qtyFmt($it['received_quantity']) . ' ' . e($u) : '<span class="muted">—</span>' ?></td>
              <?php if ($isReturn): ?><td class="r"><?= $it['refund_amount'] !== null ? '<b>' . $inr($it['refund_amount']) . '</b><small class="block muted text-xs">credited</small>' : $inr($values[(int) $it['id']] ?? 0) ?></td><?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($isReturn): ?><p class="text-xs muted" style="margin:0;padding:12px 20px 18px">Value = what the customer paid for those units (line price with its share of the order discount<?= (float) $o['tax_amount'] > 0 ? ' and GST' : '' ?>).</p><?php else: ?><div style="height:6px"></div><?php endif; ?>
    </section>

    <?php if ($st === 'submitted'): ?>
    <section class="card ret-action" id="act">
      <div class="card-h"><div><div class="eyebrow">Next step</div><h3>Accept or decline</h3></div></div>
      <form method="post" action="<?= route('admin.returns.approve', ['id' => $rid]) ?>" class="stack" id="approveForm"><?= csrf_field() ?>
        <p class="text-sm muted" style="margin:0">Approve all or part of what was asked. Once decided it can’t be undone — the request can’t be cancelled.</p>
        <div class="ret-lines">
          <?php foreach ($items as $it): ?>
          <div class="ret-line"><span><b><?= e((string) $it['product_name']) ?></b><small>Asked <?= $qtyFmt($it['requested_quantity']) ?> <?= e((string) $it['unit']) ?></small></span>
            <label class="ret-line-in">Approve <?= $qtyIn('items[' . (int) $it['id'] . ']', (float) $it['requested_quantity'], (float) $it['requested_quantity'], (string) $it['unit']) ?></label></div>
          <?php endforeach; ?>
        </div>
        <div class="field"><label>Note (internal, optional)</label><input name="admin_note" maxlength="500" placeholder="e.g. Checked photos on WhatsApp — 2 boxes cracked"></div>
        <div class="flex gap-2 flex-wrap" style="justify-content:flex-end">
          <button type="button" class="btn danger-text" data-toggle="rejectBox"><i data-lucide="x" style="width:16px"></i>Decline…</button>
          <button class="btn gold" type="submit"><i data-lucide="check" style="width:16px"></i>Approve</button>
        </div>
      </form>
      <form method="post" action="<?= route('admin.returns.reject', ['id' => $rid]) ?>" class="stack ret-reject" id="rejectBox" hidden><?= csrf_field() ?>
        <div class="field"><label>Message for the customer *</label><textarea name="note" rows="3" maxlength="900" required>Sorry, we can’t take this back. Please call us — we’ll help you find a solution.</textarea><div class="hint">Shown on their order page.</div></div>
        <div class="flex gap-2" style="justify-content:flex-end"><button type="button" class="btn" data-toggle="rejectBox">Cancel</button><button class="btn danger-text" type="submit"><i data-lucide="x" style="width:16px"></i>Decline request</button></div>
      </form>
    </section>
    <?php elseif ($st === 'approved'): ?>
    <section class="card ret-action" id="act">
      <div class="card-h"><div><div class="eyebrow">Next step</div><h3>Goods received?</h3></div></div>
      <form method="post" action="<?= route('admin.returns.receive', ['id' => $rid]) ?>" class="stack"><?= csrf_field() ?>
        <p class="text-sm muted" style="margin:0">Enter what actually came back. Ticked lines go back into stock — untick broken or unsellable pieces.</p>
        <div class="ret-lines">
          <?php foreach ($items as $it): if ((float) $it['approved_quantity'] <= 0) continue; $tracked = $stock[(int) $it['product_id']] ?? null; ?>
          <div class="ret-line"><span><b><?= e((string) $it['product_name']) ?></b><small>Approved <?= $qtyFmt($it['approved_quantity']) ?> <?= e((string) $it['unit']) ?></small></span>
            <label class="ret-line-in">Received <?= $qtyIn('items[' . (int) $it['id'] . ']', (float) $it['approved_quantity'], (float) $it['approved_quantity'], (string) $it['unit']) ?></label>
            <?php if ($tracked !== null): ?><label class="checkline ret-restock"><input type="checkbox" name="restock[<?= (int) $it['id'] ?>]" value="1" checked> Put back in stock <span class="muted">(now <?= e($tracked) ?>)</span></label>
            <?php else: ?><span class="text-xs muted ret-restock">Stock not tracked for this product</span><?php endif; ?></div>
          <?php endforeach; ?>
        </div>
        <div class="flex gap-2" style="justify-content:flex-end"><button class="btn gold" type="submit"><i data-lucide="package-open" style="width:16px"></i>Mark received</button></div>
      </form>
    </section>
    <?php elseif ($st === 'received' && $isReturn): ?>
    <section class="card ret-action" id="act">
      <div class="card-h"><div><div class="eyebrow">Next step</div><h3>Credit the returned goods</h3></div></div>
      <form method="post" action="<?= route('admin.returns.resolve', ['id' => $rid]) ?>" class="stack" id="creditForm"><?= csrf_field() ?>
        <div class="grid2">
          <div class="field"><label>Amount to credit *</label><input name="credit_amount" inputmode="decimal" value="<?= e(number_format($valueTotal, 2, '.', '')) ?>" data-max="<?= e((string) $valueTotal) ?>"><div class="hint">Value of the goods that came back: <?= $inr($valueTotal) ?>. Lower it for damage or handling.</div></div>
          <div class="field"><label>Note</label><input name="note" maxlength="300" placeholder="e.g. ₹200 off — one edge chipped"></div>
        </div>
        <div class="notice"><b>What happens:</b> the order <?= e((string) $o['order_number']) ?> owes this much less.
          <?php if ((float) $o['paid_amount'] > 0): ?>Paid so far <?= $inr($o['paid_amount']) ?> — anything paid above the new total shows as <b>refund due</b>; send it back and record it here.<?php else: ?>Nothing was paid yet, so the amount due simply drops.<?php endif; ?></div>
        <div class="flex gap-2" style="justify-content:flex-end"><button class="btn gold" type="submit"><i data-lucide="check-check" style="width:16px"></i>Complete &amp; credit</button></div>
      </form>
    </section>
    <?php elseif ($st === 'received'): ?>
    <section class="card ret-action" id="act">
      <div class="card-h"><div><div class="eyebrow">Next step</div><h3>Send the replacement</h3></div></div>
      <form method="post" action="<?= route('admin.returns.resolve', ['id' => $rid]) ?>" class="stack"><?= csrf_field() ?>
        <ul class="ret-sendlist">
          <?php foreach ($items as $it): if ((float) $it['received_quantity'] <= 0) continue; $tracked = $stock[(int) $it['product_id']] ?? null; ?>
          <li><i data-lucide="repeat"></i><span><b><?= e((string) $it['product_name']) ?> × <?= $qtyFmt($it['received_quantity']) ?> <?= e((string) $it['unit']) ?></b><small><?= $tracked !== null ? 'In stock: ' . e($tracked) . ' — this takes it out' : 'Stock not tracked' ?></small></span></li>
          <?php endforeach; ?>
        </ul>
        <div class="grid2"><div class="field"><label>Courier / vehicle</label><input name="courier_name" maxlength="120" placeholder="Own truck, WB-67 1234"></div><div class="field"><label>Tracking / LR no.</label><input name="tracking_number" maxlength="80"></div></div>
        <div class="flex gap-2" style="justify-content:flex-end"><button class="btn gold" type="submit"><i data-lucide="truck" style="width:16px"></i>Dispatch replacement</button></div>
      </form>
    </section>
    <?php endif; ?>

    <section class="card">
      <div class="card-h"><div><div class="eyebrow">Activity</div><h3>Timeline</h3></div></div>
      <ol class="ord-timeline">
        <?php foreach ($events as $ev): $p = RS::parseEvent((string) $ev['note']); $txt = $p[1] ?? (string) $ev['note']; [$ic, $tone] = $evIcon($txt); $parts = explode(' — ', $txt, 2); ?>
        <li><span class="ord-tl-ic ord-<?= e($tone) ?>"><i data-lucide="<?= e($ic) ?>"></i></span>
          <div><b><?= e($parts[0]) ?></b><small><?= $when($ev['created_at']) ?></small><?php if (isset($parts[1])): ?><p><?= e($parts[1]) ?></p><?php endif; ?></div></li>
        <?php endforeach; ?>
      </ol>
    </section>
  </div>

  <aside class="stack" style="gap:18px">
    <?php if ($isReturn && $st === 'resolved'): ?>
    <section class="card ord-paycard">
      <div class="card-h"><div><div class="eyebrow">Money</div><h3><?= $remaining > 0 && (float) $o['refund_due'] > 0 ? 'Refund to send' : 'Settled' ?></h3></div></div>
      <div class="ord-paynums">
        <div><small>Credited</small><b><?= $inr($credited) ?></b></div>
        <div><small>Refunded</small><b><?= $inr($refunded) ?></b></div>
        <div><small>Order refund due</small><b class="<?= (float) $o['refund_due'] > 0 ? 'ord-due' : '' ?>"><?= $inr($o['refund_due']) ?></b></div>
      </div>
      <?php if ($refundSuggest > 0): ?>
      <div class="pm-refundbar"><i data-lucide="undo-2"></i><span><b>Send <?= $inr($refundSuggest) ?> back</b> — then record it.</span>
        <button type="button" class="btn sm" data-pm-refund data-url="<?= e(route('admin.returns.refund', ['id' => $rid])) ?>" data-number="<?= e((string) $rq['request_number']) ?>" data-max="<?= e((string) min($remaining, (float) $o['paid_amount'])) ?>" data-suggest="<?= e((string) $refundSuggest) ?>" data-reason="Return <?= e((string) $rq['request_number']) ?>">Record refund</button></div>
      <?php elseif ($remaining > 0): ?>
      <p class="text-xs muted" style="margin:10px 0 0">The credit was set against money still due on the order — nothing to send back.</p>
      <?php endif; ?>
      <?php if ($refunds): ?>
      <ul class="pm-ledger">
        <?php foreach ($refunds as $f): $void = $f['status'] === 'void'; ?>
        <li class="refund<?= $void ? ' void' : '' ?>"><i data-lucide="undo-2"></i><div><b>−<?= $inr($f['amount']) ?></b> <span class="muted text-xs"><?= e(\App\Services\Order\PaymentService::METHODS[$f['method']] ?? $f['method']) ?><?= $f['account_name'] ? ' · ' . e((string) $f['account_name']) : '' ?></span>
          <small><?= e((string) $f['refund_number']) ?> · <?= $when($f['refunded_at'], 'j M, g:i a') ?><?= $f['reference'] ? ' · ' . e((string) $f['reference']) : '' ?><?= $void ? ' · voided' : '' ?></small></div></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="card">
      <div class="card-h"><h3>Request</h3></div>
      <dl class="kv">
        <dt>Type</dt><dd><?= e(RS::TYPES[$rq['request_type']]) ?></dd>
        <dt>Reason</dt><dd><?= e((string) $rq['reason']) ?></dd>
        <?php if ($rq['description']): ?><dt>Details</dt><dd style="font-weight:500"><?= nl2br(e((string) $rq['description'])) ?></dd><?php endif; ?>
        <?php if ($rq['admin_note'] && $st !== 'rejected'): ?><dt>Notes</dt><dd style="font-weight:500"><?= nl2br(e((string) $rq['admin_note'])) ?></dd><?php endif; ?>
      </dl>
    </section>

    <section class="card">
      <div class="card-h"><h3>Order</h3><a class="linkbtn" href="<?= route('admin.orders.show', ['id' => $rq['order_id']]) ?>">Open <i data-lucide="arrow-right"></i></a></div>
      <dl class="kv">
        <dt>Order</dt><dd><?= e((string) $o['order_number']) ?></dd>
        <dt>Delivered</dt><dd><?= $when($o['delivered_at'], 'j M Y') ?></dd>
        <dt>Total</dt><dd><?= $inr($o['grand_total']) ?></dd>
        <dt>Payment</dt><dd><?= $payBadge($o) ?></dd>
      </dl>
      <p style="margin:12px 0 0"><b><?= e((string) $rq['customer_name']) ?></b><br><span class="text-sm"><?= e(Phone::format((string) $rq['customer_phone'])) ?></span></p>
      <div class="flex gap-2" style="margin-top:12px"><a class="btn" href="tel:+91<?= e((string) $rq['customer_phone']) ?>"><i data-lucide="phone" style="width:15px"></i>Call</a>
        <a class="btn" target="_blank" rel="noopener" href="https://wa.me/91<?= rawurlencode((string) $rq['customer_phone']) ?>?text=<?= rawurlencode('Namaskar ' . $rq['customer_name'] . ', about your ' . ($isReturn ? 'return' : 'replacement') . ' request ' . $rq['request_number'] . ' for order ' . $rq['order_number'] . ': ') ?>"><i data-lucide="message-circle" style="width:15px"></i>WhatsApp</a></div>
    </section>
  </aside>
</div>
<?php
if ($isReturn && $st === 'resolved') {
    include __DIR__ . '/../payments/_modals.php';
}
$scripts = ($scripts ?? '') . <<<'JS'
<script>
(function(){
  document.querySelectorAll('[data-toggle]').forEach(function(b){b.addEventListener('click',function(){var el=document.getElementById(b.dataset.toggle);el.hidden=!el.hidden;var a=document.getElementById('approveForm');if(a)a.querySelector('.btn.gold').disabled=!el.hidden;if(!el.hidden){var t=el.querySelector('textarea');if(t)t.focus()}})});
  document.querySelectorAll('.ret-action form').forEach(function(f){f.addEventListener('submit',function(e){
    var bad=[].slice.call(f.querySelectorAll('input[data-max]')).find(function(i){var v=parseFloat(String(i.value).replace(/[,₹\s]/g,''));return isNaN(v)||v<0||v>parseFloat(i.dataset.max)+0.0005});
    if(bad){e.preventDefault();bad.focus();msToast.error('Enter 0 to '+bad.dataset.max+'.');return;}
    var b=f.querySelector('button[type=submit]');setTimeout(function(){b.disabled=true},0);
  })});
})();
</script>
JS;
include __DIR__ . '/../partials/footer.php';
