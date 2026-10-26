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

		// ADR-045: Hall public storefront profile; rendering stays in the theme.
		StoreDetails::register();
		StoreDetailsAdmin::register();
		HomepageSlider::register();
		HomepageCategories::register();
		HomepageTrust::register();
		HomepageTestimonials::register();
		HomepageAdmin::register();
		// BR-084: fixed v1 storefront fonts/colours; global-styles writes are Platform Admin only on Halls.
		StorefrontStyleGuard::register();

		// Priority 5: core-owned schema is applied before any handler that may
		// come to depend on it. Gated internally by a network schema version,
		// so a current network costs one cached option read per request.
		add_action( 'admin_init', [ Migrations::class, 'maybe_migrate_network' ], 5 );

		add_action( 'admin_init', [ Roles::class, 'maybe_provision_network' ] );
		add_action( 'admin_init', [ Roles::class, 'maybe_sync_current_site' ] );
		add_action( 'wp_initialize_site', [ Roles::class, 'provision_new_site' ], 20 );
	}
}
