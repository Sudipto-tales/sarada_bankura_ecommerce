<?php
/**
 * Contact & visit (design: html/storefront/contact.html). The form posts to /enquiry (source=contact).
 * @var list<array> $spaces @var string $stamp @var array $enquiry @var int|float $advance @var float $gst @var bool $taxIncluded
 */
$wa = store_wa_link('Hi, I’d like to ask about stone for my project');
$tel = store_tel();
$address = store_address();
$maps = $address !== '' ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(business('name') . ', ' . $address) : '';
$cats = [];
foreach ($spaces as $s) {
    foreach ($s['children'] as $c) {
        $cats[] = [$c['id'], $s['name'] . ' · ' . $c['name']];
    }
}
$num = static fn($n): string => rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
$info = array_values(array_filter([
    ['map-pin', 'Address', $address],
    ['clock', 'Open', (string) config('store.hours', '')],
    ['phone', 'Call', (string) business('phone', '')],
    ['receipt-text', 'GSTIN', (string) business('gstin', '')],
], fn ($r) => trim($r[2]) !== ''));
$faq = [
    ['Is delivery really included?', 'Yes. Every price on the site includes delivery to your site. There is no delivery charge at checkout.'],
    ['How do I pay?', 'By UPI — ' . $num($advance) . '% advance or the full amount at checkout. Bank transfer is available for large amounts. We check every payment by hand and confirm on WhatsApp.'],
    ['Can I cancel my order?', 'You can’t cancel once we’ve confirmed the order, because we start cutting stone to your size. Before that, message us and we’ll help.'],
    ['What if a piece arrives broken?', 'Report it with photos soon after delivery. We review it and send a replacement.'],
    ['Is GST included?', $taxIncluded ? 'Yes, prices include GST. The tax is shown on your invoice.' : 'Prices are before GST. ' . $num($gst) . '% GST is added at checkout and shown on your invoice.'],
];
?>
<div class="wrap pt-8">
  <div class="grid lg:grid-cols-2 gap-6">
    <section class="relative rounded-[28px] overflow-hidden bg-ink text-cream min-h-[460px] isolate">
      <div class="stone-ph dark absolute inset-0 -z-10" aria-hidden="true"></div>
      <div class="absolute inset-0 -z-10 bg-gradient-to-t from-ink via-ink/40 to-transparent"></div>
      <div class="relative p-8 sm:p-10 h-full flex flex-col justify-end">
        <p class="text-[12px] uppercase tracking-[.2em] text-cream/70 font-semibold">Visit us</p>
        <h1 class="font-display text-[38px] sm:text-[44px] leading-tight mt-1">See the slab before you buy it</h1>
        <?php if ($info !== []): ?>
        <dl class="mt-6 grid sm:grid-cols-2 gap-4 text-[14px]">
          <?php foreach ($info as [$icon, $label, $value]): ?>
          <div class="flex gap-3"><?= store_icon($icon, 'mt-0.5 text-[#E9B48F] shrink-0') ?><div class="min-w-0"><dt class="text-cream/60 text-[12px]"><?= e($label) ?></dt><dd class="font-semibold break-words"><?= e($value) ?></dd></div></div>
          <?php endforeach; ?>
        </dl>
        <?php endif; ?>
        <div class="mt-6 flex flex-wrap gap-2">
          <?php if ($maps !== ''): ?><a href="<?= e($maps) ?>" rel="noopener" target="_blank" class="btn bg-cream text-ink"><?= store_icon('navigation') ?> Directions</a><?php endif; ?>
          <?php if ($wa !== ''): ?><a href="<?= e($wa) ?>" rel="noopener" class="btn border border-cream/30 hover:bg-cream/10"><?= store_icon('message-circle') ?> WhatsApp</a><?php endif; ?>
          <?php if ($tel !== ''): ?><a href="<?= e($tel) ?>" class="btn border border-cream/30 hover:bg-cream/10"><?= store_icon('phone') ?> Call</a><?php endif; ?>
        </div>
      </div>
    </section>

    <section id="enquire" class="bg-white rounded-[28px] border border-ink/5 p-7 sm:p-10 scroll-mt-28">
      <h2 class="font-display text-[30px] leading-tight">Tell us about your project</h2>
      <p class="text-ink-500 text-[14px] mt-1 mb-6">We’ll call you back with options and a price.</p>
      <?= store_partial('enquiry-form', [
          'source' => 'contact', 'kind' => 'general', 'back' => '/contact', 'stamp' => $stamp, 'state' => $enquiry,
          'categories' => $cats, 'fields' => ['email' => true, 'city' => true, 'visit' => true],
      ]) ?>
    </section>
  </div>

  <section class="mt-16 grid lg:grid-cols-12 gap-6">
    <div class="lg:col-span-4"><p class="eyebrow">Questions</p><h2 class="font-display text-[34px] leading-tight mt-1">Before you order</h2>
      <p class="text-ink-500 text-[14px] mt-2">More in our <a href="<?= e(store_url('/policy')) ?>" class="text-teal font-semibold">policies</a>.</p></div>
    <div class="lg:col-span-8 space-y-3">
      <?php foreach ($faq as [$q, $a]): ?>
      <details class="bg-white rounded-2xl border border-ink/5 px-5 py-4 group">
        <summary class="flex items-center justify-between gap-3 font-semibold cursor-pointer list-none"><?= e($q) ?><?= store_icon('plus', 'group-open:rotate-45 transition shrink-0') ?></summary>
        <p class="text-ink-500 mt-2 text-[14.5px]"><?= e($a) ?></p>
      </details>
      <?php endforeach; ?>
    </div>
  </section>
</div>
