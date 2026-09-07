<?php
/**
 * Plugin Name: Fastsan Lead Pipeline
 * Description: Lead-capture + statusmaskin. REST POST /wp-json/fastsan/v1/lead → DB → action fastsan_lead_created → e-postnotis till Daniel med signerade ett-tryck-statuslänkar. Daglig cron påminner (3/10/30 dagar, eskalering vid 3:e). REST POST /wp-json/fastsan/v1/lead/<id>/confirm tar emot kundens bokningsbekräftelse (fakturauppgifter). Statusbyte firar fastsan_lead_status_changed (framtida CAPI-krok, inget Meta-anrop i denna version).
 * Version: 0.3.0
 * Author: B (Claude orchestrator), Fastsan AB
 * Requires PHP: 8.0
 *
 * v0.3.0 (2026-09-07, B S472, agarord): mejlen skickas som HTML (multipart/alternative) med statuslankarna som
 *   klickbar rubriktext + ren-text-del (AltBody) med fulla URL:er. Samma exp/sig i bada delarna.
 * v0.2.2 (2026-09-07, B S472): confirm skriver bara skickade falt (prov 4b blankade uppgifter).
 * v0.2.1 (2026-09-07, B S472): eskaleringsadress odsater@gmail.com (johan@aibrick.se studsade 554 vid prov; agarbeslut).
 * v0.2.0 (2026-09-07, B S472, RELA-C-1220): statusmaskin new|contacted|quoted|booked|won|lost,
 *   kolumner status_updated_at/reminder_count/org_nr, company, invoice_address/postal/city/email, reference (dbDelta),
 *   HMAC-signerade statuslänkar (?fs_lead_action=1, hemlighet självbootstrappad i wp_option
 *   fastsan_lead_action_secret), daglig cron fastsan_lead_reminders (3/10/30 dagar, max 3,
 *   CC eskalering vid 3:e), REST confirm-endpoint, do_action('fastsan_lead_status_changed').
 *   Tillägg utöver ordern (dokumenterat i leveransen): notismejlet bär även en signerad
 *   bekräftelselänk för kunden (FASTSAN_LEAD_CONFIRM_PAGE, C:s formulärsida) och mottagaren
 *   kan filtreras (fastsan_lead_notify_to) för testkörningar utan att störa Daniel.
 * v0.1.1: minimal lead-capture, CAPI-stub utkommenterad.
 */

if (!defined('ABSPATH')) return;
if (defined('FASTSAN_LEAD_PIPELINE_LOADED')) return;
define('FASTSAN_LEAD_PIPELINE_LOADED', true);
define('FASTSAN_LEAD_TABLE', 'fastsan_leads');
define('FASTSAN_LEAD_PIPELINE_VERSION', '0.3.0');
define('FASTSAN_LEAD_NOTIFY_EMAIL', 'daniel@fastsan.se'); // kanon (agarbeslut 2026-09-05)
define('FASTSAN_LEAD_ESCALATION_EMAIL', 'odsater@gmail.com'); // CC vid 3:e paminnelsen. Agarbeslut 2026-09-07: johan@aibrick.se finns inte (554 No Such User Here)
define('FASTSAN_LEAD_SECRET_OPTION', 'fastsan_lead_action_secret');
define('FASTSAN_LEAD_LINK_TTL', 90 * DAY_IN_SECONDS); // §2: exp = utfardande + 90 dagar
define('FASTSAN_LEAD_CRON_HOOK', 'fastsan_lead_reminders');
define('FASTSAN_LEAD_REMINDER_MAX', 3);
if (!defined('FASTSAN_LEAD_CONFIRM_PAGE')) define('FASTSAN_LEAD_CONFIRM_PAGE', '/bekraftelse/'); // C:s formularsida (§5), byts via filter fastsan_lead_confirm_page_url

/* ---------- STATUSMASKIN (§1) ---------- */
function fastsan_lead_statuses(): array {
    return ['new', 'contacted', 'quoted', 'booked', 'won', 'lost'];
}

/** Statusar som gar att satta via lank (§2). 'new' satts bara av systemet. */
function fastsan_lead_action_statuses(): array {
    return ['contacted', 'quoted', 'booked', 'won', 'lost'];
}

function fastsan_lead_status_label(string $status): string {
    $labels = [
        'new'       => 'Ny',
        'contacted' => 'Kontaktad',
        'quoted'    => 'Offert skickad',
        'booked'    => 'Bokad',
        'won'       => 'Blev kund',
        'lost'      => 'Ingen affär',
    ];
    return $labels[$status] ?? $status;
}

/** Paminnelsetrosklar i dagar per reminder_count (§4). */
function fastsan_lead_reminder_thresholds(): array {
    return [3, 10, 30];
}

/* ---------- DB SCHEMA ---------- */
register_activation_hook(__FILE__, 'fastsan_lead_create_table');

function fastsan_lead_create_table() {
    global $wpdb;
    $table = $wpdb->prefix . FASTSAN_LEAD_TABLE;
    $charset = $wpdb->get_charset_collate();
    // OBS dbDelta: exakt tva blanksteg efter PRIMARY KEY, annars forsoker den lagga till nyckeln igen.
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
        status_updated_at DATETIME NULL,
        reminder_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
        org_nr VARCHAR(20) NULL,
        company VARCHAR(255) NULL,
        invoice_address VARCHAR(255) NULL,
        invoice_postal VARCHAR(16) NULL,
        invoice_city VARCHAR(64) NULL,
        invoice_email VARCHAR(255) NULL,
        reference VARCHAR(128) NULL,
        ip_address VARCHAR(64) NULL,
        user_agent TEXT NULL,
        referer VARCHAR(512) NULL,
        PRIMARY KEY  (id),
        KEY idx_created (created_at),
        KEY idx_status (status),
        KEY idx_email (email)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    // Aldre rader utan status_updated_at far created_at sa att cron kan rakna (§1: vid insert = created_at).
    $wpdb->query("UPDATE $table SET status_updated_at = created_at WHERE status_updated_at IS NULL");
    update_option('fastsan_lead_pipeline_db_version', FASTSAN_LEAD_PIPELINE_VERSION);
}

// Self-trigger schema if missing (since this is a mu-plugin, activation hook never fires)
add_action('init', static function () {
    if (get_option('fastsan_lead_pipeline_db_version') !== FASTSAN_LEAD_PIPELINE_VERSION) {
        fastsan_lead_create_table();
    }
}, 1);

/* ---------- HEMLIGHET + SIGNERING (§2) ---------- */
/** Sjalvbootstrap: saknas option genereras 32 hex och sparas. Aldrig i logg/mejl/relatext. */
function fastsan_lead_secret(): string {
    $secret = (string) get_option(FASTSAN_LEAD_SECRET_OPTION, '');
    if (strlen($secret) < 32) {
        try {
            $secret = bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            $secret = bin2hex(wp_generate_password(16, false, false)); // fallback, fortfarande 32 hex
        }
        update_option(FASTSAN_LEAD_SECRET_OPTION, $secret, false);
    }
    return $secret;
}

function fastsan_lead_sign(int $id, string $status, int $exp): string {
    return hash_hmac('sha256', $id . '|' . $status . '|' . $exp, fastsan_lead_secret());
}

/** Signerad ett-tryck-lank for en status (§2). exp = nu + 90 dagar. */
function fastsan_lead_action_url(int $id, string $status): string {
    $exp = time() + FASTSAN_LEAD_LINK_TTL;
    return add_query_arg([
        'fs_lead_action' => '1',
        'id'  => $id,
        's'   => $status,
        'exp' => $exp,
        'sig' => fastsan_lead_sign($id, $status, $exp),
    ], home_url('/'));
}

/** Signerad bekraftelselank till kundens formularsida (§5; sidan bygger C). s=confirm. */
function fastsan_lead_confirm_url(int $id): string {
    $exp = time() + FASTSAN_LEAD_LINK_TTL;
    $base = apply_filters('fastsan_lead_confirm_page_url', home_url(FASTSAN_LEAD_CONFIRM_PAGE), $id);
    return add_query_arg([
        'id'  => $id,
        'exp' => $exp,
        'sig' => fastsan_lead_sign($id, 'confirm', $exp),
    ], $base);
}

/**
 * Verifierar id/status/exp/sig. Returnerar null vid OK, annars [http_status, felkod].
 * Ordning: sig 403 → exp 410 → status 400 (§2).
 */
function fastsan_lead_verify(int $id, string $status, string $exp, string $sig): ?array {
    if ($id <= 0 || !ctype_digit($exp) || !preg_match('/^[0-9a-f]{64}$/', $sig)) {
        return [403, 'sig'];
    }
    $expected = fastsan_lead_sign($id, $status, (int) $exp);
    if (!hash_equals($expected, $sig)) return [403, 'sig'];
    if ((int) $exp < time()) return [410, 'expired'];
    return null;
}

/* ---------- STATUSBYTE ---------- */
function fastsan_lead_get(int $id): ?array {
    global $wpdb;
    $table = $wpdb->prefix . FASTSAN_LEAD_TABLE;
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);
    return is_array($row) ? $row : null;
}

/**
 * Satter status. Idempotent: samma status = ingen skrivning, ingen krok.
 * Nollstaller reminder_count och satter status_updated_at. Firar fastsan_lead_status_changed($id, $old, $new).
 * Returnerar true om raden andrades.
 */
function fastsan_lead_set_status(int $id, string $new_status, string $via = 'link'): bool {
    global $wpdb;
    if (!in_array($new_status, fastsan_lead_statuses(), true)) return false;
    $lead = fastsan_lead_get($id);
    if ($lead === null) return false;
    $old = (string) $lead['status'];
    if ($old === $new_status) return false;
    $table = $wpdb->prefix . FASTSAN_LEAD_TABLE;
    $ok = $wpdb->update($table, [
        'status'            => $new_status,
        'status_updated_at' => current_time('mysql'),
        'reminder_count'    => 0,
    ], ['id' => $id], ['%s', '%s', '%d'], ['%d']);
    if ($ok === false) {
        error_log('[AIB] fastsan-lead-pipeline: status-update misslyckades lead ' . $id . ': ' . $wpdb->last_error);
        return false;
    }
    error_log(sprintf('[AIB] fastsan-lead-pipeline: lead #%d %s -> %s (%s)', $id, $old, $new_status, $via));
    do_action('fastsan_lead_status_changed', $id, $old, $new_status); // framtida CAPI-krok, ingen Meta-kod nu
    return true;
}

/* ---------- ETT-TRYCK-HANDLER (§2) ---------- */
add_action('init', 'fastsan_lead_action_handler', 1);

function fastsan_lead_action_handler() {
    if (!isset($_GET['fs_lead_action']) || $_GET['fs_lead_action'] !== '1') return;
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') return;
    nocache_headers();

    $id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $status = isset($_GET['s']) ? sanitize_key((string) $_GET['s']) : '';
    $exp    = isset($_GET['exp']) ? (string) $_GET['exp'] : '';
    $sig    = isset($_GET['sig']) ? strtolower((string) $_GET['sig']) : '';

    $err = fastsan_lead_verify($id, $status, $exp, $sig);
    if ($err !== null) {
        fastsan_lead_action_page($err[0], $err[0] === 410
            ? 'Länken har gått ut. Be om ett nytt notismejl.'
            : 'Ogiltig länk.');
    }
    if (!in_array($status, fastsan_lead_action_statuses(), true)) {
        fastsan_lead_action_page(400, 'Okänt statusvärde.');
    }
    $lead = fastsan_lead_get($id);
    if ($lead === null) {
        fastsan_lead_action_page(404, sprintf('Förfrågan #%d finns inte.', $id));
    }
    try {
        fastsan_lead_set_status($id, $status, 'link');
    } catch (\Throwable $e) {
        error_log('[AIB] fastsan-lead-pipeline: action-handler fel lead ' . $id . ': ' . $e->getMessage());
        fastsan_lead_action_page(500, 'Tekniskt fel. Statusen kunde inte sparas.');
    }
    fastsan_lead_action_page(200, sprintf('Tack — förfrågan #%d är markerad som %s', $id, fastsan_lead_status_label($status)));
}

/** Minimal HTML-sida, avslutar requesten. */
function fastsan_lead_action_page(int $http, string $message): void {
    status_header($http);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!doctype html><html lang="sv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Fastsan — status</title>'
        . '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;max-width:32rem;margin:12vh auto;padding:0 1.25rem;color:#1a1a1a;line-height:1.5}h1{font-size:1.25rem;font-weight:600}</style></head><body>'
        . '<h1>' . esc_html($message) . '</h1>'
        . ($http === 200 ? '<p>Du kan stänga den här sidan.</p>' : '')
        . '</body></html>';
    exit;
}

/* ---------- REST ENDPOINTS ---------- */
add_action('rest_api_init', static function () {
    register_rest_route('fastsan/v1', '/lead', [
        'methods'  => 'POST',
        'callback' => 'fastsan_lead_handle',
        'permission_callback' => '__return_true',
    ]);
    // §5: kundens bokningsbekraftelse. Oppen route, men sig KRAVS (samma HMAC-schema, s=confirm).
    register_rest_route('fastsan/v1', '/lead/(?P<id>\d+)/confirm', [
        'methods'  => 'POST',
        'callback' => 'fastsan_lead_confirm_handle',
        'permission_callback' => '__return_true',
        'args' => ['id' => ['validate_callback' => static fn($v) => ctype_digit((string) $v)]],
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

    $now = current_time('mysql');
    $data = [
        'created_at'             => $now,
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
        'status'                 => 'new',
        'status_updated_at'      => $now, // §1
        'reminder_count'         => 0,
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

    // §6 fail-soft: notisen far aldrig doda svaret till formularet.
    try {
        do_action('fastsan_lead_created', $lead);
    } catch (\Throwable $e) {
        error_log('[AIB] fastsan-lead-pipeline: fastsan_lead_created-krok fel lead ' . $lead_id . ': ' . $e->getMessage());
    }

    return new WP_REST_Response([
        'status'  => 'ok',
        'lead_id' => $lead_id,
    ], 201);
}

/* ---------- BEKRAFTELSE-ENDPOINT (§5) ---------- */
function fastsan_lead_confirm_handle(WP_REST_Request $req) {
    global $wpdb;
    $table = $wpdb->prefix . FASTSAN_LEAD_TABLE;

    $id  = (int) $req->get_param('id');
    $exp = (string) ($req->get_param('exp') ?? '');
    $sig = strtolower((string) ($req->get_param('sig') ?? ''));

    $err = fastsan_lead_verify($id, 'confirm', $exp, $sig);
    if ($err !== null) {
        return new WP_REST_Response(['status' => 'error', 'reason' => $err[1]], $err[0]);
    }
    $lead = fastsan_lead_get($id);
    if ($lead === null) {
        return new WP_REST_Response(['status' => 'error', 'reason' => 'not_found'], 404);
    }

    // Falt (alla sanerade). org_nr: 10-12 siffror, bindestreck tillats, lagras utan.
    $org_raw = preg_replace('/\s+/', '', (string) ($req->get_param('org_nr') ?? ''));
    $org_nr  = str_replace('-', '', $org_raw);
    if (!preg_match('/^\d{10,12}$/', $org_nr)) $org_nr = '';
    $company = sanitize_text_field((string) ($req->get_param('company') ?? ''));
    if ($org_nr === '' && $company === '') {
        return new WP_REST_Response(['status' => 'error', 'reason' => 'org_nr_or_company_required'], 400);
    }
    // v0.2.2: bara falt som SKICKAS skrivs -- en andra bekraftelse med farre falt blankar inte tidigare uppgifter.
    $fields = [];
    if ($org_nr !== '')  $fields['org_nr']  = $org_nr;
    if ($company !== '') $fields['company'] = $company;
    if ($req->has_param('invoice_address')) $fields['invoice_address'] = sanitize_text_field((string) $req->get_param('invoice_address'));
    if ($req->has_param('invoice_postal'))  $fields['invoice_postal']  = substr(sanitize_text_field((string) $req->get_param('invoice_postal')), 0, 16);
    if ($req->has_param('invoice_city'))    $fields['invoice_city']    = substr(sanitize_text_field((string) $req->get_param('invoice_city')), 0, 64);
    if ($req->has_param('invoice_email'))   $fields['invoice_email']   = sanitize_email((string) $req->get_param('invoice_email'));
    if ($req->has_param('reference'))       $fields['reference']       = substr(sanitize_text_field((string) $req->get_param('reference')), 0, 128);
    $notes = sanitize_textarea_field((string) ($req->get_param('notes') ?? ''));
    if ($notes !== '') {
        $fields['message'] = rtrim((string) $lead['message']) . "\n\n[Bekräftelse] " . $notes;
    }

    $ok = $fields ? $wpdb->update($table, $fields, ['id' => $id]) : 0;
    if ($ok === false) {
        error_log('[AIB] fastsan-lead-pipeline: confirm-update misslyckades lead ' . $id . ': ' . $wpdb->last_error);
        return new WP_REST_Response(['status' => 'error', 'reason' => 'db_update_failed'], 500);
    }

    // status=booked om inte redan won. Kroken firar bara vid faktisk andring (idempotent).
    if ((string) $lead['status'] !== 'won') {
        try {
            fastsan_lead_set_status($id, 'booked', 'confirm');
        } catch (\Throwable $e) {
            error_log('[AIB] fastsan-lead-pipeline: confirm status-byte fel lead ' . $id . ': ' . $e->getMessage());
        }
    }

    // §6 fail-soft: mejlet far aldrig doda svaret.
    try {
        $fresh = fastsan_lead_get($id) ?? array_merge($lead, $fields);
        fastsan_lead_confirm_email($fresh);
    } catch (\Throwable $e) {
        error_log('[AIB] fastsan-lead-pipeline: confirm-mejl fel lead ' . $id . ': ' . $e->getMessage());
    }

    return new WP_REST_Response(['status' => 'ok', 'lead_id' => $id], 200);
}

/* ---------- E-POST ---------- */
add_action('fastsan_lead_created', 'fastsan_lead_notify_email', 10, 1);

/** Mottagare for notiser. Filtret finns for testkorningar (kanon: daniel@fastsan.se). */
function fastsan_lead_notify_to(): string {
    $to = (string) apply_filters('fastsan_lead_notify_to', FASTSAN_LEAD_NOTIFY_EMAIL);
    return is_email($to) ? $to : FASTSAN_LEAD_NOTIFY_EMAIL;
}

/** §3: lankarna utfardas EN gang per mejl och anvands i bade HTML- och textdelen (samma exp/sig). */
function fastsan_lead_links(int $id): array {
    $status = [];
    foreach (fastsan_lead_action_statuses() as $st) {
        $status[$st] = ['label' => fastsan_lead_status_label($st), 'url' => fastsan_lead_action_url($id, $st)];
    }
    return ['status' => $status, 'confirm' => fastsan_lead_confirm_url($id)];
}

/** Ren-text-block (AltBody / klienter utan HTML). */
function fastsan_lead_links_text(array $links): string {
    $b = "\n--- MARKERA STATUS (ett tryck) ---\n";
    foreach ($links['status'] as $l) {
        $b .= sprintf("%-16s%s\n", $l['label'] . ':', $l['url']);
    }
    $b .= "\n--- BEKRÄFTELSE (skicka till kunden efter samtal) ---\n" . $links['confirm'] . "\n";
    return $b;
}

/** HTML-block: rubriken ar sjalva lanken (v0.3.0, agarord). Inline-CSS, stora tryckytor for mobil. */
function fastsan_lead_links_html(array $links): string {
    $btn = 'display:inline-block;margin:4px 6px 4px 0;padding:11px 16px;background:#1f5fbf;color:#ffffff;border-radius:6px;text-decoration:none;font-weight:600;font-size:15px;line-height:1.2';
    $h  = '<p style="margin:22px 0 6px;font-weight:700;font-size:14px;letter-spacing:.02em;color:#333">MARKERA STATUS (ett tryck)</p><p style="margin:0">';
    foreach ($links['status'] as $l) {
        $h .= '<a href="' . esc_url($l['url']) . '" style="' . $btn . '">' . esc_html($l['label']) . '</a>';
    }
    $h .= '</p>';
    $h .= '<p style="margin:22px 0 6px;font-weight:700;font-size:14px;letter-spacing:.02em;color:#333">BEKRÄFTELSE</p>'
        . '<p style="margin:0"><a href="' . esc_url($links['confirm']) . '" style="' . str_replace('#1f5fbf', '#2e7d32', $btn) . '">Bekräftelselänk till kunden</a>'
        . '<br><span style="font-size:12px;color:#666">Skicka den till kunden efter samtalet. Kunden fyller i fakturauppgifter, statusen blir Bokad.</span></p>';
    return $h;
}

/** Ren text -> HTML-stycke (radbrytningar bevaras, allt escapas). */
function fastsan_lead_text_to_html(string $text): string {
    return '<div style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;line-height:1.5;color:#222;white-space:pre-wrap">' . esc_html($text) . '</div>';
}

function fastsan_lead_html_wrap(string $inner): string {
    return '<!doctype html><html lang="sv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:16px;background:#ffffff"><div style="max-width:640px;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#222">'
        . $inner . '</div></body></html>';
}

/**
 * Skickar HTML + ren text (multipart/alternative via PHPMailer AltBody). Fungerar med WP Mail SMTP
 * (phpmailer_init firar dar ocksa). Hooken ar per anrop och tas bort direkt efter.
 */
function fastsan_lead_send_mail(string $to, string $subject, string $text, string $html, array $extra_headers = []): bool {
    $alt = static function ($phpmailer) use ($text) { $phpmailer->AltBody = $text; };
    add_action('phpmailer_init', $alt, 20);
    $headers = array_merge(['Content-Type: text/html; charset=UTF-8'], $extra_headers);
    try {
        $ok = (bool) wp_mail($to, $subject, fastsan_lead_html_wrap($html), $headers);
    } finally {
        remove_action('phpmailer_init', $alt, 20);
    }
    return $ok;
}

/** Detaljtext om leadet (utan lankar). */
function fastsan_lead_notify_details(array $lead): string {
    $body  = "Ny förfrågan inkommen via fastsan.se\n\n";
    $body .= sprintf("Lead-ID: #%d\n", $lead['id']);
    $body .= sprintf("Inkommen: %s\n", $lead['created_at']);
    $body .= sprintf("Status:   %s\n\n", fastsan_lead_status_label((string) ($lead['status'] ?? 'new')));
    $body .= "─────────────── KUND ───────────────\n";
    $body .= sprintf("Namn:           %s\n", $lead['name']);
    $body .= sprintf("E-post:         %s\n", !empty($lead['email']) ? $lead['email'] : '(ej angiven)');
    $body .= sprintf("Telefon:        %s\n", !empty($lead['phone']) ? $lead['phone'] : '(ej angiven)');
    $body .= sprintf("Föredrar:       %s\n", $lead['contact_preference']);
    $body .= "─────────────── UPPDRAG ────────────\n";
    $body .= sprintf("Objekttyp:      %s\n", $lead['object_type']);
    $body .= sprintf("Brådska:        %s\n", $lead['urgency']);
    $body .= sprintf("Källa:          %s\n", $lead['source']);
    if (!empty($lead['message'])) {
        $body .= "\nMeddelande:\n" . $lead['message'] . "\n";
    }
    $body .= "\n─────────────── METADATA ───────────\n";
    $body .= sprintf("IP:             %s\n", (string) ($lead['ip_address'] ?? ''));
    $body .= sprintf("Referrer:       %s\n", !empty($lead['referer']) ? $lead['referer'] : '(direkt)');
    return $body;
}

/** Bakatkompatibel: ren-text-kropp inkl. lankblock (anvands av paminnelsen som textdel). */
function fastsan_lead_notify_body(array $lead, ?array $links = null): string {
    $links = $links ?? fastsan_lead_links((int) $lead['id']);
    return fastsan_lead_notify_details($lead) . fastsan_lead_links_text($links);
}

function fastsan_lead_notify_email($lead) {
    $site_name = get_bloginfo('name');
    $subject = sprintf('[%s] Ny förfrågan #%d — %s', $site_name, $lead['id'], $lead['name']);
    $links   = fastsan_lead_links((int) $lead['id']);
    $details = fastsan_lead_notify_details($lead);
    fastsan_lead_send_mail(
        fastsan_lead_notify_to(), $subject,
        $details . fastsan_lead_links_text($links),
        fastsan_lead_text_to_html($details) . fastsan_lead_links_html($links)
    );
}

/** §5: bekraftelsemejl. FORSTA raden ar Org-nr (Fortnox-appen hamtar namn+adress pa org-nr). */
function fastsan_lead_confirm_email(array $lead): void {
    $id = (int) $lead['id'];
    $subject = sprintf('Bokningsbekräftelse mottagen — #%d %s', $id, (string) ($lead['company'] ?? ''));
    $body  = sprintf("Org-nr: %s\n", !empty($lead['org_nr']) ? $lead['org_nr'] : '(ej angivet)');
    $body .= sprintf("Företag:        %s\n", (string) ($lead['company'] ?? ''));
    $body .= sprintf("Fakturaadress:  %s\n", (string) ($lead['invoice_address'] ?? ''));
    $body .= sprintf("Postnr/ort:     %s %s\n", (string) ($lead['invoice_postal'] ?? ''), (string) ($lead['invoice_city'] ?? ''));
    $body .= sprintf("Faktura-e-post: %s\n", (string) ($lead['invoice_email'] ?? ''));
    $body .= sprintf("Referens:       %s\n", (string) ($lead['reference'] ?? ''));
    $body .= sprintf("\nFörfrågan #%d — %s (%s, %s)\n", $id, (string) $lead['name'], (string) $lead['email'], (string) $lead['phone']);
    $body .= sprintf("Status nu:      %s\n", fastsan_lead_status_label((string) $lead['status']));
    if (!empty($lead['message'])) {
        $body .= "\nMeddelande:\n" . $lead['message'] . "\n";
    }
    $links = fastsan_lead_links($id);
    fastsan_lead_send_mail(
        fastsan_lead_notify_to(), $subject,
        $body . fastsan_lead_links_text($links),
        fastsan_lead_text_to_html($body) . fastsan_lead_links_html($links)
    );
}

/* ---------- CRON-PAMINNELSER (§4) ---------- */
add_action('init', static function () {
    if (!wp_next_scheduled(FASTSAN_LEAD_CRON_HOOK)) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', FASTSAN_LEAD_CRON_HOOK);
    }
}, 20);

add_action(FASTSAN_LEAD_CRON_HOOK, 'fastsan_lead_run_reminders');

/** Korbar aven direkt via do_action(FASTSAN_LEAD_CRON_HOOK). Returnerar antal skickade paminnelser. */
function fastsan_lead_run_reminders(): int {
    global $wpdb;
    $table = $wpdb->prefix . FASTSAN_LEAD_TABLE;
    $thresholds = fastsan_lead_reminder_thresholds();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE status IN ('new','contacted','quoted','booked') AND reminder_count < %d AND status_updated_at IS NOT NULL ORDER BY id ASC",
        FASTSAN_LEAD_REMINDER_MAX
    ), ARRAY_A);
    if (!is_array($rows)) return 0;

    $now  = current_time('timestamp');
    $sent = 0;
    foreach ($rows as $lead) {
        try {
            $n = (int) $lead['reminder_count'];
            if (!isset($thresholds[$n])) continue;
            $since = strtotime((string) $lead['status_updated_at']);
            if ($since === false) continue;
            $days = (int) floor(($now - $since) / DAY_IN_SECONDS);
            if ($days < $thresholds[$n]) continue;

            $step  = $n + 1;
            $label = fastsan_lead_status_label((string) $lead['status']);
            $subject = sprintf('[Påminnelse %d/%d] Förfrågan #%d — %s står som %s sedan %d dagar',
                $step, FASTSAN_LEAD_REMINDER_MAX, (int) $lead['id'], (string) $lead['name'], $label, $days);
            $headers = [];
            if ($step >= FASTSAN_LEAD_REMINDER_MAX) {
                $headers[] = 'Cc: ' . FASTSAN_LEAD_ESCALATION_EMAIL;
            }
            $intro = sprintf("Påminnelse %d/%d: förfrågan #%d står som %s sedan %d dagar.\nMarkera aktuell status med ett tryck längst ner.\n\n",
                $step, FASTSAN_LEAD_REMINDER_MAX, (int) $lead['id'], $label, $days);
            $links   = fastsan_lead_links((int) $lead['id']);
            $details = $intro . fastsan_lead_notify_details($lead);
            fastsan_lead_send_mail(
                fastsan_lead_notify_to(), $subject,
                $details . fastsan_lead_links_text($links),
                fastsan_lead_text_to_html($details) . fastsan_lead_links_html($links),
                $headers
            );

            $wpdb->update($table, ['reminder_count' => $step], ['id' => (int) $lead['id']], ['%d'], ['%d']);
            $sent++;
            error_log(sprintf('[AIB] fastsan-lead-pipeline: paminnelse %d/%d lead #%d (%s, %d dagar)', $step, FASTSAN_LEAD_REMINDER_MAX, (int) $lead['id'], $lead['status'], $days));
        } catch (\Throwable $e) {
            error_log('[AIB] fastsan-lead-pipeline: paminnelse fel lead ' . (int) ($lead['id'] ?? 0) . ': ' . $e->getMessage());
        }
    }
    return $sent;
}

/* ---------- CAPI (parkerad) ----------
 * Kroken do_action('fastsan_lead_status_changed', $id, $old, $new) ar den framtida
 * Meta CAPI-ingangen. Inget anrop byggs i v0.2 (agarbeslut, RELA-C-1220).
 * Skiss v1.1: add_action('fastsan_lead_status_changed', fn($id,$old,$new) => ...);
 *   POST https://graph.facebook.com/v18.0/{PIXEL_ID}/events, em = sha256(lower(email)),
 *   ph = sha256(E.164), event Lead/Purchase, action_source website/system_generated.
 * ---------- /CAPI ---------- */
