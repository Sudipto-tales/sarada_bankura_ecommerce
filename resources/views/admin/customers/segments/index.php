<?php
use App\Services\Customer\SegmentMatcher;

$active = 'customers.segments';
$title = 'Customer segments';
$icons = ['vip' => 'crown', 'builder' => 'hard-hat', 'dealer' => 'store', 'inactive' => 'moon', 'new' => 'sparkles', 'online' => 'globe'];
$iconFor = static function (array $s) use ($icons): string {
    foreach ($icons as $k => $i) {
        if (str_contains((string) $s['slug'], $k)) return $i;
    }

    return $s['type'] === 'static' ? 'list-checks' : 'filter';
};
$tones = ['t-gold', 't-blue', 't-green', 't-rose', 't-sand'];

include __DIR__ . '/../../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs"><a href="<?= route('admin.customers') ?>">Customers</a> <i data-lucide="chevron-right"></i> Segments</div><h1>Segments<span style="color:#dca71f">.</span></h1></div>
  <a class="btn gold" href="<?= route('admin.customers.segments.create') ?>"><i data-lucide="plus" style="width:16px"></i>New segment</a>
</div>

<p class="text-sm muted" style="margin:-8px 4px 18px;max-width:760px">Segments are saved groups of customers. <b>Dynamic</b> segments follow rules and update themselves as customers change;
  <b>static</b> segments are lists you pick by hand. Orders and spend count delivered orders.</p>

<?php if ($segments): ?>
<div class="seg-grid">
  <?php foreach ($segments as $i => $s):
      $sid = (int) $s['id'];
      $pct = $totalCustomers ? round($s['live_count'] / $totalCustomers * 100) : 0;
      $sRules = $rules[$sid] ?? [];
  ?>
  <article class="card seg-card<?= (int) $s['is_active'] ? '' : ' off' ?>">
    <div class="seg-card-h">
      <span class="seg-ic <?= $tones[$i % count($tones)] ?>"><i data-lucide="<?= $iconFor($s) ?>"></i></span>
      <div class="min-w-0"><a href="<?= route('admin.customers.segments.show', ['id' => $sid]) ?>"><h3><?= e((string) $s['name']) ?></h3></a>
        <small class="muted"><?= $s['type'] === 'static' ? 'Static · hand-picked' : 'Dynamic · ' . ($s['match_type'] === 'any' ? 'any rule' : 'all rules') ?><?= (int) $s['is_active'] ? '' : ' · paused' ?></small></div>
      <span class="row-actions" style="margin-left:auto">
        <a class="iconbtn" title="Edit" href="<?= route('admin.customers.segments.edit', ['id' => $sid]) ?>"><i data-lucide="pencil"></i></a>
        <button type="button" class="iconbtn danger" title="Delete" data-del="<?= e(route('admin.customers.segments.delete', ['id' => $sid])) ?>" data-del-name="<?= e((string) $s['name']) ?>"><i data-lucide="trash-2"></i></button>
      </span>
    </div>
    <?php if ($s['description']): ?><p class="text-xs muted seg-desc"><?= e((string) $s['description']) ?></p><?php endif; ?>
    <div class="seg-rules">
      <?php if ($s['type'] === 'static'): ?><span class="seg-rule"><i data-lucide="hand"></i>Members added by hand</span>
      <?php else: foreach (array_slice($sRules, 0, 3) as $j => $r): ?>
        <?php if ($j > 0): ?><span class="seg-join"><?= $s['match_type'] === 'any' ? 'or' : 'and' ?></span><?php endif; ?>
        <span class="seg-rule"><?= e(SegmentMatcher::describe($r, $tagNames)) ?></span>
      <?php endforeach; if (count($sRules) > 3): ?><span class="seg-join">+<?= count($sRules) - 3 ?> more</span><?php endif; endif; ?>
    </div>
    <div class="seg-foot">
      <div><b><?= number_format($s['live_count']) ?></b> <small class="muted">customer<?= $s['live_count'] === 1 ? '' : 's' ?> · <?= $pct ?>%</small></div>
      <div class="seg-bar"><i style="width:<?= max($s['live_count'] ? 3 : 0, $pct) ?>%"></i></div>
      <a class="linkbtn" href="<?= route('admin.customers') ?>?segment=<?= $sid ?>">View list <i data-lucide="arrow-right"></i></a>
    </div>
  </article>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card cu-empty"><span class="metric-icon"><i data-lucide="filter"></i></span><b>No segments yet</b><small>Build one with rules, or run <code>php vayu db:seed CustomerSeeder</code> for the six defaults.</small><a class="btn gold" href="<?= route('admin.customers.segments.create') ?>">New segment</a></div>
<?php endif; ?>
<?php
$scripts = '<script>msBindDelete();</script>';
include __DIR__ . '/../../partials/footer.php';
