<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ADR-042 Canonical Request v1: closed field validation and the injective
 * request fingerprint.
 *
 * VALIDATION HAPPENS BEFORE ANY CAST, SHIFT, HASH, SQL OR PERSISTENCE. Every
 * field arrives from an external system, so each one is checked in its ORIGINAL
 * representation and only then admitted. A scalar type declaration would have
 * let a weakly typed caller turn 3.7 into 3, "7x" into 7 and true into 1 before
 * this class ever saw the value, so the public entry points are deliberately
 * untyped and check `is_int()` / `is_string()` explicitly.
 *
 * THE FINGERPRINT IS INJECTIVE BY CONSTRUCTION. Each of the eight records is
 * typed and, for strings, length-prefixed, so a JSON null can never collide
 * with a string — not even with the literal characters `null` — and no
 * accepted value can shift bytes from one field into another. The byte length,
 * not a delimiter, is authoritative.
 *
 * NOTHING HERE PERSISTS, LOCKS, AUTHENTICATES OR REGISTERS A ROUTE. It is a
 * pure function of its input.
 */
final class ProvisioningCanonicalRequest {

	/** The only canonical version this build produces or accepts. */
	public const CANONICAL_VERSION = 1;

	public const OPERATIONAL = 'operational';
	public const SUSPENDED   = 'suspended';

	/** Closed `desired_state` vocabulary. */
	public const DESIRED_STATES = [ self::OPERATIONAL, self::SUSPENDED ];

	/** Lowercase canonical UUID v4 with hyphens. Nothing else is a UUID here. */
	public const UUID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

	/** A single DNS label. Leading/trailing hyphens and underscores excluded. */
	public const SLUG_PATTERN = '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/';

	/** Maximum slug length in octets. The pattern already bounds it; both hold. */
	public const SLUG_MAX_OCTETS = 63;

	/**
	 * Hall-name limit in UNICODE CHARACTERS, matching the committed Portal
	 * snapshot contract (`PortalSnapshotValidator::HALL_NAME_MAX`). A 255-
	 * character multibyte name occupies up to 765 bytes and must be accepted.
	 */
	public const HALL_NAME_MAX = 255;

	/** Lowercase hex SHA-256. */
	public const FINGERPRINT_PATTERN = '/\A[0-9a-f]{64}\z/';

	/**
	 * Upper bound on `intent_sequence`.
	 *
	 * Matches HallCommerceState's packed-sequence capacity, because an accepted
	 * intent sequence must remain representable there. PHP's signed 64-bit
	 * integer is the binding constraint, not MySQL's BIGINT UNSIGNED, and the
	 * bound is enforced before any shift or statement preparation.
	 */
	public static function max_intent_sequence(): int {
		return PHP_INT_MAX >> HallCommerceState::PHASE_BITS;
	}

	/**
	 * Validate one Portal command and return it in canonical form.
	 *
	 * Returns the exact seven canonical values plus the computed fingerprint and
	 * canonical version. No value is trimmed, normalised, lowercased or rewritten:
	 * an accepted Hall name is stored and hashed exactly as it arrived.
	 *
	 * @param mixed $input Associative array of raw decoded JSON plus the path ID.
	 * @return array{
	 *     portal_hall_id:int, operation_id:string, intent_sequence:int,
	 *     desired_state:string, site_slug:?string, hall_name:?string,
	 *     portal_actor_id:int, request_canonical_version:int,
	 *     request_fingerprint:string
	 * }|\WP_Error
	 */
	public static function validate( $input ) {
		if ( ! is_array( $input ) ) {
			return self::invalid( 'The provisioning command is not an object.' );
		}

		/*
		 * The key set is closed in both directions. A missing field cannot be
		 * defaulted, because every one of them is part of the fingerprint; an
		 * unexpected field cannot be ignored, because a field this build does
		 * not hash is a field Portal could change without changing the
		 * fingerprint.
		 */
		$expected = [
			'portal_hall_id',
			'operation_id',
			'intent_sequence',
			'desired_state',
			'site_slug',
			'hall_name',
			'portal_actor_id',
		];
		$actual   = array_keys( $input );
		sort( $actual );
		$sorted_expected = $expected;
		sort( $sorted_expected );
		if ( $actual !== $sorted_expected ) {
			return self::invalid( 'The provisioning command fields are not exactly the expected set.' );
		}

		$portal_hall_id = $input['portal_hall_id'];
		if ( ! is_int( $portal_hall_id ) || $portal_hall_id < 1 ) {
			return self::invalid( 'The Portal Hall ID is invalid.' );
		}

		$operation_id = $input['operation_id'];
		if ( ! is_string( $operation_id ) || 1 !== preg_match( self::UUID_PATTERN, $operation_id ) ) {
			return self::invalid( 'The operation ID is not a canonical lowercase UUID v4.' );
		}

		$intent_sequence = $input['intent_sequence'];
		if ( ! is_int( $intent_sequence )
			|| $intent_sequence < 1
			|| $intent_sequence > self::max_intent_sequence() ) {
			return self::invalid( 'The intent sequence is out of range.' );
		}

		$desired_state = $input['desired_state'];
		if ( ! is_string( $desired_state ) || ! in_array( $desired_state, self::DESIRED_STATES, true ) ) {
			return self::invalid( 'The desired state is not recognised.' );
		}

		$site_slug = $input['site_slug'];
		if ( null !== $site_slug ) {
			if ( ! is_string( $site_slug )
				|| strlen( $site_slug ) > self::SLUG_MAX_OCTETS
				|| 1 !== preg_match( self::SLUG_PATTERN, $site_slug ) ) {
				return self::invalid( 'The site slug is not a valid DNS label.' );
			}
		}

		$hall_name = $input['hall_name'];
		if ( null !== $hall_name && ! self::is_valid_hall_name( $hall_name ) ) {
			return self::invalid( 'The Hall name is invalid.' );
		}

		$portal_actor_id = $input['portal_actor_id'];
		if ( ! is_int( $portal_actor_id ) || $portal_actor_id < 1 ) {
			return self::invalid( 'The Portal actor ID is invalid.' );
		}

		/*
		 * Request-level shape rule, independent of anything stored: EVERY new
		 * suspended command carries JSON-null slug and name (ADR-042 null rule
		 * 4), before or after durable ownership. The operational nullability
		 * rule is deliberately NOT enforced here — it depends on whether durable
		 * ownership already exists, which is a classification-time question.
		 */
		if ( self::SUSPENDED === $desired_state && ( null !== $site_slug || null !== $hall_name ) ) {
			return self::invalid( 'A suspend command must carry a null site slug and Hall name.' );
		}

		$canonical = [
			'portal_hall_id'  => $portal_hall_id,
			'operation_id'    => $operation_id,
			'intent_sequence' => $intent_sequence,
			'desired_state'   => $desired_state,
			'site_slug'       => $site_slug,
			'hall_name'       => $hall_name,
			'portal_actor_id' => $portal_actor_id,
		];

		$fingerprint = self::fingerprint( $canonical );
		if ( $fingerprint instanceof \WP_Error ) {
			return $fingerprint;
		}

		$canonical['request_canonical_version'] = self::CANONICAL_VERSION;
		$canonical['request_fingerprint']       = $fingerprint;

		return $canonical;
	}

	/**
	 * The exact Canonical Request v1 digest input.
	 *
	 * Eight newline-terminated records; the input ends with the eighth record's
	 * trailing newline. Built only from already-validated values, so the caller
	 * must not reach this with unchecked input — validate() is the entry point.
	 *
	 * @param array<string,mixed> $canonical
	 * @return string|\WP_Error
	 */
	public static function canonical_string( array $canonical ) {
		$records = [ 'v1' ];

		foreach ( [ 'portal_hall_id', 'operation_id', 'intent_sequence', 'desired_state', 'site_slug', 'hall_name', 'portal_actor_id' ] as $field ) {
			if ( ! array_key_exists( $field, $canonical ) ) {
				return self::invalid( 'The canonical request is incomplete.' );
			}
		}

		$records[] = self::unsigned_record( 'portal_hall_id', $canonical['portal_hall_id'] );
		$records[] = self::string_record( 'operation_id', $canonical['operation_id'] );
		$records[] = self::unsigned_record( 'intent_sequence', $canonical['intent_sequence'] );
		$records[] = self::string_record( 'desired_state', $canonical['desired_state'] );
		$records[] = self::nullable_record( 'site_slug', $canonical['site_slug'] );
		$records[] = self::nullable_record( 'hall_name', $canonical['hall_name'] );
		$records[] = self::unsigned_record( 'portal_actor_id', $canonical['portal_actor_id'] );

		foreach ( $records as $record ) {
			if ( $record instanceof \WP_Error ) {
				return $record;
			}
		}

		if ( 8 !== count( $records ) ) {
			// Unreachable while the field list above is eight long; kept so a
			// future field cannot silently change the digest input's shape.
			return self::invalid( 'The canonical request record count is wrong.' );
		}

		return implode( "\n", $records ) . "\n";
	}

	/**
	 * Lowercase SHA-256 over the canonical string's UTF-8 bytes.
	 *
	 * @param array<string,mixed> $canonical
	 * @return string|\WP_Error
	 */
	public static function fingerprint( array $canonical ) {
		$string = self::canonical_string( $canonical );
		if ( $string instanceof \WP_Error ) {
			return $string;
		}

		// hash() already returns lowercase hex; the explicit call documents that
		// the stored and compared form is lowercase and nothing else.
		return strtolower( hash( 'sha256', $string ) );
	}

	public static function is_fingerprint( $value ): bool {
		return is_string( $value ) && 1 === preg_match( self::FINGERPRINT_PATTERN, $value );
	}

	public static function is_uuid( $value ): bool {
		return is_string( $value ) && 1 === preg_match( self::UUID_PATTERN, $value );
	}

	public static function is_slug( $value ): bool {
		return is_string( $value )
			&& strlen( $value ) <= self::SLUG_MAX_OCTETS
			&& 1 === preg_match( self::SLUG_PATTERN, $value );
	}

	/**
	 * The committed Portal Hall-name contract, applied to the ORIGINAL string.
	 *
	 * Mirrors PortalSnapshotValidator: valid UTF-8, no control/format characters
	 * or line/paragraph separators, at least one non-whitespace non-separator
	 * character, and at most HALL_NAME_MAX Unicode characters. The value is never
	 * trimmed or rewritten, because an accepted name is a display attribute that
	 * must round-trip byte-for-byte through the fingerprint.
	 *
	 * @param mixed $value
	 */
	public static function is_valid_hall_name( $value ): bool {
		if ( ! is_string( $value ) || 1 !== preg_match( '//u', $value ) ) {
			return false;
		}

		if ( 0 !== preg_match( '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value )
			|| 1 !== preg_match( '/[^\s\p{Z}]/u', $value ) ) {
			return false;
		}

		$length = self::unicode_length( $value );

		return null !== $length && $length <= self::HALL_NAME_MAX;
	}

	/**
	 * Unicode character count, or null when it cannot be counted safely.
	 *
	 * Null rejects the name rather than falling back to a byte length, which
	 * would accept names longer than the Portal column allows.
	 */
	private static function unicode_length( string $value ): ?int {
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $value, 'UTF-8' );
		}

		$count = preg_match_all( '/./us', $value );

		return false === $count ? null : (int) $count;
	}

	/**
	 * `u:<name>:<decimal>` — unsigned decimal with no leading zeros.
	 *
	 * @param mixed $value
	 * @return string|\WP_Error
	 */
	private static function unsigned_record( string $name, $value ) {
		if ( ! is_int( $value ) || $value < 0 ) {
			return self::invalid( 'A canonical integer field is invalid.' );
		}

		// (string) of a non-negative int never carries a leading zero or sign.
		return 'u:' . $name . ':' . (string) $value;
	}

	/**
	 * `s:<name>:<byte_length>:<raw bytes>` — length-prefixed, unescaped.
	 *
	 * @param mixed $value
	 * @return string|\WP_Error
	 */
	private static function string_record( string $name, $value ) {
		if ( ! is_string( $value ) ) {
			return self::invalid( 'A canonical string field is invalid.' );
		}

		return 's:' . $name . ':' . strlen( $value ) . ':' . $value;
	}

	/**
	 * `n:<name>` for JSON null, otherwise the length-prefixed string record.
	 *
	 * This is where injectivity lives: the null marker carries no length and no
	 * payload, so no string — including the four characters `null` — can ever
	 * produce the same record.
	 *
	 * @param mixed $value
	 * @return string|\WP_Error
	 */
	private static function nullable_record( string $name, $value ) {
		if ( null === $value ) {
			return 'n:' . $name;
		}

		return self::string_record( $name, $value );
	}

	/** Fixed categories only: no SQL, driver text, payload echo or credential. */
	private static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'validation_failed', __( $message, 'bingocommerce-portal-integration' ) );
	}
}
