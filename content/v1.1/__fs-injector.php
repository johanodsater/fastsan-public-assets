<?php
/**
 * Plugin Name: FS Content Injector v1.2
 * Description: Reads uploads/fastsan-content/{slug}.html|.meta.json and creates/updates pages via direct $wpdb->insert/update (bypasses FSE template validation per Quirk 3). Calls aib_sanitize_block_content if available. Idempotent per slug-flag. Supports ?fs_force=all to clear all flags.
 * Version: 1.2.0
 *
 * Quirk 3 workaround: wp_insert_post() and wp_update_post() both fail with "Ogiltig sidmall"
 * on FSE themes because get_page_templates() only returns classic PHP templates, not
 * templates/*.html block-templates. We bypass validation by writing directly to wp_posts
 * with $wpdb. Block-sanitizer hook (wp_insert_post_data) does NOT fire — manual call required.
 */

if (!defined('ABSPATH')) return;
if (defined('__FS_INJECTOR_LOADED')) return;
define('__FS_INJECTOR_LOADED', true);

add_action('init', '__fs_inject_pages', 999);

function __fs_inject_pages() {
    global $wpdb;
    $content_dir = WP_CONTENT_DIR . '/uploads/fastsan-content';
    if (!is_dir($content_dir)) { error_log('[FS-INJ] Dir missing'); return; }

    // 13 slugs total: 11 from v1.1 + forvarvsbesiktning + luktutredning (new in 1.2)
    $slugs = [
        'hem','miljoinventering','provtagning','markmiljo','inomhusmiljo','pcb','radon',
        'forvarvsbesiktning','luktutredning',
        'akut','om','kontakt','integritetspolicy'
    ];

    // Handle ?fs_force=all — clears all v1 and v2 idempotency flags
    if (isset($_GET['fs_force']) && $_GET['fs_force'] === 'all') {
        foreach ($slugs as $s) {
            delete_option('__fs_inj_' . $s . '_v1');
            delete_option('__fs_inj_' . $s . '_v2');
        }
        error_log('[FS-INJ] fs_force=all: flags cleared');
    }

    $log = ['[' . date('c') . '] v1.2.0 run'];
    $ok_update = 0; $ok_insert = 0; $skip = 0; $fail = 0;

    foreach ($slugs as $slug) {
        $flag = '__fs_inj_' . $slug . '_v2';
        if (get_option($flag, false)) { $log[] = "SKIP $slug"; $skip++; continue; }

        $hf = $content_dir . '/' . $slug . '.html';
        $mf = $content_dir . '/' . $slug . '.meta.json';
        if (!file_exists($hf)) { $log[] = "PEND $slug"; continue; }

        $c = file_get_contents($hf);
        if ($c === false || strlen($c) < 100) { $log[] = "FAIL $slug (empty)"; $fail++; continue; }

        // Manual sanitizer call — wp_insert_post_data hook does NOT fire on direct $wpdb writes
        if (function_exists('aib_sanitize_block_content')) {
            $c = aib_sanitize_block_content($c);
        }

        // Read meta if present
        $meta = [];
        if (file_exists($mf)) {
            $m = json_decode(file_get_contents($mf), true);
            if (is_array($m)) $meta = $m;
        }

        $page = get_page_by_path($slug);

        if ($page) {
            // UPDATE flow — existing v1.1 logic
            $nl = current_time('mysql'); $ng = current_time('mysql', 1);
            $r = $wpdb->update($wpdb->posts,
                ['post_content' => $c, 'post_modified' => $nl, 'post_modified_gmt' => $ng],
                ['ID' => $page->ID], ['%s','%s','%s'], ['%d']);

            if ($r === false) { $log[] = "FAIL $slug update (db: " . $wpdb->last_error . ')'; $fail++; continue; }

            clean_post_cache($page->ID);
            __fs_write_meta($page->ID, $meta);

            update_option($flag, ['ts' => time(), 'pid' => $page->ID, 'bytes' => strlen($c), 'op' => 'update']);
            $log[] = "UPD $slug (#{$page->ID}, " . strlen($c) . "b)";
            $ok_update++;
        } else {
            // INSERT flow — Quirk 3 workaround: $wpdb->insert direct, bypass wp_insert_post validation
            $title = !empty($meta['title']) ? $meta['title'] : ucfirst($slug);
            // Strip Rank Math title suffix " | Fastsan" for post_title (which is shown in admin)
            $post_title = preg_replace('/\s*\|\s*Fastsan.*$/u', '', $title);

            $nl = current_time('mysql'); $ng = current_time('mysql', 1);
            $insert_r = $wpdb->insert($wpdb->posts, [
                'post_author'           => 1,
                'post_date'             => $nl,
                'post_date_gmt'         => $ng,
                'post_content'          => $c,
                'post_title'            => $post_title,
                'post_status'           => 'publish',
                'comment_status'        => 'closed',
                'ping_status'           => 'closed',
                'post_password'         => '',
                'post_name'             => $slug,
                'to_ping'               => '',
                'pinged'                => '',
                'post_modified'         => $nl,
                'post_modified_gmt'     => $ng,
                'post_content_filtered' => '',
                'post_parent'           => 0,
                'guid'                  => home_url('/?page_id=pending'),
                'menu_order'            => 0,
                'post_type'             => 'page',
                'post_mime_type'        => '',
                'comment_count'         => 0,
            ], [
                '%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%d','%s','%s','%d'
            ]);

            if ($insert_r === false) { $log[] = "FAIL $slug insert (db: " . $wpdb->last_error . ')'; $fail++; continue; }

            $new_id = (int) $wpdb->insert_id;
            if (!$new_id) { $log[] = "FAIL $slug insert (no id)"; $fail++; continue; }

            // Fix guid post-insert (needed for canonical URL)
            $wpdb->update($wpdb->posts, ['guid' => home_url('/?page_id=' . $new_id)], ['ID' => $new_id], ['%s'], ['%d']);

            clean_post_cache($new_id);

            // Inherit page template from a reference service-page (miljoinventering) so FSE picks the same template
            $ref_template = get_post_meta(__fs_ref_pid(), '_wp_page_template', true);
            if ($ref_template) {
                update_post_meta($new_id, '_wp_page_template', $ref_template);
            }

            __fs_write_meta($new_id, $meta);

            update_option($flag, ['ts' => time(), 'pid' => $new_id, 'bytes' => strlen($c), 'op' => 'insert']);
            $log[] = "INS $slug (#$new_id, " . strlen($c) . "b)";
            $ok_insert++;
        }
    }

    $log[] = "Summary: upd=$ok_update ins=$ok_insert skip=$skip fail=$fail";
    @file_put_contents($content_dir . '/injection.log', implode("\n", $log) . "\n", FILE_APPEND);
}

function __fs_write_meta($pid, $meta) {
    if (empty($meta)) return;
    if (!empty($meta['title']))         update_post_meta($pid, 'rank_math_title',         sanitize_text_field($meta['title']));
    if (!empty($meta['description']))   update_post_meta($pid, 'rank_math_description',   sanitize_text_field($meta['description']));
    if (!empty($meta['focus_keyword'])) update_post_meta($pid, 'rank_math_focus_keyword', sanitize_text_field($meta['focus_keyword']));
}

function __fs_ref_pid() {
    static $pid = null;
    if ($pid !== null) return $pid;
    $ref = get_page_by_path('miljoinventering');
    $pid = $ref ? (int) $ref->ID : 0;
    return $pid;
}
