<?php
/**
 * Plugin Name: __FS Kompetens Import (one-shot)
 * Description: Creates or updates the draft page /kompetens/ from uploads/fastsan-content/kompetens/kompetens.txt. Runs on ?fs_os=kompetens, answers with JSON and deletes itself.
 * Version: 1.0.0
 * Author: Ai Brick AB
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

add_action( 'init', static function () {
	if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}
	if ( ! isset( $_GET['fs_os'] ) || 'kompetens' !== $_GET['fs_os'] ) {
		return;
	}

	global $wpdb;
	$up  = wp_get_upload_dir();
	$src = trailingslashit( $up['basedir'] ) . 'fastsan-content/kompetens/kompetens.txt';
	$log = array( 'time' => gmdate( 'c' ), 'draft' => null );

	if ( ! file_exists( $src ) ) {
		$log['draft'] = 'missing source';
	} else {
		$content = (string) file_get_contents( $src );
		$pid     = (int) get_option( 'fastsan_kompetens_utkast_id' );
		$post    = $pid ? get_post( $pid ) : null;
		if ( ! $post || 'page' !== $post->post_type || 'draft' !== $post->post_status ) {
			// Never touch a published page that already owns the slug.
			$taken = get_page_by_path( 'kompetens', OBJECT, 'page' );
			if ( $taken && 'publish' === $taken->post_status ) {
				$log['draft'] = 'slug kompetens already published as ' . (int) $taken->ID;
				$pid          = 0;
			} else {
				$pid = wp_insert_post( array(
					'post_type'    => 'page',
					'post_status'  => 'draft',
					'post_title'   => 'Utbildning och certifiering',
					'post_name'    => 'kompetens',
					'post_content' => '',
				), true );
				if ( is_wp_error( $pid ) ) {
					$log['draft'] = 'error: ' . $pid->get_error_message();
					$pid          = 0;
				} else {
					update_option( 'fastsan_kompetens_utkast_id', (int) $pid, false );
					update_post_meta( $pid, '_fastsan_utkast_preview', '1' );
				}
			}
		}
		if ( $pid ) {
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
			$log['draft'] = array( 'id' => (int) $pid, 'bytes' => strlen( $content ), 'md5' => md5( $content ) );
		}
	}

	update_option( 'fastsan_kompetens_import_log', $log, false );
	error_log( '[AIB] __fs-kompetens-import: ' . wp_json_encode( $log ) );
	@unlink( __FILE__ );
	wp_send_json( $log );
}, 99 );
