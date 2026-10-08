<?php
/**
 * Pure adapter boundary tests. Run: php tests/qr-redemption-adapter-contract.php
 * WordPress authentication, storage and hook dispatch are explicitly mocked.
 * No WordPress boot, network call, fixture or database mutation occurs here.
 * Actual Application Password requests and concurrency belong to integration tests.
 */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
require ABSPATH . 'wp-includes/class-wp-error.php';
require ABSPATH . 'wp-includes/class-wp-http-response.php';
require ABSPATH . 'wp-includes/rest-api/class-wp-rest-response.php';

class WP_User { public int $ID = 7; }
class WP_REST_Request {
	public array $query = [];
	public array $form = [];
	public string $hall = '42';
	public bool $json = true;
	public string $method = 'POST';
	public function __construct( public string $body ) {}
	public function get_url_params() { return [ 'portal_hall_id' => $this->hall ]; }
	public function get_query_params() { return $this->query; }
	public function get_body_params() { return $this->form; }
	public function get_body() { return $this->body; }
	public function is_json_content_type() { return $this->json; }
	public function get_method() { return $this->method; }
	public function get_route() { return '/bingocommerce/v1/portal-halls/' . $this->hall . '/redemptions'; }
}
function __( $value, $domain = '' ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_current_user_id() { return 7; }
function rest_get_authenticated_app_password() { return $GLOBALS['app_password'] ? 'proven-uuid' : null; }
function current_user_can( $cap ) { return $GLOBALS['capability'] && 'bc_receive_portal_redemptions' === $cap; }
function is_super_admin( $id ) { return $GLOBALS['super_admin']; }
function is_multisite() { return true; }
function get_current_blog_id() { return $GLOBALS['blog']; }
function get_current_network_id() { return 1; }
function is_main_site( $blog, $network = 1 ) { return 1 === (int) $blog; }
function get_site_option( $key, $default ) { return 1; }
function switch_to_blog( $blog ) { $GLOBALS['blog'] = $blog; }
function restore_current_blog() { $GLOBALS['blog'] = 1; }
function apply_filters( $name, $value, $command ) { $GLOBALS['forwarded'] = $command; return ( $GLOBALS['provider'] )( $command ); }
function rest_ensure_response( $response ) { return $response; }
function add_action( ...$args ) {}
function do_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_rest_route( $namespace, $route, $args ) { $GLOBALS['route_args'] = $args; }

final class AdapterDatabaseDouble {
	public string $last_error = '';
	public string $blogs = 'wp_blogs';
	public string $base_prefix = 'wp_';
	public bool $fail = false;
	public array $site = [ 'blog_id' => '12', 'site_id' => '1', 'deleted' => '0', 'spam' => '0', 'archived' => '1' ];
	public function suppress_errors( $value ) { return false; }
	public function prepare( $query, ...$args ) { return $query; }
	public function get_row( $query, $mode ) {
		$this->last_error = $this->fail ? 'PRIVATE SQL ERROR' : '';
		if ( $this->fail ) { return null; }
		return str_contains( $query, 'FROM wp_blogs' ) ? $this->site : [ 'portal_hall_id' => 42, 'blog_id' => 12, 'status' => 'active' ];
	}
}

$base = ABSPATH . 'wp-content/plugins/bingocommerce-portal-integration/src/';
foreach ( [ 'Migrations', 'HallMappingRepository', 'ProvisioningRestController', 'RedemptionGateway', 'RedemptionRestController' ] as $file ) {
	require $base . $file . '.php';
}
use BingoCommerce\PortalIntegration\RedemptionGateway as Gateway;
use BingoCommerce\PortalIntegration\RedemptionRestController as Controller;

$assertions = 0;
function expect( bool $condition, string $message ): void {
	global $assertions;
	++$assertions;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function code( $result ): string {
	return $result instanceof WP_Error ? $result->get_error_code() : ( $result->get_data()['error']['code'] ?? '' );
}
function request( array $body ): WP_REST_Request { return new WP_REST_Request( json_encode( $body ) ); }

$GLOBALS['app_password'] = true;
$GLOBALS['capability'] = true;
$GLOBALS['super_admin'] = false;
$GLOBALS['wp_rest_application_password_status'] = new WP_User();
$GLOBALS['blog'] = 1;
$GLOBALS['wpdb'] = new AdapterDatabaseDouble();
$body = [ 'request_id' => '12345678-1234-4123-8123-123456789abc', 'player_tablet_id' => ' Tablet A ', 'caller_id' => 'Caller-1', 'qr' => 'BC1.12.' . str_repeat( 'a', 64 ) ];
$success = [ 'api_version' => 1, 'request_id' => $body['request_id'], 'player_tablet_id' => $body['player_tablet_id'], 'redemption_id' => '87654321-4321-4321-8321-cba987654321', 'recovered' => false, 'state' => 'redeemed', 'session' => [ 'commerce_session_id' => 5, 'name' => 'Evening', 'date' => '2026-10-06', 'start_time_local' => null, 'timezone_name' => null, 'starts_at_utc' => null ], 'package' => [ 'commerce_package_id' => 9, 'name' => 'Digital' ], 'digital_faces_by_game_type' => [ [ 'portal_game_type_id' => 4, 'faces' => 12 ] ] ];
$GLOBALS['provider'] = fn( $command ) => $success;

Controller::register_routes();
foreach ( $GLOBALS['route_args']['args'] as $arg ) {
	expect( is_callable( $arg['validate_callback'] ) && is_callable( $arg['sanitize_callback'] ), 'Every registered parameter validates and sanitizes.' );
}
expect( Controller::parse_command( request( $body ) )['player_tablet_id'] === ' Tablet A ', 'Opaque identity is not trimmed.' );
expect( Controller::sanitize_parameter( ' Tablet A ' ) === ' Tablet A ', 'Sanitization does not rewrite identity.' );
expect( Controller::opaque_identity( str_repeat( 'é', 64 ), 128 ), '128 UTF-8 bytes accepted.' );
expect( ! Controller::opaque_identity( str_repeat( 'é', 65 ), 128 ), 'Byte overflow refused.' );
foreach ( [ '', "tab\nlet", "tab\u{0085}let", "\xff", 7, null ] as $value ) {
	expect( ! Controller::opaque_identity( $value, 128 ), 'Invalid opaque identity refused.' );
}
foreach ( [ '0', '042', '+42', '42x', '9223372036854775808' ] as $hall ) {
	$r = request( $body ); $r->hall = $hall;
	expect( 'invalid_request' === code( Controller::parse_command( $r ) ), 'Noncanonical/overflow Hall refused.' );
}
foreach ( [ 'qr', 'request_id', 'player_tablet_id', 'caller_id' ] as $key ) {
	$bad = $body; unset( $bad[ $key ] );
	expect( 'invalid_request' === code( Controller::parse_command( request( $bad ) ) ), 'Missing field refused.' );
}
$bad = $body; $bad['portal_hall_id'] = 43;
expect( 'invalid_request' === code( Controller::parse_command( request( $bad ) ) ), 'Body cannot override Hall.' );
$r = request( $body ); $r->query = [ 'portal_hall_id' => 43 ];
expect( 'invalid_request' === code( Controller::parse_command( $r ) ), 'Query cannot override Hall.' );
$bad = $body; $bad['qr'] .= "\n";
expect( 'malformed_qr' === code( Controller::parse_command( request( $bad ) ) ), 'QR must match exact bytes.' );
$bad = $body; $bad['operating_commerce_session_id'] = '5';
expect( 'invalid_request' === code( Controller::parse_command( request( $bad ) ) ), 'Operating occurrence must be native integer.' );

$GLOBALS['app_password'] = false;
expect( 'authentication_required' === code( Controller::handle( request( $body ) ) ), 'Cookie/capability alone insufficient.' );
$GLOBALS['app_password'] = true; $GLOBALS['super_admin'] = true;
expect( 'caller_not_authorized' === code( Controller::handle( request( $body ) ) ), 'Super Admin is not the service account.' );
$GLOBALS['super_admin'] = false; $GLOBALS['capability'] = false;
expect( 'caller_not_authorized' === code( Controller::handle( request( $body ) ) ), 'Dedicated capability required.' );
$GLOBALS['capability'] = true; $GLOBALS['blog'] = 12;
expect( 'caller_not_authorized' === code( Controller::handle( request( $body ) ) ), 'Hall endpoint refused; network main required.' );
$GLOBALS['blog'] = 1;
$response = Controller::handle( request( $body ) );
expect( 200 === $response->get_status() && $success === $response->get_data(), 'Archived Hall still reaches domain.' );
expect( 12 === $GLOBALS['forwarded']['site_id'] && 42 === $GLOBALS['forwarded']['portal_hall_id'], 'Verified mapping supplies tenant.' );
expect( 1 === get_current_blog_id(), 'Hall context restored after success.' );
$bad = $body; $bad['qr'] = 'BC1.13.' . str_repeat( 'a', 64 );
expect( 'wrong_hall' === code( Controller::handle( request( $bad ) ) ), 'QR cannot select another Hall.' );
$GLOBALS['wpdb']->site['deleted'] = '1';
expect( 'mapping_unavailable' === code( Controller::handle( request( $body ) ) ), 'Deleted Hall refused.' );
$GLOBALS['wpdb']->site['deleted'] = '0'; $GLOBALS['wpdb']->site['site_id'] = '2';
expect( 'mapping_unavailable' === code( Controller::handle( request( $body ) ) ), 'Cross-network Hall refused.' );
$GLOBALS['wpdb']->site['site_id'] = '1'; $GLOBALS['wpdb']->fail = true;
expect( 'mapping_unavailable' === code( Controller::handle( request( $body ) ) ), 'Mapping database failure is unavailable.' );
$GLOBALS['wpdb']->fail = false;
$GLOBALS['provider'] = fn() => null;
expect( 'verification_unavailable' === code( Controller::handle( request( $body ) ) ), 'Absent Digital Tickets provider fails safely.' );
$GLOBALS['provider'] = function() { throw new RuntimeException( 'PRIVATE SQL QR SECRET' ); };
$response = Controller::handle( request( $body ) );
expect( 'verification_unavailable' === code( $response ) && ! str_contains( json_encode( $response->get_data() ), 'PRIVATE' ), 'Exception details never escape.' );
expect( 1 === get_current_blog_id(), 'Hall context restored after exception.' );
$leaky = $success; $leaky['order'] = [ 'billing_email' => 'private@example.test' ];
expect( ! Gateway::valid_result( $leaky, $body ), 'Unexpected result fields refused.' );
$duplicate = $success; $duplicate['digital_faces_by_game_type'][] = $duplicate['digital_faces_by_game_type'][0];
expect( ! Gateway::valid_result( $duplicate, $body ), 'Duplicate Game Type identity refused.' );
$bad_time = $success; $bad_time['session']['date'] = '2026-02-31';
expect( ! Gateway::valid_result( $bad_time, $body ), 'Invalid saved date refused.' );
$timed = $success; $timed['session']['start_time_local'] = '18:00:00'; $timed['session']['timezone_name'] = 'America/New_York'; $timed['session']['starts_at_utc'] = '2026-10-06T22:00:00Z';
expect( Gateway::valid_result( $timed, $body ), 'Recorded timing contract accepted.' );
foreach ( [ 400, 401, 403, 503 ] as $status ) {
	$final = Controller::finalize_response( new WP_REST_Response( [ 'code' => 'unsafe_core_message', 'message' => 'PRIVATE' ], $status ), null, request( $body ) );
	expect( 'no-store' === $final->get_headers()['Cache-Control'] && ! str_contains( json_encode( $final->get_data() ), 'PRIVATE' ), 'Core errors become safe no-store responses.' );
}
echo "PASS: {$assertions} isolated QR redemption adapter assertions (WordPress/auth/database mocked; no data changes).\n";
