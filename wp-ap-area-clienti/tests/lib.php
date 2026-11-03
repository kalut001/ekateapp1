<?php
/**
 * Mini-framework dei test end-to-end (nessuna dipendenza esterna).
 * I test parlano con un WordPress reale tramite HTTP e controllano il database direttamente.
 */

final class AssertionFailed extends Exception {}

final class T {
	/** @var array<int, array{0:string,1:callable}> */
	public static $tests = array();
	/** @var array<string,mixed> */
	public static $env = array();

	public static function test( $name, callable $fn ) {
		self::$tests[] = array( $name, $fn );
	}

	public static function ok( $cond, $msg, $info = null ) {
		if ( ! $cond ) {
			throw new AssertionFailed( $msg . ( null === $info ? '' : ' | ' . self::dump( $info ) ) );
		}
	}

	public static function eq( $expected, $actual, $msg ) {
		if ( $expected !== $actual ) {
			throw new AssertionFailed( $msg . ' | atteso ' . self::dump( $expected ) . ', ottenuto ' . self::dump( $actual ) );
		}
	}

	public static function dump( $v ) {
		$s = is_string( $v ) ? $v : json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return strlen( (string) $s ) > 400 ? substr( (string) $s, 0, 400 ) . '…' : (string) $s;
	}

	public static function url( $query = array() ) {
		$u = self::$env['url'] . '/area-riservata/';
		return $query ? $u . '?' . http_build_query( $query ) : $u;
	}
}

/* ------------------------------------------------------------------ Database */

final class Db {
	/** @var mysqli|null */
	private static $c;

	public static function c() {
		if ( ! self::$c ) {
			$d       = T::$env['db'];
			self::$c = new mysqli( $d['host'], $d['user'], $d['pass'], $d['name'], $d['port'] );
			self::$c->set_charset( 'utf8mb4' );
		}
		return self::$c;
	}

	public static function q( $sql ) {
		$r = self::c()->query( $sql );
		if ( false === $r ) {
			throw new RuntimeException( 'SQL: ' . self::c()->error . ' in ' . $sql );
		}
		return $r;
	}

	public static function val( $sql ) {
		$r   = self::q( $sql );
		$row = $r->fetch_row();
		return $row ? $row[0] : null;
	}

	public static function rows( $sql ) {
		return self::q( $sql )->fetch_all( MYSQLI_ASSOC );
	}

	public static function e( $s ) {
		return self::c()->real_escape_string( (string) $s );
	}

	/** Impronta del contenuto delle tabelle del plugin (per verificare che nulla sia cambiato). */
	public static function fingerprint( array $tables ) {
		$out = array();
		foreach ( $tables as $t ) {
			$out[ $t ] = self::rows( "CHECKSUM TABLE wp_apac_{$t}" )[0]['Checksum'];
		}
		return $out;
	}
}

/* ----------------------------------------------------------------- WordPress */

final class Wp {
	/** Esegue codice PHP con WordPress caricato e restituisce l'output. */
	public static function php( $code ) {
		$boot = sprintf(
			"\$_SERVER['HTTP_HOST']='%s';\$_SERVER['REQUEST_URI']='/';require '%s/wp-load.php';",
			parse_url( T::$env['url'], PHP_URL_HOST ) . ':' . parse_url( T::$env['url'], PHP_URL_PORT ),
			T::$env['wp_dir']
		);
		$cmd  = 'php -d opcache.enable_cli=0 -r ' . escapeshellarg( $boot . $code ) . ' 2>&1';
		$out  = shell_exec( $cmd );
		return trim( (string) $out );
	}

	public static function option( $name, $value ) {
		self::php( sprintf( 'update_option(%s, %s);', var_export( $name, true ), var_export( $value, true ) ) );
	}

	public static function delete_option( $name ) {
		self::php( sprintf( 'delete_option(%s);', var_export( $name, true ) ) );
	}

	public static function mails() {
		$f = T::$env['wp_dir'] . '/wp-content/apac-test-mails.jsonl';
		if ( ! file_exists( $f ) ) {
			return array();
		}
		return array_map( 'json_decode', array_filter( file( $f, FILE_IGNORE_NEW_LINES ) ) );
	}

	public static function clear_mails() {
		@unlink( T::$env['wp_dir'] . '/wp-content/apac-test-mails.jsonl' );
	}
}

/* ---------------------------------------------------------------------- HTTP */

final class Http {
	private $jar;

	public function __construct() {
		$this->jar = tempnam( sys_get_temp_dir(), 'apacjar' );
	}

	public function __destruct() {
		@unlink( $this->jar );
	}

	/**
	 * @return array{status:int, headers:array<string,string>, body:string}
	 */
	public function request( $method, $url, $data = null, array $headers = array() ) {
		$ch = curl_init( $url );
		$h  = array();
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_COOKIEJAR      => $this->jar,
				CURLOPT_COOKIEFILE     => $this->jar,
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_TIMEOUT        => 60,
				CURLOPT_HEADERFUNCTION => function ( $c, $line ) use ( &$h ) {
					$p = explode( ':', $line, 2 );
					if ( 2 === count( $p ) ) {
						$h[ strtolower( trim( $p[0] ) ) ] = trim( $p[1] );
					}
					return strlen( $line );
				},
			)
		);
		if ( 'POST' === $method ) {
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
		}
		$body   = (string) curl_exec( $ch );
		$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		curl_close( $ch );
		return array( 'status' => $status, 'headers' => $h, 'body' => $body );
	}

	public function get( $url, array $headers = array() ) {
		return $this->request( 'GET', $url, null, $headers );
	}

	public function post( $url, $data, array $headers = array() ) {
		return $this->request( 'POST', $url, $data, $headers );
	}

	public function nonce( $action ) {
		return trim( $this->get( T::$env['url'] . '/?apac_test_nonce=' . rawurlencode( $action ) )['body'] );
	}

	/** Invia un modulo dell'area riservata. Restituisce il codice apac_msg del reindirizzamento (o HTTP<n>). */
	public function op( $op, array $fields = array(), $azienda_id = 0 ) {
		$data = array_merge(
			array(
				'action'      => 'apac_action',
				'op'          => $op,
				'azienda_id'  => (string) $azienda_id,
				'_apac_nonce' => $this->nonce( 'apac_' . $op ),
				'_apac_once'  => bin2hex( random_bytes( 10 ) ),
			),
			$fields
		);
		$r = $this->post( T::url(), $data );
		return self::msg( $r );
	}

	public static function msg( array $r ) {
		$loc = isset( $r['headers']['location'] ) ? $r['headers']['location'] : '';
		if ( preg_match( '/[?&]apac_msg=([a-z_]+)/', $loc, $m ) ) {
			return $m[1];
		}
		return 'HTTP' . $r['status'];
	}
}

/* ------------------------------------------------------------------ Fixture */

final class Fx {
	public static function login( $user, $pass ) {
		$s    = new Http();
		$page = $s->get( T::url() )['body'];
		T::ok( (bool) preg_match( '/name="_apac_login_nonce" value="([^"]+)"/', $page, $m ), 'modulo di accesso non trovato' );
		$r = $s->post( T::url(), array( 'apac_login' => '1', '_apac_login_nonce' => $m[1], 'log' => $user, 'pwd' => $pass ) );
		T::ok( false === strpos( (string) ( $r['headers']['location'] ?? '' ), 'apac_login_error' ), 'accesso non riuscito per ' . $user, $r['headers'] );
		return $s;
	}

	public static function admin() {
		static $s = null;
		if ( ! $s ) {
			$s = self::login( 'admin', 'Adminpass123!' );
		}
		return $s;
	}

	/** Crea un'azienda; $perm = permessi del cliente. */
	public static function azienda( $nome, array $perm = array( 'azienda' => true, 'lavoratori' => true, 'scadenze' => true, 'documenti' => true ) ) {
		Db::q(
			sprintf(
				"INSERT INTO wp_apac_aziende (ragione_sociale, ragione_sort, permessi, created_at, updated_at) VALUES ('%s', '%s', '%s', NOW(), NOW())",
				Db::e( $nome ),
				Db::e( strtolower( $nome ) ),
				Db::e( json_encode( $perm ) )
			)
		);
		return (int) Db::c()->insert_id;
	}

	/**
	 * Crea un utente cliente collegato all'azienda. Opzioni: lettura (bool), firme (bool, default true).
	 * @return array{id:int, email:string, pass:string}
	 */
	public static function cliente( $azienda_id, array $opt = array() ) {
		$email = 'c' . bin2hex( random_bytes( 4 ) ) . '@example.com';
		$pass  = 'Pw-' . bin2hex( random_bytes( 6 ) );
		$id    = (int) Wp::php(
			sprintf(
				"\$id = wp_insert_user(array('user_login'=>'%1\$s','user_email'=>'%1\$s','user_pass'=>'%2\$s','role'=>APAC_ROLE,'first_name'=>'Test'));"
				. "update_user_meta(\$id,'apac_azienda_id',%3\$d); update_user_meta(\$id,'apac_accesso','%4\$s'); echo \$id;",
				$email,
				$pass,
				(int) $azienda_id,
				empty( $opt['lettura'] ) ? 'modifica' : 'lettura'
			)
		);
		T::ok( $id > 0, 'creazione cliente non riuscita' );
		if ( ! isset( $opt['firme'] ) || $opt['firme'] ) {
			self::firma_tutto( $id, $azienda_id );
		}
		return array( 'id' => $id, 'email' => $email, 'pass' => $pass );
	}

	public static function firma_tutto( $user_id, $azienda_id ) {
		Wp::php(
			sprintf(
				"global \$wpdb; foreach ( apac_consensi_pending(%1\$d) as \$tipo => \$t ) { \$wpdb->insert( apac_table('consensi'), array('user_id'=>%1\$d,'azienda_id'=>%2\$d,'tipo'=>\$tipo,'versione'=>apac_testo_versione(\$t),'created_at'=>current_time('mysql')) ); }",
				(int) $user_id,
				(int) $azienda_id
			)
		);
	}

	public static function lavoratore( $azienda_id, $cognome = 'Rossi' ) {
		Db::q( sprintf( "INSERT INTO wp_apac_lavoratori (azienda_id, nome, cognome, attivo, created_at) VALUES (%d, 'Mario', '%s', 1, NOW())", (int) $azienda_id, Db::e( $cognome ) ) );
		return (int) Db::c()->insert_id;
	}

	public static function attestato( $azienda_id, $lav_id ) {
		Db::q( sprintf( "INSERT INTO wp_apac_attestati (azienda_id, lavoratore_id, corso, data_conseguimento, data_scadenza, created_at) VALUES (%d, %d, 'Preposto', '2025-01-10', '2027-01-10', NOW())", (int) $azienda_id, (int) $lav_id ) );
		return (int) Db::c()->insert_id;
	}

	public static function scadenza( $azienda_id ) {
		Db::q( sprintf( "INSERT INTO wp_apac_scadenze (azienda_id, titolo, categoria, data_scadenza, stato, avviso_inviato, created_at) VALUES (%d, 'Revisione DVR', 'dvr', '2027-03-01', 'aperta', -1, NOW())", (int) $azienda_id ) );
		return (int) Db::c()->insert_id;
	}

	/** Documento con file reale nella cartella protetta. */
	public static function documento( $azienda_id, $caricato_da = 1, $contenuto = "%PDF-1.4\n% test\n" ) {
		$rel = (int) $azienda_id . '/t' . bin2hex( random_bytes( 6 ) ) . '.pdf';
		$abs = Wp::php( 'echo apac_private_dir();' ) . '/' . $rel;
		@mkdir( dirname( $abs ), 0755, true );
		file_put_contents( $abs, $contenuto );
		Db::q(
			sprintf(
				"INSERT INTO wp_apac_documenti (azienda_id, titolo, categoria, nome_file, file_path, mime, dimensione, caricato_da, created_at) VALUES (%d, 'Doc', 'altro', 'doc.pdf', '%s', 'application/pdf', %d, %d, NOW())",
				(int) $azienda_id,
				Db::e( $rel ),
				strlen( $contenuto ),
				(int) $caricato_da
			)
		);
		return array( (int) Db::c()->insert_id, $abs );
	}
}
