<?php
$launch = $launch ?? '2026-11-25T00:00:00';
$launchLabel = date('F j, Y', strtotime($launch));
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Maa Sarada Marble &amp; Stone — Coming Soon</title>
<meta name="description" content="Maa Sarada Marble &amp; Stone — premium marble and natural stone. Launching <?= e($launchLabel) ?>.">
<link rel="icon" type="image/png" href="<?= base_url('assets/image/favicon.png') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Fraunces:opsz,wght@9..144,500;9..144,600&display=swap" rel="stylesheet">
<style>
:root{--ink:#292821;--muted:#8b806d;--gold:#efb323;--line:#eadcc4}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;font-family:Manrope,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink);background:radial-gradient(circle at 60% 25%,#fff9e9 0,#f1e7d4 48%,#e4d6bf 100%);display:flex;flex-direction:column}
.wrap{flex:1;display:flex;align-items:center;justify-content:center;padding:32px 16px}
.panel{max-width:760px;width:100%;text-align:center;background:rgba(255,255,255,.6);border:1px solid rgba(255,255,255,.95);box-shadow:0 20px 60px rgba(111,85,40,.1),inset 0 1px rgba(255,255,255,.9);backdrop-filter:blur(14px);border-radius:32px;padding:48px 36px}
.brandmark{width:62px;height:62px;border-radius:18px;margin:0 auto 20px;background:linear-gradient(145deg,#ffe99c,#f1b51e);display:grid;place-items:center;box-shadow:0 8px 20px #c89c4355;color:#5a3f06}
.kicker{font-size:12px;font-weight:800;letter-spacing:.22em;text-transform:uppercase;color:#b17a13;margin-bottom:14px}
h1{font-family:Fraunces,Georgia,serif;font-weight:600;font-size:clamp(34px,6vw,56px);line-height:1.05;margin:0 0 8px}
h1 .dot{color:var(--gold)}
.tag{color:var(--muted);font-size:clamp(14px,2.5vw,17px);max-width:460px;margin:0 auto 32px}
.count{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-bottom:30px}
.cell{min-width:82px;background:rgba(255,255,255,.7);border:1px solid var(--line);border-radius:18px;padding:16px 10px}
.cell b{display:block;font-family:Fraunces,serif;font-size:34px;line-height:1;color:#3a3326}
.cell span{display:block;font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-top:8px}
.launch{display:inline-flex;align-items:center;gap:9px;background:#fff0bd;border:1px solid #f0d78c;color:#72500a;font-weight:800;font-size:13px;padding:10px 18px;border-radius:999px}
.foot{padding:20px;text-align:center;color:var(--muted);font-size:12px}
@media(max-width:480px){.panel{padding:34px 20px}.cell{min-width:68px;padding:13px 8px}.cell b{font-size:27px}}
</style>
</head>
<body>
<div class="wrap">
  <div class="panel">
    <div class="brandmark">
      <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12l4 6-10 13L2 9Z"/><path d="M11 3 8 9l4 13 4-13-3-6"/><path d="M2 9h20"/></svg>
    </div>
    <div class="kicker">Maa Sarada · Marble &amp; Stone</div>
    <h1>Something timeless is coming<span class="dot">.</span></h1>
    <p class="tag">Premium marble &amp; natural stone, curated for homes and projects. Our online catalogue opens soon.</p>
    <div class="count" id="count">
      <div class="cell"><b id="d">00</b><span>Days</span></div>
      <div class="cell"><b id="h">00</b><span>Hours</span></div>
      <div class="cell"><b id="m">00</b><span>Minutes</span></div>
      <div class="cell"><b id="s">00</b><span>Seconds</span></div>
    </div>
    <div class="launch">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
      Launching <?= e($launchLabel) ?>
    </div>
  </div>
</div>
<div class="foot">© <?= date('Y') ?> Maa Sarada Marble &amp; Stone. All rights reserved.</div>
<script>
(function(){
  var target=new Date('<?= e($launch) ?>').getTime();
  var el={d:document.getElementById('d'),h:document.getElementById('h'),m:document.getElementById('m'),s:document.getElementById('s')};
  function pad(n){return (n<10?'0':'')+n}
  function tick(){
    var diff=target-Date.now();
    if(diff<=0){document.getElementById('count').innerHTML='<div class="cell" style="min-width:auto;padding:16px 26px"><b style="font-size:24px">We’re live!</b></div>';return;}
    var d=Math.floor(diff/86400000),h=Math.floor(diff/3600000)%24,m=Math.floor(diff/60000)%60,s=Math.floor(diff/1000)%60;
    el.d.textContent=pad(d);el.h.textContent=pad(h);el.m.textContent=pad(m);el.s.textContent=pad(s);
  }
  tick();setInterval(tick,1000);
})();
</script>
</body>
</html>
