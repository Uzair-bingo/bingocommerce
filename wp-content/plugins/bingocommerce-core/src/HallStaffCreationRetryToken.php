<?php

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-authenticated, PII-minimal provisioning intent used only for
 * Create/Invite recovery.
 *
 * Deliberately a SEPARATE token family from HallStaffRetryToken: a distinct
 * signing context string means a token minted by one family can never validate
 * in the other, even though both derive from the same WordPress salt. Phase A's
 * HallStaffRetryToken is not extended, subclassed, or reused here, and
 * HallStaffProvisioningService never hand-rolls HMAC — all signing and
 * verification goes through this class.
 */
final class HallStaffCreationRetryToken {

	public const LIFETIME = DAY_IN_SECONDS;

	private const SIGNING_CONTEXT = 'bingocommerce-hall-staff-creation-retry-v1';

	public static function issue( HallStaffCreationIntent $intent ): string {
		$json = wp_json_encode( $intent->token_payload(), JSON_UNESCAPED_SLASHES );

		if ( false === $json ) {
			throw new \RuntimeException( 'The Hall Staff creation intent could not be encoded.' );
		}

		$payload   = self::base64url_encode( $json );
		$signature = hash_hmac( 'sha256', $payload, self::signing_key(), true );

		return $payload . '.' . self::base64url_encode( $signature );
	}

	/** @return HallStaffCreationIntent|\WP_Error */
	public static function verify( string $token ) {
		if ( '' === $token || strlen( $token ) > 4096 || 1 !== substr_count( $token, '.' ) ) {
			return self::error( 'invalid_retry_token', 'The provisioning retry token is invalid.' );
		}

		[ $payload, $encoded_signature ] = explode( '.', $token, 2 );
		$signature = self::base64url_decode( $encoded_signature );

		if ( false === $signature ) {
			return self::error( 'invalid_retry_token', 'The provisioning retry token is invalid.' );
		}

		$expected = hash_hmac( 'sha256', $payload, self::signing_key(), true );

		if ( ! hash_equals( $expected, $signature ) ) {
			return self::error( 'invalid_retry_token', 'The provisioning retry token signature is invalid.' );
		}

		$json = self::base64url_decode( $payload );
		$data = false === $json ? null : json_decode( $json, true );

		if ( ! is_array( $data ) || array_keys( $data ) !== self::expected_keys() ) {
			return self::error( 'invalid_retry_token', 'The provisioning retry token payload is invalid.' );
		}

		if (
			HallStaffCreationIntent::TOKEN_VERSION !== $data['version']
			|| HallStaffCreationIntent::TOKEN_AUDIENCE !== $data['audience']
			|| ! is_string( $data['operation_id'] )
			|| ! wp_is_uuid( $data['operation_id'], 4 )
			|| ! self::positive_ints( $data, [ 'network_id', 'blog_id', 'actor_user_id', 'created_user_id', 'issued_at', 'expires_at' ] )
			|| ! is_string( $data['normalized_email'] )
			|| '' === $data['normalized_email']
			|| ! is_email( $data['normalized_email'] )
			|| ! is_string( $data['user_login'] )
			|| '' === $data['user_login']
			|| HallStaffCreationIntent::IDENTITY_TYPE !== $data['identity_type']
			|| ! is_string( $data['requested_role'] )
			|| ! in_array( $data['requested_role'], [ Roles::HALL_MANAGER, Roles::GAME_OPERATOR ], true )
		) {
			return self::error( 'invalid_retry_token', 'The provisioning retry token payload is invalid.' );
		}

		if ( $data['expires_at'] <= time() || $data['expires_at'] <= $data['issued_at'] ) {
			return self::error( 'retry_token_expired', 'The provisioning retry token has expired.' );
		}

		if ( $data['expires_at'] - $data['issued_at'] > self::LIFETIME ) {
			return self::error( 'invalid_retry_token', 'The provisioning retry token lifetime is invalid.' );
		}

		return new HallStaffCreationIntent(
			strtolower( $data['operation_id'] ),
			$data['network_id'],
			$data['blog_id'],
			$data['actor_user_id'],
			$data['created_user_id'],
			$data['normalized_email'],
			$data['user_login'],
			$data['identity_type'],
			$data['requested_role'],
			$data['issued_at'],
			$data['expires_at']
		);
	}

	/** @return array<int,string> */
	private static function expected_keys(): array {
		return [
			'version',
			'audience',
			'operation_id',
			'network_id',
			'blog_id',
			'actor_user_id',
			'created_user_id',
			'normalized_email',
			'user_login',
			'identity_type',
			'requested_role',
			'issued_at',
			'expires_at',
		];
	}

	private static function signing_key(): string {
		return hash_hmac( 'sha256', self::SIGNING_CONTEXT, wp_salt( 'auth' ), true );
	}

	private static function base64url_encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/** @return string|false */
	private static function base64url_decode( string $value ) {
		if ( '' === $value || 1 === preg_match( '/[^A-Za-z0-9_-]/', $value ) ) {
			return false;
		}

		$padding = strlen( $value ) % 4;

		if ( $padding ) {
			$value .= str_repeat( '=', 4 - $padding );
		}

		return base64_decode( strtr( $value, '-_', '+/' ), true );
	}

	/** @param array<string,mixed> $data @param array<int,string> $keys */
	private static function positive_ints( array $data, array $keys ): bool {
		foreach ( $keys as $key ) {
			if ( ! is_int( $data[ $key ] ) || $data[ $key ] < 1 ) {
				return false;
			}
		}

		return true;
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-core' ) );
	}
}
