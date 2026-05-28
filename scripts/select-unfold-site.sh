#!/usr/bin/env sh
# Activate a site profile: config/unfold.yaml + assets/theme/local + site logo.
# Used locally (make use-site), in dev Docker (UNFOLD_SITE), and prod image build (Dockerfile UNFOLD_SITE).
set -eu

ROOT="$(CDPATH= cd "$(dirname "$0")/.." && pwd)"
SITE="${1:-${UNFOLD_SITE:-imwald}}"

case "$SITE" in
  imwald|gitcitadel) ;;
  *)
    echo "select-unfold-site: unknown site '$SITE' (expected imwald or gitcitadel)" >&2
    exit 1
    ;;
esac

CONFIG_SRC="$ROOT/config/sites/${SITE}.yaml"
THEME_SRC="$ROOT/assets/theme/sites/${SITE}"
LOGO_SRC="$ROOT/assets/sites/${SITE}/laeserin_logo.png"

if [ ! -f "$CONFIG_SRC" ]; then
  echo "select-unfold-site: missing $CONFIG_SRC" >&2
  exit 1
fi
if [ ! -d "$THEME_SRC" ]; then
  echo "select-unfold-site: missing theme dir $THEME_SRC" >&2
  exit 1
fi

cp "$CONFIG_SRC" "$ROOT/config/unfold.yaml"

LOCAL="$ROOT/assets/theme/local"
mkdir -p "$LOCAL/icons"
for entry in "$LOCAL"/* "$LOCAL"/.[!.]*; do
  [ -e "$entry" ] || continue
  base=$(basename "$entry")
  [ "$base" = ".gitignore" ] && continue
  rm -rf "$entry"
done
rsync -a "$THEME_SRC/" "$LOCAL/"

if [ -f "$LOGO_SRC" ]; then
  cp "$LOGO_SRC" "$ROOT/assets/laeserin_logo.png"
fi

echo "select-unfold-site: active site=$SITE"
