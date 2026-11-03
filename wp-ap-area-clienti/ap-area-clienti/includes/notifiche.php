<?php
defined( 'ABSPATH' ) || exit;

/** Tentativi massimi per destinatario prima di rinunciare (uno al giorno). */
define( 'APAC_MAX_TENTATIVI', 5 );

add_action( 'apac_cron_scadenze', 'apac_cron_run' );
function apac_cron_run() {
	apac_invia_avvisi_scadenze();
	apac_schedule_cron(); // Prossima esecuzione: domani alle 07:00 ora locale.
}

/* -------------------------------------------------------------------------
 * Blocco contro esecuzioni contemporanee (cron + avvio manuale)
 * ---------------------------------------------------------------------- */

/** Acquisisce un blocco esclusivo; un blocco più vecchio di $ttl secondi è considerato abbandonato. */
function apac_lock_acquire( $name, $ttl = 900 ) {
	global $wpdb;
	$key = 'apac_lock_' . $name;
	for ( $i = 0; $i < 2; $i++ ) {
		// INSERT IGNORE è atomico: una sola esecuzione riesce a inserire la riga.
		$r = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, (string) time() ) );
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if ( 1 === (int) $r ) {
			return true;
		}
		$since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
		if ( $since && ( time() - $since ) > $ttl ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, (string) $since ) );
			continue;
		}
		return false;
	}
	return false;
}

function apac_lock_release( $name ) {
	global $wpdb;
	$key = 'apac_lock_' . $name;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $key ) );
	wp_cache_delete( $key, 'options' );
}

/* -------------------------------------------------------------------------
 * Soglie
 * ---------------------------------------------------------------------- */

/**
 * Soglia di avviso raggiunta da una data: 30, 7 o 0 giorni (0 = scaduta o in scadenza oggi).
 * -1 = nessun avviso necessario.
 */
function apac_soglia_avviso( $date ) {
	$days = apac_days_until( $date );
	if ( null === $days ) {
		return -1;
	}
	if ( $days <= 0 ) {
		return 0;
	}
	if ( $days <= 7 ) {
		return 7;
	}
	if ( $days <= 30 ) {
		return 30;
	}
	return -1;
}

/** La riga ha raggiunto una soglia non ancora chiusa? */
function apac_da_notificare( $date, $gia_inviato ) {
	$soglia = apac_soglia_avviso( $date );
	return ( $soglia >= 0 && ( (int) $gia_inviato < 0 || $soglia < (int) $gia_inviato ) ) ? $soglia : -1;
}

/**
 * Azzera lo storico degli avvisi di una riga: usato quando cambia la data di
 * scadenza, quando un adempimento viene riaperto e quando la riga viene eliminata.
 */
function apac_avvisi_reset( $tipo, $riga_id ) {
	global $wpdb;
	return $wpdb->delete( apac_table( 'avvisi' ), array( 'tipo' => $tipo, 'riga_id' => (int) $riga_id ) );
}

/** Esito registrato per un avviso/destinatario (null se mai tentato). */
function apac_avviso_get( $tipo, $riga_id, $data_rif, $soglia, $dest ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'avvisi' ) . ' WHERE tipo = %s AND riga_id = %d AND data_rif = %s AND soglia = %d AND destinatario = %s', $tipo, $riga_id, $data_rif, $soglia, $dest ) );
}

function apac_avviso_record( $tipo, $riga_id, $data_rif, $soglia, $dest, $ok, $errore = '' ) {
	global $wpdb;
	$prev = apac_avviso_get( $tipo, $riga_id, $data_rif, $soglia, $dest );
	$data = array(
		'esito'      => $ok ? 'inviato' : 'fallito',
		'tentativi'  => ( $prev ? (int) $prev->tentativi : 0 ) + 1,
		'errore'     => mb_substr( (string) $errore, 0, 250 ),
		'updated_at' => current_time( 'mysql' ),
	);
	if ( $prev ) {
		return false !== $wpdb->update( apac_table( 'avvisi' ), $data, array( 'id' => $prev->id ) );
	}
	return false !== $wpdb->insert(
		apac_table( 'avvisi' ),
		array_merge(
			$data,
			array(
				'tipo'         => $tipo,
				'riga_id'      => (int) $riga_id,
				'data_rif'     => $data_rif,
				'soglia'       => (int) $soglia,
				'destinatario' => $dest,
			)
		)
	);
}

/** Per questo destinatario l'avviso è chiuso (consegnato o tentativi esauriti)? */
function apac_avviso_chiuso( $rec ) {
	return $rec && ( 'inviato' === $rec->esito || (int) $rec->tentativi >= APAC_MAX_TENTATIVI );
}

/* -------------------------------------------------------------------------
 * Invio
 * ---------------------------------------------------------------------- */

/**
 * Invio giornaliero degli avvisi di scadenza (30 giorni, 7 giorni, scaduta).
 *
 * - L'esito è registrato PER DESTINATARIO: chi l'ha ricevuto non lo riceve di
 *   nuovo, chi non l'ha ricevuto viene ritentato al controllo successivo (fino a
 *   APAC_MAX_TENTATIVI volte).
 * - La soglia di una riga viene chiusa solo quando tutti i destinatari l'hanno
 *   ricevuta o hanno esaurito i tentativi.
 * - Un blocco impedisce che il controllo automatico e quello manuale girino insieme.
 * - Gli attestati rinnovati da un attestato successivo non generano avvisi.
 *
 * @return array Riepilogo: [ 'aziende', 'inviate', 'fallite' ] oppure [ 'locked' => true ].
 */
function apac_invia_avvisi_scadenze() {
	if ( ! apac_lock_acquire( 'avvisi' ) ) {
		return array( 'locked' => true );
	}
	try {
		$riepilogo = apac_invia_avvisi_esegui();
	} finally {
		apac_lock_release( 'avvisi' );
	}
	update_option( 'apac_ultimo_invio', array_merge( $riepilogo, array( 'date' => current_time( 'mysql' ) ) ), false );
	return $riepilogo;
}

function apac_invia_avvisi_esegui() {
	global $wpdb;
	$riepilogo = array(
		'aziende' => 0,
		'inviate' => 0,
		'fallite' => 0,
	);

	foreach ( apac_get_aziende() as $az ) {
		$voci = array(); // Ogni voce: tipo, id, data, soglia, testo.

		$scadenze = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'scadenze' ) . " WHERE azienda_id = %d AND stato = 'aperta'", $az->id ) );
		foreach ( $scadenze as $s ) {
			$soglia = apac_da_notificare( $s->data_scadenza, $s->avviso_inviato );
			if ( $soglia >= 0 ) {
				$voci[] = array( 'scadenza', (int) $s->id, $s->data_scadenza, $soglia, '- ' . $s->titolo . ': ' . apac_date_it( $s->data_scadenza ) . apac_testo_giorni( $s->data_scadenza ) );
			}
		}
		$attestati = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.*, l.nome, l.cognome FROM ' . apac_table( 'attestati' ) . ' a INNER JOIN ' . apac_table( 'lavoratori' ) . ' l ON l.id = a.lavoratore_id
				 WHERE a.azienda_id = %d AND l.attivo = 1 AND a.data_scadenza IS NOT NULL AND ' . apac_sql_attestato_corrente( 'a' ),
				$az->id
			)
		);
		foreach ( $attestati as $a ) {
			$soglia = apac_da_notificare( $a->data_scadenza, $a->avviso_inviato );
			if ( $soglia >= 0 ) {
				$voci[] = array( 'attestato', (int) $a->id, $a->data_scadenza, $soglia, '- Formazione "' . $a->corso . '" di ' . $a->cognome . ' ' . $a->nome . ': ' . apac_date_it( $a->data_scadenza ) . apac_testo_giorni( $a->data_scadenza ) );
			}
		}
		if ( ! $voci ) {
			continue;
		}
		$riepilogo['aziende']++;
		do_action( 'apac_avvisi_azienda', $az, $voci ); // 1.7.0: notifiche sul telefono.

		// Destinatari: utenti attivi dell'azienda + studio.
		$dest = array();
		foreach ( get_users( array( 'meta_key' => 'apac_azienda_id', 'meta_value' => $az->id ) ) as $u ) {
			if ( ! apac_user_revoked( $u->ID ) && is_email( $u->user_email ) ) {
				$dest[] = strtolower( $u->user_email );
			}
		}
		$admin = strtolower( (string) get_option( 'admin_email' ) );
		if ( is_email( $admin ) ) {
			$dest[] = $admin;
		}
		$dest = array_values( array_unique( $dest ) );

		$subject = '[Area clienti] Scadenze sicurezza – ' . $az->ragione_sociale;
		foreach ( $dest as $to ) {
			$mie = array();
			foreach ( $voci as $v ) {
				if ( ! apac_avviso_chiuso( apac_avviso_get( $v[0], $v[1], $v[2], $v[3], $to ) ) ) {
					$mie[] = $v;
				}
			}
			if ( ! $mie ) {
				continue;
			}
			$body  = "Gentile cliente,\n\nper l'azienda {$az->ragione_sociale} risultano le seguenti scadenze:\n\n";
			$body .= implode( "\n", wp_list_pluck( $mie, 4 ) );
			$body .= "\n\nPuoi consultare il dettaglio nell'area riservata:\n" . apac_portal_page_url();
			$body .= "\n\nMessaggio automatico – " . get_bloginfo( 'name' );

			$ok  = apac_send_mail( $to, $subject, $body, apac_auto_headers() );
			$err = $ok ? '' : (string) ( $GLOBALS['apac_mail_error'] ?? '' );
			$ok ? $riepilogo['inviate']++ : $riepilogo['fallite']++;
			foreach ( $mie as $v ) {
				if ( ! apac_avviso_record( $v[0], $v[1], $v[2], $v[3], $to, $ok, $err ) ) {
					apac_record_mail_error( 'registro avvisi', 'registrazione dell’esito non riuscita (' . $v[0] . ' ' . $v[1] . ')' );
				}
			}
		}

		// Chiusura della soglia per le righe completate da tutti i destinatari.
		foreach ( $voci as $v ) {
			$tutti = (bool) $dest;
			foreach ( $dest as $to ) {
				if ( ! apac_avviso_chiuso( apac_avviso_get( $v[0], $v[1], $v[2], $v[3], $to ) ) ) {
					$tutti = false;
					break;
				}
			}
			if ( ! $tutti ) {
				continue;
			}
			$table = 'scadenza' === $v[0] ? 'scadenze' : 'attestati';
			// Si chiude solo se la data non è cambiata nel frattempo.
			if ( false === $wpdb->update( apac_table( $table ), array( 'avviso_inviato' => $v[3] ), array( 'id' => $v[1], 'data_scadenza' => $v[2] ) ) ) {
				apac_record_mail_error( 'registro avvisi', 'aggiornamento stato avviso non riuscito (' . $v[0] . ' ' . $v[1] . ')' );
			}
		}
	}
	return $riepilogo;
}

function apac_testo_giorni( $date ) {
	$d = apac_days_until( $date );
	if ( $d < 0 ) {
		return ' (scaduta da ' . abs( $d ) . ' giorni)';
	}
	if ( 0 === $d ) {
		return ' (scade oggi)';
	}
	return ' (tra ' . $d . ' giorni)';
}
