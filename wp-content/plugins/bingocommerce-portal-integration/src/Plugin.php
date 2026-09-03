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
}
