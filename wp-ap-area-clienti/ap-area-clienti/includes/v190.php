<?php
/**
 * 1.9.0 – Interfaccia più semplice: intestazione dedicata, un solo titolo, selettore unico,
 * azioni principali, contatori che filtrano, filtri visibili, primi passi, conferme precise,
 * protezione dai doppi invii e dalle modifiche non salvate.
 */
defined( 'ABSPATH' ) || exit;

/** Sezioni dello studio che non dipendono da un'azienda selezionata. */
function apac_studio_sections() {
	return array( 'pannello', 'richieste', 'utenti', 'installa', 'elenco' );
}

/** Logo piccolo per l'intestazione: logo del sito se impostato, altrimenti quello del plugin. */
function apac_logo_url() {
	$id = (int) get_theme_mod( 'custom_logo' );
	if ( $id ) {
		$url = wp_get_attachment_image_url( $id, 'thumbnail' );
		if ( $url ) {
			return $url;
		}
	}
	return APAC_URL . 'assets/logo-pdf.jpg';
}

/* --------------------- Intestazione dedicata (punto 1) -------------------- */

add_filter( 'body_class', 'apac_app_body_class' );
function apac_app_body_class( $classes ) {
	if ( function_exists( 'apac_is_portal_request' ) && apac_is_portal_request() && is_user_logged_in() ) {
		$classes[] = 'apac-app';
		// 1.9.7: tema scelto dall'utente anche sulla pagina, così sotto un contenuto corto non resta
		// scoperto lo sfondo bianco del tema del sito (fascia bianca sopra la barra in basso).
		$pref = function_exists( 'apac_pref_classes' ) ? apac_pref_classes() : '';
		if ( false !== strpos( $pref, 'apac--dark' ) ) {
			$classes[] = 'apac-app--dark';
		} elseif ( false !== strpos( $pref, 'apac--auto' ) ) {
			$classes[] = 'apac-app--auto';
		}
	}
	return $classes;
}

/* Dopo l'accesso, nella sola pagina dell'area riservata: niente intestazione, menu e piè di pagina del tema. */
add_action( 'wp_head', 'apac_app_hide_theme_chrome', 101 );
function apac_app_hide_theme_chrome() {
	if ( ! function_exists( 'apac_is_portal_request' ) || ! apac_is_portal_request() || ! is_user_logged_in() ) {
		return;
	}
	echo '<style id="apac-app-chrome">'
		. 'body.apac-app #header,body.apac-app #footer,body.apac-app .ct-drawer-canvas,'
		. 'body.apac-app .wp-site-blocks>header,body.apac-app .wp-site-blocks>footer,'
		. 'body.apac-app header.wp-block-template-part,body.apac-app footer.wp-block-template-part,'
		. 'body.apac-app .wp-block-post-title,body.apac-app .ap-pwa-install,'
		. 'body.apac-app #masthead,body.apac-app #colophon,body.apac-app .site-header,body.apac-app .site-footer,'
		. 'body.apac-app aside.widget-area,body.apac-app .entry-header,body.apac-app .entry-footer{display:none!important}'
		. 'body.apac-app #main-container,body.apac-app .site-main,body.apac-app .ct-container-full,body.apac-app .wp-site-blocks{padding-top:0!important;margin-top:0!important}'
		. 'body.apac-app main>.wp-block-group,body.apac-app .entry-content{margin-top:0!important;padding-top:0!important}'
		. 'body.apac-app--dark{background:#0f1712!important}'
		. '@media (prefers-color-scheme:dark){body.apac-app--auto{background:#0f1712!important}}'
		. '</style>' . "\n";
}

/** Pulsante principale dell'intestazione: "+ Azienda" (studio) o "+ Aggiungi" (dentro un'azienda). */
function apac_render_addmenu( $azienda_id, $sez ) {
	if ( apac_is_admin() && in_array( $sez, apac_studio_sections(), true ) ) {
		echo '<a class="apac-addmenu__btn" href="' . esc_url( apac_add_url( 'utenti', 'azienda_create' ) ) . '">' . apac_icon( 'plus' ) . '<span>Azienda</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		return;
	}
	if ( ! $azienda_id ) {
		return;
	}
	$items = array();
	if ( apac_client_can( $azienda_id, 'lavoratori' ) ) {
		$items[] = array( 'users', 'Lavoratore', apac_add_url( 'lavoratori', 'lavoratore_save' ) );
	}
	if ( apac_client_can( $azienda_id, 'documenti' ) ) {
		$items[] = array( 'upload', 'Documento', apac_add_url( 'documenti', 'documento_upload' ) );
	}
	if ( apac_client_can( $azienda_id, 'scadenze' ) ) {
		$items[] = array( 'calendar', 'Scadenza', apac_add_url( 'scadenziario', 'scadenza_save' ) );
	}
	$items[] = array( 'message', 'Segnalazione', apac_add_url( 'segnalazioni', 'segnalazione_create' ) );
	echo '<details class="apac-addmenu"><summary class="apac-addmenu__btn" aria-label="Aggiungi">' . apac_icon( 'plus' ) . '<span>Aggiungi</span></summary><div class="apac-addmenu__sheet"><p class="apac-addmenu__title">Aggiungi</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	foreach ( $items as $it ) {
		echo '<a href="' . esc_url( $it[2] ) . '">' . apac_icon( $it[0] ) . '<span>' . esc_html( $it[1] ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	if ( count( $items ) < 4 ) {
		echo '<p class="apac-addmenu__note">Per altre modifiche scrivi allo studio con una segnalazione.</p>';
	}
	echo '</div></details>';
}

/** Menu account: nome, preferenze, "Vai al sito", "Esci". */
function apac_render_account_menu( $user ) {
	$name = trim( (string) $user->display_name );
	$ini  = '';
	foreach ( preg_split( '/\s+/u', $name ) as $p ) {
		if ( '' !== $p && mb_strlen( $ini ) < 2 ) {
			$ini .= mb_strtoupper( mb_substr( $p, 0, 1 ) );
		}
	}
	// phpcs:disable WordPress.Security.EscapeOutput
	echo '<details class="apac-acct"><summary class="apac-acct__btn" aria-label="Account: ' . esc_attr( $name ) . '"><span class="apac-acct__ini" aria-hidden="true">' . esc_html( '' !== $ini ? $ini : '•' ) . '</span><span class="apac-acct__name">' . esc_html( $name ) . '<small>' . ( apac_is_admin() ? 'Studio' : ( apac_user_readonly() ? 'Cliente · solo consultazione' : 'Cliente' ) ) . '</small></span></summary>';
	echo '<div class="apac-acct__sheet"><p class="apac-acct__who"><strong>' . esc_html( $name ) . '</strong><small>' . esc_html( $user->user_email ) . '</small></p>';
	echo '<details class="apac-acct__prefs"><summary>' . apac_icon_extra( 'contrast' ) . '<span>Aspetto e notifiche</span></summary>' . apac_render_prefs( 'apac-prefs-side' ) . apac_render_push_box( 'apac-push-side' ) . '</details>';
	echo '<a href="' . esc_url( home_url( '/' ) ) . '">' . apac_icon( 'home' ) . '<span>Vai al sito</span></a>';
	echo '<a class="apac-top__out" href="' . esc_url( apac_logout_url() ) . '">' . apac_icon( 'logout' ) . '<span>Esci</span></a>';
	echo '</div></details>';
	// phpcs:enable
}

/** Una riga di descrizione sotto l'intestazione (niente secondo titolo). */
function apac_section_lead( $sez ) {
	$meta = apac_section_meta();
	if ( isset( $meta[ $sez ] ) && '' !== $meta[ $sez ][2] ) {
		echo '<p class="apac-lead">' . esc_html( $meta[ $sez ][2] ) . '</p>';
	}
}

/* ----------------------- Messaggi precisi (punto 14) ---------------------- */

function apac_message_text( $code, $default ) {
	$did = isset( $_GET['apac_did'] ) ? sanitize_key( wp_unslash( $_GET['apac_did'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( '' === $did || ! in_array( $code, array( 'saved', 'deleted' ), true ) ) {
		return $default;
	}
	if ( 'scadenza_toggle' === $did || 'scadenza_stato' === $did ) {
		return ( isset( $_GET['stato'] ) && 'aperta' === $_GET['stato'] ) ? 'Scadenza riaperta.' : 'Scadenza completata.'; // phpcs:ignore WordPress.Security.NonceVerification
	}
	$map = array(
		'azienda_save'          => 'Dati dell’azienda salvati.',
		'azienda_delete'        => 'Azienda eliminata.',
		'azienda_permessi'      => 'Permessi del cliente salvati.',
		'lavoratore_save'       => 'Lavoratore salvato.',
		'lavoratore_delete'     => 'Lavoratore eliminato.',
		'lavoratore_cessa'      => 'Lavoratore segnato come cessato. Lo storico resta consultabile.',
		'lavoratore_riattiva'   => 'Lavoratore di nuovo in forza.',
		'attestato_save'        => 'Attestato salvato.',
		'attestato_update'      => 'Attestato aggiornato.',
		'attestato_delete'      => 'Attestato eliminato.',
		'scadenza_save'         => 'Scadenza salvata.',
		'scadenza_delete'       => 'Scadenza eliminata.',
		'documento_update'      => 'Modifiche al documento salvate.',
		'documento_delete'      => 'Documento eliminato.',
		'segnalazione_update'   => 'Segnalazione aggiornata.',
		'segnalazione_delete'   => 'Segnalazione eliminata.',
		'utente_update'         => 'Utente aggiornato.',
		'mansione_corso_add'    => 'Corso richiesto per la mansione aggiunto.',
		'mansione_corso_delete' => 'Corso richiesto rimosso.',
		'log_svuota'            => 'Registro svuotato.',
		'primi_passi'           => 'Guida “Primi passi” aggiornata.',
	);
	return isset( $map[ $did ] ) ? $map[ $did ] : $default;
}

/* ----------------------- Protezione dai doppi invii ----------------------- */

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

/* ----------------------------- Elenchi: filtri ---------------------------- */

/**
 * Riga con contesto, filtri attivi (rimovibili), numero di risultati e "Azzera filtri".
 * $chips: [ [ etichetta, url senza quel filtro ] ]
 */
function apac_render_filter_summary( $context, $chips, $count, $reset_url = '' ) {
	echo '<div class="apac-fsum" role="status"><span class="apac-fsum__ctx">' . esc_html( $context ) . '</span>';
	foreach ( $chips as $c ) {
		echo '<a class="apac-fsum__chip" href="' . esc_url( $c[1] ) . '" aria-label="' . esc_attr( 'Rimuovi filtro: ' . $c[0] ) . '">' . esc_html( $c[0] ) . '<span aria-hidden="true">×</span></a>';
	}
	echo '<strong class="apac-fsum__n">' . (int) $count . ( 1 === (int) $count ? ' risultato' : ' risultati' ) . '</strong>';
	if ( $chips && $reset_url ) {
		echo '<a class="apac-fsum__reset" href="' . esc_url( $reset_url ) . '">Azzera filtri</a>';
	}
	echo '</div>';
}

/** Elenco vuoto: distingue "nessun dato" da "nessun risultato con questi filtri". */
function apac_render_empty( $has_data, $none_text, $reset_url = '' ) {
	if ( $has_data ) {
		echo '<p class="apac-empty">Nessun risultato con i filtri attuali.' . ( $reset_url ? ' <a href="' . esc_url( $reset_url ) . '">Azzera filtri</a>' : '' ) . '</p>';
	} else {
		echo '<p class="apac-empty">' . esc_html( $none_text ) . '</p>';
	}
}

/** URL della sezione con i filtri attuali (tranne $without). */
function apac_url_filters( $sez, $names, $without = '', $extra = array() ) {
	$args = array();
	foreach ( $names as $n ) {
		if ( $n === $without ) {
			continue;
		}
		$v = isset( $_GET[ $n ] ) ? sanitize_text_field( wp_unslash( $_GET[ $n ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $v ) {
			$args[ $n ] = $v;
		}
	}
	return apac_url( $sez, array_merge( $args, $extra ) );
}

/* -------------------- Stato iniziale e scelta azienda --------------------- */

function apac_render_prima_azienda() {
	echo '<section class="apac-card apac-first"><h2>Aggiungi la prima azienda</h2>';
	echo '<p>Per ogni azienda cliente potrai gestire lavoratori e attestati, documenti, scadenze e segnalazioni, e dare l’accesso ai referenti dell’azienda.</p>';
	echo '<p><a class="apac-btn" href="' . esc_url( apac_add_url( 'utenti', 'azienda_create' ) ) . '">' . apac_icon( 'plus' ) . '<span>Crea azienda</span></a></p></section>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

/** Sezione aziendale senza azienda selezionata: elenco diretto per sceglierla. */
function apac_render_scegli_azienda( $sez ) {
	if ( ! apac_is_admin() ) {
		echo '<div class="apac-card"><p>Nessuna azienda collegata al tuo account. Contatta AP Sicurezza Lavoro.</p></div>';
		return;
	}
	$q = apac_aziende_query( '', 1, APAC_LIST_PAGE );
	if ( ! $q['total'] ) {
		apac_render_prima_azienda();
		return;
	}
	echo '<section class="apac-card"><h2>Scegli un’azienda</h2><p class="apac-muted">Tocca un’azienda per aprire questa sezione' . ( $q['total'] > APAC_LIST_PAGE ? ', oppure cercala nel campo qui sopra' : '' ) . '.</p><ul class="apac-list apac-azlist__list">';
	foreach ( $q['rows'] as $a ) {
		echo '<li><a href="' . esc_url( apac_url( apac_target_section( $sez ), array( 'azienda' => (int) $a->id ) ) ) . '"><span class="apac-azlist__name">' . esc_html( $a->ragione_sociale ) . '<small>' . esc_html( apac_azienda_ident( $a ) . ( $a->comune ? ' · ' . $a->comune : '' ) ) . '</small></span>' . apac_icon( 'arrow' ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</ul></section>';
}

/* ----------------------- Installa l'app (punto 6) ------------------------- */

function apac_render_installa() {
	if ( ! apac_is_admin() ) {
		return;
	}
	apac_render_share_card( true );
}

/* -------------------- Elenco filtrato di tutte le aziende ----------------- */

function apac_elenco_tipi() {
	return array(
		'scadute'      => 'Scadute',
		'entro30'      => 'Entro 30 giorni',
		'segnalazioni' => 'Segnalazioni da gestire',
		'criticita'    => 'Criticità alte',
	);
}

/** Voci di tutte le aziende per un tipo di contatore (stessi criteri e stesse fonti del pannello). */
function apac_elenco_items( $tipo ) {
	global $wpdb;
	$today   = apac_today();
	$items   = array();
	$aziende = array();
	foreach ( apac_get_aziende() as $a ) {
		$aziende[ (int) $a->id ] = $a;
	}
	if ( 'scadute' === $tipo || 'entro30' === $tipo ) {
		foreach ( apac_agenda_tutte( 30 ) as $aid => $list ) {
			if ( ! isset( $aziende[ $aid ] ) ) {
				continue;
			}
			foreach ( $list as $i ) {
				if ( ( 'scadute' === $tipo ) === ( $i['data'] < $today ) ) {
					$items[] = array( 'az' => $aziende[ $aid ], 'title' => $i['titolo'], 'sub' => $i['tipo'] . ' · ' . apac_date_it( $i['data'] ), 'badge' => apac_badge_scadenza( $i['data'] ), 'url' => apac_agenda_url( $i, $aid ), 'sort' => $i['data'] );
				}
			}
		}
	} elseif ( 'segnalazioni' === $tipo ) {
		foreach ( (array) $wpdb->get_results( 'SELECT id, azienda_id, titolo, priorita, created_at FROM ' . apac_table( 'segnalazioni' ) . " WHERE stato <> 'chiusa'" ) as $s ) {
			$aid = (int) $s->azienda_id;
			if ( ! isset( $aziende[ $aid ] ) ) {
				continue;
			}
			$items[] = array( 'az' => $aziende[ $aid ], 'title' => $s->titolo, 'sub' => apac_date_it( $s->created_at ), 'badge' => apac_badge_livello( in_array( $s->priorita, array( 'alta', 'media' ), true ) ? $s->priorita : 'bassa' ), 'url' => apac_url( 'segnalazioni', array( 'azienda' => $aid ) ), 'sort' => 'z' . ( 9999999999 - strtotime( $s->created_at ) ) );
		}
	} else {
		foreach ( $aziende as $aid => $a ) {
			foreach ( apac_criticita_alte( $aid ) as $c ) {
				$items[] = array( 'az' => $a, 'title' => $c['titolo'], 'sub' => $c['norma'], 'badge' => apac_badge_livello( 'alta' ), 'url' => apac_url( $c['sez'], array( 'azienda' => $aid ) ), 'sort' => $a->ragione_sort );
			}
		}
	}
	usort(
		$items,
		function ( $x, $y ) {
			return strcmp( (string) $x['sort'], (string) $y['sort'] );
		}
	);
	return $items;
}

function apac_render_elenco() {
	if ( ! apac_is_admin() ) {
		return;
	}
	$tipi = apac_elenco_tipi();
	$tipo = apac_filter_val( 'tipo', array_keys( $tipi ), 'scadute' );
	$all  = apac_elenco_items( $tipo );
	$page = isset( $_GET['epag'] ) ? max( 1, absint( $_GET['epag'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
	$per  = 30;
	$pages = max( 1, (int) ceil( count( $all ) / $per ) );
	$page  = min( $page, $pages );

	echo '<div class="apac-filters" role="group" aria-label="Tipo di elenco">';
	foreach ( $tipi as $k => $label ) {
		echo '<a href="' . esc_url( apac_url( 'elenco', array( 'tipo' => $k, 'azienda' => 0 ) ) ) . '"' . ( $k === $tipo ? ' class="is-active" aria-current="true"' : '' ) . '>' . esc_html( $label ) . '</a>';
	}
	echo '</div>';
	apac_render_filter_summary( $tipi[ $tipo ] . ' · Tutte le aziende', array(), count( $all ) );
	echo '<section class="apac-card">';
	if ( ! $all ) {
		echo '<p class="apac-empty">Nessuna voce di questo tipo nelle aziende clienti.</p>';
	} else {
		echo '<ul class="apac-list apac-elenco">';
		foreach ( array_slice( $all, ( $page - 1 ) * $per, $per ) as $it ) {
			echo '<li><a href="' . esc_url( $it['url'] ) . '"><span><em class="apac-elenco__az">' . esc_html( $it['az']->ragione_sociale ) . '</em>' . esc_html( $it['title'] ) . ( '' !== (string) $it['sub'] ? '<small>' . esc_html( $it['sub'] ) . '</small>' : '' ) . '</span>' . $it['badge'] . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul>';
		apac_render_pager( array( 'page' => $page, 'pages' => $pages ), 'epag', array( 'tipo' => $tipo, 'azienda' => 0 ) );
	}
	echo '<p><a class="apac-more-link" href="' . esc_url( apac_url( 'pannello', array( 'azienda' => 0 ) ) ) . '">← Tutte le aziende</a></p></section>';
}

/* --------------------------- Primi passi (punto 12) ------------------------ */

function apac_primi_passi_opt( $azienda_id ) {
	$o = get_option( 'apac_pp_' . (int) $azienda_id, array() );
	$o = is_array( $o ) ? $o : array();
	return array(
		'na'     => isset( $o['na'] ) && is_array( $o['na'] ) ? $o['na'] : array(),
		'hidden' => ! empty( $o['hidden'] ),
	);
}

/** Passi con stato: fatto (dati presenti), non applicabile o da fare. */
function apac_primi_passi( $az ) {
	$id   = (int) $az->id;
	$opt  = apac_primi_passi_opt( $id );
	$defs = array(
		'dvr'        => array( 'Carica il DVR', 'documenti', 'documenti', apac_add_url( 'documenti', 'documento_upload' ), apac_count( 'documenti', "azienda_id = %d AND categoria = 'dvr'", $id ) ),
		'lavoratori' => array( 'Aggiungi i lavoratori', 'lavoratori', 'lavoratori', apac_add_url( 'lavoratori', 'lavoratore_save' ), apac_count( 'lavoratori', 'azienda_id = %d', $id ) ),
		'scadenze'   => array( 'Inserisci le scadenze', 'scadenze', 'scadenziario', apac_add_url( 'scadenziario', 'scadenza_save' ), apac_count( 'scadenze', 'azienda_id = %d', $id ) ),
	);
	$out = array();
	foreach ( $defs as $k => $d ) {
		$out[ $k ] = array(
			'label' => $d[0],
			'perm'  => $d[1],
			'url'   => $d[3],
			'done'  => $d[4] > 0,
			'na'    => in_array( $k, $opt['na'], true ),
		);
	}
	return array( $out, $opt['hidden'] );
}

function apac_render_primi_passi( $az ) {
	list( $passi, $hidden ) = apac_primi_passi( $az );
	$aperti = array_filter(
		$passi,
		function ( $p ) {
			return ! $p['done'] && ! $p['na'];
		}
	);
	if ( $hidden || ! $aperti ) {
		return;
	}
	$id = (int) $az->id;
	// phpcs:disable WordPress.Security.EscapeOutput
	echo '<section class="apac-card apac-steps-start" aria-label="Primi passi"><div class="apac-todo__head"><h2>Primi passi</h2><span class="apac-badge apac-badge--muted">' . ( count( $passi ) - count( $aperti ) ) . ' di ' . count( $passi ) . '</span></div>';
	echo '<p class="apac-muted">Tre passi per iniziare. La guida indica solo se i dati iniziali sono stati inseriti: non è una verifica della conformità.</p><ol class="apac-pp">';
	$n = 0;
	foreach ( $passi as $k => $p ) {
		$n++;
		$state = $p['done'] ? 'done' : ( $p['na'] ? 'na' : 'todo' );
		echo '<li class="apac-pp__item is-' . $state . '"><span class="apac-pp__n" aria-hidden="true">' . ( 'todo' === $state ? $n : apac_icon( 'check' ) ) . '</span><span class="apac-pp__txt"><strong>' . esc_html( $p['label'] ) . '</strong>';
		if ( 'done' === $state ) {
			echo '<small>Dati inseriti</small>';
		} elseif ( 'na' === $state ) {
			echo '<small>Non applicabile</small>';
		}
		echo '</span><span class="apac-pp__act">';
		if ( 'todo' === $state ) {
			if ( apac_client_can( $id, $p['perm'] ) ) {
				echo '<a class="apac-btn apac-btn--small" href="' . esc_url( $p['url'] ) . '">Inizia</a>';
			} else {
				echo '<a class="apac-btn apac-btn--small apac-btn--ghost" href="' . esc_url( apac_add_url( 'segnalazioni', 'segnalazione_create' ) ) . '">Chiedi allo studio</a>';
			}
		}
		if ( apac_is_admin() && ! $p['done'] ) {
			echo apac_button_form( 'primi_passi', $id, array( 'passo' => $k, 'azione' => $p['na'] ? 'ripristina' : 'na' ), $p['na'] ? 'Ripristina' : 'Non applicabile', 'apac-btn apac-btn--small apac-btn--ghost' );
		}
		echo '</span></li>';
	}
	echo '</ol>';
	if ( apac_is_admin() ) {
		echo '<div class="apac-pp__hide">' . apac_button_form( 'primi_passi', $id, array( 'azione' => 'nascondi' ), 'Nascondi la guida per questa azienda', 'apac-linkbtn' ) . '</div>';
	}
	echo '</section>';
	// phpcs:enable
}

function apac_op_primi_passi( $azienda_id ) {
	$azione = sanitize_key( apac_post( 'azione' ) );
	$passo  = sanitize_key( apac_post( 'passo' ) );
	$opt    = apac_primi_passi_opt( $azienda_id );
	if ( 'nascondi' === $azione ) {
		$opt['hidden'] = true;
	} elseif ( in_array( $passo, array( 'dvr', 'lavoratori', 'scadenze' ), true ) && in_array( $azione, array( 'na', 'ripristina' ), true ) ) {
		$opt['na'] = array_values( array_diff( $opt['na'], array( $passo ) ) );
		if ( 'na' === $azione ) {
			$opt['na'][] = $passo;
		}
	} else {
		apac_redirect( 'dashboard', 'invalid' );
	}
	update_option( 'apac_pp_' . (int) $azienda_id, $opt, false );
	apac_redirect( 'dashboard', 'saved' );
}

/* --------------------- Lavoratori: cessazione (punto 10) ------------------ */

function apac_op_lavoratore_cessa( $azienda_id ) {
	global $wpdb;
	$id  = absint( apac_post( 'id' ) );
	$row = apac_get_row_for_azienda( 'lavoratori', $id, $azienda_id );
	if ( ! $row ) {
		apac_redirect( 'lavoratori', 'denied' );
	}
	$back = array( 'lav' => $id );
	$d    = apac_parse_date( apac_post( 'data_cessazione' ) );
	if ( ! $d['ok'] || ! $d['date'] ) {
		apac_error_field( 'data_cessazione' );
		apac_redirect( 'lavoratori', 'invalid_date', $back );
	}
	if ( ! empty( $row->data_assunzione ) && $d['date'] < $row->data_assunzione ) {
		apac_error_field( 'data_cessazione' );
		apac_redirect( 'lavoratori', 'cess_before_hire', $back );
	}
	apac_must( $wpdb->update( apac_table( 'lavoratori' ), array( 'attivo' => 0, 'data_cessazione' => $d['date'] ), array( 'id' => $id, 'azienda_id' => $azienda_id ) ), 'lavoratori', $back );
	apac_log_detail( $row->cognome . ' ' . $row->nome . ' – cessato il ' . apac_date_it( $d['date'] ) );
	apac_redirect( 'lavoratori', 'saved', $back );
}

function apac_op_lavoratore_riattiva( $azienda_id ) {
	global $wpdb;
	$id  = absint( apac_post( 'id' ) );
	$row = apac_get_row_for_azienda( 'lavoratori', $id, $azienda_id );
	if ( ! $row ) {
		apac_redirect( 'lavoratori', 'denied' );
	}
	apac_must( $wpdb->update( apac_table( 'lavoratori' ), array( 'attivo' => 1, 'data_cessazione' => null ), array( 'id' => $id, 'azienda_id' => $azienda_id ) ), 'lavoratori', array( 'lav' => $id ) );
	apac_log_detail( $row->cognome . ' ' . $row->nome . ' – di nuovo in forza' );
	apac_redirect( 'lavoratori', 'saved', array( 'lav' => $id ) );
}

/** Modulo "Segna come cessato" (data obbligatoria, storico conservato). */
function apac_cessa_form( $l, $azienda_id ) {
	$h  = apac_form_open( 'lavoratore_cessa', $azienda_id, false, 'apac-form apac-form--compact' ) . apac_hidden( 'id', (int) $l->id );
	$h .= '<div class="apac-fields">' . apac_field( 'Data di cessazione *', 'data_cessazione', apac_today(), 'date', 'required' ) . '</div>';
	$h .= '<p class="apac-muted">Il lavoratore esce dall’elenco “In forza”; attestati e storico restano consultabili tra i cessati.</p>';
	$h .= '<div class="apac-actions"><button class="apac-btn apac-btn--small" type="submit">Segna come cessato</button></div></form>';
	return $h;
}

/* ---------------------- Gruppi espandibili nei moduli --------------------- */

/** Apre un gruppo: resta aperto se contiene il campo con errore o se $open. */
function apac_group_open( $title, $fields, $open = false, $filled = -1 ) {
	foreach ( $fields as $f ) {
		if ( '' !== apac_form_field_error( $f ) ) {
			$open = true;
		}
	}
	$hint = $filled >= 0 ? '<small>' . ( $filled ? (int) $filled . ' di ' . count( $fields ) . ' compilati' : 'da compilare' ) . '</small>' : '';
	return '<details class="apac-group"' . ( $open ? ' open' : '' ) . '><summary><span>' . esc_html( $title ) . '</span>' . $hint . '</summary><div class="apac-fields">';
}
function apac_group_close() {
	return '</div></details>';
}

/* -------------------------- Script dell'interfaccia ----------------------- */

add_action( 'wp_footer', 'apac_v190_script', 41 );
function apac_v190_script() {
	if ( ! function_exists( 'apac_is_portal_request' ) || ! apac_is_portal_request() || ! is_user_logged_in() ) {
		return;
	}
	?>
<script id="apac-v190-js">
(function () {
  'use strict';
  var dirty = new Set();
  var leaving = false;
  function tracked(f) {
    return f && f.classList && f.classList.contains('apac-form') && !f.classList.contains('apac-inline') && !f.closest('.apac-filterbar');
  }
  document.addEventListener('input', function (e) {
    var f = e.target.form; if (!tracked(f) || e.target.type === 'hidden' || e.target.type === 'search') return;
    dirty.add(f);
  });
  document.addEventListener('change', function (e) {
    var f = e.target.form; if (!tracked(f) || e.target.type === 'hidden') return;
    dirty.add(f);
  });
  // Invio: nessun avviso per il modulo inviato, pulsante bloccato durante il salvataggio.
  // 1.9.4: se ALTRI moduli hanno modifiche non salvate (es. una ricerca inviata mentre una scheda è
  // compilata a metà) si chiede prima cosa fare: l'avviso non viene più disattivato in silenzio.
  var bypass = null;
  function othersDirty(f) {
    var n = []; dirty.forEach(function (d) { if (d !== f && document.contains(d)) n.push(d); });
    return n;
  }
  function getUrl(f, sub) {
    try {
      var u = new URL(f.getAttribute('action') || location.href, location.href);
      var fd; try { fd = sub ? new FormData(f, sub) : new FormData(f); } catch (x) { fd = new FormData(f); }
      u.search = new URLSearchParams(fd).toString();
      return u.href;
    } catch (x) { return null; }
  }
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (e.defaultPrevented || !f.matches('form') || f.target === '_blank') return;
    var isGet = f.method && f.method.toLowerCase() === 'get';
    if (bypass !== f && othersDirty(f).length) {
      e.preventDefault();
      var url = isGet ? getUrl(f, e.submitter || null) : null;
      ask(url ? { url: url } : { form: f, submitter: e.submitter || null });
      return;
    }
    bypass = null;
    dirty.clear(); leaving = true;
    if (isGet) return;
    var b = e.submitter || f.querySelector('button[type=submit],button:not([type])');
    if (!b) return;
    // Il pulsante non ha "name": disattivarlo subito non toglie dati dall'invio.
    if (b.name) { var h = document.createElement('input'); h.type = 'hidden'; h.name = b.name; h.value = b.value; f.appendChild(h); }
    b.dataset.apacLabel = b.innerHTML;
    b.disabled = true; b.setAttribute('aria-busy', 'true');
    b.textContent = f.enctype === 'multipart/form-data' ? 'Invio in corso…' : 'Salvataggio…';
    // 1.9.9: invio senza risposta (rete lenta o assente). Si memorizza che un salvataggio è partito: se la
    // pagina successiva non riporta l'esito (es. errore di rete e ritorno indietro), si spiega cosa fare.
    try { sessionStorage.setItem('apac-pending', JSON.stringify({ t: Date.now() })); } catch (x) {}
    setTimeout(function () {
      if (!b.isConnected || !b.disabled) return;
      var n = document.createElement('p'); n.className = 'apac-netwait'; n.setAttribute('role', 'status');
      n.textContent = 'Il salvataggio sta impiegando più del solito. Non inviare di nuovo: attendi la risposta. Se la connessione è caduta, ricarica la pagina e controlla se il dato risulta salvato prima di ripetere l’operazione.';
      b.insertAdjacentElement('afterend', n);
    }, 15000);
    setTimeout(function () { unlock(b); }, 60000);
  }, false);
  // Esito di un invio precedente: con apac_msg nell'indirizzo è arrivato; senza, è possibile che la rete
  // abbia interrotto l'invio. Il doppio invio resta comunque bloccato dal server (codice monouso).
  (function () {
    var p = null; try { p = JSON.parse(sessionStorage.getItem('apac-pending') || 'null'); sessionStorage.removeItem('apac-pending'); } catch (x) {}
    if (!p || !p.t || Date.now() - p.t > 30 * 60 * 1000) return;
    if (/[?&]apac_msg=/.test(location.search)) return;
    var box = document.createElement('div'); box.className = 'apac-notice apac-notice--warn'; box.setAttribute('role', 'alert');
    box.textContent = 'Non è arrivata la conferma dell’ultimo invio (connessione interrotta o pagina chiusa). Controlla qui sotto se il dato risulta salvato: se manca, ripeti l’operazione. Un invio già arrivato non viene mai registrato due volte.';
    var host = document.querySelector('.apac-main') || document.querySelector('.apac');
    if (host) host.insertBefore(box, host.firstChild);
  })();
  function unlock(b) {
    if (b && b.dataset.apacLabel) { b.innerHTML = b.dataset.apacLabel; delete b.dataset.apacLabel; }
    if (b) { b.disabled = false; b.removeAttribute('aria-busy'); }
  }
  window.addEventListener('pageshow', function (e) {
    leaving = false;
    if (e.persisted) document.querySelectorAll('button[aria-busy]').forEach(unlock);
  });
  // Modifiche non salvate: avviso prima di lasciare la pagina.
  // target = { url: indirizzo } oppure { form: modulo da inviare, submitter: pulsante }.
  var dlg = null, target = null;
  function canSaveAndGo(t) {
    if (!t || !t.url || dirty.size !== 1) return null;
    var f = dirty.values().next().value;
    if (!f || !document.contains(f) || !f.method || f.method.toLowerCase() !== 'post') return null;
    return f;
  }
  function ask(t) {
    if (typeof t === 'string') t = { url: t };
    target = t;
    if (!window.HTMLDialogElement) {
      if (window.confirm('Hai modifiche non salvate. Vuoi abbandonarle?')) go(t);
      return;
    }
    if (!dlg) {
      dlg = document.createElement('dialog'); dlg.className = 'apac-dialog';
      dlg.setAttribute('aria-labelledby', 'apac-dlg-t');
      dlg.innerHTML = '<h2 id="apac-dlg-t">Modifiche non salvate</h2><p>Hai compilato un modulo senza salvarlo. Se esci ora, i dati inseriti andranno persi.</p><div class="apac-dialog__btns"><button type="button" class="apac-btn" data-save>Salva e continua</button><button type="button" class="apac-btn apac-btn--ghost" data-stay>Resta qui</button><button type="button" class="apac-btn apac-btn--ghost" data-leave>Abbandona modifiche</button></div>';
      (document.querySelector('.apac') || document.body).appendChild(dlg);
      dlg.querySelector('[data-stay]').addEventListener('click', stay);
      dlg.querySelector('[data-leave]').addEventListener('click', function () { dlg.close(); go(target); });
      dlg.querySelector('[data-save]').addEventListener('click', saveAndGo);
      dlg.addEventListener('cancel', function (ev) { ev.preventDefault(); stay(); });
    }
    var sv = dlg.querySelector('[data-save]');
    sv.hidden = !canSaveAndGo(t);
    dlg.showModal(); (sv.hidden ? dlg.querySelector('[data-stay]') : sv).focus();
  }
  // Salva il modulo compilato; se il salvataggio riesce il server apre la pagina scelta,
  // se non riesce si torna al modulo con i dati conservati e l'errore indicato.
  function saveAndGo() {
    var f = canSaveAndGo(target);
    if (!f) { stay(); return; }
    dlg.close();
    if (f.reportValidity && !f.reportValidity()) { stay(); return; }
    var h = f.querySelector('input[name="apac_next"]');
    if (!h) { h = document.createElement('input'); h.type = 'hidden'; h.name = 'apac_next'; f.appendChild(h); }
    h.value = target.url;
    var b = f.querySelector('button[type=submit],button:not([type])');
    if (f.requestSubmit) { f.requestSubmit(b || undefined); } else { dirty.clear(); leaving = true; f.submit(); }
  }
  function stay() {
    if (dlg && dlg.open) dlg.close();
    var f = dirty.values().next().value; if (!f) return;
    var d = f.closest('details'); while (d) { d.open = true; d = d.parentElement ? d.parentElement.closest('details') : null; }
    var el = f.querySelector('input:not([type=hidden]),select,textarea'); if (el) { el.focus(); }
  }
  function go(t) {
    if (typeof t === 'string') t = { url: t };
    if (!t) return;
    dirty.clear(); leaving = true;
    if (t.form) {
      bypass = t.form;
      if (t.form.requestSubmit) { t.form.requestSubmit(t.submitter || undefined); } else { t.form.submit(); }
      return;
    }
    window.location.href = t.url;
  }
  window.apacGo = function (url) { if (dirty.size) ask({ url: url }); else go({ url: url }); };
  document.addEventListener('click', function (e) {
    if (!dirty.size || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;
    var a = e.target.closest ? e.target.closest('a[href]') : null;
    if (!a || a.target === '_blank' || a.hasAttribute('download')) return;
    var href = a.getAttribute('href') || '';
    if (href.charAt(0) === '#' || /^(mailto|tel|javascript):/i.test(href)) return;
    var u; try { u = new URL(a.href, location.href); } catch (x) { return; }
    if (u.origin !== location.origin) return;
    if (u.pathname === location.pathname && u.search === location.search && u.hash) return;
    if (/[?&](apac_file|apac_ver|apac_foto|apac_cons|apac_export|apac_template|apac_report)=/.test(u.search)) return;
    e.preventDefault(); ask(a.href);
  }, true);
  window.addEventListener('beforeunload', function (e) {
    if (dirty.size && !leaving) { e.preventDefault(); e.returnValue = ''; return ''; }
  });
  // Menu a tendina dell'intestazione: uno alla volta, chiusi con Esc o toccando fuori.
  var menus = document.querySelectorAll('.apac-addmenu, .apac-acct, .apac-more, .apac-doc__more');
  document.addEventListener('click', function (e) {
    menus.forEach(function (m) { if (m.open && !m.contains(e.target)) m.open = false; });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    menus.forEach(function (m) { if (m.open) { m.open = false; var s = m.querySelector('summary'); if (s) s.focus(); } });
  });
  // Ritorno agli elenchi: stessa posizione di prima.
  function key(u) {
    var p = new URL(u, location.href).searchParams; var a = [];
    p.forEach(function (v, k) { if (k !== 'apac_msg' && k !== 'apac_did' && k !== 'apac_form') a.push(k + '=' + v); });
    return 'apac-pos:' + a.sort().join('&');
  }
  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('[data-apac-keep-pos] a[href]') : null;
    if (!a) return;
    try { sessionStorage.setItem(key(location.href), String(window.scrollY)); } catch (x) {}
  });
  try {
    var y = sessionStorage.getItem(key(location.href));
    if (y !== null && document.querySelector('[data-apac-keep-pos]')) { sessionStorage.removeItem(key(location.href)); window.scrollTo(0, parseInt(y, 10) || 0); }
  } catch (x) {}
})();
</script>
	<?php
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
