<?php
/** ADR-048 Phase 4b. Isolated MySQL + installed WordPress/WooCommerce, mocked Portal. */
if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) { exit( 1 ); }
putenv( 'BC_FIXTURE_NETWORK_ACTIVE=1' );
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
define( 'BINGOCOMMERCE_PORTAL_BASE_URL', 'https://portal.example.invalid' );
define( 'BINGOCOMMERCE_PORTAL_SSO_TOKEN', 'fixture-phase4b-secret' );
define( 'BINGOCOMMERCE_PORTAL_INSTALLATION_ID', 'phase4b' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
if ( DB_NAME !== 'bingocommerce_state_recon' || DB_NAME !== BC_Fixture::$database || DB_NAME === BC_Fixture::$live_db
	|| DB_NAME !== $wpdb->get_var( $wpdb->prepare( 'SELECT DATABASE() WHERE %d = %d', 1, 1 ) ) ) { throw new RuntimeException( 'Isolated binding not proven before migration.' ); }

use BingoCommerce\PortalIntegration\SsoMigrations;
use BingoCommerce\PortalIntegration\Migrations as MappingSchema;
use BingoCommerce\PortalIntegration\HallMappingRepository;
use BingoCommerce\PortalIntegration\SsoRepository;
use BingoCommerce\PortalIntegration\SsoLogin;
use BingoCommerce\PortalIntegration\SsoSession;
use BingoCommerce\PortalIntegration\ProvisioningMigrations;
use BingoCommerce\PortalIntegration\HallCommerceState;
use BingoCommerce\Core\Migrations;

final class Phase4bTest {
	private int $assertions = 0;
	private array $people = [];
	private array $users = [];
	private array $mappings = [];
	private bool $schema = false;
	private bool $commerce_schema = false;
	private int $sequence;
	private int $sso = 0;
	private int $ordinary = 0;
	private array $identity;
	private SsoRepository $repository;

	public function run(): void {
		global $wpdb;
		$this->repository = new SsoRepository();
		$this->sequence = random_int( 100000000, 900000000 );
		$_SERVER['HTTPS'] = 'on';
		switch_to_blog( 2 );
		wp_set_current_user( 0 );
		add_filter( 'pre_http_request', static function () { throw new RuntimeException( 'Parent must never make HTTP calls.' ); } );
		add_filter( 'pre_wp_mail', '__return_true' );
		try {
			foreach ( [ SsoMigrations::links_table(), SsoMigrations::attempts_table() ] as $table ) {
				$this->ok( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), 'no pre-existing SSO schema' );
			}
			$this->ok( false === get_site_option( SsoMigrations::VERSION_OPTION, false ), 'no existing SSO schema version' );
			$this->schema = true;
			$this->ok( true === SsoMigrations::migrate(), 'migration on verified isolated database only' );
			foreach ( [ 2, 3 ] as $blog ) {
				$this->ok( null === ( new HallMappingRepository() )->findActiveByBlogId( $blog ), 'fixture owns new mapping' );
				$this->ok( is_array( ( new HallMappingRepository() )->bind( 990000 + $blog, $blog ) ), 'Hall mapped' );
				$this->mappings[] = $blog;
			}
			$this->logins();
			$this->identity_and_permissions();
			$this->expiry_and_activity();
			$this->configuration_and_failures();
			$this->presentation_and_logout();
			$this->performance();
		} finally { $this->cleanup(); }
		echo "PASS: {$this->assertions} Phase 4b SSO session assertions.\n";
	}

	private function person(): array {
		$id = ++$this->sequence;
		$this->people[] = $id;
		return [ 'installation_id' => 'phase4b', 'portal_user_id' => $id, 'portal_user_created_at' => '2026-10-13T14:05:09Z',
			'portal_hall_id' => 990002, 'name' => 'Fixture Owner', 'email' => 'sso4b-' . $id . '@example.invalid', 'sso_reference' => wp_generate_uuid4() ];
	}

	private function login_job( array &$person, int $blog = 2 ): array {
		switch_to_blog( $blog );
		try { $start = SsoLogin::prepare_start( '198.51.100.' . random_int( 1, 240 ) ); }
		finally { restore_current_blog(); }
		$this->ok( is_array( $start ), 'SSO start created' );
		parse_str( parse_url( $start['url'], PHP_URL_QUERY ), $query );
		$person['sso_reference'] = $query['sso_reference'];
		$person['portal_hall_id'] = 990000 + $blog;
		return [ 'mode' => 'complete', 'blog' => $blog, 'identity' => $person, 'attempt' => $query['attempt_reference'], 'browser' => $start['browser_secret'] ];
	}

	private function logins(): void {
		global $wpdb;
		$this->identity = $this->person();
		$first = $this->worker( $this->login_job( $this->identity ) );
		$this->ok( is_int( $first['result'] ) && $first['result'] > 0, 'first login provisions and signs in' );
		$this->sso = $first['result'];
		$this->users[] = $this->sso;
		$this->ok( $this->sso === $first['user'] && 1 === $first['login_actions'] && 1 === count( $first['cookies'] ), 'one cookie pair creation and one wp_login action' );
		$this->ok( $first['audit_before_cookie'] && $first['filters_removed'], 'mandatory audit precedes cookies and temporary filters are removed' );
		$cookie = $first['cookies'][0];
		$this->ok( 0 === $cookie['expire'] && 'secure_auth' === $cookie['scheme'] && abs( $cookie['until'] - time() - 3600 ) < 10, 'nonpersistent HTTPS one-hour cookie' );
		$session = $this->sessions( $this->sso )[ hash( 'sha256', $cookie['token'] ) ];
		$this->ok( 2 === $session[ SsoSession::MARKER ]['blog_id'] && $session[ SsoSession::MARKER ]['login_at'] === $session[ SsoSession::MARKER ]['last_activity_at'], 'session has Hall/login/activity marker' );
		$this->ok( '1' === (string) get_user_meta( $this->sso, '_woocommerce_load_saved_cart_after_login', true ), 'normal WooCommerce login notification ran' );
		$second = $this->worker( $this->login_job( $this->identity ) );
		$this->ok( $this->sso === $second['result'] && 1 === $second['login_actions'], 'second login reuses account and fires wp_login once' );
		$this->ok( 1 === count( $this->repository->accounts( 'portal-phase4b-' . $this->identity['portal_user_id'] ) ), 'exactly one store account' );
		$third = $this->worker( $this->login_job( $this->identity, 3 ) );
		$this->ok( $this->sso === $third['result'], 'second Hall reuses identity' );
		$token = $third['cookies'][0]['token'];
		$this->ok( 0 === $this->worker( [ 'id' => $this->sso, 'token' => $token, 'blog' => 2 ] )['user'], 'Hall B cookie signs browser out on Hall A' );
		$this->ok( $this->sso === $this->worker( [ 'id' => $this->sso, 'token' => $token, 'blog' => 3 ] )['user'], 'wrong-Hall request did not destroy Hall B session' );
		$this->ok( 0 === $this->worker( [ 'id' => $this->sso, 'token' => $token, 'blog' => 1 ] )['user'], 'main site refuses Hall session' );
		$back = $this->worker( $this->login_job( $this->identity, 2 ) );
		$this->ok( $this->sso === $back['result'], 'new SSO click switches back to Hall A' );
		$job = $this->login_job( $this->identity ); $job['mode'] = 'return';
		$headers = $this->worker( $job, true );
		$this->ok( 1 === substr_count( $headers, 'Location:' ) && str_contains( $headers, '/wp-admin/' ) && ! str_contains( $headers, 'code=' ), 'one clean dashboard redirect' );
		preg_match_all( '/^Set-Cookie: (wordpress_(?:sec|logged_in)_[^\r\n]+)/mi', $headers, $matches );
		$this->ok( count( $matches[1] ) >= 2, 'real authentication cookie headers sent' );
		foreach ( $matches[1] as $header ) { $this->ok( str_contains( strtolower( $header ), 'secure' ) && str_contains( strtolower( $header ), 'httponly' ) && ! str_contains( strtolower( $header ), 'expires=' ), 'all auth cookies secure HttpOnly and browser-session only' ); }
		$this->ok( str_contains( strtolower( $headers ), 'no-store' ) && str_contains( $headers, 'Referrer-Policy: no-referrer' ), 'return is uncached and sends no referrer' );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT operation_id,context FROM %i WHERE action = %s AND target_id = %s', Migrations::audit_log_table(), 'portal_sso.login', (string) $this->sso ), ARRAY_A );
		$this->ok( 5 === count( $rows ) && 5 === count( array_unique( array_column( $rows, 'operation_id' ) ) ), 'each login has its own operation ID' );
		foreach ( $rows as $row ) { $this->ok( isset( json_decode( $row['context'], true )['sso_reference'] ) && ! str_contains( $row['context'], BINGOCOMMERCE_PORTAL_SSO_TOKEN ), 'audit contains flow reference and no token' ); }
	}

	private function seed( int $id, int $blog = 2, ?int $login = null, ?int $activity = null, bool $marked = true ): string {
		$login ??= time(); $activity ??= $login;
		$token = wp_generate_password( 43, false, false );
		$session = [ 'expiration' => time() + 3600, 'login' => $login ];
		if ( $marked ) { $session[ SsoSession::MARKER ] = [ 'blog_id' => $blog, 'login_at' => $login, 'last_activity_at' => $activity ]; }
		\WP_Session_Tokens::get_instance( $id )->update( $token, $session );
		return $token;
	}

	private function sessions( int $id ): array {
		wp_cache_delete( $id, 'user_meta' );
		$sessions = get_user_meta( $id, 'session_tokens', true );
		return is_array( $sessions ) ? $sessions : [];
	}

	private function roles( int $id, array $caps, int $blog = 2 ): void {
		global $wpdb;
		$key = $wpdb->get_blog_prefix( $blog ) . 'capabilities';
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d AND meta_key = %s', $wpdb->usermeta, $id, $key ) );
		if ( $caps ) { $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (user_id,meta_key,meta_value) VALUES (%d,%s,%s)', $wpdb->usermeta, $id, $key, serialize( $caps ) ) ); }
		clean_user_cache( $id );
	}

	private function identity_and_permissions(): void {
		$unmarked = $this->seed( $this->sso, 2, null, null, false );
		$this->ok( 0 === $this->worker( [ 'id' => $this->sso, 'token' => $unmarked ] )['user'], 'unmarked WP session refused' );
		$token = $this->seed( $this->sso );
		$this->ok( 0 === $this->worker( [ 'id' => $this->sso, 'token' => $token, 'bad_cookie' => true ] )['user'], 'forged cookie never authenticates' );
		foreach ( [ [], [ 'bc_game_operator' => true ], [ 'bc_hall_manager' => true, 'subscriber' => true ], [ 'bc_hall_manager' => true, 'manage_options' => true ] ] as $caps ) {
			$this->roles( $this->sso, $caps );
			foreach ( [ 'request', 'ajax', 'rest' ] as $mode ) {
				$r = $this->worker( [ 'mode' => $mode, 'id' => $this->sso, 'token' => $token, 'post' => [ 'action' => 'bc_phase4b_fixture' ] ] );
				$this->ok( 0 === $r['user'] && ( 'request' === $mode || $r['status'] >= 400 ), 'non-exact role refused on ' . $mode );
			}
		}
		$this->ok( isset( $this->sessions( $this->sso )[ hash( 'sha256', $token ) ] ), 'role refusal does not destroy session' );
		$b = $this->seed( $this->sso, 3 );
		$this->ok( $this->sso === $this->worker( [ 'id' => $this->sso, 'token' => $b, 'blog' => 3 ] )['user'], 'other Hall role/session unaffected' );
		// No new login when the Portal refuses; a local-only removal is intentionally re-granted.
		$this->roles( $this->sso, [ 'bc_hall_manager' => true ] );
		$job = $this->login_job( $this->identity ); $job['http_status'] = 403;
		$this->ok( is_string( $this->worker( $job )['result'] ), 'Portal removal refuses next login' );
		$this->ok( $this->sso === $this->worker( [ 'id' => $this->sso, 'token' => $token ] )['user'], 'Portal-only removal leaves existing session until its local limits' );
		$this->roles( $this->sso, [] );
		$job = $this->login_job( $this->identity ); $job['http_status'] = 403;
		$this->ok( is_string( $this->worker( $job )['result'] ) && [] === $this->repository->current_roles( $this->sso, 2 ), 'both removals prevent next click from restoring the Hall role' );
		$this->ok( $this->sso === $this->worker( $this->login_job( $this->identity ) )['result'], 'only local removal is restored by a fresh Portal-authorized login' );
		$job = $this->login_job( $this->identity ) + [ 'remove_role_after_portal_approval' => $this->sso ];
		$r = $this->worker( $job );
		$this->ok( $this->sso === $r['result'] && $this->sso === $r['user'], 'documented v1 limitation: an already-approved delayed login can restore local access' );
	}

	private function expiry_and_activity(): void {
		$now = time();
		foreach ( [ [ $now - 1800, $now - 1800 ], [ $now - 3600, $now - 1 ] ] as [ $login, $activity ] ) {
			foreach ( [ 'request', 'ajax', 'rest' ] as $mode ) {
				$token = $this->seed( $this->sso, 2, $login, $activity );
				$r = $this->worker( [ 'mode' => $mode, 'id' => $this->sso, 'token' => $token, 'method' => 'POST' ] );
				$this->ok( 0 === $r['user'], 'server expiry applies to ' . $mode . ' without grace' );
				$this->ok( ! isset( $this->sessions( $this->sso )[ hash( 'sha256', $token ) ] ), 'expired token destroyed' );
			}
		}
		$token = $this->seed( $this->sso, 2, $now - 3601, $now - 10 );
		$r = $this->worker( [ 'id' => $this->sso, 'token' => $token, 'cookie_expiry' => $now - 1 ] );
		$this->ok( 0 === $r['user'] && ! isset( $this->sessions( $this->sso )[ hash( 'sha256', $token ) ] ), 'cleanup also works when WP rejects expired cookie first' );

		$timers = [
			[ 'mode' => 'ajax', 'post' => [ 'action' => 'heartbeat' ] ],
			[ 'mode' => 'ajax', 'get' => [ 'action' => 'dashboard-widgets', 'widget' => 'dashboard_primary' ] ],
			[ 'mode' => 'ajax', 'get' => [ 'action' => 'woocommerce_load_status_widget' ] ],
			[ 'mode' => 'ajax', 'get' => [ 'action' => 'woocommerce_load_recent_reviews_widget' ] ],
			[ 'mode' => 'request', 'pagenow' => 'admin.php', 'get' => [ 'page' => 'wc-status', 'tab' => 'tools', 'wc_status_tools_poll' => '123456' ] ],
			[ 'mode' => 'rest', 'route' => '/wc-analytics/imports/status' ],
			[ 'mode' => 'rest', 'route' => '/wc-analytics/reports/import/status' ],
			[ 'mode' => 'rest', 'method' => 'POST', 'route' => '/wc-admin/mobile-app/qr-login-status' ],
			[ 'mode' => 'rest', 'method' => 'POST', 'route' => '/wc-admin/settings/payments/woopayments/onboarding/step/test_account/check' ],
			[ 'mode' => 'rest', 'method' => 'PUT', 'route' => '/wc-analytics/admin/notes/123', 'body' => [ 'is_read' => true ] ],
		];
		foreach ( $timers as $timer ) {
			$token = $this->seed( $this->sso, 2, $now - 100, $now - 100 );
			$job = $timer + [ 'id' => $this->sso, 'token' => $token, 'fixture_route' => true, 'now' => $now ];
			$r = $this->worker( $job );
			$this->ok( $this->sso === $r['user'] && 0 === $r['session_writes'] && 0 === $r['save_queries'], 'identified timer does not save activity: ' . json_encode( $timer ) . ' result=' . json_encode( [ $r['user'], $r['status'], $r['session_writes'], $r['save_queries'], $r['routes'] ] ) );
			$job['now'] = $now + 1700;
			$this->ok( 0 === $this->worker( $job )['user'], 'timer alone still expires at 30 minutes' );
		}
		// Real requests with a controlled server clock: 35 minutes without page loads.
		foreach ( [
			[ 'mode' => 'rest', 'route' => '/wp/v2/users/me' ],
			[ 'mode' => 'ajax', 'post' => [ 'action' => 'bc_phase4b_fixture' ] ],
			[ 'mode' => 'rest', 'method' => 'POST', 'route' => '/wp/v2/posts/123/autosaves', 'fixture_route' => true ],
			[ 'mode' => 'ajax', 'method' => 'POST', 'post' => [ 'action' => 'heartbeat', 'data' => [ 'wp_autosave' => [ 'post_id' => 123, 'content' => 'typing' ] ] ] ],
		] as $activity ) {
			$base = time(); $token = $this->seed( $this->sso, 2, $base, $base );
			for ( $minute = 5; $minute <= 35; $minute += 5 ) {
				$r = $this->worker( $activity + [ 'id' => $this->sso, 'token' => $token, 'now' => $base + $minute * 60 ] );
				$this->ok( $this->sso === $r['user'] && 200 === $r['status'] && 1 === $r['session_writes'], 'active REST/AJAX/autosave at minute ' . $minute . ': ' . json_encode( $activity ) . ' result=' . json_encode( [ $r['user'], $r['status'], $r['session_writes'], $r['save_queries'], $r['routes'] ] ) );
			}
			$this->ok( 0 === $this->worker( $activity + [ 'id' => $this->sso, 'token' => $token, 'now' => $base + 3600 ] )['user'], 'active sessions still have one-hour maximum' );
		}
		// A normal request cannot be disguised by a polling parameter for another route.
		$token = $this->seed( $this->sso, 2, time() - 100, time() - 100 );
		$r = $this->worker( [ 'id' => $this->sso, 'token' => $token, 'get' => [ 'rest_route' => '/wc-analytics/imports/status', 'wc_status_tools_poll' => 1 ] ] );
		$this->ok( 1 === $r['session_writes'], 'unrelated query parameters do not suppress real activity' );
		$token = $this->seed( $this->sso, 2, time() - 100, time() - 100 );
		$r = $this->worker( [ 'mode' => 'rest', 'id' => $this->sso, 'token' => $token, 'bad_nonce' => true ] );
		$this->ok( 403 === $r['status'] && 0 === $r['session_writes'], 'invalid REST nonce does not refresh activity' );
	}

	private function configuration_and_failures(): void {
		foreach ( [ [ 'off' => true ], [ 'no_installation' => true ], [ 'installation' => 'Bad' ], [ 'sso_token' => "bad\nsecret" ] ] as $config ) {
			$token = $this->seed( $this->sso );
			foreach ( [ 'request', 'ajax', 'rest' ] as $mode ) {
				$this->ok( 0 === $this->worker( $config + [ 'mode' => $mode, 'id' => $this->sso, 'token' => $token ] )['user'], 'SSO off/invalid refuses existing session: ' . $mode );
			}
			$this->ok( $this->sso === $this->worker( [ 'id' => $this->sso, 'token' => $token ] )['user'], 'restored configuration accepts unexpired session' );
		}
		foreach ( [ 'fail_login_audit', 'fail_session' ] as $failure ) {
			$person = $this->person();
			$r = $this->worker( $this->login_job( $person ) + [ $failure => true ] );
			$this->ok( is_string( $r['result'] ) && 0 === $r['user'] && 0 === $r['login_actions'], 'failure never signs in: ' . $failure );
			$link = $this->repository->link( 'phase4b', $person['portal_user_id'] );
			$this->ok( [] === $this->sessions( (int) $link['wp_user_id'] ), 'failure leaves no new session' );
			if ( 'fail_login_audit' === $failure ) { $this->ok( [] === $r['cookies'], 'audit failure never calls cookie creation' ); }
			$retry = $this->worker( $this->login_job( $person ) );
			$this->ok( (int) $link['wp_user_id'] === $retry['result'], 'retry reuses provisioned account' );
		}
		$person = $this->person();
		$this->ok( is_string( $this->worker( $this->login_job( $person ) + [ 'unreadable_tables' => true ] )['result'] ), 'unreadable SSO tables refuse new login' );
		$token = $this->seed( $this->sso );
		$r = $this->worker( [ 'id' => $this->sso, 'token' => $token, 'unreadable_tables' => true ] );
		$this->ok( $this->sso === $r['user'] && 0 === $r['session_table_queries'] && 0 === $r['http'], 'existing sessions do not need SSO tables or Portal' );
		$job = $this->login_job( $this->identity );
		global $wpdb;
		foreach ( [ ProvisioningMigrations::state_table(), ProvisioningMigrations::operations_table() ] as $table ) {
			$this->ok( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), 'own only new Commerce test schema' );
		}
		$this->ok( false === get_site_option( ProvisioningMigrations::VERSION_OPTION, false ), 'no existing Commerce schema version' );
		$this->commerce_schema = true;
		$this->ok( true === ProvisioningMigrations::migrate(), 'isolated Commerce state schema' );
		$this->ok( is_array( HallCommerceState::write( 2, HallCommerceState::SUSPENDED, 1, HallCommerceState::PHASE_SETTLED ) ), 'suspend through the existing Commerce state writer' );
		try {
			$r = $this->worker( $job );
			$this->ok( is_string( $r['result'] ) && 0 === $r['http'], 'disabled Hall refuses return before exchange' );
			$gate = $this->worker( [ 'mode' => 'gate', 'id' => $this->sso, 'token' => $token ], true );
			$this->ok( str_contains( $gate, '503' ), 'existing suspension gate refuses signed-in SSO staff' );
		} finally { HallCommerceState::write( 2, HallCommerceState::OPERATIONAL, 2, HallCommerceState::PHASE_SETTLED ); }
		$this->ok( $this->sso === $this->worker( [ 'id' => $this->sso, 'token' => $token ] )['user'], 're-enabled Hall retains unexpired session' );
	}

	private function presentation_and_logout(): void {
		$this->ordinary = wpmu_create_user( 'portal-mike', 'fixture-password', 'portal-mike-phase4b@example.invalid' );
		$this->ok( is_int( $this->ordinary ) && $this->ordinary > 0, 'ordinary portal-prefix account created' );
		$this->users[] = $this->ordinary;
		$this->roles( $this->ordinary, [ 'bc_hall_manager' => true ] );
		$token = $this->seed( $this->sso );
		$normal = $this->seed( $this->ordinary, 2, null, null, false );
		$r = $this->worker( [ 'mode' => 'popup', 'id' => $this->sso, 'token' => $token ] );
		$this->ok( str_contains( $r['html'], 'Your session has ended. Sign in with Portal to continue.' ) && str_contains( $r['html'], 'bc_portal_sso_start' ) && ! str_contains( $r['html'], 'wp-auth-check-form' ), 'SSO popup contains Portal button, no password iframe' );
		$r = $this->worker( [ 'mode' => 'popup', 'id' => $this->ordinary, 'token' => $normal ] );
		$this->ok( str_contains( $r['html'], 'wp-auth-check-form' ) && ! str_contains( $r['html'], 'bc_portal_sso_start' ), 'ordinary account keeps native password popup' );
		$this->ok( str_contains( $this->worker( [ 'mode' => 'login-button' ] )['html'], 'bc_portal_sso_start' ), 'configured mapped Hall login button' );
		foreach ( [ [ 'off' => true ], [ 'no_installation' => true ], [ 'blog' => 1 ] ] as $case ) { $this->ok( '' === $this->worker( $case + [ 'mode' => 'login-button' ] )['html'], 'no button when unavailable or unmapped' ); }
		update_blog_status( 2, 'archived', 1 );
		try { $this->ok( '' === $this->worker( [ 'mode' => 'login-button' ] )['html'], 'no button on blocked Hall' ); }
		finally { update_blog_status( 2, 'archived', 0 ); }
		$this->ok( false === has_action( 'woocommerce_login_form', [ SsoLogin::class, 'login_button' ] ), 'no customer My Account SSO button hook' );
		$other = $this->seed( $this->sso, 3 );
		$r = $this->worker( [ 'mode' => 'logout', 'id' => $this->sso, 'token' => $token ] );
		$this->ok( 0 === $r['user'] && 0 === $r['http'] && ! isset( $this->sessions( $this->sso )[ hash( 'sha256', $token ) ] ), 'standard WP logout destroys this session only, no Portal call' );
		$this->ok( isset( $this->sessions( $this->sso )[ hash( 'sha256', $other ) ] ), 'logout preserves other browser sessions' );
		$this->ok( $this->sso === $this->worker( $this->login_job( $this->identity ) )['result'], 'fresh Portal click signs in after store logout' );
	}

	private function performance(): void {
		foreach ( [ $this->ordinary, $this->sso ] as $id ) {
			$token = $this->seed( $id, 2, null, null, $id === $this->sso );
			foreach ( [ false, true ] as $cold ) {
				$job = [ 'id' => $id, 'token' => $token, 'cold' => $cold ];
				$baseline = $this->worker( $job + [ 'disable_guard' => true ] );
				$checked = $this->worker( $job );
				$this->ok( $baseline['auth_queries'] === $checked['auth_queries'] && 0 === $checked['save_queries'] && 0 === $checked['session_table_queries'], 'no added auth queries, warm/cold: ' . $id );
			}
		}
		$token = $this->seed( $this->sso, 2, time() - 120, time() - 120 );
		$r = $this->worker( [ 'id' => $this->sso, 'token' => $token ] );
		$this->ok( 1 === $r['session_writes'], 'activity save when minute has elapsed' );
		$r = $this->worker( [ 'id' => $this->sso, 'token' => $token ] );
		$this->ok( 0 === $r['session_writes'] && 0 === $r['save_queries'], 'no repeated activity save within minute' );
		$this->ok( $this->ordinary === $this->worker( [ 'id' => $this->ordinary, 'token' => $this->seed( $this->ordinary, 2, time() - 7200, time() - 7200, false ), 'off' => true ] )['user'], 'ordinary long-lived session unaffected with SSO off' );
	}

	private function worker( array $job, bool $cgi = false ) {
		$env = null;
		$command = [ PHP_BINARY, __DIR__ . '/portal-sso-sessions-worker.php', json_encode( $job ) ];
		if ( $cgi ) {
			$command = [ dirname( PHP_BINARY ) . '/php-cgi' ]; $env = getenv();
			$env['BC_SSO_SESSION_JOB'] = json_encode( $job ); $env['SCRIPT_FILENAME'] = __DIR__ . '/portal-sso-sessions-worker.php';
			$env['REDIRECT_STATUS'] = '1'; $env['BC_FIXTURE_CGI'] = '1'; unset( $env['REMOTE_ADDR'], $env['SERVER_SOFTWARE'] );
		}
		$p = proc_open( $command, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, null, $env );
		$this->ok( is_resource( $p ), 'isolated worker started' );
		$out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
		$this->ok( 0 === proc_close( $p ) && '' === $err, 'isolated worker succeeds: ' . $err . ( str_contains( $out, 'Fatal' ) ? $out : '' ) );
		if ( $cgi ) { return $out; }
		$result = json_decode( $out, true );
		$this->ok( is_array( $result ), 'worker returns JSON: ' . ( is_array( $result ) ? '' : $out ) );
		return $result;
	}

	private function ok( bool $condition, string $message ): void {
		++$this->assertions;
		if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	}

	private function cleanup(): void {
		global $wpdb;
		foreach ( $this->people as $person ) {
			$link = $this->repository->link( 'phase4b', $person );
			if ( is_array( $link ) && $link['wp_user_id'] ) { $this->users[] = (int) $link['wp_user_id']; }
		}
		foreach ( array_unique( $this->users ) as $user ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', $wpdb->usermeta, $user ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE ID = %d', $wpdb->users, $user ) ); clean_user_cache( $user );
		}
		foreach ( $this->mappings as $blog ) { $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE blog_id = %d AND portal_hall_id = %d', MappingSchema::hall_mappings_table(), $blog, 990000 + $blog ) ); }
		if ( $this->schema ) {
			foreach ( [ SsoMigrations::links_table(), SsoMigrations::attempts_table() ] as $table ) { $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); }
			delete_site_option( SsoMigrations::VERSION_OPTION );
		}
		if ( $this->commerce_schema ) {
			foreach ( [ ProvisioningMigrations::state_table(), ProvisioningMigrations::operations_table() ] as $table ) { $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); }
			delete_site_option( ProvisioningMigrations::VERSION_OPTION );
		}
		while ( ms_is_switched() ) { restore_current_blog(); }
	}
}
( new Phase4bTest() )->run();
