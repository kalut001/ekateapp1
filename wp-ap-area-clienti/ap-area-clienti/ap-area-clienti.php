<?php
/**
 * Plugin Name:       AP Area Clienti
 * Description:       Area riservata per le aziende clienti: dashboard, anagrafica aziendale, lavoratori e attestati, archivio documenti protetto, scadenzario con avvisi email, segnalazioni e analisi delle criticità. Inserire lo shortcode [ap_area_clienti] in una pagina.
 * Version:           1.9.13
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            AP Sicurezza Lavoro
 * Text Domain:       ap-area-clienti
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'APAC_VERSION', '1.9.13' );
define( 'APAC_DB_VERSION', '14' );
define( 'APAC_FILE', __FILE__ );
define( 'APAC_DIR', plugin_dir_path( __FILE__ ) );
define( 'APAC_URL', plugin_dir_url( __FILE__ ) );
define( 'APAC_ROLE', 'apac_cliente' );

require_once APAC_DIR . 'includes/db.php';
require_once APAC_DIR . 'includes/helpers.php';
require_once APAC_DIR . 'includes/forms.php';
require_once APAC_DIR . 'includes/mail.php';
require_once APAC_DIR . 'includes/files.php';
require_once APAC_DIR . 'includes/criticita.php';
require_once APAC_DIR . 'includes/actions.php';
require_once APAC_DIR . 'includes/notifiche.php';
require_once APAC_DIR . 'includes/portal.php';
require_once APAC_DIR . 'includes/admin.php';
require_once APAC_DIR . 'includes/menu-button.php';
require_once APAC_DIR . 'includes/site-style.php';
require_once APAC_DIR . 'includes/admin-tools.php';
require_once APAC_DIR . 'includes/search.php';
require_once APAC_DIR . 'includes/pwa.php';
require_once APAC_DIR . 'includes/ui.php';
require_once APAC_DIR . 'includes/session1.php';
require_once APAC_DIR . 'includes/session2.php';
require_once APAC_DIR . 'includes/session3.php';
require_once APAC_DIR . 'includes/seo.php';
require_once APAC_DIR . 'includes/consensi.php';
require_once APAC_DIR . 'includes/v190.php';
require_once APAC_DIR . 'includes/perf.php';
require_once APAC_DIR . 'includes/richieste.php';

register_activation_hook( __FILE__, 'apac_activate' );
register_deactivation_hook( __FILE__, 'apac_deactivate' );

/**
 * Attivazione: tabelle, ruolo, cartella protetta, pagina portale, cron.
 */
function apac_activate() {
	if ( version_compare( PHP_VERSION, '7.4', '<' ) || ! function_exists( 'mb_substr' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die( 'AP Area Clienti richiede PHP 7.4 o successivo con l’estensione mbstring.' );
	}
	apac_install_db();

	add_role(
		APAC_ROLE,
		'Cliente Area Riservata',
		array( 'read' => true )
	);

	apac_choose_private_dir();
	apac_ensure_private_dir( true );

	// Crea la pagina del portale se non esiste.
	$page_id = (int) get_option( 'apac_page_id' );
	if ( ! $page_id || ! get_post( $page_id ) ) {
		$page_id = wp_insert_post(
			array(
				'post_title'   => 'Area riservata',
				'post_name'    => 'area-riservata',
				'post_content' => '<!-- wp:shortcode -->[ap_area_clienti]<!-- /wp:shortcode -->',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);
		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( 'apac_page_id', (int) $page_id );
		}
	}

	apac_sync_site_css();

	apac_schedule_cron( true );
}

/**
 * Prossime ore 07:00 nel fuso orario impostato in WordPress.
 * Calcolata sulla data locale, quindi resta alle 07:00 anche dopo il cambio dell'ora legale.
 */
function apac_next_cron_ts( $from = null ) {
	$tz  = wp_timezone();
	$now = $from ? $from->setTimezone( $tz ) : new DateTimeImmutable( 'now', $tz );
	$t   = $now->setTime( 7, 0, 0 );
	if ( $t <= $now ) {
		$t = $now->modify( '+1 day' )->setTime( 7, 0, 0 );
	}
	return $t->getTimestamp();
}

/**
 * Pianifica il controllo giornaliero come evento singolo, ripianificato dopo ogni esecuzione:
 * un evento "daily" ogni 24 ore scivolerebbe di un'ora al cambio dell'ora legale.
 */
function apac_schedule_cron( $reset = false ) {
	if ( $reset ) {
		wp_clear_scheduled_hook( 'apac_cron_scadenze' );
	}
	if ( ! wp_next_scheduled( 'apac_cron_scadenze' ) ) {
		wp_schedule_single_event( apac_next_cron_ts(), 'apac_cron_scadenze' );
	}
}

/* Se l'evento manca (es. errore durante un'esecuzione) viene ripianificato. */
add_action( 'init', 'apac_ensure_cron' );
function apac_ensure_cron() {
	if ( get_option( 'apac_db_version' ) && ! wp_next_scheduled( 'apac_cron_scadenze' ) ) {
		apac_schedule_cron();
	}
}

/**
 * Disattivazione: si ferma solo il cron. I dati restano.
 */
function apac_deactivate() {
	wp_clear_scheduled_hook( 'apac_cron_scadenze' );
}

add_action( 'plugins_loaded', 'apac_maybe_upgrade' );
function apac_maybe_upgrade() {
	if ( get_option( 'apac_db_version' ) === APAC_DB_VERSION ) {
		return;
	}
	// Dopo un tentativo fallito si riprova al massimo ogni 10 minuti (non a ogni pagina).
	if ( get_transient( 'apac_migration_wait' ) ) {
		return;
	}
	if ( apac_install_db() ) {
		apac_schedule_cron( true ); // Passaggio al nuovo orario basato sul fuso del sito.
		apac_ensure_private_dir();
	} else {
		set_transient( 'apac_migration_wait', 1, 10 * MINUTE_IN_SECONDS );
	}
}

/* Avviso in bacheca se l'aggiornamento del database non è riuscito. */
add_action( 'admin_notices', 'apac_migration_notice' );
function apac_migration_notice() {
	$err = get_option( 'apac_migration_error' );
	if ( ! current_user_can( 'manage_options' ) || ! is_array( $err ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p><strong>AP Area Clienti:</strong> l’aggiornamento del database non è riuscito (' . esc_html( $err['missing'] ) . '). Il plugin riprova automaticamente; se il problema persiste contatta l’assistenza dell’hosting. Dettaglio: ' . esc_html( $err['db'] ) . '</p></div>';
}
