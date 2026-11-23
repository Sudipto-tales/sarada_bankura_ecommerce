<?php
/**
 * Shared uploader (public/assets/js/media.js). Include with $up set:
 *   ['id' => 'galleryUp', 'kinds' => ['image','video'], 'owner_type' => 'product', 'owner_id' => 12,
 *    'role' => null, 'title' => 'Drop images here', 'hint' => '…', 'limits' => ['image' => bytes, 'video' => bytes]]
 * Without owner_* the files only land in the library (the page listens for `ms:uploaded`).
 */
$up = ($up ?? []) + ['id' => 'msUploader', 'kinds' => ['image', 'video'], 'owner_type' => null, 'owner_id' => null, 'role' => null,
    'title' => 'Drop files here or click to choose', 'hint' => null, 'limits' => [], 'compact' => false];
$lim = $up['limits'] + [
    'image' => \App\Services\Media\MediaService::uploadLimit('image'),
    'video' => \App\Services\Media\MediaService::uploadLimit('video'),
    'document' => \App\Services\Media\MediaService::uploadLimit('document'),
];
$human = [\App\Services\Media\MediaService::class, 'humanBytes'];
$exts = [];
foreach ($up['kinds'] as $k) {
    $exts = array_merge($exts, \App\Services\Media\MediaService::extensionsFor($k));
}
$hint = $up['hint'] ?? (strtoupper(implode(', ', array_diff($exts, ['jpeg', 'm4v']))) . ' · images up to ' . $human($lim['image'])
    . (in_array('video', $up['kinds'], true) ? ', videos up to ' . $human($lim['video']) : ''));
?>
<div class="uploader<?= $up['compact'] ? ' compact' : '' ?>" id="<?= e($up['id']) ?>" data-uploader
     data-kinds="<?= e(implode(',', $up['kinds'])) ?>"
     <?php if ($up['owner_type']): ?>data-owner-type="<?= e($up['owner_type']) ?>" data-owner-id="<?= (int) $up['owner_id'] ?>"<?php endif; ?>
     <?php if ($up['role']): ?>data-role="<?= e($up['role']) ?>"<?php endif; ?>
     data-limit-image="<?= (int) $lim['image'] ?>" data-limit-video="<?= (int) $lim['video'] ?>" data-limit-document="<?= (int) $lim['document'] ?>">
  <div class="dropzone" data-dropzone tabindex="0" role="button" aria-label="<?= e($up['title']) ?>">
    <span class="up-cloud"><i data-lucide="upload-cloud"></i></span>
    <b class="text-sm"><?= e($up['title']) ?></b>
    <p class="text-xs muted mt-1"><?= e($hint) ?>. Several at once is fine.</p>
  </div>
  <div class="up-list" data-upload-list aria-live="polite"></div>
</div>
<?php if (empty($GLOBALS['__msMediaJs'])): $GLOBALS['__msMediaJs'] = true;
    $scripts = ($scripts ?? '') . '<script src="' . base_url('assets/js/media.js') . '?v=' . (int) @filemtime(app()->publicPath('assets/js/media.js')) . '"></script>';
endif; ?>
