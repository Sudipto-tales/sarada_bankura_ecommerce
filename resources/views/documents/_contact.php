<?php
/** Footer contact strip. Needs $business. */
$addr = array_filter([$business['address']['line1'] ?? '', $business['address']['line2'] ?? '', trim(($business['address']['city'] ?? '') . ', ' . ($business['address']['state'] ?? '') . ' ' . ($business['address']['pincode'] ?? ''), ', ')]);
?>
<div class="doc-contact">
  <div><b>Call us</b><?= e((string) ($business['phone'] ?: '—')) ?></div>
  <div><b>Email</b><?= e((string) ($business['email'] ?: '—')) ?></div>
  <div><b>Visit us</b><?= e(implode(', ', $addr) ?: '—') ?></div>
</div>
