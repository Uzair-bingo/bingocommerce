<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** SSO-only exchange. The read-only snapshot transport remains independent. */
final class SsoHttpClient {
	/** @return string|\WP_Error Raw successful body; never remote error detail. */
	public function exchange( PortalConfig $config, string $code, string $attempt_reference, string $proof ) {
		if ( ! SsoExchangeValidator::hex( $code ) || ! SsoExchangeValidator::hex( $attempt_reference ) || ! SsoExchangeValidator::hex( $proof ) ) {
			return self::error( 'invalid_request' );
		}
		try {
			$response = wp_remote_post( $config->baseUrl() . '/integration/v1/sso/exchange', [
				'blocking' => true, 'timeout' => 10, 'redirection' => 0, 'cookies' => [],
				'limit_response_size' => 16384,
				'headers' => [ 'Authorization' => 'Bearer ' . $config->ssoToken(), 'Accept' => 'application/json', 'Content-Type' => 'application/json' ],
				'body' => wp_json_encode( compact( 'code', 'attempt_reference', 'proof' ) ),
				'data_format' => 'body',
			] );
		} catch ( \Throwable $ignored ) {
			return self::error( 'request_failed' );
		}
		if ( is_wp_error( $response ) ) { return self::error( 'request_failed' ); }
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $status ) { return wp_remote_retrieve_body( $response ); }
		$category = [ 400 => 'invalid_code', 401 => 'authentication_failed', 403 => 'access_denied', 429 => 'rate_limited', 503 => 'sso_unavailable' ];
		return self::error( $category[ $status ] ?? 'http_error' );
	}

	private static function error( string $code ): \WP_Error {
		return new \WP_Error( $code, 'The Portal SSO exchange could not be completed.' );
	}
}
