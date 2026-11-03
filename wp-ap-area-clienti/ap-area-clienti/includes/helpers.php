<?php
defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Accesso e isolamento tra aziende
 * ---------------------------------------------------------------------- */

/** L'utente corrente è l'amministratore (consulente)? */
function apac_is_admin() {
	return current_user_can( 'manage_options' );
}

/** Azienda collegata a un utente cliente (0 se nessuna). */
function apac_user_azienda_id( $user_id = 0 ) {
	$user_id = $user_id ? $user_id : get_current_user_id();
	return (int) get_user_meta( $user_id, 'apac_azienda_id', true );
}

/** Accesso all'area riservata revocato dallo studio? */
function apac_user_revoked( $user_id = 0 ) {
	$user_id = $user_id ? $user_id : get_current_user_id();
	return (bool) get_user_meta( $user_id, 'apac_revocato', true );
}

/** L'utente corrente può accedere all'area clienti? */
function apac_user_can_use_portal() {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	if ( apac_is_admin() ) {
		return true;
	}
	if ( apac_user_revoked() ) {
		return false;
	}
	$azienda_id = apac_user_azienda_id();
	return $azienda_id > 0 && apac_get_azienda( $azienda_id );
}

/**
 * Azienda su cui sta lavorando l'utente.
 * - Cliente: SEMPRE quella del proprio profilo; i parametri della richiesta vengono ignorati.
 * - Amministratore: quella scelta nel selettore (?azienda=ID), se esiste.
 */
function apac_current_azienda_id() {
	if ( ! is_user_logged_in() ) {
		return 0;
	}
	if ( ! apac_is_admin() ) {
		return apac_user_revoked() ? 0 : apac_user_azienda_id();
	}
	$id = 0;
	if ( isset( $_REQUEST['azienda'] ) ) {
		$id = absint( wp_unslash( $_REQUEST['azienda'] ) );
	}
	return ( $id && apac_get_azienda( $id ) ) ? $id : 0;
}

/** L'utente corrente può accedere ai dati di questa azienda? */
function apac_can_access_azienda( $azienda_id ) {
	$azienda_id = (int) $azienda_id;
	if ( $azienda_id <= 0 || ! is_user_logged_in() ) {
		return false;
	}
	if ( apac_is_admin() ) {
		return (bool) apac_get_azienda( $azienda_id );
	}
	return ! apac_user_revoked() && apac_user_azienda_id() === $azienda_id;
}

/**
 * Condizione SQL: l'attestato è quello in vigore, cioè non è stato rinnovato
 * da un attestato successivo dello stesso corso per lo stesso lavoratore.
 * Gli attestati rinnovati restano nello storico ma non generano scadenze.
 */
function apac_sql_attestato_corrente( $alias = 'a' ) {
	$t = apac_table( 'attestati' );
	$k = "COALESCE(%1\$s.data_conseguimento, '1000-01-01')";
	$k2 = "COALESCE(r.data_conseguimento, '1000-01-01')";
	$self = sprintf( $k, $alias );
	return "NOT EXISTS (SELECT 1 FROM {$t} r WHERE r.azienda_id = {$alias}.azienda_id AND r.lavoratore_id = {$alias}.lavoratore_id AND r.corso = {$alias}.corso AND ( {$k2} > {$self} OR ( {$k2} = {$self} AND r.id > {$alias}.id ) ))";
}

/* -------------------------------------------------------------------------
 * Lettura dati
 * ---------------------------------------------------------------------- */

function apac_get_azienda( $id ) {
	global $wpdb;
	static $cache = array();
	$id = (int) $id;
	if ( $id <= 0 ) {
		return null;
	}
	if ( ! array_key_exists( $id, $cache ) ) {
		$cache[ $id ] = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'aziende' ) . ' WHERE id = %d', $id ) );
	}
	return $cache[ $id ];
}

/**
 * Tutte le aziende in ordine alfabetico (maiuscole e accenti indifferenti).
 * Da usare solo per elaborazioni lato server (es. invio avvisi), non per i menu.
 */
function apac_get_aziende() {
	global $wpdb;
	return $wpdb->get_results( 'SELECT * FROM ' . apac_table( 'aziende' ) . ' ORDER BY ragione_sort ASC, id ASC' );
}

/** Riga di una tabella appartenente a una specifica azienda (null se non appartiene). */
function apac_get_row_for_azienda( $table, $id, $azienda_id ) {
	global $wpdb;
	return $wpdb->get_row(
		$wpdb->prepare(
			'SELECT * FROM ' . apac_table( $table ) . ' WHERE id = %d AND azienda_id = %d',
			(int) $id,
			(int) $azienda_id
		)
	);
}

function apac_get_lavoratori( $azienda_id, $solo_attivi = false ) {
	global $wpdb;
	$sql = 'SELECT * FROM ' . apac_table( 'lavoratori' ) . ' WHERE azienda_id = %d';
	if ( $solo_attivi ) {
		$sql .= ' AND attivo = 1';
	}
	$sql .= ' ORDER BY cognome ASC, nome ASC';
	return $wpdb->get_results( $wpdb->prepare( $sql, (int) $azienda_id ) );
}

/* -------------------------------------------------------------------------
 * Elenchi di valori
 * ---------------------------------------------------------------------- */

function apac_categorie_documenti() {
	return array(
		'dvr'          => 'DVR – Documento di valutazione dei rischi',
		'nomine'       => 'Nomine e designazioni',
		'formazione'   => 'Attestati di formazione',
		'emergenze'    => 'Piano di emergenza ed evacuazione',
		'verbali'      => 'Verbali e riunioni periodiche',
		'impianti'     => 'Impianti, attrezzature e verifiche',
		'cantiere'     => 'Documenti di cantiere (POS, PSC, ecc.)',
		'sds'          => 'Schede di dati di sicurezza',
		'dpi'          => 'Consegna DPI',
		'altro'        => 'Altro',
	);
}

function apac_corsi() {
	return array(
		'Formazione generale lavoratori',
		'Formazione specifica lavoratori',
		'Preposto',
		'Dirigente',
		'Datore di lavoro',
		'Datore di lavoro RSPP',
		'RSPP / ASPP',
		'RLS',
		'Addetto antincendio',
		'Addetto primo soccorso',
		'BLSD / PBLSD',
		'Lavori in quota / DPI III categoria',
		'Attrezzature (abilitazione)',
		'Altro',
	);
}

function apac_categorie_scadenze() {
	return array(
		'formazione'  => 'Formazione',
		'sanitaria'   => 'Sorveglianza sanitaria (solo data)',
		'verifiche'   => 'Verifiche impianti e attrezzature',
		'antincendio' => 'Presidi antincendio',
		'dvr'         => 'Revisione DVR',
		'riunione'    => 'Riunione periodica',
		'altro'       => 'Altro',
	);
}

/* -------------------------------------------------------------------------
 * Formattazione e utilità
 * ---------------------------------------------------------------------- */

function apac_date_it( $date ) {
	if ( empty( $date ) || '0000-00-00' === substr( (string) $date, 0, 10 ) ) {
		return '—';
	}
	$ts = strtotime( substr( (string) $date, 0, 10 ) );
	return $ts ? gmdate( 'd/m/Y', $ts ) : '—';
}

/** Giorni da oggi (fuso del sito) alla data. Negativo = già scaduta. */
function apac_days_until( $date ) {
	if ( empty( $date ) ) {
		return null;
	}
	$today  = new DateTime( current_time( 'Y-m-d' ) );
	$target = DateTime::createFromFormat( 'Y-m-d', substr( (string) $date, 0, 10 ) );
	if ( ! $target ) {
		return null;
	}
	$target->setTime( 0, 0, 0 );
	$diff = $today->diff( $target );
	return (int) $diff->format( '%r%a' );
}

/** 1.9.9: "1 attestato" / "2 attestati". */
function apac_n( $n, $sing, $plur ) {
	$n = (int) $n;
	return $n . ' ' . ( 1 === $n ? $sing : $plur );
}

function apac_today() {
	return current_time( 'Y-m-d' );
}

function apac_now() {
	return current_time( 'mysql' );
}

/** Data valida in formato Y-m-d oppure null. */
function apac_sanitize_date( $value ) {
	$value = sanitize_text_field( (string) $value );
	if ( '' === $value ) {
		return null;
	}
	$d = DateTime::createFromFormat( 'Y-m-d', $value );
	return ( $d && $d->format( 'Y-m-d' ) === $value ) ? $value : null;
}

function apac_human_size( $bytes ) {
	return size_format( (int) $bytes, 1 );
}

/** Stato visivo di una scadenza. */
function apac_badge_scadenza( $date ) {
	$days = apac_days_until( $date );
	if ( null === $days ) {
		return '<span class="apac-badge apac-badge--muted">Nessuna scadenza</span>';
	}
	if ( $days < 0 ) {
		return '<span class="apac-badge apac-badge--danger">Scaduta da ' . absint( $days ) . ' gg</span>';
	}
	if ( 0 === $days ) {
		return '<span class="apac-badge apac-badge--danger">Scade oggi</span>';
	}
	if ( $days <= 30 ) {
		return '<span class="apac-badge apac-badge--warn">Tra ' . (int) $days . ' gg</span>';
	}
	return '<span class="apac-badge apac-badge--ok">Tra ' . (int) $days . ' gg</span>';
}

/* -------------------------------------------------------------------------
 * URL del portale e messaggi
 * ---------------------------------------------------------------------- */

function apac_portal_page_url() {
	$page_id = (int) get_option( 'apac_page_id' );
	$url     = $page_id ? get_permalink( $page_id ) : '';
	return $url ? $url : home_url( '/' );
}

/** URL di una sezione del portale; per l'amministratore conserva l'azienda selezionata. */
function apac_url( $sez = 'dashboard', $args = array() ) {
	$base = array( 'sez' => $sez );
	if ( apac_is_admin() ) {
		$az = isset( $args['azienda'] ) ? (int) $args['azienda'] : apac_current_azienda_id();
		if ( $az ) {
			$base['azienda'] = $az;
		}
	}
	return add_query_arg( array_merge( $base, $args ), apac_portal_page_url() );
}

function apac_messages() {
	return array(
		'saved'        => array( 'ok', 'Dati salvati.' ),
		'deleted'      => array( 'ok', 'Elemento eliminato.' ),
		'already_done' => array( 'ok', 'Il modulo era già stato inviato: è stato considerato un solo invio, nessun duplicato creato. Controlla che il dato risulti salvato; se non compare, ripeti l’operazione.' ),
		'in_progress'  => array( 'error', 'Questo modulo è ancora in elaborazione: attendi qualche istante e ricarica la pagina per controllare se il dato risulta salvato. Se non compare, ripeti l’operazione. Nessuna operazione è stata eseguita da questo invio.' ),
		'esito_ignoto' => array( 'error', 'Questo modulo era stato inviato prima dell’aggiornamento dell’area clienti e non è possibile stabilire se era stato salvato. Ricarica la pagina e controlla i dati; se mancano, ripeti l’operazione. Nessuna operazione è stata eseguita da questo invio.' ),
		'reload_needed' => array( 'error', 'Il modulo non è più valido (pagina aperta da tempo o aggiornata): ricarica la pagina e riprova. Nessun dato è stato salvato.' ),
		'gia_completata' => array( 'ok', 'La scadenza era già stata completata (forse da un’altra finestra): nessuna modifica.' ),
		'gia_aperta'     => array( 'ok', 'La scadenza era già aperta (forse riaperta da un’altra finestra): nessuna modifica.' ),
		'uploaded'     => array( 'ok', 'Documento caricato.' ),
		'user_created' => array( 'ok', 'Utente creato. Gli è stata inviata un’email per impostare la password.' ),
		'user_removed' => array( 'ok', 'Account eliminato definitivamente.' ),
		'sent'         => array( 'ok', 'Segnalazione inviata.' ),
		'denied'       => array( 'error', 'Operazione non consentita.' ),
		'invalid'      => array( 'error', 'Dati mancanti o non validi: controlla i campi obbligatori.' ),
		'upload_error' => array( 'error', 'Caricamento non riuscito: verifica il tipo e la dimensione del file.' ),
		'file_type'    => array( 'error', 'Tipo di file non ammesso. Formati consentiti: PDF, Word, Excel, OpenDocument, JPG, PNG.' ),
		'user_exists'  => array( 'error', 'Esiste già un utente con questa email o questo nome utente.' ),
		'no_azienda'   => array( 'error', 'Seleziona prima un’azienda.' ),
		'reset_sent'   => array( 'ok', 'Email per reimpostare la password inviata.' ),
		'readonly'     => array( 'error', 'Questa sezione è in sola lettura per il tuo account.' ),
		'db_error'     => array( 'error', 'Operazione non riuscita per un errore del database: nessuna modifica è stata salvata. Riprova; se il problema persiste contatta lo studio.' ),
		'cess_before_hire' => array( 'error', 'La data di cessazione è precedente alla data di assunzione: correggi una delle due date. Nessuna modifica è stata salvata.' ),
		'invalid_date' => array( 'error', 'Una delle date inserite non è valida: nessuna modifica è stata salvata.' ),
		'date_order'   => array( 'error', 'La data di scadenza deve essere successiva alla data di conseguimento.' ),
		'date_future'  => array( 'error', 'La data di conseguimento non può essere nel futuro.' ),
		'need_conseg'  => array( 'error', 'Indica la data di conseguimento dell’attestato.' ),
		'need_scad'    => array( 'error', 'Indica la data di scadenza oppure seleziona “Corso senza scadenza”.' ),
		'denied_upload' => array( 'error', 'Il tuo account non può caricare file: registra l’attestato senza allegato o chiedi allo studio.' ),
		'upload_blocked' => array( 'error', 'Caricamento sospeso: la protezione dei documenti sul server non risulta verificata. Contatta lo studio.' ),
		'reset_error'  => array( 'error', 'Non è stato possibile inviare l’email per reimpostare la password. Verifica la configurazione email del sito.' ),
		'user_created_nomail' => array( 'error', 'Utente creato, ma l’email con il link per la password NON è stata inviata. Usa “Invia nuova password” dopo aver verificato la configurazione email.' ),
		'user_revoked' => array( 'ok', 'Accesso revocato: l’utente non può più entrare nell’area riservata. L’account resta e può essere riattivato.' ),
		'user_reactivated' => array( 'ok', 'Accesso riattivato.' ),
		'uploaded_nomail' => array( 'warn', 'Documento caricato e salvato. L’email di avviso allo studio però NON è partita: lo studio vedrà comunque il documento nell’area riservata.' ),
		'sent_nomail'  => array( 'warn', 'Segnalazione salvata. L’email di avviso allo studio però NON è partita: la segnalazione resta comunque visibile nell’area riservata.' ),
		'too_long'     => array( 'error', 'Un campo supera la lunghezza massima consentita: accorcialo e riprova.' ),
		'need_field'   => array( 'error', 'Compila il campo obbligatorio indicato.' ),
		'need_date'    => array( 'error', 'Indica la data di scadenza.' ),
		'bad_value'    => array( 'error', 'Valore non ammesso: scegli una delle opzioni proposte.' ),
		'bad_email'    => array( 'error', 'Indirizzo email non valido: controlla di averlo scritto per intero (es. nome@azienda.it).' ),
		'bad_piva'     => array( 'error', 'Partita IVA non valida: deve contenere 11 cifre (il prefisso IT è facoltativo).' ),
		'bad_cf'       => array( 'error', 'Codice fiscale non valido: 16 caratteri per le persone, 11 cifre per le società.' ),
		'bad_prov'     => array( 'error', 'Provincia non valida: usa la sigla di due lettere (es. PA).' ),
		'bad_phone'    => array( 'error', 'Numero di telefono non valido: usa solo cifre, spazi e i simboli + ( ) - /.' ),
		'need_corso_altro' => array( 'error', 'Hai scelto “Altro”: scrivi il nome del corso.' ),
		'scad_conflict' => array( 'error', 'Hai indicato una data di scadenza ma anche “Corso senza scadenza”: scegli una delle due.' ),
		'bad_lavoratore' => array( 'error', 'Il lavoratore indicato non appartiene a questa azienda.' ),
		'storage_missing' => array( 'error', 'Caricamento sospeso: la cartella dei documenti sul server non è disponibile. Contatta lo studio.' ),
		'signed_docs'  => array( 'ok', 'Grazie, documenti firmati. Benvenuto nell’area riservata.' ),
		'consent_needed' => array( 'error', 'Prima di continuare firma i documenti richiesti.' ),
		'signed'       => array( 'ok', 'Presa visione firmata e registrata.' ),
		'course_sent'  => array( 'ok', 'Richiesta del corso inviata allo studio.' ),
		'need_consent' => array( 'error', 'Spunta la dichiarazione di presa visione.' ),
		'bad_signature' => array( 'error', 'La firma non è stata acquisita: firma nel riquadro e riprova.' ),
		'saved_next'   => array( 'ok', 'Completato. È stata creata la prossima scadenza dell’adempimento ricorrente.' ),
		'imported'     => array( 'ok', 'Importazione completata.' ),
		'import_ready' => array( 'ok', 'File letto: controlla l’anteprima e conferma.' ),
		'import_type'  => array( 'error', 'Formato non supportato: carica un file Excel (.xlsx) o CSV.' ),
		'import_noxlsx' => array( 'error', 'Il server non può leggere i file Excel: salva il foglio come CSV e caricalo di nuovo.' ),
		'import_bad'   => array( 'error', 'Il file non si riesce a leggere: aprilo in Excel, salvalo di nuovo come .xlsx e riprova.' ),
		'import_cols'  => array( 'error', 'Il file CSV contiene righe con un numero anomalo di separatori (più di 400): usa il modello oppure salvalo di nuovo da Excel e riprova.' ),
		'import_conflict' => array( 'error', 'Alcuni attestati hanno scadenze diverse in righe diverse: scegli quale riga tenere per ciascuno, poi conferma. Nulla è stato importato.' ),
		'import_header' => array( 'error', 'Nel file mancano le colonne “Cognome” e “Nome” nella prima riga: usa il modello.' ),
		'import_empty' => array( 'error', 'Il file non contiene righe da importare.' ),
		'import_big'   => array( 'error', 'File troppo grande: massimo 2000 righe e 5 MB. Dividilo in più file.' ),
		'import_expired' => array( 'error', 'Anteprima scaduta: carica di nuovo il file.' ),
		'too_many_photos' => array( 'error', 'Puoi allegare al massimo 3 foto.' ),
		'photo_type'   => array( 'error', 'Le foto devono essere in formato JPG o PNG.' ),
		'richiesta_inviata'   => array( 'ok', 'Richiesta inviata allo studio: il collega riceverà un’email per scegliere la password quando sarà approvata.' ),
		'richiesta_doppia'    => array( 'error', 'Per questa email c’è già una richiesta in attesa dello studio.' ),
		'richieste_troppe'    => array( 'error', 'Ci sono già troppe richieste in attesa per la tua azienda: attendi che lo studio le gestisca.' ),
		'richiesta_gestita'   => array( 'error', 'Questa richiesta era già stata gestita (forse da un’altra finestra): nessuna modifica.' ),
		'need_dest'           => array( 'error', 'Scegli se creare una nuova azienda o collegare il referente a un’azienda esistente.' ),
		'need_verifica'       => array( 'error', 'Per collegare il referente a un’azienda esistente conferma di aver verificato con l’azienda che è autorizzato.' ),
		'richiesta_rifiutata' => array( 'ok', 'Richiesta rifiutata. Il richiedente è stato avvisato via email.' ),
		'richiesta_rifiutata_nomail' => array( 'warn', 'Richiesta rifiutata, ma l’email al richiedente NON è partita: verifica la configurazione email e avvisalo direttamente.' ),
		'storage_write' => array( 'error', 'Caricamento non riuscito: il server non ha permesso di salvare il file. Nessuna modifica è stata salvata.' ),
	);
}

/** Reindirizza a una sezione con un messaggio e termina. */
/** Codici di esito che indicano un'operazione riuscita. */
function apac_success_codes() {
	return array( 'saved', 'deleted', 'uploaded', 'uploaded_nomail', 'user_created', 'user_created_nomail', 'user_removed', 'user_revoked', 'user_reactivated', 'sent', 'sent_nomail', 'reset_sent', 'saved_next', 'imported', 'import_ready', 'signed', 'course_sent', 'signed_docs', 'richiesta_inviata', 'richiesta_rifiutata', 'richiesta_rifiutata_nomail' );
}

/**
 * Reindirizza a una sezione con un messaggio e termina.
 * Se è aperta una transazione: con esito positivo la conferma (e solo dopo
 * elimina i file sostituiti e invia le email), altrimenti la annulla e
 * rimuove i file appena caricati.
 */
function apac_redirect( $sez, $msg = '', $args = array() ) {
	$ver_fatto = false;
	if ( apac_tx_active() ) {
		if ( in_array( $msg, apac_success_codes(), true ) ) {
			// 1.9.3: la versione dei dati dell'azienda aumenta nella STESSA transazione del salvataggio:
			// o si confermano entrambi o nessuno (niente riepiloghi non aggiornati dopo un'interruzione).
			// 1.9.4: anche l'esito del codice monouso fa parte dello stesso salvataggio.
			if ( ! apac_dati_cambiati_ctx() || ! apac_once_done() ) {
				apac_tx_rollback();
				$msg = 'db_error';
			} elseif ( ! apac_tx_commit() ) {
				$msg = 'db_error';
			} else {
				$ver_fatto = true;
				$override  = apac_tx_run_after_commit();
				if ( $override ) {
					$msg = $override;
				}
			}
		} else {
			apac_tx_rollback();
		}
	}
	if ( $msg && ! in_array( $msg, apac_success_codes(), true ) && ! in_array( $msg, array( 'already_done', 'reload_needed', 'in_progress', 'esito_ignoto' ), true ) && function_exists( 'apac_once_release' ) ) {
		apac_once_release();
	}
	// 1.9.1: dati dell'azienda cambiati -> l'analisi delle criticità salvata va ricalcolata.
	// Operazioni senza transazione (invio password): aumento fuori transazione, come prima.
	if ( ! $ver_fatto && $msg && in_array( $msg, apac_success_codes(), true ) ) {
		apac_dati_cambiati_ctx();
		apac_once_done();
	}
	if ( $msg && function_exists( 'apac_log_from_context' ) ) {
		apac_log_from_context( $msg, $args );
	}
	// Errore: i valori del modulo vengono conservati e riproposti.
	if ( $msg && ! in_array( $msg, apac_success_codes(), true ) && ! empty( $GLOBALS['apac_form_op'] ) ) {
		$token = apac_form_store_failed( $GLOBALS['apac_form_op'], $msg );
		if ( $token ) {
			$args['apac_form'] = $token;
		}
	}
	if ( $msg ) {
		$args['apac_msg'] = $msg;
		if ( in_array( $msg, apac_success_codes(), true ) && ! empty( $GLOBALS['apac_form_op'] ) ) {
			$args['apac_did'] = $GLOBALS['apac_form_op'];
		}
	}
	// 1.9.4: salvataggio riuscito -> eliminati i dati conservati dopo un errore precedente;
	// con "Salva e continua" si apre la pagina scelta (solo dello stesso sito).
	if ( $msg && in_array( $msg, apac_success_codes(), true ) && ! empty( $GLOBALS['apac_form_op'] ) ) {
		if ( function_exists( 'apac_form_ref_clear' ) ) {
			apac_form_ref_clear();
		}
		$next = function_exists( 'apac_next_url' ) ? apac_next_url() : '';
		if ( $next ) {
			wp_safe_redirect( add_query_arg( array( 'apac_msg' => $msg, 'apac_did' => $GLOBALS['apac_form_op'] ), $next ) );
			exit;
		}
	}
	wp_safe_redirect( apac_url( $sez, $args ) );
	exit;
}

/* -------------------------------------------------------------------------
 * Transazioni: tutto o niente
 * ---------------------------------------------------------------------- */

function apac_tx_state( $reset = false ) {
	static $state = null;
	if ( null === $state || $reset ) {
		$state = array(
			'active'       => false,
			'new_files'    => array(), // Da cancellare se l'operazione fallisce.
			'after_commit' => array(), // Da eseguire solo se l'operazione riesce.
			'on_rollback'  => array(), // 1.9.14: da eseguire se l'operazione viene annullata.
		);
	}
	return $state;
}

function apac_tx_set( $state ) {
	$GLOBALS['apac_tx'] = $state;
}

function apac_tx_get() {
	if ( empty( $GLOBALS['apac_tx'] ) ) {
		$GLOBALS['apac_tx'] = apac_tx_state();
	}
	return $GLOBALS['apac_tx'];
}

function apac_tx_active() {
	$s = apac_tx_get();
	return ! empty( $s['active'] );
}

function apac_tx_begin() {
	global $wpdb;
	$s           = apac_tx_state( true );
	$s['active'] = ( false !== $wpdb->query( 'START TRANSACTION' ) );
	apac_tx_set( $s );
	return $s['active'];
}

/** File appena salvato: verrà cancellato se l'operazione non va a buon fine. */
function apac_tx_track_new_file( $abs_path ) {
	$s                = apac_tx_get();
	$s['new_files'][] = $abs_path;
	apac_tx_set( $s );
}

/** Azione da eseguire solo dopo la conferma (cancellazione file, email). */
function apac_tx_after_commit( $callback ) {
	$s                   = apac_tx_get();
	$s['after_commit'][] = $callback;
	apac_tx_set( $s );
}

/**
 * 1.9.14 – Azione di compensazione da eseguire se l'operazione viene annullata (dopo il ROLLBACK).
 * Serve per ciò che il ROLLBACK potrebbe non annullare: tabelle di WordPress non InnoDB, cache persistente.
 */
function apac_tx_on_rollback( $callback ) {
	$s                  = apac_tx_get();
	$s['on_rollback'][] = $callback;
	apac_tx_set( $s );
}

function apac_tx_commit() {
	global $wpdb;
	$ok = ( false !== $wpdb->query( 'COMMIT' ) );
	if ( ! $ok ) {
		apac_tx_rollback();
		return false;
	}
	$s                = apac_tx_get();
	$s['active']      = false;
	$s['new_files']   = array();
	$s['on_rollback'] = array();
	apac_tx_set( $s );
	return true;
}

/** Esegue le azioni rimandate; un'azione può restituire un codice messaggio sostitutivo. */
function apac_tx_run_after_commit() {
	$s              = apac_tx_get();
	$override       = '';
	$callbacks      = $s['after_commit'];
	$s['after_commit'] = array();
	apac_tx_set( $s );
	foreach ( $callbacks as $cb ) {
		$r = call_user_func( $cb );
		if ( is_string( $r ) && '' !== $r ) {
			$override = $r;
		}
	}
	return $override;
}

function apac_tx_rollback() {
	global $wpdb;
	$s = apac_tx_get();
	if ( ! empty( $s['active'] ) ) {
		$wpdb->query( 'ROLLBACK' );
	}
	foreach ( $s['new_files'] as $f ) {
		if ( is_file( $f ) ) {
			wp_delete_file( $f );
		}
	}
	$undo = isset( $s['on_rollback'] ) ? array_reverse( (array) $s['on_rollback'] ) : array();
	apac_tx_set( apac_tx_state( true ) );
	foreach ( $undo as $cb ) {
		try {
			call_user_func( $cb );
		} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
		}
	}
}

/**
 * Controlla l'esito di una scrittura sul database: in caso di errore annulla
 * tutto e torna alla sezione con un messaggio di errore.
 *
 * @param mixed $result   Valore restituito da $wpdb->insert/update/delete.
 * @param bool  $need_row true se l'operazione deve toccare almeno una riga.
 */
function apac_must( $result, $sez, $args = array(), $need_row = false ) {
	if ( false === $result || ( $need_row && 0 === (int) $result ) ) {
		apac_redirect( $sez, 'db_error', $args );
	}
	return $result;
}

/** Inserimento con verifica; restituisce il nuovo ID. */
function apac_must_insert( $table, $data, $sez, $args = array() ) {
	global $wpdb;
	$r = $wpdb->insert( apac_table( $table ), $data );
	if ( false === $r || (int) $wpdb->insert_id <= 0 ) {
		apac_redirect( $sez, 'db_error', $args );
	}
	return (int) $wpdb->insert_id;
}

/* -------------------------------------------------------------------------
 * Email con esito verificato
 * ---------------------------------------------------------------------- */

/** Invia un'email e restituisce true solo se WordPress conferma l'invio; registra l'errore. */
function apac_send_mail( $to, $subject, $body, $headers = array() ) {
	$GLOBALS['apac_mail_error'] = '';
	$catch = function ( $error ) {
		$GLOBALS['apac_mail_error'] = $error instanceof WP_Error ? $error->get_error_message() : 'errore sconosciuto';
	};
	add_action( 'wp_mail_failed', $catch );
	$ok = (bool) wp_mail( $to, $subject, $body, $headers );
	remove_action( 'wp_mail_failed', $catch );
	if ( ! $ok ) {
		apac_record_mail_error( $to, $GLOBALS['apac_mail_error'] );
	}
	return $ok;
}

function apac_record_mail_error( $to, $error ) {
	update_option(
		'apac_mail_error',
		array(
			'to'    => is_array( $to ) ? implode( ', ', $to ) : (string) $to,
			'error' => (string) $error,
			'date'  => current_time( 'mysql' ),
		),
		false
	);
}

/* -------------------------------------------------------------------------
 * Date
 * ---------------------------------------------------------------------- */

/**
 * Legge una data dal modulo distinguendo campo vuoto e data errata.
 *
 * @return array [ 'ok' => bool, 'date' => 'Y-m-d'|null ]
 */
function apac_parse_date( $value ) {
	$value = trim( sanitize_text_field( (string) $value ) );
	if ( '' === $value ) {
		return array( 'ok' => true, 'date' => null );
	}
	$d = DateTime::createFromFormat( '!Y-m-d', $value );
	if ( ! $d || $d->format( 'Y-m-d' ) !== $value || (int) $d->format( 'Y' ) < 1950 || (int) $d->format( 'Y' ) > 2100 ) {
		return array( 'ok' => false, 'date' => null );
	}
	return array( 'ok' => true, 'date' => $value );
}

function apac_post( $key, $default = '' ) {
	return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default; // phpcs:ignore WordPress.Security.NonceVerification
}
