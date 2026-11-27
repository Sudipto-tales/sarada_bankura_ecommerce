/* Maa Sarada storefront — small progressive enhancements (docs/storefront.md §2).
   Every page works without this file: links and forms do the real work.
   Holds: icons, mobile drawer, toasts, saved hearts (guest list in localStorage until S5),
   the home page's slider / finder / collection tabs / spotlight calculator (S1), and the shop
   filters + product page variant picker, gallery and totals (S2). */
(function () {
  'use strict';

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function icons() { if (window.lucide) window.lucide.createIcons(); }

  function inr(n, paise) {
    var v = Number(n) || 0;
    var whole = Math.abs(v - Math.round(v)) < 0.005;
    return '₹' + v.toLocaleString('en-IN', { minimumFractionDigits: paise && !whole ? 2 : 0, maximumFractionDigits: paise && !whole ? 2 : 0 });
  }

  /* ---------- toast ---------- */
  function toast(msg) {
    var t = $('#toast'); if (!t || !msg) return;
    t.textContent = msg; t.classList.add('on');
    clearTimeout(t._h); t._h = setTimeout(function () { t.classList.remove('on'); }, 2600);
  }
  window.msToast = toast;

  /* ---------- storage (wrapped: private mode / blocked storage must not break the page) ---------- */
  var store = {
    get: function (k, d) { try { var v = JSON.parse(localStorage.getItem(k)); return v == null ? d : v; } catch (e) { return d; } },
    set: function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* ignore */ } }
  };

  /* ---------- saved: guest list in localStorage; signed in = the account's list (S5) ----------
     #store-account = {saved: [slugs], url} when signed in, else null. Signed in, the browser list
     mirrors the account and every tap is sent to /saved/toggle. On a browser that was signed in and
     now isn't (signed out), the mirrored list is cleared so the next person doesn't see it. */
  var acct = null; try { acct = JSON.parse(($('#store-account') || {}).textContent || 'null'); } catch (e) { acct = null; }
  if (acct && Array.isArray(acct.saved)) { store.set('ms.saved', acct.saved); store.set('ms.acct', 1); }
  else if (store.get('ms.acct', 0)) { store.set('ms.saved', []); store.set('ms.acct', 0); }
  var saved = {
    all: function () { var a = store.get('ms.saved', []); return Array.isArray(a) ? a : []; },
    has: function (s) { return saved.all().indexOf(s) >= 0; },
    toggle: function (s) {
      var a = saved.all(), i = a.indexOf(s);
      if (i < 0) a.push(s); else a.splice(i, 1);
      store.set('ms.saved', a);
      if (acct) syncSaved(s, i < 0);
      return i < 0;
    }
  };
  function syncSaved(slug, on) {
    if (!window.fetch || !window.FormData) return;
    var meta = $('meta[name=csrf-token]'), fd = new FormData();
    fd.append('slug', slug); fd.append('on', on ? '1' : '0');
    fetch(acct.url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': meta ? meta.content : '' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (Array.isArray(d.slugs) && (d.ok || d.slugs.length)) { store.set('ms.saved', d.slugs); paintSaved(); }
        if (!d.ok && d.message) toast(d.message);
        if (d.signin && d.url) location.href = d.url;
      })
      .catch(function () { /* offline: the local mirror keeps the tap until the next page load */ });
  }
  function paintSaved() {
    var n = saved.all().length;
    $$('[data-count=saved]').forEach(function (b) { b.textContent = n; b.classList.toggle('hidden', n === 0); b.classList.toggle('grid', n > 0); });
    $$('[data-save]').forEach(function (btn) {
      var on = saved.has(btn.getAttribute('data-save'));
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      btn.classList.toggle('text-copper', on); btn.classList.toggle('text-ink-400', !on);
      var svg = btn.querySelector('svg'); if (svg) svg.setAttribute('fill', on ? 'currentColor' : 'none');
    });
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-save]');
    if (!btn) return;
    e.preventDefault();
    var on = saved.toggle(btn.getAttribute('data-save'));
    paintSaved();
    toast(on ? (acct ? 'Saved to your account' : 'Saved on this device — sign in to keep it on any device') : 'Removed from your shortlist');
  });

  /* ---------- mobile drawer ---------- */
  function drawer() {
    var btn = $('#menuBtn'), d = $('#drawer'); if (!btn || !d) return;
    function close() { d.classList.add('hidden'); btn.setAttribute('aria-expanded', 'false'); document.body.style.overflow = ''; btn.focus(); }
    btn.addEventListener('click', function () {
      d.classList.remove('hidden'); btn.setAttribute('aria-expanded', 'true'); document.body.style.overflow = 'hidden';
      var f = d.querySelector('[data-close]:not(.absolute), input, a'); if (f) f.focus();
    });
    d.addEventListener('click', function (e) { if (e.target.closest('[data-close]')) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !d.classList.contains('hidden')) close(); });
  }

  /* ---------- home: hero slider ---------- */
  function slider(data) {
    var imgs = $$('#slides .slide'), dots = $$('#dots button'), slides = (data && data.slides) || [];
    if (imgs.length < 2 || slides.length < 2) return;
    var cur = 0, timer = null;
    function go(i) {
      cur = (i + slides.length) % slides.length; var s = slides[cur];
      imgs.forEach(function (el, k) { el.classList.toggle('on', k === cur); });
      dots.forEach(function (b, k) { b.className = 'h-1.5 rounded-full transition-all ' + (k === cur ? 'w-8 bg-cream' : 'w-3 bg-cream/35'); });
      $('#slideKicker').textContent = s.kicker;
      var h = $('#slideTitle'); h.textContent = '';
      String(s.title).split('\n').forEach(function (line, k) { if (k) h.appendChild(document.createElement('br')); h.appendChild(document.createTextNode(line)); });
      $('#slideCopy').textContent = s.copy;
      $('#slideCta').setAttribute('href', s.href); $('#slideCtaText').textContent = s.cta;
      restart();
    }
    function restart() { clearInterval(timer); if (!reduceMotion) timer = setInterval(function () { go(cur + 1); }, 7000); }
    dots.forEach(function (b, k) { b.addEventListener('click', function () { go(k); }); });
    var p = $('#prev'), n = $('#next');
    if (p) p.addEventListener('click', function () { go(cur - 1); });
    if (n) n.addEventListener('click', function () { go(cur + 1); });
    restart();
  }

  /* ---------- home: stone finder (where → room → colour), live match count ---------- */
  function finder(data) {
    var f = $('#finder'); if (!f) return;
    var m = (data && data.matches) || { cat: {}, colour: {} };
    function selected() {
      var room = f.querySelector('input[data-room]:checked'), space = f.querySelector('input[data-space]:checked');
      return { space: space, room: room };
    }
    function count() {
      var s = selected(), id = s.room ? s.room.getAttribute('data-room') : (s.space ? s.space.getAttribute('data-space') : null);
      var ids = (id && m.cat[id]) ? m.cat[id].slice() : [];
      var colours = $$('input[data-colour]:checked', f).map(function (i) { return i.value; });
      if (colours.length) {
        var allowed = {};
        colours.forEach(function (c) { (m.colour[c] || []).forEach(function (pid) { allowed[pid] = 1; }); });
        ids = ids.filter(function (pid) { return allowed[pid]; });
      }
      $('#finderCount').textContent = ids.length;
    }
    f.addEventListener('change', function (e) {
      var t = e.target;
      if (t.hasAttribute('data-space')) {
        var sid = t.getAttribute('data-space');
        $$('#fRoom [data-parent]').forEach(function (l) {
          var show = l.getAttribute('data-parent') === sid; l.hidden = !show;
        });
      }
      if (t.hasAttribute('data-room')) {
        // a room replaces its space in the same "cat" radio group; keep the space highlighted visually
        var parent = t.closest('[data-parent]').getAttribute('data-parent');
        var sp = f.querySelector('input[data-space="' + parent + '"]');
        $$('input[data-space]', f).forEach(function (i) { i.nextElementSibling.classList.toggle('!bg-ink', i === sp); i.nextElementSibling.classList.toggle('!text-cream', i === sp); });
      } else if (t.hasAttribute('data-space')) {
        $$('input[data-space]', f).forEach(function (i) { i.nextElementSibling.classList.remove('!bg-ink', '!text-cream'); });
      }
      count();
    });
    f.addEventListener('submit', function (e) {
      // Pretty URL: /shop/{space}[/{room}] ?attr[colour][]=…
      var s = selected(); if (!s.space && !s.room) return;
      e.preventDefault();
      var spaceInput = s.room ? f.querySelector('input[data-space="' + s.room.closest('[data-parent]').getAttribute('data-parent') + '"]') : s.space;
      var path = '/shop/' + encodeURIComponent(spaceInput.value) + (s.room ? '/' + encodeURIComponent(s.room.value) : '');
      var q = $$('input[data-colour]:checked', f).map(function (i) { return 'attr[colour][]=' + encodeURIComponent(i.value); }).join('&');
      var base = f.getAttribute('action').replace(/\/shop$/, '');
      location.href = base + path + (q ? '?' + q : '');
    });
    count();
  }

  /* ---------- home: collection tabs (server rendered all cards; tabs only hide) ---------- */
  function tabs() {
    var bar = $('#tabs'), grid = $('#grid'); if (!bar || !grid) return;
    bar.addEventListener('click', function (e) {
      var b = e.target.closest('[data-tab]'); if (!b) return;
      var k = b.getAttribute('data-tab');
      $$('[data-tab]', bar).forEach(function (x) { x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
      $$('[data-tabs]', grid).forEach(function (card) { card.hidden = (' ' + card.getAttribute('data-tabs') + ' ').indexOf(' ' + k + ' ') < 0; });
    });
  }

  /* ---------- home: spotlight (variant price + area calculator + photos) ---------- */
  function spotlight() {
    var s = $('#spot'); if (!s) return;
    var gst = parseFloat(s.getAttribute('data-gst')) || 0, adv = parseFloat(s.getAttribute('data-advance')) || 0;
    var taxIncluded = s.getAttribute('data-tax-included') === '1';
    var price = parseFloat(s.getAttribute('data-price')) || 0;
    function calc() {
      var a = Math.max(0, parseFloat($('#spotArea').value) || 0), area = Math.ceil(a * 1.08);
      var sub = area * price, total = Math.round(sub * (taxIncluded ? 1 : 1 + gst / 100) * 100) / 100;
      $('#calcArea').textContent = area.toLocaleString('en-IN');
      $('#calcTotal').textContent = inr(total);
      $('#calcAdvance').textContent = inr(Math.ceil(total * adv - 1e-9) / 100, true);
    }
    $$('input[name=spot_variant]', s).forEach(function (r) {
      r.addEventListener('change', function () {
        price = parseFloat(r.getAttribute('data-price')) || 0;
        $('#spotPrice').textContent = inr(price, true);
        var c = r.getAttribute('data-compare'); $('#spotCompare').textContent = c ? inr(c, true) : '';
        $('#spotSku').textContent = r.getAttribute('data-sku');
        calc();
      });
    });
    var thumbs = $('#spotThumbs'), main = s.querySelector('img');
    if (thumbs && main) thumbs.addEventListener('click', function (e) {
      var b = e.target.closest('[data-shot]'); if (!b) return;
      main.removeAttribute('srcset'); main.src = b.getAttribute('data-shot');
      $$('button', thumbs).forEach(function (x) { x.classList.toggle('ring-cream', x === b); x.classList.toggle('ring-transparent', x !== b); });
    });
    $('#spotArea').addEventListener('input', calc);
    calc();
  }


  /* ---------- shared: elements that only make sense with JS ---------- */
  function jsOnly() { $$('[data-js-only]').forEach(function (el) { el.hidden = false; }); }

  /* ---------- shop: filters auto-submit, mobile drawer, grid density ---------- */
  function shop() {
    var form = $('#facets'), panel = $('#filters'); if (!form) return;
    var bar = form.querySelector('[data-apply-bar]'); if (bar) bar.classList.add('lg:hidden');
    var t = null;
    // Leave empty boxes and the default sort out of the URL (same canonical URL the server builds).
    function send() {
      $$('input[type=number]', form).forEach(function (i) { if (i.value === '') i.disabled = true; });
      var so = $('#sort'); if (so && so.value === 'featured') so.disabled = true;
      form.submit();
    }
    form.addEventListener('submit', function (e) { e.preventDefault(); send(); });
    form.addEventListener('change', function (e) {
      if (e.target.type === 'number') return;            // price boxes submit on Enter / Apply
      if (panel && panel.classList.contains('open') && window.matchMedia('(max-width:1023px)').matches) return; // mobile: Apply button
      clearTimeout(t); t = setTimeout(send, 60);
    });
    var sort = $('#sort'); if (sort) sort.addEventListener('change', send);
    $$('[data-filters-open]').forEach(function (b) { b.addEventListener('click', function (e) { e.preventDefault(); panel.classList.add('open'); document.body.style.overflow = 'hidden'; }); });
    $$('[data-filters-close]').forEach(function (b) { b.addEventListener('click', function (e) { e.preventDefault(); panel.classList.remove('open'); document.body.style.overflow = ''; }); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && panel.classList.contains('open')) { panel.classList.remove('open'); document.body.style.overflow = ''; } });
    var grid = $('[data-grid]');
    function dense(on) {
      if (!grid) return;
      grid.className = 'mt-5 grid gap-3 sm:gap-5 ' + (on ? 'grid-cols-2 sm:grid-cols-3 xl:grid-cols-5 2xl:grid-cols-6' : 'grid-cols-2 md:grid-cols-3 2xl:grid-cols-4');
      $$('[data-dense]').forEach(function (b) { b.setAttribute('aria-pressed', (b.getAttribute('data-dense') === '1') === on ? 'true' : 'false'); });
      store.set('ms.dense', on);
    }
    $$('[data-dense]').forEach(function (b) { b.addEventListener('click', function () { dense(b.getAttribute('data-dense') === '1'); }); });
    if (store.get('ms.dense', false)) dense(true);
  }

  /* ---------- product page ---------- */
  function product(d) {
    var root = document;
    var cur = null;
    function byId(id) { for (var i = 0; i < d.variants.length; i++) if (d.variants[i].id === id) return d.variants[i]; return null; }
    function num(n) { var v = Math.round((Number(n) || 0) * 1000) / 1000; return String(v); }
    function setText(sel, txt) { $$(sel).forEach(function (el) { el.textContent = txt; }); }
    var qtyIn = $('#qty');
    var unit = d.unit, price = d.price, minQty = d.min_qty, step = d.step || 1;

    /* gallery */
    var main = $('[data-main]'), thumbs = $('#thumbs');
    function shot(i) {
      if (!main || !d.shots[i]) return;
      main.src = d.shots[i].full; main.alt = d.shots[i].alt;
      if (thumbs) $$('[data-shot]', thumbs).forEach(function (a) {
        var on = +a.getAttribute('data-shot') === i;
        a.classList.toggle('ring-ink', on); a.classList.toggle('ring-transparent', !on);
        if (on) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current');
      });
    }
    if (thumbs) thumbs.addEventListener('click', function (e) { var a = e.target.closest('[data-shot]'); if (!a) return; e.preventDefault(); shot(+a.getAttribute('data-shot')); });
    var box = $('#mainImg');
    if (box && main && window.matchMedia('(hover:hover)').matches) {
      box.addEventListener('mousemove', function (e) { var r = box.getBoundingClientRect(); main.style.transformOrigin = ((e.clientX - r.left) / r.width * 100) + '% ' + ((e.clientY - r.top) / r.height * 100) + '%'; main.style.transform = 'scale(2)'; });
      box.addEventListener('mouseleave', function () { main.style.transform = ''; });
      box.classList.add('cursor-zoom-in');
    }

    /* totals */
    function totals() {
      if (!qtyIn) return;
      var q = Math.max(0, parseFloat(qtyIn.value) || 0);
      var line = Math.round(price * q * 100) / 100, gst = Math.round(line * d.gst) / 100;
      setText('[data-line]', inr(line, true));
      setText('[data-gst]', inr(gst, true));
      setText('[data-advance]', inr(Math.ceil((line + gst) * d.advance - 1e-9) / 100, true));
      setText('[data-qty-label]', num(q));
      var wa = $('[data-wa-order]');
      if (wa && d.wa) wa.href = 'https://wa.me/' + d.wa + '?text=' + encodeURIComponent(wa.getAttribute('data-wa-base') + (cur ? ' (' + cur.title + ')' : '') + ', ' + num(q) + ' ' + unit);
    }
    function clampQty(v) {
      var lo = minQty || step; v = Math.max(lo, v);
      if (step > 0) v = Math.round(Math.ceil(v / step - 1e-9) * step * 1000) / 1000;
      return v;
    }
    if (qtyIn) {
      $$('[data-qty]').forEach(function (b) { b.addEventListener('click', function () { qtyIn.value = num(clampQty((parseFloat(qtyIn.value) || 0) + step * +b.getAttribute('data-qty'))); totals(); }); });
      qtyIn.addEventListener('input', totals);
      qtyIn.addEventListener('change', function () { qtyIn.value = num(clampQty(parseFloat(qtyIn.value) || 0)); totals(); });
    }

    /* room calculator: area + 8% → qty in the selling unit (boxes when the unit is a pack) */
    var cl = $('[data-calc=l]'), cw = $('[data-calc=w]'), cout = $('[data-calc-out]');
    function calc() {
      if (!cl) return 0;
      var a = (parseFloat(cl.value) || 0) * (parseFloat(cw.value) || 0), w = Math.ceil(a * 1.08), pack = cur ? cur.pack_qty : d.pack_qty;
      var need = pack ? Math.ceil(w / pack) : w;
      cout.textContent = a.toLocaleString('en-IN') + ' sq ft + 8% wastage = ' + w.toLocaleString('en-IN') + ' sq ft' + (pack ? ' → ' + need + ' ' + unit : '');
      return need;
    }
    if (cl) { cl.addEventListener('input', calc); cw.addEventListener('input', calc); $('[data-calc-use]').addEventListener('click', function () { qtyIn.value = num(clampQty(calc())); totals(); }); }

    /* variants */
    function apply(v, push) {
      cur = v;
      price = v.price; unit = v.unit; minQty = v.min_qty;
      setText('[data-price]', inr(v.price, true));
      setText('[data-sku]', v.sku);
      setText('[data-unit]', v.unit);
      setText('[data-variant-title]', v.title);
      var cw2 = $('[data-compare-wrap]'); if (cw2) { cw2.hidden = !v.compare; setText('[data-compare]', v.compare ? inr(v.compare, true) : ''); setText('[data-off]', v.off); }
      var pk = $('[data-pack]'); if (pk) { pk.hidden = !(v.pack_qty && v.pack_unit); pk.textContent = v.pack_qty && v.pack_unit ? '1 ' + v.unit + ' = ' + num(v.pack_qty) + ' ' + v.pack_unit + ' (' + inr(v.price / v.pack_qty, true) + '/' + v.pack_unit + ') · ' : ''; }
      var ps = $('[data-pack-spec]'); if (ps) { ps.hidden = !(v.pack_qty && v.pack_unit); setText('[data-pack-qty]', num(v.pack_qty)); }
      var mh = $('[data-min-help]'); if (mh) { mh.hidden = !v.min_qty; setText('[data-min]', num(v.min_qty)); }
      $$('[data-variant-input]').forEach(function (i) { i.value = v.id; });
      if (qtyIn) { qtyIn.min = num(v.min_qty || step); qtyIn.value = num(clampQty(parseFloat(qtyIn.value) || 0)); }
      // specs: variant values override attribute rows
      var ov = d.overrides[v.id] || {};
      $$('[data-spec]').forEach(function (row) {
        var label = row.getAttribute('data-spec'), val = ov[label], base = row.getAttribute('data-base');
        row.querySelector('[data-spec-value]').textContent = val != null ? val : base;
        row.querySelector('[data-spec-sel]').hidden = val == null;
        row.hidden = val == null && !base;
      });
      // table rows
      $$('[data-row]').forEach(function (tr) {
        var on = +tr.getAttribute('data-row') === v.id, vv = byId(+tr.getAttribute('data-row'));
        tr.classList.toggle('bg-teal-50/60', on);
        var st = tr.querySelector('[data-row-status]');
        if (st && vv) st.innerHTML = on ? '<span class="text-[11.5px] font-bold text-teal">Selected</span>' : vv.active ? '<span class="text-[11.5px] text-ink-500">Available</span>' : '<span class="text-[11.5px] text-ink-400">Not offered</span>';
      });
      // picker radios + labels
      d.groups.forEach(function (g) {
        var vid = v.values[g.id];
        $$('input[data-group="' + g.id + '"]').forEach(function (r) { r.checked = +r.value === vid; });
        var lab = $('[data-group-label="' + g.id + '"]'); if (lab) g.values.forEach(function (x) { if (x.id === vid) lab.textContent = x.value; });
      });
      strike();
      if (v.images && v.images.length) shot(v.images[0]);
      if (push && history.replaceState) history.replaceState(null, '', d.url + '?v=' + encodeURIComponent(v.sku) + location.hash);
      totals(); calc();
    }
    // A value is struck through when no active variant has it together with the other chosen values.
    function strike() {
      if (!cur) return;
      d.groups.forEach(function (g) {
        g.values.forEach(function (x) {
          var ok = d.variants.some(function (v) {
            if (!v.active || v.values[g.id] !== x.id) return false;
            return d.groups.every(function (o) { return o.id === g.id || v.values[o.id] === cur.values[o.id] || v.values[o.id] == null; });
          });
          var anyActive = d.variants.some(function (v) { return v.active && v.values[g.id] === x.id; });
          var chip = $('[data-opt="' + x.id + '"]');
          if (chip) { chip.classList.toggle('line-through', !ok); chip.classList.toggle('opacity-40', !anyActive); chip.title = ok ? '' : (anyActive ? 'Not in this combination — picks the nearest option' : 'Not offered'); }
          var input = $('input[data-group="' + g.id + '"][value="' + x.id + '"]'); if (input) input.disabled = !anyActive;
        });
      });
    }
    // Choosing a value: exact match with the other choices, else the closest active variant with that value.
    function choose(gid, vid) {
      var want = {}; d.groups.forEach(function (g) { want[g.id] = cur ? cur.values[g.id] : null; }); want[gid] = vid;
      var best = null, score = -1;
      d.variants.forEach(function (v) {
        if (!v.active || v.values[gid] !== vid) return;
        var s = 0; d.groups.forEach(function (g) { if (v.values[g.id] === want[g.id]) s++; });
        if (s > score) { score = s; best = v; }
      });
      if (best) apply(best, true);
    }
    root.addEventListener('change', function (e) { var r = e.target; if (r.matches && r.matches('input[data-group]')) choose(+r.getAttribute('data-group'), +r.value); });
    root.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('[data-pick]'); if (!a) return;
      var v = byId(+a.getAttribute('data-pick')); if (!v || !v.active) return;
      e.preventDefault(); apply(v, true); var buy = $('#buy'); if (buy) buy.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
    });
    var start = byId(d.selected);
    if (start) apply(start, false); else { totals(); calc(); }

    /* reviews filter */
    var rf = $('#revFilter');
    if (rf) rf.addEventListener('click', function (e) {
      var b = e.target.closest('[data-app]'); if (!b) return;
      var k = b.getAttribute('data-app');
      $$('[data-app]', rf).forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      $$('[data-review-app]').forEach(function (r) { r.hidden = k !== '' && r.getAttribute('data-review-app') !== k; });
    });
  }

  /* ---------- S3 bag: add-to-bag forms via fetch (plain post without JS) ---------- */
  function setBagCount(n) {
    $$('[data-count=bag]').forEach(function (b) { b.textContent = n; b.classList.toggle('hidden', !n); b.classList.toggle('grid', !!n); });
  }
  function cartForms() {
    var meta = $('meta[name=csrf-token]');
    document.addEventListener('submit', function (e) {
      var f = e.target;
      if (!f || !/\/cart\/add$/.test(f.getAttribute('action') || '')) return;
      if (e.submitter && e.submitter.name === 'next') return; // "Buy now" → normal post + redirect
      if (!window.fetch || !window.FormData) return;
      e.preventDefault();
      var btns = $$('button', f).concat(f.id ? $$('button[form="' + f.id + '"]') : []);
      btns.forEach(function (b) { b.disabled = true; });
      fetch(f.action, { method: 'POST', body: new FormData(f), credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': meta ? meta.content : '' } })
        .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Something went wrong. Please try again.' }; }); })
        .then(function (d) { if (d.ok) setBagCount(d.count); toast(d.message); })
        .catch(function () { f.submit(); })
        .then(function () { btns.forEach(function (b) { b.disabled = false; }); });
    });
  }

  /* ---------- bag page: steppers auto-submit, "Save for later" also saves locally ---------- */
  function cartPage() {
    $$('form[data-cart-qty]').forEach(function (f) {
      var input = $('input[name=qty]', f), timer = null;
      $$('[data-js-hide]', f).forEach(function (b) { b.hidden = true; });
      function send() { clearTimeout(timer); timer = setTimeout(function () { f.submit(); }, 450); }
      $$('[data-step]', f).forEach(function (b) {
        b.addEventListener('click', function () {
          var step = parseFloat(b.getAttribute('data-step')) || 1, min = parseFloat(input.min) || Math.abs(step);
          var v = Math.round(((parseFloat(input.value) || 0) + step) * 1000) / 1000;
          input.value = String(Math.max(min, v)); send();
        });
      });
      input.addEventListener('change', send);
    });
    $$('[data-later]').forEach(function (b) {
      b.addEventListener('click', function () { var s = b.getAttribute('data-later'); if (s && !saved.has(s)) saved.toggle(s); });
    });
  }

  /* ---------- calculator: rooms → sq ft → order qty (server re-checks on add) ---------- */
  function calculator(d) {
    var root = $('#calc'); if (!root || !d.stones.length) return;
    var rooms = $('#rooms'), tpl = $('[data-room]', rooms).cloneNode(true), form = $('#calcForm'), optSel = $('#opt');
    function out(k, txt) { $$('[data-out="' + k + '"]', root).forEach(function (el) { el.textContent = txt; }); }
    function n(x) { return Math.round(x * 1000) / 1000; }
    function fmt(x) { return n(x).toLocaleString('en-IN'); }
    function isSqft(u) { return /^(sq ?ft|sft|square ?f(ee|oo)t)$/i.test(String(u || '').replace(/\./g, '').trim()); }
    function stone() {
      var r = $('input[name=stone]:checked', root), id = r ? +r.value : d.stones[0].id;
      for (var i = 0; i < d.stones.length; i++) if (d.stones[i].id === id) return d.stones[i];
      return d.stones[0];
    }
    function fillOpts() {
      var s = stone(); optSel.innerHTML = '';
      s.options.forEach(function (o, i) {
        var op = document.createElement('option'); op.value = i;
        op.textContent = (o.title || 'Standard') + ' · ' + inr(o.price, true) + '/' + o.unit; optSel.appendChild(op);
      });
      $('#opts').hidden = s.options.length < 2;
    }
    function calc() {
      var area = 0;
      $$('[data-room]', rooms).forEach(function (r) {
        var g = function (k) { return Math.max(0, parseFloat(($('[data-k=' + k + ']', r) || {}).value) || 0); };
        area += Math.max(0, g('l') * g('w') - g('minus'));
      });
      var wst = +$('#waste').value, need = area * (1 + wst / 100), s = stone(), o = s.options[+optSel.value || 0] || s.options[0];
      var step = s.step || 1, qty;
      if (isSqft(o.unit)) qty = need;
      else if (o.pack_qty && isSqft(o.pack_unit)) qty = need / o.pack_qty;
      else qty = 0; // sold per piece / slab: can't be derived from an area
      if (qty > 0) {
        qty = Math.ceil(n(qty / step)) * step;
        if (o.min_qty && qty < o.min_qty) qty = Math.ceil(n(o.min_qty / step)) * step;
        qty = n(qty);
      }
      var sub = Math.round(qty * o.price * 100) / 100, gst = d.tax_rate > 0 ? Math.round(sub * d.tax_rate) / 100 : 0, total = Math.round((sub + gst) * 100) / 100;
      out('name', s.name); out('rate', (o.title ? o.title + ' · ' : '') + inr(o.price, true) + ' / ' + o.unit);
      out('area', fmt(area) + ' sq ft'); out('waste', wst); out('need', fmt(Math.ceil(need)) + ' sq ft');
      out('order', qty > 0 ? fmt(qty) + ' ' + o.unit + (o.pack_qty && o.pack_unit && !isSqft(o.unit) ? ' (' + fmt(qty * o.pack_qty) + ' ' + o.pack_unit + ')' : '') : 'Ask us — sold per ' + o.unit);
      out('sub', inr(sub, true)); out('gst', inr(gst, true)); out('total', inr(total, true));
      out('advance', inr(Math.ceil(total * d.advance_percent) / 100, true));
      out('add', qty > 0 ? 'Add ' + fmt(qty) + ' ' + o.unit + ' to bag' : 'Add to bag');
      form.elements.product_id.value = s.id; form.elements.variant_id.value = o.id; form.elements.qty.value = qty > 0 ? qty : '';
      $('button', form).disabled = !(qty > 0);
      var view = $('[data-out=view]', root); if (view) view.href = d.base + s.url;
    }
    rooms.addEventListener('input', calc);
    rooms.addEventListener('click', function (e) {
      var b = e.target.closest('[data-del]');
      if (b && $$('[data-room]', rooms).length > 1) { rooms.removeChild(b.closest('[data-room]')); calc(); }
    });
    $('#addRoom').addEventListener('click', function () {
      var r = tpl.cloneNode(true), k = $$('[data-room]', rooms).length + 1;
      $('[data-k=name]', r).value = 'Room ' + k; $('[data-k=l]', r).value = 10; $('[data-k=w]', r).value = 10; $('[data-k=minus]', r).value = 0;
      rooms.appendChild(r); icons(); calc();
    });
    $('#stones').addEventListener('change', function () { fillOpts(); calc(); });
    optSel.addEventListener('change', calc);
    $('#waste').addEventListener('change', calc);
    fillOpts(); calc();
  }

  /* ---------- saved page: cards for the local shortlist ---------- */
  function savedPage() {
    var page = $('#savedPage'); if (!page) return;
    var grid = $('#savedGrid'), empty = $('#savedEmpty'), share = $('#share');
    function render() {
      var list = saved.all();
      if (!list.length) { grid.innerHTML = ''; empty.hidden = false; share.hidden = true; return; }
      fetch(page.getAttribute('data-cards') + '?s=' + encodeURIComponent(list.join(',')), { credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (html) {
          grid.innerHTML = html; empty.hidden = html.trim() !== '';
          var names = $$('h3', grid).map(function (h) { return h.textContent.trim(); });
          share.hidden = !names.length;
          share.href = share.href.split('?')[0] + '?text=' + encodeURIComponent('My stone shortlist: ' + names.join(', '));
          paintSaved(); icons();
        });
    }
    document.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('#savedGrid [data-save]')) setTimeout(render, 0); });
    render();
  }

  /* ---------- checkout: plan, billing, GST rows by state, pincode autofill, pay window ---------- */
  function checkoutPage() {
    var form = $('#co'); if (!form) return;
    var amounts = {}; try { amounts = JSON.parse(($('#co-amounts') || {}).textContent || '{}'); } catch (e) { /* ignore */ }
    var home = (form.getAttribute('data-home-state') || '').toLowerCase();
    function plan() {
      var c = form.querySelector('[name=pay_choice]:checked'), k = c ? c.value : 'advance';
      $$('[data-paynow]').forEach(function (el) { el.textContent = amounts[k] || el.textContent; });
      $$('[data-plan-note]').forEach(function (el) { el.hidden = el.getAttribute('data-plan-note') !== k; });
      var amt = document.querySelector('#payWindow [data-pw-amount]'); if (amt) amt.textContent = amounts[k] || amt.textContent;
    }
    function taxRows() {
      var st = ($('#state').value || '').toLowerCase(), intra = !st || st === home;
      $$('[data-tax]').forEach(function (el) { el.hidden = (el.getAttribute('data-tax') === 'cgst_sgst') !== intra; });
    }
    function clearErr(name) {
      var f = form.querySelector('[name="' + name + '"]:not([type=hidden])'); if (f) f.removeAttribute('aria-invalid');
      var m = form.querySelector('[data-err-for="' + name + '"]'); if (m) m.textContent = '';
    }
    form.addEventListener('change', function (e) {
      if (e.target.name === 'pay_choice') plan();
      if (e.target.id === 'state') taxRows();
      if (e.target.id === 'sameBill') $('#billBox').hidden = e.target.checked;
      if (e.target.getAttribute('aria-invalid') === 'true' || e.target.id === 'terms') clearErr(e.target.name);
    });
    form.addEventListener('input', function (e) { if (e.target.getAttribute('aria-invalid') === 'true') clearErr(e.target.name); });

    function showErrors(errors, message) {
      $$('[data-err-for]', form).forEach(function (m) { m.textContent = ''; });
      $$('[aria-invalid]', form).forEach(function (f) { f.removeAttribute('aria-invalid'); });
      var first = null, general = [];
      Object.keys(errors || {}).forEach(function (k) {
        var msg = errors[k][0], f = form.querySelector('[name="' + k + '"]:not([type=hidden])'), m = form.querySelector('[data-err-for="' + k + '"]');
        if (f) f.setAttribute('aria-invalid', 'true');
        if (m) m.textContent = msg; else general.push(msg);
        if (!first && f) first = f;
        if (k.indexOf('bill_') === 0) { $('#billBox').hidden = false; $('#sameBill').checked = false; }
      });
      var box = $('#coErr');
      box.textContent = general.length ? general.join(' ') : (message && !first ? message : '');
      box.hidden = !box.textContent;
      if (first) { first.focus(); first.scrollIntoView({ block: 'center' }); } else if (!box.hidden) box.scrollIntoView({ block: 'center' });
    }
    document.addEventListener('paywindow:errors', function (e) { showErrors(e.detail.errors, e.detail.message); });

    /* Quick checks before the window opens (the server checks everything again). */
    function check() {
      var e = {}, val = function (n) { var f = form.querySelector('[name="' + n + '"]:not([type=hidden])'); return f ? f.value.trim() : ''; };
      if (!/^(\+?91|0)?[6-9]\d{9}$/.test(val('phone').replace(/[\s-]/g, ''))) e.phone = ['Enter your 10-digit mobile number.'];
      if (val('name').length < 2) e.name = ['Enter your name.'];
      if (!/^[1-9]\d{5}$/.test(val('pincode'))) e.pincode = ['Enter the 6-digit pincode.'];
      if (!val('state')) e.state = ['Pick your state.'];
      if (!val('city')) e.city = ['Enter your district or city.'];
      if (val('line1').length < 3) e.line1 = ['Enter your house / building and street.'];
      if (!$('#sameBill').checked) {
        if (val('bill_line1').length < 3) e.bill_line1 = ['Enter the billing street address.'];
        if (!/^[1-9]\d{5}$/.test(val('bill_pincode'))) e.bill_pincode = ['Enter the 6-digit pincode.'];
        if (!val('bill_state')) e.bill_state = ['Pick your state.'];
        if (!val('bill_city')) e.bill_city = ['Enter your district or city.'];
      }
      if (!$('#terms').checked) e.terms = ['Please accept the terms and returns policy to continue.'];
      return e;
    }
    form.addEventListener('submit', function (ev) {
      if (!window.PayWindow) return; // no pay window on the page → plain post (server explains)
      ev.preventDefault();
      var e = check();
      if (Object.keys(e).length) { showErrors(e); return; }
      showErrors({});
      window.PayWindow.open();
    });

    /* Pincode → state + city + post offices, through our own cached lookup. */
    var pin = $('#pin'), msg = $('#pinMsg'), spin = $('#pinSpin'), last = '';
    var url = form.getAttribute('data-pincode-url');
    function failed() { spin.hidden = true; msg.textContent = 'Couldn’t look this up — please pick your state and type your city.'; }
    function lookup() {
      var v = pin.value.replace(/\D/g, '').slice(0, 6);
      if (pin.value !== v) pin.value = v;
      if (v.length < 6 || v === last) return;
      last = v; spin.hidden = false; msg.textContent = 'Looking up…';
      fetch(url.replace('000000', v), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) { failed(); if (j.reason === 'invalid') msg.textContent = 'A pincode is 6 digits and doesn’t start with 0.'; return; }
          spin.hidden = true;
          var sel = $('#state'), want = String(j.state || '').toLowerCase();
          Array.prototype.forEach.call(sel.options, function (o) { if (o.value && o.value.toLowerCase() === want) sel.value = o.value; });
          if (j.district && !$('#city').value.trim()) $('#city').value = j.district;
          var list = $('#areaList'); list.textContent = '';
          (j.areas || []).forEach(function (a) { var o = document.createElement('option'); o.value = a; list.appendChild(o); });
          msg.textContent = '✓ ' + [j.district, j.state].filter(Boolean).join(', ');
          clearErr('state'); clearErr('city'); clearErr('pincode');
          taxRows();
        })
        .catch(failed);
    }
    pin.addEventListener('input', lookup);
    if (pin.value.length === 6) lookup();
    plan(); taxRows();

    /* Signed in (S5): pick a saved address → the fields fill in; typing in them → "a new address". */
    var pick = $('#addrPick');
    if (pick) {
      var names = ['pincode', 'state', 'city', 'area', 'line1', 'line2', 'landmark'], filling = false;
      pick.addEventListener('change', function (e) {
        var r = e.target; if (!r || r.name !== 'address_id') return;
        if (r.hasAttribute('data-addr-new')) { names.forEach(function (n) { var f = form.querySelector('[name="' + n + '"]'); if (f && f.tagName !== 'SELECT') f.value = ''; }); $('#state').value = ''; pin.focus(); taxRows(); return; }
        var a = {}; try { a = JSON.parse(r.getAttribute('data-addr') || '{}'); } catch (x) { a = {}; }
        filling = true;
        names.forEach(function (n) { var f = form.querySelector('[name="' + n + '"]'); if (f) { f.value = a[n] || ''; clearErr(n); } });
        var sel = $('#state'), want = String(a.state || '').toLowerCase();
        Array.prototype.forEach.call(sel.options, function (o) { if (o.value.toLowerCase() === want) sel.value = o.value; });
        last = pin.value; msg.textContent = ''; filling = false; taxRows();
      });
      form.addEventListener('input', function (e) {
        if (filling || names.indexOf(e.target.name) < 0) return;
        var n = pick.querySelector('[data-addr-new]'); if (n && !n.checked) n.checked = true;
      });
      form.addEventListener('change', function (e) { if (e.target.id === 'state' && !filling) { var n = pick.querySelector('[data-addr-new]'); if (n) n.checked = true; } });
    }
  }

  /* ---------- account address form (S5): pincode → state + city + post offices ---------- */
  function addressForm() {
    var form = $('[data-address-form]'); if (!form || !window.fetch) return;
    var pin = $('#pin', form), msg = $('#pinMsg', form), url = form.getAttribute('data-pincode-url'), last = '';
    pin.addEventListener('input', function () {
      var v = pin.value.replace(/\D/g, '').slice(0, 6); if (pin.value !== v) pin.value = v;
      if (v.length < 6 || v === last) return;
      last = v; msg.textContent = 'Looking up…';
      fetch(url.replace('000000', v), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) { msg.textContent = j.reason === 'invalid' ? 'A pincode is 6 digits and doesn’t start with 0.' : 'Couldn’t look this up — pick the state and type the city.'; return; }
          var sel = $('#state', form), want = String(j.state || '').toLowerCase();
          Array.prototype.forEach.call(sel.options, function (o) { if (o.value && o.value.toLowerCase() === want) sel.value = o.value; });
          var city = $('#city', form); if (j.district && !city.value.trim()) city.value = j.district;
          var list = $('#areaList', form); list.textContent = '';
          (j.areas || []).forEach(function (a) { var o = document.createElement('option'); o.value = a; list.appendChild(o); });
          msg.textContent = '✓ ' + [j.district, j.state].filter(Boolean).join(', ');
        })
        .catch(function () { msg.textContent = 'Couldn’t look this up — pick the state and type the city.'; });
    });
  }

  /* ---------- sign-in page (S5): tabs, and the guest saved list travels with every sign-in form ---------- */
  function authForms() {
    document.addEventListener('submit', function (e) {
      var f = e.target; if (!f || !f.hasAttribute || !f.hasAttribute('data-auth')) return;
      var i = f.querySelector('input[name=saved]'); if (i) i.value = JSON.stringify(saved.all());
    });
    var tabs = $$('[data-auth-tab]'); if (!tabs.length) return;
    function show(k) {
      if (!$('[data-auth-panel="' + k + '"]')) k = 'in';
      $$('[data-auth-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-auth-panel') !== k; });
      var tabKey = (k === 'up') ? 'up' : 'in';
      $$('[role=tab][data-auth-tab]').forEach(function (t) { t.setAttribute('aria-selected', t.getAttribute('data-auth-tab') === tabKey ? 'true' : 'false'); });
      var f = $('[data-auth-panel="' + k + '"] input:not([type=hidden])'); if (f && document.activeElement && document.activeElement.closest && document.activeElement.closest('[data-auth-tab],[data-auth-go]')) f.focus();
    }
    tabs.forEach(function (t) { t.addEventListener('click', function (e) { e.preventDefault(); var k = t.getAttribute('data-auth-tab'); history.replaceState(null, '', '#' + k); show(k); }); });
    $$('[data-auth-go]').forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault(); var k = a.getAttribute('data-auth-go'); history.replaceState(null, '', '#' + k); show(k); }); });
    var start = (location.hash || '').slice(1);
    if (start) show(start);
  }

  /* ---------- app shell (docs/mobile-app.md) ---------- */
  function haptic() {
    var h = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.Haptics;
    if (h) h.impact({ style: 'LIGHT' }).catch(function () {});
  }
  function appShell() {
    var root = document.documentElement, isApp = root.classList.contains('is-app');

    if ('serviceWorker' in navigator && root.getAttribute('data-sw')) {
      window.addEventListener('load', function () { navigator.serviceWorker.register(root.getAttribute('data-sw')).catch(function () {}); });
    }

    var netBar = $('#netBar');
    function net() { var off = navigator.onLine === false; root.classList.toggle('is-offline', off); if (netBar) netBar.hidden = !off; }
    window.addEventListener('offline', net);
    window.addEventListener('online', function () { net(); toast('Back online'); });
    net();
    document.addEventListener('submit', function (e) {
      if (navigator.onLine !== false) return;
      e.preventDefault(); e.stopImmediatePropagation();
      toast('You’re offline. Try again when you’re connected.');
    }, true);
    document.addEventListener('submit', function (e) {
      if (e.target.getAttribute('action') && /\/signout$/.test(e.target.getAttribute('action')) && window.caches) caches.delete('ms-pages-v1');
    });

    var bar = $('#tabBar');
    if (bar) {
      var lastY = window.scrollY, ticking = false;
      window.addEventListener('scroll', function () {
        if (ticking) return; ticking = true;
        requestAnimationFrame(function () {
          var y = window.scrollY, d = y - lastY;
          if (Math.abs(d) > 8) { bar.classList.toggle('tb-hide', d > 0 && y > 140); lastY = y; }
          ticking = false;
        });
      }, { passive: true });
      document.addEventListener('focusin', function (e) { if (e.target.matches('input:not([type=checkbox]):not([type=radio]), textarea, select')) bar.classList.add('tb-hide'); });
      document.addEventListener('focusout', function () { bar.classList.remove('tb-hide'); });
      bar.addEventListener('click', function (e) { if (e.target.closest('.tab-i')) haptic(); });
    }

    var np = $('#navProgress');
    function busy() { if (np) { np.classList.remove('on'); void np.offsetWidth; np.classList.add('on'); } }
    document.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('a[href]');
      if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      if (a.target || a.hasAttribute('download') || a.origin !== location.origin) return;
      if (a.pathname === location.pathname && a.search === location.search && a.hash) return;
      $$('[data-vt]').forEach(function (el) { el.style.viewTransitionName = ''; el.removeAttribute('data-vt'); });
      var img = a.closest('.card') && a.closest('.card').querySelector('.card-img img');
      if (img && /\/product\//.test(a.pathname)) {
        var main = $('#mainImg img[data-main]'); if (main) main.style.viewTransitionName = 'none';
        img.style.viewTransitionName = 'pimg'; img.setAttribute('data-vt', '');
      }
      busy();
    });
    document.addEventListener('submit', function (e) { if (!e.defaultPrevented && (e.target.method || '').toLowerCase() === 'get') busy(); });
    window.addEventListener('pageshow', function () {
      if (np) np.classList.remove('on');
      $$('[data-vt]').forEach(function (el) { el.style.viewTransitionName = ''; el.removeAttribute('data-vt'); });
      var main = $('#mainImg img[data-main]'); if (main) main.style.viewTransitionName = '';
    });

    if (!isApp) return;
    document.addEventListener('contextmenu', function (e) { if (!e.target.closest('input, textarea, [contenteditable], .selectable')) e.preventDefault(); });
    var ptr = $('#ptr'), y0 = null, pull = 0;
    if (ptr) {
      document.addEventListener('touchstart', function (e) {
        y0 = (window.scrollY <= 0 && e.touches.length === 1 && !document.body.style.overflow) ? e.touches[0].clientY : null; pull = 0;
      }, { passive: true });
      document.addEventListener('touchmove', function (e) {
        if (y0 === null) return;
        pull = Math.max(0, e.touches[0].clientY - y0);
        if (window.scrollY > 0) { y0 = null; ptr.style.opacity = 0; return; }
        ptr.style.transform = 'translateY(' + Math.min(pull * 0.45, 72) + 'px) rotate(' + Math.round(pull * 1.6) + 'deg)';
        ptr.style.opacity = Math.min(pull / 110, 1);
      }, { passive: true });
      document.addEventListener('touchend', function () {
        if (y0 === null) return;
        y0 = null;
        if (pull > 130 && navigator.onLine !== false) { haptic(); ptr.classList.add('spin'); location.reload(); return; }
        ptr.style.transition = 'transform .2s, opacity .2s'; ptr.style.transform = ''; ptr.style.opacity = 0;
        setTimeout(function () { ptr.style.transition = ''; }, 220);
      });
    }
  }

  function offlinePage() {
    var retry = $('[data-retry]'); if (retry) retry.addEventListener('click', function () { location.reload(); });
    var box = $('#offlineList'); if (!box || !window.caches) return;
    function label(u) {
      var parts = u.pathname.split('/').filter(Boolean), last = decodeURIComponent(parts[parts.length - 1] || '');
      var name = last.replace(/[-_]+/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
      if (!parts.length) return ['house', 'Home'];
      if (parts[0] === 'product') return ['gem', name];
      if (parts[0] === 'shop') return ['layout-grid', parts.length > 1 ? name : 'Shop'];
      return ['file-text', name];
    }
    caches.open('ms-pages-v1').then(function (c) { return c.keys(); }).then(function (keys) {
      var seen = {}, list = $('ul', box);
      keys.reverse().forEach(function (k) {
        var u = new URL(k.url); if (u.search || seen[u.pathname] || /^\/offline/.test(u.pathname)) return;
        seen[u.pathname] = 1;
        if (list.children.length >= 12) return;
        var l = label(u), a = document.createElement('a'), i = document.createElement('i');
        a.href = u.href; a.className = 'app-row'; i.setAttribute('data-lucide', l[0]);
        a.appendChild(i); a.appendChild(document.createTextNode(' ' + l[1]));
        var li = document.createElement('li'); li.appendChild(a); list.appendChild(li);
      });
      if (list.children.length) { box.hidden = false; icons(); }
    }).catch(function () {});
  }

  document.addEventListener('DOMContentLoaded', function () {
    icons();
    appShell();
    offlinePage();
    drawer();
    paintSaved();
    var flash = []; try { flash = JSON.parse(($('#store-flash') || {}).textContent || '[]'); } catch (e) { /* ignore */ }
    if (flash.length) toast(flash.map(function (f) { return f.message; }).join(' · '));
    var home = null; try { home = JSON.parse(($('#home-data') || {}).textContent || 'null'); } catch (e) { /* ignore */ }
    if (home) { slider(home); finder(home); tabs(); spotlight(); }
    jsOnly();
    shop();
    var pd = null; try { pd = JSON.parse(($('#product-data') || {}).textContent || 'null'); } catch (e) { /* ignore */ }
    if (pd) product(pd);
    cartForms(); cartPage(); savedPage(); checkoutPage(); authForms(); addressForm();
    $$('[data-copy-link]').forEach(function (b) {
      b.hidden = false;
      b.addEventListener('click', function () {
        var url = location.href.split('#')[0];
        (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject()).then(function () { toast('Link copied'); }, function () { prompt('Copy this link:', url); });
      });
    });
    var cd = null; try { cd = JSON.parse(($('#calc-data') || {}).textContent || 'null'); } catch (e) { /* ignore */ }
    if (cd) calculator(cd);
    icons();
  });
})();
