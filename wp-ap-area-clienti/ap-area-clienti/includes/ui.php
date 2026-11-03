<?php
defined( 'ABSPATH' ) || exit;

/*
 * Interfaccia dell'area riservata (1.6.0): testata compatta, menu con icone,
 * barra di navigazione in basso sul telefono, intestazioni di sezione.
 */

/** Icone a tratto (24x24, colore del testo). */
function apac_icon( $name ) {
	$p = array(
		'home'     => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h5v-6h4v6h5V9.5"/>',
		'building' => '<rect x="4" y="3" width="16" height="18" rx="1.5"/><path d="M8 7h2M14 7h2M8 11h2M14 11h2M8 15h2M14 15h2M10 21v-3h4v3"/>',
		'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.8c2 .7 3.2 2.4 3.5 5.2"/>',
		'file'     => '<path d="M14 3H6.5A1.5 1.5 0 0 0 5 4.5v15A1.5 1.5 0 0 0 6.5 21h11a1.5 1.5 0 0 0 1.5-1.5V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
		'calendar' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4M8 14h2M14 14h2"/>',
		'message'  => '<path d="M4 5h16v11H9l-5 4z"/><path d="M8 9.5h8M8 12.5h5"/>',
		'alert'    => '<path d="M12 3.5 2.5 20h19z"/><path d="M12 10v4.5M12 17.2v.3"/>',
		'studio'   => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 5v2M3 12.5h18"/>',
		'key'      => '<circle cx="8" cy="15" r="4"/><path d="m11 12 8.5-8.5M16 7l2.5 2.5M14 9l2 2"/>',
		'more'     => '<circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>',
		'logout'   => '<path d="M15 4h3.5A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5H15"/><path d="M10 8l-4 4 4 4M6 12h10"/>',
		'plus'     => '<path d="M12 5v14M5 12h14"/>',
		'check'    => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
		'upload'   => '<path d="M12 16V4M7 9l5-5 5 5M4 16v3.5A1.5 1.5 0 0 0 5.5 21h13a1.5 1.5 0 0 0 1.5-1.5V16"/>',
		'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
		'search'   => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m15.5 15.5 5 5"/>',
		'filter'   => '<path d="M4 6h16M7 12h10M10 18h4"/>',
		'download' => '<path d="M12 4v12M7 11l5 5 5-5M4 16v3.5A1.5 1.5 0 0 0 5.5 21h13a1.5 1.5 0 0 0 1.5-1.5V16"/>',
		'camera'   => '<path d="M4 8.5A1.5 1.5 0 0 1 5.5 7h2.2L9 5h6l1.3 2h2.2A1.5 1.5 0 0 1 20 8.5v9a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 17.5z"/><circle cx="12" cy="13" r="3.3"/>',
		'history'  => '<path d="M4 12a8 8 0 1 0 2.4-5.7L4 8.5"/><path d="M4 4v4.5h4.5M12 8v4.5l3 1.8"/>',
		'repeat'   => '<path d="M17 3l3 3-3 3"/><path d="M4 11V9a3 3 0 0 1 3-3h13M7 21l-3-3 3-3"/><path d="M20 13v2a3 3 0 0 1-3 3H4"/>',
	);
	if ( ! isset( $p[ $name ] ) ) {
		return '';
	}
	return '<svg class="apac-ico" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $p[ $name ] . '</svg>';
}

/** Icona, etichetta breve (barra in basso) e spiegazione di ogni sezione. */
function apac_section_meta() {
	return array(
		'pannello'     => array( 'studio', 'Aziende', 'Situazione di tutte le aziende clienti: scadenze, segnalazioni e criticità.' ),
		'dashboard'    => array( 'home', 'Riepilogo', 'Cosa è scaduto, cosa scade a breve e cosa manca.' ),
		'azienda'      => array( 'building', 'Dati', 'Dati dell’azienda e figure della sicurezza (datore di lavoro, RSPP, RLS, medico competente, addetti alle emergenze).' ),
		'lavoratori'   => array( 'users', 'Lavoratori', 'Elenco dei lavoratori. Apri un nominativo per vedere e registrare i suoi attestati di formazione.' ),
		'documenti'    => array( 'file', 'Documenti', 'Archivio dei documenti della sicurezza: DVR, nomine, verbali, piani di emergenza e altro.' ),
		'scadenziario' => array( 'calendar', 'Scadenze', 'Adempimenti da rispettare (verifiche, visite, riunioni) e scadenze della formazione.' ),
		'segnalazioni' => array( 'message', 'Segnalazioni', 'Scrivi allo studio per un problema, una richiesta o un dubbio, e leggi le risposte.' ),
		'criticita'    => array( 'alert', 'Criticità', 'Controllo automatico dei dati inseriti: segnala informazioni mancanti o scadute.' ),
		'cerca'        => array( 'search', 'Cerca', 'Trova lavoratori, attestati, documenti e scadenze scrivendo una parola.' ),
		'richieste'    => array( 'bell', 'Richieste', 'Richieste di accesso arrivate dal sito o dai clienti: verifica, approva o rifiuta.' ),
		'utenti'       => array( 'key', 'Accessi', 'Aziende clienti e account di accesso all’area riservata.' ),
		'installa'     => array( 'phone', 'App', 'Link, istruzioni e QR da inviare ai clienti per installare l’app sul telefono.' ),
		'elenco'       => array( 'filter', 'Elenco', 'Voci di tutte le aziende con il filtro scelto.' ),
	);
}

/** Numero di scadenze superate (scadenzario + formazione) per il pallino rosso nel menu. */
function apac_overdue_count( $azienda_id ) {
	static $cache = array();
	if ( ! $azienda_id ) {
		return 0;
	}
	if ( ! isset( $cache[ $azienda_id ] ) ) {
		$n     = 0;
		$today = apac_today();
		foreach ( apac_agenda( $azienda_id, 0 ) as $i ) {
			if ( $i['data'] < $today ) {
				$n++;
			}
		}
		$cache[ $azienda_id ] = $n;
	}
	return $cache[ $azienda_id ];
}

/** Testata dell'area riservata: logo, sezione e titolo, azione principale e menu account. */
function apac_render_topbar( $titolo, $kicker, $user, $azienda_id = 0, $sez = '' ) {
	$home = apac_is_admin() ? apac_url( 'pannello', array( 'azienda' => 0 ) ) : apac_url( 'dashboard' );
	echo '<section class="apac-top"><div class="aps-wrap apac-top__in">';
	echo '<a class="apac-top__logo" href="' . esc_url( $home ) . '" aria-label="AP Sicurezza Lavoro – inizio area riservata"><img src="' . esc_url( apac_logo_url() ) . '" alt="" width="34" height="40"></a>';
	echo '<div class="apac-top__title"><span class="apac-top__kicker">' . esc_html( $kicker ) . '</span>';
	echo '<h1 class="apac-title" title="' . esc_attr( $titolo ) . '">' . esc_html( $titolo ) . '</h1></div>';
	echo '<div class="apac-top__user">';
	apac_render_addmenu( $azienda_id, $sez );
	apac_render_account_menu( $user );
	echo '</div></div></section>';
}

/** Voce di menu. */
function apac_nav_link( $key, $label, $sez, $azienda_id, $class = '' ) {
	$meta = apac_section_meta();
	$icon = isset( $meta[ $key ] ) ? $meta[ $key ][0] : 'more';
	$cur  = $key === $sez;
	$dot  = '';
	if ( 'richieste' === $key && function_exists( 'apac_rich_count_attesa' ) ) {
		$n = apac_rich_count_attesa();
		if ( $n ) {
			$dot = '<b class="apac-dot" title="' . esc_attr( $n . ( 1 === $n ? ' richiesta da esaminare' : ' richieste da esaminare' ) ) . '">' . (int) $n . '</b>';
		}
	}
	if ( 'scadenziario' === $key ) {
		$n = apac_overdue_count( $azienda_id );
		if ( $n ) {
			/* translators: numero di scadenze superate */
			$dot = '<b class="apac-dot" title="' . esc_attr( $n . ( 1 === $n ? ' scadenza superata' : ' scadenze superate' ) ) . '">' . (int) $n . '</b>';
		}
	}
	$url = in_array( $key, array( 'pannello', 'richieste', 'utenti', 'installa' ), true ) ? apac_url( $key, array( 'azienda' => $azienda_id ) ) : apac_url( $key );
	return '<a class="' . esc_attr( trim( $class . ( $cur ? ' is-active' : '' ) ) ) . '"' . ( $cur ? ' aria-current="page"' : '' ) . ' href="' . esc_url( $url ) . '">' . apac_icon_extra( $icon ) . '<span>' . esc_html( $label ) . '</span>' . $dot . '</a>';
}

/** Menu laterale (computer e tablet orizzontale), diviso in gruppi. */
function apac_render_sidenav( $sections, $sez, $azienda, $azienda_id ) {
	echo '<nav class="apac-nav" aria-label="Sezioni area riservata">';
	if ( apac_is_admin() ) {
		echo '<p class="apac-nav__group">Studio</p>';
		foreach ( array( 'pannello', 'richieste', 'utenti', 'installa' ) as $k ) {
			echo apac_nav_link( $k, $sections[ $k ], $sez, $azienda_id ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<a class="apac-nav__wp" href="' . esc_url( admin_url() ) . '">' . apac_icon_extra( 'gear' ) . '<span>Bacheca WordPress</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="apac-nav__group">' . ( $azienda ? esc_html( $azienda->ragione_sociale ) : 'Azienda (da scegliere)' ) . '</p>';
	}
	foreach ( $sections as $k => $label ) {
		if ( in_array( $k, apac_studio_sections(), true ) ) {
			continue;
		}
		echo apac_nav_link( $k, $label, $sez, $azienda_id ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</nav>';
}

/**
 * Barra in basso sul telefono: 4 sezioni principali + "Altro".
 * "Altro" è un <details>: funziona anche senza JavaScript.
 */
function apac_render_tabbar( $sections, $sez, $azienda_id ) {
	$meta    = apac_section_meta();
	$primary = apac_is_admin() ? array( 'pannello', 'dashboard', 'lavoratori', 'scadenziario' ) : array( 'dashboard', 'lavoratori', 'documenti', 'scadenziario' );
	$others  = array_diff( array_keys( $sections ), $primary, array( 'elenco' ) );
	$in_more = in_array( $sez, $others, true );
	$meta['installa'][1] = 'Installa l’app';
	echo '<nav class="apac-tabbar" aria-label="Menu rapido">';
	foreach ( $primary as $k ) {
		$short = $meta[ $k ][1];
		echo apac_nav_link( $k, $short, $sez, $azienda_id, 'apac-tab' ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	// 1.9.9: voci raggruppate (questa azienda / studio / account) invece di un elenco unico.
	echo '<details class="apac-more"><summary class="apac-tab' . ( $in_more ? ' is-active' : '' ) . '">' . apac_icon( 'more' ) . '<span>Altro</span></summary><div class="apac-more__sheet">'; // phpcs:ignore WordPress.Security.EscapeOutput
	$studio  = array_values( array_intersect( $others, apac_studio_sections() ) );
	$azienda = array_values( array_diff( $others, $studio ) );
	// Più usate per prime.
	$ordine = array( 'documenti', 'azienda', 'segnalazioni', 'criticita', 'cerca' );
	usort( $azienda, function ( $a, $b ) use ( $ordine ) { return ( false === ( $x = array_search( $a, $ordine, true ) ) ? 99 : $x ) - ( false === ( $y = array_search( $b, $ordine, true ) ) ? 99 : $y ); } );
	if ( $azienda ) {
		$az_row = apac_is_admin() && $azienda_id ? apac_get_azienda( $azienda_id ) : null;
		echo '<p class="apac-more__title">' . esc_html( apac_is_admin() ? ( $az_row ? 'Azienda: ' . $az_row->ragione_sociale : 'Azienda selezionata' ) : 'Altre sezioni' ) . '</p>';
		foreach ( $azienda as $k ) {
			echo apac_nav_link( $k, $sections[ $k ], $sez, $azienda_id, 'apac-more__link' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}
	if ( $studio ) {
		echo '<p class="apac-more__title">Studio</p>';
		foreach ( $studio as $k ) {
			echo apac_nav_link( $k, $sections[ $k ], $sez, $azienda_id, 'apac-more__link' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}
	echo '<p class="apac-more__title">Account</p>';
	echo '<details class="apac-more__prefs"><summary class="apac-more__link">' . apac_icon_extra( 'contrast' ) . '<span>Aspetto e notifiche</span></summary>' . apac_render_prefs( 'apac-prefs-more' ) . apac_render_push_box( 'apac-push-more' ) . '</details>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<a class="apac-more__link apac-more__link--muted" href="' . esc_url( home_url( '/' ) ) . '">' . apac_icon( 'home' ) . '<span>Vai al sito</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
	if ( apac_is_admin() ) {
		echo '<a class="apac-more__link apac-more__link--muted" href="' . esc_url( admin_url() ) . '">' . apac_icon_extra( 'gear' ) . '<span>Bacheca WordPress</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '<a class="apac-more__link" href="' . esc_url( apac_logout_url() ) . '">' . apac_icon( 'logout' ) . '<span>Esci dall’area riservata</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '</div></details></nav>';
}

/** Intestazione della sezione: titolo, spiegazione breve e azione principale facoltativa. */
function apac_section_head( $sez, $label, $action = '' ) {
	$meta = apac_section_meta();
	echo '<header class="apac-sechead"><div><h2>' . esc_html( $label ) . '</h2>';
	if ( isset( $meta[ $sez ] ) ) {
		echo '<p>' . esc_html( $meta[ $sez ][2] ) . '</p>';
	}
	echo '</div>' . $action . '</header>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

/**
 * Modulo di inserimento richiudibile: si apre con un pulsante ben visibile.
 * Resta aperto se il modulo ha dato errore o se l'elenco è vuoto.
 */
function apac_add_box_open( $op, $label, $open = false, $id_key = '', $icon = 'plus' ) {
	$req     = isset( $_GET['nuovo'] ) ? sanitize_key( wp_unslash( $_GET['nuovo'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$is_open = $req === $op || '' !== apac_details_open( $op, $id_key );
	unset( $open ); // Elenco vuoto: il pulsante resta chiuso ma ben visibile, sopra il messaggio "Nessun...".
	return '<details class="apac-add" id="apac-add-' . esc_attr( $op ) . '"' . ( $is_open ? ' open' : '' ) . '><summary class="apac-btn apac-add__btn">' . apac_icon( $icon ) . '<span class="apac-add__open">' . esc_html( $label ) . '</span><span class="apac-add__close">Chiudi il modulo</span></summary><div class="apac-add__body">';
}
function apac_add_box_close() {
	return '</div></details>';
}

/* Nell'app installata la riga superiore della testata del sito (telefono, "Area riservata") è superflua. */
add_action( 'wp_head', 'apac_standalone_css', 99 );
function apac_standalone_css() {
	if ( ! function_exists( 'apac_is_portal_request' ) || ! apac_is_portal_request() ) {
		return;
	}
	echo '<style id="apac-standalone">@media (display-mode: standalone){body.apac-page #header [data-device="mobile"] [data-row="top"]{display:none!important}}</style>' . "\n";
}

/** Indirizzo che apre direttamente il modulo di inserimento di una sezione. */
function apac_add_url( $sez, $op ) {
	return apac_url( $sez, array( 'nuovo' => $op ) ) . '#apac-add-' . $op;
}
