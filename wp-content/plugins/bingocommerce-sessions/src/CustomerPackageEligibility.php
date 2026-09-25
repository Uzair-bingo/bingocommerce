<?php

namespace BingoCommerce\Sessions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only customer eligibility for one Package on one exact occurrence.
 *
 * This is intentionally separate from SessionEligibility::is_sellable(). The
 * latter answers whether a generated occurrence has at least one Package that
 * remains assignable under ADR-043. This class answers the later, narrower
 * BR-080 question for the customer's exact Package selection and therefore
 * rejects an otherwise-assignable Package with an archived required component.
 *
 * NO AUTHORIZATION, BY DESIGN. A signed-out customer asks this question. Every
 * management read and mutation remains behind its existing capability boundary.
 * No method here writes, audits, changes sales state, or rewrites relationships.
 */
final class CustomerPackageEligibility {

	/**
	 * @return bool|\WP_Error `true`, ordinary ineligibility as `false`, or an
	 *                        unreadable dependency as a fail-closed WP_Error.
	 */
	public static function is_available( int $session_id, int $package_id ) {
		if ( $session_id < 1 || $package_id < 1 ) {
			return false;
		}

		$session = SessionEligibility::is_sellable( $session_id );
		if ( true !== $session ) {
			return $session;
		}

		global $wpdb;

		$assigned = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM ' . Migrations::session_packages_table() . ' WHERE session_id = %d AND package_id = %d LIMIT 1',
				$session_id,
				$package_id
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return self::unreadable( 'the exact occurrence Package assignment could not be read', (string) $wpdb->last_error );
		}
		if ( '1' !== (string) $assigned ) {
			return false;
		}

		$package = ( new PackageGateway() )->getForCustomerPackageEligibility( $package_id );
		if ( is_wp_error( $package ) ) {
			return self::unreadable( 'the Bingo Package could not be read', $package->get_error_code() );
		}
		if ( ! is_array( $package ) || true !== PackageGateway::assert_assignable( $package ) ) {
			return false;
		}

		/*
		 * assert_assignable() has already proved a non-empty list of resolved
		 * components with recognised lifecycle values. It deliberately permits an
		 * archived component for schedule/occurrence assignment. BR-080 adds only
		 * this customer-sale distinction; it changes no assignment or lifecycle.
		 */
		foreach ( $package['components'] as $component ) {
			if ( 'archived' === $component['play_product_status'] ) {
				return false;
			}
		}

		return true;
	}

	private static function unreadable( string $detail, string $cause ): \WP_Error {
		return new \WP_Error(
			'package_eligibility_unknown',
			__( 'Whether this Bingo Package can be purchased for the selected Session could not be determined, so it is treated as unavailable.', 'bingocommerce-sessions' ),
			[
				'status' => 'package_eligibility_unknown',
				'detail' => $detail,
				'cause'  => $cause,
			]
		);
	}
}
