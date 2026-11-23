<?php
/**
 * Plugin Name: __fs-qa6-gallring-prov (ENGANGS, tas bort efter prov)
 * Description: Provar gallringen i fastsan-lead-pipeline 0.8.0 mot en KOPIA av tabellen. GET /?fs_qa6=<nyckel>&steg=prov bygger kopian, lagger in atta paahittade rader med kanda datum, kor gallringen (forst utan att radera, sedan pa riktigt) mot kopian, kor den utan att radera mot den riktiga tabellen och tar bort kopian. steg=klar raderar den har filen. Nyckeln raknas ut pa servern ur aib_deployer_secret.
 * Version: 1.0.0
 * Author: B (QA-planen punkt 6, 2026-11-23)
 */
if (!defined('ABSPATH')) return;
add_action('init', static function () {
    if (!isset($_GET['fs_qa6'])) return;
    $secret = (string) get_option('aib_deployer_secret', '');
    $key = $secret === '' ? '' : substr(hash_hmac('sha256', 'fs-qa6-s1953', $secret), 0, 32);
    if ($key === '' || !hash_equals($key, (string) $_GET['fs_qa6'])) return;
    nocache_headers();
    do_action('litespeed_control_set_nocache', 'fs-qa6');
    header('Content-Type: application/json; charset=utf-8');
    $steg = isset($_GET['steg']) ? (string) $_GET['steg'] : '';
    $out = ['steg' => $steg];
    try {
        if ($steg === 'klar') {
            $out['raderad'] = @unlink(__FILE__);
            if (function_exists('opcache_invalidate')) @opcache_invalidate(__FILE__, true);
        } elseif ($steg === 'prov') {
            global $wpdb;
            if (!function_exists('fastsan_lead_run_retention')) throw new \RuntimeException('fastsan_lead_run_retention saknas');
            $live = $wpdb->prefix . FASTSAN_LEAD_TABLE;
            $copy = $live . '_qa6';
            $wpdb->query("DROP TABLE IF EXISTS $copy");
            $wpdb->query("CREATE TABLE $copy LIKE $live");
            $wpdb->query("INSERT INTO $copy SELECT * FROM $live");
            $out['riktiga_rader'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $live");
            $out['kopia_rader_fore'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $copy");
            $now = (int) current_time('timestamp');
            $d = static fn(string $rel) => gmdate('Y-m-d H:i:s', strtotime($rel, $now));
            // [namn, status, created_at, status_updated_at, ska raderas]
            $cases = [
                ['QA6 a ny 3 ar',                         'new',       $d('-3 years'),         $d('-3 years'),         true],
                ['QA6 b offert 3 ar, kontakt 1 ar',       'quoted',    $d('-3 years'),         $d('-1 year'),          false],
                ['QA6 c forlorad 2 ar + 1 dag',           'lost',      $d('-2 years -1 day'),  $d('-2 years -1 day'),  true],
                ['QA6 d kontaktad 2 ar - 1 dag',          'contacted', $d('-2 years +1 day'),  $d('-2 years +1 day'),  false],
                ['QA6 e vunnen 3 ar',                     'won',       $d('-3 years'),         $d('-3 years'),         false],
                ['QA6 f vunnen 8 ar',                     'won',       $d('-8 years'),         $d('-8 years'),         true],
                ['QA6 g bokad 7 ar - 1 dag',              'booked',    $d('-7 years +1 day'),  $d('-7 years +1 day'),  false],
                ['QA6 h ny 3 ar utan statusdatum',        'new',       $d('-3 years'),         null,                   true],
            ];
            $expect = [];
            foreach ($cases as $c) {
                $wpdb->insert($copy, ['name' => $c[0], 'email' => 'qa6@example.com', 'status' => $c[1], 'created_at' => $c[2], 'status_updated_at' => $c[3], 'source' => 'qa6']);
                $expect[(int) $wpdb->insert_id] = ['namn' => $c[0], 'ska_raderas' => $c[4]];
            }
            $dry  = fastsan_lead_run_retention(true, $copy);
            $real = fastsan_lead_run_retention(false, $copy);
            $gone = array_merge($real['requests'], $real['assignments']);
            $rows = [];
            $ok = 0;
            foreach ($expect as $id => $e) {
                $finns = (bool) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $copy WHERE id = %d", $id));
                $pass = ($finns === !$e['ska_raderas']);
                if ($pass) $ok++;
                $rows[] = ($pass ? 'OK  ' : 'FEL ') . $e['namn'] . ': ' . ($finns ? 'kvar' : 'raderad');
            }
            $out['fall'] = $rows;
            $out['som_vantat'] = $ok . ' av ' . count($expect);
            $out['torrkorning_lika_med_riktig'] = ($dry['requests'] === $real['requests'] && $dry['assignments'] === $real['assignments']);
            $out['granser'] = ['forfragningar_fore' => $real['cut_requests'], 'uppdrag_fore' => $real['cut_assignments']];
            $out['kopia_riktiga_rader_orord'] = ((int) $wpdb->get_var("SELECT COUNT(*) FROM $copy WHERE source <> 'qa6'") === $out['kopia_rader_fore']);
            $out['raderade_utanfor_provraderna'] = count(array_diff($gone, array_keys($expect)));
            $live_dry = fastsan_lead_run_retention(true);
            $out['riktig_tabell_torrkorning'] = ['forfragningar' => count($live_dry['requests']), 'uppdrag' => count($live_dry['assignments']), 'uppringningslogg' => $live_dry['callback_log']];
            $out['riktiga_rader_efter'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $live");
            $wpdb->query("DROP TABLE IF EXISTS $copy");
            $out['kopia_borttagen'] = !$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $copy));
            $out['aldsta_riktiga_rad'] = $wpdb->get_var("SELECT MIN(created_at) FROM $live");
            $out['cron_nasta'] = wp_next_scheduled(FASTSAN_LEAD_CRON_HOOK) ? gmdate('Y-m-d H:i:s', (int) wp_next_scheduled(FASTSAN_LEAD_CRON_HOOK)) . ' UTC' : null;
            $out['pipeline'] = FASTSAN_LEAD_PIPELINE_VERSION;
        } else {
            $out['fel'] = 'okant steg';
        }
    } catch (\Throwable $e) {
        $out['fel'] = $e->getMessage();
    }
    echo wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}, 50);
