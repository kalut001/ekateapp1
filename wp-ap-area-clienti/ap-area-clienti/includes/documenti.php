<?php
/**
 * Documenti: versioni precedenti, download dei file secondari, presa visione firmata.
 *
 * 1.9.15: file per area; il codice proviene dai file per versione (session1-3.php, v190.php), invariato.
 */
defined( 'ABSPATH' ) || exit;

/** Archivia il file attuale di un documento come versione precedente (al posto di cancellarlo). */
function apac_archivia_versione( $doc ) {
	apac_must_insert(
		'documenti_versioni',
		array(
			'documento_id'  => (int) $doc->id,
			'azienda_id'    => (int) $doc->azienda_id,
			'nome_file'     => $doc->nome_file,
			'file_path'     => $doc->file_path,
			'mime'          => $doc->mime,
			'dimensione'    => (int) $doc->dimensione,
			'caricato_da'   => (int) $doc->caricato_da,
			'created_at'    => $doc->created_at,
			'sostituito_at' => apac_now(),
		),
		'documenti'
	);
}

function apac_get_versioni( $doc_id, $azienda_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'documenti_versioni' ) . ' WHERE documento_id = %d AND azienda_id = %d ORDER BY id DESC', $doc_id, $azienda_id ) );
}

/** Link protetto a un file secondario (versione precedente o foto). */
function apac_extra_file_url( $kind, $id, $mode = 'download' ) {
	return wp_nonce_url(
		add_query_arg(
			array(
				'apac_' . $kind => (int) $id,
				'mode'          => 'view' === $mode ? 'view' : 'download',
			),
			apac_portal_page_url()
		),
		'apac_' . $kind . '_' . (int) $id
	);
}

add_action( 'init', 'apac_serve_extra_files', 19 );

function apac_serve_extra_files() {
	global $wpdb;
	if ( is_admin() ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification
	foreach ( array( 'ver' => 'documenti_versioni', 'foto' => 'segnalazioni_foto' ) as $kind => $table ) {
		if ( ! isset( $_GET[ 'apac_' . $kind ] ) ) {
			continue;
		}
		$id    = absint( $_GET[ 'apac_' . $kind ] );
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! is_user_logged_in() || ! $id || ! wp_verify_nonce( $nonce, 'apac_' . $kind . '_' . $id ) ) {
			wp_die( 'Link non valido o scaduto. Torna all’area clienti e riprova.', 'Accesso negato', array( 'response' => 403 ) );
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . apac_table( $table ) . ' WHERE id = %d', $id ) );
		if ( ! $row || ! apac_can_access_azienda( (int) $row->azienda_id ) ) {
			wp_die( 'File non disponibile.', 'Accesso negato', array( 'response' => 403 ) );
		}
		apac_require_consents_for_download();
		$path = apac_document_abs_path( $row );
		if ( ! $path || ! is_readable( $path ) ) {
			wp_die( 'File non trovato sul server.', 'Errore', array( 'response' => 404 ) );
		}
		$inline = isset( $_GET['mode'] ) && 'view' === $_GET['mode'] && in_array( $row->mime, array( 'application/pdf', 'image/jpeg', 'image/png' ), true );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		$filename = str_replace( array( '"', "\r", "\n" ), '', $row->nome_file );
		nocache_headers();
		header( 'Content-Type: ' . $row->mime );
		header( 'Content-Length: ' . filesize( $path ) );
		apac_download_header( $filename, $inline ? 'inline' : 'attachment' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
	// phpcs:enable
}

/** Rimozione dei file secondari collegati (dopo la conferma della transazione). */
function apac_delete_rows_files_after_commit( $rows ) {
	foreach ( (array) $rows as $r ) {
		apac_tx_after_commit(
			function () use ( $r ) {
				apac_delete_document_file( $r );
			}
		);
	}
}

/** Versioni precedenti di un documento (sostituzioni del file). */
function apac_render_versioni( $d ) {
	$vers = apac_get_versioni( (int) $d->id, (int) $d->azienda_id );
	if ( ! $vers ) {
		return;
	}
	echo '<details class="apac-edit apac-versions"><summary class="apac-btn apac-btn--small apac-btn--ghost">' . apac_icon( 'history' ) . '<span>Versioni (' . count( $vers ) . ')</span></summary><ul class="apac-versions__list">'; // phpcs:ignore WordPress.Security.EscapeOutput
	foreach ( $vers as $v ) {
		echo '<li><span>' . esc_html( $v->nome_file ) . '<small>Caricata il ' . esc_html( apac_date_it( $v->created_at ) ) . ' · sostituita il ' . esc_html( apac_date_it( $v->sostituito_at ) ) . '</small></span><a class="apac-btn apac-btn--small apac-btn--ghost" href="' . esc_url( apac_extra_file_url( 'ver', $v->id, 'download' ) ) . '">Scarica</a></li>';
	}
	echo '</ul></details>';
}

/** Firma dell'utente sulla versione attuale del documento (o null). */
function apac_firma_utente( $doc, $user_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'firme' ) . ' WHERE documento_id = %d AND user_id = %d ORDER BY id DESC LIMIT 1', $doc->id, $user_id ) );
}

/** Impronta SHA-256 del file attuale del documento. */
function apac_doc_sha( $doc ) {
	$p = apac_document_abs_path( $doc );
	return $p ? (string) hash_file( 'sha256', $p ) : '';
}

/** La firma riguarda il file attuale? */
function apac_firma_attuale( $firma, $doc ) {
	return $firma && (string) $firma->nome_file === (string) $doc->nome_file && hash_equals( (string) $firma->sha256, apac_doc_sha( $doc ) );
}

/** Documenti che l'utente corrente deve ancora firmare. */
function apac_documenti_da_firmare( $azienda_id, $user_id = 0 ) {
	global $wpdb;
	$user_id = $user_id ? $user_id : get_current_user_id();
	if ( user_can( $user_id, 'manage_options' ) ) {
		return array();
	}
	$out = array();
	foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'documenti' ) . ' WHERE azienda_id = %d AND richiede_firma = 1 ORDER BY created_at DESC', $azienda_id ) ) as $d ) {
		if ( ! apac_firma_attuale( apac_firma_utente( $d, $user_id ), $d ) ) {
			$out[] = $d;
		}
	}
	return $out;
}

/** Controllo dell'immagine della firma (PNG dal riquadro di disegno). @return string|false PNG binario */
function apac_firma_png( $data_url ) {
	if ( ! is_string( $data_url ) || 0 !== strpos( $data_url, 'data:image/png;base64,' ) || strlen( $data_url ) > 400000 ) {
		return false;
	}
	$bin = base64_decode( substr( $data_url, 22 ), true );
	if ( false === $bin || "\x89PNG\r\n\x1a\n" !== substr( $bin, 0, 8 ) ) {
		return false;
	}
	$info = function_exists( 'getimagesizefromstring' ) ? getimagesizefromstring( $bin ) : false;
	if ( ! $info || IMAGETYPE_PNG !== $info[2] || $info[0] < 150 || $info[1] < 60 || $info[0] > 3000 || $info[1] > 2000 ) {
		return false;
	}
	// Riquadro vuoto: nessun tratto disegnato.
	if ( function_exists( 'imagecreatefromstring' ) ) {
		$im = @imagecreatefromstring( $bin ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $im ) {
			$w     = imagesx( $im );
			$h     = imagesy( $im );
			$ink   = 0;
			$stepx = max( 1, (int) ( $w / 120 ) );
			$stepy = max( 1, (int) ( $h / 60 ) );
			for ( $x = 0; $x < $w; $x += $stepx ) {
				for ( $y = 0; $y < $h; $y += $stepy ) {
					$c = imagecolorsforindex( $im, imagecolorat( $im, $x, $y ) );
					if ( $c['alpha'] < 100 && ( $c['red'] + $c['green'] + $c['blue'] ) < 600 ) {
						$ink++;
					}
				}
			}
			imagedestroy( $im );
			if ( $ink < 8 ) {
				return false;
			}
		}
	}
	return $bin;
}

function apac_op_documento_firma( $azienda_id ) {
	global $wpdb;
	$id   = absint( apac_post( 'id' ) );
	$doc  = apac_get_row_for_azienda( 'documenti', $id, $azienda_id );
	$back = array( 'firma' => $id );
	if ( ! $doc || ! (int) $doc->richiede_firma || apac_is_admin() ) {
		apac_redirect( 'documenti', 'denied' );
	}
	$nome = trim( sanitize_text_field( apac_post( 'firma_nome' ) ) );
	if ( '' === $nome || mb_strlen( $nome ) > 150 ) {
		apac_error_field( 'firma_nome' );
		apac_redirect( 'documenti', '' === $nome ? 'need_field' : 'too_long', $back );
	}
	if ( ! apac_post( 'firma_ok' ) ) {
		apac_error_field( 'firma_ok' );
		apac_redirect( 'documenti', 'need_consent', $back );
	}
	$png = apac_firma_png( (string) apac_post( 'firma_img' ) );
	if ( false === $png ) {
		apac_error_field( 'firma_img' );
		apac_redirect( 'documenti', 'bad_signature', $back );
	}
	$sha = apac_doc_sha( $doc );
	if ( '' === $sha ) {
		apac_redirect( 'documenti', 'db_error', $back );
	}
	$uid = get_current_user_id();
	if ( apac_firma_attuale( apac_firma_utente( $doc, $uid ), $doc ) ) {
		apac_redirect( 'documenti', 'signed' );
	}
	$u = wp_get_current_user();
	apac_must_insert(
		'firme',
		array(
			'documento_id' => (int) $doc->id,
			'azienda_id'   => (int) $azienda_id,
			'user_id'      => $uid,
			'nome'         => $nome,
			'email'        => $u->user_email,
			'firma'        => 'data:image/png;base64,' . base64_encode( $png ),
			'sha256'       => $sha,
			'nome_file'    => $doc->nome_file,
			'ip'           => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'user_agent'   => isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 250 ) : '',
			'created_at'   => apac_now(),
		),
		'documenti',
		$back
	);
	$az = apac_get_azienda( $azienda_id );
	apac_push_after_commit(
		function () use ( $az, $doc, $nome ) {
			apac_push_admins( 'Presa visione firmata', $nome . ' (' . $az->ragione_sociale . '): ' . $doc->titolo, apac_url( 'documenti', array( 'azienda' => (int) $az->id ) ) );
		}
	);
	apac_log_detail( $doc->titolo . ' – ' . $nome );
	apac_redirect( 'documenti', 'signed' );
}

function apac_firma_testo( $doc ) {
	return 'Dichiaro di aver ricevuto e preso visione del documento «' . $doc->titolo . '» (file ' . $doc->nome_file . ').';
}

/** Pulsante e modulo di firma per il cliente, oppure elenco delle firme per lo studio. */
function apac_render_firma_cell( $d, $azienda_id ) {
	global $wpdb;
	if ( ! (int) $d->richiede_firma ) {
		return;
	}
	// phpcs:disable WordPress.Security.EscapeOutput
	if ( apac_is_admin() ) {
		$firme = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'firme' ) . ' WHERE documento_id = %d AND azienda_id = %d ORDER BY id DESC', $d->id, $azienda_id ) );
		$sha   = apac_doc_sha( $d );
		$att   = 0;
		foreach ( $firme as $f ) {
			$att += ( $f->nome_file === $d->nome_file && hash_equals( (string) $f->sha256, $sha ) ) ? 1 : 0;
		}
		echo '<details class="apac-edit apac-signs"><summary class="apac-btn apac-btn--small apac-btn--ghost">' . apac_icon_extra( 'pen' ) . '<span>Firme (' . (int) $att . ')</span></summary>';
		if ( ! $firme ) {
			echo '<p class="apac-muted">Nessuna firma ancora.</p>';
		} else {
			echo '<ul class="apac-signs__list">';
			foreach ( $firme as $f ) {
				$cur = $f->nome_file === $d->nome_file && hash_equals( (string) $f->sha256, $sha );
				echo '<li><img src="' . esc_attr( $f->firma ) . '" alt="Firma di ' . esc_attr( $f->nome ) . '" width="120" height="48"><span><strong>' . esc_html( $f->nome ) . '</strong><small>' . esc_html( apac_date_it( $f->created_at ) . ' ' . substr( $f->created_at, 11, 5 ) . ' · ' . $f->email ) . '</small><small>' . ( $cur ? 'Versione attuale del file' : 'Versione precedente (' . esc_html( $f->nome_file ) . ')' ) . ' · SHA-256 ' . esc_html( substr( $f->sha256, 0, 12 ) ) . '…</small></span></li>';
			}
			echo '</ul>';
		}
		echo '</details>';
		return;
	}
	$mia = apac_firma_utente( $d, get_current_user_id() );
	if ( apac_firma_attuale( $mia, $d ) ) {
		echo '<span class="apac-badge apac-badge--ok">Firmato il ' . esc_html( apac_date_it( $mia->created_at ) ) . '</span>';
		return;
	}
	$open = ( isset( $_GET['firma'] ) && absint( $_GET['firma'] ) === (int) $d->id ) || '' !== apac_details_open( 'documento_firma', 'id:' . (int) $d->id ); // phpcs:ignore WordPress.Security.NonceVerification
	$u    = wp_get_current_user();
	echo '<details class="apac-edit apac-sign" id="apac-firma-' . (int) $d->id . '"' . ( $open ? ' open' : '' ) . '><summary class="apac-btn apac-btn--small apac-btn--sign">' . apac_icon_extra( 'pen' ) . '<span>' . ( $mia ? 'Firma la nuova versione' : 'Leggi e firma' ) . '</span></summary>';
	echo '<div class="apac-sign__body"><ol class="apac-howto"><li><a href="' . esc_url( apac_file_url( $d->id, 'view' ) ) . '" target="_blank" rel="noopener">Apri e leggi il documento</a>.</li><li>Firma nel riquadro con il dito o con il mouse.</li><li>Conferma la presa visione.</li></ol>';
	echo apac_form_open( 'documento_firma', $azienda_id, false, 'apac-form apac-sign__form' ) . apac_hidden( 'id', (int) $d->id ) . '<input type="hidden" name="firma_img" value="" data-sign-out>';
	list( $cls, $aria, $msg ) = apac_field_error_parts( 'firma_img' );
	echo '<div class="apac-sign__pad' . $cls . '"><canvas data-sign-pad aria-label="Riquadro per la firma" role="img"></canvas><span class="apac-sign__hint" data-sign-hint>Firma qui</span><button type="button" class="apac-sign__clear" data-sign-clear>Cancella</button></div>' . $msg;
	echo '<noscript><p class="apac-notice apac-notice--warn">Per firmare serve JavaScript attivo nel browser.</p></noscript>';
	echo '<div class="apac-fields">' . apac_field( 'Nome e cognome *', 'firma_nome', $u->display_name, 'text', 'required maxlength="150" autocomplete="name"' ) . '</div>';
	list( $cls2, $aria2, $msg2 ) = apac_field_error_parts( 'firma_ok' );
	echo '<label class="apac-field apac-check apac-sign__ok' . $cls2 . '"><input type="checkbox" name="firma_ok" value="1" required' . $aria2 . '><span>' . esc_html( apac_firma_testo( $d ) ) . '</span></label>' . $msg2;
	echo '<p class="apac-sign__legal">Firma elettronica semplice con valore di presa visione: non sostituisce le firme richieste dalla legge su specifici documenti (ad esempio la data certa o la sottoscrizione del DVR, art. 28, c. 2, D.Lgs. 81/2008). Vengono registrati data, ora e impronta del file.</p>';
	echo '<div class="apac-actions"><button class="apac-btn" type="submit" data-sign-submit>Conferma e firma</button></div></form></div></details>';
	// phpcs:enable
}
