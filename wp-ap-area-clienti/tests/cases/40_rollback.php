<?php
/**
 * "Tutto o niente": se un passaggio fallisce, nessuna modifica resta a metà.
 * I guasti sono simulati dal plugin di supporto ai test (opzione apac_test_fail_sql).
 */

function apac_t_guasto( $regex ) {
	Wp::option( 'apac_test_fail_sql', $regex );
}

function apac_t_fine_guasto() {
	Wp::delete_option( 'apac_test_fail_sql' );
	Wp::delete_option( 'apac_test_fail_meta' );
}

T::test(
	'rollback: eliminazione lavoratore fallita all\'ultimo passaggio non lascia attestati cancellati',
	function () {
		$a = Fx::azienda( 'Rb1 ' . bin2hex( random_bytes( 3 ) ) );
		$l = Fx::lavoratore( $a );
		Fx::attestato( $a, $l );
		Fx::attestato( $a, $l );
		$before = Db::fingerprint( array( 'lavoratori', 'attestati', 'scadenze' ) );
		apac_t_guasto( '/^DELETE FROM `wp_apac_lavoratori`/' );
		try {
			$msg = Fx::admin()->op( 'lavoratore_delete', array( 'id' => $l ), $a );
		} finally {
			apac_t_fine_guasto();
		}
		T::eq( 'db_error', $msg, 'esito' );
		T::eq( $before, Db::fingerprint( array( 'lavoratori', 'attestati', 'scadenze' ) ), 'modifiche parziali rimaste' );
		T::eq( '2', Db::val( "SELECT COUNT(*) FROM wp_apac_attestati WHERE lavoratore_id = {$l}" ), 'attestati' );
	}
);

T::test(
	'rollback: eliminazione azienda fallita all\'ultimo passaggio non cancella dati né file',
	function () {
		$a = Fx::azienda( 'Rb2 ' . bin2hex( random_bytes( 3 ) ) );
		$l = Fx::lavoratore( $a );
		Fx::attestato( $a, $l );
		Fx::scadenza( $a );
		list( , $file ) = Fx::documento( $a );
		$tabs   = array( 'aziende', 'lavoratori', 'attestati', 'scadenze', 'documenti' );
		$before = Db::fingerprint( $tabs );
		apac_t_guasto( '/^DELETE FROM `wp_apac_aziende`/' );
		try {
			$msg = Fx::admin()->op( 'azienda_delete', array( 'conferma' => 'ELIMINA' ), $a );
		} finally {
			apac_t_fine_guasto();
		}
		T::eq( 'db_error', $msg, 'esito' );
		T::eq( $before, Db::fingerprint( $tabs ), 'modifiche parziali rimaste' );
		T::ok( file_exists( $file ), 'il file del documento è stato cancellato nonostante l\'errore' );
	}
);

T::test(
	'rollback: caricamento documento fallito nel database non lascia il file sul server',
	function () {
		$a   = Fx::azienda( 'Rb3 ' . bin2hex( random_bytes( 3 ) ) );
		$dir = Wp::php( 'echo apac_private_dir();' ) . '/' . $a;
		$tmp = tempnam( sys_get_temp_dir(), 'apacpdf' );
		file_put_contents( $tmp, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << >>\n%%EOF\n" );
		$s = Fx::admin();
		apac_t_guasto( '/^INSERT INTO `wp_apac_documenti`/' );
		try {
			$r = $s->post(
				T::url(),
				array(
					'action'      => 'apac_action',
					'op'          => 'documento_upload',
					'azienda_id'  => (string) $a,
					'_apac_nonce' => $s->nonce( 'apac_documento_upload' ),
					'_apac_once'  => bin2hex( random_bytes( 10 ) ),
					'titolo'      => 'Prova',
					'categoria'   => 'altro',
					'file'        => new CURLFile( $tmp, 'application/pdf', 'prova.pdf' ),
				)
			);
		} finally {
			apac_t_fine_guasto();
			unlink( $tmp );
		}
		T::eq( 'db_error', Http::msg( $r ), 'esito' );
		$files = is_dir( $dir ) ? array_values( array_diff( scandir( $dir ), array( '.', '..', 'index.php' ) ) ) : array();
		T::eq( array(), $files, 'file rimasti nella cartella dell\'azienda' );
		T::eq( '0', Db::val( "SELECT COUNT(*) FROM wp_apac_documenti WHERE azienda_id = {$a}" ), 'righe documenti' );
	}
);

/** Creazione accesso con fallimento dopo l'inserimento dell'utente WordPress. */
function apac_t_utente_orfano( $engine ) {
	Db::q( "ALTER TABLE wp_users ENGINE={$engine}" );
	Db::q( "ALTER TABLE wp_usermeta ENGINE={$engine}" );
	$a     = Fx::azienda( 'Rb4 ' . bin2hex( random_bytes( 3 ) ) );
	$email = 'orfano' . bin2hex( random_bytes( 3 ) ) . '@example.com';
	Wp::option( 'apac_test_fail_meta', 1 );
	try {
		$msg = Fx::admin()->op( 'utente_create', array( 'nome' => 'Rollback', 'email' => $email ), $a );
	} finally {
		apac_t_fine_guasto();
		Db::q( 'ALTER TABLE wp_users ENGINE=InnoDB' );
		Db::q( 'ALTER TABLE wp_usermeta ENGINE=InnoDB' );
	}
	T::eq( 'db_error', $msg, 'esito' );
	T::eq( '0', Db::val( "SELECT COUNT(*) FROM wp_users WHERE user_email = '{$email}'" ), 'utente WordPress rimasto' );
}

T::test(
	'rollback: creazione accesso fallita non lascia utenti WordPress (tabelle InnoDB)',
	function () {
		apac_t_utente_orfano( 'InnoDB' );
	}
);

T::test(
	'rollback: creazione accesso fallita non lascia utenti WordPress (tabelle WordPress MyISAM)',
	function () {
		apac_t_utente_orfano( 'MyISAM' );
	}
);

T::test(
	'transazioni: con una tabella del plugin non InnoDB le modifiche sono bloccate, nulla cambia',
	function () {
		$a = Fx::azienda( 'Myisam ' . bin2hex( random_bytes( 3 ) ) );
		$l = Fx::lavoratore( $a );
		Fx::attestato( $a, $l );
		Db::q( 'ALTER TABLE wp_apac_attestati ENGINE=MyISAM' );
		try {
			$before = Db::fingerprint( array( 'lavoratori', 'attestati' ) );
			$msg    = Fx::admin()->op( 'lavoratore_delete', array( 'id' => $l ), $a );
			$after  = Db::fingerprint( array( 'lavoratori', 'attestati' ) );
			$page   = Fx::admin()->get( T::$env['url'] . '/wp-admin/options-general.php?page=apac-settings' )['body'];
		} finally {
			Db::q( 'ALTER TABLE wp_apac_attestati ENGINE=InnoDB' );
			Wp::php( 'delete_transient("apac_tx_check");' );
		}
		T::eq( 'tx_unsupported', $msg, 'esito' );
		T::eq( $before, $after, 'dati cambiati senza transazione' );
		T::ok( false !== strpos( $page, 'wp_apac_attestati' ), 'la pagina impostazioni deve indicare la tabella non transazionale' );
	}
);
