#!/usr/bin/env bash
# Crea build/ap-area-clienti-<versione>-sviluppo.zip: plugin + test + strumenti di verifica + ultimo rapporto
# (build/RISULTATI-VERIFICHE.md, prodotto da bin/verifiche.sh). Non va installato su WordPress.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VER="$(sed -n "s/^define( 'APAC_VERSION', '\([0-9.]*\)' );$/\1/p" "$ROOT/ap-area-clienti/ap-area-clienti.php")"
OUT="$ROOT/build/ap-area-clienti-$VER-sviluppo.zip"
TMP="$(mktemp -d)"
mkdir -p "$ROOT/build" "$TMP/ap-area-clienti-sviluppo"
( cd "$ROOT" && cp -r ap-area-clienti bin tests README.md composer.json phpstan.neon.dist phpcs.xml.dist .gitignore "$TMP/ap-area-clienti-sviluppo/" )
[ -f "$ROOT/../.github/workflows/ap-area-clienti.yml" ] && mkdir -p "$TMP/ap-area-clienti-sviluppo/ci" && cp "$ROOT/../.github/workflows/ap-area-clienti.yml" "$TMP/ap-area-clienti-sviluppo/ci/"
if [ -f "$ROOT/build/RISULTATI-VERIFICHE.md" ]; then
	cp "$ROOT/build/RISULTATI-VERIFICHE.md" "$TMP/ap-area-clienti-sviluppo/"
else
	echo "ATTENZIONE: build/RISULTATI-VERIFICHE.md assente (eseguire bin/verifiche.sh)" >&2
fi
rm -f "$OUT"
( cd "$TMP" && zip -qr -X "$OUT" ap-area-clienti-sviluppo )
rm -rf "$TMP"
echo "$OUT"
