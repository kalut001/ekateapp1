<?php
defined( 'ABSPATH' ) || exit;

/*
 * Moduli che non perdono i dati dopo un errore.
 *
 * Quando un'operazione fallisce per un errore di validazione, i valori inviati
 * (già ripuliti) vengono conservati per 10 minuti in un transient legato
 * all'utente; la pagina li ripropone nello stesso modulo e mostra l'errore
 * accanto al campo interessato. I file non possono essere conservati: il modulo
 * avvisa di selezionarli di nuovo.
 */

/** Campi che non vengono mai conservati. */
function apac_form_excluded_keys() {
	return array( '_apac_nonce', '_apac_once', '_apac_form_ref', 'apac_next', 'action', 'op', 'azienda_id', 'conferma', 'pwd', 'password', 'pass1', 'pass2', 'apac_smtp_pass' );
}

/** Indica il campo che ha causato l'errore (usato da apac_redirect). */
function apac_error_field( $name ) {
	$GLOBALS['apac_error_field'] = (string) $name;
}

/**
 * Salva i valori inviati dopo un errore e restituisce il token da passare nel redirect.
 * Restituisce '' se non c'è nulla da conservare.
 */
function apac_form_store_failed( $op, $code ) {
	if ( ! is_user_logged_in() || '' === $op ) {
		return '';
	}
	$values = array();
	foreach ( $_POST as $k => $v ) { // phpcs:ignore WordPress.Security.NonceVerification
		if ( in_array( $k, apac_form_excluded_keys(), true ) || is_array( $v ) ) {
			continue;
		}
		$values[ sanitize_key( $k ) ] = mb_substr( sanitize_textarea_field( wp_unslash( (string) $v ) ), 0, 5000 );
	}
	$state = array(
		'op'       => $op,
		'id'       => apac_form_identity( $values ),
		'values'   => $values,
		'field'    => isset( $GLOBALS['apac_error_field'] ) ? sanitize_key( $GLOBALS['apac_error_field'] ) : '',
		'code'     => $code,
		'had_file' => ! empty( $_FILES['file']['name'] ), // phpcs:ignore WordPress.Security.NonceVerification
	);
	apac_form_ref_clear(); // I dati di un errore precedente dello stesso modulo sono superati da questi.
	$token = strtolower( wp_generate_password( 12, false, false ) );
	set_transient( 'apac_form_' . get_current_user_id() . '_' . $token, $state, 10 * MINUTE_IN_SECONDS );
	return $token;
}

/** Identificativo del record a cui si riferisce il modulo (id, user_id o lavoratore_id). */
function apac_form_identity( $values ) {
	foreach ( array( 'id', 'user_id', 'lavoratore_id' ) as $k ) {
		if ( ! empty( $values[ $k ] ) ) {
			return $k . ':' . absint( $values[ $k ] );
		}
	}
	return '';
}

/**
 * Stato del modulo fallito. 1.9.4: NON viene più eliminato alla prima lettura (ricaricando la pagina
 * i dati andavano persi): resta per i 10 minuti previsti e viene eliminato quando lo stesso modulo
 * viene salvato con successo (campo _apac_form_ref, vedi apac_form_ref_field e apac_redirect).
 */
function apac_form_failed() {
	static $state = null;
	if ( null !== $state ) {
		return $state;
	}
	$state = array();
	if ( ! is_user_logged_in() || empty( $_GET['apac_form'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return $state;
	}
	$token = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) wp_unslash( $_GET['apac_form'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( '' === $token ) {
		return $state;
	}
	$key = 'apac_form_' . get_current_user_id() . '_' . $token;
	$got = get_transient( $key );
	if ( is_array( $got ) ) {
		$state          = $got;
		$state['token'] = $token;
	}
	return $state;
}

/** Campo nascosto che collega il modulo riproposto ai dati conservati (per eliminarli dopo il salvataggio). */
function apac_form_ref_field( $op ) {
	$f = apac_form_failed();
	if ( ! $f || empty( $f['token'] ) || $f['op'] !== $op ) {
		return '';
	}
	return '<input type="hidden" name="_apac_form_ref" value="' . esc_attr( $f['token'] ) . '">';
}

/** Salvataggio riuscito: elimina i dati conservati dell'errore precedente di questo utente. */
function apac_form_ref_clear() {
	$token = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) apac_post( '_apac_form_ref' ) ) );
	if ( '' !== $token && is_user_logged_in() ) {
		delete_transient( 'apac_form_' . get_current_user_id() . '_' . $token );
	}
}

/* Modulo in corso di stampa: serve a capire se i valori salvati appartengono a lui. */

function apac_form_begin( $op ) {
	$GLOBALS['apac_cur_form'] = array(
		'op' => $op,
		'id' => '',
	);
}

/** Campo nascosto che identifica il record del modulo. */
function apac_hidden( $name, $value ) {
	if ( in_array( $name, array( 'id', 'user_id', 'lavoratore_id' ), true ) && isset( $GLOBALS['apac_cur_form'] ) && '' === $GLOBALS['apac_cur_form']['id'] ) {
		$GLOBALS['apac_cur_form']['id'] = $name . ':' . absint( $value );
	}
	return '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
}

/** Il modulo in stampa è quello fallito? */
function apac_form_is_failed( $op = null, $id = null ) {
	$f = apac_form_failed();
	if ( ! $f ) {
		return false;
	}
	$cur = isset( $GLOBALS['apac_cur_form'] ) ? $GLOBALS['apac_cur_form'] : array( 'op' => '', 'id' => '' );
	$op  = null === $op ? $cur['op'] : $op;
	$id  = null === $id ? $cur['id'] : $id;
	return $f['op'] === $op && (string) $f['id'] === (string) $id;
}

/** Attributo "open" per il riquadro "Modifica" che contiene il modulo fallito. */
function apac_details_open( $op, $id_key ) {
	return apac_form_is_failed( $op, $id_key ) ? ' open' : '';
}

/** Valore da mostrare in un campo: quello inviato (se il modulo è fallito) o quello salvato. */
function apac_form_value( $name, $value ) {
	if ( apac_form_is_failed() ) {
		$f = apac_form_failed();
		return array_key_exists( $name, $f['values'] ) ? $f['values'][ $name ] : '';
	}
	return $value;
}

/** Casella: spuntata se inviata spuntata (modulo fallito) o se salvata così. */
function apac_form_checked( $name, $checked ) {
	if ( apac_form_is_failed() ) {
		$f = apac_form_failed();
		return ! empty( $f['values'][ $name ] );
	}
	return (bool) $checked;
}

/** Errore da mostrare accanto a questo campo ('' se nessuno). */
function apac_form_field_error( $name ) {
	if ( ! apac_form_is_failed() ) {
		return '';
	}
	$f = apac_form_failed();
	if ( $f['field'] !== $name ) {
		return '';
	}
	$msgs = apac_messages();
	return isset( $msgs[ $f['code'] ] ) ? $msgs[ $f['code'] ][1] : 'Valore non valido.';
}

/** Avviso per i moduli con allegato: il file va selezionato di nuovo. */
function apac_form_file_notice() {
	if ( ! apac_form_is_failed() ) {
		return '';
	}
	$f = apac_form_failed();
	if ( empty( $f['had_file'] ) ) {
		return '';
	}
	return '<p class="apac-field-msg apac-field-msg--file" role="alert">Il file scelto non è stato salvato a causa dell’errore: selezionalo di nuovo prima di inviare.</p>';
}

/* -------------------------------------------------------------------------
 * Validazione
 * ---------------------------------------------------------------------- */

/**
 * Lunghezze massime (in caratteri) coerenti con le colonne del database.
 */
function apac_max_lengths() {
	return array(
		'ragione_sociale'   => 200,
		'partita_iva'       => 13,
		'codice_fiscale'    => 16,
		'ateco'             => 20,
		'indirizzo'         => 255,
		'comune'            => 100,
		'provincia'         => 2,
		'telefono'          => 40,
		'email'             => 100,
		'pec'               => 150,
		'datore_lavoro'     => 150,
		'rspp_nome'         => 150,
		'rls_nome'          => 150,
		'medico_competente' => 150,
		'nome'              => 100,
		'cognome'           => 100,
		'mansione'          => 200,
		'titolo'            => 200,
		'corso'             => 150,
		'corso_altro'       => 150,
		'note'              => 5000,
		'descrizione'       => 5000,
		'risposta'          => 5000,
		'addetti_antincendio'    => 5000,
		'addetti_primo_soccorso' => 5000,
	);
}

/**
 * Interrompe l'operazione se un campo supera la lunghezza consentita.
 */
function apac_check_lengths( $data, $sez, $args = array() ) {
	$max = apac_max_lengths();
	foreach ( $data as $k => $v ) {
		if ( isset( $max[ $k ] ) && is_string( $v ) && mb_strlen( $v ) > $max[ $k ] ) {
			apac_error_field( $k );
			apac_redirect( $sez, 'too_long', $args );
		}
	}
}

/** Partita IVA italiana: 11 cifre, con prefisso "IT" facoltativo. Restituisce il valore normalizzato o false. */
function apac_normalize_piva( $v ) {
	$v = strtoupper( preg_replace( '/[\s.\-]/', '', (string) $v ) );
	if ( '' === $v ) {
		return '';
	}
	if ( 0 === strpos( $v, 'IT' ) ) {
		$v = substr( $v, 2 );
	}
	return preg_match( '/^\d{11}$/', $v ) ? $v : false;
}

/** Codice fiscale: 16 caratteri alfanumerici (persona) o 11 cifre (società o numerico provvisorio). */
function apac_normalize_cf( $v ) {
	$v = strtoupper( preg_replace( '/\s+/', '', (string) $v ) );
	if ( '' === $v ) {
		return '';
	}
	return ( preg_match( '/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/', $v ) || preg_match( '/^\d{11}$/', $v ) ) ? $v : false;
}

/** Email facoltativa: '' se vuota, false se scritta male, altrimenti l'indirizzo. */
function apac_normalize_email( $v ) {
	$v = trim( (string) $v );
	if ( '' === $v ) {
		return '';
	}
	$clean = sanitize_email( $v );
	return ( $clean && is_email( $clean ) && $clean === $v ) ? $clean : false;
}

/** Telefono facoltativo: cifre, spazi, + ( ) . / - */
function apac_normalize_phone( $v ) {
	$v = trim( (string) $v );
	if ( '' === $v ) {
		return '';
	}
	return preg_match( '/^[0-9+()\/.\s\-]{5,40}$/', $v ) ? $v : false;
}

/* -------------------------------------------------------------------------
 * Chiave di ordinamento e ricerca (maiuscole e accenti indifferenti)
 * ---------------------------------------------------------------------- */

function apac_sort_key( $s ) {
	$s = remove_accents( (string) $s );
	$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	$s = preg_replace( '/[^a-z0-9 ]+/', '', $s );
	$s = preg_replace( '/\s+/', ' ', $s );
	return mb_substr( trim( $s ), 0, 200 );
}

/**
 * 1.9.4 – "Salva e continua": pagina da aprire dopo un salvataggio riuscito.
 * Accettata solo se è un indirizzo dello stesso sito (mai verso altri domini).
 */
function apac_next_url() {
	$raw = trim( (string) apac_post( 'apac_next' ) );
	if ( '' === $raw ) {
		return '';
	}
	$url  = esc_url_raw( $raw );
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( '' === $url || ! $host || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== strtolower( $host ) ) {
		return '';
	}
	$url = wp_validate_redirect( $url, '' );
	return $url ? remove_query_arg( array( 'apac_msg', 'apac_did', 'apac_form' ), $url ) : '';
}
