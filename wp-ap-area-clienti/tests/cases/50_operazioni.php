<?php
/**
 * Operazioni riservate allo studio e operazioni sconosciute.
 * L'elenco delle operazioni viene letto dal registro del plugin (apac_operazioni()), così ogni nuova
 * operazione dello studio è coperta automaticamente.
 */

/** @return array<string, array<string,mixed>> */
function apac_t_registro() {
	static $r = null;
	if ( null === $r ) {
		$r = json_decode( Wp::php( 'echo json_encode( function_exists("apac_operazioni") ? array_map( function($o){ unset($o["fn"]); return $o; }, apac_operazioni() ) : null );' ), true );
		T::ok( is_array( $r ) && count( $r ) > 30, 'registro delle operazioni (apac_operazioni) non disponibile', $r );
	}
	return $r;
}

T::test(
	'registro: ogni funzione apac_op_* è registrata e ogni voce punta a una funzione esistente',
	function () {
		$out = json_decode(
			Wp::php(
				'$def = array_values( array_filter( get_defined_functions()["user"], function ($f) { return 0 === strpos( $f, "apac_op_" ) && ! in_array( $f, array( "apac_op_permission", "apac_op_section", "apac_op_test_non_registrata" ), true ); } ) );'
				. '$reg = function_exists("apac_operazioni") ? apac_operazioni() : array();'
				. '$fns = array(); foreach ( $reg as $k => $o ) { $fns[] = $o["fn"]; }'
				. 'echo json_encode( array( "non_registrate" => array_values( array_diff( $def, $fns ) ), "inesistenti" => array_values( array_filter( $fns, function ($f) { return ! function_exists( $f ); } ) ), "n" => count( $reg ) ) );'
			),
			true
		);
		T::ok( $out['n'] > 30, 'registro vuoto', $out );
		T::eq( array(), $out['non_registrate'], 'funzioni apac_op_* non presenti nel registro' );
		T::eq( array(), $out['inesistenti'], 'voci del registro senza funzione' );
	}
);

T::test(
	'operazioni dello studio: un cliente non ne esegue nessuna e nulla cambia',
	function () {
		$a = Fx::azienda( 'OpAdm ' . bin2hex( random_bytes( 3 ) ) );
		$c = Fx::cliente( $a );
		$l = Fx::lavoratore( $a );
		$s = Fx::login( $c['email'], $c['pass'] );
		$admin_ops = array_keys( array_filter( apac_t_registro(), function ( $o ) {
			return 'studio' === $o['ruolo'];
		} ) );
		T::ok( count( $admin_ops ) >= 15, 'troppe poche operazioni dello studio nel registro', $admin_ops );
		$tabs   = array( 'aziende', 'lavoratori', 'segnalazioni', 'mansioni_corsi', 'richieste', 'log' );
		$before = Db::fingerprint( $tabs );
		$users  = Db::val( 'SELECT CONCAT(COUNT(*), "-", MAX(ID)) FROM wp_users' );
		$meta   = Db::val( "SELECT COUNT(*) FROM wp_usermeta WHERE meta_key IN ('apac_revocato','apac_azienda_id','apac_accesso')" );
		$campi  = array( 'conferma' => 'ELIMINA', 'user_id' => $c['id'], 'id' => $l, 'ragione_sociale' => 'Hack', 'nome' => 'Hack', 'email' => 'hack@example.com', 'stato' => 'chiusa', 'perm_azienda' => '1', 'perm_documenti' => '1', 'mansione' => 'X', 'corso' => 'Preposto', 'nuova_azienda' => $a );
		foreach ( $admin_ops as $op ) {
			$msg = $s->op( $op, $campi, $a );
			T::eq( 'denied', $msg, "operazione dello studio «{$op}» eseguita da un cliente" );
		}
		T::eq( $before, Db::fingerprint( $tabs ), 'dati del plugin cambiati' );
		T::eq( $users, Db::val( 'SELECT CONCAT(COUNT(*), "-", MAX(ID)) FROM wp_users' ), 'utenti cambiati' );
		T::eq( $meta, Db::val( "SELECT COUNT(*) FROM wp_usermeta WHERE meta_key IN ('apac_revocato','apac_azienda_id','apac_accesso')" ), 'metadati utenti cambiati' );
	}
);

T::test(
	'operazioni sconosciute o funzioni interne apac_op_*: rifiutate senza eseguire nulla',
	function () {
		$a     = Fx::azienda( 'OpX ' . bin2hex( random_bytes( 3 ) ) );
		$s     = Fx::admin();
		$invii = Db::val( 'SELECT COUNT(*) FROM wp_apac_invii' );
		foreach ( array( 'inesistente', 'permission', 'section' ) as $op ) {
			$msg = $s->op( $op, array(), $a );
			T::eq( 'invalid', $msg, "operazione «{$op}»" );
		}
		// Funzione apac_op_* esistente ma NON registrata, con effetto visibile: non deve essere eseguita.
		Wp::delete_option( 'apac_test_eseguita' );
		T::eq( 'invalid', $s->op( 'test_non_registrata', array(), $a ), 'operazione non registrata' );
		T::eq( '', Wp::php( 'echo get_option("apac_test_eseguita", "");' ), 'operazione non registrata eseguita' );
		// Nessun codice monouso registrato per un'operazione sconosciuta.
		T::eq( $invii, Db::val( 'SELECT COUNT(*) FROM wp_apac_invii' ), 'codici monouso registrati' );
	}
);

T::test(
	'nonce: un\'operazione con il codice di sicurezza di un\'altra operazione è rifiutata',
	function () {
		$a = Fx::azienda( 'Nonce ' . bin2hex( random_bytes( 3 ) ) );
		$s = Fx::admin();
		$r = $s->post(
			T::url(),
			array(
				'action'      => 'apac_action',
				'op'          => 'azienda_delete',
				'azienda_id'  => (string) $a,
				'conferma'    => 'ELIMINA',
				'_apac_nonce' => $s->nonce( 'apac_segnalazione_create' ),
				'_apac_once'  => bin2hex( random_bytes( 10 ) ),
			)
		);
		T::eq( 403, $r['status'], 'stato HTTP' );
		T::eq( '1', Db::val( "SELECT COUNT(*) FROM wp_apac_aziende WHERE id = {$a}" ), 'azienda eliminata' );
	}
);

T::test(
	'doppio invio: lo stesso modulo inviato due volte crea un solo record',
	function () {
		$a    = Fx::azienda( 'Dup ' . bin2hex( random_bytes( 3 ) ) );
		$s    = Fx::admin();
		$data = array(
			'action'      => 'apac_action',
			'op'          => 'scadenza_save',
			'azienda_id'  => (string) $a,
			'_apac_nonce' => $s->nonce( 'apac_scadenza_save' ),
			'_apac_once'  => bin2hex( random_bytes( 10 ) ),
			'titolo'      => 'Doppio',
			'categoria'   => 'dvr',
			'data_scadenza' => '2027-05-05',
		);
		T::eq( 'saved', Http::msg( $s->post( T::url(), $data ) ), 'primo invio' );
		T::eq( 'already_done', Http::msg( $s->post( T::url(), $data ) ), 'secondo invio' );
		T::eq( '1', Db::val( "SELECT COUNT(*) FROM wp_apac_scadenze WHERE azienda_id = {$a}" ), 'scadenze create' );
	}
);
