<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ONE Commerce access decision for one Hall.
 *
 * Every caller — the request gate, the manual Portal synchronization entry
 * point, and anything a later increment adds — asks this class. There is no
 * second state parser and no competing policy object: the stored row is read
 * through HallCommerceState, which already owns validation, and tenant identity
 * comes from the same place it always has.
 *
 * TWO INDEPENDENT FACTS DECIDE ACCESS, and they are deliberately kept apart:
 *
 *   1. the Commerce-state row, which says what this Hall's lifecycle currently
 *      is; and
 *   2. the durable managed-Hall witness `bc_commerce_managed`, which says
 *      whether this Hall is one BingoCommerce manages at all.
 *
 * The witness exists because ABSENCE OF STATE IS AMBIGUOUS. A Hall with no row
 * is either a legacy Hall that predates this table — which must keep working —
 * or a managed Hall whose row is missing, unreadable, or not yet written, which
 * must not be served as though it were open for business. The witness is what
 * separates those two, so a missing schema, a missing row, or a database error
 * can never quietly promote a managed Hall to "allowed".
 *
 * SCHEMA-VERSION METADATA DECIDES NOTHING HERE. HallCommerceState::read() no
 * longer gates on the recorded migration version, so an intact suspended or
 * provisioning row is authoritative even when
 * `bc_portal_provisioning_db_version` is absent, unreadable or behind — which
 * is the case where a missing witness would previously have promoted a
 * genuinely suspended Hall to legacy access. What the reader still reports as a
 * distinct error is storage that could not be CONSULTED, and only that leads
 * here to the witness. Absence of version metadata is never, by itself, allow.
 *
 * NOTHING HERE WRITES. No marker is created, deleted or backfilled, no state is
 * mutated, no schema is migrated, no Hall mapping is touched, and no Portal
 * request is made. Evaluation is two indexed lookups at most — the state row by
 * primary key, and the witness by option name in the target Hall's own options
 * table — and the witness is only read when the state result cannot decide on
 * its own.
 *
 * NOTHING HERE IS CACHED. A cached allow could outlive the suspension that was
 * meant to end it, so every call re-reads. The lookups are indexed and the cost
 * is bounded; correctness under suspension is worth more than the two queries.
 */
final class HallCommerceAccess {

	/** The durable managed-Hall witness. Separate option, separate lifetime. */
	public const MARKER_OPTION = 'bc_commerce_managed';

	/**
	 * The witness stores the ENROLLING OPERATION'S lowercase UUID v4.
	 *
	 * Not a flag. A flag says only "someone once set something here"; an
	 * operation identifier says WHICH enrolment produced this Hall, which is
	 * what makes the marker auditable against the operation that wrote it and
	 * what a later increment needs in order to reconcile a half-finished
	 * enrolment. `wp_generate_uuid4()` — already used for operation ids
	 * elsewhere in this plugin — emits exactly this form.
	 *
	 * Strictly lowercase, strictly version 4, strictly variant 1 (the `4` and
	 * the `[89ab]` below). Anything else stored in this option is INVALID, not
	 * absent: a Hall carrying an unrecognised witness is still a Hall someone
	 * wrote a witness for, and must never fall through to legacy access.
	 *
	 * ANCHORED WITH \A AND \z, NOT ^ AND $. In PCRE `$` also matches
	 * immediately before a final newline, so `^...$` would accept a valid UUID
	 * with a trailing LF — and an option value is arbitrary stored text that can
	 * carry one. \z matches only the absolute end of the subject, so
	 * "<uuid>\n" and "<uuid>\r\n" are rejected like any other malformed value.
	 */
	public const MARKER_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

	/* The witness read has exactly four outcomes. */
	public const MARKER_VALID      = 'valid';
	public const MARKER_INVALID    = 'invalid';
	public const MARKER_ABSENT     = 'absent';
	public const MARKER_UNREADABLE = 'unreadable';

	/* Access verdicts. */
	public const ALLOW = 'allow';
	public const BLOCK = 'block';

	/* Fixed reason categories. No SQL, driver text, stored value or trace. */
	public const REASON_OPERATIONAL           = 'operational';
	public const REASON_LEGACY                = 'legacy';
	public const REASON_NOT_A_HALL            = 'not_a_hall';
	public const REASON_PROVISIONING          = 'provisioning';
	public const REASON_SUSPENDED             = 'suspended';
	public const REASON_STATE_INVALID         = 'state_invalid';
	public const REASON_MANAGED_WITHOUT_STATE = 'managed_without_state';
	public const REASON_STATE_UNAVAILABLE     = 'state_unavailable';
	public const REASON_UNDETERMINED          = 'undetermined';

	/* How a state read is classified before the policy is applied. */
	private const STATE_ROW         = 'row';
	private const STATE_ABSENT      = 'absent';
	private const STATE_INVALID     = 'invalid';
	private const STATE_UNAVAILABLE = 'unavailable';
	private const STATE_NOT_A_HALL  = 'not_a_hall';
	private const STATE_UNUSABLE    = 'unusable';

	/**
	 * Error codes that mean THE STORED ROW ITSELF is not trustworthy.
	 *
	 * These never reach the witness. A corrupt row is positive evidence that
	 * this Hall has Commerce state, so classifying it as legacy would turn
	 * damaged data into open access — the exact inversion this list prevents.
	 */
	private const INVALID_ROW_CODES = [ 'state_row_invalid' ];

	/**
	 * Error codes that mean THE STORAGE could not be consulted at all.
	 *
	 * `state_schema_unavailable` means the state TABLE is absent;
	 * `state_read_failed` that it is present but unreadable. Both are
	 * established from the storage itself, not from version metadata, and both
	 * mean the same thing to this policy: nothing is known about this Hall's
	 * state, so the witness decides.
	 *
	 * `state_precondition_failed` is deliberately ABSENT from this list. The
	 * reader returns it when Hall IDENTITY validation throws — the site is
	 * missing, on another network, or could not be established at all — and that
	 * is not "this Hall has no state", it is "there is no proven Hall here".
	 * Letting it reach the witness would mean an unprovable identity with no
	 * marker returned `allow / legacy`: an unidentified site served as an
	 * ordinary pre-Commerce Hall. Identity failures therefore fall through to
	 * STATE_UNUSABLE and block, and the witness is never consulted for them.
	 */
	private const UNAVAILABLE_CODES = [
		'state_schema_unavailable',
		'state_read_failed',
	];

	/**
	 * Decide Commerce access for one Hall.
	 *
	 * @param mixed $blog_id
	 * @return array{access:string,reason:string,state:?string,marker:?string,blog_id:int}
	 */
	public static function evaluate( $blog_id ): array {
		// is_int() rather than a scalar type declaration: a weakly typed caller
		// would otherwise coerce "3x", 3.7 or true into a Hall identifier.
		if ( ! is_int( $blog_id ) || $blog_id < 1 ) {
			return self::verdict( self::BLOCK, self::REASON_UNDETERMINED, 0 );
		}

		try {
			/*
			 * Without Multisite there are no Halls to isolate, so there is
			 * nothing this policy can be about. Refusing here would brick an
			 * installation the policy does not describe.
			 */
			if ( ! is_multisite() ) {
				return self::verdict( self::ALLOW, self::REASON_NOT_A_HALL, $blog_id );
			}

			// The main network site is Bingo Innovation's, not a Hall's. It is
			// never blocked, and it is never evaluated further.
			if ( is_main_site( $blog_id ) ) {
				return self::verdict( self::ALLOW, self::REASON_NOT_A_HALL, $blog_id );
			}

			$state = HallCommerceState::read( $blog_id );
		} catch ( \Throwable $throwable ) {
			// Never read: its message and trace can quote SQL and stored values.
			unset( $throwable );

			return self::verdict( self::BLOCK, self::REASON_UNDETERMINED, $blog_id );
		}

		switch ( self::classify_state( $state ) ) {
			case self::STATE_ROW:
				return self::decide_from_row( (string) $state['state'], $blog_id );

			case self::STATE_NOT_A_HALL:
				return self::verdict( self::ALLOW, self::REASON_NOT_A_HALL, $blog_id );

			case self::STATE_INVALID:
				/*
				 * Deliberately no witness lookup. An invalid row is never legacy,
				 * so no witness outcome could change this answer.
				 */
				return self::verdict( self::BLOCK, self::REASON_STATE_INVALID, $blog_id );

			case self::STATE_ABSENT:
				return self::decide_from_marker( $blog_id, self::REASON_MANAGED_WITHOUT_STATE );

			case self::STATE_UNAVAILABLE:
				return self::decide_from_marker( $blog_id, self::REASON_STATE_UNAVAILABLE );

			default:
				/*
				 * Identity could not be established — the site is missing, on
				 * another network, or the identifier was rejected. Nothing is
				 * known and nothing can be, so fail closed.
				 */
				return self::verdict( self::BLOCK, self::REASON_UNDETERMINED, $blog_id );
		}
	}

	/** Convenience predicate. Blocked is the answer for anything undecidable. */
	public static function is_blocked( $blog_id ): bool {
		return self::BLOCK === self::evaluate( $blog_id )['access'];
	}

	/**
	 * Classify what HallCommerceState::read() returned.
	 *
	 * @param mixed $state
	 */
	private static function classify_state( $state ): string {
		if ( is_array( $state ) && isset( $state['state'] ) ) {
			return self::STATE_ROW;
		}
		if ( null === $state ) {
			// A VERIFIED absence: the storage answered and held no row.
			return self::STATE_ABSENT;
		}

		// instanceof rather than is_wp_error(): no filter or callback dispatch
		// inside an access decision.
		if ( $state instanceof \WP_Error ) {
			$code = (string) $state->get_error_code();

			if ( 'main_site_not_allowed' === $code ) {
				return self::STATE_NOT_A_HALL;
			}
			if ( in_array( $code, self::INVALID_ROW_CODES, true ) ) {
				return self::STATE_INVALID;
			}
			if ( in_array( $code, self::UNAVAILABLE_CODES, true ) ) {
				return self::STATE_UNAVAILABLE;
			}
		}

		return self::STATE_UNUSABLE;
	}

	/**
	 * A valid stored row decides on its own. The witness is not consulted.
	 *
	 * @return array{access:string,reason:string,state:?string,marker:?string,blog_id:int}
	 */
	private static function decide_from_row( string $state, int $blog_id ): array {
		if ( HallCommerceState::OPERATIONAL === $state ) {
			return self::verdict( self::ALLOW, self::REASON_OPERATIONAL, $blog_id, $state );
		}
		if ( HallCommerceState::PROVISIONING === $state ) {
			return self::verdict( self::BLOCK, self::REASON_PROVISIONING, $blog_id, $state );
		}
		if ( HallCommerceState::SUSPENDED === $state ) {
			return self::verdict( self::BLOCK, self::REASON_SUSPENDED, $blog_id, $state );
		}

		/*
		 * Unreachable while HallCommerceState validates the stored value against
		 * its own allowlist. Kept so a future state added there without a rule
		 * here fails closed instead of falling through to allow.
		 */
		return self::verdict( self::BLOCK, self::REASON_STATE_INVALID, $blog_id, $state );
	}

	/**
	 * No usable state result: only a VERIFIED ABSENT witness preserves legacy
	 * access.
	 *
	 * A present witness — valid or invalid — is positive evidence that this Hall
	 * is managed, so the missing or unreadable state blocks. An unreadable
	 * witness proves nothing either way, and "we could not ask" must never be
	 * served as "open".
	 *
	 * @return array{access:string,reason:string,state:?string,marker:?string,blog_id:int}
	 */
	private static function decide_from_marker( int $blog_id, string $blocked_reason ): array {
		$marker = self::read_marker( $blog_id );

		if ( self::MARKER_ABSENT === $marker ) {
			return self::verdict( self::ALLOW, self::REASON_LEGACY, $blog_id, null, $marker );
		}
		if ( self::MARKER_UNREADABLE === $marker ) {
			return self::verdict( self::BLOCK, self::REASON_UNDETERMINED, $blog_id, null, $marker );
		}

		return self::verdict( self::BLOCK, $blocked_reason, $blog_id, null, $marker );
	}

	/**
	 * Read the managed-Hall witness straight out of the target Hall's options
	 * table.
	 *
	 * NOT get_option(). Three reasons, all of them correctness rather than
	 * style: get_option() would need switch_to_blog() to reach another Hall, it
	 * cannot report a database error — it returns the default — and a negative
	 * `notoptions` cache entry would let a CACHED MISS establish absence. This
	 * decision may only ever be made from storage that actually answered.
	 *
	 * The table name is composed from $wpdb->base_prefix and an integer, never
	 * from request data, and is re-checked against the identifier grammar before
	 * it reaches a statement; the one bound value goes through prepare().
	 *
	 * @return string One of MARKER_VALID, MARKER_INVALID, MARKER_ABSENT, MARKER_UNREADABLE.
	 */
	public static function read_marker( $blog_id ): string {
		return self::read_marker_value( $blog_id )['status'];
	}

	/**
	 * The same witness read, keeping the stored value for callers that need it.
	 *
	 * ADR-042's durable ownership requires comparing the witness against the
	 * operation row's permanent `site_ownership_id`, which a classification
	 * alone cannot answer. The value is returned ONLY for a valid witness; an
	 * invalid, absent or unreadable one yields null, so no unvalidated stored
	 * text can escape this method.
	 *
	 * @param mixed $blog_id
	 * @return array{status:string,value:?string}
	 */
	public static function read_marker_value( $blog_id ): array {
		if ( ! is_int( $blog_id ) || $blog_id < 1 ) {
			return self::marker_result( self::MARKER_UNREADABLE );
		}

		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return self::marker_result( self::MARKER_UNREADABLE );
		}

		$table = $wpdb->get_blog_prefix( $blog_id ) . 'options';
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			return self::marker_result( self::MARKER_UNREADABLE );
		}

		$previous = $wpdb->suppress_errors( true );
		try {
			/*
			 * get_row(), NOT get_var(). wpdb::get_var() returns null for an
			 * empty string as well as for no row at all, which would turn a
			 * witness stored as '' into a VERIFIED ABSENCE and hand a managed
			 * Hall legacy access. WordPress core avoids get_var() in
			 * get_option() for the same reason, in the same situation.
			 */
			$row   = $wpdb->get_row(
				$wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1", self::MARKER_OPTION ),
				ARRAY_A
			);
			$error = (string) $wpdb->last_error;
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return self::marker_result( self::MARKER_UNREADABLE );
		} finally {
			// Restored on every path, including a throw, so one witness read
			// cannot alter error reporting for the rest of the request.
			$wpdb->suppress_errors( $previous );
		}

		if ( '' !== $error ) {
			// A missing options table lands here too: the Hall could not be
			// asked, which is not the same as answering "no".
			return self::marker_result( self::MARKER_UNREADABLE );
		}
		if ( ! is_array( $row ) || ! array_key_exists( 'option_value', $row ) ) {
			return self::marker_result( self::MARKER_ABSENT );
		}

		return self::is_valid_marker( $row['option_value'] )
			? self::marker_result( self::MARKER_VALID, (string) $row['option_value'] )
			: self::marker_result( self::MARKER_INVALID );
	}

	/** @return array{status:string,value:?string} */
	private static function marker_result( string $status, ?string $value = null ): array {
		return [ 'status' => $status, 'value' => $value ];
	}

	/**
	 * Is this stored value a well-formed managed-Hall witness?
	 *
	 * Deliberately total: EVERY value that is not a lowercase UUID v4 — an
	 * empty string, a legacy `1`, an uppercase UUID, a v1/v3/v5 UUID, a padded
	 * one — is invalid. None of them is an absence.
	 *
	 * @param mixed $value
	 */
	public static function is_valid_marker( $value ): bool {
		// is_string() first: a stored value is always a string, but a caller
		// passing an array must not reach preg_match().
		return is_string( $value ) && 1 === preg_match( self::MARKER_PATTERN, $value );
	}

	/**
	 * @return array{access:string,reason:string,state:?string,marker:?string,blog_id:int}
	 */
	private static function verdict( string $access, string $reason, int $blog_id, ?string $state = null, ?string $marker = null ): array {
		return [
			'access'  => $access,
			'reason'  => $reason,
			'state'   => $state,
			'marker'  => $marker,
			'blog_id' => $blog_id,
		];
	}
}
