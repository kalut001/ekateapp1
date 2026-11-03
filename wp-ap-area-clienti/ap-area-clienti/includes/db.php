<?php
defined( 'ABSPATH' ) || exit;

/**
 * Nome completo di una tabella del plugin.
 */
function apac_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'apac_' . $name;
}

/**
 * Crea o aggiorna le tabelle.
 */
function apac_install_db() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();

	$sql = array();

	$sql[] = 'CREATE TABLE ' . apac_table( 'aziende' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		ragione_sociale varchar(200) NOT NULL DEFAULT '',
		ragione_sort varchar(200) NOT NULL DEFAULT '',
		partita_iva varchar(20) NOT NULL DEFAULT '',
		codice_fiscale varchar(20) NOT NULL DEFAULT '',
		ateco varchar(20) NOT NULL DEFAULT '',
		indirizzo varchar(255) NOT NULL DEFAULT '',
		comune varchar(100) NOT NULL DEFAULT '',
		provincia varchar(5) NOT NULL DEFAULT '',
		telefono varchar(40) NOT NULL DEFAULT '',
		email varchar(150) NOT NULL DEFAULT '',
		pec varchar(150) NOT NULL DEFAULT '',
		datore_lavoro varchar(150) NOT NULL DEFAULT '',
		rspp_nome varchar(150) NOT NULL DEFAULT '',
		rspp_tipo varchar(20) NOT NULL DEFAULT '',
		rls_nome varchar(150) NOT NULL DEFAULT '',
		rls_tipo varchar(10) NOT NULL DEFAULT '',
		sorveglianza_sanitaria varchar(10) NOT NULL DEFAULT '',
		medico_competente varchar(150) NOT NULL DEFAULT '',
		addetti_antincendio text NULL,
		addetti_primo_soccorso text NULL,
		note text NULL,
		permessi text NULL,
		dati_ver int(10) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		KEY ragione_sort (ragione_sort)
	) $charset;";

	$sql[] = 'CREATE TABLE ' . apac_table( 'lavoratori' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		azienda_id bigint(20) unsigned NOT NULL,
		nome varchar(100) NOT NULL DEFAULT '',
		cognome varchar(100) NOT NULL DEFAULT '',
		codice_fiscale varchar(20) NOT NULL DEFAULT '',
		mansione varchar(200) NOT NULL DEFAULT '',
		data_assunzione date NULL,
		attivo tinyint(1) NOT NULL DEFAULT 1,
		data_cessazione date NULL,
		note text NULL,
		created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		KEY azienda_id (azienda_id)
	) $charset;";

	$sql[] = 'CREATE TABLE ' . apac_table( 'attestati' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		azienda_id bigint(20) unsigned NOT NULL,
		lavoratore_id bigint(20) unsigned NOT NULL,
		corso varchar(150) NOT NULL DEFAULT '',
		data_conseguimento date NULL,
		data_scadenza date NULL,
		documento_id bigint(20) unsigned NULL,
		note text NULL,
		avviso_inviato smallint(6) NOT NULL DEFAULT -1,
		created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		KEY azienda_id (azienda_id),
		KEY lavoratore_id (lavoratore_id)
	) $charset;";

	$sql[] = 'CREATE TABLE ' . apac_table( 'documenti' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		azienda_id bigint(20) unsigned NOT NULL,
		titolo varchar(200) NOT NULL DEFAULT '',
		categoria varchar(50) NOT NULL DEFAULT '',
		nome_file varchar(255) NOT NULL DEFAULT '',
		file_path varchar(255) NOT NULL DEFAULT '',
		mime varchar(100) NOT NULL DEFAULT '',
		dimensione bigint(20) unsigned NOT NULL DEFAULT 0,
		caricato_da bigint(20) unsigned NOT NULL DEFAULT 0,
		richiede_firma tinyint(1) NOT NULL DEFAULT 0,
		created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		KEY azienda_id (azienda_id)
	) $charset;";

	$sql[] = 'CREATE TABLE ' . apac_table( 'scadenze' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		azienda_id bigint(20) unsigned NOT NULL,
		titolo varchar(200) NOT NULL DEFAULT '',
		categoria varchar(50) NOT NULL DEFAULT '',
		data_scadenza date NOT NULL,
		lavoratore_id bigint(20) unsigned NULL,
		stato varchar(20) NOT NULL DEFAULT 'aperta',
		note text NULL,
		avviso_inviato smallint(6) NOT NULL DEFAULT -1,
		ricorrenza_mesi smallint(6) NOT NULL DEFAULT 0,
		origine_id bigint(20) unsigned NULL,
		serie_id bigint(20) unsigned NULL,
		modificata tinyint(1) NOT NULL DEFAULT 0,
		created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		KEY azienda_id (azienda_id),
		KEY serie_id (serie_id),
		KEY origine_id (origine_id)
	) $charset;";

	$sql[] = 'CREATE TABLE ' . apac_table( 'segnalazioni' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		azienda_id bigint(20) unsigned NOT NULL,
		titolo varchar(200) NOT NULL DEFAULT '',
		descrizione text NULL,
		priorita varchar(10) NOT NULL DEFAULT 'media',
		stato varchar(20) NOT NULL DEFAULT 'aperta',
		risposta text NULL,
		autore_id bigint(20) unsigned NOT NULL DEFAULT 0,
		tipo varchar(20) NOT NULL DEFAULT '',
		created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		KEY azienda_id (azienda_id)
	) $charset;";

	$sql[] = 'CREATE TABLE ' . apac_table( 'log' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		azienda_id bigint(20) unsigned NOT NULL DEFAULT 0,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		azione varchar(60) NOT NULL DEFAULT '',
		dettaglio varchar(255) NOT NULL DEFAULT '',
		created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		KEY azienda_id (azienda_id),
		KEY created_at (created_at)
	) $charset;";

	// Esito di ogni avviso per destinatario: evita doppi invii e permette di ritentare solo chi non l'ha ricevuto.
	$sql[] = 'CREATE TABLE ' . apac_table( 'avvisi' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		tipo varchar(10) NOT NULL DEFAULT '',
		riga_id bigint(20) unsigned NOT NULL DEFAULT 0,
		data_rif date NOT NULL,
		soglia smallint(6) NOT NULL DEFAULT 0,
		destinatario varchar(150) NOT NULL DEFAULT '',
		esito varchar(10) NOT NULL DEFAULT '',
		tentativi smallint(6) NOT NULL DEFAULT 0,
		errore varchar(255) NOT NULL DEFAULT '',
		updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		UNIQUE KEY chiave (tipo,riga_id,data_rif,soglia,destinatario),
		KEY riga (tipo,riga_id)
	) $charset;";

	// Riferimento minimo agli account eliminati (nessun dato personale): serve a
	// distinguere "utente eliminato" da "autore sconosciuto" nello storico.
	$sql[] = 'CREATE TABLE ' . apac_table( 'utenti_eliminati' ) . " (
		user_id bigint(20) unsigned NOT NULL,
		ruolo varchar(20) NOT NULL DEFAULT '',
		deleted_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (user_id)
	) $charset;";

	// 1.7.0 – versioni precedenti dei documenti.
	$sql[] = 'CREATE TABLE ' . apac_table( 'documenti_versioni' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		documento_id bigint(20) unsigned NOT NULL,
		azienda_id bigint(20) unsigned NOT NULL,
		nome_file varchar(255) NOT NULL DEFAULT '',
		file_path varchar(255) NOT NULL DEFAULT '',
		mime varchar(100) NOT NULL DEFAULT '',
		dimensione bigint(20) unsigned NOT NULL DEFAULT 0,
		caricato_da bigint(20) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		sostituito_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		KEY documento_id (documento_id),
		KEY azienda_id (azienda_id)
	) $charset;";

	// 1.7.0 – foto allegate alle segnalazioni.
	$sql[] = 'CREATE TABLE ' . apac_table( 'segnalazioni_foto' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		segnalazione_id bigint(20) unsigned NOT NULL,
		azienda_id bigint(20) unsigned NOT NULL,
		nome_file varchar(255) NOT NULL DEFAULT '',
		file_path varchar(255) NOT NULL DEFAULT '',
		mime varchar(100) NOT NULL DEFAULT '',
		dimensione bigint(20) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		KEY segnalazione_id (segnalazione_id),
		KEY azienda_id (azienda_id)
	) $charset;";

	// 1.7.0 – prese visione firmate.
	$sql[] = 'CREATE TABLE ' . apac_table( 'firme' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		documento_id bigint(20) unsigned NOT NULL,
		azienda_id bigint(20) unsigned NOT NULL,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		nome varchar(200) NOT NULL DEFAULT '',
		email varchar(150) NOT NULL DEFAULT '',
		firma longtext NULL,
		sha256 varchar(64) NOT NULL DEFAULT '',
		nome_file varchar(255) NOT NULL DEFAULT '',
		ip varchar(64) NOT NULL DEFAULT '',
		user_agent varchar(255) NOT NULL DEFAULT '',
		created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		KEY documento_id (documento_id),
		KEY azienda_id (azienda_id)
	) $charset;";

	// 1.7.2 – firme dei documenti al primo accesso (informativa privacy, accordo art. 28 GDPR).
	$sql[] = 'CREATE TABLE ' . apac_table( 'consensi' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		azienda_id bigint(20) unsigned NOT NULL,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		tipo varchar(20) NOT NULL DEFAULT '',
		versione char(64) NOT NULL DEFAULT '',
		nome varchar(200) NOT NULL DEFAULT '',
		email varchar(150) NOT NULL DEFAULT '',
		ruolo varchar(150) NOT NULL DEFAULT '',
		ip varchar(64) NOT NULL DEFAULT '',
		user_agent varchar(255) NOT NULL DEFAULT '',
		file_path varchar(255) NOT NULL DEFAULT '',
		nome_file varchar(255) NOT NULL DEFAULT '',
		mime varchar(100) NOT NULL DEFAULT 'application/pdf',
		dimensione bigint(20) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		KEY azienda_id (azienda_id),
		KEY user_tipo (user_id,tipo)
	) $charset;";

	// 1.9.1 – codici monouso dei moduli: la chiave unica rende il controllo dei doppi invii atomico.
	$sql[] = 'CREATE TABLE ' . apac_table( 'invii' ) . " (
		chiave char(32) NOT NULL,
		created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		esito tinyint(1) NOT NULL DEFAULT 0,
		PRIMARY KEY  (chiave),
		KEY created_at (created_at)
	) $charset;";

	// 1.7.0 – corsi richiesti per mansione (per azienda).
	$sql[] = 'CREATE TABLE ' . apac_table( 'mansioni_corsi' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		azienda_id bigint(20) unsigned NOT NULL,
		mansione varchar(200) NOT NULL DEFAULT '',
		corso varchar(150) NOT NULL DEFAULT '',
		PRIMARY KEY  (id),
		KEY azienda_id (azienda_id)
	) $charset;";

	// 1.7.0 – iscrizioni alle notifiche sul telefono.
	$sql[] = 'CREATE TABLE ' . apac_table( 'push' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL,
		endpoint text NOT NULL,
		endpoint_hash char(64) NOT NULL DEFAULT '',
		p256dh varchar(255) NOT NULL DEFAULT '',
		auth varchar(64) NOT NULL DEFAULT '',
		user_agent varchar(255) NOT NULL DEFAULT '',
		created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		UNIQUE KEY endpoint_hash (endpoint_hash),
		KEY user_id (user_id)
	) $charset;";

	// 1.9.13 – richieste di accesso (modulo pubblico e richieste per colleghi).
	$sql[] = 'CREATE TABLE ' . apac_table( 'richieste' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		stato varchar(20) NOT NULL DEFAULT '',
		tipo varchar(12) NOT NULL DEFAULT '',
		ragione_sociale varchar(200) NOT NULL DEFAULT '',
		partita_iva varchar(20) NOT NULL DEFAULT '',
		dati text NULL,
		nome varchar(100) NOT NULL DEFAULT '',
		cognome varchar(100) NOT NULL DEFAULT '',
		email varchar(150) NOT NULL DEFAULT '',
		telefono varchar(40) NOT NULL DEFAULT '',
		ruolo varchar(20) NOT NULL DEFAULT '',
		accesso varchar(10) NOT NULL DEFAULT '',
		messaggio text NULL,
		azienda_id bigint(20) unsigned NOT NULL DEFAULT 0,
		richiedente_id bigint(20) unsigned NOT NULL DEFAULT 0,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		token_hash char(64) NOT NULL DEFAULT '',
		ip_hash char(64) NOT NULL DEFAULT '',
		motivo text NULL,
		gestita_da bigint(20) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		inviata_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		confermata_at datetime NULL,
		gestita_at datetime NULL,
		PRIMARY KEY  (id),
		KEY stato (stato),
		KEY email (email),
		KEY partita_iva (partita_iva)
	) $charset;";

	$suppress = $wpdb->suppress_errors( true );
	foreach ( $sql as $statement ) {
		dbDelta( $statement );
	}
	$wpdb->suppress_errors( $suppress );

	apac_ensure_innodb();

	// 1.9.5: nessuna marcatura di massa delle prenotazioni (una migrazione interrotta e ripetuta avrebbe
	// potuto toccare anche quelle nuove). Le prenotazioni delle versioni precedenti si riconoscono dal
	// valore di esito, che la 1.9.5 non scrive più (vedi APAC_INVIO_PRENOTATO / APAC_INVIO_RIUSCITO).

	// Chiave di ordinamento per le aziende già presenti.
	$rows = $wpdb->get_results( 'SELECT id, ragione_sociale FROM ' . apac_table( 'aziende' ) . " WHERE ragione_sort = '' AND ragione_sociale <> ''" );
	$ok   = is_array( $rows ) && '' === $wpdb->last_error;
	foreach ( (array) $rows as $r ) {
		if ( false === $wpdb->update( apac_table( 'aziende' ), array( 'ragione_sort' => apac_sort_key( $r->ragione_sociale ) ), array( 'id' => $r->id ) ) ) {
			$ok = false;
		}
	}

	// La versione si aggiorna SOLO se tutte le tabelle e le colonne risultano presenti.
	$missing = apac_db_missing();
	if ( ! $missing && ! apac_backfill_scadenze_series() ) {
		$ok = false;
	}
	if ( $ok && ! $missing ) {
		update_option( 'apac_db_version', APAC_DB_VERSION );
		delete_option( 'apac_migration_error' );
		return true;
	}
	update_option(
		'apac_migration_error',
		array(
			'date'    => current_time( 'mysql' ),
			'missing' => $missing ? implode( ', ', $missing ) : 'aggiornamento dei dati esistenti non riuscito',
			'db'      => (string) $wpdb->last_error,
		),
		false
	);
	return false;
}

/** Colonne indispensabili per ciascuna tabella (usate per verificare la migrazione). */
function apac_db_required() {
	return array(
		'aziende'          => array( 'id', 'ragione_sociale', 'ragione_sort', 'partita_iva', 'codice_fiscale', 'permessi', 'dati_ver' ),
		'lavoratori'       => array( 'id', 'azienda_id', 'nome', 'cognome', 'attivo', 'data_cessazione' ),
		'attestati'        => array( 'id', 'azienda_id', 'lavoratore_id', 'corso', 'data_conseguimento', 'data_scadenza', 'documento_id', 'avviso_inviato' ),
		'documenti'        => array( 'id', 'azienda_id', 'file_path', 'caricato_da', 'richiede_firma' ),
		'scadenze'         => array( 'id', 'azienda_id', 'data_scadenza', 'stato', 'avviso_inviato', 'ricorrenza_mesi', 'origine_id', 'serie_id', 'modificata' ),
		'invii'            => array( 'chiave', 'created_at', 'esito' ),
		'segnalazioni'     => array( 'id', 'azienda_id', 'autore_id', 'stato', 'tipo' ),
		'log'              => array( 'id', 'azienda_id', 'user_id', 'azione' ),
		'avvisi'           => array( 'id', 'tipo', 'riga_id', 'data_rif', 'soglia', 'destinatario', 'esito', 'tentativi' ),
		'utenti_eliminati' => array( 'user_id', 'ruolo', 'deleted_at' ),
		'documenti_versioni' => array( 'id', 'documento_id', 'azienda_id', 'file_path' ),
		'segnalazioni_foto'  => array( 'id', 'segnalazione_id', 'azienda_id', 'file_path' ),
		'firme'              => array( 'id', 'documento_id', 'azienda_id', 'user_id', 'sha256' ),
		'consensi'           => array( 'id', 'azienda_id', 'user_id', 'tipo', 'versione', 'file_path' ),
		'mansioni_corsi'     => array( 'id', 'azienda_id', 'mansione', 'corso' ),
		'push'               => array( 'id', 'user_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth' ),
		'richieste'          => array( 'id', 'stato', 'tipo', 'email', 'partita_iva', 'token_hash', 'azienda_id', 'richiedente_id', 'accesso', 'inviata_at', 'gestita_at' ),
	);
}

/** Elenco "tabella.colonna" mancanti (vuoto = struttura completa). */
function apac_db_missing() {
	global $wpdb;
	$missing  = array();
	$suppress = $wpdb->suppress_errors( true );
	foreach ( apac_db_required() as $t => $cols ) {
		$found = $wpdb->get_col( 'SHOW COLUMNS FROM ' . apac_table( $t ), 0 );
		if ( ! $found ) {
			$missing[] = $t;
			continue;
		}
		foreach ( $cols as $c ) {
			if ( ! in_array( $c, $found, true ) ) {
				$missing[] = $t . '.' . $c;
			}
		}
	}
	$wpdb->suppress_errors( $suppress );
	return $missing;
}

/** Nomi completi delle tabelle del plugin. */
function apac_all_tables() {
	return array_map( 'apac_table', array( 'aziende', 'lavoratori', 'attestati', 'documenti', 'scadenze', 'segnalazioni', 'log', 'avvisi', 'utenti_eliminati', 'documenti_versioni', 'segnalazioni_foto', 'firme', 'mansioni_corsi', 'push', 'consensi', 'invii', 'richieste' ) );
}

/**
 * Le transazioni (annullamento delle operazioni a metà) funzionano solo con InnoDB.
 * Converte eventuali tabelle MyISAM del plugin. Su database diversi da MySQL non fa nulla.
 */
function apac_ensure_innodb() {
	global $wpdb;
	$errori = array();
	foreach ( apac_all_tables() as $t ) {
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $t ) );
		if ( $row && isset( $row->Engine ) && 'innodb' !== strtolower( (string) $row->Engine ) ) {
			// 1.9.15: l'esito della conversione viene controllato (prima un errore passava inosservato).
			if ( false === $wpdb->query( "ALTER TABLE `{$t}` ENGINE=InnoDB" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$errori[ $t ] = (string) $wpdb->last_error;
			}
		}
	}
	// Esito verificato sul database dopo i tentativi, non dedotto dalle istruzioni eseguite.
	$problemi = apac_tx_tabelle_problema( true );
	if ( null === $problemi || $problemi ) {
		update_option(
			'apac_tx_problemi',
			array(
				'tabelle' => null === $problemi ? array() : $problemi,
				'errori'  => $errori,
				'ts'      => time(),
			),
			false
		);
	} else {
		delete_option( 'apac_tx_problemi' );
	}
	return array() === $problemi;
}

/**
 * 1.9.15 – Tabelle del plugin che NON garantiscono il "tutto o niente" (motore diverso da InnoDB o tabella assente).
 * Una sola interrogazione; il risultato vale per la richiesta in corso (una tabella convertita a mano
 * viene rilevata alla richiesta successiva, senza attese di cache).
 *
 * @param bool $refresh Rilegge dal database anche se già letto in questa richiesta.
 * @return array<string,string>|null tabella => motore ('' se assente); null se non determinabile.
 */
function apac_tx_tabelle_problema( $refresh = false ) {
	global $wpdb;
	static $cache = false;
	if ( false !== $cache && ! $refresh ) {
		return $cache;
	}
	$tabelle  = apac_all_tables();
	$suppress = $wpdb->suppress_errors( true );
	$rows     = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT TABLE_NAME AS t, ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode( ',', array_fill( 0, count( $tabelle ), '%s' ) ) . ')',
			$tabelle
		)
	);
	$errore   = (string) $wpdb->last_error;
	$wpdb->suppress_errors( $suppress );
	if ( ! is_array( $rows ) || '' !== $errore ) {
		$cache = null;
		return $cache;
	}
	$motori = array();
	foreach ( $rows as $r ) {
		$motori[ (string) $r->t ] = (string) $r->e;
	}
	$cache = array();
	foreach ( $tabelle as $t ) {
		$e = isset( $motori[ $t ] ) ? $motori[ $t ] : '';
		if ( 'innodb' !== strtolower( $e ) ) {
			$cache[ $t ] = $e;
		}
	}
	return $cache;
}

/**
 * Stato del supporto alle transazioni, per la pagina impostazioni.
 * 'si' = tutte InnoDB; 'no' = almeno una tabella senza transazioni; '' = non determinabile.
 */
/**
 * 1.9.16 – Unica condizione che blocca i salvataggi (usata dal blocco e da tutti gli avvisi, così non
 * possono divergere): una tabella del plugin non transazionale OPPURE stato non verificabile.
 */
function apac_tx_salvataggi_bloccati() {
	return array() !== apac_tx_tabelle_problema();
}

function apac_tx_support() {
	$p = apac_tx_tabelle_problema();
	if ( null === $p ) {
		return '';
	}
	return $p ? 'no' : 'si';
}

/** Ricostruisce le radici dalle origini, senza fidarsi di serie_id parziali di vecchie migrazioni. */
function apac_scadenze_series_roots( $rows ) {
	$by_id = array();
	foreach ( $rows as $row ) {
		$by_id[ (int) $row->id ] = $row;
	}
	$roots = array();
	foreach ( $by_id as $id => $row ) {
		if ( isset( $roots[ $id ] ) ) {
			continue;
		}
		$path = array();
		$pos  = array();
		$cur  = $id;
		$root = $id; // Sempre assegnata nel ciclo; valore iniziale per l'analisi statica.
		while ( true ) {
			if ( isset( $roots[ $cur ] ) ) {
				$root = $roots[ $cur ];
				break;
			}
			if ( isset( $pos[ $cur ] ) ) {
				// Dati corrotti: un ciclo ha una radice deterministica, mai un ciclo infinito.
				$root = min( array_slice( $path, $pos[ $cur ] ) );
				break;
			}
			$pos[ $cur ] = count( $path );
			$path[] = $cur;
			$node = $by_id[ $cur ];
			$parent = ! empty( $node->origine_id ) ? (int) $node->origine_id : 0;
			if ( ! $parent || ! isset( $by_id[ $parent ] ) || (int) $by_id[ $parent ]->azienda_id !== (int) $node->azienda_id ) {
				$root = $cur;
				break;
			}
			$cur = $parent;
		}
		foreach ( $path as $part ) {
			$roots[ $part ] = (int) $root;
		}
	}
	return $roots;
}

/** Backfill ripetibile: aggiorna soltanto le righe la cui radice differisce. */
function apac_backfill_scadenze_series() {
	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT id, azienda_id, origine_id, serie_id FROM ' . apac_table( 'scadenze' ) );
	if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
		return false;
	}
	$roots = apac_scadenze_series_roots( $rows );
	foreach ( $rows as $row ) {
		$root = $roots[ (int) $row->id ];
		if ( (int) $row->serie_id !== $root && false === $wpdb->update(
			apac_table( 'scadenze' ), array( 'serie_id' => $root ),
			array( 'id' => (int) $row->id, 'azienda_id' => (int) $row->azienda_id )
		) ) {
			return false; // La versione DB non avanza: il prossimo tentativo riprende in sicurezza.
		}
	}
	return true;
}
