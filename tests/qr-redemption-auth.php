<?php
/** Real Application Password / CGI REST adapter checks; no redemption provider mocked. */
declare(strict_types=1);

if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "FIXTURE GUARD: isolated bingocommerce_state_recon is required.\n" ); exit( 1 );
}
require __DIR__ . '/fixtures/qr-redemption.php';
require_once ABSPATH . 'wp-content/plugins/bingocommerce-digital-tickets/src/RedemptionContract.php';
require_once ABSPATH . 'wp-content/plugins/bingocommerce-digital-tickets/src/RedemptionPurchase.php';

function qr_http( QRRedemptionFixture $fixture, int $hall, array $body, ?string $credentials ): array {
	$json = json_encode( $body, JSON_THROW_ON_ERROR );
	$worker = __DIR__ . '/qr-redemption-http-worker.php';
	$cgi = dirname( PHP_BINARY ) . '/php-cgi';
	if ( ! is_executable( $cgi ) ) { throw new RuntimeException( 'php-cgi is required.' ); }
	$env = [ 'PATH' => getenv( 'PATH' ), 'BC_TEST_DB' => 'bingocommerce_state_recon', 'BC_FIXTURE_CGI' => '1',
		'BC_QR_REDEMPTION_OWNER' => $fixture->owner, 'HTTP_HOST' => 'fixture.bingocommerce.test', 'HTTPS' => 'on',
		'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/wp-json/bingocommerce/v1/portal-halls/' . $hall . '/redemptions',
		'SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php', 'SCRIPT_FILENAME' => $worker, 'REDIRECT_STATUS' => '1',
		'CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => (string) strlen( $json ),
	];
	if ( null !== $credentials ) { $env['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( $credentials ); }
	$process = proc_open( [ $cgi, $worker ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, dirname( __DIR__ ), $env );
	if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Could not launch fixture CGI.' ); }
	fwrite( $pipes[0], $json ); fclose( $pipes[0] );
	stream_set_blocking( $pipes[1], false ); stream_set_blocking( $pipes[2], false );
	$out = ''; $err = ''; $deadline = microtime( true ) + 35;
	do {
		$out .= stream_get_contents( $pipes[1] ); $err .= stream_get_contents( $pipes[2] ); $state = proc_get_status( $process );
		if ( ! $state['running'] ) { break; }
		usleep( 10000 );
	} while ( microtime( true ) < $deadline );
	if ( $state['running'] ) { proc_terminate( $process, 9 ); }
	$out .= stream_get_contents( $pipes[1] ); $err .= stream_get_contents( $pipes[2] );
	fclose( $pipes[1] ); fclose( $pipes[2] ); $exit = proc_close( $process );
	if ( $state['running'] || 0 !== ( $state['exitcode'] >= 0 ? $state['exitcode'] : $exit ) ) {
		// Only a bounded, credential-redacted diagnostic from this owned fixture.
		$detail = str_replace( array_filter( [ $body['qr'], $credentials, $fixture->service['password'] ?? null ] ), '[private]', $err );
		$detail = preg_replace( '/(?:BC1\.[0-9]+\.[a-f0-9]{64}|wc_order_[A-Za-z0-9]+|[a-f0-9]{64})/', '[private]', $detail );
		throw new RuntimeException( 'Fixture CGI failed: ' . substr( $detail, 0, 500 ) );
	}
	[ $headers, $payload ] = array_pad( preg_split( '/\r?\n\r?\n/', $out, 2 ), 2, '' );
	$status = preg_match( '/^Status: ([0-9]{3})/mi', $headers, $m ) ? (int) $m[1] : 200;
	if ( ! preg_match( '/^Cache-Control:.*no-store/mi', $headers ) ) { throw new RuntimeException( 'Missing no-store response.' ); }
	foreach ( [ $body['qr'], $credentials ] as $private ) {
		if ( is_string( $private ) && '' !== $private && ( str_contains( $out, $private ) || str_contains( $err, $private ) ) ) { throw new RuntimeException( 'Fixture credential leaked in output.' ); }
	}
	return [ $status, json_decode( $payload, true, 512, JSON_THROW_ON_ERROR ) ];
}

$fixture = new QRRedemptionFixture(); $passes = 0; $failed = false;
$expect = static function ( bool $ok, string $label ) use ( &$passes ): void {
	if ( ! $ok ) { throw new RuntimeException( $label ); }
	++$passes; echo "PASS $label\n";
};
try {
	$fixture->prepare();
	$a = $fixture->halls['a']; $b = $fixture->halls['b'];
	$ticket = $fixture->createTicket( $a, [ array_key_first( $a['games'] ) => 12 ] );
	$hybrid = $fixture->createTicket( $a, [ array_key_first( $a['games'] ) => 17 ], 'hybrid', true );
	$paper = $fixture->createTicket( $a, [], 'paper' );
	$expect( null === $paper['qr'], 'Paper purchase has no digital QR' );
	$expect( $a['session'] === $b['session'] && array_keys( $a['games'] ) === array_keys( $b['games'] ), 'owned Halls have colliding local identities' );
	foreach ( [ $ticket, $hybrid ] as $saved ) {
		$fixture->inHall( $a['site'], static function () use ( $saved, $expect ): void {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE entitlement_id=%d', \BingoCommerce\DigitalTickets\Migrations::table(), $saved['entitlement_id'] ), ARRAY_A );
			$purchase = \BingoCommerce\DigitalTickets\RedemptionPurchase::read( $row );
			$expect( is_array( $purchase ) && $purchase['faces'] === $saved['facts']['digital_faces_by_game_type'], 'real guest purchase reads frozen aggregate, not current Catalog' );
			$expect( is_array( $purchase ) && $purchase['session']['start_time_local'] === $saved['facts']['start_time_local'], 'recorded null or structured timing is preserved' );
			$mapping = \BingoCommerce\Catalog\RedemptionGameTypes::map( array_keys( $purchase['faces'] ) );
			$expect( is_array( $mapping ) && [ 1001 ] === array_values( $mapping ), 'narrow Catalog reader returns verified Portal identity' );
		} );
	}
	$credentials = $fixture->service['username'] . ':' . $fixture->service['password'];
	$body = [ 'qr' => $ticket['qr'], 'request_id' => wp_generate_uuid4(), 'player_tablet_id' => ' Actual tablet A ', 'caller_id' => 'relay-caller-1' ];
	$cases = [
		[ $a['portal_hall_id'], $body, null, 401, 'authentication_required', 'anonymous request refused' ],
		[ $a['portal_hall_id'], $body, $fixture->service['username'] . ':invalid', 401, 'authentication_required', 'wrong Application Password refused' ],
		[ $b['portal_hall_id'], $body, $credentials, 403, 'wrong_hall', 'authenticated cross-Hall ticket refused' ],
		[ $a['portal_hall_id'], array_replace( $body, [ 'qr' => 'not-a-qr' ] ), $credentials, 400, 'malformed_qr', 'malformed QR refused' ],
		[ $a['portal_hall_id'], $body + [ 'hall_id' => $b['portal_hall_id'] ], $credentials, 400, 'invalid_request', 'untrusted Hall override refused' ],
		[ $a['portal_hall_id'], array_replace( $body, [ 'player_tablet_id' => str_repeat( 'x', 129 ) ] ), $credentials, 400, 'invalid_request', 'oversized tablet identity refused without truncation' ],
	];
	foreach ( $cases as [ $hall, $request, $auth, $status, $code, $label ] ) {
		$received = qr_http( $fixture, $hall, $request, $auth );
		$expect( $received[0] === $status && ( $received[1]['error']['code'] ?? null ) === $code, $label );
	}
	$received = qr_http( $fixture, $a['portal_hall_id'], $body, $credentials );
	$expect( 200 === $received[0] && false === ( $received[1]['recovered'] ?? null ) && 'redeemed' === ( $received[1]['state'] ?? null ), 'valid authorized request atomically accepts a ticket' );
	$redemption_id = $received[1]['redemption_id'];
	$fixture->assertHall( $a['site'] );
	if ( wp_update_site( $a['site'], [ 'archived' => 1 ] ) instanceof WP_Error ) { throw new RuntimeException( 'Owned fixture archival failed.' ); }
	try {
		$received = qr_http( $fixture, $a['portal_hall_id'], $body, $credentials );
		$expect( 200 === $received[0] && true === ( $received[1]['recovered'] ?? null ) && $redemption_id === $received[1]['redemption_id'], 'archived paid-ticket Hall recovers the original acceptance without enabling sales' );
	} finally { wp_update_site( $a['site'], [ 'archived' => 0 ] ); }
	$service = new WP_User( $fixture->service['user_id'] );
	$service->remove_cap( 'bc_receive_portal_redemptions' );
	try {
		$received = qr_http( $fixture, $a['portal_hall_id'], $body, $credentials );
		$expect( 403 === $received[0] && 'caller_not_authorized' === ( $received[1]['error']['code'] ?? null ), 'valid password without narrow capability is refused' );
	} finally { $service->add_cap( 'bc_receive_portal_redemptions' ); }
	WP_Application_Passwords::delete_application_password( $fixture->service['user_id'], $fixture->service['application_uuid'] );
	$received = qr_http( $fixture, $a['portal_hall_id'], $body, $credentials );
	$expect( 401 === $received[0], 'revoked Application Password refused on next request' );
	$fixture->inHall( $a['site'], static function () use ( $expect ): void {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT state, contributions_json, response_json FROM %i', \BingoCommerce\DigitalTickets\Migrations::redemptions_table() ), ARRAY_A );
		$expect( '' === $wpdb->last_error && 1 === count( $rows ) && 'accepted' === $rows[0]['state'] && is_array( json_decode( $rows[0]['contributions_json'], true ) ) && is_array( json_decode( $rows[0]['response_json'], true ) ), 'one complete accepted row survives recovery; adapter rejections create no other row' );
	} );
} catch ( Throwable $error ) { $failed = true; fwrite( STDERR, "FAIL " . $error->getMessage() . "\n" ); }
finally { echo 'CLEANUP ' . json_encode( $fixture->cleanup(), JSON_THROW_ON_ERROR ) . "\n"; }
echo "QR redemption authenticated adapter: $passes passed; real Application Password, REST and database acceptance/recovery. Concurrency is covered separately.\n";
exit( $failed ? 1 : 0 );
