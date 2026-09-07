<?php
/**
 * Plugin Name: __fs-lead-test-redirect (TESTHJALP, tas bort efter prov)
 * Description: Styr fastsan-lead-pipelines notismejl till johan@aibrick.se under testkorning (filter fastsan_lead_notify_to) sa att Daniel inte far testmejl. Raderas via aib-deployer op:delete nar proven ar klara.
 * Version: 1.0.0
 * Author: B S472
 */
if (!defined('ABSPATH')) return;
add_filter('fastsan_lead_notify_to', static fn($to) => 'johan@aibrick.se', 100);
