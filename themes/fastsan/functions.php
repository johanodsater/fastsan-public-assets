<?php
/**
 * Fastsan theme functions.
 *
 * @package Fastsan
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'FASTSAN_THEME_VERSION' ) ) {
	define( 'FASTSAN_THEME_VERSION', '1.3.1' );
}

/**
 * Theme setup.
 */
function fastsan_theme_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'custom-logo', array(
		'height'      => 41,
		'width'       => 285,
		'flex-height' => true,
		'flex-width'  => true,
	) );
	add_theme_support( 'html5', array(
		'search-form',
		'comment-form',
		'comment-list',
		'gallery',
		'caption',
		'style',
		'script',
		'navigation-widgets',
	) );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'editor-styles' );
	add_theme_support( 'wp-block-styles' );
	// Mellanstorlek för bilder som visas 250–400 px breda. Utan den hoppar urvalet från 300 till 768 px.
	add_image_size( 'fastsan-480', 480, 0 );
	load_theme_textdomain( 'fastsan', get_template_directory() . '/languages' );
}
add_action( 'after_setup_theme', 'fastsan_theme_setup' );

/**
 * Typsnitten ligger i temat (assets/fonts, theme.json fontFace). Förladda dem så att texten inte byter utseende sent.
 */
function fastsan_preload_fonts() {
	$dir = get_template_directory_uri() . '/assets/fonts/';
	foreach ( array( 'schibsted-grotesk-latin-wght-normal.woff2', 'inter-latin-wght-normal.woff2' ) as $font ) {
		printf( '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url( $dir . $font ) );
	}
}
add_action( 'wp_head', 'fastsan_preload_fonts', 1 );

/**
 * Versionsstrang for en fil i temat: filens andringstid. Webblasaren far da en ny adress sa fort filen andras
 * (servern later den ligga i cache i sju dagar). Finns filen inte ges temats version.
 */
function fastsan_asset_ver( $rel ) {
	$mtime = @filemtime( get_template_directory() . '/' . ltrim( $rel, '/' ) );
	return $mtime ? (string) $mtime : FASTSAN_THEME_VERSION;
}

/**
 * Enqueue Fastsan canonical CSS-tokens + dropdown nav CSS (FAS 1 v2 IA).
 */
function fastsan_enqueue_styles() {
	$tokens_file = get_template_directory() . '/assets/fastsan-tokens.css';
	if ( file_exists( $tokens_file ) ) {
		wp_enqueue_style(
			'fastsan-tokens',
			get_template_directory_uri() . '/assets/fastsan-tokens.css',
			array(),
			fastsan_asset_ver( 'assets/fastsan-tokens.css' )
		);
	}

	// Dropdown + mobile nav (FAS 1 v2 IA — added 2026-05-16)
	$dropdown_file = get_template_directory() . '/assets/fastsan-dropdown.css';
	if ( file_exists( $dropdown_file ) ) {
		wp_enqueue_style(
			'fastsan-dropdown',
			get_template_directory_uri() . '/assets/fastsan-dropdown.css',
			array( 'fastsan-tokens' ),
			fastsan_asset_ver( 'assets/fastsan-dropdown.css' )
		);
	}

	// Forms: offertformulär + dl-styling (C, 2026-08-21)
	$forms_file = get_template_directory() . '/assets/fastsan-forms.css';
	if ( file_exists( $forms_file ) ) {
		wp_enqueue_style(
			'fastsan-forms',
			get_template_directory_uri() . '/assets/fastsan-forms.css',
			array( 'fastsan-tokens' ),
			fastsan_asset_ver( 'assets/fastsan-forms.css' )
		);
	}

	// Offert-form → lead-pipeline binding (C, 2026-08-21). Självguardad på #offert.
	$offert_js = get_template_directory() . '/assets/fastsan-offert.js';
	if ( file_exists( $offert_js ) ) {
		wp_enqueue_script(
			'fastsan-offert',
			get_template_directory_uri() . '/assets/fastsan-offert.js',
			array(),
			fastsan_asset_ver( 'assets/fastsan-offert.js' ),
			array( 'in_footer' => true, 'strategy' => 'defer' )
		);
	}

	// Startsidans sektioner (mönstren fastsan/*). Laddas bara där mallen Front Page används.
	$startsida_file = get_template_directory() . '/assets/fastsan-startsida.css';
	if ( file_exists( $startsida_file ) && ( is_front_page() || is_page_template( 'front-page' ) ) ) {
		wp_enqueue_style(
			'fastsan-startsida',
			get_template_directory_uri() . '/assets/fastsan-startsida.css',
			array( 'fastsan-tokens' ),
			fastsan_asset_ver( 'assets/fastsan-startsida.css' )
		);
	}

	// Sidan Utbildning och certifiering (/kompetens/). Laddas bara på den sidan (B 2026-11-25).
	if ( is_page( 'kompetens' ) ) {
		wp_enqueue_style(
			'fastsan-kompetens',
			get_template_directory_uri() . '/assets/fastsan-kompetens.css',
			array( 'fastsan-tokens' ),
			fastsan_asset_ver( 'assets/fastsan-kompetens.css' )
		);
	}

	// Sidhuvud, akutrad och sidfot (låg förut som <style> mitt i sidan).
	wp_enqueue_style(
		'fastsan-ram',
		get_template_directory_uri() . '/assets/fastsan-ram.css',
		array( 'fastsan-tokens' ),
		fastsan_asset_ver( 'assets/fastsan-ram.css' )
	);

	// Blogginlägg och arkiv.
	if ( is_singular( 'post' ) || is_home() || is_archive() ) {
		wp_enqueue_style(
			'fastsan-insikter',
			get_template_directory_uri() . '/assets/fastsan-insikter.css',
			array( 'fastsan-tokens' ),
			fastsan_asset_ver( 'assets/fastsan-insikter.css' )
		);
	}

	// Guider under Insikter: ramar, kort och tidslinje (klasserna fs-guide-*). Bara inlägg (B 2026-11-27).
	if ( is_singular( 'post' ) ) {
		wp_enqueue_style(
			'fastsan-guide',
			get_template_directory_uri() . '/assets/fastsan-guide.css',
			array( 'fastsan-insikter' ),
			fastsan_asset_ver( 'assets/fastsan-guide.css' )
		);
	}
}
add_action( 'wp_enqueue_scripts', 'fastsan_enqueue_styles', 20 );

/**
 * Rätt bildstorlek för bilder som visas smalare än WordPress antar.
 * WordPress skriver sizes="(max-width: 963px) 100vw, 963px" (bildens fulla bredd), och webbläsaren hämtar då
 * originalet fast bilden visas 250–400 px bred (mätt 2026-11-23: 963 -> 249, 900 -> 260, 800 -> 302).
 * Här står den bredd bilden faktiskt får, per bild-id i mediabiblioteket. Ny bild i en smal ruta: lägg till en rad.
 */
function fastsan_img_sizes_map() {
	return apply_filters(
		'fastsan_img_sizes_map',
		array(
			154 => '(min-width: 1100px) 250px, (max-width: 480px) 84vw, 420px',  // startsidan, kortet "Din kontakt" (mätt 213–414 px)
			157 => '(max-width: 600px) 45vw, 260px',  // startsidan, "Så går det till"
			111 => '(max-width: 600px) 92vw, 400px',  // /provtagning/asbest/ och /om/
		)
	);
}
function fastsan_img_sizes( $img, $context, $attachment_id ) {
	$map = fastsan_img_sizes_map();
	if ( ! isset( $map[ $attachment_id ] ) || false === strpos( $img, ' srcset=' ) ) {
		return $img;
	}
	$sizes = ' sizes="' . esc_attr( $map[ $attachment_id ] ) . '"';
	if ( preg_match( '/ sizes="[^"]*"/', $img ) ) {
		return preg_replace( '/ sizes="[^"]*"/', $sizes, $img, 1 );
	}
	return preg_replace( '/<img /', '<img' . $sizes . ' ', $img, 1 );
}
add_filter( 'wp_content_img_tag', 'fastsan_img_sizes', 9, 3 );

/**
 * Sajtikoner, temafärg och färgschema i <head>. Ikonerna ligger i temat (assets/favicons).
 * Flyttat hit 2026-11-23 från hjälpfilen mu-plugins/__fs-favicon-deploy.php, som också hade en öppen
 * hämtadress (?fs_favicon_pull=1). Samma fem rader som förut, plus color-scheme.
 */
function fastsan_head_icons() {
	$base = get_template_directory_uri() . '/assets/favicons/';
	echo '<link rel="icon" type="image/x-icon" href="' . esc_url( $base . 'favicon.ico' ) . '" />' . "\n";
	echo '<link rel="icon" type="image/png" sizes="16x16" href="' . esc_url( $base . 'favicon-16.png' ) . '" />' . "\n";
	echo '<link rel="icon" type="image/png" sizes="32x32" href="' . esc_url( $base . 'favicon-32.png' ) . '" />' . "\n";
	echo '<link rel="apple-touch-icon" sizes="180x180" href="' . esc_url( $base . 'apple-touch-icon.png' ) . '" />' . "\n";
	echo '<meta name="theme-color" content="#15324A" />' . "\n";
	echo '<meta name="color-scheme" content="light" />' . "\n";
}
if ( ! file_exists( WPMU_PLUGIN_DIR . '/__fs-favicon-deploy.php' ) ) {
	add_action( 'wp_head', 'fastsan_head_icons', 3 );
}

/**
 * Enqueue samma tokens i block-editorn (så Site Editor / Gutenberg ser samma tokens).
 */
function fastsan_enqueue_editor_styles() {
	add_editor_style( 'assets/fastsan-tokens.css' );
	add_editor_style( 'assets/fastsan-dropdown.css' );
	add_editor_style( 'assets/fastsan-forms.css' );
	add_editor_style( 'assets/fastsan-startsida.css' );
	add_editor_style( 'assets/fastsan-kompetens.css' );
	add_editor_style( 'assets/fastsan-guide.css' );
}
add_action( 'after_setup_theme', 'fastsan_enqueue_editor_styles' );

/**
 * Mönsterkategori för startsidans sektioner (patterns/*.php).
 */
function fastsan_register_pattern_category() {
	register_block_pattern_category( 'fastsan', array( 'label' => 'Fastsan' ) );
}
add_action( 'init', 'fastsan_register_pattern_category' );
