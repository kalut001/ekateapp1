<?php
defined( 'ABSPATH' ) || exit;

add_shortcode( 'ap_area_clienti', 'apac_render_portal' );

add_action( 'wp_enqueue_scripts', 'apac_enqueue_assets' );
function apac_enqueue_assets() {
	$page_id = (int) get_option( 'apac_page_id' );
	global $post;
	$has = ( $page_id && is_page( $page_id ) ) || ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'ap_area_clienti' ) );
	if ( $has ) {
		// Stile incorporato nella pagina: la cache del server (Varnish di Register) conserva
		// i file .css per 30 giorni ignorando "?ver=", quindi un file aggiornato non arriverebbe.
		// Le pagine dell'area riservata non vanno in cache, perciò lo stile incorporato è sempre attuale.
		$css = is_readable( APAC_DIR . 'assets/portal.css' ) ? (string) file_get_contents( APAC_DIR . 'assets/portal.css' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_register_style( 'apac-portal', false, array(), APAC_VERSION );
		wp_enqueue_style( 'apac-portal' );
		$dark = is_readable( APAC_DIR . 'assets/dark.css' ) ? (string) file_get_contents( APAC_DIR . 'assets/dark.css' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( '' !== $dark ) {
			// Tema scuro scelto dall'utente + versione "come il telefono" (segue l'impostazione del dispositivo).
			$css .= "\n" . $dark . "\n@media (prefers-color-scheme: dark) {\n" . str_replace( '.apac.apac--dark', '.apac.apac--auto', $dark ) . "\n}\n";
		}
		wp_add_inline_style( 'apac-portal', $css );
		// Stile del sito copiato dalle sue pagine: caricato DOPO, così a parità di regola vince il sito.
		$site_css = (string) get_option( 'apac_site_css', '' );
		if ( '' !== $site_css ) {
			wp_register_style( 'apac-site', false, array( 'apac-portal' ), APAC_VERSION );
			wp_enqueue_style( 'apac-site' );
			wp_add_inline_style( 'apac-site', $site_css );
		}
	}
}

/**
 * La richiesta mostra il portale? Vale per la pagina configurata e per QUALSIASI
 * altro contenuto che contenga lo shortcode [ap_area_clienti].
 */
function apac_is_portal_request() {
	if ( is_admin() ) {
		return false;
	}
	$page_id = (int) get_option( 'apac_page_id' );
	if ( $page_id && is_page( $page_id ) ) {
		return true;
	}
	if ( is_singular() ) {
		$post = get_queried_object();
		return $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'ap_area_clienti' );
	}
	return false;
}

/* Le pagine con il portale non vanno indicizzate né messe in cache (browser, proxy, plugin di cache). */
add_action( 'template_redirect', 'apac_portal_headers', 1 );
function apac_portal_headers() {
	if ( ! apac_is_portal_request() ) {
		return;
	}
	// Segnale per i plugin di cache più diffusi (WP Super Cache, W3 Total Cache, WP Rocket, LiteSpeed...).
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
		define( 'DONOTCACHEOBJECT', true );
	}
	if ( ! defined( 'DONOTCACHEDB' ) ) {
		define( 'DONOTCACHEDB', true );
	}
	do_action( 'litespeed_control_set_nocache', 'apac portal' );
	nocache_headers();
	header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0, private', true );
	header( 'Surrogate-Control: no-store' ); // Proxy/CDN che rispettano Surrogate-Control (es. Varnish configurati, Fastly).
	header( 'Vary: Cookie', false );
	header( 'X-Robots-Tag: noindex, nofollow' );
}

/* Anche le risposte delle operazioni (admin-post) non devono essere memorizzate. */
add_action( 'admin_post_apac_action', 'nocache_headers', 1 );

add_filter( 'wp_robots', 'apac_portal_robots' );
function apac_portal_robots( $robots ) {
	if ( apac_is_portal_request() ) {
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
	}
	return $robots;
}

/* ------------------------------------------------------------------------ */

function apac_sections() {
	$s = array();
	if ( apac_is_admin() ) {
		$s['pannello'] = 'Tutte le aziende';
	}
	$s += array(
		'dashboard'    => 'Riepilogo azienda',
		'azienda'      => 'Dati azienda',
		'lavoratori'   => 'Lavoratori',
		'documenti'    => 'Documenti',
		'scadenziario' => 'Scadenze',
		'segnalazioni' => 'Segnalazioni',
		'criticita'    => 'Criticità',
		'cerca'        => 'Cerca nei dati',
	);
	if ( apac_is_admin() ) {
		$s['richieste'] = 'Richieste di accesso';
		$s['utenti']   = 'Utenti e aziende';
		$s['installa'] = 'Installa l’app (clienti)';
		$s['elenco']   = 'Elenco filtrato';
	}
	return $s;
}

/** Apertura di un modulo che invia all'handler sicuro. */
function apac_form_open( $op, $azienda_id, $multipart = false, $class = 'apac-form' ) {
	apac_form_begin( $op );
	$html  = '<form method="post" class="' . esc_attr( $class ) . '" action="' . esc_url( apac_portal_post_url() ) . '"' . ( $multipart ? ' enctype="multipart/form-data"' : '' ) . '>';
	$html .= '<input type="hidden" name="action" value="apac_action">';
	$html .= '<input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
	$html .= '<input type="hidden" name="azienda_id" value="' . (int) $azienda_id . '">';
	$html .= '<input type="hidden" name="_apac_nonce" value="' . esc_attr( wp_create_nonce( 'apac_' . $op ) ) . '">';
	$html .= function_exists( 'apac_once_field' ) ? apac_once_field() : '';
	$html .= function_exists( 'apac_form_ref_field' ) ? apac_form_ref_field( $op ) : '';
	return $html;
}

/** Piccolo modulo con un solo pulsante (elimina, cambia stato...). */
function apac_button_form( $op, $azienda_id, $fields, $label, $class = 'apac-btn apac-btn--small', $confirm = '' ) {
	$html = apac_form_open( $op, $azienda_id, false, 'apac-inline' );
	foreach ( $fields as $k => $v ) {
		$html .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
	}
	$onclick = $confirm ? ' onclick="return confirm(\'' . esc_js( $confirm ) . '\');"' : '';
	$html   .= '<button type="submit" class="' . esc_attr( $class ) . '"' . $onclick . '>' . esc_html( $label ) . '</button></form>';
	return $html;
}

/** Classe, attributi e messaggio di errore di un campo (se è quello da correggere). */
function apac_field_error_parts( $name ) {
	$err = apac_form_field_error( $name );
	if ( '' === $err ) {
		return array( '', '', '' );
	}
	return array( ' apac-field--error', ' aria-invalid="true" autofocus', '<small class="apac-field-msg" role="alert">' . esc_html( $err ) . '</small>' );
}

function apac_field( $label, $name, $value = '', $type = 'text', $attrs = '' ) {
	$value = apac_form_value( $name, $value );
	list( $cls, $aria, $msg ) = apac_field_error_parts( $name );
	return '<label class="apac-field' . $cls . '"><span>' . esc_html( $label ) . '</span><input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" ' . $attrs . $aria . '>' . $msg . '</label>';
}

function apac_textarea( $label, $name, $value = '', $hint = '' ) {
	$value = apac_form_value( $name, $value );
	list( $cls, $aria, $msg ) = apac_field_error_parts( $name );
	$h = $hint ? '<small>' . esc_html( $hint ) . '</small>' : '';
	return '<label class="apac-field apac-field--wide' . $cls . '"><span>' . esc_html( $label ) . '</span><textarea name="' . esc_attr( $name ) . '" rows="3"' . $aria . '>' . esc_textarea( (string) $value ) . '</textarea>' . $h . $msg . '</label>';
}

function apac_select( $label, $name, $options, $selected = '', $attrs = '' ) {
	$selected = apac_form_value( $name, $selected );
	list( $cls, $aria, $msg ) = apac_field_error_parts( $name );
	$html = '<label class="apac-field' . $cls . '"><span>' . esc_html( $label ) . '</span><select name="' . esc_attr( $name ) . '" ' . $attrs . $aria . '>';
	foreach ( $options as $k => $v ) {
		$html .= '<option value="' . esc_attr( $k ) . '"' . selected( (string) $selected, (string) $k, false ) . '>' . esc_html( $v ) . '</option>';
	}
	return $html . '</select>' . $msg . '</label>';
}

function apac_checkbox( $label, $name, $checked ) {
	return '<label class="apac-field apac-check"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( apac_form_checked( $name, $checked ), true, false ) . '><span>' . esc_html( $label ) . '</span></label>';
}

/** Campo file con eventuale errore e avviso di riselezione. */
function apac_file_field( $label, $accept, $required = false, $wide = false, $max_bytes = 0 ) {
	list( $cls, $aria, $msg ) = apac_field_error_parts( 'file' );
	// 1.9.9: formati ammessi e dimensione massima indicati sotto il campo.
	$ext  = array_unique( array_map( function ( $e ) { $e = strtoupper( ltrim( trim( $e ), '.' ) ); return 'JPEG' === $e ? 'JPG' : $e; }, explode( ',', $accept ) ) );
	$limit = $max_bytes > 0 ? min( (int) $max_bytes, (int) wp_max_upload_size() ) : (int) wp_max_upload_size();
	$hint = 'Formati ammessi: ' . implode( ', ', $ext ) . ( false === strpos( $label, 'max' ) ? ' · massimo ' . size_format( $limit ) : '' ) . '.';
	return '<label class="apac-field' . ( $wide ? ' apac-field--wide' : '' ) . $cls . '"><span>' . esc_html( $label ) . '</span><input type="file" name="file" accept="' . esc_attr( $accept ) . '"' . ( $required ? ' required' : '' ) . $aria . '><small>' . esc_html( $hint ) . '</small>' . $msg . '</label>' . apac_form_file_notice();
}

function apac_count( $table, $where, ...$args ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . apac_table( $table ) . ' WHERE ' . $where, ...$args ) );
}

/* ------------------------------------------------------------------------ */

function apac_render_portal() {
	ob_start();
	// "aps" = sistema grafico del sito: colori, caratteri e componenti vengono ereditati.
	echo '<div class="aps apac' . esc_attr( apac_pref_classes() ) . '">';

	if ( ! is_user_logged_in() ) {
		if ( function_exists( 'apac_rich_render_guest' ) && apac_rich_render_guest() ) {
			echo '</div>';
			return ob_get_clean();
		}
		apac_render_hero( 'Area riservata', 'Accedi con la tua email e la password per consultare documenti, scadenze e formazione della tua azienda.' );
		echo '<section class="aps-sec aps-alt apac-body"><div class="aps-wrap">';
		apac_render_login();
		echo '</div></section></div>';
		return ob_get_clean();
	}

	if ( ! apac_user_can_use_portal() ) {
		apac_render_hero( 'Area riservata', 'Il tuo account non è collegato a nessuna azienda.' );
		echo '<section class="aps-sec aps-alt apac-body"><div class="aps-wrap"><div class="apac-card apac-login"><h2>Accesso non abilitato</h2><p>Contatta AP Sicurezza Lavoro per attivare l’accesso.</p><p><a class="apac-btn" href="' . esc_url( apac_logout_url() ) . '">Esci</a></p></div></div></section></div>';
		return ob_get_clean();
	}

	// 1.7.2: firme richieste al primo accesso.
	if ( function_exists( 'apac_consensi_gate' ) && apac_consensi_gate() ) {
		echo '</div>';
		return ob_get_clean();
	}

	$sections = apac_sections();
	$default  = ( apac_is_admin() && empty( $_GET['azienda'] ) ) ? 'pannello' : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification
	$sez      = isset( $_GET['sez'] ) ? sanitize_key( wp_unslash( $_GET['sez'] ) ) : $default; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! isset( $sections[ $sez ] ) ) {
		$sez = 'dashboard';
	}
	$senza_azienda = in_array( $sez, apac_studio_sections(), true );
	$azienda_id = apac_current_azienda_id();
	$azienda    = $azienda_id ? apac_get_azienda( $azienda_id ) : null;
	$user       = wp_get_current_user();

	// Un solo titolo: nelle pagine dello studio il nome della sezione, altrimenti il nome dell'azienda.
	if ( $senza_azienda ) {
		$titolo = $sections[ $sez ];
		$kicker = 'Studio';
	} else {
		$titolo = $azienda ? $azienda->ragione_sociale : 'Area riservata';
		$kicker = $sections[ $sez ];
	}
	apac_render_topbar( $titolo, $kicker, $user, $azienda && ! $senza_azienda ? $azienda_id : 0, $sez );

	echo '<section class="aps-sec aps-alt apac-body"><div class="aps-wrap"><div class="apac-layout">';
	apac_render_sidenav( $sections, $sez, $azienda, $azienda_id );
	apac_render_tabbar( $sections, $sez, $azienda_id );
	echo '<div class="apac-main">';

	// Selettore azienda (un solo campo con elenco e ricerca) per lo studio.
	if ( apac_is_admin() && apac_count( 'aziende', '1 = %d', 1 ) ) {
		apac_render_switcher( $azienda_id, $sez );
	}

	// Messaggi.
	if ( isset( $_GET['apac_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$msgs = apac_messages();
		$code = sanitize_key( wp_unslash( $_GET['apac_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $msgs[ $code ] ) ) {
			$text = apac_message_text( $code, $msgs[ $code ][1] );
			$role = 'ok' === $msgs[ $code ][0] ? 'status' : 'alert';
			echo '<div class="apac-notice apac-notice--' . esc_attr( $msgs[ $code ][0] ) . '" role="' . $role . '">' . esc_html( $text ) . ( apac_form_failed() ? ' <small>I dati inseriti sono stati conservati nel modulo.</small>' : '' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}
	if ( apac_is_admin() ) {
		apac_render_search_results( $sez );
	}

	if ( ! ( 'lavoratori' === $sez && ! empty( $_GET['lav'] ) ) && ( $senza_azienda || $azienda ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		apac_section_lead( $sez );
	}
	if ( 'utenti' === $sez ) {
		apac_render_utenti( $azienda );
	} elseif ( 'pannello' === $sez ) {
		apac_render_pannello();
	} elseif ( 'richieste' === $sez ) {
		apac_render_richieste();
	} elseif ( 'installa' === $sez ) {
		apac_render_installa();
	} elseif ( 'elenco' === $sez ) {
		apac_render_elenco();
	} elseif ( ! $azienda ) {
		apac_render_scegli_azienda( $sez );
	} else {
		call_user_func( 'apac_render_' . $sez, $azienda );
	}

	echo '<p class="apac-disclaimer">Area riservata di supporto alla gestione della sicurezza sul lavoro: non sostituisce la valutazione del datore di lavoro, dell’RSPP e dei professionisti competenti.</p>';
	echo '</div></div></div></section></div>';
	return ob_get_clean();
}

/** Testata verde scuro come le pagine interne del sito. */
function apac_render_hero( $title, $text ) {
	echo '<section class="aps-phero apac-hero"><div class="aps-wrap">';
	echo '<div class="aps-crumb"><a href="' . esc_url( home_url( '/' ) ) . '">Home</a> › ' . esc_html( $title ) . '</div>';
	echo '<h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $text ) . '</p></div></section>';
}

function apac_render_login() {
	echo '<div class="apac-card apac-login"><h2>Accedi</h2>';
	apac_render_login_form();
	echo '<p><a href="' . esc_url( wp_lostpassword_url( apac_portal_page_url() ) ) . '">Password dimenticata?</a></p>';
	echo '<p class="apac-muted">Non hai un accesso? <a href="' . esc_url( add_query_arg( 'accesso', 'richiedi', apac_portal_page_url() ) ) . '">Richiedilo qui</a>: compili i dati della tua azienda e lo studio verifica la richiesta.</p></div>';
	echo '<div class="apac-login apac-login__install">';
	apac_render_install_card( false );
	echo '</div>';
}

/* ------------------------------ Dashboard ------------------------------ */

/** Scadenze aperte + attestati, ordinati per data, entro $giorni (anche scadute). */
function apac_agenda( $azienda_id, $giorni = 30 ) {
	global $wpdb;
	$limite = gmdate( 'Y-m-d', strtotime( apac_today() . ' +' . (int) $giorni . ' days' ) );
	$items  = array();

	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'scadenze' ) . " WHERE azienda_id = %d AND stato = 'aperta' AND data_scadenza <= %s", $azienda_id, $limite ) );
	foreach ( $rows as $r ) {
		$items[] = array( 'data' => $r->data_scadenza, 'titolo' => $r->titolo, 'tipo' => 'Scadenza', 'url' => apac_url( 'scadenziario' ) );
	}
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT a.*, l.nome, l.cognome FROM ' . apac_table( 'attestati' ) . ' a INNER JOIN ' . apac_table( 'lavoratori' ) . ' l ON l.id = a.lavoratore_id
			 WHERE a.azienda_id = %d AND l.attivo = 1 AND a.data_scadenza IS NOT NULL AND a.data_scadenza <= %s AND ' . apac_sql_attestato_corrente( 'a' ),
			$azienda_id,
			$limite
		)
	);
	foreach ( $rows as $r ) {
		$items[] = array( 'data' => $r->data_scadenza, 'titolo' => $r->corso . ' – ' . $r->cognome . ' ' . $r->nome, 'tipo' => 'Formazione', 'url' => apac_url( 'lavoratori', array( 'lav' => (int) $r->lavoratore_id ) ) );
	}
	usort(
		$items,
		function ( $a, $b ) {
			return strcmp( $a['data'], $b['data'] );
		}
	);
	return $items;
}

/**
 * 1.9.9: il cliente vede con un'etichetta esplicita cosa può fare con il proprio accesso
 * (sola consultazione oppure le modifiche consentite dallo studio per la sua azienda).
 * Stesse regole applicate dal server in apac_handle_action / apac_client_can.
 */
function apac_render_permessi_cliente( $az ) {
	if ( apac_is_admin() ) {
		return;
	}
	$sempre = 'consultare dati e documenti, scaricarli, firmare per presa visione, inviare segnalazioni e richieste di corsi';
	if ( apac_user_readonly() ) {
		echo '<details class="apac-card apac-perm"><summary><span class="apac-badge apac-badge--muted">Solo consultazione</span><span>Il tuo accesso permette di consultare, non di modificare.</span></summary>';
		echo '<p>Puoi ' . esc_html( $sempre ) . '. Non puoi inserire, modificare o eliminare dati e documenti: per una correzione invia una <a href="' . esc_url( apac_url( 'segnalazioni' ) ) . '">segnalazione</a> allo studio.</p></details>';
		return;
	}
	$perm = apac_get_permessi( $az );
	$si   = array();
	$no   = array();
	$nomi = array( 'azienda' => 'scheda azienda', 'lavoratori' => 'lavoratori e attestati', 'scadenze' => 'scadenze', 'documenti' => 'documenti' );
	foreach ( $nomi as $k => $n ) {
		if ( ! empty( $perm[ $k ] ) ) {
			$si[] = $n;
		} else {
			$no[] = $n;
		}
	}
	echo '<details class="apac-card apac-perm"><summary><span class="apac-badge apac-badge--ok">Il tuo accesso</span><span>' . esc_html( $si ? 'Puoi modificare: ' . implode( ', ', $si ) . '.' : 'Puoi consultare i dati; le modifiche le fa lo studio.' ) . '</span></summary>';
	echo '<p>Puoi sempre ' . esc_html( $sempre ) . '.' . ( $no ? ' Sono gestiti solo dallo studio: ' . esc_html( implode( ', ', $no ) ) . '. Per una correzione invia una <a href="' . esc_url( apac_url( 'segnalazioni' ) ) . '">segnalazione</a>.' : '' ) . '</p></details>';
}

function apac_render_dashboard( $az ) {
	$id     = (int) $az->id;
	$today  = apac_today();
	$in30   = gmdate( 'Y-m-d', strtotime( $today . ' +30 days' ) );
	$agenda = apac_agenda( $id, 30 );
	apac_render_install_card( true );
	apac_render_push_invite();
	apac_render_permessi_cliente( $az );

	$scadute = 0;
	$entro30 = 0;
	foreach ( $agenda as $i ) {
		if ( $i['data'] < $today ) {
			$scadute++;
		} else {
			$entro30++;
		}
	}

	// Situazione in una riga: la prima cosa da sapere.
	if ( $scadute ) {
		$st = array( 'danger', 'alert', 1 === $scadute ? 'C’è 1 scadenza superata' : 'Ci sono ' . $scadute . ' scadenze superate', 'Vanno gestite al più presto.' );
	} elseif ( $entro30 ) {
		$st = array( 'warn', 'calendar', 1 === $entro30 ? '1 scadenza nei prossimi 30 giorni' : $entro30 . ' scadenze nei prossimi 30 giorni', 'Nessuna scadenza superata. Organizza per tempo quelle in arrivo.' );
	} else {
		$st = array( 'ok', 'check', 'Nessuna scadenza superata o imminente', 'Nei prossimi 30 giorni non risultano scadenze tra quelle inserite.' );
	}
	$st_url = $scadute ? apac_url( 'scadenziario', array( 'periodo' => 'superate' ) ) : ( $entro30 ? apac_url( 'scadenziario', array( 'periodo' => '30' ) ) : apac_url( 'scadenziario' ) );
	echo '<a class="apac-status apac-status--' . esc_attr( $st[0] ) . '" href="' . esc_url( $st_url ) . '"><span class="apac-status__ico">' . apac_icon( $st[1] ) . '</span><span class="apac-status__txt"><strong>' . esc_html( $st[2] ) . '</strong><small>' . esc_html( $st[3] ) . '</small></span>' . apac_icon( 'arrow' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput

	// Documenti da firmare (presa visione).
	$da_firmare = apac_documenti_da_firmare( $id );
	if ( $da_firmare ) {
		$n_f = count( $da_firmare );
		echo '<a class="apac-status apac-status--warn apac-status--sign" href="' . esc_url( apac_url( 'documenti', array( 'firma' => (int) $da_firmare[0]->id ) ) . '#apac-firma-' . (int) $da_firmare[0]->id ) . '"><span class="apac-status__ico">' . apac_icon_extra( 'pen' ) . '</span><span class="apac-status__txt"><strong>' . esc_html( 1 === $n_f ? '1 documento da leggere e firmare' : $n_f . ' documenti da leggere e firmare' ) . '</strong><small>' . esc_html( $da_firmare[0]->titolo . ( $n_f > 1 ? ' e altri' : '' ) ) . '</small></span>' . apac_icon( 'arrow' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	apac_render_primi_passi( $az );
	apac_render_search_box();

	// Azioni rapide senza doppioni: gli inserimenti sono nel pulsante "+ Aggiungi" in alto.
	$quick   = array();
	$quick[] = array( 'message', apac_is_admin() ? 'Scrivi una segnalazione' : 'Scrivi allo studio', apac_add_url( 'segnalazioni', 'segnalazione_create' ) );
	$quick[] = array( 'check', 'Richiedi un corso', apac_add_url( 'segnalazioni', 'corso_richiesta' ) );
	$quick[] = array( 'print', 'Riepilogo dati e scadenze (stampa o PDF)', apac_report_url( $id ) );
	echo '<div class="apac-quick" role="group" aria-label="Azioni rapide">';
	foreach ( array_slice( $quick, 0, 4 ) as $q ) {
		echo '<a class="apac-quick__item" href="' . esc_url( $q[2] ) . '">' . apac_icon_extra( $q[0] ) . '<span>' . esc_html( $q[1] ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</div>';

	// Un solo elenco "Da fare": scadenze (superate e entro 30 giorni) + criticità alte.
	$crit  = array_values(
		array_filter(
			apac_analizza_criticita( $id ),
			function ( $c ) {
				return 'alta' === $c['livello'];
			}
		)
	);
	$n_tot = count( $agenda ) + count( $crit );
	echo '<section class="apac-card apac-todo"><div class="apac-todo__head"><h2>Da fare</h2>' . ( $n_tot ? '<span class="apac-badge apac-badge--muted">' . (int) $n_tot . '</span>' : '' ) . '</div>';
	if ( ! $n_tot ) {
		echo '<p class="apac-muted">Tutto in ordine: nessuna scadenza nei prossimi 30 giorni e nessuna criticità alta sui dati inseriti.</p>';
	} else {
		echo '<ul class="apac-list">';
		foreach ( array_slice( $agenda, 0, 4 ) as $i ) {
			echo '<li><a href="' . esc_url( $i['url'] ) . '"><span>' . esc_html( $i['titolo'] ) . '<small>' . esc_html( $i['tipo'] ) . ' · ' . esc_html( apac_date_it( $i['data'] ) ) . '</small></span>' . apac_badge_scadenza( $i['data'] ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		foreach ( array_slice( $crit, 0, 2 ) as $c ) {
			echo '<li><a href="' . esc_url( apac_url( $c['sez'] ) ) . '"><span>' . esc_html( $c['titolo'] ) . '<small>Criticità' . ( $c['norma'] ? ' · ' . esc_html( $c['norma'] ) : '' ) . '</small></span>' . apac_badge_livello( $c['livello'] ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul>';
	}
	$altre = max( 0, count( $agenda ) - 4 ) + max( 0, count( $crit ) - 2 );
	if ( $altre ) {
		echo '<p class="apac-muted apac-todo__more">' . ( 1 === (int) $altre ? 'E un’altra voce' : 'E altre ' . (int) $altre . ' voci' ) . ' negli elenchi completi.</p>';
	}
	echo '<p class="apac-todo__links"><a class="apac-more-link" href="' . esc_url( apac_url( 'scadenziario' ) ) . '">Tutte le scadenze →</a><a class="apac-more-link" href="' . esc_url( apac_url( 'criticita' ) ) . '">Analisi criticità →</a></p></section>';

	// Numeri essenziali in una riga.
	// Contatori: ognuno apre l'elenco che contiene esattamente quelle voci.
	global $wpdb;
	$n_att = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . apac_table( 'attestati' ) . ' a INNER JOIN ' . apac_table( 'lavoratori' ) . ' l ON l.id = a.lavoratore_id WHERE a.azienda_id = %d AND l.attivo = 1 AND a.data_scadenza IS NOT NULL AND ' . apac_sql_attestato_corrente( 'a' ), $id ) );
	$cards = array(
		array( 'Lavoratori in forza', apac_count( 'lavoratori', 'azienda_id = %d AND attivo = 1', $id ), apac_url( 'lavoratori' ) ),
		array( 'Attestati con scadenza', $n_att, apac_url( 'scadenziario' ) . '#apac-formazione' ),
		array( 'Documenti', apac_count( 'documenti', 'azienda_id = %d', $id ), apac_url( 'documenti' ) ),
		array( 'Segnalazioni aperte', apac_count( 'segnalazioni', "azienda_id = %d AND stato <> 'chiusa'", $id ), apac_url( 'segnalazioni', array( 'stato' => 'aperte' ) ) ),
	);
	echo '<div class="apac-mini" role="group" aria-label="In numeri: tocca per vedere l’elenco">';
	foreach ( $cards as $c ) {
		echo '<a class="apac-mini__item" href="' . esc_url( $c[2] ) . '"><strong>' . (int) $c[1] . '</strong><span>' . esc_html( $c[0] ) . '</span></a>';
	}
	echo '</div>';
	apac_render_completezza( $az );
}

function apac_badge_livello( $l ) {
	$map = array(
		'alta'  => array( 'danger', 'Alta' ),
		'media' => array( 'warn', 'Media' ),
		'bassa' => array( 'muted', 'Bassa' ),
	);
	$m = isset( $map[ $l ] ) ? $map[ $l ] : $map['bassa'];
	return '<span class="apac-badge apac-badge--' . $m[0] . '">' . $m[1] . '</span>';
}

/* ------------------------------- Azienda ------------------------------- */

function apac_render_azienda( $az ) {
	if ( apac_client_can( $az->id, 'azienda' ) ) {
		echo '<section class="apac-card">' . apac_form_open( 'azienda_save', $az->id ); // phpcs:ignore WordPress.Security.EscapeOutput
		apac_render_azienda_fields( $az );
		echo '<div class="apac-actions"><button class="apac-btn" type="submit">Salva</button></div></form></section>';
	} else {
		echo '<section class="apac-card"><p class="apac-muted">La scheda è gestita da AP Sicurezza Lavoro. Per correzioni invia una <a href="' . esc_url( apac_url( 'segnalazioni' ) ) . '">segnalazione</a>.</p><fieldset disabled class="apac-readonly">';
		apac_render_azienda_fields( $az, true );
		echo '</fieldset></section>';
	}

	if ( apac_is_admin() ) {
		$perms = apac_get_permessi( $az );
		echo '<section class="apac-card"><h2>Cosa può modificare il cliente</h2>' . apac_form_open( 'azienda_permessi', $az->id ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="apac-perms">';
		foreach ( apac_permessi_defaults() as $k => $def ) {
			echo '<label class="apac-field apac-check"><input type="checkbox" name="perm_' . esc_attr( $k ) . '" value="1"' . checked( $perms[ $k ], true, false ) . '><span>' . esc_html( $def[1] ) . '</span></label>';
		}
		echo '</div><p class="apac-muted">Consultare documenti, scadenze e criticità e inviare segnalazioni è sempre consentito. Tu, come amministratore, puoi modificare tutto.</p>';
		echo '<div class="apac-actions"><button class="apac-btn" type="submit">Salva permessi</button></div></form></section>';

		$log = apac_get_log( (int) $az->id, 50 );
		echo '<details class="apac-card apac-fold"><summary><span>Registro attività dell’azienda</span><small>' . ( $log ? count( $log ) . ' più recenti · tocca per aprire' : 'nessuna' ) . '</small></summary><div class="apac-fold__body">';
		apac_render_log_table( $log, false );
		echo '</div></details>';
	}

	if ( ! apac_is_admin() && function_exists( 'apac_rich_render_collega' ) ) {
		apac_rich_render_collega( $az );
	}

	if ( apac_is_admin() ) {
		echo '<section class="apac-card apac-danger-zone"><h2>Elimina azienda</h2><p>Elimina definitivamente l’azienda con lavoratori, attestati, documenti, scadenze e segnalazioni. Gli utenti collegati perdono l’accesso. L’operazione non si può annullare.</p>';
		echo apac_form_open( 'azienda_delete', $az->id ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo apac_field( 'Scrivi ELIMINA per confermare', 'conferma', '', 'text', 'autocomplete="off"' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="apac-actions"><button class="apac-btn apac-btn--danger" type="submit">Elimina azienda</button></div></form></section>';
	}
}

function apac_render_azienda_fields( $az = null, $open = false ) {
	$v = function ( $k ) use ( $az ) {
		return $az && isset( $az->$k ) ? $az->$k : '';
	};
	$cnt = function ( $keys ) use ( $az, $v ) {
		if ( ! $az ) {
			return -1;
		}
		$n = 0;
		foreach ( $keys as $k ) {
			$n += '' !== trim( (string) $v( $k ) ) ? 1 : 0;
		}
		return $n;
	};
	// phpcs:disable WordPress.Security.EscapeOutput
	echo '<p class="apac-muted apac-req-note">I campi con * sono obbligatori. Gli altri gruppi si aprono quando servono.</p><div class="apac-fields">';
	echo apac_field( 'Ragione sociale *', 'ragione_sociale', $v( 'ragione_sociale' ), 'text', 'required' );
	echo apac_field( 'Partita IVA', 'partita_iva', $v( 'partita_iva' ) );
	echo apac_field( 'Codice fiscale', 'codice_fiscale', $v( 'codice_fiscale' ) );
	echo apac_field( 'Comune', 'comune', $v( 'comune' ) );
	echo '</div>';
	$g = array( 'ateco' );
	echo apac_group_open( 'Altri dati aziendali', $g, $open, $cnt( $g ) );
	echo apac_field( 'Codice ATECO', 'ateco', $v( 'ateco' ) );
	echo apac_group_close();
	$g = array( 'indirizzo', 'provincia', 'telefono', 'email', 'pec' );
	echo apac_group_open( 'Contatti', $g, $open, $cnt( $g ) );
	echo apac_field( 'Indirizzo sede', 'indirizzo', $v( 'indirizzo' ) );
	echo apac_field( 'Provincia', 'provincia', $v( 'provincia' ), 'text', 'maxlength="5"' );
	echo apac_field( 'Telefono', 'telefono', $v( 'telefono' ), 'tel' );
	echo apac_field( 'Email', 'email', $v( 'email' ), 'email' );
	echo apac_field( 'PEC', 'pec', $v( 'pec' ), 'email' );
	echo apac_group_close();
	$g = array( 'datore_lavoro', 'rspp_nome', 'rspp_tipo', 'rls_nome', 'rls_tipo', 'sorveglianza_sanitaria', 'medico_competente' );
	echo apac_group_open( 'Figure della sicurezza', $g, $open, $cnt( $g ) );
	echo apac_field( 'Datore di lavoro', 'datore_lavoro', $v( 'datore_lavoro' ) );
	echo apac_field( 'RSPP', 'rspp_nome', $v( 'rspp_nome' ) );
	echo apac_select(
		'Tipo RSPP',
		'rspp_tipo',
		array(
			''        => '—',
			'interno' => 'Interno',
			'esterno' => 'Esterno',
			'datore'  => 'Datore di lavoro (DL-SPP)',
		),
		$v( 'rspp_tipo' )
	);
	echo apac_field( 'RLS / RLST', 'rls_nome', $v( 'rls_nome' ) );
	echo apac_select(
		'Tipo rappresentante',
		'rls_tipo',
		array(
			''     => '—',
			'RLS'  => 'RLS aziendale',
			'RLST' => 'RLST territoriale',
		),
		$v( 'rls_tipo' )
	);
	echo apac_select(
		'Sorveglianza sanitaria prevista',
		'sorveglianza_sanitaria',
		array(
			''   => 'Non indicato',
			'si' => 'Sì',
			'no' => 'No',
		),
		$v( 'sorveglianza_sanitaria' )
	);
	echo apac_field( 'Medico competente', 'medico_competente', $v( 'medico_competente' ) );
	echo apac_group_close();
	$g = array( 'addetti_antincendio', 'addetti_primo_soccorso' );
	echo apac_group_open( 'Addetti alle emergenze', $g, $open, $cnt( $g ) );
	echo apac_textarea( 'Addetti antincendio ed evacuazione', 'addetti_antincendio', $v( 'addetti_antincendio' ), 'Un nominativo per riga.' );
	echo apac_textarea( 'Addetti primo soccorso', 'addetti_primo_soccorso', $v( 'addetti_primo_soccorso' ), 'Un nominativo per riga.' );
	echo apac_group_close();
	$g = array( 'note' );
	echo apac_group_open( 'Note', $g, $open, $cnt( $g ) );
	echo apac_textarea( 'Note', 'note', $v( 'note' ) );
	echo apac_group_close();
	// phpcs:enable
}

/* ------------------------------ Lavoratori ----------------------------- */

function apac_render_lavoratori( $az ) {
	global $wpdb;
	$id     = (int) $az->id;
	$lav_id = isset( $_GET['lav'] ) ? absint( $_GET['lav'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	$lav    = $lav_id ? apac_get_row_for_azienda( 'lavoratori', $lav_id, $id ) : null;

	if ( $lav ) {
		apac_render_lavoratore_dettaglio( $az, $lav );
		return;
	}
	$can = apac_client_can( $id, 'lavoratori' );
	// Anteprima di un'importazione in corso: è l'unica cosa da guardare.
	if ( $can && ! empty( $_GET['import'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		apac_render_import_box( $az );
		return;
	}

	$lavoratori = apac_get_lavoratori( $id );
	if ( $can ) {
		echo apac_add_box_open( 'lavoratore_save', 'Aggiungi lavoratore', ! $lavoratori ) . '<section class="apac-card"><h2>Nuovo lavoratore</h2>' . apac_form_open( 'lavoratore_save', $id ); // phpcs:ignore WordPress.Security.EscapeOutput
		apac_render_lavoratore_fields();
		echo '<div class="apac-actions"><button class="apac-btn" type="submit">Aggiungi lavoratore</button></div></form></section>' . apac_add_box_close(); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	apac_render_excel_tools( $az, $can, (bool) $lavoratori );
	apac_render_mansioni_box( $az );
	$mc = apac_mansioni_corsi( $id );

	// Dati per filtri e ordinamento.
	$rows     = array();
	$mansioni = array();
	foreach ( $lavoratori as $l ) {
		$n    = apac_count( 'attestati', 'azienda_id = %d AND lavoratore_id = %d', $id, $l->id );
		$prox = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(a.data_scadenza) FROM ' . apac_table( 'attestati' ) . ' a WHERE a.azienda_id = %d AND a.lavoratore_id = %d AND a.data_scadenza IS NOT NULL AND ' . apac_sql_attestato_corrente( 'a' ), $id, $l->id ) );
		$rows[] = array( $l, $n, $prox, apac_stato_formazione( $n, $prox ), $mc ? count( array_diff( apac_formazione_richiesta( $id, $l, $mc ), array( 'ok' ) ) ) : 0 );
		if ( '' !== $l->mansione ) {
			$mansioni[ $l->mansione ] = $l->mansione;
		}
	}
	natcasesort( $mansioni );
	$f_stato = apac_filter_val( 'stato', array( 'attivi', 'cessati', 'tutti' ), 'attivi' );
	$f_form  = apac_filter_val( 'formazione', array( '', 'scaduta', 'in_scadenza', 'nessuno', 'mancante', 'ok' ) );
	$f_mans  = isset( $_GET['mansione'] ) ? sanitize_text_field( wp_unslash( $_GET['mansione'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$f_mans  = isset( $mansioni[ $f_mans ] ) || '-' === $f_mans ? $f_mans : '';
	$f_ord   = apac_filter_val( 'ordina', array( 'cognome', 'scadenza', 'mansione' ), 'cognome' );

	$tot = count( $rows );
	$rows = array_filter(
		$rows,
		function ( $r ) use ( $f_stato, $f_form, $f_mans ) {
			if ( ( 'attivi' === $f_stato && ! $r[0]->attivo ) || ( 'cessati' === $f_stato && $r[0]->attivo ) ) {
				return false;
			}
			if ( 'mancante' === $f_form ) {
				if ( ! $r[4] ) {
					return false;
				}
			} elseif ( '' !== $f_form && ( 'ok' === $f_form ? ! in_array( $r[3], array( 'ok', 'senza_scadenza' ), true ) : $r[3] !== $f_form ) ) {
				return false;
			}
			if ( '-' === $f_mans ) {
				return '' === $r[0]->mansione;
			}
			return '' === $f_mans || $r[0]->mansione === $f_mans;
		}
	);
	if ( 'scadenza' === $f_ord ) {
		usort( $rows, function ( $a, $b ) { return strcmp( $a[2] ? $a[2] : '9999', $b[2] ? $b[2] : '9999' ); } );
	} elseif ( 'mansione' === $f_ord ) {
		usort( $rows, function ( $a, $b ) { return strnatcasecmp( $a[0]->mansione . '|' . $a[0]->cognome, $b[0]->mansione . '|' . $b[0]->cognome ); } );
	}

	echo '<section class="apac-card"><h2>Elenco lavoratori</h2>';
	if ( $tot ) {
		apac_render_filterbar(
			'lavoratori',
			array(
				'stato'      => array( 'Mostra', array( 'attivi' => 'In forza', 'cessati' => 'Cessati', 'tutti' => 'Tutti' ), $f_stato ),
				'formazione' => array( 'Formazione', array( '' => 'Qualsiasi', 'scaduta' => 'Corso scaduto', 'in_scadenza' => 'In scadenza (30 giorni)', 'nessuno' => 'Nessun attestato' ) + ( $mc ? array( 'mancante' => 'Corsi richiesti mancanti' ) : array() ) + array( 'ok' => 'Nessuna scadenza formativa rilevata' ), $f_form ),
				'mansione'   => array( 'Mansione', array( '' => 'Tutte' ) + $mansioni + array( '-' => 'Da definire' ), $f_mans ),
				'ordina'     => array( 'Ordina per', array( 'cognome' => 'Cognome', 'scadenza' => 'Prossima scadenza', 'mansione' => 'Mansione' ), $f_ord ),
			)
		);
		$fn    = array( 'stato', 'formazione', 'mansione', 'ordina' );
		$chips = array();
		$lab_s = array( 'cessati' => 'Cessati', 'tutti' => 'In forza e cessati' );
		$lab_f = array( 'scaduta' => 'Corso scaduto', 'in_scadenza' => 'In scadenza', 'nessuno' => 'Nessun attestato', 'mancante' => 'Corsi richiesti mancanti', 'ok' => 'Nessuna scadenza formativa rilevata' );
		$lab_o = array( 'scadenza' => 'Ordinati per scadenza', 'mansione' => 'Ordinati per mansione' );
		if ( 'attivi' !== $f_stato ) {
			$chips[] = array( $lab_s[ $f_stato ], apac_url_filters( 'lavoratori', $fn, 'stato' ) );
		}
		if ( '' !== $f_form ) {
			$chips[] = array( $lab_f[ $f_form ], apac_url_filters( 'lavoratori', $fn, 'formazione' ) );
		}
		if ( '' !== $f_mans ) {
			$chips[] = array( '-' === $f_mans ? 'Mansione da definire' : $f_mans, apac_url_filters( 'lavoratori', $fn, 'mansione' ) );
		}
		if ( 'cognome' !== $f_ord ) {
			$chips[] = array( $lab_o[ $f_ord ], apac_url_filters( 'lavoratori', $fn, 'ordina' ) );
		}
		apac_render_filter_summary( 'attivi' === $f_stato ? 'In forza' : 'Lavoratori', $chips, count( $rows ), apac_url( 'lavoratori' ) );
	}
	if ( ! $tot || ! $rows ) {
		apac_render_empty( (bool) $tot, 'Nessun lavoratore registrato.', apac_url( 'lavoratori' ) );
	} else {
		// Stato formazione per lavoratore: attestati validi scaduti / in scadenza.
		$today = apac_today();
		$in30  = gmdate( 'Y-m-d', strtotime( $today . ' +30 days' ) );
		$st    = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT a.lavoratore_id, SUM(CASE WHEN a.data_scadenza < %s THEN 1 ELSE 0 END) AS scad, SUM(CASE WHEN a.data_scadenza >= %s AND a.data_scadenza <= %s THEN 1 ELSE 0 END) AS pross FROM ' . apac_table( 'attestati' ) . ' a WHERE a.azienda_id = %d AND a.data_scadenza IS NOT NULL AND ' . apac_sql_attestato_corrente( 'a' ) . ' GROUP BY a.lavoratore_id', $today, $today, $in30, $id ) ) as $x ) {
			$st[ (int) $x->lavoratore_id ] = array( (int) $x->scad, (int) $x->pross );
		}
		$keep = array();
		foreach ( $fn as $k ) {
			if ( isset( $_GET[ $k ] ) && '' !== $_GET[ $k ] ) { // phpcs:ignore WordPress.Security.NonceVerification
				$keep[ $k ] = sanitize_text_field( wp_unslash( $_GET[ $k ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			}
		}
		echo '<ul class="apac-rows" data-apac-keep-pos>';
		foreach ( $rows as $r ) {
			list( $l, $n, $prox, , $gap ) = $r;
			$url = apac_url( 'lavoratori', array_merge( $keep, array( 'lav' => (int) $l->id ) ) );
			$s2  = isset( $st[ (int) $l->id ] ) ? $st[ (int) $l->id ] : array( 0, 0 );
			if ( ! $n ) {
				$badge = '<span class="apac-badge apac-badge--warn">Nessun attestato</span>';
			} elseif ( $s2[0] ) {
				$badge = '<span class="apac-badge apac-badge--danger">' . ( 1 === $s2[0] ? '1 corso scaduto' : $s2[0] . ' corsi scaduti' ) . '</span>';
			} elseif ( $s2[1] ) {
				$badge = '<span class="apac-badge apac-badge--warn">' . ( 1 === $s2[1] ? '1 corso in scadenza' : $s2[1] . ' corsi in scadenza' ) . '</span>';
			} else {
				$badge = '<span class="apac-badge apac-badge--muted">Nessuna scadenza formativa rilevata</span>';
			}
			if ( $gap ) {
				$badge .= '<span class="apac-badge apac-badge--warn">' . ( 1 === $gap ? '1 corso richiesto mancante' : (int) $gap . ' corsi richiesti mancanti' ) . '</span>';
			}
			if ( ! $l->attivo ) {
				$badge .= '<span class="apac-badge apac-badge--muted">' . esc_html( ! empty( $l->data_cessazione ) ? 'Cessato il ' . apac_date_it( $l->data_cessazione ) : 'Cessato' ) . '</span>';
			}
			$sub = ( '' !== $l->mansione ? $l->mansione : 'Mansione da definire' ) . ' · ' . ( 1 === (int) $n ? '1 attestato' : (int) $n . ' attestati' );
			echo '<li class="apac-row' . ( $l->attivo ? '' : ' is-done' ) . '"><a class="apac-row__main apac-rowlink" href="' . esc_url( $url ) . '"><span class="apac-row__txt"><strong class="apac-cell-title">' . esc_html( $l->cognome . ' ' . $l->nome ) . '</strong><small>' . esc_html( $sub ) . '</small></span><span class="apac-row__badges">' . $badge . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
			if ( $can ) {
				// phpcs:disable WordPress.Security.EscapeOutput
				echo '<details class="apac-doc__more apac-row__more"><summary class="apac-btn apac-btn--small apac-btn--ghost" aria-label="' . esc_attr( 'Altre azioni per ' . $l->cognome . ' ' . $l->nome ) . '">' . apac_icon( 'more' ) . '<span>Altro</span></summary><div class="apac-doc__panel">';
				echo '<a class="apac-btn apac-btn--small apac-btn--ghost" href="' . esc_url( add_query_arg( 'modifica', 1, $url ) . '#apac-modifica' ) . '">Modifica</a>';
				if ( $l->attivo ) {
					echo '<details class="apac-edit"><summary class="apac-btn apac-btn--small apac-btn--ghost">Segna come cessato</summary>' . apac_cessa_form( $l, $id ) . '</details>';
				} else {
					echo apac_button_form( 'lavoratore_riattiva', $id, array( 'id' => $l->id ), 'Riporta in forza', 'apac-btn apac-btn--small apac-btn--ghost' );
				}
				echo '</div></details>';
				// phpcs:enable
			}
			echo '</li>';
		}
		echo '</ul>';
	}
	echo '</section>';
}

function apac_render_lavoratore_fields( $l = null, $open = false ) {
	// phpcs:disable WordPress.Security.EscapeOutput
	echo '<div class="apac-fields">';
	echo apac_field( 'Nome *', 'nome', $l ? $l->nome : '', 'text', 'required' );
	echo apac_field( 'Cognome *', 'cognome', $l ? $l->cognome : '', 'text', 'required' );
	echo apac_field( 'Mansione', 'mansione', $l ? $l->mansione : '' );
	echo '</div>';
	$altri  = array( 'codice_fiscale', 'data_assunzione', 'attivo', 'data_cessazione', 'note' );
	$filled = $l ? count( array_filter( array( $l->codice_fiscale, $l->data_assunzione, $l->note ) ) ) : 0;
	echo apac_group_open( 'Altri dati: codice fiscale, assunzione, stato, note', $altri, $open, $l ? $filled : -1 );
	echo apac_field( 'Codice fiscale', 'codice_fiscale', $l ? $l->codice_fiscale : '', 'text', 'maxlength="16"' );
	echo apac_field( 'Data di assunzione', 'data_assunzione', $l ? $l->data_assunzione : '', 'date' );
	echo apac_checkbox( 'In forza', 'attivo', $l ? (int) $l->attivo : 1 );
	if ( $l ) {
		echo apac_field( 'Data di cessazione (se non in forza)', 'data_cessazione', isset( $l->data_cessazione ) ? (string) $l->data_cessazione : '', 'date' );
	}
	echo apac_textarea( 'Note', 'note', $l ? $l->note : '' );
	echo apac_group_close();
	// phpcs:enable
}

function apac_render_lavoratore_dettaglio( $az, $l ) {
	global $wpdb;
	$id  = (int) $az->id;
	$can = apac_client_can( $id, 'lavoratori' );
	echo '<p class="apac-back"><a href="' . esc_url( apac_url_filters( 'lavoratori', array( 'stato', 'formazione', 'mansione', 'ordina' ) ) ) . '">← Torna all’elenco dei lavoratori</a></p>';
	$info = array( '' !== $l->mansione ? $l->mansione : 'Mansione da definire', $l->attivo ? 'In forza' : ( ! empty( $l->data_cessazione ) ? 'Cessato il ' . apac_date_it( $l->data_cessazione ) : 'Cessato' ) );
	if ( $l->data_assunzione ) {
		$info[] = 'Assunto il ' . apac_date_it( $l->data_assunzione );
	}
	echo '<section class="apac-card apac-person"><h2>' . esc_html( $l->cognome . ' ' . $l->nome ) . '</h2><p class="apac-person__info">' . esc_html( implode( ' · ', $info ) ) . '</p>';
	$edit_open = '' !== apac_details_open( 'lavoratore_save', 'id:' . (int) $l->id ) || ! empty( $_GET['modifica'] ); // phpcs:ignore WordPress.Security.NonceVerification
	echo '<div class="apac-person__actions"><details class="apac-edit apac-edit--block" id="apac-modifica"' . ( $edit_open ? ' open' : '' ) . '><summary class="apac-btn apac-btn--small apac-btn--ghost">' . ( $can ? 'Modifica dati' : 'Vedi tutti i dati' ) . '</summary>'; // phpcs:ignore WordPress.Security.EscapeOutput
	if ( $can ) {
		echo apac_form_open( 'lavoratore_save', $id ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo apac_hidden( 'id', (int) $l->id ); // phpcs:ignore WordPress.Security.EscapeOutput
		apac_render_lavoratore_fields( $l );
		echo '<div class="apac-actions"><button class="apac-btn" type="submit">Salva</button></div></form>';
		echo '<p class="apac-muted">Se il lavoratore non è più in forza usa “Segna come cessato”: lo storico resta. “Elimina” cancella invece anche tutti i suoi attestati.</p>';
		echo apac_button_form( 'lavoratore_delete', $id, array( 'id' => $l->id ), 'Elimina lavoratore', 'apac-btn apac-btn--small apac-btn--danger', 'Eliminare il lavoratore e tutti i suoi attestati? Per conservare lo storico usa invece “Segna come cessato”.' ); // phpcs:ignore WordPress.Security.EscapeOutput
	} else {
		echo '<fieldset disabled class="apac-readonly">';
		apac_render_lavoratore_fields( $l, true );
		echo '</fieldset>';
	}
	echo '</details>';
	if ( $can ) {
		if ( $l->attivo ) {
			echo '<details class="apac-edit"' . apac_details_open( 'lavoratore_cessa', 'id:' . (int) $l->id ) . '><summary class="apac-btn apac-btn--small apac-btn--ghost">Segna come cessato</summary>' . apac_cessa_form( $l, $id ) . '</details>'; // phpcs:ignore WordPress.Security.EscapeOutput
		} else {
			echo apac_button_form( 'lavoratore_riattiva', $id, array( 'id' => $l->id ), 'Riporta in forza', 'apac-btn apac-btn--small apac-btn--ghost' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}
	echo '</div></section>';
	apac_render_formazione_richiesta( $az, $l );

	$corsi = array( '' => '— Seleziona —' );
	foreach ( apac_corsi() as $c ) {
		$corsi[ $c ] = $c;
	}

	$att = $wpdb->get_results( $wpdb->prepare( 'SELECT a.*, (' . apac_sql_attestato_corrente( 'a' ) . ') AS corrente FROM ' . apac_table( 'attestati' ) . ' a WHERE a.azienda_id = %d AND a.lavoratore_id = %d ORDER BY a.corso ASC, a.data_conseguimento DESC, a.id DESC', $id, $l->id ) );
	$can_files = apac_client_can( $id, 'documenti' );
	echo '<section class="apac-card"><h2>Attestati di formazione</h2>';
	if ( $can ) {
	// phpcs:disable WordPress.Security.EscapeOutput
	echo apac_add_box_open( 'attestato_save', 'Registra attestato', ! $att, 'lavoratore_id:' . (int) $l->id ) . '<div class="apac-subcard">' . apac_form_open( 'attestato_save', $id, true );
	echo apac_hidden( 'lavoratore_id', (int) $l->id ) . '<div class="apac-fields">';
	echo apac_select( 'Corso *', 'corso', $corsi, '', 'required' );
	echo apac_field( 'Se “Altro”, specifica', 'corso_altro', '', 'text', 'maxlength="150"' );
	echo apac_field( 'Data di conseguimento *', 'data_conseguimento', '', 'date', 'required max="' . esc_attr( apac_today() ) . '"' );
	echo apac_field( 'Data di scadenza / aggiornamento', 'data_scadenza', '', 'date' );
	echo apac_checkbox( 'Corso senza scadenza', 'senza_scadenza', false );
	if ( $can_files ) {
		echo apac_file_field( 'File attestato (facoltativo)', '.pdf,.jpg,.jpeg,.png,.doc,.docx,.odt' );
	}
	echo apac_textarea( 'Note', 'note' );
	echo '</div><p class="apac-muted">La data di scadenza va indicata secondo la periodicità di aggiornamento prevista per il corso specifico. Registrando un nuovo attestato dello stesso corso, quello precedente resta nello storico come “Rinnovato” e non genera più avvisi.</p>';
	echo '<div class="apac-actions"><button class="apac-btn" type="submit">Registra attestato</button></div></form></div>' . apac_add_box_close();
	// phpcs:enable
	}
	if ( ! $att ) {
		echo '<p class="apac-muted">Nessun attestato registrato.</p>';
	} else {
		echo '<div class="apac-table-wrap"><table class="apac-table"><thead><tr><th>Corso</th><th>Conseguito</th><th>Scadenza</th><th>Stato</th><th>File</th><th></th></tr></thead><tbody>';
		foreach ( $att as $a ) {
			echo '<tr><td data-label="Corso"><strong>' . esc_html( $a->corso ) . '</strong>' . ( $a->note ? '<br><small>' . esc_html( $a->note ) . '</small>' : '' ) . '</td>';
			echo '<td data-label="Conseguito">' . esc_html( apac_date_it( $a->data_conseguimento ) ) . '</td>';
			echo '<td data-label="Scadenza">' . esc_html( apac_date_it( $a->data_scadenza ) ) . '</td>';
			if ( ! (int) $a->corrente ) {
				$stato = '<span class="apac-badge apac-badge--muted">Rinnovato</span>';
			} elseif ( null === $a->data_scadenza ) {
				$stato = '<span class="apac-badge apac-badge--ok">Senza scadenza</span>';
			} else {
				$stato = apac_badge_scadenza( $a->data_scadenza );
			}
			echo '<td data-label="Stato">' . $stato . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			$file = '—';
			if ( $a->documento_id && apac_get_row_for_azienda( 'documenti', $a->documento_id, $id ) ) {
				$file = '<a href="' . esc_url( apac_file_url( $a->documento_id, 'view' ) ) . '" target="_blank" rel="noopener">Apri</a>';
			}
			echo '<td data-label="File">' . $file . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			if ( $can ) {
				$opts = $corsi;
				if ( ! isset( $opts[ $a->corso ] ) ) {
					$opts[ $a->corso ] = $a->corso;
				}
				// phpcs:disable WordPress.Security.EscapeOutput
				echo '<details class="apac-edit"' . apac_details_open( 'attestato_update', 'id:' . (int) $a->id ) . '><summary class="apac-btn apac-btn--small apac-btn--ghost">Modifica</summary>' . apac_form_open( 'attestato_update', $id, true );
				echo apac_hidden( 'id', (int) $a->id ) . '<div class="apac-fields">';
				echo apac_select( 'Corso', 'corso', $opts, $a->corso, 'required' );
				echo apac_field( 'Se “Altro”, specifica', 'corso_altro', '', 'text', 'maxlength="150"' );
				echo apac_field( 'Conseguito *', 'data_conseguimento', $a->data_conseguimento, 'date', 'required max="' . esc_attr( apac_today() ) . '"' );
				echo apac_field( 'Scadenza', 'data_scadenza', $a->data_scadenza, 'date' );
				echo apac_checkbox( 'Corso senza scadenza', 'senza_scadenza', null === $a->data_scadenza );
				if ( $can_files ) {
					echo apac_file_field( 'Sostituisci file (facoltativo)', '.pdf,.jpg,.jpeg,.png,.doc,.docx,.odt' );
				}
				echo apac_textarea( 'Note', 'note', $a->note );
				echo '</div><div class="apac-actions"><button class="apac-btn apac-btn--small" type="submit">Salva modifiche</button></div></form></details>';
				echo apac_button_form( 'attestato_delete', $id, array( 'id' => $a->id ), 'Elimina', 'apac-btn apac-btn--small apac-btn--ghost', 'Eliminare questo attestato? Il file resta nell’archivio documenti.' );
				// phpcs:enable
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
	echo '</section>';
}


/* ------------------------------ Documenti ------------------------------ */

function apac_render_documenti( $az ) {
	global $wpdb;
	$id   = (int) $az->id;
	$cats = apac_categorie_documenti();
	$cat  = isset( $_GET['cat'] ) ? sanitize_key( wp_unslash( $_GET['cat'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( $cat && ! isset( $cats[ $cat ] ) ) {
		$cat = '';
	}

	$can = apac_client_can( $id, 'documenti' );
	// phpcs:disable WordPress.Security.EscapeOutput
	if ( $can ) :
	echo apac_add_box_open( 'documento_upload', 'Carica documento', ! apac_count( 'documenti', 'azienda_id = %d', $id ), '', 'upload' ) . '<section class="apac-card"><h2>Carica documento</h2>' . apac_form_open( 'documento_upload', $id, true ) . '<div class="apac-fields">';
	echo apac_field( 'Titolo', 'titolo', '', 'text', 'placeholder="Se vuoto, si usa il nome del file"' );
	echo apac_select( 'Categoria *', 'categoria', $cats, $cat ? $cat : 'altro' );
	echo apac_file_field( 'File * (max ' . size_format( wp_max_upload_size() ) . ')', '.pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.jpg,.jpeg,.png', true, true );
	if ( apac_is_admin() ) {
		echo apac_checkbox( 'Chiedi ai clienti di leggere e firmare (presa visione)', 'richiede_firma', false );
	}
	echo '</div><p class="apac-muted">Non caricare certificati medici, giudizi di idoneità o altri dati sanitari.</p><div class="apac-actions"><button class="apac-btn" type="submit">Carica</button></div></form></section>' . apac_add_box_close();
	endif;
	// phpcs:enable

	if ( function_exists( 'apac_render_consensi_azienda' ) ) {
		apac_render_consensi_azienda( $az );
	}
	echo '<section class="apac-card"><h2>Archivio</h2><div class="apac-filters"><a href="' . esc_url( apac_url( 'documenti' ) ) . '"' . ( $cat ? '' : ' class="is-active"' ) . '>Tutti</a>';
	foreach ( $cats as $k => $label ) {
		$n = apac_count( 'documenti', 'azienda_id = %d AND categoria = %s', $id, $k );
		if ( $n ) {
			echo '<a href="' . esc_url( apac_url( 'documenti', array( 'cat' => $k ) ) ) . '"' . ( $cat === $k ? ' class="is-active"' : '' ) . '>' . esc_html( $label ) . ' (' . (int) $n . ')</a>';
		}
	}
	echo '</div>';

	$ord    = apac_filter_val( 'ordina', array( 'recenti', 'vecchi', 'titolo' ), 'recenti' );
	$orders = array(
		'recenti' => 'created_at DESC, id DESC',
		'vecchi'  => 'created_at ASC, id ASC',
		'titolo'  => 'titolo ASC',
	);
	if ( $cat ) {
		$docs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'documenti' ) . ' WHERE azienda_id = %d AND categoria = %s ORDER BY ' . $orders[ $ord ], $id, $cat ) );
	} else {
		$docs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'documenti' ) . ' WHERE azienda_id = %d ORDER BY ' . $orders[ $ord ], $id ) );
	}
	if ( count( $docs ) > 1 ) {
		apac_render_filterbar( 'documenti', array( 'ordina' => array( 'Ordina per', array( 'recenti' => 'Più recenti', 'vecchi' => 'Meno recenti', 'titolo' => 'Titolo (A-Z)' ), $ord ) ), $cat ? array( 'cat' => $cat ) : array() );
	}
	$n_docs = apac_count( 'documenti', 'azienda_id = %d', $id );
	if ( $n_docs ) {
		$chips = array();
		if ( $cat ) {
			$chips[] = array( $cats[ $cat ], apac_url_filters( 'documenti', array( 'cat', 'ordina' ), 'cat' ) );
		}
		if ( 'recenti' !== $ord ) {
			$chips[] = array( 'vecchi' === $ord ? 'Meno recenti prima' : 'Titolo A-Z', apac_url_filters( 'documenti', array( 'cat', 'ordina' ), 'ordina' ) );
		}
		apac_render_filter_summary( 'Archivio', $chips, count( $docs ), apac_url( 'documenti' ) );
	}
	if ( ! $docs ) {
		apac_render_empty( $n_docs > 0, 'Nessun documento in archivio.', apac_url( 'documenti' ) );
		echo '</section>';
		return;
	}
	$inline = array( 'application/pdf', 'image/jpeg', 'image/png' );
	// Una riga per documento: si tocca il titolo per aprirlo; le azioni meno frequenti stanno in "Altro".
	echo '<ul class="apac-docs">';
	foreach ( $docs as $d ) {
		$view  = in_array( $d->mime, $inline, true );
		$open  = $view ? apac_file_url( $d->id, 'view' ) : apac_file_url( $d->id, 'download' );
		$catl  = isset( $cats[ $d->categoria ] ) ? $cats[ $d->categoria ] : $d->categoria;
		$edit  = apac_is_admin() || ( $can && (int) $d->caricato_da === get_current_user_id() );
		echo '<li class="apac-doc"><a class="apac-doc__main" href="' . esc_url( $open ) . '"' . ( $view ? ' target="_blank" rel="noopener"' : '' ) . '><span class="apac-doc__ico">' . apac_icon( 'file' ) . '</span><span class="apac-doc__txt"><strong>' . esc_html( $d->titolo ) . '</strong><small>' . esc_html( $catl . ' · ' . apac_date_it( $d->created_at ) . ' · ' . apac_human_size( $d->dimensione ) ) . '</small>' . ( (int) $d->richiede_firma ? '<span class="apac-badge apac-badge--info">Presa visione richiesta</span>' : '' ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="apac-doc__actions apac-row-actions"><a class="apac-btn apac-btn--small apac-btn--ghost" href="' . esc_url( apac_file_url( $d->id, 'download' ) ) . '" aria-label="' . esc_attr( 'Scarica ' . $d->titolo ) . '">' . apac_icon( 'download' ) . '<span>Scarica</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		apac_render_firma_cell( $d, $id );
		ob_start();
		apac_render_versioni( $d );
		$more = ob_get_clean();
		$oper = '';
		if ( $edit ) {
			$oper = apac_details_open( 'documento_update', 'id:' . (int) $d->id );
			// phpcs:disable WordPress.Security.EscapeOutput
			$more .= '<details class="apac-edit"' . $oper . '><summary class="apac-btn apac-btn--small apac-btn--ghost">Modifica</summary>' . apac_form_open( 'documento_update', $id, true );
			$more .= apac_hidden( 'id', (int) $d->id ) . '<div class="apac-fields">';
			$more .= apac_field( 'Titolo', 'titolo', $d->titolo, 'text', 'required maxlength="200"' );
			$more .= apac_select( 'Categoria', 'categoria', $cats, $d->categoria );
			$more .= apac_file_field( 'Sostituisci file (facoltativo)', '.pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.jpg,.jpeg,.png', false, true );
			if ( apac_is_admin() ) {
				$more .= apac_checkbox( 'Chiedi ai clienti di leggere e firmare (presa visione)', 'richiede_firma', (bool) $d->richiede_firma );
			}
			$more .= '</div><p class="apac-muted">Se sostituisci il file, quello attuale resta consultabile tra le versioni precedenti.</p><div class="apac-actions"><button class="apac-btn apac-btn--small" type="submit">Salva modifiche</button></div></form></details>';
			$more .= apac_button_form( 'documento_delete', $id, array( 'id' => $d->id ), 'Elimina', 'apac-btn apac-btn--small apac-btn--ghost', 'Eliminare definitivamente il documento?' );
			// phpcs:enable
		}
		if ( '' !== $more ) {
			echo '<details class="apac-doc__more"' . $oper . '><summary class="apac-btn apac-btn--small apac-btn--ghost" aria-label="' . esc_attr( 'Altre azioni per ' . $d->titolo ) . '">' . apac_icon( 'more' ) . '<span>Altro</span></summary><div class="apac-doc__panel"><p class="apac-muted"><small>' . esc_html( $d->nome_file . ' · caricato da ' ) . apac_author_label( (int) $d->caricato_da ) . '</small></p>' . $more . '</div></details>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div></li>';
	}
	echo '</ul></section>';
}

/* ----------------------------- Scadenzario ----------------------------- */

function apac_render_scadenziario( $az ) {
	global $wpdb;
	$id   = (int) $az->id;
	$cats = apac_categorie_scadenze();
	$tutte = ! empty( $_GET['tutte'] ); // phpcs:ignore WordPress.Security.NonceVerification

	$lav_opts = array( '' => '— Nessuno —' );
	foreach ( apac_get_lavoratori( $id, true ) as $l ) {
		$lav_opts[ $l->id ] = $l->cognome . ' ' . $l->nome;
	}

	$can = apac_client_can( $id, 'scadenze' );
	// phpcs:disable WordPress.Security.EscapeOutput
	if ( $can ) :
	echo apac_add_box_open( 'scadenza_save', 'Nuova scadenza', ! apac_count( 'scadenze', "azienda_id = %d AND stato = 'aperta'", $id ) ) . '<section class="apac-card"><h2>Nuovo adempimento</h2>' . apac_form_open( 'scadenza_save', $id ) . '<div class="apac-fields">';
	echo apac_field( 'Descrizione *', 'titolo', '', 'text', 'required placeholder="Es. verifica periodica estintori"' );
	echo apac_select( 'Categoria *', 'categoria', $cats, 'altro' );
	echo apac_field( 'Data di scadenza *', 'data_scadenza', '', 'date', 'required' );
	echo apac_select( 'Lavoratore (facoltativo)', 'lavoratore_id', $lav_opts );
	echo apac_select( 'Si ripete', 'ricorrenza_mesi', apac_ricorrenze(), '0' );
	echo apac_textarea( 'Note', 'note', '', 'Se si ripete: quando lo segni completato, la prossima scadenza viene creata da sola.' );
	echo '</div><div class="apac-actions"><button class="apac-btn" type="submit">Aggiungi</button></div></form></section>' . apac_add_box_close();
	endif;
	// phpcs:enable

	$f_cat = apac_filter_val( 'categoria', array_merge( array( '' ), array_map( 'strval', array_keys( $cats ) ) ) );
	$f_per = apac_filter_val( 'periodo', array( '', 'superate', '30', '90', '365' ) );
	$sql   = 'SELECT s.*, l.nome, l.cognome FROM ' . apac_table( 'scadenze' ) . ' s LEFT JOIN ' . apac_table( 'lavoratori' ) . ' l ON l.id = s.lavoratore_id WHERE s.azienda_id = %d';
	$args  = array( $id );
	$sql  .= $tutte ? '' : " AND s.stato = 'aperta'";
	if ( '' !== $f_cat ) {
		$sql   .= ' AND s.categoria = %s';
		$args[] = $f_cat;
	}
	if ( 'superate' === $f_per ) {
		$sql   .= ' AND s.data_scadenza < %s';
		$args[] = apac_today();
	} elseif ( '' !== $f_per ) {
		$sql   .= ' AND s.data_scadenza <= %s';
		$args[] = gmdate( 'Y-m-d', strtotime( apac_today() . ' +' . (int) $f_per . ' days' ) );
	}
	$sql .= ' ORDER BY s.data_scadenza ASC';
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$ric  = apac_ricorrenze();

	echo '<section class="apac-card"><h2>Adempimenti</h2><div class="apac-filters">';
	echo '<a href="' . esc_url( apac_url( 'scadenziario', array_filter( array( 'categoria' => $f_cat, 'periodo' => $f_per ) ) ) ) . '"' . ( $tutte ? '' : ' class="is-active"' ) . '>Da completare</a>';
	echo '<a href="' . esc_url( apac_url( 'scadenziario', array_filter( array( 'tutte' => 1, 'categoria' => $f_cat, 'periodo' => $f_per ) ) ) ) . '"' . ( $tutte ? ' class="is-active"' : '' ) . '>Tutti</a></div>';
	$n_all = apac_count( 'scadenze', 'azienda_id = %d', $id );
	$per_l = array( 'superate' => 'Già superate', '30' => 'Entro 30 giorni', '90' => 'Entro 3 mesi', '365' => 'Entro un anno' );
	if ( $n_all > 1 ) {
		apac_render_filterbar(
			'scadenziario',
			array(
				'periodo'   => array( 'Scadenza', array( '' => 'Qualsiasi data' ) + $per_l, $f_per ),
				'categoria' => array( 'Categoria', array( '' => 'Tutte' ) + $cats, $f_cat ),
			),
			$tutte ? array( 'tutte' => 1 ) : array()
		);
	}
	if ( $n_all ) {
		$fn    = array( 'periodo', 'categoria', 'tutte' );
		$chips = array();
		if ( '' !== $f_per ) {
			$chips[] = array( $per_l[ $f_per ], apac_url_filters( 'scadenziario', $fn, 'periodo' ) );
		}
		if ( '' !== $f_cat ) {
			$chips[] = array( isset( $cats[ $f_cat ] ) ? $cats[ $f_cat ] : $f_cat, apac_url_filters( 'scadenziario', $fn, 'categoria' ) );
		}
		apac_render_filter_summary( $tutte ? 'Tutti gli adempimenti' : 'Da completare', $chips, count( $rows ), apac_url( 'scadenziario', $tutte ? array( 'tutte' => 1 ) : array() ) );
	}
	if ( ! $rows ) {
		apac_render_empty( $n_all > 0, 'Nessun adempimento inserito.', apac_url( 'scadenziario' ) );
	} else {
		echo '<ul class="apac-rows">';
		foreach ( $rows as $r ) {
			$done = 'completata' === $r->stato;
			$sub  = array( isset( $cats[ $r->categoria ] ) ? $cats[ $r->categoria ] : $r->categoria, apac_date_it( $r->data_scadenza ) );
			if ( $r->cognome ) {
				$sub[] = $r->cognome . ' ' . $r->nome;
			}
			echo '<li class="apac-row' . ( $done ? ' is-done' : '' ) . '"><div class="apac-row__main"><span class="apac-row__txt"><strong>' . esc_html( $r->titolo ) . '</strong><small>' . esc_html( implode( ' · ', $sub ) ) . '</small>';
			if ( (int) $r->ricorrenza_mesi > 0 && isset( $ric[ (int) $r->ricorrenza_mesi ] ) ) {
				echo '<span class="apac-repeat">' . apac_icon( 'repeat' ) . esc_html( $ric[ (int) $r->ricorrenza_mesi ] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			if ( $r->note ) {
				echo '<small class="apac-row__note">' . esc_html( $r->note ) . '</small>';
			}
			echo '</span><span class="apac-row__badges">' . ( $done ? '<span class="apac-badge apac-badge--ok">Completata</span>' : apac_badge_scadenza( $r->data_scadenza ) ) . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			if ( $can ) {
				// phpcs:disable WordPress.Security.EscapeOutput
				$oper = apac_details_open( 'scadenza_save', 'id:' . (int) $r->id );
				echo '<div class="apac-doc__actions apac-row-actions">';
				echo apac_button_form( 'scadenza_toggle', $id, array( 'id' => $r->id, 'stato_atteso' => $done ? 'completata' : 'aperta' ), $done ? 'Riapri' : 'Segna completata', $done ? 'apac-btn apac-btn--small apac-btn--ghost' : 'apac-btn apac-btn--small' );
				echo '<details class="apac-doc__more"' . $oper . '><summary class="apac-btn apac-btn--small apac-btn--ghost" aria-label="' . esc_attr( 'Altre azioni per ' . $r->titolo ) . '">' . apac_icon( 'more' ) . '<span>Altro</span></summary><div class="apac-doc__panel">';
				echo '<details class="apac-edit"' . $oper . '><summary class="apac-btn apac-btn--small apac-btn--ghost">Modifica</summary>' . apac_form_open( 'scadenza_save', $id );
				echo apac_hidden( 'id', (int) $r->id ) . '<div class="apac-fields">';
				echo apac_field( 'Descrizione', 'titolo', $r->titolo, 'text', 'required' );
				echo apac_select( 'Categoria', 'categoria', $cats, $r->categoria );
				echo apac_field( 'Data di scadenza', 'data_scadenza', $r->data_scadenza, 'date', 'required' );
				echo apac_select( 'Lavoratore', 'lavoratore_id', $lav_opts, (string) $r->lavoratore_id );
				echo apac_select( 'Si ripete', 'ricorrenza_mesi', apac_ricorrenze(), (string) (int) $r->ricorrenza_mesi );
				echo apac_textarea( 'Note', 'note', $r->note );
				echo '</div><div class="apac-actions"><button class="apac-btn apac-btn--small" type="submit">Salva modifiche</button></div></form></details>';
				echo apac_button_form( 'scadenza_delete', $id, array( 'id' => $r->id ), 'Elimina', 'apac-btn apac-btn--small apac-btn--ghost', 'Eliminare questo adempimento?' );
				echo '</div></details></div>';
				// phpcs:enable
			}
			echo '</li>';
		}
		echo '</ul>';
	}
	echo '</section>';

	// Formazione in scadenza (dagli attestati): stesso filtro di periodo degli adempimenti.
	$fsql  = 'SELECT a.*, l.nome, l.cognome FROM ' . apac_table( 'attestati' ) . ' a INNER JOIN ' . apac_table( 'lavoratori' ) . ' l ON l.id = a.lavoratore_id
			 WHERE a.azienda_id = %d AND l.attivo = 1 AND a.data_scadenza IS NOT NULL AND ' . apac_sql_attestato_corrente( 'a' );
	$fargs = array( $id );
	if ( 'superate' === $f_per ) {
		$fsql   .= ' AND a.data_scadenza < %s';
		$fargs[] = apac_today();
	} elseif ( '' !== $f_per ) {
		$fsql   .= ' AND a.data_scadenza <= %s';
		$fargs[] = gmdate( 'Y-m-d', strtotime( apac_today() . ' +' . (int) $f_per . ' days' ) );
	}
	$att = $wpdb->get_results( $wpdb->prepare( $fsql . ' ORDER BY a.data_scadenza ASC', ...$fargs ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	echo '<section class="apac-card" id="apac-formazione"><h2>Scadenze della formazione</h2>';
	if ( '' !== $f_per ) {
		apac_render_filter_summary( 'Attestati validi con scadenza', array( array( $per_l[ $f_per ], apac_url_filters( 'scadenziario', array( 'periodo', 'categoria', 'tutte' ), 'periodo' ) ) ), count( $att ), apac_url( 'scadenziario' ) );
	}
	if ( ! $att ) {
		echo '<p class="apac-empty">' . ( '' !== $f_per ? 'Nessun attestato in questo periodo.' : 'Nessun attestato con data di scadenza. Le scadenze si inseriscono nella scheda di ciascun lavoratore.' ) . '</p>';
	} else {
		echo '<ul class="apac-rows">';
		foreach ( $att as $a ) {
			echo '<li class="apac-row"><a class="apac-row__main" href="' . esc_url( apac_url( 'lavoratori', array( 'lav' => (int) $a->lavoratore_id ) ) ) . '"><span class="apac-row__txt"><strong>' . esc_html( $a->corso ) . '</strong><small>' . esc_html( $a->cognome . ' ' . $a->nome . ' · ' . apac_date_it( $a->data_scadenza ) ) . '</small></span><span class="apac-row__badges">' . apac_badge_scadenza( $a->data_scadenza ) . '</span></a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul>';
	}
	echo '</section>';
}

/* ---------------------------- Segnalazioni ----------------------------- */

function apac_render_segnalazioni( $az ) {
	global $wpdb;
	$id     = (int) $az->id;
	$stati  = array(
		'aperta'         => 'Aperta',
		'in_lavorazione' => 'In lavorazione',
		'chiusa'         => 'Chiusa',
	);
	$colori = array(
		'aperta'         => 'warn',
		'in_lavorazione' => 'info',
		'chiusa'         => 'ok',
	);

	// phpcs:disable WordPress.Security.EscapeOutput
	$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'segnalazioni' ) . ' WHERE azienda_id = %d ORDER BY created_at DESC', $id ) );
	$n_all  = count( $rows );
	$f_stat = apac_filter_val( 'stato', array( '', 'aperte', 'chiuse' ) );
	echo apac_add_box_open( 'segnalazione_create', 'Nuova segnalazione', ! $rows ) . '<section class="apac-card"><h2>Nuova segnalazione</h2><p class="apac-muted">Segnala un problema, una richiesta o un dubbio sulla sicurezza: lo studio riceverà un avviso via email.</p>' . apac_form_open( 'segnalazione_create', $id, true ) . '<div class="apac-fields">';
	echo apac_field( 'Oggetto *', 'titolo', '', 'text', 'required' );
	echo apac_select(
		'Priorità',
		'priorita',
		array(
			'bassa' => 'Bassa',
			'media' => 'Media',
			'alta'  => 'Alta',
		),
		'media'
	);
	echo apac_textarea( 'Descrizione', 'descrizione' );
	echo apac_foto_field();
	echo '</div><p class="apac-muted">Per situazioni di pericolo grave e immediato non usare questo modulo: chiama subito i numeri di emergenza.</p><div class="apac-actions"><button class="apac-btn" type="submit">Invia segnalazione</button></div></form></section>' . apac_add_box_close();
	apac_render_corso_box( $az );
	// phpcs:enable

	echo '<section class="apac-card"><h2>Segnalazioni</h2>';
	if ( $n_all ) {
		echo '<div class="apac-filters" role="group" aria-label="Stato">';
		foreach ( array( '' => 'Tutte', 'aperte' => 'Da gestire', 'chiuse' => 'Chiuse' ) as $k => $lab ) {
			echo '<a href="' . esc_url( apac_url( 'segnalazioni', '' !== $k ? array( 'stato' => $k ) : array() ) ) . '"' . ( $f_stat === $k ? ' class="is-active" aria-current="true"' : '' ) . '>' . esc_html( $lab ) . '</a>';
		}
		echo '</div>';
		$rows = array_values(
			array_filter(
				$rows,
				function ( $x ) use ( $f_stat ) {
					return '' === $f_stat || ( 'aperte' === $f_stat ? 'chiusa' !== $x->stato : 'chiusa' === $x->stato );
				}
			)
		);
		apac_render_filter_summary( 'Segnalazioni', '' !== $f_stat ? array( array( 'aperte' === $f_stat ? 'Da gestire' : 'Chiuse', apac_url( 'segnalazioni' ) ) ) : array(), count( $rows ), apac_url( 'segnalazioni' ) );
	}
	if ( ! $rows ) {
		apac_render_empty( $n_all > 0, 'Nessuna segnalazione.', apac_url( 'segnalazioni' ) );
		echo '</section>';
		return;
	}
	foreach ( $rows as $r ) {
		echo '<article class="apac-ticket"><header><strong>' . esc_html( $r->titolo ) . '</strong>';
		echo '<span class="apac-badge apac-badge--' . esc_attr( $colori[ $r->stato ] ?? 'muted' ) . '">' . esc_html( $stati[ $r->stato ] ?? $r->stato ) . '</span>';
		echo 'corso' === $r->tipo ? '<span class="apac-badge apac-badge--info">Richiesta corso</span></header>' : '<span class="apac-badge apac-badge--muted">Priorità ' . esc_html( $r->priorita ) . '</span></header>';
		echo '<small>' . esc_html( apac_date_it( $r->created_at ) ) . ' · ' . apac_author_label( (int) $r->autore_id ) . '</small>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $r->descrizione ) {
			echo '<p>' . nl2br( esc_html( $r->descrizione ) ) . '</p>';
		}
		apac_render_foto( $r );
		if ( $r->risposta ) {
			echo '<div class="apac-reply"><strong>Risposta dello studio</strong><p>' . nl2br( esc_html( $r->risposta ) ) . '</p></div>';
		}
		if ( apac_is_admin() ) {
			echo apac_form_open( 'segnalazione_update', $id, false, 'apac-form apac-form--compact' ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo apac_hidden( 'id', (int) $r->id ) . '<div class="apac-fields">'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo apac_select( 'Stato', 'stato', $stati, $r->stato ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo apac_textarea( 'Risposta', 'risposta', $r->risposta ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</div><div class="apac-actions"><button class="apac-btn apac-btn--small" type="submit">Aggiorna</button></div></form>';
			echo apac_button_form( 'segnalazione_delete', $id, array( 'id' => $r->id ), 'Elimina segnalazione', 'apac-btn apac-btn--small apac-btn--ghost', 'Eliminare definitivamente questa segnalazione?' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</article>';
	}
	echo '</section>';
}

/* ------------------------------ Criticità ------------------------------ */

function apac_render_criticita( $az ) {
	$crit = apac_analizza_criticita( (int) $az->id );
	echo '<p><a class="apac-btn apac-btn--ghost" href="' . esc_url( apac_report_url( (int) $az->id ) ) . '">' . apac_icon_extra( 'print' ) . '<span>Riepilogo dati e scadenze (stampa o PDF)</span></a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	apac_render_completezza( $az, true );
	echo '<section class="apac-card"><h2>Analisi dei dati inseriti</h2>';
	echo '<p class="apac-muted">Controllo automatico sulla completezza dei dati presenti nell’area clienti. Segnala informazioni mancanti o scadute: non è una verifica di conformità e non sostituisce la valutazione dell’RSPP o del consulente. Riferimenti: D.Lgs. 81/2008 e s.m.i.</p>';
	if ( ! $crit ) {
		echo '<div class="apac-notice apac-notice--ok">Nessuna criticità rilevata sui dati inseriti.</div></section>';
		return;
	}
	echo '<ul class="apac-crit">';
	foreach ( $crit as $c ) {
		echo '<li class="apac-crit--' . esc_attr( $c['livello'] ) . '"><div><strong>' . esc_html( $c['titolo'] ) . '</strong> ' . apac_badge_livello( $c['livello'] ); // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $c['norma'] ) {
			echo '<br><small>' . esc_html( $c['norma'] ) . '</small>';
		}
		echo '<p>' . esc_html( $c['dettaglio'] ) . '</p></div><a class="apac-btn apac-btn--small apac-btn--ghost" href="' . esc_url( apac_url( $c['sez'] ) ) . '">Vai alla sezione</a></li>';
	}
	echo '</ul></section>';
}

/* --------------------------- Utenti e aziende -------------------------- */

function apac_render_utenti( $az ) {
	if ( ! apac_is_admin() ) {
		return;
	}

	// Elenco aziende: ordine alfabetico, a pagine.
	$q = apac_aziende_query( '', isset( $_GET['apag'] ) ? absint( $_GET['apag'] ) : 1, APAC_LIST_PAGE ); // phpcs:ignore WordPress.Security.NonceVerification

	// Nuova azienda (si apre con il pulsante).
	echo apac_add_box_open( 'azienda_create', 'Nuova azienda', ! $q['total'], '', 'building' ) . '<section class="apac-card">' . apac_form_open( 'azienda_create', 0 ); // phpcs:ignore WordPress.Security.EscapeOutput
	apac_render_azienda_fields();
	echo '<div class="apac-actions"><button class="apac-btn" type="submit">Crea azienda</button></div></form></section>' . apac_add_box_close(); // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<section class="apac-card"><h2>Aziende clienti</h2>';
	if ( ! $q['rows'] ) {
		echo '<p class="apac-muted">Nessuna azienda. Compila “Nuova azienda” qui sopra per crearne una.</p>';
	} else {
		echo '<p class="apac-muted">' . esc_html( apac_n( $q['total'], 'azienda', 'aziende' ) ) . ' in ordine alfabetico. Per trovarne una usa “Cerca azienda” qui sopra.</p>';
		echo '<div class="apac-table-wrap"><table class="apac-table"><thead><tr><th>Azienda</th><th>Utenti</th><th>Lavoratori</th><th>Documenti</th><th></th></tr></thead><tbody>';
		foreach ( $q['rows'] as $a ) {
			$users = get_users( array( 'meta_key' => 'apac_azienda_id', 'meta_value' => $a->id, 'fields' => 'ID' ) );
			echo '<tr><td data-label="Azienda"><strong>' . esc_html( $a->ragione_sociale ) . '</strong><br><small>' . esc_html( apac_azienda_ident( $a ) . ( $a->comune ? ' · ' . $a->comune : '' ) ) . '</small></td>';
			echo '<td data-label="Utenti">' . count( $users ) . '</td>';
			echo '<td data-label="Lavoratori">' . (int) apac_count( 'lavoratori', 'azienda_id = %d AND attivo = 1', $a->id ) . '</td>';
			echo '<td data-label="Documenti">' . (int) apac_count( 'documenti', 'azienda_id = %d', $a->id ) . '</td>';
			echo '<td class="apac-row-actions"><a class="apac-btn apac-btn--small" href="' . esc_url( apac_url( 'dashboard', array( 'azienda' => (int) $a->id ) ) ) . '">Apri</a><a class="apac-btn apac-btn--small apac-btn--ghost" href="' . esc_url( apac_url( 'utenti', array( 'azienda' => (int) $a->id ) ) ) . '">Utenti</a></td></tr>';
		}
		echo '</tbody></table></div>';
		apac_render_pager( $q, 'apag' );
	}
	echo '</section>';

	// Utenti dell'azienda selezionata.
	if ( $az ) {
		$users = get_users( array( 'meta_key' => 'apac_azienda_id', 'meta_value' => $az->id ) );
		echo '<section class="apac-card"><h2>Accessi di ' . esc_html( $az->ragione_sociale ) . '</h2>';
		if ( ! $users ) {
			echo '<p class="apac-muted">Nessun utente collegato.</p>';
		} else {
			echo '<div class="apac-table-wrap"><table class="apac-table"><thead><tr><th>Nome</th><th>Email</th><th>Stato</th><th></th></tr></thead><tbody>';
			foreach ( $users as $u ) {
				$revocato = apac_user_revoked( $u->ID );
				echo '<tr' . ( $revocato ? ' class="is-done"' : '' ) . '><td data-label="Nome">' . esc_html( $u->display_name ) . ( apac_user_readonly( $u->ID ) ? '<br><span class="apac-badge apac-badge--muted">Solo consultazione</span>' : '' ) . '</td><td data-label="Email">' . esc_html( $u->user_email ) . '</td><td data-label="Stato">' . ( $revocato ? '<span class="apac-badge apac-badge--danger">Accesso revocato</span>' : '<span class="apac-badge apac-badge--ok">Attivo</span>' ) . '</td><td>';
				if ( in_array( APAC_ROLE, (array) $u->roles, true ) ) {
					// phpcs:disable WordPress.Security.EscapeOutput
					echo '<div class="apac-row-actions"><details class="apac-edit"' . apac_details_open( 'utente_update', 'user_id:' . (int) $u->ID ) . '><summary class="apac-btn apac-btn--small apac-btn--ghost">Modifica</summary>' . apac_form_open( 'utente_update', $az->id );
					echo apac_hidden( 'user_id', (int) $u->ID ) . '<div class="apac-fields">';
					echo apac_field( 'Nome', 'nome', $u->first_name, 'text', 'required maxlength="100"' );
					echo apac_field( 'Cognome', 'cognome', $u->last_name, 'text', 'maxlength="100"' );
					echo apac_field( 'Email (anche per l’accesso)', 'email', $u->user_email, 'email', 'required maxlength="100"' );
					echo apac_azienda_picker( 'Azienda collegata', 'nuova_azienda', (int) $az->id );
					echo apac_select( 'Cosa può fare', 'accesso', apac_accesso_options(), apac_user_readonly( $u->ID ) ? 'lettura' : 'modifica' );
					echo '</div><div class="apac-actions"><button class="apac-btn apac-btn--small" type="submit">Salva modifiche</button></div></form></details>';
					echo apac_button_form( 'utente_reset', $az->id, array( 'user_id' => $u->ID ), 'Invia nuova password', 'apac-btn apac-btn--small apac-btn--ghost', 'Inviare all’utente un’email per reimpostare la password?' );
					if ( $revocato ) {
						echo apac_button_form( 'utente_riattiva', $az->id, array( 'user_id' => $u->ID ), 'Riattiva accesso', 'apac-btn apac-btn--small' );
					} else {
						echo apac_button_form( 'utente_revoca', $az->id, array( 'user_id' => $u->ID ), 'Revoca accesso', 'apac-btn apac-btn--small apac-btn--ghost', 'Revocare l’accesso? L’utente viene disconnesso e non potrà più entrare; l’account resta e potrai riattivarlo.' );
					}
					echo '<details class="apac-edit"' . apac_details_open( 'utente_elimina', 'user_id:' . (int) $u->ID ) . '><summary class="apac-btn apac-btn--small apac-btn--ghost">Elimina account</summary>' . apac_form_open( 'utente_elimina', $az->id );
					echo apac_hidden( 'user_id', (int) $u->ID ) . '<p class="apac-muted">Eliminazione definitiva dell’account WordPress. Per sospendere l’accesso usa invece “Revoca accesso”.</p><div class="apac-fields">';
					echo apac_field( 'Scrivi ELIMINA per confermare', 'conferma', '', 'text', 'autocomplete="off" required' );
					echo '</div><div class="apac-actions"><button class="apac-btn apac-btn--small apac-btn--danger" type="submit">Elimina definitivamente</button></div></form></details>';
					echo '</div>';
					// phpcs:enable
				}
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		// phpcs:disable WordPress.Security.EscapeOutput
		echo apac_add_box_open( 'utente_create', 'Nuovo accesso', ! $users, '', 'key' ) . '<div class="apac-subcard">' . apac_form_open( 'utente_create', $az->id ) . '<div class="apac-fields">';
		echo apac_field( 'Nome *', 'nome', '', 'text', 'required' );
		echo apac_field( 'Cognome', 'cognome' );
		echo apac_field( 'Email *', 'email', '', 'email', 'required' );
		echo apac_select( 'Cosa può fare', 'accesso', apac_accesso_options(), 'modifica' );
		echo '</div><p class="apac-muted">L’utente riceve un’email di WordPress con il link per scegliere la propria password. Vedrà solo i dati di questa azienda.</p>';
		echo '<div class="apac-actions"><button class="apac-btn" type="submit">Crea accesso</button></div></form></div>' . apac_add_box_close() . '</section>';
		// phpcs:enable
	} else {
		echo '<section class="apac-card"><p class="apac-muted">Per gestire gli accessi, apri “Utenti” accanto a un’azienda.</p></section>';
	}

}
