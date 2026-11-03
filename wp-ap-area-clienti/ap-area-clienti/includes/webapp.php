<?php
/**
 * Web app installabile: service worker servito dall'area riservata, riquadri «Installa l'app» e
 * condivisione del link.
 *
 * 1.9.15: file per area; il codice proviene dai file per versione (session1-3.php, v190.php), invariato.
 */
defined( 'ABSPATH' ) || exit;

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

/* Il riquadro fluttuante del plugin della web app si sovrapporrebbe al menu in basso:
 * nell'area riservata è sostituito dal riquadro integrato "Installa l'app". */
add_action( 'wp_head', 'apac_hide_pwa_floating_card', 100 );

function apac_hide_pwa_floating_card() {
	if ( function_exists( 'apac_is_portal_request' ) && apac_is_portal_request() ) {
		echo '<style id="apac-pwa-card">.ap-pwa-install{display:none!important}</style>' . "\n";
	}
}

function apac_render_installa() {
	if ( ! apac_is_admin() ) {
		return;
	}
	apac_render_share_card( true );
}
