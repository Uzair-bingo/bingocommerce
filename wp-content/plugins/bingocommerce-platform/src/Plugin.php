<?php

namespace BingoCommerce\Platform;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the one platform-owned provisioning consumer and nothing else.
 *
 * NO ROUTE, NO MENU, NO CRON, NO SCHEDULER, NO AUTOMATIC MIGRATION. This plugin
 * exists to answer exactly one synchronous question asked by
 * `bingocommerce-portal-integration`: create or resume the platform-owned parts
 * of one Hall site. It never decides WHETHER a Hall should be provisioned, never
 * authenticates a request, never reads a Portal payload, and never writes a
 * provisioning-operation row, Hall mapping or Commerce state.
 *
 * The filter is registered on `plugins_loaded` rather than at include time so
 * the classes WooCommerce, Core, Catalog and Sessions own are already loadable
 * by the time an orchestrator can reach the consumer.
 */
final class Plugin {

	/** ADR-042's single cross-plugin provisioning contract. */
	public const PROVISION_FILTER = 'bingocommerce_platform_provision_hall_site';

	public static function boot(): void {
		add_action( 'plugins_loaded', [ self::class, 'init' ] );
	}

	public static function init(): void {
		/*
		 * Priority 10 with two accepted arguments, matching the decided call
		 * contract exactly: apply_filters( PROVISION_FILTER, $seed, $command ).
		 * Registered unconditionally, because the consumer's own seed rule —
		 * act only when $seed is null — is what keeps it from competing with
		 * anything, and a conditional registration would make "no consumer"
		 * (platform_unavailable) depend on load order rather than on intent.
		 */
		add_filter( self::PROVISION_FILTER, [ HallSiteProvisioner::class, 'provision' ], 10, 2 );
	}
}
