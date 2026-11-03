<?php
defined( 'ABSPATH' ) || exit;

/* ---------- I clienti non entrano nella bacheca di WordPress ---------- */

add_action( 'admin_init', 'apac_block_wp_admin_for_clients' );
function apac_block_wp_admin_for_clients() {
	if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}
	global $pagenow;
	if ( 'admin-post.php' === $pagenow ) {
		return;
	}
	$user = wp_get_current_user();
	if ( $user && in_array( APAC_ROLE, (array) $user->roles, true ) && ! user_can( $user, 'edit_posts' ) ) {
		wp_safe_redirect( apac_portal_page_url() );
		exit;
	}
}

add_filter( 'show_admin_bar', 'apac_hide_admin_bar' );
function apac_hide_admin_bar( $show ) {
	$user = wp_get_current_user();
	if ( $user && in_array( APAC_ROLE, (array) $user->roles, true ) ) {
		return false;
	}
	return $show;
}

add_filter( 'login_redirect', 'apac_login_redirect', 10, 3 );
function apac_login_redirect( $redirect_to, $requested, $user ) {
	if ( $user instanceof WP_User && in_array( APAC_ROLE, (array) $user->roles, true ) ) {
		return apac_portal_page_url();
	}
	return $redirect_to;
}

/* ------------------------ Pagina di impostazioni ----------------------- */

add_action( 'admin_menu', 'apac_admin_menu' );
function apac_admin_menu() {
	add_options_page( 'Area Clienti', 'Area Clienti', 'manage_options', 'apac-settings', 'apac_settings_page' );
}

add_action( 'admin_post_apac_save_button', 'apac_admin_save_button' );
function apac_admin_save_button() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'apac_save_button' ) ) {
		wp_die( 'Non consentito.' );
	}
	$menu_id = isset( $_POST['apac_menu_id'] ) ? absint( $_POST['apac_menu_id'] ) : 0;
	if ( $menu_id && ! wp_get_nav_menu_object( $menu_id ) ) {
		$menu_id = 0;
	}
	update_option( 'apac_menu_id', $menu_id );
	update_option( 'apac_button_position', ( isset( $_POST['apac_button_position'] ) && 'menu' === $_POST['apac_button_position'] ) ? 'menu' : 'auto' );
	update_option( 'apac_button_label', isset( $_POST['apac_button_label'] ) ? sanitize_text_field( wp_unslash( $_POST['apac_button_label'] ) ) : 'Area riservata' );
	wp_safe_redirect( admin_url( 'options-general.php?page=apac-settings&saved=1' ) );
	exit;
}

add_action( 'admin_post_apac_sync_style', 'apac_admin_sync_style' );
function apac_admin_sync_style() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'apac_sync_style' ) ) {
		wp_die( 'Non consentito.' );
	}
	$ok = apac_sync_site_css();
	wp_safe_redirect( admin_url( 'options-general.php?page=apac-settings&style=' . ( $ok ? '1' : '0' ) ) );
	exit;
}

add_action( 'admin_post_apac_protezione_manuale', 'apac_admin_protezione_manuale' );
function apac_admin_protezione_manuale() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'apac_protezione_manuale' ) ) {
		wp_die( 'Non consentito.' );
	}
	update_option( 'apac_protezione_manuale', ! empty( $_POST['valore'] ) ? 1 : 0, false );
	wp_safe_redirect( admin_url( 'options-general.php?page=apac-settings' ) );
	exit;
}

add_action( 'admin_post_apac_clear_mail_error', 'apac_admin_clear_mail_error' );
function apac_admin_clear_mail_error() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'apac_clear_mail_error' ) ) {
		wp_die( 'Non consentito.' );
	}
	delete_option( 'apac_mail_error' );
	wp_safe_redirect( admin_url( 'options-general.php?page=apac-settings' ) );
	exit;
}

add_action( 'admin_post_apac_run_cron', 'apac_admin_run_cron' );
function apac_admin_run_cron() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'apac_run_cron' ) ) {
		wp_die( 'Non consentito.' );
	}
	$r = apac_invia_avvisi_scadenze();
	wp_safe_redirect( admin_url( 'options-general.php?page=apac-settings&cron=' . ( ! empty( $r['locked'] ) ? 'busy' : '1' ) ) );
	exit;
}

add_action( 'admin_post_apac_recreate_dir', 'apac_admin_recreate_dir' );
function apac_admin_recreate_dir() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'apac_recreate_dir' ) ) {
		wp_die( 'Non consentito.' );
	}
	$r = apac_ensure_private_dir( true );
	apac_check_private_dir_protection();
	wp_safe_redirect( admin_url( 'options-general.php?page=apac-settings&dir=' . ( is_wp_error( $r ) ? '0' : '1' ) . '#apac-protezione' ) );
	exit;
}

/** Requisiti del server usati dal plugin. */
function apac_requirements() {
	return array(
		array( 'PHP 7.4 o successivo', version_compare( PHP_VERSION, '7.4', '>=' ), 'Versione attuale: ' . PHP_VERSION ),
		array( 'Estensione mbstring (testi con accenti)', function_exists( 'mb_substr' ), '' ),
		array( 'Estensione fileinfo (riconoscimento del tipo di file caricato)', function_exists( 'finfo_open' ), 'Senza fileinfo il controllo del tipo di file si basa solo sull’estensione.' ),
		array( 'Cifratura sodium o openssl (password SMTP)', function_exists( 'sodium_crypto_secretbox' ) || function_exists( 'openssl_encrypt' ), '' ),
		array( 'openssl (connessioni SSL/TLS al server di posta)', extension_loaded( 'openssl' ), '' ),
	);
}

function apac_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$protezione = apac_check_private_dir_protection();
	$next       = wp_next_scheduled( 'apac_cron_scadenze' );
	?>
	<div class="wrap">
		<h1>AP Area Clienti</h1>
		<?php if ( isset( $_GET['cron'] ) && 'busy' === $_GET['cron'] ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
			<div class="notice notice-warning"><p>Un controllo delle scadenze è già in corso: per evitare invii doppi non ne è stato avviato un altro. Riprova tra qualche minuto.</p></div>
		<?php elseif ( isset( $_GET['cron'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
			<div class="notice notice-success"><p>Controllo scadenze eseguito: vedi il riepilogo in fondo alla pagina.</p></div>
		<?php endif; ?>

		<h2>Pagina dell’area clienti</h2>
		<p><a class="button button-primary" href="<?php echo esc_url( apac_portal_page_url() ); ?>" target="_blank" rel="noopener">Apri l’area clienti</a></p>
		<p>Indirizzo: <code><?php echo esc_html( apac_portal_page_url() ); ?></code></p>

		<h2>Pulsante “Area riservata” nel menu del sito</h2>
		<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
			<div class="notice notice-success inline"><p>Impostazioni del pulsante salvate.</p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="apac_save_button">
			<?php wp_nonce_field( 'apac_save_button' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="apac_menu_id">Menu</label></th>
					<td>
						<select name="apac_menu_id" id="apac_menu_id">
							<option value="0"<?php selected( apac_button_menu_id(), 0 ); ?>>— Nessuno (pulsante disattivato ovunque) —</option>
							<?php foreach ( wp_get_nav_menus() as $m ) : ?>
								<option value="<?php echo (int) $m->term_id; ?>"<?php selected( apac_button_menu_id(), (int) $m->term_id ); ?>><?php echo esc_html( $m->name ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">Il pulsante viene aggiunto in fondo a questo menu (e, con la posizione consigliata, nella barra superiore). Con “Nessuno” il pulsante non compare né nel menu né nella barra superiore. In alternativa puoi usare lo shortcode <code>[ap_area_riservata]</code> in qualsiasi pagina.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="apac_button_position">Posizione</label></th>
					<td>
						<select name="apac_button_position" id="apac_button_position">
							<option value="auto"<?php selected( apac_button_position(), 'auto' ); ?>>Barra superiore verde (consigliata) + menu del telefono</option>
							<option value="menu"<?php selected( apac_button_position(), 'menu' ); ?>>Solo nel menu</option>
						</select>
						<p class="description">Nel menu del computer lo spazio è limitato: con la barra superiore il pulsante non si sovrappone a “Richiedi un preventivo”.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="apac_button_label">Testo del pulsante</label></th>
					<td><input type="text" class="regular-text" name="apac_button_label" id="apac_button_label" value="<?php echo esc_attr( apac_button_label() ); ?>"></td>
				</tr>
			</table>
			<?php submit_button( 'Salva pulsante', 'primary', 'submit', false ); ?>
		</form>

		<h2>Aspetto grafico</h2>
		<?php $info = get_option( 'apac_site_css_info', array() ); ?>
		<?php if ( isset( $_GET['style'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
			<div class="notice notice-<?php echo '1' === $_GET['style'] ? 'success' : 'warning'; // phpcs:ignore ?> inline"><p><?php echo '1' === $_GET['style'] ? 'Stile riallineato al sito.' : 'Nessuna pagina del sito contiene il foglio di stile “aps”: l’area riservata usa i colori predefiniti del sito.'; // phpcs:ignore ?></p></div>
		<?php endif; ?>
		<p>L’area riservata usa lo stesso stile delle pagine del sito (colori, testate, riquadri, pulsanti).
		<?php if ( ! empty( $info['page'] ) ) : ?>
			Stile copiato dalla pagina <strong><?php echo esc_html( $info['title'] ); ?></strong> il <?php echo esc_html( mysql2date( 'd/m/Y H:i', $info['date'] ) ); ?>.
		<?php else : ?>
			<strong>Stile del sito non ancora trovato: in uso i colori predefiniti.</strong>
		<?php endif; ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="apac_sync_style">
			<?php wp_nonce_field( 'apac_sync_style' ); ?>
			<?php submit_button( 'Riallinea lo stile al sito', 'secondary', 'submit', false ); ?>
		</form>
		<p class="description">Da usare se modifichi l’aspetto delle pagine del sito.</p>

		<h2 id="apac-protezione">Protezione dei documenti</h2>
		<?php $prot = get_option( 'apac_protezione', array() ); ?>
		<?php if ( isset( $_GET['dir'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
			<div class="notice notice-<?php echo '1' === $_GET['dir'] ? 'success' : 'error'; // phpcs:ignore ?> inline"><p><?php echo '1' === $_GET['dir'] ? 'Cartella dei documenti pronta.' : 'Impossibile creare la cartella: verifica i permessi con il fornitore di hosting.'; // phpcs:ignore ?></p></div>
		<?php endif; ?>
		<?php if ( in_array( $protezione, array( 'cartella_mancante', 'non_scrivibile' ), true ) ) : ?>
			<div class="notice notice-error inline"><p><strong>ATTENZIONE:</strong> <?php echo 'cartella_mancante' === $protezione ? 'la cartella dei documenti non esiste più sul server' : 'la cartella dei documenti non è scrivibile'; ?> (<code><?php echo esc_html( apac_private_dir() ); ?></code>). <strong>Caricamenti bloccati.</strong> I documenti già registrati potrebbero non essere disponibili: verifica con il fornitore di hosting o ripristina la cartella da un backup.</p>
			<?php if ( 'cartella_mancante' === $protezione ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="apac_recreate_dir"><?php wp_nonce_field( 'apac_recreate_dir' ); ?><p><button class="button" onclick="return confirm('Creare una cartella VUOTA nello stesso percorso? I documenti precedenti non verranno recuperati: per quelli serve il backup.');">Crea di nuovo la cartella (vuota)</button></p></form>
			<?php endif; ?></div>
		<?php elseif ( 'fuori_webroot' === $protezione ) : ?>
			<div class="notice notice-success inline"><p><strong>OK:</strong> i documenti sono salvati fuori dalla cartella pubblica del sito, quindi non sono raggiungibili con un link diretto.<br><small>Percorso: <code><?php echo esc_html( apac_private_dir() ); ?></code></small></p></div>
		<?php elseif ( 'protetta' === $protezione ) : ?>
			<div class="notice notice-success inline"><p><strong>OK:</strong> il server rifiuta l’accesso diretto ai documenti (risposta HTTP <?php echo (int) ( $prot['http'] ?? 0 ); ?>). Il controllo viene ripetuto automaticamente almeno ogni 12 ore prima dei caricamenti.</p></div>
		<?php elseif ( 'esposta' === $protezione ) : ?>
			<div class="notice notice-error inline"><p><strong>ATTENZIONE:</strong> il file di prova è scaricabile con un link diretto. <strong>I caricamenti sono bloccati</strong> finché il fornitore di hosting non blocca l’accesso a <code><?php echo esc_html( apac_private_url() ); ?>/</code>.</p></div>
		<?php else : ?>
			<div class="notice notice-warning inline"><p><strong>Protezione non dimostrata</strong> (risposta HTTP <?php echo (int) ( $prot['http'] ?? 0 ); ?><?php echo ! empty( $prot['nota'] ) ? ' – ' . esc_html( $prot['nota'] ) : ''; ?>): il controllo automatico non ha ricevuto un rifiuto esplicito. <strong>I caricamenti sono bloccati.</strong><br>
			Verifica manuale: apri questo indirizzo da una finestra anonima; deve comparire un errore 403 o 404, NON il testo “APAC-PROTEZIONE-TEST”:<br><code><?php echo esc_html( apac_private_url() . '/apac-test.txt' ); ?></code></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="apac_protezione_manuale">
				<?php wp_nonce_field( 'apac_protezione_manuale' ); ?>
				<?php if ( get_option( 'apac_protezione_manuale' ) ) : ?>
					<p>Conferma manuale registrata: i caricamenti sono consentiti. <button class="button" name="valore" value="0">Revoca conferma</button></p>
				<?php else : ?>
					<p><button class="button" name="valore" value="1" onclick="return confirm('Confermi di aver verificato da una finestra anonima che l’indirizzo restituisce un errore e non il testo di prova?');">Ho verificato manualmente: consenti i caricamenti</button></p>
				<?php endif; ?>
			</form></div>
		<?php endif; ?>

		<h2>Integrità dei dati</h2>
		<?php $txs = apac_tx_support(); ?>
		<?php if ( 'si' === $txs ) : ?>
			<div class="notice notice-success inline"><p><strong>OK:</strong> le tabelle usano InnoDB: un’operazione interrotta a metà viene annullata per intero.</p></div>
		<?php elseif ( 'no' === $txs ) : ?>
			<div class="notice notice-error inline"><p><strong>ATTENZIONE:</strong> alcune tabelle non supportano le transazioni. Disattiva e riattiva il plugin per convertirle; se il problema resta, contatta il fornitore di hosting.</p></div>
		<?php else : ?>
			<div class="notice notice-info inline"><p>Tipo di database non determinabile automaticamente.</p></div>
		<?php endif; ?>

		<?php $mig = get_option( 'apac_migration_error' ); ?>
		<?php if ( is_array( $mig ) ) : ?>
			<div class="notice notice-error inline"><p><strong>Aggiornamento del database non riuscito</strong> il <?php echo esc_html( mysql2date( 'd/m/Y H:i', $mig['date'] ) ); ?>: <?php echo esc_html( $mig['missing'] ); ?>. Il plugin riprova automaticamente ogni 10 minuti.</p></div>
		<?php endif; ?>

		<?php apac_render_backup_status(); ?>
		<?php apac_render_seo_settings(); ?>
		<?php apac_render_consensi_settings(); ?>
		<?php apac_render_misura(); ?>

		<h2>Requisiti del server</h2>
		<ul>
		<?php foreach ( apac_requirements() as $req ) : ?>
			<li><?php echo $req[1] ? '✅' : '⚠️'; ?> <?php echo esc_html( $req[0] ); ?><?php echo ( ! $req[1] && $req[2] ) || ( $req[1] && 0 === strpos( $req[2], 'Versione' ) ) ? ' – <small>' . esc_html( $req[2] ) . '</small>' : ''; ?></li>
		<?php endforeach; ?>
		</ul>

		<?php apac_render_mail_settings(); ?>

		<?php $merr = get_option( 'apac_mail_error' ); ?>
		<?php if ( is_array( $merr ) && ! empty( $merr['date'] ) ) : ?>
			<h2>Ultimo errore di invio email</h2>
			<div class="notice notice-warning inline"><p><?php echo esc_html( mysql2date( 'd/m/Y H:i', $merr['date'] ) ); ?> – destinatario: <?php echo esc_html( $merr['to'] ); ?><br><small><?php echo esc_html( $merr['error'] ); ?></small></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="apac_clear_mail_error"><?php wp_nonce_field( 'apac_clear_mail_error' ); ?><p><button class="button">Segna come risolto</button></p></form></div>
		<?php endif; ?>

		<h2>Avvisi scadenze via email</h2>
		<p>Controllo automatico giornaliero alle 07:00 (fuso orario del sito: <strong><?php echo esc_html( wp_timezone_string() ); ?></strong>). Prossima esecuzione: <strong><?php echo $next ? esc_html( wp_date( 'd/m/Y H:i', $next ) ) : 'in pianificazione'; ?></strong>.</p>
		<?php if ( '' === (string) get_option( 'timezone_string' ) ) : ?>
			<div class="notice notice-warning inline"><p>Il fuso orario del sito è impostato come scostamento fisso (<?php echo esc_html( wp_timezone_string() ); ?>): l’ora legale non viene applicata. In <a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>">Impostazioni › Generali</a> scegli la città “Roma”.</p></div>
		<?php endif; ?>
		<p>Gli avvisi partono a 30 giorni, a 7 giorni e alla scadenza, verso gli utenti dell’azienda e in copia all’email dell’amministratore (<?php echo esc_html( get_option( 'admin_email' ) ); ?>). L’esito è registrato per ogni destinatario: chi non riceve l’email viene ritentato ogni giorno, fino a <?php echo (int) APAC_MAX_TENTATIVI; ?> tentativi.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="apac_run_cron">
			<?php wp_nonce_field( 'apac_run_cron' ); ?>
			<?php submit_button( 'Esegui ora il controllo scadenze', 'secondary', 'submit', false ); ?>
		</form>
		<?php $ultimo = get_option( 'apac_ultimo_invio' ); ?>
		<?php if ( is_array( $ultimo ) ) : ?>
			<p>Ultimo controllo: <?php echo esc_html( mysql2date( 'd/m/Y H:i', $ultimo['date'] ) ); ?> – email inviate: <?php echo (int) $ultimo['inviate']; ?>, non riuscite: <strong><?php echo (int) $ultimo['fallite']; ?></strong>. Gli avvisi non consegnati vengono ritentati al controllo successivo.</p>
		<?php endif; ?>
		<?php
		global $wpdb;
		$esauriti = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . apac_table( 'avvisi' ) . " WHERE esito = 'fallito' AND tentativi >= %d", APAC_MAX_TENTATIVI ) );
		?>
		<?php if ( $esauriti ) : ?>
			<div class="notice notice-error inline"><p><?php echo (int) $esauriti; ?> avvisi non sono stati consegnati dopo <?php echo (int) APAC_MAX_TENTATIVI; ?> tentativi: verifica gli indirizzi email dei destinatari e la configurazione di invio.</p></div>
		<?php endif; ?>

		<h2>Cache e CDN</h2>
		<p>Le pagine che contengono l’area riservata inviano intestazioni “no-store” e segnalano ai plugin di cache di non memorizzarle. Se il sito usa una cache del server (es. Varnish dell’hosting) o una CDN, escludi dalla cache:</p>
		<ul style="list-style:disc;margin-left:20px">
			<li>la pagina <code><?php echo esc_html( wp_parse_url( apac_portal_page_url(), PHP_URL_PATH ) ); ?></code> e ogni altra pagina con lo shortcode <code>[ap_area_clienti]</code>;</li>
			<li><code>/wp-admin/admin-post.php</code> e <code>/wp-admin/admin-ajax.php</code> (operazioni, download dei documenti, ricerca aziende);</li>
			<li><code>/wp-login.php</code>;</li>
			<li>tutte le richieste con i cookie <code>wordpress_logged_in_*</code> o <code>wp-postpass_*</code>.</li>
		</ul>
		<p class="description">Nota: il pianificatore di WordPress si attiva quando qualcuno visita il sito. Su siti con poche visite l’invio può slittare di qualche ora.</p>
	</div>
	<?php
}
