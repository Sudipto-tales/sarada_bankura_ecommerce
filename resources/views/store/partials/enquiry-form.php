<?php
/**
 * Enquiry form → POST /enquiry (App\Services\Store\Enquiries). Used by the product page and the
 * contact page. Works without JS; spam guards = honeypot `website` + signed time stamp `ts`.
 *
 * Vars:
 *   $source   contact | product | home | calculator
 *   $kind     quote | notify | restock | photos | general
 *   $back     page path to come back to (e.g. /product/statuario)
 *   $stamp    Enquiries::stamp()
 *   $state    ['errors' => [...], 'old' => [...], 'thanks' => ref|true|null]
 *   $product  ['id' => …, 'name' => …] or null;  $variantId int
 *   $categories  list of [id, label] for the "Where" select (optional)
 *   $fields   ['email' => bool, 'city' => bool, 'visit' => bool, 'quantity' => bool, 'message' => bool]
 *   $submit   button label;  $dark bool (light text on ink background)
 */
$state += ['errors' => [], 'old' => [], 'thanks' => null];
$product = $product ?? null;
$variantId = (int) ($variantId ?? 0);
$categories = $categories ?? [];
$fields = ($fields ?? []) + ['email' => false, 'city' => false, 'visit' => false, 'quantity' => true, 'message' => true];
$dark = (bool) ($dark ?? false);
$submit = $submit ?? 'Send enquiry';
$err = $state['errors'];
$old = static fn(string $k): string => is_string($state['old'][$k] ?? null) ? $state['old'][$k] : '';
$field = 'field' . ($dark ? ' !bg-cream !text-ink' : '');
$lbl = 'lbl' . ($dark ? ' !text-cream/80' : '');
$errCls = $dark ? 'text-[#F2B79A]' : 'text-copper';
$msg = static fn(string $k): string => isset($err[$k]) ? '<p class="mt-1 text-[12.5px] ' . $errCls . '" id="enq-' . e($k) . '-err">' . e($err[$k]) . '</p>' : '';
$aria = static fn(string $k): string => isset($err[$k]) ? ' aria-invalid="true" aria-describedby="enq-' . e($k) . '-err"' : '';
?>
<?php if ($state['thanks']): ?>
<div class="rounded-2xl <?= $dark ? 'bg-cream/10' : 'bg-teal-50' ?> p-5 flex gap-3" role="status">
  <span class="w-10 h-10 shrink-0 rounded-full <?= $dark ? 'bg-cream text-ink' : 'bg-teal text-white' ?> grid place-items-center"><?= store_icon('check') ?></span>
  <div><b class="block">Thank you — we’ve got it.</b>
    <p class="text-[14px] <?= $dark ? 'text-cream/75' : 'text-ink-500' ?>">We’ll call you within a working day<?= is_string($state['thanks']) ? '. Your reference is <b>' . e($state['thanks']) . '</b>' : '' ?>.</p></div>
</div>
<?php else: ?>
<form method="post" action="<?= e((string) route('store.enquiry')) ?>" class="grid sm:grid-cols-2 gap-3" novalidate data-enquiry>
  <?= csrf_field() ?>
  <input type="hidden" name="source" value="<?= e($source) ?>">
  <input type="hidden" name="kind" value="<?= e($kind) ?>">
  <input type="hidden" name="back" value="<?= e($back) ?>">
  <input type="hidden" name="ts" value="<?= e($stamp) ?>">
  <?php if ($product): ?><input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>"><input type="hidden" name="variant_id" value="<?= $variantId ?>" data-variant-input><?php endif; ?>
  <div class="hidden" aria-hidden="true"><label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
  <?php if (isset($err['form'])): ?><p class="sm:col-span-2 text-[13.5px] <?= $errCls ?>" role="alert"><?= e($err['form']) ?></p><?php endif; ?>

  <label class="block"><span class="<?= $lbl ?>">Your name</span><input name="name" required maxlength="120" autocomplete="name" value="<?= e($old('name')) ?>" class="<?= $field ?>"<?= $aria('name') ?>><?= $msg('name') ?></label>
  <label class="block"><span class="<?= $lbl ?>">Mobile</span><input name="phone" required inputmode="tel" autocomplete="tel" maxlength="16" placeholder="10-digit number" value="<?= e($old('phone')) ?>" class="<?= $field ?>"<?= $aria('phone') ?>><?= $msg('phone') ?></label>
  <?php if ($fields['email']): ?>
  <label class="block"><span class="<?= $lbl ?>">Email <span class="font-normal opacity-60">(optional)</span></span><input name="email" type="email" autocomplete="email" maxlength="190" value="<?= e($old('email')) ?>" class="<?= $field ?>"<?= $aria('email') ?>><?= $msg('email') ?></label>
  <?php endif; ?>
  <?php if ($fields['city']): ?>
  <label class="block"><span class="<?= $lbl ?>">Town / city <span class="font-normal opacity-60">(optional)</span></span><input name="city" maxlength="80" autocomplete="address-level2" value="<?= e($old('city')) ?>" class="<?= $field ?>"></label>
  <?php endif; ?>
  <?php if ($fields['quantity']): ?>
  <label class="block"><span class="<?= $lbl ?>">Approx. area <span class="font-normal opacity-60">(sq ft)</span></span><input name="quantity" type="number" min="1" step="any" inputmode="decimal" value="<?= e($old('quantity')) ?>" class="<?= $field ?>"<?= $aria('quantity') ?>><?= $msg('quantity') ?></label>
  <?php endif; ?>
  <?php if ($categories !== []): ?>
  <label class="block"><span class="<?= $lbl ?>">Where</span>
    <select name="category_id" class="<?= $field ?>">
      <option value="">Not sure yet</option>
      <?php foreach ($categories as [$cid, $clabel]): ?><option value="<?= (int) $cid ?>"<?= $old('category_id') === (string) $cid ? ' selected' : '' ?>><?= e($clabel) ?></option><?php endforeach; ?>
    </select></label>
  <?php endif; ?>
  <?php if ($fields['visit']): ?>
  <label class="block"><span class="<?= $lbl ?>">Book a visit <span class="font-normal opacity-60">(optional)</span></span><input name="visit_date" type="date" min="<?= e(date('Y-m-d')) ?>" value="<?= e($old('visit_date')) ?>" class="<?= $field ?>"></label>
  <?php endif; ?>
  <?php if ($fields['message']): ?>
  <label class="block sm:col-span-2"><span class="<?= $lbl ?>">Message<?= $product ? ' <span class="font-normal opacity-60">(optional)</span>' : '' ?></span><textarea name="message" rows="3" maxlength="2000" class="<?= $field ?>" placeholder="<?= $product ? 'Room size, finish, when you need it…' : 'Stone you liked, finish, budget, timeline…' ?>"<?= $aria('message') ?>><?= e($old('message')) ?></textarea><?= $msg('message') ?></label>
  <?php endif; ?>
  <div class="sm:col-span-2 flex flex-wrap items-center gap-2 pt-1">
    <button class="btn <?= $dark ? 'btn-copper' : 'btn-dark' ?>"><?= e($submit) ?></button>
    <?php if (($wa = store_wa_link($product ? 'Hi, I’d like to ask about ' . $product['name'] : 'Hi, I’d like to ask about stone for my project')) !== ''): ?>
    <a href="<?= e($wa) ?>" rel="noopener" class="btn <?= $dark ? 'border border-cream/30 hover:bg-cream/10' : 'btn-line' ?>"><?= store_icon('message-circle') ?> WhatsApp</a>
    <?php endif; ?>
    <p class="w-full text-[12px] <?= $dark ? 'text-cream/60' : 'text-ink-400' ?>">We use your number only to reply to this enquiry.</p>
  </div>
</form>
<?php endif; ?>
