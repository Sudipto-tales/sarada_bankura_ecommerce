# Admin Login & Role-Based Access (plan)

**Status: PLAN rev 2 (2026-11-22), not built.** Owner decisions: "rules" = business rules;
review moderator can archive but never delete; login with email **or** phone; staff onboarding =
admin creates the account and sends a link + default password by email or WhatsApp. Replaces the "admin login: out of scope" line in
`docs/roadmap.md`. Production DB is MySQL; all times IST.

## 1. Idea in one line

Every staff member logs in with their own account. Each account gets one or more **roles**. A role
is a bundle of **permissions** written as `section.action` (e.g. `orders.accept`,
`payments.verify`, `reviews.moderate`). The sidebar, buttons and routes all check the same
permission, so a person only sees and can do what their job needs.

## 2. Sections and their permissions

| Section | View | Act | Sensitive (owner by default) |
| --- | --- | --- | --- |
| Dashboard | `dashboard.view` | — | `dashboard.revenue` (money tiles) |
| Products & catalogue (products, variants, categories, attributes, units, brands, tags) | `catalog.view` | `catalog.edit`, `catalog.import` | `catalog.delete` |
| Reviews & comments | `reviews.view` | `reviews.moderate` (publish / flag / archive), `reviews.reply` | `reviews.delete` (Owner only, never given to moderators) |
| Enquiries | `enquiries.view` | `enquiries.reply`, `enquiries.assign` | — |
| Customers | `customers.view` | `customers.edit`, `customers.segments` | `customers.export`, `customers.delete` |
| Orders | `orders.view` | `orders.accept`, `orders.reject`, `orders.dispatch`, `orders.deliver`, `orders.documents` | — |
| Payments | `payments.view` | `payments.verify`, `payments.reject`, `payments.request` | `payments.refund`, `payments.accounts` (receiving accounts) |
| Returns | `returns.view` | `returns.decide`, `returns.receive` | — |
| Inventory | `inventory.view` | `inventory.receive`, `inventory.adjust` | `inventory.settings` |
| Rules & settings (tax rules, advance %, return policies, stock thresholds, segment rules) | `rules.view` | `rules.edit` | `settings.system`, `settings.payment` |
| Staff & roles | — | — | `staff.manage`, `roles.manage`, `audit.view` |
| Notifications | own only — every logged-in user sees notifications for sections they can view | | |

"View" without "act" = read-only: pages open, action buttons are hidden and the routes refuse.

## 3. Starting roles (owner can edit or add more)

| Role | Who it is for | Sections it handles |
| --- | --- | --- |
| **Owner** | Shop owner | Everything, incl. staff & roles, receiving accounts, refunds, settings, audit log. Cannot be deleted; at least one active Owner must always exist. |
| **Review moderator** | Handles reviews & comments | Reviews (view, publish / flag / **archive**, reply), Products (view only), Customers (view only). **Cannot delete** reviews. |
| **Order manager** | Accepts and ships orders | Orders (all actions), Returns (decide, receive), Inventory (view), Customers (view), Payments (view, send payment request) |
| **Payment verifier** | Checks UPI / UTR / screenshots | Payments (view, verify, reject, request), Orders (view) — **no** refunds, **no** receiving accounts |
| **Rules manager** | Keeps business rules correct | Rules & settings (view, edit): tax rules, advance %, return policies, stock thresholds, customer segment rules. Payment/system settings stay Owner-only unless granted. |
| **Catalogue manager** | Adds/edits products | Products & catalogue (view, edit, import), Inventory (view), Reviews (view) |
| **Stock keeper** | Godown / showroom stock | Inventory (view, receive, adjust), Products (view), Orders (view, dispatch) |
| **Customer support** | Phone / WhatsApp / walk-in | Enquiries (all), Customers (view, edit), Orders (view), Reviews (view, reply) |

A person can hold several roles (e.g. small team: one person = Order manager + Payment verifier).
Permissions add up; there is no "deny" rule.

**Separation rules (built into the code, not just roles):**
- The person who **verified** a payment cannot also **refund** it (needs Owner).
- A user cannot change their own roles or deactivate themselves.
- Sensitive permissions (right column above) still go through the existing "ask twice" confirm
  (`partials/sensitive-confirm.php`), but the confirm becomes **re-enter your password** instead of
  the front-end OTP 123456.

## 4. Accounts, login and passwords

### 4.1 Default admin (first run)

- `php vayu admin:install` (also run by the deploy steps) creates the **default admin**
  only when no Owner exists: name "Store Owner", role Owner, email/phone taken from
  `ADMIN_EMAIL` / `ADMIN_PHONE` in `.env`.
- Its password is a random 10-character password **printed once in the console** (never a fixed
  password like `admin123` in the code). The account is marked **must change password**, so the
  first login goes straight to "Set your password".
- Running the command again does nothing if an Owner exists (safe to repeat).

### 4.2 Admin creates a staff member (or another admin)

`/admin/staff` → **Add staff** dialog:

| Field | Rule |
| --- | --- |
| Name | required |
| Email | optional, unique among staff |
| Mobile (WhatsApp) | optional, 10-digit Indian number, unique among staff |
| | **at least one of email / mobile is required** — it becomes their login ID |
| Roles | one or more (checkboxes). Only an **Owner** can give the Owner role → that is how an admin creates another admin. |
| Send via | Email / WhatsApp / Both / Don't send (copy manually) — only channels the person has are enabled |

On save:
1. Account is created with status **Invited**, a random **default password** (10 chars, easy to
   type, no look-alike characters like 0/O, 1/l) and **must change password = yes**.
2. A **login link** is made: `/admin/login?invite=<token>` — it opens the login page with their
   email/phone already filled. The link and the default password both **expire in 72 hours**.
3. Message sent:
   - **Email** → sent by the app's Mailer (needs `MAIL_*` in `.env`).
   - **WhatsApp** → a `wa.me` link opens on the admin's phone/PC with the message pre-typed; the
     admin presses Send (no WhatsApp API needed — same approach as payment requests).
   - Message text: *"Hi {name}, you've been added to Maa Sarada Admin as {roles}. Log in:
     {link} · Login ID: {email/phone} · Temporary password: {password} · Valid for 72 hours. You
     will be asked to set your own password."*
4. A success toast shows the result. The dialog also shows the password + link **once** with a
   Copy button, in case the message did not go through.
5. On first login: forced **Set your password** screen → status becomes **Active**.

If 72 hours pass: status shows **Invite expired** → admin presses **Resend invite** (makes a new
default password + link; old one stops working).

### 4.3 Admin resets someone's password

Staff list row menu → **Reset password** (needs `staff.manage`):
- New default password generated, must-change set again, all that person's sessions are logged
  out, sent by Email / WhatsApp exactly like the invite (same 72-hour expiry).
- Owner-account rule: only an Owner can reset another Owner. Nobody resets their own password
  here — they use 4.5.
- Admin is asked to confirm with **their own password** first (sensitive action) and it is
  written to the audit log.

### 4.4 Login

- `/admin/login`: one field **"Email or mobile number"** + password. The app detects which one
  it is (contains `@` = email, else digits = mobile; `+91`, spaces and leading 0 are stripped).
- 5 wrong tries in 15 min → locked for 15 min (per account and per IP); error toast says when to
  try again. Disabled / expired-invite accounts get a clear message, not "wrong password".
- Session ID regenerated on login, idle logout after 2 hours, optional "Remember this device"
  (30 days). Changing a password logs out all other sessions.

### 4.5 Change own password with OTP (also "Forgot password")

From **My account → Change password**, or **Forgot password?** on the login page:
1. Code is sent to the account's **email** (WhatsApp OTP: later, see below).
2. A **6-digit OTP** is sent. Valid **10 minutes**, max **5 wrong tries**, **Resend** after
   60 seconds, max 5 codes per hour. Stored hashed, never in plain text.
3. Enter OTP + new password (twice, min 8 chars, can't be the same as the old one).
4. Password saved → other sessions logged out → success toast (+ "Your password was changed"
   message to the other channel if they have one).

**OTP is email-only for now (owner decision 2026-11-22).** WhatsApp OTP would need a paid
sending service (a `wa.me` link can't send automatically), so it is not built. The code keeps a
small `OtpChannel` seam so a WhatsApp provider can be added later without changing the flow.
**Provider chosen:** Meta Cloud API, `auth_otp` template (`docs/whatsapp.md` phase W3). It switches
on only when the WhatsApp `.env` credentials are filled. If a send fails, it falls back to email.
- Step 1 above has no channel choice yet: the code always goes to the account's email.
- Accounts with **no email** (phone-only) can't use OTP: the screen says "Ask an admin to reset
  your password", and the admin uses 4.3 (which can still send by WhatsApp `wa.me`).
- `MAIL_*` must work in production.

## 5. Data (one migration)

| Table | Key columns |
| --- | --- |
| `admin_users` | name, email (unique, nullable), phone (unique, nullable), password, status (invited / active / disabled), must_change_password, temp_password_expires_at, last_login_at, last_login_ip, password_changed_at, created_by |
| `roles` | name, slug (unique), description, is_system (Owner = locked) |
| `role_permissions` | role_id, permission (string, e.g. `orders.accept`) |
| `admin_user_roles` | admin_user_id, role_id |
| `admin_invites` | admin_user_id, token_hash, purpose (invite / reset), channels sent, expires_at, used_at, created_by |
| `admin_otps` | admin_user_id, code_hash, channel (email now; whatsapp reserved), purpose (change_password / forgot_password), attempts, expires_at, used_at |
| `admin_remember_tokens` | admin_user_id, token_hash, device label, expires_at |
| `admin_login_attempts` | login_id, ip, success, reason, created_at (lockout + login history) |
| `audit_logs` | (already planned in payments migration) — now records `admin_user_id` on every action |

The list of possible permissions lives in code (`config/permissions.php`, grouped by section with
labels), so new modules add their permissions there; only role ↔ permission links are in the DB.
Existing nullable `created_by` columns (inventory movements, payments, etc.) start getting filled.

## 6. How it plugs into the code

- `AdminAuth` middleware on the whole `/admin` group (login/setup/reset routes excluded).
- `can:orders.accept` style middleware per route → no permission = 403 page in admin style +
  error toast "You don't have access to …".
- `can('payments.verify')` helper in views: sidebar groups hide when the user can't view that
  section; action buttons hide when they can't act.
- Topbar user chip shows the real name + role(s) (replaces "Admin / Store Owner"); menu = My
  account, Change password, Log out.
- Notification feed filters by the sections the user can view.

## 7. Admin screens

- `/admin/staff` — list (name, login ID, roles, status Invited / Active / Disabled / Invite expired,
  last login), Add staff, Resend invite, Reset password, Disable / Enable, Edit roles.
- `/admin/roles` — role cards; edit = permission checklist grouped by section (View / Act /
  Sensitive columns, like the table in §2), shows how many staff use the role.
- `/admin/account` — my profile, change password (OTP), my login history, log out other devices.
- `/admin/audit` — who did what, filter by user / section / date (Owner).

## 8. Build phases

1. Migration + models + `config/permissions.php` + seed 8 roles + `admin:install` (default admin).
2. Login (email or phone) / logout / lockout / remember-me / forced first-password screen +
   `AdminAuth` middleware on `/admin`.
3. Staff screen: add staff (incl. another admin), invite by email + WhatsApp link, resend,
   reset password, disable.
4. OTP: change password + forgot password by email only.
5. `can` middleware on every existing admin route + sidebar/button hiding + 403 page; roles screen.
6. Audit log wiring, password re-confirm for sensitive actions.
7. Verify: log in as each seeded role, run invite → first login → OTP change → admin reset.

## Still open

- `MAIL_*` must work in production for email invites and OTPs — test send from `/admin/account`.
- WhatsApp OTP: deferred until the owner picks a provider.
