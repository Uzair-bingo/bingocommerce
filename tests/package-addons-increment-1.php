<?php

/**
 * ADR-044 backend suite. Deliberately NON-STRICT to prove the raw mixed boundary
 * rejects PHP float prices and float/string maximums without caller coercion.
 * Run: BC_TEST_DB=bingocommerce_state_recon php tests/package-addons-increment-1.php
 * All writes belong to this run's token-owned disposable Halls/users.
 */
if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_test_scratch' === (string) getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "FIXTURE GUARD: CLI required; scratch is reserved for ownership controls.\n" );
	exit( 1 );
}
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';

use BingoCommerce\Catalog\BingoPlayProductService;
use BingoCommerce\Catalog\CatalogPackageAddonPlan;
use BingoCommerce\Catalog\CatalogTransactionTransport as Transport;
use BingoCommerce\Catalog\Migrations;
use BingoCommerce\Catalog\PackageAddonResult;
use BingoCommerce\Catalog\PackageAddonService;
use BingoCommerce\Catalog\PackageService;
use BingoCommerce\Core\Migrations as CoreMigrations;
use BingoCommerce\Core\Roles;

/** Test-only connection observation and faults; no production callbacks added. */
final class AddonFixtureConnection extends mysqli {
	public int $begins = 0;
	public int $commits = 0;
	public int $rollbacks = 0;
	public int $fault_errno = 0;
	public bool $open = false;
	public bool $unknown_commit = false;
	public bool $commit_on_unknown = true;
	public bool $fail_rollback = false;
	public array $statements = [];
	public function begin_transaction( int $flags = 0, ?string $name = null ): bool {
		++$this->begins;
		return $this->open = parent::begin_transaction( $flags, $name );
	}
	public function prepare( string $query ): mysqli_stmt|false {
		if ( $this->open ) {
			$this->statements[] = $query;
			if ( $this->fault_errno > 0 ) { throw new mysqli_sql_exception( 'Owned fixture fault.', $this->fault_errno ); }
		}
		return parent::prepare( $query );
	}
	public function commit( int $flags = 0, ?string $name = null ): bool {
		++$this->commits;
		if ( ! $this->unknown_commit || $this->commit_on_unknown ) {
			$result = parent::commit( $flags, $name );
			$this->open = ! $result;
		}
		return $this->unknown_commit ? false : $result;
	}
	public function rollback( int $flags = 0, ?string $name = null ): bool {
		++$this->rollbacks;
		if ( $this->fail_rollback ) { return false; }
		$result = parent::rollback( $flags, $name );
		$this->open = ! $result;
		return $result;
	}
}

final class PackageAddonsBackendTest {
	private const OWNER = '_bc_package_addon_backend_owner';
	private const TABLES = [ 'bingo_game_types', 'bingo_play_products', 'bingo_packages', 'bingo_package_components', 'bingo_package_addons', 'bingo_sessions', 'bingo_session_packages', 'bingo_portal_session_templates', 'bingo_session_schedules', 'bingo_session_schedule_dates', 'bingo_session_schedule_packages' ];
	private array $sites = [];
	private array $users = [];
	private string $token;
	private int $admin;
	private int $passed = 0;
	private int $failed = 0;
	private PackageAddonService $service;
	private int $game_type;

	public function run(): void {
		global $wpdb;
		$this->token = wp_generate_password( 24, false, false );
		$this->admin = (int) username_exists( 'fixture_admin' );
		wp_set_current_user( $this->admin );
		add_filter( 'pre_wp_mail', '__return_true' );
		$this->service = new PackageAddonService();
		$baseline = [];
		foreach ( [ 2, 3, 4 ] as $site ) { $baseline[ $site ] = $this->snapshot( $site ); }
		$tables_before = $wpdb->get_col( 'SHOW TABLES' );
		try {
			$a = $this->site();
			$b = $this->site();
			switch_to_blog( $a );
			$this->game_type = $this->game_type();
			$this->basic();
			$this->unresolved_listing();
			$this->validation();
			$this->lifecycle();
			$this->integrity();
			$this->invalid_enabled_integrity();
			$this->corruption();
			$this->ordinary_audit();
			$this->settlement();
			$this->transport();
			$this->authorization( $a, $b );
			$this->isolation( $a, $b );
			$this->concurrency( $a );
			restore_current_blog();
		} catch ( Throwable $error ) {
			$this->same( 'no exception', get_class( $error ) . ': ' . $error->getMessage(), 'suite completion' );
			echo $error->getTraceAsString() . "\n";
		} finally {
			while ( ms_is_switched() ) { restore_current_blog(); }
			wp_set_current_user( $this->admin );
			$this->cleanup();
		}
		foreach ( $baseline as $site => $before ) { $this->same( $before, $this->snapshot( $site ), "persistent fixture Hall {$site} byte-identical" ); }
		$this->same( $tables_before, $wpdb->get_col( 'SHOW TABLES' ), 'no lock table or other new persistence remains' );
		echo "RESULT: {$this->passed} passed; {$this->failed} failed.\n";
		exit( $this->failed > 0 ? 1 : 0 );
	}

	private function basic(): void {
		global $wpdb;
		$p = $this->play(); $q = $this->play(); $package = $this->package( [ $p ] );
		$this->same( [], $this->service->listForPackage( $package ), 'zero configured add-ons is a valid empty list' );
		$row = $this->success( $this->service->create( $package, $p, '4.54' ), 'package_addon_created', 'create base product as optional add-on' );
		$this->same( [ '4.54', 1, 1, 0 ], [ $row['unit_price'], $row['max_quantity'], $row['enabled'], $row['sort_order'] ], 'exact string price and create defaults' );
		$this->same( 'package_only_addon', $this->projection( $p ), 'create projects enabled relationship' );
		$payload = '';
		foreach ( [ 'bc-package-addon-fingerprint-v1', (string) $row['package_addon_id'], (string) $package, (string) $p, '4.54', '1', '1' ] as $value ) { $payload .= strlen( $value ) . ':' . $value; }
		$this->same( hash( 'sha256', $payload ), $row['fingerprint'], 'independent length-prefixed fingerprint oracle' );
		$this->same( 1, preg_match( '/\A[0-9a-f]{64}\z/', $row['fingerprint'] ), 'opaque lowercase SHA-256 token' );
		$this->noop( fn() => $this->service->create( $package, $p, '4.54' ), 'identical create' );
		$this->noop( fn() => $this->service->update( $row['package_addon_id'], $row['fingerprint'], '4.54', 1, true ), 'identical update' );
		$this->error( 'package_addon_already_exists', $this->service->create( $package, $p, '4.55' ), 'different duplicate rejected without overwrite' );
		$changed = $this->success( $this->service->update( $row['package_addon_id'], $row['fingerprint'], '23.55', 99, false ), 'package_addon_updated', 'update price maximum and disable' );
		$this->same( [ '23.55', 99, 0 ], [ $changed['unit_price'], $changed['max_quantity'], $changed['enabled'] ], 'disabled configuration retained' );
		$this->same( 'none', $this->projection( $p ), 'zero enabled relationships projects none' );
		$this->error( 'package_addon_stale', $this->service->update( $row['package_addon_id'], $row['fingerprint'], '21.00', 1, true ), 'stale update' );
		$this->error( 'package_addon_stale', $this->service->remove( $row['package_addon_id'], $row['fingerprint'] ), 'stale remove' );
		$this->error( 'package_addon_stale', $this->service->update( $row['package_addon_id'], 'bad', '21.00', 1, true ), 'malformed token' );
		$changed = $this->success( $this->service->update( $changed['package_addon_id'], $changed['fingerprint'], '21.00', 2, false ), 'package_addon_updated', 'disabled price and maximum editable with active parents' );
		$changed = $this->success( $this->service->update( $changed['package_addon_id'], $changed['fingerprint'], '21.00', 2, true ), 'package_addon_updated', 'enable' );
		$second = $this->success( $this->service->create( $package, $q, '0.00' ), 'package_addon_created', 'second configured add-on' );
		$this->owned( get_current_blog_id() );
		$wpdb->update( Migrations::package_addons_table(), [ 'sort_order' => 9 ], [ 'package_addon_id' => $changed['package_addon_id'] ] );
		$listed = $this->service->listForPackage( $package );
		$this->same( [ $second['package_addon_id'], $changed['package_addon_id'] ], array_column( $listed, 'package_addon_id' ), 'listing sort_order then identity' );
		$this->same( $changed['fingerprint'], $listed[1]['fingerprint'], 'sort order excluded from fingerprint' );
		$changed = $this->success( $this->service->update( $changed['package_addon_id'], $changed['fingerprint'], '21.00', 3, true ), 'package_addon_updated', 'update retains ordering' );
		$this->same( 9, $changed['sort_order'], 'update never reorders' );
		$other_package = $this->package( [ $q ] );
		$other = $this->success( $this->service->create( $other_package, $p, '10.29', 7 ), 'package_addon_created', 'same Play Product independent second Package configuration' );
		$this->success( $this->service->remove( $changed['package_addon_id'], $changed['fingerprint'] ), 'package_addon_removed', 'permanent relationship remove' );
		$this->same( 'package_only_addon', $this->projection( $p ), 'removing one relationship preserves another enabled projection' );
		$this->same( '10.29', $this->service->listForPackage( $other_package )[0]['unit_price'], 'other Package price unchanged' );
		$other = $this->success( $this->service->update( $other['package_addon_id'], $other['fingerprint'], '10.29', 7, false ), 'package_addon_updated', 'disable last enabled relationship' );
		$this->same( 'none', $this->projection( $p ), 'all disabled projects none' );
		$this->error( 'package_addon_not_found', $this->service->remove( $changed['package_addon_id'], $changed['fingerprint'] ), 'removed identity does not resolve' );
		$this->error( 'invalid_package_addon_id', $this->service->remove( 0, str_repeat( 'a', 64 ) ), 'invalid relationship identity' );
		$this->same( 'paper', ( new PackageService() )->getById( $package )['package_type'], 'add-ons never change stored base type' );
	}

	private function validation(): void {
		$p = $this->play(); $package = $this->package( [ $p ] );
		foreach ( [ '4.54', '23.55', '21.00', '0.00', '4.23', '10.29', '99999999999999999.99' ] as $price ) {
			$row = $this->success( $this->service->create( $package, $p, $price ), 'package_addon_created', 'canonical price accepted ' . $price );
			$this->same( $price, $row['unit_price'], 'price remains exact string' );
			$this->success( $this->service->remove( $row['package_addon_id'], $row['fingerprint'] ), 'package_addon_removed', 'reset owned relationship' );
		}
		foreach ( [ 4.54, 21.0, 0.0, 5, true, null, [], new stdClass(), '5', '5.0', '5.5', '4.234', '+5.00', '-5.00', '05.00', '00.00', '1e2', '$5.00', '1,000.00', '100000000000000000.00', '', ' 5.00', "5.00\n", '.00', '5.', 'NaN' ] as $price ) {
			$this->error( 'package_addon_price_invalid', $this->service->create( $package, $p, $price ), 'noncanonical/raw price rejected (' . get_debug_type( $price ) . ')' );
		}
		$row = $this->success( $this->service->create( $package, $p, '21.00', 1 ), 'package_addon_created', 'native maximum one accepted' );
		foreach ( [ 0, -1, 100, PHP_INT_MAX, 1.0, 2.5, '1', '99', true, false, null, [] ] as $maximum ) {
			$this->error( 'package_addon_max_quantity_invalid', $this->service->create( $package, $p, '21.00', $maximum ), 'raw maximum rejected (' . get_debug_type( $maximum ) . ')' );
			$this->error( 'package_addon_max_quantity_invalid', $this->service->update( $row['package_addon_id'], $row['fingerprint'], '21.00', $maximum, true ), 'update raw maximum rejected' );
		}
		$this->error( 'package_addon_price_invalid', $this->service->update( $row['package_addon_id'], $row['fingerprint'], 21.0, 1, true ), 'non-strict caller float price rejected on update' );
		$this->same( '21.00', $this->service->listForPackage( $package )[0]['unit_price'], 'invalid requests do not normalize or mutate saved price' );
	}

	private function unresolved_listing(): void {
		global $wpdb;
		$p = $this->play(); $q = $this->play(); $package = $this->package( [ $p ] );
		$valid = $this->success( $this->service->create( $package, $p, '4.23', 3 ), 'package_addon_created', 'resolved listing fixture' );
		$missing = $this->success( $this->service->create( $package, $q, '10.29', 7 ), 'package_addon_created', 'unresolved listing fixture' );
		$this->owned( get_current_blog_id() );
		$this->same( 1, $wpdb->update( Migrations::package_addons_table(), [ 'bingo_play_product_id' => 2147483647, 'sort_order' => 0 ], [ 'package_addon_id' => $missing['package_addon_id'] ] ), 'owned configured relationship points at missing Play Product' );
		$this->same( 1, $wpdb->update( Migrations::package_addons_table(), [ 'sort_order' => 9 ], [ 'package_addon_id' => $valid['package_addon_id'] ] ), 'resolved relationship ordered after unresolved relationship' );
		$stored = $this->relationship_rows( $package );
		usort( $stored, static fn( $left, $right ) => [ (int) $left['sort_order'], (int) $left['package_addon_id'] ] <=> [ (int) $right['sort_order'], (int) $right['package_addon_id'] ] );
		$before = [ $this->snapshot( get_current_blog_id() ), $this->audits() ];
		$listed = $this->service->listForPackage( $package );
		$this->same( [ $missing['package_addon_id'], $valid['package_addon_id'] ], array_column( $listed, 'package_addon_id' ), 'resolved and unresolved configured rows retain deterministic order' );
		$expected_unresolved = $stored[0];
		foreach ( [ 'package_addon_id', 'package_id', 'bingo_play_product_id', 'max_quantity', 'enabled', 'sort_order' ] as $integer_field ) {
			$expected_unresolved[ $integer_field ] = (int) $expected_unresolved[ $integer_field ];
		}
		$expected_unresolved['fingerprint'] = CatalogPackageAddonPlan::fingerprint( $stored[0] );
		$this->same( $expected_unresolved, array_intersect_key( $listed[0], $expected_unresolved ), 'unresolved row preserves every configured relationship field' );
		$this->same( CatalogPackageAddonPlan::fingerprint( $stored[0] ), $listed[0]['fingerprint'], 'unresolved row preserves exact relationship fingerprint' );
		$this->same( CatalogPackageAddonPlan::fingerprint( $stored[1] ), $listed[1]['fingerprint'], 'resolved row preserves exact relationship fingerprint' );
		$this->same( [ null, null, false, false, 'play_product_not_found' ], [ $listed[0]['play_product_status'], $listed[0]['medium'], $listed[0]['medium_compatible'], $listed[0]['eligible'], $listed[0]['resolution_error'] ], 'unresolved row exposes exact resolution state' );
		$this->same( null, $listed[1]['resolution_error'], 'resolved row exposes null resolution error' );
		$this->same( $before, [ $this->snapshot( get_current_blog_id() ), $this->audits() ], 'listing unresolved row performs no mutation, timestamp, audit or projection write' );

		$prior = $wpdb->suppress_errors( true );
		$filter = static fn( $sql ) => str_contains( $sql, Migrations::play_products_table() ) && str_contains( $sql, 'WHERE bingo_play_product_id =' ) ? 'INVALID addon_parent_read_fault' : $sql;
		add_filter( 'query', $filter );
		try { $this->error( 'persistence_failed', $this->service->listForPackage( $package ), 'Play Product read failure is not an unresolved row' ); }
		finally { remove_filter( 'query', $filter ); $wpdb->suppress_errors( $prior ); }

		$fingerprint = $listed[0]['fingerprint'];
		$before = [ $this->snapshot( get_current_blog_id() ), $this->audits() ];
		$this->error( 'play_product_not_found', $this->service->update( $listed[0]['package_addon_id'], $fingerprint, '10.29', 7, false ), 'unresolved relationship update fails closed' );
		$this->same( $before, [ $this->snapshot( get_current_blog_id() ), $this->audits() ], 'failed unresolved update leaves relationship and Hall byte-identical' );
		$this->error( 'play_product_not_found', $this->service->remove( $listed[0]['package_addon_id'], $fingerprint ), 'unresolved relationship remove fails closed' );
		$this->same( $before, [ $this->snapshot( get_current_blog_id() ), $this->audits() ], 'failed unresolved remove never detaches relationship' );
	}

	private function lifecycle(): void {
		foreach ( [ false, true ] as $package_archived ) {
			foreach ( [ false, true ] as $product_archived ) {
				$p = $this->play(); $package = $this->package( [ $p ] );
				$row = $this->success( $this->service->create( $package, $p, '5.00' ), 'package_addon_created', 'lifecycle setup' );
				if ( $package_archived ) { ( new PackageService() )->archive( $package ); }
				if ( $product_archived ) { ( new BingoPlayProductService() )->archive( $p ); }
				$listed = $this->service->listForPackage( $package );
				$this->same( $row['fingerprint'], $listed[0]['fingerprint'], 'archive preserves relationship configuration and fingerprint' );
				$this->same( ! $package_archived && ! $product_archived, $listed[0]['eligible'], 'eligibility respects both parent lifecycles' );
				$this->noop( fn() => $this->service->create( $package, $p, '5.00' ), 'verified create no-op across parent lifecycle combinations' );
				$this->noop( fn() => $this->service->update( $row['package_addon_id'], $row['fingerprint'], '5.00', 1, true ), 'verified update no-op across parent lifecycle combinations' );
				$change = $this->service->update( $row['package_addon_id'], $row['fingerprint'], '6.00', 2, false );
				if ( $package_archived || $product_archived ) {
					$this->error( $package_archived ? 'package_archived' : 'play_product_not_active', $change, 'archived actual configuration edit denied' );
				} else { $row = $this->success( $change, 'package_addon_updated', 'active parents permit edit' ); }
				$disable = $this->service->update( $row['package_addon_id'], $row['fingerprint'], $row['unit_price'], $row['max_quantity'], false );
				if ( $package_archived ) { $this->error( 'package_archived', $disable, 'archived Package forbids disable' ); }
				else { $row = $this->success( $disable, 0 === $row['enabled'] ? 'package_addon_unchanged' : 'package_addon_updated', 'archived Play Product permits disable only' ); }
				if ( ! $package_archived && $product_archived ) {
					$this->error( 'play_product_not_active', $this->service->update( $row['package_addon_id'], $row['fingerprint'], $row['unit_price'], $row['max_quantity'], true ), 'archived Play Product cannot enable' );
					$this->noop( fn() => $this->service->update( $row['package_addon_id'], $row['fingerprint'], $row['unit_price'], $row['max_quantity'], false ), 'archived disabled unchanged submission allowed' );
				}
				if ( $package_archived ) { $this->error( 'package_archived', $this->service->remove( $row['package_addon_id'], $row['fingerprint'] ), 'archived Package forbids removal' ); }
				$before_restore = $this->relationship_rows( $package );
				( new PackageService() )->restore( $package );
				( new BingoPlayProductService() )->restore( $p );
				$this->same( $before_restore, $this->relationship_rows( $package ), 'restore preserves every relationship byte' );
				( new BingoPlayProductService() )->archive( $p );
				$this->success( $this->service->remove( $row['package_addon_id'], $row['fingerprint'] ), 'package_addon_removed', 'archived Play Product permits explicit removal' );
				$this->error( 'play_product_not_active', $this->service->create( $package, $p, '5.00' ), 'archived Play Product denies new relationship' );
				( new PackageService() )->archive( $package );
				$this->error( 'package_archived', $this->service->create( $package, $p, '5.00' ), 'archived Package denies new relationship' );
			}
		}
	}

	private function integrity(): void {
		$paper = $this->play(); $digital = $this->play( 'digital' );
		foreach ( [ 'paper' => [ $paper ], 'digital' => [ $digital ], 'hybrid' => [ $paper, $digital ] ] as $type => $base ) {
			$package = $this->package( $base );
			foreach ( [ 'paper', 'digital' ] as $medium ) {
				$p = $this->play( $medium );
				$result = $this->service->create( $package, $p, '5.00' );
				if ( 'paper' === $type && 'digital' === $medium ) { $this->error( 'package_addon_medium_incompatible', $result, 'Paper rejects Digital add-on' ); }
				else { $this->success( $result, 'package_addon_created', "{$type} accepts {$medium} add-on" ); }
				$this->same( $type, ( new PackageService() )->getById( $package )['package_type'], 'type derived from base only' );
			}
		}
		foreach ( [ 'paper', 'digital' ] as $medium ) {
			foreach ( [ 'base', 'addon', 'both' ] as $cause ) {
				$p = $this->play( $medium ); $other = $this->play( 'digital' );
				$package = $this->package( 'addon' === $cause ? [ $other ] : [ $p ] );
				if ( 'base' !== $cause ) { $this->success( $this->service->create( $package, $p, '5.00' ), 'package_addon_created', 'medium reference setup' ); }
				$result = ( new BingoPlayProductService() )->updateMedium( $p, 'paper' === $medium ? 'digital' : 'paper', 'paper' === $medium ? $this->game_type : null );
				$this->error( 'play_product_package_referenced', $result, "{$medium} actual transition blocked by {$cause}" );
				$expected = 'both' === $cause ? [ 'base component', 'enabled add-on' ] : [ 'base' === $cause ? 'base component' : 'enabled add-on' ];
				$this->same( $expected, $result->get_error_data()['reference_causes'], 'existing code identifies exact reference causes' );
				foreach ( $expected as $text ) { $this->same( true, str_contains( $result->get_error_message(), $text ), 'message identifies ' . $text ); }
			}
		}
		$p = $this->play(); $package = $this->package( [ $paper ] );
		$row = $this->success( $this->service->create( $package, $p, '5.00' ), 'package_addon_created', 'disabled transition setup' );
		$row = $this->success( $this->service->update( $row['package_addon_id'], $row['fingerprint'], '5.00', 1, false ), 'package_addon_updated', 'disable before medium transition' );
		$this->same( 'play_product_medium_changed', ( new BingoPlayProductService() )->updateMedium( $p, 'digital', $this->game_type )->status, 'disabled relationship permits medium change' );
		$this->error( 'package_addon_medium_incompatible', $this->service->update( $row['package_addon_id'], $row['fingerprint'], '5.00', 1, true ), 'enabling revalidates current medium' );
		$listed = $this->service->listForPackage( $package );
		$this->same( [ 0, false ], [ $listed[0]['enabled'], $listed[0]['medium_compatible'] ], 'incompatible disabled configured row remains visible' );
		$package = $this->package( [ $digital ] );
		$row = $this->success( $this->service->create( $package, $p, '5.00' ), 'package_addon_created', 'composition compatibility setup' );
		$service = new PackageService(); $fingerprint = $service->compositionFingerprint( $package );
		$before = $this->base_rows( $package );
		$this->error( 'package_addon_medium_incompatible', $service->replaceComponents( $package, $fingerprint, [ [ 'bingo_play_product_id' => $paper, 'quantity' => 1 ] ] ), 'composition cannot become Paper with enabled Digital add-on' );
		$this->same( $before, $this->base_rows( $package ), 'rejected replacement leaves base and type byte-identical' );
		$this->success( $this->service->update( $row['package_addon_id'], $row['fingerprint'], '5.00', 1, false ), 'package_addon_updated', 'disabled add-on no longer constrains type' );
		$this->same( 'package_composition_updated', $service->replaceComponents( $package, $fingerprint, [ [ 'bingo_play_product_id' => $paper, 'quantity' => 1 ] ] )->status, 'disabled Digital add-on permits Paper composition' );
	}

	private function invalid_enabled_integrity(): void {
		global $wpdb;
		$this->owned( get_current_blog_id() );
		foreach ( [ 'composition', 'paper', 'digital' ] as $case ) {
			$base = $this->play( 'digital' );
			$p = $this->play( 'digital' === $case ? 'digital' : 'paper' );
			$package = $this->package( [ $base ] );
			$row = $this->success( $this->service->create( $package, $p, '5.00' ), 'package_addon_created', $case . ': invalid enabled fixture setup' );
			$this->same( 1, $wpdb->update( Migrations::package_addons_table(), [ 'enabled' => 2 ], [ 'package_addon_id' => $row['package_addon_id'] ] ), $case . ': owned relationship set to enabled = 2' );
			$service = new PackageService();
			$fingerprint = $service->compositionFingerprint( $package );
			$before = [ $this->snapshot( get_current_blog_id() ), $this->audits() ];
			if ( 'composition' === $case ) {
				$result = $service->replaceComponents( $package, $fingerprint, [ [ 'bingo_play_product_id' => $p, 'quantity' => 1 ] ] );
			} else {
				$result = ( new BingoPlayProductService() )->updateMedium( $p, 'paper' === $case ? 'digital' : 'paper', 'paper' === $case ? $this->game_type : null );
			}
			$this->error( 'package_addon_state_inconsistent', $result, $case . ': enabled = 2 fails closed before actual transition' );
			$this->same( $before, [ $this->snapshot( get_current_blog_id() ), $this->audits() ], $case . ': rejected transition leaves every Hall row, timestamp, metadata and audit byte-identical' );
			$this->same( 1, $wpdb->update( Migrations::package_addons_table(), [ 'enabled' => 1 ], [ 'package_addon_id' => $row['package_addon_id'] ] ), $case . ': restore owned test corruption' );
		}
	}

	private function corruption(): void {
		global $wpdb;
		$p = $this->play(); $package = $this->package( [ $p ] );
		$row = $this->success( $this->service->create( $package, $p, '5.00' ), 'package_addon_created', 'corruption setup' );
		$this->owned( get_current_blog_id() );
		foreach ( [ [ 'unit_price', '-1.00' ], [ 'max_quantity', 0 ], [ 'max_quantity', 100 ], [ 'enabled', 2 ] ] as [ $key, $value ] ) {
			$wpdb->update( Migrations::package_addons_table(), [ $key => $value ], [ 'package_addon_id' => $row['package_addon_id'] ] );
			$this->error( 'package_addon_state_inconsistent', $this->service->listForPackage( $package ), 'corrupt stored ' . $key . ' fails listing' );
			$this->error( 'package_addon_state_inconsistent', $this->service->create( $package, $p, '5.00' ), 'corrupt relationship is not a duplicate no-op' );
			$this->error( 'package_addon_state_inconsistent', $this->service->remove( $row['package_addon_id'], $row['fingerprint'] ), 'corrupt relationship is not silently removed' );
			$wpdb->update( Migrations::package_addons_table(), [ $key => $row[ $key ] ], [ 'package_addon_id' => $row['package_addon_id'] ] );
		}
		foreach ( [ [ Migrations::packages_table(), 'package_id', $package, 'status', 'broken', 'active' ], [ Migrations::packages_table(), 'package_id', $package, 'package_type', 'broken', 'paper' ], [ Migrations::play_products_table(), 'bingo_play_product_id', $p, 'medium', 'broken', 'paper' ], [ Migrations::play_products_table(), 'bingo_play_product_id', $p, 'status', 'broken', 'active' ], [ Migrations::play_products_table(), 'bingo_play_product_id', $p, 'faces_per_unit', 0, 6 ] ] as [ $table, $id_column, $id, $key, $value, $original ] ) {
			$wpdb->update( $table, [ $key => $value ], [ $id_column => $id ] );
			$this->error( 'package_addon_state_inconsistent', $this->service->listForPackage( $package ), 'corrupt parent fails listing' );
			$this->error( 'package_addon_state_inconsistent', $this->service->create( $package, $p, '5.00' ), 'corrupt parent fails locked no-op' );
			$wpdb->update( $table, [ $key => $original ], [ $id_column => $id ] );
		}
		foreach ( [ 'package_id' => 'package_not_found', 'bingo_play_product_id' => 'play_product_not_found' ] as $key => $code ) {
			$wpdb->update( Migrations::package_addons_table(), [ $key => 2147483647 ], [ 'package_addon_id' => $row['package_addon_id'] ] );
			$this->error( $code, $this->service->remove( $row['package_addon_id'], $row['fingerprint'] ), 'missing parent never silently detached' );
			$wpdb->update( Migrations::package_addons_table(), [ $key => $row[ $key ] ], [ 'package_addon_id' => $row['package_addon_id'] ] );
		}
		$prior = $wpdb->suppress_errors( true );
		$filter = static fn( $sql ) => str_contains( $sql, 'ORDER BY sort_order, package_addon_id' ) ? 'INVALID addon_read_fault' : $sql;
		add_filter( 'query', $filter );
		try { $this->error( 'persistence_failed', $this->service->listForPackage( $package ), 'failed listing is not a false empty state' ); }
		finally { remove_filter( 'query', $filter ); $wpdb->suppress_errors( $prior ); }
	}

	private function ordinary_audit(): void {
		$p = $this->play();
		$distinct_target_seen = false;
		// Two distinct relationships share one Play Product. At least one
		// target must differ numerically, exposing a mistaken Play Product ID.
		for ( $i = 0; $i < 2; ++$i ) {
			$package = $this->package( [ $p ] );
			$before_audit = $this->audits();
			$row = $this->success( $this->service->create( $package, $p, '4.54', 2 ), 'package_addon_created', 'ordinary audit create' );
			$values = [
				'package_addon_id' => $row['package_addon_id'], 'package_id' => $package, 'bingo_play_product_id' => $p,
				'unit_price' => '4.54', 'max_quantity' => 2, 'enabled' => 1, 'sort_order' => 0,
				'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
			];
			$this->assert_audit( $before_audit, 'catalog.package_addon.created', $values, null, $values );
			$distinct_target_seen = $distinct_target_seen || $row['package_addon_id'] !== $p;
			$this->noop( fn() => $this->service->create( $package, $p, '4.54', 2 ), 'ordinary audit identical create has no event' );
			$before_audit = $this->audits();
			$changed = $this->success( $this->service->update( $row['package_addon_id'], $row['fingerprint'], '23.55', 99, false ), 'package_addon_updated', 'ordinary audit update/disable' );
			$new_values = array_replace( $values, [ 'unit_price' => '23.55', 'max_quantity' => 99, 'enabled' => 0, 'updated_at' => $changed['updated_at'] ] );
			$this->assert_audit( $before_audit, 'catalog.package_addon.updated', $new_values, $values, $new_values );
			$this->noop( fn() => $this->service->update( $changed['package_addon_id'], $changed['fingerprint'], '23.55', 99, false ), 'ordinary audit identical update has no event' );
			$before_audit = $this->audits();
			$this->success( $this->service->remove( $changed['package_addon_id'], $changed['fingerprint'] ), 'package_addon_removed', 'ordinary audit remove' );
			$this->assert_audit( $before_audit, 'catalog.package_addon.removed', $new_values, $new_values, null );
		}
		$this->same( true, $distinct_target_seen, 'audit target-ID assertions include distinct relationship and Play Product IDs' );
	}

	private function assert_audit( array $before_audit, string $action, array $identity, ?array $previous, ?array $new ): void {
		$audit = array_values( array_diff_key( $this->audits(), $before_audit ) );
		$this->same( 1, count( $audit ), $action . ': exactly one ordinary audit event' );
		$this->same(
			[ $action, 'bingo_package_addon', (string) $identity['package_addon_id'], (string) get_current_blog_id(), (string) get_current_user_id() ],
			[ $audit[0]['action'], $audit[0]['target_type'], $audit[0]['target_id'], $audit[0]['blog_id'], $audit[0]['actor_user_id'] ],
			$action . ': exact action, relationship target ID, Hall and actor'
		);
		foreach ( [ 'previous_value' => $previous, 'new_value' => $new, 'context' => [ 'package_id' => $identity['package_id'], 'bingo_play_product_id' => $identity['bingo_play_product_id'] ] ] as $key => $expected ) {
			$actual = null === $audit[0][ $key ] ? null : json_decode( $audit[0][ $key ], true, 512, JSON_THROW_ON_ERROR );
			$this->same( $expected, $actual, $action . ': exact canonical ' . $key );
		}
	}

	private function settlement(): void {
		global $wpdb;
		$p = $this->play(); $package = $this->package( [ $p ] );
		$row = $this->success( $this->service->create( $package, $p, '21.00', 2 ), 'package_addon_created', 'settlement failure fixture setup' );
		$failed_audit = new PackageAddonService( static fn() => new WP_Error( 'fixture_audit', 'Injected audit failure.' ) );
		$changed = $failed_audit->update( $row['package_addon_id'], $row['fingerprint'], '23.55', 2, false );
		$row = $this->success( $changed, 'package_addon_updated', 'audit failure retains committed success', [ 'audit_write_failed' ] );
		$throws = new PackageAddonService( static function () { throw new RuntimeException( 'Injected audit exception.' ); } );
		$row = $this->success( $throws->update( $row['package_addon_id'], $row['fingerprint'], '23.55', 2, true ), 'package_addon_updated', 'audit exception retains committed success', [ 'audit_write_failed' ] );
		$wc = $this->wc_id( $p );
		$block = static fn( $check, $id, $value, $key ) => PackageAddonService::RELATION_META === $key ? false : $check;
		add_filter( 'update_post_metadata_by_mid', $block, 10, 4 );
		try { $row = $this->success( $this->service->update( $row['package_addon_id'], $row['fingerprint'], '23.55', 2, false ), 'package_addon_updated', 'projection write failure does not roll back Catalog', [ 'projection_reconciliation_required' ] ); }
		finally { remove_filter( 'update_post_metadata_by_mid', $block, 10 ); }
		$before_audit = $this->audits();
		$this->success( $this->service->reconcileProjection( $p ), 'package_addon_projection_synchronized', 'targeted retry repairs stale metadata' );
		$this->success( $this->service->reconcileProjection( $p ), 'package_addon_projection_unchanged', 'targeted retry idempotent' );
		$this->same( $before_audit, $this->audits(), 'reconciliation has no domain audit' );
		$this->same( 'none', $this->projection( $p ), 'targeted retry rederives zero enabled count' );
		$prior = $wpdb->suppress_errors( true );
		$filter = static fn( $sql ) => str_contains( $sql, 'meta_id, meta_key, meta_value' ) && str_contains( $sql, 'postmeta' ) ? 'INVALID addon_metadata_read_fault' : $sql;
		add_filter( 'query', $filter );
		try { $this->error( 'projection_reconciliation_required', $this->service->reconcileProjection( $p ), 'projection readback failure visible' ); }
		finally { remove_filter( 'query', $filter ); $wpdb->suppress_errors( $prior ); }
		$this->projection_convergence( $package, $p, $row );
	}

	private function transport(): void {
		global $wpdb;
		$p = $this->play(); $package = $this->package( [ $p ] );
		foreach ( [ 1205, 1213, 3572 ] as $errno ) {
			[ $result, $evidence ] = $this->observe( fn() => $this->service->create( $package, $p, '5.00' ), [ 'fault_errno' => $errno ] );
			$this->error( 'catalog_write_conflict', $result, 'recognized add-on conflict ' . $errno );
			$this->same( [ 3, 3, 0 ], array_slice( $evidence, 0, 3 ), 'exactly three attempts and rollbacks, no COMMIT' );
			$this->same( [ 'attempts' => 3, 'db_errno' => $errno, 'status' => 'catalog_write_conflict' ], $result->get_error_data(), 'conflict retry data preserved' );
		}
		[ $result, $evidence ] = $this->observe( fn() => $this->service->create( 2147483647, $p, '5.00' ) );
		$this->error( 'package_not_found', $result, 'locked missing parent semantic failure' );
		$this->same( [ 1, 1, 0 ], array_slice( $evidence, 0, 3 ), 'semantic failure never retries' );
		[ $result, $evidence ] = $this->observe( fn() => $this->service->create( $package, $p, '5.00' ) );
		$row = $this->success( $result, 'package_addon_created', 'fixed add-on profile create' );
		$this->same( [ 1, 0, 1 ], array_slice( $evidence, 0, 3 ), 'one confirmed create commit' );
		$locks = array_values( array_filter( $evidence[3], static fn( $sql ) => str_contains( $sql, 'FOR UPDATE NOWAIT' ) ) );
		$this->same( 3, count( $locks ), 'three ordered lock reads' );
		foreach ( [ 'bingo_packages', 'bingo_play_products', 'bingo_package_addons' ] as $index => $table ) { $this->same( true, str_contains( $locks[ $index ], $table ), 'lock rank ' . ( $index + 1 ) . ': ' . $table ); }
		[ $result, $evidence ] = $this->observe( fn() => $this->service->update( $row['package_addon_id'], str_repeat( 'a', 64 ), '5.00', 1, false ) );
		$this->error( 'package_addon_stale', $result, 'locked stale failure' );
		$this->same( [ 1, 1, 0 ], array_slice( $evidence, 0, 3 ), 'stale token is not retried' );
		[ $result, $evidence ] = $this->observe( fn() => $this->service->update( $row['package_addon_id'], $row['fingerprint'], '5.00', 1, true ) );
		$this->success( $result, 'package_addon_unchanged', 'locked update no-op' );
		$this->same( [], array_values( array_filter( $evidence[3], static fn( $sql ) => 1 === preg_match( '/\A(?:INSERT|UPDATE|DELETE) /', $sql ) ) ), 'no-op performs no DML, not even identical UPDATE' );
		[ $result, $evidence ] = $this->observe( fn() => $this->service->remove( $row['package_addon_id'], $row['fingerprint'] ), [ 'fault_errno' => 3572, 'fail_rollback' => true ] );
		$this->error( 'catalog_transaction_state_untrusted', $result, 'add-on rollback failure poisons session' );
		$this->same( 'catalog_statement_failed', $result->get_error_data()['original_code'], 'rollback failure original code retained' );
		$this->same( [ 1, 1, 0 ], array_slice( $evidence, 0, 3 ), 'rollback failure not retried' );
		foreach ( [ false, true ] as $really_committed ) {
			$q = $this->play(); $before = $this->audits();
			[ $result, $evidence ] = $this->observe( fn() => $this->service->create( $package, $q, '5.00' ), [ 'unknown_commit' => true, 'commit_on_unknown' => $really_committed ] );
			$this->error( 'catalog_commit_outcome_unknown', $result, 'ambiguous acknowledgement never reports success' );
			$this->same( [ 1, 1, 1 ], array_slice( $evidence, 0, 3 ), 'ambiguous COMMIT is not retried' );
			$this->same( $before, $this->audits(), 'ambiguous COMMIT emits no audit' );
			$this->same( '', $this->projection( $q ), 'ambiguous COMMIT performs no projection' );
			$this->same( $really_committed ? 1 : 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE package_id = %d AND bingo_play_product_id = %d', Migrations::package_addons_table(), $package, $q ) ), 'test-only durable outcome differs, returned error does not' );
		}
		$before_audit = $this->audits();
		$prior = $wpdb->suppress_errors( true );
		$filter = static fn( $sql ) => str_contains( $sql, 'bingo_package_addons' ) && str_contains( $sql, 'WHERE package_addon_id =' ) && str_starts_with( $sql, 'SELECT' ) ? 'INVALID addon_confirmation_fault' : $sql;
		add_filter( 'query', $filter );
		try {
			$q = $this->play();
			$this->success( $this->service->create( $package, $q, '5.00' ), 'package_addon_created', 'failed post-commit confirming read retains success', [ 'state_read_failed' ] );
		} finally { remove_filter( 'query', $filter ); $wpdb->suppress_errors( $prior ); }
	}

	/** Observe public operations; fault injection itself adds no production callback. */
	private function observe( callable $operation, array $faults = [] ): array {
		global $wpdb;
		$original = $wpdb->dbh;
		$connection = new AddonFixtureConnection( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$connection->set_charset( 'utf8mb4' );
		$connection->begin_transaction(); $connection->commit();
		$connection->begins = $connection->commits = $connection->rollbacks = 0;
		foreach ( $faults as $name => $value ) { $connection->$name = $value; }
		$wpdb->dbh = $connection;
		$hooks = 0;
		$listener = static function () use ( $connection, &$hooks ): void { if ( $connection->open ) { ++$hooks; } };
		add_action( 'wp_error_added', $listener ); add_action( 'is_wp_error_instance', $listener );
		try {
			$result = $operation();
			$evidence = [ $connection->begins, $connection->rollbacks, $connection->commits, $connection->statements ];
			$this->same( 0, $hooks, 'add-on profile dispatches neither WP error hook while open' );
			if ( $connection->unknown_commit || $connection->fail_rollback ) {
				$this->same( true, ( new ReflectionProperty( Transport::class, 'untrusted' ) )->getValue(), 'session poison remains set after uncertain boundary' );
			}
			return [ $result, $evidence ];
		} finally {
			remove_action( 'wp_error_added', $listener ); remove_action( 'is_wp_error_instance', $listener );
			$connection->fault_errno = 0; $connection->fail_rollback = false;
			$connection->rollback(); $wpdb->dbh = $original; $connection->close();
			( new ReflectionProperty( Transport::class, 'untrusted' ) )->setValue( null, false );
		}
	}

	private function authorization( int $a, int $b ): void {
		$p = $this->play(); $package = $this->package( [ $p ] );
		$row = $this->success( $this->service->create( $package, $p, '5.00' ), 'package_addon_created', 'authorization setup' );
		$operations = [ fn() => $this->service->listForPackage( $package ), fn() => $this->service->create( $package, $p, '5.00' ), fn() => $this->service->update( $row['package_addon_id'], $row['fingerprint'], '5.00', 1, true ), fn() => $this->service->remove( $row['package_addon_id'], $row['fingerprint'] ), fn() => $this->service->reconcileProjection( $p ) ];
		foreach ( [ 0 => 'authentication_required', $this->user( [ $a, $b ], Roles::GAME_OPERATOR ) => 'package_addon_management_denied', $this->user( [ $a ], 'subscriber' ) => 'package_addon_management_denied' ] as $actor => $code ) {
			wp_set_current_user( $actor );
			foreach ( $operations as $operation ) { $this->error( $code, $operation(), 'every public operation denies unauthenticated/unprivileged/Game Operator actor' ); }
		}
		wp_set_current_user( $this->admin );
		switch_to_blog( 1 );
		foreach ( $operations as $operation ) { $this->error( 'invalid_hall_context', $operation(), 'Super Admin needs explicit Hall context' ); }
		restore_current_blog();
		$this->owned( $a ); update_option( Migrations::VERSION_OPTION, 5 );
		foreach ( $operations as $operation ) { $this->error( 'catalog_schema_unavailable', $operation(), 'every public operation requires Catalog v6' ); }
		update_option( Migrations::VERSION_OPTION, 6 );
		$manager = $this->user( [ $a, $b ], Roles::HALL_MANAGER );
		wp_set_current_user( $manager );
		$this->same( true, current_user_can( 'bc_manage_products' ), 'native Hall Manager fixture has product capability' );
		$this->same( 1, count( $this->service->listForPackage( $package ) ), 'Hall Manager reads current-Hall relationships' );
		$this->noop( fn() => $this->service->create( $package, $p, '5.00' ), 'Hall Manager current-Hall writer allowed' );
		wp_set_current_user( $this->admin );
	}

	private function isolation( int $a, int $b ): void {
		global $wpdb;
		$this->owned( $b );
		// Both disposable Halls intentionally own the same numeric identities.
		$p = $this->play(); $package = $this->package( [ $p ] );
		$row = $this->success( $this->service->create( $package, $p, '5.00' ), 'package_addon_created', 'Hall A same-ID fixture' );
		$a_product = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE bingo_play_product_id = %d', Migrations::play_products_table(), $p ), ARRAY_A );
		$a_package = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE package_id = %d', Migrations::packages_table(), $package ), ARRAY_A );
		$a_addon = $this->relationship_rows( $package )[0];
		switch_to_blog( $b );
		$a_product['wc_product_id'] = $this->product();
		$a_package['wc_product_id'] = $this->product();
		$a_addon['unit_price'] = '99.00';
		$this->same( 1, $wpdb->insert( Migrations::play_products_table(), $a_product ), 'Hall B same numeric Play Product fixture' );
		$this->same( 1, $wpdb->insert( Migrations::packages_table(), $a_package ), 'Hall B same numeric Package fixture' );
		$this->same( 1, $wpdb->insert( Migrations::package_addons_table(), $a_addon ), 'Hall B same numeric relationship fixture' );
		$only_b = $this->play(); $only_b_package = $this->package( [ $only_b ] );
		$b_row = $this->success( $this->service->create( $only_b_package, $only_b, '10.29' ), 'package_addon_created', 'Hall B only identities' );
		$baseline = $this->snapshot( $b ); $b_audits = $this->audits();
		restore_current_blog();
		$_REQUEST['blog_id'] = $b; $_REQUEST['site_id'] = $b; $_REQUEST['hall_id'] = $b;
		try {
			$this->error( 'package_not_found', $this->service->listForPackage( $only_b_package ), 'Hall B-only Package absent in Hall A' );
			$this->error( 'play_product_not_found', $this->service->create( $package, $only_b, '5.00' ), 'Hall B-only Play Product absent in Hall A' );
			$this->error( 'package_addon_not_found', $this->service->update( $b_row['package_addon_id'], $b_row['fingerprint'], '1.00', 1, false ), 'Hall B-only relationship cannot be updated from A' );
			$this->error( 'package_addon_not_found', $this->service->remove( $b_row['package_addon_id'], $b_row['fingerprint'] ), 'Hall B-only relationship cannot be removed from A' );
			$this->error( 'play_product_not_found', $this->service->reconcileProjection( $only_b ), 'Hall B-only projection identity absent from A' );
			$this->success( $this->service->update( $row['package_addon_id'], $row['fingerprint'], '4.54', 2, true ), 'package_addon_updated', 'same numeric identity resolves only Hall A' );
		} finally { unset( $_REQUEST['blog_id'], $_REQUEST['site_id'], $_REQUEST['hall_id'] ); }
		$manager = $this->user( [ $a, $b ], Roles::HALL_MANAGER );
		wp_set_current_user( $manager );
		for ( $i = 0; $i < 4; ++$i ) {
			$this->same( '4.54', $this->service->listForPackage( $package )[0]['unit_price'], 'reused service follows Hall A after switching' );
			switch_to_blog( $b );
			$this->same( '99.00', $this->service->listForPackage( $package )[0]['unit_price'], 'same service reads Hall B only in B context' );
			restore_current_blog();
		}
		wp_set_current_user( $this->admin );
		$this->same( $baseline, $this->snapshot( $b ), 'Hall B remains byte-identical after forged requests and same-ID Hall A mutation' );
		switch_to_blog( $b ); $this->same( $b_audits, $this->audits(), 'Hall B audit unchanged by Hall A operations' ); restore_current_blog();
	}

	private function user( array $sites, string $role ): int {
		global $wpdb;
		foreach ( $sites as $site ) { $this->owned( $site ); }
		$login = 'addon-' . strtolower( wp_generate_password( 16, false, false ) );
		$id = wp_insert_user( [ 'user_login' => $login, 'user_pass' => wp_generate_password( 32 ), 'user_email' => $login . '@example.invalid', 'role' => '' ] );
		if ( $id instanceof WP_Error || $id < 1 ) { throw new RuntimeException( 'Owned user fixture creation failed.' ); }
		$this->users[ $id ] = true;
		update_user_meta( $id, self::OWNER, $this->token );
		// Match existing isolated suites: exact owned-user capability fixtures,
		// not a bypass or change to the production Hall Staff writer guard.
		foreach ( $sites as $site ) {
			$key = $wpdb->get_blog_prefix( $site ) . 'capabilities';
			$wpdb->delete( $wpdb->usermeta, [ 'user_id' => $id, 'meta_key' => $key ] );
			$wpdb->insert( $wpdb->usermeta, [ 'user_id' => $id, 'meta_key' => $key, 'meta_value' => serialize( [ $role => true ] ) ] );
		}
		clean_user_cache( $id );
		return (int) $id;
	}

	private function concurrency( int $a ): void {
		$p = $this->play(); $package = $this->package( [ $p ] );
		$codes = $this->race( [ [ 'create', [ $package, $p, '5.00' ] ], [ 'create', [ $package, $p, '5.00' ] ] ] );
		$this->same( true, in_array( 'package_addon_created', $codes, true ), 'concurrent identical creates include one confirmed creator' );
		$this->allowed( $codes, [ 'package_addon_created', 'package_addon_unchanged', 'catalog_write_conflict' ], 'identical pair serial outcomes' );
		$this->same( 1, count( $this->relationship_rows( $package ) ), 'concurrent pair creates exactly one authoritative row' );
		$row = $this->service->listForPackage( $package )[0];
		$codes = $this->race( [ [ 'update', [ $row['package_addon_id'], $row['fingerprint'], '4.54', 1, true ] ], [ 'update', [ $row['package_addon_id'], $row['fingerprint'], '23.55', 1, true ] ] ] );
		$this->same( 1, count( array_filter( $codes, static fn( $code ) => 'package_addon_updated' === $code ) ), 'two stale edits cannot both overwrite' );
		$this->allowed( $codes, [ 'package_addon_updated', 'package_addon_stale', 'catalog_write_conflict' ], 'update race serial outcomes' );
		foreach ( [ 'paper', 'digital' ] as $medium ) {
			$p = $this->play( $medium ); $base = $this->play( $medium ); $package = $this->package( [ $base ] );
			$codes = $this->race( [ [ 'create', [ $package, $p, '5.00' ] ], [ 'medium', [ $p, 'paper' === $medium ? 'digital' : 'paper', 'paper' === $medium ? $this->game_type : null ] ] ] );
			$this->allowed( $codes, [ 'package_addon_created', 'play_product_medium_changed', 'play_product_package_referenced', 'package_addon_medium_incompatible', 'catalog_write_conflict' ], 'add-on create versus medium transition ' . $medium );
			foreach ( $this->service->listForPackage( $package ) as $row ) { $this->same( true, $row['medium_compatible'], 'medium race never persists incompatible enabled relationship' ); }
		}
		$paper = $this->play(); $digital = $this->play( 'digital' ); $p = $this->play( 'digital' ); $package = $this->package( [ $digital ] );
		$row = $this->success( $this->service->create( $package, $p, '5.00' ), 'package_addon_created', 'composition race setup' );
		$row = $this->success( $this->service->update( $row['package_addon_id'], $row['fingerprint'], '5.00', 1, false ), 'package_addon_updated', 'composition race disabled reference' );
		$fp = ( new PackageService() )->compositionFingerprint( $package );
		$codes = $this->race( [ [ 'update', [ $row['package_addon_id'], $row['fingerprint'], '5.00', 1, true ] ], [ 'composition', [ $package, $fp, [ [ 'bingo_play_product_id' => $paper, 'quantity' => 1 ] ] ] ] ] );
		$this->allowed( $codes, [ 'package_addon_updated', 'package_composition_updated', 'package_addon_medium_incompatible', 'catalog_write_conflict' ], 'composition versus enabling serial outcomes' );
		$row = $this->service->listForPackage( $package )[0];
		$this->same( true, 0 === $row['enabled'] || $row['medium_compatible'], 'composition and enable cannot leave enabled Digital add-on on Paper' );
		foreach ( [ 'archive_package', 'archive_play' ] as $archive ) {
			$p = $this->play(); $package = $this->package( [ $p ] );
			$codes = $this->race( [ [ 'create', [ $package, $p, '5.00' ] ], [ $archive, [ 'archive_package' === $archive ? $package : $p ] ] ] );
			$this->allowed( $codes, [ 'package_addon_created', 'package_archived', 'play_product_archived', 'play_product_not_active', 'catalog_write_conflict' ], 'parent archive versus add-on create serial outcomes' );
			foreach ( $this->service->listForPackage( $package ) as $row ) { $this->same( [ 1, false ], [ $row['enabled'], $row['eligible'] ], 'archive race preserves committed row and enabled flag without eligibility' ); }
		}
		$p = $this->play(); $package = $this->package( [ $p ] );
		$row = $this->success( $this->service->create( $package, $p, '5.00' ), 'package_addon_created', 'real NOWAIT fixture' );
		foreach ( [ 'hold_package' => $package, 'hold_play' => $p, 'hold_addon' => $row['package_addon_id'] ] as $operation => $id ) {
			$worker = $this->start_worker( $operation, [ $id, 1500 ], 'LOCKED' );
			$start = microtime( true );
			$result = $this->service->update( $row['package_addon_id'], $row['fingerprint'], '6.00', 1, true );
			$this->error( 'catalog_write_conflict', $result, 'real NOWAIT conflict at ' . $operation );
			$this->same( true, microtime( true ) - $start < 1.0, 'bounded retries do not wait for server timeout' );
			$this->finish_worker( $worker );
		}
	}

	private function projection_convergence( int $package, int $p, array $row ): void {
		foreach ( [ 1, 3 ] as $changes ) {
			$seen = 0; $changed = 0; $busy = false;
			$filter = function ( $sql ) use ( $package, $p, $changes, &$seen, &$changed, &$busy ) {
				if ( $busy || ! str_contains( $sql, 'AS enabled_count' ) ) { return $sql; }
				++$seen;
				if ( 0 === $seen % 2 && $changed < $changes ) {
					$busy = true;
					try {
						$current = $this->service->listForPackage( $package )[0];
						$this->success( $this->service->update( $current['package_addon_id'], $current['fingerprint'], $current['unit_price'], $current['max_quantity'], 0 === $current['enabled'] ), 'package_addon_updated', 'conforming competing mutation during projection' );
						++$changed;
					} finally { $busy = false; }
				}
				return $sql;
			};
			add_filter( 'query', $filter );
			try { $result = $this->service->reconcileProjection( $p ); }
			finally { remove_filter( 'query', $filter ); }
			$this->same( $changes, $changed, 'projection barrier exercised exact competing changes' );
			if ( 1 === $changes ) {
				$this->same( true, $result instanceof PackageAddonResult, 'projection converges after competing enabled-count change' );
				$this->same( 4, $seen, 'convergence rereads authoritative count on second attempt' );
			} else {
				$this->error( 'projection_reconciliation_required', $result, 'continuously changing count exhausts bounded convergence' );
				$this->same( 6, $seen, 'convergence is bounded to three read/write/readback/count attempts' );
			}
			$this->same( $this->service->listForPackage( $package )[0]['enabled'] ? 'package_only_addon' : 'none', $this->projection( $p ), 'current projection remains derived from current Catalog state' );
		}
	}

	private function race( array $specifications ): array {
		$workers = [];
		foreach ( $specifications as [ $operation, $values ] ) { $workers[] = $this->start_worker( $operation, $values, 'READY' ); }
		foreach ( $workers as $worker ) { fwrite( $worker[1][0], "GO\n" ); fflush( $worker[1][0] ); }
		return array_map( fn( $worker ) => $this->finish_worker( $worker ), $workers );
	}
	private function start_worker( string $operation, array $values, string $ready ): array {
		$this->owned( get_current_blog_id() );
		$spec = [ 'site' => get_current_blog_id(), 'token' => $this->token, 'actor' => $this->admin, 'operation' => $operation, 'values' => $values ];
		$process = proc_open( [ PHP_BINARY, __DIR__ . '/package-addon-worker.php', json_encode( $spec, JSON_THROW_ON_ERROR ) ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Worker did not start.' ); }
		stream_set_timeout( $pipes[1], 20 );
		$line = trim( (string) fgets( $pipes[1] ) );
		if ( $line !== $ready ) { throw new RuntimeException( 'Worker not ready: ' . $line . stream_get_contents( $pipes[2] ) ); }
		return [ $process, $pipes ];
	}
	private function finish_worker( array $worker ): string {
		[ $process, $pipes ] = $worker;
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] ); $error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); fclose( $pipes[2] );
		$exit = proc_close( $process );
		$this->same( 0, $exit, 'concurrency worker exits cleanly' );
		if ( 0 !== $exit || 1 !== preg_match( '/OUTCOME ([a-z_]+)/', $output, $match ) ) { throw new RuntimeException( 'Worker failure: ' . $output . $error ); }
		if ( '' !== $error ) { echo 'DIAGNOSTIC worker: ' . $error; }
		echo 'EVIDENCE worker ' . trim( $output ) . "\n";
		return $match[1];
	}
	private function allowed( array $actual, array $allowed, string $label ): void {
		foreach ( $actual as $code ) { $this->same( true, in_array( $code, $allowed, true ), $label . ': ' . $code ); }
	}

	private function site(): int {
		$site = wp_insert_site( [ 'domain' => 'addon-' . strtolower( wp_generate_password( 16, false, false ) ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Owned add-on fixture', 'user_id' => $this->admin ] );
		if ( $site instanceof WP_Error || $site <= 4 ) { throw new RuntimeException( 'Disposable Hall creation failed.' ); }
		$this->sites[ (int) $site ] = true;
		update_site_meta( $site, self::OWNER, $this->token );
		return (int) $site;
	}
	private function owned( int $site ): void {
		global $wpdb;
		if ( ! isset( $this->sites[ $site ] ) || $site <= 4 || $this->token !== get_site_meta( $site, self::OWNER, true ) || $wpdb->get_blog_prefix( $site ) !== $wpdb->base_prefix . $site . '_' || ! in_array( DB_NAME, BC_FIXTURE_DB_ALLOWLIST, true ) || DB_NAME === BC_Fixture::$live_db || BC_FIXTURE_IDENTITY !== $wpdb->get_var( 'SELECT identity FROM bc_fixture_sentinel' ) ) { throw new RuntimeException( 'Fixture ownership guard refused operation.' ); }
	}
	private function product(): int {
		$this->owned( get_current_blog_id() );
		// Only the commerce shell is needed. No product save/AS scheduling or price API.
		$id = wp_insert_post( [ 'post_type' => 'product', 'post_title' => 'Owned add-on commerce shell', 'post_status' => 'publish' ], true );
		if ( $id instanceof WP_Error || $id < 1 ) { throw new RuntimeException( 'Product fixture failed.' ); }
		return (int) $id;
	}
	private function game_type(): int {
		global $wpdb;
		$this->owned( get_current_blog_id() );
		$now = current_time( 'mysql', true );
		$code = 'addon-' . wp_generate_password( 12, false, false );
		if ( 1 !== $wpdb->insert( Migrations::game_types_table(), [ 'code' => $code, 'name' => $code, 'local_name_key' => $code, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ] ) ) { throw new RuntimeException( 'Game Type fixture failed.' ); }
		return (int) $wpdb->insert_id;
	}
	private function play( string $medium = 'paper' ): int {
		$result = ( new BingoPlayProductService() )->create( $this->product(), $medium, 6, 'digital' === $medium ? $this->game_type : null );
		if ( $result instanceof WP_Error ) { throw new RuntimeException( 'Play fixture: ' . $result->get_error_code() ); }
		return (int) $result->play_product['bingo_play_product_id'];
	}
	private function package( array $ids ): int {
		$result = ( new PackageService() )->create( $this->product(), array_map( static fn( $id ) => [ 'bingo_play_product_id' => $id, 'quantity' => 1 ], $ids ) );
		if ( $result instanceof WP_Error ) { throw new RuntimeException( 'Package fixture: ' . $result->get_error_code() ); }
		return (int) $result->package['package_id'];
	}
	private function wc_id( int $p ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT wc_product_id FROM %i WHERE bingo_play_product_id = %d', Migrations::play_products_table(), $p ) );
	}
	private function projection( int $p ): string { return (string) get_post_meta( $this->wc_id( $p ), PackageAddonService::RELATION_META, true ); }
	private function relationship_rows( int $package ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE package_id = %d ORDER BY package_addon_id', Migrations::package_addons_table(), $package ), ARRAY_A );
	}
	private function base_rows( int $package ): array {
		global $wpdb;
		return [ $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE package_id = %d', Migrations::packages_table(), $package ), ARRAY_A ), $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE package_id = %d ORDER BY package_component_id', Migrations::package_components_table(), $package ), ARRAY_A ) ];
	}
	private function audits(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE blog_id = %d ORDER BY id', CoreMigrations::audit_log_table(), get_current_blog_id() ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Audit read failed.' ); }
		return array_column( $rows, null, 'id' );
	}
	private function noop( callable $operation, string $label ): void {
		$before = [ $this->snapshot( get_current_blog_id() ), $this->audits() ];
		$this->success( $operation(), 'package_addon_unchanged', $label );
		$this->same( $before, [ $this->snapshot( get_current_blog_id() ), $this->audits() ], $label . ': Hall rows, timestamps, metadata and audit byte-identical' );
	}
	private function success( mixed $result, string $status, string $label, array $warnings = [] ): array {
		$this->same( $status, $result instanceof PackageAddonResult ? $result->status : ( $result instanceof WP_Error ? $result->get_error_code() : get_debug_type( $result ) ), $label );
		if ( ! $result instanceof PackageAddonResult ) { throw new RuntimeException( 'Expected success: ' . $label ); }
		$this->same( $warnings, array_column( $result->warnings, 'code' ), $label . ': exact warnings' );
		return $result->package_addon;
	}
	private function error( string $code, mixed $result, string $label ): void { $this->same( $code, $result instanceof WP_Error ? $result->get_error_code() : ( $result->status ?? $result ), $label ); }
	private function same( mixed $expected, mixed $actual, string $label ): void {
		if ( $expected === $actual ) { ++$this->passed; echo "PASS {$label}\n"; }
		else { ++$this->failed; echo "FAIL {$label}: " . var_export( $actual, true ) . " (expected " . var_export( $expected, true ) . ")\n"; }
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
	private function cleanup(): void {
		global $wpdb;
		foreach ( array_keys( $this->users ) as $user ) {
			if ( $this->token !== get_user_meta( $user, self::OWNER, true ) || is_super_admin( $user ) ) { throw new RuntimeException( 'User ownership guard refused cleanup.' ); }
			foreach ( array_keys( $this->sites ) as $site ) {
				$this->owned( $site );
				$wpdb->delete( $wpdb->usermeta, [ 'user_id' => $user, 'meta_key' => $wpdb->get_blog_prefix( $site ) . 'capabilities' ] );
			}
			clean_user_cache( $user );
			if ( ! wpmu_delete_user( $user ) || false !== get_userdata( $user ) ) { throw new RuntimeException( 'Owned user cleanup failed.' ); }
			unset( $this->users[ $user ] );
		}
		foreach ( array_keys( $this->sites ) as $site ) {
			$this->owned( $site );
			if ( false === $wpdb->delete( CoreMigrations::audit_log_table(), [ 'blog_id' => $site ], [ '%d' ] ) ) { throw new RuntimeException( 'Owned Hall audit cleanup failed.' ); }
			foreach ( self::TABLES as $suffix ) {
				if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->get_blog_prefix( $site ) . $suffix ) ) ) { throw new RuntimeException( 'Owned table cleanup failed.' ); }
			}
			$deleted = wp_delete_site( $site );
			if ( $deleted instanceof WP_Error || null !== get_site( $site ) ) { throw new RuntimeException( 'Disposable site cleanup failed.' ); }
			$this->same( null, get_site( $site ), 'owned site deletion verified' );
			unset( $this->sites[ $site ] );
		}
	}
}
( new PackageAddonsBackendTest() )->run();
