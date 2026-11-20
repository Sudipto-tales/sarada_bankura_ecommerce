#!/usr/bin/env bash
# Build the admin Tailwind CSS bundle.
#   bin/build-css.sh          one-off minified build
#   bin/build-css.sh --watch  rebuild on change (dev)
set -e
cd "$(dirname "$0")/.."

CLI=tools/tailwind/tailwindcss
VERSION=v3.4.17

if [ ! -x "$CLI" ]; then
  echo "Tailwind CLI not found — downloading $VERSION standalone binary…"
  mkdir -p tools/tailwind
  curl -sSL -o "$CLI" "https://github.com/tailwindlabs/tailwindcss/releases/download/$VERSION/tailwindcss-linux-x64"
  chmod +x "$CLI"
fi

if [ "$1" = "--watch" ]; then
  exec "$CLI" -c tailwind.config.js -i resources/css/app.css -o public/assets/css/app.css --watch
else
  "$CLI" -c tailwind.config.js -i resources/css/app.css -o public/assets/css/app.css --minify
  echo "Built public/assets/css/app.css"
fi
