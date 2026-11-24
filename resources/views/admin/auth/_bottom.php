  </section>
</div>
<script>
lucide.createIcons();
// Show / hide password buttons + one submit per form.
document.querySelectorAll('[data-reveal]').forEach(function(b){
  b.addEventListener('click',function(){var i=document.getElementById(b.dataset.reveal);var show=i.type==='password';i.type=show?'text':'password';b.setAttribute('aria-pressed',show?'true':'false');b.setAttribute('aria-label',show?'Hide password':'Show password');});
});
document.querySelectorAll('form').forEach(function(f){f.addEventListener('submit',function(){var s=f.querySelector('button[type=submit]');if(s){setTimeout(function(){s.disabled=true},0);}});});
</script>
<?= $scripts ?? '' ?>
</body>
</html>
