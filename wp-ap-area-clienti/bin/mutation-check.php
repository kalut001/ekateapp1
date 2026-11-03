<?php
/**
 * Verifica della copertura dei test con mutazioni ("mutation testing").
 *
 * Per ogni mutazione: copia il plugin, toglie UN controllo di sicurezza, prepara un WordPress pulito con
 * quella copia (bin/test-env.sh con APAC_TEST_PLUGIN_DIR) ed esegue tutti i test. Se almeno un test fallisce
 * la mutazione è "rilevata"; se tutti passano, quel controllo NON è coperto dai test.
 * Alla fine l'ambiente torna al plugin del repository.
 *
 *   APAC_TEST_DB_HOST=... php bin/mutation-check.php            tutte le mutazioni
 *   APAC_TEST_DB_HOST=... php bin/mutation-check.php revoca     solo quelle con "revoca" nel nome
 *
 * Esito: 0 se tutte le mutazioni sono rilevate, 1 altrimenti.
 */
$root   = dirname( __DIR__ );
$filter = isset( $argv[1] ) ? $argv[1] : '';

/** [ nome, file, testo originale (deve comparire una sola volta), testo mutato ] */
$mutazioni = array(
	array( 'isolamento: accesso a qualsiasi azienda', 'includes/helpers.php', "return ! apac_user_revoked() && apac_user_azienda_id() === \$azienda_id;", 'return ! apac_user_revoked();' ),
	array( 'isolamento: il cliente sceglie l\'azienda e il controllo di accesso manca', 'includes/actions.php', "\$azienda_id = \$admin ? absint( apac_post( 'azienda_id' ) ) : apac_user_azienda_id();\n\tif ( 'nessuno' !== \$o['ambito'] && ! apac_can_access_azienda( \$azienda_id ) ) {", "\$azienda_id = absint( apac_post( 'azienda_id' ) );\n\tif ( false ) {" ),
	array( 'revoca: utente revocato ancora ammesso al portale', 'includes/helpers.php', "\tif ( apac_user_revoked() ) {\n\t\treturn false;\n\t}\n\t\$azienda_id = apac_user_azienda_id();", "\t\$azienda_id = apac_user_azienda_id();" ),
	array( 'revoca: utente revocato con accesso alla propria azienda', 'includes/helpers.php', "return ! apac_user_revoked() && apac_user_azienda_id() === \$azienda_id;", 'return apac_user_azienda_id() === $azienda_id;' ),
	array( 'ruolo: operazioni dello studio aperte ai clienti', 'includes/actions.php', "if ( 'cliente' !== \$o['ruolo'] && ! \$admin ) {", 'if ( false ) {' ),
	array( 'permessi: permesso per azienda non controllato', 'includes/actions.php', "if ( '' !== \$o['permesso'] && ! apac_client_can( \$azienda_id, \$o['permesso'] ) ) {", 'if ( false ) {' ),
	array( 'sola consultazione: controllo centrale tolto', 'includes/actions.php', "if ( ! \$admin && ! \$o['sola_lettura'] && apac_user_readonly() ) {", 'if ( false ) {' ),
	array( 'sola consultazione: controllo nei permessi tolto', 'includes/admin-tools.php', "\tif ( apac_user_readonly() ) {\n\t\treturn false; // Accesso in sola consultazione.\n\t}", '' ),
	array( 'firme: operazioni consentite senza firme del primo accesso', 'includes/actions.php', "if ( ! \$o['prima_delle_firme'] && ! \$admin && function_exists( 'apac_consensi_pending' ) && apac_consensi_pending() ) {", 'if ( false ) {' ),
	array( 'firme: download consentiti senza firme', 'includes/files.php', "if ( is_user_logged_in() && ! apac_is_admin() && function_exists( 'apac_consensi_pending' ) && apac_consensi_pending() ) {", 'if ( false ) {' ),
	array( 'download: nonce del documento non verificato', 'includes/files.php', "! wp_verify_nonce( sanitize_text_field( wp_unslash( \$_GET['_wpnonce'] ) ), 'apac_file_' . \$id )", 'false' ),
	array( 'download: documento di un\'altra azienda', 'includes/files.php', "if ( ! \$doc || ! apac_can_access_azienda( (int) \$doc->azienda_id ) ) {\n\t\twp_die( 'Documento non disponibile.'", "if ( ! \$doc ) {\n\t\twp_die( 'Documento non disponibile.'" ),
	array( 'download: percorso fuori dalla cartella protetta', 'includes/files.php', "if ( ! \$base || ! \$path || 0 !== strpos( \$path, \$base . DIRECTORY_SEPARATOR ) ) {", 'if ( ! $path ) {' ),
	array( 'documenti: il cliente elimina documenti non suoi', 'includes/actions.php', "\t// Il cliente può eliminare solo i documenti caricati da lui stesso.\n\tif ( ! \$doc || ( ! apac_is_admin() && (int) \$doc->caricato_da !== get_current_user_id() ) ) {", "\tif ( ! \$doc ) {" ),
	array( 'registro: operazione non registrata eseguita', 'includes/actions.php', "\t\$o = apac_operazione( \$op );\n\tif ( ! \$o || ! function_exists( \$o['fn'] ) ) {", "\t\$o = apac_operazione( \$op ) ? apac_operazione( \$op ) : array_merge( apac_operazione_default(), array( 'fn' => 'apac_op_' . \$op, 'ruolo' => 'cliente', 'ambito' => 'nessuno' ) );\n\tif ( ! function_exists( \$o['fn'] ) ) {" ),
	array( 'transazioni: salvataggi non bloccati senza InnoDB', 'includes/helpers.php', "if ( function_exists( 'apac_tx_salvataggi_bloccati' ) && apac_tx_salvataggi_bloccati() ) {", 'if ( false ) {' ),
	array( 'transazioni: avviso in bacheca assente', 'ap-area-clienti.php', "if ( ! current_user_can( 'manage_options' ) || ! apac_tx_salvataggi_bloccati() ) {", 'if ( true ) {' ),
	array( 'rollback: errori del database ignorati', 'includes/helpers.php', "\tif ( false === \$result || ( \$need_row && 0 === (int) \$result ) ) {\n\t\tapac_redirect( \$sez, 'db_error', \$args );", "\tif ( false ) {\n\t\tapac_redirect( \$sez, 'db_error', \$args );" ),
	array( 'rollback: file nuovi non rimossi', 'includes/helpers.php', "\t\tif ( is_file( \$f ) ) {\n\t\t\twp_delete_file( \$f );\n\t\t}\n\t}\n\t\$undo", "\t}\n\t\$undo" ),
	array( 'rollback: utente WordPress non rimosso', 'includes/actions.php', "\tapac_tx_on_rollback(\n\t\tfunction () use ( \$user_id ) {", "\tif ( false ) apac_tx_on_rollback(\n\t\tfunction () use ( \$user_id ) {" ),
	array( 'doppi invii: codice monouso ignorato', 'includes/actions.php', "\tif ( 'dup' === \$once ) {", "\tif ( false ) {" ),
	array( 'richieste: collegamento senza verifica', 'includes/richieste.php', "if ( 'esistente' === \$dest && '1' !== (string) apac_post( 'verificato' ) ) {", 'if ( false ) {' ),
	array( 'richieste: limite per IP assente', 'includes/richieste.php', "\tif ( \$hits >= APAC_RICH_MAX_IP_ORA ) {\n\t\t\$esito = 'troppe';", "\tif ( false ) {\n\t\t\$esito = 'troppe';" ),
	array( 'richieste: quota email per indirizzo assente', 'includes/richieste.php', "\tif ( \$n >= APAC_RICH_MAX_PER_EMAIL ) {\n\t\treturn false;\n\t}", '' ),
);

$tmp     = sys_get_temp_dir() . '/apac-mutanti-' . getmypid();
$env     = 'APAC_TEST_DB_HOST=' . escapeshellarg( (string) getenv( 'APAC_TEST_DB_HOST' ) ) . ' APAC_TEST_DB_USER=' . escapeshellarg( getenv( 'APAC_TEST_DB_USER' ) ? (string) getenv( 'APAC_TEST_DB_USER' ) : 'wp' ) . ' APAC_TEST_DB_PASS=' . escapeshellarg( getenv( 'APAC_TEST_DB_PASS' ) ? (string) getenv( 'APAC_TEST_DB_PASS' ) : 'wp' ) . ' APAC_TEST_DB_NAME=' . escapeshellarg( getenv( 'APAC_TEST_DB_NAME' ) ? (string) getenv( 'APAC_TEST_DB_NAME' ) : 'wp_test' );
$esiti   = array();
$rilev   = 0;
$errori  = 0;
foreach ( $mutazioni as $m ) {
	list( $nome, $file, $da, $a ) = $m;
	if ( '' !== $filter && false === stripos( $nome, $filter ) ) {
		continue;
	}
	shell_exec( 'rm -rf ' . escapeshellarg( $tmp ) );
	mkdir( $tmp, 0755, true );
	shell_exec( 'cp -r ' . escapeshellarg( $root . '/ap-area-clienti' ) . ' ' . escapeshellarg( $tmp . '/' ) );
	$path = $tmp . '/ap-area-clienti/' . $file;
	$src  = (string) file_get_contents( $path );
	if ( 1 !== substr_count( $src, $da ) ) {
		$esiti[] = array( $nome, 'ERRORE: testo da mutare trovato ' . substr_count( $src, $da ) . ' volte in ' . $file );
		$errori++;
		continue;
	}
	file_put_contents( $path, str_replace( $da, $a, $src ) );
	exec( 'php -l ' . escapeshellarg( $path ) . ' 2>&1', $o, $rc );
	if ( 0 !== $rc ) {
		$esiti[] = array( $nome, 'ERRORE: la mutazione rompe la sintassi' );
		$errori++;
		continue;
	}
	exec( $env . ' APAC_TEST_PLUGIN_DIR=' . escapeshellarg( $tmp . '/ap-area-clienti' ) . ' ' . escapeshellarg( $root . '/bin/test-env.sh' ) . ' > /dev/null 2>&1', $o, $rc );
	if ( 0 !== $rc ) {
		$esiti[] = array( $nome, 'ERRORE: ambiente di test non preparato' );
		$errori++;
		continue;
	}
	$out = array();
	exec( 'php -d memory_limit=1G ' . escapeshellarg( $root . '/tests/run.php' ) . ' 2>&1', $out, $rc );
	$falliti = array_values( array_filter( $out, function ( $l ) {
		return 0 === strpos( $l, 'FAIL  ' );
	} ) );
	if ( $falliti ) {
		$rilev++;
		$esiti[] = array( $nome, 'RILEVATA da ' . count( $falliti ) . ' test (es. ' . substr( $falliti[0], 6, 90 ) . ')' );
	} else {
		$esiti[] = array( $nome, 'NON RILEVATA: nessun test fallisce senza questo controllo' );
	}
	echo end( $esiti )[0] . ' → ' . end( $esiti )[1] . "\n";
}
shell_exec( 'rm -rf ' . escapeshellarg( $tmp ) );
exec( $env . ' ' . escapeshellarg( $root . '/bin/test-env.sh' ) . ' > /dev/null 2>&1' ); // Ambiente di nuovo sul plugin del repository.

$tot = count( $esiti );
echo "\nRiepilogo: {$rilev} mutazioni rilevate su {$tot}" . ( $errori ? ", {$errori} non applicabili" : '' ) . "\n";
exit( $rilev === $tot ? 0 : 1 );
