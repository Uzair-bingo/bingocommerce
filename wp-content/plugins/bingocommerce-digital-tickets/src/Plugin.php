<?php

declare(strict_types=1);

namespace BingoCommerce\DigitalTickets;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Plugin {

	public static function boot( string $file ): void {
		add_action( 'before_woocommerce_init', static function () use ( $file ): void {
			if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', $file, true );
			}
		} );
		add_action( 'plugins_loaded', [ self::class, 'init' ] );
		register_activation_hook( $file, [ Migrations::class, 'activate' ] );
	}

	public static function init(): void {
		// Register even if a dependency is missing: the payment observer must report
		// an unavailable snapshot boundary, not silently reinterpret it as Paper.
		add_action( 'woocommerce_payment_complete', [ EntitlementSnapshot::class, 'on_payment_complete' ], 10 );
		add_action( 'bingocommerce_package_refunded', [ EntitlementVoiding::class, 'on_package_refunded' ], 10, 3 );
		add_action( 'woocommerce_order_status_cancelled', [ EntitlementVoiding::class, 'on_cancelled' ], 10, 3 );
		add_action( 'admin_init', [ Migrations::class, 'maybe_migrate_current_site' ] );
		add_action( 'network_admin_menu', [ Migrations::class, 'maybe_migrate_network' ], 7 );
		add_action( 'wp_initialize_site', [ Migrations::class, 'provision_new_site' ], 50 );
	}
}
