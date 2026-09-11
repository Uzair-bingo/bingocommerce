<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	/**
	 * Where Commerce access enforcement sits on `plugins_loaded`.
	 *
	 * PHP_INT_MIN rather than a merely small number: this must run before
	 * anything a plugin can register on the same hook. WooCommerce hooks
	 * `plugins_loaded` at -1, and its request handlers run later still
	 * (`wp_loaded`, `parse_request`, `wp`, `template_redirect`), so a blocked
	 * Hall is refused before any of them is reached.
	 */
	public const ENFORCEMENT_PRIORITY = PHP_INT_MIN;

	public static function boot(): void {
		/*
		 * Registered here, at plugin include time, rather than inside init()
		 * below: init() itself runs on `plugins_loaded`, and a hook added from
		 * inside a hook is a fragile place to put a security boundary.
		 * `plugins_loaded` is the earliest boundary where the current user can
		 * be resolved — pluggable.php loads immediately before it — which is
		 * what makes Network Super Admin recovery access possible.
		 */
		add_action( 'plugins_loaded', [ HallCommerceGate::class, 'enforce' ], self::ENFORCEMENT_PRIORITY );

		add_action( 'plugins_loaded', [ self::class, 'init' ] );
	}

	public static function init(): void {
		// This plugin owns one network-wide table, so no per-site migration or
		// new-site provisioning hook is required.
		add_action( 'admin_init', [ Migrations::class, 'maybe_migrate_network' ], 6 );

		// Phase 3G: the manual hall-scoped synchronization screen and its
		// authenticated admin-post handler. Registered unconditionally so the
		// handler itself — not menu visibility — enforces tenancy, capability
		// and nonce. Still no REST route, cron event, webhook or scheduler.
		PortalSyncAdmin::register();
	}

	/**
	 * The domain replicas Phase 3E writes into.
	 *
	 * Checked at call time rather than at boot, because the answer must reflect
	 * the moment of the attempt: a boot-time result could be stale by the time
	 * PortalSyncAdmin renders readiness or handles a POST. PortalSyncAdmin fails
	 * closed on a non-empty result before any Portal fetch or lock, and
	 * PortalSyncService and the transport both re-check independently, so no
	 * caller can be reached through a stale answer.
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
