<?php
/** Isolated request worker for portal-sso-foundation.php. Never boots wp-load.php. */
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
$input = json_decode( $argv[1] ?? '', true, 512, JSON_THROW_ON_ERROR );
$mode = $input['mode'];
if ( 'xmlrpc' === $mode ) {
	define( 'XMLRPC_REQUEST', true );
}
if ( 'key_write' === $mode ) {
	define( 'DOING_AJAX', true );
}
if ( in_array( $mode, [ 'site_add', 'network_add', 'key_write' ], true ) ) {
	define( 'WP_ADMIN', true );
}
if ( 'network_add' === $mode ) {
	define( 'WP_NETWORK_ADMIN', true );
}
putenv( 'BC_FIXTURE_NETWORK_ACTIVE=1' );
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
$report = [ 'completed' => false ];
ob_start();
register_shutdown_function( static function () use ( &$report ) {
	$report['fatal'] = error_get_last();
	while ( ob_get_level() ) {
		ob_end_clean();
	}
	echo json_encode( $report, JSON_THROW_ON_ERROR ) . "\n";
} );
// Observe registration BEFORE plugins_loaded/admin_init can install guards.
$GLOBALS['wp_filter']['plugins_loaded'][PHP_INT_MIN]['sso_guard_probe'] = [
	'function' => static function () use ( &$report ) {
		$report['early_guards'] = false !== has_filter( 'authenticate', [ \BingoCommerce\PortalIntegration\SsoAccountGuard::class, 'deny_credentials' ] )
			&& false !== has_filter( 'wp_pre_insert_user_data', [ \BingoCommerce\PortalIntegration\SsoAccountGuard::class, 'reserve_username' ] )
			&& false !== has_action( 'wp_ajax_woocommerce_update_api_key', [ \BingoCommerce\PortalIntegration\SsoWooCommerceGuard::class, 'guard_key_write' ] );
	},
	'accepted_args' => 0,
];
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
use BingoCommerce\PortalIntegration\SsoAccountGuard;

switch_to_blog( (int) ( $input['blog'] ?? BC_FIXTURE_HALL_A ) );
wp_set_current_user( (int) ( $input['actor'] ?? 0 ) );
$login = $input['login'] ?? '';
$email = $input['email'] ?? '';
$password = $input['password'] ?? 'Fixture-only-password-348!';
$report['database'] = DB_NAME;
$report['blog'] = get_current_blog_id();
add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'wp_is_application_passwords_available', '__return_true' );
add_filter( 'application_password_is_api_request', '__return_true' );
add_action( 'user_register', static function ( $id ) use ( &$report ) { $report['created_id'] = $id; } );
add_filter( 'wp_pre_insert_user_data', static function ( $data, $update ) use ( &$report ) {
	if ( ! $update ) { $report['reached_insert'] = true; }
	return $data;
}, PHP_INT_MAX - 1, 2 );
$die = static function ( $message = '', $title = '', $args = [] ) use ( &$report ) {
	$report['died'] = is_wp_error( $message ) ? $message->get_error_code() : strip_tags( (string) $message );
	$report['completed'] = true;
	exit;
};
add_filter( 'wp_die_handler', static fn() => $die );
add_filter( 'wp_die_ajax_handler', static fn() => $die );
add_filter( 'wp_die_json_handler', static fn() => $die );

try {
	$result = null;
	switch ( $mode ) {
		case 'register':
			$result = register_new_user( $login, $email );
			break;
		case 'network_add':
			// Exact installed Network Add User validation/creation entry points.
			$validated = wpmu_validate_user_signup( $login, $email );
			$result = $validated['errors']->has_errors() ? $validated['errors'] : wpmu_create_user( strtolower( $login ), $password, $email );
			break;
		case 'site_add':
			// Site Add User's Multisite path creates a signup, then activates it.
			$validated = wpmu_validate_user_signup( $login, $email );
			if ( $validated['errors']->has_errors() ) {
				$result = $validated['errors'];
				break;
			}
			// Fall through to the activation path also tested directly below.
		case 'signup_activation':
			wpmu_signup_user( $login, $email, [ 'add_to_blog' => get_current_blog_id(), 'new_role' => 'subscriber' ] );
			$key = $wpdb->get_var( $wpdb->prepare( 'SELECT activation_key FROM %i WHERE user_login = %s AND user_email = %s', $wpdb->signups, $login, $email ) );
			$result = wpmu_activate_signup( $key );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_login = %s AND user_email = %s', $wpdb->signups, $login, $email ) );
			break;
		case 'wp_api':
		case 'wc_api':
			if ( 'wc_api' === $mode && ! empty( $input['generate_username'] ) ) {
				update_option( 'woocommerce_registration_generate_username', 'yes' );
			}
			$request = new WP_REST_Request( 'POST', 'wp_api' === $mode ? '/wp/v2/users' : '/wc/v3/customers' );
			$params = [ 'email' => $email, 'password' => $password ];
			if ( empty( $input['generate_username'] ) ) { $params['username'] = $login; }
			$request->set_body_params( $params );
			$result = rest_do_request( $request );
			$report['status'] = $result->get_status();
			$report['response'] = $result->get_data();
			break;
		case 'my_account':
			update_option( 'woocommerce_registration_generate_username', empty( $input['generate_username'] ) ? 'no' : 'yes' );
			update_option( 'woocommerce_registration_generate_password', 'no' );
			WC()->initialize_session();
			$_POST = [ 'register' => '1', 'email' => $email, 'password' => $password, 'woocommerce-register-nonce' => wp_create_nonce( 'woocommerce-register' ) ];
			if ( empty( $input['generate_username'] ) ) { $_POST['username'] = $login; }
			$_REQUEST = $_POST;
			// Suppress only the success redirect/login so both outcomes are inspectable.
			add_filter( 'woocommerce_registration_auth_new_customer', '__return_false' );
			WC_Form_Handler::process_registration();
			$report['notices'] = wc_get_notices( 'error' );
			break;
		case 'checkout':
			WC()->initialize_session();
			WC()->initialize_cart();
			$method = new ReflectionMethod( WC_Checkout::class, 'process_customer' );
			$method->invoke( WC_Checkout::instance(), [ 'createaccount' => true, 'account_username' => empty( $input['generate_username'] ) ? $login : '', 'account_password' => $password, 'billing_email' => $email ] );
			break;
		case 'store_checkout':
			WC()->initialize_session();
			add_filter( 'woocommerce_checkout_registration_enabled', '__return_true' );
			$checkout = \Automattic\WooCommerce\StoreApi\StoreApi::container()->get( \Automattic\WooCommerce\StoreApi\RoutesController::class )->get( 'checkout' );
			$order = new WC_Order(); // No order is persisted or paid by this test.
			$order->set_billing_email( $email );
			$property = new ReflectionProperty( $checkout, 'order' );
			$property->setValue( $checkout, $order );
			$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
			$request->set_body_params( [ 'create_account' => true, 'customer_password' => $password, 'billing_address' => [ 'email' => $email, 'first_name' => '', 'last_name' => '' ] ] );
			// The installed Store API generates the username from the email here.
			( new ReflectionMethod( $checkout, 'process_customer' ) )->invoke( $checkout, $request );
			break;
		case 'xmlrpc':
			require_once ABSPATH . WPINC . '/class-IXR.php';
			require_once ABSPATH . WPINC . '/class-wp-xmlrpc-server.php';
			add_filter( 'xmlrpc_enabled', '__return_true' );
			$result = ( new wp_xmlrpc_server() )->login( $login, $password );
			$report['authenticated'] = $result instanceof WP_User;
			break;
		case 'key_write':
			$_POST = [ 'user' => (string) $input['target'], 'description' => 'Phase 1 fixture', 'permissions' => 'read', 'key_id' => (string) ( $input['key_id'] ?? 0 ) ];
			$_POST['security'] = wp_create_nonce( 'update-api-key' );
			$_REQUEST = $_POST;
			// Real WooCommerce AJAX dispatch, including its own nonce and caps checks.
			do_action( 'wp_ajax_woocommerce_update_api_key' );
			break;
		case 'app_authorization':
			$wp = new WP();
			if ( ! empty( $input['pretty'] ) ) {
				$wp->query_vars = [ 'wc-auth-version' => 'v1', 'wc-auth-route' => 'access_granted' ];
				if ( ! empty( $input['blank_query'] ) ) { $_GET = [ 'wc-auth-version' => '', 'wc-auth-route' => '' ]; }
			} else {
				$_GET = [ 'wc-auth-version' => 'v1', 'wc-auth-route' => 'access_granted' ];
			}
			$_GET += [ 'app_name' => 'Fixture', 'user_id' => 'external-fixture', 'scope' => 'read', 'return_url' => 'https://example.invalid/return', 'callback_url' => 'https://example.invalid/callback', 'wc_auth_nonce' => wp_create_nonce( 'wc_auth_grant_access' ) ];
			$_REQUEST = $_GET;
			add_filter( 'pre_http_request', static fn() => [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [] ], 10 );
			add_filter( 'wp_redirect', static function ( $url ) use ( &$report ) { $report['redirect'] = $url; $report['completed'] = true; return false; } );
			do_action_ref_array( 'parse_request', [ &$wp ] );
			break;
		case 'key_auth':
			wp_set_current_user( 0 );
			unset( $GLOBALS['current_user'] );
			$_SERVER['HTTPS'] = 'on';
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$_SERVER['REQUEST_URI'] = '/wp-json/wc/v3/products';
			$_SERVER['PHP_AUTH_USER'] = $input['key'];
			$_SERVER['PHP_AUTH_PW'] = $input['secret'];
			if ( ! empty( $input['padded'] ) ) { $_SERVER['PHP_AUTH_USER'] = ' <b>' . $input['key'] . '</b> '; }
			if ( ! empty( $input['query'] ) ) {
				$_GET = [ 'consumer_key' => $input['key'], 'consumer_secret' => $input['secret'] ];
				unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
			}
			if ( ! empty( $input['oauth'] ) ) {
				$_SERVER['HTTPS'] = 'off';
				unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
				$params = [ 'oauth_consumer_key' => $input['key'], 'oauth_timestamp' => time(), 'oauth_nonce' => bin2hex( random_bytes( 16 ) ), 'oauth_signature_method' => 'HMAC-SHA256' ];
				ksort( $params );
				$base = 'GET&' . rawurlencode( get_home_url( null, 'wp-json/wc/v3/products', 'http' ) ) . '&' . rawurlencode( http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ) );
				$params['oauth_signature'] = base64_encode( hash_hmac( 'sha256', $base, $input['secret'] . '&', true ) );
				if ( 'header' === $input['oauth'] ) {
					$parts = [];
					foreach ( $params as $name => $value ) { $parts[] = $name . '="' . rawurlencode( (string) $value ) . '"'; }
					$_SERVER['HTTP_AUTHORIZATION'] = 'OAuth ' . implode( ', ', $parts );
				} else { $_GET = $params; }
			}
			if ( ! empty( $input['fallback'] ) ) { wp_set_current_user( 0 ); }
			$result = apply_filters( 'rest_authentication_errors', null );
			$report['authenticated'] = ! is_wp_error( $result ) && (int) $input['target'] === get_current_user_id();
			$report['current_user'] = get_current_user_id();
			break;
		case 'mcp_key_auth':
			wp_set_current_user( 0 );
			$_SERVER['HTTPS'] = 'on';
			// Exercise the installed transport's actual permission callback. Its
			// constructor only sets up the unrelated MCP router/context.
			$class = new ReflectionClass( \Automattic\WooCommerce\Internal\MCP\Transport\WooCommerceRestTransport::class );
			$transport = $class->newInstanceWithoutConstructor();
			rest_get_server();
			register_rest_route( 'sso-fixture/v1', '/mcp', [
				'methods' => 'GET', 'permission_callback' => [ $transport, 'check_permission' ],
				'callback' => static function () use ( &$report ) { $report['endpoint_ran'] = true; return [ 'ok' => true ]; },
			] );
			$request = new WP_REST_Request( 'GET', '/sso-fixture/v1/mcp' );
			$request->set_header( 'X-MCP-API-Key', $input['key'] . ':' . $input['secret'] );
			$result = rest_do_request( $request );
			$report['status'] = $result->get_status();
			$report['response'] = $result->get_data();
			break;
		case 'wc_reset':
			WC()->initialize_session();
			$_POST = [ 'wc_reset_password' => '1', 'password_1' => 'Changed-fixture-password!', 'password_2' => 'Changed-fixture-password!', 'reset_key' => $input['reset_key'], 'reset_login' => $login, 'woocommerce-reset-password-nonce' => wp_create_nonce( 'reset_password' ) ];
			$_REQUEST = $_POST;
			add_filter( 'wp_redirect', static function ( $url ) use ( &$report ) { $report['completed'] = true; return false; } );
			WC_Form_Handler::process_reset_password();
			$report['notices'] = wc_get_notices( 'error' );
			break;
		default:
			throw new RuntimeException( 'Unknown worker mode' );
	}
	if ( is_wp_error( $result ) ) { $report['error'] = $result->get_error_code(); }
	$report['completed'] = true;
} catch ( Throwable $error ) {
	$report['exception'] = get_class( $error ) . ': ' . $error->getMessage();
	$report['completed'] = true;
}
if ( '' !== $login ) { $report['user_exists'] = (int) username_exists( $login ); }
