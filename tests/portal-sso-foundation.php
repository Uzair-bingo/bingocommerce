<?php
/**
 * ADR-048 Phase 1, using the installed WordPress/WooCommerce and isolated DB.
 * BC_TEST_DB=bingocommerce_state_recon php tests/portal-sso-foundation.php
 * No development migration, Portal request, SSO login or Hall Staff mutation.
 */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
putenv( 'BC_FIXTURE_NETWORK_ACTIVE=1' );
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';

use BingoCommerce\PortalIntegration\SsoAccountGuard as Guard;
use BingoCommerce\PortalIntegration\SsoMigrations as Schema;

final class PortalSsoFoundationTest {
	private int $assertions = 0;
	private array $users = [];
	private array $options = [];
	private bool $owns_schema = false;
	private int $admin;
	private int $portal;
	private int $normal;
	private int $manager;
	private string $tag;
	private string $password = 'Fixture-only-password-348!';

	public function run(): void {
		global $wpdb;
		$this->ok( DB_NAME === BC_Fixture::$database && DB_NAME !== BC_Fixture::$live_db, 'isolated database bound' );
		$this->tag = (string) random_int( 10000000, 99999999 );
		$this->admin = (int) username_exists( 'fixture_admin' );
		$this->ok( is_super_admin( $this->admin ), 'fixture administrator exists' );
		foreach ( [ Schema::links_table(), Schema::attempts_table() ] as $table ) {
			$this->ok( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), 'refuse to adopt a pre-existing SSO table' );
		}
		$this->ok( false === get_site_option( Schema::VERSION_OPTION, false ), 'refuse to adopt a pre-existing SSO version' );
		switch_to_blog( BC_FIXTURE_HALL_A );
		foreach ( [ 'woocommerce_registration_generate_username', 'woocommerce_registration_generate_password' ] as $name ) {
			$this->options[ $name ] = get_option( $name, null );
		}
		add_filter( 'pre_wp_mail', '__return_true' );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		try {
			// A portal- prefix alone must retain all ordinary-account behaviour.
			$this->normal = $this->create_user( 'portal-mike' . $this->tag );
			$this->manager = $this->create_user( 'ssomanager' . $this->tag );
			// Fixture setup only: plant HM caps without changing any production writer.
			$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (user_id,meta_key,meta_value) VALUES (%d,%s,%s)', $wpdb->usermeta, $this->manager, $wpdb->prefix . 'capabilities', serialize( [ 'bc_hall_manager' => true ] ) ) );
			clean_user_cache( $this->manager );
			$this->portal = $this->create_user( 'portal-fixture-' . $this->tag, true );
			$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (user_id,meta_key,meta_value) VALUES (%d,%s,%s)', $wpdb->usermeta, $this->portal, $wpdb->prefix . 'capabilities', serialize( [ 'bc_hall_manager' => true ] ) ) );
			clean_user_cache( $this->portal );
			add_user_to_blog( BC_FIXTURE_HALL_A, $this->normal, 'customer' );
			$this->test_guards_without_schema();
			$this->test_schema();
			$this->test_reservation();
			$this->test_creation_routes();
			$this->test_email_generated_customers();
			$this->test_updates_and_profiles();
			$this->test_normal_staff_workflow();
			$this->test_credentials();
			$this->test_woocommerce_keys();
			$this->test_no_query_overhead();
		} finally {
			$this->cleanup();
			restore_current_blog();
		}
		echo "PASS: {$this->assertions} Portal SSO Phase 1 assertions.\n";
	}

	private function ok( bool $pass, string $message ): void {
		++$this->assertions;
		if ( ! $pass ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	}

	private function create_user( string $login, bool $portal = false ): int {
		$create = fn() => wpmu_create_user( $login, $this->password, $login . '@example.invalid' );
		$result = $portal ? Guard::with_planned_username( $login, $create ) : $create();
		$this->ok( is_int( $result ) && $result > 0, 'fixture account created' );
		$this->users[] = $result;
		return $result;
	}

	private function test_guards_without_schema(): void {
		foreach ( [ 1, BC_FIXTURE_HALL_A, BC_FIXTURE_HALL_B ] as $blog ) {
			switch_to_blog( $blog );
			$this->ok( is_wp_error( wp_authenticate( get_userdata( $this->portal )->user_login, $this->password ) ), "password denied without schema on site $blog" );
			$this->ok( false === wp_is_password_reset_allowed_for_user( $this->portal ), "reset denied without schema on site $blog" );
			$this->ok( false === wp_is_application_passwords_available_for_user( $this->portal ), "application passwords denied without schema on site $blog" );
			restore_current_blog();
		}
	}

	private function test_schema(): void {
		global $wpdb;
		$this->owns_schema = true;
		$old_mapping_version = get_site_option( 'bc_portal_integration_network_db_version' );
		$old_provisioning_version = get_site_option( 'bc_portal_provisioning_db_version' );
		// Real partial dbDelta failure must not advance the version.
		$break = static function ( $sql ) {
			return str_contains( $sql, 'CREATE TABLE' ) && str_contains( $sql, Schema::ATTEMPTS_TABLE ) ? 'SELECT * FROM bc_sso_deliberately_absent_fixture_table' : $sql;
		};
		add_filter( 'query', $break );
		$this->ok( is_wp_error( Schema::migrate() ), 'partial migration reports failure' );
		remove_filter( 'query', $break );
		$this->ok( false === get_site_option( Schema::VERSION_OPTION, false ), 'failed migration does not record success' );
		$this->ok( true === Schema::migrate(), 'partial migration can be retried' );
		$this->ok( Schema::is_ready(), 'both schemas verified' );
		$this->ok( $old_mapping_version === get_site_option( 'bc_portal_integration_network_db_version' ) && $old_provisioning_version === get_site_option( 'bc_portal_provisioning_db_version' ), 'existing schema versions unchanged' );
		$links = Schema::links_table();
		for ( $id = 1; $id <= 2; ++$id ) {
			$this->ok( 1 === $wpdb->query( $wpdb->prepare(
				'INSERT INTO %i (portal_installation_id,portal_user_id,portal_user_created_at,planned_username,creation_blog_id,creation_operation_id,creation_sso_reference,created_at) VALUES (%s,%d,%s,%s,%d,%s,%s,%s)',
				$links, 'fixture', $id, '2026-10-13T14:05:09Z', 'portal-fixture-' . $id, BC_FIXTURE_HALL_A, wp_generate_uuid4(), 'creation-sso-' . $id, gmdate( 'Y-m-d H:i:s' )
			) ), 'pending link inserted without a WP user' );
		}
		$this->ok( 2 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE wp_user_id IS NULL', $links ) ), 'multiple pending links use NULL, never default zero' );
		$this->ok( 'creation-sso-1' === $wpdb->get_var( $wpdb->prepare( 'SELECT creation_sso_reference FROM %i WHERE portal_user_id = %d', $links, 1 ) ), 'creation SSO reference retained' );
		$suppressed = $wpdb->suppress_errors( true );
		$this->ok( false === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET portal_user_id = %d WHERE portal_user_id = %d', $links, 1, 2 ) ), 'Portal identity unique' );
		$this->ok( false === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET planned_username = %s WHERE portal_user_id = %d', $links, 'portal-fixture-1', 2 ) ), 'planned username unique' );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET wp_user_id = %d WHERE portal_user_id = %d', $links, $this->portal, 1 ) );
		$this->ok( false === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET wp_user_id = %d WHERE portal_user_id = %d', $links, $this->portal, 2 ) ), 'WP user unique when populated' );
		$wpdb->suppress_errors( $suppressed );
		$hash = hash( 'sha256', 'fixture-attempt' );
		$attempt = $wpdb->prepare( 'INSERT INTO %i (attempt_reference_hash,browser_secret_fingerprint,secret_proof,ip_fingerprint,blog_id,sso_reference,created_at,expires_at) VALUES (%s,%s,%s,%s,%d,%s,%s,%s)', Schema::attempts_table(), $hash, hash( 'sha256', 'browser' ), bin2hex( random_bytes( 32 ) ), hash_hmac( 'sha256', '127.0.0.1', 'fixture-only-secret' ), BC_FIXTURE_HALL_A, wp_generate_uuid4(), gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s', time() + 300 ) );
		$this->ok( 1 === $wpdb->query( $attempt ), 'attempt inserted' );
		$suppressed = $wpdb->suppress_errors( true );
		$this->ok( false === $wpdb->query( $attempt ), 'attempt hash unique' );
		$wpdb->suppress_errors( $suppressed );
		$before = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY portal_user_id', $links ), ARRAY_A );
		$this->ok( true === Schema::migrate(), 'migration rerun succeeds' );
		$this->ok( $before === $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY portal_user_id', $links ), ARRAY_A ), 'migration rerun preserves rows and references' );
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX creation_blog_id', $links ) );
		$this->ok( ! Schema::is_ready() && is_wp_error( Schema::migrate() ), 'recorded version does not hide a missing index or silently repair it' );
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY creation_blog_id (creation_blog_id)', $links ) );
		$this->ok( true === Schema::verify_schema(), 'restored schema verifies' );
		switch_to_blog( 1 );
		$this->ok( $links === Schema::links_table(), 'same network table on main site' );
		restore_current_blog();
	}

	private function test_reservation(): void {
		foreach ( [ 'portal-a-1', 'PORTAL-ABC123-42', 'PoRtAl-aBc-123', 'portal-' . str_repeat( 'a', 20 ) . '-1' ] as $login ) {
			$this->ok( Guard::is_reserved_username( $login ), 'exact SSO format recognised: ' . $login );
		}
		foreach ( [ 'portal-', 'portal-mike', 'PORTAL-MIKE', 'portal-mike42', 'portal--1', 'portal-a-0', 'portal-a-01', 'portal-a-', 'portal-a-1extra', 'portal-a-b-1', 'portal-a_b-1', 'portal-' . str_repeat( 'a', 21 ) . '-1', 'xportal-a-1', "portal-a-1\n" ] as $login ) {
			$this->ok( ! Guard::is_reserved_username( $login ), 'non-SSO username remains ordinary: ' . $login );
		}
		foreach ( [ 'portal-', 'PORTAL-', 'PoRtAl-' ] as $prefix ) {
			$login = $prefix . 'ReSeRvEd-' . $this->tag;
			$result = wp_insert_user( [ 'user_login' => $login, 'user_pass' => $this->password, 'user_email' => $login . '@example.invalid' ] );
			$this->ok( is_wp_error( $result ) && ! username_exists( $login ), 'case-insensitive reservation before insertion' );
		}
		$planned = 'portal-scope-' . $this->tag;
		$result = Guard::with_planned_username( $planned, function () use ( $planned ) {
			$this->ok( is_wp_error( Guard::with_planned_username( $planned, static fn() => 1 ) ), 'nested exception refused' );
			$this->ok( is_wp_error( wp_create_user( $planned . '1', $this->password ) ), 'different username refused inside scope' );
			return wpmu_create_user( $planned, $this->password, $planned . '@example.invalid' );
		} );
		$this->ok( is_int( $result ) && $result > 0, 'exact planned name allowed once' );
		$this->users[] = $result;
		$planned = 'portal-consumed-' . $this->tag;
		Guard::with_planned_username( $planned, function () use ( $planned ) {
			$veto = static fn() => [];
			add_filter( 'wp_pre_insert_user_data', $veto, PHP_INT_MAX );
			$first = wp_create_user( $planned, $this->password );
			remove_filter( 'wp_pre_insert_user_data', $veto, PHP_INT_MAX );
			$second = wp_create_user( $planned, $this->password );
			$this->ok( is_wp_error( $first ) && is_wp_error( $second ), 'exception consumed even when later insertion is vetoed' );
		} );
		try { Guard::with_planned_username( $planned, static function () { throw new RuntimeException( 'fixture' ); } ); } catch ( RuntimeException $error ) {}
		$this->ok( is_wp_error( wp_create_user( $planned, $this->password ) ), 'exception cleared after throw' );
		$_REQUEST['portal_sso_allow_creation'] = $planned;
		$this->ok( is_wp_error( wp_create_user( $planned, $this->password ) ), 'request cannot enable exception' );
		unset( $_REQUEST['portal_sso_allow_creation'] );
		$rewrite = static function ( $data, $update ) use ( $planned ) { if ( ! $update ) { $data['user_login'] = $planned; } return $data; };
		add_filter( 'wp_pre_insert_user_data', $rewrite, 10, 2 );
		$this->ok( is_wp_error( wp_create_user( 'notreserved' . $this->tag, $this->password ) ), 'guard checks final insert data after earlier filters' );
		remove_filter( 'wp_pre_insert_user_data', $rewrite, 10 );
	}

	private function test_creation_routes(): void {
		foreach ( [ 'register', 'network_add', 'site_add', 'signup_activation', 'wp_api', 'wc_api', 'my_account', 'checkout', 'store_checkout' ] as $i => $route ) {
			foreach ( [ true, false ] as $reserved ) {
				$login = ( $reserved ? 'portal-route' . $i . '-' : 'ordinaryroute' . $i ) . $this->tag;
				$report = $this->worker( [ 'mode' => $route, 'actor' => 'wc_api' === $route ? $this->manager : ( in_array( $route, [ 'network_add', 'site_add', 'wp_api' ], true ) ? $this->admin : 0 ), 'blog' => 'network_add' === $route ? 1 : BC_FIXTURE_HALL_A, 'login' => $login, 'email' => $login . '@example.invalid' ] );
				wp_cache_delete( $login, 'userlogins' );
				$id = (int) username_exists( $login );
				if ( $id ) { $this->users[] = $id; }
				$this->ok( $reserved ? 0 === $id : $id > 0, "$route: " . ( $reserved ? 'reserved name refused' : 'normal account created' ) . ' ' . json_encode( $report ) );
				if ( ! $reserved ) { $this->ok( empty( $report['exception'] ), "$route normal-account handler completes: " . json_encode( $report ) ); }
				// Multisite signup validation already disallows '-' on these paths.
				if ( $reserved && ! in_array( $route, [ 'network_add', 'site_add', 'wp_api' ], true ) ) {
					$this->ok( ! empty( $report['reached_insert'] ), "$route reached central insert boundary" );
				}
			}
		}
	}

	private function test_email_generated_customers(): void {
		foreach ( [ 'wc_api', 'my_account', 'checkout', 'store_checkout' ] as $i => $route ) {
			$login = 'portal-mike' . $this->tag . $i;
			$email = $login . '@example.invalid';
			$report = $this->worker( [ 'mode' => $route, 'actor' => 'wc_api' === $route ? $this->manager : 0, 'login' => $login, 'email' => $email, 'generate_username' => true ] );
			wp_cache_delete( $login, 'userlogins' );
			wp_cache_delete( $email, 'useremail' );
			$id = (int) email_exists( $email );
			if ( $id ) { $this->users[] = $id; }
			$this->ok( $id > 0 && empty( $report['exception'] ), "$route: portal-mike email creates a customer: " . json_encode( $report ) );
			$user = get_userdata( $id );
			$this->ok( $login === $user->user_login && in_array( 'customer', $user->roles, true ), "$route: customer username generated from email" );
			$this->ok( ! Guard::is_portal_user( $user ), "$route: portal-mike customer is not an SSO account" );
			$this->ok( wp_authenticate( $email, $this->password ) instanceof WP_User, "$route: customer password login stays available" );
			$this->ok( true === wp_is_password_reset_allowed_for_user( $id ), "$route: customer password reset stays available" );
		}
	}

	private function test_updates_and_profiles(): void {
		foreach ( [ $this->portal, $this->normal ] as $id ) {
			$this->ok( $id === wp_update_user( [ 'ID' => $id, 'display_name' => 'Updated display', 'user_nicename' => 'random-' . $id, 'first_name' => 'First', 'last_name' => 'Last' ] ), 'WordPress existing-account update succeeds' );
			// Existing WooCommerce permissions restrict editing other staff accounts.
			wp_set_current_user( $this->admin );
			$request = new WP_REST_Request( 'PUT', '/wc/v3/customers/' . $id );
			$request->set_body_params( [ 'first_name' => 'Updated' ] );
			$response = rest_do_request( $request );
			$this->ok( 200 === $response->get_status(), 'WooCommerce customer update succeeds: ' . json_encode( $response->get_data() ) );
			wp_set_current_user( $id );
			$this->ok( ( $id !== $this->portal ) === apply_filters( 'show_password_fields', true, get_userdata( $id ) ), 'own WordPress password fields follow account type' );
			$html = wc_get_template_html( 'myaccount/form-edit-account.php', [ 'user' => get_userdata( $id ) ] );
			$rule = 'form.woocommerce-EditAccountForm fieldset:has(input[name="password_current"]){display:none}';
			$this->ok( ( $id === $this->portal ) === str_contains( $html, $rule ), 'scoped rule present only in Portal account form' );
			$this->ok( str_contains( $html, 'name="account_first_name"' ) && str_contains( $html, 'name="save_account_details"' ), 'ordinary account details remain rendered' );
		}
		wp_set_current_user( $this->admin );
		$this->ok( true === apply_filters( 'show_password_fields', true, get_userdata( $this->portal ) ), 'profile presentation change limited to own profile' );
	}

	private function test_credentials(): void {
		foreach ( [ $this->portal, $this->normal ] as $id ) {
			$user = get_userdata( $id );
			$blocked = $this->portal === $id;
			foreach ( [ $user->user_login, $user->user_email ] as $login ) {
				$result = wp_authenticate( $login, $this->password );
				$this->ok( $blocked ? is_wp_error( $result ) : $result instanceof WP_User, 'username/email password authentication follows account type' );
			}
			$this->ok( is_wp_error( wp_authenticate( $user->user_login, 'wrong' ) ), 'wrong password remains refused' );
			$key = get_password_reset_key( $user );
			$this->ok( $blocked ? is_wp_error( $key ) : is_string( $key ), 'central reset permission covers admin-issued reset key too' );
			$result = retrieve_password( $user->user_email );
			$this->ok( $blocked ? is_wp_error( $result ) : true === $result, 'reset request follows account type' );
			$errors = new WP_Error();
			do_action( 'validate_password_reset', $errors, $user );
			$this->ok( $blocked === $errors->has_errors(), 'existing reset key completion boundary follows account type' );
			$report = $this->worker( [ 'mode' => 'xmlrpc', 'login' => $user->user_login ] );
			$this->ok( ! empty( $report['authenticated'] ) === ! $blocked, 'real XML-RPC login follows account type ' . json_encode( $report ) );
			wp_set_current_user( $this->admin );
			$request = new WP_REST_Request( 'POST', '/wp/v2/users/' . $id . '/application-passwords' );
			$request->set_body_params( [ 'name' => 'Phase 1 fixture' ] );
			$response = rest_do_request( $request );
			$this->ok( $blocked ? $response->get_status() >= 400 : 201 === $response->get_status(), 'application password creation refused/allowed through real REST route' );
			// Seed a pre-existing credential using WP's trusted storage API (not a web route).
			$created = WP_Application_Passwords::create_new_application_password( $id, [ 'name' => 'Existing credential fixture' ] );
			$this->ok( is_array( $created ), 'existing application credential seeded' );
			add_filter( 'application_password_is_api_request', '__return_true' );
			$result = wp_authenticate_application_password( null, $user->user_email, $created[0] );
			$this->ok( $blocked ? is_wp_error( $result ) : $result instanceof WP_User, 'existing application password cannot bypass account restriction' );
			remove_filter( 'application_password_is_api_request', '__return_true' );
			WP_Application_Passwords::delete_all_application_passwords( $id );
			// Seed a pre-existing reset key; completion must be denied even though it validates.
			$key = wp_generate_password( 20, false );
			wp_update_user( [ 'ID' => $id, 'user_activation_key' => time() . ':' . wp_fast_hash( $key ) ] );
			$this->ok( check_password_reset_key( $key, $user->user_login ) instanceof WP_User, 'pre-existing reset key valid' );
			$this->worker( [ 'mode' => 'wc_reset', 'login' => $user->user_login, 'reset_key' => $key ] );
			clean_user_cache( $id );
			$this->ok( wp_check_password( $blocked ? $this->password : 'Changed-fixture-password!', get_userdata( $id )->user_pass, $id ), 'WooCommerce reset completion follows account type' );
			wp_set_password( $this->password, $id );
		}
	}

	private function test_normal_staff_workflow(): void {
		wp_set_current_user( $this->admin );
		foreach ( [ 'ssostaff' . $this->tag, 'portal-mike' . $this->tag . 'staff' ] as $login ) {
			$email = $login . '@example.invalid';
			$result = \BingoCommerce\Core\HallStaffProvisioningService::create_and_assign( $email, 'Fixture staff', \BingoCommerce\Core\Roles::GAME_OPERATOR );
			$id = (int) email_exists( $email );
			if ( $id ) { $this->users[] = $id; }
			$this->ok( $result instanceof \BingoCommerce\Core\HallStaffResult && \BingoCommerce\Core\HallStaffResult::COMMITTED_VERIFIED === $result->status, 'existing staff create/assign workflow succeeds: ' . ( is_wp_error( $result ) ? $result->get_error_code() : get_debug_type( $result ) ) );
			$user = get_userdata( $id );
			$this->ok( $login === $user->user_login && ! Guard::is_portal_user( $user ), 'email-generated staff username remains ordinary' );
			$this->ok( in_array( \BingoCommerce\Core\Roles::GAME_OPERATOR, $user->roles, true ), 'ordinary staff role assigned' );
			$this->ok( 'staff_operational' === get_user_meta( $id, 'bc_identity_type', true ), 'ordinary staff provenance unchanged' );
			$this->ok( '' !== get_user_meta( $id, \BingoCommerce\Core\HallStaffInvitation::ISSUED_META_KEY, true ), 'ordinary staff invitation still issued' );
			$this->ok( true === wp_is_password_reset_allowed_for_user( $id ), 'ordinary staff password setup stays available' );
		}
	}

	private function test_woocommerce_keys(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_api_keys';
		foreach ( [ [ $this->admin, $this->portal, false ], [ $this->portal, $this->portal, false ], [ $this->admin, $this->normal, true ] ] as [ $actor, $target, $allowed ] ) {
			$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $table, $target ) );
			$this->worker( [ 'mode' => 'key_write', 'actor' => $actor, 'target' => $target ] );
			$after = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $table, $target ) );
			$this->ok( $after === $before + (int) $allowed, 'API key writer follows actor/target rule' );
		}
		$key_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT key_id FROM %i WHERE user_id = %d LIMIT 1', $table, $this->normal ) );
		$this->worker( [ 'mode' => 'key_write', 'actor' => $this->admin, 'target' => $this->portal, 'key_id' => $key_id ] );
		$this->ok( $this->normal === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM %i WHERE key_id = %d', $table, $key_id ) ), 'existing key cannot be reassigned to Portal account' );
		foreach ( [ [ false, false ], [ true, false ], [ true, true ] ] as [ $pretty, $blank ] ) {
			$this->worker( [ 'mode' => 'app_authorization', 'actor' => $this->portal, 'pretty' => $pretty, 'blank_query' => $blank ] );
			$this->ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $table, $this->portal ) ), 'WC app authorization cannot create Portal key' );
		}
		$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $table, $this->admin ) );
		$report = $this->worker( [ 'mode' => 'app_authorization', 'actor' => $this->admin ] );
		$this->ok( $before + 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $table, $this->admin ) ), 'normal WC app authorization still creates a key ' . json_encode( $report ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d AND description LIKE %s', $table, $this->admin, 'Fixture - API (%)' ) );
		foreach ( [ $this->portal, $this->manager ] as $id ) {
			$key = 'ck_' . bin2hex( random_bytes( 20 ) );
			$secret = 'cs_' . bin2hex( random_bytes( 20 ) );
			$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (user_id,description,permissions,consumer_key,consumer_secret,truncated_key) VALUES (%d,%s,%s,%s,%s,%s)', $table, $id, 'Existing fixture key', 'read', wc_api_hash( $key ), $secret, substr( $key, -7 ) ) );
			foreach ( [ [ false, false ], [ true, false ], [ false, true ] ] as [ $query, $fallback ] ) {
				$report = $this->worker( [ 'mode' => 'key_auth', 'target' => $id, 'key' => $key, 'secret' => $secret, 'query' => $query, 'fallback' => $fallback ] );
				$this->ok( ( $id !== $this->portal ) === $report['authenticated'], 'WooCommerce key authentication and fallback follow account type ' . json_encode( $report ) );
			}
			foreach ( [ 'query', 'header' ] as $oauth ) {
				$report = $this->worker( [ 'mode' => 'key_auth', 'target' => $id, 'key' => $key, 'secret' => $secret, 'oauth' => $oauth ] );
				$this->ok( ( $id !== $this->portal ) === $report['authenticated'], 'OAuth key authentication follows account type ' . json_encode( $report ) );
			}
			$report = $this->worker( [ 'mode' => 'key_auth', 'target' => $id, 'key' => $key, 'secret' => $secret, 'padded' => true ] );
			$this->ok( ( $id !== $this->portal ) === $report['authenticated'], 'WC key normalization cannot bypass the guard ' . json_encode( $report ) );
			$report = $this->worker( [ 'mode' => 'mcp_key_auth', 'key' => $key, 'secret' => $secret ] );
			$this->ok( ( $id !== $this->portal ) === ! empty( $report['endpoint_ran'] ), 'MCP permission authentication cannot bypass the guard ' . json_encode( $report ) );
			$this->ok( ( $id === $this->portal ? 403 : 200 ) === ( $report['status'] ?? 0 ), 'MCP request gets the expected response' );
		}
	}

	private function test_no_query_overhead(): void {
		global $wpdb;
		wp_set_current_user( $this->normal );
		$user = get_userdata( $this->normal );
		$before = $wpdb->num_queries;
		for ( $i = 0; $i < 20; ++$i ) {
			apply_filters( 'authenticate', $user, '', '' );
			apply_filters( 'allow_password_reset', true, $user->ID );
			apply_filters( 'wp_is_application_passwords_available_for_user', true, $user );
			apply_filters( 'show_password_fields', true, $user );
			apply_filters( 'rest_authentication_errors', null );
			ob_start(); do_action( 'woocommerce_edit_account_form_start' ); ob_end_clean();
		}
		$this->ok( $before === $wpdb->num_queries, 'warm normal-account guards add no database queries' );
	}

	private function worker( array $input ): array {
		$process = proc_open( [ PHP_BINARY, __DIR__ . '/portal-sso-foundation-worker.php', json_encode( $input, JSON_THROW_ON_ERROR ) ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		$this->ok( is_resource( $process ), 'worker started' );
		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false ); stream_set_blocking( $pipes[2], false );
		$out = ''; $err = ''; $deadline = microtime( true ) + 45;
		do {
			$out .= stream_get_contents( $pipes[1] ); $err .= stream_get_contents( $pipes[2] );
			$status = proc_get_status( $process );
			if ( ! $status['running'] ) { break; }
			if ( microtime( true ) > $deadline ) { proc_terminate( $process, 9 ); throw new RuntimeException( 'Worker deadline exceeded' ); }
			usleep( 10000 );
		} while ( true );
		$out .= stream_get_contents( $pipes[1] ); $err .= stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); fclose( $pipes[2] ); proc_close( $process );
		$report = json_decode( $out, true );
		$this->ok( is_array( $report ) && ! empty( $report['completed'] ), 'worker completed: ' . $out . $err );
		$this->ok( ! empty( $report['early_guards'] ), 'guards registered before plugins_loaded in ' . $input['mode'] );
		$this->ok( empty( $report['fatal'] ) || ! in_array( $report['fatal']['type'], [ E_ERROR, E_PARSE, E_COMPILE_ERROR ], true ), 'worker has no fatal error' );
		return $report;
	}

	private function cleanup(): void {
		global $wpdb;
		wp_set_current_user( $this->admin );
		foreach ( array_unique( $this->users ) as $id ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', $wpdb->prefix . 'woocommerce_api_keys', $id ) );
			// Owned fixture users only; remove planted caps before normal user cleanup.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d AND meta_key LIKE %s', $wpdb->usermeta, $id, '%capabilities' ) );
			clean_user_cache( $id );
			wpmu_delete_user( $id );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE target_type = %s AND target_id = %s', \BingoCommerce\Core\Migrations::audit_log_table(), 'user', (string) $id ) );
		}
		// Workers change options in separate processes; discard this process's stale cache.
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		foreach ( $this->options as $name => $value ) {
			wp_cache_delete( $name, 'options' );
			if ( null === $value ) { delete_option( $name ); } else { update_option( $name, $value ); }
		}
		if ( $this->owns_schema ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', Schema::links_table() ) );
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', Schema::attempts_table() ) );
			delete_site_option( Schema::VERSION_OPTION );
		}
	}
}

try { ( new PortalSsoFoundationTest() )->run(); } catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . ' at ' . $error->getFile() . ':' . $error->getLine() . "\n" );
	exit( 1 );
}
