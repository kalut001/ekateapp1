<?php
/**
 * Scadenze ricorrenti: calcolo della successiva, serie di rinnovi, rimozione del rinnovo automatico
 * alla riapertura.
 *
 * 1.9.15: file per area; il codice proviene dai file per versione (session1-3.php, v190.php), invariato.
 */
defined( 'ABSPATH' ) || exit;

function apac_ricorrenze() {
	return array(
		0  => 'Non si ripete',
		1  => 'Ogni mese',
		3  => 'Ogni 3 mesi',
		6  => 'Ogni 6 mesi',
		12 => 'Ogni anno',
		24 => 'Ogni 2 anni',
		36 => 'Ogni 3 anni',
		60 => 'Ogni 5 anni',
	);
}

/** Aggiunge N mesi a una data Y-m-d senza "sforare" (31/01 + 1 mese = 28 o 29/02). */
function apac_add_months( $date, $months ) {
	$d   = DateTime::createFromFormat( '!Y-m-d', $date );
	$y   = (int) $d->format( 'Y' );
	$m   = (int) $d->format( 'n' ) + (int) $months;
	$day = (int) $d->format( 'j' );
	$y  += intdiv( $m - 1, 12 );
	$m   = ( ( $m - 1 ) % 12 ) + 1;
	$max = (int) cal_days_in_month_safe( $m, $y );
	return sprintf( '%04d-%02d-%02d', $y, $m, min( $day, $max ) );
}

/** Giorni del mese anche se l'estensione "calendar" di PHP non è installata. */
function cal_days_in_month_safe( $m, $y ) {
	return (int) gmdate( 't', gmmktime( 0, 0, 0, $m, 1, $y ) );
}

/**
 * Chiamata quando una scadenza ricorrente viene segnata "completata":
 * crea la successiva (se non esiste già). Restituisce la nuova data o ''.
 */
/** 1.9.9: identificativo della serie di una scadenza (la prima scadenza della serie). */
function apac_scadenza_serie( $row ) {
	global $wpdb;
	// Anche prima/durante una migrazione ripetuta, segue tutta la catena nella stessa azienda.
	$rows = array( $row );
	$seen = array( (int) $row->id => true );
	$next = ! empty( $row->origine_id ) ? (int) $row->origine_id : 0;
	while ( $next > 0 && ! isset( $seen[ $next ] ) ) {
		$parent = $wpdb->get_row( $wpdb->prepare(
			'SELECT id, azienda_id, origine_id, serie_id FROM ' . apac_table( 'scadenze' ) . ' WHERE id = %d AND azienda_id = %d',
			$next, (int) $row->azienda_id
		) );
		apac_must( '' === $wpdb->last_error, 'scadenziario' );
		if ( ! $parent ) {
			break; // Origine eliminata o estranea: non unire aziende diverse.
		}
		$rows[] = $parent;
		$seen[ $next ] = true;
		$next = ! empty( $parent->origine_id ) ? (int) $parent->origine_id : 0;
	}
	$roots = apac_scadenze_series_roots( $rows );
	return $roots[ (int) $row->id ];
}

function apac_crea_prossima_scadenza( $row ) {
	global $wpdb;
	$mesi = (int) $row->ricorrenza_mesi;
	if ( $mesi <= 0 ) {
		return '';
	}
	$nuova = apac_add_months( $row->data_scadenza, $mesi );
	// Il figlio già generato vale anche se completato o modificato: riaprire un antenato
	// non deve creare una seconda copia di un'occorrenza già presente nello storico.
	$serie  = apac_scadenza_serie( $row );
	$esiste = $wpdb->get_var( $wpdb->prepare(
		'SELECT COUNT(*) FROM ' . apac_table( 'scadenze' ) . ' WHERE azienda_id = %d AND ( origine_id = %d OR ( serie_id = %d AND data_scadenza = %s ) )',
		(int) $row->azienda_id, (int) $row->id, $serie, $nuova
	) );
	apac_must( '' === $wpdb->last_error, 'scadenziario' );
	if ( (int) $esiste > 0 ) {
		return ''; // Non annunciare una creazione quando il rinnovo esisteva già.
	}
	apac_must_insert(
		'scadenze',
		array(
			'azienda_id'      => (int) $row->azienda_id,
			'titolo'          => $row->titolo,
			'categoria'       => $row->categoria,
			'data_scadenza'   => $nuova,
			'lavoratore_id'   => $row->lavoratore_id ? (int) $row->lavoratore_id : null,
			'stato'           => 'aperta',
			'note'            => $row->note,
			'avviso_inviato'  => -1,
			'ricorrenza_mesi' => $mesi,
			'origine_id'      => (int) $row->id,
			'serie_id'        => $serie,
			'created_at'      => apac_now(),
		),
		'scadenziario'
	);
	return $nuova;
}

/**
 * Riaprendo una scadenza ricorrente completata per errore, la successiva creata in automatico da
 * questa viene tolta (altrimenti resterebbero due scadenze uguali), solo se è ancora aperta,
 * non risulta salvata a mano (colonna "modificata", registrata dalla 1.9.2 in poi) e ha gli stessi
 * dati dell'originale. Per le scadenze modificate prima della 1.9.2 il confronto dei dati è l'unico
 * controllo: indica che i dati coincidono, non prova che la scadenza non sia mai stata modificata.
 * Restituisce la data rimossa.
 */
function apac_rimuovi_prossima_automatica( $row ) {
	global $wpdb;
	$mesi = (int) $row->ricorrenza_mesi;
	if ( $mesi <= 0 ) {
		return '';
	}
	$data = apac_add_months( $row->data_scadenza, $mesi );
	// 1.9.2: solo la scadenza creata da QUESTA (collegamento esplicito), ancora aperta, mai salvata
	// a mano (modificata = 0) e ancora con i dati con cui è stata creata.
	$next = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT * FROM ' . apac_table( 'scadenze' ) . " WHERE azienda_id = %d AND origine_id = %d AND stato = 'aperta' AND modificata = 0 ORDER BY id DESC LIMIT 1",
			$row->azienda_id,
			$row->id
		)
	);
	if ( ! $next || $next->data_scadenza !== $data || (string) $next->titolo !== (string) $row->titolo || (string) $next->categoria !== (string) $row->categoria || (int) $next->ricorrenza_mesi !== $mesi || (string) $next->note !== (string) $row->note || (int) $next->lavoratore_id !== (int) $row->lavoratore_id ) {
		return '';
	}
	// 1.9.3: le condizioni sono ricontrollate nella stessa istruzione di eliminazione: se nel
	// frattempo la successiva è stata salvata a mano o completata (da un'altra scheda), resta.
	$tolte = $wpdb->query(
		$wpdb->prepare(
			'DELETE FROM ' . apac_table( 'scadenze' ) . " WHERE id = %d AND azienda_id = %d AND origine_id = %d AND ( serie_id IS NULL OR serie_id = %d ) AND stato = 'aperta' AND modificata = 0",
			(int) $next->id,
			(int) $row->azienda_id,
			(int) $row->id,
			apac_scadenza_serie( $row )
		)
	);
	apac_must( $tolte, 'scadenziario' );
	if ( 1 !== (int) $tolte ) {
		return '';
	}
	apac_must( apac_avvisi_reset( 'scadenza', (int) $next->id ), 'scadenziario' );
	return $data;
}
