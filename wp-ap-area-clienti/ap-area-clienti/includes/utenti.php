<?php
/**
 * Utenti dei clienti: livello di accesso (modifica / sola consultazione) ed email di benvenuto e di
 * nuova password.
 *
 * 1.9.15: file per area; il codice proviene dai file per versione (session1-3.php, v190.php), invariato.
 */
defined( 'ABSPATH' ) || exit;

function apac_is_client_user( $user ) {
	return $user instanceof WP_User && in_array( APAC_ROLE, (array) $user->roles, true );
}

/** Estrae il link per impostare la password dal testo standard di WordPress. */
function apac_extract_rp_link( $message ) {
	if ( preg_match( '#https?://[^\s<>"]*action=rp[^\s<>"]*#', (string) $message, $m ) ) {
		return html_entity_decode( $m[0], ENT_QUOTES, 'UTF-8' );
	}
	return '';
}

/** Impaginazione HTML delle email (compatibile con i principali programmi di posta). */
function apac_email_html( $title, $intro_html, $button_label, $button_url, $after_html ) {
	$portal = apac_portal_page_url();
	$qr     = APAC_URL . 'assets/qr-area-riservata.png';
	$g      = '#193E0C';
	$h      = '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $title ) . '</title></head>';
	$h     .= '<body style="margin:0;padding:0;background:#f2f4f1;font-family:Arial,Helvetica,sans-serif;color:#202020">';
	$h     .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f1;padding:24px 12px"><tr><td align="center">';
	$h     .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e3e3e3">';
	$h     .= '<tr><td style="background:#061C03;padding:22px 28px;border-bottom:4px solid #FDE117"><div style="color:#FDE117;font-size:12px;font-weight:bold;letter-spacing:2px;text-transform:uppercase">Area riservata</div><div style="color:#ffffff;font-size:22px;font-weight:bold;margin-top:4px">AP Sicurezza Lavoro</div></td></tr>';
	$h     .= '<tr><td style="padding:28px 28px 8px;font-size:16px;line-height:1.6">' . $intro_html . '</td></tr>';
	if ( $button_url ) {
		$h .= '<tr><td style="padding:8px 28px 8px" align="left"><a href="' . esc_url( $button_url ) . '" style="display:inline-block;background:' . $g . ';color:#ffffff;text-decoration:none;font-weight:bold;font-size:16px;padding:14px 24px;border-radius:8px">' . esc_html( $button_label ) . '</a>';
		$h .= '<p style="font-size:13px;color:#4A4A4A;margin:12px 0 0">Se il pulsante non funziona, copia questo indirizzo nel browser:<br><a href="' . esc_url( $button_url ) . '" style="color:' . $g . ';word-break:break-all">' . esc_html( $button_url ) . '</a></p></td></tr>';
	}
	$h .= '<tr><td style="padding:16px 28px 8px;font-size:15px;line-height:1.6">' . $after_html . '</td></tr>';
	$h .= '<tr><td style="padding:8px 28px 24px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F5F5F5;border-radius:12px"><tr>';
	$h .= '<td style="padding:16px;vertical-align:top;font-size:14px;line-height:1.55"><strong style="color:' . $g . ';font-size:15px">Installa l’app sul telefono</strong><br>';
	$h .= '<b>Android:</b> apri l’area riservata, tocca il menu ⋮ e poi «Installa app».<br>';
	$h .= '<b>iPhone:</b> apri l’area riservata in <b>Safari</b>, tocca Condividi e poi «Aggiungi alla schermata Home».<br>';
	$h .= '<a href="' . esc_url( $portal ) . '" style="color:' . $g . ';font-weight:bold">' . esc_html( $portal ) . '</a></td>';
	$h .= '<td width="130" style="padding:12px;vertical-align:middle" align="center"><a href="' . esc_url( $portal ) . '"><img src="' . esc_url( $qr ) . '" width="118" height="118" alt="QR area riservata" style="display:block;border:0"></a></td>';
	$h .= '</tr></table></td></tr>';
	$h .= '<tr><td style="padding:16px 28px 24px;border-top:1px solid #e3e3e3;font-size:12px;color:#6b6b6b;line-height:1.5">Email automatica inviata da AP Sicurezza Lavoro. Se non ti aspettavi questo messaggio, ignoralo oppure rispondi per avvisarci.</td></tr>';
	$h .= '</table></td></tr></table></body></html>';
	return $h;
}

/* Benvenuto: account creato dall'area riservata (solo per i clienti). */
add_filter( 'wp_new_user_notification_email', 'apac_welcome_email', 10, 3 );

function apac_welcome_email( $email, $user, $blogname ) {
	unset( $blogname );
	if ( ! apac_is_client_user( $user ) ) {
		return $email;
	}
	$link = apac_extract_rp_link( isset( $email['message'] ) ? $email['message'] : '' );
	if ( '' === $link ) {
		return $email; // Formato inatteso: resta l'email standard di WordPress.
	}
	$az    = apac_get_azienda( (int) get_user_meta( $user->ID, 'apac_azienda_id', true ) );
	$nome  = '' !== $user->first_name ? $user->first_name : $user->display_name;
	$intro = '<p style="margin:0 0 14px">Ciao <strong>' . esc_html( $nome ) . '</strong>,</p>';
	$intro .= '<p style="margin:0 0 14px">lo studio <strong>AP Sicurezza Lavoro</strong> ha attivato il tuo accesso all’area riservata' . ( $az ? ' di <strong>' . esc_html( $az->ragione_sociale ) . '</strong>' : '' ) . ': qui trovi documenti, scadenze e formazione della tua azienda.</p>';
	$intro .= '<p style="margin:0 0 6px"><strong>1.</strong> Per prima cosa scegli la tua password:</p>';
	$after  = '<p style="margin:0 0 10px">Il link per la password vale per un tempo limitato: se è scaduto, apri l’area riservata e tocca «Password dimenticata?».</p>';
	$after .= '<p style="margin:0"><strong>2.</strong> Poi accedi con questa email (<strong>' . esc_html( $user->user_email ) . '</strong>) e la password scelta.</p>';
	$email['subject'] = 'Il tuo accesso all’area riservata di AP Sicurezza Lavoro';
	$email['message'] = apac_email_html( $email['subject'], $intro, 'Scegli la password', $link, $after );
	$email['headers'] = apac_merge_html_headers( isset( $email['headers'] ) ? $email['headers'] : '' );
	return $email;
}

/* Nuova password richiesta (dallo studio o con "Password dimenticata?"), solo per i clienti. */
add_filter( 'retrieve_password_notification_email', 'apac_reset_email', 10, 4 );

function apac_reset_email( $defaults, $key, $user_login, $user_data ) {
	unset( $key, $user_login );
	if ( ! apac_is_client_user( $user_data ) ) {
		return $defaults;
	}
	$link = apac_extract_rp_link( isset( $defaults['message'] ) ? $defaults['message'] : '' );
	if ( '' === $link ) {
		return $defaults;
	}
	$nome  = '' !== $user_data->first_name ? $user_data->first_name : $user_data->display_name;
	$intro = '<p style="margin:0 0 14px">Ciao <strong>' . esc_html( $nome ) . '</strong>,</p><p style="margin:0 0 6px">è stata richiesta una nuova password per il tuo accesso all’area riservata. Per sceglierla tocca il pulsante:</p>';
	$after = '<p style="margin:0">Se non hai chiesto tu il cambio, ignora questa email: la password attuale resta valida.</p>';
	$defaults['subject'] = 'Nuova password per l’area riservata di AP Sicurezza Lavoro';
	$defaults['message'] = apac_email_html( $defaults['subject'], $intro, 'Scegli la nuova password', $link, $after );
	$defaults['headers'] = apac_merge_html_headers( isset( $defaults['headers'] ) ? $defaults['headers'] : '' );
	return $defaults;
}

function apac_merge_html_headers( $headers ) {
	$list = is_array( $headers ) ? $headers : ( '' !== (string) $headers ? explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) ) : array() );
	$list = array_values(
		array_filter(
			$list,
			function ( $h ) {
				return '' !== trim( $h ) && 0 !== stripos( trim( $h ), 'content-type:' );
			}
		)
	);
	$list[] = 'Content-Type: text/html; charset=UTF-8';
	return $list;
}

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
