<?php
/**
 * Plugin Name: __fs-qa10-extendify (ENGANGS, tas bort efter korning)
 * Description: Agarorder Johan 2026-11-23 19:58 ("2 ta bort"). GET /?fs_qa10=<nyckel>&steg=<steg>. lage = visar vad som ligger i plugins/_extendify_disabled-2026-05-10. radera = packar mappen i en zip utanfor webbroten, kontrollerar zipen och raderar sedan mappen. klar = raderar den har filen. Nyckeln raknas ut pa servern ur aib_deployer_secret.
 * Version: 1.0.0
 * Author: B (2026-11-23)
 */
if (!defined('ABSPATH')) return;
add_action('init', static function () {
    if (!isset($_GET['fs_qa10'])) return;
    $secret = (string) get_option('aib_deployer_secret', '');
    $key = $secret === '' ? '' : substr(hash_hmac('sha256', 'fs-qa10-s1953', $secret), 0, 32);
    if ($key === '' || !hash_equals($key, (string) $_GET['fs_qa10'])) return;
    nocache_headers();
    do_action('litespeed_control_set_nocache', 'fs-qa10');
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(120);
    $dir = WP_PLUGIN_DIR . '/_extendify_disabled-2026-05-10';
    $steg = isset($_GET['steg']) ? (string) $_GET['steg'] : '';
    $out = ['steg' => $steg];
    $walk = static function (string $d) use (&$walk): array {
        $files = [];
        foreach ((array) @scandir($d) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $d . '/' . $f;
            if (is_dir($p) && !is_link($p)) $files = array_merge($files, $walk($p)); else $files[] = $p;
        }
        return $files;
    };
    $rmdir = static function (string $d) use (&$rmdir): bool {
        if (!is_dir($d)) return true;
        foreach ((array) scandir($d) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $d . '/' . $f;
            (is_dir($p) && !is_link($p)) ? $rmdir($p) : @unlink($p);
        }
        return @rmdir($d);
    };
    $lage = static function () use ($dir, $walk) {
        if (!is_dir($dir)) return ['mappen_finns' => false];
        $files = $walk($dir);
        $names = [];
        foreach ($files as $f) {
            if (substr($f, -4) !== '.php' || substr_count(substr($f, strlen($dir) + 1), '/') > 1) continue;
            if (preg_match('/^[ \t\/*#@]*Plugin Name:\s*(.+)$/mi', (string) @file_get_contents($f, false, null, 0, 4096), $m)) {
                $v = preg_match('/^[ \t\/*#@]*Version:\s*(.+)$/mi', (string) @file_get_contents($f, false, null, 0, 4096), $mv) ? trim($mv[1]) : '?';
                $names[] = trim($m[1]) . ' ' . $v . ' (' . substr($f, strlen($dir) + 1) . ')';
            }
        }
        return ['mappen_finns' => true, 'filer' => count($files), 'byte' => array_sum(array_map('filesize', $files)),
            'overst' => array_values(array_diff((array) scandir($dir), ['.', '..'])), 'plugin' => $names,
            'aktiv' => (bool) array_filter((array) get_option('active_plugins', []), static fn($p) => stripos($p, 'extendify') !== false)];
    };
    try {
        if ($steg === 'lage') {
            $out += $lage();
        } elseif ($steg === 'radera') {
            if (!is_dir($dir)) throw new \RuntimeException('mappen finns inte');
            if (!function_exists('aib_deployer_data_dir') || !class_exists('ZipArchive')) throw new \RuntimeException('kan inte ta kopia');
            $bdir = aib_deployer_data_dir() . '/backup';
            if (!is_dir($bdir)) @mkdir($bdir, 0750, true);
            $zip_path = $bdir . '/qa10-extendify-' . gmdate('Ymd-His') . '.zip';
            if (strpos($zip_path, WP_CONTENT_DIR) === 0) throw new \RuntimeException('kopian skulle hamna i webbroten');
            $files = $walk($dir);
            $zip = new ZipArchive();
            if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('kan inte skapa zip');
            foreach ($files as $f) $zip->addFile($f, 'plugins/' . basename($dir) . '/' . substr($f, strlen($dir) + 1));
            $zip->close();
            $chk = new ZipArchive();
            if ($chk->open($zip_path) !== true || $chk->numFiles !== count($files)) throw new \RuntimeException('kopian stammer inte (' . ($chk->numFiles ?? 0) . ' av ' . count($files) . ')');
            $chk->close();
            $out['kopia'] = ['fil' => basename($zip_path), 'filer' => count($files), 'byte' => filesize($zip_path), 'utanfor_webbroten' => true];
            $out['raderad'] = $rmdir($dir);
            $out['efter'] = $lage();
            $out['plugin_mappar'] = array_values(array_map('basename', (array) glob(WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR)));
        } elseif ($steg === 'klar') {
            $out['raderad'] = @unlink(__FILE__);
            if (function_exists('opcache_invalidate')) @opcache_invalidate(__FILE__, true);
        } else {
            $out['fel'] = 'okant steg';
        }
    } catch (\Throwable $e) {
        $out['fel'] = $e->getMessage();
    }
    echo wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}, 50);
