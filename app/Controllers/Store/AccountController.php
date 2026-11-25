<?php

namespace App\Controllers\Store;

use App\Services\Customer\CustomerService;
use App\Services\Store\AuthException;
use App\Services\Store\Checkout;
use App\Services\Store\Context;
use App\Services\Store\CustomerAuth;
use App\Services\Store\Saved;
use App\Services\Store\View;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;
use Vayu\Validation\ValidationException;

/**
 * Customer accounts (docs/storefront.md §3.7, §11).
 *
 *   GET  /signin                 sign in · create account · forgot password (one page, tabs)
 *   POST /signin                 phone-or-email + password (+ remember) → next
 *   POST /register               name, phone, email?, password, consent → next
 *   POST /signin/forgot          email → 6-digit code (same answer whether the account exists)
 *   POST /signin/reset           email + code + new password → signed in
 *   POST /signout
 *   GET  /account[/{tab}]        orders · addresses · profile          (RequireCustomer)
 *   POST /account/profile | /account/password | /account/addresses[/{id}[/default|/delete]]
 *
 * Every sign-in form carries `next` (same-site path) and `saved` (the guest saved list from
 * localStorage, merged into the account).
 */
class AccountController
{
    public const TABS = ['orders' => ['package', 'Orders'], 'addresses' => ['map-pin', 'Addresses'], 'profile' => ['user-round', 'Profile']];

    /* ================================================================ sign in / register */

    public function signin(Request $request): Response
    {
        View::loadHelpers();
        $next = CustomerAuth::safeNext((string) $request->query('next', ''), '');
        if (Context::customer() !== null) {
            return redirect(store_url($next !== '' ? $next : '/account'));
        }

        return View::page('store.signin', [
            'next' => $next,
            'forCheckout' => str_starts_with($next, '/checkout'),
            'tab' => in_array($t = (string) (session()->get('signin_tab', '') ?: $request->query('tab', '')), ['in', 'up', 'forgot', 'reset'], true) ? $t : 'in',
            'mailOn' => \App\Services\Mail\Mail::configured(),
            'resetEmail' => (string) session()->get('reset_email', ''),
        ], [
            'title' => 'Sign in',
            'active' => 'account',
            'head' => '<meta name="robots" content="noindex">',
        ]);
    }

    public function login(Request $request): Response
    {
        View::loadHelpers();
        $next = CustomerAuth::safeNext((string) $request->input('next', ''));
        try {
            CustomerAuth::attempt((string) $request->input('login', ''), (string) $request->input('password', ''),
                (bool) $request->input('remember'), $request->ip(), Saved::cleanSlugs($request->input('saved', '')));
        } catch (AuthException $e) {
            session()->flash('error', $e->getMessage());
            session()->flashInput(['login' => (string) $request->input('login', '')]);

            return redirect(store_signin_url($next));
        }
        session()->flash('success', 'Welcome back, ' . self::firstName((string) Context::customer()['name']) . '.');

        return redirect(store_url($next));
    }

    public function register(Request $request): Response
    {
        View::loadHelpers();
        $next = CustomerAuth::safeNext((string) $request->input('next', ''));
        $in = (array) $request->input();
        try {
            CustomerAuth::register($in, $request->ip(), Saved::cleanSlugs($in['saved'] ?? ''));
        } catch (ValidationException $e) {
            return $this->back($e, ['name', 'phone', 'email', 'marketing_consent'], store_signin_url($next, 'up'), 'up');
        }
        $u = Context::customer();
        $orders = $u['customer_id'] ? (int) DB::table('orders')->where('customer_id', (int) $u['customer_id'])->count() : 0;
        session()->flash('success', 'Your account is ready' . ($orders > 0 ? ' — we found ' . $orders . ' earlier order' . ($orders === 1 ? '' : 's') . ' on your mobile number.' : '.'));

        return redirect(store_url($next));
    }

    public function forgot(Request $request): Response
    {
        View::loadHelpers();
        $next = CustomerAuth::safeNext((string) $request->input('next', ''), '');
        $email = trim((string) $request->input('email', ''));
        $r = CustomerAuth::sendResetCode($email, $request->ip());
        session()->flash($r['ok'] ? 'success' : 'error', $r['message']);
        session()->flash('signin_tab', $r['ok'] ? 'reset' : 'forgot');
        session()->flash('reset_email', $email);

        return redirect(store_signin_url($next, $r['ok'] ? 'reset' : 'forgot'));
    }

    public function reset(Request $request): Response
    {
        View::loadHelpers();
        $next = CustomerAuth::safeNext((string) $request->input('next', ''));
        $email = trim((string) $request->input('email', ''));
        try {
            CustomerAuth::resetPassword($email, (string) $request->input('code', ''), (string) $request->input('password', ''),
                $request->ip(), Saved::cleanSlugs($request->input('saved', '')));
        } catch (ValidationException $e) {
            session()->flash('signin_tab', 'reset');
            session()->flash('reset_email', $email);

            return $this->back($e, ['email'], store_signin_url(CustomerAuth::safeNext($next, ''), 'reset'), 'reset');
        }
        session()->flash('success', 'Password changed. You’re signed in.');

        return redirect(store_url($next));
    }

    public function logout(): Response
    {
        View::loadHelpers();
        CustomerAuth::logout();
        session()->flash('success', 'You’re signed out.');

        return redirect(store_url('/'));
    }

    /* ================================================================ account pages */

    public function show(Request $request, string $tab = 'orders'): Response
    {
        View::loadHelpers();
        if (!isset(self::TABS[$tab])) {
            return redirect(store_url('/account'));
        }
        $u = Context::customer();
        $cid = (int) ($u['customer_id'] ?? 0);
        $data = ['tab' => $tab, 'user' => $u, 'tabs' => self::TABS, 'customer' => $cid ? DB::selectOne('SELECT * FROM customers WHERE id = ?', [$cid]) : null];
        if ($tab === 'orders') {
            $data['orders'] = $cid ? self::orders($cid) : [];
        } elseif ($tab === 'addresses') {
            $data['addresses'] = $cid ? DB::select('SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, id DESC', [$cid]) : [];
            $data['states'] = Checkout::STATES;
            $data['editId'] = (int) $request->query('edit', 0);
        }

        return View::page('store.account', $data, [
            'title' => 'Your account',
            'active' => 'account',
            'head' => '<meta name="robots" content="noindex">',
        ]);
    }

    public function profile(Request $request): Response
    {
        View::loadHelpers();
        try {
            CustomerAuth::updateProfile(Context::customer(), (array) $request->input());
        } catch (ValidationException $e) {
            return $this->back($e, ['name', 'email', 'marketing_consent'], store_url('/account/profile'));
        }
        session()->flash('success', 'Profile saved.');

        return redirect(store_url('/account/profile'));
    }

    public function password(Request $request): Response
    {
        View::loadHelpers();
        try {
            CustomerAuth::changePassword((int) Context::customer()['id'], (string) $request->input('current_password', ''),
                (string) $request->input('password', ''), (string) $request->input('password_confirmation', ''));
        } catch (ValidationException $e) {
            return $this->back($e, [], store_url('/account/profile') . '#password');
        }
        session()->flash('success', 'Password changed. Other devices have been signed out.');

        return redirect(store_url('/account/profile'));
    }

    public function saveAddress(Request $request, ?string $id = null): Response
    {
        View::loadHelpers();
        $cid = $this->customerId();
        $aid = $id !== null ? (int) $id : null;
        $in = (array) $request->input();
        $state = Checkout::canonicalState((string) ($in['state'] ?? ''));
        try {
            if (trim((string) ($in['state'] ?? '')) !== '' && $state === null) {
                throw new ValidationException(['state' => ['Pick the state from the list.']]);
            }
            CustomerService::saveOwnAddress($cid, [
                'label' => $in['label'] ?? '', 'name' => $in['name'] ?? '', 'phone' => $in['phone'] ?? '',
                'address_line1' => $in['line1'] ?? '', 'address_line2' => $in['line2'] ?? '', 'area' => $in['area'] ?? '',
                'city' => $in['city'] ?? '', 'district' => $in['city'] ?? '', 'state' => $state ?? '', 'pincode' => $in['pincode'] ?? '',
            ], $aid, (bool) ($in['is_default'] ?? false));
        } catch (ValidationException $e) {
            $map = ['address_line1' => 'line1', 'address_line2' => 'line2'];
            $errs = [];
            foreach ($e->errors() as $k => $m) {
                $errs[$map[$k] ?? $k] = $m;
            }

            return $this->back(new ValidationException($errs), ['label', 'name', 'phone', 'line1', 'line2', 'area', 'city', 'state', 'pincode', 'is_default'],
                store_url('/account/addresses') . '?edit=' . ($aid ?? 'new') . '#address-form');
        }
        session()->flash('success', $aid ? 'Address updated.' : 'Address added.');

        return redirect(store_url('/account/addresses'));
    }

    public function defaultAddress(string $id): Response
    {
        View::loadHelpers();
        if (CustomerService::makeDefaultAddress($this->customerId(), (int) $id)) {
            session()->flash('success', 'Default address changed.');
        }

        return redirect(store_url('/account/addresses'));
    }

    public function deleteAddress(string $id): Response
    {
        View::loadHelpers();
        if (CustomerService::deleteOwnAddress($this->customerId(), (int) $id)) {
            session()->flash('success', 'Address removed. Your past orders keep their delivery details.');
        }

        return redirect(store_url('/account/addresses'));
    }

    /* ================================================================ helpers */

    /** The account's customer id (RequireCustomer guarantees a user; linkUser guarantees a customer). */
    private function customerId(): int
    {
        $u = Context::customer();
        $cid = (int) ($u['customer_id'] ?? 0);
        if ($cid <= 0) {
            $cid = (int) CustomerService::linkUser((int) $u['id']);
        }

        return $cid;
    }

    /** Online + showroom orders, newest first, with a short item line and the first product image. */
    public static function orders(int $cid): array
    {
        $rows = DB::select('SELECT id, order_number, order_status, channel, channel_detail, public_token, grand_total, due_amount, paid_amount,
            pending_verification_amount, payment_status, created_at FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 100', [$cid]);
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $items = [];
        $firstProduct = [];
        foreach (DB::select("SELECT order_id, product_id, product_name FROM order_items WHERE order_id IN ($in) ORDER BY order_id, sort, id", $ids) as $i) {
            $items[(int) $i['order_id']][] = (string) $i['product_name'];
            $firstProduct[(int) $i['order_id']] ??= (int) $i['product_id'];
        }
        $cards = [];
        $pids = array_values(array_unique(array_filter($firstProduct)));
        if ($pids !== []) {
            foreach (\App\Services\Store\Catalog::cards($pids) as $c) {
                $cards[(int) $c['id']] = $c;
            }
        }
        foreach ($rows as &$r) {
            $names = array_values(array_unique($items[(int) $r['id']] ?? []));
            $r['items_line'] = implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? ' +' . (count($names) - 3) . ' more' : '');
            $r['image'] = $cards[$firstProduct[(int) $r['id']] ?? 0]['image'] ?? null;
            $r['can_pay'] = (float) $r['due_amount'] > 0.009 && !in_array($r['order_status'], ['rejected', 'cancelled'], true);
        }

        return $rows;
    }

    private static function firstName(string $name): string
    {
        return explode(' ', trim($name))[0] ?: 'there';
    }

    /** Validation failure → back to the form with errors + the safe-to-refill fields. @param list<string> $keep */
    private function back(ValidationException $e, array $keep, string $to, string $tab = ''): Response
    {
        session()->flash('__errors', $e->errors());
        $in = request()?->input() ?? [];
        session()->flashInput(array_intersect_key(is_array($in) ? $in : [], array_flip($keep)));
        if ($tab !== '') {
            session()->flash('signin_tab', $tab);
        }
        session()->flash('error', 'Please check the highlighted details.');

        return redirect($to);
    }
}
