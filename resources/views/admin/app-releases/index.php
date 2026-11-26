<?php
/**
 * Settings → Android app (docs/mobile-app.md phase 3; App\Services\AppReleases).
 * @var list<array> $rows @var string $playUrl @var int $maxMb @var string $serverMax
 */

$active = 'settings.app';
$title = 'Android app';
$when = static fn($v): string => $v ? date('j M Y, g:i a', (int) strtotime((string) $v)) : '—';
$mb = static fn($b): string => number_format(((int) $b) / 1048576, 1) . ' MB';
$tone = ['published' => 'green', 'draft' => 'amber', 'archived' => 'grey'];
$label = ['published' => 'Public', 'draft' => 'Draft', 'archived' => 'Archived'];
$live = null;
foreach ($rows as $r) { if ($r['status'] === 'published') { $live = $r; } }
$nextCode = $rows ? (int) $rows[0]['version_code'] + 1 : 1;

include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs">Settings <i data-lucide="chevron-right"></i> Android app</div>
    <h1>Android app<span style="color:#dca71f">.</span></h1></div>
  <a class="btn" href="<?= e(base_url('/app')) ?>" target="_blank" rel="noopener"><i data-lucide="external-link" style="width:16px"></i>Open /app</a>
</div>

<div class="statstrip">
  <div class="stat glass"><span class="metric-icon" style="background:#e4f4e9;color:#267a55"><i data-lucide="smartphone"></i></span><div><small>Public version</small><b><?= $live ? e($live['version_name']) : '—' ?></b><em><?= $live ? 'code ' . (int) $live['version_code'] . ' · since ' . e(date('j M', (int) strtotime((string) $live['published_at']))) : 'no direct download yet' ?></em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#e3eef8;color:#3d6a92"><i data-lucide="download"></i></span><div><small>Downloads</small><b><?= $live ? (int) $live['downloads'] : 0 ?></b><em>of the public APK from /app</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#fbe1dc;color:#b3483a"><i data-lucide="shield-alert"></i></span><div><small>Forced update below</small><b><?= $live && (int) $live['min_supported_code'] > 0 ? (int) $live['min_supported_code'] : 'Off' ?></b><em>minimum supported code</em></div></div>
  <div class="stat glass"><span class="metric-icon" style="background:#f1e8d6;color:#6d5e43"><i data-lucide="store"></i></span><div><small>Google Play</small><b><?= $playUrl !== '' ? 'Linked' : 'Not yet' ?></b><em><?= $playUrl !== '' ? 'badge shown on /app' : 'set APP_PLAY_URL in .env' ?></em></div></div>
</div>

<div class="notice" style="margin-bottom:18px"><b>How a release goes out.</b> Upload the signed release APK (the same build you send to Play) → it’s a <b>Draft</b> that only staff can download → install it on a phone and test → <b>Publish</b>. Publishing makes it the download on <code>/app</code>, archives the previous one and tells sideloaded installs to update. Web changes don’t need a release: they reach the app with the normal deploy.</div>

<div class="grid2" style="align-items:start">
  <form class="card" method="post" action="<?= route('admin.app-releases.store') ?>" enctype="multipart/form-data" style="padding:20px">
    <?= csrf_field() ?>
    <h3 style="margin:0 0 12px">Upload a new version</h3>
    <div class="stack">
      <div class="field"><label for="apk">APK file *</label><input id="apk" type="file" name="apk" accept=".apk,application/vnd.android.package-archive" required>
        <small class="muted">Up to <?= $maxMb ?> MB (server limit <?= e($serverMax) ?>). Not the .aab bundle.</small></div>
      <div class="grid2" style="gap:12px">
        <div class="field"><label for="vname">Version name *</label><input id="vname" name="version_name" required maxlength="40" placeholder="1.0.0" value="<?= e((string) old('version_name', '')) ?>"></div>
        <div class="field"><label for="vcode">Version code *</label><input id="vcode" name="version_code" required inputmode="numeric" pattern="\d+" placeholder="<?= $nextCode ?>" value="<?= e((string) old('version_code', '')) ?>">
          <small class="muted">Higher than <?= $nextCode - 1 ?>.</small></div>
      </div>
      <div class="field"><label for="vmin">Minimum supported code</label><input id="vmin" name="min_supported_code" inputmode="numeric" pattern="\d*" placeholder="0" value="<?= e((string) old('min_supported_code', '')) ?>">
        <small class="muted">Installed builds below this must update before they can be used. 0 = no forced update. Only for breaking changes.</small></div>
      <div class="field"><label for="vlog">What’s new</label><textarea id="vlog" name="changelog" rows="4" maxlength="2000" placeholder="One change per line"><?= e((string) old('changelog', '')) ?></textarea></div>
    </div>
    <div style="margin-top:14px"><button class="btn gold" type="submit"><i data-lucide="upload" style="width:16px"></i>Upload as draft</button></div>
  </form>

  <div class="card" style="padding:20px">
    <h3 style="margin:0 0 12px">Releases</h3>
    <?php if (!$rows): ?>
      <p class="muted">No releases yet. Build the release APK (docs/mobile-app.md, “Release workflow”) and upload it here.</p>
    <?php else: ?>
    <div style="overflow-x:auto"><table class="data">
      <thead><tr><th>Version</th><th>Status</th><th>Size</th><th>Downloads</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $id = (int) $r['id']; ?>
        <tr>
          <td><b><?= e($r['version_name']) ?></b><br><small class="muted">code <?= (int) $r['version_code'] ?> · <?= e($when($r['created_at'])) ?></small></td>
          <td><span class="ord-st ord-<?= $tone[$r['status']] ?? 'grey' ?>"><?= e($label[$r['status']] ?? $r['status']) ?></span><?php if ($r['status'] === 'published'): ?><br><small class="muted"><?= e($when($r['published_at'])) ?></small><?php endif; ?></td>
          <td><?= e($mb($r['size_bytes'])) ?></td>
          <td><?= (int) $r['downloads'] ?></td>
          <td style="white-space:nowrap">
            <a class="btn sm" href="<?= route('admin.app-releases.download', ['id' => $id]) ?>" title="Download to test"><i data-lucide="download" style="width:14px"></i></a>
            <?php if ($r['status'] !== 'published' && (!$live || (int) $live['version_code'] < (int) $r['version_code'])): ?>
            <form method="post" action="<?= route('admin.app-releases.publish', ['id' => $id]) ?>" style="display:inline" onsubmit="return confirm('Publish version <?= e($r['version_name']) ?>? It becomes the download on /app and sideloaded installs are told to update.')"><?= csrf_field() ?><button class="btn sm gold" type="submit">Publish</button></form>
            <?php elseif ($r['status'] === 'published'): ?>
            <form method="post" action="<?= route('admin.app-releases.archive', ['id' => $id]) ?>" style="display:inline" onsubmit="return confirm('Take version <?= e($r['version_name']) ?> down? /app will offer no direct download until you publish another.')"><?= csrf_field() ?><button class="btn sm" type="submit">Take down</button></form>
            <?php endif; ?>
            <?php if ($r['status'] !== 'published'): ?>
            <form method="post" action="<?= route('admin.app-releases.delete', ['id' => $id]) ?>" style="display:inline" onsubmit="return confirm('Delete version <?= e($r['version_name']) ?> and its APK?')"><?= csrf_field() ?><button class="btn sm ghost" type="submit" title="Delete"><i data-lucide="trash-2" style="width:14px"></i></button></form>
            <?php endif; ?>
          </td>
        </tr>
        <tr><td colspan="5" style="padding-top:0">
          <details><summary class="muted" style="cursor:pointer;font-size:12.5px">Details · SHA-256 · edit notes</summary>
            <p style="font-size:12px;word-break:break-all" class="muted">SHA-256 <code><?= e($r['sha256']) ?></code></p>
            <form method="post" action="<?= route('admin.app-releases.update', ['id' => $id]) ?>" class="stack" style="margin-top:8px"><?= csrf_field() ?>
              <div class="field"><label>Minimum supported code</label><input name="min_supported_code" inputmode="numeric" pattern="\d*" value="<?= (int) $r['min_supported_code'] ?>"></div>
              <div class="field"><label>What’s new</label><textarea name="changelog" rows="3" maxlength="2000"><?= e((string) ($r['changelog'] ?? '')) ?></textarea></div>
              <div><button class="btn sm" type="submit">Save</button></div>
            </form>
          </details>
        </td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../partials/footer.php';
