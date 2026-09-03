<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Immutable, server-only Portal connection configuration. */
final class PortalConfig {

	private string $base_url;

	private function __construct( string $base_url ) {
		$this->base_url = $base_url;
	}

	/** @return self|\WP_Error */
	public static function fromConstants() {
		if ( ! defined( 'BINGOCOMMERCE_PORTAL_BASE_URL' ) ) {
			return self::error( 'portal_base_url_missing', 'Portal configuration is incomplete.' );
		}
		if ( ! defined( 'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN' ) ) {
			return self::error( 'portal_integration_token_missing', 'Portal configuration is incomplete.' );
		}

		$base_url = constant( 'BINGOCOMMERCE_PORTAL_BASE_URL' );
		$token    = constant( 'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN' );
		if ( ! is_string( $base_url ) || ! is_string( $token ) ) {
			return self::error( 'portal_configuration_invalid', 'Portal configuration is invalid.' );
		}

		$base_url = rtrim( trim( $base_url ), '/' );
		if ( '' === $base_url || strlen( $base_url ) > 2048 || false === filter_var( $base_url, FILTER_VALIDATE_URL ) ) {
			return self::error( 'portal_base_url_invalid', 'The Portal base URL is invalid.' );
		}

		$parts = wp_parse_url( $base_url );
		if (
			! is_array( $parts )
			|| ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true )
			|| '' === (string) ( $parts['host'] ?? '' )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['query'] )
			|| isset( $parts['fragment'] )
		) {
			return self::error( 'portal_base_url_invalid', 'The Portal base URL is invalid.' );
		}

		if ( '' === $token || strlen( $token ) > 4096 || 1 === preg_match( '/[\x00-\x20\x7f]/', $token ) ) {
			return self::error( 'portal_integration_token_invalid', 'The Portal integration token is invalid.' );
		}

		return new self( $base_url );
	}

	public function baseUrl(): string {
		return $this->base_url;
	}

	public function integrationToken(): string {
		// Constants are immutable for the lifetime of a PHP request. The token
		// was validated by fromConstants(), and deliberately is not copied into
		// object state where generic serialization could expose it.
		return (string) constant( 'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN' );
	}

	/** Prevent generic object debugging from exposing the bearer credential. */
	public function __debugInfo(): array {
		return [
			'base_url'          => $this->base_url,
			'integration_token' => '[REDACTED]',
		];
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-portal-integration' ) );
	}
}
