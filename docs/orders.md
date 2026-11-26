# Orders, documents & returns

**Built:** orders + documents (Stage 4, 2026-11-24), returns & replacements (Stage 6,
2026-11-24). Payments are in [payments.md](payments.md). Order status, payment status, documents,
delivery and returns are tracked **independently** of each other.

| Page | Route |
| --- | --- |
| Orders (status + payment chips, bulk move) | `/admin/orders` |
| Create order (admin) | `/admin/orders/create` |
| Order detail | `/admin/orders/{id}` |
| Packing slip / GST invoice (print) | `/admin/orders/{id}/packing-slip`, `/admin/orders/{id}/invoice` |
| Returns & replacements | `/admin/returns`, `/admin/returns/{id}` |
| Return policies | `/admin/return-policies` |
| Public order page (no login, noindex) | `/order/{token}` |
| Public invoice (QR target, no login, noindex) | `/invoice/{token}` |

## Owner rules

- **Orders never check or reserve stock.** Accepting shows a non-blocking stock warning.
- **No cancellation after accept.** An order can be rejected only while `pending`.
- **Delivery is free** (included in the price): no shipping line anywhere.
- **GST is added on top:** total = subtotal − order discount + GST. One optional rate per order,
  no per-line tax, no customer GSTIN collected.
- **GST is refused while `BUSINESS_GSTIN` is empty** (an unregistered seller issues a Bill of
  Supply and may not charge GST). Title = "Tax Invoice" only with GSTIN + GST, else
  "Invoice / Bill of Supply". CGST + SGST when place of supply = the seller's state, else IGST.
- **Dispatch is allowed with money still due** — the dialog only shows the amount due.
- **Returns can't be cancelled** after they are raised, and every request needs admin approval.

## Workflow

```
pending ──accept──► accepted ──► processing ──► packed ──► dispatched ──► delivered
   └──reject──► rejected                                  (invoice,       (OTP)
                                                           stock out)
```

- `OrderService::transition()` enforces this whitelist with a guarded
  `UPDATE … WHERE order_status = ?` and writes `order_status_history`. Payment never changes order
  status and a status step never changes payment.
- **One next-step button** on the list row and the detail page. Dialogs only when data is needed:
  accept (optional note + stock warning), reject (customer-facing note, required), dispatch
  (courier / tracking / link, shows money due), delivered (OTP, or manual with a written reason,
  logged). **Bulk:** accept / start processing / mark packed for orders in the same status.
- **Dispatch** issues the invoice, takes tracked stock out via `InventoryService::consumeForOrder()`
  (may go negative; untracked products are skipped; a unit that can't be converted is skipped
  with a warning + `order.stock_issue` notification, never a block) and creates the delivery OTP.
- **Delivery OTP:** 6 digits, HMAC hash stored, 7-day expiry, 5 tries. Shown **once** to the admin
  with a WhatsApp send link (or sent by WhatsApp/email when the API is active — see whatsapp.md).
  "New OTP" replaces it. The public order page never shows it.
- **Create order (admin):** existing customer (search) or new (a known mobile reuses that
  customer); delivery address saved / new (with pincode lookup) / "customer collects" (place of
  supply = seller state); lines with variant, qty, price (catalogue default, editable), line
  discount; order discount; GST toggle. Channel = offline + detail (showroom / walk-in / phone /
  WhatsApp). Storefront orders are `online / website` (see storefront.md).
- Addresses are **snapshotted** as JSON on the order; later edits to the customer don't change it.
- Orders can't be edited after creation: reject while pending and create a new one.

## Numbering

`DocumentNumber::next(series)` on `document_counters`: FY April–March (IST), `SELECT … FOR UPDATE`
on MySQL, called inside the document's own transaction, so numbers are gapless. Prefixes in
`config/business.php` → `numbering`.

| Series | Example |
| --- | --- |
| order | `MSO/26-27/0001` |
| invoice | `MSM/26-27/0001` |
| return | `RET/26-27/0001` |
| receipt / refund / pay attempt | `RCP/…`, `RFD/…`, `PAT/…` (payments.md) |
| enquiry | `ENQ/…` (storefront.md) |

## Documents

Printable A4 HTML (browser "Save as PDF"), scoped CSS `public/assets/css/documents.css`, QR from
the vendored `public/assets/vendor/qrcode.min.js` pointing at `/invoice/{token}`.

- **GST invoice** (at dispatch): monochrome DM Sans + Playfair layout, seller details from
  `BUSINESS_*`, single GST line (split for display), amount in words (Indian numbering, `Money`).
- **Packing slip** (at packed): ship-to, order number, invoice QR, items with qty + unit and
  **no prices**.
- **Payment receipt:** per received payment, `/admin/payments/{id}/receipt`.
- **Public pages:** `/order/{token}` (status, items, paid / due, rejection note, returns, Pay
  button) and `/invoice/{token}` (printable invoice + floating Pay button while money is due).
  `/order` uses the storefront layout; `/invoice` keeps the document style.

## Stock

Only `InventoryService` writes stock. Orders use `consumeForOrder()` (type `sale`, source `order`,
reference = order number) at dispatch and `restockFromReturn()` (type `return_in`) when a return is
received. No other state touches stock.

## Returns & replacements

```
submitted ──approve──► approved ──► received ──► resolved
     └─────decline───► rejected
```

- `ReturnService` is the only writer of `product_return_policies`, `return_requests` and
  `return_request_items`. Every step is a guarded update; there is no cancel and no second decision.
- **Raised by the admin** on the customer's behalf from a delivered order's **Return / replace**
  dialog (type, lines + qty, reason). Customers ask by phone / WhatsApp; `/order/{token}` shows
  each request read-only.
- **Eligibility** comes from the **order-item snapshot** (`return_allowed` / `replacement_allowed`
  + days, counted from delivery, IST; **0 days = no limit**). Qty ≤ delivered − already claimed;
  declined requests free their qty. The admin may **accept outside policy** with a written reason
  (needed for orders placed before a policy existed).
- **Approve** per line (partial allowed). **Decline** needs a customer-facing note.
- **Received:** per-line qty + "put back in stock" tick (untick for broken stone) →
  `restockFromReturn`.
- **Resolve a return:** the admin credits the goods (default = what the customer paid for those
  units incl. their share of discount and GST; can be lowered for damage). `PaymentService::syncOrder()`
  lowers what the order owes by the credits, so an unpaid order owes less and a paid one shows
  **Refund ₹X due**. The money sent back is recorded with **Record refund** (`Ledger::refund`
  with `return_request_id`).
- **Resolve a replacement:** "Dispatch replacement" takes the same product/variant out of stock
  (`consumeForOrder`, reference = return number). No money changes.
- Return events go into `order_status_history` (note prefixed with the return number).
- Notifications: `return.requested` (cleared once decided) and `return.approved`.
- **Return policies page:** per product, bulk "set policy for selected", pause without losing the
  settings. The table supports `variant_id` but the page edits product level only.

## Not built

Dashboard order cards and the Online vs Offline card, order list on the customer page, editing an
order, customer self-service returns, variant-level policies in the UI, a different product as a
replacement, return pickup / courier labels, stored PDFs and barcodes.
