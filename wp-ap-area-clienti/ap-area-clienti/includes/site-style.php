<?php
defined( 'ABSPATH' ) || exit;

/**
 * Allineamento grafico al sito.
 *
 * Le pagine del sito contengono un foglio di stile proprio (sistema grafico "aps":
 * colori, testate, riquadri, pulsanti). Il plugin ne copia una versione dalle pagine
 * esistenti e la applica all'area riservata, così che abbia lo stesso aspetto.
 * Si può riallineare in qualsiasi momento da Impostazioni > Area Clienti.
 */

/** Estrae i blocchi <style> che contengono il sistema grafico "aps" da un testo. */
function apac_extract_aps_css( $content ) {
	if ( ! preg_match_all( '#<style[^>]*>(.*?)</style>#is', (string) $content, $m ) ) {
		return '';
	}
	$css = array();
	foreach ( $m[1] as $block ) {
		if ( false !== strpos( $block, '.aps' ) ) {
			$css[] = trim( $block );
		}
	}
	return implode( "\n", $css );
}

/**
 * Copia lo stile dalla prima pagina del sito che lo contiene
 * (prima la home, poi le altre pagine pubblicate).
 *
 * @return bool true se lo stile è stato trovato.
 */
function apac_sync_site_css() {
	$portal_id  = (int) get_option( 'apac_page_id' );
	$candidates = array();
	$front      = (int) get_option( 'page_on_front' );
	if ( $front ) {
		$candidates[] = $front;
	}
	$pages = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'fields'         => 'ids',
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		)
	);
	$candidates = array_unique( array_merge( $candidates, $pages ) );

	foreach ( $candidates as $id ) {
		if ( (int) $id === $portal_id ) {
			continue;
		}
		$post = get_post( $id );
		if ( ! $post ) {
			continue;
		}
		$css = apac_extract_aps_css( $post->post_content );
		if ( '' !== $css ) {
			// Nessuna chiusura del tag style dentro il CSS salvato.
			$css = str_ireplace( '</style', '', $css );
			update_option( 'apac_site_css', $css, false );
			update_option(
				'apac_site_css_info',
				array(
					'page'  => (int) $id,
					'title' => get_the_title( $id ),
					'date'  => current_time( 'mysql' ),
				),
				false
			);
			return true;
		}
	}
	update_option( 'apac_site_css', '', false );
	update_option( 'apac_site_css_info', array( 'page' => 0, 'title' => '', 'date' => current_time( 'mysql' ) ), false );
	return false;
}

/** Al primo caricamento dopo l'installazione, se lo stile non è ancora stato copiato. */
add_action( 'init', 'apac_maybe_sync_site_css' );
function apac_maybe_sync_site_css() {
	if ( false === get_option( 'apac_site_css_info', false ) ) {
		apac_sync_site_css();
	}
}

/** Classe sul body della pagina dell'area riservata. */
add_filter( 'body_class', 'apac_body_class' );
function apac_body_class( $classes ) {
	$page_id = (int) get_option( 'apac_page_id' );
	if ( $page_id && is_page( $page_id ) ) {
		$classes[] = 'apac-page';
	}
	return $classes;
}
