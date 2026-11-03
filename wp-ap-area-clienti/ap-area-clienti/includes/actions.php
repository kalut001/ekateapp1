<?php
defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_apac_action', 'apac_handle_action' );
add_action( 'admin_post_nopriv_apac_action', 'apac_serve_file_denied' );

/**
 * Smistatore unico dei moduli dell'area clienti.
 * 1.9.15: tutte le regole di accesso vengono dal registro (includes/operazioni.php); un'operazione non
 * registrata viene rifiutata prima di qualsiasi altro passaggio.
 */
function apac_handle_action() {
	$op = sanitize_key( apac_post( 'op' ) );
	if ( ! $op || ! wp_verify_nonce( sanitize_text_field( apac_post( '_apac_nonce' ) ), 'apac_' . $op ) ) {
		wp_die( 'Sessione scaduta: torna indietro, ricarica la pagina e riprova.', 'Errore', array( 'response' => 403 ) );
	}
	if ( ! apac_user_can_use_portal() ) {
		wp_die( 'Accesso non consentito.', 'Errore', array( 'response' => 403 ) );
	}
	$o = apac_operazione( $op );
	if ( ! $o || ! function_exists( $o['fn'] ) ) {
		apac_redirect( 'dashboard', 'invalid' );
	}
	$admin = apac_is_admin();
	if ( 'cliente' !== $o['ruolo'] && ! $admin ) {
		apac_redirect( 'dashboard', 'denied' );
	}

	// Azienda di lavoro: per i clienti è sempre la propria (il valore inviato viene ignorato).
	$azienda_id = $admin ? absint( apac_post( 'azienda_id' ) ) : apac_user_azienda_id();
	if ( 'nessuno' !== $o['ambito'] && ! apac_can_access_azienda( $azienda_id ) ) {
		apac_redirect( 'dashboard', 'no_azienda' );
	}
	$_REQUEST['azienda'] = $azienda_id; // Mantiene l'azienda selezionata nei redirect dell'amministratore.

	// Permessi del cliente su questa azienda (l'amministratore può sempre tutto).
	if ( '' !== $o['permesso'] && ! apac_client_can( $azienda_id, $o['permesso'] ) ) {
		apac_redirect( $o['sezione'], 'denied' );
	}
	// Accesso in sola consultazione: solo le operazioni indicate nel registro.
	if ( ! $admin && ! $o['sola_lettura'] && apac_user_readonly() ) {
		apac_redirect( $o['sezione'], 'denied' );
	}

	// 1.7.2: finché mancano le firme richieste al primo accesso, nessun'altra operazione.
	if ( ! $o['prima_delle_firme'] && ! $admin && function_exists( 'apac_consensi_pending' ) && apac_consensi_pending() ) {
		apac_redirect( 'dashboard', 'consent_needed' );
	}

	// 1.9.0/1.9.2: lo stesso modulo inviato due volte non crea duplicati; senza un codice valido
	// (pagina vecchia, richiesta costruita a mano, database non disponibile) l'invio è bloccato.
	$once = apac_once_check();
	if ( 'dup' === $once ) {
		apac_redirect( $o['sezione'], 'already_done' );
	}
	if ( 'busy' === $once || 'unknown' === $once ) {
		$GLOBALS['apac_form_op'] = $op; // I dati inseriti restano disponibili.
		apac_redirect( $o['sezione'], 'busy' === $once ? 'in_progress' : 'esito_ignoto' );
	}
	if ( 'ok' !== $once ) {
		$GLOBALS['apac_form_op'] = $op; // I dati inseriti restano nel modulo.
		apac_redirect( $o['sezione'], 'reload_needed' );
	}

	// Modulo in elaborazione: se fallisce, i valori inviati vengono riproposti.
	$GLOBALS['apac_form_op'] = $op;

	// Contesto per il registro attività.
	$GLOBALS['apac_log_ctx'] = array(
		'op'         => $op,
		'azienda_id' => $azienda_id,
		'detail'     => '',
	);

	// Ogni operazione è "tutto o niente" (tranne quelle che non scrivono dati del plugin).
	if ( $o['transazione'] && ! apac_tx_begin() ) {
		apac_redirect( 'dashboard', apac_tx_errore() );
	}
	call_user_func( $o['fn'], $azienda_id );
	apac_redirect( 'dashboard', 'invalid' ); // Ogni operazione termina con un proprio reindirizzamento.
}

/* ----------------------------- Aziende -------------------------------- */

/**
 * Legge e valida i dati dell'azienda dal modulo.
 * In caso di errore torna alla sezione indicando il campo da correggere.
 */
function apac_azienda_fields_from_post( $sez = 'azienda', $args = array() ) {
	$text = array( 'ragione_sociale', 'ateco', 'indirizzo', 'comune', 'datore_lavoro', 'rspp_nome', 'rls_nome', 'medico_competente' );
	$data = array();
	foreach ( $text as $f ) {
		$data[ $f ] = trim( sanitize_text_field( apac_post( $f ) ) );
	}
	$data['addetti_antincendio']    = sanitize_textarea_field( apac_post( 'addetti_antincendio' ) );
	$data['addetti_primo_soccorso'] = sanitize_textarea_field( apac_post( 'addetti_primo_soccorso' ) );
	$data['note']                   = sanitize_textarea_field( apac_post( 'note' ) );

	if ( '' === $data['ragione_sociale'] ) {
		apac_error_field( 'ragione_sociale' );
		apac_redirect( $sez, 'need_field', $args );
	}

	$checks = array(
		'partita_iva'    => array( 'apac_normalize_piva', 'bad_piva' ),
		'codice_fiscale' => array( 'apac_normalize_cf', 'bad_cf' ),
		'telefono'       => array( 'apac_normalize_phone', 'bad_phone' ),
		'email'          => array( 'apac_normalize_email', 'bad_email' ),
		'pec'            => array( 'apac_normalize_email', 'bad_email' ),
	);
	foreach ( $checks as $f => $c ) {
		$v = call_user_func( $c[0], sanitize_text_field( apac_post( $f ) ) );
		if ( false === $v ) {
			apac_error_field( $f );
			apac_redirect( $sez, $c[1], $args );
		}
		$data[ $f ] = $v;
	}
	$prov = strtoupper( trim( sanitize_text_field( apac_post( 'provincia' ) ) ) );
	if ( '' !== $prov && ! preg_match( '/^[A-Z]{2}$/', $prov ) ) {
		apac_error_field( 'provincia' );
		apac_redirect( $sez, 'bad_prov', $args );
	}
	$data['provincia'] = $prov;

	$choices = array(
		'rspp_tipo'              => array( '', 'interno', 'esterno', 'datore' ),
		'rls_tipo'               => array( '', 'RLS', 'RLST' ),
		'sorveglianza_sanitaria' => array( '', 'si', 'no' ),
	);
	foreach ( $choices as $f => $allowed ) {
		$v = (string) apac_post( $f );
		if ( ! in_array( $v, $allowed, true ) ) {
			apac_error_field( $f );
			apac_redirect( $sez, 'bad_value', $args );
		}
		$data[ $f ] = $v;
	}
	apac_check_lengths( $data, $sez, $args );
	$data['ragione_sort'] = apac_sort_key( $data['ragione_sociale'] );
	return $data;
}

function apac_op_azienda_create( $unused ) {
	global $wpdb;
	$data = apac_azienda_fields_from_post( 'utenti' );
	$data['created_at'] = apac_now();
	$data['updated_at'] = apac_now();
	$new_id = apac_must_insert( 'aziende', $data, 'utenti' );
	apac_log_detail( $data['ragione_sociale'] );
	apac_redirect( 'azienda', 'saved', array( 'azienda' => $new_id ) );
}

function apac_op_azienda_save( $azienda_id ) {
	global $wpdb;
	$data = apac_azienda_fields_from_post( 'azienda' );
	$data['updated_at'] = apac_now();
	apac_must( $wpdb->update( apac_table( 'aziende' ), $data, array( 'id' => $azienda_id ) ), 'azienda' );
	apac_redirect( 'azienda', 'saved' );
}

function apac_op_azienda_delete( $azienda_id ) {
	global $wpdb;
	if ( 'ELIMINA' !== apac_post( 'conferma' ) ) {
		apac_redirect( 'azienda', 'invalid' );
	}
	$docs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'documenti' ) . ' WHERE azienda_id = %d', $azienda_id ) );
	// 1.7.0: anche versioni precedenti dei documenti e foto delle segnalazioni.
	foreach ( array( 'documenti_versioni', 'segnalazioni_foto', 'consensi' ) as $t ) {
		$docs = array_merge( $docs, (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( $t ) . ' WHERE azienda_id = %d', $azienda_id ) ) );
	}
	// Storico degli avvisi delle righe dell'azienda (contiene indirizzi email: si elimina con l'azienda).
	foreach ( array( 'scadenza' => 'scadenze', 'attestato' => 'attestati' ) as $tipo => $tab ) {
		apac_must( $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . apac_table( 'avvisi' ) . ' WHERE tipo = %s AND riga_id IN (SELECT id FROM ' . apac_table( $tab ) . ' WHERE azienda_id = %d)', $tipo, $azienda_id ) ), 'azienda' );
	}
	foreach ( array( 'consensi', 'documenti_versioni', 'segnalazioni_foto', 'firme', 'mansioni_corsi', 'documenti', 'attestati', 'lavoratori', 'scadenze', 'segnalazioni' ) as $t ) {
		apac_must( $wpdb->delete( apac_table( $t ), array( 'azienda_id' => $azienda_id ) ), 'azienda' );
	}
	$az_row = apac_get_azienda( $azienda_id );
	apac_must( $wpdb->delete( apac_table( 'aziende' ), array( 'id' => $azienda_id ) ), 'azienda', array(), true );
	// Gli utenti collegati perdono l'accesso.
	$users = get_users( array( 'meta_key' => 'apac_azienda_id', 'meta_value' => $azienda_id ) );
	foreach ( $users as $u ) {
		delete_user_meta( $u->ID, 'apac_azienda_id' );
		if ( '' !== (string) get_user_meta( $u->ID, 'apac_azienda_id', true ) ) {
			apac_redirect( 'azienda', 'db_error' );
		}
	}
	// I file si cancellano solo dopo che l'eliminazione dal database è confermata.
	foreach ( $docs as $d ) {
		apac_tx_after_commit(
			function () use ( $d ) {
				apac_delete_document_file( $d );
			}
		);
	}
	apac_log_detail( $az_row ? $az_row->ragione_sociale : '' );
	$GLOBALS['apac_log_ctx']['azienda_id'] = 0;
	$_REQUEST['azienda']                   = 0;
	delete_option( 'apac_pp_' . (int) $azienda_id );
	apac_redirect( 'utenti', 'deleted', array( 'azienda' => 0 ) );
}

/* ---------------------------- Lavoratori ------------------------------ */

function apac_op_lavoratore_save( $azienda_id ) {
	global $wpdb;
	$id   = absint( apac_post( 'id' ) );
	$data = array(
		'nome'            => sanitize_text_field( apac_post( 'nome' ) ),
		'cognome'         => sanitize_text_field( apac_post( 'cognome' ) ),
		'codice_fiscale'  => strtoupper( sanitize_text_field( apac_post( 'codice_fiscale' ) ) ),
		'mansione'        => sanitize_text_field( apac_post( 'mansione' ) ),
		'data_assunzione' => apac_sanitize_date( apac_post( 'data_assunzione' ) ),
		'attivo'          => apac_post( 'attivo' ) ? 1 : 0,
		'note'            => sanitize_textarea_field( apac_post( 'note' ) ),
	);
	$back = $id ? array( 'lav' => $id ) : array();
	foreach ( array( 'nome', 'cognome' ) as $f ) {
		if ( '' === trim( $data[ $f ] ) ) {
			apac_error_field( $f );
			apac_redirect( 'lavoratori', 'need_field', $back );
		}
	}
	$cf = apac_normalize_cf( apac_post( 'codice_fiscale' ) );
	if ( false === $cf ) {
		apac_error_field( 'codice_fiscale' );
		apac_redirect( 'lavoratori', 'bad_cf', $back );
	}
	$data['codice_fiscale'] = $cf;
	$assunzione = apac_parse_date( apac_post( 'data_assunzione' ) );
	if ( ! $assunzione['ok'] ) {
		apac_error_field( 'data_assunzione' );
		apac_redirect( 'lavoratori', 'invalid_date', $back );
	}
	$data['data_assunzione'] = $assunzione['date'];
	// 1.9.0: data di cessazione (se non indicata, quella già registrata o la data di oggi).
	if ( $data['attivo'] ) {
		$data['data_cessazione'] = null;
	} else {
		$ces = apac_parse_date( apac_post( 'data_cessazione' ) );
		if ( ! $ces['ok'] ) {
			apac_error_field( 'data_cessazione' );
			apac_redirect( 'lavoratori', 'invalid_date', $back );
		}
		$prev = $id ? apac_get_row_for_azienda( 'lavoratori', $id, $azienda_id ) : null;
		$data['data_cessazione'] = $ces['date'] ? $ces['date'] : ( $prev && ! empty( $prev->data_cessazione ) ? $prev->data_cessazione : apac_today() );
		// 1.9.4: la cessazione non può precedere l'assunzione (date in formato Y-m-d, confronto diretto).
		if ( $data['data_assunzione'] && $data['data_cessazione'] < $data['data_assunzione'] ) {
			apac_error_field( 'data_cessazione' );
			apac_redirect( 'lavoratori', 'cess_before_hire', $back );
		}
	}
	apac_check_lengths( $data, 'lavoratori', $back );
	if ( $id ) {
		if ( ! apac_get_row_for_azienda( 'lavoratori', $id, $azienda_id ) ) {
			apac_redirect( 'lavoratori', 'denied' );
		}
		apac_must( $wpdb->update( apac_table( 'lavoratori' ), $data, array( 'id' => $id, 'azienda_id' => $azienda_id ) ), 'lavoratori', $back );
	} else {
		$data['azienda_id'] = $azienda_id;
		$data['created_at'] = apac_now();
		$id                 = apac_must_insert( 'lavoratori', $data, 'lavoratori' );
	}
	apac_log_detail( $data['cognome'] . ' ' . $data['nome'] );
	apac_redirect( 'lavoratori', 'saved', array( 'lav' => $id ) );
}

function apac_op_lavoratore_delete( $azienda_id ) {
	global $wpdb;
	$id = absint( apac_post( 'id' ) );
	if ( ! apac_get_row_for_azienda( 'lavoratori', $id, $azienda_id ) ) {
		apac_redirect( 'lavoratori', 'denied' );
	}
	$back = array( 'lav' => $id );
	apac_must( $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . apac_table( 'avvisi' ) . " WHERE tipo = 'attestato' AND riga_id IN (SELECT id FROM " . apac_table( 'attestati' ) . ' WHERE lavoratore_id = %d AND azienda_id = %d)', $id, $azienda_id ) ), 'lavoratori', $back );
	apac_must( $wpdb->delete( apac_table( 'attestati' ), array( 'lavoratore_id' => $id, 'azienda_id' => $azienda_id ) ), 'lavoratori', $back );
	apac_must( $wpdb->update( apac_table( 'scadenze' ), array( 'lavoratore_id' => null ), array( 'lavoratore_id' => $id, 'azienda_id' => $azienda_id ) ), 'lavoratori', $back );
	apac_must( $wpdb->delete( apac_table( 'lavoratori' ), array( 'id' => $id, 'azienda_id' => $azienda_id ) ), 'lavoratori', $back, true );
	apac_redirect( 'lavoratori', 'deleted' );
}

/* ----------------------------- Attestati ------------------------------ */

/**
 * Valida le date di un attestato.
 * - conseguimento obbligatoria, valida e non futura;
 * - scadenza obbligatoria e successiva al conseguimento, salvo "Corso senza scadenza".
 *
 * @return array [ conseguimento, scadenza ]  (in caso di errore reindirizza)
 */
function apac_attestato_dates( $back ) {
	$cons  = apac_parse_date( apac_post( 'data_conseguimento' ) );
	$scad  = apac_parse_date( apac_post( 'data_scadenza' ) );
	$senza = (bool) apac_post( 'senza_scadenza' );
	if ( ! $cons['ok'] ) {
		apac_error_field( 'data_conseguimento' );
		apac_redirect( 'lavoratori', 'invalid_date', $back );
	}
	if ( ! $scad['ok'] ) {
		apac_error_field( 'data_scadenza' );
		apac_redirect( 'lavoratori', 'invalid_date', $back );
	}
	if ( null === $cons['date'] ) {
		apac_error_field( 'data_conseguimento' );
		apac_redirect( 'lavoratori', 'need_conseg', $back );
	}
	if ( $cons['date'] > apac_today() ) {
		apac_error_field( 'data_conseguimento' );
		apac_redirect( 'lavoratori', 'date_future', $back );
	}
	if ( $senza ) {
		if ( null !== $scad['date'] ) {
			apac_error_field( 'data_scadenza' );
			apac_redirect( 'lavoratori', 'scad_conflict', $back );
		}
		return array( $cons['date'], null );
	}
	if ( null === $scad['date'] ) {
		apac_error_field( 'data_scadenza' );
		apac_redirect( 'lavoratori', 'need_scad', $back );
	}
	if ( $scad['date'] <= $cons['date'] ) {
		apac_error_field( 'data_scadenza' );
		apac_redirect( 'lavoratori', 'date_order', $back );
	}
	return array( $cons['date'], $scad['date'] );
}

/** Salva l'allegato di un attestato come documento (richiede anche il permesso "documenti"). */
function apac_attestato_store_file( $azienda_id, $corso, $lav, $back ) {
	if ( empty( $_FILES['file']['name'] ) ) {
		return null;
	}
	if ( ! apac_client_can( $azienda_id, 'documenti' ) ) {
		apac_error_field( 'file' );
		apac_redirect( 'lavoratori', 'denied_upload', $back );
	}
	$stored = apac_store_upload( $_FILES['file'], $azienda_id ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( is_wp_error( $stored ) ) {
		apac_error_field( 'file' );
		apac_redirect( 'lavoratori', $stored->get_error_code(), $back );
	}
	return apac_must_insert(
		'documenti',
		array(
			'azienda_id'  => $azienda_id,
			'titolo'      => 'Attestato ' . $corso . ( $lav ? ' – ' . $lav->cognome . ' ' . $lav->nome : '' ),
			'categoria'   => 'formazione',
			'nome_file'   => $stored['name'],
			'file_path'   => $stored['path'],
			'mime'        => $stored['mime'],
			'dimensione'  => $stored['size'],
			'caricato_da' => get_current_user_id(),
			'created_at'  => apac_now(),
		),
		'lavoratori',
		$back
	);
}

/**
 * Corso dell'attestato: una voce dell'elenco oppure, con "Altro", il nome scritto
 * nel campo apposito (obbligatorio). Il valore attuale di un attestato è sempre ammesso.
 */
function apac_corso_from_post( $back, $current = '' ) {
	$corso = trim( sanitize_text_field( apac_post( 'corso' ) ) );
	if ( '' === $corso ) {
		apac_error_field( 'corso' );
		apac_redirect( 'lavoratori', 'need_field', $back );
	}
	if ( 'Altro' === $corso ) {
		$altro = trim( sanitize_text_field( apac_post( 'corso_altro' ) ) );
		if ( '' === $altro ) {
			apac_error_field( 'corso_altro' );
			apac_redirect( 'lavoratori', 'need_corso_altro', $back );
		}
		$corso = $altro;
		$field = 'corso_altro';
	} else {
		if ( ! in_array( $corso, apac_corsi(), true ) && $corso !== $current ) {
			apac_error_field( 'corso' );
			apac_redirect( 'lavoratori', 'bad_value', $back );
		}
		$field = 'corso';
	}
	if ( mb_strlen( $corso ) > 150 ) {
		apac_error_field( $field );
		apac_redirect( 'lavoratori', 'too_long', $back );
	}
	return $corso;
}

function apac_op_attestato_save( $azienda_id ) {
	$lav_id = absint( apac_post( 'lavoratore_id' ) );
	$lav    = apac_get_row_for_azienda( 'lavoratori', $lav_id, $azienda_id );
	$back   = array( 'lav' => $lav_id );
	if ( ! $lav ) {
		apac_redirect( 'lavoratori', 'bad_lavoratore' );
	}
	$corso = apac_corso_from_post( $back );
	list( $cons, $scad ) = apac_attestato_dates( $back );
	apac_check_lengths( array( 'note' => (string) apac_post( 'note' ) ), 'lavoratori', $back );
	$doc_id              = apac_attestato_store_file( $azienda_id, $corso, $lav, $back );

	apac_must_insert(
		'attestati',
		array(
			'azienda_id'         => $azienda_id,
			'lavoratore_id'      => $lav_id,
			'corso'              => $corso,
			'data_conseguimento' => $cons,
			'data_scadenza'      => $scad,
			'documento_id'       => $doc_id,
			'note'               => sanitize_textarea_field( apac_post( 'note' ) ),
			'created_at'         => apac_now(),
		),
		'lavoratori',
		$back
	);
	apac_log_detail( $corso . ' – ' . $lav->cognome . ' ' . $lav->nome );
	apac_redirect( 'lavoratori', 'saved', $back );
}

function apac_op_attestato_delete( $azienda_id ) {
	global $wpdb;
	$id  = absint( apac_post( 'id' ) );
	$row = apac_get_row_for_azienda( 'attestati', $id, $azienda_id );
	if ( ! $row ) {
		apac_redirect( 'lavoratori', 'denied' );
	}
	$back = array( 'lav' => (int) $row->lavoratore_id );
	apac_must( apac_avvisi_reset( 'attestato', $id ), 'lavoratori', $back );
	apac_must( $wpdb->delete( apac_table( 'attestati' ), array( 'id' => $id, 'azienda_id' => $azienda_id ) ), 'lavoratori', $back, true );
	apac_log_detail( $row->corso );
	apac_redirect( 'lavoratori', 'deleted', $back );
}

/* ----------------------------- Documenti ------------------------------ */

function apac_op_documento_upload( $azienda_id ) {
	global $wpdb;
	$categoria = sanitize_key( apac_post( 'categoria' ) );
	$titolo    = trim( sanitize_text_field( apac_post( 'titolo' ) ) );
	if ( ! array_key_exists( $categoria, apac_categorie_documenti() ) ) {
		apac_error_field( 'categoria' );
		apac_redirect( 'documenti', 'bad_value' );
	}
	apac_check_lengths( array( 'titolo' => $titolo ), 'documenti' );
	if ( empty( $_FILES['file']['name'] ) ) {
		apac_error_field( 'file' );
		apac_redirect( 'documenti', 'need_field' );
	}
	$stored = apac_store_upload( $_FILES['file'], $azienda_id ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( is_wp_error( $stored ) ) {
		apac_error_field( 'file' );
		apac_redirect( 'documenti', $stored->get_error_code() );
	}
	apac_must_insert(
		'documenti',
		array(
			'azienda_id'  => $azienda_id,
			'titolo'      => '' !== $titolo ? $titolo : $stored['name'],
			'categoria'   => $categoria,
			'nome_file'   => $stored['name'],
			'file_path'   => $stored['path'],
			'mime'        => $stored['mime'],
			'dimensione'  => $stored['size'],
			'caricato_da' => get_current_user_id(),
			'created_at'  => apac_now(),
			'richiede_firma' => ( apac_is_admin() && apac_post( 'richiede_firma' ) ) ? 1 : 0,
		),
		'documenti'
	);
	if ( apac_is_admin() ) {
		$t_push = '' !== $titolo ? $titolo : $stored['name'];
		$firma  = (bool) apac_post( 'richiede_firma' );
		apac_push_after_commit(
			function () use ( $azienda_id, $t_push, $firma ) {
				apac_push_azienda( $azienda_id, $firma ? 'Documento da firmare' : 'Nuovo documento', $t_push, apac_url( 'documenti' ) );
			}
		);
	}
	// Avviso all'amministratore quando carica un cliente (solo a salvataggio riuscito).
	if ( ! apac_is_admin() ) {
		$az     = apac_get_azienda( $azienda_id );
		$t_mail = '' !== $titolo ? $titolo : $stored['name'];
		apac_tx_after_commit(
			function () use ( $az, $t_mail ) {
				$ok = apac_send_mail(
					get_option( 'admin_email' ),
					'[Area clienti] Nuovo documento da ' . $az->ragione_sociale,
					"È stato caricato un nuovo documento.\n\nAzienda: {$az->ragione_sociale}\nTitolo: {$t_mail}\n\n" . apac_portal_page_url(),
					apac_auto_headers()
				);
				return $ok ? '' : 'uploaded_nomail';
			}
		);
	}
	apac_log_detail( '' !== $titolo ? $titolo : $stored['name'] );
	apac_redirect( 'documenti', 'uploaded' );
}

function apac_op_documento_delete( $azienda_id ) {
	global $wpdb;
	$id  = absint( apac_post( 'id' ) );
	$doc = apac_get_row_for_azienda( 'documenti', $id, $azienda_id );
	// Il cliente può eliminare solo i documenti caricati da lui stesso.
	if ( ! $doc || ( ! apac_is_admin() && (int) $doc->caricato_da !== get_current_user_id() ) ) {
		apac_redirect( 'documenti', 'denied' );
	}
	apac_must( $wpdb->update( apac_table( 'attestati' ), array( 'documento_id' => null ), array( 'documento_id' => $id, 'azienda_id' => $azienda_id ) ), 'documenti' );
	$versioni = apac_get_versioni( $id, $azienda_id );
	apac_must( $wpdb->delete( apac_table( 'documenti_versioni' ), array( 'documento_id' => $id, 'azienda_id' => $azienda_id ) ), 'documenti' );
	apac_must( $wpdb->delete( apac_table( 'firme' ), array( 'documento_id' => $id, 'azienda_id' => $azienda_id ) ), 'documenti' );
	apac_must( $wpdb->delete( apac_table( 'documenti' ), array( 'id' => $id, 'azienda_id' => $azienda_id ) ), 'documenti', array(), true );
	apac_delete_rows_files_after_commit( $versioni );
	// Il file si cancella solo dopo che la rimozione dal database è confermata.
	apac_tx_after_commit(
		function () use ( $doc ) {
			apac_delete_document_file( $doc );
		}
	);
	apac_log_detail( $doc->titolo );
	apac_redirect( 'documenti', 'deleted' );
}

/* ----------------------------- Scadenze ------------------------------- */

function apac_op_scadenza_save( $azienda_id ) {
	global $wpdb;
	$id        = absint( apac_post( 'id' ) );
	$categoria = sanitize_key( apac_post( 'categoria' ) );
	$titolo    = trim( sanitize_text_field( apac_post( 'titolo' ) ) );
	$lav_id    = absint( apac_post( 'lavoratore_id' ) );
	$note      = sanitize_textarea_field( apac_post( 'note' ) );
	$existing  = null;
	if ( $id ) {
		$existing = apac_get_row_for_azienda( 'scadenze', $id, $azienda_id );
		if ( ! $existing ) {
			apac_redirect( 'scadenziario', 'denied' );
		}
	}
	if ( '' === $titolo ) {
		apac_error_field( 'titolo' );
		apac_redirect( 'scadenziario', 'need_field' );
	}
	if ( ! array_key_exists( $categoria, apac_categorie_scadenze() ) ) {
		apac_error_field( 'categoria' );
		apac_redirect( 'scadenziario', 'bad_value' );
	}
	$parsed = apac_parse_date( apac_post( 'data_scadenza' ) );
	if ( ! $parsed['ok'] ) {
		apac_error_field( 'data_scadenza' );
		apac_redirect( 'scadenziario', 'invalid_date' );
	}
	if ( null === $parsed['date'] ) {
		apac_error_field( 'data_scadenza' );
		apac_redirect( 'scadenziario', 'need_date' );
	}
	$data = $parsed['date'];
	if ( $lav_id && ! apac_get_row_for_azienda( 'lavoratori', $lav_id, $azienda_id ) ) {
		apac_error_field( 'lavoratore_id' );
		apac_redirect( 'scadenziario', 'bad_lavoratore' );
	}
	apac_check_lengths( array( 'titolo' => $titolo, 'note' => $note ), 'scadenziario' );
	$row = array(
		'titolo'        => $titolo,
		'categoria'     => $categoria,
		'data_scadenza' => $data,
		'lavoratore_id' => $lav_id ? $lav_id : null,
		'note'          => $note,
	);
	$ric = (int) apac_post( 'ricorrenza_mesi', 0 );
	if ( ! array_key_exists( $ric, apac_ricorrenze() ) ) {
		apac_error_field( 'ricorrenza_mesi' );
		apac_redirect( 'scadenziario', 'bad_value' );
	}
	$row['ricorrenza_mesi'] = $ric;
	if ( $existing ) {
		// Gli avvisi ripartono SOLO se cambia la data: modificare descrizione o note
		// non deve far reinviare una soglia già notificata.
		if ( $data !== $existing->data_scadenza ) {
			$row['avviso_inviato'] = -1;
			apac_must( apac_avvisi_reset( 'scadenza', $id ), 'scadenziario' );
		}
		$row['modificata'] = 1; // Salvata a mano: non verrà mai tolta in automatico.
		apac_must( $wpdb->update( apac_table( 'scadenze' ), $row, array( 'id' => $id, 'azienda_id' => $azienda_id ) ), 'scadenziario' );
	} else {
		$row['azienda_id']     = $azienda_id;
		$row['stato']          = 'aperta';
		$row['avviso_inviato'] = -1;
		$row['created_at']     = apac_now();
		apac_must_insert( 'scadenze', $row, 'scadenziario' );
	}
	apac_log_detail( $titolo . ' – ' . apac_date_it( $data ) );
	apac_redirect( 'scadenziario', 'saved' );
}

function apac_op_scadenza_toggle( $azienda_id ) {
	global $wpdb;
	$id  = absint( apac_post( 'id' ) );
	$row = apac_get_row_for_azienda( 'scadenze', $id, $azienda_id );
	if ( ! $row ) {
		apac_redirect( 'scadenziario', 'denied' );
	}
	// 1.9.2: comando esplicito con lo stato atteso: da una scheda rimasta aperta non si inverte
	// per errore una scadenza già completata (o già riaperta) da un'altra scheda.
	$atteso = (string) apac_post( 'stato_atteso' );
	if ( ! in_array( $atteso, array( 'aperta', 'completata' ), true ) ) {
		apac_redirect( 'scadenziario', 'invalid' );
	}
	if ( $atteso !== $row->stato ) {
		apac_redirect( 'scadenziario', 'aperta' === $row->stato ? 'gia_aperta' : 'gia_completata' );
	}
	$riapri = ( 'completata' === $atteso );
	$nuovo  = $riapri ? 'aperta' : 'completata';
	// 1.9.3: il cambio di stato avviene SOLO se lo stato nel database è ancora quello atteso, in
	// un'unica istruzione. Con due invii contemporanei (due schede, codici monouso diversi) il
	// database ne fa passare uno solo: l'altro trova 0 righe e non crea una seconda scadenza successiva.
	$cambiate = $wpdb->query(
		$wpdb->prepare(
			'UPDATE ' . apac_table( 'scadenze' ) . ' SET stato = %s' . ( $riapri ? ', avviso_inviato = -1' : '' ) . ' WHERE id = %d AND azienda_id = %d AND stato = %s',
			$nuovo,
			$id,
			$azienda_id,
			$atteso
		)
	);
	apac_must( $cambiate, 'scadenziario' );
	if ( 1 !== (int) $cambiate ) {
		$ora = $wpdb->get_var( $wpdb->prepare( 'SELECT stato FROM ' . apac_table( 'scadenze' ) . ' WHERE id = %d AND azienda_id = %d', $id, $azienda_id ) );
		if ( null === $ora ) {
			apac_redirect( 'scadenziario', 'denied' ); // Eliminata nel frattempo.
		}
		apac_redirect( 'scadenziario', 'aperta' === $ora ? 'gia_aperta' : 'gia_completata' );
	}
	if ( $riapri ) {
		// Un adempimento riaperto torna da monitorare: gli avvisi ripartono da capo.
		apac_must( apac_avvisi_reset( 'scadenza', $id ), 'scadenziario' );
	}
	$prossima = $riapri ? '' : apac_crea_prossima_scadenza( $row );
	$tolta    = $riapri ? apac_rimuovi_prossima_automatica( $row ) : '';
	apac_log_detail( $row->titolo . ( $riapri ? ' – riaperta' : ' – completata' ) . ( $prossima ? ' (prossima: ' . apac_date_it( $prossima ) . ')' : '' ) . ( $tolta ? ' (rimossa la successiva del ' . apac_date_it( $tolta ) . ', creata in automatico)' : '' ) );
	apac_redirect( 'scadenziario', $prossima ? 'saved_next' : 'saved', $prossima ? array( 'prossima' => $prossima ) : array( 'stato' => $riapri ? 'aperta' : 'completata' ) );
}

function apac_op_scadenza_delete( $azienda_id ) {
	global $wpdb;
	$id = absint( apac_post( 'id' ) );
	if ( ! apac_get_row_for_azienda( 'scadenze', $id, $azienda_id ) ) {
		apac_redirect( 'scadenziario', 'denied' );
	}
	apac_must( apac_avvisi_reset( 'scadenza', $id ), 'scadenziario' );
	apac_must( $wpdb->delete( apac_table( 'scadenze' ), array( 'id' => $id, 'azienda_id' => $azienda_id ) ), 'scadenziario', array(), true );
	apac_redirect( 'scadenziario', 'deleted' );
}

/* --------------------------- Segnalazioni ----------------------------- */

function apac_op_segnalazione_create( $azienda_id ) {
	global $wpdb;
	$titolo   = trim( sanitize_text_field( apac_post( 'titolo' ) ) );
	$priorita = (string) apac_post( 'priorita', 'media' );
	if ( '' === $titolo ) {
		apac_error_field( 'titolo' );
		apac_redirect( 'segnalazioni', 'need_field' );
	}
	if ( ! in_array( $priorita, array( 'bassa', 'media', 'alta' ), true ) ) {
		apac_error_field( 'priorita' );
		apac_redirect( 'segnalazioni', 'bad_value' );
	}
	$descr = sanitize_textarea_field( apac_post( 'descrizione' ) );
	apac_check_lengths( array( 'titolo' => $titolo, 'descrizione' => $descr ), 'segnalazioni' );
	$seg_id = apac_must_insert(
		'segnalazioni',
		array(
			'azienda_id'  => $azienda_id,
			'titolo'      => $titolo,
			'descrizione' => $descr,
			'priorita'    => $priorita,
			'stato'       => 'aperta',
			'autore_id'   => get_current_user_id(),
			'created_at'  => apac_now(),
			'updated_at'  => apac_now(),
		),
		'segnalazioni'
	);
	apac_store_segnalazione_foto( $seg_id, $azienda_id );
	if ( ! apac_is_admin() ) {
		$az = apac_get_azienda( $azienda_id );
		apac_tx_after_commit(
			function () use ( $az, $priorita, $titolo, $descr ) {
				$ok = apac_send_mail(
					get_option( 'admin_email' ),
					'[Area clienti] Nuova segnalazione da ' . $az->ragione_sociale,
					"Azienda: {$az->ragione_sociale}\nPriorità: {$priorita}\nOggetto: {$titolo}\n\n{$descr}\n\n" . apac_portal_page_url(),
					apac_auto_headers()
				);
				return $ok ? '' : 'sent_nomail';
			}
		);
		apac_push_after_commit(
			function () use ( $az, $titolo ) {
				apac_push_admins( 'Nuova segnalazione', $az->ragione_sociale . ': ' . $titolo, apac_url( 'segnalazioni', array( 'azienda' => (int) $az->id ) ) );
			}
		);
	}
	apac_log_detail( $titolo );
	apac_redirect( 'segnalazioni', 'sent' );
}

function apac_op_segnalazione_update( $azienda_id ) {
	global $wpdb;
	$id    = absint( apac_post( 'id' ) );
	$row   = apac_get_row_for_azienda( 'segnalazioni', $id, $azienda_id );
	$stato = (string) apac_post( 'stato' );
	if ( ! $row ) {
		apac_redirect( 'segnalazioni', 'denied' );
	}
	if ( ! in_array( $stato, array( 'aperta', 'in_lavorazione', 'chiusa' ), true ) ) {
		apac_error_field( 'stato' );
		apac_redirect( 'segnalazioni', 'bad_value' );
	}
	apac_check_lengths( array( 'risposta' => sanitize_textarea_field( apac_post( 'risposta' ) ) ), 'segnalazioni' );
	apac_must(
		$wpdb->update(
			apac_table( 'segnalazioni' ),
			array(
				'stato'      => $stato,
				'risposta'   => sanitize_textarea_field( apac_post( 'risposta' ) ),
				'updated_at' => apac_now(),
			),
			array( 'id' => $id, 'azienda_id' => $azienda_id )
		),
		'segnalazioni'
	);
	$risposta = sanitize_textarea_field( apac_post( 'risposta' ) );
	if ( $risposta !== (string) $row->risposta || $stato !== $row->stato ) {
		apac_push_after_commit(
			function () use ( $azienda_id, $row, $risposta, $stato ) {
				$st = array( 'aperta' => 'aperta', 'in_lavorazione' => 'in lavorazione', 'chiusa' => 'chiusa' );
				apac_push_azienda( $azienda_id, 'Risposta dello studio', $row->titolo . ( $risposta !== (string) $row->risposta ? ': ' . $risposta : ' – ' . $st[ $stato ] ), apac_url( 'segnalazioni' ) );
			}
		);
	}
	apac_log_detail( $row->titolo );
	apac_redirect( 'segnalazioni', 'saved' );
}

/* ------------------------------ Utenti -------------------------------- */

function apac_op_utente_create( $azienda_id ) {
	$email   = apac_normalize_email( apac_post( 'email' ) );
	$nome    = trim( sanitize_text_field( apac_post( 'nome' ) ) );
	$cognome = trim( sanitize_text_field( apac_post( 'cognome' ) ) );
	if ( '' === $nome ) {
		apac_error_field( 'nome' );
		apac_redirect( 'utenti', 'need_field' );
	}
	if ( ! $email ) {
		apac_error_field( 'email' );
		apac_redirect( 'utenti', '' === $email ? 'need_field' : 'bad_email' );
	}
	apac_check_lengths( array( 'nome' => $nome, 'cognome' => $cognome, 'email' => $email ), 'utenti' );
	if ( email_exists( $email ) || username_exists( $email ) ) {
		apac_error_field( 'email' );
		apac_redirect( 'utenti', 'user_exists' );
	}
	$user_id = wp_insert_user(
		array(
			'user_login'   => $email,
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 24, true, true ),
			'first_name'   => $nome,
			'last_name'    => $cognome,
			'display_name' => trim( $nome . ' ' . $cognome ),
			'role'         => APAC_ROLE,
		)
	);
	if ( is_wp_error( $user_id ) ) {
		apac_redirect( 'utenti', 'db_error' );
	}
	// 1.9.14: se l'operazione viene annullata dopo questo punto, l'utente non deve restare. Con tabelle
	// InnoDB il ROLLBACK lo toglie già (qui si svuota solo la cache, che altrimenti lo mostrerebbe ancora);
	// con tabelle di WordPress non transazionali (es. MyISAM) viene eliminato esplicitamente.
	apac_tx_on_rollback(
		function () use ( $user_id ) {
			global $wpdb;
			clean_user_cache( (int) $user_id );
			wp_cache_delete( (int) $user_id, 'user_meta' );
			$ancora = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID = %d", (int) $user_id ) );
			if ( $ancora ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( (int) $user_id );
				clean_user_cache( (int) $user_id );
			}
		}
	);
	update_user_meta( $user_id, 'apac_azienda_id', $azienda_id );
	if ( (int) get_user_meta( $user_id, 'apac_azienda_id', true ) !== (int) $azienda_id ) {
		apac_redirect( 'utenti', 'db_error' );
	}
	update_user_meta( $user_id, 'apac_accesso', 'lettura' === apac_post( 'accesso' ) ? 'lettura' : 'modifica' );
	// Email con il link per la password: solo a creazione confermata, con verifica dell'invio.
	apac_tx_after_commit(
		function () use ( $user_id ) {
			$failed = false;
			$catch  = function ( $e ) use ( &$failed ) {
				$failed = true;
				apac_record_mail_error( 'nuovo utente', $e instanceof WP_Error ? $e->get_error_message() : '' );
			};
			// Invio bloccato da un altro plugin (pre_wp_mail = false): anche questo è un fallimento.
			$short = function ( $ret ) use ( &$failed ) {
				if ( false === $ret ) {
					$failed = true;
					apac_record_mail_error( 'nuovo utente', 'invio bloccato prima della spedizione' );
				}
				return $ret;
			};
			add_action( 'wp_mail_failed', $catch );
			add_filter( 'pre_wp_mail', $short, PHP_INT_MAX );
			wp_new_user_notification( $user_id, null, 'user' );
			remove_action( 'wp_mail_failed', $catch );
			remove_filter( 'pre_wp_mail', $short, PHP_INT_MAX );
			return $failed ? 'user_created_nomail' : '';
		}
	);
	apac_log_detail( trim( $nome . ' ' . $cognome ) . ' – ' . $email );
	apac_redirect( 'utenti', 'user_created' );
}

/** Utente cliente modificabile dall'amministratore (mai un amministratore o un redattore). */
function apac_get_client_user( $user_id ) {
	$user = get_userdata( $user_id );
	if ( ! $user || ! in_array( APAC_ROLE, (array) $user->roles, true ) || user_can( $user, 'manage_options' ) || user_can( $user, 'edit_posts' ) ) {
		return null;
	}
	return $user;
}

/** Revoca l'accesso all'area riservata senza eliminare l'account. */
function apac_op_utente_revoca( $unused ) {
	$user_id = absint( apac_post( 'user_id' ) );
	$user    = apac_get_client_user( $user_id );
	$az      = apac_user_azienda_id( $user_id );
	if ( ! $user ) {
		apac_redirect( 'utenti', 'denied', array( 'azienda' => $az ) );
	}
	update_user_meta( $user_id, 'apac_revocato', 1 );
	if ( ! get_user_meta( $user_id, 'apac_revocato', true ) ) {
		apac_redirect( 'utenti', 'db_error', array( 'azienda' => $az ) );
	}
	// Chiude le sessioni aperte: l'utente esce subito.
	apac_tx_after_commit(
		function () use ( $user_id ) {
			WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		}
	);
	$GLOBALS['apac_log_ctx']['azienda_id'] = $az;
	apac_log_detail( $user->display_name . ' – ' . $user->user_email );
	apac_redirect( 'utenti', 'user_revoked', array( 'azienda' => $az ) );
}

function apac_op_utente_riattiva( $unused ) {
	$user_id = absint( apac_post( 'user_id' ) );
	$user    = apac_get_client_user( $user_id );
	$az      = apac_user_azienda_id( $user_id );
	if ( ! $user ) {
		apac_redirect( 'utenti', 'denied', array( 'azienda' => $az ) );
	}
	delete_user_meta( $user_id, 'apac_revocato' );
	if ( get_user_meta( $user_id, 'apac_revocato', true ) ) {
		apac_redirect( 'utenti', 'db_error', array( 'azienda' => $az ) );
	}
	$GLOBALS['apac_log_ctx']['azienda_id'] = $az;
	apac_log_detail( $user->display_name . ' – ' . $user->user_email );
	apac_redirect( 'utenti', 'user_reactivated', array( 'azienda' => $az ) );
}

/** Eliminazione definitiva dell'account (operazione separata, con conferma scritta). */
function apac_op_utente_elimina( $unused ) {
	$user_id = absint( apac_post( 'user_id' ) );
	$user    = apac_get_client_user( $user_id );
	$az      = apac_user_azienda_id( $user_id );
	if ( ! $user ) {
		apac_redirect( 'utenti', 'denied', array( 'azienda' => $az ) );
	}
	if ( 'ELIMINA' !== apac_post( 'conferma' ) ) {
		apac_redirect( 'utenti', 'invalid', array( 'azienda' => $az ) );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$GLOBALS['apac_log_ctx']['azienda_id'] = $az;
	apac_log_detail( $user->display_name . ' – ' . $user->user_email );
	// Eventuali contenuti dell'utente passano all'amministratore invece di essere cancellati.
	if ( ! wp_delete_user( $user_id, get_current_user_id() ) ) {
		apac_redirect( 'utenti', 'db_error', array( 'azienda' => $az ) );
	}
	apac_redirect( 'utenti', 'user_removed', array( 'azienda' => $az ) );
}

/** 1.8.1: svuota il registro attività (solo studio, con conferma). Resta una riga che ne tiene traccia. */
function apac_op_log_svuota( $azienda_id ) {
	global $wpdb;
	if ( 'ELIMINA' !== apac_post( 'conferma' ) ) {
		apac_redirect( 'pannello', 'invalid' );
	}
	apac_must( $wpdb->query( 'DELETE FROM ' . apac_table( 'log' ) ), 'log' );
	apac_redirect( 'pannello', 'deleted' );
}
