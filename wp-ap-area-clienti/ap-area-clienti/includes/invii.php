<?php
/**
 * Protezione dai doppi invii dei moduli (codici monouso con lock del database).
 *
 * 1.9.15: file per area; il codice proviene dai file per versione (session1-3.php, v190.php), invariato.
 */
defined( 'ABSPATH' ) || exit;

/** Ogni modulo ha un codice monouso: lo stesso invio ripetuto non crea duplicati. */
function apac_once_field() {
	return '<input type="hidden" name="_apac_once" value="' . esc_attr( wp_generate_password( 20, false, false ) ) . '">';
}

/*
 * Valori della colonna "esito" scritti dalla 1.9.5 in poi. I valori 0, 1 e 2 delle versioni precedenti non
 * sono più scritti e vengono trattati come "esito sconosciuto".
 */
if ( ! defined( 'APAC_INVIO_PRENOTATO' ) ) {
	define( 'APAC_INVIO_PRENOTATO', 3 );
}

if ( ! defined( 'APAC_INVIO_RIUSCITO' ) ) {
	define( 'APAC_INVIO_RIUSCITO', 4 );
}

/**
 * Controllo del codice monouso del modulo. Restituisce:
 *  'ok'      – l'invio può essere eseguito (codice nuovo, oppure ripresa di un invio la cui richiesta
 *              è DIMOSTRATAMENTE terminata senza salvare);
 *  'dup'     – stesso invio già concluso con successo (esito = APAC_INVIO_RIUSCITO): non si ripete;
 *  'busy'    – un'altra richiesta sta elaborando questo stesso invio in questo momento;
 *  'unknown' – prenotazione scritta da una versione precedente alla 1.9.5 (qualsiasi altro valore di esito:
 *              0, 1 o 2): non si sa se era riuscita, quindi non si esegue e non si dichiara riuscita.
 *              Il riconoscimento è nel codice, senza marcature di massa durante l'aggiornamento: ripetere
 *              un aggiornamento interrotto non può toccare le prenotazioni nuove;
 *  'invalid' – codice assente o database non disponibile: invio BLOCCATO.
 *
 * 1.9.5 – Prova che la richiesta precedente è terminata: chi elabora un invio tiene, per tutta la durata
 * della richiesta, un lock con nome del database (GET_LOCK). Il lock appartiene alla connessione e il
 * database lo rilascia quando la connessione si chiude, anche se la richiesta termina in modo anomalo
 * (documentazione MySQL e MariaDB). Il lock si prende PRIMA di leggere o scrivere la prenotazione:
 *  - lock occupato  -> la richiesta precedente è ancora viva: 'busy' (nessun timeout, nessuna ripresa);
 *  - lock ottenuto e prenotazione APAC_INVIO_PRENOTATO -> nessuno la sta elaborando: la richiesta precedente è
 *    terminata senza salvare (in caso di errore il salvataggio è stato annullato) e l'invio si riprende.
 * APAC_INVIO_RIUSCITO viene registrato nella stessa transazione del salvataggio, confermata prima che il lock venga
 * rilasciato: chi ottiene il lock dopo vede sempre l'esito definitivo.
 */
function apac_once_check() {
	global $wpdb;
	$once = preg_replace( '/[^A-Za-z0-9]/', '', (string) apac_post( '_apac_once' ) );
	if ( strlen( $once ) < 12 ) {
		return 'invalid';
	}
	$key  = md5( get_current_user_id() . '|' . $once );
	$lock = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', apac_once_lock_name( $key ) ) );
	if ( null === $lock ) {
		return 'invalid';
	}
	if ( '1' !== (string) $lock ) {
		return 'busy';
	}
	$GLOBALS['apac_once_lock'] = apac_once_lock_name( $key );
	apac_once_shutdown_hook();

	$now      = gmdate( 'Y-m-d H:i:s' );
	$suppress = $wpdb->suppress_errors( true );
	$ok       = $wpdb->insert( apac_table( 'invii' ), array( 'chiave' => $key, 'created_at' => $now, 'esito' => APAC_INVIO_PRENOTATO ) );
	$wpdb->suppress_errors( $suppress );
	if ( false === $ok || 1 !== (int) $wpdb->rows_affected ) {
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT esito FROM ' . apac_table( 'invii' ) . ' WHERE chiave = %s', $key ) );
		if ( ! $r ) {
			apac_once_unlock();
			return 'invalid';
		}
		if ( APAC_INVIO_RIUSCITO === (int) $r->esito ) {
			apac_once_unlock();
			return 'dup';
		}
		if ( APAC_INVIO_PRENOTATO !== (int) $r->esito ) {
			apac_once_unlock();
			return 'unknown';
		}
		// Prenotata dalla 1.9.5 e lock in mano: la richiesta che l'aveva prenotata è terminata senza salvare.
		if ( false === $wpdb->update( apac_table( 'invii' ), array( 'created_at' => $now ), array( 'chiave' => $key ) ) ) {
			apac_once_unlock();
			return 'invalid';
		}
	}
	$GLOBALS['apac_once_key'] = $key;
	// Pulizia occasionale delle prenotazioni vecchie.
	if ( 1 === wp_rand( 1, 50 ) ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . apac_table( 'invii' ) . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) );
	}
	return 'ok';
}

/** Nome del lock (max 64 caratteri; distinto per sito e per database). */
function apac_once_lock_name( $key ) {
	global $wpdb;
	return 'apac_' . md5( ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $wpdb->prefix . '|' . $key );
}

function apac_once_unlock() {
	global $wpdb;
	if ( empty( $GLOBALS['apac_once_lock'] ) ) {
		return;
	}
	$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $GLOBALS['apac_once_lock'] ) );
	$GLOBALS['apac_once_lock'] = null;
}

/**
 * Fine della richiesta (anche dopo un errore fatale di PHP): una transazione rimasta aperta viene
 * annullata PRIMA di rilasciare il lock, così chi riprende l'invio non trova un salvataggio a metà.
 * Se PHP non arriva a questo punto (processo terminato), il lock lo rilascia il database alla chiusura
 * della connessione, che annulla anche la transazione non confermata.
 */
function apac_once_shutdown_hook() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	register_shutdown_function(
		function () {
			if ( function_exists( 'apac_tx_active' ) && apac_tx_active() ) {
				apac_tx_rollback();
			}
			apac_once_unlock();
		}
	);
}

/** Compatibilità: true se l'invio è un doppione. */
function apac_once_seen() {
	return 'dup' === apac_once_check();
}

/** Operazione riuscita: registra l'esito (chiamata prima della conferma della transazione). */
function apac_once_done() {
	global $wpdb;
	if ( empty( $GLOBALS['apac_once_key'] ) ) {
		return true;
	}
	// Il lock deve essere ancora di questa connessione (se la connessione fosse caduta e WordPress si
	// fosse ricollegato, un'altra richiesta potrebbe aver ripreso l'invio): altrimenti si annulla.
	if ( ! empty( $GLOBALS['apac_once_lock'] ) ) {
		$mio = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $GLOBALS['apac_once_lock'] ) );
		if ( '1' !== (string) $mio ) {
			return false;
		}
	}
	return false !== $wpdb->query( $wpdb->prepare( 'UPDATE ' . apac_table( 'invii' ) . ' SET esito = %d WHERE chiave = %s', APAC_INVIO_RIUSCITO, $GLOBALS['apac_once_key'] ) );
}

/** Operazione non riuscita: libera il codice, così il modulo può essere inviato di nuovo. */
function apac_once_release() {
	global $wpdb;
	if ( empty( $GLOBALS['apac_once_key'] ) ) {
		return;
	}
	$wpdb->delete( apac_table( 'invii' ), array( 'chiave' => $GLOBALS['apac_once_key'] ) );
	$GLOBALS['apac_once_key'] = null;
	apac_once_unlock();
}
