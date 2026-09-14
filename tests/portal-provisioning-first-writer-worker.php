<?php

/**
 * First-writer worker for tests/portal-provisioning-foundation.php.
 *
 * Boots the isolated fixture, announces readiness on stdout, waits on stdin
 * for the parent barrier, then inserts ONE first command through
 * insert_first_command() and prints a closed JSON outcome.
 *
 * COORDINATION IS A PIPE BARRIER, NOT A SLEEP. The parent does not release
 * either worker until BOTH have printed READY, so the two INSERTs start from
 * a known common point. Nothing here depends on timing.
 *
 * Exit codes: 0 answered, 2 coordination failure, 3 bootstrap/usage failure.
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 3 );
}

require __DIR__ . '/fixtures/isolated-multisite.php';

require_once ABSPATH . 'wp-content/plugins/bingocommerce-portal-integration/bingocommerce-portal-integration.php';

use BingoCommerce\PortalIntegration\ProvisioningCanonicalRequest;
use BingoCommerce\PortalIntegration\ProvisioningOperationRepository as Repo;

$portal_hall_id  = (int) getenv( 'BC_WORKER_PORTAL_HALL' );
$operation_id    = (string) getenv( 'BC_WORKER_OPERATION_ID' );
$intent_sequence = (int) getenv( 'BC_WORKER_INTENT' );
$site_slug       = (string) getenv( 'BC_WORKER_SITE_SLUG' );
$hall_name       = (string) getenv( 'BC_WORKER_HALL_NAME' );
$actor           = (int) getenv( 'BC_WORKER_ACTOR' );

if ( $portal_hall_id < 1 || '' === $operation_id || $intent_sequence < 1 || '' === $site_slug || '' === $hall_name || $actor < 1 ) {
	fwrite( STDERR, "worker: incomplete instructions\n" );
	exit( 3 );
}

$request = ProvisioningCanonicalRequest::validate(
	[
		'portal_hall_id'  => $portal_hall_id,
		'operation_id'    => $operation_id,
		'intent_sequence' => $intent_sequence,
		'desired_state'   => 'operational',
		'site_slug'       => $site_slug,
		'hall_name'       => $hall_name,
		'portal_actor_id' => $actor,
	]
);
if ( $request instanceof WP_Error ) {
	fwrite( STDERR, "worker: command rejected before the barrier\n" );
	exit( 3 );
}

fwrite( STDOUT, "READY\n" );
fflush( STDOUT );

$go = fgets( STDIN );
if ( ! is_string( $go ) || 'GO' !== trim( $go ) ) {
	fwrite( STDERR, "worker: barrier did not open\n" );
	exit( 2 );
}

$result = Repo::insert_first_command( $request );

$payload = [
	'outcome'     => 'unknown',
	'code'        => '',
	'operation_id' => '',
	'fingerprint' => '',
	'site_slug'   => '',
	'hall_name'   => '',
	'sequence'    => 0,
	'message'     => '',
];

if ( $result instanceof WP_Error ) {
	$payload['outcome'] = 'error';
	$payload['code']    = $result->get_error_code();
	$payload['message'] = (string) $result->get_error_message();
} elseif ( is_array( $result ) ) {
	$payload['outcome'] = (string) ( $result['outcome'] ?? '' );
	$row                = is_array( $result['row'] ?? null ) ? $result['row'] : null;
	if ( is_array( $row ) ) {
		$payload['operation_id'] = (string) ( $row['operation_id'] ?? '' );
		$payload['fingerprint']  = (string) ( $row['request_fingerprint'] ?? '' );
		$payload['site_slug']    = (string) ( $row['site_slug'] ?? '' );
		$payload['hall_name']    = (string) ( $row['request_hall_name'] ?? '' );
		$payload['sequence']     = (int) ( $row['intent_sequence'] ?? 0 );
	}
}

echo wp_json_encode( $payload );
exit( 0 );
