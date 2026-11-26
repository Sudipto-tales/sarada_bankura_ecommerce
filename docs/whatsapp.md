# WhatsApp channel (Meta Cloud API)

**W1–W3 built (2026-11-24), dormant.** The official Meta WhatsApp Cloud API is used directly (no
BSP, no unofficial QR tools). Until the credentials are in `.env`, everything works exactly as
before: `wa.me` links for people to send by hand, and email for OTPs.

Not built yet: `return_update` hook, admin OTP channel + `staff_invite` hook, W4 campaigns,
W5 storefront phone OTP, WhatsApp permissions (admin-access phase 5).

## Credentials (`.env` only)

Never entered or shown in the admin panel; the status page shows only ✅ / ❌ per key.

| Key | Where to get it |
| --- | --- |
| `WHATSAPP_ENABLED` | `true` to switch on |
| `WHATSAPP_ACCESS_TOKEN` | Business Settings → System users → Generate token (`whatsapp_business_messaging` + `whatsapp_business_management`, expiry **Never**). Not the 24-hour test token. |
| `WHATSAPP_PHONE_NUMBER_ID` | App dashboard → WhatsApp → API Setup |
| `WHATSAPP_BUSINESS_ACCOUNT_ID` | same page (WABA ID) |
| `WHATSAPP_APP_SECRET` | App → Settings → Basic |
| `WHATSAPP_WEBHOOK_VERIFY_TOKEN` | a long random string we choose, pasted into Meta's webhook setup |
| `WHATSAPP_API_VERSION` / `WHATSAPP_DEFAULT_LANG` / `WHATSAPP_TEST_NUMBER` | optional (`v21.0`, `en`, owner's mobile for "Send test") |

**Active** = enabled **and** the five required values are set. Anything less = dormant.

## Templates to create in Meta

Same name, category and variable order as below. `config/whatsapp.php` maps app events to
templates, so a rename is a config change.

| Template | Category | Variables | Button |
| --- | --- | --- | --- |
| `auth_otp` | Authentication | code | Copy code |
| `order_confirmed` | Utility | name, order no, total | URL `/order/{token}` |
| `payment_request` | Utility | name, amount due, order no | URL `/pay/{token}` |
| `payment_received` | Utility | name, amount, order no, balance | URL `/order/{token}` |
| `payment_rejected` | Utility | name, order no, reason | URL `/order/{token}` |
| `order_dispatched` | Utility | name, order no, date | URL `/order/{token}` |
| `order_delivered` | Utility | name, order no | URL `/invoice/{token}` |
| `return_update` | Utility | name, return no, status | URL `/order/{token}` |
| `staff_invite` | Utility | name, login link | — |
| `offer_broadcast` | Marketing | name, offer text | optional URL |

URL buttons use Meta's dynamic form `https://<domain>/pay/{{1}}`; we pass only the token.
**Passwords never go through a template.**

## How sending works

`App\Services\WhatsApp\WhatsAppService` is the only class that calls Meta and writes outgoing
`whatsapp_messages`. `send(event, phone, params, opts)` returns
`SendResult {mode api|fallback, ok, messageId, fallbackUrl, error, logId}`.

- **Dormant:** no HTTP call. If the caller passes `fallback_text`, it returns the same `wa.me`
  link as before (logged `fallback / opened`); automatic sends do nothing.
- **Active:** template payload, 8 s timeout, logged `sent` + wamid or `failed` + Meta's code. On
  failure order/payment messages offer the `wa.me` link; **OTPs fall back to email, never wa.me**.
- Phone stored as a 10-digit mobile, sent as `91XXXXXXXXXX`. OTP params are stored as `••••••`;
  the token is scrubbed from every error text. OTP limits: 1 per minute, 5 per hour per phone.
- Sends are synchronous (no queue in this repo).

**Hooks (`FlowNotifier`, run after the flow's transaction, never throw):** order accepted →
`order_confirmed`; dispatched → `order_dispatched` + delivery OTP by `auth_otp` (else email);
delivered → `order_delivered`; payment verified / recorded → `payment_received`; rejected →
`payment_rejected`; payment request's WhatsApp channel → `payment_request`. The admin's custom text
is used only for the `wa.me` fallback. Per-event switches: `auto.*` in `config/whatsapp.php`.

## Webhook `/webhooks/whatsapp`

Public and CSRF-exempt (`webhooks` prefix in `VerifyCsrfToken::$except`). 404 while dormant.

- `GET` verify: echoes `hub.challenge` when the verify token matches, else 403.
- `POST`: checks `X-Hub-Signature-256` (HMAC of the raw body with the app secret, constant-time),
  else 403. Statuses only move forward (sent → delivered → read; failed only before delivered);
  unknown wamids are ignored; duplicates are no-ops. Incoming messages are stored as `in / received`,
  linked to the customer by phone, with a bell notification `whatsapp.inbound`. A message of just
  `STOP` / `UNSUBSCRIBE` sets `customers.marketing_consent = 0`.

## Admin page `/admin/settings/whatsapp`

Dormant / Active badge, ✅ / ❌ per key, webhook URL to copy, Check connection (name, number,
quality, daily tier) + template status from Meta, Send test (`auth_otp` to the test number),
auto-message switches, month-to-date cost estimate, message log with filters and a `wa.me` Reply
for incoming messages. The order timeline shows each message's tick; the OTP card says whether the
code went by WhatsApp or email.

## Data (migration 000013)

`whatsapp_messages` (direction, wamid, phone, template, category, masked params, mode api|fallback,
status queued|sent|delivered|read|failed|received|opened, error, related type/id) and
`whatsapp_campaigns` for W4 (counters are `sent_count / delivered_count / read_count /
failed_count` — `read` is reserved in MySQL).

## Next phases

- **W4 campaigns:** pick a segment (only `marketing_consent = 1`), an approved marketing template,
  preview count + estimated cost, schedule (IST); `php vayu whatsapp:dispatch` cron sends 50 per
  run. Sending is a sensitive change (ask twice).
- **W5:** storefront phone OTP (`auth_otp`, sets `users.phone_verified_at`).
- Add `whatsapp.inbound` to `NotificationService::TYPES` (it currently shows under "system").

## Cost (approximate, India — check before go-live)

Authentication and utility ≈ ₹0.12–0.13 each (utility is free inside an open 24 h window),
marketing ≈ ₹0.80–0.90, replies inside the customer's 24 h window are free. The status page
estimate uses the rates in `config/whatsapp.php`; the real bill is in Meta Business Manager.

## Owner activation checklist

1. Verify the business in Meta Business Manager.
2. Create an app (type Business) at developers.facebook.com and add WhatsApp.
3. Add a **new** number (not used in the WhatsApp app), verify it, set the display name.
4. Add a payment method in WhatsApp Manager.
5. Create a System user, assign the app + WABA, generate the permanent token.
6. Create the templates above and wait for approval.
7. Fill `.env`, set `WHATSAPP_ENABLED=true`, run `php vayu optimize`.
8. Webhook URL `https://<domain>/webhooks/whatsapp` + verify token, subscribe to **messages**.
9. `/admin/settings/whatsapp` → Check connection → all templates ✅ → Send test.
