<?php

namespace BingoCommerce\Sessions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	/**
	 * Dependency verification runs on `plugins_loaded`, never at file-include
	 * time, so correctness never depends on plugin load or activation order.
	 * Sessions consumes Core (hall context, audit) and Catalog (PackageService),
	 * and both may be included after this file.
	 */
	public static function boot(): void {
		add_action( 'plugins_loaded', [ self::class, 'init' ] );
	}

	public static function init(): void {
		// Cart guards must still fail closed when a domain dependency is missing.
		CustomerCart::register();
		$missing = self::missing_dependencies();

		if ( [] !== $missing ) {
			// Fail closed: register no writer, no admin screen, no migration hook.
			// A missing dependency is never interpreted as "no Packages exist".
			add_action(
				'admin_notices',
				static function () use ( $missing ): void {
					self::render_missing_dependency_notice( $missing );
				}
			);

			return;
		}

		SessionAdmin::register();

		/*
		 * The two ADR-043 schedule screens attach as submenus of the Bingo
		 * Sessions page SessionAdmin registers, so they are registered here,
		 * behind the same dependency gate: a hall missing Core or Catalog gets
		 * no schedule surface either, rather than a screen whose every action
		 * would fail.
		 */
		SessionScheduleAdmin::register();
		SessionTemplateAdmin::register();

		add_action( 'network_admin_menu', [ Migrations::class, 'maybe_migrate_network' ], 6 );
		add_action( 'admin_init', [ Migrations::class, 'maybe_migrate_current_site' ], 8 );
		add_action( 'wp_initialize_site', [ Migrations::class, 'provision_new_site' ], 40 );
	}

	/**
	 * The ACTUAL classes and APIs this plugin uses, not a plugin-file guess.
	 *
	 * @return array<int,string>
	 */
	public static function missing_dependencies(): array {
		$missing = [];

		if ( ! class_exists( '\BingoCommerce\Core\HallContext' ) ) {
			$missing[] = 'BingoCommerce\Core\HallContext';
		}
		if ( ! class_exists( '\BingoCommerce\Core\AuditLog' ) ) {
			$missing[] = 'BingoCommerce\Core\AuditLog';
		}
		if ( ! PackageGateway::is_available() ) {
			$missing[] = 'BingoCommerce\Catalog\PackageService';
		}

		return $missing;
	}

	/** @param array<int,string> $missing */
	public static function render_missing_dependency_notice( array $missing ): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p><?php echo esc_html__( 'BingoCommerce Sessions is inactive because a required dependency is unavailable. Session management is disabled rather than running without it.', 'bingocommerce-sessions' ); ?></p>
			<p><?php echo esc_html( implode( ', ', $missing ) ); ?></p>
		</div>
		<?php
	}
}
