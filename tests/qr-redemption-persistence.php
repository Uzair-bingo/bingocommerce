<?php
/** Real fixture SQL and independent PHP processes; no auth claim (CGI suite covers auth). */
declare(strict_types=1);
if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) { fwrite( STDERR, "FIXTURE GUARD: isolated recon database required.\n" ); exit( 1 ); }
require __DIR__ . '/fixtures/qr-redemption.php';

use BingoCommerce\DigitalTickets\EntitlementVoiding;
use BingoCommerce\DigitalTickets\LifecycleFailure;
use BingoCommerce\DigitalTickets\LifecycleTransport;
use BingoCommerce\DigitalTickets\Migrations as TM;
use BingoCommerce\DigitalTickets\RedemptionService;

final class QRPersistenceTest {
	private QRRedemptionFixture $fixture;
	private array $children = [];
	private array $a;
	private array $b;
	private int $game;
	private string $barrier;
	private int $assertions = 0;
	private array $metrics = [];

	public function run(): void {
		$this->fixture = new QRRedemptionFixture();
		$this->barrier = sys_get_temp_dir() . '/bc-qr-' . $this->fixture->owner;
		if ( ! mkdir( $this->barrier, 0700 ) ) { throw new RuntimeException( 'Fixture barrier creation failed.' ); }
		try {
			$this->fixture->prepare( 30 ); $this->a = $this->fixture->halls['a']; $this->b = $this->fixture->halls['b'];
			$this->game = array_key_first( $this->a['games'] );
			if ( in_array( '--storage-boundaries-only', $_SERVER['argv'] ?? [], true ) ) { $this->storageBoundaries(); }
			elseif ( in_array( '--same-process-contention-only', $_SERVER['argv'] ?? [], true ) ) { $this->sameProcessContention(); }
			else { $this->basic(); $this->concurrent(); $this->sameProcessContention(); $this->failures(); $this->markerReadFailure(); $this->voiding(); $this->nativeInterleavings(); $this->refundPaymentRace(); $this->storageBoundaries(); $this->performance(); }
			echo 'METRICS ' . json_encode( $this->metrics, JSON_THROW_ON_ERROR ) . "\n";
			echo "PASS {$this->assertions} real persistence/concurrency assertions. Authentication tested separately by CGI.\n";
		} finally {
			foreach ( $this->children as $child ) {
				if ( is_resource( $child['process'] ) ) { proc_terminate( $child['process'], 9 ); proc_close( $child['process'] ); }
			}
			foreach ( glob( $this->barrier . '/*' ) ?: [] as $file ) { unlink( $file ); }
			rmdir( $this->barrier );
			echo 'CLEANUP ' . json_encode( $this->fixture->cleanup(), JSON_THROW_ON_ERROR ) . "\n";
		}
	}

	private function expect( bool $ok, string $label ): void {
		if ( ! $ok ) { throw new RuntimeException( $label ); }
		++$this->assertions; echo "PASS $label\n";
	}
	private function ticket( int $faces = 12, string $kind = 'digital', bool $timing = false ): array { return $this->fixture->createTicket( $this->a, [ $this->game => $faces ], $kind, $timing ); }
	private function command( array $ticket, string $tablet ): array {
		return [ 'site_id' => $ticket['site'], 'portal_hall_id' => $ticket['portal_hall_id'], 'caller_id' => 'fixture-relay-A',
			'player_tablet_id' => $tablet, 'qr' => $ticket['qr'], 'request_id' => wp_generate_uuid4() ];
	}
	private function redeem( array $command ): mixed { return $this->fixture->inHall( $command['site_id'], static fn() => RedemptionService::redeem( $command ) ); }
	private function error( mixed $result ): string { return $result instanceof WP_Error ? $result->get_error_code() : ''; }
	private function rows( ?string $tablet = null ): array {
		return $this->fixture->inHall( $this->a['site'], static function () use ( $tablet ): array {
			global $wpdb;
			$sql = null === $tablet ? $wpdb->prepare( 'SELECT * FROM %i ORDER BY request_id', TM::redemptions_table() ) : $wpdb->prepare( 'SELECT * FROM %i WHERE player_tablet_id=%s ORDER BY request_id', TM::redemptions_table(), $tablet );
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Fixture ledger read failed.' ); }
			return $rows;
		} );
	}
	private function accepted( string $tablet ): array { return array_values( array_filter( $this->rows( $tablet ), static fn( $row ) => 'accepted' === $row['state'] ) ); }
	private function totals( string $tablet ): array {
		$totals = [];
		foreach ( $this->accepted( $tablet ) as $row ) { foreach ( json_decode( $row['contributions_json'], true, 512, JSON_THROW_ON_ERROR ) as $game => $faces ) { $totals[ $game ] = ( $totals[ $game ] ?? 0 ) + $faces; } }
		ksort( $totals ); return $totals;
	}
	private function start( array $command, string $mode = 'redeem', bool $barrier = false, array $extra = [] ): int {
		$job = [ 'owner' => $this->fixture->owner, 'command' => $command, 'mode' => $mode ] + $extra;
		$index = count( $this->children );
		if ( $barrier || in_array( $mode, [ 'hold', 'row_hold', 'metadata_hold' ], true ) ) { $job += [ 'barrier' => $this->barrier, 'index' => $index ]; }
		$process = proc_open( [ PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/qr-redemption-persistence-worker.php' ],
			[ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, dirname( __DIR__ ), [ 'PATH' => getenv( 'PATH' ), 'TMPDIR' => sys_get_temp_dir(), 'BC_TEST_DB' => 'bingocommerce_state_recon' ] );
		if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Fixture process failed to start.' ); }
		fwrite( $pipes[0], json_encode( $job, JSON_THROW_ON_ERROR ) ); fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false ); stream_set_blocking( $pipes[2], false );
		$this->children[] = [ 'process' => $process, 'pipes' => $pipes, 'qr' => $command['qr'] ]; return $index;
	}
	private function collect( int $index, bool $empty = false ): array {
		$child = $this->children[ $index ]; $out = ''; $err = ''; $deadline = microtime( true ) + 40;
		do {
			$out .= stream_get_contents( $child['pipes'][1] ); $err .= stream_get_contents( $child['pipes'][2] ); $state = proc_get_status( $child['process'] );
			if ( ! $state['running'] ) { break; } usleep( 5000 );
		} while ( microtime( true ) < $deadline );
		if ( $state['running'] ) { proc_terminate( $child['process'], 9 ); }
		$out .= stream_get_contents( $child['pipes'][1] ); $err .= stream_get_contents( $child['pipes'][2] );
		fclose( $child['pipes'][1] ); fclose( $child['pipes'][2] ); $exit = proc_close( $child['process'] ); $this->children[ $index ]['process'] = null;
		if ( $state['running'] || 0 !== ( $state['exitcode'] >= 0 ? $state['exitcode'] : $exit ) ) {
			$err = preg_replace( '/BC1\.[0-9]+\.[a-f0-9]{64}|[a-f0-9]{64}|wc_order_[A-Za-z0-9]+/', '[private]', $err );
			throw new RuntimeException( 'Fixture worker failed: ' . substr( $err, 0, 500 ) );
		}
		if ( $empty ) { $this->expect( '' === trim( $out ), 'accepted response deliberately lost before delivery' ); return []; }
		return json_decode( trim( $out ), true, 512, JSON_THROW_ON_ERROR );
	}
	private function waitFile( string $name ): void {
		$deadline = microtime( true ) + 15;
		while ( ! is_file( $this->barrier . '/' . $name ) && microtime( true ) < $deadline ) { usleep( 5000 ); clearstatcache(); }
		if ( ! is_file( $this->barrier . '/' . $name ) ) {
			$detail = '';
			foreach ( $this->children as $child ) { if ( is_resource( $child['process'] ) ) { $detail .= stream_get_contents( $child['pipes'][2] ); } }
			$detail = preg_replace( '/BC1\.[0-9]+\.[a-f0-9]{64}|[a-f0-9]{64}/', '[private]', $detail );
			throw new RuntimeException( 'Fixture process did not reach barrier: ' . substr( $detail, 0, 500 ) );
		}
	}
	private function pair( array $first, array $second ): array {
		@unlink( $this->barrier . '/go' );
		$i = $this->start( $first, 'redeem', true ); $j = $this->start( $second, 'redeem', true );
		$this->waitFile( 'ready-' . $i ); $this->waitFile( 'ready-' . $j ); file_put_contents( $this->barrier . '/go', 'go' );
		return [ $this->collect( $i ), $this->collect( $j ) ];
	}

	private function basic(): void {
		$ticket = $this->ticket(); $command = $this->command( $ticket, 'stable-tablet' );
		$identical_rows = $this->fixture->inHall( $this->a['site'], static function () use ( $ticket ): bool {
			global $wpdb;
			$ordinary = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE entitlement_id=%d', TM::table(), $ticket['entitlement_id'] ), ARRAY_A );
			$guard = \BingoCommerce\DigitalTickets\LifecycleTransport::capture( true );
			try { $guard->acquire(); return $ordinary === $guard->ticket( substr( $ticket['qr'], strrpos( $ticket['qr'], '.' ) + 1 ) ); }
			finally { $guard->close(); }
		} );
		$this->expect( $identical_rows, 'native lifecycle row types exactly match WordPress string-valued reads' );
		$first = $this->redeem( $command );
		$this->expect( is_array( $first ) && false === $first['recovered'], 'first real acceptance succeeds on native SQL rows' );
		$this->expect( null === $first['session']['start_time_local'] && 12 === $first['digital_faces_by_game_type'][0]['faces'], 'legitimate null timing and frozen digital faces returned' );
		$recovery = $this->redeem( array_replace( $command, [ 'caller_id' => 'different-authorized-relay' ] ) );
		$this->expect( is_array( $recovery ) && true === $recovery['recovered'] && $first['redemption_id'] === $recovery['redemption_id'], 'same tablet original request recovers through another Caller' );
		$this->expect( 'already_redeemed' === $this->error( $this->redeem( array_replace( $command, [ 'request_id' => wp_generate_uuid4() ] ) ) ), 'fresh scan refuses even on the original tablet' );
		$this->expect( 'request_id_conflict' === $this->error( $this->redeem( array_replace( $command, [ 'player_tablet_id' => 'different-tablet' ] ) ) ), 'conflicting intent reveals no saved response' );
		$this->expect( [ $this->game => 12 ] === $this->totals( 'stable-tablet' ), 'recovery and failures add zero allowance' );
		$timed = $this->ticket( 14, 'hybrid', true );
		$this->fixture->inHall( $this->a['site'], static function () use ( $timed ): void { $order = wc_get_order( $timed['order_id'] ); $order->set_status( 'processing' ); $order->save(); } );
		$result = $this->redeem( $this->command( $timed, 'timed-hybrid' ) );
		$this->expect( is_array( $result ) && '18:00:00' === $result['session']['start_time_local'], 'late paid Processing guest Hybrid succeeds with recorded timing' );
		foreach ( [ 'identity', 'identity ', 'Identity' ] as $tablet ) { $this->expect( is_array( $this->redeem( $this->command( $this->ticket( 20 ), $tablet ) ) ), 'opaque tablet bytes have independent allowance: ' . strlen( $tablet ) ); }
		$wrong = $this->command( $this->ticket(), 'wrong-session' ); $wrong['operating_commerce_session_id'] = $this->a['timed_session'];
		$this->expect( 'wrong_session' === $this->error( $this->redeem( $wrong ) ), 'supplied exact operating occurrence is checked without template/date guessing' );
	}

	private function concurrent(): void {
		$cmd = $this->command( $this->ticket(), 'same-request' ); $results = $this->pair( $cmd, $cmd );
		$this->expect( 'success' === $results[0]['kind'] && 'success' === $results[1]['kind']
			&& $results[0]['result']['redemption_id'] === $results[1]['result']['redemption_id']
			&& $results[0]['result']['recovered'] !== $results[1]['result']['recovered'], 'simultaneous original requests accept once and recover one saved result' );
		$this->expect( 1 === count( $this->accepted( 'same-request' ) ), 'database contains exactly one accepted original request' );
		$cmd = $this->command( $this->ticket(), 'scan-one' ); $other = array_replace( $cmd, [ 'request_id' => wp_generate_uuid4(), 'player_tablet_id' => 'scan-two' ] );
		$results = $this->pair( $cmd, $other );
		$this->expect( 1 === count( array_filter( $results, static fn( $r ) => 'success' === $r['kind'] ) )
			&& 1 === count( array_filter( $results, static fn( $r ) => 'already_redeemed' === $r['result'] ) ), 'two simultaneous fresh scans on different tablets have one winner' );
		$this->expect( is_array( $this->redeem( $this->command( $this->ticket( 20 ), 'near-limit' ) ) ), 'near-limit baseline accepted' );
		$special = array_keys( $this->a['games'] )[1];
		$left = $this->fixture->createTicket( $this->a, [ $this->game => 8, $special => 7 ] );
		$right = $this->fixture->createTicket( $this->a, [ $this->game => 8, $special => 11 ] );
		$results = $this->pair( $this->command( $left, 'near-limit' ), $this->command( $right, 'near-limit' ) );
		$totals = $this->totals( 'near-limit' );
		$this->expect( 1 === count( array_filter( $results, static fn( $r ) => 'success' === $r['kind'] ) )
			&& 1 === count( array_filter( $results, static fn( $r ) => 'electronic_limit_exceeded' === $r['result'] ) )
			&& 28 === $totals[ $this->game ] && in_array( $totals[ $special ], [ 7, 11 ], true ) && 2 === count( $this->accepted( 'near-limit' ) ), 'concurrent multi-Game-Type tickets cannot overshoot or partially accept' );
		$cmd = $this->command( $this->ticket(), 'interrupted' );
		$this->expect( 'claimed' === $this->collect( $this->start( $cmd, 'claim_exit' ) )['kind'], 'process interrupted after durable pending registration' );
		$this->expect( [] === $this->accepted( 'interrupted' ), 'pending registration consumes no entitlement or allowance' );
		$this->expect( 'request_id_conflict' === $this->error( $this->redeem( array_replace( $cmd, [ 'player_tablet_id' => 'other' ] ) ) ), 'interrupted pending intent cannot be reused' );
		$this->expect( is_array( $this->redeem( $cmd ) ), 'original interrupted request resumes once' );
		$cmd = $this->command( $this->ticket(), 'lost-response' ); $this->collect( $this->start( $cmd, 'lost_response' ), true );
		$recovered = $this->redeem( $cmd );
		$this->expect( is_array( $recovered ) && true === $recovered['recovered'] && 1 === count( $this->accepted( 'lost-response' ) ), 'lost post-acceptance response safely recovers without reallocation' );
		$cmd = $this->command( $this->ticket(), 'lock-wait' ); @unlink( $this->barrier . '/holding' );
		$holder = $this->start( $cmd, 'hold' ); $this->waitFile( 'holding' );
		$waiting = $this->collect( $this->start( $cmd ) ); $held = $this->collect( $holder );
		// The wait ends on its own (4 s) a full second inside the 5 s SELECT bound.
		$this->expect( 'verification_unavailable' === $waiting['result'] && $waiting['elapsed_ms'] >= 3500 && $waiting['elapsed_ms'] < 8000 && [] === $this->accepted( 'lock-wait' ), 'real lifecycle lock contention fails bounded without consumption' );
		$this->metrics['contention'] = [ 'request_ms' => $waiting['elapsed_ms'], 'intentional_hold_ms' => $held['elapsed_ms'] ];
	}

	/**
	 * Ordinary Hall-mutex contention must end in a proved "not acquired", never a
	 * SELECT cancelled by its own execution bound: THIS process must stay usable.
	 * A real separate connection holds the mutex. Static guards are never reset.
	 */
	private function sameProcessContention(): void {
		$this->fixture->inHall( $this->a['site'], function (): void {
			global $wpdb;
			// LifecycleTransport's Hall mutex identity. A mismatch would leave the attempt
			// uncontended, which the retryable-503 assertion below rejects.
			$name = 'bc:dt:' . substr( hash( 'sha256', json_encode( [ DB_NAME, (string) $wpdb->base_prefix, (int) get_current_network_id(), (int) get_current_blog_id() ], JSON_THROW_ON_ERROR ) ), 0, 56 );
			$bounds = 'SELECT @@SESSION.innodb_lock_wait_timeout AS row_wait,@@SESSION.lock_wait_timeout AS metadata_wait,@@SESSION.max_execution_time AS select_ms';
			$original = $wpdb->get_row( $bounds, ARRAY_A );
			$holder_lock = static function ( mysqli $holder, string $sql ) use ( $name ): ?string {
				$statement = $holder->prepare( $sql );
				if ( false === $statement || ! $statement->bind_param( 's', $name ) || ! $statement->execute() ) { throw new RuntimeException( 'Fixture competitor lock statement failed.' ); }
				$value = $statement->get_result()->fetch_row()[0]; $statement->close();
				return null === $value ? null : (string) $value;
			};
			QRRedemptionFixture::assertDatabase();
			$holder = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
			try {
				if ( $holder->connect_errno || DB_NAME !== $holder->query( 'SELECT DATABASE()' )->fetch_row()[0] ) { throw new RuntimeException( 'Fixture competitor connection refused.' ); }
				$holder_id = (string) $holder->query( 'SELECT CONNECTION_ID()' )->fetch_row()[0];
				// A real slow preparation callback consumes the 25 s budget: 16 s leaves a
				// SELECT bound below 5 s; 21.5 s leaves too little time to wait at all.
				foreach ( [ 'reduced SELECT budget' => [ 16.0, 2000, 4000 ], 'insufficient remaining budget' => [ 21.5, 1000, 1000 ], 'normal contention' => [ 0.0, 5000, 5000 ] ] as $case => [ $delay, $min_bound, $max_bound ] ) {
					$ticket = $this->ticket(); $void = $this->ticket(); $tablet = 'same-process-' . $min_bound; $command = $this->command( $ticket, $tablet );
					$this->expect( '1' === $holder_lock( $holder, 'SELECT GET_LOCK(?,0)' ), 'separate connection holds the Hall mutex: ' . $case );
					$state = [ 'slept' => false, 'nested' => false, 'select_ms' => null, 'observed_at' => null ];
					$observe = static function ( string $sql ) use ( &$state, $delay ): string {
						if ( $state['nested'] ) { return $sql; }
						if ( $delay > 0 && ! $state['slept'] && str_contains( $sql, 'SHOW FULL COLUMNS' ) ) {
							$state['slept'] = true; sleep( (int) $delay ); usleep( (int) round( ( $delay - floor( $delay ) ) * 1000000 ) );
						} elseif ( null === $state['select_ms'] && str_contains( $sql, 'bingo_game_types' ) ) {
							// The last callback before acquire(): record the SELECT bound in force.
							global $wpdb; $state['nested'] = true;
							try { $state['select_ms'] = (int) $wpdb->get_var( 'SELECT @@SESSION.max_execution_time' ); } finally { $state['nested'] = false; }
							$state['observed_at'] = hrtime( true );
						}
						return $sql;
					};
					add_filter( 'query', $observe, PHP_INT_MIN );
					try { $result = $this->redeem( $command ); }
					finally { remove_filter( 'query', $observe, PHP_INT_MIN ); }
					$waited = null === $state['observed_at'] ? null : ( hrtime( true ) - $state['observed_at'] ) / 1e6;
					$this->expect( is_int( $state['select_ms'] ) && $state['select_ms'] >= $min_bound && $state['select_ms'] <= $max_bound, 'active SELECT bound ' . var_export( $state['select_ms'], true ) . ' ms matches: ' . $case );
					$this->expect( $result instanceof WP_Error && 'verification_unavailable' === $result->get_error_code() && 503 === $result->get_error_data()['status'] && true === $result->get_error_data()['retryable'], 'contended attempt is a retryable 503: ' . $case );
					$this->expect( [] === $this->rows( $tablet ) && [] === $this->totals( $tablet ), 'contended attempt writes no journal, acceptance or allowance: ' . $case );
					$this->expect( $original === $wpdb->get_row( $bounds, ARRAY_A ) && $holder_id === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $name ) ), 'session bounds restored; only the competitor holds the Hall mutex: ' . $case );
					$this->expect( '1' === $holder_lock( $holder, 'SELECT RELEASE_LOCK(?)' ) && null === $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $name ) ), 'competing Hall mutex released: ' . $case );
					$later = $this->redeem( $command );
					$this->expect( is_array( $later ) && false === $later['recovered'] && 1 === count( $this->accepted( $tablet ) ) && [ $this->game => 12 ] === $this->totals( $tablet ), 'same process then accepts the original request once: ' . $case );
					wc_get_order( $void['order_id'] )->update_status( 'cancelled' );
					$marker = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', TM::voids_table(), $void['order_id'] ), ARRAY_A );
					$status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE order_id=%d', TM::table(), $void['order_id'] ) );
					$this->expect( 'cancellation' === ( $marker['cause'] ?? null ) && 'void' === $status && true === EntitlementVoiding::enforce_block( $void['order_id'], $void['facts'] ), 'same process still records a durable void and converges it: ' . $case );
					$this->expect( $original === $wpdb->get_row( $bounds, ARRAY_A ) && null === $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $name ) ) && LifecycleTransport::diagnostics_safe(), 'no Hall mutex, changed session bound or unproven lock state remains: ' . $case );
					// Contended waits must have waited for the competitor; every attempt must end
					// on its own, well before the SELECT bound could cancel it.
					$this->expect( null !== $waited && $waited < $state['select_ms'] - 250 && ( 1000 === $state['select_ms'] || $waited >= 750 ), sprintf( 'lock attempt ended after %.0f ms, inside the %d ms SELECT bound: %s', (float) $waited, (int) $state['select_ms'], $case ) );
					$this->metrics['same_process_contention'][ $case ] = [ 'select_bound_ms' => $state['select_ms'], 'lock_attempt_ms' => round( (float) $waited, 1 ) ];
				}
			} finally {
				if ( 0 === $holder->connect_errno ) { $holder->close(); }
			}
		} );
	}

	private function failures(): void {
		foreach ( [ 'change_context', 'change_context_restore', 'replace_connection', 'outer_transaction', 'autocommit_off', 'lifecycle_reentry', 'malformed_reentry', 'mismatched_void_reentry' ] as $mode ) {
			$ticket = $this->ticket(); $cmd = $this->command( $ticket, $mode ); $result = $this->collect( $this->start( $cmd, $mode, false, [ 'other_site' => $this->b['site'], 'order_id' => $ticket['order_id'], 'facts' => $ticket['facts'] ] ) );
			$this->expect( true === $result['fault_injected'] && 'error' === $result['kind'] && 'verification_unavailable' === $result['result'] && [] === $this->rows( $mode ), 'callback fault ' . $mode . ' creates no journal or acceptance row' );
		}
		$this->fixture->inHall( $this->a['site'], function (): void {
			global $wpdb;
			$ticket = $this->ticket(); $cmd = $this->command( $ticket, 'invalid-saved-facts' );
			$order = wc_get_order( $ticket['order_id'] );
			$order->update_meta_data( 'bc_purchase', array_replace( $ticket['facts'], [ 'package_name' => '' ] ) ); $order->save();
			try {
				$result = $this->redeem( $cmd );
				$this->expect( 'saved_purchase_invalid' === $this->error( $result ) && 422 === $result->get_error_data()['status'] && [] === $this->accepted( 'invalid-saved-facts' ), 'invalid saved response details return 422 without acceptance' );
			} finally { $order->update_meta_data( 'bc_purchase', $ticket['facts'] ); $order->save(); }
			foreach ( [ 'ticket' => TM::tickets_table(), 'entitlement' => TM::table() ] as $kind => $unavailable_table ) {
				$cmd = $this->command( $this->ticket(), 'missing-' . $kind . '-table' ); $backup = $unavailable_table . '_fixture_unavailable';
				$this->sql( $wpdb->prepare( 'RENAME TABLE %i TO %i', $unavailable_table, $backup ) );
				try {
					$result = $this->redeem( $cmd );
					$this->expect( 'verification_unavailable' === $this->error( $result ) && 503 === $result->get_error_data()['status'] && [] === $this->rows( 'missing-' . $kind . '-table' ), 'unavailable ' . $kind . ' schema returns 503 rather than missing-ticket proof and creates no journal' );
				} finally { $this->sql( $wpdb->prepare( 'RENAME TABLE %i TO %i', $backup, $unavailable_table ) ); }
			}
			$cmd = $this->command( $this->ticket(), 'failed-accept' ); $table = TM::redemptions_table();
			$trigger = 'qr_accept_' . str_replace( '-', '', $this->fixture->owner );
			$this->sql( $wpdb->prepare( "CREATE TRIGGER %i BEFORE UPDATE ON %i FOR EACH ROW BEGIN IF NEW.state='accepted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture acceptance failure'; END IF; END", $trigger, $table ) );
			try { $result = $this->redeem( $cmd ); $this->expect( $result instanceof WP_Error && [] === $this->accepted( 'failed-accept' ), 'database acceptance failure never returns false success or partial acceptance' ); }
			finally { $this->sql( $wpdb->prepare( 'DROP TRIGGER %i', $trigger ) ); }
			$this->expect( is_array( $this->redeem( $cmd ) ) && 1 === count( $this->accepted( 'failed-accept' ) ), 'same failed-write request safely resumes after storage recovery' );
			$cmd = $this->command( $this->ticket(), 'missing-map' ); $game_table = \BingoCommerce\Catalog\Migrations::game_types_table(); $portal = $this->a['games'][ $this->game ];
			$this->sql( $wpdb->prepare( 'UPDATE %i SET portal_game_type_id=NULL WHERE game_type_id=%d', $game_table, $this->game ) );
			try { $this->expect( 'mapping_unavailable' === $this->error( $this->redeem( $cmd ) ) && [] === $this->accepted( 'missing-map' ), 'missing Game Type mapping refuses before consumption' ); }
			finally { $this->sql( $wpdb->prepare( 'UPDATE %i SET portal_game_type_id=%d WHERE game_type_id=%d', $game_table, $portal, $this->game ) ); }
			$limit = get_site_option( \BingoCommerce\Catalog\ElectronicLimit::PLATFORM_OPTION ); delete_site_option( \BingoCommerce\Catalog\ElectronicLimit::PLATFORM_OPTION );
			try { $this->expect( 'verification_unavailable' === $this->error( $this->redeem( $cmd ) ) && [] === $this->accepted( 'missing-map' ), 'missing effective limit never uses a hardcoded fallback' ); }
			finally { update_site_option( \BingoCommerce\Catalog\ElectronicLimit::PLATFORM_OPTION, $limit ); }
			$ticket = $this->ticket(); $cmd = $this->command( $ticket, 'saved-response-recovery' ); $accepted = $this->redeem( $cmd );
			$this->expect( is_array( $accepted ), 'saved-response recovery fixture accepted before later corruption' );
			$order = wc_get_order( $ticket['order_id'] );
			$order->update_meta_data( 'bc_purchase', array_replace( $ticket['facts'], [ 'session_name' => 'Changed after acceptance', 'package_name' => '' ] ) ); $order->save();
			$this->sql( $wpdb->prepare( 'UPDATE %i SET portal_game_type_id=NULL WHERE game_type_id=%d', $game_table, $this->game ) );
			delete_site_option( \BingoCommerce\Catalog\ElectronicLimit::PLATFORM_OPTION );
			try {
				$result = $this->redeem( $cmd ); $expected = array_replace( $accepted, [ 'recovered' => true ] );
				$this->expect( $expected === $result && [ $this->game => 12 ] === $this->totals( 'saved-response-recovery' ) && 1 === count( $this->accepted( 'saved-response-recovery' ) ), 'accepted recovery returns its saved payload despite later invalid purchase details, mappings and limit configuration' );
			} finally {
				$order->update_meta_data( 'bc_purchase', $ticket['facts'] ); $order->save();
				$this->sql( $wpdb->prepare( 'UPDATE %i SET portal_game_type_id=%d WHERE game_type_id=%d', $game_table, $portal, $this->game ) );
				update_site_option( \BingoCommerce\Catalog\ElectronicLimit::PLATFORM_OPTION, $limit );
			}
		} );
	}

	/** Fail the native marker SELECT after both WP and native schema checks passed. */
	private function markerReadFailure(): void {
		$this->fixture->inHall( $this->a['site'], function (): void {
			global $wpdb;
			$ticket = $this->ticket(); $command = $this->command( $ticket, 'native-marker-read-failure' );
			$table = TM::voids_table(); $backup = $table . '_fixture_read_failure'; $renamed = false;
			$before = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', TM::table(), $ticket['order_id'] ), ARRAY_A );
			// effective_limit() is the fixed callback phase AFTER acquire() has
			// completed native schema verification. Do not intercept/replace its
			// query: rename only this owned table, then let the real native read fail.
			$inject = function ( string $sql ) use ( &$renamed, $table, $backup ): string {
				if ( ! $renamed && str_starts_with( $sql, 'SELECT meta_value FROM ' )
					&& str_contains( $sql, "meta_key = 'bc_platform_electronic_maximum'" ) ) {
					global $wpdb;
					$this->sql( $wpdb->prepare( 'RENAME TABLE %i TO %i', $table, $backup ) ); $renamed = true;
				}
				return $sql;
			};
			add_filter( 'query', $inject, PHP_INT_MIN );
			try { $result = $this->redeem( $command ); }
			finally {
				remove_filter( 'query', $inject, PHP_INT_MIN );
				if ( $renamed ) { $this->sql( $wpdb->prepare( 'RENAME TABLE %i TO %i', $backup, $table ) ); }
			}
			$this->expect( $renamed, 'marker read fault injected in post-acquisition limit phase after native schema verification' );
			$this->expect( 'verification_unavailable' === $this->error( $result ) && 503 === $result->get_error_data()['status']
				&& true === $result->get_error_data()['retryable'], 'individual native marker read failure returns retryable 503, not absent marker or invalid ticket' );
			$this->expect( [] === $this->rows( 'native-marker-read-failure' ) && [] === $this->totals( 'native-marker-read-failure' ), 'failed marker read writes no request, acceptance or allowance' );
			$this->expect( $before === $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', TM::table(), $ticket['order_id'] ), ARRAY_A )
				&& $ticket['qr'] === \BingoCommerce\DigitalTickets\DigitalTicket::reference_for_order( $ticket['order_id'] ), 'individual read failure leaves entitlement and original ticket unchanged' );

			// Also exercise the native convergence primitive used by enforce_block:
			// successful acquire proves schema; only its subsequent marker read fails.
			$guard = LifecycleTransport::capture( false ); $renamed = false; $failure = null;
			try {
				$guard->acquire();
				$this->sql( $wpdb->prepare( 'RENAME TABLE %i TO %i', $table, $backup ) ); $renamed = true;
				try { $guard->converge( $ticket['order_id'], $ticket['facts'] ); }
				catch ( LifecycleFailure $error ) { $failure = $error->getMessage(); }
			} finally {
				if ( $renamed ) { $this->sql( $wpdb->prepare( 'RENAME TABLE %i TO %i', $backup, $table ) ); }
				$guard->close();
			}
			$this->expect( 'verification_unavailable' === $failure, 'native void convergence throws on individual marker read failure instead of returning no marker' );
			$result = $this->redeem( $command );
			$this->expect( is_array( $result ) && false === $result['recovered'] && 1 === count( $this->accepted( 'native-marker-read-failure' ) ), 'original request accepts once after native marker read recovery' );
		} );
	}

	private function voiding(): void {
		$void = $this->ticket(); $cmd = $this->command( $void, 'void-first' ); $this->collect( $this->start( $cmd, 'void', false, [ 'order_id' => $void['order_id'] ] ) );
		$this->expect( 'ticket_voided' === $this->error( $this->redeem( $cmd ) ), 'separate-process void-first prevents acceptance' );
		$void = $this->ticket(); $cmd = $this->command( $void, 'accept-first' ); $this->expect( is_array( $this->redeem( $cmd ) ), 'accept-first receipt recorded' );
		$this->collect( $this->start( $cmd, 'void', false, [ 'order_id' => $void['order_id'] ] ) );
		$this->expect( 'ticket_voided' === $this->error( $this->redeem( $cmd ) ) && [ $this->game => 12 ] === $this->totals( 'accept-first' ), 'later void blocks usable recovery while retaining accepted contribution' );
		$race_winners = [ 'redemption' => 0, 'void' => 0 ];
		for ( $race = 0; $race < 3; ++$race ) {
			$void = $this->ticket(); $tablet = 'simultaneous-void-' . $race; $cmd = $this->command( $void, $tablet );
			@unlink( $this->barrier . '/go' );
			$redemption = $this->start( $cmd, 'redeem', true ); $cancellation = $this->start( $cmd, 'void', true, [ 'order_id' => $void['order_id'] ] );
			$this->waitFile( 'ready-' . $redemption ); $this->waitFile( 'ready-' . $cancellation ); file_put_contents( $this->barrier . '/go', 'go' );
			$result = $this->collect( $redemption ); $this->collect( $cancellation );
			$accepted = $this->accepted( $tablet ); $won = 'success' === $result['kind']; ++$race_winners[ $won ? 'redemption' : 'void' ];
			$marker = $this->fixture->inHall( $this->a['site'], static function () use ( $void ): int {
				global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id=%d', TM::voids_table(), $void['order_id'] ) );
			} );
			$this->expect( 1 === $marker && ( $won || 'ticket_voided' === $result['result'] )
				&& ( $won ? 1 : 0 ) === count( $accepted ) && count( $accepted ) === count( $this->rows( $tablet ) )
				&& ( $won ? [ $this->game => 12 ] : [] ) === $this->totals( $tablet ), 'simultaneous redemption and cancellation serialize without partial acceptance: ' . $race );
			$this->expect( 'ticket_voided' === $this->error( $this->redeem( $cmd ) ), 'simultaneous cancellation leaves no usable original recovery: ' . $race );
		}
		$this->metrics['redemption_void_race_winners'] = $race_winners;
		foreach ( [ 'marker', 'status' ] as $failure ) {
			$void = $this->ticket(); $cmd = $this->command( $void, 'failed-' . $failure );
			$this->fixture->inHall( $this->a['site'], function () use ( $void, $cmd, $failure ): void {
				global $wpdb;
				$table = 'marker' === $failure ? TM::voids_table() : TM::table(); $event = 'marker' === $failure ? 'INSERT' : 'UPDATE';
				$trigger = 'qr_' . $failure . '_' . str_replace( '-', '', $this->fixture->owner );
				$this->sql( $wpdb->prepare( "CREATE TRIGGER %i BEFORE $event ON %i FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture void failure'", $trigger, $table ) );
				try {
					$this->collect( $this->start( $cmd, 'void', false, [ 'order_id' => $void['order_id'] ] ) );
					$marker = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id=%d', TM::voids_table(), $void['order_id'] ) );
					$status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE order_id=%d', TM::table(), $void['order_id'] ) );
					$this->expect( ( 'marker' === $failure ? 0 : 1 ) === $marker && 'active' === $status, 'real failed ' . $failure . ' write retains truthful durable state' );
					if ( 'status' === $failure ) { $this->expect( 'ticket_voided' === $this->error( $this->redeem( $cmd ) ), 'durable marker blocks redemption despite status-update failure' ); }
				} finally { $this->sql( $wpdb->prepare( 'DROP TRIGGER %i', $trigger ) ); }
				// A new request reads the other process's cancellation; the parent
				// still owns an intentionally stale Woo object cache from creation.
				$this->collect( $this->start( $cmd, 'void_redelivery', false, [ 'order_id' => $void['order_id'] ] ) );
				$this->expect( true === EntitlementVoiding::enforce_block( $void['order_id'], $void['facts'] ), 'void convergence retries safely after database recovery' );
			} );
		}
	}

	private function sql( string $sql ): void {
		QRRedemptionFixture::assertDatabase(); global $wpdb;
		if ( false === $wpdb->query( $sql ) || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Owned fixture SQL failed.' ); }
	}

	/** Server-owned trigger barriers, not WordPress query-filter simulations. */
	private function nativeInterleavings(): void {
		$this->fixture->inHall( $this->a['site'], function (): void {
			global $wpdb;
			$ticket = $this->ticket(); $command = $this->command( $ticket, 'lost-native-writer' );
			$signal = 'qr:accept:' . $this->fixture->owner; $trigger = 'qr_kill_' . str_replace( '-', '', $this->fixture->owner );
			$this->sql( $wpdb->prepare( "CREATE TRIGGER %i AFTER UPDATE ON %i FOR EACH ROW BEGIN IF NEW.state='accepted' THEN DO GET_LOCK(%s,0); DO SLEEP(4); END IF; END", $trigger, TM::redemptions_table(), $signal ) );
			try {
				$child = $this->start( $command ); $writer = $this->waitSignal( $signal );
				$this->sql( $wpdb->prepare( 'KILL CONNECTION %d', $writer ) );
				$result = $this->collect( $child );
				$this->expect( 'error' === $result['kind'] && 'write_outcome_unknown' === $result['result'], 'real writer connection loss during acceptance is reported as uncertain' );
				$this->expect( [] === $this->accepted( 'lost-native-writer' ), 'interrupted native statement did not leave partial acceptance' );
			} finally { $this->sql( $wpdb->prepare( 'DROP TRIGGER %i', $trigger ) ); }
			$this->expect( is_array( $this->redeem( $command ) ) && 1 === count( $this->accepted( 'lost-native-writer' ) ), 'original request resolves uncertain writer failure once' );

			$ticket = $this->ticket(); $command = $this->command( $ticket, 'void-payment-race' );
			// Recreate the owned order's pre-issuance stage. No gateway is called.
			$order = wc_get_order( $ticket['order_id'] ); $order->set_status( 'pending' ); $order->set_date_paid( null ); $order->save();
			$this->sql( $wpdb->prepare( 'DELETE FROM %i WHERE entitlement_id=%d', TM::tickets_table(), $ticket['entitlement_id'] ) );
			$this->sql( $wpdb->prepare( 'DELETE FROM %i WHERE entitlement_id=%d', TM::table(), $ticket['entitlement_id'] ) );
			$signal = 'qr:marker:' . $this->fixture->owner; $trigger = 'qr_marker_race_' . str_replace( '-', '', $this->fixture->owner );
			$this->sql( $wpdb->prepare( 'CREATE TRIGGER %i BEFORE INSERT ON %i FOR EACH ROW BEGIN IF NEW.order_id=%d THEN DO GET_LOCK(%s,0); DO SLEEP(3); END IF; END', $trigger, TM::voids_table(), $ticket['order_id'], $signal ) );
			try {
				$void = $this->start( $command, 'void', false, [ 'order_id' => $ticket['order_id'] ] ); $this->waitSignal( $signal );
				$payment = $this->start( $command, 'payment', false, [ 'order_id' => $ticket['order_id'] ] );
				$this->collect( $void ); $this->collect( $payment );
				$markers = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id=%d', TM::voids_table(), $ticket['order_id'] ) );
				$active = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id=%d AND status=%s', TM::table(), $ticket['order_id'], 'active' ) );
				$this->expect( 1 === $markers && 0 === $active, 'native marker insert interleaves with separate-process payment without active entitlement resurrection' );
			} finally { $this->sql( $wpdb->prepare( 'DROP TRIGGER %i', $trigger ) ); }
		} );
	}

	/** Native Package refund and payment in separate processes; no gateway call. */
	private function refundPaymentRace(): void {
		$this->fixture->inHall( $this->a['site'], function (): void {
			global $wpdb;
			$ticket = $this->ticket(); $command = $this->command( $ticket, 'refund-payment-race' );
			$order = wc_get_order( $ticket['order_id'] );
			// Keep the whole-Package refund partial at ORDER level so Woo's real
			// payment_complete() still dispatches. No status filter bypass is used.
			$item = new WC_Order_Item_Product(); $item->set_name( 'Owned independent merchandise' );
			$item->set_quantity( 1 ); $item->set_subtotal( '30.00' ); $item->set_total( '30.00' ); $order->add_item( $item );
			$order->set_total( '50.00' ); $order->set_status( 'pending' ); $order->set_date_paid( null ); $order->save();
			$this->sql( $wpdb->prepare( 'DELETE FROM %i WHERE entitlement_id=%d', TM::tickets_table(), $ticket['entitlement_id'] ) );
			$this->sql( $wpdb->prepare( 'DELETE FROM %i WHERE entitlement_id=%d', TM::table(), $ticket['entitlement_id'] ) );
			$extra = [ 'order_id' => $ticket['order_id'] ];
			$before = $this->collect( $this->start( $command, 'inspect_order', false, $extra ) );
			$signal = 'qr:refund:' . $this->fixture->owner; $gate = 'qr:release:' . $this->fixture->owner;
			$payment_signal = 'qr:payment:' . $this->fixture->owner; $trigger = 'qr_refund_race_' . str_replace( '-', '', $this->fixture->owner );
			$this->expect( '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $gate ) ), 'owned refund marker release barrier acquired' );
			$this->sql( $wpdb->prepare( 'CREATE TRIGGER %i BEFORE INSERT ON %i FOR EACH ROW BEGIN IF NEW.order_id=%d THEN DO GET_LOCK(%s,0); DO GET_LOCK(%s,8); DO RELEASE_LOCK(%s); END IF; END',
				$trigger, TM::voids_table(), $ticket['order_id'], $signal, $gate, $gate ) );
			try {
				$refund = $this->start( $command, 'package_refund', false, $extra ); $this->waitSignal( $signal );
				$payment = $this->start( $command, 'payment', false, $extra + [ 'payment_signal' => $payment_signal ] ); $this->waitSignal( $payment_signal );
				$this->expect( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $signal ) ), 'payment event reaches its observer while native Package refund marker owns the lifecycle mutex' );
				$this->sql( $wpdb->prepare( 'DO RELEASE_LOCK(%s)', $gate ) );
				$this->collect( $refund ); $paid = $this->collect( $payment );
				$this->expect( 1 === $paid['payment_events'] && true === $paid['paid'], 'racing native payment_complete really dispatches and remains paid after Package refund' );
			} finally {
				$this->sql( $wpdb->prepare( 'DO RELEASE_LOCK(%s)', $gate ) );
				$this->sql( $wpdb->prepare( 'DROP TRIGGER %i', $trigger ) );
			}
			$marker = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', TM::voids_table(), $ticket['order_id'] ), ARRAY_A );
			$after = $this->collect( $this->start( $command, 'inspect_order', false, $extra ) );
			$this->expect( 1 === count( $after['refunds'] ) && '20.00' === $after['refunds'][0]['amount']
				&& 'package_refund' === ( $marker['cause'] ?? null ) && (int) $marker['refund_id'] === $after['refunds'][0]['id'], 'native Package refund persists one refund and its matching durable terminal marker' );
			$entitlement = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', TM::table(), $ticket['order_id'] ), ARRAY_A );
			$this->expect( '' === $wpdb->last_error && null === $entitlement
				&& null === \BingoCommerce\DigitalTickets\DigitalTicket::reference_for_order( $ticket['order_id'] )
				&& null === \BingoCommerce\DigitalTickets\DigitalTicket::resolve( $ticket['qr'] ), 'refund race leaves no entitlement or usable ticket before any payment retry' );
			foreach ( [ 'purchase', 'items', 'financial' ] as $key ) { $this->expect( $before[ $key ] === $after[ $key ], 'Package refund/payment race preserves saved ' . $key ); }
			$this->expect( $ticket['facts']['digital_faces_by_game_type'] === $after['purchase']['digital_faces_by_game_type'], 'refund race preserves frozen purchased digital quantities' );
			for ( $repeat = 0; $repeat < 2; ++$repeat ) {
				$retry = $this->collect( $this->start( $command, 'payment_redelivery', false, $extra ) );
				$this->expect( 1 === $retry['payment_events'] && true === $retry['paid'], 'native payment repeat plus explicit observer redelivery executes after refund: ' . $repeat );
				$entitlement = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', TM::table(), $ticket['order_id'] ), ARRAY_A );
				$this->expect( '' === $wpdb->last_error && null === $entitlement
					&& null === \BingoCommerce\DigitalTickets\DigitalTicket::reference_for_order( $ticket['order_id'] )
					&& null === \BingoCommerce\DigitalTickets\DigitalTicket::resolve( $ticket['qr'] ), 'refund marker blocks entitlement and usable ticket through repeated payment: ' . $repeat );
				$this->expect( $marker === $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', TM::voids_table(), $ticket['order_id'] ), ARRAY_A ), 'payment redelivery preserves original durable refund marker: ' . $repeat );
				$this->expect( $after['purchase'] === $retry['purchase'] && $after['items'] === $retry['items']
					&& $after['financial'] === $retry['financial'] && $after['refunds'] === $retry['refunds'], 'payment redelivery preserves saved quantities, financial facts and existing refund: ' . $repeat );
			}
			$this->expect( [] === $this->rows( 'refund-payment-race' ), 'refund/payment race consumes no redemption or allowance' );
		} );
	}

	private function waitSignal( string $signal ): int {
		global $wpdb;
		$deadline = microtime( true ) + 15;
		do {
			$id = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $signal ) );
			if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'Native fixture barrier lookup failed.' ); }
			if ( null !== $id ) { return (int) $id; }
			usleep( 5000 );
		} while ( microtime( true ) < $deadline );
		throw new RuntimeException( 'Native fixture trigger barrier timed out.' );
	}

	private function storageBoundaries(): void {
		foreach ( [ 'row_hold', 'metadata_hold' ] as $mode ) {
			$command = $this->command( $this->ticket(), $mode );
			$this->collect( $this->start( $command, 'claim_exit' ) ); @unlink( $this->barrier . '/holding' );
			$holder = $this->start( $command, $mode ); $this->waitFile( 'holding' );
			$result = $this->collect( $this->start( $command ) ); $held = $this->collect( $holder );
			$this->expect( 'error' === $result['kind'] && in_array( $result['result'], [ 'verification_unavailable', 'write_outcome_unknown' ], true )
				&& $result['elapsed_ms'] >= 4500 && $result['elapsed_ms'] < 8000 && [] === $this->accepted( $mode ), 'real ' . $mode . ' wait is bounded and never reports acceptance' );
			$this->expect( is_array( $this->redeem( $command ) ) && 1 === count( $this->accepted( $mode ) ), 'same original request resumes after ' . $mode . ' releases' );
			$this->metrics[ $mode ] = [ 'request_ms' => $result['elapsed_ms'], 'intentional_hold_ms' => $held['elapsed_ms'] ];
		}
		$this->fixture->inHall( $this->a['site'], function (): void {
			global $wpdb;
			$command = $this->command( $this->ticket(), 'schema-refusal' ); $table = TM::redemptions_table();
			$this->sql( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX accepted_entitlement_unique', $table ) );
			try { $this->expect( 'verification_unavailable' === $this->error( $this->redeem( $command ) ) && [] === $this->accepted( 'schema-refusal' ), 'missing database uniqueness fails before consumption' ); }
			finally { $this->sql( $wpdb->prepare( 'ALTER TABLE %i ADD UNIQUE KEY accepted_entitlement_unique (accepted_entitlement_id)', $table ) ); }
			$this->sql( $wpdb->prepare( 'ALTER TABLE %i ENGINE=MyISAM', $table ) );
			try { $this->expect( 'verification_unavailable' === $this->error( $this->redeem( $command ) ) && [] === $this->accepted( 'schema-refusal' ), 'nontransactional ledger engine fails closed' ); }
			finally { $this->sql( $wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $table ) ); }
			$bounds_query = 'SELECT @@SESSION.innodb_lock_wait_timeout AS row_wait,@@SESSION.lock_wait_timeout AS metadata_wait,@@SESSION.max_execution_time AS select_ms';
			$before = $wpdb->get_row( $bounds_query, ARRAY_A );
			$this->expect( is_array( $this->redeem( $command ) ), 'schema restoration permits the original request' );
			$after = $wpdb->get_row( $bounds_query, ARRAY_A );
			$this->expect( $before === $after, 'successful native lifecycle restores all original session wait bounds' );
		} );
	}

	private function performance(): void {
		$this->fixture->inHall( $this->a['site'], function (): void {
			global $wpdb;
			$table = TM::redemptions_table(); $now = gmdate( 'Y-m-d H:i:s' ); $created = 0;
			foreach ( [ 1000, 10000 ] as $size ) {
				// Synthetic historical ledger rows measure cardinality; real ticket
				// requests below still run the full production verifier/acceptance.
				for ( ; $created < $size; ) {
					$tuples = []; $values = [];
					for ( $j = 0; $j < 250 && $created < $size; ++$j, ++$created ) {
						$tuples[] = '(%s,%s,%s,%s,%s,%d,%d,%s,%s,%s,%s,%s)';
						$request = wp_generate_uuid4(); $redemption = wp_generate_uuid4(); $tablet = 'bench-' . ( $created % 500 );
						$payload = [ 'api_version' => 1, 'request_id' => $request, 'player_tablet_id' => $tablet, 'redemption_id' => $redemption,
							'recovered' => false, 'state' => 'redeemed', 'session' => [ 'commerce_session_id' => $this->a['session'], 'name' => 'Saved QR Session',
							'date' => $this->a['session_date'], 'start_time_local' => null, 'timezone_name' => null, 'starts_at_utc' => null ],
							'package' => [ 'commerce_package_id' => $this->a['packages']['digital']['package_id'], 'name' => 'Saved Digital Package' ],
							'digital_faces_by_game_type' => [ [ 'portal_game_type_id' => $this->a['games'][ $this->game ], 'faces' => 1 ] ] ];
						array_push( $values, $request, hash( 'sha256', 'synthetic-' . $created ), $tablet, 'bench-relay', 'accepted', 1000000000 + $created, $this->a['session'], $redemption, json_encode( [ $this->game => 1 ], JSON_FORCE_OBJECT ), json_encode( $payload ), $now, $now );
					}
					$this->sql( $wpdb->prepare( 'INSERT INTO %i (request_id,intent_hash,player_tablet_id,caller_id,state,accepted_entitlement_id,session_id,redemption_id,contributions_json,response_json,created_at,accepted_at) VALUES ' . implode( ',', $tuples ), array_merge( [ $table ], $values ) ) );
				}
				$this->sql( $wpdb->prepare( 'ANALYZE TABLE %i', $table ) );
				$plan = $wpdb->get_results( $wpdb->prepare( "EXPLAIN FORMAT=TRADITIONAL SELECT contributions_json FROM %i WHERE state='accepted' AND session_id=%d AND player_tablet_id=%s", $table, $this->a['session'], 'bench-0' ), ARRAY_A );
				$this->expect( is_array( $plan ) && 'tablet_session' === ( $plan[0]['key'] ?? '' ), 'usage lookup uses tablet_session index at ' . $size . ' synthetic historical rows: ' . json_encode( $plan ) );
				$samples = [];
				for ( $i = 0; $i < 10; ++$i ) {
					$command = $this->command( $this->ticket( 1 ), 'bench-' . $i ); $start = hrtime( true ); $result = $this->redeem( $command );
					$samples[] = ( hrtime( true ) - $start ) / 1e6; $this->expect( is_array( $result ), 'real measured acceptance at historical size ' . $size );
				}
				sort( $samples ); $this->metrics[ 'ledger_' . $size ] = [ 'synthetic_historical_rows' => $size, 'samples' => count( $samples ), 'p50_ms' => $samples[4], 'p95_ms' => $samples[9], 'max_ms' => max( $samples ), 'usage_explain' => $plan ];
			}
			$this->sql( $wpdb->prepare( 'DELETE FROM %i WHERE accepted_entitlement_id BETWEEN %d AND %d', $table, 1000000000, 1000010000 ) );
		} );
	}
}

try { ( new QRPersistenceTest() )->run(); }
catch ( Throwable $error ) { fwrite( STDERR, 'FAIL ' . $error->getMessage() . "\n" ); exit( 1 ); }
