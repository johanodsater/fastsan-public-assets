<?php
/**
 * Plugin Name: Fastsan — Schema Injector
 * Description: JSON-LD-schema per tjänstesida (Service) + BreadcrumbList + FAQPage. Kompletterar Organization/LocalBusiness som temat redan injicerar. Hooks: wp_head prio 99 (efter befintlig Organization). Inga exit/die-patterns (KK247-incident 2026-05-04). Avaktivering: radera filen från mu-plugins/, eller definiera FASTSAN_SCHEMA_DISABLED i wp-config.php.
 * Version: 1.3.1
 * Author: Aibrick (C-instance)
 *
 * v1.3.1 (2026-09-06, C): engångs-bootstrap som installerar mu-plugins/aib-deployer.php (commit-pinnad, sha256-gate) om filen saknas. Tas bort i v1.4.0 tillsammans med GA4-blocket (GA4 blir fristående fil, deployad via aib-deployer).
 * v1.3.0 (2026-09-06, C): GA4-tagg G-CB3PLTJ06B i samtyckesläge (Consent Mode v2, default denied; CookieYes-cookien styr update) tillagd som guardat block sist i filen — tillfällig bärare eftersom bryggans file_write hänger; flyttas till fristående fastsan-ga4.php när bryggan fungerar (blocket hoppar över sig självt om __FASTSAN_GA4_LOADED redan är definierad). Deploy via __fs-content-puller (content/v2).
 * v1.2.1 (2026-09-05, C): ISO/IEC 17025-påståendet struket ur provtagning-description (ägarbeslut 2026-09-05; overifierbart, samma klass som "ackrediterat ISO 17025-lab"). Deploy via github-pull.
 * v1.2.0 (2026-09-05, C): aldrig-frasen utbytt mot "Vi utför ingen sanering" i två Service-descriptions (inomhusmiljo, luktutredning) (ägarbeslut: frasen får inte användas). Deploy via github-pull.
 */

if (!defined('ABSPATH')) { return; }
if (defined('FASTSAN_SCHEMA_DISABLED') && FASTSAN_SCHEMA_DISABLED) { return; }
if (defined('FASTSAN_SCHEMA_LOADED')) { return; }
define('FASTSAN_SCHEMA_LOADED', '1.3.1');

/**
 * Hämta service-data per slug.
 * Source-of-truth för vad varje tjänstesida representerar i Schema.org-termer.
 */
function fastsan_schema_get_services() {
    return [
        'miljoinventering' => [
            'name'        => 'Miljöinventering',
            'serviceType' => 'Environmental hazard inspection',
            'description' => 'Komplett kartläggning av miljö- och hälsofarliga material inför rivning, ombyggnad eller försäljning. Asbest, PCB, tungmetaller och övriga ämnen identifierade enligt AFS 2023:13 och Naturvårdsverkets vägledningar.',
            'category'    => 'Building inspection',
        ],
        'provtagning' => [
            'name'        => 'Provtagning byggnadsmaterial',
            'serviceType' => 'Building material sampling',
            'description' => 'Asbest, PCB, tungmetaller och övriga ämnen — provtagning på plats, analys på specialistlaboratorium. Vi tolkar resultatet och skriver rapporten.',
            'category'    => 'Material testing',
        ],
        'markmiljo' => [
            'name'        => 'Markmiljöundersökning',
            'serviceType' => 'Soil environmental survey',
            'description' => 'Provtagning och klassning av förorenad mark enligt Naturvårdsverkets riktvärden KM/MKM. För länsstyrelse, fastighetsförvärv, exploatering och saneringsbeslut.',
            'category'    => 'Environmental survey',
        ],
        'inomhusmiljo' => [
            'name'        => 'Fukt- och mögelutredning inomhus',
            'serviceType' => 'Indoor air quality and moisture survey',
            'description' => 'Oberoende fuktbedömning och mögelprov — bedömning som håller för försäkring, BRF-styrelse och fastighetsägare. Vi utför ingen sanering — vi visar dig vad det är.',
            'category'    => 'Indoor environment',
        ],
        'pcb' => [
            'name'        => 'PCB-inventering',
            'serviceType' => 'PCB inventory',
            'description' => 'PCB-inventering enligt SFS 2007:19 och Naturvårdsverkets rapport 6884 — fogmassor, golvmassor och kondensatorer från byggnader uppförda 1956–1973.',
            'category'    => 'PCB management',
        ],
        'radon' => [
            'name'        => 'Radonmätning',
            'serviceType' => 'Radon measurement',
            'description' => 'Långtidsmätning (2 mån) eller korttidsmätning (7–10 dygn) av radonhalt mot referensvärdet 200 Bq/m³. Bostäder, arbetsplatser och bostadsrättsföreningar.',
            'category'    => 'Radon measurement',
        ],
        'akut' => [
            'name'        => 'Akut provtagning',
            'serviceType' => 'Emergency environmental sampling',
            'description' => 'Akut provtagning av asbest, mögel och bly — svar samma dag (oftast inom 3 timmar). Stockholm-regionen.',
            'category'    => 'Emergency response',
        ],
        'forvarvsbesiktning' => [
            'name'        => 'Miljöteknisk förvärvsbesiktning',
            'serviceType' => 'Pre-acquisition environmental inspection',
            'description' => 'Miljöteknisk besiktning vid fastighetsköp — PCB, asbest, tungmetaller och markrisk identifierade innan tillträde.',
            'category'    => 'Acquisition inspection',
        ],
        'luktutredning' => [
            'name'        => 'Luktutredning',
            'serviceType' => 'Odor source identification',
            'description' => 'Oberoende identifiering av luktkällor i bostad, BRF eller kontor. Vi utför ingen sanering — vi identifierar källan så du vet vad det är.',
            'category'    => 'Indoor environment',
        ],
    ];
}

/**
 * Bygg Service-schema för aktuell tjänstesida.
 */
function fastsan_schema_build_service($post) {
    $services = fastsan_schema_get_services();
    if (!isset($services[$post->post_name])) {
        return null;
    }
    $s = $services[$post->post_name];
    $page_url = get_permalink($post);
    $home = home_url('/');

    $schema = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Service',
        '@id'         => $page_url . '#service',
        'name'        => $s['name'],
        'description' => $s['description'],
        'serviceType' => $s['serviceType'],
        'category'    => $s['category'],
        'url'         => $page_url,
        'provider'    => [
            '@id' => $home . '#organization',
        ],
        'areaServed'  => [
            ['@type' => 'AdministrativeArea', 'name' => 'Stockholm'],
            ['@type' => 'AdministrativeArea', 'name' => 'Mälardalen'],
        ],
        'offers'      => [
            '@type'         => 'Offer',
            'priceCurrency' => 'SEK',
            'priceSpecification' => [
                '@type'       => 'PriceSpecification',
                'description' => 'Pris efter offert. Skriftligt fastpris inom 24 timmar.',
            ],
            'availability'  => 'https://schema.org/InStock',
        ],
    ];

    // v1.1.0: Lägg till image-property om featured image finns.
    // Guard: assignment görs bara om get_the_post_thumbnail_url() returnerar truthy,
    // annars blir det ogiltigt schema på sidor utan thumbnail (null/false). URL byggs
    // från home_url() så den uppdateras automatiskt vid DNS-cutover.
    $image_url = get_the_post_thumbnail_url($post, 'large');
    if ($image_url) {
        $schema['image'] = $image_url;
    }

    return $schema;
}

/**
 * Bygg BreadcrumbList för alla sidor utom front-page.
 */
function fastsan_schema_build_breadcrumb($post) {
    if (is_front_page()) return null;
    $home = home_url('/');
    $items = [
        [
            '@type'    => 'ListItem',
            'position' => 1,
            'name'     => 'Hem',
            'item'     => $home,
        ],
        [
            '@type'    => 'ListItem',
            'position' => 2,
            'name'     => wp_strip_all_tags($post->post_title),
            'item'     => get_permalink($post),
        ],
    ];
    return [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $items,
    ];
}

/**
 * Bygg FAQPage-schema om sidan har post_meta `_fastsan_faqs`.
 * Format på meta: JSON-array av {q, a}-objekt.
 *   Exempel: [{"q":"Hur lång tid tar provtagningen?","a":"Cirka 1–2 timmar på plats."}]
 */
function fastsan_schema_build_faq($post) {
    $raw = get_post_meta($post->ID, '_fastsan_faqs', true);
    if (empty($raw)) return null;
    $faqs = is_array($raw) ? $raw : json_decode($raw, true);
    if (!is_array($faqs) || empty($faqs)) return null;

    $main_entity = [];
    foreach ($faqs as $f) {
        if (!isset($f['q']) || !isset($f['a'])) continue;
        $main_entity[] = [
            '@type'          => 'Question',
            'name'           => wp_strip_all_tags($f['q']),
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text'  => wp_strip_all_tags($f['a']),
            ],
        ];
    }
    if (empty($main_entity)) return null;

    return [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => $main_entity,
    ];
}

/**
 * Output ett JSON-LD-block (inline, ej via wp_add_inline_script eftersom det
 * inte är ett registrerat script-handle).
 */
function fastsan_schema_output($data) {
    if (empty($data)) return;
    $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!$json) return;
    echo "\n<script type=\"application/ld+json\">" . $json . "</script>\n";
}

/**
 * Inject scheman i wp_head.
 * Prio 99 = efter Rank Math och efter temats Organization-schema.
 */
add_action('wp_head', function () {
    if (!is_singular('page')) return;
    $post = get_queried_object();
    if (!$post || !isset($post->post_name)) return;

    $service = fastsan_schema_build_service($post);
    if ($service) fastsan_schema_output($service);

    $crumb = fastsan_schema_build_breadcrumb($post);
    if ($crumb) fastsan_schema_output($crumb);

    $faq = fastsan_schema_build_faq($post);
    if ($faq) fastsan_schema_output($faq);
}, 99);

/**
 * Admin-notice så ingen glömmer att pluginen är aktiv.
 */
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->id !== 'dashboard') return;
    echo '<div class="notice notice-info is-dismissible"><p><strong>Fastsan Schema Injector v' . esc_html(FASTSAN_SCHEMA_LOADED) . ' aktiv.</strong> Service-schema injicerar på 9 tjänstesidor. Verifiera via <a href="https://search.google.com/test/rich-results" target="_blank" rel="noopener">Google Rich Results Test</a>.</p></div>';
});

/* ------------------------------------------------------------------
 * GA4 (tillfällig bärare, v1.3.0). Identiskt med mu-plugins/fastsan-ga4.php i
 * fastsan-public-assets. Hoppar över sig självt om den fristående filen laddats.
 * ------------------------------------------------------------------ */
if (!defined('__FASTSAN_GA4_LOADED')) {
    define('__FASTSAN_GA4_LOADED', true);
    if (!defined('FASTSAN_GA4_ID')) define('FASTSAN_GA4_ID', 'G-CB3PLTJ06B');
    add_action('wp_head', static function () {
        if (is_admin() || is_user_logged_in() || is_feed() || is_preview()) return;
        $id = esc_js(FASTSAN_GA4_ID);
        ?>
<!-- Fastsan GA4 / Consent Mode v2 -->
<script>
window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',functionality_storage:'denied',personalization_storage:'denied',security_storage:'granted',wait_for_update:500});
gtag('js',new Date());
gtag('config','<?php echo $id; ?>');
(function(){
function rd(){var m=document.cookie.match(/(?:^|;\s*)cookieyes-consent=([^;]*)/);if(!m)return null;var o={};decodeURIComponent(m[1]).split(',').forEach(function(p){var kv=p.split(':');if(kv.length===2)o[kv[0].trim()]=kv[1].trim();});return o;}
function ap(o){if(!o||o.action!=='yes')return;var a=o.analytics==='yes',ad=o.advertisement==='yes',f=o.functional==='yes';gtag('consent','update',{analytics_storage:a?'granted':'denied',ad_storage:ad?'granted':'denied',ad_user_data:ad?'granted':'denied',ad_personalization:ad?'granted':'denied',functionality_storage:f?'granted':'denied',personalization_storage:f?'granted':'denied'});}
ap(rd());
document.addEventListener('cookieyes_consent_update',function(){setTimeout(function(){ap(rd());},50);});
document.addEventListener('click',function(e){var t=e.target;if(t&&t.closest&&t.closest('.cky-btn'))setTimeout(function(){ap(rd());},300);},true);
})();
</script>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo $id; ?>"></script>
        <?php
    }, 1);
}

/* ------------------------------------------------------------------
 * Engångs-bootstrap (v1.3.1): installera aib-deployer om den saknas.
 * Commit-pinnad URL + sha256-gate. Max ett försök per 5 min. Tas bort i v1.4.0.
 * ------------------------------------------------------------------ */
add_action('init', function () {
    $dst = WPMU_PLUGIN_DIR . '/aib-deployer.php';
    if (file_exists($dst) || get_transient('aib_deployer_boot_lock')) return;
    set_transient('aib_deployer_boot_lock', 1, 300);
    $url = 'https://raw.githubusercontent.com/johanodsater/fastsan-public-assets/55b36e4220c9805aaf71667b8b4f92d584e9bf47/mu-plugins/aib-deployer.php';
    $exp = '7beaa8c5f75f2cde071452ff5c027955e62990355c35befa5592ef96850b9a05';
    $r = wp_remote_get($url, ['timeout' => 20, 'redirection' => 0]);
    if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) return;
    $b = (string) wp_remote_retrieve_body($r);
    if (hash('sha256', $b) !== $exp || strpos($b, '<?php') !== 0) return;
    if (@file_put_contents($dst . '.aibtmp', $b) === strlen($b)) { @rename($dst . '.aibtmp', $dst); @chmod($dst, 0644); }
}, 0);
