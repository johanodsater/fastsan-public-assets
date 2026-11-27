<?php
/**
 * Plugin Name: __FS Forfattare (one-shot)
 * Description: ?fs_os=forfattare creates the user Daniel Stalbrand (role contributor, random password, no mail sent) if missing and sets him as author of draft post 172 (the reuse guide). Answers with JSON and deletes itself. Touches only post 172 and only while it is a draft.
 * Version: 1.0.0
 * Author: Ai Brick AB
 *
 * 1.0.0 (2026-11-27, B): Johan 11:26 "3 Författare Daniel Stålbrand".
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

add_action( 'init', static function () {
	if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}
	if ( ! isset( $_GET['fs_os'] ) || 'forfattare' !== $_GET['fs_os'] ) {
		return;
	}
	global $wpdb;
	$log  = array( 'time' => gmdate( 'c' ) );
	$user = get_user_by( 'login', 'daniel-stalbrand' );
	if ( $user ) {
		$uid            = (int) $user->ID;
		$log['user']    = 'fanns';
	} else {
		$uid = wp_insert_user( array(
			'user_login'    => 'daniel-stalbrand',
			'user_pass'     => wp_generate_password( 40, true, true ),
			'user_email'    => 'daniel@fastsan.se',
			'user_nicename' => 'daniel-stalbrand',
			'display_name'  => "Daniel St\xC3\xA5lbrand",
			'nickname'      => "Daniel St\xC3\xA5lbrand",
			'first_name'    => 'Daniel',
			'last_name'     => "St\xC3\xA5lbrand",
			'role'          => 'contributor',
		) );
		if ( is_wp_error( $uid ) ) {
			$log['user'] = 'error: ' . $uid->get_error_message();
			error_log( '[AIB] __fs-forfattare: ' . wp_json_encode( $log ) );
			wp_send_json( $log, 500 );
		}
		$log['user'] = 'skapad';
	}
	$log['uid'] = (int) $uid;
	$post       = get_post( 172 );
	if ( $post && 'post' === $post->post_type && 'draft' === $post->post_status ) {
		$wpdb->update( $wpdb->posts, array( 'post_author' => (int) $uid ), array( 'ID' => 172 ) );
		clean_post_cache( 172 );
		$log['post_172_author'] = (int) get_post_field( 'post_author', 172 );
	} else {
		$log['post_172_author'] = 'inte ett utkast, oforandrad';
	}
	error_log( '[AIB] __fs-forfattare: ' . wp_json_encode( $log ) );
	@unlink( __FILE__ );
	wp_send_json( $log );
}, 99 );
