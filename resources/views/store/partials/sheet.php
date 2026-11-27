<?php
/**
 * Bottom sheet (docs/mobile-app.md; sheet in store.js). Opened by any [data-sheet-open="<id>"],
 * closed by [data-sheet-close], Escape, the back button, or dragging the handle down. JS only:
 * every trigger is a link or control that still works without it.
 * @var string $id @var string $title @var string $body (HTML) @var string $foot (HTML, optional)
 */
$foot ??= '';
?>
<div class="sheet" id="<?= e($id) ?>" role="dialog" aria-modal="true" aria-labelledby="<?= e($id) ?>-t" hidden>
  <div class="sheet-scrim" data-sheet-close></div>
  <div class="sheet-panel" tabindex="-1">
    <div class="sheet-head" data-sheet-drag>
      <span class="sheet-grip" aria-hidden="true"></span>
      <div class="flex items-center justify-between gap-3 px-5 pb-2">
        <h2 id="<?= e($id) ?>-t" class="font-display text-[22px] leading-tight"><?= e($title) ?></h2>
        <button type="button" data-sheet-close class="w-9 h-9 -mr-2 rounded-full grid place-items-center hover:bg-ink/5" aria-label="Close"><?= store_icon('x') ?></button>
      </div>
    </div>
    <div class="sheet-body"><?= $body ?></div>
    <?php if ($foot !== ''): ?><div class="sheet-foot"><?= $foot ?></div><?php endif; ?>
  </div>
</div>
