<?php
/**
 * Plugin Name: __fs-qa9-bilder (ENGANGS, tas bort efter korning)
 * Description: QA-planen punkt 9b. GET /?fs_qa9=<nyckel>&steg=<steg>. lage = visar bildstorlekarna for bild 154, 157 och 111 och om PHP-fel skrivs ut. regen = sparar bildernas nuvarande metadata i postmeta (_aib_backup_s1953_attmeta) och skapar storlekarna pa nytt, sa att temats nya storlek fastsan-480 kommer med. klar = raderar den har filen. Nyckeln raknas ut pa servern ur aib_deployer_secret.
 * Version: 1.0.0
 * Author: B (2026-11-23)
 */
if (!defined('ABSPATH')) return;
add_action('init', static function () {
    if (!isset($_GET['fs_qa9'])) return;
    $secret = (string) get_option('aib_deployer_secret', '');
    $key = $secret === '' ? '' : substr(hash_hmac('sha256', 'fs-qa9-s1953', $secret), 0, 32);
    if ($key === '' || !hash_equals($key, (string) $_GET['fs_qa9'])) return;
    nocache_headers();
    do_action('litespeed_control_set_nocache', 'fs-qa9');
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(120);
    $ids = [154, 157, 111];
    $steg = isset($_GET['steg']) ? (string) $_GET['steg'] : '';
    $out = ['steg' => $steg];
    $lage = static function () use ($ids) {
        $o = ['display_errors' => (string) ini_get('display_errors'), 'storlek_fastsan_480' => has_image_size('fastsan-480')];
        foreach ($ids as $id) {
            $m = wp_get_attachment_metadata($id);
            $f = get_attached_file($id);
            $o['bild_' . $id] = [
                'fil' => $f ? basename($f) : null, 'finns' => $f ? file_exists($f) : false,
                'matt' => is_array($m) ? ($m['width'] ?? '?') . 'x' . ($m['height'] ?? '?') : null,
                'storlekar' => is_array($m) && !empty($m['sizes']) ? array_map(static fn($s) => $s['width'] . 'x' . $s['height'], $m['sizes']) : [],
            ];
        }
        return $o;
    };
    try {
        if ($steg === 'lage') {
            $out += $lage();
        } elseif ($steg === 'regen') {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            if (!has_image_size('fastsan-480')) throw new \RuntimeException('storleken fastsan-480 ar inte registrerad (temat inte uppdaterat)');
            foreach ($ids as $id) {
                $file = get_attached_file($id);
                if (!$file || !file_exists($file)) { $out['bild_' . $id] = 'filen saknas'; continue; }
                $old = wp_get_attachment_metadata($id);
                if (!metadata_exists('post', $id, '_aib_backup_s1953_attmeta')) add_post_meta($id, '_aib_backup_s1953_attmeta', $old, true);
                $new = wp_generate_attachment_metadata($id, $file);
                if (is_wp_error($new) || empty($new['sizes'])) { $out['bild_' . $id] = 'kunde inte skapa storlekar'; continue; }
                wp_update_attachment_metadata($id, $new);
                $out['bild_' . $id] = 'klar';
            }
            $out['efter'] = $lage();
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
