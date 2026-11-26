<?php

namespace App\Services\AdminAccess;

use App\Services\Mail\Mail;
use App\Services\Payment\Audit;
use Vayu\Database\DB;
use Vayu\Validation\ValidationException;

/**
 * Staff accounts (docs/admin-access.md). Only writer of admin_users and admin_user_roles.
 *
 * Rules kept here (not only in the screens):
 * - the actor needs staff.manage; only an Owner can give / take the Owner role or touch an Owner;
 * - nobody changes their own roles, disables or resets themselves;
 * - at least one active Owner always remains;
 * - temporary passwords + links last 72 hours, and a new one retires the old.
 */
final class Staff
{
    /* ------------------------------------------------------------ create / edit */

    /**
     * @param array{name?:string,email?:string,phone?:string,roles?:list<int|string>} $in
     * @return array{id:int,password:string,link:string} the temporary password + link, shown once
     */
    public static function create(array $in, int $actorId): array
    {
        self::requireManager($actorId);
        $data = self::validate($in, null, $actorId);

        return DB::transaction(function () use ($data, $actorId): array {
            $now = date('Y-m-d H:i:s');
            $id = DB::table('admin_users')->insertGetId([
                'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'],
                'password' => Passwords::hash(bin2hex(random_bytes(16))), 'status' => 'invited',
                'must_change_password' => 1, 'session_version' => 1, 'created_by' => $actorId,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            self::syncRoles($id, $data['roles']);
            $issued = self::issueTemporary($id, 'invite', $actorId);
            Audit::log('admin_user', $id, 'create', [
                'name' => [null, $data['name']], 'email' => [null, $data['email']], 'phone' => [null, $data['phone']],
                'roles' => [null, implode(', ', self::roleNames($id))],
            ], $actorId);

            return ['id' => $id] + $issued;
        });
    }

    /** Edit name / login IDs / roles. */
    public static function update(int $id, array $in, int $actorId): void
    {
        self::requireManager($actorId);
        $u = self::target($id, $actorId);
        $data = self::validate($in, $u, $actorId);
        $before = ['name' => $u['name'], 'email' => $u['email'], 'phone' => $u['phone'], 'roles' => implode(', ', self::roleNames($id))];

        DB::transaction(function () use ($id, $data): void {
            DB::update('UPDATE admin_users SET name = ?, email = ?, phone = ?, updated_at = ? WHERE id = ?',
                [$data['name'], $data['email'], $data['phone'], date('Y-m-d H:i:s'), $id]);
            self::syncRoles($id, $data['roles']);
        });
        $after = ['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'], 'roles' => implode(', ', self::roleNames($id))];
        $changes = [];
        foreach ($after as $k => $v) {
            if ((string) $before[$k] !== (string) $v) {
                $changes[$k] = [$before[$k], $v];
            }
        }
        if ($changes) {
            Audit::log('admin_user', $id, 'update', $changes, $actorId);
        }
        Roles::flush();
    }

    /* ------------------------------------------------------------ passwords */

    /** New temporary password + link for someone who hasn't logged in yet (old one stops working). */
    public static function resendInvite(int $id, int $actorId): array
    {
        self::requireManager($actorId);
        $u = self::target($id, $actorId);
        if ($u['status'] !== 'invited') {
            throw new AccessException($u['status'] === 'disabled' ? 'Turn this account on first.' : 'This person has already set a password — use Reset password instead.');
        }
        $issued = self::issueTemporary($id, 'invite', $actorId);
        Audit::log('admin_user', $id, 'resend_invite', [], $actorId);

        return $issued;
    }

    /**
     * Admin reset: needs the admin's own password; logs the person out everywhere.
     * @return array{password:string,link:string}
     */
    public static function resetPassword(int $id, int $actorId, string $actorPassword): array
    {
        self::requireManager($actorId);
        if ($id === $actorId) {
            throw new AccessException('You can’t reset your own password here — use My account ▸ Change password.');
        }
        $u = self::target($id, $actorId);
        if ($u['status'] === 'disabled') {
            throw new AccessException('Turn this account on first.');
        }
        self::confirmActor($actorId, $actorPassword);
        $issued = self::issueTemporary($id, $u['status'] === 'invited' ? 'invite' : 'reset', $actorId);
        Guard::logoutEverywhere($id);
        Audit::log('admin_user', $id, 'reset_password', [], $actorId);

        return $issued;
    }

    /**
     * Set the person's own password (forced first change, OTP change, forgot password).
     * Marks the account active, closes open links and logs out their other sessions.
     */
    public static function setPassword(int $id, string $new, string $confirm, bool $allowSameAsCurrent = false): void
    {
        $u = Guard::find($id);
        if ($u === null || $u['status'] === 'disabled') {
            throw new AccessException('This account is switched off.');
        }
        $problem = Passwords::problem($new, $confirm, $allowSameAsCurrent ? null : (string) $u['password']);
        if ($problem !== null) {
            throw new AccessException($problem);
        }
        $now = date('Y-m-d H:i:s');
        DB::update("UPDATE admin_users SET password = ?, status = 'active', must_change_password = 0, temp_password_expires_at = NULL,
            password_changed_at = ?, updated_at = ? WHERE id = ?", [Passwords::hash($new), $now, $now, $id]);
        Invites::close($id);
        Guard::logoutOthers($id);
        Audit::log('admin_user', $id, 'set_password', [], $id);
    }

    /* ------------------------------------------------------------ on / off */

    public static function disable(int $id, int $actorId): void
    {
        self::requireManager($actorId);
        if ($id === $actorId) {
            throw new AccessException('You can’t switch off your own account.');
        }
        $u = self::target($id, $actorId);
        if ($u['status'] === 'disabled') {
            return;
        }
        if (Roles::isOwner($id) && Roles::activeOwnerCount($id) === 0) {
            throw new AccessException('At least one active Owner must remain.');
        }
        DB::update("UPDATE admin_users SET status = 'disabled', updated_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $id]);
        Invites::close($id);
        Guard::logoutEverywhere($id);
        Audit::log('admin_user', $id, 'disable', ['status' => [$u['status'], 'disabled']], $actorId);
    }

    /** Back on: someone who never set a password goes back to invited (needs Resend invite if expired). */
    public static function enable(int $id, int $actorId): void
    {
        self::requireManager($actorId);
        $u = self::target($id, $actorId);
        if ($u['status'] !== 'disabled') {
            return;
        }
        $to = (int) $u['must_change_password'] === 1 && $u['password_changed_at'] === null ? 'invited' : 'active';
        DB::update('UPDATE admin_users SET status = ?, updated_at = ? WHERE id = ?', [$to, date('Y-m-d H:i:s'), $id]);
        Audit::log('admin_user', $id, 'enable', ['status' => ['disabled', $to]], $actorId);
    }

    /* ------------------------------------------------------------ sending */

    /**
     * Send the temporary password + link. $send = email | whatsapp | both | none.
     * Email goes out now (Mailer); WhatsApp returns a wa.me link the admin presses Send on.
     * @return array{email:?string,email_error:?string,whatsapp:?string,message:string}
     */
    public static function deliver(int $id, array $issued, string $send, string $purpose = 'invite'): array
    {
        $u = Guard::find($id);
        $msg = Invites::message($u, $issued['password'], $issued['link'], $purpose);
        $out = ['email' => null, 'email_error' => null, 'whatsapp' => null, 'message' => $msg];
        $channels = [];
        if (in_array($send, ['email', 'both'], true) && !empty($u['email'])) {
            try {
                Mail::send((string) $u['email'], $purpose === 'reset' ? 'Your Maa Sarada Admin password was reset' : 'You’ve been added to Maa Sarada Admin', $msg);
                $out['email'] = (string) $u['email'];
                $channels[] = 'email';
            } catch (\RuntimeException $e) {
                $out['email_error'] = $e->getMessage();
            }
        }
        if (in_array($send, ['whatsapp', 'both'], true) && !empty($u['phone'])) {
            $out['whatsapp'] = Invites::whatsappLink((string) $u['phone'], $msg);
            $channels[] = 'whatsapp';
        }
        Invites::setChannels($id, implode(',', $channels ?: ['copy']));

        return $out;
    }

    /* ------------------------------------------------------------ listing */

    /** @return list<array<string,mixed>> staff rows + roles + display status */
    public static function all(): array
    {
        $rows = DB::select('SELECT * FROM admin_users ORDER BY CASE status WHEN \'disabled\' THEN 1 ELSE 0 END, name');
        $roles = [];
        foreach (DB::select('SELECT ur.admin_user_id, r.id, r.name, r.slug FROM admin_user_roles ur JOIN roles r ON r.id = ur.role_id ORDER BY r.sort') as $r) {
            $roles[(int) $r['admin_user_id']][] = ['id' => (int) $r['id'], 'name' => $r['name'], 'slug' => $r['slug']];
        }
        foreach ($rows as &$r) {
            $r['roles'] = $roles[(int) $r['id']] ?? [];
            $r['display_status'] = self::displayStatus($r);
        }

        return $rows;
    }

    /** invited | invite_expired | active | reset_pending | disabled */
    public static function displayStatus(array $u): string
    {
        if ($u['status'] === 'disabled') {
            return 'disabled';
        }
        $expired = !empty($u['temp_password_expires_at']) && (string) $u['temp_password_expires_at'] < date('Y-m-d H:i:s');
        if ($u['status'] === 'invited') {
            return $expired ? 'invite_expired' : 'invited';
        }

        return (int) $u['must_change_password'] === 1 ? ($expired ? 'reset_expired' : 'reset_pending') : 'active';
    }

    /* ------------------------------------------------------------ internals */

    /** @return array{password:string,link:string} */
    private static function issueTemporary(int $id, string $purpose, int $actorId): array
    {
        $password = Passwords::temporary();
        DB::update('UPDATE admin_users SET password = ?, must_change_password = 1, temp_password_expires_at = ?, updated_at = ? WHERE id = ?',
            [Passwords::hash($password), date('Y-m-d H:i:s', time() + Invites::HOURS * 3600), date('Y-m-d H:i:s'), $id]);
        $token = Invites::issue($id, $purpose, $actorId);

        return ['password' => $password, 'link' => Invites::link($token)];
    }

    private static function requireManager(int $actorId): void
    {
        $a = Guard::find($actorId);
        if ($a === null || $a['status'] === 'disabled' || !Roles::can($actorId, 'staff.manage')) {
            throw new AccessException('You don’t have access to manage staff.');
        }
    }

    /** The account being changed; only an Owner may change an Owner. */
    private static function target(int $id, int $actorId): array
    {
        $u = Guard::find($id);
        if ($u === null) {
            throw new AccessException('That staff member no longer exists.');
        }
        if ($id !== $actorId && Roles::isOwner($id) && !Roles::isOwner($actorId)) {
            throw new AccessException('Only an Owner can change another Owner’s account.');
        }

        return $u;
    }

    private static function confirmActor(int $actorId, string $password): void
    {
        $a = Guard::find($actorId);
        if ($a === null || $password === '' || !password_verify($password, (string) $a['password'])) {
            throw new AccessException('Your password is not correct — nothing was changed.');
        }
    }

    /**
     * @return array{name:string,email:?string,phone:?string,roles:list<int>}
     * @throws ValidationException
     */
    private static function validate(array $in, ?array $existing, int $actorId): array
    {
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            $errors['name'][] = 'Enter the person’s name.';
        } elseif (mb_strlen($name) > 120) {
            $errors['name'][] = 'Keep the name under 120 characters.';
        }

        $rawEmail = trim((string) ($in['email'] ?? ''));
        $email = $rawEmail === '' ? null : LoginId::email($rawEmail);
        if ($rawEmail !== '' && $email === null) {
            $errors['email'][] = 'That email doesn’t look right.';
        }
        $rawPhone = trim((string) ($in['phone'] ?? ''));
        $phone = $rawPhone === '' ? null : LoginId::phone($rawPhone);
        if ($rawPhone !== '' && $phone === null) {
            $errors['phone'][] = 'Enter a 10-digit Indian mobile number.';
        }
        if ($rawEmail === '' && $rawPhone === '') {
            $errors['email'][] = 'Give an email or a mobile number (or both) — it becomes their login ID.';
        }
        $self = $existing ? (int) $existing['id'] : 0;
        if ($email !== null && DB::selectOne('SELECT id FROM admin_users WHERE LOWER(email) = ? AND id <> ?', [$email, $self])) {
            $errors['email'][] = 'Another staff member already uses this email.';
        }
        if ($phone !== null && DB::selectOne('SELECT id FROM admin_users WHERE phone = ? AND id <> ?', [$phone, $self])) {
            $errors['phone'][] = 'Another staff member already uses this mobile number.';
        }

        $valid = array_map('intval', array_column(DB::select('SELECT id FROM roles'), 'id'));
        $roles = array_values(array_unique(array_filter(array_map('intval', (array) ($in['roles'] ?? [])), static fn($r) => in_array($r, $valid, true))));
        sort($roles);
        $ownerId = Roles::ownerRoleId();
        $actorOwner = Roles::isOwner($actorId);
        if ($roles === []) {
            $errors['roles'][] = 'Pick at least one role.';
        }
        $had = $existing ? array_column(Roles::forUser($self), 'id') : [];
        sort($had);
        if ($existing && $self === $actorId && $roles !== $had) {
            $errors['roles'][] = 'You can’t change your own roles — ask another Owner.';
        }
        $givesOwner = in_array($ownerId, $roles, true) && !in_array($ownerId, $had, true);
        $takesOwner = !in_array($ownerId, $roles, true) && in_array($ownerId, $had, true);
        if (($givesOwner || $takesOwner) && !$actorOwner) {
            $errors['roles'][] = 'Only an Owner can give or remove the Owner role.';
        }
        if ($takesOwner && $existing && $existing['status'] !== 'disabled' && Roles::activeOwnerCount($self) === 0) {
            $errors['roles'][] = 'At least one active Owner must remain.';
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        return ['name' => $name, 'email' => $email, 'phone' => $phone, 'roles' => $roles];
    }

    /** @param list<int> $roles */
    private static function syncRoles(int $id, array $roles): void
    {
        DB::delete('DELETE FROM admin_user_roles WHERE admin_user_id = ?', [$id]);
        foreach ($roles as $r) {
            DB::table('admin_user_roles')->insert(['admin_user_id' => $id, 'role_id' => $r]);
        }
        Roles::flush();
    }

    /** @return list<string> */
    private static function roleNames(int $id): array
    {
        return array_column(Roles::forUser($id), 'name');
    }
}
