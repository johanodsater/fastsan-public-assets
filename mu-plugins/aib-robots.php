<?php
/**
 * Plugin Name: AIB Robots
 * Description: Generisk robots-hållning för AIB-kundsajter. (1) robots.txt: behåller WP/Rank Math-raderna och lägger till explicita Allow-block för sök-/grounding- och tränings-crawlers samt pekare till /llms.txt (om filen svarar). (2) Tunna arkiv (kategori, tagg, datum, författare) får noindex,follow — de är dubbletter av redaktionella hubbar (t.ex. /sv/category/insikter/ vs /insikter/). Ingen inställning krävs; styr via konstanter AIB_ROBOTS_AI_ALLOW (bool) och AIB_ROBOTS_NOINDEX_ARCHIVES (bool).
 * Version: 1.0.0
 * Author: Ai Brick AB
 */

if (!defined('ABSPATH')) return;
if (defined('AIB_ROBOTS_LOADED')) return;
define('AIB_ROBOTS_LOADED', '1.0.0');

// (1) robots.txt — AI-crawler-posture (mall: korkort247.se, seo-audit HR-8).
add_filter('robots_txt', static function ($output, $public) {
    if (!$public) return $output;
    if (defined('AIB_ROBOTS_AI_ALLOW') && !AIB_ROBOTS_AI_ALLOW) return $output;
    if (stripos($output, 'User-agent: OAI-SearchBot') !== false) return $output; // redan tillagt av annat lager
    $bots = [
        'GPTBot', 'OAI-SearchBot', 'ChatGPT-User',
        'ClaudeBot', 'anthropic-ai', 'Claude-Web', 'Claude-SearchBot', 'Claude-User',
        'PerplexityBot', 'Perplexity-User',
        'Google-Extended', 'Applebot-Extended', 'CCBot',
    ];
    $llms = home_url('/llms.txt');
    $block = "\n# AI-crawlers valkomna - kontext for AI-svarsmotorer: {$llms}\n";
    foreach ($bots as $b) {
        $block .= "User-agent: {$b}\nAllow: /\n\n";
    }
    return rtrim($output) . "\n" . $block;
}, 20, 2);

// (2) Tunna arkiv -> noindex,follow. Egen meta-tagg (Rank Math saknar output pa dessa mallar i FSE-teman), plus Rank Math-filtret om det finns.
function aib_robots_is_thin_archive() {
    if (defined('AIB_ROBOTS_NOINDEX_ARCHIVES') && !AIB_ROBOTS_NOINDEX_ARCHIVES) return false;
    return is_category() || is_tag() || is_date() || is_author();
}
add_filter('rank_math/frontend/robots', static function ($robots) {
    if (!aib_robots_is_thin_archive()) return $robots;
    $robots['index'] = 'noindex';
    $robots['follow'] = 'follow';
    return $robots;
}, 20);
add_action('wp_head', static function () {
    if (!aib_robots_is_thin_archive()) return;
    // Alltid egen tagg: matt 2026-09-06 att Rank Math varken skriver robots eller canonical pa kategorimallen (FSE). Dubblett med samma varde ar ofarlig.
    echo '<meta name="robots" content="noindex, follow" />' . "\n";
}, 1);
