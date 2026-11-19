<?php
/**
 * Plugin Name: __FS Startsida Import (one-shot)
 * Description: Registrerar startsidans bilder som bilagor och skapar eller uppdaterar utkastsidan. Körs på ?fs_os=startsida och raderar sig själv.
 * Version: 1.0.1
 * Author: Ai Brick AB
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

add_action( 'init', static function () {
	if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}
	if ( ! isset( $_GET['fs_os'] ) || 'startsida' !== $_GET['fs_os'] ) {
		return;
	}

	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$up   = wp_get_upload_dir();
	$base = trailingslashit( $up['basedir'] );
	$log  = array( 'time' => gmdate( 'c' ), 'media' => array(), 'draft' => null );

	$imgs = array(
		'fastsan-miljokonsult-daniel-stalbrand-faltbesok.webp' => 'Daniel Stålbrand, miljökonsult på Fastsan, står utomhus vid en fastighet med en uppslagen rapportpärm',
		'fastsan-daniel-stalbrand-portratt.webp'               => 'Porträtt av Daniel Stålbrand',
		'fastsan-materialprov-packning-provpase.webp'          => 'Hand håller en provpåse märkt P2 Packning framför en rörfläns',
		'fastsan-provtagning-ventilationskanal.webp'           => 'Hand håller en märkt provpåse med materialprov framför en öppnad ventilationskanal',
		'fastsan-fuktmatning-horn.webp'                        => 'Fuktmätare med mätspetsen mot golvet i ett hörn, displayen visar 98,0',
	);

	$media = get_option( 'fastsan_startsida_media', array() );
	if ( ! is_array( $media ) ) {
		$media = array();
	}

	foreach ( $imgs as $file => $alt ) {
		$rel = 'fastsan-content/img/' . $file;
		$abs = $base . $rel;
		if ( ! file_exists( $abs ) ) {
			$log['media'][ $file ] = 'missing';
			continue;
		}
		$id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
			$rel
		) );
		if ( ! $id ) {
			$id = wp_insert_attachment( array(
				'post_mime_type' => 'image/webp',
				'post_title'     => $alt,
				'post_content'   => '',
				'post_status'    => 'inherit',
			), $abs, 0, true );
			if ( is_wp_error( $id ) ) {
				$log['media'][ $file ] = 'error: ' . $id->get_error_message();
				continue;
			}
		}
		$meta = wp_generate_attachment_metadata( $id, $abs );
		wp_update_attachment_metadata( $id, $meta );
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		$media[ $file ] = array(
			'id'    => (int) $id,
			'url'   => wp_get_attachment_url( $id ),
			'w'     => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'h'     => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			'sizes' => isset( $meta['sizes'] ) ? array_keys( $meta['sizes'] ) : array(),
		);
		$log['media'][ $file ] = 'ok ' . (int) $id;
	}
	update_option( 'fastsan_startsida_media', $media, false );

	// Underkatalog och .txt: injektorn (__fs-injector.php) läser alla *.html direkt i fastsan-content/ och skulle annars skapa en sida av filen.
	$src = $base . 'fastsan-content/startsida/hem-ny.txt';
	if ( file_exists( $src ) ) {
		$content = (string) file_get_contents( $src );
		$pid     = (int) get_option( 'fastsan_startsida_utkast_id' );
		if ( ! $pid || ! get_post( $pid ) ) {
			$pid = wp_insert_post( array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Hem – ny startsida (utkast)',
				'post_name'    => 'hem-ny-utkast',
				'post_content' => '',
			), true );
			if ( is_wp_error( $pid ) ) {
				$log['draft'] = 'error: ' . $pid->get_error_message();
				$pid          = 0;
			} else {
				update_option( 'fastsan_startsida_utkast_id', (int) $pid, false );
				update_post_meta( $pid, '_wp_page_template', 'front-page' );
			}
		}
		if ( $pid ) {
			// Direkt databasskrivning: byte-exakt, utan wp_unslash och utan kses (BS-08, BS-09).
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

	update_option( 'fastsan_startsida_import_log', $log, false );
	error_log( '[AIB] __fs-startsida-import: ' . wp_json_encode( $log ) );
	@unlink( __FILE__ );
}, 99 );
