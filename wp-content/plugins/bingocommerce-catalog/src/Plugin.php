<?php

namespace BingoCommerce\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	public static function boot(): void {
		add_action( 'plugins_loaded', [ self::class, 'init' ] );
	}

	public static function init(): void {
		if (
			! class_exists( '\BingoCommerce\Core\HallContext' )
			|| ! class_exists( '\BingoCommerce\Core\AuditLog' )
		) {
			add_action( 'admin_notices', [ self::class, 'render_missing_core_notice' ] );
			return;
		}

		GameTypeAdmin::register();
		PlayProductAdmin::register();
		PackageAdmin::register();
		ElectronicLimitSettings::register();

		add_action( 'network_admin_menu', [ Migrations::class, 'maybe_migrate_network' ], 6 );
		add_action( 'admin_init', [ Migrations::class, 'maybe_migrate_current_site' ], 7 );
		add_action( 'wp_initialize_site', [ Migrations::class, 'provision_new_site' ], 30 );
	}

	public static function render_missing_core_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		?>
		<div class="notice notice-error"><p><?php echo esc_html__( 'BingoCommerce Catalog requires BingoCommerce Core.', 'bingocommerce-catalog' ); ?></p></div>
		<?php
	}
}
