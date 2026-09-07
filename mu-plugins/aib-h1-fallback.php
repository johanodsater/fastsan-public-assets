<?php
/**
 * Plugin Name: AIB H1 Fallback
 * Description: Generic, site-agnostic. If a singular page/post renders without an <h1>, prepend <h1 class="wp-block-heading aib-h1-fallback">post_title</h1> to the content. Frontend, main query, in-loop only. Content that already carries an <h1> — or a template that already rendered core/post-title as an <h1> — is untouched. Disable: define('AIB_H1_FALLBACK_DISABLED', true) in wp-config.php, or delete the file. No exit/die.
 * Version: 1.1.0
 * Author: Ai Brick AB (C)
 *
 * v1.1.0 (2026-09-07, C): the_content-only check gave a double H1 on single posts, where the template
 * renders core/post-title as an <h1> before the content (measured 2026-09-07: 1/30 URLs, the article).
 * Now a render_block hook marks the template-rendered H1 and the fallback stands down.
 * v1.0.0 (2026-09-06, C): P1-1 b for fastsan.se - 13/29 pages lacked H1 (measured live). Chosen over a template-level wp:post-title because 16 pages carry a richer content-H1 than post_title; the fallback keeps those and only fills the gap.
 */

if (!defined('ABSPATH')) return;
if (defined('AIB_H1_FALLBACK_LOADED')) return;
define('AIB_H1_FALLBACK_LOADED', '1.1.0');

add_filter('render_block', 'aib_h1_fallback_note_title_block', 10, 2);
add_filter('the_content', 'aib_h1_fallback_filter', 5);

function aib_h1_fallback_note_title_block($block_content, $block) {
    if (!empty($block['blockName']) && $block['blockName'] === 'core/post-title' && stripos((string) $block_content, '<h1') !== false) {
        $GLOBALS['aib_h1_fallback_seen'] = true;
    }
    return $block_content;
}

function aib_h1_fallback_filter($content) {
    if (defined('AIB_H1_FALLBACK_DISABLED') && AIB_H1_FALLBACK_DISABLED) return $content;
    if (is_admin() || !is_singular() || !in_the_loop() || !is_main_query()) return $content;
    if (!empty($GLOBALS['aib_h1_fallback_seen'])) return $content;
    if (stripos($content, '<h1') !== false) return $content;
    $title = get_the_title();
    if (!is_string($title) || $title === '') return $content;
    return '<h1 class="wp-block-heading aib-h1-fallback">' . esc_html($title) . '</h1>' . "\n" . $content;
}
