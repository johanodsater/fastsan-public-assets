<?php
/**
 * Plugin Name: Fastsan — Schema Injector
 * Description: JSON-LD-schema per tjänstesida (Service) + BreadcrumbList + FAQPage. Kompletterar Organization/LocalBusiness som temat redan injicerar. Hooks: wp_head prio 99 (efter befintlig Organization). Inga exit/die-patterns (KK247-incident 2026-05-04). Avaktivering: radera filen från mu-plugins/, eller definiera FASTSAN_SCHEMA_DISABLED i wp-config.php.
 * Version: 1.8.1
 * Author: Aibrick (C-instance)
 *
 * v1.8.1 (2026-11-26, B): baskursen har utfardare Corroventa Avfuktning AB (Johan 17:40 "Kor K4").
 * v1.8.0 (2026-11-26, B): Person med hasCredential pa /kompetens/ (Johan 2026-11-26 14:14 "kor byte").
 * v1.6.0 (2026-10-20, C): FAQ:n ur _fastsan_faqs renderas SYNLIGT pa sidan (H2 Vanliga fragor + H3/P per par), infogad fore sidans sista wp:html-CTA. Samma kalla som FAQPage-schemat, sa schema = text alltid (seo-audit HR-11 Hidden Schema, B 2026-10-20 C2, agarbeslut (a)). Hook the_content prio 8 = fore do_blocks, bara huvudfragan.
 * v1.5.0 (2026-09-22, C): BreadcrumbList följer sidhierarkin (Hem > förälder > sida) i stället för att alltid hoppa över föräldern. Namnet = sidtiteln före första " — " eller " | " (SEO-svansen bort). Sidor som själva bär en BreadcrumbList i post_content får ingen från injektorn — sidans lista gäller (en lista per sida).
 * v1.4.0 (2026-09-06, C): ren version — GA4 lever nu i fristående mu-plugins/fastsan-ga4.php och deploys går via mu-plugins/aib-deployer.php (HMAC + sha256, commit-pinnat). v1.3.x (GA4-block + bootstrap) var tillfälliga bärare medan bryggans file_write låg nere.
 * v1.2.1 (2026-09-05, C): ISO/IEC 17025-påståendet struket ur provtagning-description (ägarbeslut 2026-09-05; overifierbart, samma klass som "ackrediterat ISO 17025-lab"). Deploy via github-pull.
 * v1.2.0 (2026-09-05, C): aldrig-frasen utbytt mot "Vi utför ingen sanering" i två Service-descriptions (inomhusmiljo, luktutredning) (ägarbeslut: frasen får inte användas). Deploy via github-pull.
 */

if (!defined('ABSPATH')) { return; }
if (defined('FASTSAN_SCHEMA_DISABLED') && FASTSAN_SCHEMA_DISABLED) { return; }
if (defined('FASTSAN_SCHEMA_LOADED')) { return; }
define('FASTSAN_SCHEMA_LOADED', '1.8.1');

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
 * Kort namn för en brödsmula: sidtiteln före första " — " eller " | ".
 * Titlarna bär SEO-svansar ("Jordprov — markmiljöprovtagning Stockholm | MIFO fas 2").
 */
function fastsan_schema_crumb_name($p) {
    $t = trim(html_entity_decode(wp_strip_all_tags($p->post_title), ENT_QUOTES, 'UTF-8'));
    $parts = preg_split('/\s+[\x{2014}|]\s+/u', $t, 2);
    $n = is_array($parts) ? trim($parts[0]) : '';
    return $n !== '' ? $n : $t;
}

/**
 * Bygg BreadcrumbList för alla sidor utom front-page: Hem > förfäder > sidan.
 * v1.5.0: sidor med egen BreadcrumbList i post_content hoppas över (sidans lista gäller).
 */
function fastsan_schema_build_breadcrumb($post) {
    if (is_front_page()) return null;
    if (strpos((string) $post->post_content, 'BreadcrumbList') !== false) return null;
    $home  = home_url('/');
    $chain = [];
    foreach (array_reverse(get_post_ancestors($post)) as $aid) {
        $a = get_post($aid);
        if ($a && $a->post_status === 'publish') $chain[] = $a;
    }
    $chain[] = $post;
    $items = [[
        '@type'    => 'ListItem',
        'position' => 1,
        'name'     => 'Hem',
        'item'     => $home,
    ]];
    $pos = 2;
    foreach ($chain as $p) {
        $items[] = [
            '@type'    => 'ListItem',
            'position' => $pos++,
            'name'     => fastsan_schema_crumb_name($p),
            'item'     => get_permalink($p),
        ];
    }
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
 * v1.7.0 (2026-11-23, QA-planen punkt 9): Article for blogginlagg. Mätt: /asbest-2025-nya-gransvardet/ hade bara
 * ProfessionalService. Rubrik, datum och bild finns synliga på sidan. Författare och utgivare pekar på
 * bolagets nod (#organization, __fs-seo-supp.php).
 */
function fastsan_schema_build_article($post) {
    if (!$post || empty($post->ID)) return null;
    $home = home_url('/');
    $url  = get_permalink($post);
    $data = [
        '@context'         => 'https://schema.org',
        '@type'            => 'Article',
        '@id'              => $url . '#article',
        'mainEntityOfPage' => $url,
        'headline'         => wp_strip_all_tags(get_the_title($post)),
        'datePublished'    => get_post_time('c', false, $post),
        'dateModified'     => get_post_modified_time('c', false, $post),
        'inLanguage'       => 'sv-SE',
        'author'           => ['@id' => $home . '#organization'],
        'publisher'        => ['@id' => $home . '#organization'],
    ];
    $img = get_the_post_thumbnail_url($post, 'full');
    if ($img) $data['image'] = $img;
    return $data;
}

/**
 * v1.8.0 — Person med intygen pa /kompetens/ (B 2026-11-26, Johan 14:14 "kor byte").
 * Samma uppgifter som syns pa sidan: namn, utbildare och datum. Samma @id som temats
 * founder-nod (#daniel), sa noderna avser samma person. Ingen jobTitle har: temats nod bar den.
 */
function fastsan_schema_build_person($post) {
    if (!$post || 'kompetens' !== $post->post_name) return null;
    $inf  = array('@type' => 'Organization', 'name' => 'Instruktörerna i Norden AB');
    $corr = array('@type' => 'Organization', 'name' => 'Corroventa Avfuktning AB');
    $c = function ($name, $by = null, $date = null, $extra = array()) {
        $n = array('@type' => 'EducationalOccupationalCredential', 'name' => $name, 'credentialCategory' => 'certificate');
        if ($by)   $n['recognizedBy'] = $by;
        if ($date) $n['dateCreated'] = $date;
        return array_merge($n, $extra);
    };
    return array(
        '@context'    => 'https://schema.org',
        '@type'       => 'Person',
        '@id'         => 'https://fastsan.se/#daniel',
        'name'        => 'Daniel Stålbrand',
        'url'         => 'https://fastsan.se/kompetens/',
        'image'       => 'https://fastsan.se/wp-content/uploads/fastsan-content/img/fastsan-daniel-stalbrand-portratt-150x150.webp',
        'worksFor'    => array('@id' => 'https://fastsan.se/#organization'),
        'hasCredential' => array(
            $c('Material-/miljöinventering av byggnader', array('@type' => 'Organization', 'name' => 'SIFU AB'), '2024-08-28',
               array('url' => 'https://true.sifu.se/se/utbildningsbevis-sifu-daniel-stalbrand-195389/')),
            $c('Särskild utbildning Asbest', $inf, '2016-05-19'),
            $c('Kompletterande utbildning Asbest', $inf, '2022-04-19', array('validUntil' => '2027-04-19')),
            $c('Sanering och inventering av PCB', $inf, '2022-02-09'),
            $c('Byggarbetsmiljösamordnare Projektering/planering och Utförande (BAS-P)(BAS-U)', $inf, '2023-11-30'),
            $c('Baskurs inom fukt- och torkteknik', $corr),
            $c('Boendemiljö och fasta installationer', $corr, '2023-01-19'),
            $c('Fukt- och torkteknik: Masterkurs', $corr, '2023-04-26'),
        ),
    );
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
    if (is_singular('post')) {
        $article = fastsan_schema_build_article(get_queried_object());
        if ($article) fastsan_schema_output($article);
        return;
    }
    if (!is_singular('page')) return;
    $post = get_queried_object();
    if (!$post || !isset($post->post_name)) return;

    $service = fastsan_schema_build_service($post);
    if ($service) fastsan_schema_output($service);

    $crumb = fastsan_schema_build_breadcrumb($post);
    if ($crumb) fastsan_schema_output($crumb);

    $faq = fastsan_schema_build_faq($post);
    if ($faq) fastsan_schema_output($faq);

    $person = fastsan_schema_build_person($post);
    if ($person) fastsan_schema_output($person);
}, 99);

/**
 * v1.6.0 — Synlig FAQ ur samma meta som schemat. Prio 8 pa the_content = fore do_blocks (9),
 * sa blockmarkup kan infogas fore sidans sista wp:html-block (CTA:n). Saknas en avslutande
 * CTA laggs FAQ:n sist.
 */
function fastsan_schema_faq_blocks($post) {
    $raw = get_post_meta($post->ID, '_fastsan_faqs', true);
    if (empty($raw)) return '';
    $faqs = is_array($raw) ? $raw : json_decode($raw, true);
    if (!is_array($faqs) || empty($faqs)) return '';
    $out = "<!-- wp:heading {\"level\":2,\"anchor\":\"vanliga-fragor\"} -->\n<h2 class=\"wp-block-heading\" id=\"vanliga-fragor\">Vanliga frågor</h2>\n<!-- /wp:heading -->\n\n";
    $n = 0;
    foreach ($faqs as $f) {
        if (empty($f['q']) || empty($f['a'])) continue;
        $q = esc_html(wp_strip_all_tags($f['q']));
        $a = esc_html(wp_strip_all_tags($f['a']));
        $out .= "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">" . $q . "</h3>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>" . $a . "</p>\n<!-- /wp:paragraph -->\n\n";
        $n++;
    }
    return $n ? $out : '';
}
add_filter('the_content', function ($content) {
    if (!is_singular('page') || !in_the_loop() || !is_main_query()) return $content;
    $post = get_post();
    if (!$post) return $content;
    $faq = fastsan_schema_faq_blocks($post);
    if ('' === $faq) return $content;
    $trim = rtrim($content);
    $close = '<!-- /wp:html -->';
    if (substr($trim, -strlen($close)) === $close) {
        $open = strrpos($trim, '<!-- wp:html -->');
        if (false !== $open) {
            return substr($content, 0, $open) . $faq . substr($content, $open);
        }
    }
    return $content . "\n\n" . $faq;
}, 8);

/**
 * Admin-notice så ingen glömmer att pluginen är aktiv.
 */
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->id !== 'dashboard') return;
    echo '<div class="notice notice-info is-dismissible"><p><strong>Fastsan Schema Injector v' . esc_html(FASTSAN_SCHEMA_LOADED) . ' aktiv.</strong> Service-schema injicerar på 9 tjänstesidor. Verifiera via <a href="https://search.google.com/test/rich-results" target="_blank" rel="noopener">Google Rich Results Test</a>.</p></div>';
});
