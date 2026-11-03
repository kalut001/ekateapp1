<?php
/**
 * Lavoratori e formazione: corsi richiesti per mansione, stato della formazione, cessazione e
 * riattivazione.
 *
 * 1.9.15: file per area; il codice proviene dai file per versione (session1-3.php, v190.php), invariato.
 */
defined( 'ABSPATH' ) || exit;

/** Stato della formazione di un lavoratore: scaduta, in_scadenza, ok, senza_scadenza, nessuno. */
function apac_stato_formazione( $n_attestati, $prossima ) {
	if ( ! $n_attestati ) {
		return 'nessuno';
	}
	if ( ! $prossima ) {
		return 'senza_scadenza';
	}
	$giorni = apac_days_until( $prossima );
	if ( $giorni < 0 ) {
		return 'scaduta';
	}
	return $giorni <= 30 ? 'in_scadenza' : 'ok';
}

function apac_norm_corso( $c ) {
	return mb_strtolower( trim( preg_replace( '/\s+/', ' ', (string) $c ) ) );
}

/** mansione => [corsi] dell'azienda. */
function apac_mansioni_corsi( $azienda_id ) {
	global $wpdb;
	$out   = array();
	$label = array(); // Stessa mansione scritta con maiuscole/spazi diversi: un solo gruppo.
	foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'mansioni_corsi' ) . ' WHERE azienda_id = %d ORDER BY id', $azienda_id ) ) as $r ) {
		$k = apac_norm_corso( $r->mansione );
		if ( ! isset( $label[ $k ] ) ) {
			$label[ $k ] = $r->mansione;
		}
		$out[ $label[ $k ] ][ (int) $r->id ] = $r->corso;
	}
	ksort( $out, SORT_NATURAL | SORT_FLAG_CASE );
	return $out;
}

/** Corsi richiesti dalla mansione: [corso => stato] con stato 'ok' | 'scaduto' | 'mancante'. */
function apac_formazione_richiesta( $azienda_id, $l, $mc = null ) {
	global $wpdb;
	$mc = null === $mc ? apac_mansioni_corsi( $azienda_id ) : $mc;
	$m  = null;
	foreach ( array_keys( $mc ) as $k ) {
		if ( apac_norm_corso( $k ) === apac_norm_corso( $l->mansione ) && '' !== trim( (string) $l->mansione ) ) {
			$m = $k;
		}
	}
	if ( null === $m ) {
		return array();
	}
	$att = $wpdb->get_results( $wpdb->prepare( 'SELECT a.corso, a.data_scadenza FROM ' . apac_table( 'attestati' ) . ' a WHERE a.azienda_id = %d AND a.lavoratore_id = %d AND ' . apac_sql_attestato_corrente( 'a' ), $azienda_id, $l->id ) );
	$have = array();
	foreach ( $att as $a ) {
		$k  = apac_norm_corso( $a->corso );
		$st = ( $a->data_scadenza && $a->data_scadenza < apac_today() ) ? 'scaduto' : 'ok';
		if ( ! isset( $have[ $k ] ) || 'ok' === $st ) {
			$have[ $k ] = $st;
		}
	}
	$out = array();
	foreach ( $mc[ $m ] as $corso ) {
		$k             = apac_norm_corso( $corso );
		$out[ $corso ] = isset( $have[ $k ] ) ? $have[ $k ] : 'mancante';
	}
	return $out;
}

function apac_op_mansione_corso_add( $azienda_id ) {
	global $wpdb;
	$mansione = trim( sanitize_text_field( apac_post( 'mansione' ) ) );
	$back     = array( 'nuovo' => 'mansione_corso_add' );
	if ( '' === $mansione ) {
		apac_error_field( 'mansione' );
		apac_redirect( 'lavoratori', 'need_field', $back );
	}
	if ( mb_strlen( $mansione ) > 200 ) {
		apac_error_field( 'mansione' );
		apac_redirect( 'lavoratori', 'too_long', $back );
	}
	$corso = apac_corso_from_post( $back );
	foreach ( apac_mansioni_corsi( $azienda_id ) as $m => $corsi ) {
		if ( apac_norm_corso( $m ) === apac_norm_corso( $mansione ) ) {
			$mansione = $m; // Si usa la grafia già presente.
			foreach ( $corsi as $c ) {
				if ( apac_norm_corso( $c ) === apac_norm_corso( $corso ) ) {
					apac_redirect( 'lavoratori', 'saved', $back );
				}
			}
		}
	}
	apac_must_insert(
		'mansioni_corsi',
		array(
			'azienda_id' => $azienda_id,
			'mansione'   => $mansione,
			'corso'      => $corso,
		),
		'lavoratori',
		$back
	);
	apac_log_detail( $mansione . ': ' . $corso );
	apac_redirect( 'lavoratori', 'saved', $back );
}

function apac_op_mansione_corso_delete( $azienda_id ) {
	global $wpdb;
	$id  = absint( apac_post( 'id' ) );
	$row = apac_get_row_for_azienda( 'mansioni_corsi', $id, $azienda_id );
	if ( ! $row ) {
		apac_redirect( 'lavoratori', 'denied' );
	}
	apac_must( $wpdb->delete( apac_table( 'mansioni_corsi' ), array( 'id' => $id, 'azienda_id' => $azienda_id ) ), 'lavoratori', array(), true );
	apac_log_detail( $row->mansione . ': ' . $row->corso );
	apac_redirect( 'lavoratori', 'deleted', array( 'nuovo' => 'mansione_corso_add' ) );
}

/** Riquadro "Formazione richiesta per mansione" (pagina Lavoratori). */
function apac_render_mansioni_box( $az ) {
	$id  = (int) $az->id;
	$mc  = apac_mansioni_corsi( $id );
	$adm = apac_is_admin();
	if ( ! $adm && ! $mc ) {
		return;
	}
	$req  = isset( $_GET['nuovo'] ) ? sanitize_key( wp_unslash( $_GET['nuovo'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$open = 'mansione_corso_add' === $req || '' !== apac_details_open( 'mansione_corso_add', '' );
	$n    = 0;
	foreach ( $mc as $c ) {
		$n += count( $c );
	}
	// phpcs:disable WordPress.Security.EscapeOutput
	echo '<details class="apac-tools" id="apac-mansioni"' . ( $open ? ' open' : '' ) . '><summary>' . apac_icon( 'check' ) . '<span>Formazione richiesta per mansione' . ( $n ? ' (' . (int) $n . ')' : '' ) . '</span></summary><div class="apac-tools__body">';
	echo '<p class="apac-muted">Corsi indicati dallo studio per ciascuna mansione, sulla base della valutazione dei rischi. Le scadenze restano quelle registrate sui singoli attestati.</p>';
	if ( $mc ) {
		echo '<ul class="apac-mansioni">';
		foreach ( $mc as $mansione => $corsi ) {
			echo '<li><strong>' . esc_html( $mansione ) . '</strong><div class="apac-chips">';
			foreach ( $corsi as $cid => $corso ) {
				echo '<span class="apac-chip">' . esc_html( $corso );
				if ( $adm ) {
					echo apac_button_form( 'mansione_corso_delete', $id, array( 'id' => $cid ), '×', 'apac-chip__x', 'Togliere «' . $corso . '» dalla mansione «' . $mansione . '»?' );
				}
				echo '</span>';
			}
			echo '</div></li>';
		}
		echo '</ul>';
	} else {
		echo '<p class="apac-muted">Nessun corso indicato.</p>';
	}
	if ( $adm ) {
		$mansioni = array();
		foreach ( apac_get_lavoratori( $id, true ) as $l ) {
			if ( '' !== $l->mansione ) {
				$mansioni[ $l->mansione ] = $l->mansione;
			}
		}
		$corsi = array( '' => '— Seleziona —' );
		foreach ( apac_corsi() as $c ) {
			$corsi[ $c ] = $c;
		}
		echo '<div class="apac-subcard">' . apac_form_open( 'mansione_corso_add', $id ) . '<div class="apac-fields">';
		echo apac_field( 'Mansione *', 'mansione', '', 'text', 'required maxlength="200" list="apac-mansioni-list" placeholder="Es. Muratore"' );
		echo '<datalist id="apac-mansioni-list">';
		foreach ( $mansioni as $m ) {
			echo '<option value="' . esc_attr( $m ) . '">';
		}
		echo '</datalist>';
		echo apac_select( 'Corso richiesto *', 'corso', $corsi, '', 'required' );
		echo apac_field( 'Se “Altro”, specifica', 'corso_altro', '', 'text', 'maxlength="150"' );
		echo '</div><p class="apac-muted">Scrivi il nome del corso come viene registrato negli attestati: il confronto avviene sul nome.</p><div class="apac-actions"><button class="apac-btn apac-btn--small" type="submit">Aggiungi</button></div></form></div>';
	}
	echo '</div></details>';
	// phpcs:enable
}

/** Riquadro nella scheda del lavoratore. */
function apac_render_formazione_richiesta( $az, $l ) {
	$req = apac_formazione_richiesta( (int) $az->id, $l );
	if ( ! $req ) {
		return;
	}
	$lab = array(
		'ok'       => array( 'ok', 'Presente' ),
		'scaduto'  => array( 'danger', 'Scaduto' ),
		'mancante' => array( 'warn', 'Mancante' ),
	);
	echo '<section class="apac-card"><h2>Formazione richiesta per la mansione</h2><ul class="apac-list apac-reqlist">';
	foreach ( $req as $corso => $st ) {
		echo '<li><span>' . esc_html( $corso ) . '</span><span class="apac-badge apac-badge--' . esc_attr( $lab[ $st ][0] ) . '">' . esc_html( $lab[ $st ][1] ) . '</span></li>';
	}
	echo '</ul>';
	if ( in_array( 'mancante', $req, true ) || in_array( 'scaduto', $req, true ) ) {
		echo '<p><a class="apac-btn apac-btn--small apac-btn--ghost" href="' . esc_url( apac_url( 'segnalazioni', array( 'nuovo' => 'corso_richiesta', 'lav' => (int) $l->id ) ) . '#apac-add-corso_richiesta' ) . '">Richiedi un corso allo studio</a></p>';
	}
	echo '</section>';
}

function apac_op_lavoratore_cessa( $azienda_id ) {
	global $wpdb;
	$id  = absint( apac_post( 'id' ) );
	$row = apac_get_row_for_azienda( 'lavoratori', $id, $azienda_id );
	if ( ! $row ) {
		apac_redirect( 'lavoratori', 'denied' );
	}
	$back = array( 'lav' => $id );
	$d    = apac_parse_date( apac_post( 'data_cessazione' ) );
	if ( ! $d['ok'] || ! $d['date'] ) {
		apac_error_field( 'data_cessazione' );
		apac_redirect( 'lavoratori', 'invalid_date', $back );
	}
	if ( ! empty( $row->data_assunzione ) && $d['date'] < $row->data_assunzione ) {
		apac_error_field( 'data_cessazione' );
		apac_redirect( 'lavoratori', 'cess_before_hire', $back );
	}
	apac_must( $wpdb->update( apac_table( 'lavoratori' ), array( 'attivo' => 0, 'data_cessazione' => $d['date'] ), array( 'id' => $id, 'azienda_id' => $azienda_id ) ), 'lavoratori', $back );
	apac_log_detail( $row->cognome . ' ' . $row->nome . ' – cessato il ' . apac_date_it( $d['date'] ) );
	apac_redirect( 'lavoratori', 'saved', $back );
}

function apac_op_lavoratore_riattiva( $azienda_id ) {
	global $wpdb;
	$id  = absint( apac_post( 'id' ) );
	$row = apac_get_row_for_azienda( 'lavoratori', $id, $azienda_id );
	if ( ! $row ) {
		apac_redirect( 'lavoratori', 'denied' );
	}
	apac_must( $wpdb->update( apac_table( 'lavoratori' ), array( 'attivo' => 1, 'data_cessazione' => null ), array( 'id' => $id, 'azienda_id' => $azienda_id ) ), 'lavoratori', array( 'lav' => $id ) );
	apac_log_detail( $row->cognome . ' ' . $row->nome . ' – di nuovo in forza' );
	apac_redirect( 'lavoratori', 'saved', array( 'lav' => $id ) );
}

/** Modulo "Segna come cessato" (data obbligatoria, storico conservato). */
function apac_cessa_form( $l, $azienda_id ) {
	$h  = apac_form_open( 'lavoratore_cessa', $azienda_id, false, 'apac-form apac-form--compact' ) . apac_hidden( 'id', (int) $l->id );
	$h .= '<div class="apac-fields">' . apac_field( 'Data di cessazione *', 'data_cessazione', apac_today(), 'date', 'required' ) . '</div>';
	$h .= '<p class="apac-muted">Il lavoratore esce dall’elenco “In forza”; attestati e storico restano consultabili tra i cessati.</p>';
	$h .= '<div class="apac-actions"><button class="apac-btn apac-btn--small" type="submit">Segna come cessato</button></div></form>';
	return $h;
}
