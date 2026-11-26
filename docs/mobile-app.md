# Android app

**Status: phase 1 built (web side, branch `feature/app-native-feel`); phases 2–4 planned.** A Capacitor Android app for the storefront, published on
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

The worker is served extensionless so no host-level `.js` cache rule can pin an old version. It
skips any response marked `no-store` / `private` (signed-in pages, admin preview) on top of the path
list. Images are capped at 400 entries. Signing out clears the saved pages. Tests:
`tests/app_shell_test.php`.

**Not done yet (follow-up PR):** bottom sheets for the variant picker and sort.

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

- **Account deletion (Play requirement for apps with sign-up):**
  - `/account/delete` inside the account area: confirm with the password, then anonymise the user
    and their remember tokens through `Store\CustomerAuth`;
  - a public `/account-deletion` page that explains how to request deletion without the app.
  - Orders and invoices are kept for GST record-keeping. The privacy policy says this.
- **Privacy policy:** expand `/policy/privacy`. It must say what the app collects:
  - name, phone, email and addresses;
  - order and payment details (UTR, payment screenshot);
  - the device push token.
  - It must also say why, how long the data is kept, and how to delete it.
- **Reviewer access:** a demo customer account for Play's "App access" form, because checkout needs
  a sign-in.

## Phase 3: release management (admin + storefront)

**Table `app_releases`**

| Columns | |
| --- | --- |
| Version | `version_name`, `version_code` (unique; must keep increasing) |
| File | `file_path`, `size_bytes`, `sha256` |
| Notes | `changelog` |
| Update control | `min_supported_code` (forces the update for older builds) |
| State | `status` (`draft` / `published` / `archived`), `published_at`, `published_by` |
| Stats | `downloads` |

The single writer is `App\AppReleases`. Files live in `shared/storage/app/`, so deploys don't wipe
them. The Play listing URL lives in config (`APP_PLAY_URL`). Permission: `app_releases.manage`.

**Admin → App releases**
- Upload the APK, then enter the version, changelog and minimum supported code.
- The release starts as a **Draft**: only staff can download it, for testing on a phone.
- **Publish** makes it the latest release and archives the previous one.
- One APK per request (Vayu parses single uploads only), well under the 64M upload limit.

**Storefront**
- `/app` page:
  - Google Play badge (primary) and direct APK download (secondary);
  - version, size, date, changelog and SHA-256;
  - sideload install steps;
  - a QR code for desktop visitors.
- `/app/download` streams the latest published APK as
  `application/vnd.android.package-archive` and counts the download.
- `/app/version.json` returns `{latest_code, min_code, name, notes, url}`.
- A dismissible "Get the app" banner on the mobile site, plus a footer link.

## Phase 4: the Capacitor shell (`mobile/`, excluded from rsync deploys)

**Plugins**
- `@capacitor/app` handles the back button (history back; on Home, "press back again to exit") and
  `appUrlOpen` deep links.
- `@capacitor/splash-screen` is hidden from web JS on first paint, so there's no white flash.
- Also: `status-bar`, `network`, `haptics`, `share`, `keyboard`.
- `@capacitor/push-notifications` with Firebase Cloud Messaging (FCM).
- In-app updates through the Play In-App Updates API, e.g. `@capawesome/capacitor-app-update`.

**External intents:** `upi://`, `tel:`, `mailto:` and `https://wa.me` open outside the WebView.
In app mode the pay window shows a **Pay with UPI app** button in place of the QR code.

**Updates**
- If Play installed the app: use the Play In-App Updates API only.
- If the APK was sideloaded: read `/app/version.json` and link to `/app`.
- The app never downloads or installs an APK itself. Play forbids self-updating, so the app never
  asks for `REQUEST_INSTALL_PACKAGES`.

**Push notifications**
- Table `app_devices`: customer id, FCM token, platform, last seen.
- PHP sends through the FCM HTTP v1 API, using a service-account JWT signed with openssl (no SDK).
- Triggers hook into the order notifications (status change, payment verified, out for delivery).
- Push tokens live in `app_devices`, so the push code never writes to `notifications`.

**Identity:** app name and package ID, adaptive + monochrome icon and splash. **The package ID is
permanent.**

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
5. Admin ▸ App releases ▸ upload that APK + version + changelog ──► Draft ──► test ──► Publish
6. /app page and version.json update; Play users get the in-app update prompt from Play
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
