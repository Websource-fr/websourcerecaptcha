#!/usr/bin/env bash
#
# Contrôles qualité du module websourcerecaptcha (utilisé par la CI et par build-zip.sh).
#
#   scripts/check-release.sh [DOSSIER] [--no-lint] [--repo]
#
#   DOSSIER    racine à contrôler (défaut : racine du dépôt)
#   --no-lint  ne lance pas php -l
#   --repo     mode dépôt : ignore les dossiers non livrés (.git, .github, scripts, docs, dist,
#              vendor, node_modules) pour la vérification des index.php
#
# Vérifie :
#   1. présence d'un index.php dans chaque dossier livré ;
#   2. absence de clé reCAPTCHA réelle (motif 6L + 38 caractères), hors exemples factices
#      « 6Lxxxxxxxx… » et clés de test publiques documentées par Google ;
#   3. syntaxe PHP (php -l) de tous les fichiers .php (hors vendor/).
#
# @author    Websource
# @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)

set -euo pipefail

ROOT=""
LINT=1
REPO=0
for arg in "$@"; do
    case "$arg" in
        --no-lint) LINT=0 ;;
        --repo) REPO=1 ;;
        -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
        *) ROOT="$arg" ;;
    esac
done
if [ -z "$ROOT" ]; then
    ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fi
cd "$ROOT"

errors=0
fail() { echo "ERREUR : $*" >&2; errors=$((errors + 1)); }

# --- 1. index.php dans chaque dossier ---------------------------------------
echo "== Vérification des index.php"
prune=(-name .git)
if [ "$REPO" -eq 1 ]; then
    prune=( \( -path ./.git -o -path ./.github -o -path ./scripts -o -path ./docs \
               -o -path ./dist -o -path ./vendor -o -path ./node_modules \) )
fi
missing=0
while IFS= read -r -d '' dir; do
    if [ ! -f "$dir/index.php" ]; then
        fail "index.php manquant dans $dir"
        missing=$((missing + 1))
    fi
done < <(find . "${prune[@]}" -prune -o -type d -print0)
[ "$missing" -eq 0 ] && echo "OK"

# --- 2. clés reCAPTCHA réelles -----------------------------------------------
echo "== Recherche de clés reCAPTCHA réelles"
# Clés de test publiques de Google (https://developers.google.com/recaptcha/docs/faq) : tolérées.
ALLOW='^6LeIxAcTAAAAA(JcZVRqyHh4zIfyKM3RAtLQEPr0|GG-vFI1TnRWxMZNFuojJ4WifJWe)$'
hits="$(grep -rIoE --exclude-dir=.git --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=dist \
        '6L[0-9A-Za-z_-]{38}' . 2>/dev/null || true)"
found=0
if [ -n "$hits" ]; then
    while IFS= read -r line; do
        key="${line#*:}"
        # Exemples factices : 6Lxxxxxxxx…, 6L000000…, XXXX, etc.
        if printf '%s' "$key" | grep -qiE '(x{6,}|0{8,}|_{6,}|-{6,}|EXAMPLE|PLACEHOLDER|VOTRE|YOUR)'; then
            continue
        fi
        if printf '%s' "$key" | grep -qE "$ALLOW"; then
            continue
        fi
        fail "clé reCAPTCHA potentiellement réelle : ${line%%:*} (${key:0:6}…)"
        found=$((found + 1))
    done <<< "$hits"
fi
[ "$found" -eq 0 ] && echo "OK"

# --- 3. php -l ----------------------------------------------------------------
if [ "$LINT" -eq 1 ]; then
    echo "== php -l ($(php -r 'echo PHP_VERSION;'))"
    lint_err=0
    while IFS= read -r -d '' f; do
        if ! out="$(php -l "$f" 2>&1)"; then
            fail "$out"
            lint_err=$((lint_err + 1))
        fi
    done < <(find . \( -path ./.git -o -path ./vendor -o -path ./node_modules -o -path ./dist \) -prune \
             -o -type f -name '*.php' -print0)
    [ "$lint_err" -eq 0 ] && echo "OK"
fi

if [ "$errors" -gt 0 ]; then
    echo "Échec : $errors erreur(s)." >&2
    exit 1
fi
echo "Tous les contrôles sont passés."
