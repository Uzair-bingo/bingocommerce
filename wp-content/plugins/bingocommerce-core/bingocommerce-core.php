<?php
/**
 * Plugin Name: BingoCommerce Core
 * Description: Core platform services for BingoCommerce.
 * Version: 0.1.0
 * Author: Bingo Innovation
 * Network: true
 * Text Domain: bingocommerce-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BINGOCOMMERCE_CORE_VERSION', '0.1.0' );
define( 'BINGOCOMMERCE_CORE_PATH', plugin_dir_path( __FILE__ ) );

require_once BINGOCOMMERCE_CORE_PATH . 'src/HallContext.php';
require_once BINGOCOMMERCE_CORE_PATH . 'src/Plugin.php';

BingoCommerce\Core\Plugin::boot();
