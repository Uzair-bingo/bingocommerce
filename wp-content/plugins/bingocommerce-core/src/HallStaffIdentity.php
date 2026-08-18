<?php

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authoritative, cache-bypassing resolution of an operational identity.
 *
 * `bc_users` has NO unique constraint on `user_email` or `user_login` — only the
 * primary key is unique — so an email can legitimately match more than one row
 * after a concurrent or external writer creates a colliding account. WordPress's
 * own `get_user_by( 'email', … )` resolves through the object cache and silently
 * returns whichever single row it finds first, which would let a later request
 * arbitrarily pick one of two duplicates and grant it a protected hall role.
 *
 * This resolver refuses to guess. An identity is resolved only when the email
 * matches exactly one row AND that row's login also matches exactly one row and
 * is the same user. Anything else — duplicates on either column, or database
 * uncertainty — fails closed with no assignment.
 */
final class HallStaffIdentity {

	/**
	 * Resolve an email to exactly one operational identity.
	 *
	 * @return int|null|\WP_Error User ID, null when genuinely absent, or a
	 *                            controlled failure. Never a guess.
	 */
	public static function resolve_unique( string $email ) {
		global $wpdb;

		if ( '' === $email ) {
			return null;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT ID, user_login FROM {$wpdb->users} WHERE user_email = %s", $email ),
			ARRAY_A
		);

		// get_results() returns an empty array for both "no rows" and "query
		// failed", so last_error is what separates absence from uncertainty.
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return self::read_failed();
		}

		if ( 0 === count( $rows ) ) {
			return null;
		}

		if ( count( $rows ) > 1 ) {
			return self::conflict();
		}

		$user_id = (int) $rows[0]['ID'];
		$login   = (string) $rows[0]['user_login'];

		// The login must be as unambiguous as the email: a second account sharing
		// this login is the same class of collision and equally unsafe to act on.
		$logins = $wpdb->get_col(
			$wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_login = %s", $login )
		);

		if ( ! is_array( $logins ) || '' !== (string) $wpdb->last_error ) {
			return self::read_failed();
		}

		if ( 1 !== count( $logins ) || (int) $logins[0] !== $user_id ) {
			return self::conflict();
		}

		return $user_id;
	}

	private static function read_failed(): \WP_Error {
		return new \WP_Error(
			'identity_read_failed',
			__( 'The staff account could not be verified. No hall access has been granted. Retry or contact Platform Admin.', 'bingocommerce-core' ),
			[ 'status' => 'identity_read_failed' ]
		);
	}

	private static function conflict(): \WP_Error {
		return new \WP_Error(
			'duplicate_identity_conflict',
			__( 'More than one account shares this email address or username. No hall access has been granted. Contact Platform Admin.', 'bingocommerce-core' ),
			[ 'status' => 'duplicate_identity_conflict' ]
		);
	}
}
