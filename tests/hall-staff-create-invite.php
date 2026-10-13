<?php

/**
 * CLI integration coverage for Hall Staff Create/Invite (BR-035, BR-036).
 *
 * Lives outside wp-content/ so it can never ship inside the deployable plugin.
 *
 * Run from the repository root:
 * BC_TEST_DB=bingocommerce_state_recon php tests/hall-staff-create-invite.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This integration test is CLI-only.\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']      = 'bingocommerce.test';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

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
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

use BingoCommerce\Core\AuditLog;
use BingoCommerce\Core\HallStaffAdmin;
use BingoCommerce\Core\HallStaffCreationIntent;
use BingoCommerce\Core\HallStaffCreationRetryToken;
use BingoCommerce\Core\HallStaffEligibility;
use BingoCommerce\Core\HallStaffInvitation;
use BingoCommerce\Core\HallStaffProvisioningContext;
use BingoCommerce\Core\HallStaffProvisioningService;
use BingoCommerce\Core\HallStaffResult;
use BingoCommerce\Core\HallStaffRetryToken;
use BingoCommerce\Core\HallStaffService;
use BingoCommerce\Core\Migrations;
use BingoCommerce\Core\Roles;

final class CreateInviteTest {

	private const SUNRISE = 2;
	private const TAMPA   = 3;

	private array $groups = [];
	private string $group = '';
	private array $users  = [];
	private int $mails    = 0;
	private array $baseline_meta = [];
	private int $baseline_audit  = 0;

	public function run(): void {
		global $wpdb;
		$this->baseline_audit = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Migrations::audit_log_table() );
		$this->baseline_meta  = $this->membership_snapshot();

		try {
			$this->core_assumption();
			$this->eligibility();
			$this->happy_path();
			$this->display_name();
			$this->duplicate_detection();
			$this->creation_audit();
			$this->signed_role();
			$this->recovery_preconditions();
			$this->two_token_cross_check();
			$this->provenance();
			$this->invitation_gate();
			$this->fail_closed_on_db_uncertainty();
			$this->eligibility_on_retry();
			$this->eligibility_on_change_role();
			$this->signed_identity_binding();
			$this->marker_fail_closed();
			$this->unique_identity_resolution();
			$this->genuine_ambiguous_commit();
			$this->ui_copy();
			$this->ui_no_retry_on_invitation_failure();
			$this->concurrency();
			$this->invitation_concurrency();
		} finally {
			$this->cleanup();
		}

		$this->report();
	}

	/* ------------------------------------------------------------ groups */

	private function core_assumption(): void {
		$this->group( 'Core assumption regression guard' );
		$id = $this->fresh_user( 'ca' );
		$this->ok( 0 === $this->membership_count( $id ), 'wpmu_create_user leaves zero capability rows network-wide' );
	}

	private function eligibility(): void {
		$this->group( 'Strict eligibility (BR-036)' );
		$id = $this->fresh_user( 'el' );

		$this->ok( true === HallStaffEligibility::assert_operational( $id ), 'zero rows -> ALLOW' );

		$this->plant_caps( $id, self::TAMPA, [ Roles::GAME_OPERATOR => true ] );
		$this->ok( true === HallStaffEligibility::assert_operational( $id ), 'Tampa GO -> ALLOW' );

		$this->wipe_caps( $id );
		$this->plant_caps( $id, self::TAMPA, [ 'subscriber' => true ] );
		$this->ok( $this->denied( $id ), 'Tampa subscriber -> REJECT' );

		$this->wipe_caps( $id );
		$this->plant_caps( $id, 1, [ 'subscriber' => true ] );
		$this->ok( $this->denied( $id ), 'main-site bc_capabilities non-protected -> REJECT' );

		$this->wipe_caps( $id );
		$this->plant_caps( $id, self::TAMPA, [ Roles::HALL_MANAGER => true ] );
		$this->plant_caps( $id, self::TAMPA, [ Roles::HALL_MANAGER => true ] );
		$this->ok( $this->denied( $id ), 'duplicate bc_3_capabilities rows -> REJECT' );

		$this->wipe_caps( $id );
		$this->plant_raw( $id, self::TAMPA, 'a:1:{s:15:"bc_hall_manager"' );
		$this->ok( $this->denied( $id ), 'malformed / undecodable serialized value -> REJECT' );

		$this->wipe_caps( $id );
		$this->plant_caps( $id, self::TAMPA, [ Roles::HALL_MANAGER => true, 'editor' => true ] );
		$this->ok( $this->denied( $id ), 'bc_hall_manager + editor in one row -> REJECT' );

		$this->wipe_caps( $id );
		$this->plant_caps( $id, self::TAMPA, [ 'bc_totally_custom' => true ] );
		$this->ok( $this->denied( $id ), 'unknown / custom role -> REJECT' );

		$this->wipe_caps( $id );
		$this->plant_caps( $id, self::TAMPA, [ Roles::HALL_MANAGER => true, Roles::GAME_OPERATOR => true ] );
		$this->ok( $this->denied( $id ), 'two protected roles in one row -> REJECT' );

		$this->wipe_caps( $id );
		$this->plant_caps( $id, self::TAMPA, [ Roles::HALL_MANAGER => false ] );
		$this->ok( $this->denied( $id ), 'role key value not strictly true -> REJECT' );

		// Read-only guarantee.
		$before = $this->raw_caps( $id );
		HallStaffEligibility::assert_operational( $id );
		$this->ok( $before === $this->raw_caps( $id ), 'eligibility never mutates usermeta' );

		$this->wipe_caps( $id );

		// Regex parity with the writer guard.
		$guard = new ReflectionMethod( \BingoCommerce\Core\HallStaffWriterGuard::class, 'is_protected_meta_key' );
		$guard->setAccessible( true );
		$parity = true;
		foreach ( [ 'bc_capabilities', 'bc_2_capabilities', 'bc_0_capabilities', 'bc_x_capabilities' ] as $key ) {
			$guarded  = $guard->invoke( null, $key );
			$eligible = 1 === preg_match( '/^bc_([1-9][0-9]*_)?capabilities$/D', $key );
			$parity   = $parity && ( $guarded === $eligible );
		}
		$this->ok( $parity, 'regex parity with HallStaffWriterGuard' );

		// End-to-end: an ineligible existing identity is refused by the flow.
		$other = $this->fresh_user( 'el2' );
		$this->plant_caps( $other, self::TAMPA, [ 'subscriber' => true ] );
		$this->actor( 1, self::SUNRISE );
		$r = HallStaffProvisioningService::create_and_assign( $this->email( 'el2' ), 'El Two', Roles::GAME_OPERATOR );
		$this->ok(
			is_wp_error( $r ) && 'non_operational_identity_not_eligible' === $r->get_error_code(),
			'existing non-operational identity refused end-to-end -> ' . $this->res( $r )
		);
	}

	private function happy_path(): void {
		$this->group( 'Create/Invite happy path' );
		$this->actor( 1, self::SUNRISE );
		$this->mail_capture();

		$email = 'ci-happy@test.local';
		$r = HallStaffProvisioningService::create_and_assign( $email, 'Happy Path', Roles::GAME_OPERATOR );
		$this->mail_restore();

		$this->ok( $r instanceof HallStaffResult && HallStaffResult::COMMITTED_VERIFIED === $r->status, 'created + assigned -> ' . $this->res( $r ) );
		$id = $this->id_by_email( $email );
		$this->track( $id );

		$this->ok( $id > 0, 'identity created' );
		$this->ok( 'Happy Path' === get_userdata( $id )->display_name, 'display name stored correctly' );
		$this->ok( 'happypath' !== get_userdata( $id )->user_login && 'ci-happy' === get_userdata( $id )->user_login, 'user_login derived from email local-part' );
		$this->ok( HallStaffCreationIntent::IDENTITY_TYPE === get_user_meta( $id, 'bc_identity_type', true ), 'provenance marker written' );
		$this->ok( 1 === $this->witness_count_for_target( 'hall_staff.user_created', $id ), 'exactly one creation witness' );
		$this->ok( 1 === $this->mails, 'exactly one invitation mail' );
		$this->ok( '' !== (string) get_user_meta( $id, HallStaffInvitation::ISSUED_META_KEY, true ), 'invitation marker set' );
		$this->ok( Roles::GAME_OPERATOR === $this->role_on( $id, self::SUNRISE ), 'assigned GO on Sunrise' );

		// Native key lifecycle.
		$key = get_password_reset_key( get_userdata( $id ) );
		$this->ok( is_string( $key ) && ! is_wp_error( $key ), 'password reset key issued' );
		$raw = $this->db_var( 'SELECT user_activation_key FROM ' . $GLOBALS['wpdb']->users . ' WHERE ID = ' . $id );
		$this->ok( 1 === preg_match( '/^\d+:/', (string) $raw ), 'user_activation_key is time():hash shaped' );
		$this->ok( check_password_reset_key( $key, get_userdata( $id )->user_login ) instanceof WP_User, 'key validates' );
		wp_set_password( wp_generate_password( 24 ), $id );
		$this->ok( '' === (string) $this->db_var( 'SELECT user_activation_key FROM ' . $GLOBALS['wpdb']->users . ' WHERE ID = ' . $id ), 'wp_set_password clears the key' );
	}

	private function display_name(): void {
		$this->group( 'Display name (5a)' );
		$this->actor( 1, self::SUNRISE );
		$email = 'ci-dn@test.local';

		$this->mail_capture();
		$this->fail_profile_update();
		$r = HallStaffProvisioningService::create_and_assign( $email, 'Deenee Fail', Roles::GAME_OPERATOR );
		$this->restore_profile_update();
		$this->mail_restore();

		$id = $this->id_by_email( $email );
		$this->track( $id );

		$this->ok( is_wp_error( $r ) && HallStaffProvisioningService::PROVISIONING_INCOMPLETE === $r->get_error_code(), 'profile failure -> provisioning_incomplete -> ' . $this->res( $r ) );
		$this->ok( $id > 0 && get_userdata( $id ) instanceof WP_User, 'account exists, not deleted' );
		$this->ok( 0 === $this->membership_count( $id ), 'zero capability rows network-wide' );
		$this->ok( 0 === $this->witness_count_for_target( 'hall_staff.user_created', $id ), 'no creation witness written' );
		$this->ok( 0 === $this->mails, 'no invitation sent' );

		$token = $this->data( $r, 'creation_retry_token' );
		$this->ok( is_string( $token ) && '' !== $token, 'creation retry token returned' );

		$before = (int) $this->db_var( 'SELECT COUNT(*) FROM ' . $GLOBALS['wpdb']->users . " WHERE user_email = '" . esc_sql( $email ) . "'" );
		$this->mail_capture();
		$r2 = HallStaffProvisioningService::create_and_assign( $email, 'Deenee Fixed', Roles::GAME_OPERATOR, $token );
		$this->mail_restore();
		$after = (int) $this->db_var( 'SELECT COUNT(*) FROM ' . $GLOBALS['wpdb']->users . " WHERE user_email = '" . esc_sql( $email ) . "'" );

		$this->ok( $r2 instanceof HallStaffResult, 'retry completes provisioning -> ' . $this->res( $r2 ) );
		$this->ok( 1 === $before && 1 === $after, 'no duplicate account created (1 before, 1 after)' );
		$this->ok( $id === $this->id_by_email( $email ), 'same created_user_id reused' );
		$this->ok( 'Deenee Fixed' === get_userdata( $id )->display_name, 'display name present after retry' );
		$this->ok( 1 === $this->mails, 'invitation issued exactly once after recovery' );
	}

	private function duplicate_detection(): void {
		$this->group( 'Duplicate-identity detection + audit (3)' );

		foreach ( [ 'email', 'user_login' ] as $mode ) {
			$this->actor( 1, self::SUNRISE );
			$email = "ci-dup-{$mode}@test.local";
			$this->mail_capture();
			$this->inject_competitor( $mode, $email );
			$r = HallStaffProvisioningService::create_and_assign( $email, 'Dup ' . $mode, Roles::GAME_OPERATOR );
			$this->restore_competitor();
			$this->mail_restore();

			$competitor = $this->competitor_id;
			$id = $this->newest_user_with( $mode, $email, $competitor );
			$this->track( $id );

			$this->ok(
				is_wp_error( $r ) && HallStaffProvisioningService::DUPLICATE_DETECTED === $r->get_error_code(),
				"[$mode] collision -> duplicate_identity_detected -> " . $this->res( $r )
			);
			$this->ok( 0 === $this->membership_count( $id ), "[$mode] our identity stays UNASSIGNED" );
			$this->ok( 0 === $this->mails, "[$mode] no invitation sent" );

			$row = $this->witness_row( 'hall_staff.duplicate_identity_detected', $id );
			$this->ok( is_array( $row ), "[$mode] hall_staff.duplicate_identity_detected written" );
			if ( is_array( $row ) ) {
				$nv  = json_decode( (string) $row['new_value'], true );
				$ctx = json_decode( (string) $row['context'], true );
				$this->ok( ( $nv['email'] ?? '' ) === $email && ! empty( $nv['user_login'] ) && (int) ( $nv['created_user_id'] ?? 0 ) === $id, "[$mode] payload carries email/user_login/created_user_id" );
				$this->ok( in_array( $competitor, $nv['competing_user_ids'] ?? [], true ), "[$mode] competing_user_ids recorded" );
				$this->ok( in_array( $ctx['detection'] ?? '', [ 'email', 'user_login', 'both' ], true ), "[$mode] context.detection = " . ( $ctx['detection'] ?? '?' ) );
				$this->ok( ! empty( $row['operation_id'] ), "[$mode] carries its own operation_id (ordinary record(), not transactional)" );
			}

			// Competing identity untouched.
			$this->ok( get_userdata( $competitor ) instanceof WP_User && 0 === $this->membership_count( $competitor ), "[$mode] competing external identity byte-unchanged" );
		}

		// Audit write itself fails -> duplicate_identity_unrecorded.
		$this->actor( 1, self::SUNRISE );
		$email = 'ci-dup-unrec@test.local';
		$this->mail_capture();
		$competitor = $this->inject_competitor( 'email', $email );
		$this->audit_trigger_on( 'hall_staff.duplicate_identity_detected' );
		$r = HallStaffProvisioningService::create_and_assign( $email, 'Dup Unrec', Roles::GAME_OPERATOR );
		$this->audit_trigger_off();
		$this->restore_competitor();
		$this->mail_restore();
		$id = $this->newest_user_with( 'email', $email, $this->competitor_id );
		$this->track( $id );

		$this->ok( is_wp_error( $r ) && HallStaffProvisioningService::DUPLICATE_UNRECORDED === $r->get_error_code(), 'audit failure -> duplicate_identity_unrecorded -> ' . $this->res( $r ) );
		$this->ok( 0 === $this->membership_count( $id ), 'no assignment on duplicate_identity_unrecorded' );
		$this->ok( 0 === $this->mails, 'no invitation on duplicate_identity_unrecorded' );
	}

	private function creation_audit(): void {
		$this->group( 'Creation audit + witness (4)' );
		$this->actor( 1, self::SUNRISE );
		$email = 'ci-audit@test.local';

		$this->mail_capture();
		$this->audit_trigger_on( 'hall_staff.user_created' );
		$r = HallStaffProvisioningService::create_and_assign( $email, 'Audit Fail', Roles::GAME_OPERATOR );
		$this->audit_trigger_off();
		$this->mail_restore();

		$id = $this->id_by_email( $email );
		$this->track( $id );
		$token = $this->data( $r, 'creation_retry_token' );

		$this->ok( is_wp_error( $r ) && HallStaffProvisioningService::AUDIT_UNRECORDED === $r->get_error_code(), 'audit failure -> creation_audit_unrecorded -> ' . $this->res( $r ) );
		$this->ok( 0 === $this->membership_count( $id ), 'identity exists with zero capabilities' );
		$this->ok( 0 === $this->mails, 'no invitation' );

		// Retry with a matching witness present.
		$this->mail_capture();
		$r2 = HallStaffProvisioningService::create_and_assign( $email, 'Audit Fail', Roles::GAME_OPERATOR, $token );
		$this->mail_restore();
		$this->ok( $r2 instanceof HallStaffResult, 'retry writes the witness and proceeds -> ' . $this->res( $r2 ) );
		$this->ok( 1 === $this->witness_count_for_target( 'hall_staff.user_created', $id ), 'exactly one witness' );

		$original = $this->witness_row( 'hall_staff.user_created', $id );

		// Field-by-field mismatch matrix. Runs against a fixture that is still
		// UNASSIGNED (audit forced to fail, so no witness and no membership), so
		// the shape (a) zero-membership precondition is satisfied and the witness
		// comparison is actually reached.
		$fields = [
			'blog_id'        => '999',
			'actor_user_id'  => '999',
			'action'         => 'hall_staff.removed',
			'target_id'      => '999999',
			'previous_value' => '{"role":null}',
			'new_value'      => '{"email":"other@test.local","user_login":"x","identity_type":"staff_operational"}',
			'context'        => '{"intent_version":99}',
		];
		$mismatch = 0;
		$details  = [];
		foreach ( $fields as $col => $val ) {
			$slug = 'ci-mm-' . strtolower( $col );
			$uid  = $this->provision_to_audit_failure( $slug, $tk, $it );
			$this->plant_matching_witness( $it );
			$this->db_query( 'UPDATE ' . Migrations::audit_log_table() . " SET {$col} = '" . esc_sql( $val ) . "' WHERE operation_id = '" . esc_sql( $it->operation_id ) . "'" );
			$out = HallStaffProvisioningService::create_and_assign( $this->email( $slug ), 'Fixture', Roles::GAME_OPERATOR, $tk );
			$hit = is_wp_error( $out ) && HallStaffProvisioningService::IDENTITY_CONFLICT === $out->get_error_code();
			$mismatch += $hit ? 1 : 0;
			$details[] = $col . '=' . ( $hit ? 'conflict' : $this->res( $out ) );
			$this->db_query( 'DELETE FROM ' . Migrations::audit_log_table() . " WHERE operation_id = '" . esc_sql( $it->operation_id ) . "'" );
		}
		$this->ok( count( $fields ) === $mismatch, sprintf( 'every differing immutable field -> creation_identity_conflict (%d/%d) [%s]', $mismatch, count( $fields ), implode( ' ', $details ) ) );

		// Canonical payload.
		$nv  = json_decode( (string) $original['new_value'], true );
		$ctx = json_decode( (string) $original['context'], true );
		$this->ok( isset( $nv['identity_type'] ) && ! isset( $ctx['identity_type'] ), 'identity_type in new_value, absent from context' );
		$this->ok( ! isset( $nv['requested_role'] ) && ! isset( $ctx['requested_role'] ), 'requested_role absent from the creation witness entirely' );

		// Witness already present and mismatching on the FIRST read.
		$id2 = $this->provision_to_audit_failure( 'ci-preconf', $t2, $i2 );
		$this->plant_conflicting_witness( $i2->operation_id, $id2 );
		$out = HallStaffProvisioningService::create_and_assign( $this->email( 'ci-preconf' ), 'Pre Conflict', Roles::GAME_OPERATOR, $t2 );
		$this->ok( is_wp_error( $out ) && HallStaffProvisioningService::IDENTITY_CONFLICT === $out->get_error_code(), 'pre-existing mismatched witness -> creation_identity_conflict -> ' . $this->res( $out ) );
		$this->ok( 0 === $this->membership_count( $id2 ), 'no assignment on conflict' );
		$this->db_query( 'DELETE FROM ' . Migrations::audit_log_table() . " WHERE operation_id = '" . esc_sql( $i2->operation_id ) . "'" );

		// TRUE duplicate-key branch: row appears between the read and the INSERT.
		$id2b = $this->provision_to_audit_failure( 'ci-dupkey', $t2b, $i2b );
		$this->collide_on_insert( $i2b, 'mismatch' );
		$out = HallStaffProvisioningService::create_and_assign( $this->email( 'ci-dupkey' ), 'Dup Key', Roles::GAME_OPERATOR, $t2b );
		$this->restore_vanish();
		$this->ok( is_wp_error( $out ) && HallStaffProvisioningService::IDENTITY_CONFLICT === $out->get_error_code(), 'duplicate-key then mismatched re-read -> creation_identity_conflict -> ' . $this->res( $out ) );
		$this->ok( 0 === $this->membership_count( $id2b ), 'duplicate-key alone never reported as success' );
		$this->db_query( 'DELETE FROM ' . Migrations::audit_log_table() . " WHERE operation_id = '" . esc_sql( $i2b->operation_id ) . "'" );

		// TRUE duplicate-key branch resolving to idempotent success.
		$id2c = $this->provision_to_audit_failure( 'ci-dupok', $t2c, $i2c );
		$this->collide_on_insert( $i2c, 'match' );
		$this->mail_capture();
		$out = HallStaffProvisioningService::create_and_assign( $this->email( 'ci-dupok' ), 'Fixture', Roles::GAME_OPERATOR, $t2c );
		$this->mail_restore();
		$this->restore_vanish();
		$this->ok( $out instanceof HallStaffResult, 'duplicate-key then MATCHING re-read -> idempotent success -> ' . $this->res( $out ) );
		$this->ok( 1 === $this->witness_count_for_target( 'hall_staff.user_created', $id2c ), 'exactly one witness row after the collision' );

		// Duplicate-key + witness unreadable.
		$id3 = $this->provision_to_audit_failure( 'ci-unread', $t3, $i3 );
		$this->rename_audit_table( true );
		$out = HallStaffProvisioningService::create_and_assign( $this->email( 'ci-unread' ), 'Unread', Roles::GAME_OPERATOR, $t3 );
		$this->rename_audit_table( false );
		$this->ok( is_wp_error( $out ) && HallStaffProvisioningService::WITNESS_UNRESOLVED === $out->get_error_code(), 'witness unreadable -> creation_witness_unresolved -> ' . $this->res( $out ) );
		$this->ok( 0 === $this->membership_count( $id3 ), 'no assignment when the witness is unreadable' );

		// Duplicate-key + witness unexpectedly absent on the re-read.
		$id4 = $this->provision_to_audit_failure( 'ci-vanish', $t4, $i4 );
		$this->collide_on_insert( $i4, 'vanish' );
		$out = HallStaffProvisioningService::create_and_assign( $this->email( 'ci-vanish' ), 'Vanish', Roles::GAME_OPERATOR, $t4 );
		$this->restore_vanish();
		$this->ok( is_wp_error( $out ) && HallStaffProvisioningService::WITNESS_UNRESOLVED === $out->get_error_code(), 'duplicate-key then witness unexpectedly absent -> creation_witness_unresolved -> ' . $this->res( $out ) );
		$this->ok( 0 === $this->membership_count( $id4 ), 'no assignment when the witness vanishes' );
		$this->db_query( 'DELETE FROM ' . Migrations::audit_log_table() . " WHERE operation_id = '" . esc_sql( $i4->operation_id ) . "'" );

		// Token hygiene.
		[ $p, $s ] = explode( '.', $token, 2 );
		$this->ok( is_wp_error( HallStaffCreationRetryToken::verify( $p . '.' . substr( $s, 0, -4 ) . 'AAAA' ) ), 'tampered signature rejected' );
		$this->ok( is_wp_error( HallStaffCreationRetryToken::verify( 'garbage' ) ), 'malformed token rejected' );
		$this->ok( is_wp_error( HallStaffCreationRetryToken::verify( HallStaffRetryToken::issue( $this->phase_a_intent( $id ) ) ) ), 'Phase A token fails creation verify (family isolation)' );
		$this->ok( is_wp_error( HallStaffRetryToken::verify( $token ) ), 'creation token fails Phase A verify (family isolation)' );

		$src = file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-core/src/HallStaffProvisioningService.php' );
		$this->ok( ! str_contains( $src, 'hash_hmac' ), 'no hash_hmac inside HallStaffProvisioningService' );
	}

	private function signed_role(): void {
		$this->group( 'Signed requested_role (4)' );

		foreach ( [ [ Roles::GAME_OPERATOR, Roles::HALL_MANAGER ], [ Roles::HALL_MANAGER, Roles::GAME_OPERATOR ] ] as $i => $pair ) {
			[ $signed, $flipped ] = $pair;
			$slug = 'ci-role' . $i;
			$id   = $this->provision_to_audit_failure( $slug, $token, $intent, $signed );
			$out  = HallStaffProvisioningService::create_and_assign( $this->email( $slug ), 'Role Flip', $flipped, $token );
			$this->ok( is_wp_error( $out ) && 'retry_intent_mismatch' === $out->get_error_code(), "retry flipping {$signed} -> {$flipped} rejected -> " . $this->res( $out ) );
			$this->ok( 0 === $this->membership_count( $id ), '  no assignment, no role change' );
			$this->ok( 0 === $this->witness_count_for_target( 'hall_staff.user_created', $id ), '  no witness read/write on mismatch' );

			// No role submitted -> proceeds on the signed role.
			$this->mail_capture();
			$ok = HallStaffProvisioningService::create_and_assign( $this->email( $slug ), 'Role Flip', '', $token );
			$this->mail_restore();
			$this->ok( $ok instanceof HallStaffResult, '  no submitted role -> proceeds -> ' . $this->res( $ok ) );
			$this->ok( $signed === $this->role_on( $id, self::SUNRISE ), "  membership matches the signed role ({$signed})" );
		}

		// Tampered role field inside the token.
		$id   = $this->provision_to_audit_failure( 'ci-roletamper', $token, $intent, Roles::GAME_OPERATOR );
		[ $p ] = explode( '.', $token, 2 );
		$raw   = json_decode( base64_decode( strtr( $p, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $p ) % 4 ) % 4 ) ), true );
		$raw['requested_role'] = Roles::HALL_MANAGER;
		$forged = rtrim( strtr( base64_encode( wp_json_encode( $raw, JSON_UNESCAPED_SLASHES ) ), '+/', '-_' ), '=' ) . '.' . explode( '.', $token, 2 )[1];
		$this->ok( is_wp_error( HallStaffCreationRetryToken::verify( $forged ) ), 'token tamper on requested_role -> invalid_retry_token' );

		$bad = clone_intent_with_role( $intent, 'administrator' );
		$this->ok( is_wp_error( HallStaffCreationRetryToken::verify( HallStaffCreationRetryToken::issue( $bad ) ) ), 'requested_role outside the protected set rejected on verify' );
	}

	private function recovery_preconditions(): void {
		$this->group( 'Recovery-state preconditions' );

		// Shape (a): unexpected HALL-PREFIXED membership -> fail closed.
		$id = $this->provision_to_audit_failure( 'ci-recA1', $token, $intent );
		$this->plant_caps( $id, self::TAMPA, [ Roles::GAME_OPERATOR => true ] );
		$this->mail_capture();
		$out = HallStaffProvisioningService::create_and_assign( $this->email( 'ci-recA1' ), 'Rec A1', Roles::GAME_OPERATOR, $token );
		$this->mail_restore();
		$this->ok( is_wp_error( $out ) && HallStaffProvisioningService::UNEXPECTED_MEMBERSHIP === $out->get_error_code(), 'shape (a) hall-prefixed membership -> unexpected_membership_state -> ' . $this->res( $out ) );
		$this->ok( 0 === $this->mails, '  no invitation' );
		$this->ok( 0 === $this->witness_count_for_target( 'hall_staff.user_created', $id ), '  no automatic continuation' );
		$this->ok( Roles::GAME_OPERATOR === $this->role_on( $id, self::TAMPA ), '  planted membership left untouched' );

		// Shape (a): unexpected UNPREFIXED bc_capabilities -> fail closed. (extra case)
		$id2 = $this->provision_to_audit_failure( 'ci-recA2', $token2, $intent2 );
		$this->plant_caps( $id2, 1, [ 'subscriber' => true ] );
		$this->mail_capture();
		$out = HallStaffProvisioningService::create_and_assign( $this->email( 'ci-recA2' ), 'Rec A2', Roles::GAME_OPERATOR, $token2 );
		$this->mail_restore();
		$this->ok( is_wp_error( $out ) && HallStaffProvisioningService::UNEXPECTED_MEMBERSHIP === $out->get_error_code(), 'shape (a) UNPREFIXED bc_capabilities -> unexpected_membership_state -> ' . $this->res( $out ) );
		$this->ok( 0 === $this->mails, '  no invitation' );
		$this->ok( 0 === $this->witness_count_for_target( 'hall_staff.user_created', $id2 ), '  no automatic continuation' );
		$this->ok( 1 === count( $this->raw_caps_for( $id2, 'bc_capabilities' ) ), '  planted main-site row left untouched' );

		// Shape (b): the assignment COMMITTED but the operation returned a failure
		// before any invitation was issued. Real seam: the marker write fails, so
		// HallStaffInvitation cannot guarantee at-most-once and reports
		// invitation_marker_unwritable. Both AUTHENTIC tokens come back on that
		// response — nothing is fabricated.
		$this->actor( 1, self::SUNRISE );
		$email = 'ci-ack@test.local';
		$this->mail_capture();
		$this->block_meta( HallStaffInvitation::ISSUED_META_KEY );
		$first = HallStaffProvisioningService::create_and_assign( $email, 'Ack Lost', Roles::GAME_OPERATOR );
		$this->unblock_meta();
		$this->mail_restore();
		$id3 = $this->id_by_email( $email );
		$this->track( $id3 );

		$this->ok( is_wp_error( $first ) && HallStaffInvitation::MARKER_UNWRITABLE === $first->get_error_code(), 'shape (b) setup: marker unwritable -> ' . $this->res( $first ) );
		$this->ok( HallStaffResult::COMMITTED_VERIFIED === $this->data( $first, 'assignment_status' ), '  setup: the assignment really COMMITTED' );
		$this->ok( 0 === $this->mails, '  setup: no invitation was issued' );
		$this->ok( Roles::GAME_OPERATOR === $this->role_on( $id3, self::SUNRISE ), '  setup: target already holds the protected role' );

		$auth_assignment = $this->data( $first, 'retry_token' );
		$auth_creation   = $this->data( $first, 'creation_retry_token' );
		$this->ok( is_string( $auth_assignment ) && ! is_wp_error( HallStaffRetryToken::verify( $auth_assignment ) ), 'response carries an AUTHENTIC HallStaffRetryToken' );
		$this->ok( is_string( $auth_creation ) && ! is_wp_error( HallStaffCreationRetryToken::verify( $auth_creation ) ), 'response carries an AUTHENTIC HallStaffCreationRetryToken' );

		$witness_before = $this->witness_count_for_target( 'hall_staff.assigned', $id3 );

		$this->mail_capture();
		$out = HallStaffProvisioningService::create_and_assign( $email, 'Ack Lost', Roles::GAME_OPERATOR, $auth_creation, $auth_assignment );
		$this->mail_restore();

		$this->ok( $out instanceof HallStaffResult, 'two-token recovery NOT rejected because membership exists -> ' . $this->res( $out ) );
		$this->ok( $out instanceof HallStaffResult && HallStaffResult::IDEMPOTENT_REPLAY_SUCCESS === $out->status, '  Phase A reconciliation proves the original commit -> IDEMPOTENT_REPLAY_SUCCESS' );
		$this->ok( 1 === $this->mails, '  first invitation issued exactly once' );
		$this->ok( '' !== (string) get_user_meta( $id3, HallStaffInvitation::ISSUED_META_KEY, true ), '  invitation marker now set' );
		$this->ok( $witness_before === $this->witness_count_for_target( 'hall_staff.assigned', $id3 ), '  no duplicate assignment witness' );

		// Shape (b) requires a MATCHING creation witness, not merely a valid token.
		$idw = $this->provision_to_audit_failure( 'ci-nowit', $tw, $iw );
		$this->mail_capture();
		$out = HallStaffProvisioningService::create_and_assign( $this->email( 'ci-nowit' ), 'No Witness', Roles::GAME_OPERATOR, $tw, HallStaffRetryToken::issue( $this->phase_a_intent( $idw ) ) );
		$this->mail_restore();
		$this->ok( is_wp_error( $out ) && HallStaffProvisioningService::WITNESS_UNRESOLVED === $out->get_error_code(), 'shape (b) with ABSENT creation witness -> fail closed -> ' . $this->res( $out ) );
		$this->ok( 0 === $this->mails && 0 === $this->membership_count( $idw ), '  no assignment, no invitation' );

		$idm = $this->provision_to_audit_failure( 'ci-badwit', $tm, $im );
		$this->plant_conflicting_witness( $im->operation_id, $idm );
		$this->mail_capture();
		$out = HallStaffProvisioningService::create_and_assign( $this->email( 'ci-badwit' ), 'Bad Witness', Roles::GAME_OPERATOR, $tm, HallStaffRetryToken::issue( $this->phase_a_intent( $idm ) ) );
		$this->mail_restore();
		$this->ok( is_wp_error( $out ) && HallStaffProvisioningService::IDENTITY_CONFLICT === $out->get_error_code(), 'shape (b) with MISMATCHED creation witness -> creation_identity_conflict -> ' . $this->res( $out ) );
		$this->ok( 0 === $this->mails && 0 === $this->membership_count( $idm ), '  no assignment, no invitation' );
		$this->db_query( 'DELETE FROM ' . Migrations::audit_log_table() . " WHERE operation_id = '" . esc_sql( $im->operation_id ) . "'" );

		// Shape (b): assignment genuinely did not commit. Real first attempt via
		// the assignment-audit trigger, so BOTH tokens are authentic and the
		// creation witness exists; the retry then runs Phase A unchanged.
		$id4 = $this->provision_to_assignment_failure( 'ci-nocommit', $ct4, $at4, $ci4 );
		$this->ok( is_string( $at4 ) && is_string( $ct4 ), 'shape (b) no-commit setup: both authentic tokens returned' );
		$this->mail_capture();
		$this->audit_trigger_on( 'hall_staff.assigned' );
		$out = HallStaffProvisioningService::create_and_assign( $this->email( 'ci-nocommit' ), 'Fixture', Roles::GAME_OPERATOR, $ct4, $at4 );
		$this->audit_trigger_off();
		$this->mail_restore();
		$this->ok( is_wp_error( $out ), 'shape (b) with no commit follows the Phase A retry path -> ' . $this->res( $out ) );
		$this->ok( 'NOT_COMMITTED_VERIFIED' === $this->data( $out, 'status' ), '  Phase A reports NOT_COMMITTED_VERIFIED unchanged' );
		$this->ok( 0 === $this->membership_count( $id4 ), '  target left UNASSIGNED' );
		$this->ok( 0 === $this->mails, '  no invitation sent' );

		// Never infer outcome from role state: membership present, no Phase A witness.
		$id5 = $this->provision_to_assignment_failure( 'ci-noinfer', $ct5, $at5, $ci5 );
		$this->plant_caps( $id5, self::SUNRISE, [ Roles::GAME_OPERATOR => true ] );
		$this->mail_capture();
		$fake = HallStaffRetryToken::issue( $this->phase_a_intent( $id5 ) );
		$out  = HallStaffProvisioningService::create_and_assign( $this->email( 'ci-noinfer' ), 'Fixture', Roles::GAME_OPERATOR, $ct5, $fake );
		$this->mail_restore();
		$this->ok( ! ( $out instanceof HallStaffResult ), 'pre-existing membership + no Phase A witness for that op must NOT report success -> ' . $this->res( $out ) );
		$this->ok( 0 === $this->mails, '  no invitation' );
	}

	private function two_token_cross_check(): void {
		$this->group( 'Two-token cross-check (6b)' );

		// Fixture must have a REAL creation witness, otherwise shape (b) correctly
		// fails closed at the witness stage before the cross-check is reached.
		$id = $this->provision_to_assignment_failure( 'ci-xcheck', $token, $auth_assign, $intent );
		$this->ok( is_string( $token ) && 1 === $this->witness_count_for_target( 'hall_staff.user_created', $id ), 'setup: creation witness exists, assignment failed' );

		$cases = [
			'different actor'          => [ 'actor' => 999 ],
			'different blog'           => [ 'blog' => self::TAMPA ],
			'target != created_user'   => [ 'target' => 999999 ],
			'after_role != signed'     => [ 'after' => Roles::HALL_MANAGER ],
			'action not assigned'      => [ 'action' => 'hall_staff.removed', 'before' => Roles::GAME_OPERATOR, 'after' => null ],
		];

		$rejected = 0;
		foreach ( $cases as $label => $override ) {
			$this->mail_capture();
			$phase_a = HallStaffRetryToken::issue( $this->phase_a_intent( $id, $override ) );
			$out     = HallStaffProvisioningService::create_and_assign( $this->email( 'ci-xcheck' ), 'X Check', Roles::GAME_OPERATOR, $token, $phase_a );
			$this->mail_restore();
			$hit = is_wp_error( $out ) && 'retry_intent_mismatch' === $out->get_error_code() && 0 === $this->mails;
			$this->ok( $hit, "cross-check {$label} -> retry_intent_mismatch, no mail -> " . $this->res( $out ) );
			$rejected += $hit ? 1 : 0;
		}
		$this->ok( count( $cases ) === $rejected, sprintf( 'all %d cross-check fields reject before invitation', count( $cases ) ) );
		$this->ok( 0 === $this->membership_count( $id ), 'no assignment from any mismatched pair' );

		// Phase A token valid but creation token missing -> ordinary retry, no context.
		$this->mail_capture();
		$out = HallStaffService::assign_existing_user( $this->email( 'ci-xcheck' ), Roles::GAME_OPERATOR );
		$this->mail_restore();
		$this->ok( $out instanceof HallStaffResult, 'generic assignment without a creation token still works -> ' . $this->res( $out ) );
		$this->ok( 0 === $this->mails, '  and sends NO automatic invitation' );
	}

	private function provenance(): void {
		$this->group( 'Provenance (5b)' );
		$this->actor( 1, self::SUNRISE );
		$email = 'ci-prov@test.local';

		$this->mail_capture();
		$this->block_meta( 'bc_identity_type' );
		$r = HallStaffProvisioningService::create_and_assign( $email, 'Prov Fail', Roles::GAME_OPERATOR );
		$this->unblock_meta();
		$this->mail_restore();

		$id = $this->id_by_email( $email );
		$this->track( $id );
		$this->ok( is_wp_error( $r ) && HallStaffProvisioningService::PROVISIONING_INCOMPLETE === $r->get_error_code(), 'provenance failure -> provisioning_incomplete -> ' . $this->res( $r ) );
		$this->ok( 0 === $this->membership_count( $id ), 'UNASSIGNED' );
		$this->ok( get_userdata( $id ) instanceof WP_User, 'not deleted' );
		$this->ok( 0 === $this->witness_count_for_target( 'hall_staff.user_created', $id ), 'no creation witness' );
		$this->ok( 0 === $this->mails, 'no invitation' );

		$this->mail_capture();
		$r2 = HallStaffProvisioningService::create_and_assign( $email, 'Prov Fixed', Roles::GAME_OPERATOR, $this->data( $r, 'creation_retry_token' ) );
		$this->mail_restore();
		$this->ok( $r2 instanceof HallStaffResult, 'retry repairs the marker and continues -> ' . $this->res( $r2 ) );
		$this->ok( HallStaffCreationIntent::IDENTITY_TYPE === get_user_meta( $id, 'bc_identity_type', true ), 'marker present after retry' );

		// Legacy staff (no marker) remain eligible and assignable.
		$legacy = $this->fresh_user( 'legacy' );
		$this->actor( 1, self::TAMPA );
		$this->mail_capture();
		$out = HallStaffService::assign_existing_user( $this->email( 'legacy' ), Roles::GAME_OPERATOR );
		$this->mail_restore();
		$this->ok( $out instanceof HallStaffResult, 'legacy account with no marker is assignable -> ' . $this->res( $out ) );
		$this->ok( '' === (string) get_user_meta( $legacy, 'bc_identity_type', true ), '  and still carries no provenance marker' );
		$this->ok( 0 === $this->mails, '  and receives NO automatic invitation' );
	}

	private function invitation_gate(): void {
		$this->group( 'Invitation gate (6)' );

		// GATE 4 — the leak this closes.
		$this->actor( 1, self::TAMPA );
		$email = 'ci-gate4@test.local';
		$this->mail_capture();
		$this->block_meta( HallStaffInvitation::ISSUED_META_KEY );
		$made = HallStaffProvisioningService::create_and_assign( $email, 'Gate Four', Roles::GAME_OPERATOR );
		$this->unblock_meta();
		$this->mail_restore();
		$id = $this->id_by_email( $email );
		$this->track( $id );

		$this->ok(
			is_wp_error( $made ) && HallStaffResult::COMMITTED_VERIFIED === $this->data( $made, 'assignment_status' ),
			'setup: Tampa GO provisioned and assigned, invitation suppressed -> ' . $this->res( $made )
		);
		$this->ok( HallStaffCreationIntent::IDENTITY_TYPE === get_user_meta( $id, 'bc_identity_type', true ), 'setup: carries staff_operational' );
		$this->ok( '' === (string) get_user_meta( $id, HallStaffInvitation::ISSUED_META_KEY, true ), 'setup: has NO invitation marker' );

		$this->actor( 1, self::SUNRISE );
		$this->mail_capture();
		$cross = HallStaffService::assign_existing_user( $email, Roles::GAME_OPERATOR );
		$this->mail_restore();
		$this->ok( $cross instanceof HallStaffResult, 'GATE 4: cross-hall assignment succeeds -> ' . $this->res( $cross ) );
		$this->ok( 0 === $this->mails, 'GATE 4: ZERO automatic email' );
		$this->ok( '' === (string) get_user_meta( $id, HallStaffInvitation::ISSUED_META_KEY, true ), 'GATE 4: marker still absent (suppressed, not silently claimed)' );

		$this->mail_capture();
		$replay = HallStaffService::assign_existing_user( $email, Roles::GAME_OPERATOR, $cross->retry_token );
		$this->mail_restore();
		$this->ok( $replay instanceof HallStaffResult && HallStaffResult::IDEMPOTENT_REPLAY_SUCCESS === $replay->status, 'GATE 4: replay status -> ' . $this->res( $replay ) );
		$this->ok( 0 === $this->mails, 'GATE 4: still ZERO email on IDEMPOTENT_REPLAY_SUCCESS' );

		// Structural assertion.
		$svc = file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-core/src/HallStaffService.php' );
		$this->ok(
			! str_contains( $svc, 'HallStaffInvitation' ) && ! str_contains( $svc, 'HallStaffProvisioningContext' ),
			'HallStaffService references neither HallStaffInvitation nor HallStaffProvisioningContext'
		);

		// Gate-4 runtime mismatch.
		$ctx = new HallStaffProvisioningContext( $id + 100000, 1, self::SUNRISE, 1, Roles::GAME_OPERATOR );
		$this->mail_capture();
		$sent = HallStaffInvitation::maybe_send_automatic( $ctx, $id, HallStaffResult::COMMITTED_VERIFIED );
		$this->mail_restore();
		$this->ok( false === $sent && 0 === $this->mails, 'gate-4 runtime mismatch (context for a different user) -> no mail' );

		// Gate 1: a non-success status sends nothing.
		$ctx_ok = new HallStaffProvisioningContext( $id, 1, self::SUNRISE, 1, Roles::GAME_OPERATOR );
		$this->mail_capture();
		$sent = HallStaffInvitation::maybe_send_automatic( $ctx_ok, $id, 'NOT_COMMITTED_VERIFIED' );
		$this->mail_restore();
		$this->ok( false === $sent && 0 === $this->mails, 'gate 1: non-success status -> no mail' );

		// Gate 3: already-invited identity.
		update_user_meta( $id, HallStaffInvitation::ISSUED_META_KEY, (string) time() );
		$this->mail_capture();
		$sent = HallStaffInvitation::maybe_send_automatic( $ctx_ok, $id, HallStaffResult::COMMITTED_VERIFIED );
		$this->mail_restore();
		$this->ok( false === $sent && 0 === $this->mails, 'gate 3: already-invited -> no mail' );
		delete_user_meta( $id, HallStaffInvitation::ISSUED_META_KEY );

		// Gate 2: no provenance marker.
		$plain = $this->fresh_user( 'gate2' );
		$ctx_plain = new HallStaffProvisioningContext( $plain, 1, self::SUNRISE, 1, Roles::GAME_OPERATOR );
		$this->mail_capture();
		$sent = HallStaffInvitation::maybe_send_automatic( $ctx_plain, $plain, HallStaffResult::COMMITTED_VERIFIED );
		$this->mail_restore();
		$this->ok( false === $sent && 0 === $this->mails, 'gate 2: no provenance marker -> no mail' );

		// Marker write fails -> no send.
		$this->mail_capture();
		$this->block_meta( HallStaffInvitation::ISSUED_META_KEY );
		$sent = HallStaffInvitation::maybe_send_automatic( $ctx_ok, $id, HallStaffResult::COMMITTED_VERIFIED );
		$this->unblock_meta();
		$this->mail_restore();
		$this->ok(
			is_wp_error( $sent ) && HallStaffInvitation::MARKER_UNWRITABLE === $sent->get_error_code() && 0 === $this->mails,
			'marker write fails -> no send, surfaced as invitation_marker_unwritable -> ' . $this->res( $sent )
		);

		// Send fails after the marker is written.
		delete_user_meta( $id, HallStaffInvitation::ISSUED_META_KEY );
		$this->mail_fail();
		$sent = HallStaffInvitation::maybe_send_automatic( $ctx_ok, $id, HallStaffResult::COMMITTED_VERIFIED );
		$this->mail_restore();
		$this->ok( is_wp_error( $sent ) && HallStaffInvitation::NOT_COMPLETED === $sent->get_error_code(), 'send failure after marker -> invitation_not_completed -> ' . $this->res( $sent ) );
		$this->ok( '' !== (string) get_user_meta( $id, HallStaffInvitation::ISSUED_META_KEY, true ), '  marker retained (at-most-once preserved)' );

		// Resend works regardless of provenance, and throttles.
		$this->actor( 1, self::SUNRISE );
		delete_user_meta( $id, HallStaffInvitation::ISSUED_META_KEY );
		$this->mail_capture();
		$resent = HallStaffInvitation::resend( $id );
		$this->mail_restore();
		$this->ok( true === $resent && 1 === $this->mails, 'Resend works for the Tampa-GO-assigned-to-Sunrise case' );
		$throttled = HallStaffInvitation::resend( $id );
		$this->ok( is_wp_error( $throttled ) && 'invitation_throttled' === $throttled->get_error_code(), 'Resend throttled at 60s -> ' . $this->res( $throttled ) );

		$this->actor( 1, self::TAMPA );
		$legacy_id = $this->id_by_email( $this->email( 'legacy' ) );
		delete_user_meta( $legacy_id, HallStaffInvitation::ISSUED_META_KEY );
		$this->mail_capture();
		$resent = HallStaffInvitation::resend( $legacy_id );
		$this->mail_restore();
		$this->ok( true === $resent && 1 === $this->mails, 'Resend works for a legacy/no-marker staff member' );
	}

	/** Database uncertainty must never be read as a clean result. */
	private function fail_closed_on_db_uncertainty(): void {
		$this->group( 'Fail-closed on DB uncertainty' );
		$id = $this->fresh_user( 'fc' );

		// BR-036 eligibility query failure.
		$this->break_query( 'REGEXP' );
		$out = HallStaffEligibility::assert_operational( $id );
		$this->restore_query();
		$this->ok( is_wp_error( $out ), 'BR-036 eligibility query failure -> WP_Error, never true -> ' . $this->res( $out ) );
		$this->ok( is_wp_error( $out ) && 'eligibility_read_failed' === $out->get_error_code(), '  controlled fail-closed code' );

		// And end-to-end: the flow refuses to assign.
		$this->actor( 1, self::SUNRISE );
		$this->mail_capture();
		$this->break_query( 'REGEXP' );
		$r = HallStaffService::assign_existing_user( $this->email( 'fc' ), Roles::GAME_OPERATOR );
		$this->restore_query();
		$this->mail_restore();
		$this->ok( is_wp_error( $r ) && 'eligibility_read_failed' === $r->get_error_code(), 'assignment refused under eligibility DB failure -> ' . $this->res( $r ) );
		$this->ok( 0 === $this->membership_count( $id ) && 0 === $this->mails, '  no mutation, no invitation' );

		// Duplicate-detection query failure.
		$this->mail_capture();
		$this->break_query( 'OR user_login = ' );
		$r = HallStaffProvisioningService::create_and_assign( 'ci-fcdup@test.local', 'FC Dup', Roles::GAME_OPERATOR );
		$this->restore_query();
		$this->mail_restore();
		$made = $this->id_by_email( 'ci-fcdup@test.local' );
		$this->track( $made );
		$this->ok( is_wp_error( $r ) && 'duplicate_check_failed' === $r->get_error_code(), 'duplicate-detection query failure -> duplicate_check_failed -> ' . $this->res( $r ) );
		$this->ok( 0 === $this->mails, '  no invitation' );
		$this->ok( 0 === $this->witness_count_for_target( 'hall_staff.user_created', $made ), '  never continued to provenance/audit/assignment' );

		// Zero-row anomaly: our own identity is missing from the result.
		$this->actor( 1, self::SUNRISE );
		$this->mail_capture();
		$this->empty_duplicate_result();
		$r = HallStaffProvisioningService::create_and_assign( 'ci-fczero@test.local', 'FC Zero', Roles::GAME_OPERATOR );
		$this->restore_query();
		$this->mail_restore();
		$made = $this->id_by_email( 'ci-fczero@test.local' );
		$this->track( $made );
		$this->ok( is_wp_error( $r ) && 'identity_state_anomaly' === $r->get_error_code(), 'zero-row anomaly -> identity_state_anomaly -> ' . $this->res( $r ) );
		$this->ok( 0 === $this->mails && 0 === $this->witness_count_for_target( 'hall_staff.user_created', $made ), '  no continuation' );

		// One row that is NOT our created identity.
		$other = $this->fresh_user( 'fcother' );
		$this->actor( 1, self::SUNRISE );
		$this->mail_capture();
		$this->single_foreign_duplicate_result( $other );
		$r = HallStaffProvisioningService::create_and_assign( 'ci-fcone@test.local', 'FC One', Roles::GAME_OPERATOR );
		$this->restore_query();
		$this->mail_restore();
		$made = $this->id_by_email( 'ci-fcone@test.local' );
		$this->track( $made );
		$this->ok( is_wp_error( $r ) && 'identity_state_anomaly' === $r->get_error_code(), 'single row that is not created_user_id -> identity_state_anomaly -> ' . $this->res( $r ) );
		$this->ok( 0 === $this->mails && 0 === $this->witness_count_for_target( 'hall_staff.user_created', $made ), '  no continuation' );
	}

	/** BR-036 must be re-evaluated on every assignment retry. */
	private function eligibility_on_retry(): void {
		$this->group( 'BR-036 recheck on assignment retry' );
		$id = $this->fresh_user( 'retryelig' );
		$this->actor( 1, self::SUNRISE );

		// A genuine, valid retry token from a failed assignment.
		$this->audit_trigger_on( 'hall_staff.assigned' );
		$first = HallStaffService::assign_existing_user( $this->email( 'retryelig' ), Roles::GAME_OPERATOR );
		$this->audit_trigger_off();
		$token = $this->data( $first, 'retry_token' );
		$this->ok( is_wp_error( $first ) && is_string( $token ), 'setup: valid retry token from a failed assignment -> ' . $this->res( $first ) );
		$this->ok( 0 === $this->membership_count( $id ), '  setup: target UNASSIGNED' );

		// Between issue and retry, the target gains a non-protected role elsewhere.
		$this->plant_caps( $id, self::TAMPA, [ 'subscriber' => true ] );

		$this->mail_capture();
		$retry = HallStaffService::assign_existing_user( $this->email( 'retryelig' ), Roles::GAME_OPERATOR, $token );
		$this->mail_restore();

		$this->ok( is_wp_error( $retry ) && 'non_operational_identity_not_eligible' === $retry->get_error_code(), 'retry rejected: eligibility no longer canonical -> ' . $this->res( $retry ) );
		$this->ok( null === $this->role_on( $id, self::SUNRISE ), '  no mutation on the target hall' );
		$this->ok( 0 === $this->mails, '  no invitation' );
		$this->ok( 0 === $this->witness_count_for_target( 'hall_staff.assigned', $id ), '  never entered ADR-025 retry execution' );
	}

	/** BR-036 applies to role changes, but never blocks removal. */
	private function eligibility_on_change_role(): void {
		$this->group( 'BR-036 on change_role (not remove)' );
		$id = $this->fresh_user( 'crelig' );
		$this->actor( 1, self::SUNRISE );

		$assigned = HallStaffService::assign_existing_user( $this->email( 'crelig' ), Roles::GAME_OPERATOR );
		$this->ok( $assigned instanceof HallStaffResult, 'setup: Sunrise GO assigned -> ' . $this->res( $assigned ) );

		// Fresh change_role with a non-protected role on ANOTHER hall.
		$this->plant_caps( $id, self::TAMPA, [ 'subscriber' => true ] );
		$this->mail_capture();
		$changed = HallStaffService::change_role( $id, Roles::HALL_MANAGER );
		$this->mail_restore();
		$this->ok( is_wp_error( $changed ) && 'non_operational_identity_not_eligible' === $changed->get_error_code(), 'fresh GO->HM rejected while Tampa subscriber exists -> ' . $this->res( $changed ) );
		$this->ok( Roles::GAME_OPERATOR === $this->role_on( $id, self::SUNRISE ), '  no mutation: still GO on Sunrise' );

		// Retry path: obtain a valid role-change token first, THEN make ineligible.
		$this->wipe_caps( $id );
		$this->plant_caps( $id, self::SUNRISE, [ Roles::GAME_OPERATOR => true ] );
		$this->audit_trigger_on( 'hall_staff.role_changed' );
		$failed = HallStaffService::change_role( $id, Roles::HALL_MANAGER );
		$this->audit_trigger_off();
		$token = $this->data( $failed, 'retry_token' );
		$this->ok( is_wp_error( $failed ) && is_string( $token ), 'setup: valid role-change retry token -> ' . $this->res( $failed ) );

		$this->plant_caps( $id, self::TAMPA, [ 'subscriber' => true ] );
		$before = $this->witness_count_for_target( 'hall_staff.role_changed', $id );
		$retry  = HallStaffService::change_role( $id, Roles::HALL_MANAGER, $token );
		$this->ok( is_wp_error( $retry ) && 'non_operational_identity_not_eligible' === $retry->get_error_code(), 'role-change retry rejected after drift -> ' . $this->res( $retry ) );
		$this->ok( Roles::GAME_OPERATOR === $this->role_on( $id, self::SUNRISE ), '  no mutation' );
		$this->ok( $before === $this->witness_count_for_target( 'hall_staff.role_changed', $id ), '  never entered ADR-025 retry execution' );

		// Removal of the SAME ineligible identity must still work and be audited.
		$before_rm = $this->witness_count_for_target( 'hall_staff.removed', $id );
		$removed   = HallStaffService::remove_staff( $id );
		$this->ok( $removed instanceof HallStaffResult, 'remove_staff on the ineligible identity still allowed -> ' . $this->res( $removed ) );
		$this->ok( null === $this->role_on( $id, self::SUNRISE ), '  protected membership removed' );
		$this->ok( $before_rm + 1 === $this->witness_count_for_target( 'hall_staff.removed', $id ), '  removal audited' );
	}

	/** Recovery must act only on the exact signed identity. */
	private function signed_identity_binding(): void {
		$this->group( 'Signed-identity binding on recovery' );
		global $wpdb;

		// Email drift.
		$id = $this->provision_to_audit_failure( 'ci-drifte', $t, $i );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->users} SET user_email = %s WHERE ID = %d", 'ci-drifte-changed@test.local', $id ) );
		clean_user_cache( $id );
		$this->mail_capture();
		$out = HallStaffProvisioningService::create_and_assign( $this->email( 'ci-drifte' ), 'Drift', Roles::GAME_OPERATOR, $t );
		$this->mail_restore();
		$this->ok( is_wp_error( $out ) && 'identity_state_anomaly' === $out->get_error_code(), 'email changed before recovery -> identity_state_anomaly -> ' . $this->res( $out ) );
		$this->ok( 0 === $this->mails && 0 === $this->membership_count( $id ), '  no assignment, no invitation' );

		// Login drift.
		$id2 = $this->provision_to_audit_failure( 'ci-driftl', $t2, $i2 );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->users} SET user_login = %s WHERE ID = %d", 'ci_driftl_renamed', $id2 ) );
		clean_user_cache( $id2 );
		$this->mail_capture();
		$out = HallStaffProvisioningService::create_and_assign( $this->email( 'ci-driftl' ), 'Drift', Roles::GAME_OPERATOR, $t2 );
		$this->mail_restore();
		$this->ok( is_wp_error( $out ) && 'identity_state_anomaly' === $out->get_error_code(), 'login changed before recovery -> identity_state_anomaly -> ' . $this->res( $out ) );
		$this->ok( 0 === $this->mails && 0 === $this->membership_count( $id2 ), '  no assignment, no invitation' );

		// A second identity appears carrying the signed email.
		$id3 = $this->provision_to_audit_failure( 'ci-shadow', $t3, $i3 );
		$wpdb->insert(
			$wpdb->users,
			[
				'user_login'      => 'external_shadow',
				'user_pass'       => 'x',
				'user_nicename'   => 'external-shadow',
				'user_email'      => $this->email( 'ci-shadow' ),
				'user_registered' => current_time( 'mysql', true ),
				'display_name'    => 'external shadow',
			]
		);
		$shadow = (int) $wpdb->insert_id;
		$this->track( $shadow );
		$this->mail_capture();
		$out = HallStaffProvisioningService::create_and_assign( $this->email( 'ci-shadow' ), 'Shadow', Roles::GAME_OPERATOR, $t3 );
		$this->mail_restore();
		$this->ok( is_wp_error( $out ) && 'identity_state_anomaly' === $out->get_error_code(), 'competing identity with signed email -> identity_state_anomaly -> ' . $this->res( $out ) );
		$this->ok( 0 === $this->mails && 0 === $this->membership_count( $id3 ), '  no assignment, no invitation' );
		$this->ok( get_userdata( $shadow ) instanceof WP_User, '  competing identity untouched' );

		// Deleted identity.
		$id4 = $this->provision_to_audit_failure( 'ci-gone', $t4, $i4 );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->users} WHERE ID = %d", $id4 ) );
		clean_user_cache( $id4 );
		$this->mail_capture();
		$out = HallStaffProvisioningService::create_and_assign( $this->email( 'ci-gone' ), 'Gone', Roles::GAME_OPERATOR, $t4 );
		$this->mail_restore();
		$this->ok( is_wp_error( $out ) && 'identity_state_anomaly' === $out->get_error_code(), 'deleted identity -> identity_state_anomaly -> ' . $this->res( $out ) );
		$this->ok( 0 === $this->mails, '  no invitation' );

		// A success result naming a different user can never reach invitation.
		$svc = file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-core/src/HallStaffProvisioningService.php' );
		$this->ok(
			str_contains( $svc, '$result->target_user_id !== $intent->created_user_id' ),
			'assign_and_invite asserts result target === created_user_id before invitation'
		);
		$id5 = $this->provision_to_audit_failure( 'ci-wrongtarget', $t5, $i5 );
		$ctx = new HallStaffProvisioningContext( $id5, get_current_network_id(), self::SUNRISE, 1, Roles::GAME_OPERATOR );
		$this->mail_capture();
		$sent = HallStaffInvitation::maybe_send_automatic( $ctx, $id5 + 999, HallStaffResult::COMMITTED_VERIFIED );
		$this->mail_restore();
		$this->ok( false === $sent && 0 === $this->mails, '  invitation for a non-covered target suppressed' );
	}

	/** Marker reads and writes must fail closed. */
	private function marker_fail_closed(): void {
		$this->group( 'Invitation marker fail-closed' );
		$this->actor( 1, self::SUNRISE );
		$email = 'ci-marker@test.local';

		$this->mail_capture();
		$made = HallStaffProvisioningService::create_and_assign( $email, 'Marker', Roles::GAME_OPERATOR );
		$this->mail_restore();
		$id = $this->id_by_email( $email );
		$this->track( $id );
		$this->ok( $made instanceof HallStaffResult, 'setup: provisioned + invited -> ' . $this->res( $made ) );

		$ctx = new HallStaffProvisioningContext( $id, get_current_network_id(), self::SUNRISE, 1, Roles::GAME_OPERATOR );

		// Marker SELECT failure -> automatic send fails closed.
		$this->db_query( 'DELETE FROM ' . $GLOBALS['wpdb']->usermeta . " WHERE user_id = {$id} AND meta_key = '" . HallStaffInvitation::ISSUED_META_KEY . "'" );
		clean_user_cache( $id );
		$this->mail_capture();
		$this->break_query( HallStaffInvitation::ISSUED_META_KEY );
		$sent = HallStaffInvitation::maybe_send_automatic( $ctx, $id, HallStaffResult::COMMITTED_VERIFIED );
		$this->restore_query();
		$this->mail_restore();
		$this->ok( is_wp_error( $sent ) && 'invitation_marker_unreadable' === $sent->get_error_code(), 'marker SELECT failure -> automatic send fails closed -> ' . $this->res( $sent ) );
		$this->ok( 0 === $this->mails, '  no mail' );

		// Marker SELECT failure -> Resend fails closed, cannot bypass the throttle.
		$this->mail_capture();
		$this->break_query( HallStaffInvitation::ISSUED_META_KEY );
		$resent = HallStaffInvitation::resend( $id );
		$this->restore_query();
		$this->mail_restore();
		$this->ok( is_wp_error( $resent ) && 'invitation_marker_unreadable' === $resent->get_error_code(), 'marker SELECT failure -> Resend fails closed -> ' . $this->res( $resent ) );
		$this->ok( 0 === $this->mails, '  no mail, throttle not bypassed' );

		// Existing OLD marker + forced update failure -> Resend sends zero mail.
		$this->db_query( 'DELETE FROM ' . $GLOBALS['wpdb']->usermeta . " WHERE user_id = {$id} AND meta_key = '" . HallStaffInvitation::ISSUED_META_KEY . "'" );
		$this->db_query( 'INSERT INTO ' . $GLOBALS['wpdb']->usermeta . " (user_id, meta_key, meta_value) VALUES ({$id}, '" . HallStaffInvitation::ISSUED_META_KEY . "', '1')" );
		clean_user_cache( $id );
		$this->mail_capture();
		$this->block_meta( HallStaffInvitation::ISSUED_META_KEY );
		$resent = HallStaffInvitation::resend( $id );
		$this->unblock_meta();
		$this->mail_restore();
		$this->ok( is_wp_error( $resent ) && HallStaffInvitation::MARKER_UNWRITABLE === $resent->get_error_code(), 'old marker + update failure -> marker_unwritable -> ' . $this->res( $resent ) );
		$this->ok( 0 === $this->mails, '  ZERO mail (stale non-empty marker never accepted as our claim)' );
		$this->ok( '1' === (string) $this->db_var( 'SELECT meta_value FROM ' . $GLOBALS['wpdb']->usermeta . " WHERE user_id = {$id} AND meta_key = '" . HallStaffInvitation::ISSUED_META_KEY . "'" ), '  stale marker unchanged' );

		// Confirmation must equal the newly claimed timestamp.
		$src = file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-core/src/HallStaffInvitation.php' );
		$this->ok( str_contains( $src, '$now !== $confirmed' ), 'claim confirmation compares against the exact new timestamp, not merely non-empty' );
	}

	/**
	 * An existing identity must be resolved UNIQUELY or not at all.
	 *
	 * bc_users has no unique index on user_email/user_login, so a colliding row
	 * created by a concurrent or external writer must never let a later request
	 * arbitrarily pick one of them and grant it a protected hall role.
	 */
	private function unique_identity_resolution(): void {
		$this->group( 'Unique existing-identity resolution' );
		global $wpdb;

		// 6. Baseline: a normal unique identity still assigns.
		$ok_id = $this->fresh_user( 'uniqok' );
		$this->actor( 1, self::SUNRISE );
		$this->mail_capture();
		$ok = HallStaffService::assign_existing_user( $this->email( 'uniqok' ), Roles::GAME_OPERATOR );
		$this->mail_restore();
		$this->ok( $ok instanceof HallStaffResult, 'unique existing identity still assigns -> ' . $this->res( $ok ) );
		$this->ok( Roles::GAME_OPERATOR === $this->role_on( $ok_id, self::SUNRISE ), '  role granted' );

		// 1. Duplicate detected during Create/Invite, then the SAME email resubmitted.
		$this->actor( 1, self::SUNRISE );
		$email = 'ci-uniq1@test.local';
		$this->mail_capture();
		$this->inject_competitor( 'email', $email );
		$first = HallStaffProvisioningService::create_and_assign( $email, 'Uniq One', Roles::GAME_OPERATOR );
		$this->restore_competitor();
		$this->mail_restore();
		$competitor = $this->competitor_id;
		$ours       = $this->newest_user_with( 'email', $email, $competitor );
		$this->track( $competitor );
		$this->track( $ours );
		$this->ok( is_wp_error( $first ) && HallStaffProvisioningService::DUPLICATE_DETECTED === $first->get_error_code(), 'Create/Invite quarantines the duplicate -> ' . $this->res( $first ) );

		$rows = (int) $this->db_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email = '" . esc_sql( $email ) . "'" );
		$this->ok( 2 === $rows, '  two accounts now share the email' );

		$this->mail_capture();
		$again = HallStaffProvisioningService::create_and_assign( $email, 'Uniq One', Roles::GAME_OPERATOR );
		$this->mail_restore();
		$this->ok( is_wp_error( $again ) && 'duplicate_identity_conflict' === $again->get_error_code(), 'resubmitting the same email STILL fails closed -> ' . $this->res( $again ) );
		$this->ok( 0 === $this->membership_count( $ours ) && 0 === $this->membership_count( $competitor ), '  both identities remain UNASSIGNED' );
		$this->ok( 0 === $this->mails, '  no invitation' );
		$this->ok( 2 === (int) $this->db_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email = '" . esc_sql( $email ) . "'" ), '  no third account created' );

		// 2. Pre-existing duplicate email -> generic Assign Existing User.
		$this->mail_capture();
		$generic = HallStaffService::assign_existing_user( $email, Roles::GAME_OPERATOR );
		$this->mail_restore();
		$this->ok( is_wp_error( $generic ) && 'duplicate_identity_conflict' === $generic->get_error_code(), 'generic assignment on duplicate email fails closed -> ' . $this->res( $generic ) );
		$this->ok( 0 === $this->membership_count( $ours ) && 0 === $this->membership_count( $competitor ), '  neither account mutated' );

		// 3. Unique email but duplicate user_login.
		$a = $this->fresh_user( 'uniqlogin' );
		$shared = get_userdata( $a )->user_login;
		$wpdb->insert(
			$wpdb->users,
			[
				'user_login'      => $shared,
				'user_pass'       => 'x',
				'user_nicename'   => $shared . '-2',
				'user_email'      => 'ci-uniqlogin-other@test.local',
				'user_registered' => current_time( 'mysql', true ),
				'display_name'    => $shared,
			]
		);
		$twin = (int) $wpdb->insert_id;
		$this->track( $twin );
		$this->actor( 1, self::SUNRISE );
		$this->mail_capture();
		$out = HallStaffService::assign_existing_user( $this->email( 'uniqlogin' ), Roles::GAME_OPERATOR );
		$this->mail_restore();
		$this->ok( is_wp_error( $out ) && 'duplicate_identity_conflict' === $out->get_error_code(), 'unique email + duplicate user_login fails closed -> ' . $this->res( $out ) );
		$this->ok( 0 === $this->membership_count( $a ) && 0 === $this->membership_count( $twin ), '  neither account mutated' );

		// 4. Valid retry token, duplicate appears before the retry.
		$r_id = $this->fresh_user( 'uniqretry' );
		$this->actor( 1, self::SUNRISE );
		$this->audit_trigger_on( 'hall_staff.assigned' );
		$failed = HallStaffService::assign_existing_user( $this->email( 'uniqretry' ), Roles::GAME_OPERATOR );
		$this->audit_trigger_off();
		$token = $this->data( $failed, 'retry_token' );
		$this->ok( is_wp_error( $failed ) && is_string( $token ), 'setup: valid assignment retry token -> ' . $this->res( $failed ) );

		$wpdb->insert(
			$wpdb->users,
			[
				'user_login'      => 'external_uniqretry',
				'user_pass'       => 'x',
				'user_nicename'   => 'external-uniqretry',
				'user_email'      => $this->email( 'uniqretry' ),
				'user_registered' => current_time( 'mysql', true ),
				'display_name'    => 'external uniqretry',
			]
		);
		$dup = (int) $wpdb->insert_id;
		$this->track( $dup );
		$before = $this->witness_count_for_target( 'hall_staff.assigned', $r_id );
		$this->mail_capture();
		$retry = HallStaffService::assign_existing_user( $this->email( 'uniqretry' ), Roles::GAME_OPERATOR, $token );
		$this->mail_restore();
		$this->ok( is_wp_error( $retry ) && 'duplicate_identity_conflict' === $retry->get_error_code(), 'retry rejected once a duplicate appears -> ' . $this->res( $retry ) );
		$this->ok( 0 === $this->membership_count( $r_id ) && 0 === $this->membership_count( $dup ), '  no mutation on either account' );
		$this->ok( $before === $this->witness_count_for_target( 'hall_staff.assigned', $r_id ), '  never entered ADR-025 execution' );
		$this->ok( 0 === $this->mails, '  no invitation' );

		// 5. Expected target A, but the email now resolves uniquely to B.
		$b_id = $this->fresh_user( 'uniqexp' );
		$this->actor( 1, self::SUNRISE );
		$this->mail_capture();
		$mismatched = HallStaffService::assign_existing_user( $this->email( 'uniqexp' ), Roles::GAME_OPERATOR, null, $b_id + 4242 );
		$this->mail_restore();
		$this->ok( is_wp_error( $mismatched ) && 'identity_state_anomaly' === $mismatched->get_error_code(), 'expected target A but email resolves to B -> identity_state_anomaly -> ' . $this->res( $mismatched ) );
		$this->ok( 0 === $this->membership_count( $b_id ), '  B must NOT receive a hall role' );
		$this->ok( 0 === $this->mails, '  no invitation' );

		$src = file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-core/src/HallStaffProvisioningService.php' );
		$this->ok( str_contains( $src, '$intent->created_user_id' ) && str_contains( $src, 'assign_existing_user(' ), 'provisioning passes created_user_id as the expected target' );
		$svc = file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-core/src/HallStaffService.php' );
		$this->ok( ! str_contains( $svc, "get_user_by( 'email'" ), 'HallStaffService no longer uses cached get_user_by(email) as the resolver' );
	}

	/**
	 * GENUINE ADR-025 ambiguous commit.
	 *
	 * The transport reconciles after EVERY commit (run() line ~172: acknowledged
	 * and ambiguous COMMIT use the same witness authority). So: let the audit
	 * INSERT and COMMIT genuinely succeed, then make the post-commit witness read
	 * unreadable. A concurrent RENAME is queued behind the transaction's metadata
	 * lock and lands the instant the COMMIT releases it — the data is durably
	 * committed, but the transport cannot verify it and reports OUTCOME_UNKNOWN.
	 * Nothing is fabricated: both tokens come from that real response.
	 */
	private function genuine_ambiguous_commit(): void {
		$this->group( 'Genuine ADR-025 ambiguous commit' );
		global $wpdb;
		$worker = dirname( __DIR__ ) . '/tests/hall-staff-ambiguous-worker.php';
		$table  = Migrations::audit_log_table();

		$attempt  = 0;
		$response = null;
		$email    = '';

		while ( $attempt < 4 && null === $response ) {
			$attempt++;
			$email = "ci-amb{$attempt}@test.local";

			// SLEEP inside the transaction opens the window; the transaction holds
			// the table's metadata lock, so RENAME must wait for the COMMIT.
			$wpdb->query( "DROP TRIGGER IF EXISTS bc_ci_slow" );
			$wpdb->query( "CREATE TRIGGER bc_ci_slow BEFORE INSERT ON {$table} FOR EACH ROW BEGIN IF NEW.action = 'hall_staff.assigned' THEN SET @x = (SELECT SLEEP(2)); END IF; END" );

			$start = microtime( true ) + 1.0;
			$cmd   = sprintf( 'php %s %s %.6f 2>&1', escapeshellarg( $worker ), escapeshellarg( $email ), $start );
			$proc  = proc_open( $cmd, [ 1 => [ 'pipe', 'w' ] ], $pipes );

			// Issue the RENAME while the transaction is still sleeping. It blocks on
			// the table's metadata lock and is granted the instant COMMIT releases
			// it — so the table disappears immediately after the commit lands and
			// before the transport's post-commit reconcile can read the witness.
			usleep( (int) ( ( $start - microtime( true ) ) * 1000000 ) + 900000 );
			$wpdb->suppress_errors( true );
			$wpdb->query( "RENAME TABLE {$table} TO {$table}_amb" );
			// Hold the table away long enough for reconcile (and its one reconnect
			// retry) to run against a missing table, then restore it.
			usleep( 750000 );
			$wpdb->query( "RENAME TABLE {$table}_amb TO {$table}" );
			$wpdb->suppress_errors( false );

			$raw = trim( stream_get_contents( $pipes[1] ) );
			fclose( $pipes[1] );
			proc_close( $proc );
			$wpdb->query( 'DROP TRIGGER IF EXISTS bc_ci_slow' );

			// The worker also emits error_log() lines on stdout; the JSON is last.
			$lines   = array_values( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) );
			$decoded = json_decode( (string) end( $lines ), true );
			if ( is_array( $decoded ) && 'OUTCOME_UNKNOWN' === ( $decoded['status'] ?? '' ) ) {
				$response = $decoded;
			}
			$this->track( $this->id_by_email( $email ) );
		}

		if ( null === $response ) {
			$this->ok( false, 'could not reach a genuine ambiguous commit in ' . $attempt . ' attempts (timing-dependent seam)' );
			return;
		}

		$id = $this->id_by_email( $email );
		$this->ok( true, "genuine OUTCOME_UNKNOWN reached on attempt {$attempt}" );
		$this->ok( 1 === $this->witness_count_for_target( 'hall_staff.assigned', $id ), 'the assignment transaction REALLY committed (one assignment witness)' );
		$this->ok( Roles::GAME_OPERATOR === $this->role_on( $id, self::SUNRISE ), '  and the protected role is durably present' );
		$this->ok( '' === (string) get_user_meta( $id, HallStaffInvitation::ISSUED_META_KEY, true ), '  no invitation was issued' );

		$at = $response['retry_token'] ?? '';
		$ct = $response['creation_retry_token'] ?? '';
		$this->ok( is_string( $at ) && '' !== $at && ! is_wp_error( HallStaffRetryToken::verify( $at ) ), 'response carries an AUTHENTIC Phase A retry token' );
		$this->ok( is_string( $ct ) && '' !== $ct && ! is_wp_error( HallStaffCreationRetryToken::verify( $ct ) ), 'response carries an AUTHENTIC creation retry token' );

		$ci = HallStaffCreationRetryToken::verify( $ct );
		$w  = AuditLog::find_operation_witness( $ci->operation_id );
		$this->ok( is_array( $w ) && $ci->matches_witness( $w ), 'creation witness matches the signed creation intent' );

		$before = $this->witness_count_for_target( 'hall_staff.assigned', $id );
		$this->actor( 1, self::SUNRISE );
		$this->mail_capture();
		$out = HallStaffProvisioningService::create_and_assign( $email, 'Fixture', Roles::GAME_OPERATOR, $ct, $at );
		$this->mail_restore();

		$this->ok( $out instanceof HallStaffResult && HallStaffResult::IDEMPOTENT_REPLAY_SUCCESS === $out->status, 'Phase A reconciliation -> IDEMPOTENT_REPLAY_SUCCESS -> ' . $this->res( $out ) );
		$this->ok( 1 === $this->mails, 'exactly one first invitation' );
		$this->ok( $before === $this->witness_count_for_target( 'hall_staff.assigned', $id ), 'exactly one assignment witness (no duplicate)' );
	}

	/** Two concurrent claims must never both send. */
	private function invitation_concurrency(): void {
		$this->group( 'Invitation concurrency' );
		$worker = dirname( __DIR__ ) . '/tests/hall-staff-invitation-worker.php';

		// Automatic invitation: two concurrent attempts, marker absent.
		$this->actor( 1, self::SUNRISE );
		$email = 'ci-invrace@test.local';
		$this->mail_capture();
		$this->block_meta( HallStaffInvitation::ISSUED_META_KEY );
		HallStaffProvisioningService::create_and_assign( $email, 'Inv Race', Roles::GAME_OPERATOR );
		$this->unblock_meta();
		$this->mail_restore();
		$id = $this->id_by_email( $email );
		$this->track( $id );
		delete_user_meta( $id, HallStaffInvitation::ISSUED_META_KEY );

		$out  = $this->race2( $worker, [ 'auto', $id ], [ 'auto', $id ] );
		$all  = implode( ' | ', $out );
		$sent = substr_count( $all, 'SENT' );
		$this->ok( 1 === $sent, 'two concurrent automatic invitations -> exactly one mail (' . $all . ')' );
		// The loser must be SUPPRESSED by the in-lock re-read, never rescued by
		// update_user_meta happening to return false on an identical timestamp.
		$this->ok( 1 === substr_count( $all, 'SUPPRESSED' ), '  loser SUPPRESSED by the authoritative in-lock re-read (' . $all . ')' );
		$this->ok( 0 === substr_count( $all, 'marker_unwritable' ), '  never resolved via an unchanged-value coincidence' );
		$this->ok( 1 === $this->marker_rows( $id ), '  exactly one marker row' );

		// Resend: two concurrent attempts must not both pass the 60s throttle.
		$this->db_query( 'DELETE FROM ' . $GLOBALS['wpdb']->usermeta . " WHERE user_id = {$id} AND meta_key = '" . HallStaffInvitation::ISSUED_META_KEY . "'" );
		clean_user_cache( $id );
		$out  = $this->race2( $worker, [ 'resend', $id ], [ 'resend', $id ] );
		$all  = implode( ' | ', $out );
		$sent = substr_count( $all, 'SENT' );
		$this->ok( 1 === $sent, 'two concurrent Resend attempts -> exactly one mail (' . $all . ')' );
		$this->ok( 1 === substr_count( $all, 'ERR:invitation_throttled' ), '  loser blocked by the throttle inside the lock (' . $all . ')' );
	}

	private function race2( string $worker, array $one, array $two ): array {
		$start = microtime( true ) + 1.0;
		$procs = [];
		$pipes = [];
		foreach ( [ $one, $two ] as $i => $args ) {
			$cmd = sprintf( 'php %s %s %d %.6f 2>&1', escapeshellarg( $worker ), escapeshellarg( $args[0] ), $args[1], $start );
			$procs[ $i ] = proc_open( $cmd, [ 1 => [ 'pipe', 'w' ] ], $pipes[ $i ] );
		}
		$out = [];
		foreach ( $procs as $i => $proc ) {
			$out[] = trim( stream_get_contents( $pipes[ $i ][1] ) );
			fclose( $pipes[ $i ][1] );
			proc_close( $proc );
		}
		wp_cache_flush();
		return $out;
	}

	private function ui_copy(): void {
		$this->group( 'UI copy' );
		$src = file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-core/src/HallStaffProvisioningService.php' );
		$bad = preg_match( '/(database|SQL|phpMyAdmin|manually edit)/i', $src, $m );
		$offending = [];
		foreach ( [ 'Retry or contact Platform Admin', 'Contact Platform Admin' ] as $needle ) {
			$offending[ $needle ] = substr_count( $src, $needle );
		}
		$this->ok( array_sum( $offending ) >= 5, 'recovery messages offer "Retry or contact Platform Admin" (' . array_sum( $offending ) . ' occurrences)' );
		$this->ok(
			! preg_match( '/edit the database|run SQL|phpMyAdmin|manually (edit|repair) the/i', $src ),
			'no recovery message recommends a manual database edit'
		);
	}

	/**
	 * The assignment has already committed for NOT_COMPLETED and
	 * MARKER_UNWRITABLE — retrying it is the wrong recovery action even though
	 * the underlying WP_Error still legitimately carries both retry tokens for
	 * API/service-layer callers. The UI must not render "Retry the same
	 * operation" for either code; it must point at Resend Invitation instead.
	 * A genuine retryable provisioning error must still render the retry form.
	 *
	 * Exercised via reflection + output buffering against the private
	 * render_notice() — acceptable for this CLI harness; production visibility
	 * of the method is unchanged (still private).
	 */
	private function ui_no_retry_on_invitation_failure(): void {
		$this->group( 'UI: no retry form on invitation failure' );

		$render = new \ReflectionMethod( HallStaffAdmin::class, 'render_notice' );
		$render->setAccessible( true );

		$capture = function ( $result, array $posted ) use ( $render ): string {
			ob_start();
			$render->invoke( null, $result, $posted );
			return (string) ob_get_clean();
		};

		$posted_create = [
			'bc_hall_staff_action' => 'create',
			'email'                => 'ci-uinorc@test.local',
			'display_name'         => 'UI No Retry',
			'role'                 => Roles::GAME_OPERATOR,
		];

		// NOT_COMPLETED, carrying both authentic-shaped tokens.
		$not_completed = new \WP_Error(
			HallStaffInvitation::NOT_COMPLETED,
			'Staff access was created, but the invitation could not be completed. Use Resend Invitation.',
			[
				'status'               => HallStaffInvitation::NOT_COMPLETED,
				'retry_token'          => 'fake.assignment.token',
				'creation_retry_token' => 'fake.creation.token',
			]
		);
		$html = $capture( $not_completed, $posted_create );
		$this->ok( str_contains( $html, 'invitation could not be completed' ), 'NOT_COMPLETED: error message rendered' );
		$this->ok( ! str_contains( $html, 'Retry the same operation' ), 'NOT_COMPLETED: no "Retry the same operation" despite both tokens present' );
		$this->ok( str_contains( $html, 'Resend invitation' ), 'NOT_COMPLETED: directs Hall Manager to Resend invitation' );

		// MARKER_UNWRITABLE, carrying both authentic-shaped tokens.
		$marker_unwritable = new \WP_Error(
			HallStaffInvitation::MARKER_UNWRITABLE,
			'Staff access was created, but the invitation could not be started. Use Resend Invitation.',
			[
				'status'               => HallStaffInvitation::MARKER_UNWRITABLE,
				'retry_token'          => 'fake.assignment.token',
				'creation_retry_token' => 'fake.creation.token',
			]
		);
		$html = $capture( $marker_unwritable, $posted_create );
		$this->ok( str_contains( $html, 'invitation could not be started' ), 'MARKER_UNWRITABLE: error message rendered' );
		$this->ok( ! str_contains( $html, 'Retry the same operation' ), 'MARKER_UNWRITABLE: no "Retry the same operation" despite both tokens present' );
		$this->ok( str_contains( $html, 'Resend invitation' ), 'MARKER_UNWRITABLE: directs Hall Manager to Resend invitation' );

		// A genuine retryable provisioning error must still render the retry form.
		$retryable = new \WP_Error(
			HallStaffProvisioningService::AUDIT_UNRECORDED,
			'The account was created but its audit record could not be saved. No hall access has been granted. Retry or contact Platform Admin.',
			[
				'status'               => HallStaffProvisioningService::AUDIT_UNRECORDED,
				'creation_retry_token' => 'fake.creation.token',
			]
		);
		$html = $capture( $retryable, $posted_create );
		$this->ok( str_contains( $html, 'Retry the same operation' ), 'genuine retryable error still renders "Retry the same operation"' );

		// Confirm this is not merely tautological: a real invitation failure from
		// the live service, rendered through the private method, behaves the same.
		$this->actor( 1, self::SUNRISE );
		$this->mail_capture();
		$this->block_meta( HallStaffInvitation::ISSUED_META_KEY );
		$real = HallStaffProvisioningService::create_and_assign( 'ci-uinorc2@test.local', 'UI Live', Roles::GAME_OPERATOR );
		$this->unblock_meta();
		$this->mail_restore();
		$id = $this->id_by_email( 'ci-uinorc2@test.local' );
		$this->track( $id );
		$this->ok( is_wp_error( $real ) && HallStaffInvitation::MARKER_UNWRITABLE === $real->get_error_code(), 'setup: live MARKER_UNWRITABLE reproduced -> ' . $this->res( $real ) );

		$html = $capture( $real, [ 'bc_hall_staff_action' => 'create', 'email' => 'ci-uinorc2@test.local', 'display_name' => 'UI Live', 'role' => Roles::GAME_OPERATOR ] );
		$this->ok( ! str_contains( $html, 'Retry the same operation' ), 'live MARKER_UNWRITABLE response: no retry form rendered' );
		$this->ok( str_contains( $html, 'Resend invitation' ), 'live MARKER_UNWRITABLE response: directs to Resend invitation' );
	}

	private function concurrency(): void {
		$this->group( 'Concurrency' );
		$worker = dirname( __DIR__ ) . '/tests/hall-staff-create-invite-worker.php';

		// Same email, two workers.
		$email = 'ci-race@test.local';
		$out   = $this->race( $worker, [ $email, 'Race One', Roles::GAME_OPERATOR ], [ $email, 'Race Two', Roles::GAME_OPERATOR ] );
		$count = (int) $this->db_var( 'SELECT COUNT(*) FROM ' . $GLOBALS['wpdb']->users . " WHERE user_email = '" . esc_sql( $email ) . "'" );
		$this->track( $this->id_by_email( $email ) );
		$this->ok( 1 === $count, 'same-email race -> exactly one identity created (' . implode( ' | ', $out ) . ')' );

		// Same username, different emails.
		$a = 'ci-twin@a-test.local';
		$b = 'ci-twin@b-test.local';
		$out = $this->race( $worker, [ $a, 'Twin A', Roles::GAME_OPERATOR ], [ $b, 'Twin B', Roles::GAME_OPERATOR ] );
		$ida = $this->id_by_email( $a );
		$idb = $this->id_by_email( $b );
		$this->track( $ida );
		$this->track( $idb );
		$la = $ida ? get_userdata( $ida )->user_login : '';
		$lb = $idb ? get_userdata( $idb )->user_login : '';
		$this->ok( $ida > 0 && $idb > 0 && $la !== $lb, "same-username race -> distinct logins ({$la} / {$lb})" );
	}

	/* ----------------------------------------------------------- helpers */

	private function race( string $worker, array $one, array $two ): array {
		$start = microtime( true ) + 1.0;
		$procs = [];
		$pipes = [];
		foreach ( [ $one, $two ] as $i => $args ) {
			$cmd = sprintf( 'php %s %s %s %s %.6f 2>&1', escapeshellarg( $worker ), escapeshellarg( $args[0] ), escapeshellarg( $args[1] ), escapeshellarg( $args[2] ), $start );
			$procs[ $i ] = proc_open( $cmd, [ 1 => [ 'pipe', 'w' ] ], $pipes[ $i ] );
		}
		$out = [];
		foreach ( $procs as $i => $proc ) {
			$out[] = trim( stream_get_contents( $pipes[ $i ][1] ) );
			fclose( $pipes[ $i ][1] );
			proc_close( $proc );
		}
		wp_cache_flush();
		return $out;
	}

	private function provision_to_audit_failure( string $slug, &$token, &$intent, ?string $role = null ): int {
		$role = $role ?? Roles::GAME_OPERATOR;
		$this->actor( 1, self::SUNRISE );
		$this->mail_capture();
		$this->audit_trigger_on( 'hall_staff.user_created' );
		$r = HallStaffProvisioningService::create_and_assign( $this->email( $slug ), 'Fixture', $role );
		$this->audit_trigger_off();
		$this->mail_restore();
		$id = $this->id_by_email( $this->email( $slug ) );
		$this->track( $id );
		$token  = $this->data( $r, 'creation_retry_token' );
		$intent = is_string( $token ) ? HallStaffCreationRetryToken::verify( $token ) : null;
		return $id;
	}

	/**
	 * Provision fully (creation witness IS written) but make the ADR-025
	 * assignment audit fail, so the response carries BOTH authentic tokens and
	 * shape (b) can reach the cross-check and Phase A reconciliation.
	 */
	private function provision_to_assignment_failure( string $slug, &$creation_token, &$assignment_token, &$intent ): int {
		$this->actor( 1, self::SUNRISE );
		$this->mail_capture();
		$this->audit_trigger_on( 'hall_staff.assigned' );
		$r = HallStaffProvisioningService::create_and_assign( $this->email( $slug ), 'Fixture', Roles::GAME_OPERATOR );
		$this->audit_trigger_off();
		$this->mail_restore();

		$id = $this->id_by_email( $this->email( $slug ) );
		$this->track( $id );
		$creation_token   = $this->data( $r, 'creation_retry_token' );
		$assignment_token = $this->data( $r, 'retry_token' );
		$intent           = is_string( $creation_token ) ? HallStaffCreationRetryToken::verify( $creation_token ) : null;
		return $id;
	}

	private function creation_token_for( int $user_id, string $email, string $role ): string {
		$issued = time();
		return HallStaffCreationRetryToken::issue(
			new HallStaffCreationIntent(
				wp_generate_uuid4(),
				get_current_network_id(),
				self::SUNRISE,
				1,
				$user_id,
				$email,
				get_userdata( $user_id )->user_login,
				HallStaffCreationIntent::IDENTITY_TYPE,
				$role,
				$issued,
				$issued + 3600
			)
		);
	}

	private function phase_a_intent( int $target, array $o = [] ): \BingoCommerce\Core\HallStaffIntent {
		$issued = time();
		return new \BingoCommerce\Core\HallStaffIntent(
			wp_generate_uuid4(),
			$o['network'] ?? get_current_network_id(),
			$o['blog'] ?? self::SUNRISE,
			$o['actor'] ?? 1,
			$o['target'] ?? $target,
			$o['action'] ?? 'hall_staff.assigned',
			$o['before'] ?? null,
			array_key_exists( 'after', $o ) ? $o['after'] : Roles::GAME_OPERATOR,
			false,
			$issued,
			$issued + 3600
		);
	}

	private function email( string $slug ): string {
		return $slug . '@test.local';
	}

	private function fresh_user( string $slug ): int {
		if ( ms_is_switched() ) {
			restore_current_blog();
		}
		$id = wp_insert_user(
			[
				'user_login' => 'ci_' . $slug,
				'user_email' => $this->email( $slug ),
				'user_pass'  => wp_generate_password( 24 ),
			]
		);
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		$this->track( (int) $id );
		$this->wipe_caps( (int) $id );
		return (int) $id;
	}

	private function track( int $id ): void {
		if ( $id > 0 && ! in_array( $id, $this->users, true ) ) {
			$this->users[] = $id;
		}
	}

	private function actor( int $uid, int $blog ): void {
		if ( get_current_blog_id() !== $blog ) {
			if ( ms_is_switched() ) {
				restore_current_blog();
			}
			switch_to_blog( $blog );
		}
		wp_set_current_user( 0 );
		wp_set_current_user( $uid );
	}

	private function denied( int $id ): bool {
		$r = HallStaffEligibility::assert_operational( $id );
		return is_wp_error( $r ) && 'non_operational_identity_not_eligible' === $r->get_error_code();
	}

	private function caps_key( int $blog ): string {
		global $wpdb;
		return $wpdb->get_blog_prefix( $blog ) . 'capabilities';
	}

	private function plant_caps( int $id, int $blog, array $value ): void {
		$this->plant_raw( $id, $blog, serialize( $value ) );
	}

	private function plant_raw( int $id, int $blog, string $raw ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value) VALUES (%d, %s, %s)", $id, $this->caps_key( $blog ), $raw ) );
		clean_user_cache( $id );
	}

	private function wipe_caps( int $id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key REGEXP %s", $id, '^' . $wpdb->base_prefix . '([0-9]+_)?(capabilities|user_level)$' ) );
		clean_user_cache( $id );
	}

	private function raw_caps( int $id ): string {
		global $wpdb;
		return (string) wp_json_encode(
			$wpdb->get_results( $wpdb->prepare( "SELECT umeta_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d ORDER BY umeta_id", $id ), ARRAY_A )
		);
	}

	private function raw_caps_for( int $id, string $key ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $id, $key ), ARRAY_A );
	}

	private function marker_rows( int $id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $id, HallStaffInvitation::ISSUED_META_KEY ) );
	}

	private function membership_count( int $id ): int {
		$c = HallStaffEligibility::count_memberships( $id );
		return is_wp_error( $c ) ? -1 : $c;
	}

	private function membership_snapshot(): array {
		global $wpdb;
		return $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key REGEXP '^{$wpdb->base_prefix}([0-9]+_)?capabilities$' ORDER BY user_id, meta_key", ARRAY_A );
	}

	private function role_on( int $id, int $blog ): ?string {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $id, $this->caps_key( $blog ) ) );
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$v = @unserialize( $raw, [ 'allowed_classes' => false ] );
		return is_array( $v ) ? (string) array_key_first( $v ) : null;
	}

	private function id_by_email( string $email ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_email = %s ORDER BY ID DESC LIMIT 1", $email ) );
	}

	private function newest_user_with( string $mode, string $email, int $exclude ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_email = %s AND ID <> %d ORDER BY ID DESC LIMIT 1", $email, $exclude ) )
			?: (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID <> %d ORDER BY ID DESC LIMIT 1", $exclude ) );
	}

	private function witness_count_for_target( string $action, int $target ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::audit_log_table() . ' WHERE action = %s AND target_id = %s', $action, (string) $target ) );
	}

	private function witness_row( string $action, int $target ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Migrations::audit_log_table() . ' WHERE action = %s AND target_id = %s ORDER BY id DESC LIMIT 1', $action, (string) $target ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	private function witness_row_by_op( string $op ): array {
		global $wpdb;
		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Migrations::audit_log_table() . ' WHERE operation_id = %s', $op ), ARRAY_A );
	}

	private function restore_witness( string $op, array $row ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Migrations::audit_log_table() . ' SET blog_id=%d, actor_user_id=%d, action=%s, target_type=%s, target_id=%s, previous_value=%s, new_value=%s, context=%s WHERE operation_id=%s',
				$row['blog_id'],
				$row['actor_user_id'],
				$row['action'],
				$row['target_type'],
				$row['target_id'],
				$row['previous_value'],
				$row['new_value'],
				$row['context'],
				$op
			)
		);
	}

	private function plant_conflicting_witness( string $op, int $target ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . Migrations::audit_log_table() . ' (operation_id, blog_id, actor_user_id, action, target_type, target_id, new_value, context, created_at) VALUES (%s,%d,%d,%s,%s,%s,%s,%s,%s)',
				$op,
				self::TAMPA,
				1,
				'hall_staff.user_created',
				'user',
				(string) $target,
				'{"email":"wrong@test.local","user_login":"wrong","identity_type":"staff_operational"}',
				'{"intent_version":1}',
				current_time( 'mysql', true )
			)
		);
	}

	private function plant_matching_witness( HallStaffCreationIntent $intent ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . Migrations::audit_log_table() . ' (operation_id, blog_id, actor_user_id, action, target_type, target_id, new_value, context, created_at) VALUES (%s,%d,%d,%s,%s,%s,%s,%s,%s)',
				$intent->operation_id,
				$intent->blog_id,
				$intent->actor_user_id,
				'hall_staff.user_created',
				'user',
				(string) $intent->created_user_id,
				wp_json_encode( $intent->new_value() ),
				wp_json_encode( $intent->audit_context() ),
				current_time( 'mysql', true )
			)
		);
	}

	/* ------------------------------------------------------- test seams */

	private $mail_filter = null;
	private $meta_filter = null;
	private $profile_filter = null;
	private $competitor_filter = null;
	private $vanish_filter = null;

	private function mail_capture(): void {
		$this->mails = 0;
		$this->mail_restore();
		$this->mail_filter = function ( $null, $atts ) {
			$this->mails++;
			return true;
		};
		add_filter( 'pre_wp_mail', $this->mail_filter, 10, 2 );
	}

	private function mail_fail(): void {
		$this->mails = 0;
		$this->mail_restore();
		$this->mail_filter = function ( $null, $atts ) {
			do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'forced test failure' ) );
			return false;
		};
		add_filter( 'pre_wp_mail', $this->mail_filter, 10, 2 );
	}

	private function mail_restore(): void {
		if ( $this->mail_filter ) {
			remove_filter( 'pre_wp_mail', $this->mail_filter, 10 );
			$this->mail_filter = null;
		}
	}

	private function block_meta( string $key ): void {
		$this->unblock_meta();
		$this->meta_filter = function ( $check, $object_id, $meta_key ) use ( $key ) {
			return $meta_key === $key ? false : $check;
		};
		add_filter( 'update_user_metadata', $this->meta_filter, 10, 3 );
	}

	private function unblock_meta(): void {
		if ( $this->meta_filter ) {
			remove_filter( 'update_user_metadata', $this->meta_filter, 10 );
			$this->meta_filter = null;
		}
	}

	private function fail_profile_update(): void {
		$this->restore_profile_update();
		$this->profile_filter = function ( $data, $update ) {
			return $update ? [] : $data;
		};
		add_filter( 'wp_pre_insert_user_data', $this->profile_filter, 10, 2 );
	}

	private function restore_profile_update(): void {
		if ( $this->profile_filter ) {
			remove_filter( 'wp_pre_insert_user_data', $this->profile_filter, 10 );
			$this->profile_filter = null;
		}
	}

	/** Insert a competing row from an external writer that does not honour our lock. */
	private function inject_competitor( string $mode, string $email ): int {
		global $wpdb;
		$this->restore_competitor();
		$this->competitor_id = 0;
		$competitor_id = 0;
		$self = $this;
		$this->competitor_filter = function ( $data, $update ) use ( $mode, $email, &$competitor_id, $wpdb, $self ) {
			if ( $update || $competitor_id > 0 ) {
				return $data;
			}
			$login = 'external_' . wp_generate_password( 6, false, false );
			$mail  = 'external-' . wp_generate_password( 6, false, false ) . '@test.local';
			if ( 'email' === $mode ) {
				$mail = $email;
			} else {
				$login = (string) $data['user_login'];
			}
			$wpdb->insert(
				$wpdb->users,
				[
					'user_login'      => $login,
					'user_pass'       => 'x',
					'user_nicename'   => $login,
					'user_email'      => $mail,
					'user_registered' => current_time( 'mysql', true ),
					'display_name'    => $login,
				]
			);
			$competitor_id = (int) $wpdb->insert_id;
			$self->competitor_id = $competitor_id;
			return $data;
		};
		add_filter( 'wp_pre_insert_user_data', $this->competitor_filter, 10, 2 );
		return 0;
	}

	public int $competitor_id = 0;

	private function restore_competitor(): void {
		if ( $this->competitor_filter ) {
			remove_filter( 'wp_pre_insert_user_data', $this->competitor_filter, 10 );
			$this->competitor_filter = null;
		}
	}

	/** Delete the planted witness the moment the re-read query is issued. */
	/**
	 * Force a genuine UNIQUE(operation_id) collision.
	 *
	 * The first witness read must find nothing (so an INSERT is attempted); a
	 * competing row is planted the instant that INSERT is issued (so it fails
	 * with 1062); then the re-read either finds a MISMATCHING row or finds
	 * nothing at all, depending on $mode. Anything less never exercises the
	 * duplicate-key branch.
	 */
	private function collide_on_insert( HallStaffCreationIntent $intent, string $mode ): void {
		$this->restore_vanish();
		$table   = Migrations::audit_log_table();
		$op      = $intent->operation_id;
		$planted = false;
		$busy    = false;
		$self    = $this;

		$this->vanish_filter = function ( $query ) use ( $table, $op, $mode, $intent, &$planted, &$busy, $self ) {
			if ( $busy ) {
				return $query;
			}

			if ( ! $planted && str_contains( $query, 'INSERT INTO' ) && str_contains( $query, $table ) && str_contains( $query, $op ) ) {
				$busy    = true;
				$planted = true;
				if ( 'mismatch' === $mode ) {
					$self->plant_conflicting_witness_public( $op, $intent->created_user_id );
				} else {
					$self->plant_matching_witness_public( $intent );
				}
				$busy = false;
				return $query;
			}

			if ( 'vanish' === $mode && $planted && str_contains( $query, 'FROM ' . $table ) && str_contains( $query, 'WHERE operation_id' ) ) {
				$busy = true;
				global $wpdb;
				$wpdb->query( 'DELETE FROM ' . $table . " WHERE operation_id = '" . esc_sql( $op ) . "'" );
				$busy = false;
			}

			return $query;
		};
		add_filter( 'query', $this->vanish_filter );
	}

	public function plant_conflicting_witness_public( string $op, int $target ): void {
		$this->plant_conflicting_witness( $op, $target );
	}

	public function plant_matching_witness_public( HallStaffCreationIntent $intent ): void {
		$this->plant_matching_witness( $intent );
	}

	private function restore_vanish(): void {
		if ( $this->vanish_filter ) {
			remove_filter( 'query', $this->vanish_filter );
			$this->vanish_filter = null;
		}
	}

	private $query_filter = null;

	/** Corrupt the first query containing $needle so it genuinely errors. */
	private function break_query( string $needle ): void {
		global $wpdb;
		$this->restore_query();
		$fired = false;
		$this->query_filter = function ( $query ) use ( $needle, &$fired ) {
			if ( ! $fired && str_contains( $query, $needle ) && str_contains( $query, 'SELECT' ) ) {
				$fired = true;
				return 'SELECT * FROM bc_this_table_does_not_exist_xyz';
			}
			return $query;
		};
		add_filter( 'query', $this->query_filter );
		$wpdb->suppress_errors( true );
	}

	/** Make the duplicate-detection read return zero rows. */
	private function empty_duplicate_result(): void {
		global $wpdb;
		$this->restore_query();
		$fired = false;
		$this->query_filter = function ( $query ) use ( &$fired ) {
			if ( ! $fired && str_contains( $query, 'user_email = ' ) && str_contains( $query, 'OR user_login = ' ) ) {
				$fired = true;
				return 'SELECT ID, user_login, user_email FROM ' . $GLOBALS['wpdb']->users . ' WHERE 1 = 0';
			}
			return $query;
		};
		add_filter( 'query', $this->query_filter );
		$wpdb->suppress_errors( true );
	}

	/** Make the duplicate-detection read return exactly one FOREIGN row. */
	private function single_foreign_duplicate_result( int $foreign_id ): void {
		global $wpdb;
		$this->restore_query();
		$fired = false;
		$this->query_filter = function ( $query ) use ( &$fired, $foreign_id ) {
			if ( ! $fired && str_contains( $query, 'user_email = ' ) && str_contains( $query, 'OR user_login = ' ) ) {
				$fired = true;
				return 'SELECT ID, user_login, user_email FROM ' . $GLOBALS['wpdb']->users . ' WHERE ID = ' . (int) $foreign_id;
			}
			return $query;
		};
		add_filter( 'query', $this->query_filter );
		$wpdb->suppress_errors( true );
	}

	private function restore_query(): void {
		global $wpdb;
		if ( $this->query_filter ) {
			remove_filter( 'query', $this->query_filter );
			$this->query_filter = null;
		}
		$wpdb->suppress_errors( false );
	}

	private function audit_trigger_on( string $action ): void {
		global $wpdb;
		$t = Migrations::audit_log_table();
		$wpdb->query( 'DROP TRIGGER IF EXISTS bc_ci_audit' );
		$wpdb->query( "CREATE TRIGGER bc_ci_audit BEFORE INSERT ON {$t} FOR EACH ROW BEGIN IF NEW.action = '" . esc_sql( $action ) . "' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced audit failure'; END IF; END" );
		$wpdb->suppress_errors( true );
	}

	private function audit_trigger_off(): void {
		global $wpdb;
		$wpdb->query( 'DROP TRIGGER IF EXISTS bc_ci_audit' );
		$wpdb->suppress_errors( false );
	}

	private function rename_audit_table( bool $away ): void {
		global $wpdb;
		$t = Migrations::audit_log_table();
		$wpdb->suppress_errors( $away );
		$wpdb->query( $away ? "RENAME TABLE {$t} TO {$t}_ci" : "RENAME TABLE {$t}_ci TO {$t}" );
	}

	/* ------------------------------------------------------- assertions */

	private function group( string $name ): void {
		$this->group = $name;
		$this->groups[ $name ] = $this->groups[ $name ] ?? [ 'pass' => 0, 'fail' => 0, 'lines' => [] ];
		echo "\n===== {$name} =====\n";
	}

	private function ok( bool $cond, string $message ): void {
		$this->groups[ $this->group ][ $cond ? 'pass' : 'fail' ]++;
		if ( ! $cond ) {
			$this->groups[ $this->group ]['lines'][] = $message;
		}
		printf( "  [%s] %s\n", $cond ? 'PASS' : '**FAIL**', $message );
	}

	private function res( $r ): string {
		if ( $r instanceof HallStaffResult ) {
			return 'HallStaffResult(' . $r->status . ')';
		}
		if ( is_wp_error( $r ) ) {
			return 'WP_Error(' . $r->get_error_code() . ')';
		}
		return var_export( $r, true );
	}

	private function data( $r, string $key ) {
		if ( ! is_wp_error( $r ) ) {
			return null;
		}
		$d = $r->get_error_data();
		return is_array( $d ) ? ( $d[ $key ] ?? null ) : null;
	}

	private function db_var( string $sql ) {
		global $wpdb;
		return $wpdb->get_var( $sql );
	}

	private function db_query( string $sql ): void {
		global $wpdb;
		$wpdb->query( $sql );
	}

	/* ---------------------------------------------------------- cleanup */

	private function cleanup(): void {
		global $wpdb;

		$this->mail_restore();
		$this->unblock_meta();
		$this->restore_profile_update();
		$this->restore_competitor();
		$this->restore_vanish();
		$this->restore_query();
		$this->audit_trigger_off();
		$wpdb->suppress_errors( false );

		if ( ms_is_switched() ) {
			restore_current_blog();
		}

		// Purge every ci_ / external_ fixture identity.
		//
		// The Phase A writer guard deliberately blocks wpmu_delete_user() from
		// removing protected capability rows, so the fixture's own memberships
		// are stripped first via direct SQL and the orphan sweep below catches
		// anything the guard refused. Never touches non-fixture users.
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->users} WHERE user_login LIKE 'ci\\_%' OR user_login LIKE 'ci-%' OR user_login LIKE 'external\\_%' OR user_email LIKE 'ci-%@test.local' OR user_email LIKE 'external-%@test.local'" );
		foreach ( array_unique( array_merge( $ids, $this->users ) ) as $id ) {
			$id = (int) $id;
			if ( $id > 0 && 1 !== $id ) {
				$this->wipe_caps( $id );
				wpmu_delete_user( $id );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE user_id = %d", $id ) );
			}
		}

		// Any usermeta whose owner no longer exists is a fixture remnant.
		$wpdb->query( "DELETE m FROM {$wpdb->usermeta} m LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id WHERE u.ID IS NULL" );

		// Test-only synthetic audit rows (planted witnesses only; real events stay).
		$wpdb->query( "DELETE FROM " . Migrations::audit_log_table() . " WHERE new_value LIKE '%wrong@test.local%'" );

		$wpdb->query( 'DROP TRIGGER IF EXISTS bc_ci_audit' );
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'unused' ) );
	}

	private function report(): void {
		global $wpdb;
		echo "\n\n================ SUMMARY ================\n";
		$tp = 0;
		$tf = 0;
		foreach ( $this->groups as $name => $g ) {
			$tp += $g['pass'];
			$tf += $g['fail'];
			printf( "%-42s %s  (%d assertions)\n", $name, 0 === $g['fail'] ? 'PASS' : 'FAIL', $g['pass'] + $g['fail'] );
			foreach ( $g['lines'] as $line ) {
				echo "      failed: {$line}\n";
			}
		}
		printf( "\nTOTAL: %d passed, %d failed\n", $tp, $tf );

		$leftover = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'ci\\_%' OR user_email LIKE 'ci-%@test.local' OR user_email LIKE 'external-%@test.local'" );
		$orphan   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} m LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id WHERE u.ID IS NULL" );
		$trig     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_NAME LIKE 'bc_ci%'" );
		$locks    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM performance_schema.metadata_locks WHERE OBJECT_TYPE='USER LEVEL LOCK'" );
		$tables   = $wpdb->get_col( "SHOW TABLES LIKE '%audit%'" );

		printf( "\nCLEANUP: leftover test users=%d orphan usermeta=%d triggers=%d locks=%d audit tables=%s\n", $leftover, $orphan, $trig, $locks, implode( ',', $tables ) );
		printf( "MEMBERSHIP BASELINE RESTORED: %s\n", wp_json_encode( $this->membership_snapshot() ) === wp_json_encode( $this->baseline_meta ) ? 'YES' : 'NO' );
		printf( "AUDIT ROWS: %d -> %d\n", $this->baseline_audit, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Migrations::audit_log_table() ) );

		echo 0 === $tf ? "\nRESULT: PASS\n" : "\nRESULT: FAIL\n";
	}
}

function clone_intent_with_role( HallStaffCreationIntent $i, string $role ): HallStaffCreationIntent {
	return new HallStaffCreationIntent(
		$i->operation_id,
		$i->network_id,
		$i->blog_id,
		$i->actor_user_id,
		$i->created_user_id,
		$i->normalized_email,
		$i->user_login,
		$i->identity_type,
		$role,
		$i->issued_at,
		$i->expires_at
	);
}

( new CreateInviteTest() )->run();
