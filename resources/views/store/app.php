<?php
/**
 * Get the app (docs/mobile-app.md phase 3). Play badge first, direct APK second, then version,
 * changelog, SHA-256 and sideload steps. Desktop visitors get a QR code to open this page on a phone.
 * @var array|null $release the published release @var string $playUrl
 */
use App\Services\Store\AppMode;

$ua = (string) (request()?->header('User-Agent') ?? '');
$inApp = AppMode::detect($ua);
$mb = $release ? number_format(((int) $release['size_bytes']) / 1048576, 1) . ' MB' : '';
$notes = $release ? array_values(array_filter(array_map('trim', explode("\n", (string) ($release['changelog'] ?? ''))))) : [];
?>
<section class="wrap pt-8 pb-12">
  <div class="grid lg:grid-cols-[minmax(0,1fr),360px] gap-8 items-start">
    <div>
      <p class="eyebrow">Android app</p>
      <h1 class="font-display text-[38px] sm:text-[48px] leading-[1.05] tracking-tight mt-1"><?= e(business('name')) ?>,<br>on your phone</h1>
      <p class="text-ink-500 mt-3 max-w-xl text-[16px]">Browse every stone, save the ones you like, order and pay, and follow your delivery. It works on a weak signal too: pages you’ve opened stay available offline.</p>

      <?php if ($inApp !== null): ?>
      <p class="mt-6 rounded-2xl bg-teal-50 text-teal px-5 py-4 text-[14.5px] font-semibold flex gap-2"><?= store_icon('check-circle-2', 'shrink-0 mt-0.5') ?>
        <span>You’re using the app (version <?= e($inApp) ?>).<?= $release && $release['version_name'] !== $inApp ? ' The latest is ' . e($release['version_name']) . '.' : '' ?></span></p>
      <?php endif; ?>

      <?php if ($playUrl === '' && $release === null): ?>
      <div class="mt-6 bg-white rounded-[24px] border border-ink/5 p-6 max-w-xl">
        <h2 class="font-semibold text-[16px] flex items-center gap-2"><?= store_icon('hourglass', 'text-gold') ?> Coming soon</h2>
        <p class="text-ink-500 text-[14px] mt-1">The app isn’t out yet. Until then, everything works here on the website.</p>
      </div>
      <?php else: ?>
      <div class="mt-6 flex flex-wrap gap-3">
        <?php if ($playUrl !== ''): ?>
        <a href="<?= e($playUrl) ?>" rel="noopener" class="btn btn-dark !h-14 !px-6"><?= store_icon('play') ?> <span class="leading-tight text-left"><small class="block text-[10.5px] font-normal opacity-80">GET IT ON</small>Google Play</span></a>
        <?php endif; ?>
        <?php if ($release !== null): ?>
        <a href="<?= e(store_url('/app/download')) ?>" class="btn <?= $playUrl !== '' ? 'btn-line' : 'btn-dark' ?> !h-14 !px-6" download data-no-prefetch><?= store_icon('download') ?> <span class="leading-tight text-left"><small class="block text-[10.5px] font-normal opacity-80">DIRECT DOWNLOAD</small>APK · <?= e($mb) ?></span></a>
        <?php endif; ?>
      </div>
      <p class="mt-3 text-[13px] text-ink-400">Needs Android 7 or newer.<?= $playUrl !== '' && $release !== null ? ' Google Play is easier and updates by itself; the direct download is for phones without Play.' : '' ?></p>
      <?php endif; ?>

      <?php if ($release !== null): ?>
      <div class="mt-8 grid md:grid-cols-2 gap-4 max-w-3xl">
        <section class="bg-white rounded-[24px] border border-ink/5 p-6">
          <h2 class="font-semibold text-[16px]">What’s new in <?= e($release['version_name']) ?></h2>
          <p class="text-[12.5px] text-ink-400 mt-0.5">Released <?= e(date('j F Y', (int) strtotime((string) $release['published_at']))) ?> · <?= e($mb) ?></p>
          <?php if ($notes): ?><ul class="mt-3 space-y-1.5 text-[14px] text-ink-500 list-disc pl-5"><?php foreach ($notes as $n): ?><li><?= e(ltrim($n, "-•* \t")) ?></li><?php endforeach; ?></ul>
          <?php else: ?><p class="mt-3 text-[14px] text-ink-500">Improvements and fixes.</p><?php endif; ?>
        </section>
        <section class="bg-white rounded-[24px] border border-ink/5 p-6">
          <h2 class="font-semibold text-[16px]">Installing the direct download</h2>
          <ol class="mt-3 space-y-1.5 text-[14px] text-ink-500 list-decimal pl-5">
            <li>Tap <b class="text-ink">Download APK</b> and open the file when it’s done.</li>
            <li>If Android asks, allow <b class="text-ink">Install unknown apps</b> for your browser. This is a one-time step.</li>
            <li>Tap <b class="text-ink">Install</b>, then open the app.</li>
            <li>When a new version is out, the app tells you and brings you back here.</li>
          </ol>
        </section>
      </div>
      <details class="mt-4 max-w-3xl text-[13px] text-ink-500">
        <summary class="cursor-pointer font-semibold text-ink">Check the file (SHA-256)</summary>
        <p class="mt-2">Version <?= e($release['version_name']) ?> (code <?= (int) $release['version_code'] ?>). The APK’s SHA-256 fingerprint is:</p>
        <code class="selectable block mt-1 p-3 rounded-xl bg-white border border-ink/5 break-all text-[12px]"><?= e($release['sha256']) ?></code>
      </details>
      <?php endif; ?>
    </div>

    <?php if ($playUrl !== '' || $release !== null): ?>
    <aside class="hidden lg:block bg-white rounded-[28px] border border-ink/5 p-7 text-center sticky top-[92px]">
      <div id="appQr" class="mx-auto w-[184px] h-[184px] grid place-items-center" data-url="<?= e(base_url('/app')) ?>" aria-hidden="true"></div>
      <p class="mt-4 font-semibold">On a computer?</p>
      <p class="text-[13.5px] text-ink-500 mt-1">Scan this with your phone’s camera to open this page there.</p>
    </aside>
    <?php endif; ?>
  </div>
</section>
