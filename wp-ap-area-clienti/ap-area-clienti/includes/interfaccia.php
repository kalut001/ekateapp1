<?php
/**
 * Interfaccia dell'area riservata: preferenze di aspetto, intestazione e menu, ricerca, filtri,
 * messaggi, script della pagina.
 *
 * 1.9.15: file per area; il codice proviene dai file per versione (session1-3.php, v190.php), invariato.
 */
defined( 'ABSPATH' ) || exit;

/*
 * Nell'area riservata (e quindi nella web app) la barra grigia di WordPress
 * non compare: evita di aprire per errore "Modifica pagina".
 * La bacheca resta raggiungibile dal link "Bacheca WordPress" del pannello.
 */
add_action( 'template_redirect', 'apac_hide_admin_bar_portal', -100 );

function apac_hide_admin_bar_portal() {
	if ( function_exists( 'apac_is_portal_request' ) && apac_is_portal_request() ) {
		add_filter( 'show_admin_bar', '__return_false', 1000 );
	}
}

function apac_prefs( $user_id = 0 ) {
	$user_id = $user_id ? $user_id : get_current_user_id();
	$text    = (string) get_user_meta( $user_id, 'apac_pref_text', true );
	$theme   = (string) get_user_meta( $user_id, 'apac_pref_theme', true );
	return array(
		'text'  => in_array( $text, array( 'normale', 'grande' ), true ) ? $text : 'normale',
		'theme' => in_array( $theme, array( 'chiaro', 'scuro', 'auto' ), true ) ? $theme : 'chiaro',
	);
}

/** Classi da aggiungere al contenitore dell'area riservata. */
function apac_pref_classes() {
	if ( ! is_user_logged_in() ) {
		return '';
	}
	$p = apac_prefs();
	$c = '';
	if ( 'grande' === $p['text'] ) {
		$c .= ' apac--big';
	}
	if ( 'scuro' === $p['theme'] ) {
		$c .= ' apac--dark';
	} elseif ( 'auto' === $p['theme'] ) {
		$c .= ' apac--auto';
	}
	return $c;
}

add_action( 'init', 'apac_save_prefs', 19 );

function apac_save_prefs() {
	if ( is_admin() || ! isset( $_POST['apac_pref'] ) || ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$nonce = isset( $_POST['_apac_pref_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_apac_pref_nonce'] ) ) : '';
	$back  = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : '';
	$base  = apac_portal_page_url();
	if ( '' === $back || 0 !== strpos( $back, $base ) ) {
		$back = $base;
	}
	if ( ! wp_verify_nonce( $nonce, 'apac_pref' ) ) {
		wp_safe_redirect( $back );
		exit;
	}
	$text  = isset( $_POST['pref_text'] ) ? sanitize_key( wp_unslash( $_POST['pref_text'] ) ) : 'normale';
	$theme = isset( $_POST['pref_theme'] ) ? sanitize_key( wp_unslash( $_POST['pref_theme'] ) ) : 'chiaro';
	update_user_meta( get_current_user_id(), 'apac_pref_text', in_array( $text, array( 'normale', 'grande' ), true ) ? $text : 'normale' );
	update_user_meta( get_current_user_id(), 'apac_pref_theme', in_array( $theme, array( 'chiaro', 'scuro', 'auto' ), true ) ? $theme : 'chiaro' );
	nocache_headers();
	wp_safe_redirect( $back );
	exit;
}

/** Riquadro "Aspetto" (nel menu "Altro" e in fondo al menu laterale). Funziona anche senza JavaScript. */
function apac_render_prefs( $id ) {
	$p    = apac_prefs();
	$back = ( is_ssl() ? 'https://' : 'http://' ) . ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' );
	$back = remove_query_arg( array( 'apac_msg', 'apac_form', 'nuovo' ), $back );
	$opt  = function ( $name, $value, $label, $cur ) {
		return '<label class="apac-seg__opt"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . checked( $cur, $value, false ) . ' data-apac-autosubmit><span>' . esc_html( $label ) . '</span></label>';
	};
	$h  = '<form method="post" class="apac-prefs" action="' . esc_url( apac_portal_post_url() ) . '" id="' . esc_attr( $id ) . '">';
	$h .= '<input type="hidden" name="apac_pref" value="1"><input type="hidden" name="_apac_pref_nonce" value="' . esc_attr( wp_create_nonce( 'apac_pref' ) ) . '"><input type="hidden" name="back" value="' . esc_attr( $back ) . '">';
	$h .= '<fieldset class="apac-seg"><legend>Dimensione del testo</legend>' . $opt( 'pref_text', 'normale', 'Normale', $p['text'] ) . $opt( 'pref_text', 'grande', 'Grande', $p['text'] ) . '</fieldset>';
	$h .= '<fieldset class="apac-seg apac-seg--3"><legend>Tema</legend>' . $opt( 'pref_theme', 'chiaro', 'Chiaro', $p['theme'] ) . $opt( 'pref_theme', 'scuro', 'Scuro', $p['theme'] ) . $opt( 'pref_theme', 'auto', 'Come il telefono', $p['theme'] ) . '</fieldset>';
	$h .= '<button type="submit" class="apac-btn apac-btn--small apac-prefs__save">Salva</button></form>';
	return $h;
}

/** Icone aggiuntive per le istruzioni. */
function apac_icon_extra( $name ) {
	$p = array(
		'share-ios' => '<path d="M12 3v12M8 7l4-4 4 4"/><path d="M6 11H5a1.5 1.5 0 0 0-1.5 1.5v7A1.5 1.5 0 0 0 5 21h14a1.5 1.5 0 0 0 1.5-1.5v-7A1.5 1.5 0 0 0 19 11h-1"/>',
		'add-home'  => '<rect x="3.5" y="3.5" width="17" height="17" rx="4"/><path d="M12 8v8M8 12h8"/>',
		'phone'     => '<rect x="6.5" y="2.5" width="11" height="19" rx="2.5"/><path d="M10.5 18.5h3"/>',
		'copy'      => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5.5A1.5 1.5 0 0 0 14.5 4h-9A1.5 1.5 0 0 0 4 5.5v9A1.5 1.5 0 0 0 5.5 16H8"/>',
		'contrast'  => '<circle cx="12" cy="12" r="8.5"/><path d="M12 3.5a8.5 8.5 0 0 1 0 17z" fill="currentColor" stroke="none"/>',
		'gear'      => '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M5.3 18.7l2.1-2.1M16.6 7.4l2.1-2.1"/>',
		'dots-v'    => '<circle cx="12" cy="5.5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="18.5" r="1.6"/>',
		'bell'      => '<path d="M6 16.5V11a6 6 0 0 1 12 0v5.5l1.5 2h-15z"/><path d="M10 20.5a2 2 0 0 0 4 0"/>',
		'pen'       => '<path d="M4 20c3-1 4.5-2.5 6-5"/><path d="M14.5 4.5l5 5L10 19l-5.5.5L5 14z"/>',
		'print'     => '<path d="M7 9V3.5h10V9"/><rect x="3.5" y="9" width="17" height="8" rx="1.5"/><path d="M7 14h10v6.5H7z"/>',
		'whatsapp'  => '<path d="M4 20l1.2-3.6A8 8 0 1 1 8 19.1z"/><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5l1.2-1.3-1.8-1-1 .8a4 4 0 0 1-2.4-2.4l.8-1-1-1.8z"/>',
	);
	if ( ! isset( $p[ $name ] ) ) {
		return apac_icon( $name );
	}
	return '<svg class="apac-ico" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $p[ $name ] . '</svg>';
}

/** Script dell'interfaccia: installazione, condivisione, copia, preferenze, web app. */
add_action( 'wp_footer', 'apac_ui_script', 40 );

function apac_ui_script() {
	if ( ! function_exists( 'apac_is_portal_request' ) || ! apac_is_portal_request() ) {
		return;
	}
	$cfg = array(
		'sw'    => apac_sw_url(),
		'scope' => apac_portal_page_url(),
	);
	if ( is_user_logged_in() && function_exists( 'apac_push_supported' ) && apac_push_supported() && apac_user_can_use_portal() ) {
		$keys = apac_push_keys();
		if ( $keys ) {
			$cfg['push'] = array(
				'key'   => $keys['pub'],
				'url'   => apac_portal_page_url(),
				'nonce' => wp_create_nonce( 'apac_push' ),
				'user'  => get_current_user_id(),
			);
		}
	}
	echo '<script>window.apacUi=' . wp_json_encode( $cfg ) . ';</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	?>
<script>
(function () {
  'use strict';
  var cfg = window.apacUi || {};
  var ua = navigator.userAgent || '';
  var standalone = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
  var ios = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var android = /Android/i.test(ua);
  var iosOther = ios && /CriOS|FxiOS|EdgiOS|OPiOS|GSA\/|YaBrowser|DuckDuckGo|Instagram|FBAN|FBAV|Line\//.test(ua);
  var hidden = false;
  try { hidden = Date.now() - Number(localStorage.getItem('apac-install-hide') || 0) < 30 * 86400000; } catch (e) {}
  var cards = Array.prototype.slice.call(document.querySelectorAll('.apac-install'));
  var deferred = null;

  function setMode(m) {
    cards.forEach(function (c) {
      if (c.getAttribute('data-dismissable') && hidden) { c.hidden = true; return; }
      c.setAttribute('data-mode', m);
      c.hidden = !m;
    });
  }
  if (!standalone && (ios || android)) {
    setMode(ios ? (iosOther ? 'ios-other' : 'ios') : 'android-menu');
  }
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferred = e;
    if (!standalone) setMode('android');
  });
  window.addEventListener('appinstalled', function () { setMode(''); });
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest ? ev.target.closest('[data-apac-install-go],[data-apac-install-hide],[data-apac-copy],[data-apac-share]') : null;
    if (!t) return;
    if (t.hasAttribute('data-apac-install-go')) {
      if (!deferred) { setMode('android-menu'); return; }
      var p = deferred; deferred = null;
      p.prompt();
      if (p.userChoice) p.userChoice.then(function (r) { setMode(r && r.outcome === 'accepted' ? '' : 'android-menu'); });
    } else if (t.hasAttribute('data-apac-install-hide')) {
      try { localStorage.setItem('apac-install-hide', String(Date.now())); } catch (e) {}
      var card = t.closest('.apac-install'); if (card) card.hidden = true;
    } else if (t.hasAttribute('data-apac-copy')) {
      var txt = t.getAttribute('data-apac-copy');
      var done = function () { var s = t.querySelector('span'); if (s) { var o = s.textContent; s.textContent = 'Copiato ✓'; setTimeout(function () { s.textContent = o; }, 2000); } };
      if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(txt).then(done, function () { fallback(txt); done(); }); } else { fallback(txt); done(); }
    } else if (t.hasAttribute('data-apac-share')) {
      navigator.share({ title: t.getAttribute('data-title'), text: t.getAttribute('data-text'), url: t.getAttribute('data-url') }).catch(function () {});
    }
  });
  function fallback(txt) {
    var a = document.createElement('textarea'); a.value = txt; a.setAttribute('readonly', ''); a.style.position = 'fixed'; a.style.opacity = '0';
    document.body.appendChild(a); a.select(); try { document.execCommand('copy'); } catch (e) {} document.body.removeChild(a);
  }
  if (navigator.share) {
    document.querySelectorAll('[data-apac-share]').forEach(function (b) { b.hidden = false; });
  }
  // Preferenze: salvataggio immediato alla scelta.
  document.querySelectorAll('[data-apac-autosubmit]').forEach(function (r) {
    r.addEventListener('change', function () { if (r.form) r.form.submit(); });
  });
  document.querySelectorAll('.apac-prefs__save').forEach(function (b) { b.hidden = true; });
  // Numero massimo di file (foto delle segnalazioni).
  document.querySelectorAll('input[type=file][data-apac-max]').forEach(function (f) {
    f.addEventListener('change', function () {
      var max = parseInt(f.getAttribute('data-apac-max'), 10);
      f.setCustomValidity(f.files && f.files.length > max ? 'Puoi allegare al massimo ' + max + ' foto.' : '');
      if (f.files && f.files.length > max) f.reportValidity();
    });
  });
  // Web app: file servito dall'area riservata (sempre aggiornato).
  if ('serviceWorker' in navigator && window.isSecureContext && cfg.sw) {
    navigator.serviceWorker.register(cfg.sw, { scope: cfg.scope, updateViaCache: 'none' }).catch(function () {});
  }
})();
</script>
	<?php
}

function apac_render_search_box( $q = '' ) {
	echo '<form method="get" class="apac-find" role="search" action="' . esc_url( apac_portal_page_url() ) . '">';
	if ( ! get_option( 'permalink_structure' ) && get_option( 'apac_page_id' ) ) {
		echo '<input type="hidden" name="page_id" value="' . (int) get_option( 'apac_page_id' ) . '">';
	}
	echo '<input type="hidden" name="sez" value="cerca">';
	if ( apac_is_admin() && ! empty( $_GET['azienda'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		echo '<input type="hidden" name="azienda" value="' . absint( $_GET['azienda'] ) . '">'; // phpcs:ignore WordPress.Security.NonceVerification
	}
	echo '<label class="screen-reader-text" for="apac-find-q">Cerca nell’area riservata</label>';
	echo '<span class="apac-find__ico">' . apac_icon_extra( 'search' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<input type="search" id="apac-find-q" name="q" value="' . esc_attr( $q ) . '" placeholder="Nome, corso, documento…" minlength="2" maxlength="80" autocomplete="off">';
	echo '<button type="submit" class="apac-btn apac-btn--small">Cerca</button></form>';
}

function apac_render_cerca( $az ) {
	global $wpdb;
	$id = (int) $az->id;
	$q  = isset( $_GET['q'] ) ? mb_substr( trim( sanitize_text_field( wp_unslash( $_GET['q'] ) ) ), 0, 80 ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	apac_render_search_box( $q );
	if ( mb_strlen( $q ) < 2 ) {
		echo '<p class="apac-muted">Scrivi almeno 2 caratteri: nome o codice fiscale di un lavoratore, titolo di un documento, descrizione di una scadenza o nome di un corso.</p>';
		return;
	}
	$like = '%' . $wpdb->esc_like( $q ) . '%';
	$lav  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'lavoratori' ) . ' WHERE azienda_id = %d AND (CONCAT(cognome, " ", nome) LIKE %s OR CONCAT(nome, " ", cognome) LIKE %s OR codice_fiscale LIKE %s OR mansione LIKE %s) ORDER BY cognome, nome LIMIT 30', $id, $like, $like, $like, $like ) );
	$doc  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'documenti' ) . ' WHERE azienda_id = %d AND (titolo LIKE %s OR nome_file LIKE %s) ORDER BY created_at DESC LIMIT 30', $id, $like, $like ) );
	$sca  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . apac_table( 'scadenze' ) . ' WHERE azienda_id = %d AND (titolo LIKE %s OR note LIKE %s) ORDER BY data_scadenza ASC LIMIT 30', $id, $like, $like ) );
	$att  = $wpdb->get_results( $wpdb->prepare( 'SELECT a.*, l.nome, l.cognome FROM ' . apac_table( 'attestati' ) . ' a INNER JOIN ' . apac_table( 'lavoratori' ) . ' l ON l.id = a.lavoratore_id WHERE a.azienda_id = %d AND a.corso LIKE %s ORDER BY l.cognome, a.corso LIMIT 30', $id, $like ) );
	$tot  = count( $lav ) + count( $doc ) + count( $sca ) + count( $att );
	echo '<p class="apac-find__count" role="status">' . ( $tot ? (int) $tot . ( 1 === $tot ? ' risultato' : ' risultati' ) . ' per «' . esc_html( $q ) . '»' : 'Nessun risultato per «' . esc_html( $q ) . '». Prova con una parola diversa.' ) . '</p>';
	$group = function ( $title, $icon, $items ) {
		if ( ! $items ) {
			return;
		}
		echo '<section class="apac-card apac-find__group"><h2>' . apac_icon( $icon ) . esc_html( $title ) . ' <small>(' . count( $items ) . ')</small></h2><ul class="apac-list">'; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( $items as $it ) {
			echo '<li><a href="' . esc_url( $it[0] ) . '"><span>' . esc_html( $it[1] ) . '<small>' . esc_html( $it[2] ) . '</small></span>' . ( isset( $it[3] ) ? $it[3] : apac_icon( 'arrow' ) ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul></section>';
	};
	$cats_d = apac_categorie_documenti();
	$cats_s = apac_categorie_scadenze();
	$group( 'Lavoratori', 'users', array_map( function ( $l ) { return array( apac_url( 'lavoratori', array( 'lav' => (int) $l->id ) ), $l->cognome . ' ' . $l->nome, ( '' !== $l->mansione ? $l->mansione : 'Mansione da definire' ) . ( $l->attivo ? '' : ' · cessato' ) ); }, $lav ) );
	$group( 'Attestati di formazione', 'check', array_map( function ( $a ) { return array( apac_url( 'lavoratori', array( 'lav' => (int) $a->lavoratore_id ) ), $a->corso, $a->cognome . ' ' . $a->nome . ( $a->data_scadenza ? ' · scade il ' . apac_date_it( $a->data_scadenza ) : '' ) ); }, $att ) );
	$group( 'Documenti', 'file', array_map( function ( $d ) use ( $cats_d ) { return array( apac_file_url( $d->id, 'view' ), $d->titolo, ( isset( $cats_d[ $d->categoria ] ) ? $cats_d[ $d->categoria ] : $d->categoria ) . ' · ' . apac_date_it( $d->created_at ) ); }, $doc ) );
	$group( 'Scadenze', 'calendar', array_map( function ( $s ) use ( $cats_s ) { return array( apac_url( 'scadenziario', array( 'tutte' => 1 ) ), $s->titolo, ( isset( $cats_s[ $s->categoria ] ) ? $cats_s[ $s->categoria ] : $s->categoria ) . ' · ' . apac_date_it( $s->data_scadenza ), 'completata' === $s->stato ? '<span class="apac-badge apac-badge--ok">Completato</span>' : apac_badge_scadenza( $s->data_scadenza ) ); }, $sca ) );
}

/** Valore di un filtro dalla richiesta, solo se ammesso. */
function apac_filter_val( $name, $allowed, $default = '' ) {
	$v = isset( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) : $default; // phpcs:ignore WordPress.Security.NonceVerification
	return in_array( $v, $allowed, true ) ? $v : $default;
}

/**
 * Barra "Filtri e ordinamento" richiudibile. $fields: name => [label, options, current].
 * Senza JavaScript si applica con il pulsante; con JavaScript alla scelta.
 */
function apac_render_filterbar( $sez, $fields, $keep = array() ) {
	$active = 0;
	foreach ( $fields as $name => $f ) {
		if ( '' !== (string) $f[2] && (string) $f[2] !== (string) array_key_first( $f[1] ) ) {
			$active++;
		}
	}
	// 1.9.0: i filtri attivi sono già indicati (e rimovibili) nella riga sotto: il pannello resta chiuso.
	echo '<details class="apac-filterbar"><summary>' . apac_icon_extra( 'filter' ) . '<span>Filtri e ordinamento</span>' . ( $active ? '<b class="apac-filterbar__n">' . (int) $active . '</b>' : '' ) . '</summary>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<form method="get" class="apac-filterbar__form" action="' . esc_url( apac_portal_page_url() ) . '">';
	if ( ! get_option( 'permalink_structure' ) && get_option( 'apac_page_id' ) ) {
		echo '<input type="hidden" name="page_id" value="' . (int) get_option( 'apac_page_id' ) . '">';
	}
	echo '<input type="hidden" name="sez" value="' . esc_attr( $sez ) . '">';
	if ( apac_is_admin() && ! empty( $_GET['azienda'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		echo '<input type="hidden" name="azienda" value="' . absint( $_GET['azienda'] ) . '">'; // phpcs:ignore WordPress.Security.NonceVerification
	}
	foreach ( $keep as $k => $v ) {
		echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
	}
	foreach ( $fields as $name => $f ) {
		echo '<label class="apac-field"><span>' . esc_html( $f[0] ) . '</span><select name="' . esc_attr( $name ) . '" data-apac-autosubmit>';
		foreach ( $f[1] as $val => $lab ) {
			echo '<option value="' . esc_attr( $val ) . '"' . selected( (string) $f[2], (string) $val, false ) . '>' . esc_html( $lab ) . '</option>';
		}
		echo '</select></label>';
	}
	echo '<div class="apac-filterbar__actions"><button type="submit" class="apac-btn apac-btn--small apac-prefs__save">Applica</button>';
	if ( $active ) {
		$reset = apac_url( $sez, $keep );
		echo '<a class="apac-filterbar__reset" href="' . esc_url( $reset ) . '">Azzera filtri</a>';
	}
	echo '</div></form></details>';
}

add_action( 'wp_footer', 'apac_s3_script', 41 );

function apac_s3_script() {
	if ( ! function_exists( 'apac_is_portal_request' ) || ! apac_is_portal_request() || ! is_user_logged_in() ) {
		return;
	}
	?>
<script>
(function () {
  'use strict';
  var cfg = window.apacUi || {};
  /* ---------- Firma con il dito o con il mouse ---------- */
  Array.prototype.forEach.call(document.querySelectorAll('[data-sign-pad]'), function (cv) {
    var form = cv.closest('form'), out = form.querySelector('[data-sign-out]'), hint = form.querySelector('[data-sign-hint]');
    var ctx = cv.getContext('2d'), drawing = false, strokes = 0, last = null, ready = false;
    function size() {
      var r = cv.getBoundingClientRect(); if (!r.width) return;
      var ratio = Math.max(window.devicePixelRatio || 1, 1);
      var old = strokes ? cv.toDataURL('image/png') : null;
      cv.width = Math.round(r.width * ratio); cv.height = Math.round(r.height * ratio);
      ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
      ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0b1f4d';
      if (old) { var im = new Image(); im.onload = function () { ctx.drawImage(im, 0, 0, r.width, r.height); }; im.src = old; }
      ready = true;
    }
    function pos(e) { var r = cv.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }
    cv.addEventListener('pointerdown', function (e) { if (!ready) size(); drawing = true; last = pos(e); cv.setPointerCapture(e.pointerId); ctx.beginPath(); ctx.arc(last.x, last.y, 1.1, 0, 6.3); ctx.fillStyle = '#0b1f4d'; ctx.fill(); e.preventDefault(); });
    cv.addEventListener('pointermove', function (e) { if (!drawing) return; var p = pos(e); ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke(); last = p; e.preventDefault(); });
    function exportImg() {
      if (out.getAttribute('data-sign-format') !== 'jpeg') return cv.toDataURL('image/png');
      var t = document.createElement('canvas'); t.width = cv.width; t.height = cv.height;
      var c = t.getContext('2d'); c.fillStyle = '#fff'; c.fillRect(0, 0, t.width, t.height); c.drawImage(cv, 0, 0);
      return t.toDataURL('image/jpeg', 0.85);
    }
    function end() { if (drawing) { drawing = false; strokes++; if (hint) hint.hidden = true; out.value = exportImg(); } }
    cv.addEventListener('pointerup', end); cv.addEventListener('pointercancel', end); cv.addEventListener('pointerleave', end);
    form.querySelector('[data-sign-clear]').addEventListener('click', function () { ctx.clearRect(0, 0, cv.width, cv.height); strokes = 0; out.value = ''; if (hint) hint.hidden = false; });
    form.addEventListener('submit', function (e) {
      var noSign = form.querySelector('[name=accordo][value=no_poteri]:checked') && !form.querySelector('[name=ok_privacy]');
      if (!noSign && (!strokes || !out.value)) { e.preventDefault(); cv.parentNode.classList.add('is-empty'); cv.scrollIntoView({ block: 'center' }); }
    });
    var det = cv.closest('details');
    if (det) det.addEventListener('toggle', function () { if (det.open) size(); });
    if (cv.getBoundingClientRect().width) size();
    window.addEventListener('resize', function () { if (cv.getBoundingClientRect().width) size(); });
  });

  /* ---------- Notifiche sul telefono ---------- */
  var boxes = document.querySelectorAll('[data-apac-push]'), invite = document.querySelector('[data-apac-push-invite]');
  if (!cfg.push || (!boxes.length && !invite)) return;
  var ua = navigator.userAgent || '';
  var ios = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var standalone = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
  var supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && window.isSecureContext;
  function b64(s) { s = (s + '===='.slice((s.length % 4) || 4)).replace(/-/g, '+').replace(/_/g, '/'); var r = atob(s), a = new Uint8Array(r.length); for (var i = 0; i < r.length; i++) a[i] = r.charCodeAt(i); return a; }
  function enc(buf) { return btoa(String.fromCharCode.apply(null, new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
  function send(act, sub) {
    var fd = new FormData(); fd.append('apac_push', act); fd.append('_wpnonce', cfg.push.nonce);
    if (sub) { var j = sub.toJSON ? sub.toJSON() : {}; fd.append('endpoint', sub.endpoint); fd.append('p256dh', (j.keys && j.keys.p256dh) || enc(sub.getKey('p256dh'))); fd.append('auth', (j.keys && j.keys.auth) || enc(sub.getKey('auth'))); }
    return fetch(cfg.push.url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  // 1.9.13: l'iscrizione del browser deve usare la chiave attuale del sito; se no (iscrizione vecchia o di un altro plugin) va rifatta.
  function keyOk(sub) { try { var k = sub.options && sub.options.applicationServerKey; return !k || enc(k) === String(cfg.push.key).replace(/=+$/, ''); } catch (x) { return true; } }
  function fresh(r) { return r.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(cfg.push.key) }); }
  function ensure(r) { return r.pushManager.getSubscription().then(function (sub) { if (sub && keyOk(sub)) return sub; return (sub ? sub.unsubscribe().catch(function () {}) : Promise.resolve()).then(function () { return fresh(r); }); }); }
  function why(e) {
    var n = (e && e.name) || '';
    if (n === 'AbortError' || n === 'NotAllowedError' && Notification.permission === 'granted') return 'Il browser non è riuscito a registrarsi al servizio notifiche. Su Brave attiva «Usa i servizi Google per la messaggistica push» (Impostazioni › Privacy); su altri browser controlla di non essere in navigazione privata.';
    if (n === 'NotAllowedError') return 'Permesso non concesso dal browser.';
    return 'Operazione non riuscita' + (n ? ' (' + n + ')' : '') + '. Ricarica la pagina e riprova.';
  }
  function reg() { return navigator.serviceWorker.getRegistration(cfg.scope).then(function (r) { return r || navigator.serviceWorker.register(cfg.sw, { scope: cfg.scope, updateViaCache: 'none' }); }).then(function () { return navigator.serviceWorker.ready; }); }
  function render(state, text) {
    Array.prototype.forEach.call(boxes, function (b) {
      b.querySelector('[data-push-state]').textContent = text;
      b.querySelector('[data-push-on]').hidden = state !== 'off';
      b.querySelector('[data-push-off]').hidden = state !== 'on';
      b.querySelector('[data-push-test]').hidden = state !== 'on';
    });
    var hide = false; try { hide = Date.now() - Number(localStorage.getItem('apac-push-hide') || 0) < 30 * 86400000; } catch (e) {}
    if (invite) invite.hidden = !(state === 'off' && !hide);
  }
  function refresh() {
    if (!supported) {
      render('na', ios && !standalone ? 'Su iPhone le notifiche funzionano dall’app installata sulla schermata Home (iOS 16.4 o successivo): apri l’area riservata dall’icona dell’app.' : 'Questo browser non supporta le notifiche.');
      return;
    }
    if (Notification.permission === 'denied') { render('na', 'Bloccate: il permesso è stato negato. Per riattivarle apri le impostazioni del browser (o del telefono) per questo sito, consenti le notifiche e ricarica la pagina.'); return; }
    reg().then(function (r) { return r.pushManager.getSubscription(); }).then(function (sub) {
      if (sub && Notification.permission === 'granted' && !keyOk(sub)) render('off', 'Da riattivare su questo dispositivo: tocca «Attiva le notifiche».');
      else if (sub && Notification.permission === 'granted') {
        var k = 'apac-push-sync-' + (cfg.push.user || ''), last = 0; try { last = Number(localStorage.getItem(k) || 0); } catch (x) {}
        if (Date.now() - last > 86400000) { send('subscribe', sub).then(function () { try { localStorage.setItem(k, String(Date.now())); } catch (x) {} }).catch(function () {}); }
        render('on', 'Attive su questo dispositivo: riceverai scadenze, nuovi documenti e risposte dello studio.');
      }
      // 1.9.9: stato reale del permesso del browser.
      else if (Notification.permission === 'granted') render('off', 'Autorizzate ma non ancora attive su questo dispositivo: tocca «Attiva le notifiche».');
      else render('off', 'Da autorizzare: tocca «Attiva le notifiche» e rispondi «Consenti» alla richiesta del browser.');
    }).catch(function () { render('off', 'Stato non verificabile su questo dispositivo (app non ancora pronta). Ricarica la pagina; se il problema resta, tocca «Attiva le notifiche».'); });
  }
  function on(btn) {
    btn.disabled = true;
    Notification.requestPermission().then(function (p) {
      if (p !== 'granted') { refresh(); throw new Error('denied'); }
      return reg();
    }).then(ensure)
      .then(function (sub) { return send('subscribe', sub); })
      .then(function (j) { render(j && j.success ? 'on' : 'off', (j && j.data && j.data.msg) || 'Operazione non riuscita.'); })
      .catch(function (e) { if (e && e.message !== 'denied') render('off', 'Attivazione non riuscita. ' + why(e)); })
      .then(function () { btn.disabled = false; });
  }
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-push-on],[data-push-off],[data-push-test],[data-push-invite-hide]'); if (!t) return;
    if (t.hasAttribute('data-push-on')) on(t);
    if (t.hasAttribute('data-push-invite-hide')) { try { localStorage.setItem('apac-push-hide', String(Date.now())); } catch (x) {} invite.hidden = true; }
    if (t.hasAttribute('data-push-off')) reg().then(function (r) { return r.pushManager.getSubscription(); }).then(function (sub) { if (!sub) return refresh(); return send('unsubscribe', sub).then(function () { return sub.unsubscribe(); }).then(refresh); });
    // 1.9.13: prima della prova l'iscrizione viene registrata di nuovo sul server (se era stata cancellata la prova
    // falliva con «(0)»); se il servizio del browser la rifiuta (403/404/410) si rifà l'iscrizione e si riprova una volta.
    if (t.hasAttribute('data-push-test')) {
      var show = function (m) { Array.prototype.forEach.call(boxes, function (b) { b.querySelector('[data-push-state]').textContent = m; }); };
      t.disabled = true; show('Invio della prova in corso…');
      reg().then(function (r) {
        var prova = function (sub) { return send('subscribe', sub).then(function () { return send('test', sub); }); };
        return ensure(r).then(function (sub) {
          return prova(sub).then(function (j) {
            var c = j && j.data && j.data.code;
            if (j && !j.success && (c === 403 || c === 404 || c === 410)) {
              return send('unsubscribe', sub).catch(function () {}).then(function () { return sub.unsubscribe().catch(function () {}); }).then(function () { return fresh(r); }).then(prova);
            }
            return j;
          });
        });
      }).then(function (j) { show((j && j.data && j.data.msg) || 'Invio non riuscito.'); })
        .catch(function (e) { show('Invio non riuscito. ' + why(e)); })
        .then(function () { t.disabled = false; });
    }
  });
  refresh();
})();
</script>
	<?php
}

/** Sezioni dello studio che non dipendono da un'azienda selezionata. */
function apac_studio_sections() {
	return array( 'pannello', 'richieste', 'utenti', 'installa', 'elenco' );
}

/** Logo piccolo per l'intestazione: logo del sito se impostato, altrimenti quello del plugin. */
function apac_logo_url() {
	$id = (int) get_theme_mod( 'custom_logo' );
	if ( $id ) {
		$url = wp_get_attachment_image_url( $id, 'thumbnail' );
		if ( $url ) {
			return $url;
		}
	}
	return APAC_URL . 'assets/logo-pdf.jpg';
}

add_filter( 'body_class', 'apac_app_body_class' );

function apac_app_body_class( $classes ) {
	if ( function_exists( 'apac_is_portal_request' ) && apac_is_portal_request() && is_user_logged_in() ) {
		$classes[] = 'apac-app';
		// 1.9.7: tema scelto dall'utente anche sulla pagina, così sotto un contenuto corto non resta
		// scoperto lo sfondo bianco del tema del sito (fascia bianca sopra la barra in basso).
		$pref = function_exists( 'apac_pref_classes' ) ? apac_pref_classes() : '';
		if ( false !== strpos( $pref, 'apac--dark' ) ) {
			$classes[] = 'apac-app--dark';
		} elseif ( false !== strpos( $pref, 'apac--auto' ) ) {
			$classes[] = 'apac-app--auto';
		}
	}
	return $classes;
}

/* Dopo l'accesso, nella sola pagina dell'area riservata: niente intestazione, menu e piè di pagina del tema. */
add_action( 'wp_head', 'apac_app_hide_theme_chrome', 101 );

function apac_app_hide_theme_chrome() {
	if ( ! function_exists( 'apac_is_portal_request' ) || ! apac_is_portal_request() || ! is_user_logged_in() ) {
		return;
	}
	echo '<style id="apac-app-chrome">'
		. 'body.apac-app #header,body.apac-app #footer,body.apac-app .ct-drawer-canvas,'
		. 'body.apac-app .wp-site-blocks>header,body.apac-app .wp-site-blocks>footer,'
		. 'body.apac-app header.wp-block-template-part,body.apac-app footer.wp-block-template-part,'
		. 'body.apac-app .wp-block-post-title,body.apac-app .ap-pwa-install,'
		. 'body.apac-app #masthead,body.apac-app #colophon,body.apac-app .site-header,body.apac-app .site-footer,'
		. 'body.apac-app aside.widget-area,body.apac-app .entry-header,body.apac-app .entry-footer{display:none!important}'
		. 'body.apac-app #main-container,body.apac-app .site-main,body.apac-app .ct-container-full,body.apac-app .wp-site-blocks{padding-top:0!important;margin-top:0!important}'
		. 'body.apac-app main>.wp-block-group,body.apac-app .entry-content{margin-top:0!important;padding-top:0!important}'
		. 'body.apac-app--dark{background:#0f1712!important}'
		. '@media (prefers-color-scheme:dark){body.apac-app--auto{background:#0f1712!important}}'
		. '</style>' . "\n";
}

/** Pulsante principale dell'intestazione: "+ Azienda" (studio) o "+ Aggiungi" (dentro un'azienda). */
function apac_render_addmenu( $azienda_id, $sez ) {
	if ( apac_is_admin() && in_array( $sez, apac_studio_sections(), true ) ) {
		echo '<a class="apac-addmenu__btn" href="' . esc_url( apac_add_url( 'utenti', 'azienda_create' ) ) . '">' . apac_icon( 'plus' ) . '<span>Azienda</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		return;
	}
	if ( ! $azienda_id ) {
		return;
	}
	$items = array();
	if ( apac_client_can( $azienda_id, 'lavoratori' ) ) {
		$items[] = array( 'users', 'Lavoratore', apac_add_url( 'lavoratori', 'lavoratore_save' ) );
	}
	if ( apac_client_can( $azienda_id, 'documenti' ) ) {
		$items[] = array( 'upload', 'Documento', apac_add_url( 'documenti', 'documento_upload' ) );
	}
	if ( apac_client_can( $azienda_id, 'scadenze' ) ) {
		$items[] = array( 'calendar', 'Scadenza', apac_add_url( 'scadenziario', 'scadenza_save' ) );
	}
	$items[] = array( 'message', 'Segnalazione', apac_add_url( 'segnalazioni', 'segnalazione_create' ) );
	echo '<details class="apac-addmenu"><summary class="apac-addmenu__btn" aria-label="Aggiungi">' . apac_icon( 'plus' ) . '<span>Aggiungi</span></summary><div class="apac-addmenu__sheet"><p class="apac-addmenu__title">Aggiungi</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	foreach ( $items as $it ) {
		echo '<a href="' . esc_url( $it[2] ) . '">' . apac_icon( $it[0] ) . '<span>' . esc_html( $it[1] ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	if ( count( $items ) < 4 ) {
		echo '<p class="apac-addmenu__note">Per altre modifiche scrivi allo studio con una segnalazione.</p>';
	}
	echo '</div></details>';
}

/** Menu account: nome, preferenze, "Vai al sito", "Esci". */
function apac_render_account_menu( $user ) {
	$name = trim( (string) $user->display_name );
	$ini  = '';
	foreach ( preg_split( '/\s+/u', $name ) as $p ) {
		if ( '' !== $p && mb_strlen( $ini ) < 2 ) {
			$ini .= mb_strtoupper( mb_substr( $p, 0, 1 ) );
		}
	}
	// phpcs:disable WordPress.Security.EscapeOutput
	echo '<details class="apac-acct"><summary class="apac-acct__btn" aria-label="Account: ' . esc_attr( $name ) . '"><span class="apac-acct__ini" aria-hidden="true">' . esc_html( '' !== $ini ? $ini : '•' ) . '</span><span class="apac-acct__name">' . esc_html( $name ) . '<small>' . ( apac_is_admin() ? 'Studio' : ( apac_user_readonly() ? 'Cliente · solo consultazione' : 'Cliente' ) ) . '</small></span></summary>';
	echo '<div class="apac-acct__sheet"><p class="apac-acct__who"><strong>' . esc_html( $name ) . '</strong><small>' . esc_html( $user->user_email ) . '</small></p>';
	echo '<details class="apac-acct__prefs"><summary>' . apac_icon_extra( 'contrast' ) . '<span>Aspetto e notifiche</span></summary>' . apac_render_prefs( 'apac-prefs-side' ) . apac_render_push_box( 'apac-push-side' ) . '</details>';
	echo '<a href="' . esc_url( home_url( '/' ) ) . '">' . apac_icon( 'home' ) . '<span>Vai al sito</span></a>';
	echo '<a class="apac-top__out" href="' . esc_url( apac_logout_url() ) . '">' . apac_icon( 'logout' ) . '<span>Esci</span></a>';
	echo '</div></details>';
	// phpcs:enable
}

/** Una riga di descrizione sotto l'intestazione (niente secondo titolo). */
function apac_section_lead( $sez ) {
	$meta = apac_section_meta();
	if ( isset( $meta[ $sez ] ) && '' !== $meta[ $sez ][2] ) {
		echo '<p class="apac-lead">' . esc_html( $meta[ $sez ][2] ) . '</p>';
	}
}

function apac_message_text( $code, $default ) {
	$did = isset( $_GET['apac_did'] ) ? sanitize_key( wp_unslash( $_GET['apac_did'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( '' === $did || ! in_array( $code, array( 'saved', 'deleted' ), true ) ) {
		return $default;
	}
	if ( 'scadenza_toggle' === $did || 'scadenza_stato' === $did ) {
		return ( isset( $_GET['stato'] ) && 'aperta' === $_GET['stato'] ) ? 'Scadenza riaperta.' : 'Scadenza completata.'; // phpcs:ignore WordPress.Security.NonceVerification
	}
	$map = array(
		'azienda_save'          => 'Dati dell’azienda salvati.',
		'azienda_delete'        => 'Azienda eliminata.',
		'azienda_permessi'      => 'Permessi del cliente salvati.',
		'lavoratore_save'       => 'Lavoratore salvato.',
		'lavoratore_delete'     => 'Lavoratore eliminato.',
		'lavoratore_cessa'      => 'Lavoratore segnato come cessato. Lo storico resta consultabile.',
		'lavoratore_riattiva'   => 'Lavoratore di nuovo in forza.',
		'attestato_save'        => 'Attestato salvato.',
		'attestato_update'      => 'Attestato aggiornato.',
		'attestato_delete'      => 'Attestato eliminato.',
		'scadenza_save'         => 'Scadenza salvata.',
		'scadenza_delete'       => 'Scadenza eliminata.',
		'documento_update'      => 'Modifiche al documento salvate.',
		'documento_delete'      => 'Documento eliminato.',
		'segnalazione_update'   => 'Segnalazione aggiornata.',
		'segnalazione_delete'   => 'Segnalazione eliminata.',
		'utente_update'         => 'Utente aggiornato.',
		'mansione_corso_add'    => 'Corso richiesto per la mansione aggiunto.',
		'mansione_corso_delete' => 'Corso richiesto rimosso.',
		'log_svuota'            => 'Registro svuotato.',
		'primi_passi'           => 'Guida “Primi passi” aggiornata.',
	);
	return isset( $map[ $did ] ) ? $map[ $did ] : $default;
}

/**
 * Riga con contesto, filtri attivi (rimovibili), numero di risultati e "Azzera filtri".
 * $chips: [ [ etichetta, url senza quel filtro ] ]
 */
function apac_render_filter_summary( $context, $chips, $count, $reset_url = '' ) {
	echo '<div class="apac-fsum" role="status"><span class="apac-fsum__ctx">' . esc_html( $context ) . '</span>';
	foreach ( $chips as $c ) {
		echo '<a class="apac-fsum__chip" href="' . esc_url( $c[1] ) . '" aria-label="' . esc_attr( 'Rimuovi filtro: ' . $c[0] ) . '">' . esc_html( $c[0] ) . '<span aria-hidden="true">×</span></a>';
	}
	echo '<strong class="apac-fsum__n">' . (int) $count . ( 1 === (int) $count ? ' risultato' : ' risultati' ) . '</strong>';
	if ( $chips && $reset_url ) {
		echo '<a class="apac-fsum__reset" href="' . esc_url( $reset_url ) . '">Azzera filtri</a>';
	}
	echo '</div>';
}

/** Elenco vuoto: distingue "nessun dato" da "nessun risultato con questi filtri". */
function apac_render_empty( $has_data, $none_text, $reset_url = '' ) {
	if ( $has_data ) {
		echo '<p class="apac-empty">Nessun risultato con i filtri attuali.' . ( $reset_url ? ' <a href="' . esc_url( $reset_url ) . '">Azzera filtri</a>' : '' ) . '</p>';
	} else {
		echo '<p class="apac-empty">' . esc_html( $none_text ) . '</p>';
	}
}

/** URL della sezione con i filtri attuali (tranne $without). */
function apac_url_filters( $sez, $names, $without = '', $extra = array() ) {
	$args = array();
	foreach ( $names as $n ) {
		if ( $n === $without ) {
			continue;
		}
		$v = isset( $_GET[ $n ] ) ? sanitize_text_field( wp_unslash( $_GET[ $n ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $v ) {
			$args[ $n ] = $v;
		}
	}
	return apac_url( $sez, array_merge( $args, $extra ) );
}

function apac_render_prima_azienda() {
	echo '<section class="apac-card apac-first"><h2>Aggiungi la prima azienda</h2>';
	echo '<p>Per ogni azienda cliente potrai gestire lavoratori e attestati, documenti, scadenze e segnalazioni, e dare l’accesso ai referenti dell’azienda.</p>';
	echo '<p><a class="apac-btn" href="' . esc_url( apac_add_url( 'utenti', 'azienda_create' ) ) . '">' . apac_icon( 'plus' ) . '<span>Crea azienda</span></a></p></section>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

/** Sezione aziendale senza azienda selezionata: elenco diretto per sceglierla. */
function apac_render_scegli_azienda( $sez ) {
	if ( ! apac_is_admin() ) {
		echo '<div class="apac-card"><p>Nessuna azienda collegata al tuo account. Contatta AP Sicurezza Lavoro.</p></div>';
		return;
	}
	$q = apac_aziende_query( '', 1, APAC_LIST_PAGE );
	if ( ! $q['total'] ) {
		apac_render_prima_azienda();
		return;
	}
	echo '<section class="apac-card"><h2>Scegli un’azienda</h2><p class="apac-muted">Tocca un’azienda per aprire questa sezione' . ( $q['total'] > APAC_LIST_PAGE ? ', oppure cercala nel campo qui sopra' : '' ) . '.</p><ul class="apac-list apac-azlist__list">';
	foreach ( $q['rows'] as $a ) {
		echo '<li><a href="' . esc_url( apac_url( apac_target_section( $sez ), array( 'azienda' => (int) $a->id ) ) ) . '"><span class="apac-azlist__name">' . esc_html( $a->ragione_sociale ) . '<small>' . esc_html( apac_azienda_ident( $a ) . ( $a->comune ? ' · ' . $a->comune : '' ) ) . '</small></span>' . apac_icon( 'arrow' ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</ul></section>';
}

/** Apre un gruppo: resta aperto se contiene il campo con errore o se $open. */
function apac_group_open( $title, $fields, $open = false, $filled = -1 ) {
	foreach ( $fields as $f ) {
		if ( '' !== apac_form_field_error( $f ) ) {
			$open = true;
		}
	}
	$hint = $filled >= 0 ? '<small>' . ( $filled ? (int) $filled . ' di ' . count( $fields ) . ' compilati' : 'da compilare' ) . '</small>' : '';
	return '<details class="apac-group"' . ( $open ? ' open' : '' ) . '><summary><span>' . esc_html( $title ) . '</span>' . $hint . '</summary><div class="apac-fields">';
}

function apac_group_close() {
	return '</div></details>';
}

add_action( 'wp_footer', 'apac_v190_script', 41 );

function apac_v190_script() {
	if ( ! function_exists( 'apac_is_portal_request' ) || ! apac_is_portal_request() || ! is_user_logged_in() ) {
		return;
	}
	?>
<script id="apac-v190-js">
(function () {
  'use strict';
  var dirty = new Set();
  var leaving = false;
  function tracked(f) {
    return f && f.classList && f.classList.contains('apac-form') && !f.classList.contains('apac-inline') && !f.closest('.apac-filterbar');
  }
  document.addEventListener('input', function (e) {
    var f = e.target.form; if (!tracked(f) || e.target.type === 'hidden' || e.target.type === 'search') return;
    dirty.add(f);
  });
  document.addEventListener('change', function (e) {
    var f = e.target.form; if (!tracked(f) || e.target.type === 'hidden') return;
    dirty.add(f);
  });
  // Invio: nessun avviso per il modulo inviato, pulsante bloccato durante il salvataggio.
  // 1.9.4: se ALTRI moduli hanno modifiche non salvate (es. una ricerca inviata mentre una scheda è
  // compilata a metà) si chiede prima cosa fare: l'avviso non viene più disattivato in silenzio.
  var bypass = null;
  function othersDirty(f) {
    var n = []; dirty.forEach(function (d) { if (d !== f && document.contains(d)) n.push(d); });
    return n;
  }
  function getUrl(f, sub) {
    try {
      var u = new URL(f.getAttribute('action') || location.href, location.href);
      var fd; try { fd = sub ? new FormData(f, sub) : new FormData(f); } catch (x) { fd = new FormData(f); }
      u.search = new URLSearchParams(fd).toString();
      return u.href;
    } catch (x) { return null; }
  }
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (e.defaultPrevented || !f.matches('form') || f.target === '_blank') return;
    var isGet = f.method && f.method.toLowerCase() === 'get';
    if (bypass !== f && othersDirty(f).length) {
      e.preventDefault();
      var url = isGet ? getUrl(f, e.submitter || null) : null;
      ask(url ? { url: url } : { form: f, submitter: e.submitter || null });
      return;
    }
    bypass = null;
    dirty.clear(); leaving = true;
    if (isGet) return;
    var b = e.submitter || f.querySelector('button[type=submit],button:not([type])');
    if (!b) return;
    // Il pulsante non ha "name": disattivarlo subito non toglie dati dall'invio.
    if (b.name) { var h = document.createElement('input'); h.type = 'hidden'; h.name = b.name; h.value = b.value; f.appendChild(h); }
    b.dataset.apacLabel = b.innerHTML;
    b.disabled = true; b.setAttribute('aria-busy', 'true');
    b.textContent = f.enctype === 'multipart/form-data' ? 'Invio in corso…' : 'Salvataggio…';
    // 1.9.9: invio senza risposta (rete lenta o assente). Si memorizza che un salvataggio è partito: se la
    // pagina successiva non riporta l'esito (es. errore di rete e ritorno indietro), si spiega cosa fare.
    try { sessionStorage.setItem('apac-pending', JSON.stringify({ t: Date.now() })); } catch (x) {}
    setTimeout(function () {
      if (!b.isConnected || !b.disabled) return;
      var n = document.createElement('p'); n.className = 'apac-netwait'; n.setAttribute('role', 'status');
      n.textContent = 'Il salvataggio sta impiegando più del solito. Non inviare di nuovo: attendi la risposta. Se la connessione è caduta, ricarica la pagina e controlla se il dato risulta salvato prima di ripetere l’operazione.';
      b.insertAdjacentElement('afterend', n);
    }, 15000);
    setTimeout(function () { unlock(b); }, 60000);
  }, false);
  // Esito di un invio precedente: con apac_msg nell'indirizzo è arrivato; senza, è possibile che la rete
  // abbia interrotto l'invio. Il doppio invio resta comunque bloccato dal server (codice monouso).
  (function () {
    var p = null; try { p = JSON.parse(sessionStorage.getItem('apac-pending') || 'null'); sessionStorage.removeItem('apac-pending'); } catch (x) {}
    if (!p || !p.t || Date.now() - p.t > 30 * 60 * 1000) return;
    if (/[?&]apac_msg=/.test(location.search)) return;
    var box = document.createElement('div'); box.className = 'apac-notice apac-notice--warn'; box.setAttribute('role', 'alert');
    box.textContent = 'Non è arrivata la conferma dell’ultimo invio (connessione interrotta o pagina chiusa). Controlla qui sotto se il dato risulta salvato: se manca, ripeti l’operazione. Un invio già arrivato non viene mai registrato due volte.';
    var host = document.querySelector('.apac-main') || document.querySelector('.apac');
    if (host) host.insertBefore(box, host.firstChild);
  })();
  function unlock(b) {
    if (b && b.dataset.apacLabel) { b.innerHTML = b.dataset.apacLabel; delete b.dataset.apacLabel; }
    if (b) { b.disabled = false; b.removeAttribute('aria-busy'); }
  }
  window.addEventListener('pageshow', function (e) {
    leaving = false;
    if (e.persisted) document.querySelectorAll('button[aria-busy]').forEach(unlock);
  });
  // Modifiche non salvate: avviso prima di lasciare la pagina.
  // target = { url: indirizzo } oppure { form: modulo da inviare, submitter: pulsante }.
  var dlg = null, target = null;
  function canSaveAndGo(t) {
    if (!t || !t.url || dirty.size !== 1) return null;
    var f = dirty.values().next().value;
    if (!f || !document.contains(f) || !f.method || f.method.toLowerCase() !== 'post') return null;
    return f;
  }
  function ask(t) {
    if (typeof t === 'string') t = { url: t };
    target = t;
    if (!window.HTMLDialogElement) {
      if (window.confirm('Hai modifiche non salvate. Vuoi abbandonarle?')) go(t);
      return;
    }
    if (!dlg) {
      dlg = document.createElement('dialog'); dlg.className = 'apac-dialog';
      dlg.setAttribute('aria-labelledby', 'apac-dlg-t');
      dlg.innerHTML = '<h2 id="apac-dlg-t">Modifiche non salvate</h2><p>Hai compilato un modulo senza salvarlo. Se esci ora, i dati inseriti andranno persi.</p><div class="apac-dialog__btns"><button type="button" class="apac-btn" data-save>Salva e continua</button><button type="button" class="apac-btn apac-btn--ghost" data-stay>Resta qui</button><button type="button" class="apac-btn apac-btn--ghost" data-leave>Abbandona modifiche</button></div>';
      (document.querySelector('.apac') || document.body).appendChild(dlg);
      dlg.querySelector('[data-stay]').addEventListener('click', stay);
      dlg.querySelector('[data-leave]').addEventListener('click', function () { dlg.close(); go(target); });
      dlg.querySelector('[data-save]').addEventListener('click', saveAndGo);
      dlg.addEventListener('cancel', function (ev) { ev.preventDefault(); stay(); });
    }
    var sv = dlg.querySelector('[data-save]');
    sv.hidden = !canSaveAndGo(t);
    dlg.showModal(); (sv.hidden ? dlg.querySelector('[data-stay]') : sv).focus();
  }
  // Salva il modulo compilato; se il salvataggio riesce il server apre la pagina scelta,
  // se non riesce si torna al modulo con i dati conservati e l'errore indicato.
  function saveAndGo() {
    var f = canSaveAndGo(target);
    if (!f) { stay(); return; }
    dlg.close();
    if (f.reportValidity && !f.reportValidity()) { stay(); return; }
    var h = f.querySelector('input[name="apac_next"]');
    if (!h) { h = document.createElement('input'); h.type = 'hidden'; h.name = 'apac_next'; f.appendChild(h); }
    h.value = target.url;
    var b = f.querySelector('button[type=submit],button:not([type])');
    if (f.requestSubmit) { f.requestSubmit(b || undefined); } else { dirty.clear(); leaving = true; f.submit(); }
  }
  function stay() {
    if (dlg && dlg.open) dlg.close();
    var f = dirty.values().next().value; if (!f) return;
    var d = f.closest('details'); while (d) { d.open = true; d = d.parentElement ? d.parentElement.closest('details') : null; }
    var el = f.querySelector('input:not([type=hidden]),select,textarea'); if (el) { el.focus(); }
  }
  function go(t) {
    if (typeof t === 'string') t = { url: t };
    if (!t) return;
    dirty.clear(); leaving = true;
    if (t.form) {
      bypass = t.form;
      if (t.form.requestSubmit) { t.form.requestSubmit(t.submitter || undefined); } else { t.form.submit(); }
      return;
    }
    window.location.href = t.url;
  }
  window.apacGo = function (url) { if (dirty.size) ask({ url: url }); else go({ url: url }); };
  document.addEventListener('click', function (e) {
    if (!dirty.size || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;
    var a = e.target.closest ? e.target.closest('a[href]') : null;
    if (!a || a.target === '_blank' || a.hasAttribute('download')) return;
    var href = a.getAttribute('href') || '';
    if (href.charAt(0) === '#' || /^(mailto|tel|javascript):/i.test(href)) return;
    var u; try { u = new URL(a.href, location.href); } catch (x) { return; }
    if (u.origin !== location.origin) return;
    if (u.pathname === location.pathname && u.search === location.search && u.hash) return;
    if (/[?&](apac_file|apac_ver|apac_foto|apac_cons|apac_export|apac_template|apac_report)=/.test(u.search)) return;
    e.preventDefault(); ask(a.href);
  }, true);
  window.addEventListener('beforeunload', function (e) {
    if (dirty.size && !leaving) { e.preventDefault(); e.returnValue = ''; return ''; }
  });
  // Menu a tendina dell'intestazione: uno alla volta, chiusi con Esc o toccando fuori.
  var menus = document.querySelectorAll('.apac-addmenu, .apac-acct, .apac-more, .apac-doc__more');
  document.addEventListener('click', function (e) {
    menus.forEach(function (m) { if (m.open && !m.contains(e.target)) m.open = false; });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    menus.forEach(function (m) { if (m.open) { m.open = false; var s = m.querySelector('summary'); if (s) s.focus(); } });
  });
  // Ritorno agli elenchi: stessa posizione di prima.
  function key(u) {
    var p = new URL(u, location.href).searchParams; var a = [];
    p.forEach(function (v, k) { if (k !== 'apac_msg' && k !== 'apac_did' && k !== 'apac_form') a.push(k + '=' + v); });
    return 'apac-pos:' + a.sort().join('&');
  }
  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('[data-apac-keep-pos] a[href]') : null;
    if (!a) return;
    try { sessionStorage.setItem(key(location.href), String(window.scrollY)); } catch (x) {}
  });
  try {
    var y = sessionStorage.getItem(key(location.href));
    if (y !== null && document.querySelector('[data-apac-keep-pos]')) { sessionStorage.removeItem(key(location.href)); window.scrollTo(0, parseInt(y, 10) || 0); }
  } catch (x) {}
})();
</script>
	<?php
}
