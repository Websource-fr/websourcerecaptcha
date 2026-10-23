#!/usr/bin/env bash
#
# Construit l'archive de release du module :
#   dist/websourcerecaptcha-<version>.zip  (dossier racine websourcerecaptcha/)
#
#   scripts/build-zip.sh
#
# La version est lue dans config.xml (<version>), à défaut dans websourcerecaptcha.php
# ($this->version). Les deux doivent concorder si elles sont présentes.
#
# Exclus du zip : .git, .github, scripts, dist, docs (la doc reste sur GitHub), vendor,
# node_modules, fichiers cachés (sauf .htaccess), fichiers d'IDE/OS, logs, zips.
# Conservés : code, vues, traductions, upgrade, sql, logo, README.md, LICENSE.md,
# CHANGELOG.md, composer.json.
#
# @author    Websource
# @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)

set -euo pipefail

MODULE="websourcerecaptcha"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="$ROOT/dist"

command -v zip >/dev/null || { echo "zip introuvable" >&2; exit 1; }
command -v unzip >/dev/null || { echo "unzip introuvable" >&2; exit 1; }
command -v rsync >/dev/null || { echo "rsync introuvable" >&2; exit 1; }

# --- Version ------------------------------------------------------------------
v_xml=""
v_php=""
if [ -f "$ROOT/config.xml" ]; then
    v_xml="$(sed -n 's/.*<version>[[:space:]]*\(<!\[CDATA\[\)\{0,1\}[[:space:]]*\([^]<[:space:]]*\).*/\2/p' "$ROOT/config.xml" | head -n1)"
fi
if [ -f "$ROOT/$MODULE.php" ]; then
    v_php="$(sed -n "s/.*\$this->version[[:space:]]*=[[:space:]]*['\"]\([^'\"]*\)['\"].*/\1/p" "$ROOT/$MODULE.php" | head -n1)"
fi
VERSION="${v_xml:-$v_php}"
if [ -z "$VERSION" ]; then
    echo "Impossible de lire la version (config.xml ou $MODULE.php)." >&2
    exit 1
fi
if [ -n "$v_xml" ] && [ -n "$v_php" ] && [ "$v_xml" != "$v_php" ]; then
    echo "Versions divergentes : config.xml=$v_xml, $MODULE.php=$v_php" >&2
    exit 1
fi
if ! printf '%s' "$VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.]+)?$'; then
    echo "Version invalide : $VERSION" >&2
    exit 1
fi
[ -z "$v_xml" ] && echo "Attention : config.xml absent ou sans <version>, version lue dans $MODULE.php." >&2
[ -z "$v_php" ] && echo "Attention : \$this->version introuvable dans $MODULE.php." >&2

ZIP="$DIST/$MODULE-$VERSION.zip"
echo "== Construction de $MODULE $VERSION"

# --- Copie dans un dossier temporaire ---------------------------------------
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$MODULE"

rsync -a \
    --include='.htaccess' \
    --exclude='.*' \
    --exclude='/scripts/' \
    --exclude='/dist/' \
    --exclude='/docs/' \
    --exclude='/vendor/' \
    --exclude='node_modules/' \
    --exclude='/tests/' \
    --exclude='composer.lock' \
    --exclude='package.json' \
    --exclude='package-lock.json' \
    --exclude='*.zip' \
    --exclude='*.log' \
    --exclude='*.cache' \
    --exclude='*~' \
    --exclude='*.swp' \
    --exclude='Thumbs.db' \
    --exclude='/cache/' \
    "$ROOT/" "$STAGE/$MODULE/"

# Fichiers essentiels
for f in "$MODULE.php" index.php LICENSE.md; do
    [ -f "$STAGE/$MODULE/$f" ] || echo "Attention : $f absent du module." >&2
done
for f in config.xml logo.png README.md CHANGELOG.md; do
    [ -f "$STAGE/$MODULE/$f" ] || echo "Attention : $f absent (recommandé)." >&2
done

# --- Contrôles sur le contenu livré ------------------------------------------
echo "== Contrôles du contenu livré"
bash "$ROOT/scripts/check-release.sh" "$STAGE/$MODULE"

# --- Zip ----------------------------------------------------------------------
mkdir -p "$DIST"
rm -f "$ZIP"
( cd "$STAGE" && find "$MODULE" -print | LC_ALL=C sort | zip -q -X -@ "$ZIP" )

# --- Vérification du zip -----------------------------------------------------
echo "== Vérification de l'archive"
listing="$(unzip -Z1 "$ZIP")"
if printf '%s\n' "$listing" | grep -qv "^$MODULE/"; then
    echo "ERREUR : entrées hors du dossier racine $MODULE/." >&2
    exit 1
fi
if printf '%s\n' "$listing" | grep -E '(^|/)\.[^/]+' | grep -qvE '(^|/)\.htaccess$'; then
    echo "ERREUR : fichiers cachés dans l'archive :" >&2
    printf '%s\n' "$listing" | grep -E '(^|/)\.[^/]+' | grep -vE '(^|/)\.htaccess$' >&2
    exit 1
fi
if printf '%s\n' "$listing" | grep -qE "^$MODULE/(scripts|docs|dist|vendor|node_modules|\.git|\.github)/"; then
    echo "ERREUR : dossier de dev présent dans l'archive." >&2
    exit 1
fi
CHECK="$(mktemp -d)"
trap 'rm -rf "$STAGE" "$CHECK"' EXIT
unzip -q "$ZIP" -d "$CHECK"
bash "$ROOT/scripts/check-release.sh" "$CHECK/$MODULE"

printf '%s\n' "$listing"
echo "== Archive prête : $ZIP ($(du -h "$ZIP" | cut -f1), $(printf '%s\n' "$listing" | wc -l) entrées)"
