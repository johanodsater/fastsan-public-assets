<?php
/**
 * Plugin Name: Fastsan Lead Pipeline
 * Description: Lead-capture + statusmaskin. REST POST /wp-json/fastsan/v1/lead → DB → action fastsan_lead_created → e-postnotis till Daniel med signerade ett-tryck-statuslänkar. Daglig cron påminner (3/10/30 dagar, eskalering vid 3:e). REST POST /wp-json/fastsan/v1/lead/<id>/confirm tar emot kundens bokningsbekräftelse (fakturauppgifter). Statusbyte firar fastsan_lead_status_changed (framtida CAPI-krok, inget Meta-anrop i denna version).
 * Version: 0.2.1
 * Author: B (Claude orchestrator), Fastsan AB
 * Requires PHP: 8.0
 *
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
define('FASTSAN_LEAD_PIPELINE_VERSION', '0.2.1');
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
    $fields = [
        'org_nr'          => $org_nr,
        'company'         => $company,
        'invoice_address' => sanitize_text_field((string) ($req->get_param('invoice_address') ?? '')),
        'invoice_postal'  => substr(sanitize_text_field((string) ($req->get_param('invoice_postal') ?? '')), 0, 16),
        'invoice_city'    => substr(sanitize_text_field((string) ($req->get_param('invoice_city') ?? '')), 0, 64),
        'invoice_email'   => sanitize_email((string) ($req->get_param('invoice_email') ?? '')),
        'reference'       => substr(sanitize_text_field((string) ($req->get_param('reference') ?? '')), 0, 128),
    ];
    $notes = sanitize_textarea_field((string) ($req->get_param('notes') ?? ''));
    if ($notes !== '') {
        $fields['message'] = rtrim((string) $lead['message']) . "\n\n[Bekräftelse] " . $notes;
    }

    $ok = $wpdb->update($table, $fields, ['id' => $id]);
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

/** §3: statusblock, ren text (Gmail mobil). Nyutfardade lankar varje gang. */
function fastsan_lead_status_links_block(int $id): string {
    $b  = "\n--- MARKERA STATUS (ett tryck) ---\n";
    $b .= sprintf("Kontaktad:      %s\n", fastsan_lead_action_url($id, 'contacted'));
    $b .= sprintf("Offert skickad: %s\n", fastsan_lead_action_url($id, 'quoted'));
    $b .= sprintf("Bokad:          %s\n", fastsan_lead_action_url($id, 'booked'));
    $b .= sprintf("Blev kund:      %s\n", fastsan_lead_action_url($id, 'won'));
    $b .= sprintf("Ingen affär:    %s\n", fastsan_lead_action_url($id, 'lost'));
    $b .= "\n--- BEKRÄFTELSE (skicka till kunden efter samtal) ---\n";
    $b .= fastsan_lead_confirm_url($id) . "\n";
    return $b;
}

function fastsan_lead_notify_body(array $lead): string {
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
    $body .= fastsan_lead_status_links_block((int) $lead['id']);
    return $body;
}

function fastsan_lead_notify_email($lead) {
    $site_name = get_bloginfo('name');
    $subject = sprintf('[%s] Ny förfrågan #%d — %s', $site_name, $lead['id'], $lead['name']);
    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    wp_mail(fastsan_lead_notify_to(), $subject, fastsan_lead_notify_body($lead), $headers);
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
    $body .= fastsan_lead_status_links_block($id);
    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    wp_mail(fastsan_lead_notify_to(), $subject, $body, $headers);
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
            $headers = ['Content-Type: text/plain; charset=UTF-8'];
            if ($step >= FASTSAN_LEAD_REMINDER_MAX) {
                $headers[] = 'Cc: ' . FASTSAN_LEAD_ESCALATION_EMAIL;
            }
            $body = sprintf("Påminnelse %d/%d: förfrågan #%d står som %s sedan %d dagar.\nMarkera aktuell status med ett tryck längst ner.\n\n",
                $step, FASTSAN_LEAD_REMINDER_MAX, (int) $lead['id'], $label, $days);
            $body .= fastsan_lead_notify_body($lead);
            wp_mail(fastsan_lead_notify_to(), $subject, $body, $headers);

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
