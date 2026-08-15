<?php

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns BingoCommerce hall role slugs, the bc_* capability model, and
 * idempotent per-site role synchronization.
 *
 * Authorization must always be checked via current_user_can( 'bc_...' ),
 * never via role-name comparison. Role slugs below are assignment bundles,
 * not an authorization mechanism.
 */
final class Roles {

	const HALL_MANAGER  = 'bc_hall_manager';
	const GAME_OPERATOR = 'bc_game_operator';

	/**
	 * Bump when HALL_MANAGER_CAPS / GAME_OPERATOR_CAPS / PROHIBITED_NATIVE_CAPS
	 * change, so existing sites get re-synced.
	 */
	const SCHEMA_VERSION = 1;

	/** Per-site option: schema version currently applied on this site. */
	const SITE_VERSION_OPTION = 'bc_core_role_schema_version';

	/** Network option: schema version applied by the one-time network-wide pass. */
	const NETWORK_VERSION_OPTION = 'bc_core_roles_network_provisioned_version';

	/**
	 * Ratified bc_* capability model defined by BR-027.
	 */
	const HALL_MANAGER_CAPS = [
		'bc_manage_hall',
		'bc_manage_hall_staff',
		'bc_manage_hall_settings',
		'bc_manage_products',
		'bc_manage_orders',
		'bc_manage_sessions',
		'bc_view_orders',
		'bc_view_sessions',
		'bc_redeem_tickets',
		'bc_reactivate_tickets',
	];

	const GAME_OPERATOR_CAPS = [
		'bc_view_orders',
		'bc_view_sessions',
		'bc_redeem_tickets',
		'bc_reactivate_tickets',
	];

	/**
	 * Native capabilities that must never remain on a hall role, regardless
	 * of source (copied baseline, manual edit, future WooCommerce changes).
	 */
	const PROHIBITED_NATIVE_CAPS = [
		'install_plugins',
		'activate_plugins',
		'delete_plugins',
		'update_plugins',
		'edit_plugins',
		'install_themes',
		'switch_themes',
		'edit_themes',
		'delete_themes',
		'update_themes',
		'manage_network',
		'manage_network_options',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_users',
		'manage_network_sites',
		'promote_users',
		'delete_users',
	];

	/**
	 * One-time network-wide catch-up. Gated by NETWORK_VERSION_OPTION so the
	 * get_sites() loop runs at most once per SCHEMA_VERSION, not on every
	 * request. Individual sites left incomplete this pass (e.g. WooCommerce
	 * Shop Manager not yet installed there) self-heal via maybe_sync_current_site().
	 */
	public static function maybe_provision_network(): void {
		if ( ! is_multisite() ) {
			return;
		}

		if ( (int) get_site_option( self::NETWORK_VERSION_OPTION, 0 ) >= self::SCHEMA_VERSION ) {
			return;
		}

		foreach ( get_sites( [ 'number' => 0 ] ) as $site ) {
			switch_to_blog( (int) $site->blog_id );
			try {
				if ( HallContext::is_hall() ) {
					self::sync_current_site();
				}
			} finally {
				restore_current_blog();
			}
		}

		update_site_option( self::NETWORK_VERSION_OPTION, self::SCHEMA_VERSION );
	}

	/**
	 * Lightweight per-site reconciliation. Cheap on every admin_init once
	 * the site is current (single option read, no site loop). Catches
	 * sites the network pass left incomplete, and sites created directly
	 * via provision_new_site() before WooCommerce Shop Manager existed.
	 */
	public static function maybe_sync_current_site(): void {
		if ( ! HallContext::is_hall() ) {
			return;
		}

		if ( (int) get_option( self::SITE_VERSION_OPTION, 0 ) >= self::SCHEMA_VERSION ) {
			return;
		}

		self::sync_current_site();
	}

	/**
	 * wp_initialize_site handler for newly created halls. Registered at
	 * priority 20 so it runs after core's wp_initialize_site_default
	 * (priority 10), which is what actually creates the site's tables and
	 * populates its default WP roles. WooCommerce does not hook
	 * wp_initialize_site in this codebase (verified against the vendored
	 * plugin), so Shop Manager will not exist yet at this point — that is
	 * expected and handled by sync_current_site()'s incomplete-sync path.
	 */
	public static function provision_new_site( \WP_Site $new_site ): void {
		if ( ! is_multisite() ) {
			return;
		}

		switch_to_blog( (int) $new_site->blog_id );
		try {
			if ( HallContext::is_hall() ) {
				self::sync_current_site();
			}
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Synchronize both hall roles on the CURRENT site. Idempotent: safe to
	 * call repeatedly, only writes capabilities that actually differ from
	 * the desired state.
	 *
	 * Returns true only when Hall Manager's native WooCommerce baseline was
	 * fully applied (i.e. Shop Manager existed on this site at sync time).
	 * Callers use this to decide whether the site's schema version may be
	 * marked complete.
	 */
	public static function sync_current_site(): bool {
		if ( ! HallContext::is_hall() ) {
			return true;
		}

		$hall_manager_complete = self::sync_hall_manager();
		self::sync_game_operator();

		if ( $hall_manager_complete ) {
			update_option( self::SITE_VERSION_OPTION, self::SCHEMA_VERSION );
		}

		return $hall_manager_complete;
	}

	/**
	 * Create/update Hall Manager. Native baseline is copied intentionally
	 * from the live Shop Manager role (not hardcoded — avoids drifting from
	 * WooCommerce's own definition), with prohibited capabilities stripped
	 * and bc_* capabilities added on top.
	 *
	 * Returns false (without leaving the role destroyed or half-applied in
	 * a misleading "done" state) when Shop Manager does not exist yet on
	 * this site.
	 */
	private static function sync_hall_manager(): bool {
		$role = get_role( self::HALL_MANAGER );
		if ( null === $role ) {
			add_role( self::HALL_MANAGER, __( 'Hall Manager', 'bingocommerce-core' ), [] );
			$role = get_role( self::HALL_MANAGER );
		}

		if ( null === $role ) {
			return false;
		}

		$shop_manager               = get_role( 'shop_manager' );
		$native_baseline_available = null !== $shop_manager;

		$desired = [ 'read' => true ];

		if ( $native_baseline_available ) {
			foreach ( $shop_manager->capabilities as $cap => $granted ) {
				if ( $granted ) {
					$desired[ $cap ] = true;
				}
			}
		}

		foreach ( self::PROHIBITED_NATIVE_CAPS as $cap ) {
			unset( $desired[ $cap ] );
		}

		foreach ( self::HALL_MANAGER_CAPS as $cap ) {
			$desired[ $cap ] = true;
		}

		self::apply_capabilities( $role, $desired );

		return $native_baseline_available;
	}

	/**
	 * Create/update Game Operator. Deliberately minimal: no WooCommerce
	 * management baseline, just enough native capability to reach
	 * wp-admin plus the approved bc_* bundle.
	 */
	private static function sync_game_operator(): void {
		$role = get_role( self::GAME_OPERATOR );
		if ( null === $role ) {
			add_role( self::GAME_OPERATOR, __( 'Game Operator', 'bingocommerce-core' ), [] );
			$role = get_role( self::GAME_OPERATOR );
		}

		if ( null === $role ) {
			return;
		}

		$desired = [ 'read' => true ];

		foreach ( self::GAME_OPERATOR_CAPS as $cap ) {
			$desired[ $cap ] = true;
		}

		self::apply_capabilities( $role, $desired );
	}

	/**
	 * Converge a role's live capabilities to EXACTLY the desired set,
	 * without remove_role()/add_role(). bc_hall_manager and bc_game_operator
	 * are fully owned/managed by BingoCommerce — nothing on them is
	 * incidental, so every capability present that is not in $desired is
	 * removed, and every capability in $desired that is missing is added.
	 * No native capability is ever left untouched just because it's
	 * unrecognized; prohibited capabilities are already excluded from
	 * $desired before this runs, so they cannot survive reconciliation.
	 */
	private static function apply_capabilities( \WP_Role $role, array $desired ): void {
		foreach ( $desired as $cap => $granted ) {
			if ( $granted && empty( $role->capabilities[ $cap ] ) ) {
				$role->add_cap( $cap );
			}
		}

		foreach ( array_keys( $role->capabilities ) as $cap ) {
			if ( empty( $desired[ $cap ] ) ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
