<?php
/**
 * Plugin Name: Fastsan – PHP-fel skrivs inte ut i sidan
 * Description: Stänger av utskrift av PHP-fel och varningar i sidan (display_errors). Felen loggas som förut. Filnamnet börjar med 000 för att filen ska laddas före övriga mu-plugins. Ägarbeslut Johan 2026-11-23 19:00 ("B ja stäng av"). Serverns egen inställning går inte att ändra via cPanel-bryggan (MultiPHP INI Editor saknas på kontot).
 * Version: 1.0.0
 * Author: Ai Brick AB
 */
if ( ! defined( 'ABSPATH' ) ) {
	return;
}
@ini_set( 'display_errors', '0' );
