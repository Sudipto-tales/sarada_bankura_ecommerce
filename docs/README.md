# Maa Sarada Marble & Stone — project docs

Admin panel + storefront for Maa Sarada Marble & Stone (Bankura), built on the in-house **Vayu**
PHP framework. Production runs on Hostinger with **MariaDB/MySQL**. SQLite is for local dev only.

## Docs

| Doc | What it covers | Status |
| --- | --- | --- |
| [deployment.md](deployment.md) | CI, deploy pipeline, server layout, first-deploy and cron steps | live |
| [inventory.md](inventory.md) | Stock register, alerts, `inventory:check` cron | built |
| [customers.md](customers.md) | Customers, addresses, segments, tags, pincode auto-fill | built |
| [orders.md](orders.md) | Orders, workflow, documents, GST, returns & replacements | built |
| [payments.md](payments.md) | UPI payment window, manual verification, refunds, payment requests | built |
| [admin-access.md](admin-access.md) | Staff login, roles & permissions, invites, OTP | phases 1–4 built |
| [whatsapp.md](whatsapp.md) | Meta Cloud API channel (dormant until `.env` is filled) | W1–W3 built |
| [storefront.md](storefront.md) | Public shop, cart, checkout, customer accounts | S1–S5 built |
| [mobile-app.md](mobile-app.md) | Android app (Capacitor), Play Store, offline, APK release workflow | planned |
| [usage.md](usage.md) · [architecture.md](architecture.md) · [features.md](features.md) · [realtime.md](realtime.md) | The Vayu framework itself | reference |

Design references: `html/admin/` (Veylix admin mockups) and `html/storefront/` (storefront
mockups). Neither is served; the real pages are PHP views in `resources/views/`.

## Project rules

- **Indian time only (IST, Asia/Kolkata)** for every date rule: FY April–March, the 6-minute pay
  window, cooldowns, "inactive 90 days", reports. Compute dates in PHP and bind them. Never write
  SQLite-only SQL (`datetime('now')`, `julianday`) or rely on SQL `NOW()`.
- **Vayu schema limits:** no ENUM (use `string()->default()`), no foreign keys (indexed
  `bigInteger`, enforced in code). Every migration has a working `down()`.
- **POST for every write.** Route names follow `admin.<module>.*`; edit = `POST …/{id}/update`,
  delete = `POST …/{id}/delete`.
- **No CDN.** Fonts, Lucide, Chart.js and qrcodejs are vendored. Tailwind is compiled by
  `bin/build-css.sh` (admin `app.css` and storefront `store.css` are separate builds). Edit the
  sources in `resources/css/` and rebuild; never hand-edit the compiled CSS.
- **Settings** live in `config/business.php` and `.env` (`BUSINESS_*`) until the owner plans two
  separate areas: **System settings** (logo, theme, channels) and **Payment settings** (receiving
  accounts, tax rules, advance %). Don't merge them into one table.
- **Variants and the attribute library stay separate.** Attributes describe a product; variants are
  sellable options. Stock is tracked **per product**, never per variant.

## Single writers

Only the listed class may write these tables or columns. Controllers never update them directly.

| Data | Writer |
| --- | --- |
| `media`, `media_links` | `Media\MediaService` |
| `inventory_stock` + `inventory_movements` | `Inventory\InventoryService` |
| `notifications` | `NotificationService` |
| customers, addresses, customer tag links | `Customer\CustomerService` |
| `customers.total_orders / total_spent / last_order_at / lifecycle` | `Customer\CustomerStats` |
| `orders.order_status` + `order_status_history` | `Order\OrderService::transition()` |
| `orders.paid_amount / due_amount / payment_status / pending_verification_amount / refund_due` | `Order\PaymentService::syncOrder()` |
| every number series (order, invoice, receipt, refund, attempt, return, enquiry) | `Order\DocumentNumber::next()` |
| return tables | `Order\ReturnService` |
| `payment_accounts` / attempts / payments + refunds | `Payment\Accounts` / `Payment\PayWindow` / `Payment\Ledger` |
| `admin_users` + roles links / login attempts + remember tokens | `AdminAccess\Staff` / `AdminAccess\Guard` |
| `whatsapp_messages` (outgoing) | `WhatsApp\WhatsAppService` |
| carts / saved items / enquiries / storefront `users` + auth tables | `Store\Cart` / `Store\Saved` / `Store\Enquiries` / `Store\CustomerAuth` |

## Migrations

| # | Adds |
| --- | --- |
| 000001–000003 | users, api_tokens, product catalogue (products, types, attributes, brands, categories, tags, pricing) |
| 000004–000005 | units, option groups + variants, attribute values, reviews, brand/tag metadata |
| 000006–000007 | inventory + notifications |
| 000008 / 000008a | media library (copied `product_media` and brand logos, then dropped `product_media`) |
| 000009 | public review form fields |
| 000010 | customers, addresses, tags, segments, pincode cache |
| 000011 | orders, items, status history, invoices, deliveries, document counters, return tables |
| 000012 | payment accounts, attempts, payments, refunds, request logs, audit log |
| 000013 | WhatsApp messages + campaigns |
| 000014 | admin users, roles, invites, OTPs, remember tokens, login attempts |
| 000016–000017 | storefront: carts, saved items, enquiries, customer auth; nullable `users.email` |

(000015 was reserved for returns and never used.)

Seeders: `ProductCatalogSeeder`, `AttributeLibrarySeeder`, `CatalogExtrasSeeder`, `CustomerSeeder`,
`StorefrontSeeder`. All are idempotent. `php vayu admin:install` seeds roles and the first Owner.

## Admin UI conventions

- One look everywhere: KPI row on list pages, filter chips with counts, table rows with an inline
  primary action and a `⋯` menu, modals for short forms, full pages for long forms, detail page =
  content + right summary rail, IST timelines.
- Toasts: controllers `session()->flash('success'|'error'|'warning'|'info', …)`; the footer shows
  them. JS: `msToast(…)`.
- Status tones (reuse `.t-*`): amber = pending / to verify / low stock · blue = accepted ·
  indigo = processing · violet = packed / overpaid · teal = dispatched · green = delivered / paid /
  in stock · red = rejected / due / refund due / out of stock · grey = refunded / void / archived.
- **Sensitive changes ask twice** (`partials/sensitive-confirm.php`): review old → new (masked),
  then the OTP again; deleting also needs the name typed. Every change goes to `audit_logs`.

## Not built yet

- **Admin access phases 5–7:** `can:` middleware per route, sidebar/button hiding, roles screen,
  audit wiring. Until then any signed-in staff member can open every admin page except Staff.
- **Storefront S6:** admin additions (category covers, tag icons, min qty / per-variant pack in the
  wizard and importer, Room role picker), admin "reset customer password", verified-purchase flag
  and public review photos, sitemap / robots / OG, cart-abandon cron. (Production already has the
  store switched on.)
- **WhatsApp:** `return_update` hook, admin OTP + `staff_invite` hooks, W4 campaigns, W5 phone OTP.
- **Dashboard order + money cards** and the **Online vs Offline store** card (`orders.channel`
  says where the sale happened; `payments.collected_via` says how the money came in — keep the two
  separate so nothing is counted twice).
- Customer self-service returns, variant-level return policies in the UI, editing an order after
  it is created.
- Known gap: core `Request::ip()` trusts `X-Forwarded-For` (affects `ThrottleRequests`). Login
  lockouts use `REMOTE_ADDR` and are not affected.

## Testing

`php tests/run.php` runs every `tests/*_test.php` (1,154 assertions on 2026-11-26). CI also
migrates fresh, re-migrates (no-op), rolls back and re-migrates on MariaDB 11.8 and MySQL 8.0.
For manual checks use a scratch copy of the database, never the dev DB.
