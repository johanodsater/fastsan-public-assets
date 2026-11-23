<?php
/**
 * Plugin Name: __FS Byte steg 6 (one-shot)
 * Description: Reservväg för databasstegen i bytet till ny startsida (plan steg 6, 2026-11-23) när databasbryggan nekas av brandväggen. Körs på ?fs_os=byte&k=<nyckel>&steg=<lage|desc|trash159|klar>. Nyckeln härleds ur aib_deployer_secret och lagras inte. Raderar sig själv på steg=klar.
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
	if ( ! isset( $_GET['fs_os'] ) || 'byte' !== $_GET['fs_os'] ) {
		return;
	}
	$sec = (string) get_option( 'aib_deployer_secret' );
	$got = isset( $_GET['k'] ) ? (string) $_GET['k'] : '';
	if ( '' === $sec || ! hash_equals( substr( hash_hmac( 'sha256', 'fs-os-byte-s1953', $sec ), 0, 32 ), $got ) ) {
		return;
	}

	global $wpdb;
	nocache_headers();
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	do_action( 'litespeed_control_set_nocache', 'fs-os-byte' );

	$ny_md5 = 'd1eb6f52e9c34dd07130e56a41258b7f'; // Nya startsidans innehåll (utkast 159, källan i git).
	$steg   = isset( $_GET['steg'] ) ? (string) $_GET['steg'] : 'lage';
	$out    = array( 'steg' => $steg, 'tid' => gmdate( 'c' ), 'ok' => false );

	$innehall = static function ( $id ) use ( $wpdb ) {
		return $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $id ) );
	};
	$metavarde = static function ( $id, $nyckel ) use ( $wpdb ) {
		return $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id LIMIT 1", $id, $nyckel ) );
	};
	$lage = static function () use ( $wpdb ) {
		$o = array();
		foreach ( array( 5, 60, 159 ) as $id ) {
			$r = $wpdb->get_row( $wpdb->prepare( "SELECT post_status, post_name, post_modified, post_content FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );
			if ( ! $r ) {
				$o[ 'p' . $id ] = null;
				continue;
			}
			$m    = array();
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id", $id ), ARRAY_A );
			foreach ( (array) $rows as $x ) {
				$rad = array( 'meta_id' => (int) $x['meta_id'], 'nyckel' => $x['meta_key'], 'byte' => strlen( $x['meta_value'] ), 'md5' => md5( $x['meta_value'] ) );
				if ( 0 === strpos( $x['meta_key'], 'rank_math_' ) || 0 === strpos( $x['meta_key'], '_wp_' ) ) {
					$rad['varde'] = $x['meta_value'];
				}
				$m[] = $rad;
			}
			$o[ 'p' . $id ] = array(
				'status' => $r['post_status'],
				'namn'   => $r['post_name'],
				'andrad' => $r['post_modified'],
				'byte'   => strlen( $r['post_content'] ),
				'md5'    => md5( $r['post_content'] ),
				'meta'   => $m,
			);
		}
		return $o;
	};

	if ( 'lage' === $steg ) {
		$out['ok']           = true;
		$out['p60_innehall'] = $innehall( 60 );
	} elseif ( 'desc' === $steg ) {
		// Ny metabeskrivning på sida 5: kopieras från utkastet, rad till rad i databasen.
		$ny    = $metavarde( 159, 'rank_math_description' );
		$nu    = $metavarde( 5, 'rank_math_description' );
		$kopia = $metavarde( 5, '_aib_backup_s1915_p5_desc' );
		if ( null === $ny || false === strpos( $ny, '2 495 kr' ) ) {
			$out['fel'] = 'beskrivningen pa 159 saknas eller har inget pris';
		} elseif ( null === $kopia ) {
			$out['fel'] = 'kopian _aib_backup_s1915_p5_desc saknas';
		} elseif ( null === $nu ) {
			$out['fel'] = 'sida 5 har ingen rank_math_description att byta';
		} else {
			$out['fore_md5'] = md5( $nu );
			if ( $nu !== $ny ) {
				$out['rader'] = $wpdb->update( $wpdb->postmeta, array( 'meta_value' => $ny ), array( 'post_id' => 5, 'meta_key' => 'rank_math_description' ) );
				wp_cache_delete( 5, 'post_meta' );
			}
			$efter            = $metavarde( 5, 'rank_math_description' );
			$out['efter_md5'] = md5( (string) $efter );
			$out['ok']        = ( $efter === $ny );
		}
	} elseif ( 'trash159' === $steg ) {
		// Utkastet till papperskorgen, byte-exakt: samma fält som wp_trash_post sätter, utan wp_update_post (kses, BS-08).
		$p159 = $wpdb->get_row( "SELECT post_status, post_name FROM {$wpdb->posts} WHERE ID = 159", ARRAY_A );
		if ( md5( (string) $innehall( 5 ) ) !== $ny_md5 ) {
			$out['fel'] = 'sida 5 har inte nya innehallet';
		} elseif ( ! $p159 ) {
			$out['fel'] = 'sida 159 finns inte';
		} elseif ( 'trash' === $p159['post_status'] ) {
			$out['ok']   = true;
			$out['not']  = 'lag redan i papperskorgen';
		} elseif ( 'draft' !== $p159['post_status'] ) {
			$out['fel'] = 'sida 159 har status ' . $p159['post_status'];
		} else {
			$wpdb->insert( $wpdb->postmeta, array( 'post_id' => 159, 'meta_key' => '_wp_trash_meta_status', 'meta_value' => 'draft' ) );
			$wpdb->insert( $wpdb->postmeta, array( 'post_id' => 159, 'meta_key' => '_wp_trash_meta_time', 'meta_value' => (string) time() ) );
			$wpdb->insert( $wpdb->postmeta, array( 'post_id' => 159, 'meta_key' => '_wp_desired_post_slug', 'meta_value' => $p159['post_name'] ) );
			$out['rader'] = $wpdb->update( $wpdb->posts, array( 'post_status' => 'trash', 'post_name' => $p159['post_name'] . '__trashed' ), array( 'ID' => 159 ) );
			clean_post_cache( 159 );
			$out['ok'] = ( 1 === $out['rader'] );
		}
	} elseif ( 'klar' === $steg ) {
		@unlink( __FILE__ );
		clearstatcache();
		$out['ok'] = ! file_exists( __FILE__ );
	} else {
		$out['fel'] = 'okant steg';
	}

	$out['lage'] = $lage();
	error_log( '[AIB] __fs-os-byte: steg=' . $steg . ' ok=' . ( $out['ok'] ? '1' : '0' ) . ( isset( $out['fel'] ) ? ' fel=' . $out['fel'] : '' ) );
	wp_send_json( $out );
}, 99 );
