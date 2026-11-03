<?php
defined( 'ABSPATH' ) || exit;

/*
 * Consegna delle email.
 *
 * Problema: il dominio pubblica una politica DMARC "quarantine". Un'email passa
 * il controllo DMARC solo se SPF o DKIM risultano validi E ALLINEATI al dominio
 * del mittente visibile (From). Con l'invio predefinito di WordPress (funzione
 * mail() di PHP) il mittente visibile è "wordpress@dominio", spesso inesistente,
 * e il mittente tecnico (Return-Path) è quello del server: l'allineamento non è
 * garantito e il messaggio può finire nello spam.
 *
 * Interventi:
 * 1. mittente visibile = indirizzo reale configurato (predefinito: email dell'amministratore);
 * 2. mittente tecnico (Return-Path) = stesso indirizzo, così SPF è allineato;
 * 3. invio facoltativo tramite SMTP autenticato della casella (consigliato):
 *    la posta parte dai server autorizzati dal record SPF del dominio.
 */

function apac_mail_defaults() {
	return array(
		'from_email' => (string) get_option( 'admin_email' ),
		'from_name'  => (string) get_bloginfo( 'name' ),
		'smtp_on'    => 0,
		// Parametri pubblicati da Register.it (assistenza "Parametri di configurazione caselle email").
		'smtp_host'  => 'authsmtp.securemail.pro',
		'smtp_port'  => 465,
		'smtp_sec'   => 'ssl',
		'smtp_user'  => (string) get_option( 'admin_email' ),
	);
}

function apac_mail_settings() {
	$saved = get_option( 'apac_mail', array() );
	return array_merge( apac_mail_defaults(), is_array( $saved ) ? $saved : array() );
}

/* ----------------------- Cifratura della password ----------------------- */

/** Chiave derivata dalle chiavi segrete di wp-config.php (mai salvata nel database). */
function apac_secret_key() {
	$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' ) . 'apac-smtp';
	return hash( 'sha256', $material, true );
}

function apac_encrypt( $plain ) {
	if ( function_exists( 'sodium_crypto_secretbox' ) ) {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 's1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, apac_secret_key() ) );
	}
	if ( function_exists( 'openssl_encrypt' ) ) {
		$iv  = random_bytes( 16 );
		$ct  = openssl_encrypt( $plain, 'aes-256-cbc', apac_secret_key(), OPENSSL_RAW_DATA, $iv );
		$mac = hash_hmac( 'sha256', $iv . $ct, apac_secret_key(), true );
		return 'o1:' . base64_encode( $iv . $mac . $ct );
	}
	return '';
}

function apac_decrypt( $stored ) {
	$stored = (string) $stored;
	if ( 0 === strpos( $stored, 's1:' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
		$raw = base64_decode( substr( $stored, 3 ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$out = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), apac_secret_key() );
		return false === $out ? '' : $out;
	}
	if ( 0 === strpos( $stored, 'o1:' ) && function_exists( 'openssl_decrypt' ) ) {
		$raw = base64_decode( substr( $stored, 3 ), true );
		if ( false === $raw || strlen( $raw ) < 49 ) {
			return '';
		}
		$iv  = substr( $raw, 0, 16 );
		$mac = substr( $raw, 16, 32 );
		$ct  = substr( $raw, 48 );
		if ( ! hash_equals( $mac, hash_hmac( 'sha256', $iv . $ct, apac_secret_key(), true ) ) ) {
			return '';
		}
		$out = openssl_decrypt( $ct, 'aes-256-cbc', apac_secret_key(), OPENSSL_RAW_DATA, $iv );
		return false === $out ? '' : $out;
	}
	return '';
}

/** Password SMTP: costante in wp-config.php (se definita) oppure valore cifrato. */
function apac_smtp_password() {
	if ( defined( 'APAC_SMTP_PASS' ) && '' !== APAC_SMTP_PASS ) {
		return (string) APAC_SMTP_PASS;
	}
	return apac_decrypt( get_option( 'apac_smtp_pass', '' ) );
}

/** L'invio SMTP è attivo e configurato in modo completo? */
function apac_smtp_ready() {
	$s = apac_mail_settings();
	return ! empty( $s['smtp_on'] ) && '' !== $s['smtp_host'] && (int) $s['smtp_port'] > 0 && '' !== $s['smtp_user'] && '' !== apac_smtp_password();
}

/* ------------------------- Mittente e trasporto ------------------------- */

add_filter( 'wp_mail_from', 'apac_filter_mail_from', 20 );
function apac_filter_mail_from( $from ) {
	$s = apac_mail_settings();
	if ( ! is_email( $s['from_email'] ) ) {
		return $from;
	}
	// Con SMTP il mittente deve coincidere con la casella autenticata; senza SMTP
	// si sostituisce solo l'indirizzo predefinito "wordpress@", lasciando invariati
	// i mittenti scelti da altri plugin.
	if ( apac_smtp_ready() || 0 === strpos( strtolower( (string) $from ), 'wordpress@' ) ) {
		return $s['from_email'];
	}
	return $from;
}

add_filter( 'wp_mail_from_name', 'apac_filter_mail_from_name', 20 );
function apac_filter_mail_from_name( $name ) {
	$s = apac_mail_settings();
	if ( ( 'WordPress' === $name || apac_smtp_ready() ) && '' !== trim( $s['from_name'] ) ) {
		return $s['from_name'];
	}
	return $name;
}

add_action( 'phpmailer_init', 'apac_phpmailer_init', 20 );
function apac_phpmailer_init( $phpmailer ) {
	$s = apac_mail_settings();
	// Senza SMTP il trasporto resta quello predefinito del server (nessun rischio di
	// bloccare invii che oggi funzionano); l'allineamento completo si ottiene con l'SMTP.
	if ( ! apac_smtp_ready() ) {
		return;
	}
	// Se WP Mail SMTP usa già un servizio diverso dalla funzione mail() del server, comanda lui.
	$wpms = get_option( 'wp_mail_smtp', array() );
	if ( is_array( $wpms ) && ! empty( $wpms['mail']['mailer'] ) && 'mail' !== $wpms['mail']['mailer'] ) {
		return;
	}
	$phpmailer->isSMTP();
	$phpmailer->Host       = $s['smtp_host'];
	$phpmailer->Port       = (int) $s['smtp_port'];
	$phpmailer->SMTPAuth   = true;
	$phpmailer->Username   = $s['smtp_user'];
	$phpmailer->Password   = apac_smtp_password();
	$phpmailer->SMTPSecure = in_array( $s['smtp_sec'], array( 'ssl', 'tls' ), true ) ? $s['smtp_sec'] : '';
	$phpmailer->SMTPAutoTLS = ( 'tls' === $s['smtp_sec'] );
	$phpmailer->Timeout    = 20;
	// Verifica del certificato del server di posta sempre attiva.
	$phpmailer->SMTPOptions = array(
		'ssl' => array(
			'verify_peer'       => true,
			'verify_peer_name'  => true,
			'allow_self_signed' => false,
		),
	);
	$phpmailer->Sender = $phpmailer->From;
}

/** Intestazioni per gli avvisi automatici (RFC 3834): evitano risposte automatiche in loop. */
function apac_auto_headers() {
	$s = apac_mail_settings();
	$h = array( 'Auto-Submitted: auto-generated', 'X-Auto-Response-Suppress: All' );
	if ( is_email( $s['from_email'] ) ) {
		$h[] = 'Reply-To: ' . $s['from_email'];
	}
	return $h;
}

/* ------------------------- Impostazioni e prova ------------------------- */

add_action( 'admin_post_apac_save_mail', 'apac_admin_save_mail' );
function apac_admin_save_mail() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'apac_save_mail' ) ) {
		wp_die( 'Non consentito.' );
	}
	$p    = wp_unslash( $_POST );
	$errs = array();
	$from = sanitize_email( isset( $p['from_email'] ) ? $p['from_email'] : '' );
	if ( ! is_email( $from ) ) {
		$errs[] = 'Indirizzo mittente non valido.';
	}
	$port = isset( $p['smtp_port'] ) ? absint( $p['smtp_port'] ) : 465;
	$sec  = isset( $p['smtp_sec'] ) && in_array( $p['smtp_sec'], array( 'ssl', 'tls', '' ), true ) ? $p['smtp_sec'] : 'ssl';
	$host = isset( $p['smtp_host'] ) ? preg_replace( '/[^A-Za-z0-9.\-]/', '', (string) $p['smtp_host'] ) : '';
	$user = sanitize_email( isset( $p['smtp_user'] ) ? $p['smtp_user'] : '' );
	$on   = ! empty( $p['smtp_on'] ) ? 1 : 0;
	if ( $on && ( '' === $host || $port < 1 || $port > 65535 || ! is_email( $user ) ) ) {
		$errs[] = 'Per attivare l’SMTP servono server, porta e nome utente (indirizzo email completo).';
	}
	if ( $on && strtolower( $user ) !== strtolower( $from ) ) {
		$errs[] = 'Con l’SMTP attivo il mittente deve coincidere con la casella usata per l’accesso SMTP.';
	}
	$new_pass = isset( $p['apac_smtp_pass'] ) ? (string) $p['apac_smtp_pass'] : '';
	if ( '' !== $new_pass ) {
		$enc = apac_encrypt( $new_pass );
		if ( '' === $enc ) {
			$errs[] = 'Il server non dispone delle funzioni di cifratura (sodium/openssl): la password non è stata salvata. Puoi definire APAC_SMTP_PASS in wp-config.php.';
		} else {
			update_option( 'apac_smtp_pass', $enc, false );
		}
	} elseif ( ! empty( $p['cancella_pass'] ) ) {
		delete_option( 'apac_smtp_pass' );
	}
	if ( $on && '' === $new_pass && '' === apac_smtp_password() ) {
		$errs[] = 'Inserisci la password della casella per attivare l’SMTP.';
	}
	$settings = array(
		'from_email' => is_email( $from ) ? $from : apac_mail_settings()['from_email'],
		'from_name'  => mb_substr( sanitize_text_field( isset( $p['from_name'] ) ? $p['from_name'] : '' ), 0, 100 ),
		'smtp_on'    => $errs ? 0 : $on,
		'smtp_host'  => $host,
		'smtp_port'  => $port ? $port : 465,
		'smtp_sec'   => $sec,
		'smtp_user'  => $user,
	);
	update_option( 'apac_mail', $settings, false );
	set_transient( 'apac_mail_notice', $errs ? array( 'error', implode( ' ', $errs ) . ( $on ? ' L’SMTP resta disattivato.' : '' ) ) : array( 'success', 'Impostazioni email salvate.' ), 120 );
	wp_safe_redirect( admin_url( 'options-general.php?page=apac-settings#apac-email' ) );
	exit;
}

add_action( 'admin_post_apac_test_mail', 'apac_admin_test_mail' );
function apac_admin_test_mail() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'apac_test_mail' ) ) {
		wp_die( 'Non consentito.' );
	}
	$to = (string) get_option( 'admin_email' );
	$ok = apac_send_mail( $to, '[Area clienti] Email di prova', "Questa è un'email di prova inviata dall'area clienti.\n\nSe la ricevi nella posta in arrivo (non nello spam), la configurazione funziona.\n\n" . home_url( '/' ), apac_auto_headers() );
	$err = isset( $GLOBALS['apac_mail_error'] ) ? (string) $GLOBALS['apac_mail_error'] : '';
	set_transient(
		'apac_mail_notice',
		$ok ? array( 'success', 'Email di prova consegnata al server di posta per ' . $to . ' (' . ( apac_smtp_ready() ? 'SMTP autenticato' : 'invio standard del server' ) . '). Controlla che arrivi nella posta in arrivo e non nello spam.' )
			: array( 'error', 'Invio NON riuscito: ' . ( '' !== $err ? $err : 'errore sconosciuto' ) ),
		120
	);
	wp_safe_redirect( admin_url( 'options-general.php?page=apac-settings#apac-email' ) );
	exit;
}

/** Sezione della pagina impostazioni. */
function apac_render_mail_settings() {
	$s      = apac_mail_settings();
	$notice = get_transient( 'apac_mail_notice' );
	delete_transient( 'apac_mail_notice' );
	$has_pass = '' !== apac_smtp_password();
	?>
	<h2 id="apac-email">Invio delle email</h2>
	<?php if ( is_array( $notice ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> inline"><p><?php echo esc_html( $notice[1] ); ?></p></div>
	<?php endif; ?>
	<?php if ( defined( 'WPMS_PLUGIN_VER' ) || class_exists( 'WPMailSMTP\Core' ) ) : ?>
		<div class="notice notice-info inline"><p><strong>WP Mail SMTP è attivo sul sito.</strong> Configura l’SMTP in un solo posto: consigliato in WP Mail SMTP (mailer “Altro SMTP”, server authsmtp.securemail.pro, porta 465, SSL, autenticazione con la casella info@) lasciando <em>disattivato</em> l’SMTP qui sotto. Con l’SMTP attivo in WP Mail SMTP, questo plugin non modifica il trasporto.</p></div>
	<?php endif; ?>
	<?php
	$wpms     = defined( 'WPMS_PLUGIN_VER' ) || class_exists( 'WPMailSMTP\Core' );
	$wpms_smtp = $wpms && 'mail' !== (string) ( get_option( 'wp_mail_smtp' )['mail']['mailer'] ?? 'mail' );
	if ( apac_smtp_ready() ) {
		$stato = 'SMTP autenticato attivo in questo plugin (' . $s['smtp_host'] . ':' . $s['smtp_port'] . ')';
	} elseif ( $wpms_smtp ) {
		$stato = 'invio gestito da WP Mail SMTP (configurato): l’SMTP qui sotto può restare disattivato';
	} else {
		$stato = 'invio standard del server (consigliato attivare l’SMTP)';
	}
	?>
	<p>Stato: <strong><?php echo esc_html( $stato ); ?></strong></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
		<input type="hidden" name="action" value="apac_save_mail">
		<?php wp_nonce_field( 'apac_save_mail' ); ?>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="apac_from_email">Mittente (indirizzo)</label></th>
				<td><input type="email" class="regular-text" id="apac_from_email" name="from_email" value="<?php echo esc_attr( $s['from_email'] ); ?>" required>
				<p class="description">Deve essere una casella reale del dominio, ad esempio info@apsicurezzalavoro.it.</p></td></tr>
			<tr><th scope="row"><label for="apac_from_name">Mittente (nome)</label></th>
				<td><input type="text" class="regular-text" id="apac_from_name" name="from_name" value="<?php echo esc_attr( $s['from_name'] ); ?>"></td></tr>
			<tr><th scope="row">SMTP autenticato</th>
				<td><label><input type="checkbox" name="smtp_on" value="1"<?php checked( ! empty( $s['smtp_on'] ) ); ?>> Invia tramite la casella di posta (consigliato)</label></td></tr>
			<tr><th scope="row"><label for="apac_smtp_host">Server SMTP</label></th>
				<td><input type="text" class="regular-text" id="apac_smtp_host" name="smtp_host" value="<?php echo esc_attr( $s['smtp_host'] ); ?>">
				<p class="description">Valori pubblicati da Register.it: server authsmtp.securemail.pro, porta 465, SSL. Se hai acquistato gli “invii aggiuntivi SMTP” usa i parametri indicati nel loro modulo.</p></td></tr>
			<tr><th scope="row"><label for="apac_smtp_port">Porta</label></th>
				<td><input type="number" min="1" max="65535" id="apac_smtp_port" name="smtp_port" value="<?php echo (int) $s['smtp_port']; ?>" class="small-text">
				<select name="smtp_sec">
					<option value="ssl"<?php selected( $s['smtp_sec'], 'ssl' ); ?>>SSL</option>
					<option value="tls"<?php selected( $s['smtp_sec'], 'tls' ); ?>>STARTTLS</option>
					<option value=""<?php selected( $s['smtp_sec'], '' ); ?>>Nessuna (sconsigliato)</option>
				</select></td></tr>
			<tr><th scope="row"><label for="apac_smtp_user">Nome utente</label></th>
				<td><input type="email" class="regular-text" id="apac_smtp_user" name="smtp_user" value="<?php echo esc_attr( $s['smtp_user'] ); ?>">
				<p class="description">L’indirizzo email completo della casella.</p></td></tr>
			<tr><th scope="row"><label for="apac_smtp_pass">Password</label></th>
				<td><input type="password" class="regular-text" id="apac_smtp_pass" name="apac_smtp_pass" value="" autocomplete="new-password">
				<p class="description"><?php echo defined( 'APAC_SMTP_PASS' ) ? 'Password definita in wp-config.php (APAC_SMTP_PASS).' : ( $has_pass ? 'Password salvata (cifrata). Lascia vuoto per non cambiarla.' : 'Nessuna password salvata.' ); ?> Viene conservata cifrata con le chiavi di wp-config.php e non viene mai mostrata.</p>
				<?php if ( $has_pass && ! defined( 'APAC_SMTP_PASS' ) ) : ?><label><input type="checkbox" name="cancella_pass" value="1"> Elimina la password salvata</label><?php endif; ?></td></tr>
		</table>
		<?php submit_button( 'Salva impostazioni email', 'primary', 'submit', false ); ?>
	</form>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
		<input type="hidden" name="action" value="apac_test_mail">
		<?php wp_nonce_field( 'apac_test_mail' ); ?>
		<?php submit_button( 'Invia un’email di prova a ' . get_option( 'admin_email' ), 'secondary', 'submit', false ); ?>
	</form>
	<?php
}
