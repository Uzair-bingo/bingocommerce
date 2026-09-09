<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	public static function boot(): void {
		add_action( 'plugins_loaded', [ self::class, 'init' ] );
	}

	public static function init(): void {
		// This plugin owns one network-wide table, so no per-site migration or
		// new-site provisioning hook is required.
		add_action( 'admin_init', [ Migrations::class, 'maybe_migrate_network' ], 6 );
	}

	/**
	 * The domain replicas Phase 3E writes into.
	 *
	 * Checked at call time rather than at boot: this plugin registers no admin
	 * screen, no REST route, and no cron event, so an absent Catalog or Sessions
	 * plugin must fail the synchronization attempt, not disable a surface that
	 * does not exist. PortalSyncService and the transport both re-check
	 * independently, so neither can be reached through a stale boot-time answer.
	 *
	 * @return array<int,string>
	 */
	public static function missing_sync_dependencies(): array {
		$missing = [];

		if ( ! class_exists( '\BingoCommerce\Catalog\Migrations' ) ) {
			$missing[] = 'BingoCommerce\Catalog\Migrations';
		}
		if ( ! class_exists( '\BingoCommerce\Sessions\Migrations' ) ) {
			$missing[] = 'BingoCommerce\Sessions\Migrations';
		}

		return $missing;
	}
}
