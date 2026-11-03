<?php
/**
 * Plugin di supporto ai test (mu-plugin). SOLO per l'ambiente di test: non fa parte del pacchetto distribuito
 * e non fa nulla se in wp-config.php non è definita la costante APAC_TESTING.
 *
 * - Email: scritte in wp-content/apac-test-mails.jsonl invece di essere spedite.
 * - Codici di sicurezza: GET /?apac_test_nonce=AZIONE restituisce il nonce dell'utente collegato.
 * - Guasti simulati: l'opzione apac_test_fail_sql (espressione regolare) fa fallire la prima
 *   interrogazione SQL che corrisponde; apac_test_fail_meta fa fallire il salvataggio dell'azienda sull'utente.
 */
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'APAC_TESTING' ) || ! APAC_TESTING ) {
	return;
}

add_filter(
	'pre_wp_mail',
	function ( $r, $a ) {
		$line = wp_json_encode(
			array(
				'to'      => is_array( $a['to'] ) ? implode( ',', $a['to'] ) : (string) $a['to'],
				'subject' => (string) $a['subject'],
				'message' => (string) $a['message'],
			),
			JSON_UNESCAPED_UNICODE
		);
		file_put_contents( WP_CONTENT_DIR . '/apac-test-mails.jsonl', $line . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return true;
	},
	10,
	2
);

add_action(
	'init',
	function () {
		if ( isset( $_GET['apac_test_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			header( 'Content-Type: text/plain' );
			echo esc_html( wp_create_nonce( sanitize_text_field( wp_unslash( $_GET['apac_test_nonce'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
			exit;
		}
	},
	1
);

add_filter(
	'query',
	function ( $q ) {
		static $done = false, $busy = false;
		if ( $done || $busy ) {
			return $q;
		}
		$busy = true; // get_option può eseguire a sua volta una query: niente ricorsione.
		$re   = (string) get_option( 'apac_test_fail_sql', '' );
		$busy = false;
		if ( '' !== $re && preg_match( $re, $q ) ) {
			$done = true;
			return 'SELECT apac_test_guasto_simulato FROM apac_tabella_inesistente';
		}
		return $q;
	}
);

add_filter(
	'update_user_metadata',
	function ( $c, $id, $k ) {
		if ( 'apac_azienda_id' === $k && get_option( 'apac_test_fail_meta' ) ) {
			return false;
		}
		return $c;
	},
	10,
	3
);
