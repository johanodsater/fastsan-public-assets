<?php
/**
 * Plugin Name: Fastsan – inget till tredje part före samtycke
 * Description: Ägarbeslut Johan 2026-11-23 19:08 ("kör 5"). 1) Google Analytics laddas först när besökaren sagt ja till analys i kakrutan (sätter AIB_GA4_WAIT_FOR_CONSENT, som aib-ga4-tag.php 1.1.0 läser). 2) WordPress emoji-skript stängs av: det hämtade bilder från s.w.org för tecknen i uppringningsrutan. Tecknen ritas nu av besökarens egen enhet. Tas bort genom att radera filen; då gäller det gamla beteendet igen.
 * Version: 1.0.0
 * Author: Ai Brick AB
 */
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

if ( ! defined( 'AIB_GA4_WAIT_FOR_CONSENT' ) ) {
	define( 'AIB_GA4_WAIT_FOR_CONSENT', true );
}

add_action(
	'init',
	static function () {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		add_filter( 'emoji_svg_url', '__return_false' );
		add_filter(
			'wp_resource_hints',
			static function ( $urls, $relation ) {
				if ( 'dns-prefetch' !== $relation ) {
					return $urls;
				}
				return array_values(
					array_filter(
						(array) $urls,
						static function ( $u ) {
							$h = is_array( $u ) ? ( $u['href'] ?? '' ) : $u;
							return false === strpos( (string) $h, 's.w.org' );
						}
					)
				);
			},
			10,
			2
		);
	}
);
