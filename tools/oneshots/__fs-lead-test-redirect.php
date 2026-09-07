<?php
/**
 * Plugin Name: __fs-lead-test-redirect (TESTHJALP, tas bort efter prov)
 * Description: Styr fastsan-lead-pipelines notismejl till agarens brevlada under testkorning (filter fastsan_lead_notify_to) sa att Daniel inte far testmejl. Raderas via aib-deployer op:delete nar proven ar klara.
 * Version: 1.0.1
 * Author: B S472
 */
if (!defined('ABSPATH')) return;
add_filter('fastsan_lead_notify_to', static fn($to) => 'odsater@gmail.com', 100);
