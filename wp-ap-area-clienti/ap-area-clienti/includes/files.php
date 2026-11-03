<?php
defined( 'ABSPATH' ) || exit;

/** Cartella dentro uploads (soluzione di riserva, protetta solo da .htaccess). */
function apac_uploads_private_dir() {
	$uploads = wp_upload_dir( null, false );
	return trailingslashit( $uploads['basedir'] ) . 'apac-private';
}

/**
 * Percorso della cartella dei documenti.
 * Scelta all'attivazione: preferibilmente FUORI dalla cartella pubblica del sito,
 * altrimenti dentro uploads con regole di blocco.
 * Se la cartella configurata non esiste più NON si ripiega su un'altra cartella:
 * l'errore viene segnalato e i caricamenti restano bloccati.
 */
function apac_private_dir() {
	$dir = (string) get_option( 'apac_private_dir' );
	return '' !== $dir ? untrailingslashit( $dir ) : apac_uploads_private_dir();
}

/** Stato della cartella: 'ok', 'mancante', 'non_scrivibile'. */
function apac_storage_state() {
	$dir = apac_private_dir();
	if ( ! @is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return 'mancante';
	}
	return wp_is_writable( $dir ) ? 'ok' : 'non_scrivibile';
}

/** Radice pubblica del sito (document root). */
function apac_document_root() {
	$root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? (string) $_SERVER['DOCUMENT_ROOT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$real = $root ? realpath( $root ) : false;
	return $real ? $real : realpath( ABSPATH );
}

/** Il percorso si trova dentro la cartella pubblica del sito? */
function apac_is_inside_docroot( $path ) {
	$real = realpath( $path );
	$root = apac_document_root();
	if ( ! $real || ! $root ) {
		return true; // Nel dubbio si considera esposto.
	}
	return $real === $root || 0 === strpos( $real . DIRECTORY_SEPARATOR, rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR );
}

/** Sceglie la cartella dei documenti (solo alla prima attivazione, mai se è già configurata). */
function apac_choose_private_dir() {
	if ( '' !== (string) get_option( 'apac_private_dir' ) ) {
		return;
	}
	$candidate = dirname( untrailingslashit( ABSPATH ) ) . '/apac-documenti-privati';
	if ( apply_filters( 'apac_use_outside_webroot', true ) && @wp_mkdir_p( $candidate ) && @is_writable( $candidate ) && ! apac_is_inside_docroot( $candidate ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		update_option( 'apac_private_dir', $candidate, false );
		return;
	}
	update_option( 'apac_private_dir', apac_uploads_private_dir(), false );
}

/** URL pubblico teorico della cartella (solo se è dentro uploads; usato per il test). */
function apac_private_url() {
	$uploads = wp_upload_dir( null, false );
	return trailingslashit( $uploads['baseurl'] ) . 'apac-private';
}

/** Scrive un file e verifica che il contenuto sia stato salvato davvero. */
function apac_write_file( $path, $content ) {
	$r = @file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
	return false !== $r && strlen( $content ) === $r;
}

/**
 * Prepara la cartella protetta: regole di blocco per Apache, index.php e file di prova.
 *
 * @param bool $create Crea la cartella se manca (solo alla prima attivazione o su richiesta esplicita).
 * @return true|WP_Error
 */
function apac_ensure_private_dir( $create = false ) {
	$dir = apac_private_dir();
	if ( ! is_dir( $dir ) ) {
		if ( ! $create ) {
			return new WP_Error( 'storage_missing', 'La cartella dei documenti non esiste: ' . $dir );
		}
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'storage_write', 'Impossibile creare la cartella: ' . $dir );
		}
	}
	if ( ! wp_is_writable( $dir ) ) {
		return new WP_Error( 'storage_write', 'La cartella non è scrivibile: ' . $dir );
	}

	$files = array(
		'.htaccess'     => "# AP Area Clienti: accesso diretto vietato\nOptions -Indexes\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
		'index.php'     => "<?php\n// Silence is golden.\n",
		'apac-test.txt' => 'APAC-PROTEZIONE-TEST',
	);
	foreach ( $files as $name => $content ) {
		if ( ! file_exists( $dir . '/' . $name ) && ! apac_write_file( $dir . '/' . $name, $content ) ) {
			return new WP_Error( 'storage_write', 'Impossibile scrivere ' . $name . ' nella cartella dei documenti.' );
		}
	}
	return true;
}

/**
 * Verifica che i file NON siano scaricabili con un link diretto.
 *
 * Esiti:
 * - 'fuori_webroot'     cartella fuori dalla parte pubblica del sito;
 * - 'protetta'          un file di controllo nella parte pubblica di uploads è
 *                       scaricabile (quindi l'indirizzo è corretto) MENTRE il file di
 *                       prova nella cartella protetta viene rifiutato (401, 403, 404, 410);
 * - 'esposta'           il file di prova è stato scaricato;
 * - 'cartella_mancante' / 'non_scrivibile'  problema della cartella sul server;
 * - 'non_verificabile'  qualsiasi altro caso (errore di rete o del certificato,
 *                       redirect, errore del server, file di controllo non raggiungibile):
 *                       NON è considerata una prova di protezione.
 * La verifica del certificato TLS resta sempre attiva.
 */
function apac_check_private_dir_protection() {
	$prep = apac_ensure_private_dir();
	$code = 0;
	$nota = '';
	if ( is_wp_error( $prep ) ) {
		$esito = 'storage_missing' === $prep->get_error_code() ? 'cartella_mancante' : 'non_scrivibile';
		$nota  = $prep->get_error_message();
	} elseif ( ! apac_is_inside_docroot( apac_private_dir() ) ) {
		$esito = 'fuori_webroot';
	} elseif ( ! is_readable( apac_private_dir() . '/apac-test.txt' ) ) {
		$esito = 'non_verificabile';
		$nota  = 'file di prova assente';
	} else {
		$args = array(
			'timeout'     => 10,
			'sslverify'   => true,
			'redirection' => 0,
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
		);
		// 1) Controllo: un file pubblico appena creato in uploads deve essere scaricabile.
		$uploads  = wp_upload_dir( null, false );
		$token    = 'APAC-CTRL-' . wp_generate_password( 16, false, false );
		$ctrl     = 'apac-ctrl-' . strtolower( wp_generate_password( 10, false, false ) ) . '.txt';
		$ctrl_ok  = false;
		if ( apac_write_file( trailingslashit( $uploads['basedir'] ) . $ctrl, $token ) ) {
			$r       = wp_remote_get( trailingslashit( $uploads['baseurl'] ) . $ctrl . '?nocache=' . wp_rand(), $args );
			$ctrl_ok = ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) && false !== strpos( (string) wp_remote_retrieve_body( $r ), $token );
			if ( is_wp_error( $r ) ) {
				$nota = $r->get_error_message();
			}
			wp_delete_file( trailingslashit( $uploads['basedir'] ) . $ctrl );
		} else {
			$nota = 'impossibile creare il file di controllo';
		}
		// 2) Prova sulla cartella protetta.
		$response = wp_remote_get( apac_private_url() . '/apac-test.txt?nocache=' . wp_rand(), $args );
		if ( is_wp_error( $response ) ) {
			$esito = 'non_verificabile';
			$nota  = $response->get_error_message();
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );
			if ( false !== strpos( $body, 'APAC-PROTEZIONE-TEST' ) ) {
				$esito = 'esposta';
			} elseif ( $ctrl_ok && in_array( $code, array( 401, 403, 404, 410 ), true ) ) {
				$esito = 'protetta';
			} else {
				$esito = 'non_verificabile';
				if ( ! $ctrl_ok && '' === $nota ) {
					$nota = 'il file di controllo pubblico non è stato raggiunto: l’indirizzo della cartella non è verificabile';
				}
			}
		}
	}
	update_option(
		'apac_protezione',
		array(
			'esito' => $esito,
			'http'  => $code,
			'ts'    => time(),
			'dir'   => apac_private_dir(),
			'nota'  => mb_substr( $nota, 0, 250 ),
		),
		false
	);
	return $esito;
}

/**
 * I caricamenti sono consentiti solo con una protezione dimostrata, ricontrollata
 * al massimo ogni 12 ore, oppure confermata manualmente dall'amministratore.
 */
function apac_uploads_allowed() {
	if ( 'ok' !== apac_storage_state() ) {
		return false;
	}
	$p = get_option( 'apac_protezione', array() );
	// Si ricontrolla se l'esito è vecchio o se riguarda una cartella diversa da quella in uso.
	if ( ! is_array( $p ) || empty( $p['ts'] ) || ( time() - (int) $p['ts'] ) > 12 * HOUR_IN_SECONDS || ( $p['dir'] ?? '' ) !== apac_private_dir() ) {
		apac_check_private_dir_protection();
		$p = get_option( 'apac_protezione', array() );
	}
	if ( in_array( $p['esito'] ?? '', array( 'fuori_webroot', 'protetta' ), true ) ) {
		return true;
	}
	// Conferma manuale, valida solo finché il controllo automatico non rileva un'esposizione.
	return 'non_verificabile' === ( $p['esito'] ?? '' ) && (bool) get_option( 'apac_protezione_manuale', false );
}

/** Tipi di file ammessi. */
function apac_allowed_mimes() {
	return array(
		'pdf'      => 'application/pdf',
		'doc'      => 'application/msword',
		'docx'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xls'      => 'application/vnd.ms-excel',
		'xlsx'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'odt'      => 'application/vnd.oasis.opendocument.text',
		'ods'      => 'application/vnd.oasis.opendocument.spreadsheet',
		'jpg|jpeg' => 'image/jpeg',
		'png'      => 'image/png',
	);
}

/**
 * Salva un file caricato nella cartella protetta dell'azienda.
 *
 * @return array|WP_Error  [ 'path' => relativo, 'name', 'mime', 'size' ]
 */
function apac_store_upload( $file, $azienda_id ) {
	$state = apac_storage_state();
	if ( 'mancante' === $state ) {
		return new WP_Error( 'storage_missing' );
	}
	if ( 'ok' !== $state ) {
		return new WP_Error( 'storage_write' );
	}
	if ( ! apac_uploads_allowed() ) {
		return new WP_Error( 'upload_blocked' );
	}
	if ( empty( $file ) || ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
		return new WP_Error( 'upload_error' );
	}
	if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
		return new WP_Error( 'upload_error' );
	}
	if ( (int) $file['size'] <= 0 || (int) $file['size'] > wp_max_upload_size() ) {
		return new WP_Error( 'upload_error' );
	}

	$original = sanitize_file_name( wp_basename( $file['name'] ) );
	$check    = wp_check_filetype_and_ext( $file['tmp_name'], $original, apac_allowed_mimes() );
	if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
		return new WP_Error( 'file_type' );
	}
	if ( ! empty( $check['proper_filename'] ) ) {
		$original = $check['proper_filename'];
	}
	// Il nome originale entra nella colonna del database (255 caratteri): si accorcia conservando l'estensione.
	if ( mb_strlen( $original ) > 200 ) {
		$original = mb_substr( pathinfo( $original, PATHINFO_FILENAME ), 0, 190 ) . '.' . strtolower( $check['ext'] );
	}

	$prep = apac_ensure_private_dir();
	if ( is_wp_error( $prep ) ) {
		return new WP_Error( $prep->get_error_code() );
	}
	$rel_dir = (string) (int) $azienda_id;
	$abs_dir = apac_private_dir() . '/' . $rel_dir;
	if ( ! is_dir( $abs_dir ) ) {
		if ( ! wp_mkdir_p( $abs_dir ) || ! apac_write_file( $abs_dir . '/index.php', "<?php\n// Silence is golden.\n" ) ) {
			return new WP_Error( 'storage_write' );
		}
	}
	if ( ! wp_is_writable( $abs_dir ) ) {
		return new WP_Error( 'storage_write' );
	}

	// Nome casuale non indovinabile, con estensione verificata.
	$stored = wp_generate_password( 32, false, false ) . '.' . strtolower( $check['ext'] );
	$dest   = $abs_dir . '/' . $stored;

	$moved = @move_uploaded_file( $file['tmp_name'], $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! $moved || ! is_file( $dest ) || filesize( $dest ) !== (int) $file['size'] ) {
		if ( is_file( $dest ) ) {
			wp_delete_file( $dest );
		}
		return new WP_Error( 'storage_write' );
	}
	@chmod( $dest, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	// Se l'operazione non va a buon fine il file appena salvato viene rimosso.
	if ( function_exists( 'apac_tx_active' ) && apac_tx_active() ) {
		apac_tx_track_new_file( $dest );
	}

	return array(
		'path' => $rel_dir . '/' . $stored,
		'name' => $original,
		'mime' => $check['type'],
		'size' => (int) $file['size'],
	);
}

/** Percorso assoluto sicuro di un documento (solo dentro la cartella protetta). */
function apac_document_abs_path( $doc ) {
	$base = realpath( apac_private_dir() );
	$path = realpath( apac_private_dir() . '/' . $doc->file_path );
	if ( ! $base || ! $path || 0 !== strpos( $path, $base . DIRECTORY_SEPARATOR ) ) {
		return '';
	}
	return $path;
}

function apac_delete_document_file( $doc ) {
	$path = apac_document_abs_path( $doc );
	if ( $path && is_file( $path ) ) {
		wp_delete_file( $path );
	}
}

/** Link per visualizzare o scaricare un documento attraverso il controllo accessi. */
function apac_file_url( $doc_id, $mode = 'download' ) {
	// Dentro l'indirizzo dell'area riservata: sul telefono il documento si apre nella web app.
	return wp_nonce_url(
		add_query_arg(
			array(
				'apac_file' => (int) $doc_id,
				'mode'      => 'view' === $mode ? 'view' : 'download',
			),
			apac_portal_page_url()
		),
		'apac_file_' . (int) $doc_id
	);
}

add_action( 'admin_post_apac_file', 'apac_serve_file' );
add_action( 'admin_post_nopriv_apac_file', 'apac_serve_file_denied' );

function apac_serve_file_denied() {
	wp_die( 'Accesso riservato. Effettua il login.', 'Accesso negato', array( 'response' => 403 ) );
}

/** Invia il file solo se l'utente ha accesso all'azienda proprietaria. */
function apac_serve_file() {
	global $wpdb;

	$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
	if ( ! $id || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'apac_file_' . $id ) ) {
		wp_die( 'Link non valido o scaduto. Torna all’area clienti e riprova.', 'Accesso negato', array( 'response' => 403 ) );
	}

	$doc = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'documenti' ) . ' WHERE id = %d', $id ) );
	if ( ! $doc || ! apac_can_access_azienda( (int) $doc->azienda_id ) ) {
		wp_die( 'Documento non disponibile.', 'Accesso negato', array( 'response' => 403 ) );
	}

	if ( 'mancante' === apac_storage_state() ) {
		wp_die( 'L’archivio dei documenti non è al momento disponibile sul server. Contatta lo studio.', 'Errore', array( 'response' => 503 ) );
	}
	$path = apac_document_abs_path( $doc );
	if ( ! $path || ! is_readable( $path ) ) {
		wp_die( 'File non trovato sul server.', 'Errore', array( 'response' => 404 ) );
	}

	$inline_types = array( 'application/pdf', 'image/jpeg', 'image/png' );
	$mode         = ( isset( $_GET['mode'] ) && 'view' === $_GET['mode'] && in_array( $doc->mime, $inline_types, true ) ) ? 'inline' : 'attachment';

	while ( ob_get_level() ) {
		ob_end_clean();
	}

	$filename = str_replace( array( '"', "\r", "\n" ), '', $doc->nome_file );
	nocache_headers();
	header( 'Content-Type: ' . $doc->mime );
	header( 'Content-Length: ' . filesize( $path ) );
	if ( function_exists( 'apac_download_header' ) ) {
		apac_download_header( $filename, $mode );
	} else {
		header( 'Content-Disposition: ' . $mode . '; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
	}
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}
