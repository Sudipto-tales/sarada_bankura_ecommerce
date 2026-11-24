#!/usr/bin/env bash
# Install this project's cron jobs (idempotent):
#   inventory:check   every 15 min — stock alerts + notification cleanup
#   payments:expire   every 5 min  — mark payment windows that ran out as failed attempts
#
#   bin/install-cron.sh            install any job that is not present yet
#   bin/install-cron.sh --remove   remove the jobs this project installed
#   bin/install-cron.sh --show     print the current crontab and exit
#
# Safe to run on every deploy: each job has its own marker and is only added once.
set -e

# Resolve the project root (one level up from this script) as an absolute path.
PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"

# Prefer a full PHP path so cron's minimal PATH can find it; fall back to bare php.
PHP_BIN="$(command -v php || true)"
[ -n "$PHP_BIN" ] || PHP_BIN="php"

# name|schedule|command — the marker "# vayu:<name> (<dir>)" finds (and removes) exactly our line.
JOBS=(
  "inventory-check|*/15 * * * *|vayu inventory:check --quiet"
  "payments-expire|*/5 * * * *|vayu payments:expire --quiet"
)

# Read the existing crontab (empty if the user has none yet).
current="$(crontab -l 2>/dev/null || true)"

case "$1" in
  --show)
    echo "$current"
    exit 0
    ;;
  --remove)
    removed=0
    for job in "${JOBS[@]}"; do
      IFS='|' read -r name _ _ <<< "$job"
      marker="# vayu:$name ($PROJECT_DIR)"
      if printf '%s\n' "$current" | grep -Fq "$marker"; then
        current="$(printf '%s\n' "$current" | grep -Fv "$marker" || true)"
        echo "Removed $name cron job."
        removed=1
      fi
    done
    [ "$removed" = 1 ] && printf '%s\n' "$current" | grep -v '^$' | crontab - || echo "No cron jobs from this project found — nothing to remove."
    exit 0
    ;;
esac

added=0
for job in "${JOBS[@]}"; do
  IFS='|' read -r name schedule cmd <<< "$job"
  marker="# vayu:$name ($PROJECT_DIR)"
  if printf '%s\n' "$current" | grep -Fq "$marker"; then
    echo "$name cron job already installed — skipping."
    continue
  fi
  line="$schedule cd $PROJECT_DIR && $PHP_BIN $cmd >> storage/logs/cron.log 2>&1 $marker"
  current="$(printf '%s\n%s\n' "$current" "$line")"
  echo "Adding $name: $line"
  added=1
done

if [ "$added" = 1 ]; then
  printf '%s\n' "$current" | grep -v '^$' | crontab -
  echo "Crontab updated."
fi
