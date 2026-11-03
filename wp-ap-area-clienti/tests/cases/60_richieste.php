<?php
/**
 * Richieste di accesso (modulo pubblico, conferma via email, approvazione dello studio).
 */

function apac_t_rich_reset() {
	Db::q( 'DELETE FROM wp_apac_richieste' );
	Db::q( "DELETE FROM wp_options WHERE option_name LIKE '%apac_rich_%'" );
	Wp::clear_mails();
}

function apac_t_piva( $base10 ) {
	$s = 0;
	for ( $i = 0; $i < 10; $i++ ) {
		$d = (int) $base10[ $i ];
		if ( 1 === $i % 2 ) {
			$d *= 2;
			if ( $d > 9 ) {
				$d -= 9;
			}
		}
		$s += $d;
	}
	return $base10 . ( ( 10 - $s % 10 ) % 10 );
}

/** Codice del modulo firmato come se fosse stato aperto 10 secondi fa. */
function apac_t_rt() {
	$t = (string) ( time() - 10 );
	return $t . '.' . hash_hmac( 'sha256', 'apac_rich|' . $t, 'test-k4test-s4' );
}

function apac_t_richiesta( $email, array $extra = array(), array $headers = array() ) {
	$r = ( new Http() )->post(
		T::url(),
		array_merge(
			array(
				'apac_richiesta'  => '1',
				'rt'              => apac_t_rt(),
				'sito_web'        => '',
				'tipo'            => 'nuova',
				'ragione_sociale' => 'Ditta Test Srl',
				'partita_iva'     => apac_t_piva( '0123456789' ),
				'comune'          => 'Palermo',
				'ref_nome'        => 'Mario',
				'ref_cognome'     => 'Rossi',
				'ref_email'       => $email,
				'ref_ruolo'       => 'datore',
				'privacy'         => '1',
				'autorizzato'     => '1',
			),
			$extra
		),
		$headers
	);
	return preg_match( '/accesso=([a-z_]+)/', $r['headers']['location'] ?? '', $m ) ? $m[1] : 'HTTP' . $r['status'];
}

function apac_t_conferma( $id, $token ) {
	$r = ( new Http() )->post( T::url(), array( 'apac_rich_conferma' => '1', 'r' => $id, 't' => $token ) );
	return preg_match( '/accesso=([a-z_]+)/', $r['headers']['location'] ?? '', $m ) ? $m[1] : 'HTTP' . $r['status'];
}

/** @return array<int, array{0:int,1:string}> link di conferma inviati a $email */
function apac_t_link( $email ) {
	$out = array();
	foreach ( Wp::mails() as $m ) {
		if ( $m->to === $email && preg_match( '/r=(\d+)&t=([A-Za-z0-9]+)/', $m->message, $g ) ) {
			$out[] = array( (int) $g[1], $g[2] );
		}
	}
	return $out;
}

function apac_t_rich_insert( $stato, $n ) {
	for ( $i = 0; $i < $n; $i++ ) {
		Db::q( "INSERT INTO wp_apac_richieste (stato,tipo,ragione_sociale,partita_iva,dati,nome,cognome,email,telefono,ruolo,messaggio,token_hash,ip_hash,inviata_at,created_at,confermata_at) VALUES ('{$stato}','nuova','R{$i}','','','a','b','r{$i}." . bin2hex( random_bytes( 3 ) ) . "@example.com','','','','x','y',NOW(),NOW(),NOW())" );
	}
}

T::test(
	'richieste: 60 richieste mai confermate non sospendono il modulo; 50 confermate sì',
	function () {
		apac_t_rich_reset();
		apac_t_rich_insert( 'da_confermare', 60 );
		T::eq( 'inviata', apac_t_richiesta( 'legit1@example.com' ), 'con 60 non confermate' );
		apac_t_rich_reset();
		apac_t_rich_insert( 'in_attesa', 50 );
		T::eq( 'sospeso', apac_t_richiesta( 'legit2@example.com' ), 'con 50 confermate' );
		apac_t_rich_reset();
	}
);

T::test(
	'richieste: massimo 10 invii l\'ora per indirizzo IP, anche con invii contemporanei',
	function () {
		apac_t_rich_reset();
		$mh  = curl_multi_init();
		$chs = array();
		for ( $i = 0; $i < 24; $i++ ) {
			$ch = curl_init( T::url() );
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_HEADER         => true,
					CURLOPT_POST           => true,
					CURLOPT_POSTFIELDS     => http_build_query( array( 'apac_richiesta' => '1', 'rt' => apac_t_rt(), 'tipo' => 'nuova', 'ragione_sociale' => 'P', 'partita_iva' => apac_t_piva( '0123456789' ), 'comune' => 'Palermo', 'ref_nome' => 'A', 'ref_cognome' => 'B', 'ref_email' => "par{$i}@example.com", 'ref_ruolo' => 'datore', 'privacy' => '1', 'autorizzato' => '1' ) ),
				)
			);
			curl_multi_add_handle( $mh, $ch );
			$chs[] = $ch;
		}
		do {
			curl_multi_exec( $mh, $running );
			curl_multi_select( $mh );
		} while ( $running > 0 );
		$ok = 0;
		foreach ( $chs as $ch ) {
			$ok += (int) ( false !== strpos( (string) curl_multi_getcontent( $ch ), 'accesso=inviata' ) );
			curl_multi_remove_handle( $mh, $ch );
		}
		T::ok( $ok <= 10, 'invii accettati oltre il limite', $ok );
		T::eq( 'troppe', apac_t_richiesta( 'dopo@example.com', array(), array( 'CF-Connecting-IP: 203.0.113.9' ) ), 'intestazione del proxy ignorata senza APAC_IP_HEADER' );
		apac_t_rich_reset();
	}
);

T::test(
	'richieste: stessa email 5 volte = 3 email al massimo, senza testo del richiedente, link distinti',
	function () {
		apac_t_rich_reset();
		for ( $i = 0; $i < 5; $i++ ) {
			T::eq( 'inviata', apac_t_richiesta( 'vittima@example.com', array( 'ragione_sociale' => 'VISITA http://phishing.example', 'ref_nome' => 'CLICCA QUI' ) ), 'risposta a video' );
		}
		$mails = array_values( array_filter( Wp::mails(), function ( $m ) {
			return 'vittima@example.com' === $m->to;
		} ) );
		T::eq( 3, count( $mails ), 'email inviate alla stessa casella' );
		foreach ( $mails as $m ) {
			T::ok( false === strpos( $m->message, 'phishing' ) && false === strpos( $m->message, 'CLICCA' ), 'testo del richiedente nell\'email' );
		}
		$links = apac_t_link( 'vittima@example.com' );
		T::eq( 3, count( array_unique( array_map( 'json_encode', $links ) ) ), 'link distinti' );
		T::eq( 'confermata', apac_t_conferma( $links[1][0], $links[1][1] ), 'conferma del secondo link' );
		T::eq( 'in_attesa', Db::val( "SELECT GROUP_CONCAT(stato) FROM wp_apac_richieste WHERE email = 'vittima@example.com'" ), 'una sola richiesta in attesa' );
		T::eq( 'link_scaduto', apac_t_conferma( $links[0][0], $links[0][1] ), 'link di una richiesta superata' );
		T::eq( 'confermata', apac_t_conferma( $links[1][0], $links[1][1] ), 'seconda conferma dello stesso link' );
		T::eq( 1, count( array_filter( Wp::mails(), function ( $m ) {
			return 'admin@example.com' === $m->to;
		} ) ), 'notifiche allo studio' );
		apac_t_rich_reset();
	}
);

T::test(
	'richieste: approvazione verso un\'azienda esistente solo con scelta e verifica esplicite',
	function () {
		apac_t_rich_reset();
		$piva = apac_t_piva( '5555555555' );
		$az   = Fx::azienda( 'Cliente Esistente ' . bin2hex( random_bytes( 2 ) ) );
		Db::q( "UPDATE wp_apac_aziende SET partita_iva = '{$piva}' WHERE id = {$az}" );
		$email = 'estraneo' . bin2hex( random_bytes( 3 ) ) . '@example.com';
		apac_t_richiesta( $email, array( 'partita_iva' => $piva ) );
		list( list( $rid, $tok ) ) = apac_t_link( $email );
		apac_t_conferma( $rid, $tok );
		$s    = Fx::admin();
		$page = $s->get( T::url( array( 'sez' => 'richieste' ) ) )['body'];
		T::ok( (bool) preg_match_all( '/<input type="radio" name="destinazione"[^>]*>/', $page, $radios ), 'scelta della destinazione' );
		foreach ( $radios[0] as $radio ) {
			T::ok( false === strpos( $radio, 'checked' ), 'destinazione preselezionata', $radio );
		}
		$base = array( 'id' => $rid, 'ref_nome' => 'Ignoto', 'ref_email' => $email, 'accesso' => 'modifica', 'azienda_esistente' => $az );
		T::eq( 'need_dest', $s->op( 'richiesta_approva', $base ), 'senza scelta' );
		T::eq( 'need_verifica', $s->op( 'richiesta_approva', $base + array( 'destinazione' => 'esistente' ) ), 'senza verifica' );
		T::eq( 'in_attesa', Db::val( "SELECT stato FROM wp_apac_richieste WHERE id = {$rid}" ), 'richiesta ancora in attesa' );
		T::eq( '0', Db::val( "SELECT COUNT(*) FROM wp_users WHERE user_email = '{$email}'" ), 'utente creato senza verifica' );
		T::eq( 'user_created', $s->op( 'richiesta_approva', $base + array( 'destinazione' => 'esistente', 'verificato' => '1' ) ), 'con verifica' );
		$uid = Db::val( "SELECT ID FROM wp_users WHERE user_email = '{$email}'" );
		T::eq( (string) $az, Db::val( "SELECT meta_value FROM wp_usermeta WHERE user_id = {$uid} AND meta_key = 'apac_azienda_id'" ), 'azienda collegata' );
		apac_t_rich_reset();
	}
);
