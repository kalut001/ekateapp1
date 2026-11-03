<?php
defined( 'ABSPATH' ) || exit;

/*
 * 1.7.2 – Firma dei documenti al primo accesso all'area riservata:
 * - informativa privacy (presa visione, ogni utente);
 * - accordo di nomina a responsabile del trattamento (art. 28 GDPR, una volta per azienda,
 *   da chi ha i poteri di firma).
 * Alla firma viene generato un PDF (testo, firma, data/ora, IP, impronta) salvato nell'area
 * riservata e inviato via email allo studio. Se un testo cambia, la firma viene richiesta di nuovo.
 */

function apac_consensi_attivi() {
	return '0' !== (string) get_option( 'apac_consensi_attivi', '1' );
}

/* ============================== Testi ============================== */

/** Testo predefinito dell'accordo (modificabile dalle impostazioni del plugin). */
function apac_accordo_default() {
	return <<<'TXT'
# Accordo per il trattamento dei dati personali (art. 28 del Regolamento UE 2016/679)
Tra l’azienda {azienda} (di seguito «Titolare») e il Dott. Antonino Porcelli – AP Sicurezza Lavoro, Via Santocanale 74, 90151 Palermo (PA), P. IVA 07436760826 (di seguito «Responsabile»).
## 1. Oggetto
Il Titolare affida al Responsabile i trattamenti di dati personali necessari a fornire i servizi di consulenza e formazione sulla sicurezza sul lavoro e l’area riservata online (archivio documenti, scadenzario, formazione, segnalazioni, firme di presa visione). Il presente accordo regola tali trattamenti ai sensi dell’art. 28 del Regolamento (UE) 2016/679 («GDPR»).
## 2. Natura, finalità e durata
I dati sono trattati con strumenti elettronici, al solo fine di eseguire i servizi richiesti dal Titolare, per la durata del rapporto contrattuale.
## 3. Tipi di dati e categorie di interessati
Interessati: lavoratori del Titolare, figure della sicurezza aziendale e referenti che utilizzano l’area riservata. Dati: dati identificativi e di contatto, codice fiscale, mansione, date di assunzione, attestati e scadenze della formazione, documenti della sicurezza, segnalazioni e relative foto, firme di presa visione. Il Titolare si impegna a non inserire nell’area riservata dati relativi alla salute (ad esempio certificati medici o giudizi di idoneità) né altre categorie particolari di dati.
## 4. Obblighi del Responsabile
Il Responsabile si impegna a:
- trattare i dati soltanto su istruzione documentata del Titolare, salvo che lo richieda il diritto dell’Unione o nazionale; le istruzioni sono costituite dal presente accordo, dall’incarico e dalle indicazioni scritte del Titolare;
- garantire che le persone autorizzate al trattamento siano impegnate alla riservatezza;
- adottare le misure di sicurezza richieste dall’art. 32 GDPR, tra cui: accesso con credenziali personali, connessione cifrata (HTTPS), archiviazione dei documenti in un’area non accessibile direttamente dal web, livelli di accesso per utente, registro delle operazioni e copie di sicurezza;
- assistere il Titolare, per quanto possibile, nel dare seguito alle richieste degli interessati (artt. 15–22 GDPR) e nel rispetto degli obblighi degli artt. 32–36 GDPR;
- informare il Titolare senza ingiustificato ritardo dopo essere venuto a conoscenza di una violazione dei dati personali;
- al termine del rapporto, su scelta del Titolare, restituire o cancellare i dati, salvo obblighi di conservazione previsti dalla legge;
- mettere a disposizione del Titolare le informazioni necessarie a dimostrare il rispetto del presente accordo e consentire verifiche, con un preavviso ragionevole.
## 5. Altri responsabili (sub-responsabili)
Il Titolare autorizza in via generale il Responsabile ad avvalersi di altri responsabili per i servizi tecnici necessari: fornitore dei servizi di hosting del sito e di posta elettronica (con server nell’Unione Europea), servizi di consegna delle notifiche dei dispositivi (ad esempio Apple, Google, Mozilla) ed eventuali servizi di copia di sicurezza. Il Responsabile informa il Titolare di eventuali modifiche, consentendogli di opporsi, e impone ai sub-responsabili obblighi di protezione dei dati equivalenti. Eventuali trasferimenti fuori dall’Unione Europea avvengono solo con le garanzie previste dal Capo V del GDPR.
## 6. Obblighi del Titolare
Il Titolare garantisce di avere una base giuridica per i trattamenti affidati, di aver informato gli interessati (artt. 13–14 GDPR) e di inserire nell’area riservata solo i dati necessari.
## 7. Durata
Il presente accordo ha la stessa durata del rapporto contrattuale tra le parti e ne segue le sorti.
TXT;
}

function apac_accordo_testo( $azienda_nome = '' ) {
	$t = (string) get_option( 'apac_accordo_testo', '' );
	$t = '' !== trim( $t ) ? $t : apac_accordo_default();
	return str_replace( '{azienda}', '' !== $azienda_nome ? $azienda_nome : '«nome dell’azienda»', $t );
}

/** Informativa privacy pubblicata, come righe di testo (# titolo, ## sezione, - elenco). */
function apac_privacy_testo() {
	$pid  = (int) get_option( 'wp_page_for_privacy_policy' );
	$page = $pid ? get_post( $pid ) : get_page_by_path( 'privacy-policy' );
	if ( ! $page || 'publish' !== $page->post_status ) {
		return '';
	}
	$html = preg_replace( '#<(style|script)\b.*?</\1>#is', '', (string) $page->post_content );
	$html = preg_replace( '#<!--.*?-->#s', '', $html );
	$out  = array( '# ' . get_the_title( $page ) );
	if ( preg_match_all( '#<(h[1-6]|p|li)\b[^>]*>(.*?)</\1>#is', $html, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $el ) {
			$t = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $el[2] ), ENT_QUOTES, 'UTF-8' ) ) );
			if ( '' === $t ) {
				continue;
			}
			$tag   = strtolower( $el[1] );
			$out[] = ( 'li' === $tag ? '- ' : ( 'p' === $tag ? '' : ( 'h2' === $tag || 'h1' === $tag ? '## ' : '### ' ) ) ) . $t;
		}
	}
	return implode( "\n", $out );
}

/** Impronta del testo: se cambia, la firma va rinnovata. */
function apac_testo_versione( $testo ) {
	return hash( 'sha256', trim( preg_replace( '/\s+/u', ' ', (string) $testo ) ) );
}

function apac_consensi_tipi() {
	return array(
		'privacy' => 'Informativa privacy (presa visione)',
		'accordo' => 'Accordo per il trattamento dei dati (art. 28 GDPR)',
	);
}

/* ============================== Stato ============================== */

/** Firme ancora necessarie per l'utente corrente: [ 'privacy' => testo, 'accordo' => testo ]. */
function apac_consensi_pending( $user_id = 0 ) {
	global $wpdb;
	$user_id = $user_id ? $user_id : get_current_user_id();
	if ( ! apac_consensi_attivi() || ! $user_id || user_can( $user_id, 'manage_options' ) ) {
		return array();
	}
	$az = apac_get_azienda( apac_user_azienda_id( $user_id ) );
	if ( ! $az ) {
		return array();
	}
	$out     = array();
	$privacy = apac_privacy_testo();
	if ( '' !== $privacy ) {
		$v = apac_testo_versione( $privacy );
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . apac_table( 'consensi' ) . " WHERE user_id = %d AND tipo = 'privacy' AND versione = %s", $user_id, $v ) ) ) {
			$out['privacy'] = $privacy;
		}
	}
	$accordo = apac_accordo_testo( $az->ragione_sociale );
	$v       = apac_testo_versione( $accordo );
	$firmato = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . apac_table( 'consensi' ) . " WHERE azienda_id = %d AND tipo = 'accordo' AND versione = %s", $az->id, $v ) );
	if ( ! $firmato && get_user_meta( $user_id, 'apac_no_poteri', true ) !== $v ) {
		$out['accordo'] = $accordo;
	}
	return $out;
}

/* ============================== Pagina di firma ============================== */

/** Se servono firme, mostra la pagina al posto dell'area riservata. Restituisce true se l'ha mostrata. */
function apac_consensi_gate() {
	if ( apac_is_admin() ) {
		return false;
	}
	$pending = apac_consensi_pending();
	if ( ! $pending ) {
		return false;
	}
	$az   = apac_get_azienda( apac_user_azienda_id() );
	$u    = wp_get_current_user();
	$tipi = apac_consensi_tipi();
	apac_render_hero( 'Prima di iniziare', 'Leggi e firma i documenti: bastano un paio di minuti, poi accedi all’area riservata.' );
	echo '<section class="aps-sec aps-alt apac-body"><div class="aps-wrap"><div class="apac-consent">';
	if ( isset( $_GET['apac_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$msgs = apac_messages();
		$code = sanitize_key( wp_unslash( $_GET['apac_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $msgs[ $code ] ) ) {
			echo '<div class="apac-notice apac-notice--' . esc_attr( $msgs[ $code ][0] ) . '" role="alert">' . esc_html( $msgs[ $code ][1] ) . '</div>';
		}
	}
	// phpcs:disable WordPress.Security.EscapeOutput
	echo apac_form_open( 'consenso_firma', (int) $az->id, false, 'apac-form apac-sign__form apac-consent__form' ) . '<input type="hidden" name="firma_img" value="" data-sign-out data-sign-format="jpeg">';
	$n = 0;
	foreach ( $pending as $tipo => $testo ) {
		$n++;
		echo '<section class="apac-card apac-consent__doc"><p class="apac-consent__step">Documento ' . (int) $n . ' di ' . count( $pending ) . '</p><h2>' . esc_html( $tipi[ $tipo ] ) . '</h2>';
		echo '<div class="apac-consent__text" tabindex="0" role="document" aria-label="' . esc_attr( $tipi[ $tipo ] ) . '">' . apac_consensi_testo_html( $testo ) . '</div>';
		if ( 'privacy' === $tipo ) {
			echo '<label class="apac-field apac-check apac-sign__ok"><input type="checkbox" name="ok_privacy" value="1" required><span>Dichiaro di aver ricevuto e letto l’informativa sul trattamento dei dati personali.</span></label>';
		} else {
			echo '<fieldset class="apac-consent__powers"><legend>Firma dell’accordo per conto di ' . esc_html( $az->ragione_sociale ) . '</legend>';
			echo '<label class="apac-field apac-check"><input type="radio" name="accordo" value="firmo" required data-consent-accordo><span>Ho i poteri per firmare per l’azienda e accetto l’accordo.</span></label>';
			echo '<label class="apac-field apac-consent__role"><span>Ruolo *</span><select name="ruolo"><option value="Legale rappresentante">Legale rappresentante</option><option value="Delegato con poteri di firma">Delegato con poteri di firma</option></select></label>';
			echo '<label class="apac-field apac-check"><input type="radio" name="accordo" value="no_poteri" data-consent-accordo><span>Non ho i poteri di firma: lo firmerà il legale rappresentante con il proprio accesso.</span></label></fieldset>';
		}
		echo '</section>';
	}
	list( $cls, , $msg ) = apac_field_error_parts( 'firma_img' );
	echo '<section class="apac-card"><h2>La tua firma</h2><p class="apac-muted">Firma con il dito o con il mouse. Vale per i documenti qui sopra.</p>';
	echo '<div class="apac-sign__pad' . $cls . '"><canvas data-sign-pad aria-label="Riquadro per la firma" role="img"></canvas><span class="apac-sign__hint" data-sign-hint>Firma qui</span><button type="button" class="apac-sign__clear" data-sign-clear>Cancella</button></div>' . $msg;
	echo '<noscript><p class="apac-notice apac-notice--warn">Per firmare serve JavaScript attivo nel browser.</p></noscript>';
	echo '<div class="apac-fields">' . apac_field( 'Nome e cognome *', 'firma_nome', $u->display_name, 'text', 'required maxlength="150" autocomplete="name"' ) . '</div>';
	echo '<p class="apac-sign__legal">Firma elettronica semplice. Vengono registrati data, ora, indirizzo IP, browser e impronta dei testi firmati; riceverai copia in PDF dallo studio su richiesta.</p>';
	echo '<div class="apac-actions"><button class="apac-btn" type="submit" data-sign-submit>Firma e accedi</button><a class="apac-btn apac-btn--ghost" href="' . esc_url( apac_logout_url() ) . '">Esci</a></div></section></form>';
	// phpcs:enable
	echo '</div></div></section>';
	return true;
}

/** Testo a righe -> HTML semplice. */
function apac_consensi_testo_html( $testo ) {
	$html = '';
	$list = false;
	foreach ( preg_split( '/\R/u', (string) $testo ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$is_li = 0 === strpos( $line, '- ' );
		if ( $list && ! $is_li ) {
			$html .= '</ul>';
			$list  = false;
		}
		if ( 0 === strpos( $line, '### ' ) ) {
			$html .= '<h4>' . esc_html( substr( $line, 4 ) ) . '</h4>';
		} elseif ( 0 === strpos( $line, '## ' ) ) {
			$html .= '<h3>' . esc_html( substr( $line, 3 ) ) . '</h3>';
		} elseif ( 0 === strpos( $line, '# ' ) ) {
			$html .= '<h3 class="apac-consent__title">' . esc_html( substr( $line, 2 ) ) . '</h3>';
		} elseif ( $is_li ) {
			if ( ! $list ) {
				$html .= '<ul>';
				$list  = true;
			}
			$html .= '<li>' . esc_html( substr( $line, 2 ) ) . '</li>';
		} else {
			$html .= '<p>' . esc_html( $line ) . '</p>';
		}
	}
	return $html . ( $list ? '</ul>' : '' );
}

/* ============================== Firma ============================== */

/** Immagine della firma in JPEG (sfondo bianco). @return string|false */
function apac_firma_jpeg( $data_url ) {
	if ( ! is_string( $data_url ) || 0 !== strpos( $data_url, 'data:image/jpeg;base64,' ) || strlen( $data_url ) > 600000 ) {
		return false;
	}
	$bin = base64_decode( substr( $data_url, 23 ), true );
	if ( false === $bin || "\xFF\xD8" !== substr( $bin, 0, 2 ) ) {
		return false;
	}
	$info = getimagesizefromstring( $bin );
	if ( ! $info || IMAGETYPE_JPEG !== $info[2] || $info[0] < 150 || $info[1] < 60 || $info[0] > 3000 || $info[1] > 2000 ) {
		return false;
	}
	if ( function_exists( 'imagecreatefromstring' ) ) {
		$im = @imagecreatefromstring( $bin ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $im ) {
			return false;
		}
		$w   = imagesx( $im );
		$h   = imagesy( $im );
		$ink = 0;
		for ( $x = 0; $x < $w; $x += max( 1, (int) ( $w / 120 ) ) ) {
			for ( $y = 0; $y < $h; $y += max( 1, (int) ( $h / 60 ) ) ) {
				$c = imagecolorsforindex( $im, imagecolorat( $im, $x, $y ) );
				if ( ( $c['red'] + $c['green'] + $c['blue'] ) < 450 ) {
					$ink++;
				}
			}
		}
		imagedestroy( $im );
		if ( $ink < 8 ) {
			return false;
		}
	}
	return $bin;
}

function apac_op_consenso_firma( $azienda_id ) {
	global $wpdb;
	if ( apac_is_admin() ) {
		apac_redirect( 'dashboard', 'denied' );
	}
	$pending = apac_consensi_pending();
	if ( ! $pending ) {
		apac_redirect( 'dashboard', 'signed' );
	}
	$nome = trim( sanitize_text_field( apac_post( 'firma_nome' ) ) );
	if ( '' === $nome || mb_strlen( $nome ) > 150 ) {
		apac_error_field( 'firma_nome' );
		apac_redirect( 'dashboard', '' === $nome ? 'need_field' : 'too_long' );
	}
	if ( isset( $pending['privacy'] ) && ! apac_post( 'ok_privacy' ) ) {
		apac_redirect( 'dashboard', 'need_consent' );
	}
	$accordo = isset( $pending['accordo'] ) ? (string) apac_post( 'accordo' ) : '';
	if ( isset( $pending['accordo'] ) && ! in_array( $accordo, array( 'firmo', 'no_poteri' ), true ) ) {
		apac_redirect( 'dashboard', 'need_consent' );
	}
	$ruolo = in_array( apac_post( 'ruolo' ), array( 'Legale rappresentante', 'Delegato con poteri di firma' ), true ) ? (string) apac_post( 'ruolo' ) : 'Legale rappresentante';

	$u     = wp_get_current_user();
	$az    = apac_get_azienda( $azienda_id );
	$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$ua    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 250 ) : '';
	$quando = current_time( 'mysql' );
	$tipi   = apac_consensi_tipi();
	$firmati = array();
	if ( isset( $pending['privacy'] ) ) {
		$firmati['privacy'] = array( $pending['privacy'], 'Presa visione dell’informativa' );
	}
	if ( 'firmo' === $accordo ) {
		$firmati['accordo'] = array( $pending['accordo'], 'Accordo firmato in qualità di: ' . $ruolo );
	}
	if ( 'no_poteri' === $accordo ) {
		update_user_meta( get_current_user_id(), 'apac_no_poteri', apac_testo_versione( $pending['accordo'] ) );
	}
	if ( ! $firmati ) {
		apac_log_detail( 'Accordo art. 28 rimandato al legale rappresentante' );
		apac_redirect( 'dashboard', 'saved' );
	}
	$jpg = apac_firma_jpeg( (string) apac_post( 'firma_img' ) );
	if ( false === $jpg ) {
		apac_error_field( 'firma_img' );
		apac_redirect( 'dashboard', 'bad_signature' );
	}

	// PDF con i testi firmati.
	$meta = array(
		'Azienda'        => $az->ragione_sociale,
		'Firmatario'     => $nome . ( 'firmo' === $accordo ? ' (' . $ruolo . ')' : '' ),
		'Account'        => $u->user_email,
		'Data e ora'     => mysql2date( 'd/m/Y H:i:s', $quando ) . ' (' . wp_timezone_string() . ')',
		'Indirizzo IP'   => $ip,
		'Browser'        => $ua,
	);
	$docs = array();
	foreach ( $firmati as $tipo => $d ) {
		$meta[ 'Impronta SHA-256 – ' . ( 'privacy' === $tipo ? 'informativa' : 'accordo' ) ] = apac_testo_versione( $d[0] );
		$docs[] = array( $tipi[ $tipo ], $d[0], $d[1] );
	}
	$pdf = apac_pdf_firma( 'Documenti firmati – ' . $az->ragione_sociale, $docs, $jpg, $meta );

	$prep = apac_ensure_private_dir();
	if ( is_wp_error( $prep ) ) {
		apac_redirect( 'dashboard', $prep->get_error_code() );
	}
	$rel_dir = (string) (int) $azienda_id;
	$abs_dir = apac_private_dir() . '/' . $rel_dir;
	if ( ! is_dir( $abs_dir ) && ( ! wp_mkdir_p( $abs_dir ) || ! apac_write_file( $abs_dir . '/index.php', "<?php\n// Silence is golden.\n" ) ) ) {
		apac_redirect( 'dashboard', 'storage_write' );
	}
	$stored = wp_generate_password( 32, false, false ) . '.pdf';
	if ( ! apac_write_file( $abs_dir . '/' . $stored, $pdf ) ) {
		apac_redirect( 'dashboard', 'storage_write' );
	}
	apac_tx_track_new_file( $abs_dir . '/' . $stored );
	$nome_file = sanitize_file_name( 'firma-' . sanitize_title( $az->ragione_sociale ) . '-' . sanitize_title( $nome ) . '-' . mysql2date( 'Y-m-d-His', $quando ) . '.pdf' );
	foreach ( $firmati as $tipo => $d ) {
		apac_must_insert(
			'consensi',
			array(
				'azienda_id' => (int) $azienda_id,
				'user_id'    => get_current_user_id(),
				'tipo'       => $tipo,
				'versione'   => apac_testo_versione( $d[0] ),
				'nome'       => $nome,
				'email'      => $u->user_email,
				'ruolo'      => 'accordo' === $tipo ? $ruolo : '',
				'ip'         => $ip,
				'user_agent' => $ua,
				'file_path'  => $rel_dir . '/' . $stored,
				'nome_file'  => $nome_file,
				'mime'       => 'application/pdf',
				'dimensione' => strlen( $pdf ),
				'created_at' => $quando,
			),
			'dashboard'
		);
	}
	// Email allo studio con il PDF (dopo il salvataggio; un errore di invio non annulla la firma).
	$abs = $abs_dir . '/' . $stored;
	apac_tx_after_commit(
		function () use ( $abs, $nome_file, $az, $nome, $firmati, $tipi ) {
			$tmp_dir = trailingslashit( get_temp_dir() ) . 'apac-' . wp_generate_password( 12, false, false );
			if ( ! wp_mkdir_p( $tmp_dir ) ) {
				return '';
			}
			$tmp = $tmp_dir . '/' . $nome_file;
			copy( $abs, $tmp );
			$elenco = implode( "\n", array_map( function ( $t ) use ( $tipi ) { return '- ' . $tipi[ $t ]; }, array_keys( $firmati ) ) );
			$GLOBALS['apac_mail_error'] = '';
			$ok = wp_mail(
				get_option( 'admin_email' ),
				'[Area clienti] Documenti firmati – ' . $az->ragione_sociale,
				"{$nome} ({$az->ragione_sociale}) ha firmato nell’area riservata:\n{$elenco}\n\nIn allegato il PDF con i testi, la firma, data, ora e indirizzo IP.\nUna copia resta nell’area riservata (Accessi → azienda).\n\n" . apac_portal_page_url(),
				apac_auto_headers(),
				array( $tmp )
			);
			wp_delete_file( $tmp );
			@rmdir( $tmp_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! $ok && function_exists( 'apac_record_mail_error' ) ) {
				apac_record_mail_error( 'documenti firmati', 'invio del PDF non riuscito' );
			}
			return '';
		}
	);
	if ( function_exists( 'apac_push_after_commit' ) ) {
		apac_push_after_commit(
			function () use ( $az, $nome ) {
				apac_push_admins( 'Documenti firmati', $nome . ' – ' . $az->ragione_sociale, apac_url( 'utenti', array( 'azienda' => (int) $az->id ) ) );
			}
		);
	}
	apac_log_detail( implode( ', ', array_map( function ( $t ) use ( $tipi ) { return $tipi[ $t ]; }, array_keys( $firmati ) ) ) . ' – ' . $nome );
	apac_redirect( 'dashboard', 'signed_docs' );
}

/* ============================== Studio: elenco e download ============================== */

function apac_consenso_url( $id ) {
	return wp_nonce_url( add_query_arg( array( 'apac_cons' => (int) $id ), apac_portal_page_url() ), 'apac_cons_' . (int) $id );
}

add_action( 'init', 'apac_serve_consenso', 19 );
function apac_serve_consenso() {
	global $wpdb;
	if ( is_admin() || ! isset( $_GET['apac_cons'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$id    = absint( $_GET['apac_cons'] ); // phpcs:ignore WordPress.Security.NonceVerification
	$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! is_user_logged_in() || ! wp_verify_nonce( $nonce, 'apac_cons_' . $id ) ) {
		wp_die( 'Link non valido o scaduto.', 'Accesso negato', array( 'response' => 403 ) );
	}
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'consensi' ) . ' WHERE id = %d', $id ) );
	// Lo studio vede tutto; il cliente solo le firme della propria azienda.
	if ( ! $row || ! apac_can_access_azienda( (int) $row->azienda_id ) ) {
		wp_die( 'File non disponibile.', 'Accesso negato', array( 'response' => 403 ) );
	}
	$path = apac_document_abs_path( $row );
	if ( ! $path || ! is_readable( $path ) ) {
		wp_die( 'File non trovato.', 'Errore', array( 'response' => 404 ) );
	}
	while ( ob_get_level() ) {
		ob_end_clean();
	}
	nocache_headers();
	header( 'Content-Type: application/pdf' );
	header( 'Content-Length: ' . filesize( $path ) );
	apac_download_header( $row->nome_file, 'inline' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}

/** Riquadro «Documenti firmati» per l'azienda (sezione Accessi). */
function apac_render_consensi_azienda( $az ) {
	global $wpdb;
	if ( ! $az ) {
		return;
	}
	$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'consensi' ) . ' WHERE azienda_id = %d ORDER BY id DESC', $az->id ) );
	$tipi    = apac_consensi_tipi();
	$accordo = apac_testo_versione( apac_accordo_testo( $az->ragione_sociale ) );
	$ok_acc  = false;
	foreach ( $rows as $r ) {
		$ok_acc = $ok_acc || ( 'accordo' === $r->tipo && $r->versione === $accordo );
	}
	$files = count( array_unique( wp_list_pluck( $rows, 'file_path' ) ) );
	$badge = $ok_acc ? '<span class="apac-badge apac-badge--ok">Accordo art. 28 firmato</span>' : '<span class="apac-badge apac-badge--warn">Accordo art. 28 da firmare</span>';
	// Riquadro richiudibile nella pagina Documenti dell'azienda.
	echo '<details class="apac-card apac-fold apac-signed"><summary><span>Documenti firmati' . ( $files ? ' <small>(' . (int) $files . ')</small>' : '' ) . '</span>' . $badge . '</summary><div class="apac-signed__body">'; // phpcs:ignore WordPress.Security.EscapeOutput
	if ( apac_is_admin() && ! apac_consensi_attivi() ) {
		echo '<p><span class="apac-badge apac-badge--muted">Richiesta firme disattivata nelle impostazioni</span></p>';
	}
	if ( ! $rows ) {
		echo '<p class="apac-muted">Nessun documento firmato. La firma viene richiesta al primo accesso degli utenti dell’azienda.</p></div></details>';
		return;
	}
	$seen = array();
	echo '<ul class="apac-list apac-signs__list">';
	foreach ( $rows as $r ) {
		if ( isset( $seen[ $r->file_path ] ) ) {
			continue;
		}
		$seen[ $r->file_path ] = true;
		$cosa = array();
		foreach ( $rows as $x ) {
			if ( $x->file_path === $r->file_path ) {
				$cosa[] = $tipi[ $x->tipo ] ?? $x->tipo;
			}
		}
		echo '<li><span><strong>' . esc_html( $r->nome ) . '</strong><small>' . esc_html( implode( ' · ', $cosa ) ) . '</small><small>' . esc_html( mysql2date( 'd/m/Y H:i', $r->created_at ) . ' · ' . $r->email ) . '</small></span><a class="apac-btn apac-btn--small apac-btn--ghost" href="' . esc_url( apac_consenso_url( $r->id ) ) . '" target="_blank" rel="noopener">Apri PDF</a></li>';
	}
	echo '</ul></div></details>';
}

/* ============================== Impostazioni ============================== */

add_action( 'admin_post_apac_save_consensi', 'apac_admin_save_consensi' );
function apac_admin_save_consensi() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'apac_save_consensi' ) ) {
		wp_die( 'Non consentito.' );
	}
	update_option( 'apac_consensi_attivi', empty( $_POST['apac_consensi_attivi'] ) ? '0' : '1' );
	$t = isset( $_POST['apac_accordo_testo'] ) ? sanitize_textarea_field( wp_unslash( $_POST['apac_accordo_testo'] ) ) : '';
	if ( isset( $_POST['ripristina'] ) || trim( $t ) === trim( apac_accordo_default() ) ) {
		$t = '';
	}
	update_option( 'apac_accordo_testo', $t, false );
	wp_safe_redirect( admin_url( 'options-general.php?page=apac-settings&saved=1#apac-consensi' ) );
	exit;
}

function apac_render_consensi_settings() {
	$t = (string) get_option( 'apac_accordo_testo', '' );
	echo '<h2 id="apac-consensi">Firme al primo accesso</h2>';
	echo '<p>Al primo accesso ogni utente dei clienti firma con il dito o con il mouse la presa visione dell’<a href="' . esc_url( get_privacy_policy_url() ? get_privacy_policy_url() : home_url( '/privacy-policy/' ) ) . '" target="_blank" rel="noopener">informativa privacy</a> pubblicata; l’accordo art. 28 viene firmato una volta per azienda da chi ha i poteri di firma. Il PDF firmato arriva a <strong>' . esc_html( get_option( 'admin_email' ) ) . '</strong>. Se modifichi un testo, la firma viene richiesta di nuovo.</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'apac_save_consensi' );
	echo '<input type="hidden" name="action" value="apac_save_consensi"><table class="form-table">';
	echo '<tr><th scope="row">Richiesta firme</th><td><label><input type="checkbox" name="apac_consensi_attivi" value="1"' . checked( apac_consensi_attivi(), true, false ) . '> Attiva</label></td></tr>';
	echo '<tr><th scope="row"><label for="apac_accordo_testo">Testo dell’accordo art. 28</label></th><td><textarea id="apac_accordo_testo" name="apac_accordo_testo" rows="18" class="large-text code">' . esc_textarea( '' !== trim( $t ) ? $t : apac_accordo_default() ) . '</textarea><p class="description">Righe che iniziano con «# » = titolo, «## » = sezione, «- » = elenco. {azienda} viene sostituito con il nome dell’azienda. Fai verificare il testo da un consulente privacy.</p></td></tr></table>';
	submit_button( 'Salva', 'primary', 'submit', false );
	echo ' ';
	submit_button( 'Ripristina testo predefinito', 'secondary', 'ripristina', false );
	echo '</form>';
}

/* ============================== PDF ============================== */

/** Larghezze dei caratteri Helvetica (unità /1000) per il calcolo degli a capo. */
function apac_pdf_width( $s, $size, $bold = false ) {
	static $w = null;
	if ( null === $w ) {
		$w = array_combine(
			str_split( ' !"#$%&\'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~' ),
			array( 278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584 )
		);
	}
	$t = 0;
	$n = strlen( $s );
	for ( $i = 0; $i < $n; $i++ ) {
		$t += $w[ $s[ $i ] ] ?? 600;
	}
	return $t * $size / 1000 * ( $bold ? 1.1 : 1 );
}

/** Testo UTF-8 -> Windows-1252 (codifica dei font standard del PDF). */
function apac_pdf_enc( $s ) {
	$r = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT', (string) $s ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	return false === $r ? preg_replace( '/[^\x20-\x7E]/', '?', (string) $s ) : $r;
}

function apac_pdf_wrap( $text, $size, $maxw, $bold = false ) {
	$lines = array();
	$cur   = '';
	foreach ( preg_split( '/ +/', $text ) as $word ) {
		$try = '' === $cur ? $word : $cur . ' ' . $word;
		if ( apac_pdf_width( $try, $size, $bold ) <= $maxw || '' === $cur ) {
			$cur = $try;
		} else {
			$lines[] = $cur;
			$cur     = $word;
		}
	}
	if ( '' !== $cur ) {
		$lines[] = $cur;
	}
	return $lines;
}

/**
 * PDF A4 con: titolo, dati della firma, testi firmati, immagine della firma.
 * $docs: [ [ titolo, testo a righe, dichiarazione ], ... ]
 */
function apac_pdf_firma( $title, $docs, $jpeg, $meta ) {
	$W      = 595.28;
	$H      = 841.89;
	$m      = 56;
	$maxw   = $W - 2 * $m;
	$green  = '0.098 0.243 0.047';
	$dark   = '0.024 0.110 0.012';
	$yellow = '0.992 0.882 0.090';
	$gray   = '0.35 0.38 0.35';
	$top1   = $H - 132; // Prima pagina: sotto l'intestazione completa.
	$topN   = $H - 82;  // Pagine successive: intestazione ridotta.
	$bottom = 64;       // Sopra il piè di pagina.
	$pages  = array();
	$ops    = '';
	$y      = $top1;
	$esc    = function ( $s ) {
		return str_replace( array( '\\', '(', ')', "\r" ), array( '\\\\', '\\(', '\\)', '' ), $s );
	};
	$newpage = function () use ( &$pages, &$ops, &$y, $topN ) {
		if ( '' !== $ops ) {
			$pages[] = $ops;
		}
		$ops = '';
		$y   = $topN;
	};
	$text = function ( $s, $size, $bold = false, $indent = 0, $gap = 0.35, $color = '0 0 0' ) use ( &$ops, &$y, $m, $maxw, $esc, $newpage, $bottom ) {
		$enc = apac_pdf_enc( $s );
		foreach ( apac_pdf_wrap( $enc, $size, $maxw - $indent, $bold ) as $line ) {
			if ( $y - $size * ( 1 + $gap ) < $bottom ) {
				$newpage();
			}
			$y   -= $size * ( 1 + $gap );
			$ops .= sprintf( "BT %s rg /%s %.1F Tf %.2F %.2F Td (%s) Tj ET\n", $color, $bold ? 'F2' : 'F1', $size, $m + $indent, $y, $esc( $line ) );
		}
	};
	$space = function ( $h ) use ( &$y ) {
		$y -= $h;
	};

	$text( $title, 16, true, 0, 0.35, $green );
	$space( 2 );
	$text( 'Documento generato automaticamente dall’area riservata di AP Sicurezza Lavoro al momento della firma elettronica.', 8.5, false, 0, 0.35, $gray );
	$space( 10 );
	// Riquadro dati firma (sfondo inserito dietro al testo, se resta sulla stessa pagina).
	$box_at    = strlen( $ops );
	$box_pages = count( $pages );
	$box_top   = $y;
	$space( 4 );
	foreach ( $meta as $k => $v ) {
		$text( $k . ': ' . $v, 9.5, false, 10 );
	}
	$space( 8 );
	if ( count( $pages ) === $box_pages ) {
		$rect = sprintf( "q 0.957 0.973 0.949 rg %.2F %.2F %.2F %.2F re f Q\nq %s rg %.2F %.2F 3 %.2F re f Q\n", $m, $y, $maxw, $box_top - $y, $green, $m, $y, $box_top - $y );
		$ops  = substr( $ops, 0, $box_at ) . $rect . substr( $ops, $box_at );
	}
	// Firma subito sotto ai dati.
	$sz = getimagesizefromstring( $jpeg );
	$iw = 220;
	$ih = $iw * $sz[1] / $sz[0];
	$space( 10 );
	$text( 'Firma', 10.5, true, 0, 0.35, $green );
	if ( $y - $ih - 8 < $bottom ) {
		$newpage();
	}
	$y   -= $ih + 8;
	$ops .= sprintf( "q %s RG 0.8 w %.2F %.2F %.2F %.2F re S Q\nq %.2F 0 0 %.2F %.2F %.2F cm /Im1 Do Q\n", $green, $m - 3, $y - 3, $iw + 6, $ih + 6, $iw, $ih, $m, $y );
	$space( 14 );
	foreach ( $docs as $d ) {
		$text( 'Dichiarazione: ' . $d[2], 10, true, 0, 0.35, $dark );
	}
	foreach ( $docs as $d ) {
		$newpage();
		$text( $d[0], 13, true, 0, 0.35, $green );
		$ops .= sprintf( "q %s rg %.2F %.2F 60 2.5 re f Q\n", $yellow, $m, $y - 6 );
		$space( 12 );
		foreach ( preg_split( '/\R/u', $d[1] ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( 0 === strpos( $line, '### ' ) ) {
				$space( 4 );
				$text( substr( $line, 4 ), 10, true, 0, 0.35, $dark );
			} elseif ( 0 === strpos( $line, '## ' ) ) {
				$space( 6 );
				$text( substr( $line, 3 ), 11, true, 0, 0.35, $green );
			} elseif ( 0 === strpos( $line, '# ' ) ) {
				$text( substr( $line, 2 ), 12, true, 0, 0.35, $green );
				$space( 4 );
			} elseif ( 0 === strpos( $line, '- ' ) ) {
				$text( '•  ' . substr( $line, 2 ), 9.5, false, 10 );
			} else {
				$text( $line, 9.5 );
				$space( 2 );
			}
		}
	}
	$pages[] = $ops;

	// Intestazione e piè di pagina con i dati dello studio.
	$logo   = @file_get_contents( APAC_DIR . 'assets/logo-pdf.jpg' );
	$lsz    = $logo ? getimagesizefromstring( $logo ) : false;
	$has_lg = $logo && $lsz;
	$t      = function ( $s, $size, $bold, $x, $yy, $color ) use ( $esc ) {
		return sprintf( "BT %s rg /%s %.1F Tf %.2F %.2F Td (%s) Tj ET\n", $color, $bold ? 'F2' : 'F1', $size, $x, $yy, $esc( apac_pdf_enc( $s ) ) );
	};
	$header = function ( $first ) use ( $W, $H, $m, $maxw, $green, $yellow, $gray, $has_lg, $lsz, $t ) {
		$o = '';
		if ( $first ) {
			$lh = 66;
			$lw = $has_lg ? $lh * $lsz[0] / $lsz[1] : 0;
			$ty = $H - 34;
			if ( $has_lg ) {
				$o .= sprintf( "q %.2F 0 0 %.2F %.2F %.2F cm /Im2 Do Q\n", $lw, $lh, $m, $ty - $lh );
			}
			$x  = $m + ( $has_lg ? $lw + 14 : 0 );
			$o .= $t( 'AP Sicurezza Lavoro', 17, true, $x, $ty - 18, $green );
			$o .= $t( 'Dott. Antonino Porcelli · Consulente QHSE · Formatore', 9, false, $x, $ty - 32, $gray );
			$o .= $t( 'Via Santocanale 74, 90151 Palermo (PA) · P. IVA 07436760826', 8.5, false, $x, $ty - 45, $gray );
			$o .= $t( 'info@apsicurezzalavoro.it · +39 351 885 8554 · apsicurezzalavoro.it', 8.5, false, $x, $ty - 57, $gray );
			$ry = $ty - $lh - 10;
			$o .= sprintf( "q %s rg %.2F %.2F %.2F 3 re f Q\nq %s rg %.2F %.2F 90 3 re f Q\n", $green, $m, $ry, $maxw, $yellow, $m, $ry );
		} else {
			$lh = 30;
			$lw = $has_lg ? $lh * $lsz[0] / $lsz[1] : 0;
			$ty = $H - 30;
			if ( $has_lg ) {
				$o .= sprintf( "q %.2F 0 0 %.2F %.2F %.2F cm /Im2 Do Q\n", $lw, $lh, $m, $ty - $lh );
			}
			$x  = $m + ( $has_lg ? $lw + 8 : 0 );
			$o .= $t( 'AP Sicurezza Lavoro', 11, true, $x, $ty - 13, $green );
			$o .= $t( 'Documenti firmati nell’area riservata', 8, false, $x, $ty - 25, $gray );
			$ry = $ty - $lh - 8;
			$o .= sprintf( "q %s rg %.2F %.2F %.2F 1.5 re f Q\nq %s rg %.2F %.2F 60 1.5 re f Q\n", $green, $m, $ry, $maxw, $yellow, $m, $ry );
		}
		return $o;
	};
	$footer = function ( $i, $n ) use ( $W, $m, $maxw, $green, $gray, $t ) {
		$o   = sprintf( "q %s rg %.2F 46 %.2F 0.8 re f Q\n", $green, $m, $maxw );
		$o  .= $t( 'AP Sicurezza Lavoro · Via Santocanale 74, 90151 Palermo (PA) · P. IVA 07436760826', 7.5, false, $m, 34, $gray );
		$o  .= $t( 'info@apsicurezzalavoro.it · +39 351 885 8554', 7.5, false, $m, 24, $gray );
		$pg  = 'Pagina ' . $i . ' di ' . $n;
		$o  .= $t( $pg, 8, true, $W - $m - apac_pdf_width( apac_pdf_enc( $pg ), 8, true ), 34, $green );
		return $o;
	};

	// Oggetti PDF.
	$n      = count( $pages );
	$objs   = array();
	$objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
	$kids   = array();
	$first  = 7;
	for ( $i = 0; $i < $n; $i++ ) {
		$kids[] = ( $first + 2 * $i ) . ' 0 R';
	}
	$objs[2] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . $n . ' >>';
	$objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
	$objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
	$objs[5] = "<< /Type /XObject /Subtype /Image /Width {$sz[0]} /Height {$sz[1]} /ColorSpace /" . ( isset( $sz['channels'] ) && 1 === (int) $sz['channels'] ? 'DeviceGray' : 'DeviceRGB' ) . ' /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen( $jpeg ) . " >>\nstream\n" . $jpeg . "\nendstream";
	$xobj    = '/Im1 5 0 R';
	if ( $has_lg ) {
		$objs[6] = "<< /Type /XObject /Subtype /Image /Width {$lsz[0]} /Height {$lsz[1]} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen( $logo ) . " >>\nstream\n" . $logo . "\nendstream";
		$xobj   .= ' /Im2 6 0 R';
	} else {
		$objs[6] = '<< >>';
	}
	foreach ( $pages as $i => $content ) {
		$stream  = gzcompress( $header( 0 === $i ) . $content . $footer( $i + 1, $n ) );
		$objs[ $first + 2 * $i ]     = sprintf( '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << %s >> >> /Contents %d 0 R >>', $W, $H, $xobj, $first + 2 * $i + 1 );
		$objs[ $first + 2 * $i + 1 ] = '<< /Length ' . strlen( $stream ) . " /Filter /FlateDecode >>\nstream\n" . $stream . "\nendstream";
	}
	ksort( $objs );
	$out  = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
	$xref = array();
	foreach ( $objs as $num => $body ) {
		$xref[ $num ] = strlen( $out );
		$out         .= $num . " 0 obj\n" . $body . "\nendobj\n";
	}
	$start = strlen( $out );
	$max   = max( array_keys( $objs ) );
	$out  .= "xref\n0 " . ( $max + 1 ) . "\n0000000000 65535 f \n";
	for ( $i = 1; $i <= $max; $i++ ) {
		$out .= sprintf( "%010d 00000 n \n", $xref[ $i ] ?? 0 );
	}
	$out .= "trailer\n<< /Size " . ( $max + 1 ) . ' /Root 1 0 R /Info << /Producer (AP Area Clienti) /Author (AP Sicurezza Lavoro) /Title (' . $esc( apac_pdf_enc( $title ) ) . ") >> >>\nstartxref\n{$start}\n%%EOF\n";
	return $out;
}
