<?php
/**
 * Plugin Name: Fastsan Utkast Preview
 * Description: Visar startsidans utkast utan inloggning när adressen bär rätt nyckel. Nyckeln härleds ur aib_deployer_secret och lagras inte. Tas bort vid bytet till live.
 * Version: 1.0.0
 * Author: Ai Brick AB
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

function fastsan_utkast_preview_ok() {
	static $ok = null;
	if ( null !== $ok ) {
		return $ok;
	}
	$pid = (int) get_option( 'fastsan_startsida_utkast_id' );
	$sec = (string) get_option( 'aib_deployer_secret' );
	$got = isset( $_GET['fs_utkast'] ) ? (string) $_GET['fs_utkast'] : '';
	$ok  = ( $pid && '' !== $sec && '' !== $got
		&& isset( $_GET['page_id'] ) && (int) $_GET['page_id'] === $pid
		&& hash_equals( substr( hash_hmac( 'sha256', 'fs-utkast-' . $pid, $sec ), 0, 24 ), $got ) );
	return $ok;
}

add_action( 'pre_get_posts', static function ( $q ) {
	if ( is_admin() || ! $q->is_main_query() || ! fastsan_utkast_preview_ok() ) {
		return;
	}
	$q->set( 'post_status', array( 'publish', 'draft' ) );
} );

add_action( 'template_redirect', static function () {
	if ( ! fastsan_utkast_preview_ok() ) {
		return;
	}
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow', true );
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	do_action( 'litespeed_control_set_nocache', 'fastsan utkast' );
}, 0 );

add_filter( 'redirect_canonical', static function ( $url ) {
	return fastsan_utkast_preview_ok() ? false : $url;
} );
