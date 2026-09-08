<?php
/**
 * Plugin Name: AIB Meta Pixel (samtyckesgrindad)
 * Description: Generisk Meta-pixel för AIB-kundsajter. Pixel-ID läses ur wp_option aib_meta_pixel_id (eller konstanten AIB_META_PIXEL_ID). Tomt ID = ingen utdata. Till skillnad från GA4 finns ingen äkta Consent Mode i Metas pixel — därför laddas fbevents.js INTE alls förrän CookieYes-cookien (cookieyes-consent) ger advertisement:yes. Advanced Matching är avstängd via autoConfig:false (ägarbeslut 2026-09-07). Inloggade användare, admin, feeds och förhandsvisningar mäts inte.
 * Version: 1.0.0
 * Author: Ai Brick AB
 *
 * Designnot: ingen <noscript>-fallback. Metas standardsnutt lägger en <img>-pixel
 * i noscript som laddas utan samtycke — den är medvetet utelämnad här.
 */

if (!defined('ABSPATH')) return;
if (defined('AIB_META_PIXEL_LOADED')) return;
define('AIB_META_PIXEL_LOADED', '1.0.0');

function aib_meta_pixel_id() {
    $id = defined('AIB_META_PIXEL_ID') ? (string) AIB_META_PIXEL_ID : (string) get_option('aib_meta_pixel_id', '');
    $id = trim($id);
    return preg_match('/^[0-9]{10,20}$/', $id) ? $id : '';
}

add_action('wp_head', static function () {
    if (is_admin() || is_user_logged_in() || is_feed() || is_preview()) return;
    $id = aib_meta_pixel_id();
    if ($id === '') return;
    ?>
<!-- AIB Meta Pixel — laddas först vid advertisement-samtycke, utan Advanced Matching -->
<script>
(function(){
var PID='<?php echo esc_js($id); ?>';var started=false;
function rd(){var m=document.cookie.match(/(?:^|;\s*)cookieyes-consent=([^;]*)/);if(!m)return null;var o={};decodeURIComponent(m[1]).split(',').forEach(function(p){var kv=p.split(':');if(kv.length===2)o[kv[0].trim()]=kv[1].trim();});return o;}
function granted(o){return !!o&&o.action==='yes'&&o.advertisement==='yes';}
function start(){
if(started)return;started=true;
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('set','autoConfig',false,PID);
fbq('init',PID);
fbq('track','PageView');
}
function chk(){if(granted(rd()))start();}
chk();
document.addEventListener('cookieyes_consent_update',function(){setTimeout(chk,50);});
document.addEventListener('click',function(e){var t=e.target;if(t&&t.closest&&t.closest('.cky-btn'))setTimeout(chk,300);},true);
})();
</script>
    <?php
}, 2);
