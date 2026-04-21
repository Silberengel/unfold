#!/usr/bin/env bash
# Regenerate assets/theme/local/og-image.jpg (1200×630, standard OG; JPEG for smaller file than PNG24).
# Layout: ~45% left = painting full-bleed (no card frame); ~55% right = warm panel + type.
# Soft gradient seam (no hard divider); headline + subheading + description from config/unfold.yaml.
# Bottom-right: default theme mark (favicon) at reduced opacity.
# Requires: ImageMagick (convert), fold. Run from repository root:
#   ./scripts/build-og-image.sh

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

YAML="${ROOT}/config/unfold.yaml"
LOGO="${ROOT}/assets/laeserin_logo.png"
DEFAULT_MARK="${ROOT}/assets/theme/default/icons/favicon-96x96.png"
OUT="${ROOT}/assets/theme/local/og-image.jpg"
FONT_TITLE="/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
FONT_SUB="/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
FONT_BODY="/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"

W=1200
H=630
# Safe inset for *type* and corner mark (previews crop edges); painting still bleeds left.
PAD=48
# Left column width (45%).
LW=$((W * 45 / 100))
# Text starts after seam blend region.
TEXT_X=618
# Vertical start for headline (shifted down for balance; sub/body computed from headline lines).
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

mkdir -p "$(dirname "$OUT")"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

mapfile -t _OG_LINES < <(fold -s -w 44 <<<"$DESC")
DESC_MULTILINE=$(printf '%s\n' "${_OG_LINES[@]}")

mapfile -t _HL_LINES < <(fold -s -w 22 <<<"$HEADLINE")
HEAD_MULTILINE=$(printf '%s\n' "${_HL_LINES[@]}")

# Right panel: deeper warm linen (reads better on dark-mode surfaces than very pale beige).
CANVAS='#d8cdc0'
convert -size "${W}x${H}" xc:"$CANVAS" "$TMP/canvas.png"

# Left: full-bleed cover crop; richer reds / saturation, slightly darker mids for depth.
convert "$LOGO" -fuzz 8% -trim +repage \
  -resize "${LW}x${H}^" -gravity center -extent "${LW}x${H}" \
  -gamma 0.96 -modulate 93,138,104 -unsharp 0x0.6+0.52+0.014 \
  "$TMP/left.png"

convert "$TMP/canvas.png" "$TMP/left.png" -geometry +0+0 -compose over -composite "$TMP/base.png"

# Full-width wash: transparent at the far left → gentle panel tone on the right.
# Horizontal blur removes a visible “stripe” at the old painting/canvas boundary (LW).
# Text is drawn after this step so it stays crisp on top.
# Lighter wash than before so bodice reds survive; tint anchors toward the darker canvas.
convert -size "${W}x${H}" 'gradient:rgba(216,205,192,0)-rgba(175,158,142,0.26)' "$TMP/seam_raw.png"
# Blur mostly horizontally (rotate trick) so the ramp has no sharp column.
convert "$TMP/seam_raw.png" -rotate 90 -blur 0x42 -rotate -90 "$TMP/seam_blur.png"
convert "$TMP/base.png" "$TMP/seam_blur.png" -compose over -composite "$TMP/blended.png"

# Stack subheading / body under multi-line headline.
HL_COUNT=${#_HL_LINES[@]}
LINE_H=58
SUB_Y=$((TITLE_Y + HL_COUNT * LINE_H + 20))
BODY_Y=$((SUB_Y + 42))

# Type colours tuned for the darker panel.
convert "$TMP/blended.png" \
  -font "$FONT_TITLE" -pointsize 52 -fill '#14100e' -annotate +${TEXT_X}+${TITLE_Y} "$HEAD_MULTILINE" \
  -font "$FONT_SUB" -pointsize 27 -fill '#6e2218' -annotate +${TEXT_X}+${SUB_Y} "$SUBHEAD" \
  -font "$FONT_BODY" -pointsize 23 -fill '#2a221c' -interline-spacing 6 \
  -annotate +${TEXT_X}+${BODY_Y} "$DESC_MULTILINE" \
  PNG24:"$TMP/with_type.png"

# Default newsroom mark — tinted, low opacity so it belongs in the corner.
if [[ -f "$DEFAULT_MARK" ]]; then
  # Circular mark (soft vs a square stamp on the OG card).
  convert "$DEFAULT_MARK" -resize 88x88 \
    \( -size 88x88 xc:none -fill white -draw "circle 44,44 44,0" \) \
    -compose DstIn -composite -alpha set \
    -modulate 105,85,95 \
    -channel A -evaluate multiply 0.48 +channel \
    "$TMP/mark.png"
  convert "$TMP/with_type.png" \( "$TMP/mark.png" \) -gravity SouthEast -geometry +${PAD}+${PAD} -compose over -composite "$TMP/marked.png"
else
  cp "$TMP/with_type.png" "$TMP/marked.png"
fi

# Global: a touch darker + more saturated so the whole card holds up on dark UI chrome.
# JPEG (not PNG24): social previews are fine with lossy compression; much smaller on-disk.
convert "$TMP/marked.png" -modulate 96,118,102 -unsharp 0x0.55+0.48+0.012 \
  -quality 86 -sampling-factor 4:2:0 -strip "$OUT"

rm -f "${ROOT}/assets/theme/local/og-image.png"

echo "Wrote $OUT ($(wc -c <"$OUT") bytes)"
