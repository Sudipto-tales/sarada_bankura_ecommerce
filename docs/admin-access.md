# Admin login & role-based access

**Phases 1–4 built (2026-11-24):** staff login, lockout, remember-me, staff management, invites,
password reset, email OTP. **Phases 5–7 not built:** per-route permission checks, sidebar/button
hiding, roles screen, audit wiring. Until phase 5, **any signed-in staff member can open every
admin page except Staff.**

Staff accounts (`admin_users`) are separate from storefront customers (`users`). Staff never sign
in on the storefront; a signed-in admin only gets the store preview.

## Permissions and roles

A role is a bundle of `section.action` permissions (e.g. `orders.accept`, `payments.verify`). The
full list lives in code, `config/permissions.php` (label + tier view / act / sensitive per
permission); only role ↔ permission links are in the database. Permissions add up across roles;
there is no "deny".

| Role | For |
| --- | --- |
| **Owner** | Everything (`*`, including future permissions). Can't be deleted; one active Owner must always exist. |
| Review moderator | Reviews: publish / flag / **archive** / reply — **never delete**. Products + customers view only. |
| Order manager | Orders (all), returns (decide, receive), payments (view, send request), inventory + customers view. |
| Payment verifier | Payments view / verify / reject / request, orders view. No refunds, no receiving accounts. |
| Rules manager | Business rules: tax rules, advance %, return policies, stock thresholds, segment rules. |
| Catalogue manager | Products & catalogue (view, edit, import), inventory + reviews view. |
| Stock keeper | Inventory (view, receive, adjust), products view, orders view + dispatch. |
| Customer support | Enquiries (all), customers (view, edit), orders view, reviews view + reply. |

Rules enforced in `Staff`, not just the UI: nobody changes their own roles, disables or resets
themselves; only an Owner can give the Owner role or touch an Owner account. Planned for phase 6:
the person who verified a payment can't refund it, and sensitive dialogs ask for the admin's
password instead of the placeholder OTP.

## First admin

`php vayu admin:install [--email= --phone= --name=]` seeds the roles (never overwrites edited
roles) and creates an Owner **only if none exists**, from `ADMIN_EMAIL` / `ADMIN_PHONE`. The random
10-character password is printed once; the first login forces "Set your password". Safe to repeat.

## Login (`/admin/login`)

- One field: email or mobile (`+91`, leading 0 and spaces stripped).
- Lockout: 5 wrong tries on one **account** (email and phone count together) in 15 min → 15 min;
  10 from one **IP** → that IP 15 min (a shop shares one Wi-Fi). IP = `REMOTE_ADDR` only. Plus
  `throttle:30,1` on the POST. "Disabled" / "invite expired" are said only after the right password.
- Session id regenerated on login, 2 h idle logout, "Remember this device" 30 days (HttpOnly
  cookie, rotated on every use). Session cookie: HttpOnly, SameSite=Lax, Secure on HTTPS, strict
  mode. `admin_users.session_version` bump = every other session logged out.
- Only same-site `/admin…` `next=` targets are followed.
- `AdminAuth` middleware covers the whole `/admin` group except login / forgot. Guests →
  `/admin/login?next=…` (JSON → 401). A temporary password can reach only "Set password" and Log
  out. Admin responses send `Cache-Control: no-store, private`. A test asserts every `/admin` route
  has the middleware and no public route does.

## Staff (`/admin/staff`, needs `staff.manage`)

- **Add staff:** name, email and/or mobile (at least one; it is the login ID), roles. Status
  starts as *Invited* with a random default password (no look-alike characters) and "must change".
- **Invite:** `/admin/login?invite=<token>` link + password, valid **72 hours** (a new one retires
  the old). Sent by Email (Mailer), WhatsApp (`wa.me` link the admin sends), both, or copied. The
  password + link are shown **once** with Copy buttons. Passwords are never sent through WhatsApp
  templates.
- **Resend invite**, **Reset password** (asks for the admin's own password, logs the person out
  everywhere), **Switch off / on**, **Edit** (name, login IDs, roles). Every change → `audit_logs`.

## Own account (`/admin/account`)

Profile, change password with an emailed 6-digit code, last 15 logins, remembered devices,
"Log out other devices". Topbar user menu: My account, Change password, Staff (if allowed), Log out.

**OTP (email only, owner decision):** 10 min, 5 wrong tries, resend after 60 s, 5 codes an hour
(failed sends don't use the quota), stored hashed. **Forgot password** (`/admin/forgot`) answers the
same for unknown emails; a mobile number gets "ask an admin to reset your password". While
`MAIL_*` is empty the page says email isn't set up. The `OtpChannel` seam is where a WhatsApp
channel will plug in.

## Data (migration 000014)

`admin_users` (status invited|active|disabled, must_change_password, temp_password_expires_at,
session_version, last login) · `roles` · `role_permissions` · `admin_user_roles` · `admin_invites`
(purpose invite|reset) · `admin_otps` · `admin_remember_tokens` · `admin_login_attempts`.
`audit_logs` (from payments) is reused with `entity_type = 'admin_user'`. Tokens and OTPs are
stored as sha256 / HMAC-SHA256 with `APP_KEY`, passwords with `password_hash`.

## Code (`app/Services/AdminAccess/`)

`Roles` (seed sync, `can`, `isOwner`) · `Guard` (session, login, lockout, remember-me, logout;
`Guard::id()` for audit wiring) · `Staff` (only writer of `admin_users` / `admin_user_roles`) ·
`Invites` · `Otp` + `OtpChannel` / `EmailOtpChannel` · `LoginId` · `Passwords`.

## Still to do

1. **Phase 5:** `can:` middleware on every admin route, `can()` in views to hide sidebar groups
   and buttons, admin-styled 403, `/admin/roles` screen (permission checklist per role). Includes
   the `media.*`, `enquiries.*` and `whatsapp.view/test/campaign` permissions.
2. **Phase 6:** fill `created_by` / `actor_id` columns from `Guard::id()`, password re-confirm in
   `sensitive-confirm.php`, `/admin/audit` page.
3. **Phase 7:** sign in as each seeded role and run invite → first login → OTP change → reset.

Server notes: PHP's own session cleanup may use php.ini `gc_maxlifetime` (24 min) — set 7200 on
the server or rely on "Remember this device". Behind a proxy the IP lockout sees the proxy
address; the per-account lockout still works.
