<?php

use App\Console\Commands\AdminInstallCommand;
use App\Middleware\AdminAuth;
use App\Services\AdminAccess\AccessException;
use App\Services\AdminAccess\Guard;
use App\Services\AdminAccess\Invites;
use App\Services\AdminAccess\LoginId;
use App\Services\AdminAccess\Otp;
use App\Services\AdminAccess\OtpChannel;
use App\Services\AdminAccess\Passwords;
use App\Services\AdminAccess\Roles;
use App\Services\AdminAccess\Staff;
use App\Services\Mail\Mail;
use Vayu\Application;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;
use Vayu\Validation\ValidationException;

/** Admin access phases 1–4: tables, roles, admin:install, login/lockout/remember, staff, invites, OTP, AdminAuth. */
return function (Assert $assert): void {
    $app = new Application(dirname(__DIR__));
    $app->config()->set('database.default', 'sqlite');
    $app->config()->set('database.connections.sqlite.database', ':memory:');
    $app->config()->set('cache.default', 'array');
    $router = $app->router();
    $router->aliasMiddleware('throttle', \Vayu\Middleware\ThrottleRequests::class);
    require dirname(__DIR__) . '/routes/web.php';
    $files = glob(dirname(__DIR__) . '/database/migrations/2026_*.php');
    sort($files);
    foreach ($files as $f) {
        ob_start();
        (require $f)->up();
        ob_end_clean();
    }
    $tables = ['admin_users', 'roles', 'role_permissions', 'admin_user_roles', 'admin_invites', 'admin_otps', 'admin_remember_tokens', 'admin_login_attempts'];
    $exists = static fn(string $t): bool => DB::selectOne("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$t]) !== null;
    $mig = require dirname(__DIR__) . '/database/migrations/2026_11_26_000014_create_admin_access_tables.php';
    $mig->down();
    $assert->that('migration: down() drops all 8 tables', !array_filter($tables, $exists));
    $mig->up();
    $assert->that('migration: up() creates all 8 tables', count(array_filter($tables, $exists)) === 8);

    $msg = static function (callable $fn): string {
        try { $fn(); return ''; } catch (AccessException $e) { return $e->getMessage(); }
    };
    $errs = static function (callable $fn): array {
        try { $fn(); return []; } catch (ValidationException $e) { return $e->errors(); }
    };
    $fresh = static function (): void {               // a new browser: no session, no cookie
        session()->forget('admin');
        unset($_COOKIE[Guard::COOKIE]);
        Guard::forget();
    };
    $user = static fn(int $id): array => DB::selectOne('SELECT * FROM admin_users WHERE id = ?', [$id]);
    $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'TestBrowser/1.0';

    // ---- roles + permissions ----
    $assert->equals('roles: 8 seeded', 8, Roles::sync());
    $assert->equals('roles: sync is idempotent', 0, Roles::sync());
    $slugs = array_column(Roles::all(), 'slug');
    $assert->that('roles: owner first, all 8 present', $slugs[0] === 'owner' && count($slugs) === 8);
    $perms = [];
    foreach ((array) config('permissions.sections') as $sec) {
        $perms = array_merge($perms, array_keys($sec['permissions']));
    }
    $unknown = [];
    foreach ((array) config('permissions.roles') as $slug => $def) {
        foreach ($def['permissions'] as $p) {
            if ($p !== '*' && !in_array($p, $perms, true)) {
                $unknown[] = "$slug:$p";
            }
        }
    }
    $assert->equals('roles: every seeded permission exists in config', [], $unknown);
    $roleId = static fn(string $slug): int => (int) DB::selectOne('SELECT id FROM roles WHERE slug = ?', [$slug])['id'];

    // ---- login id + passwords ----
    $assert->equals('login id: +91 / spaces / dashes stripped', ['phone', '9830012345'], LoginId::parse('+91 98300-12345'));
    $assert->equals('login id: leading 0 stripped', ['phone', '9830012345'], LoginId::parse('09830012345'));
    $assert->equals('login id: email lower-cased', ['email', 'owner@shop.in'], LoginId::parse('  Owner@Shop.IN '));
    $assert->that('login id: short number refused', LoginId::parse('12345')[0] === null);
    $pw = Passwords::temporary();
    $assert->that('temp password: 10 chars, no look-alikes, has upper + digits', strlen($pw) === 10 && !preg_match('/[0O1lIo]/', $pw) && preg_match('/[A-Z]/', $pw) && preg_match_all('/\d/', $pw) >= 2);
    $assert->that('temp passwords differ', Passwords::temporary() !== Passwords::temporary());
    $assert->that('password rule: min 8', Passwords::problem('short', 'short') !== null);
    $assert->that('password rule: must match', Passwords::problem('longenough1', 'longenough2') !== null);
    $assert->that('password rule: not same as current', Passwords::problem('samesame99', 'samesame99', Passwords::hash('samesame99')) !== null);
    $assert->that('password rule: ok', Passwords::problem('a good password', 'a good password', Passwords::hash('other')) === null);

    // ---- admin:install ----
    $cmd = new AdminInstallCommand($app);
    $assert->equals('install: refuses without ADMIN_EMAIL / ADMIN_PHONE', 1, $cmd->handle(env('ADMIN_EMAIL') || env('ADMIN_PHONE') ? ['--email=bad'] : []));
    $assert->equals('install: creates the Owner', 0, $cmd->handle(['--email=Owner@Shop.in', '--phone=9830000001']));
    $owner = DB::selectOne('SELECT * FROM admin_users WHERE email = ?', ['owner@shop.in']);
    $ownerId = (int) $owner['id'];
    $assert->that('install: Owner role, invited, must change, no expiry', Roles::isOwner($ownerId) && $owner['status'] === 'invited' && (int) $owner['must_change_password'] === 1 && $owner['temp_password_expires_at'] === null);
    $assert->that('install: password hashed', str_starts_with((string) $owner['password'], '$2y$'));
    $assert->equals('install: second run creates nothing', 0, $cmd->handle(['--email=other@shop.in']));
    $assert->equals('install: still one admin', 1, (int) DB::table('admin_users')->count());
    DB::update('UPDATE admin_users SET password = ? WHERE id = ?', [Passwords::hash('TempPass22'), $ownerId]);

    // ---- login ----
    $fresh();
    $assert->equals('login: wrong password → generic', 'Wrong login ID or password.', $msg(fn() => Guard::attempt('owner@shop.in', 'nope')));
    $assert->equals('login: unknown id → same message', 'Wrong login ID or password.', $msg(fn() => Guard::attempt('ghost@shop.in', 'nope')));
    $u = Guard::attempt('OWNER@shop.in', 'TempPass22');
    $assert->that('login: by email (any case), session started', (int) $u['id'] === $ownerId && Guard::id() === $ownerId && (int) session()->get('admin')['id'] === $ownerId);
    $assert->equals('login: first login must change password', 1, (int) $u['must_change_password']);
    $fresh();
    Guard::attempt('98300 00001', 'TempPass22');
    $assert->equals('login: by phone', $ownerId, Guard::id());
    $assert->that('login: last_login_at + ip stored', $user($ownerId)['last_login_ip'] === '10.0.0.1' && $user($ownerId)['last_login_at'] !== null);
    $assert->equals('set password: same as temporary refused', 'Choose a new password — this is the same as the current one.', $msg(fn() => Staff::setPassword($ownerId, 'TempPass22', 'TempPass22')));
    Staff::setPassword($ownerId, 'owner-pass-1', 'owner-pass-1');
    $assert->that('set password: active, flag off', $user($ownerId)['status'] === 'active' && (int) $user($ownerId)['must_change_password'] === 0 && $user($ownerId)['password_changed_at'] !== null);
    Guard::forget();
    $assert->equals('set password: current browser stays logged in', $ownerId, Guard::id());

    // ---- lockout ----
    $fresh();
    $_SERVER['REMOTE_ADDR'] = '10.0.0.2';
    for ($i = 1; $i <= 4; $i++) {
        $m = $msg(fn() => Guard::attempt('owner@shop.in', 'wrong' . $i));
    }
    $assert->equals('lockout: 4 wrong tries still generic', 'Wrong login ID or password.', $m);
    $m = $msg(fn() => Guard::attempt('owner@shop.in', 'wrong5'));
    $assert->that('lockout: 5th wrong try locks for 15 min', str_contains($m, 'try again in 15 minutes'));
    $assert->that('lockout: right password refused while locked', str_contains($msg(fn() => Guard::attempt('owner@shop.in', 'owner-pass-1')), 'Too many wrong tries'));
    $_SERVER['REMOTE_ADDR'] = '10.0.0.3';
    $assert->that('lockout: per account — phone login locked too', str_contains($msg(fn() => Guard::attempt('9830000001', 'owner-pass-1')), 'Too many'));
    $assert->that('lockout: same account by email from another IP still locked', str_contains($msg(fn() => Guard::attempt('owner@shop.in', 'owner-pass-1')), 'Too many'));
    DB::update('UPDATE admin_login_attempts SET created_at = ? WHERE admin_user_id = ?', [date('Y-m-d H:i:s', time() - 16 * 60), $ownerId]);
    $fresh();
    $assert->equals('lockout: lifts after 15 min', $ownerId, (int) Guard::attempt('owner@shop.in', 'owner-pass-1')['id']);
    $fresh();
    $_SERVER['REMOTE_ADDR'] = '10.0.0.9';
    for ($i = 1; $i <= 10; $i++) {
        $msg(fn() => Guard::attempt('guess' . $i . '@x.in', 'nope'));
    }
    $assert->that('lockout: 10 misses from one IP lock that IP', str_contains($msg(fn() => Guard::attempt('owner@shop.in', 'owner-pass-1')), 'Too many'));
    $_SERVER['REMOTE_ADDR'] = '10.0.0.4';
    $assert->equals('lockout: other IPs unaffected', $ownerId, (int) Guard::attempt('owner@shop.in', 'owner-pass-1')['id']);

    // ---- session: idle, version, remember-me, logout ----
    $s = session()->get('admin');
    $s['last'] = time() - Guard::IDLE_SECONDS - 5;
    session()->put('admin', $s);
    Guard::forget();
    $assert->that('session: idle 2 h → logged out', Guard::user() === null && session()->get('admin') === null);
    $fresh();
    Guard::attempt('owner@shop.in', 'owner-pass-1', true);
    $raw = (string) ($_COOKIE[Guard::COOKIE] ?? '');
    $assert->that('remember: token stored hashed', strlen($raw) === 64 && DB::selectOne('SELECT id FROM admin_remember_tokens WHERE token_hash = ?', [hash('sha256', $raw)]) !== null
        && !DB::selectOne('SELECT id FROM admin_remember_tokens WHERE token_hash = ?', [$raw]));
    session()->forget('admin');
    Guard::forget();
    $assert->equals('remember: cookie logs back in', $ownerId, Guard::id());
    $assert->that('remember: token rotated on use', (string) $_COOKIE[Guard::COOKIE] !== $raw && !DB::selectOne('SELECT id FROM admin_remember_tokens WHERE token_hash = ?', [hash('sha256', $raw)]));
    $assert->equals('remember: login recorded', 'remember', DB::selectOne('SELECT reason FROM admin_login_attempts ORDER BY id DESC LIMIT 1')['reason']);
    Guard::logout();
    $assert->that('logout: session + device token gone', Guard::user() === null && (int) DB::table('admin_remember_tokens')->count() === 0);
    $fresh();
    Guard::attempt('owner@shop.in', 'owner-pass-1');

    // ---- staff: create, validation, permissions ----
    $e = $errs(fn() => Staff::create(['name' => '', 'email' => '', 'phone' => '', 'roles' => []], $ownerId));
    $assert->that('staff: name, login id and roles required', isset($e['name'], $e['email'], $e['roles']));
    $e = $errs(fn() => Staff::create(['name' => 'X', 'email' => 'not-email', 'phone' => '12345', 'roles' => [$roleId('order-manager')]], $ownerId));
    $assert->that('staff: bad email + bad phone', isset($e['email'], $e['phone']));
    $e = $errs(fn() => Staff::create(['name' => 'X', 'email' => 'OWNER@shop.in', 'roles' => [$roleId('order-manager')]], $ownerId));
    $assert->that('staff: email unique (any case)', isset($e['email']));
    $r = Staff::create(['name' => 'Rina Pal', 'email' => 'Rina@Shop.in', 'phone' => '+91 98300 22222', 'roles' => [$roleId('order-manager'), $roleId('payment-verifier')]], $ownerId);
    $rina = $user($r['id']);
    $assert->that('staff: created invited, must change, 72 h expiry', $rina['status'] === 'invited' && (int) $rina['must_change_password'] === 1
        && abs(strtotime((string) $rina['temp_password_expires_at']) - (time() + 72 * 3600)) < 5);
    $assert->that('staff: email lower-cased, phone normalised', $rina['email'] === 'rina@shop.in' && $rina['phone'] === '9830022222');
    $assert->that('staff: two roles, permissions add up', Roles::can($r['id'], 'orders.dispatch') && Roles::can($r['id'], 'payments.verify') && !Roles::can($r['id'], 'payments.refund') && !Roles::can($r['id'], 'staff.manage'));
    $assert->that('staff: link is /admin/login?invite=…', (bool) preg_match('#/admin/login\?invite=[0-9a-f]{48}$#', $r['link']));
    $token = substr($r['link'], strpos($r['link'], 'invite=') + 7);
    $inv = Invites::find($token);
    $assert->that('invite: token finds the person, stored hashed', $inv !== null && (int) $inv['admin_user_id'] === $r['id'] && !DB::selectOne('SELECT id FROM admin_invites WHERE token_hash = ?', [$token]));
    $assert->that('audit: staff create logged with actor', DB::selectOne("SELECT actor_id FROM audit_logs WHERE entity_type = 'admin_user' AND action = 'create' AND entity_id = ?", [$r['id']])['actor_id'] == $ownerId);
    $d = Staff::deliver($r['id'], $r, 'both');
    $assert->that('deliver: WhatsApp link with message', str_starts_with((string) $d['whatsapp'], 'https://wa.me/919830022222?text=') && str_contains(rawurldecode((string) $d['whatsapp']), $r['password']));
    $assert->that('deliver: message has link, login id, password, 72 h', str_contains($d['message'], $r['link']) && str_contains($d['message'], 'rina@shop.in') && str_contains($d['message'], $r['password']) && str_contains($d['message'], '72 hours'));
    $assert->that('deliver: email error reported when mail not set up', Mail::configured() || $d['email_error'] !== null);

    $phoneOnly = Staff::create(['name' => 'Bablu', 'phone' => '9830033333', 'roles' => [$roleId('stock-keeper')]], $ownerId);
    $assert->that('staff: phone-only allowed', $user($phoneOnly['id'])['email'] === null);
    $assert->equals('staff: order manager can’t add staff', 'You don’t have access to manage staff.', $msg(fn() => Staff::create(['name' => 'Y', 'email' => 'y@x.in', 'roles' => [$roleId('order-manager')]], $r['id'])));

    // a non-Owner who may manage staff (custom role) still can't touch Owners
    $hrRole = (int) DB::table('roles')->insertGetId(['name' => 'HR', 'slug' => 'hr', 'sort' => 99]);
    DB::table('role_permissions')->insert(['role_id' => $hrRole, 'permission' => 'staff.manage']);
    $hr = Staff::create(['name' => 'Hari', 'email' => 'hari@shop.in', 'roles' => [$hrRole]], $ownerId);
    Roles::flush();
    $e = $errs(fn() => Staff::create(['name' => 'Z', 'email' => 'z@x.in', 'roles' => [$roleId('owner')]], $hr['id']));
    $assert->that('owner role: only an Owner can give it', isset($e['roles']));
    $assert->that('owner role: non-Owner can’t reset an Owner', str_contains($msg(fn() => Staff::resetPassword($ownerId, $hr['id'], 'x')), 'Only an Owner'));
    $assert->that('owner role: non-Owner can’t disable an Owner', str_contains($msg(fn() => Staff::disable($ownerId, $hr['id'])), 'Only an Owner'));
    $o2 = Staff::create(['name' => 'Second Owner', 'email' => 'owner2@shop.in', 'roles' => [$roleId('owner')]], $ownerId);
    $assert->that('owner role: an Owner can create another Owner', Roles::isOwner($o2['id']));
    $e = $errs(fn() => Staff::update($ownerId, ['name' => 'Store Owner', 'email' => 'owner@shop.in', 'phone' => '9830000001', 'roles' => [$roleId('order-manager')]], $ownerId));
    $assert->that('roles: can’t change your own roles', isset($e['roles']));
    Staff::update($r['id'], ['name' => 'Rina Pal', 'email' => 'rina@shop.in', 'phone' => '9830022222', 'roles' => [$roleId('order-manager')]], $ownerId);
    Roles::flush();
    $assert->that('edit: roles replaced', !Roles::can($r['id'], 'payments.verify') && Roles::can($r['id'], 'orders.accept'));

    // ---- invite → first login → own password ----
    $fresh();
    $_SERVER['REMOTE_ADDR'] = '10.0.1.1';
    $u = Guard::attempt('9830022222', $r['password']);
    $assert->that('invite: temp password logs in, must change', (int) $u['id'] === $r['id'] && (int) $u['must_change_password'] === 1);
    Staff::setPassword($r['id'], 'rina-own-pass', 'rina-own-pass');
    $assert->that('invite: active after own password, link closed', $user($r['id'])['status'] === 'active' && Invites::find($token) === null);
    $assert->equals('invite: temp password no longer works', 'Wrong login ID or password.', $msg(function () use ($fresh, $r) { $fresh(); Guard::attempt('rina@shop.in', $r['password']); }));

    // expired invite + resend
    DB::update('UPDATE admin_users SET temp_password_expires_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 60), $phoneOnly['id']]);
    $fresh();
    $assert->that('expired: says invite expired (after right password)', str_contains($msg(fn() => Guard::attempt('9830033333', $phoneOnly['password'])), 'invite has expired'));
    $assert->equals('expired: wrong password stays generic', 'Wrong login ID or password.', $msg(fn() => Guard::attempt('9830033333', 'nope')));
    $assert->equals('expired: shown as invite_expired', 'invite_expired', Staff::displayStatus($user($phoneOnly['id'])));
    $re = Staff::resendInvite($phoneOnly['id'], $ownerId);
    $oldTok = DB::selectOne('SELECT COUNT(*) AS n FROM admin_invites WHERE admin_user_id = ? AND used_at IS NULL', [$phoneOnly['id']])['n'];
    $assert->equals('resend: only the newest link is open', 1, (int) $oldTok);
    $assert->equals('resend: old temp password stops working', 'Wrong login ID or password.', $msg(fn() => Guard::attempt('9830033333', $phoneOnly['password'])));
    $fresh();
    $assert->equals('resend: new temp password works', $phoneOnly['id'], (int) Guard::attempt('9830033333', $re['password'])['id']);
    $assert->that('resend: refused for someone already active', str_contains($msg(fn() => Staff::resendInvite($r['id'], $ownerId)), 'Reset password'));

    // ---- admin reset ----
    $fresh();
    Guard::attempt('rina@shop.in', 'rina-own-pass', true);
    $verBefore = (int) $user($r['id'])['session_version'];
    $fresh();
    $assert->that('reset: needs the admin’s own password', str_contains($msg(fn() => Staff::resetPassword($r['id'], $ownerId, 'wrong')), 'Your password is not correct'));
    $assert->that('reset: not your own', str_contains($msg(fn() => Staff::resetPassword($ownerId, $ownerId, 'owner-pass-1')), 'own password'));
    $rs = Staff::resetPassword($r['id'], $ownerId, 'owner-pass-1');
    $ru = $user($r['id']);
    $assert->that('reset: logged out everywhere (version bump + devices)', (int) $ru['session_version'] === $verBefore + 1 && (int) DB::table('admin_remember_tokens')->where('admin_user_id', $r['id'])->count() === 0);
    $assert->that('reset: must change again, shown as reset pending', (int) $ru['must_change_password'] === 1 && Staff::displayStatus($ru) === 'reset_pending');
    $assert->equals('reset: old password stops working', 'Wrong login ID or password.', $msg(fn() => Guard::attempt('rina@shop.in', 'rina-own-pass')));
    $fresh();
    $assert->equals('reset: new temp password works', $r['id'], (int) Guard::attempt('rina@shop.in', $rs['password'])['id']);
    $assert->equals('reset: purpose = reset', 'reset', DB::selectOne('SELECT purpose FROM admin_invites WHERE admin_user_id = ? ORDER BY id DESC LIMIT 1', [$r['id']])['purpose']);
    Staff::setPassword($r['id'], 'rina-new-pass', 'rina-new-pass');

    // ---- disable / enable ----
    $fresh();
    Guard::attempt('rina@shop.in', 'rina-new-pass');
    $rinaSession = session()->get('admin');
    $assert->that('disable: not yourself', str_contains($msg(fn() => Staff::disable($ownerId, $ownerId)), 'own account'));
    Staff::disable($r['id'], $ownerId);
    Guard::forget();
    session()->put('admin', $rinaSession);
    $assert->that('disable: open session ends', Guard::user() === null);
    $fresh();
    $assert->that('disable: login refused with a clear message', str_contains($msg(fn() => Guard::attempt('rina@shop.in', 'rina-new-pass')), 'switched off'));
    Staff::enable($r['id'], $ownerId);
    $assert->equals('enable: back to active', 'active', $user($r['id'])['status']);
    $fresh();
    $assert->equals('enable: can log in again', $r['id'], (int) Guard::attempt('rina@shop.in', 'rina-new-pass')['id']);

    // ---- OTP (email only; channel swapped for a fake) ----
    $sent = [];
    Otp::useChannel(new class($sent) implements OtpChannel {
        public bool $fail = false;
        public function __construct(private array &$sent) {}
        public function name(): string { return 'email'; }
        public function canReach(array $user): bool { return !empty($user['email']); }
        public function send(array $user, string $code, string $purpose): void {
            if ($this->fail) { throw new \RuntimeException('SMTP down'); }
            $this->sent[] = $code;
        }
    });
    $assert->that('otp: phone-only account is told to ask an admin', str_contains($msg(fn() => Otp::send($phoneOnly['id'], 'change_password')), 'Ask an admin'));
    Otp::send($r['id'], 'change_password');
    $code = end($sent);
    $row = DB::selectOne('SELECT * FROM admin_otps WHERE admin_user_id = ? ORDER BY id DESC LIMIT 1', [$r['id']]);
    $assert->that('otp: 6 digits, stored hashed, 10 min', strlen($code) === 6 && ctype_digit($code) && $row['code_hash'] !== $code && strlen($row['code_hash']) === 64
        && abs(strtotime((string) $row['expires_at']) - (time() + 600)) < 5);
    $assert->that('otp: resend waits 60 s', str_contains($msg(fn() => Otp::send($r['id'], 'change_password')), 'seconds'));
    $wrong = $code === '000000' ? '111111' : '000000';
    $assert->equals('otp: wrong code counts down', 'That code is not correct. 4 tries left.', $msg(fn() => Otp::verify($r['id'], 'change_password', $wrong)));
    $assert->equals('otp: other purpose does not match', 'Ask for a code first (or ask for a new one).', $msg(fn() => Otp::verify($r['id'], 'forgot_password', $code)));
    $assert->equals('otp: right code accepted (spaces ok)', '', $msg(fn() => Otp::verify($r['id'], 'change_password', substr($code, 0, 3) . ' ' . substr($code, 3))));
    $assert->equals('otp: used once only', 'Ask for a code first (or ask for a new one).', $msg(fn() => Otp::verify($r['id'], 'change_password', $code)));
    DB::update('UPDATE admin_otps SET created_at = ? WHERE admin_user_id = ?', [date('Y-m-d H:i:s', time() - 120), $r['id']]);
    Otp::send($r['id'], 'forgot_password');
    for ($i = 0; $i < 5; $i++) {
        $m = $msg(fn() => Otp::verify($r['id'], 'forgot_password', $wrong));
    }
    $assert->equals('otp: 5 wrong tries burn the code', 'Too many wrong codes. Ask for a new one.', $msg(fn() => Otp::verify($r['id'], 'forgot_password', (string) end($sent))));
    DB::update('UPDATE admin_otps SET created_at = ? WHERE admin_user_id = ?', [date('Y-m-d H:i:s', time() - 120), $r['id']]);
    Otp::send($r['id'], 'forgot_password');
    DB::update('UPDATE admin_otps SET expires_at = ? WHERE admin_user_id = ? AND used_at IS NULL', [date('Y-m-d H:i:s', time() - 1), $r['id']]);
    $assert->that('otp: expired code refused', str_contains($msg(fn() => Otp::verify($r['id'], 'forgot_password', (string) end($sent))), 'expired'));
    DB::update('UPDATE admin_otps SET created_at = ? WHERE admin_user_id = ?', [date('Y-m-d H:i:s', time() - 120), $r['id']]);
    Otp::send($r['id'], 'forgot_password');
    DB::update('UPDATE admin_otps SET created_at = ? WHERE admin_user_id = ?', [date('Y-m-d H:i:s', time() - 120), $r['id']]);
    Otp::send($r['id'], 'forgot_password');                 // 5th code this hour
    DB::update('UPDATE admin_otps SET created_at = ? WHERE admin_user_id = ?', [date('Y-m-d H:i:s', time() - 120), $r['id']]);
    $assert->that('otp: max 5 codes an hour', str_contains($msg(fn() => Otp::send($r['id'], 'forgot_password')), 'last hour'));
    DB::update('UPDATE admin_otps SET created_at = ? WHERE admin_user_id = ?', [date('Y-m-d H:i:s', time() - 7200), $r['id']]);
    $ch = Otp::channel();
    $ch->fail = true;
    $before = (int) DB::table('admin_otps')->count();
    $assert->that('otp: send failure is reported', str_contains($msg(fn() => Otp::send($r['id'], 'forgot_password')), 'SMTP down'));
    $assert->equals('otp: failed send leaves no code behind', $before, (int) DB::table('admin_otps')->count());
    $ch->fail = false;
    // full forgot-password flow: code + new password, other sessions out
    $fresh();
    Guard::attempt('rina@shop.in', 'rina-new-pass', true);
    $fresh();
    Otp::send($r['id'], 'forgot_password');
    Otp::verify($r['id'], 'forgot_password', (string) end($sent));
    Staff::setPassword($r['id'], 'rina-forgot-pass', 'rina-forgot-pass');
    $assert->that('forgot: new password works, devices logged out', (int) Guard::attempt('rina@shop.in', 'rina-forgot-pass')['id'] === $r['id'] && (int) DB::table('admin_remember_tokens')->where('admin_user_id', $r['id'])->count() === 0);
    Otp::useChannel(null);

    // ---- routes + AdminAuth middleware ----
    $guest = ['/admin/login', '/admin/forgot', '/admin/forgot/reset'];
    $open = $closed = [];
    foreach ($router->all() as $route) {
        $isAdmin = $route->path === '/admin' || str_starts_with($route->path, '/admin/');
        $has = in_array(AdminAuth::class, $route->middleware, true);
        if ($isAdmin && !$has && !in_array($route->path, $guest, true)) {
            $open[] = $route->path;
        }
        if (!$isAdmin && $has) {
            $closed[] = $route->path;
        }
    }
    $assert->equals('routes: every /admin route needs a login (except login/forgot)', [], array_values(array_unique($open)));
    $assert->equals('routes: public routes stay public', [], $closed);
    $publicPaths = array_column(array_map(static fn($r) => ['p' => $r->path], $router->all()), 'p');
    $assert->that('routes: /review /order /invoice /pay still registered', (bool) array_filter($publicPaths, fn($p) => str_starts_with($p, '/review/')) && in_array('/order/{token}', $publicPaths, true) && in_array('/invoice/{token}', $publicPaths, true) && in_array('/pay/{token}', $publicPaths, true));

    $req = static fn(string $method, string $path, array $query = [], array $body = [], array $headers = []): Request => new Request($method, $path, $query, $body, $headers, ['REMOTE_ADDR' => '10.0.2.1'], [], null);
    $ok = static fn(Request $r): Response => Response::make('ok');
    $mw = new AdminAuth();
    $fresh();
    $res = $mw->handle($req('GET', '/admin/orders', ['status' => 'pending']), $ok);
    $assert->equals('middleware: guest → login with next', '/admin/login?next=' . rawurlencode('/admin/orders?status=pending'), $res->getHeaders()['Location'] ?? null);
    $assert->equals('middleware: /admin → plain login', '/admin/login', $mw->handle($req('GET', '/admin'), $ok)->getHeaders()['Location'] ?? null);
    $assert->equals('middleware: JSON guest → 401', 401, $mw->handle($req('GET', '/admin/notifications/feed', [], [], ['Accept' => 'application/json']), $ok)->getStatus());
    Guard::attempt('9830033333', $re['password']);   // Bablu, still on a temporary password
    $assert->equals('middleware: temp password → set-password screen', '/admin/password/set', $mw->handle($req('GET', '/admin/orders'), $ok)->getHeaders()['Location'] ?? null);
    $assert->equals('middleware: set-password screen reachable', 'ok', $mw->handle($req('GET', '/admin/password/set'), $ok)->getContent());
    $assert->equals('middleware: logout reachable', 'ok', $mw->handle($req('POST', '/admin/logout'), $ok)->getContent());
    $fresh();
    Guard::attempt('owner@shop.in', 'owner-pass-1');
    $res = $mw->handle($req('GET', '/admin/orders'), $ok);
    $assert->that('middleware: logged in → page, not cached', $res->getContent() === 'ok' && ($res->getHeaders()['Cache-Control'] ?? '') === 'no-store, private');

    // ---- through the router ----
    $fresh();
    $res = $router->dispatch($req('GET', '/admin'));
    $assert->that('http: /admin redirects to login', $res->getStatus() === 302 && ($res->getHeaders()['Location'] ?? '') === '/admin/login');
    $res = $router->dispatch($req('GET', '/admin/login'));
    $assert->that('http: login page renders', $res->getStatus() === 200 && str_contains($res->getContent(), 'Email or mobile number'));
    $res = $router->dispatch($req('GET', '/admin/login', ['invite' => $token]));
    $assert->that('http: used invite link explains itself', str_contains((string) session()->get('error'), 'expired'));
    $res = $router->dispatch($req('POST', '/admin/login', [], ['login_id' => 'owner@shop.in', 'password' => 'owner-pass-1', 'next' => '//evil.example/x']));
    $assert->equals('http: login ignores off-site next', '/admin', $res->getHeaders()['Location'] ?? null);
    $fresh();
    $res = $router->dispatch($req('POST', '/admin/login', [], ['login_id' => 'owner@shop.in', 'password' => 'owner-pass-1', 'next' => '/admin/orders?status=pending']));
    $assert->equals('http: login follows /admin next', '/admin/orders?status=pending', $res->getHeaders()['Location'] ?? null);
    $res = $router->dispatch($req('GET', '/admin/staff'));
    $assert->that('http: staff page renders for the Owner', $res->getStatus() === 200 && str_contains($res->getContent(), 'Rina Pal') && str_contains($res->getContent(), 'Add staff'));
    $res = $router->dispatch($req('GET', '/admin/account'));
    $assert->that('http: account page renders', $res->getStatus() === 200 && str_contains($res->getContent(), 'Login history'));
    $fresh();
    Guard::attempt('rina@shop.in', 'rina-forgot-pass');
    $assert->equals('http: staff page 403 without staff.manage', 403, $router->dispatch($req('GET', '/admin/staff'))->getStatus());
    $res = $router->dispatch($req('POST', '/admin/logout'));
    $assert->that('http: logout → login page', ($res->getHeaders()['Location'] ?? '') === '/admin/login' && Guard::user() === null);
    $fresh();
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
};
