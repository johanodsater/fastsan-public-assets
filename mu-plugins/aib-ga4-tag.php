<?php
/**
 * Plugin Name: AIB GA4 Tag (Consent Mode v2)
 * Description: Generisk GA4-tagg för alla AIB-kundsajter. Mät-ID läses ur wp_option aib_ga4_id (eller konstanten AIB_GA4_ID). Tomt ID = ingen utdata. Startar i nekat läge (cookiefria pingar); CookieYes-cookien (cookieyes-consent) styr uppdatering: analytics → analytics_storage, advertisement → ad_*, functional → functionality/personalization. Inloggade användare, admin, feeds och förhandsvisningar mäts inte.
 * Version: 1.0.0
 * Author: Ai Brick AB
 */

if (!defined('ABSPATH')) return;
if (defined('AIB_GA4_TAG_LOADED')) return;
define('AIB_GA4_TAG_LOADED', '1.0.0');
if (!defined('__FASTSAN_GA4_LOADED')) define('__FASTSAN_GA4_LOADED', true); // övergång: stoppar äldre fastsan-ga4.php

function aib_ga4_tag_id() {
    $id = defined('AIB_GA4_ID') ? (string) AIB_GA4_ID : (string) get_option('aib_ga4_id', '');
    $id = strtoupper(trim($id));
    return preg_match('/^G-[A-Z0-9]{6,12}$/', $id) ? $id : '';
}

add_action('wp_head', static function () {
    if (is_admin() || is_user_logged_in() || is_feed() || is_preview()) return;
    $id = aib_ga4_tag_id();
    if ($id === '') return;
    ?>
<!-- AIB GA4 / Consent Mode v2 -->
<script>
window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',functionality_storage:'denied',personalization_storage:'denied',security_storage:'granted',wait_for_update:500});
gtag('js',new Date());
gtag('config','<?php echo esc_js($id); ?>');
(function(){
function rd(){var m=document.cookie.match(/(?:^|;\s*)cookieyes-consent=([^;]*)/);if(!m)return null;var o={};decodeURIComponent(m[1]).split(',').forEach(function(p){var kv=p.split(':');if(kv.length===2)o[kv[0].trim()]=kv[1].trim();});return o;}
function ap(o){if(!o||o.action!=='yes')return;var a=o.analytics==='yes',ad=o.advertisement==='yes',f=o.functional==='yes';gtag('consent','update',{analytics_storage:a?'granted':'denied',ad_storage:ad?'granted':'denied',ad_user_data:ad?'granted':'denied',ad_personalization:ad?'granted':'denied',functionality_storage:f?'granted':'denied',personalization_storage:f?'granted':'denied'});}
ap(rd());
document.addEventListener('cookieyes_consent_update',function(){setTimeout(function(){ap(rd());},50);});
document.addEventListener('click',function(e){var t=e.target;if(t&&t.closest&&t.closest('.cky-btn'))setTimeout(function(){ap(rd());},300);},true);
})();
</script>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr($id); ?>"></script>
    <?php
}, 1);
