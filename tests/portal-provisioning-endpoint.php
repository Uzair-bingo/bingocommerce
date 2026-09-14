<?php

/**
 * CLI coverage for the complete ADR-042 BingoCommerce machine provisioning path:
 * the REST boundary, Application Password authorization, manual validation, the
 * runtime schema gate, the provisioning orchestrator, the single platform filter
 * invocation, Hall mapping, the trusted provisioning Portal pull, SetupReadiness,
 * HallCommerceState, `archived` clearing, confirmation, the exact response
 * matrix and the terminal audit.
 *
 * Run from the repository root:
 *   BC_TEST_DB=bingocommerce_state_test php tests/portal-provisioning-endpoint.php
 *
 * WordPress boots against the isolated fixture — see
 * tests/fixtures/isolated-multisite.php. The development database is never
 * opened by the bootstrap, its identities are never borrowed, and persistent
 * object caching is off.
 *
 * NO LIVE PORTAL REQUEST. Outbound HTTP is blocked by the fixture before any
 * plugin can run. This harness short-circuits `pre_http_request` for EXACTLY the
 * Portal snapshot URL of EXACTLY the Halls under test and returns a fixture
 * body; every other outbound request still hits the fixture's blocker. Nothing
 * leaves the process, and the real PortalConfig, PortalHttpClient,
 * PortalSnapshotValidator, plan and transaction transport all run.
 *
 * NO MAIL. `pre_wp_mail` is short-circuited and counted before the first site
 * exists.
 *
 * Every site, user, Application Password, mapping, operation row, state row and
 * table this run creates is recorded as it is created and removed in teardown,
 * and every destructive statement re-verifies the connected database, the
 * fixture allowlist, the sentinel and the target name before it runs.
 */

putenv( 'BC_FIXTURE_NETWORK_ACTIVE=1' );
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );

/*
 * DISPOSABLE PORTAL CONFIGURATION, DEFINED BEFORE THE PLUGIN LOADS.
 *
 * wp-config.php is never parsed or replayed for these; the values below exist
 * only in this process, address an unroutable `.invalid` host, and are never
 * printed. PHP keeps the FIRST definition of a constant, so defining them here
 * means no operator credential can become the effective test credential even if
 * one were present in the environment.
 */
define( 'BINGOCOMMERCE_PORTAL_BASE_URL', 'http://portal.fixture.invalid' );
define( 'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN', 'fixture-disposable-token-0000000000000000' );

require __DIR__ . '/fixtures/isolated-multisite.php';

require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/ms.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-content/plugins/bingocommerce-core/bingocommerce-core.php';
require_once ABSPATH . 'wp-content/plugins/bingocommerce-catalog/bingocommerce-catalog.php';
require_once ABSPATH . 'wp-content/plugins/bingocommerce-sessions/bingocommerce-sessions.php';
require_once ABSPATH . 'wp-content/plugins/bingocommerce-platform/bingocommerce-platform.php';

use BingoCommerce\Core\Migrations as CoreMigrations;
use BingoCommerce\Platform\HallSiteAddress;
use BingoCommerce\Platform\HallSiteProvisioner;
use BingoCommerce\Platform\Plugin as PlatformPlugin;
use BingoCommerce\PortalIntegration\HallCommerceAccess;
use BingoCommerce\PortalIntegration\HallCommerceState;
use BingoCommerce\PortalIntegration\HallMappingRepository;
use BingoCommerce\PortalIntegration\Migrations as MappingMigrations;
use BingoCommerce\PortalIntegration\PlatformGateway;
use BingoCommerce\PortalIntegration\ProvisioningAudit;
use BingoCommerce\PortalIntegration\ProvisioningCanonicalRequest;
use BingoCommerce\PortalIntegration\ProvisioningMigrations;
use BingoCommerce\PortalIntegration\ProvisioningMutex;
use BingoCommerce\PortalIntegration\ProvisioningOperationRepository as Repo;
use BingoCommerce\PortalIntegration\PortalSyncService;
use BingoCommerce\PortalIntegration\ProvisioningResponse;
use BingoCommerce\PortalIntegration\ProvisioningRestController as Controller;
use BingoCommerce\PortalIntegration\SetupReadiness;

final class PortalProvisioningEndpointTest {

	private int $assertions = 0;
	private int $failures   = 0;

	/** Portal Hall identities this run owns. Deliberately unlike any blog id. */
	private const HALL_BASE = 970000;

	private int $super_admin_id = 0;
	private int $machine_id     = 0;

	/** Highest WooCommerce session id present before this run created any. */
	private int $wc_session_high_water = 0;

	private $original_site_admins = null;
	private bool $owns_super_admin = false;
	private bool $owns_schema      = false;
	private bool $owns_mapping_schema = false;
	private bool $owns_core_schema    = false;

	/** Sites, users and Halls THIS run created. */
	private array $disposable_sites = [];
	private array $disposable_users = [];
	private array $used_halls       = [];

	/** Observation counters, reset before each dispatch. */
	private int $filter_calls = 0;
	private array $http_urls  = [];
	private int $mail_attempts = 0;

	/** Portal snapshot bodies, keyed by Portal Hall id. */
	private array $snapshots = [];

	/** Every custom response body this run produced, for the disclosure sweep. */
	private array $observed_bodies = [];

	/** Statements rewritten for one dispatch: [ needle => replacement ]. */
	private array $obstructions = [];

	/** A callable run once, the first time a matching statement is seen. */
	private $interceptor = null;

	private function abort( string $reason ): void {
		fwrite( STDERR, "HARNESS GUARD: {$reason}\n" );
		fwrite( STDERR, "Stopping before any mutating test.\n" );
		exit( 1 );
	}

	public function run(): void {
		$this->test_environment_is_isolated();
		$this->require_clean_starting_state();

		try {
			$this->prepare();

			$this->test_route_registration();
			$this->test_core_boundary_is_not_intercepted();
			$this->test_route_hall_identity_is_url_only();
			$this->test_authentication_proof();
			$this->test_real_application_password_path();
			$this->test_authorization();
			$this->test_super_admin_is_refused();
			$this->test_conversion_is_route_scoped();
			$this->test_json_content_type_is_required();
			$this->test_path_validation();
			$this->test_body_shape_validation();
			$this->test_body_field_validation();
			$this->test_early_failures_have_no_side_effects();
			$this->test_schema_gate();

			$this->test_first_enable_end_to_end();
			$this->test_confirmed_retry_is_already_applied();
			$this->test_classification_precedence();
			$this->test_durable_rename_is_refused();
			$this->test_foreign_unique_collisions();

			$this->test_address_occupied_before_ownership();
			$this->test_address_invalid();
			$this->test_platform_failures();
			$this->test_attachment_failure_then_recovery();
			$this->test_sync_failure();
			$this->test_readiness_failure();
			$this->test_state_write_failure();
			$this->test_archive_clear_failure();
			$this->test_confirmation_failure();
			$this->test_ownership_allocation_exhaustion();
			$this->test_mapping_conflicts();
			$this->test_slug_conflict_and_site_missing();

			$this->test_address_probe_failure();
			$this->test_mapping_deleted_after_confirmation();
			$this->test_readiness_lost_after_confirmation();
			$this->test_lost_fence_before_and_after_mutation();
			$this->test_fence_lost_during_synchronization();
			$this->test_fence_lost_while_platform_created_a_site();
			$this->test_blog_context_is_restored_on_throw();
			$this->test_terminal_currentness_before_every_success();
			$this->test_currentness_around_error_category_persistence();
			$this->test_throwable_after_classification();
			$this->test_authoritative_site_url_ignores_filters();
			$this->test_network_url_authority();
			$this->test_duplicate_address_on_bound_paths();
			$this->test_hall_independence_and_serialization();

			$this->test_pre_bind_cancellation();
			$this->test_pre_bind_cancellation_preserves_ownership();
			$this->test_marker_matched_partial_suspend();
			$this->test_partial_suspend_marker_states();
			$this->test_partial_suspend_candidate_lifecycle();
			$this->test_durable_suspend_and_re_enable();
			$this->test_bound_suspend_address_verification();

			$this->test_ordinary_paths_are_unchanged();
			$this->test_response_matrix_is_exactly_adr_042();
			$this->test_audit_contract();
			$this->test_audit_failure_does_not_change_the_result();
			$this->test_nothing_is_disclosed();
			$this->test_no_mail_and_no_live_portal_request();
		} finally {
			$this->teardown();
		}

		$this->report();
	}

	/* ------------------------------------------------------------------ *
	 * Isolation evidence and preparation
	 * ------------------------------------------------------------------ */

	private function test_environment_is_isolated(): void {
		global $wpdb;

		$this->assert_true( is_multisite(), 'the fixture boots as Multisite' );
		if ( DB_NAME !== BC_Fixture::$database || DB_NAME === BC_Fixture::$live_db ) {
			$this->abort( 'WordPress is not connected to the fixture database.' );
		}
		if ( (string) $wpdb->get_var( 'SELECT DATABASE()' ) !== BC_Fixture::$database ) {
			$this->abort( 'the live connection does not report the fixture database.' );
		}
		/*
		 * The two guards above are the proof, and they ABORT rather than count.
		 * An `assert_true( true, ... )` restating them added a passing assertion
		 * that could never fail, which inflates a total without testing anything.
		 */
		$this->assert_true(
			defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL && '' === WP_ACCESSIBLE_HOSTS,
			'outbound HTTP is blocked with no accessible hosts'
		);
		$this->assert_true( ! wp_using_ext_object_cache(), 'no persistent object cache is in use' );
		$this->assert_true( ! file_exists( WP_CONTENT_DIR . '/db.php' ), 'no database-routing drop-in is present' );
		$this->assert_true( class_exists( '\WC_Install' ), 'WooCommerce is available for per-site initialization' );

		add_filter(
			'pre_wp_mail',
			function () {
				++$this->mail_attempts;

				return true;
			},
			0
		);

		/*
		 * A request to any host OTHER than the fixture Portal snapshot URL still
		 * reaches the fixture's blocker, proven here before anything is created.
		 */
		$probe = wp_remote_get( 'https://example.invalid/probe' );
		$this->assert_true(
			is_wp_error( $probe ) && 'bc_fixture_http_blocked' === $probe->get_error_code(),
			'outbound HTTP to an unrelated host is rejected by the fixture'
		);
	}

	private function require_clean_starting_state(): void {
		/*
		 * WOOCOMMERCE'S OWN GUEST SESSIONS ARE RESIDUE TOO.
		 *
		 * This harness loads WooCommerce and dispatches real REST requests, and
		 * WC_Session_Handler writes a row to the network MAIN site's
		 * `woocommerce_sessions` table when it initialises. Nothing in this
		 * suite asks for that and nothing else removes it, so each run left the
		 * fixture two rows heavier than it found it. The high-water mark is
		 * recorded here, before the first mutating test, and teardown removes
		 * exactly the rows created above it.
		 */
		$this->wc_session_high_water = $this->wc_session_max_id();

		$sites = get_sites( [ 'number' => 0, 'fields' => 'ids' ] );
		$extra = array_values( array_filter( array_map( 'intval', $sites ), static fn( int $id ): bool => $id > 3 ) );
		if ( [] !== $extra ) {
			$this->abort( 'the fixture already carries sites beyond the seeded three. Refusing to adopt them.' );
		}

		if ( $this->table_exists( ProvisioningMigrations::operations_table() )
			|| $this->table_exists( ProvisioningMigrations::state_table() )
			|| (int) get_site_option( ProvisioningMigrations::VERSION_OPTION, 0 ) > 0 ) {
			$this->abort( 'provisioning schema or version state is already present from a previous run.' );
		}
	}

	private function prepare(): void {
		global $wpdb;

		$this->original_site_admins = get_site_option( 'site_admins', null );

		$existing = username_exists( 'fixture_admin' );
		if ( $existing ) {
			$this->super_admin_id = (int) $existing;
		} else {
			$created = wp_insert_user(
				[
					'user_login' => 'fixture_admin',
					'user_pass'  => wp_generate_password( 32 ),
					'user_email' => 'fixture-admin@example.invalid',
					'role'       => '',
				]
			);
			if ( is_wp_error( $created ) ) {
				$this->abort( 'the disposable Super Admin could not be created.' );
			}
			$this->super_admin_id                     = (int) $created;
			$this->disposable_users[ (int) $created ]  = true;
			$this->owns_super_admin                    = true;
		}
		$this->assert_true( is_super_admin( $this->super_admin_id ), 'a disposable Network Super Admin exists' );

		/*
		 * THE NONHUMAN PORTAL PROVISIONING ACCOUNT. It holds
		 * `bc_receive_portal_provisioning` and nothing else: no Hall Manager
		 * role, no Game Operator role, and deliberately NOT Network Super Admin.
		 */
		$machine = wp_insert_user(
			[
				'user_login' => 'fixture_portal_machine',
				'user_pass'  => wp_generate_password( 32 ),
				'user_email' => 'fixture-portal-machine@example.invalid',
				'role'       => '',
			]
		);
		if ( is_wp_error( $machine ) ) {
			$this->abort( 'the disposable Portal machine account could not be created.' );
		}
		$this->machine_id                           = (int) $machine;
		$this->disposable_users[ (int) $machine ]   = true;

		$user = new WP_User( $this->machine_id );
		$user->add_cap( Controller::CAPABILITY );

		$this->assert_true( ! is_super_admin( $this->machine_id ), 'the machine account is NOT a Network Super Admin' );
		$this->assert_true(
			$this->machine_id !== $this->super_admin_id,
			'the machine account differs from the configured Hall site administrator'
		);

		if ( ! defined( 'BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID' ) ) {
			define( 'BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID', $this->super_admin_id );
		}

		/* The capability is granted to NO role by the plugin. */
		foreach ( [ 'administrator', 'bc_hall_manager', 'bc_game_operator', 'shop_manager', 'editor', 'subscriber' ] as $role_name ) {
			$role = get_role( $role_name );
			if ( $role instanceof WP_Role ) {
				$this->assert_true(
					empty( $role->capabilities[ Controller::CAPABILITY ] ),
					"the role {$role_name} does not carry bc_receive_portal_provisioning"
				);
			}
		}

		/* Schemas. This run owns only what it had to create. */
		$this->owns_core_schema = ! CoreMigrations::table_exists( CoreMigrations::audit_log_table() );
		CoreMigrations::maybe_migrate_network();
		$this->assert_true( CoreMigrations::table_exists( CoreMigrations::audit_log_table() ), 'the audit log schema is installed' );

		$this->assert_true( true === MappingMigrations::migrate_network(), 'the Hall mapping schema is installed' );
		$this->owns_mapping_schema = true;

		$this->assert_true( true === ProvisioningMigrations::migrate(), 'the provisioning schema is installed' );
		$this->owns_schema = true;
		ProvisioningMigrations::flush_ready_cache();
		$this->assert_true( ProvisioningMigrations::is_ready(), 'the runtime provisioning gate reports ready' );

		PlatformPlugin::init();

		/*
		 * A SPY IN FRONT OF THE PLATFORM CONSUMER. Priority 9, so it runs first,
		 * counts the invocation and returns the seed UNCHANGED — the same seed
		 * rule the real consumer obeys — so counting cannot change the outcome.
		 */
		add_filter(
			PlatformGateway::FILTER,
			function ( $seed, $command = null ) {
				++$this->filter_calls;
				unset( $command );

				return $seed;
			},
			9,
			2
		);

		/*
		 * THE FIXTURE PORTAL TRANSPORT. Priority 2, so it runs AFTER the
		 * fixture's own blocker at priority 1 and can answer for exactly the
		 * Portal snapshot URL of exactly the Halls this run registered. Every
		 * other URL keeps the blocker's WP_Error, so nothing leaves the process.
		 */
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$this->http_urls[] = (string) $url;

				foreach ( $this->snapshots as $hall_id => $body ) {
					$expected = BINGOCOMMERCE_PORTAL_BASE_URL . '/integration/v1/halls/' . $hall_id . '/snapshot';
					if ( (string) $url === $expected ) {
						if ( $body instanceof WP_Error ) {
							return $body;
						}
						if ( is_int( $body ) ) {
							return [ 'headers' => [], 'body' => '', 'response' => [ 'code' => $body, 'message' => 'Err' ], 'cookies' => [], 'filename' => null ];
						}

						return [ 'headers' => [], 'body' => (string) $body, 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
					}
				}

				return $preempt;
			},
			2,
			3
		);

		/* Statement obstruction, used only where a failure must be forced. */
		add_filter( 'query', [ $this, 'apply_obstructions' ], 1 );

		/* The REST route, registered through the real server. */
		Controller::register();
		do_action( 'rest_api_init', rest_get_server() );

		/*
		 * Valid proof is established HERE rather than inside whichever test
		 * happens to run first, so no test depends on another having run.
		 */
		$this->authenticate();
		$this->assert_true( Controller::has_application_password_proof(), 'valid Application Password proof is established' );
		$this->assert_true( current_user_can( Controller::CAPABILITY ), 'the machine account carries the provisioning capability' );

		unset( $wpdb );
	}

	/* ------------------------------------------------------------------ *
	 * The REST boundary
	 * ------------------------------------------------------------------ */

	/**
	 * THE HALL IS THE ONE IN THE URL, AND NOTHING ELSE CAN CHANGE IT.
	 *
	 * WP_REST_Request::get_parameter_order() resolves JSON, then POST, then GET
	 * and only then URL, so `get_param( 'portal_hall_id' )` would let a query
	 * string, a form field or a JSON property REPLACE the Hall named in the
	 * path — and every later step, including the mutex, the ledger and the site,
	 * would be about the substituted Hall while the URL said otherwise.
	 *
	 * Each case below names a REAL, ALREADY-PROVISIONED Hall as the attacker's
	 * value, so a successful override would be visible as a mutation of that
	 * Hall rather than as a harmless validation failure.
	 */
	private function test_route_hall_identity_is_url_only(): void {
		$route_hall  = $this->hall( 4 );
		$victim_hall = $this->hall( 5 );

		/* Give the victim a real, confirmed row so any touch is detectable. */
		$victim_payload = $this->payload( [ 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] );
		$seeded         = $this->dispatch( $victim_hall, $victim_payload );
		$this->assert_same( 200, $seeded['status'], 'the victim Hall is given a confirmed row' );
		$victim_before = Repo::load( $victim_hall );

		$payload = $this->payload( [ 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] );

		/* 1. A QUERY STRING cannot override the route Hall. */
		$query = new WP_REST_Request( 'PUT', '/bingocommerce/v1/portal-halls/' . $route_hall . '/commerce' );
		$query->set_header( 'content-type', 'application/json' );
		$query->set_body( (string) wp_json_encode( (object) $payload ) );
		$query->set_url_params( [ 'portal_hall_id' => (string) $route_hall ] );
		$query->set_query_params( [ 'portal_hall_id' => (string) $victim_hall ] );
		$this->assert_same(
			$route_hall,
			Controller::parse_path_id( $query ),
			'a query-string portal_hall_id cannot override the route Hall'
		);

		/* 2. A FORM/BODY parameter cannot override it. */
		$form = new WP_REST_Request( 'PUT', '/bingocommerce/v1/portal-halls/' . $route_hall . '/commerce' );
		$form->set_header( 'content-type', 'application/json' );
		$form->set_body( (string) wp_json_encode( (object) $payload ) );
		$form->set_url_params( [ 'portal_hall_id' => (string) $route_hall ] );
		$form->set_body_params( [ 'portal_hall_id' => (string) $victim_hall ] );
		$this->assert_same(
			$route_hall,
			Controller::parse_path_id( $form ),
			'a body/form portal_hall_id cannot override the route Hall'
		);

		/* 3. A JSON PROPERTY cannot override it. */
		$json_payload                   = $payload;
		$json_payload['portal_hall_id'] = $victim_hall;
		$json = new WP_REST_Request( 'PUT', '/bingocommerce/v1/portal-halls/' . $route_hall . '/commerce' );
		$json->set_header( 'content-type', 'application/json' );
		$json->set_body( (string) wp_json_encode( (object) $json_payload ) );
		$json->set_url_params( [ 'portal_hall_id' => (string) $route_hall ] );
		$this->assert_same(
			$route_hall,
			Controller::parse_path_id( $json ),
			'a JSON portal_hall_id property cannot override the route Hall'
		);
		$this->assert_true(
			(int) $json->get_param( 'portal_hall_id' ) === $victim_hall,
			'get_param() really would have returned the attacker value — which is why it is not used'
		);

		/* And the same through REAL dispatch, end to end. */
		$request = new WP_REST_Request( 'PUT', '/bingocommerce/v1/portal-halls/' . $route_hall . '/commerce' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( (object) $payload ) );
		$request->set_query_params( [ 'portal_hall_id' => (string) $victim_hall ] );

		$this->filter_calls = 0;
		$this->http_urls    = [];
		$audit_before       = $this->count_audit();
		$response           = rest_get_server()->dispatch( $request );
		$body               = $response->get_data();
		$this->obstructions = [];
		if ( is_array( $body ) && isset( $body['api_version'] ) ) {
			$this->observed_bodies[] = $body;
		}

		$this->assert_same( 200, (int) $response->get_status(), 'the dispatched request succeeds' );
		$this->assert_same( $route_hall, $body['portal_hall_id'] ?? 0, 'the response names the ROUTE Hall' );
		$this->assert_true( is_array( Repo::load( $route_hall ) ), 'the ROUTE Hall now has an operation row' );
		$this->assert_same( $victim_before, Repo::load( $victim_hall ), 'the named victim Hall row is byte-identical — it was never inspected, locked or mutated' );

		$audit = $this->audit_since( $audit_before );
		$this->assert_same( 1, count( $audit ), 'exactly one terminal audit was attempted' );
		$context = $this->sorted_context( $audit[0] ?? null );
		$this->assert_same( $route_hall, $context['portal_hall_id'] ?? 0, 'the audit attributes the ROUTE Hall' );
	}

	private function test_route_registration(): void {
		$routes = rest_get_server()->get_routes();
		$route  = Controller::FULL_ROUTE;

		$this->assert_true( isset( $routes[ $route ] ), 'the provisioning route is registered at the decided path' );
		$this->assert_same(
			'/bingocommerce/v1/portal-halls/(?P<portal_hall_id>[^/]+)/commerce',
			$route,
			'the route pattern accepts exactly one non-empty path segment, so manual validation runs'
		);

		$handlers = $routes[ $route ] ?? [];
		$this->assert_same( 1, count( $handlers ), 'the route carries exactly one handler — registered once' );

		$methods = $handlers[0]['methods'] ?? [];
		$this->assert_same( [ 'PUT' => true ], $methods, 'the handler admits PUT and nothing else' );
		$this->assert_true( empty( $handlers[0]['args'] ), 'no REST args validation or sanitization is registered for this contract' );
		$this->assert_same( [ Controller::class, 'handle' ], $handlers[0]['callback'], 'the callback is this controller' );
		$this->assert_same( [ Controller::class, 'authorize' ], $handlers[0]['permission_callback'], 'the permission callback is this controller' );

		/*
		 * Registering twice must not add a second handler. register_rest_route()
		 * APPENDS, so a route that guards only its `rest_api_init` listener would
		 * still double-register when the action is fired directly.
		 */
		Controller::register();
		Controller::register_routes();
		do_action( 'rest_api_init', rest_get_server() );
		$this->assert_same(
			1,
			count( rest_get_server()->get_routes()[ $route ] ?? [] ),
			'a repeated registration adds no second handler'
		);

		$this->assert_true( false === wp_next_scheduled( 'bingocommerce_portal_provisioning' ), 'the endpoint registers no cron event' );
	}

	/**
	 * WordPress core owns everything that happens before BingoCommerce callbacks.
	 */
	private function test_core_boundary_is_not_intercepted(): void {
		$hall = $this->hall( 1 );

		foreach ( [ 'POST', 'PATCH', 'GET', 'DELETE' ] as $method ) {
			$response = $this->raw_dispatch( $method, '/bingocommerce/v1/portal-halls/' . $hall . '/commerce', wp_json_encode( $this->payload() ) );
			$body     = $response->get_data();
			$this->assert_true( 200 !== $response->get_status(), "a {$method} request is refused" );
			$this->assert_true(
				is_array( $body ) && isset( $body['code'] ) && ! isset( $body['api_version'] ),
				"a {$method} request keeps WordPress core's own response body"
			);
		}

		$unmatched = $this->raw_dispatch( 'PUT', '/bingocommerce/v1/portal-halls/' . $hall . '/nope', '{}' );
		$this->assert_same( 404, $unmatched->get_status(), 'an unmatched route is core 404' );
		$this->assert_same( 'rest_no_route', $unmatched->get_data()['code'] ?? '', 'an unmatched route is core rest_no_route' );
		$this->assert_true( ! isset( $unmatched->get_data()['api_version'] ), 'an unmatched route never receives the custom schema' );

		$malformed = $this->raw_dispatch( 'PUT', '/bingocommerce/v1/portal-halls/' . $hall . '/commerce', '{"operation_id":' );
		$this->assert_same( 400, $malformed->get_status(), 'malformed JSON is HTTP 400' );
		$this->assert_same( 'rest_invalid_json', $malformed->get_data()['code'] ?? '', 'malformed JSON stays core rest_invalid_json' );
		$this->assert_true( ! isset( $malformed->get_data()['api_version'] ), 'malformed JSON never receives the custom schema' );

		$this->assert_same( 0, $this->count_operations(), 'no core-boundary failure created an operation row' );
		$this->assert_same( 0, $this->count_audit(), 'no core-boundary failure wrote a provisioning audit' );
	}

	private function test_authentication_proof(): void {
		$hall = $this->hall( 1 );

		$cases = [
			'no proof at all'                       => static function (): void {
				unset( $GLOBALS['wp_rest_application_password_uuid'], $GLOBALS['wp_rest_application_password_status'] );
			},
			'an Authorization header alone'         => static function (): void {
				unset( $GLOBALS['wp_rest_application_password_uuid'], $GLOBALS['wp_rest_application_password_status'] );
				$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( 'user:abcd efgh ijkl mnop qrst uvwx' );
			},
			'a UUID with no recorded status user'   => function (): void {
				$GLOBALS['wp_rest_application_password_uuid']   = '11111111-2222-4333-8444-555555555555';
				$GLOBALS['wp_rest_application_password_status'] = null;
			},
			'a status WP_Error rather than a user'  => function (): void {
				$GLOBALS['wp_rest_application_password_uuid']   = '11111111-2222-4333-8444-555555555555';
				$GLOBALS['wp_rest_application_password_status'] = new WP_Error( 'nope', 'nope' );
			},
			'a status user that is not the current user' => function (): void {
				$GLOBALS['wp_rest_application_password_uuid']   = '11111111-2222-4333-8444-555555555555';
				$GLOBALS['wp_rest_application_password_status'] = get_userdata( $this->super_admin_id );
			},
			'a recorded status but no UUID'         => function (): void {
				$GLOBALS['wp_rest_application_password_uuid']   = null;
				$GLOBALS['wp_rest_application_password_status'] = get_userdata( $this->machine_id );
			},
			'an empty UUID string'                  => function (): void {
				$GLOBALS['wp_rest_application_password_uuid']   = '';
				$GLOBALS['wp_rest_application_password_status'] = get_userdata( $this->machine_id );
			},
		];

		foreach ( $cases as $label => $arrange ) {
			$this->authenticate();
			$arrange();

			$response = $this->dispatch( $hall, $this->payload() );
			$this->assert_same( 401, $response['status'], "{$label} is HTTP 401" );
			$this->assert_same(
				[ 'api_version' => '1', 'error_category' => 'authentication_failed' ],
				$response['body'],
				"{$label} returns the reduced authentication body, with no path id"
			);
			unset( $_SERVER['HTTP_AUTHORIZATION'] );
		}

		/* A cookie-style login with the capability is still not App Password proof. */
		$this->authenticate();
		unset( $GLOBALS['wp_rest_application_password_uuid'], $GLOBALS['wp_rest_application_password_status'] );
		$response = $this->dispatch( $hall, $this->payload() );
		$this->assert_same( 401, $response['status'], 'a logged-in user with the capability but no App Password is 401' );

		/*
		 * Scoped to the Hall under test rather than to the whole table: earlier
		 * tests legitimately leave rows behind, and a global count would make
		 * this assertion depend on which tests ran before it.
		 */
		$this->assert_same( 0, $this->count_operations_for( $hall ), 'no authentication failure created an operation row' );
		$this->assert_same( 0, $this->audit_count_for_hall( $hall ), 'no authentication failure wrote a provisioning audit' );

		$this->authenticate();
	}

	/**
	 * The REAL Application Password collection path, with a real credential.
	 *
	 * The other authentication cases set the globals directly, which is the only
	 * way to exercise the individual mismatch branches. This one does not: it
	 * issues a genuine Application Password, authenticates through WordPress's
	 * own `wp_authenticate_application_password()`, and lets core's
	 * `application_password_did_authenticate` hook populate the status and the
	 * UUID — so the proof this controller requires is proven to be the proof
	 * core actually records.
	 *
	 * The credential is disposable and is destroyed at the end of this method.
	 */
	private function test_real_application_password_path(): void {
		if ( ! class_exists( '\WP_Application_Passwords' ) ) {
			$this->assert_true( false, 'WordPress Application Passwords are available' );

			return;
		}

		$created = \WP_Application_Passwords::create_new_application_password(
			$this->machine_id,
			[ 'name' => 'bc-fixture-portal-provisioning' ]
		);
		if ( is_wp_error( $created ) || ! is_array( $created ) ) {
			$this->assert_true( false, 'a disposable Application Password could be created' );

			return;
		}

		[ $plaintext, $item ] = $created;
		$uuid                 = (string) ( $item['uuid'] ?? '' );
		$this->assert_true( '' !== $uuid, 'the disposable Application Password has a UUID' );

		// CLI is not an API request and has no TLS, so both gates are opened for
		// the duration of this one authentication and closed immediately after.
		$api  = static fn() => true;
		$open = static fn() => true;
		add_filter( 'application_password_is_api_request', $api );
		add_filter( 'wp_is_application_passwords_available', $open );

		try {
			unset( $GLOBALS['wp_rest_application_password_uuid'], $GLOBALS['wp_rest_application_password_status'] );
			wp_set_current_user( 0 );

			$authenticated = wp_authenticate_application_password( null, 'fixture_portal_machine', $plaintext );

			$this->assert_true( $authenticated instanceof WP_User, 'the real credential authenticates through WordPress' );
			$this->assert_same(
				$uuid,
				(string) rest_get_authenticated_app_password(),
				'core recorded the Application Password UUID through its own collection hook'
			);
			$this->assert_true(
				( $GLOBALS['wp_rest_application_password_status'] ?? null ) instanceof WP_User,
				'core recorded the authenticated user as the status'
			);

			if ( $authenticated instanceof WP_User ) {
				$this->become( (int) $authenticated->ID );
				// become() resets the current user, which core's own flow does
				// through wp_set_current_user() after `authenticate` resolves.
				$GLOBALS['wp_rest_application_password_uuid']   = $uuid;
				$GLOBALS['wp_rest_application_password_status'] = get_userdata( (int) $authenticated->ID );
			}

			$this->assert_true(
				Controller::has_application_password_proof(),
				'the controller accepts the proof core recorded for a REAL credential'
			);

			$hall     = $this->free_hall();
			$response = $this->dispatch(
				$hall,
				$this->payload( [ 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] )
			);
			$this->assert_same( 200, $response['status'], 'a real Application Password drives a real provisioning command' );

			/* A WRONG password is refused, and records no usable proof. */
			unset( $GLOBALS['wp_rest_application_password_uuid'], $GLOBALS['wp_rest_application_password_status'] );
			$refused = wp_authenticate_application_password( null, 'fixture_portal_machine', 'wrongpasswordwrongpassword' );
			$this->assert_true( is_wp_error( $refused ), 'a wrong Application Password is refused by WordPress' );
			$this->assert_true(
				! Controller::has_application_password_proof(),
				'and leaves no proof this controller would accept'
			);
		} finally {
			remove_filter( 'application_password_is_api_request', $api );
			remove_filter( 'wp_is_application_passwords_available', $open );

			\WP_Application_Passwords::delete_application_password( $this->machine_id, $uuid );
			$this->authenticate();
		}

		$this->assert_same(
			[],
			\WP_Application_Passwords::get_user_application_passwords( $this->machine_id ),
			'the disposable Application Password was destroyed'
		);
	}

	/**
	 * A NETWORK SUPER ADMIN IS REFUSED, even with perfect credentials.
	 *
	 * WP_User::has_cap() answers true for every capability when is_super_admin()
	 * is true, so the capability check cannot be what keeps one out — a Super
	 * Admin would pass it, and could grant itself the capability in any case.
	 * BR-076 says Network Super Admins do not initiate automatic Enable/Disable,
	 * so the endpoint refuses them explicitly, before classification.
	 */
	private function test_super_admin_is_refused(): void {
		$hall = $this->free_hall();

		$this->become( $this->super_admin_id );
		$GLOBALS['wp_rest_application_password_uuid']   = '11111111-2222-4333-8444-555555555555';
		$GLOBALS['wp_rest_application_password_status'] = get_userdata( $this->super_admin_id );

		$this->assert_true( is_super_admin( $this->super_admin_id ), 'the account really is a Network Super Admin' );
		$this->assert_true( Controller::has_application_password_proof(), 'and carries valid Application Password proof' );
		$this->assert_true(
			current_user_can( Controller::CAPABILITY ),
			'and WordPress grants it the capability, as it grants a Super Admin every capability'
		);

		$before   = $this->count_audit();
		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepsuper', 'hall_name' => 'Super' ] ) );

		$this->assert_same( 403, $response['status'], 'a Network Super Admin with perfect credentials is refused with 403' );
		$this->assert_same(
			[ 'api_version' => '1', 'error_category' => 'authorization_failed' ],
			$response['body'],
			'and receives the reduced authorization body'
		);
		$this->assert_same( null, Repo::load( $hall ), 'the refusal created no operation row' );
		$this->assert_same( $before, $this->count_audit(), 'and wrote no provisioning audit' );
		$this->assert_same( 0, $response['filter_calls'], 'and invoked no platform filter' );
		$this->assert_same( 0, $this->count_sites_for_slug( 'pepsuper' ), 'and created no site' );

		$this->authenticate();
		$this->assert_true( ! is_super_admin( $this->machine_id ), 'the machine account remains a non-Super-Admin' );
	}

	/**
	 * A valid JSON object still needs a JSON content type.
	 */
	private function test_json_content_type_is_required(): void {
		$hall    = $this->free_hall();
		$payload = $this->payload( [ 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] );
		$json    = (string) wp_json_encode( (object) $payload );

		foreach (
			[
				'text/plain'                        => 'text/plain',
				'form data'                         => 'application/x-www-form-urlencoded',
				'multipart'                         => 'multipart/form-data; boundary=x',
				'no content type at all'            => '',
				'a JSON-looking but wrong type'     => 'application/jsonish',
			] as $label => $content_type
		) {
			$request = new WP_REST_Request( 'PUT', '/bingocommerce/v1/portal-halls/' . $hall . '/commerce' );
			if ( '' !== $content_type ) {
				$request->set_header( 'content-type', $content_type );
			}
			$request->set_body( $json );

			$this->assert_same( null, Controller::parse_body( $request ), "a valid JSON object sent as {$label} is refused" );

			$this->filter_calls = 0;
			$audit_before       = $this->count_audit();
			$response           = rest_get_server()->dispatch( $request );

			$this->assert_same( 400, (int) $response->get_status(), "a valid JSON object sent as {$label} is HTTP 400" );
			$this->assert_same(
				[ 'api_version' => '1', 'error_category' => 'validation_failed', 'portal_hall_id' => $hall ],
				$response->get_data(),
				"and receives the reduced validation body"
			);
			$this->assert_same( null, Repo::load( $hall ), "and touches no operation row" );
			$this->assert_same( $audit_before, $this->count_audit(), 'and writes no audit' );
			$this->assert_same( 0, $this->filter_calls, 'and invokes no platform filter' );

			if ( is_array( $response->get_data() ) ) {
				$this->observed_bodies[] = $response->get_data();
			}
		}

		/* The accepted JSON media types genuinely pass the check. */
		foreach ( [ 'application/json', 'application/json; charset=utf-8', 'application/ld+json' ] as $accepted ) {
			$request = new WP_REST_Request( 'PUT', '/bingocommerce/v1/portal-halls/' . $hall . '/commerce' );
			$request->set_header( 'content-type', $accepted );
			$request->set_body( $json );
			$this->assert_true(
				is_array( Controller::parse_body( $request ) ),
				"a body sent as {$accepted} is accepted by WordPress's own JSON media-type test"
			);
		}

		/* Malformed JSON under application/json is still core's. */
		$malformed = $this->raw_dispatch( 'PUT', '/bingocommerce/v1/portal-halls/' . $hall . '/commerce', '{"operation_id":' );
		$this->assert_same( 'rest_invalid_json', $malformed->get_data()['code'] ?? '', 'malformed application/json remains core rest_invalid_json' );
	}

	private function test_authorization(): void {
		$hall = $this->hall( 1 );

		/* Valid App Password proof, capability removed. */
		$user = new WP_User( $this->machine_id );
		$user->remove_cap( Controller::CAPABILITY );
		$this->authenticate();
		$this->assert_true( ! current_user_can( Controller::CAPABILITY ), 'the capability really is revoked' );

		$response = $this->dispatch( $hall, $this->payload() );
		$this->assert_same( 403, $response['status'], 'valid App Password proof without the capability is HTTP 403' );
		$this->assert_same(
			[ 'api_version' => '1', 'error_category' => 'authorization_failed' ],
			$response['body'],
			'authorization failure returns the reduced authorization body'
		);

		$this->assert_same( 0, $this->count_operations_for( $hall ), 'no authorization failure created an operation row' );
		$this->assert_same( 0, count( $response['audit'] ), 'no authorization failure wrote a provisioning audit' );

		/*
		 * SUPER ADMIN STATUS ALONE IS NOT APPLICATION PASSWORD PROOF. WordPress
		 * gives a Network Super Admin every capability by definition
		 * (WP_User::has_cap, wp-includes/class-wp-user.php), so the capability
		 * check cannot be what keeps one out — and a Super Admin could grant
		 * itself the capability in any case, so refusing on capability would be
		 * theatre. What this endpoint actually requires of everyone, Super Admin
		 * included, is request-scoped Application Password proof.
		 */
		$this->become( $this->super_admin_id );
		unset( $GLOBALS['wp_rest_application_password_uuid'], $GLOBALS['wp_rest_application_password_status'] );
		$this->assert_true( is_super_admin( $this->super_admin_id ), 'that account really is a Network Super Admin' );
		$this->assert_true( current_user_can( Controller::CAPABILITY ), 'WordPress grants a Super Admin every capability by definition' );

		$response = $this->dispatch( $hall, $this->payload() );
		$this->assert_same( 401, $response['status'], 'a Network Super Admin WITHOUT App Password proof is still refused' );
		$this->assert_same(
			[ 'api_version' => '1', 'error_category' => 'authentication_failed' ],
			$response['body'],
			'and is refused as an authentication failure, not silently admitted'
		);
		$this->assert_same( 0, $this->count_operations_for( $hall ), 'a refused Super Admin request created no operation row' );

		$user = new WP_User( $this->machine_id );
		$user->add_cap( Controller::CAPABILITY );
		$this->authenticate();
		$this->assert_true( current_user_can( Controller::CAPABILITY ), 'the machine account carries the capability again' );
	}

	/**
	 * The permission-error conversion must touch this route and nothing else.
	 */
	private function test_conversion_is_route_scoped(): void {
		register_rest_route(
			'bingocommerce-fixture/v1',
			'/other',
			[
				[
					'methods'             => 'PUT',
					'callback'            => static fn() => new WP_REST_Response( [ 'ok' => true ], 200 ),
					'permission_callback' => static fn() => new WP_Error( 'authentication_failed', 'refused', [ 'status' => 401 ] ),
				],
			]
		);
		register_rest_route(
			'bingocommerce-fixture/v1',
			'/args',
			[
				[
					'methods'             => 'PUT',
					'callback'            => static fn() => new WP_REST_Response( [ 'ok' => true ], 200 ),
					'permission_callback' => '__return_true',
					'args'                => [ 'required_thing' => [ 'required' => true ] ],
				],
			]
		);

		$other = $this->raw_dispatch( 'PUT', '/bingocommerce-fixture/v1/other', '{}' );
		$this->assert_same( 401, $other->get_status(), 'another route\'s permission failure keeps its own status' );
		$this->assert_true(
			isset( $other->get_data()['code'] ) && ! isset( $other->get_data()['api_version'] ),
			'another route\'s permission failure keeps WordPress core\'s error shape'
		);

		$args = $this->raw_dispatch( 'PUT', '/bingocommerce-fixture/v1/args', '{}' );
		$this->assert_same( 400, $args->get_status(), 'a core registered-argument failure is core 400' );
		$this->assert_true(
			isset( $args->get_data()['code'] ) && ! isset( $args->get_data()['api_version'] ),
			'a core registered-argument failure keeps WordPress core\'s error shape'
		);

		/*
		 * THE ADVERSARIAL CASE. A route registered against THIS CONTROLLER'S OWN
		 * PUBLIC CALLBACKS, with the same PUT method, so the marker and the
		 * callback identity both match. Only the matched route differs — and
		 * that alone must keep the conversion away from it, because these
		 * callbacks are public static methods that anything on the installation
		 * can register.
		 */
		register_rest_route(
			'bingocommerce-fixture/v1',
			'/impostor-halls/(?P<portal_hall_id>[^/]+)/commerce',
			[
				[
					'methods'             => 'PUT',
					'callback'            => [ Controller::class, 'handle' ],
					'permission_callback' => [ Controller::class, 'authorize' ],
				],
			]
		);

		/* Authenticated but Super-Admin-refused, so authorize() really fails. */
		$this->become( $this->super_admin_id );
		$GLOBALS['wp_rest_application_password_uuid']   = '11111111-2222-4333-8444-555555555555';
		$GLOBALS['wp_rest_application_password_status'] = get_userdata( $this->super_admin_id );

		$impostor = $this->raw_dispatch(
			'PUT',
			'/bingocommerce-fixture/v1/impostor-halls/' . $this->free_hall() . '/commerce',
			(string) wp_json_encode( (object) $this->payload() )
		);

		$this->authenticate();

		$this->assert_same( 403, (int) $impostor->get_status(), 'the impostor route still refuses the request' );
		$this->assert_true(
			isset( $impostor->get_data()['code'] ),
			'but its refusal keeps WordPress core\'s own error shape'
		);
		$this->assert_true(
			! isset( $impostor->get_data()['api_version'] ),
			'the ADR-042 reduced schema is NOT applied to a route that merely reuses these callbacks'
		);

		/* And the route matcher itself agrees. */
		foreach (
			[
				'/bingocommerce/v1/portal-halls/5/commerce'   => true,
				'/bingocommerce/v1/portal-halls/abc/commerce' => true,
				'/bingocommerce-fixture/v1/impostor-halls/5/commerce' => false,
				'/bingocommerce/v1/portal-halls/5/commerce/x' => false,
				'/bingocommerce/v1/portal-halls//commerce'    => false,
				'/bingocommerce/v1/portal-halls/5/nope'       => false,
				'x/bingocommerce/v1/portal-halls/5/commerce'  => false,
				''                                            => false,
			] as $route => $expected
		) {
			$this->assert_same(
				$expected,
				Controller::is_provisioning_route( $route ),
				"the route matcher answers " . var_export( $expected, true ) . " for '{$route}'"
			);
		}
	}

	private function test_path_validation(): void {
		$invalid = [
			'zero'                 => '0',
			'leading zero'         => '01',
			'padded zero'          => '007',
			'negative'             => '-1',
			'signed'               => '+1',
			'space padded'         => '%201',
			'exponent'             => '1e3',
			'hex'                  => '0x1',
			'letters'              => 'abc',
			'trailing letters'     => '12abc',
			'decimal'              => '1.0',
			'overflow'             => '9223372036854775808',
			'far overflow'         => '99999999999999999999999',
		];

		foreach ( $invalid as $label => $raw ) {
			$response = $this->dispatch_raw_path( $raw, $this->payload() );
			$this->assert_same( 400, $response['status'], "an invalid path id ({$label}) is HTTP 400" );
			$this->assert_same(
				[ 'api_version' => '1', 'error_category' => 'validation_failed' ],
				$response['body'],
				"an invalid path id ({$label}) returns the reduced body WITHOUT a path id"
			);
		}

		/* PHP_INT_MAX is the documented upper bound and is a valid path id. */
		$response = $this->dispatch_raw_path( (string) PHP_INT_MAX, $this->payload( [ 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] ) );
		$this->assert_same( 200, $response['status'], 'PHP_INT_MAX is an accepted path id' );
		$this->assert_same( PHP_INT_MAX, $response['body']['portal_hall_id'] ?? null, 'the accepted path id is echoed exactly' );
		$this->used_halls[ PHP_INT_MAX ] = true;

		$this->assert_same( 0, $this->audit_count_for( 'portal.commerce.failed' ), 'no path-validation failure wrote a provisioning audit' );
		$this->assert_same( 0, $this->count_operations_for( self::HALL_BASE + 1 ), 'no path-validation failure created an operation row' );
	}

	private function test_body_shape_validation(): void {
		$hall = $this->hall( 1 );

		$shapes = [
			'an empty body'   => '',
			'a JSON null'     => 'null',
			'a JSON string'   => '"hello"',
			'a JSON integer'  => '5',
			'a JSON float'    => '1.5',
			'a JSON boolean'  => 'true',
			'an empty list'   => '[]',
			'a populated list'=> '["a","b","c","d","e","f"]',
			'an empty object' => '{}',
			'a nested object' => '{"body":{"operation_id":"x"}}',
		];

		foreach ( $shapes as $label => $raw ) {
			$response = $this->dispatch_raw_body( $hall, $raw );
			$this->assert_same( 400, $response['status'], "{$label} is HTTP 400" );
			$this->assert_same(
				[ 'api_version' => '1', 'error_category' => 'validation_failed', 'portal_hall_id' => $hall ],
				$response['body'],
				"{$label} returns the reduced validation body WITH the validated path id"
			);
		}

		/*
		 * A WHITESPACE-ONLY body is not valid JSON, so WordPress core rejects it
		 * before the callback and keeps its own shape — exactly as ADR-042 says
		 * it must, and deliberately not intercepted to manufacture the custom
		 * schema.
		 */
		$whitespace = $this->raw_dispatch( 'PUT', '/bingocommerce/v1/portal-halls/' . $hall . '/commerce', "   \n " );
		$this->assert_same( 400, $whitespace->get_status(), 'a whitespace-only body is HTTP 400' );
		$this->assert_same( 'rest_invalid_json', $whitespace->get_data()['code'] ?? '', 'and stays core rest_invalid_json' );

		/*
		 * AN EMPTY OBJECT AND AN EMPTY LIST STAY DISTINGUISHABLE. WordPress core
		 * decodes both to the same empty PHP array, so the controller reads the
		 * RAW body instead — which is the only way an object can be told from a
		 * list at all.
		 */
		$object_request = new WP_REST_Request( 'PUT', Controller::FULL_ROUTE );
		$object_request->set_header( 'content-type', 'application/json' );
		$object_request->set_body( '{}' );
		$list_request = new WP_REST_Request( 'PUT', Controller::FULL_ROUTE );
		$list_request->set_header( 'content-type', 'application/json' );
		$list_request->set_body( '[]' );

		$this->assert_same(
			$object_request->get_json_params(),
			$list_request->get_json_params(),
			'WordPress core decodes {} and [] to the SAME value, so it cannot tell them apart'
		);
		$this->assert_true( is_object( json_decode( '{}' ) ), 'the controller\'s own decoding sees {} as an object' );
		$this->assert_true( is_array( json_decode( '[]' ) ), 'the controller\'s own decoding sees [] as a list' );

		/* The six correct values as a LIST are rejected; as an OBJECT accepted. */
		$payload = $this->payload( [ 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] );
		$as_list = $this->dispatch_raw_body( $hall, wp_json_encode( array_values( $payload ) ) );
		$this->assert_same( 400, $as_list['status'], 'the six correct values as a JSON LIST are rejected' );

		$body_request = new WP_REST_Request( 'PUT', Controller::FULL_ROUTE );
		$body_request->set_header( 'content-type', 'application/json' );
		$body_request->set_body( wp_json_encode( $payload ) );
		$this->assert_same(
			$payload,
			Controller::parse_body( $body_request ),
			'the same six values as a JSON OBJECT are accepted, key for key'
		);

		/* Missing and unknown keys, in both directions. */
		foreach ( Controller::BODY_KEYS as $key ) {
			$missing = $payload;
			unset( $missing[ $key ] );
			$response = $this->dispatch_raw_body( $hall, wp_json_encode( (object) $missing ) );
			$this->assert_same( 400, $response['status'], "a body missing {$key} is rejected" );
		}

		$unknown           = $payload;
		$unknown['extra']  = 1;
		$response          = $this->dispatch_raw_body( $hall, wp_json_encode( $unknown ) );
		$this->assert_same( 400, $response['status'], 'a body with an unknown key is rejected' );

		/*
		 * JSON NULL IS A PRESENT KEY, NOT A MISSING ONE. A suspend command whose
		 * slug and name are null is valid; the same body with those keys ABSENT
		 * is not.
		 */
		$nulls   = $this->payload( [ 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] );
		$absent  = $nulls;
		unset( $absent['site_slug'], $absent['hall_name'] );
		$this->assert_true( null !== Controller::parse_body( $this->json_request( $nulls ) ), 'JSON null keys are PRESENT keys' );
		$this->assert_true( null === Controller::parse_body( $this->json_request( $absent ) ), 'the same keys ABSENT are rejected' );

		/* A non-JSON content type reaches the callback and is rejected manually. */
		$plain = new WP_REST_Request( 'PUT', Controller::FULL_ROUTE );
		$plain->set_header( 'content-type', 'text/plain' );
		$plain->set_body( 'not json at all' );
		$this->assert_true( null === Controller::parse_body( $plain ), 'a non-JSON body that reaches the callback is rejected manually' );

		$this->assert_same( 0, $this->count_operations_for( $hall ), 'no body-shape failure created an operation row for that Hall' );
	}

	private function test_body_field_validation(): void {
		$hall = $this->hall( 1 );

		$max = ProvisioningCanonicalRequest::max_intent_sequence();

		$cases = [
			'operation_id null'            => [ 'operation_id' => null ],
			'operation_id uppercase'       => [ 'operation_id' => strtoupper( '1111111a-2222-4333-8444-55555555555b' ) ],
			'operation_id v1'              => [ 'operation_id' => '11111111-2222-1333-8444-555555555555' ],
			'operation_id bad variant'     => [ 'operation_id' => '11111111-2222-4333-c444-555555555555' ],
			'operation_id integer'         => [ 'operation_id' => 5 ],
			'intent_sequence zero'         => [ 'intent_sequence' => 0 ],
			'intent_sequence negative'     => [ 'intent_sequence' => -1 ],
			'intent_sequence string'       => [ 'intent_sequence' => '1' ],
			'intent_sequence float'        => [ 'intent_sequence' => 1.5 ],
			'intent_sequence bool'         => [ 'intent_sequence' => true ],
			'intent_sequence null'         => [ 'intent_sequence' => null ],
			'intent_sequence over max'     => [ 'intent_sequence' => $max + 1 ],
			'desired_state unknown'        => [ 'desired_state' => 'paused' ],
			'desired_state uppercase'      => [ 'desired_state' => 'OPERATIONAL' ],
			'desired_state null'           => [ 'desired_state' => null ],
			'desired_state integer'        => [ 'desired_state' => 1 ],
			'site_slug uppercase'          => [ 'site_slug' => 'Upper' ],
			'site_slug underscore'         => [ 'site_slug' => 'has_underscore' ],
			'site_slug leading hyphen'     => [ 'site_slug' => '-lead' ],
			'site_slug empty'              => [ 'site_slug' => '' ],
			'site_slug too long'           => [ 'site_slug' => str_repeat( 'a', 64 ) ],
			'site_slug integer'            => [ 'site_slug' => 5 ],
			'hall_name control character'  => [ 'hall_name' => "bad\x01name" ],
			'hall_name blank'              => [ 'hall_name' => '   ' ],
			'hall_name too long'           => [ 'hall_name' => str_repeat( 'a', 256 ) ],
			'hall_name integer'            => [ 'hall_name' => 5 ],
			'portal_actor_id zero'         => [ 'portal_actor_id' => 0 ],
			'portal_actor_id negative'     => [ 'portal_actor_id' => -5 ],
			'portal_actor_id string'       => [ 'portal_actor_id' => '42' ],
			'portal_actor_id null'         => [ 'portal_actor_id' => null ],
		];

		foreach ( $cases as $label => $override ) {
			$response = $this->dispatch( $hall, $this->payload( $override ) );
			$this->assert_same( 400, $response['status'], "an invalid body field ({$label}) is HTTP 400" );
			$this->assert_same( 'validation_failed', $response['body']['error_category'] ?? '', "an invalid body field ({$label}) is validation_failed" );
			$this->assert_true( ! isset( $response['body']['outcome'] ), "an invalid body field ({$label}) carries no outcome" );
		}

		/*
		 * A JSON number with a zero fraction is a FLOAT, and a float is not an
		 * accepted integer. It has to be sent as raw JSON: wp_json_encode( 1.0 )
		 * emits `1`, which would test the wrong thing entirely.
		 */
		foreach (
			[
				'intent_sequence as 1.0' => [ 'intent_sequence', '1.0' ],
				'portal_actor_id as 42.0' => [ 'portal_actor_id', '42.0' ],
			] as $label => [ $field, $literal ]
		) {
			$raw      = (string) wp_json_encode( (object) $this->payload( [ 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] ) );
			$raw      = (string) preg_replace( '/"' . $field . '":\d+/', '"' . $field . '":' . $literal, $raw );
			$this->assert_true( false !== strpos( $raw, $literal ), "{$label} really travels as a JSON float" );
			$response = $this->dispatch_raw_body( $hall, $raw );
			$this->assert_same( 400, $response['status'], "{$label} is HTTP 400" );
			$this->assert_same( 'validation_failed', $response['body']['error_category'] ?? '', "{$label} is validation_failed" );
		}

		/* A suspend command must carry JSON-null slug and name. */
		foreach (
			[
				'a suspend command with a slug' => [ 'desired_state' => 'suspended', 'site_slug' => 'something', 'hall_name' => null ],
				'a suspend command with a name' => [ 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => 'Something' ],
			] as $label => $override
		) {
			$response = $this->dispatch( $hall, $this->payload( $override ) );
			$this->assert_same( 400, $response['status'], "{$label} is rejected before the mutex" );
		}

		/* The accepted boundaries genuinely pass validation. */
		$boundary = $this->payload(
			[
				'intent_sequence' => $max,
				'desired_state'   => 'suspended',
				'site_slug'       => null,
				'hall_name'       => null,
				'portal_actor_id' => PHP_INT_MAX,
			]
		);
		$response = $this->dispatch( $this->hall( 2 ), $boundary );
		$this->assert_same( 200, $response['status'], 'the maximum intent sequence and actor id are accepted' );
		$this->used_halls[ $this->hall( 2 ) ] = true;

		$this->assert_same( 0, $this->count_operations_for( $hall ), 'no field-validation failure created a row for that Hall' );
	}

	private function test_early_failures_have_no_side_effects(): void {
		$this->assert_same( 0, $this->count_sites_above( 3 ), 'no early failure created a WordPress site' );
		$this->assert_same( 0, count( $this->http_urls ), 'no early failure made an outbound request' );
		$this->assert_same( 0, $this->filter_calls, 'no early failure invoked the platform filter' );
		$this->assert_same( 0, $this->count_mappings(), 'no early failure created a Hall mapping' );
		$this->assert_same( 0, $this->count_state_rows(), 'no early failure wrote Commerce state' );
	}

	private function test_schema_gate(): void {
		$hall = $this->hall( 3 );

		$this->guarded_query( 'DROP TABLE IF EXISTS `' . ProvisioningMigrations::operations_table() . '`', ProvisioningMigrations::operations_table() );
		ProvisioningMigrations::flush_ready_cache();

		$response = $this->dispatch( $hall, $this->payload() );
		$this->assert_same( 503, $response['status'], 'an unready provisioning schema is HTTP 503' );
		$this->assert_same(
			[ 'api_version' => '1', 'error_category' => 'platform_unavailable', 'portal_hall_id' => $hall ],
			$response['body'],
			'schema unreadiness returns the reduced body with error_category platform_unavailable and no outcome'
		);
		$this->assert_same( 0, $response['filter_calls'], 'schema unreadiness invokes no platform filter' );
		$this->assert_same( 0, count( $response['audit'] ), 'schema unreadiness writes no provisioning audit' );
		$this->assert_same( 0, $this->count_sites_above( 3 ), 'schema unreadiness creates no site' );

		/* The recorded version still claims 2, proving the gate is structural. */
		$this->assert_same(
			2,
			(int) get_site_option( ProvisioningMigrations::VERSION_OPTION, 0 ),
			'the recorded version still claims 2 while the schema is damaged'
		);
		$this->assert_true( ProvisioningMigrations::is_current(), 'the version-only question still answers yes' );

		/*
		 * A STALE VERSION DOES NOT LET migrate() SILENTLY RE-CREATE THE SCHEMA.
		 * With the version still claiming 2, the committed migration verifies
		 * rather than re-issuing DDL, and reports the damage — which is the whole
		 * point of separating the recorded version from structural readiness.
		 * Repair is an explicit operator step, and it is that step the harness
		 * performs here.
		 */
		$this->assert_true(
			is_wp_error( ProvisioningMigrations::migrate() ),
			'a migration against a stale version with a damaged schema reports the damage rather than claiming success'
		);

		delete_site_option( ProvisioningMigrations::VERSION_OPTION );
		$this->assert_true( true === ProvisioningMigrations::migrate(), 'the schema is repaired by an explicit operator migration, never by a request' );
		ProvisioningMigrations::flush_ready_cache();
		$this->assert_true( ProvisioningMigrations::is_ready(), 'the runtime gate reports ready again' );
	}

	/* ------------------------------------------------------------------ *
	 * Enable
	 * ------------------------------------------------------------------ */

	private function test_first_enable_end_to_end(): void {
		$hall = $this->hall( 10 );
		$slug = 'pepenable';
		$this->arm_snapshot( $hall );

		$payload = $this->payload( [ 'site_slug' => $slug, 'hall_name' => 'Enable Hall' ] );
		$before  = $this->count_sites_above( 3 );

		$response = $this->dispatch( $hall, $payload );

		$this->assert_same( 200, $response['status'], 'a first Enable through real REST dispatch is HTTP 200' );
		$body = $response['body'];

		$this->assert_same(
			[ 'api_version', 'blog_id', 'commerce_state', 'effective_access', 'error_category', 'intent_sequence', 'operation_id', 'outcome', 'portal_hall_id', 'site_url' ],
			$this->sorted_keys( $body ),
			'the success body carries exactly the ten decided keys'
		);
		$this->assert_same( '1', $body['api_version'], 'api_version is the exact string "1"' );
		$this->assert_same( $hall, $body['portal_hall_id'], 'the Hall id is echoed' );
		$this->assert_same( $payload['operation_id'], $body['operation_id'], 'the operation id is echoed' );
		$this->assert_same( 1, $body['intent_sequence'], 'the intent sequence is echoed' );
		$this->assert_same( 'applied', $body['outcome'], 'the outcome is applied' );
		$this->assert_same( 'operational', $body['commerce_state'], 'the Commerce state is operational' );
		$this->assert_same( 'allowed', $body['effective_access'], 'the billing-neutral effective access is allowed' );
		$this->assert_same( null, $body['error_category'], 'a success carries a null error category' );
		$this->assert_true( is_int( $body['blog_id'] ) && $body['blog_id'] > 0, 'a bound success returns a positive blog id' );
		$this->assert_true( is_string( $body['site_url'] ) && '' !== $body['site_url'], 'a bound success returns a non-empty site URL' );

		$blog_id = (int) $body['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;

		$this->assert_same( $before + 1, $this->count_sites_above( 3 ), 'exactly one site was created' );
		$this->assert_same( 1, $response['filter_calls'], 'the platform filter was invoked exactly once' );
		$this->assert_same(
			[ BINGOCOMMERCE_PORTAL_BASE_URL . '/integration/v1/halls/' . $hall . '/snapshot' ],
			$response['http'],
			'exactly one Portal request was made, to the mapped Hall\'s snapshot URL'
		);

		/* The operation row carries every durable fact. */
		$row = Repo::load( $hall );
		$this->assert_true( is_array( $row ), 'the operation row is readable and valid' );
		$this->assert_same( $blog_id, $row['blog_id'], 'the row carries the bound blog id' );
		$this->assert_true( ProvisioningCanonicalRequest::is_uuid( $row['site_ownership_id'] ), 'the row carries a permanent ownership id' );
		$this->assert_same( $slug, $row['site_slug'], 'the row carries the durable slug' );
		$this->assert_same( 1, $row['enforced_sequence'], 'enforcement is confirmed' );
		$this->assert_same( null, $row['last_error_category'], 'no stale error category remains' );
		$this->assert_true(
			$row['site_ownership_id'] !== $row['operation_id'],
			'the ownership identity is not the rotating Portal operation id'
		);

		/* Three-fact durable ownership. */
		$this->assert_same(
			$row['site_ownership_id'],
			$this->marker( $blog_id ),
			'bc_commerce_managed equals the permanent ownership id'
		);
		$this->assert_true( true === Repo::has_durable_ownership( $row ), 'durable ownership holds on all three facts' );

		/* The exact active mapping. */
		$mapping = ( new HallMappingRepository() )->findByPortalHallId( $hall );
		$this->assert_true( is_array( $mapping ), 'a Hall mapping exists' );
		$this->assert_same( $blog_id, (int) $mapping['blog_id'], 'the mapping names this blog' );
		$this->assert_same( 'active', $mapping['status'], 'the mapping is active' );

		/* The Portal full pull actually applied. */
		switch_to_blog( $blog_id );
		try {
			global $wpdb;
			$types    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . \BingoCommerce\Catalog\Migrations::game_types_table() . '`' );
			$sessions = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . \BingoCommerce\Sessions\Migrations::portal_session_templates_table() . '`' );
			$products = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product'" );
			$play     = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . \BingoCommerce\Catalog\Migrations::play_products_table() . '`' );
		} finally {
			restore_current_blog();
		}
		$this->assert_same( 2, $types, 'the Portal full pull imported both Game Types' );
		$this->assert_same( 1, $sessions, 'the Portal full pull imported the owned Session template' );
		$this->assert_same( 0, $products, 'the MVP store is EMPTY: no starter WooCommerce Product' );
		$this->assert_same( 0, $play, 'the MVP store is EMPTY: no starter Bingo Play Product' );

		/* Readiness, state and the archived clear. */
		$readiness = SetupReadiness::evaluate( $blog_id );
		$this->assert_true( is_array( $readiness ) && (bool) $readiness['ready'], 'the provisioned store is ready' );

		$state = HallCommerceState::read( $blog_id );
		$this->assert_true( is_array( $state ), 'Commerce state was written' );
		$this->assert_same( 'operational', $state['state'] ?? '', 'the settled Commerce state is operational' );
		$this->assert_same(
			HallCommerceState::pack( 1, HallCommerceState::PHASE_SETTLED ),
			$state['seq'] ?? 0,
			'the state sequence is this intent, settled'
		);

		$this->assert_same( '0', (string) get_site( $blog_id )->archived, 'archived was cleared and read back' );
		$this->assert_same( HallCommerceAccess::ALLOW, HallCommerceAccess::evaluate( $blog_id )['access'], 'the Hall is now allowed by the committed access evaluator' );

		/* Exactly one terminal audit, with the exact action and context. */
		$audit = $response['audit'];
		$this->assert_same( 1, count( $audit ), 'exactly one terminal audit was attempted' );
		$this->assert_same( 'portal.commerce.applied', $audit[0]['action'] ?? '', 'an operational applied maps to portal.commerce.applied' );
		$this->assert_same( $blog_id, (int) $audit[0]['blog_id'], 'the audit names the bound site' );
		$this->assert_same( $this->machine_id, (int) $audit[0]['actor_user_id'], 'the actor is the Application Password account' );
		$this->assert_same(
			[
				'desired_state'    => 'operational',
				'effective_access' => 'allowed',
				'error_category'   => null,
				'intent_sequence'  => 1,
				'operation_id'     => $payload['operation_id'],
				'outcome'          => 'applied',
				'portal_actor_id'  => $payload['portal_actor_id'],
				'portal_hall_id'   => $hall,
			],
			$this->sorted_context( $audit[0] ),
			'the audit context is exactly the eight allowlisted keys'
		);
	}

	private function test_confirmed_retry_is_already_applied(): void {
		$hall    = $this->hall( 10 );
		$payload = $this->payload_for( $hall );
		$row     = Repo::load( $hall );
		$blog_id = (int) $row['blog_id'];

		$before_row   = $row;
		$before_sites = $this->count_sites_above( 3 );
		$before_types = $this->game_type_count( $blog_id );

		$response = $this->dispatch( $hall, $payload );

		$this->assert_same( 200, $response['status'], 'an exact confirmed retry is HTTP 200' );
		$this->assert_same( 'already_applied', $response['body']['outcome'] ?? '', 'an exact confirmed retry is already_applied' );
		$this->assert_same( 'operational', $response['body']['commerce_state'] ?? '', 'the retry reports the enforced state' );
		$this->assert_same( $blog_id, $response['body']['blog_id'] ?? 0, 'the retry returns the same verified blog id' );
		$this->assert_same( get_site_url( $blog_id ), $response['body']['site_url'] ?? '', 'the retry returns the authoritative URL' );

		$this->assert_same( 0, $response['filter_calls'], 'an already_applied retry invokes NO platform filter' );
		$this->assert_same( [], $response['http'], 'an already_applied retry makes NO Portal request' );
		$this->assert_same( $before_sites, $this->count_sites_above( 3 ), 'an already_applied retry creates no site' );
		$this->assert_same( $before_types, $this->game_type_count( $blog_id ), 'an already_applied retry re-imports nothing' );
		$this->assert_same( $before_row, Repo::load( $hall ), 'an already_applied retry leaves the operation row byte-identical' );

		$this->assert_same( 1, count( $response['audit'] ), 'exactly one terminal audit was attempted' );
		$this->assert_same(
			'portal.commerce.already_applied',
			$response['audit'][0]['action'] ?? '',
			'an operational already_applied maps to portal.commerce.already_applied'
		);
	}

	private function test_classification_precedence(): void {
		$hall    = $this->hall( 10 );
		$current = $this->payload_for( $hall );
		$row     = Repo::load( $hall );

		/* (b) the SAME current UUID with a different sequence is a conflict. */
		$same_uuid_higher = array_merge( $current, [ 'intent_sequence' => 5 ] );
		$response         = $this->dispatch( $hall, $same_uuid_higher );
		$this->assert_same( 409, $response['status'], 'the same operation id with a higher sequence is HTTP 409' );
		$this->assert_same( 'conflict', $response['body']['outcome'] ?? '', 'and its outcome is conflict, not superseded' );
		$this->assert_same( $row, Repo::load( $hall ), 'a conflict mutates no row' );

		$same_uuid_lower = array_merge( $current, [ 'intent_sequence' => 1 ] );
		$same_uuid_lower['portal_actor_id'] = $current['portal_actor_id'] + 1;
		$response = $this->dispatch( $hall, $same_uuid_lower );
		$this->assert_same( 409, $response['status'], 'the same operation id with a changed payload is HTTP 409' );
		$this->assert_same( 'conflict', $response['body']['outcome'] ?? '', 'a changed payload under the same operation id is a conflict' );

		/* (c) a DIFFERENT UUID with a LOWER sequence is superseded. */
		$this->guarded_bump_sequence( $hall, 4 );
		$lower    = $this->payload( [ 'intent_sequence' => 2, 'site_slug' => null, 'hall_name' => null ] );
		$response = $this->dispatch( $hall, $lower );
		$this->assert_same( 409, $response['status'], 'a different operation id with a lower sequence is HTTP 409' );
		$this->assert_same( 'superseded', $response['body']['outcome'] ?? '', 'a lower sequence is superseded' );
		$this->assert_same( 'superseded', $response['body']['error_category'] ?? '', 'the category is superseded' );
		$this->assert_same( 1, count( $response['audit'] ), 'a superseded command still attempts exactly one terminal audit' );
		$this->assert_same( 'portal.commerce.superseded', $response['audit'][0]['action'] ?? '', 'superseded maps to its own action' );
		$this->assert_same( null, $response['audit'][0]['blog_id'], 'a superseded audit carries a null blog id' );

		/* (d) a DIFFERENT UUID with an EQUAL sequence is a conflict. */
		$equal    = $this->payload( [ 'intent_sequence' => 4, 'site_slug' => null, 'hall_name' => null ] );
		$response = $this->dispatch( $hall, $equal );
		$this->assert_same( 409, $response['status'], 'a different operation id with an equal sequence is HTTP 409' );
		$this->assert_same( 'conflict', $response['body']['outcome'] ?? '', 'an equal sequence is a conflict' );
		$this->assert_same( 'portal.commerce.conflict', $response['audit'][0]['action'] ?? '', 'conflict maps to its own action' );

		/* Every one of those left the durable facts untouched. */
		$after = Repo::load( $hall );
		$this->assert_same( $row['blog_id'], $after['blog_id'], 'precedence refusals never move the bound blog id' );
		$this->assert_same( $row['site_ownership_id'], $after['site_ownership_id'], 'precedence refusals never replace the ownership id' );
		$this->assert_same( $row['site_slug'], $after['site_slug'], 'precedence refusals never move the durable slug' );
	}

	private function test_durable_rename_is_refused(): void {
		$hall = $this->hall( 10 );
		$row  = Repo::load( $hall );

		$rename   = $this->payload( [ 'intent_sequence' => 9, 'site_slug' => 'pepnewname', 'hall_name' => 'Renamed' ] );
		$response = $this->dispatch( $hall, $rename );

		$this->assert_same( 409, $response['status'], 'renaming a durably bound site is HTTP 409' );
		$this->assert_same( 'conflict', $response['body']['outcome'] ?? '', 'a rename is a conflict, NOT slug_conflict' );
		$this->assert_same( 'conflict', $response['body']['error_category'] ?? '', 'the category is conflict' );
		$this->assert_same( $row, Repo::load( $hall ), 'a refused rename mutates nothing' );
		$this->assert_same( 0, $response['filter_calls'], 'a refused rename invokes no platform filter' );

		$address = HallSiteAddress::forSlug( 'pepnewname' );
		$this->assert_true(
			$address instanceof HallSiteAddress && null === $address->existingSiteId(),
			'a refused rename creates no site at the new address'
		);

		/* A post-ownership operational command with NULL slug and name resumes. */
		$this->arm_snapshot( $hall );
		$valid    = $this->payload( [ 'intent_sequence' => 9, 'site_slug' => null, 'hall_name' => null ] );
		$response = $this->dispatch( $hall, $valid );
		$this->assert_same( 200, $response['status'], 'a post-ownership Enable with null slug and name succeeds' );
		$this->assert_same( 'applied', $response['body']['outcome'] ?? '', 'it is applied' );
		$this->assert_same( (int) $row['blog_id'], $response['body']['blog_id'] ?? 0, 'it reuses the same site' );
		$after = Repo::load( $hall );
		$this->assert_same( $row['site_ownership_id'], $after['site_ownership_id'], 'the ownership id is never regenerated' );
		$this->assert_same( $row['site_slug'], $after['site_slug'], 'the durable slug is immutable once ownership exists' );
	}

	private function test_foreign_unique_collisions(): void {
		$hall    = $this->hall( 11 );
		$other   = $this->hall( 10 );
		$other_row = Repo::load( $other );

		/* A foreign row already holds this operation_id. */
		$payload  = $this->payload( [ 'operation_id' => $other_row['operation_id'], 'site_slug' => 'pepforeignop', 'hall_name' => 'Foreign Op' ] );
		$response = $this->dispatch( $hall, $payload );

		$this->assert_same( 409, $response['status'], 'a foreign operation_id collision is HTTP 409' );
		$this->assert_same( 'conflict', $response['body']['outcome'] ?? '', 'a foreign operation_id collision is a conflict' );
		$this->assert_same( null, $response['body']['blog_id'], 'a foreign collision discloses no blog id' );
		$this->assert_same( null, Repo::load( $hall ), 'a foreign operation_id collision establishes no row for this Hall' );
		$this->assert_same( $other_row, Repo::load( $other ), 'the foreign row is untouched' );
		$this->assert_same( 1, count( $response['audit'] ), 'a foreign collision still attempts exactly one terminal audit' );
		$this->assert_same( null, $response['audit'][0]['blog_id'], 'that audit carries a null blog id' );

		/* A foreign row already holds this site_slug. */
		$payload  = $this->payload( [ 'site_slug' => $other_row['site_slug'], 'hall_name' => 'Foreign Slug' ] );
		$response = $this->dispatch( $hall, $payload );
		$this->assert_same( 409, $response['status'], 'a foreign site_slug reservation is HTTP 409' );
		$this->assert_same( 'conflict', $response['body']['outcome'] ?? '', 'a foreign site_slug reservation is a conflict' );
		$this->assert_same( null, Repo::load( $hall ), 'a foreign slug collision establishes no row for this Hall' );
		$this->assert_same( 0, $response['filter_calls'], 'a foreign collision invokes no platform filter' );
		$this->assert_same( 0, $this->count_sites_for_slug( $other_row['site_slug'] ) - 1, 'no second site appeared at the foreign address' );
	}

	/* ------------------------------------------------------------------ *
	 * Failure paths
	 * ------------------------------------------------------------------ */

	private function test_address_occupied_before_ownership(): void {
		$hall = $this->hall( 12 );
		$slug = 'pepoccupied';

		/* A site that this Hall does not own, at the address it asks for. */
		$foreign = $this->seed_foreign_site( $slug );
		if ( 0 === $foreign ) {
			return;
		}

		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => $slug, 'hall_name' => 'Occupied' ] ) );

		$this->assert_same( 409, $response['status'], 'an occupied address is HTTP 409' );
		$this->assert_same( 'failed', $response['body']['outcome'] ?? '', 'an occupied address is a domain failure' );
		$this->assert_same( 'address_occupied', $response['body']['error_category'] ?? '', 'the category is address_occupied' );
		$this->assert_same( null, $response['body']['blog_id'], 'a non-200 body returns a null blog id' );
		$this->assert_same( null, $response['body']['site_url'], 'a non-200 body returns a null site URL' );
		$this->assert_same( null, $response['body']['commerce_state'], 'a non-200 body returns a null Commerce state' );
		$this->assert_same( null, $response['body']['effective_access'], 'a non-200 body returns a null effective access' );

		$row = Repo::load( $hall );
		$this->assert_true( is_array( $row ), 'the Hall operation row IS safely established' );
		$this->assert_same( null, $row['site_ownership_id'], 'NO ownership identity is minted for a foreign site' );
		$this->assert_same( null, $row['blog_id'], 'nothing is bound' );
		$this->assert_same( 'address_occupied', $row['last_error_category'], 'the allowlisted category is persisted on the row' );
		$this->assert_same( 0, $response['filter_calls'], 'an occupied address invokes no platform filter' );
		$this->assert_same( null, $this->marker( $foreign ), 'the foreign site is never marked' );
		$this->assert_true( get_site( $foreign ) instanceof WP_Site, 'the foreign site is never deleted' );

		/*
		 * A NEW operational command with a corrected slug replaces this Hall's
		 * own unbound reservation and succeeds. It alters no other Hall.
		 */
		$this->arm_snapshot( $hall );
		$response = $this->dispatch( $hall, $this->payload( [ 'intent_sequence' => 2, 'site_slug' => 'pepcorrected', 'hall_name' => 'Corrected' ] ) );
		$this->assert_same( 200, $response['status'], 'a corrected address succeeds' );
		$this->assert_same( 'applied', $response['body']['outcome'] ?? '', 'the corrected command is applied' );
		$corrected_blog = (int) $response['body']['blog_id'];
		$this->disposable_sites[ $corrected_blog ] = true;

		$row = Repo::load( $hall );
		$this->assert_same( 'pepcorrected', $row['site_slug'], 'the durable reservation moved to the corrected slug' );
		$this->assert_same( null, $row['last_error_category'], 'a successful confirmation cleared the stale error category' );
		$this->assert_true( $corrected_blog !== $foreign, 'the corrected command never adopted the foreign site' );
	}

	private function test_address_invalid(): void {
		$hall = $this->hall( 13 );

		/*
		 * A LEGAL DNS label whose RESULTING HOST is too long. The network domain
		 * is lengthened for the duration of this one dispatch through WordPress's
		 * own `get_network` filter, so the refusal comes from the real length
		 * rule rather than from a stubbed resolver.
		 */
		$long_domain = str_repeat( 'x', 200 ) . '.invalid';
		$stretch     = static function ( $network ) use ( $long_domain ) {
			if ( $network instanceof WP_Network ) {
				$clone         = clone $network;
				$clone->domain = $long_domain;

				return $clone;
			}

			return $network;
		};

		add_filter( 'get_network', $stretch, 10 );
		try {
			$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => str_repeat( 'a', 63 ), 'hall_name' => 'Too Long' ] ) );
		} finally {
			remove_filter( 'get_network', $stretch, 10 );
		}

		$this->assert_same( 400, $response['status'], 'an address that WordPress cannot host is HTTP 400' );
		$this->assert_same( 'failed', $response['body']['outcome'] ?? '', 'its outcome is failed' );
		$this->assert_same( 'address_invalid', $response['body']['error_category'] ?? '', 'its category is address_invalid' );

		$row = Repo::load( $hall );
		$this->assert_true( is_array( $row ), 'the Hall operation row is safely established' );
		$this->assert_same( null, $row['site_ownership_id'], 'no ownership identity is minted for an invalid address' );
		$this->assert_same( 'address_invalid', $row['last_error_category'], 'the category is persisted' );
		$this->assert_same( 0, $response['filter_calls'], 'an invalid address invokes no platform filter' );
		$this->assert_same( 0, $this->count_sites_above( 3 ) - count( $this->disposable_sites ), 'an invalid address created no untracked site' );
	}

	private function test_platform_failures(): void {
		foreach (
			[
				'site_create_failed'      => [ 'status' => 503, 'outcome' => 'failed' ],
				'site_ownership_unproven' => [ 'status' => 409, 'outcome' => 'failed' ],
				'platform_unavailable'    => [ 'status' => 503, 'outcome' => 'failed' ],
				'internal_error'          => [ 'status' => 503, 'outcome' => 'failed' ],
			] as $code => $expected
		) {
			$hall = $this->hall( 20 + crc32( $code ) % 40 );
			while ( isset( $this->used_halls[ $hall ] ) ) {
				++$hall;
			}

			$seed = static fn( $value ) => new WP_Error( $code, 'refused' );
			add_filter( PlatformGateway::FILTER, $seed, 8 );
			try {
				$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepfail' . substr( md5( $code ), 0, 6 ), 'hall_name' => 'Platform ' . $code ] ) );
			} finally {
				remove_filter( PlatformGateway::FILTER, $seed, 8 );
			}

			$this->assert_same( $expected['status'], $response['status'], "a platform {$code} is HTTP {$expected['status']}" );
			$this->assert_same( $expected['outcome'], $response['body']['outcome'] ?? '', "a platform {$code} has outcome {$expected['outcome']}" );
			$this->assert_same( $code, $response['body']['error_category'] ?? '', "a platform {$code} is reported as {$code}" );
			$this->assert_same( null, $response['body']['blog_id'], "a platform {$code} returns a null blog id" );

			$row = Repo::load( $hall );
			$this->assert_true( is_array( $row ) && null === $row['blog_id'], "a platform {$code} binds nothing" );
			$this->assert_same( $code, $row['last_error_category'], "a platform {$code} persists its allowlisted category" );
			$this->used_halls[ $hall ] = true;
		}

		/* An unrecognised platform error code is reduced, never forwarded. */
		$hall = $this->free_hall();
		$seed = static fn( $value ) => new WP_Error( 'some_future_internal_code', 'SELECT * FROM secrets' );
		add_filter( PlatformGateway::FILTER, $seed, 8 );
		try {
			$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepunknown', 'hall_name' => 'Unknown code' ] ) );
		} finally {
			remove_filter( PlatformGateway::FILTER, $seed, 8 );
		}
		$this->assert_same( 503, $response['status'], 'an unrecognised platform code is HTTP 503' );
		$this->assert_same( 'internal_error', $response['body']['error_category'] ?? '', 'an unrecognised platform code is reduced to internal_error' );

		/* A malformed platform RESULT is an integration failure, not a success. */
		foreach (
			[
				'a non-array result'          => 'nonsense',
				'a result missing a key'      => [ 'blog_id' => 5, 'site_url' => 'http://x.invalid', 'created' => true ],
				'a result with an extra key'  => [ 'blog_id' => 5, 'site_url' => 'http://x.invalid', 'created' => true, 'managed_marker_present' => true, 'extra' => 1 ],
				'a non-integer blog id'       => [ 'blog_id' => '5', 'site_url' => 'http://x.invalid', 'created' => true, 'managed_marker_present' => true ],
				'an empty site url'           => [ 'blog_id' => 5, 'site_url' => '', 'created' => true, 'managed_marker_present' => true ],
				'a non-boolean created flag'  => [ 'blog_id' => 5, 'site_url' => 'http://x.invalid', 'created' => 1, 'managed_marker_present' => true ],
			] as $label => $result
		) {
			$this->assert_same( 'internal_error', PlatformGateway::validateResult( $result ), "{$label} is an internal_error" );
		}
		$this->assert_same(
			'site_ownership_unproven',
			PlatformGateway::validateResult( [ 'blog_id' => 5, 'site_url' => 'http://x.invalid', 'created' => true, 'managed_marker_present' => false ] ),
			'a result whose marker read-back failed is site_ownership_unproven'
		);

		/* A throwing consumer is contained on the caller\'s side too. */
		$hall   = $this->free_hall();
		$thrower = static function () {
			throw new RuntimeException( 'consumer exploded' );
		};
		add_filter( PlatformGateway::FILTER, $thrower, 8 );
		try {
			$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepthrow', 'hall_name' => 'Throwing consumer' ] ) );
		} finally {
			remove_filter( PlatformGateway::FILTER, $thrower, 8 );
		}
		$this->assert_same( 503, $response['status'], 'a throwing filter consumer is HTTP 503' );
		$this->assert_same( 'internal_error', $response['body']['error_category'] ?? '', 'a throwing consumer is internal_error' );
		$this->assert_true(
			false === strpos( wp_json_encode( $response['body'] ), 'exploded' ),
			'the exception text never reaches the response'
		);

		/* No consumer at all is platform_unavailable, with no local fallback. */
		$hall = $this->free_hall();
		remove_filter( PlatformGateway::FILTER, [ HallSiteProvisioner::class, 'provision' ], 10 );
		try {
			$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepnoconsumer', 'hall_name' => 'No consumer' ] ) );
		} finally {
			add_filter( PlatformGateway::FILTER, [ HallSiteProvisioner::class, 'provision' ], 10, 2 );
		}
		$this->assert_same( 503, $response['status'], 'no platform consumer is HTTP 503' );
		$this->assert_same( 'platform_unavailable', $response['body']['error_category'] ?? '', 'no platform consumer is platform_unavailable' );
		$address = HallSiteAddress::forSlug( 'pepnoconsumer' );
		$this->assert_true(
			$address instanceof HallSiteAddress && null === $address->existingSiteId(),
			'there is no local site-creation fallback'
		);
	}

	/**
	 * The site was created, but this Hall could not record that it owns it.
	 */
	private function test_attachment_failure_then_recovery(): void {
		$hall = $this->free_hall();
		$slug = 'peppartial';
		$this->arm_snapshot( $hall );

		$this->obstruct( 'SET blog_id =', 'UPDATE ' . ProvisioningMigrations::operations_table() . ' SET blog_id = NULL WHERE 1 = 0', 'UPDATE' );
		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => $slug, 'hall_name' => 'Partial' ] ) );

		$this->assert_same( 503, $response['status'], 'a failed blog attachment is HTTP 503' );
		$this->assert_same( 'unverified', $response['body']['outcome'] ?? '', 'a failed attachment after creation is unverified, never "nothing happened"' );
		$this->assert_same( 'unverified', $response['body']['error_category'] ?? '', 'its category is unverified' );
		$this->assert_same( null, $response['body']['blog_id'], 'an unverified result returns a null blog id' );
		$this->assert_same( 1, count( $response['audit'] ), 'exactly one terminal audit was attempted' );
		$this->assert_same( 'portal.commerce.unverified', $response['audit'][0]['action'] ?? '', 'unverified maps to its own action' );

		/* The site exists, archived and marked, and was NOT deleted. */
		$address = HallSiteAddress::forSlug( $slug );
		$this->assert_true( $address instanceof HallSiteAddress, 'the address still resolves' );
		$stranded = $address instanceof HallSiteAddress ? $address->existingSiteId() : null;
		$this->assert_true( is_int( $stranded ), 'the created site was NOT deleted by the failure' );
		if ( ! is_int( $stranded ) ) {
			return;
		}
		$this->disposable_sites[ $stranded ] = true;

		$row = Repo::load( $hall );
		$this->assert_same( null, $row['blog_id'], 'the operation row records no binding' );
		$this->assert_true( ProvisioningCanonicalRequest::is_uuid( $row['site_ownership_id'] ), 'the permanent ownership identity survives' );
		$this->assert_same( $row['site_ownership_id'], $this->marker( $stranded ), 'the stranded site carries this Hall\'s exact marker' );
		$this->assert_same( '1', (string) get_site( $stranded )->archived, 'the stranded site stays archived' );

		/* The exact retry recovers THAT site rather than creating a second one. */
		$before   = $this->count_sites_above( 3 );
		$this->arm_snapshot( $hall );
		$response = $this->dispatch( $hall, $this->payload_for( $hall ) );

		$this->assert_same( 200, $response['status'], 'the exact retry resumes to success' );
		$this->assert_same( 'applied', $response['body']['outcome'] ?? '', 'the resumed retry is applied' );
		$this->assert_same( $stranded, $response['body']['blog_id'] ?? 0, 'the retry recovered the SAME site' );
		$this->assert_same( $before, $this->count_sites_above( 3 ), 'the retry created NO second site' );
		$this->assert_same( 1, $response['filter_calls'], 'the resume invoked the platform filter exactly once' );
		$this->assert_same( '0', (string) get_site( $stranded )->archived, 'the recovered site is now unarchived' );
	}

	private function test_sync_failure(): void {
		$hall = $this->free_hall();
		$this->snapshots[ $hall ] = 500;

		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepsyncfail', 'hall_name' => 'Sync fail' ] ) );

		$this->assert_same( 503, $response['status'], 'a failed Portal pull is HTTP 503' );
		$this->assert_same( 'failed', $response['body']['outcome'] ?? '', 'a failed Portal pull has outcome failed' );
		$this->assert_same( 'sync_failed', $response['body']['error_category'] ?? '', 'its category is sync_failed' );

		$row     = Repo::load( $hall );
		$blog_id = (int) $row['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;
		$this->assert_same( 'sync_failed', $row['last_error_category'], 'sync_failed is persisted on the row' );
		$this->assert_same( 0, $row['enforced_sequence'], 'a failed pull never confirms enforcement' );
		$this->assert_same( '1', (string) get_site( $blog_id )->archived, 'a failed pull NEVER clears archived' );

		$state = HallCommerceState::read( $blog_id );
		$this->assert_same( 'provisioning', $state['state'] ?? '', 'the Hall stays in the transitional provisioning state' );
		$this->assert_same(
			HallCommerceAccess::BLOCK,
			HallCommerceAccess::evaluate( $blog_id )['access'],
			'a Hall whose provisioning failed is BLOCKED, not quietly reachable'
		);

		/* A malformed Portal body is also sync_failed, with no schema leak. */
		$this->snapshots[ $hall ] = '{"api_version":"v1","generated_at":"nope"}';
		$response                 = $this->dispatch( $hall, $this->payload_for( $hall ) );
		$this->assert_same( 'sync_failed', $response['body']['error_category'] ?? '', 'an invalid Portal snapshot is sync_failed' );

		/* Repairing the Portal lets the same command resume to success. */
		$this->arm_snapshot( $hall );
		$response = $this->dispatch( $hall, $this->payload_for( $hall ) );
		$this->assert_same( 200, $response['status'], 'the same command resumes once the Portal answers' );
		$this->assert_same( $blog_id, $response['body']['blog_id'] ?? 0, 'the resume reuses the same site' );
		$this->assert_same( '0', (string) get_site( $blog_id )->archived, 'only a successful pull leads to archived being cleared' );
	}

	private function test_readiness_failure(): void {
		$hall = $this->free_hall();
		$this->arm_snapshot( $hall );

		/*
		 * A store prerequisite is made to look unmet by obstructing exactly ONE
		 * statement shape: SetupReadiness' whole-site table inventory,
		 * `SHOW TABLES LIKE '<escaped prefix>\_%'`. Nothing else in a
		 * provisioning dispatch issues that shape — dbDelta, WooCommerce and the
		 * BingoCommerce migrations all probe SPECIFIC table names with no
		 * trailing wildcard — so the obstruction is confined to the read-only
		 * evaluator and writes nothing.
		 *
		 * That confinement is not asserted from reading the source: the very same
		 * command succeeds, on the very same site, the moment the obstruction is
		 * lifted. A failure manufactured in data could not do that.
		 */
		$this->obstruct( "\\_%'", 'SELECT NULL AS `Tables_in_fixture` FROM DUAL WHERE 1 = 0', 'SHOW' );
		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepreadiness', 'hall_name' => 'Readiness' ] ) );

		$this->assert_same( 503, $response['status'], 'an unready store is HTTP 503' );
		$this->assert_same( 'failed', $response['body']['outcome'] ?? '', 'its outcome is failed' );
		$this->assert_same( 'readiness_failed', $response['body']['error_category'] ?? '', 'its category is readiness_failed' );

		$row     = Repo::load( $hall );
		$blog_id = (int) $row['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;
		$this->assert_same( 'readiness_failed', $row['last_error_category'], 'readiness_failed is persisted' );
		$this->assert_same( '1', (string) get_site( $blog_id )->archived, 'an unready store is never unarchived' );
		$this->assert_same( 'provisioning', HallCommerceState::read( $blog_id )['state'] ?? '', 'an unready store never reaches operational' );

		/* Without the obstruction the very same command succeeds. */
		$this->arm_snapshot( $hall );
		$response = $this->dispatch( $hall, $this->payload_for( $hall ) );
		$this->assert_same(
			200,
			$response['status'],
			'the same command succeeds once the prerequisite is met (category: ' . (string) ( $response['body']['error_category'] ?? 'none' ) . ')'
		);
		$this->assert_same( $blog_id, $response['body']['blog_id'] ?? 0, 'and reuses the same site' );
	}

	private function test_state_write_failure(): void {
		$hall = $this->free_hall();
		$this->arm_snapshot( $hall );

		$this->obstruct(
			'INSERT INTO ' . ProvisioningMigrations::state_table(),
			'INSERT INTO zz_bc_absent_state_table_for_test (blog_id) VALUES (1)',
			'INSERT'
		);
		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepstate', 'hall_name' => 'State' ] ) );

		$this->assert_same( 503, $response['status'], 'a failed Commerce-state write is HTTP 503' );
		$this->assert_true(
			in_array( $response['body']['error_category'] ?? '', [ 'state_write_failed', 'unverified' ], true ),
			'a failed Commerce-state write is state_write_failed or unverified, never a success'
		);
		$this->assert_true(
			in_array( $response['body']['outcome'] ?? '', [ 'failed', 'unverified' ], true ),
			'its outcome is failed or unverified'
		);

		$row     = Repo::load( $hall );
		$blog_id = (int) $row['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;
		$this->assert_same( 0, $row['enforced_sequence'], 'a failed state write never confirms enforcement' );
		$this->assert_same( '1', (string) get_site( $blog_id )->archived, 'a failed state write never clears archived' );
		$this->assert_same( null, HallCommerceState::read( $blog_id ), 'no Commerce state row was created' );
	}

	private function test_archive_clear_failure(): void {
		$hall = $this->free_hall();
		$this->arm_snapshot( $hall );

		global $wpdb;
		/*
		 * wpdb::update() backticks the table name, so the needle has to match the
		 * statement WordPress actually issues rather than the one it reads like.
		 */
		$this->obstruct(
			'`' . $wpdb->blogs . '` SET',
			'UPDATE `' . $wpdb->blogs . '` SET archived = archived WHERE blog_id = 0',
			'UPDATE'
		);
		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'peparchive', 'hall_name' => 'Archive' ] ) );

		$this->assert_true( 200 !== $response['status'], 'a failed archived clear NEVER returns success' );
		$this->assert_same( 503, $response['status'], 'a failed archived clear is HTTP 503' );
		$this->assert_true(
			in_array( $response['body']['error_category'] ?? '', [ 'state_write_failed', 'unverified' ], true ),
			'a failed archived clear is state_write_failed or unverified'
		);
		$this->assert_true(
			! in_array( $response['body']['outcome'] ?? '', [ 'applied', 'already_applied' ], true ),
			'a failed archived clear is never applied or already_applied'
		);

		$row     = Repo::load( $hall );
		$blog_id = (int) $row['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;
		$this->assert_same( '1', (string) get_site( $blog_id )->archived, 'the site really is still archived' );
		$this->assert_same( 0, $row['enforced_sequence'], 'a failed archived clear never confirms enforcement' );

		/* The same command resumes once the clear can succeed. */
		$this->arm_snapshot( $hall );
		$response = $this->dispatch( $hall, $this->payload_for( $hall ) );
		$this->assert_same( 200, $response['status'], 'the same command resumes to success' );
		$this->assert_same( '0', (string) get_site( $blog_id )->archived, 'and the clear is proven by read-back' );
	}

	private function test_confirmation_failure(): void {
		$hall = $this->free_hall();
		$this->arm_snapshot( $hall );

		$this->obstruct(
			'SET enforced_sequence = intent_sequence',
			'UPDATE ' . ProvisioningMigrations::operations_table() . ' SET updated_at = updated_at WHERE 1 = 0',
			'UPDATE'
		);
		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepconfirm', 'hall_name' => 'Confirm' ] ) );

		$this->assert_same( 503, $response['status'], 'an unconfirmable command is HTTP 503' );
		$this->assert_same( 'unverified', $response['body']['outcome'] ?? '', 'an unconfirmable command reports outcome unverified' );
		$this->assert_true(
			in_array( $response['body']['error_category'] ?? '', [ 'confirmation_failed', 'unverified' ], true ),
			'its category is confirmation_failed or unverified'
		);

		$row     = Repo::load( $hall );
		$blog_id = (int) $row['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;
		$this->assert_same( 0, $row['enforced_sequence'], 'enforcement is genuinely not confirmed' );

		/* A retry confirms, and only then is it applied. */
		$this->arm_snapshot( $hall );
		$response = $this->dispatch( $hall, $this->payload_for( $hall ) );
		$this->assert_same( 200, $response['status'], 'the retry confirms' );
		$this->assert_same( 'applied', $response['body']['outcome'] ?? '', 'and only then is it applied' );
		$this->assert_same( 1, Repo::load( $hall )['enforced_sequence'], 'enforcement is now recorded' );
	}

	private function test_ownership_allocation_exhaustion(): void {
		$hall = $this->free_hall();

		/*
		 * Every ownership-identity write is refused AND every collision probe
		 * answers "taken", which is the only combination that spends an
		 * allocation attempt. After three, ADR-042 fixes the answer as
		 * internal_error with no platform creation.
		 */
		$this->obstruct(
			'SET site_ownership_id =',
			'UPDATE zz_bc_absent_operations_for_test SET site_ownership_id = NULL',
			'UPDATE'
		);
		$this->obstruct( 'COUNT(*) FROM ' . ProvisioningMigrations::operations_table() . ' WHERE site_ownership_id', 'SELECT 1', 'SELECT' );

		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepexhaust', 'hall_name' => 'Exhaust' ] ) );

		$this->assert_same( 503, $response['status'], 'exhausted ownership allocation is HTTP 503' );
		$this->assert_same( 'failed', $response['body']['outcome'] ?? '', 'its outcome is failed' );
		$this->assert_same( 'internal_error', $response['body']['error_category'] ?? '', 'its category is internal_error' );
		$this->assert_same( 0, $response['filter_calls'], 'platform creation is NOT invoked when ownership cannot be established' );
		$this->assert_same( 0, $this->count_sites_for_slug( 'pepexhaust' ), 'no site was created' );

		$row = Repo::load( $hall );
		$this->assert_same( null, $row['site_ownership_id'], 'no ownership identity was persisted' );
		$this->assert_true(
			false === strpos( wp_json_encode( $response['body'] ), '-' ) || null === $response['body']['blog_id'],
			'no candidate identity is disclosed'
		);
	}

	private function test_mapping_conflicts(): void {
		$hall  = $this->free_hall();
		$other = $this->hall( 10 );
		$this->arm_snapshot( $hall );

		/* First bring this Hall up so it has a real site to disagree about. */
		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepmapping', 'hall_name' => 'Mapping' ] ) );
		$this->assert_same( 200, $response['status'], 'the mapping-test Hall is provisioned' );
		$blog_id = (int) $response['body']['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;

		/*
		 * Re-point this Hall's mapping at ANOTHER blog, behind the endpoint's
		 * back. The target must be a site no Hall is mapped to, because
		 * UNIQUE(blog_id) would otherwise refuse the statement and the test would
		 * silently measure nothing.
		 */
		unset( $other );
		$other_blog = $this->spare_site( 'pepspare1' );
		if ( 0 === $other_blog ) {
			return;
		}
		$this->guarded_repoint_mapping( $hall, $other_blog );
		$this->assert_same(
			$other_blog,
			(int) ( new HallMappingRepository() )->findByPortalHallId( $hall )['blog_id'],
			'the mapping really was re-pointed'
		);

		$this->arm_snapshot( $hall );
		$response = $this->dispatch( $hall, $this->payload( [ 'intent_sequence' => 2, 'site_slug' => null, 'hall_name' => null ] ) );

		$this->assert_same( 409, $response['status'], 'a Hall mapped to a different blog is HTTP 409' );
		$this->assert_same( 'failed', $response['body']['outcome'] ?? '', 'its outcome is failed' );
		$this->assert_same( 'mapping_invalid', $response['body']['error_category'] ?? '', 'its category is mapping_invalid' );
		$this->assert_same( null, $response['body']['blog_id'], 'mapping_invalid discloses no blog id' );
		$this->assert_same( null, $response['body']['site_url'], 'mapping_invalid discloses no site URL' );
		$this->assert_same(
			[ 'api_version', 'blog_id', 'commerce_state', 'effective_access', 'error_category', 'intent_sequence', 'operation_id', 'outcome', 'portal_hall_id', 'site_url' ],
			$this->sorted_keys( $response['body'] ),
			'mapping_invalid carries only the ten allowlisted keys, so no foreign identity has anywhere to appear'
		);

		$mapping = ( new HallMappingRepository() )->findByPortalHallId( $hall );
		$this->assert_same( $other_blog, (int) $mapping['blog_id'], 'the disagreeing mapping is NEVER silently repaired' );

		$this->guarded_repoint_mapping( $hall, $blog_id );
		$this->arm_snapshot( $hall );
		$response = $this->dispatch( $hall, $this->payload( [ 'intent_sequence' => 3, 'site_slug' => null, 'hall_name' => null ] ) );
		$this->assert_same( 200, $response['status'], 'once an operator repairs the mapping the command succeeds' );

		/* A DISABLED mapping is an operator decision and is never re-enabled. */
		( new HallMappingRepository() )->disable( $hall );
		$this->arm_snapshot( $hall );
		$response = $this->dispatch( $hall, $this->payload( [ 'intent_sequence' => 4, 'site_slug' => null, 'hall_name' => null ] ) );
		$this->assert_same( 409, $response['status'], 'a disabled mapping is HTTP 409' );
		$this->assert_same( 'mapping_invalid', $response['body']['error_category'] ?? '', 'a disabled mapping is mapping_invalid' );
		$this->assert_same(
			'disabled',
			( new HallMappingRepository() )->findByPortalHallId( $hall )['status'],
			'the machine endpoint never re-enables a disabled mapping'
		);
		( new HallMappingRepository() )->enable( $hall );
	}

	/**
	 * The two same-Hall invariant failures that only exist AFTER identity.
	 *
	 * `slug_conflict` is deliberately NOT `conflict`: a conflict is about the
	 * REQUEST, and this is about stored state that has come apart. Both require
	 * explicit operator repair and neither is repaired here.
	 */
	private function test_slug_conflict_and_site_missing(): void {
		global $wpdb;

		/* --- slug_conflict: the durable slug no longer describes the site. --- */
		$hall = $this->free_hall();
		$this->arm_snapshot( $hall );
		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepslugconf', 'hall_name' => 'Slug conflict' ] ) );
		$this->assert_same( 200, $response['status'], 'the slug-invariant Hall is provisioned' );
		$blog_id = (int) $response['body']['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;

		/* Move the site's address behind the endpoint's back. */
		$moved = $this->guarded_move_site( $blog_id, 'pepmoved.' . preg_replace( '|^www\.|', '', (string) get_network()->domain ) );
		$this->assert_true( $moved, 'the bound site was moved to a different address' );

		$this->arm_snapshot( $hall );
		$response = $this->dispatch( $hall, $this->payload( [ 'intent_sequence' => 2, 'site_slug' => null, 'hall_name' => null ] ) );

		$this->assert_same( 409, $response['status'], 'a durable slug that no longer describes the bound site is HTTP 409' );
		$this->assert_same( 'failed', $response['body']['outcome'], 'its outcome is failed' );
		$this->assert_same( 'slug_conflict', $response['body']['error_category'], 'its category is slug_conflict, NOT conflict' );
		$this->assert_same( null, $response['body']['blog_id'], 'it discloses no blog id' );
		$this->assert_same( 0, $response['filter_calls'], 'it invokes no platform filter' );
		$this->assert_same(
			'slug_conflict',
			Repo::load( $hall )['last_error_category'],
			'slug_conflict is persisted as an allowlisted category for operator repair'
		);
		$this->assert_same(
			'pepslugconf',
			Repo::load( $hall )['site_slug'],
			'the machine endpoint never repairs the invariant by rewriting the durable slug'
		);
		$this->assert_true(
			1 === preg_match( '/^pepmoved\./', (string) get_site( $blog_id )->domain ),
			'nor by moving the site back'
		);

		/* --- site_missing: the bound site is gone. --- */
		$hall = $this->free_hall();
		$this->arm_snapshot( $hall );
		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepvanish', 'hall_name' => 'Vanishing' ] ) );
		$this->assert_same( 200, $response['status'], 'the vanishing Hall is provisioned' );
		$blog_id = (int) $response['body']['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;

		$this->guarded_remove_site_row( $blog_id );
		$this->assert_true( ! get_site( $blog_id ) instanceof WP_Site, 'the bound site really is gone' );

		$response = $this->dispatch( $hall, $this->payload( [ 'intent_sequence' => 2, 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] ) );

		$this->assert_same( 409, $response['status'], 'a vanished bound site is HTTP 409' );
		$this->assert_same( 'failed', $response['body']['outcome'], 'its outcome is failed' );
		$this->assert_same( 'site_missing', $response['body']['error_category'], 'its category is site_missing' );
		$this->assert_same( null, $response['body']['blog_id'], 'it discloses no blog id' );
		$this->assert_same( 0, $response['filter_calls'], 'a suspend still invokes no platform filter, even when the site is gone' );
		$this->assert_same( 'site_missing', Repo::load( $hall )['last_error_category'], 'site_missing is persisted for operator recovery' );
		$this->assert_same(
			$blog_id,
			Repo::load( $hall )['blog_id'],
			'the operation row still records the binding: recovery is an operator decision, not a machine one'
		);

		unset( $this->disposable_sites[ $blog_id ] );
		$this->cleanup_orphan_tables( $blog_id );
	}

	/**
	 * An UNREADABLE address answer must never mint ownership or create a site.
	 *
	 * The probe is a prepared read against `$wpdb->blogs`; the failure is forced
	 * into that exact statement. Both the enable path (before ownership) and the
	 * pre-bind suspend path (which resolves the reserved address to look for a
	 * marker-matched partial site) are exercised.
	 */
	private function test_address_probe_failure(): void {
		global $wpdb;

		$blogs      = (string) $wpdb->blogs;
		$obstruct   = 'SELECT blog_id FROM zz_bc_absent_blogs_for_test LIMIT 2';
		$breaker    = static function ( $query ) use ( $blogs, $obstruct ) {
			$text = (string) $query;

			return ( false !== strpos( $text, $blogs ) && 1 === preg_match( '/^\s*SELECT\s+blog_id\s+FROM/i', $text ) )
				? $obstruct
				: $query;
		};

		/* --- ENABLE, before any ownership exists. --- */
		$hall = $this->free_hall();
		add_filter( 'query', $breaker, 1 );
		try {
			$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepprobe', 'hall_name' => 'Probe' ] ) );
		} finally {
			remove_filter( 'query', $breaker, 1 );
		}

		$this->assert_same( 503, $response['status'], 'an unreadable address probe on enable is HTTP 503' );
		$this->assert_same( 'failed', $response['body']['outcome'], 'its outcome is failed' );
		$this->assert_same( 'platform_unavailable', $response['body']['error_category'], 'its category is platform_unavailable' );
		$this->assert_same( null, $response['body']['blog_id'], 'it returns a null blog id' );

		$row = Repo::load( $hall );
		$this->assert_true( is_array( $row ), 'the Hall operation row is safely established' );
		$this->assert_same( null, $row['site_ownership_id'], 'NO permanent ownership identity is minted against an unanswerable address' );
		$this->assert_same( null, $row['blog_id'], 'nothing is bound' );
		$this->assert_same( 0, $response['filter_calls'], 'and the platform filter is never invoked' );
		$this->assert_same( 0, $this->count_sites_for_slug( 'pepprobe' ), 'and no site is created' );

		/* The identical command succeeds once the read works again. */
		$this->arm_snapshot( $hall );
		$response = $this->dispatch( $hall, $this->payload_for( $hall ) );
		$this->assert_same( 200, $response['status'], 'the identical command succeeds once the address can be read' );
		$this->disposable_sites[ (int) $response['body']['blog_id'] ] = true;

		/* --- PRE-BIND SUSPEND, where ownership exists but nothing is bound. --- */
		$suspend_hall = $this->free_hall();
		$seed         = static fn( $value ) => new WP_Error( 'site_create_failed', 'refused' );
		add_filter( PlatformGateway::FILTER, $seed, 8 );
		try {
			$this->dispatch( $suspend_hall, $this->payload( [ 'site_slug' => 'pepprobesusp', 'hall_name' => 'Probe suspend' ] ) );
		} finally {
			remove_filter( PlatformGateway::FILTER, $seed, 8 );
		}

		$before = Repo::load( $suspend_hall );
		$this->assert_true( ProvisioningCanonicalRequest::is_uuid( $before['site_ownership_id'] ), 'an ownership identity exists but nothing is bound' );

		add_filter( 'query', $breaker, 1 );
		try {
			$response = $this->dispatch(
				$suspend_hall,
				$this->payload( [ 'intent_sequence' => 2, 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] )
			);
		} finally {
			remove_filter( 'query', $breaker, 1 );
		}

		$this->assert_same( 503, $response['status'], 'an unreadable address probe on a pre-bind suspend is HTTP 503' );
		$this->assert_same( 'platform_unavailable', $response['body']['error_category'], 'its category is platform_unavailable' );
		$this->assert_same( null, $response['body']['blog_id'], 'it returns a null blog id' );
		$this->assert_same( 0, $response['filter_calls'], 'a suspend still invokes no platform filter' );

		$after = Repo::load( $suspend_hall );
		$this->assert_same( $before['site_ownership_id'], $after['site_ownership_id'], 'the ownership identity is preserved, never regenerated' );
		$this->assert_same( null, $after['blog_id'], 'nothing was bound' );
		$this->assert_true(
			$after['enforced_sequence'] < $after['intent_sequence'],
			'and no cancellation was confirmed against an address nobody could check'
		);
	}

	/**
	 * A mapping deleted after a confirmed enable is NOT `already_applied`.
	 */
	private function test_mapping_deleted_after_confirmation(): void {
		$hall = $this->free_hall();
		$this->arm_snapshot( $hall );

		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepmapgone', 'hall_name' => 'Mapping gone' ] ) );
		$this->assert_same( 200, $response['status'], 'the Hall is enabled and confirmed' );
		$blog_id = (int) $response['body']['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;

		$before = ( new HallMappingRepository() )->findByPortalHallId( $hall );
		$this->assert_true( is_array( $before ), 'an exact mapping exists' );

		/* Remove it behind the endpoint's back. */
		$this->guarded_delete_mapping( $hall );
		$this->assert_same( null, ( new HallMappingRepository() )->findByPortalHallId( $hall ), 'the mapping really is gone' );

		$this->arm_snapshot( $hall );
		$before_sites = $this->count_sites_above( 3 );
		$retry        = $this->dispatch( $hall, $this->payload_for( $hall ) );

		/*
		 * EXACT FACTS FROM THE RETRY, not from the earlier response, and no
		 * either/or branch: the behaviour here is deterministic, so the
		 * assertions state it.
		 */
		$this->assert_true(
			'already_applied' !== ( $retry['body']['outcome'] ?? '' ),
			'the exact retry NEVER reports already_applied while the mapping is absent'
		);
		$this->assert_same( 200, $retry['status'], 'it resumes to HTTP 200' );
		$this->assert_same( 'applied', $retry['body']['outcome'], 'with outcome applied' );
		$this->assert_same( 'operational', $retry['body']['commerce_state'], 'and an operational Commerce state' );
		$this->assert_same( $blog_id, $retry['body']['blog_id'], 'reusing the SAME site' );
		$this->assert_same( $before_sites, $this->count_sites_above( 3 ), 'and creating no second site' );

		$this->assert_same( 1, $retry['filter_calls'], 'the resume invoked the platform filter exactly once' );
		$this->assert_same(
			[ BINGOCOMMERCE_PORTAL_BASE_URL . '/integration/v1/halls/' . $hall . '/snapshot' ],
			$retry['http'],
			'and performed exactly one Portal synchronization, for this Hall'
		);

		$restored = ( new HallMappingRepository() )->findByPortalHallId( $hall );
		$this->assert_true( is_array( $restored ), 'the exact mapping was restored' );
		$this->assert_same( $blog_id, (int) ( $restored['blog_id'] ?? 0 ), 'and it names the same blog' );
		$this->assert_same( 'active', $restored['status'] ?? '', 'and it is active' );
		$this->assert_same(
			$this->portal_hall_of( $restored ),
			$hall,
			'and it belongs to this Portal Hall'
		);

		/* Only now, with the mapping exact again, is a retry already_applied. */
		$settled = $this->dispatch( $hall, $this->payload_for( $hall ) );
		$this->assert_same( 'already_applied', $settled['body']['outcome'] ?? '', 'the next retry is already_applied' );
		$this->assert_same( 0, $settled['filter_calls'], 'and does no platform work' );
		$this->assert_same( [], $settled['http'], 'and no Portal work' );
	}

	/**
	 * Replace this Hall's current command the first time a matching statement
	 * runs — the deterministic stand-in for "a newer Disable arrived".
	 *
	 * @return array<string,mixed> The canonical form of the newer command.
	 */
	private function steal_command( int $hall, string $desired_state, string $needle ): array {
		$row  = Repo::load( $hall );
		$seq  = is_array( $row ) ? (int) $row['intent_sequence'] + 7 : 7;
		$next = $this->payload(
			[
				'intent_sequence' => $seq,
				'desired_state'   => $desired_state,
				'site_slug'       => null,
				'hall_name'       => null,
			]
		);
		if ( 'operational' === $desired_state && ( ! is_array( $row ) || null === $row['blog_id'] ) ) {
			$next['site_slug'] = 'pepstolen' . $seq;
			$next['hall_name'] = 'Stolen ' . $seq;
		}

		$canonical = ProvisioningCanonicalRequest::validate( array_merge( [ 'portal_hall_id' => $hall ], $next ) );
		if ( $canonical instanceof WP_Error ) {
			$this->assert_true( false, 'the replacement command is canonical' );

			return [];
		}

		$this->interceptor = [
			'needle' => $needle,
			'run'    => static function () use ( $hall, $canonical ) {
				$current = Repo::load( $hall );
				if ( is_array( $current ) ) {
					Repo::replace_command( $current, $canonical );
				}
			},
		];

		return $canonical;
	}

	/**
	 * Replace the command from inside the platform filter, for a FIRST enable
	 * that has no row to read before the snapshot.
	 *
	 * @return array<string,mixed>
	 */
	private function steal_command_on_first( int $hall, string $desired_state, string $slug ): array {
		$next      = $this->payload(
			[
				'intent_sequence' => 9,
				'desired_state'   => $desired_state,
				'site_slug'       => null,
				'hall_name'       => null,
			]
		);
		$canonical = ProvisioningCanonicalRequest::validate( array_merge( [ 'portal_hall_id' => $hall ], $next ) );
		if ( $canonical instanceof WP_Error ) {
			$this->assert_true( false, 'the replacement command is canonical' );

			return [];
		}

		$this->interceptor = [
			/*
			 * ARMED by the confirmation UPDATE — the enable path's LAST mutation
			 * — and FIRED by the mutex probe that immediately follows it, which
			 * belongs to the terminal snapshot's own currentness proof. The
			 * replacement therefore lands in exactly the window this correction
			 * exists to close: after everything has been written, before the
			 * answer is fixed.
			 */
			'arm'    => 'SET enforced_sequence = intent_sequence',
			'needle' => 'IS_USED_LOCK',
			'run'    => static function () use ( $hall, $canonical ) {
				$current = Repo::load( $hall );
				if ( is_array( $current ) ) {
					Repo::replace_command( $current, $canonical );
				}
			},
		];
		unset( $slug );

		return $canonical;
	}

	/**
	 * steal_command() with an explicit arming statement.
	 *
	 * @return array<string,mixed>
	 */
	private function steal_command_armed( int $hall, string $desired_state, string $arm, string $needle ): array {
		$canonical                = $this->steal_command( $hall, $desired_state, $needle );
		$this->interceptor['arm'] = $arm;

		return $canonical;
	}

	/** The statement shape that reads one site's `siteurl` from its own table. */
	private function siteurl_read_needle( int $blog_id ): string {
		global $wpdb;

		return "FROM {$wpdb->get_blog_prefix( $blog_id )}options WHERE option_name = 'siteurl'";
	}

	/**
	 * A terminal snapshot that lost currentness is a refusal, never a success.
	 *
	 * @param array<string,mixed> $result
	 */
	private function assert_terminal_currentness_loss( array $result, string $label ): void {
		$this->assert_same( 503, $result['status'], "{$label}: a lost fence at the snapshot is HTTP 503" );
		$this->assert_true(
			! in_array( $result['body']['outcome'] ?? '', [ 'applied', 'already_applied' ], true ),
			"{$label}: and is NEVER reported as applied or already_applied"
		);

		$category = $result['body']['error_category'] ?? '';
		$this->assert_true(
			in_array( $category, [ 'lock_unavailable', 'unverified' ], true ),
			"{$label}: its category is lock_unavailable or unverified (got '{$category}')"
		);

		if ( 'lock_unavailable' === $category ) {
			$this->assert_same(
				[ 'api_version', 'error_category', 'portal_hall_id' ],
				$this->sorted_keys( $result['body'] ),
				"{$label}: a pre-mutation loss is the REDUCED body with no outcome"
			);
			$this->assert_same( 0, count( $result['audit'] ), "{$label}: and writes no provisioning audit" );

			return;
		}

		$this->assert_same( 'unverified', $result['body']['outcome'] ?? '', "{$label}: a post-mutation loss reports outcome unverified" );
		$this->assert_same( null, $result['body']['blog_id'], "{$label}: with a null blog id" );
		$this->assert_same( null, $result['body']['site_url'], "{$label}: a null site URL" );
		$this->assert_same( null, $result['body']['commerce_state'], "{$label}: a null Commerce state" );
		$this->assert_same( null, $result['body']['effective_access'], "{$label}: and a null effective access" );
		$this->assert_same( 1, count( $result['audit'] ), "{$label}: exactly one terminal audit was attempted" );
		$this->assert_same(
			'portal.commerce.unverified',
			$result['audit'][0]['action'] ?? '',
			"{$label}: and it is the unverified action"
		);
	}

	/** @param array<string,mixed>|null $mapping */
	private function portal_hall_of( $mapping ): int {
		return is_array( $mapping ) ? (int) ( $mapping['portal_hall_id'] ?? 0 ) : 0;
	}

	/**
	 * A readiness fact lost after confirmation is NOT `already_applied`.
	 */
	private function test_readiness_lost_after_confirmation(): void {
		global $wpdb;

		$hall = $this->free_hall();
		$this->arm_snapshot( $hall );

		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepreadygone', 'hall_name' => 'Readiness gone' ] ) );
		$this->assert_same( 200, $response['status'], 'the Hall is enabled and confirmed' );
		$blog_id = (int) $response['body']['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;

		$confirmed = Repo::load( $hall );
		$this->assert_true( Repo::is_confirmed( $confirmed ), 'the ledger says the command is fully enforced' );

		/*
		 * One required readiness fact is removed — the site's whole table
		 * inventory is made unreadable, which is how a lost WooCommerce or
		 * Action Scheduler table would present. The LEDGER is untouched, so the
		 * retry is an exact, confirmed one and would have answered
		 * `already_applied` on ledger equality alone.
		 */
		$this->obstruct( "\\_%'", 'SELECT NULL AS `Tables_in_fixture` FROM DUAL WHERE 1 = 0', 'SHOW' );
		$retry = $this->dispatch( $hall, $this->payload_for( $hall ) );

		$this->assert_true(
			'already_applied' !== ( $retry['body']['outcome'] ?? '' ),
			'a confirmed retry whose store is no longer ready NEVER reports already_applied'
		);
		$this->assert_same( 503, $retry['status'], 'it is HTTP 503' );
		$this->assert_same( 'failed', $retry['body']['outcome'], 'its outcome is failed' );
		$this->assert_same( 'readiness_failed', $retry['body']['error_category'], 'its category is readiness_failed' );
		$this->assert_same( null, $retry['body']['blog_id'], 'and its site fields are null' );
		$this->assert_same( 0, $retry['filter_calls'], 'and no platform filter ran' );

		/*
		 * The failure persisted `readiness_failed` on the row, and ADR-042
		 * requires `already_applied` to see a NULL stale error field — so the
		 * next retry legitimately RESUMES and re-applies rather than reporting
		 * already_applied, and only the retry after that, against a clean row,
		 * is already_applied.
		 */
		$this->assert_same( 'readiness_failed', Repo::load( $hall )['last_error_category'], 'the failure is persisted on the row' );

		$again = $this->dispatch( $hall, $this->payload_for( $hall ) );
		$this->assert_same( 200, $again['status'], 'once the store is ready again the same retry succeeds' );
		$this->assert_same( 'applied', $again['body']['outcome'], 'it RESUMES, because a stale error category forbids already_applied' );
		$this->assert_same( $blog_id, $again['body']['blog_id'], 'naming the same site' );
		$this->assert_same( null, Repo::load( $hall )['last_error_category'], 'and the confirmation cleared the stale error' );

		$settled = $this->dispatch( $hall, $this->payload_for( $hall ) );
		$this->assert_same( 'already_applied', $settled['body']['outcome'], 'and only the next retry, against a clean row, is already_applied' );
		$this->assert_same( $blog_id, $settled['body']['blog_id'], 'still naming the same site' );
	}

	/* ------------------------------------------------------------------ *
	 * Fencing and concurrency
	 * ------------------------------------------------------------------ */

	private function test_lost_fence_before_and_after_mutation(): void {
		$hall = $this->hall( 10 );
		$row  = Repo::load( $hall );

		/*
		 * AFTER A POSSIBLE MUTATION. A newer Disable is accepted onto the row
		 * from inside the platform filter — precisely the window in which a site
		 * may already have been created — so the guard that runs immediately
		 * after the filter finds a different current command.
		 */
		$newer_disable = $this->payload( [ 'intent_sequence' => (int) $row['intent_sequence'] + 5, 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] );
		$canonical     = ProvisioningCanonicalRequest::validate( array_merge( [ 'portal_hall_id' => $hall ], $newer_disable ) );

		$interfere = function ( $seed ) use ( $hall, $canonical ) {
			static $done = false;
			if ( ! $done ) {
				$done = true;
				Repo::replace_command( Repo::load( $hall ), $canonical );
			}

			return $seed;
		};

		$this->arm_snapshot( $hall );
		add_filter( PlatformGateway::FILTER, $interfere, 8 );
		try {
			$response = $this->dispatch( $hall, $this->payload( [ 'intent_sequence' => (int) $row['intent_sequence'] + 1, 'site_slug' => null, 'hall_name' => null ] ) );
		} finally {
			remove_filter( PlatformGateway::FILTER, $interfere, 8 );
		}

		$this->assert_same( 503, $response['status'], 'a fence lost after a possible mutation is HTTP 503' );
		$this->assert_same( 'unverified', $response['body']['outcome'] ?? '', 'it is unverified, never "nothing happened"' );
		$this->assert_same( 'unverified', $response['body']['error_category'] ?? '', 'its category is unverified' );

		$after = Repo::load( $hall );
		$this->assert_same( $canonical['operation_id'], $after['operation_id'], 'the NEWER Disable is what the row now carries' );
		$this->assert_same( (int) $row['blog_id'], $after['blog_id'], 'the stale Enable never re-pointed the bound site' );
		$this->assert_true(
			$after['enforced_sequence'] < $after['intent_sequence'],
			'the newer command is NOT confirmed: the stale Enable never enforced anything for it'
		);

		/* Now let the newer Disable finish, and prove it wins. */
		$response = $this->dispatch( $hall, $newer_disable );
		$this->assert_same( 200, $response['status'], 'the newer Disable completes' );
		$this->assert_same( 'suspended', $response['body']['commerce_state'] ?? '', 'it suspends the Hall' );
		$this->assert_same(
			'suspended',
			HallCommerceState::read( (int) $row['blog_id'] )['state'] ?? '',
			'a stale Enable never wrote operational over the newer Disable'
		);
		$this->assert_same( '0', (string) get_site( (int) $row['blog_id'] )->archived, 'suspension does not archive the site' );

		/*
		 * BEFORE ANY MUTATION. Accepting a NEW command is itself inside ADR-042's
		 * protected mutation scope, so the only request that reaches a guard with
		 * nothing yet written is an EXACT RETRY of a command already stored. One
		 * is arranged here by failing a suspend at its Commerce-state write, then
		 * retrying it while the ownership-marker read — which runs just before
		 * that first guard — replaces the current command with a newer one.
		 */
		$blog_id = (int) $row['blog_id'];
		$latest  = Repo::load( $hall );
		$pending = $this->payload( [ 'intent_sequence' => (int) $latest['intent_sequence'] + 1, 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] );

		$this->obstruct(
			'INSERT INTO ' . ProvisioningMigrations::state_table(),
			'INSERT INTO zz_bc_absent_state_table_for_test (blog_id) VALUES (1)',
			'INSERT'
		);
		$seeded = $this->dispatch( $hall, $pending );
		$this->assert_true( 200 !== $seeded['status'], 'the suspend is left stored but unconfirmed' );
		$this->assert_same(
			$pending['operation_id'],
			Repo::load( $hall )['operation_id'],
			'the row now carries that command'
		);

		$newest   = $this->payload( [ 'intent_sequence' => (int) $latest['intent_sequence'] + 9, 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] );
		$newest_c = ProvisioningCanonicalRequest::validate( array_merge( [ 'portal_hall_id' => $hall ], $newest ) );

		$this->interceptor = [
			'needle' => "option_name = 'bc_commerce_managed'",
			'run'    => static function () use ( $hall, $newest_c ) {
				Repo::replace_command( Repo::load( $hall ), $newest_c );
			},
		];

		$before_state = HallCommerceState::read( $blog_id );
		$response     = $this->dispatch( $hall, $pending );

		$this->assert_same( 503, $response['status'], 'a fence lost BEFORE any mutation is HTTP 503' );
		$this->assert_same(
			[ 'api_version' => '1', 'error_category' => 'lock_unavailable', 'portal_hall_id' => $hall ],
			$response['body'],
			'it returns the reduced lock_unavailable body with no outcome, exactly as the matrix fixes it'
		);
		$this->assert_same( $before_state, HallCommerceState::read( $blog_id ), 'nothing was written' );
		$this->assert_same( 0, $response['filter_calls'], 'and no platform filter ran' );

		/* Leave the Hall confirmed under the newest command. */
		$response = $this->dispatch( $hall, $newest );
		$this->assert_same( 200, $response['status'], 'the newest Disable completes' );
	}

	/**
	 * A fence lost DURING the Portal pull, after real work, is `unverified`.
	 *
	 * By that point the site exists, the mapping exists and transitional
	 * Commerce state is written. A currentness failure raised inside the
	 * synchronizer must NOT be reported as `sync_failed` (retryable, and about
	 * the Portal) nor as a reduced `lock_unavailable` (which claims nothing
	 * happened).
	 */
	private function test_fence_lost_during_synchronization(): void {
		$hall = $this->free_hall();
		$this->arm_snapshot( $hall );

		/* Bring the Hall up so a resumed enable has a site and a mapping. */
		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepsyncfence', 'hall_name' => 'Sync fence' ] ) );
		$this->assert_same( 200, $response['status'], 'the Hall is provisioned first' );
		$blog_id = (int) $response['body']['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;

		$row     = Repo::load( $hall );
		$newer   = $this->payload( [ 'intent_sequence' => (int) $row['intent_sequence'] + 5, 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] );
		$newer_c = ProvisioningCanonicalRequest::validate( array_merge( [ 'portal_hall_id' => $hall ], $newer ) );

		/*
		 * A newer Disable is accepted onto the row from inside the Portal HTTP
		 * round-trip — the exact window ADR-042 requires the fence to be
		 * re-proven across.
		 */
		$this->arm_snapshot( $hall );
		$interfere = function ( $preempt, $args, $url ) use ( $hall, $newer_c ) {
			static $done = false;
			if ( ! $done && false !== strpos( (string) $url, '/integration/v1/halls/' . $hall . '/snapshot' ) ) {
				$done = true;
				Repo::replace_command( Repo::load( $hall ), $newer_c );
			}

			return $preempt;
		};

		add_filter( 'pre_http_request', $interfere, 1, 3 );
		try {
			$result = $this->dispatch( $hall, $this->payload( [ 'intent_sequence' => (int) $row['intent_sequence'] + 1, 'site_slug' => null, 'hall_name' => null ] ) );
		} finally {
			remove_filter( 'pre_http_request', $interfere, 1 );
		}

		$this->assert_same( 503, $result['status'], 'a fence lost during the Portal pull is HTTP 503' );
		$this->assert_same( 'unverified', $result['body']['outcome'], 'its outcome is unverified' );
		$this->assert_same( 'unverified', $result['body']['error_category'], 'its category is unverified, NOT sync_failed' );
		$this->assert_true(
			'sync_failed' !== $result['body']['error_category'],
			'a currentness failure is never reported as a retryable synchronization problem'
		);
		$this->assert_true( isset( $result['body']['outcome'] ), 'and it is a FULL body, not the reduced lock_unavailable one' );
		$this->assert_same( 1, count( $result['audit'] ), 'exactly one terminal audit was attempted' );
		$this->assert_same( 'portal.commerce.unverified', $result['audit'][0]['action'] ?? '', 'and it is the unverified action' );

		$after = Repo::load( $hall );
		$this->assert_same( $newer_c['operation_id'], $after['operation_id'], 'the NEWER Disable is what the row carries' );
		$this->assert_true( $after['enforced_sequence'] < $after['intent_sequence'], 'the stale Enable confirmed nothing for it' );

		/* Let the newer Disable finish so the Hall is left in a defined state. */
		$final = $this->dispatch( $hall, $newer );
		$this->assert_same( 200, $final['status'], 'the newer Disable completes' );
		$this->assert_same( 'suspended', $final['body']['commerce_state'], 'and the Hall is suspended' );
	}

	/**
	 * The platform created a site, then failed, and the fence moved meanwhile.
	 *
	 * The guard after the filter must run BEFORE the platform result is
	 * interpreted: the consumer's own category describes a command that is no
	 * longer current, and a site may already exist.
	 */
	private function test_fence_lost_while_platform_created_a_site(): void {
		$hall = $this->free_hall();
		$slug = 'pepfilterfence';
		$this->arm_snapshot( $hall );

		$newer   = null;
		$newer_c = null;

		/*
		 * Priority 11 — AFTER the real consumer at 10 — so the site is genuinely
		 * created and initialized first. The command is then replaced, and only
		 * then is an error substituted for the consumer's success.
		 */
		$sabotage = function ( $result ) use ( $hall, &$newer_c ) {
			if ( null !== $newer_c ) {
				Repo::replace_command( Repo::load( $hall ), $newer_c );
			}

			return new WP_Error( 'site_create_failed', 'refused after creating' );
		};

		$row_before = null;

		/* First establish the replacement command's canonical form. */
		$probe   = $this->payload( [ 'site_slug' => $slug, 'hall_name' => 'Filter fence' ] );
		$newer   = $this->payload( [ 'intent_sequence' => 7, 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] );
		$newer_c = ProvisioningCanonicalRequest::validate( array_merge( [ 'portal_hall_id' => $hall ], $newer ) );

		add_filter( PlatformGateway::FILTER, $sabotage, 11 );
		try {
			$response = $this->dispatch( $hall, $probe );
		} finally {
			remove_filter( PlatformGateway::FILTER, $sabotage, 11 );
		}

		$this->assert_same( 503, $response['status'], 'the attempt is HTTP 503' );
		$this->assert_same(
			'unverified',
			$response['body']['outcome'],
			'the outcome is unverified, NOT the failed/site_create_failed the consumer reported'
		);
		$this->assert_same(
			'unverified',
			$response['body']['error_category'],
			'the fence is re-proven BEFORE the platform result is interpreted'
		);
		$this->assert_same( null, $response['body']['blog_id'], 'and no blog id is returned' );

		/* The site the consumer created really exists, archived and marked. */
		$stranded = $this->site_for_slug( $slug );
		$this->assert_true( $stranded > 0, 'the site the consumer created was NOT deleted' );
		if ( $stranded > 0 ) {
			$this->disposable_sites[ $stranded ] = true;
			$this->assert_same( '1', (string) get_site( $stranded )->archived, 'and it stays archived' );
		}

		$after = Repo::load( $hall );
		$this->assert_same( $newer_c['operation_id'], $after['operation_id'], 'the newer command is current' );
		$this->assert_same( null, $after['blog_id'], 'the stale Enable never bound the site it created' );
		$this->assert_same(
			1,
			count( $response['audit'] ),
			'exactly one terminal audit was attempted'
		);
		$this->assert_same( 'portal.commerce.unverified', $response['audit'][0]['action'] ?? '', 'and it is the unverified action' );
	}

	/**
	 * A throwing `switch_blog` hook must not strand the blog context.
	 */
	private function test_blog_context_is_restored_on_throw(): void {
		$hall = $this->free_hall();
		$this->arm_snapshot( $hall );

		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepswitch', 'hall_name' => 'Switch' ] ) );
		$this->assert_same( 200, $response['status'], 'the Hall is provisioned first' );
		$blog_id = (int) $response['body']['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;

		$entry_blog  = get_current_blog_id();
		$entry_depth = is_array( $GLOBALS['_wp_switched_stack'] ?? null ) ? count( $GLOBALS['_wp_switched_stack'] ) : 0;

		$row   = Repo::load( $hall );
		$fence = [ 'operation_id' => $row['operation_id'], 'intent_sequence' => $row['intent_sequence'] ];

		/*
		 * switch_to_blog() pushes its stack entry and moves $wpdb BEFORE firing
		 * `switch_blog`, so a callback that throws leaves the context already
		 * moved. Nothing may be left switched afterwards.
		 */
		$thrower = static function ( $new_blog_id, $prev_blog_id ) use ( $blog_id ) {
			if ( (int) $new_blog_id === $blog_id ) {
				throw new RuntimeException( 'switch_blog exploded' );
			}
		};

		$mutex = ProvisioningMutex::acquire( $hall );
		$this->assert_true( $mutex instanceof ProvisioningMutex, 'the Hall mutex is acquired' );
		if ( ! $mutex instanceof ProvisioningMutex ) {
			return;
		}

		global $wpdb;

		$entry_prefix = (string) $wpdb->prefix;
		$escaped      = null;
		$result       = null;

		add_action( 'switch_blog', $thrower, 1, 2 );
		try {
			$result = ( new PortalSyncService() )->synchronizeForProvisioning(
				$mutex,
				$hall,
				$blog_id,
				(string) $row['site_ownership_id'],
				$fence
			);
		} catch ( Throwable $throwable ) {
			$escaped = $throwable;
		} finally {
			remove_action( 'switch_blog', $thrower, 1 );
			$mutex->release();
		}

		/*
		 * THE DECLARED BOUNDARY IS PortalSyncResult|WP_Error, AND IT HOLDS. A
		 * Throwable from a `switch_blog` callback is contained rather than
		 * crossing it: a caller that receives an exception from a method
		 * documented to return a result cannot reason about what it left behind.
		 */
		$this->assert_same( null, $escaped, 'the switch_blog throw does NOT cross the declared result boundary' );
		$this->assert_true( is_wp_error( $result ), 'it is returned as a WP_Error instead' );

		if ( is_wp_error( $result ) ) {
			$this->assert_true(
				false === strpos( $result->get_error_message(), 'exploded' ),
				'and the exception text never reaches the caller'
			);

			/*
			 * AND IT MAPS TO `unverified`, NOT `sync_failed`. Provisioning work
			 * may already have been applied, and `sync_failed` would invite Portal
			 * to retry as though the Portal were at fault.
			 */
			$this->assert_same(
				'unverified',
				ProvisioningResponse::category( $result, 'sync_failed' ),
				'and the response vocabulary maps it to unverified rather than a retryable sync_failed'
			);
		}

		$depth_after = is_array( $GLOBALS['_wp_switched_stack'] ?? null ) ? count( $GLOBALS['_wp_switched_stack'] ) : 0;
		$this->assert_same( $entry_depth, $depth_after, 'the switched-blog STACK is unwound to exactly its entry depth' );
		$this->assert_same( $entry_blog, get_current_blog_id(), 'and the original blog context is restored' );
		$this->assert_same( $entry_prefix, (string) $wpdb->prefix, 'and the database prefix points at the original site again' );

		/* An OUTER switched context a caller established is preserved. */
		switch_to_blog( BC_FIXTURE_HALL_A );
		$outer_depth = count( $GLOBALS['_wp_switched_stack'] );
		$mutex       = ProvisioningMutex::acquire( $hall );
		if ( $mutex instanceof ProvisioningMutex ) {
			add_action( 'switch_blog', $thrower, 1, 2 );
			try {
				( new PortalSyncService() )->synchronizeForProvisioning( $mutex, $hall, $blog_id, (string) $row['site_ownership_id'], $fence );
			} catch ( Throwable $throwable ) {
				unset( $throwable );
			} finally {
				remove_action( 'switch_blog', $thrower, 1 );
				$mutex->release();
			}
		}
		$this->assert_same( $outer_depth, count( $GLOBALS['_wp_switched_stack'] ), 'an OUTER switched context is preserved, not popped' );
		$this->assert_same( BC_FIXTURE_HALL_A, get_current_blog_id(), 'and the caller is still on the site it switched to' );
		restore_current_blog();

		$this->assert_same( $entry_blog, get_current_blog_id(), 'the harness is back where it started' );

		$this->nested_sync_restoration_case( $hall, $blog_id, $row, $entry_depth, $entry_blog, $entry_prefix );
	}

	/**
	 * A THROW IN THE MIDDLE OF A **NESTED** UNWIND, on the provisioning sync path.
	 *
	 * The same case as the platform one, against the other production
	 * restoration site. Something running inside the switched context leaves two
	 * further switches open — exactly what a `switch_blog` listener that
	 * switches without restoring does — so three levels have to come off, and a
	 * `switch_blog` callback throws on the SECOND of the three restores.
	 *
	 * Contained per attempt, the unwind sees valid depth progress and keeps
	 * going. Contained as one loop, the throw abandoned the third level and the
	 * request carried on with `$wpdb` still pointing at the Hall.
	 *
	 * BOTH HALVES ARE ASSERTED: the unwind COMPLETES (depth, blog id and prefix,
	 * captured before any harness cleanup), and the synchronization STILL FAILS
	 * as `unverified` rather than as a retryable `sync_failed`.
	 *
	 * @param array<string,mixed> $row
	 */
	private function nested_sync_restoration_case( int $hall, int $blog_id, array $row, int $entry_depth, int $entry_blog, string $entry_prefix ): void {
		global $wpdb;

		$this->arm_snapshot( $hall );
		$fence = [ 'operation_id' => $row['operation_id'], 'intent_sequence' => $row['intent_sequence'] ];

		/*
		 * The intermediate site is the network MAIN site: it is the one blog
		 * this fixture is guaranteed to have fully installed, so the case adds
		 * no unrelated storage noise of its own.
		 */
		$intermediate = (int) get_main_site_id();

		// ONE-SHOT: its second switch re-enters $blog_id, which is its trigger.
		$leaked = false;
		$leak   = static function ( $new_blog_id, $prev_blog_id, $context = '' ) use ( $blog_id, $intermediate, &$leaked ): void {
			if ( 'switch' !== $context || (int) $new_blog_id !== $blog_id || $leaked ) {
				return;
			}
			$leaked = true;

			switch_to_blog( $intermediate );
			switch_to_blog( $blog_id );
		};

		// The MIDDLE pop of the three. The `$context` test matters: the entry
		// sequence above also produces a `switch` whose previous blog is the
		// intermediate one.
		$mid = static function ( $new_blog_id, $prev_blog_id, $context = '' ) use ( $intermediate ): void {
			if ( 'restore' === $context && (int) $prev_blog_id === $intermediate ) {
				throw new RuntimeException( 'switch_blog exploded midway through the sync unwind' );
			}
		};

		$mutex = ProvisioningMutex::acquire( $hall );
		$this->assert_true( $mutex instanceof ProvisioningMutex, 'the Hall mutex is acquired for the nested sync case' );
		if ( ! $mutex instanceof ProvisioningMutex ) {
			return;
		}

		$escaped = null;
		$result  = null;

		add_action( 'switch_blog', $leak, 1, 3 );
		add_action( 'switch_blog', $mid, 2, 3 );
		try {
			$result = ( new PortalSyncService() )->synchronizeForProvisioning(
				$mutex,
				$hall,
				$blog_id,
				(string) $row['site_ownership_id'],
				$fence
			);
		} catch ( Throwable $throwable ) {
			$escaped = $throwable;
		} finally {
			// CAPTURED FIRST, before any harness cleanup restores these values
			// itself and makes the measurement meaningless.
			$after_depth  = is_array( $GLOBALS['_wp_switched_stack'] ?? null ) ? count( $GLOBALS['_wp_switched_stack'] ) : 0;
			$after_blog   = get_current_blog_id();
			$after_prefix = (string) $wpdb->prefix;

			remove_action( 'switch_blog', $leak, 1 );
			remove_action( 'switch_blog', $mid, 2 );
			while ( ( is_array( $GLOBALS['_wp_switched_stack'] ?? null ) ? count( $GLOBALS['_wp_switched_stack'] ) : 0 ) > $entry_depth ) {
				if ( ! restore_current_blog() ) {
					break;
				}
			}
			$mutex->release();
		}

		$this->assert_true( $leaked, 'the nested sync case really did leave two EXTRA switches open inside the operation' );
		$this->assert_same( null, $escaped, 'a throw midway through a nested unwind does not cross the declared result boundary' );
		$this->assert_true( is_wp_error( $result ), 'and it is returned as a WP_Error' );
		if ( is_wp_error( $result ) ) {
			$this->assert_same(
				'unverified',
				ProvisioningResponse::category( $result, 'sync_failed' ),
				'mapped to unverified, never a retryable sync_failed'
			);
			$this->assert_true(
				false === strpos( $result->get_error_message(), 'exploded' )
					&& false === strpos( $result->get_error_message(), 'midway' ),
				'and carries no exception text'
			);
		}

		$this->assert_same( $entry_depth, $after_depth, 'EVERY level is unwound despite the throw, not just the ones before it' );
		$this->assert_same( $entry_blog, $after_blog, 'so the blog context is the entry one, measured before harness cleanup' );
		$this->assert_same( $entry_prefix, $after_prefix, 'and $wpdb is NOT left pointing at the Hall' );
	}

	/**
	 * EVERY `applied` / `already_applied` FAMILY IS FENCED AT THE SNAPSHOT.
	 *
	 * The proof runs immediately before the terminal snapshot, not merely before
	 * the last mutation — so a newer command arriving in the window between them
	 * cannot be answered with a stale success. Each case below replaces the
	 * current command from inside the LAST read that precedes the snapshot, which
	 * is deterministic: no sleep, no race, no timing.
	 */
	private function test_terminal_currentness_before_every_success(): void {
		/* --- 1. OPERATIONAL already_applied. --- */
		$hall = $this->free_hall();
		$this->arm_snapshot( $hall );
		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepfence1', 'hall_name' => 'Fence one' ] ) );
		$this->assert_same( 200, $response['status'], 'the operational Hall is enabled' );
		$blog_id = (int) $response['body']['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;

		$confirmed = $this->dispatch( $hall, $this->payload_for( $hall ) );
		$this->assert_same( 'already_applied', $confirmed['body']['outcome'] ?? '', 'and an ordinary retry is already_applied' );

		$stolen = $this->steal_command( $hall, 'operational', $this->siteurl_read_needle( $blog_id ) );
		$result = $this->dispatch( $hall, $this->payload_for( $hall ) );
		$this->assert_terminal_currentness_loss( $result, 'operational already_applied' );
		$this->assert_same( $stolen['operation_id'], Repo::load( $hall )['operation_id'], 'the newer command is what the row carries' );

		/* --- 2. APPLIED, after a full enable. --- */
		$hall = $this->free_hall();
		$this->arm_snapshot( $hall );
		$stolen = $this->steal_command_on_first( $hall, 'operational', 'pepfence2' );
		$result = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepfence2', 'hall_name' => 'Fence two' ] ) );
		$this->assert_terminal_currentness_loss( $result, 'operational applied' );
		$stranded = $this->site_for_slug( 'pepfence2' );
		if ( $stranded > 0 ) {
			$this->disposable_sites[ $stranded ] = true;
		}

		/* --- 3. BOUND SUSPENDED already_applied. --- */
		$hall = $this->free_hall();
		$this->arm_snapshot( $hall );
		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepfence3', 'hall_name' => 'Fence three' ] ) );
		$this->assert_same( 200, $response['status'], 'the suspend-fence Hall is enabled' );
		$blog_id = (int) $response['body']['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;

		$suspend = $this->payload( [ 'intent_sequence' => 2, 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] );
		$this->assert_same( 200, $this->dispatch( $hall, $suspend )['status'], 'and then suspended' );
		$this->assert_same( 'already_applied', $this->dispatch( $hall, $suspend )['body']['outcome'] ?? '', 'and its retry is already_applied' );

		$this->steal_command( $hall, 'suspended', $this->siteurl_read_needle( $blog_id ) );
		$result = $this->dispatch( $hall, $suspend );
		$this->assert_terminal_currentness_loss( $result, 'bound suspended already_applied' );

		/* --- 4. NO-SITE SUSPENDED already_applied. --- */
		$hall    = $this->free_hall();
		$cancel  = $this->payload( [ 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] );
		$this->assert_same( 200, $this->dispatch( $hall, $cancel )['status'], 'a no-site cancellation is applied' );
		$this->assert_same( 'already_applied', $this->dispatch( $hall, $cancel )['body']['outcome'] ?? '', 'and its retry is already_applied' );

		/*
		 * The no-site path reads the operation row and nothing else, so the
		 * replacement is injected on the ledger SELECT that precedes the
		 * snapshot.
		 */
		$this->steal_command( $hall, 'suspended', 'IS_USED_LOCK' );
		$result = $this->dispatch( $hall, $cancel );
		$this->assert_terminal_currentness_loss( $result, 'no-site suspended already_applied' );
	}

	/**
	 * Currentness is proven before AND after the error category is persisted.
	 */
	private function test_currentness_around_error_category_persistence(): void {
		/* --- Lost BEFORE the write: nothing is persisted. --- */
		$hall = $this->free_hall();
		$this->arm_snapshot( $hall );
		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'peperr1', 'hall_name' => 'Error one' ] ) );
		$this->assert_same( 200, $response['status'], 'the error-category Hall is enabled' );
		$blog_id = (int) $response['body']['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;

		$this->snapshots[ $hall ] = 500;
		/*
		 * ARMED by the sync failure's own last read and FIRED by the mutex probe
		 * that fail() performs BEFORE it would persist the error category, so
		 * the persistence step is the one that finds the command replaced.
		 */
		$stolen = $this->steal_command_armed( $hall, 'operational', 'RELEASE_LOCK', 'IS_USED_LOCK' );
		$result = $this->dispatch( $hall, $this->payload( [ 'intent_sequence' => 5, 'site_slug' => null, 'hall_name' => null ] ) );

		$this->assert_true( 200 !== $result['status'], 'a failure whose command was replaced is never a success' );
		$after = Repo::load( $hall );
		$this->assert_same( $stolen['operation_id'], $after['operation_id'], 'the newer command is current' );
		$this->assert_same(
			null,
			$after['last_error_category'],
			'and NO stale error category was stamped onto it by the older command'
		);

		/* --- Still current: the documented category IS persisted. --- */
		$this->snapshots[ $hall ] = 500;
		$ordinary = $this->dispatch( $hall, $this->payload_for( $hall ) );
		$this->assert_same( 'sync_failed', $ordinary['body']['error_category'] ?? '', 'an ordinary failure reports its own category' );
		$this->assert_same( 'sync_failed', Repo::load( $hall )['last_error_category'], 'and persists it' );
	}

	/**
	 * THE PORTAL-FACING URL COMES FROM STORAGE, NOT FROM A FILTER.
	 *
	 * Portal stores and displays whatever this endpoint returns as the Hall's
	 * address, so a `site_url` / `option_siteurl` / `pre_option_siteurl` filter
	 * must not be able to decide it. Each case below installs a filter that
	 * would change `get_site_url()` and asserts the response is unaffected — and
	 * that an inconsistent STORED value is refused rather than returned.
	 */
	private function test_authoritative_site_url_ignores_filters(): void {
		$hall = $this->free_hall();
		$this->arm_snapshot( $hall );

		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepurl', 'hall_name' => 'URL' ] ) );
		$this->assert_same( 200, $response['status'], 'the URL Hall is enabled' );
		$blog_id  = (int) $response['body']['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;
		$expected = $response['body']['site_url'];

		$this->assert_true( is_string( $expected ) && '' !== $expected, 'and returned a site URL' );

		foreach (
			[
				'an external HTTPS URL'   => 'https://attacker.invalid',
				'a javascript: URL'       => 'javascript:alert(1)',
				'a data: URL'             => 'data:text/html,x',
				'a protocol-relative URL' => '//attacker.invalid',
				'a URL with userinfo'     => 'http://user:pass@pepurl.fixture.bingocommerce.test',
				'a URL with a query'      => 'http://pepurl.fixture.bingocommerce.test?redirect=1',
				'an empty string'         => '',
			] as $label => $hijacked
		) {
			$filter = static fn() => $hijacked;
			add_filter( 'site_url', $filter, 10 );
			add_filter( 'option_siteurl', $filter, 10 );
			add_filter( 'pre_option_siteurl', $filter, 10 );
			try {
				$this->assert_same(
					$hijacked,
					get_site_url( $blog_id ),
					"{$label}: the filter really does change get_site_url()"
				);

				$retry = $this->dispatch( $hall, $this->payload_for( $hall ) );
			} finally {
				remove_filter( 'site_url', $filter, 10 );
				remove_filter( 'option_siteurl', $filter, 10 );
				remove_filter( 'pre_option_siteurl', $filter, 10 );
			}

			$this->assert_same( 200, $retry['status'], "{$label}: the command still succeeds" );
			$this->assert_same(
				$expected,
				$retry['body']['site_url'],
				"{$label}: and the Portal-facing URL is unchanged by the filter"
			);
		}

		/*
		 * An inconsistent STORED value is refused rather than returned. The
		 * option read is rewritten, so nothing durable is damaged and the same
		 * command succeeds again immediately afterwards.
		 */
		global $wpdb;
		foreach (
			[
				'a foreign host'        => 'http://attacker.invalid',
				'a javascript: scheme'  => 'javascript:alert(1)',
				'a relative path'       => '/pepurl',
				'a wrong path'          => 'http://pepurl.fixture.bingocommerce.test/elsewhere',
				'an unexpected port'    => 'http://pepurl.fixture.bingocommerce.test:8443',
				'a control character'   => "http://pepurl.fixture.bingocommerce.test\n",
			] as $label => $stored
		) {
			$this->obstruct(
				$this->siteurl_read_needle( $blog_id ),
				'SELECT ' . $wpdb->prepare( '%s', $stored ) . ' AS option_value',
				'SELECT'
			);
			$retry = $this->dispatch( $hall, $this->payload_for( $hall ) );

			$this->assert_true( 200 !== $retry['status'], "{$label}: an inconsistent stored siteurl is NOT reported as success" );
			$this->assert_true(
				in_array( $retry['body']['error_category'] ?? '', ProvisioningResponse::ERROR_CATEGORIES, true ),
				"{$label}: it uses an existing ADR-042 category"
			);
			$this->assert_same( null, $retry['body']['site_url'], "{$label}: and returns no site URL" );
			$this->assert_true(
				false === strpos( (string) wp_json_encode( $retry['body'] ), 'attacker.invalid' ),
				"{$label}: the rejected value is never echoed"
			);
		}

		$recovered = $this->dispatch( $hall, $this->payload_for( $hall ) );
		$this->assert_same( 200, $recovered['status'], 'the same command succeeds once storage is readable again' );
		$this->assert_same( $expected, $recovered['body']['site_url'], 'and returns the authoritative URL' );
	}

	/**
	 * THE NETWORK SITE-URL AUTHORITY IS A VERIFIED FACT, NOT A DEFAULT.
	 *
	 * `authoritative_site_url()` permits a per-site URL to carry a port only
	 * where the NETWORK itself uses one. The question "which port does the
	 * network use?" used to be answered by an `?int`, and that single return
	 * value merged two opposite facts: a VERIFIED network that uses no port, and
	 * a network row that was absent, duplicated, corrupt, unparseable or simply
	 * unreadable. Every storage failure therefore asserted "the network uses no
	 * port", the portless site URL matched it, and Portal was told
	 * `already_applied` with a URL confirmed on the strength of a failed read.
	 *
	 * Nine cases, each an ordinary retry of the SAME confirmed command, so the
	 * only thing that varies between them is what network storage answered. The
	 * first three establish that a genuine port IS honoured in both directions;
	 * the remaining six are the storage failures that must produce no URL at all.
	 *
	 * DETERMINISTIC, AND NOTHING DURABLE IS TOUCHED. Every case rewrites a read
	 * through WordPress's own `query` filter for exactly one dispatch; the
	 * closing retry proves storage is intact.
	 */
	private function test_network_url_authority(): void {
		global $wpdb;

		$hall = $this->free_hall();
		$this->arm_snapshot( $hall );

		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepnetauth', 'hall_name' => 'Network authority' ] ) );
		$this->assert_same( 200, $response['status'], 'the network-authority Hall is enabled' );
		$blog_id = (int) $response['body']['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;

		$expected = (string) $response['body']['site_url'];
		$this->assert_true( '' !== $expected, 'and returned a site URL' );

		$site_needle    = $this->siteurl_read_needle( $blog_id );
		$network_needle = $this->network_siteurl_read_needle();

		/*
		 * The needle really is this build's own statement and not core's. The
		 * ADR-042 read is the only one that asks for `LIMIT 2` — which is what
		 * makes a duplicated row visible instead of silently picked between —
		 * so obstructing it cannot disturb get_network_option().
		 */
		$this->assert_true(
			false !== strpos( $network_needle, 'LIMIT 2' ),
			'the network authority read is the LIMIT 2 statement, distinct from core\'s own unbounded read'
		);

		/* --- 1. VERIFIED, no port, and a site URL with no port. --- */
		$baseline = $this->dispatch( $hall, $this->payload_for( $hall ) );
		$this->assert_same( 200, $baseline['status'], 'verified network + portless site URL: the retry succeeds' );
		$this->assert_same( 'already_applied', $baseline['body']['outcome'] ?? '', 'and is already_applied' );
		$this->assert_same( $expected, $baseline['body']['site_url'], 'with the authoritative URL' );

		/* --- 2. VERIFIED, no port, but the SITE URL acquired one. --- */
		$this->obstruct( $site_needle, $this->as_option_value( $expected . ':8443' ), 'SELECT' );
		$this->assert_network_url_refusal(
			$this->dispatch( $hall, $this->payload_for( $hall ) ),
			'a portless network with a ported site URL'
		);

		/* --- 3. VERIFIED WITH A PORT, and the site URL carries the SAME one. --- */
		$ported = $this->with_port( $expected, 8443 );
		$this->obstruct( $network_needle, $this->as_meta_value( 'http://' . BC_FIXTURE_DOMAIN . ':8443' ), 'SELECT' );
		$this->obstruct( $site_needle, $this->as_option_value( $ported ), 'SELECT' );
		$matched = $this->dispatch( $hall, $this->payload_for( $hall ) );
		$this->assert_same( 200, $matched['status'], 'a network that genuinely uses a port keeps it' );
		$this->assert_same( $ported, $matched['body']['site_url'], 'and the ported URL is the one Portal is given' );

		/* --- 4. VERIFIED WITH A PORT, and the site URL carries a DIFFERENT one. --- */
		$this->obstruct( $network_needle, $this->as_meta_value( 'http://' . BC_FIXTURE_DOMAIN . ':8443' ), 'SELECT' );
		$this->obstruct( $site_needle, $this->as_option_value( $this->with_port( $expected, 9443 ) ), 'SELECT' );
		$this->assert_network_url_refusal(
			$this->dispatch( $hall, $this->payload_for( $hall ) ),
			'a ported network with a DIFFERENT site port'
		);

		/* --- 5. VERIFIED WITH A PORT, and the site URL carries NONE. --- */
		$this->obstruct( $network_needle, $this->as_meta_value( 'http://' . BC_FIXTURE_DOMAIN . ':8443' ), 'SELECT' );
		$this->assert_network_url_refusal(
			$this->dispatch( $hall, $this->payload_for( $hall ) ),
			'a ported network with a portless site URL'
		);

		/*
		 * --- 6-9. THE STORAGE FAILURES. ---
		 *
		 * The site's own `siteurl` is left completely alone in every one of
		 * these, and it is portless and correct. So under the old `?int` shape
		 * each of them returned null, matched the site URL's absent port, and
		 * answered 200 `already_applied`. Each must now produce no URL.
		 */
		foreach (
			[
				'an ABSENT network row'        => 'SELECT \'x\' AS meta_value FROM DUAL WHERE 1 = 0',
				'a DUPLICATED network row'     => $this->as_meta_value( 'http://' . BC_FIXTURE_DOMAIN )
					. ' UNION ALL ' . $this->as_meta_value( 'http://' . BC_FIXTURE_DOMAIN ),
				'an EMPTY network value'       => $this->as_meta_value( '' ),
				'a non-http network value'     => $this->as_meta_value( 'javascript:alert(1)' ),
				'a network value with userinfo' => $this->as_meta_value( 'http://user:pass@' . BC_FIXTURE_DOMAIN ),
				'a network value with a query' => $this->as_meta_value( 'http://' . BC_FIXTURE_DOMAIN . '?x=1' ),
				'a control character'          => $this->as_meta_value( "http://" . BC_FIXTURE_DOMAIN . "\n" ),
				'an out-of-range port'         => $this->as_meta_value( 'http://' . BC_FIXTURE_DOMAIN . ':99999' ),
				'an UNREADABLE network row'    => 'SELECT meta_value FROM zz_bc_absent_sitemeta_for_test WHERE 1 = 0',
			] as $label => $replacement
		) {
			$this->obstruct( $network_needle, $replacement, 'SELECT' );
			$this->assert_network_url_refusal( $this->dispatch( $hall, $this->payload_for( $hall ) ), $label );
		}

		/* --- 10. THE READ ITSELF THROWS. --- */
		$exploder = static function ( $query ) use ( $network_needle ) {
			if ( false !== strpos( (string) $query, $network_needle ) ) {
				throw new RuntimeException( 'the network siteurl read exploded' );
			}

			return $query;
		};
		add_filter( 'query', $exploder, 2 );
		try {
			$thrown = $this->dispatch( $hall, $this->payload_for( $hall ) );
		} finally {
			remove_filter( 'query', $exploder, 2 );
		}
		$this->assert_network_url_refusal( $thrown, 'a network read that THROWS' );
		$this->assert_true(
			false === strpos( (string) wp_json_encode( $thrown['body'] ), 'exploded' ),
			'a network read that THROWS: and no exception text reaches Portal'
		);

		/* Nothing durable was damaged by any of it. */
		$recovered = $this->dispatch( $hall, $this->payload_for( $hall ) );
		$this->assert_same( 200, $recovered['status'], 'the same command succeeds once network storage is readable again' );
		$this->assert_same( $expected, $recovered['body']['site_url'], 'and returns the authoritative URL' );
	}

	/**
	 * Every network-authority failure produces NO URL and no success.
	 *
	 * @param array<string,mixed> $result
	 */
	private function assert_network_url_refusal( array $result, string $label ): void {
		$this->assert_true( 200 !== $result['status'], "{$label}: is never reported as success" );
		$this->assert_true(
			! in_array( $result['body']['outcome'] ?? '', [ 'applied', 'already_applied' ], true ),
			"{$label}: and never applied or already_applied"
		);
		$this->assert_same( null, $result['body']['site_url'] ?? null, "{$label}: and no site URL is returned" );
		$this->assert_true(
			in_array( $result['body']['error_category'] ?? '', ProvisioningResponse::ERROR_CATEGORIES, true ),
			"{$label}: with an existing ADR-042 category"
		);
	}

	/** The ADR-042 network `siteurl` read, distinguished from core's by LIMIT 2. */
	private function network_siteurl_read_needle(): string {
		global $wpdb;

		return "FROM {$wpdb->sitemeta} WHERE site_id = " . (int) get_current_network_id()
			. " AND meta_key = 'siteurl' LIMIT 2";
	}

	/** A one-row replacement for the network authority read. */
	private function as_meta_value( string $value ): string {
		global $wpdb;

		return 'SELECT ' . $wpdb->prepare( '%s', $value ) . ' AS meta_value';
	}

	/** A one-row replacement for a per-site option read. */
	private function as_option_value( string $value ): string {
		global $wpdb;

		return 'SELECT ' . $wpdb->prepare( '%s', $value ) . ' AS option_value';
	}

	/** `http://host/path` -> `http://host:PORT/path`, without guessing at a URL. */
	private function with_port( string $url, int $port ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			$this->assert_true( false, 'the baseline site URL parses' );

			return $url;
		}

		return $parts['scheme'] . '://' . $parts['host'] . ':' . $port . ( $parts['path'] ?? '' );
	}

	/**
	 * BOUND SUSPEND: AN ABSENT RESOLVER IS STEPPED OVER, A FAILED ONE IS NOT.
	 *
	 * `verify_bound_site( $row, true )` is the suspend path's verification, and
	 * its `$address_optional` flag used to swallow EVERY WP_Error the platform
	 * topology resolver could produce. ADR-042 licenses exactly one thing —
	 * "`platform_unavailable` MUST NOT block safe suspension", i.e. a missing
	 * platform component must not strand a Hall — and the previous shape
	 * stretched that licence over an installed resolver that FAILED. A storage
	 * error, a duplicated `blogs` row or a thrown lookup then stood in for a
	 * passed identity check, and the suspend was confirmed to Portal on the
	 * strength of a question nobody answered.
	 *
	 * FOUR STORAGE FAULTS, EACH ON BOTH BOUND SUSPEND PATHS — a NEW suspend
	 * command and the `already_applied` retry of a suspended one — because the
	 * two reach the same verification from different classification branches and
	 * only one of them was covered before. The fifth case, genuine component
	 * absence, is the one that must still SUCCEED, and it runs in a worker
	 * process that never loads the plugin: `class_exists()` cannot be
	 * un-declared once a file is required, so no assertion inside this process
	 * could prove it.
	 *
	 * DETERMINISTIC: every fault is a rewrite of the resolver's own prepared
	 * statement through WordPress's own `query` filter, armed for exactly one
	 * dispatch. Nothing durable is damaged, and the closing retries prove it.
	 */
	private function test_bound_suspend_address_verification(): void {
		global $wpdb;

		$hall = $this->free_hall();
		$this->arm_snapshot( $hall );

		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepsuspaddr', 'hall_name' => 'Suspend address' ] ) );
		$this->assert_same( 200, $response['status'], 'the suspend-address Hall is enabled' );
		$blog_id = (int) $response['body']['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;
		$site_url = (string) $response['body']['site_url'];

		$this->assert_true(
			PlatformGateway::resolverInstalled(),
			'the platform topology resolver IS installed in this process, so every fault below is a FAILURE and not an absence'
		);

		$probe = $this->blogs_lookup_needle();

		/*
		 * Each entry is [ replacement statement | null, throwing?, acceptable
		 * categories ]. A rewritten statement that errors, returns two rows, or
		 * returns a different site all fail closed; only the categories ADR-042
		 * already defines may appear.
		 */
		$faults = [
			'an UNREADABLE blogs lookup' => [
				'SELECT blog_id FROM zz_bc_absent_blogs_for_test WHERE 1 = 0',
				false,
				[ 'platform_unavailable' ],
			],
			'a DUPLICATED address'       => [
				'SELECT ' . (int) $blog_id . ' AS blog_id UNION ALL SELECT ' . (int) BC_FIXTURE_HALL_A . ' AS blog_id',
				false,
				[ 'platform_unavailable' ],
			],
			'a DIFFERENT resolved blog'  => [
				'SELECT ' . (int) BC_FIXTURE_HALL_A . ' AS blog_id',
				false,
				[ 'slug_conflict' ],
			],
			'a THROWN blogs lookup'      => [
				null,
				true,
				[ 'platform_unavailable' ],
			],
		];

		/* ---------- 1. A NEW BOUND SUSPEND COMMAND. ---------- */
		$sequence = 2;
		foreach ( $faults as $label => $fault ) {
			$payload = $this->payload(
				[
					'intent_sequence' => $sequence,
					'desired_state'   => 'suspended',
					'site_slug'       => null,
					'hall_name'       => null,
				]
			);
			++$sequence;

			$result = $this->dispatch_with_blogs_fault( $hall, $payload, $probe, $fault );
			$this->assert_suspend_refusal( $result, "new suspend under {$label}", $fault[2] );

			/*
			 * AND THE SUSPENSION DID NOT HAPPEN. The refusal is before any
			 * Commerce-state write, so the site is still exactly as it was.
			 */
			$this->assert_same(
				'operational',
				HallCommerceState::read( $blog_id )['state'] ?? '',
				"new suspend under {$label}: the bound site's Commerce state is untouched"
			);
		}

		/* The same command with nothing obstructed really does suspend. */
		$applied = $this->dispatch(
			$hall,
			$this->payload(
				[
					'intent_sequence' => $sequence,
					'desired_state'   => 'suspended',
					'site_slug'       => null,
					'hall_name'       => null,
				]
			)
		);
		$this->assert_same( 200, $applied['status'], 'with the resolver answering normally, the suspend applies' );
		$this->assert_same( 'applied', $applied['body']['outcome'] ?? '', 'as applied' );
		$this->assert_same( 'suspended', $applied['body']['commerce_state'] ?? '', 'and the Hall is suspended' );

		/* ---------- 2. THE SUSPENDED `already_applied` RETRY. ---------- */
		$confirmed = $this->dispatch( $hall, $this->payload_for( $hall ) );
		$this->assert_same( 'already_applied', $confirmed['body']['outcome'] ?? '', 'an ordinary suspended retry is already_applied' );
		$this->assert_same( $site_url, $confirmed['body']['site_url'] ?? '', 'and returns the authoritative URL' );

		foreach ( $faults as $label => $fault ) {
			$result = $this->dispatch_with_blogs_fault( $hall, $this->payload_for( $hall ), $probe, $fault );
			$this->assert_suspend_refusal( $result, "suspended already_applied under {$label}", $fault[2] );
		}

		/*
		 * RECOVERY, AND WHY IT IS `applied` AND NOT `already_applied`. Each
		 * refusal above stamped its category onto the row, and ADR-042's
		 * `already_applied` requires a confirmed row with NO standing failure —
		 * so the first clean retry genuinely re-applies the suspension and
		 * clears the stamp, and only the one after it is `already_applied`.
		 * Asserting the pair is what shows the failures were really persisted
		 * and really cleared, rather than never recorded at all.
		 */
		$recovered = $this->dispatch( $hall, $this->payload_for( $hall ) );
		$this->assert_same( 200, $recovered['status'], 'the suspended retry succeeds again once the resolver answers' );
		$this->assert_same( 'applied', $recovered['body']['outcome'] ?? '', 'as applied, because the stale failure on the row is cleared by re-applying' );
		$cleared = Repo::load( $hall );
		$this->assert_true(
			is_array( $cleared ) && array_key_exists( 'last_error_category', $cleared ) && null === $cleared['last_error_category'],
			'and the row no longer carries a failure'
		);

		$settled = $this->dispatch( $hall, $this->payload_for( $hall ) );
		$this->assert_same( 'already_applied', $settled['body']['outcome'] ?? '', 'the retry after that is already_applied' );

		/* ---------- 3. GENUINE COMPONENT ABSENCE, IN ITS OWN PROCESS. ---------- */
		$this->absent_platform_suspend_case( $hall, $blog_id, $site_url );
	}

	/**
	 * Dispatch one command with the resolver's blogs lookup faulted.
	 *
	 * @param array<string,mixed> $payload
	 * @param array{0:?string,1:bool,2:array<int,string>} $fault
	 * @return array<string,mixed>
	 */
	private function dispatch_with_blogs_fault( int $hall, array $payload, string $probe, array $fault ): array {
		if ( true === $fault[1] ) {
			$exploder = static function ( $query ) use ( $probe ) {
				if ( false !== strpos( (string) $query, $probe ) ) {
					throw new RuntimeException( 'the blogs lookup exploded' );
				}

				return $query;
			};

			add_filter( 'query', $exploder, 2 );
			try {
				return $this->dispatch( $hall, $payload );
			} finally {
				remove_filter( 'query', $exploder, 2 );
			}
		}

		$this->obstruct( $probe, (string) $fault[0], 'SELECT' );

		return $this->dispatch( $hall, $payload );
	}

	/**
	 * A faulted resolver never confirms a suspension.
	 *
	 * @param array<string,mixed> $result
	 * @param array<int,string>   $categories
	 */
	private function assert_suspend_refusal( array $result, string $label, array $categories ): void {
		$this->assert_true( 200 !== $result['status'], "{$label}: is never reported as success" );
		$this->assert_true(
			! in_array( $result['body']['outcome'] ?? '', [ 'applied', 'already_applied' ], true ),
			"{$label}: and never applied or already_applied"
		);

		$category = (string) ( $result['body']['error_category'] ?? '' );
		$this->assert_true(
			in_array( $category, $categories, true ),
			"{$label}: fails closed as " . implode( '/', $categories ) . " (got '{$category}')"
		);
		$this->assert_true(
			in_array( $category, ProvisioningResponse::ERROR_CATEGORIES, true ),
			"{$label}: using an existing ADR-042 category and no new vocabulary"
		);

		/* No foreign identity is ever named. */
		$encoded = (string) wp_json_encode( $result['body'] );
		$this->assert_true(
			false === strpos( $encoded, '"blog_id":' . BC_FIXTURE_HALL_A )
				&& false === strpos( $encoded, 'exploded' )
				&& false === strpos( $encoded, 'zz_bc_absent_blogs_for_test' ),
			"{$label}: and discloses no foreign blog, exception text or identifier"
		);
	}

	/**
	 * GENUINE COMPONENT ABSENCE: the suspend still works, in a worker process.
	 *
	 * Both bound suspend paths are exercised there — the `already_applied` retry
	 * of the suspended command, and a NEW higher-sequence suspend — because
	 * ADR-042's allowance covers the whole suspend branch and not one entry into
	 * it. The three-fact marker agreement still runs and still decides; it needs
	 * nothing from `bingocommerce-platform`.
	 */
	private function absent_platform_suspend_case( int $hall, int $blog_id, string $site_url ): void {
		$path = __DIR__ . '/portal-platform-absent-worker.php';
		if ( ! is_file( $path ) ) {
			$this->assert_true( false, 'the absent-platform worker exists' );

			return;
		}

		$row = Repo::load( $hall );
		if ( ! is_array( $row ) ) {
			$this->assert_true( false, 'the suspended Hall row is readable before the worker runs' );

			return;
		}

		$retry = $this->payload_for( $hall );
		$fresh = $this->payload(
			[
				'intent_sequence' => (int) $row['intent_sequence'] + 3,
				'desired_state'   => 'suspended',
				'site_slug'       => null,
				'hall_name'       => null,
			]
		);

		$output = (string) shell_exec(
			sprintf(
				'BC_TEST_DB=%s BC_ABSENT_HALL=%s BC_ABSENT_MACHINE=%s BC_ABSENT_PAYLOAD_RETRY=%s BC_ABSENT_PAYLOAD_NEW=%s %s %s 2>/dev/null',
				escapeshellarg( BC_Fixture::$database ),
				escapeshellarg( (string) $hall ),
				escapeshellarg( (string) $this->machine_id ),
				escapeshellarg( (string) wp_json_encode( (object) $retry ) ),
				escapeshellarg( (string) wp_json_encode( (object) $fresh ) ),
				escapeshellarg( PHP_BINARY ),
				escapeshellarg( $path )
			)
		);

		$lines  = array_values(
			array_filter(
				array_map( 'trim', explode( "\n", $output ) ),
				static fn( string $l ): bool => '' !== $l && '{' === $l[0]
			)
		);
		$report = $lines ? json_decode( (string) end( $lines ), true ) : null;

		if ( ! is_array( $report ) ) {
			$this->assert_true( false, 'the absent-platform worker reported a usable result' );

			return;
		}

		/* THE PRECONDITION FIRST. Without it the two results below prove nothing. */
		$this->assert_same( false, $report['platform_loaded'] ?? null, 'the worker process really has NO bingocommerce-platform loaded' );
		$this->assert_same( false, $report['resolver_installed'] ?? null, 'and the gateway reports the resolver as absent' );

		foreach (
			[
				'BC_ABSENT_PAYLOAD_RETRY' => [ 'already_applied', 'the suspended already_applied retry' ],
				'BC_ABSENT_PAYLOAD_NEW'   => [ 'applied', 'a NEW bound suspend command' ],
			] as $slot => $expected
		) {
			$dispatch = $report['dispatches'][ $slot ] ?? null;
			if ( ! is_array( $dispatch ) ) {
				$this->assert_true( false, "{$expected[1]}: the worker reported a dispatch" );

				continue;
			}

			$this->assert_same( 200, (int) ( $dispatch['status'] ?? 0 ), "{$expected[1]} succeeds with the platform plugin ABSENT" );
			$this->assert_same( $expected[0], (string) ( $dispatch['body']['outcome'] ?? '' ), "{$expected[1]}: as {$expected[0]}" );
			$this->assert_same( 'suspended', (string) ( $dispatch['body']['commerce_state'] ?? '' ), "{$expected[1]}: the Hall is suspended" );
			$this->assert_same( $blog_id, (int) ( $dispatch['body']['blog_id'] ?? 0 ), "{$expected[1]}: bound to the same site" );
			$this->assert_same( $site_url, (string) ( $dispatch['body']['site_url'] ?? '' ), "{$expected[1]}: with the authoritative URL" );
		}

		/*
		 * AND THE OWNERSHIP AUTHORITY REALLY DID RUN. With the supplementary
		 * topology check stepped over, the three-fact agreement is all that
		 * stands between a suspend and the wrong site — so the marker the worker
		 * verified against is re-read here and must still be this Hall's.
		 */
		$marker = HallCommerceAccess::read_marker_value( $blog_id );
		$this->assert_same(
			(string) $row['site_ownership_id'],
			(string) ( $marker['value'] ?? '' ),
			'the ownership marker the absent-platform suspend verified against is this Hall\'s own'
		);
	}

	/** The platform resolver's own prepared address lookup against `$wpdb->blogs`. */
	private function blogs_lookup_needle(): string {
		global $wpdb;

		return "SELECT blog_id FROM {$wpdb->blogs} WHERE site_id = ";
	}

	/**
	 * AN ESCAPED Throwable IS FINALIZED LIKE ANY OTHER FAILURE.
	 *
	 * handle()'s catch used to answer with a bare
	 * `terminal( $mutated ? 'unverified' : 'internal_error' )`, and that was
	 * wrong in two ways this suite now measures. It never PROVED CURRENTNESS, so
	 * a Throwable arriving after a newer command had replaced this one produced
	 * a terminal audit attributed to a fence that no longer existed; and it never
	 * PERSISTED the category, so the one failure mode nobody planned for was the
	 * only one a later retry could not see standing on the row.
	 *
	 * Every case below raises a REAL Throwable from a `query` filter — WordPress
	 * fires that filter before the statement runs, so the statement never
	 * executes and the fault is exact — keyed on one statement of the dispatch.
	 * No sleeps, no races, no timing.
	 */
	private function test_throwable_after_classification(): void {
		$hall = $this->free_hall();
		$this->arm_snapshot( $hall );

		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepfinal', 'hall_name' => 'Throwable' ] ) );
		$this->assert_same( 200, $response['status'], 'the Throwable Hall is enabled' );
		$blog_id = (int) $response['body']['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;

		$confirmed = $this->dispatch( $hall, $this->payload_for( $hall ) );
		$this->assert_same( 'already_applied', $confirmed['body']['outcome'] ?? '', 'and an ordinary retry is already_applied' );

		/*
		 * THE THROW POINT IS THE HALL MAPPING READ, and the choice is not
		 * arbitrary. Everything ADR-042 added contains its own Throwables —
		 * ProvisioningOperationRepository, HallCommerceState, HallCommerceAccess
		 * and the orchestrator's own option reader all catch and convert — so a
		 * fault injected into any of them never reaches handle()'s catch at all.
		 * HallMappingRepository does not, which makes its read the one statement
		 * on these paths where a genuine exception really does escape into the
		 * finalizer. It runs on BOTH bound paths under test, after the row is
		 * established and before any Commerce-state write.
		 */
		$mapping_read = 'FROM ' . MappingMigrations::hall_mappings_table();
		$blogs_probe  = $this->blogs_lookup_needle();
		$operations   = 'FROM ' . ProvisioningMigrations::operations_table();

		/*
		 * --- 1. BEFORE A ROW IS ESTABLISHED. ---
		 *
		 * The finalizer's no-row branch must touch no stored state at all: no
		 * fence proof, no category stamp, nothing attributed to whatever this
		 * Hall happens to have persisted.
		 *
		 * A pre-row THROWABLE is not reachable by any hook in this build —
		 * load(), classify() and every repository write contain their own, which
		 * is why that branch is defence rather than a live path — so the CONTRACT
		 * is asserted through the failure that is reachable at the same point:
		 * an unreadable operations row, raised before classification. The row
		 * must come back byte-for-byte unchanged and the answer must name no
		 * site.
		 */
		$before = Repo::load( $hall );
		$this->assert_true( is_array( $before ), 'the confirmed row is readable before the pre-row case' );

		$this->obstruct( $operations, 'SELECT * FROM zz_bc_absent_operations_for_test WHERE 1 = 0', 'SELECT' );
		$pre_row = $this->dispatch( $hall, $this->payload_for( $hall ) );

		$this->assert_same( 503, $pre_row['status'], 'a failure before any row is established is HTTP 503' );
		$this->assert_same( 'unverified', $pre_row['body']['outcome'] ?? '', 'reported as unverified' );
		$this->assert_true(
			is_array( $pre_row['body'] ) && array_key_exists( 'blog_id', $pre_row['body'] ) && null === $pre_row['body']['blog_id'],
			'and names no site'
		);
		$this->assert_same( $before, Repo::load( $hall ), 'and the stored row is COMPLETELY unchanged: no category was stamped onto it' );

		/* --- 2. AFTER CLASSIFICATION, BEFORE ANY MUTATION. --- */
		$pre_mutation = $this->dispatch_throwing( $hall, $this->payload_for( $hall ), $mapping_read );

		$this->assert_same( 503, $pre_mutation['status'], 'a Throwable on an exact retry is HTTP 503' );
		$this->assert_same( 'internal_error', $pre_mutation['body']['error_category'] ?? '', 'a definite, non-mutating internal_error' );
		$this->assert_same( 'failed', $pre_mutation['body']['outcome'] ?? '', 'with the failed outcome' );
		$this->assert_same(
			'internal_error',
			Repo::load( $hall )['last_error_category'] ?? '',
			'AND THE CATEGORY IS PERSISTED, so a later retry cannot be granted already_applied over a standing failure'
		);
		$this->assert_true(
			false === strpos( (string) wp_json_encode( $pre_mutation['body'] ), 'exploded' ),
			'and no exception text reaches Portal'
		);

		/* The standing failure really does block already_applied until cleared. */
		$blocked = $this->dispatch( $hall, $this->payload_for( $hall ) );
		$this->assert_same( 'applied', $blocked['body']['outcome'] ?? '', 'the next clean retry re-applies rather than claiming already_applied' );

		/* --- 3. AFTER A MUTATION. --- */
		$row  = Repo::load( $hall );
		$next = $this->payload(
			[
				'intent_sequence' => (int) $row['intent_sequence'] + 1,
				'desired_state'   => 'suspended',
				'site_slug'       => null,
				'hall_name'       => null,
			]
		);

		$post_mutation = $this->dispatch_throwing( $hall, $next, $mapping_read );

		$this->assert_same( 503, $post_mutation['status'], 'a Throwable after the command was replaced is HTTP 503' );
		$this->assert_same(
			'unverified',
			$post_mutation['body']['error_category'] ?? '',
			'and is unverified, never internal_error: something may already have changed'
		);
		$this->assert_same( 'unverified', $post_mutation['body']['outcome'] ?? '', 'with the unverified outcome' );
		$this->assert_same(
			'unverified',
			Repo::load( $hall )['last_error_category'] ?? '',
			'and that category is persisted on the row too'
		);

		/* --- 4. A NEWER COMMAND REPLACES THE FENCE, PRE-MUTATION. --- */
		$recovered = $this->dispatch( $hall, $this->payload_for( $hall ) );
		$this->assert_same( 200, $recovered['status'], 'the Hall recovers before the fence cases' );
		$settled = $this->dispatch( $hall, $this->payload_for( $hall ) );
		$this->assert_same( 'already_applied', $settled['body']['outcome'] ?? '', 'and settles to already_applied' );

		$stolen = $this->steal_command( $hall, 'suspended', $blogs_probe );
		$fenced = $this->dispatch_throwing( $hall, $this->payload_for( $hall ), $mapping_read );

		$this->assert_same( 503, $fenced['status'], 'a Throwable whose fence was taken over is HTTP 503' );
		$this->assert_same(
			'lock_unavailable',
			$fenced['body']['error_category'] ?? '',
			'and reports the loss, NOT this command\'s own internal_error'
		);
		$this->assert_same(
			[ 'api_version', 'error_category', 'portal_hall_id' ],
			$this->sorted_keys( is_array( $fenced['body'] ) ? $fenced['body'] : [] ),
			'as ADR-042\'s reduced body, with the outcome absent'
		);
		$this->assert_same( [], $fenced['audit'], 'and NO terminal audit is written for a superseded fence' );
		$this->assert_same(
			$stolen['operation_id'],
			Repo::load( $hall )['operation_id'] ?? '',
			'the newer command is what the row carries'
		);
		$after_fence = Repo::load( $hall );
		$this->assert_true(
			is_array( $after_fence ) && array_key_exists( 'last_error_category', $after_fence ) && null === $after_fence['last_error_category'],
			'and this command\'s failure was NOT stamped onto the newer command\'s row'
		);

		/* --- 5. A NEWER COMMAND REPLACES THE FENCE, POST-MUTATION. --- */
		$row   = Repo::load( $hall );
		$newer = $this->payload(
			[
				'intent_sequence' => (int) $row['intent_sequence'] + 1,
				'desired_state'   => 'suspended',
				'site_slug'       => null,
				'hall_name'       => null,
			]
		);

		$this->steal_command( $hall, 'suspended', $blogs_probe );
		$lost = $this->dispatch_throwing( $hall, $newer, $mapping_read );

		$this->assert_same( 503, $lost['status'], 'a post-mutation Throwable with a lost fence is HTTP 503' );
		$this->assert_same(
			'unverified',
			$lost['body']['error_category'] ?? '',
			'and is unverified: it may already have changed something, so the reduced "nothing happened" answer is forbidden'
		);
		$this->assert_same( 'unverified', $lost['body']['outcome'] ?? '', 'with the unverified outcome' );
		$this->assert_true(
			10 === count( is_array( $lost['body'] ) ? $lost['body'] : [] ),
			'in the FULL ten-key body, never the reduced one'
		);
	}

	/**
	 * Dispatch one command with a real Throwable raised from one statement.
	 *
	 * The `query` filter fires BEFORE the statement executes, so the throw is
	 * exact: nothing partially ran, and the fault lands at a named point rather
	 * than wherever a timer happened to expire.
	 *
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function dispatch_throwing( int $hall, array $payload, string $needle ): array {
		$exploder = static function ( $query ) use ( $needle ) {
			if ( false !== strpos( (string) $query, $needle ) ) {
				throw new RuntimeException( 'the statement exploded with SELECT secrets' );
			}

			return $query;
		};

		add_filter( 'query', $exploder, 3 );
		try {
			return $this->dispatch( $hall, $payload );
		} finally {
			remove_filter( 'query', $exploder, 3 );
		}
	}

	/**
	 * A DUPLICATE network address is refused on every bound path.
	 */
	private function test_duplicate_address_on_bound_paths(): void {
		global $wpdb;

		$hall = $this->free_hall();
		$this->arm_snapshot( $hall );

		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepdup', 'hall_name' => 'Duplicate' ] ) );
		$this->assert_same( 200, $response['status'], 'the duplicate-address Hall is enabled' );
		$blog_id = (int) $response['body']['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;

		$blogs     = (string) $wpdb->blogs;
		$duplicate = static function ( $query ) use ( $blogs, $blog_id ) {
			$text = (string) $query;

			return ( false !== strpos( $text, $blogs ) && 1 === preg_match( '/^\s*SELECT\s+blog_id\s+FROM/i', $text ) )
				? 'SELECT ' . (int) $blog_id . ' AS blog_id UNION ALL SELECT 2 AS blog_id'
				: $query;
		};

		/* --- already_applied must re-prove it. --- */
		$confirmed = $this->dispatch( $hall, $this->payload_for( $hall ) );
		$this->assert_same( 'already_applied', $confirmed['body']['outcome'] ?? '', 'an ordinary retry is already_applied' );

		add_filter( 'query', $duplicate, 1 );
		try {
			$ambiguous = $this->dispatch( $hall, $this->payload_for( $hall ) );
		} finally {
			remove_filter( 'query', $duplicate, 1 );
		}

		$this->assert_true( 200 !== $ambiguous['status'], 'a DUPLICATE address is never already_applied' );
		$this->assert_true(
			in_array( $ambiguous['body']['error_category'] ?? '', [ 'platform_unavailable', 'slug_conflict', 'site_ownership_unproven' ], true ),
			'it fails closed with a documented category (got \'' . (string) ( $ambiguous['body']['error_category'] ?? '' ) . '\')'
		);
		$this->assert_same( null, $ambiguous['body']['blog_id'], 'and discloses no blog id' );

		/* --- a bound re-enable must re-prove it too. --- */
		$this->arm_snapshot( $hall );
		add_filter( 'query', $duplicate, 1 );
		try {
			$reenable = $this->dispatch( $hall, $this->payload( [ 'intent_sequence' => 6, 'site_slug' => null, 'hall_name' => null ] ) );
		} finally {
			remove_filter( 'query', $duplicate, 1 );
		}

		$this->assert_true( 200 !== $reenable['status'], 'a bound re-enable over a duplicate address is never applied' );
		$this->assert_same( null, $reenable['body']['blog_id'], 'and discloses no blog id' );
		$this->assert_true(
			false === strpos( (string) wp_json_encode( $reenable['body'] ), '"2"' ),
			'and never names the foreign row'
		);

		/* Without the duplicate, the same command succeeds. */
		$this->arm_snapshot( $hall );
		$clean = $this->dispatch( $hall, $this->payload_for( $hall ) );
		$this->assert_same( 200, $clean['status'], 'the same command succeeds against a unique address' );
		$this->assert_same( $blog_id, $clean['body']['blog_id'], 'reusing the same site' );
	}

	private function test_hall_independence_and_serialization(): void {
		$busy = $this->hall( 10 );
		$free = $this->free_hall();
		$this->arm_snapshot( $free );

		/*
		 * The Hall mutex is held for ONE Hall. There is no timing here and no
		 * SQL sleep: ProvisioningMutex refuses a second acquisition for the same
		 * Hall in the same request, deterministically, and says nothing at all
		 * about any other Hall.
		 */
		$mutex = ProvisioningMutex::acquire( $busy );
		$this->assert_true( $mutex instanceof ProvisioningMutex, 'the Hall mutex is acquired for the busy Hall' );
		if ( ! $mutex instanceof ProvisioningMutex ) {
			return;
		}

		try {
			$blocked = $this->dispatch( $busy, $this->payload( [ 'intent_sequence' => 99, 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] ) );
			$this->assert_same( 503, $blocked['status'], 'a second command for the SAME Hall is refused' );
			$this->assert_same(
				[ 'api_version' => '1', 'error_category' => 'lock_unavailable', 'portal_hall_id' => $busy ],
				$blocked['body'],
				'it returns the reduced lock_unavailable body'
			);
			$this->assert_same( 0, count( $blocked['audit'] ), 'a pre-classification lock failure writes NO provisioning audit' );
			$this->assert_same( 0, $blocked['filter_calls'], 'and invokes no platform filter' );

			$other = $this->dispatch( $free, $this->payload( [ 'site_slug' => 'pepconcurrent', 'hall_name' => 'Concurrent' ] ) );
			$this->assert_same( 200, $other['status'], 'a DIFFERENT Hall provisions concurrently, unaffected' );
			$this->disposable_sites[ (int) $other['body']['blog_id'] ] = true;
		} finally {
			$mutex->release();
		}

		$this->assert_same(
			0,
			$this->count_operations_for_sequence( $busy, 99 ),
			'the refused command left no trace on the busy Hall'
		);
	}

	/* ------------------------------------------------------------------ *
	 * Suspend and re-enable
	 * ------------------------------------------------------------------ */

	private function test_pre_bind_cancellation(): void {
		$hall     = $this->free_hall();
		$payload  = $this->payload( [ 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] );
		$response = $this->dispatch( $hall, $payload );

		$this->assert_same( 200, $response['status'], 'a first-command no-site suspend is HTTP 200' );
		$this->assert_same( 'applied', $response['body']['outcome'] ?? '', 'it is applied' );
		$this->assert_same( 'suspended', $response['body']['commerce_state'] ?? '', 'the Commerce state is suspended' );
		$this->assert_same( 'portal_blocked', $response['body']['effective_access'] ?? '', 'the effective access is portal_blocked' );
		$this->assert_same( null, $response['body']['blog_id'], 'no blog id is returned' );
		$this->assert_same( null, $response['body']['site_url'], 'no site URL is returned' );
		$this->assert_same( null, $response['body']['error_category'], 'no error category is returned' );

		$this->assert_same( 0, $response['filter_calls'], 'a suspend NEVER invokes the platform filter' );
		$this->assert_same( [], $response['http'], 'a suspend makes no Portal request' );
		$this->assert_same( 0, $this->count_sites_for_slug( 'pepnothing' ), 'a pre-bind cancellation creates no site' );

		$row = Repo::load( $hall );
		$this->assert_same( null, $row['blog_id'], 'nothing is bound' );
		$this->assert_same( null, $row['site_ownership_id'], 'no ownership identity is allocated for a suspend' );
		$this->assert_same( null, $row['site_slug'], 'no address is reserved for a suspend' );
		$this->assert_same( 1, $row['enforced_sequence'], 'the cancellation is confirmed' );
		$this->assert_same( null, ( new HallMappingRepository() )->findByPortalHallId( $hall ), 'no Hall mapping is created' );

		$this->assert_same( 1, count( $response['audit'] ), 'exactly one terminal audit was attempted' );
		$this->assert_same(
			'portal.commerce.suspended',
			$response['audit'][0]['action'] ?? '',
			'a suspended applied maps to portal.commerce.suspended'
		);
		$this->assert_same( null, $response['audit'][0]['blog_id'], 'the audit carries blog_id present-with-null before a bind' );

		/* The confirmed retry is already_applied and does no work. */
		$retry = $this->dispatch( $hall, $payload );
		$this->assert_same( 200, $retry['status'], 'the confirmed retry is HTTP 200' );
		$this->assert_same( 'already_applied', $retry['body']['outcome'] ?? '', 'the confirmed retry is already_applied' );
		$this->assert_same( 'suspended', $retry['body']['commerce_state'] ?? '', 'it still reports suspended' );
		$this->assert_same( null, $retry['body']['blog_id'], 'with a null blog id' );
		$this->assert_same( 0, $retry['filter_calls'], 'and no platform filter' );
		$this->assert_same(
			'portal.commerce.suspended',
			$retry['audit'][0]['action'] ?? '',
			'a suspended already_applied ALSO maps to portal.commerce.suspended'
		);
		$this->assert_same( $row, Repo::load( $hall ), 'the confirmed retry mutates nothing' );
	}

	/**
	 * An ownership identity was allocated, but no site exists at the address.
	 */
	private function test_pre_bind_cancellation_preserves_ownership(): void {
		$hall = $this->free_hall();

		/* Force a create failure AFTER the ownership identity is persisted. */
		$seed = static fn( $value ) => new WP_Error( 'site_create_failed', 'refused' );
		add_filter( PlatformGateway::FILTER, $seed, 8 );
		try {
			$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepownedonly', 'hall_name' => 'Owned only' ] ) );
		} finally {
			remove_filter( PlatformGateway::FILTER, $seed, 8 );
		}
		$this->assert_same( 'site_create_failed', $response['body']['error_category'] ?? '', 'creation failed as arranged' );

		$row = Repo::load( $hall );
		$this->assert_true( ProvisioningCanonicalRequest::is_uuid( $row['site_ownership_id'] ), 'an ownership identity was persisted' );
		$this->assert_same( null, $row['blog_id'], 'but nothing is bound' );
		$ownership = $row['site_ownership_id'];
		$this->assert_same( 0, $this->count_sites_for_slug( 'pepownedonly' ), 'and no site exists at the reserved address' );

		/* A higher Disable cancels without creating anything. */
		$response = $this->dispatch( $hall, $this->payload( [ 'intent_sequence' => 2, 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] ) );

		$this->assert_same( 200, $response['status'], 'a no-site cancellation with an existing ownership id is HTTP 200' );
		$this->assert_same( 'applied', $response['body']['outcome'] ?? '', 'it is applied' );
		$this->assert_same( null, $response['body']['blog_id'], 'with a null blog id' );
		$this->assert_same( 'suspended', $response['body']['commerce_state'] ?? '', 'and a suspended state' );
		$this->assert_same( 0, $response['filter_calls'], 'and no platform filter' );

		$after = Repo::load( $hall );
		$this->assert_same( $ownership, $after['site_ownership_id'], 'the permanent ownership identity is PRESERVED, never regenerated' );
		$this->assert_same( 'pepownedonly', $after['site_slug'], 'the durable slug is preserved too' );
		$this->assert_same( null, $after['last_error_category'], 'the confirmed cancellation cleared the stale error category' );
		$this->assert_same( 0, $this->count_sites_for_slug( 'pepownedonly' ), 'still no site exists' );
	}

	/**
	 * A marker-matched partial site is attached and suspended, WITHOUT
	 * initialization and WITHOUT disturbing `archived`.
	 */
	private function test_marker_matched_partial_suspend(): void {
		foreach ( [ 'absent', 'exact' ] as $mapping_state ) {
			$hall = $this->free_hall();
			$slug = 'peppartsusp' . ( 'absent' === $mapping_state ? 'a' : 'b' );
			$this->arm_snapshot( $hall );

			/* Create the partial site, then lose the attachment. */
			$this->obstruct( 'SET blog_id =', 'UPDATE ' . ProvisioningMigrations::operations_table() . ' SET blog_id = NULL WHERE 1 = 0', 'UPDATE' );
			$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => $slug, 'hall_name' => 'Partial suspend' ] ) );
			$this->assert_same( 'unverified', $response['body']['outcome'] ?? '', "the {$mapping_state} partial site is created unattached" );

			$stranded = $this->site_for_slug( $slug );
			$this->assert_true( $stranded > 0, "a marker-matched partial site exists for the {$mapping_state} case" );
			if ( 0 === $stranded ) {
				continue;
			}
			$this->disposable_sites[ $stranded ] = true;

			$row = Repo::load( $hall );
			$this->assert_same( null, $row['blog_id'], 'the row is still unbound' );

			if ( 'exact' === $mapping_state ) {
				$mutex = ProvisioningMutex::acquire( $hall );
				$this->assert_true( $mutex instanceof ProvisioningMutex, 'the Hall mutex is available to pre-establish the mapping' );
				if ( $mutex instanceof ProvisioningMutex ) {
					try {
						$bound = ( new HallMappingRepository() )->bindForProvisioning(
							$mutex,
							$hall,
							$stranded,
							(string) $row['site_ownership_id']
						);
						$this->assert_true( is_array( $bound ), 'an exact mapping is pre-established for this case' );
					} finally {
						// Released before the dispatch below, which needs this
						// very Hall's mutex and would otherwise be refused.
						$mutex->release();
					}
				}
			}

			$before_mapping = ( new HallMappingRepository() )->findByPortalHallId( $hall );

			$response = $this->dispatch( $hall, $this->payload( [ 'intent_sequence' => 2, 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] ) );

			$this->assert_same( 200, $response['status'], "a marker-matched partial suspend ({$mapping_state} mapping) is HTTP 200" );
			$this->assert_same( 'applied', $response['body']['outcome'] ?? '', 'it is applied' );
			$this->assert_same( 'suspended', $response['body']['commerce_state'] ?? '', 'its state is suspended' );
			$this->assert_same( 'portal_blocked', $response['body']['effective_access'] ?? '', 'its access is portal_blocked' );
			$this->assert_same( $stranded, $response['body']['blog_id'] ?? 0, 'it returns the VERIFIED blog id' );
			$this->assert_same( get_site_url( $stranded ), $response['body']['site_url'] ?? '', 'and the independently read authoritative URL' );
			$this->assert_same( 0, $response['filter_calls'], 'a marker-matched partial suspend invokes NO platform filter' );
			$this->assert_same( [], $response['http'], 'and makes no Portal request' );

			$after = Repo::load( $hall );
			$this->assert_same( $stranded, $after['blog_id'], 'the blog id was attached under the current fence' );
			$this->assert_same( $row['site_ownership_id'], $after['site_ownership_id'], 'the ownership identity is unchanged' );
			$this->assert_same( 2, $after['enforced_sequence'], 'the suspension is confirmed' );
			$this->assert_same( '1', (string) get_site( $stranded )->archived, 'the partial site STAYS archived' );
			$this->assert_same( 'suspended', HallCommerceState::read( $stranded )['state'] ?? '', 'settled suspended state is written' );

			$mapping_now = ( new HallMappingRepository() )->findByPortalHallId( $hall );
			if ( 'absent' === $mapping_state ) {
				$this->assert_same( null, $mapping_now, 'NO Hall mapping is created during a suspend' );
			} else {
				$this->assert_same( $before_mapping, $mapping_now, 'an exact Hall mapping is preserved unchanged' );
			}

			$this->assert_same(
				'portal.commerce.suspended',
				$response['audit'][0]['action'] ?? '',
				'a marker-matched partial suspend maps to portal.commerce.suspended'
			);
			$this->assert_same( $stranded, (int) $response['audit'][0]['blog_id'], 'the audit names the now-bound site' );
		}

		/* A CONFLICTING mapping refuses the suspend and repairs nothing. */
		$hall = $this->free_hall();
		$slug = 'peppartsuspc';
		$this->arm_snapshot( $hall );

		$this->obstruct( 'SET blog_id =', 'UPDATE ' . ProvisioningMigrations::operations_table() . ' SET blog_id = NULL WHERE 1 = 0', 'UPDATE' );
		$this->dispatch( $hall, $this->payload( [ 'site_slug' => $slug, 'hall_name' => 'Conflicting suspend' ] ) );

		$stranded = $this->site_for_slug( $slug );
		if ( 0 === $stranded ) {
			return;
		}
		$this->disposable_sites[ $stranded ] = true;

		$other_blog = $this->spare_site( 'pepspare2' );
		if ( 0 === $other_blog ) {
			return;
		}
		$this->guarded_insert_mapping( $hall, $other_blog );
		$this->assert_same(
			$other_blog,
			(int) ( new HallMappingRepository() )->findByPortalHallId( $hall )['blog_id'],
			'a conflicting mapping really was established'
		);

		$response = $this->dispatch( $hall, $this->payload( [ 'intent_sequence' => 2, 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] ) );

		$this->assert_same( 409, $response['status'], 'a conflicting mapping refuses the partial suspend' );
		$this->assert_same( 'failed', $response['body']['outcome'] ?? '', 'its outcome is failed' );
		$this->assert_same( 'mapping_invalid', $response['body']['error_category'] ?? '', 'its category is mapping_invalid' );
		$this->assert_same( null, $response['body']['blog_id'], 'it discloses no blog id' );
		$this->assert_same( '1', (string) get_site( $stranded )->archived, 'the site is kept archived' );
		$this->assert_same( null, HallCommerceState::read( $stranded ), 'no Commerce state is written' );
		$this->assert_same( 0, Repo::load( $hall )['enforced_sequence'], 'no confirmation is written' );
		$this->assert_same(
			$other_blog,
			(int) ( new HallMappingRepository() )->findByPortalHallId( $hall )['blog_id'],
			'the conflicting mapping is never repaired'
		);

		$this->guarded_delete_mapping( $hall );
	}

	private function test_partial_suspend_marker_states(): void {
		$hall = $this->free_hall();
		$slug = 'pepunproven';
		$this->arm_snapshot( $hall );

		$this->obstruct( 'SET blog_id =', 'UPDATE ' . ProvisioningMigrations::operations_table() . ' SET blog_id = NULL WHERE 1 = 0', 'UPDATE' );
		$this->dispatch( $hall, $this->payload( [ 'site_slug' => $slug, 'hall_name' => 'Unproven' ] ) );

		$stranded = $this->site_for_slug( $slug );
		if ( 0 === $stranded ) {
			return;
		}
		$this->disposable_sites[ $stranded ] = true;
		$original = $this->marker( $stranded );

		/* A DIFFERENT marker. */
		$this->guarded_set_marker( $stranded, strtolower( wp_generate_uuid4() ) );
		$response = $this->dispatch( $hall, $this->payload( [ 'intent_sequence' => 2, 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] ) );
		$this->assert_same( 409, $response['status'], 'a DIFFERENT marker refuses the suspend' );
		$this->assert_same( 'site_ownership_unproven', $response['body']['error_category'] ?? '', 'and is site_ownership_unproven' );
		$this->assert_same( null, Repo::load( $hall )['blog_id'], 'nothing is bound' );

		/* An ABSENT marker. */
		$this->guarded_delete_marker( $stranded );
		$response = $this->dispatch( $hall, $this->payload( [ 'intent_sequence' => 3, 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] ) );
		$this->assert_same( 'site_ownership_unproven', $response['body']['error_category'] ?? '', 'an ABSENT marker is site_ownership_unproven' );

		/* An UNREADABLE marker is not a verified absence either. */
		$this->guarded_set_marker( $stranded, (string) $original );
		$this->obstruct( "option_name = 'bc_commerce_managed'", 'SELECT option_value FROM zz_bc_absent_options_for_test LIMIT 1', 'SELECT' );
		$response = $this->dispatch( $hall, $this->payload( [ 'intent_sequence' => 4, 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] ) );
		$this->assert_same( 409, $response['status'], 'an UNREADABLE marker refuses the suspend' );
		$this->assert_same( 'site_ownership_unproven', $response['body']['error_category'] ?? '', 'and is site_ownership_unproven, not a verified absence' );
		$this->assert_same( null, Repo::load( $hall )['blog_id'], 'nothing is bound' );
		$this->assert_same( $original, $this->marker( $stranded ), 'no marker is ever rewritten' );
		$this->assert_true( get_site( $stranded ) instanceof WP_Site, 'the site is never deleted' );

		/* With the marker readable again the same command succeeds. */
		$response = $this->dispatch( $hall, $this->payload( [ 'intent_sequence' => 5, 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] ) );
		$this->assert_same( 200, $response['status'], 'a readable, matching marker suspends successfully' );
		$this->assert_same( $stranded, $response['body']['blog_id'] ?? 0, 'and binds the recovered site' );
	}

	/**
	 * EVERY CANDIDATE FACT IS PROVEN BEFORE A PRE-BIND SUSPEND ATTACHES.
	 *
	 * The marker says who owns the site. These say whether the site is one this
	 * command may adopt at all — and every refusal must leave the candidate
	 * completely untouched: not attached, not given Commerce state, not mapped,
	 * not archived or unarchived, not repaired and not deleted.
	 */
	private function test_partial_suspend_candidate_lifecycle(): void {
		global $wpdb;

		foreach (
			[
				'an UNARCHIVED candidate' => [ 'archived' => 0 ],
				'a DELETED candidate'     => [ 'deleted' => 1 ],
				'a SPAM candidate'        => [ 'spam' => 1 ],
			] as $label => $flags
		) {
			$hall = $this->free_hall();
			$slug = 'pepcand' . substr( md5( $label ), 0, 6 );
			$this->arm_snapshot( $hall );

			/* Create the partial site, then lose the attachment. */
			$this->obstruct( 'SET blog_id =', 'UPDATE ' . ProvisioningMigrations::operations_table() . ' SET blog_id = NULL WHERE 1 = 0', 'UPDATE' );
			$this->dispatch( $hall, $this->payload( [ 'site_slug' => $slug, 'hall_name' => 'Candidate' ] ) );

			$stranded = $this->site_for_slug( $slug );
			$this->assert_true( $stranded > 0, "{$label}: a marker-matched partial site exists" );
			if ( 0 === $stranded ) {
				continue;
			}
			$this->disposable_sites[ $stranded ] = true;

			$row    = Repo::load( $hall );
			$marker = $this->marker( $stranded );
			$this->assert_same( null, $row['blog_id'], "{$label}: the row is still unbound" );

			$before_flags = $this->site_flags( $stranded );

			$this->set_site_flags( $stranded, $flags );
			$expected_flags = $this->site_flags( $stranded );

			$response = $this->dispatch(
				$hall,
				$this->payload( [ 'intent_sequence' => 2, 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] )
			);

			$this->assert_true( 200 !== $response['status'], "{$label}: is NOT reported as a successful suspension" );
			$this->assert_same( 409, $response['status'], "{$label}: is HTTP 409" );
			$this->assert_same( 'failed', $response['body']['outcome'], "{$label}: with outcome failed" );
			$this->assert_same(
				'site_ownership_unproven',
				$response['body']['error_category'],
				"{$label}: and the documented site_ownership_unproven category"
			);
			$this->assert_same( null, $response['body']['blog_id'], "{$label}: disclosing no blog id" );
			$this->assert_same( null, $response['body']['commerce_state'], "{$label}: and no Commerce state" );
			$this->assert_same( 0, $response['filter_calls'], "{$label}: a suspend still invokes no platform filter" );

			/* Nothing about the candidate changed. */
			$after = Repo::load( $hall );
			$this->assert_same( null, $after['blog_id'], "{$label}: nothing was attached" );
			$this->assert_same( $row['site_ownership_id'], $after['site_ownership_id'], "{$label}: the ownership identity is preserved" );
			$this->assert_true( $after['enforced_sequence'] < $after['intent_sequence'], "{$label}: nothing was confirmed" );
			$this->assert_same( 0, $this->state_rows_for( $stranded ), "{$label}: no Commerce state row was written" );
			$this->assert_same( null, ( new HallMappingRepository() )->findByPortalHallId( $hall ), "{$label}: no mapping was created" );
			$this->assert_same( $marker, $this->marker( $stranded ), "{$label}: the marker was not rewritten" );
			$this->assert_same(
				$expected_flags,
				$this->site_flags( $stranded ),
				"{$label}: the site's lifecycle flags are exactly as the refusal found them"
			);
			$this->assert_true( get_site( $stranded ) instanceof WP_Site, "{$label}: and it was not deleted" );

			/* Restore the candidate, and the same command then succeeds. */
			$this->set_site_flags( $stranded, $before_flags );
			$recovered = $this->dispatch(
				$hall,
				$this->payload( [ 'intent_sequence' => 3, 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] )
			);
			$this->assert_same( 200, $recovered['status'], "{$label}: the same command succeeds once the candidate is sound again" );
			$this->assert_same( $stranded, $recovered['body']['blog_id'], "{$label}: binding that exact site" );
		}

		/*
		 * A CANDIDATE MOVED TO ANOTHER NETWORK IS NOT "a bad candidate" — from
		 * THIS network there is simply no site at the reserved address, which is
		 * the ADR-042 no-site pre-bind cancellation: applied, null blog id and
		 * site URL, and the permanent ownership identity preserved rather than
		 * regenerated. What must never happen is that the foreign-network site
		 * is adopted, and that is what this asserts.
		 */
		$hall = $this->free_hall();
		$slug = 'pepcandnet';
		$this->arm_snapshot( $hall );

		$this->obstruct( 'SET blog_id =', 'UPDATE ' . ProvisioningMigrations::operations_table() . ' SET blog_id = NULL WHERE 1 = 0', 'UPDATE' );
		$this->dispatch( $hall, $this->payload( [ 'site_slug' => $slug, 'hall_name' => 'Foreign network' ] ) );

		$stranded = $this->site_for_slug( $slug );
		$this->assert_true( $stranded > 0, 'a marker-matched partial site exists for the foreign-network case' );
		if ( 0 === $stranded ) {
			return;
		}
		$this->disposable_sites[ $stranded ] = true;

		$row    = Repo::load( $hall );
		$marker = $this->marker( $stranded );
		$before = $this->site_flags( $stranded );

		$this->set_site_flags( $stranded, [ 'site_id' => 999 ] );
		$moved = $this->site_flags( $stranded );

		$response = $this->dispatch(
			$hall,
			$this->payload( [ 'intent_sequence' => 2, 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] )
		);

		$this->assert_same( 200, $response['status'], 'a foreign-network candidate is the no-site cancellation' );
		$this->assert_same( 'applied', $response['body']['outcome'], 'with outcome applied' );
		$this->assert_same( 'suspended', $response['body']['commerce_state'], 'and a suspended Commerce state' );
		$this->assert_same( null, $response['body']['blog_id'], 'and a NULL blog id: nothing was adopted' );
		$this->assert_same( null, $response['body']['site_url'], 'and a null site URL' );
		$this->assert_same( 0, $response['filter_calls'], 'and no platform filter' );

		$after = Repo::load( $hall );
		$this->assert_same( null, $after['blog_id'], 'the foreign-network site was NOT attached' );
		$this->assert_same( $row['site_ownership_id'], $after['site_ownership_id'], 'the ownership identity is preserved, never regenerated' );
		$this->assert_same( 0, $this->state_rows_for( $stranded ), 'no Commerce state row was written for it' );
		$this->assert_same( null, ( new HallMappingRepository() )->findByPortalHallId( $hall ), 'no mapping was created' );
		$this->assert_same( $marker, $this->marker( $stranded ), 'its marker was not rewritten' );
		$this->assert_same( $moved, $this->site_flags( $stranded ), 'and its lifecycle flags were not repaired' );

		$this->set_site_flags( $stranded, $before );
	}

	/** How many Commerce state rows exist for one site. */
	private function state_rows_for( int $blog_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM `' . ProvisioningMigrations::state_table() . '` WHERE blog_id = %d', $blog_id )
		);
	}

	/**
	 * One site's lifecycle columns, normalised for comparison.
	 *
	 * Read as strings and key-sorted so the comparison is about the VALUES the
	 * row holds, not about whether the driver handed back `0` or `'0'` or in
	 * which order the columns were written.
	 *
	 * @return array<string,string>
	 */
	private function site_flags( int $blog_id ): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT archived, deleted, spam, site_id FROM {$wpdb->blogs} WHERE blog_id = %d", $blog_id ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return [];
		}

		$flags = array_map( 'strval', $row );
		ksort( $flags );

		return $flags;
	}

	/** Change lifecycle columns on a disposable site, guarded. */
	private function set_site_flags( int $blog_id, array $flags ): void {
		global $wpdb;

		if ( ! $this->fixture_is_safe() || ! isset( $this->disposable_sites[ $blog_id ] ) || $blog_id < 4 ) {
			$this->assert_true( false, 'a site flag change named a site this run does not own' );

			return;
		}

		$columns = [];
		foreach ( $flags as $column => $value ) {
			if ( in_array( $column, [ 'archived', 'deleted', 'spam', 'site_id' ], true ) ) {
				$columns[ $column ] = (int) $value;
			}
		}
		if ( [] === $columns ) {
			return;
		}

		$wpdb->update( $wpdb->blogs, $columns, [ 'blog_id' => $blog_id ], array_fill( 0, count( $columns ), '%d' ), [ '%d' ] );
		clean_blog_cache( $blog_id );
	}

	private function test_durable_suspend_and_re_enable(): void {
		$hall = $this->free_hall();
		$slug = 'pepcycle';
		$this->arm_snapshot( $hall );

		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => $slug, 'hall_name' => 'Cycle Hall' ] ) );
		$this->assert_same( 200, $response['status'], 'the lifecycle Hall is enabled' );
		$blog_id = (int) $response['body']['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;

		$row       = Repo::load( $hall );
		$ownership = $row['site_ownership_id'];
		$mapping   = ( new HallMappingRepository() )->findByPortalHallId( $hall );
		$site_url  = get_site_url( $blog_id );

		/* Give the store something to preserve. */
		$product_id = $this->seed_product( $blog_id );
		$this->assert_true( $product_id > 0, 'a product is created in the store before suspension' );
		$before_tables = $this->site_table_names( $blog_id );

		/* SUSPEND. */
		$suspend  = $this->payload( [ 'intent_sequence' => 2, 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] );
		$response = $this->dispatch( $hall, $suspend );

		$this->assert_same( 200, $response['status'], 'a durable suspend is HTTP 200' );
		$this->assert_same( 'applied', $response['body']['outcome'] ?? '', 'it is applied' );
		$this->assert_same( 'suspended', $response['body']['commerce_state'] ?? '', 'its state is suspended' );
		$this->assert_same( 'portal_blocked', $response['body']['effective_access'] ?? '', 'its access is portal_blocked' );
		$this->assert_same( $blog_id, $response['body']['blog_id'] ?? 0, 'it returns the same authoritative blog id' );
		$this->assert_same( $site_url, $response['body']['site_url'] ?? '', 'and the same authoritative URL' );
		$this->assert_same( 0, $response['filter_calls'], 'a durable suspend NEVER invokes the platform filter' );
		$this->assert_same( [], $response['http'], 'and makes no Portal request' );

		$site = get_site( $blog_id );
		$this->assert_same( '0', (string) $site->archived, 'DISABLE DOES NOT ARCHIVE the site' );
		$this->assert_same( '0', (string) $site->deleted, 'nor delete it' );
		$this->assert_same( '0', (string) $site->spam, 'nor flag it as spam' );
		$this->assert_same( $ownership, $this->marker( $blog_id ), 'the ownership marker is preserved' );
		$this->assert_same( $mapping, ( new HallMappingRepository() )->findByPortalHallId( $hall ), 'the mapping is preserved exactly' );
		$this->assert_same( $before_tables, $this->site_table_names( $blog_id ), 'every table is preserved' );
		$this->assert_same( $product_id, $this->first_product_id( $blog_id ), 'the store\'s products are preserved' );
		$this->assert_same( $slug, Repo::load( $hall )['site_slug'], 'the durable slug is preserved' );
		$this->assert_same(
			HallCommerceAccess::BLOCK,
			HallCommerceAccess::evaluate( $blog_id )['access'],
			'the committed access evaluator now blocks the Hall'
		);

		/* A confirmed suspend retry is already_applied. */
		$retry = $this->dispatch( $hall, $suspend );
		$this->assert_same( 'already_applied', $retry['body']['outcome'] ?? '', 'a confirmed durable suspend retry is already_applied' );
		$this->assert_same( $blog_id, $retry['body']['blog_id'] ?? 0, 'with the same verified blog id' );
		$this->assert_same( 0, $retry['filter_calls'], 'and no platform filter' );

		/* RE-ENABLE: same site, same everything. */
		$this->arm_snapshot( $hall );
		$before_sites = $this->count_sites_above( 3 );
		$response     = $this->dispatch( $hall, $this->payload( [ 'intent_sequence' => 3, 'site_slug' => null, 'hall_name' => null ] ) );

		$this->assert_same( 200, $response['status'], 'a re-enable is HTTP 200' );
		$this->assert_same( 'applied', $response['body']['outcome'] ?? '', 'it is applied' );
		$this->assert_same( 'operational', $response['body']['commerce_state'] ?? '', 'its state is operational' );
		$this->assert_same( 'allowed', $response['body']['effective_access'] ?? '', 'its access is allowed' );
		$this->assert_same( $blog_id, $response['body']['blog_id'] ?? 0, 'it reuses the SAME site' );
		$this->assert_same( $site_url, $response['body']['site_url'] ?? '', 'and the same URL' );
		$this->assert_same( $before_sites, $this->count_sites_above( 3 ), 'a re-enable creates NO second site' );

		$after = Repo::load( $hall );
		$this->assert_same( $ownership, $after['site_ownership_id'], 'the permanent ownership identity is reused' );
		$this->assert_same( $slug, $after['site_slug'], 'the durable slug is reused' );
		$this->assert_same( $mapping, ( new HallMappingRepository() )->findByPortalHallId( $hall ), 'the mapping is reused' );
		$this->assert_same( $product_id, $this->first_product_id( $blog_id ), 'the store\'s data survived the whole cycle' );
		$this->assert_same(
			HallCommerceAccess::ALLOW,
			HallCommerceAccess::evaluate( $blog_id )['access'],
			'the Hall is allowed again'
		);

		/*
		 * BILLING NEUTRALITY. This increment produces `allowed` or
		 * `portal_blocked` and never `billing_restricted`, and it writes no
		 * billing state of any kind.
		 */
		$this->assert_true(
			'billing_restricted' !== ( $response['body']['effective_access'] ?? '' ),
			'no response in this increment produces billing_restricted'
		);
		$this->assert_same( 0, $this->count_billing_options( $blog_id ), 're-enable invents and persists no billing state' );
	}

	/**
	 * THE ORDINARY, NON-PROVISIONING PATHS STILL BEHAVE EXACTLY AS THEY DID.
	 *
	 * Three committed rules were touched to make provisioning possible, and each
	 * relaxation is granted only against proof a caller cannot fabricate. This
	 * asserts the other side of every one of them: without that proof, the
	 * ordinary path refuses exactly what it refused before.
	 *
	 * Proven here, in the isolated fixture, because the committed harnesses that
	 * cover these classes create their disposable halls in the DEVELOPMENT
	 * database, and this increment does not create sites there.
	 */
	private function test_ordinary_paths_are_unchanged(): void {
		$hall = $this->free_hall();
		$this->arm_snapshot( $hall );

		$response = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'peporindary', 'hall_name' => 'Ordinary' ] ) );
		$this->assert_same( 200, $response['status'], 'a Hall is provisioned for the ordinary-path checks' );
		$blog_id = (int) $response['body']['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;

		$service = new PortalSyncService(
			function ( int $portal_hall_id ) {
				return (string) ( $this->snapshots[ $portal_hall_id ] ?? '' );
			}
		);

		/*
		 * 1. THE ORDINARY MANUAL SYNCHRONIZATION still works, through the SAME
		 *    plan and transaction transport, on an operational unarchived Hall.
		 */
		$ordinary = $service->synchronizeHall( $blog_id );
		$this->assert_true(
			$ordinary instanceof \BingoCommerce\PortalIntegration\PortalSyncResult,
			'the ordinary manual synchronization still applies on an operational Hall'
		);

		/*
		 * 2. THE ORDINARY PATH STILL REFUSES AN ARCHIVED SITE. The relaxation
		 *    travels on a plan that only a held Hall provisioning mutex can
		 *    build, so a manual synchronization cannot reach it.
		 */
		$this->assert_true( $this->guarded_archive( $blog_id, 1 ), 'the site is archived for the check' );
		$archived = $service->synchronizeHall( $blog_id );
		$this->assert_true( is_wp_error( $archived ), 'the ordinary manual synchronization still refuses an archived site' );
		$this->assert_true(
			in_array( ( $archived instanceof WP_Error ) ? $archived->get_error_code() : '', [ 'portal_sync_mapping_changed', 'mapping_read_failed', 'site_archived', 'mapping_not_found' ], true ),
			'and refuses it with its own committed code'
		);

		/*
		 * 3. THE ORDINARY MAPPING BIND STILL REFUSES AN ARCHIVED SITE, with the
		 *    unchanged `site_archived` code.
		 */
		$repo   = new HallMappingRepository();
		$spare  = $this->free_hall();
		$bind   = $repo->bind( $spare, $blog_id );
		$this->assert_true( is_wp_error( $bind ), 'the ordinary mapping bind still refuses an archived site' );
		$this->assert_true(
			in_array( ( $bind instanceof WP_Error ) ? $bind->get_error_code() : '', [ 'site_archived', 'blog_already_mapped' ], true ),
			'and refuses it with its own committed code'
		);

		/*
		 * 4. THE PROVISIONING-SCOPED BIND STILL DEMANDS ITS PROOF. A mutex for a
		 *    DIFFERENT Hall, and a wrong ownership witness, are both refused.
		 */
		$mutex = ProvisioningMutex::acquire( $spare );
		$this->assert_true( $mutex instanceof ProvisioningMutex, 'a mutex is acquired for a different Hall' );
		if ( $mutex instanceof ProvisioningMutex ) {
			try {
				$wrong_hall = $repo->bindForProvisioning( $mutex, $hall, $blog_id, (string) Repo::load( $hall )['site_ownership_id'] );
				$this->assert_true(
					is_wp_error( $wrong_hall ) && 'mapping_provisioning_not_current' === $wrong_hall->get_error_code(),
					'the provisioning-scoped bind refuses a mutex held for a different Hall'
				);

				$wrong_marker = $repo->bindForProvisioning( $mutex, $spare, $blog_id, strtolower( wp_generate_uuid4() ) );
				$this->assert_true(
					is_wp_error( $wrong_marker ) && 'mapping_ownership_unproven' === $wrong_marker->get_error_code(),
					'and refuses a witness that does not match the site'
				);

				$plan = \BingoCommerce\PortalIntegration\PortalSnapshotApplicationPlan::forProvisioning(
					$mutex,
					$blog_id,
					$hall,
					(int) get_current_network_id(),
					(int) get_main_site_id(),
					[ 'hall' => [ 'id' => $hall ], 'generated_at' => '2026-09-08T10:00:00-04:00', 'game_types' => [], 'sessions' => [] ],
					current_time( 'mysql', true )
				);
				$this->assert_true(
					is_wp_error( $plan ),
					'a permissive plan cannot be built with a mutex held for a different Hall'
				);
			} finally {
				$mutex->release();
			}
		}

		$this->assert_true( $this->guarded_archive( $blog_id, 0 ), 'the site is unarchived again' );

		/*
		 * 5. THE ORDINARY ACCESS GATE IS UNCHANGED. A suspended Hall is still
		 *    refused by the manual synchronization, whoever is asking.
		 */
		$response = $this->dispatch( $hall, $this->payload( [ 'intent_sequence' => 2, 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] ) );
		$this->assert_same( 200, $response['status'], 'the Hall is suspended' );

		$blocked = $service->synchronizeHall( $blog_id );
		$this->assert_true(
			is_wp_error( $blocked ) && 'hall_commerce_blocked' === $blocked->get_error_code(),
			'the ordinary manual synchronization still refuses a suspended Hall'
		);

		$this->guarded_delete_mapping( $spare );
	}

	/** Set or clear `archived` on a disposable site. */
	private function guarded_archive( int $blog_id, int $archived ): bool {
		global $wpdb;

		if ( ! $this->fixture_is_safe() || ! isset( $this->disposable_sites[ $blog_id ] ) || $blog_id < 4 ) {
			$this->assert_true( false, 'an archive change named a site this run does not own' );

			return false;
		}

		$updated = $wpdb->update( $wpdb->blogs, [ 'archived' => $archived ], [ 'blog_id' => $blog_id ], [ '%d' ], [ '%d' ] );
		clean_blog_cache( $blog_id );

		return false !== $updated && (string) $archived === (string) get_site( $blog_id )->archived;
	}

	/* ------------------------------------------------------------------ *
	 * Responses, audit and disclosure
	 * ------------------------------------------------------------------ */

	private function test_response_matrix_is_exactly_adr_042(): void {
		$expected = [
			'superseded'              => [ 'status' => 409, 'outcome' => 'superseded' ],
			'conflict'                => [ 'status' => 409, 'outcome' => 'conflict' ],
			'address_invalid'         => [ 'status' => 400, 'outcome' => 'failed' ],
			'address_occupied'        => [ 'status' => 409, 'outcome' => 'failed' ],
			'slug_conflict'           => [ 'status' => 409, 'outcome' => 'failed' ],
			'mapping_invalid'         => [ 'status' => 409, 'outcome' => 'failed' ],
			'site_missing'            => [ 'status' => 409, 'outcome' => 'failed' ],
			'site_create_failed'      => [ 'status' => 503, 'outcome' => 'failed' ],
			'site_ownership_unproven' => [ 'status' => 409, 'outcome' => 'failed' ],
			'readiness_failed'        => [ 'status' => 503, 'outcome' => 'failed' ],
			'sync_failed'             => [ 'status' => 503, 'outcome' => 'failed' ],
			'state_write_failed'      => [ 'status' => 503, 'outcome' => 'failed' ],
			'platform_unavailable'    => [ 'status' => 503, 'outcome' => 'failed' ],
			'internal_error'          => [ 'status' => 503, 'outcome' => 'failed' ],
			'confirmation_failed'     => [ 'status' => 503, 'outcome' => 'unverified' ],
			'unverified'              => [ 'status' => 503, 'outcome' => 'unverified' ],
		];

		$this->assert_same( $expected, ProvisioningResponse::MATRIX, 'the HTTP matrix is exactly the ADR-042 table' );

		foreach ( $expected as $category => $mapping ) {
			$this->assert_same( $mapping['status'], ProvisioningResponse::status_for( $category ), "{$category} maps to HTTP {$mapping['status']}" );
			$this->assert_same( $mapping['outcome'], ProvisioningResponse::outcome_for( $category ), "{$category} maps to outcome {$mapping['outcome']}" );
		}

		$this->assert_same(
			[
				'address_invalid', 'address_occupied', 'authentication_failed', 'authorization_failed',
				'confirmation_failed', 'conflict', 'internal_error', 'lock_unavailable', 'mapping_invalid',
				'platform_unavailable', 'readiness_failed', 'site_create_failed', 'site_missing',
				'site_ownership_unproven', 'slug_conflict', 'state_write_failed', 'superseded',
				'sync_failed', 'unverified', 'validation_failed',
			],
			$this->sorted( ProvisioningResponse::ERROR_CATEGORIES ),
			'the response error vocabulary is exactly the closed ADR-042 allowlist'
		);

		/* An unrecognised internal code never widens the surface. */
		$this->assert_same(
			'internal_error',
			ProvisioningResponse::category( new WP_Error( 'some_future_code', 'SELECT * FROM secrets' ) ),
			'an unknown internal code is reduced to internal_error'
		);
		$this->assert_same(
			'internal_error',
			ProvisioningResponse::category( 'not an error at all' ),
			'a non-WP_Error value is reduced too'
		);
		$this->assert_same(
			'sync_failed',
			ProvisioningResponse::category( new WP_Error( 'portal_snapshot_some_new_rule', 'x' ) ),
			'a new Portal snapshot code still reports as a retryable sync failure'
		);

		/* Every non-200 full body nulls all four site/state fields. */
		foreach ( array_keys( $expected ) as $category ) {
			$body = ProvisioningResponse::failure( 7, '11111111-2222-4333-8444-555555555555', 3, $category )->get_data();
			$this->assert_same( null, $body['blog_id'], "{$category} returns a null blog id" );
			$this->assert_same( null, $body['site_url'], "{$category} returns a null site URL" );
			$this->assert_same( null, $body['commerce_state'], "{$category} returns a null Commerce state" );
			$this->assert_same( null, $body['effective_access'], "{$category} returns a null effective access" );
			$this->assert_same( $category, $body['error_category'], "{$category} reports its own category" );
			$this->assert_same( '1', $body['api_version'], "{$category} carries api_version \"1\"" );
			$this->assert_same(
				[ 'api_version', 'blog_id', 'commerce_state', 'effective_access', 'error_category', 'intent_sequence', 'operation_id', 'outcome', 'portal_hall_id', 'site_url' ],
				$this->sorted_keys( $body ),
				"{$category} carries exactly the ten full-body keys"
			);
		}

		$this->assert_same( 'allowed', ProvisioningResponse::effective_access( 'operational' ), 'operational is allowed in the billing-neutral MVP' );
		$this->assert_same( 'portal_blocked', ProvisioningResponse::effective_access( 'suspended' ), 'suspended is portal_blocked' );
		$this->assert_same( 'portal_blocked', ProvisioningResponse::effective_access( 'provisioning' ), 'provisioning is portal_blocked' );
	}

	private function test_audit_contract(): void {
		$this->assert_same(
			[
				'portal.commerce.already_applied',
				'portal.commerce.applied',
				'portal.commerce.conflict',
				'portal.commerce.failed',
				'portal.commerce.superseded',
				'portal.commerce.suspended',
				'portal.commerce.unverified',
			],
			$this->sorted( ProvisioningAudit::ACTIONS ),
			'the audit action vocabulary is exactly the seven decided actions'
		);
		$this->assert_same(
			[ 'desired_state', 'effective_access', 'error_category', 'intent_sequence', 'operation_id', 'outcome', 'portal_actor_id', 'portal_hall_id' ],
			$this->sorted( ProvisioningAudit::CONTEXT_KEYS ),
			'the audit context allowlist is exactly the eight decided keys'
		);

		foreach (
			[
				[ 'suspended', 'applied', 'portal.commerce.suspended' ],
				[ 'suspended', 'already_applied', 'portal.commerce.suspended' ],
				[ 'suspended', 'failed', 'portal.commerce.failed' ],
				[ 'suspended', 'conflict', 'portal.commerce.conflict' ],
				[ 'suspended', 'superseded', 'portal.commerce.superseded' ],
				[ 'suspended', 'unverified', 'portal.commerce.unverified' ],
				[ 'operational', 'applied', 'portal.commerce.applied' ],
				[ 'operational', 'already_applied', 'portal.commerce.already_applied' ],
				[ 'operational', 'failed', 'portal.commerce.failed' ],
				[ 'operational', 'conflict', 'portal.commerce.conflict' ],
				[ 'operational', 'superseded', 'portal.commerce.superseded' ],
				[ 'operational', 'unverified', 'portal.commerce.unverified' ],
			] as [ $state, $outcome, $action ]
		) {
			$this->assert_same( $action, ProvisioningAudit::action_for( $state, $outcome ), "{$state}/{$outcome} maps to {$action}" );
		}

		/* The context builder copies nothing it was not asked for. */
		$context = ProvisioningAudit::context(
			[
				'portal_actor_id'   => 9,
				'portal_hall_id'    => 8,
				'operation_id'      => '11111111-2222-4333-8444-555555555555',
				'intent_sequence'   => 3,
				'desired_state'     => 'operational',
				'outcome'           => 'applied',
				'error_category'    => null,
				'effective_access'  => 'allowed',
				'site_ownership_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
				'request_fingerprint' => str_repeat( 'f', 64 ),
				'site_url'          => 'http://secret.invalid',
				'hall_name'         => 'Secret Hall',
			]
		);
		$this->assert_same(
			ProvisioningAudit::CONTEXT_KEYS,
			array_keys( $context ),
			'the context is BUILT from the allowlist, so an unexpected key cannot be copied in'
		);
		$encoded = (string) wp_json_encode( $context );
		foreach ( [ 'aaaaaaaa-bbbb', str_repeat( 'f', 64 ), 'secret.invalid', 'Secret Hall' ] as $needle ) {
			$this->assert_true( false === strpos( $encoded, $needle ), 'the context excludes ' . substr( $needle, 0, 14 ) );
		}

		/* Every audit row this run wrote uses an allowlisted action and context. */
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT action, context FROM `' . CoreMigrations::audit_log_table() . '` WHERE action LIKE %s',
				$wpdb->esc_like( 'portal.commerce.' ) . '%'
			),
			ARRAY_A
		);
		$this->assert_true( is_array( $rows ) && count( $rows ) > 0, 'this run wrote provisioning audit rows' );
		foreach ( (array) $rows as $index => $row ) {
			$this->assert_true(
				in_array( (string) $row['action'], ProvisioningAudit::ACTIONS, true ),
				"audit row {$index} uses an allowlisted action"
			);
			$decoded = json_decode( (string) $row['context'], true );
			$this->assert_same(
				ProvisioningAudit::CONTEXT_KEYS,
				is_array( $decoded ) ? array_keys( $decoded ) : [],
				"audit row {$index} carries exactly the allowlisted context keys"
			);
		}
	}

	private function test_audit_failure_does_not_change_the_result(): void {
		$hall = $this->free_hall();
		$this->arm_snapshot( $hall );

		$log     = tempnam( sys_get_temp_dir(), 'bc-provisioning-audit-' );
		$previous = ini_get( 'error_log' );
		if ( is_string( $log ) && '' !== $log ) {
			ini_set( 'error_log', $log );
		}

		/*
		 * wpdb reports a failed statement itself, so the harness's own fault
		 * injection would otherwise write the substituted SQL into the very log
		 * this assertion inspects — and the assertion is about what BINGOCOMMERCE
		 * logs, not about what the injection does. Suppressed for the duration,
		 * and restored immediately afterwards.
		 */
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		$shown      = $wpdb->show_errors( false );

		$this->obstruct(
			'`' . CoreMigrations::audit_log_table() . '`',
			'INSERT INTO zz_bc_absent_audit_for_test (id) VALUES (1)',
			'INSERT'
		);
		$before_audit = $this->count_audit();
		$response     = $this->dispatch( $hall, $this->payload( [ 'site_slug' => 'pepaudit', 'hall_name' => 'Audit' ] ) );

		$wpdb->suppress_errors( $suppressed );
		$wpdb->show_errors( $shown );
		ini_set( 'error_log', is_string( $previous ) ? $previous : '' );
		$logged = is_string( $log ) && is_file( $log ) ? (string) file_get_contents( $log ) : '';
		if ( is_string( $log ) && is_file( $log ) ) {
			unlink( $log );
		}

		$this->assert_same( 200, $response['status'], 'an AuditLog failure does NOT roll back a confirmed provisioning result' );
		$this->assert_same( 'applied', $response['body']['outcome'] ?? '', 'the confirmed result stands' );
		$blog_id = (int) $response['body']['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;
		$this->assert_same( '0', (string) get_site( $blog_id )->archived, 'the site really was brought up' );
		$this->assert_same( $before_audit, $this->count_audit(), 'and no audit row was written' );

		$this->assert_true(
			false !== strpos( $logged, ProvisioningAudit::FAILURE_MESSAGE ),
			'exactly the fixed generic server line was logged'
		);
		foreach ( [ 'INSERT INTO', 'zz_bc_absent_audit_for_test', 'wpdb', 'mysqli', $GLOBALS['wpdb']->base_prefix ] as $needle ) {
			$this->assert_true(
				'' === (string) $needle || false === strpos( $logged, (string) $needle ),
				'the audit failure log discloses no ' . substr( (string) $needle, 0, 14 )
			);
		}
	}

	private function test_nothing_is_disclosed(): void {
		global $wpdb;

		$secrets = [
			BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN,
			'Authorization',
			'Bearer ',
			(string) $this->super_admin_id === '' ? 'x' : 'BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID',
			'SELECT ',
			'INSERT INTO',
			'UPDATE ',
			'DROP TABLE',
			$wpdb->base_prefix . 'bingo_portal_provisioning_operations',
			$wpdb->base_prefix . 'audit_log',
			'mysqli',
			'Stack trace',
			ABSPATH,
			'fixture_admin',
			'fixture_portal_machine',
		];

		$this->assert_true( count( $this->observed_bodies ) > 20, 'a broad sample of responses was collected' );

		foreach ( $this->observed_bodies as $index => $body ) {
			$encoded = (string) wp_json_encode( $body );

			foreach ( $secrets as $needle ) {
				$this->assert_true(
					'' === (string) $needle || false === strpos( $encoded, (string) $needle ),
					"response {$index} discloses no " . substr( (string) $needle, 0, 22 )
				);
			}

			/* No ownership identity and no fingerprint ever appears. */
			foreach ( $this->all_ownership_ids() as $ownership ) {
				$this->assert_true( false === strpos( $encoded, $ownership ), "response {$index} discloses no site_ownership_id" );
			}
			foreach ( $this->all_fingerprints() as $fingerprint ) {
				$this->assert_true( false === strpos( $encoded, $fingerprint ), "response {$index} discloses no request fingerprint" );
			}

			/* No Hall name is echoed back. */
			$this->assert_true( false === strpos( $encoded, 'Cycle Hall' ), "response {$index} echoes no Hall name" );

			/* Only allowlisted keys appear. */
			if ( is_array( $body ) ) {
				foreach ( array_keys( $body ) as $key ) {
					$this->assert_true(
						in_array(
							$key,
							[ 'api_version', 'portal_hall_id', 'operation_id', 'intent_sequence', 'outcome', 'commerce_state', 'effective_access', 'blog_id', 'site_url', 'error_category' ],
							true
						),
						"response {$index} carries only allowlisted keys, not '{$key}'"
					);
				}
				if ( isset( $body['error_category'] ) ) {
					$this->assert_true(
						in_array( $body['error_category'], ProvisioningResponse::ERROR_CATEGORIES, true ),
						"response {$index} uses an allowlisted error category"
					);
				}
			}
		}

		/* Audit rows carry no ownership identity or fingerprint either. */
		$rows = $wpdb->get_col( 'SELECT context FROM `' . CoreMigrations::audit_log_table() . '`' );
		foreach ( (array) $rows as $index => $context ) {
			foreach ( $this->all_ownership_ids() as $ownership ) {
				$this->assert_true( false === strpos( (string) $context, $ownership ), "audit row {$index} discloses no site_ownership_id" );
			}
			foreach ( $this->all_fingerprints() as $fingerprint ) {
				$this->assert_true( false === strpos( (string) $context, $fingerprint ), "audit row {$index} discloses no request fingerprint" );
			}
			$this->assert_true(
				false === strpos( (string) $context, BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN ),
				"audit row {$index} discloses no Portal token"
			);
		}
	}

	private function test_no_mail_and_no_live_portal_request(): void {
		$this->assert_same( 0, $this->mail_attempts, 'no mail was sent by anything this run did' );

		foreach ( $this->all_http_urls as $url ) {
			$this->assert_true(
				0 === strpos( (string) $url, BINGOCOMMERCE_PORTAL_BASE_URL . '/integration/v1/halls/' ),
				'every outbound request this run attempted was the fixture Portal snapshot URL'
			);
			$this->assert_true(
				false !== strpos( (string) $url, '.invalid' ),
				'that host is unroutable by construction, and the request never left the process'
			);
		}
	}

	/* ------------------------------------------------------------------ *
	 * Dispatch and observation
	 * ------------------------------------------------------------------ */

	/** Every URL this run attempted, across all dispatches. */
	private array $all_http_urls = [];

	/**
	 * Dispatch one PUT through the REAL REST server and observe its effects.
	 *
	 * @param array<string,mixed> $payload
	 * @return array{status:int,body:mixed,filter_calls:int,http:array,audit:array}
	 */
	private function dispatch( int $hall, array $payload ): array {
		return $this->dispatch_raw_path( (string) $hall, $payload );
	}

	/** @param array<string,mixed> $payload */
	private function dispatch_raw_path( string $raw_hall, array $payload ): array {
		return $this->observe( $raw_hall, (string) wp_json_encode( (object) $payload ) );
	}

	private function dispatch_raw_body( int $hall, string $raw_body ): array {
		return $this->observe( (string) $hall, $raw_body );
	}

	/** @return array{status:int,body:mixed,filter_calls:int,http:array,audit:array} */
	private function observe( string $raw_hall, string $raw_body ): array {
		$this->filter_calls = 0;
		$this->http_urls    = [];
		$audit_before       = $this->count_audit();

		$response = $this->raw_dispatch( 'PUT', '/bingocommerce/v1/portal-halls/' . $raw_hall . '/commerce', $raw_body );

		$this->obstructions = [];
		$this->interceptor  = null;

		$body = $response->get_data();
		if ( is_array( $body ) && isset( $body['api_version'] ) ) {
			$this->observed_bodies[] = $body;
		}
		$this->all_http_urls = array_merge( $this->all_http_urls, $this->http_urls );

		return [
			'status'       => (int) $response->get_status(),
			'body'         => $body,
			'filter_calls' => $this->filter_calls,
			'http'         => $this->http_urls,
			'audit'        => $this->audit_since( $audit_before ),
		];
	}

	private function raw_dispatch( string $method, string $route, string $body ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( $body );

		return rest_get_server()->dispatch( $request );
	}

	/** Re-establish valid Application Password proof for the machine account. */
	private function authenticate(): void {
		$this->become( $this->machine_id );
		$GLOBALS['wp_rest_application_password_uuid']   = '11111111-2222-4333-8444-555555555555';
		$GLOBALS['wp_rest_application_password_status'] = get_userdata( $this->machine_id );
	}

	/**
	 * Become one user, re-reading capabilities from storage.
	 *
	 * wp_set_current_user() short-circuits when the requested id is already the
	 * current one, so a capability changed mid-run would otherwise be invisible
	 * for the rest of the process — and a capability test that cannot see a
	 * revoked capability proves nothing.
	 */
	private function become( int $user_id ): void {
		clean_user_cache( $user_id );
		wp_set_current_user( 0 );
		wp_set_current_user( $user_id );
	}

	/**
	 * Rewrite one statement for the NEXT dispatch only.
	 *
	 * Failures are forced through WordPress's own `query` filter rather than by
	 * damaging data, so nothing has to be repaired afterwards and the production
	 * code under test is completely unmodified. Every obstruction is cleared at
	 * the end of the dispatch that used it.
	 */
	private function obstruct( string $needle, string $replacement, string $kind = '' ): void {
		$this->obstructions[ $needle ] = [ 'replacement' => $replacement, 'kind' => $kind ];
	}

	/** @param string|null $query */
	public function apply_obstructions( $query ) {
		$text = (string) $query;

		if ( is_array( $this->interceptor ) ) {
			/*
			 * Optionally ARMED by one statement and FIRED by a later one. That
			 * is what makes "between the last mutation and the terminal
			 * snapshot" a deterministic point rather than a guess about how many
			 * times a given statement shape runs: the arming statement is the
			 * mutation, and the firing statement is the proof that follows it.
			 */
			$arm = (string) ( $this->interceptor['arm'] ?? '' );
			if ( '' !== $arm ) {
				if ( false !== strpos( $text, $arm ) ) {
					$this->interceptor['arm'] = '';
				}
			} elseif ( false !== strpos( $text, (string) $this->interceptor['needle'] ) ) {
				$run               = $this->interceptor['run'];
				$this->interceptor = null;
				$run();
			}
		}

		foreach ( $this->obstructions as $needle => $rule ) {
			if ( false === strpos( $text, (string) $needle ) ) {
				continue;
			}
			/*
			 * The statement KIND is matched too, so obstructing a READ of an
			 * option cannot accidentally swallow the WRITE of that same option
			 * and leave the fixture permanently damaged rather than temporarily
			 * obstructed.
			 */
			if ( '' !== $rule['kind'] && 1 !== preg_match( '/^\s*' . $rule['kind'] . '\b/i', $text ) ) {
				continue;
			}

			return $rule['replacement'];
		}

		return $query;
	}

	/* ------------------------------------------------------------------ *
	 * Fixtures and helpers
	 * ------------------------------------------------------------------ */

	private function hall( int $offset ): int {
		$hall                      = self::HALL_BASE + $offset;
		$this->used_halls[ $hall ] = true;

		return $hall;
	}

	/** The next Portal Hall id this run has not used. */
	private function free_hall(): int {
		static $next = 100;

		do {
			$hall = self::HALL_BASE + $next;
			++$next;
		} while ( isset( $this->used_halls[ $hall ] ) );

		$this->used_halls[ $hall ] = true;

		return $hall;
	}

	/** @param array<string,mixed> $overrides @return array<string,mixed> */
	private function payload( array $overrides = [] ): array {
		return array_merge(
			[
				'operation_id'    => strtolower( wp_generate_uuid4() ),
				'intent_sequence' => 1,
				'desired_state'   => 'operational',
				'site_slug'       => 'pepdefault',
				'hall_name'       => 'Default Hall',
				'portal_actor_id' => 4242,
			],
			$overrides
		);
	}

	/** The exact current command stored for one Hall, as Portal would retry it. */
	private function payload_for( int $hall ): array {
		$row = Repo::load( $hall );
		if ( ! is_array( $row ) ) {
			return $this->payload();
		}

		return [
			'operation_id'    => $row['operation_id'],
			'intent_sequence' => $row['intent_sequence'],
			'desired_state'   => $row['desired_state'],
			'site_slug'       => $row['request_site_slug'],
			'hall_name'       => $row['request_hall_name'],
			'portal_actor_id' => $row['portal_actor_id'],
		];
	}

	private function json_request( array $payload ): WP_REST_Request {
		$request = new WP_REST_Request( 'PUT', Controller::FULL_ROUTE );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( (object) $payload ) );

		return $request;
	}

	/** Arm the fixture Portal transport for one Hall with a valid v1 snapshot. */
	private function arm_snapshot( int $hall ): void {
		$this->snapshots[ $hall ] = (string) wp_json_encode(
			[
				'api_version'  => 'v1',
				'generated_at' => '2026-09-08T10:00:00-04:00',
				'hall'         => [ 'id' => $hall, 'name' => 'Fixture Portal Hall ' . $hall, 'state' => 'active', 'updated_at' => null ],
				'game_types'   => [
					[ 'id' => 7001, 'hall_id' => $hall, 'name' => 'Regular', 'updated_at' => null ],
					[ 'id' => 7002, 'hall_id' => $hall, 'name' => 'Speedball', 'updated_at' => null ],
				],
				'sessions'     => [
					[ 'id' => 8001, 'hall_id' => $hall, 'name' => 'Evening', 'status' => 'active', 'updated_at' => null ],
				],
			]
		);
	}

	/** A disposable site that no Portal Hall is mapped to. */
	private function spare_site( string $slug ): int {
		return $this->seed_foreign_site( $slug );
	}

	private function seed_foreign_site( string $slug ): int {
		$address = HallSiteAddress::forSlug( $slug );
		if ( ! $address instanceof HallSiteAddress ) {
			$this->assert_true( false, "the foreign fixture address for {$slug} resolves" );

			return 0;
		}

		$created = wpmu_create_blog( $address->domain, $address->path, 'Foreign fixture site', $this->super_admin_id, [], $address->network_id );
		wp_installing( false );

		if ( is_wp_error( $created ) || ! is_int( $created ) ) {
			$this->assert_true( false, "the foreign fixture site for {$slug} could not be created" );

			return 0;
		}

		$this->disposable_sites[ $created ] = true;

		return $created;
	}

	private function seed_product( int $blog_id ): int {
		switch_to_blog( $blog_id );
		try {
			$id = wp_insert_post(
				[
					'post_title'  => 'Preserved product',
					'post_type'   => 'product',
					'post_status' => 'publish',
				]
			);
		} finally {
			restore_current_blog();
		}

		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	private function first_product_id( int $blog_id ): int {
		global $wpdb;

		switch_to_blog( $blog_id );
		try {
			$id = $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' ORDER BY ID ASC LIMIT 1" );
		} finally {
			restore_current_blog();
		}

		return null === $id ? 0 : (int) $id;
	}

	private function game_type_count( int $blog_id ): int {
		global $wpdb;

		switch_to_blog( $blog_id );
		try {
			$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . \BingoCommerce\Catalog\Migrations::game_types_table() . '`' );
		} finally {
			restore_current_blog();
		}

		return $count;
	}

	private function count_billing_options( int $blog_id ): int {
		global $wpdb;

		$table = $wpdb->get_blog_prefix( $blog_id ) . 'options';

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE option_name LIKE %s", $wpdb->esc_like( 'bc_billing' ) . '%' )
		);
	}

	private function marker( int $blog_id ): ?string {
		global $wpdb;

		$table = $wpdb->get_blog_prefix( $blog_id ) . 'options';
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1", HallSiteProvisioner::MARKER_OPTION ),
			ARRAY_A
		);

		return is_array( $row ) ? (string) $row['option_value'] : null;
	}

	private function site_for_slug( string $slug ): int {
		$address = HallSiteAddress::forSlug( $slug );
		if ( ! $address instanceof HallSiteAddress ) {
			return 0;
		}
		$id = $address->existingSiteId();

		return is_int( $id ) ? $id : 0;
	}

	private function count_sites_for_slug( string $slug ): int {
		return $this->site_for_slug( $slug ) > 0 ? 1 : 0;
	}

	private function count_sites_above( int $floor ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->blogs} WHERE blog_id > %d", $floor ) );
	}

	private function count_operations(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . ProvisioningMigrations::operations_table() . '`' );
	}

	private function count_operations_for( int $hall ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM `' . ProvisioningMigrations::operations_table() . '` WHERE portal_hall_id = %d', $hall )
		);
	}

	private function count_operations_for_sequence( int $hall, int $sequence ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM `' . ProvisioningMigrations::operations_table() . '` WHERE portal_hall_id = %d AND intent_sequence = %d',
				$hall,
				$sequence
			)
		);
	}

	private function count_mappings(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . MappingMigrations::hall_mappings_table() . '`' );
	}

	private function count_state_rows(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . ProvisioningMigrations::state_table() . '`' );
	}

	private function count_audit(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . CoreMigrations::audit_log_table() . '`' );
	}

	/** Provisioning audit rows attributed to one Portal Hall. */
	private function audit_count_for_hall( int $hall ): int {
		global $wpdb;

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT context FROM `' . CoreMigrations::audit_log_table() . '` WHERE action LIKE %s',
				$wpdb->esc_like( 'portal.commerce.' ) . '%'
			)
		);

		$count = 0;
		foreach ( (array) $rows as $context ) {
			$decoded = json_decode( (string) $context, true );
			if ( is_array( $decoded ) && (int) ( $decoded['portal_hall_id'] ?? 0 ) === $hall ) {
				++$count;
			}
		}

		return $count;
	}

	private function audit_count_for( string $action ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM `' . CoreMigrations::audit_log_table() . '` WHERE action = %s', $action )
		);
	}

	/** @return array<int,array<string,mixed>> */
	private function audit_since( int $before ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT action, blog_id, actor_user_id, context FROM `' . CoreMigrations::audit_log_table() . '` ORDER BY id ASC LIMIT %d OFFSET %d',
				100,
				$before
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/** @param array<string,mixed>|null $row @return array<string,mixed> */
	private function sorted_context( $row ): array {
		if ( ! is_array( $row ) ) {
			return [];
		}

		$context = json_decode( (string) ( $row['context'] ?? '{}' ), true );
		if ( ! is_array( $context ) ) {
			return [];
		}
		ksort( $context );

		return $context;
	}

	/** @return array<int,string> */
	private function all_ownership_ids(): array {
		global $wpdb;

		$ids = $wpdb->get_col( 'SELECT site_ownership_id FROM `' . ProvisioningMigrations::operations_table() . '` WHERE site_ownership_id IS NOT NULL' );

		return is_array( $ids ) ? array_map( 'strval', $ids ) : [];
	}

	/** @return array<int,string> */
	private function all_fingerprints(): array {
		global $wpdb;

		$values = $wpdb->get_col( 'SELECT request_fingerprint FROM `' . ProvisioningMigrations::operations_table() . '`' );

		return is_array( $values ) ? array_map( 'strval', $values ) : [];
	}

	/** @return array<int,string> */
	private function site_table_names( int $blog_id ): array {
		global $wpdb;

		$prefix = $wpdb->get_blog_prefix( $blog_id );
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
		$tables = is_array( $tables ) ? array_map( 'strval', $tables ) : [];
		sort( $tables );

		return $tables;
	}

	/** @param array<string,mixed> $array @return array<int,string> */
	private function sorted_keys( $array ): array {
		if ( ! is_array( $array ) ) {
			return [];
		}
		$keys = array_keys( $array );
		sort( $keys );

		return $keys;
	}

	/** @param array<int,string> $values @return array<int,string> */
	private function sorted( array $values ): array {
		sort( $values );

		return $values;
	}

	private function table_exists( string $table ): bool {
		global $wpdb;

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/* ------------------------------------------------------------------ *
	 * Guarded fixture mutations
	 * ------------------------------------------------------------------ */

	/**
	 * Run one destructive fixture statement, re-verifying the environment and
	 * the target name immediately before it runs.
	 */
	private function guarded_query( string $sql, string $target_table ): void {
		global $wpdb;

		if ( ! $this->fixture_is_safe() ) {
			return;
		}

		$permitted = [
			ProvisioningMigrations::operations_table(),
			ProvisioningMigrations::state_table(),
			MappingMigrations::hall_mappings_table(),
		];
		if ( ! in_array( $target_table, $permitted, true ) || 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $target_table ) ) {
			$this->assert_true( false, 'a fixture statement named a table outside the permitted set' );

			return;
		}
		if ( false === strpos( $sql, $target_table ) ) {
			$this->assert_true( false, 'a fixture statement does not name the table it declared' );

			return;
		}

		$wpdb->query( $sql );
	}

	private function guarded_bump_sequence( int $hall, int $sequence ): void {
		global $wpdb;

		if ( ! $this->fixture_is_safe() || ! $this->owns_hall( $hall ) || $sequence < 1 ) {
			return;
		}

		/*
		 * The stored fingerprint must keep describing its own stored values, so
		 * the row is re-signed through the committed canonical-request builder
		 * rather than having a column poked.
		 */
		$row = Repo::load( $hall );
		if ( ! is_array( $row ) ) {
			return;
		}
		$canonical = [
			'portal_hall_id'  => $hall,
			'operation_id'    => $row['operation_id'],
			'intent_sequence' => $sequence,
			'desired_state'   => $row['desired_state'],
			'site_slug'       => $row['request_site_slug'],
			'hall_name'       => $row['request_hall_name'],
			'portal_actor_id' => $row['portal_actor_id'],
		];
		$fingerprint = ProvisioningCanonicalRequest::fingerprint( $canonical );
		if ( ! is_string( $fingerprint ) ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . ProvisioningMigrations::operations_table() . '` SET intent_sequence = %d, enforced_sequence = %d, request_fingerprint = %s WHERE portal_hall_id = %d',
				$sequence,
				$sequence,
				$fingerprint,
				$hall
			)
		);
	}

	private function guarded_repoint_mapping( int $hall, int $blog_id ): void {
		global $wpdb;

		if ( ! $this->fixture_is_safe() || ! $this->owns_hall( $hall ) || $blog_id < 4 ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . MappingMigrations::hall_mappings_table() . '` SET blog_id = %d WHERE portal_hall_id = %d',
				$blog_id,
				$hall
			)
		);
	}

	private function guarded_insert_mapping( int $hall, int $blog_id ): void {
		global $wpdb;

		if ( ! $this->fixture_is_safe() || ! $this->owns_hall( $hall ) || $blog_id < 4 ) {
			return;
		}

		$now = current_time( 'mysql', true );
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO `' . MappingMigrations::hall_mappings_table() . '` (portal_hall_id, blog_id, status, created_at, updated_at) VALUES (%d, %d, %s, %s, %s)',
				$hall,
				$blog_id,
				HallMappingRepository::STATUS_ACTIVE,
				$now,
				$now
			)
		);
	}

	private function guarded_delete_mapping( int $hall ): void {
		global $wpdb;

		if ( ! $this->fixture_is_safe() || ! $this->owns_hall( $hall ) ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare( 'DELETE FROM `' . MappingMigrations::hall_mappings_table() . '` WHERE portal_hall_id = %d', $hall )
		);
	}

	/** Move a disposable site to a different address, as an operator mishap would. */
	private function guarded_move_site( int $blog_id, string $domain ): bool {
		global $wpdb;

		if ( ! $this->fixture_is_safe() || ! isset( $this->disposable_sites[ $blog_id ] ) || $blog_id < 4 ) {
			$this->assert_true( false, 'a site move named a site this run does not own' );

			return false;
		}

		$moved = $wpdb->update( $wpdb->blogs, [ 'domain' => $domain ], [ 'blog_id' => $blog_id ], [ '%s' ], [ '%d' ] );
		clean_blog_cache( $blog_id );

		return false !== $moved;
	}

	/** Remove a disposable site's row while leaving its tables for teardown. */
	private function guarded_remove_site_row( int $blog_id ): void {
		global $wpdb;

		if ( ! $this->fixture_is_safe() || ! isset( $this->disposable_sites[ $blog_id ] ) || $blog_id < 4 ) {
			$this->assert_true( false, 'a site removal named a site this run does not own' );

			return;
		}

		$wpdb->delete( $wpdb->blogs, [ 'blog_id' => $blog_id ], [ '%d' ] );
		$wpdb->delete( $wpdb->registration_log, [ 'blog_id' => $blog_id ], [ '%d' ] );
		clean_blog_cache( $blog_id );
	}

	/** Drop the per-site tables of a site whose row this run already removed. */
	private function cleanup_orphan_tables( int $blog_id ): void {
		global $wpdb;

		if ( ! $this->fixture_is_safe() || $blog_id < 4 ) {
			return;
		}

		$prefix = $wpdb->base_prefix . $blog_id . '_';
		foreach ( $this->site_table_names( $blog_id ) as $table ) {
			if ( 0 === strpos( $table, $prefix ) && 1 === preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
			}
		}

		$this->assert_same( [], $this->site_table_names( $blog_id ), "the orphaned site {$blog_id} left no table behind" );
	}

	private function guarded_set_marker( int $blog_id, string $value ): void {
		global $wpdb;

		if ( ! $this->fixture_is_safe() || ! isset( $this->disposable_sites[ $blog_id ] ) || $blog_id < 4 ) {
			$this->assert_true( false, 'a marker write named a site this run does not own' );

			return;
		}

		$table = $wpdb->get_blog_prefix( $blog_id ) . 'options';

		// An upsert, because the same helper has to restore a marker this
		// harness previously deleted as well as change one that is still there.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (option_name, option_value, autoload) VALUES (%s, %s, 'no')
				 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
				HallSiteProvisioner::MARKER_OPTION,
				$value
			)
		);
	}

	private function guarded_delete_marker( int $blog_id ): void {
		global $wpdb;

		if ( ! $this->fixture_is_safe() || ! isset( $this->disposable_sites[ $blog_id ] ) || $blog_id < 4 ) {
			$this->assert_true( false, 'a marker deletion named a site this run does not own' );

			return;
		}

		$table = $wpdb->get_blog_prefix( $blog_id ) . 'options';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE option_name = %s", HallSiteProvisioner::MARKER_OPTION ) );
	}

	private function owns_hall( int $hall ): bool {
		if ( isset( $this->used_halls[ $hall ] ) ) {
			return true;
		}

		$this->assert_true( false, 'a fixture statement named a Portal Hall this run does not own' );

		return false;
	}

	/** The main site's WooCommerce session table, or '' when it does not exist. */
	private function wc_session_table(): string {
		global $wpdb;

		$table = $wpdb->base_prefix . 'woocommerce_sessions';
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			return '';
		}

		$found = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return $found === $table ? $table : '';
	}

	private function wc_session_max_id(): int {
		global $wpdb;

		$table = $this->wc_session_table();

		return '' === $table ? 0 : (int) $wpdb->get_var( "SELECT COALESCE(MAX(session_id), 0) FROM `{$table}`" );
	}

	/**
	 * Remove ONLY the WooCommerce guest sessions this run caused.
	 *
	 * Bounded by the high-water mark taken before the first mutating test, so a
	 * row that was already in the fixture is never touched. Guarded by
	 * fixture_is_safe() at the single call site.
	 */
	private function remove_wc_session_residue(): void {
		global $wpdb;

		$table = $this->wc_session_table();
		if ( '' === $table ) {
			return;
		}

		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE session_id > %d", $this->wc_session_high_water ) );

		$this->assert_same(
			$this->wc_session_high_water,
			$this->wc_session_max_id(),
			'the WooCommerce guest sessions this run created were removed'
		);

		/*
		 * AND ONE MORE SWEEP AFTER WOOCOMMERCE'S OWN. WC_Session_Handler saves
		 * on `shutdown` at priority 20, which is after every assertion this
		 * harness can make, so a session touched during teardown would be
		 * written back once the sweep above had already passed. This runs last
		 * and re-applies exactly the same bounded delete; it asserts nothing,
		 * because by then there is nothing left that could report a failure.
		 */
		$high_water = $this->wc_session_high_water;
		add_action(
			'shutdown',
			static function () use ( $table, $high_water ): void {
				global $wpdb;

				if ( $wpdb instanceof wpdb && DB_NAME === BC_Fixture::$database && DB_NAME !== BC_Fixture::$live_db ) {
					$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE session_id > %d", $high_water ) );
				}
			},
			PHP_INT_MAX
		);
	}

	private function fixture_is_safe(): bool {
		global $wpdb;

		$connected = (string) $wpdb->get_var( 'SELECT DATABASE()' );
		if ( $connected !== BC_Fixture::$database
			|| ! in_array( $connected, BC_FIXTURE_DB_ALLOWLIST, true )
			|| $connected === BC_Fixture::$live_db ) {
			$this->assert_true( false, 'refused: the connected database is not the fixture' );

			return false;
		}

		$identity = $wpdb->get_var( 'SELECT identity FROM `' . BC_FIXTURE_SENTINEL_TABLE . '` LIMIT 1' );
		if ( null === $identity || BC_FIXTURE_IDENTITY !== (string) $identity ) {
			$this->assert_true( false, 'refused: the fixture sentinel is missing or wrong' );

			return false;
		}

		return true;
	}

	/* ------------------------------------------------------------------ *
	 * Teardown
	 * ------------------------------------------------------------------ */

	private function teardown(): void {
		global $wpdb;

		$this->obstructions = [];
		$this->interceptor  = null;

		if ( ! $this->fixture_is_safe() ) {
			return;
		}

		$this->remove_wc_session_residue();

		foreach ( array_keys( $this->disposable_sites ) as $blog_id ) {
			$blog_id = (int) $blog_id;
			if ( $blog_id <= 3 ) {
				$this->assert_true( false, 'teardown refused: a site id outside the disposable range' );
				continue;
			}

			$prefix = $wpdb->base_prefix . $blog_id . '_';
			$tables = $this->site_table_names( $blog_id );

			wp_delete_site( $blog_id );

			foreach ( $tables as $table ) {
				if ( 0 === strpos( $table, $prefix ) && 1 === preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
					$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
				}
			}

			$wpdb->delete( $wpdb->registration_log, [ 'blog_id' => $blog_id ], [ '%d' ] );

			$this->assert_same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->blogs} WHERE blog_id = %d", $blog_id ) ),
				"disposable site {$blog_id} was removed"
			);
			$this->assert_same( [], $this->site_table_names( $blog_id ), "disposable site {$blog_id} left no table behind" );

			unset( $this->disposable_sites[ $blog_id ] );
		}

		foreach ( array_keys( $this->disposable_users ) as $user_id ) {
			$user_id = (int) $user_id;
			if ( $user_id < 1 ) {
				continue;
			}
			if ( is_super_admin( $user_id ) ) {
				revoke_super_admin( $user_id );
			}
			wpmu_delete_user( $user_id );
			$this->assert_true( ! get_userdata( $user_id ) instanceof WP_User, "disposable user {$user_id} was removed" );
			unset( $this->disposable_users[ $user_id ] );
		}

		if ( $this->owns_super_admin && null !== $this->original_site_admins ) {
			update_site_option( 'site_admins', $this->original_site_admins );
		}

		if ( $this->owns_schema ) {
			$this->guarded_query( 'DROP TABLE IF EXISTS `' . ProvisioningMigrations::operations_table() . '`', ProvisioningMigrations::operations_table() );
			$this->guarded_query( 'DROP TABLE IF EXISTS `' . ProvisioningMigrations::state_table() . '`', ProvisioningMigrations::state_table() );
			delete_site_option( ProvisioningMigrations::VERSION_OPTION );

			$this->assert_true( ! $this->table_exists( ProvisioningMigrations::operations_table() ), 'teardown removed the operations table' );
			$this->assert_true( ! $this->table_exists( ProvisioningMigrations::state_table() ), 'teardown removed the commerce-state table' );
			$this->assert_same( 0, (int) get_site_option( ProvisioningMigrations::VERSION_OPTION, 0 ), 'teardown cleared the provisioning schema version' );
		}

		if ( $this->owns_schema ) {
			// Both provisioning tables and the version go together, for the same
			// reason as the audit schema above.
			$this->assert_same( 0, (int) get_site_option( ProvisioningMigrations::VERSION_OPTION, 0 ), 'the provisioning version is cleared' );
		}

		if ( $this->owns_mapping_schema ) {
			$this->guarded_query( 'DROP TABLE IF EXISTS `' . MappingMigrations::hall_mappings_table() . '`', MappingMigrations::hall_mappings_table() );
			delete_site_option( MappingMigrations::NETWORK_VERSION_OPTION );
			$this->assert_true( ! $this->table_exists( MappingMigrations::hall_mappings_table() ), 'teardown removed the Hall mapping table' );
		}

		if ( $this->owns_core_schema ) {
			$table = CoreMigrations::audit_log_table();
			if ( 1 === preg_match( '/^[A-Za-z0-9_]+$/', $table ) && $table === $wpdb->base_prefix . 'audit_log' ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
			}

			/*
			 * The recorded version goes with the table. Leaving it behind would
			 * make the NEXT run's versioned migration short-circuit against a
			 * table this teardown had already dropped — an inconsistent fixture,
			 * not a defect in the code under test.
			 */
			delete_site_option( CoreMigrations::NETWORK_VERSION_OPTION );

			$this->assert_true( ! $this->table_exists( $table ), 'teardown removed the audit log table' );
			$this->assert_same(
				0,
				(int) get_site_option( CoreMigrations::NETWORK_VERSION_OPTION, 0 ),
				'teardown cleared the Core network schema version with it'
			);
		}

		$this->assert_same( [], $this->disposable_sites, 'every disposable site created by this run was destroyed' );
		$this->assert_same( [], $this->disposable_users, 'every disposable user created by this run was destroyed' );
		$this->assert_same(
			0,
			$this->count_sites_above( 3 ),
			'the fixture is left with exactly the three seeded sites'
		);
	}

	/* ------------------------------------------------------------------ *
	 * Assertions
	 * ------------------------------------------------------------------ */

	private function assert_true( bool $condition, string $message ): void {
		++$this->assertions;
		if ( $condition ) {
			return;
		}
		++$this->failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}

	/** @param mixed $expected @param mixed $actual */
	private function assert_same( $expected, $actual, string $message ): void {
		++$this->assertions;
		if ( $expected === $actual ) {
			return;
		}
		++$this->failures;
		fwrite(
			STDERR,
			sprintf(
				"FAIL: %s\n  expected: %s\n  actual:   %s\n",
				$message,
				$this->brief( $expected ),
				$this->brief( $actual )
			)
		);
	}

	/** @param mixed $value */
	private function brief( $value ): string {
		$text = var_export( $value, true );

		return strlen( $text ) > 600 ? substr( $text, 0, 600 ) . ' …' : $text;
	}

	private function report(): void {
		$passed = $this->assertions - $this->failures;
		fwrite( STDOUT, "\nPortal provisioning endpoint: {$passed}/{$this->assertions} assertions passed.\n" );
		exit( $this->failures > 0 ? 1 : 0 );
	}
}

( new PortalProvisioningEndpointTest() )->run();
