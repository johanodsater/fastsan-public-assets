<?php
/**
 * Plugin Name: AIB GA4 Tag (Consent Mode v2)
 * Description: Generisk GA4-tagg för alla AIB-kundsajter. Mät-ID läses ur wp_option aib_ga4_id (eller konstanten AIB_GA4_ID). Tomt ID = ingen utdata. Startar i nekat läge (cookiefria pingar); CookieYes-cookien (cookieyes-consent) styr uppdatering: analytics → analytics_storage, advertisement → ad_*, functional → functionality/personalization. Inloggade användare, admin, feeds och förhandsvisningar mäts inte.
 * Version: 1.1.1
 *
 * v1.1.1 (B, 2026-11-23, ägarorder Johan 22:30 "7: Ja"): Googles kakor (_ga, _ga_<id>) sätts med flaggan Secure
 * (cookie_flags i config). Mätt före: secure=false.
 * v1.1.0 (B, 2026-11-23, ägarorder Johan 19:08 "kör 5"): valbart läge "vänta på samtycke". Sätts med konstanten
 * AIB_GA4_WAIT_FOR_CONSENT eller wp_option aib_ga4_wait_for_consent. I det läget hämtas gtag.js inte och inget
 * skickas till Google förrän CookieYes-cookien säger action:yes och analytics:yes. Utan inställningen är
 * beteendet oförändrat (cookiefria pingar i nekat läge).
 * v1.0.1 (C, 2026-10-19): när besökaren godkänner analys via bannern skickas page_view
 * en gång till med samtycke. Förut skickades sidans enda page_view före klicket (gcs=G100)
 * och sändes aldrig om, så enkelsidesbesök tappade landningssida och källa. Dubbla
 * consent-update (event + klick) avdubblas.
 * Author: Ai Brick AB
 */

if (!defined('ABSPATH')) return;
if (defined('AIB_GA4_TAG_LOADED')) return;
define('AIB_GA4_TAG_LOADED', '1.1.1');
if (!defined('__FASTSAN_GA4_LOADED')) define('__FASTSAN_GA4_LOADED', true); // övergång: stoppar äldre fastsan-ga4.php

function aib_ga4_tag_id() {
    $id = defined('AIB_GA4_ID') ? (string) AIB_GA4_ID : (string) get_option('aib_ga4_id', '');
    $id = strtoupper(trim($id));
    return preg_match('/^G-[A-Z0-9]{6,12}$/', $id) ? $id : '';
}

function aib_ga4_wait_for_consent() {
    if (defined('AIB_GA4_WAIT_FOR_CONSENT')) return (bool) AIB_GA4_WAIT_FOR_CONSENT;
    return (bool) get_option('aib_ga4_wait_for_consent', false);
}

add_action('wp_head', static function () {
    if (is_admin() || is_user_logged_in() || is_feed() || is_preview()) return;
    $id = aib_ga4_tag_id();
    if ($id === '') return;
    if (aib_ga4_wait_for_consent()) {
        ?>
<!-- AIB GA4 / laddas först vid analytics-samtycke -->
<script>
window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',functionality_storage:'denied',personalization_storage:'denied',security_storage:'granted'});
(function(){
var GID='<?php echo esc_js($id); ?>',started=false,last='';
function rd(){var m=document.cookie.match(/(?:^|;\s*)cookieyes-consent=([^;]*)/);if(!m)return null;var o={};decodeURIComponent(m[1]).split(',').forEach(function(p){var kv=p.split(':');if(kv.length===2)o[kv[0].trim()]=kv[1].trim();});return o;}
function start(){if(started)return;started=true;gtag('js',new Date());gtag('config',GID,{cookie_flags:'SameSite=Lax;Secure'});var s=document.createElement('script');s.async=true;s.src='https://www.googletagmanager.com/gtag/js?id='+GID;document.head.appendChild(s);}
function ap(o){if(!o||o.action!=='yes')return;var a=o.analytics==='yes',ad=o.advertisement==='yes',f=o.functional==='yes';var k=[a,ad,f].join();if(k===last)return;last=k;gtag('consent','update',{analytics_storage:a?'granted':'denied',ad_storage:ad?'granted':'denied',ad_user_data:ad?'granted':'denied',ad_personalization:ad?'granted':'denied',functionality_storage:f?'granted':'denied',personalization_storage:f?'granted':'denied'});if(a)start();}
ap(rd());
document.addEventListener('cookieyes_consent_update',function(){setTimeout(function(){ap(rd());},50);});
document.addEventListener('click',function(e){var t=e.target;if(t&&t.closest&&t.closest('.cky-btn'))setTimeout(function(){ap(rd());},300);},true);
})();
</script>
        <?php
        return;
    }
    ?>
<!-- AIB GA4 / Consent Mode v2 -->
<script>
window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',functionality_storage:'denied',personalization_storage:'denied',security_storage:'granted',wait_for_update:500});
gtag('js',new Date());
gtag('config','<?php echo esc_js($id); ?>',{cookie_flags:'SameSite=Lax;Secure'});
(function(){
function rd(){var m=document.cookie.match(/(?:^|;\s*)cookieyes-consent=([^;]*)/);if(!m)return null;var o={};decodeURIComponent(m[1]).split(',').forEach(function(p){var kv=p.split(':');if(kv.length===2)o[kv[0].trim()]=kv[1].trim();});return o;}
var last='',aOn=null;
function ap(o,live){if(!o||o.action!=='yes')return;var a=o.analytics==='yes',ad=o.advertisement==='yes',f=o.functional==='yes';var k=[a,ad,f].join();if(k===last)return;last=k;var was=aOn;aOn=a;gtag('consent','update',{analytics_storage:a?'granted':'denied',ad_storage:ad?'granted':'denied',ad_user_data:ad?'granted':'denied',ad_personalization:ad?'granted':'denied',functionality_storage:f?'granted':'denied',personalization_storage:f?'granted':'denied'});if(live&&a&&!was)gtag('event','page_view');}
ap(rd(),false);
document.addEventListener('cookieyes_consent_update',function(){setTimeout(function(){ap(rd(),true);},50);});
document.addEventListener('click',function(e){var t=e.target;if(t&&t.closest&&t.closest('.cky-btn'))setTimeout(function(){ap(rd(),true);},300);},true);
})();
</script>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr($id); ?>"></script>
    <?php
}, 1);
