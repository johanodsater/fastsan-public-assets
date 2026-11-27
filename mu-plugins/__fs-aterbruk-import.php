<?php
/**
 * Plugin Name: __FS Aterbruk Import (one-shot)
 * Description: Insikter guide on reuse. ?fs_os=aterbruk&steg=media registers the guide images as attachments and answers with their ids. ?fs_os=aterbruk&steg=utkast creates or updates the draft post from uploads/fastsan-content/aterbruk/aterbruk.txt, answers with JSON and deletes itself. Never touches a published post.
 * Version: 1.0.0
 * Author: Ai Brick AB
 *
 * 1.0.0 (2026-11-27, B): Johan 10:56 "kör källor + kör ÅB-4".
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

add_action( 'init', static function () {
	if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}
	if ( ! isset( $_GET['fs_os'] ) || 'aterbruk' !== $_GET['fs_os'] ) {
		return;
	}
	$steg = isset( $_GET['steg'] ) ? (string) $_GET['steg'] : '';
	if ( 'media' !== $steg && 'utkast' !== $steg ) {
		wp_send_json( array( 'error' => 'steg' ), 400 );
	}

	global $wpdb;
	$up   = wp_get_upload_dir();
	$base = trailingslashit( $up['basedir'] );
	$log  = array( 'time' => gmdate( 'c' ), 'steg' => $steg );

	if ( 'media' === $steg ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$imgs  = array(
			'fastsan-aterbruk-fonsterkitt-provpase.webp'   => "Provp\xC3\xA5se m\xC3\xA4rkt f\xC3\xB6nsterkitt glaspartier, fasttejpad p\xC3\xA5 ett \xC3\xA4ldre f\xC3\xB6nster",
			'fastsan-aterbruk-asbest-skylt-rivning.webp'   => "Gul varningsskylt med texten Rivning asbest, tilltr\xC3\xA4de f\xC3\xB6rbjudet f\xC3\xB6r obeh\xC3\xB6riga, ovanf\xC3\xB6r en st\xC3\xA4llning i en lokal under rivning",
			'fastsan-aterbruk-fonsterkitt-sprickor.webp'   => "Sprucket och flagnande f\xC3\xB6nsterkitt och f\xC3\xA4rg i h\xC3\xB6rnet av en f\xC3\xB6nsterb\xC3\xA5ge",
			'fastsan-aterbruk-tapetlager.webp'             => "M\xC3\xB6nstrad \xC3\xA4ldre tapet som syns d\xC3\xA4r ett yngre tapetlager har rivits bort",
			'fastsan-aterbruk-takplatta-lim.webp'          => "H\xC3\xB6rn d\xC3\xA4r en platta har tagits bort och limklickar syns p\xC3\xA5 underlaget",
			'fastsan-aterbruk-rorisolering-markerad.webp'  => "R\xC3\xB6risolering under ett bj\xC3\xA4lklag, markerad med r\xC3\xB6d sprayf\xC3\xA4rg",
			'fastsan-aterbruk-cellplast-provpase.webp'     => "Provp\xC3\xA5se med bitar av cellplastisolering, m\xC3\xA4rkt CFC",
			'fastsan-aterbruk-matta-lim-provpase.webp'     => "Provp\xC3\xA5se m\xC3\xA4rkt matta och lim p\xC3\xA5 ett golv d\xC3\xA4r mattan \xC3\xA4r uppriven",
			'fastsan-aterbruk-kakelfix-provpase.webp'      => "Provp\xC3\xA5se m\xC3\xA4rkt kakelfix framf\xC3\xB6r en v\xC3\xA4gg d\xC3\xA4r kakel har tagits bort",
		);
		$media = array();
		foreach ( $imgs as $file => $alt ) {
			$rel = 'fastsan-content/img/' . $file;
			$abs = $base . $rel;
			if ( ! file_exists( $abs ) ) {
				$media[ $file ] = 'missing';
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
					$media[ $file ] = 'error: ' . $id->get_error_message();
					continue;
				}
			}
			$meta = wp_generate_attachment_metadata( $id, $abs );
			wp_update_attachment_metadata( $id, $meta );
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
			$media[ $file ] = array(
				'id'  => (int) $id,
				'url' => wp_get_attachment_url( $id ),
				'w'   => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
				'h'   => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			);
		}
		update_option( 'fastsan_aterbruk_media', $media, false );
		$log['media'] = $media;
		update_option( 'fastsan_aterbruk_import_log', $log, false );
		error_log( '[AIB] __fs-aterbruk-import media: ' . wp_json_encode( $log ) );
		wp_send_json( $log );
	}

	// steg=utkast
	$src = $base . 'fastsan-content/aterbruk/aterbruk.txt';
	if ( ! file_exists( $src ) ) {
		$log['draft'] = 'missing source';
		wp_send_json( $log, 500 );
	}
	$content = (string) file_get_contents( $src );
	$pid     = (int) get_option( 'fastsan_aterbruk_utkast_id' );
	$post    = $pid ? get_post( $pid ) : null;
	if ( ! $post || 'post' !== $post->post_type || 'draft' !== $post->post_status ) {
		$taken = get_page_by_path( 'aterbruk-miljoinventering', OBJECT, 'post' );
		if ( $taken && 'publish' === $taken->post_status ) {
			$log['draft'] = 'slug already published as ' . (int) $taken->ID;
			$pid          = 0;
		} else {
			$pid = wp_insert_post( array(
				'post_type'     => 'post',
				'post_status'   => 'draft',
				'post_author'   => 1,
				'post_title'    => "\xC3\x85terbruk av byggmaterial \xE2\x80\x93 d\xC3\xA4rf\xC3\xB6r ska milj\xC3\xB6inventeringen komma f\xC3\xB6rst",
				'post_name'     => 'aterbruk-miljoinventering',
				'post_content'  => '',
				'post_category' => array( 3 ),
			), true );
			if ( is_wp_error( $pid ) ) {
				$log['draft'] = 'error: ' . $pid->get_error_message();
				$pid          = 0;
			} else {
				update_option( 'fastsan_aterbruk_utkast_id', (int) $pid, false );
				update_post_meta( $pid, '_fastsan_utkast_preview', '1' );
			}
		}
	}
	if ( $pid ) {
		$excerpt = "\xC3\x85terbruk sparar resurser, men gamla byggdelar kan b\xC3\xA4ra asbest, PCB och andra \xC3\xA4mnen som inte f\xC3\xA5r spridas vidare. Vad lagen kr\xC3\xA4ver sedan 1 juli 2026, f\xC3\xA4llorna och varf\xC3\xB6r milj\xC3\xB6inventeringen ska komma f\xC3\xB6rst.";
		// Direct database write: byte-exact, no wp_unslash and no kses (BS-08, BS-09).
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_content'      => $content,
				'post_excerpt'      => $excerpt,
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', 1 ),
			),
			array( 'ID' => $pid )
		);
		clean_post_cache( $pid );
		$media = get_option( 'fastsan_aterbruk_media', array() );
		if ( isset( $media['fastsan-aterbruk-fonsterkitt-provpase.webp']['id'] ) ) {
			set_post_thumbnail( $pid, (int) $media['fastsan-aterbruk-fonsterkitt-provpase.webp']['id'] );
		}
		update_post_meta( $pid, 'rank_math_title', "\xC3\x85terbruk av byggmaterial: d\xC3\xA4rf\xC3\xB6r milj\xC3\xB6inventering f\xC3\xB6rst" );
		update_post_meta( $pid, 'rank_math_description', "Gamla byggdelar kan inneh\xC3\xA5lla asbest, PCB och freoner. Vad lagen kr\xC3\xA4ver sedan 1 juli 2026 och varf\xC3\xB6r milj\xC3\xB6inventeringen ska g\xC3\xB6ras innan n\xC3\xA5got \xC3\xA5terbrukas." );
		update_post_meta( $pid, 'rank_math_focus_keyword', "\xC3\xA5terbruk byggmaterial" );
		$log['draft'] = array(
			'id'      => (int) $pid,
			'bytes'   => strlen( $content ),
			'md5'     => md5( $content ),
			'thumb'   => (int) get_post_thumbnail_id( $pid ),
			'status'  => get_post_status( $pid ),
		);
	}

	update_option( 'fastsan_aterbruk_import_log', $log, false );
	error_log( '[AIB] __fs-aterbruk-import utkast: ' . wp_json_encode( $log ) );
	@unlink( __FILE__ );
	wp_send_json( $log );
}, 99 );
