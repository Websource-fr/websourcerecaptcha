#!/usr/bin/env bash
# Génère dist/websourcerecaptcha-guide.pdf depuis docs/guide-utilisateur.md (pandoc + Chrome headless).
set -euo pipefail
cd "$(dirname "$0")/.."
CHROME="${CHROME:-/root/.cache/ms-playwright/chromium_headless_shell-1248/chrome-headless-shell-linux64/chrome-headless-shell}"
mkdir -p dist
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
cat > "$TMP/style.css" <<'CSS'
@page { size: A4; margin: 18mm 16mm; }
body { font-family: "Open Sans", Arial, sans-serif; font-size: 10.5pt; line-height: 1.5; color: #2c2c2c; }
h1, h2, h3 { font-family: Montserrat, Arial, sans-serif; color: #1d1d1b; }
h1 { border-bottom: 3px solid #e30613; padding-bottom: 6px; }
h2 { margin-top: 1.6em; border-bottom: 1px solid #ddd; padding-bottom: 3px; page-break-after: avoid; }
code, pre { font-family: Menlo, Consolas, monospace; font-size: 9pt; background: #f4f4f4; }
pre { padding: 8px; overflow-wrap: anywhere; white-space: pre-wrap; }
table { border-collapse: collapse; width: 100%; font-size: 9.5pt; }
th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; vertical-align: top; }
th { background: #f0f0f0; }
img { max-width: 100%; border: 1px solid #ddd; page-break-inside: avoid; }
CSS
pandoc docs/guide-utilisateur.md -s --metadata pagetitle="Websource reCAPTCHA — Guide utilisateur" \
  --css "$TMP/style.css" --embed-resources --resource-path=docs -o "$TMP/guide.html"
"$CHROME" --headless --no-sandbox --disable-gpu --no-pdf-header-footer \
  --print-to-pdf="dist/websourcerecaptcha-guide.pdf" "file://$TMP/guide.html" 2>/dev/null
ls -la dist/websourcerecaptcha-guide.pdf
