# WhatsApp Channel (Meta Cloud API)

**Status: PLANNED (2026-11-22), not built.** Owner decision: use the **official Meta WhatsApp
Cloud API directly** (no BSP, no unofficial QR tools like WA Sender). The feature is built now and
stays **dormant** until the owner puts the credentials in `.env`. Until then, every flow keeps
working exactly as planned today (`wa.me` links, email OTP).

## 1. Credentials (`.env` only)

Credentials are **never** entered or shown in the admin panel. They live in `.env` only. The
admin page shows just ✅ / ❌ per item.

| Env key | Required | Where to get it (Meta) | Used for |
| --- | --- | --- | --- |
| `WHATSAPP_ENABLED` | yes | set `true` to switch on | master switch |
| `WHATSAPP_ACCESS_TOKEN` | yes | Business Settings → Users → **System users** → Generate token (permissions `whatsapp_business_messaging`, `whatsapp_business_management`, expiry **Never**) | `Authorization: Bearer` on every call |
| `WHATSAPP_PHONE_NUMBER_ID` | yes | App dashboard → WhatsApp → API Setup | send endpoint `/{id}/messages` |
| `WHATSAPP_BUSINESS_ACCOUNT_ID` | yes | same page (WABA ID) | list templates + their approval status |
| `WHATSAPP_APP_SECRET` | yes | App → Settings → Basic → App secret | check webhook signature `X-Hub-Signature-256` |
| `WHATSAPP_WEBHOOK_VERIFY_TOKEN` | yes | **we invent it** (long random string), paste the same into Meta's webhook setup | one-time webhook verification |
| `WHATSAPP_API_VERSION` | no (default `v21.0`) | Graph API version | URL `https://graph.facebook.com/{ver}/…` |
| `WHATSAPP_DEFAULT_LANG` | no (default `en`) | language code the templates were approved in | template `language.code` |
| `WHATSAPP_TEST_NUMBER` | no | owner's own mobile (10 digits) | "Send test" button |

**Active** = `WHATSAPP_ENABLED=true` **and** all five required values are non-empty. Anything
less means **dormant**, and the service silently uses the fallback (§3). The 24-hour temporary token
from the API Setup page must not be used. It expires and every send would fail.

## 2. Templates to create in Meta (exact names)

Every message we start must use an approved template. The owner creates these in WhatsApp
Manager → Message templates, with the **same name, category and variable order**. `config/whatsapp.php`
maps each app event → template name + parameter order, so a rename is a config change only.

| Template name | Category | Variables (in order) | Button | Used by |
| --- | --- | --- | --- | --- |
| `auth_otp` | Authentication | `{{1}}` code | Copy code | admin password OTP, delivery OTP, later customer login |
| `order_confirmed` | Utility | name, order no, total | URL → `/order/{token}` | order accepted |
| `payment_request` | Utility | name, amount due, order no | URL → `/pay/{token}` | payments §6 request |
| `payment_received` | Utility | name, amount, order no, balance due | URL → `/order/{token}` | payment verified / recorded at shop |
| `payment_rejected` | Utility | name, order no, reason | URL → `/order/{token}` | payment rejected |
| `order_dispatched` | Utility | name, order no, dispatch date | URL → `/order/{token}` | dispatch (delivery OTP goes separately via `auth_otp`) |
| `order_delivered` | Utility | name, order no | URL → `/invoice/{token}` | delivered |
| `return_update` | Utility | name, return no, status | URL → `/order/{token}` | return accepted / rejected / refunded |
| `staff_invite` | Utility | name, login link | — | admin-access invite + reset (password stays on screen / email) |
| `offer_broadcast` | Marketing | name, offer text | URL (optional) | campaigns (Phase W4) |

URL buttons use Meta's "dynamic URL" form `https://<domain>/pay/{{1}}`, and we pass only the token.
**Passwords are never sent through a template.** Meta may reject such templates, and it is unsafe.
The invite message carries the link, and the default password is shown to the admin to pass on, as it is now.

## 3. How sending works (one service, dormant-safe)

```
caller (PaymentRequest, OrderService, OtpChannel…)
        │  WhatsApp::send(event, phone, params, related)
        ▼
  isActive()? ── no ──► return Fallback(wa.me URL with the same text)  → UI opens it (today's behaviour)
        │ yes
        ▼
  build template payload → POST graph.facebook.com/{ver}/{phone_id}/messages  (8 s timeout)
        │                                   │
     200 + wamid                         error / timeout
        ▼                                   ▼
  log whatsapp_messages(status=sent)    log status=failed + error → caller decides fallback
```

- **Single writer:** only `App\Services\WhatsApp\WhatsAppService` talks to Meta and writes
  `whatsapp_messages`. Callers never build URLs or payloads themselves.
- **Return value** `SendResult { mode: api|fallback, ok, messageId, fallbackUrl, error }`. The UI
  shows "Sent on WhatsApp ✓" (api) or opens the `wa.me` tab (fallback), so one code path serves both states.
- **Fallback rules:** dormant → `wa.me`. API failed → order/payment messages offer the `wa.me`
  button. OTP → falls back to **email** (never `wa.me`, since a human must not relay codes).
- **Phone format:** stored as 10-digit Indian mobile, sent as `91XXXXXXXXXX` (no `+`).
  Invalid → refused before any call.
- **Sync for one-off messages** (OTP, payment request, order updates). There is no queue in this repo.
  Campaigns are sent in batches by cron (§6).
- **Limits:** OTP 1 per 60 s and 5 per hour per phone; payment request keeps its 30 s per-order
  cooldown (payments §6); Meta's own tier limit (e.g. 250 / 1k unique users per day) is shown on
  the status page.
- **Never logged:** OTP codes (stored as `••••••`) and the access token (not in logs or exceptions).

## 4. Webhook (delivery status + incoming messages)

Route `/webhooks/whatsapp`, public and **CSRF-exempt** (Meta can't send a token).

- `GET`: Meta verification. If `hub.verify_token` equals `WHATSAPP_WEBHOOK_VERIFY_TOKEN`, echo
  `hub.challenge`, else 403.
- `POST`: first check `X-Hub-Signature-256` = `sha256=` HMAC of the **raw body** with
  `WHATSAPP_APP_SECRET` (constant-time compare, else 403). Then:
  - `statuses[]` → update `whatsapp_messages` by `wamid`: sent → delivered → read, or failed + error code.
    Status only moves forward (late "delivered" after "read" is ignored).
  - `messages[]` (customer wrote to us) → store as `direction=in`, open the free 24-hour reply
    window for that phone, post a bell notification `whatsapp.inbound` ("Ramesh replied on WhatsApp").
  - Text `STOP` / `UNSUBSCRIBE` → set `customers.marketing_consent = 0` and log it. Utility
    messages (orders, payments) still go out, since they are not marketing.
- Always answer `200` fast. Meta retries on errors, so handling is idempotent on `wamid`.

## 5. Data (migration `…_000013_create_whatsapp_tables.php`, MySQL)

**`whatsapp_messages`**: `id, direction(out|in), wamid(unique, nullable), phone(10-digit),
template(nullable), category(authentication|utility|marketing|service), params_json(masked),
body_preview(text), mode(api|fallback), status(queued|sent|delivered|read|failed|received),
error_code, error_text, related_type(order|payment_request|admin_user|customer|campaign|null),
related_id, campaign_id(nullable), sent_by(admin_user_id, nullable), created_at, status_at`.
Indexes: `(phone, created_at)`, `(related_type, related_id)`, `(status)`.

**`whatsapp_campaigns`** (Phase W4): `id, name, template, params_json, segment_id
(customer_segments), status(draft|scheduled|sending|done|cancelled), scheduled_at(IST),
total, sent, delivered, read, failed, created_by, created_at`.

No credentials table: config comes from `.env` through `config/whatsapp.php`.

## 6. Features by phase

| Phase | What | Works while dormant? |
| --- | --- | --- |
| **W1 Core** | `config/whatsapp.php`, `.env.example` keys, `WhatsAppService` + `SendResult` + fallback, `whatsapp_messages`, status page | yes: everything falls back, so it is safe to ship early |
| **W2 Webhook** | `/webhooks/whatsapp` verify + signature + statuses + inbound + STOP | route answers 403/404 until active |
| **W3 Hook into flows** | payment request (payments §6) → real send; order confirmed / dispatched / delivered; payment received / rejected; return updates; `OtpChannel` WhatsApp for admin OTP (admin-access §4.5) and delivery OTP; staff invite link | yes: each keeps its `wa.me` / email behaviour |
| **W4 Campaigns** | pick segment (only `marketing_consent = 1`), pick approved marketing template, preview count + **estimated cost**, schedule (IST), `php vayu whatsapp:dispatch` cron sends 50 per run, progress + per-recipient status | hidden until active |
| **W5 Customer OTP login** | storefront login by phone + `auth_otp` with SMS/email fallback. Built with the storefront (owner plans later) | n/a |

Per-event toggles (e.g. "send order_dispatched automatically") live in `config/whatsapp.php` for
now and move to **System settings → Channels** when the owner plans that page.

## 7. Admin page `/admin/settings/whatsapp` (read-only config)

```
┌ WhatsApp ──────────────────────────────────── ● Dormant / ● Active ┐
│ Credentials (.env)   ✅ Token  ✅ Phone ID  ❌ App secret  …        │
│ Connection           [Check]  → Display name · number · quality     │
│                       rating (green/yellow/red) · daily limit tier  │
│ Templates            required list vs Meta: ✅ approved ⏳ pending   │
│                       ❌ missing/rejected (pulled from WABA)        │
│ Test                 [Send auth_otp test to 98•••••210]             │
├─────────────────────────────────────────────────────────────────────┤
│ Message log   filters: direction · status · template · date (IST)   │
│ 3:41 pm  payment_request  98•••••210  MSM-ORD-0012  ✓✓ read         │
│ 3:20 pm  inbound  "Is the slab available?"  98•••••455  [Reply wa.me]│
└─────────────────────────────────────────────────────────────────────┘
```

- Sidebar: **Settings ▾ WhatsApp** (active) next to the greyed System / Payment items.
- Order detail timeline + payment request card show each message's ✓ / ✓✓ / read / failed tick.
- Permissions (`config/permissions.php`): `whatsapp.view`, `whatsapp.test`, `whatsapp.campaign`.
  Owner role gets all three. Campaign send is a **sensitive change** (ask twice: review count +
  cost, then OTP), per roadmap.
- Replies to inbound messages (free-text inside 24 h) are **not** built in W1–W4. "Reply" opens
  `wa.me`. A shared inbox is a possible later phase.

## 8. Cost awareness (Meta per-message pricing, India, approximate; check before go-live)

Authentication ≈ ₹0.12–0.13 · Utility ≈ ₹0.12–0.13 (free inside an open 24 h window) · Marketing
≈ ₹0.80–0.90 · replies inside the customer's 24 h window are free. The status page shows a
month-to-date count per category (from `whatsapp_messages`) × the rates in `config/whatsapp.php`,
as an **estimate only** (the real bill is in Meta Business Manager).

## 9. Owner activation checklist (later)

1. Meta Business Manager → verify business (GST / business proof).
2. developers.facebook.com → create app (type Business) → add **WhatsApp** product.
3. Add a **new number** (not on the WhatsApp app) → verify by SMS/call → set display name
   ("Maa Sarada Marble & Stone") and wait for approval.
4. Add a payment method in WhatsApp Manager (needed for business-started messages).
5. Create a **System user** → assign the app + WABA → generate the permanent token.
6. Create the templates in §2 and wait for approval.
7. Fill `.env` (§1), set `WHATSAPP_ENABLED=true`.
8. App → WhatsApp → Configuration → Webhook URL `https://<domain>/webhooks/whatsapp` + verify
   token → subscribe to **messages**.
9. `/admin/settings/whatsapp` → Check connection → all templates ✅ → Send test.

## 10. Verification (when built)

`tests/whatsapp_test.php` (in-memory DB, HTTP client stubbed): dormant → fallback URL with the
right text; active → correct JSON payload per template; failure → `failed` row + fallback;
OTP never stored in clear; phone normalisation; webhook signature accept/reject; status only
moves forward; duplicate webhook is a no-op; STOP clears consent; campaign skips non-consented
customers. Then an HTTP smoke test of the status page in both dormant and active (stubbed) modes,
on MySQL.
