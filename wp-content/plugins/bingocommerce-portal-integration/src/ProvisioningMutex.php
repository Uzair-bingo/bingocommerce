<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ADR-042 Hall-scoped provisioning mutex.
 *
 * ONE MUTEX PER `portal_hall_id`, NEVER A GLOBAL ONE. The lock name carries the
 * Portal Hall ID, so two Halls provision concurrently and only the same Hall is
 * serialized. Cross-Hall address exclusion is the operations table's
 * `UNIQUE(site_slug)` and nothing else — a global slug mutex would serialize
 * every Hall in the network behind one address check, which this deliberately
 * does not do.
 *
 * KEYED BY PORTAL HALL, NOT BY `blog_id`. The mutex must cover the window
 * BEFORE a site exists, which is exactly when there is no `blog_id` to key on.
 * That also makes it a different name space from PortalSyncLock, which is keyed
 * by `blog_id`, so the two locks can never be confused for one another.
 *
 * ORDERING IS ENFORCED, NOT DOCUMENTED. ADR-042 requires the provisioning mutex
 * to be taken BEFORE the snapshot sync lock. acquire() therefore refuses while
 * this request already holds a sync lock, so the reverse order cannot be reached
 * by accident.
 *
 * Taken through ordinary `$wpdb->prepare()`, following PortalSyncLock: the lock
 * then lives on the very MySQL session that later transports capture, and MySQL
 * advisory locks survive COMMIT and ROLLBACK.
 *
 * A lock is only meaningful while the session that took it is alive. If `$wpdb`
 * reconnects, MySQL releases the advisory lock silently, so isStillHeld() asks
 * the server rather than trusting this object.
 */
final class ProvisioningMutex {

	/** Matches PortalSyncLock: a rare, machine-paced provisioning action. */
	private const TIMEOUT_SECONDS = 5;

	/** Refused before GET_LOCK, because MySQL advisory locks are re-entrant. */
	public const REENTRANT = 'provisioning_mutex_reentrant';

	/** Refused to keep the ADR-042 lock order: provisioning first, sync second. */
	public const ORDER_VIOLATION = 'provisioning_mutex_order_violation';

	/** The ONLY message any release failure logs. */
	private const RELEASE_FAILED_MESSAGE = 'BingoCommerce provisioning mutex release failed on a live connection.';

	/**
	 * Lock names this PHP request already holds.
	 *
	 * Reserved BEFORE the first query for the same reason PortalSyncLock does
	 * it: `wpdb::query()` fires the `query` filter on every statement, so a
	 * callback running on the GET_LOCK statement itself could re-enter
	 * provisioning for this same Hall, find an empty guard, and be handed the
	 * re-entrant lock again with MySQL reporting success.
	 *
	 * @var array<string,bool>
	 */
	private static array $held_names = [];

	private string $name;
	private int $portal_hall_id;
	private int $connection_id;
	private bool $held = false;

	private function __construct( string $name, int $portal_hall_id, int $connection_id ) {
		$this->name           = $name;
		$this->portal_hall_id = $portal_hall_id;
		$this->connection_id  = $connection_id;
		$this->held           = true;
	}

	/**
	 * Acquire the provisioning mutex for one Portal Hall, or fail cleanly.
	 *
	 * NOTHING ESCAPES. Every Throwable is contained and every failure is one of
	 * the fixed codes above, so no exception, SQL text, driver diagnostic or
	 * connection detail can reach the caller through this method. Lock probes
	 * locally suppress wpdb error display and restore the previous flags
	 * afterwards, including when a probe throws.
	 *
	 * THE REQUEST-LOCAL RESERVATION IS GIVEN UP ONLY ON PROOF. MySQL advisory
	 * locks are re-entrant per session, so if this request forgets a lock it may
	 * still hold, the very next acquisition for that Hall is handed the same lock
	 * with the server reporting success — two "holders" of a mutex that exists to
	 * have one. An ambiguous GET_LOCK result therefore keeps the reservation and
	 * leaves this Hall refused for the rest of the request: refusing a later
	 * attempt is recoverable, double-granting is not. The reservation is cleared
	 * only when the server proves the lock was NOT taken, or a release is
	 * positively confirmed.
	 *
	 * @param mixed $portal_hall_id
	 * @return self|\WP_Error
	 */
	public static function acquire( $portal_hall_id ) {
		if ( ! is_int( $portal_hall_id ) || $portal_hall_id < 1 ) {
			return self::error( 'invalid_portal_hall_id', 'The Portal Hall ID is invalid.' );
		}

		try {
			return self::acquire_for_hall( $portal_hall_id );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return self::error( 'lock_unavailable', 'The provisioning mutex could not be acquired.' );
		}
	}

	/**
	 * @return self|\WP_Error
	 */
	private static function acquire_for_hall( int $portal_hall_id ) {
		global $wpdb;

		/*
		 * Lock order, checked before anything else. Acquiring the provisioning
		 * mutex while a sync lock is already held is the one interleaving that
		 * can deadlock two provisioning requests against two synchronizations.
		 */
		if ( PortalSyncLock::hasAnyHeld() ) {
			return self::error(
				self::ORDER_VIOLATION,
				'The provisioning mutex must be acquired before the synchronization lock.'
			);
		}

		$name = self::lock_name( $portal_hall_id );

		if ( isset( self::$held_names[ $name ] ) ) {
			return self::error( self::REENTRANT, 'Provisioning for this Hall is already in progress in this request.' );
		}

		// Reserved BEFORE the statement, so a `query`-filter callback running on
		// the GET_LOCK statement itself cannot re-enter and be handed the
		// re-entrant lock.
		self::$held_names[ $name ] = true;

		try {
			// One statement, so the recorded connection identity is provably the
			// connection that now holds the lock.
			$probe = self::with_db_errors_contained(
				static function () use ( $wpdb, $name ) {
					$row = $wpdb->get_row(
						$wpdb->prepare(
							'SELECT GET_LOCK(%s, %d) AS acquired, CONNECTION_ID() AS connection_id',
							$name,
							self::TIMEOUT_SECONDS
						),
						ARRAY_A
					);

					return [ $row, (string) $wpdb->last_error ];
				}
			);
			$row   = $probe[0];
			$error = $probe[1];
		} catch ( \Throwable $throwable ) {
			/*
			 * The statement may have reached the server and taken the lock
			 * before the throw, so this is AMBIGUOUS and the reservation stays.
			 * The exception object is never read: its message and trace can
			 * quote SQL and connection details.
			 */
			unset( $throwable );

			return self::error( 'lock_unavailable', 'The provisioning mutex could not be acquired.' );
		}

		if ( ! is_array( $row ) || '' !== $error || ! array_key_exists( 'acquired', $row ) ) {
			// Also ambiguous: the lock may be held on this session even though
			// its result could not be read back.
			return self::error( 'lock_unavailable', 'The provisioning mutex could not be acquired.' );
		}

		$acquired = self::lock_result( $row['acquired'] );

		if ( false === $acquired ) {
			// PROVEN not acquired: GET_LOCK answered 0, which is a timeout and
			// nothing else. This is the one refusal that may safely give the
			// request-local reservation back.
			unset( self::$held_names[ $name ] );

			return self::error( 'lock_unavailable', 'Provisioning is already running for this Hall. Retry shortly.' );
		}

		if ( true !== $acquired ) {
			// GET_LOCK returned NULL (a server-side error) or something this
			// build does not recognise. Ambiguous; keep the reservation.
			return self::error( 'lock_unavailable', 'The provisioning mutex could not be acquired.' );
		}

		$connection_id = self::connection_identity( $row['connection_id'] ?? null );
		if ( null === $connection_id ) {
			/*
			 * The lock IS held, but on a session this build cannot name, so it
			 * could never be released deterministically. Give it back now — and
			 * only a CONFIRMED release may clear the reservation.
			 */
			if ( self::prove_release( $name ) ) {
				unset( self::$held_names[ $name ] );
			}

			return self::error( 'lock_unavailable', 'The provisioning mutex session could not be identified.' );
		}

		return new self( $name, $portal_hall_id, $connection_id );
	}

	/**
	 * GET_LOCK's three-valued answer, read strictly.
	 *
	 * True for 1, false for 0, null for anything else — including SQL NULL,
	 * which MySQL returns on error and which must never be read as "not
	 * acquired". `(int)` alone would turn NULL into 0 and make the ambiguous
	 * case indistinguishable from a proven timeout.
	 *
	 * @param mixed $value
	 */
	private static function lock_result( $value ): ?bool {
		if ( is_int( $value ) ) {
			return 1 === $value ? true : ( 0 === $value ? false : null );
		}
		if ( '1' === $value ) {
			return true;
		}
		if ( '0' === $value ) {
			return false;
		}

		return null;
	}

	/**
	 * A usable MySQL connection identity, or null when there is none.
	 *
	 * Representation before value: the driver hands back a decimal string, and
	 * `(int)` on a missing or non-numeric value would produce 0, which later
	 * compares unequal to the acquiring ID and would be misread as "the
	 * connection changed".
	 *
	 * @param mixed $value
	 */
	private static function connection_identity( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 1 ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A\d+\z/', $value ) ) {
			return null;
		}

		$parsed = (int) $value;

		return ( (string) $parsed === $value && $parsed >= 1 ) ? $parsed : null;
	}

	/**
	 * Ask the SERVER whether this session still holds the mutex.
	 *
	 * IS_USED_LOCK() returns the holder's connection ID, or NULL when free.
	 * Comparing it to the acquiring connection proves possession rather than
	 * inferring it, and catches both a reconnect and an out-of-band release.
	 *
	 * FAILS CLOSED AND THROWS NOTHING. An unreadable or throwing probe answers
	 * "not proven held", because callers use this to decide whether they may
	 * still mutate; the honest answer when the server cannot be asked is no.
	 */
	public function isStillHeld(): bool {
		global $wpdb;

		if ( ! $this->held ) {
			return false;
		}

		try {
			$probe = self::with_db_errors_contained(
				function () use ( $wpdb ) {
					$row = $wpdb->get_row(
						$wpdb->prepare(
							'SELECT IS_USED_LOCK(%s) AS holder, CONNECTION_ID() AS connection_id',
							$this->name
						),
						ARRAY_A
					);

					return [ $row, (string) $wpdb->last_error ];
				}
			);
			$row   = $probe[0];
			$error = $probe[1];
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return false;
		}

		if ( ! is_array( $row ) || '' !== $error ) {
			return false;
		}

		$holder        = self::connection_identity( $row['holder'] ?? null );
		$connection_id = self::connection_identity( $row['connection_id'] ?? null );

		return null !== $holder
			&& null !== $connection_id
			&& $holder === $this->connection_id
			&& $connection_id === $this->connection_id;
	}

	public function portalHallId(): int {
		return $this->portal_hall_id;
	}

	public function connectionId(): int {
		return $this->connection_id;
	}

	/**
	 * Release the mutex. Safe to call repeatedly, and safe in `finally`.
	 *
	 * AN UNCONFIRMED RELEASE IS NOT A RELEASE. RELEASE_LOCK returns 0 when this
	 * thread does not own the lock and NULL when the name does not exist, and a
	 * failed or throwing query returns nothing at all. In every one of those
	 * cases MySQL may still hold the lock on this session, and advisory locks
	 * are re-entrant per session — so the request-local reservation is given up
	 * only on positive proof, and otherwise this Hall stays refused for the rest
	 * of the request. Refusing a later provisioning attempt is recoverable;
	 * handing out a mutex that may still be held is not.
	 */
	public function release(): void {
		global $wpdb;

		if ( ! $this->held ) {
			return;
		}

		$this->held = false;
		$clear      = false;

		try {
			$probe = self::with_db_errors_contained(
				static function () use ( $wpdb ) {
					$live = $wpdb->get_var( 'SELECT CONNECTION_ID()' );

					return [ $live, (string) $wpdb->last_error ];
				}
			);
			$live  = $probe[0];
			$error = $probe[1];

			// Validated BEFORE any cast: get_var() returns null on a query error
			// and on no rows, and (int) null is 0, which would compare unequal to
			// the acquiring ID and be misread as "the connection changed" —
			// clearing the reservation on exactly the failure where the lock is
			// most likely still held.
			$connection_id = self::connection_identity( $live );
			if ( '' !== $error || null === $connection_id ) {
				error_log( self::RELEASE_FAILED_MESSAGE );

				return;
			}

			if ( $connection_id !== $this->connection_id ) {
				// A lost MySQL session has already released its advisory locks,
				// so a valid, different, live connection ID is proof rather than
				// an assumption.
				$clear = true;

				return;
			}

			if ( self::prove_release( $this->name ) ) {
				$clear = true;

				return;
			}

			error_log( self::RELEASE_FAILED_MESSAGE );
		} catch ( \Throwable $throwable ) {
			/*
			 * release() runs from the caller's `finally`, so an escaping
			 * exception would replace an already-decided provisioning outcome.
			 * The throw is swallowed, the reservation is kept because the
			 * release outcome is unknown, and only the fixed message is logged —
			 * the exception's own message may quote SQL or MySQL text.
			 */
			error_log( self::RELEASE_FAILED_MESSAGE );
		} finally {
			if ( $clear ) {
				unset( self::$held_names[ $this->name ] );
			}
		}
	}

	/**
	 * Did RELEASE_LOCK positively confirm the release?
	 *
	 * Only an exact 1 with no error counts. RELEASE_LOCK returns 0 when this
	 * thread does not own the lock and NULL when the name does not exist, and a
	 * failed or throwing statement returns nothing at all — in every one of
	 * those cases the lock may still be held on this session, so "not proven
	 * released" is the only safe reading.
	 */
	private static function prove_release( string $name ): bool {
		global $wpdb;

		try {
			$probe = self::with_db_errors_contained(
				static function () use ( $wpdb, $name ) {
					$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );

					return [ $released, (string) $wpdb->last_error ];
				}
			);
			$released = $probe[0];
			$error    = $probe[1];
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return false;
		}

		return '' === $error && true === self::lock_result( $released );
	}

	/**
	 * Silence wpdb SQL/driver diagnostics for one lock probe, then restore
	 * the exact previous suppression and display flags.
	 *
	 * `print_error()` emits SQL and driver text when `show_errors` is on and
	 * `suppress_errors` is off. A lock probe that throws, times out, or hits a
	 * missing table must not print that text — and must not leave error-display
	 * flags changed, including when the probe itself throws.
	 *
	 * @template T
	 * @param callable():T $probe
	 * @return T
	 */
	private static function with_db_errors_contained( callable $probe ) {
		global $wpdb;

		$previous_suppress = $wpdb->suppress_errors( true );
		$previous_show     = $wpdb->show_errors( false );
		try {
			return $probe();
		} finally {
			$wpdb->suppress_errors( $previous_suppress );
			$wpdb->show_errors( $previous_show );
		}
	}

	/**
	 * Installation-, network- and Hall-scoped lock name.
	 *
	 * Discriminated by database, base prefix and network so two installations on
	 * one MySQL server cannot collide, and keyed by `portal_hall_id` so Halls
	 * provision independently. The `bc:pp:` prefix is distinct from
	 * PortalSyncLock's `bc:ps:`, so the provisioning mutex and the sync lock are
	 * provably different names even for the same numeric ID. 'bc:pp:' + 32 hex +
	 * ':' + id stays inside MySQL's 64-character lock-name limit.
	 */
	private static function lock_name( int $portal_hall_id ): string {
		global $wpdb;

		$discriminator = hash(
			'sha256',
			DB_NAME . '|' . $wpdb->base_prefix . '|' . get_current_network_id()
		);

		return 'bc:pp:' . substr( $discriminator, 0, 32 ) . ':' . $portal_hall_id;
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-portal-integration' ) );
	}
}
