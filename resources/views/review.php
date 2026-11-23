<?php
/** Public review form. Standalone page; the storefront embeds the same form later. */
$p = $product;
$errors = $errors ?? [];
$old = $old ?? [];
$o = static fn(string $k, string $d = ''): string => (string) ($old[$k] ?? $d);
$err = static fn(string $k): string => isset($errors[$k]) ? '<p class="ferr" id="e-' . $k . '">' . e($errors[$k]) . '</p>' : '';
$inv = static fn(string $k): string => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="e-' . $k . '"' : '';
$rating = (int) $o('rating', '0');
$words = ['', 'Poor', 'Fair', 'Good', 'Very good', 'Excellent'];
$limitLabel = \App\Services\Media\MediaService::humanBytes((int) $photoLimit);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Review <?= e($p['name']) ?> · Maa Sarada Marble &amp; Stone</title>
<meta name="description" content="Share how <?= e($p['name']) ?> looks in your home. Reviews appear after a quick check.">
<link rel="icon" type="image/png" href="<?= base_url('assets/image/favicon.png') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--cream:#F6F2EA;--cream-50:#FBF9F5;--cream-200:#EDE6D9;--ink:#15211F;--ink-700:#22302D;--ink-500:#55615E;--ink-400:#7A8481;--ink-300:#A7AEAB;--teal:#1E6E74;--teal-50:#E7F1F1;--copper:#B4643C;--gold:#B8925A}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;background:var(--cream);color:var(--ink);font:15px/1.55 Manrope,system-ui,sans-serif}
a{color:inherit}
.wrap{width:100%;max-width:900px;margin:0 auto;padding:28px 16px 64px}
.back{display:inline-flex;align-items:center;gap:6px;font-size:13.5px;font-weight:600;color:var(--ink-500);text-decoration:none}
.back:hover{color:var(--ink)}
.brandbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:22px}
.brand{display:flex;align-items:center;gap:10px;text-decoration:none;font-family:Fraunces,Georgia,serif;font-size:19px}
.brand img{width:34px;height:34px;border-radius:10px}
.prod{display:flex;align-items:center;gap:16px;margin-top:6px}
.prod .ph{width:84px;height:84px;border-radius:18px;object-fit:cover;background:var(--cream-200);flex-shrink:0;display:grid;place-items:center;color:var(--ink-300)}
.eyebrow{font-size:12px;text-transform:uppercase;letter-spacing:.2em;color:var(--teal);font-weight:600;margin:0}
h1{font-family:Fraunces,Georgia,serif;font-weight:400;font-size:clamp(26px,5vw,36px);line-height:1.12;margin:2px 0 0}
.card{margin-top:24px;background:#fff;border-radius:28px;border:1px solid rgba(21,33,31,.06);padding:clamp(20px,4vw,36px)}
.stack>*+*{margin-top:20px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px 16px}
@media(max-width:640px){.grid{grid-template-columns:1fr}}
.lbl{display:block;font-size:13px;font-weight:600;margin-bottom:6px}
.lbl small{font-weight:400;color:var(--ink-400)}
.req{color:var(--copper)}
.field{width:100%;min-height:46px;padding:0 14px;border-radius:12px;background:var(--cream-50);border:1px solid rgba(21,33,31,.14);outline:none;font:inherit;font-size:15px;color:var(--ink)}
textarea.field{padding:11px 14px;min-height:130px;resize:vertical;line-height:1.5}
select.field{appearance:none;background-image:linear-gradient(45deg,transparent 50%,var(--ink-500) 50%),linear-gradient(135deg,var(--ink-500) 50%,transparent 50%);background-position:calc(100% - 18px) 50%,calc(100% - 13px) 50%;background-size:5px 5px;background-repeat:no-repeat;padding-right:34px}
.field:focus{border-color:var(--teal);box-shadow:0 0 0 4px rgba(30,110,116,.12)}
.field[aria-invalid=true]{border-color:var(--copper);background:#fdf7f3}
.help{font-size:12px;color:var(--ink-400);margin:5px 0 0}
.ferr{font-size:13px;color:var(--copper);margin:6px 0 0;font-weight:600}
.count{float:right;font-weight:500;color:var(--ink-400)}
fieldset{border:0;padding:0;margin:0}
legend{padding:0}
.stars{display:flex;flex-direction:row-reverse;justify-content:flex-end;gap:2px}
.stars input{position:absolute;opacity:0;width:1px;height:1px}
.stars label{font-size:42px;line-height:1;color:rgba(21,33,31,.15);cursor:pointer;padding:0 2px;transition:color .1s,transform .1s}
.stars label:hover,.stars label:hover~label,.stars input:checked~label{color:var(--gold)}
.stars input:focus-visible+label{outline:2px solid var(--teal);outline-offset:2px;border-radius:6px}
.stars label:active{transform:scale(.92)}
.rlabel{font-size:13px;color:var(--ink-500);margin:4px 0 0;min-height:20px}
.photos{border:1.5px dashed rgba(21,33,31,.18);border-radius:14px;background:var(--cream-50);padding:16px}
.photos .pick{display:flex;align-items:center;justify-content:center;gap:8px;min-height:64px;color:var(--ink-500);cursor:pointer;font-weight:600;font-size:14px;border-radius:10px}
.photos .pick:hover{color:var(--ink)}
.photos .pick:focus-within{outline:2px solid var(--teal);outline-offset:2px}
.prevs{display:flex;gap:10px;flex-wrap:wrap}
.prevs:not(:empty){margin-top:12px}
.prev{position:relative;width:88px;height:88px;border-radius:12px;overflow:hidden;background:var(--cream-200)}
.prev img{width:100%;height:100%;object-fit:cover}
.prev button{position:absolute;top:4px;right:4px;width:24px;height:24px;border-radius:50%;border:0;background:rgba(255,255,255,.92);cursor:pointer;font-size:15px;line-height:1;color:var(--ink)}
.plain-files input{display:block;margin-top:8px;font:inherit;font-size:13px}
.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:50px;padding:0 26px;border-radius:999px;font:inherit;font-weight:600;font-size:15px;border:0;cursor:pointer;text-decoration:none;transition:.15s}
.btn-dark{background:var(--ink);color:var(--cream)}
.btn-dark:hover{background:var(--ink-700)}
.btn-dark:disabled{opacity:.55;cursor:progress}
.btn-block{width:100%}
.alert{border-radius:14px;padding:12px 14px;font-size:14px;background:#fdf1ea;color:#7c3c1d;border:1px solid #f0d2c0}
.note{font-size:12.5px;color:var(--ink-400);text-align:center;margin:12px 0 0}
.thanks{text-align:center;padding:clamp(26px,6vw,48px)}
.tick{width:60px;height:60px;margin:0 auto;border-radius:50%;background:var(--teal);color:#fff;display:grid;place-items:center;font-size:30px}
.thanks h2{font-family:Fraunces,Georgia,serif;font-weight:400;font-size:32px;margin:16px 0 4px}
.thanks p{color:var(--ink-500);margin:0 0 22px}
footer{margin-top:28px;text-align:center;font-size:12.5px;color:var(--ink-400)}
@media(prefers-reduced-motion:reduce){*{transition:none!important}}
</style>
</head>
<body>
<main class="wrap" id="review">
  <div class="brandbar">
    <a class="brand" href="<?= base_url('') ?>"><img src="<?= base_url('assets/image/favicon.png') ?>" alt="">Maa Sarada</a>
    <a class="back" href="<?= base_url('') ?>">← Back to the store</a>
  </div>

  <div class="prod">
    <?php if ($p['image']): ?><img class="ph" src="<?= e($p['image']) ?>" alt="<?= e($p['name']) ?>"><?php else: ?><span class="ph" aria-hidden="true">◇</span><?php endif; ?>
    <div><p class="eyebrow">Write a review</p><h1><?= e($p['name']) ?></h1></div>
  </div>

  <?php if ($thanks): ?>
  <section class="card thanks" id="thanks" aria-live="polite">
    <div class="tick" aria-hidden="true">✓</div>
    <h2>Thank you!</h2>
    <p>Your review will appear on the page once we have checked it, usually within a day.</p>
    <a class="btn btn-dark" href="<?= base_url('') ?>">Back to the store</a>
  </section>
  <?php else: ?>
  <form class="card stack" id="f" method="post" action="<?= e(route('review.submit', ['slug' => $p['slug']])) ?>" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="ts" value="<?= e($stamp) ?>">
    <div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

    <?php if (isset($errors['form'])): ?><div class="alert" role="alert"><?= e($errors['form']) ?></div>
    <?php elseif ($errors): ?><div class="alert" role="alert">Please check the highlighted fields.</div><?php endif; ?>

    <fieldset<?= isset($errors['rating']) ? ' aria-describedby="e-rating"' : '' ?>>
      <legend class="lbl">Your rating <span class="req">*</span></legend>
      <div class="stars" role="radiogroup" aria-label="Rating">
        <?php for ($i = 5; $i >= 1; $i--): ?>
        <input type="radio" id="r<?= $i ?>" name="rating" value="<?= $i ?>"<?= $rating === $i ? ' checked' : '' ?>><label for="r<?= $i ?>" title="<?= $words[$i] ?>"><span aria-hidden="true">★</span><span class="sr"><?= $i ?> star<?= $i === 1 ? '' : 's' ?>, <?= $words[$i] ?></span></label>
        <?php endfor; ?>
      </div>
      <p class="rlabel" id="rLabel"><?= $rating ? $words[$rating] : 'Tap a star' ?></p>
      <?= $err('rating') ?>
    </fieldset>

    <div>
      <label class="lbl" for="title">Headline <span class="req">*</span></label>
      <input class="field" id="title" name="title" maxlength="80" placeholder="Sum it up in a few words" value="<?= e($o('title')) ?>" required<?= $inv('title') ?>>
      <?= $err('title') ?>
    </div>

    <div>
      <label class="lbl" for="body">Your review <span class="req">*</span> <span class="count" id="bodyCount" aria-live="polite"></span></label>
      <textarea class="field" id="body" name="body" rows="5" minlength="20" maxlength="1000" placeholder="How does it look and feel? How was delivery and fitting?" required<?= $inv('body') ?>><?= e($o('body')) ?></textarea>
      <?= $err('body') ?>
    </div>

    <div class="grid">
      <div>
        <label class="lbl" for="application">Where did you use it?</label>
        <select class="field" id="application" name="application">
          <option value="">Choose…</option>
          <?php foreach ($applications as $a): ?><option<?= $o('application') === $a ? ' selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="lbl" for="city">Town / city</label>
        <input class="field" id="city" name="city" maxlength="64" placeholder="Bankura" autocomplete="address-level2" value="<?= e($o('city')) ?>">
      </div>
      <div>
        <label class="lbl" for="name">Your name <span class="req">*</span></label>
        <input class="field" id="name" name="name" maxlength="120" autocomplete="name" value="<?= e($o('name')) ?>" required<?= $inv('name') ?>>
        <?= $err('name') ?>
      </div>
      <div>
        <label class="lbl" for="contact">Mobile or email <small>(not shown)</small></label>
        <input class="field" id="contact" name="contact" maxlength="160" autocomplete="tel" inputmode="email" value="<?= e($o('contact')) ?>"<?= $inv('contact') ?>>
        <?= $err('contact') ?: '<p class="help">Lets us mark you as a verified buyer.</p>' ?>
      </div>
    </div>

    <div>
      <span class="lbl" id="photosLbl">Photos of your space <small>(optional, up to <?= (int) $maxPhotos ?>, <?= e($limitLabel) ?> each)</small></span>
      <div class="photos" aria-labelledby="photosLbl">
        <div class="plain-files" id="plainFiles">
          <?php for ($i = 1; $i <= $maxPhotos; $i++): ?><input type="file" name="photo<?= $i ?>" accept="image/jpeg,image/png,image/webp" aria-label="Photo <?= $i ?>"><?php endfor; ?>
        </div>
        <label class="pick" id="pick" hidden><input type="file" class="sr" id="pickIn" accept="image/jpeg,image/png,image/webp" multiple><span aria-hidden="true">＋</span><span id="pickTxt">Add up to <?= (int) $maxPhotos ?> photos</span></label>
        <div class="prevs" id="prevs"></div>
      </div>
      <?= $err('photos') ?>
    </div>

    <p class="ferr" id="clientErr" role="alert" hidden></p>
    <button class="btn btn-dark btn-block" type="submit" id="send">Submit review</button>
    <p class="note">Reviews appear after a quick check, usually within a day. We never show your mobile number or email.</p>
  </form>
  <?php endif; ?>
  <footer>Maa Sarada Marble &amp; Stone · <?= e((string) business('address.city', 'Bankura')) ?></footer>
</main>
<?php if (!$thanks): ?>
<script>
(function(){
  var words=<?= json_encode($words) ?>, max=<?= (int) $maxPhotos ?>, limit=<?= (int) $photoLimit ?>;
  var f=document.getElementById('f'), lbl=document.getElementById('rLabel');
  f.querySelectorAll('input[name=rating]').forEach(function(r){r.addEventListener('change',function(){lbl.textContent=words[+r.value];});});
  var body=document.getElementById('body'), cnt=document.getElementById('bodyCount');
  function count(){var n=body.value.trim().length;cnt.textContent=n<20?(20-n)+' more characters':n+' / 1000';}
  body.addEventListener('input',count);count();

  // One "Add photos" picker that fills photo1..photoN (the server reads one file per field).
  var plain=document.getElementById('plainFiles'), slots=plain.querySelectorAll('input[type=file]');
  var pick=document.getElementById('pick'), pickIn=document.getElementById('pickIn'), prevs=document.getElementById('prevs');
  var files=[];
  if(window.DataTransfer){
    plain.hidden=true;pick.hidden=false;
    function sync(){
      slots.forEach(function(s,i){var dt=new DataTransfer();if(files[i])dt.items.add(files[i]);s.files=dt.files;});
      prevs.innerHTML='';
      files.forEach(function(file,i){var d=document.createElement('div');d.className='prev';
        var img=document.createElement('img');img.alt='Photo '+(i+1);img.src=URL.createObjectURL(file);d.appendChild(img);
        var b=document.createElement('button');b.type='button';b.setAttribute('aria-label','Remove photo '+(i+1));b.textContent='×';
        b.onclick=function(){files.splice(i,1);sync();};d.appendChild(b);prevs.appendChild(d);});
      document.getElementById('pickTxt').textContent=files.length>=max?'Maximum '+max+' photos':(files.length?'Add another photo':'Add up to '+max+' photos');
      pickIn.disabled=files.length>=max;
    }
    pickIn.addEventListener('change',function(){
      var bad=[];
      Array.prototype.forEach.call(pickIn.files,function(file){
        if(files.length>=max)return;
        if(!/^image\/(jpeg|png|webp)$/.test(file.type)){bad.push(file.name+' is not a JPG, PNG or WEBP photo');return;}
        if(file.size>limit){bad.push(file.name+' is larger than '+Math.round(limit/1048576*10)/10+' MB');return;}
        files.push(file);
      });
      pickIn.value='';sync();showErr(bad.join('. '));
    });
  }
  var ce=document.getElementById('clientErr');
  function showErr(m){ce.textContent=m;ce.hidden=!m;}
  f.addEventListener('submit',function(e){
    var m=!f.querySelector('input[name=rating]:checked')?'Choose a star rating.':!f.elements.title.value.trim()?'Add a headline.':body.value.trim().length<20?'Write a few more words (20+).':!f.elements.name.value.trim()?'Add your name.':'';
    if(m){e.preventDefault();showErr(m);ce.scrollIntoView({block:'center',behavior:'smooth'});return;}
    var btn=document.getElementById('send');btn.disabled=true;btn.textContent='Sending…';
  });
})();
</script>
<?php endif; ?>
</body>
</html>
