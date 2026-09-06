<?php
/**
 * Plugin Name: Fastsan GA4 (Consent Mode v2 + CookieYes)
 * Description: Google-taggen G-CB3PLTJ06B i samtyckesläge. Standard = allt nekat (cookiefria pingar, ingen cookie). CookieYes-kategorin "analytics" styr analytics_storage, "advertisement" styr ad_*, "functional" styr functionality/personalization. Inloggade användare mäts inte.
 * Version: 1.0.0
 * Author: Ai Brick AB
 */

if (!defined('ABSPATH')) return;
if (defined('__FASTSAN_GA4_LOADED')) return;
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
