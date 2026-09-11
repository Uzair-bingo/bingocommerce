<?php

/**
 * Concurrency worker for tests/hall-commerce-state.php.
 *
 * Boots the isolated fixture, announces readiness, waits on a database-held
 * gate, then performs one Commerce-state write and prints the outcome as JSON.
 *
 * COORDINATION IS EXPLICIT, NOT TIMED.
 *
 *   1. The worker takes its OWN ready lock and holds it. The parent waits until
 *      every ready lock is held, so "both workers reached the gate" is observed
 *      rather than assumed from a sleep.
 *   2. The parent then releases the gate. Workers POLL IS_FREE_LOCK() without
 *      acquiring it, so they proceed together instead of queueing behind an
 *      acquisition.
 *
 * A coordination failure — the ready lock cannot be taken, or the gate never
 * opens within the bound — stops the worker BEFORE it writes, and exits 2.
 *
 * Exit codes: 0 wrote, 2 coordination failure, 3 bootstrap/usage failure.
 * All coordination lives here and in the harness; none of it is production code.
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 3 );
}

require __DIR__ . '/fixtures/isolated-multisite.php';

// The fixture activates no plugins, so the definitions are loaded explicitly —
// exactly as the harness does.
require_once ABSPATH . 'wp-content/plugins/bingocommerce-portal-integration/bingocommerce-portal-integration.php';

$hall   = (int) getenv( 'BC_WORKER_HALL' );
$state  = (string) getenv( 'BC_WORKER_STATE' );
$intent = (int) getenv( 'BC_WORKER_INTENT' );
$phase  = (int) getenv( 'BC_WORKER_PHASE' );
$gate   = (string) getenv( 'BC_WORKER_GATE' );
$ready  = (string) getenv( 'BC_WORKER_READY' );

// Harness-only knobs used by the bounded-failure scenarios.
$ready_timeout = (int) ( getenv( 'BC_WORKER_READY_TIMEOUT' ) ?: 10 );
$stall_ms      = (int) ( getenv( 'BC_WORKER_STALL_MS' ) ?: 0 );
$ignore_term   = '1' === (string) getenv( 'BC_WORKER_IGNORE_TERM' );

/*
 * Harness-only: refuse SIGTERM so the parent's cleanup must escalate. Used by
 * one test to exercise the escalation path for real rather than asserting it is
 * covered. Requires pcntl; without it the knob is a no-op and the harness skips
 * that scenario explicitly.
 */
if ( $ignore_term && function_exists( 'pcntl_signal' ) && function_exists( 'pcntl_async_signals' ) ) {
	pcntl_async_signals( true );
	pcntl_signal( SIGTERM, SIG_IGN );
}

if ( $hall < 1 || '' === $state || '' === $gate || '' === $ready ) {
	fwrite( STDERR, "worker: incomplete instructions\n" );
	exit( 3 );
}

global $wpdb;

// 1. Announce readiness by holding our own lock for the rest of the process.
$held = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $ready, $ready_timeout ) );
if ( '1' !== (string) $held ) {
	fwrite( STDERR, "worker: could not announce readiness\n" );
	exit( 2 );
}

// 2. Wait for the gate to open. Bounded, and a timeout stops us before writing.
$deadline = microtime( true ) + 20.0;
$opened   = false;
while ( microtime( true ) < $deadline ) {
	$free = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $gate ) );
	if ( null === $free ) {
		fwrite( STDERR, "worker: gate state unreadable\n" );
		exit( 2 );
	}
	if ( '1' === (string) $free ) {
		$opened = true;
		break;
	}
	usleep( 1000 );
}

if ( ! $opened ) {
	fwrite( STDERR, "worker: gate did not open within the bound\n" );
	exit( 2 );
}

if ( $stall_ms > 0 ) {
	// Deliberate stall AFTER the gate opened and BEFORE writing, so the harness
	// can prove its completion deadline bounds a worker that never finishes.
	usleep( $stall_ms * 1000 );
}

$result = \BingoCommerce\PortalIntegration\HallCommerceState::write( $hall, $state, $intent, $phase );

echo wp_json_encode(
	$result instanceof WP_Error
		? [ 'error' => $result->get_error_code() ]
		: [ 'outcome' => $result['outcome'], 'seq' => $result['seq'], 'state' => $result['state'] ]
);

exit( 0 );
