# Storefront

**S1–S5 built and deployed (2026-11-26); S6 to do.** The mockups in `html/storefront/` were
converted to server-rendered PHP views in `resources/views/store/`. The store reuses the
catalogue, media, reviews, customers, orders and payments modules.

## Pages

| Page | Route | Login |
| --- | --- | --- |
| Home | `/` | — |
| Shop | `/shop`, `/shop/{space}`, `/shop/{space}/{room}` | — |
| Product | `/product/{slug}?v={SKU}` | — |
| Brands / policies / contact | `/brands`, `/policy[/{section}]`, `/contact`, `POST /enquiry` | — |
| Bag / calculator / saved | `/cart`, `/calculator`, `/saved` | — (saved merges at sign-in) |
| Checkout | `/checkout` | **required** (guest → `/signin?next=/checkout`, bag kept) |
| Order / pay (token pages) | `/order/{token}`, `/pay/{token}` | — |
| Track | `/track` (order number + mobile) | — |
| Sign in / register / reset | `/signin` | — |
| Account | `/account[/orders|addresses|profile]` | required |
| Review form | `/review/{slug}` | — |

Admin: `/admin/enquiries` inbox. Staff never sign in on the storefront.

## Owner decisions

- No compare feature, no "Shop by colour" home section, full-width layout (fluid gutters to 2000 px).
- **Out of stock stays buyable** with a "Made to order" badge (low stock = "Few left"). Orders
  never check stock.
- **Checkout needs an account**; everything else works as a guest.
- `STOREFRONT_ENABLED=false` shows the coming-soon page; a signed-in admin sees a **preview**
  (`no-store`, `noindex`, copper preview bar) instead of using a preview cookie. Production has
  the store switched on.

## Who sees what (one rule, everywhere)

```
listed   = status IN ('active','coming_soon')                (draft / archived never public)
price    = shown only if show_price = 1 AND is_enquiry_only = 0
buyable  = Purchasable::check(): active, not enquiry-only, price shown and > 0,
           and an active variant chosen when the product has variants
coming_soon → enquiry / notify, no bag          enquiry-only → enquiry form instead of Add to bag
tags     → only purpose = discovery AND status = active
reviews  → published only
```

Price = variant price ?? product price (the same rule as `OrderService::cleanItems`). Nothing
price-like is ever read from the request.

## Catalogue (S1–S2)

- `Store\Catalog` (read-only): `chrome()` (menu, cached `STOREFRONT_CACHE_TTL`), `cards()`,
  `home()`, `brands()`, `reviewSummary()`. `Store\View::page()` wraps views in
  `store/layouts/app.php` or `minimal.php`; helpers `store_card`, `store_img`, `store_inr`… in
  `app/Services/Store/helpers.php`. `Store\Context` = open / previewing / customer / bag count.
- **Shop filtering** (`Store\Listing`): one cached *facet index* (a small row per listed product)
  is filtered, counted and sorted in PHP, so nothing from the URL reaches SQL. Query string: `q`,
  `attr[code][]`, `opt[group][]`, `brand[]`, `tag[]`, `min`, `max`, `avail[]`, `offer=1`,
  `sort` (featured|best|rating|low|high|new), `page` (24 per page). OR inside a facet, AND across
  facets; each facet's counts ignore its own filter. Option groups with an attribute's name
  (Finish, Thickness, Size, Grade) merge into that facet. Colour values group by
  `attribute_values.family`. Price filter/sort use the cheapest active variant. "Imported / Indian"
  are category pages, not a filter.
- **Product page** (`Store\ProductPage`): variants + option map (inactive combinations struck,
  archived never shown), gallery incl. variant images, specs with variant overrides, return policy,
  reviews, related products, JSON-LD. Works without JS (server-picked variant, `?v=SKU` links,
  `<noscript>` select).
- **Enquiries** (`Store\Enquiries`, `ENQ/26-27/0001`): honeypot + signed time stamp (3 s – 2 days)
  + 6 per IP per hour; the same mobile/text/product within 24 h is folded into the first. The
  mobile links or creates a customer. Notification `enquiry.received`. Admin inbox: status tabs,
  search, drawer with WhatsApp / call / customer / product links and **Create order**.
- **Default images** (`Store\DefaultImages`, `public/assets/image/defaults/`): stock photos for
  hero, category/room covers, brands and the showroom block when nothing is uploaded. Real media
  always wins; product cards never show a photo that isn't that stone.

## Bag & calculator (S3)

- `Store\Cart` is the only writer of `carts` / `cart_items`. The `ms_cart` cookie (HttpOnly,
  SameSite=Lax, 30 days) and the cart row are created on the **first add**, never on a visit; an
  unknown cookie token is never adopted.
- Every view re-prices from the DB; `price_at_add` only drives a "price changed" notice. Lines
  that stop being buyable stay visible, greyed with the reason, and block checkout until removed.
- Qty: > 0, rounded **up** to `order_step`, ≥ minimum, ≤ 9,999,999; max 60 lines.
- GST is shown only when prices exclude tax **and** `BUSINESS_GSTIN` is set
  (`Cart::taxRate()`, same rule as orders).
- Calculator: buyable stones embedded as JSON (`Store\Calculator::stones()`); boxes are worked out
  only for sq ft products or boxes whose pack unit is sq ft, otherwise "Ask us". `/cart/add`
  re-checks the qty.

## Checkout (S4)

```
/checkout (signed in) ─► POST /checkout/start
   Checkout::buildOrderInput()  items + prices from Cart::lines(), GST from Cart::taxRate(),
                                channel online / website; client price / discount / customer ignored
   PayWindow::forCheckout()     attempt with cart snapshot, 6-min window (see payments.md)
 ── customer pays, submits payer name + UTR / screenshot ──
   order created (pending, payment to verify)  ─► POST /checkout/done ─► Cart::convert() ─► /order/{token}
```

- The whole bag must be buyable. Pressing Place order again (or Try again) closes the previous
  open attempt first.
- Both CGST + SGST and IGST rows are rendered; JS shows one by state. State is a select of the 36
  states / UTs (must match `business.address.state` exactly for CGST + SGST). Pincode autofill via
  `/checkout/pincode/{code}` (throttled).
- Paid but never reached `/checkout/done`: the next `/cart` or `/checkout` visit finishes it. If
  the payment was kept but the order couldn't be created (bag changed while paying), the bag stays
  and the customer is told staff will call.
- No JS: field errors still come back inline, but the UPI window needs JS (WhatsApp offered). No
  UPI account configured → WhatsApp order link instead.
- Customer's checkout note is stored as `orders.buyer_note` (`customer_note` is *our* note to them).
- **Track:** order number (any case, spaces ignored) + mobile → `/order/{token}`; the same message
  for any mismatch; 10 lookups / hour / IP.
- `/order/{token}` uses the store layout, never shows the delivery OTP, and links "Report a
  problem" to WhatsApp (returns are raised by staff).

## Accounts (S5)

- `Store\CustomerAuth` is the only writer of storefront `users`, `customer_auth_codes` and
  `customer_remember_tokens`. Migration 000017 made `users.email` nullable (phone-only accounts) and
  added `users.session_version`.
- Login ID = mobile or email. 5 wrong passwords per login ID / 20 per IP in 15 min; the same
  message for unknown account and wrong password. Registration 10 / hour / IP.
- Session regenerated on every sign-in; a password change or reset bumps `session_version` and
  deletes all remember tokens. Remember me = `ms_remember` cookie `selector:validator`, only the
  hash stored, 30 days, rotated on use.
- Reset code: 6 digits, hashed, 15 min, 5 tries, one use; 3 per hour per email, 10 per IP; the same
  answer whether the email exists. Without `MAIL_*` the page says "message us on WhatsApp".
  `phone_verified_at` stays NULL until WhatsApp phone OTP exists.
- `next` accepts same-site paths only (not `/signin` or `/admin`).
- **Bag merge** (`Cart::adopt`): at sign-in this browser's bag takes in the account's open bag
  (same line → quantities added). Sign-out forgets the cookie; the bag stays on the account.
- **Saved** (`Store\Saved`, per product): guests keep a localStorage list (`ms.saved`), merged at
  sign-in; signed-in hearts post `/saved/toggle`.
- **Addresses** go through `CustomerService` (ownership checked); checkout offers a saved-address
  picker and forces the account's phone.

## Data (migrations 000016–000017)

New tables: `carts`, `cart_items`, `customer_saved_items`, `enquiries`, `customer_auth_codes`,
`customer_remember_tokens`. New columns: `tags.icon`, `product_pricing.min_order_qty / order_step`,
`product_variants.pack_qty / pack_unit / min_order_qty`, `orders.buyer_note`,
`attribute_values.family`. Media role `room` (hover image, hero). After migrating, run
`php vayu db:seed StorefrontSeeder` (tag icons + colour families, fills NULLs only).

## Assets

`tailwind.store.config.js` + `resources/css/store.css` → `public/assets/css/store.css` (built by
`bin/build-css.sh` with the admin CSS). Palette cream / ink / teal `#1E6E74` / copper, Fraunces +
Manrope, Lucide 0.544.0 — all vendored under `public/assets/vendor/`. `store.css` has
`[hidden]{display:none!important}` because Tailwind display classes beat the `hidden` attribute.
JS: `public/assets/js/store.js` (+ `pay-window.js`).

## S6 — still to do

- Admin: category cover uploader, tag icon picker, min qty / order step / per-variant pack in the
  wizard and importer, Room in the gallery role picker, `buyer_note` on the order list,
  link a converted order to an enquiry, "reset customer password" on the customer page.
- Reviews: set `is_verified_purchase` when the phone matches a delivered order of that product;
  serve photos of published reviews publicly.
- `sitemap.xml`, `robots.txt`, meta / OG tags.
- Cart-abandon cron (idle > 30 days → `abandoned`).
- MySQL verification run + a browser check at desktop and 375 px.

Design gaps still undecided by the owner: colour values are stone names (families partly cover
it), sub-categories overlap the `application` attribute, reviews have no verified badge yet.
