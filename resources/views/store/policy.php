<?php
/**
 * Policies (design: html/storefront/policy.html). One page, anchors #delivery … #terms.
 * Numbers come from config so the copy can't drift from what checkout does.
 * @var int $advance @var float $gst @var bool $taxIncluded @var int $windowMinutes @var int $otpDays
 */
$city = (string) business('address.city', 'Bankura');
$state = (string) business('address.state', 'West Bengal');
$shop = (string) business('name');
$legal = (string) business('legal_name', $shop);
$addr = implode(', ', array_filter([(string) business('address.line1', ''), (string) business('address.line2', ''), $city, $state, (string) business('address.pincode', '')]));
$mail = (string) business('email', '');
$tel = (string) business('phone', '');
$privacyUpdated = '27 November 2026';   // change whenever the privacy text changes
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
        <p>This covers the <?= e($shop) ?> website and Android app. Both are run by <b><?= e($legal) ?></b><?= $addr !== '' ? ', ' . e($addr) : '' ?>. Last updated <?= e($privacyUpdated) ?>.</p>
        <p><b>We never sell your details and we don’t use advertising or analytics trackers.</b></p>

        <h3>What we collect</h3>
        <ul>
          <li><b>Your account:</b> name, mobile number, email (optional) and password. We store the password only in scrambled (hashed) form.</li>
          <li><b>Orders:</b> delivery and billing addresses, what you ordered, and a GST number if you give us one for a business invoice.</li>
          <li><b>Payments:</b> the payment ID (UTR), the UPI name you paid from, and any payment screenshot you upload. Payments go from your UPI or bank app straight to our bank account, so we never see your card, UPI PIN or bank login.</li>
          <li><b>Enquiries and reviews:</b> what you write, with the name, phone or email and city you give.</li>
          <li><b>Saved stones and your bag.</b></li>
          <li><b>Device and security data:</b> your IP address and browser or app version, used to stop password guessing and abuse and to record important account actions.</li>
          <li><b>Android app only:</b> once order notifications are switched on, a notification token from Google Firebase so we can send you order updates. The app does not read your contacts, location, microphone or photos. The only exception is a payment screenshot you choose to upload.</li>
        </ul>

        <h3>Cookies and storage on your device</h3>
        <ul>
          <li>A sign-in session cookie, a “stay signed in” cookie (30 days) and a bag cookie (30 days). These are needed for the shop to work.</li>
          <li>Your saved list and grid size preference are kept in your browser’s storage.</li>
          <li>To work offline, the site and app save the pages and images you’ve viewed on your device. Signing out clears the saved pages.</li>
        </ul>

        <h3>Why we use it</h3>
        <ul>
          <li>To take, deliver and bill your orders, check your payments, and handle returns and replacements.</li>
          <li>To sign you in, keep your account secure and email password reset codes.</li>
          <li>To send order updates on WhatsApp, by email or, in the app, as notifications.</li>
          <li>To send offers on WhatsApp, <b>only if you opt in</b>. You can switch this off any time in Account → Profile.</li>
          <li>To answer your enquiries and show your reviews (with your first name and initial).</li>
        </ul>

        <h3>Who we share it with</h3>
        <ul>
          <li>Our delivery staff or transporter get your name, phone and delivery address.</li>
          <li>Service providers that work for us: our web host, Meta (WhatsApp Business messages), our email provider and, for app notifications, Google Firebase. A pincode lookup service receives only the pincode you type.</li>
          <li>Government or tax authorities, when the law requires it.</li>
        </ul>

        <h3>How long we keep it</h3>
        <ul>
          <li>Account details, addresses and saved stones: until you delete your account.</li>
          <li>Orders, payments, invoices and payment screenshots: for as long as Indian GST law requires (currently about six years), even after you delete your account. Then we delete them.</li>
          <li>Password reset codes expire after 15 minutes. “Stay signed in” cookies expire after 30 days.</li>
        </ul>

        <h3>Your choices</h3>
        <ul>
          <li>See and correct your details any time in <a href="<?= e(store_url('/account/profile')) ?>">Account → Profile</a>.</li>
          <li><b>Delete your account</b> in Account → Profile, or by message if you can’t sign in. <a href="<?= e(store_url('/account-deletion')) ?>">How to delete your account</a>.</li>
          <li>To ask what we hold about you, or for anything else about your data<?= $mail !== '' ? ', email <a href="mailto:' . e($mail) . '">' . e($mail) . '</a>' : '' ?><?= $tel !== '' ? ($mail !== '' ? ' or call ' : ', call ') . e($tel) : '' ?>. These are your rights under India’s Digital Personal Data Protection Act, 2023.</li>
        </ul>

        <h3>Keeping it safe</h3>
        <p>The site and app use HTTPS. Payment screenshots are stored privately and only our staff can see them. Staff sign in separately, with their own passwords.</p>

        <h3>Children</h3>
        <p>Accounts are for adults (18 and over). We don’t knowingly collect data from children.</p>

        <h3>Changes</h3>
        <p>If we change this policy, we’ll update the date above. For big changes we’ll also tell you on the site or in the app.</p>
      </section>
      <section id="terms"><h2>Terms</h2>
        <p>Prices and availability can change without notice until your order is confirmed. Images show typical stone from each lot. Ask us for photos of the exact slabs before you order. Disputes fall under the courts of <?= e($city) ?>, <?= e($state) ?>.</p>
      </section>
    </div>
  </div>
</div>
