/* Customer payment window (docs/payments.md §1). The server fixes the amount and the expiry;
   the countdown here is display only. One attempt per opening; closing without submitting
   marks it failed (sendBeacon), so the shop can follow up. No dependencies (QRCode optional).
   Checkout mode (data-mode="checkout", storefront): "open" posts the checkout form (data-form)
   to data-start; form errors are handed back to the page as a `paywindow:errors` event, and
   after a successful submit the page posts the token to data-done (bag → order). */
(function () {
  var root = document.getElementById('payWindow');
  if (!root) return;
  var csrf = root.dataset.csrf, startUrl = root.dataset.start, context = root.dataset.context || 'order';
  var checkout = root.dataset.mode === 'checkout', doneUrl = root.dataset.done || '';
  var att = null, timer = null, deadline = 0, submitted = false, busy = false;
  var $ = function (s) { return root.querySelector(s); };
  var $$ = function (s) { return Array.prototype.slice.call(root.querySelectorAll(s)); };
  var mobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
  // Android app (docs/mobile-app.md): a QR can't be scanned by the phone showing it, so the app
  // shows one big "Pay with UPI app" button; the app hands upi:// to a chooser of UPI apps.
  var inApp = document.documentElement.classList.contains('is-app');

  function state(name) {
    $$('[data-pw-state]').forEach(function (el) { el.hidden = el.dataset.pwState !== name; });
    $('[data-pw-timer]').hidden = name !== 'open';
  }
  function fmt(s) { s = Math.max(0, s); return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0'); }
  function post(url, body) {
    var fd = body instanceof FormData ? body : new FormData();
    fd.append('_token', csrf);
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Something went wrong (' + r.status + '). Please try again.' }; }).then(function (j) { j._status = r.status; return j; }); });
  }
  function isOpen() { return att && !submitted && att.status === 'open'; }

  function startBody() {
    if (checkout) { var form = document.querySelector(root.dataset.form); return new FormData(form); }
    var f = new FormData(); f.append('context', context); return f;
  }
  function hide() {
    clearInterval(timer);
    root.hidden = true;
    document.body.style.overflow = '';
  }

  function open() {
    root.hidden = false;
    document.body.style.overflow = 'hidden';
    state('loading');
    post(startUrl, startBody()).then(function (j) {
      if (!j.ok && checkout && j.errors) {
        hide(); att = null;
        document.dispatchEvent(new CustomEvent('paywindow:errors', { detail: j }));
        return;
      }
      if (!j.ok) { $('[data-pw-msg]').textContent = j.message || 'Payment could not be started.'; state('error'); return; }
      render(j);
    }, function () { $('[data-pw-msg]').textContent = 'You seem to be offline. Check your connection and try again.'; state('error'); });
  }

  /* Checkout: the bag becomes the order (plain form post → redirect to the order page). */
  function finish() {
    if (!checkout || !doneUrl || !att) return false;
    var f = document.createElement('form');
    f.method = 'post'; f.action = doneUrl; f.hidden = true;
    [['_token', csrf], ['token', att.token]].forEach(function (p) { var i = document.createElement('input'); i.type = 'hidden'; i.name = p[0]; i.value = p[1]; f.appendChild(i); });
    document.body.appendChild(f);
    f.submit();
    return true;
  }

  function render(a) {
    att = a; submitted = false;
    $('[data-pw-amount]').textContent = a.amount_fmt;
    $('[data-pw-ref]').textContent = a.order_number ? 'Order ' + a.order_number : 'Payment';
    $('[data-pw-refcode]').textContent = a.ref;
    var qr = $('[data-pw-qr]'); qr.innerHTML = '';
    if (a.upi) {
      $('[data-pw-vpa]').textContent = a.upi.id;
      $('[data-pw-payee]').textContent = a.upi.payee;
      qr.hidden = inApp;
      if (window.QRCode && !inApp) new QRCode(qr, { text: a.upi.uri, width: 168, height: 168, colorDark: '#111111', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });
      var app = $('[data-pw-app]'); app.href = a.upi.uri; app.hidden = !mobile;
      if (inApp) { app.textContent = 'Pay with UPI app'; app.classList.add('w-full'); }
    }
    var bank = $('[data-pw-bank]');
    bank.hidden = !a.bank;
    if (a.bank) {
      ['bank', 'holder', 'number', 'ifsc'].forEach(function (k) { $('[data-pw-b="' + k + '"]').textContent = a.bank[k] || '—'; });
      bank.open = !!a.over_upi_limit;
    }
    var wa = $('[data-pw-wa]');
    if (a.whatsapp) { wa.hidden = false; wa.href = 'https://wa.me/' + a.whatsapp + '?text=' + encodeURIComponent('Hello, I paid ' + a.amount_fmt + ' (ref ' + a.ref + ') but the payment window timed out. Payment ID: '); }
    $$('[data-err]').forEach(function (e) { e.textContent = ''; });
    $('[data-pw-error]').hidden = true;
    $('[data-pw-form]').reset();
    deadline = Date.now() + a.seconds_left * 1000;
    clearInterval(timer);
    tick(); timer = setInterval(tick, 1000);
    state(a.status === 'open' ? 'open' : (a.status === 'submitted' ? 'done' : 'expired'));
    var f = $('[name=payer_name]'); if (a.status === 'open') setTimeout(function () { f.focus(); }, 50);
  }

  function tick() {
    var left = Math.round((deadline - Date.now()) / 1000);
    var t = $('[data-pw-timer]');
    t.textContent = fmt(left);
    t.classList.toggle('low', left <= 60);
    // At 0 the window closes, unless a submit is already uploading (the server allows 30 s grace for that).
    if (left <= 0 && !busy && isOpen()) expire();
  }
  function expire() {
    clearInterval(timer);
    if (att) att.status = 'expired';
    state('expired');
  }

  $('[data-pw-form]').addEventListener('submit', function (e) {
    e.preventDefault();
    if (busy || !isOpen()) return;
    var form = e.target, btn = form.querySelector('.pw-submit');
    $$('[data-err]').forEach(function (x) { x.textContent = ''; });
    $('[data-pw-error]').hidden = true;
    if (form.payer_name.value.trim().length < 2) { $('[data-err="payer_name"]').textContent = 'Enter the name shown on your UPI / bank account.'; form.payer_name.focus(); return; }
    if (!form.payer_reference.value.trim() && !form.proof.files.length) { $('[data-err="payer_reference"]').textContent = 'Enter the payment ID / UTR, or upload a screenshot.'; return; }
    busy = true; btn.disabled = true; btn.textContent = 'Submitting…';
    post(att.submit_url, new FormData(form)).then(function (j) {
      busy = false; btn.disabled = false; btn.textContent = 'I have paid — Submit';
      if (j.ok) {
        submitted = true; clearInterval(timer);
        $('[data-pw-done-ref]').textContent = att.ref;
        var link = $('[data-pw-done-link]');
        if (j.order_url) { link.href = j.order_url; link.hidden = false; } else { link.hidden = true; }
        state('done');
        if (checkout) { link.hidden = true; setTimeout(finish, 1200); }
        return;
      }
      if (j.errors) {
        Object.keys(j.errors).forEach(function (k) { var el = $('[data-err="' + k + '"]'); if (el) el.textContent = j.errors[k][0]; });
        return;
      }
      if (j.status === 'expired') { expire(); return; }
      var er = $('[data-pw-error]'); er.textContent = j.message || 'Could not submit. Please try again.'; er.hidden = false;
    }, function () {
      busy = false; btn.disabled = false; btn.textContent = 'I have paid — Submit';
      var er = $('[data-pw-error]'); er.textContent = 'Network problem — check your connection and press Submit again. Your time is still running.'; er.hidden = false;
    });
  });

  function closeWindow() {
    if (isOpen() && !confirm('Close the payment window?\n\nIf you have already paid, press Cancel and submit your payment ID first.')) return;
    if (isOpen()) beacon();
    if (submitted && finish()) return;
    hide();
    // Checkout keeps the filled-in form (no reload); "Place order" opens a fresh window.
    if (!checkout && (submitted || (att && att.status !== 'open'))) location.reload();
    att = null;
  }
  function beacon() {
    if (!att) return;
    var fd = new FormData(); fd.append('_token', csrf);
    if (navigator.sendBeacon) navigator.sendBeacon(att.close_url, fd);
    att.status = 'expired';
  }

  $$('[data-pw-close]').forEach(function (b) { b.addEventListener('click', closeWindow); });
  $('[data-pw-retry]').addEventListener('click', open);
  $$('[data-pw-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var v = b.dataset.pwCopy === 'vpa' ? (att && att.upi ? att.upi.id : '') : (att && att.bank ? att.bank.number : '');
      (navigator.clipboard ? navigator.clipboard.writeText(v) : Promise.reject()).then(function () { b.textContent = 'Copied'; setTimeout(function () { b.textContent = 'Copy'; }, 1500); }, function () { prompt('Copy:', v); });
    });
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !root.hidden) closeWindow(); });
  window.addEventListener('beforeunload', function (e) { if (isOpen()) { e.preventDefault(); e.returnValue = ''; } });
  window.addEventListener('pagehide', function () { if (isOpen()) beacon(); });

  window.PayWindow = { open: open };
  document.querySelectorAll('[data-pay]').forEach(function (b) {
    b.hidden = false;
    b.addEventListener('click', function (e) { e.preventDefault(); open(); });
  });
})();
