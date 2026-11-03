<?php
/**
 * 1.9.13 – Richieste di accesso.
 *
 * Chi non ha un account compila il modulo «Richiedi accesso»: dati dell'azienda e del referente.
 * La richiesta NON crea nulla: va confermata dal link inviato all'email indicata e poi arriva
 * allo studio, che la approva (azienda + utenza create in un solo passaggio, con la procedura
 * di creazione accesso già esistente) oppure la rifiuta. Lavoratori, attestati e documenti si
 * caricano solo dopo l'accesso e dopo le firme del primo accesso (informativa, accordo art. 28).
 *
 * Un cliente già attivo può chiedere un accesso per un collega dall'interno dell'area: anche
 * questa richiesta la approva lo studio.
 */
defined( 'ABSPATH' ) || exit;

const APAC_RICH_LINK_ORE     = 48;  // Validità del link di conferma.
const APAC_RICH_BLOCCO_GG    = 30;  // Dopo un rifiuto: nuove richieste bloccate (stessa email o partita IVA).
const APAC_RICH_CONSERVA_GG  = 90;  // Richieste gestite: eliminate dopo questo periodo.
const APAC_RICH_NONCONF_GG   = 7;   // Richieste mai confermate: eliminate dopo questo periodo.
const APAC_RICH_MAX_APERTE   = 50;  // Oltre questo numero di richieste aperte il modulo si sospende.
const APAC_RICH_MAX_IP_ORA   = 10;  // Invii per indirizzo in un'ora.
const APAC_RICH_MIN_SECONDI  = 3;   // Tempo minimo di compilazione (sotto: invio automatico).
const APAC_RICH_MAX_ORE_FORM = 6;   // Modulo aperto da troppo tempo: si chiede di inviarlo di nuovo.
const APAC_RICH_MAX_COLLEGHI = 10;  // Richieste per colleghi in attesa, per azienda.
const APAC_RICH_MAX_PER_EMAIL = 3;  // 1.9.14: richieste da confermare per lo stesso indirizzo nelle ultime 48 ore.

/** Ruoli proposti nel modulo. */
function apac_rich_ruoli() {
	return array(
		''           => '— scegli —',
		'datore'     => 'Datore di lavoro / titolare',
		'legale'     => 'Legale rappresentante',
		'delegato'   => 'Incaricato dell’azienda (personale, amministrazione, sicurezza)',
		'consulente' => 'Consulente esterno (commercialista, consulente del lavoro…)',
		'altro'      => 'Altro',
	);
}

/** Campi aziendali raccolti nella richiesta (stessi nomi della scheda azienda). */
function apac_rich_campi_azienda() {
	return array( 'ragione_sociale', 'partita_iva', 'codice_fiscale', 'ateco', 'indirizzo', 'comune', 'provincia', 'telefono', 'email', 'pec', 'datore_lavoro' );
}

/** Partita IVA: 11 cifre con cifra di controllo corretta (algoritmo di Luhn sulle prime 10). */
function apac_piva_checksum_ok( $piva ) {
	if ( ! preg_match( '/^\d{11}$/', (string) $piva ) ) {
		return false;
	}
	$s = 0;
	for ( $i = 0; $i < 10; $i++ ) {
		$d = (int) $piva[ $i ];
		if ( 1 === $i % 2 ) {
			$d *= 2;
			if ( $d > 9 ) {
				$d -= 9;
			}
		}
		$s += $d;
	}
	return ( ( 10 - $s % 10 ) % 10 ) === (int) $piva[10];
}

function apac_rich_get( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'richieste' ) . ' WHERE id = %d', (int) $id ) );
}

/** Data/ora locale di N secondi fa, nel formato del database. */
function apac_rich_ago( $seconds ) {
	return wp_date( 'Y-m-d H:i:s', time() - (int) $seconds );
}

/** Numero di richieste in attesa dello studio (per il contatore del menu). */
function apac_rich_count_attesa() {
	static $n = null;
	if ( null === $n ) {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$n        = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . apac_table( 'richieste' ) . " WHERE stato = 'in_attesa'" );
		$wpdb->suppress_errors( $suppress );
	}
	return $n;
}

/**
 * 1.9.14 – Indirizzo IP del visitatore.
 *
 * Di norma REMOTE_ADDR. Se il sito è dietro un proxy o una CDN (es. Cloudflare), REMOTE_ADDR è l'indirizzo
 * del proxy, uguale per tutti: il limite di invii diventerebbe unico per tutto il sito. In quel caso in
 * wp-config.php si indica l'intestazione con l'IP reale, per esempio:
 *   define( 'APAC_IP_HEADER', 'HTTP_CF_CONNECTING_IP' );   // Cloudflare
 *   define( 'APAC_IP_HEADER', 'HTTP_X_REAL_IP' );          // proxy che imposta X-Real-IP
 * L'intestazione NON viene letta se la costante non è definita: un visitatore potrebbe falsificarla.
 * Con X-Forwarded-For si usa l'ultimo indirizzo (quello aggiunto dal proxy, non dal visitatore).
 */
function apac_rich_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( defined( 'APAC_IP_HEADER' ) && is_string( APAC_IP_HEADER ) && '' !== APAC_IP_HEADER && ! empty( $_SERVER[ APAC_IP_HEADER ] ) ) {
		$parts = array_map( 'trim', explode( ',', (string) wp_unslash( $_SERVER[ APAC_IP_HEADER ] ) ) );
		$cand  = (string) end( $parts );
		if ( false !== filter_var( $cand, FILTER_VALIDATE_IP ) ) {
			$ip = $cand;
		}
	}
	$ip = (string) apply_filters( 'apac_rich_client_ip', $ip );
	return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

/**
 * Impronta dell'indirizzo IP (non si conserva l'indirizzo in chiaro).
 * 1.9.14: per IPv6 si usa la rete /64 (un singolo utente ne ha di solito una intera e può cambiare
 * indirizzo a piacere: contare il singolo indirizzo renderebbe inutile il limite).
 */
function apac_rich_ip_hash() {
	$ip = apac_rich_client_ip();
	if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
		$bin = inet_pton( $ip );
		if ( false !== $bin && 16 === strlen( $bin ) ) {
			$ip = bin2hex( substr( $bin, 0, 8 ) ) . '::/64';
		}
	}
	return hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
}

/**
 * 1.9.14 – Conta un invio per questo indirizzo nell'ora corrente. Lettura e scrittura del contatore
 * avvengono sotto un lock del database: invii contemporanei non possono superare il limite.
 *
 * @return string 'ok', 'troppe' oppure 'errore' (lock non ottenuto).
 */
function apac_rich_ip_hit( $ip_hash ) {
	global $wpdb;
	$tkey = 'apac_rich_ip_' . substr( $ip_hash, 0, 32 );
	$lock = 'apac_rip_' . substr( $ip_hash, 0, 40 );
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
		return 'errore';
	}
	$hits = (int) get_transient( $tkey );
	if ( $hits >= APAC_RICH_MAX_IP_ORA ) {
		$esito = 'troppe';
	} else {
		set_transient( $tkey, $hits + 1, HOUR_IN_SECONDS );
		$esito = 'ok';
	}
	$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	return $esito;
}

/** Codice del modulo: ora di apertura firmata (non dipende da sessioni o cache della pagina). */
function apac_rich_form_token() {
	$t = (string) time();
	return $t . '.' . hash_hmac( 'sha256', 'apac_rich|' . $t, wp_salt( 'nonce' ) );
}

/** 'ok', 'fast' (inviato troppo presto: automatico), 'old' (aperto da troppo tempo), 'bad'. */
function apac_rich_form_token_check( $token ) {
	$p = explode( '.', (string) $token );
	if ( 2 !== count( $p ) || ! ctype_digit( $p[0] ) || ! hash_equals( hash_hmac( 'sha256', 'apac_rich|' . $p[0], wp_salt( 'nonce' ) ), $p[1] ) ) {
		return 'bad';
	}
	$age = time() - (int) $p[0];
	if ( $age < APAC_RICH_MIN_SECONDI ) {
		return 'fast';
	}
	return $age > APAC_RICH_MAX_ORE_FORM * HOUR_IN_SECONDS ? 'old' : 'ok';
}

/* ======================= Invio del modulo (visitatore) ======================= */

add_action( 'init', 'apac_rich_router', 21 );
function apac_rich_router() {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	if ( 'POST' !== $method ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification -- codice firmato verificato in ogni ramo.
	if ( isset( $_POST['apac_richiesta'] ) ) {
		nocache_headers();
		if ( is_user_logged_in() ) {
			wp_safe_redirect( apac_portal_page_url() );
			exit;
		}
		$esito = apac_rich_submit();
		if ( is_string( $esito ) ) {
			wp_safe_redirect( add_query_arg( 'accesso', $esito, apac_portal_page_url() ) );
			exit;
		}
		$GLOBALS['apac_rich_err'] = $esito; // Errori: la pagina ripropone il modulo con i dati inseriti.
	}
	if ( isset( $_POST['apac_rich_conferma'] ) ) {
		nocache_headers();
		$esito = apac_rich_confirm( absint( $_POST['r'] ?? 0 ), sanitize_text_field( wp_unslash( $_POST['t'] ?? '' ) ) );
		wp_safe_redirect( add_query_arg( 'accesso', $esito, apac_portal_page_url() ) );
		exit;
	}
	// phpcs:enable
}

/** Valore inviato, ripulito. */
function apac_rich_in( $k, $max = 200 ) {
	return mb_substr( trim( sanitize_text_field( (string) apac_post( $k ) ) ), 0, $max + 1 );
}

/**
 * Elabora il modulo. Restituisce il codice della pagina di esito (string) oppure
 * un array campo => messaggio con gli errori da correggere.
 */
function apac_rich_submit() {
	global $wpdb;
	$err = array();

	// Protezioni contro gli invii automatici.
	$tok = apac_rich_form_token_check( apac_post( 'rt' ) );
	if ( '' !== (string) apac_post( 'sito_web' ) || 'fast' === $tok ) {
		return 'inviata'; // Al programma automatico si risponde come a tutti, ma non si salva nulla.
	}
	if ( 'ok' !== $tok ) {
		return array( '_form' => 'Il modulo è rimasto aperto troppo a lungo o non è più valido: controlla i dati e premi di nuovo «Invia la richiesta».' );
	}

	$tipo = 'esistente' === apac_post( 'tipo' ) ? 'esistente' : 'nuova';
	$az   = array();
	foreach ( apac_rich_campi_azienda() as $f ) {
		// Già clienti: contano solo ragione sociale e partita IVA (gli altri campi nascosti si ignorano).
		$az[ $f ] = ( 'nuova' === $tipo || in_array( $f, array( 'ragione_sociale', 'partita_iva' ), true ) ) ? apac_rich_in( $f, 255 ) : '';
	}
	$req = 'nuova' === $tipo ? array( 'ragione_sociale', 'partita_iva', 'comune' ) : array( 'ragione_sociale', 'partita_iva' );
	foreach ( $req as $f ) {
		if ( '' === $az[ $f ] ) {
			$err[ $f ] = 'Campo obbligatorio.';
		}
	}
	$piva = apac_normalize_piva( $az['partita_iva'] );
	if ( '' !== $az['partita_iva'] && ( false === $piva || ! apac_piva_checksum_ok( $piva ) ) ) {
		$err['partita_iva'] = 'Partita IVA non valida: controlla le 11 cifre.';
	} else {
		$az['partita_iva'] = (string) $piva;
	}
	$checks = array(
		'codice_fiscale' => array( 'apac_normalize_cf', 'Codice fiscale non valido.' ),
		'telefono'       => array( 'apac_normalize_phone', 'Numero non valido: solo cifre, spazi e + ( ) - /.' ),
		'email'          => array( 'apac_normalize_email', 'Indirizzo email non valido.' ),
		'pec'            => array( 'apac_normalize_email', 'Indirizzo PEC non valido.' ),
	);
	foreach ( $checks as $f => $c ) {
		$v = call_user_func( $c[0], $az[ $f ] );
		if ( false === $v ) {
			$err[ $f ] = $c[1];
		} else {
			$az[ $f ] = (string) $v;
		}
	}
	$az['provincia'] = strtoupper( $az['provincia'] );
	if ( '' !== $az['provincia'] && ! preg_match( '/^[A-Z]{2}$/', $az['provincia'] ) ) {
		$err['provincia'] = 'Usa la sigla di due lettere (es. PA).';
	}
	$max = apac_max_lengths();
	foreach ( $az as $f => $v ) {
		if ( ! isset( $err[ $f ] ) && isset( $max[ $f ] ) && mb_strlen( $v ) > $max[ $f ] ) {
			$err[ $f ] = 'Testo troppo lungo (massimo ' . (int) $max[ $f ] . ' caratteri).';
		}
	}

	// Referente.
	$ref = array(
		'nome'     => apac_rich_in( 'ref_nome', 100 ),
		'cognome'  => apac_rich_in( 'ref_cognome', 100 ),
		'email'    => strtolower( apac_rich_in( 'ref_email', 150 ) ),
		'telefono' => apac_rich_in( 'ref_telefono', 40 ),
		'ruolo'    => (string) apac_post( 'ref_ruolo' ),
	);
	foreach ( array( 'nome', 'cognome', 'email' ) as $f ) {
		if ( '' === $ref[ $f ] ) {
			$err[ 'ref_' . $f ] = 'Campo obbligatorio.';
		} elseif ( mb_strlen( $ref[ $f ] ) > 100 ) {
			$err[ 'ref_' . $f ] = 'Testo troppo lungo (massimo 100 caratteri).';
		}
	}
	if ( '' !== $ref['email'] && ! isset( $err['ref_email'] ) && ! apac_normalize_email( $ref['email'] ) ) {
		$err['ref_email'] = 'Indirizzo email non valido: controlla di averlo scritto per intero (es. nome@azienda.it).';
	}
	$tel = apac_normalize_phone( $ref['telefono'] );
	if ( false === $tel ) {
		$err['ref_telefono'] = 'Numero non valido: solo cifre, spazi e + ( ) - /.';
	}
	$ruoli = apac_rich_ruoli();
	if ( '' === $ref['ruolo'] || ! isset( $ruoli[ $ref['ruolo'] ] ) ) {
		$err['ref_ruolo'] = 'Scegli il tuo ruolo in azienda.';
	}
	$messaggio = mb_substr( trim( sanitize_textarea_field( (string) apac_post( 'messaggio' ) ) ), 0, 1001 );
	if ( mb_strlen( $messaggio ) > 1000 ) {
		$err['messaggio'] = 'Massimo 1000 caratteri.';
	}
	if ( ! apac_post( 'privacy' ) ) {
		$err['privacy'] = 'Per inviare la richiesta conferma di aver letto l’informativa privacy.';
	}
	if ( ! apac_post( 'autorizzato' ) ) {
		$err['autorizzato'] = 'Conferma di essere autorizzato a chiedere l’accesso per questa azienda.';
	}
	if ( $err ) {
		return $err;
	}

	// Limiti: invii dallo stesso indirizzo (contatore protetto da lock) e richieste aperte in totale.
	$ip  = apac_rich_ip_hash();
	$hit = apac_rich_ip_hit( $ip );
	if ( 'ok' !== $hit ) {
		return $hit;
	}
	// 1.9.14: contano solo le richieste CONFERMATE in attesa dello studio. Quelle da confermare non
	// sospendono più il modulo: bastavano 50 email inventate per bloccarlo a tutti fino a 7 giorni.
	// Le richieste non confermate restano limitate per indirizzo IP e per email e si cancellano da sole.
	$aperte = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . apac_table( 'richieste' ) . " WHERE stato = 'in_attesa'" );
	if ( $aperte >= APAC_RICH_MAX_APERTE ) {
		return 'sospeso';
	}

	$email = $ref['email'];
	$lock  = 'apac_rich_' . md5( $email );
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
		return 'errore';
	}
	$esito = apac_rich_store( $tipo, $az, $ref, (string) $tel, $messaggio, $ip );
	$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	return $esito;
}

/**
 * 1.9.14 – Quota di email automatiche verso lo stesso indirizzo (APAC_RICH_MAX_PER_EMAIL ogni 48 ore),
 * per tutti i tipi di risposta (account esistente, rifiuto recente, richiesta già in esame, conferma).
 * Chiamata con il lock dell'indirizzo già preso (apac_rich_submit): lettura e scrittura non si sovrappongono.
 *
 * @return bool true se si può inviare (e l'invio viene contato).
 */
function apac_rich_mail_quota( $email ) {
	$key = 'apac_rich_em_' . md5( strtolower( (string) $email ) );
	$n   = (int) get_transient( $key );
	if ( $n >= APAC_RICH_MAX_PER_EMAIL ) {
		return false;
	}
	set_transient( $key, $n + 1, APAC_RICH_LINK_ORE * HOUR_IN_SECONDS );
	return true;
}

/**
 * Salva la richiesta (o gestisce i casi particolari) e invia l'email. A video l'esito è
 * sempre lo stesso («controlla la tua email»): il sito non rivela se un indirizzo ha già un account.
 */
function apac_rich_store( $tipo, $az, $ref, $tel, $messaggio, $ip ) {
	global $wpdb;
	$t     = apac_table( 'richieste' );
	$email = $ref['email'];
	$home  = apac_portal_page_url();

	// 1. Email di un account esistente: si avvisa solo il proprietario dell'indirizzo.
	if ( email_exists( $email ) || username_exists( $email ) ) {
		if ( ! apac_rich_mail_quota( $email ) ) {
			return 'inviata'; // Quota superata: stessa risposta a video, nessuna email.
		}
		$ok = apac_send_mail(
			$email,
			'Area riservata AP Sicurezza Lavoro – hai già un account',
			"Buongiorno,\n\nabbiamo ricevuto una richiesta di accesso all'area riservata per questo indirizzo email, che però ha già un account.\n\nPer entrare apri " . $home . "\nSe non ricordi la password usa «Password dimenticata?»: " . wp_lostpassword_url( $home ) . "\n\nSe non hai fatto tu la richiesta puoi ignorare questo messaggio.\n\nAP Sicurezza Lavoro",
			apac_auto_headers()
		);
		return $ok ? 'inviata' : 'errore_mail';
	}

	// 2. Rifiuto recente con la stessa email o la stessa partita IVA.
	$rif = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE stato = 'rifiutata' AND gestita_at >= %s AND ( email = %s OR ( partita_iva <> '' AND partita_iva = %s ) ) LIMIT 1", apac_rich_ago( APAC_RICH_BLOCCO_GG * DAY_IN_SECONDS ), $email, $az['partita_iva'] ) );
	if ( $rif ) {
		if ( ! apac_rich_mail_quota( $email ) ) {
			return 'inviata'; // Quota superata: stessa risposta a video, nessuna email.
		}
		$ok = apac_send_mail(
			$email,
			'Area riservata AP Sicurezza Lavoro – richiesta di accesso',
			"Buongiorno,\n\nper questa azienda o per questo indirizzo email è stata esaminata di recente una richiesta di accesso che non è stata accolta, quindi non possiamo registrarne una nuova online.\n\nPer chiarimenti contatta direttamente lo studio: " . home_url( '/' ) . "\n\nAP Sicurezza Lavoro",
			apac_auto_headers()
		);
		return $ok ? 'inviata' : 'errore_mail';
	}

	// 3. Richiesta già all'esame dello studio.
	$attesa = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE stato = 'in_attesa' AND email = %s LIMIT 1", $email ) );
	if ( $attesa ) {
		if ( ! apac_rich_mail_quota( $email ) ) {
			return 'inviata'; // Quota superata: stessa risposta a video, nessuna email.
		}
		$ok = apac_send_mail(
			$email,
			'Area riservata AP Sicurezza Lavoro – richiesta già ricevuta',
			"Buongiorno,\n\nla tua richiesta di accesso all'area riservata è già stata ricevuta ed è all'esame dello studio: riceverai un'email appena sarà gestita.\n\nAP Sicurezza Lavoro",
			apac_auto_headers()
		);
		return $ok ? 'inviata' : 'errore_mail';
	}

	// 4. Nuova richiesta (o nuova versione di una non ancora confermata con lo stesso indirizzo).
	$token = wp_generate_password( 32, false, false );
	$data  = array(
		'stato'           => 'da_confermare',
		'tipo'            => $tipo,
		'ragione_sociale' => $az['ragione_sociale'],
		'partita_iva'     => $az['partita_iva'],
		'dati'            => wp_json_encode( $az ),
		'nome'            => $ref['nome'],
		'cognome'         => $ref['cognome'],
		'email'           => $email,
		'telefono'        => $tel,
		'ruolo'           => $ref['ruolo'],
		'messaggio'       => $messaggio,
		'token_hash'      => hash( 'sha256', $token ),
		'ip_hash'         => $ip,
		'inviata_at'      => apac_now(),
	);
	// 1.9.14: una richiesta da confermare non viene più sovrascritta da un nuovo invio con la stessa email
	// (chiunque poteva sostituirne i dati e invalidare il link già ricevuto): ogni invio è una richiesta a sé,
	// con il proprio link. Al massimo APAC_RICH_MAX_PER_EMAIL email di conferma in 48 ore per indirizzo:
	// oltre, si risponde come sempre ma non si invia nulla (il sito non può essere usato per tempestare
	// di email una casella).
	$recenti = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE stato = 'da_confermare' AND email = %s AND inviata_at >= %s", $email, apac_rich_ago( APAC_RICH_LINK_ORE * HOUR_IN_SECONDS ) ) );
	if ( $recenti >= APAC_RICH_MAX_PER_EMAIL || ! apac_rich_mail_quota( $email ) ) {
		return 'inviata';
	}
	$data['created_at'] = apac_now();
	$ok                 = false !== $wpdb->insert( $t, $data );
	$id                 = (int) $wpdb->insert_id;
	if ( ! $ok || ! $id ) {
		return 'errore';
	}
	$link = add_query_arg(
		array(
			'accesso' => 'conferma',
			'r'       => $id,
			't'       => $token,
		),
		$home
	);
	$sent = apac_send_mail(
		$email,
		'Conferma la richiesta di accesso – AP Sicurezza Lavoro',
		// 1.9.14: nessun testo scritto dal richiedente (nome, ragione sociale) in questa email: l'indirizzo
		// non è ancora verificato e il testo libero potrebbe essere usato per spam o phishing a nome dello studio.
		"Buongiorno,\n\nabbiamo ricevuto una richiesta di accesso all'area riservata di AP Sicurezza Lavoro con questo indirizzo email.\n\nPer completarla apri questo link e premi «Conferma la richiesta»:\n\n" . $link . "\n\nIl link vale " . APAC_RICH_LINK_ORE . " ore. Dopo la conferma la richiesta passa allo studio, che la verifica: riceverai un'email con l'esito e, se approvata, il link per scegliere la password.\n\nSe non hai fatto tu la richiesta ignora questo messaggio: senza conferma non viene registrato nulla e i dati si cancellano da soli.\n\nAP Sicurezza Lavoro",
		apac_auto_headers()
	);
	if ( ! $sent ) {
		$wpdb->delete( $t, array( 'id' => $id, 'stato' => 'da_confermare' ) ); // Senza email la richiesta non potrebbe essere confermata.
		return 'errore_mail';
	}
	return 'inviata';
}

/** Conferma dal link dell'email (con un pulsante: i programmi che controllano i link non confermano da soli). */
function apac_rich_confirm( $id, $token ) {
	global $wpdb;
	$r = $id ? apac_rich_get( $id ) : null;
	if ( ! $r || '' === $token || '' === (string) $r->token_hash || ! hash_equals( (string) $r->token_hash, hash( 'sha256', $token ) ) ) {
		return 'link_scaduto';
	}
	if ( 'in_attesa' === $r->stato ) {
		return 'confermata'; // Secondo clic o doppio invio: già confermata.
	}
	if ( 'da_confermare' !== $r->stato || strtotime( (string) $r->inviata_at ) < strtotime( apac_rich_ago( APAC_RICH_LINK_ORE * HOUR_IN_SECONDS ) ) ) {
		return 'link_scaduto';
	}
	// 1.9.14: dalla 1.9.14 la stessa email può avere più richieste da confermare (ognuna con il proprio link).
	// Vale la prima confermata; le altre vengono eliminate, così allo studio non arrivano doppioni.
	// Il lock dell'indirizzo evita che due conferme contemporanee passino entrambe.
	$t    = apac_table( 'richieste' );
	$lock = 'apac_rich_' . md5( (string) $r->email );
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
		return 'errore';
	}
	$gia = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE stato = 'in_attesa' AND email = %s AND id <> %d LIMIT 1", $r->email, $id ) );
	if ( $gia ) {
		$wpdb->delete( $t, array( 'id' => $id, 'stato' => 'da_confermare' ) );
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		return 'confermata'; // Un'altra richiesta con questa email è già all'esame dello studio.
	}
	$n = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET stato = 'in_attesa', confermata_at = %s WHERE id = %d AND stato = 'da_confermare'", apac_now(), $id ) );
	if ( false !== $n && 1 === (int) $n ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE stato = 'da_confermare' AND email = %s AND id <> %d", $r->email, $id ) );
	}
	$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	if ( false === $n ) {
		return 'errore';
	}
	if ( 1 === (int) $n ) {
		apac_rich_notify_studio( apac_rich_get( $id ) );
	}
	return 'confermata';
}

/** Avviso allo studio: email all'indirizzo dell'amministratore e notifica sui dispositivi degli amministratori. */
function apac_rich_notify_studio( $r ) {
	if ( ! $r ) {
		return;
	}
	$tipi  = array( 'nuova' => 'Nuova azienda cliente', 'esistente' => 'Dichiara di essere già cliente', 'collega' => 'Accesso per un collega (richiesto da un utente del cliente)' );
	$ruoli = apac_rich_ruoli();
	$url   = add_query_arg( 'sez', 'richieste', apac_portal_page_url() );
	$body  = 'Azienda: ' . $r->ragione_sociale . ( $r->partita_iva ? ' – P. IVA ' . $r->partita_iva : '' ) . "\n"
		. 'Tipo: ' . ( isset( $tipi[ $r->tipo ] ) ? $tipi[ $r->tipo ] : $r->tipo ) . "\n"
		. 'Referente: ' . trim( $r->nome . ' ' . $r->cognome ) . ' <' . $r->email . '>' . ( $r->telefono ? ' – tel. ' . $r->telefono : '' ) . "\n"
		. ( $r->ruolo && isset( $ruoli[ $r->ruolo ] ) ? 'Ruolo: ' . $ruoli[ $r->ruolo ] . "\n" : '' )
		. ( $r->messaggio ? "\nMessaggio:\n" . $r->messaggio . "\n" : '' )
		. "\nApprova o rifiuta dall'area riservata: " . $url . "\n\nPrima di approvare verifica che il richiedente sia autorizzato dall'azienda.";
	apac_send_mail( get_option( 'admin_email' ), '[Area clienti] Richiesta di accesso: ' . $r->ragione_sociale, $body, apac_auto_headers() );
	if ( function_exists( 'apac_push_user' ) ) {
		foreach ( get_users( array( 'capability' => 'manage_options', 'fields' => 'ID' ) ) as $uid ) {
			apac_push_user( (int) $uid, 'Nuova richiesta di accesso', $r->ragione_sociale . ' – ' . trim( $r->nome . ' ' . $r->cognome ), $url );
		}
	}
}

/* ============================ Pagine del visitatore ============================ */

/**
 * Pagine «Richiedi accesso» e di esito. Restituisce true se ha stampato la pagina
 * (in quel caso il portale non mostra il modulo di accesso).
 */
function apac_rich_render_guest() {
	// phpcs:disable WordPress.Security.NonceVerification -- sola lettura per la scelta della pagina.
	$view = isset( $_GET['accesso'] ) ? sanitize_key( wp_unslash( $_GET['accesso'] ) ) : '';
	if ( isset( $_POST['apac_richiesta'] ) && ! empty( $GLOBALS['apac_rich_err'] ) ) {
		$view = 'richiedi';
	}
	$back = '<p><a class="apac-btn apac-btn--ghost" href="' . esc_url( apac_portal_page_url() ) . '">Torna all’accesso</a></p>';
	$esiti = array(
		'inviata'      => array( 'ok', 'Controlla la tua email', 'Ti abbiamo inviato un messaggio: apri il link che contiene e premi «Conferma la richiesta» entro ' . APAC_RICH_LINK_ORE . ' ore. Se non lo trovi, guarda anche nella posta indesiderata.' ),
		'confermata'   => array( 'ok', 'Richiesta inviata allo studio', 'Grazie. La richiesta è stata confermata e passa ad AP Sicurezza Lavoro per la verifica. Riceverai un’email con l’esito e, se approvata, il link per scegliere la password.' ),
		'link_scaduto' => array( 'error', 'Link non valido o scaduto', 'Il link di conferma non è più valido (scaduto, già usato o copiato in modo incompleto). Puoi inviare di nuovo la richiesta.' ),
		'troppe'       => array( 'error', 'Troppe richieste', 'Sono state inviate troppe richieste in poco tempo. Riprova tra un’ora oppure contatta lo studio.' ),
		'sospeso'      => array( 'error', 'Servizio momentaneamente non disponibile', 'In questo momento non è possibile ricevere nuove richieste online. Contatta direttamente lo studio.' ),
		'errore_mail'  => array( 'error', 'Email non inviata', 'Non è stato possibile inviare l’email all’indirizzo indicato. Controlla l’indirizzo e riprova più tardi, oppure contatta lo studio.' ),
		'errore'       => array( 'error', 'Richiesta non registrata', 'Si è verificato un errore e la richiesta non è stata registrata. Riprova tra qualche minuto.' ),
	);
	if ( isset( $esiti[ $view ] ) ) {
		$e = $esiti[ $view ];
		apac_render_hero( 'Richiesta di accesso', $e[1] );
		echo '<section class="aps-sec aps-alt apac-body"><div class="aps-wrap"><div class="apac-card apac-login"><h2>' . esc_html( $e[1] ) . '</h2>';
		echo '<div class="apac-notice apac-notice--' . esc_attr( $e[0] ) . '" role="' . ( 'ok' === $e[0] ? 'status' : 'alert' ) . '">' . esc_html( $e[2] ) . '</div>';
		if ( in_array( $view, array( 'link_scaduto', 'errore', 'errore_mail' ), true ) ) {
			echo '<p><a class="apac-btn" href="' . esc_url( add_query_arg( 'accesso', 'richiedi', apac_portal_page_url() ) ) . '">Invia una nuova richiesta</a></p>';
		}
		echo $back . '</div></div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput
		return true;
	}
	if ( 'conferma' === $view ) {
		$r = absint( $_GET['r'] ?? 0 );
		$t = preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_GET['t'] ?? '' ) );
		apac_render_hero( 'Richiesta di accesso', 'Ultimo passaggio: conferma la richiesta.' );
		echo '<section class="aps-sec aps-alt apac-body"><div class="aps-wrap"><div class="apac-card apac-login"><h2>Conferma la richiesta</h2>';
		echo '<p>Premi il pulsante per confermare che l’indirizzo email è tuo e inviare la richiesta allo studio.</p>';
		echo '<form method="post" action="' . esc_url( apac_portal_post_url() ) . '"><input type="hidden" name="apac_rich_conferma" value="1"><input type="hidden" name="r" value="' . (int) $r . '"><input type="hidden" name="t" value="' . esc_attr( $t ) . '">';
		echo '<p><button class="apac-btn" type="submit">Conferma la richiesta</button></p></form>' . $back . '</div></div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput
		return true;
	}
	if ( 'richiedi' !== $view ) {
		return false;
	}
	// phpcs:enable
	apac_render_hero( 'Richiedi l’accesso', 'Compila i dati della tua azienda: lo studio verifica la richiesta e ti invia il link per scegliere la password.' );
	echo '<section class="aps-sec aps-alt apac-body"><div class="aps-wrap"><div class="apac-card apac-rich">';
	apac_rich_render_form();
	echo '</div></div></section>';
	return true;
}

/** Valore da riproporre dopo un errore. */
function apac_rich_val( $k ) {
	return isset( $_POST['apac_richiesta'], $_POST[ $k ] ) && ! is_array( $_POST[ $k ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
}

/** Campo del modulo pubblico con l'eventuale errore. */
function apac_rich_field( $label, $name, $type = 'text', $attrs = '', $hint = '' ) {
	$err = isset( $GLOBALS['apac_rich_err'][ $name ] ) ? $GLOBALS['apac_rich_err'][ $name ] : '';
	$id  = 'apac-rq-' . $name;
	$h   = '<label class="apac-field' . ( $err ? ' apac-field--error' : '' ) . '" for="' . esc_attr( $id ) . '"><span>' . esc_html( $label ) . '</span>';
	$h  .= '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( apac_rich_val( $name ) ) . '" ' . $attrs . ( $err ? ' aria-invalid="true"' : '' ) . '>';
	$h  .= ( $hint ? '<small>' . esc_html( $hint ) . '</small>' : '' ) . ( $err ? '<small class="apac-field-msg" role="alert">' . esc_html( $err ) . '</small>' : '' ) . '</label>';
	return $h;
}

function apac_rich_render_form() {
	$err  = isset( $GLOBALS['apac_rich_err'] ) ? (array) $GLOBALS['apac_rich_err'] : array();
	$tipo = 'esistente' === apac_rich_val( 'tipo' ) ? 'esistente' : 'nuova';
	$priv = get_privacy_policy_url() ? get_privacy_policy_url() : home_url( '/privacy-policy/' );
	// phpcs:disable WordPress.Security.EscapeOutput
	if ( $err ) {
		echo '<div class="apac-notice apac-notice--error" role="alert">' . esc_html( isset( $err['_form'] ) ? $err['_form'] : 'Controlla i campi segnalati in rosso: la richiesta non è ancora stata inviata.' ) . '</div>';
	}
	echo '<form method="post" class="apac-form apac-rich-form" action="' . esc_url( apac_portal_post_url() ) . '" novalidate>';
	echo '<input type="hidden" name="apac_richiesta" value="1"><input type="hidden" name="rt" value="' . esc_attr( apac_rich_form_token() ) . '">';
	// Campo trappola: invisibile alle persone, compilato dai programmi automatici.
	echo '<div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden" aria-hidden="true"><label>Sito web<input type="text" name="sito_web" value="" tabindex="-1" autocomplete="off"></label></div>';
	echo '<p class="apac-muted">I campi con * sono obbligatori. Lavoratori, attestati e documenti si caricano dopo il primo accesso.</p>';

	echo '<fieldset class="apac-rich__set"><legend>La tua azienda</legend><div class="apac-rich__tipo" role="radiogroup">';
	echo '<label class="apac-check"><input type="radio" name="tipo" value="nuova"' . checked( 'nuova', $tipo, false ) . '><span>Non siamo ancora clienti dello studio</span></label>';
	echo '<label class="apac-check"><input type="radio" name="tipo" value="esistente"' . checked( 'esistente', $tipo, false ) . '><span>Siamo già clienti: chiedo un accesso per la nostra azienda</span></label></div>';
	echo '<div class="apac-fields">';
	echo apac_rich_field( 'Ragione sociale *', 'ragione_sociale', 'text', 'required maxlength="200" autocomplete="organization"' );
	echo apac_rich_field( 'Partita IVA *', 'partita_iva', 'text', 'required maxlength="16" inputmode="numeric"' );
	echo '</div><div class="apac-fields" data-rich-nuova' . ( 'esistente' === $tipo ? ' hidden' : '' ) . '>';
	echo apac_rich_field( 'Comune della sede *', 'comune', 'text', 'maxlength="100" autocomplete="address-level2"' );
	echo apac_rich_field( 'Provincia', 'provincia', 'text', 'maxlength="2" autocomplete="off"', 'Sigla, es. PA' );
	echo apac_rich_field( 'Indirizzo della sede', 'indirizzo', 'text', 'maxlength="255" autocomplete="street-address"' );
	echo apac_rich_field( 'Codice fiscale dell’azienda', 'codice_fiscale', 'text', 'maxlength="16"' );
	echo apac_rich_field( 'Codice ATECO', 'ateco', 'text', 'maxlength="20"' );
	echo apac_rich_field( 'Datore di lavoro', 'datore_lavoro', 'text', 'maxlength="150"', 'Nome e cognome' );
	echo apac_rich_field( 'Telefono dell’azienda', 'telefono', 'tel', 'maxlength="40"' );
	echo apac_rich_field( 'Email dell’azienda', 'email', 'email', 'maxlength="100"' );
	echo apac_rich_field( 'PEC', 'pec', 'email', 'maxlength="150"' );
	echo '</div></fieldset>';

	echo '<fieldset class="apac-rich__set"><legend>Chi chiede l’accesso</legend><div class="apac-fields">';
	echo apac_rich_field( 'Nome *', 'ref_nome', 'text', 'required maxlength="100" autocomplete="given-name"' );
	echo apac_rich_field( 'Cognome *', 'ref_cognome', 'text', 'required maxlength="100" autocomplete="family-name"' );
	echo apac_rich_field( 'Email *', 'ref_email', 'email', 'required maxlength="100" autocomplete="email"', 'Sarà il tuo nome utente: riceverai qui il link di conferma.' );
	echo apac_rich_field( 'Telefono', 'ref_telefono', 'tel', 'maxlength="40" autocomplete="tel"' );
	$e = isset( $err['ref_ruolo'] ) ? $err['ref_ruolo'] : '';
	echo '<label class="apac-field' . ( $e ? ' apac-field--error' : '' ) . '"><span>Ruolo in azienda *</span><select name="ref_ruolo" required' . ( $e ? ' aria-invalid="true"' : '' ) . '>';
	foreach ( apac_rich_ruoli() as $k => $v ) {
		echo '<option value="' . esc_attr( $k ) . '"' . selected( apac_rich_val( 'ref_ruolo' ), $k, false ) . '>' . esc_html( $v ) . '</option>';
	}
	echo '</select>' . ( $e ? '<small class="apac-field-msg" role="alert">' . esc_html( $e ) . '</small>' : '' ) . '</label>';
	$e = isset( $err['messaggio'] ) ? $err['messaggio'] : '';
	echo '<label class="apac-field apac-field--wide' . ( $e ? ' apac-field--error' : '' ) . '"><span>Messaggio per lo studio (facoltativo)</span><textarea name="messaggio" rows="3" maxlength="1000">' . esc_textarea( apac_rich_val( 'messaggio' ) ) . '</textarea>' . ( $e ? '<small class="apac-field-msg" role="alert">' . esc_html( $e ) . '</small>' : '' ) . '</label>';
	echo '</div></fieldset>';

	foreach ( array(
		'privacy'     => 'Ho letto l’<a href="' . esc_url( $priv ) . '" target="_blank" rel="noopener">informativa privacy</a> sul trattamento dei dati di questa richiesta. *',
		'autorizzato' => 'Dichiaro di essere autorizzato dall’azienda indicata a chiedere l’accesso all’area riservata. *',
	) as $k => $label ) {
		$e = isset( $err[ $k ] ) ? $err[ $k ] : '';
		echo '<label class="apac-field apac-check apac-field--wide' . ( $e ? ' apac-field--error' : '' ) . '"><input type="checkbox" name="' . esc_attr( $k ) . '" value="1"' . checked( '1', apac_rich_val( $k ), false ) . ' required><span>' . $label . '</span>' . ( $e ? '<small class="apac-field-msg" role="alert">' . esc_html( $e ) . '</small>' : '' ) . '</label>';
	}
	echo '<div class="apac-actions"><button class="apac-btn" type="submit">Invia la richiesta</button> <a class="apac-btn apac-btn--ghost" href="' . esc_url( apac_portal_page_url() ) . '">Annulla</a></div></form>';
	echo '<script>(function(){var f=document.querySelector(".apac-rich-form");if(!f)return;var box=f.querySelector("[data-rich-nuova]"),c=f.querySelector("[name=comune]");function u(){var n=f.querySelector("[name=tipo]:checked");var nuova=!n||n.value==="nuova";box.hidden=!nuova;if(c)c.required=nuova;}f.addEventListener("change",function(e){if(e.target.name==="tipo")u();});u();var sent=false;f.addEventListener("submit",function(e){if(sent){e.preventDefault();return;}sent=true;var b=f.querySelector("button[type=submit]");if(b){b.disabled=true;b.textContent="Invio in corso…";}});})();</script>';
	// phpcs:enable
}

/* ========================= Richiesta per un collega (cliente) ========================= */

function apac_rich_render_collega( $az ) {
	if ( apac_is_admin() || apac_user_readonly() ) {
		return;
	}
	global $wpdb;
	$mie = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'richieste' ) . " WHERE tipo = 'collega' AND azienda_id = %d AND stato = 'in_attesa' ORDER BY id DESC LIMIT 20", (int) $az->id ) );
	// phpcs:disable WordPress.Security.EscapeOutput
	echo '<section class="apac-card"><h2>Accesso per un collega</h2><p class="apac-muted">Chiedi allo studio di creare un accesso per un’altra persona della tua azienda. La richiesta viene verificata da AP Sicurezza Lavoro; il collega riceverà un’email per scegliere la password.</p>';
	if ( $mie ) {
		echo '<ul class="apac-list">';
		foreach ( $mie as $m ) {
			echo '<li>' . esc_html( trim( $m->nome . ' ' . $m->cognome ) . ' – ' . $m->email ) . ' <span class="apac-badge apac-badge--muted">In attesa dello studio</span></li>';
		}
		echo '</ul>';
	}
	echo apac_add_box_open( 'richiesta_collega', 'Chiedi un accesso', false, '', 'key' ) . '<div class="apac-subcard">' . apac_form_open( 'richiesta_collega', $az->id ) . '<div class="apac-fields">';
	echo apac_field( 'Nome *', 'nome', '', 'text', 'required maxlength="100"' );
	echo apac_field( 'Cognome *', 'cognome', '', 'text', 'required maxlength="100"' );
	echo apac_field( 'Email *', 'email', '', 'email', 'required maxlength="100"' );
	echo apac_field( 'Ruolo o mansione', 'ruolo_testo', '', 'text', 'maxlength="100"' );
	echo apac_select( 'Cosa deve poter fare', 'accesso', apac_accesso_options(), 'modifica' );
	echo '</div><div class="apac-actions"><button class="apac-btn" type="submit">Invia la richiesta allo studio</button></div></form></div>' . apac_add_box_close() . '</section>';
	// phpcs:enable
}

function apac_op_richiesta_collega( $azienda_id ) {
	global $wpdb;
	if ( apac_is_admin() || apac_user_readonly() ) {
		apac_redirect( 'azienda', 'readonly' );
	}
	$nome    = trim( sanitize_text_field( apac_post( 'nome' ) ) );
	$cognome = trim( sanitize_text_field( apac_post( 'cognome' ) ) );
	$email   = strtolower( trim( (string) apac_post( 'email' ) ) );
	$ruolo   = trim( sanitize_text_field( apac_post( 'ruolo_testo' ) ) );
	foreach ( array( 'nome' => $nome, 'cognome' => $cognome ) as $f => $v ) {
		if ( '' === $v ) {
			apac_error_field( $f );
			apac_redirect( 'azienda', 'need_field' );
		}
	}
	if ( '' === $email ) {
		apac_error_field( 'email' );
		apac_redirect( 'azienda', 'need_field' );
	}
	if ( ! apac_normalize_email( $email ) ) {
		apac_error_field( 'email' );
		apac_redirect( 'azienda', 'bad_email' );
	}
	apac_check_lengths( array( 'nome' => $nome, 'cognome' => $cognome, 'email' => $email ), 'azienda' );
	if ( mb_strlen( $ruolo ) > 100 ) {
		apac_error_field( 'ruolo_testo' );
		apac_redirect( 'azienda', 'too_long' );
	}
	$t = apac_table( 'richieste' );
	if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE stato = 'in_attesa' AND email = %s LIMIT 1", $email ) ) ) {
		apac_redirect( 'azienda', 'richiesta_doppia' );
	}
	if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE stato = 'in_attesa' AND tipo = 'collega' AND azienda_id = %d", $azienda_id ) ) >= APAC_RICH_MAX_COLLEGHI ) {
		apac_redirect( 'azienda', 'richieste_troppe' );
	}
	$az = apac_get_azienda( $azienda_id );
	$me = wp_get_current_user();
	$id = apac_must_insert(
		'richieste',
		array(
			'stato'           => 'in_attesa',
			'tipo'            => 'collega',
			'ragione_sociale' => $az ? $az->ragione_sociale : '',
			'partita_iva'     => $az ? (string) $az->partita_iva : '',
			'dati'            => '',
			'nome'            => $nome,
			'cognome'         => $cognome,
			'email'           => $email,
			'ruolo'           => '',
			'accesso'         => 'lettura' === apac_post( 'accesso' ) ? 'lettura' : 'modifica',
			'messaggio'       => 'Richiesta da ' . $me->display_name . ' (' . $me->user_email . ')' . ( '' !== $ruolo ? '. Ruolo del collega: ' . $ruolo : '' ),
			'azienda_id'      => (int) $azienda_id,
			'richiedente_id'  => get_current_user_id(),
			'created_at'      => apac_now(),
			'inviata_at'      => apac_now(),
			'confermata_at'   => apac_now(),
		),
		'azienda'
	);
	apac_tx_after_commit(
		function () use ( $id ) {
			apac_rich_notify_studio( apac_rich_get( $id ) );
			return '';
		}
	);
	apac_log_detail( trim( $nome . ' ' . $cognome ) . ' – ' . $email );
	apac_redirect( 'azienda', 'richiesta_inviata' );
}

/* ================================ Lato studio ================================ */

function apac_render_richieste() {
	if ( ! apac_is_admin() ) {
		return;
	}
	global $wpdb;
	$t      = apac_table( 'richieste' );
	$attesa = $wpdb->get_results( "SELECT * FROM {$t} WHERE stato = 'in_attesa' ORDER BY id ASC LIMIT 50" );
	$tipi   = array( 'nuova' => 'Nuova azienda', 'esistente' => 'Dichiara di essere già cliente', 'collega' => 'Collega di un cliente' );
	$ruoli  = apac_rich_ruoli();
	// phpcs:disable WordPress.Security.EscapeOutput
	if ( ! $attesa ) {
		echo '<section class="apac-card"><p class="apac-muted">Nessuna richiesta da esaminare. Le nuove richieste arrivano qui dopo che il richiedente ha confermato la propria email; ricevi un avviso via email e sui dispositivi con le notifiche attive.</p>';
		echo '<p class="apac-muted">Link da dare a chi chiede l’accesso: <a href="' . esc_url( add_query_arg( 'accesso', 'richiedi', apac_portal_page_url() ) ) . '">' . esc_html( add_query_arg( 'accesso', 'richiedi', apac_portal_page_url() ) ) . '</a></p></section>';
	}
	foreach ( $attesa as $r ) {
		$dati  = json_decode( (string) $r->dati );
		$match = array();
		if ( '' !== (string) $r->partita_iva ) {
			$match = $wpdb->get_results( $wpdb->prepare( 'SELECT id, ragione_sociale FROM ' . apac_table( 'aziende' ) . ' WHERE partita_iva = %s ORDER BY id LIMIT 5', $r->partita_iva ) );
		}
		$exists = email_exists( $r->email );
		$prec   = $wpdb->get_var( $wpdb->prepare( "SELECT gestita_at FROM {$t} WHERE id <> %d AND stato = 'rifiutata' AND ( email = %s OR ( partita_iva <> '' AND partita_iva = %s ) ) ORDER BY id DESC LIMIT 1", $r->id, $r->email, $r->partita_iva ) );

		echo '<section class="apac-card apac-rich-req" id="richiesta-' . (int) $r->id . '"><h2>' . esc_html( $r->ragione_sociale ) . '</h2>';
		echo '<p><span class="apac-badge">' . esc_html( isset( $tipi[ $r->tipo ] ) ? $tipi[ $r->tipo ] : $r->tipo ) . '</span> <span class="apac-badge apac-badge--ok">Email confermata</span> <small class="apac-muted">Ricevuta il ' . esc_html( mysql2date( 'd/m/Y H:i', $r->confermata_at ? $r->confermata_at : $r->created_at ) ) . '</small></p>';
		echo '<p><strong>' . esc_html( trim( $r->nome . ' ' . $r->cognome ) ) . '</strong> – ' . esc_html( $r->email ) . ( $r->telefono ? ' – tel. ' . esc_html( $r->telefono ) : '' ) . ( $r->ruolo && isset( $ruoli[ $r->ruolo ] ) ? '<br><small>' . esc_html( $ruoli[ $r->ruolo ] ) . '</small>' : '' ) . '</p>';
		if ( $r->messaggio ) {
			echo '<blockquote class="apac-quote">' . nl2br( esc_html( $r->messaggio ) ) . '</blockquote>';
		}
		if ( $exists ) {
			echo '<div class="apac-notice apac-notice--error" role="alert">Esiste già un account con questa email: per approvare cambia l’email nel modulo qui sotto oppure rifiuta la richiesta.</div>';
		}
		if ( $match && 'collega' !== $r->tipo ) {
			$nomi = implode( ', ', array_map( function ( $a ) { return $a->ragione_sociale; }, $match ) );
			echo '<div class="apac-notice apac-notice--warn">Partita IVA già presente: ' . esc_html( $nomi ) . '. Se è la stessa azienda, scegli «Collega a un’azienda esistente» e verifica con il referente che conosci.</div>';
		} elseif ( 'esistente' === $r->tipo ) {
			echo '<div class="apac-notice apac-notice--warn">Dichiara di essere già cliente, ma nessuna azienda ha questa partita IVA: controlla il nome o crea una nuova azienda.</div>';
		}
		if ( $prec ) {
			echo '<div class="apac-notice apac-notice--warn">Una richiesta con la stessa email o partita IVA è stata rifiutata il ' . esc_html( mysql2date( 'd/m/Y', $prec ) ) . '.</div>';
		}
		echo '<p class="apac-muted">Prima di approvare verifica che la persona sia autorizzata dall’azienda (per esempio con una telefonata al datore di lavoro o una visura).</p>';

		// Approvazione.
		echo '<details class="apac-edit" open><summary class="apac-btn apac-btn--small">Approva…</summary>' . apac_form_open( 'richiesta_approva', 0 ) . apac_hidden( 'id', (int) $r->id );
		if ( 'collega' === $r->tipo ) {
			$az = apac_get_azienda( (int) $r->azienda_id );
			echo '<p>L’accesso sarà collegato a <strong>' . esc_html( $az ? $az->ragione_sociale : 'azienda non più presente' ) . '</strong>.</p>';
		} else {
			// 1.9.14: nessuna scelta preimpostata. Prima, con la partita IVA già presente, era selezionato
			// «Collega a un'azienda esistente»: un clic su «Approva» dava a chiunque conoscesse la partita IVA
			// (dato pubblico) l'accesso ai dati dei lavoratori di un cliente. Ora la scelta è sempre esplicita.
			$dest = (string) apac_form_value( 'destinazione', '' );
			echo '<fieldset class="apac-rich__tipo"><legend>Cosa vuoi fare? *</legend><label class="apac-check"><input type="radio" name="destinazione" value="nuova" required' . checked( 'nuova', $dest, false ) . '><span>Crea una nuova azienda con questi dati</span></label>';
			echo '<label class="apac-check"><input type="radio" name="destinazione" value="esistente" required' . checked( 'esistente', $dest, false ) . '><span>Collega a un’azienda esistente</span></label></fieldset>';
			echo '<div data-dest="esistente">' . apac_azienda_picker( 'Azienda esistente', 'azienda_esistente', $match ? (int) $match[0]->id : 0 );
			echo '<label class="apac-field apac-check"><input type="checkbox" name="verificato" value="1" required' . checked( apac_form_checked( 'verificato', false ), true, false ) . '><span><strong>Ho verificato direttamente con l’azienda</strong> (datore di lavoro o legale rappresentante, con un recapito che già conosco) che questa persona è autorizzata a vedere i dati dell’azienda e dei suoi lavoratori. *</span></label>';
			echo '<p class="apac-muted">La conferma dell’email dimostra solo che il richiedente usa quella casella, non che lavora per l’azienda. La partita IVA è un dato pubblico. Se hai dubbi scegli «Solo consultazione» qui sotto oppure rifiuta.</p></div>';
			echo '<div data-dest="nuova"><h3>Dati della nuova azienda (puoi correggerli)</h3>';
			apac_render_azienda_fields( $dati ? $dati : null, true );
			echo '<h3>Cosa potrà modificare il cliente</h3><div class="apac-perms">';
			foreach ( apac_permessi_defaults() as $k => $def ) {
				echo '<label class="apac-field apac-check"><input type="checkbox" name="perm_' . esc_attr( $k ) . '" value="1"' . checked( apac_form_checked( 'perm_' . $k, $def[0] ), true, false ) . '><span>' . esc_html( $def[1] ) . '</span></label>';
			}
			echo '</div></div>';
		}
		echo '<h3>Accesso del referente</h3><div class="apac-fields">';
		echo apac_field( 'Nome *', 'ref_nome', $r->nome, 'text', 'required maxlength="100"' );
		echo apac_field( 'Cognome', 'ref_cognome', $r->cognome, 'text', 'maxlength="100"' );
		echo apac_field( 'Email (anche per l’accesso) *', 'ref_email', $r->email, 'email', 'required maxlength="100"' );
		echo apac_select( 'Cosa può fare', 'accesso', apac_accesso_options(), $r->accesso ? $r->accesso : 'modifica' );
		echo '</div><p class="apac-muted">Approvando, il referente riceve l’email con il link per scegliere la password; al primo accesso firma l’informativa e l’accordo art. 28, poi può caricare lavoratori, attestati e documenti.</p>';
		echo '<div class="apac-actions"><button class="apac-btn" type="submit">Approva e crea l’accesso</button></div></form></details>';

		// Rifiuto ed eliminazione.
		echo '<div class="apac-row-actions"><details class="apac-edit"' . apac_details_open( 'richiesta_rifiuta', 'id:' . (int) $r->id ) . '><summary class="apac-btn apac-btn--small apac-btn--ghost">Rifiuta…</summary>' . apac_form_open( 'richiesta_rifiuta', 0 ) . apac_hidden( 'id', (int) $r->id );
		echo apac_textarea( 'Motivo (facoltativo, viene scritto nell’email al richiedente)', 'motivo', '' );
		echo '<p class="apac-muted">Per ' . APAC_RICH_BLOCCO_GG . ' giorni la stessa email o partita IVA non potrà inviare nuove richieste online.</p>';
		echo '<div class="apac-actions"><button class="apac-btn apac-btn--small apac-btn--danger" type="submit">Rifiuta e avvisa il richiedente</button></div></form></details>';
		echo apac_button_form( 'richiesta_elimina', 0, array( 'id' => (int) $r->id ), 'Elimina senza avvisare (spam)', 'apac-btn apac-btn--small apac-btn--ghost', 'Eliminare la richiesta senza inviare email?' );
		echo '</div></section>';
	}
	if ( $attesa ) {
		echo '<script>(function(){document.querySelectorAll(".apac-rich-req form").forEach(function(f){var r=f.querySelectorAll("[name=destinazione]");if(!r.length)return;function u(){var v=(f.querySelector("[name=destinazione]:checked")||{}).value||"";f.querySelectorAll("[data-dest]").forEach(function(d){var on=d.getAttribute("data-dest")===v;d.hidden=!on;d.querySelectorAll("input,select,textarea").forEach(function(i){i.disabled=!on;});});}f.addEventListener("change",function(e){if(e.target.name==="destinazione")u();});u();});})();</script>';
	}

	// Richieste non ancora confermate e storico.
	$nc = $wpdb->get_results( "SELECT * FROM {$t} WHERE stato = 'da_confermare' ORDER BY id DESC LIMIT 50" );
	$st = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE stato IN ('approvata','rifiutata') AND gestita_at >= %s ORDER BY gestita_at DESC LIMIT 100", apac_rich_ago( APAC_RICH_CONSERVA_GG * DAY_IN_SECONDS ) ) );
	echo '<details class="apac-card apac-fold"><summary><span>In attesa di conferma email</span><small>' . esc_html( $nc ? apac_n( count( $nc ), 'richiesta', 'richieste' ) : 'nessuna' ) . '</small></summary><div class="apac-fold__body">';
	echo '<p class="apac-muted">Il richiedente non ha ancora aperto il link ricevuto. Dopo ' . APAC_RICH_LINK_ORE . ' ore il link scade; dopo ' . APAC_RICH_NONCONF_GG . ' giorni la richiesta si cancella da sola.</p>';
	foreach ( $nc as $r ) {
		echo '<p>' . esc_html( $r->ragione_sociale . ' – ' . trim( $r->nome . ' ' . $r->cognome ) . ' – ' . $r->email . ' – ' . mysql2date( 'd/m/Y H:i', $r->inviata_at ) ) . ' ' . apac_button_form( 'richiesta_elimina', 0, array( 'id' => (int) $r->id ), 'Elimina', 'apac-btn apac-btn--small apac-btn--ghost', 'Eliminare la richiesta?' ) . '</p>';
	}
	echo '</div></details>';
	echo '<details class="apac-card apac-fold"><summary><span>Richieste gestite</span><small>ultimi ' . APAC_RICH_CONSERVA_GG . ' giorni · ' . esc_html( $st ? (string) count( $st ) : 'nessuna' ) . '</small></summary><div class="apac-fold__body">';
	if ( $st ) {
		echo '<div class="apac-table-wrap"><table class="apac-table"><thead><tr><th>Data</th><th>Azienda</th><th>Referente</th><th>Esito</th></tr></thead><tbody>';
		foreach ( $st as $r ) {
			echo '<tr><td data-label="Data">' . esc_html( mysql2date( 'd/m/Y', $r->gestita_at ) ) . '</td><td data-label="Azienda">' . esc_html( $r->ragione_sociale ) . '</td><td data-label="Referente">' . esc_html( trim( $r->nome . ' ' . $r->cognome ) . ' – ' . $r->email ) . '</td><td data-label="Esito">' . ( 'approvata' === $r->stato ? '<span class="apac-badge apac-badge--ok">Approvata</span>' : '<span class="apac-badge apac-badge--danger">Rifiutata</span>' . ( $r->motivo ? '<br><small>' . esc_html( $r->motivo ) . '</small>' : '' ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
	echo '<p class="apac-muted">Dopo ' . APAC_RICH_CONSERVA_GG . ' giorni le richieste gestite vengono cancellate automaticamente.</p></div></details>';
	// phpcs:enable
}

/** Prende in carico la richiesta: solo una delle operazioni concorrenti ci riesce (riga bloccata fino alla conferma). */
function apac_rich_claim( $id, $stato ) {
	global $wpdb;
	$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . apac_table( 'richieste' ) . " SET stato = %s, gestita_at = %s, gestita_da = %d, token_hash = '' WHERE id = %d AND stato = 'in_attesa'", $stato, apac_now(), get_current_user_id(), $id ) );
	if ( false === $n ) {
		apac_redirect( 'richieste', 'db_error' );
	}
	if ( 1 !== (int) $n ) {
		apac_redirect( 'richieste', 'richiesta_gestita' );
	}
}

function apac_op_richiesta_approva( $unused ) {
	global $wpdb;
	$id = absint( apac_post( 'id' ) );
	$r  = apac_rich_get( $id );
	if ( ! $r || 'in_attesa' !== $r->stato ) {
		apac_redirect( 'richieste', 'richiesta_gestita' );
	}
	// Referente.
	$nome    = trim( sanitize_text_field( apac_post( 'ref_nome' ) ) );
	$cognome = trim( sanitize_text_field( apac_post( 'ref_cognome' ) ) );
	$email   = strtolower( trim( (string) apac_post( 'ref_email' ) ) );
	if ( '' === $nome ) {
		apac_error_field( 'ref_nome' );
		apac_redirect( 'richieste', 'need_field' );
	}
	if ( '' === $email || ! apac_normalize_email( $email ) ) {
		apac_error_field( 'ref_email' );
		apac_redirect( 'richieste', '' === $email ? 'need_field' : 'bad_email' );
	}
	foreach ( array( 'ref_nome' => $nome, 'ref_cognome' => $cognome, 'ref_email' => $email ) as $f => $v ) {
		if ( mb_strlen( $v ) > 100 ) {
			apac_error_field( $f );
			apac_redirect( 'richieste', 'too_long' );
		}
	}
	if ( email_exists( $email ) || username_exists( $email ) ) {
		apac_error_field( 'ref_email' );
		apac_redirect( 'richieste', 'user_exists' );
	}
	// 1.9.14: destinazione sempre scelta esplicitamente; per collegare a un'azienda esistente serve la
	// conferma di aver verificato l'autorizzazione del richiedente (il server la ricontrolla).
	$dest = (string) apac_post( 'destinazione' );
	if ( 'collega' !== $r->tipo ) {
		if ( ! in_array( $dest, array( 'nuova', 'esistente' ), true ) ) {
			apac_error_field( 'destinazione' );
			apac_redirect( 'richieste', 'need_dest' );
		}
		if ( 'esistente' === $dest && '1' !== (string) apac_post( 'verificato' ) ) {
			apac_error_field( 'verificato' );
			apac_redirect( 'richieste', 'need_verifica' );
		}
	}

	apac_rich_claim( $id, 'approvata' ); // Prima di tutto: un secondo clic o un secondo amministratore si fermano qui.

	// Azienda di destinazione.
	if ( 'collega' === $r->tipo || 'esistente' === $dest ) {
		$az_id = 'collega' === $r->tipo ? (int) $r->azienda_id : absint( apac_post( 'azienda_esistente' ) );
		if ( ! $az_id && 'collega' !== $r->tipo ) {
			$az_id = absint( apac_post( 'azienda_esistente_id' ) );
		}
		if ( ! $az_id || ! apac_get_azienda( $az_id ) ) {
			apac_redirect( 'richieste', 'no_azienda' );
		}
	} else {
		$data = apac_azienda_fields_from_post( 'richieste' );
		$perm = array();
		foreach ( apac_permessi_defaults() as $k => $def ) {
			$perm[ $k ] = (bool) apac_post( 'perm_' . $k );
		}
		$data['permessi']   = wp_json_encode( $perm );
		$data['created_at'] = apac_now();
		$data['updated_at'] = apac_now();
		$az_id              = apac_must_insert( 'aziende', $data, 'richieste' );
	}
	if ( false === $wpdb->update( apac_table( 'richieste' ), array( 'azienda_id' => $az_id ), array( 'id' => $id ) ) ) {
		apac_redirect( 'richieste', 'db_error' );
	}

	// Creazione dell'accesso con la procedura esistente (email della password dopo la conferma).
	add_action(
		'user_register',
		function ( $user_id ) use ( $id ) {
			global $wpdb;
			$wpdb->update( apac_table( 'richieste' ), array( 'user_id' => (int) $user_id ), array( 'id' => $id ) );
		}
	);
	if ( 'collega' === $r->tipo && $r->richiedente_id ) {
		$who = trim( $nome . ' ' . $cognome );
		apac_push_after_commit(
			function () use ( $r, $who ) {
				apac_push_user( (int) $r->richiedente_id, 'Accesso creato', 'Lo studio ha creato l’accesso per ' . $who . '.', apac_portal_page_url() );
			}
		);
	}
	$_POST['nome']                         = wp_slash( $nome );
	$_POST['cognome']                      = wp_slash( $cognome );
	$_POST['email']                        = wp_slash( $email );
	$_REQUEST['azienda']                   = $az_id;
	$GLOBALS['apac_log_ctx']['azienda_id'] = $az_id;
	apac_op_utente_create( $az_id ); // Termina con il reindirizzamento (conferma della transazione).
}

function apac_op_richiesta_rifiuta( $unused ) {
	$id = absint( apac_post( 'id' ) );
	$r  = apac_rich_get( $id );
	if ( ! $r || 'in_attesa' !== $r->stato ) {
		apac_redirect( 'richieste', 'richiesta_gestita' );
	}
	$motivo = trim( sanitize_textarea_field( apac_post( 'motivo' ) ) );
	if ( mb_strlen( $motivo ) > 1000 ) {
		apac_error_field( 'motivo' );
		apac_redirect( 'richieste', 'too_long' );
	}
	apac_rich_claim( $id, 'rifiutata' );
	global $wpdb;
	if ( false === $wpdb->update( apac_table( 'richieste' ), array( 'motivo' => $motivo ), array( 'id' => $id ) ) ) {
		apac_redirect( 'richieste', 'db_error' );
	}
	apac_tx_after_commit(
		function () use ( $r, $motivo ) {
			$m = '' !== $motivo ? "\n\nMotivo: " . $motivo : '';
			if ( 'collega' === $r->tipo ) {
				$u = $r->richiedente_id ? get_userdata( (int) $r->richiedente_id ) : null;
				if ( function_exists( 'apac_push_user' ) && $u ) {
					apac_push_user( (int) $u->ID, 'Richiesta di accesso non accolta', trim( $r->nome . ' ' . $r->cognome ), apac_portal_page_url() );
				}
				$ok = $u ? apac_send_mail( $u->user_email, 'Area riservata – richiesta di accesso per un collega', 'Buongiorno ' . $u->display_name . ",\n\nla richiesta di accesso per " . trim( $r->nome . ' ' . $r->cognome ) . ' (' . $r->email . ') non è stata accolta.' . $m . "\n\nPer chiarimenti contatta lo studio.\n\nAP Sicurezza Lavoro", apac_auto_headers() ) : true;
			} else {
				$ok = apac_send_mail( $r->email, 'Area riservata AP Sicurezza Lavoro – richiesta di accesso', 'Buongiorno ' . $r->nome . ",\n\nla richiesta di accesso all'area riservata per «" . $r->ragione_sociale . '» non è stata accolta.' . $m . "\n\nPer chiarimenti contatta direttamente lo studio: " . home_url( '/' ) . "\n\nAP Sicurezza Lavoro", apac_auto_headers() );
			}
			return $ok ? '' : 'richiesta_rifiutata_nomail';
		}
	);
	apac_log_detail( $r->ragione_sociale . ' – ' . $r->email );
	apac_redirect( 'richieste', 'richiesta_rifiutata' );
}

function apac_op_richiesta_elimina( $unused ) {
	global $wpdb;
	$id = absint( apac_post( 'id' ) );
	$r  = apac_rich_get( $id );
	if ( ! $r ) {
		apac_redirect( 'richieste', 'richiesta_gestita' );
	}
	apac_must( $wpdb->delete( apac_table( 'richieste' ), array( 'id' => $id ) ), 'richieste' );
	apac_log_detail( $r->ragione_sociale . ' – ' . $r->email );
	apac_redirect( 'richieste', 'deleted' );
}

/* ============================ Pulizia giornaliera ============================ */

add_action( 'apac_cron_scadenze', 'apac_rich_pulizia', 30 );
function apac_rich_pulizia() {
	global $wpdb;
	$t = apac_table( 'richieste' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE stato = 'da_confermare' AND inviata_at < %s", apac_rich_ago( APAC_RICH_NONCONF_GG * DAY_IN_SECONDS ) ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE stato IN ('approvata','rifiutata') AND gestita_at < %s", apac_rich_ago( APAC_RICH_CONSERVA_GG * DAY_IN_SECONDS ) ) );
}
