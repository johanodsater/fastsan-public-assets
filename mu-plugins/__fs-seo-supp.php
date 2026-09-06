<?php
/**
 * Plugin Name: FS SEO Supplement + Force Re-inject
 * Description: (1) Renders <title>, <meta description>, OG/Twitter cards (inkl. og:image + twitter:image från Rank Math) och Schema.org JSON-LD från rank_math_* postmeta. (2) On ?fs_force=om,kontakt|all clears __fs_inj_{slug}_v1 flags so injector re-runs.
 * Version: 1.5.0
 * Author: AIB / C (S153-C)
 *
 * v1.5.0 (2026-09-06, C): knowsAbout rättad efter av.se — gränsvärdet 0,01 fiber/cm³ bärs av AFS 2023:14 (ändrad genom AFS 2025:5), hantering av AFS 2023:13 (ändrad genom AFS 2025:6 + 2025:8). Alla fem listade. Deploy via aib-deployer.
 * v1.4.0 (2026-09-05, C): sameAs → FB-användarnamn (fastsanab) + LinkedIn-företagssida (fastsan-ab); foundingDate 2008 (ägarbeslut: verksamhetsstart); legalName, vatID, logo tillagda. Deploy via github-pull (bridge write timeout >7 KB).
 * v1.3.1 (2026-08-21, C S392): sameAs utökad med Facebook-sidan (ägarlämnad URL).
 * v1.3.0 (2026-08-21, C S392): founder utökad till full Person-nod med @id, email och telefon (C391-4, ägarorder: endast Daniel på sidan). sameAs ifylld med allabolag.se (C391-5). Angelica: 0 förekomster mätt i DB och schema — inget att ta bort.
 * v1.2.1 (2026-08-21, C S391): Organization-schemats 'email' ensad till daniel@fastsan.se per ägarbeslut (C391-2). Tidigare daniel.stalbrand@fastsan.se.
 * v1.2.0 (2026-05-14): Added og:image, og:image:secure_url, og:image:width/height, twitter:image rendering from per-page rank_math_facebook_image (or global default). Upgraded twitter:card to summary_large_image when image present.
 * v1.1.0 (2026-05-13): Added Schema.org ProfessionalService JSON-LD (site-wide, anchor on home).
 */

if (!defined('ABSPATH')) return;
if (defined('__FS_SEO_SUPP_LOADED')) return;
define('__FS_SEO_SUPP_LOADED', true);

add_filter('pre_get_document_title', function($title) {
    if (!is_singular() && !is_front_page()) return $title;
    $pid = is_front_page() ? get_option('page_on_front') : get_the_ID();
    if (!$pid) return $title;
    $custom = get_post_meta($pid, 'rank_math_title', true);
    return !empty($custom) ? $custom : $title;
}, 1000);

add_action('wp_head', function() {
    if (!is_singular() && !is_front_page()) return;
    $pid = is_front_page() ? get_option('page_on_front') : get_the_ID();
    if (!$pid) return;

    $desc = get_post_meta($pid, 'rank_math_description', true);
    $title = get_post_meta($pid, 'rank_math_title', true);
    $url = get_permalink($pid);

    $og_image = '';
    $og_image_id = 0;
    $pm_id = (int) get_post_meta($pid, 'rank_math_facebook_image_id', true);
    if ($pm_id) {
        $og_image_id = $pm_id;
        $og_image = wp_get_attachment_image_url($pm_id, 'full');
    }
    if (!$og_image) {
        $pm_url = get_post_meta($pid, 'rank_math_facebook_image', true);
        if (!empty($pm_url)) $og_image = $pm_url;
    }
    if (!$og_image) {
        $titles = get_option('rank-math-options-titles', []);
        $og_image_id = (int) ($titles['open_graph_image_id'] ?? 0);
        $og_image = $titles['open_graph_image'] ?? '';
    }

    if (!empty($desc)) {
        echo '<meta name="description" content="' . esc_attr($desc) . '" />' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($desc) . '" />' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr($desc) . '" />' . "\n";
    }
    if (!empty($title)) {
        echo '<meta property="og:title" content="' . esc_attr($title) . '" />' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($title) . '" />' . "\n";
    }
    if ($url) {
        echo '<meta property="og:url" content="' . esc_url($url) . '" />' . "\n";
        echo '<meta property="og:type" content="website" />' . "\n";
        echo '<meta property="og:site_name" content="Fastsan AB" />' . "\n";
        echo '<meta property="og:locale" content="sv_SE" />' . "\n";
    }

    if ($og_image) {
        echo '<meta property="og:image" content="' . esc_url($og_image) . '" />' . "\n";
        echo '<meta property="og:image:secure_url" content="' . esc_url($og_image) . '" />' . "\n";
        if ($og_image_id) {
            $meta = wp_get_attachment_metadata($og_image_id);
            if (!empty($meta['width'])) {
                echo '<meta property="og:image:width" content="' . (int) $meta['width'] . '" />' . "\n";
            }
            if (!empty($meta['height'])) {
                echo '<meta property="og:image:height" content="' . (int) $meta['height'] . '" />' . "\n";
            }
        }
        echo '<meta name="twitter:image" content="' . esc_url($og_image) . '" />' . "\n";
        echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
    } elseif ($url) {
        echo '<meta name="twitter:card" content="summary" />' . "\n";
    }
}, 1);

add_action('wp_head', function() {
    $home = home_url('/');
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'ProfessionalService',
        '@id' => $home . '#organization',
        'name' => 'Fastsan AB',
        'legalName' => 'Fastsan aktiebolag',
        'url' => $home,
        'logo' => $home . 'wp-content/themes/fastsan/assets/logo-fastsanab.png',
        'telephone' => '+46701424639',
        'email' => 'daniel@fastsan.se',
        'foundingDate' => '2008',
        'vatID' => 'SE556714471101',
        'description' => 'Oberoende miljökonsult i Stockholm. Miljöinventering, provtagning och utredning för fastighet och boendemiljö — utan kommersiella låsningar till sanering eller specifika laboratorier.',
        'founder' => [
            '@type' => 'Person',
            '@id' => $home . '#daniel',
            'name' => 'Daniel Stålbrand',
            'jobTitle' => 'Grundare och konsult',
            'email' => 'daniel@fastsan.se',
            'telephone' => '+46701424639',
            'worksFor' => ['@id' => $home . '#organization'],
        ],
        'address' => [
            '@type' => 'PostalAddress',
            'streetAddress' => 'Myrmalmsringen 26',
            'postalCode' => '136 65',
            'addressLocality' => 'Söderby',
            'addressRegion' => 'Stockholms län',
            'addressCountry' => 'SE',
        ],
        'areaServed' => [
            ['@type' => 'AdministrativeArea', 'name' => 'Stockholm'],
            ['@type' => 'AdministrativeArea', 'name' => 'Mälardalen'],
        ],
        'serviceType' => [
            'Miljöinventering',
            'Provtagning byggnadsmaterial',
            'Asbestprovtagning',
            'PCB-inventering',
            'Miljöteknisk markundersökning',
            'Fukt- och mögelprov',
            'Radonmätning',
        ],
        'knowsAbout' => [
            'AFS 2023:13', 'AFS 2023:14', 'AFS 2025:5', 'AFS 2025:6', 'AFS 2025:8', 'Plan- och bygglagen',
            'Naturvårdsverket rapport 6884', 'SFS 2007:19', 'SFS 2010:963',
        ],
        'priceRange' => 'Pris efter offert',
        'sameAs' => [
            'https://www.allabolag.se/5567144711',
            'https://www.facebook.com/fastsanab',
            'https://www.linkedin.com/company/fastsan-ab',
        ],
    ];
    echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
}, 2);

add_action('init', function() {
    if (!isset($_GET['fs_force'])) return;
    $param = sanitize_text_field(wp_unslash($_GET['fs_force']));
    $all_slugs = ['hem','miljoinventering','provtagning','markmiljo','inomhusmiljo','pcb','radon','akut','om','kontakt','integritetspolicy'];

    if ($param === 'all') {
        $targets = $all_slugs;
    } else {
        $targets = array_intersect($all_slugs, array_map('trim', explode(',', $param)));
    }

    $cleared = [];
    foreach ($targets as $slug) {
        if (delete_option('__fs_inj_' . $slug . '_v1')) $cleared[] = $slug;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo wp_json_encode(['cleared' => $cleared, 'targets' => array_values($targets), 'ts' => date('c')], JSON_UNESCAPED_UNICODE);
    exit;
}, 1);
