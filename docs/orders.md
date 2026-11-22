# Orders, Payments, Invoices & Returns

**Status: planned (not yet built).** Status-driven order management for Maa Sarada Marble &
Stone, aligned to the existing inventory, customers and notifications modules. Order status,
payment status, invoice/document status, delivery status and return status are kept
**independent** of each other.

| Page | Route |
| --- | --- |
| Orders (one list, status filters) | `/admin/orders` |
| Create order | `/admin/orders/create` |
| Order detail | `/admin/orders/{id}` |
| Payments | `/admin/orders/payments` |
| Returns & Replacements | `/admin/returns` |
| Return request detail | `/admin/returns/{id}` |
| Product return policies | `/admin/return-policies` |
| Packing slip (print) | `/admin/orders/{id}/packing-slip` |
| GST invoice (print) | `/admin/orders/{id}/invoice` |
| Payment receipt (print) | `/admin/orders/payments/{id}/receipt` |
| Public invoice (QR target, no auth) | `/invoice/{token}` |

Conventions: `/admin` group, `admin.orders.*` / `admin.returns.*` / `admin.return-policies.*`
names, POST-for-mutations, string statuses (no ENUM), indexed `bigInteger` instead of FKs,
nullable `*_by` columns (no admin auth yet) — same as the inventory module.

## Confirmed business rules (from the owner)

- **Orders never check or reserve stock.** At accept, if stock is short, show a **warning** but
  let the admin accept anyway. Order creation/acceptance never blocks on stock.
- **No order cancellation after confirm.** An order is rejected only while `pending`; once
  accepted (confirmed) it proceeds through to delivered. There is no "cancelled" status.
- **Returns/replacements cannot be cancelled after submit**, and every request must be
  **accepted by an admin** before it proceeds.
- **Delivery is free / included in the product price** — there is **no shipping/delivery line**
  on the invoice. Price already absorbs those costs.
- **Tax is a single optional percentage** on the real product amount (after discount). No
  per-line tax. No customer GSTIN is collected (customers wanting GST benefit go through the
  frontend enquiry — out of scope here).
- Tax lives in **Payment settings** (owner will plan it later, separate from System settings).
  It will hold tax details **and tax rules** that apply either to **all products or to specific
  products**. Until that is planned, orders use the provisional single GST percent below. Once
  per-product rules exist, the order-level single rate becomes a per-line rate snapshot.

## Order workflow

```
pending ──accept──► accepted ──► processing ──► packed ──► dispatched ──► delivered
   │                                             (slip)     (invoice +      (OTP verify)
   └──reject──► rejected                                     stock out)
```

- **pending** — order placed, awaits admin. Initial payment may be zero/partial/full.
- **accept / reject** — admin confirms or declines (reject only here). Stock warning shown,
  never blocking. Internal note allowed. Reject also takes a **customer-facing note** shown on
  `/order/{token}`. Any refund of an advance is **manual** (admin pays back, then records it). Nothing
  automatic happens.
- **processing** — admin prepares the material.
- **packed** — records packed date + admin; generates a **packing slip** (Amazon-style, QR).
- **dispatched** — records courier + tracking + dispatch date; generates the **GST tax
  invoice**; **deducts stock** (see below); sends invoice to customer. Allowed even when money
  is still due.
- **delivered** — verifies **delivery OTP**, records delivery date + verifier.

Transition whitelist (enforced in `OrderService`): `pending→{accepted,rejected}`,
`accepted→processing`, `processing→packed`, `packed→dispatched`, `dispatched→delivered`.
Terminal: `delivered`, `rejected`. Payment never auto-changes order status.

## Stock integration (critical seam)

Stock is tracked **per product, not per variant**, and only `InventoryService` may write
`inventory_stock.quantity`. Orders must go through it — never touch inventory tables directly.

- Add `InventoryService::consumeForOrder(productId, qty, meta)` — deducts on **dispatch**,
  aggregating order-item quantities to product level, **skipping untracked products**, and
  **allowed to drive stock negative** (orders don't check stock — so dispatch is never blocked;
  a negative/low result just raises the normal alert + notification).
- Add `InventoryService::restockFromReturn(productId, qty, meta)` — adds stock back **only** when
  a return is approved **and** received.
- New movement `TYPES`: `sale`, `return_in`. New `SOURCE`: `order`. Movements carry the order id
  in `reference_no` so the stock history links back to the order.
- Created/accepted/processing/packed states touch **no** stock. There is no cancel-restock path
  (no cancellation exists); stock only comes back via received returns.

## Customers integration

On **delivered** (and whenever a payment lands), call the stubbed
`Services/Customer/CustomerStats` to refresh `total_orders`, `total_spent`, `last_order_at` and
`lifecycle` on the customer. Billing/shipping are chosen from `customer_addresses` **and
snapshotted** onto the order (a customer may later edit/delete the address).

## Notifications integration

Reuse the shared `notifications` table via `NotificationService`: new order (pending), order
accepted, payment received, order dispatched, return requested, return approved.

## Payments

**Defined in `docs/payments.md`.** Summary:

- Direct UPI only (own VPA, QR + UPI app link) through one shared **payment window** (6-min,
  collects payer UPI name + UTR or screenshot). **Manual verification:** the admin enters the exact
  amount received + notes. `payments` / `payment_attempts` / `payment_refunds` /
  `payment_accounts` replace `order_payments`.
- **Storefront orders are created only when payment details are submitted** (min 20% advance).
  An expired window is a failed attempt and no order is created.
- `paid_amount`, `due_amount`, `pending_verification_amount`, `payment_status`
  (`unpaid / partially_paid / paid / overpaid / refunded`) are cached on `orders`.
- No lump sum (1 payment → 1 order). Overpayment is always refunded, manually recorded.
- Each received payment can print a **payment receipt**.
- Outstanding = `grand_total − paid_amount`. **Dispatch is allowed with a due amount**, at the
  admin's discretion. There is no block or override, only an info line in the dispatch dialog.
- Checkout offers 20% advance **or** full payment.

## Easy status handling (admin UX)

Status changes should be one click wherever possible:

- **One "next step" button.** Order detail has a horizontal stepper (Pending → Accepted →
  Processing → Packed → Dispatched → Delivered) and **one primary button** for the only allowed next
  step ("Accept", "Start processing", "Mark packed", "Dispatch", "Mark delivered"). Pending also
  shows **Reject**. Nothing else is offered, so the admin can't pick a wrong status.
- **Same button in the list.** Each order row has the next-step button inline, so routine updates
  don't need opening the order.
- **Dialogs only when data is needed:**
  - Accept: optional note + non-blocking stock warning.
  - Reject: customer note (prefilled).
  - Dispatch: courier / tracking (optional) + "₹X still due" info.
  - Delivered: delivery OTP.
  - Processing and packed: no dialog, just click (with a small confirm toast).
- **Bulk update.** Tick several orders that are in the **same** status and move them all to the
  next step in one go (e.g. 8 orders → Packed). Steps that need a dialog (dispatch, delivered,
  reject) stay one-by-one.
- **Quick filters** at the top: status chips with counts (`Pending 3 · Processing 5 · …`) plus
  payment chips (`To verify 2 · Due 7 · Refund due 1`). These are the admin's to-do lists.
- **Payment never blocks a status step,** and a status step never changes payment. Both live on
  the same row and detail page, independent of each other.
- Every change is written to `order_status_history` (who/when/note) and shown as a timeline.

## Taxes (India) — how this is modelled

India charges **GST** on goods (VAT/CST are gone). It is destination-based:

- **Intra-state** (buyer & seller in the same state, e.g. both West Bengal) → **CGST + SGST**,
  each half of the GST rate.
- **Inter-state** (different states) → **IGST**, the full rate.
- The rate depends on the product's HSN — marble/granite blocks and worked stone (HSN
  2515/2516/6802) and tiles (6907) commonly sit at **18%** (some rough natural stone 5%).

Because the owner wants one simple optional percent and collects no customer GSTIN, the system
stores **one GST percent per order** (default from settings, editable, may be 0/disabled). The
invoice **displays** it as CGST+SGST (split 50/50) when place of supply is West Bengal, else as
IGST — a display convenience only. Totals: `grand_total = subtotal − discount + tax`.

> **Compliance flag:** a "Tax Invoice" charging GST is only valid if the business is
> **GST-registered** (seller GSTIN printed on the document). If not registered, the document is a
> **Bill of Supply** and no GST may be charged. So: seller GSTIN lives in settings; when tax is
> disabled or no GSTIN is set, the document self-labels **"Invoice / Bill of Supply"** instead of
> **"Tax Invoice"**. Have the accountant confirm registration and rate.

## Documents

All documents are **printable A4 HTML views** (dedicated print layout → browser "Save as PDF").
QR is rendered from a **locally-vendored** `qrcode.min.js` (no CDN — repo rule), encoding the
public invoice URL. Stored-PDF generation (mPDF via Composer) + Code128 barcode are an optional
**Phase 4b**, not a blocker.

### GST invoice (at dispatch)
Based on `ma-sarada-marble-invoice.html`: monochrome ink/forest/sage palette, DM Sans +
Playfair Display, black brand mark top-left (swap the placeholder for the Maa Sarada logo), A4
`@page`. Server-rendered real data replaces the `contenteditable` fields. **Changes from the
template:** remove the customer GSTIN field; remove the "Transportation & handling" line and any
delivery charge; tax becomes a single optional **"GST @ X%"** line (CGST+SGST or IGST split for
display); amount-in-words via an Indian-numbering PHP helper; QR = `/invoice/{token}`; gapless
sequential invoice number per financial year, e.g. `MSM/26-27/0001`. Seller name / GSTIN /
address / contact come from settings.

### Packing slip (at packed)
Amazon-style logistics slip: brand mark + **"PACKING SLIP"**, ship-to (customer + shipping
snapshot), order number + date, an **invoice QR** (+ optional Code128 barcode of the order
number in 4b), then an item table (`# · Description · Variant/SKU · Qty · Unit`) with **no
prices** (slips omit money). Footer note: "Tax invoice travels with the shipment — scan QR."
Printable A4.

### Payment receipt (per payment)
Compact printable receipt: order no, receipt no, date, amount, method, running paid/outstanding.

### Public invoice (`/invoice/{token}`)
Unauthenticated view rendered from the same invoice partial, reachable by the unguessable
`public_token` stored on `order_invoices`, so the QR works when shared with the customer.
Shows a **floating "Pay ₹X" button** (hidden on print and when nothing is due) that opens the
shared payment window for the **remaining amount** (see `docs/payments.md`).

### Public order page (`/order/{token}`)
New. It exists before any invoice does: order status, items, paid / due, the rejection note
(`customer_note`), and the same "Pay remaining" button.

## Schema

Migration `2026_11_24_000011_create_order_tables.php` (sequence in `docs/roadmap.md`) (Vayu-shaped: string statuses, indexed
bigInteger, `variant_id` default `0` for "no variant"):

**orders** — `id, order_number(unique), customer_id, billing_address_id, shipping_address_id,
billing_address(json), shipping_address(json), place_of_supply, order_status('pending'),
channel(online|offline), channel_detail(website|walk_in|phone|whatsapp|showroom), public_token(unique), subtotal, discount_amount, gst_rate(nullable),
tax_amount(0), grand_total, payment_status('unpaid'), paid_amount(0), due_amount,
pending_verification_amount(0), refund_due(0), notes, internal_note, customer_note,
accepted_by, accepted_at, rejected_by, rejected_at, reject_reason, created_by, created_at,
updated_at`. *(No shipping_amount — delivery is free. No cancellation fields. No
`payment_method` — an order has many payments. Money is `DECIMAL(12,2)`, MySQL in production.)*

**order_items** — `id, order_id, product_id, variant_id(0), product_name, variant_name, sku,
unit, quantity, unit_price, discount_amount, line_total, hsn_code, return_allowed,
replacement_allowed, return_days, replacement_days, created_at`. *(Policy + product snapshots at
order time; tax is order-level, not per line.)*

**order_status_history** — `id, order_id, old_status, new_status, changed_by, note, created_at`.

~~**order_payments**~~ — replaced by `payments`, `payment_attempts`, `payment_refunds` and
`payment_accounts` in `docs/payments.md`.

**order_invoices** — `id, order_id, document_type(tax_invoice|packing_slip|receipt),
invoice_number(nullable), public_token(unique), subtotal, tax_amount, total_amount,
invoice_date, file_path(nullable), created_by, created_at`.

**product_return_policies** — `id, product_id, variant_id(0), return_allowed,
replacement_allowed, return_days, replacement_days, return_conditions, is_active, created_at,
updated_at`, `UNIQUE(product_id, variant_id)`.

**return_requests** — `id, request_number, order_id, customer_id, request_type(return|
replacement), reason, description, status(submitted|approved|rejected|received|resolved),
admin_note, approved_by, approved_at, completed_at, created_at`.

**return_request_items** — `id, return_request_id, order_item_id, product_id, variant_id,
requested_quantity, approved_quantity, received_quantity, resolution_type(refund|replacement),
refund_amount, replacement_product_id, replacement_variant_id, created_at`.

**order_deliveries** — `id, order_id, courier_name, tracking_number, tracking_url,
dispatch_date, delivery_date, otp_hash, otp_expires_at, otp_attempts, otp_verified_at,
verified_by, delivery_note, created_at`. *(Store only the OTP hash; enforce expiry + attempt
limit.)*

~~**settings**~~ — **no provisional table** (see `docs/roadmap.md`). Until the owner plans System
and Payment settings, business identity (name, address, phone, email, GSTIN, logo) and tax defaults
(`tax.enabled`, `tax.default_percent`, `tax.label`) come from a **config file**
`config/business.php` (no migration, nothing to migrate away from later).

**document_counters** — `id, series(order|invoice|payment|refund|attempt|return), fy(e.g. 26-27),
prefix, last_number, updated_at`, `uniqueIndex(['series','fy'])`. Numbers are taken inside the
same transaction with `SELECT … FOR UPDATE` (MySQL), so there are no duplicates or gaps.
FY = April–March, **Indian time (IST)**.

## Return / replacement workflow

```
submitted ──admin approve──► approved ──► received ──► resolved
     └─────admin reject────► rejected
```

No cancellation after submit; admin acceptance required. Partial quantities supported (return 3
of 10 boxes without affecting the other 7). Eligibility is read from the **order_item snapshot**
(`return_allowed`/`replacement_allowed` + days), not the live product policy. On **received**:
refund → manual refund recorded in `payment_refunds` (with `return_request_id`); replacement → dispatch replacement (its own stock-out) +
`restockFromReturn` for the returned units.

## Code surface (`app/`)

- Controllers: `Admin/OrderController`, `Admin/OrderPaymentController`,
  `Admin/OrderDocumentController` (invoice/slip/receipt print), `Admin/OrderDeliveryController`
  (dispatch, OTP), `Admin/ReturnController`, `Admin/ProductReturnPolicyController`,
  `InvoiceController@public` (the `/invoice/{token}` route).
- Services (`app/Services/Order/`): `OrderService` (create+snapshots+totals+transitions+history),
  `PaymentService` (record/refund + derive status), `DocumentService` (gapless numbering,
  public token, build invoice/slip/receipt data), `ReturnService` (submit/approve/receive/
  resolve + restock/refund). Extend `InventoryService` (`consumeForOrder`/`restockFromReturn`).
- Helpers: `amountInWords()` (Indian numbering), `business()` config getter, `DocumentNumber::next(series)`.
- Models: `Order, OrderItem, OrderPayment, OrderInvoice, OrderDelivery, ProductReturnPolicy,
  ReturnRequest, ReturnRequestItem, Setting`.

## Views & design

Under `resources/views/admin/orders/` + `returns/` + print views; reuse `partials/header|
footer`. **One** Orders list with status-filter chips + KPI cards (All / Pending / Processing /
Packed / Dispatched / Delivered), dual badges (order + payment status), `msFilterTable` /
`msExportTable`, three-dot menus. Rich **order detail**: customer + addresses, items, totals +
payment history, status timeline, packing/dispatch, document downloads, OTP verify, linked
returns — with status updates restricted to permitted transitions. Sidebar: **Orders**
`<details>` group (Orders / Payments / Returns & Replacements / Return Policies). New `.ord-*` /
`.ret-*` classes in `resources/css/app.css` → rebuild with `bin/build-css.sh`. Print documents
carry their **own scoped print CSS** (DM Sans/Playfair, monochrome, A4) — not the admin app.css.

## Build phases

1. Migrations + models + `config/business.php` + `InventoryService` consume/restock + `DocumentService`
   numbering.
2. Orders: list (filters/KPIs) + create (snapshots, optional tax, non-blocking stock warning) +
   detail + transitions + history + `CustomerStats` wiring.
3. Payments: multi-payment, derived status, outstanding, printable receipt.
4. Documents: packing slip (packed) + GST invoice (dispatch, deduct stock) + public invoice URL
   + vendored QR + gapless numbering; OTP delivery.
   - **4b (optional):** stored PDFs (mPDF) + Code128 barcode on the packing slip.
5. Return policies + returns/replacements (admin-accepted, no cancel, partial qty, restock/
   refund).

## Dependencies / confirm later

- **Customers module** (`docs/customers.md`) should exist first — orders need `customers` +
  `customer_addresses` and the `CustomerStats` stub.
- Owner will plan **two separate settings areas** later: **System settings** (logo, theme,
  channels, business identity, references…) and **Payment settings** (receiving accounts, tax
  details, tax rules per product/all products, payment decisions like the 20% advance). They
  replace `config/business.php`. Business name/GSTIN for documents → System settings;
  tax rate/rules → Payment settings.
- **Seller GST registration + GSTIN** decides "Tax Invoice" vs "Bill of Supply" (see compliance
  flag). Confirm with the accountant.
