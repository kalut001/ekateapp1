<?php
/**
 * Download dei documenti: contenuto, intestazioni, link non validi, utenti non collegati.
 */

T::test(
	'download: il proprio documento si scarica con intestazioni sicure',
	function () {
		$a = Fx::azienda( 'Down ' . bin2hex( random_bytes( 3 ) ) );
		$c = Fx::cliente( $a );
		$contenuto = "%PDF-1.4\n% contenuto di prova " . bin2hex( random_bytes( 4 ) ) . "\n";
		list( $doc ) = Fx::documento( $a, 1, $contenuto );
		$s = Fx::login( $c['email'], $c['pass'] );
		$n = $s->nonce( 'apac_file_' . $doc );
		$r = $s->get( T::url( array( 'apac_file' => $doc, '_wpnonce' => $n ) ) );
		T::eq( 200, $r['status'], 'stato HTTP' );
		T::eq( $contenuto, $r['body'], 'contenuto del file' );
		T::eq( 'application/pdf', $r['headers']['content-type'] ?? '', 'Content-Type' );
		T::eq( 'nosniff', $r['headers']['x-content-type-options'] ?? '', 'X-Content-Type-Options' );
		T::ok( 0 === strpos( $r['headers']['content-disposition'] ?? '', 'attachment;' ), 'Content-Disposition', $r['headers'] );
		T::ok( false !== stripos( $r['headers']['cache-control'] ?? '', 'no-store' ) || false !== stripos( $r['headers']['cache-control'] ?? '', 'no-cache' ), 'Cache-Control', $r['headers'] );
		$r = $s->get( T::url( array( 'apac_file' => $doc, '_wpnonce' => $n, 'mode' => 'view' ) ) );
		T::ok( 0 === strpos( $r['headers']['content-disposition'] ?? '', 'inline;' ), 'visualizzazione nel browser per i PDF', $r['headers'] );
	}
);

T::test(
	'download: nonce errato, nonce di un altro documento e utente non collegato rifiutati',
	function () {
		$a = Fx::azienda( 'Down2 ' . bin2hex( random_bytes( 3 ) ) );
		$c = Fx::cliente( $a );
		list( $d1 ) = Fx::documento( $a );
		list( $d2 ) = Fx::documento( $a );
		$s = Fx::login( $c['email'], $c['pass'] );
		T::eq( 403, $s->get( T::url( array( 'apac_file' => $d1, '_wpnonce' => 'abcdef1234' ) ) )['status'], 'nonce errato' );
		T::eq( 403, $s->get( T::url( array( 'apac_file' => $d1, '_wpnonce' => $s->nonce( 'apac_file_' . $d2 ) ) ) )['status'], 'nonce di un altro documento' );
		$anon = new Http();
		T::eq( 403, $anon->get( T::url( array( 'apac_file' => $d1, '_wpnonce' => $s->nonce( 'apac_file_' . $d1 ) ) ) )['status'], 'utente non collegato' );
		T::eq( 403, $anon->get( T::$env['url'] . '/wp-admin/admin-post.php?action=apac_file&id=' . $d1 )['status'], 'utente non collegato (admin-post.php)' );
	}
);

T::test(
	'download: lo studio scarica i documenti di qualsiasi azienda',
	function () {
		$a = Fx::azienda( 'Down3 ' . bin2hex( random_bytes( 3 ) ) );
		list( $doc ) = Fx::documento( $a );
		$s = Fx::admin();
		$r = $s->get( T::url( array( 'apac_file' => $doc, '_wpnonce' => $s->nonce( 'apac_file_' . $doc ) ) ) );
		T::eq( 200, $r['status'], 'download dello studio' );
	}
);

T::test(
	'download: un percorso che esce dalla cartella protetta non viene servito',
	function () {
		$a = Fx::azienda( 'Down4 ' . bin2hex( random_bytes( 3 ) ) );
		$c = Fx::cliente( $a );
		Db::q( "INSERT INTO wp_apac_documenti (azienda_id, titolo, categoria, nome_file, file_path, mime, dimensione, caricato_da, created_at) VALUES ({$a}, 'X', 'altro', 'x.php', '../../wp/wp-config.php', 'application/pdf', 1, 1, NOW())" );
		$doc = (int) Db::c()->insert_id;
		$s   = Fx::login( $c['email'], $c['pass'] );
		$r   = $s->get( T::url( array( 'apac_file' => $doc, '_wpnonce' => $s->nonce( 'apac_file_' . $doc ) ) ) );
		T::eq( 404, $r['status'], 'percorso fuori cartella' );
		T::ok( false === strpos( $r['body'], 'DB_PASSWORD' ), 'contenuto di wp-config.php esposto' );
	}
);
