/*
 * Maa Sarada admin: shared media uploader + "pick from library" dialog.
 *
 *   <div data-uploader data-kinds="image,video" data-owner-type="product" data-owner-id="12"
 *        data-limit-image="2097152" data-limit-video="2097152"> … </div>
 *
 * Vayu parses one file per request, so every file is its own POST to /admin/media/upload with a
 * progress bar. Each finished file fires `ms:uploaded` (detail = media JSON) on the element.
 *
 *   msMedia.pick({kinds:['image'], multiple:true}).then(list => …)   // list of media JSON
 */
(function () {
  'use strict';
  var BASE = (document.querySelector('meta[name=ms-base]') || {}).content || '';
  var TOKEN = (document.querySelector('meta[name=csrf-token]') || {}).content || '';
  var EXT = {
    image: ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'],
    video: ['mp4', 'webm', 'mov', 'm4v'],
    document: ['pdf']
  };

  function url(p) { return BASE.replace(/\/$/, '') + p; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); }
  function human(b) { return b >= 1048576 ? (Math.round(b / 104857.6) / 10) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
  function kindOf(name) {
    var ext = (name.split('.').pop() || '').toLowerCase();
    for (var k in EXT) { if (EXT[k].indexOf(ext) !== -1) return k; }
    return null;
  }
  function icons() { if (window.lucide) window.lucide.createIcons(); }
  function toast(type, msg) { if (window.msToast) window.msToast[type](msg); }

  /** POST helper for the JSON endpoints (CSRF via header). */
  function post(path, data) {
    var body = new FormData();
    Object.keys(data || {}).forEach(function (k) {
      var v = data[k];
      if (Array.isArray(v)) v.forEach(function (x) { body.append(k + '[]', x); });
      else if (v != null) body.append(k, v);
    });
    body.append('_token', TOKEN);
    return fetch(path, {method: 'POST', body: body, credentials: 'same-origin', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': TOKEN}})
      .then(function (r) { return r.json().catch(function () { return {ok: false, error: 'Unexpected server response (' + r.status + ').'}; }); });
  }

  /* ------------------------------------------------------------ uploader */

  function Uploader(el) {
    if (el._msUploader) return el._msUploader;
    el._msUploader = this;
    var self = this;
    this.el = el;
    this.kinds = (el.dataset.kinds || 'image,video').split(',');
    this.limits = {
      image: +el.dataset.limitImage || 2097152,
      video: +el.dataset.limitVideo || +el.dataset.limitImage || 2097152,
      document: +el.dataset.limitDocument || +el.dataset.limitImage || 2097152
    };
    this.queue = [];
    this.busy = false;

    var accept = [];
    this.kinds.forEach(function (k) { (EXT[k] || []).forEach(function (e) { accept.push('.' + e); }); });
    this.input = document.createElement('input');
    this.input.type = 'file';
    this.input.multiple = el.dataset.multiple !== '0';
    this.input.accept = accept.join(',');
    this.input.hidden = true;
    el.appendChild(this.input);

    this.list = el.querySelector('[data-upload-list]');
    if (!this.list) {
      this.list = document.createElement('div');
      this.list.className = 'up-list';
      el.appendChild(this.list);
    }
    var zone = el.querySelector('[data-dropzone]') || el;
    zone.addEventListener('click', function (e) {
      if (e.target.closest('button,a,input,.up-row')) return;
      self.input.click();
    });
    zone.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); self.input.click(); } });
    ['dragenter', 'dragover'].forEach(function (t) { zone.addEventListener(t, function (e) { e.preventDefault(); zone.classList.add('drag'); }); });
    ['dragleave', 'drop'].forEach(function (t) { zone.addEventListener(t, function (e) { e.preventDefault(); zone.classList.remove('drag'); }); });
    zone.addEventListener('drop', function (e) { self.add(e.dataTransfer.files); });
    this.input.addEventListener('change', function () { self.add(self.input.files); self.input.value = ''; });
  }

  Uploader.prototype.add = function (files) {
    var self = this;
    Array.prototype.forEach.call(files || [], function (f) {
      var row = document.createElement('div');
      row.className = 'up-row';
      row.innerHTML = '<span class="up-ic"><i data-lucide="file"></i></span><div class="up-main"><div class="up-name">' + esc(f.name) +
        '</div><div class="up-meta">' + human(f.size) + '</div><div class="progress"><span style="width:0%"></span></div></div><span class="up-state">Waiting</span>';
      self.list.appendChild(row);
      var kind = kindOf(f.name);
      var err = null;
      if (!kind || self.kinds.indexOf(kind) === -1) {
        err = 'Not an allowed type (' + self.kinds.map(function (k) { return (EXT[k] || []).join(', '); }).join(', ') + ').';
      } else if (f.size > self.limits[kind]) {
        err = 'Too large: ' + human(f.size) + ' (max ' + human(self.limits[kind]) + ').';
      }
      if (err) { self.fail(row, err, f.name); return; }
      self.queue.push({file: f, row: row});
    });
    icons();
    this.next();
  };

  Uploader.prototype.fail = function (row, msg, name) {
    row.classList.add('err');
    row.querySelector('.up-state').textContent = 'Failed';
    row.querySelector('.up-meta').textContent = msg;
    var bar = row.querySelector('.progress span');
    if (bar) bar.style.width = '100%';
    this.el.dispatchEvent(new CustomEvent('ms:upload-error', {detail: {name: name, error: msg}}));
  };

  Uploader.prototype.next = function () {
    if (this.busy || !this.queue.length) {
      if (!this.busy && !this.queue.length) this.el.dispatchEvent(new CustomEvent('ms:idle'));
      return;
    }
    var self = this;
    var job = this.queue.shift();
    var row = job.row;
    var bar = row.querySelector('.progress span');
    var state = row.querySelector('.up-state');
    this.busy = true;
    state.textContent = 'Uploading';

    var fd = new FormData();
    fd.append('file', job.file);
    fd.append('_token', TOKEN);
    fd.append('kinds', this.kinds.join(','));
    ['ownerType', 'ownerId', 'role', 'disk'].forEach(function (k) {
      var v = self.el.dataset[k];
      if (v) fd.append(k.replace(/[A-Z]/g, function (c) { return '_' + c.toLowerCase(); }), v);
    });

    var xhr = new XMLHttpRequest();
    xhr.open('POST', url('/admin/media/upload'));
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-CSRF-TOKEN', TOKEN);
    xhr.upload.onprogress = function (e) { if (e.lengthComputable) bar.style.width = Math.round(e.loaded / e.total * 95) + '%'; };
    xhr.onload = function () {
      var res = null;
      try { res = JSON.parse(xhr.responseText); } catch (e) {}
      if (xhr.status === 413) res = {ok: false, error: 'Larger than the server allows.'};
      if (res && res.ok) {
        bar.style.width = '100%';
        row.classList.add('ok');
        state.textContent = res.duplicate ? 'Already in library' : 'Done';
        var m = res.media;
        var ic = row.querySelector('.up-ic');
        if (m.thumb_url) ic.innerHTML = '<img src="' + esc(m.thumb_url) + '" alt="">';
        row.querySelector('.up-meta').textContent = m.media_no + ' · ' + m.size + (res.duplicate ? ' · same file was already uploaded, reused it' : '');
        self.el.dispatchEvent(new CustomEvent('ms:uploaded', {detail: m}));
        setTimeout(function () { row.classList.add('fade'); setTimeout(function () { row.remove(); }, 400); }, res.duplicate ? 4000 : 1800);
      } else {
        self.fail(row, (res && res.error) || ('Upload failed (' + xhr.status + ').'), job.file.name);
      }
      self.busy = false;
      self.next();
    };
    xhr.onerror = function () { self.fail(row, 'Network error. Check the connection and try again.', job.file.name); self.busy = false; self.next(); };
    xhr.send(fd);
  };

  /* ------------------------------------------------------------ picker */

  var picker = null;

  function buildPicker() {
    var wrap = document.createElement('div');
    wrap.className = 'modal-backdrop';
    wrap.innerHTML =
      '<div class="modal wide mp" role="dialog" aria-modal="true" aria-label="Media library">' +
      '<div class="modal-h"><div><div class="eyebrow">Media library</div><h3>Pick files</h3><p class="text-xs muted mt-1">Reuse files already uploaded. The file is not copied, it is linked.</p></div>' +
      '<button type="button" class="iconbtn" data-mp-close aria-label="Close"><i data-lucide="x"></i></button></div>' +
      '<div class="mp-bar"><input class="btn mp-q" type="search" placeholder="Search media no., name or alt text" aria-label="Search">' +
      '<select class="btn mp-kind" aria-label="Kind"><option value="">All kinds</option><option value="image">Images</option><option value="video">Videos</option></select>' +
      '<select class="btn mp-owner" aria-label="Usage"><option value="">Any usage</option><option value="unused">Unused only</option><option value="product">Used on products</option></select></div>' +
      '<div class="mp-grid"></div><div class="mp-more"></div>' +
      '<div class="modal-f"><span class="text-xs muted mp-count" style="margin-right:auto"></span><button type="button" class="btn" data-mp-close>Cancel</button>' +
      '<button type="button" class="btn gold mp-ok" disabled><i data-lucide="check" style="width:16px"></i>Add selected</button></div></div>';
    document.body.appendChild(wrap);
    var st = {page: 1, pages: 1, sel: {}, resolve: null, opts: {}};
    var grid = wrap.querySelector('.mp-grid');
    var more = wrap.querySelector('.mp-more');
    var q = wrap.querySelector('.mp-q');
    var kind = wrap.querySelector('.mp-kind');
    var owner = wrap.querySelector('.mp-owner');
    var ok = wrap.querySelector('.mp-ok');
    var count = wrap.querySelector('.mp-count');
    var timer = null;

    function sync() {
      var n = Object.keys(st.sel).length;
      ok.disabled = !n;
      count.textContent = n ? n + ' selected' : '';
    }
    function load(reset) {
      if (reset) { st.page = 1; grid.innerHTML = ''; }
      var params = new URLSearchParams({q: q.value, kind: kind.value, owner: owner.value, disk: 'public', page: st.page});
      more.innerHTML = '<span class="text-xs muted">Loading…</span>';
      fetch(url('/admin/media') + '?' + params, {headers: {Accept: 'application/json'}, credentials: 'same-origin'})
        .then(function (r) { return r.json(); })
        .then(function (j) {
          st.pages = j.pages;
          (j.items || []).forEach(function (m) {
            if (st.opts.kinds && st.opts.kinds.indexOf(m.kind) === -1) return;
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'mp-tile' + (st.sel[m.id] ? ' on' : '');
            b.innerHTML = (m.thumb_url ? '<img src="' + esc(m.thumb_url) + '" alt="' + esc(m.alt_text || '') + '" loading="lazy">' : '<span class="mp-ph"><i data-lucide="' + (m.kind === 'video' ? 'film' : 'file') + '"></i></span>') +
              '<span class="mp-cap"><b>' + esc(m.media_no) + '</b>' + esc(m.name || '') + '</span><span class="mp-tick"><i data-lucide="check"></i></span>' +
              (m.used_count ? '<span class="mp-used">' + m.used_count + '×</span>' : '');
            b.onclick = function () {
              if (st.sel[m.id]) delete st.sel[m.id];
              else { if (!st.opts.multiple) { st.sel = {}; grid.querySelectorAll('.mp-tile.on').forEach(function (t) { t.classList.remove('on'); }); } st.sel[m.id] = m; }
              b.classList.toggle('on', !!st.sel[m.id]);
              sync();
            };
            grid.appendChild(b);
          });
          if (!grid.children.length) grid.innerHTML = '<div class="mp-empty muted text-sm">No files match. Upload some first.</div>';
          more.innerHTML = st.page < st.pages ? '<button type="button" class="btn">Load more</button>' : '';
          var btn = more.querySelector('button');
          if (btn) btn.onclick = function () { st.page++; load(false); };
          icons();
        })
        .catch(function () { more.innerHTML = '<span class="text-xs" style="color:#b4452f">Could not load the library.</span>'; });
    }
    function close(result) {
      wrap.classList.remove('show');
      if (st.resolve) st.resolve(result || []);
      st.resolve = null;
    }
    q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { load(true); }, 250); });
    kind.onchange = owner.onchange = function () { load(true); };
    wrap.addEventListener('click', function (e) { if (e.target === wrap || e.target.closest('[data-mp-close]')) close([]); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && wrap.classList.contains('show')) close([]); });
    ok.onclick = function () { close(Object.keys(st.sel).map(function (k) { return st.sel[k]; })); };

    return {
      open: function (opts) {
        st.opts = opts || {};
        st.sel = {};
        sync();
        kind.value = st.opts.kinds && st.opts.kinds.length === 1 ? st.opts.kinds[0] : '';
        kind.disabled = !!(st.opts.kinds && st.opts.kinds.length === 1);
        wrap.classList.add('show');
        load(true);
        setTimeout(function () { q.focus(); }, 50);
        return new Promise(function (res) { st.resolve = res; });
      }
    };
  }

  window.msMedia = {
    uploader: function (el) { return new Uploader(el); },
    pick: function (opts) { picker = picker || buildPicker(); return picker.open(opts); },
    post: post,
    url: url,
    esc: esc
  };

  document.querySelectorAll('[data-uploader]').forEach(function (el) { new Uploader(el); });
})();
