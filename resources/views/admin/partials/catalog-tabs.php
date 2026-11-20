<?php
$catTabs = [
    'attributes' => ['Attributes', 'admin.attributes'],
    'brands' => ['Brands', 'admin.brands'],
    'tags' => ['Tags', 'admin.tags'],
    'reviews' => ['Reviews & ratings', 'admin.reviews'],
];
?>
<nav class="segtabs" aria-label="Catalogue sections">
  <?php foreach ($catTabs as $key => [$label, $routeName]): ?>
  <a href="<?= route($routeName) ?>" class="<?= ($active ?? '') === $key ? 'on' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
