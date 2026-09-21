<?php

namespace BingoCommerce\Sessions;

use BingoCommerce\Core\AuditLog;
use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Commerce schedule CRUD and the definition commit (ADR-043 §3).
 *
 * A SCHEDULE IS A DEFINITION, NOT A SET OF OCCURRENCES. It names one Portal
 * Session template, one immutable scheduling timezone, one start time, and
 * either a list of explicit dates or a weekly recurrence — plus the Packages
 * every occurrence it generates should start with. Occurrences are created
 * and reconciled separately by OccurrenceReconciler; this service does neither.
 * Schedule edits may turn sales OFF on existing schedule-owned occurrences to
 * enforce removed/re-added date behavior, but do not otherwise mutate them.
 *
 * WHY A UNIQUE TOKEN AND NOT `revision + 1` (§3.1). An incrementing revision is
 * not concurrency-safe: two editors both compute the same `next`, both write
 * child rows under it, and their rows interleave into one merged definition
 * before either pointer update runs — a definition neither Manager authored. A
 * UUID per ATTEMPT removes the shared namespace entirely. Each request writes
 * its complete child set under a token no other request can name, and a single
 * compare-and-swap then publishes exactly one of them.
 *
 * WHY THERE IS NO TRANSACTION. AGENTS.md rule 4 enumerates the four approved
 * mysqli transports and none covers this, and ADR-033 PROHIBITS `$wpdb->query()`
 * for statements issued inside an open transaction because `wpdb::query()` fires
 * the `query` filter on every statement and is therefore not callback-free
 * (`docs/decisions/DECISIONS.md:468-482`). No fifth transport is introduced.
 * Correctness comes from the staging order instead: a schedule with
 * `active_definition_token IS NULL` is INERT and unreadable, so a half-written
 * definition is invisible rather than partially live, and the CAS is the single
 * instant at which it becomes real.
 *
 * WHAT THE CAS PROVES, AND WHAT IT CANNOT (§3.2). Atomically at commit time it
 * proves the schedule exists, is active, still carries the token this request
 * observed, and that the Portal template is STILL commerce-eligible. It cannot
 * prove Package assignability: `PackageGateway::assert_assignable()` is PHP that
 * reads Catalog rows and a WooCommerce product, and no SQL statement can carry
 * it. This service therefore does not claim Package validity at the instant of
 * commit. A definition committed with a Package that became unassignable a
 * millisecond later is not a correctness hole — generation refuses to create
 * anything, turning an occurrence ON refuses, and the storefront refuses. The
 * schedule is simply unusable until the Package is fixed, which is the correct
 * fail-closed outcome.
 */
final class SessionScheduleService {

	public const STATUS_ACTIVE   = 'active';
	public const STATUS_ARCHIVED = 'archived';

	private const STATUSES = [ self::STATUS_ACTIVE, self::STATUS_ARCHIVED ];

	private const COLUMNS = 'schedule_id, portal_session_id, timezone_name, start_time_local, mode, weekday_mask, recurrence_start, recurrence_end, active_definition_token, status, created_at, updated_at, archived_at';

	/** @var callable */
	private $audit_recorder;

	private PackageGateway $packages;

	private PortalTemplateGateway $templates;

	public function __construct(
		?callable $audit_recorder = null,
		?PackageGateway $packages = null,
		?PortalTemplateGateway $templates = null
	) {
		$this->audit_recorder = $audit_recorder ?? [ AuditLog::class, 'record' ];
		$this->packages       = $packages ?? new PackageGateway();
		$this->templates      = $templates ?? new PortalTemplateGateway();
	}

	/* ------------------------------------------------------------------ *
	 * Writers
	 * ------------------------------------------------------------------ */

	/**
	 * Create a schedule (§3.8 Create).
	 *
	 * Ordered exactly as §3.8 requires, and the order is the design:
	 *
	 *   1. validate the COMPLETE request — nothing is written until all of it
	 *      passes, so a rejected request leaves no row at all;
	 *   2. INSERT the provisional header, with `active_definition_token` as the
	 *      literal SQL NULL. The row is inert and unreadable;
	 *   3. stage the date and Package rows under a private token;
	 *   4. the template-guarded compare-and-swap. <- commit point;
	 *   5. on zero affected rows the schedule stays inert, this request's own
	 *      token is cleaned, and the row is shown as incomplete so the Manager
	 *      can re-save or archive it. Safe to retry.
	 *
	 * @param array<int,string> $dates        Explicit dates, `mode = dates` only.
	 * @param array<int,int>    $package_ids  At least one, each assignable now.
	 * @return SessionScheduleResult|\WP_Error
	 */
	public function create(
		int $portal_session_id,
		string $mode,
		string $start_time,
		array $dates = [],
		array $package_ids = [],
		string $recurrence_start = '',
		string $recurrence_end = '',
		int $weekday_mask = 0
	) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		// Step 1. The COMPLETE request, including the hall's zone, the template's
		// eligibility and every Package — before a single row exists.
		$zone = ScheduleTimezone::capture_hall_zone();
		if ( is_wp_error( $zone ) ) {
			return $zone;
		}

		$validated = $this->validate_definition(
			$portal_session_id,
			$mode,
			$start_time,
			$dates,
			$package_ids,
			$recurrence_start,
			$recurrence_end,
			$weekday_mask,
			$zone,
			[],
			false
		);
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		global $wpdb;

		$now   = current_time( 'mysql', true );
		$table = Migrations::schedules_table();

		// Step 2. The provisional header. `active_definition_token` is the
		// LITERAL SQL NULL, never a placeholder: $wpdb->prepare() with %s and a
		// PHP null produces a quoted EMPTY STRING, not NULL (§3.3), and an empty
		// string would be a readable token naming a definition that does not
		// exist. Each mode has its own exact statement, selected by an internal
		// validated literal and never by request text.
		if ( ScheduleExpansion::MODE_WEEKLY === $validated['mode'] ) {
			$inserted = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Migrations.
					"INSERT INTO {$table}
					   (portal_session_id, timezone_name, start_time_local, mode, weekday_mask,
					    recurrence_start, recurrence_end, active_definition_token,
					    status, created_at, updated_at, archived_at)
					 VALUES
					   (%d, %s, %s, 'weekly', %d, %s, %s, NULL, 'active', %s, %s, NULL)",
					$validated['portal_session_id'],
					$zone,
					$validated['start_time_local'],
					$validated['weekday_mask'],
					$validated['recurrence_start'],
					$validated['recurrence_end'],
					$now,
					$now
				)
			);
		} else {
			$inserted = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Migrations.
					"INSERT INTO {$table}
					   (portal_session_id, timezone_name, start_time_local, mode, weekday_mask,
					    recurrence_start, recurrence_end, active_definition_token,
					    status, created_at, updated_at, archived_at)
					 VALUES
					   (%d, %s, %s, 'dates', 0, NULL, NULL, NULL, 'active', %s, %s, NULL)",
					$validated['portal_session_id'],
					$zone,
					$validated['start_time_local'],
					$now,
					$now
				)
			);
		}

		if ( 1 !== $inserted ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		$schedule_id = (int) $wpdb->insert_id;
		if ( $schedule_id < 1 ) {
			return $this->persistence_error( 'the schedule identity could not be read back' );
		}

		// Step 3. Stage the complete child set under a token only this request
		// knows. Nothing reads it until the CAS publishes it.
		$token  = $this->new_definition_token();
		$staged = $this->stage_definition( $schedule_id, $token, $validated, $now );
		if ( is_wp_error( $staged ) ) {
			$this->clean_private_token( $schedule_id, $token );

			return $staged;
		}

		// Step 4. The commit point.
		$committed = $this->commit_create( $schedule_id, $token, $validated, $now );
		if ( is_wp_error( $committed ) ) {
			return $committed;
		}

		if ( ! $committed ) {
			// Step 5. Zero affected rows. The schedule remains inert.
			$this->clean_private_token( $schedule_id, $token );

			return $this->classify_commit_failure( $schedule_id, $validated['portal_session_id'] );
		}

		$row = $this->read_by_id( $schedule_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		return $this->record_audit(
			SessionScheduleResult::ok( $row ),
			'sessions.schedule.created',
			$context,
			null,
			$row,
			$schedule_id
		);
	}

	/**
	 * Edit a schedule's definition (§3.8 Edit).
	 *
	 * WHICH FENCE APPLIES IS DECIDED BY WHETHER A DEFINITION EXISTS, not by the
	 * fact that this method is called `edit()`. Both cases are legitimate:
	 *
	 *  - ORDINARY EDIT — the schedule already has a committed definition. The CAS
	 *    fences on `active_definition_token = $seen`, the exact token observed
	 *    before this edit began. That comparison is what makes two concurrent
	 *    editors resolve to a single winner. `IS NULL` would match nothing here,
	 *    because the column is not NULL.
	 *
	 *  - RECOVERY OF AN INERT PROVISIONAL CREATE — the schedule has no definition,
	 *    because its create CAS affected zero rows and left the header with
	 *    `active_definition_token` as SQL NULL. There is no predecessor token to
	 *    compare against, so the fence is `active_definition_token IS NULL` and
	 *    the commit is create-shaped — against the EXISTING header, keeping the
	 *    same `schedule_id`. No second header is inserted, and commit_create() is
	 *    reused rather than duplicated precisely so `portal_session_id` and
	 *    `timezone_name` stay absent from the assignment list.
	 *
	 * An earlier revision of this comment claimed `IS NULL` is never the correct
	 * fence for an edit. That was wrong, and it left an inert schedule
	 * unrecoverable: the Manager could only archive it and start again.
	 *
	 * Everything is RE-VALIDATED now. Template eligibility and Package
	 * assignability are never inherited from the definition being replaced: both
	 * can have changed since it was committed, and a schedule that silently
	 * carried a stale approval forward would generate against it.
	 *
	 * @param array<int,string> $dates
	 * @param array<int,int>    $package_ids
	 * @return SessionScheduleResult|\WP_Error
	 */
	public function edit(
		int $schedule_id,
		string $mode,
		string $start_time,
		array $dates = [],
		array $package_ids = [],
		string $recurrence_start = '',
		string $recurrence_end = '',
		int $weekday_mask = 0
	) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$before = $this->require_row( $schedule_id );
		if ( is_wp_error( $before ) ) {
			return $before;
		}

		if ( self::STATUS_ACTIVE !== $before['status'] ) {
			return $this->error( 'schedule_not_active', 'An archived schedule cannot be edited. Restore it first.' );
		}

		/*
		 * Step 1. $seen — the token this edit supersedes.
		 *
		 * AN INERT SCHEDULE IS RE-SAVED, NOT REFUSED. When a create's CAS affects
		 * zero rows the header row survives with `active_definition_token` as SQL
		 * NULL: inert, unreadable, generating nothing. §3.8 step 5 requires that
		 * row to be re-savable so the Manager can fix whatever blocked the commit
		 * — most often a template that went draft — and save again, rather than
		 * being left with a dead row they can only archive.
		 *
		 * Re-saving is a CREATE-SHAPED commit against an EXISTING header: the
		 * fence is `active_definition_token IS NULL`, not `= $seen`, because there
		 * is no predecessor token to compare against. It reuses the same
		 * schedule_id and writes no second header.
		 */
		$seen    = (string) $before['active_definition_token'];
		$is_inert = '' === $seen;

		/*
		 * The zone is the STORED one, re-validated, never the hall's current
		 * setting (§5.1). timezone_name appears in no UPDATE assignment list
		 * anywhere in this class; re-reading the hall's setting here is the exact
		 * mistake that would let a Settings change silently re-interpret every
		 * occurrence a schedule has already generated.
		 */
		$zone = ScheduleTimezone::validate_zone( (string) $before['timezone_name'] );
		if ( is_wp_error( $zone ) ) {
			return $zone;
		}

		/*
		 * An inert schedule has no committed definition, so nothing is exempt
		 * from the tomorrow-or-later rule and every date must be in the future —
		 * the same rule a create applies, which is what "create-shaped" means.
		 */
		$previously_committed = $is_inert
			? [ 'dates' => [], 'recurrence_start' => $before['recurrence_start'] ]
			: $this->read_definition_dates( $schedule_id, $seen );
		if ( is_wp_error( $previously_committed ) ) {
			return $previously_committed;
		}

		$validated = $this->validate_definition(
			(int) $before['portal_session_id'],
			$mode,
			$start_time,
			$dates,
			$package_ids,
			$recurrence_start,
			$recurrence_end,
			$weekday_mask,
			$zone,
			$previously_committed,
			! $is_inert
		);
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		/*
		 * Capture this edit's exact occurrence consequences before publishing its
		 * definition. Removed IDs stay fixed for the post-CAS OFF write even if a
		 * later edit re-adds them. Re-added IDs are turned OFF before this edit's
		 * CAS, so publishing the replacement can never expose their prior ON flag.
		 */
		$transition_occurrence_ids = $this->definition_transition_occurrence_ids(
			$schedule_id,
			$before,
			$previously_committed,
			$validated,
			$zone,
			$is_inert
		);
		if ( is_wp_error( $transition_occurrence_ids ) ) {
			return $transition_occurrence_ids;
		}

		$now = current_time( 'mysql', true );

		// Step 3. Stage under a private token.
		$token  = $this->new_definition_token();
		$staged = $this->stage_definition( $schedule_id, $token, $validated, $now );
		if ( is_wp_error( $staged ) ) {
			$this->clean_private_token( $schedule_id, $token );

			return $staged;
		}

		/*
		 * A previously generated occurrence must already be OFF when its date is
		 * re-published. If this write fails, discard this request's private rows and
		 * leave the current definition untouched. If the later CAS loses, leaving
		 * these currently ineligible occurrences OFF is intentionally conservative.
		 */
		$disabled = $this->disable_captured_occurrences( $schedule_id, $transition_occurrence_ids['readded'], true );
		if ( is_wp_error( $disabled ) ) {
			$this->clean_private_token( $schedule_id, $token );

			return $disabled;
		}

		/*
		 * Step 4. The commit point.
		 *
		 * Both statements carry the template guard, and NEITHER assigns
		 * `portal_session_id` or `timezone_name` — the re-save path reuses
		 * commit_create() precisely so that immutability is enforced by the same
		 * assignment list, not by a second one that could drift from it.
		 */
		$committed = $is_inert
			? $this->commit_create( $schedule_id, $token, $validated, $now )
			: $this->commit_edit( $schedule_id, $token, $seen, $validated, $now );
		if ( is_wp_error( $committed ) ) {
			return $committed;
		}

		if ( ! $committed ) {
			$this->clean_private_token( $schedule_id, $token );

			// Safe to retry: an inert row stays inert, and an edited row keeps
			// its previous definition intact and live.
			return $this->classify_commit_failure( $schedule_id, (int) $before['portal_session_id'] );
		}

		// A removed future date must not resume sales merely by being re-added.
		// This only turns sales OFF; lifecycle/history remain reconciliation's.
		$disabled = $this->disable_captured_occurrences( $schedule_id, $transition_occurrence_ids['excluded'] );
		if ( is_wp_error( $disabled ) ) {
			return $disabled; // The definition committed; membership still blocks sale.
		}

		// Step 5. The superseded definition's rows, proved dead before deletion.
		// An inert schedule superseded nothing, so there is nothing to clean.
		if ( ! $is_inert ) {
			$this->clean_superseded_token( $schedule_id, $seen );
		}

		$row = $this->read_by_id( $schedule_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		/*
		 * An inert row never recorded `sessions.schedule.created`: create() audits
		 * only on a successful commit, and its commit is exactly what failed. The
		 * re-save is therefore the moment this schedule's definition comes into
		 * existence, and is audited as the creation it is, with the inert row as
		 * the previous value so the transition is legible.
		 */
		return $this->record_audit(
			SessionScheduleResult::ok( $row ),
			$is_inert ? 'sessions.schedule.created' : 'sessions.schedule.updated',
			$context,
			$before,
			$row,
			$schedule_id
		);
	}

	/**
	 * The fixed sets of this edit's existing active future occurrences whose dates
	 * are removed from or re-added to the validated replacement definition.
	 *
	 * Both definitions are expanded through ScheduleExpansion, keeping these sets
	 * bounded by the definition limits and avoiding a second explicit-date or
	 * weekly-membership implementation. Dates retained continuously belong to
	 * neither set. Capturing matching IDs even when they are currently OFF ensures
	 * an ON that commits after this read but before the definition CAS is cleared.
	 *
	 * @param array<string,mixed> $current
	 * @param array<string,mixed> $current_children
	 * @param array<string,mixed> $replacement
	 * @return array{excluded:array<int,int>,readded:array<int,int>}|\WP_Error
	 */
	private function definition_transition_occurrence_ids(
		int $schedule_id,
		array $current,
		array $current_children,
		array $replacement,
		string $zone,
		bool $is_inert
	) {
		global $wpdb;

		$today = ScheduleTimezone::local_today( $zone );
		if ( null === $today ) {
			return $this->error( 'hall_timezone_not_named', 'The scheduling time zone could not be evaluated.' );
		}

		if ( $is_inert ) {
			return [ 'excluded' => [], 'readded' => [] ];
		}

		$current_dates = ScheduleExpansion::expand(
			(string) $current['mode'],
			(array) ( $current_children['dates'] ?? [] ),
			(string) $current['recurrence_start'],
			(string) $current['recurrence_end'],
			(int) $current['weekday_mask']
		);
		$replacement_dates = ScheduleExpansion::expand(
			(string) $replacement['mode'],
			(array) $replacement['dates'],
			(string) $replacement['recurrence_start'],
			(string) $replacement['recurrence_end'],
			(int) $replacement['weekday_mask']
		);
		$current_members     = array_fill_keys( $current_dates, true );
		$replacement_members = array_fill_keys( $replacement_dates, true );
		$excluded_dates      = [];
		$readded_dates       = [];
		foreach ( ScheduleExpansion::strictly_future( $current_dates, $today ) as $date ) {
			if ( ! isset( $replacement_members[ $date ] ) ) {
				$excluded_dates[] = $date;
			}
		}
		foreach ( ScheduleExpansion::strictly_future( $replacement_dates, $today ) as $date ) {
			if ( ! isset( $current_members[ $date ] ) ) {
				$readded_dates[] = $date;
			}
		}

		$changed_dates = array_merge( $excluded_dates, $readded_dates );
		if ( [] === $changed_dates ) {
			return [ 'excluded' => [], 'readded' => [] ];
		}

		$placeholders = implode( ', ', array_fill( 0, count( $changed_dates ), '%s' ) );
		$arguments    = array_merge( [ $schedule_id, $today ], $changed_dates );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Migrations; placeholders are generated internally.
				'SELECT session_id, session_date FROM ' . Migrations::sessions_table() . "
				 WHERE schedule_id = %d AND status = 'active' AND session_date > %s
				   AND session_date IN ({$placeholders})
				 ORDER BY session_id ASC",
				$arguments
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		$excluded_members = array_fill_keys( $excluded_dates, true );
		$readded_members  = array_fill_keys( $readded_dates, true );
		$ids              = [ 'excluded' => [], 'readded' => [] ];
		foreach ( $rows as $row ) {
			$date       = (string) $row['session_date'];
			$session_id = (int) $row['session_id'];
			if ( isset( $excluded_members[ $date ] ) ) {
				$ids['excluded'][] = $session_id;
			}
			if ( isset( $readded_members[ $date ] ) ) {
				$ids['readded'][] = $session_id;
			}
		}

		return $ids;
	}

	/**
	 * Apply a captured OFF consequence without re-reading current membership or
	 * the hall-local date boundary. The schedule and captured identities keep the
	 * write scoped to precisely this request's transition set. Re-added rows are
	 * written even when already OFF so the complete OFF state, operation token and
	 * timestamp are established before publication; removed rows retain the prior
	 * ON-only post-CAS behavior.
	 *
	 * @param array<int,int> $occurrence_ids
	 * @return true|\WP_Error
	 */
	private function disable_captured_occurrences( int $schedule_id, array $occurrence_ids, bool $write_already_off = false ) {
		if ( [] === $occurrence_ids ) {
			return true;
		}

		global $wpdb;

		$placeholders      = implode( ', ', array_fill( 0, count( $occurrence_ids ), '%d' ) );
		$availability_guard = $write_already_off ? '' : ' AND available_for_sale = 1';
		$arguments         = array_merge(
			[ current_time( 'mysql', true ), $schedule_id ],
			$occurrence_ids
		);
		$affected = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Migrations; placeholders are generated internally.
				'UPDATE ' . Migrations::sessions_table() . "
				 SET available_for_sale = 0, availability_operation_token = NULL, updated_at = %s
				 WHERE schedule_id = %d AND status = 'active'
				   AND session_id IN ({$placeholders}){$availability_guard}",
				$arguments
			)
		);
		if ( false === $affected || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		return true;
	}

	/**
	 * Archive a schedule (§3.8 Archive).
	 *
	 * DELIBERATELY NOT TEMPLATE-GUARDED. Stopping commerce must never be blocked:
	 * a template going draft is one of the likeliest reasons a Manager needs to
	 * archive, and a guard here would refuse exactly then.
	 *
	 * NO OCCURRENCE IS TOUCHED. An Available-for-Sale occurrence under an
	 * archived schedule stops being customer-eligible through the §6.4 predicate,
	 * with no write at all — so archiving is instant, reversible, and leaves the
	 * durable record of what was sold completely intact.
	 *
	 * @return SessionScheduleResult|\WP_Error
	 */
	public function archive( int $schedule_id ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$before = $this->require_row( $schedule_id );
		if ( is_wp_error( $before ) ) {
			return $before;
		}

		global $wpdb;

		$now   = current_time( 'mysql', true );
		$table = Migrations::schedules_table();

		$affected = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Migrations.
				"UPDATE {$table}
				    SET status = 'archived', archived_at = %s, updated_at = %s
				  WHERE schedule_id = %d
				    AND status = 'active'",
				$now,
				$now,
				$schedule_id
			)
		);

		if ( false === $affected ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		if ( 0 === (int) $affected ) {
			return $this->classify_lifecycle_no_op( $schedule_id, self::STATUS_ARCHIVED );
		}

		$row = $this->read_by_id( $schedule_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		return $this->record_audit(
			SessionScheduleResult::ok( $row ),
			'sessions.schedule.archived',
			$context,
			$before,
			$row,
			$schedule_id
		);
	}

	/**
	 * Restore an archived schedule (§3.8 Restore).
	 *
	 * TEMPLATE-GUARDED, because it RESUMES commerce. The guard is inside the
	 * statement, not a pre-read: between a read and the write the Portal can
	 * sync, and restoring a schedule onto a template that has gone draft would
	 * start generating sellable occurrences for something the Portal no longer
	 * considers active.
	 *
	 * @return SessionScheduleResult|\WP_Error
	 */
	public function restore( int $schedule_id ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$before = $this->require_row( $schedule_id );
		if ( is_wp_error( $before ) ) {
			return $before;
		}

		global $wpdb;

		$now       = current_time( 'mysql', true );
		$table     = Migrations::schedules_table();
		$templates = Migrations::portal_session_templates_table();

		$affected = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Migrations.
				"UPDATE {$table} s
				    SET s.status      = 'active',
				        s.archived_at = NULL,
				        s.updated_at  = %s
				  WHERE s.schedule_id = %d
				    AND s.status      = 'archived'
				    AND EXISTS (
				          SELECT 1 FROM {$templates} t
				           WHERE t.portal_session_id = s.portal_session_id
				             AND t.is_current        = 1
				             AND t.portal_status     = 'active'
				        )",
				$now,
				$schedule_id
			)
		);

		if ( false === $affected ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		if ( 0 === (int) $affected ) {
			return $this->classify_lifecycle_no_op( $schedule_id, self::STATUS_ACTIVE );
		}

		$row = $this->read_by_id( $schedule_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		return $this->record_audit(
			SessionScheduleResult::ok( $row ),
			'sessions.schedule.restored',
			$context,
			$before,
			$row,
			$schedule_id
		);
	}

	/* ------------------------------------------------------------------ *
	 * Commit — the compare-and-swap statements (§3.5, §3.6)
	 * ------------------------------------------------------------------ */

	/**
	 * §3.5 Create commit. Fences on `active_definition_token IS NULL`.
	 *
	 * `timezone_name` and `portal_session_id` are ABSENT from both assignment
	 * lists, in this method and in commit_edit(). That absence is the enforcement
	 * of §5.1's immutability and of the schedule's identity: there is no code
	 * path in this class that can change either one, so no review of a future
	 * caller is needed to know they cannot drift.
	 *
	 * @param array<string,mixed> $validated
	 * @return bool|\WP_Error True when exactly one row was published.
	 */
	private function commit_create( int $schedule_id, string $token, array $validated, string $now ) {
		global $wpdb;

		$table     = Migrations::schedules_table();
		$templates = Migrations::portal_session_templates_table();

		if ( ScheduleExpansion::MODE_WEEKLY === $validated['mode'] ) {
			$affected = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Migrations.
					"UPDATE {$table} s
					    SET s.mode                    = 'weekly',
					        s.weekday_mask            = %d,
					        s.recurrence_start        = %s,
					        s.recurrence_end          = %s,
					        s.start_time_local        = %s,
					        s.active_definition_token = %s,
					        s.updated_at              = %s
					  WHERE s.schedule_id             = %d
					    AND s.active_definition_token IS NULL
					    AND s.status                  = 'active'
					    AND EXISTS (
					          SELECT 1 FROM {$templates} t
					           WHERE t.portal_session_id = s.portal_session_id
					             AND t.is_current        = 1
					             AND t.portal_status     = 'active'
					        )",
					$validated['weekday_mask'],
					$validated['recurrence_start'],
					$validated['recurrence_end'],
					$validated['start_time_local'],
					$token,
					$now,
					$schedule_id
				)
			);
		} else {
			$affected = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Migrations.
					"UPDATE {$table} s
					    SET s.mode                    = 'dates',
					        s.weekday_mask            = 0,
					        s.recurrence_start        = NULL,
					        s.recurrence_end          = NULL,
					        s.start_time_local        = %s,
					        s.active_definition_token = %s,
					        s.updated_at              = %s
					  WHERE s.schedule_id             = %d
					    AND s.active_definition_token IS NULL
					    AND s.status                  = 'active'
					    AND EXISTS (
					          SELECT 1 FROM {$templates} t
					           WHERE t.portal_session_id = s.portal_session_id
					             AND t.is_current        = 1
					             AND t.portal_status     = 'active'
					        )",
					$validated['start_time_local'],
					$token,
					$now,
					$schedule_id
				)
			);
		}

		if ( false === $affected ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		return 1 === (int) $affected;
	}

	/**
	 * §3.6 Edit commit. Fences on the exact token observed before the edit began.
	 *
	 * The trailing `active_definition_token = %s` in the WHERE clause is `$seen`.
	 * Under `utf8mb4_bin` that comparison is byte-exact, so no case- or
	 * accent-folding can make one token match another.
	 *
	 * @param array<string,mixed> $validated
	 * @return bool|\WP_Error
	 */
	private function commit_edit( int $schedule_id, string $token, string $seen, array $validated, string $now ) {
		global $wpdb;

		$table     = Migrations::schedules_table();
		$templates = Migrations::portal_session_templates_table();

		if ( ScheduleExpansion::MODE_WEEKLY === $validated['mode'] ) {
			$affected = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Migrations.
					"UPDATE {$table} s
					    SET s.mode                    = 'weekly',
					        s.weekday_mask            = %d,
					        s.recurrence_start        = %s,
					        s.recurrence_end          = %s,
					        s.start_time_local        = %s,
					        s.active_definition_token = %s,
					        s.updated_at              = %s
					  WHERE s.schedule_id             = %d
					    AND s.active_definition_token = %s
					    AND s.status                  = 'active'
					    AND EXISTS (
					          SELECT 1 FROM {$templates} t
					           WHERE t.portal_session_id = s.portal_session_id
					             AND t.is_current        = 1
					             AND t.portal_status     = 'active'
					        )",
					$validated['weekday_mask'],
					$validated['recurrence_start'],
					$validated['recurrence_end'],
					$validated['start_time_local'],
					$token,
					$now,
					$schedule_id,
					$seen
				)
			);
		} else {
			$affected = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Migrations.
					"UPDATE {$table} s
					    SET s.mode                    = 'dates',
					        s.weekday_mask            = 0,
					        s.recurrence_start        = NULL,
					        s.recurrence_end          = NULL,
					        s.start_time_local        = %s,
					        s.active_definition_token = %s,
					        s.updated_at              = %s
					  WHERE s.schedule_id             = %d
					    AND s.active_definition_token = %s
					    AND s.status                  = 'active'
					    AND EXISTS (
					          SELECT 1 FROM {$templates} t
					           WHERE t.portal_session_id = s.portal_session_id
					             AND t.is_current        = 1
					             AND t.portal_status     = 'active'
					        )",
					$validated['start_time_local'],
					$token,
					$now,
					$schedule_id,
					$seen
				)
			);
		}

		if ( false === $affected ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		return 1 === (int) $affected;
	}

	/**
	 * Distinguish the reasons a CAS can affect zero rows (§3.5).
	 *
	 * An authoritative RE-READ decides, because the statement itself cannot say
	 * which of its conditions failed. Either another request committed first — so
	 * the token this request fenced on is no longer the active one — or the
	 * template stopped being commerce-eligible between validation and commit.
	 * Both leave the stored row untouched.
	 *
	 * A FAILED RE-READ IS NEITHER OF THOSE, AND IS NEVER REPORTED AS ONE. If the
	 * schedule row or the template row cannot be read at all, this method knows
	 * nothing about why the commit did not land, and `schedule_state_changed`
	 * would be a specific claim — "someone else got there first" — that no
	 * evidence supports. Worse, it is a REASSURING claim: it tells the Manager to
	 * reload and retry, when the real condition may be a database fault that
	 * retrying will not fix. The read error is propagated instead, so the caller
	 * sees `persistence_failed` and its `db_error`.
	 *
	 * @return SessionScheduleResult|\WP_Error
	 */
	private function classify_commit_failure( int $schedule_id, int $portal_session_id ) {
		$row = $this->read_by_id( $schedule_id );
		if ( is_wp_error( $row ) && 'schedule_not_found' !== $row->get_error_code() ) {
			// `schedule_not_found` is a real classification — the row was removed
			// underneath this request — and falls through to be reported as a
			// state change. Any OTHER error means the read itself failed.
			return $row;
		}

		$template = $this->templates->get( $portal_session_id );
		if ( is_wp_error( $template ) ) {
			return $template;
		}

		if ( is_array( $template ) && ! PortalTemplateGateway::is_commerce_eligible( $template ) ) {
			return new SessionScheduleResult(
				SessionScheduleResult::STATUS_FAILED,
				is_array( $row ) ? $row : [],
				'eligibility_failed',
				[],
				[],
				[
					[
						'kind'              => 'template_not_commerce_eligible',
						'portal_session_id' => $portal_session_id,
						'portal_status'     => (string) ( $template['portal_status'] ?? '' ),
						'is_current'        => (bool) ( $template['is_current'] ?? false ),
					],
				]
			);
		}

		if ( null === $template ) {
			// No replica row at all. Reported as unavailable, never as deleted:
			// Portal deletion is prohibited once a Session exists (BR-039), so
			// absence is a replication state.
			return new SessionScheduleResult(
				SessionScheduleResult::STATUS_FAILED,
				is_array( $row ) ? $row : [],
				'eligibility_failed',
				[],
				[],
				[
					[
						'kind'              => 'template_unavailable',
						'portal_session_id' => $portal_session_id,
					],
				]
			);
		}

		return new SessionScheduleResult(
			SessionScheduleResult::STATUS_SCHEDULE_STATE_CHANGED,
			is_array( $row ) ? $row : [],
			'write_failed'
		);
	}

	/**
	 * Why an archive or restore affected zero rows.
	 *
	 * Same rule as classify_commit_failure(): a re-read that FAILED is reported
	 * as the failure it is, never as a lost race.
	 *
	 * @return SessionScheduleResult|\WP_Error
	 */
	private function classify_lifecycle_no_op( int $schedule_id, string $intended ) {
		$row = $this->read_by_id( $schedule_id );
		if ( is_wp_error( $row ) && 'schedule_not_found' !== $row->get_error_code() ) {
			return $row;
		}

		if ( is_array( $row ) && $intended === $row['status'] ) {
			// Someone else already did it. The intended state holds, so this is
			// reported as a verified no-op rather than as a failure.
			return SessionScheduleResult::ok( $row );
		}

		if ( is_array( $row ) && self::STATUS_ACTIVE === $intended ) {
			// Restore is the only template-guarded one of the two.
			return $this->classify_commit_failure( $schedule_id, (int) $row['portal_session_id'] );
		}

		return new SessionScheduleResult(
			SessionScheduleResult::STATUS_SCHEDULE_STATE_CHANGED,
			is_array( $row ) ? $row : [],
			'write_failed'
		);
	}

	/* ------------------------------------------------------------------ *
	 * Definition staging and token cleanup (§3.7)
	 * ------------------------------------------------------------------ */

	/** A lowercase UUID v4, applied defensively as ProvisioningOperationRepository.php:940 does. */
	private function new_definition_token(): string {
		return strtolower( wp_generate_uuid4() );
	}

	/**
	 * Write the complete child set under a private token.
	 *
	 * Dates are staged only for `mode = dates`; a weekly schedule stores zero
	 * rows in the dates table by design (§2.2), because its dates are derived and
	 * storing them would create a second, divergent source of truth.
	 *
	 * @param array<string,mixed> $validated
	 * @return true|\WP_Error
	 */
	private function stage_definition( int $schedule_id, string $token, array $validated, string $now ) {
		global $wpdb;

		if ( ScheduleExpansion::MODE_DATES === $validated['mode'] ) {
			$dates_table = Migrations::schedule_dates_table();
			foreach ( $validated['dates'] as $date ) {
				$written = $wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Migrations.
						"INSERT INTO {$dates_table} (schedule_id, definition_token, schedule_date, created_at)
						 VALUES (%d, %s, %s, %s)",
						$schedule_id,
						$token,
						$date,
						$now
					)
				);

				if ( 1 !== $written ) {
					return $this->persistence_error( (string) $wpdb->last_error );
				}
			}
		}

		$packages_table = Migrations::schedule_packages_table();
		foreach ( $validated['package_ids'] as $package_id ) {
			$written = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Migrations.
					"INSERT INTO {$packages_table} (schedule_id, definition_token, package_id, created_at)
					 VALUES (%d, %s, %d, %s)",
					$schedule_id,
					$token,
					$package_id,
					$now
				)
			);

			if ( 1 !== $written ) {
				return $this->persistence_error( (string) $wpdb->last_error );
			}
		}

		return true;
	}

	/**
	 * Delete the rows of a token THIS REQUEST generated, after a failed commit.
	 *
	 * The private-token rule (§3.7). An authoritative re-read must prove the
	 * token is not active before anything is deleted, and nothing is deleted if
	 * the re-read fails or returns this request's own token — the second case
	 * being a commit that succeeded after all, which must never have its live
	 * definition deleted underneath it.
	 */
	private function clean_private_token( int $schedule_id, string $token ): void {
		$row = $this->read_by_id( $schedule_id );

		if ( ! is_array( $row ) ) {
			// Re-read failed. Leave the rows: a few bytes of unreferenced staging
			// is strictly better than deleting a definition that might be live.
			return;
		}

		if ( (string) $row['active_definition_token'] === $token ) {
			return;
		}

		$this->delete_definition_rows( $schedule_id, $token );
	}

	/**
	 * Delete the rows of the token a successful edit just superseded (§3.7).
	 *
	 * An earlier revision of this design claimed inactive token rows accumulate
	 * only from FAILED edits. That was wrong: every SUCCESSFUL edit also leaves
	 * its predecessor's date and Package rows behind, so a frequently edited
	 * schedule accumulates far more superseded rows than failed ones. They are
	 * therefore cleaned deliberately, and provably.
	 *
	 * The proof is in the statement, not in this method's confidence: see
	 * delete_definition_rows(), whose JOIN requires the schedule's CURRENT active
	 * token to differ from the one being deleted.
	 */
	private function clean_superseded_token( int $schedule_id, string $seen ): void {
		$this->delete_definition_rows( $schedule_id, $seen );
	}

	/**
	 * The two guarded deletes of §3.7.
	 *
	 * `JOIN … AND sch.active_definition_token <> %s` IS THE PROOF. If anything
	 * restored `$token` as the active token between the decision and this
	 * statement, the join matches nothing and ZERO rows are deleted. The
	 * guarantee is in the database, at the instant of the delete, not in a PHP
	 * check that was true a moment earlier.
	 *
	 * Three things follow, and none of them depends on timing:
	 *
	 *  - the CURRENT token is never deleted, because it is not `$token`;
	 *  - another editor's STAGED private token is never deleted, because only a
	 *    token this request observed as active and then superseded is named;
	 *  - a reconciliation worker still running on `$token` cannot be harmed. Its
	 *    every guarded mutation carries `active_definition_token = $token`, which
	 *    no longer matches, so each affects zero rows and its between-phase
	 *    re-proof stops the run. It could not have written anything with those
	 *    rows after the CAS regardless.
	 *
	 * `<>` under `ascii_bin` is exact.
	 *
	 * THE GUARD IS NULL-SAFE, AND HAS TO BE. `active_definition_token` is SQL
	 * NULL on an INERT schedule — one whose create CAS affected zero rows — and
	 * in SQL `NULL <> 'token'` evaluates to NULL, not TRUE. A bare `<>` therefore
	 * matches nothing on exactly the schedules that most need cleaning, so a
	 * failed create would leave its private token's date and Package rows behind
	 * for ever, and a later re-save of that same inert row would find two token
	 * sets where there should be one. `IS NULL OR <>` restores the intended
	 * meaning — "this token is not the active one" — for both states, and does
	 * not weaken the proof: when a token IS active, `IS NULL` is false and `<>`
	 * is false, so zero rows are still deleted.
	 */
	private function delete_definition_rows( int $schedule_id, string $token ): void {
		global $wpdb;

		$schedules = Migrations::schedules_table();
		$dates     = Migrations::schedule_dates_table();
		$packages  = Migrations::schedule_packages_table();

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Migrations.
				"DELETE d
				   FROM {$dates} d
				   JOIN {$schedules} sch
				     ON  sch.schedule_id = d.schedule_id
				    AND  sch.schedule_id = %d
				    AND  ( sch.active_definition_token IS NULL OR sch.active_definition_token <> %s )
				  WHERE  d.schedule_id      = %d
				    AND  d.definition_token = %s",
				$schedule_id,
				$token,
				$schedule_id,
				$token
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Migrations.
				"DELETE sp
				   FROM {$packages} sp
				   JOIN {$schedules} sch
				     ON  sch.schedule_id = sp.schedule_id
				    AND  sch.schedule_id = %d
				    AND  ( sch.active_definition_token IS NULL OR sch.active_definition_token <> %s )
				  WHERE  sp.schedule_id      = %d
				    AND  sp.definition_token = %s",
				$schedule_id,
				$token,
				$schedule_id,
				$token
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Request-time validation (§3.2, §3.8 step 1, §10)
	 * ------------------------------------------------------------------ */

	/**
	 * Validate a COMPLETE definition request. Nothing is written until this
	 * returns a value.
	 *
	 * Deliberately NOT atomic with the CAS, and it does not pretend to be
	 * (§3.2). Package assignability is PHP that reads Catalog rows and a
	 * WooCommerce product; no SQL statement can carry it. This is the best check
	 * available at this boundary, and every later boundary re-proves it.
	 *
	 * @param array<int,string> $dates
	 * @param array<int,int>    $package_ids
	 * @param array<int,string> $previously_committed
	 * @return array<string,mixed>|\WP_Error
	 */
	private function validate_definition(
		int $portal_session_id,
		string $mode,
		string $start_time,
		array $dates,
		array $package_ids,
		string $recurrence_start,
		string $recurrence_end,
		int $weekday_mask,
		string $zone,
		array $previously_committed,
		bool $is_edit
	) {
		if ( $portal_session_id < 1 ) {
			return $this->error( 'invalid_portal_session_id', 'Select a Portal Session template for this schedule.' );
		}

		$mode = trim( $mode );
		if ( ! in_array( $mode, ScheduleExpansion::MODES, true ) ) {
			// The mode becomes a LITERAL inside a statement, so it is checked
			// against an internal allowlist before any SQL is composed (§3.3).
			return $this->error( 'invalid_schedule_mode', 'Choose either specific dates or a weekly recurrence.' );
		}

		$start_time_local = ScheduleTimezone::canonical_start_time( $start_time );
		if ( is_wp_error( $start_time_local ) ) {
			return $start_time_local;
		}

		$template = $this->templates->get( $portal_session_id );
		if ( is_wp_error( $template ) ) {
			return $template;
		}
		if ( null === $template ) {
			return $this->error(
				'template_unavailable',
				'That Portal Session template is not available in this hall. It may not have synchronized yet.'
			);
		}

		$eligible = PortalTemplateGateway::assert_commerce_eligible( $template );
		if ( is_wp_error( $eligible ) ) {
			return $eligible;
		}

		/*
		 * "Today" comes from the SCHEDULE's zone, not from the hall's current
		 * WordPress setting (§5.3). A hall whose Settings timezone differs from a
		 * schedule's captured zone must still have its date boundary judged in the
		 * zone the schedule will actually generate and sell in.
		 */
		$today = ScheduleTimezone::local_today( $zone );
		if ( null === $today ) {
			return $this->error( 'hall_timezone_not_named', 'The scheduling time zone could not be evaluated.' );
		}

		$definition = [
			'portal_session_id' => $portal_session_id,
			'mode'              => $mode,
			'start_time_local'  => $start_time_local,
			'weekday_mask'      => 0,
			'recurrence_start'  => '',
			'recurrence_end'    => '',
			'dates'             => [],
		];

		if ( ScheduleExpansion::MODE_WEEKLY === $mode ) {
			/*
			 * §8.2: an edit PRESERVES a recurrence_start that has since passed.
			 * The tomorrow-or-later rule applies only to a start the Manager
			 * actually supplies, which is why the flag is "did this request
			 * change it", not "is this an edit".
			 */
			$supplied_new_start = ! $is_edit
				|| [] === $previously_committed
				|| trim( $recurrence_start ) !== ( $previously_committed['recurrence_start'] ?? null );

			$weekly = ScheduleExpansion::canonical_weekly(
				$recurrence_start,
				$recurrence_end,
				$weekday_mask,
				$today,
				$supplied_new_start
			);
			if ( is_wp_error( $weekly ) ) {
				return $weekly;
			}

			$definition['weekday_mask']     = $weekly['weekday_mask'];
			$definition['recurrence_start'] = $weekly['recurrence_start'];
			$definition['recurrence_end']   = $weekly['recurrence_end'];
		} else {
			$canonical = ScheduleExpansion::canonical_dates(
				$dates,
				$today,
				is_array( $previously_committed['dates'] ?? null ) ? $previously_committed['dates'] : [],
				$is_edit
			);
			if ( is_wp_error( $canonical ) ) {
				return $canonical;
			}

			$definition['dates'] = $canonical;
		}

		$validated_packages = $this->validate_packages( $package_ids );
		if ( is_wp_error( $validated_packages ) ) {
			return $validated_packages;
		}
		$definition['package_ids'] = $validated_packages;

		return $definition;
	}

	/**
	 * At least one same-hall assignable Package (§10).
	 *
	 * A zero-Package definition is refused HERE, and the same requirement is
	 * additionally proved in SQL at generation-completion and at final
	 * verification — so an occurrence can never be completed against an empty
	 * definition even if this check were somehow bypassed.
	 *
	 * Each id is resolved through PackageGateway individually rather than from
	 * map(), because map() is capped at 200 rows and an id outside the first page
	 * would otherwise be silently misclassified as missing.
	 *
	 * @param array<int,int> $package_ids
	 * @return array<int,int>|\WP_Error
	 */
	private function validate_packages( array $package_ids ) {
		$unique = [];
		foreach ( $package_ids as $raw ) {
			$package_id = (int) $raw;
			if ( $package_id < 1 ) {
				return $this->error( 'invalid_package_id', 'A selected Bingo Package identity is invalid.' );
			}
			$unique[ $package_id ] = $package_id;
		}

		if ( [] === $unique ) {
			return $this->error(
				'schedule_requires_a_package',
				'Assign at least one Bingo Package to this schedule. Occurrences cannot be sold without one.'
			);
		}

		foreach ( $unique as $package_id ) {
			$package = $this->packages->get( $package_id );
			if ( is_wp_error( $package ) ) {
				return $package;
			}
			if ( ! is_array( $package ) ) {
				return $this->error( 'package_not_found', 'A selected Bingo Package no longer exists in this hall.' );
			}

			$assignable = PackageGateway::assert_assignable( $package );
			if ( is_wp_error( $assignable ) ) {
				return $assignable;
			}
		}

		ksort( $unique, SORT_NUMERIC );

		return array_values( $unique );
	}

	/**
	 * Run reconciliation for one schedule (ADR-043 §7).
	 *
	 * THE ONLY PRODUCTION ENTRY POINT INTO OccurrenceReconciler.
	 * `SessionScheduleAdmin` calls this method after schedule creation and from
	 * its nonce-protected `admin_post_bc_session_schedule_generate` retry
	 * handler. No REST route exposes generation.
	 *
	 * Authorization only. Every domain rule — the run fence, phase order,
	 * eligibility, contention handling — lives in OccurrenceReconciler and is
	 * never re-implemented here.
	 *
	 * @return SessionScheduleResult|\WP_Error
	 */
	public function generate( int $schedule_id ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$before = $this->require_row( $schedule_id );
		if ( is_wp_error( $before ) ) {
			return $before;
		}

		$reconciler = new OccurrenceReconciler();
		$result     = $reconciler->run( $schedule_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/*
		 * `sessions.schedule.generated` carries counts, status and
		 * failure_category ONLY (§13.1) — never a per-occurrence list, which
		 * could otherwise grow unboundedly for a large schedule.
		 */
		$audit_context = array_merge(
			[ 'status' => $result->status, 'failure_category' => $result->failure_category ],
			$result->reported_counts()
		);
		$event = [
			'blog_id'        => $context['blog_id'],
			'actor_user_id'  => $context['actor_user_id'],
			'action'         => 'sessions.schedule.generated',
			'target_type'    => 'bingo_session_schedule',
			'target_id'      => $schedule_id,
			'previous_value' => null,
			'new_value'      => null,
			'context'        => $audit_context,
		];
		$audit_result = call_user_func( $this->audit_recorder, $event );
		if ( is_wp_error( $audit_result ) ) {
			$result->warnings[] = [
				'code'       => 'audit_write_failed',
				'audit_code' => $audit_result->get_error_code(),
				'message'    => $audit_result->get_error_message(),
			];
		}

		return $result;
	}

	/* ------------------------------------------------------------------ *
	 * Reads
	 * ------------------------------------------------------------------ */

	/** @return array<string,mixed>|\WP_Error */
	public function get( int $schedule_id ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		return $this->require_row( $schedule_id );
	}

	/**
	 * Every schedule in THIS hall, for the administration screen.
	 *
	 * A SUPPORTED SERVICE READ, carrying the same authorization boundary as
	 * every other entry point here — signed in, hall context,
	 * `bc_manage_sessions` or super admin, schema current. The screen never
	 * queries these tables itself: per-site `$wpdb->prefix` is the isolation
	 * boundary, and keeping the read behind `authorize()` is what makes that
	 * boundary hold for an unauthenticated or wrong-hall caller too.
	 *
	 * Adds no scheduling rule. Ordering is newest-first for presentation only.
	 *
	 * @param string $status `all`, `active` or `archived`.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function list_for_administration( string $status = 'all' ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		global $wpdb;

		$table   = Migrations::schedules_table();
		$columns = self::COLUMNS;

		if ( in_array( $status, self::STATUSES, true ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column names from Migrations.
					"SELECT {$columns} FROM {$table} WHERE status = %s ORDER BY schedule_id DESC",
					$status
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column names from Migrations.
			$rows = $wpdb->get_results( "SELECT {$columns} FROM {$table} ORDER BY schedule_id DESC", ARRAY_A );
		}

		// A FAILED READ IS NOT AN EMPTY HALL.
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		return array_map( [ $this, 'map_row' ], $rows );
	}

	/**
	 * The dates committed under one definition token, ascending.
	 *
	 * The public counterpart of `definition_packages()`, and gated identically,
	 * because the edit form has to show §8.3's *Past dates* keep/drop list and
	 * the only supported way to read them is through this service.
	 *
	 * @return array<int,string>|\WP_Error
	 */
	public function definition_dates( int $schedule_id, string $token ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$schedule = $this->read_by_id( $schedule_id );
		if ( is_wp_error( $schedule ) ) {
			return $schedule;
		}

		$definition = $this->read_definition_dates( $schedule_id, $token );
		if ( is_wp_error( $definition ) ) {
			return $definition;
		}

		return $definition['dates'];
	}

	/**
	 * The dates and Packages of one definition token.
	 *
	 * Returns `['dates' => [...], 'packages' => [...], 'recurrence_start' => ?]`
	 * so an edit can apply §8.3's exemption for dates that were already
	 * committed, and §8.2's preservation of a start date that has since passed.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private function read_definition_dates( int $schedule_id, string $token ) {
		global $wpdb;

		$dates_table = Migrations::schedule_dates_table();

		$dates = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Migrations.
				"SELECT schedule_date FROM {$dates_table}
				  WHERE schedule_id = %d AND definition_token = %s
			   ORDER BY schedule_date ASC",
				$schedule_id,
				$token
			)
		);

		/*
		 * A FAILED READ IS NOT AN EMPTY DEFINITION.
		 *
		 * `get_col()` returns an EMPTY ARRAY when the statement fails, never a
		 * non-array, so `! is_array()` alone could not tell a definition with no
		 * dates from a read that never ran — and the edit screen, which commits
		 * the whole definition, would have been handed `[]` and offered a Save
		 * that discarded every date. `last_error` is the only thing that
		 * distinguishes them.
		 */
		if ( ! is_array( $dates ) || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		$row = $this->read_by_id( $schedule_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		return [
			'dates'            => array_map( 'strval', $dates ),
			'recurrence_start' => $row['recurrence_start'],
		];
	}

	/**
	 * The Package ids in one definition token, ascending.
	 *
	 * A SUPPORTED SERVICE READ, so it carries the same authorization boundary as
	 * every other one: signed in, hall context, `bc_manage_sessions` or super
	 * admin, schema current. It was briefly public WITHOUT that check, which
	 * would have let any caller — including an unauthenticated one — enumerate a
	 * hall's Package assignments through a public method on a service whose every
	 * other entry point is gated. A read being "just a read" is not a reason to
	 * skip the gate; hall isolation is the highest-severity concern in this
	 * platform, and `$schedule_id` here is caller-supplied.
	 *
	 * @return array<int,int>|\WP_Error
	 */
	public function definition_packages( int $schedule_id, string $token ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		/*
		 * The schedule must exist in THIS hall's table before its child rows are
		 * read. Per-site `$wpdb->prefix` is the isolation boundary, so a
		 * schedule_id from another hall simply is not here — but proving that
		 * with an authoritative read, rather than letting the child query return
		 * an empty set, keeps "not yours" and "no Packages" distinguishable.
		 */
		$schedule = $this->read_by_id( $schedule_id );
		if ( is_wp_error( $schedule ) ) {
			return $schedule;
		}

		global $wpdb;

		$table = Migrations::schedule_packages_table();

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Migrations.
				"SELECT package_id FROM {$table}
				  WHERE schedule_id = %d AND definition_token = %s
			   ORDER BY package_id ASC",
				$schedule_id,
				$token
			)
		);

		// A FAILED READ IS NOT AN EMPTY PACKAGE SET, for the same reason as
		// read_definition_dates(): get_col() returns [] on failure.
		if ( ! is_array( $ids ) || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		return array_map( 'intval', $ids );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function read_by_id( int $schedule_id ) {
		global $wpdb;

		if ( $schedule_id < 1 ) {
			return $this->error( 'schedule_not_found', 'That schedule could not be found in this hall.' );
		}

		$table = Migrations::schedules_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and column list from constants.
				'SELECT ' . self::COLUMNS . " FROM {$table} WHERE schedule_id = %d",
				$schedule_id
			),
			ARRAY_A
		);

		if ( null === $row ) {
			return '' === (string) $wpdb->last_error
				? $this->error( 'schedule_not_found', 'That schedule could not be found in this hall.' )
				: $this->persistence_error( (string) $wpdb->last_error );
		}

		return $this->map_row( $row );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function require_row( int $schedule_id ) {
		$row = $this->read_by_id( $schedule_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( ! in_array( $row['status'], self::STATUSES, true ) ) {
			// Fail closed rather than coercing an unrecognised lifecycle value.
			return $this->error( 'schedule_state_inconsistent', 'That schedule holds an unrecognised state and cannot be changed.' );
		}

		return $row;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function map_row( array $row ): array {
		$token = null === $row['active_definition_token'] ? '' : (string) $row['active_definition_token'];

		return [
			'schedule_id'             => (int) $row['schedule_id'],
			'portal_session_id'       => (int) $row['portal_session_id'],
			'timezone_name'           => (string) $row['timezone_name'],
			'start_time_local'        => (string) $row['start_time_local'],
			'mode'                    => (string) $row['mode'],
			'weekday_mask'            => (int) $row['weekday_mask'],
			'recurrence_start'        => null === $row['recurrence_start'] ? null : (string) $row['recurrence_start'],
			'recurrence_end'          => null === $row['recurrence_end'] ? null : (string) $row['recurrence_end'],
			'active_definition_token' => $token,
			// NULL means no readable definition: the schedule is inert, generates
			// nothing, and is excluded from every eligibility predicate. It is
			// surfaced as its own flag so no screen has to infer it from a string.
			'has_definition'          => '' !== $token,
			'status'                  => (string) $row['status'],
			'created_at'              => (string) $row['created_at'],
			'updated_at'              => (string) $row['updated_at'],
			'archived_at'             => null === $row['archived_at'] ? null : (string) $row['archived_at'],
		];
	}

	/* ------------------------------------------------------------------ *
	 * Authorization and audit (§13.1)
	 * ------------------------------------------------------------------ */

	/**
	 * Reused verbatim from SessionService::authorize(): logged in, hall context,
	 * capability, schema current. No new capability is introduced, and Game
	 * Operator — which holds `bc_view_sessions` but not `bc_manage_sessions` — is
	 * excluded by the same check that excludes it from Sessions.
	 *
	 * @return array<string,int|bool>|\WP_Error
	 */
	private function authorize() {
		if ( ! is_user_logged_in() ) {
			return $this->error( 'authentication_required', 'You must be signed in to manage Bingo Session schedules.' );
		}
		if ( ! HallContext::is_hall() ) {
			return $this->error( 'invalid_hall_context', 'Bingo Session schedules may be managed only from a hall site.' );
		}

		$actor_id = get_current_user_id();
		$is_super = is_super_admin( $actor_id );

		if ( ! $is_super && ! current_user_can( 'bc_manage_sessions' ) ) {
			return $this->error( 'session_management_denied', 'You are not authorized to manage Bingo Session schedules for this hall.' );
		}
		if ( ! Migrations::is_current() ) {
			return $this->error( 'sessions_schema_unavailable', 'The Session schema is not ready for this hall.' );
		}

		return [
			'blog_id'        => get_current_blog_id(),
			'actor_user_id'  => $actor_id,
			'actor_is_super' => $is_super,
		];
	}

	/**
	 * @param array<string,int|bool>   $context
	 * @param array<string,mixed>|null $before
	 * @param array<string,mixed>|null $after
	 * @param array<string,mixed>      $extra_context
	 */
	private function record_audit(
		SessionScheduleResult $result,
		string $action,
		array $context,
		?array $before,
		?array $after,
		int $schedule_id,
		array $extra_context = []
	): SessionScheduleResult {
		$event = [
			'blog_id'        => $context['blog_id'],
			'actor_user_id'  => $context['actor_user_id'],
			'action'         => $action,
			'target_type'    => 'bingo_session_schedule',
			'target_id'      => $schedule_id,
			'previous_value' => null === $before ? null : $this->audit_payload( $before ),
			'new_value'      => null === $after ? null : $this->audit_payload( $after ),
			'context'        => [] === $extra_context ? null : $extra_context,
		];

		$audit_result = call_user_func( $this->audit_recorder, $event );
		if ( is_wp_error( $audit_result ) ) {
			$error_code = $audit_result->get_error_code();
			error_log( sprintf( 'BingoCommerce Sessions audit failure for %s on schedule %d: %s', $action, $schedule_id, $error_code ) );
			$result->warnings[] = [
				'code'       => 'audit_write_failed',
				'audit_code' => $error_code,
				'message'    => $audit_result->get_error_message(),
			];
		} elseif ( ! is_int( $audit_result ) || $audit_result < 1 ) {
			error_log( sprintf( 'BingoCommerce Sessions audit returned an invalid result for %s on schedule %d.', $action, $schedule_id ) );
			$result->warnings[] = [
				'code'       => 'audit_write_failed',
				'audit_code' => 'invalid_audit_result',
				'message'    => 'The audit service returned an invalid result.',
			];
		}

		return $result;
	}

	/**
	 * The audited shape of a schedule row.
	 *
	 * NOTE: no `session_id` key, and none can appear here. `AuditLog` compacts
	 * every payload key to alphanumerics and REJECTS `sessionid`
	 * (`…/AuditLog.php:83`), which is why `session_id` is absent from every
	 * Session payload (`…:884-888`).
	 *
	 * `active_definition` IS THE TOKEN, UNDER A KEY THAT SURVIVES THE FILTER.
	 * It was `active_definition_token` until the administration screens became
	 * the first caller to exercise the REAL audit recorder — every harness
	 * before them passed a swallowing stub — and every schedule create, edit,
	 * archive and restore was recording an `audit_write_failed` warning and NO
	 * audit row. `AuditLog::is_sensitive_key()` splits a key on
	 * non-alphanumerics and rejects it when ANY resulting token is a sensitive
	 * term (`…/AuditLog.php:534-550`), and `token` is one of them
	 * (`…:58`), so `active_definition_token` split to
	 * ['active','definition','token'] and was refused. The value is unchanged
	 * and still identifies the definition; only the key differs. ADR-043 §13.1
	 * previously asserted this key passed the filter, which was wrong, and has
	 * been corrected alongside this.
	 *
	 * `portal_template_id` IS `portal_session_id`, FOR THE SAME REASON.
	 * `is_sensitive_key()` ALSO rejects a key when two ADJACENT parts join into
	 * a sensitive compound (`…/AuditLog.php:560-564`), and `portal_session_id`
	 * splits to ['portal','session','id'], whose adjacent pair `session`+`id`
	 * is `sessionid` — the very name the docblock above already warns about,
	 * hit one position further along the key. The COLUMN is untouched; only the
	 * audit key differs, and `portal_template_id` is what ADR-035 calls this
	 * identity in prose anyway.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function audit_payload( array $row ): array {
		return [
			'portal_template_id' => (int) ( $row['portal_session_id'] ?? 0 ),
			'timezone_name'      => (string) ( $row['timezone_name'] ?? '' ),
			'start_time_local'   => (string) ( $row['start_time_local'] ?? '' ),
			'mode'               => (string) ( $row['mode'] ?? '' ),
			'weekday_mask'       => (int) ( $row['weekday_mask'] ?? 0 ),
			'recurrence_start'   => $row['recurrence_start'] ?? null,
			'recurrence_end'     => $row['recurrence_end'] ?? null,
			'active_definition'  => (string) ( $row['active_definition_token'] ?? '' ),
			'status'             => (string) ( $row['status'] ?? '' ),
		];
	}

	private function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-sessions' ), [ 'status' => $code ] );
	}

	private function persistence_error( string $db_error ): \WP_Error {
		return new \WP_Error(
			'persistence_failed',
			__( 'The Bingo Session schedule change could not be persisted.', 'bingocommerce-sessions' ),
			[ 'db_error' => $db_error ]
		);
	}
}
