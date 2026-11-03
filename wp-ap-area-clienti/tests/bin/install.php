<?php
/**
 * Installazione pulita di WordPress per i test: svuota il database, installa, attiva il plugin.
 * Uso: php tests/bin/install.php <cartella WordPress> <porta>
 */
if ( PHP_SAPI !== 'cli' || $argc < 3 ) {
	fwrite( STDERR, "Uso: php install.php <wp-dir> <porta>\n" );
	exit( 2 );
}
$wp_dir = rtrim( $argv[1], '/' );
$port   = (int) $argv[2];
$url    = 'http://127.0.0.1:' . $port;

// 1) Database vuoto (solo il database di test indicato in wp-config.php).
$cfg = file_get_contents( $wp_dir . '/wp-config.php' );
preg_match_all( "/define\\( '(DB_[A-Z]+)', '([^']*)' \\)/", $cfg, $m, PREG_SET_ORDER );
$db = array();
foreach ( $m as $row ) {
	$db[ $row[1] ] = $row[2];
}
list( $host, $dbport ) = array_pad( explode( ':', $db['DB_HOST'], 2 ), 2, 3306 );
$my = new mysqli( $host, $db['DB_USER'], $db['DB_PASSWORD'], '', (int) $dbport );
$my->query( 'DROP DATABASE IF EXISTS `' . $my->real_escape_string( $db['DB_NAME'] ) . '`' );
$my->query( 'CREATE DATABASE `' . $my->real_escape_string( $db['DB_NAME'] ) . '` CHARACTER SET utf8mb4' );
$my->close();

// 2) Installazione.
define( 'WP_INSTALLING', true );
$_SERVER['HTTP_HOST']   = '127.0.0.1:' . $port;
$_SERVER['REQUEST_URI'] = '/';
require $wp_dir . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_install( 'AP test', 'admin', 'admin@example.com', true, '', 'Adminpass123!' );
update_option( 'siteurl', $url );
update_option( 'home', $url );
update_option( 'permalink_structure', '/%postname%/' );

// 3) Attivazione del plugin.
wp_set_current_user( 1 );
$r = activate_plugin( 'ap-area-clienti/ap-area-clienti.php' );
if ( is_wp_error( $r ) ) {
	fwrite( STDERR, 'Attivazione non riuscita: ' . $r->get_error_message() . "\n" );
	exit( 1 );
}
flush_rewrite_rules();

file_put_contents(
	dirname( $wp_dir ) . '/env.json',
	json_encode(
		array(
			'wp_dir' => $wp_dir,
			'url'    => $url,
			'db'     => array(
				'host' => $host,
				'port' => (int) $dbport,
				'user' => $db['DB_USER'],
				'pass' => $db['DB_PASSWORD'],
				'name' => $db['DB_NAME'],
			),
		)
	)
);
echo 'WordPress installato, plugin attivo (database ' . get_option( 'apac_db_version' ) . ")\n";
