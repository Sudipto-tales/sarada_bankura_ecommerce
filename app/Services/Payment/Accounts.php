<?php

namespace App\Services\Payment;

use Vayu\Database\DB;
use Vayu\Validation\ValidationException;

/**
 * Receiving accounts (payment_accounts) — the only writer. Every change is "sensitive": the caller
 * must pass the confirmation OTP, and the change is written to audit_logs with values masked.
 *
 * Honest limit (docs/payments.md §5): the OTP is the fixed placeholder 123456 until a real
 * server-side OTP exists. It is checked here on the server as well, so swapping in a real OTP
 * later only changes checkOtp().
 */
final class Accounts
{
    public const TYPES = ['upi' => 'UPI ID', 'bank' => 'Bank account', 'cash' => 'Cash counter'];

    public const LABELS = [
        'name' => 'Name', 'type' => 'Type', 'upi_id' => 'UPI ID', 'payee_name' => 'Payee name', 'bank_name' => 'Bank',
        'account_holder' => 'Account holder', 'account_number' => 'Account no.', 'ifsc' => 'IFSC',
        'is_default_upi' => 'Default for the payment window', 'is_active' => 'Active', 'notes' => 'Notes',
    ];

    private const PLACEHOLDER_OTP = '123456';

    public static function checkOtp(?string $otp): void
    {
        if (!hash_equals(self::PLACEHOLDER_OTP, preg_replace('/\D+/', '', (string) $otp) ?? '')) {
            throw new PaymentException('That OTP is not correct — nothing was changed.');
        }
    }

    /** @return list<array<string,mixed>> */
    public static function all(bool $activeOnly = false): array
    {
        return DB::select('SELECT * FROM payment_accounts' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY is_active DESC, is_default_upi DESC, type, name');
    }

    public static function find(int $id): ?array
    {
        return DB::selectOne('SELECT * FROM payment_accounts WHERE id = ?', [$id]);
    }

    /** The UPI account the payment window shows: the active default, else the first active UPI. */
    public static function defaultUpi(): ?array
    {
        return DB::selectOne("SELECT * FROM payment_accounts WHERE type = 'upi' AND is_active = 1 ORDER BY is_default_upi DESC, id LIMIT 1");
    }

    /** Bank details shown in the window for amounts above the UPI limit. */
    public static function bankForWindow(): ?array
    {
        return DB::selectOne("SELECT * FROM payment_accounts WHERE type = 'bank' AND is_active = 1 ORDER BY id LIMIT 1");
    }

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed> clean row
     */
    public static function validate(array $in): array
    {
        $err = [];
        $type = (string) ($in['type'] ?? '');
        if (!isset(self::TYPES[$type])) {
            $err['type'][] = 'Choose UPI, bank account or cash counter.';
            $type = 'cash';
        }
        $clean = static fn(string $k, int $max): ?string => blank_to_null(mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($in[$k] ?? '')) ?? ''), 0, $max));
        $row = [
            'name' => $clean('name', 80),
            'type' => $type,
            'upi_id' => null, 'payee_name' => null, 'bank_name' => null, 'account_holder' => null, 'account_number' => null, 'ifsc' => null,
            'is_active' => !empty($in['is_active']) ? 1 : 0,
            'is_default_upi' => 0,
            'notes' => blank_to_null(mb_substr(trim((string) ($in['notes'] ?? '')), 0, 1000)),
        ];
        if ($row['name'] === null || mb_strlen($row['name']) < 2) {
            $err['name'][] = 'Give the account a name, e.g. “SBI current” or “Shop counter”.';
        }
        if ($type === 'upi') {
            $row['upi_id'] = strtolower((string) $clean('upi_id', 80));
            $row['payee_name'] = $clean('payee_name', 120);
            if (!preg_match('/^[a-z0-9.\-_]{2,64}@[a-z][a-z0-9]{1,63}$/', $row['upi_id'])) {
                $err['upi_id'][] = 'Enter a UPI ID like maasarada@sbi.';
            }
            if ($row['payee_name'] === null) {
                $err['payee_name'][] = 'Enter the name customers will see in their UPI app.';
            }
            $row['is_default_upi'] = !empty($in['is_default_upi']) && $row['is_active'] ? 1 : 0;
        }
        if ($type === 'bank') {
            $row['bank_name'] = $clean('bank_name', 120);
            $row['account_holder'] = $clean('account_holder', 120);
            $row['account_number'] = preg_replace('/\s+/', '', (string) ($in['account_number'] ?? '')) ?: null;
            $row['ifsc'] = strtoupper(trim((string) ($in['ifsc'] ?? ''))) ?: null;
            if ($row['bank_name'] === null) {
                $err['bank_name'][] = 'Enter the bank name.';
            }
            if ($row['account_holder'] === null) {
                $err['account_holder'][] = 'Enter the account holder’s name.';
            }
            if (!preg_match('/^\d{9,18}$/', (string) $row['account_number'])) {
                $err['account_number'][] = 'Account numbers are 9–18 digits.';
            }
            if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', (string) $row['ifsc'])) {
                $err['ifsc'][] = 'Enter an 11-character IFSC like SBIN0001234.';
            }
        }
        if ($err) {
            throw new ValidationException($err);
        }

        return $row;
    }

    /**
     * Create (id null) or update an account. Returns the id.
     *
     * @param array<string,mixed> $in
     */
    public static function save(array $in, ?int $id, ?string $otp, ?int $by = null): int
    {
        self::checkOtp($otp);
        $row = self::validate($in);
        $old = $id ? self::find($id) : null;
        if ($id && $old === null) {
            throw new PaymentException('That account no longer exists.');
        }
        $now = date('Y-m-d H:i:s');

        return DB::transaction(static function () use ($row, $old, $id, $now, $by): int {
            if ($row['is_default_upi']) {
                DB::update('UPDATE payment_accounts SET is_default_upi = 0, updated_at = ? WHERE is_default_upi = 1' . ($id ? ' AND id <> ' . (int) $id : ''), [$now]);
            }
            if ($old === null) {
                $id = (int) DB::table('payment_accounts')->insertGetId($row + ['created_at' => $now, 'updated_at' => $now]);
                Audit::log('payment_account', $id, 'create', self::diff([], $row), $by);
            } else {
                $changes = self::diff($old, $row);
                if (!$changes) {
                    return (int) $id;
                }
                DB::table('payment_accounts')->where('id', $id)->update($row + ['updated_at' => $now]);
                Audit::log('payment_account', $id, 'update', $changes, $by);
            }

            return (int) $id;
        });
    }

    public static function setDefault(int $id, ?string $otp, ?int $by = null): void
    {
        self::checkOtp($otp);
        $a = self::find($id);
        if ($a === null || $a['type'] !== 'upi' || !(int) $a['is_active']) {
            throw new PaymentException('Only an active UPI account can be the default for the payment window.');
        }
        $prev = DB::selectOne("SELECT id, name FROM payment_accounts WHERE is_default_upi = 1 AND id <> ?", [$id]);
        $now = date('Y-m-d H:i:s');
        DB::transaction(static function () use ($id, $a, $prev, $now, $by): void {
            DB::update('UPDATE payment_accounts SET is_default_upi = CASE WHEN id = ? THEN 1 ELSE 0 END, updated_at = ? WHERE is_default_upi = 1 OR id = ?', [$id, $now, $id]);
            Audit::log('payment_account', $id, 'set_default', ['is_default_upi' => [$prev['name'] ?? '—', $a['name']]], $by);
        });
    }

    /** Delete needs the OTP and the account name typed exactly. Accounts with money recorded can only be deactivated. */
    public static function delete(int $id, ?string $typedName, ?string $otp, ?int $by = null): void
    {
        self::checkOtp($otp);
        $a = self::find($id);
        if ($a === null) {
            throw new PaymentException('That account no longer exists.');
        }
        if (mb_strtolower(trim((string) $typedName)) !== mb_strtolower((string) $a['name'])) {
            throw new PaymentException('Type the account name exactly to delete it.');
        }
        $used = (int) (DB::selectOne('SELECT (SELECT COUNT(*) FROM payments WHERE payment_account_id = ?) + (SELECT COUNT(*) FROM payment_refunds WHERE payment_account_id = ?) + (SELECT COUNT(*) FROM payment_attempts WHERE payment_account_id = ?) AS n', [$id, $id, $id])['n'] ?? 0);
        if ($used > 0) {
            throw new PaymentException('“' . $a['name'] . '” has payments recorded against it, so it can’t be deleted. Switch it off (inactive) instead.');
        }
        DB::transaction(static function () use ($id, $a, $by): void {
            DB::table('payment_accounts')->where('id', $id)->delete();
            Audit::log('payment_account', $id, 'delete', self::diff($a, []), $by);
        });
    }

    /**
     * Masked old → new for the fields that changed.
     *
     * @return array<string, array{0:?string,1:?string}>
     */
    public static function diff(array $old, array $new): array
    {
        $out = [];
        foreach (array_keys(self::LABELS) as $k) {
            $o = array_key_exists($k, $old) ? self::mask($k, $old[$k]) : null;
            $n = array_key_exists($k, $new) ? self::mask($k, $new[$k]) : null;
            if ((string) ($old[$k] ?? '') !== (string) ($new[$k] ?? '')) {
                $out[$k] = [$o, $n];
            }
        }

        return $out;
    }

    /** Masking for logs and review dialogs: account no. → last 4, UPI ID → first 5 + handle. */
    public static function mask(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $v = (string) $value;
        if ($field === 'account_number') {
            return '••••' . substr($v, -4);
        }
        if ($field === 'upi_id' && str_contains($v, '@')) {
            [$local, $handle] = explode('@', $v, 2);

            return mb_substr($local, 0, 5) . '••@' . $handle;
        }
        if ($field === 'is_default_upi' || $field === 'is_active') {
            return (int) $v ? 'Yes' : 'No';
        }

        return $v;
    }

    /** One-line label for selects/lists: "SBI current · ••4521". */
    public static function label(?array $a): string
    {
        if ($a === null) {
            return '—';
        }
        $tail = match ($a['type']) {
            'upi' => (string) $a['upi_id'],
            'bank' => '••' . substr((string) $a['account_number'], -4),
            default => 'Cash',
        };

        return $a['name'] . ' · ' . $tail;
    }
}
