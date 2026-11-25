<?php
/**
 * Customer account (design: html/storefront/account.html; docs/storefront.md §11).
 * Tabs are real pages (/account, /account/addresses, /account/profile); Saved is /saved.
 *
 * @var string $tab @var array $user @var array $tabs @var array|null $customer
 * @var list<array> $orders @var list<array> $addresses @var list<string> $states @var int $editId
 */
use App\Services\Customer\CustomerService;
use App\Services\Customer\Phone;

$err = static fn(string $k): string => '<em class="block not-italic text-[12.5px] text-copper mt-1 empty:hidden">' . e((string) (error($k) ?? '')) . '</em>';
$inv = static fn(string $k): string => error($k) !== null ? ' aria-invalid="true"' : '';
$first = explode(' ', trim((string) $user['name']))[0] ?: 'there';
$tone = [
    'pending' => ['Waiting for confirmation', 'bg-gold/15 text-[#8a6a35]'],
    'accepted' => ['Confirmed', 'bg-teal-50 text-teal'],
    'processing' => ['Preparing', 'bg-teal-50 text-teal'],
    'packed' => ['Packed', 'bg-teal-50 text-teal'],
    'dispatched' => ['On the way', 'bg-teal text-white'],
    'delivered' => ['Delivered', 'bg-cream-200 text-ink'],
    'rejected' => ['Not accepted', 'bg-copper-50 text-copper'],
    'cancelled' => ['Cancelled', 'bg-copper-50 text-copper'],
];
$channel = static fn(array $o): string => $o['channel'] === 'online' ? 'Website' : match ((string) $o['channel_detail']) {
    'phone' => 'By phone', 'whatsapp' => 'WhatsApp', default => 'Showroom',
};
?>
<div class="wrap pt-8">
  <div class="flex flex-wrap items-end justify-between gap-4">
    <div>
      <p class="eyebrow">Your account</p>
      <h1 class="font-display text-[40px] sm:text-[46px] leading-tight tracking-tight mt-1">Namaskar, <?= e($first) ?></h1>
      <p class="text-ink-500 text-[14px]">+91 <?= e(Phone::format((string) $user['phone'])) ?>
        <?php if (!empty($user['phone_verified_at'])): ?><span class="text-teal font-semibold ml-1 inline-flex items-center gap-1"><?= store_icon('badge-check') ?>verified</span><?php endif; ?>
        <?= $user['email'] ? ' · ' . e((string) $user['email']) : '' ?></p>
    </div>
    <form method="post" action="<?= e((string) route('store.signout')) ?>"><?= csrf_field() ?><button class="btn btn-line !h-11"><?= store_icon('log-out') ?> Sign out</button></form>
  </div>

  <div class="mt-8 grid lg:grid-cols-[240px,1fr] gap-6 items-start">
    <nav class="bg-white rounded-[24px] border border-ink/5 p-2 flex lg:flex-col gap-1 overflow-x-auto no-scrollbar lg:sticky lg:top-[92px]" aria-label="Account">
      <?php foreach ($tabs as $k => [$icon, $label]): $on = $k === $tab; ?>
      <a href="<?= e(store_url($k === 'orders' ? '/account' : '/account/' . $k)) ?>" class="shrink-0 flex items-center gap-2.5 px-4 h-11 rounded-2xl text-[14px] font-semibold <?= $on ? 'bg-ink text-cream' : 'hover:bg-cream' ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= store_icon($icon) ?><?= e($label) ?></a>
      <?php endforeach; ?>
      <a href="<?= e(store_url('/saved')) ?>" class="shrink-0 flex items-center gap-2.5 px-4 h-11 rounded-2xl text-[14px] font-semibold hover:bg-cream"><?= store_icon('heart') ?>Saved</a>
    </nav>

    <div class="min-w-0">
    <?php if ($tab === 'orders'): ?>
      <?php if ($orders === []): ?>
      <section class="bg-white rounded-[24px] border border-ink/5 p-8 text-center">
        <span class="mx-auto w-14 h-14 rounded-2xl bg-cream grid place-items-center text-teal"><?= store_icon('package', '!w-6 !h-6') ?></span>
        <h2 class="font-display text-[26px] mt-4">No orders yet</h2>
        <p class="text-ink-500 mt-1">Orders you place here — and the ones from our showroom on +91 <?= e(Phone::format((string) $user['phone'])) ?> — show up in this list.</p>
        <a href="<?= e(store_url('/shop')) ?>" class="btn btn-dark mt-5 inline-flex">Browse stones</a>
      </section>
      <?php else: ?>
      <section class="space-y-3">
        <?php foreach ($orders as $o): [$tl, $tc] = $tone[$o['order_status']] ?? [ucfirst((string) $o['order_status']), 'bg-cream-200 text-ink']; ?>
        <article class="bg-white rounded-[24px] border border-ink/5 p-5 grid sm:grid-cols-[64px,1fr,auto] gap-4 items-center">
          <span class="relative w-16 h-16 rounded-xl overflow-hidden bg-cream"><?= store_img($o['image'], '', 'absolute inset-0 w-full h-full object-cover') ?></span>
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2"><b class="font-display text-[20px] font-normal"><?= e((string) $o['order_number']) ?></b>
              <span class="text-[11.5px] font-bold uppercase tracking-wide px-2 py-0.5 rounded-md <?= $tc ?>"><?= e($tl) ?></span>
              <span class="text-[11.5px] text-ink-400 border border-ink/10 rounded-md px-1.5"><?= e($channel($o)) ?></span></div>
            <p class="text-[13.5px] text-ink-500 mt-0.5 truncate"><?= e(date('j M Y', strtotime((string) $o['created_at']))) ?><?= $o['items_line'] !== '' ? ' · ' . e($o['items_line']) : '' ?></p>
            <p class="text-[13.5px] mt-0.5"><b><?= e(store_inr($o['grand_total'])) ?></b>
              <?php if ($o['can_pay']): ?> · <span class="text-copper font-semibold"><?= e(store_inr($o['due_amount'])) ?> due</span><?php endif; ?>
              <?php if ((float) $o['pending_verification_amount'] > 0): ?> · <span class="text-[#8a6a35]"><?= e(store_inr($o['pending_verification_amount'])) ?> being checked</span><?php endif; ?></p>
          </div>
          <div class="flex gap-2">
            <?php if ($o['can_pay']): ?><a href="<?= e((string) route('pay.show', ['token' => $o['public_token']])) ?>" class="btn btn-copper !h-10 !px-4 text-[13.5px]">Pay</a><?php endif; ?>
            <a href="<?= e((string) route('order.public', ['token' => $o['public_token']])) ?>" class="btn btn-line !h-10 !px-4 text-[13.5px]">View</a>
          </div>
        </article>
        <?php endforeach; ?>
      </section>
      <?php endif; ?>

    <?php elseif ($tab === 'addresses'):
        $editing = null;
        foreach ($addresses as $a) {
            if ((int) $a['id'] === $editId) {
                $editing = $a;
            }
        }
        $showForm = $editing !== null || (string) request()->query('edit', '') === 'new' || $addresses === [] || errors() !== [];
        $f = static fn(string $k, string $col) => (string) old($k, $editing[$col] ?? '');
        $stateNow = $f('state', 'state'); ?>
      <section class="grid md:grid-cols-2 gap-4">
        <?php foreach ($addresses as $a): ?>
        <article class="bg-white rounded-[24px] border border-ink/5 p-6<?= $editing && (int) $editing['id'] === (int) $a['id'] ? ' ring-2 ring-teal' : '' ?>">
          <div class="flex items-center justify-between gap-3"><b><?= e((string) ($a['label'] ?: 'Address')) ?></b>
            <?php if ((int) $a['is_default'] === 1): ?><span class="text-[11px] font-bold uppercase tracking-wide bg-teal-50 text-teal px-2 py-0.5 rounded-md">Default</span>
            <?php else: ?><form method="post" action="<?= e((string) route('store.account.address.default', ['id' => $a['id']])) ?>"><?= csrf_field() ?><button class="text-[12.5px] font-semibold text-teal">Make default</button></form><?php endif; ?></div>
          <address class="not-italic text-[14px] text-ink-500 mt-3 leading-relaxed">
            <?= $a['name'] ? e((string) $a['name']) . '<br>' : '' ?><?= e((string) $a['address_line1']) ?><?= $a['address_line2'] ? ', ' . e((string) $a['address_line2']) : '' ?><br>
            <?= e(implode(', ', array_filter([(string) ($a['area'] ?: $a['post_office']), (string) ($a['city'] ?: $a['district'])]))) ?><br>
            <?= e(trim($a['state'] . ' ' . $a['pincode'])) ?><?= $a['phone'] ? '<br>+91 ' . e(Phone::format((string) $a['phone'])) : '' ?></address>
          <div class="mt-4 flex gap-4 text-[13px] font-semibold">
            <a href="<?= e(store_url('/account/addresses') . '?edit=' . (int) $a['id'] . '#address-form') ?>" class="hover:text-teal">Edit</a>
            <form method="post" action="<?= e((string) route('store.account.address.delete', ['id' => $a['id']])) ?>" onsubmit="return confirm('Remove this address?')"><?= csrf_field() ?><button class="text-ink-400 hover:text-copper">Remove</button></form>
          </div>
        </article>
        <?php endforeach; ?>
        <?php if (!$showForm && count($addresses) < CustomerService::MAX_ADDRESSES): ?>
        <a href="<?= e(store_url('/account/addresses') . '?edit=new#address-form') ?>" class="rounded-[24px] border-2 border-dashed border-ink/15 hover:border-teal p-6 grid place-items-center text-ink-500 hover:text-teal min-h-[200px]"><span class="text-center"><?= store_icon('plus', 'mx-auto !w-6 !h-6') ?><b class="block mt-2">Add an address</b><span class="text-[12.5px]">Pincode fills state and district</span></span></a>
        <?php endif; ?>
      </section>

      <?php if ($showForm): ?>
      <section id="address-form" class="mt-4 bg-white rounded-[24px] border border-ink/5 p-6 sm:p-8 max-w-3xl scroll-mt-28">
        <h2 class="font-display text-[26px]"><?= $editing ? 'Edit address' : 'Add an address' ?></h2>
        <?= $err('address') ?>
        <form method="post" action="<?= e((string) ($editing ? route('store.account.address.update', ['id' => $editing['id']]) : route('store.account.address.add'))) ?>" class="mt-5 grid sm:grid-cols-6 gap-4"
          data-pincode-url="<?= e((string) route('store.checkout.pincode', ['code' => '000000'])) ?>" data-address-form novalidate>
          <?= csrf_field() ?>
          <label class="block sm:col-span-3"><span class="lbl">Label <span class="text-ink-400 font-normal">(Home, Site — new house…)</span></span><input name="label" value="<?= e($f('label', 'label')) ?>" maxlength="40" class="field"></label>
          <label class="block sm:col-span-3"><span class="lbl">Receiver’s name <span class="text-ink-400 font-normal">(if not you)</span></span><input name="name" value="<?= e($f('name', 'name')) ?>" maxlength="120" class="field"></label>
          <label class="block sm:col-span-2"><span class="lbl">Pincode <span class="text-copper">*</span></span><input name="pincode" id="pin" value="<?= e($f('pincode', 'pincode')) ?>" required inputmode="numeric" maxlength="6" class="field"<?= $inv('pincode') ?>><?= $err('pincode') ?><span id="pinMsg" class="text-[12px] text-ink-400 mt-1 block" aria-live="polite"></span></label>
          <label class="block sm:col-span-2"><span class="lbl">State <span class="text-copper">*</span></span>
            <select name="state" id="state" class="field" required<?= $inv('state') ?>><option value="">Pick state</option><?php foreach ($states as $st): ?><option<?= strcasecmp($st, $stateNow) === 0 ? ' selected' : '' ?>><?= e($st) ?></option><?php endforeach; ?></select><?= $err('state') ?></label>
          <label class="block sm:col-span-2"><span class="lbl">District / city</span><input name="city" id="city" value="<?= e((string) old('city', $editing['city'] ?? ($editing['district'] ?? ''))) ?>" maxlength="120" class="field"></label>
          <label class="block sm:col-span-3"><span class="lbl">Area / post office</span><input name="area" value="<?= e((string) old('area', $editing['area'] ?? ($editing['post_office'] ?? ''))) ?>" maxlength="120" class="field" list="areaList"><datalist id="areaList"></datalist></label>
          <label class="block sm:col-span-3"><span class="lbl">Phone at this address <span class="text-ink-400 font-normal">(optional)</span></span><input name="phone" value="<?= e($f('phone', 'phone')) ?>" inputmode="numeric" maxlength="14" class="field"<?= $inv('phone') ?>><?= $err('phone') ?></label>
          <label class="block sm:col-span-6"><span class="lbl">House / building, street <span class="text-copper">*</span></span><input name="line1" value="<?= e($f('line1', 'address_line1')) ?>" required maxlength="255" class="field"<?= $inv('line1') ?>><?= $err('line1') ?></label>
          <label class="block sm:col-span-6"><span class="lbl">Road, village or locality</span><input name="line2" value="<?= e($f('line2', 'address_line2')) ?>" maxlength="255" class="field"></label>
          <label class="sm:col-span-6 flex items-center gap-2.5 text-[14px]"><input type="checkbox" name="is_default" value="1"<?= old('is_default', $editing ? (int) $editing['is_default'] : 0) ? ' checked' : '' ?> class="w-4 h-4 accent-[#1E6E74]"> Use as my default delivery address</label>
          <div class="sm:col-span-6 flex gap-2"><button class="btn btn-dark"><?= $editing ? 'Save address' : 'Add address' ?></button><?php if ($addresses !== []): ?><a href="<?= e(store_url('/account/addresses')) ?>" class="btn btn-line">Cancel</a><?php endif; ?></div>
        </form>
      </section>
      <?php endif; ?>

    <?php else: /* profile */ ?>
      <section class="bg-white rounded-[24px] border border-ink/5 p-6 sm:p-8 max-w-2xl">
        <h2 class="font-display text-[26px]">Profile</h2>
        <form method="post" action="<?= e((string) route('store.account.profile')) ?>" class="mt-5 grid sm:grid-cols-2 gap-4" novalidate>
          <?= csrf_field() ?>
          <label class="block sm:col-span-2"><span class="lbl">Full name</span><input name="name" class="field" maxlength="120" value="<?= e((string) old('name', $user['name'])) ?>"<?= $inv('name') ?>><?= $err('name') ?></label>
          <label class="block"><span class="lbl">Mobile</span><input class="field bg-cream-50 text-ink-500" value="+91 <?= e(Phone::format((string) $user['phone'])) ?>" disabled><span class="text-[12px] text-ink-400">To change your number, contact us.</span></label>
          <label class="block"><span class="lbl">Email</span><input type="email" name="email" class="field" maxlength="190" value="<?= e((string) old('email', (string) $user['email'])) ?>"<?= $inv('email') ?>><?= $err('email') ?><span class="text-[12px] text-ink-400">Used for invoices and password reset codes.</span></label>
          <label class="sm:col-span-2 flex items-start gap-2.5 text-[14px]"><input type="checkbox" name="marketing_consent" value="1"<?= ($customer['marketing_consent'] ?? 0) ? ' checked' : '' ?> class="mt-0.5 w-4 h-4 accent-[#1E6E74]"> Send me new arrivals and offers on WhatsApp</label>
          <div class="sm:col-span-2"><button class="btn btn-dark">Save changes</button></div>
        </form>
      </section>

      <section id="password" class="mt-4 bg-white rounded-[24px] border border-ink/5 p-6 sm:p-8 max-w-2xl scroll-mt-28">
        <h2 class="font-display text-[26px]">Change password</h2>
        <p class="text-ink-500 text-[14px] mt-1">Other phones and computers signed in to your account will be signed out.</p>
        <form method="post" action="<?= e((string) route('store.account.password')) ?>" class="mt-5 grid sm:grid-cols-2 gap-4" novalidate>
          <?= csrf_field() ?>
          <label class="block sm:col-span-2"><span class="lbl">Current password</span><input type="password" name="current_password" class="field" autocomplete="current-password" maxlength="72"<?= $inv('current_password') ?>><?= $err('current_password') ?></label>
          <label class="block"><span class="lbl">New password <span class="text-ink-400 font-normal">(8+ characters)</span></span><input type="password" name="password" class="field" autocomplete="new-password" minlength="8" maxlength="72"<?= $inv('password') ?>><?= $err('password') ?></label>
          <label class="block"><span class="lbl">New password again</span><input type="password" name="password_confirmation" class="field" autocomplete="new-password" maxlength="72"<?= $inv('password_confirmation') ?>><?= $err('password_confirmation') ?></label>
          <div class="sm:col-span-2"><button class="btn btn-dark">Change password</button></div>
        </form>
      </section>
    <?php endif; ?>
    </div>
  </div>
</div>
