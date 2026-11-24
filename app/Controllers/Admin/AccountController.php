<?php

namespace App\Controllers\Admin;

use App\Services\AdminAccess\AccessException;
use App\Services\AdminAccess\Guard;
use App\Services\AdminAccess\Otp;
use App\Services\AdminAccess\Passwords;
use App\Services\AdminAccess\Roles;
use App\Services\AdminAccess\Staff;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

/** My account: profile, change password with an emailed OTP, login history, log out other devices. */
class AccountController
{
    public function show(): Response
    {
        $u = Guard::user();
        $id = (int) $u['id'];

        return view('admin.account.show', [
            'user' => $u,
            'roles' => Roles::forUser($id),
            'history' => DB::select('SELECT * FROM admin_login_attempts WHERE admin_user_id = ? ORDER BY id DESC LIMIT 15', [$id]),
            'devices' => DB::select('SELECT device, last_used_at, expires_at FROM admin_remember_tokens WHERE admin_user_id = ? AND expires_at > ? ORDER BY id DESC', [$id, date('Y-m-d H:i:s')]),
            'codeSent' => (bool) session()->get('admin_code_sent'),
            'resendIn' => Otp::resendIn($id, 'change_password'),
        ]);
    }

    public function sendCode(): Response
    {
        $u = Guard::user();
        try {
            Otp::send((int) $u['id'], 'change_password');
            session()->put('admin_code_sent', 1);
            session()->flash('success', 'A 6-digit code was sent to ' . Otp::maskEmail($u['email']) . '. It is valid for 10 minutes.');
        } catch (AccessException $e) {
            session()->flash('error', $e->getMessage());
        }

        return redirect(route('admin.account') . '#password');
    }

    public function changePassword(Request $request): Response
    {
        $u = Guard::user();
        $new = (string) $request->input('password', '');
        $confirm = (string) $request->input('password_confirmation', '');
        try {
            $problem = Passwords::problem($new, $confirm, (string) $u['password']);
            if ($problem !== null) {
                throw new AccessException($problem);
            }
            Otp::verify((int) $u['id'], 'change_password', (string) $request->input('code', ''));
            Staff::setPassword((int) $u['id'], $new, $confirm);
        } catch (AccessException $e) {
            session()->flash('error', $e->getMessage());

            return redirect(route('admin.account') . '#password');
        }
        session()->forget('admin_code_sent');
        session()->flash('success', 'Password changed. Your other devices have been logged out.');

        return redirect(route('admin.account'));
    }

    public function logoutOthers(): Response
    {
        Guard::logoutOthers((int) Guard::user()['id']);
        session()->flash('success', 'All your other devices and browsers have been logged out.');

        return redirect(route('admin.account'));
    }
}
