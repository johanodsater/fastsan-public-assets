<?php
/**
 * Plugin Name: Fastsan Lead Pipeline
 * Description: Minimal lead-capture v0.1. REST POST /wp-json/fastsan/v1/lead → DB-insert → action fastsan_lead_created → e-postnotis till Daniel. CAPI-stub commented för v1.1.
 * Version: 0.1.1
 * Author: B (Claude orchestrator), Fastsan AB
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) return;
if (defined('FASTSAN_LEAD_PIPELINE_LOADED')) return;
define('FASTSAN_LEAD_PIPELINE_LOADED', true);
define('FASTSAN_LEAD_TABLE', 'fastsan_leads');
define('FASTSAN_LEAD_PIPELINE_VERSION', '0.1.1');
define('FASTSAN_LEAD_NOTIFY_EMAIL', 'daniel@fastsan.se'); // kanon (agarbeslut 2026-09-05)

/* ---------- DB SCHEMA ---------- */
register_activation_hook(__FILE__, 'fastsan_lead_create_table');

function fastsan_lead_create_table() {
    global $wpdb;
    $table = $wpdb->prefix . FASTSAN_LEAD_TABLE;
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        name VARCHAR(255) NOT NULL DEFAULT '',
        email VARCHAR(255) NOT NULL DEFAULT '',
        phone VARCHAR(64) NOT NULL DEFAULT '',
        contact_preference VARCHAR(16) NOT NULL DEFAULT 'either',
        object_type VARCHAR(32) NOT NULL DEFAULT 'unknown',
        urgency VARCHAR(16) NOT NULL DEFAULT 'normal',
        message TEXT NULL,
        source VARCHAR(64) NOT NULL DEFAULT 'unknown',
        consent_marketing TINYINT(1) NOT NULL DEFAULT 0,
        consent_lab_disclosure TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(16) NOT NULL DEFAULT 'new',
        ip_address VARCHAR(64) NULL,
        user_agent TEXT NULL,
        referer VARCHAR(512) NULL,
        PRIMARY KEY (id),
        KEY idx_created (created_at),
        KEY idx_status (status),
        KEY idx_email (email)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option('fastsan_lead_pipeline_db_version', FASTSAN_LEAD_PIPELINE_VERSION);
}

// Self-trigger schema if missing (since this is a mu-plugin, activation hook never fires)
add_action('init', static function () {
    if (get_option('fastsan_lead_pipeline_db_version') !== FASTSAN_LEAD_PIPELINE_VERSION) {
        fastsan_lead_create_table();
    }
}, 1);

/* ---------- REST ENDPOINT ---------- */
add_action('rest_api_init', static function () {
    register_rest_route('fastsan/v1', '/lead', [
        'methods'  => 'POST',
        'callback' => 'fastsan_lead_handle',
        'permission_callback' => '__return_true',
    ]);
});

function fastsan_lead_handle(WP_REST_Request $req) {
    global $wpdb;
    $table = $wpdb->prefix . FASTSAN_LEAD_TABLE;

    $name  = sanitize_text_field($req->get_param('name') ?? '');
    $email = sanitize_email($req->get_param('email') ?? '');
    $phone = sanitize_text_field($req->get_param('phone') ?? '');
    $msg   = sanitize_textarea_field($req->get_param('message') ?? '');

    if ($name === '' || ($email === '' && $phone === '')) {
        return new WP_REST_Response([
            'status' => 'error',
            'reason' => 'name + (email OR phone) required',
        ], 400);
    }

    $allowed_pref = ['phone', 'email', 'either'];
    $pref = strtolower((string) ($req->get_param('contact_preference') ?? 'either'));
    if (!in_array($pref, $allowed_pref, true)) $pref = 'either';

    $allowed_obj = ['villa', 'brf', 'byggprojekt', 'mark', 'kommersiell', 'annat', 'unknown'];
    $obj = strtolower((string) ($req->get_param('object_type') ?? 'unknown'));
    if (!in_array($obj, $allowed_obj, true)) $obj = 'unknown';

    $allowed_urg = ['akut', 'inom_vecka', 'inom_manad', 'planering', 'normal'];
    $urg = strtolower((string) ($req->get_param('urgency') ?? 'normal'));
    if (!in_array($urg, $allowed_urg, true)) $urg = 'normal';

    $data = [
        'created_at'             => current_time('mysql'),
        'name'                   => $name,
        'email'                  => $email,
        'phone'                  => $phone,
        'contact_preference'     => $pref,
        'object_type'            => $obj,
        'urgency'                => $urg,
        'message'                => $msg,
        'source'                 => sanitize_text_field($req->get_param('source') ?? 'rest'),
        'consent_marketing'      => $req->get_param('consent_marketing') ? 1 : 0,
        'consent_lab_disclosure' => $req->get_param('consent_lab_disclosure') ? 1 : 0,
        'ip_address'             => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '',
        'user_agent'             => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_textarea_field($_SERVER['HTTP_USER_AGENT']) : '',
        'referer'                => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : '',
    ];

    $inserted = $wpdb->insert($table, $data);
    if ($inserted === false) {
        return new WP_REST_Response([
            'status'  => 'error',
            'reason'  => 'db_insert_failed',
            'db_err'  => $wpdb->last_error,
        ], 500);
    }

    $lead_id = (int) $wpdb->insert_id;
    $lead = array_merge(['id' => $lead_id], $data);

    do_action('fastsan_lead_created', $lead);

    return new WP_REST_Response([
        'status'  => 'ok',
        'lead_id' => $lead_id,
    ], 201);
}

/* ---------- E-POSTNOTIS ---------- */
add_action('fastsan_lead_created', 'fastsan_lead_notify_email', 10, 1);

function fastsan_lead_notify_email($lead) {
    $site_name = get_bloginfo('name');
    $subject = sprintf('[%s] Ny förfrågan #%d — %s', $site_name, $lead['id'], $lead['name']);

    $body  = "Ny förfrågan inkommen via fastsan.se\n\n";
    $body .= sprintf("Lead-ID: #%d\n", $lead['id']);
    $body .= sprintf("Inkommen: %s\n\n", $lead['created_at']);
    $body .= "─────────────── KUND ───────────────\n";
    $body .= sprintf("Namn:           %s\n", $lead['name']);
    $body .= sprintf("E-post:         %s\n", $lead['email'] ?: '(ej angiven)');
    $body .= sprintf("Telefon:        %s\n", $lead['phone'] ?: '(ej angiven)');
    $body .= sprintf("Föredrar:       %s\n", $lead['contact_preference']);
    $body .= "─────────────── UPPDRAG ────────────\n";
    $body .= sprintf("Objekttyp:      %s\n", $lead['object_type']);
    $body .= sprintf("Brådska:        %s\n", $lead['urgency']);
    $body .= sprintf("Källa:          %s\n", $lead['source']);
    if (!empty($lead['message'])) {
        $body .= "\nMeddelande:\n" . $lead['message'] . "\n";
    }
    $body .= "\n─────────────── METADATA ───────────\n";
    $body .= sprintf("IP:             %s\n", $lead['ip_address']);
    $body .= sprintf("Referrer:       %s\n", $lead['referer'] ?: '(direkt)');
    $body .= "\nHantera leadet: " . admin_url('admin.php?page=fastsan-leads&lead=' . $lead['id']) . "\n";

    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    wp_mail(FASTSAN_LEAD_NOTIFY_EMAIL, $subject, $body, $headers);
}

/* ---------- CAPI-STUB v1.1 (commented) ----------
add_action('fastsan_lead_created', 'fastsan_lead_to_capi', 20, 1);
function fastsan_lead_to_capi($lead) {
    // TODO v1.1: POST to Meta Conversions API
    // Endpoint: https://graph.facebook.com/v18.0/{PIXEL_ID}/events
    // Hash email (SHA256 lowercase) → em
    // Hash phone (E.164 SHA256) → ph
    // Event: Lead, action_source: website, event_time: now
}
---------- /CAPI-STUB ---------- */
