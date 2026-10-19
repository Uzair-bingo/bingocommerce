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
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/PlayProductResult.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/PackageResult.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/PackageAddonResult.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/CatalogPlayProductPlan.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/CatalogPackageCompositionPlan.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/CatalogPackagePlan.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/CatalogPackageAddonPlan.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/CatalogWriteOutcome.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/Migrations.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/StarterImportMigrations.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/StarterImportLock.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/StarterCatalogStorage.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/StarterCatalogVersions.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/StarterImportRepository.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/StarterImporter.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/CatalogTransactionTransport.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/GameTypeService.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/BingoPlayProductService.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/PackageService.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/PackageAddonService.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/CustomerCartCatalog.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/ShopListing.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/ElectronicLimit.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/RedemptionGameTypes.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/ElectronicLimitSettings.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/GameTypeAdmin.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/PlayProductAdmin.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/PackageAdmin.php';
require_once BINGOCOMMERCE_CATALOG_PATH . 'src/Plugin.php';

BingoCommerce\Catalog\Plugin::boot();
