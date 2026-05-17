<?php
/**
 * Plugin Name: FS Content Injector v1.5
 * Description: Hierarchical content injector with topological discovery order (Kahn's algorithm). v1.5 splits helpers to __fs-injector-helpers.php for bridge-write compatibility. Discovers {filename}.html + {filename}.meta.json in uploads/fastsan-content/. Filename = DISCOVERY KEY (default slug); meta.slug_override changes post_name. Direct $wpdb->insert/update bypasses FSE template validation per Quirk 3. Flag-tier v5 (auto-migrates from v2/v3/v4).
 * Version: 1.5.0
 *
 * v1.5.0 changelog:
 *   - Topological discovery order via Kahn's algorithm (resolves alphabetic race where children processed before parents → parent_id=0)
 *   - Flag-tier bumped v4 → v5, v4 added to auto-migration tiers
 *   - Helpers moved to __fs-injector-helpers.php (loaded via require_once)
 *   - Logs sort-order for diagnostics
 */

if (!defined('ABSPATH')) return;
if (defined('__FS_INJECTOR_LOADED')) return;
define('__FS_INJECTOR_LOADED', true);
define('__FS_INJECTOR_VERSION', '1.5.0');
define('__FS_INJECTOR_FLAG_TIER', 'v5');

// Helpers required for inject loop. Fail loud if missing.
$__fs_helpers = __DIR__ . '/__fs-injector-helpers.php';
if (!file_exists($__fs_helpers)) {
    error_log('[FS-INJ] FATAL: helpers file missing at ' . $__fs_helpers);
    return;
}
require_once $__fs_helpers;

add_action('init', '__fs_inject_pages', 999);
add_action('template_redirect', '__fs_handle_redirect_to', 1);

function __fs_inject_pages() {
    global $wpdb;
    $content_dir = WP_CONTENT_DIR . '/uploads/fastsan-content';
    if (!is_dir($content_dir)) { error_log('[FS-INJ] Dir missing'); return; }

    $html_files = glob($content_dir . '/*.html') ?: [];
    $raw_slugs = array_map(function ($p) {
        return basename($p, '.html');
    }, $html_files);
    sort($raw_slugs);

    // --- Topological sort via Kahn's algorithm ---
    $slug_meta_cache = [];
    $exposed_to_disc = [];
    $parent_of       = [];

    foreach ($raw_slugs as $slug) {
        $mf = $content_dir . '/' . $slug . '.meta.json';
        $meta = [];
        if (file_exists($mf)) {
            $m = json_decode(@file_get_contents($mf), true);
            if (is_array($m)) $meta = $m;
        }
        $slug_meta_cache[$slug] = $meta;
        $exposed = !empty($meta['slug_override']) ? sanitize_title($meta['slug_override']) : $slug;
        $exposed_to_disc[$exposed] = $slug;
        $parent_of[$slug] = !empty($meta['parent_slug']) ? sanitize_title($meta['parent_slug']) : null;
    }

    $all_slugs = [];
    $processed = [];
    $pending   = $raw_slugs;
    $max_iter  = count($raw_slugs) + 2;

    while (count($pending) > 0 && $max_iter-- > 0) {
        $progress = false;
        foreach ($pending as $idx => $slug) {
            $p_slug = $parent_of[$slug];
            $ready  = false;
            if ($p_slug === null) {
                $ready = true;
            } elseif (isset($exposed_to_disc[$p_slug])) {
                $p_disc = $exposed_to_disc[$p_slug];
                if (isset($processed[$p_disc])) {
                    $ready = true;
                }
            } else {
                $ready = true; // parent not in batch → assumed DB-resident
            }
            if ($ready) {
                $all_slugs[]        = $slug;
                $processed[$slug]   = true;
                unset($pending[$idx]);
                $progress = true;
            }
        }
        if (!$progress) break;
    }
    foreach ($pending as $slug) {
        $all_slugs[] = $slug;
    }

    if (isset($_GET['fs_force'])) {
        $force_param = sanitize_text_field($_GET['fs_force']);
        $force_slugs = ($force_param === 'all') ? $all_slugs : array_map('trim', explode(',', $force_param));
        $tiers = ['v1', 'v2', 'v3', 'v4', 'v5'];
        foreach ($force_slugs as $s) {
            foreach ($tiers as $t) {
                delete_option('__fs_inj_' . $s . '_' . $t);
            }
        }
        error_log('[FS-INJ] fs_force=' . $force_param . ': flags cleared for ' . count($force_slugs) . ' slugs');
    }

    $log = [
        '[' . date('c') . '] v' . __FS_INJECTOR_VERSION . ' run (' . count($all_slugs) . ' candidates)',
        'topo-order: ' . implode(' -> ', $all_slugs) . (count($pending) > 0 ? ' [CYCLE: ' . implode(',', $pending) . ']' : ''),
    ];
    $ok_update = 0; $ok_insert = 0; $skip = 0; $fail = 0;

    foreach ($all_slugs as $slug) {
        $flag = '__fs_inj_' . $slug . '_' . __FS_INJECTOR_FLAG_TIER;
        if (get_option($flag, false)) { $log[] = "SKIP $slug"; $skip++; continue; }

        $legacy_tiers = ['v4', 'v3', 'v2', 'v1'];
        $migrated_from = null;
        foreach ($legacy_tiers as $lt) {
            $legacy_flag = '__fs_inj_' . $slug . '_' . $lt;
            $existing_legacy = get_option($legacy_flag, false);
            if ($existing_legacy) {
                $migrated = is_array($existing_legacy) ? $existing_legacy : ['ts' => time()];
                $migrated['migrated_from'] = $lt;
                $migrated['migration_ts'] = time();
                update_option($flag, $migrated);
                $migrated_from = $lt;
                break;
            }
        }
        if ($migrated_from) {
            $log[] = "MIG $slug ($migrated_from -> v5, no-reinject)";
            $skip++;
            continue;
        }

        $hf = $content_dir . '/' . $slug . '.html';

        $c = @file_get_contents($hf);
        if ($c === false || strlen($c) < 100) { $log[] = "FAIL $slug (empty/missing)"; $fail++; continue; }

        if (function_exists('aib_sanitize_block_content')) {
            $c = aib_sanitize_block_content($c);
        }

        $meta = isset($slug_meta_cache[$slug]) ? $slug_meta_cache[$slug] : [];

        $post_type   = isset($meta['post_type'])   ? sanitize_key($meta['post_type'])   : 'page';
        $post_status = isset($meta['post_status']) ? sanitize_key($meta['post_status']) : 'publish';
        $parent_id   = 0;

        if ($post_type === 'page' && !empty($meta['parent_slug'])) {
            $parent_page = get_page_by_path(sanitize_title($meta['parent_slug']));
            if ($parent_page) {
                $parent_id = (int) $parent_page->ID;
            } else {
                $log[] = "WARN $slug: parent_slug '{$meta['parent_slug']}' not found, parent_id=0";
            }
        }

        $post_name = !empty($meta['slug_override']) ? sanitize_title($meta['slug_override']) : $slug;
        if ($post_name !== $slug) {
            $log[] = "OVR $slug -> post_name=$post_name";
        }

        if ($post_type === 'post') {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_name=%s AND post_type=%s LIMIT 1",
                $post_name, 'post'
            ));
        } else {
            $existing_p = get_page_by_path($post_name, OBJECT, 'page');
            if ($existing_p && (int) $existing_p->post_parent === $parent_id) {
                $existing = (object) ['ID' => $existing_p->ID];
            } else {
                $existing = null;
            }
        }

        if ($existing) {
            $nl = current_time('mysql'); $ng = current_time('mysql', 1);
            $update_data = [
                'post_content'      => $c,
                'post_modified'     => $nl,
                'post_modified_gmt' => $ng,
            ];
            $update_fmt = ['%s','%s','%s'];

            if (!empty($meta['title'])) {
                $update_data['post_title'] = preg_replace('/\s*\|\s*Fastsan.*$/u', '', $meta['title']);
                $update_fmt[] = '%s';
            }
            if (!empty($meta['post_status'])) {
                $update_data['post_status'] = $post_status;
                $update_fmt[] = '%s';
            }
            if ($post_type === 'page' && isset($meta['parent_slug'])) {
                $update_data['post_parent'] = $parent_id;
                $update_fmt[] = '%d';
            }

            $r = $wpdb->update($wpdb->posts, $update_data, ['ID' => $existing->ID], $update_fmt, ['%d']);

            if ($r === false) { $log[] = "FAIL $slug update (db: " . $wpdb->last_error . ')'; $fail++; continue; }

            clean_post_cache($existing->ID);
            __fs_write_meta($existing->ID, $meta);
            __fs_set_featured_image($existing->ID, $meta);
            __fs_set_post_category($existing->ID, $meta, $post_type);
            __fs_set_redirect_meta($existing->ID, $meta);

            update_option($flag, ['ts' => time(), 'pid' => $existing->ID, 'bytes' => strlen($c), 'op' => 'update', 'type' => $post_type]);
            $log[] = "UPD $slug (#{$existing->ID}, " . strlen($c) . "b, type=$post_type)";
            $ok_update++;
        } else {
            $title = !empty($meta['title']) ? $meta['title'] : ucfirst($slug);
            $post_title = preg_replace('/\s*\|\s*Fastsan.*$/u', '', $title);

            $nl = current_time('mysql'); $ng = current_time('mysql', 1);
            $insert_r = $wpdb->insert($wpdb->posts, [
                'post_author'           => 1,
                'post_date'             => $nl,
                'post_date_gmt'         => $ng,
                'post_content'          => $c,
                'post_title'            => $post_title,
                'post_status'           => $post_status,
                'comment_status'        => 'closed',
                'ping_status'           => 'closed',
                'post_password'         => '',
                'post_name'             => $post_name,
                'to_ping'               => '',
                'pinged'                => '',
                'post_modified'         => $nl,
                'post_modified_gmt'     => $ng,
                'post_content_filtered' => '',
                'post_parent'           => $parent_id,
                'guid'                  => home_url('/?page_id=pending'),
                'menu_order'            => 0,
                'post_type'             => $post_type,
                'post_mime_type'        => '',
                'comment_count'         => 0,
            ], [
                '%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%d','%s','%s','%d'
            ]);

            if ($insert_r === false) { $log[] = "FAIL $slug insert (db: " . $wpdb->last_error . ')'; $fail++; continue; }

            $new_id = (int) $wpdb->insert_id;
            if (!$new_id) { $log[] = "FAIL $slug insert (no id)"; $fail++; continue; }

            $guid_param = ($post_type === 'post') ? '?p=' . $new_id : '?page_id=' . $new_id;
            $wpdb->update($wpdb->posts, ['guid' => home_url('/' . $guid_param)], ['ID' => $new_id], ['%s'], ['%d']);

            clean_post_cache($new_id);

            if ($post_type === 'page') {
                $ref_template = get_post_meta(__fs_ref_pid(), '_wp_page_template', true);
                if ($ref_template) {
                    update_post_meta($new_id, '_wp_page_template', $ref_template);
                }
            }

            __fs_write_meta($new_id, $meta);
            __fs_set_featured_image($new_id, $meta);
            __fs_set_post_category($new_id, $meta, $post_type);
            __fs_set_redirect_meta($new_id, $meta);

            update_option($flag, ['ts' => time(), 'pid' => $new_id, 'bytes' => strlen($c), 'op' => 'insert', 'type' => $post_type]);
            $log[] = "INS $slug (#$new_id, " . strlen($c) . "b, type=$post_type, parent=$parent_id)";
            $ok_insert++;
        }
    }

    if ($ok_insert > 0) {
        flush_rewrite_rules(false);
        $log[] = 'flush_rewrite_rules() called';
    }

    $log[] = "Summary: upd=$ok_update ins=$ok_insert skip=$skip fail=$fail";

    if ($ok_update > 0 || $ok_insert > 0 || $fail > 0) {
        $logfile = $content_dir . '/injection.log';
        if (file_exists($logfile) && filesize($logfile) > 65536) {
            $tail = @file_get_contents($logfile, false, null, -16384);
            @file_put_contents($logfile, '[log rotated ' . date('c') . "]\n" . $tail);
        }
        @file_put_contents($logfile, implode("\n", $log) . "\n", FILE_APPEND);
    }
}
