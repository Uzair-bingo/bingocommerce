<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** The Phase 3a wire contract, without coercion or email-based identity. */
final class SsoExchangeValidator {
	public static function hex( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[0-9a-f]{64}\z/', $value );
	}

	public static function reference( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value );
	}

	/** @return array|\WP_Error */
	public static function validate( string $body, PortalConfig $config, int $hall, string $reference ) {
		$data = json_decode( $body, true, 16 );
		$fields = [ 'installation_id', 'portal_user_id', 'portal_user_created_at', 'portal_hall_id', 'name', 'email', 'sso_reference' ];
		if ( ! is_array( $data ) || count( $data ) !== count( $fields ) || array_diff( $fields, array_keys( $data ) ) ) {
			return self::invalid();
		}
		if ( ! is_string( $data['installation_id'] ) || $data['installation_id'] !== $config->installationId()
			|| ! is_int( $data['portal_user_id'] ) || $data['portal_user_id'] < 1
			|| ! is_int( $data['portal_hall_id'] ) || $data['portal_hall_id'] !== $hall
			|| ! is_string( $data['name'] ) || ! is_string( $data['email'] )
			|| ! self::reference( $data['sso_reference'] ) || ! hash_equals( $reference, $data['sso_reference'] )
			|| ! is_string( $data['portal_user_created_at'] ) ) {
			return self::invalid();
		}
		$time = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $data['portal_user_created_at'], new \DateTimeZone( 'UTC' ) );
		if ( ! $time || $time->format( 'Y-m-d\TH:i:s\Z' ) !== $data['portal_user_created_at'] ) {
			return self::invalid();
		}
		return $data;
	}

	private static function invalid(): \WP_Error {
		return new \WP_Error( 'invalid_response', 'The Portal SSO answer could not be verified.' );
	}
}
