<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pulsante "Area riservata" nel menu del sito e shortcode [ap_area_riservata].
 */

/** Testo del pulsante. */
function apac_button_label() {
	$label = trim( (string) get_option( 'apac_button_label', 'Area riservata' ) );
	return '' !== $label ? $label : 'Area riservata';
}

/**
 * Menu in cui inserire il pulsante.
 * Al primo utilizzo sceglie il menu assegnato alla posizione principale del tema.
 */
function apac_button_menu_id() {
	$opt = get_option( 'apac_menu_id', null );
	if ( null !== $opt && false !== $opt ) {
		return (int) $opt;
	}
	$locations = get_nav_menu_locations();
	$preferred = array( 'menu_1', 'primary', 'main', 'header', 'menu-1', 'main-menu', 'primary-menu' );
	$found     = 0;
	foreach ( $preferred as $loc ) {
		if ( ! empty( $locations[ $loc ] ) ) {
			$found = (int) $locations[ $loc ];
			break;
		}
	}
	if ( ! $found && $locations ) {
		$first = reset( $locations );
		$found = (int) $first;
	}
	update_option( 'apac_menu_id', $found );
	return $found;
}

add_filter( 'wp_nav_menu_items', 'apac_add_menu_button', 20, 2 );
function apac_add_menu_button( $items, $args ) {
	$target = apac_button_menu_id();
	if ( ! $target || empty( $args->menu ) ) {
		return $items;
	}
	$menu    = $args->menu;
	$menu_id = 0;
	if ( $menu instanceof WP_Term ) {
		$menu_id = (int) $menu->term_id;
	} else {
		$obj     = wp_get_nav_menu_object( $menu );
		$menu_id = $obj ? (int) $obj->term_id : 0;
	}
	if ( $menu_id !== $target ) {
		return $items;
	}
	$page_id = (int) get_option( 'apac_page_id' );
	$current = ( $page_id && is_page( $page_id ) ) ? ' current-menu-item' : '';
	$items  .= '<li class="menu-item apac-menu-button' . $current . '"><a class="ct-menu-link" href="' . esc_url( apac_portal_page_url() ) . '">'
		. '<svg aria-hidden="true" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>'
		. '<span>' . esc_html( apac_button_label() ) . '</span></a></li>';
	return $items;
}

/**
 * Posizione del pulsante:
 * - 'auto'  (predefinita): nella barra superiore del sito se esiste (.aps-topbar),
 *           altrimenti nel menu; nel menu mobile è sempre presente.
 * - 'menu': solo nel menu.
 */
function apac_button_position() {
	$pos = (string) get_option( 'apac_button_position', 'auto' );
	return in_array( $pos, array( 'auto', 'menu' ), true ) ? $pos : 'auto';
}

add_action( 'wp_footer', 'apac_topbar_button_script', 5 );
function apac_topbar_button_script() {
	// "Nessuno" nelle impostazioni disattiva il pulsante ovunque, anche nella barra superiore.
	if ( ! apac_button_menu_id() || 'menu' === apac_button_position() || ! get_option( 'apac_page_id' ) ) {
		return;
	}
	$page_id = (int) get_option( 'apac_page_id' );
	$data    = array(
		'url'     => apac_portal_page_url(),
		'label'   => apac_button_label(),
		'current' => ( $page_id && is_page( $page_id ) ),
	);
	?>
	<script id="apac-topbar-button">
	(function (d) {
		var cfg = <?php echo wp_json_encode( $data ); ?>;
		var bars = d.querySelectorAll('.aps-topbar');
		if (!bars.length) { return; }
		bars.forEach(function (bar) {
			if (bar.querySelector('.apac-tb-area')) { return; }
			var a = d.createElement('a');
			a.className = 'aps-tb-item apac-tb-area' + (cfg.current ? ' is-current' : '');
			a.href = cfg.url;
			a.innerHTML = '<svg aria-hidden="true" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';
			var s = d.createElement('span');
			s.textContent = cfg.label;
			a.appendChild(s);
			bar.appendChild(a);
		});
		d.documentElement.classList.add('apac-topbar-btn');
	})(document);
	</script>
	<?php
}

add_shortcode( 'ap_area_riservata', 'apac_button_shortcode' );
function apac_button_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'testo' => apac_button_label() ), $atts, 'ap_area_riservata' );
	return '<a class="apac-area-btn" href="' . esc_url( apac_portal_page_url() ) . '">' . esc_html( $atts['testo'] ) . '</a>';
}

add_action( 'wp_enqueue_scripts', 'apac_enqueue_button_css' );
function apac_enqueue_button_css() {
	wp_enqueue_style( 'apac-button', APAC_URL . 'assets/button.css', array(), APAC_VERSION );
}
