<?php
defined( 'ABSPATH' ) || exit;

/*
 * 1.7.0 – Sessione 3 (avvisi e documenti):
 * notifiche sul telefono, situazione sicurezza stampabile, formazione richiesta per mansione,
 * presa visione firmata, richiesta di corsi, indicatore di completezza dei dati.
 */

/* ============================================================================
 * 1. NOTIFICHE SUL TELEFONO (Web Push: RFC 8030, 8291, 8292)
 * ========================================================================= */

function apac_b64u( $bin ) {
	return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
}
function apac_b64u_dec( $s ) {
	$s = strtr( (string) $s, '-_', '+/' );
	$d = base64_decode( $s . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true );
	return false === $d ? '' : $d;
}

/** Il server può cifrare e firmare le notifiche? */
function apac_push_supported() {
	return function_exists( 'openssl_pkey_derive' ) && function_exists( 'hash_hkdf' ) && function_exists( 'openssl_get_curve_names' )
		&& in_array( 'prime256v1', (array) openssl_get_curve_names(), true ) && in_array( 'aes-128-gcm', openssl_get_cipher_methods(), true );
}

/** Chiave pubblica P-256 in formato DER (SubjectPublicKeyInfo) da punto non compresso di 65 byte. */
function apac_p256_pem( $raw ) {
	$der = hex2bin( '3059301306072a8648ce3d020106082a8648ce3d030107034200' ) . $raw;
	return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
}

/** Punto pubblico non compresso (0x04 || X || Y) di una chiave EC. */
function apac_ec_raw_public( $key ) {
	$d = openssl_pkey_get_details( $key );
	if ( ! $d || empty( $d['ec']['x'] ) ) {
		return '';
	}
	return "\x04" . str_pad( $d['ec']['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $d['ec']['y'], 32, "\0", STR_PAD_LEFT );
}

function apac_ec_new_key() {
	return openssl_pkey_new(
		array(
			'curve_name'       => 'prime256v1',
			'private_key_type' => OPENSSL_KEYTYPE_EC,
		)
	);
}

/** Chiavi VAPID del sito (create alla prima richiesta, conservate nelle opzioni). */
function apac_push_keys() {
	$k = get_option( 'apac_vapid' );
	if ( is_array( $k ) && ! empty( $k['pub'] ) && ! empty( $k['pem'] ) ) {
		return $k;
	}
	if ( ! apac_push_supported() ) {
		return null;
	}
	$key = apac_ec_new_key();
	if ( ! $key || ! openssl_pkey_export( $key, $pem ) ) {
		return null;
	}
	$k = array(
		'pub' => apac_b64u( apac_ec_raw_public( $key ) ),
		'pem' => $pem,
	);
	add_option( 'apac_vapid', $k, '', false ) || update_option( 'apac_vapid', $k, false );
	return get_option( 'apac_vapid' );
}

/** Servizi di notifica ammessi (i browser usano solo questi): evita richieste verso indirizzi arbitrari. */
function apac_push_host_allowed( $endpoint ) {
	$p = wp_parse_url( $endpoint );
	if ( empty( $p['scheme'] ) || 'https' !== $p['scheme'] || empty( $p['host'] ) || ! empty( $p['port'] ) && 443 !== (int) $p['port'] ) {
		return false;
	}
	$host  = strtolower( $p['host'] );
	$hosts = apply_filters( 'apac_push_hosts', array( 'fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com', '.push.apple.com', '.notify.windows.com' ) );
	foreach ( $hosts as $h ) {
		if ( $host === $h || ( '.' === $h[0] && substr( $host, -strlen( $h ) ) === $h ) ) {
			return true;
		}
	}
	return false;
}

/** JWT VAPID firmato ES256 (RFC 8292). */
function apac_vapid_jwt( $endpoint, $keys ) {
	$p    = wp_parse_url( $endpoint );
	$aud  = $p['scheme'] . '://' . $p['host'];
	$mail = get_option( 'admin_email' );
	$head = apac_b64u( wp_json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) );
	$body = apac_b64u( wp_json_encode( array( 'aud' => $aud, 'exp' => time() + 12 * HOUR_IN_SECONDS, 'sub' => 'mailto:' . ( is_email( $mail ) ? $mail : 'info@' . wp_parse_url( home_url(), PHP_URL_HOST ) ) ) ) );
	$priv = openssl_pkey_get_private( $keys['pem'] );
	if ( ! $priv || ! openssl_sign( $head . '.' . $body, $der, $priv, OPENSSL_ALGO_SHA256 ) ) {
		return '';
	}
	// Firma DER (SEQUENCE di due INTEGER) -> R || S di 32 byte ciascuno.
	$pos = 2;
	if ( 0x81 === ord( $der[1] ) ) {
		$pos = 3;
	}
	$sig = '';
	for ( $i = 0; $i < 2; $i++ ) {
		$len  = ord( $der[ $pos + 1 ] );
		$int  = substr( $der, $pos + 2, $len );
		$int  = ltrim( $int, "\0" );
		$sig .= str_pad( $int, 32, "\0", STR_PAD_LEFT );
		$pos += 2 + $len;
	}
	return $head . '.' . $body . '.' . apac_b64u( $sig );
}

/**
 * Cifratura del contenuto "aes128gcm" (RFC 8291 + RFC 8188).
 * @return string|false corpo della richiesta
 */
function apac_push_encrypt( $payload, $ua_public, $auth_secret, $salt = null, $as_key = null ) {
	if ( 65 !== strlen( $ua_public ) || "\x04" !== $ua_public[0] || 16 !== strlen( $auth_secret ) ) {
		return false;
	}
	$as_key = $as_key ? $as_key : apac_ec_new_key();
	$ua_key = openssl_pkey_get_public( apac_p256_pem( $ua_public ) );
	if ( ! $as_key || ! $ua_key ) {
		return false;
	}
	$as_public = apac_ec_raw_public( $as_key );
	$ecdh      = openssl_pkey_derive( $ua_key, $as_key, 32 );
	if ( false === $ecdh || 32 !== strlen( $ecdh ) ) {
		return false;
	}
	$ikm   = hash_hkdf( 'sha256', $ecdh, 32, "WebPush: info\0" . $ua_public . $as_public, $auth_secret );
	$salt  = $salt ? $salt : random_bytes( 16 );
	$cek   = hash_hkdf( 'sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt );
	$nonce = hash_hkdf( 'sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt );
	$tag   = '';
	$ct    = openssl_encrypt( $payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16 );
	if ( false === $ct ) {
		return false;
	}
	return $salt . pack( 'N', 4096 ) . chr( 65 ) . $as_public . $ct . $tag;
}

/** Invia una notifica a un'iscrizione. @return int codice HTTP (0 = errore locale) */
function apac_push_send_one( $sub, $data ) {
	$GLOBALS['apac_push_last_error'] = '';
	$keys = apac_push_keys();
	if ( ! $keys || ! apac_push_host_allowed( $sub->endpoint ) ) {
		$GLOBALS['apac_push_last_error'] = $keys ? 'servizio notifiche non riconosciuto (' . (string) wp_parse_url( $sub->endpoint, PHP_URL_HOST ) . ')' : 'chiavi del sito non disponibili';
		return 0;
	}
	$body = apac_push_encrypt( wp_json_encode( $data ), apac_b64u_dec( $sub->p256dh ), apac_b64u_dec( $sub->auth ) );
	$jwt  = apac_vapid_jwt( $sub->endpoint, $keys );
	if ( false === $body || '' === $jwt ) {
		$GLOBALS['apac_push_last_error'] = 'cifratura non riuscita sul server';
		return 0;
	}
	$r = wp_remote_post(
		$sub->endpoint,
		array(
			'timeout' => 6,
			'headers' => array(
				'Authorization'    => 'vapid t=' . $jwt . ', k=' . $keys['pub'],
				'Content-Encoding' => 'aes128gcm',
				'Content-Type'     => 'application/octet-stream',
				'TTL'              => (string) DAY_IN_SECONDS,
				'Urgency'          => 'normal',
			),
			'body'    => $body,
		)
	);
	if ( is_wp_error( $r ) ) {
		$GLOBALS['apac_push_last_error'] = $r->get_error_message();
		return 0;
	}
	$code = (int) wp_remote_retrieve_response_code( $r );
	if ( $code < 200 || $code >= 300 ) {
		$GLOBALS['apac_push_last_error'] = sanitize_text_field( mb_substr( (string) wp_remote_retrieve_body( $r ), 0, 300 ) );
	}
	if ( 404 === $code || 410 === $code ) {
		global $wpdb;
		$wpdb->delete( apac_table( 'push' ), array( 'id' => (int) $sub->id ) ); // Iscrizione scaduta o revocata.
	}
	return $code;
}

/** Notifica a un utente (tutti i suoi dispositivi). @return int notifiche accettate */
function apac_push_user( $user_id, $title, $body, $url = '' ) {
	global $wpdb;
	if ( ! apac_push_supported() ) {
		return 0;
	}
	$subs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'push' ) . ' WHERE user_id = %d', $user_id ) );
	$ok   = 0;
	foreach ( $subs as $s ) {
		$code = apac_push_send_one(
			$s,
			array(
				'title' => mb_substr( $title, 0, 80 ),
				'body'  => mb_substr( $body, 0, 180 ),
				'url'   => $url ? $url : apac_portal_page_url(),
			)
		);
		if ( $code >= 200 && $code < 300 ) {
			$ok++;
		}
	}
	return $ok;
}

/** Notifica agli utenti attivi di un'azienda. */
function apac_push_azienda( $azienda_id, $title, $body, $url = '' ) {
	$n = 0;
	foreach ( get_users( array( 'meta_key' => 'apac_azienda_id', 'meta_value' => (string) (int) $azienda_id, 'fields' => 'ID' ) ) as $uid ) {
		if ( ! apac_user_revoked( $uid ) ) {
			$n += apac_push_user( (int) $uid, $title, $body, $url );
		}
	}
	return $n;
}

/** Notifica agli amministratori che hanno attivato le notifiche. */
function apac_push_admins( $title, $body, $url = '' ) {
	global $wpdb;
	$n = 0;
	foreach ( array_unique( array_map( 'intval', (array) $wpdb->get_col( 'SELECT DISTINCT user_id FROM ' . apac_table( 'push' ) ) ) ) as $uid ) {
		if ( user_can( $uid, 'manage_options' ) ) {
			$n += apac_push_user( $uid, $title, $body, $url );
		}
	}
	return $n;
}

/** Invio dopo il salvataggio (non blocca e non cambia l'esito dell'operazione). */
function apac_push_after_commit( $fn ) {
	apac_tx_after_commit(
		function () use ( $fn ) {
			try {
				$fn();
			} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			}
			return '';
		}
	);
}

/* Iscrizione / cancellazione / prova (richieste dal JavaScript della pagina). */
add_action( 'init', 'apac_push_endpoint', 19 );
function apac_push_endpoint() {
	global $wpdb;
	// phpcs:disable WordPress.Security.NonceVerification
	if ( empty( $_POST['apac_push'] ) ) {
		return;
	}
	nocache_headers();
	$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
	if ( ! is_user_logged_in() || ! apac_user_can_use_portal() || ! wp_verify_nonce( $nonce, 'apac_push' ) ) {
		wp_send_json_error( array( 'msg' => 'Sessione scaduta: ricarica la pagina.' ), 403 );
	}
	$uid = get_current_user_id();
	$act = sanitize_key( wp_unslash( $_POST['apac_push'] ) );
	if ( ! apac_push_supported() || ! apac_push_keys() ) {
		wp_send_json_error( array( 'msg' => 'Il server non supporta le notifiche.' ), 500 );
	}
	$endpoint = isset( $_POST['endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint'] ), array( 'https' ) ) : '';
	if ( 'subscribe' === $act ) {
		$p256 = isset( $_POST['p256dh'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', wp_unslash( $_POST['p256dh'] ) ) : '';
		$auth = isset( $_POST['auth'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', wp_unslash( $_POST['auth'] ) ) : '';
		$pk   = apac_b64u_dec( $p256 );
		if ( ! apac_push_host_allowed( $endpoint ) || strlen( $endpoint ) > 1000 || 65 !== strlen( $pk ) || "\x04" !== $pk[0] || 16 !== strlen( apac_b64u_dec( $auth ) ) || ! openssl_pkey_get_public( apac_p256_pem( $pk ) ) ) {
			wp_send_json_error( array( 'msg' => 'Dati del dispositivo non validi.' ), 400 );
		}
		$hash = hash( 'sha256', $endpoint );
		$wpdb->delete( apac_table( 'push' ), array( 'endpoint_hash' => $hash ) );
		// Al massimo 10 dispositivi per persona: si eliminano i più vecchi.
		$old = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . apac_table( 'push' ) . ' WHERE user_id = %d ORDER BY id DESC LIMIT 100 OFFSET 9', $uid ) );
		foreach ( $old as $o ) {
			$wpdb->delete( apac_table( 'push' ), array( 'id' => (int) $o ) );
		}
		$ok = $wpdb->insert(
			apac_table( 'push' ),
			array(
				'user_id'       => $uid,
				'endpoint'      => $endpoint,
				'endpoint_hash' => $hash,
				'p256dh'        => $p256,
				'auth'          => $auth,
				'user_agent'    => isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 250 ) : '',
				'created_at'    => apac_now(),
			)
		);
		$ok ? wp_send_json_success( array( 'msg' => 'Notifiche attivate su questo dispositivo.' ) ) : wp_send_json_error( array( 'msg' => 'Salvataggio non riuscito.' ), 500 );
	}
	if ( 'unsubscribe' === $act ) {
		$wpdb->delete( apac_table( 'push' ), array( 'endpoint_hash' => hash( 'sha256', $endpoint ), 'user_id' => $uid ) );
		wp_send_json_success( array( 'msg' => 'Notifiche disattivate su questo dispositivo.' ) );
	}
	if ( 'test' === $act ) {
		$sub = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'push' ) . ' WHERE endpoint_hash = %s AND user_id = %d', hash( 'sha256', $endpoint ), $uid ) );
		$code = $sub ? apac_push_send_one( $sub, array( 'title' => 'AP Sicurezza Lavoro', 'body' => 'Le notifiche funzionano su questo dispositivo.', 'url' => apac_portal_page_url() ) ) : 0;
		if ( $code >= 200 && $code < 300 ) {
			wp_send_json_success( array( 'msg' => 'Notifica di prova inviata. Se non compare, controlla che le notifiche del browser siano consentite anche nelle impostazioni del computer (Windows: Impostazioni › Sistema › Notifiche; Mac: Impostazioni di Sistema › Notifiche) e che «Non disturbare» sia spento.' ) );
		}
		// 1.9.13: messaggio comprensibile e dettaglio tecnico per l'assistenza.
		$det = ! $sub ? 'dispositivo non registrato sul server' : (string) ( $GLOBALS['apac_push_last_error'] ?? '' );
		$map = array(
			0   => 'il server del sito non è riuscito a contattare il servizio notifiche del browser',
			400 => 'richiesta rifiutata dal servizio notifiche',
			401 => 'chiave del sito non accettata dal servizio notifiche',
			403 => 'iscrizione creata con una chiave diversa: disattiva e riattiva le notifiche',
			404 => 'iscrizione scaduta: riattiva le notifiche',
			410 => 'iscrizione revocata dal browser: riattiva le notifiche',
			413 => 'messaggio troppo grande',
			429 => 'troppe notifiche in poco tempo: riprova tra qualche minuto',
		);
		$txt = isset( $map[ $code ] ) ? $map[ $code ] : 'errore del servizio notifiche';
		wp_send_json_error( array( 'msg' => 'Invio non riuscito (' . (int) $code . '): ' . $txt . ( '' !== $det ? ' – ' . mb_substr( $det, 0, 160 ) : '' ) . '.', 'code' => (int) $code ), 502 );
	}
	wp_send_json_error( array( 'msg' => 'Richiesta non valida.' ), 400 );
	// phpcs:enable
}

/** Riquadro "Notifiche" (nel menu Impostazioni). Lo stato lo mostra il JavaScript. */
function apac_render_push_box( $id ) {
	if ( ! apac_push_supported() ) {
		return '';
	}
	return '<div class="apac-push" id="' . esc_attr( $id ) . '" data-apac-push><p class="apac-push__title">' . apac_icon_extra( 'bell' ) . '<strong>Notifiche sul telefono</strong></p>'
		. '<p class="apac-push__state" data-push-state>Controllo in corso…</p>'
		. '<div class="apac-push__btns"><button type="button" class="apac-btn apac-btn--small" data-push-on hidden>Attiva le notifiche</button>'
		. '<button type="button" class="apac-btn apac-btn--small apac-btn--ghost" data-push-test hidden>Invia una prova</button>'
		. '<button type="button" class="apac-btn apac-btn--small apac-btn--ghost" data-push-off hidden>Disattiva</button></div></div>';
}

/** Invito in Home (compare solo se le notifiche si possono attivare e non sono ancora attive). */
function apac_render_push_invite() {
	if ( ! apac_push_supported() ) {
		return;
	}
	echo '<section class="apac-pushinvite" hidden data-apac-push-invite><span class="apac-install__ico">' . apac_icon_extra( 'bell' ) . '</span><div><strong>Ricevi gli avvisi sul telefono</strong><small>Scadenze, nuovi documenti e risposte dello studio.</small></div><button type="button" class="apac-btn apac-btn--small" data-push-on>Attiva</button><button type="button" class="apac-install__x" data-push-invite-hide aria-label="Non ora">×</button></section>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

/* Notifiche automatiche dagli avvisi giornalieri: una per azienda, solo per voci nuove. */
add_action( 'apac_avvisi_azienda', 'apac_push_avvisi_azienda', 10, 2 );
function apac_push_avvisi_azienda( $az, $voci ) {
	if ( ! apac_push_supported() || ! $voci ) {
		return;
	}
	$sent = get_option( 'apac_push_sent', array() );
	$sent = is_array( $sent ) ? $sent : array();
	$new  = array();
	foreach ( $voci as $v ) {
		$k = $v[0] . ':' . $v[1] . ':' . $v[2] . ':' . $v[3];
		if ( ! isset( $sent[ $k ] ) ) {
			$new[]      = $v;
			$sent[ $k ] = time();
		}
	}
	if ( ! $new ) {
		return;
	}
	foreach ( $sent as $k => $t ) {
		if ( $t < time() - 120 * DAY_IN_SECONDS ) {
			unset( $sent[ $k ] );
		}
	}
	update_option( 'apac_push_sent', $sent, false );
	$n     = count( $new );
	$first = ltrim( $new[0][4], '- ' );
	apac_push_azienda( $az->id, 1 === $n ? 'Scadenza sicurezza' : $n . ' scadenze sicurezza', 1 === $n ? $first : $first . ' e altre ' . ( $n - 1 ), apac_url( 'scadenziario' ) );
}

/* ============================================================================
 * 3. FORMAZIONE RICHIESTA PER MANSIONE (indicata dallo studio)
 * ========================================================================= */

function apac_norm_corso( $c ) {
	return mb_strtolower( trim( preg_replace( '/\s+/', ' ', (string) $c ) ) );
}

/** mansione => [corsi] dell'azienda. */
function apac_mansioni_corsi( $azienda_id ) {
	global $wpdb;
	$out   = array();
	$label = array(); // Stessa mansione scritta con maiuscole/spazi diversi: un solo gruppo.
	foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'mansioni_corsi' ) . ' WHERE azienda_id = %d ORDER BY id', $azienda_id ) ) as $r ) {
		$k = apac_norm_corso( $r->mansione );
		if ( ! isset( $label[ $k ] ) ) {
			$label[ $k ] = $r->mansione;
		}
		$out[ $label[ $k ] ][ (int) $r->id ] = $r->corso;
	}
	ksort( $out, SORT_NATURAL | SORT_FLAG_CASE );
	return $out;
}

/** Corsi richiesti dalla mansione: [corso => stato] con stato 'ok' | 'scaduto' | 'mancante'. */
function apac_formazione_richiesta( $azienda_id, $l, $mc = null ) {
	global $wpdb;
	$mc = null === $mc ? apac_mansioni_corsi( $azienda_id ) : $mc;
	$m  = null;
	foreach ( array_keys( $mc ) as $k ) {
		if ( apac_norm_corso( $k ) === apac_norm_corso( $l->mansione ) && '' !== trim( (string) $l->mansione ) ) {
			$m = $k;
		}
	}
	if ( null === $m ) {
		return array();
	}
	$att = $wpdb->get_results( $wpdb->prepare( 'SELECT a.corso, a.data_scadenza FROM ' . apac_table( 'attestati' ) . ' a WHERE a.azienda_id = %d AND a.lavoratore_id = %d AND ' . apac_sql_attestato_corrente( 'a' ), $azienda_id, $l->id ) );
	$have = array();
	foreach ( $att as $a ) {
		$k  = apac_norm_corso( $a->corso );
		$st = ( $a->data_scadenza && $a->data_scadenza < apac_today() ) ? 'scaduto' : 'ok';
		if ( ! isset( $have[ $k ] ) || 'ok' === $st ) {
			$have[ $k ] = $st;
		}
	}
	$out = array();
	foreach ( $mc[ $m ] as $corso ) {
		$k             = apac_norm_corso( $corso );
		$out[ $corso ] = isset( $have[ $k ] ) ? $have[ $k ] : 'mancante';
	}
	return $out;
}

function apac_op_mansione_corso_add( $azienda_id ) {
	global $wpdb;
	$mansione = trim( sanitize_text_field( apac_post( 'mansione' ) ) );
	$back     = array( 'nuovo' => 'mansione_corso_add' );
	if ( '' === $mansione ) {
		apac_error_field( 'mansione' );
		apac_redirect( 'lavoratori', 'need_field', $back );
	}
	if ( mb_strlen( $mansione ) > 200 ) {
		apac_error_field( 'mansione' );
		apac_redirect( 'lavoratori', 'too_long', $back );
	}
	$corso = apac_corso_from_post( $back );
	foreach ( apac_mansioni_corsi( $azienda_id ) as $m => $corsi ) {
		if ( apac_norm_corso( $m ) === apac_norm_corso( $mansione ) ) {
			$mansione = $m; // Si usa la grafia già presente.
			foreach ( $corsi as $c ) {
				if ( apac_norm_corso( $c ) === apac_norm_corso( $corso ) ) {
					apac_redirect( 'lavoratori', 'saved', $back );
				}
			}
		}
	}
	apac_must_insert(
		'mansioni_corsi',
		array(
			'azienda_id' => $azienda_id,
			'mansione'   => $mansione,
			'corso'      => $corso,
		),
		'lavoratori',
		$back
	);
	apac_log_detail( $mansione . ': ' . $corso );
	apac_redirect( 'lavoratori', 'saved', $back );
}

function apac_op_mansione_corso_delete( $azienda_id ) {
	global $wpdb;
	$id  = absint( apac_post( 'id' ) );
	$row = apac_get_row_for_azienda( 'mansioni_corsi', $id, $azienda_id );
	if ( ! $row ) {
		apac_redirect( 'lavoratori', 'denied' );
	}
	apac_must( $wpdb->delete( apac_table( 'mansioni_corsi' ), array( 'id' => $id, 'azienda_id' => $azienda_id ) ), 'lavoratori', array(), true );
	apac_log_detail( $row->mansione . ': ' . $row->corso );
	apac_redirect( 'lavoratori', 'deleted', array( 'nuovo' => 'mansione_corso_add' ) );
}

/** Riquadro "Formazione richiesta per mansione" (pagina Lavoratori). */
function apac_render_mansioni_box( $az ) {
	$id  = (int) $az->id;
	$mc  = apac_mansioni_corsi( $id );
	$adm = apac_is_admin();
	if ( ! $adm && ! $mc ) {
		return;
	}
	$req  = isset( $_GET['nuovo'] ) ? sanitize_key( wp_unslash( $_GET['nuovo'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$open = 'mansione_corso_add' === $req || '' !== apac_details_open( 'mansione_corso_add', '' );
	$n    = 0;
	foreach ( $mc as $c ) {
		$n += count( $c );
	}
	// phpcs:disable WordPress.Security.EscapeOutput
	echo '<details class="apac-tools" id="apac-mansioni"' . ( $open ? ' open' : '' ) . '><summary>' . apac_icon( 'check' ) . '<span>Formazione richiesta per mansione' . ( $n ? ' (' . (int) $n . ')' : '' ) . '</span></summary><div class="apac-tools__body">';
	echo '<p class="apac-muted">Corsi indicati dallo studio per ciascuna mansione, sulla base della valutazione dei rischi. Le scadenze restano quelle registrate sui singoli attestati.</p>';
	if ( $mc ) {
		echo '<ul class="apac-mansioni">';
		foreach ( $mc as $mansione => $corsi ) {
			echo '<li><strong>' . esc_html( $mansione ) . '</strong><div class="apac-chips">';
			foreach ( $corsi as $cid => $corso ) {
				echo '<span class="apac-chip">' . esc_html( $corso );
				if ( $adm ) {
					echo apac_button_form( 'mansione_corso_delete', $id, array( 'id' => $cid ), '×', 'apac-chip__x', 'Togliere «' . $corso . '» dalla mansione «' . $mansione . '»?' );
				}
				echo '</span>';
			}
			echo '</div></li>';
		}
		echo '</ul>';
	} else {
		echo '<p class="apac-muted">Nessun corso indicato.</p>';
	}
	if ( $adm ) {
		$mansioni = array();
		foreach ( apac_get_lavoratori( $id, true ) as $l ) {
			if ( '' !== $l->mansione ) {
				$mansioni[ $l->mansione ] = $l->mansione;
			}
		}
		$corsi = array( '' => '— Seleziona —' );
		foreach ( apac_corsi() as $c ) {
			$corsi[ $c ] = $c;
		}
		echo '<div class="apac-subcard">' . apac_form_open( 'mansione_corso_add', $id ) . '<div class="apac-fields">';
		echo apac_field( 'Mansione *', 'mansione', '', 'text', 'required maxlength="200" list="apac-mansioni-list" placeholder="Es. Muratore"' );
		echo '<datalist id="apac-mansioni-list">';
		foreach ( $mansioni as $m ) {
			echo '<option value="' . esc_attr( $m ) . '">';
		}
		echo '</datalist>';
		echo apac_select( 'Corso richiesto *', 'corso', $corsi, '', 'required' );
		echo apac_field( 'Se “Altro”, specifica', 'corso_altro', '', 'text', 'maxlength="150"' );
		echo '</div><p class="apac-muted">Scrivi il nome del corso come viene registrato negli attestati: il confronto avviene sul nome.</p><div class="apac-actions"><button class="apac-btn apac-btn--small" type="submit">Aggiungi</button></div></form></div>';
	}
	echo '</div></details>';
	// phpcs:enable
}

/** Riquadro nella scheda del lavoratore. */
function apac_render_formazione_richiesta( $az, $l ) {
	$req = apac_formazione_richiesta( (int) $az->id, $l );
	if ( ! $req ) {
		return;
	}
	$lab = array(
		'ok'       => array( 'ok', 'Presente' ),
		'scaduto'  => array( 'danger', 'Scaduto' ),
		'mancante' => array( 'warn', 'Mancante' ),
	);
	echo '<section class="apac-card"><h2>Formazione richiesta per la mansione</h2><ul class="apac-list apac-reqlist">';
	foreach ( $req as $corso => $st ) {
		echo '<li><span>' . esc_html( $corso ) . '</span><span class="apac-badge apac-badge--' . esc_attr( $lab[ $st ][0] ) . '">' . esc_html( $lab[ $st ][1] ) . '</span></li>';
	}
	echo '</ul>';
	if ( in_array( 'mancante', $req, true ) || in_array( 'scaduto', $req, true ) ) {
		echo '<p><a class="apac-btn apac-btn--small apac-btn--ghost" href="' . esc_url( apac_url( 'segnalazioni', array( 'nuovo' => 'corso_richiesta', 'lav' => (int) $l->id ) ) . '#apac-add-corso_richiesta' ) . '">Richiedi un corso allo studio</a></p>';
	}
	echo '</section>';
}

/* ============================================================================
 * 4. PRESA VISIONE FIRMATA (firma con il dito o con il mouse)
 * ========================================================================= */

/** Firma dell'utente sulla versione attuale del documento (o null). */
function apac_firma_utente( $doc, $user_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'firme' ) . ' WHERE documento_id = %d AND user_id = %d ORDER BY id DESC LIMIT 1', $doc->id, $user_id ) );
}

/** Impronta SHA-256 del file attuale del documento. */
function apac_doc_sha( $doc ) {
	$p = apac_document_abs_path( $doc );
	return $p ? (string) hash_file( 'sha256', $p ) : '';
}

/** La firma riguarda il file attuale? */
function apac_firma_attuale( $firma, $doc ) {
	return $firma && (string) $firma->nome_file === (string) $doc->nome_file && hash_equals( (string) $firma->sha256, apac_doc_sha( $doc ) );
}

/** Documenti che l'utente corrente deve ancora firmare. */
function apac_documenti_da_firmare( $azienda_id, $user_id = 0 ) {
	global $wpdb;
	$user_id = $user_id ? $user_id : get_current_user_id();
	if ( user_can( $user_id, 'manage_options' ) ) {
		return array();
	}
	$out = array();
	foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'documenti' ) . ' WHERE azienda_id = %d AND richiede_firma = 1 ORDER BY created_at DESC', $azienda_id ) ) as $d ) {
		if ( ! apac_firma_attuale( apac_firma_utente( $d, $user_id ), $d ) ) {
			$out[] = $d;
		}
	}
	return $out;
}

/** Controllo dell'immagine della firma (PNG dal riquadro di disegno). @return string|false PNG binario */
function apac_firma_png( $data_url ) {
	if ( ! is_string( $data_url ) || 0 !== strpos( $data_url, 'data:image/png;base64,' ) || strlen( $data_url ) > 400000 ) {
		return false;
	}
	$bin = base64_decode( substr( $data_url, 22 ), true );
	if ( false === $bin || "\x89PNG\r\n\x1a\n" !== substr( $bin, 0, 8 ) ) {
		return false;
	}
	$info = function_exists( 'getimagesizefromstring' ) ? getimagesizefromstring( $bin ) : false;
	if ( ! $info || IMAGETYPE_PNG !== $info[2] || $info[0] < 150 || $info[1] < 60 || $info[0] > 3000 || $info[1] > 2000 ) {
		return false;
	}
	// Riquadro vuoto: nessun tratto disegnato.
	if ( function_exists( 'imagecreatefromstring' ) ) {
		$im = @imagecreatefromstring( $bin ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $im ) {
			$w     = imagesx( $im );
			$h     = imagesy( $im );
			$ink   = 0;
			$stepx = max( 1, (int) ( $w / 120 ) );
			$stepy = max( 1, (int) ( $h / 60 ) );
			for ( $x = 0; $x < $w; $x += $stepx ) {
				for ( $y = 0; $y < $h; $y += $stepy ) {
					$c = imagecolorsforindex( $im, imagecolorat( $im, $x, $y ) );
					if ( $c['alpha'] < 100 && ( $c['red'] + $c['green'] + $c['blue'] ) < 600 ) {
						$ink++;
					}
				}
			}
			imagedestroy( $im );
			if ( $ink < 8 ) {
				return false;
			}
		}
	}
	return $bin;
}

function apac_op_documento_firma( $azienda_id ) {
	global $wpdb;
	$id   = absint( apac_post( 'id' ) );
	$doc  = apac_get_row_for_azienda( 'documenti', $id, $azienda_id );
	$back = array( 'firma' => $id );
	if ( ! $doc || ! (int) $doc->richiede_firma || apac_is_admin() ) {
		apac_redirect( 'documenti', 'denied' );
	}
	$nome = trim( sanitize_text_field( apac_post( 'firma_nome' ) ) );
	if ( '' === $nome || mb_strlen( $nome ) > 150 ) {
		apac_error_field( 'firma_nome' );
		apac_redirect( 'documenti', '' === $nome ? 'need_field' : 'too_long', $back );
	}
	if ( ! apac_post( 'firma_ok' ) ) {
		apac_error_field( 'firma_ok' );
		apac_redirect( 'documenti', 'need_consent', $back );
	}
	$png = apac_firma_png( (string) apac_post( 'firma_img' ) );
	if ( false === $png ) {
		apac_error_field( 'firma_img' );
		apac_redirect( 'documenti', 'bad_signature', $back );
	}
	$sha = apac_doc_sha( $doc );
	if ( '' === $sha ) {
		apac_redirect( 'documenti', 'db_error', $back );
	}
	$uid = get_current_user_id();
	if ( apac_firma_attuale( apac_firma_utente( $doc, $uid ), $doc ) ) {
		apac_redirect( 'documenti', 'signed' );
	}
	$u = wp_get_current_user();
	apac_must_insert(
		'firme',
		array(
			'documento_id' => (int) $doc->id,
			'azienda_id'   => (int) $azienda_id,
			'user_id'      => $uid,
			'nome'         => $nome,
			'email'        => $u->user_email,
			'firma'        => 'data:image/png;base64,' . base64_encode( $png ),
			'sha256'       => $sha,
			'nome_file'    => $doc->nome_file,
			'ip'           => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'user_agent'   => isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 250 ) : '',
			'created_at'   => apac_now(),
		),
		'documenti',
		$back
	);
	$az = apac_get_azienda( $azienda_id );
	apac_push_after_commit(
		function () use ( $az, $doc, $nome ) {
			apac_push_admins( 'Presa visione firmata', $nome . ' (' . $az->ragione_sociale . '): ' . $doc->titolo, apac_url( 'documenti', array( 'azienda' => (int) $az->id ) ) );
		}
	);
	apac_log_detail( $doc->titolo . ' – ' . $nome );
	apac_redirect( 'documenti', 'signed' );
}

function apac_firma_testo( $doc ) {
	return 'Dichiaro di aver ricevuto e preso visione del documento «' . $doc->titolo . '» (file ' . $doc->nome_file . ').';
}

/** Pulsante e modulo di firma per il cliente, oppure elenco delle firme per lo studio. */
function apac_render_firma_cell( $d, $azienda_id ) {
	global $wpdb;
	if ( ! (int) $d->richiede_firma ) {
		return;
	}
	// phpcs:disable WordPress.Security.EscapeOutput
	if ( apac_is_admin() ) {
		$firme = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'firme' ) . ' WHERE documento_id = %d AND azienda_id = %d ORDER BY id DESC', $d->id, $azienda_id ) );
		$sha   = apac_doc_sha( $d );
		$att   = 0;
		foreach ( $firme as $f ) {
			$att += ( $f->nome_file === $d->nome_file && hash_equals( (string) $f->sha256, $sha ) ) ? 1 : 0;
		}
		echo '<details class="apac-edit apac-signs"><summary class="apac-btn apac-btn--small apac-btn--ghost">' . apac_icon_extra( 'pen' ) . '<span>Firme (' . (int) $att . ')</span></summary>';
		if ( ! $firme ) {
			echo '<p class="apac-muted">Nessuna firma ancora.</p>';
		} else {
			echo '<ul class="apac-signs__list">';
			foreach ( $firme as $f ) {
				$cur = $f->nome_file === $d->nome_file && hash_equals( (string) $f->sha256, $sha );
				echo '<li><img src="' . esc_attr( $f->firma ) . '" alt="Firma di ' . esc_attr( $f->nome ) . '" width="120" height="48"><span><strong>' . esc_html( $f->nome ) . '</strong><small>' . esc_html( apac_date_it( $f->created_at ) . ' ' . substr( $f->created_at, 11, 5 ) . ' · ' . $f->email ) . '</small><small>' . ( $cur ? 'Versione attuale del file' : 'Versione precedente (' . esc_html( $f->nome_file ) . ')' ) . ' · SHA-256 ' . esc_html( substr( $f->sha256, 0, 12 ) ) . '…</small></span></li>';
			}
			echo '</ul>';
		}
		echo '</details>';
		return;
	}
	$mia = apac_firma_utente( $d, get_current_user_id() );
	if ( apac_firma_attuale( $mia, $d ) ) {
		echo '<span class="apac-badge apac-badge--ok">Firmato il ' . esc_html( apac_date_it( $mia->created_at ) ) . '</span>';
		return;
	}
	$open = ( isset( $_GET['firma'] ) && absint( $_GET['firma'] ) === (int) $d->id ) || '' !== apac_details_open( 'documento_firma', 'id:' . (int) $d->id ); // phpcs:ignore WordPress.Security.NonceVerification
	$u    = wp_get_current_user();
	echo '<details class="apac-edit apac-sign" id="apac-firma-' . (int) $d->id . '"' . ( $open ? ' open' : '' ) . '><summary class="apac-btn apac-btn--small apac-btn--sign">' . apac_icon_extra( 'pen' ) . '<span>' . ( $mia ? 'Firma la nuova versione' : 'Leggi e firma' ) . '</span></summary>';
	echo '<div class="apac-sign__body"><ol class="apac-howto"><li><a href="' . esc_url( apac_file_url( $d->id, 'view' ) ) . '" target="_blank" rel="noopener">Apri e leggi il documento</a>.</li><li>Firma nel riquadro con il dito o con il mouse.</li><li>Conferma la presa visione.</li></ol>';
	echo apac_form_open( 'documento_firma', $azienda_id, false, 'apac-form apac-sign__form' ) . apac_hidden( 'id', (int) $d->id ) . '<input type="hidden" name="firma_img" value="" data-sign-out>';
	list( $cls, $aria, $msg ) = apac_field_error_parts( 'firma_img' );
	echo '<div class="apac-sign__pad' . $cls . '"><canvas data-sign-pad aria-label="Riquadro per la firma" role="img"></canvas><span class="apac-sign__hint" data-sign-hint>Firma qui</span><button type="button" class="apac-sign__clear" data-sign-clear>Cancella</button></div>' . $msg;
	echo '<noscript><p class="apac-notice apac-notice--warn">Per firmare serve JavaScript attivo nel browser.</p></noscript>';
	echo '<div class="apac-fields">' . apac_field( 'Nome e cognome *', 'firma_nome', $u->display_name, 'text', 'required maxlength="150" autocomplete="name"' ) . '</div>';
	list( $cls2, $aria2, $msg2 ) = apac_field_error_parts( 'firma_ok' );
	echo '<label class="apac-field apac-check apac-sign__ok' . $cls2 . '"><input type="checkbox" name="firma_ok" value="1" required' . $aria2 . '><span>' . esc_html( apac_firma_testo( $d ) ) . '</span></label>' . $msg2;
	echo '<p class="apac-sign__legal">Firma elettronica semplice con valore di presa visione: non sostituisce le firme richieste dalla legge su specifici documenti (ad esempio la data certa o la sottoscrizione del DVR, art. 28, c. 2, D.Lgs. 81/2008). Vengono registrati data, ora e impronta del file.</p>';
	echo '<div class="apac-actions"><button class="apac-btn" type="submit" data-sign-submit>Conferma e firma</button></div></form></div></details>';
	// phpcs:enable
}

/* ============================================================================
 * 5. RICHIESTA DI UN CORSO
 * ========================================================================= */

function apac_op_corso_richiesta( $azienda_id ) {
	global $wpdb;
	$back  = array( 'nuovo' => 'corso_richiesta' );
	$corso = trim( sanitize_text_field( apac_post( 'corso' ) ) );
	if ( '' === $corso ) {
		apac_error_field( 'corso' );
		apac_redirect( 'segnalazioni', 'need_field', $back );
	}
	if ( 'Altro' === $corso ) {
		$corso = trim( sanitize_text_field( apac_post( 'corso_altro' ) ) );
		if ( '' === $corso ) {
			apac_error_field( 'corso_altro' );
			apac_redirect( 'segnalazioni', 'need_corso_altro', $back );
		}
	} elseif ( ! in_array( $corso, apac_corsi(), true ) ) {
		apac_error_field( 'corso' );
		apac_redirect( 'segnalazioni', 'bad_value', $back );
	}
	$nomi = array();
	$ids  = isset( $_POST['lavoratori'] ) && is_array( $_POST['lavoratori'] ) ? array_map( 'absint', wp_unslash( $_POST['lavoratori'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
	foreach ( array_unique( array_filter( $ids ) ) as $lid ) {
		$l = apac_get_row_for_azienda( 'lavoratori', $lid, $azienda_id );
		if ( ! $l ) {
			apac_redirect( 'segnalazioni', 'bad_lavoratore', $back );
		}
		$nomi[] = $l->cognome . ' ' . $l->nome;
	}
	$note = sanitize_textarea_field( apac_post( 'note' ) );
	apac_check_lengths( array( 'titolo' => 'Richiesta corso: ' . $corso, 'descrizione' => $note ), 'segnalazioni', $back );
	$descr = 'Corso richiesto: ' . $corso . "\n" . 'Lavoratori: ' . ( $nomi ? implode( ', ', $nomi ) : 'da definire' ) . ( '' !== $note ? "\n\n" . $note : '' );
	apac_must_insert(
		'segnalazioni',
		array(
			'azienda_id'  => $azienda_id,
			'titolo'      => mb_substr( 'Richiesta corso: ' . $corso, 0, 200 ),
			'descrizione' => $descr,
			'priorita'    => 'media',
			'stato'       => 'aperta',
			'tipo'        => 'corso',
			'autore_id'   => get_current_user_id(),
			'created_at'  => apac_now(),
			'updated_at'  => apac_now(),
		),
		'segnalazioni',
		$back
	);
	if ( ! apac_is_admin() ) {
		$az = apac_get_azienda( $azienda_id );
		apac_tx_after_commit(
			function () use ( $az, $descr ) {
				$ok = apac_send_mail( get_option( 'admin_email' ), '[Area clienti] Richiesta corso da ' . $az->ragione_sociale, "Azienda: {$az->ragione_sociale}\n\n{$descr}\n\n" . apac_portal_page_url(), apac_auto_headers() );
				return $ok ? '' : 'sent_nomail';
			}
		);
		apac_push_after_commit(
			function () use ( $az, $corso ) {
				apac_push_admins( 'Richiesta corso', $az->ragione_sociale . ': ' . $corso, apac_url( 'segnalazioni', array( 'azienda' => (int) $az->id ) ) );
			}
		);
	}
	apac_log_detail( $corso . ( $nomi ? ' – ' . count( $nomi ) . ' lavorator' . ( 1 === count( $nomi ) ? 'e' : 'i' ) : '' ) );
	apac_redirect( 'segnalazioni', 'course_sent' );
}

function apac_render_corso_box( $az ) {
	$id   = (int) $az->id;
	$pre  = isset( $_GET['lav'] ) ? absint( $_GET['lav'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	$lavs = apac_get_lavoratori( $id, true );
	$corsi = array( '' => '— Seleziona —' );
	foreach ( apac_corsi() as $c ) {
		$corsi[ $c ] = $c;
	}
	// phpcs:disable WordPress.Security.EscapeOutput
	echo apac_add_box_open( 'corso_richiesta', 'Richiedi un corso', false, '', 'check' ) . '<section class="apac-card"><h2>Richiedi un corso</h2><p class="apac-muted">Lo studio ti ricontatta per date e modalità.</p>' . apac_form_open( 'corso_richiesta', $id ) . '<div class="apac-fields">';
	echo apac_select( 'Corso *', 'corso', $corsi, '', 'required' );
	echo apac_field( 'Se “Altro”, specifica', 'corso_altro', '', 'text', 'maxlength="150"' );
	echo '</div>';
	if ( $lavs ) {
		echo '<fieldset class="apac-pick"><legend>Per quali lavoratori (facoltativo)</legend><div class="apac-pick__list">';
		foreach ( $lavs as $l ) {
			echo '<label><input type="checkbox" name="lavoratori[]" value="' . (int) $l->id . '"' . checked( $pre, (int) $l->id, false ) . '><span>' . esc_html( $l->cognome . ' ' . $l->nome ) . ( '' !== $l->mansione ? ' <small>' . esc_html( $l->mansione ) . '</small>' : '' ) . '</span></label>';
		}
		echo '</div></fieldset>';
	}
	echo '<div class="apac-fields">' . apac_textarea( 'Note', 'note', '', 'Es. periodo preferito, sede, numero di partecipanti.' ) . '</div>';
	echo '<div class="apac-actions"><button class="apac-btn" type="submit">Invia la richiesta</button></div></form></section>' . apac_add_box_close();
	// phpcs:enable
}

/* ============================================================================
 * 6. COMPLETEZZA DEI DATI
 * ========================================================================= */

/**
 * Quanto sono completi i dati inseriti (0-100). NON è un giudizio di conformità.
 * @return array [ 'perc' => int, 'voci' => [ [ etichetta, punteggio 0..1, sezione, dettaglio ] ] ]
 */
function apac_completezza( $azienda_id ) {
	global $wpdb;
	$az = apac_get_azienda( $azienda_id );
	if ( ! $az ) {
		return array( 'perc' => 0, 'voci' => array() );
	}
	$v   = array();
	$dvr = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . apac_table( 'documenti' ) . " WHERE azienda_id = %d AND categoria = 'dvr'", $azienda_id ) );
	$v[] = array( 'DVR caricato in archivio', $dvr ? 1 : 0, 'documenti', '' );
	$v[] = array( 'RSPP indicato', '' !== trim( (string) $az->rspp_nome ) ? 1 : 0, 'azienda', '' );
	$v[] = array( 'RLS / RLST indicato', '' !== trim( (string) $az->rls_nome ) ? 1 : 0, 'azienda', '' );
	$v[] = array( 'Sorveglianza sanitaria e medico competente', ( '' !== $az->sorveglianza_sanitaria && ( 'si' !== $az->sorveglianza_sanitaria || '' !== trim( (string) $az->medico_competente ) ) ) ? 1 : 0, 'azienda', '' );
	$v[] = array( 'Addetti antincendio indicati', '' !== trim( (string) $az->addetti_antincendio ) ? 1 : 0, 'azienda', '' );
	$v[] = array( 'Addetti primo soccorso indicati', '' !== trim( (string) $az->addetti_primo_soccorso ) ? 1 : 0, 'azienda', '' );
	$lav = apac_get_lavoratori( $azienda_id, true );
	$v[] = array( 'Lavoratori inseriti', $lav ? 1 : 0, 'lavoratori', '' );
	if ( $lav ) {
		$mc   = apac_mansioni_corsi( $azienda_id );
		$mans = 0;
		$form = 0;
		$req  = 0;
		$reqok = 0;
		foreach ( $lav as $l ) {
			$mans += '' !== trim( (string) $l->mansione ) ? 1 : 0;
			$form += apac_count( 'attestati', 'azienda_id = %d AND lavoratore_id = %d', $azienda_id, $l->id ) ? 1 : 0;
			foreach ( apac_formazione_richiesta( $azienda_id, $l, $mc ) as $st ) {
				$req++;
				$reqok += 'ok' === $st ? 1 : 0;
			}
		}
		$n   = count( $lav );
		$v[] = array( 'Mansione indicata per ogni lavoratore', $mans / $n, 'lavoratori', $mans . ' su ' . $n );
		$v[] = array( 'Formazione registrata per ogni lavoratore', $form / $n, 'lavoratori', $form . ' su ' . $n );
		if ( $req ) {
			$v[] = array( 'Corsi richiesti per mansione presenti e validi', $reqok / $req, 'lavoratori', $reqok . ' su ' . $req );
		}
	}
	$scad_att = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . apac_table( 'attestati' ) . ' a INNER JOIN ' . apac_table( 'lavoratori' ) . ' l ON l.id = a.lavoratore_id WHERE a.azienda_id = %d AND l.attivo = 1 AND a.data_scadenza IS NOT NULL AND a.data_scadenza < %s AND ' . apac_sql_attestato_corrente( 'a' ), $azienda_id, apac_today() ) );
	$v[]      = array( 'Nessun attestato scaduto', $scad_att ? 0 : 1, 'scadenziario', $scad_att ? $scad_att . ' scadut' . ( 1 === $scad_att ? 'o' : 'i' ) : '' );
	$scad     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . apac_table( 'scadenze' ) . " WHERE azienda_id = %d AND stato = 'aperta' AND data_scadenza < %s", $azienda_id, apac_today() ) );
	$v[]      = array( 'Nessun adempimento superato', $scad ? 0 : 1, 'scadenziario', $scad ? $scad . ' superat' . ( 1 === $scad ? 'o' : 'i' ) : '' );
	$tot = 0;
	foreach ( $v as $x ) {
		$tot += $x[1];
	}
	return array(
		'perc' => (int) floor( 100 * $tot / count( $v ) ),
		'voci' => $v,
	);
}

function apac_completezza_level( $p ) {
	return $p >= 90 ? 'ok' : ( $p >= 60 ? 'warn' : 'danger' );
}

/** Indicatore compatto (Home) o completo (Criticità). */
function apac_render_completezza( $az, $full = false ) {
	$c   = apac_completezza( (int) $az->id );
	$lvl = apac_completezza_level( $c['perc'] );
	$mis = array_filter( $c['voci'], function ( $x ) { return $x[1] < 1; } );
	echo '<section class="apac-card apac-meter apac-meter--' . esc_attr( $lvl ) . ( $full ? '' : ' apac-meter--slim' ) . '"><div class="apac-meter__head"><h2>Completezza dei dati</h2><strong>' . (int) $c['perc'] . '%</strong></div>';
	echo '<div class="apac-meter__bar" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int) $c['perc'] . '" aria-label="Completezza dei dati"><span style="width:' . (int) $c['perc'] . '%"></span></div>';
	echo '<p class="apac-muted apac-meter__note">Indica quanto sono completi i dati inseriti qui: non certifica la conformità alla normativa.</p>';
	if ( $full ) {
		echo '<ul class="apac-meter__list">';
		foreach ( $c['voci'] as $x ) {
			$okk = $x[1] >= 1;
			echo '<li class="' . ( $okk ? 'is-ok' : 'is-miss' ) . '">' . apac_icon( $okk ? 'check' : 'alert' ) . '<span>' . esc_html( $x[0] ) . ( '' !== $x[3] ? ' <small>(' . esc_html( $x[3] ) . ')</small>' : '' ) . '</span>' . ( $okk ? '' : '<a href="' . esc_url( apac_url( $x[2] ) ) . '">Completa</a>' ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul>';
	} elseif ( $mis ) {
		echo '<p class="apac-meter__more"><a href="' . esc_url( apac_url( 'criticita' ) ) . '">Cosa manca (' . count( $mis ) . ') →</a></p>';
	}
	echo '</section>';
}

/* ============================================================================
 * 2. SITUAZIONE SICUREZZA (pagina stampabile / salvabile in PDF)
 * ========================================================================= */

function apac_report_url( $azienda_id ) {
	$args = array( 'apac_report' => 1 );
	if ( apac_is_admin() ) {
		$args['azienda'] = (int) $azienda_id;
	}
	return add_query_arg( $args, apac_portal_page_url() );
}

add_action( 'init', 'apac_serve_report', 19 );
function apac_serve_report() {
	if ( is_admin() || ! isset( $_GET['apac_report'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	if ( ! is_user_logged_in() || ! apac_user_can_use_portal() ) {
		wp_safe_redirect( apac_portal_page_url() );
		exit;
	}
	$az_id = apac_is_admin() ? absint( $_GET['azienda'] ?? 0 ) : apac_user_azienda_id(); // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! apac_can_access_azienda( $az_id ) ) {
		wp_die( 'Azienda non disponibile.', 'Accesso negato', array( 'response' => 403 ) );
	}
	apac_require_consents_for_download();
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'X-Content-Type-Options: nosniff' );
	apac_render_report( apac_get_azienda( $az_id ) );
	exit;
}

function apac_render_report( $az ) {
	global $wpdb;
	$id    = (int) $az->id;
	$c     = apac_completezza( $id );
	$crit  = apac_analizza_criticita( $id );
	$lav   = apac_get_lavoratori( $id, true );
	$mc    = apac_mansioni_corsi( $id );
	$cats  = apac_categorie_documenti();
	$e     = 'esc_html';
	$today = apac_date_it( apac_today() ) . ' ' . current_time( 'H:i' );
	$sorv  = array( 'si' => 'Sì', 'no' => 'No' );
	?><!doctype html>
<html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title><?php echo $e( 'Riepilogo dati e scadenze – ' . $az->ragione_sociale ); // phpcs:ignore WordPress.Security.EscapeOutput ?></title>
<style>
:root{--g:#193E0C;--gd:#061C03;--y:#FDE117;--t:#202020;--t2:#4A4A4A;--b:#DADADA;--ok:#1d7a46;--okb:#e5f4ea;--w:#7a5b00;--wb:#fff6c7;--d:#b42318;--db:#fde8e6}
*{box-sizing:border-box}body{margin:0;font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:var(--t);background:#f3f3f3}
.page{max-width:900px;margin:0 auto;padding:24px 16px 48px}
.bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px}.bar a,.bar button{font:inherit;font-weight:700;min-height:44px;padding:10px 18px;border-radius:10px;border:1.5px solid var(--g);background:#fff;color:var(--g);cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}.bar button{background:var(--g);color:#fff}
.sheet{background:#fff;border-radius:14px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.06)}
header.h{border-bottom:4px solid var(--y);padding-bottom:14px;margin-bottom:18px}header.h small{display:block;color:var(--t2);font-weight:700;letter-spacing:.06em;text-transform:uppercase;font-size:12px}h1{margin:4px 0 2px;color:var(--gd);font-size:26px;line-height:1.2}header.h p{margin:0;color:var(--t2)}
h2{color:var(--g);font-size:18px;margin:26px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--b);break-after:avoid}
table{width:100%;border-collapse:collapse;font-size:14px}th,td{text-align:left;padding:7px 8px;border-bottom:1px solid #eee;vertical-align:top}th{color:var(--g);font-size:12px;text-transform:uppercase;letter-spacing:.04em}tr{break-inside:avoid}
.kv td:first-child{width:38%;color:var(--t2)}
.b{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap}.ok{background:var(--okb);color:var(--ok)}.warn{background:var(--wb);color:var(--w)}.danger{background:var(--db);color:var(--d)}.muted{background:#eee;color:#555}
.meter{display:flex;align-items:center;gap:14px}.meter strong{font-size:28px;color:var(--gd)}.mbar{flex:1;height:12px;background:#eee;border-radius:99px;overflow:hidden}.mbar span{display:block;height:100%;background:var(--g)}
ul.c{list-style:none;padding-left:0;margin:6px 0}ul.c li{margin:3px 0}.b,.mbar,.mbar span{-webkit-print-color-adjust:exact;print-color-adjust:exact}
.note{font-size:12px;color:var(--t2);margin-top:28px;border-top:1px solid var(--b);padding-top:10px}
@media print{body{background:#fff;font-size:11.5pt}.page{max-width:none;padding:0}.bar{display:none}.sheet{box-shadow:none;padding:0;border-radius:0}h2{margin-top:18px}a{color:inherit;text-decoration:none}@page{size:A4;margin:16mm 14mm}}
@media (max-width:600px){.sheet{padding:18px}.wide{display:block;overflow-x:auto}}
</style></head><body><div class="page">
<div class="bar"><button type="button" onclick="window.print()">Stampa o salva in PDF</button><a href="<?php echo esc_url( apac_url( 'dashboard', apac_is_admin() ? array( 'azienda' => $id ) : array() ) ); ?>">Torna all’area riservata</a></div>
<div class="sheet">
<header class="h"><small>Riepilogo dati e scadenze · AP Sicurezza Lavoro</small><h1><?php echo $e( $az->ragione_sociale ); // phpcs:ignore WordPress.Security.EscapeOutput ?></h1><p>Generato il <?php echo $e( $today ); // phpcs:ignore WordPress.Security.EscapeOutput ?> con i dati presenti nell’area riservata. Non è una certificazione di conformità.</p></header>

<h2>Completezza dei dati</h2>
<div class="meter"><strong><?php echo (int) $c['perc']; ?>%</strong><div class="mbar"><span style="width:<?php echo (int) $c['perc']; ?>%"></span></div></div>
<ul class="c"><?php foreach ( $c['voci'] as $x ) { echo '<li>' . ( $x[1] >= 1 ? '✓ ' : '✗ ' ) . $e( $x[0] ) . ( '' !== $x[3] ? ' (' . $e( $x[3] ) . ')' : '' ) . '</li>'; } // phpcs:ignore WordPress.Security.EscapeOutput ?></ul>

<h2>Azienda e figure della sicurezza</h2>
<table class="kv"><tbody>
<?php
$rows = array(
	'Partita IVA'                => $az->partita_iva,
	'Sede'                       => trim( $az->indirizzo . ' ' . $az->comune . ( $az->provincia ? ' (' . $az->provincia . ')' : '' ) ),
	'Codice ATECO'               => $az->ateco,
	'Datore di lavoro'           => $az->datore_lavoro,
	'RSPP'                       => $az->rspp_nome,
	'RLS / RLST'                 => $az->rls_nome,
	'Sorveglianza sanitaria'     => isset( $sorv[ $az->sorveglianza_sanitaria ] ) ? $sorv[ $az->sorveglianza_sanitaria ] : '',
	'Medico competente'          => $az->medico_competente,
	'Addetti antincendio'        => $az->addetti_antincendio,
	'Addetti primo soccorso'     => $az->addetti_primo_soccorso,
);
foreach ( $rows as $k => $val ) {
	echo '<tr><td>' . $e( $k ) . '</td><td>' . ( '' !== trim( (string) $val ) ? nl2br( $e( $val ) ) : '<span class="b warn">Non indicato</span>' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
}
?>
</tbody></table>

<h2>Punti da verificare (<?php echo count( $crit ); ?>)</h2>
<?php
if ( ! $crit ) {
	echo '<p>Nessuna criticità rilevata sui dati inseriti.</p>';
} else {
	echo '<table><thead><tr><th>Livello</th><th>Punto</th><th>Riferimento</th></tr></thead><tbody>';
	foreach ( $crit as $x ) {
		$cl = array( 'alta' => 'danger', 'media' => 'warn', 'bassa' => 'muted' );
		echo '<tr><td><span class="b ' . esc_attr( $cl[ $x['livello'] ] ) . '">' . $e( ucfirst( $x['livello'] ) ) . '</span></td><td><strong>' . $e( $x['titolo'] ) . '</strong><br>' . $e( $x['dettaglio'] ) . '</td><td>' . $e( $x['norma'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</tbody></table>';
}
?>

<h2>Lavoratori e formazione (<?php echo count( $lav ); ?> in forza)</h2>
<?php
if ( ! $lav ) {
	echo '<p>Nessun lavoratore registrato.</p>';
} else {
	echo '<div class="wide"><table><thead><tr><th>Lavoratore</th><th>Mansione</th><th>Attestati in vigore</th>' . ( $mc ? '<th>Richiesti per la mansione</th>' : '' ) . '</tr></thead><tbody>';
	foreach ( $lav as $l ) {
		$att = $wpdb->get_results( $wpdb->prepare( 'SELECT a.corso, a.data_scadenza FROM ' . apac_table( 'attestati' ) . ' a WHERE a.azienda_id = %d AND a.lavoratore_id = %d AND ' . apac_sql_attestato_corrente( 'a' ) . ' ORDER BY a.corso', $id, $l->id ) );
		$list = array();
		foreach ( $att as $a ) {
			if ( ! $a->data_scadenza ) {
				$b = '<span class="b muted">senza scadenza</span>';
			} else {
				$g = apac_days_until( $a->data_scadenza );
				$b = '<span class="b ' . ( $g < 0 ? 'danger' : ( $g <= 30 ? 'warn' : 'ok' ) ) . '">' . ( $g < 0 ? 'scaduto il ' : 'fino al ' ) . $e( apac_date_it( $a->data_scadenza ) ) . '</span>';
			}
			$list[] = $e( $a->corso ) . ' ' . $b;
		}
		echo '<tr><td><strong>' . $e( $l->cognome . ' ' . $l->nome ) . '</strong></td><td>' . ( '' !== $l->mansione ? $e( $l->mansione ) : '<span class="b warn">Da definire</span>' ) . '</td><td>' . ( $list ? implode( '<br>', $list ) : '<span class="b warn">Nessun attestato</span>' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $mc ) {
			$req = apac_formazione_richiesta( $id, $l, $mc );
			$out = array();
			foreach ( $req as $corso => $st ) {
				$out[] = $e( $corso ) . ' <span class="b ' . ( 'ok' === $st ? 'ok' : ( 'scaduto' === $st ? 'danger' : 'warn' ) ) . '">' . ( 'ok' === $st ? 'presente' : $st ) . '</span>';
			}
			echo '<td>' . ( $out ? implode( '<br>', $out ) : '—' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tr>';
	}
	echo '</tbody></table></div>';
}
?>

<h2>Scadenze dei prossimi 90 giorni e superate</h2>
<?php
$ag = apac_agenda( $id, 90 );
if ( ! $ag ) {
	echo '<p>Nessuna scadenza superata o nei prossimi 90 giorni.</p>';
} else {
	echo '<table><thead><tr><th>Data</th><th>Descrizione</th><th>Tipo</th><th>Stato</th></tr></thead><tbody>';
	foreach ( $ag as $i ) {
		$g = apac_days_until( $i['data'] );
		echo '<tr><td>' . $e( apac_date_it( $i['data'] ) ) . '</td><td>' . $e( $i['titolo'] ) . '</td><td>' . $e( $i['tipo'] ) . '</td><td><span class="b ' . ( $g < 0 ? 'danger' : 'warn' ) . '">' . ( $g < 0 ? 'Superata' : 'Tra ' . (int) $g . ' gg' ) . '</span></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</tbody></table>';
}
?>

<h2>Documenti in archivio</h2>
<?php
$docs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'documenti' ) . ' WHERE azienda_id = %d ORDER BY categoria, created_at DESC', $id ) );
if ( ! $docs ) {
	echo '<p>Nessun documento.</p>';
} else {
	echo '<table><thead><tr><th>Documento</th><th>Categoria</th><th>Caricato</th>' . ( array_filter( wp_list_pluck( $docs, 'richiede_firma' ) ) ? '<th>Prese visione</th>' : '' ) . '</tr></thead><tbody>';
	$has_sign = (bool) array_filter( wp_list_pluck( $docs, 'richiede_firma' ) );
	foreach ( $docs as $d ) {
		echo '<tr><td>' . $e( $d->titolo ) . '</td><td>' . $e( isset( $cats[ $d->categoria ] ) ? $cats[ $d->categoria ] : $d->categoria ) . '</td><td>' . $e( apac_date_it( $d->created_at ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $has_sign ) {
			if ( (int) $d->richiede_firma ) {
				$sha = apac_doc_sha( $d );
				$n   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT user_id) FROM ' . apac_table( 'firme' ) . ' WHERE documento_id = %d AND sha256 = %s', $d->id, $sha ) );
				echo '<td>' . (int) $n . ' firm' . ( 1 === $n ? 'a' : 'e' ) . '</td>';
			} else {
				echo '<td>—</td>';
			}
		}
		echo '</tr>';
	}
	echo '</tbody></table>';
}
?>
<p class="note">Documento generato automaticamente dall’area riservata di AP Sicurezza Lavoro sui dati inseriti dallo studio e dall’azienda. È un supporto alla gestione: non certifica la conformità alla normativa (D.Lgs. 81/2008 e s.m.i.) e non sostituisce la valutazione del datore di lavoro, dell’RSPP e dei professionisti competenti.</p>
</div></div></body></html>
	<?php
}

/** Icona delle notifiche: l'icona del sito (se impostata). */
function apac_push_icon_url() {
	$u = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 192 ) : '';
	return $u ? $u : '';
}

/* ============================================================================
 * JavaScript: notifiche e riquadro di firma
 * ========================================================================= */
add_action( 'wp_footer', 'apac_s3_script', 41 );
function apac_s3_script() {
	if ( ! function_exists( 'apac_is_portal_request' ) || ! apac_is_portal_request() || ! is_user_logged_in() ) {
		return;
	}
	?>
<script>
(function () {
  'use strict';
  var cfg = window.apacUi || {};
  /* ---------- Firma con il dito o con il mouse ---------- */
  Array.prototype.forEach.call(document.querySelectorAll('[data-sign-pad]'), function (cv) {
    var form = cv.closest('form'), out = form.querySelector('[data-sign-out]'), hint = form.querySelector('[data-sign-hint]');
    var ctx = cv.getContext('2d'), drawing = false, strokes = 0, last = null, ready = false;
    function size() {
      var r = cv.getBoundingClientRect(); if (!r.width) return;
      var ratio = Math.max(window.devicePixelRatio || 1, 1);
      var old = strokes ? cv.toDataURL('image/png') : null;
      cv.width = Math.round(r.width * ratio); cv.height = Math.round(r.height * ratio);
      ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
      ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0b1f4d';
      if (old) { var im = new Image(); im.onload = function () { ctx.drawImage(im, 0, 0, r.width, r.height); }; im.src = old; }
      ready = true;
    }
    function pos(e) { var r = cv.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }
    cv.addEventListener('pointerdown', function (e) { if (!ready) size(); drawing = true; last = pos(e); cv.setPointerCapture(e.pointerId); ctx.beginPath(); ctx.arc(last.x, last.y, 1.1, 0, 6.3); ctx.fillStyle = '#0b1f4d'; ctx.fill(); e.preventDefault(); });
    cv.addEventListener('pointermove', function (e) { if (!drawing) return; var p = pos(e); ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke(); last = p; e.preventDefault(); });
    function exportImg() {
      if (out.getAttribute('data-sign-format') !== 'jpeg') return cv.toDataURL('image/png');
      var t = document.createElement('canvas'); t.width = cv.width; t.height = cv.height;
      var c = t.getContext('2d'); c.fillStyle = '#fff'; c.fillRect(0, 0, t.width, t.height); c.drawImage(cv, 0, 0);
      return t.toDataURL('image/jpeg', 0.85);
    }
    function end() { if (drawing) { drawing = false; strokes++; if (hint) hint.hidden = true; out.value = exportImg(); } }
    cv.addEventListener('pointerup', end); cv.addEventListener('pointercancel', end); cv.addEventListener('pointerleave', end);
    form.querySelector('[data-sign-clear]').addEventListener('click', function () { ctx.clearRect(0, 0, cv.width, cv.height); strokes = 0; out.value = ''; if (hint) hint.hidden = false; });
    form.addEventListener('submit', function (e) {
      var noSign = form.querySelector('[name=accordo][value=no_poteri]:checked') && !form.querySelector('[name=ok_privacy]');
      if (!noSign && (!strokes || !out.value)) { e.preventDefault(); cv.parentNode.classList.add('is-empty'); cv.scrollIntoView({ block: 'center' }); }
    });
    var det = cv.closest('details');
    if (det) det.addEventListener('toggle', function () { if (det.open) size(); });
    if (cv.getBoundingClientRect().width) size();
    window.addEventListener('resize', function () { if (cv.getBoundingClientRect().width) size(); });
  });

  /* ---------- Notifiche sul telefono ---------- */
  var boxes = document.querySelectorAll('[data-apac-push]'), invite = document.querySelector('[data-apac-push-invite]');
  if (!cfg.push || (!boxes.length && !invite)) return;
  var ua = navigator.userAgent || '';
  var ios = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var standalone = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
  var supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && window.isSecureContext;
  function b64(s) { s = (s + '===='.slice((s.length % 4) || 4)).replace(/-/g, '+').replace(/_/g, '/'); var r = atob(s), a = new Uint8Array(r.length); for (var i = 0; i < r.length; i++) a[i] = r.charCodeAt(i); return a; }
  function enc(buf) { return btoa(String.fromCharCode.apply(null, new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
  function send(act, sub) {
    var fd = new FormData(); fd.append('apac_push', act); fd.append('_wpnonce', cfg.push.nonce);
    if (sub) { var j = sub.toJSON ? sub.toJSON() : {}; fd.append('endpoint', sub.endpoint); fd.append('p256dh', (j.keys && j.keys.p256dh) || enc(sub.getKey('p256dh'))); fd.append('auth', (j.keys && j.keys.auth) || enc(sub.getKey('auth'))); }
    return fetch(cfg.push.url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  // 1.9.13: l'iscrizione del browser deve usare la chiave attuale del sito; se no (iscrizione vecchia o di un altro plugin) va rifatta.
  function keyOk(sub) { try { var k = sub.options && sub.options.applicationServerKey; return !k || enc(k) === String(cfg.push.key).replace(/=+$/, ''); } catch (x) { return true; } }
  function fresh(r) { return r.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(cfg.push.key) }); }
  function ensure(r) { return r.pushManager.getSubscription().then(function (sub) { if (sub && keyOk(sub)) return sub; return (sub ? sub.unsubscribe().catch(function () {}) : Promise.resolve()).then(function () { return fresh(r); }); }); }
  function why(e) {
    var n = (e && e.name) || '';
    if (n === 'AbortError' || n === 'NotAllowedError' && Notification.permission === 'granted') return 'Il browser non è riuscito a registrarsi al servizio notifiche. Su Brave attiva «Usa i servizi Google per la messaggistica push» (Impostazioni › Privacy); su altri browser controlla di non essere in navigazione privata.';
    if (n === 'NotAllowedError') return 'Permesso non concesso dal browser.';
    return 'Operazione non riuscita' + (n ? ' (' + n + ')' : '') + '. Ricarica la pagina e riprova.';
  }
  function reg() { return navigator.serviceWorker.getRegistration(cfg.scope).then(function (r) { return r || navigator.serviceWorker.register(cfg.sw, { scope: cfg.scope, updateViaCache: 'none' }); }).then(function () { return navigator.serviceWorker.ready; }); }
  function render(state, text) {
    Array.prototype.forEach.call(boxes, function (b) {
      b.querySelector('[data-push-state]').textContent = text;
      b.querySelector('[data-push-on]').hidden = state !== 'off';
      b.querySelector('[data-push-off]').hidden = state !== 'on';
      b.querySelector('[data-push-test]').hidden = state !== 'on';
    });
    var hide = false; try { hide = Date.now() - Number(localStorage.getItem('apac-push-hide') || 0) < 30 * 86400000; } catch (e) {}
    if (invite) invite.hidden = !(state === 'off' && !hide);
  }
  function refresh() {
    if (!supported) {
      render('na', ios && !standalone ? 'Su iPhone le notifiche funzionano dall’app installata sulla schermata Home (iOS 16.4 o successivo): apri l’area riservata dall’icona dell’app.' : 'Questo browser non supporta le notifiche.');
      return;
    }
    if (Notification.permission === 'denied') { render('na', 'Bloccate: il permesso è stato negato. Per riattivarle apri le impostazioni del browser (o del telefono) per questo sito, consenti le notifiche e ricarica la pagina.'); return; }
    reg().then(function (r) { return r.pushManager.getSubscription(); }).then(function (sub) {
      if (sub && Notification.permission === 'granted' && !keyOk(sub)) render('off', 'Da riattivare su questo dispositivo: tocca «Attiva le notifiche».');
      else if (sub && Notification.permission === 'granted') {
        var k = 'apac-push-sync-' + (cfg.push.user || ''), last = 0; try { last = Number(localStorage.getItem(k) || 0); } catch (x) {}
        if (Date.now() - last > 86400000) { send('subscribe', sub).then(function () { try { localStorage.setItem(k, String(Date.now())); } catch (x) {} }).catch(function () {}); }
        render('on', 'Attive su questo dispositivo: riceverai scadenze, nuovi documenti e risposte dello studio.');
      }
      // 1.9.9: stato reale del permesso del browser.
      else if (Notification.permission === 'granted') render('off', 'Autorizzate ma non ancora attive su questo dispositivo: tocca «Attiva le notifiche».');
      else render('off', 'Da autorizzare: tocca «Attiva le notifiche» e rispondi «Consenti» alla richiesta del browser.');
    }).catch(function () { render('off', 'Stato non verificabile su questo dispositivo (app non ancora pronta). Ricarica la pagina; se il problema resta, tocca «Attiva le notifiche».'); });
  }
  function on(btn) {
    btn.disabled = true;
    Notification.requestPermission().then(function (p) {
      if (p !== 'granted') { refresh(); throw new Error('denied'); }
      return reg();
    }).then(ensure)
      .then(function (sub) { return send('subscribe', sub); })
      .then(function (j) { render(j && j.success ? 'on' : 'off', (j && j.data && j.data.msg) || 'Operazione non riuscita.'); })
      .catch(function (e) { if (e && e.message !== 'denied') render('off', 'Attivazione non riuscita. ' + why(e)); })
      .then(function () { btn.disabled = false; });
  }
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-push-on],[data-push-off],[data-push-test],[data-push-invite-hide]'); if (!t) return;
    if (t.hasAttribute('data-push-on')) on(t);
    if (t.hasAttribute('data-push-invite-hide')) { try { localStorage.setItem('apac-push-hide', String(Date.now())); } catch (x) {} invite.hidden = true; }
    if (t.hasAttribute('data-push-off')) reg().then(function (r) { return r.pushManager.getSubscription(); }).then(function (sub) { if (!sub) return refresh(); return send('unsubscribe', sub).then(function () { return sub.unsubscribe(); }).then(refresh); });
    // 1.9.13: prima della prova l'iscrizione viene registrata di nuovo sul server (se era stata cancellata la prova
    // falliva con «(0)»); se il servizio del browser la rifiuta (403/404/410) si rifà l'iscrizione e si riprova una volta.
    if (t.hasAttribute('data-push-test')) {
      var show = function (m) { Array.prototype.forEach.call(boxes, function (b) { b.querySelector('[data-push-state]').textContent = m; }); };
      t.disabled = true; show('Invio della prova in corso…');
      reg().then(function (r) {
        var prova = function (sub) { return send('subscribe', sub).then(function () { return send('test', sub); }); };
        return ensure(r).then(function (sub) {
          return prova(sub).then(function (j) {
            var c = j && j.data && j.data.code;
            if (j && !j.success && (c === 403 || c === 404 || c === 410)) {
              return send('unsubscribe', sub).catch(function () {}).then(function () { return sub.unsubscribe().catch(function () {}); }).then(function () { return fresh(r); }).then(prova);
            }
            return j;
          });
        });
      }).then(function (j) { show((j && j.data && j.data.msg) || 'Invio non riuscito.'); })
        .catch(function (e) { show('Invio non riuscito. ' + why(e)); })
        .then(function () { t.disabled = false; });
    }
  });
  refresh();
})();
</script>
	<?php
}
