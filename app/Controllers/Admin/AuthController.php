<?php

namespace App\Controllers\Admin;

use App\Services\AdminAccess\AccessException;
use App\Services\AdminAccess\Guard;
use App\Services\AdminAccess\Invites;
use App\Services\AdminAccess\LoginId;
use App\Services\AdminAccess\Otp;
use App\Services\AdminAccess\Passwords;
use App\Services\AdminAccess\Staff;
use App\Services\Mail\Mail;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

/**
 * Login / logout, first-password screen and "Forgot password?" (docs/admin-access.md §4.4–4.5).
 * Errors are flashed by hand (never through ValidationException) so passwords are never kept as
 * "old input" in the session.
 */
class AuthController
{
    public function showLogin(Request $request): Response
    {
        if (Guard::user() !== null) {
            return redirect($this->next((string) $request->query('next', '')));
        }
        $invite = null;
        $token = (string) $request->query('invite', '');
        if ($token !== '') {
            $invite = Invites::find($token);
            if ($invite === null) {
                session()->flash('error', 'This login link has expired or was replaced by a newer one. Ask an admin to send it again.');
            }
        }
        $loginId = (string) (session()->get('admin_login_id') ?? ($invite ? ($invite['email'] ?? $invite['phone']) : ''));

        return view('admin.auth.login', [
            'loginId' => $loginId,
            'invite' => $invite,
            'next' => (string) $request->query('next', ''),
        ]);
    }

    public function login(Request $request): Response
    {
        $loginId = trim((string) $request->input('login_id', ''));
        $next = (string) $request->input('next', '');
        try {
            $u = Guard::attempt($loginId, (string) $request->input('password', ''), (bool) $request->input('remember'));
        } catch (AccessException $e) {
            session()->flash('error', $e->getMessage());
            session()->flash('admin_login_id', $loginId);

            return redirect(route('admin.login') . ($next !== '' ? '?next=' . rawurlencode($next) : ''));
        }
        if ((int) $u['must_change_password'] === 1) {
            return redirect(route('admin.password.set'));
        }
        session()->flash('success', 'Welcome back, ' . $u['name'] . '.');

        return redirect($this->next($next));
    }

    public function logout(): Response
    {
        Guard::logout();
        session()->flash('success', 'You have logged out.');

        return redirect(route('admin.login'));
    }

    /* ------------------------------------------------------------ first password */

    public function showSetPassword(): Response
    {
        $u = Guard::user();
        if ((int) $u['must_change_password'] !== 1) {
            return redirect(route('admin.dashboard'));
        }

        return view('admin.auth.set-password', ['user' => $u]);
    }

    public function setPassword(Request $request): Response
    {
        $u = Guard::user();
        if ((int) $u['must_change_password'] !== 1) {
            return redirect(route('admin.dashboard'));
        }
        try {
            Staff::setPassword((int) $u['id'], (string) $request->input('password', ''), (string) $request->input('password_confirmation', ''));
        } catch (AccessException $e) {
            session()->flash('error', $e->getMessage());

            return redirect(route('admin.password.set'));
        }
        Guard::forget();
        session()->flash('success', 'Your password is set. Welcome to Maa Sarada Admin, ' . $u['name'] . '.');

        return redirect(route('admin.dashboard'));
    }

    /* ------------------------------------------------------------ forgot password (OTP by email) */

    public function showForgot(): Response
    {
        return view('admin.auth.forgot', ['mailReady' => Mail::configured()]);
    }

    /**
     * Same answer whether or not the email belongs to a staff account (no account discovery).
     * A mobile number gets the "ask an admin" message without being looked up.
     */
    public function sendForgot(Request $request): Response
    {
        [$kind, $id] = LoginId::parse((string) $request->input('login_id', ''));
        if ($kind !== 'email') {
            session()->flash('error', $kind === 'phone'
                ? 'Codes are sent by email only. If you log in with a mobile number, ask an admin to reset your password.'
                : 'Enter the email you log in with.');
            session()->flash('admin_login_id', (string) $request->input('login_id', ''));

            return redirect(route('admin.forgot'));
        }
        if (!Mail::configured()) {
            session()->flash('error', 'Email isn’t set up on this site yet, so codes can’t be sent. Ask an admin to reset your password.');

            return redirect(route('admin.forgot'));
        }
        $u = DB::selectOne("SELECT * FROM admin_users WHERE email = ? AND status <> 'disabled'", [$id]);
        if ($u !== null) {
            try {
                Otp::send((int) $u['id'], 'forgot_password');
            } catch (AccessException $e) {
                if ($e->retryAfter === 0 || $e->retryAfter >= 3600) {
                    session()->flash('error', $e->getMessage());

                    return redirect(route('admin.forgot'));
                }
                // resend cooldown: the earlier code is still valid — carry on to the code screen
            }
        }
        session()->put('admin_forgot', ['email' => $id, 'uid' => $u ? (int) $u['id'] : 0, 'at' => time()]);
        session()->flash('info', 'If ' . $id . ' belongs to a staff account, a 6-digit code is on its way. It is valid for 10 minutes.');

        return redirect(route('admin.forgot.reset'));
    }

    public function showReset(): Response
    {
        $f = session()->get('admin_forgot');
        if (!is_array($f) || time() - (int) $f['at'] > Otp::TTL + 600) {
            session()->forget('admin_forgot');

            return redirect(route('admin.forgot'));
        }

        return view('admin.auth.reset', ['email' => (string) $f['email'], 'resendIn' => $f['uid'] ? Otp::resendIn((int) $f['uid'], 'forgot_password') : max(0, (int) $f['at'] + Otp::RESEND_AFTER - time())]);
    }

    public function reset(Request $request): Response
    {
        $f = session()->get('admin_forgot');
        if (!is_array($f)) {
            return redirect(route('admin.forgot'));
        }
        $uid = (int) $f['uid'];
        $new = (string) $request->input('password', '');
        $confirm = (string) $request->input('password_confirmation', '');
        try {
            $u = $uid ? Guard::find($uid) : null;
            $problem = Passwords::problem($new, $confirm, $u ? (string) $u['password'] : null);
            if ($problem !== null) {
                throw new AccessException($problem);
            }
            if ($u === null) {
                throw new AccessException('That code is not correct.');
            }
            Otp::verify($uid, 'forgot_password', (string) $request->input('code', ''));
            Staff::setPassword($uid, $new, $confirm);
        } catch (AccessException $e) {
            session()->flash('error', $e->getMessage());

            return redirect(route('admin.forgot.reset'));
        }
        session()->forget('admin_forgot');
        session()->flash('success', 'Password changed. Log in with your new password.');
        session()->flash('admin_login_id', (string) $f['email']);

        return redirect(route('admin.login'));
    }

    /** Only same-site /admin paths are followed after login (no open redirect). */
    private function next(string $next): string
    {
        if ($next !== '' && str_starts_with($next, '/admin') && !str_starts_with($next, '//') && !str_contains($next, '\\')
            && !preg_match('#^/admin/(login|logout|forgot)#', $next)) {
            return $next;
        }

        return route('admin.dashboard');
    }
}
