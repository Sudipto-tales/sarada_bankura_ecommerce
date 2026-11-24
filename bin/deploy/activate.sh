#!/usr/bin/env bash
#
# Server-side half of the production deploy (called by .github/workflows/deploy.yml).
#
#   bash releases/<id>/bin/deploy/activate.sh <id>
#
# Layout under $DEPLOY_PATH:
#   releases/<id>/        one directory per deploy (code + vendor, uploaded by rsync)
#   current -> releases/<id>
#   public_html -> current/public        (Hostinger's document root)
#   shared/.env                           production secrets (never in git or GitHub)
#   shared/storage/{logs,media,imports,uploads}
#   shared/uploads/                       -> releases/<id>/public/assets/uploads
#   shared/backups/                       database dumps taken before each migrate
#
# Nothing the visitor sees changes until the `current` symlink is switched. If migrate
# fails the old release keeps serving; if the health check fails after the switch the
# previous release is put back automatically.

set -Eeuo pipefail

RELEASE_ID="${1:?usage: activate.sh <release-id>}"
DEPLOY_PATH="${DEPLOY_PATH:?DEPLOY_PATH not set}"
PHP_VERSION="${PHP_VERSION:-8.3}"
HEALTHCHECK_URL="${HEALTHCHECK_URL:-}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
KEEP_BACKUPS="${KEEP_BACKUPS:-10}"

PHP="/opt/alt/php${PHP_VERSION//./}/usr/bin/php"
[ -x "$PHP" ] || PHP="$(command -v php)"

RELEASES="$DEPLOY_PATH/releases"
SHARED="$DEPLOY_PATH/shared"
RELEASE="$RELEASES/$RELEASE_ID"
CURRENT="$DEPLOY_PATH/current"
WEBROOT="$DEPLOY_PATH/public_html"

log()  { printf '\033[36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[33m!!\033[0m %s\n' "$*"; }
die()  { printf '\033[31mxx\033[0m %s\n' "$*" >&2; exit 1; }

[ -d "$RELEASE" ] || die "release directory $RELEASE not found"
[ -f "$SHARED/.env" ] || die "$SHARED/.env missing: create it first (see .env.production.example)"

PREVIOUS=""
if [ -L "$CURRENT" ]; then
    PREVIOUS="$(readlink -f "$CURRENT")"
fi

# ---------------------------------------------------------------------------
log "Release $RELEASE_ID  (php: $("$PHP" -r 'echo PHP_VERSION;'))"

# 1. Shared directories + links -------------------------------------------------
mkdir -p "$SHARED"/storage/{logs,media,imports,uploads} "$SHARED/uploads" "$SHARED/backups"
chmod 700 "$SHARED/backups"

ln -sfn "$SHARED/.env" "$RELEASE/.env"

mkdir -p "$RELEASE/storage/cache"            # per release: config cache + app cache start clean
for d in logs media imports uploads; do
    rm -rf "${RELEASE:?}/storage/$d"
    ln -sfn "$SHARED/storage/$d" "$RELEASE/storage/$d"
done

# First deploy: seed shared uploads with whatever the repo ships (.gitkeep folders).
if [ -d "$RELEASE/public/assets/uploads" ] && [ ! -L "$RELEASE/public/assets/uploads" ]; then
    cp -an "$RELEASE/public/assets/uploads/." "$SHARED/uploads/" 2>/dev/null || true
    rm -rf "${RELEASE:?}/public/assets/uploads"
fi
ln -sfn "$SHARED/uploads" "$RELEASE/public/assets/uploads"

echo "$RELEASE_ID" > "$RELEASE/REVISION"

cd "$RELEASE"

# 2. Database backup (before any migration) -----------------------------------
log "Backing up database"
CNF="$(mktemp)"; trap 'rm -f "$CNF"' EXIT
"$PHP" -r '
    $e = [];
    foreach (file($argv[1], FILE_IGNORE_NEW_LINES) as $l) {
        if (preg_match("/^\s*([A-Z_]+)\s*=\s*(.*)$/", $l, $m)) {
            $v = trim($m[2]);
            if (strlen($v) > 1 && ($v[0] === "\"" || $v[0] === "\x27") && $v[-1] === $v[0]) { $v = substr($v, 1, -1); }
            $e[$m[1]] = $v;
        }
    }
    if (($e["DB_TYPE"] ?? "") !== "mysql") { exit(3); }
    printf("[client]\nhost=%s\nport=%s\nuser=%s\npassword=\"%s\"\n",
        $e["DB_HOST"] ?? "localhost", $e["DB_PORT"] ?? "3306", $e["DB_USERNAME"] ?? "",
        addcslashes($e["DB_PASSWORD"] ?? "", "\\\""));
    fwrite(STDERR, $e["DB_DATABASE"] ?? "");
' "$SHARED/.env" > "$CNF" 2> "$CNF.db" || die "shared/.env is not a MySQL config"
DB_NAME="$(cat "$CNF.db")"; rm -f "$CNF.db"
chmod 600 "$CNF"

BACKUP="$SHARED/backups/${DB_NAME}-${RELEASE_ID}.sql.gz"
mysqldump --defaults-extra-file="$CNF" --single-transaction --quick --routines --no-tablespaces "$DB_NAME" \
    | gzip -c > "$BACKUP"
chmod 600 "$BACKUP"
log "  -> $(basename "$BACKUP") ($(du -h "$BACKUP" | cut -f1))"

# 3. Migrate + optimize (old release still live) -------------------------------
log "Running migrations"
"$PHP" vayu migrate || die "migration failed: live site untouched, backup at $BACKUP"

log "Optimizing"
"$PHP" vayu optimize

# 4. Switch ---------------------------------------------------------------------
log "Switching current -> $RELEASE_ID"
ln -sfn "$RELEASE" "$DEPLOY_PATH/current.new"
mv -Tf "$DEPLOY_PATH/current.new" "$CURRENT"

# One-time: make public_html point at current/public (old contents kept aside).
if [ ! -L "$WEBROOT" ]; then
    if [ -d "$WEBROOT" ] && [ -n "$(ls -A "$WEBROOT" 2>/dev/null)" ]; then
        mv "$WEBROOT" "$DEPLOY_PATH/public_html.before-$RELEASE_ID"
        warn "old public_html moved to public_html.before-$RELEASE_ID"
    else
        rm -rf "$WEBROOT"
    fi
    ln -sfn "$CURRENT/public" "$WEBROOT"
    log "public_html -> current/public"
fi

# 5. Health check (rolls back on failure) ---------------------------------------
rollback() {
    if [ -n "$PREVIOUS" ] && [ -d "$PREVIOUS" ]; then
        warn "Rolling back to $(basename "$PREVIOUS")"
        ln -sfn "$PREVIOUS" "$DEPLOY_PATH/current.new"
        mv -Tf "$DEPLOY_PATH/current.new" "$CURRENT"
    else
        warn "No previous release to roll back to"
    fi
    warn "Database was NOT rolled back. Backup: $BACKUP"
    exit 1
}

if [ -n "$HEALTHCHECK_URL" ]; then
    log "Health check $HEALTHCHECK_URL"
    ok=""; seen=""
    for i in $(seq 1 24); do                         # up to ~2 min (PHP realpath cache)
        body="$(curl -fsS --max-time 10 "$HEALTHCHECK_URL" 2>/dev/null || true)"
        if printf '%s' "$body" | grep -q '"status":"ok"'; then
            ok=1
            if printf '%s' "$body" | grep -q "\"release\":\"$RELEASE_ID\""; then seen=1; break; fi
        fi
        sleep 5
    done
    [ -n "$ok" ] || { warn "health check failed: $body"; rollback; }
    if [ -n "$seen" ]; then
        log "  -> healthy, serving $RELEASE_ID"
    else
        warn "  -> healthy, but still reporting the previous release (PHP path cache); it will switch shortly"
    fi
fi

# 6. Cleanup ---------------------------------------------------------------------
log "Cleaning up (keep $KEEP_RELEASES releases, $KEEP_BACKUPS backups)"
ls -1dt "$RELEASES"/*/ 2>/dev/null | tail -n +"$((KEEP_RELEASES + 1))" | while read -r old; do
    [ "$(readlink -f "$old")" = "$(readlink -f "$CURRENT")" ] && continue
    rm -rf "$old"
done
ls -1t "$SHARED"/backups/*.sql.gz 2>/dev/null | tail -n +"$((KEEP_BACKUPS + 1))" | xargs -r rm -f

log "Deployed $RELEASE_ID"
