<?php
defined( 'ABSPATH' ) || exit;

/*
 * Ricerca e ordinamento delle aziende (solo amministratori).
 *
 * - Ordine alfabetico per ragione sociale indipendente da maiuscole e accenti
 *   (colonna ragione_sort, calcolata al salvataggio).
 * - Ricerca parziale lato server su ragione sociale, partita IVA e codice fiscale,
 *   con risultati limitati e paginati: il browser non riceve mai l'elenco completo.
 * - Il menu a tendina mostra al massimo APAC_SELECT_MAX aziende (più quella aperta).
 */

define( 'APAC_SELECT_MAX', 50 );
define( 'APAC_SEARCH_PAGE', 10 );
define( 'APAC_LIST_PAGE', 20 );

/** Condizione SQL di ricerca e relativi parametri. */
function apac_aziende_where( $term ) {
	global $wpdb;
	$term = trim( (string) $term );
	if ( '' === $term ) {
		return array( '1=1', array() );
	}
	$name = apac_sort_key( $term );
	$code = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $term ) );
	if ( 0 === strpos( $code, 'IT' ) && preg_match( '/^IT\d+$/', $code ) ) {
		$code = substr( $code, 2 );
	}
	$parts  = array();
	$params = array();
	if ( '' !== $name ) {
		$parts[]  = 'ragione_sort LIKE %s';
		$params[] = '%' . $wpdb->esc_like( $name ) . '%';
	}
	if ( strlen( $code ) >= 3 ) {
		$parts[]  = "UPPER(REPLACE(partita_iva, ' ', '')) LIKE %s";
		$params[] = '%' . $wpdb->esc_like( $code ) . '%';
		$parts[]  = "UPPER(REPLACE(codice_fiscale, ' ', '')) LIKE %s";
		$params[] = '%' . $wpdb->esc_like( $code ) . '%';
	}
	if ( ! $parts ) {
		return array( '1=0', array() );
	}
	return array( '(' . implode( ' OR ', $parts ) . ')', $params );
}

/**
 * Aziende ordinate alfabeticamente, filtrate e paginate.
 *
 * @return array [ 'rows' => [], 'total' => int, 'page' => int, 'pages' => int ]
 */
function apac_aziende_query( $term = '', $page = 1, $per_page = APAC_LIST_PAGE ) {
	global $wpdb;
	list( $where, $params ) = apac_aziende_where( $term );
	$t        = apac_table( 'aziende' );
	$per_page = max( 1, min( 100, (int) $per_page ) );
	$count_sql = "SELECT COUNT(*) FROM {$t} WHERE {$where}";
	$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$pages     = max( 1, (int) ceil( $total / $per_page ) );
	$page      = max( 1, min( $pages, (int) $page ) );
	$sql       = "SELECT id, ragione_sociale, partita_iva, codice_fiscale, comune FROM {$t} WHERE {$where} ORDER BY ragione_sort ASC, id ASC LIMIT %d OFFSET %d";
	$rows      = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	return array(
		'rows'  => (array) $rows,
		'total' => $total,
		'page'  => $page,
		'pages' => $pages,
	);
}

/** Identificativo leggibile per distinguere aziende omonime. */
function apac_azienda_ident( $a ) {
	if ( ! empty( $a->partita_iva ) ) {
		return 'P.IVA ' . $a->partita_iva;
	}
	if ( ! empty( $a->codice_fiscale ) ) {
		return 'C.F. ' . $a->codice_fiscale;
	}
	return 'ID ' . (int) $a->id;
}

function apac_azienda_label( $a ) {
	return $a->ragione_sociale . ' — ' . apac_azienda_ident( $a ) . ( ! empty( $a->comune ) ? ' · ' . $a->comune : '' );
}

/** Opzioni del menu a tendina: prime aziende in ordine alfabetico più quella selezionata. */
function apac_aziende_select_options( $selected_id = 0 ) {
	$q    = apac_aziende_query( '', 1, APAC_SELECT_MAX );
	$opts = array();
	foreach ( $q['rows'] as $a ) {
		$opts[ (int) $a->id ] = $a;
	}
	$selected_id = (int) $selected_id;
	if ( $selected_id && ! isset( $opts[ $selected_id ] ) ) {
		$sel = apac_get_azienda( $selected_id );
		if ( $sel ) {
			$opts = array( $selected_id => $sel ) + $opts;
		}
	}
	return array( $opts, $q['total'] );
}

/** Sezione in cui aprire l'azienda scelta. */
function apac_target_section( $sez ) {
	return in_array( $sez, array( 'utenti', 'pannello', '' ), true ) ? 'dashboard' : $sez;
}

/* ----------------------------- Endpoint AJAX ---------------------------- */

add_action( 'wp_ajax_apac_cerca_aziende', 'apac_ajax_cerca_aziende' );
function apac_ajax_cerca_aziende() {
	if ( ! apac_is_admin() ) {
		wp_send_json_error( array( 'message' => 'Non consentito.' ), 403 );
	}
	if ( ! check_ajax_referer( 'apac_cerca', '_ajax_nonce', false ) ) {
		wp_send_json_error( array( 'message' => 'Sessione scaduta: ricarica la pagina.' ), 403 );
	}
	$term = isset( $_GET['q'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_GET['q'] ) ), 0, 100 ) : '';
	$page = isset( $_GET['page'] ) ? absint( $_GET['page'] ) : 1;
	$sez  = isset( $_GET['sez'] ) ? sanitize_key( wp_unslash( $_GET['sez'] ) ) : 'dashboard';
	if ( ! isset( apac_sections()[ $sez ] ) ) {
		$sez = 'dashboard';
	}
	// 1.9.0: campo vuoto = elenco alfabetico di tutte le aziende (a pagine).
	$browse = '' === trim( $term ) && ! empty( $_GET['browse'] );
	if ( ! $browse && mb_strlen( trim( $term ) ) < 2 ) {
		wp_send_json_success( array( 'items' => array(), 'page' => 1, 'pages' => 1, 'total' => 0 ) );
	}
	$q     = apac_aziende_query( $term, $page, APAC_SEARCH_PAGE );
	$items = array();
	foreach ( $q['rows'] as $a ) {
		// Testo semplice: il browser lo inserisce con textContent (nessun HTML).
		$items[] = array(
			'id'    => (int) $a->id,
			'name'  => (string) $a->ragione_sociale,
			'ident' => apac_azienda_ident( $a ) . ( ! empty( $a->comune ) ? ' · ' . $a->comune : '' ),
			'url'   => esc_url_raw( apac_url( apac_target_section( $sez ), array( 'azienda' => (int) $a->id ) ) ),
		);
	}
	wp_send_json_success(
		array(
			'items' => $items,
			'page'  => $q['page'],
			'pages' => $q['pages'],
			'total' => $q['total'],
		)
	);
}

/* ------------------------------ Interfaccia ----------------------------- */

/**
 * Selettore azienda dello studio (1.9.0): un solo campo "Cerca o scegli un'azienda".
 * Toccandolo si apre l'elenco alfabetico; scrivendo filtra per ragione sociale, P. IVA o C.F.
 * Senza JavaScript il pulsante "Cerca" mostra i risultati (campo vuoto: tutte le aziende).
 */
function apac_render_switcher( $azienda_id, $sez ) {
	$page_id = (int) get_option( 'apac_page_id' );
	$cerca   = isset( $_GET['cerca'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_GET['cerca'] ) ), 0, 100 ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$hidden  = '';
	if ( $page_id && ! get_option( 'permalink_structure' ) ) {
		$hidden .= '<input type="hidden" name="page_id" value="' . (int) $page_id . '">';
	}
	$cur = $azienda_id ? apac_get_azienda( $azienda_id ) : null;

	echo '<div class="apac-switch">';
	echo '<form method="get" class="apac-switch__search" role="search" action="' . esc_url( apac_portal_page_url() ) . '">' . $hidden; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<input type="hidden" name="sez" value="' . esc_attr( $sez ) . '">';
	if ( $azienda_id ) {
		echo '<input type="hidden" name="azienda" value="' . (int) $azienda_id . '">';
	}
	echo '<label for="apac-cerca" class="apac-switch__label"><span>Azienda</span>';
	if ( $cur ) {
		echo '<b class="apac-switch__cur">' . esc_html( $cur->ragione_sociale ) . ' <small>' . esc_html( apac_azienda_ident( $cur ) ) . '</small></b>';
	} else {
		echo '<b class="apac-switch__cur apac-switch__cur--none">nessuna selezionata</b>';
	}
	echo '</label><small id="apac-cerca-hint" class="screen-reader-text">Tocca per l’elenco alfabetico, oppure scrivi ragione sociale, partita IVA o codice fiscale</small>';
	echo '<div class="apac-combo" data-browse="1" data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'apac_cerca' ) ) . '" data-sez="' . esc_attr( $sez ) . '">';
	echo '<span class="apac-combo__ico" aria-hidden="true">' . apac_icon( 'search' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<input type="search" id="apac-cerca" name="cerca" value="' . esc_attr( $cerca ) . '" placeholder="Cerca o scegli un’azienda" aria-describedby="apac-cerca-hint" autocomplete="off" maxlength="100" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="apac-cerca-list">';
	echo '<button type="button" class="apac-combo__clear" aria-label="Cancella la ricerca" hidden>×</button>';
	echo '<button type="submit" class="apac-btn apac-btn--small">Cerca</button>';
	echo '<div class="apac-combo__status" role="status" aria-live="polite"></div>';
	echo '<ul class="apac-combo__list" id="apac-cerca-list" role="listbox" aria-label="Aziende" hidden></ul>';
	echo '</div></form></div>';
	apac_print_combo_script();
}

/** Risultati della ricerca senza JavaScript (pagina normale con link). */
function apac_render_search_results( $sez ) {
	if ( ! isset( $_GET['cerca'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$cerca = mb_substr( sanitize_text_field( wp_unslash( $_GET['cerca'] ) ), 0, 100 ); // phpcs:ignore WordPress.Security.NonceVerification
	$page  = isset( $_GET['cpag'] ) ? absint( $_GET['cpag'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
	echo '<section class="apac-card apac-results"><h2>' . ( '' === trim( $cerca ) ? 'Tutte le aziende' : 'Risultati per “' . esc_html( $cerca ) . '”' ) . '</h2>';
	if ( '' !== trim( $cerca ) && mb_strlen( trim( $cerca ) ) < 2 ) {
		echo '<p class="apac-muted">Scrivi almeno 2 caratteri.</p></section>';
		return;
	}
	$q = apac_aziende_query( $cerca, $page, APAC_LIST_PAGE );
	if ( ! $q['rows'] ) {
		echo '<p class="apac-muted">Nessuna azienda trovata.</p></section>';
		return;
	}
	echo '<p class="apac-muted">' . (int) $q['total'] . ( 1 === (int) $q['total'] ? ' azienda trovata' : ' aziende trovate' ) . '. Scegli quella da aprire: l’azienda attuale resta selezionata finché non ne scegli un’altra.</p><ul class="apac-list">';
	foreach ( $q['rows'] as $a ) {
		echo '<li><a href="' . esc_url( apac_url( apac_target_section( $sez ), array( 'azienda' => (int) $a->id ) ) ) . '"><span>' . esc_html( $a->ragione_sociale ) . '<small>' . esc_html( apac_azienda_ident( $a ) . ( $a->comune ? ' · ' . $a->comune : '' ) ) . '</small></span></a></li>';
	}
	echo '</ul>';
	apac_render_pager( $q, 'cpag', array( 'cerca' => $cerca ) );
	echo '</section>';
}

/** Navigazione tra le pagine di un elenco. */
function apac_render_pager( $q, $param, $extra = array() ) {
	if ( $q['pages'] <= 1 ) {
		return;
	}
	$sez = isset( $_GET['sez'] ) ? sanitize_key( wp_unslash( $_GET['sez'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification
	echo '<nav class="apac-pager" aria-label="Pagine">';
	if ( $q['page'] > 1 ) {
		echo '<a class="apac-btn apac-btn--small apac-btn--ghost" href="' . esc_url( apac_url( $sez, array_merge( $extra, array( $param => $q['page'] - 1 ) ) ) ) . '">← Precedenti</a>';
	}
	echo '<span>Pagina ' . (int) $q['page'] . ' di ' . (int) $q['pages'] . '</span>';
	if ( $q['page'] < $q['pages'] ) {
		echo '<a class="apac-btn apac-btn--small apac-btn--ghost" href="' . esc_url( apac_url( $sez, array_merge( $extra, array( $param => $q['page'] + 1 ) ) ) ) . '">Successive →</a>';
	}
	echo '</nav>';
}

/**
 * Scelta dell'azienda dentro un modulo (es. spostare un utente):
 * menu limitato + ricerca che aggiunge e seleziona il risultato; senza JavaScript
 * si può indicare il numero (ID) dell'azienda mostrato nei risultati di ricerca.
 */
function apac_azienda_picker( $label, $name, $selected_id ) {
	list( $opts ) = apac_aziende_select_options( $selected_id );
	$html  = '<div class="apac-field apac-picker"><span>' . esc_html( $label ) . '</span>';
	$html .= '<select name="' . esc_attr( $name ) . '">';
	foreach ( $opts as $id => $a ) {
		$html .= '<option value="' . (int) $id . '"' . selected( (int) $selected_id, (int) $id, false ) . '>' . esc_html( apac_azienda_label( $a ) ) . '</option>';
	}
	$html .= '</select>';
	$html .= '<div class="apac-combo apac-combo--picker" data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'apac_cerca' ) ) . '" data-sez="dashboard" hidden>';
	$html .= '<input type="search" placeholder="Cerca un’altra azienda…" autocomplete="off" maxlength="100" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-label="Cerca azienda da collegare">';
	$html .= '<div class="apac-combo__status" role="status" aria-live="polite"></div><ul class="apac-combo__list" role="listbox" hidden></ul></div>';
	$html .= '<noscript><label class="apac-field"><span>Oppure numero azienda (ID, dai risultati di “Cerca azienda”)</span><input type="number" min="1" name="' . esc_attr( $name ) . '_id"></label></noscript>';
	$html .= '</div>';
	apac_print_combo_script();
	return $html;
}

/** Script della casella di ricerca (stampato una sola volta). */
function apac_print_combo_script() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	add_action( 'wp_footer', 'apac_combo_script_output', 30 );
}

function apac_combo_script_output() {
	?>
	<script id="apac-combo-js">
	(function () {
		'use strict';
		function setup(box) {
			var input = box.querySelector('input[type="search"]');
			var list = box.querySelector('.apac-combo__list');
			var status = box.querySelector('.apac-combo__status');
			var picker = box.classList.contains('apac-combo--picker');
			var select = picker ? box.parentNode.querySelector('select') : null;
			var timer = null, ctrl = null, active = -1, items = [], page = 1, pages = 1, term = '';
			var browse = box.dataset.browse === '1';
			if (browse) { box.classList.add('is-js'); }
			var clear = box.querySelector('.apac-combo__clear');
			function syncClear() { if (clear) { clear.hidden = !input.value; } }
			if (!list.id) { list.id = 'apac-list-' + Math.random().toString(36).slice(2); }
			input.setAttribute('aria-controls', list.id);
			if (picker) { box.hidden = false; }

			function close() {
				list.hidden = true; input.setAttribute('aria-expanded', 'false');
				input.removeAttribute('aria-activedescendant'); active = -1;
			}
			function say(t) { status.textContent = t; }
			function highlight(i) {
				var opts = list.querySelectorAll('[role="option"]');
				opts.forEach(function (o) { o.classList.remove('is-active'); o.setAttribute('aria-selected', 'false'); });
				if (i >= 0 && opts[i]) {
					opts[i].classList.add('is-active'); opts[i].setAttribute('aria-selected', 'true');
					input.setAttribute('aria-activedescendant', opts[i].id);
					opts[i].scrollIntoView({ block: 'nearest' });
				}
				active = i;
			}
			function choose(it) {
				if (!it) { return; }
				if (picker) {
					var o = select.querySelector('option[value="' + it.id + '"]');
					if (!o) { o = document.createElement('option'); o.value = it.id; o.textContent = it.name + ' — ' + it.ident; select.appendChild(o); }
					select.value = String(it.id); input.value = ''; close(); say('Selezionata: ' + it.name); select.focus();
				} else {
					say('Apertura di ' + it.name + '…'); close();
					if (window.apacGo) { window.apacGo(it.url); } else { window.location.href = it.url; }
				}
			}
			function render(append) {
				if (!append) { list.innerHTML = ''; }
				items.forEach(function (it, i) {
					if (append && i < list.querySelectorAll('[role="option"]').length) { return; }
					var li = document.createElement('li');
					li.setAttribute('role', 'option'); li.id = list.id + '-o' + i; li.setAttribute('aria-selected', 'false');
					var b = document.createElement('strong'); b.textContent = it.name;
					var s = document.createElement('small'); s.textContent = it.ident;
					li.appendChild(b); li.appendChild(s);
					li.addEventListener('mousedown', function (e) { e.preventDefault(); });
					li.addEventListener('click', function () { choose(it); });
					list.appendChild(li);
				});
				var more = list.querySelector('.apac-combo__more');
				if (more) { more.remove(); }
				if (page < pages) {
					var m = document.createElement('li');
					m.className = 'apac-combo__more';
					var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'apac-btn apac-btn--small apac-btn--ghost';
					btn.textContent = 'Mostra altri risultati';
					btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
					btn.addEventListener('click', function () { fetchPage(term, page + 1); });
					m.appendChild(btn); list.appendChild(m);
				}
				list.hidden = false; input.setAttribute('aria-expanded', 'true');
			}
			function fetchPage(q, p) {
				if (ctrl) { ctrl.abort(); }
				ctrl = ('AbortController' in window) ? new AbortController() : null;
				say('Ricerca in corso…'); box.classList.add('is-loading');
				var url = box.dataset.ajax + '?action=apac_cerca_aziende&_ajax_nonce=' + encodeURIComponent(box.dataset.nonce) + '&sez=' + encodeURIComponent(box.dataset.sez) + '&q=' + encodeURIComponent(q) + '&page=' + p + (browse ? '&browse=1' : '');
				fetch(url, { credentials: 'same-origin', signal: ctrl ? ctrl.signal : undefined })
					.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
					.then(function (res) {
						box.classList.remove('is-loading');
						if (q !== input.value.trim()) { return; } // risposta superata: l'utente ha già scritto altro
						if (!res.ok || !res.j || !res.j.success) {
							close(); say((res.j && res.j.data && res.j.data.message) ? res.j.data.message : 'Errore durante la ricerca: riprova.'); return;
						}
						var d = res.j.data; page = d.page; pages = d.pages;
						items = p > 1 ? items.concat(d.items) : d.items;
						if (!items.length) { close(); say(q ? 'Nessuna azienda trovata.' : 'Nessuna azienda.'); return; }
						render(p > 1);
						say(q ? d.total + (d.total === 1 ? ' azienda trovata' : ' aziende trovate') + '. Usa le frecce e Invio per scegliere.' : d.total + (d.total === 1 ? ' azienda' : ' aziende') + ' in ordine alfabetico. Scrivi per filtrare.');
					})
					.catch(function (e) {
						if (e && e.name === 'AbortError') { return; }
						box.classList.remove('is-loading'); close(); say('Errore di connessione: riprova.');
					});
			}
			if (clear) {
				clear.addEventListener('click', function () { input.value = ''; syncClear(); items = []; close(); say(''); input.focus(); });
				syncClear();
			}
			if (browse) {
				input.addEventListener('focus', function () { if (!input.value.trim() && list.hidden) { term = ''; page = 1; fetchPage('', 1); } });
			}
			input.addEventListener('input', function () {
				clearTimeout(timer); syncClear();
				var q = input.value.trim();
				if (!q.length && browse) { timer = setTimeout(function () { term = ''; page = 1; fetchPage('', 1); }, 150); return; }
				if (q.length < 2) { if (ctrl) { ctrl.abort(); } close(); items = []; say(q.length ? 'Scrivi almeno 2 caratteri.' : ''); return; }
				timer = setTimeout(function () { term = q; page = 1; fetchPage(q, 1); }, 250);
			});
			input.addEventListener('keydown', function (e) {
				var n = list.querySelectorAll('[role="option"]').length;
				if (e.key === 'ArrowDown') { e.preventDefault(); if (list.hidden && items.length) { list.hidden = false; input.setAttribute('aria-expanded', 'true'); } highlight(Math.min(n - 1, active + 1)); }
				else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(Math.max(0, active - 1)); }
				else if (e.key === 'Enter') {
					if (active >= 0 && !list.hidden) { e.preventDefault(); choose(items[active]); }
					else if (picker) { e.preventDefault(); }
					// Altrimenti il modulo viene inviato: pagina dei risultati.
				}
				else if (e.key === 'Escape') { close(); }
			});
			input.addEventListener('blur', function () { setTimeout(close, 150); });
		}
		document.querySelectorAll('.apac-combo').forEach(setup);
	})();
	</script>
	<?php
}
