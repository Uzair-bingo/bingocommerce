<?php

/**
 * Holds a real InnoDB row lock on `bingo_sessions`, on its own mysqli
 * connection, for real MySQL contention (ADR-043 §7.11, §16 J70-72) — the
 * same model as tests/play-product-row-lock-worker.php, which §16 names as
 * the pattern for this kind of test.
 *
 * Prints "LOCKED" the instant the lock is genuinely held (a deterministic I/O
 * barrier the parent blocks on reading — never a guessed sleep), optionally
 * then attempts a SECOND row (for the 1213 deadlock scenario, closing the
 * cycle from this side), then holds for a bounded safety window before
 * releasing.
 *
 * Args: <session_id_to_lock> <hold_ms> [<second_session_id_to_attempt>]
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This worker is CLI-only.\n" );
	exit( 1 );
}

$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

putenv( 'BC_FIXTURE_NETWORK_ACTIVE=1' );
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );

require __DIR__ . '/fixtures/wordpress-bootstrap.php';

[ , $site_id, $session_id, $hold_ms, $second_session_id ] = array_pad( $argv, 5, '' );

global $wpdb;
switch_to_blog( (int) $site_id );
$table = \BingoCommerce\Sessions\Migrations::sessions_table();

mysqli_report( MYSQLI_REPORT_OFF );
$connection = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
$connection->query( 'SET SESSION innodb_lock_wait_timeout = 5' );
$connection->begin_transaction();

$statement = $connection->prepare( "SELECT session_id FROM `{$table}` WHERE session_id = ? FOR UPDATE" );
$id        = (int) $session_id;
$statement->bind_param( 'i', $id );
$statement->execute();
$result = $statement->get_result();
$rows   = $result ? $result->num_rows : 0;
if ( $result ) {
	$result->free();
}
$statement->close();

echo 'CID ' . $connection->thread_id . "\n";
echo "LOCKED {$rows}\n";
flush();

if ( '' !== $second_session_id ) {
	// Close the deadlock cycle from this side: the parent is expected to hold
	// $second_session_id's lock by the time this runs (it waits for the
	// "LOCKED" line above before taking it), so this call blocks until either
	// the parent releases or MySQL's deadlock detector kills one side.
	$second = (int) $second_session_id;
	$connection->query( "UPDATE `{$table}` SET updated_at = updated_at WHERE session_id = {$second}" );
	echo 'SECOND_ERRNO ' . $connection->errno . "\n";
	flush();
}

usleep( (int) $hold_ms * 1000 );

$connection->rollback();
$connection->close();
restore_current_blog();

echo "RELEASED\n";
