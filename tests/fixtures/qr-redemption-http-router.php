<?php
/**
 * Loopback-only owned-fixture bridge to the real CGI REST worker.
 * This file never boots WordPress against a web request or loads wp-config.php.
 * The CGI subprocess retains the independent DB/sentinel/Hall ownership guards.
 */
declare(strict_types=1);
require __DIR__ . '/qr-redemption-manual-state.php';
function fixture_http_failure(): never {
	http_response_code( 503 ); header( 'Content-Type: application/json' ); header( 'Cache-Control: no-store' );
	echo json_encode( [ 'api_version' => 1, 'error' => [ 'code' => 'write_outcome_unknown', 'message' => 'Fixture response unavailable. Retry the original request.', 'retryable' => true ] ] ); exit;
}
try {
	if ( 'cli-server' !== PHP_SAPI ) { throw new RuntimeException( 'Loopback PHP fixture server required.' ); }
	$dir = QRManualState::directory(); $state = QRManualState::read( $dir, 'commerce.json' );
	$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
	if ( ! QRManualState::loopbackOrigin( $state['commerce_origin'] )
		|| ! in_array( $_SERVER['REMOTE_ADDR'] ?? '', [ '127.0.0.1', '::1' ], true )
		|| ( $_SERVER['HTTP_HOST'] ?? '' ) !== parse_url( $state['commerce_origin'], PHP_URL_HOST ) . ':' . parse_url( $state['commerce_origin'], PHP_URL_PORT )
		|| 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' )
		|| 1 !== preg_match( '#\A/wp-json/bingocommerce/v1/portal-halls/([1-9][0-9]{0,18})/redemptions\z#', $uri ) ) {
		http_response_code( 404 ); header( 'Cache-Control: no-store' ); exit;
	}
	$body = file_get_contents( 'php://input' );
	if ( ! is_string( $body ) || strlen( $body ) > 4096 ) { throw new RuntimeException( 'Bounded request required.' ); }
	$worker = dirname( __DIR__ ) . '/qr-redemption-http-worker.php'; $cgi = dirname( PHP_BINARY ) . '/php-cgi';
	if ( ! is_executable( $cgi ) ) { throw new RuntimeException( 'CGI runtime required.' ); }
	$env = [ 'PATH' => getenv( 'PATH' ), 'TMPDIR' => $dir, 'BC_TEST_DB' => 'bingocommerce_state_recon', 'BC_FIXTURE_CGI' => '1',
		'BC_QR_FIXTURE_DIR' => $dir,
		'BC_QR_REDEMPTION_OWNER' => $state['owner'], 'HTTP_HOST' => 'fixture.bingocommerce.test', 'HTTPS' => 'on',
		'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php',
		'SCRIPT_FILENAME' => $worker, 'REDIRECT_STATUS' => '1', 'CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => (string) strlen( $body ),
	];
	if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) { $env['HTTP_AUTHORIZATION'] = $_SERVER['HTTP_AUTHORIZATION']; }
	$process = proc_open( [ $cgi, $worker ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, dirname( __DIR__, 2 ), $env );
	if ( ! is_resource( $process ) ) { throw new RuntimeException( 'CGI launch failed.' ); }
	fwrite( $pipes[0], $body ); fclose( $pipes[0] );
	stream_set_blocking( $pipes[1], false ); stream_set_blocking( $pipes[2], false );
	$output = ''; $stderr = ''; $deadline = microtime( true ) + 35;
	do {
		$output .= stream_get_contents( $pipes[1] ); $stderr .= stream_get_contents( $pipes[2] ); $running = proc_get_status( $process );
		if ( ! $running['running'] ) { break; }
		usleep( 10000 );
	} while ( microtime( true ) < $deadline && strlen( $output ) <= 270000 && strlen( $stderr ) <= 20000 );
	if ( $running['running'] ) { proc_terminate( $process, 9 ); }
	$output .= stream_get_contents( $pipes[1] ); $stderr .= stream_get_contents( $pipes[2] );
	fclose( $pipes[1] ); fclose( $pipes[2] ); $exit = proc_close( $process );
	if ( $running['running'] || 0 !== ( $running['exitcode'] >= 0 ? $running['exitcode'] : $exit ) ) { fixture_http_failure(); }
	[ $headers, $payload ] = array_pad( preg_split( '/\r?\n\r?\n/', $output, 2 ), 2, '' );
	$status = preg_match( '/^Status: ([0-9]{3})/mi', $headers, $parts ) ? (int) $parts[1] : 200;
	$decoded = json_decode( $payload, true, 512, JSON_THROW_ON_ERROR );
	if ( ! is_array( $decoded ) || strlen( $payload ) > 262144 || ! preg_match( '/^Cache-Control:.*no-store/mi', $headers ) ) { fixture_http_failure(); }
	foreach ( [ $state['fixture']['service']['password'], $_SERVER['HTTP_AUTHORIZATION'] ?? '', json_decode( $body, true )['qr'] ?? '' ] as $secret ) {
		if ( is_string( $secret ) && '' !== $secret && ( str_contains( $payload, $secret ) || str_contains( $stderr, $secret ) ) ) { fixture_http_failure(); }
	}
	// Owned test-only fault: discard one acknowledged acceptance response. The
	// production endpoint/provider is unchanged and acceptance really persisted.
	if ( 200 === $status && false === ( $decoded['recovered'] ?? null ) && file_exists( $dir . '/fault.json' ) ) {
		$fault = QRManualState::read( $dir, 'fault.json' );
		if ( true === ( $fault['drop_next_response'] ?? false ) ) {
			$fault['drop_next_response'] = false; QRManualState::write( $dir, 'fault.json', $fault );
			$payload = '{}';
		}
	}
	http_response_code( $status ); header( 'Content-Type: application/json' ); header( 'Cache-Control: no-store' ); echo $payload;
} catch ( Throwable ) { fixture_http_failure(); }
