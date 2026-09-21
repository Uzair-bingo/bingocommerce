<?php

namespace BingoCommerce\Sessions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reconciliation: converges a schedule's dated occurrences to its active
 * definition (ADR-043 §7, §8.4-§8.6).
 *
 * THE RUN FENCE (§7.1). Preflight captures `$run_schedule_id`,
 * `$run_definition_token` and `$run_template_name` ONCE, and every mutation
 * this run issues proves the token again IN ITS OWN WHERE/JOIN — never on the
 * strength of the preflight read. Between every phase the fence is RE-PROVED
 * by an authoritative read; if it no longer holds, the run stops immediately
 * with `complete = false`, `status = schedule_state_changed`, and every
 * remaining date is reported `not_attempted`. Writes already committed by
 * earlier phases are NOT rolled back — there is no enclosing transaction
 * (§3.1) — and are counted accurately, because each was individually fenced
 * on the token that was active when it ran.
 *
 * PHASE ORDER IS FIXED (§7.6): 0 preflight, 1 withdraw, 2 eligibility gate,
 * 3 repair, 4 create, 5 retime, 6 restore, 7 final verification. Withdrawal
 * runs BEFORE the eligibility gate and is the only phase exempt from
 * template/Package eligibility, so removing commerce can never be blocked by
 * the state of a dependency.
 */
final class OccurrenceReconciler {

	private OccurrenceGenerationWriter $writer;

	private PackageGateway $packages;

	public function __construct( ?OccurrenceGenerationWriter $writer = null, ?PackageGateway $packages = null ) {
		$this->writer   = $writer ?? new OccurrenceGenerationWriter();
		$this->packages = $packages ?? new PackageGateway();
	}

	/** @return SessionScheduleResult|\WP_Error */
	public function run( int $schedule_id ) {
		$counts = array_fill_keys( SessionScheduleResult::COUNTS, 0 );

		/* ============================== Phase 0 — preflight ============================== */
		$plan = $this->preflight( $schedule_id );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( [] !== $plan['blockers'] ) {
			return new SessionScheduleResult(
				SessionScheduleResult::STATUS_FAILED,
				[],
				'preflight_conflict',
				$counts,
				[],
				$plan['blockers']
			);
		}

		$run_schedule_id       = $plan['run_schedule_id'];
		$run_definition_token  = $plan['run_definition_token'];
		$run_template_name     = $plan['run_template_name'];

		/* ============================== Phase 1 — withdraw ============================== */
		foreach ( $plan['withdraw_ids'] as $session_id ) {
			$outcome = $this->withdraw_one( $session_id, $run_schedule_id, $run_definition_token, $plan['today'] );
			if ( is_wp_error( $outcome ) ) {
				return $this->stop( $outcome, $counts );
			}
			if ( true === $outcome ) {
				++$counts['withdrawn'];
			} else {
				// Zero rows: re-read and classify (§7.2). A FAILED re-read is
				// returned as the failure it is, never as a classification.
				$classified = $this->classify_withdraw_no_op( $session_id, $run_schedule_id );
				if ( is_wp_error( $classified ) ) {
					return $this->stop( $classified, $counts );
				}
				++$counts[ $classified ];
			}
		}

		$fence = $this->reprove_fence( $run_schedule_id, $run_definition_token );
		if ( is_wp_error( $fence ) ) {
			return $this->fence_lost( $counts );
		}

		/* ============================== Phase 2 — eligibility gate ============================== */
		$eligible = $this->eligibility_gate( $run_schedule_id, $run_definition_token );
		if ( is_wp_error( $eligible ) ) {
			return $this->stop( $eligible, $counts );
		}
		$definition_package_ids = $eligible;

		$fence = $this->reprove_fence( $run_schedule_id, $run_definition_token );
		if ( is_wp_error( $fence ) ) {
			return $this->fence_lost( $counts );
		}

		/* ============================== Phase 3 — repair ============================== */
		foreach ( $plan['repair_ids'] as $session_id ) {
			$outcome = $this->repair_one( $session_id, $run_schedule_id, $run_definition_token, $definition_package_ids );
			if ( is_wp_error( $outcome ) ) {
				return $this->stop( $outcome, $this->count_from( $outcome, $counts ) );
			}
			if ( true === $outcome ) {
				++$counts['repaired'];
				++$counts['completed'];
				continue;
			}
			// 'completed_elsewhere': the occurrence IS complete with the exact
			// definition set, but this run did not write it — so it is reported
			// as `existing`, never as this run's `repaired`/`completed`.
			++$counts['existing'];
		}

		$fence = $this->reprove_fence( $run_schedule_id, $run_definition_token );
		if ( is_wp_error( $fence ) ) {
			return $this->fence_lost( $counts );
		}

		/* ============================== Phase 4 — create ============================== */
		foreach ( $plan['create_dates'] as $date_plan ) {
			$outcome = $this->create_one( $run_schedule_id, $run_definition_token, $run_template_name, $date_plan, $definition_package_ids );
			if ( is_wp_error( $outcome ) ) {
				return $this->stop( $outcome, $this->count_from( $outcome, $counts ) );
			}
			if ( true === $outcome ) {
				++$counts['created'];
				++$counts['completed'];
				continue;
			}
			if ( 'created_completed_elsewhere' === $outcome ) {
				++$counts['created'];
				continue;
			}
			/*
			 * 'duplicate': §7.4 — re-read authoritatively, report `conflicted`
			 * with the owning session_id, and END THE RUN. A duplicate after
			 * preflight is a real collision the Manager has to resolve, not a
			 * count to carry into a `status = ok` result.
			 */
			$conflict = $this->classify_create_duplicate( $run_schedule_id, $date_plan['session_date'], $date_plan['display_name'] );

			return $this->stop( $conflict, $this->count_from( $conflict, $counts ) );
		}

		$fence = $this->reprove_fence( $run_schedule_id, $run_definition_token );
		if ( is_wp_error( $fence ) ) {
			return $this->fence_lost( $counts );
		}

		/* ============================== Phase 5 — retime ============================== */
		$retime_candidates = $this->find_retime_candidates( $run_schedule_id, $plan['today'], $plan['future_dates_by_key'] );
		if ( is_wp_error( $retime_candidates ) ) {
			return $this->stop( $retime_candidates, $counts );
		}
		foreach ( $retime_candidates as $candidate ) {
			$outcome = $this->retime_one( $candidate, $run_schedule_id, $run_definition_token, $run_template_name );
			if ( is_wp_error( $outcome ) ) {
				return $this->stop( $outcome, $counts );
			}
			if ( true === $outcome ) {
				++$counts['retimed'];
			} else {
				$classified = $this->classify_retime_no_op( $candidate['session_id'], $run_schedule_id, $run_template_name );
				if ( is_wp_error( $classified ) ) {
					return $this->stop( $classified, $counts );
				}
				++$counts[ $classified ];
			}
		}

		$fence = $this->reprove_fence( $run_schedule_id, $run_definition_token );
		if ( is_wp_error( $fence ) ) {
			return $this->fence_lost( $counts );
		}

		/* ============================== Phase 6 — restore ============================== */
		// Re-queried NOW, not from the preflight plan: phase 3 can have
		// completed an archived incomplete row in T, making it newly eligible.
		// Scoped to T, so an archived row the Manager REMOVED from the
		// definition stays archived (§7.6 row 12) instead of being put back on
		// sale by the next run.
		$restore_candidates = $this->find_restore_candidates( $run_schedule_id, $plan['today'], $plan['future_dates_by_key'] );
		if ( is_wp_error( $restore_candidates ) ) {
			return $this->stop( $restore_candidates, $counts );
		}
		foreach ( $restore_candidates as $candidate ) {
			$outcome = $this->restore_one( $candidate, $run_schedule_id, $run_definition_token, $run_template_name, $plan['today'] );
			if ( is_wp_error( $outcome ) ) {
				return $this->stop( $outcome, $counts );
			}
			if ( true === $outcome ) {
				++$counts['restored'];
				continue;
			}
			/*
			 * §7.5: "Zero affected rows on either statement is NEVER success.
			 * Authoritative re-read classifies." Counting `restore_lost_race`
			 * straight off the zero-row return — as an earlier version did —
			 * asserts the benign cause without ever establishing it, and lets
			 * the run reach `status = ok` over an in-definition occurrence that
			 * is still archived and therefore not on sale.
			 */
			$classified = $this->classify_restore_no_op( (int) $candidate['session_id'], $run_schedule_id, $run_definition_token );
			if ( is_wp_error( $classified ) ) {
				return $this->stop( $classified, $counts );
			}
			++$counts[ $classified ];
		}

		$counts['existing']                       += count( $plan['existing_ids'] );
		$counts['retained_outside_pattern']        += count( $plan['retained_outside_pattern_ids'] );
		$counts['retained_historical']             += count( $plan['retained_historical_ids'] );
		$counts['retained_historical_incomplete']  += count( $plan['retained_historical_incomplete_ids'] );

		/* ============================== Phase 7 — final verification ============================== */
		$final = $this->final_verification( $run_schedule_id, $run_definition_token, $run_template_name );
		if ( is_wp_error( $final ) ) {
			return $this->stop( $final, $counts );
		}

		return new SessionScheduleResult( SessionScheduleResult::STATUS_OK, [ 'schedule_id' => $run_schedule_id ], null, $counts );
	}

	/* ------------------------------------------------------------------ *
	 * Phase 0 — preflight (§8.4). READ-ONLY. Never authorizes a write.
	 * ------------------------------------------------------------------ */

	/** @return array<string,mixed>|\WP_Error */
	private function preflight( int $schedule_id ) {
		global $wpdb;

		$schedule = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT schedule_id, portal_session_id, timezone_name, status, mode,
				        weekday_mask, recurrence_start, recurrence_end, active_definition_token
				   FROM ' . Migrations::schedules_table() . ' WHERE schedule_id = %d',
				$schedule_id
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( ! is_array( $schedule ) || self::STATUS_ACTIVE !== (string) $schedule['status'] ) {
			return $this->schedule_state_changed_error();
		}

		$run_definition_token = null === $schedule['active_definition_token'] ? '' : (string) $schedule['active_definition_token'];
		if ( '' === $run_definition_token ) {
			// Inert: no committed definition. Nothing can be expanded or
			// validated against an empty definition, so this is refused the
			// same way §10's gate refuses a zero-Package definition — cleanly,
			// before any write, rather than guessed at.
			return new \WP_Error(
				'eligibility_failed',
				__( 'This schedule has no committed definition, so there is nothing to generate.', 'bingocommerce-sessions' ),
				[ 'status' => 'eligibility_failed', 'failure_category' => 'eligibility_failed' ]
			);
		}

		$zone = ScheduleTimezone::validate_zone( (string) $schedule['timezone_name'] );
		if ( is_wp_error( $zone ) ) {
			return $zone;
		}
		$today = ScheduleTimezone::local_today( $zone );
		if ( null === $today ) {
			return $this->error( 'hall_timezone_not_named', 'The scheduling time zone could not be evaluated.' );
		}

		$template = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT portal_session_id, name, portal_status, is_current
				   FROM ' . Migrations::portal_session_templates_table() . ' WHERE portal_session_id = %d',
				(int) $schedule['portal_session_id']
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( ! is_array( $template ) ) {
			return $this->schedule_state_changed_error();
		}
		$run_template_name = (string) $template['name'];

		// The exact dates T (strictly future) and the schedule's exact
		// start_time_local, for every derived name/instant below.
		$start_time_local = $this->schedule_start_time_local( $schedule_id );
		if ( is_wp_error( $start_time_local ) ) {
			return $start_time_local;
		}

		$expansion = $this->expand_definition( $schedule_id, $run_definition_token, (string) $schedule['mode'], (int) $schedule['weekday_mask'], (string) ( $schedule['recurrence_start'] ?? '' ), (string) ( $schedule['recurrence_end'] ?? '' ) );
		if ( is_wp_error( $expansion ) ) {
			return $expansion;
		}
		$future_dates = ScheduleExpansion::strictly_future( $expansion, $today );

		$blockers   = [];
		$date_plans = [];
		foreach ( $future_dates as $date ) {
			$instant = ScheduleTimezone::exact_instant( $date, $start_time_local, $zone );
			if ( is_wp_error( $instant ) ) {
				$blockers[] = [ 'kind' => $instant->get_error_code(), 'session_date' => $date ];
				continue;
			}
			$date_plans[ $date ] = [
				'session_date'     => $date,
				'display_name'     => ScheduleExpansion::occurrence_display_name( $run_template_name, $start_time_local ),
				'start_time_local' => $start_time_local,
				'timezone_name'    => $zone,
				'starts_at_utc'    => $instant,
			];
		}

		// Every occurrence this schedule already owns.
		$existing = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT session_id, session_date, display_name, status, available_for_sale,
				        sale_locked_at, generation_completed_at, start_time_local
				   FROM ' . Migrations::sessions_table() . ' WHERE schedule_id = %d',
				$schedule_id
			),
			ARRAY_A
		);
		if ( ! is_array( $existing ) || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		$by_date = [];
		foreach ( $existing as $row ) {
			$by_date[ (string) $row['session_date'] ] = $row;
		}

		$plan = [
			'run_schedule_id'                    => $schedule_id,
			'run_definition_token'               => $run_definition_token,
			'run_template_name'                  => $run_template_name,
			'today'                               => $today,
			'blockers'                            => [],
			'withdraw_ids'                        => [],
			'repair_ids'                          => [],
			'create_dates'                        => [],
			'existing_ids'                        => [],
			'retained_outside_pattern_ids'        => [],
			'retained_historical_ids'             => [],
			'retained_historical_incomplete_ids'  => [],
			'future_dates_by_key'                 => array_keys( $date_plans ),
		];

		// Rows 1, 2, 13 apply to EVERY existing row regardless of T, and row 2
		// (any date) is a blocker checked before anything else.
		foreach ( $existing as $row ) {
			$incomplete = null === $row['generation_completed_at'];
			$locked_or_on = null !== $row['sale_locked_at'] || (bool) (int) $row['available_for_sale'];

			if ( $incomplete && $locked_or_on ) {
				$blockers[] = [ 'kind' => 'incomplete_integrity_conflict', 'session_id' => (int) $row['session_id'] ];
				continue;
			}

			$is_future = ScheduleExpansion::is_tomorrow_or_later( (string) $row['session_date'], $today )
				|| (string) $row['session_date'] === $today; // handled explicitly below; "future" excludes today.
			$is_strictly_future = ScheduleExpansion::is_tomorrow_or_later( (string) $row['session_date'], $today );

			if ( ! $is_strictly_future ) {
				if ( $incomplete ) {
					$plan['retained_historical_incomplete_ids'][] = (int) $row['session_id'];
				} else {
					$plan['retained_historical_ids'][] = (int) $row['session_id'];
				}
				continue;
			}

			$in_t = isset( $date_plans[ (string) $row['session_date'] ] );
			$active_status = self::STATUS_ACTIVE === (string) $row['status'];
			$archived_status = 'archived' === (string) $row['status'];
			$off_unlocked = ! (bool) (int) $row['available_for_sale'] && null === $row['sale_locked_at'];

			if ( $in_t ) {
				if ( $incomplete && $off_unlocked && ( $active_status || $archived_status ) ) {
					$plan['repair_ids'][] = (int) $row['session_id'];
					unset( $date_plans[ (string) $row['session_date'] ] ); // occurrence exists; not a create candidate.
					continue;
				}
				if ( ! $incomplete && $active_status ) {
					$time_matches = (string) $row['start_time_local'] === $start_time_local;
					if ( $time_matches ) {
						// row 6 — no write, informational.
						$plan['existing_ids'][] = (int) $row['session_id'];
					} elseif ( $off_unlocked ) {
						// row 7 — retimed at phase 5, which independently
						// re-queries and counts it. NOT added to existing_ids:
						// that was a genuine double-count bug, caught by
						// smoke-testing an edit that both retimes one
						// occurrence and creates another in the same run.
					} else {
						$blockers[] = [ 'kind' => 'protected_time_conflict', 'session_id' => (int) $row['session_id'] ];
					}
					unset( $date_plans[ (string) $row['session_date'] ] );
					continue;
				}
				if ( ! $incomplete && $archived_status ) {
					// row 9/10 — restored at phase 6, re-queried there.
					unset( $date_plans[ (string) $row['session_date'] ] );
					continue;
				}
				// Any other in-T state (e.g. ON/locked, already excluded by row2 above) falls through untouched.
				unset( $date_plans[ (string) $row['session_date'] ] );
				continue;
			}

			// Not in T.
			if ( $active_status && $off_unlocked ) {
				$plan['withdraw_ids'][] = (int) $row['session_id'];
			} else {
				$plan['retained_outside_pattern_ids'][] = (int) $row['session_id'];
			}
		}

		// Remaining date_plans (still keyed by date, not consumed above) are row 5: create.
		$plan['create_dates'] = array_values( $date_plans );

		// Foreign (session_date, display_name) clash — any OTHER session_id in
		// this hall already using a candidate (date, name) pair, read through
		// the column collation (§4.2), not binary.
		if ( [] !== $plan['create_dates'] ) {
			$clash = $this->find_foreign_clash( $schedule_id, $plan['create_dates'] );
			if ( is_wp_error( $clash ) ) {
				return $clash;
			}
			foreach ( $clash as $c ) {
				$blockers[] = $c;
			}
		}

		$plan['blockers'] = $blockers;

		return $plan;
	}

	/** @return string|\WP_Error */
	private function schedule_start_time_local( int $schedule_id ) {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare( 'SELECT start_time_local FROM ' . Migrations::schedules_table() . ' WHERE schedule_id = %d', $schedule_id )
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		return null === $value ? '' : (string) $value;
	}

	/** @return array<int,string>|\WP_Error dates, ascending */
	private function expand_definition( int $schedule_id, string $token, string $mode, int $weekday_mask, string $recurrence_start, string $recurrence_end ) {
		global $wpdb;

		if ( ScheduleExpansion::MODE_WEEKLY === $mode ) {
			return ScheduleExpansion::expand_weekly( $recurrence_start, $recurrence_end, $weekday_mask );
		}

		$dates = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT schedule_date FROM ' . Migrations::schedule_dates_table() . ' WHERE schedule_id = %d AND definition_token = %s ORDER BY schedule_date',
				$schedule_id,
				$token
			)
		);
		if ( ! is_array( $dates ) || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		return array_map( 'strval', $dates );
	}

	/**
	 * @param array<int,array{session_date:string,display_name:string}> $create_dates
	 * @return array<int,array{kind:string,session_id:int,session_date:string,foreign_template_name:string}>|\WP_Error
	 */
	private function find_foreign_clash( int $schedule_id, array $create_dates ) {
		global $wpdb;

		if ( [] === $create_dates ) {
			return [];
		}

		/*
		 * THE PAIR MATCH HAPPENS IN THE DATABASE, UNDER THE COLUMN COLLATION
		 * (§4.2) — deliberately, and this is the whole point of the phase.
		 *
		 * `display_name` collates `utf8mb4_unicode_520_ci` (measured, not
		 * inferred), under which `Evening Bingo`, `evening bingo` and
		 * `Évening Bingo` are all EQUAL. Two names a customer could not tell
		 * apart on the same date are exactly what this blocker exists to catch.
		 *
		 * An earlier version asked the database for the candidate dates and
		 * names, then re-filtered the returned rows in PHP with an array-key
		 * lookup — a BYTE-EXACT comparison, which silently discarded every
		 * case-only and accent-only match the collation had just found and left
		 * collision detection behaving like the binary comparison that §7.8
		 * reserves for RENAME detection. Matching the pair in SQL is what keeps
		 * the two behaviours distinct, as §4.2 requires.
		 *
		 * The pairs are matched together — (date AND name) OR (date AND name) —
		 * rather than as two independent IN lists, so a candidate on date A and
		 * a different candidate's name on date B cannot combine into a clash
		 * that neither candidate actually has.
		 */
		$clauses = [];
		$values  = [];
		foreach ( $create_dates as $candidate ) {
			$clauses[] = '( s.session_date = %s AND s.display_name = %s )';
			$values[]  = (string) $candidate['session_date'];
			$values[]  = (string) $candidate['display_name'];
		}
		$pairs = implode( ' OR ', $clauses );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed-count %s pair list.
				'SELECT s.session_id, s.schedule_id, s.session_date, s.display_name
				   FROM ' . Migrations::sessions_table() . " s
				  WHERE ( {$pairs} )
				    AND ( s.schedule_id IS NULL OR s.schedule_id <> %d )
				  ORDER BY s.session_id",
				array_merge( $values, [ $schedule_id ] )
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		$blockers = [];
		foreach ( $rows as $row ) {
			$blockers[] = [
				'kind'                  => 'foreign_name_date_clash',
				'session_id'            => (int) $row['session_id'],
				'session_date'          => (string) $row['session_date'],
				// BOTH names are reported, because under the collation they can
				// differ byte for byte while colliding — and the Manager
				// resolving it needs to see the one already stored, not only
				// the one this run wanted to write (§4.2).
				'display_name'          => (string) $row['display_name'],
				'foreign_schedule_id'   => null === $row['schedule_id'] ? 0 : (int) $row['schedule_id'],
			];
		}

		return $blockers;
	}

	/* ------------------------------------------------------------------ *
	 * The run fence — re-proved between every phase (§7.1)
	 * ------------------------------------------------------------------ */

	/** @return true|\WP_Error */
	private function reprove_fence( int $schedule_id, string $run_definition_token ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status, active_definition_token FROM " . Migrations::schedules_table() . ' WHERE schedule_id = %d',
				$schedule_id
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( ! is_array( $row )
			|| self::STATUS_ACTIVE !== (string) $row['status']
			|| (string) $row['active_definition_token'] !== $run_definition_token
		) {
			return $this->schedule_state_changed_error();
		}

		return true;
	}

	/* ------------------------------------------------------------------ *
	 * Phase 1 — withdraw (§7.2). The single guarded multi-table DELETE.
	 * ------------------------------------------------------------------ */

	/** @return bool|\WP_Error true = withdrawn; false = zero rows (caller classifies) */
	private function withdraw_one( int $session_id, int $schedule_id, string $run_definition_token, string $today ) {
		global $wpdb;

		$sessions  = Migrations::sessions_table();
		$schedules = Migrations::schedules_table();
		$packages  = Migrations::session_packages_table();

		/*
		 * `s.session_date > %s` is §7.2's strictly-future guard, and it is in the
		 * STATEMENT deliberately rather than only in preflight's classification.
		 * Rows 1 and 13 of §7.6 are the complete statement of past immutability,
		 * and the only hard-delete path in the whole design is this statement —
		 * so the guarantee that no past occurrence is ever deleted has to hold
		 * at statement time, under whatever the row looks like NOW, not on the
		 * strength of a read taken before the previous phase ran.
		 */
		$affected = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Migrations.
				"DELETE s, p
				   FROM {$sessions} s
				   JOIN {$schedules} sch
				     ON  sch.schedule_id             = s.schedule_id
				    AND  sch.schedule_id             = %d
				    AND  sch.status                  = 'active'
				    AND  sch.active_definition_token = %s
				   LEFT JOIN {$packages} p
				     ON  p.session_id = s.session_id
				  WHERE  s.session_id         = %d
				    AND  s.status             = 'active'
				    AND  s.available_for_sale = 0
				    AND  s.sale_locked_at     IS NULL
				    AND  s.session_date       > %s",
				$schedule_id,
				$run_definition_token,
				$session_id,
				$today
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

		return (int) $affected > 0;
	}

	/**
	 * @return string|\WP_Error The count name, or the read failure that stops the run.
	 *
	 * A FAILED READ IS NOT AN ANSWER. `get_row()` returns null both for "no such
	 * row" and for a statement that never ran, so without the `last_error` check
	 * a database failure here would be silently reported as `withdraw_lost_race`
	 * — a benign, informational classification — and the run would go on to
	 * finish `status = ok` having never established what the row actually is.
	 */
	private function classify_withdraw_no_op( int $session_id, int $schedule_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT status FROM ' . Migrations::sessions_table() . ' WHERE session_id = %d AND schedule_id = %d',
				$session_id,
				$schedule_id
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		return is_array( $row ) ? 'retained_outside_pattern' : 'withdraw_lost_race';
	}

	/* ------------------------------------------------------------------ *
	 * Phase 2 — eligibility gate (§10)
	 * ------------------------------------------------------------------ */

	/** @return array<int,int>|\WP_Error definition Package ids, non-empty */
	private function eligibility_gate( int $schedule_id, string $run_definition_token ) {
		global $wpdb;

		$templates = Migrations::portal_session_templates_table();
		$schedules = Migrations::schedules_table();

		$eligible_template = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $schedules . ' sch JOIN ' . $templates . ' t
				  ON t.portal_session_id = sch.portal_session_id AND t.is_current = 1 AND t.portal_status = \'active\'
				 WHERE sch.schedule_id = %d AND sch.status = \'active\' AND sch.active_definition_token = %s',
				$schedule_id,
				$run_definition_token
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( 0 === (int) $eligible_template ) {
			return new \WP_Error(
				'eligibility_failed',
				__( 'The Portal Session template is no longer active and current, so this schedule cannot generate occurrences.', 'bingocommerce-sessions' ),
				[ 'status' => 'eligibility_failed', 'failure_category' => 'eligibility_failed' ]
			);
		}

		$package_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT package_id FROM ' . Migrations::schedule_packages_table() . ' WHERE schedule_id = %d AND definition_token = %s ORDER BY package_id',
				$schedule_id,
				$run_definition_token
			)
		);
		if ( ! is_array( $package_ids ) || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( [] === $package_ids ) {
			return new \WP_Error(
				'eligibility_failed',
				__( 'This schedule\'s active definition has no Bingo Packages, so occurrences cannot be generated or sold.', 'bingocommerce-sessions' ),
				[ 'status' => 'eligibility_failed', 'failure_category' => 'eligibility_failed' ]
			);
		}

		$package_ids = array_map( 'intval', $package_ids );
		foreach ( $package_ids as $package_id ) {
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
		}

		return $package_ids;
	}

	/* ------------------------------------------------------------------ *
	 * Phase 3 — repair (§7.3)
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<int,int> $definition_package_ids
	 * @return true|'completed_elsewhere'|\WP_Error
	 */
	private function repair_one( int $session_id, int $schedule_id, string $run_definition_token, array $definition_package_ids ) {
		$converged = $this->writer->converge_packages( $session_id, $schedule_id, $run_definition_token, $definition_package_ids );
		if ( is_wp_error( $converged ) ) {
			return $converged;
		}

		$completed = $this->writer->complete_occurrence( $session_id, $schedule_id, $run_definition_token );
		if ( is_wp_error( $completed ) ) {
			return $completed;
		}
		if ( true === $completed ) {
			return true;
		}

		return $this->classify_completion_no_op( $session_id, $schedule_id, $run_definition_token, $definition_package_ids );
	}

	/**
	 * ZERO AFFECTED ROWS ON THE COMPLETION STATEMENT IS NEVER SUCCESS.
	 *
	 * §7.3 step 4's completion is a single guarded UPDATE that proves, in SQL,
	 * that the occurrence is still incomplete/OFF/unlocked, that the fence and
	 * the template still hold, and that the Package set is EXACTLY the
	 * definition's and non-empty. It therefore affects zero rows for causes that
	 * are not remotely equivalent, and the only way to tell them apart is to
	 * read the row back authoritatively:
	 *
	 *   1. another worker genuinely completed it — the row is complete AND its
	 *      Package set matches the definition exactly. Benign: the desired state
	 *      holds, this run simply did not produce it, so it is counted
	 *      `existing` rather than `completed`;
	 *   2. the fence or the template moved — the run stops, named;
	 *   3. the occurrence is still incomplete, or its Package set is wrong —
	 *      the run stops. This is the case that matters: an earlier version
	 *      treated the whole `false` return as "a benign convergence no-op",
	 *      so a row left INCOMPLETE by a partially-applied repair was counted as
	 *      repaired and completed, and the run reported `status = ok` over an
	 *      occurrence that was unsellable and would stay that way.
	 *
	 * @param array<int,int> $definition_package_ids
	 * @return 'completed_elsewhere'|\WP_Error
	 */
	private function classify_completion_no_op( int $session_id, int $schedule_id, string $run_definition_token, array $definition_package_ids ) {
		global $wpdb;

		// Case 2 first: a lost fence explains every other symptom below, and
		// naming it as an incomplete Package set would be wrong.
		$fence = $this->reprove_fence( $schedule_id, $run_definition_token );
		if ( is_wp_error( $fence ) ) {
			return $fence;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT session_id, generation_completed_at, sale_locked_at, available_for_sale
				   FROM ' . Migrations::sessions_table() . ' WHERE session_id = %d AND schedule_id = %d',
				$session_id,
				$schedule_id
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( ! is_array( $row ) ) {
			// The occurrence this run was completing no longer exists.
			return $this->incomplete_occurrence_error( $session_id, 'occurrence_disappeared' );
		}

		$assigned = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT package_id FROM ' . Migrations::session_packages_table() . ' WHERE session_id = %d ORDER BY package_id',
				$session_id
			)
		);
		if ( ! is_array( $assigned ) || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		$assigned_ids = array_map( 'intval', $assigned );
		$expected_ids = array_map( 'intval', $definition_package_ids );
		sort( $assigned_ids );
		sort( $expected_ids );
		$set_matches = [] !== $expected_ids && $assigned_ids === $expected_ids;

		if ( null !== $row['generation_completed_at'] && $set_matches ) {
			// Case 1. Complete, and complete with the RIGHT set — which is the
			// state this run was trying to reach. Not this run's write.
			return 'completed_elsewhere';
		}

		// Case 3.
		return $this->incomplete_occurrence_error(
			$session_id,
			null === $row['generation_completed_at'] ? 'occurrence_still_incomplete' : 'package_set_incorrect'
		);
	}

	private function incomplete_occurrence_error( int $session_id, string $reason ): \WP_Error {
		return new \WP_Error(
			'generation_incomplete',
			__( 'An occurrence could not be completed, so it is not sellable. Generate again to converge it.', 'bingocommerce-sessions' ),
			[
				'status'           => 'write_failed',
				'failure_category' => 'write_failed',
				'session_id'       => $session_id,
				'reason'           => $reason,
				// Named so the caller can count `incomplete` before it stops.
				'count'            => 'incomplete',
			]
		);
	}

	/* ------------------------------------------------------------------ *
	 * Phase 4 — create (§7.7)
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string,mixed> $date_plan
	 * @param array<int,int>      $definition_package_ids
	 * @return true|'duplicate'|'created_completed_elsewhere'|\WP_Error
	 */
	private function create_one( int $schedule_id, string $run_definition_token, string $run_template_name, array $date_plan, array $definition_package_ids ) {
		$session_id = $this->writer->create_occurrence(
			$schedule_id,
			$run_definition_token,
			$run_template_name,
			$date_plan['session_date'],
			$date_plan['display_name'],
			$date_plan['start_time_local'],
			$date_plan['timezone_name'],
			$date_plan['starts_at_utc']
		);
		if ( is_wp_error( $session_id ) ) {
			return $session_id;
		}
		if ( 'duplicate' === $session_id ) {
			return 'duplicate';
		}
		if ( 0 === $session_id ) {
			// Fence lost, template renamed, or template went ineligible
			// mid-phase — classified by an authoritative re-read rather than
			// reported as one generic thing, because D25/D31 require the
			// rename case specifically named `template_renamed`.
			return $this->classify_create_zero_rows( $schedule_id, $run_definition_token, $run_template_name );
		}

		$converged = $this->writer->converge_packages( (int) $session_id, $schedule_id, $run_definition_token, $definition_package_ids );
		if ( is_wp_error( $converged ) ) {
			return $converged;
		}

		$completed = $this->writer->complete_occurrence( (int) $session_id, $schedule_id, $run_definition_token );
		if ( is_wp_error( $completed ) ) {
			return $completed;
		}
		if ( true === $completed ) {
			return true;
		}

		/*
		 * The INSERT succeeded, so the occurrence exists — but its completion
		 * affected zero rows, and an earlier version returned `true` here
		 * regardless, counting BOTH `created` and `completed` for a row that was
		 * left incomplete and therefore unsellable. Same rule as phase 3: read
		 * it back and let the row say which case this is.
		 */
		$classified = $this->classify_completion_no_op( (int) $session_id, $schedule_id, $run_definition_token, $definition_package_ids );
		if ( is_wp_error( $classified ) ) {
			return $classified;
		}

		// The row was created by this run and completed by another: `created`
		// is this run's, `completed` is not.
		return 'created_completed_elsewhere';
	}

	/** @return \WP_Error */
	private function classify_create_zero_rows( int $schedule_id, string $run_definition_token, string $run_template_name ) {
		global $wpdb;

		$schedule = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT status, active_definition_token, portal_session_id FROM ' . Migrations::schedules_table() . ' WHERE schedule_id = %d',
				$schedule_id
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( ! is_array( $schedule ) || self::STATUS_ACTIVE !== (string) $schedule['status'] || (string) $schedule['active_definition_token'] !== $run_definition_token ) {
			return $this->schedule_state_changed_error();
		}

		$template = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT name, is_current, portal_status FROM ' . Migrations::portal_session_templates_table() . ' WHERE portal_session_id = %d',
				(int) $schedule['portal_session_id']
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( ! is_array( $template ) || 1 !== (int) $template['is_current'] || 'active' !== (string) $template['portal_status'] ) {
			return $this->schedule_state_changed_error();
		}

		if ( (string) $template['name'] !== $run_template_name ) {
			return new \WP_Error(
				'template_renamed',
				__( 'The Portal Session template was renamed during this run. Re-run to pick up the new name.', 'bingocommerce-sessions' ),
				[ 'status' => 'template_renamed', 'failure_category' => 'template_renamed' ]
			);
		}

		return $this->schedule_state_changed_error();
	}

	/**
	 * §7.4's duplicate-key rule for the phase-4 occurrence INSERT: "the
	 * reconciler re-reads authoritatively, reports `conflicted` with the owning
	 * `session_id`, and the run ends `complete = false, status = failed`."
	 *
	 * A duplicate here is NOT the benign convergence case that §7.3 grants
	 * Package inserts. Preflight already proved this (date, name) pair was free
	 * under the column collation; a 1062 afterwards means something outside this
	 * run took the pair while the run was executing, and the Manager has to
	 * resolve it (§4.2 — "resolution of a collision is the Manager's"). An
	 * earlier version counted it `existing` or `conflicted` and let the run
	 * finish `status = ok`, which reported a converged schedule that had in fact
	 * not created the occurrence it was asked for.
	 *
	 * @return \WP_Error Always — the run stops either way; the data names which.
	 */
	private function classify_create_duplicate( int $schedule_id, string $session_date, string $display_name ): \WP_Error {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT session_id, schedule_id FROM ' . Migrations::sessions_table()
				. ' WHERE session_date = %s AND display_name = %s ORDER BY session_id LIMIT 1',
				$session_date,
				$display_name
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		$owner_session_id = is_array( $row ) ? (int) $row['session_id'] : 0;

		return new \WP_Error(
			'duplicate_conflict',
			__( 'Another occurrence already uses this date and name, so this one could not be created. Resolve the clash and generate again.', 'bingocommerce-sessions' ),
			[
				'status'           => 'duplicate_conflict',
				'failure_category' => 'duplicate_conflict',
				'session_id'       => $owner_session_id,
				'session_date'     => $session_date,
				'display_name'     => $display_name,
				// Named so the caller can count `conflicted` before it stops.
				'count'            => 'conflicted',
			]
		);
	}

	/* ------------------------------------------------------------------ *
	 * Phase 5 — retime (§7.4)
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<int,array<string,mixed>>|\WP_Error
	 *
	 * A failed read returns the failure. Returning `[]` — as an earlier version
	 * did for BOTH the schedule read and the candidate query — reports "there is
	 * nothing to retime" for a question the database never answered, and the run
	 * then reaches `status = ok` having skipped a phase it never ran.
	 */
	private function find_retime_candidates( int $schedule_id, string $today, array $future_date_keys ) {
		global $wpdb;

		if ( [] === $future_date_keys ) {
			return [];
		}

		$start_time_local = $this->schedule_start_time_local( $schedule_id );
		if ( is_wp_error( $start_time_local ) ) {
			return $start_time_local;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $future_date_keys ), '%s' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed-count %s list.
				'SELECT session_id, session_date, display_name, start_time_local
				   FROM ' . Migrations::sessions_table() . "
				  WHERE schedule_id             = %d
				    AND status                  = 'active'
				    AND available_for_sale      = 0
				    AND sale_locked_at          IS NULL
				    AND generation_completed_at IS NOT NULL
				    AND start_time_local        <> %s
				    AND session_date IN ({$placeholders})",
				array_merge( [ $schedule_id, $start_time_local ], $future_date_keys )
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		return $rows;
	}

	/** @param array<string,mixed> $candidate @return bool|\WP_Error */
	private function retime_one( array $candidate, int $schedule_id, string $run_definition_token, string $run_template_name ) {
		global $wpdb;

		$start_time_local = $this->schedule_start_time_local( $schedule_id );
		if ( is_wp_error( $start_time_local ) ) {
			return $start_time_local;
		}
		$zone = $this->schedule_timezone( $schedule_id );
		if ( is_wp_error( $zone ) ) {
			return $zone;
		}
		$instant = ScheduleTimezone::exact_instant( (string) $candidate['session_date'], $start_time_local, $zone );
		if ( is_wp_error( $instant ) ) {
			return $instant;
		}
		$new_name = ScheduleExpansion::occurrence_display_name( $run_template_name, $start_time_local );

		$sessions  = Migrations::sessions_table();
		$schedules = Migrations::schedules_table();
		$templates = Migrations::portal_session_templates_table();

		$affected = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Migrations.
				"UPDATE {$sessions} s
				   JOIN {$schedules} sch
				     ON  sch.schedule_id             = s.schedule_id
				    AND  sch.schedule_id             = %d
				    AND  sch.status                  = 'active'
				    AND  sch.active_definition_token = %s
				    SET  s.start_time_local = %s,
				         s.starts_at_utc    = %s,
				         s.display_name     = %s,
				         s.updated_at       = %s
				  WHERE  s.session_id              = %d
				    AND  s.status                  = 'active'
				    AND  s.available_for_sale      = 0
				    AND  s.sale_locked_at          IS NULL
				    AND  s.generation_completed_at IS NOT NULL
				    AND  s.session_date            = %s
				    AND  s.start_time_local        = %s
				    AND  s.display_name            = %s
				    AND  EXISTS (
				           SELECT 1 FROM {$templates} t
				            WHERE t.portal_session_id   = sch.portal_session_id
				              AND t.is_current          = 1
				              AND t.portal_status       = 'active'
				              AND CAST(t.name AS BINARY) = CAST(%s AS BINARY)
				         )",
				$schedule_id,
				$run_definition_token,
				$start_time_local,
				$instant,
				$new_name,
				current_time( 'mysql', true ),
				(int) $candidate['session_id'],
				(string) $candidate['session_date'],
				(string) $candidate['start_time_local'],
				(string) $candidate['display_name'],
				$run_template_name
			)
		);

		if ( false === $affected ) {
			if ( DatabaseContention::is_duplicate_key( $wpdb ) ) {
				return $this->duplicate_conflict_error( (int) $candidate['session_id'] );
			}
			if ( DatabaseContention::is_contention( $wpdb ) ) {
				return DatabaseContention::error();
			}

			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		if ( (int) $affected > 0 ) {
			return true;
		}

		/*
		 * §7.8: zero affected rows on retime is classified, and specifically a
		 * RENAME stops the whole run — the one zero-row cause among "turned ON,
		 * sale-locked, archived, incomplete, already retimed, fence lost,
		 * template no longer eligible, template renamed, or foreign schedule"
		 * that is NOT merely informational.
		 */
		$renamed = $this->classify_retime_rename( $schedule_id, $run_definition_token, $run_template_name );
		if ( is_wp_error( $renamed ) ) {
			return $renamed;
		}

		return false;
	}

	/** @return \WP_Error|null null when this is not a rename. */
	private function classify_retime_rename( int $schedule_id, string $run_definition_token, string $run_template_name ) {
		global $wpdb;

		$schedule = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT status, active_definition_token, portal_session_id FROM ' . Migrations::schedules_table() . ' WHERE schedule_id = %d',
				$schedule_id
			),
			ARRAY_A
		);
		// A read that FAILED is not evidence that this was not a rename, so it
		// is returned as the failure it is rather than as `null`.
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( ! is_array( $schedule ) || self::STATUS_ACTIVE !== (string) $schedule['status'] || (string) $schedule['active_definition_token'] !== $run_definition_token ) {
			return null; // fence loss is handled by the between-phase re-proof, not here.
		}

		$template = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT name, is_current, portal_status FROM ' . Migrations::portal_session_templates_table() . ' WHERE portal_session_id = %d',
				(int) $schedule['portal_session_id']
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( ! is_array( $template ) || 1 !== (int) $template['is_current'] || 'active' !== (string) $template['portal_status'] ) {
			return null;
		}

		if ( (string) $template['name'] !== $run_template_name ) {
			return new \WP_Error(
				'template_renamed',
				__( 'The Portal Session template was renamed during this run. Re-run to pick up the new name.', 'bingocommerce-sessions' ),
				[ 'status' => 'template_renamed', 'failure_category' => 'template_renamed' ]
			);
		}

		return null;
	}

	/** @return string|\WP_Error The count name, or the read failure that stops the run. */
	private function classify_retime_no_op( int $session_id, int $schedule_id, string $run_template_name ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT status, available_for_sale, sale_locked_at, generation_completed_at
				   FROM ' . Migrations::sessions_table() . ' WHERE session_id = %d AND schedule_id = %d',
				$session_id,
				$schedule_id
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( ! is_array( $row ) ) {
			return 'retained_outside_pattern';
		}
		if ( null !== $row['sale_locked_at'] || (bool) (int) $row['available_for_sale'] ) {
			return 'protected_time_conflict';
		}

		return 'existing';
	}

	/* ------------------------------------------------------------------ *
	 * Phase 6 — restore (§7.5)
	 * ------------------------------------------------------------------ */

	/**
	 * Archived future complete occurrences WHOSE DATE IS STILL IN T.
	 *
	 * §7.6 rows 9 and 10 restore an archived row only when it is "In T, in E";
	 * row 12 — "In E, not in T, locked, ON, **or archived**" — is explicitly
	 * **no write**, classified `retained_outside_pattern`. An earlier version of
	 * this method selected every archived future complete row for the schedule
	 * regardless of T, so a date the Manager had REMOVED from the definition was
	 * un-archived and put back on sale by the next run, while preflight
	 * simultaneously counted that same row `retained_outside_pattern`. The
	 * date filter is what makes the two agree.
	 *
	 * @param array<int,string> $future_date_keys T, strictly future, as dates.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private function find_restore_candidates( int $schedule_id, string $today, array $future_date_keys ) {
		global $wpdb;

		if ( [] === $future_date_keys ) {
			// Nothing is in T, so nothing is restorable. Not an error.
			return [];
		}

		$placeholders = implode( ', ', array_fill( 0, count( $future_date_keys ), '%s' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed-count %s list.
				"SELECT session_id, session_date, display_name, start_time_local, sale_locked_at
				   FROM " . Migrations::sessions_table() . "
				  WHERE schedule_id             = %d
				    AND status                  = 'archived'
				    AND generation_completed_at IS NOT NULL
				    AND session_date            > %s
				    AND session_date IN ({$placeholders})",
				array_merge( [ $schedule_id, $today ], $future_date_keys )
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		return $rows;
	}

	/** @param array<string,mixed> $candidate @return bool|\WP_Error */
	private function restore_one( array $candidate, int $schedule_id, string $run_definition_token, string $run_template_name, string $today ) {
		global $wpdb;

		$sessions  = Migrations::sessions_table();
		$schedules = Migrations::schedules_table();
		$templates = Migrations::portal_session_templates_table();
		$now       = current_time( 'mysql', true );
		$session_id = (int) $candidate['session_id'];

		if ( null !== $candidate['sale_locked_at'] ) {
			// Locked: lifecycle only. No name proof — this statement writes no display_name.
			$affected = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Migrations.
					"UPDATE {$sessions} s
					   JOIN {$schedules} sch
					     ON  sch.schedule_id             = s.schedule_id
					    AND  sch.schedule_id             = %d
					    AND  sch.status                  = 'active'
					    AND  sch.active_definition_token = %s
					    SET  s.status             = 'active',
					         s.archived_at        = NULL,
					         s.available_for_sale = 0,
					         s.updated_at         = %s
					 WHERE  s.session_id              = %d
					   AND  s.status                  = 'archived'
					   AND  s.sale_locked_at          IS NOT NULL
					   AND  s.generation_completed_at IS NOT NULL
					   AND  s.session_date            > %s
					   AND  EXISTS (
					          SELECT 1 FROM {$templates} t
					           WHERE t.portal_session_id = sch.portal_session_id
					             AND t.is_current = 1 AND t.portal_status = 'active'
					        )",
					$schedule_id,
					$run_definition_token,
					$now,
					$session_id,
					$today
				)
			);
		} else {
			$start_time_local = $this->schedule_start_time_local( $schedule_id );
			if ( is_wp_error( $start_time_local ) ) {
				return $start_time_local;
			}
			$zone = $this->schedule_timezone( $schedule_id );
			if ( is_wp_error( $zone ) ) {
				return $zone;
			}
			$instant = ScheduleTimezone::exact_instant( (string) $candidate['session_date'], $start_time_local, $zone );
			if ( is_wp_error( $instant ) ) {
				return $instant;
			}
			$new_name = ScheduleExpansion::occurrence_display_name( $run_template_name, $start_time_local );

			$affected = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Migrations.
					"UPDATE {$sessions} s
					   JOIN {$schedules} sch
					     ON  sch.schedule_id             = s.schedule_id
					    AND  sch.schedule_id             = %d
					    AND  sch.status                  = 'active'
					    AND  sch.active_definition_token = %s
					    SET  s.status             = 'active',
					         s.archived_at        = NULL,
					         s.available_for_sale = 0,
					         s.start_time_local   = %s,
					         s.starts_at_utc      = %s,
					         s.display_name       = %s,
					         s.updated_at         = %s
					 WHERE  s.session_id              = %d
					   AND  s.status                  = 'archived'
					   AND  s.sale_locked_at          IS NULL
					   AND  s.generation_completed_at IS NOT NULL
					   AND  s.session_date            > %s
					   AND  EXISTS (
					          SELECT 1 FROM {$templates} t
					           WHERE t.portal_session_id   = sch.portal_session_id
					             AND t.is_current          = 1
					             AND t.portal_status       = 'active'
					             AND CAST(t.name AS BINARY) = CAST(%s AS BINARY)
					        )",
					$schedule_id,
					$run_definition_token,
					$start_time_local,
					$instant,
					$new_name,
					$now,
					$session_id,
					$today,
					$run_template_name
				)
			);
		}

		if ( false === $affected ) {
			if ( DatabaseContention::is_contention( $wpdb ) ) {
				return DatabaseContention::error();
			}

			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		if ( (int) $affected > 0 ) {
			return true;
		}

		/*
		 * §7.8, D27: zero rows on the UNLOCKED restore — the one that writes a
		 * display_name — is checked for a rename, which stops the run. The
		 * LOCKED branch writes no display_name and carries no rename proof, so
		 * it never reaches this: candidate['sale_locked_at'] being non-NULL
		 * routed it to the OTHER branch above, which returns before this point.
		 */
		if ( null === $candidate['sale_locked_at'] ) {
			$renamed = $this->classify_retime_rename( $schedule_id, $run_definition_token, $run_template_name );
			if ( is_wp_error( $renamed ) ) {
				return $renamed;
			}
		}

		return false;
	}

	/**
	 * §7.5's authoritative re-read for a zero-row restore, on BOTH statements.
	 *
	 * Zero rows has causes that are not equivalent, and the statement cannot
	 * say which it hit — only a read of the row can:
	 *
	 *   - the fence or the template moved, which is checked FIRST because it is
	 *     in the statement's own JOIN and therefore explains a zero-row result
	 *     on its own;
	 *   - another worker already restored it — the row is active and still
	 *     owned by this schedule. Benign: the state this phase wanted holds, it
	 *     simply was not this run's write, so it is counted `restore_lost_race`;
	 *   - anything else — the row is gone, it is owned by a different schedule,
	 *     or IT IS STILL ARCHIVED. The last of these is the one that matters:
	 *     the occurrence is in the active definition and is not on sale, so the
	 *     run must NOT report `ok` over it.
	 *
	 * A read that FAILED is none of the above and is never guessed at: it is
	 * returned as `persistence_failed`, because "the row is archived" and "the
	 * database did not answer" are different facts and only one of them is
	 * something this run observed.
	 *
	 * @return 'restore_lost_race'|\WP_Error
	 */
	private function classify_restore_no_op( int $session_id, int $schedule_id, string $run_definition_token ) {
		global $wpdb;

		$fence = $this->reprove_fence( $schedule_id, $run_definition_token );
		if ( is_wp_error( $fence ) ) {
			return $fence;
		}

		// Read WITHOUT a schedule_id predicate, deliberately: a row that is
		// absent and a row that belongs to someone else are different failures
		// and a filtered read would collapse them into one.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT session_id, schedule_id, status, sale_locked_at, generation_completed_at
				   FROM ' . Migrations::sessions_table() . ' WHERE session_id = %d',
				$session_id
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( ! is_array( $row ) ) {
			return $this->restore_failed_error( $session_id, 'occurrence_disappeared' );
		}
		if ( null === $row['schedule_id'] || (int) $row['schedule_id'] !== $schedule_id ) {
			return $this->restore_failed_error( $session_id, 'occurrence_not_owned' );
		}

		if ( self::STATUS_ACTIVE === (string) $row['status'] ) {
			return 'restore_lost_race';
		}

		return $this->restore_failed_error( $session_id, 'occurrence_still_archived' );
	}

	private function restore_failed_error( int $session_id, string $reason ): \WP_Error {
		return new \WP_Error(
			'restore_failed',
			__( 'An occurrence in this schedule could not be restored, so it is still not on sale. Generate again to converge it.', 'bingocommerce-sessions' ),
			[
				'status'           => 'write_failed',
				'failure_category' => 'write_failed',
				'session_id'       => $session_id,
				'reason'           => $reason,
			]
		);
	}

	private function schedule_timezone( int $schedule_id ) {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare( 'SELECT timezone_name FROM ' . Migrations::schedules_table() . ' WHERE schedule_id = %d', $schedule_id )
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		return ScheduleTimezone::validate_zone( (string) $value );
	}

	/* ------------------------------------------------------------------ *
	 * Phase 7 — final verification (§7.12)
	 * ------------------------------------------------------------------ */

	/** @return true|\WP_Error */
	private function final_verification( int $schedule_id, string $run_definition_token, string $run_template_name ) {
		global $wpdb;

		$schedule = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT status, active_definition_token, portal_session_id FROM ' . Migrations::schedules_table() . ' WHERE schedule_id = %d',
				$schedule_id
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( ! is_array( $schedule )
			|| self::STATUS_ACTIVE !== (string) $schedule['status']
			|| (string) $schedule['active_definition_token'] !== $run_definition_token
		) {
			return $this->schedule_state_changed_error();
		}

		$template = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT name, portal_status, is_current FROM ' . Migrations::portal_session_templates_table() . ' WHERE portal_session_id = %d',
				(int) $schedule['portal_session_id']
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( ! is_array( $template ) || 1 !== (int) $template['is_current'] || 'active' !== (string) $template['portal_status'] ) {
			return new \WP_Error(
				'eligibility_failed',
				__( 'The Portal Session template is no longer active and current.', 'bingocommerce-sessions' ),
				[ 'status' => 'eligibility_failed', 'failure_category' => 'final_verification_failed' ]
			);
		}
		if ( (string) $template['name'] !== $run_template_name ) {
			return new \WP_Error(
				'template_renamed',
				__( 'The Portal Session template was renamed during this run. Re-run to pick up the new name.', 'bingocommerce-sessions' ),
				[ 'status' => 'template_renamed', 'failure_category' => 'template_renamed' ]
			);
		}

		$package_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT package_id FROM ' . Migrations::schedule_packages_table() . ' WHERE schedule_id = %d AND definition_token = %s',
				$schedule_id,
				$run_definition_token
			)
		);
		if ( ! is_array( $package_ids ) || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( [] === $package_ids ) {
			return new \WP_Error(
				'final_verification_failed',
				__( 'The active definition has no Bingo Packages.', 'bingocommerce-sessions' ),
				[ 'status' => 'final_verification_failed', 'failure_category' => 'final_verification_failed' ]
			);
		}

		foreach ( $package_ids as $package_id ) {
			$package = $this->packages->get( (int) $package_id );
			if ( is_wp_error( $package ) ) {
				return $package;
			}
			if ( ! is_array( $package ) || is_wp_error( PackageGateway::assert_assignable( $package ) ) ) {
				return new \WP_Error(
					'final_verification_failed',
					__( 'A Bingo Package in the active definition is no longer assignable.', 'bingocommerce-sessions' ),
					[ 'status' => 'final_verification_failed', 'failure_category' => 'final_verification_failed', 'package_id' => (int) $package_id ]
				);
			}
		}

		return true;
	}

	/* ------------------------------------------------------------------ *
	 * Shared helpers
	 * ------------------------------------------------------------------ */

	private const STATUS_ACTIVE = 'active';

	/**
	 * Apply the count an error names before the run stops on it.
	 *
	 * A run that stops still reports what it OBSERVED accurately (§7.6): a
	 * duplicate is counted `conflicted` and an occurrence left unfinished is
	 * counted `incomplete`, in the same result that carries the failure. The
	 * count name travels on the error's own data so the classifier that
	 * established the fact is the thing that names it.
	 *
	 * @param array<string,int> $counts
	 * @return array<string,int>
	 */
	private function count_from( \WP_Error $error, array $counts ): array {
		$data = $error->get_error_data();
		$name = is_array( $data ) ? (string) ( $data['count'] ?? '' ) : '';

		if ( '' !== $name && in_array( $name, SessionScheduleResult::COUNTS, true ) ) {
			$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + 1;
		}

		return $counts;
	}

	/** @param array<string,int> $counts @return SessionScheduleResult */
	private function stop( \WP_Error $error, array $counts ): SessionScheduleResult {
		$data = $error->get_error_data();

		/*
		 * `schedule_state_changed` is a STATUS (§8.6), not a member of
		 * `failure_category`'s smaller enum — SessionScheduleResult's own
		 * constructor sets `failure_category` to null whenever status is
		 * `schedule_state_changed`, exactly as it does for a successful run.
		 * This distinction was lost by an earlier version of this method, which
		 * unconditionally wrapped every error as STATUS_FAILED regardless of
		 * what the error's own `status` data said — caught by C22, which
		 * expects `schedule_state_changed`, not `failed`.
		 */
		if ( is_array( $data ) && 'schedule_state_changed' === ( $data['status'] ?? '' ) ) {
			return $this->fence_lost( $counts );
		}

		$category = is_array( $data ) ? (string) ( $data['failure_category'] ?? 'write_failed' ) : 'write_failed';

		return new SessionScheduleResult(
			SessionScheduleResult::STATUS_FAILED,
			[],
			in_array( $category, SessionScheduleResult::FAILURE_CATEGORIES, true ) ? $category : 'write_failed',
			$counts,
			[ [ 'code' => $error->get_error_code(), 'message' => $error->get_error_message() ] ]
		);
	}

	/** @param array<string,int> $counts */
	private function fence_lost( array $counts ): SessionScheduleResult {
		return new SessionScheduleResult( SessionScheduleResult::STATUS_SCHEDULE_STATE_CHANGED, [], 'write_failed', $counts );
	}

	private function schedule_state_changed_error(): \WP_Error {
		return new \WP_Error(
			'schedule_state_changed',
			__( 'The schedule changed while this run was executing. Reload and try again.', 'bingocommerce-sessions' ),
			[ 'status' => 'schedule_state_changed', 'failure_category' => 'write_failed' ]
		);
	}

	private function duplicate_conflict_error( int $session_id ): \WP_Error {
		return new \WP_Error(
			'duplicate_conflict',
			__( 'A concurrent process already changed this occurrence.', 'bingocommerce-sessions' ),
			[ 'status' => 'duplicate_conflict', 'failure_category' => 'duplicate_conflict', 'session_id' => $session_id ]
		);
	}

	private function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-sessions' ), [ 'status' => $code, 'failure_category' => 'write_failed' ] );
	}

	private function persistence_error( string $db_error ): \WP_Error {
		return new \WP_Error(
			'persistence_failed',
			__( 'A reconciliation statement failed.', 'bingocommerce-sessions' ),
			[ 'status' => 'write_failed', 'failure_category' => 'write_failed', 'db_error' => $db_error ]
		);
	}
}
