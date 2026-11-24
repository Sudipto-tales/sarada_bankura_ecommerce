<?php
/**
 * Policies (design: html/storefront/policy.html). One page, anchors #delivery … #terms.
 * Numbers come from config so the copy can't drift from what checkout does.
 * @var int $advance @var float $gst @var bool $taxIncluded @var int $windowMinutes @var int $otpDays
 */
$city = (string) business('address.city', 'Bankura');
$state = (string) business('address.state', 'West Bengal');
$sections = ['delivery' => 'Delivery', 'payments' => 'Payments', 'orders' => 'Orders & cancellation', 'returns' => 'Returns & replacements', 'privacy' => 'Privacy', 'terms' => 'Terms'];
?>
<div class="w-full max-w-[1400px] mx-auto px-4 sm:px-6 lg:px-10 pt-8">
  <p class="eyebrow">Policies</p>
  <h1 class="font-display text-[40px] sm:text-[48px] leading-tight tracking-tight mt-1">The fine print, in plain words</h1>
  <div class="mt-8 grid lg:grid-cols-[240px,1fr] gap-8 items-start">
    <nav class="lg:sticky lg:top-[92px] bg-white rounded-[24px] border border-ink/5 p-2 flex lg:flex-col gap-1 overflow-x-auto no-scrollbar text-[14px] font-semibold" aria-label="Policies">
      <?php foreach ($sections as $id => $label): ?><a href="#<?= e($id) ?>" class="shrink-0 px-4 h-11 rounded-2xl flex items-center hover:bg-cream"><?= e($label) ?></a><?php endforeach; ?>
    </nav>
    <div class="prose-policy space-y-6 text-[15px] text-ink-500 leading-relaxed">
      <section id="delivery"><h2>Delivery</h2><ul>
        <li><b>Delivery is included</b> in every price. There’s no delivery line on your bill.</li>
        <li>We call to fix a date once your order is confirmed. Stone travels on our own trucks or a trusted transporter.</li>
        <li>At delivery you’ll get a <b>delivery OTP</b>. Share it only after the stone is unloaded and checked. It stays valid for <?= (int) $otpDays ?> days.</li>
      </ul></section>
      <section id="payments"><h2>Payments</h2><ul>
        <li>Pay by <b>UPI</b> to our own account. Bank transfer is available for large amounts.</li>
        <li>At checkout pay a <b><?= (int) $advance ?>% advance</b> (rounded up) or the full amount. Pay the balance any time before or at delivery.</li>
        <li>The payment window stays open for <b><?= (int) $windowMinutes ?> minutes</b>. If time runs out after you paid, don’t pay again — message us your payment ID (UTR).</li>
        <li>We check every payment by hand. If you pay more than is due, we refund the extra.</li>
        <?php if ($taxIncluded): ?>
        <li>Prices include GST.</li>
        <?php else: ?>
        <li>Prices are before GST. <?= e(rtrim(rtrim(number_format($gst, 2, '.', ''), '0'), '.')) ?>% GST is added at checkout (CGST + SGST in <?= e($state) ?>, IGST elsewhere).</li>
        <?php endif; ?>
      </ul></section>
      <section id="orders"><h2>Orders &amp; cancellation</h2><ul>
        <li>Your order is placed when you submit your payment details.</li>
        <li>We review each order. If we can’t fulfil it, we’ll tell you why on your order page and refund your advance.</li>
        <li><b>Orders can’t be cancelled once confirmed</b> — we start cutting stone to your size.</li>
      </ul></section>
      <section id="returns"><h2>Returns &amp; replacements</h2><ul>
        <li>Each product page shows its own return and replacement window. Some cut-to-size stone can’t be returned.</li>
        <li><b>Breakage in transit:</b> report it with photos within the product’s replacement window and we replace the broken pieces.</li>
        <li><b>Returns</b> are for uncut stone in original condition.</li>
        <li>Every request is reviewed by us. Once submitted, a request can’t be cancelled. You can return part of an order (for example 3 of 10 boxes).</li>
        <li>Natural stone varies in colour and veining — that isn’t a defect.</li>
      </ul></section>
      <section id="privacy"><h2>Privacy</h2>
        <p>We use your phone number to manage orders and, if you agree, to send offers on WhatsApp. We never sell your details. Payment screenshots are stored privately and only our staff can see them.</p>
      </section>
      <section id="terms"><h2>Terms</h2>
        <p>Prices and availability can change without notice until your order is confirmed. Images show typical stone from each lot. Ask us for photos of the exact slabs before you order. Disputes fall under the courts of <?= e($city) ?>, <?= e($state) ?>.</p>
      </section>
    </div>
  </div>
</div>
