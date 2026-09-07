<?php
/**
 * Plugin Name: __fs-lead-test-errlog (ENGANGS, sjalvraderande)
 * Description: Laser sista 4 kB av public_html/error_log (PHP:s error_log pa kontot) och wp-content/debug.log om den finns, skriver till wp_option fastsan_lead_test_errlog, raderar sig sjalv. Lasning enbart.
 * Version: 1.0.0
 * Author: B S472
 */
if (!defined('ABSPATH')) return;
add_action('init', static function () {
    $out = [];
    foreach ([ABSPATH . 'error_log', WP_CONTENT_DIR . '/debug.log', WP_CONTENT_DIR . '/error_log'] as $f) {
        if (!is_file($f)) { $out[$f] = 'saknas'; continue; }
        $size = filesize($f);
        $fh = fopen($f, 'rb');
        if ($size > 4096) fseek($fh, -4096, SEEK_END);
        $out[$f] = ['size' => $size, 'tail' => stream_get_contents($fh)];
        fclose($fh);
    }
    update_option('fastsan_lead_test_errlog', wp_json_encode($out), false);
    @unlink(__FILE__);
    if (function_exists('opcache_invalidate')) @opcache_invalidate(__FILE__, true);
}, 30);
