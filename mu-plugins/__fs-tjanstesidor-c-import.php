<?php
/**
 * Plugin Name: __FS Tjanstesidor C Import (one-shot)
 * Description: ?fs_os=tjanstesidor-c creates or updates draft copies of /markmiljo/ and its four subpages from uploads/fastsan-content/tjanstesida/<slug>.txt, marked for the preview link. Never touches the published pages 8, 71, 85, 87, 86. Answers with JSON and deletes itself.
 * Version: 1.0.0
 * Author: Ai Brick AB
 *
 * 1.0.0 (2026-11-27, B): Johan 18:33 "kör grupp C", fas 2 grupp C.
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

add_action( 'init', static function () {
	if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}
	if ( ! isset( $_GET['fs_os'] ) || 'tjanstesidor-c' !== $_GET['fs_os'] ) {
		return;
	}
	global $wpdb;
	$o     = "\xC3\xB6";
	$sidor = array(
		'markmiljo'              => array( 8, 'Markmilj' . $o . ' (utkast)' ),
		'markmiljo_undersokning' => array( 71, 'Markmilj' . $o . 'unders' . $o . 'kning (utkast)' ),
		'markmiljo_pah'          => array( 85, 'PAH-analys (utkast)' ),
		'markmiljo_btex'         => array( 87, 'BTEX-analys (utkast)' ),
		'markmiljo_tungmetaller' => array( 86, 'Tungmetaller i mark (utkast)' ),
	);
	$up  = wp_get_upload_dir();
	$log = array( 'time' => gmdate( 'c' ), 'drafts' => array() );
	$ok  = true;
	foreach ( $sidor as $slug => $info ) {
		$live = (int) $info[0];
		$src  = trailingslashit( $up['basedir'] ) . 'fastsan-content/tjanstesida/' . $slug . '.txt';
		if ( ! file_exists( $src ) ) {
			$log['drafts'][ $slug ] = 'missing source';
			$ok = false;
			continue;
		}
		$content = (string) file_get_contents( $src );
		$opt     = 'fastsan_tjanst_' . $slug . '_utkast_id';
		$pid     = (int) get_option( $opt );
		$post    = $pid ? get_post( $pid ) : null;
		if ( ! $post || 'page' !== $post->post_type || 'draft' !== $post->post_status || $live === $pid ) {
			$pid = wp_insert_post( array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_author'  => 1,
				'post_title'   => $info[1],
				'post_name'    => str_replace( '_', '-', $slug ) . '-utkast',
				'post_content' => '',
			), true );
			if ( is_wp_error( $pid ) ) {
				$log['drafts'][ $slug ] = 'error: ' . $pid->get_error_message();
				$ok = false;
				continue;
			}
			update_option( $opt, (int) $pid, false );
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
		$log['drafts'][ $slug ] = array(
			'id'     => (int) $pid,
			'bytes'  => strlen( $content ),
			'md5'    => md5( $content ),
			'status' => get_post_status( $pid ),
		);
	}
	error_log( '[AIB] __fs-tjanstesidor-c-import: ' . wp_json_encode( $log ) );
	@unlink( __FILE__ );
	wp_send_json( $log, $ok ? 200 : 500 );
}, 99 );
