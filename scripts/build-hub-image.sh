#!/usr/bin/env sh
# Build a production hub image for imwald or gitcitadel (same Dockerfile, different UNFOLD_SITE).
set -eu

if [ "$#" -lt 1 ]; then
  echo "Usage: $0 <imwald|gitcitadel> [docker build options…]" >&2
  exit 1
fi

SITE="$1"
shift

case "$SITE" in
  imwald|gitcitadel) ;;
  *)
    echo "Unknown site: $SITE" >&2
    exit 1
    ;;
esac

REGISTRY="${REGISTRY:-silberengel/unfold}"
PLATFORM="${PLATFORM:-linux/amd64}"

ROOT="$(CDPATH= cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

docker build \
  --platform "$PLATFORM" \
  --target frankenphp_prod \
  --build-arg "UNFOLD_SITE=$SITE" \
  -t "${REGISTRY}:${SITE}" \
  "$@" \
  .

echo "Built ${REGISTRY}:${SITE}"
