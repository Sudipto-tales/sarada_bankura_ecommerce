# Storefront: PHP Build Plan (Stage 8)

**Status: S1 + S2 + S3 BUILT (2026-11-25) on `feature/stage8-storefront`; S4–S6 planned.** This turns the HTML design set in `html/storefront/*.html`
(+ `assets/store.js`) into server-rendered PHP pages on the Vayu stack. It reuses what Stages 0–5
already built: catalogue, media, reviews, customers, orders, payments. It adds only what the design
needs and the schema doesn't have yet.

Same rules as the other plans: production DB is **MySQL**, all time is **IST** (computed in PHP and
bound, never SQL `NOW()`), string statuses, indexed `bigInteger` instead of FKs, POST for every
write, one single-writer service per table.

---

## 0. What the design needs vs. what already exists

| Design page | Route | Already built (reuse) | New work |
| --- | --- | --- | --- |
| `index.html` home | `GET /` | products, pricing, media, categories, brands, tags, reviews | `Store\Catalog` queries, home view |
| `shop.html` listing | `GET /shop[/{cat}[/{sub}]]` | category tree, attribute values, option values, tags, brands | facet + filter query builder |
| `product.html` detail | `GET /product/{slug}` | variants, option groups, attribute values, media, stock status, return policies, reviews | variant resolver, related products |
| `cart.html` | `GET /cart` | `OrderService::cleanItems()` pricing rules | **`carts` / `cart_items`**, `Store\Cart` |
| `checkout.html` | `GET /checkout` | `PayWindow::forCheckout()`, `OrderService::quote()`, `PincodeLookup`, tax mode | `Store\Checkout` (server builds order input) |
| `pay.html` | `GET /pay/{token}` | **done** (`PayController`) | reskin to store layout |
| `order.html` | `GET /order/{token}` | **done** (`PublicOrderController`) | reskin, return button after Stage 6 |
| `track.html` | `GET /track` | orders.order_number + phone | lookup + throttle |
| `signin.html` | `GET /signin` | `users` (phone/status/verified cols), `CustomerService` phone linking | `Store\CustomerAuth`, reset codes, remember-me |
| `account.html` | `GET /account` | customers, addresses, orders by customer_id | account controller |
| `saved.html` | `GET /saved` | — | **`customer_saved_items`** |
| `review.html` | `GET /review/{slug}` | **done** (`ReviewFormController`) | reskin + verified-purchase flag |
| `brands.html` | `GET /brands` | brands (tier, country, logo link, featured) | view |
| `contact.html` | `GET/POST /contact` | `ContactController` (stores **nothing** today) | **`enquiries`** table + admin inbox |
| `policy.html` | `GET /policy/{page}` | `config/business.php` | static views |
| `calculator.html` | `GET /calculator` | pricing + `pack_qty` | JSON of purchasable products |
| (not designed) 404 | — | `errors/404.php` | restyle in store layout |

---

## 1. Extra migration (only one)

**`database/migrations/2026_11_26_000016_create_storefront_tables.php`**: numbers 000013–000015
are reserved for WhatsApp, admin access and returns (see the parallel-worktrees note), so the
storefront takes **000016**.

### 1a. ALTER existing tables (all nullable, so no data change)

| Table | New column | Why (design gap) |
| --- | --- | --- |
| `tags` | `icon` string(40) null | discovery chips show an icon (Lucide name) |
| `product_pricing` | `min_order_qty` decimal(12,3) null, `order_step` decimal(12,3) null | "minimum 50 sq ft", "+/- in steps of a box" |
| `product_variants` | `pack_qty` decimal(12,3) null, `pack_unit` string(16) null, `min_order_qty` decimal(12,3) null | box contents differ per size/thickness. NULL = use the product's `product_pricing` value |
| `orders` | `buyer_note` text null | the customer's own note at checkout. `customer_note` already means *our* note **to** the customer (reject reason), so it can't be reused |
| `attribute_values` | `family` string(30) null **(only if owner says yes, §6 Q2)** | colour values are stone names ("Statuario"), and the facet needs families (white / black / beige) |

### 1b. New tables

```
carts
  id, token (64, unique)            ← long-lived cookie ms_cart (30 days)
  customer_id (null, idx), user_id (null)
  status (active | converted | abandoned), converted_order_id (null)
  last_seen_at, ip, user_agent, timestamps
  index (status, last_seen_at)

cart_items
  id, cart_id (idx), product_id, variant_id (default 0)
  quantity decimal(12,3)
  price_at_add decimal(12,2)        ← only to show "price changed since you added it"
  timestamps
  unique (cart_id, product_id, variant_id)

customer_saved_items
  id, customer_id (idx), product_id, variant_id (default 0), created_at
  unique (customer_id, product_id, variant_id)

enquiries
  id, enquiry_number (unique, ENQ/26-27/0001 via DocumentNumber)
  customer_id (null, idx)           ← matched/created by phone (CustomerService)
  name, phone, email (null), city (null)
  category_id (null), product_id (null), variant_id (default 0)
  quantity (null), unit (null), message (text)
  source (contact | product | home | calculator)
  status (new | contacted | quoted | converted | closed), idx
  assigned_to (null), converted_order_id (null), internal_note (null)
  ip, user_agent, timestamps

customer_auth_codes                  ← storefront only; admin has its own admin_otps (admin-access.md)
  id, user_id (idx), purpose (reset_password | verify_phone[reserved for WhatsApp])
  channel (email | whatsapp), code_hash, attempts (default 0)
  expires_at, used_at (null), ip, created_at

customer_remember_tokens             ← "stay signed in" (PHP sessions die after ~24 min idle)
  id, user_id (idx), selector (unique), validator_hash, expires_at, last_used_at, user_agent, created_at
```

**Why a cart table and not localStorage/session:** core `Session` calls a plain `session_start()`
(PHP default ≈24 min idle, cookie gone on browser close), so a session cart would quietly empty.
localStorage carts can't be priced or validated server-side and don't follow a signed-in customer
to another device. A cookie token + table fixes both and enables abandoned-cart follow-up later
(WhatsApp W-phase).

### 1c. Gaps that need **no** migration

| Design gap | Solved by |
| --- | --- |
| Category image | `media_links` owner_type `category`, role `cover` (owner type already allowed) + uploader in the category modal |
| Room / lifestyle shots | `media_links.role = 'room'` (string column). Add to MediaService's allowed roles + gallery role picker |
| "Imported / Indian" | attribute **`origin`** (already seeded). Home tiles link to `/shop?origin=imported` |
| Sub-category vs `application` overlap | keep both: sub-category = navigation, application = facet |
| Hero slides | `products.is_featured` + cover image (design says so) |
| Offers | `compare_at_price > price` (product or variant) |
| Verified review | `product_reviews.is_verified_purchase` exists (000009). Set it in `ReviewSubmission` |
| Pincode autofill | `pincodes` cache + `PincodeLookup` (Stage 3) |
| Return/replacement days on PDP | `product_return_policies` (000011) |

---

## 2. Code layout

```
app/Controllers/Store/      HomeController, ShopController, ProductController, BrandController,
                            CartController, CheckoutController, TrackController, AuthController,
                            AccountController, SavedController, EnquiryController, PageController
app/Services/Store/         Catalog        (read-only queries: home blocks, listing+facets, PDP, related)
                            Listing        (filter/sort/facet query builder from query string)
                            Cart           (only writer of carts/cart_items)
                            Checkout       (cart → server-built order input → PayWindow)
                            CustomerAuth   (register/sign-in/reset/remember; only writer of the 2 auth tables)
                            Saved          (only writer of customer_saved_items)
                            Enquiries      (only writer of enquiries)
                            Purchasable    (one rule: can this product/variant be bought online?)
app/Middleware/             StoreCustomer  (loads signed-in customer; remember-me cookie → session)
                            StoreOpen      (shows coming-soon while STOREFRONT_ENABLED=false)
resources/views/store/
  layouts/app.php           <head>, fonts, store.css, header, footer, toasts
  layouts/minimal.php       checkout / pay chrome (design: minimal header)
  partials/                 header.php, footer.php, mega-menu.php, product-card.php,
                            option-picker.php, price.php, stars.php, breadcrumbs.php,
                            pagination.php, facets.php, enquiry-form.php, address-form.php
  home.php shop.php product.php brands.php cart.php checkout.php track.php signin.php
  account.php saved.php contact.php calculator.php policy/*.php errors/404.php
  order.php pay.php         (reskins: logic stays in PublicOrderController / PayController)
resources/css/store.css + tailwind.store.config.js → public/assets/css/store.css (compiled)
public/assets/js/store.js   small: mega menu, gallery, option picker, qty steppers, facets drawer,
                            pincode autofill, calculator. pay-window.js reused as is.
```

**Design conversion rules**

- `renderChrome()` → `partials/header.php` + `footer.php`. Categories, brands and the cart count
  come from one `Catalog::chrome()` call, cached 10 min in `storage/cache`.
- `card()` → `partials/product-card.php` (one function `store_card($p)` used by home, shop,
  related and saved pages).
- `renderOptions()` → server renders all option values. Inactive combinations are struck through
  via a JSON map of variants embedded in the page. JS only swaps price/SKU/images and sets
  `variant_id` in the add-to-cart form. **The form works without JS** (falls back to the default
  variant + a select).
- Demo data in `store.js` is **deleted**, and so are all `data-map` attributes and the "Show schema
  map" toggle.
- **No Tailwind CDN in production.** A second compiled build uses the storefront palette (cream /
  ink / teal #1E6E74 / copper / gold, Fraunces + Manrope). It's separate from the admin `app.css`
  so the two palettes don't clash. Lucide and the fonts are vendored locally (same no-CDN rule as qrcodejs).
- Full-width layout (fluid gutters to 2000 px) kept. No compare feature, no "shop by colour" block
  (owner decisions).

---

## 3. Backend logic flow

### 3.1 Every storefront request

```
request ─► StoreOpen      STOREFRONT_ENABLED=false → coming-soon (preview cookie lets the owner in)
        ─► StoreCustomer  session('customer_user_id') ? load : remember cookie ? validate selector+
                          validator (hash_equals) → rotate token → log in : guest
        ─► Cart::current() cookie ms_cart → carts row (created lazily on first add, not on visit)
        ─► controller ─► Store\Catalog (read-only) ─► view(store/…) with layout
```

### 3.2 What is visible (one rule, used everywhere)

```
listed  = products.status IN ('active','coming_soon')        (draft/archived never public)
price   = shown only if show_price = 1 AND is_enquiry_only = 0
buyable = Purchasable::check(product, variant):
            status = 'active' AND is_enquiry_only = 0 AND show_price = 1 AND price > 0
            AND (no active variants OR variant chosen AND variant.status = 'active')
coming_soon     → "Notify me" / enquiry button, no cart
enquiry-only    → enquiry form (source=product) instead of Add to bag
stock           → inventory_stock.status shows "Few left" / "Made to order" badge; **never blocks**
                  (owner rule: orders never check stock; see §6 Q1)
tags            → only purpose = 'discovery' AND status = 'active'
reviews         → status = 'published' only; photos served only when the review is published
```

### 3.3 Shop listing (`/shop`, `/shop/{cat}`, `/shop/{cat}/{sub}`)

```
query string ─► Listing::fromRequest()   whitelist: q, brand[], tag[], origin, attr[slug][],
                                          opt[group][], price_min/max, offer, sort, page
            ─► base:  products p JOIN product_pricing  WHERE listed
                      + category: product_category_map (cat + all its children)
                      + q: name / sku / variant sku / brand name LIKE ?  (bound, escaped %)
            ─► facets: same base WITHOUT that facet's own filter → counts per value
                       (attribute values, option values, brands, discovery tags, price range)
            ─► sort: featured (is_featured, is_bestseller, sort) | newest | price ↑↓ (variant min price)
            ─► page: 24/page, LIMIT/OFFSET, canonical URL without empty params
            ─► one batched query for cover + hover images (MediaService), one for min variant price
```

### 3.4 Product page (`/product/{slug}?v={sku}`)

```
slug ─► product (listed) else 404
     ─► breadcrumbs from product_category_map.is_primary → parent
     ─► media links (cover, gallery, room, per-variant images)
     ─► variants + option_groups/option_values used → JSON map {values → variant id, price,
        compare_at, sku, unit, pack_qty, min_qty, image ids, status}
     ─► specs = product_attribute_values, overridden by the selected variant's values
     ─► pricing per unit + pack conversion (sq ft → boxes, using variant pack_qty ?? product pack_qty)
     ─► return policy (variant row, else product row)
     ─► reviews: published, avg + count, verified badge; "Write a review" → /review/{slug}
     ─► related: same primary sub-category, then shared discovery tags, limit 8, exclude self
     ─► JSON-LD Product + Offer (only when price shown)
```

### 3.5 Cart

```
POST /cart/add {product_id, variant_id, qty}     (CSRF; also works as a plain form post)
  ─► Purchasable::check → refuse with a message (enquiry-only / coming soon / pick an option)
  ─► qty: ≥ min_order_qty, rounded to order_step, ≤ 9,999,999
  ─► Cart::add → create cart + cookie if none → upsert line (same product+variant adds qty)
  ─► JSON {count, line} for JS, or redirect back with a toast

GET /cart
  ─► re-price every line from the DB (never from the cookie/client)
  ─► lines that became unbuyable → shown greyed with the reason + Remove
  ─► price changed vs price_at_add → small notice
  ─► totals preview = OrderService::quote() (same maths as admin orders: subtotal − discount + GST,
     delivery included, no shipping line)

Sign-in merge: guest cart + customer's open cart → one cart (qty summed); guest saved items
  (localStorage) are posted once to /saved/merge.
Cron (existing payments:expire slot): carts idle > 30 days → status abandoned.
```

### 3.6 Checkout → pay → order (the core flow)

```
GET /checkout          cart empty → /cart.  Signed in → prefill name/phone/email + default address
POST /checkout/quote   (JSON, on pincode/state change) → state from PincodeLookup
                       → place_of_supply → tax mode CGST+SGST (West Bengal) or IGST → totals
POST /checkout/start   {name, phone, email?, address (or saved address id), billing_same, buyer_note,
                        pay_choice advance|full}
  1. validate (phone via Customer\Phone normaliser, pincode 6 digits, address lines)
  2. Checkout::buildOrderInput(cart):
       items = [{product_id, variant_id, quantity, unit_price ← SERVER price}]   (no discount key;
       anything the client posts for price/discount is ignored)
       channel = online, channel_detail = website, buyer_note, addresses, customer by phone
  3. Purchasable::check every line again
  4. PayWindow::forCheckout(input, choice, ip, ua)
       → OrderService::quote() (rolled-back txn, numbers stay gapless)
       → amount = full or PaymentService::advance() (20%, rounded up)
       → payment_attempts row (context=checkout, cart_snapshot, expires_at = now IST + 6 min)
  5. session('checkout_attempt') = token → JSON {token} → page opens the existing pay window
  ── customer pays by UPI, enters payer name + UTR / screenshot ──
  6. POST /pay/attempt/{token}/submit (existing) → order created from cart_snapshot
       (OrderService::create, status pending, payment pending verification)
  7. POST /checkout/done {token} → only if token == session value AND attempt submitted
       → Cart::convert(cart, order_id) → redirect /order/{public_token}
  expiry / close → attempt expired (existing behaviour), cart untouched, "Try again"
```

Admin side doesn't change: the order lands in `/admin/orders` as **pending / online**, with the
payment in **To verify**. Accept/reject → dispatch → delivered continue as in Stages 4–5.

### 3.7 Customer accounts

```
Register  {name, phone, email?, password, marketing_consent}
  ─► throttle per IP ─► phone unique in users
  ─► users row (password_hash()) ─► CustomerService: customer with same phone? link user_id
     (showroom history appears immediately) : create customer (source=online, is_online=1)
  ─► session_regenerate_id ─► /account
Sign in   phone-or-email + password ─► throttle (IP + identifier) ─► password_verify
          ─► status must be active ─► last_login_at ─► optional remember token (30 days)
Forgot    email → 6-digit code (hash stored, 15 min, 5 tries) via App\Services\Mail\Mail
          MAIL_* empty or no email on account → "Message us on WhatsApp to reset" (design copy)
          Same response whether the account exists or not.
Phone verified badge: phone_verified_at stays NULL until the WhatsApp OTP (W-phase) exists;
          UI shows "verified" only when set. Purpose 'verify_phone' is reserved for that.
Account   orders: orders WHERE customer_id (online + showroom), status tones from design, Pay → /pay/{token}
          addresses: CustomerService (same writer as admin), pincode autofill, default toggle
          profile: name, email, consent (phone read-only: "contact us"); change password (current required)
          sign out: destroy session + delete remember token
```

### 3.8 Track, enquiries, reviews, saved

```
Track     POST /track {order_number, phone} ─► throttle 10/h/IP ─► match normalised phone
          ─► redirect /order/{public_token}  (same message on any mismatch, no enumeration)
Enquiry   contact / product / home / calculator forms ─► honeypot + time trap + rate limit
          (same guard as ReviewSubmission) ─► Enquiries::create ─► customer by phone
          ─► notification enquiry.received ─► /admin/enquiries inbox (status chips, assign,
          "Create order" prefill → admin order form, WhatsApp wa.me reply link)
Review    existing /review/{slug} in store layout; on submit set is_verified_purchase when phone
          matches a delivered order_item of that product. On publish, review photos become public
          (served by a route that checks status = published, so nothing is copied)
Saved     guest: localStorage (as in the design) ─► on sign-in POST /saved/merge → customer_saved_items
          signed in: Saved::toggle(customer, product, variant)
```

### 3.9 Calculator

`GET /calculator.json` = buyable products with unit, price, pack_qty, pack_unit, min qty. The
maths (area → sq ft → boxes, wastage %) runs in the browser. "Add to bag" posts the computed qty to
`/cart/add`, which validates it again.

---

## 4. Admin additions (small, one block each in routes + sidebar)

| Where | Change |
| --- | --- |
| `/admin/enquiries` (new, sidebar under Customers) | inbox + detail drawer, status, assign, notes, convert to order |
| Categories modal | cover image uploader (media link category/cover) |
| Tags | icon picker (Lucide name) for discovery tags |
| Product wizard step 4 + variant builder | min order qty, order step, per-variant pack qty/unit |
| Product gallery | role picker adds **Room** |
| Importer | columns `min_order_qty`, `order_step`, variant `pack_qty`/`pack_unit` (blank = keep, `-` = clear, as today) |
| Orders list/detail | show `buyer_note`; online badge already exists |
| `config/permissions.php` (when admin-access merges) | `enquiries.view`, `enquiries.manage` |

---

## 5. Build phases (one commit per phase on `feature/stage8-storefront`)

| Phase | Scope | Done when |
| --- | --- | --- |
| **S1 Foundation** | migration 000016, store Tailwind build, layouts + header/footer/card partials, `Catalog::chrome()`, `StoreOpen`/`StoreCustomer` middleware, home, brands, policy, 404 | home renders from DB on MySQL + SQLite, coming-soon switch works |
| **S2 Catalogue** | `Listing` facets/search/sort/pagination, shop page, product page (variants, gallery, specs, reviews, related, JSON-LD), enquiry form + `/admin/enquiries` | every filter combo bound & tested; PDP works without JS |
| **S3 Cart** | carts/cart_items, `Purchasable`, add/update/remove, re-pricing, calculator, saved (guest) | tampered price/variant/qty refused in tests |
| **S4 Checkout** | `/checkout`, quote endpoint, `Checkout::buildOrderInput` → `PayWindow::forCheckout`, done/convert, track page, reskin `/order` + `/pay` | full flow: bag → pay → order pending online in admin → verify → dispatch → delivered |
| **S5 Accounts** | register/sign-in/forgot/remember, account tabs, addresses, cart + saved merge, showroom-order linking by phone | register with a showroom customer's phone shows their past orders |
| **S6 Admin + launch** | §4 admin additions, review verified flag + public photos, sitemap.xml/robots.txt/meta/OG, cart abandon cron, MySQL verification run + Chromium (desktop + 375 px) | `STOREFRONT_ENABLED=true` on prod |

**Fitting in with the parallel work:** start S1 on a fresh branch from `main` **after Stage 6 returns
merges**. Phases S1–S3 don't touch returns, so they can begin earlier in their own worktree (port
8003). The "Request return" button on `/order/{token}` is wired after Stage 6. WhatsApp hooks
(enquiry reply, abandoned cart, phone OTP) are added when WhatsApp W3 merges. Until then they fall
back to `wa.me` links, like everything else.

---

## 6. Owner decisions (owner took every recommended default, 2026-11-25)

1. **Out-of-stock on the storefront:** orders never check stock today. Recommendation: still buyable
   with a "Made to order: we'll confirm the dispatch date" badge. Alternative: block when
   `status = out`.
2. **Colour family** (`attribute_values.family`): add it now so the shop's colour facet can group
   stone names? Recommendation: **yes**, it's one nullable column filled from the attribute page.
3. **Order page/pay page look:** recommendation: reskin `/order` and `/pay` to the storefront
   design, and keep `/invoice/{token}` in the printable document style.
4. **Launch switch:** recommendation: keep coming-soon on `/` (env `STOREFRONT_ENABLED=false`) with
   an owner preview cookie until S4 is verified on MySQL.

---

## 7. As built: S1 Foundation (2026-11-25)

**Decisions applied (§6 defaults):** Q1 out-of-stock stays buyable with a "Made to order" badge
(low stock = "Few left"); Q2 `attribute_values.family` added; Q3 `/order` + `/pay` reskin is S4
work; Q4 `STOREFRONT_ENABLED=false` keeps coming-soon. **Preview = a logged-in admin** (any staff
login) instead of a preview cookie: no extra secret to manage; preview pages send `no-store` +
`noindex` and show a copper preview bar.

| Piece | Where |
| --- | --- |
| Migration 000016 (all §1 ALTERs + 6 tables) | `database/migrations/2026_11_26_000016_create_storefront_tables.php` |
| Tag icons + colour families (fills NULLs only) | `php vayu db:seed StorefrontSeeder` (run on prod after migrate) |
| Launch switch, cache TTL, shop hours | `config/store.php`, `.env`: `STOREFRONT_ENABLED`, `STOREFRONT_LAUNCH_AT`, `STOREFRONT_CACHE_TTL`, `BUSINESS_HOURS` |
| Middleware | `App\Middleware\StoreOpen` (switch), `StoreCustomer` (session only; remember-me arrives with S5) |
| Read-only queries | `App\Services\Store\Catalog`: `chrome()` (cached), `cards()`, `home()`, `brands()`, `reviewSummary()` |
| Request state | `App\Services\Store\Context`: open / previewing / customer / cart count (cookie `ms_cart`) |
| Rendering | `App\Services\Store\View::page()` wraps `resources/views/store/*.php` in `store/layouts/app.php` (or `minimal`); helpers in `app/Services/Store/helpers.php` (`store_card`, `store_img`, `store_inr`, …) |
| Pages | `/` home, `/brands` (`?tier=`), `/policy` (+ `/policy/{section}` → anchor), store-styled 404 (`errors/404.php` → `store/errors/404.php`; `/admin` keeps the plain page) |
| CSS / JS | `tailwind.store.config.js` + `resources/css/store.css` → `public/assets/css/store.css` (`bin/build-css.sh` builds both; admin config now excludes store files, admin `app.css` unchanged). `public/assets/js/store.js` = icons, drawer, toasts, saved hearts, home slider/finder/tabs/spotlight |
| No CDN | Fraunces + Manrope woff2 in `public/assets/vendor/fonts/`, Lucide 0.544.0 in `public/assets/vendor/lucide/` |
| Media role | `room` added to `MediaService::ROLES` (hover image on cards, hero slides). Admin role picker = S6 |
| Tests | `tests/storefront_test.php` (migration, visibility/price/badge/CTA rules, counts, escaping, no-CDN, launch switch incl. admin preview) |

**Behaviour notes**

- Home: hero slides = brand slide + featured products with images; finder = real radios/checkboxes
  (GET `/shop?cat=…&attr[colour][]=…`, JS turns it into `/shop/{space}/{room}`); collection tabs
  are server-rendered, JS only hides cards; spotlight = first featured buyable product.
- Card CTA "Add to bag" becomes a POST form automatically once a route named `store.cart.add`
  exists (S3); until then it links to the product page ("View & buy").
- Home enquiry form is replaced by WhatsApp / call / `/contact` buttons until S2 builds `enquiries`.
- Room visualiser + catalogues tiles from the design were dropped (not in any phase).
- Links to `/shop`, `/product/…`, `/cart`, `/calculator`, `/track`, `/saved`, `/signin` show the
  store 404 until their phase ships — another reason the switch stays off until S4.
- Verified: SQLite (tests, 884 assertions) and MariaDB (`maa_sarada_test`: migrate + seeder + `/`,
  `/brands`, `/policy`, 404 render with no SQL errors); Chromium 1440 px + 375 px, no console
  errors, no horizontal scroll.

---

## 8. As built: S2 Catalogue (2026-11-25)

| Piece | Where |
| --- | --- |
| Shop listing | `GET /shop`, `/shop/{space}`, `/shop/{space}/{room}` → `Store\ShopController`. `?cat=<slug>` (finder without JS) redirects to the path; a room reached by its own slug 301s to `/shop/{space}/{room}` |
| Filters / facets / search / sort / pages | `App\Services\Store\Listing` (`fromQuery()` whitelist → `run()`), 24 per page |
| Product page | `GET /product/{slug}?v={SKU}` → `Store\ProductController` + `App\Services\Store\ProductPage` (variants, option map, gallery incl. variant images, specs + variant overrides, return policy, reviews, related, JSON-LD) |
| Enquiries | `POST /enquiry` → `Store\EnquiryController` → `App\Services\Store\Enquiries` (only writer of `enquiries`). Numbers `ENQ/26-27/0001` (new `enquiry` series in `DocumentNumber` + `business.numbering.enquiry`) |
| Contact page | `GET /contact` (store layout) replaces the old demo `ContactController` + `views/contact.php` (deleted; `ContactRequest` stays for the CSRF test) |
| Admin inbox | `GET /admin/enquiries` (+ `POST /admin/enquiries/{id}/update`) → `Admin\EnquiryController`, view `admin/enquiries/index.php`; sidebar link with a "new" badge |
| Views | `store/shop.php`, `store/product.php`, `store/contact.php`, partials `enquiry-form.php`, `spec-teaser.php` |
| JS | `store.js`: filters auto-submit + mobile drawer + grid density; product gallery/zoom, option picker (inactive combinations struck), qty stepper + totals, room calculator, variant table, review filter |
| Tests | `tests/storefront_s2_test.php` (89 assertions) |

**How filtering works (differs from §3.3's "query builder"):** `Listing` builds one cached *facet
index* (a small row per listed product: categories, brand, discovery tags, attribute + option
values, cheapest active price, flags, search text) and filters / counts / sorts in PHP. The SQL is
fixed — nothing from the URL reaches a query. Fine for a catalogue of hundreds (thousands) of
products; cached for `STOREFRONT_CACHE_TTL` like the menu.

**Query string:** `q`, `attr[code][]`, `opt[group][]`, `brand[]`, `tag[]`, `min`, `max`, `avail[]`
(`in` / `price` / `nosoon`), `offer=1`, `sort` (`featured|best|rating|low|high|new`), `page`.
OR inside a facet, AND across facets; each facet's counts ignore its own filter.
- Facets = attributes with `used_for` `filters` / `variants_filters`. An option group with the same
  name as an attribute (Finish, Thickness, Size, Grade) is merged into it; other groups get
  `opt[slug][]`. Only active variants count.
- Colour: values are grouped by `attribute_values.family`; a stone name in the URL (home finder)
  selects its family. Names without a family stay as their own value.
- Price filter / sort use the cheapest **active** variant (else the product price); enquiry-only,
  price-hidden and coming-soon products drop out of a price filter.
- `origin=` from §3.3 was not added: origin is `attr[origin][]`, and "Imported / Indian" are the
  `/shop/imported` and `/shop/indian` category pages.

**Product page without JS:** the selected variant is rendered server-side; variant table rows link
to `?v=SKU`, and a `<noscript>` select switches variant. Inactive variants show as "Not offered";
archived ones are never shown. Until S3 adds the cart, the buy box shows **Request this stone**
(enquiry form, `kind=quote`) and **Order on WhatsApp** (qty + variant in the message); when the
`store.cart.add` route exists, the same box turns into the Add to bag / Buy now form
(`product_id`, `variant_id`, `qty`).

**Enquiries:** honeypot + signed time stamp (≥ 3 s, ≤ 2 days) + 6 per IP per hour + same
mobile/text/product within 24 h = the first one (no duplicate). The mobile links an existing
customer, else creates an online customer. Product enquiries store "Quote request: <stone>
(<variant>)" at the top of the message. Notification `enquiry.received` (dedupe key
`enquiry:<id>`, cleared when the status leaves *new*). `back` (return page) accepts local paths
only.

**Admin inbox:** tabs Open / New / Contacted / Quoted / Converted / Closed / All, search (name,
mobile, ENQ number, text), drawer with WhatsApp / call / customer / product links and **Create
order** (`/admin/orders/create?customer=<id>`). Permissions checked in the controller:
`enquiries.view` (see), `enquiries.reply` (status + note), `enquiries.assign` (assignee = active
`admin_users`). Linking a converted order (`converted_order_id`) exists in the service; the UI for
it comes with S6.

**Verified:** SQLite (full suite 973 assertions) and MariaDB `maa_sarada_test` (all pages,
filters, search, enquiry write, inbox search; demo rows removed afterwards). Chromium 1440 px +
375 px on shop, room, product (variant / enquiry-only / coming soon) and contact: no console
errors, no horizontal scroll; variant switch updates price, SKU, specs and URL; filter
auto-submit gives clean URLs; mobile filter drawer works (also without JS via `#filters:target`).

## 9. As built: S3 Cart (2026-11-25)

| Piece | Where |
| --- | --- |
| Buy rule | `App\Services\Store\Purchasable::check(product, variant)` (§3.2) + `quantity()` (number > 0, rounded **up** to `order_step`, ≥ minimum, ≤ 9,999,999). Price = variant price ?? product price, the same rule as `OrderService::cleanItems` |
| Bag | `App\Services\Store\Cart` (only writer of `carts` / `cart_items`): `add`, `update` (0 = remove), `remove`, `convert` (for S4), `lines()` (re-priced), `totals()`, `taxRate()` |
| Routes | `GET /cart`, `POST /cart/add` (`product_id`, `variant_id`, `qty`, optional `next=checkout`), `POST /cart/update` (`line`, `qty`), `POST /cart/remove` (`line`, `later`), `GET /calculator`, `GET /saved`, `GET /saved/cards?s=slug,…` → `Store\CartController`, `Store\SavedController` |
| Views | `store/cart.php`, `store/calculator.php`, `store/saved.php` |
| Calculator data | `App\Services\Store\Calculator::stones()` (buyable stones + active options, 2 batched queries), embedded as `#calc-data` JSON instead of a separate `/calculator.json` |
| JS | `store.js`: add-to-bag forms → fetch JSON (badge + toast; plain post without JS, "Buy now" always posts), bag steppers auto-submit, "Save for later" also saves locally, calculator maths, saved page cards |
| Tests | `tests/storefront_s3_test.php` (75 assertions) |

**Behaviour notes**

- Cart row + `ms_cart` cookie (48 chars, HttpOnly, SameSite=Lax, 30 days) are created on the
  **first add**, never on a visit. An unknown / malformed cookie token is never adopted: the next
  add makes a fresh cart. A line can only be changed through the visitor's own cart.
- Nothing price-like is read from the request. `price_at_add` only drives the "price went up /
  dropped since you added it" notice; every view re-prices from the DB.
- Lines that stop being buyable (enquiry-only, archived option, minimum raised…) stay visible,
  greyed with the reason, and are left out of the totals; checkout is disabled until removed.
- Adding to an existing line checks the minimum against the new total. Blank qty (card button) =
  max(minimum, one step). Up to 60 lines (= `OrderService::MAX_ITEMS`).
- **GST in the bag (and on the product page):** added only when prices exclude tax **and**
  `BUSINESS_GSTIN` is set, because `OrderService::create` refuses GST without a GSTIN. S4 checkout
  must use `Cart::taxRate()` for `gst_enabled` / `gst_rate` so the bag total = the order total.
- Until S4 registers `store.checkout`, the bag's button is **Order this bag on WhatsApp** (lines in
  the message) and "Buy now" lands on `/cart`.
- Calculator: qty derived only for sq ft products or boxes whose `pack_unit` is sq ft; per-piece /
  slab stones say "Ask us". `/cart/add` validates the posted qty again.
- Saved: still the guest localStorage list (`ms.saved`, slugs); the server only renders the cards.
  `customer_saved_items` + merge come with S5. Cart abandon cron is S6.

**Verified:** SQLite full suite 1048 assertions; curl smoke on a scratch DB copy (JSON add, plain
form add → back to the product page, update, CSRF 419, foreign Referer → `/cart`). No browser run
(owner asked to skip Playwright for S3).

## Default images

When the catalogue has no picture yet, `App\Services\Store\DefaultImages` fills in stock photos from
`public/assets/image/defaults/` (`<key>.jpg` 1600w, `<key>-sm.jpg` 600w, downloaded from the design
mockups in `html/storefront`). Used for: hero slides when no featured product has a photo, category /
room covers (matched by slug keyword: living, bath, kitchen, wall, floor…), brand pictures, the
showroom block and the shop header. Real uploaded media always wins. Product cards keep the stone
placeholder so a product never shows a photo that is not of that stone.
