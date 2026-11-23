<?php /** Black brand tab (logo or initials). Needs $logo, $business. */ ?>
<div class="brand-mark"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt="<?= e((string) $business['name']) ?>"><?php else: ?><span><?= e(mb_substr((string) $business['name'], 0, 2)) ?></span><?php endif; ?></div>
