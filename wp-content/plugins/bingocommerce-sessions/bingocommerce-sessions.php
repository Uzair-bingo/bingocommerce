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

/*
 * ADR-043 commerce schedules. Required in dependency order, and before
 * SessionService, because the pure classes carry the date, time and name rules
 * the rest of the domain composes.
 */
require_once BINGOCOMMERCE_SESSIONS_PATH . 'src/ScheduleTimezone.php';
require_once BINGOCOMMERCE_SESSIONS_PATH . 'src/ScheduleExpansion.php';
require_once BINGOCOMMERCE_SESSIONS_PATH . 'src/PortalTemplateGateway.php';
require_once BINGOCOMMERCE_SESSIONS_PATH . 'src/SessionScheduleResult.php';
require_once BINGOCOMMERCE_SESSIONS_PATH . 'src/SessionScheduleService.php';

/*
 * ADR-043 §6 availability. OccurrenceAvailability is a collaborator of
 * SessionService and SessionEligibility is the storefront predicate, so both are
 * loaded before it.
 */
require_once BINGOCOMMERCE_SESSIONS_PATH . 'src/OccurrenceAvailability.php';
require_once BINGOCOMMERCE_SESSIONS_PATH . 'src/SessionEligibility.php';
require_once BINGOCOMMERCE_SESSIONS_PATH . 'src/CustomerPackageEligibility.php';

/*
 * ADR-043 §7 reconciliation. DatabaseContention and OccurrenceGenerationWriter
 * are collaborators of OccurrenceReconciler, loaded before it. None of the
 * three is reachable from any admin screen, POST handler or REST route —
 * SessionScheduleService::generate() is the only production entry point.
 */
require_once BINGOCOMMERCE_SESSIONS_PATH . 'src/DatabaseContention.php';
require_once BINGOCOMMERCE_SESSIONS_PATH . 'src/OccurrenceGenerationWriter.php';
require_once BINGOCOMMERCE_SESSIONS_PATH . 'src/OccurrenceReconciler.php';

require_once BINGOCOMMERCE_SESSIONS_PATH . 'src/SessionService.php';
require_once BINGOCOMMERCE_SESSIONS_PATH . 'src/SessionAdmin.php';

/*
 * ADR-043 §15 Manager administration. Both screens attach as submenus of
 * SessionAdmin's page and are loaded after it for that reason. Neither holds a
 * scheduling rule: SessionScheduleAdmin marshals requests into
 * SessionScheduleService, and SessionTemplateAdmin is a read-only window onto
 * the Portal template replica.
 */
require_once BINGOCOMMERCE_SESSIONS_PATH . 'src/SessionScheduleAdmin.php';
require_once BINGOCOMMERCE_SESSIONS_PATH . 'src/SessionTemplateAdmin.php';

require_once BINGOCOMMERCE_SESSIONS_PATH . 'src/Plugin.php';

BingoCommerce\Sessions\Plugin::boot();
