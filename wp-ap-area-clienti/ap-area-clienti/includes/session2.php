<?php
defined( 'ABSPATH' ) || exit;

/*
 * 1.7.0 – Sessione 2 (gestione dati):
 * accessi in sola consultazione, scadenze ricorrenti, storico versioni dei documenti,
 * foto nelle segnalazioni, importazione ed esportazione Excel, ricerca unica, filtri.
 */

/* ===================== Accessi: modifica / sola consultazione ===================== */

/** L'utente cliente ha un accesso in sola consultazione? */
function apac_user_readonly( $user_id = 0 ) {
	$user_id = $user_id ? $user_id : get_current_user_id();
	if ( ! $user_id || user_can( $user_id, 'manage_options' ) ) {
		return false;
	}
	return 'lettura' === get_user_meta( $user_id, 'apac_accesso', true );
}

function apac_accesso_options() {
	return array(
		'modifica' => 'Può inserire e modificare (secondo i permessi dell’azienda)',
		'lettura'  => 'Solo consultazione',
	);
}

/* ============================ Scadenze ricorrenti ============================ */

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

/* ========================= File: versioni e foto ========================= */

/** Archivia il file attuale di un documento come versione precedente (al posto di cancellarlo). */
function apac_archivia_versione( $doc ) {
	apac_must_insert(
		'documenti_versioni',
		array(
			'documento_id'  => (int) $doc->id,
			'azienda_id'    => (int) $doc->azienda_id,
			'nome_file'     => $doc->nome_file,
			'file_path'     => $doc->file_path,
			'mime'          => $doc->mime,
			'dimensione'    => (int) $doc->dimensione,
			'caricato_da'   => (int) $doc->caricato_da,
			'created_at'    => $doc->created_at,
			'sostituito_at' => apac_now(),
		),
		'documenti'
	);
}

function apac_get_versioni( $doc_id, $azienda_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'documenti_versioni' ) . ' WHERE documento_id = %d AND azienda_id = %d ORDER BY id DESC', $doc_id, $azienda_id ) );
}

/** Link protetto a un file secondario (versione precedente o foto). */
function apac_extra_file_url( $kind, $id, $mode = 'download' ) {
	return wp_nonce_url(
		add_query_arg(
			array(
				'apac_' . $kind => (int) $id,
				'mode'          => 'view' === $mode ? 'view' : 'download',
			),
			apac_portal_page_url()
		),
		'apac_' . $kind . '_' . (int) $id
	);
}

add_action( 'init', 'apac_serve_extra_files', 19 );
function apac_serve_extra_files() {
	global $wpdb;
	if ( is_admin() ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification
	foreach ( array( 'ver' => 'documenti_versioni', 'foto' => 'segnalazioni_foto' ) as $kind => $table ) {
		if ( ! isset( $_GET[ 'apac_' . $kind ] ) ) {
			continue;
		}
		$id    = absint( $_GET[ 'apac_' . $kind ] );
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! is_user_logged_in() || ! $id || ! wp_verify_nonce( $nonce, 'apac_' . $kind . '_' . $id ) ) {
			wp_die( 'Link non valido o scaduto. Torna all’area clienti e riprova.', 'Accesso negato', array( 'response' => 403 ) );
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . apac_table( $table ) . ' WHERE id = %d', $id ) );
		if ( ! $row || ! apac_can_access_azienda( (int) $row->azienda_id ) ) {
			wp_die( 'File non disponibile.', 'Accesso negato', array( 'response' => 403 ) );
		}
		apac_require_consents_for_download();
		$path = apac_document_abs_path( $row );
		if ( ! $path || ! is_readable( $path ) ) {
			wp_die( 'File non trovato sul server.', 'Errore', array( 'response' => 404 ) );
		}
		$inline = isset( $_GET['mode'] ) && 'view' === $_GET['mode'] && in_array( $row->mime, array( 'application/pdf', 'image/jpeg', 'image/png' ), true );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		$filename = str_replace( array( '"', "\r", "\n" ), '', $row->nome_file );
		nocache_headers();
		header( 'Content-Type: ' . $row->mime );
		header( 'Content-Length: ' . filesize( $path ) );
		apac_download_header( $filename, $inline ? 'inline' : 'attachment' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
	// phpcs:enable
}

/** Foto caricate con una segnalazione (massimo 3, solo JPG/PNG). */
function apac_store_segnalazione_foto( $segnalazione_id, $azienda_id ) {
	if ( empty( $_FILES['foto'] ) || ! is_array( $_FILES['foto']['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$f     = $_FILES['foto']; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
	$count = 0;
	foreach ( array_keys( $f['name'] ) as $i ) {
		if ( '' === (string) $f['name'][ $i ] ) {
			continue;
		}
		if ( ++$count > 3 ) {
			apac_error_field( 'foto' );
			apac_redirect( 'segnalazioni', 'too_many_photos' );
		}
		$one = array(
			'name'     => $f['name'][ $i ],
			'type'     => $f['type'][ $i ],
			'tmp_name' => $f['tmp_name'][ $i ],
			'error'    => $f['error'][ $i ],
			'size'     => $f['size'][ $i ],
		);
		$ext = strtolower( pathinfo( (string) $one['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
			apac_error_field( 'foto' );
			apac_redirect( 'segnalazioni', 'photo_type' );
		}
		$stored = apac_store_upload( $one, $azienda_id );
		if ( is_wp_error( $stored ) ) {
			apac_error_field( 'foto' );
			apac_redirect( 'segnalazioni', $stored->get_error_code() );
		}
		if ( ! in_array( $stored['mime'], array( 'image/jpeg', 'image/png' ), true ) ) {
			apac_error_field( 'foto' );
			apac_redirect( 'segnalazioni', 'photo_type' );
		}
		apac_must_insert(
			'segnalazioni_foto',
			array(
				'segnalazione_id' => (int) $segnalazione_id,
				'azienda_id'      => (int) $azienda_id,
				'nome_file'       => $stored['name'],
				'file_path'       => $stored['path'],
				'mime'            => $stored['mime'],
				'dimensione'      => $stored['size'],
				'created_at'      => apac_now(),
			),
			'segnalazioni'
		);
	}
}

function apac_get_foto( $segnalazione_id, $azienda_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'segnalazioni_foto' ) . ' WHERE segnalazione_id = %d AND azienda_id = %d ORDER BY id ASC', $segnalazione_id, $azienda_id ) );
}

/** Rimozione dei file secondari collegati (dopo la conferma della transazione). */
function apac_delete_rows_files_after_commit( $rows ) {
	foreach ( (array) $rows as $r ) {
		apac_tx_after_commit(
			function () use ( $r ) {
				apac_delete_document_file( $r );
			}
		);
	}
}

/* =============================== Ricerca unica =============================== */

function apac_render_search_box( $q = '' ) {
	echo '<form method="get" class="apac-find" role="search" action="' . esc_url( apac_portal_page_url() ) . '">';
	if ( ! get_option( 'permalink_structure' ) && get_option( 'apac_page_id' ) ) {
		echo '<input type="hidden" name="page_id" value="' . (int) get_option( 'apac_page_id' ) . '">';
	}
	echo '<input type="hidden" name="sez" value="cerca">';
	if ( apac_is_admin() && ! empty( $_GET['azienda'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		echo '<input type="hidden" name="azienda" value="' . absint( $_GET['azienda'] ) . '">'; // phpcs:ignore WordPress.Security.NonceVerification
	}
	echo '<label class="screen-reader-text" for="apac-find-q">Cerca nell’area riservata</label>';
	echo '<span class="apac-find__ico">' . apac_icon_extra( 'search' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<input type="search" id="apac-find-q" name="q" value="' . esc_attr( $q ) . '" placeholder="Nome, corso, documento…" minlength="2" maxlength="80" autocomplete="off">';
	echo '<button type="submit" class="apac-btn apac-btn--small">Cerca</button></form>';
}

function apac_render_cerca( $az ) {
	global $wpdb;
	$id = (int) $az->id;
	$q  = isset( $_GET['q'] ) ? mb_substr( trim( sanitize_text_field( wp_unslash( $_GET['q'] ) ) ), 0, 80 ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	apac_render_search_box( $q );
	if ( mb_strlen( $q ) < 2 ) {
		echo '<p class="apac-muted">Scrivi almeno 2 caratteri: nome o codice fiscale di un lavoratore, titolo di un documento, descrizione di una scadenza o nome di un corso.</p>';
		return;
	}
	$like = '%' . $wpdb->esc_like( $q ) . '%';
	$lav  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'lavoratori' ) . ' WHERE azienda_id = %d AND (CONCAT(cognome, " ", nome) LIKE %s OR CONCAT(nome, " ", cognome) LIKE %s OR codice_fiscale LIKE %s OR mansione LIKE %s) ORDER BY cognome, nome LIMIT 30', $id, $like, $like, $like, $like ) );
	$doc  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'documenti' ) . ' WHERE azienda_id = %d AND (titolo LIKE %s OR nome_file LIKE %s) ORDER BY created_at DESC LIMIT 30', $id, $like, $like ) );
	$sca  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'scadenze' ) . ' WHERE azienda_id = %d AND (titolo LIKE %s OR note LIKE %s) ORDER BY data_scadenza ASC LIMIT 30', $id, $like, $like ) );
	$att  = $wpdb->get_results( $wpdb->prepare( 'SELECT a.*, l.nome, l.cognome FROM ' . apac_table( 'attestati' ) . ' a INNER JOIN ' . apac_table( 'lavoratori' ) . ' l ON l.id = a.lavoratore_id WHERE a.azienda_id = %d AND a.corso LIKE %s ORDER BY l.cognome, a.corso LIMIT 30', $id, $like ) );
	$tot  = count( $lav ) + count( $doc ) + count( $sca ) + count( $att );
	echo '<p class="apac-find__count" role="status">' . ( $tot ? (int) $tot . ( 1 === $tot ? ' risultato' : ' risultati' ) . ' per «' . esc_html( $q ) . '»' : 'Nessun risultato per «' . esc_html( $q ) . '». Prova con una parola diversa.' ) . '</p>';
	$group = function ( $title, $icon, $items ) {
		if ( ! $items ) {
			return;
		}
		echo '<section class="apac-card apac-find__group"><h2>' . apac_icon( $icon ) . esc_html( $title ) . ' <small>(' . count( $items ) . ')</small></h2><ul class="apac-list">'; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( $items as $it ) {
			echo '<li><a href="' . esc_url( $it[0] ) . '"><span>' . esc_html( $it[1] ) . '<small>' . esc_html( $it[2] ) . '</small></span>' . ( isset( $it[3] ) ? $it[3] : apac_icon( 'arrow' ) ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul></section>';
	};
	$cats_d = apac_categorie_documenti();
	$cats_s = apac_categorie_scadenze();
	$group( 'Lavoratori', 'users', array_map( function ( $l ) { return array( apac_url( 'lavoratori', array( 'lav' => (int) $l->id ) ), $l->cognome . ' ' . $l->nome, ( '' !== $l->mansione ? $l->mansione : 'Mansione da definire' ) . ( $l->attivo ? '' : ' · cessato' ) ); }, $lav ) );
	$group( 'Attestati di formazione', 'check', array_map( function ( $a ) { return array( apac_url( 'lavoratori', array( 'lav' => (int) $a->lavoratore_id ) ), $a->corso, $a->cognome . ' ' . $a->nome . ( $a->data_scadenza ? ' · scade il ' . apac_date_it( $a->data_scadenza ) : '' ) ); }, $att ) );
	$group( 'Documenti', 'file', array_map( function ( $d ) use ( $cats_d ) { return array( apac_file_url( $d->id, 'view' ), $d->titolo, ( isset( $cats_d[ $d->categoria ] ) ? $cats_d[ $d->categoria ] : $d->categoria ) . ' · ' . apac_date_it( $d->created_at ) ); }, $doc ) );
	$group( 'Scadenze', 'calendar', array_map( function ( $s ) use ( $cats_s ) { return array( apac_url( 'scadenziario', array( 'tutte' => 1 ) ), $s->titolo, ( isset( $cats_s[ $s->categoria ] ) ? $cats_s[ $s->categoria ] : $s->categoria ) . ' · ' . apac_date_it( $s->data_scadenza ), 'completata' === $s->stato ? '<span class="apac-badge apac-badge--ok">Completato</span>' : apac_badge_scadenza( $s->data_scadenza ) ); }, $sca ) );
}

/* ============================== Filtri ============================== */

/** Valore di un filtro dalla richiesta, solo se ammesso. */
function apac_filter_val( $name, $allowed, $default = '' ) {
	$v = isset( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) : $default; // phpcs:ignore WordPress.Security.NonceVerification
	return in_array( $v, $allowed, true ) ? $v : $default;
}

/**
 * Barra "Filtri e ordinamento" richiudibile. $fields: name => [label, options, current].
 * Senza JavaScript si applica con il pulsante; con JavaScript alla scelta.
 */
function apac_render_filterbar( $sez, $fields, $keep = array() ) {
	$active = 0;
	foreach ( $fields as $name => $f ) {
		if ( '' !== (string) $f[2] && (string) $f[2] !== (string) array_key_first( $f[1] ) ) {
			$active++;
		}
	}
	// 1.9.0: i filtri attivi sono già indicati (e rimovibili) nella riga sotto: il pannello resta chiuso.
	echo '<details class="apac-filterbar"><summary>' . apac_icon_extra( 'filter' ) . '<span>Filtri e ordinamento</span>' . ( $active ? '<b class="apac-filterbar__n">' . (int) $active . '</b>' : '' ) . '</summary>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<form method="get" class="apac-filterbar__form" action="' . esc_url( apac_portal_page_url() ) . '">';
	if ( ! get_option( 'permalink_structure' ) && get_option( 'apac_page_id' ) ) {
		echo '<input type="hidden" name="page_id" value="' . (int) get_option( 'apac_page_id' ) . '">';
	}
	echo '<input type="hidden" name="sez" value="' . esc_attr( $sez ) . '">';
	if ( apac_is_admin() && ! empty( $_GET['azienda'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		echo '<input type="hidden" name="azienda" value="' . absint( $_GET['azienda'] ) . '">'; // phpcs:ignore WordPress.Security.NonceVerification
	}
	foreach ( $keep as $k => $v ) {
		echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
	}
	foreach ( $fields as $name => $f ) {
		echo '<label class="apac-field"><span>' . esc_html( $f[0] ) . '</span><select name="' . esc_attr( $name ) . '" data-apac-autosubmit>';
		foreach ( $f[1] as $val => $lab ) {
			echo '<option value="' . esc_attr( $val ) . '"' . selected( (string) $f[2], (string) $val, false ) . '>' . esc_html( $lab ) . '</option>';
		}
		echo '</select></label>';
	}
	echo '<div class="apac-filterbar__actions"><button type="submit" class="apac-btn apac-btn--small apac-prefs__save">Applica</button>';
	if ( $active ) {
		$reset = apac_url( $sez, $keep );
		echo '<a class="apac-filterbar__reset" href="' . esc_url( $reset ) . '">Azzera filtri</a>';
	}
	echo '</div></form></details>';
}

/** Stato della formazione di un lavoratore: scaduta, in_scadenza, ok, senza_scadenza, nessuno. */
function apac_stato_formazione( $n_attestati, $prossima ) {
	if ( ! $n_attestati ) {
		return 'nessuno';
	}
	if ( ! $prossima ) {
		return 'senza_scadenza';
	}
	$giorni = apac_days_until( $prossima );
	if ( $giorni < 0 ) {
		return 'scaduta';
	}
	return $giorni <= 30 ? 'in_scadenza' : 'ok';
}

/* ==================== Importazione da Excel / CSV ==================== */

/** Colonne riconosciute (intestazione normalizzata => campo). */
function apac_import_columns() {
	return array(
		'cognome'            => 'cognome',
		'nome'               => 'nome',
		'codicefiscale'      => 'cf',
		'cf'                 => 'cf',
		'mansione'           => 'mansione',
		'dataassunzione'     => 'assunzione',
		'assunzione'         => 'assunzione',
		'corso'              => 'corso',
		'dataconseguimento'  => 'conseguimento',
		'conseguimento'      => 'conseguimento',
		'conseguito'         => 'conseguimento',
		'datascadenza'       => 'scadenza',
		'scadenza'           => 'scadenza',
		'scadenzaaggiornamento' => 'scadenza',
		'note'               => 'note',
	);
}

function apac_import_norm_header( $h ) {
	$h = strtolower( remove_accents( trim( (string) $h ) ) );
	return preg_replace( '/[^a-z]/', '', $h );
}

/** Data da Excel/CSV: gg/mm/aaaa, g/m/aa, aaaa-mm-gg o numero seriale di Excel. Restituisce Y-m-d, '' (vuota) o false. */
function apac_import_date( $v ) {
	$v = trim( (string) $v );
	if ( '' === $v ) {
		return '';
	}
	if ( preg_match( '/^\d{4,5}(\.0+)?$/', $v ) && (int) $v > 10000 && (int) $v < 80000 ) {
		return gmdate( 'Y-m-d', ( (int) $v - 25569 ) * 86400 );
	}
	if ( preg_match( '#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2}|\d{4})$#', $v, $m ) ) {
		$y = (int) $m[3];
		if ( $y < 100 ) {
			$y += ( $y > 50 ) ? 1900 : 2000;
		}
		$d = (int) $m[1];
		$mo = (int) $m[2];
	} elseif ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m ) ) {
		$y  = (int) $m[1];
		$mo = (int) $m[2];
		$d  = (int) $m[3];
	} else {
		return false;
	}
	if ( ! checkdate( $mo, $d, $y ) || $y < 1950 || $y > 2100 ) {
		return false;
	}
	return sprintf( '%04d-%02d-%02d', $y, $mo, $d );
}

/** 1.9.5: limiti dell'importazione (righe di dati e colonne; il modello ne usa 9). */
if ( ! defined( 'APAC_IMPORT_MAX_ROWS' ) ) {
	define( 'APAC_IMPORT_MAX_ROWS', 2000 );
}
if ( ! defined( 'APAC_IMPORT_MAX_COLS' ) ) {
	define( 'APAC_IMPORT_MAX_COLS', 100 );
}

/**
 * 1.9.5 – Scompone un testo CSV (virgolette doppie, "" = virgoletta nel campo, campi su più righe tra
 * virgolette) memorizzando al massimo $max_cols colonne per record e $max_rows record.
 * La memoria usata non supera quella del testo, qualunque sia il numero di separatori.
 * Una riga vuota dà un record vuoto (come fgetcsv), così la numerazione delle righe resta la stessa.
 */
function apac_csv_parse_bounded( $raw, $delim, $max_cols, $max_rows ) {
	$rows  = array();
	$row   = array();
	$field = '';
	$nf    = 0; // Campi già chiusi nel record corrente (anche quelli non memorizzati).
	$quote = false;
	$any   = false; // Il record corrente ha contenuto (campi o separatori).
	$n     = strlen( $raw );
	$i     = 0;
	$stop  = "\"\r\n" . $delim;
	while ( $i < $n ) {
		if ( $quote ) {
			$p = strpos( $raw, '"', $i );
			if ( false === $p ) { // Virgolette non chiuse: il resto del testo è il campo.
				if ( $nf < $max_cols ) {
					$field .= substr( $raw, $i );
				}
				$i = $n;
				break;
			}
			if ( $nf < $max_cols ) {
				$field .= substr( $raw, $i, $p - $i );
			}
			if ( $p + 1 < $n && '"' === $raw[ $p + 1 ] ) {
				if ( $nf < $max_cols ) {
					$field .= '"';
				}
				$i = $p + 2;
				continue;
			}
			$quote = false;
			$i     = $p + 1;
			continue;
		}
		$c = $raw[ $i ];
		if ( '"' === $c ) {
			$quote = true;
			$any   = true;
			$i++;
			continue;
		}
		if ( $c === $delim ) {
			if ( $nf < $max_cols ) {
				$row[] = $field;
			}
			$nf++;
			$field = '';
			$any   = true;
			$i++;
			continue;
		}
		if ( "\r" === $c || "\n" === $c ) {
			if ( $nf < $max_cols ) {
				$row[] = $field;
			}
			$rows[] = $any || '' !== $field ? $row : array( '' );
			if ( count( $rows ) >= $max_rows ) {
				return $rows;
			}
			$row   = array();
			$field = '';
			$nf    = 0;
			$any   = false;
			$i    += ( "\r" === $c && $i + 1 < $n && "\n" === $raw[ $i + 1 ] ) ? 2 : 1;
			continue;
		}
		$l = strcspn( $raw, $stop, $i );
		if ( $nf < $max_cols ) {
			$field .= substr( $raw, $i, $l );
		}
		$any = true;
		$i  += $l;
	}
	if ( $any || '' !== $field ) {
		if ( $nf < $max_cols ) {
			$row[] = $field;
		}
		$rows[] = $row;
	}
	return $rows;
}

/** Legge le righe di un file CSV o XLSX. @return array|WP_Error  (prima riga = intestazione) */
function apac_import_read_rows( $tmp, $name ) {
	$ext = strtolower( pathinfo( (string) $name, PATHINFO_EXTENSION ) );
	if ( 'csv' === $ext || 'txt' === $ext ) {
		$raw = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$raw = mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' );
		}
		// 1.9.5: solo la prima riga (prima il file intero veniva spezzato in righe: milioni di elementi
		// con un file fatto di soli "a capo").
		$first = substr( $raw, 0, strcspn( $raw, "\r\n" ) );
		$delim = ';';
		foreach ( array( ';', ',', "\t" ) as $c ) {
			if ( substr_count( $first, $c ) > substr_count( $first, $delim ) ) {
				$delim = $c;
			}
		}
		// 1.9.5: lettura con limite applicato DURANTE la scomposizione, sui record CSV (anche quelli con
		// campi su più righe tra virgolette), non sulle righe fisiche: le colonne oltre il limite non
		// vengono mai memorizzate e la lettura si ferma dopo il numero massimo di righe.
		return apac_csv_parse_bounded( $raw, $delim, APAC_IMPORT_MAX_COLS, APAC_IMPORT_MAX_ROWS + 2 );
	}
	if ( 'xlsx' !== $ext ) {
		return new WP_Error( 'import_type' );
	}
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'import_noxlsx' );
	}
	$zip = new ZipArchive();
	if ( true !== $zip->open( $tmp ) ) {
		return new WP_Error( 'import_bad' );
	}
	// 1.9.4: il limite si controlla PRIMA di decomprimere. 1) dimensione dichiarata nell'archivio;
	// 2) lettura comunque limitata a $max + 1 byte, se la dimensione dichiarata fosse falsa.
	// Così un XLSX molto compresso non può esaurire la memoria. 8 MB di XML bastano ampiamente
	// per il massimo di 2.000 righe importabili.
	$max  = 8 * MB_IN_BYTES;
	$load = function ( $path ) use ( $zip, $max ) {
		$st = $zip->statName( $path );
		if ( false === $st || ! isset( $st['size'] ) || (int) $st['size'] > $max ) {
			return null;
		}
		$xml = $zip->getFromName( $path, $max + 1 );
		if ( false === $xml || strlen( $xml ) > $max ) {
			return null;
		}
		$prev = libxml_use_internal_errors( true );
		$sx   = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT );
		libxml_use_internal_errors( $prev );
		return $sx ? $sx : null;
	};
	$shared = array();
	$ss     = $load( 'xl/sharedStrings.xml' );
	if ( $ss ) {
		foreach ( $ss->si as $si ) {
			if ( isset( $si->t ) ) {
				$shared[] = (string) $si->t;
			} else {
				$t = '';
				foreach ( $si->r as $run ) {
					$t .= (string) $run->t;
				}
				$shared[] = $t;
			}
		}
	}
	// Primo foglio del file.
	$sheet = 'xl/worksheets/sheet1.xml';
	$wb    = $load( 'xl/workbook.xml' );
	$rels  = $load( 'xl/_rels/workbook.xml.rels' );
	if ( $wb && $rels && isset( $wb->sheets->sheet[0] ) ) {
		$rid = (string) $wb->sheets->sheet[0]->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' )->id;
		foreach ( $rels->Relationship as $rel ) {
			if ( (string) $rel['Id'] === $rid ) {
				$target = ltrim( (string) $rel['Target'], '/' );
				$sheet  = 0 === strpos( $target, 'xl/' ) ? $target : 'xl/' . $target;
			}
		}
	}
	$sx = $load( $sheet );
	$zip->close();
	if ( ! $sx || ! isset( $sx->sheetData ) ) {
		return new WP_Error( 'import_bad' );
	}
	$rows = array();
	foreach ( $sx->sheetData->row as $row ) {
		$cells = array();
		foreach ( $row->c as $c ) {
			$ref = (string) $c['r'];
			$col = 0;
			if ( preg_match( '/^([A-Z]{1,3})[0-9]*$/', $ref, $m ) ) {
				foreach ( str_split( $m[1] ) as $ch ) {
					$col = $col * 26 + ( ord( $ch ) - 64 );
				}
				$col--;
			} elseif ( '' === $ref ) {
				$col = count( $cells );
			} else {
				return new WP_Error( 'import_bad' ); // Riferimento di cella non valido.
			}
			// 1.9.5: le celle oltre il limite di colonne vengono ignorate SENZA essere create (le colonne
			// del modello sono le prime). Prima un riferimento come "XFD1" o anomalo faceva creare
			// migliaia/milioni di celle vuote nel completamento della riga qui sotto.
			if ( $col < 0 || $col >= APAC_IMPORT_MAX_COLS ) {
				continue;
			}
			$t = (string) $c['t'];
			if ( 's' === $t ) {
				$val = isset( $shared[ (int) $c->v ] ) ? $shared[ (int) $c->v ] : '';
			} elseif ( 'inlineStr' === $t ) {
				$val = (string) $c->is->t;
			} else {
				$val = (string) $c->v;
			}
			$cells[ $col ] = $val;
		}
		if ( $cells ) {
			$max = max( array_keys( $cells ) );
			$out = array();
			for ( $i = 0; $i <= $max; $i++ ) {
				$out[] = isset( $cells[ $i ] ) ? $cells[ $i ] : '';
			}
			$rows[] = $out;
		}
		if ( count( $rows ) > APAC_IMPORT_MAX_ROWS + 1 ) {
			break;
		}
	}
	return $rows;
}

/**
 * Analizza le righe e le confronta con i dati esistenti.
 * 1.9.9: righe dello stesso attestato (stessa persona, corso e data di conseguimento):
 *  - con la stessa scadenza = duplicato esatto: si importa una sola volta;
 *  - con scadenze diverse   = CONFLITTO: nessuna riga si importa finché non si sceglie quale tenere
 *    ($scelte: codice del conflitto => numero di riga da tenere, 0 = nessuna);
 *  - già presente nell'archivio con scadenza diversa = errore: si corregge nella scheda del lavoratore.
 * @return array [ 'righe' => [...], 'nuovi' => n, 'attestati' => n, 'errori' => n, 'conflitti' => [ codice => [righe] ], 'irrisolti' => n ]
 */
function apac_import_analyze( $rows, $azienda_id, $scelte = array() ) {
	global $wpdb;
	$map    = apac_import_columns();
	$header = array_shift( $rows );
	$idx    = array();
	foreach ( (array) $header as $i => $h ) {
		$k = apac_import_norm_header( $h );
		if ( isset( $map[ $k ] ) && ! isset( $idx[ $map[ $k ] ] ) ) {
			$idx[ $map[ $k ] ] = $i;
		}
	}
	if ( ! isset( $idx['cognome'], $idx['nome'] ) ) {
		return new WP_Error( 'import_header' );
	}
	$esistenti = $wpdb->get_results( $wpdb->prepare( 'SELECT id, nome, cognome, codice_fiscale FROM ' . apac_table( 'lavoratori' ) . ' WHERE azienda_id = %d', $azienda_id ) );
	$by_cf     = array();
	$by_name   = array();
	foreach ( $esistenti as $e ) {
		if ( '' !== $e->codice_fiscale ) {
			$by_cf[ strtoupper( $e->codice_fiscale ) ] = (int) $e->id;
		}
		$by_name[ mb_strtolower( trim( $e->cognome ) . '|' . trim( $e->nome ) ) ][] = (int) $e->id;
	}
	$out   = array();
	$nuovi = array();
	$n_att = 0;
	$n_err = 0;
	$get   = function ( $r, $k ) use ( $idx ) {
		return isset( $idx[ $k ], $r[ $idx[ $k ] ] ) ? trim( (string) $r[ $idx[ $k ] ] ) : '';
	};
	$riga = 1;
	$gruppi  = array(); // 1.9.9: righe per attestato (persona|corso|data di conseguimento).
	$in_arch = array(); // Scadenze degli stessi attestati già in archivio.
	foreach ( $rows as $r ) {
		$riga++;
		if ( ! array_filter( array_map( 'trim', array_map( 'strval', (array) $r ) ) ) ) {
			continue; // riga vuota
		}
		$it = array(
			'riga'          => $riga,
			'cognome'       => sanitize_text_field( $get( $r, 'cognome' ) ),
			'nome'          => sanitize_text_field( $get( $r, 'nome' ) ),
			'cf'            => strtoupper( sanitize_text_field( $get( $r, 'cf' ) ) ),
			'mansione'      => sanitize_text_field( $get( $r, 'mansione' ) ),
			'assunzione'    => apac_import_date( $get( $r, 'assunzione' ) ),
			'corso'         => sanitize_text_field( $get( $r, 'corso' ) ),
			'conseguimento' => apac_import_date( $get( $r, 'conseguimento' ) ),
			'scadenza'      => apac_import_date( $get( $r, 'scadenza' ) ),
			'note'          => sanitize_textarea_field( $get( $r, 'note' ) ),
			'errore'        => '',
			'avviso'        => '',
			'lav_id'        => 0,
			'esito'         => '',
		);
		$err = '';
		if ( '' === $it['cognome'] || '' === $it['nome'] ) {
			$err = 'Cognome e nome sono obbligatori';
		} elseif ( mb_strlen( $it['cognome'] ) > 100 || mb_strlen( $it['nome'] ) > 100 || mb_strlen( $it['mansione'] ) > 200 || mb_strlen( $it['corso'] ) > 150 ) {
			$err = 'Testo troppo lungo';
		} elseif ( '' !== $it['cf'] && false === apac_normalize_cf( $it['cf'] ) ) {
			$err = 'Codice fiscale non valido';
		} elseif ( false === $it['assunzione'] ) {
			$err = 'Data di assunzione non valida';
		} elseif ( false === $it['conseguimento'] || false === $it['scadenza'] ) {
			$err = 'Data del corso non valida (usa gg/mm/aaaa)';
		} elseif ( '' !== $it['corso'] && '' === $it['conseguimento'] ) {
			$err = 'Manca la data di conseguimento del corso';
		} elseif ( '' === $it['corso'] && ( '' !== $it['conseguimento'] || '' !== $it['scadenza'] ) ) {
			$err = 'Date del corso senza il nome del corso';
		} elseif ( '' !== $it['conseguimento'] && $it['conseguimento'] > apac_today() ) {
			$err = 'Data di conseguimento nel futuro';
		} elseif ( '' !== $it['scadenza'] && '' !== $it['conseguimento'] && $it['scadenza'] <= $it['conseguimento'] ) {
			$err = 'La scadenza è precedente al conseguimento';
		}
		if ( '' !== $it['cf'] && ! $err ) {
			$it['cf'] = (string) apac_normalize_cf( $it['cf'] );
		}
		if ( $err ) {
			$it['errore'] = $err;
			$n_err++;
			$out[] = $it;
			continue;
		}
		// Lavoratore già presente? Prima per codice fiscale, poi per cognome e nome (se univoco).
		$key = mb_strtolower( $it['cognome'] . '|' . $it['nome'] );
		if ( '' !== $it['cf'] && isset( $by_cf[ $it['cf'] ] ) ) {
			$it['lav_id'] = $by_cf[ $it['cf'] ];
		} elseif ( isset( $by_name[ $key ] ) && 1 === count( $by_name[ $key ] ) ) {
			$it['lav_id'] = $by_name[ $key ][0];
		} elseif ( isset( $by_name[ $key ] ) ) {
			$it['errore'] = 'Più lavoratori con questo nome: indica il codice fiscale';
			$n_err++;
			$out[] = $it;
			continue;
		}
		if ( $it['lav_id'] ) {
			$it['esito'] = 'esistente';
			$chi         = 'id:' . (int) $it['lav_id'];
		} else {
			$nk = '' !== $it['cf'] ? 'cf:' . $it['cf'] : 'n:' . $key;
			$it['esito'] = isset( $nuovi[ $nk ] ) ? 'stesso_nuovo' : 'nuovo';
			$nuovi[ $nk ] = true;
			$chi          = $nk;
		}
		if ( '' !== $it['corso'] ) {
			// Raggruppamento per attestato: le decisioni si prendono dopo aver letto tutto il file.
			$ak = $chi . '|' . mb_strtolower( trim( $it['corso'] ) ) . '|' . $it['conseguimento'];
			$gruppi[ $ak ][] = count( $out );
			if ( $it['lav_id'] && ! isset( $in_arch[ $ak ] ) ) {
				$in_arch[ $ak ] = array_map(
					'strval',
					(array) $wpdb->get_col( $wpdb->prepare( 'SELECT COALESCE(data_scadenza, \'\') FROM ' . apac_table( 'attestati' ) . ' WHERE azienda_id = %d AND lavoratore_id = %d AND corso = %s AND data_conseguimento = %s', $azienda_id, $it['lav_id'], $it['corso'], $it['conseguimento'] ) )
				);
			}
		}
		$out[] = $it;
	}

	$conflitti = array();
	$irrisolti = 0;
	foreach ( $gruppi as $ak => $idx ) {
		$scad  = array_values( array_unique( array_map( function ( $i ) use ( $out ) { return (string) $out[ $i ]['scadenza']; }, $idx ) ) );
		$prima = (int) $out[ $idx[0] ]['riga'];
		$arch  = isset( $in_arch[ $ak ] ) ? $in_arch[ $ak ] : array();
		if ( $arch ) {
			// Già in archivio: uguale = non si duplica; diversa = da correggere a mano (mai sovrascritto).
			foreach ( $idx as $i ) {
				if ( in_array( (string) $out[ $i ]['scadenza'], $arch, true ) ) {
					$out[ $i ]['avviso'] = 'Attestato già presente: non verrà duplicato';
				} else {
					$reg = '' !== $arch[0] ? apac_date_it( $arch[0] ) : 'senza scadenza';
					$out[ $i ]['errore'] = 'Attestato già presente con scadenza diversa (registrata: ' . $reg . '): correggila nella scheda del lavoratore';
					$n_err++;
				}
				$out[ $i ]['corso'] = '';
			}
			continue;
		}
		if ( 1 === count( $scad ) ) {
			// Duplicato esatto: si importa solo la prima riga.
			foreach ( array_slice( $idx, 1 ) as $i ) {
				$out[ $i ]['avviso'] = 'Riga ripetuta (uguale alla riga ' . $prima . '): non verrà importata di nuovo';
				$out[ $i ]['corso']  = '';
			}
			continue;
		}
		// Conflitto: stesso attestato con scadenze diverse. Una sola opzione per ciascuna scadenza (la prima
		// riga che la riporta); le righe identiche a un'opzione sono semplici duplicati.
		$cod  = substr( md5( $ak ), 0, 12 );
		$opz  = array(); // scadenza => indice della prima riga
		$rest = array();
		foreach ( $idx as $i ) {
			$k = (string) $out[ $i ]['scadenza'];
			if ( isset( $opz[ $k ] ) ) {
				$out[ $i ]['avviso'] = 'Riga ripetuta (uguale alla riga ' . (int) $out[ $opz[ $k ] ]['riga'] . '): non verrà importata di nuovo';
				$out[ $i ]['corso']  = '';
			} else {
				$opz[ $k ] = $i;
				$rest[]    = $i;
			}
		}
		$idx    = $rest;
		$righe  = array_map( function ( $i ) use ( $out ) { return (int) $out[ $i ]['riga']; }, $idx );
		$scelta = isset( $scelte[ $cod ] ) ? (int) $scelte[ $cod ] : -1;
		if ( $scelta > 0 && ! in_array( $scelta, $righe, true ) ) {
			$scelta = -1;
		}
		$conflitti[ $cod ] = $righe;
		foreach ( $idx as $i ) {
			$out[ $i ]['conflitto'] = $cod;
			if ( $scelta < 0 ) {
				$out[ $i ]['avviso']  = 'Conflitto: le righe ' . implode( ', ', $righe ) . ' riportano scadenze diverse per lo stesso attestato. Scegli quale tenere';
				$out[ $i ]['in_attesa'] = true;
			} elseif ( (int) $out[ $i ]['riga'] !== $scelta ) {
				$out[ $i ]['avviso'] = 0 === $scelta ? 'Conflitto: hai scelto di non importare questo attestato' : 'Conflitto: hai scelto la riga ' . $scelta . ', questa non verrà importata';
				$out[ $i ]['corso']  = '';
			} else {
				$out[ $i ]['avviso'] = 'Conflitto risolto: verrà importata questa riga';
			}
		}
		if ( $scelta < 0 ) {
			$irrisolti++;
		}
	}
	foreach ( $out as &$it ) {
		if ( '' === $it['errore'] && '' !== $it['corso'] && empty( $it['in_attesa'] ) ) {
			$n_att++;
			if ( '' === $it['scadenza'] && '' === $it['avviso'] ) {
				$it['avviso'] = 'Corso senza scadenza: non genererà avvisi';
			}
		}
	}
	unset( $it );
	return array(
		'righe'     => $out,
		'nuovi'     => count( $nuovi ),
		'attestati' => $n_att,
		'errori'    => $n_err,
		'conflitti' => $conflitti,
		'irrisolti' => $irrisolti,
	);
}

function apac_op_import_preview( $azienda_id ) {
	if ( empty( $_FILES['file']['name'] ) || UPLOAD_ERR_OK !== (int) $_FILES['file']['error'] || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		apac_error_field( 'file' );
		apac_redirect( 'lavoratori', 'need_field', array( 'nuovo' => 'import_preview' ) );
	}
	if ( (int) $_FILES['file']['size'] > 5 * MB_IN_BYTES ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		apac_redirect( 'lavoratori', 'import_big', array( 'nuovo' => 'import_preview' ) );
	}
	$rows = apac_import_read_rows( $_FILES['file']['tmp_name'], sanitize_file_name( wp_unslash( $_FILES['file']['name'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( is_wp_error( $rows ) ) {
		apac_redirect( 'lavoratori', $rows->get_error_code(), array( 'nuovo' => 'import_preview' ) );
	}
	if ( count( $rows ) < 2 ) {
		apac_redirect( 'lavoratori', 'import_empty', array( 'nuovo' => 'import_preview' ) );
	}
	if ( count( $rows ) > 2001 ) {
		apac_redirect( 'lavoratori', 'import_big', array( 'nuovo' => 'import_preview' ) );
	}
	$an = apac_import_analyze( $rows, $azienda_id );
	if ( is_wp_error( $an ) ) {
		apac_redirect( 'lavoratori', $an->get_error_code(), array( 'nuovo' => 'import_preview' ) );
	}
	$token = strtolower( wp_generate_password( 16, false, false ) );
	set_transient( 'apac_imp_' . get_current_user_id() . '_' . $token, array( 'azienda' => (int) $azienda_id, 'rows' => $rows ), HOUR_IN_SECONDS );
	$GLOBALS['apac_log_ctx'] = null; // l'anteprima non va nel registro
	apac_redirect( 'lavoratori', 'import_ready', array( 'import' => $token ) );
}

function apac_op_import_confirm( $azienda_id ) {
	global $wpdb;
	$token = preg_replace( '/[^a-z0-9]/', '', (string) apac_post( 'token' ) );
	$key   = 'apac_imp_' . get_current_user_id() . '_' . $token;
	$data  = get_transient( $key );
	if ( ! is_array( $data ) || (int) $data['azienda'] !== (int) $azienda_id ) {
		apac_redirect( 'lavoratori', 'import_expired' );
	}
	// 1.9.9: scelte fatte nell'anteprima per i conflitti (codice => riga da tenere, 0 = nessuna).
	$scelte = array();
	$raw    = isset( $_POST['scelta'] ) && is_array( $_POST['scelta'] ) ? wp_unslash( $_POST['scelta'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification
	foreach ( $raw as $cod => $riga ) {
		$cod = preg_replace( '/[^a-f0-9]/', '', (string) $cod );
		if ( '' !== $cod && is_scalar( $riga ) && '' !== (string) $riga ) {
			$scelte[ $cod ] = absint( $riga );
		}
	}
	$an = apac_import_analyze( $data['rows'], $azienda_id, $scelte );
	if ( is_wp_error( $an ) ) {
		apac_redirect( 'lavoratori', $an->get_error_code() );
	}
	if ( ! empty( $an['irrisolti'] ) ) {
		apac_redirect( 'lavoratori', 'import_conflict', array( 'import' => $token ) );
	}
	$creati  = array();
	$n_lav   = 0;
	$n_att   = 0;
	foreach ( $an['righe'] as $it ) {
		if ( '' !== $it['errore'] ) {
			continue;
		}
		$lav_id = (int) $it['lav_id'];
		if ( ! $lav_id ) {
			$nk = '' !== $it['cf'] ? 'cf:' . $it['cf'] : 'n:' . mb_strtolower( $it['cognome'] . '|' . $it['nome'] );
			if ( isset( $creati[ $nk ] ) ) {
				$lav_id = $creati[ $nk ];
			} else {
				$lav_id = apac_must_insert(
					'lavoratori',
					array(
						'azienda_id'      => $azienda_id,
						'nome'            => $it['nome'],
						'cognome'         => $it['cognome'],
						'codice_fiscale'  => $it['cf'],
						'mansione'        => $it['mansione'],
						'data_assunzione' => '' !== $it['assunzione'] ? $it['assunzione'] : null,
						'attivo'          => 1,
						'note'            => '',
						'created_at'      => apac_now(),
					),
					'lavoratori'
				);
				$creati[ $nk ] = $lav_id;
				$n_lav++;
			}
		}
		if ( '' !== $it['corso'] ) {
			apac_must_insert(
				'attestati',
				array(
					'azienda_id'         => $azienda_id,
					'lavoratore_id'      => $lav_id,
					'corso'              => $it['corso'],
					'data_conseguimento' => $it['conseguimento'],
					'data_scadenza'      => '' !== $it['scadenza'] ? $it['scadenza'] : null,
					'note'               => $it['note'],
					'created_at'         => apac_now(),
				),
				'lavoratori'
			);
			$n_att++;
		}
	}
	delete_transient( $key );
	apac_log_detail( apac_n( $n_lav, 'lavoratore', 'lavoratori' ) . ', ' . apac_n( $n_att, 'attestato', 'attestati' ) );
	apac_redirect( 'lavoratori', 'imported', array( 'n_lav' => $n_lav, 'n_att' => $n_att ) );
}

/** Anteprima dell'importazione (pagina Lavoratori con ?import=codice). */
function apac_render_import_box( $az ) {
	$id    = (int) $az->id;
	$token = isset( $_GET['import'] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( sanitize_text_field( wp_unslash( $_GET['import'] ) ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$data  = '' !== $token ? get_transient( 'apac_imp_' . get_current_user_id() . '_' . $token ) : false;
	if ( is_array( $data ) && (int) $data['azienda'] === $id ) {
		apac_render_import_preview( $az, $token, apac_import_analyze( $data['rows'], $id ) );
		return;
	}
	echo '<div class="apac-notice apac-notice--warn" role="alert">L’anteprima dell’importazione è scaduta: carica di nuovo il file.</div>';
	echo '<p><a class="apac-btn apac-btn--ghost" href="' . esc_url( apac_url( 'lavoratori', array( 'nuovo' => 'import_preview' ) ) . '#apac-excel' ) . '">Torna all’importazione</a></p>';
}

/** "Excel: importa o esporta" – strumenti secondari, chiusi per non affollare la pagina. */
function apac_render_excel_tools( $az, $can_import, $has_data ) {
	$id   = (int) $az->id;
	$req  = isset( $_GET['nuovo'] ) ? sanitize_key( wp_unslash( $_GET['nuovo'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$open = 'import_preview' === $req || '' !== apac_details_open( 'import_preview', '' );
	if ( ! $can_import && ! $has_data ) {
		return;
	}
	// phpcs:disable WordPress.Security.EscapeOutput
	echo '<details class="apac-tools" id="apac-excel"' . ( $open ? ' open' : '' ) . '><summary>' . apac_icon( 'file' ) . '<span>' . ( $can_import ? 'Excel: importa o esporta' : 'Esporta in Excel' ) . '</span></summary><div class="apac-tools__body">';
	if ( $has_data ) {
		echo '<div class="apac-tools__row"><div><strong>Esporta</strong><small>Lavoratori, attestati e scadenze in un unico file.</small></div><div class="apac-tools__btns"><a class="apac-btn apac-btn--small" href="' . esc_url( apac_export_url( $id, 'xlsx' ) ) . '">' . apac_icon( 'download' ) . '<span>Excel</span></a><a class="apac-btn apac-btn--small apac-btn--ghost" href="' . esc_url( apac_export_url( $id, 'csv' ) ) . '">CSV</a></div></div>';
	}
	if ( $can_import ) {
		$tpl = wp_nonce_url( add_query_arg( 'apac_template', '1', apac_portal_page_url() ), 'apac_template' );
		echo '<div class="apac-tools__row apac-tools__row--col"><div><strong>Importa</strong><small>Carica in una volta lavoratori e attestati da un foglio Excel.</small></div>';
		echo '<ol class="apac-howto"><li>Scarica il <a href="' . esc_url( $tpl ) . '">modello</a> e compilalo: una riga per ogni corso.</li><li>Carica il file (.xlsx o .csv): massimo 2000 righe e 5 MB (o il limite inferiore indicato sotto).</li><li>Controlla l’anteprima: nulla viene salvato finché non confermi.</li></ol>';
		echo apac_form_open( 'import_preview', $id, true ) . '<div class="apac-fields">';
		echo apac_file_field( 'File Excel o CSV *', '.xlsx,.csv', true, true, 5 * MB_IN_BYTES );
		echo '</div><div class="apac-actions"><button class="apac-btn" type="submit">' . apac_icon( 'upload' ) . '<span>Mostra anteprima</span></button></div></form></div>';
	}
	echo '</div></details>';
	// phpcs:enable
}

/** Versioni precedenti di un documento (sostituzioni del file). */
function apac_render_versioni( $d ) {
	$vers = apac_get_versioni( (int) $d->id, (int) $d->azienda_id );
	if ( ! $vers ) {
		return;
	}
	echo '<details class="apac-edit apac-versions"><summary class="apac-btn apac-btn--small apac-btn--ghost">' . apac_icon( 'history' ) . '<span>Versioni (' . count( $vers ) . ')</span></summary><ul class="apac-versions__list">'; // phpcs:ignore WordPress.Security.EscapeOutput
	foreach ( $vers as $v ) {
		echo '<li><span>' . esc_html( $v->nome_file ) . '<small>Caricata il ' . esc_html( apac_date_it( $v->created_at ) ) . ' · sostituita il ' . esc_html( apac_date_it( $v->sostituito_at ) ) . '</small></span><a class="apac-btn apac-btn--small apac-btn--ghost" href="' . esc_url( apac_extra_file_url( 'ver', $v->id, 'download' ) ) . '">Scarica</a></li>';
	}
	echo '</ul></details>';
}

/** Campo per allegare fino a 3 foto alla segnalazione (sul telefono apre anche la fotocamera). */
function apac_foto_field() {
	list( $cls, $aria, $msg ) = apac_field_error_parts( 'foto' );
	return '<label class="apac-field apac-field--wide apac-foto' . $cls . '"><span>' . apac_icon( 'camera' ) . 'Foto (facoltative, massimo 3)</span><input type="file" name="foto[]" accept="image/jpeg,image/png" multiple data-apac-max="3"' . $aria . '><small>Formato JPG o PNG. Non fotografare persone riconoscibili se non serve.</small>' . $msg . '</label>';
}

function apac_render_foto( $seg ) {
	$foto = apac_get_foto( (int) $seg->id, (int) $seg->azienda_id );
	if ( ! $foto ) {
		return;
	}
	echo '<div class="apac-photos">';
	foreach ( $foto as $f ) {
		echo '<a href="' . esc_url( apac_extra_file_url( 'foto', $f->id, 'view' ) ) . '" target="_blank" rel="noopener"><img src="' . esc_url( apac_extra_file_url( 'foto', $f->id, 'view' ) ) . '" alt="' . esc_attr( 'Foto allegata: ' . $f->nome_file ) . '" loading="lazy" width="96" height="96"></a>';
	}
	echo '</div>';
}

function apac_render_import_preview( $az, $token, $an ) {
	if ( is_wp_error( $an ) ) {
		echo '<div class="apac-notice apac-notice--error" role="alert">Il file non ha le colonne Cognome e Nome.</div>';
		return;
	}
	$valide = count( $an['righe'] ) - $an['errori'];
	echo '<section class="apac-card apac-import"><h2>Anteprima importazione</h2>';
	$nn = (int) $an['nuovi'];
	$na = (int) $an['attestati'];
	$ne = (int) $an['errori'];
	$nc = ! empty( $an['conflitti'] ) ? count( $an['conflitti'] ) : 0;
	echo '<div class="apac-import__sum"><span><b>' . $nn . '</b> ' . ( 1 === $nn ? 'nuovo lavoratore' : 'nuovi lavoratori' ) . '</span><span><b>' . $na . '</b> ' . ( 1 === $na ? 'attestato' : 'attestati' ) . '</span><span class="' . ( $ne ? 'is-bad' : '' ) . '"><b>' . $ne . '</b> ' . ( 1 === $ne ? 'riga con errori' : 'righe con errori' ) . '</span>' . ( $nc ? '<span class="is-bad"><b>' . $nc . '</b> ' . ( 1 === $nc ? 'conflitto da risolvere' : 'conflitti da risolvere' ) . '</span>' : '' ) . '</div>';
	if ( $an['errori'] ) {
		echo '<p class="apac-muted">Le righe con errori <strong>non</strong> verranno importate: correggile nel file e ricaricalo, oppure importa solo quelle valide.</p>';
	}
	echo '<div class="apac-table-wrap apac-import__table"><table class="apac-table"><thead><tr><th>Riga</th><th>Lavoratore</th><th>Corso</th><th>Esito</th></tr></thead><tbody>';
	foreach ( $an['righe'] as $it ) {
		$esito = '' !== $it['errore'] ? '<span class="apac-badge apac-badge--danger">' . esc_html( $it['errore'] ) . '</span>'
			: ( 'esistente' === $it['esito'] ? '<span class="apac-badge apac-badge--muted">Lavoratore già presente</span>' : ( 'nuovo' === $it['esito'] ? '<span class="apac-badge apac-badge--ok">Nuovo lavoratore</span>' : '<span class="apac-badge apac-badge--muted">Stesso lavoratore della riga sopra</span>' ) );
		if ( '' !== $it['avviso'] ) {
			$esito .= ' <span class="apac-badge apac-badge--warn">' . esc_html( $it['avviso'] ) . '</span>';
		}
		$corso = '' !== $it['corso'] ? esc_html( $it['corso'] ) . '<br><small>' . esc_html( $it['conseguimento'] ? apac_date_it( $it['conseguimento'] ) : '' ) . ( $it['scadenza'] ? ' → ' . esc_html( apac_date_it( $it['scadenza'] ) ) : '' ) . '</small>' : '—';
		echo '<tr' . ( '' !== $it['errore'] ? ' class="is-error"' : '' ) . '><td data-label="Riga">Riga ' . (int) $it['riga'] . '</td><td data-label="Lavoratore"><strong>' . esc_html( $it['cognome'] . ' ' . $it['nome'] ) . '</strong>' . ( $it['mansione'] ? '<br><small>' . esc_html( $it['mansione'] ) . '</small>' : '' ) . '</td><td data-label="Corso">' . $corso . '</td><td data-label="Esito">' . $esito . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</tbody></table></div>';
	$conf = ! empty( $an['conflitti'] ) ? $an['conflitti'] : array();
	if ( $valide > 0 && ( $an['nuovi'] || $an['attestati'] || $conf ) ) {
		echo apac_form_open( 'import_confirm', (int) $az->id ) . apac_hidden( 'token', $token ); // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $conf ) {
			// 1.9.9: per ogni conflitto si sceglie quale riga tenere (obbligatorio prima di confermare).
			$per_riga = array();
			foreach ( $an['righe'] as $it ) {
				$per_riga[ (int) $it['riga'] ] = $it;
			}
			echo '<div class="apac-import__conflicts"><h3>Scegli quale riga tenere</h3><p class="apac-muted">Per questi attestati il file riporta scadenze diverse. Indica quale riga è corretta: le altre non verranno importate.</p>';
			foreach ( $conf as $cod => $righe ) {
				$r0 = $per_riga[ $righe[0] ];
				echo '<fieldset class="apac-field apac-field--wide"><legend>' . esc_html( $r0['cognome'] . ' ' . $r0['nome'] . ' – ' . trim( $r0['corso'] ) . ' del ' . apac_date_it( $r0['conseguimento'] ) ) . ' *</legend>';
				foreach ( $righe as $n ) {
					$sc = (string) $per_riga[ $n ]['scadenza'];
					echo '<label class="apac-radio"><input type="radio" name="scelta[' . esc_attr( $cod ) . ']" value="' . (int) $n . '" required> Tieni la riga ' . (int) $n . ' (scadenza ' . esc_html( '' !== $sc ? apac_date_it( $sc ) : 'non indicata' ) . ')</label>';
				}
				echo '<label class="apac-radio"><input type="radio" name="scelta[' . esc_attr( $cod ) . ']" value="0" required> Non importare questo attestato</label></fieldset>';
			}
			echo '</div>';
		}
		echo '<div class="apac-actions"><button class="apac-btn" type="submit">' . apac_icon( 'check' ) . '<span>Conferma importazione</span></button><a class="apac-btn apac-btn--ghost" href="' . esc_url( apac_url( 'lavoratori' ) ) . '">Annulla</a></div></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
	} else {
		echo '<p class="apac-muted">Non c’è nulla da importare.</p><p><a class="apac-btn apac-btn--ghost" href="' . esc_url( apac_url( 'lavoratori' ) ) . '">Torna ai lavoratori</a></p>';
	}
	echo '</section>';
}

/* ============================ Modello ed esportazione ============================ */

add_action( 'init', 'apac_serve_downloads', 19 );
function apac_serve_downloads() {
	if ( is_admin() ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification
	if ( isset( $_GET['apac_template'] ) ) {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! is_user_logged_in() || ! wp_verify_nonce( $nonce, 'apac_template' ) ) {
			wp_die( 'Link non valido o scaduto.', 'Accesso negato', array( 'response' => 403 ) );
		}
		apac_send_csv(
			'modello-importazione-lavoratori.csv',
			array(
				array( 'Cognome', 'Nome', 'Codice fiscale', 'Mansione', 'Data assunzione', 'Corso', 'Data conseguimento', 'Data scadenza', 'Note' ),
				array( 'Rossi', 'Mario', '', 'Muratore', '15/01/2024', 'Formazione generale lavoratori', '10/02/2024', '', '' ),
				array( 'Rossi', 'Mario', '', 'Muratore', '15/01/2024', 'Formazione specifica rischio alto', '20/02/2024', '20/02/2029', '' ),
			)
		);
	}
	if ( isset( $_GET['apac_export'] ) ) {
		$az_id = apac_is_admin() ? absint( $_GET['azienda'] ?? 0 ) : apac_user_azienda_id();
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! is_user_logged_in() || ! apac_user_can_use_portal() || ! wp_verify_nonce( $nonce, 'apac_export_' . $az_id ) || ! apac_can_access_azienda( $az_id ) ) {
			wp_die( 'Link non valido o scaduto.', 'Accesso negato', array( 'response' => 403 ) );
		}
		apac_require_consents_for_download();
		apac_export_azienda( $az_id, 'csv' === $_GET['apac_export'] ? 'csv' : 'xlsx' );
	}
	// phpcs:enable
}

function apac_export_url( $azienda_id, $format = 'xlsx' ) {
	$args = array( 'apac_export' => $format );
	if ( apac_is_admin() ) {
		$args['azienda'] = (int) $azienda_id;
	}
	return wp_nonce_url( add_query_arg( $args, apac_portal_page_url() ), 'apac_export_' . (int) $azienda_id );
}

/** Un valore di testo non deve diventare formula nei programmi di calcolo. */
function apac_csv_safe( $v ) {
	$v = (string) $v;
	return ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $v : $v;
}

/**
 * 1.9.12: intestazione di download con nome sicuro per tutti i browser. Il nome ASCII evita caratteri
 * illeggibili (es. "–" mostrato come "â"); filename* conserva il nome originale dove supportato.
 */
function apac_download_header( $filename, $mode = 'attachment' ) {
	$filename = str_replace( array( '"', "\r", "\n", '/', '\\' ), '', (string) $filename );
	$ascii    = function_exists( 'remove_accents' ) ? remove_accents( $filename ) : $filename;
	$ascii    = trim( preg_replace( '/-{2,}/', '-', preg_replace( '/[^A-Za-z0-9._-]+/', '-', $ascii ) ), '-' );
	if ( '' === $ascii ) {
		$ascii = 'download';
	}
	header( 'Content-Disposition: ' . $mode . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
}

function apac_send_csv( $filename, $rows ) {
	while ( ob_get_level() ) {
		ob_end_clean();
	}
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	apac_download_header( $filename );
	header( 'X-Content-Type-Options: nosniff' );
	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	foreach ( $rows as $r ) {
		fputcsv( $out, array_map( 'apac_csv_safe', $r ), ';', '"', '\\' );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}

/** Dati dell'azienda in tre tabelle: lavoratori, attestati, scadenze. */
function apac_export_data( $azienda_id ) {
	global $wpdb;
	$cats = apac_categorie_scadenze();
	$lav  = array( array( 'Cognome', 'Nome', 'Codice fiscale', 'Mansione', 'Data assunzione', 'Stato', 'Note' ) );
	foreach ( apac_get_lavoratori( $azienda_id ) as $l ) {
		$lav[] = array( $l->cognome, $l->nome, $l->codice_fiscale, $l->mansione, $l->data_assunzione ? apac_date_it( $l->data_assunzione ) : '', $l->attivo ? 'In forza' : 'Cessato', (string) $l->note );
	}
	$att  = array( array( 'Cognome', 'Nome', 'Corso', 'Data conseguimento', 'Data scadenza', 'Stato', 'Note' ) );
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT a.*, l.nome, l.cognome, (' . apac_sql_attestato_corrente( 'a' ) . ') AS corrente FROM ' . apac_table( 'attestati' ) . ' a INNER JOIN ' . apac_table( 'lavoratori' ) . ' l ON l.id = a.lavoratore_id WHERE a.azienda_id = %d ORDER BY l.cognome, l.nome, a.corso, a.data_conseguimento DESC', $azienda_id ) );
	foreach ( $rows as $a ) {
		if ( ! (int) $a->corrente ) {
			$stato = 'Rinnovato';
		} elseif ( ! $a->data_scadenza ) {
			$stato = 'Senza scadenza';
		} else {
			$stato = apac_days_until( $a->data_scadenza ) < 0 ? 'Scaduto' : 'Valido';
		}
		$att[] = array( $a->cognome, $a->nome, $a->corso, apac_date_it( $a->data_conseguimento ), $a->data_scadenza ? apac_date_it( $a->data_scadenza ) : '', $stato, (string) $a->note );
	}
	$sca  = array( array( 'Adempimento', 'Categoria', 'Data scadenza', 'Stato', 'Lavoratore', 'Ricorrenza', 'Note' ) );
	$ric  = apac_ricorrenze();
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT s.*, l.nome, l.cognome FROM ' . apac_table( 'scadenze' ) . ' s LEFT JOIN ' . apac_table( 'lavoratori' ) . ' l ON l.id = s.lavoratore_id WHERE s.azienda_id = %d ORDER BY s.data_scadenza', $azienda_id ) );
	foreach ( $rows as $s ) {
		$sca[] = array( $s->titolo, isset( $cats[ $s->categoria ] ) ? $cats[ $s->categoria ] : $s->categoria, apac_date_it( $s->data_scadenza ), 'completata' === $s->stato ? 'Completato' : ( apac_days_until( $s->data_scadenza ) < 0 ? 'Superata' : 'Da fare' ), $s->cognome ? $s->cognome . ' ' . $s->nome : '', isset( $ric[ (int) $s->ricorrenza_mesi ] ) ? $ric[ (int) $s->ricorrenza_mesi ] : '', (string) $s->note );
	}
	return array(
		'Lavoratori' => $lav,
		'Attestati'  => $att,
		'Scadenze'   => $sca,
	);
}

function apac_export_azienda( $azienda_id, $format ) {
	$az    = apac_get_azienda( $azienda_id );
	$data  = apac_export_data( $azienda_id );
	$base  = sanitize_file_name( remove_accents( 'sicurezza-' . ( $az ? $az->ragione_sociale : 'azienda' ) . '-' . apac_today() ) );
	$base  = trim( preg_replace( '/-{2,}/', '-', preg_replace( '/[^A-Za-z0-9._-]+/', '-', $base ) ), '-' );
	if ( 'csv' === $format || ! class_exists( 'ZipArchive' ) ) {
		$rows = array();
		foreach ( $data as $title => $t ) {
			$rows[] = array( strtoupper( $title ) );
			foreach ( $t as $r ) {
				$rows[] = $r;
			}
			$rows[] = array();
		}
		apac_send_csv( $base . '.csv', $rows );
	}
	$tmp = tempnam( get_temp_dir(), 'apac-export' );
	if ( ! apac_write_xlsx( $tmp, $data ) ) {
		wp_die( 'Esportazione non riuscita. Riprova.', 'Errore', array( 'response' => 500 ) );
	}
	while ( ob_get_level() ) {
		ob_end_clean();
	}
	nocache_headers();
	header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
	apac_download_header( $base . '.xlsx' );
	header( 'Content-Length: ' . filesize( $tmp ) );
	header( 'X-Content-Type-Options: nosniff' );
	readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	wp_delete_file( $tmp );
	exit;
}

/** File Excel minimo (un foglio per tabella, intestazione in grassetto, colonne larghe). */
function apac_write_xlsx( $path, $sheets ) {
	$zip = new ZipArchive();
	if ( true !== $zip->open( $path, ZipArchive::OVERWRITE | ZipArchive::CREATE ) ) {
		return false;
	}
	$x   = function ( $s ) {
		return htmlspecialchars( preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $s ), ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	};
	$col = function ( $i ) {
		$s = '';
		for ( $i++; $i > 0; $i = intdiv( $i - 1, 26 ) ) {
			$s = chr( 65 + ( ( $i - 1 ) % 26 ) ) . $s;
		}
		return $s;
	};
	$n         = count( $sheets );
	$ct        = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
	$wb_sheets = '';
	$wb_rels   = '';
	$i         = 0;
	foreach ( $sheets as $title => $rows ) {
		$i++;
		$ct        .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		$wb_sheets .= '<sheet name="' . $x( mb_substr( $title, 0, 31 ) ) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
		$wb_rels   .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
		$widths     = array();
		foreach ( $rows as $r ) {
			foreach ( $r as $c => $v ) {
				$widths[ $c ] = min( 60, max( $widths[ $c ] ?? 8, mb_strlen( (string) $v ) + 2 ) );
			}
		}
		$cols = '<cols>';
		foreach ( $widths as $c => $w ) {
			$cols .= '<col min="' . ( $c + 1 ) . '" max="' . ( $c + 1 ) . '" width="' . $w . '" customWidth="1"/>';
		}
		$cols .= '</cols>';
		$data  = '';
		foreach ( array_values( $rows ) as $ri => $r ) {
			$data .= '<row r="' . ( $ri + 1 ) . '">';
			foreach ( array_values( $r ) as $ci => $v ) {
				// 1.9.12: le date gg/mm/aaaa diventano date vere di Excel (ordinabili e filtrabili), formato gg/mm/aaaa.
				if ( $ri > 0 && preg_match( '/^(\d{2})\/(\d{2})\/(\d{4})$/', (string) $v, $dm ) && checkdate( (int) $dm[2], (int) $dm[1], (int) $dm[3] ) ) {
					$serial = (int) floor( ( gmmktime( 0, 0, 0, (int) $dm[2], (int) $dm[1], (int) $dm[3] ) - gmmktime( 0, 0, 0, 12, 30, 1899 ) ) / 86400 );
					$data  .= '<c r="' . $col( $ci ) . ( $ri + 1 ) . '" s="2"><v>' . $serial . '</v></c>';
					continue;
				}
				$data .= '<c r="' . $col( $ci ) . ( $ri + 1 ) . '" t="inlineStr"' . ( 0 === $ri ? ' s="1"' : '' ) . '><is><t xml:space="preserve">' . $x( $v ) . '</t></is></c>';
			}
			$data .= '</row>';
		}
		$zip->addFromString( 'xl/worksheets/sheet' . $i . '.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' . $cols . '<sheetData>' . $data . '</sheetData></worksheet>' );
	}
	$ct .= '</Types>';
	$zip->addFromString( '[Content_Types].xml', $ct );
	$zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>' );
	$zip->addFromString( 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wb_sheets . '</sheets></workbook>' );
	$zip->addFromString( 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wb_rels . '<Relationship Id="rId' . ( $n + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>' );
	$zip->addFromString( 'xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>' );
	return $zip->close();
}
