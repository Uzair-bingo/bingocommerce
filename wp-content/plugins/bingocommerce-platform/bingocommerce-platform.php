<?php
/**
 * Plugin Name: BingoCommerce Platform
 * Description: Platform-owned WordPress Multisite Hall site creation and site/WooCommerce initialization for BingoCommerce.
 * Version: 0.1.0
 * Author: Bingo Innovation
 * Network: true
 * Text Domain: bingocommerce-platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BINGOCOMMERCE_PLATFORM_VERSION', '0.1.0' );
define( 'BINGOCOMMERCE_PLATFORM_PATH', plugin_dir_path( __FILE__ ) );

require_once BINGOCOMMERCE_PLATFORM_PATH . 'src/HallSiteAddress.php';
require_once BINGOCOMMERCE_PLATFORM_PATH . 'src/HallSiteAdminUser.php';
require_once BINGOCOMMERCE_PLATFORM_PATH . 'src/HallSiteCommand.php';
require_once BINGOCOMMERCE_PLATFORM_PATH . 'src/HallSiteProvisioner.php';
require_once BINGOCOMMERCE_PLATFORM_PATH . 'src/Plugin.php';

BingoCommerce\Platform\Plugin::boot();
