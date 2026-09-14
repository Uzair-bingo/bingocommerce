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
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/PortalHttpClient.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/PortalSnapshotValidator.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/PortalSyncState.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/PortalSyncLock.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/PortalSyncResult.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/PortalSnapshotApplicationPlan.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/PortalSnapshotTransactionTransport.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/PortalSyncService.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/PortalSyncAdmin.php';
// Read-only setup-prerequisite evaluation. Loaded only; it registers no hook,
// no menu and no route, so its presence cannot change any request's behaviour.
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/SetupReadiness.php';
// Commerce-state persistence. Definitions only: ProvisioningMigrations has no
// activation, init, admin_init or cron trigger, so loading these files cannot
// apply the schema, and HallCommerceState changes no request's behaviour.
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/ProvisioningMigrations.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/HallCommerceState.php';
// ADR-042 provisioning persistence foundation. Definitions only: there is no
// REST route, no platform provisioning filter, no site creation, no Hall-mapping
// mutation and no automatic trigger, so loading these files cannot provision
// anything or change any request's behaviour.
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/ProvisioningCanonicalRequest.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/ProvisioningMutex.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/ProvisioningOperationRepository.php';
// Commerce access enforcement. HallCommerceAccess reads only; HallCommerceGate
// refuses requests to a blocked Hall and is registered by Plugin::boot() at the
// earliest boundary where the current user can be resolved.
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/HallCommerceAccess.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/HallCommerceGate.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/Plugin.php';

BingoCommerce\PortalIntegration\Plugin::boot();
