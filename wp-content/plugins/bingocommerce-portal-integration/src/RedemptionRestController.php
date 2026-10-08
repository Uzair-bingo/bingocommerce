<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Authenticated server-to-server adapter; no ticket or lifecycle SQL here. */
final class RedemptionRestController {
	public const NAMESPACE = 'bingocommerce/v1';
	public const ROUTE = '/portal-halls/(?P<portal_hall_id>[^/]+)/redemptions';
	public const CAPABILITY = 'bc_receive_portal_redemptions';
	private const REQUIRED = [ 'request_id', 'qr', 'player_tablet_id', 'caller_id' ];
	private const OPTIONAL = [ 'operating_commerce_session_id' ];
	private static bool $registered = false;
	private static bool $routes_registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function register_routes(): void {
		if ( self::$routes_registered ) {
			return;
		}
		self::$routes_registered = true;
		$args = [];
		foreach ( array_merge( [ 'portal_hall_id' ], self::REQUIRED, self::OPTIONAL ) as $field ) {
			$args[ $field ] = [
				'required' => ! in_array( $field, self::OPTIONAL, true ),
				'validate_callback' => [ self::class, 'validate_parameter' ],
				// Deliberately lossless. Validation rejects invalid values; a
				// sanitizer must never turn an opaque tablet ID into another ID.
				'sanitize_callback' => [ self::class, 'sanitize_parameter' ],
			];
		}
		register_rest_route( self::NAMESPACE, self::ROUTE, [
			'methods' => 'POST',
			'callback' => [ self::class, 'handle' ],
			'permission_callback' => [ self::class, 'authorize' ],
			'args' => $args,
		] );
		add_filter( 'rest_post_dispatch', [ self::class, 'finalize_response' ], 10, 3 );
	}

	/** @return true|\WP_Error */
	public static function authorize( \WP_REST_Request $request ) {
		unset( $request );
		if ( ! ProvisioningRestController::has_application_password_proof() ) {
			return RedemptionGateway::error( 'authentication_required' );
		}
		// A dedicated nonhuman service account, not Super Admin's universal cap.
		if ( ! is_multisite() || ! is_main_site( get_current_blog_id(), get_current_network_id() )
			|| is_super_admin( get_current_user_id() ) || ! current_user_can( self::CAPABILITY ) ) {
			return RedemptionGateway::error( 'caller_not_authorized' );
		}
		return true;
	}

	public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
		// Also protect direct invocation by another plugin or a test harness.
		$allowed = self::authorize( $request );
		if ( $allowed instanceof \WP_Error ) {
			return RedemptionGateway::response( $allowed );
		}
		$switched = false;
		global $wpdb;
		$previous_errors = $wpdb->suppress_errors( true );
		try {
			$command = self::parse_command( $request );
			if ( $command instanceof \WP_Error ) {
				return RedemptionGateway::response( $command );
			}
			$mapping = ( new HallMappingRepository() )->findForRedemption( $command['portal_hall_id'] );
			if ( $mapping instanceof \WP_Error || null === $mapping ) {
				return RedemptionGateway::response( RedemptionGateway::error( 'mapping_unavailable' ) );
			}
			$command['site_id'] = (int) $mapping['blog_id'];
			// Resolve the Hall first. The untrusted QR can only disagree with it.
			preg_match( '/\ABC1\.([1-9][0-9]*)\.[a-f0-9]{64}\z/', $command['qr'], $parts );
			if ( (string) $command['site_id'] !== $parts[1] ) {
				return RedemptionGateway::response( RedemptionGateway::error( 'wrong_hall' ) );
			}
			switch_to_blog( $command['site_id'] );
			$switched = true;
			if ( (int) get_current_blog_id() !== $command['site_id'] ) {
				return RedemptionGateway::response( RedemptionGateway::error( 'verification_unavailable' ) );
			}
			return RedemptionGateway::response( RedemptionGateway::redeem( $command ) );
		} catch ( \Throwable $ignored ) {
			return RedemptionGateway::response( RedemptionGateway::error( 'verification_unavailable' ) );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
			$wpdb->suppress_errors( $previous_errors );
		}
	}

	/** No query/form overrides; path and JSON body have separate authorities. */
	public static function parse_command( \WP_REST_Request $request ) {
		$url = $request->get_url_params();
		if ( ! $request->is_json_content_type() || [] !== $request->get_query_params()
			|| [] !== $request->get_body_params() || ! self::positive_id( $url['portal_hall_id'] ?? null, true )
			|| strlen( $request->get_body() ) > 4096 ) {
			return RedemptionGateway::error( 'invalid_request' );
		}
		$decoded = json_decode( $request->get_body(), false, 8 );
		if ( ! $decoded instanceof \stdClass || JSON_ERROR_NONE !== json_last_error() ) {
			return RedemptionGateway::error( 'invalid_request' );
		}
		$body = (array) $decoded;
		if ( array_diff( self::REQUIRED, array_keys( $body ) ) || array_diff( array_keys( $body ), array_merge( self::REQUIRED, self::OPTIONAL ) ) ) {
			return RedemptionGateway::error( 'invalid_request' );
		}
		foreach ( $body as $field => $value ) {
			$valid = self::validate_parameter( $value, $request, $field );
			if ( true !== $valid ) {
				return $valid;
			}
		}
		return array_merge( $body, [ 'portal_hall_id' => (int) $url['portal_hall_id'] ] );
	}

	/** @return true|\WP_Error */
	public static function validate_parameter( $value, $request, string $field ) {
		unset( $request );
		switch ( $field ) {
			case 'portal_hall_id': $valid = self::positive_id( $value, true ); break;
			case 'operating_commerce_session_id': $valid = self::positive_id( $value, false ); break;
			case 'request_id': $valid = self::uuid( $value ); break;
			case 'player_tablet_id': $valid = self::opaque_identity( $value, 128 ); break;
			case 'caller_id': $valid = self::opaque_identity( $value, 191 ); break;
			case 'qr':
				$valid = is_string( $value ) && strlen( $value ) <= 88 && 1 === preg_match( '/\ABC1\.[1-9][0-9]*\.[a-f0-9]{64}\z/', $value );
				return $valid ? true : RedemptionGateway::error( 'malformed_qr' );
			default: $valid = false;
		}
		return $valid ? true : RedemptionGateway::error( 'invalid_request' );
	}

	public static function sanitize_parameter( $value ) {
		return $value;
	}

	public static function uuid( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value );
	}

	public static function opaque_identity( $value, int $maximum_bytes ): bool {
		return is_string( $value ) && '' !== $value && strlen( $value ) <= $maximum_bytes
			&& 1 === preg_match( '//u', $value ) && 0 === preg_match( '/\p{Cc}/u', $value );
	}

	private static function positive_id( $value, bool $text ): bool {
		if ( ! $text ) {
			return is_int( $value ) && $value > 0;
		}
		return is_string( $value ) && 1 === preg_match( '/\A[1-9][0-9]*\z/', $value ) && (int) $value > 0 && (string) (int) $value === $value;
	}

	/** Safe shape and no-store for this POST route, including core auth/args errors. */
	public static function finalize_response( $response, $server, $request ) {
		unset( $server );
		if ( ! $request instanceof \WP_REST_Request || 'POST' !== $request->get_method()
			|| 1 !== preg_match( '#\A/' . self::NAMESPACE . '/portal-halls/[^/]+/redemptions\z#', $request->get_route() ) ) {
			return $response;
		}
		$response = rest_ensure_response( $response );
		if ( $response instanceof \WP_Error ) {
			$response = RedemptionGateway::response( $response );
		}
		$data = $response->get_data();
		if ( $response->get_status() >= 400 && ! ( is_array( $data ) && isset( $data['error']['code'] ) ) ) {
			$status = $response->get_status();
			$code = is_array( $data ) ? ( $data['code'] ?? '' ) : '';
			if ( 'rest_invalid_param' === $code ) {
				$code = ( $data['data']['details']['qr']['code'] ?? '' ) === 'malformed_qr' ? 'malformed_qr' : 'invalid_request';
			} elseif ( 401 === $status ) {
				$code = 'authentication_required';
			} elseif ( 403 === $status ) {
				$code = 'caller_not_authorized';
			} elseif ( 400 === $status ) {
				$code = 'invalid_request';
			}
			$response = RedemptionGateway::response( RedemptionGateway::error( $code ) );
		}
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
