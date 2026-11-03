<?php
/**
 * 1.9.15 – Registro unico delle operazioni dei moduli dell'area clienti.
 *
 * Ogni operazione inviata al portale (campo "op") DEVE essere qui: il dispatcher (apac_handle_action)
 * rifiuta ogni operazione non registrata e non chiama più funzioni scelte dal nome inviato.
 * Per aggiungere un'operazione: scrivere la funzione apac_op_<nome>( $azienda_id ) e aggiungere qui la voce;
 * il test "registro" fallisce se una funzione apac_op_* non è registrata.
 *
 * Campi di ogni voce (valori predefiniti in apac_operazione_default()):
 *  - fn                 funzione da chiamare, con l'azienda di lavoro come argomento;
 *  - ruolo              'studio' (solo amministratori) oppure 'cliente' (clienti e studio);
 *  - permesso           permesso del cliente per l'azienda ('azienda', 'lavoratori', 'scadenze', 'documenti')
 *                       oppure '' (nessun permesso specifico); lo studio ha sempre tutti i permessi;
 *  - ambito             'azienda' = serve un'azienda a cui l'utente ha accesso; 'nessuno' = l'operazione
 *                       non riguarda un'azienda o la individua da sé (utenti, richieste, registro);
 *  - sola_lettura       true se è consentita anche agli accessi in sola consultazione;
 *  - prima_delle_firme  true se è consentita prima delle firme del primo accesso (solo la firma stessa);
 *  - transazione        false solo per operazioni che non scrivono dati del plugin;
 *  - sezione            sezione a cui tornare dopo un rifiuto o un doppio invio (come nelle versioni
 *                       precedenti: quella del permesso richiesto, altrimenti il riepilogo).
 */
defined( 'ABSPATH' ) || exit;

/** Valori predefiniti di una voce del registro (i più restrittivi). */
function apac_operazione_default() {
	return array(
		'fn'                => '',
		'ruolo'             => 'studio',
		'permesso'          => '',
		'ambito'            => 'azienda',
		'sola_lettura'      => false,
		'prima_delle_firme' => false,
		'transazione'       => true,
		'sezione'           => 'dashboard',
	);
}

/**
 * Registro completo.
 *
 * @return array<string, array<string, mixed>>
 */
function apac_operazioni() {
	static $reg = null;
	if ( null !== $reg ) {
		return $reg;
	}
	$studio  = array( 'ruolo' => 'studio' );
	$cliente = array( 'ruolo' => 'cliente' );
	$nessuno = array( 'ambito' => 'nessuno' );
	$voci    = array(
		// Aziende.
		'azienda_create'        => $studio + $nessuno,
		'azienda_save'          => $cliente + array( 'permesso' => 'azienda', 'sezione' => 'azienda' ),
		'azienda_delete'        => $studio,
		'azienda_permessi'      => $studio,
		'primi_passi'           => $studio,
		// Lavoratori e attestati.
		'lavoratore_save'       => $cliente + array( 'permesso' => 'lavoratori', 'sezione' => 'lavoratori' ),
		'lavoratore_delete'     => $cliente + array( 'permesso' => 'lavoratori', 'sezione' => 'lavoratori' ),
		'lavoratore_cessa'      => $cliente + array( 'permesso' => 'lavoratori', 'sezione' => 'lavoratori' ),
		'lavoratore_riattiva'   => $cliente + array( 'permesso' => 'lavoratori', 'sezione' => 'lavoratori' ),
		'attestato_save'        => $cliente + array( 'permesso' => 'lavoratori', 'sezione' => 'lavoratori' ),
		'attestato_update'      => $cliente + array( 'permesso' => 'lavoratori', 'sezione' => 'lavoratori' ),
		'attestato_delete'      => $cliente + array( 'permesso' => 'lavoratori', 'sezione' => 'lavoratori' ),
		'import_preview'        => $cliente + array( 'permesso' => 'lavoratori', 'sezione' => 'lavoratori' ),
		'import_confirm'        => $cliente + array( 'permesso' => 'lavoratori', 'sezione' => 'lavoratori' ),
		'mansione_corso_add'    => $studio,
		'mansione_corso_delete' => $studio,
		// Scadenze.
		'scadenza_save'         => $cliente + array( 'permesso' => 'scadenze', 'sezione' => 'scadenziario' ),
		'scadenza_toggle'       => $cliente + array( 'permesso' => 'scadenze', 'sezione' => 'scadenziario' ),
		'scadenza_delete'       => $cliente + array( 'permesso' => 'scadenze', 'sezione' => 'scadenziario' ),
		// Documenti e firme.
		'documento_upload'      => $cliente + array( 'permesso' => 'documenti', 'sezione' => 'documenti' ),
		'documento_update'      => $cliente + array( 'permesso' => 'documenti', 'sezione' => 'documenti' ),
		'documento_delete'      => $cliente + array( 'permesso' => 'documenti', 'sezione' => 'documenti' ),
		'documento_firma'       => $cliente + array( 'sola_lettura' => true ),
		'consenso_firma'        => $cliente + array( 'sola_lettura' => true, 'prima_delle_firme' => true ),
		// Segnalazioni e richieste di corsi (consentite anche in sola consultazione: è il canale per chiedere correzioni).
		'segnalazione_create'   => $cliente + array( 'sola_lettura' => true ),
		'corso_richiesta'       => $cliente + array( 'sola_lettura' => true ),
		'segnalazione_update'   => $studio,
		'segnalazione_delete'   => $studio,
		// Utenti.
		'utente_create'         => $studio,
		'utente_update'         => $studio + $nessuno,
		'utente_revoca'         => $studio + $nessuno,
		'utente_riattiva'       => $studio + $nessuno,
		'utente_elimina'        => $studio + $nessuno,
		'utente_reset'          => $studio + $nessuno + array( 'transazione' => false ),
		// Richieste di accesso.
		'richiesta_collega'     => $cliente,
		'richiesta_approva'     => $studio + $nessuno,
		'richiesta_rifiuta'     => $studio + $nessuno,
		'richiesta_elimina'     => $studio + $nessuno,
		// Registro attività.
		'log_svuota'            => $studio + $nessuno,
	);
	$def = apac_operazione_default();
	$reg = array();
	foreach ( $voci as $op => $voce ) {
		$voce['fn'] = 'apac_op_' . $op;
		$reg[ $op ] = array_merge( $def, $voce );
	}
	return $reg;
}

/** Voce del registro oppure null se l'operazione non esiste. */
function apac_operazione( $op ) {
	$reg = apac_operazioni();
	return isset( $reg[ $op ] ) ? $reg[ $op ] : null;
}

/** Permesso richiesto da un'operazione ('' = nessun permesso specifico). Compatibilità con le versioni precedenti. */
function apac_op_permission( $op ) {
	$o = apac_operazione( $op );
	return $o ? (string) $o['permesso'] : '';
}

/** Sezione a cui tornare dopo un rifiuto. Compatibilità con le versioni precedenti. */
function apac_op_section( $op ) {
	$o = apac_operazione( $op );
	return $o ? (string) $o['sezione'] : 'dashboard';
}
