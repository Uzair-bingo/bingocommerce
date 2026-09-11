<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistence for a managed Hall's Commerce state.
 *
 * INTERNAL PERSISTENCE ONLY. Nothing here enforces access, renders a
 * maintenance page, enrols a Hall, creates a marker, or touches the Hall
 * mapping or Portal synchronization. Writing a row changes no request's
 * behaviour; a later increment decides who reads it and what that means.
 *
 * ORDERING IS ENFORCED IN THE DATABASE, not in PHP. A read-then-write would
 * lose a race with a concurrent writer and could let an older command overwrite
 * a newer state. The single INSERT ... ON DUPLICATE KEY UPDATE below is the
 * whole mechanism: it creates the row when absent (uniqueness on the primary
 * key resolves simultaneous first writers) and updates it only when the
 * incoming sequence is strictly greater. No transaction, lock, lease or retry
 * loop is required, so none is added.
 *
 * AFFECTED-ROW SEMANTICS ARE ENFORCED, NOT ASSUMED. For
 * INSERT ... ON DUPLICATE KEY UPDATE, MySQL normally reports:
 *
 *     1  a row was inserted
 *     2  an existing row was changed
 *     0  an existing row already held these exact values
 *
 * A connection carrying CLIENT_FOUND_ROWS reports the unchanged duplicate as 1
 * instead of 0; the changed duplicate is still 2. That makes an insert and an
 * unchanged duplicate both report 1 — exactly the pair this class must tell
 * apart — so such a connection is refused. wpdb takes that flag from the
 * MYSQL_CLIENT_FLAGS constant (wp-includes/class-wpdb.php, db_connect()), which
 * is therefore what assert_connection_mode() inspects, before any state write.
 *
 * The stored row remains authoritative: the read-back alone decides superseded,
 * conflict and unverified. Affected rows are consulted only after the read-back
 * has confirmed the requested sequence AND state, and only to separate "this
 * call wrote the row" from "the row already held exactly this". A timestamp
 * comparison cannot make that distinction — two identical requests inside one
 * second share a timestamp — so it is not used for it.
 */
final class HallCommerceState {

	public const PROVISIONING = 'provisioning';
	public const OPERATIONAL  = 'operational';
	public const SUSPENDED    = 'suspended';

	/** The only accepted states. */
	public const STATES = [ self::PROVISIONING, self::OPERATIONAL, self::SUSPENDED ];

	/** Transitional: entering provisioning. */
	public const PHASE_TRANSITIONAL = 0;

	/** Settled: operational or suspended. */
	public const PHASE_SETTLED = 1;

	/**
	 * The lifecycle phase each state must be written with.
	 *
	 * This is SEPARATE from the packer's numeric capacity below. The packer can
	 * represent 256 phase values; the lifecycle currently defines two, and the
	 * rest are reserved. A reserved value is a valid number and an invalid
	 * lifecycle write, so it is refused here rather than silently stored.
	 */
	public const STATE_PHASES = [
		self::PROVISIONING => self::PHASE_TRANSITIONAL,
		self::OPERATIONAL  => self::PHASE_SETTLED,
		self::SUSPENDED    => self::PHASE_SETTLED,
	];

	/** Bits reserved for the phase inside the packed enforcement sequence. */
	public const PHASE_BITS = 8;

	/** Numeric capacity of the phase field. NOT the set of permitted phases. */
	public const MAX_PHASE = 255;

	/** Datetime representation every stored row must use. */
	private const TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

	/**
	 * Is the connection's affected-row reporting the mode this class requires?
	 *
	 * Established from the installed implementation rather than an invented API:
	 * wpdb::db_connect() passes `defined( 'MYSQL_CLIENT_FLAGS' ) ?
	 * MYSQL_CLIENT_FLAGS : 0` to mysqli_real_connect(), so that constant is the
	 * only place CLIENT_FOUND_ROWS can be introduced.
	 *
	 * @return true|\WP_Error
	 */
	private static function assert_connection_mode() {
		if ( ! defined( 'MYSQL_CLIENT_FLAGS' ) ) {
			return true;
		}

		$flags = MYSQL_CLIENT_FLAGS;
		if ( ! is_int( $flags ) ) {
			return self::error( 'unsupported_connection_mode', 'The database connection mode is not supported.' );
		}

		if ( defined( 'MYSQLI_CLIENT_FOUND_ROWS' ) && ( $flags & MYSQLI_CLIENT_FOUND_ROWS ) ) {
			return self::error( 'unsupported_connection_mode', 'The database connection mode is not supported.' );
		}

		return true;
	}

	/* Write outcomes. */
	public const APPLIED         = 'applied';
	public const ALREADY_APPLIED = 'already_applied';
	public const SUPERSEDED      = 'superseded';
	public const CONFLICT        = 'conflict';
	public const UNVERIFIED      = 'unverified';

	/**
	 * Highest intent sequence that can be packed without overflowing a PHP int.
	 *
	 * PHP integers are signed 64-bit, so `1 << 63` is negative. MySQL's
	 * BIGINT UNSIGNED would hold more, but PHP is the binding constraint and the
	 * bound is checked BEFORE any shift, cast or statement preparation.
	 */
	public static function max_intent_sequence(): int {
		return PHP_INT_MAX >> self::PHASE_BITS;
	}

	/**
	 * Pack an intent sequence and phase into the stored enforcement sequence.
	 *
	 * Numeric capacity only: this accepts any phase the field can hold. Whether
	 * a phase is a legal LIFECYCLE value is a separate question, answered by
	 * validate_state_phase().
	 *
	 * @param mixed $intent
	 * @param mixed $phase
	 * @return int|\WP_Error
	 */
	public static function pack( $intent, $phase ) {
		if ( ! self::is_strict_int( $intent ) || $intent < 0 || $intent > self::max_intent_sequence() ) {
			return self::error( 'sequence_out_of_range', 'The intent sequence is out of range.' );
		}
		if ( ! self::is_strict_int( $phase ) || $phase < 0 || $phase > self::MAX_PHASE ) {
			return self::error( 'invalid_phase', 'The sequence phase is invalid.' );
		}

		$packed = ( $intent << self::PHASE_BITS ) | $phase;
		if ( $packed < 0 ) {
			// Unreachable after the bounds above; kept so a future change to
			// PHASE_BITS cannot silently produce a negative sequence.
			return self::error( 'sequence_out_of_range', 'The intent sequence is out of range.' );
		}

		return $packed;
	}

	/**
	 * Record a Commerce state for one managed Hall.
	 *
	 * Parameters are deliberately untyped and validated explicitly. A scalar
	 * type declaration would let a weakly typed caller coerce 3.7 to 3, "5x" to
	 * 5 and true to 1 before this method ever saw the value.
	 *
	 * @param mixed $blog_id
	 * @param mixed $state
	 * @param mixed $intent
	 * @param mixed $phase
	 * @return array{outcome:string,blog_id:int,seq:int,state:string,stored:?array}|\WP_Error
	 */
	public static function write( $blog_id, $state, $intent, $phase ) {
		if ( ! self::is_strict_int( $blog_id ) ) {
			return self::error( 'invalid_blog_id', 'The WordPress site ID is invalid.' );
		}
		if ( ! is_string( $state ) || ! in_array( $state, self::STATES, true ) ) {
			return self::error( 'invalid_state', 'The Commerce state is not recognised.' );
		}

		$combination = self::validate_state_phase( $state, $phase );
		if ( is_wp_error( $combination ) ) {
			return $combination;
		}

		$seq = self::pack( $intent, $phase );
		if ( is_wp_error( $seq ) ) {
			return $seq;
		}

		/*
		 * Preconditions are database work too: get_site() and is_main_site() both
		 * query, and so does the schema-version read. A throw here must become a
		 * fixed category, not escape into the caller.
		 */
		try {
			$identity = self::validate_identity( $blog_id );
			if ( $identity instanceof \WP_Error ) {
				return $identity;
			}

			$eligible = self::validate_lifecycle( $identity );
			if ( $eligible instanceof \WP_Error ) {
				return $eligible;
			}

			if ( ! ProvisioningMigrations::is_current() ) {
				return self::error( 'state_schema_unavailable', 'The Commerce state schema is unavailable.' );
			}

			// Refused before a single row is touched: an unsupported mode would
			// make applied and already_applied indistinguishable after the fact.
			$mode = self::assert_connection_mode();
			if ( $mode instanceof \WP_Error ) {
				return $mode;
			}
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return self::error( 'state_precondition_failed', 'The Commerce state preconditions could not be evaluated.' );
		}

		global $wpdb;
		$table = ProvisioningMigrations::state_table();
		$now   = current_time( self::TIMESTAMP_FORMAT, true );

		/*
		 * `seq` is assigned LAST on purpose. MySQL evaluates ON DUPLICATE KEY
		 * UPDATE assignments left to right, so `state` and `updated_at` must be
		 * decided against the OLD sequence. Assigning seq first would advance the
		 * sequence while leaving the state and timestamp stale — a corrupt row
		 * that classify() below reports as CONFLICT rather than success.
		 */
		$sql = $wpdb->prepare(
			"INSERT INTO {$table} (blog_id, seq, state, updated_at)
			 VALUES (%d, %d, %s, %s)
			 ON DUPLICATE KEY UPDATE
			   state      = IF(VALUES(seq) > seq, VALUES(state), state),
			   updated_at = IF(VALUES(seq) > seq, VALUES(updated_at), updated_at),
			   seq        = IF(VALUES(seq) > seq, VALUES(seq), seq)",
			$blog_id,
			$seq,
			$state,
			$now
		);

		$previous = $wpdb->suppress_errors( true );
		try {
			$affected = $wpdb->query( $sql );
			$error    = (string) $wpdb->last_error;
		} catch ( \Throwable $throwable ) {
			// The exception object is never read: its message and trace can quote
			// SQL, stored values and connection details.
			unset( $throwable );

			return self::error( 'state_write_failed', 'The Commerce state could not be written.' );
		} finally {
			$wpdb->suppress_errors( $previous );
		}

		if ( false === $affected || '' !== $error ) {
			return self::error( 'state_write_failed', 'The Commerce state could not be written.' );
		}

		/*
		 * From here the statement has completed, so the row may already carry our
		 * values. Every remaining failure is UNVERIFIED, never an error: claiming
		 * nothing was written would be false.
		 */
		try {
			$stored = self::read( $blog_id );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return self::result( self::UNVERIFIED, $blog_id, $seq, $state, null );
		}

		// instanceof rather than is_wp_error(): no filter or callback dispatch
		// inside the post-write boundary, where a throw would lose the fact that
		// the row may already be stored.
		if ( $stored instanceof \WP_Error || ! is_array( $stored ) ) {
			return self::result( self::UNVERIFIED, $blog_id, $seq, $state, null );
		}

		return self::result( self::classify( $stored, $seq, $state, (int) $affected ), $blog_id, $seq, $state, $stored );
	}

	/**
	 * Classify a completed write from what the database now holds.
	 *
	 * The stored row decides everything except the final applied/already-applied
	 * split. Only once it confirms the requested sequence AND state may the
	 * affected-row count separate "this call wrote it" from "it already held
	 * exactly this" — and only because assert_connection_mode() has already
	 * refused a CLIENT_FOUND_ROWS connection, where 1 would mean either.
	 *
	 * @param array{blog_id:int,seq:int,state:string,updated_at:string} $stored
	 */
	private static function classify( array $stored, int $seq, string $state, int $affected ): string {
		if ( $stored['seq'] > $seq ) {
			return self::SUPERSEDED;
		}
		if ( $stored['seq'] < $seq ) {
			// The statement reported success yet the row is older than what we
			// wrote. Never report that as applied.
			return self::UNVERIFIED;
		}
		if ( $stored['state'] !== $state ) {
			// Equal sequence, different state: the guard correctly refused to
			// overwrite, and this is a genuine disagreement, not a retry.
			return self::CONFLICT;
		}

		/*
		 * Safe only because assert_connection_mode() has already refused a
		 * CLIENT_FOUND_ROWS connection, where an insert and an unchanged
		 * duplicate would both report 1.
		 */
		return 0 === $affected ? self::ALREADY_APPLIED : self::APPLIED;
	}

	/**
	 * Read one Hall's stored state.
	 *
	 * Returns null for a VERIFIED absence, and a WP_Error when the storage could
	 * not be read or the stored row is not trustworthy. Collapsing those would
	 * let an unreadable or corrupt table look like a Hall that simply has no
	 * state.
	 *
	 * @param mixed $blog_id
	 * @return array{blog_id:int,seq:int,state:string,updated_at:string}|null|\WP_Error
	 */
	public static function read( $blog_id ) {
		if ( ! self::is_strict_int( $blog_id ) || $blog_id < 1 ) {
			return self::error( 'invalid_blog_id', 'The WordPress site ID is invalid.' );
		}

		/*
		 * IDENTITY only, not lifecycle. A Hall must exist, belong to this network
		 * and not be the main site before its state means anything. Lifecycle
		 * eligibility is a WRITE concern: a suspended Hall whose site has since
		 * been marked deleted or spam must still have its recorded state
		 * readable, and an archived provisioning target must remain fully
		 * supported on both paths.
		 */
		try {
			$identity = self::validate_identity( $blog_id );
			if ( $identity instanceof \WP_Error ) {
				return $identity;
			}

			if ( ! ProvisioningMigrations::is_current() ) {
				return self::error( 'state_schema_unavailable', 'The Commerce state schema is unavailable.' );
			}
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return self::error( 'state_precondition_failed', 'The Commerce state preconditions could not be evaluated.' );
		}

		global $wpdb;
		$table = ProvisioningMigrations::state_table();

		$previous = $wpdb->suppress_errors( true );
		try {
			$row   = $wpdb->get_row(
				$wpdb->prepare( "SELECT blog_id, seq, state, updated_at FROM {$table} WHERE blog_id = %d", $blog_id ),
				ARRAY_A
			);
			$error = (string) $wpdb->last_error;
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return self::error( 'state_read_failed', 'The Commerce state could not be read.' );
		} finally {
			$wpdb->suppress_errors( $previous );
		}

		if ( '' !== $error ) {
			return self::error( 'state_read_failed', 'The Commerce state could not be read.' );
		}
		if ( ! is_array( $row ) ) {
			return null;
		}

		return self::validate_stored_row( $row, $blog_id );
	}

	/**
	 * Validate a stored row before returning it as trusted data.
	 *
	 * @param array<string,mixed> $row
	 * @return array{blog_id:int,seq:int,state:string,updated_at:string}|\WP_Error
	 */
	private static function validate_stored_row( array $row, int $blog_id ) {
		// Representation first, then value. Casting straight to int would turn
		// "12abc", "1e3" or a negative into a plausible-looking Hall ID.
		$raw_blog = isset( $row['blog_id'] ) ? (string) $row['blog_id'] : '';
		if ( 1 !== preg_match( '/^\d+$/', $raw_blog ) ) {
			return self::error( 'state_row_invalid', 'The stored Commerce Hall ID is malformed.' );
		}
		$stored_blog = (int) $raw_blog;
		if ( $stored_blog < 1 || (string) $stored_blog !== $raw_blog ) {
			return self::error( 'state_row_invalid', 'The stored Commerce Hall ID is out of range.' );
		}
		if ( $stored_blog !== $blog_id ) {
			return self::error( 'state_row_invalid', 'The stored Commerce state does not belong to this Hall.' );
		}

		$state = isset( $row['state'] ) ? (string) $row['state'] : '';
		if ( ! in_array( $state, self::STATES, true ) ) {
			return self::error( 'state_row_invalid', 'The stored Commerce state is not recognised.' );
		}

		$raw_seq = isset( $row['seq'] ) ? (string) $row['seq'] : '';
		if ( 1 !== preg_match( '/^\d+$/', $raw_seq ) ) {
			return self::error( 'state_row_invalid', 'The stored Commerce sequence is not a whole number.' );
		}
		$seq = (int) $raw_seq;
		if ( $seq < 0 || (string) $seq !== $raw_seq ) {
			// Beyond PHP's integer range, so it cannot be compared safely.
			return self::error( 'state_row_invalid', 'The stored Commerce sequence is out of range.' );
		}

		$phase = $seq & self::MAX_PHASE;
		if ( ( self::STATE_PHASES[ $state ] ?? null ) !== $phase ) {
			return self::error( 'state_row_invalid', 'The stored Commerce state and phase disagree.' );
		}

		$updated_at = isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '';
		if ( ! self::is_timestamp( $updated_at ) ) {
			return self::error( 'state_row_invalid', 'The stored Commerce timestamp is malformed.' );
		}

		return [
			'blog_id'    => $stored_blog,
			'seq'        => $seq,
			'state'      => $state,
			'updated_at' => $updated_at,
		];
	}

	/**
	 * Is this state/phase pair a legal lifecycle write?
	 *
	 * @param mixed $phase
	 * @return true|\WP_Error
	 */
	private static function validate_state_phase( string $state, $phase ) {
		if ( ! self::is_strict_int( $phase ) ) {
			return self::error( 'invalid_phase', 'The sequence phase is invalid.' );
		}

		$expected = self::STATE_PHASES[ $state ] ?? null;
		if ( null === $expected || $expected !== $phase ) {
			return self::error( 'invalid_state_phase', 'The Commerce state and sequence phase do not agree.' );
		}

		return true;
	}

	/**
	 * Site IDENTITY: does this Hall exist, on this network, and is it not the
	 * main site?
	 *
	 * @return \WP_Site|\WP_Error
	 */
	private static function validate_identity( int $blog_id ) {
		if ( ! is_multisite() ) {
			return self::error( 'multisite_required', 'Commerce state requires WordPress Multisite.' );
		}
		if ( $blog_id < 1 ) {
			return self::error( 'invalid_blog_id', 'The WordPress site ID is invalid.' );
		}

		$site = get_site( $blog_id );
		if ( ! $site instanceof \WP_Site ) {
			return self::error( 'site_not_found', 'The target WordPress site does not exist.' );
		}

		$network_id = get_current_network_id();
		if ( (int) $site->site_id !== $network_id ) {
			return self::error( 'site_wrong_network', 'The target WordPress site does not belong to the current network.' );
		}
		if ( is_main_site( $blog_id, $network_id ) ) {
			return self::error( 'main_site_not_allowed', 'The main network site is not a Hall.' );
		}

		return $site;
	}

	/**
	 * Site LIFECYCLE eligibility for a write.
	 *
	 * An ARCHIVED site is deliberately accepted: provisioning creates a site
	 * archived on purpose, so refusing one here would reject exactly the target
	 * this table exists to describe. Deleted and spam sites are refused.
	 *
	 * @return true|\WP_Error
	 */
	private static function validate_lifecycle( \WP_Site $site ) {
		if ( '1' === (string) $site->deleted ) {
			return self::error( 'site_deleted', 'A deleted WordPress site cannot carry Commerce state.' );
		}
		if ( '1' === (string) $site->spam ) {
			return self::error( 'site_spam', 'A spam WordPress site cannot carry Commerce state.' );
		}

		return true;
	}

	/**
	 * Strict integer test.
	 *
	 * is_int() rejects "5", 5.0, true and null, all of which a scalar type
	 * declaration would have silently coerced under weak typing.
	 *
	 * @param mixed $value
	 */
	private static function is_strict_int( $value ): bool {
		return is_int( $value );
	}

	private static function is_timestamp( string $value ): bool {
		$parsed = \DateTimeImmutable::createFromFormat( self::TIMESTAMP_FORMAT, $value, new \DateTimeZone( 'UTC' ) );

		// createFromFormat() rolls impossible calendar values forward instead of
		// failing, so an exact round-trip is required before the value is trusted.
		return $parsed instanceof \DateTimeImmutable && $value === $parsed->format( self::TIMESTAMP_FORMAT );
	}

	/** @param array<string,mixed>|null $stored */
	private static function result( string $outcome, int $blog_id, int $seq, string $state, ?array $stored ): array {
		return [
			'outcome' => $outcome,
			'blog_id' => $blog_id,
			'seq'     => $seq,
			'state'   => $state,
			'stored'  => $stored,
		];
	}

	/** Fixed categories only: no SQL, driver message, credential or trace. */
	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-portal-integration' ) );
	}
}
