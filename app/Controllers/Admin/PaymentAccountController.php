<?php

namespace App\Controllers\Admin;

use App\Services\Payment\Accounts;
use App\Services\Payment\Audit;
use App\Services\Payment\PaymentException;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

/**
 * Receiving accounts (docs/payments.md §5). The page loads empty behind an OTP overlay; the
 * account details are sent only after POST /unlock accepts the OTP, so they are never in the page
 * source. Every change goes through the "ask twice" dialog (review old → new, then OTP again),
 * and the OTP is re-checked on the server by Accounts. The OTP is the fixed 123456 placeholder
 * until a real one exists.
 */
class PaymentAccountController
{
    public function index(): Response
    {
        return view('admin.payments.accounts', ['types' => Accounts::TYPES, 'labels' => Accounts::LABELS]);
    }

    public function unlock(Request $request): Response
    {
        try {
            Accounts::checkOtp((string) $request->input('otp'));
        } catch (PaymentException $e) {
            return json(['ok' => false, 'message' => 'That OTP is not correct.'], 403);
        }
        $accounts = Accounts::all();
        $usage = [];
        foreach (DB::select("SELECT payment_account_id AS id, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS s FROM payments WHERE status = 'received' AND payment_account_id IS NOT NULL GROUP BY payment_account_id") as $u) {
            $usage[(int) $u['id']] = ['n' => (int) $u['n'], 's' => (float) $u['s']];
        }

        return json([
            'ok' => true,
            'accounts' => array_map(static fn(array $a): array => [
                'id' => (int) $a['id'], 'name' => $a['name'], 'type' => $a['type'], 'upi_id' => $a['upi_id'], 'payee_name' => $a['payee_name'],
                'bank_name' => $a['bank_name'], 'account_holder' => $a['account_holder'], 'account_number' => $a['account_number'], 'ifsc' => $a['ifsc'],
                'is_default_upi' => (int) $a['is_default_upi'], 'is_active' => (int) $a['is_active'], 'notes' => $a['notes'],
                'label' => Accounts::label($a), 'masked_number' => Accounts::mask('account_number', $a['account_number']),
                'payments' => $usage[(int) $a['id']]['n'] ?? 0, 'received' => $usage[(int) $a['id']]['s'] ?? 0,
                'updated' => $a['updated_at'] ? date('j M Y', strtotime((string) $a['updated_at'])) : null,
            ], $accounts),
            'history' => array_map(static fn(array $h): array => [
                'action' => $h['action'], 'entity_id' => (int) $h['entity_id'], 'changes' => $h['changes'], 'ip' => $h['ip'],
                'when' => date('j M Y, g:i a', strtotime((string) $h['created_at'])),
            ], Audit::history('payment_account', 40)),
        ]);
    }

    public function store(Request $request): Response
    {
        return $this->run(fn() => ['Account “' . trim((string) $request->input('name')) . '” added.', Accounts::save($request->input(), null, (string) $request->input('otp'))]);
    }

    public function update(Request $request, string $id): Response
    {
        return $this->run(fn() => ['Account updated.', Accounts::save($request->input(), (int) $id, (string) $request->input('otp'))]);
    }

    public function makeDefault(Request $request, string $id): Response
    {
        return $this->run(function () use ($request, $id): array {
            Accounts::setDefault((int) $id, (string) $request->input('otp'));

            return ['The payment window now shows this UPI ID.', (int) $id];
        });
    }

    public function destroy(Request $request, string $id): Response
    {
        return $this->run(function () use ($request, $id): array {
            Accounts::delete((int) $id, (string) $request->input('confirm_name'), (string) $request->input('otp'));

            return ['Account deleted.', (int) $id];
        });
    }

    /** @param \Closure(): array{0:string,1:int} $fn */
    private function run(\Closure $fn): Response
    {
        try {
            [$msg, $id] = $fn();
        } catch (PaymentException $e) {
            return json(['ok' => false, 'message' => $e->getMessage()], 409);
        }

        return json(['ok' => true, 'message' => $msg, 'id' => $id]);
    }
}
