</main>
<div class="toast" id="toast"></div>
<form method="post" id="msDelForm" class="hidden"><?= csrf_field() ?></form>
<script>
lucide.createIcons();
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
  if((e.metaKey||e.ctrlKey)&&e.key.toLowerCase()==='k'){var s=document.getElementById('globalSearch');if(s){e.preventDefault();s.focus();}}
  if(e.key==='Escape')document.body.classList.remove('sb-open');
});
</script>
<?= $scripts ?? '' ?>
</body>
</html>
