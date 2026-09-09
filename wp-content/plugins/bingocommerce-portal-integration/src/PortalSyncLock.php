<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The per-Hall MySQL advisory lock that serializes Portal synchronization.
 *
 * The lock is acquired BEFORE the Portal HTTP request and covers the whole
 * operation: mapping resolution, fetch, validation, and application. Two
 * synchronizations of the same Hall therefore never overlap at any stage, and
 * the loser never issues a Portal request at all.
 *
 * Different Halls are never serialized against one another: the lock name
 * carries the target `blog_id`, and the mapping is one-to-one with the Portal
 * Hall.
 *
 * The lock is taken through ordinary `$wpdb->prepare()`, following
 * HallStaffProvisioningService and HallStaffInvitation. That matters for
 * correctness, not just style: `$wpdb->dbh` is the very session
 * PortalSnapshotTransactionTransport later captures, so the lock and the
 * transaction are held by the same MySQL session. MySQL advisory locks survive
 * COMMIT and ROLLBACK, so the lock keeps covering the operation after the
 * transaction closes.
 *
 * A lock is only meaningful while the session that took it is alive. If `$wpdb`
 * reconnects — a lost connection, a server restart — MySQL releases the advisory
 * lock silently and another request could enter. isStillHeld() therefore asks
 * the server directly, and the transport re-asserts it before BEGIN.
 */
final class PortalSyncLock {

	/** Matches the Hall Staff boundaries: a rare, human/operator-paced action. */
	private const TIMEOUT_SECONDS = 5;

	/**
	 * Lock names this PHP request already holds.
	 *
	 * MySQL advisory locks are RE-ENTRANT within one session: since 5.7 the same
	 * connection may take the same name repeatedly, and each acquisition needs its
	 * own RELEASE_LOCK. GET_LOCK would therefore happily hand a nested
	 * synchronization of the SAME hall a second lock and let it fetch and write
	 * concurrently with the outer one — the exact interleaving this lock exists to
	 * prevent, invisible because MySQL reports success.
	 *
	 * This request-local guard rejects the second acquisition BEFORE GET_LOCK is
	 * issued, so a nested attempt never reaches the Portal request and never
	 * reaches the transaction. Different halls carry different names and are
	 * unaffected.
	 *
	 * @var array<string,bool>
	 */
	private static array $held_names = [];

	public const REENTRANT = 'portal_sync_lock_reentrant';

	/**
	 * The ONLY message any release failure logs.
	 *
	 * One fixed string on every branch, so a lock name, a connection id, an SQL
	 * statement, or a MySQL exception message can never reach the log.
	 */
	private const RELEASE_FAILED_MESSAGE = 'BingoCommerce Portal synchronization advisory lock release failed on a live connection.';

	private string $name;
	private int $connection_id;
	private bool $held = false;

	private function __construct( string $name, int $connection_id ) {
		$this->name          = $name;
		$this->connection_id = $connection_id;
		$this->held          = true;
	}

	/**
	 * Acquire the lock for one hall site, or fail without side effects.
	 *
	 * @return self|\WP_Error
	 */
	public static function acquire( int $blog_id ) {
		global $wpdb;

		if ( $blog_id < 1 ) {
			return self::error( 'invalid_blog_id', 'The WordPress site ID is invalid.' );
		}

		$name = self::lock_name( $blog_id );

		// Before GET_LOCK, before any fetch, and before any write.
		if ( isset( self::$held_names[ $name ] ) ) {
			return self::error( self::REENTRANT, 'Portal synchronization for this hall is already in progress in this request.' );
		}

		/*
		 * RESERVE THE NAME BEFORE THE FIRST $wpdb QUERY.
		 *
		 * Checking and then querying would leave a re-entrancy window exactly the
		 * width of the acquisition query: wpdb::query() fires the `query` filter on
		 * every statement, so a third-party callback running on the GET_LOCK
		 * statement itself could call back into synchronizeHall() for this same
		 * hall. It would find the guard still empty, and MySQL — whose advisory
		 * locks are re-entrant per session — would hand it the lock again and
		 * report success. Reserving first closes that window: the nested attempt is
		 * refused before it issues any query, fetches anything, or writes anything.
		 */
		self::$held_names[ $name ] = true;
		$acquired                  = false;

		try {
			// One statement, so the connection identity recorded here is provably
			// the connection that now holds the lock.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT GET_LOCK(%s, %d) AS acquired, CONNECTION_ID() AS connection_id',
					$name,
					self::TIMEOUT_SECONDS
				),
				ARRAY_A
			);

			if ( ! is_array( $row ) || '' !== (string) $wpdb->last_error ) {
				return self::error( 'portal_sync_lock_failed', 'The Portal synchronization lock could not be acquired.' );
			}

			// GET_LOCK returns 1 on success, 0 on timeout, and NULL on error.
			if ( 1 !== (int) ( $row['acquired'] ?? 0 ) ) {
				return self::error( 'portal_sync_lock_timeout', 'Portal synchronization is already running for this hall. Retry shortly.' );
			}

			$connection_id = (int) ( $row['connection_id'] ?? 0 );
			if ( $connection_id < 1 ) {
				// The lock may be held on a session we cannot identify, so it cannot
				// be released deterministically later. Give it back immediately.
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );

				return self::error( 'portal_sync_lock_failed', 'The Portal synchronization lock session could not be identified.' );
			}

			$acquired = true;

			return new self( $name, $connection_id );
		} finally {
			// Every failure path AND any throw from the queries above releases the
			// reservation. Only a successfully constructed lock keeps it, and that
			// instance owns clearing it in release().
			if ( ! $acquired ) {
				unset( self::$held_names[ $name ] );
			}
		}
	}

	/**
	 * Ask the SERVER whether this session still holds the lock.
	 *
	 * IS_USED_LOCK() returns the connection ID of the holder, or NULL when the
	 * lock is free. Comparing it to the acquiring connection ID proves possession
	 * rather than inferring it, and catches both a reconnect and a lock released
	 * out from under this request.
	 */
	public function isStillHeld(): bool {
		global $wpdb;

		if ( ! $this->held ) {
			return false;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT IS_USED_LOCK(%s) AS holder, CONNECTION_ID() AS connection_id',
				$this->name
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || '' !== (string) $wpdb->last_error ) {
			return false;
		}

		$holder        = $row['holder'] ?? null;
		$connection_id = (int) ( $row['connection_id'] ?? 0 );

		return null !== $holder
			&& (int) $holder === $this->connection_id
			&& $connection_id === $this->connection_id;
	}

	public function connectionId(): int {
		return $this->connection_id;
	}

	/**
	 * Release the lock. Safe to call more than once, and always safe in `finally`.
	 *
	 * A session that was lost has already released its advisory locks, so no
	 * RELEASE_LOCK is issued against a different connection that may have since
	 * reused the name.
	 */
	public function release(): void {
		global $wpdb;

		if ( ! $this->held ) {
			return;
		}

		$this->held = false;

		/*
		 * The reservation stays in place for the WHOLE release path, and is given
		 * up ONLY on positive proof that the lock can no longer be held.
		 *
		 * Both statements below go through $wpdb and therefore fire the `query`
		 * filter. Clearing the guard before they finish would let a callback
		 * running on the CONNECTION_ID() or RELEASE_LOCK statement re-enter
		 * synchronizeHall() for this hall while the advisory lock is still held or
		 * is mid-release — a nested run overlapping the tail of the outer one.
		 *
		 * More importantly, an UNCONFIRMED release is not a release. RELEASE_LOCK
		 * returns 0 when this thread does not own the lock and NULL when the name
		 * does not exist, and a failed or throwing query returns nothing at all. In
		 * every one of those cases MySQL may still be holding the lock on this
		 * session, and MySQL advisory locks are re-entrant per session — so
		 * clearing the guard would let a later same-Hall synchronization in this
		 * request call GET_LOCK and be handed the lock again, successfully.
		 *
		 * $clear therefore defaults to false and is set only on PROOF:
		 *
		 *   1. a VALID, POSITIVE connection id that differs from the acquiring one,
		 *      so the original session and every advisory lock it held are provably
		 *      gone; or
		 *   2. RELEASE_LOCK returned exactly 1, without a query error, on that same
		 *      session.
		 *
		 * Anything else — 0, NULL, false, a query error, an unusable connection id,
		 * an exception — keeps the reservation for the rest of the PHP request and
		 * deliberately leaves this hall refused. Refusing a later synchronization is
		 * recoverable; handing out a lock that may still be held is not.
		 *
		 * The connection-id result is validated BEFORE it is cast. get_var()
		 * returns null on a query error and on no rows, and (int) null is 0, which
		 * would compare unequal to a real connection id and be misread as "the
		 * connection changed" — clearing the reservation on precisely the failure
		 * where the lock is most likely still held.
		 *
		 * Only the single generic message is logged, on every failure branch. No
		 * lock name, SQL, connection id, or MySQL text is disclosed.
		 */
		$clear = false;

		try {
			$connection_id = $wpdb->get_var( 'SELECT CONNECTION_ID()' );

			if ( null === $connection_id || false === $connection_id || '' !== (string) $wpdb->last_error ) {
				error_log( self::RELEASE_FAILED_MESSAGE );

				return;
			}

			$connection_id = (int) $connection_id;
			if ( $connection_id < 1 ) {
				error_log( self::RELEASE_FAILED_MESSAGE );

				return;
			}

			if ( $connection_id !== $this->connection_id ) {
				// A lost MySQL session releases its advisory locks automatically, so
				// a valid, different, live connection id is proof the lock is gone
				// rather than an assumption.
				$clear = true;

				return;
			}

			$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name ) );
			if ( 1 === (int) $released && '' === (string) $wpdb->last_error ) {
				$clear = true;

				return;
			}

			error_log( self::RELEASE_FAILED_MESSAGE );
		} catch ( \Throwable $throwable ) {
			/*
			 * release() runs from the CALLER's finally, so an escaping exception
			 * would replace an already-completed synchronization result: a committed
			 * snapshot would surface to the caller as a thrown release error. The
			 * throw is swallowed here, the reservation is kept because the release
			 * outcome is unknown, and only the generic message is logged — the
			 * exception's own message may quote SQL or MySQL text and never escapes.
			 */
			error_log( self::RELEASE_FAILED_MESSAGE );
		} finally {
			if ( $clear ) {
				unset( self::$held_names[ $this->name ] );
			}
		}
	}

	/**
	 * Installation-, network-, and site-scoped lock name.
	 *
	 * Discriminated by database, base prefix, and network so two installations
	 * sharing one MySQL server cannot collide, and keyed by `blog_id` so halls
	 * synchronize independently. 'bc:ps:' + 32 hex + ':' + id stays inside
	 * MySQL's 64-character lock-name limit.
	 */
	private static function lock_name( int $blog_id ): string {
		global $wpdb;

		$discriminator = hash(
			'sha256',
			DB_NAME . '|' . $wpdb->base_prefix . '|' . get_current_network_id()
		);

		return 'bc:ps:' . substr( $discriminator, 0, 32 ) . ':' . $blog_id;
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => $code ] );
	}
}
