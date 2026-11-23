<?php
/**
 * Fastsan theme functions.
 *
 * @package Fastsan
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'FASTSAN_THEME_VERSION' ) ) {
	define( 'FASTSAN_THEME_VERSION', '1.1.1' );
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
	add_theme_support( 'elementor' );
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
 * Enqueue Fastsan canonical CSS-tokens + dropdown nav CSS (FAS 1 v2 IA).
 */
function fastsan_enqueue_styles() {
	$tokens_file = get_template_directory() . '/assets/fastsan-tokens.css';
	if ( file_exists( $tokens_file ) ) {
		wp_enqueue_style(
			'fastsan-tokens',
			get_template_directory_uri() . '/assets/fastsan-tokens.css',
			array(),
			FASTSAN_THEME_VERSION
		);
	}

	// Dropdown + mobile nav (FAS 1 v2 IA — added 2026-05-16)
	$dropdown_file = get_template_directory() . '/assets/fastsan-dropdown.css';
	if ( file_exists( $dropdown_file ) ) {
		wp_enqueue_style(
			'fastsan-dropdown',
			get_template_directory_uri() . '/assets/fastsan-dropdown.css',
			array( 'fastsan-tokens' ),
			FASTSAN_THEME_VERSION
		);
	}

	// Forms: offertformulär + dl-styling (C, 2026-08-21)
	$forms_file = get_template_directory() . '/assets/fastsan-forms.css';
	if ( file_exists( $forms_file ) ) {
		wp_enqueue_style(
			'fastsan-forms',
			get_template_directory_uri() . '/assets/fastsan-forms.css',
			array( 'fastsan-tokens' ),
			FASTSAN_THEME_VERSION
		);
	}

	// Offert-form → lead-pipeline binding (C, 2026-08-21). Självguardad på #offert.
	$offert_js = get_template_directory() . '/assets/fastsan-offert.js';
	if ( file_exists( $offert_js ) ) {
		wp_enqueue_script(
			'fastsan-offert',
			get_template_directory_uri() . '/assets/fastsan-offert.js',
			array(),
			FASTSAN_THEME_VERSION,
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
			FASTSAN_THEME_VERSION
		);
	}

	// Elementor overrides — endast om Elementor laddat
	if ( did_action( 'elementor/loaded' ) ) {
		$elementor_file = get_template_directory() . '/assets/elementor-overrides.css';
		if ( file_exists( $elementor_file ) ) {
			wp_enqueue_style(
				'fastsan-elementor-overrides',
				get_template_directory_uri() . '/assets/elementor-overrides.css',
				array( 'fastsan-tokens' ),
				FASTSAN_THEME_VERSION
			);
		}
	}
}
add_action( 'wp_enqueue_scripts', 'fastsan_enqueue_styles', 20 );

/**
 * Enqueue samma tokens i block-editorn (så Site Editor / Gutenberg ser samma tokens).
 */
function fastsan_enqueue_editor_styles() {
	add_editor_style( 'assets/fastsan-tokens.css' );
	add_editor_style( 'assets/fastsan-dropdown.css' );
	add_editor_style( 'assets/fastsan-forms.css' );
	add_editor_style( 'assets/fastsan-startsida.css' );
}
add_action( 'after_setup_theme', 'fastsan_enqueue_editor_styles' );

/**
 * Mönsterkategori för startsidans sektioner (patterns/*.php).
 */
function fastsan_register_pattern_category() {
	register_block_pattern_category( 'fastsan', array( 'label' => 'Fastsan' ) );
}
add_action( 'init', 'fastsan_register_pattern_category' );
