<?php
/**
 * The customer payment window (docs/payments.md) — one component for the order page, the public
 * invoice (floating button) and the /pay link page.
 *
 * Needs: $o (order row). Optional: $payContext (order|invoice|request), $payFloating (default true:
 * render the floating "Pay ₹X" button; the /pay page renders its own [data-pay] button),
 * $qrLoaded (true when the page already loaded qrcode.min.js).
 */
use App\Services\Order\Money;
use App\Services\Order\PaymentService;
use App\Services\Payment\Accounts;

$payContext ??= 'order';
$payFloating ??= true;
$payDue = PaymentService::dueNow($o);
$payOn = $payDue > 0 && Accounts::defaultUpi() !== null;
if (!$payOn) {
    return;
}
?>
<?php if ($payFloating): ?><button type="button" class="doc-btn pub-pay" data-pay>Pay ₹<?= Money::format($payDue) ?></button><?php endif; ?>
<div class="pw-back" id="payWindow" hidden
  data-start="<?= e((string) route('pay.start', ['token' => $o['public_token']])) ?>"
  data-context="<?= e($payContext) ?>" data-csrf="<?= e(csrf_token()) ?>">
  <div class="pw" role="dialog" aria-modal="true" aria-labelledby="pwTitle">
    <header class="pw-h">
      <div><div class="eyebrow" data-pw-ref>Payment</div><h2 id="pwTitle">Pay <span data-pw-amount>₹<?= Money::format($payDue) ?></span></h2></div>
      <div class="pw-timer" data-pw-timer aria-live="off">06:00</div>
      <button type="button" class="pw-x" data-pw-close aria-label="Close">×</button>
    </header>

    <div class="pw-body" data-pw-state="loading"><p class="muted">Opening a secure payment window…</p></div>

    <div class="pw-body" data-pw-state="open" hidden>
      <p class="pw-warn">⚠ Do not close or refresh this window until you submit.</p>
      <section class="pw-step">
        <h3><span>1</span> Pay</h3>
        <div class="pw-pay">
          <div class="pw-qr" data-pw-qr aria-label="UPI QR code"></div>
          <div class="pw-upi">
            <small class="muted">UPI ID</small>
            <div class="pw-copyrow"><b data-pw-vpa>—</b><button type="button" class="pw-link" data-pw-copy="vpa">Copy</button></div>
            <small class="muted" data-pw-payee></small>
            <a class="doc-btn pw-app" data-pw-app href="#" hidden>Open UPI app</a>
            <p class="pw-ref">Ref <b data-pw-refcode>—</b> — keep it in the payment note.</p>
          </div>
        </div>
        <details class="pw-bank" data-pw-bank hidden>
          <summary>Above ₹1 lakh? Bank transfer details</summary>
          <dl><dt>Bank</dt><dd data-pw-b="bank"></dd><dt>Account name</dt><dd data-pw-b="holder"></dd>
            <dt>Account no.</dt><dd><span data-pw-b="number"></span> <button type="button" class="pw-link" data-pw-copy="acct">Copy</button></dd><dt>IFSC</dt><dd data-pw-b="ifsc"></dd></dl>
          <p class="muted">Use the same Step 2 below with your IMPS / NEFT reference.</p>
        </details>
      </section>
      <form class="pw-step" data-pw-form novalidate>
        <h3><span>2</span> Confirm</h3>
        <label class="pw-f"><span>Name on your UPI / bank account *</span><input name="payer_name" autocomplete="name" maxlength="120" required><em data-err="payer_name"></em></label>
        <label class="pw-f"><span>Payment ID / UTR</span><input name="payer_reference" inputmode="text" maxlength="40" placeholder="12-digit number from your UPI app"><em data-err="payer_reference"></em></label>
        <label class="pw-f"><span>— or — Screenshot of the payment</span><input type="file" name="proof" accept="image/*,application/pdf"><em data-err="proof"></em></label>
        <button class="doc-btn pw-submit" type="submit">I have paid — Submit</button>
        <p class="pw-err" data-pw-error hidden></p>
      </form>
    </div>

    <div class="pw-body pw-center" data-pw-state="done" hidden>
      <div class="pw-ok">✓</div>
      <h3>Thank you — payment submitted</h3>
      <p class="muted">We will check it against our bank and confirm. Your reference: <b data-pw-done-ref></b>.</p>
      <a class="doc-btn" data-pw-done-link href="#">View my order</a>
    </div>

    <div class="pw-body pw-center" data-pw-state="expired" hidden>
      <div class="pw-ok bad">!</div>
      <h3>Time ran out</h3>
      <p>If you already paid, <b>don’t pay again</b> — contact us on WhatsApp with your payment ID.</p>
      <div class="pub-links" style="justify-content:center"><button type="button" class="doc-btn" data-pw-retry>Try again</button><a class="doc-btn ghost" data-pw-wa href="#" target="_blank" rel="noopener" hidden>WhatsApp us</a></div>
    </div>

    <div class="pw-body pw-center" data-pw-state="error" hidden>
      <p data-pw-msg></p>
      <button type="button" class="doc-btn ghost" data-pw-close>Close</button>
    </div>
  </div>
</div>
<?php if (!($qrLoaded ?? false)): ?><script src="<?= base_url('assets/vendor/qrcode.min.js') ?>"></script><?php endif; ?>
<script src="<?= base_url('assets/js/pay-window.js') ?>"></script>
