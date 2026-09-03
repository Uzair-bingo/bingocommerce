<?php
/**
 * Plugin Name: BingoCommerce Portal Integration
 * Description: Network Hall mapping and server-only Portal configuration for BingoCommerce.
 * Version: 0.1.0
 * Author: Bingo Innovation
 * Network: true
 * Text Domain: bingocommerce-portal-integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BINGOCOMMERCE_PORTAL_INTEGRATION_VERSION', '0.1.0' );
define( 'BINGOCOMMERCE_PORTAL_INTEGRATION_PATH', plugin_dir_path( __FILE__ ) );

require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/Migrations.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/HallMappingRepository.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/PortalConfig.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/Plugin.php';

BingoCommerce\PortalIntegration\Plugin::boot();
