<?php
use App\Services\Order\Money;
use App\Services\WhatsApp\FlowNotifier;
use App\Services\WhatsApp\WhatsAppService as WA;

$active = 'settings.whatsapp';
$title = 'WhatsApp';
$when = static fn($v, string $fmt = 'j M, g:i a'): string => $v ? date($fmt, (int) strtotime((string) $v)) : '—';
$tone = ['sent' => 'blue', 'delivered' => 'teal', 'read' => 'green', 'failed' => 'red', 'received' => 'violet', 'opened' => 'grey', 'queued' => 'grey'];
$tplTone = ['approved' => 'green', 'pending' => 'amber', 'in_appeal' => 'amber', 'rejected' => 'red', 'paused' => 'red', 'disabled' => 'red', 'missing' => 'red', 'unknown' => 'grey'];
$tplLabel = ['approved' => '✓ Approved', 'pending' => '⏳ Pending', 'rejected' => '✗ Rejected', 'missing' => '✗ Missing in Meta', 'unknown' => 'Not checked'];
$quality = ['GREEN' => 'green', 'YELLOW' => 'amber', 'RED' => 'red'];
$url = static function (array $over = []) use ($f): string {
    $p = array_filter(array_merge($f, $over), static fn($v) => $v !== null && $v !== '');

    return route('admin.settings.whatsapp') . ($p ? '?' . http_build_query($p) : '');
};
$pages = max(1, (int) ceil($total / \App\Controllers\Admin\WhatsAppController::PER_PAGE));
$templates ??= [];
$checkData = is_array($check ?? null) && !empty($check['ok']) ? (array) $check['data'] : null;

include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs">Settings <i data-lucide="chevron-right"></i> WhatsApp</div>
    <h1>WhatsApp<span style="color:#dca71f">.</span> <span class="ord-st ord-<?= $isActive ? 'green' : 'grey' ?>" style="vertical-align:middle"><?= $isActive ? 'Active' : 'Dormant' ?></span></h1></div>
  <div class="flex gap-2 flex-wrap">
    <form method="post" action="<?= route('admin.settings.whatsapp.check') ?>"><?= csrf_field() ?><button class="btn" type="submit"<?= $isActive ? '' : ' disabled title="Fill .env first"' ?>><i data-lucide="plug-zap" style="width:16px"></i>Check connection</button></form>
    <form method="post" action="<?= route('admin.settings.whatsapp.test') ?>"><?= csrf_field() ?><button class="btn gold" type="submit"<?= $isActive && $testNumber ? '' : ' disabled' ?>><i data-lucide="send" style="width:16px"></i>Send test<?= $testNumber ? ' to ' . e(WA::mask($testNumber)) : '' ?></button></form>
  </div>
</div>

<?php if (!$isActive): ?>
<div class="ord-warnbox wa-dormant"><i data-lucide="moon"></i><div><b>WhatsApp is dormant.</b> Everything keeps working as before: payment requests and the delivery OTP open <b>wa.me</b> in your own WhatsApp, and nothing is sent automatically.
  It switches on when <code>WHATSAPP_ENABLED=true</code> and all five required values below are filled in <code>.env</code> on the server (see <code>docs/whatsapp.md</code>, "Owner activation checklist"). Credentials are never entered here.</div></div>
<?php endif; ?>

<div class="statstrip">
  <div class="stat glass"><span class="metric-icon" style="background:#e4f4e9;color:#267a55"><i data-lucide="send"></i></span><div><small>Sent (7 days)</small><b><?= $kpi['sent'] ?? 0 ?></b><em>through the Cloud API</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#fbe1dc;color:#b3483a"><i data-lucide="circle-x"></i></span><div><small>Failed (7 days)</small><b><?= $kpi['failed'] ?? 0 ?></b><em>see the log below</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#efeae0;color:#7d7462"><i data-lucide="external-link"></i></span><div><small>Opened in wa.me (7 days)</small><b><?= $kpi['fallback'] ?? 0 ?></b><em>sent from your own app</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#efe7f6;color:#6f4a92"><i data-lucide="message-circle"></i></span><div><small>Customer replies (7 days)</small><b><?= $kpi['inbound'] ?? 0 ?></b><em>reply opens wa.me</em></div></div>
</div>

<div class="grid2 wa-grid">
  <section class="card">
    <div class="card-h"><div><div class="eyebrow">Server .env</div><h3>Credentials</h3></div><span class="text-xs muted">Values are never shown</span></div>
    <ul class="wa-creds">
      <?php foreach ($creds as $key => $ok): ?>
      <li class="<?= $ok ? 'ok' : 'no' ?>"><i data-lucide="<?= $ok ? 'circle-check' : 'circle-x' ?>"></i><code><?= e($key) ?></code><span><?= $key === 'WHATSAPP_ENABLED' ? ($ok ? 'true' : 'false') : ($ok ? 'set' : 'missing') ?></span></li>
      <?php endforeach; ?>
      <li class="opt"><i data-lucide="info"></i><code>WHATSAPP_API_VERSION</code><span><?= e($apiVersion) ?></span></li>
      <li class="opt"><i data-lucide="info"></i><code>WHATSAPP_DEFAULT_LANG</code><span><?= e($lang) ?></span></li>
      <li class="opt"><i data-lucide="info"></i><code>WHATSAPP_TEST_NUMBER</code><span><?= $testNumber ? e(WA::mask($testNumber)) : 'not set' ?></span></li>
    </ul>
    <dl class="kv" style="margin-top:14px">
      <dt>Webhook URL</dt><dd><code class="wa-code"><?= e($webhookUrl) ?></code> <button class="iconbtn" type="button" data-copy="<?= e($webhookUrl) ?>" title="Copy"><i data-lucide="copy" style="width:14px"></i></button></dd>
      <dt>Subscribe to</dt><dd>messages</dd>
    </dl>
  </section>

  <section class="card">
    <div class="card-h"><div><div class="eyebrow">Meta</div><h3>Connection</h3></div><?php if ($checkData): ?><span class="ord-st ord-green">Connected</span><?php endif; ?></div>
    <?php if ($checkData): ?>
    <dl class="kv">
      <dt>Display name</dt><dd><?= e((string) ($checkData['verified_name'] ?? '—')) ?><?= !empty($checkData['name_status']) ? ' <span class="text-xs muted">(' . e(strtolower((string) $checkData['name_status'])) . ')</span>' : '' ?></dd>
      <dt>Number</dt><dd><?= e((string) ($checkData['display_phone_number'] ?? '—')) ?></dd>
      <dt>Quality</dt><dd><span class="ord-st ord-<?= $quality[strtoupper((string) ($checkData['quality_rating'] ?? ''))] ?? 'grey' ?>"><?= e(ucfirst(strtolower((string) ($checkData['quality_rating'] ?? 'unknown')))) ?></span></dd>
      <dt>Daily limit</dt><dd><?= e(str_replace('_', ' ', (string) ($checkData['messaging_limit_tier'] ?? '—'))) ?> <span class="text-xs muted">unique customers / day</span></dd>
    </dl>
    <?php else: ?>
    <p class="text-sm muted" style="margin:0"><?= $isActive ? 'Press <b>Check connection</b> to load the number’s display name, quality rating and daily limit from Meta.' : 'Available once WhatsApp is active.' ?></p>
    <?php endif; ?>
    <div class="wa-sub">
      <div class="eyebrow">Automatic messages (config/whatsapp.php)</div>
      <ul class="wa-auto">
        <?php foreach (['order_confirmed' => 'Order accepted', 'order_dispatched' => 'Dispatched', 'delivery_otp' => 'Delivery OTP', 'order_delivered' => 'Delivered', 'payment_received' => 'Payment received', 'payment_rejected' => 'Payment rejected'] as $k => $l): $on = $isActive && !empty($auto[$k]); ?>
        <li><span class="ord-st ord-<?= $on ? 'green' : 'grey' ?>"><?= $on ? 'On' : 'Off' ?></span> <?= e($l) ?></li>
        <?php endforeach; ?>
      </ul>
      <p class="text-xs muted" style="margin:6px 0 0">Payment requests send the template when you press <b>Send request</b> (WhatsApp). Returns, admin OTP and staff invites are added with those modules.</p>
    </div>
  </section>
</div>

<div class="grid2 wa-grid">
  <section class="card" style="padding:0">
    <div class="card-h" style="padding:20px 20px 0"><div><div class="eyebrow">WhatsApp Manager</div><h3>Templates</h3></div><span class="text-xs muted">Create with the same name, category and variable order</span></div>
    <div class="tablewrap" style="border:0;margin-top:12px;border-radius:0 0 24px 24px">
      <table class="data">
        <thead><tr><th>Template</th><th>Category</th><th>Variables</th><th class="r">Status</th></tr></thead>
        <tbody>
        <?php foreach ($templates as $t): $cfgT = WA::template($t['event']); ?>
          <tr><td><code><?= e($t['name']) ?></code></td><td class="text-xs"><?= e(ucfirst($t['category'])) ?></td>
            <td class="text-xs muted"><?= e(implode(', ', array_map(static fn($i, $p) => '{{' . ($i + 1) . '}} ' . str_replace('_', ' ', $p), array_keys($cfgT['params']), $cfgT['params']))) ?><?= $cfgT['button'] ? ' · ' . ($cfgT['button'] === 'copy_code' ? 'Copy code' : 'URL button') : '' ?></td>
            <td class="r"><span class="ord-st ord-<?= $tplTone[$t['status']] ?? 'grey' ?>"><?= e($tplLabel[$t['status']] ?? ucfirst($t['status'])) ?></span></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="card">
    <div class="card-h"><div><div class="eyebrow">Estimate only</div><h3>This month’s cost</h3></div><b><?= e(Money::inr($cost['total'])) ?></b></div>
    <table class="data wa-cost">
      <thead><tr><th>Category</th><th class="r">Messages</th><th class="r">Rate</th><th class="r">Estimate</th></tr></thead>
      <tbody>
      <?php foreach (['authentication' => 'Authentication (OTP)', 'utility' => 'Utility (orders, payments)', 'marketing' => 'Marketing'] as $k => $l): ?>
        <tr><td><?= e($l) ?></td><td class="r"><?= (int) ($cost['rows'][$k]['n'] ?? 0) ?></td><td class="r text-xs muted">₹<?= e(number_format((float) ($rates[$k] ?? 0), 2)) ?></td><td class="r"><?= e(Money::inr($cost['rows'][$k]['cost'] ?? 0)) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="text-xs muted" style="margin:10px 0 0">Approximate Meta rates for India. Utility messages inside a customer’s open 24-hour window are free. The real bill is in Meta Business Manager.</p>
  </section>
</div>

<section class="card" style="padding:0" id="log">
  <div class="card-h" style="padding:20px 20px 0"><div><div class="eyebrow">Every message</div><h3>Message log</h3></div><span class="text-xs muted"><?= number_format($total) ?> message<?= $total === 1 ? '' : 's' ?></span></div>
  <form class="wa-filters" method="get" action="<?= route('admin.settings.whatsapp') ?>">
    <select class="btn" name="direction" onchange="this.form.submit()"><option value="">In &amp; out</option><option value="out"<?= $f['direction'] === 'out' ? ' selected' : '' ?>>Sent by us</option><option value="in"<?= $f['direction'] === 'in' ? ' selected' : '' ?>>Customer replies</option></select>
    <select class="btn" name="status" onchange="this.form.submit()"><option value="">Any status</option><?php foreach (WA::STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <select class="btn" name="template" onchange="this.form.submit()"><option value="">Any template</option><?php foreach (array_keys((array) WA::cfg('templates', [])) as $k): ?><option value="<?= e($k) ?>"<?= $f['template'] === $k ? ' selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?></select>
    <input class="btn" type="date" name="date" value="<?= e($f['date']) ?>" onchange="this.form.submit()" aria-label="Date (IST)">
    <?php if (array_filter($f)): ?><a class="linkbtn" href="<?= route('admin.settings.whatsapp') ?>#log"><i data-lucide="x"></i>Clear</a><?php endif; ?>
  </form>
  <div class="tablewrap" style="border:0;border-radius:0 0 24px 24px">
    <table class="data">
      <thead><tr><th>Time (IST)</th><th>Message</th><th>To / from</th><th>Related</th><th class="r">Status</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="5" style="text-align:center;padding:36px" class="muted"><b>No messages yet</b><br><?= $isActive ? 'Messages appear here as orders and payments move.' : 'While dormant only wa.me hand-offs (payment requests) are logged.' ?></td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $m): $in = $m['direction'] === 'in'; ?>
        <tr>
          <td class="whitespace-nowrap"><b><?= $when($m['created_at']) ?></b><small class="block muted text-xs"><?= $in ? 'Inbound' : ($m['mode'] === 'api' ? 'Cloud API' : 'wa.me') ?></small></td>
          <td style="max-width:420px"><?php if (!$in): ?><code class="text-xs"><?= e((string) $m['template']) ?></code><?php endif; ?>
            <small class="block text-xs<?= $in ? '' : ' muted' ?> wa-preview"><?= e(mb_strimwidth((string) $m['body_preview'], 0, 160, '…')) ?></small></td>
          <td class="whitespace-nowrap"><?= e(WA::mask((string) $m['phone'])) ?></td>
          <td class="text-xs"><?php if ($m['related_type'] === 'order' && $m['related_id']): ?><a class="linkbtn" href="<?= e(route('admin.orders.show', ['id' => (int) $m['related_id']])) ?>">Order</a>
            <?php elseif ($m['related_type'] === 'customer' && $m['related_id']): ?><a class="linkbtn" href="<?= e(route('admin.customers.show', ['id' => (int) $m['related_id']])) ?>">Customer</a>
            <?php elseif ($m['related_type']): ?><span class="muted"><?= e(str_replace('_', ' ', (string) $m['related_type'])) ?></span><?php endif; ?></td>
          <td class="r whitespace-nowrap"><span class="ord-st ord-<?= $tone[$m['status']] ?? 'grey' ?>" title="<?= e((string) $m['error_text']) ?>"><?= e(FlowNotifier::tick((string) $m['status'])) ?></span>
            <?php if ($in && strlen((string) $m['phone']) === 10): ?> <a class="btn sm" target="_blank" rel="noopener" href="<?= e(WA::waMeUrl((string) $m['phone'], '')) ?>"><i data-lucide="reply" style="width:14px"></i>Reply</a><?php endif; ?>
            <?php if ($m['error_text']): ?><small class="block text-xs <?= $m['status'] === 'failed' ? 'wa-err' : 'muted' ?>"><?= e(trim(($m['error_code'] ? '#' . $m['error_code'] . ' ' : '') . $m['error_text'])) ?></small><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php if ($pages > 1): ?>
<div class="pager">
  <?php if ($page > 1): ?><a class="btn" href="<?= e($url(['page' => $page - 1 > 1 ? $page - 1 : null])) ?>#log"><i data-lucide="chevron-left" style="width:15px"></i>Previous</a><?php endif; ?>
  <span class="text-xs muted">Page <?= $page ?> of <?= $pages ?></span>
  <?php if ($page < $pages): ?><a class="btn" href="<?= e($url(['page' => $page + 1])) ?>#log">Next<i data-lucide="chevron-right" style="width:15px"></i></a><?php endif; ?>
</div>
<?php endif; ?>
<?php
$scripts = <<<'JS'
<script>
document.addEventListener('click',function(e){var b=e.target.closest('[data-copy]');if(!b)return;navigator.clipboard&&navigator.clipboard.writeText(b.dataset.copy).then(function(){msToast.success('Copied.')});});
</script>
JS;
include __DIR__ . '/../partials/footer.php';
