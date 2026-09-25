<?php

namespace BingoCommerce\Sessions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The composed storefront eligibility predicate (ADR-043 §6.4).
 *
 * `SessionService::evaluate_available_today()` is NOT touched — it remains
 * byte-identical, and this class calls it. What is new is everything a
 * SCHEDULE-OWNED occurrence must additionally satisfy before a customer may buy
 * it.
 *
 * FAIL-CLOSED IN EVERY STATE. Any linked fact that cannot be read returns a
 * WP_Error rather than a boolean, and callers must treat WP_Error as NOT
 * sellable and must never coerce it. That covers a failed ON re-proof, an
 * incomplete generation, an unsettled ON transition (§6.5), and a reconciliation
 * run that ended `failed` because a Package became unassignable. The default
 * answer to "can this be sold?" is no.
 *
 * `availability_operation_token` IS DELIBERATELY NOT READ HERE. The token means
 * only that an ON transition has not yet been settled by its owner (§6.5), and
 * an owner may have died. Making sellability depend on it would let a dangling
 * token from a killed process take a genuinely-ON occurrence off sale, and would
 * make sellability depend on whether a PHP process happened to survive. The
 * predicate answers from its own durable facts instead.
 *
 * NO AUTHORIZATION, BY DESIGN. This is the storefront's question, asked on
 * behalf of a customer who is not signed in and holds no hall capability. It
 * reads and never writes.
 */
final class SessionEligibility {

	/**
	 * Shared committed-definition predicate. The alias is an internal SQL
	 * identifier, never request input. Legacy Sessions have no membership rule.
	 */
	public static function current_definition_sql( string $occurrence_alias ): string {
		$schedules = Migrations::schedules_table();
		$dates     = Migrations::schedule_dates_table();

		return "({$occurrence_alias}.schedule_id IS NULL OR EXISTS (
			SELECT 1 FROM {$schedules} membership_schedule
			 WHERE membership_schedule.schedule_id = {$occurrence_alias}.schedule_id
			   AND membership_schedule.active_definition_token IS NOT NULL
			   AND membership_schedule.active_definition_token <> ''
			   AND (
				(membership_schedule.mode = 'dates' AND EXISTS (
					SELECT 1 FROM {$dates} membership_date
					 WHERE membership_date.schedule_id = membership_schedule.schedule_id
					   AND membership_date.definition_token = membership_schedule.active_definition_token
					   AND membership_date.schedule_date = {$occurrence_alias}.session_date
				)) OR (
					membership_schedule.mode = 'weekly'
					AND {$occurrence_alias}.session_date BETWEEN membership_schedule.recurrence_start AND membership_schedule.recurrence_end
					AND membership_schedule.weekday_mask BETWEEN 1 AND 127
					AND (membership_schedule.weekday_mask & (1 << WEEKDAY({$occurrence_alias}.session_date))) <> 0
				)
			   )
		))";
	}

	/** @return bool|\WP_Error An unreadable definition fails closed. */
	public static function belongs_to_current_definition( int $session_id ) {
		global $wpdb;

		$membership = self::current_definition_sql( 's' );
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ' . $membership . ' FROM ' . Migrations::sessions_table() . ' s WHERE s.session_id = %d',
				$session_id
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return self::unreadable( 'the committed schedule definition could not be read', 'schedule_membership_unknown' );
		}

		return '1' === (string) $value;
	}

	/**
	 * Whether one occurrence may be sold right now.
	 *
	 * @return bool|\WP_Error `true`, `false`, or fail-closed WP_Error.
	 */
	public static function is_sellable( int $session_id ) {
		if ( $session_id < 1 ) {
			return false;
		}

		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT session_id, session_date, status, available_for_sale, schedule_id,
				        timezone_name, generation_completed_at
				   FROM ' . Migrations::sessions_table() . ' WHERE session_id = %d',
				$session_id
			),
			ARRAY_A
		);

		if ( '' !== (string) $wpdb->last_error ) {
			return self::unreadable( 'the Bingo Session could not be read', (string) $wpdb->last_error );
		}
		if ( ! is_array( $row ) ) {
			// Definitively absent rather than unreadable: not sellable, and not an
			// error condition the caller needs to distinguish.
			return false;
		}

		$schedule_id = null === $row['schedule_id'] ? 0 : (int) $row['schedule_id'];

		/*
		 * §5.3: which zone decides "today".
		 *
		 * A generated occurrence is evaluated in the zone STORED ON THE ROW, so a
		 * later change to the hall's WordPress timezone setting cannot re-window
		 * an occurrence that already exists. A legacy occurrence keeps
		 * `wp_timezone()`, exactly as at v2.
		 */
		if ( $schedule_id > 0 ) {
			$zone = (string) ( $row['timezone_name'] ?? '' );
			$today = '' === $zone ? null : ScheduleTimezone::local_today( $zone );
			if ( null === $today ) {
				return self::unreadable( 'the occurrence timezone could not be evaluated', $zone );
			}
		} else {
			$today = SessionService::hall_local_today();
		}

		$row['available_for_sale'] = (bool) (int) $row['available_for_sale'];

		// BR-051/BR-063, unchanged. Applies to legacy and generated alike.
		if ( ! SessionService::evaluate_available_today( $row, $today ) ) {
			return false;
		}

		// A legacy occurrence is subject to no further test, which is what keeps
		// v2 behaviour intact for every row a schedule does not own.
		if ( $schedule_id < 1 ) {
			return true;
		}

		/* ---------- everything below applies ONLY to generated occurrences ---------- */

		// An incomplete occurrence is unsellable. Package propagation is not
		// finished, so what the customer would be buying is not yet defined.
		if ( null === $row['generation_completed_at'] ) {
			return false;
		}

		$schedule = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT schedule_id, portal_session_id, status, active_definition_token
				   FROM ' . Migrations::schedules_table() . ' WHERE schedule_id = %d',
				$schedule_id
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return self::unreadable( 'the commerce schedule could not be read', (string) $wpdb->last_error );
		}
		if ( ! is_array( $schedule ) ) {
			return false;
		}
		if ( SessionService::STATUS_ACTIVE !== (string) $schedule['status'] ) {
			// An archived schedule stops its occurrences being sellable with no
			// write at all — which is what makes archiving instant and reversible.
			return false;
		}
		if ( null === $schedule['active_definition_token'] || '' === (string) $schedule['active_definition_token'] ) {
			// Inert: no readable definition, so nothing under it may be sold.
			return false;
		}

		$membership = self::belongs_to_current_definition( $session_id );
		if ( true !== $membership ) {
			return $membership;
		}

		$template = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT portal_session_id, portal_status, is_current
				   FROM ' . Migrations::portal_session_templates_table() . ' WHERE portal_session_id = %d',
				(int) $schedule['portal_session_id']
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return self::unreadable( 'the Portal Session template could not be read', (string) $wpdb->last_error );
		}
		if ( ! is_array( $template ) ) {
			return false;
		}
		if ( 1 !== (int) $template['is_current'] || PortalTemplateGateway::STATUS_ACTIVE !== (string) $template['portal_status'] ) {
			return false;
		}

		return self::has_assignable_package( $session_id );
	}

	/**
	 * At least one occurrence-level Package must still be assignable.
	 *
	 * A Package that cannot be READ is an error, not an absence: under ADR-038
	 * treating an unreadable dependency as "no Packages, therefore nothing to
	 * check" is the difference between refusing a sale and making a silent
	 * integrity decision on a customer's behalf.
	 *
	 * @return bool|\WP_Error
	 */
	private static function has_assignable_package( int $session_id ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT package_id FROM ' . Migrations::session_packages_table() . ' WHERE session_id = %d ORDER BY package_id',
				$session_id
			)
		);

		if ( ! is_array( $ids ) || '' !== (string) $wpdb->last_error ) {
			return self::unreadable( 'the occurrence Bingo Packages could not be read', (string) $wpdb->last_error );
		}
		if ( [] === $ids ) {
			return false;
		}

		$gateway = new PackageGateway();

		foreach ( $ids as $package_id ) {
			$package = $gateway->getForSessionEligibility( (int) $package_id );
			if ( is_wp_error( $package ) ) {
				return self::unreadable( 'a Bingo Package could not be read', $package->get_error_code() );
			}
			if ( is_array( $package ) && true === PackageGateway::assert_assignable( $package ) ) {
				return true;
			}
		}

		return false;
	}

	private static function unreadable( string $detail, string $cause ): \WP_Error {
		return new \WP_Error(
			'sellability_unknown',
			__( 'Whether this Bingo Session can be sold could not be determined, so it is treated as not for sale.', 'bingocommerce-sessions' ),
			[
				'status' => 'sellability_unknown',
				'detail' => $detail,
				'cause'  => $cause,
			]
		);
	}
}
