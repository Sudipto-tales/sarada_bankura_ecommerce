<?php

namespace App\Controllers\Admin;

use App\Services\AdminAccess\AccessException;
use App\Services\AdminAccess\Guard;
use App\Services\AdminAccess\Roles;
use App\Services\AdminAccess\Staff;
use App\Services\Mail\Mail;
use Vayu\Exceptions\HttpException;
use Vayu\Http\Request;
use Vayu\Http\Response;

/**
 * /admin/staff — add staff (incl. another Owner), invite by email / WhatsApp link, resend,
 * reset password, edit, disable / enable. Needs staff.manage (Owner by default); the rules
 * themselves live in App\Services\AdminAccess\Staff.
 */
class StaffController
{
    public function index(): Response
    {
        $me = $this->actor();

        return view('admin.staff.index', [
            'rows' => Staff::all(),
            'roles' => Roles::all(),
            'me' => $me,
            'meOwner' => Roles::isOwner($me),
            'mailReady' => Mail::configured(),
            'issued' => session()->get('staff_issued'),
        ]);
    }

    public function store(Request $request): Response
    {
        $me = $this->actor();
        try {
            $r = Staff::create($this->input($request), $me);
        } catch (AccessException $e) {
            session()->flash('error', $e->getMessage());

            return redirect(route('admin.staff'));
        }

        return $this->issued($r['id'], $r, (string) $request->input('send', 'none'), 'invite', 'Staff member added');
    }

    public function update(Request $request, string $id): Response
    {
        $me = $this->actor();
        try {
            Staff::update((int) $id, $this->input($request), $me);
            session()->flash('success', 'Staff details saved.');
        } catch (AccessException $e) {
            session()->flash('error', $e->getMessage());
        }

        return redirect(route('admin.staff'));
    }

    public function resend(Request $request, string $id): Response
    {
        $me = $this->actor();
        try {
            $r = Staff::resendInvite((int) $id, $me);
        } catch (AccessException $e) {
            session()->flash('error', $e->getMessage());

            return redirect(route('admin.staff'));
        }

        return $this->issued((int) $id, $r, (string) $request->input('send', 'none'), 'invite', 'New invite made — the old link and password no longer work');
    }

    public function reset(Request $request, string $id): Response
    {
        $me = $this->actor();
        try {
            $r = Staff::resetPassword((int) $id, $me, (string) $request->input('my_password', ''));
        } catch (AccessException $e) {
            session()->flash('error', $e->getMessage());

            return redirect(route('admin.staff'));
        }
        $u = Guard::find((int) $id);

        return $this->issued((int) $id, $r, (string) $request->input('send', 'none'), $u['status'] === 'invited' ? 'invite' : 'reset', 'Password reset — they have been logged out everywhere');
    }

    public function disable(string $id): Response
    {
        $me = $this->actor();
        try {
            Staff::disable((int) $id, $me);
            session()->flash('success', 'Account switched off. They have been logged out and can’t log in.');
        } catch (AccessException $e) {
            session()->flash('error', $e->getMessage());
        }

        return redirect(route('admin.staff'));
    }

    public function enable(string $id): Response
    {
        $me = $this->actor();
        try {
            Staff::enable((int) $id, $me);
            session()->flash('success', 'Account switched back on.');
        } catch (AccessException $e) {
            session()->flash('error', $e->getMessage());
        }

        return redirect(route('admin.staff'));
    }

    /* ------------------------------------------------------------ */

    /** Send the message, then show the password + link once (flash) on the staff page. */
    private function issued(int $id, array $r, string $send, string $purpose, string $title): Response
    {
        $d = Staff::deliver($id, $r, $send, $purpose);
        $u = Guard::find($id);
        session()->flash('staff_issued', [
            'title' => $title, 'name' => $u['name'], 'email' => $u['email'], 'phone' => $u['phone'],
            'password' => $r['password'], 'link' => $r['link'], 'message' => $d['message'],
            'emailed' => $d['email'], 'email_error' => $d['email_error'], 'whatsapp' => $d['whatsapp'],
        ]);
        if ($d['email_error']) {
            session()->flash('warning', 'The email could not be sent (' . $d['email_error'] . '). Copy the details below or use WhatsApp.');
        } else {
            session()->flash('success', $title . ($d['email'] ? ' — email sent to ' . $d['email'] : '') . '.');
        }

        return redirect(route('admin.staff'));
    }

    private function input(Request $request): array
    {
        return [
            'name' => (string) $request->input('name', ''),
            'email' => (string) $request->input('email', ''),
            'phone' => (string) $request->input('phone', ''),
            'roles' => (array) ($request->input('roles') ?? []),
        ];
    }

    /** The logged-in admin's id; 403 without staff.manage. */
    private function actor(): int
    {
        if (!Guard::can('staff.manage')) {
            throw new HttpException(403, 'You don’t have access to Staff & roles.');
        }

        return (int) Guard::id();
    }
}
