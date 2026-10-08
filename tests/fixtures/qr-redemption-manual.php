<?php
/** Guarded retained fixtures. Only disposable recon DB and private /private/tmp files. */
declare(strict_types=1);
if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) { fwrite( STDERR, "Use CLI with BC_TEST_DB=bingocommerce_state_recon.\n" ); exit( 1 ); }
$mode = $argv[1] ?? '';
if ( ! in_array( $mode, [ 'prepare', 'inspect', 'void-a', 'cleanup' ], true ) ) { fwrite( STDERR, "Modes: prepare, inspect, void-a, cleanup.\n" ); exit( 1 ); }
require __DIR__ . '/qr-redemption-manual-state.php';
$bytes = random_bytes( 16 ); $bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 ); $bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
$hex = bin2hex( $bytes ); $owner = substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
$dir = 'prepare' === $mode ? QRManualState::create( $owner ) : QRManualState::directory();
QRManualState::isolateWordPressFiles( $dir );
require __DIR__ . '/qr-redemption.php';

function manual_void( QRRedemptionFixture $fixture, array $ticket ): void {
	$fixture->inHall( $ticket['site'], function () use ( $fixture, $ticket ): void {
		$order = wc_get_order( $ticket['order_id'] );
		if ( ! $order instanceof WC_Order || $order->get_meta( QRRedemptionFixture::OWNER_OPTION, true ) !== $fixture->owner ) { throw new RuntimeException( 'Owned fixture order required.' ); }
		$order->update_status( 'cancelled' );
		global $wpdb;
		$marker = $wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM %i WHERE order_id=%d', \BingoCommerce\DigitalTickets\Migrations::voids_table(), $ticket['order_id'] ) );
		if ( '' !== $wpdb->last_error || (string) $marker !== (string) $ticket['order_id'] ) { throw new RuntimeException( 'Fixture cancellation did not persist its terminal marker.' ); }
	} );
}

$fixture = null;
try {
	if ( 'prepare' === $mode ) {
		$fixture = new QRRedemptionFixture(); $fixture->owner = QRManualState::owner( $dir );
		$state = [ 'owner' => $fixture->owner, 'fixture' => $fixture->exportState(), 'tickets' => [],
			'portal_origin' => getenv( 'BC_QR_PORTAL_ORIGIN' ) ?: 'http://127.0.0.1:18781',
			'commerce_origin' => getenv( 'BC_QR_COMMERCE_ORIGIN' ) ?: 'http://127.0.0.1:18782' ];
		if ( ! QRManualState::loopbackOrigin( $state['portal_origin'] ) || ! QRManualState::loopbackOrigin( $state['commerce_origin'] ) || $state['portal_origin'] === $state['commerce_origin'] ) { throw new RuntimeException( 'Distinct loopback fixture origins required.' ); }
		QRManualState::write( $dir, 'commerce.json', $state );
		$fixture->prepare( 24, function ( array $saved ) use ( &$state, $dir ): void { $state['fixture'] = $saved; QRManualState::write( $dir, 'commerce.json', $state ); } );
		foreach ( [ 'a', 'b', 'c', 'voided', 'other_hall' ] as $label ) {
			$hall = $fixture->halls[ 'other_hall' === $label ? 'b' : 'a' ];
			$state['tickets'][ $label ] = $fixture->createTicket( $hall, [ array_key_first( $hall['games'] ) => 12 ] );
			QRManualState::write( $dir, 'commerce.json', $state );
		}
		manual_void( $fixture, $state['tickets']['voided'] );
		global $wpdb; $database_version = $wpdb->get_var( 'SELECT VERSION()' );
		if ( '' !== $wpdb->last_error || ! is_string( $database_version ) ) { throw new RuntimeException( 'Fixture runtime inspection failed.' ); }
		echo json_encode( [ 'fixture_dir' => $dir, 'database' => DB_NAME, 'database_version' => $database_version, 'php_version' => PHP_VERSION, 'portal_origin' => $state['portal_origin'], 'commerce_origin' => $state['commerce_origin'], 'owned_halls' => array_column( $fixture->halls, 'site' ), 'private_credentials_printed' => false ], JSON_PRETTY_PRINT ) . "\n";
	} else {
		$state = QRManualState::read( $dir, 'commerce.json' ); $fixture = QRRedemptionFixture::resume( $state['fixture'] );
		if ( 'void-a' === $mode ) { manual_void( $fixture, $state['tickets']['a'] ); echo "Owned ticket A terminal void recorded. Accepted history is retained.\n"; }
		elseif ( 'inspect' === $mode ) {
			$report = [];
			foreach ( $fixture->halls as $label => $hall ) {
				$report[ $label ] = $fixture->inHall( $hall['site'], function () use ( $hall ): array {
					global $wpdb;
					$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT request_id,state,player_tablet_id,session_id,accepted_entitlement_id,redemption_id,contributions_json FROM %i ORDER BY request_id', \BingoCommerce\DigitalTickets\Migrations::redemptions_table() ), ARRAY_A );
					if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Fixture inspection failed.' ); }
					$totals = []; $accepted = 0;
					foreach ( $rows as &$row ) {
						$row['tablet_bytes_hex'] = bin2hex( $row['player_tablet_id'] ); unset( $row['player_tablet_id'] );
						if ( 'accepted' !== $row['state'] ) { continue; }
						++$accepted; $key = $row['session_id'] . ':' . $row['tablet_bytes_hex'];
						foreach ( json_decode( $row['contributions_json'], true, 512, JSON_THROW_ON_ERROR ) as $game => $faces ) { $totals[ $key ][ $game ] = ( $totals[ $key ][ $game ] ?? 0 ) + $faces; }
					}
					unset( $row );
					return [ 'site' => $hall['site'], 'portal_hall_id' => $hall['portal_hall_id'], 'accepted_count' => $accepted, 'derived_totals' => $totals, 'rows' => $rows ];
				} );
			}
			echo json_encode( $report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) . "\n";
		} else {
			if ( file_exists( $dir . '/portal.json' ) ) { throw new RuntimeException( 'Run the Portal fixture cleanup first, then Commerce cleanup.' ); }
			$evidence = $fixture->cleanup(); QRManualState::removeDirectory( $dir );
			$evidence['private_directory_removed'] = ! file_exists( $dir ); echo json_encode( $evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) . "\n";
		}
	}
} catch ( Throwable $error ) {
	// Raw exceptions may contain private order credentials. Leave the owned
	// checkpoint for explicit cleanup; never print those exception details.
	fwrite( STDERR, 'Manual fixture command failed safely. ' . ( is_string( $dir ) ? 'Owned checkpoint: ' . $dir : 'No retained fixture directory.' ) . "\n" );
	exit( 1 );
}
