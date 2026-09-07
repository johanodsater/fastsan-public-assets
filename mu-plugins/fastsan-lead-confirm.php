<?php
/**
 * Plugin Name: Fastsan Lead Confirm (kundens bekräftelseformulär)
 * Description: Renderar kundens bokningsbekräftelse på /bekraftelse/. Läser id/exp/sig ur den signerade länken i Daniels notismejl och POST:ar till /wp-json/fastsan/v1/lead/<id>/confirm (fastsan-lead-pipeline §5). Skapar sidan en gång om den saknas. Alltid noindex, aldrig i sitemap.
 * Version: 1.0.1
 * Author: C (Claude), Fastsan AB
 * Requires PHP: 8.0
 *
 * v1.0.1 (2026-09-07, C): sidan lyfts ur wp-sitemap. rank_math_robots + wp_robots gav noindex i HTML,
 *   men WP:s inbyggda sitemap läser inte den metan — sidan låg kvar i wp-sitemap-posts-page-1.xml.
 *   Fynd i A:s peer-review RELA-A-1226 (P1-d).
 *
 * Motpart: fastsan-lead-pipeline v0.3.0 (B S472). Signaturschema id|status|exp, status = 'confirm'.
 * Sidan är avsiktligt utan navigering till andra åtgärder: kunden ska fylla i fakturauppgifter, inget annat.
 */

if (!defined('ABSPATH')) return;
if (defined('FASTSAN_LEAD_CONFIRM_LOADED')) return;
define('FASTSAN_LEAD_CONFIRM_LOADED', true);
define('FASTSAN_LEAD_CONFIRM_VERSION', '1.0.1');
define('FASTSAN_LEAD_CONFIRM_SLUG', 'bekraftelse');
define('FASTSAN_LEAD_CONFIRM_PAGE_OPTION', 'fastsan_lead_confirm_page_id');

/* ---------- SIDAN (skapas en gång, idempotent) ---------- */

add_action('init', static function () {
    $stored = (int) get_option(FASTSAN_LEAD_CONFIRM_PAGE_OPTION, 0);
    if ($stored > 0 && get_post_status($stored) === 'publish') return;

    $existing = get_page_by_path(FASTSAN_LEAD_CONFIRM_SLUG);
    if ($existing instanceof WP_Post) {
        update_option(FASTSAN_LEAD_CONFIRM_PAGE_OPTION, (int) $existing->ID, false);
        return;
    }

    $id = wp_insert_post([
        'post_title'     => 'Bekräfta uppdraget',
        'post_name'      => FASTSAN_LEAD_CONFIRM_SLUG,
        'post_status'    => 'publish',
        'post_type'      => 'page',
        'post_content'   => '[fastsan_lead_confirm]',
        'comment_status' => 'closed',
        'ping_status'    => 'closed',
    ], true);

    if (!is_wp_error($id)) {
        update_option(FASTSAN_LEAD_CONFIRM_PAGE_OPTION, (int) $id, false);
        update_post_meta((int) $id, 'rank_math_robots', ['noindex']);
        error_log('[AIB] fastsan-lead-confirm: sida skapad, ID ' . (int) $id);
    }
}, 5);

/** Sidan får aldrig indexeras — URL:en bär en signatur. */
add_filter('wp_robots', static function (array $robots): array {
    if (!fastsan_lead_confirm_is_page()) return $robots;
    $robots['noindex']  = true;
    $robots['nofollow'] = true;
    unset($robots['index'], $robots['follow'], $robots['max-image-preview'], $robots['max-snippet']);
    return $robots;
}, 20);

/**
 * WP:s inbyggda sitemap tittar inte på robots-metan — sidan måste lyftas ur med ett query-filter.
 * Utan detta ligger en transaktionssida med org-nummer kvar i wp-sitemap-posts-page-1.xml.
 */
add_filter('wp_sitemaps_posts_query_args', static function (array $args, string $post_type): array {
    if ($post_type !== 'page') return $args;
    $id = (int) get_option(FASTSAN_LEAD_CONFIRM_PAGE_OPTION, 0);
    if ($id > 0) {
        $existing = isset($args['post__not_in']) ? (array) $args['post__not_in'] : [];
        $args['post__not_in'] = array_values(array_unique(array_merge($existing, [$id])));
    }
    return $args;
}, 10, 2);

function fastsan_lead_confirm_is_page(): bool {
    $stored = (int) get_option(FASTSAN_LEAD_CONFIRM_PAGE_OPTION, 0);
    return ($stored > 0 && is_page($stored)) || is_page(FASTSAN_LEAD_CONFIRM_SLUG);
}

/* ---------- FORMULÄRET ---------- */

add_shortcode('fastsan_lead_confirm', 'fastsan_lead_confirm_render');

function fastsan_lead_confirm_render(): string {
    $id  = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $exp = isset($_GET['exp']) ? preg_replace('/\D/', '', (string) wp_unslash($_GET['exp'])) : '';
    $sig = isset($_GET['sig']) ? strtolower(preg_replace('/[^a-f0-9]/i', '', (string) wp_unslash($_GET['sig']))) : '';

    if ($id <= 0 || $exp === '' || $sig === '') {
        return fastsan_lead_confirm_notice(
            'Länken är ofullständig',
            'Öppna länken direkt ur mejlet från Fastsan, utan att ändra adressen. Hör av dig till oss om den fortsatt inte fungerar.'
        );
    }

    // Samma dom som REST-endpointen gör — men här innan kunden fyller i något.
    if (function_exists('fastsan_lead_verify')) {
        $err = fastsan_lead_verify($id, 'confirm', $exp, $sig);
        if ($err !== null) {
            $reason = is_array($err) ? (string) ($err[1] ?? '') : '';
            if ($reason === 'expired') {
                return fastsan_lead_confirm_notice(
                    'Länken har gått ut',
                    'Bekräftelselänkar gäller i 90 dagar. Hör av dig till oss så skickar vi en ny.'
                );
            }
            return fastsan_lead_confirm_notice(
                'Länken går inte att verifiera',
                'Öppna länken direkt ur mejlet från Fastsan. Hör av dig till oss om den fortsatt inte fungerar.'
            );
        }
    }

    $endpoint = esc_url_raw(rest_url('fastsan/v1/lead/' . $id . '/confirm'));

    ob_start();
    ?>
    <div class="fs-confirm" id="fs-confirm">
        <p class="fs-confirm__lead">Fyll i uppgifterna nedan så skickar vi fakturan rätt. Fälten med * behövs för att vi ska kunna fakturera.</p>

        <div class="fs-confirm__msg" id="fs-confirm-msg" role="status" aria-live="polite" hidden></div>

        <div class="fs-confirm__form" id="fs-confirm-form">
            <p class="fs-confirm__field">
                <label for="fs-org">Organisationsnummer *</label>
                <input type="text" id="fs-org" inputmode="numeric" autocomplete="off" placeholder="556677-8899">
                <span class="fs-confirm__hint">Tio siffror. Är du privatperson lämnar du fältet tomt och fyller i företagsnamn.</span>
            </p>
            <p class="fs-confirm__field">
                <label for="fs-company">Företag eller namn *</label>
                <input type="text" id="fs-company" autocomplete="organization">
            </p>
            <p class="fs-confirm__field">
                <label for="fs-address">Fakturaadress</label>
                <input type="text" id="fs-address" autocomplete="street-address">
            </p>
            <div class="fs-confirm__row">
                <p class="fs-confirm__field fs-confirm__field--narrow">
                    <label for="fs-postal">Postnummer</label>
                    <input type="text" id="fs-postal" inputmode="numeric" autocomplete="postal-code">
                </p>
                <p class="fs-confirm__field">
                    <label for="fs-city">Ort</label>
                    <input type="text" id="fs-city" autocomplete="address-level2">
                </p>
            </div>
            <p class="fs-confirm__field">
                <label for="fs-email">E-post för faktura</label>
                <input type="email" id="fs-email" autocomplete="email">
            </p>
            <p class="fs-confirm__field">
                <label for="fs-reference">Er referens</label>
                <input type="text" id="fs-reference" placeholder="Beställarens namn eller märkning">
            </p>
            <p class="fs-confirm__field">
                <label for="fs-notes">Övrigt till oss</label>
                <textarea id="fs-notes" rows="4"></textarea>
            </p>
            <p class="fs-confirm__actions">
                <button type="button" class="fs-confirm__btn" id="fs-confirm-submit">Skicka bekräftelse</button>
            </p>
        </div>
    </div>

    <style>
    .fs-confirm{max-width:38rem;font:inherit}
    .fs-confirm__lead{margin:0 0 1.5rem}
    .fs-confirm__field{display:flex;flex-direction:column;margin:0 0 1.1rem}
    .fs-confirm__field--narrow{flex:0 0 9rem}
    .fs-confirm__row{display:flex;gap:1rem;flex-wrap:wrap}
    .fs-confirm__row .fs-confirm__field{flex:1 1 12rem}
    .fs-confirm label{font-weight:600;margin-bottom:.35rem}
    .fs-confirm input,.fs-confirm textarea{font:inherit;padding:.7rem .8rem;border:1px solid #b9b9b9;border-radius:4px;background:#fff;width:100%;box-sizing:border-box}
    .fs-confirm input:focus,.fs-confirm textarea:focus{outline:2px solid #1f5fbf;outline-offset:1px;border-color:#1f5fbf}
    .fs-confirm__hint{font-size:.875rem;opacity:.75;margin-top:.3rem}
    .fs-confirm__btn{font:inherit;font-weight:600;padding:.85rem 1.6rem;border:0;border-radius:4px;background:#1f5fbf;color:#fff;cursor:pointer}
    .fs-confirm__btn:hover{background:#17498f}
    .fs-confirm__btn[disabled]{opacity:.6;cursor:default}
    .fs-confirm__msg{padding:1rem 1.15rem;border-radius:4px;margin:0 0 1.5rem;border-left:4px solid #1f5fbf;background:#eef3fb}
    .fs-confirm__msg--ok{border-left-color:#2e7d32;background:#eef6ee}
    .fs-confirm__msg--err{border-left-color:#b3261e;background:#fbeeed}
    .fs-confirm__msg h2{margin:0 0 .4rem;font-size:1.15rem}
    .fs-confirm__msg p{margin:0}
    </style>

    <script>
    (function () {
        var endpoint = <?php echo wp_json_encode($endpoint); ?>;
        var exp = <?php echo wp_json_encode($exp); ?>;
        var sig = <?php echo wp_json_encode($sig); ?>;
        var form = document.getElementById('fs-confirm-form');
        var msg = document.getElementById('fs-confirm-msg');
        var btn = document.getElementById('fs-confirm-submit');
        if (!form || !btn) return;

        function val(id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; }

        function show(kind, title, text) {
            msg.className = 'fs-confirm__msg' + (kind ? ' fs-confirm__msg--' + kind : '');
            msg.innerHTML = '';
            var h = document.createElement('h2');
            h.textContent = title;
            var p = document.createElement('p');
            p.textContent = text;
            msg.appendChild(h);
            msg.appendChild(p);
            msg.hidden = false;
            msg.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        btn.addEventListener('click', function () {
            var org = val('fs-org').replace(/[\s-]/g, '');
            var company = val('fs-company');
            if (!org && !company) {
                show('err', 'Fyll i minst ett fält', 'Vi behöver organisationsnummer eller företagsnamn för att kunna fakturera.');
                return;
            }
            if (org && !/^\d{10,12}$/.test(org)) {
                show('err', 'Kontrollera organisationsnumret', 'Skriv tio siffror, med eller utan bindestreck.');
                return;
            }

            btn.disabled = true;
            btn.textContent = 'Skickar …';

            var payload = { exp: exp, sig: sig };
            if (org) payload.org_nr = org;
            if (company) payload.company = company;
            var optional = { invoice_address: 'fs-address', invoice_postal: 'fs-postal', invoice_city: 'fs-city', invoice_email: 'fs-email', reference: 'fs-reference', notes: 'fs-notes' };
            Object.keys(optional).forEach(function (key) {
                var v = val(optional[key]);
                if (v) payload[key] = v;
            });

            fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }).then(function (res) {
                return res.json().catch(function () { return {}; }).then(function (data) {
                    return { status: res.status, data: data };
                });
            }).then(function (r) {
                if (r.status === 200) {
                    form.hidden = true;
                    show('ok', 'Tack — vi har fått dina uppgifter', 'Daniel hör av sig med tid för provtagningen. Du behöver inte göra något mer.');
                    return;
                }
                btn.disabled = false;
                btn.textContent = 'Skicka bekräftelse';
                var reason = r.data && r.data.reason ? r.data.reason : '';
                if (r.status === 410 || reason === 'expired') {
                    show('err', 'Länken har gått ut', 'Bekräftelselänkar gäller i 90 dagar. Hör av dig till oss så skickar vi en ny.');
                } else if (r.status === 403) {
                    show('err', 'Länken går inte att verifiera', 'Öppna länken direkt ur mejlet från Fastsan, utan att ändra adressen.');
                } else if (r.status === 400) {
                    show('err', 'Uppgifterna räckte inte', 'Vi behöver organisationsnummer eller företagsnamn.');
                } else if (r.status === 404) {
                    show('err', 'Förfrågan hittades inte', 'Hör av dig till oss så reder vi ut det.');
                } else {
                    show('err', 'Något gick fel hos oss', 'Försök igen om en stund, eller hör av dig så tar vi uppgifterna över telefon.');
                }
            }).catch(function () {
                btn.disabled = false;
                btn.textContent = 'Skicka bekräftelse';
                show('err', 'Ingen kontakt med servern', 'Kontrollera uppkopplingen och försök igen.');
            });
        });
    })();
    </script>
    <?php
    return (string) ob_get_clean();
}

function fastsan_lead_confirm_notice(string $title, string $text): string {
    return '<div class="fs-confirm"><div class="fs-confirm__msg fs-confirm__msg--err">'
        . '<h2>' . esc_html($title) . '</h2><p>' . esc_html($text) . '</p></div>'
        . '<style>.fs-confirm__msg{padding:1rem 1.15rem;border-radius:4px;border-left:4px solid #b3261e;background:#fbeeed;max-width:38rem}'
        . '.fs-confirm__msg h2{margin:0 0 .4rem;font-size:1.15rem}.fs-confirm__msg p{margin:0}</style></div>';
}
