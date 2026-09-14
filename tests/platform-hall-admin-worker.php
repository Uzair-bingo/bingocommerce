<?php

/**
 * One process, one `BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID` state.
 *
 * A PHP constant is fixed for the life of a request, so the parent harness
 * cannot exercise more than one configuration in its own process. This worker
 * defines exactly the state named by BC_PLATFORM_ADMIN_CASE and prints one word:
 * `ok` when a Hall administrator resolves, or the WP_Error code when it does not.
 *
 * IT PRINTS NO CONFIGURATION VALUE. Not the constant, not a login, not an email,
 * not a user id — only the verdict, which is what the parent asserts on.
 *
 * IT CREATES NOTHING. No site, no user, no table, no option. The disposable
 * Super Admin it resolves against was created by the parent harness and is
 * removed by the parent harness.
 */

putenv( 'BC_FIXTURE_NETWORK_ACTIVE=1' );

$case = (string) getenv( 'BC_PLATFORM_ADMIN_CASE' );

/*
 * The constant is defined BEFORE WordPress boots, which is also the only
 * honest simulation of deployment configuration: it is read, never written,
 * and this file is the only place that knows the case names.
 *
 * The 'nonexistent' and 'not_super_admin' user ids are resolved after boot,
 * so those two are defined later, below.
 */
switch ( $case ) {
	case 'empty':
		define( 'BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID', '' );
		break;
	case 'zero':
		define( 'BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID', 0 );
		break;
	case 'negative':
		define( 'BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID', -1 );
		break;
	case 'float':
		define( 'BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID', 1.0 );
		break;
	case 'bool':
		define( 'BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID', true );
		break;
	case 'array':
		define( 'BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID', [ 1 ] );
		break;
	case 'padded':
		define( 'BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID', ' 1 ' );
		break;
	case 'trailing':
		define( 'BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID', '1abc' );
		break;
	case 'exponent':
		define( 'BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID', '1e3' );
		break;
	case 'leading_zero':
		// A decimal string is accepted only when it round-trips exactly, so a
		// padded spelling of a real user id is still refused.
		define( 'BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID', '01' );
		break;
	case 'nonexistent':
		define( 'BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID', 987654321 );
		break;
	case 'undefined':
	case 'not_super_admin':
	case 'valid':
	default:
		break;
}

require __DIR__ . '/fixtures/isolated-multisite.php';
require_once ABSPATH . 'wp-content/plugins/bingocommerce-platform/bingocommerce-platform.php';

use BingoCommerce\Platform\HallSiteAdminUser;

if ( 'valid' === $case ) {
	$super = username_exists( 'fixture_admin' );
	if ( ! $super ) {
		fwrite( STDOUT, "fixture_admin_missing\n" );
		exit( 0 );
	}
	define( 'BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID', (int) $super );
}

if ( 'not_super_admin' === $case ) {
	/*
	 * An ordinary account. Created here and removed before this process exits,
	 * so the parent's residue assertions still hold.
	 */
	$ordinary = wp_insert_user(
		[
			'user_login' => 'fixture_not_super_' . wp_generate_password( 6, false, false ),
			'user_pass'  => wp_generate_password( 32 ),
			'user_email' => 'fixture-not-super-' . wp_generate_password( 6, false, false ) . '@example.invalid',
			'role'       => '',
		]
	);
	if ( is_wp_error( $ordinary ) ) {
		fwrite( STDOUT, "ordinary_user_missing\n" );
		exit( 0 );
	}
	define( 'BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID', (int) $ordinary );

	$resolved = HallSiteAdminUser::resolve( 0 );

	require_once ABSPATH . 'wp-admin/includes/user.php';
	require_once ABSPATH . 'wp-admin/includes/ms.php';
	wpmu_delete_user( (int) $ordinary );

	fwrite( STDOUT, ( is_wp_error( $resolved ) ? $resolved->get_error_code() : 'ok' ) . "\n" );
	exit( 0 );
}

$resolved = HallSiteAdminUser::resolve( 0 );

// One word. Never the constant, a login, an email or a user id.
fwrite( STDOUT, ( is_wp_error( $resolved ) ? $resolved->get_error_code() : 'ok' ) . "\n" );
