<?php
/** Short spec grid under the enquiry / coming-soon box (design: specsTeaser()). @var list<array> $specs */
$want = \App\Services\Store\ProductPage::TEASER;
$rows = array_values(array_filter($specs, fn ($s) => in_array($s['code'], $want, true)));
if ($rows === []) {
    return;
}
?>
<dl class="mt-6 grid grid-cols-2 gap-px bg-ink/5 rounded-2xl overflow-hidden text-[13.5px]">
  <?php foreach ($rows as $s): ?><div class="bg-white px-4 py-3"><dt class="text-ink-400 text-[12px]"><?= e($s['label']) ?></dt><dd class="font-semibold"><?= e($s['value']) ?></dd></div><?php endforeach; ?>
</dl>
