<?php
defined( 'ABSPATH' ) || exit;

/*
 * Strumenti dell'amministratore:
 * - permessi dei clienti per azienda
 * - registro attività
 * - modifiche (utenti, attestati, documenti, segnalazioni)
 * - pannello riepilogativo di tutte le aziende
 */

/* ============================== Permessi ============================== */

/** Cosa può modificare il cliente, con i valori predefiniti. */
function apac_permessi_defaults() {
	return array(
		'azienda'    => array( false, 'Modificare la scheda azienda e le figure della sicurezza' ),
		'lavoratori' => array( true, 'Aggiungere e modificare lavoratori e attestati' ),
		'scadenze'   => array( true, 'Aggiungere e modificare le scadenze' ),
		'documenti'  => array( true, 'Caricare documenti (ed eliminare quelli caricati da lui)' ),
	);
}

function apac_get_permessi( $azienda ) {
	$saved = array();
	if ( $azienda && ! empty( $azienda->permessi ) ) {
		$dec = json_decode( (string) $azienda->permessi, true );
		$saved = is_array( $dec ) ? $dec : array();
	}
	$out = array();
	foreach ( apac_permessi_defaults() as $k => $def ) {
		$out[ $k ] = array_key_exists( $k, $saved ) ? (bool) $saved[ $k ] : $def[0];
	}
	return $out;
}

/** L'utente corrente può eseguire questo tipo di modifica sull'azienda? */
function apac_client_can( $azienda_id, $perm ) {
	if ( apac_is_admin() ) {
		return true;
	}
	if ( apac_user_readonly() ) {
		return false; // Accesso in sola consultazione.
	}
	$perms = apac_get_permessi( apac_get_azienda( $azienda_id ) );
	return ! empty( $perms[ $perm ] );
}

/* 1.9.15: permessi e sezioni delle operazioni sono nel registro unico (includes/operazioni.php). */

function apac_op_azienda_permessi( $azienda_id ) {
	global $wpdb;
	$data = array();
	foreach ( array_keys( apac_permessi_defaults() ) as $k ) {
		$data[ $k ] = apac_post( 'perm_' . $k ) ? 1 : 0;
	}
	apac_must( $wpdb->update( apac_table( 'aziende' ), array( 'permessi' => wp_json_encode( $data ) ), array( 'id' => $azienda_id ) ), 'azienda' );
	apac_redirect( 'azienda', 'saved' );
}

/* =========================== Registro attività ========================= */

function apac_log( $azienda_id, $azione, $dettaglio = '' ) {
	global $wpdb;
	$wpdb->insert(
		apac_table( 'log' ),
		array(
			'azienda_id' => (int) $azienda_id,
			'user_id'    => get_current_user_id(),
			'azione'     => substr( sanitize_text_field( $azione ), 0, 60 ),
			'dettaglio'  => mb_substr( sanitize_text_field( $dettaglio ), 0, 250 ),
			'created_at' => apac_now(),
		)
	);
}

function apac_log_labels() {
	return array(
		'azienda_create'      => 'Azienda creata',
		'azienda_save'        => 'Scheda azienda modificata',
		'azienda_permessi'    => 'Permessi del cliente modificati',
		'lavoratore_save'     => 'Lavoratore salvato',
		'lavoratore_delete'   => 'Lavoratore eliminato',
		'attestato_save'      => 'Attestato registrato',
		'attestato_update'    => 'Attestato modificato',
		'attestato_delete'    => 'Attestato eliminato',
		'documento_upload'    => 'Documento caricato',
		'documento_update'    => 'Documento modificato',
		'documento_delete'    => 'Documento eliminato',
		'scadenza_save'       => 'Scadenza salvata',
		'scadenza_toggle'     => 'Stato scadenza cambiato',
		'scadenza_delete'     => 'Scadenza eliminata',
		'segnalazione_create' => 'Segnalazione inviata',
		'segnalazione_update' => 'Segnalazione aggiornata',
		'segnalazione_delete' => 'Segnalazione eliminata',
		'utente_create'       => 'Accesso creato',
		'utente_update'       => 'Utente modificato',
		'utente_reset'        => 'Reimpostazione password inviata',
		'utente_revoca'       => 'Accesso revocato',
		'utente_riattiva'     => 'Accesso riattivato',
		'utente_elimina'      => 'Account eliminato',
		'azienda_delete'      => 'Azienda eliminata',
		'log_svuota'          => 'Registro svuotato',
		'lavoratore_cessa'    => 'Lavoratore cessato',
		'lavoratore_riattiva' => 'Lavoratore di nuovo in forza',
		'primi_passi'         => 'Guida primi passi aggiornata',
		'import_confirm'      => 'Importazione da Excel',
		'documento_firma'     => 'Presa visione firmata',
		'consenso_firma'      => 'Documenti firmati al primo accesso',
		'corso_richiesta'     => 'Corso richiesto',
		'mansione_corso_add'  => 'Corso richiesto per mansione aggiunto',
		'mansione_corso_delete' => 'Corso richiesto per mansione tolto',
		'richiesta_collega'   => 'Accesso richiesto per un collega',
		'richiesta_approva'   => 'Richiesta di accesso approvata',
		'richiesta_rifiuta'   => 'Richiesta di accesso rifiutata',
		'richiesta_elimina'   => 'Richiesta di accesso eliminata',
	);
}

/** Chiamata da apac_redirect(): registra solo le operazioni riuscite. */
function apac_log_from_context( $msg, $args ) {
	if ( empty( $GLOBALS['apac_log_ctx'] ) || ! in_array( $msg, apac_success_codes(), true ) ) {
		return;
	}
	$ctx    = $GLOBALS['apac_log_ctx'];
	$labels = apac_log_labels();
	$az     = ! empty( $args['azienda'] ) ? (int) $args['azienda'] : (int) $ctx['azienda_id'];
	apac_log( $az, isset( $labels[ $ctx['op'] ] ) ? $labels[ $ctx['op'] ] : $ctx['op'], $ctx['detail'] );
	$GLOBALS['apac_log_ctx'] = null;
}

function apac_log_detail( $text ) {
	if ( ! empty( $GLOBALS['apac_log_ctx'] ) ) {
		$GLOBALS['apac_log_ctx']['detail'] = (string) $text;
	}
}

function apac_get_log( $azienda_id = 0, $limit = 30 ) {
	global $wpdb;
	if ( $azienda_id ) {
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'log' ) . ' WHERE azienda_id = %d ORDER BY id DESC LIMIT %d', $azienda_id, $limit ) );
	}
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'log' ) . ' ORDER BY id DESC LIMIT %d', $limit ) );
}

function apac_render_log_table( $rows, $show_azienda = true ) {
	if ( ! $rows ) {
		echo '<p class="apac-muted">Nessuna attività registrata.</p>';
		return;
	}
	echo '<div class="apac-table-wrap"><table class="apac-table"><thead><tr><th>Quando</th><th>Chi</th>' . ( $show_azienda ? '<th>Azienda</th>' : '' ) . '<th>Attività</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$az = $show_azienda ? apac_get_azienda( (int) $r->azienda_id ) : null;
		echo '<tr><td data-label="Quando">' . esc_html( mysql2date( 'd/m/Y H:i', $r->created_at ) ) . '</td>';
		echo '<td data-label="Chi">' . apac_author_label( (int) $r->user_id ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $show_azienda ) {
			echo '<td data-label="Azienda">' . esc_html( $az ? $az->ragione_sociale : '—' ) . '</td>';
		}
		echo '<td data-label="Attività"><strong>' . esc_html( $r->azione ) . '</strong>' . ( '' !== $r->dettaglio ? '<br><small>' . esc_html( $r->dettaglio ) . '</small>' : '' ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
}

/* ===================== Autore delle attività (storico) ================== */

/*
 * Quando un account viene eliminato (dall'area riservata o dalla bacheca di
 * WordPress) si conserva solo il suo numero e il ruolo, senza nome né email:
 * lo storico può così distinguere "utente eliminato" da "autore sconosciuto".
 */
add_action( 'delete_user', 'apac_remember_deleted_user' );
function apac_remember_deleted_user( $user_id ) {
	global $wpdb;
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return;
	}
	$ruolo = user_can( $user, 'manage_options' ) ? 'studio' : ( in_array( APAC_ROLE, (array) $user->roles, true ) ? 'cliente' : 'altro' );
	$t     = apac_table( 'utenti_eliminati' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$wpdb->insert( $t, array( 'user_id' => (int) $user_id, 'ruolo' => $ruolo, 'deleted_at' => apac_now() ) );
}

/**
 * Etichetta HTML (già sanitizzata) dell'autore di un'attività.
 * - account esistente: nome visualizzato (+ "studio" per gli amministratori);
 * - account eliminato: "Utente eliminato" con il ruolo, se noto;
 * - nessun autore registrato: "Autore sconosciuto".
 */
function apac_author_label( $user_id ) {
	global $wpdb;
	static $cache = array();
	$user_id = (int) $user_id;
	if ( isset( $cache[ $user_id ] ) ) {
		return $cache[ $user_id ];
	}
	if ( $user_id <= 0 ) {
		$out = '<span class="apac-muted">Autore sconosciuto</span>';
	} else {
		$u = get_userdata( $user_id );
		if ( $u ) {
			$out = esc_html( $u->display_name ) . ( user_can( $u, 'manage_options' ) ? ' <small>(studio)</small>' : '' );
		} else {
			$ruolo = $wpdb->get_var( $wpdb->prepare( 'SELECT ruolo FROM ' . apac_table( 'utenti_eliminati' ) . ' WHERE user_id = %d', $user_id ) );
			$map   = array(
				'studio'  => ' (studio)',
				'cliente' => ' (cliente)',
			);
			$out   = '<span class="apac-muted">Utente eliminato' . esc_html( $ruolo && isset( $map[ $ruolo ] ) ? $map[ $ruolo ] : '' ) . '</span>';
		}
	}
	$cache[ $user_id ] = $out;
	return $out;
}

/* ============================ Nuove modifiche ========================== */

function apac_op_utente_update( $unused ) {
	$user_id = absint( apac_post( 'user_id' ) );
	// 1.9.14: stesso controllo di revoca/eliminazione (mai un amministratore o un utente che scrive sul sito).
	$user    = apac_get_client_user( $user_id );
	if ( ! $user ) {
		apac_redirect( 'utenti', 'denied' );
	}
	$email   = apac_normalize_email( apac_post( 'email' ) );
	$nome    = trim( sanitize_text_field( apac_post( 'nome' ) ) );
	$cognome = trim( sanitize_text_field( apac_post( 'cognome' ) ) );
	// Il numero inserito a mano (alternativa senza JavaScript) prevale sul menu.
	$new_az  = absint( apac_post( 'nuova_azienda_id' ) ) ? absint( apac_post( 'nuova_azienda_id' ) ) : absint( apac_post( 'nuova_azienda' ) );
	$back    = array( 'azienda' => apac_user_azienda_id( $user_id ) );
	if ( '' === $nome ) {
		apac_error_field( 'nome' );
		apac_redirect( 'utenti', 'need_field', $back );
	}
	if ( ! $email ) {
		apac_error_field( 'email' );
		apac_redirect( 'utenti', '' === $email ? 'need_field' : 'bad_email', $back );
	}
	if ( ! apac_get_azienda( $new_az ) ) {
		apac_error_field( 'nuova_azienda' );
		apac_redirect( 'utenti', 'bad_value', $back );
	}
	apac_check_lengths( array( 'nome' => $nome, 'cognome' => $cognome, 'email' => $email ), 'utenti', $back );
	$other = email_exists( $email );
	if ( $other && (int) $other !== $user_id ) {
		apac_error_field( 'email' );
		apac_redirect( 'utenti', 'user_exists', $back );
	}
	$old_az = apac_user_azienda_id( $user_id );
	$res    = wp_update_user(
		array(
			'ID'           => $user_id,
			'user_email'   => $email,
			'first_name'   => $nome,
			'last_name'    => $cognome,
			'display_name' => trim( $nome . ' ' . $cognome ),
		)
	);
	if ( is_wp_error( $res ) ) {
		apac_redirect( 'utenti', 'db_error', array( 'azienda' => $old_az ) );
	}
	update_user_meta( $user_id, 'apac_azienda_id', $new_az );
	update_user_meta( $user_id, 'apac_accesso', 'lettura' === apac_post( 'accesso' ) ? 'lettura' : 'modifica' );
	// update_user_meta restituisce false anche se il valore non cambia: si verifica il dato salvato.
	if ( (int) get_user_meta( $user_id, 'apac_azienda_id', true ) !== $new_az || get_userdata( $user_id )->user_email !== $email ) {
		apac_redirect( 'utenti', 'db_error', array( 'azienda' => $old_az ) );
	}
	apac_log_detail( trim( $nome . ' ' . $cognome ) . ' – ' . $email );
	if ( ! empty( $GLOBALS['apac_log_ctx'] ) ) {
		$GLOBALS['apac_log_ctx']['azienda_id'] = $new_az;
	}
	apac_redirect( 'utenti', 'saved', array( 'azienda' => $new_az ) );
}

function apac_op_utente_reset( $unused ) {
	$user_id = absint( apac_post( 'user_id' ) );
	$user    = apac_get_client_user( $user_id ); // 1.9.14: solo utenti clienti.
	if ( ! $user ) {
		apac_redirect( 'utenti', 'denied' );
	}
	$az = apac_user_azienda_id( $user_id );
	if ( ! empty( $GLOBALS['apac_log_ctx'] ) ) {
		$GLOBALS['apac_log_ctx']['azienda_id'] = $az;
	}
	apac_log_detail( $user->user_email );
	$res = retrieve_password( $user->user_login );
	if ( true !== $res ) {
		apac_record_mail_error( $user->user_email, is_wp_error( $res ) ? $res->get_error_message() : 'invio non riuscito' );
		apac_redirect( 'utenti', 'reset_error', array( 'azienda' => $az ) );
	}
	apac_redirect( 'utenti', 'reset_sent', array( 'azienda' => $az ) );
}

function apac_op_attestato_update( $azienda_id ) {
	global $wpdb;
	$id  = absint( apac_post( 'id' ) );
	$row = apac_get_row_for_azienda( 'attestati', $id, $azienda_id );
	if ( ! $row ) {
		apac_redirect( 'lavoratori', 'denied' );
	}
	$back  = array( 'lav' => (int) $row->lavoratore_id );
	$lav   = apac_get_row_for_azienda( 'lavoratori', $row->lavoratore_id, $azienda_id );
	$corso = apac_corso_from_post( $back, $row->corso );
	list( $cons, $scad ) = apac_attestato_dates( $back );
	$data = array(
		'corso'              => $corso,
		'data_conseguimento' => $cons,
		'data_scadenza'      => $scad,
		'note'               => sanitize_textarea_field( apac_post( 'note' ) ),
	);
	apac_check_lengths( array( 'note' => $data['note'] ), 'lavoratori', $back );
	// Gli avvisi ripartono SOLO se cambia la data di scadenza (non per note o corso).
	if ( $scad !== $row->data_scadenza ) {
		$data['avviso_inviato'] = -1;
		apac_must( apac_avvisi_reset( 'attestato', $id ), 'lavoratori', $back );
	}
	$doc_id = apac_attestato_store_file( $azienda_id, $corso, $lav, $back );
	if ( $doc_id ) {
		$data['documento_id'] = $doc_id;
	}
	apac_must( $wpdb->update( apac_table( 'attestati' ), $data, array( 'id' => $id, 'azienda_id' => $azienda_id ) ), 'lavoratori', $back );
	apac_log_detail( $corso . ( $lav ? ' – ' . $lav->cognome . ' ' . $lav->nome : '' ) );
	apac_redirect( 'lavoratori', 'saved', $back );
}

function apac_op_documento_update( $azienda_id ) {
	global $wpdb;
	$id  = absint( apac_post( 'id' ) );
	$doc = apac_get_row_for_azienda( 'documenti', $id, $azienda_id );
	if ( ! $doc || ( ! apac_is_admin() && (int) $doc->caricato_da !== get_current_user_id() ) ) {
		apac_redirect( 'documenti', 'denied' );
	}
	$categoria = sanitize_key( apac_post( 'categoria' ) );
	$titolo    = trim( sanitize_text_field( apac_post( 'titolo' ) ) );
	if ( '' === $titolo ) {
		apac_error_field( 'titolo' );
		apac_redirect( 'documenti', 'need_field' );
	}
	if ( ! array_key_exists( $categoria, apac_categorie_documenti() ) ) {
		apac_error_field( 'categoria' );
		apac_redirect( 'documenti', 'bad_value' );
	}
	apac_check_lengths( array( 'titolo' => $titolo ), 'documenti' );
	$data = array(
		'titolo'    => $titolo,
		'categoria' => $categoria,
	);
	if ( apac_is_admin() ) {
		$data['richiede_firma'] = apac_post( 'richiede_firma' ) ? 1 : 0;
	}
	// Sostituzione del file in tre passi:
	// 1) il nuovo file viene salvato (e verrà rimosso se qualcosa fallisce);
	// 2) si aggiorna il database e si conferma;
	// 3) solo a conferma avvenuta si cancella il vecchio file.
	$replaced = false;
	if ( ! empty( $_FILES['file']['name'] ) ) {
		$stored = apac_store_upload( $_FILES['file'], $azienda_id ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( is_wp_error( $stored ) ) {
			apac_error_field( 'file' );
			apac_redirect( 'documenti', $stored->get_error_code() );
		}
		$data['nome_file']  = $stored['name'];
		$data['file_path']  = $stored['path'];
		$data['mime']       = $stored['mime'];
		$data['dimensione'] = $stored['size'];
		$data['created_at'] = apac_now();
		$replaced           = true;
		// 1.7.0: il file precedente non si cancella, resta come versione consultabile.
		apac_archivia_versione( $doc );
	}
	apac_must( $wpdb->update( apac_table( 'documenti' ), $data, array( 'id' => $id, 'azienda_id' => $azienda_id ) ), 'documenti' );
	apac_log_detail( $titolo . ( isset( $data['nome_file'] ) ? ' (file sostituito)' : '' ) );
	apac_redirect( 'documenti', 'saved' );
}

function apac_op_segnalazione_delete( $azienda_id ) {
	global $wpdb;
	$id  = absint( apac_post( 'id' ) );
	$row = apac_get_row_for_azienda( 'segnalazioni', $id, $azienda_id );
	if ( ! $row ) {
		apac_redirect( 'segnalazioni', 'denied' );
	}
	$foto = apac_get_foto( $id, $azienda_id );
	apac_must( $wpdb->delete( apac_table( 'segnalazioni_foto' ), array( 'segnalazione_id' => $id, 'azienda_id' => $azienda_id ) ), 'segnalazioni' );
	apac_must( $wpdb->delete( apac_table( 'segnalazioni' ), array( 'id' => $id, 'azienda_id' => $azienda_id ) ), 'segnalazioni', array(), true );
	apac_delete_rows_files_after_commit( $foto );
	apac_log_detail( $row->titolo );
	apac_redirect( 'segnalazioni', 'deleted' );
}

/* ======================= Pannello amministratore ======================= */

function apac_render_pannello() {
	global $wpdb;
	if ( ! apac_is_admin() ) {
		return;
	}
	apac_render_install_card( true );
	$aziende = apac_get_aziende();
	$today   = apac_today();
	if ( ! $aziende ) {
		apac_render_prima_azienda();
		return;
	}

	// Riepilogo per azienda.
	$tot = array( 'aziende' => count( $aziende ), 'scadute' => 0, 'entro30' => 0, 'segnalazioni' => 0, 'alte' => 0 );
	$righe = array();
	// 1.9.1: dati di tutte le aziende con poche interrogazioni complessive.
	$ag_all = apac_agenda_tutte( 30 );
	$sg_all = apac_segnalazioni_aperte_per_azienda();
	foreach ( $aziende as $a ) {
		$agenda  = isset( $ag_all[ (int) $a->id ] ) ? $ag_all[ (int) $a->id ] : array();
		$scadute = 0;
		foreach ( $agenda as $i ) {
			if ( $i['data'] < $today ) {
				$scadute++;
			}
		}
		$entro30 = count( $agenda ) - $scadute;
		$segn    = isset( $sg_all[ (int) $a->id ] ) ? $sg_all[ (int) $a->id ] : 0;
		$alte    = count( apac_criticita_alte( (int) $a->id ) );
		$tot['scadute']      += $scadute;
		$tot['entro30']      += $entro30;
		$tot['segnalazioni'] += $segn;
		$tot['alte']         += $alte;
		$righe[]              = compact( 'a', 'scadute', 'entro30', 'segn', 'alte' );
	}

	// Contatori: ognuno apre l'elenco filtrato di tutte le aziende (stessi criteri di conteggio).
	$mini = array(
		array( 'Aziende', $tot['aziende'], '', apac_url( 'utenti', array( 'azienda' => 0 ) ) ),
		array( 'Scadute', $tot['scadute'], $tot['scadute'] ? 'danger' : '', apac_url( 'elenco', array( 'tipo' => 'scadute', 'azienda' => 0 ) ) ),
		array( 'Entro 30 gg', $tot['entro30'], $tot['entro30'] ? 'warn' : '', apac_url( 'elenco', array( 'tipo' => 'entro30', 'azienda' => 0 ) ) ),
		array( 'Segnalazioni', $tot['segnalazioni'], $tot['segnalazioni'] ? 'warn' : '', apac_url( 'elenco', array( 'tipo' => 'segnalazioni', 'azienda' => 0 ) ) ),
		array( 'Criticità alte', $tot['alte'], $tot['alte'] ? 'danger' : '', apac_url( 'elenco', array( 'tipo' => 'criticita', 'azienda' => 0 ) ) ),
	);
	echo '<div class="apac-mini apac-mini--5" role="group" aria-label="In numeri: tocca per vedere l’elenco">';
	foreach ( $mini as $c ) {
		echo '<a class="apac-mini__item' . ( $c[2] ? ' apac-mini__item--' . esc_attr( $c[2] ) : '' ) . '" href="' . esc_url( $c[3] ) . '"><strong>' . (int) $c[1] . '</strong><span>' . esc_html( $c[0] ) . '</span></a>';
	}
	echo '</div>';

	echo '<section class="apac-card apac-azlist"><h2>Situazione delle aziende</h2>';
	if ( ! $righe ) {
		echo '<p class="apac-muted">Nessuna azienda. Creane una in <a href="' . esc_url( apac_url( 'utenti' ) ) . '">Utenti e aziende</a>.</p>';
	} else {
		// Prima le aziende con qualcosa da gestire (più urgenti in alto), poi in ordine alfabetico.
		usort(
			$righe,
			function ( $x, $y ) {
				$px = $x['scadute'] * 100 + $x['alte'] * 10 + $x['segn'] + $x['entro30'];
				$py = $y['scadute'] * 100 + $y['alte'] * 10 + $y['segn'] + $y['entro30'];
				if ( ( $px > 0 ) !== ( $py > 0 ) ) {
					return $px > 0 ? -1 : 1;
				}
				if ( $x['scadute'] !== $y['scadute'] ) {
					return $y['scadute'] - $x['scadute'];
				}
				return strcmp( (string) $x['a']->ragione_sort, (string) $y['a']->ragione_sort );
			}
		);
		$riga = function ( $r ) {
			$b = '';
			if ( $r['scadute'] ) {
				$b .= '<span class="apac-badge apac-badge--danger">' . (int) $r['scadute'] . ( 1 === $r['scadute'] ? ' scaduta' : ' scadute' ) . '</span>';
			}
			if ( $r['entro30'] ) {
				$b .= '<span class="apac-badge apac-badge--warn">' . (int) $r['entro30'] . ' entro 30 gg</span>';
			}
			if ( $r['segn'] ) {
				$b .= '<span class="apac-badge apac-badge--warn">' . (int) $r['segn'] . ( 1 === $r['segn'] ? ' segnalazione' : ' segnalazioni' ) . '</span>';
			}
			if ( $r['alte'] ) {
				$b .= '<span class="apac-badge apac-badge--danger">' . (int) $r['alte'] . ( 1 === $r['alte'] ? ' criticità alta' : ' criticità alte' ) . '</span>';
			}
			if ( '' === $b ) {
				$b = '<span class="apac-badge apac-badge--ok">Nessun avviso</span>';
			}
			return '<li><a href="' . esc_url( apac_url( 'dashboard', array( 'azienda' => (int) $r['a']->id ) ) ) . '"><span class="apac-azlist__name">' . esc_html( $r['a']->ragione_sociale ) . '<small>' . esc_html( apac_azienda_ident( $r['a'] ) . ( $r['a']->comune ? ' · ' . $r['a']->comune : '' ) ) . '</small></span><span class="apac-azlist__badges">' . $b . '</span>' . apac_icon( 'arrow' ) . '</a></li>';
		};
		$attenzione = array();
		$ok         = array();
		foreach ( $righe as $r ) {
			if ( $r['scadute'] + $r['entro30'] + $r['segn'] + $r['alte'] > 0 ) {
				$attenzione[] = $r;
			} else {
				$ok[] = $r;
			}
		}
		$pages = max( 1, (int) ceil( count( $attenzione ) / APAC_LIST_PAGE ) );
		$page  = isset( $_GET['ppag'] ) ? max( 1, min( $pages, absint( $_GET['ppag'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $attenzione ) {
			echo '<ul class="apac-list apac-azlist__list">';
			foreach ( array_slice( $attenzione, ( $page - 1 ) * APAC_LIST_PAGE, APAC_LIST_PAGE ) as $r ) {
				echo $riga( $r ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</ul>';
			apac_render_pager( array( 'page' => $page, 'pages' => $pages ), 'ppag' );
		} else {
			echo '<p class="apac-muted">Nessuna azienda con scadenze, segnalazioni o criticità alte.</p>';
		}
		if ( $ok ) {
			echo '<details class="apac-azlist__ok"><summary>Aziende senza nulla da gestire (' . count( $ok ) . ')</summary><ul class="apac-list apac-azlist__list">';
			foreach ( $ok as $r ) {
				echo $riga( $r ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</ul></details>';
		}
	}
	echo '</section>';

	// Scadenze di tutte le aziende.
	$tutte = array();
	foreach ( $aziende as $a ) {
		foreach ( isset( $ag_all[ (int) $a->id ] ) ? $ag_all[ (int) $a->id ] : array() as $i ) {
			$i['azienda'] = $a;
			$i['url']     = apac_agenda_url( $i, (int) $a->id );
			$tutte[]      = $i;
		}
	}
	usort(
		$tutte,
		function ( $x, $y ) {
			return strcmp( $x['data'], $y['data'] );
		}
	);
	echo '<div class="apac-grid2"><section class="apac-card"><h2>Prossime scadenze</h2>';
	if ( ! $tutte ) {
		echo '<p class="apac-muted">Nessuna scadenza superata o nei prossimi 30 giorni.</p>';
	} else {
		echo '<ul class="apac-list">';
		foreach ( array_slice( $tutte, 0, 8 ) as $i ) {
			$url = add_query_arg( 'azienda', (int) $i['azienda']->id, $i['url'] );
			echo '<li><a href="' . esc_url( $url ) . '"><span>' . esc_html( $i['titolo'] ) . '<small>' . esc_html( $i['azienda']->ragione_sociale ) . ' · ' . esc_html( apac_date_it( $i['data'] ) ) . '</small></span>' . apac_badge_scadenza( $i['data'] ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul>';
		if ( count( $tutte ) > 8 ) {
			echo '<p class="apac-muted">Altre ' . ( count( $tutte ) - 8 ) . ': aprile dalla scheda di ciascuna azienda.</p>';
		}
	}
	echo '</section>';

	// Segnalazioni aperte di tutte le aziende.
	$segn = $wpdb->get_results( 'SELECT * FROM ' . apac_table( 'segnalazioni' ) . " WHERE stato <> 'chiusa' ORDER BY created_at DESC LIMIT 8" );
	echo '<section class="apac-card"><h2>Segnalazioni da gestire</h2>';
	if ( ! $segn ) {
		echo '<p class="apac-muted">Nessuna segnalazione aperta.</p>';
	} else {
		echo '<ul class="apac-list">';
		foreach ( $segn as $s ) {
			$a = apac_get_azienda( (int) $s->azienda_id );
			echo '<li><a href="' . esc_url( apac_url( 'segnalazioni', array( 'azienda' => (int) $s->azienda_id ) ) ) . '"><span>' . esc_html( $s->titolo ) . '<small>' . esc_html( $a ? $a->ragione_sociale : '—' ) . ' · ' . esc_html( apac_date_it( $s->created_at ) ) . '</small></span>' . apac_badge_livello( 'alta' === $s->priorita ? 'alta' : ( 'media' === $s->priorita ? 'media' : 'bassa' ) ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul>';
	}
	echo '</section></div>';

	// Registro chiuso in un menu a tendina: si apre solo quando serve.
	$log = apac_get_log( 0, 30 );
	echo '<details class="apac-card apac-fold"><summary><span>Ultime attività</span><small>' . ( $log ? count( $log ) . ' più recenti · tocca per aprire' : 'nessuna' ) . '</small></summary><div class="apac-fold__body">';
	apac_render_log_table( $log );
	if ( $log ) {
		echo '<div class="apac-log-clear"><p class="apac-muted">Puoi svuotare il registro, ad esempio dopo le prove. L’operazione non si può annullare; resta solo una riga che indica chi l’ha svuotato e quando.</p>';
		echo apac_form_open( 'log_svuota', 0 ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo apac_field( 'Scrivi ELIMINA per confermare', 'conferma', '', 'text', 'autocomplete="off"' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="apac-actions"><button class="apac-btn apac-btn--danger apac-btn--small" type="submit">Svuota registro</button></div></form></div>';
	}
	echo '</div></details>';
}
