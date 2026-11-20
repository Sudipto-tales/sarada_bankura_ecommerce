<?php
$active = 'units';
$title = 'Units';
$rows = $rows ?? [];
$success = $success ?? null;
$error = $error ?? null;
$kinds = [
    'area' => ['Area', 'square', '#e3eef8', '#3d6a92', 'Price per sq ft / sq m'],
    'length' => ['Length', 'ruler', '#eaf5e9', '#2f7650', 'Thickness, sizes, running ft'],
    'count' => ['Count', 'hash', '#fbf0e4', '#a2632a', 'Per piece or per slab'],
    'pack' => ['Pack', 'package', '#fff0bf', '#9b6c0a', 'Boxes and sets'],
    'weight' => ['Weight', 'weight', '#efe7f6', '#6f4a92', 'Kg or tonne'],
];
$grouped = [];
foreach ($rows as $r) {
    $grouped[$r['kind']][] = $r;
}
include __DIR__ . '/../partials/header.php';
?>
<div class="pagehead">
  <div><div class="crumbs">Catalog <i data-lucide="chevron-right"></i> Units</div><h1>Units of measure<span style="color:#dca71f">.</span></h1></div>
</div>
<?php if ($success): ?><div class="notice ok">✓ <?= e($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice bad"><?= e($error) ?></div><?php endif; ?>

<div class="notice" style="margin-bottom:16px">
  Units are used in three places: the <b>selling unit</b> and <b>box/pack contents</b> on the product Pricing step, the unit of each <b>variant</b>, and the measurement unit of an <b>attribute</b> (e.g. Thickness in mm).
</div>

<div class="layout" style="grid-template-columns:minmax(0,1fr) 300px">
  <div class="stack" style="gap:14px">
    <?php foreach ($kinds as $kind => [$label, $icon, $bg, $fg, $hint]): $list = $grouped[$kind] ?? []; ?>
    <div class="card">
      <div class="card-h">
        <span class="flex items-center gap-2"><span class="metric-icon" style="width:32px;height:32px;background:<?= $bg ?>;color:<?= $fg ?>"><i data-lucide="<?= $icon ?>"></i></span><span><h3><?= $label ?></h3><span class="text-xs muted"><?= $hint ?></span></span></span>
        <span class="text-xs muted"><?= count($list) ?> unit<?= count($list) === 1 ? '' : 's' ?></span>
      </div>
      <?php if (!$list): ?><p class="text-xs muted">None yet.</p><?php endif; ?>
      <div class="unitgrid">
        <?php foreach ($list as $u): $uses = (int) $u['pricing_count'] + (int) $u['variant_count'] + (int) $u['attribute_count']; ?>
        <div class="unitchip">
          <b><?= e((string) $u['symbol']) ?></b><span class="muted"><?= e((string) $u['name']) ?></span>
          <small class="muted"><?= $uses ? $uses . ' in use' : 'unused' ?></small>
          <?php if (!$uses): ?><button type="button" class="iconbtn danger" title="Delete" data-del="<?= e(route('admin.units.delete', ['id' => $u['id']])) ?>" data-del-name="<?= e((string) $u['symbol']) ?>"><i data-lucide="x"></i></button><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <aside class="rightcol">
    <form method="post" action="<?= route('admin.units.store') ?>" class="rightcard">
      <?= csrf_field() ?>
      <h3 class="font-extrabold text-base mb-1">Add a unit</h3>
      <p class="text-xs muted mb-3">The symbol is what shows next to prices, e.g. “₹ 240 / sq ft”.</p>
      <div class="stack" style="gap:12px">
        <div class="field"><label>Name</label><input name="name" placeholder="e.g. Box" required></div>
        <div class="field"><label>Symbol</label><input name="symbol" placeholder="e.g. box" required maxlength="32"></div>
        <div class="field"><label>Kind</label><select name="kind"><?php foreach ($kinds as $k => $d): ?><option value="<?= $k ?>"><?= $d[0] ?></option><?php endforeach; ?></select></div>
        <button class="btn gold" type="submit" style="width:100%;justify-content:center"><i data-lucide="plus" style="width:16px"></i>Add unit</button>
      </div>
    </form>
  </aside>
</div>
<?php
$scripts = '<script>msBindDelete();</script>';
include __DIR__ . '/../partials/footer.php';
?>
