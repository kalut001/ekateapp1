<?php
defined( 'ABSPATH' ) || exit;

/*
 * 1.7.1 – Visibilità sui motori di ricerca (senza plugin SEO esterni):
 * titoli e descrizioni delle pagine, anteprima per i social, dati strutturati
 * dell'attività, verifica di Google Search Console, area riservata fuori dalla sitemap.
 * Se è attivo un plugin SEO completo, questo modulo si disattiva da solo.
 */

function apac_seo_active_plugin() {
	return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ) || class_exists( 'The_SEO_Framework\Load' );
}

/** Dati dell'attività (come riportati nel sito). */
function apac_seo_business() {
	return array(
		'name'      => 'AP Sicurezza Lavoro',
		'person'    => 'Antonino Porcelli',
		'job'       => 'Consulente QHSE e formatore per la sicurezza sul lavoro',
		'telephone' => '+39 351 885 8554',
		'email'     => 'info@apsicurezzalavoro.it',
		'street'    => 'Via Santocanale 74',
		'cap'       => '90151',
		'city'      => 'Palermo',
		'province'  => 'PA',
		'vat'       => 'IT07436760826',
		'logo'      => content_url( '/uploads/2026/10/logo-ap-sicurezza-lavoro.webp' ),
	);
}

/** Percorso => [ titolo, descrizione ]. */
function apac_seo_pages() {
	$s = ' | AP Sicurezza Lavoro';
	return array(
		'/'                                                    => array( 'Sicurezza sul lavoro a Palermo: DVR, consulenza e corsi' . $s, 'Consulenza sulla sicurezza sul lavoro a Palermo: valutazione dei rischi e DVR, formazione obbligatoria, corsi BLSD e supporto QHSE in azienda e in cantiere.' ),
		'/consulenza-per-la-sicurezza-sul-lavoro/'             => array( 'Consulenza sicurezza sul lavoro a Palermo' . $s, 'DVR, consulenza QHSE in azienda e in cantiere, sopralluoghi, documentazione e scadenziari: un unico riferimento tecnico per la sicurezza della tua azienda a Palermo.' ),
		'/redazione-del-dvr/'                                  => array( 'Redazione del DVR a Palermo, sopralluogo compreso' . $s, 'Documento di Valutazione dei Rischi per aziende di Palermo: analisi delle attività e delle mansioni, sopralluogo compreso, allegati, nomine e scadenziario.' ),
		'/consulente-qhse-a-palermo/'                          => array( 'Consulente QHSE a Palermo – Dott. Antonino Porcelli' . $s, 'Dott. Antonino Porcelli, consulente QHSE a Palermo e formatore per la sicurezza: supporto in azienda e nei cantieri edili e infrastrutturali.' ),
		'/corsi-blsd-pblsd/'                                   => array( 'Corsi BLSD e PBLSD a Palermo per aziende e scuole' . $s, 'Corsi BLSD e PBLSD a Palermo: rianimazione cardiopolmonare, uso del defibrillatore e disostruzione nell’adulto, nel bambino e nel lattante, con prove pratiche.' ),
		'/formazione-sulla-sicurezza/'                         => array( 'Corsi di formazione sulla sicurezza a Palermo' . $s, 'Corsi sulla sicurezza a Palermo per lavoratori, preposti, dirigenti, datori di lavoro, RSPP, ASPP e addetti alle emergenze, in base a ruolo e mansioni.' ),
		'/formazione-sulla-sicurezza/formazione-dei-lavoratori/' => array( 'Formazione dei lavoratori: generale, specifica e aggiornamento' . $s, 'Formazione generale, specifica e aggiornamento dei lavoratori secondo l’art. 37 del D.Lgs. 81/2008 e l’Accordo Stato-Regioni, costruita sulle mansioni reali.' ),
		'/formazione-sulla-sicurezza/formazione-dei-preposti/' => array( 'Formazione dei preposti e aggiornamento' . $s, 'Corso preposti interamente in presenza con aggiornamento almeno biennale: vigilanza, comunicazione e gestione delle criticità (art. 37, c. 7-ter, D.Lgs. 81/2008).' ),
		'/formazione-sulla-sicurezza/formazione-dei-dirigenti/' => array( 'Formazione dei dirigenti per la sicurezza' . $s, 'Corso per dirigenti: ruolo, obblighi e organizzazione della prevenzione per chi attua le direttive del datore di lavoro (art. 37, c. 7, D.Lgs. 81/2008).' ),
		'/formazione-sulla-sicurezza/formazione-del-datore-di-lavoro/' => array( 'Formazione del datore di lavoro' . $s, 'Obblighi, responsabilità e organizzazione della prevenzione per il datore di lavoro, secondo l’art. 37 del D.Lgs. 81/2008 e l’Accordo Stato-Regioni.' ),
		'/formazione-sulla-sicurezza/datore-di-lavoro-rspp/'   => array( 'Corso datore di lavoro RSPP' . $s, 'Quando il datore di lavoro può svolgere direttamente i compiti del servizio di prevenzione e protezione e quale formazione serve (art. 34 D.Lgs. 81/2008).' ),
		'/formazione-sulla-sicurezza/formazione-rspp-e-aspp/'  => array( 'Formazione RSPP e ASPP: moduli A, B, C e aggiornamento' . $s, 'Moduli A, B e C per RSPP e ASPP e aggiornamento: verifichiamo titolo di studio e attestati per indicarti il percorso corretto (art. 32 D.Lgs. 81/2008).' ),
		'/formazione-sulla-sicurezza/addetti-antincendio/'     => array( 'Corso addetti antincendio livello 1, 2 e 3' . $s, 'Corsi per addetti antincendio di livello 1, 2 e 3 e aggiornamento secondo il D.M. 2 settembre 2021, in base alla classificazione del luogo di lavoro.' ),
		'/formazione-sulla-sicurezza/addetti-al-primo-soccorso/' => array( 'Corso addetti al primo soccorso aziendale' . $s, 'Formazione e aggiornamento degli addetti al primo soccorso per aziende dei gruppi A, B e C secondo il D.M. 388/2003.' ),
		'/misurazione-del-radon/'                              => array( 'Misurazione del radon a Palermo' . $s, 'Supporto alle campagne di misurazione del radon negli ambienti di lavoro: posizionamento dei dosimetri e raccordo con il servizio incaricato.' ),
		'/rischi/'                                             => array( 'I principali rischi sul lavoro' . $s, 'Cosa sono, dove si presentano, come si valutano e quali misure adottare per i principali rischi sul lavoro da considerare nel DVR.' ),
		'/rischi/rischio-amianto/' => array( 'Rischio amianto sul lavoro' . $s, 'Materiali contenenti amianto ancora presenti negli edifici: come individuarli prima dei lavori e gestirli in sicurezza.' ),
		'/rischi/rischio-biologico/' => array( 'Rischio biologico sul lavoro' . $s, 'Batteri, virus, funghi e altri microrganismi con cui si può entrare in contatto durante il lavoro: valutazione e misure di prevenzione.' ),
		'/rischi/rischio-chimico/' => array( 'Rischio chimico sul lavoro' . $s, 'Sostanze e miscele pericolose usate o prodotte durante il lavoro: come individuarle, valutare l’esposizione e ridurla.' ),
		'/rischi/rischio-elettrico/' => array( 'Rischio elettrico sul lavoro' . $s, 'Impianti, attrezzature e lavori vicino a parti in tensione: come prevenire contatti, archi elettrici e incendi.' ),
		'/rischi/rischio-incendio-esplosione/' => array( 'Rischio incendio ed esplosione' . $s, 'Materiali combustibili, fonti di innesco e atmosfere esplosive: come valutare il rischio, prevenirlo e organizzare l’emergenza.' ),
		'/rischi/rischio-lavoro-in-quota/' => array( 'Rischio lavoro in quota e cadute dall’alto' . $s, 'Le cadute dall’alto sono tra le cause più gravi di infortunio: valutazione del rischio e priorità alle protezioni collettive.' ),
		'/rischi/rischio-legionella/' => array( 'Rischio legionella negli impianti' . $s, 'Impianti idrici e di climatizzazione possono diffondere la Legionella con l’acqua nebulizzata: valutazione e misure di controllo.' ),
		'/rischi/rischio-movimentazione-manuale-carichi/' => array( 'Rischio movimentazione manuale dei carichi' . $s, 'Sollevare, spingere, tirare o trasportare pesi: un rischio diffuso per la schiena e gli arti superiori, da valutare e ridurre.' ),
		'/rischi/rischio-rumore/' => array( 'Rischio rumore sul lavoro' . $s, 'L’esposizione al rumore durante il lavoro può danneggiare l’udito in modo permanente: va misurata, valutata e ridotta.' ),
		'/rischi/rischio-stress-lavoro-correlato/' => array( 'Rischio stress lavoro-correlato' . $s, 'Carichi di lavoro, organizzazione e relazioni: lo stress lavoro-correlato è un rischio da valutare come gli altri.' ),
		'/rischi/rischio-vibrazioni/' => array( 'Rischio vibrazioni sul lavoro' . $s, 'Utensili vibranti e veicoli trasmettono vibrazioni a mani, braccia e colonna vertebrale: valutazione e misure di riduzione.' ),
		'/rischi/rischio-videoterminali/' => array( 'Rischio videoterminali in ufficio' . $s, 'Computer e schermi in ufficio: postazione, illuminazione e pause per proteggere vista e postura di chi lavora al videoterminale.' ),
		'/domande-frequenti/'                                  => array( 'Domande frequenti su DVR, corsi e BLSD' . $s, 'Risposte alle domande più frequenti su DVR, formazione sulla sicurezza, BLSD e radon, e su cosa preparare per richiedere un preventivo.' ),
		'/normativa-sulla-sicurezza-sul-lavoro/'               => array( 'Normativa sulla sicurezza sul lavoro: fonti utili' . $s, 'Collegamenti alle fonti istituzionali per consultare la normativa sulla sicurezza sul lavoro: Normattiva, Gazzetta Ufficiale, Ministero del Lavoro, INAIL.' ),
		'/richiedi-un-preventivo/'                             => array( 'Richiedi un preventivo' . $s, 'Descrivi la tua attività e cosa ti serve: ti ricontattiamo entro un giorno lavorativo per preparare il preventivo per DVR, consulenza o corsi. Senza impegno.' ),
	);
}

/** Titolo e descrizione per la richiesta corrente ([] se non previsti). */
function apac_seo_current() {
	static $cur = null;
	if ( null !== $cur ) {
		return $cur;
	}
	$cur = array();
	if ( is_admin() || is_feed() || is_404() || ( function_exists( 'apac_is_portal_request' ) && apac_is_portal_request() ) ) {
		return $cur;
	}
	$path  = wp_parse_url( home_url( add_query_arg( array() ) ), PHP_URL_PATH );
	$base  = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	$path  = '/' . ltrim( substr( (string) $path, strlen( rtrim( (string) $base, '/' ) ) ), '/' );
	$path  = '/' === $path ? '/' : trailingslashit( $path );
	$pages = apac_seo_pages();
	if ( is_front_page() ) {
		$path = '/';
	}
	if ( isset( $pages[ $path ] ) ) {
		$cur = $pages[ $path ];
	}
	return $cur;
}

add_filter( 'pre_get_document_title', 'apac_seo_title', 20 );
function apac_seo_title( $title ) {
	if ( apac_seo_active_plugin() ) {
		return $title;
	}
	$c = apac_seo_current();
	return ( $c && '' !== $c[0] ) ? $c[0] : $title;
}

add_action( 'wp_head', 'apac_seo_head', 2 );
function apac_seo_head() {
	if ( apac_seo_active_plugin() ) {
		return;
	}
	$code = (string) get_option( 'apac_gsc_code', '' );
	if ( '' !== $code && is_front_page() ) {
		echo '<meta name="google-site-verification" content="' . esc_attr( $code ) . '">' . "\n";
	}
	$c = apac_seo_current();
	if ( ! $c ) {
		return;
	}
	$b     = apac_seo_business();
	$url   = is_front_page() ? home_url( '/' ) : get_permalink();
	$title = '' !== $c[0] ? $c[0] : wp_get_document_title();
	echo '<meta name="description" content="' . esc_attr( $c[1] ) . '">' . "\n";
	echo '<meta property="og:locale" content="it_IT">' . "\n";
	echo '<meta property="og:type" content="website">' . "\n";
	echo '<meta property="og:site_name" content="' . esc_attr( $b['name'] ) . '">' . "\n";
	echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
	echo '<meta property="og:description" content="' . esc_attr( $c[1] ) . '">' . "\n";
	if ( $url ) {
		echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
	}
	echo '<meta property="og:image" content="' . esc_url( $b['logo'] ) . '">' . "\n";
	echo '<meta name="twitter:card" content="summary">' . "\n";
	if ( is_front_page() ) {
		$data = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'ProfessionalService',
			'@id'         => home_url( '/#attivita' ),
			'name'        => $b['name'],
			'url'         => home_url( '/' ),
			'logo'        => $b['logo'],
			'image'       => $b['logo'],
			'description' => $c[1],
			'telephone'   => $b['telephone'],
			'email'       => $b['email'],
			'vatID'       => $b['vat'],
			'address'     => array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => $b['street'],
				'postalCode'      => $b['cap'],
				'addressLocality' => $b['city'],
				'addressRegion'   => $b['province'],
				'addressCountry'  => 'IT',
			),
			'areaServed'  => array( '@type' => 'City', 'name' => 'Palermo' ),
			'founder'     => array(
				'@type'    => 'Person',
				'name'     => $b['person'],
				'jobTitle' => $b['job'],
			),
			'knowsAbout'  => array( 'Sicurezza sul lavoro', 'Valutazione dei rischi', 'DVR', 'Formazione sulla sicurezza', 'BLSD', 'PBLSD', 'QHSE', 'Radon' ),
		);
		echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}
}

/* L'area riservata (non indicizzabile) non va nella sitemap. */
add_filter( 'wp_sitemaps_posts_query_args', 'apac_seo_sitemap_exclude', 10, 2 );
function apac_seo_sitemap_exclude( $args, $post_type ) {
	$pid = (int) get_option( 'apac_page_id' );
	if ( 'page' === $post_type && $pid ) {
		$args['post__not_in'] = array_merge( isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array(), array( $pid ) );
	}
	return $args;
}

/* Codice di verifica di Google Search Console (impostazioni del plugin). */
add_action( 'admin_post_apac_save_gsc', 'apac_admin_save_gsc' );
function apac_admin_save_gsc() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'apac_save_gsc' ) ) {
		wp_die( 'Non consentito.' );
	}
	$raw = isset( $_POST['apac_gsc_code'] ) ? trim( wp_unslash( $_POST['apac_gsc_code'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	// Si accetta sia il solo codice sia l'intero tag <meta ... content="...">.
	if ( preg_match( '/content=["\']([^"\']+)["\']/', $raw, $m ) ) {
		$raw = $m[1];
	}
	$code = preg_replace( '/[^A-Za-z0-9_\-]/', '', $raw );
	update_option( 'apac_gsc_code', substr( $code, 0, 100 ) );
	wp_safe_redirect( admin_url( 'options-general.php?page=apac-settings&saved=1#apac-seo' ) );
	exit;
}

function apac_render_seo_settings() {
	$code = (string) get_option( 'apac_gsc_code', '' );
	echo '<h2 id="apac-seo">Visibilità su Google</h2>';
	if ( apac_seo_active_plugin() ) {
		echo '<p>È attivo un plugin SEO: titoli, descrizioni e dati dell’attività li gestisce quel plugin.</p>';
	} else {
		echo '<p>Titoli, descrizioni, anteprime per i social e dati dell’attività sono gestiti da questo plugin. La sitemap del sito è <a href="' . esc_url( home_url( '/wp-sitemap.xml' ) ) . '" target="_blank" rel="noopener">wp-sitemap.xml</a>.</p>';
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'apac_save_gsc' );
	echo '<input type="hidden" name="action" value="apac_save_gsc"><table class="form-table"><tr><th scope="row"><label for="apac_gsc_code">Codice di verifica Google Search Console</label></th><td><input type="text" class="regular-text" id="apac_gsc_code" name="apac_gsc_code" value="' . esc_attr( $code ) . '" placeholder="Incolla qui il tag HTML o il solo codice"><p class="description">In Search Console scegli «Prefisso URL» → metodo «Tag HTML» e incolla qui il tag.</p></td></tr></table>';
	submit_button( 'Salva codice' );
	echo '</form>';
}
