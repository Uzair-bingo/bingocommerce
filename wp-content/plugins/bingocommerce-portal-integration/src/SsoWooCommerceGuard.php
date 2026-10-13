<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** WooCommerce keys bypass WordPress's authenticate/application-password hooks. */
final class SsoWooCommerceGuard {

	private static bool $key_authentication_refused = false;

	public static function register(): void {
		// Both installed key writers have no shared pre-insert filter.
		add_action( 'wp_ajax_woocommerce_update_api_key', [ self::class, 'guard_key_write' ], PHP_INT_MIN );
		add_action( 'parse_request', [ self::class, 'guard_app_authorization' ], PHP_INT_MIN );
		add_filter( 'determine_current_user', [ self::class, 'guard_key_identity' ], PHP_INT_MAX );
		// WC can authenticate again in its REST fallback, after determine_current_user.
		add_filter( 'rest_authentication_errors', [ self::class, 'guard_rest_authentication' ], PHP_INT_MAX );
		add_filter( 'rest_request_before_callbacks', [ self::class, 'guard_rest_request' ], PHP_INT_MAX, 3 );
		// The installed WC MCP transport authenticates in its permission_callback.
		add_filter( 'rest_dispatch_request', [ self::class, 'guard_rest_dispatch' ], PHP_INT_MAX, 4 );
		add_action( 'woocommerce_edit_account_form_start', [ self::class, 'hide_password_fields' ] );
	}

	public static function guard_key_write(): void {
		// Match the installed writer's absint() conversion, including malformed input.
		$target = isset( $_POST['user'] ) ? absint( $_POST['user'] ) : 0;
		if ( SsoAccountGuard::is_portal_user( wp_get_current_user() ) || ( $target && SsoAccountGuard::is_portal_user( get_userdata( $target ) ) ) ) {
			wp_send_json_error( [ 'message' => SsoAccountGuard::message() ], 403 );
		}
		// All ordinary requests keep WooCommerce's original nonce/capability checks.
	}

	public static function guard_app_authorization( \WP $wp ): void {
		// WC accepts both pretty URLs and these query-string equivalents.
		// Empty query parameters do not override a pretty route in WC_Auth.
		$version = ! empty( $_GET['wc-auth-version'] ) ? $_GET['wc-auth-version'] : ( $wp->query_vars['wc-auth-version'] ?? '' );
		$route   = ! empty( $_GET['wc-auth-route'] ) ? $_GET['wc-auth-route'] : ( $wp->query_vars['wc-auth-route'] ?? '' );
		if ( ! empty( $version ) && ! empty( $route ) && SsoAccountGuard::is_portal_user( wp_get_current_user() ) ) {
			wp_die( esc_html( SsoAccountGuard::message() ), '', [ 'response' => 403 ] );
		}
	}

	/** @param int|false $user_id @return int|false */
	public static function guard_key_identity( $user_id ) {
		if ( $user_id && self::has_key_credentials() && SsoAccountGuard::is_portal_user( get_userdata( (int) $user_id ) ) ) {
			self::$key_authentication_refused = true;
			return 0;
		}
		return $user_id;
	}

	/** @param mixed $result @return mixed */
	public static function guard_rest_authentication( $result ) {
		return self::refuse_key_request( $result );
	}

	/** @param mixed $result @param array<string,mixed> $handler @return mixed */
	public static function guard_rest_request( $result, array $handler, \WP_REST_Request $request ) {
		return self::refuse_key_request( $result, $request );
	}

	/** @param mixed $result @param array<string,mixed> $handler @return mixed */
	public static function guard_rest_dispatch( $result, \WP_REST_Request $request, string $route, array $handler ) {
		return self::refuse_key_request( $result, $request );
	}

	/** @param mixed $result @return mixed */
	private static function refuse_key_request( $result, ?\WP_REST_Request $request = null ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( self::$key_authentication_refused || ( self::has_key_credentials( $request ) && SsoAccountGuard::is_portal_user( wp_get_current_user() ) ) ) {
			self::$key_authentication_refused = true;
			wp_set_current_user( 0 );
			return SsoAccountGuard::error();
		}
		return $result;
	}

	/** Recognizes transports only; the account decision always uses its username. */
	private static function has_key_credentials( ?\WP_REST_Request $request = null ): bool {
		foreach ( [ 'consumer_key', 'oauth_consumer_key' ] as $key ) {
			if ( ! empty( $_GET[ $key ] ) || ! empty( $_POST[ $key ] ) || ( $request && $request->get_param( $key ) ) ) {
				return true;
			}
		}
		if ( ! empty( $_SERVER['HTTP_X_MCP_API_KEY'] ) || ( $request && $request->get_header( 'x-mcp-api-key' ) ) ) {
			return true;
		}
		// WC sanitizes the consumer key before hashing it, so padding/HTML must
		// not conceal the transport from this guard while still authenticating.
		if ( isset( $_SERVER['PHP_AUTH_USER'] ) && is_string( $_SERVER['PHP_AUTH_USER'] ) && self::is_consumer_key( $_SERVER['PHP_AUTH_USER'] ) ) {
			return true;
		}
		$authorization = $request ? $request->get_header( 'authorization' ) : '';
		if ( ! $authorization ) {
			$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
		}
		// Match WC's OAuth header source when the server exposes only getallheaders().
		if ( ! $authorization && function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $name => $value ) {
				if ( 0 === strcasecmp( (string) $name, 'authorization' ) ) {
					$authorization = $value;
					break;
				}
			}
		}
		if ( ! is_string( $authorization ) ) {
			return false;
		}
		if ( 0 === stripos( $authorization, 'OAuth ' ) ) {
			return true;
		}
		if ( 0 === stripos( $authorization, 'Basic ' ) ) {
			$decoded = base64_decode( substr( $authorization, 6 ), true );
			return is_string( $decoded ) && self::is_consumer_key( explode( ':', $decoded, 2 )[0] );
		}
		return false;
	}

	private static function is_consumer_key( string $key ): bool {
		return 0 === strpos( sanitize_text_field( $key ), 'ck_' );
	}

	public static function hide_password_fields(): void {
		if ( SsoAccountGuard::is_portal_user( wp_get_current_user() ) ) {
			echo '<style>form.woocommerce-EditAccountForm fieldset:has(input[name="password_current"]){display:none}</style>';
		}
	}
}
