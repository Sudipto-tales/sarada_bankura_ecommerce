<?php
/** Shared display helpers for the customer views (closures, so they can be included more than once). */
use App\Services\Customer\CustomerService as CS;

$cuTone = ['#fff0bf;color:#91670a', '#e3eef8;color:#3d6a92', '#efe7f6;color:#6f4a92', '#e4f4e9;color:#267a55', '#fbe1dc;color:#b3483a', '#f1e8d6;color:#6d5e43'];
$cuAvatar = static function (array $c, string $size = '') use ($cuTone): string {
    $tone = $cuTone[(int) $c['id'] % count($cuTone)];

    return '<span class="avatar-sq cu-av' . ($size ? ' ' . $size : '') . '" style="background:' . $tone . '">' . e(CS::initials((string) $c['name'])) . '</span>';
};
$cuType = static fn(string $t): string => '<span class="cu-type cu-type-' . e($t) . '">' . e(CS::TYPES[$t] ?? ucfirst($t)) . '</span>';
$cuLife = static fn(string $l): string => '<span class="cu-life cu-life-' . e($l) . '">' . ($l === 'vip' ? '★ ' : '') . e(CS::LIFECYCLES[$l] ?? ucfirst($l)) . '</span>';
$cuStatus = static function (string $s): string {
    $cls = ['active' => 'ok', 'inactive' => 'off', 'blocked' => 'bad'][$s] ?? 'off';

    return '<span class="status-dot ' . $cls . '">' . e(CS::STATUSES[$s] ?? ucfirst($s)) . '</span>';
};
$cuTag = static fn(array $t): string => '<span class="cu-tag" style="background:' . (CS::TAG_COLORS[$t['color'] ?? 'gold'] ?? CS::TAG_COLORS['gold']) . '">' . e((string) $t['name']) . '</span>';
$cuMoney = static fn($v): string => (float) $v > 0 ? '₹' . number_format((float) $v, 0, '.', ',') : '—';
$cuDate = static fn($v, string $f = 'j M Y'): string => $v ? date($f, (int) strtotime((string) $v)) : '—';
