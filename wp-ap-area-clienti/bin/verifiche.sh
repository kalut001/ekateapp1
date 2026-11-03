#!/usr/bin/env bash
# Esegue tutte le verifiche e scrive build/RISULTATI-VERIFICHE.md con gli output completi.
#   1. sintassi PHP   2. PHPStan   3. PHPCS (sicurezza, baseline)
#   4. test end-to-end sul plugin del repository
#   5. test end-to-end sul plugin estratto dallo zip da installare
#   6. aggiornamento dalla 1.9.13 originale (se presente tests/fixtures/ap-area-clienti-1.9.13.zip)
#   7. mutazioni (bin/mutation-check.php): ogni controllo di sicurezza tolto deve far fallire almeno un test
# Richiede le stesse variabili di bin/test-env.sh (database di prova DEDICATO) e gli strumenti di composer install.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
OUT="$ROOT/build/RISULTATI-VERIFICHE.md"
mkdir -p "$ROOT/build"
ZIP="$("$ROOT/bin/build-zip.sh")"
VER="$(basename "$ZIP" .zip | sed 's/^ap-area-clienti-//')"
TMP="$(mktemp -d)"
ESITI=()

blocco() { # titolo, comando
	local titolo="$1"; shift
	echo "== $titolo"
	local log="$TMP/log.txt"
	( "$@" ) > "$log" 2>&1
	local rc=$?
	{
		echo
		echo "## $titolo"
		echo
		echo "Comando: \`$*\` — esito: **$([ $rc -eq 0 ] && echo SUPERATO || echo "FALLITO (codice $rc)")**"
		echo
		echo '```'
		cat "$log"
		echo '```'
	} >> "$TMP/corpo.md"
	ESITI+=("| $titolo | $([ $rc -eq 0 ] && echo superato || echo FALLITO) |")
	return 0
}

lint() { for f in ap-area-clienti/*.php ap-area-clienti/includes/*.php; do php -l "$f" | grep -v '^No syntax errors' ; php -l "$f" > /dev/null || return 1; done; echo "Nessun errore di sintassi in $(ls ap-area-clienti/*.php ap-area-clienti/includes/*.php | wc -l) file."; }
test_repo() { bin/test-env.sh && php -d memory_limit=1G tests/run.php; }
test_zip() {
	rm -rf "$TMP/zip" && mkdir "$TMP/zip" && unzip -q "$ZIP" -d "$TMP/zip" || return 1
	APAC_TEST_PLUGIN_DIR="$TMP/zip/ap-area-clienti" bin/test-env.sh && php -d memory_limit=1G tests/run.php
}
aggiornamento() {
	local orig="$ROOT/tests/fixtures/ap-area-clienti-1.9.13.zip"
	[ -f "$orig" ] || { echo "Pacchetto 1.9.13 non presente: verifica saltata"; return 0; }
	rm -rf "$TMP/v13" "$TMP/zip" && mkdir "$TMP/v13" "$TMP/zip" && unzip -q "$orig" -d "$TMP/v13" && unzip -q "$ZIP" -d "$TMP/zip" || return 1
	# 1) Installazione della 1.9.13 originale e dati creati con quella versione.
	APAC_TEST_PLUGIN_DIR="$TMP/v13/ap-area-clienti" bin/test-env.sh || return 1
	php tests/bin/upgrade-check.php prima || return 1
	# 2) Stessi file sostituiti con lo zip nuovo (server riavviato SENZA reinstallare il database).
	APAC_TEST_PLUGIN_DIR="$TMP/zip/ap-area-clienti" APAC_TEST_KEEP_DB=1 bin/test-env.sh || return 1
	php tests/bin/upgrade-check.php dopo
}

{
	echo "# Risultati delle verifiche – AP Area Clienti $VER"
	echo
	echo "- Data: $(date -u '+%Y-%m-%d %H:%M UTC')"
	echo "- Commit: $(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo n/d)$(git -C "$ROOT" diff --quiet 2>/dev/null || echo ' (con modifiche non ancora salvate)')"
	echo "- PHP: $(php -r 'echo PHP_VERSION;') · Database: $(php -r '$e=json_decode(@file_get_contents("'"$ROOT"'/.test-env/env.json"),true); if($e){$m=new mysqli($e["db"]["host"],$e["db"]["user"],$e["db"]["pass"],"",$e["db"]["port"]); echo $m->server_info;} else echo "n/d";' 2>/dev/null) · WordPress di prova: ${APAC_TEST_WP_VERSION:-6.6.2}"
	echo "- Pacchetto da installare: \`$(basename "$ZIP")\` — SHA-256 \`$(sha256sum "$ZIP" | cut -d' ' -f1)\`"
} > "$TMP/testa.md"

blocco "Sintassi PHP" lint
blocco "PHPStan livello 5" php -d memory_limit=2G vendor/bin/phpstan analyse -c phpstan.neon.dist --no-progress --no-ansi
blocco "PHPCS regole di sicurezza (baseline verificata a mano)" php bin/phpcs-check.php
blocco "Test end-to-end sul plugin del repository" test_repo
blocco "Test end-to-end sul plugin estratto dallo zip" test_zip
blocco "Aggiornamento dalla 1.9.13 originale con dati esistenti" aggiornamento
blocco "Mutazioni: controlli di sicurezza tolti uno per volta" php bin/mutation-check.php
bin/test-env.sh > /dev/null 2>&1 # Ambiente di nuovo sul plugin del repository.

{
	cat "$TMP/testa.md"
	echo
	echo "| Verifica | Esito |"
	echo "|---|---|"
	printf '%s\n' "${ESITI[@]}"
	echo
	echo "Elenco dei test (nome = cosa verifica): \`tests/cases/*.php\`. Mutazioni: \`bin/mutation-check.php\`."
	cat "$TMP/corpo.md"
} > "$OUT"
rm -rf "$TMP"
echo "Risultati: $OUT"
grep -q 'FALLITO' "$OUT" && exit 1 || exit 0
