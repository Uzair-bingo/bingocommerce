<?php
/** ADR-048 Phase 4a; real isolated MySQL/WordPress, mocked Portal only. */
if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) { exit( 1 ); }
putenv( 'BC_FIXTURE_NETWORK_ACTIVE=1' );
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
define( 'BINGOCOMMERCE_PORTAL_BASE_URL', 'https://portal.example.invalid' );
define( 'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN', 'fixture-snapshot-token' );
define( 'BINGOCOMMERCE_PORTAL_SSO_TOKEN', 'fixture-sso-secret-token' );
define( 'BINGOCOMMERCE_PORTAL_INSTALLATION_ID', 'phase4a' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
if ( DB_NAME !== BC_Fixture::$database || DB_NAME === BC_Fixture::$live_db || DB_NAME !== 'bingocommerce_state_recon'
	|| DB_NAME !== $wpdb->get_var( $wpdb->prepare( 'SELECT DATABASE() WHERE %d = %d', 1, 1 ) ) ) { throw new RuntimeException( 'Isolated database binding not proven.' ); }

use BingoCommerce\PortalIntegration\SsoMigrations as Schema;
use BingoCommerce\PortalIntegration\Migrations as MappingSchema;
use BingoCommerce\PortalIntegration\HallMappingRepository;
use BingoCommerce\PortalIntegration\SsoRepository;
use BingoCommerce\PortalIntegration\SsoLogin;
use BingoCommerce\PortalIntegration\SsoProvisioningService;
use BingoCommerce\PortalIntegration\SsoExchangeValidator;
use BingoCommerce\PortalIntegration\SsoHttpClient;
use BingoCommerce\PortalIntegration\SsoAccountGuard;
use BingoCommerce\PortalIntegration\PortalConfig;
use BingoCommerce\Core\Migrations;
use BingoCommerce\Core\HallStaffEligibility;
use BingoCommerce\Core\HallStaffProvisioningService;

final class Phase4aTest {
	private int $assertions = 0;
	private string $last_failure = '';
	private int $sequence;
	private array $people = [];
	private array $users = [];
	private array $mappings = [];
	private bool $schema = false;
	private array $answer = [];
	private int $status = 200;
	private int $calls = 0;
	private int $auth = 0;
	private int $mails = 0;
	private array $secrets = [];
	private array $references = [];
	private ?string $trigger = null;
	private array $halls = [ 2 => 980002, 3 => 980003 ];
	private SsoRepository $repository;

	public function run(): void {
		global $wpdb;
		$this->repository = new SsoRepository();
		$this->sequence = random_int( 100000000, 900000000 );
		$_SERVER['HTTPS'] = 'on';
		switch_to_blog( 2 );
		wp_set_current_user( 0 );
		add_filter( 'pre_wp_mail', function () { ++$this->mails; return true; } );
		add_action( 'set_auth_cookie', function () { ++$this->auth; } );
		add_action( 'set_logged_in_cookie', function () { ++$this->auth; } );
		add_filter( 'pre_http_request', [ $this, 'http' ], 10, 3 );
		try {
			foreach ( [ Schema::links_table(), Schema::attempts_table() ] as $table ) {
				$this->ok( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), 'refuse pre-existing SSO schema' );
			}
			$this->ok( false === get_site_option( Schema::VERSION_OPTION, false ), 'refuse existing SSO version' );
			$this->schema = true;
			$this->ok( true === Schema::migrate(), 'isolated Phase 1 schema' );
			foreach ( $this->halls as $blog => $hall ) {
				$this->ok( null === ( new HallMappingRepository() )->findActiveByBlogId( $blog ), 'own only new fixture mappings' );
				$this->ok( is_array( ( new HallMappingRepository() )->bind( $hall, $blog ) ), 'fixture mapping created' );
				$this->mappings[] = $blog;
			}
			$this->configuration();
			$this->start_lock_guards();
			$this->start_and_return();
			$this->validation();
			$this->accounts();
			$this->recovery();
			$this->edge_cases();
			$this->failures();
			$this->concurrency_and_headers();
			$this->start_limit_concurrency();
			$this->ok( $this->auth > 0 && get_current_user_id() > 0, 'successful completion now authenticates through Phase 4b' );
			$this->ok( 0 === $this->mails, 'no welcome or invitation mail' );
			foreach ( $this->people as $id ) {
				$link = $this->repository->link( 'phase4a', $id );
				if ( is_array( $link ) && $link['wp_user_id'] ) {
					wp_cache_delete( (int) $link['wp_user_id'], 'user_meta' );
					$has_login = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE action = %s AND target_id = %s', Migrations::audit_log_table(), 'portal_sso.login', (string) $link['wp_user_id'] ) ) > 0;
					$this->ok( $has_login === (bool) get_user_meta( (int) $link['wp_user_id'], 'session_tokens', true ), 'only completed, audited logins create sessions' );
				}
			}
			$logs = $wpdb->get_col( $wpdb->prepare( 'SELECT context FROM %i WHERE action LIKE %s', Migrations::audit_log_table(), 'portal_sso.%' ) );
			$logs = implode( '', $logs );
			foreach ( array_merge( $this->secrets, [ BINGOCOMMERCE_PORTAL_SSO_TOKEN, str_repeat( 'a', 64 ) ] ) as $secret ) {
				$this->ok( ! str_contains( $logs, $secret ), 'no credential in SSO audits' );
			}
		} finally { $this->cleanup(); }
		echo "PASS: {$this->assertions} Phase 4a SSO provisioning assertions.\n";
	}

	public function http( $pre, array $args, string $url ) {
		++$this->calls;
		$this->ok( 'https://portal.example.invalid/integration/v1/sso/exchange' === $url, 'only mocked fixed exchange destination' );
		$this->ok( 'POST' === $args['method'] && 10 === $args['timeout'] && 0 === $args['redirection'] && [] === $args['cookies'], 'transport method/timeout/no redirects/no cookies' );
		$this->ok( 'Bearer fixture-sso-secret-token' === $args['headers']['Authorization'] && 'application/json' === $args['headers']['Content-Type'], 'bearer header and JSON' );
		$body = json_decode( $args['body'], true );
		$this->ok( [ 'code', 'attempt_reference', 'proof' ] === array_keys( $body ), 'exact request body fields' );
		foreach ( $body as $value ) { $this->ok( SsoExchangeValidator::hex( $value ), 'wire field exact lowercase hex' ); }
		// The existing creation lock must still be free during network I/O.
		global $wpdb;
		$lock = 'bc:hsu:' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->base_prefix . '|' . get_current_network_id() ), 0, 32 ) . ':create';
		$this->ok( '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $lock ) ), 'no creation lock held during HTTP' );
		$ip = $wpdb->get_var( $wpdb->prepare( 'SELECT ip_fingerprint FROM %i WHERE attempt_reference_hash = %s', Schema::attempts_table(), hash( 'sha256', $body['attempt_reference'] ) ) );
		if ( is_string( $ip ) ) {
			$start_lock = 'bc:sso:start:' . substr( $ip, 0, 51 );
			$this->ok( '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $start_lock ) ), 'no start lock held during HTTP' );
		}
		return [ 'response' => [ 'code' => $this->status ], 'headers' => [], 'body' => json_encode( $this->answer ) ];
	}

	private function identity( int $blog = 2 ): array {
		$id = ++$this->sequence;
		$this->people[] = $id;
		$reference = wp_generate_uuid4();
		$this->references[] = $reference;
		return [ 'installation_id' => 'phase4a', 'portal_user_id' => $id, 'portal_user_created_at' => '2026-10-13T14:05:09Z', 'portal_hall_id' => $this->halls[ $blog ], 'name' => 'Hall Owner', 'email' => 'sso' . $id . '@example.invalid', 'sso_reference' => $reference ];
	}

	private function provision( array $identity, int $blog = 2 ) {
		switch_to_blog( $blog );
		try { return ( new SsoProvisioningService() )->provision( $identity, $blog, $this->halls[ $blog ], $identity['sso_reference'] ); }
		finally { restore_current_blog(); }
	}

	private function attempt( array &$identity, int $blog = 2 ): array {
		switch_to_blog( $blog );
		try { $start = SsoLogin::prepare_start( '192.0.2.' . random_int( 1, 190 ) ); }
		finally { restore_current_blog(); }
		$this->ok( is_array( $start ), 'start succeeds' );
		parse_str( parse_url( $start['url'], PHP_URL_QUERY ), $query );
		$identity['sso_reference'] = $query['sso_reference'];
		$this->references[] = $query['sso_reference'];
		$this->secrets[] = $start['browser_secret'];
		$row = $this->repository->attempt( $query['attempt_reference'], $blog );
		$this->secrets[] = $row['secret_proof'];
		return [ 'attempt' => $query['attempt_reference'], 'browser' => $start['browser_secret'], 'query' => $query, 'row' => $row ];
	}

	private function configuration(): void {
		foreach ( [ [ 'missing_token' => true ], [ 'missing_installation' => true ], [ 'token' => '' ], [ 'token' => "bad\nsecret" ], [ 'installation' => 'UpperCase' ], [ 'installation' => str_repeat( 'a', 21 ) ] ] as $case ) {
			$result = json_decode( $this->workers( [ array_merge( [ 'mode' => 'config' ], $case ) ] )[0], true );
			$this->ok( ! $result['sso'] && $result['snapshot'] && $result['safe'] && $result['start_refused'] && $result['return_refused'], 'invalid/missing SSO does not alter snapshot or disclose credential' );
		}
		$result = json_decode( $this->workers( [ [ 'mode' => 'config' ] ] )[0], true );
		$this->ok( $result['sso'] && $result['snapshot'] && $result['safe'], 'valid separate credentials and safe debug/error output' );
		foreach ( [ 'http://portal.example.invalid', 'https://user:pass@portal.example.invalid', 'https://portal.example.invalid?x=1' ] as $url ) {
			$result = json_decode( $this->workers( [ [ 'mode' => 'config', 'base_url' => $url ] ] )[0], true );
			$this->ok( ! $result['sso'] && $result['safe'], 'unsafe production SSO URL refused' );
		}
	}

	private function start_and_return(): void {
		global $wpdb;
		$before = $this->audit_count();
		for ( $i = 0; $i < 10; ++$i ) { $this->ok( is_array( SsoLogin::prepare_start( '198.51.100.17' ) ), 'ten starts allowed' ); }
		$this->ok( is_wp_error( SsoLogin::prepare_start( '198.51.100.17' ) ), 'eleventh start refused' );
		$this->ok( is_wp_error( SsoLogin::prepare_start( 'spoofed' ) ), 'invalid direct peer address refused' );
		$this->ok( $before === $this->audit_count(), 'starts and early refusals unaudited' );
		$identity = $this->identity(); $attempt = $this->attempt( $identity );
		$this->ok( hash( 'sha256', $attempt['row']['secret_proof'] ) === $attempt['query']['proof_fingerprint'], 'proof fingerprint hashes hex string' );
		$this->ok( ! str_contains( json_encode( $attempt['query'] ), $attempt['browser'] ) && $attempt['browser'] !== $attempt['attempt'], 'private browser secret never in URL' );
		$this->ok( hash( 'sha256', $attempt['attempt'] ) === $attempt['row']['attempt_reference_hash'] && hash( 'sha256', $attempt['browser'] ) === $attempt['row']['browser_secret_fingerprint'], 'only fingerprints stored for browser and attempt' );
		$this->ok( 300 === strtotime( $attempt['row']['expires_at'] . ' UTC' ) - strtotime( $attempt['row']['created_at'] . ' UTC' ), 'five-minute lifetime' );
		$before = $this->audit_count(); $calls = $this->calls;
		foreach ( [ null, '', str_repeat( 'd', 64 ), $attempt['attempt'] ] as $cookie ) {
			$this->ok( is_wp_error( SsoLogin::complete( str_repeat( 'a', 64 ), $attempt['attempt'], $cookie ) ), 'copied callback/forged cookie refused' );
		}
		switch_to_blog( 3 );
		$this->ok( is_wp_error( SsoLogin::complete( str_repeat( 'a', 64 ), $attempt['attempt'], $attempt['browser'] ) ), 'cross-Hall callback refused' );
		restore_current_blog();
		$this->ok( $calls === $this->calls && $before === $this->audit_count(), 'pre-exchange failures have no HTTP or audit' );
		$this->answer = $identity;
		$result = SsoLogin::complete( str_repeat( 'a', 64 ), $attempt['attempt'], $attempt['browser'] );
		$this->ok( is_int( $result ), 'first complete flow provisions and signs in; ' . $this->last_failure . '; audits=' . json_encode( $wpdb->get_results( $wpdb->prepare( 'SELECT action,context FROM %i WHERE action LIKE %s ORDER BY id DESC LIMIT 3', Migrations::audit_log_table(), 'portal_sso.%' ), ARRAY_A ) ) );
		$this->ok( is_wp_error( SsoLogin::complete( str_repeat( 'a', 64 ), $attempt['attempt'], $attempt['browser'] ) ) && $calls + 1 === $this->calls, 'replay never exchanges again' );
		$expired_identity = $this->identity(); $expired = $this->attempt( $expired_identity );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET expires_at = %s WHERE attempt_reference_hash = %s', Schema::attempts_table(), gmdate( 'Y-m-d H:i:s', time() - 1 ), hash( 'sha256', $expired['attempt'] ) ) );
		$this->ok( is_wp_error( SsoLogin::complete( str_repeat( 'a', 64 ), $expired['attempt'], $expired['browser'] ) ), 'expiry enforced before cleanup' );
		// Expired cleanup remains bounded even after a long idle period.
		for ( $i = 0; $i < 501; ++$i ) {
			$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (attempt_reference_hash,browser_secret_fingerprint,secret_proof,ip_fingerprint,blog_id,sso_reference,created_at,expires_at) VALUES (%s,%s,%s,%s,%d,%s,%s,%s)', Schema::attempts_table(), hash( 'sha256', 'expired-' . $i ), str_repeat( 'b', 64 ), str_repeat( 'c', 64 ), str_repeat( 'd', 64 ), 2, wp_generate_uuid4(), '2020-01-01 00:00:00', '2020-01-01 00:00:00' ) );
		}
		$this->ok( 500 === $this->repository->prune(), 'cleanup deletes at most 500 using expiry' );
		$this->ok( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE expires_at = %s', Schema::attempts_table(), '2020-01-01 00:00:00' ) ) === 1, 'one old row remains for next batch' );
	}

	private function validation(): void {
		$identity = $this->identity(); $config = PortalConfig::fromSsoConstants();
		$this->ok( is_array( SsoExchangeValidator::validate( json_encode( $identity ), $config, $this->halls[2], $identity['sso_reference'] ) ), 'exact seven-field reply valid' );
		$invalid = [ array_merge( $identity, [ 'extra' => true ] ) ];
		$missing = $identity; unset( $missing['email'] ); $invalid[] = $missing;
		foreach ( [ 'installation_id' => 'another', 'portal_user_id' => '42', 'portal_hall_id' => $this->halls[3], 'portal_user_created_at' => '2026-02-30T14:05:09Z', 'sso_reference' => strtoupper( $identity['sso_reference'] ), 'name' => [], 'email' => null ] as $key => $value ) { $invalid[] = array_replace( $identity, [ $key => $value ] ); }
		foreach ( $invalid as $data ) { $this->ok( is_wp_error( SsoExchangeValidator::validate( json_encode( $data ), $config, $this->halls[2], $identity['sso_reference'] ) ), 'malformed/mismatched response refused' ); }
		foreach ( [ 400, 401, 403, 429, 503, 302, 500 ] as $status ) {
			$attempt = $this->attempt( $identity ); $this->status = $status; $this->answer = [ 'secret' => BINGOCOMMERCE_PORTAL_SSO_TOKEN ];
			$this->ok( is_wp_error( SsoLogin::complete( str_repeat( 'a', 64 ), $attempt['attempt'], $attempt['browser'] ) ), 'HTTP refusal or redirect never provisions: ' . $status );
		}
		$this->status = 200;
		$attempt = $this->attempt( $identity ); $this->answer = array_replace( $identity, [ 'portal_hall_id' => $this->halls[3] ] );
		$this->ok( is_wp_error( SsoLogin::complete( str_repeat( 'a', 64 ), $attempt['attempt'], $attempt['browser'] ) ), 'wrong Hall answer never provisions' );
		$this->ok( null === $this->repository->link( 'phase4a', $identity['portal_user_id'] ), 'failed exchange created no link' );
	}

	private function accounts(): void {
		global $wpdb;
		$identity = $this->identity(); $user = $this->provision( $identity );
		$this->ok( is_int( $user ), 'SSO account created' );
		$link = $this->repository->link( 'phase4a', $identity['portal_user_id'] );
		$account = get_userdata( $user );
		$this->ok( $account->user_login === 'portal-phase4a-' . $identity['portal_user_id'] && $account->display_name === $identity['name'] && 1 === preg_match( '/\A[0-9a-f]{32}\z/', $account->user_nicename ), 'identity username, display name and random public slug' );
		$this->ok( [ [ 'meta_value' => serialize( [ 'bc_hall_manager' => true ] ) ] ] === $this->repository->current_roles( $user, 2 ) && [] === $this->repository->current_roles( $user, 3 ), 'HM current Hall only' );
		$this->ok( 'portal_sso' === get_user_meta( $user, 'bc_identity_type', true ), 'SSO marker before creation audit' );
		$original = $link;
		$identity['email'] = 'changed@example.invalid'; $identity['sso_reference'] = wp_generate_uuid4();
		$this->ok( $user === $this->provision( $identity ), 'later login keeps account despite Portal email change' );
		$identity['portal_hall_id'] = $this->halls[3];
		$this->ok( $user === $this->provision( $identity, 3 ), 'one person on another Hall reuses account' );
		$this->ok( $original === $this->repository->link( 'phase4a', $identity['portal_user_id'] ), 'original creation facts remain immutable' );
		$this->ok( 1 === $this->creation_count( $link['creation_operation_id'] ), 'creation audit written exactly once' );
		$bad = $identity; $bad['portal_user_created_at'] = '2026-10-14T14:05:09Z';
		$this->error( 'restore_mismatch', $this->provision( $bad, 3 ), 'restored Portal identity refused' );
		grant_super_admin( $user );
		$this->error( 'super_admin', $this->provision( $bad, 3 ), 'Super Admin refusal precedes restore mismatch' );
		revoke_super_admin( $user );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET meta_value = %s WHERE user_id = %d AND meta_key = %s', $wpdb->usermeta, serialize( [ 'bc_game_operator' => true ] ), $user, $wpdb->get_blog_prefix( 3 ) . 'capabilities' ) );
		$this->error( 'access_removed', $this->provision( $identity, 3 ), 'explicit other role never promoted' );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET user_login = %s WHERE ID = %d', $wpdb->users, 'renamed' . $user, $user ) );
		$this->error( 'account_missing', $this->provision( $identity, 3 ), 'renamed linked account not replaced' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE ID = %d', $wpdb->users, $user ) );
		$this->error( 'account_missing', $this->provision( $identity, 3 ), 'deleted linked account not recreated' );
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (ID,user_login,user_pass,user_email,user_registered) VALUES (%d,%s,%s,%s,%s)', $wpdb->users, $user, 'ordinary-reused-' . $user, wp_hash_password( 'fixture-password' ), 'reused' . $user . '@example.invalid', gmdate( 'Y-m-d H:i:s' ) ) );
		$unrelated = $this->repository->account( $user );
		$this->error( 'account_missing', $this->provision( $identity, 3 ), 'reused numeric ID never selects unrelated account' );
		$this->ok( $unrelated === $this->repository->account( $user ), 'unrelated replacement account unchanged' );


		foreach ( [ 'customer', 'bc_hall_manager' ] as $role ) {
			$person = $this->identity(); $normal = wpmu_create_user( 'portal-mike-x' . $person['portal_user_id'], 'fixture-password', $person['email'] ); $this->ok( is_int( $normal ) && $normal > 0, 'ordinary account creation succeeds' ); $this->users[] = $normal;
			$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (user_id,meta_key,meta_value) VALUES (%d,%s,%s)', $wpdb->usermeta, $normal, $wpdb->prefix . 'capabilities', serialize( [ $role => true ] ) ) );
			$before = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d', $wpdb->usermeta, $normal ), ARRAY_A );
			$this->error( 'email_conflict', $this->provision( $person ), 'ordinary ' . $role . ' email is never linked' );
			$this->ok( null === $this->repository->link( 'phase4a', $person['portal_user_id'] ) && $before === $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d', $wpdb->usermeta, $normal ), ARRAY_A ), 'ordinary account completely unchanged' );
			$this->ok( wp_authenticate( 'portal-mike-x' . $person['portal_user_id'], 'fixture-password' ) instanceof WP_User, 'ordinary portal-prefix password still works' );
		}
		foreach ( [ 'not an email', str_repeat( 'a', 90 ) . '@example.invalid' ] as $email ) {
			$person = $this->identity(); $person['email'] = $email;
			$this->error( 'invalid_email', $this->provision( $person ), 'invalid/over-100 email refused before link' );
			$this->ok( null === $this->repository->link( 'phase4a', $person['portal_user_id'] ), 'invalid email leaves no link' );
		}
		$person = $this->identity(); $login = 'portal-phase4a-' . $person['portal_user_id'];
		$orphan = SsoAccountGuard::with_planned_username( $login, fn() => wpmu_create_user( $login, 'fixture-password', $person['email'] ) ); $this->users[] = $orphan;
		$this->error( 'username_occupied', $this->provision( $person ), 'unexplained username never adopted' );
	}

	private function recovery(): void {
		global $wpdb;
		foreach ( [ 'after-link', 'inside-create', 'after-create', 'after-attach' ] as $fault ) {
			$identity = $this->identity();
			$result = $this->workers( [ [ 'mode' => 'provision', 'blog' => 2, 'hall' => $this->halls[2], 'identity' => $identity, 'fault' => $fault ] ] )[0];
			$this->ok( 'INTERRUPTED' === $result, 'real interruption: ' . $fault );
			$link = $this->repository->link( 'phase4a', $identity['portal_user_id'] );
			$this->ok( is_array( $link ), 'original pending link survives interruption' );
			$this->ok( 'after-attach' === $fault ? null !== $link['wp_user_id'] : null === $link['wp_user_id'], 'expected NULL/populated link at interruption' );
			$identity['sso_reference'] = wp_generate_uuid4(); $identity['portal_hall_id'] = $this->halls[3];
			$user = $this->provision( $identity, 3 );
			$this->ok( is_int( $user ), 'another Hall finishes interrupted ' . $fault );
			$after = $this->repository->link( 'phase4a', $identity['portal_user_id'] );
			foreach ( [ 'creation_blog_id', 'creation_operation_id', 'creation_sso_reference', 'created_at' ] as $field ) { $this->ok( $link[ $field ] === $after[ $field ], 'recovery retains ' . $field ); }
			$this->ok( [] === $this->repository->current_roles( $user, 2 ) && is_array( $this->repository->current_roles( $user, 3 ) ), 'recovery grants only retry Hall' );
			$this->ok( 1 === $this->creation_count( $link['creation_operation_id'] ), 'recovered creation audit exactly once' );
			$this->ok( 'Hall Owner' === get_userdata( $user )->display_name && 1 === preg_match( '/\A[0-9a-f]{32}\z/', get_userdata( $user )->user_nicename ), 'recovery completes naming' );
		}
		// An account predating its pending link is not creation proof.
		$identity = $this->identity(); $this->pending( $identity );
		$user = $this->pending_account( $identity );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET user_registered = %s WHERE ID = %d', $wpdb->users, '2000-01-01 00:00:00', $user ) );
		$this->error( 'recovery_unproven', $this->provision( $identity ), 'older account cannot be recovered' );
		$identity = $this->identity(); $this->pending( $identity ); $user = $this->pending_account( $identity );
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (user_id,meta_key,meta_value) VALUES (%d,%s,%s)', $wpdb->usermeta, $user, $wpdb->get_blog_prefix( 3 ) . 'capabilities', serialize( [] ) ) );
		$this->error( 'recovery_unproven', $this->provision( $identity ), 'empty foreign Hall role record cannot be cleaned' );
		$identity = $this->identity(); $this->pending( $identity );
		$other = wpmu_create_user( 'email-racer-' . $identity['portal_user_id'], 'fixture-password', $identity['email'] ); $this->users[] = $other;
		$this->error( 'email_conflict', $this->provision( $identity ), 'email taken while pending gets conflict' );
		$this->ok( null === $this->repository->link( 'phase4a', $identity['portal_user_id'] ), 'empty pending link deleted on email conflict' );
	}

	private function pending( array $identity ): void {
		$this->ok( 1 === $this->repository->create_link( $identity, 'portal-phase4a-' . $identity['portal_user_id'], 2, $identity['sso_reference'] ), 'fixture pending link' );
	}
	private function pending_account( array $identity ): int {
		$login = 'portal-phase4a-' . $identity['portal_user_id'];
		$user = SsoAccountGuard::with_planned_username( $login, fn() => wpmu_create_user( $login, 'fixture-password', $identity['email'] ) );
		$this->ok( is_int( $user ), 'fixture interrupted account' ); return $user;
	}

	private function edge_cases(): void {
		global $wpdb;
		// Normal creation can silently leave its temporary meta behind.
		$prevent_delete = static function ( $check, $id, $key ) {
			return str_ends_with( $key, 'capabilities' ) || str_ends_with( $key, 'user_level' ) ? false : $check;
		};
		$identity = $this->identity();
		add_filter( 'delete_user_metadata', $prevent_delete, 10, 3 );
		try { $user = $this->provision( $identity ); }
		finally { remove_filter( 'delete_user_metadata', $prevent_delete, 10 ); }
		$this->ok( is_int( $user ) && 1 === HallStaffEligibility::count_memberships( $user ), 'silent WP temporary deletion failure cleaned before grant' );

		foreach ( [ 'empty', 'different-role', 'super-admin', 'linked-elsewhere', 'random-slug' ] as $case ) {
			$identity = $this->identity(); $this->pending( $identity ); $user = $this->pending_account( $identity );
			if ( 'empty' === $case || 'different-role' === $case ) {
				$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (user_id,meta_key,meta_value) VALUES (%d,%s,%s)', $wpdb->usermeta, $user, $wpdb->prefix . 'capabilities', serialize( 'empty' === $case ? [] : [ 'editor' => true ] ) ) );
				$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (user_id,meta_key,meta_value) VALUES (%d,%s,%s)', $wpdb->usermeta, $user, $wpdb->prefix . 'user_level', '0' ) );
			}
			if ( 'super-admin' === $case ) { grant_super_admin( $user ); }
			if ( 'linked-elsewhere' === $case ) {
				$other = $this->identity(); $this->pending( $other );
				$this->repository->attach( $this->repository->link( 'phase4a', $other['portal_user_id'] ), $user );
			}
			if ( 'random-slug' === $case ) { wp_update_user( [ 'ID' => $user, 'user_nicename' => 'already-random-public-slug' ] ); }
			$result = $this->provision( $identity );
			if ( in_array( $case, [ 'empty', 'random-slug' ], true ) ) {
				$this->ok( $user === $result, 'recovery succeeds: ' . $case );
				if ( 'random-slug' === $case ) { $this->ok( 'already-random-public-slug' === get_userdata( $user )->user_nicename, 'existing random slug preserved' ); }
			} else { $this->error( 'recovery_unproven', $result, 'unexplained recovery refused: ' . $case ); }
			if ( 'super-admin' === $case ) { revoke_super_admin( $user ); }
		}

		// Failed narrow cleanup leaves NULL, then the next attempt can recover.
		$identity = $this->identity(); $this->pending( $identity ); $user = $this->pending_account( $identity );
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (user_id,meta_key,meta_value) VALUES (%d,%s,%s)', $wpdb->usermeta, $user, $wpdb->prefix . 'capabilities', serialize( [] ) ) );
		$fail_cleanup = static fn( $sql ) => str_starts_with( $sql, 'DELETE FROM `' . $GLOBALS['wpdb']->usermeta . '` WHERE umeta_id' ) ? 'SELECT * FROM bc_sso_absent_cleanup_fixture' : $sql;
		add_filter( 'query', $fail_cleanup );
		try { $this->error( 'recovery_unproven', $this->provision( $identity ), 'narrow cleanup failure refused' ); }
		finally { remove_filter( 'query', $fail_cleanup ); }
		$this->ok( null === $this->repository->link( 'phase4a', $identity['portal_user_id'] )['wp_user_id'], 'cleanup failure leaves NULL link' );
		$this->ok( $user === $this->provision( $identity ), 'retry finishes cleanup' );

		$identity = $this->identity();
		$wpdb->query( $wpdb->prepare( 'SET timestamp = %d', 946684800 ) );
		try {
			$this->pending( $identity ); $user = $this->pending_account( $identity );
			$link = $this->repository->link( 'phase4a', $identity['portal_user_id'] );
			$account = $this->repository->account( $user );
			$this->ok( str_starts_with( $link['created_at'], gmdate( 'Y-m-d' ) ) && str_starts_with( $account['user_registered'], gmdate( 'Y-m-d' ) ), 'link/account use PHP UTC despite database year 2000' );
			// Explicit equal-second boundary, retaining the link's PHP timestamp.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET user_registered = %s WHERE ID = %d', $wpdb->users, $link['created_at'], $user ) );
			$this->ok( $user === $this->provision( $identity ), 'same-second recovery with a different DB clock' );
		} finally { $wpdb->query( $wpdb->prepare( 'SET timestamp = %d', 0 ) ); }

		// Two pending NULL links and concurrent retries cannot adopt or duplicate.
		$one = $this->identity(); $two = $this->identity(); $this->pending( $one ); $this->pending( $two );
		$job = [ 'mode' => 'provision', 'blog' => 2, 'hall' => $this->halls[2], 'identity' => $one, 'start' => microtime( true ) + 2 ];
		$results = array_map( fn( $r ) => json_decode( $r, true ), $this->workers( [ $job, $job ] ) );
		$this->ok( is_int( $results[0]['result'] ) && $results[0]['result'] === $results[1]['result'], 'simultaneous recovery reuses one account' );
		$this->ok( is_int( $this->provision( $two ) ), 'another pending NULL user does not block creation' );

		// A Portal outage/refusal never modifies existing identity or session data.
		$identity = $this->identity(); $attempt = $this->attempt( $identity );
		$fail_http = static fn() => new WP_Error( 'timeout', 'fixture-sso-secret-token' );
		add_filter( 'pre_http_request', $fail_http, 11 );
		try { $result = SsoLogin::complete( str_repeat( 'a', 64 ), $attempt['attempt'], $attempt['browser'] ); }
		finally { remove_filter( 'pre_http_request', $fail_http, 11 ); }
		$this->ok( is_wp_error( $result ) && ! str_contains( serialize( $result ), BINGOCOMMERCE_PORTAL_SSO_TOKEN ), 'transport timeout reduced to secret-free error' );

		foreach ( [ 'portal_sso.exchanged', 'portal_sso.account_ready' ] as $action ) {
			$identity = $this->identity(); $attempt = $this->attempt( $identity ); $this->answer = $identity;
			$auth_before = $this->auth;
			$fail = static fn( $sql ) => str_starts_with( $sql, 'INSERT INTO `' . Migrations::audit_log_table() . '`' ) && str_contains( $sql, $action ) ? 'SELECT * FROM bc_sso_absent_terminal_audit_fixture' : $sql;
			add_filter( 'query', $fail );
			try { $result = SsoLogin::complete( str_repeat( 'a', 64 ), $attempt['attempt'], $attempt['browser'] ); }
			finally { remove_filter( 'query', $fail ); }
			$this->ok( is_wp_error( $result ) && $auth_before === $this->auth, 'required exchange/ready audit failure refuses success' );
		}

		$identity = $this->identity(); $attempt = $this->attempt( $identity ); $this->answer = $identity;
		$disable = static function ( $response ) { update_blog_status( 2, 'archived', 1 ); return $response; };
		add_filter( 'pre_http_request', $disable, 12 );
		try {
			$this->ok( is_wp_error( SsoLogin::complete( str_repeat( 'a', 64 ), $attempt['attempt'], $attempt['browser'] ) ), 'store disabled during exchange refuses provisioning' );
			$this->ok( null === $this->repository->link( 'phase4a', $identity['portal_user_id'] ), 'disabled store created no link' );
		} finally { remove_filter( 'pre_http_request', $disable, 12 ); update_blog_status( 2, 'archived', 0 ); }
	}

	private function failures(): void {
		global $wpdb;
		$identity = $this->identity();
		$break = static fn( $sql ) => str_starts_with( $sql, 'INSERT INTO `' . Migrations::audit_log_table() . '`' ) && str_contains( $sql, 'hall_staff.user_created' ) ? 'SELECT * FROM bc_sso_absent_creation_audit_fixture' : $sql;
		add_filter( 'query', $break );
		try { $this->error( 'creation_audit_failed', $this->provision( $identity ), 'creation audit failure grants nothing' ); }
		finally { remove_filter( 'query', $break ); }
		$link = $this->repository->link( 'phase4a', $identity['portal_user_id'] );
		$this->ok( $link['wp_user_id'] && [] === $this->repository->current_roles( (int) $link['wp_user_id'], 2 ), 'linked account remains without role' );
		$this->ok( (int) $link['wp_user_id'] === $this->provision( $identity ), 'retry completes original creation audit' );
		$identity = $this->identity();
		$this->trigger = 'bc_sso_4a_' . bin2hex( random_bytes( 4 ) );
		$this->ok( false !== $wpdb->query( $wpdb->prepare( 'CREATE TRIGGER %i BEFORE INSERT ON %i FOR EACH ROW BEGIN IF NEW.action = %s THEN SIGNAL SQLSTATE %s SET MESSAGE_TEXT = %s; END IF; END', $this->trigger, Migrations::audit_log_table(), 'hall_staff.assigned', '45000', 'fixture failure' ) ), 'fixture mandatory grant audit failure trigger' );
		try { $this->error( 'grant_failed', $this->provision( $identity ), 'mandatory grant audit failure refused' ); }
		finally { $wpdb->query( $wpdb->prepare( 'DROP TRIGGER %i', $this->trigger ) ); $this->trigger = null; }
		$link = $this->repository->link( 'phase4a', $identity['portal_user_id'] );
		$this->ok( [] === $this->repository->current_roles( (int) $link['wp_user_id'], 2 ), 'grant and mandatory audit roll back together' );
		$this->ok( (int) $link['wp_user_id'] === $this->provision( $identity ), 'grant retry reuses account' );
		$break = static fn( $sql ) => str_contains( $sql, Schema::links_table() ) && str_starts_with( $sql, 'SELECT' ) ? 'SELECT * FROM bc_sso_absent_links_fixture' : $sql;
		add_filter( 'query', $break );
		try { $this->error( 'storage_failed', $this->provision( $identity ), 'unreadable links never treated as absent' ); }
		finally { remove_filter( 'query', $break ); }
	}

	private function concurrency_and_headers(): void {
		global $wpdb;
		$identity = $this->identity();
		$job = [ 'mode' => 'provision', 'blog' => 2, 'hall' => $this->halls[2], 'identity' => $identity, 'start' => microtime( true ) + 2 ];
		$results = array_map( fn( $r ) => json_decode( $r, true ), $this->workers( [ $job, $job ] ) );
		$this->ok( is_int( $results[0]['result'] ) && $results[0]['result'] === $results[1]['result'], 'concurrent first logins share one account' );
		$this->ok( 1 === count( $this->repository->accounts( 'portal-phase4a-' . $identity['portal_user_id'] ) ), 'one account after first-login race' );
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE action = %s AND target_id = %s', Migrations::audit_log_table(), 'hall_staff.assigned', (string) $results[0]['result'] ) );
		$this->ok( '1' === (string) $count, 'one mandatory grant in race' );
		$identity = $this->identity(); $attempt = $this->attempt( $identity );
		$job = [ 'mode' => 'complete', 'consume_at' => microtime( true ) + 3, 'blog' => 2, 'identity' => $identity, 'attempt' => $attempt['attempt'], 'browser' => $attempt['browser'], 'start' => microtime( true ) + 2 ];
		$results = array_map( fn( $r ) => json_decode( $r, true ), $this->workers( [ $job, $job ] ) );
		$this->ok( 1 === $results[0]['http'] + $results[1]['http'], 'concurrent callbacks exchange exactly once' );
		foreach ( $results as $result ) { $this->ok( is_int( $result['result'] ) ? ( 2 === $result['auth'] && $result['result'] === $result['current_user'] && $result['marked_session'] ) : ( 0 === $result['auth'] && 0 === $result['current_user'] && ! $result['marked_session'] ), 'only the winning callback signs in with a marked session' ); }
		$headers = $this->workers( [ [ 'mode' => 'cgi-start', 'ip' => '203.0.113.211' ] ], true )[0];
		$this->ok( str_contains( $headers, 'Set-Cookie: __Host-bc_portal_sso=' ) && str_contains( strtolower( $headers ), 'httponly' ) && str_contains( strtolower( $headers ), 'samesite=lax' ) && str_contains( strtolower( $headers ), 'secure' ) && ! str_contains( strtolower( $headers ), 'domain=' ), 'real HTTPS cookie is host-only secure/HttpOnly/Lax: ' . preg_replace( [ '/(__Host-bc_portal_sso=)[^;]+/', '/(Location: [^?]+)\?[^\r\n]+/' ], [ '$1[redacted]', '$1?[redacted]' ], $headers ) );
		$this->ok( str_contains( $headers, 'Referrer-Policy: no-referrer' ) && str_contains( strtolower( $headers ), 'no-store' ) && str_contains( $headers, 'Location: https://portal.example.invalid/commerce/sso/authorize?' ), 'start real headers and trusted Portal redirect' );
		$identity = $this->identity(); $attempt = $this->attempt( $identity );
		$headers = $this->workers( [ [ 'mode' => 'cgi-return', 'identity' => $identity, 'attempt' => $attempt['attempt'], 'browser' => $attempt['browser'] ] ], true )[0];
		$this->ok( str_contains( $headers, '/wp-admin/' ) && ! str_contains( $headers, 'bc_portal_sso_message' ) && ! str_contains( $headers, 'code=' ), 'successful return one clean dashboard redirect' );
		$this->ok( 1 === substr_count( $headers, 'Location:' ) && str_contains( $headers, 'wordpress_logged_in_' ) && str_contains( $headers, 'wordpress_sec_' ), 'one redirect and WordPress auth cookies' );
		$this->ok( str_contains( $headers, 'Referrer-Policy: no-referrer' ) && str_contains( strtolower( $headers ), 'no-store' ) && str_contains( $headers, '__Host-bc_portal_sso=deleted' ), 'return clears private cookie and prevents caching/referrer' );
	}

	private function start_lock_guards(): void {
		global $wpdb;
		$ip = '203.0.113.249';
		$fingerprint = hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
		$queries = []; $inside = false; $lock = null; $wait = null;
		$observe = static function ( $sql ) use ( &$queries, &$inside, &$lock, &$wait ) {
			if ( preg_match( "/^SELECT GET_LOCK\('(bc:sso:start:[0-9a-f]+)', ([0-9]+)\)$/", $sql, $match ) ) {
				$lock = $match[1]; $wait = (int) $match[2]; $inside = true;
			} elseif ( str_starts_with( $sql, 'SELECT RELEASE_LOCK(' ) ) { $inside = false; }
			elseif ( $inside ) { $queries[] = $sql; }
			return $sql;
		};
		add_filter( 'query', $observe );
		try { $result = SsoLogin::prepare_start( $ip ); }
		finally { remove_filter( 'query', $observe ); }
		$this->ok( is_array( $result ), 'start protected by IP lock succeeds' );
		$this->ok( is_string( $lock ) && strlen( $lock ) <= 64 && 'bc:sso:start:' . substr( $fingerprint, 0, 51 ) === $lock && ! str_contains( $lock, $ip ), 'lock derives only from keyed fingerprint with SSO prefix and at most 64 characters' );
		$this->ok( 1 === $wait, 'GET_LOCK waits at most one second' );
		$this->ok( 2 === count( $queries ) && str_starts_with( $queries[0], 'SELECT COUNT(*) AS total FROM `' . Schema::attempts_table() ) && str_starts_with( $queries[1], 'INSERT INTO `' . Schema::attempts_table() ), 'lock covers only count and insert, not cleanup/mapping/redirect' );
		$this->ok( '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $lock ) ), 'successful start releases lock' );

		// A different connection holds the same lock throughout a real timeout.
		$before = $this->repository->recent_starts( $fingerprint ); $audit = $this->audit_count();
		$this->ok( '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 0 ) ), 'fixture holds start lock' );
		try { $blocked = json_decode( $this->workers( [ [ 'mode' => 'start', 'ip' => $ip, 'measure' => true ] ] )[0], true ); }
		finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
		$this->ok( 'refused' === $blocked['result'] && 0 === $blocked['http'] && [ [ 'name' => $lock, 'wait' => 1 ] ] === $blocked['locks'], 'lock timeout fails closed without HTTP' );
		$this->ok( $blocked['elapsed'] >= 0.8 && $blocked['elapsed'] < 2.5, 'one-second lock timeout observed, allowing process scheduling overhead' );
		$this->ok( $before === $this->repository->recent_starts( $fingerprint ) && $audit === $this->audit_count(), 'failed acquisition inserts and audits nothing' );

		foreach ( [ 'acquire', 'count', 'insert', 'throw' ] as $failure ) {
			$break = static function ( $sql ) use ( $failure ) {
				$matches = 'acquire' === $failure ? str_starts_with( $sql, "SELECT GET_LOCK('bc:sso:start:" )
					: ( 'count' === $failure ? str_starts_with( $sql, 'SELECT COUNT(*) AS total FROM `' . Schema::attempts_table() ) : str_starts_with( $sql, 'INSERT INTO `' . Schema::attempts_table() ) );
				if ( ! $matches ) { return $sql; }
				if ( 'throw' === $failure ) { throw new RuntimeException( 'Fixture insert interruption.' ); }
				return 'SELECT * FROM bc_sso_absent_start_lock_fixture';
			};
			add_filter( 'query', $break );
			try { $result = SsoLogin::prepare_start( $ip ); }
			finally { remove_filter( 'query', $break ); }
			$this->ok( is_wp_error( $result ) && $before === $this->repository->recent_starts( $fingerprint ), $failure . ' failure inserts nothing' );
			$this->ok( '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $lock ) ), $failure . ' failure leaves no held lock' );
		}
		$this->ok( is_array( SsoLogin::prepare_start( $ip ) ), 'a fresh start works after failures' );
	}

	private function start_limit_concurrency(): void {
		$ip = '203.0.113.250';
		for ( $i = 0; $i < 9; ++$i ) { $this->ok( is_array( SsoLogin::prepare_start( $ip ) ), 'seed nine starts before boundary race' ); }
		$job = [ 'mode' => 'start', 'ip' => $ip, 'start' => microtime( true ) + 2, 'insert_at' => microtime( true ) + 3 ];
		$results = $this->workers( [ $job, $job ] );
		$created = count( array_filter( $results, static fn( $result ) => 'created' === $result ) );
		$this->ok( 1 === $created, 'concurrent boundary starts must create exactly one attempt; created=' . $created );
		$this->ok( 10 === $this->repository->recent_starts( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ) ), 'concurrent starts cannot exceed ten in a minute' );
	}

	private function workers( array $jobs, bool $cgi = false ): array {
		$children = [];
		foreach ( $jobs as $job ) {
			$command = $cgi ? [ dirname( PHP_BINARY ) . '/php-cgi' ] : [ PHP_BINARY, __DIR__ . '/portal-sso-provisioning-worker.php', json_encode( $job ) ];
			$env = null;
			if ( $cgi ) { $env = getenv(); $env['BC_SSO_JOB'] = json_encode( $job ); $env['SCRIPT_FILENAME'] = __DIR__ . '/portal-sso-provisioning-worker.php'; $env['BC_FIXTURE_CGI'] = '1'; $env['REDIRECT_STATUS'] = '1'; unset( $env['REMOTE_ADDR'], $env['SERVER_SOFTWARE'] ); }
			$process = proc_open( $command, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, null, $env );
			$this->ok( is_resource( $process ), 'isolated worker started' ); $children[] = [ $process, $pipes ];
		}
		$results = [];
		foreach ( $children as [ $process, $pipes ] ) {
			$output = stream_get_contents( $pipes[1] ); $error = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
			$this->ok( 0 === proc_close( $process ) && '' === $error, 'worker succeeds: ' . $error . ( str_contains( $output, 'Fatal' ) ? $output : '' ) );
			$results[] = trim( $output );
		}
		return $results;
	}
	private function audit_count(): int { global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE action LIKE %s', Migrations::audit_log_table(), 'portal_sso.%' ) ); }
	private function creation_count( string $op ): int { global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE operation_id = %s AND action = %s', Migrations::audit_log_table(), $op, 'hall_staff.user_created' ) ); }
	private function error( string $code, $result, string $message ): void { $this->ok( is_wp_error( $result ) && $result->get_error_code() === $code, $message . ( is_wp_error( $result ) ? ': ' . $result->get_error_code() : '' ) ); }
	private function ok( bool $condition, string $message ): void { ++$this->assertions; if ( ! $condition ) { $this->last_failure = $message; throw new RuntimeException( 'FAIL: ' . $message ); } }
	private function cleanup(): void {
		global $wpdb;
		if ( $this->trigger ) { $wpdb->query( $wpdb->prepare( 'DROP TRIGGER %i', $this->trigger ) ); }
		foreach ( $this->people as $person ) {
			$link = $this->repository->link( 'phase4a', $person );
			if ( is_array( $link ) && $link['wp_user_id'] ) { $this->users[] = (int) $link['wp_user_id']; }
			$rows = $this->repository->accounts( 'portal-phase4a-' . $person );
			if ( is_array( $rows ) ) { foreach ( $rows as $row ) { $this->users[] = (int) $row['ID']; } }
		}
		foreach ( array_unique( $this->users ) as $user ) {
			if ( ! $user ) { continue; }
			if ( is_super_admin( $user ) ) { revoke_super_admin( $user ); }
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', $wpdb->usermeta, $user ) ); clean_user_cache( $user );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE ID = %d', $wpdb->users, $user ) );
		}
		foreach ( $this->mappings as $blog ) { $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE blog_id = %d AND portal_hall_id = %d', MappingSchema::hall_mappings_table(), $blog, $this->halls[ $blog ] ) ); }
		if ( $this->schema ) { foreach ( [ Schema::links_table(), Schema::attempts_table() ] as $table ) { $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); } delete_site_option( Schema::VERSION_OPTION ); }
		while ( ms_is_switched() ) { restore_current_blog(); }
	}
}
( new Phase4aTest() )->run();
