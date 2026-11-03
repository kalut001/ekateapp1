<?php
/**
 * Notifiche sul telefono e sul computer (Web Push: RFC 8030, 8291, 8292).
 *
 * 1.9.15: file per area; il codice proviene dai file per versione (session1-3.php, v190.php), invariato.
 */
defined( 'ABSPATH' ) || exit;

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

/** Icona delle notifiche: l'icona del sito (se impostata). */
function apac_push_icon_url() {
	$u = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 192 ) : '';
	return $u ? $u : '';
}
