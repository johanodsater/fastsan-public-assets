<?php
/**
 * Plugin Name: AIB Klickkarta (Fastsans egen klickkarta)
 * Description: Sparar klick och hur långt besökaren rullade i en egen tabell i sajtens databas, och ritar dem ovanpå sidan i Verktyg → Klickkarta. Inga kakor, inget besökar-id, inget sparat i webbläsaren och ingen IP-adress sparas. Mäter alla besökare utan samtycke (Johans beslut B1 A, 2026-11-26). Klick från Johans och kontorets adresser (samma som IP-regeln i Taggstyrning) sparas inte; adressen används bara i jämförelsen. Gallring efter 24 månader. Spärr: skriptet laddas bara när wp_option aib_klickkarta_live är 1, eller med provnyckeln i adressen (?aib_klickprov=<nyckel>, visas i Verktyg → Klickkarta). Provklick märks prov=1 och gallras efter 30 dagar. Stängs av med konstanten AIB_KLICKKARTA_AV eller genom att filen tas bort; tabellen ligger kvar.
 * Version: 1.0.0
 *
 * v1.0.0 (B, 2026-11-26, ägarorder Johan 11:07 "kör 4"): mätplanen P4 (claude/Fastsan_Matning_Plan_2026-11-25.md),
 *   besluten B1 A (alla besökare), B2 A (visning i WP-admin), B3 24 månader, B4 nej.
 * Author: Ai Brick AB
 */

if (!defined('ABSPATH')) return;
if (defined('AIB_KLICKKARTA_LOADED')) return;
define('AIB_KLICKKARTA_LOADED', '1.0.0');

const AIB_KLICKKARTA_TABELL = 'aib_klick';
const AIB_KLICKKARTA_DB = '1';
const AIB_KLICKKARTA_MANADER = 24;
const AIB_KLICKKARTA_PROV_DAGAR = 30;
const AIB_KLICKKARTA_CRON = 'aib_klickkarta_gallra';
const AIB_KLICKKARTA_TAK_PER_MINUT = 3000;

function aib_klickkarta_av() {
    return defined('AIB_KLICKKARTA_AV') && AIB_KLICKKARTA_AV;
}

function aib_klickkarta_tabell() {
    global $wpdb;
    return $wpdb->prefix . AIB_KLICKKARTA_TABELL;
}

function aib_klickkarta_live() {
    return (string) get_option('aib_klickkarta_live', '0') === '1';
}

/** Provnyckeln: 32 tecken ur HMAC-SHA256(aib_deployer_secret, "fs-klickkarta"). Tom utan hemlighet = provläget av. */
function aib_klickkarta_provnyckel() {
    $s = (string) get_option('aib_deployer_secret', '');
    return strlen($s) >= 32 ? substr(hash_hmac('sha256', 'fs-klickkarta', $s), 0, 32) : '';
}

function aib_klickkarta_ar_prov($nyckel) {
    $n = aib_klickkarta_provnyckel();
    return $n !== '' && is_string($nyckel) && hash_equals($n, $nyckel);
}

/** Samma adresser som IP-regeln "Johan och DT" i Taggstyrning (version 2, 2026-11-26). */
function aib_klickkarta_interna_natverk() {
    return (array) apply_filters('aib_klickkarta_interna_natverk', [
        '176.10.159.49/32',
        '217.31.160.174/32',
        '2001:9b1:4012:9401::/64',
    ]);
}

function aib_klickkarta_ip_i_natet($ip, $cidr) {
    $delar = explode('/', (string) $cidr, 2);
    $a = @inet_pton((string) $ip);
    $b = @inet_pton($delar[0]);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;
    $bitar = isset($delar[1]) ? max(0, min(strlen($a) * 8, (int) $delar[1])) : strlen($a) * 8;
    $hela = intdiv($bitar, 8);
    $rest = $bitar % 8;
    if ($hela > 0 && substr($a, 0, $hela) !== substr($b, 0, $hela)) return false;
    if ($rest > 0) {
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        if ((ord($a[$hela]) & $mask) !== (ord($b[$hela]) & $mask)) return false;
    }
    return true;
}

function aib_klickkarta_ar_intern($ip) {
    foreach (aib_klickkarta_interna_natverk() as $n) {
        if (aib_klickkarta_ip_i_natet($ip, $n)) return true;
    }
    return false;
}

/* ---------- Tabellen ---------- */

function aib_klickkarta_skapa_tabell() {
    global $wpdb;
    $t = aib_klickkarta_tabell();
    $charset = $wpdb->get_charset_collate();
    // dbDelta: exakt två blanksteg efter PRIMARY KEY.
    $sql = "CREATE TABLE $t (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        tid DATETIME NOT NULL,
        typ CHAR(1) NOT NULL DEFAULT 'k',
        sida VARCHAR(191) NOT NULL DEFAULT '/',
        bredd SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        x SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        y INT UNSIGNED NOT NULL DEFAULT 0,
        yf SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        sidhojd INT UNSIGNED NOT NULL DEFAULT 0,
        elem VARCHAR(100) NOT NULL DEFAULT '',
        text VARCHAR(100) NOT NULL DEFAULT '',
        sektion VARCHAR(40) NOT NULL DEFAULT '',
        dod TINYINT(1) NOT NULL DEFAULT 0,
        prov TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY sida_tid (sida, tid),
        KEY tid (tid)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option('aib_klickkarta_db', AIB_KLICKKARTA_DB, true);
}

add_action('init', static function () {
    if (aib_klickkarta_av()) return;
    if ((string) get_option('aib_klickkarta_db', '') !== AIB_KLICKKARTA_DB) aib_klickkarta_skapa_tabell();
    if (!wp_next_scheduled(AIB_KLICKKARTA_CRON)) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', AIB_KLICKKARTA_CRON);
}, 5);

/* ---------- Gallring ---------- */

add_action(AIB_KLICKKARTA_CRON, static function () {
    global $wpdb;
    $t = aib_klickkarta_tabell();
    $a = (int) $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE tid < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d MONTH)", AIB_KLICKKARTA_MANADER));
    $b = (int) $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE prov = 1 AND tid < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)", AIB_KLICKKARTA_PROV_DAGAR));
    if ($a + $b > 0) error_log('[AIB] klickkarta gallring: ' . $a . ' gamla, ' . $b . ' prov');
});

/* ---------- Mottagaren: POST /?aib_klick=1 ---------- */

function aib_klickkarta_svara($kod, $data = null) {
    status_header($kod);
    nocache_headers();
    if ($data !== null) {
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode($data);
    }
    exit;
}

function aib_klickkarta_samma_vard() {
    $hem = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    $hem = preg_replace('/^www\./', '', $hem);
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
        if (empty($_SERVER[$h])) continue;
        $v = strtolower((string) wp_parse_url(wp_unslash($_SERVER[$h]), PHP_URL_HOST));
        return preg_replace('/^www\./', '', $v) === $hem;
    }
    return false;
}

function aib_klickkarta_str($v, $max) {
    $v = is_scalar($v) ? (string) $v : '';
    $v = sanitize_text_field($v);
    return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
}

function aib_klickkarta_int($v, $min, $max) {
    $v = is_numeric($v) ? (int) round((float) $v) : $min;
    return max($min, min($max, $v));
}

add_action('init', static function () {
    if (!isset($_GET['aib_klick'])) return;
    if (aib_klickkarta_av()) aib_klickkarta_svara(204);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') aib_klickkarta_svara(405);
    if (!aib_klickkarta_samma_vard()) aib_klickkarta_svara(403);
    $ra = file_get_contents('php://input', false, null, 0, 16385);
    if (!is_string($ra) || $ra === '' || strlen($ra) > 16384) aib_klickkarta_svara(413);
    $d = json_decode($ra, true);
    if (!is_array($d) || (int) ($d['v'] ?? 0) !== 1) aib_klickkarta_svara(400);

    $prov = aib_klickkarta_ar_prov($d['p'] ?? '');
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $intern = aib_klickkarta_ar_intern($ip);
    if (!$prov && (!aib_klickkarta_live() || $intern)) aib_klickkarta_svara(204);

    $nyckel = 'aib_klick_min_' . gmdate('YmdHi');
    $antal = (int) get_transient($nyckel);
    if ($antal > AIB_KLICKKARTA_TAK_PER_MINUT) aib_klickkarta_svara(429);

    $sida = (string) wp_parse_url('/' . ltrim(aib_klickkarta_str($d['s'] ?? '/', 191), '/'), PHP_URL_PATH);
    if ($sida === '') $sida = '/';
    $bredd = aib_klickkarta_int($d['w'] ?? 0, 0, 10000);
    $sidhojd = aib_klickkarta_int($d['dh'] ?? 0, 0, 1000000);
    $tid = gmdate('Y-m-d H:i:s');
    $rader = [];

    $klick = isset($d['k']) && is_array($d['k']) ? array_slice($d['k'], 0, 50) : [];
    foreach ($klick as $k) {
        if (!is_array($k) || count($k) < 7) continue;
        $rader[] = ['k', aib_klickkarta_int($k[0], 0, 10000), aib_klickkarta_int($k[1], 0, 1000000), aib_klickkarta_int($k[2], 0, 10000),
            aib_klickkarta_str($k[3], 100), aib_klickkarta_str($k[4], 100), aib_klickkarta_str($k[5], 40), empty($k[6]) ? 0 : 1];
    }
    if (isset($d['r'])) {
        $rf = aib_klickkarta_int($d['r'], 0, 10000);
        $rader[] = ['r', 0, (int) round($rf / 10000 * $sidhojd), $rf, '', '', '', 0];
    }
    if (!$rader) aib_klickkarta_svara($prov ? 200 : 204, $prov ? ['ok' => true, 'sparade' => 0, 'intern' => $intern] : null);

    global $wpdb;
    $t = aib_klickkarta_tabell();
    $varden = [];
    $plats = [];
    foreach ($rader as $r) {
        $plats[] = '(%s,%s,%s,%d,%d,%d,%d,%d,%s,%s,%s,%d,%d)';
        array_push($varden, $tid, $r[0], $sida, $bredd, $r[1], $r[2], $r[3], $sidhojd, $r[4], $r[5], $r[6], $r[7], $prov ? 1 : 0);
    }
    $sql = "INSERT INTO $t (tid,typ,sida,bredd,x,y,yf,sidhojd,elem,text,sektion,dod,prov) VALUES " . implode(',', $plats);
    $ok = $wpdb->query($wpdb->prepare($sql, $varden));
    set_transient($nyckel, $antal + count($rader), 120);
    if ($prov) aib_klickkarta_svara(200, ['ok' => $ok !== false, 'sparade' => (int) $ok, 'intern' => $intern]);
    aib_klickkarta_svara(204);
}, 1);

/* ---------- Skriptet i sidan ---------- */

add_action('wp_footer', static function () {
    if (aib_klickkarta_av()) return;
    if (is_admin() || is_user_logged_in() || is_feed() || is_preview()) return;
    $prov = isset($_GET['aib_klickprov']) && aib_klickkarta_ar_prov(wp_unslash($_GET['aib_klickprov']));
    if (!$prov && !aib_klickkarta_live()) return;
    $p = $prov ? aib_klickkarta_provnyckel() : '';
    ?>
<!-- AIB Klickkarta 1.0.0 / inga kakor, inget besökar-id -->
<script>
(function(){
var U=<?php echo wp_json_encode(home_url('/?aib_klick=1')); ?>,P=<?php echo wp_json_encode($p); ?>,q=[],maxr=0,klar=false;
function rensa(s){return String(s||'').replace(/\s+/g,' ').replace(/[^\s@]+@[^\s@]+\.[a-z]{2,}/gi,'[e-post]').replace(/\+?\d[\d\s().-]{5,}\d/g,'[nummer]').trim().slice(0,60);}
function klass(el){return (el.getAttribute&&el.getAttribute('class'))||'';}
function del(el){
if(el.closest('#fsStickyBar'))return 'kontaktrad';
if(el.closest('.fs-cb-overlay'))return 'uppringning';
if(el.closest('.fs-akut-strip'))return 'akutrad';
if(el.closest('[class^="cky-"],[class*=" cky-"]'))return 'kakruta';
if(el.closest('header,.fs-header'))return 'sidhuvud';
if(el.closest('footer'))return 'sidfot';
var h=el.closest('.fs-hem');if(h){var m=klass(h).match(/fs-hem-([a-z0-9]+)/);if(m)return 'hem-'+m[1];}
var huvud=el.closest('main')||document.body,c=el;
while(c.parentElement&&c.parentElement!==huvud&&!/(^|\s)(entry-content|wp-block-post-content)(\s|$)/.test(klass(c.parentElement)))c=c.parentElement;
if(c.id)return c.id.slice(0,40);
var i=1,s=c;while((s=s.previousElementSibling))i++;
return 'block-'+i;
}
function besk(el){var t=el.tagName.toLowerCase();if(el.id)return (t+'#'+el.id).slice(0,100);var c=klass(el).trim().split(/\s+/).filter(function(x){return x&&!/^(is-|has-|wp-container|wp-elements)/.test(x);})[0];return (c?t+'.'+c:t).slice(0,100);}
function mal(el){return el.closest('a[href],button,input,select,textarea,label,summary,[role="button"],[role="link"],[onclick],[tabindex]:not([tabindex="-1"])');}
function dok(){var d=document.documentElement,b=document.body;return {w:Math.max(d.scrollWidth,b?b.scrollWidth:0,1),h:Math.max(d.scrollHeight,b?b.scrollHeight:0,1)};}
function skicka(slut){
var m=dok(),data={v:1,s:location.pathname.slice(0,191),w:window.innerWidth,dh:m.h,p:P,k:q.splice(0,50)};
if(slut&&!klar){data.r=Math.min(10000,Math.round(maxr/m.h*10000));klar=true;}
if(!data.k.length&&data.r===undefined)return;
var b=JSON.stringify(data),ok=false;
try{ok=!!(navigator.sendBeacon&&navigator.sendBeacon(U,new Blob([b],{type:'text/plain'})));}catch(e){}
if(!ok){try{fetch(U,{method:'POST',body:b,keepalive:true,credentials:'same-origin',headers:{'Content-Type':'text/plain'}});}catch(e){}}
if(q.length)skicka(false);
}
function rull(){var r=(window.scrollY||window.pageYOffset||0)+window.innerHeight;if(r>maxr)maxr=r;}
rull();addEventListener('scroll',rull,{passive:true});
document.addEventListener('click',function(e){
var t=e.target;if(!t||!t.closest||t.closest('#wpadminbar'))return;
if(e.detail===0&&e.clientX===0&&e.clientY===0)return;
var m=dok(),el=mal(t),vis=el||t;
var x=Math.round(e.pageX/m.w*10000);if(!(x>=0))return;
var txt=/^(INPUT|TEXTAREA|SELECT)$/.test(vis.tagName)||(!el&&vis.children.length)?'':rensa(vis.innerText||vis.textContent);
q.push([Math.min(10000,x),Math.round(e.pageY),Math.min(10000,Math.round(e.pageY/m.h*10000)),besk(vis),txt,del(vis),el?0:1]);
if(q.length>=20)skicka(false);
},true);
addEventListener('pagehide',function(){skicka(true);});
document.addEventListener('visibilitychange',function(){if(document.visibilityState==='hidden')skicka(true);});
})();
</script>
    <?php
}, 30);

/* ---------- Visningen: Verktyg → Klickkarta ---------- */

add_action('init', static function () {
    if (isset($_GET['aib_klickvy']) && is_user_logged_in()) add_filter('show_admin_bar', '__return_false');
}, 20);

add_action('admin_menu', static function () {
    if (aib_klickkarta_av()) return;
    add_management_page('Klickkarta', 'Klickkarta', 'manage_options', 'aib-klickkarta', 'aib_klickkarta_visning');
});

add_action('admin_post_aib_klickkarta_tom_prov', static function () {
    if (!current_user_can('manage_options')) wp_die('Nekad', 403);
    check_admin_referer('aib_klickkarta_tom_prov');
    global $wpdb;
    $n = (int) $wpdb->query('DELETE FROM ' . aib_klickkarta_tabell() . ' WHERE prov = 1');
    wp_safe_redirect(add_query_arg(['page' => 'aib-klickkarta', 'tomt' => $n], admin_url('tools.php')));
    exit;
});

function aib_klickkarta_klasser() {
    return [
        'mobil' => ['Mobil (under 768 px)', 0, 767, 390],
        'surfplatta' => ['Surfplatta (768–1199 px)', 768, 1199, 820],
        'dator' => ['Dator (1200 px och bredare)', 1200, 10000, 1400],
    ];
}

function aib_klickkarta_visning() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $t = aib_klickkarta_tabell();
    $klasser = aib_klickkarta_klasser();
    $klass = isset($_GET['klass'], $klasser[$_GET['klass']]) ? sanitize_key($_GET['klass']) : 'dator';
    $dagar = isset($_GET['dagar']) ? max(1, min(730, (int) $_GET['dagar'])) : 30;
    $medprov = !empty($_GET['prov']);
    $provvillkor = $medprov ? '1=1' : 'prov = 0';
    [$knamn, $bmin, $bmax, $vybredd] = $klasser[$klass];

    $sidor = $wpdb->get_results($wpdb->prepare(
        "SELECT sida, SUM(typ='k') AS klick, SUM(typ='r') AS visningar FROM $t WHERE tid >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) AND $provvillkor GROUP BY sida ORDER BY klick DESC LIMIT 100",
        $dagar), ARRAY_A);
    $sida = isset($_GET['sida']) ? (string) wp_unslash($_GET['sida']) : ($sidor[0]['sida'] ?? '/');
    $sida = '/' . ltrim(sanitize_text_field($sida), '/');

    $bas = $wpdb->prepare("FROM $t WHERE sida = %s AND bredd BETWEEN %d AND %d AND tid >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) AND $provvillkor",
        $sida, $bmin, $bmax, $dagar);
    $klick = $wpdb->get_results("SELECT x, yf, dod $bas AND typ = 'k' ORDER BY id DESC LIMIT 5000", ARRAY_N);
    $element = $wpdb->get_results("SELECT elem, text, sektion, COUNT(*) AS n, SUM(dod) AS doda $bas AND typ = 'k' GROUP BY elem, text, sektion ORDER BY n DESC LIMIT 25", ARRAY_A);
    $sektioner = $wpdb->get_results("SELECT sektion, COUNT(*) AS n, SUM(dod) AS doda $bas AND typ = 'k' GROUP BY sektion ORDER BY n DESC LIMIT 25", ARRAY_A);
    $rull = $wpdb->get_col("SELECT yf $bas AND typ = 'r' LIMIT 20000");
    $visningar = count($rull);
    $totalt = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t");
    $provnyckel = aib_klickkarta_provnyckel();

    $djup = [];
    foreach ([25, 50, 75, 100] as $p) {
        $n = 0;
        foreach ($rull as $r) if ((int) $r >= $p * 100 - 50) $n++;
        $djup[$p] = $visningar ? round($n / $visningar * 100) : 0;
    }

    $url = function ($andra) use ($klass, $dagar, $medprov, $sida) {
        return esc_url(add_query_arg(array_merge(['page' => 'aib-klickkarta', 'klass' => $klass, 'dagar' => $dagar, 'prov' => $medprov ? 1 : null, 'sida' => $sida], $andra), admin_url('tools.php')));
    };
    ?>
    <div class="wrap aib-kk">
      <h1>Klickkarta</h1>
      <p>
        Läge: <strong><?php echo aib_klickkarta_live() ? 'live – alla besökare mäts' : 'spärr – bara provklick'; ?></strong>.
        Inga kakor, inget besökar-id och ingen IP-adress sparas. Klick från Johans och kontorets adresser sparas inte.
        Klicken gallras efter <?php echo (int) AIB_KLICKKARTA_MANADER; ?> månader, provklick efter <?php echo (int) AIB_KLICKKARTA_PROV_DAGAR; ?> dagar.
        Rader i tabellen: <?php echo (int) $totalt; ?>.
        <?php if (isset($_GET['tomt'])) echo ' Provklick borttagna: ' . (int) $_GET['tomt'] . '.'; ?>
      </p>
      <form method="get" class="aib-kk-val">
        <input type="hidden" name="page" value="aib-klickkarta">
        <label>Sida <select name="sida">
          <?php if (!$sidor) echo '<option value="/">/ (inga klick än)</option>'; foreach ($sidor as $s): ?>
            <option value="<?php echo esc_attr($s['sida']); ?>" <?php selected($s['sida'], $sida); ?>><?php echo esc_html($s['sida'] . ' – ' . (int) $s['klick'] . ' klick, ' . (int) $s['visningar'] . ' visningar'); ?></option>
          <?php endforeach; ?>
        </select></label>
        <label>Bredd <select name="klass">
          <?php foreach ($klasser as $k => $v): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($k, $klass); ?>><?php echo esc_html($v[0]); ?></option><?php endforeach; ?>
        </select></label>
        <label>Period <select name="dagar">
          <?php foreach ([7 => '7 dagar', 30 => '30 dagar', 90 => '90 dagar', 365 => '12 månader', 730 => '24 månader'] as $k => $v): ?><option value="<?php echo (int) $k; ?>" <?php selected($k, $dagar); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?>
        </select></label>
        <label><input type="checkbox" name="prov" value="1" <?php checked($medprov); ?>> Visa provklick</label>
        <button class="button button-primary">Visa</button>
      </form>

      <div class="aib-kk-siffror">
        <div><b><?php echo count($klick); ?></b> klick</div>
        <div><b><?php echo (int) $visningar; ?></b> sidvisningar</div>
        <div>Når 25 %: <b><?php echo (int) $djup[25]; ?> %</b></div>
        <div>Når 50 %: <b><?php echo (int) $djup[50]; ?> %</b></div>
        <div>Når 75 %: <b><?php echo (int) $djup[75]; ?> %</b></div>
        <div>Når botten: <b><?php echo (int) $djup[100]; ?> %</b></div>
      </div>

      <div class="aib-kk-rad">
        <div class="aib-kk-karta">
          <p class="description">Blå prick = klick på något klickbart. Röd prick = dött klick (på något som inte går att klicka på). Linjerna visar hur stor del av sidvisningarna som når sektionen.</p>
          <div id="aibKkRam" style="--vb:<?php echo (int) $vybredd; ?>px">
            <div id="aibKkInre">
              <iframe id="aibKkSida" src="<?php echo esc_url(add_query_arg('aib_klickvy', '1', home_url($sida))); ?>" width="<?php echo (int) $vybredd; ?>" height="900" title="Sidan <?php echo esc_attr($sida); ?>"></iframe>
              <canvas id="aibKkDuk"></canvas>
            </div>
          </div>
        </div>
        <div class="aib-kk-listor">
          <h2>Mest klickat</h2>
          <table class="widefat striped"><thead><tr><th>Element</th><th>Text</th><th>Sektion</th><th>Klick</th><th>Döda</th></tr></thead><tbody>
          <?php if (!$element) echo '<tr><td colspan="5">Inga klick i urvalet.</td></tr>'; foreach ($element as $e): ?>
            <tr><td><code><?php echo esc_html($e['elem']); ?></code></td><td><?php echo esc_html($e['text']); ?></td><td><?php echo esc_html($e['sektion']); ?></td><td><?php echo (int) $e['n']; ?></td><td><?php echo (int) $e['doda']; ?></td></tr>
          <?php endforeach; ?>
          </tbody></table>
          <h2>Per sektion</h2>
          <table class="widefat striped"><thead><tr><th>Sektion</th><th>Klick</th><th>Döda</th></tr></thead><tbody>
          <?php if (!$sektioner) echo '<tr><td colspan="3">Inga klick i urvalet.</td></tr>'; foreach ($sektioner as $s): ?>
            <tr><td><?php echo esc_html($s['sektion']); ?></td><td><?php echo (int) $s['n']; ?></td><td><?php echo (int) $s['doda']; ?></td></tr>
          <?php endforeach; ?>
          </tbody></table>
          <?php if ($provnyckel !== ''): ?>
          <h2>Prov</h2>
          <p>Med spärren på sparas bara klick från en adress med provnyckeln:<br><code><?php echo esc_html(add_query_arg('aib_klickprov', $provnyckel, home_url($sida))); ?></code></p>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="aib_klickkarta_tom_prov"><?php wp_nonce_field('aib_klickkarta_tom_prov'); ?>
            <button class="button">Ta bort alla provklick</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <style>
      .aib-kk-val{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin:12px 0}
      .aib-kk-val label{display:flex;gap:6px;align-items:center}
      .aib-kk-siffror{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 16px}
      .aib-kk-siffror div{background:#fff;border:1px solid #dcdcde;padding:8px 12px}
      .aib-kk-rad{display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start}
      .aib-kk-karta{flex:1 1 560px;min-width:320px}
      .aib-kk-listor{flex:1 1 360px;min-width:320px}
      #aibKkRam{position:relative;overflow:hidden;border:1px solid #dcdcde;background:#fff;width:100%}
      #aibKkInre{position:relative;transform-origin:0 0;width:var(--vb)}
      #aibKkSida{display:block;border:0;pointer-events:none}
      #aibKkDuk{position:absolute;left:0;top:0;pointer-events:none}
    </style>
    <script>
    (function(){
      var K=<?php echo wp_json_encode(array_map(static function ($r) { return [(int) $r[0], (int) $r[1], (int) $r[2]]; }, $klick)); ?>;
      var R=<?php echo wp_json_encode(array_map('intval', $rull)); ?>;
      var ram=document.getElementById('aibKkRam'),inre=document.getElementById('aibKkInre'),f=document.getElementById('aibKkSida'),duk=document.getElementById('aibKkDuk');
      function rita(){
        var d;try{d=f.contentDocument;}catch(e){return;}if(!d||!d.documentElement)return;
        var w=Math.max(d.documentElement.scrollWidth,1),h=Math.max(d.documentElement.scrollHeight,d.body?d.body.scrollHeight:0,1);
        f.style.height=h+'px';f.height=h;duk.width=w;duk.height=h;duk.style.width=w+'px';duk.style.height=h+'px';
        var s=Math.min(1,ram.clientWidth/w);inre.style.transform='scale('+s+')';ram.style.height=Math.ceil(h*s)+'px';
        var c=duk.getContext('2d');c.clearRect(0,0,w,h);
        K.forEach(function(k){c.beginPath();c.arc(k[0]/10000*w,k[1]/10000*h,7,0,6.2832);c.fillStyle=k[2]?'rgba(200,30,30,.45)':'rgba(20,90,200,.35)';c.fill();});
        var huvud=d.querySelector('main')||d.body,delar=[];
        [].slice.call(d.querySelectorAll('.fs-hem, main > *, .wp-block-post-content > *, footer')).forEach(function(el){if(el.closest('.fs-hem')&&!el.classList.contains('fs-hem'))return;var r=el.getBoundingClientRect();if(r.height<80)return;delar.push(r.top+(d.defaultView.scrollY||0));});
        delar=delar.filter(function(y,i,a){return a.indexOf(y)===i;}).sort(function(a,b){return a-b;});
        c.font='bold 22px sans-serif';
        delar.forEach(function(y){var fr=y/h*10000,n=R.filter(function(r){return r>=fr;}).length,p=R.length?Math.round(n/R.length*100):0;
          c.fillStyle='rgba(0,0,0,.55)';c.fillRect(0,y,w,2);var txt=p+' % av '+R.length+' visningar når hit';var tw=c.measureText(txt).width;c.fillRect(8,y+4,tw+16,30);c.fillStyle='#fff';c.fillText(txt,16,y+26);});
      }
      f.addEventListener('load',function(){rita();setTimeout(rita,1500);});
      addEventListener('resize',rita);
    })();
    </script>
    <?php
}
