<?php
/**
 * Plugin Name: __fs-lead-test-redirect (TESTHJALP, tas bort efter prov)
 * Description: Styr fastsan-lead-pipelines notismejl till en teknisk AIB-brevlada under testkorning (filter fastsan_lead_notify_to) sa att Daniel inte far testmejl. To skiljer sig fran eskaleringsadressen sa att CC-headern kan bevisas (PHPMailer slar ihop identisk To/Cc). Raderas via aib-deployer op:delete nar proven ar klara.
 * Version: 1.0.2
 * Author: B S472
 */
if (!defined('ABSPATH')) return;
add_filter('fastsan_lead_notify_to', static fn($to) => 'dmarcreports@korkort247.se', 100);
