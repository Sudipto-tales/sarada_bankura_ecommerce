#!/usr/bin/env bash
# Install the inventory-check cron job for this project (idempotent).
#
#   bin/install-cron.sh            install the cron line if it is not present
#   bin/install-cron.sh --remove   remove the cron line this project installed
#   bin/install-cron.sh --show     print the current crontab and exit
#
# Safe to run on every deploy: it only edits the crontab the first time.
# If the inventory:check line is already present, it prints a notice and exits 0.
set -e

# Resolve the project root (one level up from this script) as an absolute path.
PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"

# Prefer a full PHP path so cron's minimal PATH can find it; fall back to bare php.
PHP_BIN="$(command -v php || true)"
[ -n "$PHP_BIN" ] || PHP_BIN="php"

# A stable marker so we can find (and later remove) exactly our line.
MARKER="# vayu:inventory-check ($PROJECT_DIR)"
SCHEDULE="*/15 * * * *"
CMD="cd $PROJECT_DIR && $PHP_BIN vayu inventory:check --quiet >> storage/logs/cron.log 2>&1"
CRON_LINE="$SCHEDULE $CMD $MARKER"

# Read the existing crontab (empty if the user has none yet).
current="$(crontab -l 2>/dev/null || true)"

case "$1" in
  --show)
    echo "$current"
    exit 0
    ;;
  --remove)
    if printf '%s\n' "$current" | grep -Fq "$MARKER"; then
      printf '%s\n' "$current" | grep -Fv "$MARKER" | crontab -
      echo "Removed inventory-check cron job."
    else
      echo "No inventory-check cron job found — nothing to remove."
    fi
    exit 0
    ;;
esac

# Idempotent install: if our marker is already there, do nothing.
if printf '%s\n' "$current" | grep -Fq "$MARKER"; then
  echo "Inventory-check cron job already installed — skipping."
  exit 0
fi

# Append our line to whatever is already in the crontab and reinstall.
printf '%s\n%s\n' "$current" "$CRON_LINE" | grep -v '^$' | crontab -
echo "Installed inventory-check cron job:"
echo "  $CRON_LINE"
