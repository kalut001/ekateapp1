<?php
defined( 'ABSPATH' ) || exit;

/**
 * Analisi automatica delle criticità sui DATI INSERITI nell'area clienti.
 *
 * Riferimenti normativi: D.Lgs. 9 aprile 2008, n. 81 e s.m.i.
 * Ogni riferimento è stato controllato sul testo coordinato prima del rilascio.
 * L'analisi segnala dati mancanti: non è una verifica di conformità.
 *
 * @return array Elenco di criticità: [ titolo, livello (alta|media|bassa), norma, dettaglio, sez ]
 */
function apac_analizza_criticita( $azienda_id ) {
	global $wpdb;

	$azienda = apac_get_azienda( $azienda_id );
	if ( ! $azienda ) {
		return array();
	}

	$out = array();

	// 1. DVR presente in archivio.
	$dvr = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM ' . apac_table( 'documenti' ) . " WHERE azienda_id = %d AND categoria = 'dvr'",
			$azienda_id
		)
	);
	if ( 0 === $dvr ) {
		$out[] = array(
			'titolo'    => 'DVR non presente in archivio',
			'livello'   => 'alta',
			'norma'     => 'D.Lgs. 81/2008, art. 17, c. 1, lett. a); artt. 28 e 29',
			'dettaglio' => 'Non risulta caricato alcun documento nella categoria “DVR”. La valutazione dei rischi e l’elaborazione del documento sono obblighi non delegabili del datore di lavoro.',
			'sez'       => 'documenti',
		);
	}

	// 2. RSPP.
	if ( '' === trim( (string) $azienda->rspp_nome ) ) {
		$out[] = array(
			'titolo'    => 'RSPP non indicato',
			'livello'   => 'alta',
			'norma'     => 'D.Lgs. 81/2008, art. 17, c. 1, lett. b)',
			'dettaglio' => 'Nella scheda azienda non è indicato il Responsabile del servizio di prevenzione e protezione. La sua designazione è un obbligo non delegabile del datore di lavoro.',
			'sez'       => 'azienda',
		);
	}

	// 3. RLS / RLST.
	if ( '' === trim( (string) $azienda->rls_nome ) ) {
		$out[] = array(
			'titolo'    => 'RLS / RLST non indicato',
			'livello'   => 'alta',
			'norma'     => 'D.Lgs. 81/2008, art. 47 (RLS) e art. 48 (RLST)',
			'dettaglio' => 'In tutte le aziende o unità produttive è eletto o designato il rappresentante dei lavoratori per la sicurezza; in mancanza, le funzioni sono esercitate dal rappresentante territoriale.',
			'sez'       => 'azienda',
		);
	}

	// 4. Sorveglianza sanitaria e medico competente.
	if ( '' === $azienda->sorveglianza_sanitaria ) {
		$out[] = array(
			'titolo'    => 'Sorveglianza sanitaria: dato non indicato',
			'livello'   => 'media',
			'norma'     => 'D.Lgs. 81/2008, art. 18, c. 1, lett. a); art. 41',
			'dettaglio' => 'Indicare nella scheda azienda se la sorveglianza sanitaria è prevista: in tal caso va nominato il medico competente.',
			'sez'       => 'azienda',
		);
	} elseif ( 'si' === $azienda->sorveglianza_sanitaria && '' === trim( (string) $azienda->medico_competente ) ) {
		$out[] = array(
			'titolo'    => 'Medico competente non indicato',
			'livello'   => 'alta',
			'norma'     => 'D.Lgs. 81/2008, art. 18, c. 1, lett. a)',
			'dettaglio' => 'È indicata la sorveglianza sanitaria ma non il medico competente.',
			'sez'       => 'azienda',
		);
	}

	// 5. Addetti alle emergenze.
	$mancanti = array();
	if ( '' === trim( (string) $azienda->addetti_antincendio ) ) {
		$mancanti[] = 'antincendio ed evacuazione';
	}
	if ( '' === trim( (string) $azienda->addetti_primo_soccorso ) ) {
		$mancanti[] = 'primo soccorso';
	}
	if ( $mancanti ) {
		$out[] = array(
			'titolo'    => 'Addetti alle emergenze non indicati',
			'livello'   => 'alta',
			'norma'     => 'D.Lgs. 81/2008, art. 18, c. 1, lett. b)',
			'dettaglio' => 'Mancano gli addetti per: ' . implode( ', ', $mancanti ) . '. Il datore di lavoro deve designarli preventivamente.',
			'sez'       => 'azienda',
		);
	}

	// 6-7. Lavoratori.
	$lavoratori = apac_get_lavoratori( $azienda_id, true );
	if ( ! $lavoratori ) {
		$out[] = array(
			'titolo'    => 'Nessun lavoratore registrato',
			'livello'   => 'bassa',
			'norma'     => '',
			'dettaglio' => 'Inserisci i lavoratori per monitorare mansioni e formazione.',
			'sez'       => 'lavoratori',
		);
	} else {
		$senza_mansione = array();
		$senza_attestati = array();
		foreach ( $lavoratori as $l ) {
			$nome = trim( $l->cognome . ' ' . $l->nome );
			if ( '' === trim( (string) $l->mansione ) ) {
				$senza_mansione[] = $nome;
			}
			$n = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . apac_table( 'attestati' ) . ' WHERE azienda_id = %d AND lavoratore_id = %d',
					$azienda_id,
					$l->id
				)
			);
			if ( 0 === $n ) {
				$senza_attestati[] = $nome;
			}
		}
		if ( $senza_mansione ) {
			$out[] = array(
				'titolo'    => 'Mansioni non definite',
				'livello'   => 'media',
				'norma'     => 'D.Lgs. 81/2008, art. 28, c. 2, lett. f); art. 37, c. 1, lett. b)',
				'dettaglio' => 'Lavoratori senza mansione: ' . implode( ', ', $senza_mansione ) . '. La mansione serve a individuare i rischi specifici e la formazione dovuta.',
				'sez'       => 'lavoratori',
			);
		}
		if ( $senza_attestati ) {
			$out[] = array(
				'titolo'    => 'Formazione non registrata',
				'livello'   => 'media',
				'norma'     => 'D.Lgs. 81/2008, art. 18, c. 1, lett. l); art. 37',
				'dettaglio' => 'Nessun attestato registrato per: ' . implode( ', ', $senza_attestati ) . '.',
				'sez'       => 'lavoratori',
			);
		}
	}

	// 7-bis (1.7.0). Corsi indicati dallo studio per la mansione e non presenti o scaduti.
	if ( function_exists( 'apac_mansioni_corsi' ) && $lavoratori ) {
		$mc   = apac_mansioni_corsi( $azienda_id );
		$gaps = array();
		foreach ( $mc ? $lavoratori : array() as $l ) {
			$miss = array_keys( array_diff( apac_formazione_richiesta( $azienda_id, $l, $mc ), array( 'ok' ) ) );
			if ( $miss ) {
				$gaps[] = trim( $l->cognome . ' ' . $l->nome ) . ' (' . implode( ', ', $miss ) . ')';
			}
		}
		if ( $gaps ) {
			$out[] = array(
				'titolo'    => 'Corsi richiesti per la mansione mancanti o scaduti',
				'livello'   => 'media',
				'norma'     => 'D.Lgs. 81/2008, art. 37',
				'dettaglio' => 'Secondo i corsi indicati dallo studio per ciascuna mansione: ' . implode( '; ', array_slice( $gaps, 0, 8 ) ) . ( count( $gaps ) > 8 ? ' e altri ' . ( count( $gaps ) - 8 ) . '.' : '.' ),
				'sez'       => 'lavoratori',
			);
		}
	}

	// 8. Attestati scaduti.
	$scaduti = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM ' . apac_table( 'attestati' ) . ' a INNER JOIN ' . apac_table( 'lavoratori' ) . ' l ON l.id = a.lavoratore_id
			 WHERE a.azienda_id = %d AND l.attivo = 1 AND a.data_scadenza IS NOT NULL AND a.data_scadenza < %s AND ' . apac_sql_attestato_corrente( 'a' ),
			$azienda_id,
			apac_today()
		)
	);
	if ( $scaduti > 0 ) {
		$out[] = array(
			'titolo'    => 'Attestati di formazione scaduti',
			'livello'   => 'alta',
			'norma'     => 'D.Lgs. 81/2008, art. 37',
			'dettaglio' => $scaduti . ' attestat' . ( 1 === $scaduti ? 'o risulta scaduto' : 'i risultano scaduti' ) . '. Verificare l’aggiornamento previsto per ciascun corso.',
			'sez'       => 'scadenziario',
		);
	}

	// 9. Scadenze superate.
	$sc = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM ' . apac_table( 'scadenze' ) . " WHERE azienda_id = %d AND stato = 'aperta' AND data_scadenza < %s",
			$azienda_id,
			apac_today()
		)
	);
	if ( $sc > 0 ) {
		$out[] = array(
			'titolo'    => 'Adempimenti scaduti nello scadenzario',
			'livello'   => 'alta',
			'norma'     => '',
			'dettaglio' => $sc . ' adempiment' . ( 1 === $sc ? 'o non risulta completato' : 'i non risultano completati' ) . ' entro la data prevista.',
			'sez'       => 'scadenziario',
		);
	}

	// Ordine: alta, media, bassa.
	$peso = array(
		'alta'  => 0,
		'media' => 1,
		'bassa' => 2,
	);
	usort(
		$out,
		function ( $a, $b ) use ( $peso ) {
			return $peso[ $a['livello'] ] - $peso[ $b['livello'] ];
		}
	);

	return $out;
}
