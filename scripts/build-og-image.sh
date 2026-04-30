#!/usr/bin/env bash
# Regenerate default Open Graph JPEG (1200×630): dark editorial panel + left painting.
# Writes assets/theme/default/og-image.jpg (committed default) and assets/theme/local/og-image.jpg (local override).
# Requires: ImageMagick (convert), fold. From repository root:
#   ./scripts/build-og-image.sh

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

YAML="${ROOT}/config/unfold.yaml"
LOGO="${ROOT}/assets/laeserin_logo.png"
DEFAULT_MARK="${ROOT}/assets/theme/default/icons/favicon-96x96.png"
OUT_DEFAULT="${ROOT}/assets/theme/default/og-image.jpg"
OUT_LOCAL="${ROOT}/assets/theme/local/og-image.jpg"
FONT_TITLE="/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
FONT_SUB="/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
FONT_BODY="/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"

W=1200
H=630
PAD=48
LW=$((W * 45 / 100))
TEXT_X=618
TITLE_Y=108

if [[ ! -f "$LOGO" ]]; then
  echo "Missing logo: $LOGO" >&2
  exit 1
fi

yaml_scalar() {
  local key="$1"
  grep -E "^[[:space:]]*${key}:" "$YAML" | head -1 | sed -E "s/^[[:space:]]*${key}:[[:space:]]*['\"](.*)['\"].*/\1/"
}

NAME="$(yaml_scalar name)"
DESC="$(yaml_scalar description)"
HEADLINE="$(yaml_scalar og_headline)"
SUBHEAD="$(yaml_scalar og_subheading)"
[[ -z "$HEADLINE" ]] && HEADLINE="$NAME"
[[ -z "$SUBHEAD" ]] && SUBHEAD="$NAME"

if [[ -z "$NAME" || -z "$DESC" ]]; then
  echo "Could not read name/description from $YAML" >&2
  exit 1
fi

mkdir -p "$(dirname "$OUT_DEFAULT")" "$(dirname "$OUT_LOCAL")"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

mapfile -t _OG_LINES < <(fold -s -w 44 <<<"$DESC")
DESC_MULTILINE=$(printf '%s\n' "${_OG_LINES[@]}")

mapfile -t _HL_LINES < <(fold -s -w 22 <<<"$HEADLINE")
HEAD_MULTILINE=$(printf '%s\n' "${_HL_LINES[@]}")

# Dark editorial right panel (warm charcoal, not pure black).
CANVAS='#1c1a18'
convert -size "${W}x${H}" xc:"$CANVAS" "$TMP/canvas.png"

# Left: painting — darker, slightly desaturated so it reads as “lamplight” beside the panel.
convert "$LOGO" -fuzz 8% -trim +repage \
  -resize "${LW}x${H}^" -gravity center -extent "${LW}x${H}" \
  -gamma 1.05 -modulate 78,92,98 -unsharp 0x0.5+0.45+0.012 \
  "$TMP/left.png"

convert "$TMP/canvas.png" "$TMP/left.png" -geometry +0+0 -compose over -composite "$TMP/base.png"

# Seam: transparent at left → soft lift into panel (#262320).
convert -size "${W}x${H}" 'gradient:rgba(28,26,24,0)-rgba(38,35,32,0.55)' "$TMP/seam_raw.png"
convert "$TMP/seam_raw.png" -rotate 90 -blur 0x38 -rotate -90 "$TMP/seam_blur.png"
convert "$TMP/base.png" "$TMP/seam_blur.png" -compose over -composite "$TMP/blended.png"

HL_COUNT=${#_HL_LINES[@]}
LINE_H=58
SUB_Y=$((TITLE_Y + HL_COUNT * LINE_H + 20))
BODY_Y=$((SUB_Y + 42))

# Type: warm off-white headline, sage sub, muted body (matches theme-dark.css tokens).
convert "$TMP/blended.png" \
  -font "$FONT_TITLE" -pointsize 52 -fill '#e8e1d9' -annotate +${TEXT_X}+${TITLE_Y} "$HEAD_MULTILINE" \
  -font "$FONT_SUB" -pointsize 27 -fill '#8faf8f' -annotate +${TEXT_X}+${SUB_Y} "$SUBHEAD" \
  -font "$FONT_BODY" -pointsize 23 -fill '#b7ada3' -interline-spacing 6 \
  -annotate +${TEXT_X}+${BODY_Y} "$DESC_MULTILINE" \
  PNG24:"$TMP/with_type.png"

if [[ -f "$DEFAULT_MARK" ]]; then
  convert "$DEFAULT_MARK" -resize 88x88 \
    \( -size 88x88 xc:none -fill white -draw "circle 44,44 44,0" \) \
    -compose DstIn -composite -alpha set \
    -modulate 110,90,98 \
    -channel A -evaluate multiply 0.42 +channel \
    "$TMP/mark.png"
  convert "$TMP/with_type.png" \( "$TMP/mark.png" \) -gravity SouthEast -geometry +${PAD}+${PAD} -compose over -composite "$TMP/marked.png"
else
  cp "$TMP/with_type.png" "$TMP/marked.png"
fi

# Slight depth; keep warmth (not neon).
convert "$TMP/marked.png" -modulate 98,105,100 -unsharp 0x0.5+0.42+0.01 \
  -quality 86 -sampling-factor 4:2:0 -strip "$OUT_DEFAULT"

cp "$OUT_DEFAULT" "$OUT_LOCAL"

rm -f "${ROOT}/assets/theme/local/og-image.png"

echo "Wrote $OUT_DEFAULT and $OUT_LOCAL ($(wc -c <"$OUT_DEFAULT") bytes each)"
