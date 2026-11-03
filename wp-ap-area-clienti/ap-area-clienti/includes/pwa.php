<?php
defined( 'ABSPATH' ) || exit;

/*
 * Compatibilità con la web app installabile (PWA).
 *
 * La web app ha come ambito /area-riservata/: ogni pagina fuori da questo
 * percorso (wp-login.php, wp-admin/admin-post.php...) sul telefono si apre fuori
 * dall'app. Per questo accesso, uscita, salvataggi e download dell'area clienti
 * passano ora dall'indirizzo stesso dell'area riservata. Gli indirizzi precedenti
 * (admin-post.php) restano attivi per compatibilità.
 */

/** Indirizzo a cui inviare i moduli dell'area clienti (dentro l'ambito della web app). */
function apac_portal_post_url() {
	return apac_portal_page_url();
}

/** Uscita senza passare da wp-login.php. */
function apac_logout_url() {
	return wp_nonce_url( add_query_arg( 'apac_logout', '1', apac_portal_page_url() ), 'apac_logout' );
}

add_action( 'init', 'apac_portal_router', 20 );
function apac_portal_router() {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification -- ogni ramo verifica il proprio nonce.
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

	// Operazioni dei moduli (stesso gestore di admin-post.php, nonce verificato lì).
	if ( 'POST' === $method && isset( $_POST['action'] ) && 'apac_action' === $_POST['action'] ) {
		nocache_headers();
		if ( ! is_user_logged_in() ) {
			apac_serve_file_denied();
		}
		apac_handle_action();
		exit;
	}

	// Accesso dall'area riservata.
	if ( 'POST' === $method && isset( $_POST['apac_login'] ) ) {
		apac_portal_login();
	}

	// Download e visualizzazione dei documenti (nonce verificato in apac_serve_file).
	if ( 'GET' === $method && isset( $_GET['apac_file'] ) ) {
		$_GET['id'] = absint( $_GET['apac_file'] );
		if ( ! is_user_logged_in() ) {
			apac_serve_file_denied();
		}
		apac_serve_file();
		exit;
	}

	// Uscita.
	if ( isset( $_GET['apac_logout'] ) ) {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( is_user_logged_in() && wp_verify_nonce( $nonce, 'apac_logout' ) ) {
			wp_logout();
		}
		nocache_headers();
		wp_safe_redirect( add_query_arg( 'apac_out', '1', apac_portal_page_url() ) );
		exit;
	}
	// phpcs:enable
}

/**
 * Accesso con email e password dall'area riservata.
 * Usa wp_signon(): restano attive tutte le protezioni collegate al filtro
 * "authenticate" (limite tentativi, plugin di sicurezza).
 */
function apac_portal_login() {
	nocache_headers();
	$back  = apac_portal_page_url();
	$nonce = isset( $_POST['_apac_login_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_apac_login_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! wp_verify_nonce( $nonce, 'apac_login' ) ) {
		wp_safe_redirect( add_query_arg( 'apac_login_error', 'scaduta', $back ) );
		exit;
	}
	$user = wp_signon(
		array(
			'user_login'    => isset( $_POST['log'] ) ? sanitize_user( wp_unslash( $_POST['log'] ) ) : '',
			'user_password' => isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- la password non va alterata.
			'remember'      => ! empty( $_POST['rememberme'] ),
		),
		is_ssl()
	);
	if ( is_wp_error( $user ) ) {
		$code = in_array( $user->get_error_code(), array( 'invalid_username', 'invalid_email', 'incorrect_password', 'empty_username', 'empty_password' ), true ) ? 'credenziali' : 'bloccato';
		wp_safe_redirect( add_query_arg( 'apac_login_error', $code, $back ) );
		exit;
	}
	wp_safe_redirect( $back );
	exit;
}

/** Modulo di accesso (sostituisce wp_login_form, che invierebbe a wp-login.php). */
function apac_render_login_form() {
	$err  = isset( $_GET['apac_login_error'] ) ? sanitize_key( wp_unslash( $_GET['apac_login_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$msgs = array(
		'credenziali' => 'Email o password non corrette. Riprova oppure usa “Password dimenticata?”.',
		'scaduta'     => 'La pagina era aperta da troppo tempo: inserisci di nuovo email e password.',
		'bloccato'    => 'Accesso non riuscito. Se hai sbagliato più volte attendi qualche minuto e riprova.',
	);
	if ( isset( $msgs[ $err ] ) ) {
		echo '<div class="apac-notice apac-notice--error" role="alert">' . esc_html( $msgs[ $err ] ) . '</div>';
	}
	if ( isset( $_GET['apac_out'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="apac-notice apac-notice--ok" role="status">Sessione chiusa.</div>';
	}
	echo '<form method="post" class="apac-login-form" action="' . esc_url( apac_portal_post_url() ) . '">';
	echo '<input type="hidden" name="apac_login" value="1">';
	echo '<input type="hidden" name="_apac_login_nonce" value="' . esc_attr( wp_create_nonce( 'apac_login' ) ) . '">';
	echo '<p class="login-username"><label for="apac-user">Email</label><input type="text" name="log" id="apac-user" autocomplete="username" inputmode="email" required></p>';
	echo '<p class="login-password"><label for="apac-pass">Password</label><input type="password" name="pwd" id="apac-pass" autocomplete="current-password" required></p>';
	echo '<p class="login-remember"><label><input name="rememberme" type="checkbox" value="forever"> Ricordami</label></p>';
	echo '<p class="login-submit"><button type="submit" class="apac-btn">Accedi</button></p>';
	echo '</form>';
}

/*
 * Icona per iPhone: se la web app fornisce la propria icona (PNG), si toglie
 * quella generica di WordPress (WebP), così il telefono usa sempre la stessa.
 */
add_filter( 'site_icon_meta_tags', 'apac_dedupe_apple_icon' );
function apac_dedupe_apple_icon( $tags ) {
	if ( ! apac_pwa_plugin_active() ) {
		return $tags;
	}
	return array_values(
		array_filter(
			(array) $tags,
			function ( $t ) {
				return false === strpos( (string) $t, 'apple-touch-icon' );
			}
		)
	);
}

function apac_pwa_plugin_active() {
	$active = (array) get_option( 'active_plugins', array() );
	return in_array( 'ap-sicurezza-pwa/ap-sicurezza-pwa.php', $active, true );
}

/*
 * 1.9.9 – Un solo service worker nell'area riservata.
 * Il plugin "AP Sicurezza PWA" fornisce il manifest (nome e icone dell'app) e, fuori dall'area riservata,
 * il riquadro di installazione. Il suo script registra però un secondo service worker sullo stesso ambito
 * /area-riservata/, senza gestione delle notifiche: al primo accesso i due si contendevano la registrazione.
 * Nelle pagine dell'area riservata lo script viene quindi tolto: service worker, installazione,
 * aggiornamenti e notifiche sono gestiti solo da AP Area Clienti. Il manifest resta quello del plugin PWA.
 */
add_action( 'wp_enqueue_scripts', 'apac_single_service_worker', 999 );
add_action( 'wp_print_scripts', 'apac_single_service_worker', 999 );
function apac_single_service_worker() {
	if ( ! apac_pwa_plugin_active() || ! function_exists( 'apac_is_portal_request' ) || ! apac_is_portal_request() ) {
		return;
	}
	wp_dequeue_script( 'ap-sicurezza-pwa' );
	wp_deregister_script( 'ap-sicurezza-pwa' );
}
