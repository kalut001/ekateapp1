<?php
/**
 * Isolamento tra aziende: un cliente vede e modifica SOLO la propria azienda,
 * qualunque azienda_id o id indichi nella richiesta.
 */

/** Due aziende, un cliente di A, dati in B. */
function apac_t_scenario_ab() {
	$a   = Fx::azienda( 'Alfa ' . bin2hex( random_bytes( 3 ) ) );
	$b   = Fx::azienda( 'Beta ' . bin2hex( random_bytes( 3 ) ) );
	$c   = Fx::cliente( $a );
	$lb  = Fx::lavoratore( $b, 'Segretissimo' . bin2hex( random_bytes( 3 ) ) );
	$atb = Fx::attestato( $b, $lb );
	$scb = Fx::scadenza( $b );
	list( $db ) = Fx::documento( $b );
	return array(
		'a'   => $a,
		'b'   => $b,
		'c'   => $c,
		's'   => Fx::login( $c['email'], $c['pass'] ),
		'lb'  => $lb,
		'atb' => $atb,
		'scb' => $scb,
		'db'  => $db,
		'cognome_b' => Db::val( "SELECT cognome FROM wp_apac_lavoratori WHERE id = {$lb}" ),
	);
}

T::test(
	'isolamento: modifiche a record di un\'altra azienda rifiutate',
	function () {
		$x      = apac_t_scenario_ab();
		$s      = $x['s'];
		$before = Db::fingerprint( array( 'lavoratori', 'attestati', 'scadenze', 'documenti' ) );
		$casi   = array(
			array( 'lavoratore_save', array( 'id' => $x['lb'], 'nome' => 'X', 'cognome' => 'Y' ) ),
			array( 'lavoratore_delete', array( 'id' => $x['lb'] ) ),
			array( 'lavoratore_cessa', array( 'id' => $x['lb'], 'data_cessazione' => '2026-01-01' ) ),
			array( 'attestato_update', array( 'id' => $x['atb'], 'corso' => 'Preposto', 'data_conseguimento' => '2025-01-01', 'data_scadenza' => '2027-01-01' ) ),
			array( 'attestato_delete', array( 'id' => $x['atb'] ) ),
			array( 'scadenza_save', array( 'id' => $x['scb'], 'titolo' => 'Z', 'categoria' => 'dvr', 'data_scadenza' => '2027-01-01' ) ),
			array( 'scadenza_toggle', array( 'id' => $x['scb'], 'stato_atteso' => 'aperta' ) ),
			array( 'scadenza_delete', array( 'id' => $x['scb'] ) ),
			array( 'documento_update', array( 'id' => $x['db'], 'titolo' => 'Z', 'categoria' => 'altro' ) ),
			array( 'documento_delete', array( 'id' => $x['db'] ) ),
		);
		foreach ( $casi as $caso ) {
			// Il cliente indica anche l'azienda B: deve essere ignorata.
			$msg = $s->op( $caso[0], $caso[1], $x['b'] );
			T::ok( in_array( $msg, array( 'denied', 'bad_lavoratore' ), true ), $caso[0] . ' su un record di B non è stato rifiutato', $msg );
		}
		T::eq( $before, Db::fingerprint( array( 'lavoratori', 'attestati', 'scadenze', 'documenti' ) ), 'i dati sono cambiati' );
	}
);

T::test(
	'isolamento: azienda_id inviato dal cliente ignorato (il nuovo lavoratore va nella sua azienda)',
	function () {
		$x   = apac_t_scenario_ab();
		$msg = $x['s']->op( 'lavoratore_save', array( 'nome' => 'Nuovo', 'cognome' => 'Inserito' . $x['a'], 'attivo' => '1' ), $x['b'] );
		T::eq( 'saved', $msg, 'salvataggio' );
		T::eq( (string) $x['a'], Db::val( "SELECT azienda_id FROM wp_apac_lavoratori WHERE cognome = 'Inserito{$x['a']}'" ), 'azienda del nuovo lavoratore' );
	}
);

T::test(
	'isolamento: le pagine con ?azienda= di un\'altra azienda mostrano solo i propri dati',
	function () {
		$x = apac_t_scenario_ab();
		foreach ( array( 'lavoratori', 'dashboard', 'scadenziario', 'documenti', 'cerca' ) as $sez ) {
			$r = $x['s']->get( T::url( array( 'sez' => $sez, 'azienda' => $x['b'], 'q' => 'Segretissimo' ) ) );
			T::eq( 200, $r['status'], "pagina {$sez}" );
			T::ok( false === strpos( $r['body'], $x['cognome_b'] ), "la pagina {$sez} mostra dati dell'azienda B" );
		}
		$r = $x['s']->get( T::url( array( 'apac_report' => 1, 'azienda' => $x['b'] ) ) );
		T::ok( false === strpos( $r['body'], $x['cognome_b'] ), 'il riepilogo stampabile mostra dati di B' );
	}
);

T::test(
	'isolamento: file, versioni, firme ed esportazione di un\'altra azienda non scaricabili',
	function () {
		$x = apac_t_scenario_ab();
		$s = $x['s'];
		// Documento di B con un nonce valido per la sessione del cliente.
		$r = $s->get( T::url( array( 'apac_file' => $x['db'], '_wpnonce' => $s->nonce( 'apac_file_' . $x['db'] ) ) ) );
		T::eq( 403, $r['status'], 'documento di B' );
		// Vecchio indirizzo admin-post.php.
		$r = $s->get( T::$env['url'] . '/wp-admin/admin-post.php?action=apac_file&id=' . $x['db'] . '&_wpnonce=' . $s->nonce( 'apac_file_' . $x['db'] ) );
		T::eq( 403, $r['status'], 'documento di B via admin-post.php' );
		// Versione precedente di un documento di B.
		Db::q( "INSERT INTO wp_apac_documenti_versioni (documento_id, azienda_id, nome_file, file_path, mime, dimensione, created_at) SELECT id, azienda_id, nome_file, file_path, mime, dimensione, NOW() FROM wp_apac_documenti WHERE id = {$x['db']}" );
		$ver = (int) Db::c()->insert_id;
		$r   = $s->get( T::url( array( 'apac_ver' => $ver, '_wpnonce' => $s->nonce( 'apac_ver_' . $ver ) ) ) );
		T::eq( 403, $r['status'], 'versione di un documento di B' );
		// Esportazione richiesta per B: il cliente esporta solo la propria azienda.
		$r = $s->get( T::url( array( 'apac_export' => 'csv', 'azienda' => $x['b'], '_wpnonce' => $s->nonce( 'apac_export_' . $x['b'] ) ) ) );
		T::eq( 403, $r['status'], 'esportazione di B' );
		$r = $s->get( T::url( array( 'apac_export' => 'csv', '_wpnonce' => $s->nonce( 'apac_export_' . $x['a'] ) ) ) );
		T::eq( 200, $r['status'], 'esportazione della propria azienda' );
		T::ok( false === strpos( $r['body'], $x['cognome_b'] ), "l'esportazione contiene dati di B" );
		// Ricerca aziende (solo studio).
		$r = $s->get( T::$env['url'] . '/wp-admin/admin-ajax.php?action=apac_cerca_aziende&q=Beta&_ajax_nonce=' . $s->nonce( 'apac_cerca' ) );
		T::eq( 403, $r['status'], 'ricerca aziende da cliente' );
	}
);
