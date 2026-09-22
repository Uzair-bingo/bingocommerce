<?php

declare(strict_types=1);

/** Callback-free Catalog error regression. Isolated, owned disposable Hall only. */
if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_test_scratch' === (string) getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "FIXTURE GUARD: CLI required; scratch is reserved for ownership controls.\n" );
	exit( 1 );
}
require __DIR__ . '/fixtures/wordpress-bootstrap.php';

use BingoCommerce\Catalog\CatalogPackageCompositionPlan;
use BingoCommerce\Catalog\CatalogPackagePlan;
use BingoCommerce\Catalog\CatalogPlayProductPlan;
use BingoCommerce\Catalog\CatalogTransactionTransport as Transport;
use BingoCommerce\Catalog\Migrations;

/** Faults only after a real BEGIN; no production fault-injection surface. */
final class CatalogErrorConnection extends mysqli {
	public int $begins = 0;
	public int $rollbacks = 0;
	public int $commits = 0;
	public int $fault_errno = 0;
	public bool $open = false;
	public bool $fail_rollback = false;
	public bool $unclean_commit = false;
	public function begin_transaction( int $flags = 0, ?string $name = null ): bool {
		++$this->begins;
		$this->open = parent::begin_transaction( $flags, $name );
		return $this->open;
	}
	public function prepare( string $query ): mysqli_stmt|false {
		if ( $this->open && $this->fault_errno > 0 ) {
			throw new mysqli_sql_exception( 'Owned fixture statement fault.', $this->fault_errno );
		}
		return parent::prepare( $query );
	}
	public function rollback( int $flags = 0, ?string $name = null ): bool {
		++$this->rollbacks;
		if ( $this->fail_rollback ) {
			return false;
		}
		$result = parent::rollback( $flags, $name );
		$this->open = ! $result;
		return $result;
	}
	public function commit( int $flags = 0, ?string $name = null ): bool {
		++$this->commits;
		$result = parent::commit( $flags, $name );
		$this->open = ! $result;
		return $this->unclean_commit ? false : $result;
	}
}

final class CatalogTransportErrorsTest {
	private const OWNER = '_bc_catalog_error_fixture_owner';
	private const TABLES = [ 'bingo_game_types', 'bingo_play_products', 'bingo_packages', 'bingo_package_components', 'bingo_package_addons', 'bingo_sessions', 'bingo_session_packages', 'bingo_portal_session_templates', 'bingo_session_schedules', 'bingo_session_schedule_dates', 'bingo_session_schedule_packages' ];
	private int $site = 0;
	private string $token;
	private int $passed = 0;
	private int $failed = 0;
	private array $hooks = [];

	public function run(): void {
		global $wpdb;
		$this->token = wp_generate_password( 24, false, false );
		wp_set_current_user( (int) username_exists( 'fixture_admin' ) );
		add_filter( 'pre_wp_mail', '__return_true' );
		$baseline = [];
		foreach ( [ 2, 3, 4 ] as $site ) {
			$baseline[ $site ] = $this->snapshot( $site );
		}
		$site = wp_insert_site( [ 'domain' => 'catalog-errors-' . strtolower( $this->token ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Catalog error disposable fixture', 'user_id' => get_current_user_id() ] );
		if ( $site instanceof \WP_Error || $site <= 4 ) {
			throw new RuntimeException( 'Disposable site creation failed.' );
		}
		$this->site = (int) $site;
		update_site_meta( $this->site, self::OWNER, $this->token );
		switch_to_blog( $this->site );
		$original = $wpdb->dbh;
		$connection = new CatalogErrorConnection( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$connection->set_charset( 'utf8mb4' );
		// Match the existing suites: establish the server's COMMITTED record on
		// this fresh connection before the production fail-closed state probe.
		$connection->begin_transaction();
		$connection->commit();
		try {
			$this->owned();
			$this->same( true, Migrations::verify_current_site_schema(), 'disposable Catalog schema ready' );
			$post = wp_insert_post( [ 'post_type' => 'product', 'post_title' => 'Transport fixture', 'post_status' => 'publish' ], true );
			if ( $post instanceof \WP_Error || $post < 1 ) {
				throw new RuntimeException( 'Disposable product fixture failed.' );
			}
			$now = '2026-09-22 00:00:00';
			$wpdb->dbh = $connection;
			$created = Transport::createWithProductClaim( CatalogPlayProductPlan::forCreate( $this->site, $post, 'paper', 1, null, true, $now ) );
			if ( $created instanceof \WP_Error ) {
				throw new RuntimeException( $created->get_error_message() );
			}
			$play = $created->bingo_play_product_id;
			$listener = function () use ( $connection ): void {
				$this->hooks[] = [ current_filter(), $connection->open ];
			};
			add_action( 'wp_error_added', $listener );
			add_action( 'is_wp_error_instance', $listener );
			// Positive controls establish both listeners, outside any transaction.
			$control = new \WP_Error( 'control', 'control' );
			is_wp_error( $control );
			$this->same( [ [ 'wp_error_added', false ], [ 'is_wp_error_instance', false ] ], $this->hooks, 'both hook listeners observe positive controls outside BEGIN' );
			$this->hooks = [];
			$missing = 2147483647;
			$profiles = [
				'Play Product create' => [ fn() => Transport::createWithProductClaim( CatalogPlayProductPlan::forCreate( $this->site, $missing, 'paper', 1, null, true, $now ) ), 'woo_product_missing', 'The linked WooCommerce product no longer exists in this hall.' ],
				'Game Type assignment' => [ fn() => Transport::assignGameTypeReference( CatalogPlayProductPlan::forAssignGameType( $this->site, $missing, 1, $now ) ), 'play_product_not_found', 'The Bingo Play Product was not found.' ],
				'Paper to Digital' => [ fn() => Transport::transitionToDigital( CatalogPlayProductPlan::forPaperToDigital( $this->site, $missing, 1, $now ) ), 'play_product_not_found', 'The Bingo Play Product was not found.' ],
				'Digital to Paper' => [ fn() => Transport::transitionToPaper( CatalogPlayProductPlan::forDigitalToPaper( $this->site, $missing, $now ) ), 'play_product_not_found', 'The Bingo Play Product was not found.' ],
				'Play Product restore' => [ fn() => Transport::restoreWithWooRevalidation( CatalogPlayProductPlan::forRestore( $this->site, $missing, $missing, $now ) ), 'woo_product_missing', 'The linked WooCommerce product no longer exists in this hall.' ],
				'Package create' => [ fn() => Transport::createPackageWithComposition( CatalogPackagePlan::forCreate( $this->site, $missing, [ [ 'bingo_play_product_id' => $play, 'quantity' => 1 ] ], $now ) ), 'woo_product_missing', 'The linked WooCommerce product no longer exists in this hall.' ],
				'Composition replacement' => [ fn() => Transport::replacePackageComposition( CatalogPackageCompositionPlan::forReplaceComposition( $this->site, $missing, hash( 'sha256', '' ), [ [ 'bingo_play_product_id' => $play, 'quantity' => 1 ] ], $now ) ), 'package_not_found', 'The Bingo Package was not found.' ],
			];
			foreach ( $profiles as $label => [ $operation, $code, $message ] ) {
				$this->reset_counts( $connection );
				$error = $operation();
				$this->error( $code, $message, [ 'status' => $code ], $error, $label );
				$this->same( [ 1, 1, 0, false ], [ $connection->begins, $connection->rollbacks, $connection->commits, $connection->open ], $label . ': semantic error rolled back once before escaping, no retry' );
				$this->same( 'ROLLED BACK', $this->transaction_state( $connection ), $label . ': server confirms rollback' );
				$this->same( [], $this->hooks, $label . ': neither hook dispatched' );
				foreach ( [ 1205, 1213, 3572, 9999 ] as $errno ) {
					$this->reset_counts( $connection );
					$connection->fault_errno = $errno;
					$error = $operation();
					$connection->fault_errno = 0;
					$retryable = 9999 !== $errno;
					$attempts = $retryable ? 3 : 1;
					$data = [ 'db_errno' => $errno, 'status' => $retryable ? 'catalog_write_conflict' : 'catalog_statement_failed' ];
					if ( $retryable ) { $data['attempts'] = 3; }
					$this->error( $data['status'], $retryable ? 'The Catalog change could not be serialized against concurrent activity. Reload and try again.' : 'A required Catalog statement failed.', $data, $error, $label . " errno {$errno}" );
					$this->same( [ $attempts, $attempts, 0, false ], [ $connection->begins, $connection->rollbacks, $connection->commits, $connection->open ], $label . " errno {$errno}: original bounded retry/rollback behavior" );
					$this->same( [], $this->hooks, $label . " errno {$errno}: neither hook dispatched" );
				}
			}
			$this->reset_counts( $connection );
			$connection->fault_errno = 3572;
			$connection->fail_rollback = true;
			$error = $profiles['Package create'][0]();
			$this->error( 'catalog_transaction_state_untrusted', 'The Catalog transaction could not be rolled back, so the database session is no longer trustworthy.', [ 'original_code' => 'catalog_statement_failed', 'status' => 'catalog_transaction_state_untrusted' ], $error, 'rollback failure' );
			$this->same( [ 1, 1, true ], [ $connection->begins, $connection->rollbacks, $connection->open ], 'failed rollback is not retried' );
			$blocked = $profiles['Play Product create'][0]();
			$this->same( 'catalog_transaction_state_untrusted', $blocked instanceof \WP_Error ? $blocked->get_error_code() : null, 'poison is shared across profiles' );
			$this->same( 1, $connection->begins, 'poison prevents another BEGIN' );
			$this->same( [], $this->hooks, 'rollback failure and poisoned rejection dispatch neither hook' );
			$connection->fault_errno = 0;
			$connection->fail_rollback = false;
			$connection->rollback();
			// Test-only reset AFTER positively restoring the real server session.
			( new ReflectionProperty( Transport::class, 'untrusted' ) )->setValue( null, false );
			$this->reset_counts( $connection );
			$connection->unclean_commit = true;
			$error = Transport::transitionToPaper( CatalogPlayProductPlan::forDigitalToPaper( $this->site, $play, $now ) );
			$this->error( 'catalog_commit_outcome_unknown', 'The Catalog change was submitted but its result could not be confirmed. Reload this screen and check the current state before retrying.', [ 'status' => 'catalog_commit_outcome_unknown' ], $error, 'ambiguous COMMIT' );
			$this->same( [ 1, 1, 1 ], [ $connection->begins, $connection->commits, $connection->rollbacks ], 'ambiguous COMMIT: one attempt and hygiene rollback, no retry' );
			$this->same( [], $this->hooks, 'ambiguous COMMIT dispatches neither hook' );
			$builder = new ReflectionMethod( Transport::class, 'error' );
			$custom = [ 'status' => 'preserved', 'db_errno' => 1205, 'attempts' => 3, 'original_code' => 'original' ];
			$this->error( 'fixture_error', 'Exact message.', $custom, $builder->invoke( null, 'fixture_error', 'Exact message.', $custom ), 'central builder preserves all caller data' );
			$this->same( [], $this->hooks, 'central builder dispatches neither hook' );
			remove_action( 'wp_error_added', $listener );
			remove_action( 'is_wp_error_instance', $listener );
		} finally {
			$connection->fault_errno = 0;
			$connection->fail_rollback = false;
			$connection->rollback();
			$wpdb->dbh = $original;
			$connection->close();
			( new ReflectionProperty( Transport::class, 'untrusted' ) )->setValue( null, false );
			restore_current_blog();
			$this->cleanup();
		}
		foreach ( $baseline as $site => $before ) {
			$this->same( $before, $this->snapshot( $site ), "persistent fixture Hall {$site} byte-identical" );
		}
		echo "RESULT: {$this->passed} passed; {$this->failed} failed.\n";
		exit( $this->failed > 0 ? 1 : 0 );
	}
	private function reset_counts( CatalogErrorConnection $connection ): void {
		$connection->begins = $connection->rollbacks = $connection->commits = 0;
	}
	private function transaction_state( mysqli $connection ): string {
		$statement = $connection->prepare( 'SELECT state FROM performance_schema.events_transactions_current WHERE thread_id = PS_CURRENT_THREAD_ID()' );
		$statement->execute();
		$state = $statement->get_result()->fetch_row()[0];
		$statement->close();
		return $state;
	}
	private function error( string $code, string $message, array $data, mixed $actual, string $label ): void {
		$this->same( true, $actual instanceof \WP_Error, $label . ': WP_Error' );
		$this->same( $code, $actual instanceof \WP_Error ? $actual->get_error_code() : null, $label . ': code' );
		$this->same( $message, $actual instanceof \WP_Error ? $actual->get_error_message() : null, $label . ': message' );
		$actual_data = $actual instanceof \WP_Error ? $actual->get_error_data() : [];
		ksort( $data ); ksort( $actual_data );
		$this->same( $data, $actual_data, $label . ': exact data' );
	}
	private function owned(): void {
		global $wpdb;
		if ( $this->site <= 4 || $this->token !== get_site_meta( $this->site, self::OWNER, true ) || $wpdb->get_blog_prefix( $this->site ) !== $wpdb->base_prefix . $this->site . '_' || ! in_array( DB_NAME, BC_FIXTURE_DB_ALLOWLIST, true ) || DB_NAME === BC_Fixture::$live_db || BC_FIXTURE_IDENTITY !== $wpdb->get_var( 'SELECT identity FROM bc_fixture_sentinel' ) ) {
			throw new RuntimeException( 'Fixture ownership guard refused cleanup.' );
		}
	}
	private function cleanup(): void {
		global $wpdb;
		$this->owned();
		foreach ( self::TABLES as $suffix ) {
			if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->get_blog_prefix( $this->site ) . $suffix ) ) ) {
				throw new RuntimeException( 'Owned custom-table cleanup failed.' );
			}
		}
		$deleted = wp_delete_site( $this->site );
		if ( $deleted instanceof \WP_Error || null !== get_site( $this->site ) ) {
			throw new RuntimeException( 'Disposable site cleanup failed.' );
		}
	}
	private function snapshot( int $site ): array {
		global $wpdb;
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->get_blog_prefix( $site ) ) . '%' ) );
		$result = [];
		foreach ( $tables as $table ) {
			$ddl = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A );
			if ( ! is_array( $ddl ) || ! is_array( $rows ) || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Snapshot failed.' ); }
			$encoded = array_map( 'serialize', $rows ); sort( $encoded, SORT_STRING );
			$result[ $table ] = hash( 'sha256', $ddl[1] . serialize( $encoded ) );
		}
		ksort( $result ); return $result;
	}
	private function same( mixed $expected, mixed $actual, string $label ): void {
		if ( $expected === $actual ) { ++$this->passed; echo "PASS {$label}\n"; }
		else { ++$this->failed; echo "FAIL {$label}: " . var_export( $actual, true ) . "\n"; }
	}
}
( new CatalogTransportErrorsTest() )->run();
