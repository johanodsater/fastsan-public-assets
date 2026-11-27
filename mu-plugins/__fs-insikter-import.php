<?php
/**
 * Plugin Name: __FS Insikter Import (one-shot)
 * Description: ?fs_os=insikter creates or updates a draft copy of the list page /insikter/ from uploads/fastsan-content/insikter/insikter.txt, marked for the preview link. Never touches the published page 92. Answers with JSON and deletes itself.
 * Version: 1.0.0
 * Author: Ai Brick AB
 *
 * 1.0.0 (2026-11-27, B): Johan 14:24 "kör" på planen för /insikter/.
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

add_action( 'init', static function () {
	if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}
	if ( ! isset( $_GET['fs_os'] ) || 'insikter' !== $_GET['fs_os'] ) {
		return;
	}
	global $wpdb;
	$up  = wp_get_upload_dir();
	$src = trailingslashit( $up['basedir'] ) . 'fastsan-content/insikter/insikter.txt';
	$log = array( 'time' => gmdate( 'c' ) );
	if ( ! file_exists( $src ) ) {
		$log['draft'] = 'missing source';
		wp_send_json( $log, 500 );
	}
	$content = (string) file_get_contents( $src );
	$pid     = (int) get_option( 'fastsan_insikter_utkast_id' );
	$post    = $pid ? get_post( $pid ) : null;
	if ( ! $post || 'page' !== $post->post_type || 'draft' !== $post->post_status || 92 === $pid ) {
		$pid = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_author'  => 1,
			'post_title'   => "Insikter (utkast)",
			'post_name'    => 'insikter-utkast',
			'post_content' => '',
		), true );
		if ( is_wp_error( $pid ) ) {
			$log['draft'] = 'error: ' . $pid->get_error_message();
			wp_send_json( $log, 500 );
		}
		update_option( 'fastsan_insikter_utkast_id', (int) $pid, false );
		update_post_meta( $pid, '_fastsan_utkast_preview', '1' );
		update_post_meta( $pid, '_wp_page_template', 'service-page' );
	}
	// Direct database write: byte-exact, no wp_unslash and no kses (BS-08, BS-09).
	$wpdb->update(
		$wpdb->posts,
		array(
			'post_content'      => $content,
			'post_modified'     => current_time( 'mysql' ),
			'post_modified_gmt' => current_time( 'mysql', 1 ),
		),
		array( 'ID' => $pid )
	);
	clean_post_cache( $pid );
	$log['draft'] = array(
		'id'     => (int) $pid,
		'bytes'  => strlen( $content ),
		'md5'    => md5( $content ),
		'status' => get_post_status( $pid ),
	);
	error_log( '[AIB] __fs-insikter-import: ' . wp_json_encode( $log ) );
	@unlink( __FILE__ );
	wp_send_json( $log );
}, 99 );
