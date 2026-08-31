<?php
/**
 * Plugin Name: BingoCommerce Sessions
 * Description: Hall-local BingoCommerce Session domain services.
 * Version: 0.1.0
 * Author: Bingo Innovation
 * Network: true
 * Text Domain: bingocommerce-sessions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BINGOCOMMERCE_SESSIONS_VERSION', '0.1.0' );
define( 'BINGOCOMMERCE_SESSIONS_PATH', plugin_dir_path( __FILE__ ) );

require_once BINGOCOMMERCE_SESSIONS_PATH . 'src/SessionResult.php';
require_once BINGOCOMMERCE_SESSIONS_PATH . 'src/Migrations.php';
require_once BINGOCOMMERCE_SESSIONS_PATH . 'src/PackageGateway.php';
require_once BINGOCOMMERCE_SESSIONS_PATH . 'src/SessionService.php';
require_once BINGOCOMMERCE_SESSIONS_PATH . 'src/SessionAdmin.php';
require_once BINGOCOMMERCE_SESSIONS_PATH . 'src/Plugin.php';

BingoCommerce\Sessions\Plugin::boot();
