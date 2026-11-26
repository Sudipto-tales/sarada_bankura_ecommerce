# Customers, segments & pincode auto-fill

**Built (Stage 3, 2026-11-24).** One customer list for showroom and online buyers, with
addresses, tags, rule-based segments and Indian pincode auto-fill.

| Page | Route |
| --- | --- |
| Customers (segment tabs, filters, CSV export) | `/admin/customers` |
| Create / edit (shared view, "Save & add another") | `/admin/customers/create`, `/admin/customers/{id}/edit` |
| Detail (call / WhatsApp, order stats, addresses, reviews, segments, online account; no order list yet) | `/admin/customers/{id}` |
| Segments: list / builder with live preview / detail | `/admin/customers/segments[/create|/{id}]` |
| Customer tags | `/admin/customers/tags` |
| Pincode lookup (JSON, internal) | `/admin/lookup/pincode/{code}` |

## Rules

- **Phone is the identity.** `Phone::normalize()` strips `+91` / leading 0 and requires a 10-digit
  mobile starting 6–9. A duplicate phone is a validation error that names the existing customer.
- **Online accounts reuse the `users` table** (extended with phone, status, verified and last-login
  columns). On every save, a `users` row with the same phone links the customer (`is_online`,
  `user_id`). Storefront registration calls `CustomerService::linkUser()`: a showroom customer who
  signs up online sees their past orders straight away.
- **Customer tags are separate** from product tags (`customer_tags` + `customer_tag_map`).
- `customers.source` (admin | showroom | online | import) is set once and never changed by an edit.
- Reviews with the same `customer_phone` are linked on save. Deleting a customer keeps their
  reviews (unlinked). A customer with orders can't be deleted.

## Data (migration 000010)

`customers` (name, phone, email, company, gst_number, customer_type
individual|builder|dealer|architect|other, status active|inactive|blocked, is_online, user_id,
marketing_consent, lifecycle, source, order stats, notes, merged_into_id) ·
`customer_addresses` (type billing|shipping|both, label, default flag, lines, area / post office,
city, district, state, pincode, country) · `customer_tags` + `customer_tag_map` ·
`customer_segments` (dynamic|static, match all|any, member_count, refreshed_at) +
`customer_segment_conditions` + `customer_segment_members` (cache) · `pincodes` (lookup cache).

## Code

- `Services/Customer/CustomerService` — single writer: validate, then save customer, addresses
  (upserted by id), tags, online link and review links in one transaction. Also
  `saveOwnAddress / makeDefaultAddress / deleteOwnAddress` for the storefront account page.
- `Services/Customer/CustomerStats` — order stats. `total_orders` / `total_spent` count
  **delivered** orders; `last_order_at` = latest non-rejected order. (Returns are not subtracted
  from `total_spent`.)
- `Services/Customer/SegmentMatcher` — compiles segment rules to SQL.
- `Services/Customer/PincodeLookup` — pincode proxy.
- Seeder `CustomerSeeder`: 5 tags (VIP, Builder, Dealer, Wholesale, Architect) + 6 default segments.

## Segments

- Whitelisted fields only, grouped as **Behavior** (orders, spend, AOV), **Recency**, **Profile**
  (type, status, joined via, city, district, state, pincode, country, has email, lifecycle) and
  **Consent**. Operators depend on the field type (number / text / select / bool / date / tag).
- Address fields match **any** of the customer's addresses. Dates are computed in PHP (IST) and
  bound; numbers compare via `CAST(? AS DECIMAL(14,2))` so SQLite and MySQL agree.
- A dynamic segment with no rules matches nobody.
- `customer_segment_members` is rebuilt on segment save / Refresh and per customer on every
  customer save. The list filter and the segment page use the **live rules**, so they always agree
  with the builder preview.
- Static segments: members are picked from a searchable checklist. Switching to dynamic clears them.
- A tag used in a segment rule can't be deleted until the rule is removed.

| Default segment | Rule |
| --- | --- |
| VIP Customers | spent ≥ ₹2,00,000 and orders ≥ 3 |
| Builders & Contractors / Dealers | customer type |
| Inactive Customers | last order older than 90 days |
| New Customers | orders ≤ 1 |
| Online Customers | `is_online = true` |

## Pincode auto-fill

```
browser ─► GET /admin/lookup/pincode/{code}
           1. 6-digit check
           2. `pincodes` cache hit → return
           3. miss → api.dharun.dev/v1/pincode/{code} (3 s timeout) → cache 180 days → return
           4. API down → stale cache if any, else { ok: false } → manual entry
```

A pincode maps to one district + state but many post offices, so the form fills **State +
District** and offers the post offices in a datalist (staff can also type freely). Misses are not
cached. On edit, a lookup only refreshes the list and never overwrites saved fields. The storefront
uses the same service through `/checkout/pincode/{code}`.
