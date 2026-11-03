<?php
defined( 'ABSPATH' ) || exit;

/*
 * 1.7.0 – Installazione facile, condivisione, email di benvenuto, web app
 * non trattenuta dalla cache, preferenze di aspetto, barra di WordPress
 * nascosta nell'area riservata, stato del backup.
 */

/* ======================= Barra di WordPress nascosta ===================== */

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

/* ===================== Web app: file sempre aggiornato =================== */

/*
 * Il file che fa funzionare la web app (service worker) viene servito
 * dall'indirizzo dell'area riservata: queste pagine non sono trattenute
 * dalla cache del server, quindi ogni modifica arriva subito ai telefoni.
 * Sostituisce in modo trasparente quello del plugin della web app
 * (stesso ambito /area-riservata/, stesso comportamento più le pagine con parametri).
 */
define( 'APAC_SW_VERSION', '4' );

function apac_sw_url() {
	return add_query_arg( 'apac_sw', APAC_SW_VERSION, apac_portal_page_url() );
}

add_action( 'init', 'apac_serve_sw', 19 );
function apac_serve_sw() {
	if ( ! isset( $_GET['apac_sw'] ) || is_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$portal = wp_parse_url( apac_portal_page_url() );
	$path   = isset( $portal['path'] ) ? $portal['path'] : '/';
	nocache_headers();
	header( 'Content-Type: application/javascript; charset=utf-8' );
	header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0', true );
	header( 'Surrogate-Control: no-store' );
	header( 'X-Content-Type-Options: nosniff' );
	$offline = '<!doctype html><html lang="it"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AP Sicurezza – Connessione assente</title>'
		. '<body style="font-family:system-ui,sans-serif;max-width:34rem;margin:15vh auto;padding:24px;color:#202020"><h1 style="color:#193E0C">Connessione assente</h1>'
		. '<p>Per usare l’area riservata serve una connessione a Internet. Ricollegati e riprova.</p>'
		. '<p><a href="' . esc_url( apac_portal_page_url() ) . '" style="display:inline-block;background:#193E0C;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:700">Riprova</a></p></body></html>';
	// phpcs:disable WordPress.Security.EscapeOutput
	echo "/* AP Area Clienti – web app, versione " . APAC_SW_VERSION . " */\n";
	echo 'const APAC_SCOPE_PATH = ' . wp_json_encode( $path ) . ";\n";
	echo 'const APAC_OFFLINE = ' . wp_json_encode( $offline ) . ";\n";
	echo 'const APAC_ICON = ' . wp_json_encode( apac_push_icon_url() ) . ";\n";
	// phpcs:enable
	?>
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', event => {
  const req = event.request;
  if (req.method !== 'GET' || req.mode !== 'navigate') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin || !url.pathname.startsWith(APAC_SCOPE_PATH)) return;
  // Download, uscita e file della web app: li gestisce direttamente il browser.
  if (['apac_file','apac_ver','apac_foto','apac_export','apac_template','apac_report','apac_cons','apac_logout','apac_sw'].some(k => url.searchParams.has(k))) return;
  event.respondWith(fetch(req, {cache: 'no-store'}).catch(() => new Response(APAC_OFFLINE, {
    status: 503, headers: {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store'}
  })));
});
// Notifiche sul telefono.
self.addEventListener('push', event => {
  let d = {};
  try { d = event.data ? event.data.json() : {}; } catch (e) { d = { body: event.data ? event.data.text() : '' }; }
  event.waitUntil(self.registration.showNotification(d.title || 'AP Sicurezza Lavoro', {
    body: d.body || '', icon: APAC_ICON, badge: APAC_ICON, data: { url: d.url || APAC_SCOPE_PATH }, tag: d.tag || undefined
  }));
});
self.addEventListener('notificationclick', event => {
  event.notification.close();
  const url = new URL((event.notification.data && event.notification.data.url) || APAC_SCOPE_PATH, self.location.origin);
  if (url.origin !== self.location.origin) return;
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
    for (const c of list) { if (c.url.startsWith(self.location.origin) && 'navigate' in c) { return c.focus().then(() => c.navigate(url.href)); } }
    return self.clients.openWindow(url.href);
  }));
});
	<?php
	exit;
}

/* ======================= Preferenze di aspetto ======================== */

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

/* =================== Installazione e condivisione ====================== */

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

/**
 * Riquadro "Installa l'app". È nascosto finché il JavaScript non capisce il dispositivo:
 * sul computer o nell'app già installata non compare mai.
 */
function apac_render_install_card( $dismissable = true ) {
	$url = apac_portal_page_url();
	// phpcs:disable WordPress.Security.EscapeOutput
	echo '<section class="apac-install" hidden data-mode="" ' . ( $dismissable ? 'data-dismissable="1"' : '' ) . ' aria-label="Installa l’app">';
	echo '<div class="apac-install__head"><span class="apac-install__ico">' . apac_icon_extra( 'phone' ) . '</span><div><strong>Usa l’area riservata come un’app</strong><small>Un’icona sulla schermata Home, si apre con un tocco.</small></div>';
	if ( $dismissable ) {
		echo '<button type="button" class="apac-install__x" data-apac-install-hide aria-label="Nascondi">×</button>';
	}
	echo '</div>';
	// Android con installazione diretta.
	echo '<div data-show="android"><button type="button" class="apac-btn apac-install__go" data-apac-install-go>' . apac_icon( 'plus' ) . '<span>Installa l’app</span></button></div>';
	// Android senza installazione diretta.
	echo '<ol class="apac-steps" data-show="android-menu"><li><span class="apac-steps__ico">' . apac_icon_extra( 'dots-v' ) . '</span><span>Tocca il menu <b>⋮</b> del browser, in alto a destra</span></li><li><span class="apac-steps__ico">' . apac_icon_extra( 'add-home' ) . '</span><span>Scegli <b>«Installa app»</b> oppure <b>«Aggiungi a schermata Home»</b></span></li></ol>';
	// iPhone / iPad con Safari.
	echo '<ol class="apac-steps" data-show="ios"><li><span class="apac-steps__ico">' . apac_icon_extra( 'share-ios' ) . '</span><span>Tocca <b>Condividi</b> nella barra di Safari</span></li><li><span class="apac-steps__ico">' . apac_icon_extra( 'add-home' ) . '</span><span>Scorri e tocca <b>«Aggiungi alla schermata Home»</b></span></li><li><span class="apac-steps__ico">' . apac_icon( 'check' ) . '</span><span>Conferma con <b>«Aggiungi»</b></span></li></ol>';
	// iPhone / iPad con un altro browser.
	echo '<div data-show="ios-other"><p class="apac-install__warn">Su iPhone l’app si installa da <b>Safari</b>. Copia il link, apri Safari e incollalo nella barra degli indirizzi.</p><button type="button" class="apac-btn apac-btn--ghost" data-apac-copy="' . esc_attr( $url ) . '">' . apac_icon_extra( 'copy' ) . '<span>Copia link</span></button></div>';
	echo '</section>';
	// phpcs:enable
}

/** Riquadro del pannello studio: far installare l'app ai clienti. */
function apac_render_share_card( $open = false ) {
	$url  = apac_portal_page_url();
	$text = "Area riservata AP Sicurezza Lavoro: documenti, scadenze e formazione della tua azienda.\n\nApri il link e installa l’app sul telefono:\n• Android: menu ⋮ → «Installa app»\n• iPhone: apri in Safari → Condividi → «Aggiungi alla schermata Home»\n\nAccedi con l’email e la password ricevute dallo studio.";
	$wa   = 'https://wa.me/?text=' . rawurlencode( $text . "\n\n" . $url );
	$qr   = APAC_URL . 'assets/qr-area-riservata.png';
	// phpcs:disable WordPress.Security.EscapeOutput
	echo '<details class="apac-card apac-fold apac-share"' . ( $open ? ' open' : '' ) . '><summary><span>Fai installare l’app ai clienti</span><small>link, WhatsApp, QR</small></summary><div class="apac-fold__body">';
	echo '<p class="apac-muted">Invia il link con le istruzioni già pronte, oppure mostra il QR dal tuo telefono e fallo inquadrare al cliente.</p>';
	echo '<div class="apac-share__btns">';
	echo '<button type="button" class="apac-btn" data-apac-share data-url="' . esc_attr( $url ) . '" data-title="AP Sicurezza Lavoro – Area riservata" data-text="' . esc_attr( $text ) . '" hidden>' . apac_icon_extra( 'share-ios' ) . '<span>Condividi l’app</span></button>';
	echo '<a class="apac-btn apac-btn--ghost" href="' . esc_attr( $wa ) . '" target="_blank" rel="noopener">' . apac_icon_extra( 'whatsapp' ) . '<span>WhatsApp</span></a>';
	echo '<button type="button" class="apac-btn apac-btn--ghost" data-apac-copy="' . esc_attr( $text . "\n\n" . $url ) . '">' . apac_icon_extra( 'copy' ) . '<span>Copia testo e link</span></button>';
	echo '</div>';
	echo '<div class="apac-share__qr"><img src="' . esc_url( $qr ) . '" width="180" height="180" alt="QR dell’area riservata" loading="lazy"><small>' . esc_html( $url ) . '</small></div>';
	echo '</div></details>';
	// phpcs:enable
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

/* Il riquadro fluttuante del plugin della web app si sovrapporrebbe al menu in basso:
 * nell'area riservata è sostituito dal riquadro integrato "Installa l'app". */
add_action( 'wp_head', 'apac_hide_pwa_floating_card', 100 );
function apac_hide_pwa_floating_card() {
	if ( function_exists( 'apac_is_portal_request' ) && apac_is_portal_request() ) {
		echo '<style id="apac-pwa-card">.ap-pwa-install{display:none!important}</style>' . "\n";
	}
}

/* ====================== Email di benvenuto ============================ */

function apac_is_client_user( $user ) {
	return $user instanceof WP_User && in_array( APAC_ROLE, (array) $user->roles, true );
}

/** Estrae il link per impostare la password dal testo standard di WordPress. */
function apac_extract_rp_link( $message ) {
	if ( preg_match( '#https?://[^\s<>"]*action=rp[^\s<>"]*#', (string) $message, $m ) ) {
		return html_entity_decode( $m[0], ENT_QUOTES, 'UTF-8' );
	}
	return '';
}

/** Impaginazione HTML delle email (compatibile con i principali programmi di posta). */
function apac_email_html( $title, $intro_html, $button_label, $button_url, $after_html ) {
	$portal = apac_portal_page_url();
	$qr     = APAC_URL . 'assets/qr-area-riservata.png';
	$g      = '#193E0C';
	$h      = '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $title ) . '</title></head>';
	$h     .= '<body style="margin:0;padding:0;background:#f2f4f1;font-family:Arial,Helvetica,sans-serif;color:#202020">';
	$h     .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f1;padding:24px 12px"><tr><td align="center">';
	$h     .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e3e3e3">';
	$h     .= '<tr><td style="background:#061C03;padding:22px 28px;border-bottom:4px solid #FDE117"><div style="color:#FDE117;font-size:12px;font-weight:bold;letter-spacing:2px;text-transform:uppercase">Area riservata</div><div style="color:#ffffff;font-size:22px;font-weight:bold;margin-top:4px">AP Sicurezza Lavoro</div></td></tr>';
	$h     .= '<tr><td style="padding:28px 28px 8px;font-size:16px;line-height:1.6">' . $intro_html . '</td></tr>';
	if ( $button_url ) {
		$h .= '<tr><td style="padding:8px 28px 8px" align="left"><a href="' . esc_url( $button_url ) . '" style="display:inline-block;background:' . $g . ';color:#ffffff;text-decoration:none;font-weight:bold;font-size:16px;padding:14px 24px;border-radius:8px">' . esc_html( $button_label ) . '</a>';
		$h .= '<p style="font-size:13px;color:#4A4A4A;margin:12px 0 0">Se il pulsante non funziona, copia questo indirizzo nel browser:<br><a href="' . esc_url( $button_url ) . '" style="color:' . $g . ';word-break:break-all">' . esc_html( $button_url ) . '</a></p></td></tr>';
	}
	$h .= '<tr><td style="padding:16px 28px 8px;font-size:15px;line-height:1.6">' . $after_html . '</td></tr>';
	$h .= '<tr><td style="padding:8px 28px 24px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F5F5F5;border-radius:12px"><tr>';
	$h .= '<td style="padding:16px;vertical-align:top;font-size:14px;line-height:1.55"><strong style="color:' . $g . ';font-size:15px">Installa l’app sul telefono</strong><br>';
	$h .= '<b>Android:</b> apri l’area riservata, tocca il menu ⋮ e poi «Installa app».<br>';
	$h .= '<b>iPhone:</b> apri l’area riservata in <b>Safari</b>, tocca Condividi e poi «Aggiungi alla schermata Home».<br>';
	$h .= '<a href="' . esc_url( $portal ) . '" style="color:' . $g . ';font-weight:bold">' . esc_html( $portal ) . '</a></td>';
	$h .= '<td width="130" style="padding:12px;vertical-align:middle" align="center"><a href="' . esc_url( $portal ) . '"><img src="' . esc_url( $qr ) . '" width="118" height="118" alt="QR area riservata" style="display:block;border:0"></a></td>';
	$h .= '</tr></table></td></tr>';
	$h .= '<tr><td style="padding:16px 28px 24px;border-top:1px solid #e3e3e3;font-size:12px;color:#6b6b6b;line-height:1.5">Email automatica inviata da AP Sicurezza Lavoro. Se non ti aspettavi questo messaggio, ignoralo oppure rispondi per avvisarci.</td></tr>';
	$h .= '</table></td></tr></table></body></html>';
	return $h;
}

/* Benvenuto: account creato dall'area riservata (solo per i clienti). */
add_filter( 'wp_new_user_notification_email', 'apac_welcome_email', 10, 3 );
function apac_welcome_email( $email, $user, $blogname ) {
	unset( $blogname );
	if ( ! apac_is_client_user( $user ) ) {
		return $email;
	}
	$link = apac_extract_rp_link( isset( $email['message'] ) ? $email['message'] : '' );
	if ( '' === $link ) {
		return $email; // Formato inatteso: resta l'email standard di WordPress.
	}
	$az    = apac_get_azienda( (int) get_user_meta( $user->ID, 'apac_azienda_id', true ) );
	$nome  = '' !== $user->first_name ? $user->first_name : $user->display_name;
	$intro = '<p style="margin:0 0 14px">Ciao <strong>' . esc_html( $nome ) . '</strong>,</p>';
	$intro .= '<p style="margin:0 0 14px">lo studio <strong>AP Sicurezza Lavoro</strong> ha attivato il tuo accesso all’area riservata' . ( $az ? ' di <strong>' . esc_html( $az->ragione_sociale ) . '</strong>' : '' ) . ': qui trovi documenti, scadenze e formazione della tua azienda.</p>';
	$intro .= '<p style="margin:0 0 6px"><strong>1.</strong> Per prima cosa scegli la tua password:</p>';
	$after  = '<p style="margin:0 0 10px">Il link per la password vale per un tempo limitato: se è scaduto, apri l’area riservata e tocca «Password dimenticata?».</p>';
	$after .= '<p style="margin:0"><strong>2.</strong> Poi accedi con questa email (<strong>' . esc_html( $user->user_email ) . '</strong>) e la password scelta.</p>';
	$email['subject'] = 'Il tuo accesso all’area riservata di AP Sicurezza Lavoro';
	$email['message'] = apac_email_html( $email['subject'], $intro, 'Scegli la password', $link, $after );
	$email['headers'] = apac_merge_html_headers( isset( $email['headers'] ) ? $email['headers'] : '' );
	return $email;
}

/* Nuova password richiesta (dallo studio o con "Password dimenticata?"), solo per i clienti. */
add_filter( 'retrieve_password_notification_email', 'apac_reset_email', 10, 4 );
function apac_reset_email( $defaults, $key, $user_login, $user_data ) {
	unset( $key, $user_login );
	if ( ! apac_is_client_user( $user_data ) ) {
		return $defaults;
	}
	$link = apac_extract_rp_link( isset( $defaults['message'] ) ? $defaults['message'] : '' );
	if ( '' === $link ) {
		return $defaults;
	}
	$nome  = '' !== $user_data->first_name ? $user_data->first_name : $user_data->display_name;
	$intro = '<p style="margin:0 0 14px">Ciao <strong>' . esc_html( $nome ) . '</strong>,</p><p style="margin:0 0 6px">è stata richiesta una nuova password per il tuo accesso all’area riservata. Per sceglierla tocca il pulsante:</p>';
	$after = '<p style="margin:0">Se non hai chiesto tu il cambio, ignora questa email: la password attuale resta valida.</p>';
	$defaults['subject'] = 'Nuova password per l’area riservata di AP Sicurezza Lavoro';
	$defaults['message'] = apac_email_html( $defaults['subject'], $intro, 'Scegli la nuova password', $link, $after );
	$defaults['headers'] = apac_merge_html_headers( isset( $defaults['headers'] ) ? $defaults['headers'] : '' );
	return $defaults;
}

function apac_merge_html_headers( $headers ) {
	$list = is_array( $headers ) ? $headers : ( '' !== (string) $headers ? explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) ) : array() );
	$list = array_values(
		array_filter(
			$list,
			function ( $h ) {
				return '' !== trim( $h ) && 0 !== stripos( trim( $h ), 'content-type:' );
			}
		)
	);
	$list[] = 'Content-Type: text/html; charset=UTF-8';
	return $list;
}

/* ============================ Backup ================================== */

/** Stato del backup per la pagina impostazioni. */
function apac_render_backup_status() {
	$updraft  = class_exists( 'UpdraftPlus' ) || in_array( 'updraftplus/updraftplus.php', (array) get_option( 'active_plugins', array() ), true );
	$uploads  = wp_upload_dir( null, false );
	$inside   = 0 === strpos( wp_normalize_path( apac_private_dir() ), wp_normalize_path( trailingslashit( $uploads['basedir'] ) ) );
	$content  = 0 === strpos( wp_normalize_path( apac_private_dir() ), wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) ) );
	echo '<h2 id="apac-backup">Backup</h2>';
	echo '<p>Consigliato: plugin gratuito <strong>UpdraftPlus</strong> con salvataggio automatico su Google Drive (database + caricamenti).</p>';
	echo $updraft ? '<div class="notice notice-success inline"><p><strong>UpdraftPlus attivo.</strong> Verifica in Impostazioni → UpdraftPlus Backups che siano programmati sia i <em>file</em> sia il <em>database</em> e che la destinazione sia Google Drive.</p></div>'
		: '<div class="notice notice-warning inline"><p><strong>Nessun plugin di backup rilevato.</strong> Senza backup, un guasto del server può far perdere documenti e dati dei clienti.</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	if ( $inside ) {
		echo '<p>La cartella dei documenti dei clienti è dentro <code>uploads</code>: viene inclusa nel backup dei <em>caricamenti</em> (Uploads).</p>';
	} elseif ( $content ) {
		echo '<p>La cartella dei documenti è dentro <code>wp-content</code>: in UpdraftPlus va inclusa la voce <em>Altre cartelle</em> (Others).</p>';
	} else {
		echo '<div class="notice notice-error inline"><p><strong>Attenzione:</strong> la cartella dei documenti (<code>' . esc_html( apac_private_dir() ) . '</code>) è fuori da <code>wp-content</code> e la versione gratuita di UpdraftPlus non la salva. Va copiata con il backup dell’hosting.</p></div>';
	}
	echo '<p class="description">I backup contengono dati personali dei lavoratori: conservali solo su un account Google dello studio e indicalo nell’informativa privacy.</p>';
}
