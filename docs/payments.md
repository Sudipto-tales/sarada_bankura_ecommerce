# Payments: direct UPI, payment window & manual verification

**Built (Stage 5, 2026-11-24).** Money comes in by UPI to the shop's own account (or at the shop)
and an admin confirms every amount by hand. There is no payment gateway.

| Page | Route |
| --- | --- |
| Payments (To verify · Received · Failed attempts · Refunds · Requests) | `/admin/payments` |
| Receiving accounts (OTP-gated) | `/admin/payments/accounts` |
| Receipt (print) | `/admin/payments/{id}/receipt` |
| Pay link (public) | `/pay/{order token}` |

## Owner rules

| Topic | Rule |
| --- | --- |
| Collection | Direct UPI to our own VPA (QR + UPI app link); bank transfer details for large amounts. Gateways (Razorpay/Stripe) are future work. |
| Verification | Manual: the admin checks the bank / UPI app and **types the amount actually received**. |
| Customer proof | Payer UPI name (required) + UTR **or** screenshot (at least one). |
| Window | 6 minutes, server-enforced, "do not close this window" warning. |
| Checkout | 20% advance (rounded **up** to the paisa) **or** full. |
| One payment → one order | No lump sums across orders. |
| Overpayment / rejected order | Refunded by hand outside the system, then recorded. |
| Corrections | A mistyped payment or refund is **voided** (kept, struck through), never edited or deleted. |

## The payment window

One component used everywhere: storefront checkout, "Pay remaining" on `/order/{token}`, the
floating "Pay ₹X" button on `/invoice/{token}`, and `/pay/{token}` links sent as payment requests.
Store look: `resources/views/store/partials/pay-window.php`; invoice: `documents/_pay-window.php`;
JS `public/assets/js/pay-window.js`.

- The **amount is set by the server**: checkout = advance or full; otherwise **due now** =
  outstanding − amounts already submitted and awaiting verification (so a balance can't be paid
  twice while the first payment is unverified).
- The server stores `expires_at` (IST + 6 min) and accepts a submit up to 30 s late. Closing the
  window sends a beacon → attempt `expired`. Cron `payments:expire` and page loads also expire stale
  windows. "Try again" starts a new attempt.
- On `/pay/{token}` the timer starts at **Pay now**, not when the link is opened. The link reuses
  the order's `public_token`, so an old link always shows the current due amount.
- UPI link: `upi://pay?pa&pn&am&cu=INR&tn=<order + ref>`. `tr` is left out on purpose — some apps
  refuse personal / current-account VPAs that carry it.
- Screenshots are private media (image or PDF), never under `public/`.
- **Storefront checkout creates the order only on submit.** An expired attempt is a failed payment
  with no order; it shows under Failed attempts (phone + amount) so a "paid but didn't submit"
  customer can be followed up. Admin-created orders don't use the window.

## Verification (admin)

- **To verify** queue + the order detail payment card. The verify window leaves the amount
  **blank on purpose**, shows the difference live, warns when the UTR is already on another
  payment, and needs the receiving account and time.
- **Confirm** → `payments` row (`received`, `collected_via = window`) → attempt `verified` →
  `syncOrder()`. **Reject** needs a customer-facing reason (shown on the order page).
- **Record payment** at the shop (cash / cheque / bank / UPI) → `collected_via = shop`.
- **Record refund** covers overpayments, rejected orders and returns (`return_request_id`).

## Order money columns

Cached on `orders`, written only by `Order\PaymentService::syncOrder()` (integer-paise maths):
`paid_amount = Σ received − Σ refunds`, `due_amount`, `pending_verification_amount`,
`refund_due`, `payment_status`.

| `payment_status` | When |
| --- | --- |
| `unpaid` | nothing received |
| `partially_paid` | 0 < paid < owed |
| `paid` | paid = owed |
| `overpaid` | paid > owed → "Refund ₹X due" badge |
| `refunded` | money was received and paid is back to 0 |

Owed = grand total − credits of completed returns. "Awaiting verification" is a separate amount,
not a status. Rounding: `DECIMAL(12,2)` in the DB, `round(x, 2)` once when a value is created,
compare as integer paise, never as floats.

## Payment requests (admin → customer)

From the order detail and the order list, for any non-rejected order with money due.

- Amount = due now, read-only. Message prefilled with the `/pay/{token}` link.
- **Email:** real send via `App\Services\Mail\Mail` (PHPMailer); logged `sent` / `failed`. Reports
  "Email isn't set up yet" while `MAIL_*` is empty.
- **WhatsApp:** template send when the Meta API is active, otherwise a `wa.me` link the admin sends
  by hand (logged `opened`).
- **SMS:** an `sms:` link opens the phone's messaging app (no SMS provider).
- **30 s cooldown per order** on the server (`orders.last_payment_request_at`). Every request is
  logged in `payment_request_logs` and shown in the order timeline. A request never changes order
  or payment status.

## Receiving accounts

`/admin/payments/accounts` (UPI / bank / cash; the default UPI is what the window shows).

- Opening the page asks for OTP **`123456`** (fixed placeholder until a real server OTP exists). The
  account data is **not** in the page source: it is fetched by `POST …/unlock` after the OTP, which
  the server checks again.
- Every change asks twice (`partials/sensitive-confirm.php`: review old → new masked, then OTP
  again; deleting also needs the name typed) and is written to `audit_logs` (shown as change
  history). Accounts that have money recorded can only be deactivated.

## Data (migration 000012)

`payment_accounts` · `payment_attempts` (context checkout|order|invoice|request, cart snapshot,
status open|submitted|expired|verified|rejected, payer name / UTR / proof media) · `payments`
(method, account, `collected_via` window|shop, status received|void) · `payment_refunds` (status
paid|void, optional `return_request_id`) · `payment_request_logs` · `audit_logs`.

## Code (`app/Services/Payment/`)

`Accounts` (receiving accounts + masked audit) · `PayWindow` (open / resume / submit / close /
expire, `forCheckout()`) · `Ledger` (verify / reject / record / refund / void) · `PaymentRequests`
(channels + cooldown) · `Audit`. `Order\PaymentService` keeps `syncOrder()`, `advance()` and
`dueNow()`. Notification `payment.submitted`. Topbar chips *To verify* and *Refund due*.

## Not built

Dashboard money cards, a real server-side OTP for receiving accounts, real SMS, payment gateways.
