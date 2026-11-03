<?php
/**
 * Esegue i test end-to-end. Prima preparare l'ambiente con bin/test-env.sh.
 *   php tests/run.php            tutti i test
 *   php tests/run.php download   solo i test il cui nome contiene "download"
 */
require __DIR__ . '/lib.php';

$env_dir = getenv( 'APAC_TEST_ENV_DIR' ) ? getenv( 'APAC_TEST_ENV_DIR' ) : dirname( __DIR__ ) . '/.test-env';
$env     = @file_get_contents( $env_dir . '/env.json' );
if ( ! $env ) {
	fwrite( STDERR, "Ambiente di test non trovato: eseguire prima bin/test-env.sh\n" );
	exit( 2 );
}
T::$env = json_decode( $env, true );

$files = glob( __DIR__ . '/cases/*.php' );
sort( $files );
foreach ( $files as $f ) {
	require $f;
}

$filter = isset( $argv[1] ) ? $argv[1] : '';
$log    = T::$env['wp_dir'] . '/wp-content/debug.log';
$log_da = file_exists( $log ) ? filesize( $log ) : 0; // Si controllano solo le righe scritte durante questi test.
$pass   = 0;
$fail   = array();
$t0     = microtime( true );
foreach ( T::$tests as $t ) {
	list( $name, $fn ) = $t;
	if ( '' !== $filter && false === stripos( $name, $filter ) ) {
		continue;
	}
	try {
		$fn();
		$pass++;
		echo "PASS  {$name}\n";
	} catch ( Throwable $e ) {
		$fail[] = $name;
		echo "FAIL  {$name}\n      " . ( $e instanceof AssertionFailed ? '' : get_class( $e ) . ': ' ) . $e->getMessage() . "\n";
	}
}

// Nessun avviso o errore PHP durante i test (registro di debug di WordPress).
if ( '' === $filter && file_exists( $log ) && preg_match_all( '/^.*(PHP (Fatal|Warning|Notice|Deprecated)|WordPress database error).*$/m', (string) file_get_contents( $log, false, null, $log_da ), $mm ) ) {
	$righe = array_values( array_unique( array_filter( $mm[0], function ( $l ) {
		// Attesi: guasti simulati dai test; controlli aggiornamenti di WordPress senza accesso a wordpress.org.
		return false === strpos( $l, 'apac_tabella_inesistente' ) && ! preg_match( '/PHP Warning:  wp_(version_check|update_plugins|update_themes)\(\)/', $l );
	} ) ) );
	if ( $righe ) {
		$fail[] = 'registro di debug pulito';
		echo "FAIL  registro di debug pulito\n      " . implode( "\n      ", array_slice( $righe, 0, 10 ) ) . "\n";
	}
}

printf( "\n%d superati, %d falliti (%.1f s)\n", $pass, count( $fail ), microtime( true ) - $t0 );
exit( $fail ? 1 : 0 );
