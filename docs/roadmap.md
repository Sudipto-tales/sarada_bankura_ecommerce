# Roadmap: Execution Sequence & Admin Panel Design

**Status: master plan (2026-11-22).** This ties the module plans together in build order:
`docs/customers.md`, `docs/orders.md`, `docs/payments.md`, `docs/inventory.md` (built). Each stage
is finished and verified before the next one starts. Production DB is **MySQL**. All time is
**Indian time only (IST, Asia/Kolkata)**, including FY April–March, the 6-minute window, cooldowns,
"inactive 90 days" and reports.

## Out of scope (owner will plan later)

Storefront (catalogue, cart, checkout pages), admin login, System settings, Payment settings (tax
rules for all/specific products), payment gateways (Razorpay/Stripe), real SMS APIs.
**WhatsApp is now planned** (Meta Cloud API, dormant until `.env` credentials are filled), see
`docs/whatsapp.md` and Stage 7.
**Variants and the attribute library stay separate on purpose.** They are different things
logically (attributes describe a product, variants are sellable options). Merging them would
create conflicts.

## What exists today (checked against migrations & models)

| Migration | Tables | Model classes |
| --- | --- | --- |
| 000001 / 000002 | users, api_tokens | User |
| 000003 | products, product_types, product_type_attributes, attributes, product_attribute_values, brands, product_categories, product_category_map, tags, product_tags, product_media, product_pricing | Product, ProductType, Attribute, Brand, ProductCategory, Tag |
| 000004 | units, option_groups, option_values, product_variants, product_variant_values (+ category & pricing columns) | — (DB::table) |
| 000005 | attribute_values, product_reviews (+ attribute/brand/tag columns) | — |
| 000006 / 000007 | inventory_settings, inventory_stock, inventory_movements, inventory_alerts, notifications | InventoryStock, InventoryMovement, InventoryAlert, Notification |

Findings to fix along the way:

- **Stale model `$fillable`:** `Brand` (only name/slug, but the table has country, tier, logo,
  status…), `Attribute` (missing display/used_for/status/description/sort) and `Tag` (missing
  purpose/status/description). Today the controllers write via `DB::table`, so nothing breaks yet,
  but any future `::create()` would silently drop columns. Fix in Stage 0.
- **`product_media`** has no alt text, no timestamps and no variant link, and brand logos are a bare
  path column. Stage 1 replaces both with a central media library.
- **`product_reviews`** has no customer/order link, IP, or verified-purchase flag. That's needed
  for the public form (Stage 2).
- **`database/migrations/UsersTable.php`** is legacy scaffolding (a raw-SQL `users_tbl` class). The
  migrator ignores it (it isn't a `Migration`), but it's confusing. Delete it in Stage 0.
- **`DashboardController`** uses SQLite-only SQL (`datetime('now',…)`, `julianday`). It will fail
  on MySQL. Compute the date boundaries in PHP (IST) and pass them as parameters, which works on both.

## Execution sequence

```
Stage 0  Foundation fixes            (small, before anything else)
Stage 1  Media library + product gallery ← catalogue complete
Stage 2  Public review form          ← first public write path (rate limit, spam guard)
Stage 3  Customers                   ← orders need customers + addresses
Stage 4  Orders (+ documents)        ← payments need orders
Stage 5  Payments                    ← refunds need payments
Stage 6  Returns & replacements      ← needs orders + payments (refund) + inventory (restock)
Stage 7  WhatsApp channel (Meta)     ← hooks into orders/payments/returns/admin OTP; dormant until .env
```

**Stage 7 note.** WhatsApp phase W1 (service + fallback + log + status page) can ship earlier,
because while dormant it behaves exactly like today's `wa.me` links. W3 hooks are added to each
module once that module exists. Migration `…_000013_create_whatsapp_tables.php`. Full plan in
`docs/whatsapp.md`.

**Why this order.** Each stage only *reads* what earlier stages built and never waits on a later
one. Returns used to sit inside the orders plan, but their refunds go through `payment_refunds`,
so returns UI is built **after** payments. Their tables are still created with the orders migration,
so nothing needs to be re-migrated.

### Stage 0: Foundation fixes (no migration, no new features)

Goal: what's already built runs correctly on **production MySQL**, and the code matches the schema.

| # | Change | Why | Files |
| --- | --- | --- | --- |
| 0.1 | Replace SQLite-only date SQL with dates computed in PHP (IST) and passed as `?` params | `datetime('now',…)` / `julianday()` don't exist in MySQL, so the dashboard would crash | `DashboardController.php:34-49` |
| 0.2 | "Products added per day" chart: group by `DATE(created_at)` (works in both) and fill missing days in PHP | removes the `julianday` maths | same |
| 0.3 | Sync model `$fillable` with the real columns | Brand/Attribute/Tag list only their first columns, so a future `::create()` would silently drop country/tier/status/display… | `app/Models/{Brand,Attribute,Tag,Product,ProductCategory}.php` |
| 0.4 | Delete legacy `database/migrations/UsersTable.php` | old raw-SQL `users_tbl` scaffolding, ignored by the migrator but misleading | — |
| 0.5 | **Empty string → NULL** guard on every numeric/date write (product pricing, variants, inventory, import) | MySQL strict mode rejects `''` in DECIMAL/INT/DATE columns (SQLite accepted it), so saves would fail in production | ProductController, VariantController, InventoryController, ProductImporter |
| 0.6 | **Case-insensitive uniqueness check:** SKU, slug and unit symbol compared lower-cased in validation and import | MySQL's default collation treats `MSM-a` = `msm-A` as duplicates while SQLite doesn't, so imports that pass locally would fail on prod with a raw DB error | StoreProductRequest, update validator, ProductImporter, CatalogController |
| 0.7 | `config/business.php` + `business()` helper (name, address, phone, email, GSTIN, logo path, tax defaults) | placeholder until System/Payment settings are planned (no throwaway table) | `config/`, `core/Support/helpers.php` or `app/Support/` |
| 0.8 | `.env.example` documents MySQL + MAIL_* keys; README "production checklist": php.ini upload limits, cron line for `inventory:check`, `APP_TIMEZONE=Asia/Kolkata`, MySQL `time_zone='+05:30'` on connect | production setup is currently undocumented | `.env.example`, `docs/usage.md` |
| 0.9 | Set MySQL session timezone to IST in `ConnectionManager::createMysql` (`SET time_zone = '+05:30'`) | so `created_at` defaults and PHP times agree (IST-only decision) | `core/Database/ConnectionManager.php` |
| 0.10 | **MySQL verification run:** fresh MySQL DB → `php vayu migrate` → all seeders → `tests/run.php` → HTTP smoke test of every admin page + create/edit/delete product + import + stock receive | proves 0.1–0.9 and that every migration compiles for MySQL | — |
| 0.11 | Commit the current uncommitted work first, on its own branch | 8 modified files + docs are sitting on `main` | git |

Done when: every admin page loads on MySQL, the tests pass, and the dev DB is back to seed data.

**Status: done (2026-11-22).** Verified on MariaDB 10.11 (strict mode) in a throwaway `maa_sarada_test` DB:
7 migrations + 4 seeders, 291 test assertions, all 17 admin GET pages, create/edit/delete product,
case-insensitive duplicate SKU, CSV import (blank cells → NULL, duplicate-case row flagged), stock
receive. Connection runs `+05:30` / utf8mb4. Extra fixes found during the run: the fallback `.env`
loader is now immutable (real env vars win, like phpdotenv), and the validator treats whitespace-only
input as empty.

### Stage 1: Media library + product images & gallery

**One central media system** instead of per-module file columns. Every uploaded file gets one
record with its own **media number**, and anything that uses it points to it by reference.

**Migration `2026_11_23_000008_create_media_tables.php`:**

- **`media`** (one row per physical file): `id, media_no(unique, MED-000123), disk(public|private),
  path, thumb_path, original_name, mime, kind(image|video|document), extension, size_bytes, width,
  height, checksum(sha1, index → duplicate detection), alt_text, title, uploaded_by, created_at,
  updated_at`.
- **`media_links`** (who uses it): `id, media_id(index), owner_type(product|variant|brand|category|
  review|payment_attempt|order|…), owner_id, role(cover|gallery|video|logo|photo|proof|document),
  sort, created_at`. `uniqueIndex(['owner_type','owner_id','media_id','role'])`, index
  `(owner_type, owner_id)`.
- **Data move:** copy existing `product_media` rows into media + media_links (role cover/gallery/
  video), and `brands.logo` into a `logo` link. `product_media` is dropped in a **later** migration,
  once all code reads from the new tables (two-step, so nothing breaks in between).

Rules:

- `MediaService` is the **single writer** (upload → validate type/size → resize + thumbnail via GD →
  checksum → row). Owners call `attach(owner, media, role)` / `detach()`. Deleting an owner removes
  its **links**; the file is deleted only when **no links remain** (one image can be reused, e.g.
  the same slab photo on two products, without being stored twice).
- `disk = private` files (payment screenshots, later documents) live in `storage/media/`, never
  under `public/`, and are served through an admin-only controller route.
- Code that reads `product_media` today (ProductController, VariantController, InventoryController,
  DashboardController, ProductImporter ZIP/URL images) moves to `MediaService` lookups.
- Payments (`payment_attempts.proof_path`) and reviews (photo) use media links instead of their own
  path columns, so those plans just reference `media_id`.

Screens:

- **Media library** `/admin/media`: grid/list of all files, search by media no./name, filter by
  kind, owner type and "unused". Detail drawer shows the preview, alt text, size, and **"Used in"**
  (each link → its product/brand/…). Bulk delete is allowed for unused files only.
- **Product detail page** `/admin/products/{id}`: gallery (cover badge, drag reorder, set cover, alt
  text, assign to variant, remove link, "pick from library"), info, pricing, variants, attributes,
  stock card, reviews summary. Edit stays on the wizard.
- **One uploader component** (shared by gallery, wizard step 2, brand logo, library). It sends one
  file per request (Vayu parses single files only) with a progress bar per file, and supports
  drag & drop of many files.

### Stage 2: Public review form
**Migration `2026_11_23_000009_extend_product_reviews.php`:** add `customer_id(nullable)`,
`order_id(nullable)`, `is_verified_purchase(false)`, `ip`, `user_agent`.

- Standalone public page `/review/{product-slug}` (works now and gets embedded in the storefront
  later): name, email, city, application, star rating, title, text, optional photo (single input).
- New reviews land as `pending` in the existing `/admin/reviews` queue, with a notification
  `review.submitted`.
- Spam guard: honeypot field + per-IP rate limit (core `RateLimiting`) + CSRF. No captcha service.
- `is_verified_purchase` gets set automatically later, once orders exist (matching customer phone
  or email to a delivered order item).

### Stage 3: Customers (`docs/customers.md`)
**Migration `2026_11_24_000010_create_customer_tables.php`:** ALTER `users` (phone, status,
verified/last-login), `customers`, `customer_addresses`, `customer_tags`, `customer_tag_map`,
`customer_segments`, `customer_segment_conditions`, `customer_segment_members`, `pincodes`.
**Models:** Customer, CustomerAddress, CustomerTag, CustomerSegment, CustomerSegmentCondition.
Phases 1–4 from the doc. `CustomerStats` is stubbed, and Stage 4 switches it on.

### Stage 4: Orders + documents (`docs/orders.md`)
**Migration `2026_11_24_000011_create_order_tables.php`:** orders (with the payment cache
columns from payments.md), order_items, order_status_history, order_invoices, order_deliveries,
**document_counters**, plus the return tables product_return_policies, return_requests and
return_request_items (created now, UI in Stage 6).
**Models:** Order, OrderItem, OrderStatusHistory, OrderInvoice, OrderDelivery, DocumentCounter.

- Order list + one-click next step + bulk move. Create order (admin). Detail with timeline.
- `InventoryService::consumeForOrder` at dispatch. The new movement types `sale` / `return_in` are
  just new string constants, so no migration is needed.
- Documents: packing slip, GST invoice, public `/invoice/{token}`, public `/order/{token}`,
  delivery OTP. The floating Pay button is **placed but hidden** until Stage 5 turns it on.
- `CustomerStats` goes live (delivered → totals).

### Stage 5: Payments (`docs/payments.md`)
**Migration `2026_11_25_000012_create_payment_tables.php`:** payment_accounts,
payment_attempts, payments, payment_refunds, payment_request_logs.
**Models:** PaymentAccount, PaymentAttempt, Payment, PaymentRefund, PaymentRequestLog.

Order of work: receiving accounts (OTP-gated) → record payment at shop → `PaymentService::syncOrder`
→ payment window + `/pay/{token}` → verification queue + verify window → payment requests
(email / WhatsApp / SMS, 30 s cooldown) → turn on the floating Pay on invoice/order page → refunds →
cron marks expired attempts → payments overview.

### Stage 6: Returns & replacements
Return policies page, return requests (admin accept, partial qty, no cancel), received →
`restockFromReturn`, refund → `payment_refunds` (manual), replacement → own dispatch stock-out.
Notifications `return.requested` / `return.approved`.

## Sensitive changes: ask twice

Confidential data can't be changed in one click. Applies to: **receiving account details**
(create / edit / delete / change default UPI), **Payment settings**, **System settings** and
**provider changes** (future), plus anything else marked sensitive later.

```
Open page ──► OTP #1 (view)                      already planned for receiving accounts
Save change ─► Step 1: review dialog — "You are changing: UPI ID  maasa••@sbi → newsh••@hdfc"
               (old → new, masked) + [Confirm]
            ─► Step 2: OTP #2 (123456 for now) → change is saved
```

- Two separate confirmations: a **review of exactly what changes**, then the **OTP again**. A
  wrong OTP cancels the save and nothing is written. Deleting needs typing the account name as
  well.
- One reusable component `partials/sensitive-confirm.php` + JS, so every sensitive form gets the
  same behaviour.
- **Audit log** of every sensitive change. **Migration** (with Stage 5)
  `audit_logs`: `id, entity_type, entity_id, action(create|update|delete|set_default), changes(json,
  values masked: last 4 only), actor_id(nullable), ip, user_agent, created_at`. Shown as "Change
  history" on the receiving accounts page.
- Honest limit (same as OTP #1): the OTP is a front-end check until a server-side OTP exists. The
  two-step flow stops **accidental** changes now. Real protection comes with the server OTP and
  admin login later. The component is built so the check moves to the server without UI changes.

## Single-writer rules (keeps data logically correct)

| Data | Only this may write it |
| --- | --- |
| `inventory_stock.quantity` + movements | `InventoryService` |
| `orders.order_status` + `order_status_history` | `OrderService::transition()` (whitelist) |
| `orders.paid_amount / due_amount / payment_status / pending_verification_amount / refund_due` | `PaymentService::syncOrder()` |
| `customers.total_orders / total_spent / last_order_at / lifecycle` | `CustomerStats` |
| Every number series (order, invoice, payment, refund, attempt, return) | `DocumentNumber::next()` on `document_counters` |
| `notifications` | `NotificationService` |

Controllers never update these columns directly. That's what keeps order status, payment status,
stock and customer stats from drifting apart.

## Admin panel design

### Sidebar (information architecture)

```
◆ Maa Sarada                      (collapse to icons — existing)
─────────────────────────
⌂  Dashboard
▤  Catalogue ▾        Products · Add product · Bulk upload · Categories · Attributes
                      · Variants · Units · Brands · Tags · Reviews (●pending)
▦  Inventory ▾        Overview · Stock · Movements · Alerts (●)
☺  Customers ▾        All customers · Segments · Tags
🧾 Orders ▾           All orders (●pending) · Create order · Returns & replacements
                      · Return policies
₹  Payments ▾         Overview · To verify (●N) · Payment requests · Failed attempts
                      · Refunds (●due) · Receiving accounts 🔒
⚙  Settings ▾         System (future) · Payment (future) — shown greyed "coming soon"
```

The `●` badges are live counts using the same query pattern as the existing topbar chips. Groups
use the existing `<details>` pattern and gold active bar.

### Topbar
Existing search + chips, plus **Pending orders N**, **To verify N** (amber) and **Refund due N**
(red). Each chip is a filtered link, so the topbar doubles as the admin's to-do list. The bell gets
the new notification types (`order.new`, `payment.submitted`, `review.submitted`…).

### Dashboard (extends the current Veylix grid)
Row 1 (existing catalogue KPIs) + new **Today (IST)** strip: orders today · collected today ·
to verify · total due across orders. New cards: **Order pipeline** (counts per status, click →
filtered list), **Payments to verify** (latest 5 with a quick Verify), **Due by order** (top dues).
The stock card stays.

#### Online store vs Offline store (new dashboard section)

**What decides the side (set once at order creation, never changes):**

| `orders.channel` | `channel_detail` | Created by |
| --- | --- | --- |
| `online` | `website` | customer checkout on the storefront |
| `offline` | `walk_in`, `showroom`, `phone`, `whatsapp` | admin via Create order (picks the detail) |

An order's channel says **where the sale happened**. Separately, `payments.collected_via` says
**how money came in**: `window` (UPI payment window / pay link) or `shop` (recorded by admin). An
offline order paid through a WhatsApp pay link counts as an **offline sale** with a **window
collection**. Keeping the two separate stops numbers from being double-counted or mislabeled.

**Layout:** a full-width card, two equal columns, plus a period switch
`Today · 7 days · 30 days · This FY` (IST, FY = Apr–Mar):

```
┌ Store performance ──────────────────────── [Today|7d|30d|FY] ┐
│  🌐 ONLINE STORE                 │  🏬 OFFLINE STORE           │
│  Orders        12   ▲ 20%        │  Orders        31   ▼ 5%    │
│  Sales      ₹3.4L                │  Sales      ₹9.8L           │
│  Collected  ₹1.1L                │  Collected  ₹7.2L           │
│  Due        ₹2.3L                │  Due        ₹2.6L           │
│  Avg order  ₹28.3K               │  Avg order  ₹31.6K          │
│  New customers  9                │  New customers  14          │
│  Pending / to verify  3 / 2      │  Walk-in 18 · Phone 9 · WA 4│
├──────────────────────────────────┴─────────────────────────────┤
│  Sales trend: two lines (online gold, offline ink), same axis  │
│  Share bar  ▓▓▓▓▓░░░░░░░░░░  Online 26% · Offline 74%         │
└────────────────────────────────────────────────────────────────┘
```

- Rules: **Sales** = grand_total of non-rejected orders created in the period. **Collected** =
  payments received in the period (minus refunds) for that channel's orders. **Due** = current due
  on that channel's open orders. Change % compares with the previous equal period.
- Each number links to the filtered order/payment list (`?channel=online&period=30d`).
- Order list gets an **Online / Offline** filter chip and a small channel icon per row. The
  payments list gets the same chip plus **Window / Shop**.
- Before the storefront exists, the online column shows an empty state ("Online store not live
  yet") instead of zeros, so it doesn't look broken.
- Queries: one grouped query per metric (`GROUP BY channel`) with IST date bounds computed in PHP,
  backed by indexes `orders(channel, created_at)` and `payments(received_at)`. No cache table
  needed at this scale.
- Customers: the existing `customers.is_online` shows "online vs offline customers" as a small
  stat inside each column (new customers by the channel of their first order).

### Shared page patterns (one look everywhere)

| Pattern | Used for |
| --- | --- |
| KPI row (4 tiles) | top of every list page |
| Filter chips with counts | order status, payment status, review status, attempt status |
| Table + inline primary action + `⋯` menu | orders (next step + ₹ Request), payments (Verify), reviews (Publish) |
| **Modal** (short, focused form) | verify, record payment, request payment, refund, reject, dispatch, OTP |
| **Full page** (long form) | create order, create customer, product wizard, segment builder |
| Detail page = left content + right summary rail | product, customer, order, payment |
| Timeline (who · what · when IST) | order, payment, customer |
| Countdown button | request cooldown (30 s), payment window (6 min) |

### Status colour tokens (consistent across modules; reuse existing `.t-*` tones)

| Tone | Order | Payment | Other |
| --- | --- | --- | --- |
| amber | pending | partially paid, **to verify** | review pending, stock low |
| blue | accepted | — | — |
| indigo | processing | — | — |
| violet | packed | overpaid | — |
| teal | dispatched | — | — |
| green | delivered | paid | published, in stock |
| red | rejected | unpaid (due), refund due | out of stock, failed attempt |
| grey | — | refunded, void | archived, expired |

### Order detail layout (the busiest screen)

```
┌ MSM-ORD-0012 · Ramesh Das · 98•••••210 ───────────────── [Mark packed ▶] [⋯] ┐
│ ● Pending ─ ● Accepted ─ ● Processing ─ ○ Packed ─ ○ Dispatched ─ ○ Delivered │
├──────────────────────────────────────────────┬────────────────────────────────┤
│ Items (name · variant/SKU · qty · unit · ₹)  │ PAYMENT          Partially paid│
│ Totals: subtotal · discount · GST · total    │ ▓▓▓░░░░  ₹5,000 of ₹25,000     │
│                                              │ Due ₹20,000 · Verify ₹2,000 ⚠ │
│ Customer & addresses (snapshot)              │ [Verify] [Record] [₹ Request ⟳]│
│ Delivery: courier · tracking · OTP           │ Payments / attempts / refunds │
│ Documents: packing slip · invoice · receipts │ Requests log (channel·to·time)│
│ Timeline (status + payment + requests, IST)  │ Customer note (if rejected)   │
└──────────────────────────────────────────────┴────────────────────────────────┘
```

CSS: new component classes `.ord-*`, `.pay-*`, `.med-*`, `.rev-*` in `resources/css/app.css` →
`bin/build-css.sh`. Print documents and public pages (`/pay`, `/order`, `/invoice`, `/review`)
use their own scoped CSS, not the admin bundle.

## Migration map (final)

| # | File | Stage |
| --- | --- | --- |
| 000008 | `2026_11_23_000008_create_media_tables.php` (+ copy product_media & brand logos) | 1 |
| later | `…_drop_product_media.php` (after all code reads media tables) | 1 (end) |
| 000009 | `2026_11_23_000009_extend_product_reviews.php` | 2 |
| 000010 | `2026_11_24_000010_create_customer_tables.php` | 3 |
| 000011 | `2026_11_24_000011_create_order_tables.php` (incl. return tables + document_counters) | 4 |
| 000012 | `2026_11_25_000012_create_payment_tables.php` (+ `audit_logs`) | 5 |

Dates in file names only set the run order, not deadlines. Each migration has a working `down()`.

## Verification per stage
Each stage ends with a PHP test file in the style of `tests/inventory_test.php` for its service
(numbering, transitions, syncOrder math, cooldown, expiry), plus an HTTP smoke test of every new
page, run on **MySQL**. The dev DB is cleaned back to seed data afterwards, as with earlier phases.
