/* Maa Sarada storefront — small progressive enhancements (docs/storefront.md §2).
   Every page works without this file: links and forms do the real work.
   Holds: icons, mobile drawer, toasts, saved hearts (guest list in localStorage until S5),
   and the home page's slider / finder / collection tabs / spotlight calculator. */
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

  /* ---------- saved (guest shortlist; merged into the account at sign-in in S5) ---------- */
  var saved = {
    all: function () { var a = store.get('ms.saved', []); return Array.isArray(a) ? a : []; },
    has: function (s) { return saved.all().indexOf(s) >= 0; },
    toggle: function (s) {
      var a = saved.all(), i = a.indexOf(s);
      if (i < 0) a.push(s); else a.splice(i, 1);
      store.set('ms.saved', a); return i < 0;
    }
  };
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
    paintSaved(); toast(on ? 'Saved to your shortlist' : 'Removed from your shortlist');
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

  document.addEventListener('DOMContentLoaded', function () {
    icons();
    drawer();
    paintSaved();
    var flash = []; try { flash = JSON.parse(($('#store-flash') || {}).textContent || '[]'); } catch (e) { /* ignore */ }
    if (flash.length) toast(flash.map(function (f) { return f.message; }).join(' · '));
    var home = null; try { home = JSON.parse(($('#home-data') || {}).textContent || 'null'); } catch (e) { /* ignore */ }
    if (home) { slider(home); finder(home); tabs(); spotlight(); }
  });
})();
