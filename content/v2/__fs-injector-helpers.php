<?php
/**
 * FS Content Injector helpers (v1.5)
 * Loaded by __fs-injector.php via require_once. Do not load directly.
 */

if (!defined('ABSPATH')) return;
if (defined('__FS_INJECTOR_HELPERS_LOADED')) return;
define('__FS_INJECTOR_HELPERS_LOADED', true);

function __fs_write_meta($pid, $meta) {
    if (empty($meta)) return;
    if (!empty($meta['title']))         update_post_meta($pid, 'rank_math_title',         sanitize_text_field($meta['title']));
    if (!empty($meta['description']))   update_post_meta($pid, 'rank_math_description',   sanitize_text_field($meta['description']));
    if (!empty($meta['focus_keyword'])) update_post_meta($pid, 'rank_math_focus_keyword', sanitize_text_field($meta['focus_keyword']));
    if (!empty($meta['og_image']))      update_post_meta($pid, 'rank_math_facebook_image', esc_url_raw($meta['og_image']));
    if (!empty($meta['og_image']))      update_post_meta($pid, 'rank_math_twitter_image',  esc_url_raw($meta['og_image']));
}

function __fs_set_featured_image($pid, $meta) {
    if (empty($meta['featured_image_url'])) return;
    if (get_post_thumbnail_id($pid)) return;

    $url = esc_url_raw($meta['featured_image_url']);

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url($url, 30);
    if (is_wp_error($tmp)) { error_log('[FS-INJ] featured_image download failed: ' . $tmp->get_error_message()); return; }

    $file_array = [
        'name'     => basename(parse_url($url, PHP_URL_PATH)),
        'tmp_name' => $tmp,
    ];
    $attachment_id = media_handle_sideload($file_array, $pid);
    @unlink($tmp);

    if (is_wp_error($attachment_id)) { error_log('[FS-INJ] featured_image sideload failed: ' . $attachment_id->get_error_message()); return; }

    set_post_thumbnail($pid, $attachment_id);
}

function __fs_set_post_category($pid, $meta, $post_type) {
    if ($post_type !== 'post') return;
    if (empty($meta['post_category_slug'])) return;

    $cat_slug = sanitize_title($meta['post_category_slug']);
    $term = get_term_by('slug', $cat_slug, 'category');

    if (!$term) {
        $created = wp_insert_term(ucfirst(str_replace('-', ' ', $cat_slug)), 'category', ['slug' => $cat_slug]);
        if (is_wp_error($created)) return;
        $term_id = (int) $created['term_id'];
    } else {
        $term_id = (int) $term->term_id;
    }

    wp_set_post_terms($pid, [$term_id], 'category', false);
}

function __fs_set_redirect_meta($pid, $meta) {
    if (empty($meta['redirect_to'])) return;
    update_post_meta($pid, '_fs_redirect_to', esc_url_raw($meta['redirect_to']));
}

function __fs_ref_pid() {
    static $pid = null;
    if ($pid !== null) return $pid;
    $ref = get_page_by_path('miljoinventering');
    $pid = $ref ? (int) $ref->ID : 0;
    return $pid;
}

/**
 * Handle redirect_to meta — 301 from virtual parent (e.g. /fukt-mogel/) to canonical URL.
 */
function __fs_handle_redirect_to() {
    if (!is_page() && !is_single()) return;
    $obj = get_queried_object();
    if (!$obj || empty($obj->ID)) return;

    $target = get_post_meta($obj->ID, '_fs_redirect_to', true);
    if (empty($target)) return;

    $current = '/' . ltrim($_SERVER['REQUEST_URI'] ?? '', '/');
    if (strpos($current, rtrim($target, '/')) === 0) return;

    wp_safe_redirect(home_url($target), 301);
    exit;
}
