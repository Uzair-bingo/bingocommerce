<?php
/** ADR-048 isolated grant race and dependency-boundary worker. */
if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) { exit( 1 ); }
$job = json_decode( $argv[1] ?? '', true, 512, JSON_THROW_ON_ERROR );
$mode = $job['mode'] ?? '';
if ( ! in_array( $mode, [ 'grant', 'core-only', 'missing-core' ], true ) ) { exit( 1 ); }
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
putenv( 'BC_FIXTURE_NETWORK_ACTIVE=' . ( 'grant' === $mode ? '1' : '0' ) );
require __DIR__ . ( 'missing-core' === $mode ? '/fixtures/isolated-multisite.php' : '/fixtures/wordpress-bootstrap.php' );
if ( DB_NAME !== BC_Fixture::$database || DB_NAME === BC_Fixture::$live_db || DB_NAME !== 'bingocommerce_state_recon'
	|| DB_NAME !== $wpdb->get_var( $wpdb->prepare( 'SELECT DATABASE() WHERE %d = %d', 1, 1 ) ) ) {
	throw new RuntimeException( 'Worker is not bound to the isolated database.' );
}

use BingoCommerce\Core\HallStaffProvisioningService as Provisioning;
use BingoCommerce\Core\HallStaffSsoCreationIntent;
use BingoCommerce\Core\HallStaffSsoGrant as Grant;
use BingoCommerce\Core\HallStaffResult;
use BingoCommerce\PortalIntegration\SsoAccountGuard as Guard;

if ( 'missing-core' === $mode ) {
	if ( class_exists( '\BingoCommerce\Core\HallStaffIdentity' ) ) { throw new RuntimeException( 'Missing-core worker unexpectedly loaded core.' ); }
	require ABSPATH . 'wp-content/plugins/bingocommerce-portal-integration/src/SsoAccountGuard.php';
	require_once ABSPATH . 'wp-admin/includes/ms.php';
	add_filter( 'pre_wp_mail', '__return_true' );
	Guard::register();
	$cases = [ 'portal-bingo1-42' => true, 'PORTAL-BINGO1-42' => true, 'portal-mike' => false, 'portal-mike-x' => false, 'portal-a-0' => false, 'mike.smith' => false ];
	$fallback = [];
	foreach ( $cases as $login => $expected ) {
		$fallback[ $login ] = Guard::is_reserved_username( $login );
		$data = [ 'user_login' => $login ];
		if ( $expected !== $fallback[ $login ] || ( $expected ? [] : $data ) !== Guard::reserve_username( $data, false ) ) {
			throw new RuntimeException( 'Incorrect fallback identity boundary: ' . $login );
		}
	}
	$called = false;
	$exception = Guard::with_planned_username( 'portal-fixture-1', static function () use ( &$called ) { $called = true; } );
	if ( ! is_wp_error( $exception ) || $called ) {
		throw new RuntimeException( 'SSO creation must remain unavailable without core.' );
	}
	$created = [];
	$password = wp_generate_password( 32 );
	try {
		foreach ( $cases as $login => $reserved ) {
			if ( $reserved ) { continue; }
			if ( username_exists( $login ) ) { throw new RuntimeException( 'Refusing to adopt existing fixture user: ' . $login ); }
			$id = wpmu_create_user( $login, $password, $login . '@example.invalid' );
			if ( ! $id ) { throw new RuntimeException( 'Ordinary fallback account creation failed: ' . $login ); }
			$created[] = $id;
			if ( 'mike.smith' === $login ) { grant_super_admin( $id ); }
			foreach ( [ $login, $login . '@example.invalid' ] as $identifier ) {
				$result = wp_authenticate( $identifier, $password );
				if ( ! $result instanceof WP_User || $result->ID !== $id ) {
					throw new RuntimeException( 'Ordinary password/email login blocked without core: ' . $login );
				}
			}
			if ( ! Guard::allow_password_reset( true, $id ) || ! Guard::allow_application_passwords( true, get_userdata( $id ) ) ) {
				throw new RuntimeException( 'Ordinary credentials restricted without core: ' . $login );
			}
		}
		// Load only the pure matcher after exercising the real missing-core path.
		require ABSPATH . 'wp-content/plugins/bingocommerce-core/src/HallStaffIdentity.php';
		foreach ( $fallback as $login => $answer ) {
			if ( $answer !== \BingoCommerce\Core\HallStaffIdentity::is_portal_sso_username( $login ) ) {
				throw new RuntimeException( 'Core and fallback disagree: ' . $login );
			}
		}
	} finally {
		foreach ( $created as $id ) {
			if ( is_super_admin( $id ) ) { revoke_super_admin( $id ); }
			wpmu_delete_user( $id );
		}
	}
	echo 'PASS';
	exit;
}

add_filter( 'pre_wp_mail', '__return_true' );
switch_to_blog( 2 );
wp_set_current_user( 0 );

if ( 'core-only' === $mode ) {
	if ( class_exists( Guard::class ) ) { throw new RuntimeException( 'Core-only worker unexpectedly loaded Portal Integration.' ); }
	$login = 'portal-coretest-' . random_int( 10000000, 99999999 );
	$id = wpmu_create_user( $login, wp_generate_password( 32 ), $login . '@example.invalid' );
	if ( ! $id ) { throw new RuntimeException( 'Could not prepare core-only fixture identity.' ); }
	try {
		$creation = Provisioning::record_portal_sso_creation( $id, 2, $login, wp_generate_uuid4(), 'core-only-creation' );
		if ( ! $creation instanceof HallStaffSsoCreationIntent ) { throw new RuntimeException( 'Core-only creation witness failed.' ); }
		$result = Grant::grant( $creation, 2, 'core-only-login' );
		if ( ! $result instanceof HallStaffResult ) { throw new RuntimeException( 'Core-only grant failed: ' . $result->get_error_code() ); }
		echo 'PASS';
	} finally {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', $wpdb->usermeta, $id ) );
		clean_user_cache( $id );
		wpmu_delete_user( $id );
	}
	exit;
}

$id = (int) $job['user_id'];
$creation = Provisioning::record_portal_sso_creation( $id, 2, get_userdata( $id )->user_login, $job['creation_operation_id'], $job['creation_sso_reference'] );
if ( ! $creation instanceof HallStaffSsoCreationIntent ) { throw new RuntimeException( 'Worker creation witness failed.' ); }
while ( microtime( true ) < (float) $job['start'] ) { usleep( 200 ); }
$result = Grant::grant( $creation, 2, 'concurrent-login' );
echo is_wp_error( $result ) ? $result->get_error_code() : $result->status;
