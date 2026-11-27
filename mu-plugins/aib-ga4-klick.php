<?php
/**
 * Plugin Name: AIB GA4 Klick (knappar, länkar, telefon, e-post)
 * Description: Skickar klick till GA4 som händelser: cta_click (cta_typ knapp eller lank), klick_telefon och klick_epost, med parametrarna sektion, link_text och link_url. Skickar bara när besökaren sagt ja till analys i CookieYes-rutan (action:yes och analytics:yes) och GA4-taggen (aib-ga4-tag.php) har laddats. Inget skickas till Google av den här filen: händelserna läggs i gtag, som aib-ga4-tag.php äger. Telefonnummer och e-postadresser skickas aldrig (Googles regel mot personuppgifter i GA4): de ersätts med [nummer] och [e-post]. Inloggade användare, admin, feeds och förhandsvisningar mäts inte. Stängs av med konstanten AIB_GA4_KLICK_AV eller genom att filen tas bort.
 * Version: 1.1.0
 *
 * v1.1.0 (B, 2026-11-27, Johan 12:01 "kör allt", plan S1): klick inne i en guide (.fs-guide) får sektionen guide-<kapitlets ankare>, guide-innehall eller guide-kallor i stället för block-1.
 * v1.0.0 (B, 2026-11-26, ägarorder Johan 10:28 "kör 3"): mätplanen P3 (claude/Fastsan_Matning_Plan_2026-11-25.md).
 * Author: Ai Brick AB
 */

if (!defined('ABSPATH')) return;
if (defined('AIB_GA4_KLICK_LOADED')) return;
define('AIB_GA4_KLICK_LOADED', '1.1.0');

add_action('wp_footer', static function () {
    if (defined('AIB_GA4_KLICK_AV') && AIB_GA4_KLICK_AV) return;
    if (is_admin() || is_user_logged_in() || is_feed() || is_preview()) return;
    if (!function_exists('aib_ga4_tag_id') || aib_ga4_tag_id() === '') return;
    ?>
<!-- AIB GA4 Klick 1.1.0 / bara efter ja till analys -->
<script>
(function(){
var senast={k:'',t:0};
function samtycke(){var m=document.cookie.match(/(?:^|;\s*)cookieyes-consent=([^;]*)/);if(!m)return false;var o={};decodeURIComponent(m[1]).split(',').forEach(function(p){var kv=p.split(':');if(kv.length===2)o[kv[0].trim()]=kv[1].trim();});return o.action==='yes'&&o.analytics==='yes';}
function rensa(s){return String(s||'').replace(/\s+/g,' ').replace(/[^\s@]+@[^\s@]+\.[a-z]{2,}/gi,'[e-post]').replace(/\+?\d[\d\s().-]{5,}\d/g,'[nummer]').trim().slice(0,100);}
function klass(el){return el.getAttribute('class')||'';}
function del(el){
if(el.closest('#fsStickyBar'))return 'kontaktrad';
if(el.closest('.fs-cb-overlay'))return 'uppringning';
if(el.closest('.fs-akut-strip'))return 'akutrad';
if(el.closest('header,.fs-header'))return 'sidhuvud';
if(el.closest('footer'))return 'sidfot';
var gd=el.closest('.fs-guide');if(gd){if(el.closest('.fs-guide-innehall'))return 'guide-innehall';if(el.closest('.fs-guide-kallor'))return 'guide-kallor';var r='',x=gd.firstElementChild;while(x){if(x.tagName==='H2'&&x.id)r=x.id;if(x===el||x.contains(el))break;x=x.nextElementSibling;}return ('guide-'+(r||'topp')).slice(0,40);}
var h=el.closest('.fs-hem');if(h){var m=klass(h).match(/fs-hem-([a-z0-9]+)/);if(m)return 'hem-'+m[1];}
var huvud=el.closest('main')||document.body,c=el;
while(c.parentElement&&c.parentElement!==huvud&&!/(^|\s)(entry-content|wp-block-post-content)(\s|$)/.test(klass(c.parentElement)))c=c.parentElement;
if(c.id)return c.id.slice(0,40);
var i=1,s=c;while((s=s.previousElementSibling))i++;
return 'block-'+i;
}
function adress(href){try{var u=new URL(href,location.href);if(u.host===location.host)return (u.pathname+u.search+u.hash).slice(0,100);return (u.host+u.pathname).slice(0,100);}catch(e){return '';}}
document.addEventListener('click',function(e){
var t=e.target;if(!t||!t.closest)return;
var el=t.closest('a[href],button,[role="button"],input[type="submit"],input[type="button"]');if(!el)return;
if(el.closest('#wpadminbar,[class^="cky-"],[class*=" cky-"]'))return;
if(el.tagName==='BUTTON'&&el.hasAttribute('aria-expanded'))return;
if(!samtycke()||typeof window.gtag!=='function')return;
var href=el.tagName==='A'?(el.getAttribute('href')||''):'';
var text=rensa(el.innerText||el.textContent||el.value||el.getAttribute('aria-label')||el.getAttribute('title'));
var namn,p={sektion:del(el),link_text:text};
if(/^tel:/i.test(href)){namn='klick_telefon';}
else if(/^mailto:/i.test(href)){namn='klick_epost';}
else{namn='cta_click';var knapp=el.tagName!=='A'||el.getAttribute('role')==='button'||/(^|\s)(wp-block-button__link|wp-element-button|button|btn)(\s|$)/.test(klass(el));p.cta_typ=knapp?'knapp':'lank';if(href&&href.charAt(0)!=='#')p.link_url=adress(href);}
var k=namn+'|'+p.sektion+'|'+text,n=Date.now();if(k===senast.k&&n-senast.t<800)return;senast={k:k,t:n};
window.gtag('event',namn,p);
},true);
})();
</script>
    <?php
}, 20);
