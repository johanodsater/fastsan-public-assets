<?php
/**
 * Plugin Name: AIB fs_force Guard
 * Description: Puts the __fs-injector ?fs_force= trigger behind aib-deployer auth. Unsigned requests get the parameter dropped before init:999 (the injector then runs its normal no-op pass). Signed requests (X-AIB-Ts / X-AIB-Nonce / X-AIB-Sig = HMAC-SHA256 over "ts\nnonce\nfs_force=<value>" with option aib_deployer_secret, same replay window as aib-deployer) pass through. Log: uploads/fastsan-content/guard.log.
 * Version: 1.0.1
 * Author: Ai Brick AB (C)
 *
 * Why: ?fs_force=all clears the injector flags and re-writes post_content from uploads/fastsan-content/*.html — it reverts every DB-side content patch. Owner decision 2026-09-07 (RELA-A-1179 p2, alt. "bakom aib-deployer-auth"; .off would also kill __fs_handle_redirect_to and the file->page pipeline).
 * 1.0.1 (C, 2026-09-07): init-prio 1 -> 0 (måste ligga före allt annat på init; __fs-seo-supp hade en egen fs_force-handler på init:1 som exitade före guarden — den är borttagen i seo-supp v1.6.0).
 * Rollback: rename to .off via aib-deployer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}
if ( defined( 'AIB_FS_FORCE_GUARD_LOADED' ) ) {
	return;
}
define( 'AIB_FS_FORCE_GUARD_LOADED', '1.0.0' );

add_action( 'init', static function () {
	if ( ! isset( $_GET['fs_force'] ) ) {
		return;
	}
	$value = (string) $_GET['fs_force'];
	$ok    = false;
	$why   = 'unsigned';

	$secret = (string) get_option( 'aib_deployer_secret', '' );
	$ts     = (string) ( $_SERVER['HTTP_X_AIB_TS'] ?? '' );
	$nonce  = (string) ( $_SERVER['HTTP_X_AIB_NONCE'] ?? '' );
	$sig    = (string) ( $_SERVER['HTTP_X_AIB_SIG'] ?? '' );

	if ( strlen( $secret ) < 32 ) {
		$why = 'no_secret';
	} elseif ( '' === $ts && '' === $nonce && '' === $sig ) {
		$why = 'unsigned';
	} elseif ( ! ctype_digit( $ts ) || abs( time() - (int) $ts ) > 300 ) {
		$why = 'ts';
	} elseif ( ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $nonce ) ) {
		$why = 'nonce';
	} elseif ( ! hash_equals( hash_hmac( 'sha256', $ts . "\n" . $nonce . "\n" . 'fs_force=' . $value, $secret ), strtolower( $sig ) ) ) {
		$why = 'sig';
	} elseif ( get_transient( 'aib_dep_n_' . md5( $nonce ) ) ) {
		$why = 'replay';
	} else {
		set_transient( 'aib_dep_n_' . md5( $nonce ), 1, 600 );
		$ok  = true;
		$why = 'ok';
	}

	if ( ! $ok ) {
		unset( $_GET['fs_force'], $_REQUEST['fs_force'] );
	}

	$dir = WP_CONTENT_DIR . '/uploads/fastsan-content';
	if ( is_dir( $dir ) ) {
		$line = '[' . gmdate( 'c' ) . '] ' . ( $ok ? 'ALLOW' : 'DROP' ) . ' fs_force=' . substr( preg_replace( '/[^A-Za-z0-9_,.-]/', '_', $value ), 0, 80 )
			. ' reason=' . $why . ' ip=' . ( $_SERVER['REMOTE_ADDR'] ?? '?' ) . "\n";
		@file_put_contents( $dir . '/guard.log', $line, FILE_APPEND );
	}
}, 0 );
