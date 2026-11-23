<?php
/** Shared display helpers for the order views. */
use App\Services\Order\Money;
use App\Services\Order\OrderService as OS;
use App\Services\Order\PaymentService as PS;

$ordStatus = static fn(string $s): string => '<span class="ord-st ord-' . e(OS::TONES[$s] ?? 'grey') . '">' . e(OS::STATUSES[$s] ?? ucfirst($s)) . '</span>';
$payTone = ['unpaid' => 'red', 'partially_paid' => 'amber', 'paid' => 'green', 'overpaid' => 'violet', 'refunded' => 'grey'];
$payBadge = static function (array $o) use ($payTone): string {
    if ($o['order_status'] === 'rejected' && (float) $o['paid_amount'] <= 0) {
        return '<span class="ord-pay ord-grey">—</span>';
    }
    $s = (string) $o['payment_status'];

    return '<span class="ord-pay ord-' . ($payTone[$s] ?? 'grey') . '">' . e(PS::STATUSES[$s] ?? $s) . '</span>';
};
$inr = static fn($v): string => Money::inr($v);
$inr0 = static fn($v): string => Money::inr($v, (float) $v == floor((float) $v) ? 0 : 2);
$when = static fn($v, string $f = 'j M Y, g:i a'): string => $v ? date($f, (int) strtotime((string) $v)) : '—';
$channelIcon = ['website' => 'globe', 'walk_in' => 'footprints', 'showroom' => 'store', 'phone' => 'phone', 'whatsapp' => 'message-circle'];
$qtyFmt = static fn($q): string => rtrim(rtrim(number_format((float) $q, 3, '.', ','), '0'), '.');
