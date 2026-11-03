<?php
/**
 * Segnalazioni: foto allegate e richieste di corsi.
 *
 * 1.9.15: file per area; il codice proviene dai file per versione (session1-3.php, v190.php), invariato.
 */
defined( 'ABSPATH' ) || exit;

/** Foto caricate con una segnalazione (massimo 3, solo JPG/PNG). */
function apac_store_segnalazione_foto( $segnalazione_id, $azienda_id ) {
	if ( empty( $_FILES['foto'] ) || ! is_array( $_FILES['foto']['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$f     = $_FILES['foto']; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
	$count = 0;
	foreach ( array_keys( $f['name'] ) as $i ) {
		if ( '' === (string) $f['name'][ $i ] ) {
			continue;
		}
		if ( ++$count > 3 ) {
			apac_error_field( 'foto' );
			apac_redirect( 'segnalazioni', 'too_many_photos' );
		}
		$one = array(
			'name'     => $f['name'][ $i ],
			'type'     => $f['type'][ $i ],
			'tmp_name' => $f['tmp_name'][ $i ],
			'error'    => $f['error'][ $i ],
			'size'     => $f['size'][ $i ],
		);
		$ext = strtolower( pathinfo( (string) $one['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
			apac_error_field( 'foto' );
			apac_redirect( 'segnalazioni', 'photo_type' );
		}
		$stored = apac_store_upload( $one, $azienda_id );
		if ( is_wp_error( $stored ) ) {
			apac_error_field( 'foto' );
			apac_redirect( 'segnalazioni', $stored->get_error_code() );
		}
		if ( ! in_array( $stored['mime'], array( 'image/jpeg', 'image/png' ), true ) ) {
			apac_error_field( 'foto' );
			apac_redirect( 'segnalazioni', 'photo_type' );
		}
		apac_must_insert(
			'segnalazioni_foto',
			array(
				'segnalazione_id' => (int) $segnalazione_id,
				'azienda_id'      => (int) $azienda_id,
				'nome_file'       => $stored['name'],
				'file_path'       => $stored['path'],
				'mime'            => $stored['mime'],
				'dimensione'      => $stored['size'],
				'created_at'      => apac_now(),
			),
			'segnalazioni'
		);
	}
}

function apac_get_foto( $segnalazione_id, $azienda_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'segnalazioni_foto' ) . ' WHERE segnalazione_id = %d AND azienda_id = %d ORDER BY id ASC', $segnalazione_id, $azienda_id ) );
}

/** Campo per allegare fino a 3 foto alla segnalazione (sul telefono apre anche la fotocamera). */
function apac_foto_field() {
	list( $cls, $aria, $msg ) = apac_field_error_parts( 'foto' );
	return '<label class="apac-field apac-field--wide apac-foto' . $cls . '"><span>' . apac_icon( 'camera' ) . 'Foto (facoltative, massimo 3)</span><input type="file" name="foto[]" accept="image/jpeg,image/png" multiple data-apac-max="3"' . $aria . '><small>Formato JPG o PNG. Non fotografare persone riconoscibili se non serve.</small>' . $msg . '</label>';
}

function apac_render_foto( $seg ) {
	$foto = apac_get_foto( (int) $seg->id, (int) $seg->azienda_id );
	if ( ! $foto ) {
		return;
	}
	echo '<div class="apac-photos">';
	foreach ( $foto as $f ) {
		echo '<a href="' . esc_url( apac_extra_file_url( 'foto', $f->id, 'view' ) ) . '" target="_blank" rel="noopener"><img src="' . esc_url( apac_extra_file_url( 'foto', $f->id, 'view' ) ) . '" alt="' . esc_attr( 'Foto allegata: ' . $f->nome_file ) . '" loading="lazy" width="96" height="96"></a>';
	}
	echo '</div>';
}

function apac_op_corso_richiesta( $azienda_id ) {
	global $wpdb;
	$back  = array( 'nuovo' => 'corso_richiesta' );
	$corso = trim( sanitize_text_field( apac_post( 'corso' ) ) );
	if ( '' === $corso ) {
		apac_error_field( 'corso' );
		apac_redirect( 'segnalazioni', 'need_field', $back );
	}
	if ( 'Altro' === $corso ) {
		$corso = trim( sanitize_text_field( apac_post( 'corso_altro' ) ) );
		if ( '' === $corso ) {
			apac_error_field( 'corso_altro' );
			apac_redirect( 'segnalazioni', 'need_corso_altro', $back );
		}
	} elseif ( ! in_array( $corso, apac_corsi(), true ) ) {
		apac_error_field( 'corso' );
		apac_redirect( 'segnalazioni', 'bad_value', $back );
	}
	$nomi = array();
	$ids  = isset( $_POST['lavoratori'] ) && is_array( $_POST['lavoratori'] ) ? array_map( 'absint', wp_unslash( $_POST['lavoratori'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
	foreach ( array_unique( array_filter( $ids ) ) as $lid ) {
		$l = apac_get_row_for_azienda( 'lavoratori', $lid, $azienda_id );
		if ( ! $l ) {
			apac_redirect( 'segnalazioni', 'bad_lavoratore', $back );
		}
		$nomi[] = $l->cognome . ' ' . $l->nome;
	}
	$note = sanitize_textarea_field( apac_post( 'note' ) );
	apac_check_lengths( array( 'titolo' => 'Richiesta corso: ' . $corso, 'descrizione' => $note ), 'segnalazioni', $back );
	$descr = 'Corso richiesto: ' . $corso . "\n" . 'Lavoratori: ' . ( $nomi ? implode( ', ', $nomi ) : 'da definire' ) . ( '' !== $note ? "\n\n" . $note : '' );
	apac_must_insert(
		'segnalazioni',
		array(
			'azienda_id'  => $azienda_id,
			'titolo'      => mb_substr( 'Richiesta corso: ' . $corso, 0, 200 ),
			'descrizione' => $descr,
			'priorita'    => 'media',
			'stato'       => 'aperta',
			'tipo'        => 'corso',
			'autore_id'   => get_current_user_id(),
			'created_at'  => apac_now(),
			'updated_at'  => apac_now(),
		),
		'segnalazioni',
		$back
	);
	if ( ! apac_is_admin() ) {
		$az = apac_get_azienda( $azienda_id );
		apac_tx_after_commit(
			function () use ( $az, $descr ) {
				$ok = apac_send_mail( get_option( 'admin_email' ), '[Area clienti] Richiesta corso da ' . $az->ragione_sociale, "Azienda: {$az->ragione_sociale}\n\n{$descr}\n\n" . apac_portal_page_url(), apac_auto_headers() );
				return $ok ? '' : 'sent_nomail';
			}
		);
		apac_push_after_commit(
			function () use ( $az, $corso ) {
				apac_push_admins( 'Richiesta corso', $az->ragione_sociale . ': ' . $corso, apac_url( 'segnalazioni', array( 'azienda' => (int) $az->id ) ) );
			}
		);
	}
	apac_log_detail( $corso . ( $nomi ? ' – ' . count( $nomi ) . ' lavorator' . ( 1 === count( $nomi ) ? 'e' : 'i' ) : '' ) );
	apac_redirect( 'segnalazioni', 'course_sent' );
}

function apac_render_corso_box( $az ) {
	$id   = (int) $az->id;
	$pre  = isset( $_GET['lav'] ) ? absint( $_GET['lav'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	$lavs = apac_get_lavoratori( $id, true );
	$corsi = array( '' => '— Seleziona —' );
	foreach ( apac_corsi() as $c ) {
		$corsi[ $c ] = $c;
	}
	// phpcs:disable WordPress.Security.EscapeOutput
	echo apac_add_box_open( 'corso_richiesta', 'Richiedi un corso', false, '', 'check' ) . '<section class="apac-card"><h2>Richiedi un corso</h2><p class="apac-muted">Lo studio ti ricontatta per date e modalità.</p>' . apac_form_open( 'corso_richiesta', $id ) . '<div class="apac-fields">';
	echo apac_select( 'Corso *', 'corso', $corsi, '', 'required' );
	echo apac_field( 'Se “Altro”, specifica', 'corso_altro', '', 'text', 'maxlength="150"' );
	echo '</div>';
	if ( $lavs ) {
		echo '<fieldset class="apac-pick"><legend>Per quali lavoratori (facoltativo)</legend><div class="apac-pick__list">';
		foreach ( $lavs as $l ) {
			echo '<label><input type="checkbox" name="lavoratori[]" value="' . (int) $l->id . '"' . checked( $pre, (int) $l->id, false ) . '><span>' . esc_html( $l->cognome . ' ' . $l->nome ) . ( '' !== $l->mansione ? ' <small>' . esc_html( $l->mansione ) . '</small>' : '' ) . '</span></label>';
		}
		echo '</div></fieldset>';
	}
	echo '<div class="apac-fields">' . apac_textarea( 'Note', 'note', '', 'Es. periodo preferito, sede, numero di partecipanti.' ) . '</div>';
	echo '<div class="apac-actions"><button class="apac-btn" type="submit">Invia la richiesta</button></div></form></section>' . apac_add_box_close();
	// phpcs:enable
}
