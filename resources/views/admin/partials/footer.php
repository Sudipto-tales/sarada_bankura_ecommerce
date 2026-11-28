</main>
<div class="toasts" id="msToasts" role="region" aria-label="Notifications" aria-live="polite"></div>
<form method="post" id="msDelForm" class="hidden"><?= csrf_field() ?></form>
<?php
// Every flashed message becomes a toast, so controllers only need session()->flash('success'|'error'|'warning'|'info', …).
$msFlash = [];
foreach (['success', 'error', 'warning', 'info'] as $msTone) {
    $msVal = session()->get($msTone);
    if (is_string($msVal) && $msVal !== '') $msFlash[] = ['type' => $msTone, 'message' => $msVal];
}
$msErrCount = count(errors());
if ($msErrCount) $msFlash[] = ['type' => 'warning', 'title' => 'Check the form', 'message' => $msErrCount . ' field' . ($msErrCount === 1 ? ' needs' : 's need') . ' attention — see the highlighted inputs.'];
?>
<script>
lucide.createIcons();
// ---- Toasts: msToast('Saved') · msToast.error('Failed', {title, duration, action:{label,href}}) · msToast({type,title,message}).
(function(){
  var box=document.getElementById('msToasts');
  var ICON={success:'circle-check',error:'circle-alert',warning:'triangle-alert',info:'info'};
  var TITLE={success:'Done',error:'Couldn\'t complete that',warning:'Heads up',info:'For your information'};
  function make(tag,cls,text){var el=document.createElement(tag);if(cls)el.className=cls;if(text!=null)el.textContent=text;return el;}
  function close(t){if(t.dataset.closing)return;t.dataset.closing=1;clearTimeout(t._timer);t.classList.add('out');setTimeout(function(){t.remove();},230);}
  function toast(o,opts){
    if(typeof o==='string')o=Object.assign({message:o},opts||{});
    var type=ICON[o.type]?o.type:'success';
    // errors stay until dismissed; everything else fades after a few seconds (longer for long messages)
    var dur=o.duration!=null?o.duration:(type==='error'?0:Math.min(9000,4200+(o.message||'').length*25));
    var t=make('div','tst '+type);t.setAttribute('role',type==='error'?'alert':'status');
    var ic=make('div','tst-ic');ic.innerHTML='<i data-lucide="'+ICON[type]+'"></i>';
    var body=make('div','tst-body');body.appendChild(make('div','tst-title',o.title||TITLE[type]));
    if(o.message)body.appendChild(make('div','tst-msg',o.message));
    if(o.action&&o.action.href){var a=make('a','tst-act',o.action.label||'View');a.href=o.action.href;a.insertAdjacentHTML('beforeend','<i data-lucide="arrow-right" style="width:13px;height:13px"></i>');body.appendChild(a);}
    var x=make('button','tst-x');x.type='button';x.setAttribute('aria-label','Dismiss');x.innerHTML='<i data-lucide="x"></i>';x.onclick=function(){close(t);};
    t.append(ic,body,x);
    if(dur>0){
      var bar=make('div','tst-bar');bar.style.animationDuration=dur+'ms';t.appendChild(bar);
      // the bar drives the timer, so hovering (which pauses the bar) also pauses the dismiss
      bar.addEventListener('animationend',function(){close(t);});
    }
    box.appendChild(t);
    while(box.children.length>4)close(box.firstElementChild);
    if(window.lucide)lucide.createIcons();
    return {close:function(){close(t);}};
  }
  ['success','error','warning','info'].forEach(function(k){toast[k]=function(m,o){return toast(Object.assign({},o||{},{type:k,message:m}));};});
  window.msToast=toast;
  var flash=<?= json_encode($msFlash, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  flash.forEach(function(f,i){setTimeout(function(){toast(f);},120+i*140);});
})();
function msToggleSidebar(){
  var r=document.documentElement; r.classList.toggle('sb-mini');
  try{localStorage.setItem('ms.sidebar',r.classList.contains('sb-mini')?'mini':'full')}catch(e){}
}
// Buttons with data-del="<url>" POST to that URL after a confirm (uses the shared CSRF form above).
function msBindDelete(){
  document.querySelectorAll('[data-del]').forEach(function(b){
    b.addEventListener('click',function(){
      if(!confirm('Delete "'+(b.dataset.delName||'this item')+'"? This cannot be undone.'))return;
      var f=document.getElementById('msDelForm');f.action=b.dataset.del;f.submit();
    });
  });
}
// Client-side table filtering: selects map row data-* keys; archived rows stay hidden unless asked for.
function msFilterTable(tableId,selects,searchId,archivedId,countId,noun){
  var t=document.getElementById(tableId);if(!t)return;
  var rows=[].slice.call(t.querySelectorAll('tbody tr:not(.empty-row)')),empty=t.querySelector('.empty-row');
  function run(){
    var q=(document.getElementById(searchId)||{value:''}).value.trim().toLowerCase(),arch=document.getElementById(archivedId),n=0;
    rows.forEach(function(r){
      var ok=true;
      Object.keys(selects).forEach(function(k){var v=document.getElementById(selects[k]).value;if(v&&r.dataset[k]!==v)ok=false;});
      var wantsArchived=(selects.status&&document.getElementById(selects.status).value==='archived')||(arch&&arch.checked);
      if(r.dataset.status==='archived'&&!wantsArchived)ok=false;
      if(q&&(r.dataset.search||'').indexOf(q)<0)ok=false;
      r.classList.toggle('hidden',!ok);if(ok)n++;
    });
    if(empty)empty.classList.toggle('hidden',n>0);
    var c=document.getElementById(countId);if(c)c.textContent=n+' matching '+noun+(n===1?'':'s');
  }
  Object.keys(selects).forEach(function(k){document.getElementById(selects[k]).addEventListener('change',run);});
  ['input','change'].forEach(function(ev){[searchId,archivedId].forEach(function(id){var x=document.getElementById(id);if(x)x.addEventListener(ev,run);});});
  run();
}
// Download the visible rows of a table as CSV (columns marked data-noexport are skipped).
function msExportTable(tableId,filename){
  var t=document.getElementById(tableId);if(!t)return;
  var skip=[].map.call(t.querySelectorAll('thead th'),function(th){return th.hasAttribute('data-noexport');});
  var out=[].filter.call(t.querySelectorAll('tr'),function(r){return !r.classList.contains('hidden')&&!r.classList.contains('empty-row');}).map(function(r){
    return [].filter.call(r.children,function(c,i){return !skip[i];}).map(function(c){
      var s=c.innerText.replace(/\s+/g,' ').trim();return '"'+s.replace(/"/g,'""')+'"';
    }).join(',');
  }).join('\n');
  var a=document.createElement('a');a.href=URL.createObjectURL(new Blob([out],{type:'text/csv'}));a.download=filename;a.click();
}
document.addEventListener('keydown',function(e){
  var t=e.target,typing=t&&(t.isContentEditable||/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName));
  if(((e.metaKey||e.ctrlKey)&&e.key.toLowerCase()==='k')||(e.key==='/'&&!typing)){var s=document.getElementById('globalSearch');if(s){e.preventDefault();s.focus();s.select();}}
  if(e.key==='Escape')document.body.classList.remove('sb-open');
});
// ---- Global search: live grouped suggestions, recent searches, keyboard navigation (GlobalSearch service).
(function(){
  var form=document.getElementById('gsForm'),inp=document.getElementById('globalSearch'),panel=document.getElementById('gsPanel');if(!form||!inp||!panel)return;
  var body=document.getElementById('gsBody'),all=document.getElementById('gsAll'),url=form.dataset.suggest,KEY='ms.recentSearch';
  var quick=[];try{quick=JSON.parse(document.getElementById('gsQuick').innerHTML)}catch(e){}
  var timer=null,ctl=null,cache={},cur=-1,last='';
  var esc=function(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})};
  function mark(s,q){
    s=esc(s);var w=q.trim().split(/\s+/).filter(function(x){return x.length>1}).map(function(x){return esc(x).replace(/[.*+?^${}()|[\]\\]/g,'\\$&')});
    return w.length?s.replace(new RegExp('('+w.join('|')+')','gi'),'<mark>$1</mark>'):s;
  }
  function recent(){try{return JSON.parse(localStorage.getItem(KEY)||'[]')}catch(e){return[]}}
  function remember(q){q=q.trim();if(q.length<2)return;try{var r=recent().filter(function(x){return x.toLowerCase()!==q.toLowerCase()});r.unshift(q);localStorage.setItem(KEY,JSON.stringify(r.slice(0,6)))}catch(e){}}
  function item(it,q,ic){
    var lead=it.thumb?'<img src="'+esc(it.thumb)+'" alt="" loading="lazy">':(it.initials?'<b>'+esc(it.initials)+'</b>':'<i data-lucide="'+esc(it.icon||ic||'circle')+'"></i>');
    return '<a class="gs-item" role="option" href="'+esc(it.url)+'"'+(it.fill?' data-fill="'+esc(it.fill)+'"':'')+'><span class="gs-lead">'+lead+'</span><span class="gs-txt"><span class="gs-t">'+mark(it.title,q)+'</span>'
      +(it.sub?'<span class="gs-s">'+mark(it.sub,q)+'</span>':'')+'</span>'+(it.badge?'<span class="gs-b '+esc(it.tone||'off')+'">'+esc(it.badge)+'</span>':'<i data-lucide="corner-down-left" class="gs-go"></i>')+'</a>';
  }
  function section(label,icon,items,q,more){
    return '<div class="gs-sec"><div class="gs-h"><i data-lucide="'+esc(icon)+'"></i>'+esc(label)+(more?'<a href="'+esc(more)+'" tabindex="-1">See all</a>':'')+'</div>'+items.map(function(it){return item(it,q,icon)}).join('')+'</div>';
  }
  function paint(html){body.innerHTML=html;cur=-1;if(window.lucide)lucide.createIcons();}
  function idle(){
    var r=recent(),h='';
    if(r.length)h+='<div class="gs-sec"><div class="gs-h"><i data-lucide="history"></i>Recent searches<button type="button" class="gs-clear" tabindex="-1">Clear</button></div>'
      +r.map(function(x){return item({title:x,url:form.action+'?q='+encodeURIComponent(x),icon:'history',fill:x},'')}).join('')+'</div>';
    if(quick.length)h+='<div class="gs-sec"><div class="gs-h"><i data-lucide="zap"></i>Quick actions</div><div class="gs-quick">'
      +quick.map(function(x){return '<a class="gs-item gs-chip" role="option" href="'+esc(x.url)+'"><i data-lucide="'+esc(x.icon)+'"></i>'+esc(x.title)+'</a>'}).join('')+'</div></div>';
    h+='<div class="gs-tips"><b>Try:</b> an order number <code>0012</code>, a phone <code>98300…</code>, a SKU, a customer or a page like <code>low stock</code>.</div>';
    paint(h);
  }
  function results(j){
    var q=j.q,keys=Object.keys(j.groups||{});
    if(!keys.length){paint('<div class="gs-empty"><i data-lucide="search-x"></i><b>No matches for “'+esc(q)+'”</b><span>Check the spelling, or try a phone number, SKU or order number.</span></div>');return;}
    paint(keys.map(function(k){var g=j.groups[k];return section(g.label,g.icon,g.items,q,g.more)}).join(''));
  }
  function open(){panel.hidden=false;inp.setAttribute('aria-expanded','true');form.classList.add('is-open');}
  function close(){panel.hidden=true;inp.setAttribute('aria-expanded','false');form.classList.remove('is-open');cur=-1;}
  function run(){
    var q=inp.value.trim();all.href=form.action+(q?'?q='+encodeURIComponent(q):'');
    if(q.length<2){last='';idle();return;}
    if(q===last)return;last=q;
    if(cache[q]){results(cache[q]);return;}
    if(ctl)ctl.abort();ctl=window.AbortController?new AbortController():null;form.classList.add('is-busy');
    fetch(url+'?q='+encodeURIComponent(q),{headers:{Accept:'application/json'},credentials:'same-origin',signal:ctl?ctl.signal:undefined})
      .then(function(r){if(!r.ok)throw r;return r.json()})
      .then(function(j){cache[q]=j;if(inp.value.trim()===q)results(j);})
      .catch(function(e){if(e&&e.name==='AbortError')return;last='';paint('<div class="gs-empty"><i data-lucide="wifi-off"></i><b>Search is unavailable</b><span>Press Enter to search on the results page.</span></div>');})
      .then(function(){form.classList.remove('is-busy')});
  }
  function opts(){return [].slice.call(body.querySelectorAll('.gs-item'))}
  function move(d){var o=opts();if(!o.length)return;if(cur>=0&&o[cur])o[cur].classList.remove('is-cur');cur=(cur+d+o.length)%o.length;o[cur].classList.add('is-cur');o[cur].scrollIntoView({block:'nearest'});}
  inp.addEventListener('focus',function(){open();run();});
  inp.addEventListener('input',function(){open();clearTimeout(timer);timer=setTimeout(run,180);});
  inp.addEventListener('keydown',function(e){
    if(e.key==='ArrowDown'){e.preventDefault();open();move(1);}
    else if(e.key==='ArrowUp'){e.preventDefault();move(-1);}
    else if(e.key==='Escape'){if(!panel.hidden){e.stopPropagation();close();}else inp.blur();}
    else if(e.key==='Enter'){var o=opts()[cur];if(o&&!panel.hidden){e.preventDefault();if(o.dataset.fill){inp.value=o.dataset.fill;remember(o.dataset.fill);form.submit();}else{remember(inp.value);location.href=o.href;}}else remember(inp.value);}
  });
  body.addEventListener('mousedown',function(e){if(e.target.closest('a,button'))e.preventDefault();});   // keep focus in the box
  body.addEventListener('click',function(e){
    if(e.target.closest('.gs-clear')){try{localStorage.removeItem(KEY)}catch(x){}idle();return;}
    var a=e.target.closest('.gs-item');if(a&&a.dataset.fill){e.preventDefault();inp.value=a.dataset.fill;remember(a.dataset.fill);form.submit();}else if(a)remember(inp.value);
  });
  form.addEventListener('submit',function(){remember(inp.value)});
  document.addEventListener('mousedown',function(e){if(!form.contains(e.target))close();});
})();
// ---- Notification bell panel: feed every 60s (paused while the tab is hidden), tabs, mark read, dismiss.
(function(){
  var bell=document.getElementById('nfBell'),panel=document.getElementById('nfPanel');if(!bell||!panel)return;
  var list=document.getElementById('nfList'),badge=document.getElementById('nfBadge'),tab='all',timer=null,lastUnread=null;
  var token=(document.querySelector('meta[name=csrf-token]')||{}).content||'';
  var esc=function(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})};
  var empty={all:['You’re all caught up','New stock alerts and updates will show up here.'],unread:['No unread notifications','You’ve seen everything.'],stock:['No stock alerts','Low and out-of-stock alerts appear here.'],system:['No system updates','Bulk uploads and checks report here.']};
  function post(url){return fetch(url,{method:'POST',headers:{'X-CSRF-TOKEN':token,'Accept':'application/json'},credentials:'same-origin'}).then(function(r){return r.json()})}
  function setBadge(n){
    lastUnread=n;badge.textContent=n>9?'9+':String(n);badge.classList.toggle('hidden',!n);
    bell.setAttribute('aria-label','Notifications'+(n?', '+n+' unread':''));
  }
  function setCounts(c){panel.querySelectorAll('[data-count]').forEach(function(s){var v=c[s.dataset.count]||0;s.textContent=v?String(v):''})}
  function render(items){
    if(!items.length){var m=empty[tab];list.innerHTML='<div class="nf-empty"><div class="nf-bell"><i data-lucide="bell-ring"></i></div><b>'+m[0]+'</b><small>'+m[1]+'</small></div>';lucide.createIcons();return}
    var html='',group='';
    items.forEach(function(n){
      if(n.group!==group){group=n.group;html+='<div class="nf-group">'+esc(group)+'</div>'}
      html+='<a class="nf-item'+(n.is_read?'':' unread')+'" href="'+esc(n.open)+'" data-id="'+n.id+'" data-read="'+esc(n.read)+'" data-delete="'+esc(n.delete)+'">'
        +'<span class="nf-ic '+esc(n.level)+'"><i data-lucide="'+esc(n.icon)+'"></i></span>'
        +'<span class="nf-txt"><b>'+esc(n.title)+'</b>'+(n.body?'<small>'+esc(n.body)+'</small>':'')
        +(n.action?'<span class="nf-act" data-go="'+esc(n.action.open)+'"><i data-lucide="package-plus"></i>'+esc(n.action.label)+'</span>':'')+'</span>'
        +'<span class="nf-side"><span class="nf-ago" title="'+esc(n.when)+'">'+esc(n.ago)+'</span>'+(n.is_read?'':'<span class="nf-dot" aria-label="Unread"></span>')
        +'<button type="button" class="nf-x" title="Dismiss" aria-label="Dismiss notification"><i data-lucide="x"></i></button></span></a>';
    });
    list.innerHTML=html;lucide.createIcons();
  }
  function load(countOnly){
    var u=bell.dataset.feed+'?tab='+tab+(countOnly?'&count_only=1':'');
    if(!countOnly&&!list.children.length)list.innerHTML='<div class="nf-skel"></div><div class="nf-skel"></div><div class="nf-skel"></div>';
    return fetch(u,{headers:{Accept:'application/json'},credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){
      var changed=lastUnread!==null&&j.unread!==lastUnread;
      setBadge(j.unread);setCounts(j.counts);
      if(j.items)render(j.items);else if(changed&&!panel.hidden)load(false);
    }).catch(function(){if(!countOnly)list.innerHTML='<div class="nf-empty"><b>Couldn’t load notifications</b><small>Check your connection and try again.</small></div>'});
  }
  function open(){panel.hidden=false;bell.setAttribute('aria-expanded','true');load(false)}
  function close(focusBell){panel.hidden=true;bell.setAttribute('aria-expanded','false');if(focusBell)bell.focus()}
  bell.addEventListener('click',function(e){e.stopPropagation();panel.hidden?open():close(false)});
  document.addEventListener('click',function(e){if(!panel.hidden&&!panel.contains(e.target)&&e.target!==bell)close(false)});
  document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!panel.hidden){e.preventDefault();close(true)}});
  panel.addEventListener('keydown',function(e){
    if(e.key==='ArrowDown'||e.key==='ArrowUp'){
      var items=[].slice.call(list.querySelectorAll('.nf-item'));if(!items.length)return;
      var i=items.indexOf(document.activeElement);e.preventDefault();
      i=e.key==='ArrowDown'?Math.min(items.length-1,i+1):Math.max(0,i-1);items[i].focus();
    }
  });
  bell.addEventListener('keydown',function(e){if(e.key==='ArrowDown'&&!panel.hidden){e.preventDefault();var f=list.querySelector('.nf-item');if(f)f.focus()}});
  panel.querySelectorAll('[data-tab]').forEach(function(b){b.addEventListener('click',function(){
    tab=b.dataset.tab;panel.querySelectorAll('[data-tab]').forEach(function(x){var on=x===b;x.classList.toggle('on',on);x.setAttribute('aria-selected',on?'true':'false')});
    list.innerHTML='';load(false);
  })});
  document.getElementById('nfReadAll').addEventListener('click',function(){post(this.dataset.url).then(function(){load(false)})});
  list.addEventListener('click',function(e){
    var item=e.target.closest('.nf-item');if(!item)return;
    if(e.target.closest('.nf-x')){
      e.preventDefault();e.stopPropagation();item.style.opacity='.4';
      post(item.dataset.delete).then(function(){return load(false)}).then(function(){var f=list.querySelector('.nf-item');(f||panel.querySelector('.nf-foot')).focus()});return;
    }
    var go=e.target.closest('[data-go]');
    if(go){e.preventDefault();location.href=go.dataset.go}
    // otherwise the link itself opens /notifications/{id}/open, which marks it read and redirects
  });
  function schedule(){clearInterval(timer);timer=setInterval(function(){if(!document.hidden)load(panel.hidden)},60000)}
  document.addEventListener('visibilitychange',function(){if(!document.hidden){load(panel.hidden);schedule()}});
  schedule();
  window.msNotes={refresh:function(){return load(panel.hidden)}};
})();
</script>
<?= $scripts ?? '' ?>
</body>
</html>
