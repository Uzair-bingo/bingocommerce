<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ENTIRE boundary between this plugin and `bingocommerce-platform`.
 *
 * Two calls cross it and nothing else does, so the cross-plugin surface is one
 * reviewable file rather than a scattering of `class_exists()` checks:
 *
 *   - resolveAddress() — a READ. WordPress network topology is platform-owned
 *     (ADR-042 plugin ownership), and ADR-042 requires BOTH the orchestrator
 *     (before an ownership UUID is minted for an address) and the platform
 *     consumer (before it creates) to resolve the reserved address. Keeping a
 *     second copy of that resolution here is exactly what "Business logic MUST
 *     NOT be duplicated between plugins" forbids, so there is one resolver and
 *     this plugin reads it.
 *
 *   - provisionHallSite() — the single MUTATING contract,
 *     `bingocommerce_platform_provision_hall_site`. This plugin never creates or
 *     initializes a WordPress site itself, and there is deliberately no local
 *     fallback for when nothing answers.
 *
 * A MISSING PLATFORM PLUGIN IS `platform_unavailable` ON BOTH CALLS. That is the
 * same answer a filter with no consumer produces, so an incomplete installation
 * fails the same way whichever of the two is reached first.
 *
 * EVERY Throwable IS CONTAINED ON THIS SIDE TOO. The consumer contains its own,
 * but a filter is a public extension point: a third party could hook it and
 * throw, and an exception escaping mid-`apply_filters()` would abandon a
 * possibly half-created site with no result at all.
 */
final class PlatformGateway {

	/** ADR-042's single cross-plugin provisioning contract. */
	public const FILTER = 'bingocommerce_platform_provision_hall_site';

	/** The only WP_Error codes ADR-042 permits the filter to return. */
	public const PLATFORM_ERROR_CODES = [
		'site_create_failed',
		'site_ownership_unproven',
		'platform_unavailable',
		'internal_error',
	];

	/** The exact key set of a successful filter result. */
	public const RESULT_KEYS = [ 'blog_id', 'site_url', 'created', 'managed_marker_present' ];

	/**
	 * IS THE PLATFORM-OWNED TOPOLOGY RESOLVER INSTALLED AT ALL?
	 *
	 * A SEPARATE QUESTION FROM WHETHER IT WORKED, and the two must never be
	 * inferred from one another. resolveAddress() answers `platform_unavailable`
	 * for BOTH "the plugin is not installed" and "the installed resolver failed
	 * or threw", which is right for what the caller may be TOLD but wrong for
	 * what the caller may CONCLUDE: ADR-042 permits an absent platform component
	 * to be stepped over on the suspend path — "`platform_unavailable` MUST NOT
	 * prevent safe suspension" — and permits nothing of the kind for a resolver
	 * that is present and answered badly. Treating the second as the first would
	 * turn a storage failure into a skipped identity check.
	 *
	 * So absence is established POSITIVELY, by asking whether the class this
	 * plugin would call is loadable, rather than by reading it out of an error
	 * code that both cases share.
	 */
	public static function resolverInstalled(): bool {
		return class_exists( '\BingoCommerce\Platform\HallSiteAddress' );
	}

	/**
	 * Resolve one Hall slug to its address on this network.
	 *
	 * @return \BingoCommerce\Platform\HallSiteAddress|\WP_Error
	 */
	public static function resolveAddress( string $slug ) {
		if ( ! self::resolverInstalled() ) {
			return self::error( 'platform_unavailable', 'The platform address resolver is unavailable.' );
		}

		try {
			return \BingoCommerce\Platform\HallSiteAddress::forSlug( $slug );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return self::error( 'platform_unavailable', 'The network address could not be resolved.' );
		}
	}

	/**
	 * Does a WordPress site already exist at this address?
	 *
	 * @param \BingoCommerce\Platform\HallSiteAddress $address
	 * @return int|null|\WP_Error Null is a VERIFIED absence.
	 */
	public static function existingSiteId( $address ) {
		if ( ! $address instanceof \BingoCommerce\Platform\HallSiteAddress ) {
			return self::error( 'platform_unavailable', 'The network address is unusable.' );
		}

		try {
			return $address->existingSiteId();
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return self::error( 'platform_unavailable', 'The network address could not be checked.' );
		}
	}

	/**
	 * Invoke the platform provisioning filter exactly once.
	 *
	 * The seed is ALWAYS null and is not a parameter: a non-null seed is how the
	 * consumer recognises that somebody else already answered, so a caller that
	 * could prime it could make the consumer skip real work.
	 *
	 * @param array<string,mixed> $command
	 * @return array<string,mixed>|string Validated result, or an error category.
	 */
	public static function provisionHallSite( array $command ) {
		try {
			$result = apply_filters( self::FILTER, null, $command );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return 'internal_error';
		}

		if ( null === $result ) {
			// Nothing acted: an unchanged null seed. No local fallback exists.
			return 'platform_unavailable';
		}

		if ( $result instanceof \WP_Error ) {
			$code = (string) $result->get_error_code();

			// Only the four permitted codes are honoured. Anything else — from a
			// third consumer, or from a future platform change — is reduced,
			// because an unrecognised code is an unreviewed disclosure.
			return in_array( $code, self::PLATFORM_ERROR_CODES, true ) ? $code : 'internal_error';
		}

		return self::validateResult( $result );
	}

	/**
	 * @param mixed $result
	 * @return array<string,mixed>|string
	 */
	public static function validateResult( $result ) {
		if ( ! is_array( $result ) ) {
			return 'internal_error';
		}

		$keys = array_keys( $result );
		sort( $keys );
		$expected = self::RESULT_KEYS;
		sort( $expected );
		if ( $keys !== $expected ) {
			return 'internal_error';
		}

		if ( ! is_int( $result['blog_id'] ) || $result['blog_id'] < 1
			|| ! is_string( $result['site_url'] ) || '' === $result['site_url']
			|| ! is_bool( $result['created'] )
			|| ! is_bool( $result['managed_marker_present'] ) ) {
			return 'internal_error';
		}

		if ( true !== $result['managed_marker_present'] ) {
			// The consumer could not read its own ownership witness back.
			// Ambiguous ownership is operator recovery, never a silent bind.
			return 'site_ownership_unproven';
		}

		return $result;
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-portal-integration' ) );
	}
}
