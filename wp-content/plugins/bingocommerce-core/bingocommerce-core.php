<?php
/**
 * Plugin Name: BingoCommerce Core
 * Description: Core platform services for BingoCommerce.
 * Version: 0.2.0
 * Author: Bingo Innovation
 * Network: true
 * Text Domain: bingocommerce-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BINGOCOMMERCE_CORE_VERSION', '0.2.0' );
define( 'BINGOCOMMERCE_CORE_PATH', plugin_dir_path( __FILE__ ) );

require_once BINGOCOMMERCE_CORE_PATH . 'src/HallContext.php';
require_once BINGOCOMMERCE_CORE_PATH . 'src/Roles.php';
require_once BINGOCOMMERCE_CORE_PATH . 'src/Migrations.php';
require_once BINGOCOMMERCE_CORE_PATH . 'src/PreparedAuditEvent.php';
require_once BINGOCOMMERCE_CORE_PATH . 'src/AuditLog.php';
require_once BINGOCOMMERCE_CORE_PATH . 'src/HallStaffIntent.php';
require_once BINGOCOMMERCE_CORE_PATH . 'src/HallStaffResult.php';
require_once BINGOCOMMERCE_CORE_PATH . 'src/HallStaffRetryToken.php';
require_once BINGOCOMMERCE_CORE_PATH . 'src/HallStaffTransactionTransport.php';
require_once BINGOCOMMERCE_CORE_PATH . 'src/HallStaffEligibility.php';
require_once BINGOCOMMERCE_CORE_PATH . 'src/HallStaffIdentity.php';
require_once BINGOCOMMERCE_CORE_PATH . 'src/HallStaffService.php';
require_once BINGOCOMMERCE_CORE_PATH . 'src/HallStaffCreationIntent.php';
require_once BINGOCOMMERCE_CORE_PATH . 'src/HallStaffCreationRetryToken.php';
require_once BINGOCOMMERCE_CORE_PATH . 'src/HallStaffProvisioningContext.php';
require_once BINGOCOMMERCE_CORE_PATH . 'src/HallStaffInvitation.php';
require_once BINGOCOMMERCE_CORE_PATH . 'src/HallStaffProvisioningService.php';
require_once BINGOCOMMERCE_CORE_PATH . 'src/HallStaffWriterGuard.php';
require_once BINGOCOMMERCE_CORE_PATH . 'src/HallStaffAdmin.php';
require_once BINGOCOMMERCE_CORE_PATH . 'src/StoreDetails.php';
require_once BINGOCOMMERCE_CORE_PATH . 'src/StoreDetailsAdmin.php';
require_once BINGOCOMMERCE_CORE_PATH . 'src/HomepageSlider.php';
require_once BINGOCOMMERCE_CORE_PATH . 'src/HomepageAdmin.php';
require_once BINGOCOMMERCE_CORE_PATH . 'src/StorefrontStyleGuard.php';
require_once BINGOCOMMERCE_CORE_PATH . 'src/Plugin.php';

BingoCommerce\Core\Plugin::boot();
