<?php
/**
 * Plugin Name: __fs-lead-test-run (ENGANGS, sjalvraderande)
 * Description: Kor fastsan_lead_run_reminders() EN gang pa forsta request efter deploy, skriver resultatet till wp_option fastsan_lead_test_last (json) och raderar sig sjalv. Ingen nyckel behovs: filen finns bara mellan deploy och forsta request. Idempotens via sjalvradering + option-flagga (aldrig transient).
 * Version: 1.0.0
 * Author: B S472
 */
if (!defined('ABSPATH')) return;
add_action('init', static function () {
    if (!function_exists('fastsan_lead_run_reminders')) return;
    $stamp = 'run-' . gmdate('Ymd-His');
    $sent = null; $err = '';
    try { $sent = fastsan_lead_run_reminders(); } catch (\Throwable $e) { $err = $e->getMessage(); }
    update_option('fastsan_lead_test_last', wp_json_encode(['stamp' => $stamp, 'sent' => $sent, 'error' => $err]), false);
    @unlink(__FILE__);
    if (function_exists('opcache_invalidate')) @opcache_invalidate(__FILE__, true);
}, 30);
