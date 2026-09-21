<?php

namespace BingoCommerce\Sessions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The internal reconciliation Package/occurrence writer (ADR-043 §7.3, §7.7,
 * §7.9, §7.10).
 *
 * NOT A MANAGER ACTION. `SessionService::assignPackage()` and
 * `removePackage()` refuse a schedule-owned occurrence while
 * `generation_completed_at IS NULL` (§9) — the very state in which generation
 * must write Packages. This class is the ONLY path that writes Packages
 * while an occurrence is incomplete, and it is reached ONLY from
 * `OccurrenceReconciler`: no admin screen, POST handler, REST route or public
 * SessionService method constructs it or calls its methods. A test enumerates
 * every call site to prove this (§16 F46).
 *
 * EVERY STATEMENT CARRIES THE RUN FENCE, never a pre-read. `schedule_id`,
 * `sch.active_definition_token = $run_definition_token`,
 * `generation_completed_at IS NULL`, `available_for_sale = 0` and
 * `sale_locked_at IS NULL` are in every WHERE/JOIN below — which is what
 * makes this class safe under overlapping reconciliation workers: a worker
 * whose token has been superseded, or that is racing a worker that already
 * completed the row, affects zero rows rather than corrupting anything.
 *
 * `INSERT IGNORE` IS NEVER USED (§7.3). It would downgrade unrelated errors
 * to warnings, hiding exactly the failures §7.3's duplicate-key rule exists
 * to surface. Duplicate-key (1062) is caught and classified explicitly;
 * every other error stops the run.
 */
final class OccurrenceGenerationWriter {

	public const DUPLICATE_ALREADY_SATISFIED = 'already_satisfied';
	public const DUPLICATE_UNEXPECTED_STATE  = 'unexpected_state';

	private PackageGateway $packages;

	public function __construct( ?PackageGateway $packages = null ) {
		$this->packages = $packages ?? new PackageGateway();
	}

	/**
	 * §7.7 step 1. The guarded occurrence INSERT, with the binary template-name
	 * proof because this statement writes a `display_name`.
	 *
	 * `generation_completed_at` is the literal SQL NULL: the row is created
	 * INCOMPLETE, and only the completion statement (§7.3 step 4, via
	 * complete_occurrence() below) may ever set it.
	 *
	 * @return int|'duplicate'|\WP_Error session_id on success.
	 */
	public function create_occurrence(
		int $schedule_id,
		string $run_definition_token,
		string $run_template_name,
		string $session_date,
		string $display_name,
		string $start_time_local,
		string $timezone_name,
		string $starts_at_utc
	) {
		global $wpdb;

		$sessions  = Migrations::sessions_table();
		$schedules = Migrations::schedules_table();
		$templates = Migrations::portal_session_templates_table();
		$now       = current_time( 'mysql', true );

		$written = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Migrations.
				"INSERT INTO {$sessions}
				   (session_date, display_name, available_for_sale, status, created_at, updated_at, archived_at,
				    schedule_id, start_time_local, timezone_name, starts_at_utc,
				    sale_locked_at, generation_completed_at, availability_operation_token)
				 SELECT %s, %s, 0, 'active', %s, %s, NULL,
				        %d, %s, %s, %s,
				        NULL, NULL, NULL
				   FROM DUAL
				  WHERE EXISTS (
				          SELECT 1
				            FROM {$schedules} sch
				           WHERE sch.schedule_id             = %d
				             AND sch.status                  = 'active'
				             AND sch.active_definition_token = %s
				             AND EXISTS (
				                   SELECT 1 FROM {$templates} t
				                    WHERE t.portal_session_id   = sch.portal_session_id
				                      AND t.is_current          = 1
				                      AND t.portal_status       = 'active'
				                      AND CAST(t.name AS BINARY) = CAST(%s AS BINARY)
				                 )
				        )",
				$session_date,
				$display_name,
				$now,
				$now,
				$schedule_id,
				$start_time_local,
				$timezone_name,
				$starts_at_utc,
				$schedule_id,
				$run_definition_token,
				$run_template_name
			)
		);

		if ( false === $written ) {
			if ( DatabaseContention::is_duplicate_key( $wpdb ) ) {
				return 'duplicate';
			}
			if ( DatabaseContention::is_contention( $wpdb ) ) {
				return DatabaseContention::error();
			}

			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		if ( 0 === (int) $written ) {
			// The EXISTS guard did not match: fence lost or template ineligible.
			// The caller re-reads authoritatively to classify which.
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * §7.3 step 2. Remove Package rows outside the definition's exact set.
	 * `NOT IN` binds the definition's ids directly, so no correlated subquery
	 * on the target is needed.
	 *
	 * @param array<int,int> $definition_package_ids Non-empty, proved by the caller (§10).
	 * @return int|\WP_Error Affected rows.
	 */
	public function remove_extra_packages( int $session_id, int $schedule_id, string $run_definition_token, array $definition_package_ids ) {
		global $wpdb;

		$sessions  = Migrations::sessions_table();
		$schedules = Migrations::schedules_table();
		$templates = Migrations::portal_session_templates_table();
		$relations = Migrations::session_packages_table();

		$placeholders = implode( ', ', array_fill( 0, count( $definition_package_ids ), '%d' ) );

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Migrations; $placeholders is a fixed-count %d list.
			"DELETE p
			   FROM {$relations} p
			   JOIN {$sessions} s
			     ON  s.session_id              = p.session_id
			    AND  s.session_id              = %d
			    AND  s.schedule_id             = %d
			    AND  s.generation_completed_at IS NULL
			    AND  s.available_for_sale      = 0
			    AND  s.sale_locked_at          IS NULL
			   JOIN {$schedules} sch
			     ON  sch.schedule_id             = s.schedule_id
			    AND  sch.status                  = 'active'
			    AND  sch.active_definition_token = %s
			  WHERE  p.package_id NOT IN ( {$placeholders} )
			    AND  EXISTS (
			           SELECT 1 FROM {$templates} t
			            WHERE t.portal_session_id = sch.portal_session_id
			              AND t.is_current        = 1
			              AND t.portal_status     = 'active'
			         )",
			array_merge( [ $session_id, $schedule_id, $run_definition_token ], $definition_package_ids )
		);

		$affected = $wpdb->query( $sql );

		if ( false === $affected ) {
			if ( DatabaseContention::is_contention( $wpdb ) ) {
				return DatabaseContention::error();
			}

			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		return (int) $affected;
	}

	/**
	 * §7.3 step 3, with §7.3's explicit duplicate-key rule (also reused by
	 * §7.7 step 2's Package writes on a newly created occurrence — the same
	 * statement, the same rule).
	 *
	 * `INSERT IGNORE` is never used, per the docblock above.
	 *
	 * @return true|'duplicate'|\WP_Error True on a real insert.
	 */
	public function insert_missing_package( int $session_id, int $schedule_id, string $run_definition_token, int $package_id ) {
		global $wpdb;

		$sessions  = Migrations::sessions_table();
		$schedules = Migrations::schedules_table();
		$templates = Migrations::portal_session_templates_table();
		$relations = Migrations::session_packages_table();
		$now       = current_time( 'mysql', true );

		$written = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Migrations.
				"INSERT INTO {$relations} (session_id, package_id, created_at)
				 SELECT %d, %d, %s FROM DUAL
				  WHERE EXISTS (
				          SELECT 1
				            FROM {$sessions} s
				            JOIN {$schedules} sch
				              ON  sch.schedule_id             = s.schedule_id
				             AND  sch.status                  = 'active'
				             AND  sch.active_definition_token = %s
				           WHERE s.session_id              = %d
				             AND s.schedule_id             = %d
				             AND s.generation_completed_at IS NULL
				             AND s.available_for_sale      = 0
				             AND s.sale_locked_at          IS NULL
				             AND EXISTS (
				                   SELECT 1 FROM {$templates} t
				                    WHERE t.portal_session_id = sch.portal_session_id
				                      AND t.is_current        = 1
				                      AND t.portal_status     = 'active'
				                 )
				        )",
				$session_id,
				$package_id,
				$now,
				$run_definition_token,
				$session_id,
				$schedule_id
			)
		);

		if ( false === $written ) {
			if ( DatabaseContention::is_duplicate_key( $wpdb ) ) {
				return $this->classify_duplicate_package( $session_id, $schedule_id, $run_definition_token, $package_id );
			}
			if ( DatabaseContention::is_contention( $wpdb ) ) {
				return DatabaseContention::error();
			}

			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		return true;
	}

	/**
	 * §7.3's duplicate-key rule, steps 2-4:
	 *
	 *   1. (caller already caught 1062)
	 *   2. re-read authoritatively under the SAME schedule id and run token;
	 *   3. exact expected (session_id, package_id) exists AND the occurrence
	 *      is still incomplete, OFF and unlocked -> already satisfied, continue;
	 *   4. otherwise -> the run stops.
	 *
	 * @return 'already_satisfied'|\WP_Error
	 */
	private function classify_duplicate_package( int $session_id, int $schedule_id, string $run_definition_token, int $package_id ) {
		global $wpdb;

		$sessions  = Migrations::sessions_table();
		$schedules = Migrations::schedules_table();
		$relations = Migrations::session_packages_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Migrations.
				"SELECT s.session_id
				   FROM {$sessions} s
				   JOIN {$schedules} sch
				     ON  sch.schedule_id             = s.schedule_id
				    AND  sch.active_definition_token = %s
				   JOIN {$relations} p
				     ON  p.session_id = s.session_id
				    AND  p.package_id = %d
				  WHERE  s.session_id              = %d
				    AND  s.schedule_id             = %d
				    AND  s.generation_completed_at IS NULL
				    AND  s.available_for_sale      = 0
				    AND  s.sale_locked_at          IS NULL",
				$run_definition_token,
				$package_id,
				$session_id,
				$schedule_id
			),
			ARRAY_A
		);

		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		if ( is_array( $row ) ) {
			return self::DUPLICATE_ALREADY_SATISFIED;
		}

		return new \WP_Error(
			'duplicate_conflict',
			__( 'A concurrent process changed this occurrence\'s Bingo Packages unexpectedly.', 'bingocommerce-sessions' ),
			[ 'status' => 'duplicate_conflict', 'failure_category' => 'duplicate_conflict' ]
		);
	}

	/**
	 * §7.3 step 4. Exact-set completion: at least one Package (the non-empty
	 * proof), no required Package missing, no extra Package present, the
	 * template still eligible, the run fence still holding.
	 *
	 * The non-empty `EXISTS` is NOT redundant with the two `NOT EXISTS`
	 * branches — without it, a zero-Package definition satisfies both
	 * vacuously and this statement would complete an occurrence with nothing
	 * sellable on it.
	 *
	 * @return bool True on completion; false on zero affected rows (caller re-reads to classify).
	 */
	public function complete_occurrence( int $session_id, int $schedule_id, string $run_definition_token ) {
		global $wpdb;

		$sessions  = Migrations::sessions_table();
		$schedules = Migrations::schedules_table();
		$templates = Migrations::portal_session_templates_table();
		$def_pkgs  = Migrations::schedule_packages_table();
		$occ_pkgs  = Migrations::session_packages_table();
		$now       = current_time( 'mysql', true );

		$affected = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Migrations.
				"UPDATE {$sessions} s
				   JOIN {$schedules} sch
				     ON  sch.schedule_id             = s.schedule_id
				    AND  sch.status                  = 'active'
				    AND  sch.active_definition_token = %s
				    SET  s.generation_completed_at = %s,
				         s.updated_at              = %s
				 WHERE  s.session_id              = %d
				   AND  s.schedule_id             = %d
				   AND  s.generation_completed_at IS NULL
				   AND  s.available_for_sale      = 0
				   AND  s.sale_locked_at          IS NULL
				   AND  EXISTS (
				          SELECT 1 FROM {$templates} t
				           WHERE t.portal_session_id = sch.portal_session_id
				             AND t.is_current        = 1
				             AND t.portal_status     = 'active'
				        )
				   AND  EXISTS (
				          SELECT 1 FROM {$def_pkgs} spx
				           WHERE spx.schedule_id      = sch.schedule_id
				             AND spx.definition_token = sch.active_definition_token
				        )
				   AND NOT EXISTS (
				         SELECT 1 FROM {$def_pkgs} sp
				          WHERE sp.schedule_id      = sch.schedule_id
				            AND sp.definition_token = sch.active_definition_token
				            AND NOT EXISTS (
				                  SELECT 1 FROM {$occ_pkgs} op
				                   WHERE op.session_id = s.session_id
				                     AND op.package_id = sp.package_id
				                )
				       )
				   AND NOT EXISTS (
				         SELECT 1 FROM {$occ_pkgs} op2
				          WHERE op2.session_id = s.session_id
				            AND NOT EXISTS (
				                  SELECT 1 FROM {$def_pkgs} sp2
				                   WHERE sp2.schedule_id      = sch.schedule_id
				                     AND sp2.definition_token = sch.active_definition_token
				                     AND sp2.package_id       = op2.package_id
				                )
				       )",
				$run_definition_token,
				$now,
				$now,
				$session_id,
				$schedule_id
			)
		);

		if ( false === $affected ) {
			if ( DatabaseContention::is_contention( $wpdb ) ) {
				return DatabaseContention::error();
			}

			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		return 1 === (int) $affected;
	}

	/**
	 * Converge one occurrence's Packages to the exact definition set (§7.3
	 * steps 2-4, composed). Used by both repair (existing incomplete rows) and
	 * create (a row this same run just inserted).
	 *
	 * @param array<int,int> $definition_package_ids Non-empty, proved by the caller.
	 * @return true|\WP_Error
	 */
	public function converge_packages( int $session_id, int $schedule_id, string $run_definition_token, array $definition_package_ids ) {
		$removed = $this->remove_extra_packages( $session_id, $schedule_id, $run_definition_token, $definition_package_ids );
		if ( is_wp_error( $removed ) ) {
			return $removed;
		}

		foreach ( $definition_package_ids as $package_id ) {
			$assignable = $this->assert_still_assignable( $package_id );
			if ( is_wp_error( $assignable ) ) {
				return $assignable;
			}

			$inserted = $this->insert_missing_package( $session_id, $schedule_id, $run_definition_token, $package_id );
			if ( is_wp_error( $inserted ) ) {
				return $inserted;
			}
			// true (really inserted) or DUPLICATE_ALREADY_SATISFIED are both fine.
		}

		return true;
	}

	private function assert_still_assignable( int $package_id ) {
		$package = $this->packages->get( $package_id );
		if ( is_wp_error( $package ) ) {
			return $package;
		}
		if ( ! is_array( $package ) ) {
			return new \WP_Error(
				'eligibility_failed',
				__( 'A Bingo Package configured on this schedule no longer exists.', 'bingocommerce-sessions' ),
				[ 'status' => 'eligibility_failed', 'failure_category' => 'eligibility_failed', 'package_id' => $package_id ]
			);
		}

		$assignable = PackageGateway::assert_assignable( $package );
		if ( is_wp_error( $assignable ) ) {
			return new \WP_Error(
				'eligibility_failed',
				$assignable->get_error_message(),
				[ 'status' => 'eligibility_failed', 'failure_category' => 'eligibility_failed', 'package_id' => $package_id ]
			);
		}

		return true;
	}

	private function persistence_error( string $db_error ): \WP_Error {
		return new \WP_Error(
			'persistence_failed',
			__( 'The occurrence could not be written.', 'bingocommerce-sessions' ),
			[ 'status' => 'write_failed', 'failure_category' => 'write_failed', 'db_error' => $db_error ]
		);
	}
}
