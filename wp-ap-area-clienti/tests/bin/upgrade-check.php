<?php
/**
 * Prova di aggiornamento (usata da bin/verifiche.sh).
 *   php tests/bin/upgrade-check.php prima   con la versione vecchia attiva: crea dati e li registra
 *   php tests/bin/upgrade-check.php dopo    con la versione nuova: i dati devono esserci ancora e il portale funzionare
 */
require dirname( __DIR__ ) . '/lib.php';
$env_dir = getenv( 'APAC_TEST_ENV_DIR' ) ? getenv( 'APAC_TEST_ENV_DIR' ) : dirname( __DIR__, 2 ) . '/.test-env';
T::$env  = json_decode( (string) file_get_contents( $env_dir . '/env.json' ), true );
$stato   = $env_dir . '/upgrade-state.json';
$fase    = isset( $argv[1] ) ? $argv[1] : '';

function apac_u_versione() {
	return Wp::php( 'echo APAC_VERSION;' );
}

try {
	if ( 'prima' === $fase ) {
		$a = Fx::azienda( 'Dati Prima Aggiornamento Srl' );
		$c = Fx::cliente( $a );
		$l = Fx::lavoratore( $a, 'PrimaAggiornamento' );
		Fx::attestato( $a, $l );
		Fx::scadenza( $a );
		list( $doc, $abs ) = Fx::documento( $a, 1, "%PDF-1.4\n% documento caricato con la versione precedente\n" );
		$conteggi = array();
		foreach ( array( 'aziende', 'lavoratori', 'attestati', 'scadenze', 'documenti' ) as $t ) {
			$conteggi[ $t ] = Db::val( "SELECT COUNT(*) FROM wp_apac_{$t}" );
		}
		file_put_contents(
			$stato,
			json_encode(
				array(
					'versione' => apac_u_versione(),
					'db'       => Db::val( "SELECT option_value FROM wp_options WHERE option_name = 'apac_db_version'" ),
					'azienda'  => $a,
					'cliente'  => $c,
					'doc'      => $doc,
					'file'     => $abs,
					'conteggi' => $conteggi,
				)
			)
		);
		echo 'Dati creati con la versione ' . apac_u_versione() . ' (database ' . Db::val( "SELECT option_value FROM wp_options WHERE option_name = 'apac_db_version'" ) . '): ' . json_encode( $conteggi ) . ", 1 cliente, 1 file\n";
		exit( 0 );
	}

	T::ok( 'dopo' === $fase, 'fase sconosciuta (prima | dopo)' );
	$p = json_decode( (string) file_get_contents( $stato ), true );
	T::ok( is_array( $p ), 'stato della fase «prima» mancante' );
	$nuova = apac_u_versione();
	echo "Aggiornamento da {$p['versione']} a {$nuova}\n";
	T::ok( $nuova !== $p['versione'], 'la versione del plugin non è cambiata' );
	// La prima richiesta dopo l'aggiornamento esegue l'eventuale migrazione.
	$r = ( new Http() )->get( T::url() );
	T::eq( 200, $r['status'], 'prima pagina dopo l\'aggiornamento' );
	T::eq( $p['db'], Db::val( "SELECT option_value FROM wp_options WHERE option_name = 'apac_db_version'" ), 'versione del database' );
	foreach ( $p['conteggi'] as $t => $n ) {
		T::eq( $n, Db::val( "SELECT COUNT(*) FROM wp_apac_{$t}" ), "righe di {$t} dopo l'aggiornamento" );
	}
	echo "Dati conservati: " . json_encode( $p['conteggi'] ) . "\n";
	T::ok( is_file( $p['file'] ), 'file del documento sparito' );
	// Il cliente creato con la versione vecchia entra, vede i suoi dati, scarica il documento e salva.
	$s = Fx::login( $p['cliente']['email'], $p['cliente']['pass'] );
	T::ok( false !== strpos( $s->get( T::url( array( 'sez' => 'lavoratori' ) ) )['body'], 'PrimaAggiornamento' ), 'lavoratore non visibile al cliente' );
	$d = $s->get( T::url( array( 'apac_file' => $p['doc'], '_wpnonce' => $s->nonce( 'apac_file_' . $p['doc'] ) ) ) );
	T::ok( 200 === $d['status'] && false !== strpos( $d['body'], 'versione precedente' ), 'download del documento', $d['status'] );
	T::eq( 'saved', $s->op( 'lavoratore_save', array( 'nome' => 'Dopo', 'cognome' => 'Aggiornamento', 'attivo' => '1' ), $p['azienda'] ), 'salvataggio con la nuova versione' );
	$log = T::$env['wp_dir'] . '/wp-content/debug.log';
	$err = file_exists( $log ) ? preg_grep( '/PHP (Fatal|Warning|Notice)|WordPress database error/', file( $log ) ) : array();
	$err = array_filter( $err, function ( $l ) {
		return ! preg_match( '/wp_(version_check|update_plugins|update_themes)\(\)/', $l );
	} );
	T::eq( array(), array_values( $err ), 'errori PHP o del database nel registro' );
	echo "Accesso, consultazione, download e salvataggio del cliente: OK\n";
	exit( 0 );
} catch ( Throwable $e ) {
	echo 'FALLITO: ' . $e->getMessage() . "\n";
	exit( 1 );
}
