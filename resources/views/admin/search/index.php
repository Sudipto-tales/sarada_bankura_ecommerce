<?php
/** Full global-search results (GlobalSearch::run, 20 per group). */
$q = (string) $res['q'];
$title = $q !== '' ? 'Search: ' . $q : 'Search';
$tooShort = $q !== '' && mb_strlen($q) < \App\Services\Admin\GlobalSearch::MIN;
$mark = static function (string $s) use ($q): string {
    $s = e($s);
    $w = array_filter(preg_split('/\s+/', $q) ?: [], static fn($x) => mb_strlen($x) > 1);
    if (!$w) {
        return $s;
    }
    $re = implode('|', array_map(static fn($x) => preg_quote(e($x), '/'), $w));

    return preg_replace('/(' . $re . ')/iu', '<mark>$1</mark>', $s) ?? $s;
};

include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs">Workspace <i data-lucide="chevron-right"></i> Search</div><h1><?= $q !== '' ? 'Results for “' . e($q) . '”' : 'Search' ?><span style="color:#dca71f">.</span></h1></div>
</div>

<div class="gs-page">
  <?php if ($q === '' || $tooShort): ?>
    <div class="card">
      <div class="gs-empty"><i data-lucide="search"></i><b><?= $tooShort ? 'Type at least 2 characters' : 'Search across the whole admin' ?></b><span>Products, SKUs, orders, customers, mobiles, enquiries, returns and pages.</span></div>
      <?php if ($quick): ?><div class="gs-quick" style="justify-content:center"><?php foreach ($quick as $x): ?><a class="gs-item gs-chip" href="<?= e($x['url']) ?>"><i data-lucide="<?= e($x['icon']) ?>"></i><?= e($x['title']) ?></a><?php endforeach; ?></div><?php endif; ?>
    </div>
  <?php elseif (!$res['groups']): ?>
    <div class="card"><div class="gs-empty"><i data-lucide="search-x"></i><b>No matches for “<?= e($q) ?>”</b><span>Check the spelling, or try a mobile number, SKU or order number.</span></div></div>
  <?php else: ?>
    <div class="gs-count"><?= (int) $res['total'] ?> result<?= $res['total'] === 1 ? '' : 's' ?><?= $res['total'] >= 20 ? ' (top matches per section)' : '' ?></div>
    <?php foreach ($res['groups'] as $g): ?>
    <section class="card">
      <div class="card-h"><h3 class="flex items-center gap-2"><i data-lucide="<?= e($g['icon']) ?>" style="width:18px"></i><?= e($g['label']) ?> <span class="cu-count"><?= count($g['items']) ?></span></h3>
        <?php if ($g['more']): ?><a class="linkbtn" href="<?= e($g['more']) ?>">Open in <?= e($g['label']) ?> <i data-lucide="arrow-right"></i></a><?php endif; ?></div>
      <?php foreach ($g['items'] as $it): ?>
      <a class="gs-item" href="<?= e($it['url']) ?>">
        <span class="gs-lead"><?php if (!empty($it['thumb'])): ?><img src="<?= e($it['thumb']) ?>" alt="" loading="lazy"><?php elseif (!empty($it['initials'])): ?><b><?= e($it['initials']) ?></b><?php else: ?><i data-lucide="<?= e($it['icon'] ?? $g['icon']) ?>"></i><?php endif; ?></span>
        <span class="gs-txt"><span class="gs-t"><?= $mark((string) $it['title']) ?></span><?php if (!empty($it['sub'])): ?><span class="gs-s"><?= $mark((string) $it['sub']) ?></span><?php endif; ?></span>
        <?php if (!empty($it['badge'])): ?><span class="gs-b <?= e($it['tone'] ?? 'off') ?>"><?= e($it['badge']) ?></span><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </section>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../partials/footer.php';
