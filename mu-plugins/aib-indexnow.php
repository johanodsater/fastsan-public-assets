<?php
/**
 * Plugin Name: AIB IndexNow
 * Description: Meddelar IndexNow (Bing, Yandex, Naver, Seznam, Yep) när en sida eller ett inlägg publiceras, ändras eller tas ner. Nyckelfilen /<nyckel>.txt serveras av WordPress. Google deltar inte i IndexNow. Avstängning: byt namn på filen till .off.
 * Version: 1.0.0
 * Author: Ai Brick AB
 *
 * 1.0.0 (2026-11-26, B): första versionen (Johan 2026-11-26 14:38 "kör IX2"). Egen fil i stället för Rank Maths
 * direktindexering: Rank Math på fastsan.se saknar modulinställningen (rank_math_modules), och att skriva den kan slå
 * på andra moduler. Nyckeln är publik till sin natur (den ligger i en öppen fil på sajten) och är ingen inloggning.
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}
if ( defined( 'AIB_INDEXNOW_LOADED' ) ) {
	return;
}
define( 'AIB_INDEXNOW_LOADED', '1.0.0' );
define( 'AIB_INDEXNOW_KEY', 'b09c3a45d8fe9e45f048c6afe1517537' );
define( 'AIB_INDEXNOW_ENDPOINT', 'https://api.indexnow.org/indexnow' );

/** Nyckelfilen: /<nyckel>.txt svarar med nyckeln som ren text. */
add_action( 'init', static function () {
	$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
	if ( '/' . AIB_INDEXNOW_KEY . '.txt' !== $path ) {
		return;
	}
	status_header( 200 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	echo AIB_INDEXNOW_KEY;
	exit;
}, 0 );

/** Samlar adresser som ändrats under anropet. */
function aib_indexnow_queue( $url = null ) {
	static $urls = array();
	if ( null !== $url ) {
		$urls[ $url ] = true;
	}
	return array_keys( $urls );
}

add_action( 'transition_post_status', static function ( $new, $old, $post ) {
	if ( ! $post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
		return;
	}
	if ( ! in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
		return;
	}
	// Publicerad, ändrad medan publicerad, eller nertagen efter att ha varit publicerad.
	if ( 'publish' !== $new && 'publish' !== $old ) {
		return;
	}
	if ( 'publish' === $new ) {
		$url = get_permalink( $post );
	} else {
		// Nertagen: adressen den hade som publicerad.
		$url = home_url( '/' . user_trailingslashit( str_replace( '__trashed', '', (string) get_page_uri( $post ) ) ) );
	}
	if ( $url && 0 === strpos( $url, home_url( '/' ) ) ) {
		aib_indexnow_queue( $url );
	}
}, 10, 3 );

/** Ett anrop per WordPress-anrop, i slutet, utan att vänta på svaret. */
add_action( 'shutdown', static function () {
	$urls = aib_indexnow_queue();
	if ( ! $urls ) {
		return;
	}
	$host = (string) parse_url( home_url( '/' ), PHP_URL_HOST );
	$body = array(
		'host'        => $host,
		'key'         => AIB_INDEXNOW_KEY,
		'keyLocation' => home_url( '/' . AIB_INDEXNOW_KEY . '.txt' ),
		'urlList'     => array_values( $urls ),
	);
	wp_remote_post( AIB_INDEXNOW_ENDPOINT, array(
		'blocking' => false,
		'timeout'  => 2,
		'headers'  => array( 'Content-Type' => 'application/json; charset=utf-8' ),
		'body'     => wp_json_encode( $body ),
	) );
	update_option( 'aib_indexnow_last', array( 'tid' => gmdate( 'c' ), 'urls' => array_values( $urls ) ), false );
}, 20 );
