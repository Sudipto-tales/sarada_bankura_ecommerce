<?php $docTitle = 'Not found'; $business = business(); include __DIR__ . '/_head.php'; ?>
<body>
<main class="pub" style="text-align:center;padding-top:80px">
  <div class="pub-card"><h2 class="serif" style="font-size:22px">We couldn’t find that <?= e($what) ?></h2>
    <p class="muted">The link may be incomplete. Please check the message you received, or call <?= e((string) ($business['phone'] ?: $business['name'])) ?>.</p></div>
</main>
</body>
</html>
