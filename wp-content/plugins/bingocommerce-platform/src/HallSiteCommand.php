<?php

namespace BingoCommerce\Platform;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The validated `$command` passed through
 * `bingocommerce_platform_provision_hall_site`.
 *
 * DEFENSIVE BY CONTRACT, NOT BY HABIT. ADR-042 requires the platform consumer to
 * validate this command itself: it arrives through a public WordPress filter, so
 * any code on the installation can invoke the consumer with anything at all, and
 * "the orchestrator already checked it" is not a property this plugin can
 * verify. Every field is therefore re-checked here in its ORIGINAL
 * representation, and the key set is closed in both directions — an unexpected
 * key is a command this build does not understand, and acting on the subset it
 * recognises would be acting on a different command than the caller sent.
 *
 * A MALFORMED COMMAND IS A PROGRAMMING FAILURE, NOT A CLIENT ERROR. It returns
 * the fixed `internal_error` and performs no platform side effect, and no raw
 * value ever reaches the error, the log or the caller.
 */
final class HallSiteCommand {

	/** Lowercase canonical UUID v4 with hyphens. */
	public const UUID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

	/** Lowercase hex SHA-256. */
	public const FINGERPRINT_PATTERN = '/\A[0-9a-f]{64}\z/';

	/** Hall-name limit in UNICODE CHARACTERS, matching the Portal contract. */
	public const HALL_NAME_MAX = 255;

	/** The exact key set, and nothing else. */
	public const KEYS = [
		'portal_hall_id',
		'operation_id',
		'site_ownership_id',
		'site_slug',
		'hall_name',
		'blog_id',
		'request_fingerprint',
	];

	private function __construct(
		public readonly int $portal_hall_id,
		public readonly string $operation_id,
		public readonly string $site_ownership_id,
		public readonly string $site_slug,
		public readonly ?string $hall_name,
		public readonly ?int $blog_id,
		public readonly string $request_fingerprint
	) {}

	/**
	 * @param mixed $command
	 * @return self|\WP_Error Fixed `internal_error` on any malformed field.
	 */
	public static function fromFilterArgument( $command ) {
		if ( ! is_array( $command ) ) {
			return self::invalid();
		}

		$actual = array_keys( $command );
		sort( $actual );
		$expected = self::KEYS;
		sort( $expected );
		if ( $actual !== $expected ) {
			return self::invalid();
		}

		$portal_hall_id = $command['portal_hall_id'];
		if ( ! is_int( $portal_hall_id ) || $portal_hall_id < 1 ) {
			return self::invalid();
		}

		foreach ( [ 'operation_id', 'site_ownership_id' ] as $uuid_field ) {
			if ( ! is_string( $command[ $uuid_field ] )
				|| 1 !== preg_match( self::UUID_PATTERN, $command[ $uuid_field ] ) ) {
				return self::invalid();
			}
		}

		/*
		 * The ownership marker and the current Portal command identity must be
		 * different values. They are generated independently, so equality means
		 * a caller has confused the rotating command UUID with the permanent
		 * ownership UUID — which is the one substitution ADR-042 forbids
		 * outright, because it would write `operation_id` into
		 * `bc_commerce_managed`.
		 */
		if ( hash_equals( (string) $command['operation_id'], (string) $command['site_ownership_id'] ) ) {
			return self::invalid();
		}

		$site_slug = $command['site_slug'];
		if ( ! is_string( $site_slug )
			|| strlen( $site_slug ) > HallSiteAddress::SLUG_MAX_OCTETS
			|| 1 !== preg_match( HallSiteAddress::SLUG_PATTERN, $site_slug ) ) {
			return self::invalid();
		}

		$hall_name = $command['hall_name'];
		if ( null !== $hall_name && ! self::is_valid_hall_name( $hall_name ) ) {
			return self::invalid();
		}

		$blog_id = $command['blog_id'];
		if ( null !== $blog_id && ( ! is_int( $blog_id ) || $blog_id < 1 ) ) {
			return self::invalid();
		}

		$fingerprint = $command['request_fingerprint'];
		if ( ! is_string( $fingerprint ) || 1 !== preg_match( self::FINGERPRINT_PATTERN, $fingerprint ) ) {
			return self::invalid();
		}

		/*
		 * A RELATIONAL RULE, not a field rule: a CREATE command must name the
		 * Hall it is creating a site for.
		 *
		 * `blog_id` null means "resolve the reserved address, then create or
		 * recover", and `hall_name` null is correct only on a command that has
		 * something to inherit — a bound site already carries its title. Both
		 * null together describes a create with no title at all, which
		 * `wpmu_create_blog()` would silently paper over by falling back to the
		 * slug. Refused as the integration mistake it is: ADR-042 requires a
		 * first operational command to carry a Hall name, so a command that
		 * reaches this consumer without one did not come from that path.
		 */
		if ( null === $blog_id && null === $hall_name ) {
			return self::invalid();
		}

		return new self(
			$portal_hall_id,
			$command['operation_id'],
			$command['site_ownership_id'],
			$site_slug,
			$hall_name,
			$blog_id,
			$fingerprint
		);
	}

	/**
	 * The site title this command implies.
	 *
	 * A Hall name is a DISPLAY ATTRIBUTE, never identity, so it is used only to
	 * give the new site a human-readable title and is never compared, matched or
	 * stored as a key. When it is null — every suspend command and every
	 * post-ownership operational command — the slug is used instead, because
	 * `wpmu_create_blog()` requires a non-empty title and the slug is the one
	 * value guaranteed present.
	 */
	public function siteTitle(): string {
		return ( null !== $this->hall_name && '' !== $this->hall_name )
			? $this->hall_name
			: $this->site_slug;
	}

	/**
	 * The committed Portal Hall-name contract, applied to the ORIGINAL string.
	 *
	 * Valid UTF-8, no control/format characters or line/paragraph separators, at
	 * least one non-whitespace non-separator character, at most HALL_NAME_MAX
	 * Unicode characters. Never trimmed or rewritten.
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

		$length = function_exists( 'mb_strlen' )
			? (int) mb_strlen( $value, 'UTF-8' )
			: preg_match_all( '/./us', $value );

		return false !== $length && $length <= self::HALL_NAME_MAX;
	}

	/** One fixed category. No field name, value or diagnostic escapes. */
	private static function invalid(): \WP_Error {
		return new \WP_Error(
			'internal_error',
			__( 'The platform provisioning command is not usable.', 'bingocommerce-platform' )
		);
	}
}
