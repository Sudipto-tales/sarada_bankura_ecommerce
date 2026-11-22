# Customers, Segments & Pincode auto-fill

**Status: planned (not yet built).** This is the agreed design for the customer-relationship
module (online + offline) plus automatic address fill from an Indian pincode. It follows the
same Vayu + Veylix conventions as the catalog and inventory modules.

| Page | Route |
| --- | --- |
| Customers list | `/admin/customers` |
| Create customer | `/admin/customers/create` |
| Customer detail | `/admin/customers/{id}` |
| Edit customer | `/admin/customers/{id}/edit` |
| Customer segments | `/admin/customers/segments` |
| Create segment | `/admin/customers/segments/create` |
| Segment detail | `/admin/customers/segments/{id}` |
| Customer tags | `/admin/customers/tags` |
| Pincode lookup (JSON, internal) | `/admin/lookup/pincode/{code}` |

Mutations keep the project convention: `POST /…`, `POST /…/{id}/update`, `POST /…/{id}/delete`,
route names `admin.customers.*`, `admin.customers.segments.*`, `admin.customers.tags.*`,
`admin.lookup.*`. Customer routes are registered **before** any `{id}` wildcard that could
shadow them (as `/products/import` is).

## Confirmed decisions

- **Online login reuses the existing `users` table**, extended with new columns — not a second
  accounts table. The password column stays `password` (not `password_hash`).
- **Customer tags are their own vocabulary** (`customer_tags` + `customer_tag_map`), separate
  from the product `tags` table, so VIP/Builder/Dealer never mix with product tags.
- **Phone is the primary identity.** Email is secondary (shown under the name like Veylix).
- Order-derived stats (`total_orders`, `total_spent`, `last_order_at`, AOV, "Last order")
  ship **dormant** (0 / "—") and are auto-updated once an Orders module exists.
- Per Vayu limits: every ENUM → `string()->default()`, every FK → indexed `bigInteger`
  (enforced in app code, no DB constraint).

## Online ↔ offline linking (phone = identity)

| Situation | Action |
| --- | --- |
| Offline customer exists + same phone registers online | Link existing customer: `is_online = true`, set `user_id` |
| New online registration, no matching phone | Create a `users` row + a new `customers` row |
| Admin creates a customer whose phone already has an online account | Link them |

## Schema

One migration, `2026_11_24_000010_create_customer_tables.php` (sequence in `docs/roadmap.md`).

- **`users` (ALTER)** — add `phone` (unique, nullable), `status` (default `active`),
  `email_verified_at`, `phone_verified_at`, `last_login_at`.
- **`customers`** — `name`, `phone` (unique), `email`, `gst_number`, `customer_type`
  (individual/builder/dealer/architect/other), `is_online`, `user_id`, `status`,
  `total_orders`, `total_spent`, `last_order_at`, `notes`, `merged_into_id`, timestamps.
  **Added for the Veylix design:** `marketing_consent` (bool — the "Subscribed" column),
  `lifecycle` (string default `new` — New/Returning/VIP badge).
- **`customer_addresses`** — type (billing/shipping/both), `is_default`, name, phone,
  `address_line1/2`, `city`, `state`, `pincode`, `country` (default India), **plus `area` /
  `post_office`** from the pincode lookup.
- **`customer_tags`** (`name`, `color`, `created_at`) + **`customer_tag_map`**
  (`customer_id`, `tag_id`).
- **`customer_segments`** — name, description, `type` (dynamic/static),
  `match_type` (all/any = AND/OR), `is_active`, `created_by`, timestamps.
- **`customer_segment_conditions`** — `segment_id`, `field`, `operator`, `value`, `sort_order`.
- **`customer_segment_members`** — `segment_id`, `customer_id`, `matched_at` (perf cache).
- **`pincodes`** — lookup cache: `pincode` (unique), `state`, `district`, `areas` (JSON),
  `cached_at`.

Seeders: `CustomerSegmentSeeder` (6 defaults below); optional `CustomerTagSeeder`
(VIP / Builder / Dealer / Wholesale / Architect).

### Default segments

| Segment | Rules |
| --- | --- |
| VIP Customers | `total_spent ≥ 200000` AND `total_orders ≥ 3` |
| Builders & Contractors | `customer_type = builder` |
| Dealers | `customer_type = dealer` |
| Inactive Customers | `last_order_at` older than 90 days |
| New Customers | `total_orders ≤ 1` |
| Online Customers | `is_online = true` |

## Code surface (`app/`)

- `Admin/CustomerController` — index/create/store/show/edit/update/destroy; list filters
  `q / type / status / segment / tag`; phone-match linking on store/update.
- `Admin/CustomerSegmentController` — CRUD + `preview` (POST conditions → live match count).
- `Admin/CustomerTagController` — follows the existing `TagController` pattern.
- `Admin/LookupController::pincode($code)` — the pincode proxy (below).
- `Services/Customer/SegmentMatcher` — compiles conditions → SQL; shared by segment preview,
  the customers-list segment filter, and member refresh. (The one non-trivial piece; isolated
  like `InventoryService`.)
- `Services/Customer/CustomerStats` — stubbed now with the methods the Orders module will call
  to keep `total_orders / total_spent / last_order_at / lifecycle` current.

Models: `Customer`, `CustomerAddress`, `CustomerTag`, `CustomerSegment`,
`CustomerSegmentCondition` (+ `fillable`). Extend `User` `fillable` with the new columns.

## Views & design

Under `resources/views/admin/customers/`, reusing `partials/header|footer`:
`index`, `create` (shared create/edit), `show`, `segments/index`, `segments/create`
(shared create/edit), `segments/show`, `tags/index`.

- Create/Edit is two-column: **left** Basic info / Online account / Tags / Notes; **right**
  Addresses (with pincode auto-fill) + a Stats card shown only on edit.
- Segment builder groups the condition **field** dropdown into the four Veylix categories:
  **Behavior** (orders, spend, AOV), **Recency** (last order before date),
  **Profile** (city, country, lifecycle), **Consent** (marketing subscription). Each condition
  row = field + operator + value; a live "X customers match these rules" preview; match
  type All (AND) / Any (OR).
- New `.cust-*` / `.seg-*` component classes go in `resources/css/app.css`, then rebuild with
  `bin/build-css.sh` — never hand-edit the compiled `public/assets/css/app.css`.
- Sidebar gets a **Customers** `<details>` group (Customers / Segments / Tags) in `header.php`.
- Reuse the shared `msFilterTable` / `msExportTable` helpers, KPI cards, tinted rows,
  status/segment badges and three-dot action menus to match the reference screenshots.

## Pincode auto-fill

The browser never calls the third-party API directly — it calls our proxy:

```
GET /admin/lookup/pincode/{code}  → LookupController::pincode()
  1. validate 6-digit code
  2. hit the local `pincodes` cache table → return on hit
  3. miss → curl https://api.dharun.dev/v1/pincode/{code} (2s --max-time)
           → normalize, insert into cache, return
  4. API down / timeout → return { ok: false } so the form falls back to manual entry
```

Rationale:

- **Resilience** — the API has gone down (Cloudflare error 1033) during planning. The cache
  makes repeat pincodes instant and keeps the form usable during outages.
- **Privacy** — no customer data or referrer leaks to a third party; swapping to a keyed
  provider later (APIVerve, API Ninjas) is a one-file change.
- An Indian pincode maps to **one district + state** but **many post offices (areas)** — there
  is no single "city". So the UI auto-fills **State + District** and offers an **area / post
  office `<select>`** to choose the locality.

Frontend: on the 6th digit / `blur` of the pincode field, `fetch` the proxy, fill State and
District (editable), populate the area select; show a spinner and a "couldn't look up — enter
manually" fallback. Plain vanilla JS in the `footer.php` style, no new dependencies.

## Build phases

1. Migration + models + seeders + `users` extension + pincode proxy/cache/JS
   (self-contained, testable before any customer exists).
2. Customers list (KPIs, filters, table, badges) + Create/Edit/Detail + phone linking.
3. Segments: list, builder with live preview, detail, default segments, `SegmentMatcher`.
4. Tags management + wire segment/tag filters into the list + member refresh.
5. *(Deferred)* stat auto-update hooks fire when the Orders module is built.

## Open dependency

The Veylix mockups show Orders / AOV / Last-order columns and order numbers — all depend on an
**Orders module that does not exist yet**. Until then those cells show `0` / "—". The
`CustomerStats` service is stubbed so wiring Orders in later is a small change, not a refactor.
