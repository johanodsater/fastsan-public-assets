<?php
/**
 * Plugin Name: Fastsan Legacy Redirects
 * Description: 301/410 map for the pre-cutover fastsan.se URL space (/sv/tjanster/... sanering-era pages). Google still indexes these; they returned 404, which discards the link equity and keeps the old "saneringsfirma" identity alive. Rank Math's Redirections module is not active on this install, so the map lives here.
 * Version: 1.1.1
 * Author: Ai Brick AB (A)
 *
 * Source: RELA B fastsan-analys-atgardsplan 2026-08-20, wave 1.1.
 * 1.1.1 (C, 2026-09-06): GSC Coverage-genomgång — /sv/tjanster/vattenskada/ och /sv/sanering/vattenskada/ gick via den generiska regeln i två hopp (/tjanster/vattenskada/ -> /fukt-mogel/vattenskada/); nu explicit ett hopp. Google hade redan valt /fukt-mogel/vattenskada/ som kanonisk.
 * 1.1.0 (C, 2026-09-06): P2-3 — permalink_structure /sv/%postname%/ -> /%postname%/, category_base -> kategori. Generic safety net after the explicit map: /sv/category/X/ -> /kategori/X/, then any other /sv/X -> /X (301). Explicit 301/410 entries always win.
 * 1.0.1 (C, 2026-09-05): added /sv/sanering/* and /sv/fastighetsunderhall/* entries — the URL set hitta.se still links to.
 * Rollback: file_move to .off (Quirk 7).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( defined( 'FASTSAN_LEGACY_REDIRECTS_LOADED' ) ) {
	return;
}
define( 'FASTSAN_LEGACY_REDIRECTS_LOADED', true );

function fastsan_legacy_redirect_map() {
	return array(
		// 301 — relevant successor exists.
		'/sv/'                                => '/',
		'/sv/tjanster/asbestsanering/'        => '/provtagning/asbest/',
		'/sv/tjanster/mogelsanering/'         => '/fukt-mogel/mogel/',
		'/sv/tjanster/luktsanering/'          => '/luktutredning/',
		'/sv/tjanster/rivning/'               => '/miljoinventering/',
		'/sv/tjanster/aterbruksinventering/'  => '/miljoinventering/',
		'/sv/om-foretaget/'                   => '/om/',
		'/sv/tjanster/sanering/'              => '/tjanster/',
		'/sv/tjanster/asbest/'                => '/provtagning/asbest/',
		'/sv/tjanster/mogel/'                 => '/fukt-mogel/mogel/',
		'/sv/tjanster/fukt/'                  => '/fukt-mogel/fukt/',
		'/sv/tjanster/radon/'                 => '/radon/',
		'/sv/tjanster/pcb/'                   => '/pcb/',
		'/sv/tjanster/vattenskada/'           => '/fukt-mogel/vattenskada/',
		// 1.0.1 — /sv/sanering/* (linked from hitta.se and other directories).
		'/sv/sanering/'                       => '/tjanster/',
		'/sv/sanering/asbestsanering/'        => '/provtagning/asbest/',
		'/sv/sanering/mogelsanering/'         => '/fukt-mogel/mogel/',
		'/sv/sanering/rivning/'               => '/miljoinventering/',
		'/sv/sanering/luktsanering/'          => '/luktutredning/',
		'/sv/sanering/vattenskada/'           => '/fukt-mogel/vattenskada/',
		// 410 — discontinued, no successor. Signals permanent removal to Google.
		'/sv/tjanster/rattsanering/'          => 410,
		'/sv/fastighetsunderhall/'            => 410,
		'/sv/tjanster/fastighetsunderhall/'   => 410,
		'/sv/tjanster/snoskottning/'          => 410,
		'/sv/tjanster/takarbeten/'            => 410,
		// 1.0.1 — /sv/fastighetsunderhall/* children (hitta.se links).
		'/sv/fastighetsunderhall/takarbeten/' => 410,
		'/sv/fastighetsunderhall/grovsopor/'  => 410,
		'/sv/sanering/rattsanering/'          => 410,
	);
}

add_action( 'template_redirect', static function () {
	if ( is_admin() ) {
		return;
	}
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
	$path = strtok( $uri, '?' );
	if ( ! is_string( $path ) || '' === $path ) {
		return;
	}
	// Normalise: lowercase, ensure single leading and trailing slash.
	$path = strtolower( $path );
	if ( '/' !== substr( $path, -1 ) ) {
		$path .= '/';
	}

	$map = fastsan_legacy_redirect_map();
	if ( isset( $map[ $path ] ) ) {
		$target = $map[ $path ];
	} elseif ( 0 === strpos( $path, '/sv/category/' ) ) {
		// 1.1.0 — old category archive base under /sv/ -> /kategori/.
		$target = '/kategori/' . substr( $path, strlen( '/sv/category/' ) );
	} elseif ( 0 === strpos( $path, '/sv/' ) ) {
		// 1.1.0 — generic: strip the legacy language prefix.
		$target = substr( $path, 3 );
	} else {
		return;
	}

	if ( 410 === $target ) {
		status_header( 410 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="sv"><head><meta charset="utf-8">';
		echo '<meta name="robots" content="noindex">';
		echo '<title>Tjänsten är avvecklad – Fastsan AB</title></head><body>';
		echo '<h1>Den här tjänsten erbjuds inte längre</h1>';
		echo '<p>Fastsan arbetar i dag uteslutande med oberoende provtagning, inventering och utredning. Vi utför ingen sanering och inget fastighetsunderhåll.</p>';
		echo '<p><a href="' . esc_url( home_url( '/tjanster/' ) ) . '">Se våra nuvarande tjänster</a></p>';
		echo '</body></html>';
		exit;
	}

	wp_redirect( home_url( $target ), 301 );
	exit;
}, 1 );
