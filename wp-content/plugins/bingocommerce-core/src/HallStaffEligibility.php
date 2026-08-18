<?php

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BR-036 — network-wide operational identity eligibility.
 *
 * STRICT AND FAIL-CLOSED. An identity may hold a protected hall role only when
 * every capability row it holds network-wide is canonical and contains
 * exclusively protected roles. Anything unexpected — duplicates, undecodable
 * values, mixed roles, unknown roles, multi-role rows, non-true values — is a
 * rejection, never a silent pass.
 *
 * READ-ONLY. This gate never repairs, normalizes, or writes anything. It is a
 * pre-transaction check and is deliberately outside the ADR-025 boundary; the
 * accepted residual TOCTOU window is recorded in BR-036.
 *
 * It never reads provenance metadata (bc_identity_type). Accounts predating
 * Create/Invite carry no marker and remain fully eligible.
 */
final class HallStaffEligibility {

	/**
	 * Capability meta keys that represent site membership.
	 *
	 * Matches the unprefixed primary-site key and every blog-prefixed key. Kept
	 * deliberately identical in shape to HallStaffWriterGuard's protected-key
	 * pattern so the two can never disagree about what a capability key is.
	 */
	private const CAPABILITY_KEY_SQL_REGEX = '^%s([1-9][0-9]*_)?capabilities$';

	/**
	 * Determine whether an identity may hold a protected hall role.
	 *
	 * @return true|\WP_Error
	 */
	public static function assert_operational( int $user_id ) {
		global $wpdb;

		if ( $user_id < 1 ) {
			return self::denied();
		}

		$pattern = sprintf( self::CAPABILITY_KEY_SQL_REGEX, $wpdb->base_prefix );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT umeta_id, meta_key, meta_value FROM {$wpdb->usermeta}"
				. ' WHERE user_id = %d AND meta_key REGEXP %s ORDER BY meta_key, umeta_id',
				$user_id,
				$pattern
			),
			ARRAY_A
		);

		// get_results() returns an empty array both for "no rows" and for a failed
		// query, so absence must never be inferred from it alone. last_error is
		// reset at the start of every query, so it is authoritative here.
		// Database uncertainty is never evidence of eligibility.
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return new \WP_Error(
				'eligibility_read_failed',
				__( 'Hall Staff eligibility could not be verified. No hall access has been granted. Retry or contact Platform Admin.', 'bingocommerce-core' ),
				[ 'status' => 'eligibility_read_failed' ]
			);
		}

		$seen = [];

		foreach ( $rows as $row ) {
			$key = (string) $row['meta_key'];

			// Duplicate membership rows for one site: fail closed.
			if ( isset( $seen[ $key ] ) ) {
				return self::denied();
			}

			$seen[ $key ] = true;

			if ( ! self::is_canonical_protected_value( $row['meta_value'] ) ) {
				return self::denied();
			}
		}

		return true;
	}

	/**
	 * Is this a canonical single protected-role capability value?
	 *
	 * @param mixed $value Raw serialized meta_value straight from the database.
	 */
	private static function is_canonical_protected_value( $value ): bool {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}

		// Guarded decode, matching the Phase A transport discipline: never
		// instantiate objects from stored capability data.
		$decoded = @unserialize( $value, [ 'allowed_classes' => false ] );

		if ( ! is_array( $decoded ) || 1 !== count( $decoded ) ) {
			return false;
		}

		$role = array_key_first( $decoded );

		if ( ! is_string( $role ) || true !== $decoded[ $role ] ) {
			return false;
		}

		return in_array( $role, [ Roles::HALL_MANAGER, Roles::GAME_OPERATOR ], true );
	}

	/**
	 * Count the capability memberships an identity currently holds network-wide.
	 *
	 * Used only by provisioning-stage recovery, which requires a target that is
	 * still genuinely UNASSIGNED. Counts the unprefixed primary-site key as well
	 * as every blog-prefixed key — the same key set this class evaluates above.
	 *
	 * @return int|\WP_Error
	 */
	public static function count_memberships( int $user_id ) {
		global $wpdb;

		$pattern = sprintf( self::CAPABILITY_KEY_SQL_REGEX, $wpdb->base_prefix );

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key REGEXP %s",
				$user_id,
				$pattern
			)
		);

		if ( null === $count || '' !== (string) $wpdb->last_error ) {
			return new \WP_Error(
				'membership_read_failed',
				__( 'The membership state could not be read.', 'bingocommerce-core' )
			);
		}

		return (int) $count;
	}

	private static function denied(): \WP_Error {
		return new \WP_Error(
			'non_operational_identity_not_eligible',
			__( 'This email has an existing non-operational site role. Use a separate operational staff email.', 'bingocommerce-core' )
		);
	}
}
