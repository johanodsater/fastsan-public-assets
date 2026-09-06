<?php
/**
 * Plugin Name: AIB Deployer
 * Description: Autentiserad (HMAC-SHA256), replay-skyddad, sha256-verifierad fildeploy från commit-pinnade GitHub-raw-URL:er. Trigger: POST /?aib_deploy=1. Mål begränsade till mu-plugins/, themes/, uploads/fastsan-content/. Logg: uploads/aib-deployer/deploy.log. Hemlighet: wp_option aib_deployer_secret (hex). Ersätter engångs-pullers och bryggans file_write.
 * Version: 1.0.0
 * Author: Ai Brick AB
 */

if (!defined('ABSPATH')) return;
if (defined('AIB_DEPLOYER_LOADED')) return;
define('AIB_DEPLOYER_LOADED', '1.0.0');

add_action('init', 'aib_deployer_handle', 1);

function aib_deployer_handle() {
    if (!isset($_GET['aib_deploy']) || $_GET['aib_deploy'] !== '1') return;
    $res = aib_deployer_run();
    wp_send_json($res['body'], $res['status']);
}

function aib_deployer_fail($status, $error, $extra = []) {
    aib_deployer_log('FAIL ' . $error . (isset($extra['dest']) ? ' ' . $extra['dest'] : ''));
    return ['status' => $status, 'body' => array_merge(['ok' => false, 'error' => $error, 'version' => AIB_DEPLOYER_LOADED], $extra)];
}

function aib_deployer_run() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return aib_deployer_fail(405, 'method');
    $secret = (string) get_option('aib_deployer_secret', '');
    if (strlen($secret) < 32) return aib_deployer_fail(503, 'no_secret');

    $ts    = (string) ($_SERVER['HTTP_X_AIB_TS'] ?? '');
    $nonce = (string) ($_SERVER['HTTP_X_AIB_NONCE'] ?? '');
    $sig   = (string) ($_SERVER['HTTP_X_AIB_SIG'] ?? '');
    $raw   = (string) file_get_contents('php://input');
    if (!ctype_digit($ts) || abs(time() - (int) $ts) > 300) return aib_deployer_fail(401, 'ts');
    if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $nonce)) return aib_deployer_fail(401, 'nonce');
    $expect = hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . $raw, $secret);
    if (!hash_equals($expect, strtolower($sig))) return aib_deployer_fail(401, 'sig');
    $nkey = 'aib_dep_n_' . md5($nonce);
    if (get_transient($nkey)) return aib_deployer_fail(401, 'replay');
    set_transient($nkey, 1, 600);

    $req = json_decode($raw, true);
    if (!is_array($req)) return aib_deployer_fail(400, 'json');
    $op   = (string) ($req['op'] ?? '');
    $dest = aib_deployer_path((string) ($req['dest'] ?? ''));
    if ($dest === null) return aib_deployer_fail(400, 'dest');

    if ($op === 'write') {
        $url = (string) ($req['url'] ?? '');
        $exp = strtolower((string) ($req['sha256'] ?? ''));
        if (!preg_match('#^https://raw\.githubusercontent\.com/johanodsater/[A-Za-z0-9_.-]+/[0-9a-f]{40}/[A-Za-z0-9_./-]+$#', $url)) return aib_deployer_fail(400, 'url_not_pinned');
        if (!preg_match('/^[0-9a-f]{64}$/', $exp)) return aib_deployer_fail(400, 'sha256');
        $r = wp_remote_get($url, ['timeout' => 30, 'redirection' => 0]);
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) return aib_deployer_fail(502, 'fetch', ['dest' => $req['dest']]);
        $body = (string) wp_remote_retrieve_body($r);
        $got  = hash('sha256', $body);
        if ($got !== $exp) return aib_deployer_fail(409, 'sha_mismatch', ['got' => $got, 'dest' => $req['dest']]);
        if (substr($dest, -4) === '.php' && strpos($body, '<?php') !== 0) return aib_deployer_fail(400, 'php_header', ['dest' => $req['dest']]);
        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return aib_deployer_fail(500, 'mkdir', ['dest' => $req['dest']]);
        $backup = aib_deployer_backup($dest);
        $tmp = $dest . '.aibtmp';
        if (@file_put_contents($tmp, $body) !== strlen($body) || !@rename($tmp, $dest)) { @unlink($tmp); return aib_deployer_fail(500, 'write', ['dest' => $req['dest']]); }
        @chmod($dest, 0644);
        if (function_exists('opcache_invalidate')) @opcache_invalidate($dest, true);
        aib_deployer_log('OK write ' . $req['dest'] . ' ' . strlen($body) . 'b ' . $got . ($backup ? ' backup=' . basename($backup) : ''));
        $out = ['ok' => true, 'op' => 'write', 'dest' => $req['dest'], 'bytes' => strlen($body), 'sha256' => $got, 'backup' => $backup ? basename($backup) : null];
    } elseif ($op === 'delete') {
        if (!file_exists($dest)) return aib_deployer_fail(404, 'missing', ['dest' => $req['dest']]);
        $backup = aib_deployer_backup($dest);
        if (!@unlink($dest)) return aib_deployer_fail(500, 'unlink', ['dest' => $req['dest']]);
        aib_deployer_log('OK delete ' . $req['dest'] . ($backup ? ' backup=' . basename($backup) : ''));
        $out = ['ok' => true, 'op' => 'delete', 'dest' => $req['dest'], 'backup' => $backup ? basename($backup) : null];
    } elseif ($op === 'rename') {
        $to = aib_deployer_path((string) ($req['to'] ?? ''));
        if ($to === null) return aib_deployer_fail(400, 'to');
        if (!file_exists($dest)) return aib_deployer_fail(404, 'missing', ['dest' => $req['dest']]);
        if (!@rename($dest, $to)) return aib_deployer_fail(500, 'rename', ['dest' => $req['dest']]);
        if (function_exists('opcache_invalidate')) { @opcache_invalidate($dest, true); @opcache_invalidate($to, true); }
        aib_deployer_log('OK rename ' . $req['dest'] . ' -> ' . $req['to']);
        $out = ['ok' => true, 'op' => 'rename', 'dest' => $req['dest'], 'to' => $req['to']];
    } elseif ($op === 'stat') {
        $out = ['ok' => true, 'op' => 'stat', 'dest' => $req['dest'], 'exists' => file_exists($dest), 'bytes' => file_exists($dest) ? filesize($dest) : null, 'sha256' => file_exists($dest) ? hash_file('sha256', $dest) : null];
    } else {
        return aib_deployer_fail(400, 'op');
    }

    if (!empty($req['purge'])) {
        if (function_exists('opcache_reset')) @opcache_reset();
        do_action('litespeed_purge_all');
        $out['purged'] = true;
    }
    $out['version'] = AIB_DEPLOYER_LOADED;
    return ['status' => 200, 'body' => $out];
}

/** Whitelist: relativ sökväg under wp-content, utan '..', bara mu-plugins/, themes/, uploads/fastsan-content/. */
function aib_deployer_path($rel) {
    if (!preg_match('#^(mu-plugins|themes|uploads/fastsan-content)/[A-Za-z0-9_./-]+$#', $rel) || strpos($rel, '..') !== false) return null;
    return WP_CONTENT_DIR . '/' . $rel;
}

function aib_deployer_backup($dest) {
    if (!file_exists($dest)) return null;
    $bdir = WP_CONTENT_DIR . '/uploads/aib-deployer/backup';
    if (!is_dir($bdir) && !@mkdir($bdir, 0755, true)) return null;
    $b = $bdir . '/' . basename($dest) . '.' . gmdate('Ymd-His');
    return @copy($dest, $b) ? $b : null;
}

function aib_deployer_log($line) {
    $ldir = WP_CONTENT_DIR . '/uploads/aib-deployer';
    if (!is_dir($ldir)) @mkdir($ldir, 0755, true);
    if (!file_exists($ldir . '/index.html')) @file_put_contents($ldir . '/index.html', '');
    @file_put_contents($ldir . '/deploy.log', '[' . gmdate('c') . '] ' . $line . "\n", FILE_APPEND);
}
