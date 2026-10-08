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
// ADR-042 provisioning persistence foundation. Definitions only: writing a row
// changes no request's behaviour.
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/ProvisioningCanonicalRequest.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/ProvisioningMutex.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/ProvisioningOperationRepository.php';
// ADR-042 machine provisioning path. ProvisioningRestController is the ONLY file
// here that registers anything: one PUT route on `rest_api_init`, reachable only
// with Application Password proof plus `bc_receive_portal_provisioning`. The
// remaining four are definitions — a response builder, the cross-plugin platform
// boundary, the terminal audit and the state machine — and register no hook,
// cron event, scheduler or webhook of their own.
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/ProvisioningResponse.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/PlatformGateway.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/ProvisioningAudit.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/ProvisioningOrchestrator.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/ProvisioningRestController.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/RedemptionGateway.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/RedemptionRestController.php';
// Commerce access enforcement. HallCommerceAccess reads only; HallCommerceGate
// refuses requests to a blocked Hall and is registered by Plugin::boot() at the
// earliest boundary where the current user can be resolved.
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/HallCommerceAccess.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/HallCommerceGate.php';
require_once BINGOCOMMERCE_PORTAL_INTEGRATION_PATH . 'src/Plugin.php';

BingoCommerce\PortalIntegration\Plugin::boot();
