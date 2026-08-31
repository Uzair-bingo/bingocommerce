<?php

namespace BingoCommerce\Sessions;

use BingoCommerce\Core\AuditLog;
use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authoritative current-hall Bingo Session domain service (ADR-026, ADR-031).
 *
 * `bingo_sessions` and `bingo_session_packages` are the authority for Session
 * identity, schedule, availability and Package assignment. Catalog state is
 * consumed only through PackageGateway's supported PackageService reads.
 *
 * NO custom transaction transport is used. There is no cross-row invariant here
 * of the kind BR-061 creates for Packages: availability and lifecycle are
 * single-row, and an assignment is one row protected by a real UNIQUE
 * constraint. Ordinary prepared statements plus database constraints and
 * guarded conditional writes are sufficient, so ADR-025/032/033 semantics are
 * deliberately NOT imported.
 *
 * BR-050/BR-063: `session_date` is the only structured schedule value. Time
 * exists only as human text inside `display_name` and is NEVER parsed.
 */
final class SessionService {

	public const STATUS_ACTIVE   = 'active';
	public const STATUS_ARCHIVED = 'archived';

	private const STATUSES = [ self::STATUS_ACTIVE, self::STATUS_ARCHIVED ];

	/** BR-051/BR-063: the sales window opens this many calendar days before the Session date. */
	private const SALES_WINDOW_DAYS = 5;

	private const DISPLAY_NAME_MAX = 191;

	private const COLUMNS = 'session_id, session_date, display_name, available_for_sale, status, created_at, updated_at, archived_at';

	/** @var callable */
	private $audit_recorder;

	private PackageGateway $packages;

	public function __construct( ?callable $audit_recorder = null, ?PackageGateway $packages = null ) {
		$this->audit_recorder = $audit_recorder ?? [ AuditLog::class, 'record' ];
		$this->packages       = $packages ?? new PackageGateway();
	}

	/* ------------------------------------------------------------------ *
	 * Writers
	 * ------------------------------------------------------------------ */

	/** @return SessionResult|\WP_Error */
	public function create( string $session_date, string $display_name, bool $available_for_sale = false ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$date = $this->canonical_date( $session_date );
		if ( is_wp_error( $date ) ) {
			return $date;
		}

		$name = $this->canonical_display_name( $display_name );
		if ( is_wp_error( $name ) ) {
			return $name;
		}

		global $wpdb;
		$now = current_time( 'mysql', true );

		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . Migrations::sessions_table()
				. ' (session_date, display_name, available_for_sale, status, created_at, updated_at, archived_at)'
				. " VALUES (%s, %s, %d, 'active', %s, %s, NULL)",
				$date,
				$name,
				$available_for_sale ? 1 : 0,
				$now,
				$now
			)
		);

		if ( false === $inserted || '' !== (string) $wpdb->last_error ) {
			// UNIQUE (session_date, display_name) is authoritative. Rather than
			// parsing the driver message, re-read: if that exact pair now exists,
			// this was a duplicate.
			$existing = $this->find_by_date_and_name( $date, $name );
			if ( is_array( $existing ) ) {
				return $this->error( 'duplicate_session', 'A Session with that date and display name already exists in this hall.' );
			}

			return $this->persistence_error( (string) $wpdb->last_error );
		}

		$session_id = (int) $wpdb->insert_id;
		if ( $session_id < 1 ) {
			return $this->persistence_error( 'The Session could not be created.' );
		}

		return $this->settle( $session_id, 'session_created', 'sessions.session.created', $context, null );
	}

	/** @return SessionResult|\WP_Error */
	public function rename( int $session_id, string $display_name ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$before = $this->require_active_row( $session_id );
		if ( is_wp_error( $before ) ) {
			return $before;
		}

		$name = $this->canonical_display_name( $display_name );
		if ( is_wp_error( $name ) ) {
			return $name;
		}

		if ( $name === $before['display_name'] ) {
			return new SessionResult( 'session_name_unchanged', $before );
		}

		return $this->apply_field_change( $session_id, $before, [ 'display_name' => $name ], 'session_renamed', 'sessions.session.renamed', $context );
	}

	/** @return SessionResult|\WP_Error */
	public function reschedule( int $session_id, string $session_date ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$before = $this->require_active_row( $session_id );
		if ( is_wp_error( $before ) ) {
			return $before;
		}

		$date = $this->canonical_date( $session_date );
		if ( is_wp_error( $date ) ) {
			return $date;
		}

		if ( $date === $before['session_date'] ) {
			return new SessionResult( 'session_date_unchanged', $before );
		}

		return $this->apply_field_change( $session_id, $before, [ 'session_date' => $date ], 'session_rescheduled', 'sessions.session.rescheduled', $context );
	}

	/** @return SessionResult|\WP_Error */
	public function setAvailableForSale( int $session_id, bool $available ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$before = $this->require_active_row( $session_id );
		if ( is_wp_error( $before ) ) {
			return $before;
		}

		if ( $available === (bool) $before['available_for_sale'] ) {
			return new SessionResult( 'session_availability_unchanged', $before );
		}

		return $this->apply_field_change(
			$session_id,
			$before,
			[ 'available_for_sale' => $available ? 1 : 0 ],
			'session_availability_changed',
			'sessions.session.availability_changed',
			$context
		);
	}

	/**
	 * Apply date, display name and availability as ONE mutation.
	 *
	 * The combined admin form uses this rather than chaining rename() +
	 * reschedule() + setAvailableForSale(), because a failure partway through
	 * that chain would leave an earlier field committed while the screen
	 * reported the Save as failed. Every value is validated and canonicalised
	 * BEFORE any DML, and a single conditional UPDATE writes all three, so a
	 * partial save is not expressible.
	 *
	 * No custom transaction is involved: this is one ordinary prepared statement
	 * whose guard lives in its WHERE clause.
	 *
	 * @return SessionResult|\WP_Error
	 */
	public function updateDetails( int $session_id, string $session_date, string $display_name, bool $available_for_sale ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$before = $this->require_active_row( $session_id );
		if ( is_wp_error( $before ) ) {
			return $before;
		}

		// ALL validation before ANY write.
		$date = $this->canonical_date( $session_date );
		if ( is_wp_error( $date ) ) {
			return $date;
		}

		$name = $this->canonical_display_name( $display_name );
		if ( is_wp_error( $name ) ) {
			return $name;
		}

		if (
			$date === $before['session_date']
			&& $name === $before['display_name']
			&& $available_for_sale === (bool) $before['available_for_sale']
		) {
			// Exact no-op: nothing is written and nothing is audited.
			return new SessionResult( 'session_unchanged', $before );
		}

		global $wpdb;
		$table = Migrations::sessions_table();
		$now   = current_time( 'mysql', true );

		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET session_date = %s, display_name = %s, available_for_sale = %d, updated_at = %s"
				. " WHERE session_id = %d AND status = 'active'",
				$date,
				$name,
				$available_for_sale ? 1 : 0,
				$now,
				$session_id
			)
		);

		if ( false === $affected || '' !== (string) $wpdb->last_error ) {
			// UNIQUE (session_date, display_name) is authoritative. Decide by
			// authoritative re-read, never by parsing the driver message.
			$clash = $this->find_by_date_and_name( $date, $name );
			if ( is_array( $clash ) && (int) $clash['session_id'] !== $session_id ) {
				return $this->error( 'duplicate_session', 'A Session with that date and display name already exists in this hall.' );
			}

			return $this->persistence_error( (string) $wpdb->last_error );
		}

		if ( 0 === (int) $affected ) {
			$current = $this->read_by_id( $session_id );
			if ( is_wp_error( $current ) ) {
				return $current;
			}

			if ( ! in_array( $current['status'], self::STATUSES, true ) ) {
				return $this->error( 'session_state_inconsistent', 'The Session holds an unrecognised lifecycle value. Contact Platform Admin.' );
			}
			if ( self::STATUS_ACTIVE !== $current['status'] ) {
				return $this->error( 'session_not_active', 'The Session is archived, so it cannot be edited. Restore it first.' );
			}
			if (
				$date === $current['session_date']
				&& $name === $current['display_name']
				&& $available_for_sale === (bool) $current['available_for_sale']
			) {
				return new SessionResult( 'session_unchanged', $current );
			}

			return $this->error( 'session_state_changed', 'The Session changed while it was being updated. Reload and try again.' );
		}

		return $this->settle( $session_id, 'session_updated', 'sessions.session.updated', $context, $before );
	}

	/** @return SessionResult|\WP_Error */
	public function archive( int $session_id ) {
		return $this->transition( $session_id, self::STATUS_ARCHIVED, 'session_archived', 'session_already_archived', 'sessions.session.archived' );
	}

	/** @return SessionResult|\WP_Error */
	public function restore( int $session_id ) {
		return $this->transition( $session_id, self::STATUS_ACTIVE, 'session_restored', 'session_already_active', 'sessions.session.restored' );
	}

	/**
	 * Assign a Bingo Package to a Session.
	 *
	 * Two independent guards, neither of which is a check-then-insert:
	 *
	 *   - the INSERT ... SELECT ... WHERE EXISTS shape means the row can only be
	 *     written while the Session row is genuinely `active` at statement time,
	 *     so an attempt that observes an archived Session can never create one;
	 *   - UNIQUE (session_id, package_id) is the authoritative duplicate guard,
	 *     resolved by an authoritative re-read rather than by parsing last_error.
	 *
	 * A Package archived between the eligibility read and the INSERT is a VALID
	 * final state: Package archive preserves relationships, so it linearizes as
	 * assignment-then-archive. No cross-plugin transaction or Catalog lock is
	 * taken to prevent it.
	 *
	 * @return SessionResult|\WP_Error
	 */
	public function assignPackage( int $session_id, int $package_id ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$session = $this->require_active_row( $session_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		if ( $package_id < 1 ) {
			return $this->error( 'invalid_package_id', 'The Bingo Package ID is invalid.' );
		}

		// Hall-local by construction: PackageService reads the current hall's own
		// tables, so a foreign Package id simply does not resolve.
		$package = $this->packages->get( $package_id );
		if ( is_wp_error( $package ) ) {
			return $package;
		}

		$assignable = PackageGateway::assert_assignable( $package );
		if ( is_wp_error( $assignable ) ) {
			return $assignable;
		}

		global $wpdb;
		$now       = current_time( 'mysql', true );
		$sessions  = Migrations::sessions_table();
		$relations = Migrations::session_packages_table();

		$written = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$relations} (session_id, package_id, created_at)"
				. ' SELECT %d, %d, %s FROM DUAL'
				. " WHERE EXISTS ( SELECT 1 FROM {$sessions} WHERE session_id = %d AND status = 'active' )",
				$session_id,
				$package_id,
				$now,
				$session_id
			)
		);

		if ( false === $written || '' !== (string) $wpdb->last_error ) {
			$failure = (string) $wpdb->last_error;

			// Authoritative re-read decides, never the driver message.
			$exists = $this->relation_exists( $session_id, $package_id );
			if ( is_wp_error( $exists ) ) {
				return $exists;
			}
			if ( $exists ) {
				return $this->settle_no_op( $session_id, 'session_package_already_assigned' );
			}

			return $this->persistence_error( $failure );
		}

		if ( 0 === (int) $written ) {
			// The guard did not match. Either the Session stopped being active, or
			// the row already existed. Re-read to report precisely.
			$exists = $this->relation_exists( $session_id, $package_id );
			if ( is_wp_error( $exists ) ) {
				return $exists;
			}
			if ( $exists ) {
				return $this->settle_no_op( $session_id, 'session_package_already_assigned' );
			}

			return $this->error( 'session_not_active', 'The Session is archived, so its Bingo Packages cannot be changed.' );
		}

		return $this->settle(
			$session_id,
			'session_package_assigned',
			'sessions.session.package_assigned',
			$context,
			$session,
			[ 'package_id' => $package_id, 'session_package_id' => (int) $wpdb->insert_id ]
		);
	}

	/** @return SessionResult|\WP_Error */
	public function removePackage( int $session_id, int $package_id ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$session = $this->require_active_row( $session_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		if ( $package_id < 1 ) {
			return $this->error( 'invalid_package_id', 'The Bingo Package ID is invalid.' );
		}

		global $wpdb;
		$sessions  = Migrations::sessions_table();
		$relations = Migrations::session_packages_table();

		$removed = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$relations}"
				. ' WHERE session_id = %d AND package_id = %d'
				. " AND EXISTS ( SELECT 1 FROM {$sessions} WHERE session_id = %d AND status = 'active' )",
				$session_id,
				$package_id,
				$session_id
			)
		);

		if ( false === $removed || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		if ( 0 === (int) $removed ) {
			$active = $this->is_active( $session_id );
			if ( is_wp_error( $active ) ) {
				return $active;
			}
			if ( ! $active ) {
				return $this->error( 'session_not_active', 'The Session is archived, so its Bingo Packages cannot be changed.' );
			}

			return $this->settle_no_op( $session_id, 'session_package_not_assigned' );
		}

		return $this->settle(
			$session_id,
			'session_package_removed',
			'sessions.session.package_removed',
			$context,
			$session,
			[ 'package_id' => $package_id ]
		);
	}

	/* ------------------------------------------------------------------ *
	 * Reads
	 * ------------------------------------------------------------------ */

	/** @return array<string,mixed>|\WP_Error */
	public function getById( int $session_id ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		return $this->read_by_id( $session_id );
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function listForAdministration( string $status = 'all', int $page = 1, int $per_page = 50 ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		global $wpdb;
		$table    = Migrations::sessions_table();
		$page     = max( 1, $page );
		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		if ( in_array( $status, self::STATUSES, true ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT ' . self::COLUMNS . " FROM {$table} WHERE status = %s ORDER BY session_date DESC, session_id DESC LIMIT %d OFFSET %d",
					$status,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT ' . self::COLUMNS . " FROM {$table} ORDER BY session_date DESC, session_id DESC LIMIT %d OFFSET %d",
					$per_page,
					$offset
				),
				ARRAY_A
			);
		}

		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		$sessions = [];
		foreach ( $rows as $row ) {
			$decorated = $this->decorate( $this->map_row( $row ) );
			if ( is_wp_error( $decorated ) ) {
				// Propagate. A partial listing is indistinguishable from a hall
				// that simply has fewer Sessions.
				return $decorated;
			}
			$sessions[] = $decorated;
		}

		return $sessions;
	}

	/**
	 * Bingo Packages this hall may NEWLY assign to a Session.
	 *
	 * Convenience for administration. Correctness is enforced in assignPackage().
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function assignablePackages( int $session_id = 0 ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$map = $this->packages->map();
		if ( is_wp_error( $map ) ) {
			return $map;
		}

		$assigned = [];
		if ( $session_id > 0 ) {
			$assigned = $this->assigned_package_ids( $session_id );
			if ( is_wp_error( $assigned ) ) {
				// A failed read is NOT "nothing is assigned". Offering an already
				// assigned Package as available would be a confident wrong answer.
				return $assigned;
			}
		}

		$assignable = [];
		foreach ( $map as $package_id => $package ) {
			if ( in_array( (int) $package_id, $assigned, true ) ) {
				continue;
			}
			if ( ! PackageGateway::is_assignable( $package ) ) {
				continue;
			}
			$assignable[] = $package;
		}

		return $assignable;
	}

	/**
	 * BR-051 / BR-063, evaluated read-only.
	 *
	 *     status = active
	 *     AND available_for_sale = ON
	 *     AND D - 5 <= hall_local_today <= D
	 *
	 * `hall_local_today` uses the HALL SITE's configured WordPress timezone via
	 * wp_timezone(), never the server timezone. No time is parsed from
	 * display_name (BR-050).
	 *
	 * This increment only reports the result. It changes no storefront,
	 * purchasability, cart, capacity or entitlement behaviour.
	 *
	 * @return bool|\WP_Error
	 */
	public function isAvailableForSaleToday( int $session_id ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$session = $this->read_by_id( $session_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		return (bool) $session['available_for_sale_today'];
	}

	/**
	 * Parse a calendar date, requiring an EXACT round-trip.
	 *
	 * Anything that normalises to a different date — 2030-02-30, 0000-00-00,
	 * 2030-13-01 — returns null rather than a silently shifted date.
	 */
	private static function parse_exact_date( string $value ): ?\DateTimeImmutable {
		// The SAME domain validity canonical_date() applies at write time, so a
		// value that could never have been written is never honoured on read.
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) ) {
			return null;
		}

		// checkdate() rejects year 0, month 13, and 30 February.
		if ( ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
			return null;
		}

		$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
		if ( ! $parsed instanceof \DateTimeImmutable ) {
			return null;
		}

		$parsed = $parsed->setTime( 0, 0, 0 );
		if ( $parsed->format( 'Y-m-d' ) !== $value ) {
			return null;
		}

		return $parsed;
	}

	/**
	 * The first hall-local calendar date on which a Session dated `$session_date`
	 * becomes eligible (BR-051/BR-063), or null when the stored date is corrupt.
	 *
	 * Presentation uses this instead of parsing an authoritative stored date
	 * itself, so a corrupt value can never be normalised into a plausible-looking
	 * window on screen.
	 */
	public static function salesWindowStartDate( string $session_date ): ?string {
		$parsed = self::parse_exact_date( $session_date );

		return null === $parsed ? null : $parsed->modify( '-' . self::SALES_WINDOW_DAYS . ' days' )->format( 'Y-m-d' );
	}

	/** The hall-local calendar date, from the hall site's WordPress timezone. */
	public static function hall_local_today(): string {
		return ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );
	}

	/**
	 * Pure window evaluation, so administration and tests share one definition.
	 *
	 * @param array<string,mixed> $row
	 */
	public static function evaluate_available_today( array $row, string $hall_local_today ): bool {
		if ( self::STATUS_ACTIVE !== ( $row['status'] ?? '' ) ) {
			return false;
		}
		if ( empty( $row['available_for_sale'] ) ) {
			return false;
		}

		// createFromFormat() happily normalises 2030-02-30 into 2030-03-02, so
		// the object existing is not proof the input was a real date. Require an
		// exact round-trip and fail closed otherwise: a Session whose stored date
		// is corrupt is never treated as on sale.
		$date  = self::parse_exact_date( (string) $row['session_date'] );
		$today = self::parse_exact_date( $hall_local_today );
		if ( null === $date || null === $today ) {
			return false;
		}
		$opens = $date->modify( '-' . self::SALES_WINDOW_DAYS . ' days' );

		// Inclusive on BOTH boundaries (BR-063).
		return $today >= $opens && $today <= $date;
	}

	/* ------------------------------------------------------------------ *
	 * Internals
	 * ------------------------------------------------------------------ */

	/** @return array<string,int|bool>|\WP_Error */
	private function authorize() {
		if ( ! is_user_logged_in() ) {
			return $this->error( 'authentication_required', 'You must be signed in to manage Bingo Sessions.' );
		}
		if ( ! HallContext::is_hall() ) {
			return $this->error( 'invalid_hall_context', 'Bingo Sessions may be managed only from a hall site.' );
		}

		$actor_id = get_current_user_id();
		$is_super = is_super_admin( $actor_id );

		// Game Operator holds bc_view_sessions but NOT bc_manage_sessions, so this
		// administration surface is closed to that role.
		if ( ! $is_super && ! current_user_can( 'bc_manage_sessions' ) ) {
			return $this->error( 'session_management_denied', 'You are not authorized to manage Bingo Sessions for this hall.' );
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
	 * Strict literal Y-m-d plus a real calendar check, so "2026-02-30" and
	 * "2026-2-3" are both rejected rather than silently normalised.
	 *
	 * @return string|\WP_Error
	 */
	private function canonical_date( string $session_date ) {
		$session_date = trim( $session_date );

		// One definition of a valid Session date, shared with parse_exact_date()
		// so write-time and read-time validity can never drift apart.
		if ( null === self::parse_exact_date( $session_date ) ) {
			return $this->error( 'invalid_session_date', 'The Session date must be a real calendar date in YYYY-MM-DD form.' );
		}

		return $session_date;
	}

	/**
	 * Collapse whitespace and trim. Time text inside the name is preserved
	 * verbatim and is NEVER parsed for business logic (BR-050).
	 *
	 * @return string|\WP_Error
	 */
	private function canonical_display_name( string $display_name ) {
		$name = trim( preg_replace( '/\s+/u', ' ', $display_name ) ?? '' );

		if ( '' === $name ) {
			return $this->error( 'invalid_display_name', 'The Session display name cannot be empty.' );
		}

		if ( mb_strlen( $name ) > self::DISPLAY_NAME_MAX ) {
			return $this->error( 'invalid_display_name', 'The Session display name is too long.' );
		}

		return $name;
	}

	/**
	 * One conditional single-row UPDATE whose guard lives in the WHERE clause, so
	 * a Session archived concurrently cannot be edited.
	 *
	 * @param array<string,mixed>     $before
	 * @param array<string,string|int> $changes
	 * @return SessionResult|\WP_Error
	 */
	private function apply_field_change( int $session_id, array $before, array $changes, string $status, string $action, array $context ) {
		global $wpdb;
		$table = Migrations::sessions_table();
		$now   = current_time( 'mysql', true );

		$assignments = [];
		$values      = [];
		foreach ( $changes as $column => $value ) {
			// Column names are internal literals from this method's callers, never
			// request input.
			$assignments[] = "{$column} = " . ( is_int( $value ) ? '%d' : '%s' );
			$values[]      = $value;
		}
		$assignments[] = 'updated_at = %s';
		$values[]      = $now;
		$values[]      = $session_id;

		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET " . implode( ', ', $assignments )
				. " WHERE session_id = %d AND status = 'active'",
				$values
			)
		);

		if ( false === $affected || '' !== (string) $wpdb->last_error ) {
			if ( isset( $changes['session_date'] ) || isset( $changes['display_name'] ) ) {
				$date = (string) ( $changes['session_date'] ?? $before['session_date'] );
				$name = (string) ( $changes['display_name'] ?? $before['display_name'] );
				$clash = $this->find_by_date_and_name( $date, $name );
				if ( is_array( $clash ) && (int) $clash['session_id'] !== $session_id ) {
					return $this->error( 'duplicate_session', 'A Session with that date and display name already exists in this hall.' );
				}
			}

			return $this->persistence_error( (string) $wpdb->last_error );
		}

		if ( 0 === (int) $affected ) {
			$current = $this->read_by_id( $session_id );
			if ( is_wp_error( $current ) ) {
				return $current;
			}

			return self::STATUS_ACTIVE === $current['status']
				? $this->error( 'session_state_changed', 'The Session changed while it was being updated. Reload and try again.' )
				: $this->error( 'session_not_active', 'The Session is archived, so it cannot be edited. Restore it first.' );
		}

		return $this->settle( $session_id, $status, $action, $context, $before );
	}

	/** @return SessionResult|\WP_Error */
	private function transition( int $session_id, string $target, string $status, string $noop_status, string $action ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$before = $this->require_row( $session_id );
		if ( is_wp_error( $before ) ) {
			return $before;
		}

		if ( $target === $before['status'] ) {
			// Verified no-op. No mutation, and therefore no audit event.
			return new SessionResult( $noop_status, $before );
		}

		global $wpdb;
		$table = Migrations::sessions_table();
		$now   = current_time( 'mysql', true );

		if ( self::STATUS_ARCHIVED === $target ) {
			$affected = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'archived', archived_at = %s, updated_at = %s"
					. " WHERE session_id = %d AND status = 'active'",
					$now,
					$now,
					$session_id
				)
			);
		} else {
			$affected = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'active', archived_at = NULL, updated_at = %s"
					. " WHERE session_id = %d AND status = 'archived'",
					$now,
					$session_id
				)
			);
		}

		if ( false === $affected || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		if ( 0 === (int) $affected ) {
			$current = $this->read_by_id( $session_id );
			if ( is_wp_error( $current ) ) {
				return $current;
			}

			return $target === $current['status']
				? new SessionResult( $noop_status, $current )
				: $this->error( 'session_state_changed', 'The Session changed while it was being updated. Reload and try again.' );
		}

		return $this->settle( $session_id, $status, $action, $context, $before );
	}

	/**
	 * Ordinary post-mutation settlement: confirming read, then ADR-023 audit.
	 *
	 * @param array<string,mixed>|null $before
	 * @param array<string,mixed>      $extra_context
	 */
	private function settle( int $session_id, string $status, string $action, array $context, ?array $before, array $extra_context = [] ): SessionResult {
		$after  = $this->read_by_id( $session_id );
		$result = new SessionResult(
			$status,
			is_wp_error( $after ) ? ( $before ?? [ 'session_id' => $session_id ] ) : $after
		);

		if ( is_wp_error( $after ) ) {
			// The mutation is durably committed; only the confirming read failed.
			$result->warnings[] = [
				'code'    => 'state_read_failed',
				'message' => $after->get_error_message(),
			];
		}

		return $this->record_audit( $result, $action, $context, $before, is_wp_error( $after ) ? null : $after, $session_id, $extra_context );
	}

	/**
	 * Ordinary ADR-023 audit, after a confirmed write. This is NOT ADR-025: no
	 * operation_id, commit witness, retry token or reconciliation.
	 *
	 * The Session identity travels in `target_id`. It is deliberately absent from
	 * every payload, because AuditLog's sensitive-key validation compacts a key
	 * to alphanumerics and rejects `sessionid` — so `session_id` in a payload
	 * would fail every Session audit write.
	 *
	 * @param array<string,mixed>|null $before
	 * @param array<string,mixed>|null $after
	 * @param array<string,mixed>      $extra_context
	 */
	private function record_audit( SessionResult $result, string $action, array $context, ?array $before, ?array $after, int $session_id, array $extra_context = [] ): SessionResult {
		$event = [
			'blog_id'        => $context['blog_id'],
			'actor_user_id'  => $context['actor_user_id'],
			'action'         => $action,
			'target_type'    => 'bingo_session',
			'target_id'      => $session_id,
			'previous_value' => null === $before ? null : $this->audit_payload( $before ),
			'new_value'      => null === $after ? null : $this->audit_payload( $after ),
			'context'        => [] === $extra_context ? null : $extra_context,
		];

		$audit_result = call_user_func( $this->audit_recorder, $event );
		if ( is_wp_error( $audit_result ) ) {
			$error_code = $audit_result->get_error_code();
			error_log( sprintf( 'BingoCommerce Sessions audit failure for %s on Bingo Session %d: %s', $action, $session_id, $error_code ) );
			$result->warnings[] = [
				'code'       => 'audit_write_failed',
				'audit_code' => $error_code,
				'message'    => $audit_result->get_error_message(),
			];
		} elseif ( ! is_int( $audit_result ) || $audit_result < 1 ) {
			error_log( sprintf( 'BingoCommerce Sessions audit returned an invalid result for %s on Bingo Session %d.', $action, $session_id ) );
			$result->warnings[] = [
				'code'       => 'audit_write_failed',
				'audit_code' => 'invalid_audit_result',
				'message'    => 'The audit service returned an invalid result.',
			];
		}

		return $result;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function audit_payload( array $row ): array {
		// NOTE: no session_id key. See record_audit().
		return [
			'session_date'       => (string) ( $row['session_date'] ?? '' ),
			'display_name'       => (string) ( $row['display_name'] ?? '' ),
			'available_for_sale' => (bool) ( $row['available_for_sale'] ?? false ),
			'status'             => (string) ( $row['status'] ?? '' ),
		];
	}

	/**
	 * Report a verified relationship no-op, propagating a failed confirming read
	 * instead of handing a WP_Error to SessionResult as if it were a row.
	 *
	 * @return SessionResult|\WP_Error
	 */
	private function settle_no_op( int $session_id, string $status ) {
		$current = $this->read_by_id( $session_id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		return new SessionResult( $status, $current );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function require_row( int $session_id ) {
		$row = $this->read_by_id( $session_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( ! in_array( $row['status'], self::STATUSES, true ) ) {
			// Fail closed rather than coercing an unrecognised lifecycle value.
			return $this->error( 'session_state_inconsistent', 'The Session holds an unrecognised lifecycle value. Contact Platform Admin.' );
		}

		return $row;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function require_active_row( int $session_id ) {
		$row = $this->require_row( $session_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( self::STATUS_ACTIVE !== $row['status'] ) {
			return $this->error( 'session_not_active', 'The Session is archived. Restore it before making changes.' );
		}

		return $row;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function read_by_id( int $session_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM ' . Migrations::sessions_table() . ' WHERE session_id = %d', $session_id ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( ! is_array( $row ) ) {
			return $this->error( 'session_not_found', 'The Bingo Session was not found.' );
		}

		return $this->decorate( $this->map_row( $row ) );
	}

	/** @return array<string,mixed>|null */
	private function find_by_date_and_name( string $session_date, string $display_name ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT ' . self::COLUMNS . ' FROM ' . Migrations::sessions_table() . ' WHERE session_date = %s AND display_name = %s',
				$session_date,
				$display_name
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->map_row( $row ) : null;
	}

	/**
	 * A failed read is never coerced into `false` or `[]`.
	 *
	 * These helpers decide duplicate/no-op and eligibility outcomes, so a
	 * database error that silently became "no relationship" or "not active"
	 * would turn an unknown into a confident wrong answer.
	 *
	 * @return bool|\WP_Error
	 */
	private function relation_exists( int $session_id, int $package_id ) {
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Migrations::session_packages_table() . ' WHERE session_id = %d AND package_id = %d',
				$session_id,
				$package_id
			)
		);
		if ( null === $count || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		return 0 < (int) $count;
	}

	/** @return bool|\WP_Error */
	private function is_active( int $session_id ) {
		global $wpdb;

		$status = $wpdb->get_var(
			$wpdb->prepare( 'SELECT status FROM ' . Migrations::sessions_table() . ' WHERE session_id = %d', $session_id )
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		return self::STATUS_ACTIVE === (string) $status;
	}

	/** @return array<int,int>|\WP_Error */
	private function assigned_package_ids( int $session_id ) {
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

		return array_map( 'intval', $ids );
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function map_row( array $row ): array {
		$row['session_id']         = (int) $row['session_id'];
		$row['session_date']       = (string) $row['session_date'];
		$row['display_name']       = (string) $row['display_name'];
		$row['available_for_sale'] = (bool) (int) $row['available_for_sale'];

		return $row;
	}

	/**
	 * Attach derived, non-authoritative view data: assignments and the
	 * read-only BR-051/BR-063 result.
	 *
	 * A failed assignment read is NOT an empty assignment list; it propagates.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>|\WP_Error
	 */
	private function decorate( array $row ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT session_package_id, package_id, created_at FROM ' . Migrations::session_packages_table()
				. ' WHERE session_id = %d ORDER BY package_id',
				(int) $row['session_id']
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		$assignments = [];
		foreach ( $rows as $assignment ) {
			$assignments[] = [
				'session_package_id' => (int) $assignment['session_package_id'],
				'package_id'         => (int) $assignment['package_id'],
				'created_at'         => (string) $assignment['created_at'],
			];
		}

		$row['assignments']              = $assignments;
		$row['assigned_package_ids']     = array_map( static fn( array $a ): int => $a['package_id'], $assignments );
		$row['available_for_sale_today'] = self::evaluate_available_today( $row, self::hall_local_today() );

		return $row;
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
