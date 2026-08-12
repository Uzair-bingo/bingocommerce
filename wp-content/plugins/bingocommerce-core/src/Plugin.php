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
		add_action( 'admin_init', [ Roles::class, 'maybe_provision_network' ] );
		add_action( 'admin_init', [ Roles::class, 'maybe_sync_current_site' ] );
		add_action( 'wp_initialize_site', [ Roles::class, 'provision_new_site' ], 20 );
	}
}
