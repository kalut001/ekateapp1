<?php
/**
 * Riepiloghi: completezza dei dati, riepilogo stampabile, elenchi filtrati dei contatori, guida
 * «Primi passi».
 *
 * 1.9.15: file per area; il codice proviene dai file per versione (session1-3.php, v190.php), invariato.
 */
defined( 'ABSPATH' ) || exit;

/**
 * Quanto sono completi i dati inseriti (0-100). NON è un giudizio di conformità.
 * @return array [ 'perc' => int, 'voci' => [ [ etichetta, punteggio 0..1, sezione, dettaglio ] ] ]
 */
function apac_completezza( $azienda_id ) {
	global $wpdb;
	$az = apac_get_azienda( $azienda_id );
	if ( ! $az ) {
		return array( 'perc' => 0, 'voci' => array() );
	}
	$v   = array();
	$dvr = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . apac_table( 'documenti' ) . " WHERE azienda_id = %d AND categoria = 'dvr'", $azienda_id ) );
	$v[] = array( 'DVR caricato in archivio', $dvr ? 1 : 0, 'documenti', '' );
	$v[] = array( 'RSPP indicato', '' !== trim( (string) $az->rspp_nome ) ? 1 : 0, 'azienda', '' );
	$v[] = array( 'RLS / RLST indicato', '' !== trim( (string) $az->rls_nome ) ? 1 : 0, 'azienda', '' );
	$v[] = array( 'Sorveglianza sanitaria e medico competente', ( '' !== $az->sorveglianza_sanitaria && ( 'si' !== $az->sorveglianza_sanitaria || '' !== trim( (string) $az->medico_competente ) ) ) ? 1 : 0, 'azienda', '' );
	$v[] = array( 'Addetti antincendio indicati', '' !== trim( (string) $az->addetti_antincendio ) ? 1 : 0, 'azienda', '' );
	$v[] = array( 'Addetti primo soccorso indicati', '' !== trim( (string) $az->addetti_primo_soccorso ) ? 1 : 0, 'azienda', '' );
	$lav = apac_get_lavoratori( $azienda_id, true );
	$v[] = array( 'Lavoratori inseriti', $lav ? 1 : 0, 'lavoratori', '' );
	if ( $lav ) {
		$mc   = apac_mansioni_corsi( $azienda_id );
		$mans = 0;
		$form = 0;
		$req  = 0;
		$reqok = 0;
		foreach ( $lav as $l ) {
			$mans += '' !== trim( (string) $l->mansione ) ? 1 : 0;
			$form += apac_count( 'attestati', 'azienda_id = %d AND lavoratore_id = %d', $azienda_id, $l->id ) ? 1 : 0;
			foreach ( apac_formazione_richiesta( $azienda_id, $l, $mc ) as $st ) {
				$req++;
				$reqok += 'ok' === $st ? 1 : 0;
			}
		}
		$n   = count( $lav );
		$v[] = array( 'Mansione indicata per ogni lavoratore', $mans / $n, 'lavoratori', $mans . ' su ' . $n );
		$v[] = array( 'Formazione registrata per ogni lavoratore', $form / $n, 'lavoratori', $form . ' su ' . $n );
		if ( $req ) {
			$v[] = array( 'Corsi richiesti per mansione presenti e validi', $reqok / $req, 'lavoratori', $reqok . ' su ' . $req );
		}
	}
	$scad_att = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . apac_table( 'attestati' ) . ' a INNER JOIN ' . apac_table( 'lavoratori' ) . ' l ON l.id = a.lavoratore_id WHERE a.azienda_id = %d AND l.attivo = 1 AND a.data_scadenza IS NOT NULL AND a.data_scadenza < %s AND ' . apac_sql_attestato_corrente( 'a' ), $azienda_id, apac_today() ) );
	$v[]      = array( 'Nessun attestato scaduto', $scad_att ? 0 : 1, 'scadenziario', $scad_att ? $scad_att . ' scadut' . ( 1 === $scad_att ? 'o' : 'i' ) : '' );
	$scad     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . apac_table( 'scadenze' ) . " WHERE azienda_id = %d AND stato = 'aperta' AND data_scadenza < %s", $azienda_id, apac_today() ) );
	$v[]      = array( 'Nessun adempimento superato', $scad ? 0 : 1, 'scadenziario', $scad ? $scad . ' superat' . ( 1 === $scad ? 'o' : 'i' ) : '' );
	$tot = 0;
	foreach ( $v as $x ) {
		$tot += $x[1];
	}
	return array(
		'perc' => (int) floor( 100 * $tot / count( $v ) ),
		'voci' => $v,
	);
}

function apac_completezza_level( $p ) {
	return $p >= 90 ? 'ok' : ( $p >= 60 ? 'warn' : 'danger' );
}

/** Indicatore compatto (Home) o completo (Criticità). */
function apac_render_completezza( $az, $full = false ) {
	$c   = apac_completezza( (int) $az->id );
	$lvl = apac_completezza_level( $c['perc'] );
	$mis = array_filter( $c['voci'], function ( $x ) { return $x[1] < 1; } );
	echo '<section class="apac-card apac-meter apac-meter--' . esc_attr( $lvl ) . ( $full ? '' : ' apac-meter--slim' ) . '"><div class="apac-meter__head"><h2>Completezza dei dati</h2><strong>' . (int) $c['perc'] . '%</strong></div>';
	echo '<div class="apac-meter__bar" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int) $c['perc'] . '" aria-label="Completezza dei dati"><span style="width:' . (int) $c['perc'] . '%"></span></div>';
	echo '<p class="apac-muted apac-meter__note">Indica quanto sono completi i dati inseriti qui: non certifica la conformità alla normativa.</p>';
	if ( $full ) {
		echo '<ul class="apac-meter__list">';
		foreach ( $c['voci'] as $x ) {
			$okk = $x[1] >= 1;
			echo '<li class="' . ( $okk ? 'is-ok' : 'is-miss' ) . '">' . apac_icon( $okk ? 'check' : 'alert' ) . '<span>' . esc_html( $x[0] ) . ( '' !== $x[3] ? ' <small>(' . esc_html( $x[3] ) . ')</small>' : '' ) . '</span>' . ( $okk ? '' : '<a href="' . esc_url( apac_url( $x[2] ) ) . '">Completa</a>' ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul>';
	} elseif ( $mis ) {
		echo '<p class="apac-meter__more"><a href="' . esc_url( apac_url( 'criticita' ) ) . '">Cosa manca (' . count( $mis ) . ') →</a></p>';
	}
	echo '</section>';
}

function apac_report_url( $azienda_id ) {
	$args = array( 'apac_report' => 1 );
	if ( apac_is_admin() ) {
		$args['azienda'] = (int) $azienda_id;
	}
	return add_query_arg( $args, apac_portal_page_url() );
}

add_action( 'init', 'apac_serve_report', 19 );

function apac_serve_report() {
	if ( is_admin() || ! isset( $_GET['apac_report'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	if ( ! is_user_logged_in() || ! apac_user_can_use_portal() ) {
		wp_safe_redirect( apac_portal_page_url() );
		exit;
	}
	$az_id = apac_is_admin() ? absint( $_GET['azienda'] ?? 0 ) : apac_user_azienda_id(); // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! apac_can_access_azienda( $az_id ) ) {
		wp_die( 'Azienda non disponibile.', 'Accesso negato', array( 'response' => 403 ) );
	}
	apac_require_consents_for_download();
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'X-Content-Type-Options: nosniff' );
	apac_render_report( apac_get_azienda( $az_id ) );
	exit;
}

function apac_render_report( $az ) {
	global $wpdb;
	$id    = (int) $az->id;
	$c     = apac_completezza( $id );
	$crit  = apac_analizza_criticita( $id );
	$lav   = apac_get_lavoratori( $id, true );
	$mc    = apac_mansioni_corsi( $id );
	$cats  = apac_categorie_documenti();
	$e     = 'esc_html';
	$today = apac_date_it( apac_today() ) . ' ' . current_time( 'H:i' );
	$sorv  = array( 'si' => 'Sì', 'no' => 'No' );
	?><!doctype html>
<html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title><?php echo $e( 'Riepilogo dati e scadenze – ' . $az->ragione_sociale ); // phpcs:ignore WordPress.Security.EscapeOutput ?></title>
<style>
:root{--g:#193E0C;--gd:#061C03;--y:#FDE117;--t:#202020;--t2:#4A4A4A;--b:#DADADA;--ok:#1d7a46;--okb:#e5f4ea;--w:#7a5b00;--wb:#fff6c7;--d:#b42318;--db:#fde8e6}
*{box-sizing:border-box}body{margin:0;font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:var(--t);background:#f3f3f3}
.page{max-width:900px;margin:0 auto;padding:24px 16px 48px}
.bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px}.bar a,.bar button{font:inherit;font-weight:700;min-height:44px;padding:10px 18px;border-radius:10px;border:1.5px solid var(--g);background:#fff;color:var(--g);cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}.bar button{background:var(--g);color:#fff}
.sheet{background:#fff;border-radius:14px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.06)}
header.h{border-bottom:4px solid var(--y);padding-bottom:14px;margin-bottom:18px}header.h small{display:block;color:var(--t2);font-weight:700;letter-spacing:.06em;text-transform:uppercase;font-size:12px}h1{margin:4px 0 2px;color:var(--gd);font-size:26px;line-height:1.2}header.h p{margin:0;color:var(--t2)}
h2{color:var(--g);font-size:18px;margin:26px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--b);break-after:avoid}
table{width:100%;border-collapse:collapse;font-size:14px}th,td{text-align:left;padding:7px 8px;border-bottom:1px solid #eee;vertical-align:top}th{color:var(--g);font-size:12px;text-transform:uppercase;letter-spacing:.04em}tr{break-inside:avoid}
.kv td:first-child{width:38%;color:var(--t2)}
.b{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap}.ok{background:var(--okb);color:var(--ok)}.warn{background:var(--wb);color:var(--w)}.danger{background:var(--db);color:var(--d)}.muted{background:#eee;color:#555}
.meter{display:flex;align-items:center;gap:14px}.meter strong{font-size:28px;color:var(--gd)}.mbar{flex:1;height:12px;background:#eee;border-radius:99px;overflow:hidden}.mbar span{display:block;height:100%;background:var(--g)}
ul.c{list-style:none;padding-left:0;margin:6px 0}ul.c li{margin:3px 0}.b,.mbar,.mbar span{-webkit-print-color-adjust:exact;print-color-adjust:exact}
.note{font-size:12px;color:var(--t2);margin-top:28px;border-top:1px solid var(--b);padding-top:10px}
@media print{body{background:#fff;font-size:11.5pt}.page{max-width:none;padding:0}.bar{display:none}.sheet{box-shadow:none;padding:0;border-radius:0}h2{margin-top:18px}a{color:inherit;text-decoration:none}@page{size:A4;margin:16mm 14mm}}
@media (max-width:600px){.sheet{padding:18px}.wide{display:block;overflow-x:auto}}
</style></head><body><div class="page">
<div class="bar"><button type="button" onclick="window.print()">Stampa o salva in PDF</button><a href="<?php echo esc_url( apac_url( 'dashboard', apac_is_admin() ? array( 'azienda' => $id ) : array() ) ); ?>">Torna all’area riservata</a></div>
<div class="sheet">
<header class="h"><small>Riepilogo dati e scadenze · AP Sicurezza Lavoro</small><h1><?php echo $e( $az->ragione_sociale ); // phpcs:ignore WordPress.Security.EscapeOutput ?></h1><p>Generato il <?php echo $e( $today ); // phpcs:ignore WordPress.Security.EscapeOutput ?> con i dati presenti nell’area riservata. Non è una certificazione di conformità.</p></header>

<h2>Completezza dei dati</h2>
<div class="meter"><strong><?php echo (int) $c['perc']; ?>%</strong><div class="mbar"><span style="width:<?php echo (int) $c['perc']; ?>%"></span></div></div>
<ul class="c"><?php foreach ( $c['voci'] as $x ) { echo '<li>' . ( $x[1] >= 1 ? '✓ ' : '✗ ' ) . $e( $x[0] ) . ( '' !== $x[3] ? ' (' . $e( $x[3] ) . ')' : '' ) . '</li>'; } // phpcs:ignore WordPress.Security.EscapeOutput ?></ul>

<h2>Azienda e figure della sicurezza</h2>
<table class="kv"><tbody>
<?php
$rows = array(
	'Partita IVA'                => $az->partita_iva,
	'Sede'                       => trim( $az->indirizzo . ' ' . $az->comune . ( $az->provincia ? ' (' . $az->provincia . ')' : '' ) ),
	'Codice ATECO'               => $az->ateco,
	'Datore di lavoro'           => $az->datore_lavoro,
	'RSPP'                       => $az->rspp_nome,
	'RLS / RLST'                 => $az->rls_nome,
	'Sorveglianza sanitaria'     => isset( $sorv[ $az->sorveglianza_sanitaria ] ) ? $sorv[ $az->sorveglianza_sanitaria ] : '',
	'Medico competente'          => $az->medico_competente,
	'Addetti antincendio'        => $az->addetti_antincendio,
	'Addetti primo soccorso'     => $az->addetti_primo_soccorso,
);
foreach ( $rows as $k => $val ) {
	echo '<tr><td>' . $e( $k ) . '</td><td>' . ( '' !== trim( (string) $val ) ? nl2br( $e( $val ) ) : '<span class="b warn">Non indicato</span>' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
}
?>
</tbody></table>

<h2>Punti da verificare (<?php echo count( $crit ); ?>)</h2>
<?php
if ( ! $crit ) {
	echo '<p>Nessuna criticità rilevata sui dati inseriti.</p>';
} else {
	echo '<table><thead><tr><th>Livello</th><th>Punto</th><th>Riferimento</th></tr></thead><tbody>';
	foreach ( $crit as $x ) {
		$cl = array( 'alta' => 'danger', 'media' => 'warn', 'bassa' => 'muted' );
		echo '<tr><td><span class="b ' . esc_attr( $cl[ $x['livello'] ] ) . '">' . $e( ucfirst( $x['livello'] ) ) . '</span></td><td><strong>' . $e( $x['titolo'] ) . '</strong><br>' . $e( $x['dettaglio'] ) . '</td><td>' . $e( $x['norma'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</tbody></table>';
}
?>

<h2>Lavoratori e formazione (<?php echo count( $lav ); ?> in forza)</h2>
<?php
if ( ! $lav ) {
	echo '<p>Nessun lavoratore registrato.</p>';
} else {
	echo '<div class="wide"><table><thead><tr><th>Lavoratore</th><th>Mansione</th><th>Attestati in vigore</th>' . ( $mc ? '<th>Richiesti per la mansione</th>' : '' ) . '</tr></thead><tbody>';
	foreach ( $lav as $l ) {
		$att = $wpdb->get_results( $wpdb->prepare( 'SELECT a.corso, a.data_scadenza FROM ' . apac_table( 'attestati' ) . ' a WHERE a.azienda_id = %d AND a.lavoratore_id = %d AND ' . apac_sql_attestato_corrente( 'a' ) . ' ORDER BY a.corso', $id, $l->id ) );
		$list = array();
		foreach ( $att as $a ) {
			if ( ! $a->data_scadenza ) {
				$b = '<span class="b muted">senza scadenza</span>';
			} else {
				$g = apac_days_until( $a->data_scadenza );
				$b = '<span class="b ' . ( $g < 0 ? 'danger' : ( $g <= 30 ? 'warn' : 'ok' ) ) . '">' . ( $g < 0 ? 'scaduto il ' : 'fino al ' ) . $e( apac_date_it( $a->data_scadenza ) ) . '</span>';
			}
			$list[] = $e( $a->corso ) . ' ' . $b;
		}
		echo '<tr><td><strong>' . $e( $l->cognome . ' ' . $l->nome ) . '</strong></td><td>' . ( '' !== $l->mansione ? $e( $l->mansione ) : '<span class="b warn">Da definire</span>' ) . '</td><td>' . ( $list ? implode( '<br>', $list ) : '<span class="b warn">Nessun attestato</span>' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $mc ) {
			$req = apac_formazione_richiesta( $id, $l, $mc );
			$out = array();
			foreach ( $req as $corso => $st ) {
				$out[] = $e( $corso ) . ' <span class="b ' . ( 'ok' === $st ? 'ok' : ( 'scaduto' === $st ? 'danger' : 'warn' ) ) . '">' . ( 'ok' === $st ? 'presente' : $st ) . '</span>';
			}
			echo '<td>' . ( $out ? implode( '<br>', $out ) : '—' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tr>';
	}
	echo '</tbody></table></div>';
}
?>

<h2>Scadenze dei prossimi 90 giorni e superate</h2>
<?php
$ag = apac_agenda( $id, 90 );
if ( ! $ag ) {
	echo '<p>Nessuna scadenza superata o nei prossimi 90 giorni.</p>';
} else {
	echo '<table><thead><tr><th>Data</th><th>Descrizione</th><th>Tipo</th><th>Stato</th></tr></thead><tbody>';
	foreach ( $ag as $i ) {
		$g = apac_days_until( $i['data'] );
		echo '<tr><td>' . $e( apac_date_it( $i['data'] ) ) . '</td><td>' . $e( $i['titolo'] ) . '</td><td>' . $e( $i['tipo'] ) . '</td><td><span class="b ' . ( $g < 0 ? 'danger' : 'warn' ) . '">' . ( $g < 0 ? 'Superata' : 'Tra ' . (int) $g . ' gg' ) . '</span></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</tbody></table>';
}
?>

<h2>Documenti in archivio</h2>
<?php
$docs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'documenti' ) . ' WHERE azienda_id = %d ORDER BY categoria, created_at DESC', $id ) );
if ( ! $docs ) {
	echo '<p>Nessun documento.</p>';
} else {
	echo '<table><thead><tr><th>Documento</th><th>Categoria</th><th>Caricato</th>' . ( array_filter( wp_list_pluck( $docs, 'richiede_firma' ) ) ? '<th>Prese visione</th>' : '' ) . '</tr></thead><tbody>';
	$has_sign = (bool) array_filter( wp_list_pluck( $docs, 'richiede_firma' ) );
	foreach ( $docs as $d ) {
		echo '<tr><td>' . $e( $d->titolo ) . '</td><td>' . $e( isset( $cats[ $d->categoria ] ) ? $cats[ $d->categoria ] : $d->categoria ) . '</td><td>' . $e( apac_date_it( $d->created_at ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $has_sign ) {
			if ( (int) $d->richiede_firma ) {
				$sha = apac_doc_sha( $d );
				$n   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT user_id) FROM ' . apac_table( 'firme' ) . ' WHERE documento_id = %d AND sha256 = %s', $d->id, $sha ) );
				echo '<td>' . (int) $n . ' firm' . ( 1 === $n ? 'a' : 'e' ) . '</td>';
			} else {
				echo '<td>—</td>';
			}
		}
		echo '</tr>';
	}
	echo '</tbody></table>';
}
?>
<p class="note">Documento generato automaticamente dall’area riservata di AP Sicurezza Lavoro sui dati inseriti dallo studio e dall’azienda. È un supporto alla gestione: non certifica la conformità alla normativa (D.Lgs. 81/2008 e s.m.i.) e non sostituisce la valutazione del datore di lavoro, dell’RSPP e dei professionisti competenti.</p>
</div></div></body></html>
	<?php
}

function apac_elenco_tipi() {
	return array(
		'scadute'      => 'Scadute',
		'entro30'      => 'Entro 30 giorni',
		'segnalazioni' => 'Segnalazioni da gestire',
		'criticita'    => 'Criticità alte',
	);
}

/** Voci di tutte le aziende per un tipo di contatore (stessi criteri e stesse fonti del pannello). */
function apac_elenco_items( $tipo ) {
	global $wpdb;
	$today   = apac_today();
	$items   = array();
	$aziende = array();
	foreach ( apac_get_aziende() as $a ) {
		$aziende[ (int) $a->id ] = $a;
	}
	if ( 'scadute' === $tipo || 'entro30' === $tipo ) {
		foreach ( apac_agenda_tutte( 30 ) as $aid => $list ) {
			if ( ! isset( $aziende[ $aid ] ) ) {
				continue;
			}
			foreach ( $list as $i ) {
				if ( ( 'scadute' === $tipo ) === ( $i['data'] < $today ) ) {
					$items[] = array( 'az' => $aziende[ $aid ], 'title' => $i['titolo'], 'sub' => $i['tipo'] . ' · ' . apac_date_it( $i['data'] ), 'badge' => apac_badge_scadenza( $i['data'] ), 'url' => apac_agenda_url( $i, $aid ), 'sort' => $i['data'] );
				}
			}
		}
	} elseif ( 'segnalazioni' === $tipo ) {
		foreach ( (array) $wpdb->get_results( 'SELECT id, azienda_id, titolo, priorita, created_at FROM ' . apac_table( 'segnalazioni' ) . " WHERE stato <> 'chiusa'" ) as $s ) {
			$aid = (int) $s->azienda_id;
			if ( ! isset( $aziende[ $aid ] ) ) {
				continue;
			}
			$items[] = array( 'az' => $aziende[ $aid ], 'title' => $s->titolo, 'sub' => apac_date_it( $s->created_at ), 'badge' => apac_badge_livello( in_array( $s->priorita, array( 'alta', 'media' ), true ) ? $s->priorita : 'bassa' ), 'url' => apac_url( 'segnalazioni', array( 'azienda' => $aid ) ), 'sort' => 'z' . ( 9999999999 - strtotime( $s->created_at ) ) );
		}
	} else {
		foreach ( $aziende as $aid => $a ) {
			foreach ( apac_criticita_alte( $aid ) as $c ) {
				$items[] = array( 'az' => $a, 'title' => $c['titolo'], 'sub' => $c['norma'], 'badge' => apac_badge_livello( 'alta' ), 'url' => apac_url( $c['sez'], array( 'azienda' => $aid ) ), 'sort' => $a->ragione_sort );
			}
		}
	}
	usort(
		$items,
		function ( $x, $y ) {
			return strcmp( (string) $x['sort'], (string) $y['sort'] );
		}
	);
	return $items;
}

function apac_render_elenco() {
	if ( ! apac_is_admin() ) {
		return;
	}
	$tipi = apac_elenco_tipi();
	$tipo = apac_filter_val( 'tipo', array_keys( $tipi ), 'scadute' );
	$all  = apac_elenco_items( $tipo );
	$page = isset( $_GET['epag'] ) ? max( 1, absint( $_GET['epag'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
	$per  = 30;
	$pages = max( 1, (int) ceil( count( $all ) / $per ) );
	$page  = min( $page, $pages );

	echo '<div class="apac-filters" role="group" aria-label="Tipo di elenco">';
	foreach ( $tipi as $k => $label ) {
		echo '<a href="' . esc_url( apac_url( 'elenco', array( 'tipo' => $k, 'azienda' => 0 ) ) ) . '"' . ( $k === $tipo ? ' class="is-active" aria-current="true"' : '' ) . '>' . esc_html( $label ) . '</a>';
	}
	echo '</div>';
	apac_render_filter_summary( $tipi[ $tipo ] . ' · Tutte le aziende', array(), count( $all ) );
	echo '<section class="apac-card">';
	if ( ! $all ) {
		echo '<p class="apac-empty">Nessuna voce di questo tipo nelle aziende clienti.</p>';
	} else {
		echo '<ul class="apac-list apac-elenco">';
		foreach ( array_slice( $all, ( $page - 1 ) * $per, $per ) as $it ) {
			echo '<li><a href="' . esc_url( $it['url'] ) . '"><span><em class="apac-elenco__az">' . esc_html( $it['az']->ragione_sociale ) . '</em>' . esc_html( $it['title'] ) . ( '' !== (string) $it['sub'] ? '<small>' . esc_html( $it['sub'] ) . '</small>' : '' ) . '</span>' . $it['badge'] . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul>';
		apac_render_pager( array( 'page' => $page, 'pages' => $pages ), 'epag', array( 'tipo' => $tipo, 'azienda' => 0 ) );
	}
	echo '<p><a class="apac-more-link" href="' . esc_url( apac_url( 'pannello', array( 'azienda' => 0 ) ) ) . '">← Tutte le aziende</a></p></section>';
}

function apac_primi_passi_opt( $azienda_id ) {
	$o = get_option( 'apac_pp_' . (int) $azienda_id, array() );
	$o = is_array( $o ) ? $o : array();
	return array(
		'na'     => isset( $o['na'] ) && is_array( $o['na'] ) ? $o['na'] : array(),
		'hidden' => ! empty( $o['hidden'] ),
	);
}

/** Passi con stato: fatto (dati presenti), non applicabile o da fare. */
function apac_primi_passi( $az ) {
	$id   = (int) $az->id;
	$opt  = apac_primi_passi_opt( $id );
	$defs = array(
		'dvr'        => array( 'Carica il DVR', 'documenti', 'documenti', apac_add_url( 'documenti', 'documento_upload' ), apac_count( 'documenti', "azienda_id = %d AND categoria = 'dvr'", $id ) ),
		'lavoratori' => array( 'Aggiungi i lavoratori', 'lavoratori', 'lavoratori', apac_add_url( 'lavoratori', 'lavoratore_save' ), apac_count( 'lavoratori', 'azienda_id = %d', $id ) ),
		'scadenze'   => array( 'Inserisci le scadenze', 'scadenze', 'scadenziario', apac_add_url( 'scadenziario', 'scadenza_save' ), apac_count( 'scadenze', 'azienda_id = %d', $id ) ),
	);
	$out = array();
	foreach ( $defs as $k => $d ) {
		$out[ $k ] = array(
			'label' => $d[0],
			'perm'  => $d[1],
			'url'   => $d[3],
			'done'  => $d[4] > 0,
			'na'    => in_array( $k, $opt['na'], true ),
		);
	}
	return array( $out, $opt['hidden'] );
}

function apac_render_primi_passi( $az ) {
	list( $passi, $hidden ) = apac_primi_passi( $az );
	$aperti = array_filter(
		$passi,
		function ( $p ) {
			return ! $p['done'] && ! $p['na'];
		}
	);
	if ( $hidden || ! $aperti ) {
		return;
	}
	$id = (int) $az->id;
	// phpcs:disable WordPress.Security.EscapeOutput
	echo '<section class="apac-card apac-steps-start" aria-label="Primi passi"><div class="apac-todo__head"><h2>Primi passi</h2><span class="apac-badge apac-badge--muted">' . ( count( $passi ) - count( $aperti ) ) . ' di ' . count( $passi ) . '</span></div>';
	echo '<p class="apac-muted">Tre passi per iniziare. La guida indica solo se i dati iniziali sono stati inseriti: non è una verifica della conformità.</p><ol class="apac-pp">';
	$n = 0;
	foreach ( $passi as $k => $p ) {
		$n++;
		$state = $p['done'] ? 'done' : ( $p['na'] ? 'na' : 'todo' );
		echo '<li class="apac-pp__item is-' . $state . '"><span class="apac-pp__n" aria-hidden="true">' . ( 'todo' === $state ? $n : apac_icon( 'check' ) ) . '</span><span class="apac-pp__txt"><strong>' . esc_html( $p['label'] ) . '</strong>';
		if ( 'done' === $state ) {
			echo '<small>Dati inseriti</small>';
		} elseif ( 'na' === $state ) {
			echo '<small>Non applicabile</small>';
		}
		echo '</span><span class="apac-pp__act">';
		if ( 'todo' === $state ) {
			if ( apac_client_can( $id, $p['perm'] ) ) {
				echo '<a class="apac-btn apac-btn--small" href="' . esc_url( $p['url'] ) . '">Inizia</a>';
			} else {
				echo '<a class="apac-btn apac-btn--small apac-btn--ghost" href="' . esc_url( apac_add_url( 'segnalazioni', 'segnalazione_create' ) ) . '">Chiedi allo studio</a>';
			}
		}
		if ( apac_is_admin() && ! $p['done'] ) {
			echo apac_button_form( 'primi_passi', $id, array( 'passo' => $k, 'azione' => $p['na'] ? 'ripristina' : 'na' ), $p['na'] ? 'Ripristina' : 'Non applicabile', 'apac-btn apac-btn--small apac-btn--ghost' );
		}
		echo '</span></li>';
	}
	echo '</ol>';
	if ( apac_is_admin() ) {
		echo '<div class="apac-pp__hide">' . apac_button_form( 'primi_passi', $id, array( 'azione' => 'nascondi' ), 'Nascondi la guida per questa azienda', 'apac-linkbtn' ) . '</div>';
	}
	echo '</section>';
	// phpcs:enable
}

function apac_op_primi_passi( $azienda_id ) {
	$azione = sanitize_key( apac_post( 'azione' ) );
	$passo  = sanitize_key( apac_post( 'passo' ) );
	$opt    = apac_primi_passi_opt( $azienda_id );
	if ( 'nascondi' === $azione ) {
		$opt['hidden'] = true;
	} elseif ( in_array( $passo, array( 'dvr', 'lavoratori', 'scadenze' ), true ) && in_array( $azione, array( 'na', 'ripristina' ), true ) ) {
		$opt['na'] = array_values( array_diff( $opt['na'], array( $passo ) ) );
		if ( 'na' === $azione ) {
			$opt['na'][] = $passo;
		}
	} else {
		apac_redirect( 'dashboard', 'invalid' );
	}
	update_option( 'apac_pp_' . (int) $azienda_id, $opt, false );
	apac_redirect( 'dashboard', 'saved' );
}
