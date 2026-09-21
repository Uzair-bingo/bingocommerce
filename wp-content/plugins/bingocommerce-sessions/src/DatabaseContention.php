<?php

namespace BingoCommerce\Sessions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database-contention classification (ADR-043 §7.11).
 *
 * MySQL 1213 (deadlock, transaction rolled back) and 1205 (lock wait timeout
 * exceeded) are the two codes reconciliation treats identically: stop the run
 * immediately, report the FIXED category `database_contention`, and expose
 * NOTHING else — no SQL text, no driver message, no table name, no stored
 * value. §7.11 item 6 is explicit that those specifics must never surface in
 * the result or the audit context, because a reconciliation failure notice is
 * seen by a Hall Manager, not a developer.
 *
 * READS `$wpdb->dbh->errno` — a public property of the mysqli connection
 * WordPress core itself uses, exposed the same way `$wpdb->last_error` is.
 * This is not a new mysqli transport under AGENTS.md rule 4: nothing here
 * ISSUES a statement or BINDS a value through mysqli, it only reads a numeric
 * property off the connection immediately after `$wpdb->query()` reports
 * failure, exactly as this codebase already reads `$wpdb->last_error`.
 */
final class DatabaseContention {

	/** MySQL 1213: deadlock detected, transaction rolled back. */
	public const ERRNO_DEADLOCK = 1213;

	/** MySQL 1205: lock wait timeout exceeded. */
	public const ERRNO_LOCK_WAIT_TIMEOUT = 1205;

	private const CONTENTION_ERRNOS = [ self::ERRNO_DEADLOCK, self::ERRNO_LOCK_WAIT_TIMEOUT ];

	/** The exact numeric MySQL error from the LAST statement on `$wpdb`, or 0. */
	public static function last_errno( \wpdb $wpdb ): int {
		if ( ! isset( $wpdb->dbh ) || ! ( $wpdb->dbh instanceof \mysqli ) ) {
			return 0;
		}

		return (int) $wpdb->dbh->errno;
	}

	/** Whether the last statement on `$wpdb` failed with a contention code. */
	public static function is_contention( \wpdb $wpdb ): bool {
		return in_array( self::last_errno( $wpdb ), self::CONTENTION_ERRNOS, true );
	}

	/** Whether the last statement failed with MySQL 1062 (duplicate key). */
	public static function is_duplicate_key( \wpdb $wpdb ): bool {
		return 1062 === self::last_errno( $wpdb );
	}

	/**
	 * The FIXED, sanitized contention error. Callers pass NOTHING statement-
	 * specific into it, and it accepts nothing statement-specific — that
	 * asymmetry is deliberate: there is no parameter through which a SQL
	 * fragment or a stored value could be smuggled into the result.
	 */
	public static function error(): \WP_Error {
		return new \WP_Error(
			'database_contention',
			__( 'The database was busy while making this change. Nothing was lost; try again.', 'bingocommerce-sessions' ),
			[ 'status' => 'database_contention', 'failure_category' => 'database_contention' ]
		);
	}
}
