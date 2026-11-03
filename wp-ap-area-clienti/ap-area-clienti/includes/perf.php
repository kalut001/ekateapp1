<?php
/**
 * 1.9.1 – Prestazioni con molte aziende.
 * I riepiloghi dello studio leggono scadenze e segnalazioni di tutte le aziende con poche
 * interrogazioni complessive (non una serie per azienda) e riusano l'analisi delle criticità
 * finché i dati di quell'azienda non cambiano (o cambia il giorno).
 */
defined( 'ABSPATH' ) || exit;

/**
 * Scadenze aperte e attestati (stessi criteri di apac_agenda) di TUTTE le aziende,
 * raggruppati per azienda. Due interrogazioni in tutto.
 *
 * @return array azienda_id => [ voci ordinate per data ]
 */
function apac_agenda_tutte( $giorni = 30 ) {
	global $wpdb;
	static $memo = array(), $gen = 0;
	if ( $gen !== (int) ( $GLOBALS['apac_perf_gen'] ?? 0 ) ) {
		$memo = array();
		$gen  = (int) ( $GLOBALS['apac_perf_gen'] ?? 0 );
	}
	if ( isset( $memo[ $giorni ] ) ) {
		return $memo[ $giorni ];
	}
	$limite = gmdate( 'Y-m-d', strtotime( apac_today() . ' +' . (int) $giorni . ' days' ) );
	$out    = array();
	$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT id, azienda_id, titolo, data_scadenza FROM ' . apac_table( 'scadenze' ) . " WHERE stato = 'aperta' AND data_scadenza <= %s", $limite ) );
	foreach ( (array) $rows as $r ) {
		$out[ (int) $r->azienda_id ][] = array( 'data' => $r->data_scadenza, 'titolo' => $r->titolo, 'tipo' => 'Scadenza', 'sez' => 'scadenziario', 'args' => array() );
	}
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT a.azienda_id, a.lavoratore_id, a.corso, a.data_scadenza, l.nome, l.cognome FROM ' . apac_table( 'attestati' ) . ' a INNER JOIN ' . apac_table( 'lavoratori' ) . ' l ON l.id = a.lavoratore_id
			 WHERE l.attivo = 1 AND a.data_scadenza IS NOT NULL AND a.data_scadenza <= %s AND ' . apac_sql_attestato_corrente( 'a' ),
			$limite
		)
	);
	foreach ( (array) $rows as $r ) {
		$out[ (int) $r->azienda_id ][] = array( 'data' => $r->data_scadenza, 'titolo' => $r->corso . ' – ' . $r->cognome . ' ' . $r->nome, 'tipo' => 'Formazione', 'sez' => 'lavoratori', 'args' => array( 'lav' => (int) $r->lavoratore_id ) );
	}
	foreach ( $out as &$items ) {
		usort(
			$items,
			function ( $a, $b ) {
				return strcmp( $a['data'], $b['data'] );
			}
		);
	}
	unset( $items );
	$memo[ $giorni ] = $out;
	return $out;
}

/** Indirizzo di una voce di apac_agenda_tutte. */
function apac_agenda_url( $item, $azienda_id ) {
	return apac_url( $item['sez'], array_merge( $item['args'], array( 'azienda' => (int) $azienda_id ) ) );
}

/** Segnalazioni non chiuse per azienda (una interrogazione). */
function apac_segnalazioni_aperte_per_azienda() {
	global $wpdb;
	$out = array();
	foreach ( (array) $wpdb->get_results( 'SELECT azienda_id, COUNT(*) AS n FROM ' . apac_table( 'segnalazioni' ) . " WHERE stato <> 'chiusa' GROUP BY azienda_id" ) as $r ) {
		$out[ (int) $r->azienda_id ] = (int) $r->n;
	}
	return $out;
}

/* --------------------- Analisi delle criticità riutilizzata --------------------- */

/**
 * Versione dei dati di ogni azienda (colonna dati_ver). 1.9.2: l'aumento è una sola istruzione
 * UPDATE ... SET dati_ver = dati_ver + 1 sulla riga dell'azienda, quindi due salvataggi
 * contemporanei non possono sovrascriversi e far perdere l'aggiornamento del riepilogo.
 */
function apac_dati_versioni() {
	global $wpdb;
	$out = array();
	foreach ( (array) $wpdb->get_results( 'SELECT id, dati_ver FROM ' . apac_table( 'aziende' ) ) as $r ) {
		$out[ (int) $r->id ] = (int) $r->dati_ver;
	}
	return $out;
}

/** Restituisce false solo se il database segnala un errore (azienda inesistente = nulla da fare). */
function apac_dati_cambiati( $azienda_id ) {
	global $wpdb;
	$azienda_id = (int) $azienda_id;
	if ( $azienda_id <= 0 ) {
		return true;
	}
	return false !== $wpdb->query( $wpdb->prepare( 'UPDATE ' . apac_table( 'aziende' ) . ' SET dati_ver = dati_ver + 1 WHERE id = %d', $azienda_id ) );
}

/**
 * 1.9.3: aumenta la versione delle aziende toccate dall'operazione in corso (quella del contesto
 * e, per l'amministratore, quella selezionata se diversa). Chiamata da apac_redirect prima della
 * conferma della transazione, così l'aumento fa parte dello stesso salvataggio.
 */
function apac_dati_cambiati_ctx() {
	if ( empty( $GLOBALS['apac_log_ctx'] ) ) {
		return true;
	}
	$ids = array( (int) $GLOBALS['apac_log_ctx']['azienda_id'] );
	if ( ! empty( $_REQUEST['azienda'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$ids[] = absint( $_REQUEST['azienda'] ); // phpcs:ignore WordPress.Security.NonceVerification
	}
	$ok = true;
	foreach ( array_unique( $ids ) as $id ) {
		$ok = apac_dati_cambiati( $id ) && $ok;
	}
	return $ok;
}

/**
 * Criticità di livello "alta" di un'azienda, ricalcolate solo se i dati dell'azienda sono
 * cambiati o se è cambiato il giorno (le scadenze dipendono dalla data).
 */
function apac_criticita_alte( $azienda_id ) {
	static $cache = null, $vers = null, $dirty = false, $gen = 0, $hooked = false;
	$azienda_id = (int) $azienda_id;
	if ( $gen !== (int) ( $GLOBALS['apac_perf_gen'] ?? 0 ) ) {
		$cache = null; // Misura: ogni pagina rilegge tutto, come in una richiesta nuova.
		wp_cache_delete( 'apac_crit_cache', 'options' );
		$gen   = (int) ( $GLOBALS['apac_perf_gen'] ?? 0 );
	}
	if ( null === $cache ) {
		$cache = get_option( 'apac_crit_cache', array() );
		$cache = is_array( $cache ) ? $cache : array();
		$vers  = apac_dati_versioni();
		if ( ! $hooked ) {
			$hooked = true;
			add_action(
				'shutdown',
				function () use ( &$cache, &$dirty ) {
					if ( $dirty ) {
						update_option( 'apac_crit_cache', $cache, false );
					}
				}
			);
		}
	}
	$ver = isset( $vers[ $azienda_id ] ) ? (int) $vers[ $azienda_id ] : 0;
	$day = apac_today();
	if ( isset( $cache[ $azienda_id ] ) && $cache[ $azienda_id ]['v'] === $ver && $cache[ $azienda_id ]['d'] === $day ) {
		return $cache[ $azienda_id ]['c'];
	}
	$alte = array();
	foreach ( apac_analizza_criticita( $azienda_id ) as $c ) {
		if ( 'alta' === $c['livello'] ) {
			$alte[] = array(
				'titolo'  => $c['titolo'],
				'norma'   => $c['norma'],
				'sez'     => $c['sez'],
				'livello' => 'alta',
			);
		}
	}
	$cache[ $azienda_id ] = array( 'v' => $ver, 'd' => $day, 'c' => $alte );
	$dirty                = true;
	return $alte;
}

/**
 * Il controllo giornaliero delle scadenze prepara anche l'analisi delle criticità del giorno:
 * così il primo accesso dello studio non deve ricalcolarla per tutte le aziende.
 */
add_action( 'apac_cron_scadenze', 'apac_criticita_prepara', 20 );
function apac_criticita_prepara() {
	foreach ( apac_get_aziende() as $a ) {
		apac_criticita_alte( (int) $a->id );
	}
	// Il salvataggio avviene alla fine della richiesta (shutdown).
}

/**
 * Impostazioni > Area Clienti: misura riproducibile del riepilogo dello Studio sul sito reale
 * (numero di interrogazioni al database e tempo), senza dati di prova.
 */
function apac_render_misura() {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<h2>Misura delle prestazioni</h2><p>Calcola quante interrogazioni al database e quanto tempo servono per preparare i contenuti delle pagine dello Studio con le aziende presenti (escluso il resto della pagina WordPress: tema, menu, plugin).</p>';
	$go = isset( $_GET['apac_misura'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'apac_misura' );
	if ( $go ) {
		$righe = array();
		$prove = array(
			'Studio (pannello)'         => function () {
				apac_render_pannello();
			},
			'Elenco «Scadute»'          => function () {
				$_GET['tipo'] = 'scadute';
				apac_render_elenco();
			},
			'Elenco «Criticità alte»'   => function () {
				$_GET['tipo'] = 'criticita';
				apac_render_elenco();
			},
		);
		foreach ( $prove as $nome => $fn ) {
			$GLOBALS['apac_perf_gen'] = (int) ( $GLOBALS['apac_perf_gen'] ?? 0 ) + 1; // Ogni pagina misurata da sola.
			$q0 = (int) $wpdb->num_queries;
			$t0 = microtime( true );
			ob_start();
			$fn();
			ob_end_clean();
			$righe[] = array( $nome, (int) $wpdb->num_queries - $q0, round( ( microtime( true ) - $t0 ) * 1000 ) );
		}
		unset( $_GET['tipo'] );
		echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th>Pagina</th><th>Interrogazioni</th><th>Tempo (ms)</th></tr></thead><tbody>';
		foreach ( $righe as $r ) {
			echo '<tr><td>' . esc_html( $r[0] ) . '</td><td>' . (int) $r[1] . '</td><td>' . (int) $r[2] . '</td></tr>';
		}
		echo '</tbody></table><p class="description">Aziende presenti: ' . (int) apac_count( 'aziende', '1 = %d', 1 ) . '. Il tempo dipende anche dal carico del server in quel momento. La prima misura del giorno, o dopo molte modifiche, può essere più lenta: l’analisi delle criticità viene ricalcolata e poi riutilizzata.</p>';
	}
	echo '<p><a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'apac-settings', 'apac_misura' => 1 ), admin_url( 'options-general.php' ) ), 'apac_misura' ) ) . '">Misura ora</a></p>';
}
