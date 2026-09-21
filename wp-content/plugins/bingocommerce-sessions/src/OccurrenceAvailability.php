<?php

namespace BingoCommerce\Sessions;

use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Available-for-Sale transitions for SCHEDULE-OWNED occurrences (ADR-043 §6).
 *
 * AN INTERNAL COLLABORATOR, NOT A PUBLIC ENTRY POINT. `SessionService::
 * setAvailableForSale()` authorizes the request and routes here only when the
 * row is schedule-owned; a legacy occurrence (`schedule_id IS NULL`) never
 * reaches this class and keeps its v2 writer unchanged. The methods below refuse
 * a context that was not produced by that authorization, so the class cannot be
 * used to bypass it — and every statement additionally proves ownership in SQL.
 *
 * WHY A PRE-WRITE CHECK IS NOT ENOUGH. Schedule status, definition currentness
 * and template eligibility can all change between a check and the statement that
 * acts on it. So everything expressible in SQL is expressed IN the statement
 * that flips the switch, and that statement also CLAIMS OWNERSHIP of the
 * transition by stamping `availability_operation_token`, so that only this
 * request can later compensate it.
 *
 * WHAT THE TOKEN MEANS — exactly one thing (§6.5):
 *
 *   > An ON transition was committed and has not yet been settled by its owner.
 *
 * It is NOT proof that a request is still running. The owner may have been
 * killed between the §6.1 UPDATE and the §6.2 settlement by a fatal error, a
 * timeout, a worker kill or a deployment, leaving a row that is ON and locked
 * with a token nobody will ever settle. That state is ACCEPTED rather than
 * pretended away, and it is harmless: the occurrence really is ON, `sale_locked_at`
 * really is set, storefront eligibility never reads the token at all, ON is
 * already ON so no further ON applies, and the next OFF clears it.
 */
final class OccurrenceAvailability {

	private PackageGateway $packages;

	public function __construct( ?PackageGateway $packages = null ) {
		$this->packages = $packages ?? new PackageGateway();
	}

	/* ------------------------------------------------------------------ *
	 * ON
	 * ------------------------------------------------------------------ */

	/**
	 * Turn a generated occurrence ON (§6.1, §6.2).
	 *
	 * The sequence is fixed and its order is the design:
	 *
	 *   1. mint this request's own `$operation_token`;
	 *   2. PRE-prove at least one occurrence-level Package is assignable;
	 *   3. execute the guarded UPDATE;
	 *   4. zero rows -> authoritative re-read and classify. NOTHING was written,
	 *      no token is ours, and no compensation is attempted;
	 *   5. one row -> RE-prove assignability;
	 *   6a. re-proof succeeds -> settle, clearing only our own token;
	 *   6b. re-proof fails    -> compensate, fenced on our own token.
	 *
	 * @param array<string,mixed>    $row     The authoritative row, already read.
	 * @param array<string,int|bool> $context The caller's authorization context.
	 * @return SessionResult|\WP_Error
	 */
	public function turn_on( int $session_id, array $row, array $context ) {
		$authorized = $this->assert_authorized_context( $context );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$schedule_id = (int) ( $row['schedule_id'] ?? 0 );
		if ( $schedule_id < 1 ) {
			// A legacy row must never reach here. Refusing is safer than
			// silently applying schedule semantics to a row that has none.
			return $this->error( 'occurrence_not_schedule_owned', 'That Session is not owned by a commerce schedule.' );
		}

		$schedule = $this->read_schedule( $schedule_id );
		if ( is_wp_error( $schedule ) ) {
			return $schedule;
		}
		if ( null === $schedule ) {
			return $this->error( 'schedule_state_changed', 'The commerce schedule for this occurrence could not be found.' );
		}

		$expected_token = (string) $schedule['active_definition_token'];
		if ( '' === $expected_token ) {
			// An inert schedule has no readable definition, so there is no exact
			// token to prove and nothing it generates may be sold.
			return $this->error( 'schedule_state_changed', 'The commerce schedule has no committed definition.' );
		}

		// Step 1. This request's ownership claim, minted before any write.
		$operation_token = strtolower( wp_generate_uuid4() );

		// Step 2. The pre-proof. It is deliberately NOT relied on afterwards:
		// §3.2 records that Package assignability cannot be carried by SQL, so it
		// is proved here and re-proved after the write.
		$pre = $this->assert_any_package_assignable( $session_id );
		if ( is_wp_error( $pre ) ) {
			return $pre;
		}

		// Step 3.
		$affected = $this->guarded_on( $session_id, $schedule_id, $expected_token, $operation_token );
		if ( is_wp_error( $affected ) ) {
			return $affected;
		}

		// Step 4. Zero affected rows is NEVER treated as success.
		if ( 1 !== $affected ) {
			return $this->classify_on_failure( $session_id, $schedule_id, $expected_token );
		}

		// Step 5.
		$post = $this->assert_any_package_assignable( $session_id );

		if ( is_wp_error( $post ) ) {
			// Step 6b.
			return $this->compensate( $session_id, $schedule_id, $operation_token, $post );
		}

		// Step 6a.
		return $this->settle( $session_id, $operation_token );
	}

	/**
	 * §6.1. The one statement that flips the switch, proves every SQL-expressible
	 * precondition at statement time, and claims ownership.
	 *
	 * @return int|\WP_Error Affected rows.
	 */
	private function guarded_on( int $session_id, int $schedule_id, string $expected_token, string $operation_token ) {
		global $wpdb;

		$sessions  = Migrations::sessions_table();
		$schedules = Migrations::schedules_table();
		$templates = Migrations::portal_session_templates_table();
		$membership = SessionEligibility::current_definition_sql( 's' );

		$affected = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Migrations.
				"UPDATE {$sessions} s
				   JOIN {$schedules} sch
				     ON  sch.schedule_id             = s.schedule_id
				    AND  sch.schedule_id             = %d
				    AND  sch.status                  = 'active'
				    AND  sch.active_definition_token = %s
				    SET  s.available_for_sale            = 1,
				         s.sale_locked_at                = COALESCE(s.sale_locked_at, UTC_TIMESTAMP()),
				         s.availability_operation_token  = %s,
				         s.updated_at                    = %s
				  WHERE  s.session_id              = %d
				    AND  s.status                  = 'active'
				    AND  s.available_for_sale      = 0
				    AND  s.generation_completed_at IS NOT NULL
				    AND  {$membership}
				    AND  EXISTS (
				           SELECT 1
				             FROM {$templates} t
				            WHERE t.portal_session_id = sch.portal_session_id
				              AND t.is_current        = 1
				              AND t.portal_status     = 'active'
				         )",
				$schedule_id,
				$expected_token,
				$operation_token,
				current_time( 'mysql', true ),
				$session_id
			)
		);

		if ( false === $affected || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		return (int) $affected;
	}

	/**
	 * §6.2 step 6a. Settle by clearing ONLY this request's own token.
	 *
	 * Zero affected rows here is NOT an error. It means another request has
	 * already taken ownership — turned the occurrence OFF and ON again, say — so
	 * this request's token is no longer stamped on the row. The correct response
	 * is to re-read and report what is actually there, writing nothing further.
	 *
	 * @return SessionResult|\WP_Error
	 */
	private function settle( int $session_id, string $operation_token ) {
		global $wpdb;

		$sessions = Migrations::sessions_table();

		$affected = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Migrations.
				"UPDATE {$sessions}
				    SET availability_operation_token = NULL,
				        updated_at                   = %s
				  WHERE session_id                   = %d
				    AND availability_operation_token = %s",
				current_time( 'mysql', true ),
				$session_id,
				$operation_token
			)
		);

		if ( false === $affected || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		$row = $this->read_row( $session_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( null === $row ) {
			return $this->error( 'session_not_found', 'The Bingo Session was not found.' );
		}

		return new SessionResult( 'session_availability_changed', $row );
	}

	/**
	 * §6.2 step 6b. Undo THIS REQUEST'S OWN transition, and only that.
	 *
	 * The fence is `availability_operation_token = $operation_token` plus
	 * `available_for_sale = 1` and the owning `schedule_id`. If another request
	 * turned the occurrence OFF and a third turned it ON again, the token no
	 * longer matches and the statement affects ZERO rows.
	 *
	 * ZERO ROWS IS NOT SILENTLY ACCEPTED. An earlier revision of this method
	 * reported `compensated: false` on zero rows with NO further read at all —
	 * which meant a caller could not tell "another request already owns this
	 * occurrence" from "the occurrence no longer exists" from "the database could
	 * not be read", and had no observed state to act on. The ADR requires an
	 * AUTHORITATIVE RE-READ in exactly this case, so this request:
	 *
	 *   - propagates a FAILED re-read as `persistence_failed`, rather than
	 *     reporting a lost race that may not be what actually happened;
	 *   - returns `session_not_found` if the occurrence is genuinely gone;
	 *   - otherwise reports `package_became_unassignable` with
	 *     `compensated: false` and the OBSERVED `available_for_sale` and
	 *     `status`, sanitized to those two facts. **The other request's
	 *     `availability_operation_token` is never exposed** — it identifies a
	 *     request, not a fact about the occurrence, and this request has no
	 *     business publishing another request's identity.
	 *
	 * Either way, NO FURTHER WRITE is attempted after a zero-row compensation.
	 * An older request can never overwrite a newer one's outcome — not with a
	 * second guess, and not with a second UPDATE.
	 *
	 * `sale_locked_at` is NEVER cleared here, on either path. It is durable proof
	 * the occurrence has EVER been Available for Sale, and a compensated ON did
	 * genuinely make it available for the interval between the two statements.
	 *
	 * @return \WP_Error Always a failure: the caller asked for ON and it did not stand.
	 */
	private function compensate( int $session_id, int $schedule_id, string $operation_token, \WP_Error $reason ): \WP_Error {
		global $wpdb;

		$sessions = Migrations::sessions_table();

		$affected = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Migrations.
				"UPDATE {$sessions}
				    SET available_for_sale           = 0,
				        availability_operation_token = NULL,
				        updated_at                   = %s
				  WHERE session_id                   = %d
				    AND schedule_id                  = %d
				    AND available_for_sale           = 1
				    AND availability_operation_token = %s",
				current_time( 'mysql', true ),
				$session_id,
				$schedule_id,
				$operation_token
			)
		);

		if ( false === $affected || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		if ( 1 === (int) $affected ) {
			return new \WP_Error(
				'package_became_unassignable',
				__( 'A Bingo Package for this occurrence stopped being assignable, so it was not made available for sale.', 'bingocommerce-sessions' ),
				[
					'status'        => 'package_became_unassignable',
					'compensated'   => true,
					'package_error' => $reason->get_error_code(),
				]
			);
		}

		// Zero rows: another request already took ownership, or the occurrence is
		// gone. The authoritative re-read decides which, rather than this request
		// guessing.
		$observed = $this->read_row( $session_id );
		if ( is_wp_error( $observed ) ) {
			// A failed re-read is a failed re-read. It is never reported as a lost
			// race, which would be a specific, reassuring claim the evidence does
			// not support.
			return $observed;
		}
		if ( null === $observed ) {
			return $this->error( 'session_not_found', 'The Bingo Session was not found.' );
		}

		return new \WP_Error(
			'package_became_unassignable',
			__( 'A Bingo Package for this occurrence stopped being assignable, so it was not made available for sale. Another request already changed its availability.', 'bingocommerce-sessions' ),
			[
				'status'                      => 'package_became_unassignable',
				'compensated'                 => false,
				'package_error'               => $reason->get_error_code(),
				// Sanitized to two observed facts. NEVER the other request's
				// availability_operation_token, which identifies a request, not a
				// fact about the occurrence.
				'observed_available_for_sale' => $observed['available_for_sale'],
				'observed_status'             => $observed['status'],
			]
		);
	}
	/* ------------------------------------------------------------------ *
	 * OFF
	 * ------------------------------------------------------------------ */

	/**
	 * Turn a generated occurrence OFF (§6.3).
	 *
	 * NO template, schedule, definition or Package check. Removing something from
	 * sale must never be blocked by the state of a dependency — a template going
	 * draft or a Package becoming unassignable is one of the likeliest reasons a
	 * Manager needs to turn an occurrence OFF, and a guard here would refuse
	 * exactly then.
	 *
	 * It CLEARS the operation token, which is also how an abandoned unsettled ON
	 * is resolved (§6.5) — including one whose owning process died. It never
	 * clears `sale_locked_at`.
	 *
	 * @param array<string,mixed>    $row
	 * @param array<string,int|bool> $context
	 * @return SessionResult|\WP_Error
	 */
	public function turn_off( int $session_id, array $row, array $context ) {
		$authorized = $this->assert_authorized_context( $context );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		global $wpdb;

		$sessions = Migrations::sessions_table();

		$affected = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Migrations.
				"UPDATE {$sessions}
				    SET available_for_sale           = 0,
				        availability_operation_token = NULL,
				        updated_at                   = %s
				  WHERE session_id         = %d
				    AND status             = 'active'
				    AND available_for_sale = 1",
				current_time( 'mysql', true ),
				$session_id
			)
		);

		if ( false === $affected || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		$current = $this->read_row( $session_id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( null === $current ) {
			return $this->error( 'session_not_found', 'The Bingo Session was not found.' );
		}

		if ( 1 === (int) $affected ) {
			return new SessionResult( 'session_availability_changed', $current );
		}

		// Zero affected rows: re-read and classify rather than assume.
		if ( SessionService::STATUS_ACTIVE !== (string) $current['status'] ) {
			return $this->error( 'session_not_active', 'The Session is archived. Restore it before making changes.' );
		}

		return new SessionResult( 'session_availability_unchanged', $current );
	}

	/* ------------------------------------------------------------------ *
	 * Classification
	 * ------------------------------------------------------------------ */

	/**
	 * Why the guarded ON affected zero rows.
	 *
	 * An authoritative re-read decides, because the statement cannot say which of
	 * its conditions failed. A read that ITSELF fails is propagated rather than
	 * being reported as a state change — the latter is a specific claim that no
	 * evidence supports, and a reassuring one, since it invites a retry that will
	 * not help.
	 *
	 * @return SessionResult|\WP_Error
	 */
	private function classify_on_failure( int $session_id, int $schedule_id, string $expected_token ) {
		$row = $this->read_row( $session_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( null === $row ) {
			return $this->error( 'session_not_found', 'The Bingo Session was not found.' );
		}

		if ( SessionService::STATUS_ACTIVE !== (string) $row['status'] ) {
			return $this->error( 'session_not_active', 'The Session is archived. Restore it before making changes.' );
		}

		$schedule = $this->read_schedule( $schedule_id );
		if ( is_wp_error( $schedule ) ) {
			return $schedule;
		}
		if ( null === $schedule
			|| SessionService::STATUS_ACTIVE !== (string) $schedule['status']
			|| (string) $schedule['active_definition_token'] !== $expected_token ) {
			return $this->error(
				'schedule_state_changed',
				'The commerce schedule changed while this occurrence was being made available. Reload and try again.'
			);
		}

		$membership = SessionEligibility::belongs_to_current_definition( $session_id );
		if ( is_wp_error( $membership ) ) {
			return $membership;
		}
		if ( ! $membership ) {
			return $this->error( 'outside_current_definition', 'This Session date is no longer in the commerce schedule.' );
		}

		// Already ON means another request won the race. That is the state the
		// caller asked for, so it is reported as a verified no-op, not a failure.
		if ( ! empty( $row['available_for_sale'] ) ) {
			return new SessionResult( 'session_availability_unchanged', $row );
		}

		if ( null === $row['generation_completed_at'] ) {
			return $this->error(
				'occurrence_generation_incomplete',
				'This occurrence is still being generated and cannot be made available for sale yet.'
			);
		}

		$template = $this->read_template( (int) $schedule['portal_session_id'] );
		if ( is_wp_error( $template ) ) {
			return $template;
		}
		if ( null === $template || ! PortalTemplateGateway::is_commerce_eligible( $template ) ) {
			return $this->error(
				'template_not_commerce_eligible',
				'The Portal Session template for this occurrence is no longer active and current, so it cannot be sold.'
			);
		}

		return $this->error(
			'schedule_state_changed',
			'The occurrence could not be made available for sale because its state changed. Reload and try again.'
		);
	}

	/* ------------------------------------------------------------------ *
	 * Reads and guards
	 * ------------------------------------------------------------------ */

	/**
	 * At least ONE occurrence-level Package must be assignable (§6.2, §10).
	 *
	 * A read that cannot be completed is an error, never "no Packages, therefore
	 * fine": under ADR-038 that difference is a rejected transition versus a
	 * silent integrity decision.
	 *
	 * @return true|\WP_Error
	 */
	private function assert_any_package_assignable( int $session_id ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT package_id FROM ' . Migrations::session_packages_table() . ' WHERE session_id = %d ORDER BY package_id',
				$session_id
			)
		);

		if ( ! is_array( $ids ) || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		if ( [] === $ids ) {
			return $this->error(
				'occurrence_has_no_package',
				'This occurrence has no Bingo Package assigned, so it cannot be made available for sale.'
			);
		}

		$last = null;
		foreach ( $ids as $package_id ) {
			$package = $this->packages->get( (int) $package_id );
			if ( is_wp_error( $package ) ) {
				// Catalog unavailable or a failed read: fail closed rather than
				// treating an unreadable Package as merely unassignable.
				return $package;
			}
			if ( ! is_array( $package ) ) {
				$last = $this->error( 'package_not_found', 'A Bingo Package assigned to this occurrence no longer exists.' );
				continue;
			}

			$assignable = PackageGateway::assert_assignable( $package );
			if ( true === $assignable ) {
				return true;
			}
			$last = $assignable;
		}

		return $last instanceof \WP_Error
			? $last
			: $this->error( 'package_not_assignable', 'No Bingo Package assigned to this occurrence is currently assignable.' );
	}

	/** @return array<string,mixed>|null|\WP_Error */
	private function read_row( int $session_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT session_id, session_date, display_name, available_for_sale, status, schedule_id,
				        start_time_local, timezone_name, starts_at_utc, sale_locked_at,
				        generation_completed_at, availability_operation_token, created_at, updated_at, archived_at
				   FROM ' . Migrations::sessions_table() . ' WHERE session_id = %d',
				$session_id
			),
			ARRAY_A
		);

		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( ! is_array( $row ) ) {
			return null;
		}

		$row['session_id']         = (int) $row['session_id'];
		$row['available_for_sale'] = (bool) (int) $row['available_for_sale'];
		$row['schedule_id']        = null === $row['schedule_id'] ? null : (int) $row['schedule_id'];

		return $row;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	private function read_schedule( int $schedule_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT schedule_id, portal_session_id, status, active_definition_token
				   FROM ' . Migrations::schedules_table() . ' WHERE schedule_id = %d',
				$schedule_id
			),
			ARRAY_A
		);

		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( ! is_array( $row ) ) {
			return null;
		}

		$row['schedule_id']             = (int) $row['schedule_id'];
		$row['portal_session_id']       = (int) $row['portal_session_id'];
		$row['active_definition_token'] = null === $row['active_definition_token'] ? '' : (string) $row['active_definition_token'];

		return $row;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	private function read_template( int $portal_session_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT portal_session_id, name, portal_status, is_current
				   FROM ' . Migrations::portal_session_templates_table() . ' WHERE portal_session_id = %d',
				$portal_session_id
			),
			ARRAY_A
		);

		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( ! is_array( $row ) ) {
			return null;
		}

		return [
			'portal_session_id' => (int) $row['portal_session_id'],
			'name'              => (string) $row['name'],
			'portal_status'     => (string) $row['portal_status'],
			'is_current'        => 1 === (int) $row['is_current'],
		];
	}

	/**
	 * Independently RE-PROVE authorization. Never trust the shape of `$context`.
	 *
	 * An earlier revision of this method checked only that `blog_id` and
	 * `actor_user_id` were positive integers — which a caller could satisfy with
	 * a FABRICATED context such as `['blog_id' => 1, 'actor_user_id' => 1]`,
	 * without ever having been through `SessionService::authorize()` at all.
	 * That is not defence in depth; it is a check that passes for anyone who
	 * read this file. Positivity proves nothing about WHO the caller is.
	 *
	 * This method now re-derives every fact `SessionService::authorize()`
	 * derives, from the SAME environment functions, independently of whatever
	 * `$context` claims:
	 *
	 *   - the request is authenticated;
	 *   - `HallContext::is_hall()` — this is a hall site;
	 *   - `bc_manage_sessions` or Super Admin;
	 *   - `Migrations::is_current()` — the Sessions schema is ready;
	 *   - and, only once every fact above is independently established,
	 *     `$context['blog_id']` and `$context['actor_user_id']` are compared
	 *     against `get_current_blog_id()` and `get_current_user_id()` — NOT
	 *     tested for positivity. A context naming a different hall or a
	 *     different user than the one actually making the request is refused,
	 *     which is what stops a caller from constructing a context for a hall or
	 *     a user it does not have.
	 *
	 * Nothing here is derived from `$context` alone. If every one of these
	 * checks passed, the caller could have obtained the same answer by asking
	 * `SessionService::authorize()` itself — which is the point: this is not a
	 * shortcut around authorization, it is authorization, run again.
	 *
	 * @param array<string,mixed> $context
	 * @return true|\WP_Error
	 */
	private function assert_authorized_context( array $context ) {
		if ( ! is_user_logged_in() ) {
			return $this->error( 'authentication_required', 'You must be signed in to manage Bingo Sessions.' );
		}
		if ( ! HallContext::is_hall() ) {
			return $this->error( 'invalid_hall_context', 'Bingo Sessions may be managed only from a hall site.' );
		}

		$actor_id = get_current_user_id();
		if ( ! is_super_admin( $actor_id ) && ! current_user_can( 'bc_manage_sessions' ) ) {
			return $this->error(
				'session_management_denied',
				'You are not authorized to manage Bingo Sessions for this hall.'
			);
		}
		if ( ! Migrations::is_current() ) {
			return $this->error( 'sessions_schema_unavailable', 'The Session schema is not ready for this hall.' );
		}

		if ( (int) ( $context['blog_id'] ?? 0 ) !== get_current_blog_id() ) {
			return $this->error(
				'session_management_denied',
				'The authorization context does not match the current hall.'
			);
		}
		if ( (int) ( $context['actor_user_id'] ?? 0 ) !== $actor_id ) {
			return $this->error(
				'session_management_denied',
				'The authorization context does not match the current user.'
			);
		}

		return true;
	}

	private function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-sessions' ), [ 'status' => $code ] );
	}

	private function persistence_error( string $db_error ): \WP_Error {
		return new \WP_Error(
			'persistence_failed',
			__( 'The Bingo Session change could not be persisted.', 'bingocommerce-sessions' ),
			[ 'db_error' => $db_error ]
		);
	}
}
