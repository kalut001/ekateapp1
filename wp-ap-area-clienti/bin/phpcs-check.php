<?php
/**
 * Controllo PHPCS (regole di sicurezza) con baseline delle segnalazioni già verificate a mano.
 *
 *   php bin/phpcs-check.php            fallisce se compaiono segnalazioni NON presenti nella baseline
 *   php bin/phpcs-check.php --update   rigenera la baseline (solo dopo aver verificato ogni nuova segnalazione)
 *
 * Ogni segnalazione è identificata da regola + testo della riga (non dal numero di riga né dal file):
 * spostare codice o aggiungere righe altrove non la rende "nuova". La baseline conta le occorrenze.
 */
$root     = dirname( __DIR__ );
$baseline = $root . '/tests/phpcs-baseline.json';
$phpcs    = getenv( 'PHPCS_BIN' ) ? getenv( 'PHPCS_BIN' ) : $root . '/vendor/bin/phpcs';

$cmd = 'php -d memory_limit=2G ' . escapeshellarg( $phpcs ) . ' -q --report=json --standard=' . escapeshellarg( $root . '/phpcs.xml.dist' ) . ' 2>/dev/null';
chdir( $root );
$json = shell_exec( $cmd );
$data = json_decode( (string) $json, true );
if ( ! is_array( $data ) || ! isset( $data['files'] ) ) {
	fwrite( STDERR, "PHPCS non ha prodotto un risultato leggibile.\n" . substr( (string) $json, 0, 2000 ) . "\n" );
	exit( 2 );
}

$found = array(); // chiave => occorrenze
$where = array(); // chiave => [ "file:riga" ]
foreach ( $data['files'] as $file => $info ) {
	$lines = file( $file, FILE_IGNORE_NEW_LINES );
	foreach ( $info['messages'] as $m ) {
		$text = preg_replace( '/\s+/', ' ', trim( (string) $lines[ $m['line'] - 1 ] ) );
		$key  = $m['source'] . ' | ' . $text;
		$found[ $key ]   = ( $found[ $key ] ?? 0 ) + 1;
		$where[ $key ][] = substr( $file, strlen( $root ) + 1 ) . ':' . $m['line'] . ' ' . $m['message'];
	}
}
ksort( $found );

if ( in_array( '--update', $argv, true ) ) {
	file_put_contents( $baseline, json_encode( $found, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
	echo 'Baseline aggiornata: ' . array_sum( $found ) . " segnalazioni verificate.\n";
	exit( 0 );
}

$known = is_file( $baseline ) ? json_decode( (string) file_get_contents( $baseline ), true ) : array();
$nuove = array();
foreach ( $found as $key => $n ) {
	$extra = $n - (int) ( $known[ $key ] ?? 0 );
	if ( $extra > 0 ) {
		$nuove[ $key ] = array_slice( $where[ $key ], -$extra );
	}
}
$risolte = array_sum( array_map( function ( $k ) use ( $known, $found ) {
	return max( 0, (int) $known[ $k ] - (int) ( $found[ $k ] ?? 0 ) );
}, array_keys( (array) $known ) ) );

if ( $nuove ) {
	echo "NUOVE segnalazioni di sicurezza PHPCS (non presenti nella baseline):\n";
	foreach ( $nuove as $list ) {
		foreach ( $list as $w ) {
			echo '  ' . $w . "\n";
		}
	}
	echo "\nCorreggerle, oppure verificarle una per una e poi eseguire: php bin/phpcs-check.php --update\n";
	exit( 1 );
}
echo 'PHPCS sicurezza: nessuna nuova segnalazione (' . array_sum( $found ) . ' già verificate in baseline' . ( $risolte ? ", {$risolte} non più presenti" : '' ) . ").\n";
exit( 0 );
