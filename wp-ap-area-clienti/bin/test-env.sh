#!/usr/bin/env bash
# Prepara un WordPress di prova con il plugin e avvia il server PHP locale.
# Richiede un MySQL/MariaDB raggiungibile (vedi variabili sotto) e Composer per scaricare WordPress.
#
#   APAC_TEST_DB_HOST=127.0.0.1:3306 APAC_TEST_DB_USER=wp APAC_TEST_DB_PASS=wp APAC_TEST_DB_NAME=wp_test \
#   bin/test-env.sh            # prepara e avvia (porta 8089)
#   php tests/run.php          # esegue i test
#
# Il database indicato viene SVUOTATO: usare solo un database dedicato ai test.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_DIR="${APAC_TEST_ENV_DIR:-$ROOT/.test-env}"
WP_DIR="$ENV_DIR/wp"
PORT="${APAC_TEST_PORT:-8089}"
DB_HOST="${APAC_TEST_DB_HOST:-127.0.0.1:3306}"
DB_USER="${APAC_TEST_DB_USER:-wp}"
DB_PASS="${APAC_TEST_DB_PASS:-wp}"
DB_NAME="${APAC_TEST_DB_NAME:-wp_test}"
WP_VERSION="${APAC_TEST_WP_VERSION:-6.6.2}"

mkdir -p "$ENV_DIR"
if [ ! -f "$WP_DIR/wp-settings.php" ]; then
	echo "Scarico WordPress $WP_VERSION..."
	composer create-project -q --no-interaction --no-install johnpbloch/wordpress-core "$ENV_DIR/wp-core-tmp" "$WP_VERSION"
	mv "$ENV_DIR/wp-core-tmp" "$WP_DIR"
	rm -f "$WP_DIR/composer.json" "$WP_DIR/composer.lock"
fi

cat > "$WP_DIR/wp-config.php" <<EOF
<?php
define( 'DB_NAME', '$DB_NAME' );
define( 'DB_USER', '$DB_USER' );
define( 'DB_PASSWORD', '$DB_PASS' );
define( 'DB_HOST', '$DB_HOST' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'AUTH_KEY', 'test-k1' ); define( 'SECURE_AUTH_KEY', 'test-k2' ); define( 'LOGGED_IN_KEY', 'test-k3' ); define( 'NONCE_KEY', 'test-k4' );
define( 'AUTH_SALT', 'test-s1' ); define( 'SECURE_AUTH_SALT', 'test-s2' ); define( 'LOGGED_IN_SALT', 'test-s3' ); define( 'NONCE_SALT', 'test-s4' );
\$table_prefix = 'wp_';
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true );
define( 'APAC_TESTING', true );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
EOF

# Plugin (collegamento alla cartella del repository) e plugin di supporto ai test.
mkdir -p "$WP_DIR/wp-content/plugins" "$WP_DIR/wp-content/mu-plugins"
rm -rf "$WP_DIR/wp-content/plugins/ap-area-clienti"
ln -s "$ROOT/ap-area-clienti" "$WP_DIR/wp-content/plugins/ap-area-clienti"
cp "$ROOT/tests/fixtures/apac-test-helpers.php" "$WP_DIR/wp-content/mu-plugins/"

# Database pulito, installazione e attivazione.
php "$ROOT/tests/bin/install.php" "$WP_DIR" "$PORT"

# Server PHP (opcache spento: ogni richiesta legge i file aggiornati).
if [ -f "$ENV_DIR/server.pid" ] && kill -0 "$(cat "$ENV_DIR/server.pid")" 2>/dev/null; then
	kill "$(cat "$ENV_DIR/server.pid")"
	sleep 1
fi
( cd "$WP_DIR" && PHP_CLI_SERVER_WORKERS=6 nohup php -d opcache.enable=0 -d opcache.enable_cli=0 -S "127.0.0.1:$PORT" > "$ENV_DIR/server.log" 2>&1 < /dev/null & echo $! > "$ENV_DIR/server.pid" )
for _ in $(seq 1 30); do
	if curl -s -m 5 -o /dev/null "http://127.0.0.1:$PORT/area-riservata/"; then break; fi
	sleep 0.5
done
echo "Ambiente pronto: http://127.0.0.1:$PORT/area-riservata/ (WordPress in $WP_DIR)"
