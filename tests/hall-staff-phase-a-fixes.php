<?php

/**
 * CLI integration coverage for the Hall Staff Phase A precision fixes.
 *
 * Lives outside wp-content/ so it can never ship inside the deployable plugin.
 *
 * Run from the repository root:
 * BC_TEST_DB=bingocommerce_state_recon php tests/hall-staff-phase-a-fixes.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This integration test is CLI-only.\n" );
	exit( 1 );
}

// These legacy suites may run only on the existing isolated fixture.
if ( 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "Isolated Hall Staff fixture required; development bootstrap is forbidden.\n" );
	exit( 1 );
}
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
if ( DB_NAME !== BC_Fixture::$database || DB_NAME === BC_Fixture::$live_db
	|| DB_NAME !== 'bingocommerce_state_recon'
	|| DB_NAME !== $wpdb->get_var( $wpdb->prepare( 'SELECT DATABASE() WHERE %d = %d', 1, 1 ) ) ) {
	throw new RuntimeException( 'Refusing Hall Staff tests: isolated database binding is not proven.' );
}
if ( 1 !== (int) username_exists( 'fixture_admin' ) || ! is_super_admin( 1 ) ) {
	throw new RuntimeException( 'The isolated fixture must provide fixture_admin as actor 1 for these unchanged legacy cases.' );
}
require_once ABSPATH . 'wp-admin/includes/ms.php';

use BingoCommerce\Core\HallStaffAdmin;
use BingoCommerce\Core\HallStaffResult;
use BingoCommerce\Core\HallStaffService;
use BingoCommerce\Core\Roles;

final class HallStaffPhaseAFixTest {

	/** @var array<int,int> */
	private array $temporary_users = [];
	private int $assertions = 0;
	private int $baseline_manager = 0;
	private int $hall_three_fixture_manager = 0;

	public function run(): void {
		try {
			$this->prepare_fixture();
			$this->test_reserved_scope_fields();
			$this->test_assignment_retry_target_binding_and_override_audit();
			$this->test_writer_guard_pairs();
			$this->test_last_manager_override_and_concurrency();
			$this->test_legacy_super_admin_target_cleanup();
		} finally {
			$this->cleanup();
		}

		echo "PASS: {$this->assertions} Hall Staff Phase A fix assertions.\n";
	}

	/** Reproduce the original suite's Hall/actor fixtures without live identities. */
	private function prepare_fixture(): void {
		$managers = $this->manager_ids( 3 );
		if ( [] !== $managers ) {
			$fixture = get_user_by( 'login', 'bc_fixture_manager_3' );
			if ( ! $fixture instanceof WP_User || [ (int) $fixture->ID ] !== $managers || is_super_admin( $fixture->ID ) ) {
				throw new RuntimeException( 'Fixture Hall 3 has unexpected staff; no unrelated staff will be removed.' );
			}
			$this->hall_three_fixture_manager = (int) $fixture->ID;
			switch_to_blog( 3 );
			wp_set_current_user( 1 );
			$removed = HallStaffService::remove_staff( $fixture->ID );
			restore_current_blog();
			if ( ! $removed instanceof HallStaffResult ) {
				throw new RuntimeException( 'Could not prepare the isolated Hall 3 fixture.' );
			}
		}
		$this->baseline_manager = $this->create_user( 'baseline-manager' )->ID;
		// The existing hall_manager_id helper intentionally excludes test targets.
		array_pop( $this->temporary_users );
		switch_to_blog( 2 );
		wp_set_current_user( 1 );
		$result = HallStaffService::assign_existing_user( get_userdata( $this->baseline_manager )->user_email, Roles::HALL_MANAGER );
		restore_current_blog();
		if ( ! $result instanceof HallStaffResult ) {
			throw new RuntimeException( 'Could not prepare the isolated Hall Manager fixture.' );
		}
	}

	private function test_reserved_scope_fields(): void {
		switch_to_blog( 2 );
		wp_set_current_user( 1 );

		$base = [
			'bc_hall_staff_action' => 'assign',
			'email'                => 'invalid',
			'role'                 => Roles::GAME_OPERATOR,
		];

		foreach ( [ 'blog_id', 'actor_user_id', 'operation_id' ] as $field ) {
			$result = HallStaffAdmin::handle_request( $base + [ $field => 'forged' ] );
			$this->assert_error( 'unexpected_scope_field', $result, "reserved {$field}" );
		}

		$this->assert_error( 'invalid_email', HallStaffAdmin::handle_request( $base ), 'standard request reaches service' );
		restore_current_blog();
	}

	private function test_assignment_retry_target_binding_and_override_audit(): void {
		$first  = $this->create_user( 'retry-a' );
		$second = $this->create_user( 'retry-b' );

		switch_to_blog( 2 );
		wp_set_current_user( 1 );

		$assigned = HallStaffService::assign_existing_user( $first->user_email, Roles::GAME_OPERATOR );
		$this->assert_success( HallStaffResult::COMMITTED_VERIFIED, $assigned, 'Super Admin assignment' );
		$this->assert_same( false, $this->audit_override( $assigned->operation_id ), 'standard Super Admin assignment override' );

		$retry = HallStaffService::assign_existing_user( $first->user_email, Roles::GAME_OPERATOR, $assigned->retry_token );
		$this->assert_success( HallStaffResult::IDEMPOTENT_REPLAY_SUCCESS, $retry, 'matching assignment retry' );
		$this->assert_same( 1, $this->witness_count( $assigned->operation_id ), 'matching retry does not duplicate DML/audit' );

		$this->assert_error(
			'retry_intent_mismatch',
			HallStaffService::assign_existing_user( $second->user_email, Roles::GAME_OPERATOR, $assigned->retry_token ),
			'different existing email retry'
		);
		$this->assert_error(
			'retry_intent_mismatch',
			HallStaffService::assign_existing_user( 'malformed', Roles::GAME_OPERATOR, $assigned->retry_token ),
			'malformed email retry'
		);
		$this->assert_error(
			'retry_intent_mismatch',
			HallStaffService::assign_existing_user( '', Roles::GAME_OPERATOR, $assigned->retry_token ),
			'empty email retry'
		);

		$changed = HallStaffService::change_role( $first->ID, Roles::HALL_MANAGER );
		$this->assert_success( HallStaffResult::COMMITTED_VERIFIED, $changed, 'standard Super Admin GO to HM' );
		$this->assert_same( false, $this->audit_override( $changed->operation_id ), 'standard Super Admin role-change override' );

		$late_retry = HallStaffService::assign_existing_user( $first->user_email, Roles::GAME_OPERATOR, $assigned->retry_token );
		$this->assert_success( HallStaffResult::IDEMPOTENT_REPLAY_SUCCESS, $late_retry, 'witness-first retry after later role change' );

		wp_set_current_user( $this->hall_manager_id( 2 ) );
		$manager_assign = HallStaffService::assign_existing_user( $second->user_email, Roles::GAME_OPERATOR );
		$this->assert_success( HallStaffResult::COMMITTED_VERIFIED, $manager_assign, 'Hall Manager assignment' );
		$this->assert_same( false, $this->audit_override( $manager_assign->operation_id ), 'Hall Manager override flag' );

		wp_set_current_user( 1 );
		$hall_three = HallStaffService::assign_existing_user( $first->user_email, Roles::GAME_OPERATOR );
		$this->assert_error( 'role_change_required', $hall_three, 'current hall remains hall two' );
		restore_current_blog();

		switch_to_blog( 3 );
		wp_set_current_user( 1 );
		$other_hall = HallStaffService::assign_existing_user( $first->user_email, Roles::GAME_OPERATOR );
		$this->assert_success( HallStaffResult::COMMITTED_VERIFIED, $other_hall, 'multi-hall assignment' );
		restore_current_blog();

		switch_to_blog( 2 );
		wp_set_current_user( 1 );
		$removed = HallStaffService::remove_staff( $first->ID );
		$this->assert_success( HallStaffResult::COMMITTED_VERIFIED, $removed, 'hall-two removal' );
		$this->assert_same( false, $this->audit_override( $removed->operation_id ), 'non-last Super Admin removal override' );
		$this->assert_same( [ Roles::GAME_OPERATOR => true ], $this->capabilities( $first->ID, 3 ), 'other-hall membership preserved' );
		restore_current_blog();

		switch_to_blog( 3 );
		wp_set_current_user( 1 );
		$this->assert_success( HallStaffResult::COMMITTED_VERIFIED, HallStaffService::remove_staff( $first->ID ), 'other-hall cleanup' );
		restore_current_blog();

		switch_to_blog( 2 );
		wp_set_current_user( 1 );
		$this->assert_success( HallStaffResult::COMMITTED_VERIFIED, HallStaffService::remove_staff( $second->ID ), 'Hall Manager action target cleanup' );
		restore_current_blog();
	}

	private function test_writer_guard_pairs(): void {
		foreach ( [ Roles::HALL_MANAGER, Roles::GAME_OPERATOR ] as $role ) {
			$user = $this->create_user( 'set-role-' . $role );
			add_user_to_blog( 2, $user->ID, 'subscriber' );
			$before = $this->pair_rows( $user->ID, 2 );

			switch_to_blog( 2 );
			$wp_user = new WP_User( $user->ID, '', 2 );
			$wp_user->set_role( $role );
			$this->assert_same( $before, $this->pair_rows( $user->ID, 2 ), "WP_User::set_role({$role}) DB pair" );
			restore_current_blog();
		}

		$incoming = $this->create_user( 'incoming-pair' );
		switch_to_blog( 2 );
		global $wpdb;
		$capability_key = $wpdb->get_blog_prefix( 2 ) . 'capabilities';
		$level_key      = $wpdb->get_blog_prefix( 2 ) . 'user_level';
		$this->assert_same( false, add_user_meta( $incoming->ID, $capability_key, [ Roles::GAME_OPERATOR => true ], true ), 'protected capability add blocked' );
		$this->assert_same( false, add_user_meta( $incoming->ID, $level_key, '0', true ), 'companion level add blocked after capability block' );
		$this->assert_same( [], $this->pair_rows( $incoming->ID, 2 ), 'blocked incoming pair absent' );
		restore_current_blog();

		$protected = $this->create_user( 'protected-pair' );
		switch_to_blog( 2 );
		wp_set_current_user( 1 );
		$this->assert_success(
			HallStaffResult::COMMITTED_VERIFIED,
			HallStaffService::assign_existing_user( $protected->user_email, Roles::GAME_OPERATOR ),
			'protected pair fixture assignment'
		);
		$before = $this->pair_rows( $protected->ID, 2 );

		$this->assert_same( false, update_user_meta( $protected->ID, $capability_key, [ 'subscriber' => true ] ), 'capability update blocked' );
		$this->assert_same( $before, $this->pair_rows( $protected->ID, 2 ), 'capability update unchanged' );
		$this->assert_same( false, delete_user_meta( $protected->ID, $capability_key ), 'capability delete blocked' );
		$this->assert_same( $before, $this->pair_rows( $protected->ID, 2 ), 'capability delete unchanged' );
		$this->assert_same( false, update_user_meta( $protected->ID, $level_key, '9' ), 'user level update blocked' );
		$this->assert_same( $before, $this->pair_rows( $protected->ID, 2 ), 'user level update unchanged' );
		$this->assert_same( false, delete_user_meta( $protected->ID, $level_key ), 'user level delete blocked' );
		$this->assert_same( $before, $this->pair_rows( $protected->ID, 2 ), 'user level delete unchanged' );

		$ids = $this->pair_ids( $protected->ID, 2 );
		foreach ( $ids as $key => $meta_id ) {
			$this->assert_same( false, update_metadata_by_mid( 'user', $meta_id, 'changed' ), "{$key} by-mid update blocked" );
			$this->assert_same( $before, $this->pair_rows( $protected->ID, 2 ), "{$key} by-mid update unchanged" );
			$this->assert_same( false, delete_metadata_by_mid( 'user', $meta_id ), "{$key} by-mid delete blocked" );
			$this->assert_same( $before, $this->pair_rows( $protected->ID, 2 ), "{$key} by-mid delete unchanged" );
		}

		$this->assert_success( HallStaffResult::COMMITTED_VERIFIED, HallStaffService::remove_staff( $protected->ID ), 'protected pair cleanup' );
		restore_current_blog();
	}

	private function test_last_manager_override_and_concurrency(): void {
		$this->assert_same( [], $this->manager_ids( 3 ), 'hall three starts without Hall Managers' );
		$last = $this->create_user( 'last-manager' );

		switch_to_blog( 3 );
		wp_set_current_user( 1 );
		$assigned = HallStaffService::assign_existing_user( $last->user_email, Roles::HALL_MANAGER );
		$this->assert_success( HallStaffResult::COMMITTED_VERIFIED, $assigned, 'last-manager fixture assignment' );
		$this->assert_same( false, $this->audit_override( $assigned->operation_id ), 'ordinary HM assignment override' );
		$removed = HallStaffService::remove_staff( $last->ID );
		$this->assert_success( HallStaffResult::COMMITTED_VERIFIED, $removed, 'Super Admin removes last HM' );
		$this->assert_same( true, $this->audit_override( $removed->operation_id ), 'last-HM override flag' );
		restore_current_blog();

		$demoted = $this->create_user( 'last-manager-demotion' );
		switch_to_blog( 3 );
		wp_set_current_user( 1 );
		$this->assert_success( HallStaffResult::COMMITTED_VERIFIED, HallStaffService::assign_existing_user( $demoted->user_email, Roles::HALL_MANAGER ), 'last-manager demotion fixture' );
		$demotion = HallStaffService::change_role( $demoted->ID, Roles::GAME_OPERATOR );
		$this->assert_success( HallStaffResult::COMMITTED_VERIFIED, $demotion, 'Super Admin demotes last HM' );
		$this->assert_same( true, $this->audit_override( $demotion->operation_id ), 'last-HM demotion override flag' );
		$this->assert_success( HallStaffResult::COMMITTED_VERIFIED, HallStaffService::remove_staff( $demoted->ID ), 'demoted fixture cleanup' );
		restore_current_blog();

		$first  = $this->create_user( 'race-a' );
		$second = $this->create_user( 'race-b' );
		switch_to_blog( 3 );
		wp_set_current_user( 1 );
		$this->assert_success( HallStaffResult::COMMITTED_VERIFIED, HallStaffService::assign_existing_user( $first->user_email, Roles::HALL_MANAGER ), 'race HM A setup' );
		$this->assert_success( HallStaffResult::COMMITTED_VERIFIED, HallStaffService::assign_existing_user( $second->user_email, Roles::HALL_MANAGER ), 'race HM B setup' );
		restore_current_blog();

		$results = $this->run_removal_race( $first->ID, $second->ID );
		sort( $results );
		$this->assert_same( [ 'COMMITTED_VERIFIED', 'ERROR:actor_not_authorized' ], $results, 'concurrent HM removals serialize' );
		$this->assert_same( 1, count( array_intersect( [ $first->ID, $second->ID ], $this->manager_ids( 3 ) ) ), 'one raced HM remains' );

		switch_to_blog( 3 );
		wp_set_current_user( 1 );
		foreach ( [ $first->ID, $second->ID ] as $user_id ) {
			if ( [ Roles::HALL_MANAGER => true ] === $this->capabilities( $user_id, 3 ) ) {
				$this->assert_success( HallStaffResult::COMMITTED_VERIFIED, HallStaffService::remove_staff( $user_id ), 'race cleanup' );
			}
		}
		restore_current_blog();
	}

	private function test_legacy_super_admin_target_cleanup(): void {
		$target = $this->create_user( 'legacy-super' );
		grant_super_admin( $target->ID );

		switch_to_blog( 4 );
		global $wpdb;
		$capability_key = $wpdb->get_blog_prefix( 4 ) . 'capabilities';
		$level_key      = $wpdb->get_blog_prefix( 4 ) . 'user_level';
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value) VALUES (%d, %s, %s)",
				$target->ID,
				$capability_key,
				serialize( [ Roles::HALL_MANAGER => true ] )
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value) VALUES (%d, %s, %s)",
				$target->ID,
				$level_key,
				'9'
			)
		);
		clean_user_cache( $target->ID );

		wp_set_current_user( 1 );
		$this->assert_error( 'super_admin_target_denied', HallStaffService::change_role( $target->ID, Roles::GAME_OPERATOR ), 'legacy Super Admin target role change denied' );
		$removed = HallStaffService::remove_staff( $target->ID );
		$this->assert_success( HallStaffResult::COMMITTED_VERIFIED, $removed, 'legacy Super Admin target cleanup allowed' );
		$this->assert_same( true, $this->audit_override( $removed->operation_id ), 'legacy Super Admin target cleanup override' );
		restore_current_blog();

		revoke_super_admin( $target->ID );
	}

	/** @return array<int,string> */
	private function run_removal_race( int $first, int $second ): array {
		$root  = dirname( __DIR__ );
		$start = microtime( true ) + 1.0;
		$code  = <<<'PHP'
if ('bingocommerce_state_recon' !== getenv('BC_TEST_DB')) { exit(1); }
putenv('BC_FIXTURE_WOOCOMMERCE=1');
require $argv[1] . '/tests/fixtures/wordpress-bootstrap.php';
if (DB_NAME !== 'bingocommerce_state_recon' || DB_NAME !== BC_Fixture::$database || DB_NAME === BC_Fixture::$live_db || DB_NAME !== $wpdb->get_var($wpdb->prepare('SELECT DATABASE() WHERE %d = %d', 1, 1))) { exit(1); }
switch_to_blog(3); wp_set_current_user((int) $argv[2]); while (microtime(true) < (float) $argv[4]) { usleep(1000); } $result = \BingoCommerce\Core\HallStaffService::remove_staff((int) $argv[3]); echo is_wp_error($result) ? 'ERROR:' . $result->get_error_code() : $result->status;
PHP;
		$specification = [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ];
		$processes = [
			proc_open( [ PHP_BINARY, '-r', $code, $root, (string) $first, (string) $second, (string) $start ], $specification, $first_pipes, $root ),
			proc_open( [ PHP_BINARY, '-r', $code, $root, (string) $second, (string) $first, (string) $start ], $specification, $second_pipes, $root ),
		];
		$output = [];

		foreach ( [ $first_pipes, $second_pipes ] as $index => $pipes ) {
			$output[] = trim( stream_get_contents( $pipes[1] ) );
			$error    = trim( stream_get_contents( $pipes[2] ) );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			$exit = proc_close( $processes[ $index ] );
			$this->assert_same( 0, $exit, 'race child exit' );
			$this->assert_same( '', $error, 'race child stderr' );
		}

		return $output;
	}

	private function create_user( string $label ): WP_User {
		$suffix  = str_replace( '.', '', uniqid( '', true ) );
		$user_id = wpmu_create_user(
			'bc_fix_' . sanitize_key( $label ) . '_' . $suffix,
			wp_generate_password( 24, true, true ),
			'bc-fix-' . sanitize_key( $label ) . '-' . $suffix . '@example.test'
		);

		if ( ! $user_id ) {
			throw new RuntimeException( "Could not create {$label} fixture user." );
		}

		$this->temporary_users[] = (int) $user_id;
		return get_userdata( $user_id );
	}

	private function cleanup(): void {
		global $wpdb;
		wp_set_current_user( 1 );

		if ( $this->baseline_manager > 0 ) {
			$this->temporary_users[] = $this->baseline_manager;
		}

		foreach ( array_reverse( $this->temporary_users ) as $user_id ) {
			if ( is_super_admin( $user_id ) ) {
				revoke_super_admin( $user_id );
			}

			foreach ( [ 2, 3, 4 ] as $blog_id ) {
				$prefix = $wpdb->get_blog_prefix( $blog_id );
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key IN (%s, %s)",
						$user_id,
						$prefix . 'capabilities',
						$prefix . 'user_level'
					)
				);
			}

			clean_user_cache( $user_id );
			wpmu_delete_user( $user_id );
		}
		if ( $this->hall_three_fixture_manager > 0 ) {
			switch_to_blog( 3 );
			wp_set_current_user( 1 );
			$fixture = get_userdata( $this->hall_three_fixture_manager );
			if ( [] === $this->capabilities( $fixture->ID, 3 ) ) {
				$restored = HallStaffService::assign_existing_user( $fixture->user_email, Roles::HALL_MANAGER );
				if ( ! $restored instanceof HallStaffResult ) {
					throw new RuntimeException( 'Could not restore the isolated Hall 3 fixture manager.' );
				}
			}
			restore_current_blog();
		}
	}

	private function hall_manager_id( int $blog_id ): int {
		$ids = $this->manager_ids( $blog_id );
		foreach ( $ids as $id ) {
			if ( ! in_array( $id, $this->temporary_users, true ) && ! is_super_admin( $id ) ) {
				return $id;
			}
		}

		throw new RuntimeException( "Hall {$blog_id} needs an existing Hall Manager fixture." );
	}

	/** @return array<int,int> */
	private function manager_ids( int $blog_id ): array {
		return array_map(
			'intval',
			get_users( [ 'blog_id' => $blog_id, 'role' => Roles::HALL_MANAGER, 'fields' => 'ids' ] )
		);
	}

	/** @return array<string,bool> */
	private function capabilities( int $user_id, int $blog_id ): array {
		global $wpdb;
		return (array) get_user_meta( $user_id, $wpdb->get_blog_prefix( $blog_id ) . 'capabilities', true );
	}

	/** @return array<int,array<string,string>> */
	private function pair_rows( int $user_id, int $blog_id ): array {
		global $wpdb;
		$prefix = $wpdb->get_blog_prefix( $blog_id );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key IN (%s, %s) ORDER BY meta_key, umeta_id",
				$user_id,
				$prefix . 'capabilities',
				$prefix . 'user_level'
			),
			ARRAY_A
		);
	}

	/** @return array<string,int> */
	private function pair_ids( int $user_id, int $blog_id ): array {
		global $wpdb;
		$prefix = $wpdb->get_blog_prefix( $blog_id );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT umeta_id, meta_key FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key IN (%s, %s)",
				$user_id,
				$prefix . 'capabilities',
				$prefix . 'user_level'
			),
			ARRAY_A
		);
		$ids = [];
		foreach ( $rows as $row ) {
			$ids[ str_ends_with( $row['meta_key'], 'capabilities' ) ? 'capabilities' : 'user_level' ] = (int) $row['umeta_id'];
		}

		return $ids;
	}

	private function audit_override( string $operation_id ): bool {
		global $wpdb;
		$json = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT context FROM {$wpdb->base_prefix}audit_log WHERE operation_id = %s",
				$operation_id
			)
		);
		$context = json_decode( (string) $json, true );
		return (bool) ( $context['safeguard_override'] ?? null );
	}

	private function witness_count( string $operation_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->base_prefix}audit_log WHERE operation_id = %s",
				$operation_id
			)
		);
	}

	/** @param mixed $actual */
	private function assert_error( string $expected, $actual, string $label ): void {
		++$this->assertions;
		if ( ! is_wp_error( $actual ) || $expected !== $actual->get_error_code() ) {
			throw new RuntimeException( "{$label}: expected error {$expected}." );
		}
	}

	/** @param mixed $actual */
	private function assert_success( string $expected, $actual, string $label ): void {
		++$this->assertions;
		if ( ! $actual instanceof HallStaffResult || $expected !== $actual->status ) {
			$detail = is_wp_error( $actual ) ? $actual->get_error_code() : get_debug_type( $actual );
			throw new RuntimeException( "{$label}: expected {$expected}, received {$detail}." );
		}
	}

	/** @param mixed $expected @param mixed $actual */
	private function assert_same( $expected, $actual, string $label ): void {
		++$this->assertions;
		if ( $expected !== $actual ) {
			throw new RuntimeException(
				"{$label}: expected " . wp_json_encode( $expected ) . ', received ' . wp_json_encode( $actual ) . '.'
			);
		}
	}
}

try {
	( new HallStaffPhaseAFixTest() )->run();
} catch ( Throwable $throwable ) {
	fwrite( STDERR, 'FAIL: ' . $throwable->getMessage() . "\n" );
	exit( 1 );
}
