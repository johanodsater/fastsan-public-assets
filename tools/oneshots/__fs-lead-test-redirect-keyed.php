<?php
/**
 * Plugin Name: __fs-lead-test-redirect-keyed (TESTHJALP, tas bort efter prov)
 * Description: Styr notismejlet for EN provforfragan till en teknisk AIB-brevlada. Galler bara anrop som bar huvudet X-Fs-Test med ratt nyckel; riktiga forfragningar gar till Daniel som vanligt. Nyckeln raknas ut pa servern ur aib_deployer_secret och star inte i filen. Raderas via aib-deployer nar provet ar klart.
 * Version: 1.0.0
 * Author: B (QA-planen punkt 4, 2026-11-23)
 */
if (!defined('ABSPATH')) return;
add_filter('fastsan_lead_notify_to', static function ($to) {
    $got = isset($_SERVER['HTTP_X_FS_TEST']) ? (string) $_SERVER['HTTP_X_FS_TEST'] : '';
    $secret = (string) get_option('aib_deployer_secret', '');
    if ($got === '' || $secret === '') return $to;
    $key = substr(hash_hmac('sha256', 'fs-lead-test', $secret), 0, 32);
    return hash_equals($key, $got) ? 'dmarcreports@korkort247.se' : $to;
}, 100);
