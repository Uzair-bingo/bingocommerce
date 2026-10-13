<?php
/** ADR-048 Phase 2. All writes and failure injection are fixture-only. */
if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "Use BC_TEST_DB=bingocommerce_state_recon.\n" );
	exit( 1 );
}
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
if ( DB_NAME !== BC_Fixture::$database || DB_NAME === BC_Fixture::$live_db || DB_NAME !== 'bingocommerce_state_recon'
	|| DB_NAME !== $wpdb->get_var( $wpdb->prepare( 'SELECT DATABASE() WHERE %d = %d', 1, 1 ) ) ) {
	throw new RuntimeException( 'Refusing tests: isolated database binding is not proven.' );
}

use BingoCommerce\Core\AuditLog;
use BingoCommerce\Core\HallStaffAdmin;
use BingoCommerce\Core\HallStaffIdentity;
use BingoCommerce\Core\HallStaffIntent;
use BingoCommerce\Core\HallStaffInvitation;
use BingoCommerce\Core\HallStaffProvisioningContext;
use BingoCommerce\Core\HallStaffProvisioningService as Provisioning;
use BingoCommerce\Core\HallStaffResult;
use BingoCommerce\Core\HallStaffRetryToken;
use BingoCommerce\Core\HallStaffService as Staff;
use BingoCommerce\Core\HallStaffSsoCreationIntent;
use BingoCommerce\Core\HallStaffSsoGrant as Grant;
use BingoCommerce\Core\HallStaffTransactionTransport as Transport;
use BingoCommerce\Core\Migrations;
use BingoCommerce\Core\Roles;
use BingoCommerce\PortalIntegration\SsoAccountGuard as Guard;

final class HallStaffSsoTest {
	private int $assertions = 0;
	private array $users = [];
	private int $mails = 0;
	private int $admin;
	private string $tag;

	public function run(): void {
		$this->admin = (int) username_exists( 'fixture_admin' );
		$this->ok( $this->admin > 0 && is_super_admin( $this->admin ), 'isolated administrator' );
		$this->tag = bin2hex( random_bytes( 4 ) );
		add_filter( 'pre_wp_mail', function () { ++$this->mails; return true; } );
		switch_to_blog( 2 );
		try {
			$this->matcher();
			$this->creation_audit();
			$this->grant_and_protection();
			$this->failures_and_concurrency();
			foreach ( [ 'core-only', 'missing-core' ] as $mode ) {
				$result = $this->workers( [ [ 'mode' => $mode ] ] )[0];
				$this->ok( 'PASS' === $result, $mode . ' dependency boundary' );
			}
			$this->ok( 0 === $this->mails, 'no SSO invitation or welcome mail' );
		} finally {
			$this->cleanup();
			while ( ms_is_switched() ) { restore_current_blog(); }
		}
		echo "PASS: {$this->assertions} Hall Staff SSO assertions.\n";
	}

	private function matcher(): void {
		foreach ( [ 'portal-a-1', 'PORTAL-Ab12-987', 'portal-abcdefghijklmnopqrst-1' ] as $login ) {
			$this->ok( HallStaffIdentity::is_portal_sso_username( $login ) && Guard::is_reserved_username( $login ), 'shared matcher accepts ' . $login );
		}
		foreach ( [ 'portal-mike', 'portal-a-0', 'portal-a-01', 'portal--1', 'portal-a!-1', 'portal-abcdefghijklmnopqrstu-1', "portal-a-1\n", 'ordinary' ] as $login ) {
			$this->ok( ! HallStaffIdentity::is_portal_sso_username( $login ) && ! Guard::is_reserved_username( $login ), 'shared matcher excludes ordinary/malformed username' );
		}
		$this->ok( ( new ReflectionMethod( Grant::class, '__construct' ) )->isPrivate(), 'only the dedicated entry constructs SSO requests' );
	}

	private function creation_audit(): void {
		global $wpdb;
		$id = $this->user();
		wp_set_current_user( 0 );
		$op = wp_generate_uuid4();
		$reference = 'creation-' . $this->tag;
		$marker_seen = false;
		$observe = static function ( $sql ) use ( $id, &$marker_seen ) {
			if ( str_starts_with( $sql, 'INSERT INTO `' . Migrations::audit_log_table() . '`' ) && str_contains( $sql, 'hall_staff.user_created' ) ) {
				$marker_seen = 'portal_sso' === get_user_meta( $id, 'bc_identity_type', true );
			}
			return $sql;
		};
		add_filter( 'query', $observe );
		$creation = $this->audit( $id, $op, $reference );
		remove_filter( 'query', $observe );
		$this->ok( $creation instanceof HallStaffSsoCreationIntent, 'creation audit accepts no signed-in actor' );
		$this->ok( $marker_seen, 'portal_sso marker exists before audit INSERT' );
		$witness = AuditLog::find_operation_witness( $op );
		$this->ok( $creation->matches_witness( $witness ), 'creation audit exactly matches immutable intent' );
		$this->ok( null === $witness['actor_user_id'] && 'portal_sso' === json_decode( $witness['context'], true )['source'], 'creation records system source and null actor' );
		$this->ok( get_userdata( $id )->user_email === json_decode( $witness['new_value'], true )['email'], 'creation records account own email' );
		$this->ok( [] === $this->roles( $id, 2 ), 'audit does not grant a role' );
		switch_to_blog( 3 );
		wp_set_current_user( $this->admin );
		$retry = $this->audit( $id, $op, $reference );
		$this->ok( $retry instanceof HallStaffSsoCreationIntent && $retry->blog_id === 2 && null === $retry->actor_user_id, 'retry from another Hall preserves original Hall and null actor' );
		$this->ok( 1 === $this->witness_count( $op ), 'creation retry has one audit record' );
		restore_current_blog();
		$this->error( Provisioning::IDENTITY_CONFLICT, $this->audit( $id, $op, 'different-reference' ), 'changed creation reference refused' );
		$this->error( Provisioning::IDENTITY_CONFLICT, Provisioning::record_portal_sso_creation( $id, 3, get_userdata( $id )->user_login, $op, $reference ), 'changed creation Hall refused' );
		$this->error( 'target_not_eligible', Provisioning::record_portal_sso_creation( $id, 2, 'portal-another-1', wp_generate_uuid4(), $reference ), 'wrong planned username refused' );
		$this->error( 'invalid_sso_creation', Provisioning::record_portal_sso_creation( $id, 1, get_userdata( $id )->user_login, $op, $reference ), 'main site creation attribution refused' );
		$normal = $this->user( false );
		$this->error( 'invalid_sso_creation', $this->audit( $normal ), 'ordinary account refused by SSO audit entry' );
		$conflict = $this->user();
		add_user_meta( $conflict, 'bc_identity_type', 'staff_operational' );
		$this->error( Provisioning::PROVISIONING_INCOMPLETE, $this->audit( $conflict ), 'human provenance not overwritten' );
		$blocked = $this->user();
		$deny_marker = static fn( $pre, $user, $key ) => $user === $blocked && 'bc_identity_type' === $key ? false : $pre;
		add_filter( 'add_user_metadata', $deny_marker, 10, 3 );
		$blocked_op = wp_generate_uuid4();
		$this->error( Provisioning::PROVISIONING_INCOMPLETE, $this->audit( $blocked, $blocked_op ), 'marker write failure fails closed' );
		remove_filter( 'add_user_metadata', $deny_marker, 10 );
		$this->ok( 0 === $this->witness_count( $blocked_op ), 'no audit claims missing marker' );
		$unrecorded = $this->user();
		$failed_op = wp_generate_uuid4();
		$fail_audit = static fn( $sql ) => str_starts_with( $sql, 'INSERT INTO `' . Migrations::audit_log_table() . '`' ) ? 'SELECT * FROM bc_sso_missing_audit_fixture' : $sql;
		$old_errors = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail_audit );
		$failed = $this->audit( $unrecorded, $failed_op, 'retry-original' );
		remove_filter( 'query', $fail_audit );
		$wpdb->suppress_errors( $old_errors );
		$this->error( Provisioning::AUDIT_UNRECORDED, $failed, 'creation audit write failure' );
		$this->ok( ! array_key_exists( 'creation_retry_token', $failed->get_error_data() ), 'SSO failure exposes no human creation token' );
		$this->ok( $this->audit( $unrecorded, $failed_op, 'retry-original' ) instanceof HallStaffSsoCreationIntent, 'same creation operation recovers after write failure' );
		$this->ok( 1 === $this->witness_count( $failed_op ), 'recovery writes one creation audit' );
		$ctx = new HallStaffProvisioningContext( $id, get_current_network_id(), 2, $this->admin, Roles::HALL_MANAGER );
		HallStaffInvitation::maybe_send_automatic( $ctx, $id, HallStaffResult::COMMITTED_VERIFIED );
		$this->ok( 0 === $this->mails, 'portal_sso provenance cannot trigger automatic invitation' );
	}

	private function grant_and_protection(): void {
		global $wpdb;
		$id = $this->user();
		$creation = $this->audit( $id );
		wp_set_current_user( 0 );
		$grant = Grant::grant( $creation, 2, 'login-' . $this->tag );
		$this->success( HallStaffResult::COMMITTED_VERIFIED, $grant, 'unsigned-in system grant' );
		$this->ok( '' === $grant->retry_token, 'SSO creates no human assignment token' );
		$this->ok( [ serialize( [ Roles::HALL_MANAGER => true ] ) ] === $this->roles( $id, 2 ) && [] === $this->roles( $id, 3 ), 'grant affects only current Hall' );
		$this->ok( in_array( Roles::HALL_MANAGER, get_userdata( $id )->roles, true ), 'target cache refreshed after commit' );
		$witness = AuditLog::find_operation_witness( $grant->operation_id );
		$this->ok( null === $witness['actor_user_id'] && 'hall_staff.assigned' === $witness['action'], 'fixed system actor and assignment audit' );
		$this->ok( 'portal_sso' === json_decode( $witness['context'], true )['source'] && $creation->operation_id !== $grant->operation_id, 'grant uses source and distinct mandatory operation ID' );
		$this->success( HallStaffResult::IDEMPOTENT_REPLAY_SUCCESS, Grant::grant( $creation, 2, 'login-' . $this->tag, $grant->operation_id ), 'same grant reconciles from audit' );
		$this->error( 'operation_identity_conflict', Grant::grant( $creation, 2, 'other-reference', $grant->operation_id ), 'grant operation cannot change reference' );
		$this->error( 'state_conflict', Grant::grant( $creation, 2, 'new-login' ), 'fresh grant cannot replace existing role' );
		$this->error( 'invalid_hall_context', Grant::grant( $creation, 3, 'wrong-hall' ), 'caller cannot name another Hall' );
		switch_to_blog( 1 );
		$this->error( 'invalid_hall_context', Grant::grant( $creation, 1, 'main-site' ), 'main site grant refused' );
		restore_current_blog();
		switch_to_blog( 3 );
		$this->success( HallStaffResult::COMMITTED_VERIFIED, Grant::grant( $creation, 3, 'hall-b' ), 'same creation witness permits separately verified current Hall grant' );
		restore_current_blog();

		$manager = $this->user( false );
		wp_set_current_user( $this->admin );
		$this->success( HallStaffResult::COMMITTED_VERIFIED, Staff::assign_existing_user( get_userdata( $manager )->user_email, Roles::HALL_MANAGER ), 'ordinary manager fixture' );
		wp_set_current_user( $manager );
		$this->error( 'managed_by_portal', Staff::change_role( $id, Roles::GAME_OPERATOR ), 'HM cannot change Portal role' );
		$this->error( 'managed_by_portal', Staff::remove_staff( $id ), 'HM cannot remove Portal role' );
		$unassigned = $this->user();
		$this->error( 'managed_by_portal', Staff::assign_existing_user( get_userdata( $unassigned )->user_email, Roles::HALL_MANAGER ), 'HM cannot add SSO identity by email' );
		$this->error( 'managed_by_portal', Provisioning::create_and_assign( get_userdata( $unassigned )->user_email, 'Existing SSO', Roles::GAME_OPERATOR ), 'Create staff existing-email branch also refuses SSO' );
		$issued = time();
		$human = new HallStaffIntent( wp_generate_uuid4(), get_current_network_id(), 2, $manager, $id, 'hall_staff.role_changed', Roles::HALL_MANAGER, Roles::GAME_OPERATOR, false, $issued, $issued + HallStaffRetryToken::LIFETIME );
		$this->error( 'managed_by_portal', Staff::change_role( $id, Roles::GAME_OPERATOR, HallStaffRetryToken::issue( $human ) ), 'human retry token cannot bypass protection' );
		$event = [ 'blog_id' => 2, 'actor_user_id' => $manager, 'action' => $human->action, 'target_type' => 'user', 'target_id' => $id, 'previous_value' => $human->previous_value(), 'new_value' => $human->new_value(), 'context' => $human->audit_context() ];
		$this->error( 'managed_by_portal', Transport::execute( $human, $event, false ), 'transport also protects authoritative target' );
		$staff = Staff::list_current_hall_staff();
		$row = array_values( array_filter( $staff, static fn( $row ) => $row['id'] === $id ) )[0];
		$this->ok( true === $row['managed_by_portal'], 'staff list includes Portal label' );
		ob_start(); HallStaffAdmin::render(); $html = ob_get_clean();
		preg_match( '~<tr>\s*<td>[^<]*(?:(?!</tr>).)*' . preg_quote( get_userdata( $id )->user_email, '~' ) . '(?:(?!</tr>).)*</tr>~s', $html, $match );
		$this->ok( isset( $match[0] ) && str_contains( $match[0], 'Managed by Portal' ) && ! str_contains( $match[0], '<form' ), 'HM screen hides Portal role actions' );
		$ordinary = $this->user( false );
		$this->success( HallStaffResult::COMMITTED_VERIFIED, Staff::assign_existing_user( get_userdata( $ordinary )->user_email, Roles::GAME_OPERATOR ), 'HM still assigns ordinary staff' );
		$this->success( HallStaffResult::COMMITTED_VERIFIED, Staff::change_role( $ordinary, Roles::HALL_MANAGER ), 'HM still changes ordinary staff' );
		$this->success( HallStaffResult::COMMITTED_VERIFIED, Staff::remove_staff( $ordinary ), 'HM still removes ordinary staff' );
		wp_set_current_user( $this->admin );
		$this->success( HallStaffResult::COMMITTED_VERIFIED, Staff::change_role( $id, Roles::GAME_OPERATOR ), 'Platform Admin can change SSO role' );
		$this->success( HallStaffResult::IDEMPOTENT_REPLAY_SUCCESS, Grant::grant( $creation, 2, 'login-' . $this->tag, $grant->operation_id ), 'replay reports original commit without restoring later removed HM role' );
		$this->ok( [ serialize( [ Roles::GAME_OPERATOR => true ] ) ] === $this->roles( $id, 2 ), 'replay leaves subsequent role unchanged' );
		$this->error( 'state_conflict', Grant::grant( $creation, 2, 'new-login-after-demotion' ), 'SSO never changes GO back to HM' );
		$this->success( HallStaffResult::COMMITTED_VERIFIED, Staff::remove_staff( $id ), 'Platform Admin can remove SSO role' );
		$this->success( HallStaffResult::COMMITTED_VERIFIED, Staff::assign_existing_user( get_userdata( $id )->user_email, Roles::HALL_MANAGER ), 'Platform Admin can add SSO identity' );
	}

	private function failures_and_concurrency(): void {
		global $wpdb;
		$id = $this->user();
		$missing = new HallStaffSsoCreationIntent( wp_generate_uuid4(), get_current_network_id(), 2, $id, get_userdata( $id )->user_email, get_userdata( $id )->user_login, 'no-witness' );
		$this->error( 'sso_creation_audit_required', Grant::grant( $missing, 2, 'login' ), 'missing creation audit refuses grant' );
		$creation = $this->audit( $id );
		grant_super_admin( $id );
		try {
			$this->error( 'target_not_eligible', Grant::grant( $creation, 2, 'super-admin' ), 'Network Super Admin target refused' );
			$this->error( 'target_not_eligible', $this->audit( $id ), 'SSO creation audit refuses Network Super Admin' );
		} finally { revoke_super_admin( $id ); }
		foreach ( [ serialize( [ 'customer' => true ] ), serialize( [] ), 'broken' ] as $caps ) {
			$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (user_id,meta_key,meta_value) VALUES (%d,%s,%s)', $wpdb->usermeta, $id, $wpdb->get_blog_prefix( 3 ) . 'capabilities', $caps ) );
			$this->error( 'non_operational_identity_not_eligible', Grant::grant( $creation, 2, 'ineligible' ), 'BR-036 refuses incompatible network membership' );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d AND meta_key = %s', $wpdb->usermeta, $id, $wpdb->get_blog_prefix( 3 ) . 'capabilities' ) );
			clean_user_cache( $id );
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET user_login = %s WHERE ID = %d', $wpdb->users, 'renamed-' . $this->tag, $id ) );
		$this->error( 'target_not_eligible', Grant::grant( $creation, 2, 'renamed' ), 'replaced or renamed linked identity refused' );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET user_login = %s WHERE ID = %d', $wpdb->users, $creation->user_login, $id ) );
		clean_user_cache( $id );

		$trigger = 'bc_sso_grant_' . $this->tag;
		$operation = wp_generate_uuid4();
		$created = $wpdb->query( $wpdb->prepare(
			'CREATE TRIGGER %i BEFORE INSERT ON %i FOR EACH ROW BEGIN IF NEW.action = %s THEN SIGNAL SQLSTATE %s SET MESSAGE_TEXT = %s; END IF; END',
			$trigger, Migrations::audit_log_table(), 'hall_staff.assigned', '45000', 'SSO fixture audit failure'
		) );
		$this->ok( false !== $created, 'isolated mandatory-audit failure trigger installed' );
		try {
			$failed = Grant::grant( $creation, 2, 'failure-retry', $operation );
			$this->ok( is_wp_error( $failed ) && Transport::NOT_COMMITTED_VERIFIED === $failed->get_error_data()['status'], 'failed mandatory audit reconciles rollback' );
			$this->ok( [] === $this->roles( $id, 2 ) && 0 === $this->witness_count( $operation ), 'audit failure rolls back role and audit together' );
		} finally { $wpdb->query( $wpdb->prepare( 'DROP TRIGGER %i', $trigger ) ); }
		$this->success( HallStaffResult::COMMITTED_VERIFIED, Grant::grant( $creation, 2, 'failure-retry', $operation ), 'retry commits same grant after audit restored' );
		$this->ok( 1 === $this->witness_count( $operation ), 'one grant audit after retry' );

		$race_id = $this->user();
		$race_creation = $this->audit( $race_id );
		$job = [ 'mode' => 'grant', 'user_id' => $race_id, 'creation_operation_id' => $race_creation->operation_id, 'creation_sso_reference' => $race_creation->creation_sso_reference, 'start' => microtime( true ) + 2 ];
		$results = $this->workers( [ $job, $job ] );
		sort( $results );
		$this->ok( [ HallStaffResult::COMMITTED_VERIFIED, 'state_conflict' ] === $results, 'simultaneous grants serialize to one mutation' );
		$this->ok( [ serialize( [ Roles::HALL_MANAGER => true ] ) ] === $this->roles( $race_id, 2 ), 'concurrency leaves one canonical role row' );
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE target_id = %s AND action = %s', Migrations::audit_log_table(), (string) $race_id, 'hall_staff.assigned' ) );
		$this->ok( 1 === $count, 'concurrency writes one mandatory grant audit' );
	}

	private function user( bool $sso = true ): int {
		$login = $sso ? 'portal-' . $this->tag . '-' . ( count( $this->users ) + 1 ) : 'hs-' . $this->tag . '-' . count( $this->users );
		$create = static fn() => wpmu_create_user( $login, wp_generate_password( 32 ), $login . '@example.invalid' );
		$id = $sso ? Guard::with_planned_username( $login, $create ) : $create();
		$this->ok( is_int( $id ) && $id > 0, 'fixture account created' );
		$this->users[] = $id;
		return $id;
	}

	private function audit( int $id, ?string $op = null, string $reference = 'original-creation' ) {
		return Provisioning::record_portal_sso_creation( $id, 2, get_userdata( $id )->user_login, $op ?? wp_generate_uuid4(), $reference );
	}
	private function roles( int $id, int $blog ): array {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE user_id = %d AND meta_key = %s', $wpdb->usermeta, $id, $wpdb->get_blog_prefix( $blog ) . 'capabilities' ) );
	}
	private function witness_count( string $op ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE operation_id = %s', Migrations::audit_log_table(), $op ) );
	}
	private function workers( array $jobs ): array {
		$children = [];
		foreach ( $jobs as $job ) {
			$process = proc_open( [ PHP_BINARY, __DIR__ . '/hall-staff-sso-worker.php', json_encode( $job ) ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
			$this->ok( is_resource( $process ), 'isolated worker launched' );
			$children[] = [ $process, $pipes ];
		}
		$results = [];
		foreach ( $children as [ $process, $pipes ] ) {
			$results[] = trim( stream_get_contents( $pipes[1] ) );
			$error = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] ); fclose( $pipes[2] );
			$this->ok( 0 === proc_close( $process ) && '' === $error, 'worker succeeded: ' . $error );
		}
		return $results;
	}
	private function error( string $code, $result, string $message ): void {
		$this->ok( is_wp_error( $result ) && $code === $result->get_error_code(), $message . ( is_wp_error( $result ) ? ': ' . $result->get_error_code() : '' ) );
	}
	private function success( string $status, $result, string $message ): void {
		$this->ok( $result instanceof HallStaffResult && $status === $result->status, $message . ( is_wp_error( $result ) ? ': ' . $result->get_error_code() : '' ) );
	}
	private function ok( bool $condition, string $message ): void {
		++$this->assertions;
		if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	}
	private function cleanup(): void {
		global $wpdb;
		wp_set_current_user( $this->admin );
		foreach ( $this->users as $id ) {
			if ( is_super_admin( $id ) ) { revoke_super_admin( $id ); }
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', $wpdb->usermeta, $id ) );
			clean_user_cache( $id );
			wpmu_delete_user( $id );
		}
	}
}
( new HallStaffSsoTest() )->run();
