# Deployment

Production is one Hostinger shared-hosting account (no staging). Every merge to `main` deploys
automatically. The database is MariaDB 11.8 (hPanel calls it "MySQL").

```
feature branch ──► pull request ──► CI ──► merge to main ──► Deploy workflow ──► live
```

## CI (`.github/workflows/ci.yml`)

Runs on every pull request to `main` and again before each deploy:

1. PHP syntax check of every file, `composer validate`, production `composer install`.
2. `php tests/run.php` on SQLite.
3. Migrations on **MariaDB 11.8** and **MySQL 8.0**: fresh migrate → migrate again (must be a
   no-op) → `php vayu admin:install` → roll back everything → migrate again (proves every `down()`).

A branch cut before a CI fix must merge `main` in to pick it up.

## Deploy (`.github/workflows/deploy.yml` + `bin/deploy/activate.sh`)

1. Build the release on the runner: code + production `vendor/` (no tests, docs, `html/`, tools,
   `.env*`, SQLite files). Release id = `YYYYMMDD-HHMMSS-<sha7>` (IST).
2. `rsync` it to `releases/<id>/` over SSH.
3. On the server, `activate.sh`:
   1. links `shared/.env`, `shared/storage/*` and `shared/uploads` into the release;
   2. dumps the database to `shared/backups/` (gzip, `chmod 600`);
   3. `php vayu migrate` + `php vayu optimize` — if migrate fails, the old release keeps serving;
   4. switches the `current` symlink atomically;
   5. polls `HEALTHCHECK_URL` (`/up`, up to ~2 min) for `"status":"ok"` and the new release id —
      on failure it puts the previous release back (the **database is not rolled back**; restore
      from the backup it printed if needed);
   6. keeps the last `KEEP_RELEASES` releases and `KEEP_BACKUPS` dumps.

### Server layout (under `DEPLOY_PATH`)

```
releases/<id>/          one folder per deploy
current -> releases/<id>
public_html -> current/public        Hostinger's document root
shared/.env             production secrets — only here, never in git or GitHub
shared/storage/{logs,media,imports,uploads}
shared/uploads/         -> public/assets/uploads (product and media images)
shared/backups/         database dumps taken before each migrate
```

The account hosts other sites too: only touch this domain's folder.

### GitHub settings (`production` environment, deploys from `main` only)

- Secret: `DEPLOY_SSH_KEY`. Set it from a file (`gh secret set DEPLOY_SSH_KEY < key`); pasting
  into the web form can break the key.
- Variables: `DEPLOY_HOST`, `DEPLOY_PORT`, `DEPLOY_USER`, `DEPLOY_PATH`, `DEPLOY_KNOWN_HOSTS`,
  `APP_URL`, `HEALTHCHECK_URL`, `KEEP_RELEASES` (5), `KEEP_BACKUPS` (10), `PHP_VERSION` (8.3).

## Production `.env`

Start from `.env.production.example`. Key points:

- `APP_ENV=production`, `APP_DEBUG=false`, real `APP_URL`, fresh `APP_KEY`,
  `APP_TIMEZONE=Asia/Kolkata`, `DB_TYPE=mysql`, `DB_HOST=localhost`.
- **Quote secrets in single quotes.** phpdotenv cuts an unquoted value at `#`, which silently broke
  a database password once.
- `MAIL_*`: Hostinger SMTP (`smtp.hostinger.com`, port 465, SSL), from a `no-reply@` mailbox.
  Email invites, OTPs, password resets and payment-request emails need it.
- `BUSINESS_*` (name, address, GSTIN…) feed invoices; `ADMIN_EMAIL` / `ADMIN_PHONE` feed
  `admin:install`; `STOREFRONT_ENABLED` is the launch switch; `WHATSAPP_*` stay empty until the
  Meta account is ready (see whatsapp.md).
- After changing `.env`, run `php vayu optimize` again (the config cache freezes env values).

The server's CLI `php` is older than 8.3: run commands with the alt-PHP 8.3 binary
(`/opt/alt/php83/usr/bin/php vayu …`).

## First deploy on an empty database

```
php vayu migrate                         # done by the deploy itself
php vayu admin:install                   # roles + first Owner, password printed once
php vayu db:seed ProductCatalogSeeder
php vayu db:seed AttributeLibrarySeeder
php vayu db:seed CatalogExtrasSeeder
php vayu db:seed StorefrontSeeder
php vayu db:seed CustomerSeeder
```

Without the catalogue seeders every bulk-import row fails (no product types, units or attributes).

## Cron (hPanel → Cron Jobs; `crontab` isn't available over SSH)

```
*/15 * * * *  cd <DEPLOY_PATH>/current && <php83> vayu inventory:check --quiet
*/5  * * * *  cd <DEPLOY_PATH>/current && <php83> vayu payments:expire --quiet
```

On a VPS, `bin/install-cron.sh` adds both lines idempotently.

## PHP limits

`public/.user.ini` sets upload 64M / post 80M / 300 s / memory 256M for bulk imports (sheet +
images ZIP in one request; keep batches ≤ 250 rows). hPanel's own values on the live site are
higher (256M upload/post, 512M memory) and win. `php vayu run` passes the same limits locally.

## Before go-live checklist

- [ ] hPanel cron jobs added
- [ ] `MAIL_*` filled and a test email sent (`/admin/account` → change password sends a code)
- [ ] Storefront S6 finished (the store is already public on production: `/shop` is open to anyone)
- [ ] Uptime monitor on `/up`
- [ ] Remote MySQL access in hPanel not left "open to all"
