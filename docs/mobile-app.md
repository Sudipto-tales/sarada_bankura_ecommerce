# Android app

**Status: phases 1–4 built (branches `feature/app-native-feel`, `feature/app-skeleton-sheets`, `feature/play-prereqs`, `feature/app-releases`, `feature/app-shell`). Next: the owner's accounts (Play Console, Firebase, upload key) and the first Play submission.** A Capacitor Android app for the storefront, published on
Google Play and also offered as a direct APK download from `/app`. Releases are managed from the
admin panel.

## Approach

The app is a native Capacitor shell that loads the live storefront (`server.url`). Pricing,
checkout and payments stay server-side in one codebase, and web changes reach app users on the
next deploy. A new app build is needed only when the native shell changes.

| Option | Verdict |
| --- | --- |
| Capacitor + remote URL + service worker | **Chosen.** Native plugins, one codebase, instant web updates |
| TWA (Bubblewrap) | Lighter, but less native control (back stack, splash, intents) |
| Flutter / React Native | Full rewrite of the storefront and checkout |

Limits: the first launch needs internet (a branded offline screen ships in the app). Checkout,
pay, account and sign-in always need the network.

Play rejects apps that are "just a website" (Webview and spam / minimum functionality policy).
The app must offer real app value: push notifications for orders, offline browsing, native share,
the native back stack, haptics, the UPI app hand-off and the calculator. Push therefore ships in
v1, not later.

## Phase 1: native feel on the web side

These changes also improve the mobile website.

**Built:**

| Piece | Where |
| --- | --- |
| App detection (`MaaSaradaApp/<ver>` in the UA → `<html class="is-app">`) | `Store\AppMode` |
| Glass tab bar, offline pill, nav progress bar, pull to refresh | `partials/app-shell.php`, `appShell()` in `store.js` |
| Compact app footer, no utility bar, header bag/saved hidden in the app | `partials/footer.php`, `partials/header.php` |
| Product page: no tab bar, glass buy bar at the bottom | `product.php` (`'tabbar' => false`) |
| View Transitions, card → product image move, Speculation Rules prefetch | `store.css`, `layouts/app.php` |
| Service worker (`/service-worker`) + `/offline` page with "saved on this device" list | `AppShellController`, `resources/sw/sw.js` |
| Skeleton shop grid: placeholder cards while the next filter / sort / page loads (120 ms delay so prefetched pages don't flash it; cleared on back/forward) | `shop()` in `store.js`, `.skel*` in `store.css` |
| Bottom sheets below `lg`: sort on the shop, variant picker from the product buy bar. Drag-down / Escape / back button close them (each sheet adds a history entry) | `partials/sheet.php`, `sheet` in `store.js` |

The worker is served extensionless so no host-level `.js` cache rule can pin an old version. It
skips any response marked `no-store` / `private` (signed-in pages, admin preview) on top of the path
list. Images are capped at 400 entries. Signing out clears the saved pages. Tests:
`tests/app_shell_test.php`.

Sheets: the native sort `<select>` stays on desktop, and without JS the buy-bar link jumps to the
options table. `window.msSheet.close()` returns `true` if it closed a sheet; the phase 4 back-button
handler calls it before navigating back.

**Plan:**

- **App mode:** the app appends `MaaSaradaApp/<versionName>` to the user agent, and the server sets
  `class="is-app"` on `<html>`. App mode hides the footer, the get-the-app banner and the `/app`
  links.
- **Floating glass bottom bar:**
  - tabs: Home · Shop · Saved · Bag (count badge) · Account;
  - `backdrop-blur` with a solid fallback, safe-area padding and an animated active pill;
  - hides when scrolling down, and while typing; shows when scrolling up;
  - not shown on checkout, pay or product pages (the product page has its own buy bar).
- **Remove web tells:**
  - no tap highlight and no long-press callout;
  - `user-select: none` on UI chrome;
  - no pinch zoom (inputs stay at 16 px or larger, so no auto-zoom);
  - `overscroll-behavior: none`;
  - `theme-color` and an edge-to-edge layout (Android 15+).
- **Transitions:**
  - cross-document View Transitions;
  - Speculation Rules prefetch on touch;
  - a shared-element image move from card to product page.
- **UI:** skeleton grid on the shop page, pull to refresh, toasts, and bottom sheets for the variant
  picker and sort.
- **Offline (service worker at `/service-worker`, scope `/`; template `resources/sw/sw.js`):**

  | Request | Strategy |
  | --- | --- |
  | CSS, JS, fonts, icons | Precached, versioned per deploy |
  | Home, shop, product, brands, policy pages | Network first, then cache |
  | Images | Cache first, capped at 400 entries (oldest out) |
  | `/checkout*`, `/pay*`, `/cart*`, `/account*`, `/signin*`, `/admin*`, `/order/*`, any non-GET | Never cached |

  When offline:
  - a banner says "You're offline, showing saved pages";
  - Add to bag, enquiry and saved toggles are disabled;
  - pages that were never cached show an offline page.

## Phase 2: Play Store prerequisites (web side)

**Built** (branch `feature/play-prereqs`, tests: `tests/account_deletion_test.php`):

| Piece | Where |
| --- | --- |
| `/account/delete`: lists what is deleted and kept, warns about orders still in progress, then asks for the password and a confirm box | `AccountController::deleteForm/destroy`, `account-delete.php`; linked from Account → Profile |
| Deletion itself, in one transaction, then sign-out | `CustomerAuth::deleteAccount()` |
| Public `/account-deletion` page (the URL for Play's "Delete account" form): in-app steps, how to ask without signing in, what's deleted / kept | `PageController::accountDeletion`, `account-deletion.php` |
| Privacy policy (`/policy#privacy`; `/policy/privacy` redirects there) | `policy.php` (`$privacyUpdated` holds the date) |
| Play reviewer login: `php vayu store:demo-account --phone=<spare shop SIM>` | `StoreDemoAccountCommand` |

**What deletion does:**

| Data | Result |
| --- | --- |
| `users` row | Emptied: name → "Deleted customer", phone / email / password cleared, `status = deleted`, every session ended. The row stays as an id tombstone |
| Remember tokens, reset codes, saved stones, bag | Deleted |
| Reviews (matched by customer or phone) | Stay published as "A customer"; email / phone cleared |
| Enquiries (matched by customer or phone) | Name, phone and email cleared |
| Customer record the website created (`source = online`) | Emptied (name, phone → `deleted-<id>`, email, company, GSTIN, notes), addresses / tags / segments removed, `inactive` |
| Customer record the showroom had first | Only unlinked (`user_id`, `is_online`, marketing consent cleared). It's the shop's own file. The public page says customers can ask for it to be removed |
| Orders, items, payments, refunds, screenshots | **Kept** (GST record-keeping, about six years). Orders hold their own name / phone / address snapshot |

An `audit_logs` row (`customer_account`, `delete`, orders kept) records each deletion. The same
mobile number can register again and gets a fresh customer record. Deletion also removes every push
token of the login (`app_devices`, phase 4).

**Play Console values:**
- Privacy policy URL: `https://<domain>/policy#privacy`
- Delete account URL: `https://<domain>/account-deletion`
- App access: run `store:demo-account` on the server and paste the printed mobile and password. The
  customer is tagged **Google Play review** in admin, so reject any order it places. If a reviewer
  deletes the account, run the command again and update the form.

## Phase 3: release management (admin + storefront)

**Built** (branch `feature/app-releases`, tests: `tests/app_releases_test.php`).

**Table `app_releases`** (migration 000018). The single writer is `App\Services\AppReleases`.

| Columns | |
| --- | --- |
| Version | `version_name`, `version_code` (unique; every upload must be higher than all earlier ones) |
| File | `file_path`, `size_bytes`, `sha256` |
| Notes | `changelog` |
| Update control | `min_supported_code` (builds below it are told to update; 0 = off) |
| State | `status` (`draft` / `published` / `archived`), `published_at`, `published_by`, `uploaded_by` |
| Stats | `downloads` (public `/app/download` only) |

APKs live in `storage/uploads/app/`. That folder is already a link to `shared/storage/uploads`, so
deploys keep the files and no deploy change was needed. Files never sit under `public/`. The Play
listing URL is `APP_PLAY_URL` (`config('store.app_play_url')`); only `https://play.google.com/…`
links are used. Permission: `app_releases.manage` (sensitive, Owner by default). Every action is
audit-logged (`app_release`).

**Admin → Settings → Android app** (`/admin/app-releases`, `AppReleaseController`)
- Upload the signed release APK (not the `.aab`) with the version name, version code, minimum
  supported code and "What's new". The upload is checked: `.apk`, ZIP header, 1 KB–60 MB.
- The release starts as a **Draft**. Staff can download any release to test it on a phone; those
  downloads aren't counted.
- **Publish** makes it the public release and archives the previous one. Publishing a lower code
  than the current public release is refused, because phones can't go back.
- **Take down** archives the public release, so `/app` offers no APK until the next publish.
  Drafts and archived releases can be deleted, which deletes their file too.

**Storefront**
- `/app` page (`AppController::page`, `store/app.php`):
  - Google Play button first (when `APP_PLAY_URL` is set), direct APK second;
  - version, size, date, changelog and SHA-256;
  - sideload install steps;
  - a QR code for desktop visitors (vendored `qrcode.min.js`, loaded on this page only);
  - "Coming soon" when there's neither a release nor a Play link.
  - The Play button is styled text for now. Swap in Google's official badge image before launch
    (brand rules).
- `/app/download` streams the published APK (`App\Http\FileResponse`, which doesn't load it into
  memory) as `application/vnd.android.package-archive` and counts it. It is throttled to 20 a
  minute, kept out of prefetch and the service worker, and redirects to `/app` when nothing is
  published.
- `/app/version` returns `{latest_code, min_code, name, notes, url, size, sha256, play_url}` with
  `no-store`. It has no extension, for the same reason as `/service-worker`: no host `.json` cache
  rule can pin a stale copy.
- Both run outside the store-open switch, so installed apps always reach them.
- A dismissible "Get the app" banner under the header: mobile web, Android browsers only, not in
  the app and not on `/app`. Closing it hides it for 30 days (`ms.appBanner` in browser storage).
  There's also a footer link. Both appear only once there's a public release or a Play link.

## Phase 4: the Capacitor shell (`mobile/`, excluded from rsync deploys)

**Built** (branch `feature/app-shell`, tests: `tests/app_push_test.php`; the Android build itself runs
in `.github/workflows/android.yml`).

### The project (`mobile/`)

| Piece | Where |
| --- | --- |
| Capacitor 8 config: package `com.maasarada.store`, name "Maa Sarada", `server.url` = the live store, local `offline.html` when the first load fails | `capacitor.config.json`, `www/offline.html` |
| Native Android project (committed, regenerated parts come from `npx cap sync`) | `android/` |
| Version in one place: `version.properties` (`versionName`, `versionCode = major·10000 + minor·100 + patch`) plus the `MaaSaradaApp/<versionName>` UA token | `tools/set-version.mjs 1.2.0` (CI runs it from the tag) |
| Release signing from env only (`ANDROID_KEYSTORE_FILE` + passwords) | `android/app/build.gradle` |
| UPI / tel / mailto / sms / whatsapp links leave the WebView (UPI opens a "Pay with" chooser; no app → `noApp` event → toast); `intent:` / `file:` / `content:` links are blocked; `info()` reports the installer (Play or sideload) and version code | `MsShellPlugin.java` |
| Manifest: `INTERNET`, `POST_NOTIFICATIONS`, `<queries>` for upi / tel / mailto / sms / https, App Links for `/product/`, `/shop`, `/order/`, `/pay/`, `/account`, `/saved`, `/cart`, `/brands`, no backup, FCM icon + `orders` channel | `AndroidManifest.xml` |
| Icons: adaptive (crest on cream) + monochrome (themed icons) + round/legacy, white status-bar icon for push, splash light/dark | `tools/make-assets.php` via `npm run assets` |

Plugins: `app`, `splash-screen`, `keyboard`, `network`, `haptics`, `share`, `browser`,
`push-notifications` and `@capawesome/capacitor-app-update`. Status bar and edge-to-edge come from
Capacitor 8's built-in `SystemBars` (`insetsHandling: native`, so the site's
`env(safe-area-inset-*)` padding works), so `@capacitor/status-bar` isn't used.

### Web side (`public/assets/js/app-bridge.js`, loaded only in app mode)

The app loads the live site, so each page is a fresh JS context and the bridge re-adds its native
listeners on every page.

| Feature | Behaviour |
| --- | --- |
| Splash | Hidden on first paint (fallback: auto-hide after 3 s, e.g. on the offline screen) |
| Back button | Closes, in order: a bottom sheet (`msSheet.close()`), the pay window, the menu drawer, an app card. Then `history.back()`. On Home: "Press back again to exit" |
| Deep links | `appUrlOpen` → same-site path only |
| Share | `[data-copy-link]` / `[data-share]` buttons open the Android share sheet (the WebView has no `navigator.share`) |
| Keyboard | `html.kb-open` hides the tab bar |
| Pay window | No QR in the app; one full-width **Pay with UPI app** button (`pay-window.js`) |
| Push | See below |
| Updates | Play install → Play In-App Updates (flexible; immediate when below `min_code`). Sideloaded → `/app/version`; "New version" card (skippable) or a blocking "Please update" card below `min_code`; **Download** opens `/app` in a Custom Tab. The app never installs APKs itself |

### Push notifications

- **Table `app_devices`** (migration 000019, single writer `Push\Devices`): `user_id`, `customer_id`,
  unique `token`, `platform`, `app_version`, `last_seen_at`, `failures`.
- **Registering:** the layout adds `<meta name="ms-push">` for a signed-in customer in the app. The
  bridge then registers with FCM when permission is already granted, and `POST /app/devices` stores
  the token (once per app session; CSRF + `RequireCustomer`, throttled). A token moves to whoever
  registered it last, so a shared phone never gets the previous customer's updates. At most 10
  phones per login.
- **Asking:** never at launch. The first time a signed-in customer opens an order page, a card asks
  "Get order updates on this phone?" and only **Turn on** shows Android's permission prompt. It is
  asked once (`ms.pushAsked`).
- **Removing:** sign-out deletes this phone's token (kept in the session); account deletion deletes
  all of the login's tokens; FCM's `UNREGISTERED` / 404 deletes a token; 5 failures in a row too.
- **Sending:** `Push\Fcm`, FCM HTTP v1 with a service-account JWT signed by openssl (RS256), the
  OAuth token cached until 5 minutes before expiry, 6 s timeout. `FCM_SERVICE_ACCOUNT` unset = off.
- **Triggers** (`Push\OrderPush`, after the flow's transaction, never throws):

  | Event | Hook | Message |
  | --- | --- | --- |
  | Order placed | `PayWindow::submit` (checkout) | "Order … placed", checking the payment |
  | Payment verified / recorded | `Ledger::verify`, `Ledger::record` | amount, balance or "Fully paid" |
  | Dispatched | `OrderService::transition` | "on its way", keep the OTP ready |
  | Delivered | `OrderService::transition` | thanks, invoice on the order page |

  Tapping opens `/order/<token>`. Notifications of one order share a tag, so a newer one replaces
  the older one. Push never writes to `notifications` (the admin bell).

### App Links

`/.well-known/assetlinks.json` lists `APP_CERT_SHA256` (comma-separated; Play Console ▸ App integrity ▸
App signing key certificate, and the upload key's if you test signed builds outside Play). Empty →
`[]`, and links keep opening in the browser. The host in `AndroidManifest.xml` comes from
`@string/app_host`; change it together with `server.url` if the domain changes.

### Building (`.github/workflows/android.yml`)

| Trigger | Result |
| --- | --- |
| Pull request touching `mobile/` | Unsigned debug APK (proves it builds; push off without Firebase config) |
| Tag `app-v1.2.0` | Version 1.2.0 / code 10200, signed **AAB** (for Play) + APK, as a workflow artifact |
| Manual run, "release" ticked | Signed build with the version in `version.properties` |

Secrets: `ANDROID_KEYSTORE_BASE64`, `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS`,
`ANDROID_KEY_PASSWORD`, `GOOGLE_SERVICES_JSON` (the file's JSON text). A release build fails when any is
missing. Locally (Node 22, JDK 21, Android SDK 36): `cd mobile && npm ci && npx cap sync android &&
cd android && ./gradlew assembleDebug`.

### Before the first release (owner)

1. Play Console organisation account (D-U-N-S), Firebase project with an Android app
   `com.maasarada.store`, upload key (see *Signing* below), and the five GitHub secrets.
2. Server: the service-account JSON in `shared/`, `FCM_SERVICE_ACCOUNT` and (after Play App Signing is
   set up) `APP_CERT_SHA256` in `shared/.env`, then `php vayu optimize`.
3. A sharper icon: the crest is cut from the 560 px web logo. Put a 1024 px crest in
   `tools/make-assets.php`'s source and run `npm run assets`.
4. Tag `app-v1.0.0` and follow the release workflow below.

## Android permissions

| Permission | Why | Notes |
| --- | --- | --- |
| `INTERNET` | Load the store | Normal; no prompt |
| `ACCESS_NETWORK_STATE` | Offline banner | Normal |
| `POST_NOTIFICATIONS` | Order updates | Runtime prompt on Android 13+; ask after the first order, not at launch |
| `VIBRATE` | Haptics | Normal |
| `<queries>` for `upi`, `tel`, `mailto`, `https` (wa.me) | Find UPI / WhatsApp apps on Android 11+ | Not `QUERY_ALL_PACKAGES` (restricted) |

**Not requested:**
- storage or `READ_MEDIA_*`: the payment screenshot goes through the system photo picker, which
  needs no permission;
- camera, location, contacts;
- `REQUEST_INSTALL_PACKAGES`.

The Play Data safety form lists exactly what the privacy policy lists:
- data is encrypted in transit and can be deleted on request;
- no ads, no data sharing, no tracking SDKs.

## Requirements to build the app

**Toolchain (local machine or GitHub Actions)**

| Need | Version |
| --- | --- |
| Node + npm | 22 LTS (this machine has Node 18 and no npm) |
| Capacitor | 8 (targets API 36) |
| JDK | 21 (Temurin) |
| Android SDK | platform 36, build-tools 36, cmdline-tools; Android Studio is optional |
| SDK levels | `targetSdk 36` (Play requirement for new apps since Aug 31 2026), `minSdk 24` (Android 7) |
| Page size | 16 KB page-size compatible (AGP 8.5.1+; Capacitor ships no native `.so` files of its own) |

Recommended: build in GitHub Actions, so only a phone is needed locally.

**Signing (Play App Signing is mandatory for new apps)**
- Create an **upload key** once:
  ```
  keytool -genkeypair -v -keystore upload.jks -alias upload -keyalg RSA -keysize 4096 -validity 10000
  ```
- Google holds the **app signing key**. A lost upload key can be reset through Play support.
- Back up `upload.jks` and its passwords offline. Never commit them, because the repo is public.
- GitHub secrets, set by the owner: `ANDROID_KEYSTORE_BASE64`, `ANDROID_KEYSTORE_PASSWORD`,
  `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD`.
- Firebase: `google-services.json` as a secret for the app, and the FCM service-account JSON only in
  `shared/.env` (path) on the server.

**Accounts**
- **Google Play Console developer account**: USD 25 one-time, with identity verification.
  - **Organisation** account (recommended): needs a free D-U-N-S number for the business. It shows
    the business name and skips the testing gate below.
  - **Personal** account: production access needs a **closed test with at least 12 testers for 14
    days in a row**.
- **Android developer verification:** Google is rolling out mandatory verification for apps
  installed outside Play as well. The Play Console identity covers it. Register the package name
  there, so the sideloaded APK installs without warnings once enforcement reaches India.
- A Firebase project for FCM.

**Store listing assets**

| Asset | Requirement |
| --- | --- |
| App name | ≤ 30 characters |
| Short description | ≤ 80 characters |
| Full description | ≤ 4000 characters |
| App icon | 512 × 512 PNG, 32-bit |
| Feature graphic | 1024 × 500 |
| Phone screenshots | 2–8, 9:16 (e.g. 1080 × 1920) |

Play Console declarations:
- privacy policy URL and account deletion URL;
- Data safety form;
- content rating (IARC questionnaire);
- target audience: 18+;
- ads: none;
- App access: the demo login.

Payments are for physical goods through UPI, so Play Billing does not apply.

## Release workflow

```
1. Bump versionName; versionCode is derived from the tag and always increases
2. Push tag app-v1.2.0 ──► GitHub Action builds a signed AAB (upload key) + artifact
3. Play Console: upload the AAB ──► Internal testing ──► (personal account: closed test,
   12 testers / 14 days) ──► Production, staged rollout 20% → 50% → 100%
4. Play Console ▸ App bundle explorer ▸ download the signed universal APK
   (Google's signing key, so a sideloaded install can later update from Play and vice versa)
5. Admin ▸ Settings ▸ Android app ▸ upload that APK + version + changelog ──► Draft ──► test ──► Publish
6. /app page and /app/version update; Play users get the in-app update prompt from Play
```

- **Rollback:** Android blocks a lower versionCode. Rebuild the previous code as a new version with
  a higher code.
- **Most fixes need no release:** web-side changes ship with the normal `main` deploy.
- **Later:** automate step 3 with the Play Developer API (a service account plus an
  upload-to-Play action).

## Build order

One PR per phase: 1 → 2 → 3 → 4, then the Play submission. Web gains and the Play prerequisites go
live before the first app build.

## Decisions (owner, 2026-11-27)

1. App name "Maa Sarada", package ID `com.maasarada.store` (permanent).
2. Play account: organisation (D-U-N-S).
3. Glass bottom bar on the app **and** the mobile website.
4. Bottom bar tabs: Home · Shop · Saved · Bag · Account.
5. Push triggers for v1: order placed, payment verified, dispatched, delivered.
