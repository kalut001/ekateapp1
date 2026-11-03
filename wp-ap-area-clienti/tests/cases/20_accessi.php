<?php
/**
 * Utenti revocati, accesso in sola consultazione, firme del primo accesso mancanti.
 */

T::test(
	'revoca: con accesso revocato (anche con sessione ancora aperta) nessuna operazione e nessun download',
	function () {
		$a = Fx::azienda( 'Revoca ' . bin2hex( random_bytes( 3 ) ) );
		$c = Fx::cliente( $a );
		list( $doc ) = Fx::documento( $a );
		$s     = Fx::login( $c['email'], $c['pass'] );
		$nonce = $s->nonce( 'apac_file_' . $doc );
		$apac  = $s->nonce( 'apac_lavoratore_save' );
		// Revoca scritta direttamente: la sessione del cliente resta valida, deve bloccare il plugin.
		Wp::php( "update_user_meta({$c['id']}, 'apac_revocato', 1);" );
		$before = Db::fingerprint( array( 'lavoratori' ) );
		$r      = $s->post( T::url(), array( 'action' => 'apac_action', 'op' => 'lavoratore_save', '_apac_nonce' => $apac, '_apac_once' => bin2hex( random_bytes( 10 ) ), 'nome' => 'A', 'cognome' => 'B' ) );
		T::eq( 403, $r['status'], 'operazione da utente revocato' );
		T::eq( $before, Db::fingerprint( array( 'lavoratori' ) ), 'dati cambiati' );
		T::eq( 403, $s->get( T::url( array( 'apac_file' => $doc, '_wpnonce' => $nonce ) ) )['status'], 'download da utente revocato' );
		$r = $s->get( T::url( array( 'apac_export' => 'csv', '_wpnonce' => $s->nonce( 'apac_export_0' ) ) ) );
		T::eq( 403, $r['status'], 'esportazione da utente revocato' );
		$page = $s->get( T::url( array( 'sez' => 'lavoratori' ) ) )['body'];
		T::ok( false === strpos( $page, 'name="op" value="lavoratore_save"' ), "l'utente revocato vede ancora i moduli" );
	}
);

T::test(
	'revoca dallo studio: le sessioni aperte del cliente vengono chiuse',
	function () {
		$a = Fx::azienda( 'Revoca2 ' . bin2hex( random_bytes( 3 ) ) );
		$c = Fx::cliente( $a );
		$s = Fx::login( $c['email'], $c['pass'] );
		T::ok( '' !== $s->nonce( 'x' ), 'sessione cliente' );
		T::eq( 'user_revoked', Fx::admin()->op( 'utente_revoca', array( 'user_id' => $c['id'] ) ), 'revoca' );
		T::eq( '1', Db::val( "SELECT meta_value FROM wp_usermeta WHERE user_id = {$c['id']} AND meta_key = 'apac_revocato'" ), 'revoca registrata' );
		$r = $s->get( T::url( array( 'sez' => 'dashboard' ) ) );
		T::ok( false !== strpos( $r['body'], 'name="apac_login"' ), 'dopo la revoca il cliente deve vedere di nuovo il modulo di accesso' );
	}
);

T::test(
	'sola consultazione: niente modifiche; segnalazioni consentite',
	function () {
		$a = Fx::azienda( 'Lettura ' . bin2hex( random_bytes( 3 ) ) );
		$c = Fx::cliente( $a, array( 'lettura' => true ) );
		$l = Fx::lavoratore( $a );
		list( $doc ) = Fx::documento( $a, $c['id'] );
		$s      = Fx::login( $c['email'], $c['pass'] );
		$tabs   = array( 'lavoratori', 'attestati', 'scadenze', 'documenti', 'aziende', 'richieste' );
		$before = Db::fingerprint( $tabs );
		foreach (
			array(
				array( 'lavoratore_save', array( 'nome' => 'A', 'cognome' => 'B' ) ),
				array( 'lavoratore_delete', array( 'id' => $l ) ),
				array( 'scadenza_save', array( 'titolo' => 'T', 'categoria' => 'dvr', 'data_scadenza' => '2027-01-01' ) ),
				array( 'documento_delete', array( 'id' => $doc ) ),
				array( 'azienda_save', array( 'ragione_sociale' => 'Cambiata' ) ),
				array( 'richiesta_collega', array( 'nome' => 'A', 'cognome' => 'B', 'email' => 'collega@example.com' ) ),
			) as $caso
		) {
			$msg = $s->op( $caso[0], $caso[1], $a );
			T::ok( in_array( $msg, array( 'denied', 'readonly' ), true ), $caso[0] . ' in sola consultazione non rifiutata', $msg );
		}
		T::eq( $before, Db::fingerprint( $tabs ), 'dati cambiati' );
		T::eq( 'sent', $s->op( 'segnalazione_create', array( 'titolo' => 'Correzione', 'priorita' => 'media' ), $a ), 'segnalazione in sola consultazione' );
	}
);

T::test(
	'permessi per azienda: senza il permesso "documenti" il cliente non elimina documenti',
	function () {
		$a = Fx::azienda( 'Perm ' . bin2hex( random_bytes( 3 ) ), array( 'azienda' => false, 'lavoratori' => true, 'scadenze' => true, 'documenti' => false ) );
		$c = Fx::cliente( $a );
		list( $doc ) = Fx::documento( $a, $c['id'] );
		$s = Fx::login( $c['email'], $c['pass'] );
		T::eq( 'denied', $s->op( 'documento_delete', array( 'id' => $doc ), $a ), 'eliminazione senza permesso' );
		T::eq( 'denied', $s->op( 'azienda_save', array( 'ragione_sociale' => 'X' ), $a ), 'scheda azienda senza permesso' );
		T::eq( '1', Db::val( "SELECT COUNT(*) FROM wp_apac_documenti WHERE id = {$doc}" ), 'documento eliminato' );
	}
);

T::test(
	'documenti: il cliente elimina solo quelli caricati da lui',
	function () {
		$a = Fx::azienda( 'Propri ' . bin2hex( random_bytes( 3 ) ) );
		$c = Fx::cliente( $a );
		list( $suo ) = Fx::documento( $a, $c['id'] );
		list( $studio ) = Fx::documento( $a, 1 );
		$s = Fx::login( $c['email'], $c['pass'] );
		T::eq( 'denied', $s->op( 'documento_delete', array( 'id' => $studio ), $a ), 'documento dello studio' );
		T::eq( 'deleted', $s->op( 'documento_delete', array( 'id' => $suo ), $a ), 'documento proprio' );
	}
);

T::test(
	'firme del primo accesso mancanti: operazioni e download bloccati, firma consentita',
	function () {
		$a = Fx::azienda( 'Firme ' . bin2hex( random_bytes( 3 ) ) );
		$c = Fx::cliente( $a, array( 'firme' => false ) );
		list( $doc ) = Fx::documento( $a );
		T::ok( (int) Wp::php( "echo count(apac_consensi_pending({$c['id']}));" ) > 0, 'il cliente di prova deve avere firme in sospeso' );
		$s = Fx::login( $c['email'], $c['pass'] );
		T::eq( 'consent_needed', $s->op( 'lavoratore_save', array( 'nome' => 'A', 'cognome' => 'B' ), $a ), 'operazione con firme mancanti' );
		T::eq( 'consent_needed', $s->op( 'segnalazione_create', array( 'titolo' => 'T', 'priorita' => 'media' ), $a ), 'segnalazione con firme mancanti' );
		T::eq( 403, $s->get( T::url( array( 'apac_file' => $doc, '_wpnonce' => $s->nonce( 'apac_file_' . $doc ) ) ) )['status'], 'download con firme mancanti' );
		T::eq( 403, $s->get( T::url( array( 'apac_report' => 1 ) ) )['status'], 'riepilogo con firme mancanti' );
		$msg = $s->op( 'consenso_firma', array( 'firma_nome' => '' ), $a );
		T::ok( 'consent_needed' !== $msg && 'denied' !== $msg, 'la firma stessa deve restare possibile', $msg );
	}
);
