<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Blocking, read-only HTTP transport for the Portal Hall snapshot endpoint. */
final class PortalHttpClient {

	/** @var callable():PortalConfig|\WP_Error */
	private $configuration_provider;

	/** @param callable():PortalConfig|\WP_Error|null $configuration_provider */
	public function __construct( ?callable $configuration_provider = null ) {
		$this->configuration_provider = $configuration_provider ?? [ PortalConfig::class, 'fromConstants' ];
	}

	/** @return string|\WP_Error Raw response body on HTTP 200. */
	public function fetchHallSnapshot( int $portal_hall_id ) {
		if ( $portal_hall_id < 1 ) {
			return self::error( 'invalid_portal_hall_id', 'The Portal Hall ID is invalid.' );
		}

		$config = call_user_func( $this->configuration_provider );
		if ( ! $config instanceof PortalConfig ) {
			return self::error( 'portal_configuration_unavailable', 'Portal configuration is unavailable.' );
		}

		$url = $config->baseUrl() . '/integration/v1/halls/' . $portal_hall_id . '/snapshot';
		$response = wp_remote_get(
			$url,
			[
				'blocking'    => true,
				'redirection' => 0,
				'headers'     => [
					'Authorization' => 'Bearer ' . $config->integrationToken(),
					'Accept'        => 'application/json',
				],
				'cookies'     => [],
			]
		);

		if ( is_wp_error( $response ) ) {
			return self::error( 'portal_request_failed', 'The Portal request could not be completed.' );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $status ) {
			return wp_remote_retrieve_body( $response );
		}
		if ( 401 === $status || 403 === $status ) {
			return self::http_error( 'portal_authentication_failed', 'Portal authentication failed.', $status );
		}
		if ( 404 === $status ) {
			return self::http_error( 'portal_hall_not_accessible', 'The Portal Hall is not accessible.', $status );
		}

		return self::http_error( 'portal_http_error', 'The Portal returned an unexpected HTTP response.', $status );
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-portal-integration' ) );
	}

	private static function http_error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error(
			$code,
			__( $message, 'bingocommerce-portal-integration' ),
			[ 'status' => $status ]
		);
	}
}
