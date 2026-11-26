<?php
/**
 * Shop listing (design: html/storefront/shop.html; docs/storefront.md). Works without JS:
 * the filter panel is a GET form (Apply button), chips / categories / pages are links. store.js
 * only auto-submits on change, opens the mobile drawer and toggles the grid density.
 *
 * @var array $state @var array $result @var list<array> $cards @var string $path @var array|null $top @var array|null $room
 * @var array $hero @var list<array> $subChips @var list<array> $tree @var list<array> $origins @var int $total @var list<array> $others
 */
use App\Services\Store\Listing;

$current = $room ?? $top;
$keep = Listing::query($state, ['page' => 1]);             // current filters, for category links
$link = static fn(string $p, ?array $s = null): string => store_url($p) . ($s === null ? '' : Listing::query($s));
$swatch = static fn(string $s): string => preg_match('/^#[0-9a-fA-F]{3,8}$/', $s) ? $s : '#E0D5C2';
$n = $result['total'];
$catLink = static fn(?array $t, ?array $r): string => store_url($t === null ? '/shop' : '/shop/' . rawurlencode($t['slug']) . ($r ? '/' . rawurlencode($r['slug']) : '')) . $keep;
$on = 'font-semibold text-teal';
$off = 'text-ink-500 hover:text-ink';
$box = 'fbox bg-white rounded-2xl border border-ink/5 p-4';
$sumCls = 'flex items-center justify-between gap-2 font-semibold cursor-pointer list-none text-[14px]';
?>
<section class="wrap pt-6">
  <nav class="text-[13px] text-ink-500 flex items-center gap-1.5 flex-wrap" aria-label="Breadcrumb">
    <a href="<?= e(store_url('/')) ?>" class="hover:text-ink">Home</a><?= store_icon('chevron-right', '!w-3.5 !h-3.5') ?>
    <?php if ($current === null): ?><span class="text-ink font-semibold" aria-current="page">Shop</span>
    <?php else: ?><a href="<?= e(store_url('/shop')) ?>" class="hover:text-ink">Shop</a><?= store_icon('chevron-right', '!w-3.5 !h-3.5') ?>
      <?php if ($room): ?><a href="<?= e(store_url('/shop/' . rawurlencode($top['slug']))) ?>" class="hover:text-ink"><?= e($top['name']) ?></a><?= store_icon('chevron-right', '!w-3.5 !h-3.5') ?><?php endif; ?>
      <span class="text-ink font-semibold" aria-current="page"><?= e($current['name']) ?></span>
    <?php endif; ?>
  </nav>

  <div class="mt-4 relative rounded-[28px] overflow-hidden bg-ink text-cream min-h-[200px] sm:min-h-[220px] isolate">
    <?= store_img($hero['image'], '', 'absolute inset-0 w-full h-full object-cover -z-10', true, false, true) ?>
    <div class="absolute inset-0 -z-10 bg-gradient-to-r from-ink/90 via-ink/60 to-ink/10"></div>
    <div class="p-7 sm:p-10 max-w-2xl">
      <p class="text-[11.5px] uppercase tracking-[.2em] text-cream/70"><?= e($hero['kicker']) ?></p>
      <h1 class="font-display font-light text-[36px] sm:text-[52px] leading-[1.05] tracking-tight mt-2 break-words"><?= e($hero['title']) ?></h1>
      <p class="text-cream/80 mt-3 max-w-lg"><?= e($hero['copy']) ?></p>
    </div>
  </div>

  <?php if ($subChips !== []): ?>
  <div class="mt-4 flex gap-2 overflow-x-auto no-scrollbar -mx-4 px-4 sm:mx-0 sm:px-0">
    <?php foreach ($subChips as $c): ?>
    <a href="<?= e(store_url($c['url']) . $keep) ?>" class="chip shrink-0 flex items-center gap-2.5 h-12 pl-1.5 pr-4 rounded-full border border-ink/10 bg-white hover:border-ink/40 text-[13.5px] font-semibold" aria-pressed="<?= $c['on'] ? 'true' : 'false' ?>">
      <?php if ($c['image']): ?><?= store_img($c['image'], '', 'w-9 h-9 rounded-full object-cover') ?><?php else: ?><span class="w-9 h-9 rounded-full bg-cream grid place-items-center text-ink"><?= store_icon('layers') ?></span><?php endif; ?>
      <?= e($c['label']) ?><span class="opacity-50 font-normal"><?= (int) $c['count'] ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<section class="wrap mt-8 grid lg:grid-cols-[280px,minmax(0,1fr)] gap-8 items-start">

  <!-- FILTERS: a GET form; checkboxes auto-submit with JS, "Apply" without -->
  <aside id="filters" class="lg:sticky lg:top-[92px] lg:max-h-[calc(100vh-110px)] lg:overflow-y-auto no-scrollbar" aria-label="Filters">
    <form id="facets" method="get" action="<?= e(store_url($path)) ?>">
      <?php if ($state['q'] !== ''): ?><input type="hidden" name="q" value="<?= e($state['q']) ?>"><?php endif; ?>
      <?php if ($state['offer']): ?><input type="hidden" name="offer" value="1"><?php endif; ?>
      <div class="flex items-center justify-between lg:hidden mb-4">
        <h2 class="font-display text-[24px]">Filters</h2>
        <a href="#results-top" data-filters-close class="p-2 rounded-full hover:bg-ink/5" aria-label="Close filters"><?= store_icon('x') ?></a>
      </div>
      <div class="space-y-3">
        <details open class="<?= $box ?>">
          <summary class="<?= $sumCls ?>">Category<?= store_icon('chevron-down') ?></summary>
          <ul class="mt-3 space-y-0.5 text-[14px]">
            <li><a href="<?= e($catLink(null, null)) ?>" class="flex justify-between py-1.5 <?= $current === null ? $on : $off ?>">All stone<span class="text-ink-300 text-[12px]"><?= (int) $total ?></span></a></li>
            <?php foreach (array_merge($tree, $origins) as $t): ?>
            <li>
              <a href="<?= e($catLink($t, null)) ?>" class="flex justify-between py-1.5 <?= $top && $top['id'] === $t['id'] && !$room ? $on : $off ?>"><?= e($t['name']) ?><span class="text-ink-300 text-[12px]"><?= (int) $t['count'] ?></span></a>
              <?php if ($top && $top['id'] === $t['id'] && $t['children'] !== []): ?>
              <ul class="ml-3 pl-3 border-l border-ink/10">
                <?php foreach ($t['children'] as $s): ?>
                <li><a href="<?= e($catLink($t, $s)) ?>" class="flex justify-between py-1.5 <?= $room && $room['id'] === $s['id'] ? $on : $off ?>"><?= e($s['name']) ?><span class="text-ink-300 text-[12px]"><?= (int) $s['count'] ?></span></a></li>
                <?php endforeach; ?>
              </ul>
              <?php endif; ?>
            </li>
            <?php endforeach; ?>
          </ul>
        </details>

        <?php foreach ($result['facets'] as $f): ?>
        <details <?= $f['open'] ? 'open' : '' ?> class="<?= $box ?>">
          <summary class="<?= $sumCls ?>"><span><?= e($f['label']) ?></span>
            <span class="ml-auto flex items-center gap-2"><?php if ($f['selected']): ?><span class="text-[11px] bg-teal text-white rounded-full px-2"><?= (int) $f['selected'] ?></span><?php endif; ?><?= store_icon('chevron-down') ?></span></summary>
          <?php if ($f['swatch']): ?>
          <div class="mt-3 grid grid-cols-4 gap-2">
            <?php foreach ($f['values'] as $v): ?>
            <label class="cursor-pointer text-center" title="<?= e($v['label']) ?>">
              <input type="checkbox" class="sr-only peer" name="<?= e($f['param']) ?>[]" value="<?= e($v['value']) ?>"<?= $v['on'] ? ' checked' : '' ?>>
              <span class="block aspect-square rounded-full ring-1 ring-ink/15 peer-checked:ring-2 peer-checked:ring-teal peer-checked:ring-offset-2 peer-focus-visible:ring-2 peer-focus-visible:ring-teal" style="background:<?= e($swatch($v['swatch'])) ?>"></span>
              <small class="block text-[10.5px] text-ink-500 mt-1 leading-tight truncate"><?= e($v['label']) ?> <span class="text-ink-300"><?= (int) $v['count'] ?></span></small>
            </label>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <div class="mt-3 space-y-1">
            <?php foreach ($f['values'] as $v): ?>
            <label class="flex items-center gap-2.5 py-1 text-[14px] cursor-pointer">
              <input type="checkbox" name="<?= e($f['param']) ?>[]" value="<?= e($v['value']) ?>"<?= $v['on'] ? ' checked' : '' ?>>
              <span class="flex-1 min-w-0 truncate"><?= e($v['label']) ?></span><span class="text-ink-300 text-[12px]"><?= (int) $v['count'] ?></span>
            </label>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </details>
        <?php endforeach; ?>

        <details open class="<?= $box ?>">
          <summary class="<?= $sumCls ?>">Price per unit<?= store_icon('chevron-down') ?></summary>
          <div class="mt-3 grid grid-cols-2 gap-2">
            <label><span class="text-[12px] text-ink-500">Min ₹</span><input name="min" type="number" min="0" step="1" inputmode="numeric" value="<?= e($state['min']) ?>" placeholder="<?= (int) $result['price']['low'] ?>" class="field !h-10"></label>
            <label><span class="text-[12px] text-ink-500">Max ₹</span><input name="max" type="number" min="0" step="1" inputmode="numeric" value="<?= e($state['max']) ?>" placeholder="<?= $result['price']['high'] ? (int) $result['price']['high'] : 'Any' ?>" class="field !h-10"></label>
          </div>
          <div class="mt-2 flex flex-wrap gap-1.5">
            <?php foreach ([[0, 200], [200, 500], [500, 1000], [1000, null]] as [$a, $b]): ?>
            <a href="<?= e($link($path, array_replace($state, ['min' => (string) $a, 'max' => $b === null ? '' : (string) $b, 'page' => 1]))) ?>" class="text-[12px] px-2.5 py-1 rounded-full bg-cream hover:bg-cream-300"><?= $b ? '₹' . $a . '–' . $b : '₹' . $a . '+' ?></a>
            <?php endforeach; ?>
          </div>
        </details>

        <details open class="<?= $box ?>">
          <summary class="<?= $sumCls ?>">Availability<?= store_icon('chevron-down') ?></summary>
          <div class="mt-3 space-y-1 text-[14px]">
            <?php foreach (Listing::AVAIL as $k => $l): ?>
            <label class="flex items-center gap-2.5 py-1 cursor-pointer"><input type="checkbox" name="avail[]" value="<?= e($k) ?>"<?= in_array($k, $state['avail'], true) ? ' checked' : '' ?>><?= e($l) ?></label>
            <?php endforeach; ?>
          </div>
        </details>
      </div>
      <div class="sticky bottom-0 pt-4 pb-2 bg-cream flex gap-2" data-apply-bar>
        <a href="<?= e($link($path, Listing::cleared($state))) ?>" class="btn btn-line flex-1">Clear</a>
        <button class="btn btn-dark flex-1">Show <?= (int) $n ?> stone<?= $n === 1 ? '' : 's' ?></button>
      </div>
    </form>
  </aside>

  <!-- RESULTS -->
  <div class="min-w-0 scroll-mt-28" id="results-top">
    <div class="flex flex-wrap items-center gap-3 justify-between">
      <div class="flex items-center gap-3">
        <a href="#filters" data-filters-open class="lg:hidden h-10 px-4 rounded-full border border-ink/15 bg-white text-[13.5px] font-semibold flex items-center gap-2" aria-controls="filters">
          <?= store_icon('sliders-horizontal') ?> Filters<?php if ($result['filters']): ?> <span class="min-w-[20px] h-5 px-1 rounded-full bg-teal text-white text-[11px] grid place-items-center"><?= (int) $result['filters'] ?></span><?php endif; ?>
        </a>
        <p class="text-[14px] text-ink-500"><b class="text-ink"><?= (int) $n ?></b> stone<?= $n === 1 ? '' : 's' ?></p>
      </div>
      <div class="flex items-center gap-2">
        <label for="sort" class="text-[13px] text-ink-500 hidden sm:block">Sort</label>
        <select id="sort" name="sort" form="facets" data-autosubmit class="h-10 pl-3 pr-8 rounded-full bg-white border border-ink/15 text-[13.5px] font-semibold outline-none focus:border-teal">
          <?php foreach (Listing::SORTS as $k => $l): ?><option value="<?= e($k) ?>"<?= $state['sort'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
        <noscript><button form="facets" class="h-10 px-4 rounded-full bg-ink text-cream text-[13px] font-semibold">Sort</button></noscript>
        <span data-js-only hidden><span class="hidden md:flex rounded-full border border-ink/15 bg-white p-0.5" role="group" aria-label="Grid size">
          <button type="button" data-dense="0" class="chip w-9 h-9 rounded-full grid place-items-center" aria-label="Large tiles" aria-pressed="true"><?= store_icon('layout-grid') ?></button>
          <button type="button" data-dense="1" class="chip w-9 h-9 rounded-full grid place-items-center" aria-label="Small tiles" aria-pressed="false"><?= store_icon('grid-3x3') ?></button>
        </span></span>
      </div>
    </div>

    <?php if ($result['chips'] !== []): ?>
    <div class="mt-4 flex flex-wrap gap-2">
      <?php foreach ($result['chips'] as $c): ?>
      <a href="<?= e(store_url($path) . $c['query']) ?>" class="h-8 pl-3 pr-2 rounded-full bg-white border border-ink/10 hover:border-ink text-[13px] flex items-center gap-1.5" aria-label="Remove filter <?= e($c['label']) ?>"><?= e($c['label']) ?><?= store_icon('x', '!w-3.5 !h-3.5') ?></a>
      <?php endforeach; ?>
      <a href="<?= e($link($path, Listing::cleared($state))) ?>" class="h-8 px-2 text-[13px] font-semibold text-teal flex items-center">Clear all</a>
    </div>
    <?php endif; ?>

    <?php if ($cards !== []): ?>
    <div id="results" class="mt-5 grid gap-3 sm:gap-5 grid-cols-2 md:grid-cols-3 2xl:grid-cols-4" data-grid>
      <?php foreach ($cards as $c): ?><?= store_card($c) ?><?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="mt-5 bg-white rounded-[24px] border border-ink/5 p-10 text-center">
      <span class="w-14 h-14 mx-auto rounded-full bg-cream grid place-items-center"><?= store_icon('search-x', '!w-6 !h-6') ?></span>
      <h2 class="font-display text-[26px] mt-4">No stones match</h2>
      <p class="text-ink-500 mt-1">Try removing a filter, or tell us what you need — we source to order.</p>
      <div class="mt-5 flex flex-wrap justify-center gap-2">
        <?php if ($result['filters']): ?><a href="<?= e($link($path, Listing::cleared($state))) ?>" class="btn btn-dark">Clear filters</a><?php endif; ?>
        <a href="<?= e(store_url('/contact')) ?>" class="btn btn-line">Ask us</a>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($result['pages'] > 1):
        $p = $result['page'];
        $pages = $result['pages'];
        $show = array_unique(array_filter([1, $p - 1, $p, $p + 1, $pages], fn ($x) => $x >= 1 && $x <= $pages));
        sort($show);
        $pageLink = fn (int $i): string => $link($path, array_replace($state, ['page' => $i]));
    ?>
    <nav class="mt-10 flex items-center justify-center gap-1.5 flex-wrap" aria-label="Pages">
      <?php if ($p > 1): ?><a href="<?= e($pageLink($p - 1)) ?>" rel="prev" class="w-10 h-10 rounded-full grid place-items-center hover:bg-white" aria-label="Previous page"><?= store_icon('chevron-left') ?></a><?php endif; ?>
      <?php $last = 0; foreach ($show as $i): ?>
        <?php if ($i - $last > 1): ?><span class="w-6 text-center text-ink-300">…</span><?php endif; $last = $i; ?>
        <?php if ($i === $p): ?><span class="w-10 h-10 rounded-full text-[14px] font-semibold bg-ink text-cream grid place-items-center" aria-current="page"><?= $i ?></span>
        <?php else: ?><a href="<?= e($pageLink($i)) ?>" class="w-10 h-10 rounded-full text-[14px] font-semibold grid place-items-center hover:bg-white"><?= $i ?></a><?php endif; ?>
      <?php endforeach; ?>
      <?php if ($p < $pages): ?><a href="<?= e($pageLink($p + 1)) ?>" rel="next" class="w-10 h-10 rounded-full grid place-items-center hover:bg-white" aria-label="Next page"><?= store_icon('chevron-right') ?></a><?php endif; ?>
    </nav>
    <?php endif; ?>
  </div>
</section>

<section class="wrap mt-20">
  <div class="grid lg:grid-cols-12 gap-6">
    <div class="lg:col-span-5 bg-white rounded-[24px] p-7 border border-ink/5">
      <p class="eyebrow">Buying guide</p>
      <h2 class="font-display text-[28px] leading-tight mt-1">Choosing <?= e(mb_strtolower($current['name'] ?? 'natural')) ?> stone</h2>
      <div class="text-[14.5px] text-ink-500 mt-3 space-y-3 leading-relaxed">
        <p>For busy floors choose a <b class="text-ink">honed</b> or <b class="text-ink">leather</b> finish — polished marble is beautiful but can be slippery when wet.</p>
        <p>Order about <b class="text-ink">8% extra</b> to cover cutting and breakage.</p>
        <p>Every lot varies. Visit the showroom in <?= e((string) business('address.city', 'Bankura')) ?>, or ask us on WhatsApp for photos of the exact slabs we will send.</p>
      </div>
    </div>
    <?php if ($others !== []): ?>
    <div class="lg:col-span-7">
      <p class="eyebrow">Other spaces</p>
      <div class="mt-3 grid grid-cols-2 sm:grid-cols-3 gap-3">
        <?php foreach ($others as $o): ?>
        <a href="<?= e(store_url($o['url'])) ?>" class="group relative rounded-2xl overflow-hidden aspect-[4/3] bg-ink">
          <?= store_img($o['image'], '', 'absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition duration-500', false, true, true) ?>
          <div class="absolute inset-0 bg-gradient-to-t from-ink/75 to-transparent"></div>
          <span class="absolute left-4 bottom-3 right-3 text-cream"><small class="block text-[11px] text-cream/70"><?= e($o['parent']) ?></small><b class="font-display text-[19px] font-normal"><?= e($o['name']) ?></b></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>
