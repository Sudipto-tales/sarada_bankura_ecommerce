#!/usr/bin/env bash
# Build the Tailwind CSS bundles: admin (app.css) and storefront (store.css).
#   bin/build-css.sh                  one-off minified build of both
#   bin/build-css.sh --watch          rebuild admin on change (dev)
#   bin/build-css.sh --watch-store    rebuild storefront on change (dev)
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
elif [ "$1" = "--watch-store" ]; then
  exec "$CLI" -c tailwind.store.config.js -i resources/css/store.css -o public/assets/css/store.css --watch
else
  "$CLI" -c tailwind.config.js -i resources/css/app.css -o public/assets/css/app.css --minify
  echo "Built public/assets/css/app.css"
  "$CLI" -c tailwind.store.config.js -i resources/css/store.css -o public/assets/css/store.css --minify
  echo "Built public/assets/css/store.css"
fi
