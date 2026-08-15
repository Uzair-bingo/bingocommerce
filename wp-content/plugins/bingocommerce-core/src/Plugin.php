<?php

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	public static function boot(): void {
		add_action( 'plugins_loaded', [ self::class, 'init' ] );
	}

	public static function init(): void {
		HallStaffWriterGuard::register();
		HallStaffAdmin::register();

		// Priority 5: core-owned schema is applied before any handler that may
		// come to depend on it. Gated internally by a network schema version,
		// so a current network costs one cached option read per request.
		add_action( 'admin_init', [ Migrations::class, 'maybe_migrate_network' ], 5 );

		add_action( 'admin_init', [ Roles::class, 'maybe_provision_network' ] );
		add_action( 'admin_init', [ Roles::class, 'maybe_sync_current_site' ] );
		add_action( 'wp_initialize_site', [ Roles::class, 'provision_new_site' ], 20 );
	}
}
