<?php
/**
 * Plugin Name: __fs-qa7-stadning (ENGANGS, tas bort efter korning)
 * Description: QA-planen punkt 7 och 8. GET /?fs_qa7=<nyckel>&steg=<steg>. lage = visar vad som finns. elementor_av = stanger av Elementor. radera = packar hjalpfiler och den avstangda temamappen i en zip utanfor webbroten, raderar sedan Akismet, Elementor, tre standardteman, den avstangda temamappen och hjalpfilerna i mu-plugins. favicon = kopierar temats favicon.ico till webbroten. kommentarer = stanger kommentarer och pingar. klar = raderar den har filen. Nyckeln raknas ut pa servern ur aib_deployer_secret.
 * Version: 1.0.0
 * Author: B (2026-11-23)
 */
if (!defined('ABSPATH')) return;
add_action('init', static function () {
    if (!isset($_GET['fs_qa7'])) return;
    $secret = (string) get_option('aib_deployer_secret', '');
    $key = $secret === '' ? '' : substr(hash_hmac('sha256', 'fs-qa7-s1953', $secret), 0, 32);
    if ($key === '' || !hash_equals($key, (string) $_GET['fs_qa7'])) return;
    nocache_headers();
    do_action('litespeed_control_set_nocache', 'fs-qa7');
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(120);
    global $wpdb;
    $steg = isset($_GET['steg']) ? (string) $_GET['steg'] : '';
    $out = ['steg' => $steg];
    $mu = WPMU_PLUGIN_DIR;
    $mu_files = [
        '__fs-aa-opcache-invalidator.php', '__fs-dump-v2.php', '__fs-psi-bypass.php', '__fs-favicon-deploy.php',
        'aib-elementor-sanitizer.php', '0-kk247-core-loader.php.off', '__fs-content-puller.php.off',
        '__fs-inj-setup.php.off', '__fs-maintenance.php.off', 'fastsan-noindex-staging.php.off',
        'fastsan-og-image-supplement.php.off', 'error_log',
    ];
    $themes_del = ['twentytwentythree', 'twentytwentyfour', 'twentytwentyfive'];
    $theme_off  = get_theme_root() . '/_extendable_disabled-2026-05-10';
    $plugins_del = ['akismet/akismet.php', 'elementor/elementor.php'];
    $lage = static function () use ($wpdb, $mu, $mu_files, $themes_del, $theme_off, $plugins_del) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $o = [];
        $o['aktiva_plugins'] = array_values((array) get_option('active_plugins', []));
        $o['plugin_mappar'] = array_values(array_filter(array_map('basename', (array) glob(WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR))));
        $o['teman'] = array_values(array_map('basename', (array) glob(get_theme_root() . '/*', GLOB_ONLYDIR)));
        $o['aktivt_tema'] = get_stylesheet();
        $o['mu_hjalpfiler_kvar'] = array_values(array_filter($mu_files, static fn($f) => file_exists($mu . '/' . $f)));
        $o['kk247_core'] = is_dir($mu . '/kk247-core');
        $o['dump_v2_json'] = file_exists(WP_CONTENT_DIR . '/uploads/fastsan-content/dump-v2.json');
        $o['favicon_i_roten'] = file_exists(ABSPATH . 'favicon.ico') ? filesize(ABSPATH . 'favicon.ico') : false;
        $o['elementor_data_rader'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data'");
        $o['elementor_poster'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type LIKE 'elementor%' OR post_type = 'e-floating-buttons'");
        $o['kommentarer'] = ['default_comment_status' => get_option('default_comment_status'), 'default_ping_status' => get_option('default_ping_status'),
            'oppna_poster' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE (comment_status = 'open' OR ping_status = 'open') AND post_type IN ('post','page')"),
            'antal_kommentarer' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments}")];
        return $o;
    };
    $rmdir = static function (string $dir) use (&$rmdir): bool {
        if (!is_dir($dir)) return true;
        foreach ((array) scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            (is_dir($p) && !is_link($p)) ? $rmdir($p) : @unlink($p);
        }
        return @rmdir($dir);
    };
    try {
        if ($steg === 'lage') {
            $out += $lage();
        } elseif ($steg === 'elementor_av') {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            deactivate_plugins('elementor/elementor.php', true);
            $out['aktiva_plugins'] = array_values((array) get_option('active_plugins', []));
        } elseif ($steg === 'radera') {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/theme.php';
            if (is_plugin_active('elementor/elementor.php')) throw new \RuntimeException('Elementor ar fortfarande aktivt');
            if (in_array(get_stylesheet(), $themes_del, true)) throw new \RuntimeException('ett av temana ar aktivt');
            // 1. Kopia utanfor webbroten av det som inte gar att hamta hem igen fran wordpress.org.
            if (!function_exists('aib_deployer_data_dir') || !class_exists('ZipArchive')) throw new \RuntimeException('kan inte ta kopia (deployer 1.1.0 eller ZipArchive saknas)');
            $bdir = aib_deployer_data_dir() . '/backup';
            if (!is_dir($bdir)) @mkdir($bdir, 0750, true);
            $zip_path = $bdir . '/qa7-stadning-' . gmdate('Ymd-His') . '.zip';
            $zip = new ZipArchive();
            if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('kan inte skapa zip');
            $n = 0;
            $add = static function (string $path, string $name) use (&$add, $zip, &$n) {
                if (is_dir($path)) {
                    foreach ((array) scandir($path) as $f) { if ($f !== '.' && $f !== '..') $add($path . '/' . $f, $name . '/' . $f); }
                } elseif (is_file($path)) { $zip->addFile($path, $name); $n++; }
            };
            foreach ($mu_files as $f) $add($mu . '/' . $f, 'mu-plugins/' . $f);
            $add($mu . '/kk247-core', 'mu-plugins/kk247-core');
            $add($theme_off, 'themes/' . basename($theme_off));
            $add(WP_CONTENT_DIR . '/uploads/fastsan-content/dump-v2.json', 'uploads/fastsan-content/dump-v2.json');
            $zip->close();
            if ($n > 0 && (!file_exists($zip_path) || filesize($zip_path) < 100)) throw new \RuntimeException('kopian blev tom');
            $out['kopia'] = ['fil' => basename($zip_path), 'utanfor_webbroten' => strpos($zip_path, WP_CONTENT_DIR) !== 0, 'filer' => $n, 'byte' => file_exists($zip_path) ? filesize($zip_path) : 0];
            // 2. Radera.
            WP_Filesystem();
            $present = array_values(array_filter($plugins_del, static fn($p) => file_exists(WP_PLUGIN_DIR . '/' . $p)));
            $r = $present ? delete_plugins($present) : true;
            $out['plugins'] = is_wp_error($r) ? $r->get_error_message() : $present;
            $out['teman'] = [];
            foreach ($themes_del as $t) {
                if (!is_dir(get_theme_root() . '/' . $t)) { $out['teman'][$t] = 'fanns inte'; continue; }
                $r = delete_theme($t);
                $out['teman'][$t] = is_wp_error($r) ? $r->get_error_message() : 'raderat';
            }
            $out['avstangd_temamapp'] = is_dir($theme_off) ? ($rmdir($theme_off) ? 'raderad' : 'kunde inte raderas') : 'fanns inte';
            $out['mu'] = [];
            foreach ($mu_files as $f) {
                if (file_exists($mu . '/' . $f)) $out['mu'][$f] = @unlink($mu . '/' . $f) ? 'raderad' : 'kunde inte raderas';
            }
            $out['kk247_core'] = is_dir($mu . '/kk247-core') ? ($rmdir($mu . '/kk247-core') ? 'raderad' : 'kunde inte raderas') : 'fanns inte';
            $d = WP_CONTENT_DIR . '/uploads/fastsan-content/dump-v2.json';
            $out['dump_v2_json'] = file_exists($d) ? (@unlink($d) ? 'raderad' : 'kunde inte raderas') : 'fanns inte';
            delete_option('__fs_v2_dump_done');
            if (function_exists('opcache_reset')) @opcache_reset();
            $out['efter'] = $lage();
        } elseif ($steg === 'favicon') {
            $src = get_template_directory() . '/assets/favicons/favicon.ico';
            $dst = ABSPATH . 'favicon.ico';
            $out['fanns'] = file_exists($dst);
            $out['kopierad'] = (!file_exists($dst) && is_file($src)) ? @copy($src, $dst) : false;
            $out['lika'] = file_exists($dst) && hash_file('sha256', $dst) === hash_file('sha256', $src);
        } elseif ($steg === 'kommentarer') {
            $out['fore'] = ['default_comment_status' => get_option('default_comment_status'), 'default_ping_status' => get_option('default_ping_status'), 'default_pingback_flag' => get_option('default_pingback_flag')];
            $out['oppna_poster_fore'] = array_map('intval', (array) $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE (comment_status = 'open' OR ping_status = 'open') AND post_type IN ('post','page','attachment')"));
            update_option('default_comment_status', 'closed');
            update_option('default_ping_status', 'closed');
            update_option('default_pingback_flag', '');
            $out['stangda_poster'] = (int) $wpdb->query("UPDATE {$wpdb->posts} SET comment_status = 'closed', ping_status = 'closed' WHERE (comment_status = 'open' OR ping_status = 'open') AND post_type IN ('post','page','attachment')");
            $out['antal_kommentarer'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments}");
        } elseif ($steg === 'klar') {
            $out['raderad'] = @unlink(__FILE__);
            if (function_exists('opcache_invalidate')) @opcache_invalidate(__FILE__, true);
        } else {
            $out['fel'] = 'okant steg';
        }
    } catch (\Throwable $e) {
        $out['fel'] = $e->getMessage();
    }
    echo wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}, 50);
