<?php
/**
 * Plugin Name: BingoCommerce Catalog
 * Description: Hall-local BingoCommerce catalog domain services.
 * Version: 0.1.0
 * Author: Bingo Innovation
 * Network: true
 * Text Domain: bingocommerce-catalog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BINGOCOMMERCE_CATALOG_VERSION', '0.1.0' );
define( 'BINGOCOMMERCE_CATALOG_PATH', plugin_dir_path( __FILE__ ) );

require_once BINGOCOMMERCE_CATALOG_PATH . 'src/GameTypeResult.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/Migrations.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/GameTypeService.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/GameTypeAdmin.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/Plugin.php';

BingoCommerce\Catalog\Plugin::boot();
