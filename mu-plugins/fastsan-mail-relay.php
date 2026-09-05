<?php
/**
 * Plugin Name: Fastsan Mail Relay (Google SMTP)
 * Description: Skickar all wp_mail() via Googles SMTP-rela (smtp-relay.gmail.com:587, STARTTLS) i stallet for PHP mail() fran reptilhe. Tvingar IPv4 (relaet ar IP-godkant pa 91.201.60.163; servern gar annars ut pa IPv6 2a02:28f0:304e:6f:: som Google avvisar). HELO fastsan.se. Avsandare daniel@fastsan.se (finns i Workspace, DKIM-signeras av Google, SPF passerar). Mottagaralias daniel.stalbrand@fastsan.se skrivs om till kanonisk daniel@fastsan.se (agarbeslut 2026-09-05). Rollback: byt namn pa filen till .off.
 * Version: 1.0.2
 * Author: Ai Brick AB / C
 */

if (!defined('ABSPATH')) return;
if (defined('FASTSAN_MAIL_RELAY')) return;
define('FASTSAN_MAIL_RELAY', '1.0.2');

const FASTSAN_MAIL_FROM      = 'daniel@fastsan.se';
const FASTSAN_MAIL_FROM_NAME = 'Fastsan AB (webb)';
const FASTSAN_MAIL_RELAY_HOST = 'smtp-relay.gmail.com';

add_filter('wp_mail_from', static function () { return FASTSAN_MAIL_FROM; }, 100);
add_filter('wp_mail_from_name', static function () { return FASTSAN_MAIL_FROM_NAME; }, 100);

// Kanonisk mottagare: utfasat alias -> daniel@ (relaet accepterar bada, men kanon ar daniel@).
add_filter('wp_mail', static function ($args) {
    $map = ['daniel.stalbrand@fastsan.se' => 'daniel@fastsan.se'];
    $to  = $args['to'] ?? '';
    if (is_string($to)) {
        $args['to'] = strtr($to, $map);
    } elseif (is_array($to)) {
        $args['to'] = array_map(static function ($a) use ($map) { return strtr((string) $a, $map); }, $to);
    }
    return $args;
}, 100);

add_action('phpmailer_init', static function ($phpmailer) {
    $phpmailer->isSMTP();
    $phpmailer->Host        = FASTSAN_MAIL_RELAY_HOST;
    $phpmailer->Port        = 587;
    $phpmailer->SMTPSecure  = 'tls';
    $phpmailer->SMTPAutoTLS = true;
    $phpmailer->SMTPAuth    = false;
    $phpmailer->Helo        = 'fastsan.se';
    $phpmailer->Timeout     = 15;
    $phpmailer->Sender      = FASTSAN_MAIL_FROM; // envelope-from / Return-Path i domanen
    $phpmailer->setFrom(FASTSAN_MAIL_FROM, FASTSAN_MAIL_FROM_NAME, false);

    // Tvinga IPv4: anslut till A-posten, verifiera TLS-certet mot vardnamnet (SNI + peer_name).
    $ipv4 = @gethostbynamel(FASTSAN_MAIL_RELAY_HOST);
    if (!empty($ipv4) && filter_var($ipv4[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $phpmailer->Host        = $ipv4[0];
        $phpmailer->SMTPOptions = [
            'ssl' => [
                'peer_name'        => FASTSAN_MAIL_RELAY_HOST,
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'SNI_enabled'      => true,
            ],
        ];
    }
}, 100);

add_action('wp_mail_failed', static function ($error) {
    error_log('[AIB] fastsan-mail-relay: wp_mail_failed ' . $error->get_error_message());
});
