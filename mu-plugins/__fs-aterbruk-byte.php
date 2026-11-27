<?php
/**
 * Plugin Name: __FS Aterbruk Byte (one-shot)
 * Description: ?fs_os=aterbruk-byte publishes draft post 172 (the reuse guide) with wp_publish_post, so the publish hooks run (IndexNow, sitemap, cache). Refuses unless the post is a draft with the verified content md5. Sets the date to now, removes the preview mark, answers with JSON and deletes itself. Rollback: UPDATE 9nmfXYqF_posts SET post_status='draft' WHERE ID=172.
 * Version: 1.0.0
 * Author: Ai Brick AB
 *
 * 1.0.0 (2026-11-27, B): Johan 12:04 "kör byte".
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

add_action( 'init', static function () {
	if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}
	if ( ! isset( $_GET['fs_os'] ) || 'aterbruk-byte' !== $_GET['fs_os'] ) {
		return;
	}
	global $wpdb;
	$id   = 172;
	$want = 'd919023f8025b1c1b9fbec9b77f4519b';
	$log  = array( 'time' => gmdate( 'c' ) );
	$post = get_post( $id );
	if ( ! $post || 'post' !== $post->post_type ) {
		$log['fel'] = 'inlagget finns inte';
		wp_send_json( $log, 500 );
	}
	$log['fore'] = array( 'status' => $post->post_status, 'md5' => md5( $post->post_content ) );
	if ( 'draft' !== $post->post_status || md5( $post->post_content ) !== $want ) {
		$log['fel'] = 'inte utkast med ratt innehall, inget andrat';
		error_log( '[AIB] __fs-aterbruk-byte: ' . wp_json_encode( $log ) );
		@unlink( __FILE__ );
		wp_send_json( $log, 409 );
	}
	$now = current_time( 'mysql' );
	$wpdb->update( $wpdb->posts, array(
		'post_date'     => $now,
		'post_date_gmt' => get_gmt_from_date( $now ),
	), array( 'ID' => $id ) );
	clean_post_cache( $id );
	wp_publish_post( $id );
	clean_post_cache( $id );
	delete_post_meta( $id, '_fastsan_utkast_preview' );
	$after       = get_post( $id );
	$log['efter'] = array(
		'status' => $after->post_status,
		'datum'  => $after->post_date,
		'md5'    => md5( $after->post_content ),
		'url'    => get_permalink( $id ),
	);
	if ( function_exists( 'litespeed_purge_all' ) ) {
		litespeed_purge_all();
	}
	do_action( 'litespeed_purge_all' );
	error_log( '[AIB] __fs-aterbruk-byte: ' . wp_json_encode( $log ) );
	@unlink( __FILE__ );
	wp_send_json( $log );
}, 99 );
