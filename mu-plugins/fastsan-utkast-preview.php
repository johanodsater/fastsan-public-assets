<?php
/**
 * Plugin Name: Fastsan Utkast Preview
 * Description: Shows a draft page to visitors without login when the address carries the right key (?page_id=<id>&fs_utkast=<key>). The key is the first 24 hex of HMAC-SHA256('fs-utkast-<id>', aib_deployer_secret) and is never stored. A draft qualifies when it has post meta _fastsan_utkast_preview = 1, or is the page in option fastsan_startsida_utkast_id. Sends noindex and disables page cache.
 * Version: 1.1.0
 * Author: Ai Brick AB
 *
 * 1.1.0 (2026-11-25, B): any draft marked with _fastsan_utkast_preview, not only the start page draft.
 * 1.0.0 (2026-11-19, B): start page draft only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

function fastsan_utkast_preview_ok() {
	static $ok = null;
	if ( null !== $ok ) {
		return $ok;
	}
	$ok  = false;
	$pid = isset( $_GET['page_id'] ) ? (int) $_GET['page_id'] : 0;
	$got = isset( $_GET['fs_utkast'] ) ? (string) $_GET['fs_utkast'] : '';
	$sec = (string) get_option( 'aib_deployer_secret' );
	if ( ! $pid || '' === $got || '' === $sec ) {
		return $ok;
	}
	$post = get_post( $pid );
	if ( ! $post || 'page' !== $post->post_type || 'draft' !== $post->post_status ) {
		return $ok;
	}
	$marked = ( '1' === (string) get_post_meta( $pid, '_fastsan_utkast_preview', true ) )
		|| ( (int) get_option( 'fastsan_startsida_utkast_id' ) === $pid );
	if ( ! $marked ) {
		return $ok;
	}
	$ok = hash_equals( substr( hash_hmac( 'sha256', 'fs-utkast-' . $pid, $sec ), 0, 24 ), $got );
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
