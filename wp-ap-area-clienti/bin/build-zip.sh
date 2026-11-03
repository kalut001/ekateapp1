#!/usr/bin/env bash
# Crea build/ap-area-clienti-<versione>.zip con la sola cartella del plugin (niente test o strumenti).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VER="$(sed -n "s/^define( 'APAC_VERSION', '\([0-9.]*\)' );$/\1/p" "$ROOT/ap-area-clienti/ap-area-clienti.php")"
[ -n "$VER" ] || { echo "Versione non trovata"; exit 1; }
for f in "$ROOT"/ap-area-clienti/*.php "$ROOT"/ap-area-clienti/includes/*.php; do php -l "$f" > /dev/null; done
mkdir -p "$ROOT/build"
OUT="$ROOT/build/ap-area-clienti-$VER.zip"
rm -f "$OUT"
( cd "$ROOT" && zip -qr -X "$OUT" ap-area-clienti -x '*.DS_Store' )
echo "$OUT"
