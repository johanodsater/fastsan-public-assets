<?php
/**
 * Plugin Name: Fastsan Force HTTPS
 * Description: Skickar varje sidanrop over http till samma adress over https pa sajtens egen doman, i ett hopp (301).
 * Version: 1.0.0
 * Author: Ai Brick AB
 *
 * Varfor i PHP: public_html/.htaccess ligger utanfor cPanel-bryggans skrivlista, och cPanels egen
 * https-omdirigering (SSL::toggle_ssl_redirect_for_domains) gav ingen effekt pa den har servern
 * (matt 2026-11-23). Tas bort genom att radera filen.
 *
 * Undantag: wp-cron.php (serverns schemalagda anrop foljer inte omdirigeringar), /.well-known/
 * (certifikatfornyelsen provar over http), kommandoraden och aib-deployerns egen adress.
 */

if (!defined('ABSPATH')) return;

(static function () {
    if (PHP_SAPI === 'cli' || (defined('WP_CLI') && WP_CLI)) return;
    if (is_ssl()) return;
    if (empty($_SERVER['REQUEST_URI']) || empty($_SERVER['HTTP_HOST'])) return;
    if (isset($_GET['aib_deploy'])) return;

    $uri  = (string) $_SERVER['REQUEST_URI'];
    $path = (string) strtok($uri, '?');
    if (strpos($path, '/.well-known/') === 0) return;
    if (substr($path, -12) === '/wp-cron.php') return;
    if (strpos($uri, "\r") !== false || strpos($uri, "\n") !== false) return;

    $host = parse_url((string) get_option('home'), PHP_URL_HOST);
    if (!$host) return;
    if ($uri[0] !== '/') $uri = '/' . $uri;

    header('X-Redirect-By: fastsan-force-https');
    header('Location: https://' . $host . $uri, true, 301);
    exit;
})();
