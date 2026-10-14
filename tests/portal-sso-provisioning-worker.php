<?php
/** Isolated Phase 4a worker; never boots the development WordPress config. */
if ( ! in_array( PHP_SAPI, [ 'cli', 'cgi-fcgi' ], true ) || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) { exit( 1 ); }
$job = json_decode( 'cli' === PHP_SAPI ? ( $argv[1] ?? '{}' ) : ( getenv( 'BC_SSO_JOB' ) ?: '{}' ), true, 32, JSON_THROW_ON_ERROR );
$mode = $job['mode'] ?? '';
if ( ! in_array( $mode, [ 'config', 'provision', 'complete', 'start', 'cgi-start', 'cgi-return' ], true ) ) { exit( 1 ); }
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
putenv( 'BC_FIXTURE_NETWORK_ACTIVE=1' );
putenv( 'BC_FIXTURE_CGI=1' );
define( 'BINGOCOMMERCE_PORTAL_BASE_URL', $job['base_url'] ?? 'https://portal.example.invalid' );
define( 'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN', 'fixture-snapshot-token' );
if ( ! ( $job['missing_token'] ?? false ) ) { define( 'BINGOCOMMERCE_PORTAL_SSO_TOKEN', $job['token'] ?? 'fixture-sso-secret-token' ); }
if ( ! ( $job['missing_installation'] ?? false ) ) { define( 'BINGOCOMMERCE_PORTAL_INSTALLATION_ID', $job['installation'] ?? 'phase4a' ); }
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
if ( DB_NAME !== BC_Fixture::$database || DB_NAME === BC_Fixture::$live_db || DB_NAME !== 'bingocommerce_state_recon'
	|| DB_NAME !== $wpdb->get_var( $wpdb->prepare( 'SELECT DATABASE() WHERE %d = %d', 1, 1 ) ) ) { throw new RuntimeException( 'Isolated binding not proven.' ); }
switch_to_blog( $job['blog'] ?? 2 );
$_SERVER['HTTPS'] = 'on';
$_SERVER['REMOTE_ADDR'] = $job['ip'] ?? '192.0.2.201';
wp_set_current_user( 0 );
add_filter( 'pre_wp_mail', '__return_true' );
use BingoCommerce\PortalIntegration\PortalConfig;
use BingoCommerce\PortalIntegration\PortalHttpClient;
use BingoCommerce\PortalIntegration\SsoHttpClient;
use BingoCommerce\PortalIntegration\SsoLogin;
use BingoCommerce\PortalIntegration\SsoProvisioningService;
use BingoCommerce\PortalIntegration\SsoMigrations;
use BingoCommerce\Core\Migrations;

$http_calls = 0;
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( $job, &$http_calls ) {
	++$http_calls;
	if ( 'config' === ( $job['mode'] ?? '' ) ) { return new WP_Error( 'transport', 'fixture-sso-secret-token' ); }
	if ( ! isset( $job['identity'] ) || 'https://portal.example.invalid/integration/v1/sso/exchange' !== $url ) { throw new RuntimeException( 'Unmocked HTTP forbidden.' ); }
	return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => json_encode( $job['identity'] ) ];
}, 10, 3 );
$auth = 0;
$marked_session = false;
add_action( 'set_auth_cookie', static function () use ( &$auth ) { ++$auth; } );
add_action( 'set_logged_in_cookie', static function () use ( &$auth ) { ++$auth; } );
add_action( 'set_auth_cookie', static function ( $cookie, $expire, $until, $id, $scheme, $token ) use ( &$marked_session ) {
	$session = WP_Session_Tokens::get_instance( $id )->get( $token );
	$marked_session = get_current_blog_id() === ( $session[ \BingoCommerce\PortalIntegration\SsoSession::MARKER ]['blog_id'] ?? null );
}, 10, 6 );
if ( 'config' === $mode ) {
	$config = PortalConfig::fromSsoConstants();
	ob_start(); var_dump( $config ); $debug = ob_get_clean();
	$snapshot = PortalConfig::fromConstants();
	$error = is_wp_error( $config ) ? $config : ( new SsoHttpClient() )->exchange( $config, str_repeat( 'a', 64 ), str_repeat( 'b', 64 ), str_repeat( 'c', 64 ) );
	echo json_encode( [ 'sso' => ! is_wp_error( $config ), 'snapshot' => ! is_wp_error( $snapshot ), 'safe' => ! str_contains( $debug . serialize( $error ), 'fixture-sso-secret-token' ), 'start_refused' => is_wp_error( SsoLogin::prepare_start( '192.0.2.201' ) ), 'return_refused' => is_wp_error( SsoLogin::complete( str_repeat( 'a', 64 ), str_repeat( 'b', 64 ), str_repeat( 'c', 64 ) ) ) ] );
	exit;
}

// Crash points leave actual durable partial states, recovered by another request.
$fault = $job['fault'] ?? '';
if ( 'after-link' === $fault ) {
	add_filter( 'query', static function ( $sql ) {
		if ( str_starts_with( $sql, 'INSERT INTO `' . $GLOBALS['wpdb']->users . '`' ) ) { echo 'INTERRUPTED'; exit; }
		return $sql;
	} );
}
if ( in_array( $fault, [ 'inside-create', 'after-create' ], true ) ) {
	add_action( 'inside-create' === $fault ? 'user_register' : 'wpmu_new_user', static function () { echo 'INTERRUPTED'; exit; }, PHP_INT_MAX );
}
if ( 'after-attach' === $fault ) {
	add_filter( 'query', static function ( $sql ) {
		if ( str_starts_with( $sql, 'INSERT INTO `' . Migrations::audit_log_table() . '`' ) && str_contains( $sql, 'hall_staff.user_created' ) ) { echo 'INTERRUPTED'; exit; }
		return $sql;
	} );
}


if ( isset( $job['insert_at'] ) ) {
	add_filter( 'query', static function ( $sql ) use ( $job ) {
		if ( str_starts_with( $sql, 'INSERT INTO `' . SsoMigrations::attempts_table() . '`' ) ) {
			while ( microtime( true ) < $job['insert_at'] ) { usleep( 500 ); }
		}
		return $sql;
	} );
}
if ( isset( $job['consume_at'] ) ) {
	add_filter( 'query', static function ( $sql ) use ( $job ) {
		if ( str_starts_with( $sql, 'UPDATE `' . SsoMigrations::attempts_table() . '` SET used_at' ) ) {
			while ( microtime( true ) < $job['consume_at'] ) { usleep( 500 ); }
		}
		return $sql;
	} );
}
if ( isset( $job['start'] ) ) { while ( microtime( true ) < $job['start'] ) { usleep( 500 ); } }
$start_locks = [];
add_filter( 'query', static function ( $sql ) use ( &$start_locks ) {
	if ( preg_match( "/^SELECT GET_LOCK\('(bc:sso:start:[0-9a-f]+)', ([0-9]+)\)$/", $sql, $match ) ) {
		$start_locks[] = [ 'name' => $match[1], 'wait' => (int) $match[2] ];
	}
	return $sql;
} );
add_filter( 'wp_redirect', static function ( $url ) use ( &$start_locks ) {
	global $wpdb;
	foreach ( $start_locks as $lock ) {
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $lock['name'] ) ) ) {
			throw new RuntimeException( 'Start lock was not released before redirect.' );
		}
	}
	return $url;
} );
if ( 'start' === $mode ) {
	$began = microtime( true );
	$result = is_array( SsoLogin::prepare_start( $_SERVER['REMOTE_ADDR'] ) ) ? 'created' : 'refused';
	echo ! empty( $job['measure'] ) ? json_encode( [ 'result' => $result, 'elapsed' => microtime( true ) - $began, 'locks' => $start_locks, 'http' => $http_calls ] ) : $result;
	exit;
}
if ( 'cgi-start' === $mode ) { SsoLogin::start(); }
if ( 'cgi-return' === $mode ) {
	$_GET = [ 'code' => str_repeat( 'a', 64 ), 'attempt_reference' => $job['attempt'] ];
	$_COOKIE[ SsoLogin::cookie_name() ] = $job['browser'];
	SsoLogin::returned();
}
if ( 'complete' === $mode ) {
	$result = SsoLogin::complete( str_repeat( 'a', 64 ), $job['attempt'], $job['browser'] );
} else {
	$result = ( new SsoProvisioningService() )->provision( $job['identity'], (int) $job['blog'], (int) $job['hall'], $job['identity']['sso_reference'] );
}
echo json_encode( [ 'result' => is_wp_error( $result ) ? $result->get_error_code() : $result, 'http' => $http_calls, 'auth' => $auth, 'current_user' => get_current_user_id(), 'marked_session' => $marked_session ] );
