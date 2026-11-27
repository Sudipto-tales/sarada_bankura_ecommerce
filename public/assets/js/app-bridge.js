/* Maa Sarada Android app ↔ storefront glue (docs/mobile-app.md phase 4).
   Loaded only in app mode (<html class="is-app">). The app is a Capacitor shell around the live
   site, so every page load starts a fresh JS context: native listeners are added again on each page
   (Capacitor drops them on navigation) and nothing here assumes a long-lived app state.

   Holds: splash hide, back button, deep links, share, keyboard class, UPI "no app" message, push
   (permission after the first order, token → /app/devices, tap → order page) and update checks
   (Play In-App Updates when Play installed the app, /app/version for a sideloaded APK). */
(function () {
  'use strict';

  var C = window.Capacitor;
  if (!C || !C.isNativePlatform || !C.isNativePlatform() || !C.Plugins) return;
  var P = C.Plugins, root = document.documentElement;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var toast = function (m) { if (window.msToast) window.msToast(m); };
  var noop = function () {};
  var store = {
    get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) { /* ignore */ } },
    sget: function (k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } },
    sset: function (k, v) { try { sessionStorage.setItem(k, v); } catch (e) { /* ignore */ } }
  };
  function call(plugin, method, arg) {
    var p = P[plugin];
    if (!p || typeof p[method] !== 'function') return Promise.reject(new Error(plugin + '.' + method + ' missing'));
    try { return Promise.resolve(p[method](arg)); } catch (e) { return Promise.reject(e); }
  }
  function on(plugin, event, fn) { if (P[plugin] && P[plugin].addListener) P[plugin].addListener(event, fn); }
  /** Only same-site paths are followed from native events (push data, deep links). */
  function samePath(u) {
    try { var x = new URL(u, location.origin); return x.origin === location.origin ? x.pathname + x.search + x.hash : null; } catch (e) { return null; }
  }

  /* ---------- splash: hide on first paint, so there's no white flash ---------- */
  requestAnimationFrame(function () { call('SplashScreen', 'hide', { fadeOutDuration: 180 }).catch(noop); });

  /* ---------- back button ---------- */
  var exitArmed = 0;
  on('App', 'backButton', function () {
    if (window.msSheet && window.msSheet.close()) return;
    var pw = $('#payWindow');
    if (pw && !pw.hidden) { var x = $('[data-pw-close]', pw); if (x) x.click(); return; }
    var drawer = $('#drawer');
    if (drawer && !drawer.classList.contains('hidden')) { var dc = $('[data-close]', drawer); if (dc) { dc.click(); return; } }
    var ac = $('#appCard');
    if (ac && !ac.classList.contains('app-card-block')) { closeCard(); return; }
    if (location.pathname === '/' || location.pathname === '') {
      if (Date.now() - exitArmed < 2000) { call('App', 'exitApp').catch(noop); return; }
      exitArmed = Date.now();
      toast('Press back again to exit');
      return;
    }
    if (history.length > 1) history.back();
    else location.replace('/');
  });

  /* ---------- deep links (App Links) ---------- */
  on('App', 'appUrlOpen', function (e) {
    var path = e && samePath(e.url);
    if (path && path !== location.pathname + location.search + location.hash) location.href = path;
  });

  /* ---------- keyboard: lets CSS hide floating chrome while typing ---------- */
  on('Keyboard', 'keyboardWillShow', function () { root.classList.add('kb-open'); });
  on('Keyboard', 'keyboardWillHide', function () { root.classList.remove('kb-open'); });

  /* ---------- UPI / phone / mail links with no app to open them ---------- */
  on('MsShell', 'noApp', function (e) {
    toast(e && e.scheme === 'upi'
      ? 'No UPI app found on this phone. Copy the UPI ID and pay from any UPI app.'
      : 'No app on this phone can open that link.');
  });

  /* ---------- native share (the WebView has no navigator.share) ---------- */
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-copy-link], [data-share]');
    if (!b || !P.Share) return;
    e.preventDefault(); e.stopImmediatePropagation();
    var url = b.getAttribute('data-share') || location.href.split('#')[0];
    var h1 = $('h1');
    call('Share', 'share', { title: h1 ? h1.textContent.trim() : document.title, url: url, dialogTitle: 'Share' }).catch(noop);
  }, true);

  /* ---------- small UI card (push prompt, update notice) ---------- */
  function card(html, opts) {
    var old = $('#appCard'); if (old) old.remove();
    var d = document.createElement('div');
    d.id = 'appCard';
    d.setAttribute('role', opts && opts.block ? 'alertdialog' : 'dialog');
    d.className = 'app-card' + (opts && opts.block ? ' app-card-block' : '');
    d.innerHTML = '<div class="app-card-in">' + html + '</div>';
    document.body.appendChild(d);
    requestAnimationFrame(function () { d.classList.add('on'); });
    return d;
  }
  function closeCard() { var d = $('#appCard'); if (d) { d.classList.remove('on'); setTimeout(function () { d.remove(); }, 200); } }
  function esc(s) { var t = document.createElement('span'); t.textContent = String(s == null ? '' : s); return t.innerHTML; }

  /* ---------- push notifications ---------- */
  var pushMeta = $('meta[name="ms-push"]');     // present only for a signed-in customer
  var csrf = $('meta[name="csrf-token"]');

  // The server prints data-reg = the first 16 hex of sha256(token) this session registered. Sending
  // only when it differs re-registers the phone after a sign-out / sign-in (a sessionStorage flag
  // would survive the sign-out and leave the new login without updates) and skips it otherwise.
  function tag(token) {
    var s = window.crypto && window.crypto.subtle;
    if (!s || !window.TextEncoder) return Promise.resolve(null);
    return s.digest('SHA-256', new TextEncoder().encode(token)).then(function (b) {
      return Array.prototype.map.call(new Uint8Array(b), function (x) { return ('0' + x.toString(16)).slice(-2); }).join('').slice(0, 16);
    }).catch(function () { return null; });
  }
  function sendToken(token) {
    if (!pushMeta || !token) return;
    var reg = pushMeta.getAttribute('data-reg') || '';
    tag(token).then(function (t) {
      if (t !== null ? t === reg : (reg !== '' && store.sget('ms.pushSent') === token + '|' + reg)) return;
      var fd = new FormData(); fd.append('token', token);
      return fetch(pushMeta.content, { method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf ? csrf.content : '' } })
        .then(function (r) { if (r.ok) store.sset('ms.pushSent', token + '|' + reg); });
    }).catch(noop);
  }
  function registerPush() {
    call('PushNotifications', 'createChannel', { id: 'orders', name: 'Order updates', description: 'Order, payment and delivery updates', importance: 4, visibility: 1 })
      .catch(noop)
      .then(function () { return call('PushNotifications', 'register'); })
      .catch(noop);
  }
  if (P.PushNotifications) {
    on('PushNotifications', 'registration', function (t) { sendToken(t && t.value); });
    on('PushNotifications', 'pushNotificationActionPerformed', function (a) {
      var url = a && a.notification && a.notification.data && a.notification.data.url;
      var path = url && samePath(url);
      if (path) location.href = path;
    });
    on('PushNotifications', 'pushNotificationReceived', function (n) {
      if (n && (n.title || n.body)) toast([n.title, n.body].filter(Boolean).join(' · '));
    });
    if (pushMeta) {
      call('PushNotifications', 'checkPermissions').then(function (s) {
        if (s.receive === 'granted') { registerPush(); return; }
        // Ask after the first order (docs/mobile-app.md), never at launch: on an order page, once.
        if (s.receive.indexOf('prompt') === 0 && /^\/order\//.test(location.pathname) && !store.get('ms.pushAsked')) {
          setTimeout(askPush, 1200);
        }
      }).catch(noop);
    }
  }
  function askPush() {
    var d = card('<b class="app-card-t">Get order updates on this phone?</b>'
      + '<p>We’ll tell you when your payment is confirmed and when your stone is on its way.</p>'
      + '<div class="app-card-b"><button type="button" class="btn btn-line" data-no>Not now</button><button type="button" class="btn btn-dark" data-yes>Turn on</button></div>');
    store.set('ms.pushAsked', String(Date.now()));
    $('[data-no]', d).addEventListener('click', closeCard);
    $('[data-yes]', d).addEventListener('click', function () {
      closeCard();
      call('PushNotifications', 'requestPermissions').then(function (s) {
        if (s.receive === 'granted') { registerPush(); toast('Order updates are on'); }
      }).catch(noop);
    });
  }

  /* ---------- updates ---------- */
  // Play installs use Play's own in-app update flow (Play forbids self-updating). A sideloaded APK
  // is pointed to /app in the browser, where the customer downloads the new APK themselves.
  function versionInfo() {
    return fetch('/app/version', { credentials: 'omit', cache: 'no-store' }).then(function (r) { return r.ok ? r.json() : null; }).catch(function () { return null; });
  }
  function checkUpdates() {
    if (store.sget('ms.updChecked')) return;
    store.sset('ms.updChecked', '1');
    call('MsShell', 'info').then(function (me) {
      var code = Number(me.versionCode) || 0;
      return versionInfo().then(function (v) {
        var min = v ? Number(v.min_code) || 0 : 0;
        var mustUpdate = code > 0 && min > code;
        if (me.fromPlay) return playUpdate(mustUpdate);
        if (!v || !v.latest_code || Number(v.latest_code) <= code) return;
        sideloadUpdate(v, mustUpdate);
      });
    }).catch(noop);
  }
  function playUpdate(mustUpdate) {
    return call('AppUpdate', 'getAppUpdateInfo').then(function (i) {
      if (Number(i.updateAvailability) !== 2) return;            // UPDATE_AVAILABLE
      if ((mustUpdate || !i.flexibleUpdateAllowed) && i.immediateUpdateAllowed) return call('AppUpdate', 'performImmediateUpdate');
      if (!i.flexibleUpdateAllowed) return;
      on('AppUpdate', 'onFlexibleUpdateStateChange', function (s) {
        if (Number(s.installStatus) !== 11) return;              // DOWNLOADED
        var d = card('<b class="app-card-t">Update ready</b><p>Restart the app to use the new version.</p>'
          + '<div class="app-card-b"><button type="button" class="btn btn-line" data-no>Later</button><button type="button" class="btn btn-dark" data-yes>Restart</button></div>');
        $('[data-no]', d).addEventListener('click', closeCard);
        $('[data-yes]', d).addEventListener('click', function () { call('AppUpdate', 'completeFlexibleUpdate').catch(noop); });
      });
      return call('AppUpdate', 'startFlexibleUpdate');
    }).catch(noop);
  }
  function sideloadUpdate(v, mustUpdate) {
    if (!mustUpdate && store.get('ms.updSkip') === String(v.latest_code)) return;
    var d = card('<b class="app-card-t">' + (mustUpdate ? 'Please update the app' : 'New version ' + esc(v.name)) + '</b>'
      + '<p>' + (mustUpdate ? 'This version of the app is too old to place orders. Download the new one; your account and bag stay as they are.'
        : esc(v.notes ? String(v.notes).split('\n')[0] : 'Faster and better. Download it from our website.')) + '</p>'
      + '<div class="app-card-b">' + (mustUpdate ? '' : '<button type="button" class="btn btn-line" data-no>Later</button>')
      + '<button type="button" class="btn btn-dark" data-yes>Download</button></div>', { block: mustUpdate });
    var no = $('[data-no]', d);
    if (no) no.addEventListener('click', function () { store.set('ms.updSkip', String(v.latest_code)); closeCard(); });
    $('[data-yes]', d).addEventListener('click', function () {
      var url = location.origin + '/app';
      call('Browser', 'open', { url: url, toolbarColor: '#F6F2EA' }).catch(function () { location.href = url; });
    });
  }
  if (navigator.onLine !== false) setTimeout(checkUpdates, 2500);
})();
