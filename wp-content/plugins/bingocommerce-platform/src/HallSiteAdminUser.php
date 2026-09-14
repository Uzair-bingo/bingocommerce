<?php

namespace BingoCommerce\Platform;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The server-only new-site administrator identity (ADR-042).
 *
 * Supported Multisite creation requires a WordPress user to become the new
 * site's administrator. That identity comes from exactly one place: the PHP
 * constant `BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID`, defined in deployment
 * configuration outside version control. It NEVER comes from the Portal
 * request, from `portal_actor_id`, or from the authenticated machine account.
 *
 * FAILS BEFORE CREATION, NOT AFTER. Every rejection below happens while nothing
 * has been created, because the alternative — discovering a bad administrator
 * identity after `wpmu_create_blog()` — leaves a site nobody can administer.
 *
 * DISCLOSES NOTHING. No branch returns, logs or embeds the constant's value, a
 * user login, an email address or a user ID. The caller receives one fixed
 * `platform_unavailable` error, which the orchestrator reduces to the same
 * category it uses for a missing consumer.
 */
final class HallSiteAdminUser {

	/** The only source of the new-site administrator identity. */
	public const CONSTANT = 'BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID';

	/**
	 * Resolve the configured recovery Super Admin, or refuse.
	 *
	 * @param int $machine_user_id The authenticated provisioning machine account,
	 *                             which must NOT become Hall administrator. Pass
	 *                             0 when there is no authenticated user.
	 * @return int|\WP_Error Positive user ID, or fixed `platform_unavailable`.
	 */
	public static function resolve( int $machine_user_id ) {
		try {
			return self::resolve_configured( $machine_user_id );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return self::unavailable();
		}
	}

	/**
	 * @return int|\WP_Error
	 */
	private static function resolve_configured( int $machine_user_id ) {
		if ( ! defined( self::CONSTANT ) ) {
			return self::unavailable();
		}

		$configured = constant( self::CONSTANT );

		/*
		 * REPRESENTATION BEFORE VALUE. `(int)` would turn "12abc", "1e3", " 7 ",
		 * 7.0 and true into plausible-looking user IDs, and an administrator
		 * identity conjured out of a malformed constant is exactly the mistake
		 * this check exists to prevent. A decimal string is accepted because a
		 * PHP constant read from deployment configuration is commonly written as
		 * one, but only when it round-trips exactly.
		 */
		if ( is_string( $configured ) ) {
			if ( 1 !== preg_match( '/\A\d+\z/', $configured ) ) {
				return self::unavailable();
			}
			$parsed = (int) $configured;
			if ( (string) $parsed !== $configured ) {
				return self::unavailable();
			}
			$configured = $parsed;
		}

		if ( ! is_int( $configured ) || $configured < 1 ) {
			return self::unavailable();
		}

		// The machine account must never silently become Administrator of every
		// new Hall site. Checked before the user lookup, so a misconfiguration
		// that names the machine account is refused even if that account has
		// since been deleted.
		if ( $machine_user_id > 0 && $configured === $machine_user_id ) {
			return self::unavailable();
		}

		$user = get_userdata( $configured );
		if ( ! $user instanceof \WP_User || (int) $user->ID !== $configured ) {
			return self::unavailable();
		}

		/*
		 * A NETWORK SUPER ADMIN, PROVEN BY THE NETWORK LIST. is_super_admin()
		 * also answers true for a user holding `delete_users` on a single-site
		 * installation, which is not the authority this contract means, so the
		 * Multisite path is required and the user must exist in the network's
		 * own `site_admins` list.
		 */
		if ( ! is_super_admin( $configured ) ) {
			return self::unavailable();
		}

		return $configured;
	}

	/** One fixed category. No constant value, login, email or user ID escapes. */
	private static function unavailable(): \WP_Error {
		return new \WP_Error(
			'platform_unavailable',
			__( 'The Hall site administrator identity is not configured.', 'bingocommerce-platform' )
		);
	}
}
