# Payments: Direct UPI, Payment Window & Manual Verification

**Status: planned (not yet built).** This replaces the `order_payments` table from `docs/orders.md`.
It depends on the Customers and Orders modules. Production database is **MySQL** (SQLite is
local dev only, so nothing here is SQLite-specific). Vayu conventions apply: string statuses,
indexed `bigInteger` instead of FKs, POST-for-mutations, `admin.payments.*` names, and nullable
`*_by` columns (there is no admin login, and it is out of scope here).

## Owner decisions

| Topic | Decision |
| --- | --- |
| Collection | **Direct UPI to our own VPA** (QR + UPI app link). Payment gateways are out of scope for this plan (future). |
| Verification | **Manual.** The admin checks the bank/UPI app and enters the exact amount received. |
| Customer proof | Payer **UPI account name** (required) + **payment ID / UTR *or* a screenshot** (at least one). |
| Payment window | One shared window, open **6 minutes**, with a "do not close this window" warning. |
| Advance | **20%** minimum at checkout. The customer may pay more, up to the full amount. |
| Lump sum | **No.** One payment → one order. |
| Overpayment | **Always refunded** (manual, recorded by admin). |
| Rejected order | **Manual.** The customer sees a note. The admin handles any refund off-system, then records it. |
| Receiving-accounts page | Gated by an OTP prompt, fixed **`123456`**, **front-end only** for now. |
| Rounding | MySQL `DECIMAL(12,2)` (exact). Advance amount **rounded up** to 2 decimals. Other math rounds half-up to 2 decimals. |

## 1. The payment window (one component, used everywhere)

A single modal, `partials/pay-window.php` + `pay-window.js`. It opens from:

1. **Storefront checkout:** "Place order & pay".
2. **Public order page** `/order/{token}`: "Pay remaining".
3. **Public invoice** `/invoice/{token}`: a **floating "Pay ₹X" button** (bottom-right, hidden when
   nothing is due).
4. **Pay link** `/pay/{order token}`: sent by the admin as a **payment request** (§6) by email,
   WhatsApp or SMS. The page shows the order summary + "Pay now". The 6-minute timer starts only
   when the customer clicks Pay now, not when the link is opened.

What it shows:

```
┌──────────────────────────── Pay ₹5,000.00 ─────── 05:42 ┐
│ ⚠ Do not close or refresh this window until you submit. │
│                                                          │
│ Step 1 — Pay                                             │
│   [ QR code ]   UPI ID: maasarada@sbi  [copy]            │
│   [ Open UPI app ]  (mobile only — upi://pay?…)          │
│   Ref: MSM-PA-0042 (please keep it in the note)          │
│   Above ₹1 lakh? Bank transfer details ▸                 │
│                                                          │
│ Step 2 — Confirm                                         │
│   Name on your UPI account *  [____________]             │
│   Payment ID / UTR            [____________]             │
│   — or — Screenshot           [ Upload ]                 │
│                                                          │
│               [ I have paid — Submit ]                   │
└──────────────────────────────────────────────────────────┘
```

- The amount is **fixed by the server**, never typed by the customer:
  - Checkout: the customer picks **20% advance** (rounded up) or **Full**.
  - Order page / invoice / pay link: **amount due now** = outstanding − amounts already submitted
    and awaiting verification. This stops a customer paying the same balance twice while the first
    payment is still unverified.
- **6-minute timer.** The server stores `expires_at`. The client countdown is only for display.
  The server accepts submissions up to **30 s past expiry** (to absorb slow uploads) and refuses
  anything later. A `beforeunload` warning shows if the customer tries to close the window.
- **On expiry or close without submit**, the attempt becomes `expired` (a failed payment). The window
  then shows: *"Time ran out. If you already paid, don't pay again. Contact us on WhatsApp with
  your payment ID."* plus a **Try again** button, which starts a new 6-minute attempt.
- The UPI link `tn`/`tr` carry the attempt number (`MSM-PA-0042`), so the admin can spot it in
  the bank/UPI app.
- **Bank-transfer details** are in the same window for amounts above the UPI limit. The same
  Step 2 form is used, with the UTR or IMPS/NEFT reference.

### Checkout rule: order placed only on submit

```
Checkout → window opens (attempt created, cart snapshot held on the attempt, NO order yet)
   ├─ customer submits name + UTR/screenshot within 6 min
   │      → order created (pending) + attempt linked → admin sees it in Orders and Verify queue
   └─ timeout / closed
          → attempt = expired (failed payment), no order, cart kept for retry
          → shown to admin under Payments ▸ Failed attempts (customer phone + amount),
            so a "paid but didn't submit" customer can be followed up and converted to an order
```

On the storefront, a customer can't place an order without submitting payment details. Orders the
admin creates (phone/walk-in) don't need a window. The admin records payments on them directly.

## 2. Admin verification window

Admin ▸ **Payments ▸ To verify** (also shown inline on the order detail). Clicking **Verify** opens:

```
┌──────────── Verify MSM-PA-0042 · Order MSM-ORD-0012 ────────────┐
│ Customer submitted:  ₹5,000.00 expected                          │
│   Name on UPI: Ramesh K. Das     Payment ID: 431298765012        │
│   Screenshot: [ thumbnail — click to enlarge ]                   │
│   Submitted 22 Nov 2026, 3:41 pm   (paid to: SBI UPI maasarada@sbi)│
│                                                                  │
│ Amount actually received * [ ________ ]   (blank on purpose)    │
│ Received into *            [ SBI Current ••4521 ▾ ]              │
│ Received on *              [ 22-11-2026 15:40 ]                  │
│ Notes                      [ ____________________ ]              │
│                                                                  │
│        [ Reject… ]                         [ Confirm received ]  │
└──────────────────────────────────────────────────────────────────┘
```

- **Amount is left blank on purpose.** The admin types what the bank actually shows, so a mistyped
  or fake claim can't slip through on a prefilled value. If it differs from the expected amount,
  a warning shows ("₹4,000 received vs ₹5,000 expected — order will show ₹1,000 more due").
- **Confirm** creates a `payments` row (status `received`) with the admin's amount. The attempt
  becomes `verified`, and the order totals are recalculated:
  `paid = Σ received − Σ refunds`, `due = grand_total − paid`.
- **Reject** (fake/unmatched) requires a reason. The attempt becomes `rejected` and the order total
  is unchanged.
- **Duplicate guard:** a UTR already on another verified payment shows a red warning ("used on
  MSM-ORD-0009"). The admin can still decide.
- If the result is **overpaid** (paid > total), the order gets a **"Refund ₹X due"** badge. The
  admin refunds by hand and records it (§4).

## 3. Order ↔ payment data (shown together)

The order list and order detail always show payment data next to the order data.

- **Order list:** extra columns *Paid / Due* + a payment badge (`Unpaid`, `Partially paid`,
  `Paid`, `Overpaid`, `Refunded`) + an amber **"Verify ₹X"** chip when a submission is waiting.
- **Order detail, Payment card:** Total · Paid · Awaiting verification · **Due**, progress bar,
  then every attempt and payment in time order (submitted details, screenshot, admin amount + notes,
  receiving account, refunds), and the buttons **Verify**, **Record payment** (cash / cheque / bank
  / UPI at shop: amount + account + reference + notes), **Request payment** (§6), and
  **Record refund**.
- `orders.paid_amount`, `orders.due_amount` and `orders.payment_status` are **cached** columns,
  recomputed in one place (`PaymentService::syncOrder`) after every verify, payment or refund.

| `payment_status` | Rule |
| --- | --- |
| `unpaid` | paid = 0 and no refunds |
| `partially_paid` | 0 < paid < total |
| `paid` | paid = total |
| `overpaid` | paid > total → refund due |
| `refunded` | money was received and paid is now 0 |

"Awaiting verification" is a separate flag (`orders.pending_verification_amount`), not a status,
because an order can be `partially_paid` and have a new submission waiting.

## 4. Rejected orders & refunds (manual)

- Rejecting a pending order changes nothing in payments automatically.
- The admin enters a **customer-facing note** when rejecting (prefilled: *"Sorry, we couldn't
  accept this order. Our team will contact you about your payment of ₹X."*). It shows on the public
  order page `/order/{token}`.
- The admin sends money back outside the system (UPI/bank), then clicks **Record refund** (amount,
  from-account, reference, note). `paid` drops accordingly.
- The same "Record refund" covers overpayments and return refunds (`return_request_id` link).

## 5. Receiving accounts page (OTP-gated, front-end only)

> Part of the future **Payment settings** area (owner will plan it): receiving accounts, tax
> details, tax rules (all products / specific products), advance %, and other payment decisions.
> It's kept separate from **System settings** (logo, theme, channels…). This page is the first
> piece of it.

`/admin/payments/accounts`: where the shop receives money (UPI IDs, bank accounts, cash
counter). Each account has a name, type (`upi|bank|cash`), UPI ID + payee name, bank name, IFSC,
holder, account no. (last 4 shown masked), "default for the payment window", and active/inactive.

- Opening the page shows an **OTP overlay** (6 boxes, paste supported). Entering `123456` unlocks the
  page for **that page view** only, so it asks again on every open or refresh.
- **Honest limit:** it's a JS check, so the details are still in the HTML for anyone who views the
  source. It's a UI placeholder for the real server-side OTP later. When that comes, the page
  will load the details only after the server accepts the OTP. The page is designed for that swap.
- The account marked **default UPI** is what the payment window shows.
- **Every change asks twice** (review old→new + OTP again) and is written to `audit_logs`. See
  roadmap "Sensitive changes: ask twice".

## Schema (MySQL)

One migration, after the customers and orders migrations. All money `DECIMAL(12,2)`.

**payment_accounts** (receiving accounts) — `id, name, type(upi|bank|cash), upi_id,
payee_name, bank_name, account_holder, account_number, ifsc, is_default_upi, is_active, notes,
timestamps`.

**payment_attempts** (one per window opening) — `id, attempt_number(unique MSM-PA-NNNN),
public_token(unique), context(checkout|order|invoice|request), order_id(nullable until checkout
submit), customer_id(nullable), customer_phone, amount, payment_account_id, cart_snapshot(json,
checkout only), status(open|submitted|expired|verified|rejected), expires_at, payer_name,
payer_reference(UTR, nullable), proof_media_id(nullable → `media`, disk=private; see roadmap Stage 1), submitted_at,
reviewed_by, reviewed_at, reject_reason, ip, timestamps`.
Indexes: `(status, expires_at)`, `order_id`, `payer_reference`.

**payments** (money actually received, admin-confirmed) — `id, payment_number(unique
MSM/26-27/P0001), order_id, customer_id, payment_attempt_id(nullable, null = recorded at shop),
method(upi|bank_transfer|cash|cheque), payment_account_id, amount, reference, received_at,
notes, status(received|void), void_reason, created_by, timestamps`.

**payment_refunds** — `id, refund_number(unique), order_id, payment_id(nullable),
return_request_id(nullable), amount, payment_account_id, method, reference, reason, refunded_at,
created_by, created_at`.

**payment_request_logs** (every request the admin sends) — `id, order_id, channel(email|whatsapp|sms),
recipient, amount(due at send time), status(sent|failed|opened), error(nullable), sent_by,
created_at`. Index `(order_id, created_at)`. Used for the 30 s cooldown and the order timeline.

**orders additions** (see orders.md) — `paid_amount(0), due_amount, pending_verification_amount(0),
payment_status('unpaid'), refund_due(0), customer_note(nullable), public_token(unique),
channel(online|offline), channel_detail, last_payment_request_at(nullable)`. Payments also store
`collected_via(window|shop)`, so collections can be split independently of the order channel.

Expired attempts are marked by the existing cron (`php vayu …` alongside `inventory:check`),
and lazily on read. No queue needed.

**Optimization notes:** no provider/gateway tables or driver layer. Method is a plain string
column. Attempts double as failed payments and the audit trail. The pay link reuses the order's
`public_token`, so there's no per-request token, and an old link always shows the *current* due
amount and never goes stale. Money uses MySQL `DECIMAL`, so there's no conversion layer. Gateways,
webhooks, settlements and saved cards are out of scope (future).

## 6. Payment requests (admin → customer)

Available from the **order detail** (Payment card) and the **order list** row, for any order
that is not rejected and has **due > 0**.

```
┌─────────── Request payment · MSM-ORD-0012 ───────────┐
│ Amount due   ₹20,000.00   (from the order, read-only) │
│ Send via     ( Email ) ( WhatsApp ) ( SMS )           │
│ To           [ ramesh@example.com           ]  ← prefilled from customer, editable
│ Message      Hi Ramesh, ₹20,000.00 is due for order   │
│              MSM-ORD-0012. Pay securely: https://…/pay/…│
│                                       [ Send request ]│
│ Last sent: WhatsApp · 98•••••210 · 2 min ago          │
└───────────────────────────────────────────────────────┘
```

- **Amount** = order due − amounts awaiting verification, read from the order and never typed.
  The link itself always recomputes at open time, so a resent or old link can't over-ask.
- **Channels (what "send" really means):**
  - **Email:** sent by the server through the existing `Mailer` (SMTP). A real send, and logged
    `sent`/`failed`.
  - **WhatsApp:** no WhatsApp API, so the server logs the request and opens
    `https://wa.me/91XXXXXXXXXX?text=…` in a new tab. The admin presses **Send** in their own
    WhatsApp. Logged as `opened` (the system can't know it was delivered).
    **Later:** once the Meta Cloud API is active (`docs/whatsapp.md`), the same button sends the
    `payment_request` template for real (logged sent → delivered → read). While dormant it stays
    `wa.me`. The call goes through `WhatsAppService`, so this dialog doesn't change.
  - **SMS:** no SMS provider (Indian bulk SMS needs a paid, DLT-registered sender), so the same
    approach is used: an `sms:+91…?body=…` link opens the phone's messaging app. Works best when
    the admin uses a phone. A real SMS API can replace it later.
- **Phone normalisation:** numbers are stored/used as 10-digit Indian mobiles and `91` is prefixed
  for WhatsApp/SMS links. Invalid numbers are refused in the dialog.
- **30 s cooldown per order** (any channel). Enforced on the server via
  `orders.last_payment_request_at` (the request is refused with "wait Ns"). The button shows a
  countdown ring and stays disabled until it ends. This stops double sends and accidental spam.
- Every request appears in the order **timeline** ("₹20,000 requested via WhatsApp to 98•••••210,
  by admin, 3:41 pm").
- The request never changes order or payment status. Only a verified payment does.

## Rounding

- `DECIMAL(12,2)` in MySQL is exact, so SQL `SUM()` is safe.
- In PHP: `advance = ceil(total × 0.20 × 100) / 100` (round **up**). Everything else is
  `round(x, 2)` (half-up). Apply rounding once, at the point a value is created, never on display.
- Totals are compared as strings/cents (`(int) round($x * 100)`), never as floats.

## Gaps this plan closes (things not covered before)

1. **"Paid but window expired."** Expired attempts are visible to the admin (phone + amount + ref)
   so the money isn't lost and can be turned into an order.
2. **Double payment of the same balance.** Amount due excludes submissions awaiting verification.
3. **Customer can't see order status/rejection before an invoice exists** (the invoice is generated
   only at dispatch). A new public **order page** `/order/{token}` carries the rejection note and the
   Pay button. The invoice page gets the floating Pay button after dispatch.
4. **Wrong amount claimed.** The admin types the received amount, and the system shows the
   difference.
5. **UPI per-transaction limit (~₹1 lakh).** Bank-transfer details sit in the same window.

## Confirmed (2026-11-22, rev 3)

- Checkout offers **both**: "Pay 20% advance ₹X" (rounded up) and "Pay full ₹Y".
- **Dispatch is allowed with money still due.** It's the admin's call, with no block and no override
  step. The dispatch dialog only *shows* "₹X still due" as information, and the due amount stays on
  the order, the invoice (floating Pay button) and the order page until paid.
